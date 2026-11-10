<?php

use App\Modules\Admin\Support\MediaUsageLocator;
use App\Modules\Admin\Support\Settings\BranchList;
use App\Modules\Admin\Support\Settings\SettingsChangeRejected;
use App\Modules\Cms\Support\BlockMediaUsage;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Chrome\StorefrontChrome;

/*
 * The website's branches and factories are one list, `storefront.footer.factories`: the footer
 * shows the first six, a branch map or card list reads them all. One check (BranchList) refuses a
 * list it cannot store, turns a picture's library key into the id of a picture of the same site,
 * and the library's "in use" rows (collection `branch`) follow the list, so a factory photo is
 * never listed as unused, the filter the trash is emptied from.
 */

beforeEach(function (): void {
    seedTenancy();
    config(['cms.map_hosts' => []]);
});

function branchMedia(int $siteId, string $slug, string $mime = 'image/webp'): Media
{
    return app(Tenancy::class)->enter($siteId, function () use ($slug, $mime): Media {
        $hash = hash('sha256', $slug.$mime);

        return Media::query()->create([
            'collection' => 'library', 'slug' => $slug, 'hash' => $hash,
            'storage_key' => 's/library/'.$slug.'-'.substr($hash, 0, 12).'.webp',
            'mime' => $mime, 'size' => 50_000, 'width' => 1600, 'height' => 900,
        ]);
    });
}

/** @return array<string, mixed> */
function branchItem(int $n, array $extra = []): array
{
    return array_replace(['key' => "nha-may-{$n}", 'title' => ['vi' => "NHÀ MÁY {$n}"]], $extra);
}

/** The reason and field of the refusal, or null when the list passes. */
function branchRefusal(mixed $factories): ?string
{
    try {
        BranchList::checked($factories);
    } catch (SettingsChangeRejected $e) {
        return "{$e->reason} {$e->field}";
    }

    return null;
}

it('accepts the full branch fields next to the footer\'s own', function (): void {
    $list = [
        branchItem(1, ['area_m2' => 2000, 'name' => ['vi' => 'Lắp ráp'], 'address' => 'Đường 418', 'map_url' => 'https://maps.google.com/?q=1',
            'full_name' => ['vi' => 'Nhà máy 1 – Xuân Sơn'], 'locality' => 'Trung Giã', 'map_query' => 'Cơ khí An Vinh, Xuân Sơn',
            'lat' => 21.2983367, 'lng' => 105.8672342, 'approximate' => false, 'image_media_id' => 's/library/x.webp']),
        branchItem(2, ['lat' => -90, 'lng' => 180, 'approximate' => true]),
        ['title' => 'Không khóa'],
    ];

    expect(BranchList::checked($list))->toBe($list)
        ->and(BranchList::checked(null))->toBe([])
        ->and(branchRefusal(array_map(fn (int $n): array => branchItem($n), range(1, 12))))->toBeNull();
});

