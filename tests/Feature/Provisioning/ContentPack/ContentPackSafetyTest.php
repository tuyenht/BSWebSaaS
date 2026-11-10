<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\Menus\DeleteMenuItem;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\ContentPack\Applier;
use App\Modules\Provisioning\ContentPack\Ledger;
use App\Modules\Provisioning\ContentPack\PackReader;
use App\Modules\Provisioning\ContentPack\Plan;
use App\Modules\Provisioning\ContentPack\PlanItem;
use App\Modules\Provisioning\ContentPack\Planner;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * site:load-content — what it must never do: overwrite a staff edit, bring back what staff
 * deleted, step over a pending draft, touch another site, or leave half a load behind. Plus the
 * backup it writes first and the URL change a category rename makes.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $this->folder = ContentPacks::use();
    $this->siteId = ContentPacks::site();
});

afterEach(fn () => ContentPacks::cleanup());

/** The entry a page path routes to (inside the tenant context). */
function cpEntryAt(string $path): Entry
{
    return Entry::query()->whereKey(SiteRoute::query()->where('path', $path)->value('entry_id'))->firstOrFail();
}

/** Every row of the content tables (inside the tenant context), for a before/after comparison. */
function cpSnapshot(int $siteId): string
{
    $tables = ['entries', 'entry_translations', 'entry_revisions', 'entry_taxonomies', 'routes', 'taxonomies', 'taxonomy_translations',
        'products', 'product_translations', 'product_images', 'product_taxonomies', 'menus', 'menu_items', 'menu_item_translations',
        'menu_assignments', 'site_settings', 'redirects', 'media', 'mediables', 'site_profiles', 'site_profile_translations'];

    return ContentPacks::in($siteId, function () use ($siteId, $tables): string {
        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = DB::table($table)->where('site_id', $siteId)->get()
                ->map(fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->sort()->values()->all();
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    });
}

/** A staff member saves a new title on a page through the real editor (which records a revision). */
function cpStaffEdit(int $siteId, string $path, string $title): void
{
    $owner = User::query()->where('email', 'owner@'.ContentPacks::HOST)->firstOrFail();

    ContentPacks::in($siteId, function () use ($owner, $path, $title): void {
        app(TeamContext::class)->forget();
        Livewire::actingAs($owner)
            ->test(Editor::class, ['ulid' => cpEntryAt($path)->ulid, 'locale' => 'vi'])
            ->set('title', $title)
            ->call('save')
            ->assertHasNoErrors();
    });
}

/** The decoded backup file the apply printed. @return array<string, mixed> */
function cpBackup(string $output): array
{
    expect(preg_match('/^Backup: (\S+)$/m', $output, $m))->toBe(1, $output);

    return json_decode((string) gzdecode((string) file_get_contents($m[1])), true, 512, JSON_THROW_ON_ERROR) + ['path' => $m[1]];
}

/** One item of a backup, by key. @param array<string, mixed> $backup @return array<string, mixed> */
function cpBackupItem(array $backup, string $key): array
{
    foreach ($backup['items'] as $item) {
        if ($item['key'] === $key) {
            return $item;
        }
    }
    throw new RuntimeException("No {$key} in the backup.");
}

function cpVersion2(string $folder): void
{
    ContentPacks::edit($folder, 'pack.json', fn (array $pack): array => ['version' => 2] + $pack);
}

it('shows what staff wrote next to what the pack would write: trimmed with -v, in full with -vv, writing nothing', function (): void {
    ContentPacks::load();
    $staff = 'Giới thiệu do nhân viên sửa, một tiêu đề dài hơn tám mươi ký tự để dòng tóm tắt phải cắt bớt ở giữa';
    cpStaffEdit($this->siteId, '/gioi-thieu', $staff);
    cpVersion2($this->folder);
    ContentPacks::item($this->folder, 'pages.json', 'page:/gioi-thieu', fn (array $item): array => ['title' => ['vi' => 'Giới thiệu bản 2']] + $item);

    [, $short] = ContentPacks::run('demo-co', ['-v' => true]);
    [$exit, $full] = ContentPacks::run('demo-co', ['-vv' => true]);

    expect(ContentPacks::verdict($full, 'page:/gioi-thieu'))->toBe('skip:edited')
        ->and($exit)->toBe(0, $full)
        ->and($short)->toContain(' … ')->not->toContain($staff)
        ->and($full)->toContain(json_encode(['pack' => 'Giới thiệu bản 2', 'site' => $staff], JSON_UNESCAPED_UNICODE))
        ->and(ContentPacks::in($this->siteId, fn () => cpEntryAt('/gioi-thieu')->translationFor('vi')?->title))->toBe($staff);
});

it('keeps a staff edit byte for byte, updates the rest, and overwrites the edit only when forced (backup first)', function (): void {
    ContentPacks::load();
    cpStaffEdit($this->siteId, '/gioi-thieu', 'Giới thiệu do nhân viên sửa');
    $staff = ContentPacks::in($this->siteId, fn (): array => cpEntryAt('/gioi-thieu')->translationFor('vi')?->attributesToArray() ?? []);

    cpVersion2($this->folder);
    ContentPacks::item($this->folder, 'pages.json', 'page:/gioi-thieu', fn (array $item): array => ['title' => ['vi' => 'Giới thiệu bản 2']] + $item);
    ContentPacks::item($this->folder, 'pages.json', 'page:/', fn (array $item): array => ['title' => ['vi' => 'Trang chủ bản 2']] + $item);

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'page:/gioi-thieu'))->toBe('skip:edited')
        ->and(ContentPacks::verdict($output, 'page:/'))->toBe('update');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);
    ContentPacks::in($this->siteId, fn () => expect(cpEntryAt('/gioi-thieu')->translationFor('vi')?->attributesToArray())->toBe($staff)
        ->and(cpEntryAt('/')->translationFor('vi')?->title)->toBe('Trang chủ bản 2'));

    [, $output] = ContentPacks::run('demo-co', ['--force-item' => ['page:/gioi-thieu']]);
    expect(ContentPacks::verdict($output, 'page:/gioi-thieu'))->toBe('update (forced)');

    [$exit, $output] = ContentPacks::load('demo-co', ['page:/gioi-thieu']);
    expect($exit)->toBe(0, $output);
    $item = cpBackupItem(cpBackup($output), 'page:/gioi-thieu');

    ContentPacks::in($this->siteId, function () use ($item): void {
        $page = cpEntryAt('/gioi-thieu');
        expect($page->translationFor('vi')?->title)->toBe('Giới thiệu bản 2')
            ->and($item['forced'])->toBeTrue()
            ->and($item['rows']['translations'][0]['title'])->toBe('Giới thiệu do nhân viên sửa')
            ->and(EntryRevision::query()->where('entry_id', $page->id)->whereNull('created_by')->exists())->toBeTrue();
    });
});

