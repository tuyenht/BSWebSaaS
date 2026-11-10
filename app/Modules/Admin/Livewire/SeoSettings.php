<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\WithMediaLibraryPicker;
use App\Modules\Admin\Support\SeoFields;
use App\Modules\Admin\Support\Settings\SetBrandImages;
use App\Modules\Admin\Support\Settings\SettingsChangeRejected;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\PlainText;
use App\Modules\Storefront\Support\BrandImages;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteIdentity;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Theme\Jobs\CheckAiCrawlers;
use App\Modules\Theme\Theming\AiCrawlerCheck;
use App\Modules\Theme\Theming\RobotsTxt;
use App\Modules\Theme\Theming\SiteSeo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
 * "Google & mạng xã hội": per language the site's home page title and default description, one
 * title template for the other pages without their own title, whether Google may find the site,
 * whether the sitemap is made, and a custom robots.txt. One `site_settings` row (group `seo`, key
 * `config`, the shape SiteSeo reads), written through SettingsRepository; the storefront follows it
 * (SiteSeo). The page edits a draft and saves it in one step, like "Thông tin chung": a field is
 * checked when it is left and every field again on save. Turning Google off asks first. Every
 * request is settings.manage-gated (boot) and so is every action.
 *
 * The share image is set here too, from the shared Image library (WithMediaLibraryPicker, which
 * re-checks media.view and only hands over this site's live pictures): the pick is a media key in
 * the draft, counted and discarded like any field, and saved with it through SetBrandImages (slot
 * og_image), which checks the key again; a refused key writes nothing. "Gỡ ảnh" empties the slot
 * and the site falls back to its logo. The picture the slot held before stays in the library. A
 * share image that is still the older general.brand URL shows in the slot too, so it can be
 * replaced or removed here.
 *
 * It also says whether AI bots reach the website (AiCrawlerCheck, run off the request by
 * CheckAiCrawlers): opening the page starts a check when the kept result is missing or older than
 * a day, and "Kiểm tra lại" starts one at most once a minute per site.
 */
#[Layout('admin::layouts.app')]
final class SeoSettings extends Component
{
    use WithMediaLibraryPicker {
        openImagePicker as private openLibrary;
        togglePickerItem as private toggleLibraryItem;
    }

    /**
     * The draft's share image while it is still the older URL general.brand holds (a site whose
     * share image was never saved here): never a media key, so a save that sends it is refused.
     */
    private const string OLD_IMAGE = '@old';

    /** "Kiểm tra lại" (the AI crawler check): once per site in this many seconds. */
    public const int RECHECK_WINDOW = 60;

    /** The AI check's findings that come with a way to fix them (`admin.seoset.ai.fix.<code>`). */
    private const array AI_FIXES = ['hidden', 'own_blocks', 'own_partial', 'proxy_blocks', 'cloudflare_challenge', 'bot_refused', 'platform_hidden', 'noindex_header', 'not_pointing', 'redirect_off_host'];

    /**
     * The groups of the page and the fields of each, in order: [key, kind, per-language].
     * Kinds: text, textarea, preview, toggle, note, aibot (the AI bot check card), image (a picture
     * from the Image library), code.
     *
     * @var array<string, list<array{string, string, bool}>>
     */
    public const array GROUPS = [
        'google' => [['title', 'text', true], ['template', 'text', false], ['description', 'textarea', true], ['share_image', 'image', false], ['preview', 'preview', false]],
        'index' => [['indexable', 'toggle', false], ['domain_note', 'note', false], ['ai_check', 'aibot', false], ['verify_note', 'note', false]],
        'advanced' => [['sitemap', 'toggle', false], ['robots', 'code', false]],
    ];

    /** @var array<string, array{title: string, description: string}> per-language values */
    public array $i18n = [];

    /** @var array{template: string, indexable: bool, sitemap: bool, robots: string} the values every language shares */
    public array $form = ['template' => '', 'indexable' => true, 'sitemap' => true, 'robots' => ''];

    /** The share image of the draft: a media key of the site's library ('' = none, the logo is used). */
    public string $shareKey = '';

    /** The language whose own values are being edited. */
    public string $lang = '';

    /** The "hide the site from Google?" question is open. */
    public bool $confirmHide = false;

    /**
     * The draft as it was loaded or last saved: the bar counts the fields that differ.
     *
     * @var array{i18n: array<string, array{title: string, description: string}>, shared: array{template: string, indexable: bool, sitemap: bool, robots: string}, image: string}
     */
    #[Locked]
    public array $saved = ['i18n' => [], 'shared' => ['template' => '', 'indexable' => true, 'sitemap' => true, 'robots' => ''], 'image' => ''];

    public string $toast = '';

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    /** The address the AI check looks at, read once per request ('' = not read yet). */
    private ?string $aiHost = '';

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
        $this->checkAiCrawlersIfStale();
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
     * "Kiểm tra lại": check again whether AI bots reach the website, off the request. Once a
     * minute per site; the card says "Đang kiểm" at once and shows the result when the check ends
     * (it asks again every few seconds meanwhile). Pressed while a check is under way it does
     * nothing (the card already says so) and uses up no minute.
     */
    #[Authorize('settings.manage')]
    public function recheck(): void
    {
        $siteId = app(CurrentSite::class)->id();
        if (app(AiCrawlerCheck::class)->isRunning($siteId)) {
            return;
        }
        $key = self::recheckKey($siteId);
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $this->notify(Cast::string(__('admin.seoset.ai.wait', ['time' => self::recheckFrom($key)])), 'info');

            return;
        }
        RateLimiter::hit($key, self::RECHECK_WINDOW);

        if ($this->aiHost($siteId) === null) {
            $this->notify(__('admin.seoset.ai.not_ready'), 'error');

            return;
        }
        $this->startAiCheck($siteId);
    }

    /** "Gỡ ảnh": the slot is emptied in the draft; once saved the site uses its logo. */
    #[Authorize('settings.manage')]
    public function removeShareImage(): void
    {
        $this->shareKey = '';
        $this->resetErrorBag('share_image');
    }

    /** The library opens on pictures only: the slot takes nothing else. */
    public function openImagePicker(string $mode = 'gallery'): void
    {
        $this->openLibrary('gallery');
        $this->pickerType = 'image';
    }

    /** The slot holds one image: choosing another in the library replaces the one chosen before. */
    public function togglePickerItem(int $mediaId): void
    {
        $this->toggleLibraryItem($mediaId);
        if (! $this->pickerTrash && count($this->pickerSelected) > 1) {
            $this->pickerSelected = [$mediaId];
        }
    }

    /**
     * The picture chosen in the library goes into the draft, as its media key. A file that is not a
     * picture, or an SVG (Facebook and Zalo show none), is refused with a message under the slot.
     */
    protected function addGalleryImage(int $mediaId): void
    {
        $this->showImagePicker = false;
        $this->resetErrorBag('share_image');
        $media = Media::query()->whereKey($mediaId)->first(['id', 'storage_key', 'mime']);
        if ($media === null) {
            return;
        }
        if (! str_starts_with($media->mime, 'image/') || $media->mime === 'image/svg+xml') {
            $this->addError('share_image', __('admin.seoset.err_share_type'));

            return;
        }
        $this->shareKey = $media->storage_key;
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
        $image = trim($this->shareKey);
        try {
            // One step: a share image the library refuses (another site's, in the trash, not a
            // picture) or a busy lock leaves the texts unsaved too, so the draft is kept whole.
            DB::transaction(function () use ($settings, $titles, $descriptions, $shared, $image): void {
                if ($image !== $this->saved['image']) {
                    app(SetBrandImages::class)->handle(['og_image' => $image]);
                }
                $settings->put('seo', 'config', [
                    'titles' => array_filter($titles, static fn (string $text): bool => $text !== ''),
                    'descriptions' => array_filter($descriptions, static fn (string $text): bool => $text !== ''),
                    'template' => $shared['template'],
                    'indexable' => $shared['indexable'],
                    'sitemap' => $shared['sitemap'],
                    'robots' => $shared['robots'],
                ]);
            });
        } catch (SettingsChangeRejected $e) {
            if ($e->reason === 'busy') {
                $this->notify(__('admin.settings.busy'), 'error');

                return;
            }
            if ($e->reason !== 'image_invalid') {
                throw $e;
            }
            $this->addError('share_image', __('admin.seoset.err_share_image'));
            $this->notify(__('admin.gs.toast_invalid', ['count' => 1]), 'error');
            $this->dispatch('seo-first-error', group: $this->groupOf('share_image'));

            return;
        }

        // What the AI check looked at changed: its kept result no longer holds — check again.
        $was = $this->saved['shared'];
        if ($was['indexable'] !== $shared['indexable'] || $was['sitemap'] !== $shared['sitemap'] || $was['robots'] !== $shared['robots']) {
            $this->forgetAiCheck();
        }

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
        // The share preview shows the image a shared link would carry with the draft: the saved
        // share image (or the logo) until another is picked, the picked one, else the logo.
        $urls = app(MediaUrlResolver::class);
        $images = app(BrandImages::class)->for(app(SiteIdentity::class)->for($this->lang));
        $picked = $this->pickedShareImage();
        $oldImage = $this->shareKey === self::OLD_IMAGE ? ($images['og_image'] ?? null) : null;
        $shareImage = match (true) {
            $this->shareKey === $this->saved['image'] => $images['og_image'] ?? $images['logo'] ?? '',
            $picked !== null => $urls->url($picked->storage_key, 1280),
            default => $images['logo'] ?? '',
        };

        $aiCheck = $this->aiCheck();
        $blocking = $this->robotsBlocking($this->sharedDraft()['robots']);

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
            'shareSlot' => match (true) {
                $picked !== null => [
                    'thumb' => $urls->url($picked->storage_key, 320),
                    'name' => $picked->original_filename ?? $picked->slug ?? basename($picked->storage_key),
                    'meta' => $picked->width !== null && $picked->height !== null
                        ? Cast::string(__('admin.seoset.share_size', ['w' => $picked->width, 'h' => $picked->height]))
                        : Cast::string(__('admin.seoset.share_from_library')),
                ],
                $oldImage !== null && preg_match('~\Ahttps?://~i', $oldImage) === 1 => [
                    'thumb' => $oldImage,
                    'name' => rawurldecode(basename(Cast::string(parse_url($oldImage, PHP_URL_PATH)))) ?: $oldImage,
                    'meta' => Cast::string(__('admin.seoset.share_old')),
                ],
                default => null,
            },
            // Under the preview, "the image the site uses now" holds only while the slot is as saved.
            'shareSaved' => $this->shareKey === $this->saved['image'],
            'canPick' => Gate::allows('media.view'),
            // The sentence is true only while the platform keeps its subdomains out of search engines.
            'platformHidden' => Cast::bool(config('tenancy.noindex_platform_subdomains')),
            'domainsLink' => $this->domainsLink(),
            'aiCheck' => $aiCheck,
            'aiCard' => $this->aiCard($aiCheck, $blocking),
            // Under the robots.txt box: what the text as typed blocks, and how to undo it.
            'robotsNote' => $blocking !== null ? $blocking['message'].' '.Cast::string(__('admin.seoset.ai.robots_note_fix')) : null,
        ]);
        $view->title(__('admin.seoset.title'));

        return $view;
    }

    private static function siteIdOrZero(): int
    {
        $current = app(CurrentSite::class);

        return $current->hasId() ? $current->id() : 0;
    }

    /** Null without a site context (nothing to check then). */
    private function aiHost(int $siteId): ?string
    {
        if ($this->aiHost === '') {
            $this->aiHost = $siteId > 0 ? app(AiCrawlerCheck::class)->host($siteId) : null;
        }

        return $this->aiHost;
    }

    /** Drop the site's kept AI check result and start a new one (it runs once the save commits). */
    private function forgetAiCheck(): void
    {
        $siteId = self::siteIdOrZero();
        $host = $this->aiHost($siteId);
        if ($host !== null) {
            app(AiCrawlerCheck::class)->forget($siteId, $host);
            $this->checkAiCrawlersIfStale();
        }
    }

    /** Opening the page: start a check when none is kept for the site's address (they last a day). */
    private function checkAiCrawlersIfStale(): void
    {
        $siteId = self::siteIdOrZero();
        $check = app(AiCrawlerCheck::class);
        $host = $this->aiHost($siteId);
        if ($host === null || $check->result($siteId, $host) !== null) {
            return;
        }
        $this->startAiCheck($siteId);
    }

    /**
     * Queue a check unless one already is. The job is sent once this request's transaction
     * commits; should it roll back instead, the job is dropped with it, and so is the mark — or
     * the page would say "checking" for RUNNING_TTL with nothing queued.
     */
    private function startAiCheck(int $siteId): void
    {
        $check = app(AiCrawlerCheck::class);
        if (! $check->markRunning($siteId)) {
            return;
        }
        DB::afterRollBack(static fn () => $check->clearRunning($siteId));
        CheckAiCrawlers::dispatch($siteId);
    }

    /**
     * The AI crawler check as the status line shows it: whether a check is under way, the overall
     * level (`ok`, `info`, `warning`, `unchecked`; null before the first result) with its words,
     * when it was checked (site time), the address checked, and each finding in plain words with
     * its fix.
     *
     * @return array{running: bool, level: string|null, label: string, checkedAt: string|null, host: string|null, findings: list<array{level: string, code: string, message: string, fix: string|null}>}
     */
    private function aiCheck(): array
    {
        $siteId = self::siteIdOrZero();
        $check = app(AiCrawlerCheck::class);
        $host = $this->aiHost($siteId);
        if ($host === null) {
            return ['running' => false, 'level' => 'unchecked', 'label' => Cast::string(__('admin.seoset.ai.level.unchecked')), 'checkedAt' => null, 'host' => null,
                'findings' => [['level' => 'unchecked', 'code' => 'not_ready', 'message' => Cast::string(__('admin.seoset.ai.not_ready')), 'fix' => null]]];
        }

        $running = $check->isRunning($siteId);
        $result = $check->result($siteId, $host);
        if ($result === null) {
            return ['running' => $running, 'level' => null, 'label' => Cast::string(__($running ? 'admin.seoset.ai.running' : 'admin.seoset.ai.never')), 'checkedAt' => null, 'host' => $host, 'findings' => []];
        }

        $time = app(SiteTime::class);
        $at = CarbonImmutable::createFromTimestampUTC($result['checked_at']);
        $findings = [];
        foreach ($result['findings'] as $finding) {
            $findings[] = [
                'level' => $finding['level'],
                'code' => $finding['code'],
                'message' => Cast::string(__('admin.seoset.ai.'.$finding['code'], self::aiParams($finding['params'] + ['host' => $host]))),
                'fix' => in_array($finding['code'], self::AI_FIXES, true) ? Cast::string(__('admin.seoset.ai.fix.'.$finding['code'])) : null,
            ];
        }
        if ($findings === []) {
            $findings[] = ['level' => 'ok', 'code' => 'all_clear', 'message' => Cast::string(__('admin.seoset.ai.all_clear')), 'fix' => null];
        }

        return [
            'running' => $running,
            'level' => $result['level'],
            'label' => Cast::string(__('admin.seoset.ai.level.'.$result['level'])),
            'checkedAt' => $time->forDisplay($at)?->format('H:i').', '.$time->day($at, app()->getLocale()),
            'host' => $host,
            'findings' => $findings,
        ];
    }

    /**
     * The AI bot card (design: the shared status block): its tone (`wait` while a check is under
     * way, `done`, `info`, `warn`, `nochk`), icon, status word, the lines to read (each with its
     * fix), when it was checked, and whether "Kiểm tra lại" waits for the minute to pass. Being
     * hidden from search engines and blocking bots in the site's own robots.txt are read from the
     * draft on the page, so the card follows the switch and the box before they are saved; the
     * kept result answers the rest (its own-settings findings are left to the draft). Several
     * findings are listed the worst first.
     *
     * @param  array{running: bool, level: string|null, label: string, checkedAt: string|null, host: string|null, findings: list<array{level: string, code: string, message: string, fix: string|null}>}  $check
     * @param  array{message: string, unchecked: bool}|null  $blocking  what the robots.txt text as typed blocks (robotsBlocking)
     * @return array{tone: string, icon: string, status: string, lines: list<array{message: string, fix: string|null}>, at: string|null, busy: bool, limited: bool, limit: string, unlockIn: int}
     */
    private function aiCard(array $check, ?array $blocking): array
    {
        $draft = $this->sharedDraft();
        $words = static fn (string $key): string => Cast::string(__('admin.seoset.ai.'.$key));
        $busy = $check['running'];

        if ($busy) {
            [$tone, $icon, $status, $lines] = ['wait', 'ri-loader-4', $words('running'), [['message' => $words('running_note'), 'fix' => null]]];
        } elseif (! $draft['indexable']) {
            [$tone, $icon, $status, $lines] = ['info', 'ri-eye-off', $words('level.info'), [['message' => $words('hidden'), 'fix' => $words('fix.hidden')]]];
        } elseif ($blocking !== null && $blocking['unchecked']) {
            [$tone, $icon, $status, $lines] = ['nochk', 'ri-question', $words('level.unchecked'), [['message' => $blocking['message'], 'fix' => $words('fix.own_blocks')]]];
        } elseif ($blocking !== null) {
            [$tone, $icon, $status, $lines] = ['info', 'ri-information', $words('level.info'), [['message' => $blocking['message'], 'fix' => $words('fix.own_blocks')]]];
        } elseif ($check['level'] === null) {
            [$tone, $icon, $status, $lines] = ['nochk', 'ri-question', $words('level.unchecked'), [['message' => $words('never'), 'fix' => null]]];
        } else {
            $rank = ['ok' => 0, 'info' => 1, 'unchecked' => 2, 'warning' => 3];
            $findings = array_values(array_filter($check['findings'], static fn (array $f): bool => ! in_array($f['code'], ['hidden', 'own_blocks', 'own_partial'], true)));
            usort($findings, static fn (array $a, array $b): int => ($rank[$b['level']] ?? 0) <=> ($rank[$a['level']] ?? 0));
            $worst = $findings[0] ?? ['level' => 'ok', 'code' => 'all_clear', 'message' => $words('all_clear'), 'fix' => null];
            if ($findings === [] || $worst['level'] === 'ok') {
                $findings = [$worst];
            }
            [$tone, $icon, $status] = match ($worst['level']) {
                'warning' => ['warn', 'ri-alert', $words('level.warning')],
                'unchecked' => ['nochk', 'ri-question', $words('level.unchecked')],
                'info' => ['info', $worst['code'] === 'cloudflare_challenge' ? 'ri-cloud' : 'ri-information', $words('level.info')],
                default => ['done', 'ri-checkbox-circle-line', $words('level.ok')],
            };
            $lines = array_map(static fn (array $f): array => ['message' => $f['message'], 'fix' => $f['fix']], $findings);
        }

        $siteId = self::siteIdOrZero();
        $key = self::recheckKey($siteId);
        $limited = ! $busy && $siteId > 0 && RateLimiter::tooManyAttempts($key, 1);
        $at = $check['checkedAt'];

        return [
            'tone' => $tone,
            'icon' => $icon,
            'status' => $status,
            'lines' => $lines,
            'at' => $at === null ? null : Cast::string(__($busy ? 'admin.seoset.ai.checked_before' : 'admin.seoset.ai.checked_at', ['at' => $at])),
            'busy' => $busy,
            'limited' => $limited,
            'limit' => $limited ? Cast::string(__('admin.seoset.ai.wait', ['time' => self::recheckFrom($key)])) : '',
            'unlockIn' => $limited ? max(1, RateLimiter::availableIn($key)) : 0,
        ];
    }

    /**
     * What the site's own robots.txt text (as typed) blocks, in words — every search engine
     * (Googlebot and every AI bot the check knows), else the bots it shuts out and those it keeps
     * from some pages; a text past the judging bounds says it could not be judged (never "ổn") —
     * or null when it blocks no bot the check knows.
     *
     * @return array{message: string, unchecked: bool}|null
     */
    private function robotsBlocking(string $robots): ?array
    {
        if ($robots === '') {
            return null;
        }
        $parsed = RobotsTxt::parse($robots);
        $verdicts = $parsed->verdicts(AiCrawlerCheck::bots());
        if ($verdicts === null) {
            return ['message' => Cast::string(__('admin.seoset.ai.robots_too_complex')), 'unchecked' => true];
        }
        $allBlocked = $verdicts !== [] && array_filter($verdicts, static fn (string $v): bool => $v !== 'blocked') === [];
        if ($allBlocked && $parsed->verdict('Googlebot') === 'blocked') {
            return ['message' => Cast::string(__('admin.seoset.ai.own_all')), 'unchecked' => false];
        }
        $blocked = array_keys(array_filter($verdicts, static fn (string $v): bool => $v === 'blocked'));
        $partial = array_keys(array_filter($verdicts, static fn (string $v): bool => $v === 'partial'));
        $parts = [];
        if ($blocked !== []) {
            $parts[] = Cast::string(__('admin.seoset.ai.own_blocks', ['bots' => implode(', ', $blocked)]));
        }
        if ($partial !== []) {
            $parts[] = Cast::string(__('admin.seoset.ai.own_partial', ['bots' => implode(', ', $partial)]));
        }

        return $parts === [] ? null : ['message' => implode(' ', $parts), 'unchecked' => false];
    }

    private static function recheckKey(int $siteId): string
    {
        return 'seo-ai-check:'.$siteId;
    }

    /** When "Kiểm tra lại" may be pressed again, in site time (HH:MM). */
    private static function recheckFrom(string $key): string
    {
        $time = app(SiteTime::class);

        return (string) $time->forDisplay(CarbonImmutable::now()->addSeconds(max(1, RateLimiter::availableIn($key))))?->format('H:i');
    }

    /**
     * A finding's placeholders in words: bot lists joined (a refused bot with its status code),
     * Content-Signal values joined, a status code and a host as they are.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    private static function aiParams(array $params): array
    {
        $words = [];
        foreach (Cast::strings($params['bots'] ?? null) as $bot) {
            $at = strrpos($bot, ':');
            $words[] = $at === false ? $bot : Cast::string(__('admin.seoset.ai.bot_status', ['bot' => substr($bot, 0, $at), 'status' => substr($bot, $at + 1)]));
        }

        return [
            'bots' => implode(', ', $words),
            'signals' => implode('; ', Cast::strings($params['signals'] ?? null)),
            'status' => (string) Cast::int($params['status'] ?? null),
            'host' => Cast::string($params['host'] ?? null),
        ];
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

    /**
     * The picture the draft's share image names, when it is a live picture of this site's library
     * (null otherwise: none, or a key the library does not know).
     */
    private function pickedShareImage(): ?Media
    {
        if ($this->shareKey === '' || $this->shareKey === self::OLD_IMAGE) {
            return null;
        }

        return Media::query()->where('storage_key', $this->shareKey)->where('mime', 'like', 'image/%')
            ->first(['id', 'storage_key', 'slug', 'original_filename', 'width', 'height']);
    }

    /** @return array{i18n: array<string, array{title: string, description: string}>, shared: array{template: string, indexable: bool, sitemap: bool, robots: string}, image: string} */
    private function load(): array
    {
        $stored = SiteSeo::normalize(app(SettingsRepository::class)->get('seo', 'config'));
        $i18n = [];
        foreach ($this->locales() as $locale) {
            $i18n[$locale] = ['title' => $stored['titles'][$locale] ?? '', 'description' => $stored['descriptions'][$locale] ?? ''];
        }

        // The saved share image, by its key: only a live picture of the site counts (one query).
        // Without one, the older URL general.brand may still hold is what the site shows.
        $image = Media::query()->whereIn('id', SiteProfile::query()->select(SiteProfile::IMAGE_COLUMNS['og_image']))
            ->where('mime', 'like', 'image/%')->value('storage_key');
        if (! is_string($image) || $image === '') {
            $old = Cast::arr(app(SiteIdentity::class)->for(app(SiteLocale::class)->default())['legacy_images'] ?? null)['og_image'] ?? null;
            $image = is_string($old) && $old !== '' ? self::OLD_IMAGE : '';
        }

        return ['i18n' => $i18n, 'shared' => [
            'template' => $stored['template'], 'indexable' => $stored['indexable'], 'sitemap' => $stored['sitemap'], 'robots' => $stored['robots'],
        ], 'image' => $image];
    }

    /** @param  array{i18n: array<string, array{title: string, description: string}>, shared: array{template: string, indexable: bool, sitemap: bool, robots: string}, image: string}  $loaded */
    private function apply(array $loaded): void
    {
        $this->i18n = $loaded['i18n'];
        $this->form = $loaded['shared'];
        $this->shareKey = $loaded['image'];
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
        $n += $this->shareKey !== $this->saved['image'] ? 1 : 0;

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
