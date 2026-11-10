<?php

declare(strict_types=1);

namespace App\Modules\Cms\Events;

/**
 * An entry went public: it was created published, or its status changed to published by any path
 * (publish now, a scheduled publish coming due, an approval). Dispatched by EntryObserver inside
 * the same transaction, in the entry's site context, so a listener's writes commit or roll back
 * with the publish.
 */
final readonly class EntryPublished
{
    public function __construct(
        public int $entryId,
        public string $type,
    ) {}
}
