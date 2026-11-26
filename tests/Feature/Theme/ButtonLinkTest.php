<?php

use App\Modules\Admin\Support\MediaUsageLocator;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Support\EntryBlockMediaReconciler;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Components\ButtonLink;
use App\Modules\Theme\Rendering\BlockRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/*
 * What a block button does when pressed: one typed value per button (`<prop>_do`) of kind page,
 * url, anchor, tel, zalo, mail or file. It is checked when the page is saved, resolved once when the
 * page is drawn (a page through its current route, a file through this site's library), and a value
 * that does not resolve draws no link at all: never "#", never the raw text. An older plain address
 * in the prop itself keeps working.
 */

beforeEach(fn () => seedTenancy());

/**
 * @param  array<string, mixed>  $props
 * @return list<string>
 */
function buttonErrors(array $props, string $type = 'cta'): array
{
    app()->setLocale('vi');

    return array_values(Validator::make(['blocks' => [['type' => $type, 'props' => $props]]], ['blocks' => [app(ValidPageJson::class)]])->errors()->get('blocks'));
}

/** @param  array<string, mixed>  $props */
function buttonHtml(array $props, string $type = 'cta'): string
{
    app()->setLocale('vi');

    return app(BlockRenderer::class)->render(['blocks' => [['type' => $type, 'props' => $props]]]);
}

function buttonPage(string $slug, string $path, string $status = 'published'): Entry
{
    $entry = Entry::query()->create(['type' => 'page', 'status' => $status, 'published_at' => now()]);
    $entry->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ', 'slug' => $slug, 'status' => $status, 'body' => ['blocks' => []]]);
    SiteRoute::query()->create(['locale' => 'vi', 'path' => $path, 'entry_id' => $entry->id]);

    return $entry;
}

function buttonDocument(string $key, string $mime = 'application/pdf'): Media
{
    return Media::query()->create([
        'collection' => 'library', 'slug' => 'ho-so', 'hash' => hash('sha256', $key),
        'storage_key' => $key, 'disk' => 'r2', 'mime' => $mime, 'size' => 2_400_000, 'original_filename' => 'Hồ sơ năng lực.pdf',
    ]);
}

