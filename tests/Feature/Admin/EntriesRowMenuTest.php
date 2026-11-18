<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * A row's ⋮ and status menu on the posts and pages list (design board 05): «Xem trên website» for a
 * published row or «Xem trước» (the admin's preview) for any other, «Sao chép đường dẫn», «Sửa đường
 * dẫn» while the path column is off, and «Chuyển vào Thùng rác» locked with its reason where the
 * member may not use it on that row (the Home page; a colleague's record for who may remove only
 * their own) — the reason inside the menu, or in a bubble that takes no room on the row button.
 * The filters: «Trong phạm vi của tôi» first for a member limited to some categories, «Của tôi»
 * always, and a member who may edit only their own records opens on «Của tôi». A quick title
 * edit never empties a title; a rule's refusal stays until closed. «Hẹn giờ đăng» opens on tomorrow
 * 08:00 of the website's clock and keeps a wrong time on its field; «Hủy hẹn giờ» takes a scheduled
 * row back to a draft that the background publisher leaves alone. The Thùng rác tab of a member who
 * may move only their own records there lists and counts only their own.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** A member of site 1 with a role of $level holding $permissions. */
function rmMember(string $role, array $permissions, int $level = 40): User
{
    $user = User::factory()->create(['name' => Str::title($role).' '.Str::random(4)]);
    app(Tenancy::class)->enter(1, function () use ($user, $role, $level, $permissions): void {
        $granted = Role::findOrCreate($role, PermissionRegistry::GUARD);
        $granted->forceFill(['level' => $level, 'is_system' => true])->save();
        $granted->syncPermissions($permissions);
        $user->assignRole($granted);
    });
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

/** An entry of site 1 (enter the site first). */
function rmEntry(?User $author, string $title, string $status = 'draft', string $type = 'page', ?string $slug = null, ?Taxonomy $category = null): Entry
{
    $entry = Entry::query()->create([
        'type' => $type, 'status' => $status, 'author_id' => $author?->id, 'meta' => [],
        'published_at' => $status === 'published' ? now() : null,
    ]);
    $entry->translations()->create([
        'locale' => 'vi', 'title' => $title, 'slug' => $slug ?? Str::slug($title).'-'.Str::lower(Str::random(5)),
        'status' => $status === 'published' ? 'published' : 'draft', 'body' => ['blocks' => []],
    ]);
    if ($category !== null) {
        $entry->assignCategories([$category->id]);
    }

    return $entry->fresh() ?? $entry;
}

/** «Tin tức» with «Sự kiện» and «Tuyển dụng» below it (enter the site first). @return array{0: Taxonomy, 1: Taxonomy} */
function rmSection(): array
{
    $create = app(CreateTaxonomy::class);
    $root = $create->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Tin tức']));
    $events = $create->handle('post_cat', $root, new TaxonomyData(names: ['vi' => 'Sự kiện']));
    $jobs = $create->handle('post_cat', $root, new TaxonomyData(names: ['vi' => 'Tuyển dụng']));
    ContentSection::query()->create([
        'key' => 'tin-tuc', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $root->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);

    return [$events, $jobs];
}

/** The markup of one row (from its data-rk to the next row's). */
function rmRowHtml(string $html, string $ulid): string
{
    $start = strpos($html, 'data-rk="'.$ulid.'"');
    expect($start)->not->toBeFalse();
    $next = strpos($html, 'data-rk="', $start + 10);

    return substr($html, (int) $start, $next === false ? null : $next - $start);
}

/** @return list<string> the listed titles */
function rmTitles(mixed $list): array
{
    return collect($list->viewData('rows')->items())
        ->map(fn (array $row): string => (string) $row['entry']->translations->firstWhere('locale', 'vi')?->title)
        ->all();
}

describe('the row ⋮', function (): void {
    it('offers «Xem trước» (the admin preview) on a draft, «Xem trên website» on a published row, and «Sao chép đường dẫn» with the full address', function (): void {
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);

        app(Tenancy::class)->enter(1, function () use ($owner): void {
            $draft = rmEntry($owner, 'Bản nháp riêng', slug: 'ban-nhap-rieng');
            $live = rmEntry($owner, 'Giới thiệu', 'published', slug: 'gioi-thieu');

            $list = Livewire::actingAs($owner)->test(Index::class)->assertOk();
            $html = $list->html();
            $origin = $list->viewData('publicOrigin');

            expect($html)->toContain(e(route('admin.entries.preview', ['ulid' => $draft->ulid])))
                ->not->toContain(e(route('admin.entries.preview', ['ulid' => $live->ulid])))
                ->toContain(__('entries.view_on_site'))
                ->toContain(__('entries.action_preview'))
                ->toContain(__('entries.copy_link'))
                ->toContain((string) Js::from($origin.'/gioi-thieu'))
                ->and($origin)->toStartWith('http');
        });
    });

    it('offers a reviewer «Xem trước» only on a colleague’s post waiting at their step, read for the whole page at once', function (): void {
        $author = rmMember('author', ['entries.view', 'entries.create', 'entries.edit.own', 'entries.submit']);
        $reviewer = rmMember('reviewer', ['entries.view', 'entries.edit.own', 'entries.review', 'entries.approve']);

        app(Tenancy::class)->enter(1, function () use ($author, $reviewer): void {
            [$events] = rmSection();
            app(InstantiateWorkflow::class)->handle('admin_approval', 'tin-tuc', active: true);
            $root = Taxonomy::query()->findOrFail($events->parent_id);
            // a category of its own under the section for each post sent for review (made before anyone signs in)
            $categories = [];
            foreach (range(1, 8) as $i) {
                $categories[] = app(CreateTaxonomy::class)->handle('post_cat', $root, new TaxonomyData(names: ['vi' => "Mục {$i}"]));
            }
            $sendIn = function (int $count, string $tag) use ($author, &$categories): array {
                $ulids = [];
                foreach (range(1, $count) as $i) {
                    $post = rmEntry($author, "Bài chờ {$tag} {$i}", 'draft', 'post', category: array_shift($categories));
                    app(ApplyEntryWorkflowTransition::class)->handle($post, 'vi', $author, 'submit');
                    $ulids[] = $post->ulid;
                }

                return $ulids;
            };
            $draft = rmEntry($author, 'Bản nháp chưa gửi', 'draft', 'post', category: $events);
            $waiting = $sendIn(2, 'a');

            // a member who may edit only their own work opens on «Của tôi»: every author is asked for
            $few = countQueries(fn () => Livewire::actingAs($reviewer)->test(Index::class, ['section' => 'tin-tuc'])->call('resetFilterGroup', 'creator'));
            $waiting = [...$waiting, ...$sendIn(6, 'b')];
            $list = null;
            $many = countQueries(function () use ($reviewer, &$list): void {
                $list = Livewire::actingAs($reviewer)->test(Index::class, ['section' => 'tin-tuc'])->call('resetFilterGroup', 'creator');
            });

            $html = $list->html();
            foreach ($waiting as $ulid) {
                expect(rmRowHtml($html, $ulid))->toContain(e(route('admin.entries.preview', ['ulid' => $ulid])));
            }
            expect(rmRowHtml($html, $draft->ulid))->not->toContain(e(route('admin.entries.preview', ['ulid' => $draft->ulid])))
                ->and($many)->toBeLessThanOrEqual($few);
        });
    });

    it('offers «Sửa đường dẫn» for the row (shown while the path column is off), never for the Home page', function (): void {
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);

        app(Tenancy::class)->enter(1, function () use ($owner): void {
            $home = rmEntry($owner, 'Trang chủ', 'published', slug: 'home');
            $contact = rmEntry($owner, 'Liên hệ', 'published', slug: 'lien-he');

            $html = Livewire::actingAs($owner)->test(Index::class)->html();

            expect(rmRowHtml($html, $contact->ulid))->toContain('data-path-edit')->toContain('x-show="! vPath"')
                ->and(rmRowHtml($html, $home->ulid))->not->toContain('data-path-edit')
                ->and($html)->toContain(__('entries.edit_path'));
        });
    });

    it('locks «Chuyển vào Thùng rác» on the Home page with the reason, in the menu and on the row button', function (): void {
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);

        app(Tenancy::class)->enter(1, function () use ($owner): void {
            rmEntry($owner, 'Trang chủ', 'published', slug: 'home');

            $html = Livewire::actingAs($owner)->test(Index::class)->set('xbtn', true)->html();

            expect($html)->toContain(e(__('entries.home_trash_why')))
                ->toContain('class="list-lkw"')
                ->toContain('data-locked')
                ->not->toContain('wire:click="trash(');
        });
    });

    it('shows «Thùng rác» locked on a colleague’s record for a member who may remove only their own, and hides it from one with no Trash right', function (): void {
        $own = rmMember('own-publisher', ['entries.view', 'entries.create', 'entries.edit.own', 'entries.delete.own', 'entries.publish']);
        $viewer = rmMember('viewer', ['entries.view', 'entries.edit.any']);
        $colleague = rmMember('writer', ['entries.view']);

        app(Tenancy::class)->enter(1, function () use ($own, $viewer, $colleague): void {
            $theirs = rmEntry($colleague, 'Bài của đồng nghiệp');
            $mine = rmEntry($own, 'Bài của tôi');
            $why = __('entries.refused_others_delete', ['noun' => __('entries.noun_page')]);

            // (they open on «Của tôi»: everyone's rows first)
            $list = Livewire::actingAs($own)->test(Index::class)->set('xbtn', true)->call('resetFilterGroup', 'creator');
            $html = $list->html();
            expect($html)->toContain(e($why))
                ->toContain("wire:click=\"trash('{$mine->ulid}')\"")
                ->not->toContain("wire:click=\"trash('{$theirs->ulid}')\"")
                ->toContain('data-locked');

            // the server refuses it as well, whatever the screen shows
            $list->call('trash', $theirs->ulid, true);
            expect(Entry::query()->whereKey($theirs->id)->exists())->toBeTrue();

            $plain = Livewire::actingAs($viewer)->test(Index::class)->set('xbtn', true)->html();
            expect($plain)->not->toContain(e($why))->not->toContain('data-locked');
        });
    });

    it('names the pager in the member’s language', function (): void {
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);

        app(Tenancy::class)->enter(1, function () use ($owner): void {
            rmEntry($owner, 'Một trang');
            $html = Livewire::actingAs($owner)->test(Index::class)->html();

            expect($html)->toContain('<nav aria-label="'.e(__('admin.pagination.nav')).'" data-guide="pager"')
                ->not->toContain('aria-label="Pagination"');
        });
    });
});

