<?php

use App\Modules\Admin\Livewire\GeneralSettings;
use App\Modules\Admin\Support\GeneralSettingsStore;
use App\Modules\Admin\Support\Settings\ResetSettingsSection;
use App\Modules\Admin\Support\Settings\SetBrandImages;
use App\Modules\Admin\Support\Settings\SettingsChangeRejected;
use App\Modules\Admin\Support\Settings\SettingsWrite;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * A settings save from an admin screen waits for the site's settings lock a few seconds at most:
 * behind a long writer (a content-pack apply holds the same lock) the admin gets a plain "try again"
 * line and keeps what was typed, instead of a frozen screen. The pictures a save points at are
 * looked up with that lock held and locked against a purge until the save ends, so a picture
 * removed at the same time is refused as image_invalid, never a foreign-key error.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

afterEach(function (): void {
    Livewire::flushState();
});

function swlAdmin(): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
}

function swlMedia(string $slug): Media
{
    return app(Tenancy::class)->enter(1, function () use ($slug): Media {
        $hash = hash('sha256', $slug);

        return Media::query()->create([
            'collection' => 'brand', 'slug' => $slug, 'hash' => $hash,
            'storage_key' => 's/brand/'.$slug.'-'.substr($hash, 0, 12).'.png',
            'mime' => 'image/png', 'size' => 1_000, 'width' => 320, 'height' => 80,
        ]);
    });
}

/** Calls $then once, right after the first query on the app connection that matches $pattern. */
function swlAfterQuery(string $pattern, Closure $then): void
{
    $done = false;
    DB::listen(function (QueryExecuted $query) use ($pattern, $then, &$done): void {
        if (! $done && $query->connectionName === DB::getDefaultConnection() && preg_match($pattern, $query->sql) === 1) {
            $done = true;
            $then($query);
        }
    });
}

/** Whether another session can take a row lock on the picture within 300ms (the probe changes nothing). */
function swlOtherSessionCan(string $sql, int $mediaId): bool
{
    $other = DB::connection('pgsql_admin');
    $other->beginTransaction();
    try {
        $other->statement("set local lock_timeout = '300ms'");
        $other->statement($sql, [$mediaId]);

        return true;
    } catch (QueryException $e) {
        if (($e->errorInfo[0] ?? null) === '55P03') {
            return false;
        }

        throw $e;
    } finally {
        $other->rollBack();
    }
}

