<?php

use App\Modules\Provisioning\ContentPack\PackReader;
use App\Modules\Provisioning\ContentPack\PackValidator;
use Tests\Fixtures\ContentPacks;

/*
 * What the pack validator refuses before any planning: a link or redirect that would leave the
 * site, a setting outside the groups the loader is meant to write, and a profile field the loader
 * could never write as given.
 */

beforeEach(function (): void {
    $this->folder = ContentPacks::use();
});

afterEach(fn () => ContentPacks::cleanup());

/** @return list<string> */
function cpProblems(): array
{
    return app(PackValidator::class)->validate(app(PackReader::class)->read('demo-co'), 'demo-co');
}

it('accepts the fixture pack as it is', function (): void {
    expect(cpProblems())->toBe([]);
});

it('refuses a redirect whose target is not a path on the site', function (string $to): void {
    ContentPacks::item($this->folder, 'redirects.json', 'redirect:vi:/trang-cu', fn (array $item): array => ['to' => $to] + $item);

    expect(implode("\n", cpProblems()))->toContain('redirect:vi:/trang-cu: to must be');
})->with([
    'protocol-relative' => ['//evil.example/x'],
    'backslash host' => ['/\evil.example'],
    'backslash later' => ['/a\b'],
    'a full link' => ['https://evil.example/x'],
    'a link inside the path' => ['/go?next=https://evil.example'],
    'whitespace' => ['/x y'],
    'a tab' => ["/x\ty"],
    'a control character' => ["/x\x01"],
    'no leading slash' => ['gioi-thieu'],
]);

it('refuses a redirect from something that is not a path on the site', function (): void {
    ContentPacks::edit($this->folder, 'redirects.json', fn (array $data): array => ['items' => [['key' => 'redirect:vi://evil.example', 'to' => '/', 'status' => 301]]]);

    expect(implode("\n", cpProblems()))->toContain('redirect:vi://evil.example: a redirect key is');
});

it('refuses a menu link that leaves the site or is not a web link', function (string $url): void {
    ContentPacks::item($this->folder, 'menus.json', 'menu:primary', function (array $menu) use ($url): array {
        $menu['items'][] = ['label' => ['vi' => 'Ngoài'], 'url' => $url];

        return $menu;
    });

    expect(implode("\n", cpProblems()))->toContain('url is not a safe link');
})->with([
    'protocol-relative' => ['//evil.example/x'],
    'backslash host' => ['/\evil.example'],
    'a link inside the path' => ['/go?next=https://evil.example'],
    'leading space' => [' /gioi-thieu'],
    'a script' => ['javascript:alert(1)'],
    'mail' => ['mailto:a@b.test'],
]);

it('keeps accepting a menu link to a site path, an http(s) site, or #', function (string $url): void {
    ContentPacks::item($this->folder, 'menus.json', 'menu:primary', function (array $menu) use ($url): array {
        $menu['items'][] = ['label' => ['vi' => 'Liên kết'], 'url' => $url];

        return $menu;
    });

    expect(cpProblems())->toBe([]);
})->with(['/gioi-thieu', 'https://baoson.net', 'http://example.test/a?b=c', '#']);

it('refuses a block link that leaves the site or is not a web link, and checks phone and email as such', function (string $type, array $props, string $message): void {
    ContentPacks::item($this->folder, 'pages.json', 'page:/gioi-thieu', function (array $page) use ($type, $props): array {
        $page['body']['vi'][] = ['type' => $type, 'props' => $props];

        return $page;
    });

    expect(implode("\n", cpProblems()))->toContain($message);
})->with([
    'protocol-relative button' => ['cta', ['heading' => 'Liên hệ', 'button_label' => 'Gửi', 'button_url' => '//evil.example/x'], 'button_url must be a site path'],
    'backslash button' => ['cta', ['heading' => 'Liên hệ', 'button_label' => 'Gửi', 'button_url' => '/\evil.example'], 'button_url must be a site path'],
    'a mail button' => ['cta', ['heading' => 'Liên hệ', 'button_label' => 'Gửi', 'button_url' => 'mailto:a@b.test'], 'button_url must be a site path'],
    'a hash button' => ['cta', ['heading' => 'Liên hệ', 'button_label' => 'Gửi', 'button_url' => '#'], 'button_url must be a site path'],
    'a partner logo link' => ['partners', ['heading' => 'Đối tác', 'logos' => [['name' => 'A', 'url' => '//evil.example']]], 'url must be a site path'],
    'a phone that is no number' => ['contact', ['heading' => 'Liên hệ', 'phone' => 'gọi đi'], 'phone must be a phone number'],
    'an email that is no address' => ['contact', ['heading' => 'Liên hệ', 'email' => 'javascript:alert(1)'], 'email must be an email address'],
]);

