<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Media\Models\Media;
use App\Modules\Provisioning\ContentPack\CanonicalHash;
use App\Modules\Provisioning\ContentPack\Ledger;
use App\Modules\Provisioning\ContentPack\PackReader;
use App\Modules\Provisioning\ContentPack\Planner;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * site:load-content — the dry run, the first apply and the ledger, idempotency, unverified
 * content, refusals, images without the image step (MediaStepTest covers it) and the old seeder's guard. Every run
 * goes through the command as the app connection, against a site provisioned like a customer's.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $this->folder = ContentPacks::use();
    $this->siteId = ContentPacks::site();
});

afterEach(fn () => ContentPacks::cleanup());

it('writes nothing on a dry run and prints a fingerprint', function (): void {
    Storage::fake('r2');

    $writes = ContentPacks::writes(function () use (&$exit, &$output): void {
        [$exit, $output] = ContentPacks::run();
    });

    expect($exit)->toBe(0, $output)
        ->and($writes)->toBe([])
        ->and(ContentPacks::fingerprint($output))->toMatch('/^[0-9a-f]{12}$/')
        ->and($output)->toContain('Ledger: none (first load)')
        ->and(File::exists(ContentPacks::backups($this->folder)))->toBeFalse()
        ->and(Storage::disk('r2')->allFiles())->toBe([]);
});

it('creates every item on the first apply and records the read-back hash of each in the ledger', function (): void {
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);

    ContentPacks::in($this->siteId, function (): void {
        $page = Entry::query()->whereKey(SiteRoute::query()->where('path', '/gioi-thieu')->value('entry_id'))->firstOrFail();
        $post = EntryTranslation::query()->where('slug', 'ra-mat-website')->firstOrFail();
        $product = Product::query()->where('status', 'published')->firstOrFail();

        expect($page->status)->toBe('published')
            ->and($page->translationFor('vi')?->title)->toBe('Giới thiệu')
            ->and(Entry::query()->findOrFail($post->entry_id)->primaryCategory()?->translations()->value('slug'))->toBe('cap-nhat')
            ->and(SiteRoute::query()->where('path', '/cap-nhat/ra-mat-website')->exists())->toBeTrue()
            ->and($product->translations()->value('name'))->toBe('Máy mẫu A')
            ->and(TaxonomyTranslation::query()->where('slug', 'dich-vu')->exists())->toBeTrue()
            ->and(SiteSetting::query()->where('group_name', 'theme')->where('key', 'tokens')->value('value'))->toEqual(['primary' => '#0f766e', 'ink' => '#111827'])
            ->and(SiteSetting::query()->where('group_name', 'general')->where('key', 'brand')->first()?->value['name'])->toBe('Công ty Mẫu')
            ->and(SiteProfile::query()->value('email'))->toBe('hello@demo.test')
            ->and(Redirect::query()->where('from_path', '/trang-cu')->value('to_path'))->toBe('/gioi-thieu');

        $menuId = MenuAssignment::query()->where('region_key', 'primary')->where('locale', 'vi')->value('menu_id');
        expect(MenuItem::query()->where('menu_id', $menuId)->whereNull('parent_id')->count())->toBe(5);

        $pack = app(PackReader::class)->read('demo-co');
        $ledger = Ledger::read('demo-co');
        $plan = app(Planner::class)->plan($pack, Site::query()->value('ulid'), 'Demo', ContentPacks::HOST);

        expect(array_keys($ledger['items']))->toEqualCanonicalizing(array_map(fn ($item) => $item->key, $pack->items));
        foreach ($plan->items as $item) {
            expect($item->verdict)->toBe('unchanged')
                ->and($ledger['items'][$item->item->key]['hash'])->toBe($item->currentHash())
                ->and($ledger['items'][$item->item->key]['ref'])->not->toMatch('/^\d+$/');
        }
    });
    expectProductSearchWordsCurrent($this->siteId);
});

it('is idempotent: the second dry run is all unchanged and the second apply writes nothing', function (): void {
    ContentPacks::load();

    [$exit, $output] = ContentPacks::run();
    $count = count(app(PackReader::class)->read('demo-co')->items);
    expect($exit)->toBe(0)->and($output)->toContain("create 0 · update 0 · adopt 0 · unchanged {$count} · skip 0 · conflict 0");

    $writes = ContentPacks::writes(function () use ($output, &$exit): void {
        [$exit] = ContentPacks::run('demo-co', ['--apply' => true, '--plan' => ContentPacks::fingerprint($output)]);
    });
    expect($exit)->toBe(0)->and($writes)->toBe([]);
});

