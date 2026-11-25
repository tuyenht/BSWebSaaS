<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The saved views of a content section's post list: each member keeps their own under
 * `users.preferences.lists["entries.post.<section key>"]` (the columns and card fields they chose).
 * A section that is removed leaves those keys behind; prune() drops them for the site's members, so
 * a later section under the same key starts from the default view. A member who also works on
 * another website with a section under the same key keeps one saved view for both lists (the key
 * names no website), so pruning resets that one too: it only goes back to the default columns.
 */
final class SectionListViews
{
    /** The preferences key of a post section's list. */
    public static function key(string $section): string
    {
        return 'entries.post.'.$section;
    }

    /**
     * The members of the website who keep a saved view of the section's list.
     *
     * @return Builder<User>
     */
    public static function members(int $siteId, string $section): Builder
    {
        return User::query()
            ->whereIn('id', fn (QueryBuilder $members) => $members->select('model_id')->from('model_has_roles')
                ->where('site_id', $siteId)->where('model_type', (new User)->getMorphClass()))
            ->whereRaw("jsonb_typeof(preferences #> array['lists', ?]::text[]) is not null", [self::key($section)]);
    }

    /**
     * The saved views before they go, member id → view: what a backup keeps.
     *
     * @return array<int, mixed>
     */
    public static function saved(int $siteId, string $section): array
    {
        $views = [];
        foreach (self::members($siteId, $section)->orderBy('id')->get(['id', 'preferences']) as $member) {
            $lists = $member->preferences['lists'] ?? null;
            $views[$member->id] = is_array($lists) ? ($lists[self::key($section)] ?? null) : null;
        }

        return $views;
    }

    /** Drop the section's saved view from every member of the website who has one; returns how many. */
    public static function prune(int $siteId, string $section): int
    {
        $user = new User;
        $pruned = 0;
        foreach (array_chunk(array_map(fn (mixed $id): int => Cast::int($id), self::members($siteId, $section)->orderBy('id')->pluck('id')->all()), 1000) as $ids) {
            $pruned += DB::connection($user->getConnectionName())->update(
                "update {$user->getTable()} set preferences = preferences #- array['lists', ?]::text[] where id in (".implode(', ', array_fill(0, count($ids), '?')).')',
                [self::key($section), ...$ids],
            );
        }

        return $pruned;
    }
}