it('keeps accepting block links to a site path or an http(s) site, and a real phone and email', function (): void {
    ContentPacks::item($this->folder, 'pages.json', 'page:/gioi-thieu', function (array $page): array {
        $page['body']['vi'][] = ['type' => 'cta', 'props' => ['heading' => 'Liên hệ', 'button_label' => 'Gửi', 'button_url' => '/lien-he']];
        $page['body']['vi'][] = ['type' => 'partners', 'props' => ['heading' => 'Đối tác', 'logos' => [['name' => 'A', 'url' => 'https://a.example']]]];
        $page['body']['vi'][] = ['type' => 'contact', 'props' => ['heading' => 'Liên hệ', 'phone' => '0900 000 000', 'email' => 'hello@demo.test']];

        return $page;
    });

    expect(cpProblems())->toBe([]);
});

it('refuses a setting or profile link that leaves the site or is not a web link', function (string $file, string $key, Closure $change, string $message): void {
    ContentPacks::item($this->folder, $file, $key, $change);

    expect(implode("\n", cpProblems()))->toContain($message);
})->with([
    'a brand link' => ['settings.json', 'setting:general.brand', fn (array $item): array => ['value' => $item['value'] + ['website_url' => '//evil.example']] + $item, "setting:general.brand: 'website_url' must be a site path"],
    'a nested brand link' => ['settings.json', 'setting:general.brand', fn (array $item): array => ['value' => $item['value'] + ['links' => ['shop' => ['href' => '/\evil.example']]]] + $item, "setting:general.brand: 'links.shop.href' must be a site path"],
    'a social profile' => ['settings.json', 'profile', fn (array $item): array => ['fields' => $item['fields'] + ['social_links' => ['facebook' => 'javascript:alert(1)']]] + $item, "profile: 'social_links' has a value of the wrong shape"],
    'a social profile on the site' => ['settings.json', 'profile', fn (array $item): array => ['fields' => $item['fields'] + ['social_links' => ['facebook' => '/trang']]] + $item, "profile: 'social_links' has a value of the wrong shape"],
    'the trade ministry link' => ['settings.json', 'profile', fn (array $item): array => ['fields' => $item['fields'] + ['moit_url' => '//evil.example']] + $item, "profile: 'moit_url' has a value of the wrong shape"],
]);

it('refuses a list or post layout that is not a layout of the theme', function (string $field): void {
    ContentPacks::item($this->folder, 'taxonomies.json', 'tax:post_cat/tin-tuc', fn (array $item): array => [...$item, $field => 'khong-co']);

    expect(implode("\n", cpProblems()))->toContain("tax:post_cat/tin-tuc: {$field} is not a layout name.");
})->with(['list_template', 'detail_template']);

it('refuses a layout that cannot render a post as the detail layout of a category', function (string $layout): void {
    ContentPacks::item($this->folder, 'taxonomies.json', 'tax:post_cat/tin-tuc', fn (array $item): array => [...$item, 'detail_template' => $layout]);

    expect(implode("\n", cpProblems()))->toContain('tax:post_cat/tin-tuc: detail_template must be a post layout, or @parent.');
})->with(['category', 'product']);

