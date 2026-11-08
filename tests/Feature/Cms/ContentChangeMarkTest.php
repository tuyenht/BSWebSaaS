<?php

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\RestoreEntryRevision;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\CategoryRelinker;
use App\Modules\Cms\Support\EntryDraftApplier;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * The hidden content mark of a post or page language (entry_translations.content_changed_at): it moves
 * only when the main content the page shows changes (its text fields, the cover, the main category),
 * never for an address, a layout, a status or another category, and a save that changes nothing
 * writes nothing. An old row without a mark gets one from the time it last changed.
 */

const CONTENT_MARK_OLD = '2026-01-02 03:04:05+00';
const CONTENT_MARK_OLD_ISO = '2026-01-02T03:04:05+00:00';

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A post category with a vi name, in the current site. */
function markCategory(string $name, string $slug): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);

    return $category;
}

/**
 * A draft post with one vi translation, filed under $category (or a new one); returns [post, translation].
 *
 * @param  array<string, mixed>  $meta
 * @return array{0: Entry, 1: EntryTranslation}
 */
function markPost(string $slug = 'an-pham-may-phay', ?Taxonomy $category = null, array $meta = [], string $type = 'post'): array
{
    $post = Entry::query()->create(['type' => $type, 'status' => 'draft', 'meta' => $meta]);
    $translation = $post->translations()->create([
        'locale' => 'vi', 'title' => 'Ấn phẩm máy phay mới về phố', 'slug' => $slug, 'status' => 'draft',
        'excerpt' => 'Ước tính chi phí cả năm ở',
        'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Đề án mùa hè ở']]]],
    ]);
    if ($type === 'post') {
        $post->assignCategories([($category ?? markCategory('Ống thép mạ kẽm', 'ong-thep-'.$slug))->id]);
    }

    return [$post->fresh(), $translation->fresh()];
}

/**
 * Every content row of the current site: its mark (and updated_at) set back to the old time, past the
 * model. On the tenant connection, inside the caller's transaction (RLS keeps it to the site).
 */
function pinMarks(string $table = 'entry_translations'): void
{
    DB::table($table)->update(['content_changed_at' => CONTENT_MARK_OLD, 'updated_at' => CONTENT_MARK_OLD]);
}

/** The stored mark of one row of the current site, read past the model, as ISO 8601 UTC (null: none). */
function markOf(int $id, string $table = 'entry_translations'): ?string
{
    $value = DB::table($table)->where('id', $id)->value('content_changed_at');

    return $value === null ? null : CarbonImmutable::parse((string) $value)->utc()->toIso8601String();
}

/** An editor who may edit and publish any entry. */
function markPublisher(): User
{
    $user = User::factory()->create();
    grantRole($user, 'editor', ['entries.edit.any', 'entries.publish']);

    return $user->fresh();
}

it('starts a new language with a mark', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [, $translation] = markPost();

        expect(markOf($translation->id))->not->toBeNull()
            ->and($translation->content_changed_at)->not->toBeNull();
    });
});

it('moves the mark when a field of the main content changes', function (array $change): void {
    app(Tenancy::class)->enter(1, function () use ($change): void {
        [, $translation] = markPost();
        pinMarks();

        $translation->refresh()->update($change);

        expect(markOf($translation->id))->not->toBeNull()->not->toBe(CONTENT_MARK_OLD_ISO);
    });
})->with([
    'the title' => [['title' => 'Ấn phẩm đổi tên mới về phố']],
    'the body' => [['body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Ước tính mới ở']]]]]],
    'the excerpt' => [['excerpt' => 'Ước tính chi phí năm sau ở']],
    'the title for Google' => [['seo_title' => 'Ấn phẩm cho Google về phố']],
    'the description for Google' => [['seo_description' => 'Ước tính mô tả cho Google ở']],
]);

it('leaves the mark for a save of anything else on the language', function (array $change): void {
    app(Tenancy::class)->enter(1, function () use ($change): void {
        [, $translation] = markPost();
        pinMarks();

        $translation->refresh()->update($change);

        expect(markOf($translation->id))->toBe(CONTENT_MARK_OLD_ISO)
            ->and((string) DB::table('entry_translations')->where('id', $translation->id)->value('updated_at'))->not->toStartWith('2026-01-02');
    });
})->with([
    'its review status' => [['status' => 'published']],
    'its address' => [['slug' => 'an-pham-dia-chi-moi']],
    'the share image column the page does not use' => [['og_image_id' => 42]],
]);

