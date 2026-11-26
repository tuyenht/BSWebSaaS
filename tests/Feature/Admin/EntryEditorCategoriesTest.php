<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The post editor's «Chuyên mục» card (board 07): a post filed under several categories, the first
 * the main one (its address and its section follow it), chosen by key only and never another
 * website's; a member limited to some categories adds and takes out only those inside them and keeps
 * the stored ones outside (read again under the post's row lock at save); another section's
 * categories locked while an approval workflow governs the post's section; «Thêm chuyên mục mới»
 * for who may manage categories; one read of the categories however many there are.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

/** A member of site 1 holding a corporate template role. */
function eccMember(string $role): User
{
    $template = PermissionRegistry::tenantRoleTemplates('corporate')[$role];
    $permissions = $template['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $template['permissions'];
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user, $role, $template, $permissions): void {
        $granted = Role::findOrCreate($role, PermissionRegistry::GUARD);
        $granted->forceFill(['level' => $template['level'], 'is_system' => true])->save();
        $granted->syncPermissions($permissions);
        $user->assignRole($granted);
    });

    return $user->fresh() ?? $user;
}

function eccCategory(string $name, ?Taxonomy $parent = null, int $site = 1): Taxonomy
{
    return app(Tenancy::class)->enter($site, fn (): Taxonomy => app(CreateTaxonomy::class)->handle(
        'post_cat', $parent, new TaxonomyData(names: ['vi' => $name]),
    ));
}

/** A post section `$key` built on a new category named $name (its own category, not a row of the card). */
function eccSection(string $key, string $name, int $order = 1): Taxonomy
{
    $root = eccCategory($name);
    app(Tenancy::class)->enter(1, fn () => ContentSection::query()->create([
        'key' => $key, 'label' => $name, 'entry_type' => 'post', 'taxonomy_id' => $root->id,
        'permission_prefix' => 'entries', 'sort_order' => $order,
    ]));

    return $root;
}

function eccLimit(User $user, Taxonomy ...$categories): void
{
    app(Tenancy::class)->enter(1, function () use ($user, $categories): void {
        foreach ($categories as $category) {
            MemberCategoryScope::query()->create(['user_id' => $user->id, 'taxonomy_id' => $category->id]);
        }
        app(CategoryScopes::class)->forget();
    });
}

function eccPost(?User $author, Taxonomy ...$categories): Entry
{
    return app(Tenancy::class)->enter(1, function () use ($author, $categories): Entry {
        $slug = 'bai-'.Str::lower(Str::random(8));
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author?->id, 'meta' => []]);
        $entry->translations()->create([
            'locale' => 'vi', 'title' => 'Bài '.$slug, 'slug' => $slug, 'status' => 'draft',
            'body' => ['blocks' => [['type' => 'richtext', 'props' => ['body' => 'Nội dung']]]],
        ]);
        $entry->assignCategories(array_map(fn (Taxonomy $c): int => $c->id, $categories));

        return $entry->fresh() ?? $entry;
    });
}

/** Run $read on site 1 — inside the site already entered when there is one (a nested enter would leave it on return). */
function eccOnSite(Closure $read): mixed
{
    return app(CurrentSite::class)->hasId() ? $read() : app(Tenancy::class)->enter(1, $read);
}

/** @return list<int> the post's categories, the main one first (facet terms left out) */
function eccCategoriesOf(Entry $entry): array
{
    return eccOnSite(fn (): array => array_map('intval', DB::table('entry_taxonomies')
        ->where('entry_id', $entry->id)->where('position', '<', Entry::FACET_POSITION_BASE)->orderBy('position')->pluck('taxonomy_id')->all()));
}

function eccSlug(Taxonomy $category): string
{
    return (string) eccOnSite(fn () => DB::table('taxonomy_translations')->where('taxonomy_id', $category->id)->where('locale', 'vi')->value('slug'));
}

/** @return array<string, array<string, mixed>> the card's rows by category key */
function eccRows(mixed $editor): array
{
    return array_column($editor->viewData('categoryCard')['rows'], null, 'ulid');
}

