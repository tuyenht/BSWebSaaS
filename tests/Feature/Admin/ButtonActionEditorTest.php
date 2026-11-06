<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The editor's "Khi bấm nút" control on every block button, as the page builder design draws it:
 * the current kind in one button, the list of seven kinds (no "Mở biểu mẫu" yet), the field of the
 * chosen kind, a problem shown once the field is left (at once for an unsafe link or a page that
 * has gone), an older address sorted into a kind with a note, and a page or file taken only from
 * this site.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

function actionEditor(array $permissions = ['entries.create', 'entries.edit.any', 'media.view']): User
{
    $user = User::factory()->create();
    grantRole($user, 'editor', $permissions);

    return $user->fresh();
}

function actionPage(string $title, string $path, string $type = 'page'): Entry
{
    $entry = Entry::query()->create(['type' => $type, 'status' => 'published', 'published_at' => now()]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => ltrim($path, '/'), 'status' => 'published', 'body' => ['blocks' => []]]);
    SiteRoute::query()->create(['locale' => 'vi', 'path' => $path, 'entry_id' => $entry->id]);

    return $entry;
}

function actionDoc(string $key, string $name = 'Hồ sơ năng lực.pdf'): Media
{
    return Media::query()->create([
        'collection' => 'library', 'slug' => 'ho-so', 'hash' => hash('sha256', $key),
        'storage_key' => $key, 'disk' => 'r2', 'mime' => 'application/pdf', 'size' => 2_516_582, 'original_filename' => $name,
    ]);
}

it('draws the control in place of the bare address field, for every button of the block', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        Livewire::actingAs(actionEditor())->test(Editor::class)
            ->set('blocks', [['type' => 'hero', 'props' => ['heading' => 'Xin chào', 'cta_label' => 'Liên hệ', 'cta2_label' => 'Gọi ngay']]])
            ->assertSeeHtml('id="btn-act-0-props-cta_url"')
            ->assertSeeHtml('id="btn-act-0-props-cta2_url"')
            ->assertSee('Khi bấm nút “Liên hệ”')
            ->assertSee('Khi bấm nút “Gọi ngay”')
            ->assertDontSeeHtml('wire:model="blocks.0.props.cta_url"');
    });
});

it('opens the list of the seven kinds, the current one checked; the form kind is not offered until forms ship', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $c = Livewire::actingAs(actionEditor())->test(Editor::class)
            ->set('blocks', [['type' => 'cta', 'props' => ['button_label' => 'Báo giá', 'button_url_do' => ['k' => 'tel', 'phone' => '0915668383']]]])
            ->call('toggleButtonKinds', '0.props.button_url')
            ->assertSeeHtml('role="radiogroup" aria-label="Nút “Báo giá” làm gì khi bấm"')
            ->assertSeeHtml('aria-expanded="true"');

        foreach (['Mở trang trong website', 'Mở liên kết ngoài', 'Cuộn tới phần', 'Gọi điện', 'Nhắn Zalo', 'Gửi thư', 'Tải tệp'] as $kind) {
            $c->assertSee($kind);
        }
        $c->assertDontSee('Mở biểu mẫu');
        expect(substr_count($c->html(), 'role="radio"'))->toBe(7)
            ->and(substr_count($c->html(), 'aria-checked="true"'))->toBe(1);
    });
});

it('switches kind, writes the typed value and clears the older address', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        Livewire::actingAs(actionEditor())->test(Editor::class)
            ->set('blocks', [['type' => 'cta', 'props' => ['button_label' => 'A', 'button_url' => 'https://example.com']]])
            ->call('setButtonKind', '0.props.button_url', 'mail')
            ->assertSet('blocks.0.props.button_url_do', ['k' => 'mail'])
            ->assertSet('blocks.0.props.button_url', null)
            ->call('setButtonField', '0.props.button_url', 'email', ' lienhe@tencongty.vn ')
            ->call('setButtonField', '0.props.button_url', 'subject', 'Hỏi báo giá gia công')
            ->assertSet('blocks.0.props.button_url_do', ['k' => 'mail', 'email' => 'lienhe@tencongty.vn', 'subject' => 'Hỏi báo giá gia công'])
            // a field the kind does not have, a kind that does not exist, a path that is no button
            ->call('setButtonField', '0.props.button_url', 'url', 'https://x.vn')
            ->call('setButtonKind', '0.props.button_url', 'form')
            ->call('setButtonKind', '0.props.heading', 'url')
            ->assertSet('blocks.0.props.button_url_do', ['k' => 'mail', 'email' => 'lienhe@tencongty.vn', 'subject' => 'Hỏi báo giá gia công'])
            ->assertSet('blocks.0.props.heading_do', null);
    });
});

