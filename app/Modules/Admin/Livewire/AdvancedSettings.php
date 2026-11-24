<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\TracksSavedState;
use App\Modules\Admin\Support\AdminAddressLimit;
use App\Modules\Admin\Support\Locales;
use App\Modules\Admin\Support\Settings\SettingsChangeRejected;
use App\Modules\Admin\Support\Settings\SettingsStamp;
use App\Modules\Admin\Support\Settings\SettingsWrite;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\SiteOwner;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\EdgeCachePause;
use App\Modules\Storefront\Support\SiteEmbedCode;
use App\Modules\Storefront\Support\SiteMaintenance;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\MessageBag;
use Illuminate\View\View;
use Livewire\Attributes\Authorize;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Bảo mật & Nâng cao": the switches that change the whole website for everyone, which only its
 * owner may change. Every holder of settings.manage may open it and reads it «Chỉ xem»; the owner
 * (or a platform operator) changes it. It holds the maintenance mode (SiteMaintenance): one switch,
 * the owner's words per website language, and an optional time the website is expected back, set in
 * the website's time zone and stored in UTC. The website never opens by itself at that time; it only
 * tells visitors when to come back.
 *
 * And the extra embed code (SiteEmbedCode): one switch and four boxes of code pasted from a provider,
 * printed raw in the website's pages for visitors without a session. The first save that turns code
 * on asks first (a dialog with «Tôi hiểu…» ticked, then «Bật và lưu»); that acknowledgement is kept
 * with the code, so it is never asked again. Switched off, the code is kept but not printed. Each box
 * holds at most SiteEmbedCode::MAX_BYTES; the audit records the sizes and short hashes of what was
 * saved, never the code itself.
 *
 * And where the admin may be opened from (AdminAddressLimit): one switch and a list of network
 * addresses (one address or a range) with a note each. Turning it on with an empty list starts it
 * with the address in use; a row is checked when it is left and every row on save; a list that is on
 * must hold at least one address and must cover the address the owner saves from, so no save can
 * lock him out (a member in a support session is let in by support itself and is not checked).
 * Switched off, the valid addresses are kept for later. Saving it purges nothing on the edge: the
 * admin is never cached there.
 *
 * The page edits a draft and saves it in one step (the shared save bar). The reopen time is checked
 * when its fields are left and again on save: both a day and an hour, and not already past (a time
 * kept from an earlier save may have passed and still be saved unchanged). Saving writes the
 * site_settings row of each part that changed, which purges the website's pages from the edge
 * (EdgeCachePurges), so visitors see the change at once.
 */
#[Layout('admin::layouts.app')]
final class AdvancedSettings extends Component
{
    use TracksSavedState;

    /** @var array<string, string> locale → the owner's words, for each website language */
    public array $messages = [];

    /** The day the website is expected back (Y-m-d, the website's time zone), or empty. */
    public string $reopenDate = '';

    /** The hour the website is expected back (H:i, the website's time zone), or empty. */
    public string $reopenTime = '';

    /** The switch as drafted; changed only through toggleMaintenance(). */
    #[Locked]
    public bool $maintenanceOn = false;

    /** The switch as saved: the chip and the line under the title follow it. */
    #[Locked]
    public bool $savedOn = false;

    /** The reopen time as saved ("Y-m-d H:i" local), so a passed one may be kept unchanged. */
    #[Locked]
    public string $savedReopen = '';

    /** @var array<string, string> place (SiteEmbedCode::SLOTS) → the code drafted for it */
    public array $code = ['head' => '', 'body_top' => '', 'body_end' => '', 'foot' => ''];

    /** The embed-code switch as drafted; changed only through toggleCode(). */
    #[Locked]
    public bool $codeOn = false;

    /** The embed code as saved runs on the website (on, with some code): the chip follows it. */
    #[Locked]
    public bool $codeRunning = false;

    /** The owner has already confirmed running his own code once: the dialog is not asked again. */
    #[Locked]
    public bool $codeAcknowledged = false;

    /** The «Bật mã nhúng thêm cho website?» dialog is open (entangled: closing it sets it back). */
    public bool $askCode = false;

    /** Counts the dialog's openings, so each one starts with «Tôi hiểu…» unticked. */
    #[Locked]
    public int $askSeq = 0;

    /** The server opened the question this time: only then does save(true) count as the confirmation. */
    #[Locked]
    public bool $codeAsked = false;

    /** @var list<array{id: int, address: string, note: string}> the drafted addresses, each with a stable id */
    public array $ips = [];

