<?php

namespace App\Modules\Storefront\Http\Requests;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormField;
use App\Modules\Forms\Support\FormDraft;
use App\Modules\Forms\Support\FormFieldTypes;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Validates a storefront lead submission (the contact / quote form, GĐ1). The endpoint is public
 * so authorize() is open; anti-spam (Turnstile + honeypot + rate-limit) is enforced by the
 * controller + SubmitForm, not here. The rules come from the fields the site defined for the form
 * (FormFieldTypes): every field the visitor is shown is validated and kept, nothing else is. A form
 * that asks for both email and phone needs one of them, so such a lead is always reachable.
 *
 * A file field's files come as a list under its key (`drawing[]`); an empty file box sends nothing.
 * They are checked for being files only: each is kept or refused on its own afterwards (SubmitForm),
 * never by failing the lead. A send whose body is over post_max_size reaches here with nothing in
 * it (PHP drops the whole body): the visitor gets their form back with one plain message.
 */
final class StoreLeadRequest extends FormRequest
{
    /** The rules of a form with no input field rows (at most its consent box): the original contact form. */
    private const array LEGACY_RULES = [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
        'phone' => ['nullable', 'string', 'max:32', 'required_without:email'],
        'message' => ['required', 'string', 'max:5000'],
    ];

    /** false = not looked up yet; null = no active form under that key. */
    private Form|false|null $resolvedForm = false;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Localize validation and the rest of the visitor's reply (errors, thank-you, rate-limit
     * message) to the language of the page they filled the form on. The POST URL carries no
     * locale prefix, so the form sends it as a hidden field; only a locale visitors can open on
     * this site is accepted — anything else falls back to the site default (never app.locale,
     * which is 'en': a vi site would otherwise answer in English). The site owner's email about
     * the lead stays in the site's default language (SubmitForm).
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $locales = app(SiteLocale::class);
        $requested = $this->input('locale');

        app()->setLocale(is_string($requested) && $locales->isPublic($requested) ? $requested : $locales->default());

        // The posted form's key rides along in the flashed input, so the page shows a failed send on
        // that form only. Set on the application request: the error redirect and the controller's
        // withInput() flash that one, not this copy. Never a stored field (not in rules()).
        request()->merge(['lead_form' => (string) $this->route('key')]);

        $limit = self::postLimit();
        if ($limit > 0 && Cast::int($this->server('CONTENT_LENGTH')) > $limit) {
            throw ValidationException::withMessages(['form' => __('forms.err_files_total', ['mb' => intdiv($limit, 1024 * 1024)])]);
        }
    }

    /**
     * What the validator reads: the input, and each file field's uploads as a list without the
     * empty entries a file box left blank sends (no list at all when none is left).
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function validationData(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->all();
        foreach ($this->collectableFields() as $field) {
            // A number typed the Vietnamese way («1,5») is read as 1.5 and kept that way. The rule is
            // one only: a lone comma with digits on both sides and no dot is the decimal separator.
            // Anything mixing both marks («1,234.5») is not guessed at and fails the number rule.
            if ($field->type === 'number' && is_string($data[$field->key] ?? null) && preg_match('/^-?\d+,\d+$/D', $data[$field->key]) === 1) {
                $data[$field->key] = str_replace(',', '.', $data[$field->key]);
            }
            if ($field->type !== FormFieldTypes::FILE || ! array_key_exists($field->key, $data)) {
                continue;
            }
            $sent = $data[$field->key] instanceof UploadedFile ? [$data[$field->key]] : $data[$field->key]; // a lone file sent without []
            $sent = is_array($sent) ? array_values(array_filter($sent, fn (mixed $one): bool => $one !== null && $one !== '')) : $sent;
            if ($sent === [] || $sent === null || $sent === '') {
                unset($data[$field->key]);
            } else {
                $data[$field->key] = $sent;
            }
        }

        return $data;
    }

    /**
     * The answers to keep with the lead, and the files sent with it by file field key (validated:
     * files only), apart.
     *
     * @return array{0: array<string, mixed>, 1: array<string, list<UploadedFile>>}
     */
    public function answersAndFiles(): array
    {
        /** @var array<string, mixed> $answers */
        $answers = $this->validated();
        $files = [];
        foreach ($this->collectableFields() as $field) {
            if ($field->type !== FormFieldTypes::FILE) {
                continue;
            }
            $sent = $answers[$field->key] ?? [];
            unset($answers[$field->key]);
            $files[$field->key] = array_values(array_filter(is_array($sent) ? $sent : [], fn (mixed $one): bool => $one instanceof UploadedFile));
        }

        return [$answers, array_filter($files)];
    }

    /**
     * A form with no input field rows keeps the original contact rules (plus its consent box);
     * otherwise each field the visitor can answer gets the rules of its type. When a form asks for
     * both email and phone and requires neither, at least one must be filled.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $form = $this->leadForm();
        if ($form === null) {
            return []; // the controller answers 404
        }

        $rules = $form->fields->contains(fn (FormField $field): bool => $field->type !== 'checkbox') ? [] : self::LEGACY_RULES;

        foreach ($this->collectableFields() as $field) {
            $rules[$field->key] = FormFieldTypes::rules($field);
            $rules += FormFieldTypes::nestedRules($field);
        }

        $email = $this->field('email');
        $phone = $this->field('phone');
        if ($email !== null && $phone !== null && ! $email->is_required && ! $phone->is_required) {
            $rules['email'][] = 'required_without:phone';
            $rules['phone'][] = 'required_without:email';
        }

        return $rules;
    }

    /**
     * Field names in error messages are the labels the visitor read, in their language.
     *
     * @return array<string, string>
     */
    #[\Override]
    public function attributes(): array
    {
        $locale = app()->getLocale();
        $attributes = [];
        foreach ($this->collectableFields() as $field) {
            $labels = Cast::arr($field->label);
            $label = FormDraft::text($labels, $locale);
            if ($label !== '') {
                $attributes[$field->key] = $label;
                $attributes[$field->key.'.*'] = $label; // each pick of «Chọn nhiều» is named by the field too
            }
        }

        return $attributes;
    }

    /**
     * Plain-language messages (§10.3.6) in the site locale — never the framework defaults: every
     * rule FormFieldTypes can give has one here.
     *
     * @return array<string, string>
     */
    #[\Override]
    public function messages(): array
    {
        $messages = [
            'name.required' => __('forms.err_name_required'),
            'message.required' => __('forms.err_message_required'),
            'email.email' => __('forms.err_email_invalid'),
            'email.required_without' => __('forms.err_contact_required'),
            'phone.required_without' => __('forms.err_contact_required'),
            'accepted' => __('forms.err_consent_required'),
            'required' => __('forms.err_field_required'),
            'string' => __('forms.err_field_invalid'),
            'email' => __('forms.err_email_invalid'),
            'boolean' => __('forms.err_field_invalid'),
            'max' => __('forms.err_too_long'),
            'in' => __('forms.err_choice_invalid'),
            'list' => __('forms.err_choice_invalid'),
            'distinct' => __('forms.err_choice_invalid'),
            'regex' => __('forms.err_number_invalid'),
            'numeric' => __('forms.err_number_invalid'),
            'date_format' => __('forms.err_date_invalid'),
            'after_or_equal' => __('forms.err_date_past'),
        ];

        foreach ($this->collectableFields() as $field) {
            if (FormFieldTypes::isChoice($field->type)) {
                $messages[$field->key.'.required'] = __('forms.err_choice_required');
                // a list sent for one choice is a choice to make again, not text to type again
                $messages[$field->key.'.string'] = __('forms.err_choice_invalid');
                $messages[$field->key.'.*.string'] = __('forms.err_choice_invalid');
            }
            if ($field->type === FormFieldTypes::FILE) {
                $messages[$field->key.'.required'] = __('forms.err_file_required');
                $messages[$field->key.'.array'] = __('forms.err_field_invalid');
                $messages[$field->key.'.max'] = __('forms.err_field_invalid');
            }
            if ($field->type === 'number') {
                $messages[$field->key.'.min'] = __('forms.err_number_min');
                $messages[$field->key.'.max'] = __('forms.err_number_max');
            } elseif ($field->type === 'checkboxes') {
                $messages[$field->key.'.max'] = __('forms.err_too_many_choices');
            }
        }

        return $messages;
    }

    /**
     * The active form this request posts to, with its fields — looked up once per request (RLS)
     * and reused by the controller and SubmitForm.
     */
    public function leadForm(): ?Form
    {
        if ($this->resolvedForm !== false) {
            return $this->resolvedForm;
        }

        $key = $this->route('key');

        return $this->resolvedForm = is_string($key)
            ? Form::query()->where('key', $key)->where('status', 'active')->with('fields')->first()
            : null;
    }

    /**
     * The fields of the form a visitor can answer (FormFieldTypes::isCollectable).
     *
     * @return list<FormField>
     */
    private function collectableFields(): array
    {
        $form = $this->leadForm();

        return $form === null ? [] : array_values($form->fields->filter(FormFieldTypes::isCollectable(...))->all());
    }

    /** post_max_size in bytes (0 when unlimited), read as PHP reads it. */
    private static function postLimit(): int
    {
        $value = trim((string) ini_get('post_max_size'));
        if ($value === '' || is_numeric($value)) {
            return (int) $value;
        }

        return (int) $value * match (strtoupper(substr($value, -1))) {
            'K' => 1024,
            'M' => 1024 * 1024,
            'G' => 1024 * 1024 * 1024,
            default => 1,
        };
    }

    private function field(string $key): ?FormField
    {
        foreach ($this->collectableFields() as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }
}
