<?php

use App\Modules\Admin\Support\AuditCatalog;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\EntryTrash;
use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The daily emptying of each website's post and page Trash: what has sat there longer than
 * EntryTrash::days() is deleted for good, with everything the Trash's «Xóa vĩnh viễn» removes.
 * Live content, rows trashed more recently, rows restored meanwhile and other websites' Trash are
 * untouched; running it again deletes nothing more.
 */

beforeEach(function (): void {
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
});

/** A post or page of the current site, in the Trash since $trashedAt (null: live). */
function ptEntry(string $title, ?Carbon $trashedAt, string $type = 'post', ?int $parentId = null): Entry
{
    $entry = Entry::query()->create(['type' => $type, 'status' => 'published', 'parent_id' => $parentId, 'meta' => []]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'status' => 'published', 'body' => ['blocks' => []]]);
    if ($trashedAt !== null) {
        $entry->delete();
        Entry::withTrashed()->whereKey($entry->id)->toBase()->update(['deleted_at' => $trashedAt->utc()->toDateTimeString().'+00']);
    }

    return $entry;
}

/** Inside the site: file the entry under a category and make it an image; returns the image's id. */
function ptLink(Entry $entry, Taxonomy $category, string $tag): int
{
    $entry->assignCategories([$category->id]);

    return Media::query()->create([
        'collection' => 'library', 'slug' => "anh-{$tag}", 'hash' => hash('sha256', "img-{$tag}"),
        'storage_key' => "s/library/anh-{$tag}.png", 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
    ])->id;
}

/**
 * After the site's transaction: give the entry one row in every other table that hangs off it (an
 * address, a version, a change draft, a workflow step, a block-image link, a block usage, a search
 * word and a redirect), written past RLS as the rows the app writes would be.
 */
function ptFill(int $entryId, int $mediaId, string $tag): void
{
    $admin = DB::connection('pgsql_admin');
    $now = now();
    $blockId = $admin->table('content_blocks')->insertGetId([
        'ulid' => (string) Str::ulid(), 'site_id' => 1, 'key' => "khoi-{$tag}", 'name' => "Khối {$tag}",
    ]);
    $admin->table('routes')->insert(['site_id' => 1, 'locale' => 'vi', 'path' => "/dia-chi-{$tag}", 'entry_id' => $entryId, 'created_at' => $now, 'updated_at' => $now]);
    $admin->table('entry_revisions')->insert(['ulid' => (string) Str::ulid(), 'site_id' => 1, 'entry_id' => $entryId, 'locale' => 'vi', 'kind' => 'publish', 'snapshot' => '{}']);
    $admin->table('entry_drafts')->insert(['site_id' => 1, 'entry_id' => $entryId, 'content' => '{"locales":{}}', 'created_at' => $now, 'updated_at' => $now]);
    $admin->table('workflow_log')->insert(['site_id' => 1, 'entry_id' => $entryId, 'locale' => 'vi', 'transition_key' => 'submit', 'from_state' => 'draft', 'to_state' => 'pending']);
    $admin->table('mediables')->insert(['site_id' => 1, 'media_id' => $mediaId, 'mediable_type' => 'entry', 'mediable_id' => $entryId, 'collection' => 'content', 'created_at' => $now, 'updated_at' => $now]);
    $admin->table('block_usages')->insert(['site_id' => 1, 'content_block_id' => $blockId, 'locale' => 'vi', 'entry_id' => $entryId]);
    $admin->table('entry_search_words')->insertOrIgnore(['site_id' => 1, 'locale' => 'vi', 'word' => "tu{$tag}", 'entry_id' => $entryId, 'weight' => 1]);
    $admin->table('redirects')->insert(['site_id' => 1, 'locale' => 'vi', 'from_path' => "/cu-{$tag}", 'to_path' => "/dia-chi-{$tag}", 'entry_id' => $entryId, 'created_at' => $now, 'updated_at' => $now]);
}

/** @return array<string, int> rows still pointing at the entry, in every table, read past RLS */
function ptRows(int $entryId): array
{
    $admin = DB::connection('pgsql_admin');
    $rows = [];
    foreach (['entry_translations', 'routes', 'entry_taxonomies', 'entry_revisions', 'entry_drafts', 'workflow_log', 'block_usages', 'entry_search_words', 'redirects'] as $table) {
        $rows[$table] = $admin->table($table)->where('entry_id', $entryId)->count();
    }
    $rows['mediables'] = $admin->table('mediables')->where('mediable_type', 'entry')->where('mediable_id', $entryId)->count();

    return $rows;
}

function ptCategory(string $slug): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'parent_id' => null, 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin '.$slug, 'slug' => $slug]);

    return $category;
}

