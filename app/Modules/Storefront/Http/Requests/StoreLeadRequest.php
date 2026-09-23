<?php

namespace App\Modules\Storefront\Http\Requests;

use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a storefront lead submission (the contact / quote form, GĐ1). The endpoint is public
 * so authorize() is open; anti-spam (Turnstile + honeypot + rate-limit) is enforced by the
 * controller + SubmitForm, not here. At least one contact channel (email OR phone) is required so
 * a captured lead is always reachable. Rules stay fixed for the seeded contact form; per-field
 * rules derived from form_fields arrive with the form builder (deliberately deferred — YAGNI).
 */
final class StoreLeadRequest extends FormRequest
{
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
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:32', 'required_without:email'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * Plain-language messages (§10.3.6) in the site locale — never the framework defaults.
     *
     * @return array<string, string>
     */
    #[\Override]
    public function messages(): array
    {
        return [
            'name.required' => __('forms.err_name_required'),
            'message.required' => __('forms.err_message_required'),
            'email.email' => __('forms.err_email_invalid'),
            'email.required_without' => __('forms.err_contact_required'),
            'phone.required_without' => __('forms.err_contact_required'),
        ];
    }
}