    /** The address-limit switch as drafted; changed only through toggleIp(). */
    #[Locked]
    public bool $ipOn = false;

    /** The address limit as saved: its chip follows it. */
    #[Locked]
    public bool $ipSavedOn = false;

    /** The last row id handed out. */
    #[Locked]
    public int $ipSeq = 0;

    /** @var list<int> the rows left once (their faults show from then on) */
    #[Locked]
    public array $ipTouched = [];

    /** A save was tried: every row's fault, the empty list and the lock-out band show. */
    #[Locked]
    public bool $ipTried = false;

    /** Set once at mount: the member may look but not change («Chỉ xem»). */
    #[Locked]
    public bool $readOnly = true;

    #[Locked]
    public string $toast = '';

    #[Locked]
    public string $toastTone = 'success';

    #[Locked]
    public int $toastSeq = 0;

    /** @var Collection<int, SiteSetting>|null the rows loaded in this request, for the stamp (not sent to the browser) */
    private ?Collection $stampRows = null;

    public function boot(): void
    {
        // Defense-in-depth: the livewire/update endpoint bypasses route middleware, so
        // re-check the site-settings permission on every request (mount + every action).
        abort_unless(Gate::allows('settings.manage'), 403);
    }

    public function mount(): void
    {
        $this->readOnly = ! self::mayChange();
        $this->load();
    }

    #[Authorize('settings.manage')]
    public function toggleMaintenance(): void
    {
        abort_unless(self::mayChange(), 403);

        $this->maintenanceOn = ! $this->maintenanceOn;
    }

    #[Authorize('settings.manage')]
    public function toggleCode(): void
    {
        abort_unless(self::mayChange(), 403);

        $this->codeOn = ! $this->codeOn;
    }

    #[Authorize('settings.manage')]
    public function toggleIp(): void
    {
        abort_unless(self::mayChange(), 403);

        $this->ipOn = ! $this->ipOn;
        // turned on with nothing listed: start with the address in use (never a support desk's)
        $me = self::myEntry();
        if ($this->ipOn && $this->ips === [] && $me !== null && ! self::inSupport()) {
            $this->ips[] = ['id' => ++$this->ipSeq, 'address' => $me, 'note' => Cast::string(__('admin.adv.ip.me_note'))];
        }
        $this->checkIps();
    }

    /** «Thêm địa chỉ»: an empty row, focused by the page. */
    #[Authorize('settings.manage')]
    public function addIpRow(): void
    {
        abort_unless(self::mayChange(), 403);

        if (! $this->roomForIp()) {
            return;
        }
        $this->ips[] = ['id' => ++$this->ipSeq, 'address' => '', 'note' => ''];
        $this->checkIps();
        $this->dispatch('adv-ip-focus', id: $this->ipSeq);
    }

    /** «Thêm địa chỉ này» / «Thêm địa chỉ đang dùng»: the address in use, unless a row covers it. */
    #[Authorize('settings.manage')]
    public function addMyIp(): void
    {
        abort_unless(self::mayChange(), 403);

        $me = self::myEntry();
        if ($me === null || AdminAddressLimit::covers($this->validIps(), self::myAddress()) || ! $this->roomForIp()) {
            $this->checkIps();

            return;
        }
        $this->ips[] = ['id' => ++$this->ipSeq, 'address' => $me, 'note' => Cast::string(__('admin.adv.ip.me_note'))];
        $this->checkIps();
    }

    #[Authorize('settings.manage')]
    public function removeIpRow(int $id): void
    {
        abort_unless(self::mayChange(), 403);

        $this->ips = array_values(array_filter($this->ips, static fn (array $row): bool => $row['id'] !== $id));
        $this->ipTouched = array_values(array_diff($this->ipTouched, [$id]));
        $this->checkIps();
    }

    /** A row's address was left: its fault shows from now on. */
    #[Authorize('settings.manage')]
    public function touchIp(int $id): void
    {
        abort_unless(self::mayChange(), 403);

        if (in_array($id, array_column($this->ips, 'id'), true) && ! in_array($id, $this->ipTouched, true)) {
            $this->ipTouched[] = $id;
        }
        $this->checkIps();
    }

