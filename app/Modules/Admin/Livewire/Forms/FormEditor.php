<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Forms;

use App\Modules\Admin\Livewire\Forms\Concerns\GatesFormScreens;
use App\Modules\Forms\Actions\SaveForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Support\FormDraft;
use App\Modules\Forms\Support\FormFieldTypes;
use App\Modules\Forms\Support\FormTemplates;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Js;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The «Biểu mẫu» editor (board 15): a form's name and switch, its fields (six kinds; dragged or
 * moved up and down, one opened at a time), the thank-you sentence and the addresses its lead emails
 * go to, with the visitor's view beside them. The draft lives here until «Lưu»: the bar counts the
 * changes the board's way (each of name, thanks, emails and switch is one; each field changed, added
 * or removed is one; a new order is one), fields are checked as they are left, and leaving with
 * changes asks first. The consent box is the platform's: shown locked, last, never part of the draft.
 *
 * Texts are written in the site's main language; other languages stay as they are (SaveForm).
 *
 * @phpstan-import-type Draft from FormDraft
 * @phpstan-import-type DraftField from FormDraft
 */
#[Layout('admin::layouts.app')]
final class FormEditor extends Component
{
    use GatesFormScreens;

    /** The kinds of field a member can add, in the board's order, with their icons. */
    public const array KINDS = ['text' => 'ri-text', 'email' => 'ri-mail', 'tel' => 'ri-phone', 'textarea' => 'ri-file-text', 'select' => 'ri-list-check', 'checkbox' => 'ri-checkbox'];

    #[Locked]
    public ?string $ulid = null;

    #[Locked]
    public string $locale = 'vi';

    #[Locked]
    public bool $isNew = false;

    #[Locked]
    public string $consentLabel = '';

    public string $name = '';

    public string $success = '';

    public bool $active = true;

    /** @var list<DraftField> */
    public array $fields = [];

    /** @var list<string> */
    #[Locked]
    public array $emails = [];

    public string $newEmail = '';

    #[Locked]
    public string $emailError = '';

    /** The one field opened for editing (its key). */
    public ?string $openKey = null;

    /** The preview shows the thank-you sentence instead of the form. */
    public bool $showThanks = false;

    /** @var Draft the form as last saved (a new form: as its starting point) */
    #[Locked]
    public array $saved = ['name' => '', 'success' => '', 'active' => true, 'emails' => [], 'fields' => []];

    /** @var array<string, true> what the member has left or tried to save: 'name', 'success', 'f:<key>' */
    #[Locked]
    public array $touched = [];

    #[Locked]
    public ?string $savedAt = null;

    public string $toastMsg = '';

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    /** @var array{field: DraftField, index: int}|null the field «Hoàn tác» puts back */
    #[Locked]
    public ?array $removed = null;

    public function mount(?string $ulid = null, ?string $purpose = null): void
    {
        $this->locale = app(SiteLocale::class)->default();
        if (! $this->entitled() || ! Gate::allows('viewAny', Form::class)) {
            return; // render() shows the plan or the no-permission card; nothing is read
        }

        if ($ulid !== null) {
            $form = Form::query()->where('ulid', $ulid)->with('fields')->firstOrFail();
            $this->ulid = $form->ulid;
            $draft = FormDraft::fromForm($form, $this->locale);
            $consent = $form->fields->firstWhere('key', Form::CONSENT_KEY);
            $this->consentLabel = $consent !== null ? FormDraft::text($consent->label, $this->locale) : '';
        } else {
            abort_unless(in_array($purpose, FormTemplates::PURPOSES, true), 404);
            $this->isNew = true;
            $draft = FormDraft::template($purpose, $this->locale);
        }
        if ($this->consentLabel === '') {
            $this->consentLabel = (string) __('forms.consent_label', [], $this->locale);
        }
        $this->fill($this->draftProps($draft));
        $this->saved = $this->draft(); // compared the way the draft is, so nothing counts as changed on opening
    }

    /** Only the draft's own fields may be set from the page; every other change goes through a method. */
    public function updating(string $property, mixed $value): void
    {
        $scalar = match ($property) {
            'name', 'success', 'newEmail' => is_string($value),
            'active', 'showThanks' => is_bool($value),
            'openKey' => $value === null || is_string($value),
            default => null,
        };
        if ($scalar === null && preg_match('/^fields\.(\d+)\.(label|type|required|choices)$/', $property, $m) === 1) {
            // only a row the draft has, with a value of the column's own kind
            $scalar = isset($this->fields[(int) $m[1]]) && match ($m[2]) {
                'required' => is_bool($value),
                'type' => is_string($value) && array_key_exists($value, self::KINDS),
                default => is_string($value),
            };
        }
        if ($scalar !== true) {
            throw new AuthorizationException;
        }
    }

