<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\ContentPack\Ledger;
use App\Modules\Provisioning\ContentPack\PackReader;
use App\Modules\Provisioning\ContentPack\PackRejected;
use App\Modules\Provisioning\ContentPack\PackValidator;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * A pack removes a content section (sections.json `remove`) only when it is safe: the loader created
 * the section and nobody changed it, no active workflow uses its key, its posts then belong to the
 * section above their category (the pack moves the category there in the same load, `moved_from`)
 * and not one post address moves. The row and the members' saved views of its list are backed up
 * first, the saved views go with the row, and the ledger forgets the section.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $this->folder = ContentPacks::use();
    $this->siteId = ContentPacks::site();
});

afterEach(fn () => ContentPacks::cleanup());

/** @param array{items?: list<array<string, mixed>>, remove?: list<string>} $sections */
function srSections(string $folder, array $sections): void
{
    file_put_contents($folder.'/sections.json', json_encode($sections + ['items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}

/** The services section the loader creates on the Dịch vụ category. */
function srServices(array $overrides = []): array
{
    return $overrides + ['key' => 'section:services', 'label' => 'Dịch vụ', 'entry_type' => 'post', 'category' => 'tax:post_cat/dich-vu', 'icon' => 'ri-briefcase', 'sort_order' => 2];
}

/** The pack moves Dịch vụ under Tin tức (the «posts» section): its key, its parent and every reference to it. */
function srMoveServices(string $folder): void
{
    foreach (['posts.json', 'menus.json'] as $file) {
        file_put_contents($folder.'/'.$file, str_replace('"tax:post_cat/dich-vu"', '"tax:post_cat/tin-tuc/dich-vu"', (string) file_get_contents($folder.'/'.$file)));
    }
    ContentPacks::item($folder, 'taxonomies.json', 'tax:post_cat/dich-vu', fn (array $item): array => [
        'key' => 'tax:post_cat/tin-tuc/dich-vu', 'parent' => 'tax:post_cat/tin-tuc', 'moved_from' => 'tax:post_cat/dich-vu', 'position' => 2,
    ] + $item);
}

/** The plan line printed for one key (verdict and notes). */
function srLine(string $output, string $key): string
{
    foreach (explode("\n", $output) as $line) {
        if (preg_match('/\s'.preg_quote($key, '/').'(\s|$)/', $line) === 1) {
            return $line;
        }
    }

    return '';
}

/** @return array<string, string> every route of the site, path → what it serves */
function srRoutes(int $siteId): array
{
    return ContentPacks::in($siteId, fn (): array => SiteRoute::query()->orderBy('locale')->orderBy('path')->get(['locale', 'path', 'entry_id', 'taxonomy_id', 'product_id'])
        ->mapWithKeys(fn (SiteRoute $route): array => [$route->locale.':'.$route->path => $route->entry_id.'|'.$route->taxonomy_id.'|'.$route->product_id])->all());
}

/** The section a post belongs to, by its slug. */
function srSectionOf(int $siteId, string $slug): ?string
{
    return ContentPacks::in($siteId, function () use ($slug): ?string {
        app(SectionResolver::class)->flush();
        $post = Entry::query()->findOrFail(EntryTranslation::query()->where('locale', 'vi')->where('slug', $slug)->value('entry_id'));

        return app(SectionResolver::class)->forEntry($post)['key'] ?? null;
    });
}

function srFlat(int $siteId): void
{
    ContentPacks::in($siteId, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'posts'], ['value' => ['pattern' => 'flat']]));
}

function srOwner(): User
{
    return User::query()->where('email', 'owner@'.ContentPacks::HOST)->firstOrFail();
}

/** A services section the site made itself, as the pack would write it. */
function srSiteServices(int $siteId): void
{
    $category = (int) ContentPacks::in($siteId, fn () => TaxonomyTranslation::query()->where('locale', 'vi')->where('slug', 'dich-vu')->value('taxonomy_id'));
    ContentPacks::in($siteId, fn () => ContentSection::query()->create([
        'key' => 'services', 'label' => 'Dịch vụ', 'entry_type' => 'post', 'taxonomy_id' => $category, 'permission_prefix' => 'entries', 'icon' => 'ri-briefcase', 'sort_order' => 2,
    ]));
}

/** The site's services section, loaded by the pack (and so recorded as the loader's own). */
function srLoadServices(int $siteId, string $folder): void
{
    srSections($folder, ['items' => [srServices()]]);
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)->and(ContentPacks::in($siteId, fn () => ContentSection::query()->where('key', 'services')->exists()))->toBeTrue();
}

describe('removing a section the loader created', function (): void {
    it('removes it with its saved list views, its posts joining the section above, no address moving', function (): void {
        srFlat($this->siteId);
        srLoadServices($this->siteId, $this->folder);
        $owner = srOwner();
        // The owner keeps a view of the section's list; another list's view stays.
        DB::table('users')->where('id', $owner->id)->update(['preferences' => json_encode(['lists' => [
            'entries.post.services' => ['cols' => ['title']], 'entries.post.posts' => ['cols' => ['title', 'date']],
        ]])]);
        $routes = srRoutes($this->siteId);
        expect(srSectionOf($this->siteId, 'thiet-ke-mau'))->toBe('services');

        srMoveServices($this->folder);
        srSections($this->folder, ['remove' => ['section:services']]);
        [$exit, $output] = ContentPacks::run();
        expect($exit)->toBe(0, $output)
            ->and(ContentPacks::verdict($output, 'section:services'))->toBe('remove')
            ->and(srLine($output, 'section:services'))->toContain('its posts join «posts»')
            ->and(srLine($output, 'section:services'))->toContain('0 post addresses move')
            ->and(srLine($output, 'section:services'))->toContain('1 saved list view of it pruned')
            ->and(ContentPacks::verdict($output, 'tax:post_cat/tin-tuc/dich-vu'))->toBe('update')
            ->and($output)->toContain('remove 1');

        [$exit, $output] = ContentPacks::load();
        expect($exit)->toBe(0, $output)
            ->and(ContentPacks::in($this->siteId, fn () => ContentSection::query()->where('key', 'services')->exists()))->toBeFalse()
            ->and(srRoutes($this->siteId))->toBe($routes)
            ->and(srSectionOf($this->siteId, 'thiet-ke-mau'))->toBe('posts');
        $dichVu = ContentPacks::in($this->siteId, fn () => Taxonomy::query()->findOrFail(TaxonomyTranslation::query()->where('locale', 'vi')->where('slug', 'dich-vu')->value('taxonomy_id')));
        $tinTuc = ContentPacks::in($this->siteId, fn () => TaxonomyTranslation::query()->where('locale', 'vi')->where('slug', 'tin-tuc')->value('taxonomy_id'));
        expect($dichVu->parent_id)->toBe($tinTuc);

        // The saved view went with the section; the other list's stayed.
        $lists = json_decode((string) DB::table('users')->where('id', $owner->id)->value('preferences'), true)['lists'];
        expect($lists)->toBe(['entries.post.posts' => ['cols' => ['title', 'date']]]);

        // The backup holds the row and the saved view.
        expect(preg_match('/^Backup: (\S+)$/m', $output, $m))->toBe(1, $output);
        $backup = json_decode((string) gzdecode((string) file_get_contents($m[1])), true, 512, JSON_THROW_ON_ERROR);
        $item = collect($backup['items'])->firstWhere('key', 'section:services');
        expect($item['rows']['section']['key'] ?? null)->toBe('services')
            ->and($item['rows']['section']['label'] ?? null)->toBe('Dịch vụ')
            ->and($item['rows']['saved_list_views'] ?? null)->toBe([(string) $owner->id => ['cols' => ['title']]]);

        // The ledger forgets the section and keeps the moved category under its new key.
        $ledger = ContentPacks::in($this->siteId, fn () => Ledger::read('demo-co'));
        expect($ledger['items'])->not->toHaveKey('section:services')
            ->and($ledger['items'])->toHaveKey('tax:post_cat/tin-tuc/dich-vu')
            ->and($ledger['items'])->not->toHaveKey('tax:post_cat/dich-vu');

        // The news list now holds the moved category and its post (the admin list follows the nearest section).
        $test = ContentPacks::in($this->siteId, fn () => Livewire::actingAs($owner)->test(Index::class, ['section' => 'posts']));
        $titles = collect($test->viewData('rows')->items())->map(fn (array $row): string => (string) $row['entry']->translations->firstWhere('locale', 'vi')?->title)->all();
        expect($titles)->toContain('Thiết kế mẫu')
            ->and(collect($test->viewData('catOptions'))->pluck('label')->all())->toContain('Dịch vụ');

        // A second run has nothing to do.
        [$exit, $output] = ContentPacks::run();
        expect($exit)->toBe(0, $output)
            ->and(ContentPacks::verdict($output, 'section:services'))->toBe('unchanged')
            ->and(srLine($output, 'section:services'))->toContain('nothing to remove')
            ->and(ContentPacks::verdict($output, 'tax:post_cat/tin-tuc/dich-vu'))->toBe('unchanged');
    });

    it('refuses when a post address would move without the section, and writes nothing', function (): void {
        // The news addresses follow their category (the fixture's preset): the moved posts would leave /thiet-ke-mau.
        srLoadServices($this->siteId, $this->folder);
        $routes = srRoutes($this->siteId);
        srMoveServices($this->folder);
        srSections($this->folder, ['remove' => ['section:services']]);

        [$exit, $output] = ContentPacks::run();
        expect($exit)->toBe(1, $output)
            ->and(ContentPacks::verdict($output, 'section:services'))->toBe('conflict')
            ->and(srLine($output, 'section:services'))->toContain('would move without the section «services»')
            ->and(srLine($output, 'section:services'))->toContain('/thiet-ke-mau → /dich-vu/thiet-ke-mau');

        expect(fn () => ContentPacks::load())->toThrow(RuntimeException::class);
        expect(ContentPacks::in($this->siteId, fn () => ContentSection::query()->where('key', 'services')->exists()))->toBeTrue()
            ->and(srRoutes($this->siteId))->toBe($routes);
    });

    it('refuses while a workflow is active on its key', function (): void {
        srFlat($this->siteId);
        srLoadServices($this->siteId, $this->folder);
        ContentPacks::in($this->siteId, fn () => Workflow::query()->create([
            'key' => 'review-services', 'name' => 'Duyệt dịch vụ', 'applies_to_type' => 'services', 'template' => 'admin_approval', 'is_active' => true,
        ]));
        srMoveServices($this->folder);
        srSections($this->folder, ['remove' => ['section:services']]);

        [$exit, $output] = ContentPacks::run();

        expect($exit)->toBe(1, $output)
            ->and(ContentPacks::verdict($output, 'section:services'))->toBe('conflict')
            ->and(srLine($output, 'section:services'))->toContain('the workflow «Duyệt dịch vụ» is active on the section «services»');
    });

    it('names the workflow its posts join when the section above has one', function (): void {
        srFlat($this->siteId);
        srLoadServices($this->siteId, $this->folder);
        ContentPacks::in($this->siteId, fn () => Workflow::query()->create([
            'key' => 'review-posts', 'name' => 'Duyệt tin', 'applies_to_type' => 'posts', 'template' => 'admin_approval', 'is_active' => true,
        ]));
        srMoveServices($this->folder);
        srSections($this->folder, ['remove' => ['section:services']]);

        [$exit, $output] = ContentPacks::run();

        expect($exit)->toBe(0, $output)
            ->and(srLine($output, 'section:services'))->toContain('its posts join «posts» and its workflow «Duyệt tin»');
    });

    it('refuses a section staff edited since the load: its label, or a layout of its own', function (array $change, string $why): void {
        srFlat($this->siteId);
        srLoadServices($this->siteId, $this->folder);
        ContentPacks::in($this->siteId, fn () => ContentSection::query()->where('key', 'services')->update($change));
        srMoveServices($this->folder);
        srSections($this->folder, ['remove' => ['section:services']]);

        [$exit, $output] = ContentPacks::run();

        expect($exit)->toBe(1, $output)
            ->and(ContentPacks::verdict($output, 'section:services'))->toBe('conflict')
            ->and(srLine($output, 'section:services'))->toContain($why);
    })->with([
        'label' => [['label' => 'Dịch vụ kỹ thuật'], 'was edited by staff since the last load'],
        'list layout' => [['list_template' => 'grid-news'], 'layouts or settings of its own'],
        'permissions' => [['permission_prefix' => 'services'], 'kind or permissions the loader gave it'],
    ]);

    it('refuses a section the site made itself', function (): void {
        srFlat($this->siteId);
        ContentPacks::load();
        srSiteServices($this->siteId);
        srMoveServices($this->folder);
        srSections($this->folder, ['remove' => ['section:services']]);

        [$exit, $output] = ContentPacks::run();

        expect($exit)->toBe(1, $output)
            ->and(ContentPacks::verdict($output, 'section:services'))->toBe('conflict')
            ->and(srLine($output, 'section:services'))->toContain('was not created by this pack');
    });

    it('refuses a section the loader took over from the site as it was', function (): void {
        srFlat($this->siteId);
        ContentPacks::load();
        srSiteServices($this->siteId);
        srSections($this->folder, ['items' => [srServices()]]);
        [$exit, $output] = ContentPacks::run();
        expect($exit)->toBe(0, $output)->and(ContentPacks::verdict($output, 'section:services'))->toBe('unchanged');
        [$exit, $output] = ContentPacks::load();
        expect($exit)->toBe(0, $output)
            ->and(ContentPacks::in($this->siteId, fn () => Ledger::read('demo-co'))['items']['section:services']['created'] ?? null)->toBeFalse();

        srMoveServices($this->folder);
        srSections($this->folder, ['remove' => ['section:services']]);
        [$exit, $output] = ContentPacks::run();

        expect($exit)->toBe(1, $output)
            ->and(ContentPacks::verdict($output, 'section:services'))->toBe('conflict')
            ->and(srLine($output, 'section:services'))->toContain('took the section «services» over from the site');
    });

    it('records the section it creates as its own', function (): void {
        srLoadServices($this->siteId, $this->folder);

        expect(ContentPacks::in($this->siteId, fn () => Ledger::read('demo-co'))['items']['section:services']['created'] ?? null)->toBeTrue();
        [$exit, $output] = ContentPacks::load();
        expect($exit)->toBe(0, $output)
            ->and(ContentPacks::in($this->siteId, fn () => Ledger::read('demo-co'))['items']['section:services']['created'] ?? null)->toBeTrue();
    });

    it('refuses when its category would belong to no section', function (): void {
        // Dịch vụ stays a top category: without its section, its posts would sit in no admin list.
        srFlat($this->siteId);
        srLoadServices($this->siteId, $this->folder);
        srSections($this->folder, ['remove' => ['section:services']]);

        [$exit, $output] = ContentPacks::run();

        expect($exit)->toBe(1, $output)
            ->and(ContentPacks::verdict($output, 'section:services'))->toBe('conflict')
            ->and(srLine($output, 'section:services'))->toContain('tax:post_cat/dich-vu would belong to no section');
    });

    it('has nothing to remove for a section the site does not have', function (): void {
        ContentPacks::load();
        srSections($this->folder, ['remove' => ['section:nothing_here']]);

        [$exit, $output] = ContentPacks::run();

        expect($exit)->toBe(0, $output)
            ->and(ContentPacks::verdict($output, 'section:nothing_here'))->toBe('unchanged')
            ->and(srLine($output, 'section:nothing_here'))->toContain('not on the site; nothing to remove');
    });
});

describe('the pack file', function (): void {
    it('rejects a removal that is not a section key, a platform section, or a key also written', function (array $sections, string $error): void {
        srSections($this->folder, $sections);

        try {
            $errors = app(PackValidator::class)->validate(app(PackReader::class)->read('demo-co'), 'demo-co');
        } catch (PackRejected $e) {
            $errors = $e->reasons;
        }

        expect(implode("\n", $errors))->toContain($error);
    })->with([
        'no prefix' => [['remove' => ['services']], 'a section key is section:<key>'],
        'not a list' => [['remove' => 'section:services'], '"remove" must be a list of section keys'],
        'platform' => [['remove' => ['section:pages']], "is the platform's own section"],
        'twice' => [['items' => [srServices()], 'remove' => ['section:services']], 'the key is used twice'],
    ]);

    it('rejects a moved category whose earlier key is not under another parent, or still in the pack', function (array $item, string $error): void {
        ContentPacks::edit($this->folder, 'taxonomies.json', fn (array $data): array => ['items' => [...$data['items'], $item]] + $data);

        $errors = app(PackValidator::class)->validate(app(PackReader::class)->read('demo-co'), 'demo-co');

        expect(implode("\n", $errors))->toContain($error);
    })->with([
        'same parent' => [['key' => 'tax:post_cat/tin-tuc/su-kien', 'parent' => 'tax:post_cat/tin-tuc', 'moved_from' => 'tax:post_cat/tin-tuc/ky-niem', 'name' => ['vi' => 'Sự kiện']], 'moved_from must be the category\'s earlier key, under another parent'],
        'other vocabulary' => [['key' => 'tax:post_cat/tin-tuc/su-kien', 'parent' => 'tax:post_cat/tin-tuc', 'moved_from' => 'tax:product_cat/su-kien', 'name' => ['vi' => 'Sự kiện']], 'moved_from must be the category\'s earlier key'],
        'still an item' => [['key' => 'tax:post_cat/tin-tuc/san-pham', 'parent' => 'tax:post_cat/tin-tuc', 'moved_from' => 'tax:post_cat/san-pham', 'name' => ['vi' => 'Sản phẩm']], 'is still an item of the pack'],
    ]);
});
