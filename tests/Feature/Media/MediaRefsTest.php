<?php

use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaRefs;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * The media rows a page names, in one query whatever the shape: a bigint id, a ulid or an old storage
 * key. Only the current website's files outside the Trash are found, so a value naming another
 * website's file or a trashed one finds nothing; values of the wrong shape are never sent, and
 * nothing named reads nothing.
 */

beforeEach(fn () => seedTenancy());

function refsMedia(string $slug, int $site = 1): Media
{
    return app(Tenancy::class)->enter($site, fn (): Media => Media::query()->create([
        'collection' => 'library', 'slug' => $slug, 'hash' => hash('sha256', $site.$slug),
        'storage_key' => "s{$site}/library/{$slug}-abcdef123456.png", 'disk' => 'r2',
        'mime' => 'image/png', 'size' => 1024, 'width' => 800, 'height' => 600,
    ]));
}

it('finds a file by its id, its ulid (any case) and its old key, in one query', function (): void {
    $byId = refsMedia('by-id');
    $byUlid = refsMedia('by-ulid');
    $byKey = refsMedia('by-key');

    app(Tenancy::class)->enter(1, function () use ($byId, $byUlid, $byKey): void {
        $refs = null;
        $count = countQueries(function () use (&$refs, $byId, $byUlid, $byKey): void {
            $refs = MediaRefs::rows([$byId->id, (string) $byId->id], [strtolower($byUlid->ulid)], [$byKey->storage_key]);
        });

        expect($count)->toBe(1)
            ->and($refs->byId($byId->id)?->storage_key)->toBe($byId->storage_key)
            ->and($refs->byId((string) $byId->id)?->id)->toBe($byId->id)
            ->and($refs->byUlid($byUlid->ulid)?->id)->toBe($byUlid->id)
            ->and($refs->byUlid(strtolower($byUlid->ulid))?->id)->toBe($byUlid->id)
            ->and($refs->byKey($byKey->storage_key)?->id)->toBe($byKey->id)
            // each row carries what a reader draws with
            ->and($refs->byKey($byKey->storage_key)?->only(MediaRefs::COLUMNS))->toMatchArray([
                'id' => $byKey->id, 'ulid' => $byKey->ulid, 'storage_key' => $byKey->storage_key, 'mime' => 'image/png', 'width' => 800, 'height' => 600,
            ])
            // a row found by one shape answers to the others too
            ->and($refs->byKey($byId->storage_key)?->id)->toBe($byId->id);
    });
});

it('reads nothing when nothing of a valid shape is named', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $refs = null;
        $count = countQueries(function () use (&$refs): void {
            $refs = MediaRefs::rows([null, 0, -3, '', 'abc', '12x', 1.5], ['', 'not-a-ulid', null], ['', 'shield', 'https://cdn.example.test/a/b.jpg', null, 7]);
        });

        expect($count)->toBe(0)
            ->and($refs->byId(1))->toBeNull()
            ->and($refs->byUlid('01ARZ3NDEKTSV4RRFFQ69G5FAV'))->toBeNull()
            ->and($refs->byKey('shield'))->toBeNull()
            ->and(MediaRefs::none()->byId(1))->toBeNull();
    });
});

it('never finds a file in the Trash', function (): void {
    $trashed = refsMedia('trashed');
    app(Tenancy::class)->enter(1, fn () => Media::query()->whereKey($trashed->id)->firstOrFail()->delete());

    app(Tenancy::class)->enter(1, function () use ($trashed): void {
        $refs = MediaRefs::rows([$trashed->id], [$trashed->ulid], [$trashed->storage_key]);

        expect($refs->byId($trashed->id))->toBeNull()
            ->and($refs->byUlid($trashed->ulid))->toBeNull()
            ->and($refs->byKey($trashed->storage_key))->toBeNull();
    });
});

it('finds a file in the Trash only when asked to, and never another website\'s', function (): void {
    $trashed = refsMedia('trashed');
    $theirs = refsMedia('theirs', 2);
    app(Tenancy::class)->enter(1, fn () => Media::query()->whereKey($trashed->id)->firstOrFail()->delete());

    app(Tenancy::class)->enter(1, function () use ($trashed, $theirs): void {
        $refs = MediaRefs::rows([], [], [$trashed->storage_key, $theirs->storage_key], ['deleted_at'], withTrashed: true, lock: true);

        expect($refs->byKey($trashed->storage_key)?->id)->toBe($trashed->id)
            ->and($refs->byKey($trashed->storage_key)?->trashed())->toBeTrue()
            ->and($refs->byKey($theirs->storage_key))->toBeNull();
    });
});

it('never finds another website’s file, by id, ulid or key', function (): void {
    $theirs = refsMedia('theirs', 2);
    $ours = refsMedia('ours');

    app(Tenancy::class)->enter(1, function () use ($theirs, $ours): void {
        $refs = MediaRefs::rows([$theirs->id, $ours->id], [$theirs->ulid], [$theirs->storage_key]);

        expect($refs->byId($theirs->id))->toBeNull()
            ->and($refs->byUlid($theirs->ulid))->toBeNull()
            ->and($refs->byKey($theirs->storage_key))->toBeNull()
            ->and($refs->byId($ours->id)?->id)->toBe($ours->id);
    });

    // the same values read from website B find B's file
    app(Tenancy::class)->enter(2, function () use ($theirs): void {
        expect(MediaRefs::rows([$theirs->id])->byId($theirs->id)?->id)->toBe($theirs->id);
    });
});

it('tells a stored id and a key-shaped string from everything else', function (mixed $value, bool $id, bool $key): void {
    expect(MediaRefs::id($value) !== null)->toBe($id)
        ->and(MediaRefs::looksLikeKey($value))->toBe($key);
})->with([
    'an int id' => [42, true, false],
    'a numeric string id' => ['42', true, false],
    'zero' => [0, false, false],
    'a negative id' => [-1, false, false],
    'a storage key' => ['s1/library/a-abcdef123456.png', false, true],
    'a glyph name' => ['shield', false, false],
    'an outside address' => ['https://cdn.example.test/a/b.jpg', false, false],
    'empty' => ['', false, false],
    'null' => [null, false, false],
]);
