<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\SeoFields;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\PlainText;
use App\Modules\Storefront\Support\BrandImages;
use App\Modules\Storefront\Support\SitemapRoutes;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteIdentity;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Theme\Theming\SiteSeo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Number;
use Illuminate\View\View;
use Livewire\Attributes\Authorize;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Hiện trên Google & mạng xã hội": per language the site's default Google title and description,
 * one title template for the pages without their own title, whether Google may find the site,
 * whether the sitemap is made, and a custom robots.txt. One `site_settings` row (group `seo`, key
 * `config`, the shape SiteSeo reads), written through SettingsRepository; the storefront follows it
 * (SiteSeo). The page edits a draft and saves it in one step, like "Thông tin chung": a field is
 * checked when it is left and every field again on save. Turning Google off asks first. Every
 * request is settings.manage-gated (boot) and so is every action.
 */
#[Layout('admin::layouts.app')]
final class SeoSettings extends Component
{
    /** "Tạo lại sitemap": this many times per site in WINDOW seconds. */
    public const int REGENERATE_TRIES = 3;

    public const int REGENERATE_WINDOW = 600;

    /**
     * The groups of the page and the fields of each, in order: [key, kind, per-language].
     * Kinds: text, textarea, preview, toggle, note, action, code.
     *
     * @var array<string, list<array{string, string, bool}>>
     */
    public const array GROUPS = [
        'google' => [['title', 'text', true], ['template', 'text', false], ['description', 'textarea', true], ['preview', 'preview', false]],
        'index' => [['indexable', 'toggle', false], ['domain_note', 'note', false], ['verify_note', 'note', false]],
        'advanced' => [['sitemap', 'toggle', false], ['regenerate', 'action', false], ['robots', 'code', false]],
    ];

    /** @var array<string, array{title: string, description: string}> per-language values */
    public array $i18n = [];

    /** @var array{template: string, indexable: bool, sitemap: bool, robots: string} the values every language shares */
    public array $form = ['template' => '', 'indexable' => true, 'sitemap' => true, 'robots' => ''];

    /** The language whose own values are being edited. */
    public string $lang = '';

    /** The "hide the site from Google?" question is open. */
    public bool $confirmHide = false;

