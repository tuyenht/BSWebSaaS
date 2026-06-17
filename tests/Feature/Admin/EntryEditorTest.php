<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * W4 — Entries CRUD + block-list editor (Livewire). Every mutation authorizes through
 * EntryPolicy; the Page-JSON body is validated against the shared ComponentRegistry
 * allow-list (W5); Publish runs the Tầng-1 workflow (W6). All work is site-scoped by
 * BelongsToSite + RLS.
 */

beforeEach(function () {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('creates a draft page and persists its Page-JSON translation (no route yet)', function () {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor) {
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('title', 'Giới thiệu')
            ->set('slug', 'gioi-thieu')
            ->call('addBlock', 'hero')
            ->set('blocks.0.props.heading', 'Về chúng tôi')
            ->call('save')
            ->assertHasNoErrors();

        $entry = Entry::query()->where('type', 'page')->first();
        expect($entry)->not->toBeNull()
            ->and($entry->status)->toBe('draft')
            ->and($entry->author_id)->toBe($editor->id);

        $translation = $entry->translationFor('vi');
        expect($translation->slug)->toBe('gioi-thieu')
            ->and($translation->body['blocks'][0]['type'])->toBe('hero')
            ->and($translation->body['blocks'][0]['props']['heading'])->toBe('Về chúng tôi')
            ->and(SiteRoute::query()->where('entry_id', $entry->id)->exists())->toBeFalse();
    });
});

it('adds, reorders and removes blocks from the registry', function () {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor) {
        grantRole($editor, 'editor', ['entries.create']);

        $component = Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->call('addBlock', 'hero')
            ->call('addBlock', 'cta')
            ->call('addBlock', 'richtext');

        expect(array_column($component->get('blocks'), 'type'))->toBe(['hero', 'cta', 'richtext']);

        $component->call('moveUp', 2); // richtext up one
        expect(array_column($component->get('blocks'), 'type'))->toBe(['hero', 'richtext', 'cta']);

        $component->call('removeBlock', 0); // drop hero
        expect(array_column($component->get('blocks'), 'type'))->toBe(['richtext', 'cta']);
    });
});

it('refuses to add an unregistered block type', function () {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor) {
        grantRole($editor, 'editor', ['entries.create']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->call('addBlock', 'evil_script')
            ->assertHasErrors('blocks')
            ->assertSet('blocks', []);
    });
});

it('rejects an unknown block on save and persists nothing (block lạ)', function () {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor) {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('title', 'X')
            ->set('slug', 'x')
            ->set('blocks', [['type' => 'evil_script', 'props' => []]])
            ->call('save')
            ->assertHasErrors('blocks');

        expect(Entry::query()->where('type', 'page')->count())->toBe(0);
    });
});

it('publishes a page straight through for an editor (status + route + published_at)', function () {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor) {
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('title', 'Giới thiệu')
            ->set('slug', 'gioi-thieu')
            ->call('addBlock', 'hero')
            ->call('publish')
            ->assertHasNoErrors()
            ->assertSet('status', 'published');

        $entry = Entry::query()->where('type', 'page')->first();
        expect($entry->status)->toBe('published')
            ->and($entry->published_at)->not->toBeNull()
            ->and(SiteRoute::query()->where('path', '/gioi-thieu')->where('entry_id', $entry->id)->exists())->toBeTrue();
    });
});

it('parks an author submission as pending (no publish permission, no route)', function () {
    $tenancy = app(Tenancy::class);
    $author = User::factory()->create();

    $tenancy->enter(1, function () use ($author) {
        grantRole($author, 'author', ['entries.view', 'entries.create', 'entries.edit.own', 'entries.submit']);

        Livewire::actingAs($author->fresh())
            ->test(Editor::class)
            ->set('title', 'Tin mới')
            ->set('slug', 'tin-moi')
            ->call('addBlock', 'richtext')
            ->call('publish')
            ->assertHasNoErrors()
            ->assertSet('status', 'pending');

        $entry = Entry::query()->where('type', 'page')->first();
        expect($entry->status)->toBe('pending')
            ->and($entry->published_at)->toBeNull()
            ->and(SiteRoute::query()->where('entry_id', $entry->id)->exists())->toBeFalse();
    });
});

it('lists only the current site pages', function () {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(2, function () {
        $other = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => null]);
        $other->translations()->create(['locale' => 'vi', 'title' => 'B-only page', 'slug' => 'b', 'status' => 'draft', 'body' => ['blocks' => []]]);
    });

    $tenancy->enter(1, function () use ($editor) {
        grantRole($editor, 'editor', ['entries.view']);
        $mine = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        $mine->translations()->create(['locale' => 'vi', 'title' => 'A-only page', 'slug' => 'a', 'status' => 'draft', 'body' => ['blocks' => []]]);

        Livewire::actingAs($editor->fresh())
            ->test(Index::class)
            ->assertSee('A-only page')
            ->assertDontSee('B-only page');
    });
});

it('forbids a member without entries.view from the page list', function () {
    $tenancy = app(Tenancy::class);
    $member = User::factory()->create();
    $tenancy->enter(1, fn () => grantRole($member, 'media-only', ['media.view']));

    $this->actingAs($member)->get('http://a.test/admin/content')->assertForbidden();
});

it('forbids an author from editing another author\'s page (scope own)', function () {
    $tenancy = app(Tenancy::class);
    $author = User::factory()->create();
    $owner = User::factory()->create();

    $ulid = $tenancy->enter(1, function () use ($author, $owner): string {
        grantRole($author, 'author', ['entries.view', 'entries.edit.own']);
        $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $owner->id]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Owned', 'slug' => 'owned', 'status' => 'draft', 'body' => ['blocks' => []]]);

        return $page->ulid;
    });

    $this->actingAs($author)->get("http://a.test/admin/content/{$ulid}/edit")->assertForbidden();
});

it('persists under the site default locale, not a hardcoded vi', function () {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    // Flip site 1's default to 'en' via the source of truth (site_locales.is_default).
    // Clear the old default first so the partial-unique (one default/site) is never violated.
    $admin = DB::connection('pgsql_admin');
    $admin->table('site_locales')->where('site_id', 1)->update(['is_default' => false]);
    $admin->table('site_locales')->insert([
        'site_id' => 1, 'locale' => 'en', 'is_default' => true, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $tenancy->enter(1, function () use ($editor) {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('title', 'About us')
            ->set('slug', 'about-us')
            ->call('addBlock', 'hero')
            ->call('save')
            ->assertHasNoErrors();

        $entry = Entry::query()->where('type', 'page')->first();
        expect($entry->translationFor('en'))->not->toBeNull()
            ->and($entry->translations()->where('locale', 'vi')->exists())->toBeFalse();
    });
});

it('rejects a slug already owned by another page with a friendly error (no 500)', function () {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor) {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'entries.publish']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('title', 'Liên hệ')->set('slug', 'lien-he')->call('addBlock', 'hero')
            ->call('publish')->assertHasNoErrors()->assertSet('status', 'published');

        // A second page claiming the same slug → friendly slug error, not a DB 500.
        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('title', 'Liên hệ 2')->set('slug', 'lien-he')->call('addBlock', 'hero')
            ->call('publish')->assertHasErrors('slug');

        expect(SiteRoute::query()->where('path', '/lien-he')->count())->toBe(1);
    });
});
