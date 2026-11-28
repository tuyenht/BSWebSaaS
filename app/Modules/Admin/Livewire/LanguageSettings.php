<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\TracksSavedState;
use App\Modules\Admin\Support\UpgradeInvitation;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Actions\ChangeDefaultSiteLocale;
use App\Modules\Tenancy\Actions\ConfigureSiteLocale;
use App\Modules\Tenancy\Context\SiteLocale as SiteLocales;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Exceptions\SiteLocaleChangeRejectedException;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteLocale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Settings › Ngôn ngữ website: which languages editors write in («Đang soạn»), which of them
 * visitors see («Khách thấy») and which one is the default (the star: the source for
 * translations and the version the bare domain opens). Every platform content language is a row;
 * the plan's `locales.max` (the default included) locks the ones that would go over it.
 *
 * Changes are a draft until «Lưu» (the shared save bar). Turning a public language off, opening a
 * language that is off to visitors and changing the default each ask first and say what happens.
 * Saving goes through the single write paths (ConfigureSiteLocale, ChangeDefaultSiteLocale) inside
 * one transaction, so a refusal (the plan limit) leaves every language as it was. A language
 * switched off keeps its translations. Writes are settings.manage-gated like every site-wide tab.
 */
#[Layout('admin::layouts.app')]
final class LanguageSettings extends Component
{
    use TracksSavedState;

    /** @var list<string> the draft: languages editors write in */
    #[Locked]
    public array $enabled = [];

    /** @var list<string> the draft: languages visitors see (always within $enabled) */
    #[Locked]
    public array $public = [];

    #[Locked]
    public string $default = '';

    /** The question open before a change: its kind (pub · off · def) and language. */
    #[Locked]
    public string $askKind = '';

    #[Locked]
    public string $askCode = '';

    public bool $asking = false;

    public string $toast = '';

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    public function boot(): void
    {
        // The livewire/update endpoint bypasses route middleware: re-check on every request.
        abort_unless(Gate::allows('settings.manage'), 403);
    }

    public function mount(): void
    {
        $this->load();
    }

    /** «Đang soạn»: switch a language on or off for editors (off also hides it from visitors). */
    public function toggleEnabled(string $code): void
    {
        if (! $this->offers($code)) {
            return;
        }
        if ($code === $this->default) {
            $this->notify(__('admin.lng.n_def_on'), 'info');

            return;
        }
        $on = in_array($code, $this->enabled, true);
        if (! $on && $this->lockedByPlan($code)) {
            $this->limitNotice();

            return;
        }
        if ($on && in_array($code, $this->public, true)) {
            $this->ask('off', $code);

            return;
        }
        $on ? $this->switchOff($code) : $this->enabled = $this->ordered([...$this->enabled, $code]);
    }

    /** «Khách thấy»: show a language to visitors or hide it (one that is off asks to switch both on). */
    public function togglePublic(string $code): void
    {
        if (! $this->offers($code)) {
            return;
        }
        if ($code === $this->default) {
            $this->notify(__('admin.lng.n_def_pub'), 'info');

            return;
        }
        if (! in_array($code, $this->enabled, true)) {
            $this->lockedByPlan($code) ? $this->limitNotice() : $this->ask('pub', $code);

            return;
        }
        $this->public = in_array($code, $this->public, true)
            ? array_values(array_diff($this->public, [$code]))
            : $this->ordered([...$this->public, $code]);
    }

    /** The star: ask before making a language the default. */
    public function chooseDefault(string $code): void
    {
        if (! $this->offers($code) || $code === $this->default) {
            return;
        }
        if (! in_array($code, $this->enabled, true) && $this->lockedByPlan($code)) {
            $this->limitNotice();

            return;
        }
        $this->ask('def', $code);
    }

    public function confirmAsk(): void
    {
        $code = $this->askCode;
        $kind = $this->askKind;
        $this->cancelAsk();
        if (! $this->offers($code)) {
            return;
        }
        $name = $this->label($code);

        if ($kind === 'pub' && ! $this->lockedByPlan($code)) {
            $this->enabled = $this->ordered([...$this->enabled, $code]);
            $this->public = $this->ordered([...$this->public, $code]);
            $this->notify(__('admin.lng.n_pub', ['name' => $name]));
        } elseif ($kind === 'off' && $code !== $this->default) {
            $this->switchOff($code);
            $this->notify(__('admin.lng.n_off', ['name' => $name]));
        } elseif ($kind === 'def' && (in_array($code, $this->enabled, true) || ! $this->lockedByPlan($code))) {
            $this->default = $code;
            $this->enabled = $this->ordered([...$this->enabled, $code]);
            $this->public = $this->ordered([...$this->public, $code]);
            $this->notify(__('admin.lng.n_def', ['name' => $name]));
        }
    }

    public function cancelAsk(): void
    {
        $this->asking = false;
        $this->askKind = '';
        $this->askCode = '';
    }

