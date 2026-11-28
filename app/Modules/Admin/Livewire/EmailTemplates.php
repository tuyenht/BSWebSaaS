<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\ShowsSettingsToast;
use App\Modules\Admin\Models\MailTemplate;
use App\Modules\Admin\Models\PlatformMailTemplate;
use App\Modules\Admin\Support\Locales;
use App\Modules\Admin\Support\Mail\MailSendContext;
use App\Modules\Admin\Support\Mail\MailTemplateCatalog;
use App\Modules\Admin\Support\Mail\MailTemplateDefaults;
use App\Modules\Admin\Support\Mail\MailTemplateRenderer;
use App\Modules\Admin\Support\Mail\MailTemplates;
use App\Modules\Admin\Support\Mail\MailTemplateStore;
use App\Modules\Admin\Support\MailBrand;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Mail\Markdown;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * «Mẫu thư» on a website's Email page (design c-email-settings, the templates tab): the website's
 * automatic mails, each with its on/off switch where it may be turned off, and the editor that words
 * one in one language (MailTemplateStore keeps only what was edited). The list is drawn here; the
 * editor's text box, toolbar and variables are the lazy part bsEmailTpl (admin-email-template.js),
 * which hands the typed subject and body to save()/preview() and never keeps them on the server.
 *
 * Every public method is an endpoint: boot() checks the Email page's permission on each request,
 * and each method checks its template and language against the catalog and the website.
 */
final class EmailTemplates extends Component
{
    use ShowsSettingsToast;

    public const array FILTERS = ['all', 'on', 'off'];

    /** The board's per-minute and per-day caps on test mails are the Email page's (shared counts). */
    public const int TEST_SENDS_PER_MINUTE = 5;

    #[Locked]
    public string $filter = 'all';

    /** The template open in the editor (a catalog key, never an id). */
    #[Locked]
    public ?string $editing = null;

    #[Locked]
    public string $lang = '';

    /** Bumped when the editor must take the server's text again (opened, saved, put back, another language). */
    #[Locked]
    public int $version = 0;

    /** The «Về mẫu gốc» question: a template's key, '*' for all, or null. */
    #[Locked]
    public ?string $resetAsk = null;

    /** The preview's «Từ» line (the sender the «Gửi thư» tab shows). */
    #[Locked]
    public string $fromName = '';

    #[Locked]
    public string $fromAddress = '';

    public function boot(): void
    {
        // The livewire/update endpoint bypasses route middleware: the Email page's own checks again.
        abort_unless(Gate::allows('settings.manage'), 403);
        app(SupportSession::class)->forbid();
        abort_unless(app(CurrentSite::class)->hasId(), 403);
    }

