<?php

use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Models\Workflow;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * Regression for ca62f01: the row lock inside ApplyEntryWorkflowTransition must be acquired
 * BEFORE the entry is re-read, not after — otherwise two reviewers racing the same post would
 * both read the pre-lock stage, queue on the lock, and both pass the engine's origin guard (see
 * the action's own doc comment on that line). A genuine two-connection race is exactly what the
 * deterministic tests in this suite avoid (README.md); the invariant that actually broke — and
 * the one worth locking in — is QUERY ORDER inside the transaction, which is cheap and never
 * flaky to assert directly, unlike a real race.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('locks the entry row before reading its workflow stage', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
        ContentSection::query()->firstOrCreate(
            ['key' => 'tin-tuc'],
            ['label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id],
        );

        $workflow = Workflow::query()->create([
            'key' => 'editorial', 'name' => 'Quy trình biên tập',
            'applies_to_type' => 'tin-tuc', 'template' => 'editorial', 'is_active' => true,
        ]);
        $workflow->states()->create(['key' => 'draft', 'label' => 'Bản nháp', 'is_initial' => true]);
        $workflow->states()->create(['key' => 'pending', 'label' => 'Chờ duyệt']);
        $workflow->transitions()->create([
            'key' => 'submit', 'label' => 'Gửi duyệt', 'from_state' => 'draft', 'to_state' => 'pending',
            'required_permission' => 'entries.submit',
        ]);

        $author = User::factory()->create();
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        $author = $author->fresh() ?? throw new RuntimeException('Author vanished after creation.');

        $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
        $entry->translations()->create([
            'locale' => 'vi', 'title' => 'Bản tin', 'slug' => 'ban-tin', 'status' => 'draft', 'body' => ['blocks' => []],
        ]);
        $entry->assignCategories([$category->id]);
        $entry = $entry->fresh() ?? throw new RuntimeException('Entry vanished after creation.');

        /** @var list<string> $queries */
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(ApplyEntryWorkflowTransition::class)->handle($entry, 'vi', $author, 'submit');

        // Isolate the two `entries` SELECTs the action issues: the lock, then the post-lock
        // refresh. Matching on the table name keeps an unrelated select elsewhere (the
        // translation lookup, the workflow engine, the audit write) from ever being picked up.
        $entrySelects = array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_starts_with(strtolower(trim($sql)), 'select')
                && str_contains(strtolower($sql), 'from "entries"'),
        ));

        expect($entrySelects)->toHaveCount(2);
        expect(strtolower($entrySelects[0]))->toContain('for update');
        expect(strtolower($entrySelects[1]))->not->toContain('for update');
    });
});
