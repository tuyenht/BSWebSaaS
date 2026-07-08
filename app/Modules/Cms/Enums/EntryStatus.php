<?php

declare(strict_types=1);

namespace App\Modules\Cms\Enums;

/**
 * Canonical lifecycle states of a content entry — the entries_status_check set
 * (design.md §8/§15.4 + Pages-admin design §3a). draft/pending/published/archived are the
 * Tầng-1 workflow states; `scheduled` (content ready, auto-published at published_at by
 * entries:publish-due) and `private` (finished but deliberately non-public) extend the pages
 * surface. Only Published is ever publicly resolvable — RouteResolver serves nothing else —
 * so scheduled/private are non-public without a separate visibility engine.
 */
enum EntryStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Published = 'published';
    case Scheduled = 'scheduled';
    case Private = 'private';
    case Archived = 'archived';

    /** Only a published entry is served on the storefront (RouteResolver::resolveEntry). */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }

    /** Human label via i18n (the entries.status_<value> key). */
    public function label(): string
    {
        return __('entries.status_'.$this->value);
    }
}
