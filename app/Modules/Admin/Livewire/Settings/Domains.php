<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Settings;

use App\Modules\Admin\Support\Domains\DomainGroup;
use App\Modules\Admin\Support\Domains\DomainGroups;
use App\Modules\Admin\Support\Domains\DomainOverview;
use App\Modules\Admin\Support\SiteDomains;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Notifications\Support\NotificationFeed;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Domains\DnsLookup;
use App\Modules\Tenancy\Domains\DomainCheck;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Tenancy\Domains\OwnershipProof;
use App\Modules\Tenancy\Domains\PointingMethod;
use App\Modules\Tenancy\Domains\PointingRecords;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Exceptions\DomainChangeRejectedException;
use App\Modules\Tenancy\Models\Site;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * A website's own domains (design.md §28, design screen 21 "Tên miền"): add a name, point it,
 * prove it is yours when another site holds it, turn HTTPS on, pick the main name and the spelling
 * that runs, ask support to point it — and see what went wrong with each name.
 *
 * Two places serve it: the site's admin (Settings › System › Domains) and the platform console's
 * page for one site (`/sites/{ulid}/domains`). Every change goes through SiteDomains, which checks
 * who may make it and leaves the trail.
 *
 *  - Reading needs `domains.view` on the site (`platform.sites.view` on the console). Staff signed
 *    in for support may look; they never change a name — the controls stay visible and say why.
 *  - `domains` has no row-level security: every name the browser sends is looked up among the
 *    site's own names, by name (never by id); a name of another site is "not found".
 *  - Livewire's update endpoint passes no route middleware, so each public method checks again.
 */
final class Domains extends Component
{
    /** The site, when the platform console opened the page; null on the site's own admin. */
    #[Locked]
    public ?string $siteUlid = null;

    public bool $addOpen = false;

    #[Locked]
    public int $addStep = 1;

    public string $addName = '';

    #[Locked]
    public bool $addWww = false;

    #[Locked]
    public bool $addWwwTouched = false;

    /** The name the add dialog created (its step 2). */
    #[Locked]
    public ?string $addedName = null;

    public bool $confirmOpen = false;

    /** primary · spelling · remove */
    #[Locked]
    public ?string $confirm = null;

    #[Locked]
    public ?string $confirmName = null;

    /** @var array<string, bool> the pointing panels opened or closed by hand, by name */
    #[Locked]
    public array $panels = [];

    /** @var array<string, string> how the owner points `@`, by name: move · ip */
    #[Locked]
    public array $apex = [];

    /** The server's address, looked up once when someone asks to point `@` by number. */
    #[Locked]
    public ?string $serverIp = null;

    /** @var array<string, bool> names whose HTTPS retry must wait (the 5-minute limit) */
    #[Locked]
    public array $retryLater = [];

    /** @var array<string, string> each name's state when the screen last drew it */
    #[Locked]
    public array $seen = [];

    #[Locked]
    public string $toast = '';

    /** success · error · info */
    #[Locked]
    public string $toastTone = 'success';

    #[Locked]
    public bool $toastUndo = false;

    /** The spelling to run again when the owner undoes a switch. */
    #[Locked]
    public ?string $undoHost = null;

    private ?Site $consoleSite = null;

    /** This request's read of the site's names; every change goes through domains(), which drops it. */
    private ?DomainOverview $overviewMemo = null;

    public function mount(?string $ulid = null): void
    {
        if ($ulid !== null) {
            $this->siteUlid = $ulid;
            $this->authorizeView();
            $this->site();
        }

        if (! $this->mayView()) {
            return;
        }

        $overview = $this->overview();

        foreach ($overview->groups as $group) {
            $this->seen[$group->name] = $group->state;

            if ($group->byNumber) {
                $this->apex[$group->name] = 'ip';
            }
        }

        if (in_array('ip', $this->apex, true)) {
            $this->serverIp = $this->lookUpServerIp();
        }
    }

    /** Every property the browser changes is a request too. */
    public function updating(): void
    {
        $this->authorizeView();
    }

    public function updatedAddName(): void
    {
        $this->resetErrorBag('addName');

        if (! $this->addWwwTouched) {
            $typed = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', trim($this->addName));
            $this->addWww = str_starts_with(mb_strtolower($typed), 'www.');
        }
    }

    // ── Adding a name ────────────────────────────────────────────────────────