it('does not bring back what staff deleted unless the item is forced, and then restores it', function (): void {
    ContentPacks::load();
    $id = ContentPacks::in($this->siteId, function (): int {
        $entry = Entry::query()->whereKey(EntryTranslation::query()->where('slug', 'thiet-ke-mau')->value('entry_id'))->firstOrFail();
        $entry->delete();

        return $entry->id;
    });

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'post:thiet-ke-mau'))->toBe('skip:deleted');
    ContentPacks::load();
    ContentPacks::in($this->siteId, fn () => expect(Entry::query()->whereKey($id)->exists())->toBeFalse()
        ->and(EntryTranslation::query()->where('slug', 'thiet-ke-mau')->count())->toBe(1));

    [$exit, $output] = ContentPacks::load('demo-co', ['post:thiet-ke-mau']);
    expect($exit)->toBe(0, $output);
    ContentPacks::in($this->siteId, fn () => expect(Entry::query()->whereKey($id)->value('status'))->toBe('published')
        ->and(EntryTranslation::query()->where('slug', 'thiet-ke-mau')->count())->toBe(1));
});

it('keeps the category totals through a load, a staff trash and a forced restore', function (): void {
    $dichVu = fn (): int => ContentPacks::in($this->siteId, fn (): int => (int) Taxonomy::query()
        ->whereKey(TaxonomyTranslation::query()->where('slug', 'dich-vu')->value('taxonomy_id'))->value('items_count'));

    ContentPacks::load();
    expect($dichVu())->toBe(1)->and(categoryCountDrift($this->siteId))->toBe([]);

    ContentPacks::in($this->siteId, fn () => Entry::query()->whereKey(EntryTranslation::query()->where('slug', 'thiet-ke-mau')->value('entry_id'))->firstOrFail()->delete());
    expect($dichVu())->toBe(0);

    [$exit, $output] = ContentPacks::load('demo-co', ['post:thiet-ke-mau']);
    expect($exit)->toBe(0, $output)
        ->and($dichVu())->toBe(1)
        ->and(categoryCountDrift($this->siteId))->toBe([]);
});

it('leaves a page with a pending draft alone', function (): void {
    ContentPacks::load();
    ContentPacks::in($this->siteId, fn () => EntryDraft::query()->create([
        'entry_id' => cpEntryAt('/gioi-thieu')->id,
        'content' => ['v' => 1, 'shared' => [], 'locales' => ['vi' => ['title' => 'Bản nháp đang chờ']]],
    ]));
    ContentPacks::item($this->folder, 'pages.json', 'page:/gioi-thieu', fn (array $item): array => ['title' => ['vi' => 'Giới thiệu bản 2']] + $item);

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'page:/gioi-thieu'))->toBe('skip:draft-pending');

    ContentPacks::load();
    ContentPacks::in($this->siteId, fn () => expect(cpEntryAt('/gioi-thieu')->translationFor('vi')?->title)->toBe('Giới thiệu'));
});

it('adopts a record made before any load, unless a person edited it', function (bool $edited, string $verdict, string $title): void {
    ContentPacks::in($this->siteId, function () use ($edited): void {
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $translation = $page->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu cũ', 'slug' => 'gioi-thieu', 'status' => 'published', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/gioi-thieu', 'entry_id' => $page->id]);
        if ($edited) {
            $owner = User::query()->where('email', 'owner@'.ContentPacks::HOST)->firstOrFail();
            app(RecordEntryRevision::class)->forLocale($page, $translation, RevisionKind::Edit, $owner);
        }
    });

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'page:/gioi-thieu'))->toBe($verdict);

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);
    ContentPacks::in($this->siteId, fn () => expect(cpEntryAt('/gioi-thieu')->translationFor('vi')?->title)->toBe($title)
        ->and(SiteRoute::query()->where('path', '/gioi-thieu')->count())->toBe(1));
})->with([
    'no human revision' => [false, 'adopt', 'Giới thiệu'],
    'an edit by a person' => [true, 'skip:edited', 'Giới thiệu cũ'],
]);

it('never touches another site with the same slugs and paths, under the strict tenancy assertion', function (): void {
    config(['tenancy.assert' => 'strict']);
    $other = StorefrontCards::site('Other', 'other.platform.test');
    ContentPacks::in($other, function (): void {
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu B', 'slug' => 'gioi-thieu', 'status' => 'published', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/gioi-thieu', 'entry_id' => $page->id]);
        $post = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => now()]);
        $post->translations()->create(['locale' => 'vi', 'title' => 'Ra mắt B', 'slug' => 'ra-mat-website', 'status' => 'published', 'body' => ['blocks' => []]]);
        StorefrontCards::media('about');
    });
    $before = cpSnapshot($other);

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)
        ->and(cpSnapshot($other))->toBe($before)
        ->and(ContentPacks::in($other, fn (): ?array => Ledger::read('demo-co')))->toBeNull()
        ->and(ContentPacks::in($this->siteId, fn (): ?array => Ledger::read('demo-co')))->not->toBeNull();
});

