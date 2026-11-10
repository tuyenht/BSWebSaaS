<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Tenancy\Models\SiteSetting;
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
    expect($html)->toContain('<nav class="sf-fc sf-fc--left"')
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