    public function openAdd(): void
    {
        if (! $this->mayChange()) {
            return;
        }

        $overview = $this->overview();
        $limit = $this->limit();

        if ($overview->used >= $limit) {
            $this->flash($this->limitText($overview, $limit), 'error');

            return;
        }

        $this->resetErrorBag();
        $this->fill(['addName' => '', 'addWww' => false, 'addWwwTouched' => false, 'addStep' => 1, 'addedName' => null, 'addOpen' => true]);
    }

    public function toggleWww(): void
    {
        $this->authorizeView();
        $this->addWww = ! $this->addWww;
        $this->addWwwTouched = true;
    }

    /** Leaving the field: say what is wrong now, before the owner presses Continue. */
    public function checkName(): void
    {
        $this->authorizeView();
        $this->resetErrorBag('addName');

        if (trim($this->addName) === '') {
            return;
        }

        $problem = $this->nameProblem($this->addName);

        if ($problem !== null) {
            $this->addError('addName', $problem);
        }
    }

    public function submitAdd(): void
    {
        if (! $this->mayChange() || $this->addStep !== 1) {
            return;
        }

        $this->resetErrorBag('addName');

        if (trim($this->addName) === '') {
            $this->addError('addName', __('admin.domains_err_invalid_empty'));

            return;
        }

        try {
            $created = $this->domains()->attach($this->user(), $this->addName, PointingMethod::Alias, $this->addWww, $this->site());
        } catch (DomainChangeRejectedException $e) {
            $this->addError('addName', $this->attachMessage($e->getMessage(), $this->addName));

            return;
        }

        $name = DomainName::pairKey($created[0]->hostname);
        $this->addedName = $name;
        $this->addStep = 2;
        $this->seen[$name] = 'wait';
    }

    public function closeAdd(): void
    {
        $this->authorizeView();
        $this->fill(['addOpen' => false, 'addStep' => 1, 'addName' => '', 'addedName' => null]);
        $this->resetErrorBag();
    }

    /** "Đã trỏ xong — Kiểm tra": close the dialog, then check the new name. */
    public function checkFromAdd(): void
    {
        $name = $this->addedName;
        $this->closeAdd();

        if ($name !== null) {
            $this->check($name);
        }
    }

    // ── Changes to one name ─────────────────────────────────────────────────

    public function check(string $name): void
    {
        $this->withGroup($name, function (DomainGroup $group): void {
            if (in_array($group->state, ['wait', 'claim'], true)
                && ! RateLimiter::attempt('domain-check:'.$this->siteId().':'.$group->name, 1, fn (): bool => true, 60)) {
                $this->flash(__('admin.domains_toast_throttle'), 'error');

                return;
            }

            $result = $this->domains()->check($this->user(), $group->runs, $this->site());
            $this->checked($group, $result);
        });
    }

    public function enableHttps(string $name): void
    {
        $this->withGroup($name, function (DomainGroup $group): void {
            try {
                $this->domains()->enableHttps($this->user(), $group->runs, $this->site());
            } catch (DomainChangeRejectedException $e) {
                if ($e->getMessage() !== 'too_many_attempts') {
                    throw $e;
                }

                $this->retryLater[$group->name] = true;

                return;
            }

            unset($this->retryLater[$group->name]);
            $this->seen[$group->name] = 'issuing';
        });
    }

    public function askPrimary(string $name): void
    {
        $this->withGroup($name, function (DomainGroup $group): void {
            if ($group->state !== 'active') {
                $this->flash(__('admin.domains_menu_primary_need_https'), 'error');

                return;
            }

            $this->ask('primary', $group);
        });
    }

    public function askRemove(string $name): void
    {
        $this->withGroup($name, fn (DomainGroup $group) => $this->ask('remove', $group));
    }

    /** Run the other spelling. The main name asks first; any other name switches at once, with an undo. */
    public function switchSpelling(string $name): void
    {
        $this->withGroup($name, function (DomainGroup $group): void {
            if ($group->alt === null) {
                $this->notFound();

                return;
            }

            if ($group->primary) {
                $this->ask('spelling', $group);

                return;
            }

            $this->domains()->chooseRunningSpelling($this->user(), $group->alt, $this->site());
            $this->flash(__('admin.domains_toast_www_switched', ['host' => DomainName::display($group->alt)]));
            $this->toastUndo = true;
            $this->undoHost = $group->runs;
        });
    }

