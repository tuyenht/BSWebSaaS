<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Users\Concerns;

use App\Modules\Admin\Support\UpgradeInvitation;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Taxonomy\CategoryLimitGuard;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Models\Role;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Phạm vi làm việc" in the invite dialog (design 22): the categories the invited person will be
 * limited to on each axis — post categories, and product categories where the website sells
 * products — every category, or only the ones picked (a category includes everything below it).
 * The block follows the roles ticked: a role that is never limited needs no scope, and an axis shows
 * only for roles that work on it (write posts / change products). Picking needs the site's
 * `rbac.category_scopes` feature; a limited inviter picks only inside their own limits of that axis,
 * and never "every category" of it.
 *
 * Only the block is drawn here; whether the limit may be set is decided by InviteMemberWithCategories
 * when the invitation is sent. Categories are addressed by ulid, never by bigint id. The class
 * using it uses DrawsCategoryScopes too.
 *
 * @phpstan-import-type AxisView from DrawsCategoryScopes
 */
trait LimitsInvitationCategories
{
    /** @var array<string, string> per axis: "all" = every category of it, "only" = the ones picked */
    public array $inviteScope = [CategoryScopes::POSTS => 'all', CategoryScopes::PRODUCTS => 'all'];

    /** @var array<string, list<string>> per axis: the categories picked (ulids) */
    public array $inviteCategories = [CategoryScopes::POSTS => [], CategoryScopes::PRODUCTS => []];

    /** @var array<string, string> per axis: the search in its tree */
    public array $inviteCatQ = [CategoryScopes::POSTS => '', CategoryScopes::PRODUCTS => ''];

    /** @var array<string, string> per axis: what is missing before sending */
    public array $inviteScopeError = [CategoryScopes::POSTS => '', CategoryScopes::PRODUCTS => ''];

    public function updatedInviteScope(mixed $value = null, ?string $key = null): void
    {
        $this->clearInviteScopeError(self::axisOfKey($key));
    }

    public function updatedInviteCategories(mixed $value = null, ?string $key = null): void
    {
        $this->clearInviteScopeError(self::axisOfKey($key));
    }

    private function clearInviteScopeError(?string $axis): void
    {
        $errors = self::eachAxis('');
        if ($axis !== null) {
            foreach (CategoryScopes::AXES as $other) {
                $errors[$other] = $other === $axis ? '' : Cast::string($this->inviteScopeError[$other] ?? '');
            }
        }
        $this->inviteScopeError = $errors;
    }

    /** A clean scope, as the dialog opens: on an axis a limited inviter starts on "only" (the only choice they have). */
    private function resetInviteScope(): void
    {
        $scopes = app(CategoryScopes::class);
        $actor = $this->actor();
        $modes = [];
        foreach (CategoryScopes::AXES as $axis) {
            $modes[$axis] = $scopes->isLimited($actor, $axis) ? 'only' : 'all';
        }
        $this->inviteScope = $modes;
        $this->inviteCategories = self::nonePicked();
        $this->inviteCatQ = self::eachAxis('');
        $this->inviteScopeError = self::eachAxis('');
    }

    /** Has the block been touched since it was reset (something picked, or "only" chosen)? */
    private function inviteScopeTouched(): bool
    {
        foreach (CategoryScopes::AXES as $axis) {
            if (self::modeOn($this->inviteScope, $axis) === 'only' || self::picksOn($this->inviteCategories, $axis) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The scope an invitation carried, for "Mời lại": its categories (still in their tree) picked,
     * each on its own axis.
     *
     * @param  list<int>  $taxonomyIds
     */
    private function fillInviteScope(array $taxonomyIds): void
    {
        $this->resetInviteScope();
        $modes = $this->inviteScope;
        $picks = $this->inviteCategories;
        foreach (CategoryScopes::AXES as $axis) {
            $ulids = $this->scopeUlids($axis, $taxonomyIds);
            if ($ulids !== []) {
                $modes[$axis] = 'only';
                $picks[$axis] = $ulids;
            }
        }
        $this->inviteScope = $modes;
        $this->inviteCategories = $picks;
    }

    /**
     * What the block shows for the roles ticked, and the categories it sends on each axis it shows
     * (an axis it does not show is not sent). With $draw, each axis's tree rows too.
     *
     * @param  Collection<int, Role>  $roles
     * @return array{show: bool, high: bool, none: bool, feat: bool, upsell: bool, axes: array<string, AxisView>}
     */
    private function inviteScopeState(User $actor, Collection $roles, bool $draw = false): array
    {
        $scopes = app(CategoryScopes::class);
        $limited = [];
        foreach (CategoryScopes::AXES as $axis) {
            $limited[$axis] = $scopes->isLimited($actor, $axis);
        }
        $level = Cast::int($roles->max('level'));
        $state = ['show' => false, 'high' => false, 'none' => false, 'feat' => false, 'upsell' => false, 'axes' => []];
        // someone who can neither set limits (users.manage, above the roles) nor is held to their own sees no block
        if (! in_array(true, $limited, true) && ! app(CategoryLimitGuard::class)->mayChangeLimitsOf([['id' => 0, 'name' => '', 'level' => 0]])) {
            return $state;
        }
        $state['show'] = true;
        if ($level >= CategoryScopes::EXEMPT_LEVEL) {
            $state['high'] = true;

            return $state;
        }
        $roles->loadMissing('permissions');
        $list = array_values($roles->all());
        $works = array_values(array_filter(CategoryScopes::AXES, fn (string $axis): bool => $this->rolesWorkOn($axis, $list, $limited[$axis])));
        if ($works === []) {
            $state['none'] = true;

            return $state;
        }
        $feat = app(Entitlement::class)->featureEnabled('rbac.category_scopes');
        $state['feat'] = $feat;
        $state['upsell'] = ! $feat && app(UpgradeInvitation::class)->isOn();
        foreach ($works as $axis) {
            // a limited inviter never hands out "every category"; without the feature nobody picks
            $mode = $limited[$axis] ? 'only' : ($feat && self::modeOn($this->inviteScope, $axis) === 'only' ? 'only' : 'all');
            $picks = self::picksOn($this->inviteCategories, $axis);
            $tree = $feat && $mode === 'only';
            $query = self::queryOn($this->inviteCatQ, $axis);
            $rows = $draw && $tree ? $this->scopeTreeRows($actor, $axis, $picks, $query, $feat, $limited[$axis]) : [];
            $state['axes'][$axis] = [
                'axis' => $axis,
                'words' => self::axisWords($axis),
                'limited' => $limited[$axis],
                'mode' => $mode,
                'ids' => $tree ? $this->scopeTree($axis)->normalise($picks) : [],
                'cur' => '', 'ro' => false, 'roText' => '', 'lim' => false, 'limText' => '', 'liftLocked' => false, 'liftNote' => '',
                'radios' => true,
                'allDis' => ! $feat || $limited[$axis],
                'onlyDis' => ! $feat,
                'allWhy' => $limited[$axis],
                'tree' => $tree,
                'rows' => $rows,
                'q' => $query,
                'error' => Cast::string($this->inviteScopeError[$axis] ?? ''),
            ];
        }

        return $state;
    }
}
