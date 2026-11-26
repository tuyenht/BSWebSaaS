<?php

use App\Modules\Admin\Support\MediaBackfill\MediaRefsBackfill;
use App\Modules\Admin\Support\Settings\HeadingDecorInput;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\ContentBlockTranslation;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\BlockMediaUsage;
use App\Modules\Cms\Support\EntryCoverUsage;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * media:refs-by-id names media by library row where content still names it by address: entry
 * covers (and the copies in pending changes and history), block image props and file buttons in
 * every place blocks are stored, and the heading mask. A dry run writes nothing; --apply converts,
 * keeps the usage index, and a second run converts nothing. A value naming no file of the website
 * (another website's included) is left and reported; a file in the Trash is converted and counted.
 */

/** The JSON places and entries, as text, read past RLS: what a dry run must leave untouched. */
function backfillSnapshot(): array
{
    $admin = DB::connection('pgsql_admin');
    $tables = ['entries', 'entry_translations', 'entry_drafts', 'entry_revisions', 'content_block_translations', 'taxonomies', 'site_settings', 'mediables'];

    return array_combine($tables, array_map(
        static fn (string $table): string => (string) $admin->selectOne("SELECT md5(coalesce(string_agg(t::text, '|' ORDER BY t.id), '')) AS h FROM {$table} t")->h,
        $tables,
    ));
}

function backfillMedia(int $site, string $file, string $mime, bool $trashed = false): Media
{
    $key = siteCode($site).'/'.$file;

    return app(Tenancy::class)->enter($site, function () use ($key, $mime, $trashed): Media {
        $media = Media::query()->create([
            'collection' => 'library', 'slug' => 'tep', 'hash' => hash('sha256', $key),
            'storage_key' => $key, 'disk' => 'r2', 'mime' => $mime, 'size' => 10,
        ]);
        if ($trashed) {
            DB::table('media')->where('id', $media->id)->update(['deleted_at' => now()]);
        }

        return $media;
    });
}

function backfillJson(string $table, int $id, string $column): mixed
{
    return json_decode((string) DB::connection('pgsql_admin')->table($table)->where('id', $id)->value($column), true);
}

/** @param  array<string, mixed>  $value */
function backfillWrite(string $table, int $id, string $column, array $value): void
{
    DB::connection('pgsql_admin')->table($table)->where('id', $id)->update([$column => json_encode($value, JSON_UNESCAPED_SLASHES)]);
}

/**
 * Site 1 names its files by key in every place; site 2 names site 1's picture by key, and one of its own.
 *
 * @return array<string, mixed>
 */
function backfillContent(): array
{
    $hero = backfillMedia(1, 'hero-1a2b.jpg', 'image/jpeg');
    $doc = backfillMedia(1, 'bang-gia-3c4d.pdf', 'application/pdf');
    $mask = backfillMedia(1, 'mask-5e6f.png', 'image/png');
    $old = backfillMedia(1, 'cu-7a8b.jpg', 'image/jpeg', trashed: true);
    $theirs = backfillMedia(2, 'cua-ho-9c0d.jpg', 'image/jpeg');
    $heroUrl = 'https://files.test/media/'.$hero->storage_key.'?w=800';

    $made = app(Tenancy::class)->enter(1, function () use ($hero, $doc, $mask, $old, $theirs, $heroUrl): array {
        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => $hero->storage_key, 'badge' => 'Mới']]);
        $trashedCover = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => $old->storage_key]]);
        $glyph = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => 'gear']]);
        $foreign = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => $theirs->storage_key]]);
        $byRow = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'cover_media_id' => $hero->id, 'meta' => []]);

        $translation = $post->translations()->create(['locale' => 'vi', 'title' => 'Bài', 'slug' => 'bai', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $draft = EntryDraft::query()->create(['entry_id' => $post->id, 'content' => ['v' => 1, 'locales' => ['vi' => ['title' => 'Bản sửa']]]]);
        $revision = EntryRevision::query()->create(['entry_id' => $post->id, 'locale' => 'vi', 'kind' => RevisionKind::Edit, 'snapshot' => ['v' => 1]]);
        $block = ContentBlock::query()->create(['key' => 'doi-tac', 'name' => 'Đối tác', 'region' => 'inline', 'status' => 'published']);
        $blockText = ContentBlockTranslation::query()->create(['content_block_id' => $block->id, 'locale' => 'vi', 'body' => ['blocks' => []]]);
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $slots = SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'layout_blocks', 'value' => ['x' => 1]]);
        $decor = SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'heading_decor', 'value' => ['style' => 'bar', 'mask_media' => $mask->storage_key]]);

        return compact('post', 'trashedCover', 'glyph', 'foreign', 'byRow', 'translation', 'draft', 'revision', 'block', 'blockText', 'category', 'slots', 'decor');
    });

    $fileButton = ['type' => 'cta', 'props' => ['heading' => 'Tải bảng giá', 'button_label' => 'Tải', 'button_url_do' => ['k' => 'file', 'file' => $doc->storage_key]]];
    backfillWrite('entry_translations', $made['translation']->id, 'body', ['blocks' => [
        ['type' => 'hero', 'props' => ['heading' => 'Chào', 'image_url' => $heroUrl]],
        $fileButton,
        ['type' => 'about', 'props' => ['heading' => 'Ngoài', 'image_url' => 'https://cdn.other.test/a.jpg']],
    ]]);
    backfillWrite('entry_drafts', $made['draft']->id, 'content', ['v' => 1, 'shared' => ['meta' => ['image_url' => $hero->storage_key]], 'locales' => ['vi' => ['title' => 'Bản sửa', 'body' => ['blocks' => [
        ['type' => 'about', 'props' => ['heading' => 'Về', 'image_url' => $hero->storage_key]],
    ]]]]]);
    backfillWrite('entry_revisions', $made['revision']->id, 'snapshot', ['v' => 1, 'meta' => ['image_url' => $hero->storage_key], 'body' => ['blocks' => [$fileButton]]]);
    backfillWrite('content_block_translations', $made['blockText']->id, 'body', ['blocks' => [
        ['type' => 'partners', 'props' => ['heading' => 'Đối tác', 'logos' => [['image_url' => $hero->storage_key, 'name' => 'A'], ['image_url' => $hero->id, 'name' => 'B']]]],
    ]]);
    backfillWrite('taxonomies', $made['category']->id, 'meta', ['layout_blocks' => ['after_post' => ['vi' => ['blocks' => [$fileButton]]]]]);
    backfillWrite('site_settings', $made['slots']->id, 'value', ['before_list' => ['vi' => ['blocks' => [
        ['type' => 'hero', 'props' => ['heading' => 'Cũ', 'image_url' => $old->storage_key]],
    ]]], 'post_aside' => 'none']);

    $site2 = app(Tenancy::class)->enter(2, fn (): array => [
        'borrowed' => Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => $hero->storage_key]]),
        'own' => Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => $theirs->storage_key]]),
    ]);

    return $made + $site2 + compact('hero', 'doc', 'mask', 'old', 'theirs', 'heroUrl');
}

