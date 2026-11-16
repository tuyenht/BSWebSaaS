<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Admin\Support\EditedWhen;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteLocale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * The words of the posts and pages list: the list named as its menu section, the main button
 * saying what it adds, the one set of status words in the design's order with each count in its
 * status's ink, the dates written out in the website's clock ("Sửa hôm nay, 10:20" … "Sửa 7 ngày
 * trước", then the day), views named only where visitors can see the record, and the live record's
 * "Xem" saying it opens a new tab.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    // the words under test are the Vietnamese ones the owner signed
    app()->setLocale('vi');
});

/** @param  array<string, mixed>  $extra */
function lwEntry(User $author, string $title, string $type = 'post', array $extra = []): Entry
{
    $entry = Entry::query()->create($extra + ['type' => $type, 'status' => 'draft', 'author_id' => $author->id]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => 'lw-'.$entry->id, 'status' => 'draft', 'body' => ['blocks' => []]]);
    if ($type === 'post') {
        $entry->assignCategories([(int) ContentSection::query()->where('key', 'tin-tuc')->value('taxonomy_id')]);
    }

    return $entry;
}

function lwSection(): ContentSection
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);

    return ContentSection::query()->create(['key' => 'tin-tuc', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id, 'permission_prefix' => 'entries', 'sort_order' => 1]);
}

/** Set a record's last edit to a UTC moment, without touching it again. */
function lwEditedAt(Entry $entry, string $utc): void
{
    Entry::query()->whereKey($entry->id)->toBase()->update(['updated_at' => $utc.'+00']);
}

describe('header', function (): void {
    it('names the posts list as its section, says what it holds, and adds a post', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view', 'entries.create']);
            lwSection();

            Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc'])
                ->assertSeeHtml('>Tin tức</h1>')
                ->assertDontSee('Quản lý Tin tức')
                ->assertSee('Mọi tin tức của website: tìm, lọc, đổi trạng thái và sửa ngay tại dòng.')
                ->assertSee('Thêm bài viết')
                ->assertDontSee('Tạo mới')
                ->assertSeeHtml('placeholder="Ví dụ: bảng giá"')
                ->assertSeeHtml('aria-label="'.e(__('entries.search_placeholder')).'"');
        });
    });

    it('names the pages list as its own section, with its own button and hint, and nothing of the posts list', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view', 'entries.create']);

            Livewire::actingAs($user->fresh())->test(Index::class)
                ->assertSeeHtml('>'.e(__('entries.section_fallback')).'</h1>')
                ->assertSee('Thêm trang')
                ->assertDontSee('Thêm bài viết')
                ->assertSee(__('entries.desc_page'))
                ->assertSeeHtml('placeholder="Ví dụ: lịch sử"');
        });
    });
});

describe('status groups', function (): void {
    it('uses the one set of status words, in the design order, each count in its own ink', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view', 'entries.delete.any']);
            foreach (['published', 'draft', 'scheduled', 'private', 'archived'] as $i => $status) {
                lwEntry($user, 'Trang '.$i, 'page', ['status' => $status, 'position' => $i, 'published_at' => $status === 'scheduled' ? now()->addDay() : null]);
            }

            $html = Livewire::actingAs($user->fresh())->test(Index::class)->html();

            expect($html)->toMatch('/Tất cả.*Đã đăng.*Bản nháp.*Đã hẹn giờ.*Không công khai.*Đã gỡ khỏi website.*Thùng rác/s');
            foreach (['all', 'published', 'draft', 'scheduled', 'private', 'archived', 'trash'] as $tone) {
                expect($html)->toMatch('/<span data-s="'.$tone.'" class="inline-flex min-w-5[^"]*bg-\[color-mix\(in_srgb,var\(--c\)_11%,var\(--color-surface\)\)\]/');
            }
            foreach (['Đã xuất bản', 'Đã lên lịch', 'Riêng tư', 'Lưu trữ'] as $old) {
                expect($html)->not->toContain($old);
            }
        });
    });

    it('keeps the plain count bubble on a list whose groups carry no tone', function (): void {
        $html = (string) $this->blade('<x-bs-admin::group-row :groups="$g" active="a" model="tab" label="Nhóm" />', ['g' => [['key' => 'a', 'label' => 'A', 'count' => 3]]]);

        expect($html)->toContain('bg-primary/10 text-primary-text')
            ->and($html)->not->toContain('data-s=');
    });
});

