<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The two «Mẫu thư» tables. mail_templates is a website's own wording (site_id, row-level security
 * forced, the one policy reading app.current_site_id, the app role's grants); platform_mail_templates
 * is the platform's (no site_id, no row-level security: a global table the app role only reads). down() drops each table;
 * up() builds it again on top of that, and twice in a row, as after a half-finished deploy.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** @return array{exists: bool, rls: ?object, policies: list<string>, grants: list<string>, checks: list<string>} */
function mailTemplatesMigShape(string $table): array
{
    $db = DB::connection('pgsql_admin');

    return [
        'exists' => $db->selectOne('select to_regclass(?) is not null as e', ['public.'.$table])->e,
        'rls' => $db->selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relkind = ?', [$table, 'r']),
        'policies' => array_map(fn ($r): string => $r->policyname, $db->select('select policyname from pg_policies where tablename = ? order by policyname', [$table])),
        'grants' => array_map(fn ($r): string => $r->privilege_type, $db->select("select privilege_type from information_schema.role_table_grants where table_name = ? and grantee = 'app_user' order by privilege_type", [$table])),
        'checks' => array_map(fn ($r): string => $r->conname, $db->select("select conname from pg_constraint where conrelid = to_regclass(?) and contype in ('c', 'u') order by conname", ['public.'.$table])),
    ];
}

it('rolls the «Mẫu thư» tables back and forward on a real database', function (): void {
    $site = require base_path('database/migrations/2104_01_01_000000_create_mail_templates.php');
    $platform = require base_path('database/migrations/2105_01_01_000000_create_platform_mail_templates.php');
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');

    try {
        $grants = ['DELETE', 'INSERT', 'SELECT', 'UPDATE'];
        $siteShape = mailTemplatesMigShape('mail_templates');
        expect($siteShape['exists'])->toBeTrue()
            ->and($siteShape['rls']?->relrowsecurity)->toBeTrue()
            ->and($siteShape['rls']?->relforcerowsecurity)->toBeTrue()
            ->and($siteShape['policies'])->toBe(['mail_templates_site_isolation'])
            ->and($siteShape['grants'])->toBe($grants)
            ->and($siteShape['checks'])->toBe(['mail_templates_body_length_check', 'mail_templates_key_check', 'mail_templates_locale_check', 'mail_templates_site_key_locale_unique']);
        $platformShape = mailTemplatesMigShape('platform_mail_templates');
        expect($platformShape['exists'])->toBeTrue()
            ->and($platformShape['rls']?->relrowsecurity)->toBeFalse()
            ->and($platformShape['policies'])->toBe([])
            ->and($platformShape['grants'])->toBe(['SELECT']);   // read only until the platform's tab

        $platform->down();
        $site->down();
        expect(mailTemplatesMigShape('mail_templates')['exists'])->toBeFalse()
            ->and(mailTemplatesMigShape('platform_mail_templates')['exists'])->toBeFalse();
        $site->down();      // twice is harmless
        $platform->down();

        $site->up();
        $site->up();        // twice is harmless too
        $platform->up();
        $platform->up();
        expect(mailTemplatesMigShape('mail_templates'))->toEqual($siteShape)
            ->and(mailTemplatesMigShape('platform_mail_templates'))->toEqual($platformShape);
    } finally {
        DB::setDefaultConnection($previous);
    }
});

it('refuses a template key, language or body the screen would never write', function (): void {
    $db = DB::connection('pgsql_admin');
    $row = ['template_key' => 'reset', 'locale' => 'vi', 'subject' => 'Chào', 'body' => '<p>x</p>'];

    foreach ([['template_key' => 'Reset;drop'], ['locale' => 'vi_VN!'], ['body' => str_repeat('a', 60001)], ['subject' => str_repeat('a', 201)]] as $bad) {
        expect(fn () => $db->table('platform_mail_templates')->insert([...$row, ...$bad]))->toThrow(QueryException::class);
    }
    $db->table('platform_mail_templates')->insert($row);
    expect(fn () => $db->table('platform_mail_templates')->insert($row))->toThrow(QueryException::class)
        ->and(fn () => $db->table('mail_templates')->insert(['site_id' => 1, ...$row, 'template_key' => 'Bad key']))->toThrow(QueryException::class);
    $db->table('mail_templates')->insert(['site_id' => 1, ...$row]);
    expect(fn () => $db->table('mail_templates')->insert(['site_id' => 1, ...$row]))->toThrow(QueryException::class);
    $db->table('mail_templates')->insert(['site_id' => 2, ...$row]);   // the same key in another website
    $db->statement('truncate platform_mail_templates restart identity');
});