it('shows a problem only once the field is left, and an unsafe link at once', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $c = Livewire::actingAs(actionEditor())->test(Editor::class)
            ->set('blocks', [['type' => 'cta', 'props' => ['button_label' => 'A']]])
            ->call('setButtonKind', '0.props.button_url', 'url')
            ->assertDontSee('Hãy dán địa chỉ liên kết')
            ->call('touchButton', '0.props.button_url')
            ->assertSee('Hãy dán địa chỉ liên kết, ví dụ https://facebook.com/tencongty.')
            ->assertSeeHtml('aria-invalid="true"')
            ->call('setButtonField', '0.props.button_url', 'url', 'facebook')
            ->assertSee('Liên kết chưa đúng: cần bắt đầu bằng https://')
            ->call('setButtonField', '0.props.button_url', 'url', 'javascript:alert(1)')
            ->assertSee('không an toàn nên sẽ bị chặn')
            ->call('setButtonField', '0.props.button_url', 'url', 'https://facebook.com/tencongty')
            ->assertDontSeeHtml('role="alert"')
            ->assertSeeHtml('aria-checked="true"')
            ->call('toggleButtonBlank', '0.props.button_url')
            ->assertSet('blocks.0.props.button_url_do.blank', false);

        $c->call('setButtonKind', '0.props.button_url', 'tel')
            ->call('setButtonField', '0.props.button_url', 'phone', '0915')
            ->assertSee('Số điện thoại chưa đúng: cần 10 chữ số, bắt đầu bằng 0, ví dụ 0915 668 383.');
    });
});

it('sorts an older address into its kind with the note, and keeps it until changed', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $page = actionPage('Liên hệ', '/lien-he');

        $c = Livewire::actingAs(actionEditor())->test(Editor::class)
            ->set('blocks', [['type' => 'hero', 'props' => ['cta_label' => 'Liên hệ', 'cta_url' => '/lien-he', 'cta2_label' => 'Zalo', 'cta2_url' => 'https://zalo.me/0915668383']]])
            ->assertSee('Nút này có liên kết cũ “/lien-he” nên đã được tự xếp vào kiểu “Mở trang trong website”. Chưa đúng thì bấm “Đổi”.')
            ->assertSee('Nút này có liên kết cũ “https://zalo.me/0915668383” nên đã được tự xếp vào kiểu “Nhắn Zalo”.')
            ->assertSee('Liên hệ');

        // confirming the sorted kind keeps the address, now as the page it opens
        $c->call('setButtonKind', '0.props.cta_url', 'page')
            ->assertSet('blocks.0.props.cta_url_do', ['k' => 'page', 'page' => $page->ulid])
            ->assertSet('blocks.0.props.cta_url', null)
            ->assertDontSee('Nút này có liên kết cũ “/lien-he”');
    });
});

it('finds a page or post by name, ignoring marks, and takes only one of this site', function (): void {
    $foreign = app(Tenancy::class)->enter(2, fn (): string => (string) actionPage('Ở mục Liên hệ', '/lien-he')->ulid);

    app(Tenancy::class)->enter(1, function () use ($foreign): void {
        $page = actionPage('Liên hệ', '/lien-he');
        actionPage('Tin công ty', '/tin-cong-ty', 'post');

        Livewire::actingAs(actionEditor())->test(Editor::class)
            // The second button is set to a phone call: an unset page button opens its own search, which lists the posts too.
            ->set('blocks', [['type' => 'cta', 'props' => ['button_label' => 'A', 'button_url_do' => ['k' => 'page'], 'button2_label' => 'B', 'button2_url_do' => ['k' => 'tel']]]])
            ->assertSee('Tìm trang hoặc bài viết theo tên')
            ->set('buttonQueries.0-props-button_url', 'lien he')
            ->assertSee('Trang · /lien-he')
            ->assertDontSee('Ở mục Liên hệ')
            ->assertDontSee('Tin công ty')
            ->set('buttonQueries.0-props-button_url', 'không có')
            ->assertSee('Không thấy trang hay bài viết nào có tên “không có”. Thử gõ ngắn hơn, ví dụ “liên hệ”.')
            ->call('pickButtonPage', '0.props.button_url', $foreign)
            ->assertSet('blocks.0.props.button_url_do', ['k' => 'page'])
            ->call('pickButtonPage', '0.props.button_url', $page->ulid)
            ->assertSet('blocks.0.props.button_url_do', ['k' => 'page', 'page' => $page->ulid])
            ->assertSee('Chọn trang khác')
            ->call('searchButtonPage', '0.props.button_url')
            ->assertSee('Giữ trang đang chọn')
            ->assertSee('Địa chỉ trang tự cập nhật khi trang đổi tên, nút không bị hỏng.')
            ->call('keepButtonPage', '0.props.button_url')
            ->assertDontSee('Giữ trang đang chọn');
    });
});