it('refuses a list it cannot store, naming the field', function (mixed $factories, string $refusal): void {
    expect(branchRefusal($factories))->toBe($refusal);
})->with([
    'a 13th item' => [array_map(fn (int $n): array => branchItem($n), range(1, 13)), 'branches_too_many factories'],
    'not a list' => [['a' => branchItem(1)], 'branches_invalid factories'],
    'an item that is not an object' => [['Nhà máy 1'], 'branch_invalid factories.0'],
    'an unknown key' => [[branchItem(1, ['iframe' => '<iframe>'])], 'field_invalid factories.0.iframe'],
    'a duplicate key' => [[branchItem(1), branchItem(1)], 'key_duplicate factories.1.key'],
    'a key that is not a slug' => [[branchItem(1, ['key' => 'Nhà máy'])], 'key_invalid factories.0.key'],
    'no title' => [[['key' => 'a', 'title' => ['vi' => ' ']]], 'title_required factories.0.title'],
    'a text too long' => [[branchItem(1, ['full_name' => str_repeat('a', 301)])], 'text_invalid factories.0.full_name'],
    'a latitude above 90' => [[branchItem(1, ['lat' => 90.5, 'lng' => 105])], 'lat_invalid factories.0.lat'],
    'a latitude as text' => [[branchItem(1, ['lat' => '21.1', 'lng' => 105])], 'lat_invalid factories.0.lat'],
    'a longitude below -180' => [[branchItem(1, ['lat' => 21, 'lng' => -180.0000001])], 'lng_invalid factories.0.lng'],
    'eight decimals' => [[branchItem(1, ['lat' => 21.12345678, 'lng' => 105])], 'lat_invalid factories.0.lat'],
    'fourteen decimals' => [[branchItem(1, ['lat' => 21.00000000000001, 'lng' => 105])], 'lat_invalid factories.0.lat'],
    'a decimal past the twelfth place' => [[branchItem(1, ['lat' => 21, 'lng' => 105.1234567000001])], 'lng_invalid factories.0.lng'],
    'a tiny number in exponent form' => [[branchItem(1, ['lat' => 1e-13, 'lng' => 105])], 'lat_invalid factories.0.lat'],
    'a latitude without its longitude' => [[branchItem(1, ['lat' => 21.1])], 'coordinates_invalid factories.0.lng'],
    'a map search of 201 characters' => [[branchItem(1, ['map_query' => str_repeat('a', 201)])], 'map_query_invalid factories.0.map_query'],
    'a map search with a line break' => [[branchItem(1, ['map_query' => "a\nb"])], 'map_query_invalid factories.0.map_query'],
    'approximate as text' => [[branchItem(1, ['approximate' => 'yes'])], 'approximate_invalid factories.0.approximate'],
    'an area that is not whole' => [[branchItem(1, ['area_m2' => 12.5])], 'area_invalid factories.0.area_m2'],
    'an unsafe map link' => [[branchItem(1, ['map_url' => 'javascript:alert(1)'])], 'map_url_invalid factories.0.map_url'],
    'a picture as an object' => [[branchItem(1, ['image_media_id' => ['id' => 1]])], 'image_invalid factories.0.image_media_id'],
]);

it('stores a picture as the id of a picture of the same site, and refuses another site\'s file or a document', function (): void {
    $own = branchMedia(1, 'plant-2');
    $other = branchMedia(2, 'plant-b');
    $pdf = branchMedia(1, 'profile', 'application/pdf');

    $stored = fn (string $key): array => app(Tenancy::class)->enter(1, fn (): array => BranchList::stored([branchItem(1, ['image_media_id' => $key])]));
    $refusal = function (string $key) use ($stored): ?string {
        try {
            $stored($key);
        } catch (SettingsChangeRejected $e) {
            return "{$e->reason} {$e->field}";
        }

        return null;
    };

    expect($stored($own->storage_key))->toBe([branchItem(1, ['image_media_id' => $own->id])])
        ->and($refusal($other->storage_key))->toBe('image_invalid factories.0.image_media_id')
        ->and($refusal($pdf->storage_key))->toBe('image_invalid factories.0.image_media_id')
        ->and($refusal('s/library/missing.webp'))->toBe('image_invalid factories.0.image_media_id');
});

it('saves a stored list again without the picture that is gone or not the site\'s, keeping the rest', function (): void {
    $kept = branchMedia(1, 'kept');
    $trashed = branchMedia(1, 'trashed');
    $purged = branchMedia(1, 'purged');
    $other = branchMedia(2, 'other');
    app(Tenancy::class)->enter(1, function () use ($trashed, $purged): void {
        $trashed->delete();
        $purged->forceDelete();
    });

    $list = [
        branchItem(1, ['image_media_id' => $kept->id]),
        branchItem(2, ['image_media_id' => $trashed->id]),
        branchItem(3, ['image_media_id' => $purged->id, 'lat' => 21.0655337, 'lng' => 105.556209]),
        branchItem(4, ['image_media_id' => $other->id]),
    ];
    $stored = app(Tenancy::class)->enter(1, fn (): array => BranchList::stored($list));

    expect($stored)->toBe([
        branchItem(1, ['image_media_id' => $kept->id]),
        branchItem(2, ['image_media_id' => $trashed->id]),
        branchItem(3, ['lat' => 21.0655337, 'lng' => 105.556209]),
        branchItem(4),
    ]);
});

