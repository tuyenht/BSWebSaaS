<?php

use App\Modules\Admin\Livewire\Platform\Audit\Index;
use App\Modules\Admin\Support\Customers\CustomerDirectory;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\VietnameseFold;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The activity log's filters each have an index (`audit_logs` is platform-wide, D6, so they lead
 * with the filter's column, not site_id). The definitions are pinned here, and the page query the
 * screen really sends is planned against them. The test database is small, so the planner is told
 * to avoid a full table read (`enable_seqscan = off`) and must then pick an index that can serve
 * the query; each test starts from the same 2,000 analysed rows, because statistics left by an
 * earlier test (TRUNCATE keeps them) would otherwise sway the choice between indexes. A screen
 * query rewritten so it no longer matches its index (the support predicate above all, which a
 * partial index matches only word for word) fails here. The people and website search is
 * planned the same way against the trigram indexes on users and sites.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
    $admin = DB::connection('pgsql_admin');
    $admin->statement('truncate audit_logs restart identity cascade');
    // 60 days of rows from 500 people across 50 websites, 2% of them support rows
    $admin->statement(<<<'SQL'
        insert into audit_logs (ulid, action, actor_id, impersonator_id, support_session_ulid, site_id, meta, created_at)
        select lpad(g::text, 26, '0'),
               case when g % 50 = 0 then 'support.session.entered' else 'cms.menu.saved' end,
               1000000000 + g % 500,
               case when g % 50 = 0 then 1000000000 + (g / 50) % 20 end,
               case when g % 50 = 0 then lpad((g / 1000)::text, 26, 's') end,
               1000000000 + g % 50, '{}', now() - g * interval '43 minutes'
        from generate_series(1, 2000) g
    SQL);
    $admin->statement('analyze audit_logs');
});

function auditIndexOperator(): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('support'));
    app(PlatformAccess::class)->forgetMemo();

    return $user->fresh();
}

/**
 * The page query (`limit per + 1`) the screen sent on its last render with these URL values.
 *
 * @param  array<string, mixed>  $filters
 * @return array{sql: string, bindings: array<int, mixed>}
 */
function auditPageQuery(array $filters): array
{
    $operator = auditIndexOperator();
    $page = null;
    DB::listen(function (QueryExecuted $query) use (&$page): void {
        if (str_contains($query->sql, 'from "audit_logs"') && str_ends_with($query->sql, 'limit 21')) {
            $page = ['sql' => $query->sql, 'bindings' => $query->connection->prepareBindings($query->bindings)];
        }
    });

    Livewire::withQueryParams($filters)->actingAs($operator)->test(Index::class)->assertOk();

    expect($page)->not->toBeNull('The screen sent no page query.');

    return $page;
}

/**
 * The indexes Postgres plans to read for $sql when a full table read is ruled out.
 *
 * @param  array<int, mixed>  $bindings
 * @return list<string>
 */
function auditPlannedIndexes(string $sql, array $bindings): array
{
    return DB::transaction(function () use ($sql, $bindings): array {
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = (array) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql, $bindings);
        preg_match_all('/"Index Name":\s*"([a-z_]+)"/', (string) reset($plan), $names);

        return array_values(array_unique($names[1]));
    });
}

it('defines each filter index of the activity log as signed', function (string $index, string $definition): void {
    $row = DB::selectOne('select pg_get_indexdef(indexrelid) as def, indisvalid as valid from pg_index where indexrelid = to_regclass(?)', [$index]);

    expect($row)->not->toBeNull("{$index} is missing")
        ->and($row->valid)->toBeTrue()
        ->and($row->def)->toBe("CREATE INDEX {$index} ON public.audit_logs USING btree {$definition}");
})->with([
    'first page, groups, platform, period' => ['audit_logs_created_at_id_index', '(created_at, id)'],
    'person' => ['audit_logs_actor_id_index', '(actor_id)'],
    'staff member behind a support sign-in' => ['audit_logs_impersonator_id_index', '(impersonator_id) WHERE (impersonator_id IS NOT NULL)'],
    'support actions only' => ['audit_logs_support_created_at_index', "(created_at, id) WHERE ((impersonator_id IS NOT NULL) OR ((action)::text ~~ 'support.%'::text))"],
    'one support session' => ['audit_logs_support_session_created_at_index', '(support_session_ulid, created_at, id) WHERE (support_session_ulid IS NOT NULL)'],
]);