describe('saving', function (): void {
    it('accepts a well formed value of every kind, and a blank one still being filled in', function (array $do): void {
        expect(buttonErrors(['button_label' => 'Liên hệ', 'button_url_do' => $do]))->toBe([]);
    })->with([
        'url' => [['k' => 'url', 'url' => 'https://facebook.com/tencongty', 'blank' => true]],
        'url, same tab' => [['k' => 'url', 'url' => 'https://zalo.me/g/abc', 'blank' => false]],
        'anchor' => [['k' => 'anchor', 'anchor' => 'bao-gia']],
        'tel' => [['k' => 'tel', 'phone' => '0915 668 383']],
        'zalo number' => [['k' => 'zalo', 'phone' => '0915668383']],
        'zalo link' => [['k' => 'zalo', 'phone' => 'https://zalo.me/0915668383']],
        'mail with subject' => [['k' => 'mail', 'email' => 'lienhe@tencongty.vn', 'subject' => 'Hỏi báo giá gia công']],
        'blank page' => [['k' => 'page']],
        'blank url' => [['k' => 'url', 'url' => '']],
        'blank file' => [['k' => 'file']],
    ]);

    it('refuses an unsafe or malformed value', function (array $do): void {
        $errors = buttonErrors(['button_label' => 'Liên hệ', 'button_url_do' => $do]);

        expect($errors)->toHaveCount(1)->and($errors[0])->toContain('cta')->not->toContain('entries.');
    })->with([
        'javascript:' => [['k' => 'url', 'url' => 'javascript:alert(1)']],
        'data:' => [['k' => 'url', 'url' => 'data:text/html,<b>x</b>']],
        'vbscript:' => [['k' => 'url', 'url' => 'vbscript:msgbox(1)']],
        'protocol relative //' => [['k' => 'url', 'url' => '//evil.example']],
        'backslash /\\' => [['k' => 'url', 'url' => '/\\evil.example']],
        'a site path as url' => [['k' => 'url', 'url' => '/lien-he']],
        'bad anchor' => [['k' => 'anchor', 'anchor' => 'Báo giá!']],
        'html in a phone' => [['k' => 'tel', 'phone' => '<b>0915668383</b>']],
        'html in a zalo' => [['k' => 'zalo', 'phone' => '<script>1</script>']],
        'bad email' => [['k' => 'mail', 'email' => 'lienhe@']],
        'a header in the address, ?' => [['k' => 'mail', 'email' => 'x?cc=evil@b.com']],
        'a header in the address, &' => [['k' => 'mail', 'email' => 'a&cc=x@b.com']],
        'a header in the address, %' => [['k' => 'mail', 'email' => 'a%0D%0Abcc=x@b.com']],
        'control in subject' => [['k' => 'mail', 'email' => 'a@b.vn', 'subject' => "a\nBcc: x@y.z"]],
        'long subject' => [['k' => 'mail', 'email' => 'a@b.vn', 'subject' => str_repeat('a', ButtonLink::SUBJECT_MAX + 1)]],
        'bad page ulid' => [['k' => 'page', 'page' => 'not-a-ulid']],
        'file as an address' => [['k' => 'file', 'file' => 'https://evil.example/x.pdf']],
        'file row not a ulid' => [['k' => 'file', 'media' => 'a/library/ho-so-aaaaaaaaaaaa.pdf']],
        'file row and key both' => [['k' => 'file', 'media' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'file' => 'a/library/ho-so-aaaaaaaaaaaa.pdf']],
        'file row not a string' => [['k' => 'file', 'media' => 42]],
        'unknown kind' => [['k' => 'form', 'form' => 'lien-he']],
        'a field of another kind' => [['k' => 'tel', 'phone' => '0915668383', 'url' => 'https://x.vn']],
        'a resolved address smuggled in' => [['k' => 'page', 'page' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'href' => 'javascript:alert(1)']],
        'blank flag not a bool' => [['k' => 'url', 'url' => 'https://x.vn', 'blank' => 'yes']],
    ]);

    it('refuses an older plain address a browser reads as another host', function (string $legacy): void {
        expect(buttonErrors(['button_label' => 'Liên hệ', 'button_url' => $legacy]))->toHaveCount(1);
    })->with(['//evil.example', '/\\evil.example', ' //evil.example', "/\t/evil.example"]);

    it('checks the typed value in list items too (quick links, partners, pricing)', function (): void {
        expect(buttonErrors(['items' => [['icon' => 'phone', 'label' => 'Gọi', 'url_do' => ['k' => 'tel', 'phone' => '0915668383']]]], 'quick_links'))->toBe([])
            ->and(buttonErrors(['items' => [['icon' => 'phone', 'label' => 'Gọi', 'url_do' => ['k' => 'url', 'url' => 'javascript:alert(1)']]]], 'quick_links'))->toHaveCount(1)
            ->and(buttonErrors(['logos' => [['name' => 'A', 'url_do' => ['k' => 'url', 'url' => 'data:x']]]], 'partners'))->toHaveCount(1);
    });

    it("refuses another site's page or file, the same way as one that does not exist", function (): void {
        $foreignPage = app(Tenancy::class)->enter(2, fn (): string => (string) buttonPage('lien-he', '/lien-he')->ulid);
        app(Tenancy::class)->enter(2, fn () => buttonDocument('b/library/ho-so-bbbbbbbbbbbb.pdf'));

        app(Tenancy::class)->enter(1, function () use ($foreignPage): void {
            $mine = buttonPage('lien-he', '/lien-he');
            buttonDocument('a/library/ho-so-aaaaaaaaaaaa.pdf');

            expect(buttonErrors(['button_url_do' => ['k' => 'page', 'page' => $mine->ulid]]))->toBe([])
                ->and(buttonErrors(['button_url_do' => ['k' => 'file', 'file' => 'a/library/ho-so-aaaaaaaaaaaa.pdf']]))->toBe([]);

            $page = buttonErrors(['button_url_do' => ['k' => 'page', 'page' => $foreignPage]]);
            $unknown = buttonErrors(['button_url_do' => ['k' => 'page', 'page' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']]);
            expect($page)->toBe([__('entries.err_block_button_page_missing')])->and($unknown)->toBe($page)
                ->and(buttonErrors(['button_url_do' => ['k' => 'file', 'file' => 'b/library/ho-so-bbbbbbbbbbbb.pdf']]))->toBe([__('entries.err_block_button_file_missing')]);
        });
    });

    it('still saves a button whose page went to the trash (it draws no link until restored)', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $page = buttonPage('lien-he', '/lien-he');
            $page->delete();

            expect(buttonErrors(['button_url_do' => ['k' => 'page', 'page' => $page->ulid]]))->toBe([]);
        });
    });

    it("names a file by its row ulid: this site's document saves, also from the Trash; another site's, an image or an unknown one is refused", function (): void {
        $foreign = app(Tenancy::class)->enter(2, fn (): string => buttonDocument('b/library/ho-so-bbbbbbbbbbbb.pdf')->ulid);

        app(Tenancy::class)->enter(1, function () use ($foreign): void {
            $doc = buttonDocument('a/library/ho-so-aaaaaaaaaaaa.pdf');
            $trashed = buttonDocument('a/library/ho-so-dddddddddddd.pdf');
            $trashed->delete();
            $image = buttonDocument('a/library/anh-aaaaaaaaaaaa.png', 'image/png');
            app()->setLocale('vi');
            $missing = [__('entries.err_block_button_file_missing')];

            expect(buttonErrors(['button_url_do' => ['k' => 'file', 'media' => $doc->ulid]]))->toBe([])
                ->and(buttonErrors(['button_url_do' => ['k' => 'file', 'media' => strtolower($doc->ulid)]]))->toBe([])
                ->and(buttonErrors(['button_url_do' => ['k' => 'file', 'media' => $trashed->ulid]]))->toBe([])
                ->and(buttonErrors(['button_url_do' => ['k' => 'file', 'media' => $foreign]]))->toBe($missing)
                ->and(buttonErrors(['button_url_do' => ['k' => 'file', 'media' => $image->ulid]]))->toBe($missing)
                ->and(buttonErrors(['button_url_do' => ['k' => 'file', 'media' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']]))->toBe($missing)
                // one forged value among good ones of both shapes is still refused
                ->and(buttonErrors(['items' => [
                    ['icon' => 'link', 'label' => 'A', 'url_do' => ['k' => 'file', 'media' => $doc->ulid]],
                    ['icon' => 'link', 'label' => 'B', 'url_do' => ['k' => 'file', 'file' => $doc->storage_key]],
                    ['icon' => 'link', 'label' => 'C', 'url_do' => ['k' => 'file', 'media' => $foreign]],
                ]], 'quick_links'))->toBe($missing);
        });
    });

    it('checks every file button of a body, in both shapes, with one query', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $docs = [buttonDocument('a/library/ho-so-aaaaaaaaaaaa.pdf'), buttonDocument('a/library/ho-so-cccccccccccc.pdf')];
            $items = [];
            foreach (range(0, 9) as $i) {
                $doc = $docs[$i % 2];
                $items[] = ['icon' => 'link', 'label' => 'T'.$i, 'url_do' => $i < 5 ? ['k' => 'file', 'media' => $doc->ulid] : ['k' => 'file', 'file' => $doc->storage_key]];
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $errors = buttonErrors(['items' => $items], 'quick_links');
            $mediaQueries = count(array_filter(DB::getQueryLog(), static fn (array $q): bool => str_contains($q['query'], '"media"')));
            DB::disableQueryLog();

            expect($errors)->toBe([])->and($mediaQueries)->toBe(1);
        });
    });

    it('refuses an image picked as a file to download', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            buttonDocument('a/library/anh-aaaaaaaaaaaa.png', 'image/png');

            expect(buttonErrors(['button_url_do' => ['k' => 'file', 'file' => 'a/library/anh-aaaaaaaaaaaa.png']]))->toBe([__('entries.err_block_button_file_missing')]);
        });
    });

    it('words its refusals with Vietnamese marks at both ends', function (): void {
        app()->setLocale('vi');
        expect(__('entries.err_block_button_page_missing'))->toStartWith('Một')->toEndWith('nút.')
            ->and(__('entries.btn_kind_page'))->toBe('Mở trang trong website')
            ->and(__('entries.btn_err_url_unsafe'))->toStartWith('Liên kết')
            ->and(__('entries.btn_url_new_tab'))->toBe('Mở ở thẻ mới')
            ->and(__('entries.btn_err_file_gone'))->toStartWith('Tệp')->toEndWith('khác.');
    });
});