it('keeps unverified items as drafts, unpublishes a published item the pack marks unverified, and lists every claim', function (): void {
    ContentPacks::load();
    ContentPacks::in($this->siteId, fn () => expect(Product::query()->where('status', 'draft')->count())->toBe(1)
        ->and(Entry::query()->whereKey(EntryTranslation::query()->where('slug', 'giai-thuong-mau')->value('entry_id'))->value('status'))->toBe('draft'));

    ContentPacks::item($this->folder, 'posts.json', 'post:thiet-ke-mau', fn (array $item): array => ['verified' => false, 'status' => 'draft', 'claims' => ['Thiết kế trong 24 giờ']] + $item);
    [, $output] = ContentPacks::run();
    expect($output)->toMatch('/post:thiet-ke-mau\s+.*unpublish \(unverified: "Thiết kế trong 24 giờ"\)/');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);
    ContentPacks::in($this->siteId, fn () => expect(Entry::query()->whereKey(EntryTranslation::query()->where('slug', 'thiet-ke-mau')->value('entry_id'))->value('status'))->toBe('draft'));

    Artisan::call('site:load-content', ['pack' => 'demo-co', '--claims' => true]);
    expect(Artisan::output())->toContain('product:may-mau-b')->toContain('Công suất 10 kW')
        ->toContain('post:giai-thuong-mau')->toContain('Đạt giải thưởng mẫu năm 2026')->toContain('Thiết kế trong 24 giờ');
});

it('refuses a pack that publishes an unverified item, writing nothing', function (): void {
    ContentPacks::item($this->folder, 'products.json', 'product:may-mau-b', fn (array $item): array => ['status' => 'published'] + $item);

    $writes = ContentPacks::writes(function () use (&$exit, &$output): void {
        [$exit, $output] = ContentPacks::run();
    });

    expect($exit)->toBe(1)->and($writes)->toBe([])
        ->and($output)->toContain('an unverified item cannot be published');
});

it('refuses each unsafe run with exit 1 and no write', function (callable $setup, array $options, string $message): void {
    $options = $setup($this) ?? $options;

    $writes = ContentPacks::writes(function () use ($options, &$exit, &$output): void {
        $exit = Artisan::call('site:load-content', $options + ['pack' => 'demo-co', '--host' => ContentPacks::HOST]);
        $output = Artisan::output();
    });

    expect($exit)->toBe(1, $output)->and($output)->toContain($message)
        ->and(array_filter($writes, fn (string $sql): bool => ! str_contains($sql, 'audit_logs')))->toBe([]);
})->with([
    'unknown host' => [fn () => null, ['--host' => 'nowhere.platform.test'], "no site answers to 'nowhere.platform.test'"],
    'host not in the pack' => [function (): ?array {
        StorefrontCards::site('Other', 'other.platform.test');

        return ['--host' => 'other.platform.test'];
    }, [], 'is not meant for other.platform.test'],
    'apply without a plan' => [fn () => null, ['--apply' => true], '--apply needs --plan'],
    'apply with a wrong plan' => [fn () => null, ['--apply' => true, '--plan' => 'abcdefabcdef'], 'plan changed, run the dry run again'],
    'plan changed after the dry run' => [function ($test): array {
        [, $output] = ContentPacks::run();
        ContentPacks::in($test->siteId, fn () => SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'tokens', 'value' => ['primary' => '#000000']]));

        return ['--apply' => true, '--plan' => ContentPacks::fingerprint($output)];
    }, [], 'plan changed, run the dry run again'],
    'a path owned by an unrelated entry' => [function ($test): array {
        ContentPacks::in($test->siteId, function (): void {
            $post = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => now()]);
            $post->translations()->create(['locale' => 'vi', 'title' => 'Bài khác', 'slug' => 'gioi-thieu', 'status' => 'published', 'body' => ['blocks' => []]]);
            SiteRoute::query()->create(['locale' => 'vi', 'path' => '/gioi-thieu', 'entry_id' => $post->id]);
        });

        return [];
    }, [], 'conflict'],
    'the backup cannot be written' => [function ($test): array {
        [, $output] = ContentPacks::run();
        File::ensureDirectoryExists(dirname(ContentPacks::backups($test->folder)));
        File::put(ContentPacks::backups($test->folder), 'a file where the folder should be');

        return ['--apply' => true, '--plan' => ContentPacks::fingerprint($output)];
    }, [], 'the backup could not be written'],
]);

