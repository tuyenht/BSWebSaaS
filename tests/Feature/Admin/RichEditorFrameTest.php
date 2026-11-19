<?php

use App\Modules\Admin\Livewire\Catalog\Editor as ProductEditor;
use App\Modules\Admin\Livewire\Entries\Editor as EntryEditor;
use App\Modules\Admin\Support\Locales;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Shared\Support\RichTextSanitizer;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use Livewire\Livewire;

/*
 * The shared writing frame (<x-bs-admin::rich-editor>): one formatting bar with the everyday
 * buttons in a fixed order and the rest behind «Thêm công cụ» (hidden until opened), the footer
 * counts, the link and special-character dialogs, the hint shown while the body is empty, and the
 * product's «Chèn bảng thông số» button. What the bar's tools write must survive the save.
 */

beforeEach(function (): void {
    app()->setLocale('vi'); // the words checked below are the Vietnamese ones
});

function rteFrame(string $placeholder = ''): string
{
    return (string) test()->blade(
        '<x-bs-admin::rich-editor target="body" value="<p>Xin chào</p>" :placeholder="$p" aria-label="Nội dung" />',
        ['p' => $placeholder !== '' ? $placeholder : null],
    );
}

it('draws the everyday buttons in order, then the toggle', function (): void {
    $html = rteFrame();

    expect($html)->toContain('role="toolbar" aria-label="Định dạng nội dung"');
    test()->blade('<x-bs-admin::rich-editor target="body" />')->assertSeeInOrder([
        'Kiểu', 'Đậm (Ctrl+B)', 'Nghiêng (Ctrl+I)', 'Danh sách gạch đầu dòng', 'Danh sách đánh số',
        'Chèn liên kết (Ctrl+K)', 'Chèn ảnh', 'Chèn video', 'Hoàn tác (Ctrl+Z)', 'Làm lại (Ctrl+Y)',
        'Gạch chân (Ctrl+U)', 'Gạch ngang', 'Chỉ số trên', 'Chỉ số dưới', 'Cỡ chữ', 'Màu chữ và màu nền',
        'Căn trái', 'Căn giữa', 'Căn phải', 'Căn đều 2 bên', 'Giảm thụt lề', 'Tăng thụt lề',
        'Bỏ liên kết', 'Xóa định dạng', 'Chèn bảng', 'Đường kẻ ngang', 'Ký tự đặc biệt', 'Xem / sửa HTML', 'Toàn màn hình',
        'Thêm công cụ',
    ], false);
});

it('keeps the extra tools hidden until «Thêm công cụ» opens them', function (): void {
    $html = rteFrame();

    preg_match_all('/<span class="rte-g" data-rte-more role="group" aria-label="[^"]+" hidden>/', $html, $groups);

    expect($groups[0])->toHaveCount(7)
        ->and($html)->toContain('data-rte-toggle aria-expanded="false" title="Thêm công cụ — gạch chân, cỡ chữ, màu, căn lề, bảng, HTML…"')
        // the everyday groups are never hidden
        ->and(substr_count($html, '<span class="rte-g" role="group"'))->toBe(6);
});

it('offers the style list without the page title level, and five sizes', function (): void {
    $html = rteFrame();

    foreach (['p', 'h2', 'h3', 'h4', 'h5', 'blockquote'] as $tag) {
        expect($html)->toContain('data-rte-block="'.$tag.'"');
    }
    expect($html)->not->toContain('data-rte-block="h1"')
        ->and(substr_count($html, 'data-rte-size="'))->toBe(5)
        ->and($html)->toContain('Bình thường · 16px')
        // ten fixed tones, then ten hues at three lightnesses
        ->and(substr_count($html, 'data-rte-color="#'))->toBe(40)
        ->and($html)->toContain('data-rte-color="#ffffff"');
});

it('shows the counts, the dialogs and the bridges to the media library', function (): void {
    $html = rteFrame();

    expect($html)->toContain('Ký tự: <strong data-rte-n="chars">')
        ->toContain('Từ: <strong data-rte-n="words">')
        ->toContain('Đọc: <strong data-rte-n="read">1 phút</strong>')
        ->toContain('<dialog class="rte-dlg" data-rte-link')
        ->toContain('data-rte-link-ok aria-disabled="true"')
        ->toContain('<dialog class="rte-dlg rte-dlg-ch" data-rte-chars')
        ->toContain('data-rte-char="₫"')
        ->toContain('<dialog class="rte-sheet" data-rte-sheet')
        ->toContain('data-rich-sync="body" wire:model="body"')
        ->toContain('wire:click="openImagePicker(\'editor\')"')
        ->toContain('wire:click="openVideoDialog"')
        ->toContain('aria-label="Nội dung"');
});

it('passes the hint to the writing area only when there is one', function (): void {
    expect(rteFrame('Nhập nội dung trang…'))->toContain('data-rich-placeholder="Nhập nội dung trang…"')
        ->and(rteFrame())->not->toContain('data-rich-placeholder');
});

