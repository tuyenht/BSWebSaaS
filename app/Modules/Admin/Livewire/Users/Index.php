<?php

namespace App\Modules\Admin\Livewire\Users;

use App\Modules\Admin\Livewire\Users\Concerns\LimitsInvitationCategories;
use App\Modules\Admin\Livewire\Users\Concerns\ManagesInvitations;
use App\Modules\Admin\Livewire\Users\Concerns\SelectsMembers;
use App\Modules\Admin\Notifications\MemberInvitationNotification;
use App\Modules\Admin\Support\FirstHints;
use App\Modules\Admin\Support\Members\InvitationDirectory;
use App\Modules\Admin\Support\Members\InviteCategoryTree;
use App\Modules\Admin\Support\Members\MemberDirectory;
use App\Modules\Cms\Actions\InviteMemberWithCategories;
use App\Modules\Cms\Exceptions\CategoryScopeRejectedException;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Identity\Support\UserPreferences;
use App\Modules\Rbac\Actions\AssignRole;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Actions\RevokeRole;
use App\Modules\Rbac\Exceptions\InvitationRejectedException;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\IssuedInvitation;
use App\Modules\Rbac\Support\MembershipLock;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The site's members and the roles each one holds (design 22 "Thành viên"): a search that ignores
 * accents, a role filter, the list read page by page from a (name, id) cursor, a panel that sets a
 * member's roles, removal behind a confirmation that says what it means, and the invitation dialog.
 *
 * Who may do what is never decided here: every grant and revoke goes through AssignRole / RevokeRole
 * (strictly below the actor's own level, within the permissions they hold), every invitation through
 * InviteMember, and all of them refuse while support staff are signed in as a member. The screen only
 * draws those rules ahead of time: a member at or above your level, and your own row, show why they
 * cannot be changed instead of buttons; the role choices list only roles you may grant.
 *
 * A member without `users.manage` sees the no-permission card; nothing below is read for them, and
 * every public method checks it again (the livewire/update endpoint bypasses route middleware).
 *
 * `users` is global identity (D6, no RLS): a member is always addressed by ulid and resolved among
 * this site's members only (siteMember()), never by a bigint from the browser.
 */
#[Layout('admin::layouts.app')]
final class Index extends Component
{
    use LimitsInvitationCategories;
    use ManagesInvitations;
    use SelectsMembers;

    /** Where this list's page size lives in the member's preferences. */
    public const string PREFS = 'site.members';

    /** Addresses one send may hold: more is refused with a message, never cut short in silence. */
    public const int INVITE_MAX = 50;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @var list<string> role names */
    #[Url(as: 'nhom', except: [])]
    public array $groups = [];

    #[Url(except: '')]
    public string $after = '';

    #[Url(except: '')]
    public string $before = '';

    public int $per = 20;

    /** The member whose roles the panel is changing (ulid), '' while it is closed. */
    public string $panelFor = '';

    /** @var list<string> the roles ticked in the panel */
    public array $panelRoles = [];

    public string $panelError = '';

    public bool $confirmOpen = false;

    /** The member the removal question is about (ulid). */
    public string $removeFor = '';

    public bool $inviteOpen = false;

    /** One address or several, separated by commas, semicolons, spaces or new lines. */
    public string $inviteEmail = '';

    /** @var list<string> */
    public array $inviteRoles = [];

    public string $inviteMessage = '';

    public bool $inviteTried = false;

    /** @var list<array{email: string, ok: bool, text: string}> what happened to each address */
    public array $inviteResults = [];

    public ?string $toast = null;

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    /** @var array<string, mixed>|null the roles a member had before the last change ({ulid, roles}); it comes back from the browser, so it is read with care */
    public ?array $undoRoles = null;

    public function mount(): void
    {
        if (! $this->open()) {
            return;
        }
        $view = Cast::arr(Cast::arr(Cast::arr($this->actor()->preferences)['lists'] ?? null)[self::PREFS] ?? null);
        $this->per = in_array($view['per'] ?? null, MemberDirectory::PER_PAGE, true) ? Cast::int($view['per']) : 20;
        $this->clean();
    }

    public function updated(string $property): void
    {
        $this->ensureOpen();
        $name = Str::before($property, '.');
        $this->clean();
        if (in_array($name, ['search', 'groups', 'per', 'tab', 'state'], true)) {
            $this->after = $this->before = '';
        }
        if ($name === 'per') {
            UserPreferences::set($this->actor(), ['lists', self::PREFS, 'per'], $this->per);
        }
        if ($name === 'panelRoles') {
            $this->panelError = '';
        }
    }

    public function older(string $ulid): void
    {
        $this->ensureOpen();
        $this->after = self::ulidOr($ulid);
        $this->before = '';
    }

    public function newer(string $ulid): void
    {
        $this->ensureOpen();
        $this->before = self::ulidOr($ulid);
        $this->after = '';
    }

    /** Bỏ lọc: the search and the roles go; the page size stays. */
    public function clearFilters(): void
    {
        $this->ensureOpen();
        $this->search = '';
        $this->groups = [];
        $this->after = $this->before = '';
    }

    public function clearSearch(): void
    {
        $this->ensureOpen();
        $this->search = '';
        $this->after = $this->before = '';
    }

    public function removeGroup(string $name): void
    {
        $this->ensureOpen();
        $this->groups = array_values(array_diff($this->groups, [$name]));
        $this->after = $this->before = '';
    }

    public function clearGroups(): void
    {
        $this->ensureOpen();
        $this->groups = [];
        $this->after = $this->before = '';
    }

    /** Open the roles panel for one member you may change. */
    public function openPanel(string $memberUlid): void
    {
        $this->ensureOpen();
        if ($this->refusedLocked()) {
            return;
        }
        $target = $this->siteMember($memberUlid);
        if (! $target instanceof User) {
            $this->say(__('admin.member_not_found'), 'error');

            return;
        }
        if (! $this->touchable($target)) {
            $this->say(__('admin.members.cannot_touch'), 'error');

            return;
        }
        $this->panelFor = $target->ulid;
        $this->panelRoles = Cast::strings($target->roles()->pluck('name')->all());
        $this->panelError = '';
    }

    public function closePanel(): void
    {
        $this->panelFor = '';
        $this->panelBulk = false;
        $this->panelRoles = [];
        $this->panelError = '';
    }

    /** Save the panel: the member holds exactly the ticked roles afterwards (at least one). */
    public function saveRoles(): void
    {
        $this->ensureOpen();
        $this->validate([
            'panelRoles' => 'array|max:50',
            'panelRoles.*' => 'string|max:125|distinct',
        ]);
        if ($this->refusedLocked()) {
            return;
        }
        if ($this->panelBulk) {
            $roles = $this->siteRoles($this->panelRoles);
            if ($roles->isEmpty()) {
                $this->panelError = __('admin.members.panel_need_one');

                return;
            }
            $this->saveBulkRoles($roles);

            return;
        }
        $target = $this->siteMember($this->panelFor);
        if (! $target instanceof User) {
            $this->closePanel();
            $this->say(__('admin.member_not_found'), 'error');

            return;
        }
        if (! $this->touchable($target)) {
            $this->closePanel();
            $this->say(__('admin.members.cannot_touch'), 'error');

            return;
        }
        $roles = $this->siteRoles($this->panelRoles);
        if ($roles->isEmpty()) {
            $this->panelError = __('admin.members.panel_need_one');

            return;
        }

        $before = Cast::strings($target->roles()->pluck('name')->all());
        if (! $this->applyRoles($target, $roles)) {
            $this->panelError = __('admin.role_change_denied');

            return;
        }

        $this->closePanel();
        $this->say(__('admin.members.saved_roles', ['name' => $target->name]), 'success', ['ulid' => $target->ulid, 'roles' => $before]);
    }

    /** "Hoàn tác" on the toast: give the member back the roles they had before the last change. */
    public function undo(): void
    {
        $this->ensureOpen();
        // the earlier roles come back from the browser: the same limits as the panel's list
        try {
            $this->validate([
                'undoRoles' => 'nullable|array',
                'undoRoles.ulid' => 'string|max:26',
                'undoRoles.roles' => 'array|max:50',
                'undoRoles.roles.*' => 'string|max:125|distinct',
            ]);
        } catch (ValidationException) {
            $this->say(__('admin.members.undo_failed'), 'error');

            return;
        }
        $undo = $this->undoRoles;
        $this->undoRoles = null;
        if ($undo === null || $this->refusedLocked()) {
            return;
        }
        $target = $this->siteMember(Cast::string($undo['ulid'] ?? ''));
        $roles = $this->siteRoles(Cast::strings(Cast::arr($undo['roles'] ?? [])));
        if (! $target instanceof User || ! $this->touchable($target) || $roles->isEmpty() || ! $this->applyRoles($target, $roles)) {
            $this->say(__('admin.members.undo_failed'), 'error');

            return;
        }
        $this->say(__('admin.members.undone', ['name' => $target->name]));
    }

    /** Ask before removing a member (from the row's ⋮). */
    public function askRemove(string $memberUlid): void
    {
        $this->ensureOpen();
        if ($this->refusedLocked()) {
            return;
        }
        $target = $this->siteMember($memberUlid);
        if (! $target instanceof User) {
            $this->say(__('admin.member_not_found'), 'error');

            return;
        }
        if (! $this->touchable($target)) {
            $this->say(__('admin.members.cannot_touch'), 'error');

            return;
        }
        $this->removeFor = $target->ulid;
        $this->confirmOpen = true;
    }

    public function cancelRemove(): void
    {
        $this->confirmOpen = false;
        $this->removeFor = '';
        $this->removeBulk = false;
    }

    /** Remove the member asked about: every role they hold on this site goes, or none does. */
    public function confirmRemove(): void
    {
        $this->ensureOpen();
        $ulid = $this->removeFor;
        $bulk = $this->removeBulk;
        $this->cancelRemove();
        if ($this->refusedLocked()) {
            return;
        }
        if ($bulk) {
            $this->removePicked();

            return;
        }
        $actor = $this->actor();
        $target = $this->siteMember($ulid);
        if (! $target instanceof User) {
            $this->say(__('admin.member_not_found'), 'error');

            return;
        }
        if (! $this->touchable($target)) {
            $this->say(__('admin.members.cannot_touch'), 'error');

            return;
        }

        try {
            // a role above the actor (which cannot be stripped) rolls the whole removal back
            DB::transaction(function () use ($actor, $target): void {
                foreach ($target->roles()->get() as $role) {
                    if ($role instanceof Role) {
                        app(RevokeRole::class)->handle($actor, $target, $role);
                    }
                }
            });
        } catch (AuthorizationException) {
            $this->say($this->lockReason() ?? __('admin.member_remove_denied'), 'error');

            return;
        }

        $this->undoRoles = null;
        $this->say(__('admin.members.removed', ['name' => $target->name]));
    }

    public function openInvite(): void
    {
        $this->ensureOpen();
        if (! $this->mayInvite() || $this->refusedLocked()) {
            return;
        }
        if ($this->siteFull()) {
            $this->say(__('admin.members.inv_full', ['max' => InviteMember::DAILY_LIMIT]), 'error');

            return;
        }
        $this->inviteResults = [];
        $this->inviteTried = false;
        $this->resetErrorBag();
        if ($this->inviteCategories === [] && $this->inviteScope === 'all') {
            $this->resetInviteScope();
        }
        $this->inviteOpen = true;
    }

    public function closeInvite(): void
    {
        $this->inviteOpen = false;
        if ($this->inviteResults !== []) {
            $this->reset('inviteEmail', 'inviteRoles', 'inviteMessage', 'inviteResults', 'inviteTried');
            $this->resetInviteScope();
        }
    }

    /** "Mời thêm" after the results: a clean form, the dialog stays open. */
    public function inviteAgain(): void
    {
        $this->ensureOpen();
        $this->reset('inviteEmail', 'inviteRoles', 'inviteMessage', 'inviteResults', 'inviteTried');
        $this->resetInviteScope();
        $this->resetErrorBag();
    }

    /**
     * Send one invitation per address. The answer for an address is the same whether or not it
     * holds an account anywhere on the platform (no probing the platform's users); the mail goes
     * to the address, and its holder signs in or creates the account from the link.
     */
    public function sendInvites(): void
    {
        $this->ensureOpen();
        $this->inviteTried = true;
        $this->validate([
            'inviteEmail' => 'required|string|max:5000',
            'inviteRoles' => 'array|max:50',
            'inviteRoles.*' => 'string|max:125',
            'inviteMessage' => 'nullable|string|max:500',
            'inviteScope' => 'in:all,only',
            'inviteCategories' => 'array|max:'.InviteCategoryTree::LIMIT,
            'inviteCategories.*' => 'string|max:26',
            'inviteCatQ' => 'string|max:100',
        ], [], ['inviteEmail' => __('admin.members.inv_email'), 'inviteMessage' => __('admin.members.inv_msg')]);

        $actor = $this->actor();
        if (! $this->mayInvite() || $this->refusedLocked()) {
            return;
        }

        [$valid, $bad] = self::addresses($this->inviteEmail);
        if ($bad !== []) {
            $this->addError('inviteEmail', __('admin.members.inv_email_bad', ['email' => $bad[0]]));

            return;
        }
        if ($valid === []) {
            $this->addError('inviteEmail', __('admin.members.inv_email_need'));

            return;
        }
        if (count($valid) > self::INVITE_MAX) {
            $this->addError('inviteEmail', __('admin.members.inv_too_many', ['n' => count($valid), 'max' => self::INVITE_MAX]));

            return;
        }
        $roles = $this->siteRoles($this->inviteRoles);
        if ($roles->isEmpty()) {
            $this->addError('inviteRoles', __('admin.members.inv_role_need'));

            return;
        }
        // "Phạm vi làm việc": "only" with nothing picked is a choice not made yet, said on the block
        $scope = $this->inviteScopeState($actor, $roles);
        if ($scope['axis'] && $scope['feat'] && $scope['mode'] === 'only' && $scope['ids'] === []) {
            $this->inviteScopeError = __('admin.members.inv_sc_err');

            return;
        }
        // what the site may still mail today, said up front: the action would refuse the addresses past it one by one
        $siteId = app(CurrentSite::class)->id();
        $left = max(0, InviteMember::DAILY_LIMIT - app(InviteMember::class)->sentToday($siteId));
        if (count($valid) > $left) {
            $this->addError('inviteEmail', $left === 0
                ? __('admin.members.inv_quota_none', ['max' => InviteMember::DAILY_LIMIT])
                : __('admin.members.inv_over_quota', ['n' => count($valid), 'left' => $left, 'max' => InviteMember::DAILY_LIMIT]));

            return;
        }

        $message = trim($this->inviteMessage) === '' ? null : trim($this->inviteMessage);
        // read once for every address: the mail names the site and the roles the invitation carries ($roles, synced as is)
        $siteName = $this->siteName($siteId);
        $roleList = $this->mailRoles($roles->all());
        $results = [];
        foreach ($valid as $email) {
            try {
                $issued = app(InviteMemberWithCategories::class)->handle($actor, $email, array_values($roles->all()), $message, $scope['ids']);
                $this->mailInvitation($issued, $actor, $siteName, $roleList);
                $results[] = ['email' => $issued->invitation->email, 'ok' => true, 'text' => __('admin.members.inv_res_ok', ['days' => InviteMember::VALID_DAYS])];
            } catch (AuthorizationException) {
                $results[] = ['email' => $email, 'ok' => false, 'text' => __('admin.role_change_denied')];
            } catch (InvitationRejectedException $e) {
                $results[] = ['email' => $email, 'ok' => false, 'text' => __('rbac.invitation.error_'.$e->reason)];
            } catch (CategoryScopeRejectedException $e) {
                $results[] = ['email' => $email, 'ok' => false, 'text' => __('admin.members.inv_sc_err_'.$e->reason)];
            }
        }
        $this->inviteResults = $results;
    }

    public function dismissToast(int $seq = 0): void
    {
        if ($seq === 0 || $seq === $this->toastSeq) {
            $this->toast = null;
            $this->undoRoles = null;
        }
    }

    public function render(): View
    {
        $title = __('admin.members.title');
        $actor = $this->actor();
        if (! $this->open()) {
            return $this->page('admin::livewire.users.noperm', $title, ['role' => RoleLabel::topOf($actor)]);
        }

        $this->clean();
        $directory = app(MemberDirectory::class);
        $invitations = app(InvitationDirectory::class);
        $guard = app(RoleHierarchyGuard::class);
        $siteId = app(CurrentSite::class)->id();
        $onInvites = $this->tab === 'loi-moi';
        $canInvite = $this->mayInvite();
        $support = app(SupportSession::class)->active();
        $lockedWhy = $this->lockReason();

        $failed = false;
        $rows = new Collection;
        $invRows = new Collection;
        $older = $newer = null;
        $counts = ['total' => 0, 'roles' => []];
        $invCounts = array_fill_keys(InvitationDirectory::STATES, 0);
        $invPending = 0;
        $invRoleCounts = [];
        $matched = 0;
        $scopes = [];
        $stayed = [];
        $invScopes = [];
        try {
            // a savepoint inside the request's tenancy transaction: a failed read leaves it usable for the rest of the page
            $read = DB::transaction(function () use ($directory, $invitations, $siteId, $onInvites): array {
                $counts = $directory->counts();
                $narrowed = MemberDirectory::searches($this->search) || $this->groups !== [];
                if ($onInvites) {
                    // the per-state counts (the state row) are read only here; the tab label reuses the waiting one
                    $invCounts = $invitations->counts();
                    $page = $invitations->page($this->state, $this->search, $this->groups, $this->per, $this->after, $this->before);
                    $inviters = array_values(array_unique(array_filter($page['rows']->pluck('invited_by')->all(), 'is_int')));

                    return [
                        'page' => $page,
                        'counts' => $counts,
                        'invCounts' => $invCounts,
                        'invPending' => min($invCounts['pending'], InvitationDirectory::TAB_CAP + 1),
                        'invRoleCounts' => $invitations->roleCounts($this->state),
                        'matched' => $narrowed ? $invitations->matchCount($this->state, $this->search, $this->groups) : $invCounts[$this->state],
                        'stayed' => $invitations->stillMembers($inviters),
                        'invScopes' => $this->invitationScopeNames($page['rows'], $siteId),
                    ];
                }
                $page = $directory->page($this->search, $this->groups, $this->per, $this->after, $this->before);

                return [
                    'page' => $page,
                    'counts' => $counts,
                    'invPending' => $invitations->pendingUpTo(),
                    'matched' => $narrowed ? $directory->matchCount($this->search, $this->groups) : $counts['total'],
                    'scopes' => $this->scopeNames($page['rows'], $siteId),
                ];
            });
            if ($onInvites) {
                $invRows = $read['page']['rows'];
            } else {
                $rows = $read['page']['rows'];
            }
            $older = $read['page']['older'];
            $newer = $read['page']['newer'];
            $counts = $read['counts'];
            $invCounts = $read['invCounts'] ?? $invCounts;
            $invPending = $read['invPending'];
            $invRoleCounts = $read['invRoleCounts'] ?? [];
            $matched = $read['matched'];
            $scopes = $read['scopes'] ?? [];
            $stayed = $read['stayed'] ?? [];
            $invScopes = $read['invScopes'] ?? [];
        } catch (QueryException $e) {
            report($e);
            $failed = true;
        }

        $siteRoles = Role::query()->where('site_id', $siteId)->orderByDesc('level')->orderBy('name')->get(['id', 'name', 'level', 'is_system']);
        $assignable = $guard->assignableRoles($actor)->loadMissing('permissions')->sortByDesc('level')->values();
        $ceiling = $guard->levelCeiling($actor);

        // the site's invitation mail over the last 24 hours: the quota line (invitations), the near-limit note (an inviter)
        $sent = ($onInvites || ($canInvite && $lockedWhy === null)) ? app(InviteMember::class)->sentToday($siteId) : 0;
        $reopens = $sent >= InviteMember::DAILY_LIMIT ? app(InviteMember::class)->reopensAt($siteId, $sent) : null;

        $picked = ! $onInvites && $this->picked !== [] ? $this->pickedMembers() : new Collection;
        $pickedOk = $picked->isEmpty() ? $picked : $this->pickedTouchable($picked);

        $panelMember = $this->panelFor !== '' ? $this->siteMember($this->panelFor) : null;
        if ($this->panelFor !== '' && ! $panelMember instanceof User) {
            $this->closePanel();
        }
        if ($this->panelBulk && $pickedOk->isEmpty()) {
            $this->closePanel();
        }
        $panelBefore = $panelMember instanceof User ? $panelMember->roles()->with('permissions')->get() : new Collection;
        $removeMember = $this->confirmOpen && $this->removeFor !== '' ? $this->siteMember($this->removeFor) : null;
        $revoking = $this->revokeOpen && $this->revokeFor !== '' ? $this->siteInvitation($this->revokeFor) : null;
        // "Phạm vi làm việc" of the invite dialog, read only while its form is open
        $invScope = null;
        $invTree = [];
        if ($canInvite && $this->inviteOpen && $this->inviteResults === []) {
            $invScope = $this->inviteScopeState($actor, $this->siteRoles($this->inviteRoles));
            if ($invScope['axis'] && $invScope['feat'] && $invScope['mode'] === 'only') {
                $invTree = $this->inviteTreeRows($actor, $invScope);
            }
        }

        return $this->page('admin::livewire.users.index', $title, [
            'failed' => $failed,
            'onInvites' => $onInvites,
            'rows' => $rows,
            'invRows' => $invRows,
            'older' => $older,
            'newer' => $newer,
            'total' => $counts['total'],
            'roleCounts' => $onInvites ? $invRoleCounts : $counts['roles'],
            'invCounts' => $invCounts,
            'invPending' => $invPending,
            'invCap' => InvitationDirectory::TAB_CAP,
            'matched' => $matched,
            'searching' => MemberDirectory::searches($this->search),
            'scopes' => $scopes,
            'invScopes' => $invScopes,
            'stayed' => $stayed,
            'siteRoles' => $siteRoles,
            'assignable' => $assignable,
            'ceiling' => $ceiling,
            'me' => $actor,
            'support' => $support,
            'lockedWhy' => $lockedWhy,
            'canInvite' => $canInvite,
            'youHint' => ! FirstHints::dismissed(FirstHints::MEMBERS_YOU, $actor),
            'pickedRows' => $picked,
            'pickedOk' => $pickedOk,
            'panelMember' => $panelMember,
            'panelBefore' => $panelBefore,
            'removeMember' => $removeMember,
            'revoking' => $revoking,
            'sent' => $sent,
            'reopens' => $reopens,
            'inviteLeft' => max(0, InviteMember::DAILY_LIMIT - $sent),
            'invScope' => $invScope,
            'invTree' => $invTree,
        ]);
    }

    /**
     * Split what was typed into addresses: valid ones (lower-cased, each once) and the ones that
     * are not an email.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function addresses(string $text): array
    {
        $parts = preg_split('/[\s,;]+/u', trim($text)) ?: [];
        $valid = [];
        $bad = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (filter_var($part, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($part) <= 254) {
                $valid[mb_strtolower($part)] = true;
            } else {
                $bad[] = $part;
            }
        }

        return [array_keys($valid), $bad];
    }

    /**
     * Give $target exactly $roles: grant the new ones first (they never drop out of the site midway),
     * then revoke the others, all in one transaction — a refused step rolls everything back.
     *
     * @param  Collection<int, Role>  $roles
     */
    private function applyRoles(User $target, Collection $roles): bool
    {
        $actor = $this->actor();
        try {
            DB::transaction(fn () => $this->giveRoles($target, $roles));
        } catch (AuthorizationException) {
            $locked = $this->lockReason();
            if ($locked !== null) {
                $this->say($locked, 'error');
            }

            return false;
        }

        return true;
    }

    /**
     * Grant $target the roles of $roles they lack, then revoke the others (the caller holds the transaction).
     *
     * @param  Collection<int, Role>  $roles
     *
     * @throws AuthorizationException a step the actor may not take
     */
    private function giveRoles(User $target, Collection $roles): void
    {
        $actor = $this->actor();
        $held = $target->roles()->get();
        $wanted = $roles->pluck('id')->all();
        foreach ($roles as $role) {
            if (! $held->contains('id', $role->id)) {
                app(AssignRole::class)->handle($actor, $target, $role);
            }
        }
        foreach ($held as $existing) {
            if ($existing instanceof Role && ! in_array($existing->id, $wanted, true)) {
                app(RevokeRole::class)->handle($actor, $target, $existing);
            }
        }
    }

    /** The site's name for the invitation mail (its host when it has none). */
    private function siteName(int $siteId): string
    {
        $name = Site::query()->whereKey($siteId)->value('name');

        return is_string($name) && $name !== '' ? $name : request()->getHost();
    }

    /** The site has mailed DAILY_LIMIT invitations in the last 24 hours: inviting and re-sending wait. */
    private function siteFull(): bool
    {
        return app(InviteMember::class)->sentToday(app(CurrentSite::class)->id()) >= InviteMember::DAILY_LIMIT;
    }

    /** Whether the actor may change $target at all: not themselves, and every role strictly below their own level. */
    private function touchable(User $target): bool
    {
        $actor = $this->actor();
        if ($target->id === $actor->id) {
            return false;
        }
        $max = $target->roles()->max('level');

        return (is_numeric($max) ? (int) $max : 0) < app(RoleHierarchyGuard::class)->levelCeiling($actor);
    }

    /**
     * The post categories each limited member on the page may write in, by user id (names in the
     * site's default language). Owners and admins are never limited, whatever rows exist.
     *
     * @param  Collection<int, User>  $rows
     * @return array<int, list<string>>
     */
    private function scopeNames(Collection $rows, int $siteId): array
    {
        $ids = array_values($rows->filter(fn (User $u): bool => Cast::int($u->roles->max('level')) < CategoryScopes::EXEMPT_LEVEL)->map(fn (User $u): int => (int) $u->id)->all());

        return $this->namedRoots(app(CategoryScopes::class)->rootIdsOf($ids), $siteId);
    }

    /**
     * The post categories each invitation on the page limits its new member to, by invitation id,
     * by name (in the site's default language).
     *
     * @param  BaseCollection<int, Invitation>  $rows
     * @return array<int, list<string>>
     */
    private function invitationScopeNames(BaseCollection $rows, int $siteId): array
    {
        $ids = array_values(array_map(static fn (mixed $id): int => Cast::int($id), $rows->pluck('id')->all()));

        return $this->namedRoots(app(CategoryScopes::class)->invitationRootIdsOf($ids), $siteId);
    }

    /**
     * Category ids by owner, as names by owner (sorted), read in one query.
     *
     * @param  array<int, list<int>>  $roots
     * @return array<int, list<string>>
     */
    private function namedRoots(array $roots, int $siteId): array
    {
        if ($roots === []) {
            return [];
        }
        $label = DB::table('taxonomy_translations')
            ->where('site_id', $siteId)
            ->where('locale', app(SiteLocale::class)->default())
            ->whereIn('taxonomy_id', array_values(array_unique(array_merge(...array_values($roots)))))
            ->limit(1000)
            ->pluck('name', 'taxonomy_id');
        $names = [];
        foreach ($roots as $userId => $taxonomyIds) {
            foreach ($taxonomyIds as $taxonomyId) {
                if (isset($label[$taxonomyId])) {
                    $names[$userId][] = Cast::string($label[$taxonomyId]);
                }
            }
            if (isset($names[$userId])) {
                sort($names[$userId]);
            }
        }

        return $names;
    }

    /** A member of the CURRENT site (holds a role in its team), addressed by public ulid. */
    private function siteMember(string $ulid): ?User
    {
        if (! Str::isUlid($ulid)) {
            return null;
        }

        return User::query()
            ->where('ulid', $ulid)
            ->whereExists(fn (Builder $membership): Builder => $membership->selectRaw('1')
                ->from('model_has_roles')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->where('model_has_roles.model_type', User::class)
                ->where('model_has_roles.site_id', app(CurrentSite::class)->id()))
            ->first();
    }

    /**
     * This site's roles among $names (unknown names are dropped).
     *
     * @param  list<string>  $names
     * @return Collection<int, Role>
     */
    private function siteRoles(array $names): Collection
    {
        $names = array_values(array_unique(array_filter($names, fn (string $n): bool => $n !== '')));
        if ($names === []) {
            return new Collection;
        }

        return Role::query()
            ->where('site_id', app(CurrentSite::class)->id())
            ->whereIn('name', $names)
            ->get();
    }

    /**
     * A short message at the bottom of the screen; with $undo the member's earlier roles, so
     * "Hoàn tác" can give them back.
     *
     * @param  array{ulid: string, roles: list<string>}|null  $undo
     */
    private function say(string $message, string $tone = 'success', ?array $undo = null): void
    {
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->undoRoles = $undo;
        $this->toastSeq++;
    }

    /**
     * Support staff signed in as a member, and platform staff with no role on this website, change
     * nobody's membership (MembershipLock — the actions refuse too): say so and stop.
     */
    private function refusedLocked(): bool
    {
        $locked = $this->lockReason();
        if ($locked !== null) {
            $this->say($locked, 'error');

            return true;
        }

        return false;
    }

    /** Why members cannot be changed here by this person, in the screen's words; null when they can. */
    private function lockReason(): ?string
    {
        return app(MembershipLock::class)->reason($this->actor(), app(CurrentSite::class)->id());
    }

    /** May this person manage the website's members? Their real permission here (MembershipLock::permits — never the platform bypass for staff who hold a role). */
    private function open(): bool
    {
        return app(MembershipLock::class)->permits($this->actor(), app(CurrentSite::class)->id(), 'users.manage');
    }

    /** May this person send invitations here? As open(), for `users.invite`. */
    private function mayInvite(): bool
    {
        return app(MembershipLock::class)->permits($this->actor(), app(CurrentSite::class)->id(), 'users.invite');
    }

    /** Every public method: the member may manage members. */
    private function ensureOpen(): void
    {
        abort_unless($this->open(), 403);
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** Public properties come from the browser: keep only values the screen knows. */
    private function clean(): void
    {
        $this->search = mb_substr($this->search, 0, MemberDirectory::SEARCH_MAX);
        $this->groups = array_slice(array_values(array_unique(array_filter(Cast::strings($this->groups), fn (string $v): bool => $v !== '' && strlen($v) <= 125))), 0, 50);
        $this->per = in_array($this->per, MemberDirectory::PER_PAGE, true) ? $this->per : 20;
        $this->after = self::ulidOr($this->after);
        $this->before = self::ulidOr($this->before);
        $this->tab = $this->tab === 'loi-moi' ? 'loi-moi' : '';
        $this->state = in_array($this->state, InvitationDirectory::STATES, true) ? $this->state : 'pending';
    }

    private static function ulidOr(string $value): string
    {
        return Str::isUlid($value) ? strtoupper($value) : '';
    }

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
    private function page(string $view, string $title, array $data): View
    {
        $page = view($view, $data);
        $page->title($title);

        return $page;
    }

    /**
     * Queue the invitation mail. Everything is composed here, in the request: the worker has no
     * host to build the link from and no site context to read names in. Same mail for every
     * address — the invitation page decides whether to sign in or create the account.
     *
     * @param  list<array{name: string, system: bool}>  $roles
     */
    private function mailInvitation(IssuedInvitation $issued, User $actor, string $siteName, array $roles): void
    {
        $invitation = $issued->invitation;
        $expiresAt = app(SiteTime::class)->forDisplay($invitation->expires_at);

        $notification = (new MemberInvitationNotification(
            acceptUrl: route('admin.invitations.accept', ['token' => $issued->token]),
            siteName: $siteName,
            inviterName: $actor->name,
            roles: $roles,
            personalMessage: $invitation->message,
            expiresOn: $expiresAt?->format('d/m/Y H:i') ?? '',
        ))->forCurrentSite();

        Notification::route('mail', $invitation->email)
            ->notify($notification->locale(app(SiteLocale::class)->default()));
    }
}