    /** A field the member leaves is checked from then on (its message stays until it is right). */
    public function updated(string $property): void
    {
        if ($property === 'name' || $property === 'success') {
            $this->touched[$property] = true;
        } elseif (preg_match('/^fields\.(\d+)\.(label|choices)$/', $property, $m) === 1 && isset($this->fields[(int) $m[1]])) {
            $this->touched['f:'.$this->fields[(int) $m[1]]['key']] = true;
        } elseif ($property === 'newEmail') {
            $this->emailError = '';
        }
    }

    public function toggleField(string $key): void
    {
        $this->openKey = $this->openKey === $key ? null : $key;
    }

    /** «Lên» / «Xuống». */
    public function move(string $key, int $step): void
    {
        $i = $this->indexOf($key);
        if ($i !== null) {
            $this->sortField($key, $i + ($step < 0 ? -1 : 1));
        }
    }

    /** A field dropped at $position (wire:sort). */
    public function sortField(string $key, int $position): void
    {
        $i = $this->indexOf($key);
        if ($i === null) {
            return;
        }
        $row = array_splice($this->fields, $i, 1);
        array_splice($this->fields, max(0, min($position, count($this->fields))), 0, $row);
    }

    /** «Thêm ô»: a new empty field of $type, opened, its name field focused. */
    public function addField(string $type): void
    {
        if (! array_key_exists($type, self::KINDS) || count($this->fields) >= FormDraft::MAX_FIELDS) {
            return;
        }
        // The inbox fills a lead's email and phone (and the reply address) from the fields keyed
        // `email` and `phone`: the first such field added takes that key.
        // A key still waiting in «Hoàn tác» stays taken, so the undo can put its field back.
        $key = ['email' => 'email', 'tel' => 'phone'][$type] ?? '';
        if ($key === '' || $this->indexOf($key) !== null || ($this->removed['field']['key'] ?? null) === $key) {
            $n = (int) (microtime(true) * 1000);
            do {
                $key = $type.'_'.base_convert((string) $n++, 10, 36);
            } while ($this->indexOf($key) !== null || ! FormFieldTypes::isUsableKey($key));
        }
        $this->fields[] = ['key' => $key, 'type' => $type, 'label' => '', 'required' => false, 'choices' => ''];
        $this->openKey = $key;
        $this->dispatch('bm-focus', id: 'bm-l-'.$key);
    }

    /** «Xóa ô này»: gone from the draft; «Hoàn tác» puts it back where it was. */
    public function removeField(string $key): void
    {
        $i = $this->indexOf($key);
        if ($i === null) {
            return;
        }
        $field = $this->fields[$i];
        array_splice($this->fields, $i, 1);
        if ($this->openKey === $key) {
            $this->openKey = null;
        }
        $this->removed = ['field' => $field, 'index' => $i];
        $label = trim($field['label']) !== '' ? $field['label'] : __('form_builder.type.'.$field['type']);
        $this->toast(__('form_builder.toast_field_removed', ['label' => $label]));
    }

    public function undoLast(): void
    {
        $op = $this->removed;
        $this->removed = null;
        $this->toastMsg = '';
        if ($op !== null && $this->indexOf($op['field']['key']) === null) {
            array_splice($this->fields, min($op['index'], count($this->fields)), 0, [$op['field']]);
        }
    }

    public function closeToast(int $seq = 0): void
    {
        if ($seq === 0 || $seq === $this->toastSeq) {
            $this->toastMsg = '';
            $this->removed = null;
        }
    }

    /** «Thêm email» (or Enter in its box). */
    public function addEmail(): void
    {
        $email = trim($this->newEmail);
        if ($email === '') {
            return;
        }
        $this->emailError = match (true) {
            ! FormDraft::isEmail($email) => __('form_builder.email_invalid'),
            in_array(mb_strtolower($email), array_map(mb_strtolower(...), $this->emails), true) => __('form_builder.email_dup'),
            count($this->emails) >= FormDraft::MAX_EMAILS => __('form_builder.email_max', ['max' => FormDraft::MAX_EMAILS]),
            default => '',
        };
        if ($this->emailError === '') {
            $this->emails[] = $email;
            $this->newEmail = '';
        }
    }