it('rolls the whole load back when an item fails half way, ledger included', function (): void {
    ContentPacks::load();
    cpVersion2($this->folder);
    ContentPacks::item($this->folder, 'settings.json', 'setting:theme.tokens', fn (array $item): array => ['value' => ['primary' => '#1d4ed8', 'ink' => '#111827']] + $item);
    ContentPacks::item($this->folder, 'taxonomies.json', 'tax:post_cat/dich-vu', fn (array $item): array => ['name' => ['vi' => 'Dịch vụ bản 2']] + $item);
    ContentPacks::edit($this->folder, 'posts.json', fn (array $data): array => ['items' => [...$data['items'], [
        'key' => 'post:bai-moi', 'verified' => true, 'status' => 'published', 'categories' => ['tax:post_cat/tin-tuc'],
        'title' => ['vi' => 'Bài mới'], 'body' => ['vi' => [['type' => 'richtext', 'props' => ['paragraphs' => ['Bài mới.']]]]],
    ]]]);
    [, $dry] = ContentPacks::run();
    $before = cpSnapshot($this->siteId);
    $ledger = ContentPacks::in($this->siteId, fn (): ?array => Ledger::read('demo-co'));

    // The settings and the category are written by then; the new post's text is where it breaks.
    EntryTranslation::creating(function (EntryTranslation $translation): void {
        if ($translation->title === 'Bài mới') {
            throw new RuntimeException('the disk is full');
        }
    });
    [$exit, $output] = ContentPacks::run('demo-co', ['--apply' => true, '--plan' => ContentPacks::fingerprint($dry)]);

    expect($exit)->toBe(1)->and($output)->toContain('rolled back')->toContain('the disk is full')
        ->and(cpSnapshot($this->siteId))->toBe($before)
        ->and(ContentPacks::in($this->siteId, fn (): ?array => Ledger::read('demo-co')))->toBe($ledger);
});

it('backs up the full before-rows of every item it changes, readable only by its owner', function (): void {
    ContentPacks::load();
    cpVersion2($this->folder);
    ContentPacks::item($this->folder, 'pages.json', 'page:/', fn (array $item): array => ['title' => ['vi' => 'Trang chủ bản 2']] + $item);
    ContentPacks::item($this->folder, 'settings.json', 'setting:theme.tokens', fn (array $item): array => ['value' => ['primary' => '#1d4ed8', 'ink' => '#111827']] + $item);
    ContentPacks::item($this->folder, 'taxonomies.json', 'tax:post_cat/dich-vu', fn (array $item): array => ['name' => ['vi' => 'Dịch vụ bản 2']] + $item);

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);
    $backup = cpBackup($output);

    expect(fileperms($backup['path']) & 0777)->toBe(0600)
        ->and($backup['pack'])->toBe(['code' => 'demo-co', 'version' => 2])
        ->and($backup['previous_ledger']['pack_version'])->toBe(1)
        ->and(array_column($backup['items'], 'key'))->toEqualCanonicalizing(['page:/', 'setting:theme.tokens', 'tax:post_cat/dich-vu']);

    $page = cpBackupItem($backup, 'page:/')['rows'];
    expect($page['entry']['type'])->toBe('page')
        ->and($page['translations'][0]['title'])->toBe('Trang chủ')
        ->and($page['routes'][0]['path'])->toBe('/')
        ->and(cpBackupItem($backup, 'setting:theme.tokens')['rows']['setting']['value'])->toEqual(['primary' => '#0f766e', 'ink' => '#111827'])
        ->and(cpBackupItem($backup, 'tax:post_cat/dich-vu')['rows']['translations'][0]['name'])->toBe('Dịch vụ');
});

it('moves the posts of a renamed category, with a 301 from every old path', function (): void {
    ContentPacks::load();
    cpVersion2($this->folder);
    ContentPacks::item($this->folder, 'taxonomies.json', 'tax:post_cat/tin-tuc/cap-nhat', fn (array $item): array => ['key' => 'tax:post_cat/tin-tuc/ban-tin', 'was' => ['cap-nhat'], 'name' => ['vi' => 'Bản tin']] + $item);
    ContentPacks::item($this->folder, 'posts.json', 'post:ra-mat-website', fn (array $item): array => ['categories' => ['tax:post_cat/tin-tuc/ban-tin', 'tax:post_cat/tin-tuc']] + $item);

    [, $dry] = ContentPacks::run();
    expect(ContentPacks::verdict($dry, 'tax:post_cat/tin-tuc/ban-tin'))->toBe('update')
        ->and($dry)->toContain('URL /cap-nhat → /ban-tin (301 + 1 posts)')
        ->and($dry)->not->toContain('ORPHANS');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);
    expect(array_keys(ContentPacks::in($this->siteId, fn (): ?array => Ledger::read('demo-co'))['items'] ?? []))
        ->toContain('tax:post_cat/tin-tuc/ban-tin')->not->toContain('tax:post_cat/tin-tuc/cap-nhat');

    ContentPacks::in($this->siteId, fn () => expect(SiteRoute::query()->where('path', '/ban-tin/ra-mat-website')->exists())->toBeTrue()
        ->and(TaxonomyTranslation::query()->where('slug', 'cap-nhat')->exists())->toBeFalse()
        ->and(TaxonomyTranslation::query()->where('slug', 'ban-tin')->count())->toBe(1));

    $this->get('http://'.ContentPacks::HOST.'/cap-nhat/ra-mat-website')->assertStatus(301)->assertRedirect('/ban-tin/ra-mat-website');
    $this->get('http://'.ContentPacks::HOST.'/cap-nhat')->assertStatus(301)->assertRedirect('/ban-tin');
    $this->get('http://'.ContentPacks::HOST.'/ban-tin/ra-mat-website')->assertOk();
});

