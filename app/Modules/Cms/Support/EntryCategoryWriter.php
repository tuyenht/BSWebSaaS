<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * The one road by which a person's action files an entry under categories (the provisioning
 * seeders, which act for nobody, and products are the only other callers of assignCategories —
 * an arch test keeps that list). It applies the member's category limits (CategoryScopes):
 *
 *  - the primary category (index 0) must lie inside them (EntryPolicy::fileUnder), else the whole
 *    write is refused with the sentence that names the member's categories;
 *  - another category may be added or removed only inside them;
 *  - a category outside them that the post already carries stays, after the requested ones — the
 *    member can neither see it go nor take it off.
 *
 * For a member who is not limited — or when no person acts (a pending change folded in by the
 * system) — the requested list is written exactly as given. Runs inside the caller's transaction
 * and tenant context.
 */
final class EntryCategoryWriter
{
    /**
     * File $entry under $categoryIds (index 0 = primary) on behalf of $actor (null: no person acts).
     *
     * @param  list<int>  $categoryIds
     *
     * @throws AuthorizationException the primary category, or an added or removed one, lies outside the actor's limits
     */
    public function assign(Entry $entry, array $categoryIds, ?User $actor): void
    {
        $entry->assignCategories($this->resolve($entry, $categoryIds, $actor));
        $entry->unsetRelation('taxonomies');
    }

    /**
     * The categories to write: the request, checked against the actor's limits, plus those outside
     * the limits the entry keeps.
     *
     * @param  list<int>  $categoryIds
     * @return list<int>
     *
     * @throws AuthorizationException
     */
    public function resolve(Entry $entry, array $categoryIds, ?User $actor): array
    {
        $categoryIds = array_values(array_unique($categoryIds));
        if ($actor === null) {
            return $categoryIds;
        }
        $response = Gate::forUser($actor)->inspect('fileUnder', [Entry::class, $entry->type, $categoryIds[0] ?? null]);
        if ($response->denied()) {
            throw new AuthorizationException($response->message());
        }

        $scopes = app(CategoryScopes::class);
        if (! $scopes->isLimited($actor)) {
            return $categoryIds;
        }

        $current = $entry->exists ? $this->currentCategories($entry) : [];
        foreach ($categoryIds as $id) {
            if (! $scopes->coversId($actor, $id) && ! in_array($id, $current, true)) {
                throw new AuthorizationException($scopes->refusal($actor));   // adding one outside
            }
        }

        $kept = [];
        foreach ($current as $id) {
            if (! in_array($id, $categoryIds, true) && ! $scopes->coversId($actor, $id)) {
                $kept[] = $id;   // removing one outside is not theirs to do: it stays
            }
        }

        return [...$categoryIds, ...$kept];
    }

    /**
     * The entry's categories now, in order — facet terms excluded.
     *
     * @return list<int>
     */
    private function currentCategories(Entry $entry): array
    {
        $ids = [];
        foreach ($entry->taxonomies()->wherePivot('position', '<', Entry::FACET_POSITION_BASE)->get(['taxonomies.id']) as $taxonomy) {
            $ids[] = Cast::int($taxonomy->getKey());
        }

        return $ids;
    }
}
