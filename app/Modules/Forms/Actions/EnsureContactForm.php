<?php

namespace App\Modules\Forms\Actions;

use App\Modules\Forms\Models\Form;

/**
 * Idempotently seed the lead-capture contact form (key 'contact') + its fields for the CURRENT
 * site (caller is inside the tenant context → site_id is stamped + RLS-scoped). Shared by
 * ProvisionSite (every new site, owner as recipient) and SeedAnVinhContent (An Vinh, business
 * inbox). Anchored on the unique (site_id, key) and each field's (form_id, key) — a re-run never
 * duplicates and never clobbers a tenant's later-customized recipients.
 */
final class EnsureContactForm
{
    /**
     * @param  list<string>  $notifyEmails  lead-notification recipients (set on first creation)
     */
    public function handle(array $notifyEmails): Form
    {
        $form = Form::query()->firstOrCreate(
            ['key' => 'contact'],
            [
                'name' => ['vi' => 'Liên hệ'],
                'success_message' => ['vi' => 'Cảm ơn bạn! Chúng tôi đã nhận được thông tin và sẽ liên hệ lại sớm.'],
                'notify_emails' => $notifyEmails,
                'status' => 'active',
            ],
        );

        $fields = [
            ['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Họ tên'], 'is_required' => true, 'sort_order' => 1],
            ['key' => 'email', 'type' => 'email', 'label' => ['vi' => 'Email'], 'is_required' => false, 'sort_order' => 2],
            ['key' => 'phone', 'type' => 'tel', 'label' => ['vi' => 'Số điện thoại'], 'is_required' => false, 'sort_order' => 3],
            ['key' => 'message', 'type' => 'textarea', 'label' => ['vi' => 'Nội dung'], 'is_required' => true, 'sort_order' => 4],
            // Personal data is collected only with the visitor's explicit agreement; the ticked
            // sentence is stored with the lead as the evidence (SubmitForm).
            ['key' => 'consent', 'type' => 'checkbox', 'label' => $this->inEveryLanguage('forms.consent_label'), 'is_required' => true, 'sort_order' => 5],
        ];

        foreach ($fields as $field) {
            $form->fields()->firstOrCreate(['key' => $field['key']], $field);
        }

        return $form;
    }

    /**
     * Rewrite the consent box's sentence from the current interface translations. The sentence is
     * the platform's legal wording, not the site's copy, so a reworded version (after a legal
     * review) has to reach every site; handle() never rewrites an existing field. Leads keep the
     * sentence the visitor actually ticked (SubmitForm stores it), so earlier evidence stays as is.
     *
     * @return bool whether the stored sentence changed
     */
    public function refreshConsentLabel(Form $form): bool
    {
        $field = $form->fields()->where('key', 'consent')->first();
        if ($field === null) {
            return false;
        }

        $label = $this->inEveryLanguage('forms.consent_label');
        $stored = $field->label;
        ksort($stored);
        if ($stored === $label) {
            return false;
        }

        $field->update(['label' => $label]);

        return true;
    }

    /**
     * A per-locale label map from the interface translations (lang/*.json), so a visitor reading
     * the site in any of those languages sees the sentence in it.
     *
     * @return array<string, string>
     */
    private function inEveryLanguage(string $key): array
    {
        $labels = [];
        foreach (glob(lang_path('*.json')) ?: [] as $file) {
            $locale = basename($file, '.json');
            $labels[$locale] = (string) __($key, [], $locale);
        }
        ksort($labels);

        return $labels;
    }
}
