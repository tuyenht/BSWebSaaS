<?php

use App\Modules\Cms\Actions\DuplicateEntry;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\UpdateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\EntryContentWriter;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * Every write of a category's list/detail layout and of an entry's own layout is checked on the
 * server against the theme's template list: the role (list, post, page) and the kind of category.
 * The reserved "follow the parent" value is accepted on categories only.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function trMake(string $vocabulary, ?string $list, ?string $detail, ?Taxonomy $parent = null, string $name = 'Nhóm mới'): Taxonomy
{
    return app(CreateTaxonomy::class)->handle($vocabulary, $parent, new TaxonomyData(names: ['vi' => $name], listTemplate: $list, detailTemplate: $detail));
}

function trRefusal(Closure $change): ?string
{
    try {
        $change();
    } catch (TaxonomyChangeRejectedException $e) {
        return $e->reason;
    }

    return null;
}

it('creates a category with a layout set, a job pair or the follow-the-parent value', function (string $vocabulary, ?string $list, ?string $detail): void {
    app(Tenancy::class)->enter(1, function () use ($vocabulary, $list, $detail): void {
        $parent = trMake($vocabulary, null, null, null, 'Cha');
        $child = trMake($vocabulary, $list, $detail, $parent);

        expect($child->list_template)->toBe($list)->and($child->detail_template)->toBe($detail);
    });
})->with([
    'a job pair' => ['post_cat', 'grid-job', 'job'],
    'follow the parent' => ['post_cat', '@parent', '@parent'],
    'a product grid on a product category' => ['product_cat', 'grid-product', '@parent'],
]);

it('refuses a layout of the wrong role or kind on create', function (string $vocabulary, ?string $list, ?string $detail): void {
    app(Tenancy::class)->enter(1, function () use ($vocabulary, $list, $detail): void {
        expect(trRefusal(fn () => trMake($vocabulary, $list, $detail)))->toBe('template_invalid');
    });
})->with([
    'a post layout as the list' => ['post_cat', 'post', null],
    'a page layout as the list' => ['post_cat', 'page', null],
    'a missing layout as the list' => ['post_cat', 'khong-co', null],
    'a list layout as the post layout' => ['post_cat', null, 'grid-job'],
    'the not-found layout as the post layout' => ['post_cat', null, 'not-found'],
    'a news grid on a product category' => ['product_cat', 'grid-news', null],
]);

it('refuses a layout of the wrong role or kind on update and still keeps one that is already stored', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $category = trMake('post_cat', 'grid-news', 'post');
        $update = fn (?string $list, ?string $detail): Taxonomy => app(UpdateTaxonomy::class)->handle($category->fresh(), new TaxonomyData(names: ['vi' => 'Nhóm mới'], listTemplate: $list, detailTemplate: $detail));

        expect(trRefusal(fn () => $update('post', null)))->toBe('template_invalid')
            ->and(trRefusal(fn () => $update(null, 'grid-news')))->toBe('template_invalid')
            ->and($update('grid-job', 'job')->only(['list_template', 'detail_template']))->toBe(['list_template' => 'grid-job', 'detail_template' => 'job'])
            ->and($update('@parent', '@parent')->only(['list_template', 'detail_template']))->toBe(['list_template' => '@parent', 'detail_template' => '@parent']);

        // A value stored before the rule existed stays savable while it is left alone.
        $category->forceFill(['list_template' => 'post'])->save();
        expect($update('post', '@parent')->list_template)->toBe('post');
    });
});

it('refuses an own layout of the wrong role when snapshot content is read for a draft or a restore', function (string $type, ?string $layout, bool $accepted): void {
    app(Tenancy::class)->enter(1, function () use ($type, $layout, $accepted): void {
        $entry = Entry::query()->create(['type' => $type, 'status' => 'draft']);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Bài', 'slug' => 'bai', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $snapshot = RecordEntryRevision::snapshot($entry->fresh(), $entry->translationFor('vi')) + [];
        $snapshot['template_override'] = $layout;
        $read = app(EntryContentWriter::class)->read($snapshot, $type);

        expect($read !== null)->toBe($accepted);
    });
})->with([
    'a job layout on a post' => ['post', 'job', true],
    'a list layout on a post' => ['post', 'grid-news', false],
    'a post layout on a page' => ['page', 'post', false],
    'the page layout on a page' => ['page', 'page', true],
    'the reserved parent value' => ['post', '@parent', false],
    'a layout the theme lacks' => ['post', 'khong-co', false],
    'none' => ['page', null, true],
]);

it('does not carry an own layout of the wrong role into a copy', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create']);
        $good = Entry::query()->create(['type' => 'post', 'status' => 'published', 'template_override' => 'job']);
        $bad = Entry::query()->create(['type' => 'post', 'status' => 'published', 'template_override' => 'grid-news']);
        foreach ([$good, $bad] as $n => $entry) {
            $entry->translations()->create(['locale' => 'vi', 'title' => 'Tin '.$n, 'slug' => 'tin-'.$n, 'status' => 'published', 'body' => ['blocks' => []]]);
        }

        $copyGood = app(DuplicateEntry::class)->handle($good->fresh(), $editor->fresh());
        $copyBad = app(DuplicateEntry::class)->handle($bad->fresh(), $editor->fresh());

        expect($copyGood->template_override)->toBe('job')->and($copyBad->template_override)->toBeNull();
    });
});
