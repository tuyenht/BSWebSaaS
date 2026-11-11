<?php

namespace App\Modules\Admin\Livewire\Users;

use App\Modules\Admin\Notifications\MemberInvitationNotification;
use App\Modules\Admin\Support\FirstHints;
use App\Modules\Admin\Support\Members\MemberDirectory;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Identity\Support\UserPreferences;
use App\Modules\Rbac\Actions\AssignRole;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Actions\RevokeRole;
use App\Modules\Rbac\Exceptions\InvitationRejectedException;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\IssuedInvitation;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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
    /** Where this list's page size lives in the member's preferences. */
    public const string PREFS = 'site.members';

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
        if (in_array($name, ['search', 'groups', 'per'], true)) {
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
        if ($this->refusedWhileSupport()) {
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
        $this->panelRoles = [];
        $this->panelError = '';
    }

    /** Save the panel: the member holds exactly the ticked roles afterwards (at least one). */
    public function saveRoles(): void
    {
        $this->ensureOpen();
        $this->validate([
            'panelRoles' => 'array|max:50',
            'panelRoles.*' => 'string|max:125',
        ]);
        if ($this->refusedWhileSupport()) {
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
        $undo = $this->undoRoles;
        $this->undoRoles = null;
        if ($undo === null || $this->refusedWhileSupport()) {
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
        if ($this->refusedWhileSupport()) {
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
    }

    /** Remove the member asked about: every role they hold on this site goes, or none does. */
    public function confirmRemove(): void
    {
        $this->ensureOpen();
        $ulid = $this->removeFor;
        $this->cancelRemove();
        if ($this->refusedWhileSupport()) {
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
            $this->say(app(SupportSession::class)->active() ? __('admin.support.locked') : __('admin.member_remove_denied'), 'error');

            return;
        }

        $this->undoRoles = null;
        $this->say(__('admin.members.removed', ['name' => $target->name]));
    }

    public function openInvite(): void
    {
        $this->ensureOpen();
        if (! Gate::allows('users.invite') || $this->refusedWhileSupport()) {
            return;
        }
        $this->inviteResults = [];
        $this->inviteTried = false;
        $this->resetErrorBag();
        $this->inviteOpen = true;
    }

    public function closeInvite(): void
    {
        $this->inviteOpen = false;
        if ($this->inviteResults !== []) {
            $this->reset('inviteEmail', 'inviteRoles', 'inviteMessage', 'inviteResults', 'inviteTried');
        }
    }

    /** "Mời thêm" after the results: a clean form, the dialog stays open. */
    public function inviteAgain(): void
    {
        $this->ensureOpen();
        $this->reset('inviteEmail', 'inviteRoles', 'inviteMessage', 'inviteResults', 'inviteTried');
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
        ], [], ['inviteEmail' => __('admin.members.inv_email'), 'inviteMessage' => __('admin.members.inv_msg')]);

        $actor = $this->actor();
        if (! Gate::allows('users.invite') || $this->refusedWhileSupport()) {
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
        $roles = $this->siteRoles($this->inviteRoles);
        if ($roles->isEmpty()) {
            $this->addError('inviteRoles', __('admin.members.inv_role_need'));

            return;
        }

        $message = trim($this->inviteMessage) === '' ? null : trim($this->inviteMessage);
        $results = [];
        foreach ($valid as $email) {
            try {
                $issued = app(InviteMember::class)->handle($actor, $email, array_values($roles->all()), $message);
                $this->mailInvitation($issued, $actor);
                $results[] = ['email' => $issued->invitation->email, 'ok' => true, 'text' => __('admin.members.inv_res_ok', ['days' => InviteMember::VALID_DAYS])];
            } catch (AuthorizationException) {
                $results[] = ['email' => $email, 'ok' => false, 'text' => __('admin.role_change_denied')];
            } catch (InvitationRejectedException $e) {
                $results[] = ['email' => $email, 'ok' => false, 'text' => __('rbac.invitation.error_'.$e->reason)];
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
        if (! Gate::allows('users.manage')) {
            return $this->page('admin::livewire.users.noperm', $title, ['role' => RoleLabel::topOf($actor)]);
        }

        $this->clean();
        $directory = app(MemberDirectory::class);
        $guard = app(RoleHierarchyGuard::class);
        $siteId = app(CurrentSite::class)->id();

        $failed = false;
        $rows = new Collection;
        $older = $newer = null;
        $counts = ['total' => 0, 'roles' => []];
        $matched = 0;
        $scopes = [];
        try {
            $page = $directory->page($this->search, $this->groups, $this->per, $this->after, $this->before);
            $rows = $page['rows'];
            $older = $page['older'];
            $newer = $page['newer'];
            $counts = $directory->counts();
            $narrowed = MemberDirectory::searches($this->search) || $this->groups !== [];
            $matched = $narrowed ? $directory->matchCount($this->search, $this->groups) : $counts['total'];
            $scopes = $this->scopeNames($rows, $siteId);
        } catch (QueryException $e) {
            report($e);
            $failed = true;
        }

        $siteRoles = Role::query()->where('site_id', $siteId)->orderByDesc('level')->orderBy('name')->get(['id', 'name', 'level', 'is_system']);
        $assignable = $guard->assignableRoles($actor)->loadMissing('permissions')->sortByDesc('level')->values();
        $ceiling = $guard->levelCeiling($actor);

        $panelMember = $this->panelFor !== '' ? $this->siteMember($this->panelFor) : null;
        if ($this->panelFor !== '' && ! $panelMember instanceof User) {
            $this->closePanel();
        }
        $panelBefore = $panelMember instanceof User ? $panelMember->roles()->with('permissions')->get() : new Collection;
        $removeMember = $this->confirmOpen && $this->removeFor !== '' ? $this->siteMember($this->removeFor) : null;

        $inviteSent = 0;
        if ($this->inviteOpen) {
            $inviteSent = app(InviteMember::class)->sentToday($siteId);
        }

        return $this->page('admin::livewire.users.index', $title, [
            'failed' => $failed,
            'rows' => $rows,
            'older' => $older,
            'newer' => $newer,
            'total' => $counts['total'],
            'roleCounts' => $counts['roles'],
            'matched' => $matched,
            'searching' => MemberDirectory::searches($this->search),
            'scopes' => $scopes,
            'siteRoles' => $siteRoles,
            'assignable' => $assignable,
            'ceiling' => $ceiling,
            'me' => $actor,
            'support' => app(SupportSession::class)->active(),
            'canInvite' => Gate::allows('users.invite'),
            'youHint' => ! FirstHints::dismissed(FirstHints::MEMBERS_YOU, $actor),
            'panelMember' => $panelMember,
            'panelBefore' => $panelBefore,
            'removeMember' => $removeMember,
            'inviteLeft' => max(0, InviteMember::DAILY_LIMIT - $inviteSent),
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

        return [array_slice(array_keys($valid), 0, 50), $bad];
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
            DB::transaction(function () use ($actor, $target, $roles): void {
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
            });
        } catch (AuthorizationException) {
            if (app(SupportSession::class)->active()) {
                $this->say(__('admin.support.locked'), 'error');
            }

            return false;
        }

        return true;
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
        $roots = app(CategoryScopes::class)->rootIdsOf($ids);
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

    /** Support staff signed in as a member change nobody's membership: say so and stop. */
    private function refusedWhileSupport(): bool
    {
        if (app(SupportSession::class)->active()) {
            $this->say(__('admin.support.locked'), 'error');

            return true;
        }

        return false;
    }

    private function open(): bool
    {
        return Gate::allows('users.manage');
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
     */
    private function mailInvitation(IssuedInvitation $issued, User $actor): void
    {
        $invitation = $issued->invitation;
        $siteName = Site::query()->whereKey(app(CurrentSite::class)->id())->value('name');
        $expiresAt = app(SiteTime::class)->forDisplay($invitation->expires_at);

        $notification = (new MemberInvitationNotification(
            acceptUrl: route('admin.invitations.accept', ['token' => $issued->token]),
            siteName: is_string($siteName) && $siteName !== '' ? $siteName : request()->getHost(),
            inviterName: $actor->name,
            roles: array_values($invitation->roles()->get()->map(fn (Role $role): array => ['name' => $role->name, 'system' => $role->is_system])->all()),
            personalMessage: $invitation->message,
            expiresOn: $expiresAt?->format('d/m/Y H:i') ?? '',
        ))->forCurrentSite();

        Notification::route('mail', $invitation->email)
            ->notify($notification->locale(app(SiteLocale::class)->default()));
    }
}