describe('the filters', function (): void {
    it('offers «Trong phạm vi của tôi» first to a member limited to some categories, and it keeps the posts of those categories', function (): void {
        $template = PermissionRegistry::tenantRoleTemplates('corporate');
        $limited = rmMember('editor', $template['editor']['permissions'], 50);
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);

        app(Tenancy::class)->enter(1, function () use ($limited, $owner): void {
            [$events, $jobs] = rmSection();
            MemberCategoryScope::query()->create(['user_id' => $limited->id, 'taxonomy_id' => $events->id]);
            app(CategoryScopes::class)->forget();
            rmEntry($owner, 'Hội chợ máy', 'published', 'post', category: $events);
            rmEntry($owner, 'Tuyển thợ hàn', 'published', 'post', category: $jobs);

            $list = Livewire::actingAs($limited)->test(Index::class, ['section' => 'tin-tuc']);
            $cat = $list->viewData('catOptions');
            expect($cat[0]['value'])->toBe(Index::CAT_SCOPE)
                ->and($cat[0]['label'])->toBe(__('entries.f_scope'))
                ->and(rmTitles($list))->toEqualCanonicalizing(['Hội chợ máy', 'Tuyển thợ hàn']);

            $list->set('catF', [Index::CAT_SCOPE]);
            expect(rmTitles($list))->toBe(['Hội chợ máy'])
                ->and($list->get('catF'))->toBe([Index::CAT_SCOPE])
                ->and($list->html())->toContain(e(__('entries.f_scope')));

            // a member with no limits never gets it, and cannot keep it from the address
            $free = Livewire::actingAs($owner)->test(Index::class, ['section' => 'tin-tuc'])->set('catF', [Index::CAT_SCOPE]);
            expect(collect($free->viewData('catOptions'))->pluck('value'))->not->toContain(Index::CAT_SCOPE)
                ->and($free->get('catF'))->toBe([])
                ->and(rmTitles($free))->toHaveCount(2);
        });
    });

    it('always offers «Của tôi» first among the authors, even before the member has written anything', function (): void {
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);
        $a = rmMember('writer-a', ['entries.view']);
        $b = rmMember('writer-b', ['entries.view']);

        app(Tenancy::class)->enter(1, function () use ($owner, $a, $b): void {
            rmEntry($a, 'Trang A');
            rmEntry($b, 'Trang B');

            $list = Livewire::actingAs($owner)->test(Index::class);
            $creators = $list->viewData('facets')['creator'];
            expect($creators[0]['value'])->toBe((string) $owner->ulid)
                ->and($creators[0]['label'])->toBe(__('entries.f_mine', ['name' => $owner->name]))
                ->and(Index::creatorShown($creators))->toBeTrue();

            // one other author only: the filter stays away, the member's own added line does not count
            Entry::query()->whereHas('translations', fn ($q) => $q->where('title', 'Trang B'))->firstOrFail()->forceDelete();
            $one = Livewire::actingAs($owner)->test(Index::class)->viewData('facets')['creator'];
            expect(Index::creatorShown($one))->toBeFalse();
        });
    });

    it('opens on «Của tôi» for a member who may edit only their own records, until they take it off', function (): void {
        $own = rmMember('own-publisher', ['entries.view', 'entries.create', 'entries.edit.own', 'entries.delete.own']);
        $other = rmMember('writer', ['entries.view']);
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);

        app(Tenancy::class)->enter(1, function () use ($own, $other, $owner): void {
            rmEntry($own, 'Trang của tôi');
            rmEntry($other, 'Trang của người khác');

            $list = Livewire::actingAs($own)->test(Index::class);
            expect($list->get('creatorF'))->toBe([(string) $own->ulid])
                ->and(rmTitles($list))->toBe(['Trang của tôi']);

            // taken off: the next visit opens on everyone's, and back on once put on again
            $list->call('resetFilterGroup', 'creator');
            expect(Livewire::actingAs($own->fresh())->test(Index::class)->get('creatorF'))->toBe([]);
            Livewire::actingAs($own->fresh())->test(Index::class)->call('toggleFilterValue', 'creator', (string) $own->ulid);
            expect(Livewire::actingAs($own->fresh())->test(Index::class)->get('creatorF'))->toBe([(string) $own->ulid]);

            // a member who may edit everyone's opens on everyone's
            expect(Livewire::actingAs($owner)->test(Index::class)->get('creatorF'))->toBe([]);

            // an address that chooses filters wins
            $byAddress = Livewire::withQueryParams(['lang' => ['vi']])->actingAs($own->fresh())->test(Index::class);
            expect($byAddress->get('creatorF'))->toBe([]);
        });
    });
});

