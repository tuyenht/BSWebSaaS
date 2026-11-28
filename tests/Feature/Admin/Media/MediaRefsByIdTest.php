<?php

use App\Modules\Admin\Console\RekeySiteMedia;
use App\Modules\Admin\Support\MediaBackfill\MediaRefsBackfill;
use App\Modules\Admin\Support\Settings\HeadingDecorInput;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\ContentBlockTranslation;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\BlockMediaUsage;
use App\Modules\Cms\Support\EntryCoverUsage;
use App\Modules\Forms\Models\Form;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\RichTextSanitizer;
use App\Modules\Storefront\Support\BrandImages;
use App\Modules\Tenancy\Context\SiteIdentity;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
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
        ->and($output)->toContain('2 covers, block pictures or brand images name a file in the Trash')
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

it('walks or counts every place media:rekey-site rewrites keys in', function (): void {
    $walked = [['entries', 'meta'], ...array_map(static fn (array $doc): array => [$doc[0], $doc[1]], array_values(MediaRefsBackfill::DOCS)), ...array_values(MediaRefsBackfill::COUNTED)];

    foreach (RekeySiteMedia::PLACES as $place) {
        expect($walked)->toContain($place);
    }
    foreach (array_column($walked, 0) as $table) {
        expect(MediaRefsBackfill::WALKS)->toHaveKey($table);
    }
});

it('counts the file addresses of the places it only reads, and writes nothing there', function (): void {
    $hero = backfillMedia(1, 'hero-1a2b.jpg', 'image/jpeg');
    $logo = backfillMedia(1, 'logo-2b3c.png', 'image/png');
    $tables = ['products', 'menus', 'menu_items', 'content_sections', 'site_profiles', 'forms', 'site_settings'];
    $made = app(Tenancy::class)->enter(1, function () use ($logo): array {
        $menu = Menu::query()->create(['name' => 'Chính', 'handle' => 'primary']);

        return [
            'product' => Product::query()->create(['status' => 'published', 'published_at' => now()]),
            'item' => MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => '/lien-he', 'position' => 0]),
            'form' => Form::query()->create(['key' => 'contact', 'name' => ['vi' => 'Liên hệ'], 'status' => 'active']),
            'brand' => SiteSetting::query()->create(['group_name' => 'general', 'key' => 'brand', 'value' => ['note' => 'https://files.test/media/'.$logo->storage_key.'?w=256', 'name' => 'A']]),
        ];
    });
    backfillWrite('products', $made['product']->id, 'meta', ['old_image' => $hero->storage_key, 'note' => '26/11/2026']);
    backfillWrite('menu_items', $made['item']->id, 'meta', ['picture' => 'https://files.test/media/'.$hero->storage_key.'?w=320', 'icon' => 'phone']);
    backfillWrite('forms', $made['form']->id, 'settings', ['banner' => siteCode(1).'/khong-co-9z9z.jpg', 'edited' => true]);
    $hash = fn (): array => array_map(static fn (string $table): string => (string) DB::connection('pgsql_admin')->selectOne("SELECT md5(coalesce(string_agg(t::text, '|' ORDER BY t.id), '')) AS h FROM {$table} t")->h, $tables);
    $before = $hash();

    [$exit, $output, $report] = backfillRun();
    $lines = $report['sites'][1]['lines'];
    expect($exit)->toBe(0, $output)
        ->and($lines['products address'])->toMatchArray(['found' => 1, 'unmatched' => 0])
        ->and($lines['menu_items address'])->toMatchArray(['found' => 1, 'unmatched' => 0])
        ->and($lines['forms address'])->toMatchArray(['found' => 0, 'unmatched' => 1])
        ->and($lines['forms address']['samples'])->toBe([siteCode(1).'/khong-co-9z9z.jpg'])
        // a key of the brand setting that is no brand image is only counted
        ->and($lines['site_settings general.brand'])->toMatchArray(['found' => 1, 'unmatched' => 0])
        ->and($report['totals'])->toMatchArray(['found' => 3, 'unmatched' => 1, 'converted' => 0])
        ->and($output)->toContain('in site_settings general.brand: names a file by address: https://files.test/media/'.$logo->storage_key.'?w=256');

    [$exit, $output, $report] = backfillRun(apply: true);
    expect($exit)->toBe(0, $output)
        ->and($report['totals']['found'])->toBe(3)
        ->and($hash())->toBe($before);
});

