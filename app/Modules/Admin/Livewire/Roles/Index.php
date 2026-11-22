<?php

namespace App\Modules\Admin\Livewire\Roles;

use App\Modules\Admin\Support\Members\InvitationDirectory;
use App\Modules\Admin\Support\Members\MemberDirectory;
use App\Modules\Admin\Support\Members\RoleAbilities;
use App\Modules\Admin\Support\Roles\RolesGuide;
use App\Modules\Admin\Support\UpgradeInvitation;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Actions\CreateCustomRole;
use App\Modules\Rbac\Actions\DeleteCustomRole;
use App\Modules\Rbac\Actions\RestoreCustomRole;
use App\Modules\Rbac\Actions\UpdateCustomRole;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\CustomRoleRules;
use App\Modules\Rbac\Support\MembershipLock;
use App\Modules\Rbac\Support\RoleCeiling;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The website's roles (design board «Nhóm quyền»): every system role and every role of the site's
 * own, highest first, each with where it stands, how many members and pending invitations hold it
 * and a few of the things it may do; a search that ignores accents, the «Tất cả · Mặc định · Tự tạo»
 * chips, the «Nhóm này làm được gì?» panel, one role read-only (`?nhom=<name>&che=xem`), the editor
 * of a role of the site's own (`che=sua`), a new role, blank or copied from one the member may give
 * (`che=moi`, `nhom` the source), and deleting a role into the trash with «Hoàn tác».
 *
 * Every write goes through the Rbac actions (CreateCustomRole, UpdateCustomRole, DeleteCustomRole,
 * RestoreCustomRole), which hold the rules; this screen only decides what to offer and says the
 * refusals in plain words. What the editor shows as the result («Xem trước khi lưu») is the set the
 * rules store: what the member ticked among what they may give, what the role holds that they may
 * not touch, without what the website's plan has switched off.
 *
 * A member without `roles.manage` sees the no-permission card; nothing below is read for them, and
 * every public method checks it again (the livewire/update endpoint bypasses route middleware).
 *
 * `roles` is platform-wide (D6, no RLS): a role is always addressed by its name and resolved among
 * this website's live roles only (the trash scope hides deleted ones), never by an id.
 */
#[Layout('admin::layouts.app')]
final class Index extends Component
{
    /** The longest role name a request may name (spatie's column); anything longer is no role. */
    private const int NAME_MAX = 125;

    /** The search fields keep at most this much of what was typed. */
    private const int SEARCH_MAX = 100;

    /** The chips: every role, the system roles, the website's own. */
    private const array KINDS = ['all', 'sys', 'cust'];

    /** The levels the editor offers («Vị trí cấp»), those below the member's own; the board's gaps between the system roles. */
    private const array LEVELS = [80, 60, 40, 25, 15, 5];

    /** A blank role starts from this level: the first offered below it. */
    private const int BLANK_LEVEL = 20;

    /**
     * The permissions that say most about a role, the most telling first: the «Việc tiêu biểu»
     * column names the first three a role holds.
     */
    private const array TYPICAL = ['settings.manage', 'users.manage', 'entries.publish', 'entries.approve', 'entries.edit.any', 'catalog.manage',
        'entries.review', 'entries.create', 'entries.edit.own', 'media.upload', 'forms.manage', 'entries.submit', 'entries.view', 'media.view',
        'forms.view', 'catalog.view'];

    /** The role opened (its name), the source of a copy, or '' on the list and for a blank role. */
    #[Url(as: 'nhom', except: '')]
    public string $role = '';

    /** '' the list · 'xem' one role read-only · 'sua' the editor of one role · 'moi' a new role. */
    #[Url(as: 'che', except: '')]
    public string $mode = '';

    public string $search = '';

    public string $kind = 'all';

    /** «Tìm việc» of one role's page. */
    public string $permSearch = '';

    /** The role «Nhóm này làm được gì?» is about (its name), '' while the panel is closed. */
    public string $explainRole = '';

    /** «Nhóm này làm được gì?» in the editor: the panel reads the draft as it would be saved. */
    #[Locked]
    public bool $explainDraft = false;

    /** The id of the button that opened the panel, so the focus goes back to it on closing. */
    public string $explainFrom = '';

    /** «Tên nhóm» as typed. */
    public string $name = '';

    /** «Vị trí cấp»: changed only through pickLevel(), which offers the levels below the member. */
    #[Locked]
    public int $level = 0;

    /**
     * The permissions ticked: changed only through toggle() and toggleArea().
     *
     * @var list<string>
     */
    #[Locked]
    public array $sel = [];

    /**
     * What the editor opened with («N thay đổi chưa lưu» counts from it).
     *
     * @var array{name: string, level: int, sel: list<string>}
     */
    #[Locked]
    public array $init = ['name' => '', 'level' => 0, 'sel' => []];

    /**
     * What «Đã đổi» and «Thêm · Bớt» compare with: the saved role, the role a copy starts from, nothing for a blank role.
     *
     * @var list<string>
     */
    #[Locked]
    public array $base = [];

    /**
     * The permissions the role holds now ([] for a new role): those the member may not touch stay.
     *
     * @var list<string>
     */
    #[Locked]
    public array $current = [];

    /** The edited role as it was opened: a save over a change made meanwhile by someone else is refused. */
    #[Locked]
    public string $openedAs = '';

    /** The name of the role the editor opened (it may be renamed in the field). */
    #[Locked]
    public string $origName = '';

    /** The refusal under «Tên nhóm», '' while there is none. */
    #[Locked]
    public string $nameError = '';

    /** Why a permission could not be unticked, and the area it is said in. */
    #[Locked]
    public string $note = '';

    #[Locked]
    public string $noteArea = '';

    /** The question open: '' none · 'leave' unsaved changes · 'inuse' a role someone holds · 'delete' a delete with no way back. */
    #[Locked]
    public string $ask = '';

    /** The role the question is about (its name). */
    #[Locked]
    public string $askRole = '';

    /** The dialog shows while this is true (the dialog component entangles it). */
    public bool $asking = false;

    /** The role just moved to the trash (its name), for «Hoàn tác». */
    #[Locked]
    public string $undoName = '';

    /** The editor's role went by another name or away meanwhile, and the member was told once. */
    #[Locked]
    public bool $staleTold = false;

    /** That exact row, sealed (encrypted, never a readable id): «Hoàn tác» brings back only it. */
    #[Locked]
    public string $undoKey = '';

    /** The guide was printed with the list header now on the page (wire:ignore keeps it until the header goes). */
    #[Locked]
    public bool $guidePrinted = false;

    public ?string $toast = null;

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    public bool $toastReload = false;

    public bool $toastUndo = false;

    /**
     * {@see who()}: read again on every request (never sent to the browser).
     *
     * @var array{ceiling: int, support: bool, member_lock: string|null, lock: string|null, assignable: array<string, true>}|null
     */
    private ?array $who = null;