it('reuses an existing image for a slot with a legacy key, leaves the others out, and the pages render with no broken image', function (): void {
    $about = ContentPacks::in($this->siteId, fn () => StorefrontCards::media('about'));

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);

    ContentPacks::in($this->siteId, function () use ($about): void {
        $home = Entry::query()->whereKey(SiteRoute::query()->where('path', '/')->value('entry_id'))->firstOrFail()->translationFor('vi');
        $about_ = Entry::query()->whereKey(SiteRoute::query()->where('path', '/gioi-thieu')->value('entry_id'))->firstOrFail()->translationFor('vi');

        expect($home?->body['blocks'][0]['props']['image_url'])->toBe($about->id)
            ->and($about_?->body['blocks'][0]['props'])->not->toHaveKey('image_url')
            ->and(Entry::query()->whereKey(EntryTranslation::query()->where('slug', 'ra-mat-website')->value('entry_id'))->value('meta'))->not->toHaveKey('image_url');
    });

    foreach (['/', '/gioi-thieu'] as $path) {
        $html = $this->get('http://'.ContentPacks::HOST.$path)->assertOk()->getContent();
        expect($html)->not->toMatch('/<img[^>]*src=""/');
    }
});

it('keeps the image step out of the content load: it uploads nothing and refuses a media slot key', function (): void {
    Storage::fake('r2');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)
        ->and($output)->not->toContain('Uploaded')
        ->and(Storage::disk('r2')->allFiles())->toBe([]);

    [$exit, $output] = ContentPacks::run('demo-co', ['--force-item' => ['media:header']]);
    expect($exit)->toBe(1)->and($output)->toContain('--force-item media:header is not an item of the pack');
});

it('makes the old seeder refuse a site loaded from a content pack', function (): void {
    ContentPacks::load();

    $exit = Artisan::call('anvinh:seed-content', ['--host' => ContentPacks::HOST]);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain('site:load-content');
});

it('records one audit row per apply with the pack, its fingerprint and the totals', function (): void {
    $before = (int) AuditLog::query()->max('id'); // audit rows outlive the tables a test truncates
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);

    $meta = AuditLog::query()->where('action', 'content_pack.applied')->where('id', '>', $before)->sole()->meta;
    expect($meta)->toMatchArray(['pack' => 'demo-co', 'version' => 1])
        ->and($meta['fingerprint'])->toMatch('/^[0-9a-f]{12}$/')
        ->and($meta['totals'])->toHaveKey('create');
});

it('writes a category\'s post layout and then sees it as unchanged', function (): void {
    ContentPacks::item($this->folder, 'taxonomies.json', 'tax:post_cat/tin-tuc', fn (array $item): array => $item + ['detail_template' => 'project']);

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);
    ContentPacks::in($this->siteId, function (): void {
        $category = Taxonomy::query()->findOrFail(TaxonomyTranslation::query()->where('slug', 'tin-tuc')->value('taxonomy_id'));
        expect($category->detail_template)->toBe('project')
            ->and($category->list_template)->toBe('grid-news');
    });

    [, $again] = ContentPacks::run();
    expect(ContentPacks::verdict($again, 'tax:post_cat/tin-tuc'))->toBe('unchanged');
});

/** The publication date (Y-m-d, UTC) of the post behind a slug. */
function cpPostDate(int $siteId, string $slug): ?string
{
    return ContentPacks::in($siteId, fn () => Entry::query()
        ->whereKey(EntryTranslation::query()->where('slug', $slug)->value('entry_id'))
        ->firstOrFail()->published_at?->copy()->utc()->format('Y-m-d'));
}

function cpSetPostDate(int $siteId, string $slug, string $date): void
{
    ContentPacks::in($siteId, fn () => Entry::query()
        ->whereKey(EntryTranslation::query()->where('slug', $slug)->value('entry_id'))
        ->update(['published_at' => $date.' 08:00:00']));
}

it('moves a published post to the date its pack item states, and the plan says so before the apply writes it', function (): void {
    ContentPacks::load();
    expect(cpPostDate($this->siteId, 'ra-mat-website'))->toBe('2026-01-15');

    ContentPacks::item($this->folder, 'posts.json', 'post:ra-mat-website', fn (array $item): array => ['published_at' => '2024-03-05'] + $item);

    [, $output] = ContentPacks::run('demo-co', ['-vv' => true]);
    expect(ContentPacks::verdict($output, 'post:ra-mat-website'))->toBe('update')
        ->and($output)->toContain('published_at');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)
        ->and(cpPostDate($this->siteId, 'ra-mat-website'))->toBe('2024-03-05');

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'post:ra-mat-website'))->toBe('unchanged');
});