it('reports a redirect over a live path as a conflict', function (): void {
    ContentPacks::load();
    ContentPacks::edit($this->folder, 'redirects.json', fn (array $data): array => ['items' => [...$data['items'], ['key' => 'redirect:vi:/gioi-thieu', 'to' => '/', 'status' => 301]]]);

    [$exit, $output] = ContentPacks::run();

    expect($exit)->toBe(1)->and(ContentPacks::verdict($output, 'redirect:vi:/gioi-thieu'))->toBe('conflict');
});

/** A published post staff filed primarily under a category, at the address the site composes for it. */
function cpStaffPost(int $siteId, string $slug, string $categorySlug): void
{
    ContentPacks::in($siteId, function () use ($slug, $categorySlug): void {
        $post = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => now()]);
        $post->translations()->create(['locale' => 'vi', 'title' => 'Bài của nhân viên', 'slug' => $slug, 'status' => 'published', 'body' => ['blocks' => []]]);
        $post->assignCategories([(int) TaxonomyTranslation::query()->where('slug', $categorySlug)->value('taxonomy_id')]);
        $post->unsetRelation('taxonomies');
        app(RouteSync::class)->sync($post, 'vi', $slug);
    });
}

/** v2 of the fixture: the news child category cap-nhat becomes ban-tin (its post follows the key). */
function cpRenameCapNhat(string $folder, array $extra = []): void
{
    cpVersion2($folder);
    ContentPacks::item($folder, 'taxonomies.json', 'tax:post_cat/tin-tuc/cap-nhat', fn (array $item): array => ['key' => 'tax:post_cat/tin-tuc/ban-tin', 'was' => ['cap-nhat'], 'name' => ['vi' => 'Bản tin']] + $extra + $item);
    ContentPacks::item($folder, 'posts.json', 'post:ra-mat-website', fn (array $item): array => ['categories' => ['tax:post_cat/tin-tuc/ban-tin', 'tax:post_cat/tin-tuc']] + $item);
}

/** Every SQL statement the callback issues. @return list<string> */
function cpQueries(callable $callback): array
{
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $callback();

    return $queries;
}

it('never follows a stored redirect off the site', function (string $to): void {
    ContentPacks::in($this->siteId, fn () => Redirect::query()->create(['locale' => 'vi', 'from_path' => '/ra-ngoai', 'to_path' => $to, 'status_code' => 301]));

    $this->get('http://'.ContentPacks::HOST.'/ra-ngoai')->assertNotFound();
})->with(['//evil.example/x', '/\evil.example', 'https://evil.example/x']);

it('backs up every row a category rename changes, staff posts under it included, and a post filed in between changes the plan', function (): void {
    $about = ContentPacks::in($this->siteId, fn () => StorefrontCards::media('about'));
    ContentPacks::load();
    cpStaffPost($this->siteId, 'bai-cua-nhan-vien', 'cap-nhat');
    ContentPacks::in($this->siteId, fn () => Redirect::query()->create(['locale' => 'vi', 'from_path' => '/bai-cu', 'to_path' => '/cap-nhat/bai-cua-nhan-vien', 'status_code' => 301]));
    cpRenameCapNhat($this->folder);
    // The home page drops its image, so the media usage index loses the page's row.
    ContentPacks::item($this->folder, 'pages.json', 'page:/', function (array $item): array {
        unset($item['body']['vi'][0]['props']['image_url']);

        return $item;
    });

    [, $dry] = ContentPacks::run();
    cpStaffPost($this->siteId, 'bai-nop-sau', 'cap-nhat');
    [$exit, $output] = ContentPacks::run('demo-co', ['--apply' => true, '--plan' => ContentPacks::fingerprint($dry)]);
    expect($exit)->toBe(1)->and($output)->toContain('plan changed, run the dry run again');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);
    $backup = cpBackup($output);
    $category = cpBackupItem($backup, 'tax:post_cat/tin-tuc/ban-tin')['rows'];
    $redirect = collect($backup['redirects'])->firstWhere('from_path', '/bai-cu');

    expect(array_column($category['branch_routes'], 'path'))->toContain('/cap-nhat/bai-cua-nhan-vien', '/cap-nhat/bai-nop-sau', '/cap-nhat/ra-mat-website')
        ->and($redirect['to_path'] ?? null)->toBe('/cap-nhat/bai-cua-nhan-vien')
        ->and(array_column(cpBackupItem($backup, 'page:/')['rows']['mediables'], 'media_id'))->toBe([$about->id]);

    // …and those rows are indeed what the load changed.
    ContentPacks::in($this->siteId, fn () => expect(Redirect::query()->where('from_path', '/bai-cu')->value('to_path'))->toBe('/ban-tin/bai-cua-nhan-vien')
        ->and(SiteRoute::query()->where('path', '/ban-tin/bai-nop-sau')->exists())->toBeTrue()
        ->and(DB::table('mediables')->where('media_id', $about->id)->exists())->toBeFalse());
});

it('keeps protecting a staff edit on a renamed category the load skipped, run after run', function (): void {
    ContentPacks::load();
    ContentPacks::in($this->siteId, fn () => TaxonomyTranslation::query()->where('slug', 'cap-nhat')->update(['name' => 'Cập nhật của nhân viên']));
    cpRenameCapNhat($this->folder);

    [, $dry] = ContentPacks::run();
    expect(ContentPacks::verdict($dry, 'tax:post_cat/tin-tuc/ban-tin'))->toBe('skip:edited');
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)
        ->and(array_keys(ContentPacks::in($this->siteId, fn (): ?array => Ledger::read('demo-co'))['items'] ?? []))->toContain('tax:post_cat/tin-tuc/ban-tin');

    [, $again] = ContentPacks::run();
    expect(ContentPacks::verdict($again, 'tax:post_cat/tin-tuc/ban-tin'))->toBe('skip:edited');
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);
    ContentPacks::in($this->siteId, fn () => expect(TaxonomyTranslation::query()->where('slug', 'cap-nhat')->value('name'))->toBe('Cập nhật của nhân viên'));
});

