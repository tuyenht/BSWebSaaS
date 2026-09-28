<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\RestoreEntryRevision;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\RevisionRestoreRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/*
 * Restoring a revision writes an earlier version's content back through the editor's own checks:
 * the status never moves, a live slug change moves the route with a 301, media that has left the
 * library is dropped (and counted), and every refusal is a reason the person can act on
 * (design.md §8, §29).
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A published page "cu" with one publish revision (v1); returns [page, v1]. */
function restorablePage(User $publisher, ?User $author = null, string $slug = 'cu'): array
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $author?->id, 'meta' => []]);
    $page->translations()->create([
        'locale' => 'vi', 'title' => 'Bản đầu', 'slug' => $slug, 'status' => 'draft', 'excerpt' => 'Tóm tắt đầu',
        'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Tiêu đề đầu']]]],
    ]);
    app(PublishEntry::class)->handle($page->fresh(), 'vi', $publisher);

    $v1 = EntryRevision::query()->where('entry_id', $page->id)->where('locale', 'vi')->sole();

    return [$page->fresh(), $v1];
}

/** Change the vi content of a live page the way a save does, recording the edit. */
function editLivePage(Entry $page, array $changes, User $actor): void
{
    $translation = $page->translationFor('vi');
    $translation->update($changes);
    if (isset($changes['slug'])) {
        app(RouteSync::class)->sync($page, 'vi', $changes['slug']);
    }
    app(RecordEntryRevision::class)->forLocale($page->fresh(), $translation->fresh(), RevisionKind::Edit, $actor);
}

function restoreMedia(string $slug, string $hashChar): Media
{
    return Media::query()->create([
        'collection' => 'library', 'slug' => $slug, 'hash' => str_repeat($hashChar, 64),
        'storage_key' => "a/library/{$slug}-abcdef123456.png", 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
    ]);
}

it('writes the old content back, keeps the status, records a restore revision and an audit line', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $actor = $editor->fresh();
        [$page, $v1] = restorablePage($actor);
        editLivePage($page, [
            'title' => 'Bản hai', 'excerpt' => 'Tóm tắt hai',
            'body' => ['blocks' => [['type' => 'cta', 'props' => ['heading' => 'Mới']]]],
        ], $actor);

        $dropped = app(RestoreEntryRevision::class)->handle($v1, $actor);

        $translation = $page->fresh()->translationFor('vi');
        expect($dropped)->toBe(0)
            ->and($translation->title)->toBe('Bản đầu')
            ->and($translation->excerpt)->toBe('Tóm tắt đầu')
            ->and($translation->body)->toMatchArray(['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Tiêu đề đầu']]]])
            ->and($translation->status)->toBe('published')
            ->and($page->fresh()->status)->toBe('published');

        $newest = EntryRevision::query()->where('entry_id', $page->id)->orderByDesc('id')->first();
        expect($newest->kind)->toBe(RevisionKind::Restore)
            ->and($newest->created_by)->toBe($editor->id)
            ->and($newest->snapshot)->toMatchArray(['title' => 'Bản đầu', 'excerpt' => 'Tóm tắt đầu'])
            ->and(EntryRevision::query()->where('entry_id', $page->id)->count())->toBe(3);

        $audit = AuditLog::query()->where('action', RestoreEntryRevision::AUDIT_ACTION)->sole();
        expect($audit->auditable_id)->toBe($page->id)
            ->and($audit->actor_id)->toBe($editor->id)
            ->and($audit->meta)->toMatchArray([
                'entry_ulid' => $page->ulid, 'revision_ulid' => $v1->ulid, 'locale' => 'vi',
                'status' => 'published', 'dropped_media' => 0,
            ]);
    });
});

it('moves the route and leaves a 301 when the restored slug differs on live content', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $actor = $editor->fresh();
        [$page, $v1] = restorablePage($actor);
        editLivePage($page, ['slug' => 'moi'], $actor);
        expect(SiteRoute::query()->where('entry_id', $page->id)->value('path'))->toBe('/moi');

        app(RestoreEntryRevision::class)->handle($v1, $actor);

        expect($page->fresh()->translationFor('vi')->slug)->toBe('cu')
            ->and(SiteRoute::query()->where('entry_id', $page->id)->value('path'))->toBe('/cu')
            ->and(Redirect::query()->where('from_path', '/moi')->value('to_path'))->toBe('/cu')
            // The earlier /cu → /moi hop is gone, so the two cannot loop.
            ->and(Redirect::query()->where('from_path', '/cu')->exists())->toBeFalse();
    });

    $this->get('http://a.test/cu')->assertOk();
    $this->get('http://a.test/moi')->assertStatus(301)->assertRedirect('http://a.test/cu');
});