it('keeps the live date of a published post whose pack item states none', function (): void {
    ContentPacks::load();
    cpSetPostDate($this->siteId, 'ra-mat-website', '2025-07-07');
    ContentPacks::item($this->folder, 'posts.json', 'post:ra-mat-website', function (array $item): array {
        unset($item['published_at']);

        return $item;
    });

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'post:ra-mat-website'))->toBe('unchanged');

    ContentPacks::load();
    expect(cpPostDate($this->siteId, 'ra-mat-website'))->toBe('2025-07-07');
});

it('does not overwrite the date of a post staff edited, until it is forced', function (): void {
    ContentPacks::load();
    ContentPacks::in($this->siteId, fn () => EntryTranslation::query()->where('slug', 'ra-mat-website')->update(['title' => 'Tiêu đề do nhân viên sửa']));
    ContentPacks::item($this->folder, 'posts.json', 'post:ra-mat-website', fn (array $item): array => ['published_at' => '2024-03-05'] + $item);

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'post:ra-mat-website'))->toBe('skip:edited');
    ContentPacks::load();
    expect(cpPostDate($this->siteId, 'ra-mat-website'))->toBe('2026-01-15');

    ContentPacks::load('demo-co', ['post:ra-mat-website']);
    expect(cpPostDate($this->siteId, 'ra-mat-website'))->toBe('2024-03-05');
});

/** Rewrite the ledger line of one item as a load made before the date was managed recorded it: the hash of the state without published_at. */
function cpLegacyLedger(int $siteId, string $key): void
{
    ContentPacks::in($siteId, function () use ($key): void {
        $plan = app(Planner::class)->plan(app(PackReader::class)->read('demo-co'), Site::query()->value('ulid'), 'Demo', ContentPacks::HOST);
        $current = $plan->item($key)?->current ?? [];
        unset($current['published_at']);
        $ledger = Ledger::read('demo-co');
        $items = $ledger['items'];
        $items[$key]['hash'] = CanonicalHash::of($current);
        Ledger::write('demo-co', $ledger['pack_version'], $items, null);
    });
}

it('plans an entry loaded before the date was managed as an update and applies the new date', function (): void {
    ContentPacks::load();
    cpLegacyLedger($this->siteId, 'post:ra-mat-website');
    ContentPacks::item($this->folder, 'posts.json', 'post:ra-mat-website', fn (array $item): array => ['published_at' => '2024-03-05'] + $item);

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'post:ra-mat-website'))->toBe('update');

    ContentPacks::load();
    expect(cpPostDate($this->siteId, 'ra-mat-website'))->toBe('2024-03-05');
});

it('still skips an entry loaded before the date was managed when staff also changed its title', function (): void {
    ContentPacks::load();
    cpLegacyLedger($this->siteId, 'post:ra-mat-website');
    ContentPacks::in($this->siteId, fn () => EntryTranslation::query()->where('slug', 'ra-mat-website')->update(['title' => 'Tiêu đề do nhân viên sửa']));
    ContentPacks::item($this->folder, 'posts.json', 'post:ra-mat-website', fn (array $item): array => ['published_at' => '2024-03-05'] + $item);

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'post:ra-mat-website'))->toBe('skip:edited');

    ContentPacks::load();
    expect(cpPostDate($this->siteId, 'ra-mat-website'))->toBe('2026-01-15');
});

it('leaves the date of an entry alone when its pack item is a draft that states one', function (): void {
    ContentPacks::load();
    cpSetPostDate($this->siteId, 'ra-mat-website', '2025-07-07');
    ContentPacks::item($this->folder, 'posts.json', 'post:ra-mat-website', fn (array $item): array => ['published_at' => '2024-03-05', 'status' => 'draft'] + $item);

    ContentPacks::load();
    expect(cpPostDate($this->siteId, 'ra-mat-website'))->toBe('2025-07-07');
});

/** Add page:/doi-ngu after the pack's own pages, under /gioi-thieu unless $extra says otherwise ([] names no parent). */
function cpAddChildPage(string $folder, array $extra = ['parent' => 'page:/gioi-thieu']): void
{
    ContentPacks::edit($folder, 'pages.json', function (array $data) use ($extra): array {
        $data['items'][] = $extra + [
            'key' => 'page:/doi-ngu',
            'verified' => true,
            'status' => 'published',
            'title' => ['vi' => 'Đội ngũ'],
            'body' => ['vi' => [['type' => 'page_header', 'props' => ['title' => 'Đội Ngũ', 'subtitle' => 'Những người làm nên sản phẩm']]]],
        ];

        return $data;
    });
}

