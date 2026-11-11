<?php

use App\Modules\Media\Actions\UploadMedia;
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

it('refuses a block the admin would refuse, with the plain message', function (array $value, array $messages): void {
    cpAddSetting($this->folder, 'setting:storefront.layout_blocks', $value);

    expect(cpProblems())->toBe($messages);
})->with([
    'an unknown block type' => [
        ['post_aside' => ['vi' => ['blocks' => [['type' => 'no-such-block', 'props' => []]]]]],
        ["setting:storefront.layout_blocks: 'post_aside' (vi): The \"no-such-block\" block is not supported."],
    ],
    'a javascript link in a block' => [
        ['post_aside' => ['vi' => ['blocks' => [['type' => 'cta', 'props' => ['heading' => 'Hi', 'button_label' => 'Go', 'button_url' => 'javascript:alert(1)']]]]]],
        [
            "setting:storefront.layout_blocks: 'post_aside.vi.blocks.0.props.button_url' must be a site path (/…, never //host, a backslash or a space) or an http(s) link.",
            "setting:storefront.layout_blocks: 'post_aside' (vi): A link in the \"cta\" block is not valid.",
            "setting:storefront.layout_blocks: 'post_aside' (vi): block 1 (cta): button_url must be a site path (/…, never //host, a backslash or a space) or an http(s) link.",
        ],
    ],
    'a library picture by id in a block' => [
        ['post_aside' => ['vi' => ['blocks' => [['type' => 'about', 'props' => ['heading' => 'Hi', 'image_url' => 7]]]]]],
        [
            "setting:storefront.layout_blocks: 'post_aside.vi.blocks.0.props.image_url' must be a site path (/…, never //host, a backslash or a space) or an http(s) link.",
            "setting:storefront.layout_blocks: 'post_aside' (vi): block 1 (about) names a library picture or file by id or key; a block slot of a pack cannot reference media.",
        ],
    ],
    'a library file by key in a block' => [
        ['post_aside' => ['vi' => ['blocks' => [['type' => 'cta', 'props' => ['heading' => 'Hi', 'button_label' => 'Go', 'button_url_do' => ['k' => 'file', 'file' => 'docs/brochure.pdf']]]]]]],
        [
            "setting:storefront.layout_blocks: 'post_aside' (vi): A button points to a file that is not in this website’s library. Choose the button’s file again.",
            "setting:storefront.layout_blocks: 'post_aside' (vi): block 1 (cta) names a library picture or file by id or key; a block slot of a pack cannot reference media.",
        ],
    ],
]);

it('accepts a managed heading decoration whose custom colour is left to the live value', function (): void {
    cpAddSetting($this->folder, 'setting:theme.heading_decor', ['color' => 'custom'], ['managed_keys' => ['color']]);

    expect(cpProblems())->toBe([]);
});
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
        'phone_bar' => ['enabled' => true, 'cta' => ['label' => ['vi' => 'Yêu cầu báo giá'], 'url' => '/lien-he']],
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
    'a phone bar that is not an object' => [['enabled' => true, 'phone_bar' => true], "'phone_bar' must be"],
    'a phone bar cell with an unsafe link' => [['enabled' => true, 'phone_bar' => ['enabled' => true, 'cta' => ['label' => ['vi' => 'Báo giá'], 'url' => 'javascript:alert(1)']]], "'phone_bar.cta.url' must be a safe link"],
    'a phone bar cell in a language the pack lacks' => [['enabled' => true, 'phone_bar' => ['enabled' => true, 'cta' => ['label' => ['en' => 'Quote'], 'url' => '/lien-he']]], "'phone_bar.cta.label.en' must be a text"],
    'a phone bar cell with no text' => [['enabled' => true, 'phone_bar' => ['enabled' => true, 'cta' => ['label' => [], 'url' => '/lien-he']]], "'phone_bar.cta.label' needs a text"],
]);

