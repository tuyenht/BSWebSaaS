<?php

use App\Modules\Admin\Livewire\FloatingContactSettings;
use App\Modules\Admin\Support\Settings\BranchList;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Support\BlockMediaUsage;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Mediable;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Models\SiteSetting;
use Livewire\Livewire;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * The storefront settings a pack may write beyond header and footer: the heading decoration, the
 * floating contact buttons and the blocks of the post, list and product slots. Each is loaded from
 * the pack and then drawn by the storefront; a value staff changed in the admin is left alone
 * unless the item is forced.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $this->folder = ContentPacks::use();
    $this->siteId = ContentPacks::site();
});

afterEach(fn () => ContentPacks::cleanup());

function sfAddSetting(string $folder, string $key, array $value, array $extra = []): void
{
    ContentPacks::edit($folder, 'settings.json', fn (array $data): array => ['items' => [...$data['items'], ['key' => $key, 'value' => $value] + $extra]]);
}

function sfStored(int $siteId, string $group, string $key): ?array
{
    return ContentPacks::in($siteId, fn (): ?array => SiteSetting::query()->where('group_name', $group)->where('key', $key)->value('value'));
}

function sfPage(string $path): string
{
    return test()->get('http://'.ContentPacks::HOST.$path)->assertOk()->getContent();
}

it('draws only the floating contact channels the pack switched on', function (): void {
    sfAddSetting($this->folder, 'setting:storefront.floating_contact', [
        'enabled' => true, 'theme' => 'vertical', 'position' => 'left',
        'channels' => [
            ['key' => 'phone', 'on' => true, 'value' => '0901 234 567'],
            ['key' => 'zalo', 'on' => true, 'value' => '0901234567'],
            ['key' => 'email', 'on' => false, 'value' => 'an@demo.test'],
            ['key' => 'maps', 'on' => true, 'value' => 'https://maps.google.com/?q=demo'],
        ],
    ]);
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);

    $html = sfPage('/');
    expect($html)->toContain('<nav class="sf-fc__float sf-fc__float--left"')
        ->toContain('href="tel:0901234567"')
        ->toContain('href="https://zalo.me/0901234567"')
        ->toContain('href="https://maps.google.com/?q=demo"')
        ->not->toContain('mailto:an@demo.test');
});

it('draws the heading decoration a pack sets, with the fields filled in as the admin stores them', function (): void {
    sfAddSetting($this->folder, 'setting:theme.heading_decor', ['style' => 'gear', 'color' => '700', 'width' => 'wide']);
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);

    expect(sfStored($this->siteId, 'theme', 'heading_decor'))->toEqual([
        'style' => 'gear', 'color' => '700', 'custom_color' => '', 'gradient' => false, 'custom_color_2' => '', 'align' => 'title',
        'width' => 'wide', 'weight' => 'medium', 'mask_media' => '', 'mask_keep_color' => false,
    ]);
    expect(sfPage('/'))->toContain('--sf-decor-c:var(--color-brand-700)');
});

it('shows a block of a post slot on the post page, with its text sanitized', function (): void {
    sfAddSetting($this->folder, 'setting:storefront.layout_blocks', ['post_aside' => ['vi' => ['blocks' => [
        ['type' => 'richtext_html', 'props' => ['html' => '<p>Cần tư vấn thêm? <img src=x onerror=alert(1)> Gọi ngay.</p>']],
    ]]]]);
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);

    $stored = json_encode(sfStored($this->siteId, 'storefront', 'layout_blocks'), JSON_UNESCAPED_UNICODE);
    expect($stored)->toContain('Cần tư vấn thêm?')->not->toContain('onerror');

    $html = sfPage(ContentPacks::in($this->siteId, fn (): string => SiteRoute::query()->where('entry_id', Entry::query()->whereHas('translations', fn ($q) => $q->where('title', 'Ra mắt website'))->value('id'))->value('path')));
    expect($html)->toContain('Cần tư vấn thêm?')->not->toContain('onerror');
});

it('replaces only the slots the pack manages and keeps the others staff set', function (): void {
    ContentPacks::in($this->siteId, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'layout_blocks', 'value' => [
        'after_list' => ['vi' => ['blocks' => [['type' => 'richtext', 'props' => ['paragraphs' => ['Của nhân viên']]]]]],
    ]]));
    sfAddSetting($this->folder, 'setting:storefront.layout_blocks', ['post_aside' => ['vi' => ['blocks' => [['type' => 'richtext', 'props' => ['paragraphs' => ['Của gói']]]]]]], ['managed_keys' => ['post_aside']]);

    [$exit, $output] = ContentPacks::load('demo-co', ['setting:storefront.layout_blocks']);
    expect($exit)->toBe(0, $output);

    expect(array_keys(sfStored($this->siteId, 'storefront', 'layout_blocks')))->toEqualCanonicalizing(['after_list', 'post_aside']);
});

