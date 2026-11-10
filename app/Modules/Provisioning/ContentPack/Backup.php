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
use App\Modules\Forms\Models\Form;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Before --apply writes anything: a gzip JSON file of every row the plan is about to change (per
 * item: the record, its translations, routes, category links, images, image-usage rows, menu
 * tree or form fields; for a category, the routes of the posts and products it carries along; settings; the
 * profile; redirects) plus every redirect from or to an address the load moves, writes or vacates
 * (the new addresses of the posts and products a category carries along included),
 * and the previous ledger, so one item can be inspected or restored by hand.
 * `storage/app/private/content-pack-backups/<site-ulid>/<pack>-v<version>-<UTC>-<fingerprint>.json.gz`, mode
 * 0600, flushed to disk with its folder and re-read before the write transaction opens. Rows are read inside the
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
        $paths = [];
        foreach ($plan->writes() as $planned) {
            $rows = $planned->recordId === null && ! in_array($planned->item->kind, ['setting', 'profile', 'redirect', 'menu'], true)
                ? null
                : $this->rows($planned, $planner);
            $items[] = [
                'key' => $planned->item->key,
                'verdict' => $planned->verdict,
                'forced' => $planned->forced,
                'ref' => $planned->ref,
                'rows' => $rows,
            ];
            foreach ([...Cast::arr($rows['routes'] ?? null), ...Cast::arr($rows['branch_routes'] ?? null)] as $route) {
                $route = Cast::arr($route);
                $paths[Cast::string($route['locale'] ?? null)][Cast::string($route['path'] ?? null)] = true;
            }
            foreach ($planner->plannedPaths()[$planned->item->key] ?? [] as $locale => $path) {
                $paths[$locale][$path] = true;
            }
            // The new addresses of the posts and products a category write carries along: RouteSync
            // drops the redirect from each of them too.
            foreach ($planner->carriedMoves()[$planned->item->key] ?? [] as $move) {
                $paths[$move['locale']][$move['to']] = true;
            }
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
            'redirects' => $this->redirectsAround($paths),
        ];
    }

    /**
     * Every redirect from or to one of these addresses: RouteSync adds one from each old address,
     * points the ones that led to it at the new one, and drops the one from a new address.
     *
     * @param  array<string, array<string, true>>  $paths  locale → path
     * @return list<array<string, mixed>>
     */
    private function redirectsAround(array $paths): array
    {
        $rows = [];
        foreach ($paths as $locale => $set) {
            foreach (array_chunk(array_keys($set), 1000) as $chunk) {
                $found = Redirect::query()->where('locale', $locale)
                    ->where(fn ($query) => $query->whereIn('from_path', $chunk)->orWhereIn('to_path', $chunk))
                    ->orderBy('id')->get();
                foreach ($found as $redirect) {
                    $rows[$redirect->id] = $redirect->attributesToArray();
                }
            }
        }
        ksort($rows);

        return array_values($rows);
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
            // The file's directory entry must reach the disk too, or a crash can lose the file.
            $folder = $ok ? @fopen($dir, 'r') : false;
            $ok = $folder !== false && fsync($folder) && fclose($folder);
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
            'taxonomy' => $this->taxonomyRows((int) $id, $planned->item->key, $planner),
            'menu' => $this->menuRows($id, $planned->item->anchor(), $planner->defaultLocale()),
            'form' => $this->formRows((int) $id),
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
            // The image-usage index the media reconciler rewrites after a body write.
            'mediables' => array_values(DB::table('mediables')->where('site_id', $this->site->id())->where('mediable_type', 'entry')->where('mediable_id', $id)
                ->orderBy('id')->get()->map(fn (object $row): array => Cast::arr((array) $row))->all()),
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
    private function taxonomyRows(int $id, string $key, Planner $planner): array
    {
        $taxonomy = Taxonomy::query()->with('translations')->findOrFail($id);
        $branch = [];
        foreach (array_chunk($planner->branchRoutes($key), 1000) as $chunk) {
            array_push($branch, ...SiteRoute::query()->whereIn('id', $chunk)->orderBy('id')->get()->map(fn (Model $row): array => $row->attributesToArray())->all());
        }

        return [
            'taxonomy' => $taxonomy->attributesToArray(),
            'translations' => $taxonomy->translations->map(fn (Model $row): array => $row->attributesToArray())->all(),
            'routes' => SiteRoute::query()->where('taxonomy_id', $id)->get()->map(fn (Model $row): array => $row->attributesToArray())->all(),
            // The posts and products filed under the branch, whose addresses a rename or move changes.
            'branch_routes' => $branch,
        ];
    }

    /**
     * The menu, its tree and the region rows the apply writes. A new menu ($id null) has no rows of
     * its own, but the region it is bound to may follow another region ("Dùng trình đơn chung"):
     * the apply replaces that row with the new menu, so the row is kept here. The trashed items are
     * kept too, since the apply replaces the whole tree, trash included.
     *
     * @return array<string, mixed>
     */
    private function menuRows(?int $id, string $region, string $locale): array
    {
        $menu = $id === null ? null : Menu::query()->findOrFail($id);

        return [
            'menu' => $menu?->attributesToArray(),
            'assignments' => MenuAssignment::query()
                ->where(fn ($query) => $query
                    ->where(fn ($bound) => $bound->where('region_key', $region)->where('locale', $locale))
                    ->when($id !== null, fn ($query) => $query->orWhere('menu_id', $id)))
                ->orderBy('id')->get()->map(fn (Model $row): array => $row->attributesToArray())->all(),
            'items' => $id === null ? [] : MenuItem::withTrashed()->with('translations')->where('menu_id', $id)->orderBy('id')->get()
                ->map(fn (MenuItem $row): array => $row->attributesToArray() + ['translations' => $row->translations->map(fn (Model $t): array => $t->attributesToArray())->all()])->all(),
        ];
    }

    /**
     * The form row (its settings hold the thank-you page) and every one of its fields.
     *
     * @return array<string, mixed>
     */
    private function formRows(int $id): array
    {
        $form = Form::query()->with('fields')->findOrFail($id);

        return [
            'form' => $form->attributesToArray(),
            'fields' => $form->fields->map(fn (Model $row): array => $row->attributesToArray())->all(),
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