it('says at once when the chosen page has gone', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $page = actionPage('Liên hệ', '/lien-he');
        $page->delete();

        Livewire::actingAs(actionEditor())->test(Editor::class)
            ->set('blocks', [['type' => 'cta', 'props' => ['button_label' => 'A', 'button_url_do' => ['k' => 'page', 'page' => $page->ulid]]]])
            ->assertSee('Trang không còn trong website')
            ->assertSee('không còn trong website (có thể đã bị xóa hoặc đổi địa chỉ). Hãy chọn trang khác.');
    });
});

it('lists the parts with an anchor, or says how to add one', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        Livewire::actingAs(actionEditor())->test(Editor::class)
            ->set('blocks', [['type' => 'cta', 'props' => ['button_label' => 'A', 'button_url_do' => ['k' => 'anchor']]]])
            ->assertSee('Trang này chưa có phần nào điền ô Neo liên kết.')
            ->set('blocks', [
                ['type' => 'cta', 'props' => ['button_label' => 'A', 'button_url_do' => ['k' => 'anchor']]],
                ['type' => 'faq', 'props' => ['heading' => 'Hỏi đáp', 'anchor_id' => 'hoi-dap']],
            ])
            ->assertSee('tên: hoi-dap')
            ->call('pickButtonAnchor', '0.props.button_url', 'hoi-dap')
            ->assertSet('blocks.0.props.button_url_do', ['k' => 'anchor', 'anchor' => 'hoi-dap'])
            ->call('pickButtonAnchor', '0.props.button_url', 'Không hợp lệ')
            ->assertSet('blocks.0.props.button_url_do', ['k' => 'anchor', 'anchor' => 'hoi-dap']);
    });
});

it("picks a document of this site's library in the file dialog, never another site's", function (): void {
    app(Tenancy::class)->enter(2, fn () => actionDoc('b/library/ho-so-bbbbbbbbbbbb.pdf', 'Ấn phẩm bên ấy'));

    app(Tenancy::class)->enter(1, function (): void {
        $doc = actionDoc('a/library/ho-so-aaaaaaaaaaaa.pdf');

        Livewire::actingAs(actionEditor())->test(Editor::class)
            ->set('blocks', [['type' => 'cta', 'props' => ['button_label' => 'Tải hồ sơ', 'button_url_do' => ['k' => 'file']]]])
            ->assertSee('Chưa chọn tệp. Khách bấm nút sẽ tải tệp này về máy.')
            ->call('openButtonFilePicker', '0.props.button_url')
            ->assertSet('showButtonFilePicker', true)
            ->assertSee('Chọn tệp cho khách tải')
            ->assertSee('Hồ sơ năng lực.pdf')
            ->assertSee('PDF · 2,4 MB')
            ->assertDontSee('Ấn phẩm bên ấy')
            ->call('pickButtonFile', 'b/library/ho-so-bbbbbbbbbbbb.pdf')
            ->assertSet('blocks.0.props.button_url_do', ['k' => 'file'])
            ->call('openButtonFilePicker', '0.props.button_url')
            ->call('pickButtonFile', $doc->storage_key)
            ->assertSet('blocks.0.props.button_url_do', ['k' => 'file', 'file' => $doc->storage_key])
            ->assertSet('showButtonFilePicker', false)
            ->assertSee('Đổi tệp')
            ->call('clearButtonFile', '0.props.button_url')
            ->assertSet('blocks.0.props.button_url_do', ['k' => 'file']);
    });
});