it('plans as a conflict every address the load would write that already belongs to something else', function (Closure $setup, string $key): void {
    $setup($this);

    [$exit, $output] = ContentPacks::run();

    expect($exit)->toBe(1, $output)->and(ContentPacks::verdict($output, $key))->toBe('conflict');
})->with([
    'a new post at a page\'s address' => [function ($test): void {
        ContentPacks::in($test->siteId, function (): void {
            $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
            $page->translations()->create(['locale' => 'vi', 'title' => 'Trang khác', 'slug' => 'bai-moi', 'status' => 'published', 'body' => ['blocks' => []]]);
            SiteRoute::query()->create(['locale' => 'vi', 'path' => '/tin-tuc/bai-moi', 'entry_id' => $page->id]);
        });
        ContentPacks::edit($test->folder, 'posts.json', fn (array $data): array => ['items' => [...$data['items'], [
            'key' => 'post:bai-moi', 'verified' => true, 'status' => 'published', 'categories' => ['tax:post_cat/tin-tuc'],
            'title' => ['vi' => 'Bài mới'], 'body' => ['vi' => [['type' => 'richtext', 'props' => ['paragraphs' => ['Bài mới.']]]]],
        ]]]);
    }, 'post:bai-moi'],
    'a new product at a page\'s address' => [function ($test): void {
        ContentPacks::in($test->siteId, function (): void {
            $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
            $page->translations()->create(['locale' => 'vi', 'title' => 'Máy cũ', 'slug' => 'may-mau-a', 'status' => 'published', 'body' => ['blocks' => []]]);
            SiteRoute::query()->create(['locale' => 'vi', 'path' => '/may-mau-a', 'entry_id' => $page->id]);
        });
    }, 'product:may-mau-a'],
    'a post at the address of a category of the same pack' => [function ($test): void {
        ContentPacks::edit($test->folder, 'posts.json', fn (array $data): array => ['items' => [...$data['items'], [
            'key' => 'post:dich-vu', 'verified' => true, 'status' => 'published', 'categories' => ['tax:post_cat/san-pham'],
            'title' => ['vi' => 'Dịch vụ'], 'body' => ['vi' => [['type' => 'richtext', 'props' => ['paragraphs' => ['Dịch vụ.']]]]],
        ]]]);
    }, 'post:dich-vu'],
    'a category\'s address in a second language' => [function ($test): void {
        ContentPacks::in($test->siteId, function (): void {
            SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true]);
            $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
            $page->translations()->create(['locale' => 'en', 'title' => 'Services', 'slug' => 'services', 'status' => 'published', 'body' => ['blocks' => []]]);
            SiteRoute::query()->create(['locale' => 'en', 'path' => '/services', 'entry_id' => $page->id]);
        });
        ContentPacks::edit($test->folder, 'pack.json', fn (array $pack): array => ['locales' => ['vi', 'en']] + $pack);
        ContentPacks::item($test->folder, 'taxonomies.json', 'tax:post_cat/dich-vu', fn (array $item): array => ['name' => ['vi' => 'Dịch vụ', 'en' => 'Services'], 'slug' => ['en' => 'services']] + $item);
    }, 'tax:post_cat/dich-vu'],
    'a staff post a category rename would move onto a taken address' => [function ($test): void {
        ContentPacks::load();
        cpStaffPost($test->siteId, 'bai-cua-nhan-vien', 'cap-nhat');
        ContentPacks::in($test->siteId, function (): void {
            $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
            $page->translations()->create(['locale' => 'vi', 'title' => 'Trang khác', 'slug' => 'bai-cua-nhan-vien', 'status' => 'published', 'body' => ['blocks' => []]]);
            SiteRoute::query()->create(['locale' => 'vi', 'path' => '/ban-tin/bai-cua-nhan-vien', 'entry_id' => $page->id]);
        });
        cpRenameCapNhat($test->folder);
    }, 'tax:post_cat/tin-tuc/ban-tin'],
]);

/** The last plan's addresses: [planned paths, carried moves], planned in the site's tenant context. @return array{0: array<string, array<string, string>>, 1: array<string, list<array<string, mixed>>>} */
function cpPlannedAddresses(int $siteId): array
{
    return ContentPacks::in($siteId, function (): array {
        $planner = app(Planner::class);
        $planner->plan(app(PackReader::class)->read('demo-co'), (string) Site::query()->value('ulid'), 'Demo', ContentPacks::HOST);

        return [$planner->plannedPaths(), $planner->carriedMoves()];
    });
}

/**
 * A published post or product staff filed primarily under a category, routed in every language it
 * has a slug in. Returns its id.
 *
 * @param  array<string, string>  $slugs  locale → slug
 */
function cpStaffItem(int $siteId, string $kind, array $slugs, string $categorySlug): int
{
    return ContentPacks::in($siteId, function () use ($kind, $slugs, $categorySlug): int {
        $category = (int) TaxonomyTranslation::query()->where('slug', $categorySlug)->value('taxonomy_id');
        if ($kind === 'product') {
            $product = Product::query()->create(['status' => 'published', 'published_at' => now()]);
            foreach ($slugs as $locale => $slug) {
                $product->translations()->create(['locale' => $locale, 'name' => $slug, 'slug' => $slug]);
            }
            $product->assignCategories([$category]);
            $product->unsetRelation('taxonomies');
            foreach ($slugs as $locale => $slug) {
                app(RouteSync::class)->syncProduct($product->id, $product->primaryCategory(), $locale, $slug);
            }

            return $product->id;
        }
        $post = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => now()]);
        foreach ($slugs as $locale => $slug) {
            $post->translations()->create(['locale' => $locale, 'title' => $slug, 'slug' => $slug, 'status' => 'published', 'body' => ['blocks' => []]]);
        }
        $post->assignCategories([$category]);
        $post->unsetRelation('taxonomies');
        foreach ($slugs as $locale => $slug) {
            app(RouteSync::class)->sync($post, $locale, $slug);
        }

        return $post->id;
    });
}

