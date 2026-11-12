<?php

namespace App\Modules\Theme\Rendering;

use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;

/**
 * The storefront's stylesheets past the core one: each kind of block or layout has its own small file,
 * linked in the <head> only when the page's HTML uses one of its classes. The head prints every file
 * between two markers; PageRenderer narrows that list once the whole page is drawn, so a
 * page drawn on any path keeps every rule it could need (a path that does not narrow loads them all).
 *
 * The files keep the order the single stylesheet had (storefront.css first, then FILES in order), so
 * the cascade does not change. A class "root" is the class name up to its first `__` or `--`
 * (`sf-card__title` → `sf-card`); every rule in a file needs one of the file's roots on the page, which
 * BlockStylesTest checks against the CSS.
 */
final class BlockStyles
{
    public const string START = '<!--sf-styles-->';

    public const string END = '<!--/sf-styles-->';

    /** @var array<string, list<string>> Vite input → the class roots that need it, in link order */
    public const array FILES = [
        'resources/css/sf-hero.css' => ['hero'],
        'resources/css/sf-slider.css' => ['sf-slider', 'sf-slide'],
        'resources/css/sf-about.css' => ['about', 'about-band'],
        'resources/css/sf-cta.css' => ['cta'],
        'resources/css/sf-decor.css' => ['sf-decor'],
        'resources/css/sf-cards.css' => ['sf-card', 'sf-cards', 'sf-grid', 'sf-view-all', 'entry-grid', 'latest-posts'],
        'resources/css/sf-lists.css' => ['category', 'pagination', 'sf-chips', 'sf-list', 'sf-empty', 'sf-empty-box', 'sf-cap', 'sf-caps', 'sf-jobs', 'sf-jobs-head', 'sf-zigzag', 'sf-tabs', 'sf-feature', 'sf-features', 'sf-hcard', 'sf-fcard'],
        'resources/css/sf-blocks.css' => ['sf-branch', 'sf-branches', 'sf-faq', 'sf-qlinks', 'sf-quote', 'sf-quotes', 'sf-team', 'sf-member', 'sf-step', 'sf-steps', 'sf-plan', 'sf-plans'],
        'resources/css/sf-rail.css' => ['partners', 'sf-rail'],
        'resources/css/sf-prose.css' => ['sf-prose', 'sf-table', 'richtext', 'sf-doc'],
        'resources/css/sf-post.css' => ['post', 'post-aside', 'post-band', 'post-columns', 'post-cover', 'post-nav', 'post-page', 'post-progress', 'post-related', 'post-share', 'post-toc', 'post-toc-phone', 'slot-categories', 'slot-consult', 'slot-file', 'slot-recent', 'slot-title'],
        'resources/css/sf-product.css' => ['product', 'product-band', 'product-page', 'product-toc', 'sf-preview'],
        'resources/css/sf-form.css' => ['lead-form', 'contact', 'sf-contact-card', 'sf-contact-cards', 'sf-map', 'sf-bmap', 'cf-turnstile'],
        'resources/css/sf-media.css' => ['sf-gallery', 'sf-video', 'sf-video-block', 'sf-viewer'],
        'resources/css/sf-stats.css' => ['sf-stat', 'sf-stats'],
        'resources/css/sf-floating.css' => ['sf-fc', 'sf-fc-bar', 'sf-fc-ghost'],
        'resources/css/sf-404.css' => ['sf-404', 'error-page'],
    ];

    /** Every block stylesheet between the markers (the head's default, before the page is narrowed). */
    public static function all(): HtmlString
    {
        return new HtmlString(self::START.app(Vite::class)(array_keys(self::FILES)).self::END);
    }

    /**
     * Replace the head's full list with the files the rest of the page uses. HTML without the markers
     * is returned unchanged.
     */
    public static function narrow(string $html): string
    {
        $start = strpos($html, self::START);
        $end = $start === false ? false : strpos($html, self::END, $start);
        if ($start === false || $end === false) {
            return $html;
        }
        $end += strlen(self::END);
        $needed = self::needed(substr($html, 0, $start).substr($html, $end));

        return substr_replace($html, $needed === [] ? '' : (string) app(Vite::class)($needed), $start, $end - $start);
    }

    /**
     * The block stylesheets the HTML needs, in link order: a file whose class roots appear in a class
     * attribute (templates included, since a script copies them into the page).
     *
     * @return list<string>
     */
    public static function needed(string $html): array
    {
        preg_match_all('~\sclass="([^"]*)"~', $html, $matches);
        $roots = [];
        foreach (array_unique(preg_split('~\s+~', implode(' ', $matches[1]), -1, PREG_SPLIT_NO_EMPTY) ?: []) as $token) {
            $roots[self::root($token)] = true;
        }

        $files = [];
        foreach (self::FILES as $file => $fileRoots) {
            foreach ($fileRoots as $root) {
                if (isset($roots[$root])) {
                    $files[] = $file;
                    break;
                }
            }
        }

        return $files;
    }

    /** A class name up to its first `__` or `--` (`sf-card__title` → `sf-card`). */
    public static function root(string $class): string
    {
        $cut = strlen($class);
        foreach (['__', '--'] as $mark) {
            $at = strpos($class, $mark);
            if ($at !== false && $at < $cut) {
                $cut = $at;
            }
        }

        return substr($class, 0, $cut);
    }
}
