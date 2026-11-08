<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the products list (Admin\Livewire\Catalog\Index through ProductListQuery) and the
 * nightly emptying of the product Trash (products:purge-trash). Before them, the first page of the
 * list, a status tab and the Trash each read and sorted every product of the website.
 *
 *   - products_live_updated_idx: live products by last edit, the list's default order. Its first
 *     page, the quick filters (featured, best seller, stock, on sale: checked row by row along the
 *     walk), the numbered pages and the cursor past them.
 *   - products_live_status_idx: the same within one status (the Đã đăng / Nháp / Lưu trữ tabs).
 *     Without it a tab with few or no products walks the whole website on the first index.
 *   - products_trash_idx: the Trash by deletion time, newest first, and the purge's range
 *     `deleted_at < cutoff`, which mostly finds nothing due.
 *
 * Every index leads with site_id. The partial predicates are written exactly as the list sends them
 * (the soft-delete test is a literal in its SQL), so a generic plan still matches them. The date keys
 * are NULLS FIRST: read backwards they are the list's "newest first, never-dated last", read forwards
 * its way back from a cursor. The (site_id, status) index stays: it serves queries that take in the
 * Trash too.
 *
 * Measured on PostgreSQL 17 as app_user under RLS, best of 3 (ms), on 653,396 products loaded with
 * COPY in creation order: 3 websites of 200,000 and 2,000 of 10 to 80, 10% in the Trash (about half
 * of them past the 30 days), names in Vietnamese for 99% and in English for half the large websites'
 * products, a main category each; then VACUUM ANALYZE. On a website of 200,000:
 *
 *   first page, all products          103 -> 0.04
 *   a status tab (draft / archived)   46 / 34 -> 0.04; a status tab with no product: 181 on the
 *                                     last-edit index alone -> 0.02 with the status index
 *   the five tab counts               87 -> 15
 *   featured / out of stock / on sale 62 / 68 / 64 -> 0.3 / 0.1 / 0.2
 *   numbered page 500 (offset 9,980)  162 -> 10
 *   cursor after row 10,000           130 -> 8
 *   Trash, first page                 79 -> 0.04
 *   purge, nothing due                101 -> 0.02 (200 due: 4 -> 9)
 *   a website of 10 to 80, first page 0.11 -> 0.04
 *
 * A cursor deep in the list stays a range of the index only because ProductListQuery reads the
 * rows with an edit time and the rows without one as two parts: with one "past the cursor, or no
 * edit time" condition the walk starts at the index's end and filters row by row. Measured on one
 * website of 200,000 (one row in ten in the Trash), a cursor after row 150,000: 35.5 ms before the
 * indexes, 29 ms with them and the one condition, 0.04 ms with them and the two parts (the Trash
 * after row 15,000: 13.7 -> 0.07).
 *
 * Size on the 653,396 rows (table 255 MB): 24 + 34 + 2.6 MB. Write cost: the two live indexes hold
 * updated_at, which every edit moves, so an edit is no longer a heap-only update; editing 10,000
 * products went from 521 to 705-852 ms, inserting 20,000 from 808 to 929 ms.
 *
 * Not indexed: the name order (a left join on the default language's names sorts the website
 * whatever the index), the price order (the shown price depends on now()), the stock order (stock
 * moves with every order, so its index would cost the shop's hot path), and the search (LIKE is not
 * leakproof, so under RLS no index can serve it).
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * Expand-only; down() drops what up() added.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    /** @var array<string, string> index name => definition after the table */
    private const array INDEXES = [
        'products_live_updated_idx' => '(site_id, updated_at NULLS FIRST, id) WHERE deleted_at IS NULL',
        'products_live_status_idx' => '(site_id, status, updated_at NULLS FIRST, id) WHERE deleted_at IS NULL',
        'products_trash_idx' => '(site_id, deleted_at NULLS FIRST, id) WHERE deleted_at IS NOT NULL',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index => $definition) {
            $this->dropIfInvalid($index);

            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON products {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $index) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }

    private function dropIfInvalid(string $index): void
    {
        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid',
            [$index],
        );

        if ($invalid !== null) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }
};
