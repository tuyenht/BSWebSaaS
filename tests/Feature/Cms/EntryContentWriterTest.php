<?php

use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Support\EntryContentWriter;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * The writer takes content in the shape RecordEntryRevision::snapshot produces and writes it through
 * the editor's checks. It never records a revision and never moves the status — those are the
 * caller's decisions, made from what write() reports (RestoreEntryRevisionTest covers the restore
 * road end to end).
 */

beforeEach(function (): void {
    seedTenancy();
});

/** A draft page with vi text and one hero block. */
function writerPage(): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => ['layout' => 'wide']]);
    $page->translations()->create([
        'locale' => 'vi', 'title' => 'Trang', 'slug' => 'trang', 'status' => 'draft', 'excerpt' => 'Tóm tắt',
        'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Tiêu đề']]]],
    ]);

    return $page->fresh();
}

it('reads the snapshot shape the revision recorder writes, unchanged', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $page = writerPage();
        $snapshot = RecordEntryRevision::snapshot($page, $page->translationFor('vi'));

        expect(app(EntryContentWriter::class)->read($snapshot))->toBe([
            'title' => 'Trang',
            'slug' => 'trang',
            'blocks' => [['type' => 'hero', 'props' => ['heading' => 'Tiêu đề']]],
            'excerpt' => 'Tóm tắt',
            'seo_title' => null,
            'seo_description' => null,
            'og_image_id' => null,
            'template_override' => null,
            'cover_media_id' => null,
            'meta' => ['layout' => 'wide'],
        ]);
    });
});

it('reads content that cannot be written today as null', function (array $change): void {
    app(Tenancy::class)->enter(1, function () use ($change): void {
        $page = writerPage();
        $snapshot = array_replace(RecordEntryRevision::snapshot($page, $page->translationFor('vi')), $change);

        expect(app(EntryContentWriter::class)->read($snapshot))->toBeNull();
    });
})->with([
    'an unknown format' => [['v' => 99]],
    'a missing title' => [['title' => null]],
    'a retired block type' => [['body' => ['blocks' => [['type' => 'retired_slider', 'props' => []]]]]],
    'meta that is not an object' => [['meta' => 'wide']],
]);

it('writes one language, reports whether the shared fields changed, and records no revision', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $page = writerPage();
        $page->translations()->create(['locale' => 'en', 'title' => 'Page', 'slug' => 'page', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $writer = app(EntryContentWriter::class);
        $content = $writer->read(RecordEntryRevision::snapshot($page, $page->translationFor('vi')));
        expect($content)->not->toBeNull();

        $same = $writer->write($page, 'vi', [...$content, 'title' => 'Trang mới']);
        expect($same['shared_changed'])->toBeFalse()
            ->and($same['translation']->title)->toBe('Trang mới');

        $shared = $writer->write($page->fresh(), 'vi', [...$content, 'meta' => ['layout' => 'narrow']]);
        expect($shared['shared_changed'])->toBeTrue();

        $page = $page->fresh();
        expect($page->meta)->toBe(['layout' => 'narrow'])
            ->and($page->status)->toBe('draft')
            ->and($page->translationFor('en')->title)->toBe('Page')
            ->and(EntryRevision::query()->where('entry_id', $page->id)->count())->toBe(0);
    });
});
