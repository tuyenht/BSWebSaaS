<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\ContentPack\Backup;
use App\Modules\Provisioning\ContentPack\Ledger;
use App\Modules\Provisioning\ContentPack\PackReader;
use App\Modules\Provisioning\ContentPack\PackRejected;
use App\Modules\Provisioning\ContentPack\PackValidator;
use App\Modules\Provisioning\ContentPack\Planner;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * A pack removes a content section (sections.json `remove`) only when it is safe: the loader created
 * the section and nobody changed it, no active workflow uses its key, its posts then belong to
 * another section (here «posts», which the pack makes cover the category beside its own, both at
 * the top of the tree) and not one post address moves. The row, its permalink setting and the
 * members' saved views of its list are backed up first and go with it; the ledger forgets it.
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

/** The news section («posts», the site's own) as the pack writes it: Tin tức and, beside it, Dịch vụ. */
function srNews(array $categories = ['tax:post_cat/tin-tuc', 'tax:post_cat/dich-vu']): array
{
    return ['key' => 'section:posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'categories' => $categories, 'sort_order' => 1];
}

/** The pack hands Dịch vụ to the news section and removes the services section, in one load. */
function srNewsTakesServices(string $folder): void
{
    srSections($folder, ['items' => [srNews()], 'remove' => ['section:services']]);
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
    it('removes it with its saved list views and permalink, its posts joining the section that takes its category, no address moving', function (): void {
        srFlat($this->siteId);
        srLoadServices($this->siteId, $this->folder);
        ContentPacks::in($this->siteId, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'services'], ['value' => ['pattern' => 'flat']]));
        $owner = srOwner();
        // The owner keeps a view of the section's list; another list's view stays.
        DB::table('users')->where('id', $owner->id)->update(['preferences' => json_encode(['lists' => [
            'entries.post.services' => ['cols' => ['title']], 'entries.post.posts' => ['cols' => ['title', 'date']],
        ]])]);
        $routes = srRoutes($this->siteId);
        expect(srSectionOf($this->siteId, 'thiet-ke-mau'))->toBe('services');

        srNewsTakesServices($this->folder);
        [$exit, $output] = ContentPacks::run();
        expect($exit)->toBe(0, $output)
            ->and(ContentPacks::verdict($output, 'section:services'))->toBe('remove')
            ->and(srLine($output, 'section:services'))->toContain('its posts join «posts»')
            ->and(srLine($output, 'section:services'))->toContain('0 post addresses move')
            ->and(srLine($output, 'section:services'))->toContain('1 saved list view of it pruned')
            // The news section is the site's own: only its categories change, so the loader takes it over.
            ->and(ContentPacks::verdict($output, 'section:posts'))->toBe('adopt')
            ->and(srLine($output, 'section:posts'))->toContain('adds tax:post_cat/dich-vu; 0 post addresses move')
            ->and($output)->toContain('remove 1');
        // The removal is planned before the section that takes its category.
        expect(strpos($output, ' section:services'))->toBeLessThan(strpos($output, ' section:posts'));

        [$exit, $output] = ContentPacks::load();
        expect($exit)->toBe(0, $output)
            ->and(ContentPacks::in($this->siteId, fn () => ContentSection::query()->where('key', 'services')->exists()))->toBeFalse()
            ->and(ContentPacks::in($this->siteId, fn () => SiteSetting::query()->where('group_name', 'permalinks')->where('key', 'services')->exists()))->toBeFalse()
            ->and(srRoutes($this->siteId))->toBe($routes)
            ->and(srSectionOf($this->siteId, 'thiet-ke-mau'))->toBe('posts');
        // Dịch vụ stays at the top of the tree, beside Tin tức.
        $dichVu = ContentPacks::in($this->siteId, fn () => Taxonomy::query()->findOrFail(TaxonomyTranslation::query()->where('locale', 'vi')->where('slug', 'dich-vu')->value('taxonomy_id')));
        $tinTuc = (int) ContentPacks::in($this->siteId, fn () => TaxonomyTranslation::query()->where('locale', 'vi')->where('slug', 'tin-tuc')->value('taxonomy_id'));
        expect($dichVu->parent_id)->toBeNull()
            ->and(ContentPacks::in($this->siteId, fn () => ContentSection::query()->where('key', 'posts')->firstOrFail()->categoryIds()))->toBe([$tinTuc, $dichVu->id]);

        // The saved view went with the section; the other list's stayed.
        $lists = json_decode((string) DB::table('users')->where('id', $owner->id)->value('preferences'), true)['lists'];
        expect($lists)->toBe(['entries.post.posts' => ['cols' => ['title', 'date']]]);

        // The backup holds the row, its category, its permalink and the saved view.
        expect(preg_match('/^Backup: (\S+)$/m', $output, $m))->toBe(1, $output);
        $backup = json_decode((string) gzdecode((string) file_get_contents($m[1])), true, 512, JSON_THROW_ON_ERROR);
        $item = collect($backup['items'])->firstWhere('key', 'section:services');
        expect($item['rows']['section']['key'] ?? null)->toBe('services')
            ->and($item['rows']['section']['label'] ?? null)->toBe('Dịch vụ')
            ->and(array_column($item['rows']['categories'] ?? [], 'taxonomy_id'))->toBe([$dichVu->id])
            ->and($item['rows']['permalinks'][0]['value'] ?? null)->toBe(['pattern' => 'flat'])
            ->and($item['rows']['saved_list_views'] ?? null)->toBe([(string) $owner->id => ['cols' => ['title']]]);

        // The ledger forgets the section and records the news section's categories.
        $ledger = ContentPacks::in($this->siteId, fn () => Ledger::read('demo-co'));
        expect($ledger['items'])->not->toHaveKey('section:services')
            ->and($ledger['items'])->toHaveKey('section:posts')
            ->and($ledger['items']['section:posts']['created'] ?? null)->toBeFalse();

        // The news list now holds Dịch vụ and its post; Dịch vụ is a choice at the top of its filter.
        $test = ContentPacks::in($this->siteId, fn () => Livewire::actingAs($owner)->test(Index::class, ['section' => 'posts']));
        $titles = collect($test->viewData('rows')->items())->map(fn (array $row): string => (string) $row['entry']->translations->firstWhere('locale', 'vi')?->title)->all();
        expect($titles)->toContain('Thiết kế mẫu')
            ->and(collect($test->viewData('catOptions'))->firstWhere('label', 'Dịch vụ')['depth'] ?? null)->toBe(0);

        // A second run has nothing to do.
        [$exit, $output] = ContentPacks::run();
        expect($exit)->toBe(0, $output)
            ->and(ContentPacks::verdict($output, 'section:services'))->toBe('unchanged')
            ->and(srLine($output, 'section:services'))->toContain('nothing to remove')
            ->and(ContentPacks::verdict($output, 'section:posts'))->toBe('unchanged');
    });

    it('refuses when a post address would move without the section, and writes nothing', function (): void {
        // The news addresses follow their category (the fixture's preset): under the news the services post would leave /thiet-ke-mau.
        srLoadServices($this->siteId, $this->folder);
        $routes = srRoutes($this->siteId);
        srNewsTakesServices($this->folder);

        [$exit, $output] = ContentPacks::run();
        expect($exit)->toBe(1, $output)
            ->and(ContentPacks::verdict($output, 'section:posts'))->toBe('conflict')
            ->and(srLine($output, 'section:posts'))->toContain('/thiet-ke-mau → /dich-vu/thiet-ke-mau')
            // Nothing else takes Dịch vụ, so the removal stays too.
            ->and(ContentPacks::verdict($output, 'section:services'))->toBe('conflict')
            ->and(srLine($output, 'section:services'))->toContain('tax:post_cat/dich-vu would belong to no section');

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
        srNewsTakesServices($this->folder);

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
        srNewsTakesServices($this->folder);

        [$exit, $output] = ContentPacks::run();

        expect($exit)->toBe(0, $output)
            ->and(srLine($output, 'section:services'))->toContain('its posts join «posts» and its workflow «Duyệt tin»');
    });

    it('refuses a section staff edited since the load: its label, or a layout of its own', function (array $change, string $why): void {
        srFlat($this->siteId);
        srLoadServices($this->siteId, $this->folder);
        ContentPacks::in($this->siteId, fn () => ContentSection::query()->where('key', 'services')->update($change));
        srNewsTakesServices($this->folder);

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
        srNewsTakesServices($this->folder);

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

        srNewsTakesServices($this->folder);
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

    it('removes a section whose ledger line predates the created flag only when the run names it', function (): void {
        srFlat($this->siteId);
        srLoadServices($this->siteId, $this->folder);
        // A line an older loader wrote: no created flag.
        ContentPacks::in($this->siteId, function (): void {
            $row = SiteSetting::query()->where('group_name', Ledger::GROUP)->where('key', 'demo-co')->firstOrFail();
            $value = is_string($row->value) ? json_decode($row->value, true) : $row->value;
            unset($value['items']['section:services']['created']);
            $row->update(['value' => $value]);
        });
        srNewsTakesServices($this->folder);

        [$exit, $output] = ContentPacks::run();
        expect($exit)->toBe(1, $output)
            ->and(ContentPacks::verdict($output, 'section:services'))->toBe('conflict')
            ->and(srLine($output, 'section:services'))->toContain('--force-item=section:services to remove it');

        [$exit, $output] = ContentPacks::run(options: ['--force-item' => ['section:services']]);
        expect($exit)->toBe(0, $output)->and(ContentPacks::verdict($output, 'section:services'))->toStartWith('remove');
        [$exit, $output] = ContentPacks::load(force: ['section:services']);
        expect($exit)->toBe(0, $output)
            ->and(srSectionOf($this->siteId, 'thiet-ke-mau'))->toBe('posts');
    });

    it('never gives a section a category another section keeps, nor takes one off a section', function (): void {
        srFlat($this->siteId);
        srLoadServices($this->siteId, $this->folder);

        // Dịch vụ stays the services section's: the news section may not take it.
        srSections($this->folder, ['items' => [srServices(), srNews()]]);
        [$exit, $output] = ContentPacks::run();
        expect($exit)->toBe(1, $output)
            ->and(ContentPacks::verdict($output, 'section:posts'))->toBe('conflict')
            ->and(srLine($output, 'section:posts'))->toContain('tax:post_cat/dich-vu is already the section «services»');

        srNewsTakesServices($this->folder);
        [$exit, $output] = ContentPacks::load();
        expect($exit)->toBe(0, $output);

        // A pack that no longer names Dịch vụ for the news leaves it there: a conflict, nothing changes.
        srSections($this->folder, ['items' => [srNews(['tax:post_cat/tin-tuc'])]]);
        [$exit, $output] = ContentPacks::run();
        expect($exit)->toBe(1, $output)
            ->and(srLine($output, 'section:posts'))->toContain('also covers tax:post_cat/dich-vu; the loader never takes a category off a section');
    });

    it('refuses the apply when a removed section changed after its backup was read', function (): void {
        srFlat($this->siteId);
        srLoadServices($this->siteId, $this->folder);
        srNewsTakesServices($this->folder);
        $owner = srOwner();

        ContentPacks::in($this->siteId, function () use ($owner): void {
            $pack = app(PackReader::class)->read('demo-co');
            $site = Site::query()->findOrFail($this->siteId);
            $planner = app(Planner::class);
            $plan = $planner->plan($pack, $site->ulid, $site->name, ContentPacks::HOST, []);
            $backup = app(Backup::class);
            $snapshot = $backup->snapshot($plan, $planner);
            $backup->assertRemovalsUnchanged($plan, $snapshot);   // as read: passes

            // A member saves a view of the section's list after the backup was read.
            DB::table('users')->where('id', $owner->id)->update(['preferences' => json_encode(['lists' => ['entries.post.services' => ['cols' => ['title']]]])]);
            expect(fn () => $backup->assertRemovalsUnchanged($plan, $snapshot))->toThrow(PackRejected::class, 'changed after the backup was taken');
        });
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

    it('rejects a section naming both category and categories, none, or one category under another of its list', function (array $item, string $error): void {
        srSections($this->folder, ['items' => [$item]]);

        try {
            $errors = app(PackValidator::class)->validate(app(PackReader::class)->read('demo-co'), 'demo-co');
        } catch (PackRejected $e) {
            $errors = $e->reasons;
        }

        expect(implode("\n", $errors))->toContain($error);
    })->with([
        'both' => [srNews() + ['category' => 'tax:post_cat/tin-tuc'], 'name either category or categories, not both'],
        'empty' => [srNews([]), 'categories must list from 1 to 20 post category keys'],
        'nested' => [srNews(['tax:post_cat/tin-tuc', 'tax:post_cat/tin-tuc/cap-nhat']), 'tax:post_cat/tin-tuc/cap-nhat sits under tax:post_cat/tin-tuc'],
        'unknown' => [srNews(['tax:post_cat/tin-tuc', 'tax:post_cat/khong-co']), 'category tax:post_cat/khong-co is not in taxonomies.json'],
    ]);
});