    /** An address or a note was typed: only rows of text, as many as the list may hold. */
    public function updatedIps(): void
    {
        abort_unless(self::mayChange(), 403);

        $rows = [];
        $ids = [];
        foreach (self::untrusted($this->ips) as $row) {
            $row = is_array($row) ? $row : [];
            $id = is_int($row['id'] ?? null) ? $row['id'] : ++$this->ipSeq;
            if (isset($ids[$id])) {
                $id = ++$this->ipSeq;
            }
            $ids[$id] = true;
            $this->ipSeq = max($this->ipSeq, $id);
            $rows[] = [
                'id' => $id,
                'address' => mb_substr(is_string($row['address'] ?? null) ? $row['address'] : '', 0, 64),
                'note' => mb_substr(is_string($row['note'] ?? null) ? $row['note'] : '', 0, AdminAddressLimit::MAX_NOTE),
            ];
            if (count($rows) === AdminAddressLimit::MAX_ENTRIES) {
                break;
            }
        }
        $this->ips = $rows;
        $this->checkIps();
    }

    /**
     * A value the browser may have sent in any shape, as a list.
     *
     * @return list<mixed>
     */
    private static function untrusted(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /** Code was typed: only the four places, only text, checked for its size. */
    public function updatedCode(mixed $value, ?string $slot = null): void
    {
        abort_unless(self::mayChange(), 403);

        if ($slot === null || str_contains($slot, '.') || ! in_array($slot, SiteEmbedCode::SLOTS, true)) {
            $kept = [];
            foreach (SiteEmbedCode::SLOTS as $known) {
                $kept[$known] = is_string($this->code[$known] ?? null) ? $this->code[$known] : '';
            }
            $this->code = $kept;

            return;
        }
        if (! is_string($value)) {
            $this->code[$slot] = '';
        }
        $this->checkCode($slot);
    }

    /**
     * Only the owner opens or closes the embed-code dialog (opening it changes nothing by itself);
     * once it is closed («Hủy», ✕, Escape), a confirmation needs the question asked again.
     */
    public function updatedAskCode(): void
    {
        abort_unless(self::mayChange(), 403);

        if (! $this->askCode) {
            $this->codeAsked = false;
        }
    }

    /** A message was typed: only the website's languages, only text, checked for its length. */
    public function updatedMessages(mixed $value, ?string $locale = null): void
    {
        abort_unless(self::mayChange(), 403);

        $known = $this->locales();
        if ($locale === null || str_contains($locale, '.') || ! in_array($locale, $known, true)) {
            $kept = [];
            foreach ($known as $lc) {
                $kept[$lc] = is_string($this->messages[$lc] ?? null) ? $this->messages[$lc] : '';
            }
            $this->messages = $kept;

            return;
        }
        if (! is_string($value)) {
            $this->messages[$locale] = '';
        }
        $this->checkMessage($locale);
    }

    public function updatedReopenDate(): void
    {
        abort_unless(self::mayChange(), 403);

        // The hour is usually picked right after the day: a day alone is told about once the hour
        // is left, or on save.
        $this->checkWhen($this->reopenTime !== '');
    }

    public function updatedReopenTime(): void
    {
        abort_unless(self::mayChange(), 403);

        $this->checkWhen(true);
    }

    /**
     * Save both parts. The first save that turns embed code on opens the confirm dialog instead and
     * answers false; its «Bật và lưu» calls save(true) once «Tôi hiểu…» is ticked.
     */
    #[Authorize('settings.manage')]
    public function save(bool $confirmed = false): bool
    {
        abort_unless(self::mayChange(), 403);

        $this->resetErrorBag();
        $ok = $this->checkWhen(true);
        foreach ($this->locales() as $lc) {
            $ok = $this->checkMessage($lc) && $ok;
        }
        foreach (SiteEmbedCode::SLOTS as $slot) {
            $ok = $this->checkCode($slot) && $ok;
        }
        $this->ipTried = true;
        $ok = $this->checkIps() && $ok;
        if (! $ok) {
            $this->askCode = false;
            $this->codeAsked = false;
            $this->notify(__('admin.adv.toast_invalid'), 'error');
            $this->dispatch('adv-first-error');

            return false;
        }

        // only the parts this tab changed are written: a part left as it was loaded keeps whatever
        // another tab saved since (switching broken code off elsewhere is never undone from here)
        $changed = $this->unsavedFields();
        $codeTouched = array_filter($changed, static fn (string $f): bool => str_starts_with($f, 'code.')) !== [];
        $ipPart = array_filter($changed, static fn (string $f): bool => str_starts_with($f, 'ip.')) !== [];
        $mtTouched = array_filter($changed, static fn (string $f): bool => ! str_starts_with($f, 'code.') && ! str_starts_with($f, 'ip.')) !== [];

        $confirmed = $confirmed && $this->codeAsked;
        if (! $confirmed && $codeTouched && $this->codeOn && SiteEmbedCode::hasCode($this->draftCode()) && ! $this->codeAcknowledged) {
            $this->askCode = true;
            $this->codeAsked = true;
            $this->askSeq++;

            return false;
        }

        try {
            // read what is saved under the lock: another tab's save is neither lost (the words of a
            // language not shown here) nor miscounted (each switch's audit compares with its row)
            $written = SettingsWrite::run('advanced', function () use ($confirmed, $mtTouched, $codeTouched, $ipPart): array {
                $repository = app(SettingsRepository::class);

                // a touched part is written only when it differs from its row (a part never saved
                // compares as its empty value), so saving one part leaves the other's row, stamp and audit alone
                $mtChanged = false;
                $wasOn = false;
                $mt = null;
                if ($mtTouched) {
                    $savedMt = $repository->get(SiteMaintenance::GROUP, SiteMaintenance::KEY);
                    $wasOn = SiteMaintenance::shape($savedMt)['on'];
                    $mt = $this->stored(SiteMaintenance::shape($savedMt)['messages']);
                    $mtChanged = $mt != ($savedMt ?? ['on' => false, 'messages' => [], 'reopens_at' => null]);
                    if ($mtChanged) {
                        $repository->put(SiteMaintenance::GROUP, SiteMaintenance::KEY, $mt);
                    }
                }

                $codeChanged = false;
                $was = null;
                $code = null;
                if ($codeTouched) {
                    $savedCode = $repository->get(SiteEmbedCode::GROUP, SiteEmbedCode::KEY);
                    $was = SiteEmbedCode::shape($savedCode);
                    $code = $this->storedCode($was, $confirmed);
                    $codeChanged = $code != ($savedCode ?? ['on' => false, 'code' => ['head' => '', 'body_top' => '', 'body_end' => '', 'foot' => ''], 'acknowledged_at' => null, 'acknowledged_by' => null]);
                    if ($codeChanged) {
                        $repository->put(SiteEmbedCode::GROUP, SiteEmbedCode::KEY, $code);
                    }
                }

                // the admin is never cached at the edge: this part purges nothing
                $ipWritten = null;
                if ($ipPart) {
                    $savedIp = $repository->get(AdminAddressLimit::GROUP, AdminAddressLimit::KEY);
                    $ip = ['on' => $this->ipOn, 'entries' => $this->storedIps()];
                    if ($ip != ($savedIp ?? ['on' => false, 'entries' => []])) {
                        app(EdgeCachePause::class)->during(fn () => $repository->put(AdminAddressLimit::GROUP, AdminAddressLimit::KEY, $ip));
                        $ipWritten = ['value' => $ip, 'was' => AdminAddressLimit::shape($savedIp)];
                    }
                }

                return [
                    'mt' => $mtChanged && $mt['on'] !== $wasOn ? $mt['on'] : null,
                    'code' => $codeChanged ? ['value' => $code, 'was' => $was] : null,
                    'ip' => $ipWritten,
                ];
            });
        } catch (SettingsChangeRejected $e) {
            if ($e->reason !== 'busy') {
                throw $e;
            }
            $this->notify(__('admin.settings.busy'), 'error');

            return false;
        }
        if ($written['mt'] !== null) {
            Audit::record($written['mt'] ? 'settings.maintenance.on' : 'settings.maintenance.off');
        }
        if ($written['code'] !== null) {
            $this->auditCode($written['code']['value'], $written['code']['was']);
        }
        if ($written['ip'] !== null) {
            $on = $written['ip']['value']['on'];
            $wasOn = $written['ip']['was']['on'];
            // which addresses came and went (each as it is kept; never the notes), so a row tells who
            // opened the admin to where; each list stops at the most a list may hold
            $now = array_column($written['ip']['value']['entries'], 'address');
            $before = array_column($written['ip']['was']['entries'], 'address');
            Audit::record(match (true) {
                $on && ! $wasOn => 'settings.admin_ip.on',
                ! $on && $wasOn => 'settings.admin_ip.off',
                default => 'settings.admin_ip.changed',
            }, ['meta' => [
                'on' => $on,
                'count' => count($now),
                'added' => array_slice(array_values(array_diff($now, $before)), 0, AdminAddressLimit::MAX_ENTRIES),
                'removed' => array_slice(array_values(array_diff($before, $now)), 0, AdminAddressLimit::MAX_ENTRIES),
            ]]);
        }

        $this->askCode = false;
        $this->codeAsked = false;
        $this->load();
        $this->notify(__('admin.adv.toast_saved'));

        return true;
    }

    /** Back to the saved setting. */
    #[Authorize('settings.manage')]
    public function resetChanges(): void
    {
        abort_unless(self::mayChange(), 403);

        $this->askCode = false;
        $this->codeAsked = false;
        $this->load();
        $this->resetErrorBag();
        $this->notify(__('admin.adv.toast_reset'));
    }

    #[Authorize('settings.manage')]
    public function dismissToast(?int $seq = null): void
    {
        if ($seq === null || $seq === $this->toastSeq) {
            $this->toast = '';
        }
    }

    public function render(): View
    {
        $siteLocale = app(SiteLocale::class);
        $default = $siteLocale->default();
        $user = Auth::user();

        $view = view('admin::livewire.advanced-settings', [
            'changes' => $this->readOnly ? null : $this->unsavedCount(),
            'locales' => $this->locales(),
            'defaultLocale' => $default,
            'names' => array_combine($this->locales(), array_map(static fn (string $lc): string => Locales::label($lc), $this->locales())),
            'stamp' => $this->stampText(),
            'role' => $user instanceof User ? RoleLabel::topOf($user) : null,
            'whenText' => $this->whenText(),
            'codeKept' => ! $this->codeOn && SiteEmbedCode::hasCode($this->draftCode()),
            'myIp' => self::myAddress(),
            'meIn' => AdminAddressLimit::covers($this->validIps(), self::myAddress()),
            'inSupport' => self::inSupport(),
            'ipRows' => $this->ipRows(),
            'ipMax' => AdminAddressLimit::MAX_ENTRIES,
            'platform' => Cast::string(config('admin.brand.name')),
        ]);
        $view->title(__('admin.adv.title'));

        return $view;
    }

    /** The website's owner, or a platform operator, may change this page; anyone else only reads it. */
    private static function mayChange(): bool
    {
        return SiteOwner::is(Auth::user());
    }

    /** The address this request comes from, in the form the list keeps (never a header's say). */
    private static function myAddress(): ?string
    {
        return AdminAddressLimit::clientForm(request()->ip());
    }

    /**
     * What «Thêm địa chỉ này» adds: the IPv4 address in use, or for IPv6 its /64 network, since a
     * device on IPv6 changes its own address within that network every day or so (the board's hint
     * says the same) and a single IPv6 address would lock its owner out tomorrow.
     */
    private static function myEntry(): ?string
    {
        $me = self::myAddress();
        if ($me === null || ! str_contains($me, ':')) {
            return $me;
        }
        $network = AdminAddressLimit::normalize($me.'/64');

        return $network['ok'] ? $network['address'] : null;
    }

    /** A platform staff member in a live support session (support itself is how a locked-out owner gets in). */
    private static function inSupport(): bool
    {
        return app(SupportSession::class)->active();
    }

    /**
     * The drafted addresses that are addresses, as they would be kept.
     *
     * @return list<string>
     */
    private function validIps(): array
    {
        return array_column($this->storedIps(), 'address');
    }

    /**
     * What is saved for the list: each address that is one, once, as normalize() keeps it, with its note.
     *
     * @return list<array{address: string, note: string}>
     */
    private function storedIps(): array
    {
        $out = [];
        $seen = [];
        foreach ($this->ips as $row) {
            $address = AdminAddressLimit::normalize($row['address']);
            if (! $address['ok'] || isset($seen[$address['address']])) {
                continue;
            }
            $seen[$address['address']] = true;
            $out[] = ['address' => $address['address'], 'note' => mb_substr(trim($row['note']), 0, AdminAddressLimit::MAX_NOTE)];
        }

        return $out;
    }

    private function errorBag(): MessageBag
    {
        $bag = $this->getErrorBag();

        return $bag instanceof MessageBag ? $bag : new MessageBag;
    }

    /** Below the most addresses a list may hold; otherwise say so on the list. */
    private function roomForIp(): bool
    {
        if (count($this->ips) < AdminAddressLimit::MAX_ENTRIES) {
            return true;
        }
        $this->checkIps();
        $this->addError('ip.list', __('admin.adv.ip.err_many', ['max' => AdminAddressLimit::MAX_ENTRIES]));

        return false;
    }

    /**
     * The address list's faults, told as the board tells them: a row's once it was left (or a save
     * was tried), the empty list and the lock-out only once a save was tried. Answers whether the
     * list may be saved (a list switched off always may: its invalid rows are simply not kept).
     */
    private function checkIps(): bool
    {
        // only the list's own: an empty list of keys would clear every other fault on the screen
        $own = array_values(array_filter($this->errorBag()->keys(), static fn (mixed $key): bool => str_starts_with(Cast::string($key), 'ip.')));
        if ($own !== []) {
            $this->resetErrorBag($own);
        }
        if (! $this->ipOn) {
            return true;
        }

        $ok = true;
        foreach ($this->ips as $i => $row) {
            $text = trim($row['address']);
            $result = AdminAddressLimit::normalize($text);
            if ($result['ok']) {
                continue;
            }
            $ok = false;
            if ($this->ipTried || in_array($row['id'], $this->ipTouched, true)) {
                $this->addError('ip.'.$row['id'], __('admin.adv.ip.err_'.$result['error'], ['n' => $i + 1, 'value' => $text]));
            }
        }

        $valid = $this->validIps();
        if ($this->ips === []) {
            $ok = false;
            if ($this->ipTried) {
                $this->addError('ip.list', __('admin.adv.ip.err_list'));
            }
        } elseif (count($this->ips) > AdminAddressLimit::MAX_ENTRIES) {
            $ok = false;
            if ($this->ipTried) {
                $this->addError('ip.list', __('admin.adv.ip.err_many', ['max' => AdminAddressLimit::MAX_ENTRIES]));
            }
        } elseif ($valid !== [] && ! self::inSupport() && ! AdminAddressLimit::covers($valid, request()->ip())) {
            // the very address this save comes from must stay in, or the save locks its owner out
            $ok = false;
            if ($this->ipTried) {
                $this->addError('ip.lock', __('admin.adv.ip.lock', ['ip' => self::myAddress() ?? '']));
            }
        }

        return $ok;
    }

    /**
     * The rows as the page draws them: number, fault (when told) and whether it holds the address in use.
     *
     * @return list<array{id: int, no: int, address: string, note: string, error: string|null, isMe: bool}>
     */
    private function ipRows(): array
    {
        $me = self::myAddress();
        $rows = [];
        foreach ($this->ips as $i => $row) {
            $error = $this->errorBag()->first('ip.'.$row['id']);
            $result = AdminAddressLimit::normalize($row['address']);
            $rows[] = [
                'id' => $row['id'],
                'no' => $i + 1,
                'address' => $row['address'],
                'note' => $row['note'],
                'error' => $error !== '' ? $error : null,
                'isMe' => $error === '' && $result['ok'] && AdminAddressLimit::covers([$result['address']], $me),
            ];
        }

        return $rows;
    }

    /**
     * The website's languages, the default first.
     *
     * @return list<string>
     */
    private function locales(): array
    {
        $siteLocale = app(SiteLocale::class);
        $default = $siteLocale->default();

        return array_values(array_unique([$default, ...$siteLocale->enabled()]));
    }

    private function checkMessage(string $locale): bool
    {
        $key = 'messages.'.$locale;
        $this->resetErrorBag($key);
        if (mb_strlen(trim($this->messages[$locale] ?? '')) > SiteMaintenance::MAX_MESSAGE) {
            $this->addError($key, __('admin.adv.mt.msg_long', ['max' => SiteMaintenance::MAX_MESSAGE]));

            return false;
        }

        return true;
    }

    private function checkCode(string $slot): bool
    {
        $key = 'code.'.$slot;
        $this->resetErrorBag($key);
        if (strlen($this->code[$slot] ?? '') > SiteEmbedCode::MAX_BYTES) {
            $this->addError($key, __('admin.adv.cd.too_long', ['max' => intdiv(SiteEmbedCode::MAX_BYTES, 1024)]));

            return false;
        }

        return true;
    }

    /**
     * The code of each place as it would be kept: a box of spaces only is empty.
     *
     * @return array{head: string, body_top: string, body_end: string, foot: string}
     */
    private function draftCode(): array
    {
        $code = ['head' => '', 'body_top' => '', 'body_end' => '', 'foot' => ''];
        foreach (SiteEmbedCode::SLOTS as $slot) {
            $text = $this->code[$slot] ?? '';
            $code[$slot] = trim($text) === '' ? '' : $text;
        }

        return $code;
    }

    /**
     * What is saved for the embed code: the switch, the code of each place, and when (and by whom)
     * running it was first confirmed — kept from the saved row, or now when this save is that first
     * confirmed one.
     *
     * @param  array{on: bool, code: array<string, string>, acknowledged_at: CarbonImmutable|null, acknowledged_by: int|null}  $saved
     * @return array{on: bool, code: array{head: string, body_top: string, body_end: string, foot: string}, acknowledged_at: string|null, acknowledged_by: int|null}
     */
    private function storedCode(array $saved, bool $confirmed): array
    {
        $code = $this->draftCode();
        $at = $saved['acknowledged_at']?->toIso8601ZuluString();
        $by = $saved['acknowledged_by'];
        if ($at === null && $confirmed && $this->codeOn && SiteEmbedCode::hasCode($code)) {
            $at = CarbonImmutable::now()->utc()->toIso8601ZuluString();
            $user = Auth::user();
            $by = $user instanceof User ? Cast::int($user->getKey()) : null;
        }

        return ['on' => $this->codeOn, 'code' => $code, 'acknowledged_at' => $at, 'acknowledged_by' => $by];
    }

    /**
     * One audit row for a saved change to the embed code: switched on, switched off, or changed.
     * Who and when are the row's own; it names each place's size and a short hash of its code (to
     * tell versions apart), never the code.
     *
     * @param  array{on: bool, code: array<string, string>, acknowledged_at: string|null, acknowledged_by: int|null}  $value
     * @param  array{on: bool, code: array<string, string>, acknowledged_at: CarbonImmutable|null, acknowledged_by: int|null}  $was
     */
    private function auditCode(array $value, array $was): void
    {
        $bytes = [];
        $hashes = [];
        foreach (SiteEmbedCode::SLOTS as $slot) {
            $text = $value['code'][$slot] ?? '';
            $bytes[$slot] = strlen($text);
            if ($text !== '') {
                $hashes[$slot] = substr(hash('sha256', $text), 0, 12);
            }
        }
        $action = match (true) {
            $value['on'] && ! $was['on'] => 'settings.embed_code.on',
            ! $value['on'] && $was['on'] => 'settings.embed_code.off',
            default => 'settings.embed_code.changed',
        };

        Audit::record($action, ['meta' => [
            'on' => $value['on'],
            'bytes' => $bytes,
            'sha256' => $hashes,
            'acknowledged' => $was['acknowledged_at'] === null && $value['acknowledged_at'] !== null,
        ]]);
    }

    /**
     * Both a day and an hour, or neither; not already past unless it is the time already saved.
     * `complete` false leaves a day without its hour unreported (the hour comes next).
     */
    private function checkWhen(bool $complete): bool
    {
        $this->resetErrorBag('reopen');
        $date = trim($this->reopenDate);
        $time = trim($this->reopenTime);
        if ($date === '' && $time === '') {
            return true;
        }
        if ($date === '' || ! self::isDate($date)) {
            $this->addError('reopen', __('admin.adv.when_need_date'));

            return false;
        }
        if ($time === '' || ! self::isTime($time)) {
            if ($complete || $time !== '') {
                $this->addError('reopen', __('admin.adv.when_need_time'));
            }

            return false;
        }
        $local = $date.' '.$time;
        if ($local !== $this->savedReopen && ! $this->instant($local)->isFuture()) {
            $this->addError('reopen', __('admin.adv.when_past'));

            return false;
        }

        return true;
    }

    private static function isDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function isTime(string $time): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
    }