it('says how to fill an empty library, and opens no file dialog without the library permission', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        Livewire::actingAs(actionEditor())->test(Editor::class)
            ->set('blocks', [['type' => 'cta', 'props' => ['button_label' => 'A', 'button_url_do' => ['k' => 'file']]]])
            ->call('openButtonFilePicker', '0.props.button_url')
            ->assertSee('Thư viện chưa có tài liệu nào.')
            ->assertSee('Mở Thư viện ảnh & tệp');

        Livewire::actingAs(actionEditor(['entries.create', 'entries.edit.any']))->test(Editor::class)
            ->set('blocks', [['type' => 'cta', 'props' => ['button_label' => 'A', 'button_url_do' => ['k' => 'file']]]])
            ->assertDontSee('Chọn tệp trong thư viện')
            ->call('openButtonFilePicker', '0.props.button_url')
            ->assertForbidden();
    });
});

it('works on the buttons of list items too', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        Livewire::actingAs(actionEditor())->test(Editor::class)
            ->set('blocks', [['type' => 'quick_links', 'props' => ['items' => [['icon' => 'phone', 'label' => 'Gọi', 'url' => 'tel:0915668383']]]]])
            ->assertSee('Nút này có liên kết cũ “tel:0915668383” nên đã được tự xếp vào kiểu “Gọi điện”.')
            ->call('setButtonField', '0.props.items.0.url', 'phone', '0915 668 383')
            ->assertSet('blocks.0.props.items.0.url_do', ['k' => 'tel', 'phone' => '0915 668 383'])
            ->assertSet('blocks.0.props.items.0.url', null)
            ->call('setButtonKind', '0.props.items.5.url', 'tel')
            ->assertSet('blocks.0.props.items.5', null);
    });
});

it('saves the typed value with the page', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $page = actionPage('Liên hệ', '/lien-he');

        Livewire::actingAs(actionEditor())->test(Editor::class)
            ->set('title', 'Trang chủ')
            ->set('slug', 'trang-chu-moi')
            ->set('blocks', [['type' => 'cta', 'props' => ['heading' => 'Liên hệ', 'button_label' => 'Liên hệ', 'button_url' => '']]])
            ->call('setButtonKind', '0.props.button_url', 'page')
            ->call('pickButtonPage', '0.props.button_url', $page->ulid)
            ->call('save')
            ->assertHasNoErrors();

        $body = EntryTranslation::query()->where('slug', 'trang-chu-moi')->value('body');
        expect($body['blocks'][0]['props']['button_url_do'])->toBe(['k' => 'page', 'page' => $page->ulid]);
    });
});

it('refuses every button call to a member who may not edit the page', function (string $method, array $args): void {
    app(Tenancy::class)->enter(1, function () use ($method, $args): void {
        $page = actionPage('Ảnh hồ sơ', '/anh-ho-so');
        $c = Livewire::actingAs(actionEditor())->test(Editor::class, ['ulid' => $page->ulid])
            ->set('blocks', [['type' => 'cta', 'props' => ['button_label' => 'Ấn gọi cô Hà', 'button_url_do' => ['k' => 'tel', 'phone' => '0915668383']]]]);

        $viewer = User::factory()->create();
        grantRole($viewer, 'viewer', ['media.view']);
        $viewer = $viewer->fresh();
        Livewire::actingAs($viewer);
        $this->actingAs($viewer);

        $c->call($method, ...$args)->assertForbidden();
    });
})->with([
    'toggleButtonKinds' => ['toggleButtonKinds', ['0.props.button_url']],
    'setButtonKind' => ['setButtonKind', ['0.props.button_url', 'url']],
    'setButtonField' => ['setButtonField', ['0.props.button_url', 'phone', '0912345678']],
    'toggleButtonBlank' => ['toggleButtonBlank', ['0.props.button_url']],
    'touchButton' => ['touchButton', ['0.props.button_url']],
    'pickButtonPage' => ['pickButtonPage', ['0.props.button_url', '01J0000000000000000000000A']],
    'searchButtonPage' => ['searchButtonPage', ['0.props.button_url']],
    'keepButtonPage' => ['keepButtonPage', ['0.props.button_url']],
    'pickButtonAnchor' => ['pickButtonAnchor', ['0.props.button_url', 'hoi-dap']],
    'useSitePhone' => ['useSitePhone', ['0.props.button_url']],
    'openButtonFilePicker' => ['openButtonFilePicker', ['0.props.button_url']],
    'closeButtonFilePicker' => ['closeButtonFilePicker', []],
    'pickButtonFile' => ['pickButtonFile', ['a/library/ho-so-aaaaaaaaaaaa.pdf']],
    'clearButtonFile' => ['clearButtonFile', ['0.props.button_url']],
]);