it('accepts the follow-the-parent value and the job pair as category layouts', function (): void {
    ContentPacks::edit($this->folder, 'taxonomies.json', fn (array $data): array => ['items' => [...$data['items'],
        ['key' => 'tax:post_cat/tuyen-dung', 'name' => ['vi' => 'Tuyển dụng'], 'list_template' => 'grid-job', 'detail_template' => 'job'],
        ['key' => 'tax:post_cat/tuyen-dung/ha-noi', 'parent' => 'tax:post_cat/tuyen-dung', 'name' => ['vi' => 'Hà Nội'], 'list_template' => '@parent', 'detail_template' => '@parent'],
    ]]);

    expect(cpProblems())->toBe([]);
});

it('refuses a list layout that does not fit the kind of category', function (): void {
    ContentPacks::item($this->folder, 'taxonomies.json', 'tax:post_cat/tin-tuc', fn (array $item): array => [...$item, 'list_template' => 'job']);

    expect(implode("\n", cpProblems()))->toContain('tax:post_cat/tin-tuc: list_template must be a list layout');
});

it('writes only the setting groups the loader is meant for', function (string $key): void {
    ContentPacks::edit($this->folder, 'settings.json', fn (array $data): array => ['items' => [...$data['items'], ['key' => $key, 'value' => ['pattern' => 'flat']]]]);

    expect(implode("\n", cpProblems()))->toContain("{$key}: the loader writes only");
})->with(['setting:permalinks.posts', 'setting:permalinks.products', 'setting:storefront.other', 'setting:mail.smtp']);

it('refuses null for a localized profile field (leaving the field out keeps the live text)', function (): void {
    ContentPacks::item($this->folder, 'settings.json', 'profile', fn (array $item): array => ['localized' => $item['localized'] + ['tagline' => null]] + $item);

    expect(implode("\n", cpProblems()))->toContain('profile: tagline must be a language map');
});

it('lets a product category of a pack take any theme layout as its detail layout', function (string $layout): void {
    ContentPacks::edit($this->folder, 'taxonomies.json', function (array $data) use ($layout): array {
        $copy = collect($data['items'])->firstWhere('key', 'tax:post_cat/tin-tuc');
        $data['items'][] = [...$copy, 'key' => 'tax:product_cat/tin-tuc', 'detail_template' => $layout];

        return $data;
    });

    expect(implode("\n", cpProblems()))->not->toContain('detail_template');
})->with(['product', 'category']);

/** Add a setting item to the copied pack's settings.json. */
function cpAddSetting(string $folder, string $key, array $value, array $extra = []): void
{
    ContentPacks::edit($folder, 'settings.json', fn (array $data): array => ['items' => [...$data['items'], ['key' => $key, 'value' => $value] + $extra]]);
}

it('accepts a heading decoration, floating contact buttons and block slots written as the admin writes them', function (): void {
    cpAddSetting($this->folder, 'setting:theme.heading_decor', ['style' => 'gear', 'color' => '600', 'width' => 'wide']);
    cpAddSetting($this->folder, 'setting:storefront.floating_contact', [
        'enabled' => true, 'theme' => 'vertical', 'position' => 'right',
        'channels' => [['key' => 'phone', 'on' => true, 'value' => '0900 000 000'], ['key' => 'zalo', 'on' => true, 'value' => '0900000000'], ['key' => 'maps', 'on' => true, 'value' => 'https://maps.google.com/?q=x']],
    ]);
    cpAddSetting($this->folder, 'setting:storefront.layout_blocks', ['post_aside' => ['vi' => ['blocks' => [['type' => 'richtext', 'props' => ['paragraphs' => ['Cần tư vấn?']]]]]]]);

    expect(cpProblems())->toBe([]);
});

