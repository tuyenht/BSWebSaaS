<?php

use App\Modules\Admin\Support\MediaUsageLocator;
use App\Modules\Admin\Support\Settings\SetLayoutBlocks;
use App\Modules\Admin\Support\Settings\SettingsChangeRejected;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\DeleteTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\SetCategoryLayoutBlocks;
use App\Modules\Cms\Actions\Taxonomies\UpdateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Exceptions\LayoutBlocksRejectedException;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\LayoutSlotBlocks;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Rendering\LayoutSlots;
use App\Modules\Theme\Theming\StorefrontSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
 * The slots around posts, lists and products: the site's default blocks (a setting, behind the
 * settings permission) and a category's own choice (its meta, behind the category policy), one
 * block list per language, checked like a page body in the site's context. The resolver reads the
 * category first, then the site, falling back to the default language, at no extra query. The
 * pictures and files the slots name are recorded as "in use" in the library on every save, for this
 * site's media only, and leave with their owner.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

function lsSettingsAdmin(int $siteId = 1): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter($siteId, fn () => grantRole($admin, 'site-admin', ['settings.manage', 'taxonomies.manage']));
    app(TeamContext::class)->forget();

    return $admin->fresh() ?? $admin;
}

function lsPicture(int $siteId, string $slug): Media
{
    return app(Tenancy::class)->enter($siteId, fn (): Media => Media::query()->create([
        'collection' => 'library', 'slug' => $slug, 'hash' => hash('sha256', "{$siteId}-{$slug}"),
        'storage_key' => "s{$siteId}/library/{$slug}-".substr(hash('sha256', $slug), 0, 12).'.png', 'disk' => 'r2',
        'mime' => 'image/png', 'size' => 1024,
    ]));
}

function lsDocument(int $siteId, string $slug): Media
{
    return app(Tenancy::class)->enter($siteId, fn (): Media => Media::query()->create([
        'collection' => 'library', 'slug' => $slug, 'hash' => hash('sha256', "{$siteId}-{$slug}-doc"),
        'storage_key' => "s{$siteId}/library/{$slug}-".substr(hash('sha256', "{$slug}-doc"), 0, 12).'.pdf', 'disk' => 'r2',
        'mime' => 'application/pdf', 'size' => 2048, 'original_filename' => 'ho-so.pdf',
    ]));
}

