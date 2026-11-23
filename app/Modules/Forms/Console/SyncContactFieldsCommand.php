<?php

declare(strict_types=1);

namespace App\Modules\Forms\Console;

use App\Modules\Forms\Actions\EnsureContactForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        $failed = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            try {
                // counted only once the site's transaction has committed
                $result = $tenancy->enter($siteId, function () use ($ensure, $refreshConsent): ?bool {
                    if (! Form::query()->where('key', 'contact')->exists()) {
                        return null;
                    }

                    $form = $ensure->handle([]);

                    return $refreshConsent && $ensure->refreshConsentLabel($form);
                });
                if ($result !== null) {
                    $synced++;
                    $refreshed += $result ? 1 : 0;
                }
            } catch (Throwable $e) {
                $failed++;
                Log::error('forms:sync-contact-fields could not process a website.', ['site_id' => $siteId]);
                report($e);
            }
        }

        $this->info("Contact forms checked: {$synced}.");
        if ($refreshConsent) {
            $this->info("Consent sentences rewritten: {$refreshed}.");
        }

        if ($failed > 0) {
            $this->error("{$failed} website(s) could not be processed (see the error log).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
