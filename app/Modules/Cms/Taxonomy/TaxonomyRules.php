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
}
