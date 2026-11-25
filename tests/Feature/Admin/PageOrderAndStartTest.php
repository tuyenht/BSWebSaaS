<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Support\Entries\PageStartTemplates;
use App\Modules\Admin\Support\EntryProseBody;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * The page editor's «Thứ tự» and «Bắt đầu từ mẫu» (board 07 «Trình sửa trang»). «Thứ tự» is the page's
 * place among the pages beside it, from 1, as the Trang list's «Thứ tự trên website» orders them; a save
 * with another number moves the page there through MovePage. «Bắt đầu từ mẫu» offers a new page's empty
 * body four starts: three write headings and suggested text (and a title when there is none), «Trang
 * trống» writes nothing.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

function poPage(string $slug, ?Entry $parent = null, int $position = 0, ?User $author = null): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $author?->id, 'parent_id' => $parent?->id, 'position' => $position]);
    $page->translations()->create(['locale' => 'vi', 'title' => 'T '.$slug, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);

    return $page->fresh() ?? $page;
}

function poMember(array $permissions, string $role = 'editor'): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, $role, $permissions));

    return $user->fresh() ?? $user;
}

function poWriter(): User
{
    return poMember(['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);
}

/** The slugs of the pages under $parent (the top ones when null), in the website's order. */
function poOrder(?Entry $parent = null): array
{
    return Entry::query()->where('type', 'page')
        ->when($parent === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parent?->id))
        ->orderBy('position')->orderBy('id')->get()
        ->map(fn (Entry $e): string => (string) $e->translations()->value('slug'))->all();
}

it('shows the page\'s place among the pages beside it, from 1, whatever numbers they carry', function (): void {
    $writer = poWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        $a = poPage('a', position: 0);
        $b = poPage('b', position: 4);
        $c = poPage('c', position: 4);
        $a1 = poPage('a1', $a, 7);

        Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $c->ulid])->assertSet('pageOrder', '3');
        Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $b->ulid])->assertSet('pageOrder', '2');
        Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $a1->ulid])->assertSet('pageOrder', '1')
            ->assertSeeHtml('id="entry-ord"')->assertSee(__('entries.ed.ord_label'));
    });
});

it('moves the page to the place typed on save, through MovePage, and shows the place it took', function (string $typed, array $order, string $shown): void {
    $writer = poWriter();

    app(Tenancy::class)->enter(1, function () use ($writer, $typed, $order, $shown): void {
        poPage('a', position: 0);
        poPage('b', position: 1);
        $c = poPage('c', position: 2);

        Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $c->ulid])
            ->set('pageOrder', $typed)
            ->call('save')->assertHasNoErrors()
            ->assertSet('pageOrder', $shown)->assertSet('pageOrderLoaded', $shown);

        expect(poOrder())->toBe($order)
            ->and(AuditLog::query()->where('site_id', 1)->where('action', 'cms.page.moved')->where('auditable_id', $c->id)->exists())->toBeTrue();
    });
})->with([
    'first' => ['1', ['c', 'a', 'b'], '1'],
    'middle' => ['2', ['a', 'c', 'b'], '2'],
    'below 1 is the first' => ['-3', ['c', 'a', 'b'], '1'],
]);

it('puts a place past the end at the end, and keeps the place shown for what is not a whole number', function (): void {
    $writer = poWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        $a = poPage('a', position: 0);
        poPage('b', position: 1);
        $moves = fn (): int => AuditLog::query()->where('site_id', 1)->where('action', 'cms.page.moved')->count();
        $before = $moves();

        Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $a->ulid])
            ->set('pageOrder', '99')
            ->call('save')->assertHasNoErrors()->assertSet('pageOrder', '2');
        expect(poOrder())->toBe(['b', 'a']);

        Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $a->ulid])
            ->set('pageOrder', '1.5')->assertSet('pageOrder', '2')
            ->set('pageOrder', '')->assertSet('pageOrder', '2')
            ->call('save')->assertHasNoErrors();
        expect(poOrder())->toBe(['b', 'a'])->and($moves())->toBe($before + 1);
    });
});