describe('drawing', function (): void {
    it('draws every direct kind as its link, with no address for a value that does not resolve', function (array $do, ?string $href): void {
        $html = buttonHtml(['heading' => 'Liên hệ', 'button_label' => 'Bấm đây', 'button_url_do' => $do]);

        if ($href === null) {
            expect($html)->not->toContain('cta__button')->not->toContain('href="#"');
        } else {
            expect($html)->toContain('href="'.$href.'"');
        }
    })->with([
        'url, new tab' => [['k' => 'url', 'url' => 'https://facebook.com/tencongty', 'blank' => true], 'https://facebook.com/tencongty'],
        'anchor' => [['k' => 'anchor', 'anchor' => 'bao-gia'], '#bao-gia'],
        'tel' => [['k' => 'tel', 'phone' => '0915 668 383'], 'tel:0915668383'],
        'zalo number' => [['k' => 'zalo', 'phone' => '0915 668 383'], 'https://zalo.me/0915668383'],
        'zalo link' => [['k' => 'zalo', 'phone' => 'zalo.me/0915668383'], 'https://zalo.me/0915668383'],
        'mail, subject encoded' => [['k' => 'mail', 'email' => 'lienhe@tencongty.vn', 'subject' => 'Hỏi giá & giao hàng'], 'mailto:lienhe@tencongty.vn?subject=H%E1%BB%8Fi%20gi%C3%A1%20%26%20giao%20h%C3%A0ng'],
        'blank url' => [['k' => 'url', 'url' => ''], null],
        'javascript stored anyway' => [['k' => 'url', 'url' => 'javascript:alert(1)'], null],
        'html phone stored anyway' => [['k' => 'tel', 'phone' => '"><script>1</script>'], null],
        'blank page' => [['k' => 'page'], null],
    ]);

    it('opens an outside link in a new tab only when asked, and says so to a screen reader', function (): void {
        $blank = buttonHtml(['button_label' => 'Facebook', 'button_url_do' => ['k' => 'url', 'url' => 'https://facebook.com/x', 'blank' => true]]);
        $same = buttonHtml(['button_label' => 'Facebook', 'button_url_do' => ['k' => 'url', 'url' => 'https://facebook.com/x', 'blank' => false]]);

        expect($blank)->toContain('target="_blank" rel="noopener"')->toContain(__('storefront.new_tab'))
            ->and($same)->not->toContain('target="_blank"');
    });

    it('still draws an older plain address', function (string $legacy, string $href): void {
        expect(buttonHtml(['button_label' => 'Liên hệ', 'button_url' => $legacy]))->toContain('href="'.$href.'"');
    })->with([
        'site path' => ['/lien-he', '/lien-he'],
        'web' => ['https://example.com/a', 'https://example.com/a'],
        'anchor' => ['#bao-gia', '#bao-gia'],
        'tel' => ['tel:0915668383', 'tel:0915668383'],
        'mailto' => ['mailto:a@b.vn', 'mailto:a@b.vn'],
    ]);

    it('draws no link, not "#", for a button with no address', function (): void {
        $html = buttonHtml(['heading' => 'Xin chào', 'cta_label' => 'Xem thêm'], 'hero');

        expect($html)->not->toContain('hero__cta')->not->toContain('href="#"');
    });

    it('follows a page to its new address, and draws no link once it is in the trash or deleted', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $page = buttonPage('lien-he', '/lien-he');
            $props = ['heading' => 'Xin chào', 'cta_label' => 'Liên hệ', 'cta_url_do' => ['k' => 'page', 'page' => $page->ulid]];

            expect(buttonHtml($props, 'hero'))->toContain('href="/lien-he"');

            SiteRoute::query()->where('entry_id', $page->id)->update(['path' => '/lien-he-ngay']);
            expect(buttonHtml($props, 'hero'))->toContain('href="/lien-he-ngay"')->not->toContain('href="/lien-he"');

            $page->delete();
            expect(buttonHtml($props, 'hero'))->not->toContain('hero__cta')->not->toContain('href="#"');

            $page->forceDelete();
            expect(buttonHtml($props, 'hero'))->not->toContain('hero__cta');
        });
    });

    it("draws no link to a draft page or another site's page", function (): void {
        $foreign = app(Tenancy::class)->enter(2, fn (): string => (string) buttonPage('lien-he', '/lien-he')->ulid);

        app(Tenancy::class)->enter(1, function () use ($foreign): void {
            $draft = buttonPage('nhap', '/nhap', 'draft');

            expect(buttonHtml(['cta_label' => 'A', 'cta_url_do' => ['k' => 'page', 'page' => $draft->ulid]], 'hero'))->not->toContain('hero__cta')
                ->and(buttonHtml(['cta_label' => 'A', 'cta_url_do' => ['k' => 'page', 'page' => $foreign]], 'hero'))->not->toContain('hero__cta');
        });
    });

    it("draws a download for this site's document, and nothing for another site's", function (): void {
        app(Tenancy::class)->enter(2, fn () => buttonDocument('b/library/ho-so-bbbbbbbbbbbb.pdf'));

        app(Tenancy::class)->enter(1, function (): void {
            buttonDocument('a/library/ho-so-aaaaaaaaaaaa.pdf');

            $mine = buttonHtml(['button_label' => 'Tải hồ sơ', 'button_url_do' => ['k' => 'file', 'file' => 'a/library/ho-so-aaaaaaaaaaaa.pdf']]);
            expect($mine)->toMatch('~href="[^"]+/a/library/ho-so-aaaaaaaaaaaa\.pdf" download=""~');

            $foreign = buttonHtml(['button_label' => 'Tải hồ sơ', 'button_url_do' => ['k' => 'file', 'file' => 'b/library/ho-so-bbbbbbbbbbbb.pdf']]);
            expect($foreign)->not->toContain('cta__button')->not->toContain('ho-so-bbbbbbbbbbbb');
        });
    });

    it('draws the same download for a file named by its row as for the older key, and nothing for a trashed or foreign row', function (): void {
        $foreign = app(Tenancy::class)->enter(2, fn (): string => buttonDocument('b/library/ho-so-bbbbbbbbbbbb.pdf')->ulid);

        app(Tenancy::class)->enter(1, function () use ($foreign): void {
            $doc = buttonDocument('a/library/ho-so-aaaaaaaaaaaa.pdf');
            $trashed = buttonDocument('a/library/ho-so-dddddddddddd.pdf');
            $trashed->delete();
            $image = buttonDocument('a/library/anh-aaaaaaaaaaaa.png', 'image/png');

            $old = buttonHtml(['button_label' => 'Tải hồ sơ', 'button_url_do' => ['k' => 'file', 'file' => $doc->storage_key]]);
            $new = buttonHtml(['button_label' => 'Tải hồ sơ', 'button_url_do' => ['k' => 'file', 'media' => $doc->ulid]]);
            expect($new)->toBe($old)->toMatch('~href="[^"]+/a/library/ho-so-aaaaaaaaaaaa\.pdf" download=""~')
                ->and(buttonHtml(['button_label' => 'Tải hồ sơ', 'button_url_do' => ['k' => 'file', 'media' => strtolower($doc->ulid)]]))->toBe($old);

            foreach ([$trashed->ulid, $foreign, $image->ulid, '01HZZZZZZZZZZZZZZZZZZZZZZZ'] as $ulid) {
                expect(buttonHtml(['button_label' => 'Tải hồ sơ', 'button_url_do' => ['k' => 'file', 'media' => $ulid]]))->not->toContain('cta__button')->not->toContain('download');
            }
        });
    });

    it('resolves a page of ten file buttons, old and new shapes mixed, with one file query', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $docs = [buttonDocument('a/library/ho-so-aaaaaaaaaaaa.pdf'), buttonDocument('a/library/ho-so-cccccccccccc.pdf')];
            $page = static function (string $shape) use ($docs): array {
                $blocks = [];
                foreach (range(0, 9) as $i) {
                    $doc = $docs[$i % 2];
                    $byRow = $shape === 'new' || ($shape === 'mixed' && $i % 3 === 0);
                    $blocks[] = ['type' => 'cta', 'props' => ['button_label' => 'T'.$i, 'button_url_do' => $byRow ? ['k' => 'file', 'media' => $doc->ulid] : ['k' => 'file', 'file' => $doc->storage_key]]];
                }

                return $blocks;
            };
            $render = static function (array $blocks): array {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $html = app(BlockRenderer::class)->render(['blocks' => $blocks]);
                $n = count(DB::getQueryLog());
                DB::disableQueryLog();

                return [$html, $n];
            };

            [$old, $oldCount] = $render($page('old'));
            [$new, $newCount] = $render($page('new'));
            [$mixed, $mixedCount] = $render($page('mixed'));

            expect($oldCount)->toBe(1)->and($newCount)->toBe(1)->and($mixedCount)->toBe(1)
                ->and($new)->toBe($old)->and($mixed)->toBe($old)
                ->and(substr_count($old, 'download=""'))->toBe(10);
        });
    });

    it('resolves the buttons of list items (quick links) the same way', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $page = buttonPage('lien-he', '/lien-he');
            $html = buttonHtml(['items' => [
                ['icon' => 'phone', 'label' => 'Gọi', 'url_do' => ['k' => 'tel', 'phone' => '0915668383']],
                ['icon' => 'link', 'label' => 'Liên hệ', 'url_do' => ['k' => 'page', 'page' => $page->ulid]],
                ['icon' => 'link', 'label' => 'Hỏng', 'url_do' => ['k' => 'url', 'url' => 'javascript:1']],
            ]], 'quick_links');

            expect($html)->toContain('href="tel:0915668383"')->toContain('href="/lien-he"')->not->toContain('Hỏng');
        });
    });

    it('looks pages and files up once for the whole page, and not at all when no button names one', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $a = buttonPage('a', '/a');
            $b = buttonPage('b', '/b');
            buttonDocument('a/library/ho-so-aaaaaaaaaaaa.pdf');
            $blocks = [];
            foreach ([$a, $b, $a] as $page) {
                $blocks[] = ['type' => 'cta', 'props' => ['button_label' => 'X', 'button_url_do' => ['k' => 'page', 'page' => $page->ulid]]];
                $blocks[] = ['type' => 'cta', 'props' => ['button_label' => 'Y', 'button_url_do' => ['k' => 'file', 'file' => 'a/library/ho-so-aaaaaaaaaaaa.pdf']]];
            }

            $count = static function (array $blocks): int {
                DB::flushQueryLog();
                DB::enableQueryLog();
                app(BlockRenderer::class)->render(['blocks' => $blocks]);
                $n = count(DB::getQueryLog());
                DB::disableQueryLog();

                return $n;
            };

            $direct = $count([['type' => 'cta', 'props' => ['button_label' => 'X', 'button_url_do' => ['k' => 'tel', 'phone' => '0915668383']]]]);
            $many = $count($blocks);
            expect($direct)->toBe(0)->and($many)->toBeLessThanOrEqual(2);
        });
    });
});

