<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The table holding pending changes to live content (design.md §8 `entry_drafts`). Nothing writes
 * it yet; these tests pin what the database itself guarantees before any code relies on it: a
 * site sees and writes only its own changes (RLS, fail-closed without a context), one change per
 * entry, a content shape the CHECK enforces, and purge/user-removal behaviour of its keys.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** A published post in the current site context. */
function draftSchemaEntry(): Entry
{
    return Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => now(), 'meta' => []]);
}

/** @return array{v: int, shared: array<string, mixed>, locales: array<string, array<string, mixed>>} */
function draftSchemaContent(string $title = 'Tiêu đề mới'): array
{
    return ['v' => 1, 'shared' => [], 'locales' => ['vi' => ['title' => $title]]];
}

/** Insert a raw row inside a savepoint, so a rejected insert leaves the tenant transaction usable. */
function draftSchemaInsert(array $row): void
{
    DB::transaction(fn () => DB::table('entry_drafts')->insert($row + [
        'created_at' => now(), 'updated_at' => now(),
    ]));
}

it('shows a site only its own pending changes, even through raw queries', function (): void {
    $tenancy = app(Tenancy::class);

    $tenancy->enter(1, function (): void {
        EntryDraft::query()->create(['entry_id' => draftSchemaEntry()->id, 'content' => draftSchemaContent('Của site A')]);
    });

    $tenancy->enter(2, function (): void {
        EntryDraft::query()->create(['entry_id' => draftSchemaEntry()->id, 'content' => draftSchemaContent('Của site B')]);

        // RLS, not only the model scope: a raw read sees nothing of site 1 either.
        expect(DB::table('entry_drafts')->count())->toBe(1)
            ->and(EntryDraft::query()->sole()->content['locales']['vi']['title'])->toBe('Của site B');
    });

    $tenancy->enter(1, function (): void {
        expect(DB::table('entry_drafts')->count())->toBe(1)
            ->and(EntryDraft::query()->sole()->content['locales']['vi']['title'])->toBe('Của site A');
    });
});

it('is fail-closed: with no site context a pending change is invisible', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        EntryDraft::query()->create(['entry_id' => draftSchemaEntry()->id, 'content' => draftSchemaContent()]);
    });

    expect(DB::connection('pgsql_admin')->table('entry_drafts')->count())->toBe(1)
        ->and(DB::table('entry_drafts')->count())->toBe(0)
        ->and(EntryDraft::query()->count())->toBe(0);
});

it('rejects a row written for another site (WITH CHECK)', function (): void {
    $foreignEntryId = app(Tenancy::class)->enter(2, fn (): int => draftSchemaEntry()->id);

    app(Tenancy::class)->enter(1, function () use ($foreignEntryId): void {
        expect(fn () => draftSchemaInsert([
            'site_id' => 2, 'entry_id' => $foreignEntryId, 'content' => json_encode(draftSchemaContent()),
        ]))->toThrow(QueryException::class, 'row-level security');

        expect(DB::table('entry_drafts')->count())->toBe(0);
    });

    expect(DB::connection('pgsql_admin')->table('entry_drafts')->count())->toBe(0);
});

it('stamps site_id from the tenant context, never from input', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $draft = EntryDraft::query()->create([
            'site_id' => 2, 'entry_id' => draftSchemaEntry()->id, 'content' => draftSchemaContent(),
        ]);

        expect($draft->site_id)->toBe(1)
            ->and(DB::table('entry_drafts')->where('id', $draft->id)->value('site_id'))->toBe(1);
    });
});

it('keeps at most one pending change per entry', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $entry = draftSchemaEntry();
        EntryDraft::query()->create(['entry_id' => $entry->id, 'content' => draftSchemaContent('Một')]);

        expect(fn () => draftSchemaInsert([
            'site_id' => 1, 'entry_id' => $entry->id, 'content' => json_encode(draftSchemaContent('Hai')),
        ]))->toThrow(QueryException::class, 'entry_drafts_entry_id_unique');

        expect($entry->draft()->sole()->content['locales']['vi']['title'])->toBe('Một');
    });
});

