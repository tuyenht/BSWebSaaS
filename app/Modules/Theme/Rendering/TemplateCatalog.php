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

    /**
     * A post's own look laid over the detail layout its category gives it («Mẫu hiển thị» in the
     * editor): never a layout of its own, so no category picks one and it keeps a job or project
     * post's band. Stored in the post's template_override.
     */
    public const string ROLE_POST_VARIANT = 'post-variant';

    /** The post variant whose cover photo leads the page at full width, above the band. */
    public const string VARIANT_HERO = 'hero';

    /** The post variant with no side column: no contents box and no side blocks beside the text. */
    public const string VARIANT_WIDE = 'wide';

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
        self::VARIANT_HERO => ['role' => self::ROLE_POST_VARIANT, 'kinds' => [self::KIND_POST], 'cards' => null, 'set' => null],
        self::VARIANT_WIDE => ['role' => self::ROLE_POST_VARIANT, 'kinds' => [self::KIND_POST], 'cards' => null, 'set' => null],
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
     * layout) it fits this kind of category. A name the theme no longer has never fits. A post
     * variant is drawn by the post layout, so it fits while the theme has that one.
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

        return View::exists('theme::layouts.'.($entry['role'] === self::ROLE_POST_VARIANT ? 'post' : $name));
    }

    /**
     * Whether an entry of this type may store this name as its own template_override: a layout of
     * the type's role, or (a post) one of the post variants.
     */
    public static function fitsEntry(string $name, string $entryType): bool
    {
        $role = self::roleOfEntry($entryType);
        if ($role === null) {
            return false;
        }

        return self::fits($name, $role) || ($role === self::ROLE_POST && self::fits($name, self::ROLE_POST_VARIANT));
    }

    /** The post variant a stored template_override names, or null for none (or a layout). */
    public static function variant(?string $name): ?string
    {
        return $name !== null && self::fits($name, self::ROLE_POST_VARIANT) ? $name : null;
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