it('leaves the mark for a save of the entry that keeps its cover', function (array $change): void {
    app(Tenancy::class)->enter(1, function () use ($change): void {
        [$post, $translation] = markPost(meta: ['image_url' => 's/library/ong-thep-abcdef123456.webp', 'layout' => 'wide']);
        pinMarks();

        $post->update($change);

        expect(markOf($translation->id))->toBe(CONTENT_MARK_OLD_ISO);
    });
})->with([
    'its layout' => [['template_override' => 'post-wide']],
    'its place in a list' => [['position' => 5]],
    'its view count' => [['view_count' => 12]],
    'another part of meta' => [['meta' => ['image_url' => 's/library/ong-thep-abcdef123456.webp', 'layout' => 'narrow']]],
]);

it('moves the mark of every language when the cover changes, and of no other entry', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$post, $vi] = markPost(meta: ['image_url' => 's/library/ong-thep-abcdef123456.webp']);
        $en = $post->translations()->create(['locale' => 'en', 'title' => 'Ấn phẩm tiếng Anh ở', 'slug' => 'an-pham-en', 'status' => 'draft', 'body' => ['blocks' => []]]);
        [, $other] = markPost('bai-khac');
        pinMarks();

        $post->update(['meta' => ['image_url' => 's/library/kho-thep-fedcba654321.webp']]);

        expect(markOf($vi->id))->not->toBe(CONTENT_MARK_OLD_ISO)
            ->and(markOf($en->id))->not->toBe(CONTENT_MARK_OLD_ISO)
            ->and(markOf($other->id))->toBe(CONTENT_MARK_OLD_ISO);

        pinMarks();
        $post->refresh()->update(['meta' => []]);   // the cover taken off is a change too
        expect(markOf($vi->id))->not->toBe(CONTENT_MARK_OLD_ISO);
    });
});

it('writes nothing for a save that changes nothing', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$post, $translation] = markPost();
        pinMarks();
        $translation->refresh();
        $post->refresh();

        $translation->fill(['title' => $translation->title, 'body' => $translation->body, 'excerpt' => $translation->excerpt]);
        $post->fill(['meta' => $post->meta]);

        expect(countQueries(fn () => $translation->save()))->toBe(0)
            ->and(countQueries(fn () => $post->save()))->toBe(0)
            ->and(markOf($translation->id))->toBe(CONTENT_MARK_OLD_ISO);
    });
});

it('leaves the mark when the body is cleaned again by the command', function (): void {
    $translationId = app(Tenancy::class)->enter(1, function (): int {
        [, $translation] = markPost();
        DB::table('entry_translations')->where('id', $translation->id)->update(['body' => json_encode(['blocks' => [
            ['type' => 'richtext_html', 'props' => ['html' => '<p onclick="steal()">Đoạn văn ở</p><script>alert(1)</script>']],
        ]])]);

        pinMarks();

        return $translation->id;
    });

    $this->artisan('cms:resanitize-bodies')->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($translationId): void {
        $body = (string) DB::table('entry_translations')->where('id', $translationId)->value('body');
        expect($body)->toContain('Đoạn văn ở')->not->toContain('onclick')->not->toContain('<script')
            ->and(markOf($translationId))->toBe(CONTENT_MARK_OLD_ISO);
    });
});

it('fills an empty mark from the time the row last changed, on the first save that keeps the content', function (): void {
    $actor = markPublisher();

    app(Tenancy::class)->enter(1, function () use ($actor): void {
        [$page, $translation] = markPost('trang-gioi-thieu', type: 'page');
        // A row from before the mark existed.
        DB::table('entry_translations')->where('id', $translation->id)
            ->update(['content_changed_at' => null, 'updated_at' => CONTENT_MARK_OLD]);

        // Publishing saves the language's status first: the mark is filled from the old updated_at.
        app(PublishEntry::class)->handle($page->fresh(), 'vi', $actor);
        expect(markOf($translation->id))->toBe(CONTENT_MARK_OLD_ISO)
            ->and(EntryTranslation::query()->findOrFail($translation->id)->status)->toBe('published');

        // The next content change moves it on.
        EntryTranslation::query()->findOrFail($translation->id)->update(['title' => 'Ấn phẩm giới thiệu mới về phố']);
        expect(markOf($translation->id))->not->toBe(CONTENT_MARK_OLD_ISO);
    });
});

