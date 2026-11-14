<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Users\Concerns;

use App\Modules\Admin\Support\Members\InviteCategoryTree;
use App\Modules\Admin\Support\UpgradeInvitation;
use App\Modules\Cms\Actions\InviteMemberWithCategories;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Taxonomy\CategoryLimitGuard;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Models\Role;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Phạm vi làm việc" in the invite dialog (design 22): the post categories the invited person will
 * be limited to — every category, or only the ones picked (a category includes everything below it).
 * The block follows the roles ticked: a role that is never limited needs no scope, roles that do not
 * write posts need none either. Picking needs the site's `rbac.category_scopes` feature; a limited
 * inviter picks only inside their own limits, and never "every category".
 *
 * Only the block is drawn here; whether the limit may be set is decided by InviteMemberWithCategories
 * when the invitation is sent. Categories are addressed by ulid, never by bigint id. The product axis
 * of the design waits for its backend and is not drawn.
 */
trait LimitsInvitationCategories
{
    /** "all" = every post category, "only" = the ones picked. */
    public string $inviteScope = 'all';

    /** @var list<string> the post categories picked (ulids) */
    public array $inviteCategories = [];

    /** The search in the category tree. */
    public string $inviteCatQ = '';

    public string $inviteScopeError = '';

    /** The tree read for this request (the dialog reads it once per render). */
    private ?InviteCategoryTree $inviteTree = null;

    public function updatedInviteScope(): void
    {
        $this->inviteScopeError = '';
    }

    public function updatedInviteCategories(): void
    {
        $this->inviteScopeError = '';
    }

    /** A clean scope, as the dialog opens: a limited inviter starts on "only" (the only choice they have). */
    private function resetInviteScope(): void
    {
        $this->inviteScope = app(CategoryScopes::class)->isLimited($this->actor()) ? 'only' : 'all';
        $this->inviteCategories = [];
        $this->inviteCatQ = '';
        $this->inviteScopeError = '';
    }

    /**
     * The scope an invitation carried, for "Mời lại": its categories (still in the tree) picked.
     *
     * @param  list<int>  $taxonomyIds
     */
    private function fillInviteScope(array $taxonomyIds): void
    {
        $this->resetInviteScope();
        $tree = $this->inviteTree();
        $ulids = array_values(array_filter(array_map(fn (int $id): ?string => $tree->ulidOf($id), $taxonomyIds)));
        if ($ulids !== []) {
            $this->inviteScope = 'only';
            $this->inviteCategories = $ulids;
        }
    }

    private function inviteTree(): InviteCategoryTree
    {
        return $this->inviteTree ??= InviteCategoryTree::load(app(CurrentSite::class)->id(), app(SiteLocale::class)->default());
    }

    /**
     * What the block shows for the roles ticked, and the categories it sends.
     *
     * @param  Collection<int, Role>  $roles
     * @return array{show: bool, high: bool, none: bool, axis: bool, feat: bool, upsell: bool, limited: bool, mode: string, ids: list<int>}
     */
    private function inviteScopeState(User $actor, Collection $roles): array
    {
        $scopes = app(CategoryScopes::class);
        $limited = $scopes->isLimited($actor);
        $level = Cast::int($roles->max('level'));
        $state = ['show' => false, 'high' => false, 'none' => false, 'axis' => false, 'feat' => false, 'upsell' => false, 'limited' => $limited, 'mode' => 'all', 'ids' => []];
        // someone who can neither set limits (users.manage, above the roles) nor is held to their own sees no block
        if (! $limited && ! app(CategoryLimitGuard::class)->mayChangeLimitsOf([['id' => 0, 'name' => '', 'level' => 0]])) {
            return $state;
        }
        $state['show'] = true;
        if ($level >= CategoryScopes::EXEMPT_LEVEL) {
            $state['high'] = true;

            return $state;
        }
        $roles->loadMissing('permissions');
        if (! InviteMemberWithCategories::writesPosts(array_values($roles->all()))) {
            $state['none'] = true;

            return $state;
        }
        $state['axis'] = true;
        $state['feat'] = app(Entitlement::class)->featureEnabled('rbac.category_scopes');
        $state['upsell'] = ! $state['feat'] && app(UpgradeInvitation::class)->isOn();
        // a limited inviter never hands out "every category"; without the feature nobody picks
        $state['mode'] = $limited ? 'only' : ($state['feat'] && $this->inviteScope === 'only' ? 'only' : 'all');
        if ($state['mode'] === 'only' && $state['feat']) {
            $state['ids'] = $this->inviteTree()->normalise(array_values(array_filter($this->inviteCategories, 'is_string')));
        }

        return $state;
    }

    /**
     * The tree rows the block lists: depth, the path above while searching, ticked, locked and why.
     *
     * @param  array{feat: bool, limited: bool}  $state
     * @return list<array{ulid: string, name: string, depth: int, path: string, on: bool, inc: bool, dis: bool, note: string}>
     */
    private function inviteTreeRows(User $actor, array $state): array
    {
        $tree = $this->inviteTree();
        $scopes = app(CategoryScopes::class);
        $picked = array_flip(array_map('strtoupper', array_values(array_filter($this->inviteCategories, 'is_string'))));
        $q = InviteCategoryTree::fold($this->inviteCatQ);
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
            $outside = $state['limited'] && ! $scopes->coversId($actor, $row['id']);
            $rows[] = [
                'ulid' => $row['ulid'],
                'name' => $row['name'],
                'depth' => $q !== '' ? 0 : min(3, $row['depth']),
                'path' => $q !== '' && $row['above'] !== [] ? implode(' › ', array_map(fn (int $id): string => (string) $tree->name($id), $row['above'])).' › ' : '',
                'on' => $inc || isset($picked[$row['ulid']]),
                'inc' => $inc,
                'dis' => $inc || $outside || ! $state['feat'],
                'note' => $inc ? __('admin.members.inv_sc_included') : ($outside ? __('admin.members.inv_sc_outside') : ''),
            ];
        }

        return $rows;
    }
}
