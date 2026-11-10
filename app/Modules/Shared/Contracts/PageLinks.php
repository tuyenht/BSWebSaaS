<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * The current addresses of pages and posts named by their ulid — what a block button that opens a
 * page of the site links to (the button stores the ulid, so a renamed slug never breaks it). Lives
 * in Shared\Contracts so the Theme renders those buttons without depending on Cms, which owns the
 * routes and implements it (acyclic boundary).
 */
interface PageLinks
{
    /**
     * Ulid => locale-prefixed public path, only for entries of the current site a visitor can open
     * in $locale (published, with a published translation in that locale, not in the Trash). One
     * query for the whole list.
     *
     * @param  list<string>  $ulids
     * @return array<string, string>
     */
    public function paths(array $ulids, string $locale): array;
}
