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
