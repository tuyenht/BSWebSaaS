<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\HomePages;
use App\Modules\Cms\Support\PageRules;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Support\Facades\DB;

/**
 * What the page editor's «Trang cha» offers (board 07 «Trình sửa trang»): «Không có (trang gốc)» first,
 * then the live pages of the website in website order, indented by level. A page that cannot be the
 * parent stays in the list, greyed, with the reason: the page being edited, a page under it, and a page
 * whose level would push the page (with its sub-pages) past PageRules::MAX_DEPTH. The Home page is not
 * listed (it has no sub-pages). A page under a page in the Trash is not offered, as in «Chuyển đến…».
 *
 * The tree is read in one statement, one bounded level after another from the top (each level at most
 * PageRules::WALK_SIZE + 1 pages, in (position, id) order, its title in the website's default language),
 * then which of them is the Home page (HomePages, one read). Past WALK_SIZE pages the list is cut and
 * `more` says so, as the «Chuyển đến…» dialog does. MovePage and the editor's first save check the
 * choice again, under the page-tree lock.
 */
final readonly class PageParentChoices
{
    public function __construct(
        private CurrentSite $site,
        private SiteLocale $locales,
    ) {}

    /**
     * The choices for $page (null: a page not saved yet, which has no sub-pages).
     *
     * @return array{more: bool, opts: list<array{k: string|null, name: string, lv: int, why: string|null}>}
     */
    public function for(?Entry $page): array
    {
        $cap = PageRules::WALK_SIZE;
        [$rows, $more] = $this->tree($cap);
        $homes = HomePages::among(array_map(static fn (array $row): string => $row['ulid'], $rows));

        $kids = [];
        foreach ($rows as $row) {
            $kids[$row['parent'] ?? 0][] = $row;
        }

        $height = $page === null ? 1 : PageRules::heightOf($page);
        $current = $page?->parent_id;
        $out = [[
            'k' => null,
            'name' => Cast::string(__('entries.ed.par_none')),
            'lv' => 0,
            'why' => match (true) {
                $current === null => null,
                $height === null => Cast::string(__('entries.move_refused_branch_too_big')),
                $height > PageRules::MAX_DEPTH => Cast::string(__('entries.ed.par_why_branch')),
                default => null,
            },
        ]];
        $seen = [];
        $walk = function (int $parentId, int $lv, bool $inside) use (&$walk, &$out, &$seen, $kids, $homes, $page, $height, $current): void {
            foreach ($kids[$parentId] ?? [] as $row) {
                if (isset($seen[$row['id']])) {
                    continue;
                }
                $seen[$row['id']] = true;
                $self = $page !== null && $row['id'] === $page->id;
                $under = $inside || $self;
                if (! isset($homes[$row['id']])) {
                    $out[] = ['k' => $row['ulid'], 'name' => $row['title'], 'lv' => $lv, 'why' => match (true) {
                        $self => Cast::string(__('entries.ed.par_why_self')),
                        $inside => Cast::string(__('entries.ed.par_why_sub')),
                        $row['id'] === $current => null,
                        $height === null => Cast::string(__('entries.move_refused_branch_too_big')),
                        $lv + $height > PageRules::MAX_DEPTH => $height > 1
                            ? Cast::string(__('entries.ed.par_why_branch'))
                            : Cast::string(__('entries.ed.par_why_level', ['n' => $lv + 1])),
                        default => null,
                    }];
                }
                $walk($row['id'], $lv + 1, $under);
            }
        };
        $walk(0, 1, false);

        return ['more' => $more, 'opts' => $out];
    }

    /**
     * The live pages from the top, PageRules::MAX_DEPTH levels deep, in level order and (position, id)
     * order within a level, at most $cap in all; true when there were more.
     *
     * @return array{0: list<array{id: int, ulid: string, parent: int|null, title: string}>, 1: bool}
     */
    private function tree(int $cap): array
    {
        $site = $this->site->id();
        $levels = [];
        $bindings = [];
        for ($lv = 1; $lv <= PageRules::MAX_DEPTH; $lv++) {
            $under = $lv === 1 ? 'e.parent_id is null' : 'e.parent_id in (select id from l'.($lv - 1).')';
            $levels[] = "l{$lv} as (select e.id, e.ulid, e.parent_id, {$lv} as lv, e.position from entries e "
                ."where e.site_id = ? and e.type = 'page' and e.deleted_at is null and {$under} order by e.position, e.id limit ?)";
            array_push($bindings, $site, $cap + 1);
        }
        $union = implode(' union all ', array_map(static fn (int $lv): string => "select * from l{$lv}", range(1, PageRules::MAX_DEPTH)));
        $sql = 'with '.implode(', ', $levels).' select t.id, t.ulid, t.parent_id, t.lv, '
            ."(select tr.title from entry_translations tr where tr.site_id = ? and tr.entry_id = t.id and tr.title <> '' "
            .'order by tr.locale = ? desc, tr.locale limit 1) as title '
            ."from ({$union}) t order by t.lv, t.position, t.id";
        array_push($bindings, $site, $this->locales->default());

        $rows = [];
        $kept = [];
        $more = false;
        foreach (DB::select($sql, $bindings) as $row) {
            $row = (array) $row;
            $id = Cast::int($row['id'] ?? 0);
            $parent = $row['parent_id'] ?? null;
            $parent = $parent === null ? null : Cast::int($parent);
            if ($parent !== null && ! isset($kept[$parent])) {
                continue;   // under a page the cap left out
            }
            if (count($rows) >= $cap) {
                $more = true;
                break;
            }
            $title = $row['title'] ?? null;
            $kept[$id] = true;
            $rows[] = ['id' => $id, 'ulid' => Cast::string($row['ulid'] ?? ''), 'parent' => $parent,
                'title' => is_string($title) && $title !== '' ? $title : Cast::string(__('entries.untitled'))];
        }

        return [$rows, $more];
    }
}
