<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\GeneralSettingsStore;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\Province;
use App\Modules\Tenancy\Models\Ward;
use App\Modules\Tenancy\Support\BusinessHours;
use App\Modules\Theme\Chrome\StorefrontChrome;
use DateTimeImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\MessageBag;
use Illuminate\View\View;
use Livewire\Attributes\Authorize;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Thông tin chung": the site's name, legal details, contact and address, opening hours, region
 * (timezone, day format) and social links. Shared values sit in site_profiles, the per-language
 * ones in site_profile_translations (one row per language), the timezone and day format in
 * site_settings group general; GeneralSettingsStore reads and writes them. The page edits a
 * draft and saves it in one step; a field is checked when it is left and every field again on
 * save. Writes are settings.manage-gated like every site-wide settings tab.
 */
#[Layout('admin::layouts.app')]
final class GeneralSettings extends Component
{
    /** Longest value a text column takes. */
    public const int MAX = 255;

    /** The timezones offered (the design's list), IANA name → label key. */
    public const array ZONES = ['Asia/Ho_Chi_Minh', 'Asia/Bangkok', 'Asia/Singapore', 'Asia/Shanghai', 'Asia/Tokyo',
        'Australia/Sydney', 'Europe/Paris', 'Europe/London', 'America/New_York'];

    /**
     * The groups of the page and the fields of each, in order: [key, kind, per-language, required].
     * Kinds: text, textarea, select, toggle, note.
     *
     * @var array<string, list<array{string, string, bool, bool}>>
     */
    public const array GROUPS = [
        'name' => [['name', 'text', true, true], ['tagline', 'text', true, false], ['description', 'textarea', true, false]],
        'legal' => [['legalName', 'text', false, true], ['taxCode', 'text', false, false], ['representative', 'text', false, false],
            ['licenseNo', 'text', false, false], ['licenseDate', 'text', false, false], ['license_text', 'text', true, false],
            ['moitOn', 'toggle', false, false], ['moitUrl', 'text', false, false]],
        'contact' => [['phone', 'text', false, false], ['email', 'text', false, false], ['provinceId', 'select', false, false],
            ['wardId', 'select', false, false], ['address_line', 'textarea', true, false]],
        'hours' => [['days', 'select', false, false], ['opens', 'select', false, false], ['closes', 'select', false, false], ['hours_note', 'text', true, false]],
        'region' => [['timezone', 'select', false, false], ['dateFormat', 'select', false, false], ['region_note', 'note', false, false]],
        'social' => [['facebook', 'text', false, false], ['youtube', 'text', false, false], ['tiktok', 'text', false, false],
            ['zalo', 'text', false, false], ['social_note', 'note', false, false]],
    ];

    /** @var array<string, array<string, string>> per-language values, locale → field → text */
    public array $i18n = [];

    /** @var array<string, string|bool> the values every language shares */
    public array $form = [];

    /** The language whose own values are being edited. */
    public string $lang = '';