it('keeps the slots staff set that the pack does not name, with no managed_keys given', function (): void {
    $staff = ['after_list' => ['vi' => ['blocks' => [['type' => 'richtext', 'props' => ['paragraphs' => ['Của nhân viên']]]]]]];
    ContentPacks::in($this->siteId, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'layout_blocks', 'value' => $staff]));
    $key = 'setting:storefront.layout_blocks';
    sfAddSetting($this->folder, $key, ['post_aside' => ['vi' => ['blocks' => [['type' => 'richtext', 'props' => ['paragraphs' => ['Của gói']]]]]]]);

    [$exit, $output] = ContentPacks::load('demo-co', [$key]);
    expect($exit)->toBe(0, $output);
    $stored = sfStored($this->siteId, 'storefront', 'layout_blocks');
    expect(array_keys($stored))->toEqualCanonicalizing(['after_list', 'post_aside'])
        ->and($stored['after_list'])->toEqual($staff['after_list']);

    // the first load (adopting the existing row) behaves the same
    ContentPacks::in($this->siteId, fn () => SiteSetting::query()->where('group_name', 'storefront')->where('key', 'layout_blocks')->update(['value' => $staff]));
    ContentPacks::edit($this->folder, 'pack.json', fn (array $pack): array => ['version' => 3] + $pack);
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)->and(array_keys(sfStored($this->siteId, 'storefront', 'layout_blocks')))->toContain('after_list');
});

it('checks a managed heading decoration as the whole it becomes, as the admin does', function (): void {
    $key = 'setting:theme.heading_decor';
    sfAddSetting($this->folder, $key, ['style' => 'gear', 'color' => 'accent']);
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);
    $before = sfStored($this->siteId, 'theme', 'heading_decor');

    ContentPacks::edit($this->folder, 'pack.json', fn (array $pack): array => ['version' => 2] + $pack);
    ContentPacks::item($this->folder, 'settings.json', $key, fn (array $item): array => ['value' => ['color' => 'custom', 'custom_color' => '#aabbcc'], 'managed_keys' => ['color']] + $item);
    [$exit, $output] = ContentPacks::run('demo-co', ['--apply' => true, '--force-item' => [$key]]);
    expect($exit)->not->toBe(0)
        ->and($output)->toContain("Refused: {$key} would leave the site's heading decoration with 'custom_color' not accepted (color_invalid) once merged with its current value.")
        ->and(sfStored($this->siteId, 'theme', 'heading_decor'))->toEqual($before);
});

it('stores the floating contact as the admin form does, so an unchanged admin save is not a staff edit', function (): void {
    $key = 'setting:storefront.floating_contact';
    sfAddSetting($this->folder, $key, [
        'enabled' => true,
        'channels' => [
            ['key' => 'zalo', 'on' => true, 'value' => ' 0901234567 ', 'color' => '#AABBCC', 'tips' => ['vi' => ' Nhắn Zalo ']],
            ['key' => 'phone', 'on' => true, 'value' => '0901 234 567', 'tips' => ['vi' => '']],
            ['key' => 'c1', 'on' => true, 'value' => 'https://example.com/x', 'icon' => 'ri-global-line', 'name' => ' Cửa hàng '],
        ],
    ]);
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);

    $stored = sfStored($this->siteId, 'storefront', 'floating_contact');
    expect(array_column($stored['channels'], 'key'))->toBe(['zalo', 'phone', 'c1', 'messenger', 'email', 'sms', 'maps'])
        ->and($stored['channels'][0])->toEqual(['key' => 'zalo', 'on' => true, 'value' => '0901234567', 'color' => '#aabbcc', 'tips' => ['vi' => 'Nhắn Zalo']])
        ->and($stored['channels'][1]['tips'])->toBe([])
        ->and($stored['channels'][2]['name'])->toBe('Cửa hàng')
        ->and($stored['offset_x'])->toBe(20)->and($stored['buy'])->toEqual(['enabled' => false, 'color' => '#ff7a46']);

    // an admin save with no change
    $admin = User::factory()->create();
    $this->artisan('rbac:sync')->assertSuccessful();
    ContentPacks::in($this->siteId, fn () => grantRole($admin, 'site-admin', ['settings.manage']));
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    ContentPacks::in($this->siteId, function () use ($admin): void {
        Livewire::actingAs($admin->fresh())->test(FloatingContactSettings::class)->call('save')->assertHasNoErrors();
    });
    expect(sfStored($this->siteId, 'storefront', 'floating_contact'))->toEqual($stored);

    [, $full] = ContentPacks::run('demo-co', ['-vv' => true]);
    expect(ContentPacks::verdict($full, $key))->toBe('unchanged');
});

