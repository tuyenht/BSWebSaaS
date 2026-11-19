<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * The post and page editor's "Đăng" card as board 07 draws it: «Khi lưu, bài sẽ là» picks the status the next
 * save moves the entry to (through PublishEntry or TransitionEntryStatus, never written straight), «Hẹn giờ
 * đăng» starts on tomorrow 08:00 in the website's clock, a scheduled entry's band offers «Hủy hẹn giờ», and a
 * new entry or a draft saves itself (Editor::autosave) while live content never does.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    Carbon::setTestNow('2026-11-18 03:00:00 UTC');   // 10:00 in Viet Nam
});

function esMember(array $permissions = ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', $permissions));

    return $user->fresh() ?? $user;
}

/** A page of the current site, its vi version titled «T $slug». */
function esPage(string $slug, string $status = 'draft', ?Carbon $at = null): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => $status, 'published_at' => $at, 'meta' => []]);
    $page->translations()->create(['locale' => 'vi', 'title' => 'T '.$slug, 'slug' => $slug, 'status' => $status === 'published' ? 'published' : 'draft', 'body' => ['blocks' => []]]);

    return $page->fresh() ?? $page;
}

/** @return list<string> the option keys of «Khi lưu, … sẽ là» as drawn, with ':locked' after a locked one */
function esOptions(mixed $component): array
{
    $panel = $component->viewData('statusPanel');

    return $panel === null ? [] : array_map(static fn (array $o): string => $o['key'].($o['why'] !== '' ? ':locked' : ''), $panel['options']);
}

