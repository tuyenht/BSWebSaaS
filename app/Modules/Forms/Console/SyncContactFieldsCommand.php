<?php

declare(strict_types=1);

namespace App\Modules\Forms\Console;

use App\Modules\Forms\Actions\EnsureContactForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;

/**
 * Brings the contact form of every site up to the current set of fields (e.g. the required consent
 * box) through EnsureContactForm, which only ADDS a missing field: a site's recipients, labels and
 * existing fields stay as they are. A site without a contact form is skipped, never given one.
 * Idempotent; run after a deploy that changes the default contact fields.
 *
 * --refresh-consent also rewrites the consent sentence from lang/*.json on every site — for when
 * the legal wording changes. Nothing else a site set is touched.
 */
final class SyncContactFieldsCommand extends Command
{
    #[\Override]
    protected $signature = 'forms:sync-contact-fields
        {--refresh-consent : Also rewrite the consent sentence from the current translations}';

    #[\Override]
    protected $description = 'Add the default contact-form fields a site is missing (e.g. the consent box); never removes or edits one.';

    public function handle(Tenancy $tenancy, EnsureContactForm $ensure): int
    {
        $refreshConsent = (bool) $this->option('refresh-consent');
        $synced = 0;
        $refreshed = 0;

        $tenancy->eachSite(function () use ($ensure, $refreshConsent, &$synced, &$refreshed): void {
            if (! Form::query()->where('key', 'contact')->exists()) {
                return;
            }

            $form = $ensure->handle([]);
            $synced++;
            if ($refreshConsent && $ensure->refreshConsentLabel($form)) {
                $refreshed++;
            }
        });

        $this->info("Contact forms checked: {$synced}.");
        if ($refreshConsent) {
            $this->info("Consent sentences rewritten: {$refreshed}.");
        }

        return self::SUCCESS;
    }
}