    public function undo(): void
    {
        $host = $this->undoHost;

        if ($host === null) {
            $this->authorizeView();

            return;
        }

        $this->withGroup(DomainName::pairKey($host), function () use ($host): void {
            $this->domains()->chooseRunningSpelling($this->user(), $host, $this->site());
            $this->dismissToast();
        });
    }

    public function confirmOk(): void
    {
        $kind = $this->confirm;
        $name = $this->confirmName;

        if ($kind === null || $name === null) {
            $this->authorizeView();

            return;
        }

        $this->withGroup($name, function (DomainGroup $group) use ($kind): void {
            $this->cancelConfirm();
            $actor = $this->user();
            $site = $this->site();

            if ($kind === 'primary') {
                $this->domains()->makePrimary($actor, $group->runs, $site);
                $this->flash(__('admin.domains_toast_primary_set', ['host' => DomainName::display($group->runs)]));
            } elseif ($kind === 'spelling' && $group->alt !== null) {
                $this->domains()->chooseRunningSpelling($actor, $group->alt, $site);
                $this->flash(__('admin.domains_toast_spelling_set', ['host' => DomainName::display($group->alt)]));
            } elseif ($kind === 'remove') {
                $this->domains()->remove($actor, $group->runs, $site);
                unset($this->seen[$group->name], $this->panels[$group->name], $this->apex[$group->name]);
                $message = __('admin.domains_toast_removed', ['name' => DomainName::display($group->name)]);

                if ($group->primary) {
                    $message .= ' '.__('admin.domains_toast_removed_primary', ['host' => DomainName::display((string) $this->overview()->primary)]);
                }

                $this->flash($message);
            }
        });
    }

    public function cancelConfirm(): void
    {
        $this->authorizeView();
        $this->fill(['confirmOpen' => false, 'confirm' => null, 'confirmName' => null]);
    }

    public function requestHelp(string $name): void
    {
        $this->withGroup($name, function (DomainGroup $group): void {
            try {
                $this->domains()->requestHelp($this->user(), $group->runs, $this->site());
            } catch (DomainChangeRejectedException $e) {
                if ($e->getMessage() !== 'too_many_attempts') {
                    throw $e;
                }

                $this->flash(__('admin.domains_toast_help_wait'), 'error');

                return;
            }

            $this->flash(__('admin.domains_toast_help_sent', ['name' => DomainName::display($group->name)]));
        });
    }

    // ── What only changes the screen ────────────────────────────────────────

    public function togglePanel(string $name): void
    {
        $this->authorizeView();
        $group = $this->find($name);

        if ($group === null) {
            $this->notFound();

            return;
        }

        $this->panels[$group->name] = ! $this->panelOpen($group);
    }

    /** How to point `@` when the registrar cannot point it at a name: move · ip. */
    public function pickApex(string $name, string $option): void
    {
        $this->authorizeView();
        $group = $this->find($name);

        if ($group === null || ! in_array($option, ['move', 'ip'], true)) {
            $this->notFound();

            return;
        }

        if ($option === 'move') {
            $this->apex[$group->name] = 'move';
            $this->flash(__('admin.domains_point_moved_toast'), 'info');

            return;
        }

        $this->serverIp ??= $this->lookUpServerIp();

        if ($this->serverIp === null) {
            $this->flash(__('admin.domains_toast_ip_unknown'), 'error');

            return;
        }

        $this->apex[$group->name] = 'ip';
    }

    /** The browser copied a value (target · txt_name · txt_value) of a name, or could not. */
    public function copied(string $what, string $name, bool $ok = true): void
    {
        $this->authorizeView();
        $group = $this->find($name);
        $labels = ['target' => 'admin.domains_copied_target', 'txt_name' => 'admin.domains_copied_txt_name', 'txt_value' => 'admin.domains_copied_txt_value'];

        if ($group === null || ! isset($labels[$what])) {
            $this->notFound();

            return;
        }

        if ($ok) {
            $this->flash(__($labels[$what]), 'info');

            return;
        }

        $proof = $this->proof($group);
        $text = match ($what) {
            'txt_name' => $proof['name'],
            'txt_value' => $proof['value'],
            default => PointingRecords::target(),
        };

        $this->flash(__('admin.domains_copy_failed', ['text' => $text]), 'error');
    }

    /** "Đã hiểu" on the notice that another site took a name. */
    public function acknowledgeTaken(string $ulid): void
    {
        $this->authorizeView();

        if ($this->siteUlid === null) {
            app(NotificationFeed::class)->markRead($this->user(), $ulid);
        }
    }