describe('«Khi lưu, … sẽ là»', function (): void {
    it('offers a publisher the board\'s statuses, «Gỡ khỏi website» only while live, and says what the saved one means', function (): void {
        $editor = esMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $draft = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => esPage('nhap')->ulid])
                ->assertSee(__('entries.ed.st_label_page'))->assertSee(__('entries.ed.vis_draft'))
                ->assertSeeHtml('data-status-btn');
            expect(esOptions($draft))->toBe(['draft', 'scheduled', 'published']);

            $live = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => esPage('song', 'published')->ulid]);
            expect(esOptions($live))->toBe(['draft', 'scheduled', 'published', 'archived']);
        });
    });

    it('offers a member who may only send for review «Gửi duyệt», and locks what they may not do with the reason', function (): void {
        $writer = esMember(['entries.view', 'entries.create', 'entries.edit.any', 'entries.submit']);

        app(Tenancy::class)->enter(1, function () use ($writer): void {
            $page = esPage('nhap');
            $c = Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $page->ulid]);
            expect(esOptions($c))->toBe(['draft', 'pending', 'scheduled:locked', 'published:locked']);

            $c->set('statusTo', 'scheduled')
                ->assertSet('statusTo', '')
                ->assertDispatched('bs-toast', text: __('entries.ed.st_why_schedule'));
            $c->set('statusTo', 'pending')->assertSet('statusTo', 'pending')
                ->call('save')->assertHasNoErrors()->assertSet('status', 'pending')->assertSet('statusTo', '');
            expect($page->fresh()?->status)->toBe('pending');
        });
    });

    it('starts «Hẹn giờ đăng» on tomorrow 08:00 in the website clock, and moves the entry only when saved', function (): void {
        $editor = esMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $page = esPage('nhap');
            $c = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid])
                ->set('statusTo', 'scheduled')
                ->assertSet('scheduleAt', '2026-11-19T08:00')
                ->assertSeeHtml('id="entry-sched-at"')
                ->assertSee(__('entries.ed.st_not_yet', ['status' => __('entries.ed.st_scheduled')]))
                ->assertViewHas('changes', 2);
            expect($page->fresh()?->status)->toBe('draft');

            $c->call('save')->assertHasNoErrors()
                ->assertSet('status', 'scheduled')->assertSet('statusTo', '')->assertSet('scheduleLoaded', '2026-11-19T08:00')
                ->assertViewHas('changes', 0)
                ->assertDispatched('bs-toast', text: __('entries.scheduled_set_at', ['date' => '19/11/2026', 'time' => '08:00']));
            expect($page->fresh()?->status)->toBe('scheduled')
                ->and($page->fresh()?->published_at?->utc()->format('Y-m-d H:i'))->toBe('2026-11-19 01:00');
        });
    });

    it('stops the save on an empty or past time, says it as the field is left, and stores nothing', function (): void {
        $editor = esMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $page = esPage('nhap');
            $c = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid])->set('statusTo', 'scheduled');
            $c->set('scheduleAt', '')->assertHasErrors(['scheduleAt'])->assertSee(__('entries.sched_err_empty'));
            $c->set('scheduleAt', '2026-11-18T09:00')->assertHasErrors(['scheduleAt'])->assertSee(__('entries.sched_err_past'));
            $c->set('title', 'Đổi tên')->call('save')->assertHasErrors(['scheduleAt'])->assertSet('status', 'draft');
            expect($page->fresh()?->status)->toBe('draft')
                ->and($page->fresh()?->translationFor('vi')?->title)->toBe('T nhap');
        });
    });

    it('keeps a scheduled entry\'s own time when its status is picked again', function (): void {
        $editor = esMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $page = esPage('hen', 'scheduled', Carbon::parse('2026-11-25 02:30:00 UTC'));
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid])
                ->set('statusTo', 'draft')->assertDontSeeHtml('id="entry-sched-at"')
                ->set('statusTo', 'scheduled')->assertSet('statusTo', '')->assertSet('scheduleAt', '2026-11-25T09:30')
                ->assertViewHas('changes', 0);
        });
    });

    it('takes live content off the website, or back to a draft, through the status rules', function (): void {
        $editor = esMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $off = esPage('go', 'published');
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $off->ulid])
                ->set('statusTo', 'archived')->call('save')->assertHasNoErrors()->assertSet('status', 'archived')
                ->assertDispatched('bs-toast', text: __('entries.ed.toast_kept', ['status' => __('entries.ed.pill_archived')]));
            expect($off->fresh()?->status)->toBe('archived');

            $back = esPage('ve', 'scheduled', Carbon::parse('2026-11-25 02:30:00 UTC'));
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $back->ulid])
                ->set('statusTo', 'draft')->call('save')->assertHasNoErrors()->assertSet('status', 'draft')->assertSet('scheduleAt', '');
            expect($back->fresh()?->status)->toBe('draft')->and($back->fresh()?->published_at)->toBeNull();
        });
    });

    it('publishes through the publish flow when «Công khai» is picked', function (): void {
        $editor = esMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $page = esPage('dang');
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid])
                ->set('statusTo', 'published')
                ->assertViewHas('actions', fn (array $a): bool => $a['primaryAction'] === 'publish' && $a['primary'] === __('entries.ed.update'))
                ->call('save')->assertHasNoErrors()->assertSet('status', 'published')->assertSet('statusTo', '');
            expect($page->fresh()?->status)->toBe('published')->and($page->fresh()?->published_at)->not->toBeNull();
        });
    });

    it('never moves the live Home page: every other status is locked with the board\'s reason', function (): void {
        $editor = esMember(['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish', 'entries.delete.any']);

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $home = esPage(PathBuilder::HOME_SLUG, 'published');
            $c = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $home->ulid])
                ->assertSee(__('entries.home_trash_why'));
            expect(esOptions($c))->toBe(['draft:locked', 'scheduled:locked', 'published', 'archived:locked']);

            $c->set('statusTo', 'scheduled')->assertSet('statusTo', '')
                ->assertDispatched('bs-toast', text: __('entries.home_trash_why'))
                ->call('save')->assertHasNoErrors();
            expect($home->fresh()?->status)->toBe('published');
        });
    });

    it('is not drawn where an approval workflow governs the post', function (): void {
        $editor = esMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
            $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
            ContentSection::query()->create([
                'key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id,
                'permission_prefix' => 'entries', 'sort_order' => 1,
            ]);
            $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => []]);
            $post->translations()->create(['locale' => 'vi', 'title' => 'Bài', 'slug' => 'bai', 'status' => 'draft', 'body' => ['blocks' => []]]);
            $post->assignCategories([$category->id]);
            app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
                ->assertViewHas('statusPanel', null)->assertDontSeeHtml('data-status-btn')
                ->set('statusTo', 'published')->assertSet('statusTo', '');
        });
    });

    it('asks the policy again when a status is picked', function (): void {
        $author = esMember(['entries.view', 'entries.create', 'entries.edit.own', 'entries.publish']);

        app(Tenancy::class)->enter(1, function () use ($author): void {
            $page = esPage('cua-toi');
            $page->update(['author_id' => $author->id]);
            $c = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $page->ulid]);
            $page->update(['author_id' => null]);

            $c->set('statusTo', 'published')->assertForbidden();
        });
    });
});

