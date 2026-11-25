<?php

use App\Modules\Admin\Support\MediaUsageLocator;
use App\Modules\Cms\Actions\ContentBlocks\CreateContentBlock;
use App\Modules\Cms\Actions\ContentBlocks\DeleteContentBlock;
use App\Modules\Cms\Actions\ContentBlocks\DuplicateContentBlock;
use App\Modules\Cms\Actions\ContentBlocks\SaveContentBlockBody;
use App\Modules\Cms\ContentBlocks\ContentBlockVersion;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * A shared block's body names pictures and files like a page body does, so its saves write the
 * library's "in use" rows (mediables, collection content, owner `content_block`) in the same
 * transaction: a picture used only inside a block is never offered as unused, and so never deleted
 * from under the places that show the block. Removing it from the body drops its row, deleting the
 * block drops them all, media:reconcile-content-usage rebuilds them, and another website's media is
 * never recorded.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 / 2
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    $this->editor = blockLibraryUser(1, 'editor');
});

function blockMedia(string $slug, string $hashChar): Media
{
    return Media::query()->create([
        'collection' => 'library', 'slug' => $slug, 'hash' => str_repeat($hashChar, 64),
        'storage_key' => "a/library/{$slug}-abcdef123456.png", 'disk' => 'r2',
        'mime' => 'image/png', 'size' => 1024,
    ]);
}

/** @return list<int> the media ids recorded as used by the block's body */
function blockContentMediaIds(ContentBlock $block): array
{
    return Mediable::query()
        ->where('mediable_type', 'content_block')->where('mediable_id', $block->id)->where('collection', 'content')
        ->orderBy('media_id')->pluck('media_id')->map(fn ($id): int => (int) $id)->all();
}

/** @param list<int> $ids */
function heroBlocks(array $ids): array
{
    return array_map(fn (int $id): array => ['type' => 'hero', 'props' => ['image_url' => $id]], $ids);
}

function saveBlockBody(ContentBlock $block, string $locale, array $blocks): void
{
    app(SaveContentBlockBody::class)->handle(test()->editor, $block, $locale, $blocks, ContentBlockVersion::current($block));
}

it('records the pictures a saved block body uses, and drops one taken out of the body', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = blockMedia('a', 'a');
        $b = blockMedia('b', 'b');
        $block = app(CreateContentBlock::class)->handle($this->editor, 'Chân trang', ContentBlock::DEFAULT_REGION);

        saveBlockBody($block, 'vi', heroBlocks([$a->id, $b->id]));
        expect(blockContentMediaIds($block))->toBe([$a->id, $b->id]);

        saveBlockBody($block, 'vi', heroBlocks([$b->id]));
        expect(blockContentMediaIds($block))->toBe([$b->id]);
    });
});

it('writes the rows in the save transaction: a save that fails leaves none', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = blockMedia('a', 'a');
        $block = app(CreateContentBlock::class)->handle($this->editor, 'Chân trang', ContentBlock::DEFAULT_REGION);

        try {
            DB::transaction(function () use ($block, $a): void {
                saveBlockBody($block, 'vi', heroBlocks([$a->id]));
                expect(blockContentMediaIds($block))->toBe([$a->id]);

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        expect(blockContentMediaIds($block))->toBe([]);
    });
});

it('never records another website\'s media or an unknown id', function (): void {
    $foreignId = app(Tenancy::class)->enter(2, fn (): int => (int) blockMedia('theirs', 'c')->id);

    app(Tenancy::class)->enter(1, function () use ($foreignId): void {
        $mine = blockMedia('mine', 'a');
        $block = app(CreateContentBlock::class)->handle($this->editor, 'Chân trang', ContentBlock::DEFAULT_REGION);

        saveBlockBody($block, 'vi', heroBlocks([$mine->id, $foreignId, 9_000_000_000_000_000]));

        expect(blockContentMediaIds($block))->toBe([$mine->id]);
    });

    expect(DB::connection('pgsql_admin')->table('mediables')->where('media_id', $foreignId)->count())->toBe(0);
});

it('counts every language of the block, and a copy records its own rows', function (): void {
    DB::connection('pgsql_admin')->table('site_locales')->insert(
        ['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    );

    app(Tenancy::class)->enter(1, function (): void {
        $a = blockMedia('a', 'a');
        $b = blockMedia('b', 'b');
        $block = app(CreateContentBlock::class)->handle($this->editor, 'Chân trang', ContentBlock::DEFAULT_REGION);
        saveBlockBody($block, 'vi', heroBlocks([$a->id]));
        saveBlockBody($block, 'en', heroBlocks([$b->id]));

        expect(blockContentMediaIds($block))->toBe([$a->id, $b->id]);

        $copy = app(DuplicateContentBlock::class)->handle($this->editor, $block);
        expect(blockContentMediaIds($copy))->toBe([$a->id, $b->id]);
    });
});

it('drops the block\'s rows when the block is deleted', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = blockMedia('a', 'a');
        $block = app(CreateContentBlock::class)->handle($this->editor, 'Chân trang', ContentBlock::DEFAULT_REGION);
        saveBlockBody($block, 'vi', heroBlocks([$a->id]));
        expect(blockContentMediaIds($block))->toBe([$a->id]);

        app(DeleteContentBlock::class)->handle($this->editor, $block);

        expect(Mediable::query()->where('mediable_type', 'content_block')->where('mediable_id', $block->id)->exists())->toBeFalse();
    });
});