/** @return array{parent: int|null, gioiThieu: int, route: string|null} */
function cpChildPage(int $siteId): array
{
    return ContentPacks::in($siteId, function (): array {
        $child = SiteRoute::query()->where('path', '/doi-ngu')->value('entry_id');

        return [
            'parent' => Entry::query()->whereKey($child)->value('parent_id'),
            'gioiThieu' => (int) SiteRoute::query()->where('path', '/gioi-thieu')->value('entry_id'),
            'route' => SiteRoute::query()->where('entry_id', $child)->value('path'),
        ];
    });
}

it('puts a page under the parent page it names, at its own address, with the parent in its breadcrumb, and re-plans it unchanged', function (): void {
    cpAddChildPage($this->folder);

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);

    $page = cpChildPage($this->siteId);
    expect($page['parent'])->toBe($page['gioiThieu'])
        ->and($page['route'])->toBe('/doi-ngu');

    $html = $this->get('http://'.ContentPacks::HOST.'/doi-ngu')->assertOk()->getContent();
    expect(preg_match('#<nav class="sf-crumbs[^"]*"[^>]*>.*?</nav>#s', $html, $crumbs))->toBe(1)
        ->and(strip_tags($crumbs[0]))->toMatch('/Trang chủ\s+Giới thiệu\s+Đội ngũ/u')
        ->and($crumbs[0])->toContain('href="/gioi-thieu"');
    $this->get('http://'.ContentPacks::HOST.'/gioi-thieu/doi-ngu')->assertNotFound();

    [$exit, $output] = ContentPacks::run();
    $count = count(app(PackReader::class)->read('demo-co')->items);
    expect($exit)->toBe(0)->and($output)->toContain("create 0 · update 0 · adopt 0 · unchanged {$count} · skip 0 · conflict 0");
});

it('gives a page loaded before parents were managed its parent as an update, leaves it when the item names none, and clears it on null', function (): void {
    cpAddChildPage($this->folder, []);
    ContentPacks::load();
    expect(cpChildPage($this->siteId)['parent'])->toBeNull();

    ContentPacks::item($this->folder, 'pages.json', 'page:/doi-ngu', fn (array $item): array => ['parent' => 'page:/gioi-thieu'] + $item);
    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'page:/doi-ngu'))->toBe('update')
        ->and($output)->toContain('parent');
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);
    $page = cpChildPage($this->siteId);
    expect($page['parent'])->toBe($page['gioiThieu'])->and($page['route'])->toBe('/doi-ngu');

    ContentPacks::item($this->folder, 'pages.json', 'page:/doi-ngu', function (array $item): array {
        unset($item['parent']);

        return $item;
    });
    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'page:/doi-ngu'))->toBe('unchanged');

    ContentPacks::item($this->folder, 'pages.json', 'page:/doi-ngu', fn (array $item): array => ['parent' => null] + $item);
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)->and(cpChildPage($this->siteId)['parent'])->toBeNull();
});

it('still skips a page staff moved under another parent since the last load', function (): void {
    cpAddChildPage($this->folder);
    ContentPacks::load();
    ContentPacks::in($this->siteId, fn () => Entry::query()->whereKey(SiteRoute::query()->where('path', '/doi-ngu')->value('entry_id'))
        ->update(['parent_id' => SiteRoute::query()->where('path', '/')->value('entry_id')]));
    ContentPacks::item($this->folder, 'pages.json', 'page:/doi-ngu', fn (array $item): array => ['title' => ['vi' => 'Đội ngũ kỹ sư']] + $item);

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'page:/doi-ngu'))->toBe('skip:edited');
});

