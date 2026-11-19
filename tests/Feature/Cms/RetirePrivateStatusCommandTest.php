<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\EntryStatusAudit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * `cms:retire-private-status` moves the posts and pages still in the retired «Không công khai»
 * (`private`) to «Gỡ khỏi website» (`archived`), once: each row through the status engine (one audit
 * row naming the system), per site in its tenant context, trashed rows too, translations untouched,
 * the workflow stage cleared. --dry-run only counts per site, and a second run moves nothing.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** An entry in $status on $siteId, with one Vietnamese translation in $translationStatus; returns its id. */
function rpsEntry(int $siteId, string $status, string $slug, string $translationStatus = 'draft', bool $trashed = false): int
{
    return app(Tenancy::class)->enter($siteId, function () use ($status, $slug, $translationStatus, $trashed): int {
        $entry = Entry::query()->create(['type' => 'page', 'status' => $status]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Trang '.$slug, 'slug' => $slug, 'status' => $translationStatus, 'body' => ['blocks' => []]]);
        if ($trashed) {
            $entry->delete();
        }

        return $entry->id;
    });
}

/** @return array{status: string, translation: string, workflow_state: string|null} */
function rpsState(int $siteId, int $id): array
{
    return app(Tenancy::class)->enter($siteId, function () use ($id): array {
        $entry = Entry::withTrashed()->findOrFail($id);

        return ['status' => $entry->status, 'translation' => (string) $entry->translations()->value('status'), 'workflow_state' => $entry->workflow_state];
    });
}

/** @return list<array{0: int, 1: string, 2: string, 3: string, 4: int|null, 5: string|null}> [entry id, from, to, via, actor id, actor label] */
function rpsAudit(int $siteId): array
{
    return app(Tenancy::class)->enter($siteId, fn (): array => AuditLog::query()
        ->where('action', EntryStatusAudit::ACTION)->where('site_id', $siteId)->orderBy('id')->get()
        ->map(fn (AuditLog $row): array => [(int) $row->auditable_id, $row->meta['from'], $row->meta['to'], $row->meta['via'], $row->actor_id, $row->actor_label])
        ->all());
}

it('counts per site on a dry run and writes nothing', function (): void {
    $a1 = rpsEntry(1, 'private', 'a-mot');
    $a2 = rpsEntry(1, 'private', 'a-hai', trashed: true);
    rpsEntry(1, 'draft', 'a-nhap');
    $b1 = rpsEntry(2, 'private', 'b-mot');

    $this->artisan('cms:retire-private-status', ['--dry-run' => true])
        ->expectsOutputToContain("site 1: would move 2 entr(y/ies) private → archived: {$a1}, {$a2}")
        ->expectsOutputToContain("site 2: would move 1 entr(y/ies) private → archived: {$b1}")
        ->expectsOutputToContain('Dry run: would move 3 entr(y/ies) private → archived on 2 site(s).')
        ->assertSuccessful();

    expect(rpsState(1, $a1)['status'])->toBe('private')
        ->and(rpsState(1, $a2)['status'])->toBe('private')
        ->and(rpsState(2, $b1)['status'])->toBe('private')
        ->and(rpsAudit(1))->toBe([])
        ->and(rpsAudit(2))->toBe([]);
});

it('moves every private entry of every site to archived through the engine, trashed ones too, with a system audit row, and leaves the rest alone', function (): void {
    $a1 = rpsEntry(1, 'private', 'a-mot', translationStatus: 'published');
    $a2 = rpsEntry(1, 'private', 'a-hai', trashed: true);
    $draft = rpsEntry(1, 'draft', 'a-nhap');
    $live = rpsEntry(1, 'published', 'a-dang', translationStatus: 'published');
    $b1 = rpsEntry(2, 'private', 'b-mot');
    $edited = '2026-01-02 03:04:05+00';
    app(Tenancy::class)->enter(1, fn () => DB::table('entries')->where('id', $a1)->update(['updated_at' => $edited]));

    $this->artisan('cms:retire-private-status')
        ->expectsOutputToContain("site 1: moved 2 entr(y/ies) private → archived: {$a1}, {$a2}")
        ->expectsOutputToContain("site 2: moved 1 entr(y/ies) private → archived: {$b1}")
        ->expectsOutputToContain('Moved 3 entr(y/ies) private → archived on 2 site(s).')
        ->assertSuccessful();

    // translations keep their own statuses: only the entry's status decides what the website serves
    expect(rpsState(1, $a1))->toBe(['status' => 'archived', 'translation' => 'published', 'workflow_state' => null])
        ->and(rpsState(1, $a2)['status'])->toBe('archived')
        ->and(rpsState(1, $draft)['status'])->toBe('draft')
        ->and(rpsState(1, $live)['status'])->toBe('published')
        ->and(rpsState(2, $b1)['status'])->toBe('archived')
        // each site's rows audited in that site, by the system (no member), on its own road
        ->and(rpsAudit(1))->toBe([
            [$a1, 'private', 'archived', TransitionEntryStatus::VIA_RETIRE_PRIVATE, null, 'system'],
            [$a2, 'private', 'archived', TransitionEntryStatus::VIA_RETIRE_PRIVATE, null, 'system'],
        ])
        ->and(rpsAudit(2))->toBe([[$b1, 'private', 'archived', TransitionEntryStatus::VIA_RETIRE_PRIVATE, null, 'system']]);

    // no member edited it: its edit time stays (the list's «Sửa …» and its sort are unchanged)
    expect(app(Tenancy::class)->enter(1, fn () => Entry::query()->whereKey($a1)->first()?->updated_at?->equalTo(CarbonImmutable::parse($edited))))->toBeTrue();

    // nothing the visitor sees changed: neither state is served
    $this->get('http://a.test/a-mot')->assertNotFound();
});

it('moves nothing on a second run', function (): void {
    $a1 = rpsEntry(1, 'private', 'a-mot');

    $this->artisan('cms:retire-private-status')->expectsOutputToContain('Moved 1 entr(y/ies)')->assertSuccessful();
    $this->artisan('cms:retire-private-status')
        ->expectsOutputToContain('Moved 0 entr(y/ies) private → archived on 2 site(s).')
        ->doesntExpectOutputToContain('site 1:')
        ->assertSuccessful();

    expect(rpsState(1, $a1)['status'])->toBe('archived')
        ->and(rpsAudit(1))->toHaveCount(1);
});

it('walks past one chunk of rows', function (): void {
    $ids = app(Tenancy::class)->enter(1, fn (): array => collect(range(1, 205))
        ->map(fn (): int => Entry::query()->create(['type' => 'post', 'status' => 'private'])->id)->all());

    $this->artisan('cms:retire-private-status', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run: would move 205 entr(y/ies)')->assertSuccessful();
    $this->artisan('cms:retire-private-status')->expectsOutputToContain('Moved 205 entr(y/ies)')->assertSuccessful();

    expect(app(Tenancy::class)->enter(1, fn (): int => Entry::query()->whereKey($ids)->where('status', 'archived')->count()))->toBe(205);
});

it('clears the workflow stage of a private post its section governs, so only approval brings it back', function (): void {
    $editor = User::factory()->create();

    $id = app(Tenancy::class)->enter(1, function () use ($editor): int {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.submit', 'entries.approve', 'entries.publish']);
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
        ContentSection::query()->firstOrCreate(['key' => 'tin-tuc'], ['label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id]);
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft']);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Bản tin', 'slug' => 'ban-tin', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $entry->assignCategories([$category->id]);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'tin-tuc', active: true);
        $move = app(ApplyEntryWorkflowTransition::class);
        $move->handle($entry->fresh(), 'vi', $editor->fresh(), 'submit');
        $move->handle($entry->fresh(), 'vi', $editor->fresh(), 'approve');
        // set private while the old menu still offered it: the stage stayed behind
        DB::table('entries')->where('id', $entry->id)->update(['status' => 'private']);

        return $entry->id;
    });
    expect(rpsState(1, $id)['workflow_state'])->toBe('published');

    $this->artisan('cms:retire-private-status')->assertSuccessful();

    expect(rpsState(1, $id)['status'])->toBe('archived')
        ->and(rpsState(1, $id)['workflow_state'])->toBeNull();
});