it('leaves the page where it is when the place is not changed', function (): void {
    $writer = poWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        poPage('a', position: 0);
        $b = poPage('b', position: 5);
        $moves = fn (): int => AuditLog::query()->where('site_id', 1)->where('action', 'cms.page.moved')->count();
        $before = $moves();

        Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $b->ulid])
            ->set('title', 'Mới')->call('save')->assertHasNoErrors();
        expect($b->fresh()?->position)->toBe(5)->and($moves())->toBe($before);
    });
});

it('shows a new page\'s place as the last, and puts it at the place typed when it is first saved', function (): void {
    $writer = poWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        $a = poPage('a', position: 0);
        poPage('b', position: 1);
        poPage('a1', $a);

        Livewire::actingAs($writer)->test(Editor::class)->assertSet('pageOrder', '3');
        Livewire::actingAs($writer)->test(Editor::class, ['parent' => $a->ulid])->assertSet('pageOrder', '2')
            ->set('title', 'Con')->set('slug', 'con')
            ->set('pageOrder', '1')
            ->call('save')->assertHasNoErrors()->assertSet('pageOrder', '1');
        expect(poOrder($a))->toBe(['con', 'a1']);

        // no place typed: last, and the field says where that is
        Livewire::actingAs($writer)->test(Editor::class)
            ->set('title', 'Cuối')->set('slug', 'cuoi')
            ->call('save')->assertHasNoErrors()->assertSet('pageOrder', '3');
        expect(poOrder())->toBe(['a', 'b', 'cuoi']);
    });
});

it('follows the parent picked: the last place there, a typed place kept and applied under the new parent', function (): void {
    $writer = poWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        $a = poPage('a', position: 0);
        $b = poPage('b', position: 1);
        poPage('a1', $a, 0);
        poPage('a2', $a, 1);

        $editor = Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $b->ulid])->assertSet('pageOrder', '2')
            ->set('parentUlid', $a->ulid)->assertSet('pageOrder', '3')
            ->set('parentUlid', null)->assertSet('pageOrder', '2');

        $editor->set('pageOrder', '1')->set('parentUlid', $a->ulid)->assertSet('pageOrder', '1')->assertSet('pageOrderLoaded', '3')
            ->call('save')->assertHasNoErrors()->assertSet('pageOrder', '1');
        expect($b->fresh()?->parent_id)->toBe($a->id)
            ->and(poOrder($a))->toBe(['b', 'a1', 'a2']);

        // a new parent with no place typed: the last there
        Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $b->ulid])
            ->set('parentUlid', null)->call('save')->assertHasNoErrors()->assertSet('pageOrder', '2');
        expect(poOrder())->toBe(['a', 'b']);
    });
});

it('gives the Home page «Thứ tự» without «Trang cha»', function (): void {
    $writer = poWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        poPage('a', position: 0);
        $home = poPage('home', position: 1);
        app(PublishEntry::class)->handle($home, 'vi', $writer);

        Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $home->ulid])
            ->assertSet('pageHome', true)->assertSet('pageOrder', '2')
            ->assertSeeHtml('id="entry-ord"')->assertSeeHtml('data-page-props')
            ->assertDontSeeHtml('$wire.parentChoices()')
            ->set('pageOrder', '1')->call('publish')->assertHasNoErrors();
        expect(poOrder())->toBe(['home', 'a'])->and($home->fresh()?->parent_id)->toBeNull();
    });
});

it('keeps «Thứ tự» in a language other than the website\'s default one', function (): void {
    $writer = poWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true]);
        poPage('a', position: 0);
        $b = poPage('b', position: 1);

        Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $b->ulid, 'locale' => 'en'])
            ->assertSet('pageOrder', '2')
            ->set('pageOrder', '1')->assertSet('pageOrder', '2');
        expect(poOrder())->toBe(['a', 'b']);
    });
});

