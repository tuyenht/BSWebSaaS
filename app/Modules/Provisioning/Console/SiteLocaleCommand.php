<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Tenancy\Actions\ConfigureSiteLocale;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Exceptions\SiteLocaleChangeRejectedException;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Models\SiteLocale;
use Illuminate\Console\Command;

/**
 * Operator tool for a site's content languages until the platform console has a screen for it. A
 * website starts with ONE language; more are a separate grant at the SaaS tier:
 *
 *   site:locale av1.baoson.net --limit=3        grant up to 3 languages (per-site override of the plan)
 *   site:locale av1.baoson.net en               switch English on for editors, visitors unchanged
 *   site:locale av1.baoson.net en --public      …and open it to visitors
 *   site:locale av1.baoson.net en --hide        keep it for editors, hide it from visitors
 *   site:locale av1.baoson.net en --off         switch it off for editors and visitors
 *
 * The language change goes through ConfigureSiteLocale — the single write path — so the code shape,
 * the default-language lock and the limit hold here as they will in the settings screen. Every
 * change is audited. Given only a host, it lists the site's languages and limit.
 */
final class SiteLocaleCommand extends Command
{
    #[\Override]
    protected $signature = 'site:locale
        {host : Primary hostname of the site}
        {locale? : Language code, e.g. en, ja, pt-br}
        {--limit= : Grant this many languages to the site, the default included (overrides the plan)}
        {--public : Also open the language to visitors}
        {--hide : Keep the language for editors but hide it from visitors}
        {--off : Switch the language off for editors and visitors}';

    #[\Override]
    protected $description = 'Grant a site more content languages; switch one on, public, hidden or off.';

    public function handle(Tenancy $tenancy): int
    {
        $host = $this->argument('host');
        $locale = $this->argument('locale');
        $limit = $this->option('limit');
        $flags = array_filter(['public' => $this->option('public'), 'hide' => $this->option('hide'), 'off' => $this->option('off')]);

        if (count($flags) > 1) {
            $this->error('Use only one of --public, --hide, --off.');

            return self::FAILURE;
        }
        if ($flags !== [] && $locale === null) {
            $this->error('--public, --hide and --off need a language code.');

            return self::FAILURE;
        }
        if ($limit !== null && (! ctype_digit($limit) || (int) $limit < 1)) {
            $this->error('--limit must be a whole number of at least 1 (the default language counts).');

            return self::FAILURE;
        }

        $resolved = app(SiteResolver::class)->resolve($host);
        if ($resolved === null) {
            $this->error("No site found for host '{$host}'.");

            return self::FAILURE;
        }

        return $tenancy->enter($resolved->siteId, function () use ($resolved, $locale, $limit, $flags): int {
            if ($limit !== null) {
                $this->grantLimit($resolved->siteId, (int) $limit);
            }

            if ($locale !== null && ! $this->changeLocale($resolved->siteId, $locale, array_key_first($flags))) {
                return self::FAILURE;
            }

            $this->report();

            return self::SUCCESS;
        });
    }

    private function grantLimit(int $siteId, int $limit): void
    {
        SiteFeature::query()->updateOrCreate(['feature_key' => 'locales.max'], ['value' => $limit]);

        Audit::record('provisioning.site.feature_overridden', [
            'site_id' => $siteId,
            'actor_label' => 'console',
            'meta' => ['feature' => 'locales.max', 'value' => $limit],
        ]);
    }

    /** @param  'public'|'hide'|'off'|null  $flag */
    private function changeLocale(int $siteId, string $locale, ?string $flag): bool
    {
        // No flag = make sure editors have it and keep whatever visitors see today.
        $current = SiteLocale::query()->firstWhere('locale', $locale);
        [$enabled, $public] = match ($flag) {
            'public' => [true, true],
            'hide' => [true, false],
            'off' => [false, false],
            null => [true, $current !== null && $current->is_public],
        };

        try {
            app(ConfigureSiteLocale::class)->handle($locale, $enabled, $public);
        } catch (SiteLocaleChangeRejectedException $e) {
            $this->error(match ($e->reason) {
                'limit_reached' => 'limit_reached — the site already uses every language it was granted; add --limit to grant more.',
                'default_locked' => 'default_locked — the default language always stays on and public.',
                default => "{$e->reason} — use a lower-case language code such as en, ja or pt-br.",
            });

            return false;
        }

        Audit::record('provisioning.site.locale_changed', [
            'site_id' => $siteId,
            'actor_label' => 'console',
            'meta' => ['locale' => $locale, 'enabled' => $enabled, 'public' => $public],
        ]);

        return true;
    }

    private function report(): void
    {
        $this->line('Language limit: '.app(Entitlement::class)->featureValue('locales.max'));
        $this->table(
            ['Language', 'Default', 'Editors', 'Visitors'],
            SiteLocale::query()->orderByDesc('is_default')->orderBy('locale')->get()
                ->map(fn (SiteLocale $row): array => [
                    $row->locale,
                    $row->is_default ? 'yes' : '',
                    $row->enabled ? 'on' : 'off',
                    $row->is_public ? 'open' : 'hidden',
                ])->all(),
        );
    }
}
