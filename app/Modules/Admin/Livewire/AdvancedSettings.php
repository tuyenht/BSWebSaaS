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
use App\Modules\Storefront\Support\SiteMaintenance;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;
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
 * (or a platform operator) changes it. For now it holds the maintenance mode (SiteMaintenance):
 * one switch, the owner's words per website language, and an optional time the website is expected
 * back, set in the website's time zone and stored in UTC. The website never opens by itself at that
 * time; it only tells visitors when to come back.
 *
 * The page edits a draft and saves it in one step (the shared save bar). The reopen time is checked
 * when its fields are left and again on save: both a day and an hour, and not already past (a time
 * kept from an earlier save may have passed and still be saved unchanged). Saving writes one
 * site_settings row, which purges the website's pages from the edge (EdgeCachePurges), so visitors
 * see the change at once.
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

    /** Set once at mount: the member may look but not change («Chỉ xem»). */
    #[Locked]
    public bool $readOnly = true;

    #[Locked]
    public string $toast = '';

    #[Locked]
    public string $toastTone = 'success';

    #[Locked]
    public int $toastSeq = 0;

    /** The row loaded in this request, for the stamp (not sent to the browser). */
    private ?SiteSetting $stampRow = null;

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

    #[Authorize('settings.manage')]
    public function save(): bool
    {
        abort_unless(self::mayChange(), 403);

        $this->resetErrorBag();
        $ok = $this->checkWhen(true);
        foreach ($this->locales() as $lc) {
            $ok = $this->checkMessage($lc) && $ok;
        }
        if (! $ok) {
            $this->notify(__('admin.adv.toast_invalid'), 'error');
            $this->dispatch('adv-first-error');

            return false;
        }

        $value = $this->stored();
        $wasOn = $this->savedOn;
        try {
            SettingsWrite::run('advanced', function () use ($value): void {
                app(SettingsRepository::class)->put(SiteMaintenance::GROUP, SiteMaintenance::KEY, $value);
            });
        } catch (SettingsChangeRejected $e) {
            if ($e->reason !== 'busy') {
                throw $e;
            }
            $this->notify(__('admin.settings.busy'), 'error');

            return false;
        }
        if ($value['on'] !== $wasOn) {
            Audit::record($value['on'] ? 'settings.maintenance.on' : 'settings.maintenance.off');
        }

        $this->load();
        $this->notify(__('admin.adv.toast_saved'));

        return true;
    }

    /** Back to the saved setting. */
    #[Authorize('settings.manage')]
    public function resetChanges(): void
    {
        abort_unless(self::mayChange(), 403);

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
     * @return array{on: bool, messages: array<string, string>, reopens_at: string|null}
     */
    private function stored(): array
    {
        $messages = SiteMaintenance::shape(app(SettingsRepository::class)->get(SiteMaintenance::GROUP, SiteMaintenance::KEY))['messages'];
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
        $row = SiteSetting::query()
            ->where('group_name', SiteMaintenance::GROUP)
            ->where('key', SiteMaintenance::KEY)
            ->first(['id', 'value', 'updated_at', 'updated_by']);
        $shape = SiteMaintenance::shape($row?->value);

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
        $this->stampRow = $row;
        $this->rememberSaved();
    }

    /**
     * "Cập nhật lần cuối 14:05 · Ngọc Anh" (today) or "… 12/11/2026 · …" (another day, in the site's
     * date format and time zone); null when it was never saved.
     */
    private function stampText(): ?string
    {
        $row = $this->stampRow ?? SiteSetting::query()
            ->where('group_name', SiteMaintenance::GROUP)
            ->where('key', SiteMaintenance::KEY)
            ->first(['id', 'updated_at', 'updated_by']);
        $stamp = app(SettingsStamp::class)->of([$row]);
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
     * The switch, each language's words and the reopen time as they would be kept, for the save bar.
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

        return $state;
    }

    private function notify(string $message, string $tone = 'success'): void
    {
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }
}
