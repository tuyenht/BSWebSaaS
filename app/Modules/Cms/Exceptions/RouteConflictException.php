<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use App\Modules\Cms\Routing\PathBuilder;
use RuntimeException;

/**
 * Raised when an entry would be routed to a path another entry already owns
 * (routes UNIQUE(site_id, locale, path)). Callers translate it into a friendly
 * "slug taken" validation error instead of a raw DB 500. A path the platform keeps for a page of
 * its own (the site search) is refused the same way; reserved() tells the two apart, so the
 * screen can say which it is. $entryId names the entry whose address was being written (null for a
 * product or a category), so a screen can tell a page's own address from one of its sub-pages' that
 * a publication moves along with it (subPageOf()). $locale is the language whose address it is (null when
 * the caller did not say), so a screen can name the language and show the address as visitors see it.
 */
final class RouteConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $path,
        public readonly ?int $entryId = null,
        public readonly ?string $locale = null,
    ) {
        parent::__construct("The path '{$path}' is already used by another entry.");
    }

    /** The taken address is not $entryId's own but another entry's that moved with it (a sub-page's). */
    public function subPageOf(int $entryId): bool
    {
        return $this->entryId !== null && $this->entryId !== $entryId;
    }

    /**
     * The taken address as visitors see it: the default language unprefixed, any other `/{locale}`
     * (PathBuilder). $fallbackLocale is the language to use when the thrower did not say.
     */
    public function address(?string $fallbackLocale = null): string
    {
        $locale = $this->locale ?? $fallbackLocale;

        return $locale === null ? $this->path : app(PathBuilder::class)->publicUrl($this->path, $locale);
    }

    /** The path is one the platform keeps for a page of its own (PathBuilder::RESERVED_PATHS), not another item's. */
    public function reserved(): bool
    {
        return PathBuilder::isReserved($this->path);
    }
}
