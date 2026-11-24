<?php

declare(strict_types=1);

namespace App\Modules\Forms\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormField;
use App\Modules\Forms\Support\FormDraft;
use App\Modules\Forms\Support\FormFieldTypes;
use App\Modules\Forms\Support\LeadFileKinds;
use App\Modules\Shared\Support\Cast;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * «Lưu» on the «Biểu mẫu» editor: writes one form draft (FormDraft) in $locale — a new form when
 * $form is null. The draft's texts replace that language only; the other languages and the settings the editor does not show stay. Fields are matched by key: a kept field is updated
 * in place (its leads keep their answers), a new key is a new field, a key left out is removed. The
 * consent box is never part of a draft: it is kept (or added) as the last field, as the platform
 * wrote it. The caller has checked the member may do this (FormPolicy) and validated the draft's
 * texts; this action still refuses a draft that could not be stored (unknown type, unusable or
 * repeated key, too many fields), so a crafted request never reaches the tables.
 *
 * A new form gets a key from its name (unique on the site); the key is what pages and the visitor's
 * browser use to send a lead, so it never changes afterwards.
 *
 * A file field keeps its kind in options.accept and its most files in validation.max_files; a kind
 * held back by config('forms.file_kinds') is refused unless the field already had it.
 *
 * @phpstan-import-type Draft from FormDraft
 * @phpstan-import-type DraftField from FormDraft
 */