it('refuses block slots the admin would refuse', function (array $value, string $message): void {
    cpAddSetting($this->folder, 'setting:storefront.layout_blocks', $value);

    expect(implode("\n", cpProblems()))->toContain($message);
})->with([
    'an unknown slot' => [['sidebar' => ['vi' => ['blocks' => []]]], "'sidebar' is not a block slot"],
    'a language the pack lacks' => [['post_aside' => ['en' => ['blocks' => []]]], 'the pack does not list this language'],
    'a block type that belongs in no side slot' => [['after_list' => ['vi' => ['blocks' => [['type' => 'recent_posts', 'props' => []]]]]], 'does not belong in this slot'],
    'blocks that are not a list' => [['post_aside' => ['vi' => ['blocks' => ['x' => 1]]]], 'needs {"blocks"'],
    'too many tabs' => [['product_tabs' => ['vi' => ['blocks' => array_fill(0, 4, ['type' => 'richtext', 'props' => ['paragraphs' => ['x']]])]]], 'holds at most 3 blocks'],
]);

it('refuses a page parent that is no other page, the home page, the page itself, or a pack page listed later', function (mixed $parent, string $message): void {
    ContentPacks::item($this->folder, 'pages.json', 'page:/', fn (array $page): array => ['parent' => $parent === 'later' ? 'page:/gioi-thieu' : $parent] + $page);

    expect(implode("\n", cpProblems()))->toContain($message);
})->with([
    'a category' => ['tax:post_cat/dich-vu', 'page:/: parent must be the key of another page'],
    'not a string' => [7, 'page:/: parent must be the key of another page'],
    'the home page' => ['page:/', 'page:/: parent must be the key of another page'],
    'a pack page listed later' => ['later', 'page:/: its parent page:/gioi-thieu must come earlier in pages.json.'],
]);

it('refuses a page that names itself as its parent', function (): void {
    ContentPacks::item($this->folder, 'pages.json', 'page:/gioi-thieu', fn (array $page): array => ['parent' => 'page:/gioi-thieu'] + $page);

    expect(cpProblems())->toContain('page:/gioi-thieu: a page cannot be its own parent.');
});

it('accepts a page parent listed earlier in the pack, a page outside it (the plan checks the site), and null', function (?string $parent): void {
    ContentPacks::edit($this->folder, 'pages.json', function (array $data) use ($parent): array {
        $data['items'][] = ['key' => 'page:/doi-ngu', 'parent' => $parent, 'verified' => true, 'status' => 'published',
            'title' => ['vi' => 'Đội ngũ'], 'body' => ['vi' => [['type' => 'richtext', 'props' => ['paragraphs' => ['Đội ngũ.']]]]]];

        return $data;
    });

    expect(cpProblems())->toBe([]);
})->with(['page:/gioi-thieu', 'page:/ngoai-goi', null]);

/** Adds a media slot whose file holds $bytes. */
function cpAddSlot(string $folder, array $slot, string $file, string $bytes): void
{
    file_put_contents($folder.'/'.$file, $bytes);
    ContentPacks::edit($folder, 'media.json', function (array $media) use ($slot, $file): array {
        $media['slots'][] = $slot + ['file' => $file];

        return $media;
    });
}

it('accepts a PDF in a document slot', function (): void {
    cpAddSlot($this->folder, ['slot' => 'profile', 'kind' => 'document'], 'media/profile.pdf', "%PDF-1.4\n%%EOF\n");

    expect(cpProblems())->toBe([]);
});

it('refuses a file named .pdf that is not a PDF, in a document slot', function (): void {
    cpAddSlot($this->folder, ['slot' => 'profile', 'kind' => 'document'], 'media/profile.pdf', (string) file_get_contents($this->folder.'/media/header.png'));

    expect(implode("\n", cpProblems()))->toContain("media slot 'profile': media/profile.pdf is missing or not a PDF document.");
});

it('refuses a PDF in an image slot', function (?string $kind): void {
    cpAddSlot($this->folder, ['slot' => 'banner'] + ($kind === null ? [] : ['kind' => $kind]), 'media/banner.pdf', "%PDF-1.4\n%%EOF\n");

    expect(implode("\n", cpProblems()))->toContain("media slot 'banner': media/banner.pdf is missing or not a JPEG/PNG/WebP image.");
})->with(['no kind' => [null], 'kind image' => ['image']]);

it('refuses a slot kind other than image or document', function (): void {
    cpAddSlot($this->folder, ['slot' => 'clip', 'kind' => 'video'], 'media/clip.pdf', "%PDF-1.4\n%%EOF\n");

    expect(implode("\n", cpProblems()))->toContain("media slot 'clip': kind is image or document.");
});