it('makes a page a conflict when its parent cannot be given', function (Closure $setup, string $message): void {
    $setup($this);

    [$exit, $output] = ContentPacks::run();
    expect($exit)->toBe(1, $output)
        ->and(ContentPacks::verdict($output, 'page:/doi-ngu'))->toBe('conflict')
        ->and($output)->toContain($message);
})->with([
    'a parent neither in the pack nor on the site' => [function ($test): void {
        cpAddChildPage($test->folder, ['parent' => 'page:/khong-co']);
    }, 'its parent page page:/khong-co is neither in the pack nor on the site'],
    'a site that nests page addresses' => [function ($test): void {
        ContentPacks::in($test->siteId, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'pages'], ['value' => ['pattern' => 'nested']]));
        cpAddChildPage($test->folder);
    }, 'the site nests page addresses under their parent page'],
    'a loop through a live parent' => [function ($test): void {
        cpAddChildPage($test->folder, []);
        ContentPacks::load();
        ContentPacks::in($test->siteId, fn () => Entry::query()->whereKey(SiteRoute::query()->where('path', '/gioi-thieu')->value('entry_id'))
            ->update(['parent_id' => SiteRoute::query()->where('path', '/doi-ngu')->value('entry_id')]));
        ContentPacks::item($test->folder, 'pages.json', 'page:/doi-ngu', fn (array $item): array => ['parent' => 'page:/gioi-thieu'] + $item);
    }, 'its parent pages lead back to page:/doi-ngu itself'],
    'a parent staff deleted' => [function ($test): void {
        cpAddChildPage($test->folder);
        ContentPacks::load();
        ContentPacks::in($test->siteId, fn () => Entry::query()->whereKey(SiteRoute::query()->where('path', '/gioi-thieu')->value('entry_id'))->firstOrFail()->delete());
    }, 'its parent page page:/gioi-thieu would not exist after this load'],
]);

it('never takes another site\'s page for the parent a page names', function (): void {
    config(['tenancy.assert' => 'strict']);
    $other = StorefrontCards::site('Other', 'other.platform.test');
    ContentPacks::in($other, function (): void {
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu B', 'slug' => 'gioi-thieu', 'status' => 'published', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/gioi-thieu', 'entry_id' => $page->id]);
    });
    ContentPacks::edit($this->folder, 'pages.json', fn (array $data): array => ['items' => array_values(array_filter($data['items'], fn (array $item): bool => $item['key'] !== 'page:/gioi-thieu'))] + $data);
    cpAddChildPage($this->folder);

    [$exit, $output] = ContentPacks::run();
    expect($exit)->toBe(1, $output)
        ->and(ContentPacks::verdict($output, 'page:/doi-ngu'))->toBe('conflict')
        ->and($output)->toContain('its parent page page:/gioi-thieu is neither in the pack nor on the site');
});

it('records each page\'s parent in its ledger line and skips a page staff moved before its item named a parent', function (): void {
    cpAddChildPage($this->folder, []);
    ContentPacks::load();
    $line = fn (): array => ContentPacks::in($this->siteId, fn (): ?array => Ledger::read('demo-co'))['items']['page:/doi-ngu'] ?? [];
    expect($line())->toHaveKey('parent')->and($line()['parent'])->toBeNull();

    ContentPacks::in($this->siteId, fn () => Entry::query()->whereKey(SiteRoute::query()->where('path', '/doi-ngu')->value('entry_id'))
        ->update(['parent_id' => SiteRoute::query()->where('path', '/')->value('entry_id')]));
    // a load that leaves the page as it is keeps the parent its line recorded, not the one staff chose
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)->and($line()['parent'])->toBeNull();

    ContentPacks::item($this->folder, 'pages.json', 'page:/doi-ngu', fn (array $item): array => ['parent' => 'page:/gioi-thieu'] + $item);
    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'page:/doi-ngu'))->toBe('skip:edited');
});

it('writes the short facts and the badge of a post into its meta, plans a change as an update, and removes them when the item drops them', function (): void {
    $meta = fn (): array => (array) ContentPacks::in($this->siteId, fn () => Entry::query()->whereKey(EntryTranslation::query()->where('slug', 'ra-mat-website')->value('entry_id'))->value('meta'));
    $facts = [['text' => ['vi' => 'Trung Giã'], 'tone' => 'plain'], ['text' => ['vi' => 'Lương: Thỏa thuận'], 'tone' => 'accent']];

    ContentPacks::load();
    expect($meta())->not->toHaveKey('facts');

    ContentPacks::item($this->folder, 'posts.json', 'post:ra-mat-website', fn (array $item): array => ['facts' => $facts, 'badge' => ['vi' => 'Tuyển gấp']] + $item);
    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'post:ra-mat-website'))->toBe('update');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)
        ->and($meta()['facts'])->toBe($facts)
        ->and($meta()['badge'])->toBe(['vi' => 'Tuyển gấp']);

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'post:ra-mat-website'))->toBe('unchanged');

    ContentPacks::item($this->folder, 'posts.json', 'post:ra-mat-website', function (array $item): array {
        unset($item['facts'], $item['badge']);

        return $item;
    });
    ContentPacks::load();
    expect($meta())->not->toHaveKey('facts')->not->toHaveKey('badge');
});