    public function dismissToast(): void
    {
        $this->authorizeView();
        $this->fill(['toast' => '', 'toastTone' => 'success', 'toastUndo' => false, 'undoHost' => null]);
    }

    public function render(): View
    {
        $view = view('admin::livewire.settings.domains', $this->mayView() ? $this->screen() : ['allowed' => false, 'role' => $this->roleLabel()]);
        $view->title(__('admin.domains_title'));

        if ($this->siteUlid !== null) {
            $view->layout('admin::layouts.platform');
        } else {
            $view->layout('admin::layouts.app');
        }

        return $view;
    }

    /**
     * What the view draws.
     *
     * @return array<string, mixed>
     */
    private function screen(): array
    {
        try {
            $overview = $this->overview();
            $limit = $this->limit();
        } catch (Throwable $e) {
            report($e);

            return ['allowed' => true, 'failed' => true];
        }

        $this->noticeChanges($overview);
        [$taken, $recovered] = $this->notices();
        $manage = $this->mayManage();
        $site = $this->site();

        return [
            'allowed' => true,
            'failed' => false,
            'overview' => $overview,
            'limit' => $limit,
            'manage' => $manage,
            'locked' => $this->supportActive(),
            'console' => $site?->name,
            'taken' => $taken,
            'recovered' => $recovered,
            'platformHost' => $this->platformHost($overview),
            'limitText' => $this->limitText($overview, $limit),
            'open' => array_combine(
                array_map(fn (DomainGroup $g): string => $g->name, $overview->groups),
                array_map(fn (DomainGroup $g): bool => $this->panelOpen($g), $overview->groups),
            ),
            'records' => fn (DomainGroup $g): array => PointingRecords::for($g->runs, $g->alt, ($this->apex[$g->name] ?? null) === 'ip' ? $this->serverIp : null),
            'proof' => fn (DomainGroup $g): array => $this->proof($g),
            'added' => $this->addStep === 2 && $this->addedName !== null ? $overview->find($this->addedName) : null,
            'confirmGroup' => $this->confirmName !== null ? $overview->find($this->confirmName) : null,
            'addPreview' => $this->addPreview(),
        ];
    }

    // ── Results ──────────────────────────────────────────────────────────────

    private function checked(DomainGroup $before, DomainCheck $result): void
    {
        $name = DomainName::display($before->name);

        if ($result->status === 'needs_proof') {
            $this->flash(__('admin.domains_msg_claim'), 'error');

            return;
        }

        if (! $result->verified()) {
            $this->flash(__('admin.domains_toast_not_pointed', ['name' => $name]), 'error');

            return;
        }

        $twinVerified = $result->twin?->verified() === true;

        $message = match (true) {
            $before->state === 'claim' => __('admin.domains_toast_claim_ok', ['name' => $name]),
            in_array($before->state, ['failing', 'expiring', 'lost'], true) => __('admin.domains_toast_recovered', ['name' => $name]),
            $before->state === 'active' && $before->alt !== null && $twinVerified => __('admin.domains_toast_alt_pointed', ['alt' => DomainName::display($before->alt), 'run' => DomainName::display($before->runs)]),
            $before->state === 'active' && $before->alt !== null => __('admin.domains_toast_not_pointed', ['name' => DomainName::display($before->alt)]),
            default => __('admin.domains_toast_verified', ['name' => $name]),
        };

        $this->flash($message, $before->state === 'active' && ! $twinVerified ? 'error' : 'success');
    }

    /** A certificate that was on its way is on now: say so once. */
    private function noticeChanges(DomainOverview $overview): void
    {
        foreach ($overview->groups as $group) {
            if (($this->seen[$group->name] ?? null) === 'issuing' && $group->state === 'active') {
                $this->flash(__('admin.domains_toast_https_on', ['name' => DomainName::display($group->name)]));
            }

            $this->seen[$group->name] = $group->state;
        }
    }

