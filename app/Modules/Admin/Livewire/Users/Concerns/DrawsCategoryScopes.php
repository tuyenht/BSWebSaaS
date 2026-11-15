<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Users\Concerns;

use App\Modules\Admin\Support\Members\InviteCategoryTree;
use App\Modules\Cms\Actions\InviteMemberWithCategories;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Models\Role;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Entitlement\Entitlement;

/**
 * What the "Phạm vi làm việc" block of design 22 shares between the invite dialog and a member's
 * panel: its two axes — post categories (CategoryScopes::POSTS) and product categories
 * (CategoryScopes::PRODUCTS) — whether the roles ticked work on an axis, the tree of each axis and
 * the rows it lists, and the words of each axis. The state of the block lives in public arrays keyed
 * by axis; they come from the browser, so every read goes through modeOn / picksOn / queryOn.
 *
 * Drawing only: what may be set is decided by InviteMemberWithCategories and SetMemberCategoryScopes.
 *
 * @phpstan-type AxisView array{axis: string, words: array{title: string, icon: string, all: string, only: string, q: string, ph: string, tree: string, need: string, again: string}, limited: bool, mode: string, ids: list<int>, cur: string, ro: bool, roText: string, lim: bool, limText: string, liftLocked: bool, liftNote: string, radios: bool, allDis: bool, onlyDis: bool, allWhy: bool, tree: bool, rows: list<array{ulid: string, name: string, depth: int, path: string, on: bool, inc: bool, dis: bool, note: string}>, q: string, error: string}
 */
trait DrawsCategoryScopes
{
    /** @var array<string, InviteCategoryTree> the trees read for this request, by axis */
    private array $scopeTrees = [];

    private ?bool $catalogOn = null;

    /** @return array<string, string> every axis set to $value */
    private static function eachAxis(string $value): array
    {
        return array_fill_keys(CategoryScopes::AXES, $value);
    }

    /** @return array<string, list<string>> every axis with nothing picked */
    private static function nonePicked(): array
    {
        return array_fill_keys(CategoryScopes::AXES, []);
    }

    /**
     * "all" or "only" for $axis, from a state array the browser sent back.
     *
     * @param  array<mixed>  $modes
     */
    private static function modeOn(array $modes, string $axis): string
    {
        return ($modes[$axis] ?? 'all') === 'only' ? 'only' : 'all';
    }

    /**
     * @param  array<mixed>  $picks
     * @return list<string> the ulids picked on $axis
     */
    private static function picksOn(array $picks, string $axis): array
    {
        return array_values(array_filter(Cast::arr($picks[$axis] ?? []), 'is_string'));
    }

    /** @param  array<mixed>  $queries */
    private static function queryOn(array $queries, string $axis): string
    {
        return mb_substr(Cast::string($queries[$axis] ?? ''), 0, 100);
    }

    /** The axis a wire:model key names ("product_cat" or "product_cat.0"), or null. */
    private static function axisOfKey(?string $key): ?string
    {
        $axis = explode('.', (string) $key)[0];

        return CategoryScopes::isAxis($axis) ? $axis : null;
    }

    /**
     * The validation rules of one block's state arrays.
     *
     * @return array<string, string>
     */
    private static function scopeRules(string $modes, string $picks, string $queries): array
    {
        return [
            $modes => 'array',
            $modes.'.*' => 'in:all,only',
            $picks => 'array',
            $picks.'.*' => 'array|max:'.InviteCategoryTree::LIMIT,
            $picks.'.*.*' => 'string|max:26',
            $queries => 'array',
            $queries.'.*' => 'nullable|string|max:100',
        ];
    }

    private function scopeTree(string $axis): InviteCategoryTree
    {
        return $this->scopeTrees[$axis] ??= InviteCategoryTree::load(app(CurrentSite::class)->id(), app(SiteLocale::class)->default(), $axis);
    }

    /** The website sells products (its catalog module is on): the product axis exists there. */
    private function catalogOn(): bool
    {
        return $this->catalogOn ??= in_array('catalog', app(Entitlement::class)->entitledModuleKeys(), true);
    }

    /**
     * Do $roles work on $axis — write posts, or change products — by the server's own test? The
     * product axis shows only where the catalog is on, or to someone held to product limits (the
     * server holds them to those whatever the module).
     *
     * @param  list<Role>  $roles  with their permissions
     */
    private function rolesWorkOn(string $axis, array $roles, bool $actorLimited): bool
    {
        if ($axis === CategoryScopes::PRODUCTS) {
            return InviteMemberWithCategories::managesProducts($roles) && ($actorLimited || $this->catalogOn());
        }

        return InviteMemberWithCategories::writesPosts($roles);
    }