it('keeps a post loaded before facts and badges existed reading as unchanged, and plans a post that gains facts as an update', function (): void {
    ContentPacks::load();
    ContentPacks::in($this->siteId, function (): void {
        $plan = app(Planner::class)->plan(app(PackReader::class)->read('demo-co'), Site::query()->value('ulid'), 'Demo', ContentPacks::HOST);
        $current = $plan->item('post:ra-mat-website')?->current ?? [];
        // The state shape a load made before facts and badges recorded: neither key at all.
        expect($current)->not->toHaveKey('facts')->not->toHaveKey('badge');
        $ledger = Ledger::read('demo-co');
        $items = $ledger['items'];
        $items['post:ra-mat-website']['hash'] = CanonicalHash::of($current);
        Ledger::write('demo-co', $ledger['pack_version'], $items, null);
    });

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'post:ra-mat-website'))->toBe('unchanged');

    ContentPacks::item($this->folder, 'posts.json', 'post:ra-mat-website', fn (array $item): array => ['facts' => [['text' => ['vi' => 'Trung Giã'], 'tone' => 'plain']]] + $item);
    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'post:ra-mat-website'))->toBe('update');
});

/** The intro picture's storage key and the intro text of the category with this slug. */
function cpCategoryIntro(int $siteId, string $slug = 'tin-tuc'): array
{
    return ContentPacks::in($siteId, function () use ($slug): array {
        $category = Taxonomy::query()->findOrFail(TaxonomyTranslation::query()->where('slug', $slug)->value('taxonomy_id'));

        return [
            'cover' => $category->cover_media_id === null ? null : Media::query()->whereKey($category->cover_media_id)->value('storage_key'),
            'intro' => TaxonomyTranslation::query()->where('taxonomy_id', $category->id)->where('locale', 'vi')->value('description'),
        ];
    });
}

/** Gives the news category of the pack the fields of an intro. */
function cpCategoryFields(string $folder, array $fields): void
{
    ContentPacks::item($folder, 'taxonomies.json', 'tax:post_cat/tin-tuc', fn (array $item): array => [...$item, ...$fields]);
}

it('writes a category\'s intro picture and text, plans a change as an update, and sees a second identical load as unchanged', function (): void {
    $about = ContentPacks::in($this->siteId, fn () => StorefrontCards::media('about'));
    $logo = ContentPacks::in($this->siteId, fn () => StorefrontCards::media('logo'));
    cpCategoryFields($this->folder, ['cover' => ['media' => 'about'], 'description' => ['vi' => 'Tin tức của chúng tôi.']]);

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)
        ->and(cpCategoryIntro($this->siteId))->toBe(['cover' => $about->storage_key, 'intro' => 'Tin tức của chúng tôi.']);

    [, $again] = ContentPacks::run();
    expect(ContentPacks::verdict($again, 'tax:post_cat/tin-tuc'))->toBe('unchanged');

    cpCategoryFields($this->folder, ['cover' => ['media' => 'logo'], 'description' => ['vi' => 'Tin mới.']]);
    [, $plan] = ContentPacks::run();
    expect(ContentPacks::verdict($plan, 'tax:post_cat/tin-tuc'))->toBe('update');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)
        ->and(cpCategoryIntro($this->siteId))->toBe(['cover' => $logo->storage_key, 'intro' => 'Tin mới.']);

    [, $settled] = ContentPacks::run();
    expect(ContentPacks::verdict($settled, 'tax:post_cat/tin-tuc'))->toBe('unchanged');
});

it('clears a category\'s intro when the pack states it empty or null, and then plans nothing', function (mixed $empty): void {
    cpCategoryFields($this->folder, ['description' => ['vi' => 'Có lời giới thiệu.']]);
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)->and(cpCategoryIntro($this->siteId)['intro'])->toBe('Có lời giới thiệu.');

    cpCategoryFields($this->folder, ['description' => ['vi' => $empty]]);
    [, $plan] = ContentPacks::run();
    expect(ContentPacks::verdict($plan, 'tax:post_cat/tin-tuc'))->toBe('update');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)->and(cpCategoryIntro($this->siteId)['intro'])->toBeNull();

    [, $settled] = ContentPacks::run();
    expect(ContentPacks::verdict($settled, 'tax:post_cat/tin-tuc'))->toBe('unchanged');
})->with(['empty text' => [''], 'null' => [null]]);

