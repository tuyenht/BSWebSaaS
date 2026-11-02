<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Before --apply writes anything: a gzip JSON file of every row the plan is about to change (per
 * item: the record, its translations, routes, category links, images or menu tree; settings; the
 * profile; redirects) plus the previous ledger, so one item can be inspected or restored by hand.
 * `storage/app/private/content-pack-backups/<site-ulid>/<pack>-v<version>-<UTC>-<fingerprint>.json.gz`, mode
 * 0600, flushed to disk and re-read before the write transaction opens. Rows are read inside the
 * site's tenant context. The file stays even when the apply then fails — a record of the attempt.
 */
final readonly class Backup
{
    private string $root;

    public function __construct(private CurrentSite $site, ?string $root = null)
    {
        $this->root = rtrim($root ?? storage_path('app/private/content-pack-backups'), '/');
    }

    /**
     * Collect the before-rows (inside the tenant context).
     *
     * @return array<string, mixed>
     */
    public function snapshot(Plan $plan, Planner $planner): array
    {
        $items = [];
        foreach ($plan->writes() as $planned) {
            $items[] = [
                'key' => $planned->item->key,
                'verdict' => $planned->verdict,
                'forced' => $planned->forced,
                'ref' => $planned->ref,
                'rows' => $planned->recordId === null && ! in_array($planned->item->kind, ['setting', 'profile', 'redirect'], true)
                    ? null
                    : $this->rows($planned, $planner),
            ];
        }

        return [
            'pack' => ['code' => $plan->pack->code, 'version' => $plan->pack->version],
            'fingerprint' => $plan->fingerprint,
            'host' => $plan->host,
            'site_ulid' => $plan->siteUlid,
            'app_version' => config('app.version'),
            'taken_at' => now()->utc()->toIso8601String(),
            'previous_ledger' => $plan->ledger,
            'items' => $items,
        ];
    }

    /**
     * Write the file; returns its path.
     *
     * @param  array<string, mixed>  $snapshot
     *
     * @throws PackRejected the folder or the file cannot be written, or the file reads back different
     */
    public function write(Plan $plan, array $snapshot): string
    {
        $dir = $this->root.'/'.$plan->siteUlid;
        $base = sprintf('%s/%s-v%d-%s-%s', $dir, $plan->pack->code, $plan->pack->version, now()->utc()->format('Ymd-His'), $plan->fingerprint);
        $bytes = gzencode(CanonicalHash::encode($snapshot), 6);

        $ok = $bytes !== false && (is_dir($dir) || @mkdir($dir, 0700, true));
        $path = $base.'.json.gz';
        if ($ok) {
            // Never overwrite an earlier backup: a second attempt in the same second gets a suffix.
            for ($n = 2; file_exists($path) && $n < 100; $n++) {
                $path = "{$base}-{$n}.json.gz";
            }
            $handle = @fopen($path, 'xb');
            $ok = $handle !== false
                && fwrite($handle, $bytes) === strlen($bytes)
                && fflush($handle)
                && fsync($handle)
                && fclose($handle);
            $ok = $ok && chmod($path, 0600) && hash_file('sha256', $path) === hash('sha256', $bytes);
        }

        if (! $ok) {
            throw new PackRejected("Refused: the backup could not be written to {$dir}; nothing was changed.");
        }

        return $path;
    }

    /** @return array<string, mixed> */
    private function rows(PlanItem $planned, Planner $planner): array
    {
        $id = $planned->recordId;

        return match ($planned->item->kind) {
            'page', 'post' => $this->entryRows((int) $id),
            'product' => $this->productRows((int) $id),
            'taxonomy' => $this->taxonomyRows((int) $id),
            'menu' => $this->menuRows((int) $id),
            'setting' => $this->settingRows($planned->item->anchor()),
            'profile' => $this->profileRows(),
            'redirect' => $this->redirectRows($planned->item->anchor()),
            default => [],
        } + ['default_locale' => $planner->defaultLocale()];
    }

    /** @return array<string, mixed> */
    private function entryRows(int $id): array
    {
        $entry = Entry::withTrashed()->with('translations')->findOrFail($id);

        return [
            'entry' => $entry->attributesToArray(),
            'translations' => $entry->translations->map(fn (Model $row): array => $row->attributesToArray())->all(),
            'routes' => SiteRoute::query()->where('entry_id', $id)->get()->map(fn (Model $row): array => $row->attributesToArray())->all(),
            'categories' => $this->pivot('entry_taxonomies', 'entry_id', $id),
        ];
    }

    /** @return array<string, mixed> */
    private function productRows(int $id): array
    {
        $product = Product::query()->with(['translations', 'images'])->findOrFail($id);

        return [
            'product' => $product->attributesToArray(),
            'translations' => $product->translations->map(fn (Model $row): array => $row->attributesToArray())->all(),
            'images' => $product->images->map(fn (Model $row): array => $row->attributesToArray())->all(),
            'routes' => SiteRoute::query()->where('product_id', $id)->get()->map(fn (Model $row): array => $row->attributesToArray())->all(),
            'categories' => $this->pivot('product_taxonomies', 'product_id', $id),
        ];
    }

    /** @return array<string, mixed> */
    private function taxonomyRows(int $id): array
    {
        $taxonomy = Taxonomy::query()->with('translations')->findOrFail($id);

        return [
            'taxonomy' => $taxonomy->attributesToArray(),
            'translations' => $taxonomy->translations->map(fn (Model $row): array => $row->attributesToArray())->all(),
            'routes' => SiteRoute::query()->where('taxonomy_id', $id)->get()->map(fn (Model $row): array => $row->attributesToArray())->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function menuRows(int $id): array
    {
        $menu = Menu::query()->findOrFail($id);

        return [
            'menu' => $menu->attributesToArray(),
            'assignments' => MenuAssignment::query()->where('menu_id', $id)->get()->map(fn (Model $row): array => $row->attributesToArray())->all(),
            'items' => MenuItem::query()->with('translations')->where('menu_id', $id)->orderBy('id')->get()
                ->map(fn (MenuItem $row): array => $row->attributesToArray() + ['translations' => $row->translations->map(fn (Model $t): array => $t->attributesToArray())->all()])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function settingRows(string $anchor): array
    {
        [$group, $key] = explode('.', $anchor, 2);

        return ['setting' => SiteSetting::query()->where('group_name', $group)->where('key', $key)->first()?->attributesToArray()];
    }

    /** @return array<string, mixed> */
    private function profileRows(): array
    {
        $profile = SiteProfile::query()->with('translations')->first();

        return [
            'profile' => $profile?->attributesToArray(),
            'translations' => $profile?->translations->map(fn (Model $row): array => $row->attributesToArray())->all() ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function redirectRows(string $anchor): array
    {
        [$locale, $from] = explode(':', $anchor, 2);

        return ['redirect' => Redirect::query()->where('locale', $locale)->where('from_path', $from)->first()?->attributesToArray()];
    }

    /** @return list<array<string, mixed>> */
    private function pivot(string $table, string $column, int $id): array
    {
        return array_values(DB::table($table)->where('site_id', $this->site->id())->where($column, $id)->orderBy('position')->get()
            ->map(fn (object $row): array => Cast::arr((array) $row))->all());
    }
}
