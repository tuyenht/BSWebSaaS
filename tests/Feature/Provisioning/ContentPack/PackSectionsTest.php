<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Admin\Navigation\AdminNav;
use App\Modules\Admin\Navigation\NavItem;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\ContentPack\Ledger;
use App\Modules\Provisioning\ContentPack\PackReader;
use App\Modules\Provisioning\ContentPack\PackValidator;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Models\Workflow;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * Content sections in a pack (sections.json): the loader creates a section bound to one of the pack's
 * post categories, or adopts the one the site already has under that key, and never deletes or rebinds
 * one. A section created by the loader is the same row provisioning makes (entries permissions, a post
 * section on its category), so the admin menu, the section's post list and every role behave as they
 * do for a provisioned section; a member limited to other categories gains nothing. Binding a category
 * never moves a post's address: a binding that would is a conflict.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $this->folder = ContentPacks::use();
    $this->siteId = ContentPacks::site();
});

afterEach(fn () => ContentPacks::cleanup());

/** The services section the tests load: bound to the pack's Dịch vụ category, which no section covers yet. */
function psServices(array $overrides = []): array
{
    return $overrides + [
        'key' => 'section:services',
        'label' => 'Dịch vụ',
        'entry_type' => 'post',
        'category' => 'tax:post_cat/dich-vu',
        'icon' => 'ri-briefcase',
        'sort_order' => 2,
    ];
}

