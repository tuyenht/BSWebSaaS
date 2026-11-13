<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\Settings\FloatingContactInput;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Catalog\Support\ProductIndexLink;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteIdentity;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Chrome\ContactIcons;
use App\Modules\Theme\Chrome\FloatingContact;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Authorize;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Nút liên hệ nổi": the site's floating contact buttons or phone bottom bar, one site setting
 * (site_settings group `storefront`, key `floating_contact`) that the storefront prints on every
 * page (FloatingContact normalizes it there). The page edits a draft and saves it in one step;
 * values are checked when a field is left and again on save, with the same rules the storefront
 * applies, so what is saved is what the site shows. Hover labels are kept per language
 * ({locale: text}); a language left blank shows the channel's own wording in that language.
 * Writes are settings.manage-gated like every site-wide settings tab.
 */
#[Layout('admin::layouts.app')]
final class FloatingContactSettings extends Component
{
    /** Colours offered for the "Mua hàng" button (`brand` = the site's brand colour). */
    public const array BUY_SWATCHES = ['#f7b84b', 'brand', '#16223f', '#0ab39c', '#f06548', '#ffffff'];

    /** New channels of the site's own take the first colour not yet used. */
    private const array CUSTOM_COLORS = ['#7c3aed', '#0ea5e9', '#f59e0b', '#14b8a6', '#ec4899', '#64748b', '#84cc16', '#1e40af'];

    public bool $enabled = false;

    public string $theme = 'vertical';

    public string $position = 'right';

    public int $offsetX = FloatingContact::DEFAULT_OFFSET_X;

    public int $offsetY = FloatingContact::DEFAULT_OFFSET_Y;

    public bool $buyOn = false;

    /**
     * The board-style phone bar a content pack may switch on (no control for it in the form yet):
     * kept as stored and written back unchanged, so saving the form never drops it.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $phoneBar = [];

    public string $buyColor = FloatingContact::BUY_COLOR;

    /**
     * The newer fields of the setting (layout, folding of round buttons on a phone, bar tone, click
     * counting) have no control in the form yet: kept as stored and written back, so saving the form
     * never drops them. `$loadedTheme` and `$loadedBarOn` remember what the layout was read from, so a
     * change of the theme made in the form still decides the layout.
     */
    #[Locked]
    public string $layout = 'vertical';

    #[Locked]
    public bool $dial = true;

    #[Locked]
    public string $barTone = 'auto';

    #[Locked]
    public bool $track = true;

    #[Locked]
    public string $loadedTheme = 'vertical';

    #[Locked]
    public bool $loadedBarOn = false;

    /**
     * Whether the site has a product list for "Mua hàng" to open in the language being previewed:
     * without one the switch is kept but the button is shown nowhere on those pages (the preview
     * draws what the site draws in that language). Worked out again when the language changes.
     */
    #[Locked]
    public bool $hasShop = false;

    /** @var list<array{key: string, on: bool, value: string, color: string, icon: string, name: string, tips: array<string, string>}> */
    public array $channels = [];

    /** The language whose hover labels are being edited. */
    public string $lang = '';

    public bool $presetMenu = false;

    /** Fingerprint of the saved setting: the page shows "Chưa lưu" while the draft differs. */
    public string $savedSig = '';

    public string $toast = '';

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    public function boot(): void
    {
        // Defense-in-depth: the livewire/update endpoint bypasses route middleware, so
        // re-check the site-settings permission on every request (mount + every action).
        abort_unless(Gate::allows('settings.manage'), 403);
    }

    public function mount(): void
    {
        $this->lang = app(SiteLocale::class)->default();
        $this->hasShop = $this->shopIn($this->lang);
        $this->load(app(SettingsRepository::class)->get('storefront', 'floating_contact'));
        $this->savedSig = $this->signature();
    }

    public function toggleEnabled(): void
    {
        $this->enabled = ! $this->enabled;
    }

