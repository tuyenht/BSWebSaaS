<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;

/**
 * Why a member may not change a post or a page, in the design's words — said by the list's row
 * actions and by the editor alike, so a refusal reads the same on both screens.
 */
final class EntryRefusal
{
    /**
     * Why this member may not $verb this entry: outside their category limits (a page: every page,
     * for a limited member), someone else's record for an own-only right, or not a right their role has.
     *
     * @param  'edit'|'delete'|'status'  $verb
     */
    public static function for(User $user, Entry $entry, string $verb): string
    {
        $scopes = app(CategoryScopes::class);
        if (! $scopes->coversEntry($user, $entry)) {
            return self::scopeWhy($entry->type, $scopes->refusal($user));
        }
        if ($verb === 'status') {
            return Cast::string(__('entries.refused_status'));
        }
        $noun = __('entries.noun_'.$entry->type);
        $own = $entry->author_id !== null && $entry->author_id === $user->id;

        return Cast::string(! $own && $user->can('entries.'.$verb.'.own')
            ? __('entries.refused_others_'.$verb, ['noun' => $noun])
            : __('entries.refused_role_'.$verb, ['noun' => $noun]));
    }

    /**
     * The limits sentence for an entry of $type: for a page it adds that pages are out of reach (a
     * page has no category, so no limit ever covers it).
     */
    public static function scopeWhy(string $type, string $refusal): string
    {
        return $type === 'page'
            ? Cast::string(__('entries.scope_page_refusal', ['why' => preg_replace('/[.。]$/u', '', $refusal) ?? $refusal]))
            : $refusal;
    }
}