/** Staff gives a category a name and an address in another language. */
function cpStaffCategoryIn(int $siteId, string $slug, string $locale, string $localized): void
{
    ContentPacks::in($siteId, function () use ($slug, $locale, $localized): void {
        $taxonomy = Taxonomy::query()->findOrFail(TaxonomyTranslation::query()->where('slug', $slug)->value('taxonomy_id'));
        $taxonomy->translations()->create(['locale' => $locale, 'name' => $localized, 'slug' => $localized]);
        app(RouteSync::class)->syncTaxonomy($taxonomy, $locale, $localized);
    });
}

function cpEnableEnglish(int $siteId): void
{
    ContentPacks::in($siteId, fn () => SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true]));
}

/** Every route of these posts and products: "column:id:locale" → path. @param array<string, list<int>> $ids column → ids @return array<string, string> */
function cpRoutesOf(int $siteId, array $ids): array
{
    return ContentPacks::in($siteId, function () use ($ids): array {
        $routes = [];
        foreach ($ids as $column => $list) {
            foreach (SiteRoute::query()->whereIn($column, $list)->get([$column, 'locale', 'path']) as $route) {
                $routes["{$column}:{$route->{$column}}:{$route->locale}"] = $route->path;
            }
        }
        ksort($routes);

        return $routes;
    });
}

it('plans the same addresses the platform then writes, under every permalink preset', function (array $posts, array $products, array $moving): void {
    ContentPacks::in($this->siteId, function () use ($posts, $products): void {
        SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'posts'], ['value' => $posts]);
        SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'products'], ['value' => $products]);
    });
    // The site has a language the pack leaves out.
    cpEnableEnglish($this->siteId);
    [$planned] = cpPlannedAddresses($this->siteId);

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)->and($planned)->toHaveKeys(['post:ra-mat-website', 'product:may-mau-a', 'tax:post_cat/tin-tuc/cap-nhat']);
    $routed = function (array $planned): void {
        ContentPacks::in($this->siteId, function () use ($planned): void {
            foreach ($planned as $key => $paths) {
                foreach ($paths as $locale => $path) {
                    expect(SiteRoute::query()->where('locale', $locale)->where('path', $path)->exists())->toBeTrue("{$key} was planned at {$path}");
                }
            }
        });
    };
    $routed($planned);

    // Staff files posts and a product under the categories v2 renames, in both languages, one post
    // in a category of their own under the renamed one (its address carries the renamed parent).
    cpStaffCategoryIn($this->siteId, 'cap-nhat', 'en', 'updates');
    cpStaffCategoryIn($this->siteId, 'san-pham', 'en', 'products');
    ContentPacks::in($this->siteId, fn () => app(CreateTaxonomy::class)->handle(
        'post_cat',
        Taxonomy::query()->findOrFail(TaxonomyTranslation::query()->where('slug', 'cap-nhat')->value('taxonomy_id')),
        new TaxonomyData(names: ['vi' => 'Tháng một', 'en' => 'January']),
    ));
    $ids = [
        'entry_id' => [
            cpStaffItem($this->siteId, 'post', ['vi' => 'bai-cua-nhan-vien', 'en' => 'staff-post'], 'cap-nhat'),
            cpStaffItem($this->siteId, 'post', ['vi' => 'bai-thang-mot', 'en' => 'january-post'], 'thang-mot'),
        ],
        'product_id' => [cpStaffItem($this->siteId, 'product', ['vi' => 'may-cua-nhan-vien', 'en' => 'staff-machine'], 'san-pham')],
    ];

    // v2 renames both categories.
    cpRenameCapNhat($this->folder);
    ContentPacks::item($this->folder, 'taxonomies.json', 'tax:post_cat/san-pham', fn (array $item): array => ['key' => 'tax:post_cat/may-moc', 'was' => ['san-pham'], 'name' => ['vi' => 'Máy móc']] + $item);
    foreach (['product:may-mau-a', 'product:may-mau-b'] as $key) {
        ContentPacks::item($this->folder, 'products.json', $key, fn (array $item): array => ['categories' => ['tax:post_cat/may-moc']] + $item);
    }

    [$planned, $carried] = cpPlannedAddresses($this->siteId);
    foreach ($carried as $moves) {
        foreach ($moves as $move) {
            $ids[$move['column']][] = $move['id'];   // pack posts and products v2 leaves as they are move too
        }
    }
    $before = cpRoutesOf($this->siteId, $ids);
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)->and(array_keys($carried))->toEqualCanonicalizing($moving);
    $routed($planned);

    // Every route of what the categories carry is where the plan said it moves to, and nowhere else.
    $expected = $before;
    foreach ($carried as $moves) {
        foreach ($moves as $move) {
            $expected["{$move['column']}:{$move['id']}:{$move['locale']}"] = $move['to'];
        }
    }
    expect(cpRoutesOf($this->siteId, $ids))->toBe($expected);
})->with([
    'flat' => [['pattern' => 'flat'], ['pattern' => 'flat'], []],
    'category' => [['pattern' => 'category'], ['pattern' => 'category'], ['tax:post_cat/tin-tuc/ban-tin', 'tax:post_cat/may-moc']],
    'category path' => [['pattern' => 'category_path'], ['pattern' => 'category_path'], ['tax:post_cat/tin-tuc/ban-tin', 'tax:post_cat/may-moc']],
    'base' => [['pattern' => 'base', 'base' => 'bai-viet'], ['pattern' => 'base', 'base' => 'may'], []],
]);