    public function mount(string $fromName = '', string $fromAddress = ''): void
    {
        $this->fromName = $fromName;
        $this->fromAddress = $fromAddress;
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, self::FILTERS, true) ? $filter : 'all';
    }

    /** «Sửa» / «Xem»: the editor, in the website's default language. */
    public function open(string $key): void
    {
        if (! in_array($key, $this->visibleKeys(), true)) {
            $this->notify(__('admin.mt_err_unknown'), 'error');

            return;
        }
        $this->editing = $key;
        $this->lang = $this->languagesOf($key)[0];
        $this->version++;
        $this->resetErrorBag();
        $this->dispatch('email-tpl-editing', on: true);
    }

    /** Back to the list (the editor asked about unsaved text first). */
    public function close(): void
    {
        $this->editing = null;
        $this->resetErrorBag();
        $this->dispatch('email-tpl-editing', on: false);
    }

    /** Another language of the open template (the editor asked about unsaved text first). */
    public function pickLang(string $code): void
    {
        if ($this->editing === null || ! in_array($code, $this->languagesOf($this->editing), true)) {
            return;
        }
        $this->lang = $code;
        $this->version++;
        $this->resetErrorBag();
    }

    /**
     * «Lưu mẫu»: the open template in the open language, as typed. False (with the errors under the
     * fields) when it cannot be kept.
     */
    public function save(string $subject, string $body): bool
    {
        $key = $this->editing;
        if ($key === null || ! MailTemplateCatalog::editable($key) || ! in_array($key, $this->visibleKeys(), true)) {
            $this->notify(__('admin.mt_err_system'), 'error');

            return false;
        }
        if (! in_array($this->lang, $this->languagesOf($key), true)) {
            $this->notify(__('admin.mt_err_lang'), 'error');

            return false;
        }
        $this->resetErrorBag();
        $bad = false;
        $plain = MailTemplateRenderer::plainSubject($subject);
        $required = MailTemplateCatalog::TEMPLATES[$key]['required'];
        // the required links in plain sight (no style around them that could hide one)
        $clean = mb_strlen($body) > MailTemplateCatalog::BODY_MAX ? null : MailTemplateRenderer::exposeLinks(
            app(MailTemplateRenderer::class)->clean($body),
            array_filter($required, MailTemplateCatalog::isLinkVar(...)),
        );
        $vars = MailTemplateCatalog::TEMPLATES[$key]['vars'];

        if ($plain === '') {
            $this->addError('tplSubject', __('admin.mt_err_subject_req'));
            $bad = true;
        } elseif (mb_strlen($plain) > MailTemplateCatalog::SUBJECT_MAX) {
            $this->addError('tplSubject', __('admin.mt_err_subject_long', ['max' => MailTemplateCatalog::SUBJECT_MAX]));
            $bad = true;
        } elseif (($unknown = self::unknownVar($plain, $vars)) !== null) {
            $this->addError('tplSubject', __('admin.mt_err_unknown_var', ['var' => $unknown]));
            $bad = true;
        } elseif (($link = self::linkVarIn($plain)) !== null) {
            // a subject is plain text: an address there is words anyone can copy, never a link
            $this->addError('tplSubject', __('admin.mt_err_subject_link', ['var' => $link]));
            $bad = true;
        }
        if ($clean === null || mb_strlen($clean) > MailTemplateCatalog::BODY_MAX) {
            $this->addError('tplBody', __('admin.mt_err_body_long'));
            $bad = true;
        } elseif (preg_match('/[\p{L}\p{N}]/u', strip_tags($clean)) !== 1) {
            $this->addError('tplBody', __('admin.mt_err_body_req'));
            $bad = true;
        } elseif (($unknown = self::unknownVar($clean, $vars)) !== null) {
            $this->addError('tplBody', __('admin.mt_err_unknown_var', ['var' => $unknown]));
            $bad = true;
        } else {
            $missing = array_filter(
                $required,
                // a link variable counts only as the whole address of a link with words (not in an
                // image's words, not as typed text, not as an empty link)
                static fn (string $token): bool => MailTemplateCatalog::isLinkVar($token) ? ! MailTemplateRenderer::hasLink($clean, $token) : ! str_contains($clean, $token),
            );
            if ($missing !== []) {
                $this->addError('tplBody', __('admin.mt_err_required_var', ['vars' => implode(', ', $missing)]));
                $bad = true;
            }
        }
        if ($bad) {
            return false;
        }

        app(MailTemplateStore::class)->put($key, $this->lang, $plain, (string) $clean);
        $this->version++;
        $this->notify(__('admin.mt_saved', ['name' => __('admin.mt_n_'.$key)]));

        return true;
    }

    /**
     * «Xem trước»: the typed template as the mail would carry it, with sample values (nothing kept).
     *
     * @return array{subject: string, html: string}
     */
    public function preview(string $subject, string $body): array
    {
        $this->skipRender();   // the answer is the preview; nothing on the page changes
        if ($this->editing === null || mb_strlen($body) > MailTemplateCatalog::BODY_MAX || mb_strlen($subject) > 2000) {
            return ['subject' => '', 'html' => ''];
        }

        return $this->sampleMail($this->editing, $this->lang, $subject, $body);
    }

    /**
     * «Gửi thử tới hộp thư của tôi»: the typed template with sample values to the member's own
     * address, through the website's outbox and the Email page's caps on test mails.
     */
    public function sendTest(string $subject, string $body): void
    {
        $to = Cast::string(auth()->user()?->email);
        if ($this->editing === null || $to === '' || mb_strlen($body) > MailTemplateCatalog::BODY_MAX || mb_strlen($subject) > 2000) {
            return;
        }
        $minute = 'mail-test-send:'.app(CurrentSite::class)->id().':'.Cast::int(auth()->id());
        if (RateLimiter::tooManyAttempts($minute, self::TEST_SENDS_PER_MINUTE)) {
            $this->notify(__('admin.em_test_wait', ['seconds' => max(1, RateLimiter::availableIn($minute))]), 'error');

            return;
        }
        RateLimiter::hit($minute, 60);

        if (! app(TenantMailer::class)->apply(fallback: false)) {
            $this->notify(__('admin.mt_test_failed'), 'error');

            return;
        }
        if (app(MailSendContext::class)->via === 'own' && $this->testDayUsedUp()) {
            $this->notify(__('admin.em_test_day_full', ['count' => EmailSettings::TEST_SENDS_PER_DAY]), 'error');

            return;
        }

        try {
            $mail = $this->sampleMail($this->editing, $this->lang, $subject, $body);
            $message = (new MailMessage)->subject($mail['subject'])->markdown('notifications::email', [
                'templateHtml' => new HtmlString($mail['html']),
                'templateText' => MailTemplateRenderer::text($mail['html']),
            ]);
            $views = [
                'html' => new HtmlString((string) $message->render()),
                'text' => new HtmlString((string) app(Markdown::class)->renderText('notifications::email', $message->data())),
            ];
            $subjectLine = $mail['subject'] !== '' ? $mail['subject'] : Cast::string(__('admin.test_email_subject'));
            Mail::send($views, [], function (Message $m) use ($to, $subjectLine): void {
                $m->to($to)->subject($subjectLine);
            });
            $this->notify(__('admin.mt_test_sent', ['to' => $to]));
        } catch (Throwable $e) {
            report($e);
            $this->notify(__('admin.mt_test_failed'), 'error');
        }
    }

    /** A switch: on or off at once (the website's off list). */
    public function toggle(string $key): void
    {
        if (! MailTemplateCatalog::switchable($key) || ! in_array($key, $this->visibleKeys(), true)) {
            return;
        }
        $off = ! app(MailTemplates::class)->isOff($key);
        app(MailTemplateStore::class)->setOff($key, $off);
        $this->notify(__($off ? 'admin.mt_off_done' : 'admin.mt_on_done', ['name' => __('admin.mt_n_'.$key)]));
    }

    /** «Hoàn tác» / «Về mẫu gốc» (one template) and «Về mẫu gốc tất cả» ('*'): the question first. */
    public function askReset(string $key): void
    {
        $this->resetAsk = $key === '*' || (MailTemplateCatalog::editable($key) && in_array($key, $this->visibleKeys(), true)) ? $key : null;
    }

    public function cancelReset(): void
    {
        $this->resetAsk = null;
    }

    public function doReset(): void
    {
        $key = $this->resetAsk;
        $this->resetAsk = null;
        if ($key === null) {
            return;
        }
        $store = app(MailTemplateStore::class);
        $keys = array_values(array_filter($this->visibleKeys(), MailTemplateCatalog::editable(...)));
        if ($key === '*') {
            $n = MailTemplate::query()->whereIn('template_key', $keys)->distinct()->count('template_key');
            $store->forgetAll($keys);
            $this->notify(__('admin.mt_reset_all_done', ['n' => $n]));
        } elseif (in_array($key, $keys, true)) {
            $store->forget($key);
            $this->notify(__('admin.mt_reset_done', ['name' => __('admin.mt_n_'.$key)]));
        }
        $this->version++;
    }

    public function render(): View
    {
        $keys = $this->visibleKeys();
        // The website's edited copies and the platform's: which template and language each holds (one query each).
        $site = [];
        foreach (MailTemplate::query()->get(['template_key', 'locale']) as $row) {
            $site[$row->template_key][$row->locale] = true;
        }
        $platform = [];
        foreach (PlatformMailTemplate::query()->get(['template_key', 'locale']) as $row) {
            $platform[$row->template_key][$row->locale] = true;
        }
        $off = app(MailTemplates::class)->offKeys();
        $locales = app(SiteLocale::class);
        $enabled = $locales->enabled();
        $default = $locales->default();
        $defaults = app(MailTemplateDefaults::class);

        $rows = [];
        foreach ($keys as $key) {
            $t = MailTemplateCatalog::TEMPLATES[$key];
            $langs = [];
            foreach ($this->languagesOf($key) as $code) {
                $langs[] = ['code' => $code, 'done' => $this->sendsIn($key, $code, $default, $site, $platform, $defaults)];
            }
            $rows[$key] = [
                'key' => $key,
                'name' => __('admin.mt_n_'.$key),
                'when' => __('admin.mt_w_'.$key),
                'icon' => $t['icon'],
                'kind' => $t['kind'],
                'group' => $t['group'],
                'on' => ! in_array($key, $off, true),
                'edited' => $t['kind'] !== MailTemplateCatalog::SYSTEM && isset($site[$key]),
                // what turning it off stops besides its mail
                'note' => in_array($key, MailTemplateCatalog::NEEDED, true) ? Cast::string(__('admin.mt_needed_'.$key)) : null,
                'langs' => $t['i18n'] && count($enabled) <= 1 ? [] : $langs,
            ];
        }

        $listed = array_filter($rows, static fn (array $r): bool => $r['kind'] === MailTemplateCatalog::SWITCH);
        $counts = [
            'all' => count($listed),
            'on' => count(array_filter($listed, static fn (array $r): bool => $r['on'])),
            'off' => count(array_filter($listed, static fn (array $r): bool => ! $r['on'])),
        ];
        $shown = array_filter($listed, fn (array $r): bool => $this->filter === 'all' || ($this->filter === 'on') === $r['on']);
        $groups = [];
        foreach (MailTemplateCatalog::GROUPS as $group) {
            $items = array_values(array_filter($shown, static fn (array $r): bool => $r['group'] === $group));
            if ($items !== []) {
                $groups[] = ['label' => __('admin.mt_g_'.$group), 'items' => $items];
            }
        }
        $locked = array_values(array_filter($rows, static fn (array $r): bool => $r['kind'] !== MailTemplateCatalog::SWITCH));
        $editedCount = count(array_filter($rows, static fn (array $r): bool => $r['edited']));
        $look = $this->editing !== null ? app(MailBrand::class)->current() : null;
        $brand = [
            'name' => $look['name'] ?? '',
            'logo' => $look['logo'] ?? null,
            'color' => $look['color'] ?? MailBrand::FALLBACK_COLOR,
            'text' => $look['text'] ?? '#ffffff',
            'signature' => $look['signature'] ?? '',
        ];

        return view('admin::livewire.email-templates', [
            'groups' => $groups,
            'counts' => $counts,
            'locked' => $locked,
            'editedCount' => $editedCount,
            'brand' => $brand,
            'editor' => $this->editing !== null && isset($rows[$this->editing]) ? $this->editorView($rows[$this->editing], $site, $platform, $default) : null,
        ]);
    }

    /**
     * What the editor shows for the open template and language: the text that language's mail goes
     * out with today (the website's copy, the platform's, the default language's when that language
     * has none, or the wording in code), the variables, and the switches.
     *
     * @param  array{key: string, name: string, when: string, icon: string, kind: string, group: string, on: bool, edited: bool, note: string|null, langs: list<array{code: string, done: bool}>}  $row
     * @param  array<string, array<string, true>>  $site
     * @param  array<string, array<string, true>>  $platform
     * @return array<string, mixed>
     */
    private function editorView(array $row, array $site, array $platform, string $default): array
    {
        $key = $row['key'];
        $t = MailTemplateCatalog::TEMPLATES[$key];
        $text = $this->textOf($key, $this->lang);
        $languages = [];
        foreach ($this->languagesOf($key) as $code) {
            $languages[] = [
                'code' => $code,
                'name' => Locales::label($code),
                'done' => $this->sendsIn($key, $code, $default, $site, $platform, app(MailTemplateDefaults::class)),
                'source' => $code === $default,
            ];
        }
        $samples = $this->sampleValues($this->lang, app(MailBrand::class)->values($this->lang)['{site_url}'] ?? '');
        $vars = [];
        foreach ($t['vars'] as $token) {
            $name = trim($token, '{}');
            $vars[] = [
                'token' => $token,
                'group' => MailTemplateCatalog::VARS[$token]['group'],
                'link' => MailTemplateCatalog::isLinkVar($token),
                'desc' => __('admin.mt_v_'.$name),
                'sample' => $samples[$token] ?? '',
            ];
        }
        $groups = [];
        foreach (MailTemplateCatalog::VAR_GROUPS as $g) {
            if (array_filter($vars, static fn (array $v): bool => $v['group'] === $g) !== []) {
                $groups[] = ['key' => $g, 'label' => __('admin.mt_vg_'.$g)];
            }
        }

        return [
            ...$row,
            'subject' => $text['subject'],
            'body' => $text['body'],
            'fromDefault' => $text['fromDefault'],
            'editable' => $t['kind'] !== MailTemplateCatalog::SYSTEM,
            'i18n' => $t['i18n'],
            'languages' => $languages,
            'langName' => Locales::label($this->lang),
            'defaultName' => Locales::label($default),
            'vars' => $vars,
            'varGroups' => $groups,
            'required' => $t['required'],
            'preview' => $t['kind'] === MailTemplateCatalog::SYSTEM ? $this->sampleMail($key, $this->lang, $text['subject'], $text['body']) : null,
        ];
    }

    /**
     * The first `{name}` in a text that is not one of the template's variables (it would go out as
     * typed), also inside an address the cleaner encoded.
     *
     * @param  list<string>  $vars
     */
    private static function unknownVar(string $text, array $vars): ?string
    {
        preg_match_all('/\{[a-z0-9_]+\}/i', preg_replace('/%7B([a-z0-9_]+)%7D/i', '{$1}', $text) ?? $text, $found);
        foreach ($found[0] as $token) {
            if (! in_array($token, $vars, true)) {
                return $token;
            }
        }

        return null;
    }

    /** The first link variable in a text. */
    private static function linkVarIn(string $text): ?string
    {
        preg_match_all('/\{[a-z_]+\}/', $text, $found);
        foreach ($found[0] as $token) {
            if (MailTemplateCatalog::isLinkVar($token)) {
                return $token;
            }
        }

        return null;
    }

    /**
     * The text a template's mail in a language goes out with today, as the editor shows it.
     *
     * @return array{subject: string, body: string, fromDefault: bool}
     */
    private function textOf(string $key, string $locale): array
    {
        $stored = app(MailTemplates::class)->stored($key, $locale);
        if ($stored !== null) {
            // cleaned again before the editor puts it in the page (a row is only ever as safe as its writer)
            return ['subject' => MailTemplateRenderer::plainSubject($stored['subject']), 'body' => app(MailTemplateRenderer::class)->clean($stored['body']), 'fromDefault' => $stored['locale'] !== $locale];
        }
        $code = app(MailTemplateDefaults::class)->for($key, $locale);

        return ['subject' => $code['subject'], 'body' => $code['body'], 'fromDefault' => false];
    }

    /**
     * Whether a template's mail in a language is worded in that language (a chip's «Đã có bản …»).
     *
     * @param  array<string, array<string, true>>  $site
     * @param  array<string, array<string, true>>  $platform
     */
    private function sendsIn(string $key, string $code, string $default, array $site, array $platform, MailTemplateDefaults $defaults): bool
    {
        $siteTier = MailTemplateCatalog::TEMPLATES[$key]['kind'] !== MailTemplateCatalog::SYSTEM;
        $own = ($siteTier && isset($site[$key][$code])) || isset($platform[$key][$code]);
        if ($own) {
            return true;
        }
        $fallback = $code !== $default && (($siteTier && isset($site[$key][$default])) || isset($platform[$key][$default]));

        return ! $fallback && $defaults->has($key, $code);
    }

    /**
     * A template as the mail carries it, with sample values in the editor's language.
     *
     * @return array{subject: string, html: string}
     */
    private function sampleMail(string $key, string $locale, string $subject, string $body): array
    {
        $renderer = app(MailTemplateRenderer::class);
        $brand = app(MailBrand::class);
        $own = $brand->values($locale);   // the website's own variables, read once per preview
        $values = array_merge($own, $this->sampleValues($locale, $own['{site_url}'] ?? ''));
        $look = $brand->current();

        return [
            'subject' => $renderer->subject($subject, $values),
            'html' => $renderer->html($body, $values, $look['color'] ?? MailBrand::FALLBACK_COLOR, $look['text'] ?? '#ffffff'),
        ];
    }

    /**
     * The preview's sample values for a mail's own variables (the website's own come from MailBrand).
     *
     * @return array<string, string>
     */
    private function sampleValues(string $locale, string $site): array
    {
        $base = $site !== '' ? $site : 'https://example.com';
        $words = [];
        foreach (['customer_name', 'change_time', 'message', 'form_name', 'submitted_at', 'inviter_name', 'roles', 'invite_message', 'expires_at'] as $name) {
            $words['{'.$name.'}'] = Cast::string(__('admin.mt_s_'.$name, [], $locale));
        }

        return [
            ...$words,
            '{customer_email}' => 'an.nguyen@gmail.com',
            '{customer_phone}' => '0912 345 678',
            '{change_ip}' => '113.161.44.20',
            '{verify_url}' => $base.'/xac-minh/9f2c',
            '{reset_url}' => $base.'/dat-lai-mat-khau/7b1d',
            '{account_url}' => $base.'/tai-khoan',
            '{admin_url}' => $base.'/admin/lien-he/482',
            '{accept_url}' => $base.'/admin/loi-moi/8f3a',
        ];
    }

    /**
     * The templates this website sees (the board's list for a company website, by its modules).
     *
     * @return list<string>
     */
    private function visibleKeys(): array
    {
        return MailTemplateCatalog::visible(app(Entitlement::class)->entitledModuleKeys());
    }

    /**
     * The languages a template is written in: the website's languages, default first; only the
     * default one for a mail the website's own people get.
     *
     * @return non-empty-list<string>
     */
    private function languagesOf(string $key): array
    {
        $locales = app(SiteLocale::class);
        $default = $locales->default();
        if (! (MailTemplateCatalog::TEMPLATES[$key]['i18n'] ?? false)) {
            return [$default];
        }

        return array_values(array_unique([$default, ...$locales->enabled()]));
    }

    /** Takes one of the website's test mails of its day through its own outbox (the Email page's count). */
    private function testDayUsedUp(): bool
    {
        $now = app(SiteTime::class)->now();
        $untilTomorrow = (int) ceil(abs($now->diffInSeconds($now->addDay()->startOfDay())));
        $key = 'mail-test-day:'.app(CurrentSite::class)->id().':'.$now->toDateString();

        return RateLimiter::hit($key, $untilTomorrow + 3600) > EmailSettings::TEST_SENDS_PER_DAY;
    }
}