it('files a post under every ticked category, the first one the main one, and the address follows it', function (): void {
    $root = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $root);
    $notices = eccCategory('Thông báo', $root);
    $editor = eccMember('editor');
    // the section's posts live under their main category's address
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'tin-tuc', 'value' => ['pattern' => 'category']]));

    app(Tenancy::class)->enter(1, function () use ($editor, $root, $events, $notices): void {
        $form = Livewire::actingAs($editor)->test(Editor::class, ['section' => 'tin-tuc'])
            ->assertSet('categoryIds', [$root->id])
            ->assertSee(__('entries.ed.cat_note_none', ['unit' => 'Tin tức', 'section' => 'Tin tức']))
            ->assertDontSeeHtml("toggleCategory('{$root->ulid}')");

        // the section's own category gives way to the first one ticked
        $form->call('toggleCategory', $events->ulid)->call('toggleCategory', $notices->ulid)
            ->assertSet('categoryIds', [$events->id, $notices->id])->assertSet('categoryId', $events->id)
            ->assertSee(__('entries.ed.cat_main'));
        expect($form->get('address')['base'])->toContain(eccSlug($events));

        $form->call('setMainCategory', $notices->ulid)->assertDispatched('bs-toast')
            ->assertSet('categoryIds', [$notices->id, $events->id]);
        expect($form->get('address')['base'])->toContain(eccSlug($notices))->not->toContain(eccSlug($events));

        $form->set('title', 'Khai trương xưởng')->set('slug', 'khai-truong-xuong')->call('save')->assertHasNoErrors();
        $post = Entry::query()->sole();
        expect(eccCategoriesOf($post))->toBe([$notices->id, $events->id]);

        // reopened: the same order; the main one unticked, the next takes its place; none left, the section's own
        $again = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])->assertSet('categoryIds', [$notices->id, $events->id]);
        $again->call('toggleCategory', $notices->ulid)->assertSet('categoryIds', [$events->id])->assertSet('categoryId', $events->id)
            ->call('toggleCategory', $events->ulid)->assertSet('categoryIds', [$root->id])->call('save')->assertHasNoErrors();
        expect(eccCategoriesOf($post))->toBe([$root->id]);
    });
});

it('never takes another website\'s category, nor a section\'s own one, by key', function (): void {
    eccSection('tin-tuc', 'Tin tức');
    $other = eccSection('tuyen-dung', 'Tuyển dụng', 2);
    $foreign = eccCategory('Tin site B', null, 2);
    $editor = eccMember('editor');

    app(Tenancy::class)->enter(1, function () use ($editor, $other, $foreign): void {
        Livewire::actingAs($editor)->test(Editor::class, ['section' => 'tin-tuc'])->call('toggleCategory', $foreign->ulid)->assertStatus(404);
        Livewire::actingAs($editor)->test(Editor::class, ['section' => 'tin-tuc'])->call('toggleCategory', $other->ulid)->assertStatus(404);
        Livewire::actingAs($editor)->test(Editor::class, ['section' => 'tin-tuc'])->call('setMainCategory', $foreign->ulid)->assertStatus(404);
        // the categoryIds list is the server's: a client cannot write it
        expect(fn () => Livewire::actingAs($editor)->test(Editor::class, ['section' => 'tin-tuc'])->set('categoryIds', [$foreign->id]))
            ->toThrow(Exception::class);
    });

    expect(app(Tenancy::class)->enter(1, fn () => Entry::query()->count()))->toBe(0);
});