    /** "Y-m-d H:i" in the website's time zone, as a UTC instant. */
    private function instant(string $local): CarbonImmutable
    {
        return app(SiteTime::class)->toStorage($local);
    }

    /**
     * What is saved: the switch, the words of each language (those of a language switched off since
     * are kept, for when it comes back) and the reopen time in UTC.
     *
     * @param  array<string, string>  $messages  the words saved now, per language
     * @return array{on: bool, messages: array<string, string>, reopens_at: string|null}
     */
    private function stored(array $messages): array
    {
        foreach ($this->locales() as $lc) {
            $text = trim($this->messages[$lc] ?? '');
            if ($text === '') {
                unset($messages[$lc]);
            } else {
                $messages[$lc] = $text;
            }
        }
        $date = trim($this->reopenDate);
        $time = trim($this->reopenTime);

        return [
            'on' => $this->maintenanceOn,
            'messages' => $messages,
            'reopens_at' => $date !== '' && $time !== '' ? $this->instant($date.' '.$time)->toIso8601ZuluString() : null,
        ];
    }

    private function load(): void
    {
        $rows = self::rows()->get(['id', 'group_name', 'key', 'value', 'updated_at', 'updated_by']);
        $shape = SiteMaintenance::shape($rows->first(static fn (SiteSetting $row): bool => $row->group_name === SiteMaintenance::GROUP && $row->key === SiteMaintenance::KEY)?->value);

        $this->maintenanceOn = $shape['on'];
        $this->savedOn = $shape['on'];
        $this->messages = [];
        foreach ($this->locales() as $lc) {
            $this->messages[$lc] = $shape['messages'][$lc] ?? '';
        }
        $local = app(SiteTime::class)->forDisplay($shape['reopens_at']);
        $this->reopenDate = $local?->format('Y-m-d') ?? '';
        $this->reopenTime = $local?->format('H:i') ?? '';
        $this->savedReopen = $local?->format('Y-m-d H:i') ?? '';

        $code = SiteEmbedCode::shape($rows->first(static fn (SiteSetting $row): bool => $row->group_name === SiteEmbedCode::GROUP && $row->key === SiteEmbedCode::KEY)?->value);
        $this->codeOn = $code['on'];
        $this->code = $code['code'];
        $this->codeRunning = $code['on'] && SiteEmbedCode::hasCode($code['code']);
        $this->codeAcknowledged = $code['acknowledged_at'] !== null;

        $ip = AdminAddressLimit::shape($rows->first(static fn (SiteSetting $row): bool => $row->group_name === AdminAddressLimit::GROUP && $row->key === AdminAddressLimit::KEY)?->value);
        $this->ipOn = $ip['on'];
        $this->ipSavedOn = $ip['on'];
        $this->ips = [];
        foreach ($ip['entries'] as $entry) {
            $this->ips[] = ['id' => ++$this->ipSeq, 'address' => $entry['address'], 'note' => $entry['note']];
        }
        $this->ipTouched = [];
        $this->ipTried = false;

        $this->stampRows = $rows;
        $this->rememberSaved();
    }