    public function setTheme(string $theme): void
    {
        if (in_array($theme, FloatingContact::THEMES, true)) {
            $this->theme = $theme;
        }
    }

    public function setPosition(string $position): void
    {
        if (in_array($position, FloatingContact::POSITIONS, true)) {
            $this->position = $position;
        }
    }

    public function setLang(string $locale): void
    {
        if (in_array($locale, $this->locales(), true) && $locale !== $this->lang) {
            $this->lang = $locale;
            $this->hasShop = $this->shopIn($locale);
        }
    }

    public function toggleChannel(string $key): void
    {
        $i = $this->indexOf($key);
        if ($i !== null) {
            $this->channels[$i]['on'] = ! $this->rows()[$i]['on'];
            $this->checkChannel($i);
        }
    }

    /** Move a channel one place up (-1) or down (+1). */
    public function move(string $key, int $step): void
    {
        $i = $this->indexOf($key);
        if ($i === null) {
            return;
        }
        $j = $i + ($step < 0 ? -1 : 1);
        if (! isset($this->channels[$j])) {
            return;
        }
        $channels = $this->channels;
        [$channels[$i], $channels[$j]] = [$channels[$j], $channels[$i]];
        $this->channels = $channels;
        $this->resetErrorBag();
    }

    /** Drag and drop (wire:sort): put the channel at $position. */
    public function sortChannel(string $key, int $position): void
    {
        $i = $this->indexOf($key);
        if ($i === null) {
            return;
        }
        $row = array_splice($this->channels, $i, 1);
        array_splice($this->channels, max(0, min($position, count($this->channels))), 0, $row);
        $this->resetErrorBag();
    }

    public function deleteChannel(string $key): void
    {
        $i = $this->indexOf($key);
        if ($i !== null && ! in_array($key, FloatingContact::CORE, true)) {
            array_splice($this->channels, $i, 1);
            $this->resetErrorBag();
        }
    }

    public function togglePresetMenu(): void
    {
        $this->presetMenu = ! $this->presetMenu;
    }

    public function addPreset(string $key): void
    {
        if (! in_array($key, FloatingContact::PRESETS, true) || $this->indexOf($key) !== null || count($this->channels) >= FloatingContact::MAX_CHANNELS) {
            return;
        }
        $this->channels[] = $this->row($key, true);
        $this->presetMenu = false;
    }

    public function addCustom(): void
    {
        if (count($this->channels) >= FloatingContact::MAX_CHANNELS) {
            return;
        }
        $used = array_map(fn (array $c): string => $c['color'] !== '' ? $c['color'] : strtolower(FloatingContact::CHANNELS[$c['key']]['color'] ?? ''), $this->rows());
        $color = current(array_diff(self::CUSTOM_COLORS, $used)) ?: self::CUSTOM_COLORS[count($this->channels) % count(self::CUSTOM_COLORS)];
        $n = 1;
        while ($this->indexOf('c'.$n) !== null) {
            $n++;
        }
        $this->channels[] = ['key' => 'c'.$n, 'on' => true, 'value' => '', 'color' => $color, 'icon' => 'ri-global-line', 'name' => '', 'tips' => []];
    }

    public function pickIcon(string $key, string $icon): void
    {
        $i = $this->indexOf($key);
        if ($i !== null && FloatingContact::typeOf($key) === 'custom' && in_array($icon, ContactIcons::CUSTOM, true)) {
            $this->channels[$i]['icon'] = $icon;
            $this->resetErrorBag("channels.{$i}.icon");
        }
    }

    public function toggleBuy(): void
    {
        $this->buyOn = ! $this->buyOn;
    }

    public function setBuyColor(string $color): void
    {
        if (in_array($color, self::BUY_SWATCHES, true) || FloatingContact::color($color) !== null) {
            $this->buyColor = $color === 'brand' ? 'brand' : strtolower($color);
        }
    }