it('asks the policy on the «Thứ tự» and «Bắt đầu từ mẫu» endpoints', function (): void {
    $own = poMember(['entries.view', 'entries.create', 'entries.edit.own'], 'author');

    app(Tenancy::class)->enter(1, function () use ($own): void {
        $mine = poPage('mine', author: $own);
        $sets = Livewire::actingAs($own)->test(Editor::class, ['ulid' => $mine->ulid]);
        $mine->update(['author_id' => null]);
        $sets->set('pageOrder', '1')->assertForbidden();

        $starts = Livewire::actingAs($own)->test(Editor::class);
        $own->syncRoles([]);
        app(TeamContext::class)->forget();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        auth()->setUser($own->fresh());
        $starts->call('pickStartTemplate', 'about')->assertForbidden();
    });
});

it('offers the four starts on a new page\'s empty body only, and a template writes its text and a missing title', function (): void {
    $writer = poWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        $page = poPage('a');
        Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $page->ulid])->assertDontSeeHtml('data-page-start');

        $editor = Livewire::actingAs($writer)->test(Editor::class)
            ->assertSeeHtml('data-page-start')->assertSee(__('entries.ed.start_title'))
            ->assertSee(__('entries.ed.start_about'))->assertSee(__('entries.ed.start_service'))
            ->assertSee(__('entries.ed.start_contact'))->assertSee(__('entries.ed.start_blank'));
        expect(array_column(PageStartTemplates::options(), 'key'))->toBe(['about', 'service', 'contact', 'blank']);

        $html = (string) __('entries.ed.start_service_body');
        $editor->call('pickStartTemplate', 'service')
            ->assertSet('title', 'Dịch vụ')->assertSet('slug', 'dich-vu')->assertSet('bodyHtml', $html)->assertSet('startDone', true)
            ->assertDispatched('admin-editor-set-html', target: 'bodyHtml', html: $html)
            ->assertDispatched('bs-toast', text: __('entries.ed.start_used', ['name' => 'Dịch vụ']))
            ->assertDontSeeHtml('data-page-start')
            // offered once: a second pick changes nothing
            ->call('pickStartTemplate', 'about')->assertSet('bodyHtml', $html)
            ->call('save')->assertHasNoErrors();

        $saved = EntryTranslation::query()->where('slug', 'dich-vu')->sole();
        expect($saved->body['blocks'])->toHaveCount(1)
            ->and($saved->body['blocks'][0]['type'])->toBe(EntryProseBody::TYPE)
            ->and((string) $saved->body['blocks'][0]['props']['html'])->toContain('<h2>Chúng tôi giúp gì cho bạn</h2>');

        // a typed title stays
        Livewire::actingAs($writer)->test(Editor::class)->set('title', 'Về An Vinh')
            ->call('pickStartTemplate', 'about')->assertSet('title', 'Về An Vinh')
            ->assertSet('bodyHtml', (string) __('entries.ed.start_about_body'));
    });
});

it('writes nothing for «Trang trống», and offers no start once the body has text or in another language', function (): void {
    $writer = poWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true]);
        Livewire::actingAs($writer)->test(Editor::class)
            ->call('pickStartTemplate', 'blank')
            ->assertSet('title', '')->assertSet('bodyHtml', '')->assertSet('startDone', true)
            ->assertDispatched('bs-toast', text: __('entries.ed.start_blank_done'))
            ->assertNotDispatched('admin-editor-set-html')
            ->assertDontSeeHtml('data-page-start');

        Livewire::actingAs($writer)->test(Editor::class)
            ->set('bodyHtml', '<p>Đã có chữ</p>')->assertDontSeeHtml('data-page-start')
            ->call('pickStartTemplate', 'about')->assertSet('bodyHtml', '<p>Đã có chữ</p>')->assertSet('startDone', false);

        Livewire::actingAs($writer)->test(Editor::class)->call('pickStartTemplate', 'nope')->assertSet('startDone', false)->assertSet('bodyHtml', '');

        Livewire::actingAs($writer)->test(Editor::class, ['locale' => 'en'])->assertSet('locale', 'en')->assertDontSeeHtml('data-page-start')
            ->call('pickStartTemplate', 'about')->assertSet('bodyHtml', '');
    });
});
