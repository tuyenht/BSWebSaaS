<?php

namespace App\Modules\Admin\Livewire\Roles;

use App\Modules\Admin\Support\Members\InvitationDirectory;
use App\Modules\Admin\Support\Members\MemberDirectory;
use App\Modules\Admin\Support\Members\RoleAbilities;
use App\Modules\Admin\Support\UpgradeInvitation;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\MembershipLock;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The website's roles (design board «Nhóm quyền»): every system role and every role of the site's
 * own, highest first, each with where it stands, how many members and pending invitations hold it
 * and a few of the things it may do; a search that ignores accents, the «Tất cả · Mặc định · Tự tạo»
 * chips, the «Nhóm này làm được gì?» panel, and one role's permissions read-only
 * (`?nhom=<name>&che=xem`).
 *
 * This screen changes nothing: creating, changing, copying and deleting a role come with the editor.
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

    /**
     * The permissions that say most about a role, the most telling first: the «Việc tiêu biểu»
     * column names the first three a role holds.
     */
    private const array TYPICAL = ['settings.manage', 'users.manage', 'entries.publish', 'entries.approve', 'entries.edit.any', 'catalog.manage',
        'entries.review', 'entries.create', 'entries.edit.own', 'media.upload', 'forms.manage', 'entries.submit', 'entries.view', 'media.view',
        'forms.view', 'catalog.view'];

    /** The role opened read-only (its name), '' on the list. */
    #[Url(as: 'nhom', except: '')]
    public string $role = '';

    /** '' the list · 'xem' one role read-only. 'sua' opens the same read-only view for now; 'moi' goes to the list. */
    #[Url(as: 'che', except: '')]
    public string $mode = '';

    public string $search = '';

    public string $kind = 'all';

    /** «Tìm việc» of the read-only view. */
    public string $permSearch = '';

    /** The role «Nhóm này làm được gì?» is about (its name), '' while the panel is closed. */
    public string $explainRole = '';

    /** The id of the button that opened the panel, so the focus goes back to it on closing. */
    public string $explainFrom = '';

    public ?string $toast = null;

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    public bool $toastReload = false;

    public function mount(): void
    {
        if (! $this->open()) {
            return;
        }
        $this->clean();
        // a link naming a role this website does not have (another site's, a deleted one) opens the list
        if ($this->role !== '' && ! $this->siteRole($this->role) instanceof Role) {
            $this->role = '';
            $this->mode = '';
        }
    }

    public function updated(string $property): void
    {
        $this->ensureOpen();
        $this->clean();
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

    /** The name, «Xem» or a deep link: the role's permissions read-only. Only this website's roles. */
    public function openRole(string $name): void
    {
        $this->ensureOpen();
        $found = $this->siteRole($name);
        if (! $found instanceof Role) {
            $this->say(__('admin.members.x_gone'), 'error', reload: true);

            return;
        }
        $this->role = $found->name;
        $this->mode = 'xem';
        $this->permSearch = '';
    }

    /** «Quay lại Nhóm quyền». */
    public function backToList(): void
    {
        $this->ensureOpen();
        $this->role = '';
        $this->mode = '';
        $this->permSearch = '';
    }

    public function clearPermSearch(): void
    {
        $this->ensureOpen();
        $this->permSearch = '';
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
        $this->explainFrom = preg_match('/^[a-z0-9-]{1,40}$/', $from) === 1 ? $from : '';
    }

    public function closeExplain(): void
    {
        $this->ensureOpen();
        $this->explainRole = '';
    }

    public function dismissToast(int $seq = 0): void
    {
        $this->ensureOpen();
        if ($seq === 0 || $seq === $this->toastSeq) {
            $this->toast = null;
            $this->toastReload = false;
        }
    }

    /** «Tải lại» on a message about a role that is gone: the list read afresh, the panel let go. */
    public function reloadList(): void
    {
        $this->ensureOpen();
        $this->explainRole = '';
        $this->toast = null;
        $this->toastReload = false;
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
        $catalogOn = in_array('catalog', app(Entitlement::class)->entitledModuleKeys(), true);
        $ceiling = app(RoleHierarchyGuard::class)->levelCeiling($actor);
        $support = app(SupportSession::class)->active();
        $lockedWhy = app(MembershipLock::class)->reason($actor, $siteId);

        $rows = $roles->map(fn (Role $r): array => $this->rowView($r, $roles, $members, $invites))->all();
        $custom = $roles->where('is_system', false)->count();
        $needle = self::fold($this->search);
        $shown = array_values(array_filter($rows, fn (array $r): bool => ($this->kind === 'all' || ($this->kind === 'sys') === $r['system'])
            && ($needle === '' || str_contains(self::fold($r['name']), $needle))));

        $viewed = $this->mode !== '' && $this->role !== '' ? $roles->firstWhere('name', $this->role) : null;
        if (! $failed && ! $viewed instanceof Role) {
            // a role gone meanwhile, or a name the browser made up: the list
            $this->role = '';
            $this->mode = '';
        }
        $explained = $this->explainRole !== '' ? $roles->firstWhere('name', $this->explainRole) : null;

        return $this->page('admin::livewire.roles.index', $title, [
            'failed' => $failed,
            'support' => $support,
            'lockedWhy' => $lockedWhy,
            'planOn' => app(Entitlement::class)->featureEnabled('rbac.custom_roles'),
            'upsell' => app(UpgradeInvitation::class)->isOn(),
            'total' => count($rows),
            'custom' => $custom,
            'rows' => $shown,
            'filtering' => $needle !== '' || $this->kind !== 'all',
            'viewed' => $viewed instanceof Role ? $this->roleView($viewed, $roles, $members, $invites, $ceiling, $support, $catalogOn) : null,
            'explain' => $explained instanceof Role ? $this->explainView($explained, $roles, $members, $invites, $catalogOn) : null,
        ]);
    }

    /**
     * One row of the list.
     *
     * @param  Collection<int, Role>  $roles
     * @param  array<string, int>  $members
     * @param  array<string, int>  $invites
     * @return array{key: string, name: string, system: bool, from: string|null, desc: string|null, pos: string, members: int, invites: int, membersText: string, typical: string}
     */
    private function rowView(Role $role, Collection $roles, array $members, array $invites): array
    {
        $m = $members[$role->name] ?? 0;
        $i = $invites[$role->name] ?? 0;

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
        ];
    }

    /**
     * The read-only view of one role: its areas, each with every permission of the catalog in it,
     * held or not, narrowed by «Tìm việc».
     *
     * @param  Collection<int, Role>  $roles
     * @param  array<string, int>  $members
     * @param  array<string, int>  $invites
     * @return array{key: string, name: string, desc: string, note: string|null, areas: list<array{key: string, name: string, held: int, of: int, off: string, cells: list<array{key: string, label: string, desc: string, on: bool}>}>}
     */
    private function roleView(Role $role, Collection $roles, array $members, array $invites, int $ceiling, bool $support, bool $catalogOn): array
    {
        $held = array_flip($this->held($role));
        $needle = self::fold($this->permSearch);
        $areas = [];
        foreach (RoleAbilities::areas() as $area => $permissions) {
            $areaName = Cast::string(__('admin.members.xa.'.$area));
            $cells = [];
            foreach ($permissions as $permission) {
                $label = Cast::string(__('admin.roles.cl.'.$permission));
                $desc = Cast::string(__('admin.roles.cd.'.$permission));
                if ($needle !== '' && ! str_contains(self::fold($areaName.' '.$label.' '.$desc), $needle)) {
                    continue;
                }
                $cells[] = ['key' => $permission, 'label' => $label, 'desc' => $desc, 'on' => isset($held[$permission])];
            }
            if ($cells === []) {
                continue;
            }
            $off = $area === 'catalog' && ! $catalogOn
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
            $role->level >= $ceiling => Cast::string(__('admin.roles.ro_level')),
            default => null,
        };

        return [
            'key' => $role->name,
            'name' => RoleLabel::for($role),
            'desc' => RoleAbilities::position($role, $roles).' · '.self::membersText($members[$role->name] ?? 0, $invites[$role->name] ?? 0),
            'note' => $note,
            'areas' => $areas,
        ];
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @param  array<string, int>  $members
     * @param  array<string, int>  $invites
     * @return array{name: string, meta: string, areas: list<array{key: string, name: string, off: string, items: list<array{ok: bool, text: string}>}>}
     */
    private function explainView(Role $role, Collection $roles, array $members, array $invites, bool $catalogOn): array
    {
        $meta = [
            $role->is_system ? __('admin.members.x_sys') : __('admin.members.x_custom'),
            RoleAbilities::position($role, $roles),
            self::membersText($members[$role->name] ?? 0, $invites[$role->name] ?? 0),
        ];

        return [
            'name' => RoleLabel::for($role),
            'meta' => implode(' · ', $meta),
            'areas' => RoleAbilities::explain($this->held($role), $catalogOn),
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

        return Role::query()->where('site_id', app(CurrentSite::class)->id())->where('name', $name)->first();
    }

    private function say(string $message, string $tone = 'success', bool $reload = false): void
    {
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastReload = $reload;
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
        $this->kind = in_array($this->kind, self::KINDS, true) ? $this->kind : 'all';
        $this->role = mb_strlen($this->role) <= self::NAME_MAX ? $this->role : '';
        $this->explainRole = mb_strlen($this->explainRole) <= self::NAME_MAX ? $this->explainRole : '';
        $this->explainFrom = preg_match('/^[a-z0-9-]{1,40}$/', $this->explainFrom) === 1 ? $this->explainFrom : '';
        // 'sua' (edit) shows the same read-only view until the editor exists; 'moi' (new) has nothing to show yet
        $this->mode = in_array($this->mode, ['xem', 'sua'], true) && $this->role !== '' ? 'xem' : '';
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
