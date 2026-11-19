<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Support\EntryProseBody;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * The post and page editor's guards drawn on board 07: «Xóa khối» on a block or on the written text,
 * the «Hẹn giờ đăng» field of a scheduled entry (an empty or past time stops the save, an existing time
 * is kept as it is), and the link that follows the title only while a version has never been saved.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

function egMember(array $permissions = ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', $permissions));

    return $user->fresh() ?? $user;
}

/** A page of the current site whose vi version has $blocks. */
function egPage(string $slug, array $blocks = [], string $status = 'draft', ?Carbon $at = null): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => $status, 'published_at' => $at, 'meta' => []]);
    $page->translations()->create(['locale' => 'vi', 'title' => 'T '.$slug, 'slug' => $slug, 'status' => $status === 'published' ? 'published' : 'draft', 'body' => ['blocks' => $blocks]]);

    return $page->fresh() ?? $page;
}

describe('«Xóa khối»', function (): void {
    it('takes a block out of the layout, and gives the body back to the writing area when only text is left', function (): void {
        $editor = egMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $text = ['type' => EntryProseBody::TYPE, 'props' => ['html' => '<p>Xin chào</p>']];
            $hero = ['type' => 'hero', 'props' => ['heading' => 'A']];
            $page = egPage('gioi-thieu', [$hero, $hero, $text]);

            $c = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid])
                ->assertSet('prose', false)
                ->call('removeBlock', 0)
                ->assertDispatched('bs-toast', text: __('entries.ed.block_removed'))
                ->assertSet('prose', false)->assertCount('blocks', 2);
            $c->call('removeBlock', 0)
                ->assertSet('prose', true)->assertSet('blocks', [])->assertSet('bodyHtml', '<p>Xin chào</p>');

            $empty = egPage('trong', [$hero]);
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $empty->ulid])
                ->call('removeBlock', 0)
                ->assertSet('prose', true)->assertSet('bodyHtml', '');
            // nothing is stored until the save
            expect($page->fresh()?->translationFor('vi')?->body['blocks'])->toHaveCount(3);
        });
    });

    it('empties the written text of this language and tells the writing area', function (): void {
        $editor = egMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $page = egPage('gioi-thieu', [['type' => EntryProseBody::TYPE, 'props' => ['html' => '<p>Xin chào</p>']]]);

            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid])
                ->assertSet('prose', true)
                ->call('clearProse')
                ->assertSet('bodyHtml', '')
                ->assertDispatched('admin-editor-set-html', target: 'bodyHtml', html: '');

            // a body built of blocks has no written text to empty: nothing changes
            $built = egPage('khoi', [['type' => 'hero', 'props' => []], ['type' => 'hero', 'props' => []]]);
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $built->ulid])
                ->call('clearProse')
                ->assertNotDispatched('admin-editor-set-html')->assertNotDispatched('bs-toast')->assertCount('blocks', 2);
        });
    });

    it('asks first and names the language in the browser', function (): void {
        $editor = egMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $page = egPage('gioi-thieu', [['type' => 'hero', 'props' => ['heading' => 'A']], ['type' => 'hero', 'props' => []]]);

            $html = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid])->html();
            expect($html)->toContain('$bsConfirm')->toContain(__('entries.ed.del_title'))
                ->toContain(e(__('entries.ed.del_block_text', ['lang' => 'Tiếng Việt', 'unit' => 'trang'])))
                ->not->toContain('wire:click="removeBlock');
        });
    });

    it('asks the policy again: an author whose page is no longer theirs is refused', function (): void {
        $author = egMember(['entries.view', 'entries.create', 'entries.edit.own']);

        app(Tenancy::class)->enter(1, function () use ($author): void {
            $page = egPage('gioi-thieu', [['type' => 'hero', 'props' => []], ['type' => 'hero', 'props' => []]]);
            $page->update(['author_id' => $author->id]);
            $blocks = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $page->ulid]);
            $text = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $page->ulid]);
            $page->update(['author_id' => null]);

            $blocks->call('removeBlock', 0)->assertForbidden();
            $text->call('clearProse')->assertForbidden();
        });
    });
});