/** @param list<array<string, mixed>> $items */
function psSections(string $folder, array $items): void
{
    file_put_contents($folder.'/sections.json', json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}

/** The plan line printed for one key (verdict and notes). */
function psLine(string $output, string $key): string
{
    foreach (explode("\n", $output) as $line) {
        if (preg_match('/\s'.preg_quote($key, '/').'(\s|$)/', $line) === 1) {
            return $line;
        }
    }

    return '';
}

/** @return array<string, mixed>|null */
function psSection(int $siteId, string $key): ?array
{
    return ContentPacks::in($siteId, fn (): ?array => ContentSection::query()->where('key', $key)->first()?->only(['key', 'label', 'entry_type', 'taxonomy_id', 'icon', 'sort_order', 'permission_prefix', 'list_template', 'detail_template']));
}

function psCategoryId(int $siteId, string $slug): int
{
    return (int) ContentPacks::in($siteId, fn () => TaxonomyTranslation::query()->where('locale', 'vi')->where('slug', $slug)->value('taxonomy_id'));
}

/** @return array<string, string> every route of the site, path → what it serves */
function psRoutes(int $siteId): array
{
    return ContentPacks::in($siteId, fn (): array => SiteRoute::query()->orderBy('locale')->orderBy('path')->get(['locale', 'path', 'entry_id', 'taxonomy_id', 'product_id'])
        ->mapWithKeys(fn (SiteRoute $route): array => [$route->locale.':'.$route->path => $route->entry_id.'|'.$route->taxonomy_id.'|'.$route->product_id])->all());
}

/** An active approval workflow on the section key (no stages needed: the plan only asks whether one governs the key). */
function psWorkflow(int $siteId, string $appliesTo): Workflow
{
    return ContentPacks::in($siteId, fn (): Workflow => Workflow::query()->create([
        'key' => 'review-'.$appliesTo, 'name' => 'Duyệt bài', 'applies_to_type' => $appliesTo, 'template' => 'admin_approval', 'is_active' => true,
    ]));
}

function psPost(int $siteId, string $slug): Entry
{
    return ContentPacks::in($siteId, fn (): Entry => Entry::query()->findOrFail(EntryTranslation::query()->where('locale', 'vi')->where('slug', $slug)->value('entry_id')));
}

describe('the plan and the write', function (): void {
    it('creates the section the way provisioning does, bound to the category, with no post address moving', function (): void {
        ContentPacks::load();
        $routes = psRoutes($this->siteId);
        psSections($this->folder, [psServices()]);

        [$exit, $output] = ContentPacks::run();
        expect($exit)->toBe(0, $output)
            ->and(ContentPacks::verdict($output, 'section:services'))->toBe('create')
            ->and(psLine($output, 'section:services'))->toContain('0 post addresses move')
            ->and(psSection($this->siteId, 'services'))->toBeNull();

        [$exit, $output] = ContentPacks::load();
        expect($exit)->toBe(0, $output)
            ->and(psSection($this->siteId, 'services'))->toBe([
                'key' => 'services', 'label' => 'Dịch vụ', 'entry_type' => 'post',
                'taxonomy_id' => psCategoryId($this->siteId, 'dich-vu'), 'icon' => 'ri-briefcase', 'sort_order' => 2,
                'permission_prefix' => 'entries', 'list_template' => null, 'detail_template' => null,
            ])
            ->and(psRoutes($this->siteId))->toBe($routes);

        $ledger = ContentPacks::in($this->siteId, fn () => Ledger::read('demo-co'));
        expect($ledger['items']['section:services']['ref'] ?? null)->toBe('services');
    });

    it('creates the section on the first load too, with the category the same load creates', function (): void {
        psSections($this->folder, [psServices()]);

        [$exit, $output] = ContentPacks::load();

        expect($exit)->toBe(0, $output)
            ->and(psSection($this->siteId, 'services')['taxonomy_id'] ?? null)->toBe(psCategoryId($this->siteId, 'dich-vu'))
            ->and(ContentPacks::in($this->siteId, fn () => SiteRoute::query()->where('path', '/thiet-ke-mau')->exists()))->toBeTrue();
    });

    it('is unchanged on a rerun and writes nothing', function (): void {
        psSections($this->folder, [psServices()]);
        ContentPacks::load();

        [$exit, $output] = ContentPacks::run();
        expect($exit)->toBe(0, $output)->and(ContentPacks::verdict($output, 'section:services'))->toBe('unchanged');

        $writes = ContentPacks::writes(function () use (&$exit, &$output): void {
            [$exit, $output] = ContentPacks::load();
        });
        expect($exit)->toBe(0, $output)
            ->and($output)->toContain('0 items written')
            ->and(array_filter($writes, fn (string $sql): bool => str_contains($sql, 'content_sections')))->toBe([])
            ->and(ContentPacks::in($this->siteId, fn () => ContentSection::query()->where('key', 'services')->count()))->toBe(1);
    });

    it('keeps the label, icon and order of a section the loader has not written before, unless forced', function (): void {
        // No ledger line: nothing tells a staff choice apart from an old default, so, as for products,
        // the site's own presentation wins; the binding (same key, same category, its permissions) is the site's already.
        ContentPacks::load();
        $category = psCategoryId($this->siteId, 'dich-vu');
        ContentPacks::in($this->siteId, fn () => ContentSection::query()->create([
            'key' => 'services', 'label' => 'DV cũ', 'entry_type' => 'post', 'taxonomy_id' => $category, 'permission_prefix' => null, 'icon' => 'ri-tools', 'sort_order' => 7,
        ]));
        $before = psSection($this->siteId, 'services');
        psSections($this->folder, [psServices()]);

        [$exit, $output] = ContentPacks::run();
        expect($exit)->toBe(0, $output)
            ->and(ContentPacks::verdict($output, 'section:services'))->toBe('skip:edited')
            ->and(psLine($output, 'section:services'))->toContain('the loader has not written before');

        [$exit, $output] = ContentPacks::load();
        expect($exit)->toBe(0, $output)->and(psSection($this->siteId, 'services'))->toBe($before);

        [$exit, $output] = ContentPacks::load(force: ['section:services']);
        expect($exit)->toBe(0, $output)
            ->and(psSection($this->siteId, 'services'))->toMatchArray([
                'label' => 'Dịch vụ', 'icon' => 'ri-briefcase', 'sort_order' => 2, 'taxonomy_id' => $category, 'permission_prefix' => null,
            ])
            ->and(ContentPacks::in($this->siteId, fn () => ContentSection::query()->where('key', 'services')->count()))->toBe(1);
    });

    it('takes over a section the site already has as it is, when its presentation matches the pack', function (): void {
        ContentPacks::load();
        $category = psCategoryId($this->siteId, 'dich-vu');
        ContentPacks::in($this->siteId, fn () => ContentSection::query()->create([
            'key' => 'services', 'label' => 'Dịch vụ', 'entry_type' => 'post', 'taxonomy_id' => $category, 'permission_prefix' => 'entries', 'icon' => 'ri-briefcase', 'sort_order' => 2,
        ]));
        psSections($this->folder, [psServices()]);

        [$exit, $output] = ContentPacks::run();

        expect($exit)->toBe(0, $output)->and(ContentPacks::verdict($output, 'section:services'))->toBe('unchanged');
    });

    it('never rebinds a section the site has on another category: a conflict, nothing written', function (): void {
        ContentPacks::load();
        $other = psCategoryId($this->siteId, 'san-pham');
        ContentPacks::in($this->siteId, fn () => ContentSection::query()->create([
            'key' => 'services', 'label' => 'DV', 'entry_type' => 'post', 'taxonomy_id' => $other, 'permission_prefix' => 'entries', 'sort_order' => 2,
        ]));
        psSections($this->folder, [psServices()]);

        [$exit, $output] = ContentPacks::run();

        expect($exit)->toBe(1, $output)
            ->and(ContentPacks::verdict($output, 'section:services'))->toBe('conflict')
            ->and(psLine($output, 'section:services'))->toContain('never moves a section to another category')
            ->and(psSection($this->siteId, 'services')['taxonomy_id'] ?? null)->toBe($other);
    });

    it('refuses a second section on a category another section is built on', function (): void {
        ContentPacks::load();
        psSections($this->folder, [psServices(['key' => 'section:news2', 'category' => 'tax:post_cat/tin-tuc'])]);

        [$exit, $output] = ContentPacks::run();

        expect($exit)->toBe(1, $output)
            ->and(ContentPacks::verdict($output, 'section:news2'))->toBe('conflict')
            ->and(psLine($output, 'section:news2'))->toContain('already the section «posts»');
    });

    it('refuses a section on a branch of a category another section is built on, even when no address moves', function (): void {
        // A flat site: a section of its own on Cập nhật (under Tin tức, the «posts» section) would move
        // no address, but it would take the branch's posts out of «posts» — out of its workflow, its
        // reviewers' reach and its admin list.
        ContentPacks::in($this->siteId, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'posts'], ['value' => ['pattern' => 'flat']]));
        ContentPacks::load();
        $workflow = psWorkflow($this->siteId, 'posts');
        $post = psPost($this->siteId, 'ra-mat-website');
        $governing = function () use ($post): ?int {
            app(SectionResolver::class)->flush();

            return ContentPacks::in($this->siteId, fn (): ?int => app(EntryWorkflowResolver::class)->forEntry($post)?->id);
        };
        expect($governing())->toBe($workflow->id);
        psSections($this->folder, [psServices(['key' => 'section:updates', 'category' => 'tax:post_cat/tin-tuc/cap-nhat'])]);

        [$exit, $output] = ContentPacks::run();
        expect($exit)->toBe(1, $output)
            ->and(ContentPacks::verdict($output, 'section:updates'))->toBe('conflict')
            ->and(psLine($output, 'section:updates'))->toContain('tax:post_cat/tin-tuc/cap-nhat belongs to the section «posts»')
            ->and(psLine($output, 'section:updates'))->not->toContain('0 post addresses move');

        expect(fn () => ContentPacks::load())->toThrow(RuntimeException::class);
        expect(psSection($this->siteId, 'updates'))->toBeNull()
            ->and($governing())->toBe($workflow->id);
    });

    it('refuses the same branch on a site whose news addresses follow their category', function (): void {
        ContentPacks::load();
        psSections($this->folder, [psServices(['key' => 'section:updates', 'category' => 'tax:post_cat/tin-tuc/cap-nhat'])]);

        [$exit, $output] = ContentPacks::run();

        expect($exit)->toBe(1, $output)
            ->and(psLine($output, 'section:updates'))->toContain('belongs to the section «posts»');
    });

    it('refuses a new section whose key an active workflow already waits on', function (): void {
        // The workflow would govern every post of the branch the moment the section exists, with
        // no one having chosen that for these posts: the loader refuses rather than notes it.
        ContentPacks::load();
        psWorkflow($this->siteId, 'services');
        psSections($this->folder, [psServices()]);

        [$exit, $output] = ContentPacks::run();

        expect($exit)->toBe(1, $output)
            ->and(ContentPacks::verdict($output, 'section:services'))->toBe('conflict')
            ->and(psLine($output, 'section:services'))->toContain('the workflow «Duyệt bài» already waits on the section key «services»');
        expect(fn () => ContentPacks::load())->toThrow(RuntimeException::class);
        expect(psSection($this->siteId, 'services'))->toBeNull();
    });

    it('creates the section when the workflow on its key is switched off', function (): void {
        ContentPacks::load();
        $workflow = psWorkflow($this->siteId, 'services');
        ContentPacks::in($this->siteId, fn () => $workflow->update(['is_active' => false]));
        psSections($this->folder, [psServices()]);

        [$exit, $output] = ContentPacks::run();

        expect($exit)->toBe(0, $output)->and(ContentPacks::verdict($output, 'section:services'))->toBe('create');
    });

    it('takes the permalink the site already set for the key into account', function (): void {
        ContentPacks::load();
        ContentPacks::in($this->siteId, fn () => SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'services', 'value' => ['pattern' => 'category']]));
        psSections($this->folder, [psServices()]);

        [$exit, $output] = ContentPacks::run();

        expect($exit)->toBe(1, $output)
            ->and(ContentPacks::verdict($output, 'section:services'))->toBe('conflict')
            ->and(psLine($output, 'section:services'))->toContain('1 post address under tax:post_cat/dich-vu would move (/thiet-ke-mau → /dich-vu/thiet-ke-mau)');
    });

    it('keeps a staff edit since the last load unless forced, and never deletes a section the pack no longer lists', function (): void {
        psSections($this->folder, [psServices()]);
        ContentPacks::load();
        ContentPacks::in($this->siteId, fn () => ContentSection::query()->where('key', 'services')->update(['label' => 'Dịch vụ kỹ thuật']));

        [$exit, $output] = ContentPacks::run();
        expect($exit)->toBe(0, $output)->and(ContentPacks::verdict($output, 'section:services'))->toBe('skip:edited');

        [$exit, $output] = ContentPacks::load(force: ['section:services']);
        expect($exit)->toBe(0, $output)->and(psSection($this->siteId, 'services')['label'] ?? null)->toBe('Dịch vụ');

        unlink($this->folder.'/sections.json');
        [$exit, $output] = ContentPacks::load();
        expect($exit)->toBe(0, $output)
            ->and($output)->not->toContain('section:services')
            ->and(psSection($this->siteId, 'services'))->not->toBeNull();
        [, $output] = ContentPacks::run();
        expect($output)->toContain('orphan')->toContain('section:services');
    });
});