it('keeps the live intro picture and text of a category whose pack item states neither', function (): void {
    $about = ContentPacks::in($this->siteId, fn () => StorefrontCards::media('about'));
    cpCategoryFields($this->folder, ['cover' => ['media' => 'about'], 'description' => ['vi' => 'Giữ lại.']]);
    expect(ContentPacks::load()[0])->toBe(0);

    ContentPacks::item($this->folder, 'taxonomies.json', 'tax:post_cat/tin-tuc', function (array $item): array {
        unset($item['cover'], $item['description']);

        return $item;
    });
    [, $plan] = ContentPacks::run();
    expect(ContentPacks::verdict($plan, 'tax:post_cat/tin-tuc'))->toBe('unchanged');

    expect(ContentPacks::load()[0])->toBe(0)
        ->and(cpCategoryIntro($this->siteId))->toBe(['cover' => $about->storage_key, 'intro' => 'Giữ lại.']);
});

it('warns about an intro picture whose file is not uploaded and keeps the live picture', function (): void {
    $about = ContentPacks::in($this->siteId, fn () => StorefrontCards::media('about'));
    cpCategoryFields($this->folder, ['cover' => ['media' => 'about']]);
    expect(ContentPacks::load()[0])->toBe(0);

    cpCategoryFields($this->folder, ['cover' => ['media' => 'header']]);
    [, $output] = ContentPacks::run();
    expect($output)->toContain('tax:post_cat/tin-tuc')->toContain('keeps its live picture');

    expect(ContentPacks::load()[0])->toBe(0)
        ->and(cpCategoryIntro($this->siteId)['cover'])->toBe($about->storage_key);
});

/** The news category's card label and the byline of the launch post, as stored. */
function cpLabelAndByline(int $siteId): array
{
    return ContentPacks::in($siteId, fn (): array => [
        'label' => TaxonomyTranslation::query()->where('slug', 'tin-tuc')->where('locale', 'vi')->value('card_label'),
        'author' => EntryTranslation::query()->where('slug', 'ra-mat-website')->where('locale', 'vi')->value('author_name'),
    ]);
}

it('writes a category\'s card label and a post\'s byline, sees a second load as unchanged, and clears them when the pack drops them', function (): void {
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)->and(cpLabelAndByline($this->siteId))->toBe(['label' => null, 'author' => null]);

    cpCategoryFields($this->folder, ['card_label' => ['vi' => 'Bản tin']]);
    ContentPacks::item($this->folder, 'posts.json', 'post:ra-mat-website', fn (array $item): array => [...$item, 'author' => ['vi' => 'Phòng Kỹ thuật']]);
    [, $plan] = ContentPacks::run();
    expect(ContentPacks::verdict($plan, 'tax:post_cat/tin-tuc'))->toBe('update')
        ->and(ContentPacks::verdict($plan, 'post:ra-mat-website'))->toBe('update');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)->and(cpLabelAndByline($this->siteId))->toBe(['label' => 'Bản tin', 'author' => 'Phòng Kỹ thuật']);

    [, $again] = ContentPacks::run();
    expect(ContentPacks::verdict($again, 'tax:post_cat/tin-tuc'))->toBe('unchanged')
        ->and(ContentPacks::verdict($again, 'post:ra-mat-website'))->toBe('unchanged');

    // A category item that no longer names its label keeps the live one; one that states it empty clears it.
    // A post's byline is part of its text, like the excerpt: an item without one has none.
    cpCategoryFields($this->folder, ['card_label' => ['vi' => '']]);
    ContentPacks::item($this->folder, 'posts.json', 'post:ra-mat-website', function (array $item): array {
        unset($item['author']);

        return $item;
    });
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)->and(cpLabelAndByline($this->siteId))->toBe(['label' => null, 'author' => null]);

    [, $settled] = ContentPacks::run();
    expect(ContentPacks::verdict($settled, 'tax:post_cat/tin-tuc'))->toBe('unchanged')
        ->and(ContentPacks::verdict($settled, 'post:ra-mat-website'))->toBe('unchanged');
});

it('keeps the live card label of a category whose pack item does not name one', function (): void {
    cpCategoryFields($this->folder, ['card_label' => ['vi' => 'Bản tin']]);
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);

    ContentPacks::item($this->folder, 'taxonomies.json', 'tax:post_cat/tin-tuc', function (array $item): array {
        unset($item['card_label']);

        return $item;
    });
    [, $plan] = ContentPacks::run();
    expect(ContentPacks::verdict($plan, 'tax:post_cat/tin-tuc'))->toBe('unchanged')
        ->and(cpLabelAndByline($this->siteId)['label'])->toBe('Bản tin');
});