it('says what is wrong with a Zalo number or link, an internal address or an email', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        Livewire::actingAs(actionEditor())->test(Editor::class)
            ->set('blocks', [['type' => 'cta', 'props' => ['button_label' => 'A']]])
            ->call('setButtonKind', '0.props.button_url', 'zalo')
            ->call('setButtonField', '0.props.button_url', 'phone', 'zalo.me/abc')
            ->assertSee('Liên kết Zalo chưa đúng: cần có dạng https://zalo.me/0915668383.')
            ->call('setButtonField', '0.props.button_url', 'phone', '0915')
            ->assertSee('Số Zalo chưa đúng: cần 10 chữ số, bắt đầu bằng 0, ví dụ 0915 668 383. Hoặc dán liên kết zalo.me của bạn.')
            ->call('setButtonKind', '0.props.button_url', 'url')
            ->call('setButtonField', '0.props.button_url', 'url', '/lien-he')
            ->assertSee('Đây là một trang trong website của bạn. Hãy chọn kiểu “Mở trang trong website” để nút không bị hỏng khi trang đổi địa chỉ.')
            ->call('setButtonKind', '0.props.button_url', 'mail')
            ->call('setButtonField', '0.props.button_url', 'email', 'lienhe@')
            ->assertSee('Email chưa đúng: cần có dạng ten@tencongty.vn.')
            ->call('setButtonField', '0.props.button_url', 'email', 'x?cc=evil@b.com')
            ->assertSee('Email chưa đúng: cần có dạng ten@tencongty.vn.');
    });
});

it("takes the site's number for a phone button, and says when it is in use", function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'brand'], ['value' => ['name' => 'Ấn phẩm mẫu số', 'phone' => '0915668383']]);

        Livewire::actingAs(actionEditor())->test(Editor::class)
            ->set('blocks', [['type' => 'cta', 'props' => ['button_label' => 'Ấn gọi cô Hà', 'button_url_do' => ['k' => 'tel']]]])
            ->assertSee('Lấy số của website: 0915 668 383')
            ->assertDontSee('Đang dùng số của website')
            ->call('useSitePhone', '0.props.button_url')
            ->assertSet('blocks.0.props.button_url_do', ['k' => 'tel', 'phone' => '0915668383'])
            ->assertSee('Đang dùng số của website (Cài đặt › Thông tin chung).');
    });
});

it('says at once when the chosen file or part has gone', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $doc = actionDoc('a/library/ho-so-aaaaaaaaaaaa.pdf');
        $doc->delete();

        Livewire::actingAs(actionEditor())->test(Editor::class)
            ->set('blocks', [
                ['type' => 'cta', 'props' => ['button_label' => 'Ấn tải hồ sơ', 'button_url_do' => ['k' => 'file', 'file' => $doc->storage_key]]],
                ['type' => 'cta', 'props' => ['button_label' => 'Đọc câu hỏi đáp nhé', 'button_url_do' => ['k' => 'anchor', 'anchor' => 'hoi-dap']]],
            ])
            ->assertSee('Tệp đã chọn không còn trong thư viện. Hãy chọn tệp khác.')
            ->assertSee('Phần có Neo liên kết “hoi-dap” không còn trên trang này (Neo liên kết đã đổi hoặc phần đã bị xóa). Hãy chọn lại một phần khác.');
    });
});