it('refuses with slug_taken when the old address now belongs to other content, changing nothing', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $actor = $editor->fresh();
        [$page, $v1] = restorablePage($actor);
        editLivePage($page, ['slug' => 'moi', 'title' => 'Bản hai'], $actor);
        // Another page now owns /cu (the 301 from /cu is dropped when a route claims the path).
        Redirect::query()->where('from_path', '/cu')->delete();
        restorablePage($actor, null, 'cu');
        $before = EntryRevision::query()->count();

        try {
            app(RestoreEntryRevision::class)->handle($v1, $actor);
            $this->fail('The restore should have been refused.');
        } catch (RevisionRestoreRejectedException $e) {
            expect($e->reason)->toBe('slug_taken')
                ->and($e->userMessage())->toBe(__('entries.revision_err_slug_taken'));
        }

        $translation = $page->fresh()->translationFor('vi');
        expect($translation->slug)->toBe('moi')
            ->and($translation->title)->toBe('Bản hai')
            ->and(EntryRevision::query()->count())->toBe($before)
            ->and(AuditLog::query()->where('action', RestoreEntryRevision::AUDIT_ACTION)->exists())->toBeFalse();
    });
});

it('restores a draft slug without creating a route', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $actor = $editor->fresh();
        [$page, $v1] = restorablePage($actor);
        $page->update(['status' => 'draft']);
        SiteRoute::query()->where('entry_id', $page->id)->delete();
        $page->translationFor('vi')->update(['slug' => 'moi', 'status' => 'draft']);

        app(RestoreEntryRevision::class)->handle($v1, $actor);

        expect($page->fresh()->translationFor('vi')->slug)->toBe('cu')
            ->and($page->fresh()->status)->toBe('draft')
            ->and(SiteRoute::query()->where('entry_id', $page->id)->exists())->toBeFalse();
    });
});

it('brings back a language the entry lost, in a status a translation can hold', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        [$page, $v1] = restorablePage($editor->fresh());
        $page->update(['status' => 'private']);
        SiteRoute::query()->where('entry_id', $page->id)->delete();
        $page->translations()->where('locale', 'vi')->delete();

        app(RestoreEntryRevision::class)->handle($v1, $editor->fresh());

        $translation = $page->fresh()->translationFor('vi');
        expect($translation?->title)->toBe('Bản đầu')
            ->and($translation?->status)->toBe('draft')
            ->and($page->fresh()->status)->toBe('private');
    });
});

it('drops references to media that left the library, counts them, and rebuilds the usage index', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $actor = $editor->fresh();
        $kept = restoreMedia('giu', 'a');
        $gone = restoreMedia('mat', 'b');

        $page = Entry::query()->create([
            'type' => 'page', 'status' => 'draft', 'cover_media_id' => $gone->id, 'meta' => ['image_url' => $gone->storage_key],
        ]);
        $page->translations()->create([
            'locale' => 'vi', 'title' => 'Ảnh', 'slug' => 'anh', 'status' => 'draft', 'og_image_id' => $kept->id,
            'body' => ['blocks' => [
                ['type' => 'hero', 'props' => ['heading' => 'Hero', 'image_url' => $gone->id]],
                ['type' => 'partners', 'props' => ['heading' => 'Đối tác', 'logos' => [
                    ['image_url' => $kept->id, 'name' => 'Còn'],
                    ['image_url' => $gone->id, 'name' => 'Mất'],
                ]]],
            ]],
        ]);
        app(PublishEntry::class)->handle($page->fresh(), 'vi', $actor);
        $v1 = EntryRevision::query()->where('entry_id', $page->id)->sole();

        $page->translationFor('vi')->update(['body' => ['blocks' => []], 'og_image_id' => null]);
        $page->update(['cover_media_id' => null, 'meta' => []]);
        $gone->delete();

        $dropped = app(RestoreEntryRevision::class)->handle($v1, $actor);

        $page = $page->fresh();
        $blocks = $page->translationFor('vi')->body['blocks'];
        expect($dropped)->toBe(4)   // hero image, one partner logo, cover id, cover key
            ->and($blocks[0]['props'])->toMatchArray(['heading' => 'Hero', 'image_url' => null])
            ->and($blocks[1]['props']['logos'][0])->toMatchArray(['image_url' => $kept->id, 'name' => 'Còn'])
            ->and($blocks[1]['props']['logos'][1])->toMatchArray(['image_url' => null, 'name' => 'Mất'])
            ->and($page->cover_media_id)->toBeNull()
            ->and($page->meta)->not->toHaveKey('image_url')
            ->and($page->translationFor('vi')->og_image_id)->toBe($kept->id)
            ->and(Mediable::query()->where('mediable_type', 'entry')->where('mediable_id', $page->id)->where('collection', 'content')->pluck('media_id')->all())
            ->toBe([$kept->id]);
    });
});