    public function mount(): void
    {
        if (! $this->open()) {
            return;
        }
        $this->clean();
        $wanted = $this->mode;
        $name = $this->role;
        $this->mode = '';
        $this->role = '';
        $found = $name !== '' ? $this->siteRole($name) : null;
        if ($wanted === 'moi') {
            $this->startNew($name, quiet: true);
        } elseif ($wanted === 'xem' && $found instanceof Role) {
            // a link to a role's read-only page opens it so, even when the member could edit it
            $this->role = $found->name;
            $this->mode = 'xem';
        } elseif ($found instanceof Role) {
            $this->openRole($name);
        }
        // a link naming a role this website does not have (another site's, a deleted one) opens the list
    }

    public function updated(string $property): void
    {
        $this->ensureOpen();
        if ($property === 'mode' || $property === 'role') {
            // the screen moves between the list, a role and the editor through its methods only
            $this->closeEditor();
        }
        $this->clean();
        if ($property === 'name') {
            $this->nameError = '';
        }
    }

    /** A chip: «Tất cả», «Mặc định» or «Tự tạo». */
    public function setKind(string $kind): void
    {
        $this->ensureOpen();
        $this->kind = in_array($kind, self::KINDS, true) ? $kind : 'all';
    }

    /** «Bỏ lọc»: the search and the chip back to every role. */
    public function clearFilters(): void
    {
        $this->ensureOpen();
        $this->search = '';
        $this->kind = 'all';
    }

    /**
     * The name, «Sửa», «Xem», «Sửa nhóm» or a deep link: the editor of a role the member may change,
     * otherwise the role's permissions read-only. Only this website's roles.
     */
    public function openRole(string $name): void
    {
        $this->ensureOpen();
        $found = $this->siteRole($name);
        if (! $found instanceof Role) {
            $this->say(__('admin.members.x_gone'), 'error', reload: true);

            return;
        }
        $this->resetEditor();
        $this->explainRole = '';
        $this->role = $found->name;
        if ($this->manageable($found) && $this->writeLock() === null) {
            $this->loadEditor($found);
            $this->mode = 'sua';
        } else {
            $this->mode = 'xem';
        }
        $this->js('window.scrollTo(0, 0)');
    }

    /** «Nhóm trống». */
    public function newRole(): void
    {
        $this->startNew('');
    }

    /** «Từ: X», «Nhân bản», «Nhân bản để tùy chỉnh»: a new role that starts from one the member may give. */
    public function copyRole(string $name): void
    {
        $this->startNew($name);
    }

    /** «Quay lại Nhóm quyền» and «Hủy»: with unsaved changes, asks first. */
    public function backToList(): void
    {
        $this->ensureOpen();
        if ($this->editing() && $this->dirty() > 0) {
            $this->ask('leave', '');

            return;
        }
        $this->closeEditor();
    }

    public function clearPermSearch(): void
    {
        $this->ensureOpen();
        $this->permSearch = '';
    }

    /** A free permission ticked or unticked; one another ticked permission needs stays, with the reason in its area. */
    public function toggle(string $permission): void
    {
        $this->ensureOpen();
        if (! $this->editing() || $this->writeLock() !== null || ! in_array($permission, $this->grantable(), true)) {
            return;
        }
        $this->note = '';
        $this->noteArea = '';
        if (in_array($permission, $this->sel, true)) {
            $by = array_values(array_filter($this->sel, fn (string $p): bool => $p !== $permission && in_array($permission, PermissionRegistry::IMPLIES[$p] ?? [], true)));
            if ($by !== []) {
                $this->note = Cast::string(__('admin.roles.implied_block', [
                    'perm' => self::cellLabel($permission),
                    'by' => implode(', ', array_map(self::cellLabel(...), $by)),
                ]));
                $this->noteArea = PermissionRegistry::prefixOf($permission);

                return;
            }
            $this->sel = array_values(array_diff($this->sel, [$permission]));

            return;
        }
        $grant = $this->grantable();
        $this->sel = self::ordered(array_filter(PermissionRegistry::withImplied([...$this->sel, $permission]),
            fn (string $p): bool => in_array($p, $grant, true) || in_array($p, $this->sel, true)));
    }

    /** «Chọn cả hàng»: every free permission of the area on, or off when all of them are. */
    public function toggleArea(string $area): void
    {
        $this->ensureOpen();
        $areas = RoleAbilities::areas();
        if (! $this->editing() || $this->writeLock() !== null || ! isset($areas[$area])) {
            return;
        }
        $grant = $this->grantable();
        $free = array_values(array_intersect($areas[$area], $grant));
        if ($free === []) {
            return;
        }
        $this->note = '';
        $this->noteArea = '';
        $this->sel = array_diff($free, $this->sel) === []
            ? array_values(array_diff($this->sel, $free))
            : self::ordered(array_filter(PermissionRegistry::withImplied([...$this->sel, ...$free]),
                fn (string $p): bool => in_array($p, $grant, true) || in_array($p, $this->sel, true)));
    }

    /** «Vị trí cấp»: one of the levels offered. */
    public function pickLevel(int $level): void
    {
        $this->ensureOpen();
        if ($this->editing() && in_array($level, $this->levelChoices(), true)) {
            $this->level = $level;
        }
    }

    /** Leaving «Tên nhóm»: the refusal the save would give for the name, said now. */
    public function checkName(): void
    {
        $this->ensureOpen();
        if (! $this->editing()) {
            return;
        }
        $rules = app(CustomRoleRules::class);
        $except = $this->mode === 'sua' ? $this->siteRole($this->origName) : null;
        try {
            $name = $rules->name($this->name, $except instanceof Role ? $this->origName : null);
            $rules->assertNameFree($name, $except);
            $this->nameError = '';
        } catch (RoleChangeRejectedException $e) {
            $this->nameError = $this->nameText($e->reason);
        }
    }

    /** «Lưu nhóm» / «Tạo nhóm», and the save of the unsaved-changes dialog: saved, then the list with what happened; a refusal stays on the page. */
    public function save(): void
    {
        if ($this->store()) {
            $this->closeEditor();
        }
    }

    /** The unsaved-changes dialog's discard: the editor back to how it opened. */
    public function discardDraft(): void
    {
        $this->ensureOpen();
        if ($this->editing()) {
            $this->name = $this->init['name'];
            $this->level = $this->init['level'];
            $this->sel = $this->init['sel'];
            $this->nameError = '';
            $this->note = '';
            $this->noteArea = '';
        }
    }

    /** «Tải lại» after someone else changed the role meanwhile: the editor opens it again as it is now. */
    public function reloadEditor(): void
    {
        $this->ensureOpen();
        $this->toast = null;
        $this->toastReload = false;
        $name = $this->origName;
        $this->closeEditor();
        if ($name !== '') {
            $this->openRole($name);
        }
    }

