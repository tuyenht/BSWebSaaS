<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Actions\ContentBlocks\CreateContentBlock;
use App\Modules\Cms\Actions\ContentBlocks\DeleteContentBlock;
use App\Modules\Cms\Actions\ContentBlocks\DuplicateContentBlock;
use App\Modules\Cms\Actions\ContentBlocks\PublishContentBlock;
use App\Modules\Cms\Actions\ContentBlocks\SaveContentBlockBody;
use App\Modules\Cms\Actions\ContentBlocks\UpdateContentBlock;
use App\Modules\Cms\ContentBlocks\ContentBlockVersion;
use App\Modules\Cms\Exceptions\ContentBlockChangeRejectedException;
use App\Modules\Cms\Models\BlockUsage;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\ContentBlockTranslation;
use App\Modules\Cms\Models\Entry;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * The block library's write path behind screen 28: create, rename or move, save a language's
 * content, publish, copy and delete a block. The rules hold whoever calls: a block starts as a
 * draft with a reference code made from its name, content is checked and cleaned like a page body,
 * a change made from an older state is refused, a website keeps at most 200 blocks, a copy carries
 * every language and is used nowhere, a delete takes the block's languages and usage rows with it,
 * and another website's block is out of reach. Every write is in the audit log.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    $this->editor = blockLibraryUser(1, 'editor');
});

/** Run inside $site (site 1 by default). */
function inBlockSite(Closure $callback, int $site = 1): mixed
{
    return app(Tenancy::class)->enter($site, $callback);
}

/** Why a block change was refused, or null when it went through. */
function blockRefusal(Closure $change): ?string
{
    try {
        $change();
    } catch (ContentBlockChangeRejectedException $e) {
        return $e->reason;
    }

    return null;
}

function newLibraryBlock(string $name = 'Chân trang', string $region = ContentBlock::DEFAULT_REGION): ContentBlock
{
    return app(CreateContentBlock::class)->handle(test()->editor, $name, $region);
}

/** @return list<string> the audit codes written so far, oldest first */
function blockAuditCodes(): array
{
    return AuditLog::query()->orderBy('id')->pluck('action')->all();
}

it('creates a draft block whose reference code comes from its name, -2 and -3 on a clash', function (): void {
    inBlockSite(function (): void {
        $first = newLibraryBlock();
        $second = newLibraryBlock();
        $third = newLibraryBlock('CHÂN TRANG', 'footer');

        expect([$first->key, $second->key, $third->key])->toBe(['chan-trang', 'chan-trang-2', 'chan-trang-3'])
            ->and($first->status)->toBe('draft')
            ->and($first->region)->toBe('inline')
            ->and($third->region)->toBe('footer')
            ->and($first->ulid)->toHaveLength(26)
            ->and(ContentBlockTranslation::query()->count())->toBe(0);
    });

    expect(blockAuditCodes())->toBe(['cms.content_block.created', 'cms.content_block.created', 'cms.content_block.created']);
});

it('makes a code from a name with no letters, and refuses a bad name or region', function (): void {
    inBlockSite(function (): void {
        expect(newLibraryBlock('!!!')->key)->toBe('khoi')
            ->and(blockRefusal(fn () => newLibraryBlock('   ')))->toBe('name_invalid')
            ->and(blockRefusal(fn () => newLibraryBlock(str_repeat('a', 121))))->toBe('name_invalid')
            ->and(blockRefusal(fn () => newLibraryBlock('Popup', 'popup')))->toBe('region_invalid')
            ->and(ContentBlock::query()->count())->toBe(1);
    });
});