/** @return array<string, array{blocks: list<array<string, mixed>>}> */
function lsHero(int $mediaId, string $locale = 'vi'): array
{
    return [$locale => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Đăng ký ngay để được hỗ trợ', 'image_url' => $mediaId]]]]];
}

/** @return array<string, array{blocks: list<array<string, mixed>>}> */
function lsCta(string $heading, string $locale = 'vi'): array
{
    return [$locale => ['blocks' => [['type' => 'cta', 'props' => ['heading' => $heading]]]]];
}

function lsCategory(int $siteId, string $name, string $vocabulary = 'post_cat'): Taxonomy
{
    return app(Tenancy::class)->enter($siteId, fn (): Taxonomy => app(CreateTaxonomy::class)->handle($vocabulary, null, new TaxonomyData(names: ['vi' => $name])));
}

/**
 * The media ids recorded as used by one owner, read past RLS so another site's row would show.
 *
 * @return list<int>
 */
function lsUsage(string $type, int $ownerId): array
{
    return DB::connection('pgsql_admin')->table('mediables')
        ->where('mediable_type', $type)->where('mediable_id', $ownerId)->where('collection', 'content')
        ->orderBy('media_id')->pluck('media_id')->map(fn ($id): int => (int) $id)->all();
}

function lsSettingId(int $siteId): int
{
    return (int) DB::connection('pgsql_admin')->table('site_settings')
        ->where('site_id', $siteId)->where('group_name', 'storefront')->where('key', 'layout_blocks')->value('id');
}

/** Why a site default save was refused, or null. */
function lsSettingsRefusal(array $slots): ?string
{
    try {
        app(SetLayoutBlocks::class)->handle($slots);
    } catch (SettingsChangeRejected $e) {
        return $e->reason;
    }

    return null;
}

/** Why a category save was refused, or null. */
function lsCategoryRefusal(Taxonomy $category, array $slots): ?string
{
    try {
        app(SetCategoryLayoutBlocks::class)->handle($category, $slots);
    } catch (TaxonomyChangeRejectedException $e) {
        return $e->reason;
    }

    return null;
}

function lsEnableEnglish(int $siteId): void
{
    DB::connection('pgsql_admin')->table('site_locales')->insert([
        'site_id' => $siteId, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(Tenancy::class)->enter($siteId, fn () => app(SiteLocale::class)->forget());
}

describe('saving', function (): void {
    it('stores every slot of the site default as one block list per language, and reads it back', function (): void {
        $this->actingAs(lsSettingsAdmin());
        $slots = [];
        foreach (LayoutSlots::SLOTS as $slot) {
            $slots[$slot] = lsCta("Ưu đãi {$slot} ở");
        }

        app(Tenancy::class)->enter(1, fn () => app(SetLayoutBlocks::class)->handle($slots));

        $stored = app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('group_name', 'storefront')->where('key', 'layout_blocks')->value('value'));
        expect(array_keys($stored))->toEqualCanonicalizing(LayoutSlots::SLOTS);
        foreach (LayoutSlots::SLOTS as $slot) {
            expect($stored[$slot]['vi']['blocks'][0]['props']['heading'])->toBe("Ưu đãi {$slot} ở");
        }
    });

    it('keeps the slots a save does not name, and removes one given null', function (): void {
        $this->actingAs(lsSettingsAdmin());
        app(Tenancy::class)->enter(1, function (): void {
            app(SetLayoutBlocks::class)->handle(['post_aside' => lsCta('Ứng dụng thực tế'), 'after_list' => lsCta('Đồ gá cơ khí')]);
            app(SetLayoutBlocks::class)->handle(['after_list' => null, 'after_post' => lsCta('Đăng ký ngay để được hỗ trợ')]);

            expect(array_keys(SiteSetting::query()->where('key', 'layout_blocks')->value('value')))->toEqualCanonicalizing(['post_aside', 'after_post']);
        });
    });

    it('stores every slot of a category, and "none" for one', function (): void {
        $category = lsCategory(1, 'Đồ gá cơ khí');
        app(Tenancy::class)->enter(1, function () use ($category): void {
            $slots = array_fill_keys(LayoutSlots::SLOTS, lsCta('Ứng dụng thực tế'));
            $slots['after_post'] = 'none';
            app(SetCategoryLayoutBlocks::class)->handle($category, $slots);

            $layout = Taxonomy::query()->whereKey($category->id)->value('meta')['layout_blocks'];
            expect(array_keys($layout))->toEqualCanonicalizing(LayoutSlots::SLOTS)
                ->and($layout['after_post'])->toBe('none')
                ->and($layout['post_aside']['vi']['blocks'][0]['props']['heading'])->toBe('Ứng dụng thực tế');

            // null gives the slot back to the site's default; no slot left removes the key.
            app(SetCategoryLayoutBlocks::class)->handle($category, array_fill_keys(LayoutSlots::SLOTS, null));
            expect(Taxonomy::query()->whereKey($category->id)->value('meta'))->not->toHaveKey('layout_blocks');
        });
    });

    it('cleans formatted text the way a page body is cleaned', function (): void {
        $this->actingAs(lsSettingsAdmin());
        app(Tenancy::class)->enter(1, function (): void {
            app(SetLayoutBlocks::class)->handle(['product_tabs' => ['vi' => ['blocks' => [
                ['type' => 'richtext_html', 'props' => ['html' => '<p>Ổn định lâu dài ở</p><script>alert(1)</script>']],
            ]]]]);
            $html = SiteSetting::query()->where('key', 'layout_blocks')->value('value')['product_tabs']['vi']['blocks'][0]['props']['html'];

            expect($html)->toContain('Ổn định lâu dài ở')->not->toContain('script');
        });
    });

    it('refuses, with a reason, a bad block, another site\'s page or file, an unknown language or slot, and too many tabs', function (): void {
        $foreignPage = app(Tenancy::class)->enter(2, function (): string {
            $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
            $entry->translations()->create(['locale' => 'vi', 'title' => 'Đường dây liên hệ', 'slug' => 'lien-he', 'status' => 'published', 'body' => ['blocks' => []]]);

            return (string) $entry->ulid;
        });
        $foreignFile = lsDocument(2, 'ho-so');
        $category = lsCategory(1, 'Đồ gá cơ khí');
        $this->actingAs(lsSettingsAdmin());

        app(Tenancy::class)->enter(1, function () use ($foreignPage, $foreignFile, $category): void {
            $button = fn (array $do): array => ['vi' => ['blocks' => [['type' => 'cta', 'props' => ['button_label' => 'Ấn để xem hồ sơ', 'button_url_do' => $do]]]]];
            $tab = ['type' => 'richtext_html', 'props' => ['html' => '<p>Ưu đãi ở</p>']];
            $cases = [
                'bad block' => [['post_aside' => ['vi' => ['blocks' => [['type' => 'unknown_block']]]]], 'blocks_invalid'],
                'not a block list' => [['post_aside' => ['vi' => 'Ưu đãi ở']], 'blocks_invalid'],
                'another site\'s page' => [['after_post' => $button(['k' => 'page', 'page' => $foreignPage])], 'blocks_invalid'],
                'another site\'s file' => [['after_post' => $button(['k' => 'file', 'file' => $foreignFile->storage_key])], 'blocks_invalid'],
                'unknown language' => [['after_list' => lsCta('Ưu đãi ở', 'en')], 'locale_invalid'],
                'unknown slot' => [['sidebar' => lsCta('Ưu đãi ở')], 'slot_invalid'],
                'four tabs' => [['product_tabs' => ['vi' => ['blocks' => [$tab, $tab, $tab, $tab]]]], 'blocks_too_many'],
            ];

            foreach ($cases as $name => [$slots, $reason]) {
                expect(lsSettingsRefusal($slots))->toBe($reason, $name)
                    ->and(lsCategoryRefusal($category, $slots))->toBe($reason, $name);
            }

            // The screen can say which slot and why, in plain words.
            $refusal = null;
            try {
                app(SetLayoutBlocks::class)->handle(['after_post' => $button(['k' => 'page', 'page' => $foreignPage])]);
            } catch (SettingsChangeRejected $e) {
                $refusal = $e;
            }
            expect($refusal?->field)->toBe('after_post.vi')
                ->and($refusal?->getPrevious())->toBeInstanceOf(LayoutBlocksRejectedException::class)
                ->and($refusal?->getPrevious()?->getMessage())->toBe(__('entries.err_block_button_page_missing'));

            // "none" is a category's choice only; three tabs fit.
            expect(lsSettingsRefusal(['post_aside' => 'none']))->toBe('blocks_invalid')
                ->and(lsSettingsRefusal(['product_tabs' => ['vi' => ['blocks' => [$tab, $tab, $tab]]]]))->toBeNull()
                ->and(Taxonomy::query()->whereKey($category->id)->value('meta'))->not->toHaveKey('layout_blocks')
                ->and(SiteSetting::query()->where('key', 'layout_blocks')->value('value'))->toHaveKey('product_tabs')->not->toHaveKey('after_post');
        });
    });

    it('denies the site default to someone without the settings permission', function (): void {
        $editor = User::factory()->create();
        app(Tenancy::class)->enter(1, fn () => grantRole($editor, 'editor', ['entries.view']));
        app(TeamContext::class)->forget();
        $this->actingAs($editor->fresh());

        expect(fn () => app(Tenancy::class)->enter(1, fn () => app(SetLayoutBlocks::class)->handle(['post_aside' => lsCta('Ưu đãi ở')])))
            ->toThrow(AuthorizationException::class);
        expect(lsSettingId(1))->toBe(0);
    });

    it('denies a category override to someone the category policy does not let update it', function (): void {
        $category = lsCategory(1, 'Đồ gá cơ khí');
        $editor = User::factory()->create();
        app(Tenancy::class)->enter(1, fn () => grantRole($editor, 'editor', ['settings.manage']));
        app(TeamContext::class)->forget();
        $this->actingAs($editor->fresh());

        expect(fn () => app(Tenancy::class)->enter(1, fn () => app(SetCategoryLayoutBlocks::class)->handle($category, ['post_aside' => lsCta('Ưu đãi ở')])))
            ->toThrow(AuthorizationException::class);
        expect(app(Tenancy::class)->enter(1, fn () => Taxonomy::query()->whereKey($category->id)->value('meta')))->not->toHaveKey('layout_blocks');
    });
    it('refuses a category override to a member limited to other categories, and allows their own', function (): void {
        $own = lsCategory(1, 'Đồ gá cơ khí');
        $other = lsCategory(1, 'Ứng dụng thực tế');
        $member = User::factory()->create();
        app(Tenancy::class)->enter(1, function () use ($member, $own): void {
            grantRole($member, 'category-editor', ['settings.manage', 'taxonomies.manage']);
            MemberCategoryScope::query()->create(['user_id' => $member->id, 'taxonomy_id' => $own->id]);
        });
        app(TeamContext::class)->forget();
        $this->actingAs($member->fresh());

        expect(fn () => app(Tenancy::class)->enter(1, fn () => app(SetCategoryLayoutBlocks::class)->handle($other, ['post_aside' => lsCta('Ưu đãi ở')])))
            ->toThrow(AuthorizationException::class);
        expect(app(Tenancy::class)->enter(1, fn () => Taxonomy::query()->whereKey($other->id)->value('meta')))->not->toHaveKey('layout_blocks');

        app(Tenancy::class)->enter(1, fn () => app(SetCategoryLayoutBlocks::class)->handle($own, ['post_aside' => lsCta('Ưu đãi riêng ở')]));
        expect(app(Tenancy::class)->enter(1, fn () => Taxonomy::query()->whereKey($own->id)->value('meta')))->toHaveKey('layout_blocks');
    });
});

describe('reading', function (): void {
    it('shows the category\'s own blocks over the site default, nothing for "none", and the default otherwise', function (): void {
        $own = lsCategory(1, 'Đồ gá cơ khí');
        $hidden = lsCategory(1, 'Ứng dụng thực tế');
        $plain = lsCategory(1, 'Ống thép cỡ lớn ở');
        $this->actingAs(lsSettingsAdmin());

        app(Tenancy::class)->enter(1, function () use ($own, $hidden, $plain): void {
            app(SetLayoutBlocks::class)->handle(['post_aside' => lsCta('Đăng ký ngay để được hỗ trợ')]);
            app(SetCategoryLayoutBlocks::class)->handle($own, ['post_aside' => lsCta('Ưu đãi riêng của chuyên mục ở')]);
            app(SetCategoryLayoutBlocks::class)->handle($hidden, ['post_aside' => 'none']);
            $resolver = app(LayoutSlotBlocks::class);
            $heading = fn (array $blocks): ?string => $blocks[0]['props']['heading'] ?? null;

            expect($heading($resolver->blocks('post_aside', 'vi', $own->fresh())))->toBe('Ưu đãi riêng của chuyên mục ở')
                ->and($resolver->blocks('post_aside', 'vi', $hidden->fresh()))->toBe([])
                ->and($heading($resolver->blocks('post_aside', 'vi', $plain->fresh())))->toBe('Đăng ký ngay để được hỗ trợ')
                ->and($heading($resolver->blocks('post_aside', 'vi')))->toBe('Đăng ký ngay để được hỗ trợ')
                ->and($resolver->blocks('after_list', 'vi', $own->fresh()))->toBe([])
                ->and($resolver->blocks('sidebar', 'vi', $own->fresh()))->toBe([]);
        });
    });

    it('falls back to the default language when a language has no blocks of its own', function (): void {
        lsEnableEnglish(1);
        $category = lsCategory(1, 'Đồ gá cơ khí');
        $this->actingAs(lsSettingsAdmin());

        app(Tenancy::class)->enter(1, function () use ($category): void {
            app(SetLayoutBlocks::class)->handle(['after_post' => [...lsCta('Đăng ký ngay để được hỗ trợ'), ...lsCta('Contact us', 'en')], 'after_list' => lsCta('Ứng dụng thực tế')]);
            app(SetCategoryLayoutBlocks::class)->handle($category, ['after_list' => lsCta('Ưu đãi riêng ở')]);
            $resolver = app(LayoutSlotBlocks::class);
            $heading = fn (array $blocks): ?string => $blocks[0]['props']['heading'] ?? null;

            expect($heading($resolver->blocks('after_post', 'en')))->toBe('Contact us')
                ->and($heading($resolver->blocks('after_list', 'en')))->toBe('Ứng dụng thực tế')
                ->and($heading($resolver->blocks('after_list', 'en', $category->fresh())))->toBe('Ưu đãi riêng ở');
        });
    });

    it('adds no query once the storefront settings are read', function (): void {
        $category = lsCategory(1, 'Đồ gá cơ khí');
        $this->actingAs(lsSettingsAdmin());

        app(Tenancy::class)->enter(1, function () use ($category): void {
            app(SetLayoutBlocks::class)->handle(['post_aside' => lsCta('Đăng ký ngay để được hỗ trợ')]);
            app(SetCategoryLayoutBlocks::class)->handle($category, ['after_post' => lsCta('Ưu đãi riêng ở')]);
            $category = $category->fresh();

            // What a storefront page has read before it draws a slot: its settings and its languages.
            app(StorefrontSettings::class)->get('storefront', 'header');
            app(SiteLocale::class)->default();
            $resolver = app(LayoutSlotBlocks::class);

            $blocks = [];
            expect(countQueries(function () use ($resolver, $category, &$blocks): void {
                foreach (LayoutSlots::SLOTS as $slot) {
                    $blocks[$slot] = $resolver->blocks($slot, 'vi', $category);
                }
            }))->toBe(0)
                ->and($blocks['post_aside'])->toHaveCount(1)
                ->and($blocks['after_post'])->toHaveCount(1);
        });
    });
});

describe('media in use', function (): void {
    it('records the pictures and files of this site the slots name, and prunes a removed block', function (): void {
        $picture = lsPicture(1, 'anh-bia');
        $file = lsDocument(1, 'ho-so');
        $other = lsPicture(1, 'anh-khac');
        $theirs = lsPicture(2, 'cua-ho');
        $category = lsCategory(1, 'Đồ gá cơ khí');
        $this->actingAs(lsSettingsAdmin());

        app(Tenancy::class)->enter(1, function () use ($picture, $file, $other, $theirs, $category): void {
            app(SetLayoutBlocks::class)->handle([
                'post_aside' => lsHero($picture->id),
                'after_post' => ['vi' => ['blocks' => [['type' => 'cta', 'props' => ['button_label' => 'Ấn để tải hồ sơ', 'button_url_do' => ['k' => 'file', 'file' => $file->storage_key]]]]]],
                'after_list' => lsHero($theirs->id),
            ]);
            app(SetCategoryLayoutBlocks::class)->handle($category, ['post_aside' => lsHero($other->id), 'after_list' => lsHero($theirs->id)]);
        });

        $setting = lsSettingId(1);
        expect(lsUsage('site_setting', $setting))->toBe([$picture->id, $file->id])
            ->and(lsUsage('taxonomy', $category->id))->toBe([$other->id]);

        app(Tenancy::class)->enter(1, function () use ($category): void {
            app(SetLayoutBlocks::class)->handle(['post_aside' => null]);
            app(SetCategoryLayoutBlocks::class)->handle($category, ['post_aside' => 'none']);
        });
        expect(lsUsage('site_setting', $setting))->toBe([$file->id])
            ->and(lsUsage('taxonomy', $category->id))->toBe([]);
    });

    it('drops the rows of a deleted category or setting', function (): void {
        $picture = lsPicture(1, 'anh-bia');
        $deleted = lsCategory(1, 'Đồ gá cơ khí');
        $removed = lsCategory(1, 'Ứng dụng thực tế');
        $this->actingAs(lsSettingsAdmin());

        app(Tenancy::class)->enter(1, function () use ($picture, $deleted, $removed): void {
            app(SetLayoutBlocks::class)->handle(['post_aside' => lsHero($picture->id)]);
            app(SetCategoryLayoutBlocks::class)->handle($deleted, ['post_aside' => lsHero($picture->id)]);
            app(SetCategoryLayoutBlocks::class)->handle($removed, ['post_aside' => lsHero($picture->id)]);
        });
        $setting = lsSettingId(1);
        expect(lsUsage('taxonomy', $deleted->id))->toBe([$picture->id]);

        app(Tenancy::class)->enter(1, function () use ($deleted, $removed): void {
            app(DeleteTaxonomy::class)->handle($deleted);   // the screen's delete, one statement
            Taxonomy::query()->whereKey($removed->id)->first()?->delete();   // any road through the model
            SiteSetting::query()->where('group_name', 'storefront')->where('key', 'layout_blocks')->first()?->delete();
        });

        expect(lsUsage('taxonomy', $deleted->id))->toBe([])
            ->and(lsUsage('taxonomy', $removed->id))->toBe([])
            ->and(lsUsage('site_setting', $setting))->toBe([]);
    });

    it('lists the slots in the library\'s "where used", linking to the screen that edits them', function (): void {
        $picture = lsPicture(1, 'anh-bia');
        $category = lsCategory(1, 'Đồ gá cơ khí', 'product_cat');
        $this->actingAs(lsSettingsAdmin());

        $usages = app(Tenancy::class)->enter(1, function () use ($picture, $category): array {
            app(SetLayoutBlocks::class)->handle(['after_product' => lsHero($picture->id)]);
            app(SetCategoryLayoutBlocks::class)->handle($category, ['after_product' => lsHero($picture->id)]);

            return app(MediaUsageLocator::class)->usagesFor($picture, 'vi');
        });

        expect($usages)->toBe([
            ['kind' => 'site', 'role' => 'content', 'label' => 'Cột phải / Kêu gọi cuối trang', 'url' => route('admin.settings.appearance')],
            ['kind' => 'category', 'role' => 'content', 'label' => 'Đồ gá cơ khí · Cột phải / Kêu gọi cuối trang', 'url' => route('admin.taxonomies.index', ['section' => 'catalog'])],
        ]);
        expect(app(Tenancy::class)->enter(1, function () use ($picture): bool {
            $query = Media::query();
            app(MediaUsageLocator::class)->constrain($query, false);

            return $query->whereKey($picture->id)->exists();
        }))->toBeFalse();
    });

    it('links a post category to the section built on its root, at any depth, in a fixed number of queries', function (): void {
        $picture = lsPicture(1, 'anh-bia');
        $this->actingAs(lsSettingsAdmin());

        [$usages, $few, $many] = app(Tenancy::class)->enter(1, function () use ($picture): array {
            $create = fn (string $name, ?Taxonomy $parent): Taxonomy => app(CreateTaxonomy::class)->handle('post_cat', $parent, new TaxonomyData(names: ['vi' => $name]));
            $root = $create('Tin tức', null);
            $second = $create('Dự án', $create('Công trình', $root));
            ContentSection::query()->create(['key' => 'news', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $root->id, 'permission_prefix' => 'entries', 'sort_order' => 1]);
            $level3 = $second;
            app(SetCategoryLayoutBlocks::class)->handle($level3, ['post_aside' => lsHero($picture->id)]);
            $locator = app(MediaUsageLocator::class);
            $usages = $locator->usagesFor($picture, 'vi');
            $few = countQueries(fn () => $locator->usagesFor($picture, 'vi'));

            // More categories using the file at the same depth: the query count does not move.
            $parent = Taxonomy::query()->whereKey($level3->parent_id)->firstOrFail();
            foreach (['Mục cùng cấp một', 'Mục cùng cấp hai', 'Mục cùng cấp ba'] as $name) {
                app(SetCategoryLayoutBlocks::class)->handle($create($name, $parent), ['post_aside' => lsHero($picture->id)]);
            }
            $many = countQueries(fn () => $locator->usagesFor($picture, 'vi'));

            return [$usages, $few, $many];
        });

        expect($usages)->toBe([
            ['kind' => 'category', 'role' => 'content', 'label' => 'Dự án · Cột phải / Kêu gọi cuối trang', 'url' => route('admin.taxonomies.index', ['section' => 'news'])],
        ])->and($many)->toBe($few);
    });

    it('rebuilds the rows with the reconcile command and drops the stale ones', function (): void {
        $picture = lsPicture(1, 'anh-bia');
        $category = lsCategory(1, 'Đồ gá cơ khí');
        $plain = lsCategory(1, 'Ứng dụng thực tế');
        $this->actingAs(lsSettingsAdmin());
        app(Tenancy::class)->enter(1, function () use ($picture, $category): void {
            app(SetLayoutBlocks::class)->handle(['post_aside' => lsHero($picture->id)]);
            app(SetCategoryLayoutBlocks::class)->handle($category, ['post_aside' => lsHero($picture->id)]);
        });
        $setting = lsSettingId(1);
        $admin = DB::connection('pgsql_admin');
        $admin->table('mediables')->whereIn('mediable_type', ['site_setting', 'taxonomy'])->delete();
        // A stray row of a category with no slot blocks, as a road that skipped the save would leave.
        $admin->table('mediables')->insert(['media_id' => $picture->id, 'mediable_type' => 'taxonomy', 'mediable_id' => $plain->id, 'site_id' => 1, 'collection' => 'content', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('media:reconcile-content-usage')->assertSuccessful();

        expect(lsUsage('site_setting', $setting))->toBe([$picture->id])
            ->and(lsUsage('taxonomy', $category->id))->toBe([$picture->id])
            ->and(lsUsage('taxonomy', $plain->id))->toBe([]);
    });
});

it('keeps two sites\' slots and rows apart in one app', function (): void {
    $mine = lsPicture(1, 'anh-a');
    $theirs = lsPicture(2, 'anh-b');
    $adminA = lsSettingsAdmin(1);
    $adminB = lsSettingsAdmin(2);
    $categoryA = lsCategory(1, 'Đồ gá cơ khí');
    $categoryB = lsCategory(2, 'Ứng dụng thực tế');

    $this->actingAs($adminA);
    app(Tenancy::class)->enter(1, fn () => app(SetLayoutBlocks::class)->handle(['post_aside' => lsHero($mine->id)]));
    $this->actingAs($adminB);
    app(Tenancy::class)->enter(2, function () use ($theirs, $categoryB): void {
        app(SetLayoutBlocks::class)->handle(['post_aside' => lsHero($theirs->id), 'after_post' => lsCta('Ưu đãi của B ở')]);
        app(SetCategoryLayoutBlocks::class)->handle($categoryB, ['post_aside' => 'none']);
    });
    $this->actingAs($adminA);
    app(Tenancy::class)->enter(1, fn () => app(SetCategoryLayoutBlocks::class)->handle($categoryA, ['after_post' => lsHero($mine->id)]));

    app(Tenancy::class)->enter(1, function () use ($mine, $categoryA): void {
        $resolver = app(LayoutSlotBlocks::class);
        expect($resolver->blocks('post_aside', 'vi')[0]['props']['image_url'])->toBe($mine->id)
            ->and($resolver->blocks('after_post', 'vi'))->toHaveCount(0)
            ->and($resolver->blocks('after_post', 'vi', $categoryA->fresh())[0]['props']['image_url'])->toBe($mine->id)
            ->and(Mediable::query()->whereIn('mediable_type', ['site_setting', 'taxonomy'])->pluck('media_id')->unique()->values()->all())->toBe([$mine->id]);
    });
    app(Tenancy::class)->enter(2, function () use ($theirs, $categoryB): void {
        $resolver = app(LayoutSlotBlocks::class);
        expect($resolver->blocks('post_aside', 'vi')[0]['props']['image_url'])->toBe($theirs->id)
            ->and($resolver->blocks('post_aside', 'vi', $categoryB->fresh()))->toBe([])
            ->and(Mediable::query()->whereIn('mediable_type', ['site_setting', 'taxonomy'])->pluck('media_id')->all())->toBe([$theirs->id]);
    });

    expect(DB::connection('pgsql_admin')->table('mediables')->where('site_id', 1)->whereIn('mediable_type', ['site_setting', 'taxonomy'])->pluck('media_id')->unique()->values()->map(fn ($id): int => (int) $id)->all())->toBe([$mine->id])
        ->and(DB::connection('pgsql_admin')->table('mediables')->where('site_id', 2)->whereIn('mediable_type', ['site_setting', 'taxonomy'])->pluck('media_id')->map(fn ($id): int => (int) $id)->all())->toBe([$theirs->id]);
});

describe('category meta', function (): void {
    it('keeps the slot blocks when the category is saved, and the other meta keys when the slots are', function (): void {
        $category = lsCategory(1, 'Đồ gá cơ khí');

        app(Tenancy::class)->enter(1, function () use ($category): void {
            Taxonomy::query()->whereKey($category->id)->update(['meta->ghi_chu' => 'Ưu tiên hiển thị ở']);
            app(SetCategoryLayoutBlocks::class)->handle($category, ['post_aside' => lsCta('Đăng ký ngay để được hỗ trợ')]);

            $meta = Taxonomy::query()->whereKey($category->id)->value('meta');
            expect($meta['ghi_chu'])->toBe('Ưu tiên hiển thị ở')
                ->and($meta['layout_blocks']['post_aside']['vi']['blocks'][0]['props']['heading'])->toBe('Đăng ký ngay để được hỗ trợ');

            app(UpdateTaxonomy::class)->handle($category->fresh(), new TaxonomyData(names: ['vi' => 'Ứng dụng thực tế'], descriptions: ['vi' => 'Ổn định lâu dài ở']));

            $meta = Taxonomy::query()->whereKey($category->id)->value('meta');
            expect($meta['ghi_chu'])->toBe('Ưu tiên hiển thị ở')
                ->and($meta['layout_blocks']['post_aside']['vi']['blocks'][0]['props']['heading'])->toBe('Đăng ký ngay để được hỗ trợ');

            app(SetCategoryLayoutBlocks::class)->handle($category, ['post_aside' => null]);
            expect(Taxonomy::query()->whereKey($category->id)->value('meta'))->toBe(['ghi_chu' => 'Ưu tiên hiển thị ở']);
        });
    });
});
