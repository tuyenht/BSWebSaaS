<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Moves the brand images toward their home on the site profile, one safe step at a time: for every
 * site it reads the logo, favicon and share image URLs the older `general.brand` setting holds,
 * finds this site's picture with that media key, and reports what it would fill; with --apply it
 * writes the media id into a profile image column that is EMPTY — it never overwrites one that is
 * set, so a second run fills nothing.
 *
 * The page does not change: the storefront keeps showing the URL from general.brand until the
 * image is first saved on the settings screen (Storefront BrandImages). What this changes is that
 * the media library now counts these pictures as in use.
 *
 * It writes past the model on purpose: neither `updated_at` nor `updated_by` moves, so each section
 * keeps saying nothing was saved there until a person saves it. A URL that names no picture of this
 * site (another host, a deleted file, not a picture) is reported and left alone. Sites are walked
 * in id order, each inside its own tenant context; a failure on one site is reported and the walk
 * goes on. Without --apply nothing is written.
 */
final class FillBrandImagesFromBrandCommand extends Command
{
    /** general.brand key → site_profiles column. */
    private const array SLOTS = [
        'logo' => 'logo_media_id',
        'favicon' => 'favicon_media_id',
        'og_image' => 'og_image_media_id',
    ];

    #[\Override]
    protected $signature = 'site-profiles:fill-brand-images {--apply : Write the media ids into EMPTY profile image columns (default: report only)}';

    #[\Override]
    protected $description = 'Report which brand image URLs in general.brand match a picture of the site; --apply fills the empty profile image columns.';

    public function handle(Tenancy $tenancy, MediaUrlResolver $urls, CurrentSite $current): int
    {
        $apply = (bool) $this->option('apply');
        $lines = [];
        $failed = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            try {
                $siteLines = $tenancy->enter($siteId, fn (): array => $this->fillSite($apply, $urls, $current));
            } catch (Throwable $e) {
                $failed++;
                $siteLines = [['-', 'failed', $e::class.': '.$e->getMessage(), '']];
            }
            foreach ($siteLines as [$slot, $action, $url, $key]) {
                $lines[] = [(string) $siteId, $slot, $action, $url, $key];
            }
        }

        if ($lines === []) {
            $this->info('No site has a brand image URL left to fill — nothing to do.');

            return self::SUCCESS;
        }

        $this->table(['Site', 'Image', 'Action', 'general.brand', 'media key'], $lines);
        $this->line($apply
            ? 'Empty profile image columns were filled. "not found" lines were left as they are.'
            : 'Report only (nothing written). Run with --apply to fill the empty profile image columns.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}> slot, action, URL, media key
     */
    private function fillSite(bool $apply, MediaUrlResolver $urls, CurrentSite $current): array
    {
        $brand = SiteSetting::query()->where('group_name', 'general')->where('key', 'brand')->value('value');
        if (! is_array($brand)) {
            return [];
        }

        $profile = SiteProfile::query()->first(['id', ...array_values(self::SLOTS)]);
        $lines = [];
        $fill = [];

        foreach (self::SLOTS as $slot => $column) {
            $url = is_string($brand[$slot] ?? null) ? trim($brand[$slot]) : '';
            if ($url === '' || $profile?->getAttribute($column) !== null) {
                continue;
            }

            $key = $urls->keyFromUrl($url);
            $id = $key === null ? null : Media::query()
                ->where('storage_key', $key)->where('mime', 'like', 'image/%')->orderBy('id')->value('id');
            if (! is_numeric($id)) {
                $lines[] = [$slot, 'not found', $url, (string) $key];

                continue;
            }

            $fill[$column] = (int) $id;
            $lines[] = [$slot, $apply ? 'filled' : 'would fill', $url, (string) $key];
        }

        if ($apply && $fill !== []) {
            // Past the model: updated_at and updated_by stay as they are.
            if ($profile !== null) {
                DB::table('site_profiles')->where('site_id', $current->id())->where('id', $profile->id)->update($fill);
            } else {
                DB::table('site_profiles')->insert([...$fill, 'site_id' => $current->id()]);
            }
        }

        return $lines;
    }
}