it('refuses a document slot used where an image goes', function (): void {
    cpAddSlot($this->folder, ['slot' => 'profile', 'kind' => 'document'], 'media/profile.pdf', "%PDF-1.4\n%%EOF\n");
    ContentPacks::edit($this->folder, 'media.json', function (array $media): array {
        foreach ($media['slots'] as $i => $slot) {
            if ($slot['slot'] === 'header') {
                $media['slots'][$i] = ['slot' => 'header', 'kind' => 'document', 'file' => 'media/profile.pdf'];
            }
        }

        return $media;
    });

    expect(implode("\n", cpProblems()))->toContain("media slot 'header' is a document, not an image.");
});

it('refuses a file over the media library\'s upload limit', function (): void {
    cpAddSlot($this->folder, ['slot' => 'profile', 'kind' => 'document'], 'media/profile.pdf', "%PDF-1.4\n".str_repeat('0', UploadMedia::MAX_KB * 1024));

    expect(implode("\n", cpProblems()))->toContain("media slot 'profile': media/profile.pdf is 10.0 MB; the media library takes files up to 10 MB.");
});

/** Adds a call to action to the about page whose button downloads $slot, its label followed by the file's type and size. */
function cpAddFileButton(string $folder, string $slot): void
{
    ContentPacks::item($folder, 'pages.json', 'page:/gioi-thieu', function (array $page) use ($slot): array {
        $page['body']['vi'][] = ['type' => 'cta', 'props' => [
            'heading' => 'Hồ sơ năng lực',
            'button_label' => ['text' => 'Tải hồ sơ', 'type_and_size_of' => $slot],
            'button_url_do' => ['k' => 'file', 'file' => ['media' => $slot]],
        ]];

        return $page;
    });
}

it('accepts a page button that downloads a document slot', function (): void {
    cpAddSlot($this->folder, ['slot' => 'profile', 'kind' => 'document'], 'media/profile.pdf', "%PDF-1.4\n%%EOF\n");
    cpAddFileButton($this->folder, 'profile');

    expect(cpProblems())->toBe([]);
});

it('refuses a download button that names an image slot or a slot media.json does not list', function (): void {
    cpAddFileButton($this->folder, 'header');
    cpAddFileButton($this->folder, 'brochure');

    expect(implode("\n", cpProblems()))->toContain("page:/gioi-thieu: media slot 'header' is an image, not a document.")
        ->toContain("page:/gioi-thieu: document slot 'brochure' is not in media.json.");
});

it('refuses a download button outside a page or post body', function (): void {
    cpAddSlot($this->folder, ['slot' => 'profile', 'kind' => 'document'], 'media/profile.pdf', "%PDF-1.4\n%%EOF\n");
    cpAddSetting($this->folder, 'setting:storefront.layout_blocks', ['post_aside' => ['vi' => ['blocks' => [['type' => 'consult', 'props' => [
        'heading' => 'Hồ sơ năng lực',
        'button_label' => 'Tải hồ sơ',
        'button_url_do' => ['k' => 'file', 'file' => ['media' => 'profile']],
    ]]]]]]);

    expect(implode("\n", cpProblems()))->toContain('setting:storefront.layout_blocks: a file download button is supported only in a page or post body.');
});

/** Adds a footer setting holding the given factories to the fixture pack. */
function cpFooter(string $folder, array $factories): void
{
    ContentPacks::edit($folder, 'settings.json', fn (array $data): array => ['items' => [...$data['items'], ['key' => 'setting:storefront.footer', 'value' => ['factories' => $factories]]]]);
}

it('accepts up to twelve branches with their map and picture, the footer showing the first six', function (): void {
    $factories = array_map(fn (int $n): array => ['key' => "nha-may-{$n}", 'title' => ['vi' => "Nhà máy {$n}"], 'lat' => 21.0655337, 'lng' => 105.556209, 'approximate' => true], range(1, 12));
    $factories[1] += ['image_media_id' => ['media' => 'about'], 'map_query' => 'Xưởng mẫu, Hà Nội', 'map_url' => 'https://maps.google.com/?q=2'];
    $factories[8] += ['map_url' => 'https://maps.google.com/?q=9'];
    cpFooter($this->folder, $factories);

    expect(cpProblems())->toBe([]);
});