it('lets a limited member add and take out only categories inside the limits, keeping a stored one outside', function (): void {
    $root = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $root);
    $fairs = eccCategory('Hội chợ', $events);
    $notices = eccCategory('Thông báo', $root);
    $hidden = eccCategory('Nội bộ', $root);
    $lan = eccMember('editor');
    eccLimit($lan, $events);
    $post = eccPost($lan, $events, $notices);

    app(Tenancy::class)->enter(1, function () use ($lan, $post, $events, $fairs, $notices, $hidden): void {
        $form = Livewire::actingAs($lan)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSee(app(CategoryScopes::class)->refusal($lan));
        $rows = eccRows($form);
        expect($rows[$notices->ulid]['locked'])->toBeTrue()
            ->and($rows[$notices->ulid]['why'])->toBe(__('entries.ed.cat_why_scope'))
            ->and($rows[$events->ulid]['locked'])->toBeFalse()
            ->and($rows)->not->toHaveKey($hidden->ulid);   // outside the limits and not ticked: not shown

        $form->call('toggleCategory', $notices->ulid)->assertForbidden();
        Livewire::actingAs($lan)->test(Editor::class, ['ulid' => $post->ulid])->call('setMainCategory', $notices->ulid)->assertForbidden();
        Livewire::actingAs($lan)->test(Editor::class, ['ulid' => $post->ulid])->call('toggleCategory', $hidden->ulid)->assertForbidden();

        Livewire::actingAs($lan)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('toggleCategory', $fairs->ulid)->call('save')->assertHasNoErrors();
        expect(eccCategoriesOf($post))->toBe([$events->id, $notices->id, $fairs->id]);

        Livewire::actingAs($lan)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('toggleCategory', $fairs->ulid)->call('save')->assertHasNoErrors();
        expect(eccCategoriesOf($post))->toBe([$events->id, $notices->id]);
    });
});

it('does not file a post again under a category outside the limits taken out elsewhere while the form was open', function (): void {
    $root = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $root);
    $fairs = eccCategory('Hội chợ', $events);
    $notices = eccCategory('Thông báo', $root);
    $lan = eccMember('editor');
    eccLimit($lan, $events);
    $post = eccPost($lan, $events, $notices);

    app(Tenancy::class)->enter(1, function () use ($lan, $post, $events, $fairs, $notices): void {
        $form = Livewire::actingAs($lan)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSet('categoryIds', [$events->id, $notices->id])
            ->call('toggleCategory', $fairs->ulid);

        // someone else takes the post out of «Thông báo» while this save waits for the post's row
        $done = false;
        DB::listen(function ($query) use (&$done, $post, $notices): void {
            if (! $done && str_contains($query->sql, 'for update') && str_contains($query->sql, '"entries"')) {
                $done = true;
                DB::table('entry_taxonomies')->where('entry_id', $post->id)->where('taxonomy_id', $notices->id)->delete();
            }
        });
        // the categories changed under the save: it asks first, nothing written
        $form->call('save');
        expect($done)->toBeTrue()->and($form->get('stale'))->not->toBeNull()
            ->and(eccCategoriesOf($post))->toBe([$events->id]);

        // «keep mine»: the list is held against what is stored now, «Thông báo» not filed again
        $form->call('keepMine')->assertHasNoErrors()->assertSet('categoryIds', [$events->id, $fairs->id]);
        expect(eccCategoriesOf($post))->toBe([$events->id, $fairs->id]);
    });
});

it('refuses to take out the last category a limited member may lead with, the list kept as it was', function (): void {
    $root = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $root);
    $notices = eccCategory('Thông báo', $root);
    $lan = eccMember('editor');
    eccLimit($lan, $events);
    $post = eccPost($lan, $events, $notices);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($lan)->test(Editor::class, ['ulid' => $post->ulid])
        ->call('toggleCategory', $events->ulid)
        ->assertHasErrors(['categoryIds'])
        ->assertSet('categoryIds', [$events->id, $notices->id])->assertSet('categoryId', $events->id));
});

it('refuses to take out a limited member\'s only category when the section\'s own one is outside the limits', function (): void {
    $root = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $root);
    $lan = eccMember('editor');
    eccLimit($lan, $events);
    $post = eccPost($lan, $events);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($lan)->test(Editor::class, ['ulid' => $post->ulid])
        ->call('toggleCategory', $events->ulid)
        ->assertHasErrors(['categoryIds'])
        ->assertSet('categoryIds', [$events->id])->assertSet('categoryId', $events->id));
});

