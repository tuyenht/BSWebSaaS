<?php

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Notifications\Support\NotificationFeed;
use App\Modules\Notifications\Support\NotificationSearchText;
use App\Modules\Notifications\Support\Notify;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * The Notifications page's search line (site_notifications.search_text): the facts of a notice,
 * folded (lower case, no accents, đ → d) and cut at 512 characters, written with the notice and
 * rewritten when its data changes, filled for older notices by `notifications:search-text`; a
 * search walks the reader's own notices on the feed index under RLS.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->statement('truncate site_notifications restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function nstReader(int $site = 1): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, 'editor', ['entries.view']));

    return $user->fresh();
}

/** @return array<int, string|null> id => search_text, as stored */
function nstStored(): array
{
    return DB::connection('pgsql_admin')->table('site_notifications')->orderBy('id')->pluck('search_text', 'id')->all();
}

it('folds the facts in a fixed order and leaves the sentence, the addresses and the times out', function (): void {
    expect(NotificationSearchText::for([
        'note' => "Thêm bảng\n dung sai.", 'title' => 'Máy phay 5 trục', 'actor' => 'Đặng Văn Nam', 'entry' => '01J0000000000000000000000',
        'locale' => 'vi', 'sentence' => 'failing', 'at' => '14/11/2026 10:00', 'change' => true, 'platform' => 'Bảo Sơn',
    ]))->toBe('dang van nam may phay 5 truc them bang dung sai.')
        ->and(NotificationSearchText::for(['staff' => 'Lê Hỗ Trợ', 'member' => 'Chị Hà', 'reason' => 'Sửa giá', 'platform' => 'Bảo Sơn']))->toBe('le ho tro chi ha sua gia')
        ->and(NotificationSearchText::for(['name' => '0900 000 000', 'submission' => '01J0000000000000000000000']))->toBe('0900 000 000')
        ->and(NotificationSearchText::for(['domains' => 'AnVinh.vn, www.anvinh.vn']))->toBe('anvinh.vn, www.anvinh.vn')
        ->and(NotificationSearchText::for(['ip' => '203.0.113.9']))->toBe('')
        ->and(NotificationSearchText::for(['title' => ['not', 'text'], 'actor' => null]))->toBe('')
        ->and(mb_strlen(NotificationSearchText::for(['actor' => 'An', 'note' => str_repeat('ư', 900)])))->toBe(512);
});

it('splits what is typed into folded words, at most eight, from the first 100 characters', function (): void {
    expect(NotificationSearchText::words('  Anh   NAM, máy phay '))->toBe(['anh', 'nam,', 'may', 'phay'])
        ->and(NotificationSearchText::words("nam\tnam"))->toBe(['nam'])
        ->and(NotificationSearchText::words('   '))->toBe([])
        ->and(NotificationSearchText::words('a b c d e f g h i j'))->toHaveCount(8)
        ->and(NotificationSearchText::words(str_repeat('x', 150)))->toBe([str_repeat('x', 100)])
        // a Mac keyboard may send combining marks: folded the same as the precomposed letters
        ->and(NotificationSearchText::words("Nguye\u{0302}\u{0303}n"))->toBe(['nguyen'])
        // control characters split like a space: a NUL would make PostgreSQL refuse the bound word
        ->and(NotificationSearchText::words("nam\u{0000}phay\u{001F}x\u{007F}"))->toBe(['nam', 'phay', 'x']);
});

it('refuses a --site that is not the id of an existing site, instead of filling nothing and passing', function (): void {
    $this->artisan('notifications:search-text', ['--site' => 'av1.baoson.net'])->expectsOutputToContain('--site takes the id of an existing site.')->assertFailed();
    $this->artisan('notifications:search-text', ['--site' => '0'])->assertFailed();
    $this->artisan('notifications:search-text', ['--site' => '2147483000'])->assertFailed();
});

it('writes the line with every notice, and rewrites it when the notice\'s data changes', function (): void {
    $reader = nstReader();
    app(Tenancy::class)->enter(1, fn () => Notify::user($reader->id, 'lead.new', ['name' => 'Nguyễn Thị Lan', 'submission' => '01J0000000000000000000000']));
    expect(array_values(nstStored()))->toBe(['nguyen thi lan']);

    app(Tenancy::class)->enter(1, function (): void {
        $notice = SiteNotification::query()->firstOrFail();
        $notice->update(['read_at' => now()]);                            // data untouched: the line stays
        expect($notice->search_text)->toBe('nguyen thi lan');
        $notice->update(['data' => ['name' => '—', 'submission' => '01J0000000000000000000000']]); // a customer's details cleared
    });
    expect(array_values(nstStored()))->toBe(['—']);
});