    /**
     * The newest unread "another site took your name" notice, and the names that work again —
     * both from the reader's own bell, on the site's admin only.
     *
     * @return array{0: array{ulid: string, name: string}|null, 1: array<string, true>}
     */
    private function notices(): array
    {
        if ($this->siteUlid !== null) {
            return [null, []];
        }

        $rows = SiteNotification::query()
            ->where('user_id', $this->user()->getKey())->whereNull('read_at')
            ->whereIn('kind', ['security.domain_taken_over', 'system.domain_problem'])
            ->orderByDesc('id')->limit(20)
            ->get(['ulid', 'kind', 'data']);

        $taken = null;
        $recovered = [];

        foreach ($rows as $row) {
            $data = $row->data;
            $first = trim(explode(',', Cast::string($data['domains'] ?? ''))[0]);

            if ($first === '') {
                continue;
            }

            if ($row->kind === 'security.domain_taken_over') {
                $taken ??= ['ulid' => Cast::string($row->ulid), 'name' => DomainName::display(DomainName::pairKey($first))];
            } elseif (($data['sentence'] ?? null) === 'recovered') {
                $recovered[DomainName::pairKey($first)] = true;
            }
        }

        return [$taken, $recovered];
    }

    // ── Who may do what ─────────────────────────────────────────────────────

    private function mayView(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($this->siteUlid !== null) {
            $platform = app(PlatformAccess::class);

            return $platform->isSuperAdmin($user) || $platform->can($user, 'platform.sites.view');
        }

        return Gate::forUser($user)->allows('domains.view');
    }

    private function mayManage(): bool
    {
        $user = $this->user();

        if ($this->siteUlid !== null) {
            $platform = app(PlatformAccess::class);

            return $platform->isSuperAdmin($user) || $platform->can($user, 'platform.sites.manage');
        }

        return Gate::forUser($user)->allows('domains.manage');
    }

    private function authorizeView(): void
    {
        abort_unless($this->mayView(), 403);
    }

    /**
     * A change may be made: the reader may view, is not support staff signed in as a member (who
     * hear why instead), and may manage the site's domains.
     */
    private function mayChange(): bool
    {
        $this->authorizeView();

        if ($this->supportActive()) {
            $this->flash(__('admin.domains_locked'), 'error');

            return false;
        }

        abort_unless($this->mayManage(), 403);

        return true;
    }

    private function supportActive(): bool
    {
        return $this->siteUlid === null && app(SupportSession::class)->active();
    }

    /**
     * Run a change on one of the site's own names; anything else is "not found".
     *
     * @param  Closure(DomainGroup): void  $change
     */
    private function withGroup(string $name, Closure $change): void
    {
        if (! $this->mayChange()) {
            return;
        }

        $group = $this->find($name);

        if ($group === null) {
            $this->notFound();

            return;
        }

        try {
            $change($group);
        } catch (DomainChangeRejectedException $e) {
            $this->flash($this->rejectionMessage($e->getMessage()), 'error');
        }
    }

