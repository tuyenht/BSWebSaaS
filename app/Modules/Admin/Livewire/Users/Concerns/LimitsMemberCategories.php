<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Users\Concerns;

use App\Modules\Admin\Support\UpgradeInvitation;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Models\Role;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * "Phạm vi làm việc" in one member's roles panel (design 22): the categories the member is limited
 * to on each axis — post categories, and product categories where the website sells products — as
 * the invite dialog draws them, plus what only an existing member has: limits that would be dropped
 * by a role that is never limited, limits partly outside the editor's own (read only, "nhờ Quản trị
 * đổi"), limits kept while the roles ticked do not work on that axis, and "Bỏ giới hạn" when the
 * plan no longer lets anyone pick.
 *
 * Only the block is drawn and the change worked out here; SetMemberCategoryScopes decides whether
 * it may be made when the panel is saved. Categories are addressed by ulid, never by bigint id. The
 * class using it uses DrawsCategoryScopes too.
 *
 * @phpstan-import-type AxisView from DrawsCategoryScopes
 */
trait LimitsMemberCategories
{
    /** @var array<string, string> per axis: "all" = every category of it, "only" = the ones picked */
    public array $panelScope = [CategoryScopes::POSTS => 'all', CategoryScopes::PRODUCTS => 'all'];

    /** @var array<string, list<string>> per axis: the categories picked (ulids) */
    public array $panelCategories = [CategoryScopes::POSTS => [], CategoryScopes::PRODUCTS => []];

    /** @var array<string, string> per axis: the search in its tree */
    public array $panelCatQ = [CategoryScopes::POSTS => '', CategoryScopes::PRODUCTS => ''];

    /** @var array<string, string> per axis: what is missing before saving */
    public array $panelScopeError = [CategoryScopes::POSTS => '', CategoryScopes::PRODUCTS => ''];

    public function updatedPanelScope(mixed $value = null, ?string $key = null): void
    {
        $this->clearPanelScopeError(self::axisOfKey($key));
    }

    public function updatedPanelCategories(mixed $value = null, ?string $key = null): void
    {
        $this->clearPanelScopeError(self::axisOfKey($key));
    }

    private function clearPanelScopeError(?string $axis): void
    {
        $errors = self::eachAxis('');
        if ($axis !== null) {
            foreach (CategoryScopes::AXES as $other) {
                $errors[$other] = $other === $axis ? '' : Cast::string($this->panelScopeError[$other] ?? '');
            }
        }
        $this->panelScopeError = $errors;
    }

    private function resetPanelScope(): void
    {
        $this->panelScope = self::eachAxis('all');
        $this->panelCategories = self::nonePicked();
        $this->panelCatQ = self::eachAxis('');
        $this->panelScopeError = self::eachAxis('');
    }

    /** The panel opens on the member's limits as saved: "only" with their categories picked, per axis. */
    private function fillPanelScope(User $target): void
    {
        $this->resetPanelScope();
        $scopes = app(CategoryScopes::class);
        $modes = $this->panelScope;
        $picks = $this->panelCategories;
        foreach (CategoryScopes::AXES as $axis) {
            $ulids = $this->scopeUlids($axis, $scopes->rootIds($target, $axis) ?? []);
            if ($ulids !== []) {
                $modes[$axis] = 'only';
                $picks[$axis] = $ulids;
            }
        }
        $this->panelScope = $modes;
        $this->panelCategories = $picks;
    }