describe('quick title edit', function (): void {
    it('keeps the old title when the new one is empty, and says so', function (): void {
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);

        app(Tenancy::class)->enter(1, function () use ($owner): void {
            $page = rmEntry($owner, 'Tiêu đề cũ');

            Livewire::actingAs($owner)->test(Index::class)
                ->call('saveTitle', $page->ulid, '   ')
                ->assertDispatched('bs-toast', fn (string $name, array $p): bool => $p['text'] === __('entries.title_empty_kept'));

            expect($page->fresh()->translationFor('vi')?->title)->toBe('Tiêu đề cũ');
        });
    });

    it('keeps a rule’s refusal of a quick edit on screen until it is closed', function (): void {
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);

        app(Tenancy::class)->enter(1, function () use ($owner): void {
            $page = rmEntry($owner, 'Tiêu đề cũ');

            Livewire::actingAs($owner)->test(Index::class)
                ->call('saveTitle', $page->ulid, str_repeat('Rất dài ', 60))
                ->assertDispatched('bs-toast', fn (string $name, array $p): bool => ($p['stay'] ?? false) === true);

            expect($page->fresh()->translationFor('vi')?->title)->toBe('Tiêu đề cũ');
        });
    });

    it('starts the field from the title the row shows, and Esc puts it back', function (): void {
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);

        app(Tenancy::class)->enter(1, function () use ($owner): void {
            rmEntry($owner, 'Tiêu đề hiện tại');
            $html = Livewire::actingAs($owner)->test(Index::class)->html();

            expect($html)->toContain('data-t="Tiêu đề hiện tại"')
                ->toContain('@keydown.escape="cancel = true; value = orig; $el.blur()"');
        });
    });
});

