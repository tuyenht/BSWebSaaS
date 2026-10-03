<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Tenancy\Actions\OverrideSiteFeature;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Exceptions\FeatureChangeRejectedException;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Registry\FeatureRegistry;
use Illuminate\Console\Command;

/**
 * Operator tool for what ONE website is granted on top of its customer's plan, until the platform
 * console has a screen for it:
 *
 *   site:feature av1.baoson.net                          list every feature: what the site gets, and its own value if any
 *   site:feature av1.baoson.net rbac.custom_roles on     switch a feature on for this website (off to deny it)
 *   site:feature av1.baoson.net custom_domains.max 3     give this website its own limit
 *   site:feature av1.baoson.net custom_domains.max --clear   take its own value away: the plan decides again
 *
 * The change goes through OverrideSiteFeature — the single write path — and is audited.
 */
final class SiteFeatureCommand extends Command
{
    #[\Override]
    protected $signature = 'site:feature
        {host : Primary hostname of the site}
        {feature? : Feature key, e.g. locales.max}
        {value? : on / off for a switch, a whole number for a limit}
        {--clear : Take the website\'s own value away; its plan decides again}';

    #[\Override]
    protected $description = 'Give one website its own value for a plan feature, or take it away.';

    public function handle(Tenancy $tenancy): int
    {
        $host = $this->argument('host');
        $key = $this->argument('feature');
        $raw = $this->argument('value');
        $clear = (bool) $this->option('clear');

        if ($key !== null && ($raw === null) === ! $clear) {
            $this->error('Give a value (on, off or a whole number), or --clear — one of the two.');

            return self::FAILURE;
        }

        $value = $raw === null ? null : $this->parse($raw);
        if ($raw !== null && $value === null) {
            $this->error("'{$raw}' is not a value: use on, off or a whole number.");

            return self::FAILURE;
        }

        $resolved = app(SiteResolver::class)->resolve($host);
        if ($resolved === null) {
            $this->error("No site found for host '{$host}'.");

            return self::FAILURE;
        }

        return $tenancy->enter($resolved->siteId, function () use ($resolved, $key, $value): int {
            if ($key !== null) {
                try {
                    $was = app(OverrideSiteFeature::class)->handle($key, $value);
                } catch (FeatureChangeRejectedException $e) {
                    $this->error(match ($e->reason) {
                        'unknown_feature' => "unknown_feature — no feature is called '{$key}'; run the command with only the host to list them.",
                        'customer_wide' => 'customer_wide — this feature belongs to the customer across all their websites; set it on their plan.',
                        default => 'wrong_kind — a switch takes on or off, a limit takes a whole number of 0 or more.',
                    });

                    return self::FAILURE;
                }

                Audit::record($value === null ? 'provisioning.site.feature_override_cleared' : 'provisioning.site.feature_overridden', [
                    'site_id' => $resolved->siteId,
                    'actor_label' => 'console',
                    'meta' => array_filter(['feature' => $key, 'value' => $value, 'was' => $was], fn (mixed $v): bool => $v !== null),
                ]);
            }

            $this->report();

            return self::SUCCESS;
        });
    }

    private function parse(string $raw): bool|int|null
    {
        return match (true) {
            in_array($raw, ['on', 'true'], true) => true,
            in_array($raw, ['off', 'false'], true) => false,
            ctype_digit($raw) => (int) $raw,
            default => null,
        };
    }

    private function report(): void
    {
        $own = SiteFeature::query()->pluck('value', 'feature_key');
        $entitlement = app(Entitlement::class);
        $show = fn (mixed $v): string => is_bool($v) ? ($v ? 'on' : 'off') : (is_int($v) ? (string) $v : '—');

        $rows = [];
        foreach (FeatureRegistry::all() as $key => $feature) {
            $rows[] = [$key, $show($entitlement->featureValue($key)), $show($own->get($key)), $feature['scope'] === 'site' ? '' : 'customer-wide'];
        }

        $this->table(['Feature', 'Site gets', 'Own value', ''], $rows);
    }
}