it('renames or moves a block without changing its code, and refuses a change from an older state', function (): void {
    inBlockSite(function (): void {
        $block = newLibraryBlock();
        $version = ContentBlockVersion::current($block);

        $next = app(UpdateContentBlock::class)->handle($this->editor, $block, 'Chân trang mới', 'footer', $version);
        $block->refresh();

        expect($block->name)->toBe('Chân trang mới')
            ->and($block->region)->toBe('footer')
            ->and($block->key)->toBe('chan-trang')
            ->and($next)->not->toBe($version)
            ->and($next)->toBe(ContentBlockVersion::current($block))
            // the first tab saves again from what it read before
            ->and(blockRefusal(fn () => app(UpdateContentBlock::class)->handle($this->editor, $block, 'Cũ', 'inline', $version)))->toBe('version_stale')
            ->and($block->refresh()->name)->toBe('Chân trang mới')
            ->and(blockRefusal(fn () => app(UpdateContentBlock::class)->handle($this->editor, $block, '', 'inline', $next)))->toBe('name_invalid')
            ->and(blockRefusal(fn () => app(UpdateContentBlock::class)->handle($this->editor, $block, 'Tên', 'popup', $next)))->toBe('region_invalid');
    });

    expect(blockAuditCodes())->toBe(['cms.content_block.created', 'cms.content_block.updated']);
});

it('saves a language checked and cleaned like a page body, and moves the block\'s date', function (): void {
    $this->travelTo(now()->subHour());
    $block = inBlockSite(fn () => newLibraryBlock());
    $this->travelBack();

    $version = inBlockSite(fn () => app(SaveContentBlockBody::class)->handle($this->editor, $block, 'vi', [
        ['type' => 'hero', 'props' => ['heading' => 'Xin chào']],
        ['type' => 'richtext_html', 'props' => ['html' => '<p onclick="steal()">Đoạn</p><script>alert(1)</script>']],
    ], ContentBlockVersion::current($block)));

    inBlockSite(function () use ($block, $version): void {
        $translation = ContentBlockTranslation::query()->where('content_block_id', $block->id)->sole();
        $html = (string) DB::table('content_block_translations')->where('id', $translation->id)->value('body');

        expect($translation->locale)->toBe('vi')
            ->and($translation->body['blocks'][0])->toBe(['type' => 'hero', 'props' => ['heading' => 'Xin chào']])
            ->and($html)->toContain('Đoạn')->not->toContain('script')->not->toContain('onclick')
            ->and($block->fresh()?->updated_at?->greaterThan(now()->subMinutes(5)))->toBeTrue()
            ->and($version)->toBe(ContentBlockVersion::current($block));
    });

    expect(blockAuditCodes())->toBe(['cms.content_block.created', 'cms.content_block.body_saved']);
});