final readonly class SaveForm
{
    public function __construct(private EnsureContactForm $consent) {}

    /**
     * @param  Draft  $draft
     */
    public function handle(?Form $form, array $draft, string $locale): Form
    {
        $this->check($draft);

        return DB::transaction(function () use ($form, $draft, $locale): Form {
            $created = $form === null;
            if ($form === null) {
                if (Form::query()->count() >= FormDraft::MAX_FORMS) {
                    throw new InvalidArgumentException('The site holds the most forms it may.');
                }
                $form = $this->create($draft, $locale);
            } else {
                $form = Form::query()->whereKey($form->getKey())->lockForUpdate()->firstOrFail();
                $success = Cast::arr($form->success_message);
                if ($draft['success'] !== '') {
                    $success[$locale] = $draft['success'];
                } else {
                    unset($success[$locale]);
                }
                $form->update([
                    'name' => [...Cast::arr($form->name), $locale => $draft['name']],
                    'success_message' => $success,
                    'notify_emails' => $draft['emails'],
                    'settings' => [...Cast::arr($form->settings), 'edited' => true],
                    'status' => $draft['active'] ? 'active' : 'disabled',
                ]);
            }

            $this->saveFields($form, $draft['fields'], $locale);

            Audit::record('forms.form.saved', [
                'auditable_type' => Form::class,
                'auditable_id' => $form->id,
                'meta' => ['key' => $form->key, 'created' => $created, 'fields' => count($draft['fields']), 'status' => $form->status],
            ]);

            return $form;
        });
    }

    /**
     * @param  list<DraftField>  $fields
     */
    private function saveFields(Form $form, array $fields, string $locale): void
    {
        /** @var array<string, FormField> $existing */
        $existing = $form->fields()->get()->keyBy('key')->all();

        foreach ($fields as $i => $draft) {
            $field = $existing[$draft['key']] ?? new FormField(['form_id' => $form->id, 'key' => $draft['key'], 'label' => [], 'options' => [], 'validation' => []]);
            $options = Cast::arr($field->options);
            $validation = Cast::arr($field->validation);
            if (FormFieldTypes::isChoice($draft['type'])) {
                $options['choices'] = FormDraft::choicesFrom($draft['choices'], $locale, FormFieldTypes::choices($field));
            } else {
                unset($options['choices']);
            }
            if ($draft['type'] === FormFieldTypes::FILE) {
                // a kind the platform holds back (forms.file_kinds) may stay on a field that has it,
                // never be newly picked
                $kept = $field->exists && $field->type === FormFieldTypes::FILE && FormFieldTypes::fileKind($field) === $draft['accept'];
                if (! $kept && ! in_array($draft['accept'], FormFieldTypes::fileKinds(), true)) {
                    throw new InvalidArgumentException('That kind of file is not taken.');
                }
                $options['accept'] = $draft['accept'];
                $validation['max_files'] = $draft['max_files'];
            } else {
                unset($options['accept'], $validation['max_files']);
            }
            $field->fill([
                'type' => $draft['type'],
                'label' => [...Cast::arr($field->label), $locale => $draft['label']],
                'is_required' => $draft['required'],
                'options' => $options,
                'validation' => $validation,
                'sort_order' => $i + 1,
            ]);
            $field->save();
            unset($existing[$draft['key']]);
        }

        $consent = $existing[Form::CONSENT_KEY] ?? null;
        unset($existing[Form::CONSENT_KEY]);
        if ($existing !== []) {
            FormField::query()->whereIn('id', array_map(fn (FormField $f): int => $f->id, array_values($existing)))->delete();
        }

        if ($consent === null) {
            $this->consent->ensureConsent($form);
        } else {
            $consent->update(['sort_order' => count($fields) + 1, 'is_required' => true, 'type' => 'checkbox']);
        }
    }

    /**
     * @param  Draft  $draft
     */
    private function check(array $draft): void
    {
        if (count($draft['fields']) > FormDraft::MAX_FIELDS) {
            throw new InvalidArgumentException('Too many fields.');
        }
        if (! FormDraft::hasAnswerField(array_column($draft['fields'], 'type'))) {
            throw new InvalidArgumentException('A form needs a field besides tick boxes.');
        }
        $keys = [];
        foreach ($draft['fields'] as $field) {
            if (! in_array($field['type'], FormFieldTypes::STORED_TYPES, true)
                || ! FormFieldTypes::isUsableKey($field['key'])
                || $field['key'] === Form::CONSENT_KEY
                || isset($keys[$field['key']])
                || trim($field['label']) === '' || mb_strlen($field['label']) > FormDraft::MAX_NAME
                || count(FormDraft::lines($field['choices'])) > FormDraft::MAX_CHOICES
                || array_filter(FormDraft::lines($field['choices']), fn (string $c): bool => mb_strlen($c) > FormDraft::MAX_NAME) !== []
                || ($field['type'] === FormFieldTypes::FILE && (! array_key_exists($field['accept'], LeadFileKinds::KINDS)
                    || $field['max_files'] < 1 || $field['max_files'] > FormFieldTypes::MAX_FILES))) {
                throw new InvalidArgumentException('A field cannot be stored.');
            }
            $keys[$field['key']] = true;
        }
        if (trim($draft['name']) === '' || mb_strlen($draft['name']) > FormDraft::MAX_NAME || mb_strlen($draft['success']) > FormDraft::MAX_SUCCESS) {
            throw new InvalidArgumentException('A form needs a name of a usable length.');
        }
        if (count($draft['emails']) > FormDraft::MAX_EMAILS
            || count(array_unique(array_map(mb_strtolower(...), $draft['emails']))) !== count($draft['emails'])) {
            throw new InvalidArgumentException('Too many or repeated emails.');
        }
        foreach ($draft['emails'] as $email) {
            if (! FormDraft::isEmail($email)) {
                throw new InvalidArgumentException('An email cannot be stored.');
            }
        }
    }

    /**
     * The new form row. Two members saving a new form of the same name at once may pick the same
     * key: the one that loses the unique (site_id, key) check picks again (each try a savepoint).
     *
     * @param  Draft  $draft
     */
    private function create(array $draft, string $locale): Form
    {
        for ($try = 1; ; $try++) {
            try {
                return DB::transaction(fn (): Form => Form::query()->create([
                    'key' => $this->newKey($draft['name']),
                    'name' => [$locale => $draft['name']],
                    'success_message' => $draft['success'] !== '' ? [$locale => $draft['success']] : [],
                    'notify_emails' => $draft['emails'],
                    'settings' => ['edited' => true],
                    'status' => $draft['active'] ? 'active' : 'disabled',
                ]));
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= 3) {
                    throw $e;
                }
            }
        }
    }

    /**
     * A key for a new form from its name: lowercase letters, digits and dashes, unique on the site,
     * never the system contact form's key (a site that has not made that form yet keeps it free).
     */
    private function newKey(string $name): string
    {
        $base = Str::limit(Str::slug($name), 60, '') ?: 'bieu-mau';
        $base = rtrim($base, '-') ?: 'bieu-mau';
        // a form still being deleted (hidden by its scope) keeps its key until its row goes
        $taken = array_flip([...Cast::strings(Form::query()->withoutGlobalScope(Form::NOT_DELETING)->where('key', 'like', $base.'%')->limit(500)->pluck('key')->all()), Form::SYSTEM_KEY]);
        $key = $base;
        for ($n = 2; isset($taken[$key]); $n++) {
            $key = $base.'-'.$n;
        }

        return $key;
    }
}