it('fills the notices written before the column, site by site, and a second run fills nothing', function (): void {
    $a = nstReader(1);
    $b = nstReader(2);
    $admin = DB::connection('pgsql_admin');
    foreach ([[1, $a, ['name' => 'Trần Văn Bình']], [1, $a, ['ip' => '203.0.113.9']], [2, $b, ['title' => 'Khuôn ép nhựa', 'actor' => 'Chị Hà']]] as [$site, $user, $data]) {
        $admin->table('site_notifications')->insert(['ulid' => (string) Str::ulid(), 'site_id' => $site, 'user_id' => $user->id, 'kind' => 'lead.new', 'data' => json_encode($data)]);
    }
    expect(array_values(nstStored()))->toBe([null, null, null]);

    $this->artisan('notifications:search-text', ['--chunk' => 1])->expectsOutputToContain('Wrote the search text of 3 notification(s)')->assertSuccessful();
    expect(array_values(nstStored()))->toBe(['tran van binh', '', 'chi ha khuon ep nhua']);

    $this->artisan('notifications:search-text')->expectsOutputToContain('Wrote the search text of 0 notification(s)')->assertSuccessful();

    // --all rewrites a line that no longer matches the facts, and only that one
    $admin->table('site_notifications')->where('id', 1)->update(['search_text' => 'cu']);
    $this->artisan('notifications:search-text', ['--all' => true, '--site' => 1])->expectsOutputToContain('Wrote the search text of 1 notification(s) on 1 site(s)')->assertSuccessful();
    expect(array_values(nstStored()))->toBe(['tran van binh', '', 'chi ha khuon ep nhua']);
});

it('keeps the line the model wrote when a notice\'s data changes between the fill\'s read and its write', function (): void {
    $reader = nstReader();
    $admin = DB::connection('pgsql_admin');
    $admin->table('site_notifications')->insert(['ulid' => (string) Str::ulid(), 'site_id' => 1, 'user_id' => $reader->id, 'kind' => 'lead.new', 'data' => json_encode(['name' => 'Đỗ Văn Cũ'])]);

    $changed = false;
    DB::connection()->beforeExecuting(function (string $sql) use ($admin, &$changed): void {
        if (! $changed && str_starts_with($sql, 'UPDATE site_notifications AS n')) {
            $changed = true; // as a privacy clean-up would, through the model: new data, its own line
            $admin->table('site_notifications')->update(['data' => json_encode(['name' => '—']), 'search_text' => '—']);
        }
    });
    $this->artisan('notifications:search-text')->expectsOutputToContain('Wrote the search text of 0 notification(s)')->assertSuccessful();

    expect($changed)->toBeTrue()->and(array_values(nstStored()))->toBe(['—']);
});

it('logs a failed site without the statement, which would carry the notices\' facts', function (): void {
    $reader = nstReader();
    DB::connection('pgsql_admin')->table('site_notifications')->insert(['ulid' => (string) Str::ulid(), 'site_id' => 1, 'user_id' => $reader->id, 'kind' => 'lead.new', 'data' => json_encode(['name' => 'Ngô Thị Riêng'])]);
    DB::connection()->beforeExecuting(function (string $sql): void {
        if (str_starts_with($sql, 'UPDATE site_notifications AS n')) {
            throw new RuntimeException('UPDATE … ngo thi rieng');
        }
    });
    Log::spy();

    $this->artisan('notifications:search-text', ['--site' => 1])->doesntExpectOutputToContain('rieng')->assertFailed();

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context === ['site_id' => 1, 'error' => RuntimeException::class, 'code' => 0])->once();
});

