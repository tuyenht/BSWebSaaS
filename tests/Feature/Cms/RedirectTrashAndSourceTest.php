<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * The redirects a moved address leaves are marked as made by the system (source auto) and name what
 * they lead to: a post or page (entry_id), a product (product_id) or a category (taxonomy_id), so
 * the «Chuyển hướng» screen can say where a row came from. Rows in the Trash longer than
 * Redirect::TRASH_DAYS are deleted for good each night, in every website; newer ones and rows in use
 * stay. A trashed row no longer holds its old address: RouteSync may write it again.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** @return list<array{from: string, to: string, source: string, entry: int|null, product: int|null, taxonomy: int|null}> */
function rtsRows(int $site): array
{
    return DB::connection('pgsql_admin')->table('redirects')->where('site_id', $site)->whereNull('deleted_at')->orderBy('id')->get()
        ->map(fn (object $r): array => [
            'from' => (string) $r->from_path, 'to' => (string) $r->to_path, 'source' => (string) $r->source,
            'entry' => $r->entry_id === null ? null : (int) $r->entry_id,
            'product' => $r->product_id === null ? null : (int) $r->product_id,
            'taxonomy' => $r->taxonomy_id === null ? null : (int) $r->taxonomy_id,
        ])->all();
}

it('marks a moved address as made by the system and names the post, product or category it leads to', function (): void {
    [$entry, $product, $category] = app(Tenancy::class)->enter(1, function (): array {
        $sync = app(RouteSync::class);

        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'published', 'body' => ['blocks' => []]]);
        $sync->sync($entry, 'vi', 'gioi-thieu');
        $sync->sync($entry, 'vi', 've-chung-toi');

        $product = Product::query()->create(['status' => 'published', 'published_at' => now()]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá', 'slug' => 'do-ga']);
        $sync->syncProduct($product->id, null, 'vi', 'do-ga');
        $sync->syncProduct($product->id, null, 'vi', 'do-ga-cnc');

        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'parent_id' => null, 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin', 'slug' => 'tin']);
        $sync->syncTaxonomy($category, 'vi', 'tin');
        $sync->syncTaxonomy($category, 'vi', 'tin-tuc');

        return [$entry, $product, $category];
    });

    $rows = collect(rtsRows(1))->keyBy('to');
    expect($rows)->toHaveCount(3)
        ->and($rows->every(fn (array $r): bool => $r['source'] === 'auto'))->toBeTrue()
        ->and($rows->first(fn (array $r): bool => $r['entry'] !== null))->toMatchArray(['entry' => $entry->id, 'product' => null, 'taxonomy' => null])
        ->and($rows->first(fn (array $r): bool => $r['product'] !== null))->toMatchArray(['entry' => null, 'product' => $product->id, 'taxonomy' => null])
        ->and($rows->first(fn (array $r): bool => $r['taxonomy'] !== null))->toMatchArray(['entry' => null, 'product' => null, 'taxonomy' => $category->id]);

    // deleting what a redirect leads to keeps the redirect and forgets the link
    DB::connection('pgsql_admin')->table('products')->where('id', $product->id)->delete();
    expect(collect(rtsRows(1))->where('product', '!==', null)->count())->toBe(0)
        ->and(rtsRows(1))->toHaveCount(3);
});

it('writes a moved address again while a row of that old address sits in the Trash', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ', 'slug' => 'lien-he', 'status' => 'published', 'body' => ['blocks' => []]]);
        Redirect::query()->create(['locale' => 'vi', 'from_path' => '/lien-he', 'to_path' => '/cu', 'status_code' => 302, 'source' => 'manual'])->delete();

        app(RouteSync::class)->sync($entry, 'vi', 'lien-he');
        app(RouteSync::class)->sync($entry, 'vi', 'lien-he-ngay');

        expect(Redirect::query()->where('from_path', '/lien-he')->value('to_path'))->toBe('/lien-he-ngay')
            ->and(Redirect::onlyTrashed()->where('from_path', '/lien-he')->value('to_path'))->toBe('/cu');
    });
});

it('deletes for good only the redirects trashed over the retention window, in every website', function (): void {
    $this->travelTo(Carbon::parse('2027-01-15 10:00:00', 'UTC'));
    foreach ([1, 2] as $site) {
        app(Tenancy::class)->enter($site, function (): void {
            $row = fn (string $from, ?Carbon $trashedAt): Redirect => tap(
                Redirect::query()->create(['locale' => 'vi', 'from_path' => $from, 'to_path' => '/moi', 'status_code' => 301, 'source' => 'manual']),
                function (Redirect $r) use ($trashedAt): void {
                    if ($trashedAt !== null) {
                        $r->delete();
                        Redirect::withTrashed()->whereKey($r->id)->toBase()->update(['deleted_at' => $trashedAt->utc()->toDateTimeString().'+00']);
                    }
                },
            );
            $row('/qua-han', now()->subDays(Redirect::TRASH_DAYS)->subHour());
            $row('/qua-han-lau', now()->subDays(90));
            $row('/sap-het', now()->subDays(Redirect::TRASH_DAYS)->addHour());
            $row('/dang-dung', null);
        });
    }

    $this->artisan('redirects:purge-trash')->expectsOutputToContain('Purged 4 redirect(s)')->assertSuccessful();

    foreach ([1, 2] as $site) {
        $left = DB::connection('pgsql_admin')->table('redirects')->where('site_id', $site)->orderBy('id')->pluck('from_path')->all();
        expect($left)->toBe(['/sap-het', '/dang-dung']);
    }

    $this->artisan('redirects:purge-trash')->expectsOutputToContain('Purged 0 redirect(s)')->assertSuccessful();
});

it('is scheduled daily without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'redirects:purge-trash'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