    public function removeEmail(int $index): void
    {
        if (isset($this->emails[$index])) {
            array_splice($this->emails, $index, 1);
        }
    }

    /** «Xem lỗi đầu tiên» (the save bar's event): open what holds it and put the cursor there. */
    public function openFirstError(): void
    {
        $first = array_key_first($this->errorsByKey());
        if ($first === null) {
            return;
        }
        $this->touched[$first] = true;
        if (str_starts_with($first, 'f:')) {
            $this->openKey = substr($first, 2);
            $this->dispatch('bm-focus', id: $this->errorsByKey()[$first]['id']);

            return;
        }
        $this->dispatch('bm-focus', id: $this->errorsByKey()[$first]['id']);
    }

    public function save(): void
    {
        $errors = $this->errorsByKey();
        if ($errors !== []) {
            $this->touched = [...$this->touched, ...array_fill_keys(array_keys($errors), true)];
            $this->openFirstError();

            return;
        }
        if ($this->changeCount() === 0 || ! $this->entitled()) {
            return;
        }

        $form = $this->ulid !== null ? Form::query()->where('ulid', $this->ulid)->first() : null;
        if (($form === null && ($this->ulid !== null || Gate::denies('create', Form::class))) || ($form !== null && Gate::denies('update', $form))) {
            $this->toast(__('form_builder.toast_cannot'), 'error');

            return;
        }

        if ($form === null && Form::query()->count() >= FormDraft::MAX_FORMS) {
            $this->toast(__('form_builder.forms_max', ['max' => FormDraft::MAX_FORMS]), 'error');

            return;
        }
        $draft = $this->draft();
        try {
            $form = app(SaveForm::class)->handle($form, $draft, $this->locale);
        } catch (InvalidArgumentException) {
            $this->toast(__('form_builder.toast_failed'), 'error');

            return;
        }

        if ($this->ulid === null) {
            $this->ulid = $form->ulid;
            $this->isNew = false;
            $this->js('history.replaceState(history.state, "", '.Js::from(route('admin.form-builder.edit', $form->ulid)).')');
        }
        $this->saved = $draft;
        $this->touched = [];
        $this->removed = null;
        $this->savedAt = app(SiteTime::class)->forDisplay(now())?->format('H:i');
        $this->toast(__('form_builder.toast_saved'));
    }

    /** «Bỏ thay đổi»: back to the form as last saved (a new form: its starting point). */
    public function resetChanges(): void
    {
        $this->fill($this->draftProps($this->saved));
        $this->touched = [];
        $this->removed = null;
        $this->emailError = '';
        $this->newEmail = '';
        $this->openKey = null;
    }

    /** The board's count of changes; a form not yet saved counts one more (it does not exist yet). */
    public function changeCount(): int
    {
        $a = $this->draft();
        $b = $this->saved;
        $n = $this->isNew ? 1 : 0;
        foreach (['name', 'success', 'emails', 'active'] as $k) {
            if ($a[$k] !== $b[$k]) {
                $n++;
            }
        }
        $before = [];
        foreach ($b['fields'] as $f) {
            $before[$f['key']] = $f;
        }
        foreach ($a['fields'] as $f) {
            if (! isset($before[$f['key']]) || $before[$f['key']] !== $f) {
                $n++;
            }
            unset($before[$f['key']]);
        }
        $n += count($before);
        $keysA = array_column($a['fields'], 'key');
        $keysB = array_column($b['fields'], 'key');
        if (array_values(array_intersect($keysA, $keysB)) !== array_values(array_intersect($keysB, $keysA))) {
            $n++;
        }

        return $n;
    }