describe('the pack file', function (): void {
    it('refuses a malformed section item', function (array $item, string $error): void {
        psSections($this->folder, [$item]);
        $pack = app(PackReader::class)->read('demo-co');

        expect(implode("\n", app(PackValidator::class)->validate($pack, 'demo-co')))->toContain($error);
    })->with([
        'key shape' => [psServices(['key' => 'section:Dich Vu']), 'a section key is section:<key>'],
        'reserved key' => [psServices(['key' => 'section:pages']), 'is the platform\'s own'],
        'label' => [psServices(['label' => ' ']), 'label must be one line of text'],
        'entry type' => [psServices(['entry_type' => 'page']), 'entry_type must be post'],
        'category not in the pack' => [psServices(['category' => 'tax:post_cat/khong-co']), 'is not in taxonomies.json'],
        'category of another kind' => [psServices(['category' => 'tax:product_cat/x']), 'must be a post category'],
        'icon' => [psServices(['icon' => 'fa fa-x']), 'icon must be a glyph name'],
        'sort order' => [psServices(['sort_order' => -1]), 'sort_order must be a whole number from 0'],
    ]);

    it('refuses two sections on one category', function (): void {
        psSections($this->folder, [psServices(), psServices(['key' => 'section:services2'])]);
        $pack = app(PackReader::class)->read('demo-co');

        expect(implode("\n", app(PackValidator::class)->validate($pack, 'demo-co')))->toContain('section:services2: another section of the pack is built on tax:post_cat/dich-vu');
    });
});