    private function ask(string $kind, DomainGroup $group): void
    {
        $this->fill(['confirm' => $kind, 'confirmName' => $group->name, 'confirmOpen' => true]);
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    private function overview(): DomainOverview
    {
        return $this->overviewMemo ??= $this->inSite(fn (): DomainOverview => app(DomainGroups::class)->forSite($this->siteId()));
    }

    private function find(string $name): ?DomainGroup
    {
        try {
            $key = DomainName::pairKey(DomainName::parse($name));
        } catch (DomainChangeRejectedException) {
            return null;
        }

        return $this->overview()->find($key);
    }

    private function limit(): int
    {
        return $this->inSite(fn (): int => (int) app(Entitlement::class)->featureValue('custom_domains.max'));
    }

    /**
     * The TXT record that proves a contested name is this site's.
     *
     * @return array{name: string, value: string}
     */
    private function proof(DomainGroup $group): array
    {
        $proof = app(OwnershipProof::class);

        return ['name' => $proof->recordName($group->runs), 'value' => $proof->recordValue($this->siteId(), $group->runs)];
    }

    private function panelOpen(DomainGroup $group): bool
    {
        return ! $group->platform && ($this->panels[$group->name] ?? in_array($group->state, ['wait', 'failing', 'expiring', 'lost'], true));
    }

    /** The site the console opened, read once per request; null on the site's own admin. */
    private function site(): ?Site
    {
        if ($this->siteUlid === null) {
            return null;
        }

        return $this->consoleSite ??= Site::query()->where('ulid', $this->siteUlid)->firstOrFail(['id', 'ulid', 'name']);
    }

    private function siteId(): int
    {
        return $this->siteUlid !== null ? Cast::int($this->site()?->getKey()) : app(CurrentSite::class)->id();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    private function inSite(Closure $read): mixed
    {
        return $this->siteUlid !== null ? app(Tenancy::class)->enter($this->siteId(), $read) : $read();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function domains(): SiteDomains
    {
        $this->overviewMemo = null;

        return app(SiteDomains::class);
    }

    private function lookUpServerIp(): ?string
    {
        foreach (app(DnsLookup::class)->addresses(PointingRecords::target()) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $address;
            }
        }

        return null;
    }

    private function platformHost(DomainOverview $overview): string
    {
        foreach ($overview->groups as $group) {
            if ($group->platform) {
                return $group->name;
            }
        }

        return '';
    }

    /** The reader's role on the site, as the no-permission card names it. */
    private function roleLabel(): string
    {
        $user = auth()->user();

        if (! $user instanceof User || $this->siteUlid !== null) {
            return '';
        }

        $name = Cast::string($user->roles->sortByDesc('level')->first()?->getAttribute('name'));
        $key = 'rbac.role.'.$name;

        return $name === '' ? '' : (__($key) === $key ? $name : __($key));
    }

    // ── Words ────────────────────────────────────────────────────────────────

    private function flash(string $message, string $tone = 'success'): void
    {
        $this->fill(['toast' => $message, 'toastTone' => $tone, 'toastUndo' => false, 'undoHost' => null]);
    }

    private function notFound(): void
    {
        $this->cancelConfirm();
        $this->flash(__('admin.domains_err_not_found'), 'error');
    }

    private function rejectionMessage(string $code): string
    {
        return match ($code) {
            'https_not_on', 'not_verified' => __('admin.domains_menu_primary_need_https'),
            'too_many_attempts' => __('admin.domains_https_err_ratelimit'),
            'platform_subdomain' => __('admin.domains_msg_platform'),
            default => __('admin.domains_err_not_found'),
        };
    }

    /** Why a typed name cannot be added, before trying: null when it may be. */
    private function nameProblem(string $typed): ?string
    {
        try {
            $host = DomainName::parse($typed);
        } catch (DomainChangeRejectedException) {
            return __('admin.domains_err_invalid_format');
        }

        if (DomainName::isReserved($host)) {
            return $this->attachMessage('reserved', $typed);
        }

        $overview = $this->overview();

        if ($overview->find(DomainName::pairKey($host)) !== null) {
            return Cast::string(__('admin.domains_err_already_attached'));
        }

        $limit = $this->limit();

        return $overview->used >= $limit ? Cast::string(__('admin.domains_err_limit_reached', ['l' => $limit])) : null;
    }

    private function attachMessage(string $code, string $typed): string
    {
        if ($code === 'reserved') {
            try {
                $host = DomainName::parse($typed);
            } catch (DomainChangeRejectedException) {
                $host = '';
            }

            foreach (Cast::strings(config('tenancy.base_domains')) as $base) {
                if ($host !== $base && str_ends_with($host, '.'.$base)) {
                    return __('admin.domains_err_platform_subdomain', ['root' => $base, 'host' => $this->platformHost($this->overview())]);
                }
            }

            return __('admin.domains_err_reserved');
        }

        return match ($code) {
            'already_attached' => __('admin.domains_err_already_attached'),
            'limit_reached' => __('admin.domains_err_limit_reached', ['l' => $this->limit()]),
            default => __('admin.domains_err_invalid_format'),
        };
    }

    private function limitText(DomainOverview $overview, int $limit): string
    {
        if ($limit === 0) {
            return __('admin.domains_limit_none');
        }

        $text = __('admin.domains_limit_full', ['l' => $limit, 'u' => $overview->used]);

        if ($overview->lost > 0) {
            $text .= ' '.__('admin.domains_limit_lost');
        }

        return $text.' '.__('admin.domains_limit_contact');
    }

    /**
     * The add dialog's preview: where the website will run, and whether typing `www.` turned the
     * switch on.
     *
     * @return array{name: string, runs: string, alt: string, typedWww: bool}
     */
    private function addPreview(): array
    {
        $typed = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', trim(mb_strtolower($this->addName)));
        $typed = (string) preg_replace('#[/?\#:\s].*$#', '', $typed);
        $typed = rtrim($typed, '.');
        $typedWww = str_starts_with($typed, 'www.');
        $name = $typedWww ? substr($typed, 4) : $typed;

        return [
            'name' => $name,
            'runs' => $this->addWww ? 'www.'.$name : $name,
            'alt' => $this->addWww ? $name : 'www.'.$name,
            'typedWww' => $typedWww && ! $this->addWwwTouched,
        ];
    }
}
