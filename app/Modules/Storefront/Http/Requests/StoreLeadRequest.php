<?php

namespace App\Modules\Storefront\Http\Requests;

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