it('walks only the reader\'s notices on the feed index, the search a row filter after RLS', function (): void {
    $reader = nstReader();
    $others = User::factory()->count(4)->create()->modelKeys();
    $admin = DB::connection('pgsql_admin');
    // the reader's notices a sliver of the table, as on a large one: 300 of theirs among 40,000
    $admin->statement(<<<'SQL'
        insert into site_notifications (ulid, site_id, user_id, kind, data, search_text, created_at)
        select lpad(v.u::text || 'x' || g::text, 26, '0'), 1, v.u, 'lead.new', '{}', 'khach ' || g, now() - (v.n - g) * interval '5 minutes'
        from (values (?::bigint, 300), (?::bigint, 10000), (?::bigint, 10000), (?::bigint, 10000), (?::bigint, 10000)) v(u, n)
        cross join lateral generate_series(1, v.n) g
        order by g
    SQL, [$reader->id, ...$others]);
    $admin->statement('analyze site_notifications');

    app(Tenancy::class)->enter(1, function () use ($reader): void {
        $sent = [];
        DB::listen(function (QueryExecuted $query) use (&$sent): void {
            $sent[] = ['sql' => $query->sql, 'bindings' => $query->connection->prepareBindings($query->bindings)];
        });
        $found = app(NotificationFeed::class)->matches($reader, null, false, ['khach', '29'], 100);
        expect(array_slice(array_column($found, 'ulid'), 0, 3))->toBe([
            str_pad($reader->id.'x299', 26, '0', STR_PAD_LEFT), str_pad($reader->id.'x298', 26, '0', STR_PAD_LEFT), str_pad($reader->id.'x297', 26, '0', STR_PAD_LEFT),
        ])->and($sent)->toHaveCount(1);

        $plan = DB::transaction(function () use ($sent): string {
            DB::statement('SET LOCAL enable_seqscan = off');

            return (string) array_values((array) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sent[0]['sql'], $sent[0]['bindings']))[0];
        });
        $scans = [];
        $walk = function (array $node) use (&$walk, &$scans): void {
            if (isset($node['Index Name'])) {
                $scans[$node['Index Name']] = [(string) ($node['Index Cond'] ?? ''), (string) ($node['Filter'] ?? '')];
            }
            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk(json_decode($plan, true)[0]['Plan']);

        // the walk: the reader's slice newest first on the feed index; the words and the 90 days are
        // checked on each row it reads
        expect(array_keys($scans))->toBe(['site_notifications_feed'])
            ->and($scans['site_notifications_feed'][0])->toContain('site_id =')->toContain('user_id =')
            ->and($scans['site_notifications_feed'][1])->toContain('search_text')->toContain('created_at >=');
    });
});

it('logs a search slower than the line, with no word typed in the log', function (): void {
    $reader = nstReader();
    Log::spy();
    app(Tenancy::class)->enter(1, fn () => app(NotificationFeed::class)->matches($reader, null, false, ['nguyen'], 8));
    Log::shouldNotHaveReceived('warning');

    DB::connection()->beforeExecuting(function (string $sql): void {
        if (str_contains($sql, 'search_text')) {
            usleep((NotificationFeed::SEARCH_SLOW_MS + 20) * 1000);
        }
    });
    app(Tenancy::class)->enter(1, fn () => app(NotificationFeed::class)->matches($reader, null, false, ['nguyen'], 8));

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($reader): bool {
        return $message === 'Notifications search was slow' && $context['ms'] > NotificationFeed::SEARCH_SLOW_MS
            && $context['site_id'] === 1 && $context['user_id'] === $reader->id && $context['words'] === 1
            && ! str_contains(json_encode($context), 'nguyen');
    });
});

it('adds the line as a nullable text column, and takes it away and back without touching the notices', function (): void {
    $column = fn () => DB::connection('pgsql_admin')->selectOne(
        "select data_type, is_nullable from information_schema.columns where table_name = 'site_notifications' and column_name = 'search_text'",
    );
    expect((array) $column())->toBe(['data_type' => 'text', 'is_nullable' => 'YES']);

    $reader = nstReader();
    app(Tenancy::class)->enter(1, fn () => Notify::user($reader->id, 'lead.new', ['name' => 'Võ Thị Hạnh']));
    $migration = require database_path('migrations/2063_01_01_000000_add_search_text_to_site_notifications.php');
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $migration->down();
        expect($column())->toBeNull();
        $migration->down(); // a repeated step is harmless
    } finally {
        $migration->up();
        $migration->up();
        DB::setDefaultConnection($previous);
    }
    expect((array) $column())->toBe(['data_type' => 'text', 'is_nullable' => 'YES'])
        ->and(array_values(nstStored()))->toBe([null]);
    $this->artisan('notifications:search-text')->assertSuccessful();
    expect(array_values(nstStored()))->toBe(['vo thi hanh']);
})->skip(getenv('CI') !== 'true', 'drops and re-adds a column: runs on CI\'s own database only');