it('refuses with locale_disabled when the revision language is off on the site', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        [$page] = restorablePage($editor->fresh());
        $en = $page->translations()->create(['locale' => 'en', 'title' => 'About', 'slug' => 'about', 'status' => 'draft', 'body' => ['blocks' => []]]);
        app(RecordEntryRevision::class)->forLocale($page, $en, RevisionKind::Edit, $editor);
        $revision = EntryRevision::query()->where('entry_id', $page->id)->where('locale', 'en')->sole();

        expect(fn () => app(RestoreEntryRevision::class)->handle($revision, $editor->fresh()))
            ->toThrow(fn (RevisionRestoreRejectedException $e) => expect($e->reason)->toBe('locale_disabled'));
    });
});

it('refuses with snapshot_incompatible when the old layout no longer passes today\'s checks', function (array $change): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor, $change): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        [$page, $v1] = restorablePage($editor->fresh());
        // Simulate content written under an older builder / snapshot format.
        DB::table('entry_revisions')->where('id', $v1->id)->update(['snapshot' => json_encode(array_replace($v1->snapshot, $change))]);

        expect(fn () => app(RestoreEntryRevision::class)->handle($v1->fresh(), $editor->fresh()))
            ->toThrow(fn (RevisionRestoreRejectedException $e) => expect($e->reason)->toBe('snapshot_incompatible'));
        expect($page->fresh()->translationFor('vi')->body['blocks'][0]['type'])->toBe('hero');
    });
})->with([
    'a retired block type' => [['body' => ['blocks' => [['type' => 'retired_slider', 'props' => []]]]]],
    'an unsafe link' => [['body' => ['blocks' => [['type' => 'hero', 'props' => ['cta_url' => 'javascript:alert(1)']]]]]],
    'an unknown snapshot format' => [['v' => 99]],
    'a slug the editor would refuse' => [['slug' => 'Không Hợp Lệ']],
    'a wrongly typed field' => [['og_image_id' => 'abc']],
    'an excerpt longer than its column' => [['excerpt' => str_repeat('a', 256)]],
    'an SEO title longer than its column' => [['seo_title' => str_repeat('ă', 256)]],
    'a title longer than the editor allows' => [['title' => str_repeat('a', 201)]],
]);

it('refuses with entry_trashed while the entry is in the Trash', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        [$page, $v1] = restorablePage($editor->fresh());
        $page->delete();

        expect(fn () => app(RestoreEntryRevision::class)->handle($v1, $editor->fresh()))
            ->toThrow(fn (RevisionRestoreRejectedException $e) => expect($e->reason)->toBe('entry_trashed'));
    });
});

it('lets edit.own restore only its own entries and edit.any restore anyone\'s', function (): void {
    $publisher = User::factory()->create();
    $author = User::factory()->create();
    $other = User::factory()->create();
    $chief = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($publisher, $author, $other, $chief): void {
        grantRole($publisher, 'publisher', ['entries.publish']);
        grantRole($author, 'author', ['entries.edit.own']);
        grantRole($other, 'author', ['entries.edit.own']);
        grantRole($chief, 'chief', ['entries.edit.any']);
        [, $v1] = restorablePage($publisher->fresh(), $author);

        expect(fn () => app(RestoreEntryRevision::class)->handle($v1, $other->fresh()))->toThrow(AuthorizationException::class);
        expect(app(RestoreEntryRevision::class)->handle($v1, $author->fresh()))->toBe(0);
        expect(app(RestoreEntryRevision::class)->handle($v1, $chief->fresh()))->toBe(0);
    });
});

