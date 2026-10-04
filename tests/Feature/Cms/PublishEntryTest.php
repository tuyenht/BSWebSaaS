<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Support\EntryStatusAudit;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
 * W6 — Publish workflow Tầng-1 + route sync + 301 (design.md §15.4, §213). An actor
 * with entries.publish publishes straight through (route + published_at); without it
 * the entry parks as pending. Changing a published page's slug moves its route and
 * leaves a 301 the storefront serves; a revert must not create a redirect loop.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/**
 * Create a draft `page` + its vi translation in the current site context.
 *
 * @param  array<string, mixed>  $body
 */
function makeDraftPage(User $author, string $slug, string $title = 'Trang', array $body = ['blocks' => []]): Entry
{
    $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $author->id]);
    $entry->translations()->create([
        'locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'draft', 'body' => $body,
    ]);

    return $entry;
}

it('publishes straight through for an actor with entries.publish', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish', 'entries.submit']);
        $page = makeDraftPage($editor, 'gioi-thieu');

        $status = app(PublishEntry::class)->handle($page->fresh(), 'vi', $editor->fresh());

        expect($status)->toBe('published')
            ->and($page->fresh()->status)->toBe('published')
            ->and($page->fresh()->published_at)->not->toBeNull()
            ->and($page->translationFor('vi')->status)->toBe('published')
            ->and(SiteRoute::query()->where('path', '/gioi-thieu')->where('entry_id', $page->id)->exists())->toBeTrue();
    });
});

it('parks as pending (no route, no published_at) for an actor without publish', function (): void {
    $tenancy = app(Tenancy::class);
    $author = User::factory()->create();

    $tenancy->enter(1, function () use ($author): void {
        grantRole($author, 'author', ['entries.create', 'entries.edit.own', 'entries.submit']);
        $page = makeDraftPage($author, 'tin-tuc');

        $status = app(PublishEntry::class)->handle($page->fresh(), 'vi', $author->fresh());

        expect($status)->toBe('pending')
            ->and($page->fresh()->status)->toBe('pending')
            ->and($page->fresh()->published_at)->toBeNull()
            ->and(SiteRoute::query()->where('entry_id', $page->id)->exists())->toBeFalse();
    });
});

it('rewrites the route and records a 301 when a published slug changes', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $page = makeDraftPage($editor, 'gioi-thieu');
        app(PublishEntry::class)->handle($page->fresh(), 'vi', $editor->fresh());

        $page->translationFor('vi')->update(['slug' => 've-chung-toi']);
        app(PublishEntry::class)->handle($page->fresh(), 'vi', $editor->fresh());

        expect(SiteRoute::query()->where('entry_id', $page->id)->value('path'))->toBe('/ve-chung-toi')
            ->and(SiteRoute::query()->where('path', '/gioi-thieu')->exists())->toBeFalse()
            ->and(Redirect::query()->where('from_path', '/gioi-thieu')->where('to_path', '/ve-chung-toi')->where('status_code', 301)->exists())->toBeTrue();
    });
});

it('does not create a redirect loop when a slug reverts (A→B→A)', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $page = makeDraftPage($editor, 'a');
        $publish = app(PublishEntry::class);
        $publish->handle($page->fresh(), 'vi', $editor->fresh());

        $page->translationFor('vi')->update(['slug' => 'b']);
        $publish->handle($page->fresh(), 'vi', $editor->fresh());

        $page->translationFor('vi')->update(['slug' => 'a']);
        $publish->handle($page->fresh(), 'vi', $editor->fresh());

        expect(SiteRoute::query()->where('entry_id', $page->id)->value('path'))->toBe('/a')
            ->and(Redirect::query()->where('from_path', '/a')->exists())->toBeFalse()
            ->and(Redirect::query()->where('from_path', '/b')->where('to_path', '/a')->exists())->toBeTrue();
    });
});

it('serves the 301 from the storefront and 404s an unmapped path', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $page = makeDraftPage($editor, 'gioi-thieu', 'Giới thiệu', ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Giới thiệu']]]]);
        $publish = app(PublishEntry::class);
        $publish->handle($page->fresh(), 'vi', $editor->fresh());

        $page->translationFor('vi')->update(['slug' => 've-chung-toi']);
        $publish->handle($page->fresh(), 'vi', $editor->fresh());
    });

    $this->get('http://a.test/gioi-thieu')->assertStatus(301)->assertRedirect('http://a.test/ve-chung-toi');
    $this->get('http://a.test/ve-chung-toi')->assertOk();
    $this->get('http://a.test/khong-ton-tai')->assertNotFound();
});

it('does not let a submit-only actor unpublish an already-published page', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();
    $author = User::factory()->create();

    $tenancy->enter(1, function () use ($editor, $author): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);

        $page = makeDraftPage($author, 'gioi-thieu');
        app(PublishEntry::class)->handle($page->fresh(), 'vi', $editor->fresh());
        expect($page->fresh()->status)->toBe('published');

        // The author (submit only) re-submits the live page → it must stay published.
        $status = app(PublishEntry::class)->handle($page->fresh(), 'vi', $author->fresh());

        expect($status)->toBe('published')
            ->and($page->fresh()->status)->toBe('published');
    });
});