describe('«Hẹn giờ đăng»', function (): void {
    beforeEach(function (): void {
        Carbon::setTestNow('2026-11-18 03:00:00 UTC');   // 10:00 in Viet Nam
    });

    it('shows the time a scheduled entry goes live in the website clock, or tomorrow 08:00 when it has none', function (): void {
        $editor = egMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $set = egPage('hen', status: 'scheduled', at: Carbon::parse('2026-11-25 02:30:00 UTC'));
            $none = egPage('chua', status: 'scheduled');

            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $set->ulid])
                ->assertSet('scheduleAt', '2026-11-25T09:30')
                ->assertSee(__('entries.ed.sched_zone', ['zone' => 'GMT+7']))
                ->assertSee(__('entries.ed.sched_note', ['unit' => 'Trang', 'time' => '09:30 25/11/2026']));
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $none->ulid])
                ->assertSet('scheduleAt', '2026-11-19T08:00');
            // a draft has no such field
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => egPage('nhap')->ulid])
                ->assertDontSeeHtml('id="entry-sched-at"');
        });
    });

    it('says an empty or past time as the field is left, and the save stops on it with nothing stored', function (): void {
        $editor = egMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $page = egPage('hen', status: 'scheduled', at: Carbon::parse('2026-11-25 02:30:00 UTC'));
            $c = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid]);

            $c->set('scheduleAt', '')->assertHasErrors(['scheduleAt'])
                ->assertSee(__('entries.sched_err_empty'));
            $c->set('scheduleAt', '2026-11-18T09:59')->assertHasErrors(['scheduleAt'])
                ->assertSee(__('entries.sched_err_past'));
            $c->set('title', 'Đổi tên')->call('save')->assertHasErrors(['scheduleAt'])->assertNotDispatched('bs-toast');
            expect($page->fresh()?->translationFor('vi')?->title)->toBe('T hen')
                ->and($page->fresh()?->published_at?->utc()->format('Y-m-d H:i'))->toBe('2026-11-25 02:30');
        });
    });

    it('keeps an existing time that has passed, and moves a changed future time', function (): void {
        $editor = egMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $late = egPage('tre', status: 'scheduled', at: Carbon::parse('2026-11-18 01:00:00 UTC'));
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $late->ulid])
                ->set('title', 'Đổi tên')->call('save')->assertHasNoErrors();
            expect($late->fresh()?->translationFor('vi')?->title)->toBe('Đổi tên')
                ->and($late->fresh()?->published_at?->utc()->format('Y-m-d H:i'))->toBe('2026-11-18 01:00');

            $page = egPage('hen', status: 'scheduled', at: Carbon::parse('2026-11-25 02:30:00 UTC'));
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid])
                ->set('scheduleAt', '2026-11-26T08:00')->assertHasNoErrors()
                ->call('save')->assertHasNoErrors()->assertSet('scheduleLoaded', '2026-11-26T08:00');
            expect($page->fresh()?->status)->toBe('scheduled')
                ->and($page->fresh()?->published_at?->utc()->format('Y-m-d H:i'))->toBe('2026-11-26 01:00');
        });
    });

    it('never schedules again an entry published or taken back to a draft since the form opened', function (): void {
        $editor = egMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $page = egPage('hen', status: 'scheduled', at: Carbon::parse('2026-11-25 02:30:00 UTC'));
            $c = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid])
                ->set('scheduleAt', '2026-11-26T08:00')->assertHasNoErrors();
            $page->update(['status' => 'published', 'published_at' => Carbon::parse('2026-11-18 02:00:00 UTC')]);

            $c->set('title', 'Đổi tên')->call('save')
                ->assertDispatched('bs-toast', text: __('entries.ed.sched_gone', ['unit' => 'trang']))
                ->assertSet('scheduleLoaded', '2026-11-25T09:30')
                // the form settles on the stored status: no field, nothing left unsaved, a second save is plain
                ->assertSet('status', 'published')->assertSet('scheduleAt', '2026-11-25T09:30')
                ->assertViewHas('changes', 0)->assertDontSeeHtml('id="entry-sched-at"');
            $c->call('save')->assertHasNoErrors()
                ->assertNotDispatched('bs-toast', text: __('entries.ed.sched_gone', ['unit' => 'trang']));
            expect($page->fresh()?->status)->toBe('published')
                ->and($page->fresh()?->published_at?->utc()->format('Y-m-d H:i'))->toBe('2026-11-18 02:00')
                ->and($page->fresh()?->translationFor('vi')?->title)->toBe('Đổi tên');
        });
    });

    it('counts a changed time as unsaved until it is moved, and not once the entry is published', function (): void {
        $editor = egMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $page = egPage('hen', status: 'scheduled', at: Carbon::parse('2026-11-25 02:30:00 UTC'));
            $c = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid])
                ->set('scheduleAt', '2026-11-26T08:00')->assertViewHas('changes', 1);
            // «Đăng» stores the text and publishes now: the time was never moved, and no longer counts
            $c->call('publish')->assertHasNoErrors()->assertSet('status', 'published')->assertViewHas('changes', 0);
            expect($page->fresh()?->status)->toBe('published');
        });
    });

    it('is not offered on a post governed by an approval flow', function (): void {
        $editor = egMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
            $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
            ContentSection::query()->create([
                'key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id,
                'permission_prefix' => 'entries', 'sort_order' => 1,
            ]);
            $post = Entry::query()->create(['type' => 'post', 'status' => 'scheduled', 'published_at' => Carbon::parse('2026-11-25 02:30:00 UTC'), 'meta' => []]);
            $post->translations()->create(['locale' => 'vi', 'title' => 'Bài', 'slug' => 'bai', 'status' => 'draft', 'body' => ['blocks' => []]]);
            $post->assignCategories([$category->id]);
            app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
                ->assertDontSeeHtml('id="entry-sched-at"')
                ->set('scheduleAt', '2026-11-30T08:00')->assertSet('scheduleAt', '2026-11-25T09:30');
        });
    });

    it('asks the policy again when the time is changed', function (): void {
        $author = egMember(['entries.view', 'entries.create', 'entries.edit.own', 'entries.publish']);

        app(Tenancy::class)->enter(1, function () use ($author): void {
            $page = egPage('hen', status: 'scheduled', at: Carbon::parse('2026-11-25 02:30:00 UTC'));
            $page->update(['author_id' => $author->id]);
            $c = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $page->ulid]);
            $page->update(['author_id' => null]);

            $c->set('scheduleAt', '2026-11-30T08:00')->assertForbidden();
        });
    });

    it('is not offered to a member who may not publish', function (): void {
        $writer = egMember(['entries.view', 'entries.create', 'entries.edit.any']);

        app(Tenancy::class)->enter(1, function () use ($writer): void {
            $page = egPage('hen', status: 'scheduled', at: Carbon::parse('2026-11-25 02:30:00 UTC'));
            Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $page->ulid])
                ->assertDontSeeHtml('id="entry-sched-at"')
                ->set('scheduleAt', '2026-11-30T08:00')->assertSet('scheduleAt', '2026-11-25T09:30')
                ->set('title', 'Đổi tên')->call('save');
            expect($page->fresh()?->published_at?->utc()->format('Y-m-d H:i'))->toBe('2026-11-25 02:30');
        });
    });
});