it('writes the in-use rows of the branch pictures with the setting, removes them with the list, and never counts another site\'s id', function (): void {
    $plant2 = branchMedia(1, 'plant-2');
    $plant3 = branchMedia(1, 'plant-3');
    $other = branchMedia(2, 'other');

    $rows = function (): array {
        return app(Tenancy::class)->enter(1, fn (): array => Mediable::query()->where('collection', BranchList::COLLECTION)->orderBy('media_id')->pluck('media_id')->all());
    };
    $settingId = app(Tenancy::class)->enter(1, fn (): int => (int) SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'footer', 'value' => []])->getKey());
    $sync = fn (array $factories) => app(Tenancy::class)->enter(1, fn () => BranchList::syncUsage($settingId, $factories));

    $sync([branchItem(1), branchItem(2, ['image_media_id' => $plant2->id]), branchItem(3, ['image_media_id' => $plant3->id]), branchItem(4, ['image_media_id' => $other->id])]);
    expect($rows())->toBe([$plant2->id, $plant3->id]);

    $sync([branchItem(2, ['image_media_id' => $plant2->id]), branchItem(3, ['image_media_id' => $plant2->id])]);
    $sync([branchItem(2, ['image_media_id' => $plant2->id]), branchItem(3, ['image_media_id' => $plant2->id])]);
    expect($rows())->toBe([$plant2->id]);

    $sync([]);
    expect($rows())->toBe([]);
});

it('shows a factory photo as used under "Chi nhánh / Nhà máy", keeps it out of the unused filter, and leaves other pictures and site B alone', function (): void {
    app()->setLocale('vi');
    $plant = branchMedia(1, 'plant-2');
    $loose = branchMedia(1, 'loose');
    $otherPlant = branchMedia(2, 'plant-b');
    $otherLoose = branchMedia(2, 'loose-b');
    foreach ([[1, $plant], [2, $otherPlant]] as [$siteId, $media]) {
        app(Tenancy::class)->enter($siteId, function () use ($media): void {
            $row = SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'footer', 'value' => ['factories' => [branchItem(2, ['image_media_id' => $media->id])]]]);
            BranchList::syncUsage((int) $row->getKey(), $row->value['factories']);
        });
    }

    $read = fn (int $siteId, Media $media): array => app(Tenancy::class)->enter($siteId, function () use ($media): array {
        $locator = app(MediaUsageLocator::class);
        $used = Media::query();
        $locator->constrain($used, true);
        $unused = Media::query();
        $locator->constrain($unused, false);

        return [$used->pluck('id')->all(), $unused->pluck('id')->all(), $locator->usagesFor($media, 'vi')];
    });

    [$used, $unused, $usages] = $read(1, $plant);
    expect($used)->toBe([$plant->id])
        ->and($unused)->toBe([$loose->id])
        ->and($usages)->toBe([['kind' => 'site', 'role' => 'content', 'label' => 'Chi nhánh / Nhà máy', 'url' => route('admin.settings.appearance')]])
        ->and($read(1, $loose)[2])->toBe([]);

    [$used, $unused] = $read(2, $otherPlant);
    expect($used)->toBe([$otherPlant->id])->and($unused)->toBe([$otherLoose->id]);

    // A block's picture still reads as before, beside the branch rows.
    app(Tenancy::class)->enter(1, function () use ($loose): void {
        Mediable::query()->create(['media_id' => $loose->id, 'mediable_type' => 'site_setting', 'mediable_id' => SiteSetting::query()->value('id'), 'collection' => BlockMediaUsage::COLLECTION]);
    });
    expect($read(1, $loose)[2])->toBe([['kind' => 'site', 'role' => 'content', 'label' => 'Cột phải / Kêu gọi cuối trang', 'url' => route('admin.settings.appearance')]])
        ->and($read(1, $plant)[1])->toBe([]);
});