    /**
     * What the block shows for the roles ticked, and the limits saving would leave on each axis
     * (`next`; an axis that is not shown, or read only, keeps what is saved). `bad` lists the axes
     * on "only" with nothing picked. With $draw, each axis's tree rows too.
     *
     * @param  Collection<int, Role>  $roles
     * @return array{high: bool, drop: string, none: bool, feat: bool, upsell: bool, axes: array<string, AxisView>, keep: list<string>, init: array<string, list<int>>, next: array<string, list<int>>, bad: list<string>}
     */
    private function panelScopeState(User $actor, User $target, Collection $roles, bool $draw = false): array
    {
        $scopes = app(CategoryScopes::class);
        $init = [];
        $limited = [];
        foreach (CategoryScopes::AXES as $axis) {
            $init[$axis] = $scopes->rootIds($target, $axis) ?? [];
            $limited[$axis] = $scopes->isLimited($actor, $axis);
        }
        $state = ['high' => false, 'drop' => '', 'none' => false, 'feat' => false, 'upsell' => false, 'axes' => [], 'keep' => [], 'init' => $init, 'next' => $init, 'bad' => []];
        if (Cast::int($roles->max('level')) >= CategoryScopes::EXEMPT_LEVEL) {
            // the member's limits go with such a role (ForgetMemberCategoryScopes): said before saving
            $now = [];
            foreach (CategoryScopes::AXES as $axis) {
                if ($init[$axis] !== []) {
                    $now[] = $this->scopeCurrent($axis, $init[$axis]);
                }
            }
            $state['high'] = true;
            $state['drop'] = $now === [] ? '' : __('admin.members.sc_drop_high', ['scopes' => implode(' · ', $now)]);

            return $state;
        }
        $roles->loadMissing('permissions');
        $list = array_values($roles->all());
        $works = [];
        foreach (CategoryScopes::AXES as $axis) {
            if ($this->rolesWorkOn($axis, $list, $limited[$axis])) {
                $works[] = $axis;
            } elseif ($init[$axis] !== [] && ($axis === CategoryScopes::POSTS || $this->catalogOn())) {
                // kept as saved: they apply again when the member is given such a role
                $state['keep'][] = __('admin.members.sc_keep', ['scope' => $this->scopeCurrent($axis, $init[$axis]), 'again' => self::axisWords($axis)['again']]);
            }
        }
        if ($works === []) {
            $state['none'] = true;

            return $state;
        }
        $feat = app(Entitlement::class)->featureEnabled('rbac.category_scopes');
        $state['feat'] = $feat;
        $state['upsell'] = ! $feat && app(UpgradeInvitation::class)->isOn();
        $first = Str::afterLast(trim($target->name), ' ');
        foreach ($works as $axis) {
            $saved = $init[$axis];
            $own = $limited[$axis];
            // limits partly outside the editor's own: shown, never changed from here
            $ro = $own && $saved !== [] && array_filter($saved, fn (int $id): bool => ! $scopes->coversId($actor, $id, $axis)) !== [];
            $mode = self::modeOn($this->panelScope, $axis);
            if (! $feat && $saved === []) {
                $mode = 'all';   // without the feature nobody picks: a forged "only" changes nothing
            } elseif ($own) {
                $mode = 'only';   // a limited editor never leaves the member with every category (as in the invite dialog)
            }
            $picks = self::picksOn($this->panelCategories, $axis);
            $tree = ! $ro && $feat && $mode === 'only';
            $sel = $tree ? $this->scopeTree($axis)->normalise($picks) : [];
            $state['next'][$axis] = ($ro || (! $feat && $mode === 'only')) ? $saved : ($mode === 'only' ? $sel : []);
            if ($tree && $sel === []) {
                $state['bad'][] = $axis;
            }
            $query = self::queryOn($this->panelCatQ, $axis);
            $words = self::axisWords($axis);
            $state['axes'][$axis] = [
                'axis' => $axis,
                'words' => $words,
                'limited' => $own,
                'mode' => $mode,
                'ids' => $state['next'][$axis],
                'cur' => $saved === [] ? '' : $this->scopeCurrent($axis, $saved),
                'ro' => $ro,
                'roText' => __('admin.members.sc_ro', ['name' => $first]),
                'lim' => ! $ro && ! $feat && $mode === 'only' && $saved !== [],
                'limText' => $saved === [] ? '' : __('admin.members.sc_lim', ['scope' => $this->scopeCurrent($axis, $saved)]),
                'liftLocked' => $own,
                'liftNote' => $own ? __('admin.members.inv_sc_own') : __('admin.members.sc_lift_note'),
                'radios' => ! $ro,
                'allDis' => ! $feat || $own,
                'onlyDis' => ! $feat,
                'allWhy' => $own,
                'tree' => $tree,
                'rows' => $draw && $tree ? $this->scopeTreeRows($actor, $axis, $picks, $query, $feat, $own) : [],
                'q' => $query,
                'error' => Cast::string($this->panelScopeError[$axis] ?? ''),
            ];
        }

        return $state;
    }

    /**
     * The sentences the saved toast adds for each axis whose limits change (design 22).
     *
     * @param  array<string, list<int>>  $changes  the new limits by axis ([] = every category)
     * @return list<string>
     */
    private function scopeSaidLines(User $target, array $changes): array
    {
        $first = Str::afterLast(trim($target->name), ' ');
        $lines = [];
        foreach (CategoryScopes::AXES as $axis) {
            if (! array_key_exists($axis, $changes)) {
                continue;
            }
            $names = implode(', ', $this->axisNames($axis, $changes[$axis]));
            $lines[] = $axis === CategoryScopes::PRODUCTS
                ? ($changes[$axis] === [] ? __('admin.members.sc_said_pr_all', ['name' => $first]) : __('admin.members.sc_said_pr_only', ['name' => $first, 'names' => $names]))
                : ($changes[$axis] === [] ? __('admin.members.sc_said_posts_all', ['name' => $first]) : __('admin.members.sc_said_posts_only', ['name' => $first, 'names' => $names]));
        }

        return $lines;
    }
}
