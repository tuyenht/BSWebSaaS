<?php

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Context\TenantTransactionAborted;
use App\Modules\Tenancy\Database\TrackedPostgresConnection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

/*
 * Tenancy::enter rolls back a site transaction PostgreSQL aborted only when the connection counted a
 * failed statement. So every pgsql connection must be the counting one, and no code in app/ may send
 * a statement past Laravel on the raw PDO handle, where a failure would go uncounted and the
 * transaction would "commit" (silently roll back) with its after-commit work running.
 */

/** Files allowed to touch the raw PDO handle: the file => why. */
const RAW_PDO_ALLOWED = [
    'app/Modules/Tenancy/Database/TrackedPostgresConnection.php' => 'the counting connection itself',
];

it('resolves every pgsql connection to the counting connection', function (): void {
    expect(DB::connection())->toBeInstanceOf(TrackedPostgresConnection::class)
        ->and(DB::connection('pgsql'))->toBeInstanceOf(TrackedPostgresConnection::class)
        ->and(DB::connection('pgsql_admin'))->toBeInstanceOf(TrackedPostgresConnection::class);
});

it('sends no statement on the raw PDO handle anywhere in app/', function (): void {
    $methods = ['getpdo', 'getreadpdo', 'getrawpdo', 'getrawreadpdo'];
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $path = 'app/'.$file->getRelativePathname();
        if (array_key_exists($path, RAW_PDO_ALLOWED)) {
            continue;
        }

        $tokens = array_values(array_filter(
            PhpToken::tokenize($file->getContents()),
            fn (PhpToken $t): bool => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML]),
        ));

        foreach ($tokens as $i => $token) {
            $name = strtolower(ltrim($token->text, '\\'));
            $previous = $tokens[$i - 1]->text ?? '';
            $isCall = $token->is(T_STRING) && in_array($name, $methods, true) && in_array($previous, ['->', '?->', '::'], true);
            $isNewPdo = $previous === 'new' && $token->is([T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED]) && $name === 'pdo';

            if ($isCall || $isNewPdo) {
                $offenders[] = $path.':'.$token->line.' '.$token->text;
            }
        }
    }

    expect($offenders)->toBe([]);
});

arch('the aborted-transaction sentinel never leaves Tenancy::enter')
    ->expect(TenantTransactionAborted::class)
    ->toOnlyBeUsedIn(Tenancy::class);