    public function render(): View
    {
        // The plan first, then the permission: either card, and no form is read.
        if (($locked = $this->lockedView()) !== null) {
            return $this->titled($locked);
        }

        $errors = $this->errorsByKey();
        $shown = array_intersect_key($errors, $this->touched + ['fields' => true]); // a form left with nothing to fill in says so at once
        $changes = $this->changeCount();
        $firstError = array_key_first($errors);
        $title = $this->isNew && $this->ulid === null ? __('form_builder.title_new') : __('form_builder.title_edit', ['name' => trim($this->saved['name'])]);

        return $this->titled(view('admin::livewire.forms.editor', [
            'title' => $title,
            'changes' => $changes,
            'invalid' => count($errors),
            'shown' => $shown,
            'firstError' => $firstError,
            'kinds' => self::KINDS,
            'sendLabel' => __('forms.submit_button', [], $this->locale),
            'privacyLabel' => __('forms.privacy_link', [], $this->locale),
            'thanksDefault' => __('form_builder.thanks_default', [], $this->locale),
            'leave' => trans_choice('form_builder.leave_body', $changes, ['n' => $changes]),
        ]), $title);
    }

    /**
     * Each thing in error with the message under it and the field to focus, in the page's order.
     *
     * @return array<string, array{msg: string, id: string}>
     */
    private function errorsByKey(): array
    {
        $out = [];
        $name = trim($this->name);
        if ($name === '') {
            $out['name'] = ['msg' => __('form_builder.name_error'), 'id' => 'bm-name'];
        } elseif (mb_strlen($name) > FormDraft::MAX_NAME) {
            $out['name'] = ['msg' => __('form_builder.too_long', ['max' => FormDraft::MAX_NAME]), 'id' => 'bm-name'];
        }
        if (! FormDraft::hasAnswerField(array_column($this->fields, 'type'))) {
            // the add button, or the message itself once the form holds the most fields
            $out['fields'] = ['msg' => __('form_builder.fields_none'), 'id' => count($this->fields) < FormDraft::MAX_FIELDS ? 'bm-add' : 'bm-add-err'];
        }
        foreach ($this->fields as $f) {
            $label = trim($f['label']);
            $lines = FormDraft::lines($f['choices']);
            $msg = match (true) {
                $label === '' => ['msg' => __('form_builder.field_label_error'), 'id' => 'bm-l-'.$f['key']],
                mb_strlen($label) > FormDraft::MAX_NAME => ['msg' => __('form_builder.too_long', ['max' => FormDraft::MAX_NAME]), 'id' => 'bm-l-'.$f['key']],
                $f['type'] === 'select' && $lines === [] => ['msg' => __('form_builder.choices_error'), 'id' => 'bm-o-'.$f['key']],
                $f['type'] === 'select' && (count($lines) > FormDraft::MAX_CHOICES || array_filter($lines, fn (string $l): bool => mb_strlen($l) > FormDraft::MAX_NAME) !== []) => ['msg' => __('form_builder.choices_long', ['n' => FormDraft::MAX_CHOICES, 'max' => FormDraft::MAX_NAME]), 'id' => 'bm-o-'.$f['key']],
                default => null,
            };
            if ($msg !== null) {
                $out['f:'.$f['key']] = $msg;
            }
        }
        if (mb_strlen(trim($this->success)) > FormDraft::MAX_SUCCESS) {
            $out['success'] = ['msg' => __('form_builder.too_long', ['max' => FormDraft::MAX_SUCCESS]), 'id' => 'bm-thx'];
        }

        return $out;
    }

    /** @return Draft */
    private function draft(): array
    {
        return [
            'name' => trim($this->name),
            'success' => trim($this->success),
            'active' => $this->active,
            'emails' => $this->emails,
            'fields' => array_map(fn (array $f): array => [
                'key' => Cast::string($f['key']),
                'type' => Cast::string($f['type']),
                'label' => trim(Cast::string($f['label'])),
                'required' => Cast::bool($f['required']),
                'choices' => Cast::string($f['type']) === 'select' ? implode("\n", FormDraft::lines(Cast::string($f['choices']))) : '',
            ], $this->fields),
        ];
    }

    /**
     * @param  Draft  $draft
     * @return array<string, mixed>
     */
    private function draftProps(array $draft): array
    {
        return ['name' => $draft['name'], 'success' => $draft['success'], 'active' => $draft['active'], 'emails' => $draft['emails'], 'fields' => $draft['fields']];
    }

    private function indexOf(string $key): ?int
    {
        foreach ($this->fields as $i => $f) {
            if ($f['key'] === $key) {
                return $i;
            }
        }

        return null;
    }

    private function toast(string $message, string $tone = 'success'): void
    {
        if ($tone === 'error') {
            $this->removed = null;
        }
        $this->toastMsg = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }

    private function titled(View $view, ?string $title = null): View
    {
        $view->title($title ?? __('form_builder.title'));

        return $view;
    }
}