    /**
     * «Xóa…»: a role someone holds or is invited to cannot go (says who, and opens Thành viên); a
     * role that can come back goes to the trash at once with «Hoàn tác»; while the plan has no
     * custom roles nothing could bring it back, so that delete asks first.
     */
    public function askDelete(string $name): void
    {
        $this->ensureOpen();
        $found = $this->siteRole($name);
        if (! $found instanceof Role) {
            $this->say(__('admin.members.x_gone'), 'error', reload: true);

            return;
        }
        abort_if($found->is_system, 403);   // the screen never offers it
        if (! $this->manageable($found)) {
            // moved to the member's level or above a moment ago
            $this->say($this->reasonText('changed_elsewhere'), 'error', reload: true);

            return;
        }
        $lock = $this->writeLock();
        if ($lock !== null) {
            $this->say($lock, 'error');

            return;
        }
        [$members, $invites] = $this->holders($found->name);
        if ($members > 0 || $invites > 0) {
            $this->ask('inuse', $found->name);

            return;
        }
        if (! app(RestoreCustomRole::class)->availableOnThisSite()) {
            $this->ask('delete', $found->name);

            return;
        }
        $this->delete($found, undo: true);
    }

    /** The dialog's main button. */
    public function confirmAsk(): void
    {
        $this->ensureOpen();
        $ask = $this->ask;
        $name = $this->askRole;
        $this->cancelAsk();
        if ($ask === 'leave') {
            $this->closeEditor();
        } elseif ($ask === 'inuse') {
            $this->redirectRoute('admin.users.index', ['nhom' => [$name]], navigate: true);
        } elseif ($ask === 'delete') {
            $found = $this->siteRole($name);
            if ($found instanceof Role) {
                $this->delete($found, undo: false);
            } else {
                $this->say(__('admin.members.x_gone'), 'error', reload: true);
            }
        }
    }

    /** «Hủy», «Ở lại», «Đóng», Escape or the backdrop. */
    public function cancelAsk(): void
    {
        $this->ensureOpen();
        $this->asking = false;
        $this->ask = '';
        $this->askRole = '';
    }

    /** «Hoàn tác» on «Đã chuyển nhóm … vào Thùng rác»: the role just deleted comes back as it was. */
    public function undoDelete(): void
    {
        $this->ensureOpen();
        $key = $this->undoKey;
        $this->undoName = '';
        $this->undoKey = '';
        $this->toastUndo = false;
        if ($key === '') {
            return;
        }
        try {
            $id = (int) Crypt::decryptString($key);
        } catch (DecryptException) {
            return;
        }
        // the row this member just moved, still in this website's trash
        $trashed = Role::onlyTrashed()->where('site_id', app(CurrentSite::class)->id())->where('is_system', false)->whereKey($id)->first();
        if (! $trashed instanceof Role) {
            $this->say(__('rbac.role_change.gone'), 'error');

            return;
        }
        try {
            app(RestoreCustomRole::class)->handle($this->actor(), $trashed);
            $this->say(__('admin.roles.restored'));
        } catch (RoleChangeRejectedException $e) {
            $this->say($this->reasonText($e->reason), 'error');
        } catch (AuthorizationException) {
            $this->say($this->reasonText('changed_elsewhere'), 'error', reload: true);
        }
    }

    /** «Nhóm này làm được gì?» / «Xem đủ»: what the role lets its holder do. Only this website's roles. */
    public function openExplain(string $name, string $from = ''): void
    {
        $this->ensureOpen();
        $found = $this->siteRole($name);
        if (! $found instanceof Role) {
            $this->explainRole = '';
            $this->say(__('admin.members.x_gone'), 'error', reload: true);

            return;
        }
        $this->explainRole = $found->name;
        $this->explainDraft = false;
        $this->explainFrom = preg_match('/^[a-z0-9-]{1,40}$/', $from) === 1 ? $from : '';
    }

    /** «Nhóm này làm được gì?» in the editor: what the role would let its holder do once saved. */
    public function explainEditor(): void
    {
        $this->ensureOpen();
        if ($this->editing()) {
            $this->explainRole = '';
            $this->explainDraft = true;
            $this->explainFrom = 'roles-edit-explain';
        }
    }

    public function closeExplain(): void
    {
        $this->ensureOpen();
        $this->explainRole = '';
        $this->explainDraft = false;
    }

    public function dismissToast(int $seq = 0): void
    {
        $this->ensureOpen();
        if ($seq === 0 || $seq === $this->toastSeq) {
            $this->toast = null;
            $this->toastReload = false;
            $this->toastUndo = false;
            $this->undoName = '';
            $this->undoKey = '';
        }
    }

    /** «Tải lại» on a message about a role that is gone: the list read afresh, the panel let go. */
    public function reloadList(): void
    {
        $this->ensureOpen();
        $this->explainRole = '';
        $this->toast = null;
        $this->toastReload = false;
        if ($this->editing() && $this->origName !== '') {
            $this->reloadEditor();
        }
    }