it('media:reconcile-content-usage rebuilds a block\'s rows and drops the rows of a block that is gone', function (): void {
    [$blockId, $mediaId, $goneId] = app(Tenancy::class)->enter(1, function (): array {
        $m = blockMedia('a', 'a');
        $block = ContentBlock::query()->create(['key' => 'seeded', 'name' => 'Seeded', 'region' => 'inline', 'status' => 'draft']);
        $block->translations()->create(['locale' => 'vi', 'body' => ['blocks' => heroBlocks([$m->id])]]);
        expect(blockContentMediaIds($block))->toBe([]); // written by a road that skipped the save

        // a stale row of a block that no longer exists
        $gone = ContentBlock::query()->create(['key' => 'gone', 'name' => 'Gone', 'region' => 'inline', 'status' => 'draft']);
        $goneId = (int) $gone->id;
        Mediable::query()->create(['media_id' => $m->id, 'mediable_type' => 'content_block', 'mediable_id' => $goneId, 'collection' => 'content']);
        ContentBlock::query()->whereKey($goneId)->delete(); // one statement: the observer never runs

        return [(int) $block->id, (int) $m->id, $goneId];
    });

    $this->artisan('media:reconcile-content-usage')->assertSuccessful();
    $this->artisan('media:reconcile-content-usage')->assertSuccessful(); // idempotent

    app(Tenancy::class)->enter(1, function () use ($blockId, $mediaId, $goneId): void {
        expect(blockContentMediaIds(ContentBlock::query()->findOrFail($blockId)))->toBe([$mediaId])
            ->and(Mediable::query()->where('mediable_type', 'content_block')->where('mediable_id', $goneId)->exists())->toBeFalse();
    });
});

it('counts a picture used only inside a block as used in the media library', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $inBlock = blockMedia('in-block', 'a');
        $unused = blockMedia('unused', 'b');
        $block = app(CreateContentBlock::class)->handle($this->editor, 'Chân trang', ContentBlock::DEFAULT_REGION);
        saveBlockBody($block, 'vi', heroBlocks([$inBlock->id]));

        $locator = app(MediaUsageLocator::class);
        $files = Media::query()->whereIn('id', [$inBlock->id, $unused->id])->get();
        expect($locator->countsFor($files))->toEqual([$inBlock->id => 1, $unused->id => 0]);

        foreach ([false, true] as $liveOwners) {
            $unusedQuery = Media::query()->whereIn('id', [$inBlock->id, $unused->id]);
            $locator->constrain($unusedQuery, false, $liveOwners);
            $usedQuery = Media::query()->whereIn('id', [$inBlock->id, $unused->id]);
            $locator->constrain($usedQuery, true, $liveOwners);

            expect($unusedQuery->pluck('id')->map(fn ($id): int => (int) $id)->all())->toBe([$unused->id])
                ->and($usedQuery->pluck('id')->map(fn ($id): int => (int) $id)->all())->toBe([$inBlock->id]);
        }

        $usages = $locator->usagesFor($inBlock, 'vi');
        expect($usages)->toHaveCount(1)
            ->and($usages[0]['kind'])->toBe('block')
            ->and($usages[0]['role'])->toBe('content')
            ->and($usages[0]['label'])->toContain('Chân trang')
            ->and($usages[0]['url'])->toBe(route('admin.blocks.edit', ['ulid' => $block->ulid]))
            ->and($usages[0]['url'])->not->toMatch('~/'.$block->id.'(?:[/?#]|$)~')
            // the picker's chip reads its text from media.picker_kind_{kind}: every kind needs that key
            ->and(__('media.picker_kind_'.$usages[0]['kind']))->not->toBe('media.picker_kind_'.$usages[0]['kind']);
    });
});

it('has a picker chip text for every kind of place the usage list can name', function (): void {
    $source = (string) file_get_contents(app_path('Modules/Admin/Support/MediaUsageLocator.php'));
    preg_match_all("/'kind' => '([a-z_]+)'/", $source, $m);

    expect($m[1])->not->toBeEmpty();
    foreach (array_unique([...$m[1], 'page', 'post']) as $kind) {
        expect(__('media.picker_kind_'.$kind))->not->toBe('media.picker_kind_'.$kind);
    }
});
