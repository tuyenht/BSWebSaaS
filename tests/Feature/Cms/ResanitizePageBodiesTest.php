<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Shared\Support\RichTextSanitizer;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * cms:resanitize-bodies cleans the formatted text already stored in bodies and pending changes —
 * what the write-time casts never saw (data stored before them, or a sanitizer that has since
 * been narrowed). Here the dirty text is put in place past the model, as such data would be.
 */

const RESANITIZE_DIRTY = '<p onclick="steal()">Đoạn văn <a href="javascript:alert(1)">liên kết</a></p><script>alert(1)</script>';

beforeEach(function (): void {
    seedTenancy();
});

/** @return array{blocks: list<array<string, mixed>>} */
function resanitizeBody(string $html): array
{
    return ['blocks' => [
        ['type' => 'hero', 'props' => ['heading' => 'Tiêu đề']],
        ['type' => 'richtext_html', 'props' => ['html' => $html]],
    ]];
}

/**
 * On $siteId: one entry with a dirty translation body and a dirty pending change, and one entry
 * whose body is clean. Returns the dirty entry's id.
 */
function seedDirtyBodies(int $siteId, string $slug): int
{
    return app(Tenancy::class)->enter($siteId, function () use ($slug): int {
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => []]);
        $translation = $entry->translations()->create(['locale' => 'vi', 'title' => 'Bài', 'slug' => $slug, 'status' => 'draft', 'body' => resanitizeBody('<p>sạch</p>')]);
        $draft = EntryDraft::query()->create(['entry_id' => $entry->id, 'content' => ['v' => 1, 'locales' => ['vi' => ['title' => 'Bài', 'body' => resanitizeBody('<p>sạch</p>')]]]]);

        // Past the model and its cast: what a body stored before the casts existed looks like.
        DB::table('entry_translations')->where('id', $translation->id)->update(['body' => json_encode(resanitizeBody(RESANITIZE_DIRTY))]);
        DB::table('entry_drafts')->where('id', $draft->id)->update(['content' => json_encode(['v' => 1, 'locales' => ['vi' => ['title' => 'Bài', 'body' => resanitizeBody(RESANITIZE_DIRTY)]]])]);

        Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => []])
            ->translations()->create(['locale' => 'vi', 'title' => 'Bài sạch', 'slug' => "{$slug}-sach", 'status' => 'draft', 'body' => resanitizeBody('<p>đã sạch</p>')]);

        return (int) $entry->id;
    });
}

/**
 * The stored html of the dirty entry's translation and pending change, and their updated_at, read past the model.
 *
 * @return array{body: mixed, draft: mixed, body_updated_at: mixed, draft_updated_at: mixed}
 */
function storedBodies(int $siteId, int $entryId): array
{
    return app(Tenancy::class)->enter($siteId, function () use ($entryId): array {
        $translation = DB::table('entry_translations')->where('entry_id', $entryId)->first(['body', 'updated_at']);
        $draft = DB::table('entry_drafts')->where('entry_id', $entryId)->first(['content', 'updated_at']);

        return [
            'body' => data_get(json_decode((string) $translation?->body, true), 'blocks.1.props.html'),
            'draft' => data_get(json_decode((string) $draft?->content, true), 'locales.vi.body.blocks.1.props.html'),
            'body_updated_at' => $translation?->updated_at,
            'draft_updated_at' => $draft?->updated_at,
        ];
    });
}

it('cleans dirty formatted text in bodies and pending changes, writing only the rows that change', function (): void {
    $entryId = seedDirtyBodies(1, 'bai');
    $clean = app(RichTextSanitizer::class)->cms(RESANITIZE_DIRTY);

    $this->artisan('cms:resanitize-bodies')
        ->expectsOutputToContain('Site 1: 3 checked, 2 changed.')
        ->assertSuccessful();

    $stored = storedBodies(1, $entryId);
    expect($stored['body'])->toBe($clean)
        ->and($stored['draft'])->toBe($clean)
        ->and(strtolower((string) $stored['body']))->not->toContain('<script')->not->toContain('onclick')->not->toContain('javascript:')
        ->and((string) $stored['body'])->toContain('Đoạn văn');
});