it('plans the first page and a period on the newest-first index', function (array $filters): void {
    AuditLog::query()->create(['action' => 'cms.menu.saved', 'meta' => []]);
    ['sql' => $sql, 'bindings' => $bindings] = auditPageQuery($filters);

    expect(auditPlannedIndexes($sql, $bindings))->toBe(['audit_logs_created_at_id_index']);
})->with([
    'no filter' => [[]],
    'last 7 days' => [['when' => '7']],
]);

it('plans "Chỉ thao tác hỗ trợ" on its partial index, as the screen writes the predicate', function (): void {
    AuditLog::query()->create(['action' => 'support.session.issued', 'meta' => []]);
    ['sql' => $sql, 'bindings' => $bindings] = auditPageQuery(['support' => '1']);

    expect($sql)->toContain("(impersonator_id IS NOT NULL OR action LIKE 'support.%')")
        ->and(auditPlannedIndexes($sql, $bindings))->toBe(['audit_logs_support_created_at_index']);
});

it('plans "Mọi thao tác của phiên này" on the session index', function (): void {
    $session = (string) Str::ulid();
    AuditLog::query()->create(['action' => 'support.session.issued', 'support_session_ulid' => $session, 'meta' => []]);
    ['sql' => $sql, 'bindings' => $bindings] = auditPageQuery(['session' => $session]);

    expect(auditPlannedIndexes($sql, $bindings))->toBe(['audit_logs_support_session_created_at_index']);
});

it('plans one person as the member or the staff member behind them on both person indexes', function (): void {
    $person = User::factory()->create();
    AuditLog::query()->create(['action' => 'cms.menu.saved', 'actor_id' => $person->id, 'meta' => []]);
    ['sql' => $sql, 'bindings' => $bindings] = auditPageQuery(['people' => [$person->ulid]]);

    expect(auditPlannedIndexes($sql, $bindings))
        ->toContain('audit_logs_actor_id_index')
        ->toContain('audit_logs_impersonator_id_index');
});

/** The folded-name expression VietnameseFold::sql() writes, as Postgres prints it in an index. */
function auditFoldedName(): string
{
    $letters = fn (string $name): string => (string) (new ReflectionClassConstant(VietnameseFold::class, $name))->getValue();

    return "lower(translate((name)::text, '".$letters('FROM')."'::text, '".$letters('TO')."'::text))";
}

it('defines the name and email search indexes on the expressions the search writes', function (string $index, string $table, string $expression): void {
    $row = DB::selectOne('select pg_get_indexdef(indexrelid) as def, indisvalid as valid from pg_index where indexrelid = to_regclass(?)', [$index]);

    expect($row)->not->toBeNull("{$index} is missing")
        ->and($row->valid)->toBeTrue()
        ->and($row->def)->toBe("CREATE INDEX {$index} ON public.{$table} USING gin ({$expression} gin_trgm_ops)");
})->with([
    'person name' => fn (): array => ['users_name_fold_trgm_index', 'users', auditFoldedName()],
    'person email' => ['users_email_lower_trgm_index', 'users', 'lower((email)::text)'],
    'website name' => fn (): array => ['sites_name_fold_trgm_index', 'sites', auditFoldedName()],
    'customer name' => fn (): array => ['tenants_name_fold_trgm_index', 'tenants', auditFoldedName()],
]);

it('defines the word indexes for a search of one or two letters on the expression the search writes', function (string $index, string $table): void {
    $row = DB::selectOne('select pg_get_indexdef(indexrelid) as def, indisvalid as valid from pg_index where indexrelid = to_regclass(?)', [$index]);

    expect($row)->not->toBeNull("{$index} is missing")
        ->and($row->valid)->toBeTrue()
        ->and($row->def)->toBe("CREATE INDEX {$index} ON public.{$table} USING gin (to_tsvector('simple'::regconfig, ".auditFoldedName().'))');
})->with([
    'customer name' => ['tenants_name_fold_words_index', 'tenants'],
    'person name' => ['users_name_fold_words_index', 'users'],
]);

