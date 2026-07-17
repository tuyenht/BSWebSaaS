<?php

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Exceptions\WorkflowGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Services\WorkflowEngine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Workflow Tầng-1 (design.md §15.4 — permission-driven, no configuration). An actor
 * carrying entries.publish publishes straight through: entry + translation go
 * `published`, published_at is stamped, and the route is synced (with a 301 on a slug
 * change). Anyone else who can submit parks the entry as `pending` for a publisher to
 * review. Only published entries with a published translation reach the public
 * (RouteResolver).
 *
 * Tầng-1 remains the behaviour for every site that has not switched a workflow on — which is
 * all of them by default. Where one IS active, this straight-through path would be a hole
 * around the approval gate it exists to enforce, so it refuses and sends the caller to
 * ApplyEntryWorkflowTransition instead.
 */
final readonly class PublishEntry
{
    public function __construct(
        private RouteSync $routes,
        private SectionResolver $sections,
        private WorkflowEngine $engine,
    ) {}

    /**
     * @return 'published'|'pending' the resulting lifecycle status
     *
     * @throws WorkflowGovernedException a workflow governs this entry — move it via a transition
     */
    public function handle(Entry $entry, string $locale, User $actor): string
    {
        $translation = $entry->translationFor($locale)
            ?? throw new RuntimeException("Entry {$entry->id} has no '{$locale}' translation to publish.");

        // Tier-2 wins where it is active: publishing must not skip the stages a site configured.
        $section = $this->sections->forEntry($entry);
        if ($section !== null && $this->engine->activeFor($section['key']) !== null) {
            throw new WorkflowGovernedException;
        }

        $gate = Gate::forUser($actor);

        if ($gate->allows('publish', $entry)) {
            // Atomic: a RouteConflictException from sync must roll back the status flip, or the
            // entry is left `published` with no reachable route — a silent 404.
            DB::transaction(function () use ($entry, $translation, $locale): void {
                $entry->update([
                    'status' => 'published',
                    'published_at' => $entry->published_at ?? now(),
                ]);
                $translation->update(['status' => 'published']);

                $this->routes->sync($entry, $locale, $translation->slug);
            });

            return 'published';
        }

        if ($gate->allows('submit', $entry)) {
            // Never demote a live page: a submitter without publish rights must not be
            // able to take a published entry offline by re-submitting it.
            if ($entry->status === 'published') {
                return 'published';
            }

            $entry->update(['status' => 'pending']);
            $translation->update(['status' => 'pending']);

            return 'pending';
        }

        throw new AuthorizationException('Bạn không có quyền đăng hoặc gửi duyệt nội dung này.');
    }
}
