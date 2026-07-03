<?php

use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * Media foundation — RLS isolation (§2 / Gate #1). A master/attachment stored for one site must
 * never be visible in another site's context (RLS + FORCE, fail-closed). BelongsToSite stamps
 * site_id from the current context on create. (RlsCoverageTest separately enforces RLS + FORCE +
 * policy on both new tables, data-driven.)
 */

beforeEach(function (): void {
    seedTenancy(); // sites 1 (a.test) + 2 (b.test)
});

/**
 * @param  array<string, mixed>  $attrs
 */
function makeMedia(int $site, array $attrs = []): Media
{
    return app(Tenancy::class)->enter($site, fn () => Media::query()->create(array_merge([
        'collection' => 'library',
        'hash' => str_repeat('a', 64),
        'storage_key' => 'a-vinh/library/anh-aaaaaaaaaaaa.jpg',
        'mime' => 'image/jpeg',
        'size' => 12345,
    ], $attrs)));
}

it('stamps site_id + a ulid from the current site context on create', function (): void {
    $media = makeMedia(1);

    expect($media->site_id)->toBe(1)
        ->and($media->ulid)->not->toBeEmpty();
});

it('isolates media per site (RLS fail-closed)', function (): void {
    makeMedia(1);

    $inSite1 = app(Tenancy::class)->enter(1, fn () => Media::query()->count());
    $inSite2 = app(Tenancy::class)->enter(2, fn () => Media::query()->count());

    expect($inSite1)->toBe(1)
        ->and($inSite2)->toBe(0);
});

it('stamps site_id on a polymorphic attachment', function (): void {
    $attachment = app(Tenancy::class)->enter(1, function () {
        $media = Media::query()->create([
            'collection' => 'library',
            'hash' => str_repeat('b', 64),
            'storage_key' => 'a-vinh/library/x-bbbbbbbbbbbb.jpg',
            'mime' => 'image/jpeg',
            'size' => 999,
        ]);

        return Mediable::query()->create([
            'media_id' => $media->id,
            'mediable_type' => 'entry',
            'mediable_id' => 42,
            'collection' => 'gallery',
        ]);
    });

    expect($attachment->site_id)->toBe(1);
});
