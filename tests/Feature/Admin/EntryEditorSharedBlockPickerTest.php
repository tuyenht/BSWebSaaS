<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\ContentBlockTranslation;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Theme\Components\BlockReference;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fixtures\StorefrontCards;

/*
 * «Khối dùng chung» in «Trình dựng» of the page editor (board 27): «Chèn khối dùng chung» lists this
 * website's shared blocks (published first, drafts too), never another website's, and places the one
 * picked at the end of the language's layout as a reference holding only the block's ulid; a layout
 * holds at most BlockReference::LIST_MAX of them. A placed block shows a card: its status, a deleted
 * block with «Bỏ khỏi trang», a missing language. A draft placed on a page shows on the website only
 * once the block is published.
 */

const SPK_EDITOR = ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish', 'blocks.manage', 'media.view'];
const SPK_AUTHOR = ['entries.view', 'entries.create', 'entries.edit.own'];

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    StorefrontCards::pinMediaConfig();
    spkEntitle([1, 2]);
});

/** Give $sites the block library (the `blocks` module of their plan). */
function spkEntitle(array $sites): void
{
    DB::connection('pgsql_admin')->table('site_modules')->insert(array_map(fn (int $site): array => [
        'site_id' => $site, 'module_key' => 'blocks', 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ], $sites));
}

function spkMember(array $permissions, int $site = 1): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, 'sbp-'.md5(implode(',', $permissions)), $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

/** A shared block of $site with a layout in each of $locales. */
function spkBlock(int $site, string $key, string $name, string $status = 'published', string $region = 'inline', array $locales = ['vi'], array $body = []): ContentBlock
{
    return app(Tenancy::class)->enter($site, function () use ($key, $name, $status, $region, $locales, $body): ContentBlock {
        $block = ContentBlock::query()->create(['key' => $key, 'name' => $name, 'region' => $region, 'status' => $status]);
        foreach ($locales as $locale) {
            ContentBlockTranslation::query()->create(['content_block_id' => $block->id, 'locale' => $locale, 'body' => ['blocks' => $body]]);
        }

        return $block;
    });
}

/** A page of site 1 whose vi body is $blocks (an empty body is written text). */
function spkPage(User $by, array $blocks = [], string $status = 'draft', string $slug = 'gioi-thieu'): Entry
{
    return app(Tenancy::class)->enter(1, function () use ($by, $blocks, $status, $slug): Entry {
        $entry = Entry::query()->create(['type' => 'page', 'status' => $status, 'author_id' => $by->id, 'meta' => []] + ($status === 'published' ? ['published_at' => now()] : []));
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => $slug, 'status' => $status, 'body' => ['blocks' => $blocks]]);

        return $entry;
    });
}

function spkCta(string $heading): array
{
    return ['type' => 'cta', 'props' => ['heading' => $heading]];
}

function spkRef(string $ulid): array
{
    return ['type' => BlockReference::TYPE, 'props' => [BlockReference::PROP => $ulid]];
}

/** What «Chèn khối dùng chung» lists, as the browser receives it. */
function spkChoices($component): array
{
    $out = null;
    $component->call('sharedBlockChoices')->assertReturned(function ($value) use (&$out): bool {
        $out = $value;

        return is_array($value);
    });

    return $out;
}

it('lists this website\'s shared blocks, published first then drafts, never another website\'s, by ulid only', function (): void {
    $user = spkMember(SPK_EDITOR);
    $draft = spkBlock(1, 'cta-khuyen-mai', 'A khuyến mãi', 'draft');
    $footer = spkBlock(1, 'chan-trang', 'Chân trang', 'published', 'footer');
    $contact = spkBlock(1, 'thong-tin-lien-he', 'Thông tin liên hệ');
    spkBlock(2, 'khoi-website-khac', 'Khối của website khác');
    $entry = spkPage($user, [spkCta('Đầu trang'), spkRef($contact->ulid)]);

    app(Tenancy::class)->enter(1, function () use ($user, $entry, $draft, $footer, $contact): void {
        $choices = spkChoices(Livewire::actingAs($user)->test(Editor::class, ['ulid' => $entry->ulid]));

        expect(array_column($choices['rows'], 'ulid'))->toBe([strtoupper($footer->ulid), strtoupper($contact->ulid), strtoupper($draft->ulid)])
            ->and($choices['cap'])->toBeFalse()
            ->and(array_keys($choices['rows'][0]))->toBe(['ulid', 'name', 'region', 'draft', 'meta', 'onPage', 'loc', 'key'])
            ->and($choices['rows'][2]['draft'])->toBeTrue()
            ->and($choices['rows'][1]['onPage'])->toBeTrue()
            ->and($choices['rows'][0]['onPage'])->toBeFalse()
            ->and($choices['rows'][1]['meta'])->toBe('Mã: thong-tin-lien-he · VI · Dùng ở 0 nơi')
            ->and(json_encode($choices))->not->toContain('Khối của website khác')
            ->and(json_encode($choices))->not->toContain('"id"');
    });
});