it('deletes only posts and pages trashed over the retention window, in every website, each in its own', function (): void {
    $this->travelTo(Carbon::parse('2027-01-15 10:00:00', 'UTC'));
    $kept = [];
    $gone = [];
    foreach ([1, 2] as $site) {
        app(Tenancy::class)->enter($site, function () use ($site, &$kept, &$gone): void {
            $gone[$site] = [ptEntry('Quá hạn', now()->subDays(30)->subHour())->id, ptEntry('Trang quá hạn', now()->subDays(45), 'page')->id];
            $kept[$site] = [
                ptEntry('Sắp tới hạn', now()->subDays(30)->addHour())->id,
                ptEntry('Còn mới', now()->subDay())->id,
                ptEntry('Đang đăng', null)->id,
                ptEntry('Trang đang dùng', null, 'page')->id,
            ];
        });
    }

    $this->artisan('entries:purge-trash')->expectsOutputToContain('Purged 4 post(s) and page(s)')->assertSuccessful();

    foreach ([1, 2] as $site) {
        app(Tenancy::class)->enter($site, function () use ($site, $kept): void {
            expect(Entry::withTrashed()->orderBy('id')->pluck('id')->all())->toBe($kept[$site]);
        });
        expect(DB::connection('pgsql_admin')->table('entries')->whereIn('id', $gone[$site])->exists())->toBeFalse();
    }

    // one audit row per website, written in that website and counting its own rows only
    $audit = DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'entries.entry.purged')->orderBy('site_id')->get();
    expect($audit)->toHaveCount(2)
        ->and($audit->pluck('site_id')->map(fn ($id): int => (int) $id)->all())->toBe([1, 2]);
    foreach ($audit as $row) {
        expect(json_decode((string) $row->meta, true))->toMatchArray(['entries' => 2, 'scheduled' => true, 'trash_days' => 30]);
    }
});

it('removes everything hanging off a purged entry and nothing of a kept one', function (): void {
    $this->travelTo(Carbon::parse('2027-01-15 10:00:00', 'UTC'));
    [$goneId, $keptId, $parentId, $childId, $categoryId, $countBefore, $media] = app(Tenancy::class)->enter(1, function (): array {
        $category = ptCategory('su-kien');
        $gone = ptEntry('Xóa lâu', null);
        $kept = ptEntry('Mới xóa', null);
        $media = [ptLink($gone, $category, 'a'), ptLink($kept, $category, 'b')];
        $parent = ptEntry('Trang cha đã xóa', null, 'page');
        $child = ptEntry('Trang con còn dùng', null, 'page', $parent->id);
        foreach ([[$gone, 40], [$kept, 2], [$parent, 40]] as [$entry, $days]) {
            $entry->delete();
            Entry::withTrashed()->whereKey($entry->id)->toBase()->update(['deleted_at' => now()->subDays($days)->utc()->toDateTimeString().'+00']);
        }

        return [$gone->id, $kept->id, $parent->id, $child->id, $category->id, (int) Taxonomy::query()->whereKey($category->id)->value('items_count'), $media];
    });
    ptFill($goneId, $media[0], 'a');
    ptFill($keptId, $media[1], 'b');

    $tables = ['entry_translations', 'routes', 'entry_taxonomies', 'entry_revisions', 'entry_drafts', 'workflow_log', 'block_usages', 'entry_search_words', 'redirects', 'mediables'];
    $some = fn (array $rows): array => array_keys(array_filter($rows, fn (int $n): bool => $n > 0));
    expect($some(ptRows($goneId)))->toBe($tables)
        ->and($some(ptRows($keptId)))->toBe($tables);

    $this->artisan('entries:purge-trash')->expectsOutputToContain('Purged 2 post(s) and page(s)')->assertSuccessful();

    $admin = DB::connection('pgsql_admin');
    expect($admin->table('entries')->whereIn('id', [$goneId, $parentId])->exists())->toBeFalse()
        // nothing is left pointing at the purged entry; the redirect keeps its rule and only loses the link back
        ->and($some(ptRows($goneId)))->toBe([])
        ->and($admin->table('redirects')->where('from_path', '/cu-a')->whereNull('entry_id')->count())->toBe(1)
        ->and($some(ptRows($keptId)))->toBe($tables)
        // a sub-page left live keeps its place, at the top level
        ->and($admin->table('entries')->where('id', $childId)->whereNull('parent_id')->whereNull('deleted_at')->exists())->toBeTrue();

    // the category totals already moved when the post went to the Trash: the purge does not move them again
    app(Tenancy::class)->enter(1, function () use ($categoryId, $countBefore): void {
        expect((int) Taxonomy::query()->whereKey($categoryId)->value('items_count'))->toBe($countBefore);
    });
});

