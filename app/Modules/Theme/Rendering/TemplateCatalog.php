<?php

declare(strict_types=1);

namespace App\Modules\Theme\Rendering;

use Illuminate\Support\Facades\View;

/**
 * The one list of layouts the default theme offers to categories, posts and pages: each has a
 * name, a role (a category's list page, a post's page, a plain page), the category kinds it fits
 * (post or product categories) and how its cards load. A group with its own look (jobs, projects)
 * is a pair in this list, a list layout and a post layout of the same set; nothing else in the
 * code names a layout. Pure data plus a view-exists check, so the Theme stays content-agnostic.
 */
final class TemplateCatalog
{
    /** The reserved value of a category's list or detail layout: "use the one of the category above". */
    public const string PARENT = '@parent';

    public const string ROLE_LIST = 'list';

    public const string ROLE_POST = 'post';

    public const string ROLE_PAGE = 'page';

    public const string KIND_POST = 'post';

    public const string KIND_PRODUCT = 'product';

    /** The page layout with a numbered table of contents built from its headings (a policy, terms). */
    public const string PAGE_TOC = 'page-toc';

    /** The set whose posts carry an application deadline. */
    public const string SET_JOB = 'job';

    /**
     * @var array<string, array{role: string, kinds: list<string>, cards: string|null, set: string|null}>
     *
     * `cards` is how a list layout loads its cards (null: a plain list of titles).
     */
    private const array ENTRIES = [
        'category' => ['role' => self::ROLE_LIST, 'kinds' => [self::KIND_POST, self::KIND_PRODUCT], 'cards' => null, 'set' => null],
        'grid-news' => ['role' => self::ROLE_LIST, 'kinds' => [self::KIND_POST], 'cards' => 'news', 'set' => null],
        'grid-project' => ['role' => self::ROLE_LIST, 'kinds' => [self::KIND_POST], 'cards' => 'project', 'set' => null],
        'grid-job' => ['role' => self::ROLE_LIST, 'kinds' => [self::KIND_POST], 'cards' => 'news', 'set' => self::SET_JOB],
        'grid-capability' => ['role' => self::ROLE_LIST, 'kinds' => [self::KIND_POST], 'cards' => 'project', 'set' => null],
        'grid-product' => ['role' => self::ROLE_LIST, 'kinds' => [self::KIND_POST, self::KIND_PRODUCT], 'cards' => 'product', 'set' => null],
        'post' => ['role' => self::ROLE_POST, 'kinds' => [self::KIND_POST], 'cards' => null, 'set' => null],
        'project' => ['role' => self::ROLE_POST, 'kinds' => [self::KIND_POST], 'cards' => null, 'set' => null],
        'capability' => ['role' => self::ROLE_POST, 'kinds' => [self::KIND_POST], 'cards' => null, 'set' => null],
        'job' => ['role' => self::ROLE_POST, 'kinds' => [self::KIND_POST], 'cards' => null, 'set' => self::SET_JOB],
        'page' => ['role' => self::ROLE_PAGE, 'kinds' => [], 'cards' => null, 'set' => null],
        self::PAGE_TOC => ['role' => self::ROLE_PAGE, 'kinds' => [], 'cards' => null, 'set' => null],
    ];

    /** The kind of category a vocabulary holds. */
    public static function kindOf(string $vocabulary): string
    {
        return $vocabulary === 'product_cat' ? self::KIND_PRODUCT : self::KIND_POST;
    }

    /** The role an entry type's own page has. */
    public static function roleOfEntry(string $type): ?string
    {
        return match ($type) {
            'post' => self::ROLE_POST,
            'page' => self::ROLE_PAGE,
            default => null,
        };
    }

    /**
     * Whether the theme has this layout, it is in the list with this role and (for a category
     * layout) it fits this kind of category. A name the theme no longer has never fits.
     */
    public static function fits(string $name, string $role, ?string $kind = null): bool
    {
        $entry = self::ENTRIES[$name] ?? null;
        if ($entry === null || $entry['role'] !== $role) {
            return false;
        }
        if ($kind !== null && ! in_array($kind, $entry['kinds'], true)) {
            return false;
        }

        return View::exists('theme::layouts.'.$name);
    }

    /**
     * The layout names of a role (and kind) the theme has, sorted: what a picker offers.
     *
     * @return list<string>
     */
    public static function names(string $role, ?string $kind = null): array
    {
        $names = array_values(array_filter(array_keys(self::ENTRIES), fn (string $name): bool => self::fits($name, $role, $kind)));
        sort($names);

        return $names;
    }

    /** How a list layout loads its cards: news, project, product — null for a plain list. */
    public static function cards(?string $name): ?string
    {
        return $name === null ? null : (self::ENTRIES[$name]['cards'] ?? null);
    }

    /** Whether a layout belongs to the job set (its posts carry an application deadline). */
    public static function isJob(?string $name): bool
    {
        return $name !== null && (self::ENTRIES[$name]['set'] ?? null) === self::SET_JOB;
    }
}