it('counts an old key kept beside the new shape and a block of an unknown type, and converts a mask whose ulid the page ignores', function (): void {
    $hero = backfillMedia(1, 'hero-1a2b.jpg', 'image/jpeg');
    $mask = backfillMedia(1, 'mask-5e6f.png', 'image/png');
    $theirMask = backfillMedia(2, 'mask-7a8b.png', 'image/png');
    $made = app(Tenancy::class)->enter(1, function () use ($hero, $mask): array {
        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'cover_media_id' => $hero->id, 'meta' => []]);
        $text = $post->translations()->create(['locale' => 'vi', 'title' => 'Bài', 'slug' => 'bai', 'status' => 'draft', 'body' => ['blocks' => []]]);
        SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'heading_decor', 'value' => ['style' => 'bar', 'mask_ulid' => strtoupper($mask->ulid), 'mask_media' => $mask->storage_key]]);

        return compact('post', 'text');
    });
    $decor2 = app(Tenancy::class)->enter(2, fn (): SiteSetting => SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'heading_decor', 'value' => ['style' => 'bar', 'mask_ulid' => 'not-a-ulid', 'mask_media' => $theirMask->storage_key]]));
    // a cover id with the old key still beside it (written past the editor, which never keeps both)
    DB::connection('pgsql_admin')->table('entries')->where('id', $made['post']->id)->update(['meta' => json_encode(['image_url' => $hero->storage_key])]);
    backfillWrite('entry_translations', $made['text']->id, 'body', ['blocks' => [['type' => 'gone_block', 'props' => ['image_url' => $hero->storage_key]]]]);

    [$exit, $output, $report] = backfillRun('all', apply: true);
    $lines = $report['sites'][1]['lines'];
    expect($exit)->toBe(0, $output)
        ->and($lines['entries cover'])->toMatchArray(['stale' => 1, 'already' => 0, 'converted' => 0])
        ->and($lines['entries cover']['notes'])->toBe(['key beside the cover id: '.$hero->storage_key])
        ->and($lines['site_settings mask'])->toMatchArray(['stale' => 1, 'already' => 0])
        ->and($lines['entry_translations block'])->toMatchArray(['skipped' => 1])
        ->and($lines['entry_translations block']['notes'])->toBe(['block type gone_block'])
        ->and($report['totals'])->toMatchArray(['stale' => 2, 'skipped' => 1])
        // the old key is left for the contract, never dropped here
        ->and(backfillJson('entries', $made['post']->id, 'meta'))->toBe(['image_url' => $hero->storage_key])
        // site 2: the page ignores a mask_ulid that is no ulid and shows mask_media, so that one is converted
        ->and($report['sites'][2]['lines']['site_settings mask'])->toMatchArray(['converted' => 1, 'stale' => 0])
        ->and(backfillJson('site_settings', $decor2->id, 'value'))->toEqual(['style' => 'bar', 'mask_ulid' => strtoupper($theirMask->ulid)]);
});