it('files a new post under a section\'s own category only as the main one, whatever path the list comes by', function (): void {
    $root = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $root);
    $editor = eccMember('editor');

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($editor)->test(Editor::class, ['section' => 'tin-tuc'])
        ->call('restoreKept', ['categoryIds' => [$events->id, $root->id]])
        ->assertSet('categoryIds', [$events->id])
        ->set('title', 'Hội thảo')->set('slug', 'hoi-thao')->call('save')->assertHasNoErrors());

    expect(eccCategoriesOf(app(Tenancy::class)->enter(1, fn () => Entry::query()->sole())))->toBe([$events->id]);
});

it('opens a new post of a limited member on the first category of the section inside the limits', function (): void {
    $root = eccSection('tin-tuc', 'Tin tức');
    eccCategory('Thông báo', $root);
    $events = eccCategory('Sự kiện', $root);
    $lan = eccMember('editor');
    eccLimit($lan, $events);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($lan)->test(Editor::class, ['section' => 'tin-tuc'])
        ->assertSet('categoryIds', [$events->id])->assertSet('categoryId', $events->id)
        ->set('title', 'Hội thảo')->set('slug', 'hoi-thao')->call('save')->assertHasNoErrors());

    expect(eccCategoriesOf(app(Tenancy::class)->enter(1, fn () => Entry::query()->sole())))->toBe([$events->id]);
});

it('locks another section\'s categories while an approval workflow governs the post\'s section, and lets them move it otherwise', function (): void {
    $news = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $news);
    $jobs = eccSection('tuyen-dung', 'Tuyển dụng', 2);
    $it = eccCategory('Kỹ thuật', $jobs);
    $editor = eccMember('editor');
    $post = eccPost($editor, $events);

    // not governed: another section's category is a row with its section beside it, and as the main one it moves the post
    app(Tenancy::class)->enter(1, function () use ($editor, $post, $it, $events): void {
        $form = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid]);
        expect(eccRows($form)[$it->ulid])->toMatchArray(['locked' => false, 'section' => 'Tuyển dụng']);
        $form->call('toggleCategory', $it->ulid)->call('setMainCategory', $it->ulid)->assertSet('categoryIds', [$it->id, $events->id])
            ->assertSee(__('entries.ed.cat_moves', ['section' => 'Tuyển dụng']));
    });

    app(Tenancy::class)->enter(1, fn () => app(InstantiateWorkflow::class)->handle('admin_approval', 'tin-tuc', active: true));
    app()->forgetScopedInstances();

    app(Tenancy::class)->enter(1, function () use ($editor, $post, $it, $events): void {
        $form = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid]);
        expect(eccRows($form)[$it->ulid])->toMatchArray(['locked' => true, 'section' => 'Tuyển dụng'])
            ->and(eccRows($form)[$it->ulid]['why'])->toContain('Tin tức');
        $form->call('toggleCategory', $it->ulid)->assertDispatched('bs-toast')->assertSet('categoryIds', [$events->id])
            ->call('save')->assertHasNoErrors();
    });

    expect(eccCategoriesOf($post))->toBe([$events->id]);
});

it('asks before writing over categories another save ticked since the form opened', function (): void {
    $root = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $root);
    $notices = eccCategory('Thông báo', $root);
    $fairs = eccCategory('Hội chợ', $root);
    $editor = eccMember('editor');
    $post = eccPost($editor, $events);

    app(Tenancy::class)->enter(1, function () use ($editor, $post, $events, $notices, $fairs): void {
        $mine = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid]);
        // a colleague files the post under «Thông báo» too (not the main one) and saves first
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('toggleCategory', $notices->ulid)->call('save')->assertHasNoErrors();
        expect(eccCategoriesOf($post))->toBe([$events->id, $notices->id]);

        $mine->call('toggleCategory', $fairs->ulid)->call('save');
        $stale = $mine->get('stale');
        expect($stale)->not->toBeNull()
            ->and(array_column($stale['rows'], 'label'))->toContain(__('entries.ed.card_category'))
            ->and(eccCategoriesOf($post))->toBe([$events->id, $notices->id]);
    });
});