it('never fills over a mark another write has just set', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$post, $translation] = markPost();
        DB::table('entry_translations')->where('id', $translation->id)
            ->update(['content_changed_at' => null, 'updated_at' => CONTENT_MARK_OLD]);
        $loaded = EntryTranslation::query()->findOrFail($translation->id);   // read before the mark

        Entry::markContentChanged([$post->id]);
        $marked = markOf($translation->id);
        $loaded->update(['status' => 'published']);

        expect($marked)->not->toBeNull()->not->toBe(CONTENT_MARK_OLD_ISO)
            ->and(markOf($translation->id))->toBe($marked);
    });
});

it('moves an empty mark to now on a content save', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [, $translation] = markPost();
        DB::table('entry_translations')->where('id', $translation->id)
            ->update(['content_changed_at' => null, 'updated_at' => CONTENT_MARK_OLD]);

        EntryTranslation::query()->findOrFail($translation->id)->update(['excerpt' => 'Ước tính mới cho năm sau ở']);

        expect(markOf($translation->id))->not->toBeNull()->not->toBe(CONTENT_MARK_OLD_ISO);
    });
});

it('moves the mark on an applied change only when it changes the content', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$page, $translation] = markPost('trang-du-an', type: 'page');
        $snapshot = RecordEntryRevision::snapshot($page, $translation);
        $change = fn (array $locale): EntryDraft => new EntryDraft(['entry_id' => $page->id, 'content' => [
            'v' => RecordEntryRevision::FORMAT,
            'shared' => array_intersect_key($snapshot, array_flip(['template_override', 'cover_media_id', 'meta'])),
            'locales' => ['vi' => array_replace(array_intersect_key($snapshot, array_flip(EntryDraft::LOCALE_FIELDS)), $locale)],
        ]]);
        pinMarks();

        app(EntryDraftApplier::class)->apply($page->fresh(), $change(['slug' => 'trang-du-an-moi']));
        expect(EntryTranslation::query()->findOrFail($translation->id)->slug)->toBe('trang-du-an-moi')
            ->and(markOf($translation->id))->toBe(CONTENT_MARK_OLD_ISO);

        app(EntryDraftApplier::class)->apply($page->fresh(), $change(['slug' => 'trang-du-an-moi', 'title' => 'Ấn phẩm đã duyệt về phố']));
        expect(markOf($translation->id))->not->toBe(CONTENT_MARK_OLD_ISO);
    });
});

it('moves the mark on a restored revision only when it brings other content back', function (): void {
    $actor = markPublisher();

    app(Tenancy::class)->enter(1, function () use ($actor): void {
        [$page, $translation] = markPost('trang-lich-su', type: 'page');
        app(PublishEntry::class)->handle($page->fresh(), 'vi', $actor);
        $v1 = EntryRevision::query()->where('entry_id', $page->id)->where('locale', 'vi')->sole();

        // A later version that only moved the address: restoring v1 brings back the old address alone.
        $translation->refresh()->update(['slug' => 'trang-lich-su-moi']);
        app(RecordEntryRevision::class)->forLocale($page->fresh(), $translation->fresh(), RevisionKind::Edit, $actor);
        pinMarks();
        app(RestoreEntryRevision::class)->handle($v1, $actor);
        expect(EntryTranslation::query()->findOrFail($translation->id)->slug)->toBe('trang-lich-su')
            ->and(markOf($translation->id))->toBe(CONTENT_MARK_OLD_ISO);

        // A later version with another title: restoring v1 brings the old title back.
        EntryTranslation::query()->findOrFail($translation->id)->update(['title' => 'Ấn phẩm bản hai về phố']);
        app(RecordEntryRevision::class)->forLocale($page->fresh(), $translation->fresh(), RevisionKind::Edit, $actor);
        pinMarks();
        app(RestoreEntryRevision::class)->handle($v1, $actor);
        expect(markOf($translation->id))->not->toBe(CONTENT_MARK_OLD_ISO);
    });
});

