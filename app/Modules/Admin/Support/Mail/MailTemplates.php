<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Mail;

use App\Modules\Admin\Models\MailTemplate;
use App\Modules\Admin\Models\PlatformMailTemplate;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;

/**
 * Reads the stored wording of a mail template, top tier first: the website's own copy, then the
 * platform's. Languages: the one asked for, then the website's default. Null when neither tier holds
 * the template in those languages: the mail then goes out in its wording in code (the third tier).
 * A «Hệ thống» template skips the website's tier; a template only the website's own people get uses
 * the website's default language alone.
 *
 * Also the website's on/off switches (site setting email.templates: {"off": [keys]}), read once per
 * website for the request or job (scoped binding): a batch of invitations asks for each address.
 */
final class MailTemplates
{
    public function __construct(
        private readonly CurrentSite $current,
        private readonly SiteLocale $locales,
        private readonly SettingsRepository $settings,
    ) {}

    /** @var array<int, list<string>> the off list per website id, for this request or job */
    private array $off = [];

    /**
     * @return array{subject: string, body: string, tier: 'site'|'platform', locale: string}|null
     */
    public function stored(string $key, string $locale): ?array
    {
        $t = MailTemplateCatalog::get($key);
        if ($t === null) {
            return null;
        }
        $default = $this->locales->default();
        $order = $t['i18n'] ? array_values(array_unique([$locale, $default])) : [$default];

        $site = [];
        if ($t['kind'] !== MailTemplateCatalog::SYSTEM && $this->current->hasId()) {
            foreach (MailTemplate::query()->where('template_key', $key)->whereIn('locale', $order)->get(['locale', 'subject', 'body']) as $row) {
                $site[$row->locale] = $row;
            }
        }
        $platform = [];
        foreach (PlatformMailTemplate::query()->where('template_key', $key)->whereIn('locale', $order)->get(['locale', 'subject', 'body']) as $row) {
            $platform[$row->locale] = $row;
        }

        foreach ($order as $code) {
            if (isset($site[$code])) {
                return ['subject' => $site[$code]->subject, 'body' => $site[$code]->body, 'tier' => 'site', 'locale' => $code];
            }
            if (isset($platform[$code])) {
                return ['subject' => $platform[$code]->subject, 'body' => $platform[$code]->body, 'tier' => 'platform', 'locale' => $code];
            }
        }

        return null;
    }

    /**
     * The website's turned-off templates (only those it may turn off).
     *
     * @return list<string>
     */
    public function offKeys(): array
    {
        if (! $this->current->hasId()) {
            return [];
        }
        $site = $this->current->id();
        if (! array_key_exists($site, $this->off)) {
            $off = Cast::arr($this->settings->get('email', 'templates')['off'] ?? null);
            $this->off[$site] = array_values(array_filter($off, static fn (mixed $k): bool => is_string($k) && MailTemplateCatalog::switchable($k)));
        }

        return $this->off[$site];
    }

    /** After a switch is written: the next read takes the stored list again. */
    public function forgetOff(): void
    {
        $this->off = [];
    }

    public function isOff(string $key): bool
    {
        return in_array($key, $this->offKeys(), true);
    }
}