it('deletes nothing twice, and leaves an entry restored before the run', function (): void {
    $this->travelTo(Carbon::parse('2027-01-15 10:00:00', 'UTC'));
    [$restoredId, $goneId] = app(Tenancy::class)->enter(1, function (): array {
        $restored = ptEntry('Đã khôi phục', now()->subDays(50));
        Entry::onlyTrashed()->whereKey($restored->id)->firstOrFail()->restore();

        return [$restored->id, ptEntry('Bỏ đi', now()->subDays(50))->id];
    });

    $this->artisan('entries:purge-trash')->expectsOutputToContain('Purged 1 post(s)')->assertSuccessful();
    $this->artisan('entries:purge-trash')->expectsOutputToContain('Purged 0 post(s)')->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($restoredId, $goneId): void {
        expect(Entry::query()->whereKey($restoredId)->exists())->toBeTrue()
            ->and(Entry::withTrashed()->whereKey($goneId)->exists())->toBeFalse();
    });
    expect(DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'entries.entry.purged')->count())->toBe(1);
});

it('empties a Trash larger than one chunk', function (): void {
    $this->travelTo(Carbon::parse('2027-01-15 10:00:00', 'UTC'));
    $old = now()->subDays(60)->utc()->toDateTimeString().'+00';
    $rows = [];
    foreach (range(1, 205) as $i) {
        $rows[] = ['ulid' => (string) Str::ulid(), 'site_id' => 1, 'type' => 'post', 'status' => 'draft', 'meta' => '{}', 'created_at' => $old, 'updated_at' => $old, 'deleted_at' => $old];
    }
    DB::connection('pgsql_admin')->table('entries')->insert($rows);

    $this->artisan('entries:purge-trash')->expectsOutputToContain('Purged 205 post(s)')->assertSuccessful();

    expect(DB::connection('pgsql_admin')->table('entries')->where('site_id', 1)->count())->toBe(0);
});

it('counts entries trashed before the purge began from the day it began', function (): void {
    $this->travelTo(Carbon::parse('2026-12-01 10:00:00', 'UTC'));
    $id = app(Tenancy::class)->enter(1, fn (): int => ptEntry('Xóa từ trước', Carbon::parse('2026-10-01 08:00:00', 'UTC'))->id);

    // trashed 61 days ago, but nobody was told then that it would go: it still has its 30 days from the start
    $this->artisan('entries:purge-trash')->expectsOutputToContain('Purged 0 post(s)')->assertSuccessful();
    expect(EntryTrash::daysLeft(Carbon::parse('2026-10-01 08:00:00', 'UTC'), now()))->toBe(17);

    $this->travelTo(Carbon::parse(EntryTrash::SINCE, 'UTC')->addDays(30)->addHour());
    $this->artisan('entries:purge-trash')->expectsOutputToContain('Purged 1 post(s)')->assertSuccessful();
    expect(DB::connection('pgsql_admin')->table('entries')->where('id', $id)->exists())->toBeFalse();
});

it('takes its window from the settings, and the default for one that is not a whole number of days', function (): void {
    $now = Carbon::parse('2027-01-15 10:00:00', 'UTC');

    expect(EntryTrash::days())->toBe(30)
        ->and(EntryTrash::daysLeft($now->copy(), $now))->toBe(30)
        ->and(EntryTrash::daysLeft($now->copy()->subDays(29)->subHours(23), $now))->toBe(1)
        ->and(EntryTrash::daysLeft($now->copy()->subDays(30), $now))->toBe(0)
        ->and(EntryTrash::daysLeft($now->copy()->subDays(99), $now))->toBe(0);

    config(['cms.trash_retention_days' => 7]);
    expect(EntryTrash::days())->toBe(7)
        ->and(EntryTrash::cutoff($now)?->toDateTimeString())->toBe('2027-01-08 10:00:00');

    config(['cms.trash_retention_days' => '12']);
    expect(EntryTrash::days())->toBe(12);
    foreach ([0, -3, '', 'abc', '0', null] as $mistyped) {
        config(['cms.trash_retention_days' => $mistyped]);
        expect(EntryTrash::days())->toBe(30);
    }
});

it('reads the environment setting as written, so an empty or mistyped one keeps the 30 days', function (): void {
    $read = function (string $value): int {
        putenv("CMS_TRASH_RETENTION_DAYS={$value}");
        $_ENV['CMS_TRASH_RETENTION_DAYS'] = $_SERVER['CMS_TRASH_RETENTION_DAYS'] = $value;
        try {
            config(['cms' => require base_path('config/cms.php')]);

            return EntryTrash::days();
        } finally {
            putenv('CMS_TRASH_RETENTION_DAYS');
            unset($_ENV['CMS_TRASH_RETENTION_DAYS'], $_SERVER['CMS_TRASH_RETENTION_DAYS']);
        }
    };

    expect($read(''))->toBe(30)
        ->and($read('abc'))->toBe(30)
        ->and($read('0'))->toBe(30)
        ->and($read('14'))->toBe(14);
});

it('is scheduled daily without overlapping, and named in the audit log', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'entries:purge-trash'));
    $catalog = new AuditCatalog;

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($catalog->groupOf('entries.entry.purged'))->not->toBeNull()
        ->and($catalog->label('entries.entry.purged'))->not->toStartWith('admin.audit.act.');
});