    public function render(): View
    {
        $title = __('admin.roles.title');
        $actor = $this->actor();
        if (! $this->open()) {
            return $this->page('admin::livewire.roles.noperm', $title, ['myRole' => RoleLabel::topOf($actor)]);
        }
        $this->clean();
        $siteId = app(CurrentSite::class)->id();
        $this->who = null;   // an action of this request may have changed the roles

        $failed = false;
        $roles = new Collection;
        $members = [];
        $invites = [];
        try {
            // a savepoint inside the request's tenancy transaction: a failed read leaves it usable for the rest of the page
            [$roles, $members, $invites] = DB::transaction(fn (): array => [
                // the role a copy was made from comes in one query, a trashed one too: its name still tells where the copy began
                Role::query()->where('site_id', $siteId)
                    ->with(['permissions:id,name', 'forkedFrom' => fn (BelongsTo $q): BelongsTo => $q->withTrashed()->select(['id', 'name', 'site_id', 'is_system'])])
                    ->get(['id', 'name', 'level', 'is_system', 'site_id', 'forked_from_role_id']),
                app(MemberDirectory::class)->counts()['roles'],
                app(InvitationDirectory::class)->roleCounts('pending'),
            ]);
        } catch (QueryException $e) {
            report($e);
            $failed = true;
        }

        // highest first; a system role before a role of the site's own at the same level
        $roles = $roles->sort(fn (Role $a, Role $b): int => [$b->level, $b->is_system ? 1 : 0, RoleLabel::for($a)] <=> [$a->level, $a->is_system ? 1 : 0, RoleLabel::for($b)])->values();
        $siteCeiling = app(RoleCeiling::class)->forSite();
        $support = $this->who()['support'];
        $lockedWhy = $this->who()['member_lock'];
        $planOn = app(Entitlement::class)->featureEnabled('rbac.custom_roles');
        $upsell = app(UpgradeInvitation::class)->isOn();
        $writeLock = $this->writeLock();
        $planWhy = $planOn ? null : Cast::string(__($upsell ? 'admin.roles.lock_plan_up' : 'admin.roles.lock_plan_off'));

        $rows = $roles->map(fn (Role $r): array => $this->rowView($r, $roles, $members, $invites))->all();
        $custom = $roles->where('is_system', false)->count();
        $needle = self::fold($this->search);
        $shown = array_values(array_filter($rows, fn (array $r): bool => ($this->kind === 'all' || ($this->kind === 'sys') === $r['system'])
            && ($needle === '' || str_contains(self::fold($r['name']), $needle))));

        $opened = $this->mode !== '' && $this->mode !== 'moi' ? $roles->firstWhere('name', $this->mode === 'sua' ? $this->origName : $this->role) : null;
        if (! $failed && $this->mode === 'sua' && ! $opened instanceof Role) {
            // renamed or deleted by someone else while this member edits: the draft stays, they are told
            // once and choose «Tải lại» (which opens the role again only if it still goes by that name)
            if (! $this->staleTold) {
                $this->staleTold = true;
                $this->say($this->reasonText('changed_elsewhere'), 'error', reload: true);
            }
        } elseif (! $failed && $this->mode === 'xem' && ! $opened instanceof Role) {
            // a role gone meanwhile, or a name the browser made up: the list
            $this->closeEditor();
        }
        $viewed = $this->mode === 'xem' && $opened instanceof Role ? $opened : null;
        $explained = $this->explainRole !== '' ? $roles->firstWhere('name', $this->explainRole) : null;
        if (! $this->editing()) {
            $this->explainDraft = false;
        }

        $guide = null;
        if ($this->mode === '' && ! $failed && ! $this->guidePrinted) {
            $this->guidePrinted = true;
            $guide = RolesGuide::for($actor, $planOn ? '' : ($upsell ? 'up' : 'off'), $rows[0]['name'] ?? null)->toArray($actor, $siteId);
        }

        return $this->page('admin::livewire.roles.index', $title, [
            'failed' => $failed,
            'support' => $support,
            'lockedWhy' => $lockedWhy,
            'writeLock' => $writeLock,
            'planOn' => $planOn,
            'planWhy' => $planWhy,
            'upsell' => $upsell,
            'total' => count($rows),
            'custom' => $custom,
            'rows' => $shown,
            // «Tạo nhóm ▾ · Từ nhóm có sẵn»: every role the member may give, highest first
            'sources' => array_values(array_filter($rows, fn (array $r): bool => $r['copyable'])),
            'filtering' => $needle !== '' || $this->kind !== 'all',
            'guide' => $guide,
            'viewed' => $viewed instanceof Role ? $this->roleView($viewed, $roles, $members, $invites, $support, $siteCeiling) : null,
            'editor' => $this->editing() && ! $failed ? $this->editorView($roles, $members, $invites, $siteCeiling) : null,
            'explain' => match (true) {
                $this->explainDraft && ! $failed => $this->draftExplainView($roles, $siteCeiling),
                $explained instanceof Role => $this->explainView($explained, $roles, $members, $invites, $siteCeiling),
                default => null,
            },
            'dialog' => $this->dialogView($members, $invites),
        ]);
    }

    /**
     * Save the editor through the actions. A refusal of the name goes under the field (the field
     * takes the focus), any other in a message; the draft stays as it was typed.
     */
    private function store(): bool
    {
        $this->ensureOpen();
        if (! $this->editing()) {
            return false;
        }
        $lock = $this->writeLock();
        if ($lock !== null) {
            $this->say($lock, 'error');

            return false;
        }
        if ($this->mode === 'sua' && $this->dirty() === 0) {
            return false;   // «Lưu nhóm» stays off until something changed
        }
        $actor = $this->actor();
        $grant = $this->grantable();
        $requested = array_values(array_intersect(PermissionRegistry::withImplied($this->sel), $grant));

        try {
            if ($this->mode === 'moi') {
                $source = $this->role !== '' ? $this->siteRole($this->role) : null;
                $created = app(CreateCustomRole::class)->handle($actor, $this->name, $this->level, $requested, $source);
                $this->say(__('admin.roles.saved_new', ['role' => $created->name]));
            } else {
                $found = $this->siteRole($this->origName);
                if (! $found instanceof Role) {
                    throw new RoleChangeRejectedException('changed_elsewhere');
                }
                $saved = app(UpdateCustomRole::class)->handle($actor, $found, $this->name, $this->level, $requested, $this->openedAs);
                [$members] = $this->holders($saved->name);
                $this->say(__('admin.roles.saved', ['role' => $saved->name, 'members' => trans_choice('admin.members.x_members', $members, ['n' => $members])]));
            }
        } catch (RoleChangeRejectedException $e) {
            if (in_array($e->reason, ['name_invalid', 'name_reserved', 'name_taken'], true)) {
                $this->nameError = $this->nameText($e->reason);
                $this->js("document.getElementById('roles-name')?.focus()");
            } else {
                $this->say($this->reasonText($e->reason), 'error', reload: in_array($e->reason, ['changed_elsewhere', 'gone'], true));
            }

            return false;
        } catch (AuthorizationException) {
            // the role (or the role copied from) was moved above the member or deleted a moment ago
            $this->say($this->reasonText('changed_elsewhere'), 'error', reload: true);

            return false;
        } catch (QueryException $e) {
            report($e);
            $this->say(__('admin.roles.save_failed'), 'error');

            return false;
        }

        return true;
    }