describe('«Khi lưu, … sẽ là» — what the server allows', function (): void {
    it('locks «Bản nháp» and «Gửi duyệt» for a member who may not publish once the entry is no longer a draft', function (): void {
        $writer = esMember(['entries.view', 'entries.create', 'entries.edit.any', 'entries.submit']);

        app(Tenancy::class)->enter(1, function () use ($writer): void {
            $page = esPage('cho', 'pending');
            $c = Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $page->ulid]);
            expect(esOptions($c))->toBe(['draft:locked', 'pending', 'scheduled:locked', 'published:locked']);
            $c->set('statusTo', 'draft')->assertSet('statusTo', '')
                ->assertDispatched('bs-toast', text: __('entries.ed.st_why_move'));
            expect($page->fresh()?->status)->toBe('pending');
        });
    });

    it('shows a publisher\'s entry waiting for review by its pill on the button', function (): void {
        $editor = esMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => esPage('cho', 'pending')->ulid])
                ->assertViewHas('statusPanel', fn (array $p): bool => $p['current'] === 'pending' && $p['currentShort'] === __('entries.ed.pill_pending'));
        });
    });

    it('will not schedule live content with unsaved changes: update or undo them first', function (): void {
        $editor = esMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $page = esPage('song', 'published');
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid])
                ->set('title', 'Đang sửa')
                ->set('statusTo', 'scheduled')->assertSet('statusTo', '')
                ->assertDispatched('bs-toast', text: __('entries.ed.sched_live_dirty', ['unit' => 'trang']))
                ->set('title', 'T song')
                ->set('statusTo', 'scheduled')->assertSet('statusTo', 'scheduled');
        });
    });

    it('drops «Hẹn giờ đăng» picked before the edits, and puts nothing on the website', function (): void {
        $editor = esMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $page = esPage('song', 'published');
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid])
                ->set('statusTo', 'scheduled')->assertSet('statusTo', 'scheduled')
                ->set('title', 'Đang sửa')
                ->call('save')
                ->assertDispatched('bs-toast', text: __('entries.ed.sched_live_dirty', ['unit' => 'trang']))
                ->assertSet('statusTo', '')->assertSet('status', 'published')->assertSet('title', 'Đang sửa');
            expect($page->fresh()?->status)->toBe('published')
                ->and($page->fresh()?->translationFor('vi')?->title)->toBe('T song');
        });
    });
});

describe('«Hủy hẹn giờ»', function (): void {
    it('shows a scheduled entry\'s band beside its time field, and takes it back to a draft', function (): void {
        $editor = esMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $page = esPage('hen', 'scheduled', Carbon::parse('2026-11-25 02:30:00 UTC'));
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid])
                ->assertSeeHtml('data-sched-band')->assertSeeHtml('id="entry-sched-at"')
                ->assertSee(__('entries.ed.sched_band', ['unit' => 'Trang', 'time' => '09:30 25/11/2026']))
                ->set('title', 'Còn đang gõ')
                ->call('unschedule')->assertHasNoErrors()
                ->assertSet('status', 'draft')->assertSet('scheduleAt', '')->assertSet('title', 'Còn đang gõ')
                ->assertDispatched('bs-toast', text: __('entries.unsched_done', ['noun' => 'trang', 'step' => __('entries.status_draft')]))
                ->assertDontSeeHtml('data-sched-band');
            expect($page->fresh()?->status)->toBe('draft')->and($page->fresh()?->published_at)->toBeNull()
                ->and($page->fresh()?->translationFor('vi')?->title)->toBe('T hen');
        });
    });

    it('leaves an entry published meanwhile as it is', function (): void {
        $editor = esMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $page = esPage('hen', 'scheduled', Carbon::parse('2026-11-25 02:30:00 UTC'));
            $c = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid]);
            $page->update(['status' => 'published']);

            $c->call('unschedule')->assertSet('status', 'published')
                ->assertDispatched('bs-toast', text: __('entries.ed.unsched_gone', ['unit' => 'trang']));
            expect($page->fresh()?->status)->toBe('published');
        });
    });

    it('is not offered to a member who may not publish, and refuses them', function (): void {
        $writer = esMember(['entries.view', 'entries.create', 'entries.edit.any']);

        app(Tenancy::class)->enter(1, function () use ($writer): void {
            $page = esPage('hen', 'scheduled', Carbon::parse('2026-11-25 02:30:00 UTC'));
            Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $page->ulid])
                ->assertDontSeeHtml('data-sched-band')
                ->call('unschedule');
            expect($page->fresh()?->status)->toBe('scheduled');
        });
    });
});