    /** A field was left: check that channel's fields now, so the error shows beside it at once. */
    public function updated(string $name): void
    {
        if (preg_match('/\Achannels\.(\d+)\./', $name, $m) === 1) {
            $this->checkChannel((int) $m[1]);
        }
        if ($name === 'lang' && in_array($this->lang, $this->locales(), true)) {
            $this->hasShop = $this->shopIn($this->lang);
        }
        $this->offsetX = FloatingContact::offset($this->offsetX, FloatingContact::MAX_OFFSET_X, FloatingContact::DEFAULT_OFFSET_X);
        $this->offsetY = FloatingContact::offset($this->offsetY, FloatingContact::MAX_OFFSET_Y, FloatingContact::DEFAULT_OFFSET_Y);
    }

    #[Authorize('settings.manage')]
    public function save(): void
    {
        $this->resetErrorBag();
        $errors = $this->allErrors();
        foreach ($errors as $field => $message) {
            $this->addError($field, $message);
        }
        if ($errors !== []) {
            $bad = count(array_unique(array_map(fn (string $f): string => (string) preg_replace('/\A(channels\.\d+)\..*\z/', '$1', $f), array_keys($errors))));
            $this->notify(__('admin.fc.toast_invalid', ['count' => $bad]), 'error');
            $this->dispatch('fc-first-error');

            return;
        }

        app(SettingsRepository::class)->put('storefront', 'floating_contact', $this->stored());
        $this->savedSig = $this->signature();
        $this->notify(__('admin.fc.toast_saved'));
    }

    /** Back to the saved setting. */
    public function resetChanges(): void
    {
        $this->load(app(SettingsRepository::class)->get('storefront', 'floating_contact'));
        $this->savedSig = $this->signature();
        $this->resetErrorBag();
        $this->notify(__('admin.fc.toast_reset'));
    }

    public function dismissToast(?int $seq = null): void
    {
        if ($seq === null || $seq === $this->toastSeq) {
            $this->toast = '';
        }
    }

    public function render(): View
    {
        $siteLocale = app(SiteLocale::class);
        $locales = $this->locales();
        if (! in_array($this->lang, $locales, true)) {
            $this->lang = $siteLocale->default();
            $this->hasShop = $this->shopIn($this->lang);
        }

        // One cast copy of the rows for the whole render: the view draws from it (never the raw
        // property), and the fingerprint and the preview are built from the same copy.
        $rows = $this->rows();
        $stored = $this->shape($rows);

        $view = view('admin::livewire.floating-contact-settings', [
            'rows' => $rows,
            'dirty' => md5((string) json_encode($stored)) !== $this->savedSig,
            'defaultLocale' => $siteLocale->default(),
            'locales' => $locales,
            'publicLocales' => $siteLocale->public(),
            // The draft as the site would show it, in the language being edited; a channel switched on
            // but not filled in yet keeps its place as an unfinished slot (the site never shows it).
            'preview' => FloatingContact::preview(['enabled' => true] + $stored, $this->lang, $this->hasShop ? '#' : null),
        ]);
        $view->title(__('admin.fc.title'));

        return $view;
    }

    /**
     * The draft in the stored shape.
     *
     * @return array<string, mixed>
     */
    public function stored(): array
    {
        return $this->shape($this->rows());
    }

    /**
     * @param  array<int|string, array{key: string, on: bool, value: string, color: string, icon: string, name: string, tips: array<string, string>}>  $rows
     * @return array<string, mixed>
     */
    private function shape(array $rows): array
    {
        return [
            'enabled' => $this->enabled,
            'theme' => $this->theme,
            'position' => $this->position,
            'offset_x' => $this->offsetX,
            'offset_y' => $this->offsetY,
            'layout' => $this->layoutToStore(),
            'dial' => $this->dial,
            'bar_tone' => $this->barTone,
            'track' => $this->track,
            'buy' => ['enabled' => $this->buyOn, 'color' => $this->buyColor],
            ...($this->phoneBar === [] ? [] : ['phone_bar' => $this->phoneBar]),
            'channels' => array_map(FloatingContactInput::storedRow(...), $rows),
        ];
    }