it('rejects content that is not an object with a locales object', function (string $content): void {
    app(Tenancy::class)->enter(1, function () use ($content): void {
        expect(fn () => draftSchemaInsert(['site_id' => 1, 'entry_id' => draftSchemaEntry()->id, 'content' => $content]))
            ->toThrow(QueryException::class, 'entry_drafts_content_shape');
    });
})->with([
    'an array' => '[]',
    'a string' => '"tiêu đề"',
    'json null' => 'null',
    'no locales key' => '{"v": 1, "shared": {}}',
    'locales as an array' => '{"v": 1, "locales": []}',
    'locales as null' => '{"v": 1, "locales": null}',
]);

it('accepts the documented content shape and reads it back as an array', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $entry = draftSchemaEntry();
        draftSchemaInsert(['site_id' => 1, 'entry_id' => $entry->id, 'content' => json_encode([
            'v' => 1,
            'shared' => ['cover_media_id' => null, 'meta' => [], 'primary_category_id' => null, 'template_override' => null],
            'locales' => ['vi' => ['title' => 'Máy CNC', 'slug' => 'may-cnc', 'body' => ['blocks' => []]], 'en' => ['title' => 'CNC']],
        ])]);

        $draft = $entry->draft()->sole();

        expect($draft->workflow_state)->toBeNull()   // no workflow, or its first stage
            // jsonb stores keys in its own order, never the written one: read locales by key.
            ->and(array_keys($draft->content['locales']))->toEqualCanonicalizing(['vi', 'en'])
            ->and($draft->content['locales']['vi']['slug'])->toBe('may-cnc')
            ->and($draft->entry->is($entry))->toBeTrue();
    });
});

it('keeps the pending change when the entry goes to the Trash and drops it when purged', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $entry = draftSchemaEntry();
        EntryDraft::query()->create(['entry_id' => $entry->id, 'content' => draftSchemaContent()]);

        $entry->delete();
        expect(EntryDraft::query()->where('entry_id', $entry->id)->exists())->toBeTrue();

        $entry->forceDelete();
        expect(EntryDraft::query()->where('entry_id', $entry->id)->exists())->toBeFalse();
    });
});

it('forgets a removed user without losing the pending change', function (): void {
    $reviewer = User::factory()->create();
    $author = User::factory()->create();

    $draftId = app(Tenancy::class)->enter(1, fn (): int => EntryDraft::query()->create([
        'entry_id' => draftSchemaEntry()->id, 'content' => draftSchemaContent(),
        'assigned_to' => $reviewer->id, 'created_by' => $author->id, 'updated_by' => $author->id,
    ])->id);

    $reviewer->delete();
    $author->delete();

    app(Tenancy::class)->enter(1, function () use ($draftId): void {
        $draft = EntryDraft::query()->findOrFail($draftId);

        expect($draft->assigned_to)->toBeNull()
            ->and($draft->created_by)->toBeNull()
            ->and($draft->updated_by)->toBeNull()
            ->and($draft->assignee)->toBeNull();
    });
});

it('can be rolled back and re-applied', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_01_01_000000_create_entry_drafts_table.php');

    // Migrations run on the privileged connection (DDL needs table ownership). Restore the table
    // before asserting so a failing assertion never leaves it dropped for the rest of the suite.
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $migration->down();
        $dropped = ! Schema::hasTable('entry_drafts');
        $migration->up();
    } finally {
        DB::setDefaultConnection($original);
    }

    expect($dropped)->toBeTrue();

    // Recreated with its policy and grants intact: the app role can write inside a site context.
    app(Tenancy::class)->enter(1, function (): void {
        EntryDraft::query()->create(['entry_id' => draftSchemaEntry()->id, 'content' => draftSchemaContent()]);
        expect(EntryDraft::query()->count())->toBe(1);
    });
    expect(DB::table('entry_drafts')->count())->toBe(0);
});
