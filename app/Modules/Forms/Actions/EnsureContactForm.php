<?php

namespace App\Modules\Forms\Actions;

use App\Modules\Forms\Models\Form;
use App\Modules\Shared\Support\Cast;

/**
 * Idempotently seed the lead-capture contact form (key 'contact') + its fields for the CURRENT
 * site (caller is inside the tenant context → site_id is stamped + RLS-scoped). Shared by
 * ProvisionSite (every new site, owner as recipient) and SeedAnVinhContent (An Vinh, business
 * inbox). Anchored on the unique (site_id, key) and each field's (form_id, key) — a re-run never
 * duplicates and never clobbers a tenant's later-customized recipients.
 *
 * The default wording (form name, thank-you sentence, field labels) is written in every interface
 * language, so a visitor reading the site in English sees English labels. A form created before
 * that — Vietnamese only — gets its missing languages filled on the next run; a language the site
 * already has is never rewritten.
 */
final class EnsureContactForm
{
    /** The default fields, their wording keyed by the lang/*.json entry it comes from. */
    private const array FIELDS = [
        ['key' => 'name', 'type' => 'text', 'label' => 'forms.lead_field_name', 'is_required' => true, 'sort_order' => 1],
        ['key' => 'email', 'type' => 'email', 'label' => 'forms.lead_field_email', 'is_required' => false, 'sort_order' => 2],
        ['key' => 'phone', 'type' => 'tel', 'label' => 'forms.lead_field_phone', 'is_required' => false, 'sort_order' => 3],
        ['key' => 'message', 'type' => 'textarea', 'label' => 'forms.lead_field_message', 'is_required' => true, 'sort_order' => 4],
        // Personal data is collected only with the visitor's explicit agreement; the ticked
        // sentence is stored with the lead as the evidence (SubmitForm).
        ['key' => 'consent', 'type' => 'checkbox', 'label' => 'forms.consent_label', 'is_required' => true, 'sort_order' => 5],
    ];

    /**
     * @param  list<string>  $notifyEmails  lead-notification recipients (set on first creation)
     */
    public function handle(array $notifyEmails): Form
    {
        $form = Form::query()->firstOrCreate(
            ['key' => 'contact'],
            [
                'name' => $this->inEveryLanguage('forms.contact_form_name'),
                'success_message' => $this->inEveryLanguage('forms.submit_success'),
                'notify_emails' => $notifyEmails,
                'status' => 'active',
            ],
        );

        foreach (self::FIELDS as $field) {
            $form->fields()->firstOrCreate(['key' => $field['key']], [...$field, 'label' => $this->inEveryLanguage($field['label'])]);
        }

        $this->fillMissingLanguages($form);

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
     * Add the languages the default wording is missing — form name, thank-you sentence and the
     * default fields' labels — keeping every language the site already has exactly as written.
     */
    private function fillMissingLanguages(Form $form): void
    {
        $name = $this->withMissing($form->name, 'forms.contact_form_name');
        $success = $this->withMissing($form->success_message, 'forms.submit_success');
        if ($name !== null || $success !== null) {
            $form->update(array_filter(['name' => $name, 'success_message' => $success], static fn (?array $value): bool => $value !== null));
        }

        $defaults = array_column(self::FIELDS, 'label', 'key');
        foreach ($form->fields()->whereIn('key', array_keys($defaults))->get() as $field) {
            $label = $this->withMissing($field->label, $defaults[$field->key]);
            if ($label !== null) {
                $field->update(['label' => $label]);
            }
        }
    }

    /**
     * $current plus the languages of $key it lacks, or null when it lacks none.
     *
     * @return array<string, string>|null
     */
    private function withMissing(mixed $current, string $key): ?array
    {
        $current = Cast::arr($current);
        $merged = $current + $this->inEveryLanguage($key);
        ksort($merged);

        return count($merged) === count($current) ? null : array_map(static fn (mixed $text): string => Cast::string($text), $merged);
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
