<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\PublishScheduledEntry;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\RestoreEntryRevision;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Support\EntryStatusAudit;
use Illuminate\Support\Facades\File;

/*
 * Every road that puts an entry live records a revision (design.md §8). The roads are known: each
 * one audits its status change through EntryStatusAudit, so that class may only be used by the
 * listed actions — a new road fails here until it is added below, as a publishing road (and then
 * must record a revision) or as one that only takes content offline. The behavioural tests
 * (EntryRevisionRecordingTest) prove each road writes exactly one row per language.
 *
 * Those two — EntryStatusAudit::toOnlyBeUsedIn and the behavioural tests — are the guarantee. The
 * toUse() rules and the source scan below are a coarse tripwire only: toUse() proves a class
 * references RecordEntryRevision, not that every branch calls it, and the scan only sees the
 * literal spellings of "set status to published".
 */

// The roads that change an entry's public status. TransitionEntryStatus only ever takes content
// off the site (draft / private / archived / scheduled — publishing goes through PublishEntry).
$statusRoads = [PublishEntry::class, PublishScheduledEntry::class, ApplyEntryWorkflowTransition::class, TransitionEntryStatus::class];
$publishingRoads = [PublishEntry::class, PublishScheduledEntry::class, ApplyEntryWorkflowTransition::class];

arch('entry status changes happen only on the known roads')
    ->expect(EntryStatusAudit::class)
    ->toOnlyBeUsedIn($statusRoads);

// One rule per class: an array on the expect() side passes as soon as ONE of them complies.
foreach ([...$publishingRoads, Editor::class, Index::class, RestoreEntryRevision::class] as $road) {
    arch(class_basename($road).' records a revision')
        ->expect($road)
        ->toUse(RecordEntryRevision::class);
}

arch('the revision model stays inside the Cms module (other modules go through its actions and queries)')
    ->expect(EntryRevision::class)
    ->toOnlyBeUsedIn(['App\\Modules\\Cms']);

it('marks entries published only inside a road that records a revision', function () use ($publishingRoads): void {
    $allowed = array_map(
        fn (string $class): string => (string) (new ReflectionClass($class))->getFileName(),
        $publishingRoads,
    );

    $violations = [];
    foreach (File::allFiles(app_path('Modules')) as $file) {
        $path = $file->getPathname();
        // Genesis seeders create a site's first published pages before anyone can edit them;
        // cms:snapshot-published gives such content its baseline revision.
        if (str_contains($path, '/Modules/Provisioning/') || ! str_ends_with($path, '.php') || in_array($path, $allowed, true)) {
            continue;
        }

        $source = File::get($path);
        if (preg_match("/'status'\\s*=>\\s*('published'|EntryStatus::Published)/", $source) === 1
            || preg_match("/->status\\s*=\\s*('published'|EntryStatus::Published)/", $source) === 1) {
            $violations[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($violations)->toBe([]);
});