it('refuses a branch list the settings check refuses', function (array $change, string $problem): void {
    $factories = [['key' => 'nha-may-1', 'title' => ['vi' => 'Nhà máy 1']], ['key' => 'nha-may-2', 'title' => ['vi' => 'Nhà máy 2']]];
    $factories[1] = array_replace($factories[1], $change);
    cpFooter($this->folder, $factories);

    expect(implode("\n", cpProblems()))->toContain($problem);
})->with([
    'a duplicate key' => [['key' => 'nha-may-1'], "setting:storefront.footer: 'factories.1.key' is not accepted (key_duplicate)"],
    'an unknown key' => [['embed' => '<iframe>'], "setting:storefront.footer: 'factories.1.embed' is not accepted (field_invalid)"],
    'a title only in another language' => [['title' => ['en' => 'Plant 2']], "setting:storefront.footer: a factory would be dropped in 'vi'"],
    'a latitude out of range' => [['lat' => -91, 'lng' => 105], "setting:storefront.footer: 'factories.1.lat' is not accepted (lat_invalid)"],
    'a picture given as a key' => [['image_media_id' => 's/library/about-a4262e1c9bcb.webp'], 'setting:storefront.footer: factory 2 image_media_id must be an image reference'],
    'a picture given as an id' => [['image_media_id' => 12], 'setting:storefront.footer: factory 2 image_media_id must be an image reference'],
    'a picture slot not in media.json' => [['image_media_id' => ['media' => 'nowhere']], "setting:storefront.footer: image slot 'nowhere' is not in media.json"],
]);

it('reports a bad picture and a bad branch list in the same run', function (): void {
    cpFooter($this->folder, [
        ['key' => 'nha-may-1', 'title' => ['vi' => 'Nhà máy 1'], 'image_media_id' => 12],
        ['key' => 'nha-may-1', 'title' => ['vi' => 'Nhà máy 2']],
    ]);

    $problems = implode("\n", cpProblems());
    expect($problems)->toContain('factory 1 image_media_id must be an image reference')
        ->and($problems)->toContain("'factories.1.key' is not accepted (key_duplicate)");
});

it('refuses a thirteenth branch', function (): void {
    cpFooter($this->folder, array_map(fn (int $n): array => ['key' => "nha-may-{$n}", 'title' => "Nhà máy {$n}"], range(1, 13)));

    expect(implode("\n", cpProblems()))->toContain("'factories' is not accepted (branches_too_many)");
});

it('refuses a category intro picture that is not an image reference', function (mixed $cover): void {
    ContentPacks::item($this->folder, 'taxonomies.json', 'tax:post_cat/tin-tuc', fn (array $item): array => [...$item, 'cover' => $cover]);

    expect(implode("\n", cpProblems()))->toContain('tax:post_cat/tin-tuc: cover must be an image reference');
})->with(['a path' => ['s/library/a.webp'], 'a list' => [['about']], 'an extra key' => [['media' => 'about', 'x' => 1]]]);

it('refuses a category intro that is not a language map of text within the limit', function (mixed $description, string $message): void {
    ContentPacks::item($this->folder, 'taxonomies.json', 'tax:post_cat/tin-tuc', fn (array $item): array => [...$item, 'description' => $description]);

    expect(implode("\n", cpProblems()))->toContain($message);
})->with([
    'plain text' => ['Giới thiệu', 'description must be a language map'],
    'a number' => [['vi' => 5], 'description (vi) must be text'],
    'a language the pack does not list' => [['xx' => 'a'], 'description has a language the pack does not list (xx)'],
    'too long' => [['vi' => str_repeat('a', 1001)], 'description (vi) is longer than 1000 characters'],
]);

it('accepts a category intro that is empty or null, which clears it', function (mixed $text): void {
    ContentPacks::item($this->folder, 'taxonomies.json', 'tax:post_cat/tin-tuc', fn (array $item): array => [...$item, 'description' => ['vi' => $text], 'cover' => ['media' => 'about']]);

    expect(cpProblems())->toBe([]);
})->with(['empty' => [''], 'null' => [null]]);