it("counts a button's file as in use in the library, named by its row or by the older key", function (string $shape): void {
    $foreign = app(Tenancy::class)->enter(2, fn (): string => buttonDocument('b/library/ho-so-bbbbbbbbbbbb.pdf')->ulid);

    app(Tenancy::class)->enter(1, function () use ($shape, $foreign): void {
        $doc = buttonDocument('a/library/ho-so-aaaaaaaaaaaa.pdf');
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'T', 'slug' => 't', 'status' => 'draft',
            'body' => ['blocks' => [['type' => 'quick_links', 'props' => ['items' => [
                ['icon' => 'link', 'label' => 'Tải', 'url_do' => $shape === 'row' ? ['k' => 'file', 'media' => strtolower($doc->ulid)] : ['k' => 'file', 'file' => $doc->storage_key]],
                // another site's row named in the body is never recorded
                ['icon' => 'link', 'label' => 'Lạ', 'url_do' => ['k' => 'file', 'media' => $foreign]],
            ]]]]]]);

        app(EntryBlockMediaReconciler::class)->reconcile($entry);

        expect(Mediable::query()->where('mediable_id', $entry->id)->where('collection', 'content')->pluck('media_id')->all())->toBe([$doc->id]);
        expect(app(MediaUsageLocator::class)->usagesFor($doc, 'vi'))->toHaveCount(1)
            ->and(app(MediaUsageLocator::class)->usagesFor($doc, 'vi')[0]['role'])->toBe('content');
    });
})->with(['row', 'key']);