    /** A new role, blank ('') or starting from one the member may give; refused with the reason while it cannot be made. */
    private function startNew(string $from, bool $quiet = false): void
    {
        $this->ensureOpen();
        $why = $this->writeLock() ?? (app(Entitlement::class)->featureEnabled('rbac.custom_roles') ? null
            : Cast::string(__(app(UpgradeInvitation::class)->isOn() ? 'admin.roles.lock_plan_up' : 'admin.roles.lock_plan_off')));
        $source = $from !== '' ? $this->siteRole($from) : null;
        if ($why !== null || ($from !== '' && (! $source instanceof Role || ! $this->copyable($source)))) {
            if (! $quiet) {
                $why !== null ? $this->say($why, 'error') : $this->say(__('admin.members.x_gone'), 'error', reload: true);
            }

            return;
        }

        $this->resetEditor();
        $this->explainRole = '';
        $grant = $this->grantable();
        $from = $source instanceof Role ? $source->level : self::BLANK_LEVEL;
        $ceiling = $this->who()['ceiling'];
        $below = array_values(array_filter(self::LEVELS, fn (int $l): bool => $l < $ceiling));
        $this->level = array_values(array_filter($below, fn (int $l): bool => $l < $from))[0] ?? ($below === [] ? 1 : $below[count($below) - 1]);
        $this->sel = $source instanceof Role
            ? self::ordered(array_intersect(PermissionRegistry::withImplied($this->held($source)), $grant))
            : self::ordered(array_intersect(['entries.view'], $grant));
        $this->name = $source instanceof Role ? mb_substr(Cast::string(__('admin.roles.copy_name', ['role' => RoleLabel::for($source)])), 0, CustomRoleRules::MAX_NAME_LENGTH) : '';
        $this->base = $source instanceof Role ? $this->held($source) : [];
        $this->current = [];
        $this->init = ['name' => $this->name, 'level' => $this->level, 'sel' => $this->sel];
        $this->role = $source instanceof Role ? $source->name : '';
        $this->mode = 'moi';
        $this->guidePrinted = false;
        $this->js("window.scrollTo(0, 0); document.getElementById('roles-name')?.focus()");
    }

    private function loadEditor(Role $role): void
    {
        $held = $this->held($role);
        $this->name = $role->name;
        $this->level = (int) $role->level;
        $this->sel = $held;
        $this->base = $held;
        $this->current = $held;
        $this->init = ['name' => $role->name, 'level' => (int) $role->level, 'sel' => $held];
        $this->openedAs = CustomRoleRules::fingerprint($role);
        $this->origName = $role->name;
        $this->guidePrinted = false;
    }

    private function resetEditor(): void
    {
        $this->name = '';
        $this->level = 0;
        $this->sel = [];
        $this->init = ['name' => '', 'level' => 0, 'sel' => []];
        $this->base = [];
        $this->current = [];
        $this->openedAs = '';
        $this->origName = '';
        $this->nameError = '';
        $this->note = '';
        $this->noteArea = '';
        $this->permSearch = '';
        $this->explainDraft = false;
        $this->staleTold = false;
    }

    /** Back to the list, the draft let go. */
    private function closeEditor(): void
    {
        $this->resetEditor();
        $this->role = '';
        $this->mode = '';
        $this->guidePrinted = false;
    }

    private function delete(Role $role, bool $undo): void
    {
        try {
            $moved = app(DeleteCustomRole::class)->handle($this->actor(), $role);
        } catch (RoleChangeRejectedException $e) {
            if ($e->reason === 'in_use') {
                $this->ask('inuse', $role->name);   // someone got the role meanwhile

                return;
            }
            $this->say($this->reasonText($e->reason), 'error');

            return;
        } catch (AuthorizationException) {
            $this->say($this->reasonText('changed_elsewhere'), 'error', reload: true);

            return;
        }
        if (! $moved) {
            // someone else deleted it a moment earlier: nothing of this member's to undo
            $this->say(__('admin.members.x_gone'), 'error', reload: true);

            return;
        }
        $this->explainRole = $this->explainRole === $role->name ? '' : $this->explainRole;
        $this->say(__($undo ? 'admin.roles.deleted' : 'admin.roles.deleted_off', ['role' => $role->name]));
        if ($undo) {
            // the exact row this member moved, never another of the same name deleted meanwhile
            $this->undoName = $role->name;
            $this->undoKey = Crypt::encryptString((string) Cast::int($role->getKey()));
            $this->toastUndo = true;
        }
    }

    private function ask(string $kind, string $role): void
    {
        $this->ask = $kind;
        $this->askRole = $role;
        $this->asking = true;
    }

    /**
     * The open question, its words read now (the role's holders as they are).
     *
     * @param  array<string, int>  $members
     * @param  array<string, int>  $invites
     * @return array{title: string, lines: list<string>, ok: string, cancel: string, danger: bool, icon: string}|null
     */
    private function dialogView(array $members, array $invites): ?array
    {
        if ($this->ask === '') {
            return null;
        }
        if ($this->ask === 'leave') {
            $n = $this->dirty();

            return ['title' => __('admin.roles.leave_title'), 'lines' => [trans_choice('admin.roles.leave_text', $n, ['n' => $n])],
                'ok' => __('admin.roles.leave_ok'), 'cancel' => __('admin.roles.leave_stay'), 'danger' => true, 'icon' => 'ri-error-warning'];
        }
        if ($this->ask === 'inuse') {
            $m = $members[$this->askRole] ?? 0;
            $i = $invites[$this->askRole] ?? 0;
            $words = ['role' => $this->askRole, 'members' => trans_choice('admin.members.x_members', $m, ['n' => $m]),
                'invites' => trans_choice('admin.roles.invites_waiting', $i, ['n' => $i])];

            return ['title' => __('admin.roles.inuse_title'), 'lines' => [__($i > 0 ? 'admin.roles.inuse_text_inv' : 'admin.roles.inuse_text', $words)],
                'ok' => __('admin.roles.inuse_ok'), 'cancel' => __('admin.close'), 'danger' => false, 'icon' => 'ri-error-warning'];
        }

        return ['title' => __('admin.roles.delete_off_title', ['role' => $this->askRole]), 'lines' => [__('admin.roles.delete_off_text')],
            'ok' => __('admin.roles.delete_off_ok'), 'cancel' => __('admin.cancel'), 'danger' => true, 'icon' => 'ri-delete-bin'];
    }