it('opens the editor on the same number of queries however many buttons of each kind the page holds', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $user = actionEditor();
        $pages = [actionPage('Ấn liên hệ', '/lien-he'), actionPage('Ảnh hồ sơ', '/anh-ho-so')];
        $docs = [actionDoc('a/library/ho-so-aaaaaaaaaaaa.pdf'), actionDoc('a/library/ho-so-cccccccccccc.pdf')];
        SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'brand'], ['value' => ['name' => 'Ấn phẩm mẫu số', 'phone' => '0915668383']]);

        $edited = static function (int $copies) use ($pages, $docs): Entry {
            $blocks = [['type' => 'faq', 'props' => ['heading' => 'Ừ hỏi đáp đó', 'anchor_id' => 'hoi-dap']]];
            for ($i = 0; $i < $copies; $i++) {
                $page = $pages[$i % 2];
                $doc = $docs[$i % 2];
                foreach ([
                    ['k' => 'page', 'page' => $page->ulid], ['k' => 'page'], ['k' => 'url', 'url' => 'https://facebook.com/tencongty'],
                    ['k' => 'anchor', 'anchor' => 'hoi-dap'], ['k' => 'tel', 'phone' => '0915668383'], ['k' => 'zalo', 'phone' => '0915668383'],
                    ['k' => 'mail', 'email' => 'lienhe@tencongty.vn'], ['k' => 'file', 'file' => $doc->storage_key],
                ] as $do) {
                    $blocks[] = ['type' => 'cta', 'props' => ['button_label' => 'Ấn thử nhé', 'button_url_do' => $do]];
                }
                $blocks[] = ['type' => 'hero', 'props' => ['cta_label' => 'Ấn liên hệ', 'cta_url' => $i % 2 === 0 ? '/lien-he' : '/anh-ho-so']];
            }
            $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
            $entry->translations()->create(['locale' => 'vi', 'title' => 'Ảnh trang chủ', 'slug' => 'trang-chu-'.$copies, 'status' => 'draft', 'body' => ['blocks' => $blocks]]);

            return $entry;
        };
        $none = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
        $none->translations()->create(['locale' => 'vi', 'title' => 'Ảnh để trống ở đó', 'slug' => 'trang-trong', 'status' => 'draft', 'body' => ['blocks' => [['type' => 'faq', 'props' => ['heading' => 'Ừ hỏi đáp đó']]]]]);
        $one = $edited(1);
        $two = $edited(2);

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $none->ulid]);   // warm up
        $open = static fn (Entry $entry): int => countQueries(fn () => Livewire::actingAs($user)->test(Editor::class, ['ulid' => $entry->ulid]));
        $base = $open($none);
        $withOne = $open($one);
        $withTwo = $open($two);

        expect($withTwo)->toBe($withOne)
            ->and($withOne - $base)->toBeLessThanOrEqual(BUTTON_ACTIONS_OPEN_EXTRA);
    });
});

it('searches pages only when the search text changes, not on every redraw', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        actionPage('Ấn liên hệ', '/lien-he');
        $c = Livewire::actingAs(actionEditor())->test(Editor::class)
            ->set('blocks', [['type' => 'cta', 'props' => ['button_label' => 'Ấn thử nhé', 'button_url_do' => ['k' => 'page']]]]);

        $searches = static function (Closure $redraw): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $redraw();
            $n = count(array_filter(DB::getQueryLog(), static fn (array $q): bool => str_contains($q['query'], "entries.type = 'page' desc")));
            DB::disableQueryLog();

            return $n;
        };

        expect($searches(fn () => $c->set('title', 'Ảnh trang chủ')))->toBe(0)
            ->and($searches(fn () => $c->call('touchButton', '0.props.button_url')))->toBe(0)
            ->and($searches(fn () => $c->set('buttonQueries.0-props-button_url', 'liên hệ')))->toBe(1)
            ->and($c->html())->toContain('Trang · /lien-he');
    });
});

/** Opening the editor with buttons of every kind: pages by ulid and by older address, files, one search, the site's phone. */
const BUTTON_ACTIONS_OPEN_EXTRA = 5;

it('keeps the search of one button apart from the second button of the same block', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        actionPage('Liên hệ', '/lien-he');

        $c = Livewire::actingAs(actionEditor())->test(Editor::class)
            ->set('blocks', [['type' => 'cta', 'props' => [
                'button_label' => 'A', 'button_url_do' => ['k' => 'page'],
                'button2_label' => 'B', 'button2_url_do' => ['k' => 'page'],
            ]]])
            ->set('buttonQueries.0-props-button_url', 'lien he')
            ->assertSee('Trang · /lien-he');

        expect($c->get('buttonQueries'))->toHaveKey('0-props-button_url')->not->toHaveKey('0-props-button2_url');
    });
});