it('locks the rows it writes while it re-plans for --apply, and takes no lock on a dry run', function (): void {
    ContentPacks::load();
    cpVersion2($this->folder);
    ContentPacks::item($this->folder, 'pages.json', 'page:/', fn (array $item): array => ['title' => ['vi' => 'Trang chủ bản 2']] + $item);

    $dry = cpQueries(function () use (&$output): void {
        [, $output] = ContentPacks::run();
    });
    $apply = cpQueries(fn () => ContentPacks::run('demo-co', ['--apply' => true, '--plan' => ContentPacks::fingerprint($output)]));

    $locked = fn (array $queries, string $mode): array => array_values(array_unique(array_map(
        fn (string $sql): string => preg_match('/\bfrom "([a-z_]+)"/', $sql, $m) === 1 ? $m[1] : '?',
        array_filter($queries, fn (string $sql): bool => preg_match('/\bfor '.$mode.'\b/i', $sql) === 1),
    )));
    // FOR NO KEY UPDATE, never FOR UPDATE: a staff save filing a post under a category takes FOR KEY
    // SHARE on it (the foreign-key check), which FOR UPDATE blocks — the two would deadlock.
    expect($locked($dry, '(no key )?update'))->toBe([])
        ->and($locked($apply, 'update'))->toBe([])
        ->and($locked($apply, 'no key update'))->toContain('entries', 'entry_translations', 'products', 'product_translations', 'taxonomies', 'taxonomy_translations',
            'menus', 'menu_items', 'site_settings', 'site_profiles', 'site_profile_translations', 'redirects');
});

it('relinks every permalink preset a category write affects when its slug and parent change together', function (): void {
    ContentPacks::in($this->siteId, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'products'], ['value' => ['pattern' => 'category']]));
    ContentPacks::load();

    // The pack renames the category (so the Planner carries its products along); a pack cannot give
    // a category a new parent (its parent is the chain of its key, and it is matched under that
    // parent), so the move is added to the Applier's plan line.
    ContentPacks::item($this->folder, 'taxonomies.json', 'tax:post_cat/san-pham', fn (array $item): array => ['key' => 'tax:post_cat/may-moc', 'was' => ['san-pham'], 'name' => ['vi' => 'Máy móc']] + $item);
    ContentPacks::edit($this->folder, 'products.json', fn (array $data): array => ['items' => array_map(
        fn (array $item): array => ['categories' => array_map(fn (string $key): string => $key === 'tax:post_cat/san-pham' ? 'tax:post_cat/may-moc' : $key, $item['categories'])] + $item,
        $data['items'],
    )] + $data);
    ContentPacks::in($this->siteId, function (): void {
        $planner = app(Planner::class);
        $plan = $planner->plan(app(PackReader::class)->read('demo-co'), (string) Site::query()->value('ulid'), 'Demo', ContentPacks::HOST);
        $items = array_map(function (PlanItem $planned): PlanItem {
            if ($planned->item->key !== 'tax:post_cat/may-moc') {
                return $planned;
            }
            $desired = ['parent' => 'tax:post_cat/tin-tuc'] + $planned->desired;

            return new PlanItem($planned->item, PlanItem::UPDATE, $planned->current, $desired, null, $planned->recordId);
        }, $plan->items);
        app(Applier::class)->apply(new Plan($plan->pack, $plan->siteUlid, $plan->siteName, $plan->host, $items, [], [], [], $plan->ledger, $plan->media), $planner);
    });

    ContentPacks::in($this->siteId, fn () => expect(SiteRoute::query()->whereNotNull('product_id')->where('path', 'like', '%/may-mau-a')->value('path'))->toBe('/may-moc/may-mau-a')
        ->and(Redirect::query()->where('from_path', '/san-pham/may-mau-a')->value('to_path'))->toBe('/may-moc/may-mau-a'));
});

it('carries a staff post along in a site language the pack leaves out: backed up, and filing one there in between changes the plan', function (): void {
    cpEnableEnglish($this->siteId);
    ContentPacks::load();
    cpStaffCategoryIn($this->siteId, 'cap-nhat', 'en', 'updates');
    cpStaffItem($this->siteId, 'post', ['vi' => 'bai-cua-nhan-vien', 'en' => 'staff-post'], 'cap-nhat');
    cpRenameCapNhat($this->folder);

    [, $dry] = ContentPacks::run();
    cpStaffItem($this->siteId, 'post', ['en' => 'late-post'], 'cap-nhat');
    [$exit, $output] = ContentPacks::run('demo-co', ['--apply' => true, '--plan' => ContentPacks::fingerprint($dry)]);
    expect($exit)->toBe(1)->and($output)->toContain('plan changed, run the dry run again');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);
    $branch = collect(cpBackupItem(cpBackup($output), 'tax:post_cat/tin-tuc/ban-tin')['rows']['branch_routes'])
        ->map(fn (array $route): string => $route['locale'].':'.$route['path'])->all();

    expect($branch)->toContain('vi:/cap-nhat/bai-cua-nhan-vien', 'en:/updates/staff-post', 'en:/updates/late-post');
    ContentPacks::in($this->siteId, fn () => expect(SiteRoute::query()->where('locale', 'en')->where('path', '/updates/staff-post')->exists())->toBeTrue()
        ->and(SiteRoute::query()->where('locale', 'vi')->where('path', '/ban-tin/bai-cua-nhan-vien')->exists())->toBeTrue());
});

it('backs up the redirect a carried post\'s new address replaces', function (): void {
    ContentPacks::load();
    cpStaffPost($this->siteId, 'bai-cua-nhan-vien', 'cap-nhat');
    ContentPacks::in($this->siteId, fn () => Redirect::query()->create(['locale' => 'vi', 'from_path' => '/ban-tin/bai-cua-nhan-vien', 'to_path' => '/trang-cu', 'status_code' => 301]));
    cpRenameCapNhat($this->folder);

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);
    $redirect = collect(cpBackup($output)['redirects'])->firstWhere('from_path', '/ban-tin/bai-cua-nhan-vien');

    // RouteSync drops it — the address is live content now — so the backup is the only copy.
    expect($redirect['to_path'] ?? null)->toBe('/trang-cu');
    ContentPacks::in($this->siteId, fn () => expect(Redirect::query()->where('from_path', '/ban-tin/bai-cua-nhan-vien')->exists())->toBeFalse()
        ->and(SiteRoute::query()->where('path', '/ban-tin/bai-cua-nhan-vien')->exists())->toBeTrue());
});

