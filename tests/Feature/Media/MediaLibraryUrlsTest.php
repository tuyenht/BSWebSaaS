<?php

use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaLibraryUrls;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * Slice 1 (content ↔ media by media_id). Content stores media_id, never a URL; MediaLibraryUrls is
 * the single batched id→URL resolver every render surface uses. Proves: one query for many ids
 * (anti-N+1 at scale), URLs derived via the driver, unknown/other-tenant ids omitted (fail-safe +
 * RLS isolation), and empty/garbage input short-circuits with no query.
 */

beforeEach(function (): void {
    seedTenancy();
    config(['media.driver' => 'static', 'media.cloudflare.base' => 'https://files.test']);
});

function seedMediaRow(string $slug, string $hashChar, string $key): Media
{
    return Media::query()->create([
        'collection' => 'library',
        'slug' => $slug,
        'hash' => str_repeat($hashChar, 64),
        'storage_key' => $key,
        'disk' => 'r2',
        'mime' => 'image/png',
        'size' => 1024,
    ]);
}

it('resolves many ids to URLs in ONE query, omitting ids that do not resolve', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = seedMediaRow('x', 'a', 'a/library/x-aaaaaaaaaaaa.png');
        $b = seedMediaRow('y', 'b', 'a/library/y-bbbbbbbbbbbb.png');

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        $urls = app(MediaLibraryUrls::class)->urlsForIds([$a->id, $b->id, 999_999]);
        $queries = DB::connection()->getQueryLog();

        expect($urls)->toBe([
            $a->id => 'https://files.test/a/library/x-aaaaaaaaaaaa.png',
            $b->id => 'https://files.test/a/library/y-bbbbbbbbbbbb.png',
        ]);
        expect($queries)->toHaveCount(1);   // batched: one SELECT regardless of id count
    });
});

it('short-circuits with no query when there are no usable ids', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        $urls = app(MediaLibraryUrls::class)->urlsForIds(['abc', 0, -3, null]);

        expect($urls)->toBe([]);
        expect(DB::connection()->getQueryLog())->toHaveCount(0);
    });
});

it('never resolves another tenant\'s media id (RLS isolation)', function (): void {
    $foreign = app(Tenancy::class)->enter(2, fn (): Media => seedMediaRow('z', 'c', 'b/library/z-cccccccccccc.png'));

    app(Tenancy::class)->enter(1, function () use ($foreign): void {
        expect(app(MediaLibraryUrls::class)->urlsForIds([$foreign->id]))->toBe([]);
    });
});