it('changes only the media references of a body: html props, lists, empty objects and decimals stay as stored', function (): void {
    $hero = backfillMedia(1, 'hero-1a2b.jpg', 'image/jpeg');
    $doc = backfillMedia(1, 'bang-gia-3c4d.pdf', 'application/pdf');
    $clean = app(RichTextSanitizer::class)->cms('<p>Xin chào <strong>An Vinh</strong> &amp; <a href="https://a.test/x">đối tác</a></p>');
    $dirty = '<p onclick="steal()">Cũ</p>';
    $made = app(Tenancy::class)->enter(1, function (): array {
        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => []]);

        return [
            'text' => $post->translations()->create(['locale' => 'vi', 'title' => 'Bài', 'slug' => 'bai', 'status' => 'draft', 'body' => ['blocks' => []]]),
            'revision' => EntryRevision::query()->create(['entry_id' => $post->id, 'locale' => 'vi', 'kind' => RevisionKind::Edit, 'snapshot' => ['v' => 1]]),
        ];
    });
    $blocks = static fn (mixed $image, mixed $file, string $dirtyHtml): string => '[{"type":"richtext_html","props":{"html":'.json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'}},'
        .'{"type":"richtext_html","props":{"html":'.json_encode($dirtyHtml, JSON_UNESCAPED_SLASHES).'}},'
        .'{"type":"partners","props":{"heading":"Đối tác","style":{},"scale":1.0,"logos":['
        .'{"image_url":'.json_encode($image, JSON_UNESCAPED_SLASHES).',"name":"A","url_do":'.json_encode($file, JSON_UNESCAPED_SLASHES).',"extra":{}},'
        .'{"image_url":'.$hero->id.',"name":"B","tags":[]}]}}]';
    $old = $blocks($hero->storage_key, ['k' => 'file', 'file' => $doc->storage_key], $dirty);
    $admin = DB::connection('pgsql_admin');
    $admin->update('UPDATE entry_translations SET body = ?::jsonb WHERE id = ?', ['{"blocks":'.$old.'}', $made['text']->id]);
    $admin->update('UPDATE entry_revisions SET snapshot = ?::jsonb WHERE id = ?', ['{"v":1,"note":{},"body":{"blocks":'.$old.'}}', $made['revision']->id]);

    [$exit, $output] = backfillRun(apply: true);
    expect($exit)->toBe(0, $output);

    $converted = ['k' => 'file', 'media' => strtoupper($doc->ulid)];
    // compared as jsonb text, which tells {} from [] and 1.0 from 1 (jsonb equality does not tell the last two apart)
    $same = static fn (string $table, string $column, int $id, string $expected): bool => (bool) $admin->selectOne("SELECT {$column}::text = (?::jsonb)::text AS same FROM {$table} WHERE id = ?", [$expected, $id])->same;
    // a place written past the model (a revision): every byte but the two references
    expect($same('entry_revisions', 'snapshot', $made['revision']->id, '{"v":1,"note":{},"body":{"blocks":'.$blocks($hero->id, $converted, $dirty).'}}'))->toBeTrue();
    // a page body is saved through its model, whose cast does what every editor save does: the
    // html props go through RichTextSanitizer::cms (HTML already clean is unchanged, the old unsafe
    // attribute goes), and the JSON is decoded to PHP arrays, so an empty object is stored as [] and 1.0 as 1
    $throughCast = str_replace(['"style":{}', '"scale":1.0', '"extra":{}'], ['"style":[]', '"scale":1', '"extra":[]'], $blocks($hero->id, $converted, app(RichTextSanitizer::class)->cms($dirty)));
    expect(app(RichTextSanitizer::class)->cms($clean))->toBe($clean)
        ->and($same('entry_translations', 'body', $made['text']->id, '{"blocks":'.$throughCast.'}'))->toBeTrue();
});

it('holds the rows of a batch locked from read to write, with lock_timeout set, so a save in between waits', function (): void {
    $hero = backfillMedia(1, 'hero-1a2b.jpg', 'image/jpeg');
    $text = app(Tenancy::class)->enter(1, function (): EntryTranslation {
        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => []]);

        return $post->translations()->create(['locale' => 'vi', 'title' => 'Bài', 'slug' => 'bai', 'status' => 'draft', 'body' => ['blocks' => []]]);
    });
    backfillWrite('entry_translations', $text->id, 'body', ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'A', 'image_url' => $hero->storage_key]]]]);

    $seen = [];
    $previous = '';
    $busy = false;
    DB::listen(function (QueryExecuted $query) use (&$seen, &$previous, &$busy, $text): void {
        if ($busy || $query->connectionName === 'pgsql_admin') {
            return;
        }
        // between the batch's read of the bodies and its write: the media lookup
        if (str_contains($previous, 'FROM entry_translations') && str_contains($query->sql, 'for key share') && $seen === []) {
            $busy = true;
            $seen['lock_timeout'] = DB::selectOne("SELECT current_setting('lock_timeout') AS v")->v;
            try {
                // a staff save from another connection: it has to wait for the batch's row lock
                DB::connection('pgsql_admin')->transaction(function () use ($text): void {
                    DB::connection('pgsql_admin')->statement("SET LOCAL lock_timeout = '200ms'");
                    DB::connection('pgsql_admin')->update("UPDATE entry_translations SET title = 'Sửa' WHERE id = ?", [$text->id]);
                });
                $seen['save'] = 'went through';
            } catch (QueryException $e) {
                $seen['save'] = $e->errorInfo[0] ?? 'error';
            }
            $busy = false;
        }
        $previous = $query->sql;
    });

    [$exit, $output] = backfillRun(apply: true);

    expect($exit)->toBe(0, $output)
        ->and($seen)->toBe(['lock_timeout' => MediaRefsBackfill::LOCK_TIMEOUT, 'save' => '55P03'])
        ->and(backfillJson('entry_translations', $text->id, 'body')['blocks'][0]['props']['image_url'])->toBe($hero->id);
});