    /**
     * The draft as it was loaded or last saved: the bar counts the fields that differ.
     *
     * @var array{i18n: array<string, array{title: string, description: string}>, shared: array{template: string, indexable: bool, sitemap: bool, robots: string}}
     */
    #[Locked]
    public array $saved = ['i18n' => [], 'shared' => ['template' => '', 'indexable' => true, 'sitemap' => true, 'robots' => '']];

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
        $this->apply($this->load());
    }

    #[Authorize('settings.manage')]
    public function setLang(string $locale): void
    {
        if (in_array($locale, $this->locales(), true)) {
            $this->lang = $locale;
        }
    }

    /** Turning Google back on needs no question; turning it off asks first (confirmHide). */
    #[Authorize('settings.manage')]
    public function toggleIndexable(): void
    {
        if ($this->sharedDraft()['indexable']) {
            $this->confirmHide = true;

            return;
        }
        $this->form['indexable'] = true;
    }

    #[Authorize('settings.manage')]
    public function hideFromGoogle(): void
    {
        $this->form['indexable'] = false;
        $this->confirmHide = false;
    }

    #[Authorize('settings.manage')]
    public function cancelHide(): void
    {
        $this->confirmHide = false;
    }

    #[Authorize('settings.manage')]
    public function toggleSitemap(): void
    {
        $this->form['sitemap'] = ! $this->sharedDraft()['sitemap'];
    }

    /**
     * "Tạo lại sitemap ngay": a big site's sitemap is split along a cached page plan, which is
     * dropped so the next read lists the site as it is now. A site that fits in one file is read
     * on every request: nothing to redo, and the message says so. A few times per site per window.
     */
    #[Authorize('settings.manage')]
    public function regenerateSitemap(): void
    {
        $key = 'seo-sitemap:'.app(CurrentSite::class)->id();
        if (RateLimiter::tooManyAttempts($key, self::REGENERATE_TRIES)) {
            $this->notify(__('admin.seoset.regen_wait', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))]), 'error');

            return;
        }
        // A refused try (the site hidden, the sitemap off) does not use up the window.
        if (! $this->saved['shared']['indexable']) {
            $this->notify(__('admin.seoset.regen_hidden'), 'error');

            return;
        }
        if (! $this->saved['shared']['sitemap']) {
            $this->notify(__('admin.seoset.regen_off'), 'error');

            return;
        }
        RateLimiter::hit($key, self::REGENERATE_WINDOW);
        $this->notify(app(SitemapRoutes::class)->forgetPlan() ? __('admin.seoset.regen_done') : __('admin.seoset.regen_fresh'));
    }

    /** A field was left: check it now, so the error shows at once. */
    public function updated(string $name): void
    {
        $errors = $this->allErrors();
        $this->resetErrorBag($name);
        if (isset($errors[$name])) {
            $this->addError($name, $errors[$name]);
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
            $this->dispatch('seo-first-error', group: $this->groupOf((string) array_key_first($errors)));

            return;
        }

        $settings = app(SettingsRepository::class);
        // Languages the site no longer offers keep what they had.
        $stored = SiteSeo::normalize($settings->get('seo', 'config'));
        $titles = $stored['titles'];
        $descriptions = $stored['descriptions'];
        foreach ($this->i18nDraft() as $locale => $row) {
            $titles[$locale] = $row['title'];
            $descriptions[$locale] = $row['description'];
        }
        $shared = $this->sharedDraft();
        $settings->put('seo', 'config', [
            'titles' => array_filter($titles, static fn (string $text): bool => $text !== ''),
            'descriptions' => array_filter($descriptions, static fn (string $text): bool => $text !== ''),
            'template' => $shared['template'],
            'indexable' => $shared['indexable'],
            'sitemap' => $shared['sitemap'],
            'robots' => $shared['robots'],
        ]);

        $this->apply($this->load());
        $this->notify(__('admin.seoset.toast_saved'));
    }

    /** Back to the saved values. */
    #[Authorize('settings.manage')]
    public function resetChanges(): void
    {
        $this->apply($this->load());
        $this->resetErrorBag();
        $this->confirmHide = false;
        $this->notify(__('admin.gs.toast_reset'));
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
        if (! in_array($this->lang, $this->locales(), true)) {
            $this->lang = $siteLocale->default();
        }

        $groupErrors = [];
        $bag = $this->getErrorBag();
        foreach ($bag instanceof MessageBag ? $bag->keys() : [] as $key) {
            $group = $this->groupOf(Cast::string($key));
            $groupErrors[$group] = ($groupErrors[$group] ?? 0) + 1;
        }

        $host = SeoFields::publicHost();
        $home = SeoFields::address($host, '/', $this->lang, true)['home'] ?? $host;
        // The share preview shows the image a shared link carries today: the share image, else the logo.
        $images = app(BrandImages::class)->for(app(SiteIdentity::class)->for($this->lang));
        $shareImage = $images['og_image'] ?? $images['logo'] ?? '';

        $view = view('admin::livewire.seo-settings', [
            'groupErrors' => $groupErrors,
            'form' => $this->sharedDraft(),
            'i18nDraft' => $this->i18nDraft(),
            'defaultLocale' => $siteLocale->default(),
            'locales' => $this->locales(),
            'changes' => $this->changeCount(),
            'previewConfig' => SeoFields::clientConfig(
                ['title' => "i18n.{$this->lang}.title", 'description' => "i18n.{$this->lang}.description"],
                ['base' => $home, 'home' => $home, 'homeSlug' => ''],
                words: ['emptyDescription' => __('admin.seoset.preview_no_description')],
            ),
            'home' => $home,
            'host' => $host,
            'shareImage' => preg_match('~\Ahttps?://~i', $shareImage) === 1 ? $shareImage : '',
            // The sentence is true only while the platform keeps its subdomains out of search engines.
            'platformHidden' => Cast::bool(config('tenancy.noindex_platform_subdomains')),
            'domainsLink' => $this->domainsLink(),
        ]);
        $view->title(__('admin.seoset.title'));

        return $view;
    }

    /** The domains page, when this person can open it there (otherwise the note stays plain text). */
    private function domainsLink(): ?string
    {
        if (! Route::has('admin.settings.domains') || ! Gate::allows('domains.view')
            || ! in_array('domains', app(Entitlement::class)->entitledModuleKeys(), true)) {
            return null;
        }

        return route('admin.settings.domains');
    }

    /** @return array{i18n: array<string, array{title: string, description: string}>, shared: array{template: string, indexable: bool, sitemap: bool, robots: string}} */
    private function load(): array
    {
        $stored = SiteSeo::normalize(app(SettingsRepository::class)->get('seo', 'config'));
        $i18n = [];
        foreach ($this->locales() as $locale) {
            $i18n[$locale] = ['title' => $stored['titles'][$locale] ?? '', 'description' => $stored['descriptions'][$locale] ?? ''];
        }

        return ['i18n' => $i18n, 'shared' => [
            'template' => $stored['template'], 'indexable' => $stored['indexable'], 'sitemap' => $stored['sitemap'], 'robots' => $stored['robots'],
        ]];
    }

    /** @param  array{i18n: array<string, array{title: string, description: string}>, shared: array{template: string, indexable: bool, sitemap: bool, robots: string}}  $loaded */
    private function apply(array $loaded): void
    {
        $this->i18n = $loaded['i18n'];
        $this->form = $loaded['shared'];
        $this->saved = $loaded;
    }

    /** @return list<string> */
    private function locales(): array
    {
        $locales = app(SiteLocale::class);

        return array_values(array_unique([$locales->default(), ...$locales->enabled()]));
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

        return 'google';
    }

    /**
     * The per-language draft as it would be stored: one line each (SeoFields::singleLine, as the
     * editors keep their Google title and description), for the site's languages only.
     *
     * @return array<string, array{title: string, description: string}>
     */
    private function i18nDraft(): array
    {
        $out = [];
        foreach ($this->locales() as $locale) {
            $row = Cast::arr($this->i18n[$locale] ?? null);
            $out[$locale] = [
                'title' => SeoFields::singleLine(Cast::string($row['title'] ?? null)),
                'description' => SeoFields::singleLine(Cast::string($row['description'] ?? null)),
            ];
        }

        return $out;
    }

    /**
     * The shared draft as it would be stored: the template one line, robots.txt plain text.
     *
     * @return array{template: string, indexable: bool, sitemap: bool, robots: string}
     */
    private function sharedDraft(): array
    {
        /** @var array<mixed> $sent the raw payload: a tampered request can send anything */
        $sent = $this->form;

        return [
            'template' => SeoFields::singleLine(Cast::string($sent['template'] ?? null)),
            'indexable' => ($sent['indexable'] ?? true) !== false,
            'sitemap' => ($sent['sitemap'] ?? true) !== false,
            'robots' => PlainText::lines(Cast::string($sent['robots'] ?? null)),
        ];
    }

    /** How many fields differ from the saved values. */
    private function changeCount(): int
    {
        $n = 0;
        foreach ($this->sharedDraft() as $key => $value) {
            $n += $value !== $this->saved['shared'][$key] ? 1 : 0;
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
        $tampered = Cast::string(__('admin.gs.err_tampered'));
        /** @var array<mixed> $sent */
        $sent = $this->form;
        foreach (['template' => 'string', 'robots' => 'string', 'indexable' => 'bool', 'sitemap' => 'bool'] as $key => $type) {
            $value = $sent[$key] ?? null;
            if ($value !== null && ($type === 'bool' ? ! is_bool($value) : ! is_string($value))) {
                $errors['form.'.$key] = $tampered;
            }
        }
        /** @var array<mixed> $sentI18n */
        $sentI18n = $this->i18n;
        foreach ($sentI18n as $locale => $row) {
            if (! in_array($locale, $this->locales(), true) || ! is_array($row)) {
                $errors['i18n'] = $tampered;

                continue;
            }
            foreach (['title', 'description'] as $field) {
                if (isset($row[$field]) && ! is_string($row[$field])) {
                    $errors["i18n.{$locale}.{$field}"] = $tampered;
                }
            }
        }

        $titleCap = SeoFields::hardCap('seoTitle');
        $descriptionCap = SeoFields::hardCap('seoDesc');
        foreach ($this->i18nDraft() as $locale => $row) {
            if (! isset($errors["i18n.{$locale}.title"]) && mb_strlen($row['title']) > $titleCap) {
                $errors["i18n.{$locale}.title"] = Cast::string(__('admin.gs.err_long', ['max' => $titleCap]));
            }
            if (! isset($errors["i18n.{$locale}.description"]) && mb_strlen($row['description']) > $descriptionCap) {
                $errors["i18n.{$locale}.description"] = Cast::string(__('admin.gs.err_long', ['max' => $descriptionCap]));
            }
        }
        $shared = $this->sharedDraft();
        if (! isset($errors['form.template']) && mb_strlen($shared['template']) > $titleCap) {
            $errors['form.template'] = Cast::string(__('admin.gs.err_long', ['max' => $titleCap]));
        }
        if (! isset($errors['form.robots']) && mb_strlen($shared['robots']) > SiteSeo::ROBOTS_MAX) {
            $errors['form.robots'] = Cast::string(__('admin.seoset.err_robots_long', ['max' => Number::format(SiteSeo::ROBOTS_MAX, locale: app()->getLocale())]));
        }

        return $errors;
    }

    private function notify(string $message, string $tone = 'success'): void
    {
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }
}