    public function save(): bool
    {
        if ($this->unsavedCount() === 0) {
            $this->notify(__('admin.lng.saved'));

            return true;
        }
        $before = $this->stored(fresh: true);
        $max = $this->limit();

        try {
            DB::transaction(function () use ($before): void {
                $configure = app(ConfigureSiteLocale::class);
                $rows = $this->offered($before);
                // Languages going off or hidden first, so their places count for the ones going on.
                foreach ($rows as $code) {
                    [$on, $pub] = [in_array($code, $this->enabled, true), in_array($code, $this->public, true)];
                    $was = $before['rows'][$code] ?? ['on' => false, 'pub' => false];
                    if ($code !== $before['default'] && (($was['on'] && ! $on) || ($was['pub'] && ! $pub))) {
                        $configure->handle($code, $on, $pub);
                    }
                }
                if ($this->default !== $before['default']) {
                    // The old default goes off or hidden in the same step, so its place is free first.
                    app(ChangeDefaultSiteLocale::class)->handle(
                        $this->default,
                        oldOn: in_array($before['default'], $this->enabled, true),
                        oldPublic: in_array($before['default'], $this->public, true),
                    );
                }
                // Then the rest, once more every place freed before any taken: the old default can only
                // go off after it stepped down, and it may sort after a language switching on.
                $now = $this->stored(fresh: true);
                $changes = [];
                foreach ($rows as $code) {
                    [$on, $pub] = [in_array($code, $this->enabled, true), in_array($code, $this->public, true)];
                    $was = $now['rows'][$code] ?? ['on' => false, 'pub' => false];
                    if ($on !== $was['on'] || $pub !== $was['pub']) {
                        $changes[] = [$code, $on, $pub, ($was['on'] && ! $on) || ($was['pub'] && ! $pub)];
                    }
                }
                usort($changes, static fn (array $a, array $b): int => $b[3] <=> $a[3]);
                foreach ($changes as [$code, $on, $pub]) {
                    $configure->handle($code, $on, $pub);
                }
            });
        } catch (SiteLocaleChangeRejectedException $e) {
            $this->notify(match ($e->reason) {
                'limit_reached' => __('admin.lng.err_limit', ['max' => $max]),
                'default_locked' => __('admin.lng.err_default'),
                'prefix_in_use' => __('admin.lng.err_prefix', ['section' => $this->sectionLabel($e->section)]),
                default => __('admin.lng.err_invalid'),
            }, 'error');

            return false;
        }

        if ($this->default !== $before['default']) {
            Audit::record('settings.locales.default_changed', ['meta' => ['from' => $before['default'], 'to' => $this->default]]);
        }
        Audit::record('settings.locales.changed', ['meta' => ['enabled' => $this->enabled, 'public' => $this->public, 'default' => $this->default]]);

        $this->load();
        $this->notify(__('admin.lng.saved'));

        return true;
    }

    /** Back to the saved languages (the save bar's «Bỏ thay đổi»). */
    public function resetChanges(): void
    {
        $this->cancelAsk();
        $this->load();
    }

    public function dismissToast(?int $seq = null): void
    {
        if ($seq === null || $seq === $this->toastSeq) {
            $this->toast = '';
        }
    }

    public function render(): View
    {
        $max = $this->limit();
        $upsell = app(UpgradeInvitation::class)->isOn();
        // The dialog flag is the client's (entangled); a question exists only once a toggle set one.
        $asking = $this->asking && in_array($this->askKind, ['pub', 'off', 'def'], true);
        $codes = $this->offered($this->stored());
        $full = count($this->enabled) >= $max;
        $hidden = array_values(array_filter($codes, fn (string $code): bool => $code !== $this->default
            && in_array($code, $this->enabled, true) && ! in_array($code, $this->public, true)));

        $rows = array_map(fn (string $code): array => [
            'code' => $code,
            'name' => $this->label($code),
            'on' => in_array($code, $this->enabled, true),
            'pub' => in_array($code, $this->public, true),
            'def' => $code === $this->default,
            'lock' => ! in_array($code, $this->enabled, true) && $full,
        ], $codes);

        $view = view('admin::livewire.language-settings', [
            'rows' => $rows,
            'max' => $max,
            'upsell' => $upsell,
            'limited' => $full && array_any($codes, fn (string $code): bool => ! in_array($code, $this->enabled, true)),
            'over' => count($this->enabled) > $max,
            'previews' => $this->previews($hidden),
            'supportMail' => Cast::string(config('support.mailbox')) ?: Cast::string(config('mail.from.address')),
            'askTitle' => $asking ? __('admin.lng.ask_'.$this->askKind.'_t', ['name' => $this->label($this->askCode)]) : '',
            'askText' => $asking ? __('admin.lng.ask_'.$this->askKind, ['name' => $this->label($this->askCode), 'code' => $this->askCode]) : '',
            'askOk' => $asking ? __('admin.lng.ask_'.$this->askKind.'_ok') : '',
            'changes' => $this->unsavedCount(),
        ]);
        $view->title(__('admin.lng.title'));

        return $view;
    }