    /**
     * The words of one axis, as the block writes them.
     *
     * @return array{title: string, icon: string, all: string, only: string, q: string, ph: string, tree: string, need: string, again: string}
     */
    private static function axisWords(string $axis): array
    {
        if ($axis === CategoryScopes::PRODUCTS) {
            return [
                'title' => __('admin.members.sc_products'), 'icon' => 'ri-shopping-bag-3',
                'all' => __('admin.members.sc_pr_all'), 'only' => __('admin.members.sc_pr_only'),
                'q' => __('admin.members.sc_pr_q'), 'ph' => __('admin.members.sc_pr_ph'), 'tree' => __('admin.members.sc_pr_tree'),
                'need' => __('admin.members.sc_pr_need'), 'again' => __('admin.members.sc_again_products'),
            ];
        }

        return [
            'title' => __('admin.members.inv_sc_posts'), 'icon' => 'ri-article',
            'all' => __('admin.members.inv_sc_all'), 'only' => __('admin.members.inv_sc_only'),
            'q' => __('admin.members.inv_sc_q'), 'ph' => __('admin.members.inv_sc_ph'), 'tree' => __('admin.members.inv_sc_tree'),
            'need' => __('admin.members.inv_sc_err'), 'again' => __('admin.members.sc_again_posts'),
        ];
    }

    /**
     * "Sản phẩm — chỉ: Phụ tùng, Khuôn mẫu": an axis's limits, named.
     *
     * @param  list<int>  $ids
     */
    private function scopeCurrent(string $axis, array $ids): string
    {
        return __('admin.members.sc_cur', ['axis' => self::axisWords($axis)['title'], 'names' => implode(', ', $this->axisNames($axis, $ids))]);
    }

    /**
     * @param  list<int>  $ids
     * @return list<string> the names of $ids, in the tree's order
     */
    private function axisNames(string $axis, array $ids): array
    {
        $tree = $this->scopeTree($axis);
        $want = array_flip($ids);
        $names = [];
        foreach ($tree->rows() as $row) {
            if (isset($want[$row['id']])) {
                $names[] = $row['name'];
            }
        }

        return $names;
    }

    /**
     * @param  list<int>  $ids
     * @return list<string> their ulids (those still in the tree)
     */
    private function scopeUlids(string $axis, array $ids): array
    {
        $tree = $this->scopeTree($axis);

        return array_values(array_filter(array_map(fn (int $id): ?string => $tree->ulidOf($id), $ids)));
    }

    /**
     * The tree rows an axis lists: depth, the path above while searching, ticked, locked and why.
     *
     * @param  list<string>  $picked  ulids
     * @return list<array{ulid: string, name: string, depth: int, path: string, on: bool, inc: bool, dis: bool, note: string}>
     */
    private function scopeTreeRows(User $actor, string $axis, array $picked, string $query, bool $feat, bool $limited): array
    {
        $tree = $this->scopeTree($axis);
        $scopes = app(CategoryScopes::class);
        $picked = array_flip(array_map('strtoupper', $picked));
        $q = InviteCategoryTree::fold($query);
        $ulidOf = [];
        $rows = [];
        foreach ($tree->rows() as $row) {
            $ulidOf[$row['id']] = $row['ulid'];
            if ($q !== '' && ! str_contains(InviteCategoryTree::fold($row['name']), $q)) {
                continue;
            }
            $inc = false;
            foreach ($row['above'] as $above) {
                if (isset($picked[$ulidOf[$above] ?? ''])) {
                    $inc = true;
                    break;
                }
            }
            $outside = $limited && ! $scopes->coversId($actor, $row['id'], $axis);
            $rows[] = [
                'ulid' => $row['ulid'],
                'name' => $row['name'],
                'depth' => $q !== '' ? 0 : min(3, $row['depth']),
                'path' => $q !== '' && $row['above'] !== [] ? implode(' › ', array_map(fn (int $id): string => (string) $tree->name($id), $row['above'])).' › ' : '',
                'on' => $inc || isset($picked[$row['ulid']]),
                'inc' => $inc,
                'dis' => $inc || $outside || ! $feat,
                'note' => $inc ? __('admin.members.inv_sc_included') : ($outside ? __('admin.members.inv_sc_outside') : ''),
            ];
        }

        return $rows;
    }
}
