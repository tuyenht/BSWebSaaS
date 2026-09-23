<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menus can link to a catalog product (design.md §13): `product` joins the link kinds that point
 * at a routable target, alongside `entry` and `taxonomy`. Catalog registers the `product` morph
 * alias; the URL comes from `routes.product_id`, which Cms owns, so Cms still imports nothing
 * from Catalog.
 *
 * The target CHECK also ties `routable_type` to `link_type` for those kinds. The menu resolver
 * picks the table by `link_type` while Eloquent's morph relation picks it by `routable_type`; a
 * row where they disagree would resolve against one table in the storefront and another in the
 * admin. Every writer already sets them equal — the CHECK makes it a rule for the menu editor
 * still to come.
 *
 * Each CHECK is swapped in one short transaction (drop old + add new NOT VALID), then validated
 * in its own statement so the scan holds no lock that blocks reads or writes; every step is
 * guarded, so a run that stops half way can be repeated. down() deletes product links first —
 * the old CHECKs forbid them — so rolling back loses them.
 */
return new class extends Migration
{
    /** Each step commits on its own so VALIDATE CONSTRAINT runs under its weaker lock. */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->swapCheck(
            'menu_items_link_type_check',
            "CHECK (link_type IN ('entry','taxonomy','product','dynamic','url'))",
        );
        $this->swapCheck('menu_items_target_check', <<<'SQL'
            CHECK (
                (link_type IN ('entry','taxonomy','product') AND routable_type IS NOT NULL AND routable_type = link_type AND routable_id IS NOT NULL AND url IS NULL AND dynamic_key IS NULL)
                OR (link_type = 'url'     AND url IS NOT NULL         AND routable_type IS NULL AND routable_id IS NULL AND dynamic_key IS NULL)
                OR (link_type = 'dynamic' AND dynamic_key IS NOT NULL AND routable_type IS NULL AND routable_id IS NULL AND url IS NULL)
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement("DELETE FROM menu_items WHERE link_type = 'product'");

        $this->swapCheck(
            'menu_items_link_type_check',
            "CHECK (link_type IN ('entry','taxonomy','dynamic','url'))",
        );
        $this->swapCheck('menu_items_target_check', <<<'SQL'
            CHECK (
                (link_type IN ('entry','taxonomy') AND routable_type IS NOT NULL AND routable_id IS NOT NULL AND url IS NULL AND dynamic_key IS NULL)
                OR (link_type = 'url'     AND url IS NOT NULL         AND routable_type IS NULL AND routable_id IS NULL AND dynamic_key IS NULL)
                OR (link_type = 'dynamic' AND dynamic_key IS NOT NULL AND routable_type IS NULL AND routable_id IS NULL AND url IS NULL)
            )
            SQL);
    }

    /** Replace a CHECK's definition, then validate existing rows outside the swap's lock. */
    private function swapCheck(string $name, string $check): void
    {
        DB::transaction(function () use ($name, $check): void {
            DB::statement("ALTER TABLE menu_items DROP CONSTRAINT IF EXISTS {$name}");
            DB::statement("ALTER TABLE menu_items ADD CONSTRAINT {$name} {$check} NOT VALID");
        });

        DB::statement("ALTER TABLE menu_items VALIDATE CONSTRAINT {$name}");
    }
};
