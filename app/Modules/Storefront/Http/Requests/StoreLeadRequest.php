<?php

namespace App\Modules\Storefront\Http\Requests;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormField;
use App\Modules\Forms\Support\FormFieldTypes;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a storefront lead submission (the contact / quote form, GĐ1). The endpoint is public
 * so authorize() is open; anti-spam (Turnstile + honeypot + rate-limit) is enforced by the
 * controller + SubmitForm, not here. The rules come from the fields the site defined for the form
 * (FormFieldTypes): every field the visitor is shown is validated and kept, nothing else is. A form
 * that asks for both email and phone needs one of them, so such a lead is always reachable.
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
            $label = Cast::string($labels[$locale] ?? $labels['vi'] ?? null);
            if ($label !== '') {
                $attributes[$field->key] = $label;
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