describe('the link', function (): void {
    it('follows the title of a new entry until it is typed or saved, and is made again when emptied', function (): void {
        $editor = egMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            egPage('gioi-thieu');

            $c = Livewire::actingAs($editor)->test(Editor::class)
                ->set('title', 'Giới thiệu')->assertSet('slug', 'gioi-thieu-2')
                ->set('title', 'Về chúng tôi')->assertSet('slug', 've-chung-toi');
            $c->set('slug', 've-cong-ty')->assertSet('slugTouched', true)
                ->set('title', 'Liên hệ')->assertSet('slug', 've-cong-ty');
            $c->set('slug', '')->assertSet('slug', 'lien-he')
                ->assertDispatched('bs-toast', text: __('entries.ed.slug_auto', ['slug' => 'lien-he']));
            $c->call('save')->assertHasNoErrors()->assertSet('slugFresh', false)
                ->set('title', 'Liên hệ ngay')->assertSet('slug', 'lien-he');
        });
    });

    it('never follows the title of a saved version, nor changes the Home page', function (): void {
        $editor = egMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $page = egPage('gioi-thieu');
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid])
                ->set('title', 'Về chúng tôi')->assertSet('slug', 'gioi-thieu');

            $home = egPage('home');
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $home->ulid])
                ->assertSet('pageHome', true)
                ->assertSee(__('entries.ed.slug_home_hint'))->assertDontSeeHtml('id="entry-slug"')
                ->set('slug', 'trang-chu')->assertSet('slug', 'home');
        });
    });

    it('keeps the Home page at the root in a language not written yet', function (): void {
        $editor = egMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true]);
            SiteLocale::query()->create(['locale' => 'ja', 'is_default' => false, 'enabled' => true]);
            $home = egPage('home');
            $c = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $home->ulid, 'locale' => 'en'])
                ->assertSet('pageHome', true)->assertSet('slug', 'home')
                ->set('title', 'Home page')->assertSet('slug', 'home')
                ->set('slug', '')->assertSet('slug', 'home');
            $c->call('save')->assertHasNoErrors();
            expect($home->fresh()?->translationFor('en')?->slug)->toBe('home');

            // a post may be called 'home' (under its category): the Home page still keeps the root
            $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => []]);
            $post->translations()->create(['locale' => 'ja', 'title' => 'Home', 'slug' => 'home', 'status' => 'draft', 'body' => ['blocks' => []]]);
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $home->ulid, 'locale' => 'ja'])
                ->assertSet('pageHome', true)->assertSet('slug', 'home');

            // another page already holds the root in this language: here the page is an ordinary page
            egPage('other')->translations()->create(['locale' => 'ja', 'title' => 'ホーム', 'slug' => 'home', 'status' => 'draft', 'body' => ['blocks' => []]]);
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $home->ulid, 'locale' => 'ja'])
                ->assertSet('pageHome', false)->assertSet('slug', '')
                ->set('title', 'Company')->assertSet('slug', 'company');
        });
    });
});

describe('«Thêm tùy chọn»', function (): void {
    it('opens «Thuộc tính trang» when the page has a parent, existing or preset, so the chosen parent shows', function (): void {
        $editor = egMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $parent = egPage('dich-vu');
            $child = egPage('thi-cong');
            $child->update(['parent_id' => $parent->id]);

            Livewire::actingAs($editor)->test(Editor::class, ['parent' => $parent->ulid])
                ->assertSet('parentUlid', $parent->ulid)->assertSeeHtml('data-more-open');
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $child->ulid])
                ->assertSet('parentUlid', $parent->ulid)->assertSeeHtml('data-more-open');
            // a top-level page keeps the member's own open or closed choice
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $parent->ulid])
                ->assertSet('parentUlid', null)->assertDontSeeHtml('data-more-open');
        });
    });
});