it('syncs the library usage rows of the block slots after a load, as the admin does', function (): void {
    $key = 'setting:storefront.layout_blocks';
    $rowId = ContentPacks::in($this->siteId, function (): int {
        $row = SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'layout_blocks', 'value' => []]);
        $media = StorefrontCards::media('stale-picture');
        Mediable::query()->create(['media_id' => $media->id, 'mediable_type' => 'site_setting', 'mediable_id' => $row->id, 'collection' => BlockMediaUsage::COLLECTION]);

        return (int) $row->id;
    });
    sfAddSetting($this->folder, $key, ['post_aside' => ['vi' => ['blocks' => [['type' => 'richtext', 'props' => ['paragraphs' => ['Một']]]]]]]);

    [$exit, $output] = ContentPacks::load('demo-co', [$key]);
    expect($exit)->toBe(0, $output);

    $rows = ContentPacks::in($this->siteId, fn (): int => Mediable::query()->where('mediable_type', 'site_setting')->where('mediable_id', $rowId)->count());
    expect($rows)->toBe(0);
});

it('keeps a value staff changed in the admin, shows both at -vv, and writes the pack only when forced', function (string $group, string $name, array $first, array $second, array $staff): void {
    $key = "setting:{$group}.{$name}";
    sfAddSetting($this->folder, $key, $first);
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);

    ContentPacks::in($this->siteId, fn () => SiteSetting::query()->where('group_name', $group)->where('key', $name)->update(['value' => $staff]));
    ContentPacks::edit($this->folder, 'pack.json', fn (array $pack): array => ['version' => 2] + $pack);
    ContentPacks::item($this->folder, 'settings.json', $key, fn (array $item): array => ['value' => $second] + $item);

    [, $full] = ContentPacks::run('demo-co', ['-vv' => true]);
    expect(ContentPacks::verdict($full, $key))->toBe('skip:edited');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)->and(sfStored($this->siteId, $group, $name))->toEqual($staff);

    [$exit, $output] = ContentPacks::load('demo-co', [$key]);
    expect($exit)->toBe(0, $output)->and(sfStored($this->siteId, $group, $name))->not->toEqual($staff);
})->with([
    'floating contact' => ['storefront', 'floating_contact',
        ['enabled' => true, 'channels' => [['key' => 'phone', 'on' => true, 'value' => '0901234567']]],
        ['enabled' => true, 'channels' => [['key' => 'phone', 'on' => true, 'value' => '0907654321']]],
        ['enabled' => false, 'channels' => []]],
    'heading decoration' => ['theme', 'heading_decor',
        ['style' => 'gear'], ['style' => 'wave'],
        ['style' => 'star', 'color' => 'accent', 'custom_color' => '', 'gradient' => false, 'custom_color_2' => '', 'align' => 'title', 'width' => 'medium', 'weight' => 'medium', 'mask_media' => '', 'mask_keep_color' => false]],
    'block slots' => ['storefront', 'layout_blocks',
        ['post_aside' => ['vi' => ['blocks' => [['type' => 'richtext', 'props' => ['paragraphs' => ['Một']]]]]]],
        ['post_aside' => ['vi' => ['blocks' => [['type' => 'richtext', 'props' => ['paragraphs' => ['Hai']]]]]]],
        ['post_aside' => ['vi' => ['blocks' => []]]]],
]);

it('plans the change of a setting at -vv without writing', function (): void {
    sfAddSetting($this->folder, 'setting:storefront.floating_contact', ['enabled' => true, 'channels' => [['key' => 'phone', 'on' => true, 'value' => '0901234567']]]);
    ContentPacks::load();
    ContentPacks::edit($this->folder, 'pack.json', fn (array $pack): array => ['version' => 2] + $pack);
    ContentPacks::item($this->folder, 'settings.json', 'setting:storefront.floating_contact', fn (array $item): array => ['value' => ['enabled' => true, 'channels' => [['key' => 'phone', 'on' => true, 'value' => '0907654321']]]] + $item);

    $writes = ContentPacks::writes(function () use (&$output): void {
        [, $output] = ContentPacks::run('demo-co', ['-vv' => true]);
    });

    expect($writes)->toBe([])
        ->and(ContentPacks::verdict($output, 'setting:storefront.floating_contact'))->toBe('update')
        ->and($output)->toContain('0907654321');
});