    /**
     * The rows this page edits: maintenance, the embed code and the address limit.
     *
     * @return Builder<SiteSetting>
     */
    private static function rows(): Builder
    {
        return SiteSetting::query()->where(static fn (Builder $q): Builder => $q
            ->where(static fn (Builder $m): Builder => $m->where('group_name', SiteMaintenance::GROUP)->whereIn('key', [SiteMaintenance::KEY, SiteEmbedCode::KEY]))
            ->orWhere(static fn (Builder $a): Builder => $a->where('group_name', AdminAddressLimit::GROUP)->where('key', AdminAddressLimit::KEY)));
    }

    /**
     * "Cập nhật lần cuối 14:05 · Ngọc Anh" (today) or "… 12/11/2026 · …" (another day, in the site's
     * date format and time zone); null when it was never saved.
     */
    private function stampText(): ?string
    {
        $rows = $this->stampRows ?? self::rows()->get(['id', 'updated_at', 'updated_by']);
        $stamp = app(SettingsStamp::class)->of($rows);
        if ($stamp === null) {
            return null;
        }
        $time = app(SiteTime::class);
        $local = $time->forDisplay($stamp['at']);
        $when = $local !== null && $local->isSameDay($time->now()) ? $local->format('H:i') : $time->day($stamp['at'], app()->getLocale());

        return $stamp['by'] !== null
            ? Cast::string(__('admin.set.stamp', ['when' => $when, 'name' => $stamp['by']]))
            : Cast::string(__('admin.set.stamp_anon', ['when' => $when]));
    }