it('changes nothing on a second run', function (): void {
    seedDirtyBodies(1, 'bai');
    $this->artisan('cms:resanitize-bodies')->assertSuccessful();

    $this->artisan('cms:resanitize-bodies')
        ->expectsOutputToContain('Site 1: 3 checked, 0 changed.')
        ->expectsOutputToContain('0 changed on 2 site(s).')
        ->assertSuccessful();
});

it('only counts on a dry run, writing nothing', function (): void {
    $entryId = seedDirtyBodies(1, 'bai');
    $before = storedBodies(1, $entryId);

    $this->artisan('cms:resanitize-bodies --dry-run')
        ->expectsOutputToContain('Site 1: 3 checked, 2 would change.')
        ->assertSuccessful();

    expect(storedBodies(1, $entryId))->toBe($before)
        ->and($before['body'])->toBe(RESANITIZE_DIRTY);
});

it('cleans only the site named with --site, leaving the other site untouched', function (): void {
    $mine = seedDirtyBodies(1, 'bai');
    $theirs = seedDirtyBodies(2, 'bai');

    $this->artisan('cms:resanitize-bodies --site=a.test')
        ->expectsOutputToContain('Site 1: 3 checked, 2 changed.')
        ->doesntExpectOutputToContain('Site 2')
        ->assertSuccessful();

    expect(storedBodies(1, $mine)['body'])->not->toBe(RESANITIZE_DIRTY)
        ->and(storedBodies(2, $theirs)['body'])->toBe(RESANITIZE_DIRTY)
        ->and(storedBodies(2, $theirs)['draft'])->toBe(RESANITIZE_DIRTY);
});

it('refuses a hostname no site answers to, changing nothing', function (): void {
    $entryId = seedDirtyBodies(1, 'bai');

    $this->artisan('cms:resanitize-bodies --site=nobody.test')
        ->expectsOutputToContain("No site answers to 'nobody.test'.")
        ->assertFailed();

    expect(storedBodies(1, $entryId)['body'])->toBe(RESANITIZE_DIRTY);
});

it('keeps updated_at and records no revision or workflow history', function (): void {
    $entryId = seedDirtyBodies(1, 'bai');
    $before = storedBodies(1, $entryId);
    $this->travel(10)->minutes();

    $this->artisan('cms:resanitize-bodies')->assertSuccessful();

    $after = storedBodies(1, $entryId);
    $admin = DB::connection('pgsql_admin');
    expect($after['body'])->not->toBe($before['body'])
        ->and($after['body_updated_at'])->toBe($before['body_updated_at'])
        ->and($after['draft_updated_at'])->toBe($before['draft_updated_at'])
        ->and($admin->table('entry_revisions')->count())->toBe(0)
        ->and($admin->table('workflow_log')->count())->toBe(0);
});

it('walks a site chunk after chunk until every row is read', function (): void {
    // More rows than one chunk holds (100), so the keyset has to move on at least twice.
    app(Tenancy::class)->enter(1, function (): void {
        foreach (range(1, 230) as $n) {
            Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => []])
                ->translations()->create(['locale' => 'vi', 'title' => "Bài {$n}", 'slug' => "bai-{$n}", 'status' => 'draft', 'body' => resanitizeBody('<p>sạch</p>')]);
        }
        DB::table('entry_translations')->update(['body' => json_encode(resanitizeBody(RESANITIZE_DIRTY))]);
    });

    $this->artisan('cms:resanitize-bodies --site=a.test')
        ->expectsOutputToContain('Site 1: 230 checked, 230 changed.')
        ->assertSuccessful();

    app(Tenancy::class)->enter(1, function (): void {
        expect(DB::table('entry_translations')->where('body', 'like', '%<script%')->count())->toBe(0);
    });
});