    /**
     * The editor: its title, the name and level fields, every area with each permission free,
     * locked (the member does not hold it) or off (the plan switched its area off), what saving
     * would store, and the save bar's status.
     *
     * @param  Collection<int, Role>  $roles
     * @param  array<string, int>  $members
     * @param  array<string, int>  $invites
     * @param  list<string>  $siteCeiling
     * @return array<string, mixed>
     */
    private function editorView(Collection $roles, array $members, array $invites, array $siteCeiling): array
    {
        $actor = $this->actor();
        $grant = $this->grantable();
        $new = $this->mode === 'moi';
        $sel = array_flip($this->sel);
        $current = array_flip($this->current);
        $base = array_flip($this->base);
        $needle = self::fold($this->permSearch);
        $upsell = app(UpgradeInvitation::class)->isOn();
        $from = $new && $this->role !== '' ? $roles->firstWhere('name', $this->role) : null;
        $fromName = $from instanceof Role ? RoleLabel::for($from) : null;

        $areas = [];
        foreach (RoleAbilities::areas() as $area => $permissions) {
            $areaName = Cast::string(__('admin.members.xa.'.$area));
            $cells = [];
            $on = 0;
            $free = [];
            $offArea = false;
            foreach ($permissions as $permission) {
                $state = ! in_array($permission, $siteCeiling, true) ? 'off' : (in_array($permission, $grant, true) ? 'free' : 'lock');
                $has = $state === 'free' ? isset($sel[$permission]) : isset($current[$permission]);
                $on += $has ? 1 : 0;
                $offArea = $offArea || $state === 'off';
                if ($state === 'free') {
                    $free[] = $permission;
                }
                $label = self::cellLabel($permission);
                $desc = Cast::string(__('admin.roles.cd.'.$permission));
                if ($needle !== '' && ! str_contains(self::fold($areaName.' '.$label.' '.$desc), $needle)) {
                    continue;
                }
                $cells[] = [
                    'key' => $permission,
                    'label' => $label,
                    'desc' => $desc,
                    'state' => $state,
                    'on' => $has,
                    'changed' => ($new && $fromName !== null || ! $new) && $has !== isset($base[$permission]),
                    'why' => $state === 'free' ? '' : Cast::string(__($state === 'off' ? ($has ? 'admin.roles.off_why_on' : 'admin.roles.off_why_off') : ($has ? 'admin.roles.lock_why_on' : 'admin.roles.lock_why_off'), ['area' => $areaName, 'perm' => $label])),
                    'aria' => Cast::string(__($has ? 'admin.roles.lock_aria_on' : 'admin.roles.lock_aria_off', ['area' => $areaName, 'perm' => $label])),
                ];
            }
            if ($cells === []) {
                continue;
            }
            $areas[] = [
                'key' => $area,
                'name' => $areaName,
                'count' => $on.'/'.count($permissions),
                'all' => count($free) > 1,
                'allOn' => $free !== [] && array_diff($free, $this->sel) === [],
                'off' => $offArea ? Cast::string(__($upsell ? 'admin.roles.mod_off_up' : 'admin.roles.mod_off', ['area' => $areaName])) : '',
                'note' => $this->noteArea === $area ? $this->note : '',
                'cells' => $cells,
            ];
        }

        // what the save stores (CustomRoleRules::permissions): the ticked among those the member may give,
        // with what they imply, plus what the role holds that they may not touch; what the plan switched off goes
        [$final, $untouched, $dropped] = $this->finalSet($grant, $siteCeiling);
        $added = array_values(array_diff($final, $this->base));
        $removed = array_values(array_diff($this->base, $final, $dropped));
        $list = fn (array $permissions): string => implode(', ', array_map(fn (string $p): string => Cast::string(__('admin.members.perm.'.$p)), self::ordered(Cast::strings($permissions))));
        $m = $members[$this->origName] ?? 0;
        $i = $invites[$this->origName] ?? 0;
        $affect = match (true) {
            $new => __('admin.roles.pv_new'),
            $m === 0 => __('admin.roles.pv_nobody'),
            $i > 0 => __('admin.roles.pv_affect_inv', ['members' => trans_choice('admin.members.x_members', $m, ['n' => $m]), 'invites' => trans_choice('admin.roles.invites_waiting', $i, ['n' => $i])]),
            default => __('admin.roles.pv_affect', ['members' => trans_choice('admin.members.x_members', $m, ['n' => $m])]),
        };

        $choices = $this->levelChoices();
        $probe = new Role;
        $levels = array_map(function (int $level) use ($probe, $roles): array {
            $probe->forceFill(['level' => $level, 'is_system' => false]);

            return ['level' => $level, 'label' => RoleAbilities::position($probe, $roles)];
        }, $choices);
        $dirty = $this->dirty();

        return [
            'new' => $new,
            'title' => $new ? __('admin.roles.new_title') : __('admin.roles.edit_title', ['role' => $this->init['name']]),
            'desc' => $new ? ($fromName !== null ? __('admin.roles.new_desc_from', ['role' => $fromName]) : __('admin.roles.new_desc')) : __('admin.roles.edit_desc'),
            'from' => $fromName,
            'levels' => $levels,
            'me' => RoleLabel::topOf($actor) ?? '',
            'areas' => $areas,
            'pv' => [
                'none' => $added === [] && $removed === [] && $dropped === [],
                'base' => $new ? ($fromName !== null ? __('admin.roles.pv_base_from', ['role' => $fromName]) : __('admin.roles.pv_base_blank')) : __('admin.roles.pv_base_saved'),
                'add' => $list($added),
                'rem' => $list($removed),
                'keep' => $list($untouched),
                'drop' => $list($dropped),
                'affect' => $affect,
            ],
            'dirty' => $dirty,
            'saveOff' => ! $new && $dirty === 0,
        ];
    }

    /**
     * What the save stores, as CustomRoleRules::permissions decides it, what of it the member may not
     * touch, and what the plan switched off goes.
     *
     * @param  list<string>  $grant
     * @param  list<string>  $siteCeiling
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private function finalSet(array $grant, array $siteCeiling): array
    {
        $untouched = array_values(array_diff(array_intersect($this->current, $siteCeiling), $grant));
        $final = self::ordered([...array_intersect(PermissionRegistry::withImplied($this->sel), $grant), ...$untouched]);

        return [$final, $untouched, array_values(array_diff($this->current, $siteCeiling))];
    }

    /**
     * «Nhóm này làm được gì?» about the draft: its name as typed (or the editor's title), its level, what it would hold.
     *
     * @param  Collection<int, Role>  $roles
     * @param  list<string>  $siteCeiling
     * @return array{key: string, name: string, meta: string, editable: bool, areas: list<array{key: string, name: string, off: string, items: list<array{ok: bool, text: string}>}>}
     */
    private function draftExplainView(Collection $roles, array $siteCeiling): array
    {
        [$final] = $this->finalSet($this->grantable(), $siteCeiling);
        $probe = new Role;
        $probe->forceFill(['level' => $this->level, 'is_system' => false]);
        $name = trim($this->name);

        return [
            'key' => '',
            'name' => $name !== '' ? $name : Cast::string(__('admin.roles.new_title')),
            'meta' => __('admin.members.x_custom').' · '.RoleAbilities::position($probe, $roles),
            'editable' => false,
            'areas' => RoleAbilities::explain($final, in_array('catalog.view', $siteCeiling, true)),
        ];
    }

    /**
     * One row of the list.
     *
     * @param  Collection<int, Role>  $roles
     * @param  array<string, int>  $members
     * @param  array<string, int>  $invites
     * @return array{key: string, name: string, system: bool, from: string|null, desc: string|null, pos: string, members: int, invites: int, membersText: string, typical: string, editable: bool, copyable: bool, deletable: bool}
     */
    private function rowView(Role $role, Collection $roles, array $members, array $invites): array
    {
        $m = $members[$role->name] ?? 0;
        $i = $invites[$role->name] ?? 0;
        $manageable = $this->manageable($role);

        return [
            'key' => $role->name,
            'name' => RoleLabel::for($role),
            'system' => $role->is_system,
            'from' => self::forkedFromName($role),
            'desc' => $role->is_system ? self::systemDesc($role->name) : null,
            'pos' => RoleAbilities::position($role, $roles),
            'members' => $m,
            'invites' => $i,
            'membersText' => self::membersText($m, $i),
            'typical' => self::typical($this->held($role)),
            'editable' => $manageable && $this->writeLock() === null,
            'copyable' => $this->copyable($role),
            'deletable' => $manageable,
        ];
    }