it('has a translated sentence for every refusal reason in every language', function (): void {
    foreach (glob(lang_path('*.json')) ?: [] as $file) {
        $lines = json_decode((string) file_get_contents($file), true);
        foreach ([...RevisionRestoreRejectedException::REASONS] as $reason) {
            expect($lines)->toHaveKey('entries.revision_err_'.$reason);
        }
        expect($lines)->toHaveKey('entries.err_update_forbidden');
    }
});

it('restores the shared fields and records every language when they change, leaving other languages\' text alone', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $actor = $editor->fresh();
        $first = restoreMedia('bia-dau', 'c');
        $second = restoreMedia('bia-sau', 'd');

        $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => ['image_url' => $first->storage_key]]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Trang', 'slug' => 'trang', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $page->translations()->create(['locale' => 'en', 'title' => 'Page', 'slug' => 'page', 'status' => 'draft', 'body' => ['blocks' => []]]);
        app(PublishEntry::class)->handle($page->fresh(), 'vi', $actor);
        $v1 = EntryRevision::query()->where('entry_id', $page->id)->where('locale', 'vi')->sole();

        // Later: a new cover, and new English text.
        $page->update(['meta' => ['image_url' => $second->storage_key]]);
        $page->translationFor('en')->update(['title' => 'Page, rewritten']);
        app(RecordEntryRevision::class)->forAllLocales($page->fresh(), RevisionKind::Edit, $actor);   // as a live save records them
        $before = EntryRevision::query()->where('entry_id', $page->id)->count();

        app(RestoreEntryRevision::class)->handle($v1, $actor);

        $page = $page->fresh();
        expect($page->meta['image_url'])->toBe($first->storage_key)
            ->and($page->translationFor('en')->title)->toBe('Page, rewritten');

        $restores = EntryRevision::query()->where('entry_id', $page->id)->where('kind', RevisionKind::Restore->value)->orderBy('locale')->get();
        expect($restores->pluck('locale')->all())->toBe(['en', 'vi'])
            ->and(EntryRevision::query()->where('entry_id', $page->id)->count())->toBe($before + 2)
            ->and($restores[0]->snapshot)->toMatchArray(['title' => 'Page, rewritten', 'meta' => ['image_url' => $first->storage_key]]);
    });
});

it('records only the restored language when the shared fields are unchanged', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $actor = $editor->fresh();
        [$page, $v1] = restorablePage($actor);
        $page->translations()->create(['locale' => 'en', 'title' => 'Page', 'slug' => 'page', 'status' => 'draft', 'body' => ['blocks' => []]]);
        editLivePage($page, ['title' => 'Bản hai'], $actor);

        app(RestoreEntryRevision::class)->handle($v1, $actor);

        expect(EntryRevision::query()->where('entry_id', $page->id)->where('kind', RevisionKind::Restore->value)->pluck('locale')->all())->toBe(['vi']);
    });
});

it('cannot restore another site\'s revision: the entry is not found and nothing changes', function (): void {
    $editor = User::factory()->create();

    [$page, $v1] = app(Tenancy::class)->enter(1, function () use ($editor): array {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        [$page, $v1] = restorablePage($editor->fresh());
        editLivePage($page, ['title' => 'Bản hai'], $editor->fresh());

        return [$page, $v1];
    });
    $count = app(Tenancy::class)->enter(1, fn (): int => EntryRevision::query()->count());

    app(Tenancy::class)->enter(2, function () use ($editor, $v1): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);

        expect(fn () => app(RestoreEntryRevision::class)->handle($v1, $editor->fresh()))->toThrow(ModelNotFoundException::class);
    });

    app(Tenancy::class)->enter(1, function () use ($page, $count): void {
        expect($page->fresh()->translationFor('vi')->title)->toBe('Bản hai')
            ->and(EntryRevision::query()->count())->toBe($count);
    });
    expect(AuditLog::query()->where('action', RestoreEntryRevision::AUDIT_ACTION)->exists())->toBeFalse();
});