describe('scheduling', function (): void {
    it('opens «Hẹn giờ đăng» on tomorrow 08:00 of the website’s clock', function (): void {
        // 20:30 UTC on the 18th is 03:30 on the 19th in Viet Nam (the website's zone): tomorrow is the 20th
        Carbon::setTestNow('2026-11-18 20:30:00 UTC');
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);

        app(Tenancy::class)->enter(1, function () use ($owner): void {
            rmEntry($owner, 'Bài hẹn giờ');
            $list = Livewire::actingAs($owner)->test(Index::class);

            expect($list->viewData('schedDefault'))->toBe('2026-11-20T08:00')
                ->and($list->html())->toContain(e(__('entries.sched_title')))->toContain('2026-11-20T08:00');
        });
    });

    it('keeps an empty or past time on the field, and schedules a future one', function (): void {
        Carbon::setTestNow('2026-11-18 03:00:00 UTC'); // 10:00 in Viet Nam
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);

        app(Tenancy::class)->enter(1, function () use ($owner): void {
            $page = rmEntry($owner, 'Bài hẹn giờ');
            $list = Livewire::actingAs($owner)->test(Index::class);

            $list->call('scheduleEntry', $page->ulid, '')->assertReturned(__('entries.sched_err_empty'))->assertNotDispatched('bs-toast');
            $list->call('scheduleEntry', $page->ulid, '2026-11-18T09:59')->assertReturned(__('entries.sched_err_past'))->assertNotDispatched('bs-toast');
            expect($page->fresh()->status)->toBe('draft');

            $list->call('scheduleEntry', $page->ulid, '2026-11-19T08:00')->assertReturned(null)
                ->assertDispatched('bs-toast', fn (string $name, array $p): bool => $p['text'] === __('entries.scheduled_set_at', ['date' => '19/11/2026', 'time' => '08:00']));
            $fresh = $page->fresh();
            expect($fresh->status)->toBe('scheduled')
                ->and($fresh->published_at?->utc()->format('Y-m-d H:i'))->toBe('2026-11-19 01:00');
        });
    });

    it('takes a scheduled row back to a draft with «Hủy hẹn giờ», which the background publisher then leaves alone', function (): void {
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);

        app(Tenancy::class)->enter(1, function () use ($owner): void {
            $page = rmEntry($owner, 'Bài hẹn giờ');
            $page->forceFill(['status' => 'scheduled', 'published_at' => now()->addHour()])->save();

            $list = Livewire::actingAs($owner)->test(Index::class);
            expect($list->html())->toContain('data-unsched')->toContain(e(__('entries.unsched_title')));

            $list->call('unschedule', $page->ulid)
                ->assertDispatched('bs-toast', fn (string $name, array $p): bool => $p['text'] === __('entries.unsched_done', ['noun' => __('entries.noun_page'), 'step' => __('entries.status_draft')]));
            $fresh = $page->fresh();
            expect($fresh->status)->toBe('draft')->and($fresh->published_at)->toBeNull();
        });

        Carbon::setTestNow(now()->addHours(2));
        $this->artisan('entries:publish-due')->assertSuccessful();

        app(Tenancy::class)->enter(1, function (): void {
            expect(Entry::query()->whereHas('translations', fn ($q) => $q->where('title', 'Bài hẹn giờ'))->firstOrFail()->status)->toBe('draft');
        });
    });

    it('refuses «Hủy hẹn giờ» to a member without the publish right, and leaves a row that is no longer scheduled as it is', function (): void {
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);
        $writer = rmMember('writer', ['entries.view', 'entries.edit.any']);

        app(Tenancy::class)->enter(1, function () use ($owner, $writer): void {
            $page = rmEntry($owner, 'Bài hẹn giờ');
            $page->forceFill(['status' => 'scheduled', 'published_at' => now()->addHour()])->save();

            Livewire::actingAs($writer)->test(Index::class)
                ->assertDontSee(__('entries.unsched_title'))
                ->call('unschedule', $page->ulid)
                ->assertDispatched('bs-toast', fn (string $name, array $p): bool => ($p['stay'] ?? false) === true);
            expect($page->fresh()->status)->toBe('scheduled');

            $live = rmEntry($owner, 'Đã lên website', 'published');
            Livewire::actingAs($owner)->test(Index::class)->call('unschedule', $live->ulid);
            expect($live->fresh()->status)->toBe('published');
        });
    });

    it('hides «Hẹn giờ đăng» on the live Home page, and refuses it there with the reason, kept until closed', function (): void {
        Carbon::setTestNow('2026-11-18 03:00:00 UTC'); // 10:00 in Viet Nam
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);

        app(Tenancy::class)->enter(1, function () use ($owner): void {
            $home = rmEntry($owner, 'Trang chủ', 'published', slug: 'home');
            $other = rmEntry($owner, 'Liên hệ', 'published');
            $list = Livewire::actingAs($owner)->test(Index::class);
            $html = $list->html();

            expect(rmRowHtml($html, $home->ulid))->not->toContain('data-sched x-on:click')
                ->and(rmRowHtml($html, $other->ulid))->toContain('data-sched x-on:click');

            // a call made anyway (a tab opened before the page became the Home page) is refused by the action
            $list->call('scheduleEntry', $home->ulid, '2026-11-19T08:00')->assertReturned(null)
                ->assertDispatched('bs-toast', text: __('entries.home_sched_why'), stay: true);
            expect($home->fresh()->status)->toBe('published')->and($home->fresh()->published_at?->isFuture())->toBeFalse();
        });
    });

    it('lists «Đã đăng» plus draft and private greyed with the reason on the live Home page, refuses a draft or private there with the reason kept until closed, and «/» stays live', function (): void {
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);
        $menuItem = static fn (Entry $entry, string $status): string => '$wire.changeStatus('.Js::from($entry->ulid).', '.Js::from($status).')';

        app(Tenancy::class)->enter(1, function () use ($owner, $menuItem): void {
            $home = rmEntry($owner, 'Trang chủ', 'published', slug: 'home');
            SiteRoute::query()->create(['locale' => 'vi', 'path' => '/', 'entry_id' => $home->id]);
            $other = rmEntry($owner, 'Liên hệ', 'published');
            $list = Livewire::actingAs($owner)->test(Index::class);
            $html = $list->html();

            $homeRow = rmRowHtml($html, $home->ulid);
            expect($homeRow)->toContain($menuItem($home, 'published'))
                ->not->toContain($menuItem($home, 'draft'))
                ->not->toContain($menuItem($home, 'private'))
                ->toContain('data-home-locked="draft"')->toContain('data-home-locked="private"')
                ->and(substr_count($homeRow, 'aria-disabled="true"'))->toBeGreaterThanOrEqual(2)
                ->and(substr_count($homeRow, '<span class="list-mwhy">'.e(__('entries.home_trash_why')).'</span>'))->toBe(2)
                ->and(rmRowHtml($html, $other->ulid))->not->toContain('data-home-locked')
                ->and(rmRowHtml($html, $other->ulid))->toContain($menuItem($other, 'draft'))->toContain($menuItem($other, 'private'));

            // a call made anyway (a tab opened before the page became the Home page) is refused by the action, with why
            foreach (['draft', 'private'] as $status) {
                $list->call('changeStatus', $home->ulid, $status)
                    ->assertDispatched('bs-toast', text: __('entries.home_offline_why'), stay: true);
                expect($home->fresh()->status)->toBe('published');
            }

            // another page still changes as asked
            $list->call('changeStatus', $other->ulid, 'draft')->assertDispatched('bs-toast', text: __('entries.status_changed'));
            expect($other->fresh()->status)->toBe('draft');
        });

        $this->get('http://a.test/')->assertOk();
    });

    it('offers every status on a Home page not yet on the website, and publishes it', function (): void {
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);

        app(Tenancy::class)->enter(1, function () use ($owner): void {
            $home = rmEntry($owner, 'Trang chủ', 'draft', slug: 'home');
            $list = Livewire::actingAs($owner)->test(Index::class);

            expect(rmRowHtml($list->html(), $home->ulid))->toContain('$wire.changeStatus('.Js::from($home->ulid).', '.Js::from('private').')');

            $list->call('changeStatus', $home->ulid, 'published')->assertDispatched('bs-toast', text: __('entries.status_changed'));
            expect($home->fresh()->status)->toBe('published');
        });

        $this->get('http://a.test/')->assertOk();
    });
});