it('never overwrites a row that changed after it was read: it is left, counted, and converted on the next run', function (): void {
    $hero = backfillMedia(1, 'hero-1a2b.jpg', 'image/jpeg');
    $logo = backfillMedia(1, 'logo-2b3c.png', 'image/png');
    $text = app(Tenancy::class)->enter(1, function (): EntryTranslation {
        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => []]);

        return $post->translations()->create(['locale' => 'vi', 'title' => 'Bài', 'slug' => 'bai', 'status' => 'draft', 'body' => ['blocks' => []]]);
    });
    backfillWrite('entry_translations', $text->id, 'body', ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'A', 'image_url' => $hero->storage_key]]]]);
    $staff = ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Bản của người sửa', 'image_url' => $logo->storage_key]]]];

    $previous = '';
    $done = false;
    DB::listen(function (QueryExecuted $query) use (&$previous, &$done, $text, $staff): void {
        if ($done || $query->connectionName === 'pgsql_admin') {
            return;
        }
        if (str_contains($previous, 'FROM entry_translations') && str_contains($query->sql, 'for key share')) {
            // the save the row lock rules out, made in the batch's own transaction to stand for it
            $done = true;
            EntryTranslation::query()->whereKey($text->id)->firstOrFail()->update(['body' => $staff]);
        }
        $previous = $query->sql;
    });

    [$exit, $output, $report] = backfillRun(apply: true);
    expect($exit)->toBe(0, $output)
        ->and(backfillJson('entry_translations', $text->id, 'body'))->toBe($staff)
        ->and($report['sites'][1]['rows'])->toMatchArray(['entry_translations' => 0, 'entry_translations changed since read, left for the next run' => 1]);

    [, , $again] = backfillRun(apply: true);
    expect($again['sites'][1]['rows']['entry_translations'])->toBe(1)
        ->and(backfillJson('entry_translations', $text->id, 'body')['blocks'][0]['props'])->toBe(['heading' => 'Bản của người sửa', 'image_url' => $logo->id]);
});

