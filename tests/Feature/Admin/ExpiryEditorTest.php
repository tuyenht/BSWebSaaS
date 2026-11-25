<?php

use App\Modules\Admin\Livewire\Catalog\Editor as ProductEditor;
use App\Modules\Admin\Livewire\Catalog\Index as ProductIndex;
use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * «Gỡ khỏi website lúc» in the «Đăng» card of the post/page editor and the product editor (boards 07, 09),
 * «Ngày hiện trên bài» moved into that card, and «Hết hạn dd/mm/yyyy» in both lists (boards 05, 08). The
 * field holds the website's wall clock; the stored end time is the same instant in UTC.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    enableCatalog(1);
    app()->setLocale('vi');
    $this->travelTo(CarbonImmutable::parse('2026-11-25 03:00:00', 'UTC'));
});

function xpMember(array $permissions): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'xp-'.md5(implode(',', $permissions)), $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

/** A post in the `posts` collection with $fields; the editor's member may publish unless told otherwise. */
function xpPost(array $fields = []): Entry
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
    ContentSection::query()->create([
        'key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);
    SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);
    $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => []] + $fields);
    $entry->translations()->create(['locale' => 'vi', 'title' => 'Tuyển kỹ sư', 'slug' => 'tuyen-ky-su', 'status' => 'draft', 'body' => ['blocks' => []]]);
    $entry->assignCategories([$category->id]);

    return $entry->fresh() ?? $entry;
}

/** The instant a wall-clock time in the website's zone names, in UTC. */
function xpUtc(string $local): string
{
    return CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $local, app(SiteTime::class)->zone())->utc()->format('Y-m-d H:i');
}

const XP_EDITOR = ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish'];

it('saves the end time typed in the website clock as the same instant in UTC, and «Bỏ giờ gỡ» clears it with «Hoàn tác»', function (): void {
    $user = xpMember(XP_EDITOR);
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $post = xpPost();
        $c = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSee(__('entries.ed.exp_label'))
            ->assertSeeHtml('id="entry-exp-at"')
            ->set('expiresAt', '2026-12-01T09:30')
            ->assertHasNoErrors('expiresAt')
            ->call('save')
            ->assertHasNoErrors();
        expect($post->fresh()->expires_at->utc()->format('Y-m-d H:i'))->toBe(xpUtc('2026-12-01T09:30'))
            ->and(app(SiteTime::class)->zone())->not->toBe('UTC');

        $c->assertSee(__('entries.ed.exp_clear'))
            ->call('clearExpiry')
            ->assertSet('expiresAt', '')
            ->assertSet('formUndo', ['field' => 'expiresAt', 'value' => '2026-12-01T09:30'])
            ->call('undoFormClear')
            ->assertSet('expiresAt', '2026-12-01T09:30')
            ->call('clearExpiry')
            ->call('save');
        expect($post->fresh()->expires_at)->toBeNull();
    });
});

it('refuses an end time already past, as the field is left and on save, in plain words', function (): void {
    $user = xpMember(XP_EDITOR);
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $post = xpPost();
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('expiresAt', '2026-11-01T08:00')
            ->assertHasErrors(['expiresAt'])
            ->assertSee(__('entries.expiry_past'))
            ->call('save')
            ->assertHasErrors(['expiresAt']);
        expect($post->fresh()->expires_at)->toBeNull();
    });
});

it('keeps the field read only for a member who may not publish, and writes nothing they send', function (): void {
    $user = xpMember(['entries.view', 'entries.create', 'entries.edit.any']);
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $post = xpPost();
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSeeHtml('id="entry-exp-at"')
            ->assertDontSee(__('entries.ed.exp_clear'))
            ->set('expiresAt', '2026-12-01T09:30')
            ->assertSet('expiresAt', '')
            ->call('save');
        expect($post->fresh()->expires_at)->toBeNull();
    });
});

