<?php

use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\DeleteTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\MergeTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\MoveTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\UndoTaxonomyMove;
use App\Modules\Cms\Actions\Taxonomies\UpdateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Policies\TaxonomyPolicy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Taxonomy\TaxonomyRules;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/*
 * The category details the Chuyên mục drawer edits — a description and Google title/description
 * per language, one picture named by its media key — plus the policy every category write asks,
 * the merge built on the delete, and the undo of a move that is refused once the tree changed.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
});

function tdIn(int $site, Closure $callback): mixed
{
    return app(Tenancy::class)->enter($site, $callback);
}

function tdRefusal(Closure $change): ?string
{
    try {
        $change();
    } catch (TaxonomyChangeRejectedException $e) {
        return $e->reason;
    }

    return null;
}

function tdCreate(string $name, ?Taxonomy $parent = null, array $extra = []): Taxonomy
{
    return app(CreateTaxonomy::class)->handle('post_cat', $parent, new TaxonomyData(...['names' => ['vi' => $name], ...$extra]));
}

function tdPicture(string $key, string $mime = 'image/jpeg'): Media
{
    return Media::query()->create([
        'collection' => 'library', 'slug' => 'anh-'.md5($key), 'hash' => hash('sha256', $key), 'storage_key' => $key,
        'disk' => 'r2', 'original_filename' => 'anh.jpg', 'mime' => $mime, 'size' => 1024,
    ]);
}

function tdTranslation(Taxonomy $taxonomy): ?TaxonomyTranslation
{
    return TaxonomyTranslation::query()->where('taxonomy_id', $taxonomy->id)->where('locale', 'vi')->first();
}

/** A member of site 1 holding exactly $permissions. */
function tdMember(array $permissions): User
{
    $user = User::factory()->create();
    tdIn(1, fn () => grantRole($user, 'td-'.md5(implode(',', $permissions)), $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

it('saves a description and Google title and description per language, and clears them with an empty value', function (): void {
    tdIn(1, function (): void {
        $category = tdCreate('Ứng dụng', extra: [
            'descriptions' => ['vi' => "  Ứng dụng cơ khí chính xác\nvà gia công đồ gá ạ  "],
            'seoTitles' => ['vi' => 'Ứng dụng gia công — Ạ'],
            'seoDescriptions' => ['vi' => 'Đồ gá, dao phay và mũi khoan chính hãng ở'],
        ]);

        $saved = tdTranslation($category);
        expect($saved?->description)->toBe("Ứng dụng cơ khí chính xác\nvà gia công đồ gá ạ")
            ->and($saved?->seo_title)->toBe('Ứng dụng gia công — Ạ')
            ->and($saved?->seo_description)->toBe('Đồ gá, dao phay và mũi khoan chính hãng ở');

        // A language left out keeps what it has; an empty value clears.
        app(UpdateTaxonomy::class)->handle($category, new TaxonomyData(names: ['vi' => 'Ứng dụng'], descriptions: ['vi' => '']));
        $saved = tdTranslation($category);
        expect($saved?->description)->toBeNull()->and($saved?->seo_title)->toBe('Ứng dụng gia công — Ạ');
    });
});

it('cleans control characters out of a description and refuses one past the cap', function (): void {
    tdIn(1, function (): void {
        $category = tdCreate('Tin tức', extra: ['descriptions' => ['vi' => "Đầu\u{200B}\x07 dòng\r\nhai"]]);
        expect(tdTranslation($category)?->description)->toBe("Đầu dòng\nhai");

        $long = str_repeat('ạ', TaxonomyRules::DESCRIPTION_MAX + 1);
        expect(tdRefusal(fn () => tdCreate('Quá dài', extra: ['descriptions' => ['vi' => $long]])))->toBe('description_invalid')
            ->and(tdRefusal(fn () => tdCreate('Vừa đủ', extra: ['descriptions' => ['vi' => str_repeat('ạ', TaxonomyRules::DESCRIPTION_MAX)]])))->toBeNull();
    });
});

it('refuses a Google title or description that is not one line or is longer than its column', function (): void {
    tdIn(1, function (): void {
        expect(tdRefusal(fn () => tdCreate('Một', extra: ['seoTitles' => ['vi' => "Hai\ndòng"]])))->toBe('seo_invalid')
            ->and(tdRefusal(fn () => tdCreate('Hai', extra: ['seoDescriptions' => ['vi' => str_repeat('đ', 256)]])))->toBe('seo_invalid')
            ->and(tdRefusal(fn () => tdCreate('Ba', extra: ['seoDescriptions' => ['vi' => str_repeat('đ', 255)]])))->toBeNull();
    });
});

it('sets the picture by its media key, keeps it when none is named and removes it with an empty key', function (): void {
    tdIn(1, function (): void {
        $picture = tdPicture('s/library/anh-tin-tuc.jpg');
        $category = tdCreate('Tin tức', extra: ['cover' => 's/library/anh-tin-tuc.jpg']);
        expect($category->cover_media_id)->toBe($picture->id);

        app(UpdateTaxonomy::class)->handle($category, new TaxonomyData(names: ['vi' => 'Tin tức mới']));
        expect($category->fresh()?->cover_media_id)->toBe($picture->id);

        app(UpdateTaxonomy::class)->handle($category, new TaxonomyData(cover: ''));
        expect($category->fresh()?->cover_media_id)->toBeNull();
    });
});

it('refuses another site\'s media key, a file that is not a picture and one in the trash', function (): void {
    tdIn(2, fn () => tdPicture('s/library/anh-site-b.jpg'));

    tdIn(1, function (): void {
        tdPicture('s/library/bang-gia.pdf', 'application/pdf');
        tdPicture('s/library/anh-cu.jpg')->delete();
        $category = tdCreate('Dự án');

        foreach (['s/library/anh-site-b.jpg', 's/library/bang-gia.pdf', 's/library/anh-cu.jpg', 's/library/khong-co.jpg'] as $key) {
            expect(tdRefusal(fn () => app(UpdateTaxonomy::class)->handle($category, new TaxonomyData(cover: $key))))->toBe('cover_invalid');
        }
        expect($category->fresh()?->cover_media_id)->toBeNull();
    });
});

it('lets only someone who may manage categories change them', function (): void {
    $writer = tdMember(['entries.view', 'entries.create']);
    $manager = tdMember(['taxonomies.manage']);

    tdIn(1, function () use ($writer, $manager): void {
        $category = tdCreate('Tin công ty');   // nobody signed in: provisioning, the console

        Auth::setUser($writer);
        expect(Gate::forUser($writer)->allows('manage', Taxonomy::class))->toBeFalse()
            ->and(fn () => tdCreate('Không được'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(UpdateTaxonomy::class)->handle($category, new TaxonomyData(names: ['vi' => 'Đổi'])))->toThrow(AuthorizationException::class)
            ->and(fn () => app(MoveTaxonomy::class)->handle($category, null, 0))->toThrow(AuthorizationException::class)
            ->and(fn () => app(DeleteTaxonomy::class)->handle($category))->toThrow(AuthorizationException::class);

        Auth::setUser($manager);
        expect(Gate::forUser($manager)->allows('manage', Taxonomy::class))->toBeTrue()
            ->and(tdCreate('Được phép')->exists)->toBeTrue();
    });
});

it('keeps a limited member\'s per-category abilities inside their limits', function (): void {
    $member = tdMember(['taxonomies.manage']);

    tdIn(1, function () use ($member): void {
        $root = tdCreate('Tin tức');
        $mine = tdCreate('Tin công ty', $root);
        $below = tdCreate('Sự kiện', $mine);
        $other = tdCreate('Tuyển dụng', $root);
        MemberCategoryScope::query()->create(['user_id' => $member->id, 'taxonomy_id' => $mine->id]);
        app(CategoryScopes::class)->forget();

        $policy = app(TaxonomyPolicy::class);
        expect($policy->update($member, $mine))->toBeTrue()
            ->and($policy->move($member, $mine))->toBeFalse()      // the category the limit names
            ->and($policy->update($member, $below))->toBeTrue()
            ->and($policy->move($member, $below))->toBeTrue()
            ->and($policy->update($member, $other))->toBeFalse()
            ->and($policy->delete($member, $other))->toBeFalse();
    });
});

it('merges a category into another: its items move onto the target, its sub-categories go up a level', function (): void {
    tdIn(1, function (): void {
        $root = tdCreate('Tin tức');
        $source = tdCreate('Tin cũ', $root);
        $child = tdCreate('Con của tin cũ', $source);
        $target = tdCreate('Tin mới', $root);
        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft']);
        $post->translations()->create(['locale' => 'vi', 'title' => 'Bài ạ', 'slug' => 'bai-a', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $post->assignCategories([$source->id]);

        $counts = app(MergeTaxonomy::class)->handle($source, $target);

        expect($counts['categories'])->toBe(1)->and($counts['items'])->toBe(1)
            ->and(Taxonomy::query()->whereKey($source->id)->exists())->toBeFalse()
            ->and($child->fresh()?->parent_id)->toBe($root->id)
            ->and(DB::table('entry_taxonomies')->where('entry_id', $post->id)->pluck('taxonomy_id')->all())->toBe([$target->id]);
    });
});

it('refuses to merge a category into its own branch or into another site\'s category', function (): void {
    $foreign = tdIn(2, fn () => tdCreate('Tin của B'));

    tdIn(1, function () use ($foreign): void {
        $source = tdCreate('Nguồn');
        $child = tdCreate('Con', $source);

        expect(tdRefusal(fn () => app(MergeTaxonomy::class)->handle($source, $child)))->toBe('target_invalid')
            ->and(tdRefusal(fn () => app(MergeTaxonomy::class)->handle($source, $source)))->toBe('target_invalid')
            ->and(tdRefusal(fn () => app(MergeTaxonomy::class)->handle($source, $foreign)))->toBe('target_invalid')
            ->and(Taxonomy::query()->whereKey($source->id)->exists())->toBeTrue();
    });
});

it('undoes a move back to the old parent and position while the tree is unchanged', function (): void {
    tdIn(1, function (): void {
        $a = tdCreate('A');
        $b = tdCreate('B');
        $first = tdCreate('Đầu', $a);
        $moved = tdCreate('Giữa', $a);
        $last = tdCreate('Cuối', $a);

        app(MoveTaxonomy::class)->handle($moved, $b, 0);
        $version = TaxonomyTree::version('post_cat');

        app(UndoTaxonomyMove::class)->handle($moved, $a, 1, $version);

        expect(Taxonomy::query()->where('parent_id', $a->id)->orderBy('position')->pluck('id')->all())->toBe([$first->id, $moved->id, $last->id]);
    });
});

it('refuses the undo of a move once the tree changed since', function (): void {
    tdIn(1, function (): void {
        $a = tdCreate('A');
        $b = tdCreate('B');
        $moved = tdCreate('Chuyển', $a);

        app(MoveTaxonomy::class)->handle($moved, $b, 0);
        $version = TaxonomyTree::version('post_cat');
        tdCreate('Mới thêm', $b);   // someone else added a category meanwhile

        expect(tdRefusal(fn () => app(UndoTaxonomyMove::class)->handle($moved, $a, 0, $version)))->toBe('move_stale')
            ->and($moved->fresh()?->parent_id)->toBe($b->id);
    });
});

it('changes the tree version on a move, an addition or a delete but not on a rename', function (): void {
    tdIn(1, function (): void {
        $a = tdCreate('A');
        $start = TaxonomyTree::version('post_cat');

        app(UpdateTaxonomy::class)->handle($a, new TaxonomyData(names: ['vi' => 'Đổi tên']));
        expect(TaxonomyTree::version('post_cat'))->toBe($start);

        $b = tdCreate('B');
        $added = TaxonomyTree::version('post_cat');
        expect($added)->not->toBe($start);

        app(MoveTaxonomy::class)->handle($b, $a, 0);
        expect(TaxonomyTree::version('post_cat'))->not->toBe($added);
    });
});
