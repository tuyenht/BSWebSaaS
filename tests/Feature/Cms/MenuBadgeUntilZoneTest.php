<?php

use App\Modules\Cms\Actions\Menus\ApplyMenuDraft;
use App\Modules\Cms\Actions\Menus\SaveMenu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Navigation\MenuVersion;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * A menu badge's end, given in the website's clock (UTC+7), is stored as the same instant in UTC:
 * a date ends at the end of that day in site time; a time typed with the site's offset in the
 * evening keeps its instant (23:30 at +07:00 is 16:30Z the same day).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    $this->zbEditor = menuEditor(1);
});

it('stores a badge end given as an evening site time as the same UTC instant', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $menu = app(SaveMenu::class)->handle(null, 'Menu chính');
        $item = MenuItem::query()->create(['menu_id' => $menu->id, 'parent_id' => null, 'position' => 0, 'link_type' => 'dynamic', 'dynamic_key' => 'home', 'meta' => []]);
        $item->translations()->create(['locale' => 'vi', 'label' => 'Trang chủ']);

        $until = function (string $given) use ($menu, $item): ?string {
            [$items, $labels] = app(ApplyMenuDraft::class)->read($menu->id);
            app(ApplyMenuDraft::class)->handle($this->zbEditor, $menu, [
                'version' => MenuVersion::of($menu->fresh(), $items, $labels),
                'tree' => [['key' => $item->ulid, 'parent' => null]],
                'updates' => [['key' => $item->ulid, 'options' => ['badge' => ['kind' => 'new', 'until' => $given]]]],
            ]);

            $row = DB::selectOne("select meta->>'badge_until' as until from menu_items where id = ?", [$item->id]);

            return $row->until;
        };

        expect($until('2026-12-01T23:30:00+07:00'))->toBe('2026-12-01T16:30:00Z')
            ->and($until('2026-12-01T17:00:00+07:00'))->toBe('2026-12-01T10:00:00Z')
            ->and($until('2026-12-01'))->toBe('2026-12-01T16:59:59Z');
    });
});