describe('older plain addresses and mail headers', function (): void {
    it('never lets a mail address carry extra header fields, typed or older, saved or drawn', function (): void {
        foreach (['x?cc=evil@b.com', 'a&cc=x@b.com', 'a%0D%0Abcc=x@b.com', "a\r\nbcc=x@b.com"] as $email) {
            expect(ButtonLink::mail($email, null))->toBeNull()
                ->and(buttonHtml(['button_label' => 'Ấn gửi thư', 'button_url_do' => ['k' => 'mail', 'email' => $email]]))->not->toContain('mailto:');
        }

        foreach (['mailto:x%3Fbcc%3Devil@b.com', 'mailto:x%26cc%3Devil@b.com', 'mailto:a%250D%250Abcc=x@b.com'] as $legacy) {
            expect(buttonErrors(['button_label' => 'Ấn gửi thư', 'button_url' => $legacy]))->not->toBe([])
                ->and(buttonHtml(['button_label' => 'Ấn gửi thư', 'button_url' => $legacy]))->not->toContain('mailto:');
        }

        // an older link keeps its address and its subject (encoded), never a cc or bcc it carried
        $html = buttonHtml(['button_label' => 'Ấn gửi thư', 'button_url' => 'mailto:a@b.vn?subject=Hỏi giá&cc=evil@x.com&bcc=evil@x.com']);
        expect($html)->toContain('href="mailto:a@b.vn?subject=H%E1%BB%8Fi%20gi%C3%A1"')->not->toContain('evil@x.com');
    });

    it('still draws every older plain address the templates printed, in every button template', function (Closure $props, string $type, string $class, string $value): void {
        $html = buttonHtml($props($value), $type);

        expect($html)->toContain($class)->toContain('href="'.$value.'"');
    })->with([
        'hero' => [fn (string $v): array => ['heading' => 'Ảnh mẫu', 'cta_label' => 'Ấn liên hệ', 'cta_url' => $v], 'hero', 'hero__cta'],
        'hero, second button' => [fn (string $v): array => ['heading' => 'Ảnh mẫu', 'cta2_label' => 'Ấn liên hệ', 'cta2_url' => $v], 'hero', 'hero__cta--secondary'],
        'hero slider' => [fn (string $v): array => ['slides' => [['heading' => 'Ảnh mẫu']], 'cta_label' => 'Ấn liên hệ', 'cta_url' => $v], 'hero_slider', 'sf-slide__cta'],
        'about' => [fn (string $v): array => ['heading' => 'Ảnh mẫu', 'cta_label' => 'Ấn liên hệ', 'cta_url' => $v], 'about', 'about__cta'],
        'cta' => [fn (string $v): array => ['heading' => 'Ảnh mẫu', 'button_label' => 'Ấn liên hệ', 'button_url' => $v], 'cta', 'cta__button'],
        'cta, rounded look' => [fn (string $v): array => ['heading' => 'Ảnh mẫu', 'variant' => 'rounded', 'button_label' => 'Ấn liên hệ', 'button_url' => $v], 'cta', 'cta__button--pill'],
        'partners' => [fn (string $v): array => ['logos' => [['image_url' => 'https://cdn.example.com/logo.png', 'name' => 'Ấn phẩm hồ sơ', 'url' => $v]]], 'partners', 'partners__link'],
        'pricing' => [fn (string $v): array => ['plans' => [['name' => 'Ấn phẩm cơ sở', 'cta_label' => 'Ấn liên hệ', 'cta_url' => $v], ['name' => 'Ấn phẩm đầy đủ']]], 'pricing', 'sf-plan__cta'],
        'quick links' => [fn (string $v): array => ['items' => [['icon' => 'link', 'label' => 'Ấn liên hệ', 'url' => $v]]], 'quick_links', 'sf-qlinks__tile'],
    ])->with([
        'relative path' => 'lien-he',
        'site path' => '/lien-he',
        'www without a scheme' => 'www.example.com',
        'zalo.me without a scheme' => 'zalo.me/0915668383',
        'sms:' => 'sms:0915668383',
        'anchor with capitals' => '#Top',
        'bare #' => '#',
    ]);

    it('saves those older plain addresses unchanged', function (): void {
        foreach (['lien-he', '/lien-he', 'www.example.com', 'zalo.me/0915668383', 'sms:0915668383', '#Top', '#'] as $value) {
            expect(buttonErrors(['heading' => 'Ảnh mẫu', 'button_label' => 'Ấn liên hệ', 'button_url' => $value]))->toBe([]);
        }
    });

    it('draws no link for an older address that is unsafe, in every button template', function (string $value): void {
        foreach ([
            'hero' => ['heading' => 'Ảnh mẫu', 'cta_label' => 'Ấn liên hệ', 'cta_url' => $value],
            'hero_slider' => ['slides' => [['heading' => 'Ảnh mẫu']], 'cta_label' => 'Ấn liên hệ', 'cta_url' => $value],
            'about' => ['heading' => 'Ảnh mẫu', 'cta_label' => 'Ấn liên hệ', 'cta_url' => $value],
            'cta' => ['heading' => 'Ảnh mẫu', 'button_label' => 'Ấn liên hệ', 'button_url' => $value],
            'partners' => ['logos' => [['image_url' => 'https://cdn.example.com/logo.png', 'name' => 'Ấn phẩm hồ sơ', 'url' => $value]]],
            'pricing' => ['plans' => [['name' => 'Ấn phẩm cơ sở', 'cta_label' => 'Ấn liên hệ', 'cta_url' => $value], ['name' => 'Ấn phẩm đầy đủ']]],
            'quick_links' => ['items' => [['icon' => 'link', 'label' => 'Ấn liên hệ', 'url' => $value]]],
        ] as $type => $props) {
            expect(buttonHtml($props, $type))->not->toContain('evil.example')->not->toContain('href="#"');
        }
        expect(buttonHtml(['heading' => 'Ảnh mẫu', 'variant' => 'rounded', 'button_label' => 'Ấn liên hệ', 'button_url' => $value]))->not->toContain('evil.example');
    })->with([
        'protocol relative //' => '//evil.example/a',
        'backslash /\\' => '/\\evil.example/a',
        'two backslashes' => '\\\\evil.example/a',
        'javascript:' => 'javascript:location="//evil.example"',
        'mixed case vbscript:' => 'VbScript:evil.example',
        'data:' => 'data:text/html,evil.example',
        'a tab inside the scheme' => "java\tscript:alert('evil.example')",
    ]);
});