    /**
     * The draft as it was loaded or last saved: the bar counts the fields that differ.
     *
     * @var array{i18n: array<string, array<string, string>>, shared: array<string, string|bool>}
     */
    #[Locked]
    public array $saved = ['i18n' => [], 'shared' => []];

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
        $this->apply(app(GeneralSettingsStore::class)->load());
    }

    public function setLang(string $locale): void
    {
        if (in_array($locale, $this->locales(), true)) {
            $this->lang = $locale;
        }
    }

    public function toggleMoit(): void
    {
        $this->form['moitOn'] = ($this->form['moitOn'] ?? false) !== true;
        if ($this->form['moitOn'] === false) {
            $this->resetErrorBag('form.moitUrl');
        }
    }

    /** A field was left: check it now (and the field it is checked against), so the error shows at once. */
    public function updated(string $name): void
    {
        if ($name === 'form.provinceId') {
            $this->form['wardId'] = '';   // the wards listed belong to the province chosen
            $this->resetErrorBag('form.wardId');
        }
        $related = match ($name) {
            'form.opens', 'form.closes', 'form.days' => ['form.closes'],
            'form.provinceId' => ['form.provinceId'],
            'form.wardId' => ['form.wardId'],
            default => [$name],
        };
        $errors = $this->allErrors();
        foreach ($related as $field) {
            $this->resetErrorBag($field);
            if (isset($errors[$field])) {
                $this->addError($field, $errors[$field]);
            }
        }
        if ($name === 'lang' && ! in_array($this->lang, $this->locales(), true)) {
            $this->lang = app(SiteLocale::class)->default();
        }
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
            $this->notify(__('admin.gs.toast_invalid', ['count' => count($errors)]), 'error');
            $this->dispatch('gs-first-error', group: $this->groupOf((string) array_key_first($errors)));

            return;
        }

        $shared = $this->sharedDraft();
        $date = DateTimeImmutable::createFromFormat('!d/m/Y', Cast::string($shared['licenseDate']));
        $shared['licenseDate'] = $date === false ? '' : $date->format('Y-m-d');
        app(GeneralSettingsStore::class)->save($this->i18nDraft(), $shared);
        app(SiteTime::class)->forget();

        $this->apply(app(GeneralSettingsStore::class)->load());
        $this->notify(__('admin.gs.toast_saved'));
    }

    /** Back to the saved values. */
    public function resetChanges(): void
    {
        $this->apply(app(GeneralSettingsStore::class)->load());
        $this->resetErrorBag();
        $this->notify(__('admin.gs.toast_reset'));
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
        if (! in_array($this->lang, $this->locales(), true)) {
            $this->lang = $siteLocale->default();
        }
        $form = $this->sharedDraft();
        $provinceId = Cast::string($form['provinceId']);
        $provinces = Province::query()->whereNull('valid_to')->orderBy('code')->get(['id', 'name']);
        $province = $provinces->firstWhere('id', (int) $provinceId);
        $wards = $province === null ? collect() : Ward::query()->where('province_id', $province->id)->whereNull('valid_to')->orderBy('name')->get(['id', 'name']);

        $zones = self::ZONES;
        $zone = Cast::string($form['timezone']);
        if (! in_array($zone, $zones, true) && $zone !== '') {
            $zones[] = $zone;   // a zone set before this list keeps showing as chosen
        }

        // Problems per group: the group heads show "N lỗi", the banner and the bar the total.
        $groupErrors = [];
        $bag = $this->getErrorBag();
        foreach ($bag instanceof MessageBag ? $bag->keys() : [] as $key) {
            $group = $this->groupOf(Cast::string($key));
            $groupErrors[$group] = ($groupErrors[$group] ?? 0) + 1;
        }

        $view = view('admin::livewire.general-settings', [
            'groupErrors' => $groupErrors,
            'form' => $form,
            'i18nDraft' => $this->i18nDraft(),
            'defaultLocale' => $siteLocale->default(),
            'locales' => $this->locales(),
            'changes' => $this->changeCount(),
            'provinces' => $provinces,
            'province' => $province,
            'wards' => $wards,
            'zones' => $zones,
        ]);
        $view->title(__('admin.gs.title'));

        return $view;
    }

    /** The group a field (error-bag key) sits in. */
    private function groupOf(string $key): string
    {
        $field = (string) preg_replace('/\A(form\.|i18n\.[^.]+\.)/', '', $key);
        foreach (self::GROUPS as $group => $fields) {
            foreach ($fields as $f) {
                if ($f[0] === $field) {
                    return $group;
                }
            }
        }

        return 'name';
    }

    /** @param  array{i18n: array<string, array<string, string>>, shared: array<string, string|bool>}  $loaded */
    private function apply(array $loaded): void
    {
        $this->i18n = $loaded['i18n'];
        $this->form = $loaded['shared'];
        $this->saved = $loaded;
    }

    /** @return list<string> */
    private function locales(): array
    {
        return app(GeneralSettingsStore::class)->locales();
    }

    /**
     * The per-language draft cast to strings, for the site's languages only.
     *
     * @return array<string, array<string, string>>
     */
    private function i18nDraft(): array
    {
        $out = [];
        foreach ($this->locales() as $locale) {
            $row = Cast::arr($this->i18n[$locale] ?? null);
            foreach (array_keys(GeneralSettingsStore::LOCALIZED) as $field) {
                $out[$locale][$field] = trim(Cast::string($row[$field] ?? null));
            }
        }

        return $out;
    }

    /**
     * The shared draft cast to its shape (strings, the switch a bool).
     *
     * @return array<string, string|bool>
     */
    private function sharedDraft(): array
    {
        $out = [];
        foreach (self::GROUPS as $fields) {
            foreach ($fields as $field) {
                $key = $field[0];
                $kind = $field[1];
                if ($field[2] || $kind === 'note') {
                    continue;
                }
                $out[$key] = $kind === 'toggle'
                    ? ($this->form[$key] ?? false) === true
                    : trim(Cast::string($this->form[$key] ?? null));
            }
        }

        return $out;
    }

    /** How many fields differ from the saved values. */
    private function changeCount(): int
    {
        $n = 0;
        foreach ($this->sharedDraft() as $key => $value) {
            $n += $value !== ($this->saved['shared'][$key] ?? ($value === true || $value === false ? false : '')) ? 1 : 0;
        }
        foreach ($this->i18nDraft() as $locale => $row) {
            foreach ($row as $field => $value) {
                $n += $value !== ($this->saved['i18n'][$locale][$field] ?? '') ? 1 : 0;
            }
        }

        return $n;
    }

    /**
     * Every problem of the draft, field (error-bag key) → plain message, in the page's order.
     *
     * @return array<string, string>
     */
    private function allErrors(): array
    {
        $errors = [];
        $default = app(SiteLocale::class)->default();
        $s = $this->sharedDraft();
        $i18n = $this->i18nDraft();
        $long = __('admin.gs.err_long', ['max' => self::MAX]);
        $tampered = __('admin.gs.err_tampered');

        foreach ($this->sent() as $key => $value) {
            if (! is_string($value) && ! ($key === 'moitOn' && is_bool($value))) {
                $errors['form.'.$key] = $tampered;
            }
        }
        /** @var array<mixed> $sentI18n the raw payload: a tampered request can send anything */
        $sentI18n = $this->i18n;
        foreach ($sentI18n as $locale => $row) {
            if (! in_array($locale, $this->locales(), true) || ! is_array($row)) {
                $errors['i18n'] = $tampered;
            }
        }

        foreach (self::GROUPS as $fields) {
            foreach ($fields as [$key, $kind, $perLang, $required]) {
                if ($kind === 'note' || $kind === 'toggle') {
                    continue;
                }
                if ($perLang) {
                    foreach ($i18n as $locale => $row) {
                        $bag = "i18n.{$locale}.{$key}";
                        if (mb_strlen($row[$key]) > self::MAX) {
                            $errors[$bag] = $long;
                        } elseif ($required && $locale === $default && $row[$key] === '') {
                            $errors[$bag] = __('admin.gs.req.'.$key);
                        }
                    }

                    continue;
                }
                $bag = 'form.'.$key;
                if (isset($errors[$bag])) {
                    continue;
                }
                $value = Cast::string($s[$key]);
                if (mb_strlen($value) > self::MAX) {
                    $errors[$bag] = $long;
                } elseif ($required && $value === '') {
                    $errors[$bag] = __('admin.gs.req.'.$key);
                } elseif (($message = $this->check($key, $value, $s)) !== null) {
                    $errors[$bag] = $message;
                }
            }
        }

        return $errors;
    }

    /** @param  array<string, string|bool>  $s */
    private function check(string $key, string $value, array $s): ?string
    {
        $url = static fn (string $v): bool => preg_match('~\Ahttps?://[^\s./]+\.[^\s]{2,}\z~i', $v) === 1 && filter_var($v, FILTER_VALIDATE_URL) !== false;

        return Cast::stringOrNull(match ($key) {
            'taxCode' => $value === '' || preg_match('/\A\d{10}(-\d{3})?\z/', $value) === 1 ? null : __('admin.gs.err.taxCode'),
            'licenseDate' => $value === '' || self::isDate($value) ? null : __('admin.gs.err.licenseDate'),
            'moitUrl' => ($s['moitOn'] ?? false) !== true ? null
                : ($value === '' ? __('admin.gs.req.moitUrl') : ($url($value) ? null : __('admin.gs.err.url'))),
            'phone' => $value === '' || self::isPhone($value) ? null : __('admin.gs.err.phone'),
            'email' => $value === '' || (preg_match('/\A[^\s@]+@[^\s@]+\.[a-z]{2,}\z/i', $value) === 1 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) ? null : __('admin.gs.err.email'),
            'facebook', 'youtube', 'tiktok' => $value === '' || $url($value) ? null : __('admin.gs.err.url'),
            'provinceId' => $value === '' || (ctype_digit($value) && Province::query()->whereNull('valid_to')->whereKey((int) $value)->exists()) ? null : __('admin.gs.err.provinceId'),
            'wardId' => $this->checkWard($value, Cast::string($s['provinceId'] ?? null)),
            'days' => $value === '' || array_key_exists($value, BusinessHours::DAYS) ? null : __('admin.gs.err_tampered'),
            'opens' => in_array($value, BusinessHours::TIMES, true) ? null : __('admin.gs.err_tampered'),
            'closes' => ! in_array($value, BusinessHours::TIMES, true) ? __('admin.gs.err_tampered')
                : (in_array($s['days'] ?? '', ['', 'appointment'], true) || strcmp($value, Cast::string($s['opens'] ?? null)) > 0
                    ? null : __('admin.gs.err.closes', ['opens' => Cast::string($s['opens'] ?? null)])),
            'timezone' => in_array($value, self::ZONES, true) || $value === app(SiteTime::class)->zone() ? null : __('admin.gs.err_tampered'),
            'dateFormat' => in_array($value, SiteTime::DATE_FORMATS, true) ? null : __('admin.gs.err_tampered'),
            'zalo' => $value === '' || StorefrontChrome::zalo($value) !== null ? null : __('admin.gs.err.zalo'),
            default => null,
        });
    }

    /** A ward must be one of the chosen province's wards (checked here, never trusted from the list). */
    private function checkWard(string $ward, string $province): ?string
    {
        if ($ward === '') {
            return null;
        }
        if ($province === '' || ! ctype_digit($province)) {
            return Cast::string(__('admin.gs.err.wardFirst'));
        }
        $owner = Province::query()->whereNull('valid_to')->whereKey((int) $province)->first(['id', 'name']);
        if ($owner === null) {
            return Cast::string(__('admin.gs.err.wardFirst'));
        }

        return ctype_digit($ward) && Ward::query()->where('province_id', $owner->id)->whereNull('valid_to')->whereKey((int) $ward)->exists()
            ? null
            : Cast::string(__('admin.gs.err.wardId', ['province' => $owner->name]));
    }

    /**
     * A Vietnamese number: 0 or +84 first, spaces and dots allowed, and at least 8 digits in its
     * national form (a leading 84 counts as the 0 it stands for: "+84 123 456" is too short).
     */
    private static function isPhone(string $value): bool
    {
        $national = (string) preg_replace('/\A84/', '0', (string) preg_replace('/\D/', '', $value));

        return preg_match('/\A(0|\+84)[0-9\s.]{8,13}\z/', $value) === 1 && strlen($national) >= 8;
    }

    /** A real day written dd/mm/yyyy. */
    private static function isDate(string $value): bool
    {
        if (preg_match('~\A(0[1-9]|[12]\d|3[01])/(0[1-9]|1[0-2])/((19|20)\d{2})\z~', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[1], (int) $m[3]);
    }

    /**
     * The shared values as the browser sent them: a tampered request can put anything there.
     *
     * @return array<mixed>
     */
    private function sent(): array
    {
        return $this->form;
    }

    private function notify(string $message, string $tone = 'success'): void
    {
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }
}