    /** @return array{default: string, rows: list<array{0: string, 1: bool, 2: bool}>} */
    protected function trackedState(): array
    {
        return [
            'default' => $this->default,
            'rows' => array_map(fn (string $code): array => [$code, in_array($code, $this->enabled, true), in_array($code, $this->public, true)], $this->offered($this->stored())),
        ];
    }

    private function load(): void
    {
        $stored = $this->stored(fresh: true);
        $codes = $this->offered($stored);
        $this->default = $stored['default'];
        $this->enabled = array_values(array_filter($codes, static fn (string $code): bool => ($stored['rows'][$code]['on'] ?? false)));
        $this->public = array_values(array_filter($codes, static fn (string $code): bool => ($stored['rows'][$code]['pub'] ?? false)));
        $this->rememberSaved();
    }

    /** @var array{default: string, rows: array<string, array{on: bool, pub: bool}>}|null the saved rows, read once per request */
    private ?array $storedMemo = null;

    /**
     * The saved languages of the site (code → on/pub) and its default.
     *
     * @return array{default: string, rows: array<string, array{on: bool, pub: bool}>}
     */
    private function stored(bool $fresh = false): array
    {
        if ($this->storedMemo !== null && ! $fresh) {
            return $this->storedMemo;
        }
        $default = '';
        $rows = [];
        foreach (SiteLocale::query()->orderBy('id')->get(['locale', 'is_default', 'enabled', 'is_public']) as $row) {
            $code = (string) $row->locale;
            $rows[$code] = ['on' => (bool) $row->enabled, 'pub' => (bool) $row->enabled && (bool) $row->is_public];
            if ($row->is_default && $default === '') {
                $default = $code;
            }
        }

        return $this->storedMemo = ['default' => $default !== '' ? $default : app(SiteLocales::class)->default(), 'rows' => $rows];
    }

    /**
     * The rows the screen lists: the platform's content languages, then any the site already has
     * outside that list.
     *
     * @param  array{default: string, rows: array<string, array{on: bool, pub: bool}>}  $stored
     * @return list<string>
     */
    private function offered(array $stored): array
    {
        $platform = array_values(array_filter((array) config('locales.content', []), 'is_string'));

        return array_values(array_unique([...$platform, ...array_map('strval', array_keys($stored['rows']))]));
    }

    private function offers(string $code): bool
    {
        return in_array($code, $this->offered($this->stored()), true);
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function ordered(array $codes): array
    {
        return array_values(array_intersect($this->offered($this->stored()), $codes));
    }

    private function switchOff(string $code): void
    {
        $this->enabled = array_values(array_diff($this->enabled, [$code]));
        $this->public = array_values(array_diff($this->public, [$code]));
    }

    private function lockedByPlan(string $code): bool
    {
        return ! in_array($code, $this->enabled, true) && count($this->enabled) >= $this->limit();
    }

    private function limit(): int
    {
        return max(1, (int) app(Entitlement::class)->featureValue('locales.max'));
    }

    private function limitNotice(): void
    {
        $key = app(UpgradeInvitation::class)->isOn() ? 'admin.lng.n_limit_up' : 'admin.lng.n_limit_off';
        $this->notify(__($key, ['max' => $this->limit()]), 'info');
        // The banner's button takes the focus, as the board does: the way to more languages.
        $this->dispatch('lng-limit');
    }

    private function ask(string $kind, string $code): void
    {
        $this->askKind = $kind;
        $this->askCode = $code;
        $this->asking = true;
    }

    private function label(string $code): string
    {
        $labels = (array) config('locales.labels', []);

        return is_string($labels[$code] ?? null) ? $labels[$code] : strtoupper($code);
    }

    /**
     * The home page preview of each hidden language that has one (the admin preview: only a
     * signed-in member sees it), as code → address. Two queries for all of them.
     *
     * @param  list<string>  $codes
     * @return array<string, string>
     */
    private function previews(array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $homes = SiteRoute::query()->whereIn('locale', $codes)->where('path', '/')->whereNotNull('entry_id')
            ->pluck('entry_id', 'locale');
        $ulids = $homes->isEmpty() ? collect() : Entry::query()->whereIn('id', $homes->values()->all())->pluck('ulid', 'id');

        $out = [];
        foreach ($homes as $locale => $entryId) {
            $ulid = $ulids->get(Cast::int($entryId));
            if (is_string($ulid)) {
                $out[(string) $locale] = route('admin.entries.preview', ['ulid' => $ulid, 'locale' => (string) $locale]);
            }
        }

        return $out;
    }

    /** A permalink section's name as Settings › Permalinks shows it, never its raw key. */
    private function sectionLabel(?string $key): string
    {
        return match ($key) {
            null, 'pages' => __('admin.pl.pages'),
            'products' => __('admin.pl.products'),
            default => ContentSection::query()->firstWhere('key', $key)?->localizedLabel() ?? __('admin.pl.pages'),
        };
    }

    private function notify(string $message, string $tone = 'success'): void
    {
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }
}