it('leaves the mark through publish, unpublish, republish, the trash and a new schedule', function (): void {
    $actor = markPublisher();

    app(Tenancy::class)->enter(1, function () use ($actor): void {
        [$page, $translation] = markPost('trang-lich-dang', type: 'page');
        pinMarks();

        app(PublishEntry::class)->handle($page->fresh(), 'vi', $actor);
        app(TransitionEntryStatus::class)->handle($page->fresh(), EntryStatus::Draft, $actor);
        app(PublishEntry::class)->handle($page->fresh(), 'vi', $actor);
        $page->fresh()->delete();
        Entry::withTrashed()->findOrFail($page->id)->restore();
        expect(markOf($translation->id))->toBe(CONTENT_MARK_OLD_ISO);

        // Scheduled again for later: the content date follows the new publish time, as datePublished does.
        $later = CarbonImmutable::now()->addDays(3)->startOfSecond();
        app(TransitionEntryStatus::class)->handle($page->fresh(), EntryStatus::Scheduled, $actor, $later);
        $page = $page->fresh();
        $stored = EntryTranslation::query()->findOrFail($translation->id);
        expect(markOf($translation->id))->toBe(CONTENT_MARK_OLD_ISO)
            ->and($stored->contentDate($page->published_at)?->toIso8601String())->toBe($later->utc()->toIso8601String())
            ->and($stored->contentDate(null)?->toIso8601String())->toBe(CONTENT_MARK_OLD_ISO);
    });
});

it('moves the mark when the main category changes, never for another category', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $main = markCategory('Ống thép mạ kẽm', 'ong-thep');
        $second = markCategory('Ấn phẩm số hóa', 'an-pham-so-hoa');
        $third = markCategory('Đồ gá kiểm tra độ', 'do-ga');
        [$post, $translation] = markPost(category: $main);
        pinMarks();

        $post->assignCategories([$main->id, $second->id]);
        $post->assignCategories([$main->id, $third->id]);
        $post->assignCategories([$main->id, $third->id]);
        expect(markOf($translation->id))->toBe(CONTENT_MARK_OLD_ISO);

        $post->assignCategories([$third->id, $main->id]);
        expect(markOf($translation->id))->not->toBe(CONTENT_MARK_OLD_ISO);
    });
});

it('moves the mark of the posts whose main category a delete or a merge changes', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $gone = markCategory('Ống thép cũ ở', 'ong-thep-cu');
        $kept = markCategory('Ấn phẩm số hóa', 'an-pham-so-hoa');
        $target = markCategory('Đồ gá kiểm tra độ', 'do-ga');
        [$primary, $primaryText] = markPost('bai-chinh', $gone);
        [$secondary, $secondaryText] = markPost('bai-phu', $kept);
        $secondary->assignCategories([$kept->id, $gone->id]);
        pinMarks();

        // Merged into the target: the post filed under it first moves, the other keeps its main one.
        app(CategoryRelinker::class)->refile([$gone->id], $target->id);
        expect($primary->fresh()->primaryCategory()?->id)->toBe($target->id)
            ->and(markOf($primaryText->id))->not->toBe(CONTENT_MARK_OLD_ISO)
            ->and(markOf($secondaryText->id))->toBe(CONTENT_MARK_OLD_ISO);

        // Deleted with nowhere to go: the next category moves up and becomes the main one.
        pinMarks();
        app(CategoryRelinker::class)->refile([$kept->id], null);
        expect($secondary->fresh()->primaryCategory()?->id)->toBe($target->id)
            ->and(markOf($secondaryText->id))->not->toBe(CONTENT_MARK_OLD_ISO)
            ->and(markOf($primaryText->id))->toBe(CONTENT_MARK_OLD_ISO);
    });
});

it('marks only rows of the current site', function (): void {
    [$otherPost, $otherText] = app(Tenancy::class)->enter(2, function (): array {
        $other = markPost('bai-site-khac');
        pinMarks();

        return $other;
    });

    app(Tenancy::class)->enter(1, function () use ($otherPost): void {
        [$post, $translation] = markPost();
        pinMarks();

        Entry::markContentChanged([$post->id, $otherPost->id]);

        expect(markOf($translation->id))->not->toBe(CONTENT_MARK_OLD_ISO);
    });
    app(Tenancy::class)->enter(2, fn () => expect(markOf($otherText->id))->toBe(CONTENT_MARK_OLD_ISO));
});
