<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * `site:fill-entry-authors` names a creator for posts and pages saved with none: the first member
 * recorded as saving it, else the website's first owner. It writes only empty creators, never an
 * edit time, keeps to each site, and a second run writes nothing.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, entry_revisions, routes, entry_translations, entries,
         site_profile_translations, site_profiles, site_settings, site_locales,
         site_modules, content_sections, domains, sites, tenants, tenant_users, users, audit_logs
         restart identity cascade'
    );
    truncateRbac();
});

function authorSite(string $name, string $host): int
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: $name, siteName: $name, hostname: $host,
        ownerEmail: 'owner@'.$host, ownerName: 'Owner '.$name, ownerPassword: 'a-strong-password',
    ));

    return (int) Site::query()->where('name', $name)->value('id');
}

/** A post with no creator, as an old pack or seed wrote it; returns its id. */
function authorlessPost(int $siteId, bool $trashed = false): int
{
    return app(Tenancy::class)->enter($siteId, function () use ($trashed): int {
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => now()]);
        DB::table('entries')->where('id', $entry->id)->update(['updated_at' => '2026-01-02 03:04:05+00']);
        if ($trashed) {
            DB::table('entries')->where('id', $entry->id)->update(['deleted_at' => now()]);
        }

        return $entry->id;
    });
}

/** @return array{author: int|null, updated: string} */
function authorOf(int $siteId, int $entryId): array
{
    return app(Tenancy::class)->enter($siteId, function () use ($entryId): array {
        $row = DB::table('entries')->where('id', $entryId)->first(['author_id', 'updated_at']);

        return ['author' => $row->author_id === null ? null : (int) $row->author_id, 'updated' => (string) $row->updated_at];
    });
}

function ownerIdOf(string $host): int
{
    return (int) User::query()->where('email', 'owner@'.$host)->value('id');
}

it('names the first owner for an entry nobody saved, trashed ones too, and leaves its edit time alone', function (): void {
    $site = authorSite('Tác Giả Một', 'mot.platform.test');
    $post = authorlessPost($site);
    $trashed = authorlessPost($site, trashed: true);
    $before = authorOf($site, $post)['updated'];

    $this->artisan('site:fill-entry-authors')->expectsOutputToContain('Filled 2 creator(s)')->assertSuccessful();

    expect(authorOf($site, $post))->toBe(['author' => ownerIdOf('mot.platform.test'), 'updated' => $before])
        ->and(authorOf($site, $trashed)['author'])->toBe(ownerIdOf('mot.platform.test'));
});

it('names the owner even when a member saved the entry first, so no one gains «own» rights on it', function (): void {
    $site = authorSite('Tác Giả Một', 'mot.platform.test');
    $post = authorlessPost($site);
    $editor = User::query()->create(['name' => 'Biên Tập', 'email' => 'bt@mot.test', 'password' => 'x-strong-password']);
    app(Tenancy::class)->enter($site, fn () => DB::table('entry_revisions')->insert([
        'ulid' => (string) Str::ulid(), 'site_id' => $site, 'entry_id' => $post, 'locale' => 'vi',
        'kind' => 'edit', 'snapshot' => '{}', 'created_by' => $editor->id,
    ]));

    $this->artisan('site:fill-entry-authors', ['--site' => (string) $site])->assertSuccessful();

    expect(authorOf($site, $post)['author'])->toBe(ownerIdOf('mot.platform.test'))
        ->and(app(Tenancy::class)->enter($site, fn () => DB::table('audit_logs')->where('action', 'content.entry_authors_filled')->count()))->toBe(1);
});

it('walks a site past one chunk, and past a full chunk it must skip on a site with no owner', function (): void {
    $one = authorSite('Tác Giả Một', 'mot.platform.test');
    $two = authorSite('Tác Giả Hai', 'hai.platform.test');
    foreach ([$one, $two] as $site) {
        app(Tenancy::class)->enter($site, function () use ($site): void {
            $rows = array_map(fn (): array => ['ulid' => (string) Str::ulid(), 'site_id' => $site, 'type' => 'post', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()], range(1, 501));
            DB::table('entries')->insert($rows);
        });
    }
    DB::connection('pgsql_admin')->table('model_has_roles')->where('site_id', $one)->delete();

    $this->artisan('site:fill-entry-authors')->expectsOutputToContain('Filled 501 creator(s)')->assertSuccessful();

    expect(app(Tenancy::class)->enter($two, fn () => Entry::query()->whereNull('author_id')->count()))->toBe(0)
        ->and(app(Tenancy::class)->enter($one, fn () => Entry::query()->whereNull('author_id')->count()))->toBe(501);
});

it('never replaces a creator, keeps to each site, and writes nothing the second time', function (): void {
    $one = authorSite('Tác Giả Một', 'mot.platform.test');
    $two = authorSite('Tác Giả Hai', 'hai.platform.test');
    $mine = authorlessPost($one);
    $theirs = authorlessPost($two);
    $named = app(Tenancy::class)->enter($one, fn (): int => Entry::query()->whereNotNull('author_id')->value('id'));
    $namedAuthor = authorOf($one, $named)['author'];

    $this->artisan('site:fill-entry-authors', ['--site' => (string) $one])->assertSuccessful();

    expect(authorOf($one, $mine)['author'])->toBe(ownerIdOf('mot.platform.test'))
        ->and(authorOf($two, $theirs)['author'])->toBeNull()
        ->and(authorOf($one, $named)['author'])->toBe($namedAuthor);

    $this->artisan('site:fill-entry-authors')->expectsOutputToContain('Filled 1 creator(s)')->assertSuccessful();
    expect(authorOf($two, $theirs)['author'])->toBe(ownerIdOf('hai.platform.test'));

    $this->artisan('site:fill-entry-authors')->expectsOutputToContain('Filled 0 creator(s)')->assertSuccessful();
});

it('counts without writing on a dry run', function (): void {
    $site = authorSite('Tác Giả Một', 'mot.platform.test');
    $post = authorlessPost($site);

    $this->artisan('site:fill-entry-authors', ['--dry-run' => true])->expectsOutputToContain('would fill 1')->assertSuccessful();

    expect(authorOf($site, $post)['author'])->toBeNull();
});

it('leaves the creator empty on a website with no owner and says so', function (): void {
    $site = authorSite('Tác Giả Một', 'mot.platform.test');
    $post = authorlessPost($site);
    DB::connection('pgsql_admin')->table('model_has_roles')->where('site_id', $site)->delete();

    $this->artisan('site:fill-entry-authors')->expectsOutputToContain('no owner')->assertSuccessful();

    expect(authorOf($site, $post)['author'])->toBeNull();
});

it('refuses a site that is not an id or does not exist', function (): void {
    $this->artisan('site:fill-entry-authors', ['--site' => 'abc'])->assertFailed();
    $this->artisan('site:fill-entry-authors', ['--site' => '999999'])->assertFailed();
});
