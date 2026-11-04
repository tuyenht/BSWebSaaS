<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Give every category and menu item made before they had a ulid its key (D2: the key screens put
 * into the page instead of the bigint id). The models fill it on every new row, so once this has
 * run on a database no NULL is left, and a later deploy can make the column NOT NULL and unique.
 *
 * Site by site, inside each site's tenant context (RLS and the site scope pick the site's own
 * rows). Each chunk of rows is its own short transaction, walked by id, so a large site never holds
 * its rows locked for long. Only rows whose ulid is still NULL are written (checked again in the
 * UPDATE), so running it again, or while the site is being edited, is harmless and reports 0. A key
 * fill is not an edit: updated_at is left as it was. Trashed menu items get their key too.
 */
final class FillMissingUlids extends Command
{
    private const int CHUNK = 500;

    /** @var list<string> the tables filled, in this order */
    private const array TABLES = ['taxonomies', 'menu_items'];

    #[\Override]
    protected $signature = 'cms:fill-ulids';

    #[\Override]
    protected $description = 'Give every category and menu item made before row keys existed its ulid (safe to re-run)';

    public function handle(Tenancy $tenancy): int
    {
        /** @var array<string, int> $filled */
        $filled = array_fill_keys(self::TABLES, 0);
        $sites = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            $sites++;

            foreach (self::TABLES as $table) {
                $after = 0;

                do {
                    [$read, $written, $after] = $tenancy->enter($siteId, fn (): array => $this->fillChunk($table, $siteId, $after));
                    $filled[$table] += $written;
                } while ($read === self::CHUNK);
            }
        }

        $this->info("Checked {$sites} site(s); filled {$filled['taxonomies']} category key(s) and {$filled['menu_items']} menu item key(s).");

        return self::SUCCESS;
    }

    /**
     * Fill the next chunk of the current site's rows that still have no ulid, after id $after.
     *
     * @return array{0: int, 1: int, 2: int} [rows read, rows written, last id read]
     */
    private function fillChunk(string $table, int $siteId, int $after): array
    {
        $ids = $this->query($table)
            ->whereNull('ulid')
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::CHUNK)
            ->pluck('id')
            ->map(static fn (mixed $id): int => Cast::int($id))
            ->all();

        if ($ids === []) {
            return [0, 0, $after];
        }

        $values = implode(', ', array_fill(0, count($ids), '(?::bigint, ?::char(26))'));
        $bindings = [];
        foreach ($ids as $id) {
            $bindings[] = $id;
            $bindings[] = (string) Str::ulid();
        }
        $bindings[] = $siteId;

        // $table is one of self::TABLES, never input.
        $written = DB::update(
            "UPDATE {$table} AS t SET ulid = v.ulid FROM (VALUES {$values}) AS v(id, ulid) WHERE t.id = v.id AND t.site_id = ? AND t.ulid IS NULL",
            $bindings,
        );

        return [count($ids), $written, (int) end($ids)];
    }

    /** @return Builder<Taxonomy>|Builder<MenuItem> */
    private function query(string $table): Builder
    {
        return $table === 'taxonomies' ? Taxonomy::query() : MenuItem::query()->withTrashed();
    }
}
