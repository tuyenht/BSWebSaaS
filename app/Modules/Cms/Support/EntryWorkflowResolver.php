<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Services\WorkflowEngine;

/**
 * Answers ONE question for the Cms consumers of the workflow engine: which active workflow, if
 * any, governs this content? The answer is derived, not stored — an entry's section comes from
 * its primary category (SectionResolver), and the engine only reports a workflow the site has
 * switched on. Null means tier-1 permission-driven publishing, which is every site's default.
 *
 * Every road to `published` (publish, schedule, the scheduler) asks here, so the gate cannot be
 * bypassed by one path forgetting the check. A page never resolves: it carries no taxonomy, so
 * pages stay tier-1 by construction (design.md §15.4).
 */
final readonly class EntryWorkflowResolver
{
    public function __construct(
        private SectionResolver $sections,
        private WorkflowEngine $engine,
    ) {}

    public function forEntry(Entry $entry): ?Workflow
    {
        $section = $this->sections->forEntry($entry);

        return $section !== null ? $this->engine->activeFor($section['key']) : null;
    }

    /** For content that does not exist yet: the workflow its chosen primary category would put it under. */
    public function forCategory(Taxonomy $primary): ?Workflow
    {
        $section = $this->sections->forCategory($primary);

        return $section !== null ? $this->engine->activeFor($section['key']) : null;
    }
}
