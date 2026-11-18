<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Services\ReviewQueue;

/**
 * Is this entry waiting for this person to act on it? The same answer the review inbox gives for
 * its rows (ReviewWaiting): the entry sits, in its own section, on a stage the person may move it
 * out of (ReviewQueue), and inside their category limits. An unsent draft, live content, an entry
 * of a section the person does not review, or one parked at another person's step is not.
 *
 * The person's stages are read once per request (one read of the active workflows), so asking it
 * for every row of a list costs no query per row once the rows' sections are primed (prime()).
 * Registered scoped (CmsServiceProvider): the memo never outlives the request.
 */
final class EntryReviewReach
{
    /** @var array<int, array<string, list<string>>> user id => section key => stage keys */
    private array $stages = [];

    public function __construct(
        private readonly ReviewQueue $queue,
        private readonly SectionResolver $sections,
        private readonly CategoryScopes $scopes,
    ) {}

    public function waitsFor(User $actor, Entry $entry): bool
    {
        if ($entry->trashed() || ! $this->onSomeStage($actor, $entry)) {
            return false;
        }

        // the limits first: they are read from memory, the section may need a query
        return $this->scopes->coversEntry($actor, $entry) && $this->sections->onStage($entry, $this->stagesOf($actor));
    }

    /**
     * Resolve, in a few queries for the whole list, the sections of the rows that could be waiting for
     * this person (their stage is one the person acts on), so waitsFor() then answers from memory.
     *
     * @param  iterable<Entry>  $entries  with their primary category loaded
     */
    public function prime(User $actor, iterable $entries): void
    {
        $candidates = [];
        foreach ($entries as $entry) {
            if ($this->onSomeStage($actor, $entry)) {
                $candidates[] = $entry;
            }
        }
        if ($candidates !== []) {
            $this->sections->prime($candidates);
        }
    }

    /** The entry's stage is one this person acts on in some section: a cheap test before the section is read. */
    private function onSomeStage(User $actor, Entry $entry): bool
    {
        $state = $entry->workflow_state;
        if ($state === null) {
            return false;
        }
        foreach ($this->stagesOf($actor) as $keys) {
            if (in_array($state, $keys, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, list<string>> */
    private function stagesOf(User $actor): array
    {
        return $this->stages[$actor->id] ??= $this->queue->actionableStages($actor);
    }
}