it('offers «Khối dùng chung» in «+ Thêm khối» and its dialog only where the plan has the block library', function (): void {
    $user = spkMember(SPK_EDITOR);
    $entry = spkPage($user, [spkCta('Đầu trang')]);

    app(Tenancy::class)->enter(1, function () use ($user, $entry): void {
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $entry->ulid])
            ->assertSeeHtml('data-ref-open')
            ->assertSeeHtml('data-ref-picker')
            ->assertSee('Chèn khối dùng chung');
    });

    DB::connection('pgsql_admin')->table('site_modules')->where('site_id', 1)->where('module_key', 'blocks')->delete();
    app()->forgetInstance(Entitlement::class);
    app()->forgetScopedInstances();
    app(Tenancy::class)->enter(1, function () use ($user, $entry): void {
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $entry->ulid])
            ->assertDontSeeHtml('data-ref-open')
            ->assertDontSeeHtml('data-ref-picker')
            ->call('sharedBlockChoices')->assertForbidden();
    });
    $block = spkBlock(1, 'thong-tin-lien-he', 'Thông tin liên hệ');
    // a block placed before the plan lost the library still shows, without «Mở khối» to a screen it cannot open
    $placed = spkPage($user, [spkRef(strtoupper($block->ulid))], 'draft', 'trang-co-khoi');
    app(Tenancy::class)->enter(1, function () use ($user, $placed): void {
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $placed->ulid])
            ->assertSeeHtml('data-ref-card="ok"')
            ->assertDontSeeHtml('data-ref-open-block');
    });
    app(Tenancy::class)->enter(1, function () use ($user, $entry, $block): void {
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $entry->ulid])
            ->call('insertSharedBlock', $block->ulid)->assertForbidden();
        expect(EntryTranslation::query()->where('entry_id', $entry->id)->firstOrFail()->body['blocks'])->toBe([spkCta('Đầu trang')]);
    });
});

it('places the block picked at the end of the layout, holding only its ulid, and saves it', function (): void {
    $user = spkMember(SPK_EDITOR);
    $block = spkBlock(1, 'thong-tin-lien-he', 'Thông tin liên hệ');
    $entry = spkPage($user, [spkCta('Đầu trang')]);

    app(Tenancy::class)->enter(1, function () use ($user, $entry, $block): void {
        $c = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $entry->ulid])
            ->call('insertSharedBlock', strtolower($block->ulid))
            ->assertReturned(1)
            ->assertHasNoErrors()
            ->assertSet('openBlock', 1)
            ->assertSet('blocks.1', spkRef(strtoupper($block->ulid)))
            ->assertDispatched('bs-toast', text: 'Đã chèn khối dùng chung “Thông tin liên hệ”')
            ->assertSeeHtml('data-ref-card="ok"')
            ->assertSeeHtml('id="block-ref-1"')
            ->assertSee('Mở khối');
        $c->call('save')->assertHasNoErrors();

        $body = EntryTranslation::query()->where('entry_id', $entry->id)->where('locale', 'vi')->firstOrFail()->body;
        expect($body['blocks'][1] ?? null)->toBe(spkRef(strtoupper($block->ulid)));
    });
});

it('turns a page of written text into a layout whose first block is the one placed', function (): void {
    $user = spkMember(SPK_EDITOR);
    $block = spkBlock(1, 'thong-tin-lien-he', 'Thông tin liên hệ');
    $entry = spkPage($user);

    app(Tenancy::class)->enter(1, function () use ($user, $entry, $block): void {
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $entry->ulid])
            ->assertSet('prose', true)
            ->call('insertSharedBlock', $block->ulid)
            ->assertSet('prose', false)
            ->assertSet('blocks', [spkRef(strtoupper($block->ulid))]);
    });
});

