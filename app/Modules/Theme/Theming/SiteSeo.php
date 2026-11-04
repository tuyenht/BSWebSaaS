<?php

declare(strict_types=1);

namespace App\Modules\Theme\Theming;

use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\PlainText;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What a site tells search engines (the `seo` site setting, key `config`, written by the admin
 * "Hiện trên Google & mạng xã hội" panel): per language a default Google title and description;
 * one title template for the pages without their own title; whether Google may find the site,
 * whether the sitemap is made, and an optional custom robots.txt.
 *
 * Stored shape (anything else reads as the defaults, which keep today's output):
 *   {titles: {locale: text}, descriptions: {locale: text}, template: text,
 *    indexable: bool (true), sitemap: bool (true), robots: text}
 *
 * Read without a new query on every path that serves it. A page already loads the site's
 * settings in one query (StorefrontSettings, whose groups include `seo`); /robots.txt and
 * /sitemap.xml load nothing else, so the value is also kept in the shared cache per site (never in
 * the worker: one Octane worker could not invalidate another). A write to the setting drops that
 * copy once committed (ThemeServiceProvider); the five-minute TTL is only the backstop (a read that
 * raced the write). If the cache is down the database answers.
 */
final class SiteSeo
{
    /** Bump when the SHAPE of the cached value changes — the old value outlives the deploy. */
    private const string KEY = 'site-seo:v1:';

    private const int TTL = 300;

    /** The longest custom robots.txt kept. */
    public const int ROBOTS_MAX = 10000;

    /** What a site without the setting has. */
    public const array DEFAULTS = ['titles' => [], 'descriptions' => [], 'template' => '', 'indexable' => true, 'sitemap' => true, 'robots' => ''];

    /** The meta robots value every page sends while the site is hidden from search engines. */
    public const string HIDDEN = 'noindex, nofollow';

    /** @var array<int, array{titles: array<string, string>, descriptions: array<string, string>, template: string, indexable: bool, sitemap: bool, robots: string}> */
    private array $memo = [];

    public function __construct(
        private readonly CurrentSite $current,
        private readonly StorefrontSettings $settings,
        private readonly Cache $cache,
    ) {}

    /**
     * The current site's settings in their checked shape; the defaults without a site.
     *
     * @return array{titles: array<string, string>, descriptions: array<string, string>, template: string, indexable: bool, sitemap: bool, robots: string}
     */
    public function settings(): array
    {
        if (! $this->current->hasId()) {
            return self::normalize(null);
        }
        $siteId = $this->current->id();

        return $this->memo[$siteId] ??= $this->load($siteId);
    }

    public function indexable(): bool
    {
        return $this->settings()['indexable'];
    }

    /** Whether /sitemap.xml is served: only while Google may find the site and the sitemap is on. */
    public function sitemapOn(): bool
    {
        $settings = $this->settings();

        return $settings['indexable'] && $settings['sitemap'];
    }

    /**
     * A page's <title>: its own Google title when it has one; on the home page the site's default
     * title in that language; on any other page (and a home page without a default title) the
     * template, with %tieu-de% the page's title and %ten-website% the site's name in that language.
     * No template keeps the page's title, as before.
     */
    public function title(?string $own, string $pageTitle, bool $home, string $locale, string $siteName): string
    {
        if ($own !== null && trim($own) !== '') {
            return $own;
        }
        $settings = $this->settings();
        $default = $settings['titles'][$locale] ?? '';
        if ($home && $default !== '') {
            return $default;
        }
        if ($settings['template'] === '') {
            return $pageTitle;
        }
        // strtr replaces each placeholder once: a page title holding "%ten-website%" stays text.
        $title = strtr($settings['template'], ['%tieu-de%' => $pageTitle, '%ten-website%' => $siteName]);
        // An empty part leaves its separator dangling at an end ("Giới thiệu | ").
        $title = trim($title, " \t|-–—·:,");

        return $title !== '' ? $title : $pageTitle;
    }

    /** A page's description: its own when it has one, else the site's default in that language (null = none). */
    public function description(?string $own, string $locale): ?string
    {
        if ($own !== null && trim($own) !== '') {
            return $own;
        }
        $default = $this->settings()['descriptions'][$locale] ?? '';

        return $default !== '' ? $default : null;
    }

    /**
     * The robots.txt body. Hidden from Google: everything is disallowed, whatever the custom text
     * says. Otherwise the custom text, or the safe default (everything allowed); the sitemap's
     * address is added while the sitemap is served and the text names none.
     */
    public function robotsTxt(string $sitemapUrl): string
    {
        $settings = $this->settings();
        if (! $settings['indexable']) {
            return "User-agent: *\nDisallow: /";
        }
        $body = $settings['robots'] !== '' ? $settings['robots'] : "User-agent: *\nDisallow:";
        if ($settings['sitemap'] && preg_match('/^\s*sitemap\s*:/im', $body) !== 1) {
            $body .= "\nSitemap: {$sitemapUrl}";
        }

        return $body;
    }

    /** Drop the request's memo (a writer that changed the setting within this request). */
    public function forgetMemo(): void
    {
        $this->memo = [];
    }

    /** Drop the site's shared copy once the write is committed (at once outside a transaction). */
    public function forget(int $siteId): void
    {
        $this->memo = [];
        DB::afterCommit(function () use ($siteId): void {
            try {
                $this->cache->forget(self::KEY.$siteId);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * A custom robots.txt as it may be kept: plain text with line breaks and tabs, without any other
     * control character, at most ROBOTS_MAX characters, the ends trimmed.
     */
    public static function cleanRobots(string $value): string
    {
        return trim(mb_substr(PlainText::lines($value), 0, self::ROBOTS_MAX));
    }

    /**
     * Any stored value in the checked shape: unknown keys dropped, wrong types read as the defaults.
     *
     * @return array{titles: array<string, string>, descriptions: array<string, string>, template: string, indexable: bool, sitemap: bool, robots: string}
     */
    public static function normalize(mixed $value): array
    {
        $value = Cast::arr($value);
        $texts = static function (mixed $map): array {
            $out = [];
            foreach (Cast::arr($map) as $locale => $text) {
                if (is_string($text) && trim($text) !== '') {
                    $out[$locale] = $text;
                }
            }

            return $out;
        };

        return [
            'titles' => $texts($value['titles'] ?? null),
            'descriptions' => $texts($value['descriptions'] ?? null),
            'template' => trim(Cast::string($value['template'] ?? null)),
            'indexable' => Cast::bool($value['indexable'] ?? null, true),
            'sitemap' => Cast::bool($value['sitemap'] ?? null, true),
            'robots' => self::cleanRobots(Cast::string($value['robots'] ?? null)),
        ];
    }

    /** @return array{titles: array<string, string>, descriptions: array<string, string>, template: string, indexable: bool, sitemap: bool, robots: string} */
    private function load(int $siteId): array
    {
        $key = self::KEY.$siteId;
        try {
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                return self::normalize($cached);
            }
        } catch (Throwable $e) {
            report($e);
        }

        $settings = self::normalize($this->settings->get('seo', 'config'));
        try {
            $this->cache->put($key, $settings, self::TTL);
        } catch (Throwable $e) {
            report($e);
        }

        return $settings;
    }
}