it('moves the posts of a category renamed in its second language only, with a 301', function (): void {
    cpEnableEnglish($this->siteId);
    ContentPacks::edit($this->folder, 'pack.json', fn (array $pack): array => ['locales' => ['vi', 'en']] + $pack);
    ContentPacks::item($this->folder, 'taxonomies.json', 'tax:post_cat/tin-tuc/cap-nhat', fn (array $item): array => ['name' => ['vi' => 'Cập nhật', 'en' => 'Updates'], 'slug' => ['en' => 'updates']] + $item);
    ContentPacks::item($this->folder, 'posts.json', 'post:ra-mat-website', fn (array $item): array => ['title' => ['vi' => 'Ra mắt website', 'en' => 'Website launch'], 'slug' => ['en' => 'website-launch']] + $item);
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);
    ContentPacks::in($this->siteId, fn () => expect(SiteRoute::query()->where('locale', 'en')->where('path', '/updates/website-launch')->exists())->toBeTrue());

    cpVersion2($this->folder);
    ContentPacks::item($this->folder, 'taxonomies.json', 'tax:post_cat/tin-tuc/cap-nhat', fn (array $item): array => ['name' => ['vi' => 'Cập nhật', 'en' => 'News'], 'slug' => ['en' => 'news']] + $item);
    [, $dry] = ContentPacks::run();
    expect(ContentPacks::verdict($dry, 'tax:post_cat/tin-tuc/cap-nhat'))->toBe('update')
        ->and(ContentPacks::verdict($dry, 'post:ra-mat-website'))->toBe('unchanged');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);
    ContentPacks::in($this->siteId, fn () => expect(SiteRoute::query()->where('locale', 'en')->whereNotNull('entry_id')->where('path', 'like', '%/website-launch')->value('path'))->toBe('/news/website-launch')
        ->and(Redirect::query()->where('locale', 'en')->where('from_path', '/updates/website-launch')->value('to_path'))->toBe('/news/website-launch')
        ->and(SiteRoute::query()->where('locale', 'vi')->where('path', '/cap-nhat/ra-mat-website')->exists())->toBeTrue());
});

it('stops the whole load when a post is filed under a moving category after the locked re-plan', function (): void {
    ContentPacks::load();
    cpStaffPost($this->siteId, 'bai-cua-nhan-vien', 'cap-nhat');
    cpRenameCapNhat($this->folder);
    [, $dry] = ContentPacks::run();
    $before = cpSnapshot($this->siteId);

    // Staff files a post under the category after the re-plan and before the relink: simulated at
    // the moment the load renames the category, inside the load's own transaction.
    $filed = false;
    TaxonomyTranslation::updated(function (TaxonomyTranslation $translation) use (&$filed): void {
        if ($filed || $translation->slug !== 'ban-tin') {
            return;
        }
        $filed = true;
        $post = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => now()]);
        $post->translations()->create(['locale' => 'vi', 'title' => 'Bài nộp giữa chừng', 'slug' => 'bai-nop-giua-chung', 'status' => 'published', 'body' => ['blocks' => []]]);
        $post->assignCategories([(int) $translation->taxonomy_id]);
        $post->unsetRelation('taxonomies');
        app(RouteSync::class)->sync($post, 'vi', 'bai-nop-giua-chung');
    });
    [$exit, $output] = ContentPacks::run('demo-co', ['--apply' => true, '--plan' => ContentPacks::fingerprint($dry)]);

    expect($filed)->toBeTrue()
        ->and($exit)->toBe(1)
        ->and($output)->toContain('plan changed, run the dry run again')->toContain('tax:post_cat/tin-tuc/ban-tin')
        ->and(cpSnapshot($this->siteId))->toBe($before);
});

it('replaces a menu with its trash on a forced re-apply, the trashed items kept in the backup', function (): void {
    ContentPacks::load();
    $editor = menuEditor($this->siteId);
    $trashed = ContentPacks::in($this->siteId, function () use ($editor): int {
        $menuId = MenuAssignment::query()->where('region_key', 'primary')->where('locale', 'vi')->value('menu_id');
        $item = MenuItem::query()->where('menu_id', $menuId)->whereNull('parent_id')->orderBy('position')->firstOrFail();
        app(DeleteMenuItem::class)->handle($editor, $item);

        return $item->id;
    });

    [$exit, $output] = ContentPacks::load('demo-co', ['menu:primary']);
    expect($exit)->toBe(0, $output);

    $backup = collect(cpBackupItem(cpBackup($output), 'menu:primary')['rows']['items'])->firstWhere('id', $trashed);
    expect($backup['deleted_at'] ?? null)->not->toBeNull();

    ContentPacks::in($this->siteId, function (): void {
        $menuId = MenuAssignment::query()->where('region_key', 'primary')->where('locale', 'vi')->value('menu_id');
        expect(MenuItem::onlyTrashed()->count())->toBe(0)
            ->and(MenuItem::query()->where('menu_id', $menuId)->whereNull('parent_id')->count())->toBe(5);
    });
});

it('backs up the follow row a pack menu replaces, and leaves one target on the row', function (): void {
    ContentPacks::load();
    ContentPacks::in($this->siteId, fn () => MenuAssignment::query()->where('region_key', 'primary')->where('locale', 'vi')
        ->update(['menu_id' => null, 'same_as_region' => 'topbar']));

    [$exit, $output] = ContentPacks::load('demo-co', ['menu:primary']);
    expect($exit)->toBe(0, $output);

    $rows = cpBackupItem(cpBackup($output), 'menu:primary')['rows'];
    expect(collect($rows['assignments'])->firstWhere('region_key', 'primary'))->toMatchArray(['locale' => 'vi', 'menu_id' => null, 'same_as_region' => 'topbar']);

    ContentPacks::in($this->siteId, function (): void {
        $row = MenuAssignment::query()->where('region_key', 'primary')->where('locale', 'vi')->firstOrFail();
        expect($row->menu_id)->not->toBeNull()->and($row->same_as_region)->toBeNull();
    });
});