it('forbids «Bỏ giờ gỡ» to a member who may not edit the post', function (): void {
    $writer = xpMember(XP_EDITOR);
    $viewer = xpMember(['entries.view']);
    app(Tenancy::class)->enter(1, function () use ($writer, $viewer): void {
        $post = xpPost();
        $c = Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $post->ulid]);
        $this->actingAs($viewer);
        $c->call('clearExpiry')->assertForbidden();
    });
});

it('puts «Ngày hiện trên bài» in the «Đăng» card behind «Đổi ngày hiện», and refuses a day after today', function (): void {
    $user = xpMember(XP_EDITOR);
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $post = xpPost();
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSeeHtmlInOrder(['id="entry-pub-h"', __('entries.ed.pd_line_none'), __('entries.ed.pd_change'), 'id="entry-pd"', 'id="entry-exp-at"', 'id="entry-cat-h"'])
            ->set('displayDate', '2026-12-31')
            ->call('save')
            ->assertHasErrors(['displayDate'])
            ->set('displayDate', '2026-11-20')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('20/11/2026')
            ->call('useRealDate')
            ->assertSet('displayDate', '')
            ->call('undoFormClear')
            ->assertSet('displayDate', '2026-11-20');
    });
});

it('shows «Hết hạn dd/mm/yyyy» beside the pill of a post and a product taken down at their end time', function (): void {
    $user = xpMember([...XP_EDITOR, 'catalog.view', 'catalog.manage']);
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $end = CarbonImmutable::parse('2026-11-20 03:00:00', 'UTC');
        xpPost(['status' => 'archived', 'published_at' => $end->subDays(5), 'expires_at' => $end]);
        $product = Product::query()->create(['status' => 'archived', 'published_at' => $end->subDays(5), 'expires_at' => $end]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Máy hàn TIG', 'slug' => 'may-han-tig']);
        $live = Product::query()->create(['status' => 'published', 'published_at' => $end->subDays(5), 'expires_at' => $end->addMonth()]);
        $live->translations()->create(['locale' => 'vi', 'name' => 'Máy cắt', 'slug' => 'may-cat']);

        Livewire::actingAs($user)->test(Index::class, ['section' => 'posts'])
            ->set('tab', 'archived')
            ->assertSeeHtml('data-expired')
            ->assertSee(__('entries.list_expired', ['date' => '20/11/2026']))
            ->assertSee(__('entries.status_archived'));
        Livewire::actingAs($user)->test(ProductIndex::class)
            ->assertSee(__('catalog.list_expired', ['date' => '20/11/2026']))
            ->assertDontSee(__('catalog.list_expired', ['date' => '20/12/2026']));
    });
});

it('saves a product end time in UTC, refuses a past one, and forbids it to a member who may only look', function (): void {
    $manager = xpMember(['catalog.view', 'catalog.manage']);
    $viewer = xpMember(['catalog.view']);
    app(Tenancy::class)->enter(1, function () use ($manager, $viewer): void {
        $product = Product::query()->create(['status' => 'draft']);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá hàn', 'slug' => 'do-ga-han']);
        $c = Livewire::actingAs($manager)->test(ProductEditor::class, ['ulid' => $product->ulid])
            ->assertSeeHtml('id="ped-exp"')
            ->set('expiresAt', '2026-11-01T08:00')
            ->assertHasErrors(['expiresAt'])
            ->set('expiresAt', '2026-12-01T09:30')
            ->assertHasNoErrors('expiresAt')
            ->call('save');
        expect($product->fresh()->expires_at->utc()->format('Y-m-d H:i'))->toBe(xpUtc('2026-12-01T09:30'));

        $c->call('clearExpiry')->assertSet('expiresAt', '')->call('undoLast')->assertSet('expiresAt', '2026-12-01T09:30');

        Livewire::actingAs($viewer)->test(ProductEditor::class, ['ulid' => $product->ulid])
            ->call('clearExpiry')->assertForbidden();
        expect($product->fresh()->expires_at)->not->toBeNull();
    });
});