it('keeps another section\'s categories as stored while a workflow governs, whatever path the list comes by', function (): void {
    $news = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $news);
    $jobs = eccSection('tuyen-dung', 'Tuyển dụng', 2);
    $it = eccCategory('Kỹ thuật', $jobs);
    $sales = eccCategory('Kinh doanh', $jobs);
    $editor = eccMember('editor');
    $post = eccPost($editor, $events, $it);
    app(Tenancy::class)->enter(1, fn () => app(InstantiateWorkflow::class)->handle('admin_approval', 'tin-tuc', active: true));
    app()->forgetScopedInstances();

    app(Tenancy::class)->enter(1, function () use ($editor, $post, $news, $events, $jobs, $it, $sales): void {
        // the device's copy: another section's category added, a stored one dropped, a section's own one
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('restoreKept', ['categoryIds' => [$events->id, $sales->id, $jobs->id]])
            ->assertSet('categoryIds', [$events->id, $it->id]);

        // the one-category field the earlier picker used drops «Kỹ thuật»: the save keeps it
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('categoryId', $events->id)->assertSet('categoryIds', [$events->id])
            ->call('save')->assertHasNoErrors();
        expect(eccCategoriesOf($post))->toBe([$events->id, $it->id]);

        // another section's category filed elsewhere while the form was open: «keep mine» keeps it too
        $form = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid]);
        $post->assignCategories([$events->id, $it->id, $sales->id]);
        $form->set('title', 'Bài đã sửa')->call('save');
        expect($form->get('stale'))->not->toBeNull();
        $form->call('keepMine')->assertHasNoErrors();
        expect(eccCategoriesOf($post))->toBe([$events->id, $it->id, $sales->id]);
        $post->assignCategories([$events->id, $it->id]);

        // the main one unticked with only a locked row left: the section's own category leads, «Kỹ thuật» stays
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('toggleCategory', $events->ulid)->assertSet('categoryIds', [$news->id, $it->id])->assertSet('categoryId', $news->id)
            ->call('save')->assertHasNoErrors();
        expect(eccCategoriesOf($post))->toBe([$news->id, $it->id]);
    });
});

it('moves a post to the section of a main category in another one when it is saved', function (): void {
    $news = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $news);
    $jobs = eccSection('tuyen-dung', 'Tuyển dụng', 2);
    $it = eccCategory('Kỹ thuật', $jobs);
    $editor = eccMember('editor');
    $post = eccPost($editor, $events);

    app(Tenancy::class)->enter(1, function () use ($editor, $post, $events, $it): void {
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSet('sectionKey', 'tin-tuc')
            ->call('toggleCategory', $it->ulid)->call('setMainCategory', $it->ulid)
            ->call('save')->assertHasNoErrors()
            ->assertSet('sectionKey', 'tuyen-dung');
        expect(eccCategoriesOf($post))->toBe([$it->id, $events->id]);
    });
});