it('indexes the customers of an owner, which the customer search looks up', function (): void {
    $row = DB::selectOne('select pg_get_indexdef(indexrelid) as def, indisvalid as valid from pg_index where indexrelid = to_regclass(?)', ['tenants_owner_user_id_index']);

    expect($row)->not->toBeNull('tenants_owner_user_id_index is missing')
        ->and($row->valid)->toBeTrue()
        ->and($row->def)->toBe('CREATE INDEX tenants_owner_user_id_index ON public.tenants USING btree (owner_user_id)');
});

it('plans the customer search on the customer and owner indexes', function (): void {
    // 2,000 customers with an owner each, analysed: with a handful of rows (and statistics an
    // earlier test left) the planner reads the primary keys instead, whatever the other indexes
    $admin = DB::connection('pgsql_admin');
    $admin->statement("delete from users where email like 'ix-plan-%'");
    $admin->statement(<<<'SQL'
        insert into users (ulid, name, email, password, created_at, updated_at)
        select lpad(upper(to_hex(i)), 26, 'P'), 'Chủ ' || (array['Nguyễn','Trần','Lê','Phạm','Hoàng'])[1 + i % 5] || ' ' || i,
               'ix-plan-' || i || '@example.test', 'x', now(), now()
        from generate_series(1, 2000) as i
        SQL);
    $admin->statement(<<<'SQL'
        insert into tenants (ulid, name, status, settings, owner_user_id, created_at, updated_at)
        select lpad(upper(to_hex(id)), 26, 'Q'), 'Công ty ' || id, 'active', '{}', id, now(), now()
        from users where email like 'ix-plan-%'
        SQL);
    $admin->statement('vacuum analyze users, tenants');

    $sent = [];
    DB::listen(function (QueryExecuted $query) use (&$sent): void {
        if (str_starts_with($query->sql, 'with customer_matches')) {
            $sent[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        }
    });
    app(CustomerDirectory::class)->page('ix-plan-1234@', '', [], 20, '', ''); // one owner, by email
    app(CustomerDirectory::class)->page('ng', '', [], 20, '', ''); // two letters: word starts

    expect($sent)->toHaveCount(2)
        ->and(auditPlannedIndexes($sent[0]['sql'], $sent[0]['bindings']))
        ->toContain('tenants_name_fold_trgm_index')
        ->toContain('users_name_fold_trgm_index')
        ->toContain('users_email_lower_trgm_index')
        ->toContain('tenants_owner_user_id_index')
        ->and(auditPlannedIndexes($sent[1]['sql'], $sent[1]['bindings']))
        ->toContain('tenants_name_fold_words_index')
        ->toContain('users_name_fold_words_index')
        ->toContain('tenants_owner_user_id_index');

    $admin->statement("delete from users where email like 'ix-plan-%'"); // the customers go with the next seedTenancy()
});

it('plans the search for people on the trigram indexes, without a change to its SQL', function (): void {
    ['sql' => $sql, 'bindings' => $bindings] = auditPageQuery(['q' => 'Nguyễn']);

    expect($sql)->toContain(VietnameseFold::sql('name').' LIKE ?')
        ->and($sql)->toContain('lower(email) LIKE ?')
        ->and(auditPlannedIndexes($sql, $bindings))
        ->toContain('users_name_fold_trgm_index')
        ->toContain('users_email_lower_trgm_index');
});

it('matches the website name search to its trigram index', function (): void {
    // The screen's website lookup also skips websites in the trash, and with a handful of
    // websites the planner reads the live-websites index instead, whatever the name index; so
    // the name condition is planned on its own, which only the trigram index can serve.
    $sites = Site::query()->withoutGlobalScopes()->select('id')
        ->whereRaw(VietnameseFold::sql('name').' LIKE ?', [VietnameseFold::like(VietnameseFold::text('An Vinh'))]);

    expect(auditPlannedIndexes($sites->toSql(), $sites->getBindings()))->toBe(['sites_name_fold_trgm_index']);
});