/** @return array{0: int, 1: string, 2: array<string, mixed>} */
function backfillRun(string $site = '1', bool $apply = false): array
{
    $path = storage_path('framework/testing/media-refs-'.Str::random(8).'.json');
    $exit = Artisan::call('media:refs-by-id', ['--site' => $site, '--apply' => $apply, '--output' => $path]);
    $output = Artisan::output();
    $report = json_decode((string) File::get($path), true);
    File::delete($path);

    return [$exit, $output, $report];
}

beforeEach(function (): void {
    seedTenancy();
    config(['media.cloudflare.base' => 'https://files.test/media']);
});

it('counts every place in a dry run and writes nothing', function (): void {
    $c = backfillContent();
    $before = backfillSnapshot();

    [$exit, $output, $report] = backfillRun();

    $lines = $report['sites'][1]['lines'];
    expect($exit)->toBe(0, $output)
        ->and(backfillSnapshot())->toBe($before)
        ->and($output)->toContain('Nothing was written.')
        ->and($report['mode'])->toBe('dry-run')
        ->and($lines['entries cover'])->toMatchArray(['converted' => 2, 'already' => 1, 'kept' => 1, 'unmatched' => 1, 'trashed' => 1])
        ->and($lines['entry_translations image'])->toMatchArray(['converted' => 1, 'kept' => 1])
        ->and($lines['entry_translations file']['converted'])->toBe(1)
        ->and($lines['entry_drafts cover']['converted'])->toBe(1)
        ->and($lines['entry_drafts image']['converted'])->toBe(1)
        ->and($lines['entry_revisions cover']['converted'])->toBe(1)
        ->and($lines['entry_revisions file']['converted'])->toBe(1)
        ->and($lines['content_block_translations image'])->toMatchArray(['converted' => 1, 'already' => 1])
        ->and($lines['taxonomies file']['converted'])->toBe(1)
        ->and($lines['site_settings image'])->toMatchArray(['converted' => 1, 'trashed' => 1])
        ->and($lines['site_settings mask']['converted'])->toBe(1)
        ->and($report['sites'][1]['rows'])->toMatchArray(['entries' => 2, 'entry_translations' => 1, 'entry_drafts' => 1, 'entry_revisions' => 1, 'content_block_translations' => 1, 'taxonomies' => 1, 'site_settings' => 2])
        // the Risk the owner is told about: a cover and a block picture whose file is in the Trash
        ->and($report['sites'][1]['trash_stops_showing'])->toBe(2)
        ->and($output)->toContain('2 covers or block pictures name a file in the Trash')
        ->and($output)->toContain('in the Trash in entries cover: '.$c['old']->storage_key)
        ->and($output)->toContain('unmatched in entries cover: '.$c['theirs']->storage_key.' (left as it is)');
});