it('adds a new category from the card for who may manage categories, inside the limits and the depth', function (): void {
    $root = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $root);
    $fairs = eccCategory('Hội chợ', $events);
    $editor = eccMember('editor');
    $author = eccMember('author');
    $lan = eccMember('editor');
    eccLimit($lan, $events);

    app(Tenancy::class)->enter(1, function () use ($editor, $author, $lan, $root, $events, $fairs): void {
        // who may not manage categories sees no button and is refused
        Livewire::actingAs($author)->test(Editor::class, ['section' => 'tin-tuc'])
            ->assertDontSee(__('entries.ed.cat_add'))->call('addCategory', 'Bảo trì')->assertForbidden();

        // at the top of the section, ticked as the main one
        $form = Livewire::actingAs($editor)->test(Editor::class, ['section' => 'tin-tuc'])->assertSee(__('entries.ed.cat_add'));
        expect($form->call('addCategory', '  ')->errors()->has('catNew'))->toBeTrue();
        $form->call('addCategory', 'Bảo trì máy')->assertHasNoErrors()->assertDispatched('bs-toast');
        $made = Taxonomy::query()->where('vocabulary', 'post_cat')->orderByDesc('id')->firstOrFail();
        expect($made->parent_id)->toBe($root->id)->and($form->get('categoryIds'))->toBe([$made->id]);

        // a limited member: under the main category inside the limits, not below the third level
        $limited = Livewire::actingAs($lan)->test(Editor::class, ['section' => 'tin-tuc'])->assertSet('categoryId', $events->id)
            ->assertSee(__('entries.ed.cat_add_in', ['name' => 'Sự kiện']));
        $limited->call('addCategory', 'Hội thảo')->assertHasNoErrors();
        $talks = Taxonomy::query()->where('vocabulary', 'post_cat')->orderByDesc('id')->firstOrFail();
        expect($talks->parent_id)->toBe($events->id)->and($limited->get('categoryIds'))->toBe([$events->id, $talks->id]);

        $deep = Livewire::actingAs($lan)->test(Editor::class, ['section' => 'tin-tuc'])
            ->call('toggleCategory', $fairs->ulid)->call('setMainCategory', $fairs->ulid)
            ->assertSee(__('entries.ed.cat_add_lock_depth', ['depth' => 3]));
        $count = Taxonomy::query()->count();
        expect($deep->call('addCategory', 'Quá sâu')->errors()->first('catNew'))->toBe(__('entries.ed.cat_add_lock_depth', ['depth' => 3]))
            ->and(Taxonomy::query()->count())->toBe($count);
    });
});

it('reads the categories once whatever their number', function (bool $limited): void {
    $root = eccSection('tin-tuc', 'Tin tức');
    $first = eccCategory('Sự kiện', $root);
    $notices = eccCategory('Thông báo', $root);
    $editor = eccMember('editor');
    // a limited member's post also under a category outside the limits: its row reads as locked
    $post = $limited ? eccPost($editor, $first, $notices) : eccPost($editor, $first);
    if ($limited) {
        eccLimit($editor, $first);
    }

    $open = function () use ($editor, $post): int {
        app()->forgetScopedInstances();

        return countQueries(fn () => $this->actingAs($editor)->get("http://a.test/admin/content/{$post->ulid}/edit")->assertOk());
    };
    $open();   // warm the member's caches first
    $few = $open();
    auth()->guard()->forgetUser();   // the categories are made by the website, not the limited member
    foreach (range(1, 30) as $n) {
        eccCategory('Mục '.$n, $n % 2 === 0 ? $root : $first);
    }
    $many = $open();

    expect($many)->toBe($few);
})->with(['every category' => [false], 'limited to some' => [true]]);

it('puts back from the device only the categories a limited member may file under, and the save keeps to them', function (): void {
    $root = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $root);
    $fairs = eccCategory('Hội chợ', $events);
    $notices = eccCategory('Thông báo', $root);
    $hidden = eccCategory('Nội bộ', $root);
    $lan = eccMember('editor');
    eccLimit($lan, $events);
    $post = eccPost($lan, $events);

    app(Tenancy::class)->enter(1, function () use ($lan, $post, $events, $fairs, $notices, $hidden): void {
        // the device's copy names categories outside the limits the post is not under: they are dropped
        Livewire::actingAs($lan)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('restoreKept', ['categoryIds' => [$notices->id, $events->id, $hidden->id, $fairs->id]])
            ->assertSet('categoryIds', [$events->id, $fairs->id])->assertSet('categoryId', $events->id)
            ->call('save')->assertHasNoErrors();
        expect(eccCategoriesOf($post))->toBe([$events->id, $fairs->id]);

        // only outside ones: nothing changes
        Livewire::actingAs($lan)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('restoreKept', ['categoryIds' => [$notices->id, $hidden->id]])
            ->assertSet('categoryIds', [$events->id, $fairs->id]);
    });
});

it('refuses a limited member\'s save after the main category field is set outside the limits', function (): void {
    $root = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $root);
    $notices = eccCategory('Thông báo', $root);
    $lan = eccMember('editor');
    eccLimit($lan, $events);
    $post = eccPost($lan, $events);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($lan)->test(Editor::class, ['ulid' => $post->ulid])
        ->set('categoryId', $notices->id)->set('title', 'Bài đã sửa')
        ->call('save')->assertHasErrors(['categoryId']));

    expect(eccCategoriesOf($post))->toBe([$events->id])
        ->and(app(Tenancy::class)->enter(1, fn () => $post->translations()->where('locale', 'vi')->value('title')))->not->toBe('Bài đã sửa');
});