it('keeps what the bar writes through the save (sizes, colours, headings, strike, alignment)', function (): void {
    $out = app(RichTextSanitizer::class)->full(
        '<h5>Nhỏ</h5><blockquote>Trích</blockquote><p style="text-align:center">'
        .'<span style="font-size:20px">to</span> <span style="color:#3577f1;background-color:#ffd8a8">màu</span> '
        .'<s>bỏ</s> <u>gạch</u> m<sup>2</sup> H<sub>2</sub>O</p>'
        // a colour and a size picked on bold text: the engine writes them on <strong> (seen in the
        // browser) and the frame moves them onto a <span> inside before sending (moveInlineStyles)
        .'<p><strong><span style="color: rgb(240, 101, 72); font-size: 20px;">gá</span></strong></p>'
    );

    expect($out)->toContain('<h5>Nhỏ</h5>')
        ->toContain('<blockquote>')
        ->toContain('text-align:center')
        ->toContain('font-size:20px')
        ->toContain('color:#3577f1')
        ->toContain('background-color:#ffd8a8')
        ->toContain('<s>bỏ</s>')
        ->toContain('<u>gạch</u>')
        ->toContain('<sup>2</sup>')
        ->toContain('<sub>2</sub>')
        ->toMatch('/<strong><span style="color:\s*rgb\(240, ?101, ?72\);\s*font-size:\s*20px;?">gá<\/span><\/strong>/');
});

describe('in the product editor', function (): void {
    beforeEach(function (): void {
        truncateRbac();
        seedTenancy();
        enableCatalog();
        $this->artisan('rbac:sync')->assertSuccessful();
        app(TeamContext::class)->forget();
        $this->manager = User::factory()->create();
        app(Tenancy::class)->enter(1, fn () => grantRole($this->manager, 'cat-manager', ['catalog.view', 'catalog.manage']));
    });

    it('offers «Chèn bảng thông số» that appends the spec table to the description, and the hint', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $c = Livewire::actingAs($this->manager->fresh())->test(ProductEditor::class)
                ->assertSeeHtml('data-rich-append="description"')
                ->assertSee('Chèn bảng thông số')
                ->assertSeeHtml('data-rich-toast="Đã chèn bảng thông số ở cuối mô tả — điền giá trị vào từng ô."')
                ->assertSeeHtml('data-rich-placeholder="Viết công dụng, ưu điểm và cách dùng. Bấm “Chèn bảng thông số” để thêm bảng kích thước, vật liệu."');

            preg_match('/data-rich-html="([^"]+)"/', $c->html(), $m);
            $spec = html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5);

            expect($spec)->toStartWith('<h3>Thông số kỹ thuật</h3><table><tbody><tr><th>Thông số</th><th>Giá trị</th></tr>')
                ->toContain('<tr><td>Kích thước</td><td>&nbsp;</td></tr>')
                ->toContain('<tr><td>Bảo hành</td><td>&nbsp;</td></tr>')
                ->toEndWith('</tbody></table><p></p>')
                ->and(substr_count($spec, '<tr><td>'))->toBe(5);

            // The table the button writes survives the save.
            $kept = app(RichTextSanitizer::class)->full($spec);
            expect($kept)->toContain('<h3>Thông số kỹ thuật</h3>')
                ->toContain('<th>Thông số</th>')
                ->toContain('<td>Xuất xứ</td>');
        });
    });
});

describe('in the post and page editor', function (): void {
    beforeEach(function (): void {
        truncateRbac();
        seedTenancy();
        $this->artisan('rbac:sync')->assertSuccessful();
        app(TeamContext::class)->forget();
        $this->writer = User::factory()->create();
        app(Tenancy::class)->enter(1, fn () => grantRole($this->writer, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'media.view']));
    });

    it('hints «Nhập nội dung trang…» in the site language and says another language is still empty', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            SiteLocale::query()->firstOrCreate(['locale' => 'vi'], ['is_default' => true, 'enabled' => true]);
            SiteLocale::query()->firstOrCreate(['locale' => 'en'], ['is_default' => false, 'enabled' => true]);
            $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $this->writer->id]);
            $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'draft', 'body' => ['blocks' => []]]);
            $entry->translations()->create(['locale' => 'en', 'title' => 'About', 'slug' => 'about', 'status' => 'draft', 'body' => ['blocks' => []]]);

            Livewire::actingAs($this->writer->fresh())->test(EntryEditor::class, ['ulid' => $entry->ulid])
                ->assertSeeHtml('data-rich-placeholder="Nhập nội dung trang…"')
                ->assertSeeHtml('data-rte="bodyHtml"');

            Livewire::actingAs($this->writer->fresh())->test(EntryEditor::class, ['ulid' => $entry->ulid, 'locale' => 'en'])
                ->assertSeeHtml('data-rich-placeholder="Bản '.e(Locales::label('en')).' chưa có nội dung — dịch từ bản gốc hoặc nhập trực tiếp."');
        });
    });
});