it('limits the wait for the site lock inside each writer\'s own transaction, and gives the request its value back', function (): void {
    $logo = swlMedia('logo');
    $this->actingAs(swlAdmin());

    $writers = [
        'brand images' => fn () => app(SetBrandImages::class)->handle(['logo' => $logo->storage_key]),
        'general' => function (): void {
            $store = app(GeneralSettingsStore::class);
            ['i18n' => $i18n, 'shared' => $shared] = $store->load();
            $store->save($i18n, $shared);
        },
        'reset and undo' => fn () => app(ResetSettingsSection::class)->undo(app(ResetSettingsSection::class)->reset('brand_images')),
    ];

    foreach ($writers as $name => $write) {
        app(Tenancy::class)->enter(1, function () use ($name, $write): void {
            DB::statement("set local lock_timeout = '7s'");   // what the request had before the save
            $seen = [];
            $recording = true;
            DB::listen(function (QueryExecuted $query) use (&$seen, &$recording): void {
                if ($recording && $query->connectionName === DB::getDefaultConnection()) {
                    $seen[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
                }
            });

            $write();
            $recording = false;

            $limits = array_keys(array_filter($seen, fn (array $q): bool => $q['sql'] === "set local lock_timeout = '".SettingsWrite::LOCK_TIMEOUT."'"));
            $siteLocks = array_keys(array_filter($seen, fn (array $q): bool => str_starts_with($q['sql'], 'select "id" from "sites"') && str_ends_with($q['sql'], 'for no key update')));
            expect($limits)->not->toBeEmpty($name)
                ->and(count($limits))->toBe(count($siteLocks), $name);
            foreach ($limits as $i => $at) {
                expect($seen[$at]['level'])->toBe(2, $name)   // inside the save's own transaction, not the request's
                    ->and($siteLocks[$i])->toBeGreaterThan($at, $name);   // set before the lock is taken
            }
            expect(DB::scalar("select current_setting('lock_timeout')"))->toBe('7s', $name);
        });
    }
});

it('says another save is running and keeps what the admin typed, when the site lock is not free in time', function (): void {
    $admin = swlAdmin();
    $other = DB::connection('pgsql_admin');
    $other->beginTransaction();
    $other->select('select id from sites where id = 1 for no key update');   // a long writer, as a content-pack apply

    try {
        app(Tenancy::class)->enter(1, function () use ($admin): void {
            Livewire::actingAs($admin)->test(GeneralSettings::class)
                ->set('i18n.vi.name', 'Ở xưởng Bảo Hà')
                ->set('form.legalName', 'CÔNG TY TNHH CƠ KHÍ BẢO HÀ')
                ->call('save')
                ->assertHasNoErrors()
                ->assertSet('toast', 'Đang có người khác lưu cài đặt của website, vui lòng thử lại sau ít phút.')
                ->assertSet('toastTone', 'error')
                ->assertSet('i18n.vi.name', 'Ở xưởng Bảo Hà')
                ->assertSet('form.legalName', 'CÔNG TY TNHH CƠ KHÍ BẢO HÀ')
                ->assertSee('Đang có người khác lưu cài đặt của website');
        });
    } finally {
        $other->rollBack();
    }

    expect(DB::connection('pgsql_admin')->table('site_profiles')->where('site_id', 1)->exists())->toBeFalse();
});

it('turns a lock wait that ran out into a busy refusal in every writer', function (): void {
    $logo = swlMedia('logo');
    $this->actingAs(swlAdmin());
    // The lock wait running out, without waiting for it: the site lock query fails as PostgreSQL does.
    $timesOut = function (): void {
        swlAfterQuery('/from "sites" .*for no key update$/', function (QueryExecuted $query): void {
            $pdo = new PDOException('SQLSTATE[55P03]: Lock not available: 7 ERROR:  canceling statement due to lock timeout');
            $pdo->errorInfo = ['55P03', 7, 'ERROR:  canceling statement due to lock timeout'];

            throw new QueryException($query->connectionName, $query->sql, $query->bindings, $pdo);
        });
    };

    $calls = [
        'brand_images' => fn () => app(SetBrandImages::class)->handle(['logo' => $logo->storage_key]),
        'general' => function (): void {
            $store = app(GeneralSettingsStore::class);
            ['i18n' => $i18n, 'shared' => $shared] = $store->load();
            $store->save($i18n, $shared);
        },
        'seo' => fn () => app(ResetSettingsSection::class)->reset('seo'),
        'brand_images undo' => fn () => app(ResetSettingsSection::class)->undo(['section' => 'brand_images', 'settings' => [], 'profile' => ['logo_media_id' => $logo->storage_key], 'translations' => [], 'brand' => []]),
    ];
    foreach ($calls as $field => $call) {
        app(Tenancy::class)->enter(1, function () use ($timesOut, $call, $field): void {
            $timesOut();
            expect($call)->toThrow(SettingsChangeRejected::class, 'busy: '.explode(' ', $field)[0]);
            expect(DB::scalar('select 1'))->toBe(1);   // the request's transaction is still usable
        });
    }

    expect(DB::connection('pgsql_admin')->table('site_profiles')->where('site_id', 1)->exists())->toBeFalse();
});

it('refuses a picture purged once the save holds the site lock as image_invalid, and writes nothing', function (): void {
    $logo = swlMedia('logo');
    $this->actingAs(swlAdmin());

    app(Tenancy::class)->enter(1, function () use ($logo): void {
        // Purged by another session after the save has its lock, before it looks the picture up.
        swlAfterQuery('/from "sites" .*for no key update$/', function () use ($logo): void {
            $other = DB::connection('pgsql_admin');
            $other->transaction(fn () => $other->table('media')->where('id', $logo->id)->delete());
        });

        expect(fn () => app(SetBrandImages::class)->handle(['logo' => $logo->storage_key]))
            ->toThrow(SettingsChangeRejected::class, 'image_invalid: logo');
    });

    expect(DB::connection('pgsql_admin')->table('site_profiles')->where('site_id', 1)->exists())->toBeFalse();
});

it('holds a purge of the pictures a save points at until it ends, but not a move to the trash', function (): void {
    $logo = swlMedia('logo');
    $this->actingAs(swlAdmin());
    $undo = app(Tenancy::class)->enter(1, function () use ($logo): array {
        app(SetBrandImages::class)->handle(['logo' => $logo->storage_key]);

        return app(ResetSettingsSection::class)->reset('brand_images');
    });

    foreach (['brand images' => fn () => app(SetBrandImages::class)->handle(['logo' => $logo->storage_key]), 'undo' => fn () => app(ResetSettingsSection::class)->undo($undo)] as $name => $write) {
        $probe = [];
        app(Tenancy::class)->enter(1, function () use ($logo, $write, &$probe): void {
            // Right after the save has looked the picture up, before it writes the profile.
            swlAfterQuery('/from "media" .*for key share$/', function () use ($logo, &$probe): void {
                $probe = [
                    'purge' => swlOtherSessionCan('delete from media where id = ?', $logo->id),
                    'trash' => swlOtherSessionCan('update media set deleted_at = now() where id = ?', $logo->id),
                ];
            });
            $write();
        });

        expect($probe)->toBe(['purge' => false, 'trash' => true], $name)
            ->and(DB::connection('pgsql_admin')->table('site_profiles')->where('site_id', 1)->value('logo_media_id'))->toBe($logo->id, $name);
    }
});