describe('autosave', function (): void {
    it('makes a new entry a draft only once its title, short description or body has text', function (): void {
        $editor = esMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $c = Livewire::actingAs($editor)->test(Editor::class)
                ->set('slug', 'chi-co-duong-dan')->call('autosave')->assertSet('ulid', null);
            expect(Entry::query()->count())->toBe(0);

            $c->set('slug', '')->set('title', 'Giới thiệu công ty')->call('autosave')->assertHasNoErrors()
                ->assertSet('status', 'draft')->assertSet('autoSaved', true)
                ->assertDispatched('bs-toast', text: __('entries.ed.toast_auto_first', ['unit' => 'trang']))
                ->assertSee(__('entries.ed.sv_auto', ['when' => '10:00']));
            $entry = Entry::query()->sole();
            expect($entry->status)->toBe('draft')->and($entry->translationFor('vi')?->title)->toBe('Giới thiệu công ty');

            // the link still follows the title of a draft that saved itself
            $c->set('title', 'Giới thiệu công ty Bảo Sơn')->assertSet('slug', 'gioi-thieu-cong-ty-bao-son')
                ->call('autosave');
            expect(Entry::query()->count())->toBe(1)
                ->and($entry->fresh()?->translationFor('vi')?->slug)->toBe('gioi-thieu-cong-ty-bao-son');
        });
    });

    it('saves a draft\'s text but never moves its status', function (): void {
        $editor = esMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $page = esPage('nhap');
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid])
                ->set('statusTo', 'published')->set('title', 'Tên mới')
                ->call('autosave')->assertHasNoErrors()->assertSet('status', 'draft')->assertSet('statusTo', 'published')
                ->assertNotDispatched('bs-toast');
            expect($page->fresh()?->status)->toBe('draft')
                ->and($page->fresh()?->translationFor('vi')?->title)->toBe('Tên mới');
        });
    });

    it('never saves content on the website, nor a scheduled entry', function (): void {
        $editor = esMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            foreach ([esPage('song', 'published'), esPage('hen', 'scheduled', Carbon::parse('2026-11-25 02:30:00 UTC'))] as $page) {
                Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid])
                    ->set('title', 'Đang gõ dở')->call('autosave')->assertSet('autoSaved', false);
                expect($page->fresh()?->translationFor('vi')?->title)->toBe($page->translationFor('vi')?->title);
            }
        });
    });

    it('leaves a field still to fix as it is, with no message added and nothing stored', function (): void {
        $editor = esMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            $page = esPage('nhap');
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $page->ulid])
                ->set('title', '')->call('autosave')
                ->assertHasNoErrors()->assertSet('saveFailed', false);
            expect($page->fresh()?->translationFor('vi')?->title)->toBe('T nhap');
        });
    });

    it('keeps a status picked but not saved counted as a change after the draft saved itself', function (): void {
        $editor = esMember();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => esPage('nhap')->ulid])
                ->set('statusTo', 'published')->set('title', 'Tên mới')
                ->call('autosave')->assertSet('autoSaved', true)
                ->assertViewHas('changes', 1)
                ->assertSet('saved.statusTo', '');
        });
    });

    it('runs again as a save by itself on «Giữ bản của tôi», never applying the status picked', function (): void {
        $mine = esMember();
        $other = esMember();

        app(Tenancy::class)->enter(1, function () use ($mine, $other): void {
            $page = esPage('nhap');
            $c = Livewire::actingAs($mine)->test(Editor::class, ['ulid' => $page->ulid]);
            Livewire::actingAs($other)->test(Editor::class, ['ulid' => $page->ulid])->set('title', 'Của người khác')->call('save');

            $this->actingAs($mine);
            $c->set('statusTo', 'published')->set('title', 'Của tôi')->call('autosave');
            expect($c->get('stale'))->not->toBeNull();

            $c->call('keepMine')->assertSet('stale', null)->assertSet('status', 'draft')->assertSet('statusTo', 'published');
            expect($page->fresh()?->status)->toBe('draft')
                ->and($page->fresh()?->translationFor('vi')?->title)->toBe('Của tôi');
        });
    });

    it('shows why on «Giữ bản của tôi» when the save by itself it runs again cannot be stored', function (): void {
        $mine = esMember();
        $other = esMember();

        app(Tenancy::class)->enter(1, function () use ($mine, $other): void {
            $page = esPage('nhap');
            $c = Livewire::actingAs($mine)->test(Editor::class, ['ulid' => $page->ulid]);
            Livewire::actingAs($other)->test(Editor::class, ['ulid' => $page->ulid])->set('title', 'Của người khác')->call('save');

            $this->actingAs($mine);
            $c->set('title', 'Của tôi')->call('autosave');
            expect($c->get('stale'))->not->toBeNull();

            $c->set('title', '')->call('keepMine')->assertHasErrors(['title'])->assertSet('saveFailed', true);
            expect($page->fresh()?->translationFor('vi')?->title)->toBe('Của người khác');
        });
    });

    it('asks the policy as a save does', function (): void {
        $author = esMember(['entries.view', 'entries.create', 'entries.edit.own']);

        app(Tenancy::class)->enter(1, function () use ($author): void {
            $page = esPage('cua-toi');
            $page->update(['author_id' => $author->id]);
            $c = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $page->ulid]);
            $page->update(['author_id' => null]);

            $c->set('title', 'Tên mới')->call('autosave')->assertForbidden();
            expect($page->fresh()?->translationFor('vi')?->title)->toBe('T cua-toi');
        });
    });
});