describe('dates', function (): void {
    it('writes the last edit in the website clock: today and yesterday with the hour, up to 7 days ago in days, then the day', function (): void {
        $this->travelTo(Carbon::parse('2026-11-16 03:00:00', 'UTC')); // 10:00 on 16/11 in Hà Nội
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            // 17:20 UTC on 15/11 is already 00:20 on 16/11 in Hà Nội: "today", not "yesterday"
            lwEditedAt(lwEntry($user, 'A', 'page', ['position' => 1]), '2026-11-15 17:20:00');
            lwEditedAt(lwEntry($user, 'B', 'page', ['position' => 2]), '2026-11-15 01:15:00');
            lwEditedAt(lwEntry($user, 'C', 'page', ['position' => 3]), '2026-11-13 02:00:00');
            lwEditedAt(lwEntry($user, 'D', 'page', ['position' => 4]), '2026-11-09 02:00:00');
            lwEditedAt(lwEntry($user, 'E', 'page', ['position' => 5]), '2026-11-08 02:00:00');

            $html = Livewire::actingAs($user->fresh())->test(Index::class)->html();

            expect($html)->toContain('Sửa hôm nay, 00:20')
                ->and($html)->toContain('Sửa hôm qua, 08:15')
                ->and($html)->toContain('Sửa 3 ngày trước')
                ->and($html)->toContain('Sửa 7 ngày trước')
                ->and($html)->toContain('Sửa 08/11/2026')
                ->and($html)->not->toContain('8 ngày trước')
                ->and($html)->not->toContain('Th11')
                // the exact moment is in the page for machines and screen readers, never only on hover
                ->and($html)->toContain('<time datetime="2026-11-13T09:00:00+07:00" title="13/11/2026 · 09:00">Sửa 3 ngày trước<span class="sr-only"> (13/11/2026 · 09:00)</span></time>');
        });
    });

    it('writes a scheduled post as the moment it goes up', function (): void {
        $this->travelTo(Carbon::parse('2026-11-16 03:00:00', 'UTC'));
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            lwSection();
            lwEntry($user, 'Hẹn', 'post', ['status' => 'scheduled', 'published_at' => '2026-11-23 01:00:00+00']);

            $html = Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc'])->html();

            // the hour stays with its day when the line wraps (no-break spaces around the dot)
            expect($html)->toContain('<time datetime="2026-11-23T08:00:00+07:00" title="23/11/2026 · 08:00">Đăng lúc 23/11/2026'."\u{00A0}·\u{00A0}".'08:00</time>');
        });
    });

    it('keeps the product list on its own short form through the same helper', function (): void {
        $now = CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Ho_Chi_Minh');

        expect(EditedWhen::short($now->subDays(3), $now))->toBe('Sửa 13/11, 10:00')
            ->and(EditedWhen::short($now->subYear(), $now))->toBe('Sửa 16/11/2025')
            ->and(EditedWhen::short($now->subDays(7), $now, recentDays: 7, dayThisYear: false))->toBe('Sửa 7 ngày trước')
            ->and(EditedWhen::short($now->subDays(8), $now, recentDays: 7, dayThisYear: false))->toBe('Sửa 08/11/2026')
            // a clock running slightly ahead never reads as a future day
            ->and(EditedWhen::short($now->addMinutes(5), $now))->toBe('Sửa hôm nay, 10:05');
    });
});

describe('rows', function (): void {
    it('names views only on a published record, and opens a published record in a new tab, saying so', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view', 'entries.edit.any']);
            lwSection();
            lwEntry($user, 'Đang đăng', 'post', ['status' => 'published', 'published_at' => now()->subDay(), 'view_count' => 1240]);
            lwEntry($user, 'Đã gỡ', 'post', ['status' => 'archived', 'published_at' => now()->subDays(2), 'view_count' => 410]);
            lwEntry($user, 'Kín', 'post', ['status' => 'private', 'published_at' => now()->subDays(3), 'view_count' => 35]);

            $html = Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc'])->html();

            expect($html)->toContain('1.240 lượt xem')
                ->and($html)->not->toContain('410 lượt xem')
                ->and($html)->not->toContain('35 lượt xem')
                ->and($html)->toContain('aria-label="'.e(__('entries.view_named', ['title' => 'Đang đăng'])).' (mở ở thẻ mới)"')
                ->and($html)->toContain('aria-label="'.e(__('entries.view_named', ['title' => 'Kín'])).'"')
                ->and(substr_count($html, 'title="mở ở thẻ mới"'))->toBe(1);
        });
    });
});

describe('tools', function (): void {
    it('offers no Ngôn ngữ filter on a one-language website', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            lwEntry($user, 'Một', 'page');

            expect(Livewire::actingAs($user->fresh())->test(Index::class)->html())->not->toContain('data-fsec="lang"');
        });
    });

    it('offers the Ngôn ngữ filter on a website with two languages', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            foreach (['vi', 'en'] as $i => $code) {
                SiteLocale::query()->firstOrCreate(['locale' => $code], ['is_default' => $i === 0, 'enabled' => true]);
            }
            lwEntry($user, 'Một', 'page');

            expect(Livewire::actingAs($user->fresh())->test(Index::class)->html())->toContain('data-fsec="lang"');
        });
    });

    it('says how many columns are hidden on the Cột button', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            lwEntry($user, 'Một', 'page');

            $list = Livewire::actingAs($user->fresh())->test(Index::class)->set('cols', ['path', 'creator', 'tpl']);
            expect($list->html())->toContain('aria-label="Chọn cột hiển thị"')->not->toContain('Cột (ẩn');
            $list->set('cols', ['path']);
            expect($list->html())->toContain('Cột (ẩn 2)');
        });
    });

    it('names select-all by what it ticks: "Chọn tất cả" on one page, "Chọn cả trang" once there are more', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            foreach (range(1, 11) as $i) {
                lwEntry($user, 'Trang '.$i, 'page', ['position' => $i]);
            }

            $list = Livewire::actingAs($user->fresh())->test(Index::class);
            expect($list->html())->toContain('Chọn tất cả')->not->toContain('Chọn cả trang');

            $list->call('setPerPage', 10);
            expect($list->html())->toContain('Chọn cả trang');
        });
    });
});