    /**
     * One role read-only: its areas, each with every permission of the catalog in it, held or not,
     * narrowed by «Tìm việc», and whether «Nhân bản để tùy chỉnh» is offered.
     *
     * @param  Collection<int, Role>  $roles
     * @param  array<string, int>  $members
     * @param  array<string, int>  $invites
     * @param  list<string>  $siteCeiling
     * @return array{key: string, name: string, desc: string, note: string|null, copyable: bool, areas: list<array{key: string, name: string, held: int, of: int, off: string, cells: list<array{key: string, label: string, desc: string, on: bool}>}>}
     */
    private function roleView(Role $role, Collection $roles, array $members, array $invites, bool $support, array $siteCeiling): array
    {
        $held = array_flip($this->held($role));
        $needle = self::fold($this->permSearch);
        $areas = [];
        foreach (RoleAbilities::areas() as $area => $permissions) {
            $areaName = Cast::string(__('admin.members.xa.'.$area));
            $cells = [];
            foreach ($permissions as $permission) {
                $label = self::cellLabel($permission);
                $desc = Cast::string(__('admin.roles.cd.'.$permission));
                if ($needle !== '' && ! str_contains(self::fold($areaName.' '.$label.' '.$desc), $needle)) {
                    continue;
                }
                $cells[] = ['key' => $permission, 'label' => $label, 'desc' => $desc, 'on' => isset($held[$permission])];
            }
            if ($cells === []) {
                continue;
            }
            $off = array_diff($permissions, $siteCeiling) !== []
                ? Cast::string(__(app(UpgradeInvitation::class)->isOn() ? 'admin.roles.mod_off_up' : 'admin.roles.mod_off', ['area' => $areaName]))
                : '';
            $areas[] = [
                'key' => $area,
                'name' => $areaName,
                'held' => count(array_intersect_key($held, array_flip($permissions))),
                'of' => count($permissions),
                'off' => $off,
                'cells' => $cells,
            ];
        }

        $note = match (true) {
            $support => Cast::string(__('admin.support.locked')),
            $role->is_system => Cast::string(__('admin.roles.ro_system')),
            ! $this->manageable($role) => Cast::string(__('admin.roles.ro_level')),
            default => $this->writeLock(),
        };

        return [
            'key' => $role->name,
            'name' => RoleLabel::for($role),
            'desc' => RoleAbilities::position($role, $roles).' · '.self::membersText($members[$role->name] ?? 0, $invites[$role->name] ?? 0),
            'note' => $note,
            'copyable' => $this->copyable($role),
            'areas' => $areas,
        ];
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @param  array<string, int>  $members
     * @param  array<string, int>  $invites
     * @param  list<string>  $siteCeiling
     * @return array{key: string, name: string, meta: string, editable: bool, areas: list<array{key: string, name: string, off: string, items: list<array{ok: bool, text: string}>}>}
     */
    private function explainView(Role $role, Collection $roles, array $members, array $invites, array $siteCeiling): array
    {
        $meta = [
            $role->is_system ? __('admin.members.x_sys') : __('admin.members.x_custom'),
            RoleAbilities::position($role, $roles),
            self::membersText($members[$role->name] ?? 0, $invites[$role->name] ?? 0),
        ];

        return [
            'key' => $role->name,
            'name' => RoleLabel::for($role),
            'meta' => implode(' · ', $meta),
            'editable' => $this->manageable($role) && $this->writeLock() === null && ! $this->editing(),
            'areas' => RoleAbilities::explain($this->held($role), in_array('catalog.view', $siteCeiling, true)),
        ];
    }

    /**
     * The catalog permissions the role holds (a retired one kept for a rollback is not counted).
     *
     * @return list<string>
     */
    private function held(Role $role): array
    {
        return array_values(array_intersect(PermissionRegistry::TENANT_PERMISSIONS, Cast::strings($role->permissions->pluck('name')->all())));
    }

    /** A role of the site's own below the member: they may edit and delete it (CustomRoleRules::assertManageable decides on the write). */
    private function manageable(Role $role): bool
    {
        return ! $role->is_system && $role->level < $this->who()['ceiling'];
    }

    /**
     * What this request knows of the member, read once however many rows ask: their level ceiling,
     * why they may change nothing now, and the roles they may give (by name).
     *
     * @return array{ceiling: int, support: bool, member_lock: string|null, lock: string|null, assignable: array<string, true>}
     */
    private function who(): array
    {
        if ($this->who === null) {
            $actor = $this->actor();
            $guard = app(RoleHierarchyGuard::class);
            $ceiling = $guard->levelCeiling($actor);
            $assignable = [];
            foreach ($guard->assignableRoles($actor) as $role) {
                if ($role->level < $ceiling) {
                    $assignable[$role->name] = true;
                }
            }
            $support = app(SupportSession::class)->active();
            $memberLock = app(MembershipLock::class)->reason($actor, app(CurrentSite::class)->id());
            $this->who = [
                'ceiling' => $ceiling,
                'support' => $support,
                'member_lock' => $memberLock,
                'lock' => $support ? Cast::string(__('admin.support.locked')) : $memberLock,
                'assignable' => $assignable,
            ];
        }

        return $this->who;
    }

    /**
     * A role the member may give (below them, and every permission of it theirs): a new role may
     * start from it, as «Nhân bản» on the board offers only those.
     */
    private function copyable(Role $role): bool
    {
        return isset($this->who()['assignable'][$role->name]);
    }

    /**
     * What the member may give or take away on this website (the plan's modules ∩ their own permissions).
     *
     * @return list<string>
     */
    private function grantable(): array
    {
        $ceiling = app(RoleCeiling::class);

        return $ceiling->grantableBy($this->actor(), $ceiling->forSite());
    }

    /**
     * The levels «Vị trí cấp» offers: those below the member, and the role's own when it is none of them.
     *
     * @return list<int>
     */
    private function levelChoices(): array
    {
        $ceiling = $this->who()['ceiling'];
        $choices = array_values(array_filter(self::LEVELS, fn (int $l): bool => $l < $ceiling));
        if ($this->init['level'] > 0 && $this->init['level'] < $ceiling && ! in_array($this->init['level'], $choices, true)) {
            $choices[] = $this->init['level'];
        }
        rsort($choices);

        return $choices;
    }

    /** «N thay đổi chưa lưu»: the name, the level and each permission ticked or unticked since the editor opened. */
    private function dirty(): int
    {
        if (! $this->editing()) {
            return 0;
        }

        return count(array_diff($this->sel, $this->init['sel'])) + count(array_diff($this->init['sel'], $this->sel))
            + ($this->name !== $this->init['name'] ? 1 : 0) + ($this->level !== $this->init['level'] ? 1 : 0);
    }

    private function editing(): bool
    {
        return $this->mode === 'sua' || $this->mode === 'moi';
    }

    /** Why no change can be made here now (support signed in for the member, platform staff), null when one can. */
    private function writeLock(): ?string
    {
        return $this->who()['lock'];
    }

    /**
     * The members holding the role and the pending invitations granting it.
     *
     * @return array{0: int, 1: int}
     */
    private function holders(string $name): array
    {
        return [app(MemberDirectory::class)->counts()['roles'][$name] ?? 0, app(InvitationDirectory::class)->roleCounts('pending')[$name] ?? 0];
    }

    /** The words under «Tên nhóm» for a refused name. */
    private function nameText(string $reason): string
    {
        $name = trim($this->name);

        return Cast::string(match ($reason) {
            'name_reserved' => __('admin.roles.err_name_reserved', ['name' => $name]),
            'name_taken' => __('admin.roles.err_name_taken', ['name' => $name]),
            default => __('admin.roles.err_name_invalid'),
        });
    }

    /** A refusal in plain words. */
    private function reasonText(string $reason): string
    {
        return Cast::string(match ($reason) {
            'level_invalid' => __('admin.roles.err_level_invalid'),
            'custom_roles_disabled' => __($this->mode === 'moi' ? 'admin.roles.err_disabled' : 'rbac.role_change.custom_roles_disabled'),
            'too_many' => __('rbac.role_change.too_many', ['max' => CustomRoleRules::MAX_CUSTOM_ROLES]),
            'trash_full' => __('rbac.role_change.trash_full', ['max' => CustomRoleRules::MAX_KEPT_CUSTOM_ROLES, 'days' => Role::TRASH_DAYS]),
            'name_invalid', 'name_reserved', 'name_taken' => $this->nameText($reason),
            default => in_array($reason, RoleChangeRejectedException::CUSTOM_ROLE_REASONS, true) ? __('rbac.role_change.'.$reason) : __('admin.roles.save_failed'),
        });
    }

    /** A permission's name in the matrix («Xem», «Viết bài»…). */
    private static function cellLabel(string $permission): string
    {
        return Cast::string(__('admin.roles.cl.'.$permission));
    }

    /**
     * In the catalog's order, each once.
     *
     * @param  iterable<string>  $permissions
     * @return list<string>
     */
    private static function ordered(iterable $permissions): array
    {
        $in = [];
        foreach ($permissions as $permission) {
            $in[$permission] = true;
        }

        return array_values(array_filter(PermissionRegistry::TENANT_PERMISSIONS, fn (string $p): bool => isset($in[$p])));
    }

    /**
     * «Việc tiêu biểu»: up to three telling permissions and how many more, or «Mọi việc trên website».
     *
     * @param  list<string>  $held
     */
    private static function typical(array $held): string
    {
        if (count($held) >= count(PermissionRegistry::TENANT_PERMISSIONS)) {
            return __('admin.members.does_all');
        }
        $top = array_slice(array_values(array_intersect(self::TYPICAL, $held)), 0, 3);
        if ($top === []) {
            return $held === [] ? __('admin.members.does_nothing') : trans_choice('admin.roles.jobs', count($held), ['n' => count($held)]);
        }
        $text = implode(' · ', array_map(fn (string $p): string => __('admin.members.perm.'.$p), $top));
        $rest = count($held) - count($top);

        return $rest > 0 ? $text.' · '.trans_choice('admin.roles.more_jobs', $rest, ['n' => $rest]) : $text;
    }

    /**
     * «Tạo từ: X» on a role of the site's own: the role it was copied from, also when that one is in
     * the trash now; nothing when it was never a copy or its source is gone for good. A source on
     * another website is never named.
     */
    private static function forkedFromName(Role $role): ?string
    {
        $source = $role->is_system ? null : $role->forkedFrom;
        if (! $source instanceof Role || (! $source->is_system && $source->site_id !== $role->site_id)) {
            return null;
        }

        return RoleLabel::for($source);
    }

    /** The one-line description of a system role, when there is one for its name. */
    private static function systemDesc(string $name): ?string
    {
        $key = 'admin.roles.sys_desc.'.$name;
        $text = Cast::string(__($key));

        return $text !== $key && $text !== '' ? $text : null;
    }

    /** «N thành viên» and, when some wait, « · M lời mời». */
    private static function membersText(int $members, int $invites): string
    {
        $text = trans_choice('admin.members.x_members', $members, ['n' => $members]);

        return $invites > 0 ? $text.' · '.trans_choice('admin.members.x_invites', $invites, ['n' => $invites]) : $text;
    }

    /** Lower case without accents, so «bien tap» finds «Biên tập». */
    private static function fold(string $text): string
    {
        return Str::lower(Str::ascii(trim($text)));
    }

    /** One of this website's live roles by name; null for any other name. */
    private function siteRole(string $name): ?Role
    {
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            return null;
        }

        return Role::query()->where('site_id', app(CurrentSite::class)->id())->where('name', $name)->with('permissions:id,name')->first();
    }

