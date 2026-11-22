<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\TracksSavedState;
use App\Modules\Admin\Support\Locales;
use App\Modules\Admin\Support\Settings\SettingsChangeRejected;
use App\Modules\Admin\Support\Settings\SettingsStamp;
use App\Modules\Admin\Support\Settings\SettingsWrite;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\SiteOwner;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Storefront\Support\SiteEmbedCode;
use App\Modules\Storefront\Support\SiteMaintenance;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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

    /** Only the owner opens or closes the embed-code dialog (opening it changes nothing by itself). */
    public function updatedAskCode(): void
    {
        abort_unless(self::mayChange(), 403);
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
        if (! $ok) {
            $this->askCode = false;
            $this->notify(__('admin.adv.toast_invalid'), 'error');
            $this->dispatch('adv-first-error');

            return false;
        }
        if (! $confirmed && $this->codeOn && SiteEmbedCode::hasCode($this->draftCode()) && ! $this->codeAcknowledged) {
            $this->askCode = true;
            $this->askSeq++;

            return false;
        }

        try {
            // read what is saved under the lock: another tab's save is neither lost (the words of a
            // language not shown here) nor miscounted (each switch's audit compares with its row)
            $written = SettingsWrite::run('advanced', function () use ($confirmed): array {
                $repository = app(SettingsRepository::class);

                // a part is written only when it changed (a part never saved compares as its empty
                // value), so saving one part leaves the other's row, stamp and audit alone
                $savedMt = $repository->get(SiteMaintenance::GROUP, SiteMaintenance::KEY);
                $wasOn = SiteMaintenance::shape($savedMt)['on'];
                $mt = $this->stored(SiteMaintenance::shape($savedMt)['messages']);
                $mtChanged = $mt != ($savedMt ?? ['on' => false, 'messages' => [], 'reopens_at' => null]);
                if ($mtChanged) {
                    $repository->put(SiteMaintenance::GROUP, SiteMaintenance::KEY, $mt);
                }

                $savedCode = $repository->get(SiteEmbedCode::GROUP, SiteEmbedCode::KEY);
                $was = SiteEmbedCode::shape($savedCode);
                $code = $this->storedCode($was, $confirmed);
                $codeChanged = $code != ($savedCode ?? ['on' => false, 'code' => ['head' => '', 'body_top' => '', 'body_end' => '', 'foot' => ''], 'acknowledged_at' => null, 'acknowledged_by' => null]);
                if ($codeChanged) {
                    $repository->put(SiteEmbedCode::GROUP, SiteEmbedCode::KEY, $code);
                }

                return [
                    'mt' => $mtChanged && $mt['on'] !== $wasOn ? $mt['on'] : null,
                    'code' => $codeChanged ? ['value' => $code, 'was' => $was] : null,
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

        $this->askCode = false;
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
        ]);
        $view->title(__('admin.adv.title'));

        return $view;
    }

    /** The website's owner, or a platform operator, may change this page; anyone else only reads it. */
    private static function mayChange(): bool
    {
        return SiteOwner::is(Auth::user());
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
        $rows = SiteSetting::query()
            ->where('group_name', SiteMaintenance::GROUP)
            ->whereIn('key', [SiteMaintenance::KEY, SiteEmbedCode::KEY])
            ->get(['id', 'key', 'value', 'updated_at', 'updated_by']);
        $shape = SiteMaintenance::shape($rows->firstWhere('key', SiteMaintenance::KEY)?->value);

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

        $code = SiteEmbedCode::shape($rows->firstWhere('key', SiteEmbedCode::KEY)?->value);
        $this->codeOn = $code['on'];
        $this->code = $code['code'];
        $this->codeRunning = $code['on'] && SiteEmbedCode::hasCode($code['code']);
        $this->codeAcknowledged = $code['acknowledged_at'] !== null;

        $this->stampRows = $rows;
        $this->rememberSaved();
    }

    /**
     * "Cập nhật lần cuối 14:05 · Ngọc Anh" (today) or "… 12/11/2026 · …" (another day, in the site's
     * date format and time zone); null when it was never saved.
     */
    private function stampText(): ?string
    {
        $rows = $this->stampRows ?? SiteSetting::query()
            ->where('group_name', SiteMaintenance::GROUP)
            ->whereIn('key', [SiteMaintenance::KEY, SiteEmbedCode::KEY])
            ->get(['id', 'updated_at', 'updated_by']);
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
     * @return array<string, bool|string>
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

        return $state;
    }

    private function notify(string $message, string $tone = 'success'): void
    {
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }
}