it('places nothing for another website\'s block, a made-up code or something that is not a code', function (mixed $pick): void {
    $user = spkMember(SPK_EDITOR);
    $foreign = spkBlock(2, 'khoi-website-khac', 'Khối của website khác');
    $entry = spkPage($user, [spkCta('Đầu trang')]);
    $pick = $pick === 'foreign' ? $foreign->ulid : $pick;

    app(Tenancy::class)->enter(1, function () use ($user, $entry, $pick): void {
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $entry->ulid])
            ->call('insertSharedBlock', $pick)
            ->assertReturned(null)
            ->assertHasErrors('blocks')
            ->assertSet('blocks', [spkCta('Đầu trang')]);
    });
})->with([
    'another website\'s block' => ['foreign'],
    'a made-up ulid' => ['01JZZZZZZZZZZZZZZZZZZZZZZZ'],
    'not a ulid' => ['lien-he'],
    'a list' => [['01JZZZZZZZZZZZZZZZZZZZZZZZ']],
]);

it('says every row is full and places nothing once the layout holds the most shared blocks', function (): void {
    $user = spkMember(SPK_EDITOR);
    $block = spkBlock(1, 'thong-tin-lien-he', 'Thông tin liên hệ');
    $full = array_fill(0, BlockReference::LIST_MAX, spkRef(strtoupper($block->ulid)));
    $entry = spkPage($user, $full);

    app(Tenancy::class)->enter(1, function () use ($user, $entry, $block, $full): void {
        $c = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $entry->ulid]);
        expect(spkChoices($c)['cap'])->toBeTrue();
        $c->call('insertSharedBlock', $block->ulid)
            ->assertReturned(null)
            ->assertHasErrors('blocks')
            ->assertSet('blocks', $full);

        // one fewer: room for one more
        $c->call('removeBlock', 0);
        expect(spkChoices($c)['cap'])->toBeFalse();
        $c->call('insertSharedBlock', $block->ulid)->assertReturned(BlockReference::LIST_MAX - 1);
    });
});

it('shows a block deleted from the library as gone, with «Bỏ khỏi trang» taking it off the page', function (): void {
    $user = spkMember(SPK_EDITOR);
    $block = spkBlock(1, 'khoi-da-xoa', 'Khối đã xóa');
    $entry = spkPage($user, [spkCta('Đầu trang'), spkRef(strtoupper($block->ulid))]);
    app(Tenancy::class)->enter(1, fn () => $block->delete());

    app(Tenancy::class)->enter(1, function () use ($user, $entry): void {
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $entry->ulid])
            ->assertSeeHtml('data-ref-card="gone"')
            ->assertSee('Khối không còn trong Thư viện khối')
            ->assertSee('Khối dùng chung đặt ở đây đã bị xóa khỏi Thư viện khối. Khách không thấy gì ở chỗ này.')
            ->assertSeeHtml('data-ref-remove')
            ->assertDontSeeHtml('data-ref-open-block')
            ->call('removeBlock', 1)
            ->assertSet('blocks', [spkCta('Đầu trang')]);
    });
});

it('says a placed draft is not seen yet, and tells a member who may not edit blocks to ask', function (): void {
    $editor = spkMember(SPK_EDITOR);
    $author = spkMember(SPK_AUTHOR);
    $block = spkBlock(1, 'cta-khuyen-mai', 'Khung khuyến mãi', 'draft');
    $mine = spkPage($editor, [spkRef(strtoupper($block->ulid))]);
    $theirs = spkPage($author, [spkRef(strtoupper($block->ulid))]);

    app(Tenancy::class)->enter(1, function () use ($editor, $mine, $block): void {
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $mine->ulid])
            ->assertSeeHtml('data-ref-card="draft"')
            ->assertSee('Khối đang là bản nháp nên khách chưa thấy phần này. Mở khối rồi bấm «Lưu & xuất bản» để hiện ra.')
            ->assertSeeHtml('/admin/blocks/'.$block->ulid.'/edit');
    });
    app(Tenancy::class)->enter(1, function () use ($author, $theirs): void {
        $c = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $theirs->ulid])
            ->assertSee('Nhờ người quản lý website xuất bản khối.')
            ->assertSee('Bạn chưa có quyền sửa khối dùng chung')
            ->assertDontSeeHtml('data-ref-open-block');
        // an author may still place a block, without the library link
        expect(spkChoices($c)['rows'])->toHaveCount(1);
        $c->assertDontSeeHtml('/admin/blocks"');
    });
});