it('does not lock the rows of a post whose main category no section is built on, whatever workflow the first section has', function (): void {
    $news = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $news);
    $jobs = eccSection('tuyen-dung', 'Tuyển dụng', 2);
    $it = eccCategory('Kỹ thuật', $jobs);
    $loose = eccCategory('Chưa xếp mục');   // at the top, no section built on it or above it
    $editor = eccMember('editor');
    $post = eccPost($editor, $loose);
    app(Tenancy::class)->enter(1, fn () => app(InstantiateWorkflow::class)->handle('admin_approval', 'tin-tuc', active: true));
    app()->forgetScopedInstances();

    app(Tenancy::class)->enter(1, function () use ($editor, $post, $loose, $events, $it): void {
        // listed under the site's first post section, but that section's workflow does not govern it
        $form = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])->assertSet('sectionKey', 'tin-tuc');
        $rows = eccRows($form);
        expect($rows[$it->ulid]['locked'])->toBeFalse()
            ->and($rows[$events->ulid]['locked'])->toBeFalse()
            ->and($rows[$loose->ulid]['locked'])->toBeFalse();
        $form->call('toggleCategory', $it->ulid)->assertNotDispatched('bs-toast')
            ->assertSet('categoryIds', [$loose->id, $it->id])
            ->call('save')->assertHasNoErrors();
    });

    expect(eccCategoriesOf($post))->toBe([$loose->id, $it->id]);
});

it('refuses «Thêm chuyên mục mới» to a limited member whose main category is in another section, nothing made', function (): void {
    $news = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $news);
    $jobs = eccSection('tuyen-dung', 'Tuyển dụng', 2);
    $it = eccCategory('Kỹ thuật', $jobs);
    $lan = eccMember('editor');
    eccLimit($lan, $events, $it);

    app(Tenancy::class)->enter(1, function () use ($lan, $events, $it): void {
        $form = Livewire::actingAs($lan)->test(Editor::class, ['section' => 'tin-tuc'])
            ->call('toggleCategory', $it->ulid)->call('setMainCategory', $it->ulid)
            ->assertSet('categoryId', $it->id)
            ->assertSee(__('entries.ed.cat_add_lock_scope'));
        $count = Taxonomy::query()->count();
        expect($form->call('addCategory', 'Bảo trì')->errors()->first('catNew'))->toBe(__('entries.ed.cat_add_lock_scope'))
            ->and(Taxonomy::query()->count())->toBe($count);
        $form->assertSet('categoryIds', [$it->id, $events->id]);
    });
});

it('never shows another website\'s categories among the rows', function (): void {
    $root = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $root);
    $foreign = eccCategory('Tin site B', null, 2);
    $foreignChild = eccCategory('Con site B', $foreign, 2);
    $editor = eccMember('editor');

    app(Tenancy::class)->enter(1, function () use ($editor, $events, $foreign, $foreignChild): void {
        $form = Livewire::actingAs($editor)->test(Editor::class, ['section' => 'tin-tuc']);
        $rows = eccRows($form);
        expect($rows)->toHaveKey($events->ulid)
            ->not->toHaveKey($foreign->ulid)->not->toHaveKey($foreignChild->ulid)
            ->and(array_column($rows, 'label'))->not->toContain('Tin site B')->not->toContain('Con site B');
        $form->assertDontSee('Tin site B');
    });
});