it('replaces a language on a second save and keeps the other languages', function (): void {
    DB::connection('pgsql_admin')->table('site_locales')->insert(['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

    inBlockSite(function (): void {
        $block = newLibraryBlock();
        $save = app(SaveContentBlockBody::class);
        $version = $save->handle($this->editor, $block, 'vi', [['type' => 'hero', 'props' => ['heading' => 'Một']]], ContentBlockVersion::current($block));
        $version = $save->handle($this->editor, $block, 'en', [['type' => 'hero', 'props' => ['heading' => 'One']]], $version);
        $save->handle($this->editor, $block, 'vi', [['type' => 'cta', 'props' => []]], $version);

        $bodies = ContentBlockTranslation::query()->where('content_block_id', $block->id)->orderBy('locale')->get(['locale', 'body'])
            ->mapWithKeys(fn (ContentBlockTranslation $t): array => [$t->locale => $t->body['blocks'][0]['type']])->all();

        expect($bodies)->toBe(['en' => 'hero', 'vi' => 'cta']);
    });
});

it('refuses content the page body check refuses, a language the website lacks, and an older state', function (): void {
    inBlockSite(function (): void {
        $block = newLibraryBlock();
        $version = ContentBlockVersion::current($block);
        $save = fn (array $blocks, string $locale = 'vi', ?string $token = null) => app(SaveContentBlockBody::class)
            ->handle($this->editor, $block, $locale, $blocks, $token ?? $version);

        $message = null;
        try {
            $save([['type' => 'evil_script', 'props' => []]]);
        } catch (ContentBlockChangeRejectedException $e) {
            $message = $e->getMessage();
        }

        expect($message)->toBe(__('entries.err_block_unsupported', ['type' => 'evil_script']))
            // a block placing another block is not a block type (yet), so never nested
            ->and(blockRefusal(fn () => $save([['type' => 'reusable_ref', 'props' => ['block' => $block->ulid]]])))->toBe('body_invalid')
            ->and(blockRefusal(fn () => $save([['type' => 'cta', 'props' => ['button_url' => 'javascript:alert(1)']]])))->toBe('body_invalid')
            ->and(blockRefusal(fn () => $save(array_fill(0, 101, ['type' => 'cta', 'props' => []]))))->toBe('body_invalid')
            ->and(blockRefusal(fn () => $save(['a' => ['type' => 'cta', 'props' => []]])))->toBe('body_invalid')
            ->and(blockRefusal(fn () => $save([['type' => 'cta', 'props' => []]], 'en')))->toBe('locale_invalid')
            ->and(blockRefusal(fn () => $save([['type' => 'cta', 'props' => []]], 'vi', 'not-the-token')))->toBe('version_stale')
            ->and(ContentBlockTranslation::query()->count())->toBe(0);
    });
});

it('publishes a block and takes it back to draft, recording only real changes', function (): void {
    inBlockSite(function (): void {
        $block = newLibraryBlock();
        $publish = app(PublishContentBlock::class);

        expect($publish->handle($this->editor, $block)->status)->toBe('published')
            ->and($publish->handle($this->editor, $block)->status)->toBe('published')
            ->and($publish->handle($this->editor, $block, false)->status)->toBe('draft')
            ->and($block->refresh()->status)->toBe('draft');
    });

    expect(blockAuditCodes())->toBe(['cms.content_block.created', 'cms.content_block.published', 'cms.content_block.unpublished']);
});

it('copies a block with every language, a new code and no place using it', function (): void {
    DB::connection('pgsql_admin')->table('site_locales')->insert(['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

    inBlockSite(function (): void {
        $source = newLibraryBlock('Chân trang', 'footer');
        $save = app(SaveContentBlockBody::class);
        $version = $save->handle($this->editor, $source, 'vi', [['type' => 'hero', 'props' => ['heading' => 'Một']]], ContentBlockVersion::current($source));
        $save->handle($this->editor, $source, 'en', [['type' => 'hero', 'props' => ['heading' => 'One']]], $version);
        app(PublishContentBlock::class)->handle($this->editor, $source);
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        BlockUsage::query()->create(['content_block_id' => $source->id, 'entry_id' => $entry->id, 'locale' => 'vi']);

        $copy = app(DuplicateContentBlock::class)->handle($this->editor, $source);
        $bodies = fn (ContentBlock $block): array => ContentBlockTranslation::query()->where('content_block_id', $block->id)
            ->orderBy('locale')->get(['locale', 'body'])->mapWithKeys(fn (ContentBlockTranslation $t): array => [$t->locale => $t->body])->all();

        expect($copy->id)->not->toBe($source->id)
            ->and($copy->ulid)->not->toBe($source->ulid)
            ->and($copy->key)->toBe('chan-trang-2')
            ->and($copy->name)->toBe(__('blocks.copy_name', ['name' => 'Chân trang']))
            ->and($copy->region)->toBe('footer')
            ->and($copy->status)->toBe('draft')
            ->and($bodies($copy))->toBe($bodies($source))
            ->and(array_keys($bodies($copy)))->toBe(['en', 'vi'])
            ->and(BlockUsage::query()->where('content_block_id', $copy->id)->count())->toBe(0)
            ->and(BlockUsage::query()->where('content_block_id', $source->id)->count())->toBe(1)
            ->and($source->refresh()->status)->toBe('published');
    });

    expect(blockAuditCodes())->toContain('cms.content_block.duplicated');
});

it('deletes a block for good with its languages and usage rows, and records how many places used it', function (): void {
    inBlockSite(function (): void {
        $block = newLibraryBlock();
        $kept = newLibraryBlock('Đầu trang');
        app(SaveContentBlockBody::class)->handle($this->editor, $block, 'vi', [['type' => 'cta', 'props' => []]], ContentBlockVersion::current($block));
        $one = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        $two = Entry::query()->create(['type' => 'post', 'status' => 'draft']);
        BlockUsage::query()->create(['content_block_id' => $block->id, 'entry_id' => $one->id, 'locale' => 'vi']);
        BlockUsage::query()->create(['content_block_id' => $block->id, 'entry_id' => $two->id, 'locale' => 'vi']);
        BlockUsage::query()->create(['content_block_id' => $kept->id, 'entry_id' => $one->id, 'locale' => 'vi']);

        expect(app(DeleteContentBlock::class)->handle($this->editor, $block))->toBe(2)
            ->and(ContentBlock::query()->pluck('id')->all())->toBe([$kept->id])
            ->and(ContentBlockTranslation::query()->count())->toBe(0)
            ->and(BlockUsage::query()->pluck('content_block_id')->all())->toBe([$kept->id])
            ->and(blockRefusal(fn () => app(DeleteContentBlock::class)->handle($this->editor, $block)))->toBe('block_missing');
    });

    $audit = AuditLog::query()->where('action', 'cms.content_block.deleted')->sole();
    expect($audit->meta)->toMatchArray(['key' => 'chan-trang', 'places' => 2]);
});

it('keeps a website at 200 blocks, for a new block and for a copy', function (): void {
    expect(config('cms.blocks.max_per_site'))->toBe(200);
    $rows = [];
    for ($i = 1; $i <= 199; $i++) {
        $rows[] = ['ulid' => sprintf('01JBLOCKCAP%015d', $i), 'site_id' => 1, 'key' => "khoi-co-san-{$i}", 'name' => "Khối {$i}"];
    }
    DB::connection('pgsql_admin')->table('content_blocks')->insert($rows);

    inBlockSite(function (): void {
        $last = newLibraryBlock();

        expect(ContentBlock::query()->count())->toBe(200)
            ->and(blockRefusal(fn () => newLibraryBlock('Thêm nữa')))->toBe('library_full')
            ->and(blockRefusal(fn () => app(DuplicateContentBlock::class)->handle($this->editor, $last)))->toBe('library_full')
            ->and(ContentBlock::query()->count())->toBe(200);
    });

    // the limit is per website
    $other = blockLibraryUser(2, 'editor');
    expect(inBlockSite(fn () => app(CreateContentBlock::class)->handle($other, 'Chân trang')->key, 2))->toBe('chan-trang');
});

it("cannot touch another website's block", function (): void {
    $theirs = inBlockSite(fn () => ContentBlock::query()->create(['key' => 'footer', 'name' => 'Của B']), 2);

    inBlockSite(function () use ($theirs): void {
        $version = 'whatever';

        expect(blockRefusal(fn () => app(UpdateContentBlock::class)->handle($this->editor, $theirs, 'Lấy', 'inline', $version)))->toBe('block_missing')
            ->and(blockRefusal(fn () => app(SaveContentBlockBody::class)->handle($this->editor, $theirs, 'vi', [], $version)))->toBe('block_missing')
            ->and(blockRefusal(fn () => app(PublishContentBlock::class)->handle($this->editor, $theirs)))->toBe('block_missing')
            ->and(blockRefusal(fn () => app(DuplicateContentBlock::class)->handle($this->editor, $theirs)))->toBe('block_missing')
            ->and(blockRefusal(fn () => app(DeleteContentBlock::class)->handle($this->editor, $theirs)))->toBe('block_missing');
    });

    expect(inBlockSite(fn () => ContentBlock::query()->sole()->only(['name', 'status']), 2))->toBe(['name' => 'Của B', 'status' => 'draft']);
});