it('refuses the list and the placing once the member may no longer edit the page', function (): void {
    $owner = spkMember(SPK_EDITOR);
    $author = spkMember(SPK_AUTHOR);
    $block = spkBlock(1, 'thong-tin-lien-he', 'Thông tin liên hệ');
    $entry = spkPage($author, [spkCta('Đầu trang')]);

    app(Tenancy::class)->enter(1, function () use ($owner, $author, $entry, $block): void {
        $list = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $entry->ulid]);
        $place = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $entry->ulid]);
        // the page is handed to someone else while the author has it open: it is not theirs to edit now
        Entry::query()->whereKey($entry->id)->update(['author_id' => $owner->id]);
        $list->call('sharedBlockChoices')->assertForbidden();
        $place->call('insertSharedBlock', $block->ulid)->assertForbidden();
        expect(EntryTranslation::query()->where('entry_id', $entry->id)->firstOrFail()->body['blocks'])->toBe([spkCta('Đầu trang')]);
    });
});

it('says what a visitor of another language sees of a block without that language', function (): void {
    DB::connection('pgsql_admin')->table('site_locales')->insert([
        'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $user = spkMember(SPK_EDITOR);
    $viOnly = spkBlock(1, 'widget-cot', 'Widget cột');
    $entry = spkPage($user, [spkCta('Đầu trang')]);
    app(Tenancy::class)->enter(1, fn () => $entry->translations()->create(['locale' => 'en', 'title' => 'About', 'slug' => 'about', 'status' => 'draft', 'body' => ['blocks' => [spkRef(strtoupper($viOnly->ulid))]]]));

    app(Tenancy::class)->enter(1, function () use ($user, $entry): void {
        $c = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $entry->ulid, 'locale' => 'en'])
            ->assertSee('Khối chưa có bản English. Khách xem trang English sẽ thấy bản Tiếng Việt của khối.');
        expect(spkChoices($c)['rows'][0]['loc'])->toBe('Chưa có bản English — khách xem trang English thấy bản Tiếng Việt');
    });
})->skip(fn (): bool => ! class_exists(Locale::class), 'needs intl for language names');

it('keeps a shared block\'s place free of looks: «Trình bày» changes nothing there', function (): void {
    $user = spkMember(SPK_EDITOR);
    $block = spkBlock(1, 'thong-tin-lien-he', 'Thông tin liên hệ');
    $entry = spkPage($user, [spkRef(strtoupper($block->ulid))]);

    app(Tenancy::class)->enter(1, function () use ($user, $entry, $block): void {
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $entry->ulid])
            ->call('setLook', 0, 'scheme', 'dark')
            ->assertSet('blocks.0', spkRef(strtoupper($block->ulid)))
            ->assertSee('Khối dùng chung giữ màu, khoảng cách và cách trình bày của chính nó');
    });
});

it('reads the list in the same few queries however many blocks the website keeps', function (): void {
    $user = spkMember(SPK_EDITOR);
    $entry = spkPage($user, [spkCta('Đầu trang')]);
    $count = function () use ($user, $entry): int {
        return app(Tenancy::class)->enter(1, function () use ($user, $entry): int {
            $c = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $entry->ulid]);
            DB::flushQueryLog();
            DB::enableQueryLog();
            spkChoices($c);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        });
    };
    spkBlock(1, 'k-1', 'Khối 1');
    $few = $count();
    foreach (range(2, 25) as $n) {
        spkBlock(1, 'k-'.$n, 'Khối '.$n, $n % 2 === 0 ? 'published' : 'draft', 'inline', ['vi', 'en']);
    }

    expect($count())->toBe($few);
});

