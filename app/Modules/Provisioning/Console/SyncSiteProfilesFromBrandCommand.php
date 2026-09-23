<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Moves business identity (NAP) toward its home in site_profiles (design.md §14), one safe step
 * at a time. For every site it compares the legacy `general.brand` setting with the profile and
 * reports each field that differs; with --apply it copies a brand value into a profile field that
 * is EMPTY — it never overwrites what the profile already holds.
 *
 * Read the report before the contract step (removing the NAP keys from general.brand): the
 * storefront shows the brand value today in a site's default language, and would show the profile
 * value afterwards, so every "differs" line is a choice to settle first. Without --apply nothing
 * is written. Sites are walked in id order, 200 at a time, each inside its own tenant context.
 */
final class SyncSiteProfilesFromBrandCommand extends Command
{
    /** general.brand key → site_profiles column. */
    private const array FIELDS = [
        'name' => 'brand_name',
        'legal_name' => 'legal_name',
        'phone' => 'phone',
        'hotline' => 'hotline',
        'email' => 'email',
        'address_line' => 'address_line',
        'ward' => 'ward',
        'province' => 'province',
        'tax_id' => 'tax_code',
        'license' => 'business_license_no',
    ];

    #[\Override]
    protected $signature = 'site-profiles:sync-from-brand {--apply : Fill EMPTY profile fields from the brand setting (default: report only)}';

    #[\Override]
    protected $description = 'Report NAP differences between general.brand and site_profiles; --apply fills empty profile fields.';

    public function handle(Tenancy $tenancy): int
    {
        $apply = (bool) $this->option('apply');
        $lines = [];

        Site::query()->orderBy('id')->chunkById(200, function (Collection $sites) use ($tenancy, $apply, &$lines): void {
            foreach ($sites as $site) {
                /** @var Site $site */
                $tenancy->enter($site->id, function () use ($site, $apply, &$lines): void {
                    foreach ($this->syncSite($apply) as [$field, $action, $brand, $profile]) {
                        $lines[] = [$site->name, $field, $action, $brand, $profile];
                    }
                });
            }
        });

        if ($lines === []) {
            $this->info('Every profile already matches its brand setting — nothing to do.');

            return self::SUCCESS;
        }

        $this->table(['Site', 'Field', 'Action', 'general.brand', 'site_profiles'], $lines);
        $this->line($apply
            ? 'Empty profile fields were filled. "differs" lines were left as they are — settle them before removing NAP from general.brand.'
            : 'Report only (nothing written). Run with --apply to fill the empty profile fields.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}> field, action, brand value, profile value
     */
    private function syncSite(bool $apply): array
    {
        $brand = SiteSetting::query()->where('group_name', 'general')->where('key', 'brand')->value('value');
        if (! is_array($brand)) {
            return [];
        }

        $profile = SiteProfile::query()->firstOrNew();
        $lines = [];

        foreach (self::FIELDS as $key => $column) {
            $brandValue = is_scalar($brand[$key] ?? null) ? trim((string) $brand[$key]) : '';
            $profileValue = is_scalar($profile->getAttribute($column)) ? trim((string) $profile->getAttribute($column)) : '';

            if ($brandValue === '' || $brandValue === $profileValue) {
                continue;
            }

            if ($profileValue === '') {
                $profile->setAttribute($column, $brandValue);
                $lines[] = [$column, $apply ? 'filled' : 'would fill', $brandValue, ''];
            } else {
                $lines[] = [$column, 'differs', $brandValue, $profileValue];
            }
        }

        if ($apply && $profile->isDirty()) {
            $profile->save();
        }

        return $lines;
    }
}