it('collapses a repeated rename into a single-hop 301 chain (A→B→C)', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $page = makeDraftPage($editor, 'a');
        $publish = app(PublishEntry::class);
        $publish->handle($page->fresh(), 'vi', $editor->fresh());

        $page->translationFor('vi')->update(['slug' => 'b']);
        $publish->handle($page->fresh(), 'vi', $editor->fresh());

        $page->translationFor('vi')->update(['slug' => 'c']);
        $publish->handle($page->fresh(), 'vi', $editor->fresh());

        // /a redirects straight to /c (single hop), not /a→/b→/c.
        expect(Redirect::query()->where('from_path', '/a')->value('to_path'))->toBe('/c')
            ->and(Redirect::query()->where('from_path', '/b')->value('to_path'))->toBe('/c')
            ->and(SiteRoute::query()->where('entry_id', $page->id)->value('path'))->toBe('/c');
    });
});

it('resolves a case-variant inbound path on the storefront', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $page = makeDraftPage($editor, 'gioi-thieu', 'Giới thiệu', ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'GT']]]]);
        app(PublishEntry::class)->handle($page->fresh(), 'vi', $editor->fresh());
    });

    $this->get('http://a.test/GIOI-THIEU')->assertOk();
});

it("refuses to send a colleague's draft for review", function (): void {
    $tenancy = app(Tenancy::class);
    $author = User::factory()->create();
    $colleague = User::factory()->create();
    $publisher = User::factory()->create();

    $tenancy->enter(1, function () use ($author, $colleague, $publisher): void {
        grantRole($author, 'author', ['entries.view', 'entries.edit.own', 'entries.submit']);
        grantRole($colleague, 'author', ['entries.view', 'entries.edit.own', 'entries.submit']);
        grantRole($publisher, 'editor', ['entries.view', 'entries.edit.any', 'entries.publish']);
        $theirs = makeDraftPage($colleague, 'ban-nhap-cua-dong-nghiep');

        // Sending is part of working on an entry: a half-written draft that is not yours stays yours to leave.
        expect(fn () => app(PublishEntry::class)->handle($theirs->fresh(), 'vi', $author->fresh()))
            ->toThrow(AuthorizationException::class);

        expect($theirs->fresh()->status)->toBe('draft')
            ->and($theirs->translationFor('vi')->status)->toBe('draft')
            ->and(AuditLog::query()->where('action', EntryStatusAudit::ACTION)->where('auditable_id', $theirs->id)->exists())->toBeFalse()
            ->and(SiteNotification::query()->where('user_id', $publisher->id)->where('kind', 'workflow.pending_review')->exists())->toBeFalse();
    });
});

it("lets someone who may edit any entry, without publish, send anyone's draft for review", function (): void {
    $tenancy = app(Tenancy::class);
    $copyEditor = User::factory()->create();
    $author = User::factory()->create();

    $tenancy->enter(1, function () use ($copyEditor, $author): void {
        grantRole($copyEditor, 'copy-editor', ['entries.view', 'entries.edit.any', 'entries.submit']);
        $theirs = makeDraftPage($author, 'bai-cua-tac-gia');

        expect(app(PublishEntry::class)->handle($theirs->fresh(), 'vi', $copyEditor->fresh()))->toBe('pending')
            ->and($theirs->fresh()->status)->toBe('pending')
            ->and(SiteRoute::query()->where('entry_id', $theirs->id)->exists())->toBeFalse();
    });
});

it('sends only a draft: a scheduled or already pending entry is left as it is', function (): void {
    $tenancy = app(Tenancy::class);
    $author = User::factory()->create();
    $publisher = User::factory()->create();

    $tenancy->enter(1, function () use ($author, $publisher): void {
        grantRole($author, 'author', ['entries.view', 'entries.edit.own', 'entries.submit']);
        grantRole($publisher, 'editor', ['entries.view', 'entries.edit.any', 'entries.publish']);
        $goLive = now()->addDay()->startOfMinute();
        $scheduled = makeDraftPage($author, 'len-lich');
        $scheduled->update(['status' => 'scheduled', 'published_at' => $goLive]);
        $pending = makeDraftPage($author, 'cho-duyet');
        $pending->update(['status' => 'pending']);

        $publish = app(PublishEntry::class);

        // A publisher set this go-live: re-sending must not quietly cancel it.
        expect($publish->handle($scheduled->fresh(), 'vi', $author->fresh()))->toBe('scheduled')
            ->and($scheduled->fresh()->status)->toBe('scheduled')
            ->and($scheduled->fresh()->published_at->equalTo($goLive))->toBeTrue()
            ->and($publish->handle($pending->fresh(), 'vi', $author->fresh()))->toBe('pending')
            ->and(AuditLog::query()->where('action', EntryStatusAudit::ACTION)->exists())->toBeFalse()
            ->and(SiteNotification::query()->where('user_id', $publisher->id)->exists())->toBeFalse();
    });
});

it('never publishes or sends an entry from the Trash, whoever calls', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();
    $author = User::factory()->create();

    $tenancy->enter(1, function () use ($editor, $author): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish', 'entries.submit']);
        grantRole($author, 'author', ['entries.view', 'entries.edit.own', 'entries.submit']);
        $published = makeDraftPage($editor, 'trong-thung-rac');
        $published->delete();
        $sent = makeDraftPage($author, 'nhap-trong-thung-rac');
        $sent->delete();

        $publish = app(PublishEntry::class);

        expect(fn () => $publish->handle(Entry::withTrashed()->find($published->id), 'vi', $editor->fresh()))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => $publish->handle(Entry::withTrashed()->find($sent->id), 'vi', $author->fresh()))
            ->toThrow(AuthorizationException::class)
            ->and(Entry::withTrashed()->find($published->id)->status)->toBe('draft')
            ->and(Entry::withTrashed()->find($sent->id)->status)->toBe('draft')
            ->and(SiteRoute::query()->where('path', '/trong-thung-rac')->exists())->toBeFalse();
    });
});