describe('in the admin', function (): void {
    beforeEach(function (): void {
        psSections($this->folder, [psServices()]);
    });

    it('shows the section in the menu after the news section, as a group with its list', function (): void {
        ContentPacks::load();
        $owner = User::query()->where('email', 'owner@'.ContentPacks::HOST)->firstOrFail();

        $items = ContentPacks::in($this->siteId, fn (): array => (new AdminNav)->items($owner));
        $keys = array_map(fn (NavItem $item): string => $item->key, $items);
        $services = collect($items)->firstWhere('key', 'services');

        expect($services)->not->toBeNull()
            ->and($services->label)->toBe(__('section.services'))
            ->and($services->icon)->toBe('ri-briefcase')
            ->and(array_search('services', $keys, true))->toBeGreaterThan(array_search('posts', $keys, true))
            ->and(collect($services->children)->firstWhere('key', 'services.list')?->url)->toBe(route('admin.entries.index', ['section' => 'services']));
    });

    it('lists the posts filed under the category in the section, and no longer leaves them out of every list', function (): void {
        ContentPacks::load();
        $owner = User::query()->where('email', 'owner@'.ContentPacks::HOST)->firstOrFail();
        $titles = fn (string $section): array => ContentPacks::in($this->siteId, fn (): array => collect(
            Livewire::actingAs($owner)->test(Index::class, ['section' => $section])->viewData('rows')->items()
        )->map(fn (array $row): string => (string) $row['entry']->translations->firstWhere('locale', 'vi')?->title)->all());

        expect($titles('services'))->toBe(['Thiết kế mẫu'])
            ->and($titles('posts'))->not->toContain('Thiết kế mẫu');
    });

    it('gives a member limited to other categories nothing over the section\'s posts', function (): void {
        unlink($this->folder.'/sections.json');
        ContentPacks::load(); // without the section yet: the "before"

        $member = User::factory()->create();
        $news = psCategoryId($this->siteId, 'tin-tuc');
        ContentPacks::in($this->siteId, function () use ($member, $news): void {
            grantRole($member, 'news-editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.delete.any', 'entries.publish']);
            MemberCategoryScope::query()->create(['user_id' => $member->id, 'taxonomy_id' => $news]);
        });
        $post = psPost($this->siteId, 'thiet-ke-mau');
        $rights = function () use ($member, $post): array {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            app(TeamContext::class)->forget();
            app(CategoryScopes::class)->forget();

            return ContentPacks::in($this->siteId, function () use ($member, $post): array {
                $fresh = $member->fresh();

                return [
                    'update' => $fresh->can('update', $post),
                    'delete' => $fresh->can('delete', $post),
                    'covered' => app(CategoryScopes::class)->coversEntry($fresh, $post),
                    'allowed' => app(CategoryScopes::class)->allowedTaxonomyIds($fresh),
                ];
            });
        };

        $before = $rights();
        psSections($this->folder, [psServices()]);
        ContentPacks::load();
        $after = $rights();

        expect($before['update'])->toBeFalse()
            ->and($before['covered'])->toBeFalse()
            ->and($after)->toBe($before)
            ->and(psSection($this->siteId, 'services'))->not->toBeNull();
    });

    it('shows the section only to a member who may see posts, like every provisioned section', function (): void {
        ContentPacks::load();
        $viewer = User::factory()->create();
        $stranger = User::factory()->create();
        ContentPacks::in($this->siteId, function () use ($viewer, $stranger): void {
            grantRole($viewer, 'viewer-test', ['entries.view']);
            grantRole($stranger, 'media-only', ['media.view']);
        });
        app(TeamContext::class)->forget();
        $keys = fn (User $user): array => ContentPacks::in($this->siteId, fn (): array => array_map(fn (NavItem $item): string => $item->key, (new AdminNav)->items($user->fresh())));

        expect($keys($viewer))->toContain('services')->toContain('posts')
            ->and($keys($stranger))->not->toContain('services')->not->toContain('posts');
    });
});