describe('the Trash tab', function (): void {
    it('lists and counts only their own trashed records for a member who may remove only their own, everyone’s for one who may remove any', function (): void {
        $own = rmMember('own-publisher', ['entries.view', 'entries.create', 'entries.edit.own', 'entries.delete.own']);
        $owner = rmMember('owner', PermissionRegistry::TENANT_PERMISSIONS, 90);
        $viewer = rmMember('viewer', ['entries.view']);

        app(Tenancy::class)->enter(1, function () use ($own, $owner, $viewer): void {
            $mine = rmEntry($own, 'Trang tôi đã bỏ');
            $theirs = rmEntry($owner, 'Trang người khác đã bỏ');
            $mine->delete();
            $theirs->delete();

            $list = Livewire::actingAs($own)->test(Index::class)->call('resetFilterGroup', 'creator')->call('setTab', 'trash');
            expect(rmTitles($list))->toBe(['Trang tôi đã bỏ'])
                ->and($list->viewData('counts')['trash'])->toBe(1);

            // restoring a colleague's record is refused, as before
            $list->call('restore', $theirs->ulid);
            expect(Entry::onlyTrashed()->whereKey($theirs->id)->exists())->toBeTrue();
            $list->call('restore', $mine->ulid);
            expect(Entry::query()->whereKey($mine->id)->exists())->toBeTrue();

            $all = Livewire::actingAs($owner)->test(Index::class)->call('setTab', 'trash');
            expect(rmTitles($all))->toBe(['Trang người khác đã bỏ'])
                ->and($all->viewData('counts')['trash'])->toBe(1);
            $mine->delete();
            expect(rmTitles(Livewire::actingAs($owner)->test(Index::class)->call('setTab', 'trash')))->toHaveCount(2);

            // a member with no Trash right has no tab, and an address that asks for it lists nothing
            $none = Livewire::withQueryParams(['tab' => 'trash'])->actingAs($viewer)->test(Index::class);
            expect(rmTitles($none))->toBe([]);
        });
    });
});