    private function say(string $message, string $tone = 'success', bool $reload = false): void
    {
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastReload = $reload;
        $this->toastUndo = false;
        $this->undoName = '';
        $this->undoKey = '';
        $this->toastSeq++;
    }

    /** May this person see the website's roles? Their real permission here (MembershipLock::permits — never the platform bypass for staff who hold a role). */
    private function open(): bool
    {
        return app(MembershipLock::class)->permits($this->actor(), app(CurrentSite::class)->id(), 'roles.manage');
    }

    /** Every public method: the member may manage roles. */
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
        $this->search = mb_substr($this->search, 0, self::SEARCH_MAX);
        $this->permSearch = mb_substr($this->permSearch, 0, self::SEARCH_MAX);
        $this->name = mb_substr($this->name, 0, self::NAME_MAX);
        $this->kind = in_array($this->kind, self::KINDS, true) ? $this->kind : 'all';
        $this->role = mb_strlen($this->role) <= self::NAME_MAX ? $this->role : '';
        $this->explainRole = mb_strlen($this->explainRole) <= self::NAME_MAX ? $this->explainRole : '';
        $this->explainFrom = preg_match('/^[a-z0-9-]{1,40}$/', $this->explainFrom) === 1 ? $this->explainFrom : '';
        $this->mode = in_array($this->mode, ['xem', 'sua', 'moi'], true) && ($this->role !== '' || $this->mode === 'moi') ? $this->mode : '';
        if ($this->mode === '') {
            $this->role = '';
        }
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
}
