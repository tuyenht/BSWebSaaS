<?php

declare(strict_types=1);

namespace App\Modules\Cms\Taxonomy;

use App\Modules\Shared\Support\Slugger;
use Illuminate\Support\Facades\View;

/**
 * The category rules the write path (Cms\Actions\Taxonomies) enforces, in one place so the screen
 * can show the same limits it will be held to.
 */
final class TaxonomyRules
{
    /**
     * Levels a category tree may have, a top-level category being level 1. Raising it later is
     * safe; lowering it would leave existing deeper categories beyond the rule.
     */
    public const int MAX_DEPTH = 3;

    public const int NAME_MAX = 120;

    public const int SLUG_MAX = 200;

    /**
     * A category's description is a short plain-text introduction shown above its list (a few
     * sentences; the design's field is a 3-row textarea). The column is `text`, so the cap is ours:
     * it keeps the category page's header and the Categories screen's payload (every row carries
     * its descriptions) small, and long-form writing belongs in a post or a page.
     */
    public const int DESCRIPTION_MAX = 1000;

    /** The Google title and description columns are varchar(255), as on posts (EntryContentRules). */
    public const int SEO_MAX = 255;

    /** The same shape the entry and product editors accept: lower-case words joined by hyphens. */
    public const string SLUG_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    /** A vocabulary key: post_cat, product_cat, sector, tag… */
    public const string VOCABULARY_PATTERN = '/\A[a-z][a-z0-9_]{0,31}\z/';

    /** A theme layout name (theme::layouts.{name}); never a path. */
    public const string TEMPLATE_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,63}\z/';

    /** The name to store — trimmed, 1–NAME_MAX characters — or null when it is not acceptable. */
    public static function name(string $name): ?string
    {
        $name = trim($name);

        return $name === '' || mb_strlen($name) > self::NAME_MAX ? null : $name;
    }

    /**
     * The description to store — plain text with line breaks kept (as \n), invisible format and
     * control characters removed, the ends trimmed, at most DESCRIPTION_MAX characters; '' when
     * nothing is left (the description is cleared). Null when it is not acceptable (too long, or
     * not valid UTF-8).
     */
    public static function description(string $text): ?string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // The joiners (U+200C, U+200D) stay: they hold emoji sequences and some scripts together.
        $clean = preg_replace('/(?![\x{200C}\x{200D}])\p{Cf}|[^\P{Cc}\n\t]/u', '', $text);
        if ($clean === null) {
            return null;
        }
        $clean = trim($clean);

        return mb_strlen($clean) > self::DESCRIPTION_MAX ? null : $clean;
    }

    /**
     * A Google title or description to store — one line, invisible format characters removed (as
     * in description(), the joiners kept), the ends trimmed, at most SEO_MAX characters; '' when
     * empty (cleared). Null when it is not acceptable: longer, not valid UTF-8, or holding a line
     * break or another control character (the screen sends it as one line).
     */
    public static function seoText(string $text): ?string
    {
        if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $text) !== 0) {
            return null;
        }
        $text = preg_replace('/(?![\x{200C}\x{200D}])\p{Cf}/u', '', $text);
        if ($text === null) {
            return null;
        }
        $text = trim($text);

        return mb_strlen($text) > self::SEO_MAX ? null : $text;
    }

    /** The slug to store — trimmed, SLUG_PATTERN, at most SLUG_MAX characters — or null. */
    public static function slug(string $slug): ?string
    {
        $slug = trim($slug);

        return strlen($slug) <= self::SLUG_MAX && preg_match(self::SLUG_PATTERN, $slug) === 1 ? $slug : null;
    }

    /** A slug made from a name in any writing system, '' when nothing usable is left. */
    public static function slugFrom(string $name, ?string $locale = null): string
    {
        return Slugger::make($name, $locale, self::SLUG_MAX);
    }

    /**
     * Whether a list/detail template names a layout the theme actually has — an unknown name would
     * break the category's pages instead of falling back.
     */
    public static function template(string $template): bool
    {
        return preg_match(self::TEMPLATE_PATTERN, $template) === 1 && View::exists('theme::layouts.'.$template);
    }

    /** Only these layouts render a post: the list layouts expect a page of cards and the product layout a product. */
    public const array POST_DETAIL_TEMPLATES = ['post', 'project'];

    /** Whether a post category may take this layout for its posts' detail page (it must also exist in the theme). */
    public static function detailTemplate(string $template): bool
    {
        return in_array($template, self::POST_DETAIL_TEMPLATES, true) && self::template($template);
    }
}
