<?php

declare(strict_types=1);

namespace App\Modules\Forms\Console;

use App\Modules\Forms\Actions\EnsureContactForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;

/**
 * Brings the contact form of every site up to the current defaults through EnsureContactForm, which
 * only ADDS: a missing field (e.g. the required consent box) and the languages the default wording
 * lacks (a form written in Vietnamese only gets its English, Japanese and Chinese labels). A
 * site's recipients, fields and every wording it already has stay as they are. A site without a
 * contact form is skipped, never given one. Idempotent; run after every deploy.
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
    protected $description = 'Add the contact-form fields and the languages of their default wording a site is missing; never changes what a site wrote.';

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
