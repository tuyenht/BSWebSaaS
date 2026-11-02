<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\ContentPack\Ledger;
use App\Modules\Rbac\Context\TeamContext;
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
    $this->siteId = StorefrontCards::site('Demo', ContentPacks::HOST);
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
    // An unrelated page already owns the path the new post would take.
    ContentPacks::in($this->siteId, function (): void {
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Trang khác', 'slug' => 'bai-moi', 'status' => 'published', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/tin-tuc/bai-moi', 'entry_id' => $page->id]);
    });
    cpVersion2($this->folder);
    ContentPacks::item($this->folder, 'settings.json', 'setting:theme.tokens', fn (array $item): array => ['value' => ['primary' => '#1d4ed8', 'ink' => '#111827']] + $item);
    ContentPacks::item($this->folder, 'taxonomies.json', 'tax:post_cat/dich-vu', fn (array $item): array => ['name' => ['vi' => 'Dịch vụ bản 2']] + $item);
    ContentPacks::edit($this->folder, 'posts.json', fn (array $data): array => ['items' => [...$data['items'], [
        'key' => 'post:bai-moi', 'verified' => true, 'status' => 'published', 'categories' => ['tax:post_cat/tin-tuc'],
        'title' => ['vi' => 'Bài mới'], 'body' => ['vi' => [['type' => 'richtext', 'props' => ['paragraphs' => ['Bài mới.']]]]],
    ]]]);
    $before = cpSnapshot($this->siteId);
    $ledger = ContentPacks::in($this->siteId, fn (): ?array => Ledger::read('demo-co'));

    [$exit, $output] = ContentPacks::load();

    expect($exit)->toBe(1)->and($output)->toContain('rolled back')->toContain('/tin-tuc/bai-moi')
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