it('refuses a heading decoration the admin would refuse', function (array $value, string $message): void {
    cpAddSetting($this->folder, 'setting:theme.heading_decor', $value);

    expect(implode("\n", cpProblems()))->toContain($message);
})->with([
    'a shape that is not the platform\'s' => [['style' => 'skull'], "setting:theme.heading_decor: 'style' is not accepted"],
    'a colour that is not hex' => [['style' => 'gear', 'color' => 'custom', 'custom_color' => 'red'], "'custom_color' is not accepted"],
    'an unknown field' => [['style' => 'gear', 'glow' => true], "'glow' is not accepted"],
    'a mask picture' => [['mask_media' => 'a/b.png'], 'mask_media is a picture of the site'],
    'an empty value' => [[], 'value must not be empty'],
]);

it('refuses floating contact channels the storefront would drop or that are unsafe', function (array $value, string $message): void {
    cpAddSetting($this->folder, 'setting:storefront.floating_contact', $value);

    expect(implode("\n", cpProblems()))->toContain($message);
})->with([
    'javascript in a web link' => [['enabled' => true, 'channels' => [['key' => 'youtube', 'on' => true, 'value' => 'javascript:alert(1)']]], "channel 'youtube': 'value' is not a valid url"],
    'a protocol-relative link' => [['enabled' => true, 'channels' => [['key' => 'maps', 'on' => true, 'value' => '//evil.example/x']]], "channel 'maps': 'value' is not a valid url"],
    'a javascript custom link' => [['enabled' => true, 'channels' => [['key' => 'c1', 'on' => true, 'value' => 'javascript:alert(1)', 'icon' => 'ri-global-line', 'name' => 'X']]], "channel 'c1': 'value' is not a valid custom"],
    'a phone that is not a number' => [['enabled' => true, 'channels' => [['key' => 'phone', 'on' => true, 'value' => 'gọi tôi']]], "channel 'phone': 'value' is not a valid phone"],
    'an unknown channel' => [['enabled' => true, 'channels' => [['key' => 'icq', 'on' => true, 'value' => '1']]], "channel 1: 'key' must be one of"],
    'a channel listed twice' => [['enabled' => true, 'channels' => [['key' => 'phone', 'on' => false, 'value' => ''], ['key' => 'phone', 'on' => false, 'value' => '']]], "channel 'phone': the channel is listed twice"],
    'an unknown theme' => [['enabled' => true, 'theme' => 'diagonal'], "'theme' must be one of"],
    'a label in a language the pack lacks' => [['enabled' => true, 'channels' => [['key' => 'phone', 'on' => true, 'value' => '0900000000', 'tips' => ['en' => 'Call']]]], 'tips.en must be a label'],
    'a field of its own' => [['enabled' => true, 'script' => 'x'], "'script' is not a floating-contact field"],
]);

it('refuses block slots the admin would refuse', function (array $value, string $message): void {
    cpAddSetting($this->folder, 'setting:storefront.layout_blocks', $value);

    expect(implode("\n", cpProblems()))->toContain($message);
})->with([
    'an unknown slot' => [['sidebar' => ['vi' => ['blocks' => []]]], "'sidebar' is not a block slot"],
    'an unknown block type' => [['post_aside' => ['vi' => ['blocks' => [['type' => 'no-such-block', 'props' => []]]]]], "'post_aside' (vi)"],
    'a language the pack lacks' => [['post_aside' => ['en' => ['blocks' => []]]], 'the pack does not list this language'],
    'a block type that belongs in no side slot' => [['after_list' => ['vi' => ['blocks' => [['type' => 'recent_posts', 'props' => []]]]]], 'does not belong in this slot'],
    'a javascript link in a block' => [['post_aside' => ['vi' => ['blocks' => [['type' => 'cta', 'props' => ['heading' => 'Hi', 'button_label' => 'Go', 'button_url' => 'javascript:alert(1)']]]]]], "'post_aside' (vi)"],
    'blocks that are not a list' => [['post_aside' => ['vi' => ['blocks' => ['x' => 1]]]], 'needs {"blocks"'],
    'too many tabs' => [['product_tabs' => ['vi' => ['blocks' => array_fill(0, 4, ['type' => 'richtext', 'props' => ['paragraphs' => ['x']]])]]], 'holds at most 3 blocks'],
]);