it('drops the branch rows when the footer setting row is deleted', function (): void {
    $plant = branchMedia(1, 'plant-2');
    $count = app(Tenancy::class)->enter(1, function () use ($plant): int {
        $row = SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'footer', 'value' => ['factories' => [branchItem(2, ['image_media_id' => $plant->id])]]]);
        BranchList::syncUsage((int) $row->getKey(), $row->value['factories']);
        $row->delete();

        return Mediable::query()->where('collection', BranchList::COLLECTION)->count();
    });

    expect($count)->toBe(0);
});

it('rebuilds the branch rows from the stored list with the usage reconcile command', function (): void {
    $plant = branchMedia(1, 'plant-2');
    app(Tenancy::class)->enter(1, function () use ($plant): void {
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'footer', 'value' => ['factories' => [branchItem(2, ['image_media_id' => $plant->id])]]]);
        $stale = SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'header', 'value' => []]);
        Mediable::query()->create(['media_id' => $plant->id, 'mediable_type' => 'site_setting', 'mediable_id' => $stale->id, 'collection' => BranchList::COLLECTION]);
    });

    $this->artisan('media:reconcile-content-usage')->assertSuccessful();

    $rows = app(Tenancy::class)->enter(1, fn (): array => Mediable::query()->where('collection', BranchList::COLLECTION)->get(['media_id', 'mediable_id'])->map(fn ($row): array => [$row->media_id, $row->mediable_id])->all());
    $footerId = app(Tenancy::class)->enter(1, fn (): int => (int) SiteSetting::query()->where('key', 'footer')->value('id'));
    expect($rows)->toBe([[$plant->id, $footerId]]);
});

it('reads every branch field for a branch map, while the footer keeps its own shape', function (): void {
    $raw = ['factories' => [
        branchItem(1, ['area_m2' => 2000, 'name' => ['vi' => 'Lắp ráp'], 'address' => ['vi' => 'Đường 418'], 'full_name' => ['vi' => 'Nhà máy 1 – Xuân Sơn'],
            'locality' => ['vi' => 'Trung Giã'], 'map_query' => ' Cơ khí An Vinh ', 'lat' => 21.2983367, 'lng' => 105.8672342, 'approximate' => false, 'image_media_id' => 7]),
        branchItem(1, ['lat' => 95, 'lng' => 105, 'approximate' => true, 'image_media_id' => 's/library/x.webp', 'map_url' => 'javascript:alert(1)']),
        ['title' => ''],
        ...array_map(fn (int $n): array => branchItem($n), range(3, 14)),
    ]];

    $branches = StorefrontChrome::branches($raw, 'vi', 'vi');

    expect($branches)->toHaveCount(12)
        ->and($branches[0])->toBe([
            'key' => 'nha-may-1', 'title' => 'NHÀ MÁY 1', 'full_name' => 'Nhà máy 1 – Xuân Sơn', 'locality' => 'Trung Giã', 'area' => 2000,
            'name' => 'Lắp ráp', 'address' => 'Đường 418', 'map_url' => null, 'map_query' => 'Cơ khí An Vinh',
            'lat' => 21.2983367, 'lng' => 105.8672342, 'approximate' => false, 'image_media_id' => 7,
        ])
        // a key already taken, an out-of-range pair and a picture that is not an id are not read
        ->and($branches[1])->toMatchArray(['key' => null, 'lat' => null, 'lng' => null, 'approximate' => true, 'image_media_id' => null, 'map_url' => null])
        ->and($branches[11]['key'])->toBe('nha-may-12')
        ->and(StorefrontChrome::branches(null, 'vi', 'vi'))->toBe([])
        ->and(StorefrontChrome::footer($raw, 'vi', 'vi')['factories'])->toHaveCount(StorefrontChrome::MAX_FACTORIES);
});