it('stores a factory picture as the site\'s picture id with its in-use row, plans the same pack as unchanged, and drops the row with the picture', function (): void {
    $about = ContentPacks::in($this->siteId, fn () => StorefrontCards::media('about'));
    $key = 'setting:storefront.footer';
    $factories = [
        ['key' => 'nha-may-1', 'title' => ['vi' => 'NHÀ MÁY 1'], 'lat' => 21.2983367, 'lng' => 105.8672342, 'approximate' => false, 'map_query' => 'Xưởng mẫu, Hà Nội'],
        ['key' => 'nha-may-2', 'title' => ['vi' => 'NHÀ MÁY 2'], 'image_media_id' => ['media' => 'about'], 'lat' => 21.2936586, 'lng' => 105.8649934, 'approximate' => true],
    ];
    sfAddSetting($this->folder, $key, ['factories' => $factories]);

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);

    $stored = sfStored($this->siteId, 'storefront', 'footer');
    $rows = fn (): array => ContentPacks::in($this->siteId, fn (): array => Mediable::query()->where('collection', BranchList::COLLECTION)->get(['media_id', 'mediable_type'])->map(fn ($row): array => [$row->media_id, $row->mediable_type])->all());
    expect($stored['factories'][0])->toEqual($factories[0])
        ->and($stored['factories'][1]['image_media_id'])->toBe($about->id)
        ->and($stored['factories'][1]['lat'])->toBe(21.2936586)
        ->and($rows())->toBe([[$about->id, 'site_setting']]);

    [$exit, $output] = ContentPacks::run();
    expect($exit)->toBe(0, $output)->and(ContentPacks::verdict($output, $key))->toBe('unchanged');

    ContentPacks::item($this->folder, 'settings.json', $key, function (array $item): array {
        unset($item['value']['factories'][1]['image_media_id']);

        return $item;
    });
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)
        ->and(array_key_exists('image_media_id', sfStored($this->siteId, 'storefront', 'footer')['factories'][1]))->toBeFalse()
        ->and($rows())->toBe([]);
});

it('loads a factory whose picture is not on the site yet without one, with a warning', function (): void {
    sfAddSetting($this->folder, 'setting:storefront.footer', ['factories' => [['key' => 'nha-may-1', 'title' => 'NHÀ MÁY 1', 'image_media_id' => ['media' => 'about']]]]);

    [$exit, $output] = ContentPacks::run();
    expect($exit)->toBe(0, $output)
        ->and($output)->toContain("setting:storefront.footer: image slot 'about' has no file and no existing image; factory 1 is shown without a picture.");

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)
        ->and(sfStored($this->siteId, 'storefront', 'footer')['factories'])->toBe([['key' => 'nha-may-1', 'title' => 'NHÀ MÁY 1']]);
});

it('says the factory is shown without a picture when its live picture was purged', function (): void {
    $live = ContentPacks::in($this->siteId, fn () => StorefrontCards::media('live-plant'));
    ContentPacks::in($this->siteId, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'footer', 'value' => ['factories' => [['key' => 'nha-may-1', 'title' => 'NHÀ MÁY 1', 'image_media_id' => $live->id]]]]));
    ContentPacks::in($this->siteId, fn () => $live->forceDelete());
    sfAddSetting($this->folder, 'setting:storefront.footer', ['factories' => [['key' => 'nha-may-1', 'title' => 'NHÀ MÁY 1', 'image_media_id' => ['media' => 'about']]]]);

    [$exit, $output] = ContentPacks::run();
    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('factory 1 is shown without a picture.')
        ->and($output)->not->toContain('keeps its live picture');
});

it('keeps the live picture of a factory whose picture slot is not on the site yet', function (): void {
    $live = ContentPacks::in($this->siteId, fn () => StorefrontCards::media('live-plant'));
    ContentPacks::in($this->siteId, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'footer', 'value' => ['factories' => [['key' => 'nha-may-1', 'title' => 'NHÀ MÁY 1', 'image_media_id' => $live->id]]]]));
    sfAddSetting($this->folder, 'setting:storefront.footer', ['factories' => [['key' => 'nha-may-1', 'title' => 'NHÀ MÁY 1', 'image_media_id' => ['media' => 'about']]]]);

    [$exit, $output] = ContentPacks::run();
    expect($exit)->toBe(0, $output)->and($output)->toContain("image slot 'about' has no file and no existing image; factory 1 keeps its live picture.");

    [$exit, $output] = ContentPacks::load('demo-co', ['setting:storefront.footer']);
    expect($exit)->toBe(0, $output)
        ->and(sfStored($this->siteId, 'storefront', 'footer')['factories'][0]['image_media_id'])->toBe($live->id);
});