it('converts every place to the row, keeps the usage index, and a second run converts nothing', function (): void {
    $c = backfillContent();
    $updatedAt = DB::connection('pgsql_admin')->table('entries')->where('id', $c['post']->id)->value('updated_at');

    [$exit, $output] = backfillRun(apply: true);
    expect($exit)->toBe(0, $output);

    $entry = fn (Entry $e): array => (array) DB::connection('pgsql_admin')->table('entries')->where('id', $e->id)->first(['cover_media_id', 'meta', 'updated_at']);
    $post = $entry($c['post']);
    expect($post['cover_media_id'])->toBe($c['hero']->id)
        ->and(json_decode($post['meta'], true))->toBe(['badge' => 'Mới'])
        ->and($post['updated_at'])->toBe($updatedAt)                                          // written past the model
        ->and($entry($c['trashedCover'])['cover_media_id'])->toBe($c['old']->id)            // the Trash converted too
        ->and(json_decode($entry($c['glyph'])['meta'], true))->toBe(['image_url' => 'gear'])
        ->and($entry($c['foreign'])['cover_media_id'])->toBeNull()                          // another website's file: left
        ->and(json_decode($entry($c['foreign'])['meta'], true))->toBe(['image_url' => $c['theirs']->storage_key]);

    $body = backfillJson('entry_translations', $c['translation']->id, 'body')['blocks'];
    expect($body[0]['props']['image_url'])->toBe($c['hero']->id)
        ->and($body[1]['props']['button_url_do'])->toBe(['k' => 'file', 'media' => strtoupper($c['doc']->ulid)])
        ->and($body[2]['props']['image_url'])->toBe('https://cdn.other.test/a.jpg');

    $draft = backfillJson('entry_drafts', $c['draft']->id, 'content');
    expect($draft['shared'])->toEqual(['meta' => [], 'cover_media_id' => $c['hero']->id])
        ->and($draft['locales']['vi']['body']['blocks'][0]['props']['image_url'])->toBe($c['hero']->id);

    $snapshot = backfillJson('entry_revisions', $c['revision']->id, 'snapshot');
    expect($snapshot['cover_media_id'])->toBe($c['hero']->id)
        ->and($snapshot['meta'])->toBe([])
        ->and($snapshot['body']['blocks'][0]['props']['button_url_do'])->toBe(['k' => 'file', 'media' => strtoupper($c['doc']->ulid)]);

    expect(array_column(backfillJson('content_block_translations', $c['blockText']->id, 'body')['blocks'][0]['props']['logos'], 'image_url'))->toBe([$c['hero']->id, $c['hero']->id])
        ->and(backfillJson('taxonomies', $c['category']->id, 'meta')['layout_blocks']['after_post']['vi']['blocks'][0]['props']['button_url_do'])->toBe(['k' => 'file', 'media' => strtoupper($c['doc']->ulid)])
        ->and(backfillJson('site_settings', $c['slots']->id, 'value'))->toEqual(['before_list' => ['vi' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Cũ', 'image_url' => $c['old']->id]]]]], 'post_aside' => 'none'])
        ->and(backfillJson('site_settings', $c['decor']->id, 'value'))->toEqual(['style' => 'bar', 'mask_ulid' => strtoupper($c['mask']->ulid)]);

    // the library sees every picture as used: block pictures now named by id, the mask, the covers
    $usage = app(Tenancy::class)->enter(1, fn (): array => Mediable::query()->orderBy('mediable_type')->orderBy('media_id')
        ->get(['media_id', 'mediable_type', 'mediable_id', 'collection'])->map(fn (Mediable $m): string => "{$m->collection}:{$m->mediable_type}:{$m->mediable_id}:{$m->media_id}")->all());
    expect($usage)->toContain(BlockMediaUsage::COLLECTION.":entry:{$c['post']->id}:{$c['hero']->id}")
        ->toContain(BlockMediaUsage::COLLECTION.":entry:{$c['post']->id}:{$c['doc']->id}")
        ->toContain(BlockMediaUsage::COLLECTION.":content_block:{$c['block']->id}:{$c['hero']->id}")
        ->toContain(BlockMediaUsage::COLLECTION.":taxonomy:{$c['category']->id}:{$c['doc']->id}")
        ->toContain(BlockMediaUsage::COLLECTION.":site_setting:{$c['slots']->id}:{$c['old']->id}")
        ->toContain(HeadingDecorInput::MASK_COLLECTION.":site_setting:{$c['decor']->id}:{$c['mask']->id}")
        ->toContain(EntryCoverUsage::COLLECTION.":entry:{$c['post']->id}:{$c['hero']->id}")
        ->toContain(EntryCoverUsage::COLLECTION.":entry:{$c['trashedCover']->id}:{$c['old']->id}");

    $after = backfillSnapshot();
    [$exit, $output, $report] = backfillRun(apply: true);
    expect($exit)->toBe(0, $output)
        ->and($report['totals']['converted'])->toBe(0)
        ->and(array_sum($report['sites'][1]['rows']))->toBe(0)
        ->and($report['sites'][1]['lines']['entries cover'])->toMatchArray(['already' => 3, 'unmatched' => 1, 'kept' => 1])
        ->and(backfillSnapshot())->toBe($after);
});

it('never resolves another website\'s file, with both websites in one run', function (): void {
    $c = backfillContent();

    [$exit, $output, $report] = backfillRun('all', apply: true);

    $row = fn (Entry $e): array => (array) DB::connection('pgsql_admin')->table('entries')->where('id', $e->id)->first(['cover_media_id', 'meta']);
    expect($exit)->toBe(0, $output)
        ->and(array_keys($report['sites']))->toBe([1, 2])
        // site 2 named site 1's picture: left and reported, never site 1's row
        ->and($row($c['borrowed'])['cover_media_id'])->toBeNull()
        ->and(json_decode($row($c['borrowed'])['meta'], true))->toBe(['image_url' => $c['hero']->storage_key])
        ->and($report['sites'][2]['lines']['entries cover'])->toMatchArray(['converted' => 1, 'unmatched' => 1])
        ->and($report['sites'][2]['lines']['entries cover']['samples'])->toBe([$c['hero']->storage_key])
        ->and($row($c['own'])['cover_media_id'])->toBe($c['theirs']->id)
        // and site 1 never took site 2's
        ->and($row($c['foreign'])['cover_media_id'])->toBeNull()
        ->and($report['sites'][1]['lines']['entries cover']['samples'])->toBe([$c['theirs']->storage_key]);
});

it('converts past a full batch, then nothing on the next run', function (): void {
    $hero = backfillMedia(1, 'hero-1a2b.jpg', 'image/jpeg');
    $rows = array_map(static fn (int $i): array => [
        'ulid' => (string) Str::ulid(), 'site_id' => 1, 'type' => 'post', 'status' => 'draft',
        'meta' => json_encode(['image_url' => $hero->storage_key]), 'created_at' => now(), 'updated_at' => now(),
    ], range(1, MediaRefsBackfill::BATCH + 1));
    DB::connection('pgsql_admin')->table('entries')->insert($rows);

    [, $output, $report] = backfillRun(apply: true);
    expect($report['sites'][1]['lines']['entries cover']['converted'])->toBe(MediaRefsBackfill::BATCH + 1, $output)
        ->and(DB::connection('pgsql_admin')->table('entries')->whereNull('cover_media_id')->count())->toBe(0);

    [, , $again] = backfillRun(apply: true);
    expect($again['totals']['converted'])->toBe(0)
        ->and($again['sites'][1]['lines']['entries cover']['already'])->toBe(MediaRefsBackfill::BATCH + 1);
});

it('reads, looks up and writes a batch in a fixed number of queries, plus one save per rewritten page body', function (): void {
    $count = function (int $posts): int {
        seedTenancy();
        $doc = backfillMedia(1, 'bang-gia.pdf', 'application/pdf');
        $files = array_map(static fn (int $i): Media => backfillMedia(1, "anh-{$i}.jpg", 'image/jpeg'), range(1, $posts));
        app(Tenancy::class)->enter(1, function () use ($files, $doc): void {
            foreach ($files as $i => $file) {
                $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => $file->storage_key]]);
                $text = $post->translations()->create(['locale' => 'vi', 'title' => "Bài {$i}", 'slug' => "bai-{$i}", 'status' => 'draft', 'body' => ['blocks' => []]]);
                DB::table('entry_translations')->where('id', $text->id)->update(['body' => json_encode(['blocks' => [
                    ['type' => 'hero', 'props' => ['heading' => 'A', 'image_url' => $file->storage_key]],
                    ['type' => 'cta', 'props' => ['button_label' => 'Tải', 'button_url_do' => ['k' => 'file', 'file' => $doc->storage_key]]],
                ]], JSON_UNESCAPED_SLASHES)]);
            }
        });
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });
        app(MediaRefsBackfill::class)->site(1, true);

        return $queries;
    };

    $few = $count(2);
    $many = $count(12);

    // every place is one read, one media lookup and one write per batch; only a page body is saved
    // through its model (its sanitizing cast), one statement per rewritten body
    expect($many - $few)->toBe(12 - 2);
});