    /** The loaded layout while the theme and phone-bar switch are as loaded; else the layout those two now give. */
    private function layoutToStore(): string
    {
        $barOn = ($this->phoneBar['enabled'] ?? false) === true;
        if ($this->theme === $this->loadedTheme && $barOn === $this->loadedBarOn) {
            return $this->layout;
        }

        return FloatingContact::layout(['theme' => $this->theme, 'phone_bar' => ['enabled' => $barOn]]);
    }

    /**
     * The channel rows cast to their shape, keyed as sent (so `channels.{i}` still names the row):
     * a tampered request can put any type in a row, or a string for a whole row, and the draft is
     * fingerprinted, previewed and drawn on every render — save() then refuses it with the plain
     * message. Everything that reads a row goes through this copy, never the raw property.
     *
     * @return array<int|string, array{key: string, on: bool, value: string, color: string, icon: string, name: string, tips: array<string, string>}>
     */
    private function rows(): array
    {
        return array_map(FloatingContactInput::castRow(...), $this->sent());
    }

    /** @return list<string> */
    private function locales(): array
    {
        $enabled = app(SiteLocale::class)->enabled();

        return $enabled !== [] ? $enabled : [app(SiteLocale::class)->default()];
    }

    /** @param  array<string, mixed>|null  $raw */
    private function load(?array $raw): void
    {
        if ($raw === null) {
            $this->loadDefaults();

            return;
        }

        $this->enabled = ($raw['enabled'] ?? false) === true;
        $this->theme = in_array($raw['theme'] ?? null, FloatingContact::THEMES, true) ? Cast::string($raw['theme']) : 'vertical';
        $this->position = in_array($raw['position'] ?? null, FloatingContact::POSITIONS, true) ? Cast::string($raw['position']) : 'right';
        $this->offsetX = FloatingContact::offset($raw['offset_x'] ?? null, FloatingContact::MAX_OFFSET_X, FloatingContact::DEFAULT_OFFSET_X);
        $this->offsetY = FloatingContact::offset($raw['offset_y'] ?? null, FloatingContact::MAX_OFFSET_Y, FloatingContact::DEFAULT_OFFSET_Y);
        $this->phoneBar = isset($raw['phone_bar']) ? FloatingContactInput::storedPhoneBar($raw['phone_bar'], $this->locales()) : [];
        $this->layout = FloatingContact::layout($raw);
        $this->dial = FloatingContact::dial($raw);
        $this->barTone = FloatingContact::barTone($raw);
        $this->track = FloatingContact::track($raw);
        $this->loadedTheme = $this->theme;
        $this->loadedBarOn = ($this->phoneBar['enabled'] ?? false) === true;
        $buy = Cast::arr($raw['buy'] ?? null);
        $this->buyOn = ($buy['enabled'] ?? false) === true;
        $color = Cast::string($buy['color'] ?? null);
        $this->buyColor = in_array($color, self::BUY_SWATCHES, true) || FloatingContact::color($color) !== null ? $color : FloatingContact::BUY_COLOR;

        $this->channels = [];
        $seen = [];
        $locales = $this->locales();
        foreach (array_slice(array_values(Cast::arr($raw['channels'] ?? null)), 0, FloatingContact::MAX_CHANNELS) as $c) {
            $c = Cast::arr($c);
            $key = Cast::string($c['key'] ?? null);
            if (FloatingContact::typeOf($key) === null || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $tips = [];
            // Labels of a language the site no longer has are dropped: they cannot be edited, and
            // keeping them would make every save look tampered with.
            foreach (Cast::arr($c['tips'] ?? null) as $lc => $tip) {
                if (is_string($tip) && in_array((string) $lc, $locales, true)) {
                    $tips[(string) $lc] = $tip;
                }
            }
            $this->channels[] = [
                'key' => $key,
                'on' => ($c['on'] ?? false) === true,
                'value' => Cast::string($c['value'] ?? null),
                'color' => FloatingContact::color($c['color'] ?? null) ?? '',
                // An icon no longer offered becomes the default one, which is what the site draws
                // for it anyway: kept, it would block the save of a row that may be folded away.
                'icon' => in_array($c['icon'] ?? null, ContactIcons::CUSTOM, true) ? Cast::string($c['icon']) : 'ri-global-line',
                'name' => Cast::string($c['name'] ?? null),
                'tips' => $tips,
            ];
        }
        // The built-in five are always listed (switched off when missing), so they can be turned on.
        foreach (FloatingContact::CORE as $key) {
            if (! isset($seen[$key])) {
                $this->channels[] = $this->row($key, false);
            }
        }
    }

    /**
     * A first visit: off, the call, Zalo, Messenger and email channels ready, SMS off; the site's
     * own phone (a mobile, landline or hotline) fills the call channel, Zalo only when it is a
     * mobile, and the site's email the email channel.
     */
    private function loadDefaults(): void
    {
        $brand = app(SiteIdentity::class)->for(app(SiteLocale::class)->default()) ?? [];
        $phone = FloatingContact::clean('phone', Cast::string($brand['phone'] ?? null)) ?? '';
        $zalo = FloatingContact::clean('zalo', Cast::string($brand['phone'] ?? null)) ?? '';
        $email = FloatingContact::clean('email', Cast::string($brand['email'] ?? null)) ?? '';

        $this->enabled = false;
        $this->theme = 'vertical';
        $this->position = 'right';
        $this->offsetX = FloatingContact::DEFAULT_OFFSET_X;
        $this->offsetY = FloatingContact::DEFAULT_OFFSET_Y;
        $this->buyOn = false;
        $this->buyColor = FloatingContact::BUY_COLOR;
        $this->phoneBar = [];
        $this->layout = 'vertical';
        $this->dial = true;
        $this->barTone = 'auto';
        $this->track = true;
        $this->loadedTheme = 'vertical';
        $this->loadedBarOn = false;
        $this->channels = [];
        foreach (FloatingContact::CORE as $key) {
            $row = $this->row($key, $key !== 'sms');
            $row['value'] = match ($key) {
                'phone' => $phone,
                'zalo' => $zalo,
                'email' => $email,
                default => '',
            };
            $this->channels[] = $row;
        }
    }

    /** @return array{key: string, on: bool, value: string, color: string, icon: string, name: string, tips: array<string, string>} */
    private function row(string $key, bool $on): array
    {
        return ['key' => $key, 'on' => $on, 'value' => '', 'color' => '', 'icon' => '', 'name' => '', 'tips' => []];
    }

    private function indexOf(string $key): ?int
    {
        // a row sent back as a non-array has no key of its own: never a target to write into
        foreach ($this->rows() as $i => $c) {
            if ($c['key'] === $key && is_int($i) && is_array($this->channels[$i] ?? null)) {
                return $i;
            }
        }

        return null;
    }

    private function checkChannel(int $i): void
    {
        $this->resetErrorBag(["channels.{$i}.value", "channels.{$i}.icon", "channels.{$i}.name", "channels.{$i}.color", "channels.{$i}.tips"]);
        foreach ($this->channelErrors($i) as $field => $message) {
            $this->addError($field, $message);
        }
    }

    /**
     * Every problem of the draft, field → plain message. A channel that is switched on and has a
     * value must pass its type's check; values (on or off), names and labels are capped; anything else (an unknown or
     * repeated channel, a value no control can produce) means the page was tampered with.
     *
     * @return array<string, string>
     */
    private function allErrors(): array
    {
        $errors = [];
        $tampered = ! in_array($this->theme, FloatingContact::THEMES, true)
            || ! in_array($this->position, FloatingContact::POSITIONS, true)
            || FloatingContact::offset($this->offsetX, FloatingContact::MAX_OFFSET_X, -1) === -1
            || FloatingContact::offset($this->offsetY, FloatingContact::MAX_OFFSET_Y, -1) === -1
            || ! (in_array($this->buyColor, self::BUY_SWATCHES, true) || FloatingContact::color($this->buyColor) !== null)
            || count($this->channels) > FloatingContact::MAX_CHANNELS;

        $keys = [];
        foreach ($this->sent() as $i => $c) {
            if (! is_array($c) || ! is_string($c['key'] ?? null) || FloatingContact::typeOf($c['key']) === null || isset($keys[$c['key']])
                || ! is_bool($c['on'] ?? null) || ! is_string($c['value'] ?? null) || ! is_string($c['color'] ?? null)
                || ! is_string($c['icon'] ?? null) || ! is_string($c['name'] ?? null) || ! is_array($c['tips'] ?? null)) {
                $tampered = true;

                continue;
            }
            $keys[$c['key']] = true;
            $errors += $this->channelErrors($i);
        }
        foreach (FloatingContact::CORE as $core) {
            $tampered = $tampered || ! isset($keys[$core]);
        }

        if ($tampered) {
            $errors['channels'] = __('admin.fc.err_tampered');
        }

        return $errors;
    }

    /**
     * The channel rows as the browser sent them: a tampered request can put anything there, so
     * the checks below test every field's type instead of trusting the property's shape.
     *
     * @return array<mixed>
     */
    private function sent(): array
    {
        return $this->channels;
    }

    /** @return array<string, string> */
    private function channelErrors(int $i): array
    {
        $c = $this->sent()[$i] ?? null;
        if (! is_array($c) || ! is_string($c['key'] ?? null) || ! is_string($c['value'] ?? null)) {
            return [];
        }
        $type = FloatingContact::typeOf($c['key']);
        if ($type === null) {
            return [];
        }

        $errors = [];
        $value = trim($c['value']);
        if (mb_strlen($value) > FloatingContact::MAX_VALUE) {
            $errors["channels.{$i}.value"] = __('admin.fc.err_long', ['max' => FloatingContact::MAX_VALUE]);
        } elseif ($value !== '' && ($c['on'] ?? false) === true && FloatingContact::clean($type, $value) === null) {
            $errors["channels.{$i}.value"] = __('admin.fc.err_value', ['how' => __('admin.fc.how.'.$type)]);
        }
        if (is_string($c['color'] ?? null) && $c['color'] !== '' && FloatingContact::color($c['color']) === null) {
            $errors["channels.{$i}.color"] = __('admin.fc.err_color');
        }
        if ($type === 'custom') {
            if (! in_array($c['icon'] ?? null, ContactIcons::CUSTOM, true)) {
                $errors["channels.{$i}.icon"] = __('admin.fc.err_icon');
            }
            if (mb_strlen(trim(Cast::string($c['name'] ?? null))) > FloatingContact::MAX_LABEL) {
                $errors["channels.{$i}.name"] = __('admin.fc.err_long', ['max' => FloatingContact::MAX_LABEL]);
            }
        }
        foreach (is_array($c['tips'] ?? null) ? $c['tips'] : [] as $lc => $tip) {
            if (! in_array($lc, $this->locales(), true) || ! is_string($tip)) {
                $errors["channels.{$i}.tips"] = __('admin.fc.err_tampered');
            } elseif (mb_strlen(trim($tip)) > FloatingContact::MAX_LABEL) {
                $errors["channels.{$i}.tips.{$lc}"] = __('admin.fc.err_long', ['max' => FloatingContact::MAX_LABEL]);
            }
        }

        return $errors;
    }

    private function shopIn(string $locale): bool
    {
        return app(ProductIndexLink::class)->for($locale) !== null;
    }

    private function signature(): string
    {
        return md5((string) json_encode($this->stored()));
    }

    private function notify(string $message, string $tone = 'success'): void
    {
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }
}