it('checks the post\'s categories in one count of this website\'s, however many are ticked', function (): void {
    $root = eccSection('tin-tuc', 'Tin tức');
    $many = array_map(fn (int $n): Taxonomy => eccCategory('Mục '.$n, $root), range(1, 6));
    $editor = eccMember('editor');

    app(Tenancy::class)->enter(1, function () use ($editor, $many): void {
        $form = Livewire::actingAs($editor)->test(Editor::class, ['section' => 'tin-tuc'])->set('title', 'Hội thảo')->set('slug', 'hoi-thao');
        foreach ($many as $category) {
            $form->call('toggleCategory', $category->ulid);
        }
        $checks = [];
        DB::listen(function ($query) use (&$checks): void {
            if (str_contains($query->sql, 'as "aggregate" from "taxonomies"')) {
                $checks[] = $query->sql;
            }
        });
        $form->call('save')->assertHasNoErrors();

        // the main category's own check and one for the whole list, never one per category
        expect($checks)->toHaveCount(2)
            ->and(array_values(array_filter($checks, fn (string $sql): bool => str_contains($sql, '"id" in ('))))->toHaveCount(1)
            ->and(collect($checks)->first(fn (string $sql): bool => str_contains($sql, '"id" in (')))->toContain('"taxonomies"."site_id" = ?');
    });

    expect(eccCategoriesOf(app(Tenancy::class)->enter(1, fn () => Entry::query()->sole())))->toBe(array_map(fn (Taxonomy $c): int => $c->id, $many));
});

it('keeps a governed post\'s lock when the main category field is emptied', function (): void {
    $news = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $news);
    $jobs = eccSection('tuyen-dung', 'Tuyển dụng', 2);
    $it = eccCategory('Kỹ thuật', $jobs);
    $editor = eccMember('editor');
    $post = eccPost($editor, $events);
    app(Tenancy::class)->enter(1, fn () => app(InstantiateWorkflow::class)->handle('admin_approval', 'tin-tuc', active: true));
    app()->forgetScopedInstances();

    app(Tenancy::class)->enter(1, function () use ($editor, $post, $events, $it): void {
        // the one-category field emptied: the stored post is still governed, another section's category refused
        $form = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('categoryId', null)->assertSet('categoryIds', []);
        expect(eccRows($form)[$it->ulid]['locked'])->toBeTrue();
        $form->call('toggleCategory', $it->ulid)->assertDispatched('bs-toast')->assertSet('categoryIds', [])
            ->call('toggleCategory', $events->ulid)->assertSet('categoryIds', [$events->id])
            ->call('save')->assertHasNoErrors();
    });

    expect(eccCategoriesOf($post))->toBe([$events->id]);
});

it('holds the categories in order and the fields added later (cover words, look, shown day, job card) last in what it compares, so a fresh form has nothing unsaved', function (): void {
    $root = eccSection('tin-tuc', 'Tin tức');
    $events = eccCategory('Sự kiện', $root);
    $notices = eccCategory('Thông báo', $root);
    $editor = eccMember('editor');
    $post = eccPost($editor, $notices, $events);
    app(Tenancy::class)->enter(1, function () use ($post): void {
        $post->update(['meta' => ['image_url' => 'a/library/xuong-cccccccccccc.png']]);
        $post->translationFor('vi')->update(['cover_alt' => 'Thợ đứng máy']);
    });

    app(Tenancy::class)->enter(1, function () use ($editor, $post, $events, $notices): void {
        $form = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSet('coverAlt', 'Thợ đứng máy')->assertSet('categoryIds', [$notices->id, $events->id]);
        $print = $form->get('storedPrint');
        expect(array_slice(array_keys($print), -5))->toBe(['category', 'cover_alt', 'template', 'display_date', 'job'])
            ->and($print['category'])->toBe($notices->id.','.$events->id);
        expect($form->viewData('changes'))->toBe(0);

        // saved as it is, then again from a fresh form: no question about someone else's save
        $form->set('title', 'Bài đã sửa')->call('save')->assertHasNoErrors();
        expect($form->get('stale'))->toBeNull();
        $again = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid]);
        expect($again->get('storedPrint'))->toBe($form->get('storedPrint'))
            ->and($again->viewData('changes'))->toBe(0);
        $again->set('excerpt', 'Tóm tắt')->call('save')->assertHasNoErrors();
        expect($again->get('stale'))->toBeNull();
    });
});