it('walks every segment of a table: the Trash, pages, rows with no updated_at, and a full batch of equal titles', function (): void {
    $hero = backfillMedia(1, 'hero-1a2b.jpg', 'image/jpeg');
    $admin = DB::connection('pgsql_admin');
    $entry = static fn (array $values): int => (int) $admin->table('entries')->insertGetId($values + [
        'ulid' => (string) Str::ulid(), 'site_id' => 1, 'type' => 'post', 'status' => 'draft', 'meta' => json_encode(['image_url' => $hero->storage_key]), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $ids = [
        $entry(['deleted_at' => now()]),
        $entry(['deleted_at' => now(), 'updated_at' => null]),
        $entry(['type' => 'page', 'status' => 'published']),
        $entry(['type' => 'page', 'status' => 'draft', 'deleted_at' => now()]),
        $entry(['updated_at' => null]),
    ];
    $body = json_encode(['blocks' => [['type' => 'hero', 'props' => ['heading' => 'A', 'image_url' => $hero->storage_key]]]], JSON_UNESCAPED_SLASHES);
    $posts = array_map(static fn (int $i): int => $entry(['meta' => '{}']), range(1, MediaRefsBackfill::BATCH + 1));
    foreach (array_chunk($posts, 200) as $chunk) {
        $admin->table('entry_translations')->insert(array_map(static fn (int $id): array => [
            'site_id' => 1, 'entry_id' => $id, 'locale' => 'vi', 'title' => 'Cùng một tên', 'slug' => "bai-{$id}", 'status' => 'draft', 'body' => $body, 'created_at' => now(), 'updated_at' => now(),
        ], $chunk));
    }

    // revisions of two entries in two languages, more than a batch, many sharing one created_at
    $snapshot = json_encode(['v' => 1, 'meta' => ['image_url' => $hero->storage_key]], JSON_UNESCAPED_SLASHES);
    $at = now()->subDay();
    $revisions = [];
    foreach ([$posts[0], $posts[1]] as $n => $entryId) {
        foreach (range(1, 260) as $i) {
            $revisions[] = ['ulid' => (string) Str::ulid(), 'site_id' => 1, 'entry_id' => $entryId, 'locale' => $i % 2 === 0 ? 'vi' : 'en', 'kind' => 'edit', 'snapshot' => $snapshot, 'created_at' => $i % 3 === 0 ? $at->copy()->addMinutes($i) : $at];
        }
    }
    foreach (array_chunk($revisions, 200) as $chunk) {
        $admin->table('entry_revisions')->insert($chunk);
    }

    [$exit, $output, $report] = backfillRun(apply: true);
    expect($exit)->toBe(0, $output)
        ->and($report['sites'][1]['lines']['entry_revisions cover']['converted'])->toBe(count($revisions))
        ->and($admin->table('entry_revisions')->whereRaw("jsonb_exists(snapshot->'meta', 'image_url')")->count())->toBe(0)
        ->and($report['sites'][1]['lines']['entries cover']['converted'])->toBe(count($ids))
        ->and($admin->table('entries')->whereIn('id', $ids)->whereNull('cover_media_id')->count())->toBe(0)
        ->and($report['sites'][1]['lines']['entry_translations image']['converted'])->toBe(MediaRefsBackfill::BATCH + 1)
        ->and($admin->table('entry_translations')->whereRaw("body->'blocks'->0->'props'->>'image_url' = ?", [(string) $hero->id])->count())->toBe(MediaRefsBackfill::BATCH + 1);
});

it('passes over no row when the last row of a batch moves ahead between its page read and its lock', function (): void {
    $hero = backfillMedia(1, 'hero-1a2b.jpg', 'image/jpeg');
    $admin = DB::connection('pgsql_admin');
    $total = MediaRefsBackfill::BATCH + 3;
    $at = now()->subDays(2)->startOfSecond();
    $rows = array_map(static fn (int $i): array => [
        'ulid' => (string) Str::ulid(), 'site_id' => 1, 'type' => 'post', 'status' => 'draft',
        'meta' => json_encode(['image_url' => $hero->storage_key]), 'created_at' => $at, 'updated_at' => $at->copy()->addSeconds($i),
    ], range(1, $total));
    foreach (array_chunk($rows, 200) as $chunk) {
        $admin->table('entries')->insert($chunk);
    }
    // the last row of the first batch in the walk's order (updated_at, id)
    $last = (int) $admin->table('entries')->where('site_id', 1)->orderBy('updated_at')->orderBy('id')->pluck('id')[MediaRefsBackfill::BATCH - 1];
    $ahead = now()->addDay()->startOfSecond();

    $moved = false;
    DB::listen(function (QueryExecuted $query) use (&$moved, $admin, $last, $ahead): void {
        if ($moved || $query->connectionName === 'pgsql_admin') {
            return;
        }
        // the first page of entries is read, not yet locked: a staff save commits and moves its last row ahead
        if (str_contains($query->sql, 'FROM entries') && ! str_contains($query->sql, 'FOR NO KEY UPDATE')) {
            $moved = true;
            $admin->table('entries')->where('id', $last)->update(['updated_at' => $ahead]);
        }
    });

    [$exit, $output, $report] = backfillRun(apply: true);

    expect($moved)->toBeTrue()
        ->and($exit)->toBe(0, $output)
        ->and($report['sites'][1]['lines']['entries cover']['converted'])->toBe($total)
        ->and($report['sites'][1]['rows']['entries'])->toBe($total)
        ->and($admin->table('entries')->where('site_id', 1)->whereNull('cover_media_id')->count())->toBe(0)
        ->and(Carbon::parse((string) $admin->table('entries')->where('id', $last)->value('updated_at'))->eq($ahead))->toBeTrue();
});

it('never overwrites a category or a cover changed under the lock: each is left and counted', function (): void {
    $hero = backfillMedia(1, 'hero-1a2b.jpg', 'image/jpeg');
    $logo = backfillMedia(1, 'logo-2b3c.png', 'image/png');
    $doc = backfillMedia(1, 'bang-gia-3c4d.pdf', 'application/pdf');
    $made = app(Tenancy::class)->enter(1, fn (): array => [
        'keyChanged' => Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => $hero->storage_key]]),
        'idSet' => Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => $hero->storage_key]]),
        'category' => Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]),
    ]);
    $slots = static fn (string $heading): array => ['layout_blocks' => ['after_post' => ['vi' => ['blocks' => [
        ['type' => 'cta', 'props' => ['heading' => $heading, 'button_label' => 'Tải', 'button_url_do' => ['k' => 'file', 'file' => $doc->storage_key]]],
    ]]]]];
    backfillWrite('taxonomies', $made['category']->id, 'meta', $slots('Tải bảng giá'));
    $staff = $slots('Bản của người sửa');

    $previous = '';
    $busy = false;
    $done = [];
    DB::listen(function (QueryExecuted $query) use (&$previous, &$busy, &$done, $made, $logo, $staff): void {
        if ($busy || $query->connectionName === 'pgsql_admin') {
            return;
        }
        // after the rows are locked and read, before the write: the media lookup. The changes are
        // made in the batch's own transaction, standing for the save the lock rules out.
        if (str_contains($query->sql, 'for key share')) {
            $busy = true;
            if (str_contains($previous, 'FROM entries') && ! isset($done['entries'])) {
                $done['entries'] = true;
                DB::table('entries')->where('id', $made['keyChanged']->id)->update(['meta' => json_encode(['image_url' => $logo->storage_key])]);
                DB::table('entries')->where('id', $made['idSet']->id)->update(['cover_media_id' => $logo->id]);
            }
            if (str_contains($previous, 'FROM taxonomies') && ! isset($done['taxonomies'])) {
                $done['taxonomies'] = true;
                DB::table('taxonomies')->where('id', $made['category']->id)->update(['meta' => json_encode($staff, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            }
            $busy = false;
        }
        $previous = $query->sql;
    });

    [$exit, $output, $report] = backfillRun(apply: true);
    $entry = fn (Entry $e): array => (array) DB::connection('pgsql_admin')->table('entries')->where('id', $e->id)->first(['cover_media_id', 'meta']);
    expect($done)->toBe(['entries' => true, 'taxonomies' => true])
        ->and($exit)->toBe(0, $output)
        // the cover's key changed: the write needs meta.image_url to be the key read
        ->and($entry($made['keyChanged'])['cover_media_id'])->toBeNull()
        ->and(json_decode($entry($made['keyChanged'])['meta'], true))->toBe(['image_url' => $logo->storage_key])
        // a cover id set meanwhile: the write needs cover_media_id still empty
        ->and($entry($made['idSet'])['cover_media_id'])->toBe($logo->id)
        ->and(json_decode($entry($made['idSet'])['meta'], true))->toBe(['image_url' => $hero->storage_key])
        // a category written past its model: the write needs the stored meta to be the one read
        ->and(backfillJson('taxonomies', $made['category']->id, 'meta'))->toBe($staff)
        ->and($report['sites'][1]['rows'])->toMatchArray([
            'entries' => 0, 'entries changed since read, left for the next run' => 2,
            'taxonomies' => 0, 'taxonomies changed since read, left for the next run' => 1,
        ]);

    [, , $again] = backfillRun(apply: true);
    expect($again['sites'][1]['rows'])->toMatchArray(['entries' => 1, 'taxonomies' => 1])
        ->and(backfillJson('taxonomies', $made['category']->id, 'meta')['layout_blocks']['after_post']['vi']['blocks'][0]['props'])
        ->toBe(['heading' => 'Bản của người sửa', 'button_label' => 'Tải', 'button_url_do' => ['k' => 'file', 'media' => strtoupper($doc->ulid)]]);
});

/** The brand setting, the profile's four picture columns, and the four addresses the page prints, of site 1. */
function backfillBrand(): array
{
    $admin = DB::connection('pgsql_admin');

    return [
        json_decode((string) $admin->table('site_settings')->where('site_id', 1)->where('group_name', 'general')->where('key', 'brand')->value('value'), true),
        (array) $admin->table('site_profiles')->where('site_id', 1)->first(array_values(SiteProfile::IMAGE_COLUMNS)),
        app(Tenancy::class)->enter(1, fn (): array => app(BrandImages::class)->for(app(SiteIdentity::class)->for(app(SiteLocale::class)->default()))),
    ];
}

/** @param  array<string, mixed>  $value */
function backfillBrandSetting(int $site, array $value, array $profile = []): void
{
    app(Tenancy::class)->enter($site, function () use ($value, $profile): void {
        SiteSetting::query()->create(['group_name' => 'general', 'key' => 'brand', 'value' => $value]);
        SiteProfile::query()->firstOrCreate([], ['brand_name' => 'A'])->forceFill($profile)->saveQuietly();
    });
}

it('moves every brand image the brand setting holds by address to the site profile, the page unchanged, and a second run moves nothing', function (): void {
    $urls = app(MediaUrlResolver::class);
    $logo = backfillMedia(1, 'logo-1a2b.png', 'image/png');
    $dark = backfillMedia(1, 'logo-toi-3c4d.png', 'image/png');
    $icon = backfillMedia(1, 'bieu-tuong-5e6f.png', 'image/png');
    $share = backfillMedia(1, 'doi-ngu-7a8b.jpg', 'image/jpeg');
    // the share image already the profile's (as site-profiles:fill-brand-images left it), the others by address only
    backfillBrandSetting(1, [
        'name' => 'A', 'phone' => '0900 000 000',
        'logo' => $urls->url($logo->storage_key),
        'logo_dark' => $dark->storage_key,                           // a bare key
        'favicon' => $urls->url($icon->storage_key, 64),             // a ?w= address
        'og_image' => $urls->url($share->storage_key, 1280),         // ?w=1280
    ], ['og_image_media_id' => $share->id]);
    [$setting, $profile, $shown] = backfillBrand();
    expect($setting['favicon'])->toContain('?w=')->and($setting['og_image'])->toEndWith('?w=1280');

    // a dry run counts each slot and writes nothing
    [$exit, $output, $report] = backfillRun();
    $lines = $report['sites'][1]['lines'];
    expect($exit)->toBe(0, $output)
        ->and(backfillBrand())->toBe([$setting, $profile, $shown])
        ->and($lines['site_settings brand logo'])->toMatchArray(['converted' => 1, 'unmatched' => 0])
        ->and($lines['site_settings brand logo_dark'])->toMatchArray(['converted' => 1])
        ->and($lines['site_settings brand favicon'])->toMatchArray(['converted' => 1])
        ->and($lines['site_settings brand og_image'])->toMatchArray(['converted' => 1])
        // a bare key: the page will print the address the profile's picture gives
        ->and($lines['site_settings brand logo_dark']['notes'])->toBe(["the page's address becomes ".$urls->url($dark->storage_key)])
        ->and($lines['site_settings brand favicon']['notes'])->toBe([])
        ->and($lines)->not->toHaveKey('site_settings general.brand')
        ->and($report['sites'][1]['rows']['site_settings brand'])->toBe(1);

    $updatedAt = DB::connection('pgsql_admin')->table('site_profiles')->where('site_id', 1)->value('updated_at');
    [$exit, $output, $report] = backfillRun(apply: true);
    [$after, $columns, $images] = backfillBrand();
    expect($exit)->toBe(0, $output)
        ->and($report['sites'][1]['rows']['site_settings brand'])->toBe(1)
        ->and($after)->toBe(['name' => 'A', 'phone' => '0900 000 000'])
        ->and($columns)->toBe(['logo_media_id' => $logo->id, 'logo_dark_media_id' => $dark->id, 'favicon_media_id' => $icon->id, 'og_image_media_id' => $share->id])
        ->and(DB::connection('pgsql_admin')->table('site_profiles')->where('site_id', 1)->value('updated_at'))->toBe($updatedAt)   // past the model
        // the same addresses, now from the profile (the bare key is printed as the address it names)
        ->and($images)->toBe([...$shown, 'logo_dark' => $urls->url($dark->storage_key)])
        ->and($shown['logo_dark'])->toBe($dark->storage_key);

    $again = backfillBrand();
    [$exit, $output, $report] = backfillRun(apply: true);
    expect($exit)->toBe(0, $output)
        ->and($report['totals']['converted'])->toBe(0)
        ->and($report['sites'][1]['rows']['site_settings brand'])->toBe(0)
        ->and(backfillBrand())->toBe($again);
});

it('leaves a brand address naming another website\'s picture, a file that is no picture, an outside address, or a picture other than the profile\'s', function (): void {
    $urls = app(MediaUrlResolver::class);
    $theirs = backfillMedia(2, 'logo-cua-ho-1a2b.png', 'image/png');
    $pdf = backfillMedia(1, 'ho-so-3c4d.pdf', 'application/pdf');
    $dark = backfillMedia(1, 'logo-toi-5e6f.png', 'image/png');
    $chosen = backfillMedia(1, 'logo-toi-moi-7a8b.png', 'image/png');
    backfillBrandSetting(1, [
        'name' => 'A',
        'logo' => $urls->url($theirs->storage_key),
        'favicon' => $urls->url($pdf->storage_key, 64),
        'og_image' => 'https://cdn.other.test/og.jpg',
        'logo_dark' => $urls->url($dark->storage_key),
    ], ['logo_dark_media_id' => $chosen->id]);
    $before = backfillBrand();

    [$exit, $output, $report] = backfillRun('all', apply: true);
    $lines = $report['sites'][1]['lines'];
    expect($exit)->toBe(0, $output)
        ->and(backfillBrand())->toBe($before)
        ->and($report['sites'][1]['rows']['site_settings brand'])->toBe(0)
        ->and($lines['site_settings brand logo'])->toMatchArray(['converted' => 0, 'unmatched' => 1])
        ->and($lines['site_settings brand logo']['samples'])->toBe([$urls->url($theirs->storage_key)])
        ->and($lines['site_settings brand favicon']['samples'])->toBe([$urls->url($pdf->storage_key, 64).' (application/pdf)'])
        ->and($lines['site_settings brand og_image'])->toMatchArray(['kept' => 1, 'unmatched' => 0])
        ->and($lines['site_settings brand og_image']['notes'])->toBe(['not a file of the library, shown until the contract: https://cdn.other.test/og.jpg'])
        ->and($lines['site_settings brand logo_dark']['samples'])->toBe([$urls->url($dark->storage_key).' (the profile names another picture)'])
        ->and($report['totals'])->toMatchArray(['converted' => 0, 'unmatched' => 3])
        // site 2 has no brand setting: nothing of site 1's is read for it
        ->and(array_keys($report['sites'][2]['lines']))->not->toContain('site_settings brand logo');
});

it('counts a brand image whose picture is in the Trash as one that stops showing, and moves it', function (): void {
    $logo = backfillMedia(1, 'logo-1a2b.png', 'image/png', trashed: true);
    backfillBrandSetting(1, ['name' => 'A', 'logo' => app(MediaUrlResolver::class)->url($logo->storage_key)]);

    [$exit, $output, $report] = backfillRun();
    expect($exit)->toBe(0, $output)
        ->and($report['sites'][1]['lines']['site_settings brand logo'])->toMatchArray(['converted' => 1, 'trashed' => 1])
        ->and($report['sites'][1]['trash_stops_showing'])->toBe(1)
        ->and($output)->toContain('1 covers, block pictures or brand images name a file in the Trash');

    [$exit, $output] = backfillRun(apply: true);
    expect($exit)->toBe(0, $output)
        ->and(backfillBrand()[0])->toBe(['name' => 'A'])
        ->and(backfillBrand()[1]['logo_media_id'])->toBe($logo->id);
});

it('takes the website\'s write lock before it moves a brand image, so a settings save at the same time waits', function (): void {
    $logo = backfillMedia(1, 'logo-1a2b.png', 'image/png');
    backfillBrandSetting(1, ['name' => 'A', 'logo' => app(MediaUrlResolver::class)->url($logo->storage_key)]);

    $seen = null;
    $busy = false;
    DB::listen(function (QueryExecuted $query) use (&$seen, &$busy): void {
        if ($busy || $seen !== null || $query->connectionName === 'pgsql_admin' || ! str_contains($query->sql, 'for key share') || ! str_contains($query->sql, 'storage_key')) {
            return;
        }
        $busy = true;
        try {
            // SetBrandImages' first statement (SiteWriteLock), from another connection
            DB::connection('pgsql_admin')->transaction(function (): void {
                DB::connection('pgsql_admin')->statement("SET LOCAL lock_timeout = '200ms'");
                DB::connection('pgsql_admin')->select('SELECT id FROM sites WHERE id = 1 FOR NO KEY UPDATE');
            });
            $seen = 'went through';
        } catch (QueryException $e) {
            $seen = $e->errorInfo[0] ?? 'error';
        }
        $busy = false;
    });

    [$exit, $output] = backfillRun(apply: true);
    expect($exit)->toBe(0, $output)
        ->and($seen)->toBe('55P03')
        ->and(backfillBrand()[1]['logo_media_id'])->toBe($logo->id);
});