    /** The reopen time as visitors will read it, while the fields hold a day and an hour. */
    private function whenText(): ?string
    {
        $date = trim($this->reopenDate);
        $time = trim($this->reopenTime);
        if (! self::isDate($date) || ! self::isTime($time)) {
            return null;
        }

        return SiteMaintenance::format($this->instant($date.' '.$time), app(SiteTime::class)->zone());
    }

    /**
     * Both switches, each language's words, the reopen time and each place's code as they would be
     * kept, for the save bar.
     *
     * @return array<string, bool|string|list<array{0: string, 1: string}>>
     */
    protected function trackedState(): array
    {
        $state = ['on' => $this->maintenanceOn];
        foreach ($this->locales() as $lc) {
            $state['messages.'.$lc] = trim($this->messages[$lc] ?? '');
        }
        $state['reopen'] = trim($this->reopenDate).' '.trim($this->reopenTime);
        $state['code.on'] = $this->codeOn;
        foreach ($this->draftCode() as $slot => $text) {
            $state['code.'.$slot] = $text;
        }
        $state['ip.on'] = $this->ipOn;
        $state['ip.rows'] = array_map(static fn (array $row): array => [trim($row['address']), trim($row['note'])], $this->ips);

        return $state;
    }

    private function notify(string $message, string $tone = 'success'): void
    {
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }
}
