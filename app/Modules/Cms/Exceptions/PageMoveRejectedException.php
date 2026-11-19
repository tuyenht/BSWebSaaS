<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use DomainException;
use Throwable;

/**
 * A move in a site's page tree refused for a BUSINESS reason the screen explains in plain words. Its
 * own type so no broad `catch (RuntimeException)` swallows it. The message IS the reason key, one of:
 * page_invalid (not a live page of this site) · parent_invalid (the new parent is not a live page of
 * this site) · home_locked (the Home page keeps its place in the tree) · parent_home (the Home page has
 * no sub-pages) · cycle (into the page itself or a page under it) · too_deep (more than
 * PageRules::MAX_DEPTH levels; $height says how many levels the moved branch has) · branch_too_big
 * (too many pages under it to move at once) · path_taken ($path: an address the move would give is
 * another item's).
 */
final class PageMoveRejectedException extends DomainException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $height = 1,
        public readonly string $path = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }
}