it('places a draft block that the website shows only once the block is published', function (): void {
    seedHomepage(1, 'An Vinh');
    $user = spkMember(SPK_EDITOR);
    $block = spkBlock(1, 'cta-khuyen-mai', 'Khung khuyến mãi', 'draft', 'inline', ['vi'], [spkCta('Ưu đãi tháng mười một')]);
    $home = app(Tenancy::class)->enter(1, fn (): Entry => Entry::query()->where('type', 'page')->orderBy('id')->firstOrFail());

    app(Tenancy::class)->enter(1, function () use ($user, $home, $block): void {
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $home->ulid])
            ->call('insertSharedBlock', $block->ulid)
            ->call('publish')
            ->assertHasNoErrors();
    });
    $stored = app(Tenancy::class)->enter(1, fn (): array => EntryTranslation::query()->where('entry_id', $home->id)->where('locale', 'vi')->firstOrFail()->body);
    expect($stored['blocks'])->toContain(spkRef(strtoupper($block->ulid)));

    $page = function (): string {
        app()->forgetScopedInstances();
        foreach (app('router')->getRoutes() as $route) {
            $route->flushController();
        }

        return (string) test()->get('http://a.test/')->assertOk()->getContent();
    };
    expect($page())->not->toContain('Ưu đãi tháng mười một');

    app(Tenancy::class)->enter(1, fn () => $block->fresh()?->update(['status' => ContentBlock::PUBLISHED]));
    expect($page())->toContain('Ưu đãi tháng mười một');
});

it('says a visitor sees nothing of a block that has neither the page\'s language nor the website\'s default', function (): void {
    DB::connection('pgsql_admin')->table('site_locales')->insert([
        'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $user = spkMember(SPK_EDITOR);
    $enOnly = spkBlock(1, 'chi-tieng-anh', 'Chỉ tiếng Anh', 'published', 'inline', ['en']);
    $jaOnly = spkBlock(1, 'chi-tieng-nhat', 'Chỉ tiếng Nhật', 'published', 'inline', ['ja']);
    $entry = spkPage($user, [spkRef(strtoupper($enOnly->ulid))]);
    app(Tenancy::class)->enter(1, fn () => $entry->translations()->create(['locale' => 'en', 'title' => 'About', 'slug' => 'about', 'status' => 'draft', 'body' => ['blocks' => [spkRef(strtoupper($jaOnly->ulid))]]]));

    app(Tenancy::class)->enter(1, function () use ($user, $entry): void {
        // the page in the default language, the block without it: nothing shows
        $vi = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $entry->ulid])
            ->assertSee('Khối chưa có bản Tiếng Việt nên khách xem trang này không thấy phần này.');
        $rows = collect(spkChoices($vi)['rows'])->keyBy('key');
        expect($rows['chi-tieng-anh']['loc'])->toBe('Chưa có bản Tiếng Việt — khách xem trang Tiếng Việt không thấy khối này');

        // the page in English, the block with neither English nor Vietnamese: nothing shows either
        $en = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $entry->ulid, 'locale' => 'en'])
            ->assertSee('Khối chưa có bản English nên khách xem trang này không thấy phần này.');
        $rows = collect(spkChoices($en)['rows'])->keyBy('key');
        expect($rows['chi-tieng-nhat']['loc'])->toBe('Chưa có bản English — khách xem trang English không thấy khối này')
            ->and($rows['chi-tieng-anh']['loc'])->toBeNull();
    });
})->skip(fn (): bool => ! class_exists(Locale::class), 'needs intl for language names');

it('draws the cards of the placed blocks in the same few queries however many the layout places', function (): void {
    $user = spkMember(SPK_EDITOR);
    $blocks = [];
    foreach (range(1, 15) as $n) {
        $blocks[] = spkBlock(1, 'k-'.$n, 'Khối '.$n, $n % 2 === 0 ? 'published' : 'draft', 'inline', ['vi', 'en']);
    }
    $count = function (array $placed) use ($user): int {
        $entry = spkPage($user, array_map(fn (ContentBlock $b): array => spkRef(strtoupper($b->ulid)), $placed), 'draft', 'trang-'.count($placed));

        return app(Tenancy::class)->enter(1, function () use ($user, $entry): int {
            $c = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $entry->ulid]);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $c->call('setLook', 0, 'scheme', 'dark')->assertSeeHtml('data-ref-card=');
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        });
    };

    expect($count($blocks))->toBe($count([$blocks[0]]));
});
