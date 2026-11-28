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

/**
 * Where $source reaches PDO past Laravel: a getPdo-family call, or a PDO handle of its own (`new PDO`,
 * `PDO::connect`, under an import alias too), or any driver subclass under `Pdo\` (`Pdo\Pgsql`…),
 * which app/ has no reason to name at all. PDO's constants and PDOException stay allowed.
 *
 * @return list<string> "line: code"
 */
function rawPdoOffenders(string $source): array
{
    $methods = ['getpdo', 'getreadpdo', 'getrawpdo', 'getrawreadpdo'];
    $names = [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED];
    $offenders = [];

    $tokens = array_values(array_filter(
        PhpToken::tokenize($source),
        fn (PhpToken $t): bool => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML]),
    ));
    $text = fn (int $i): string => strtolower(ltrim($tokens[$i]->text ?? '', '\\'));

    // `use PDO as Db;` makes Db another name for PDO.
    $pdoNames = ['pdo'];
    foreach ($tokens as $i => $token) {
        if ($token->is(T_USE) && $text($i + 1) === 'pdo' && ($tokens[$i + 2] ?? null)?->is(T_AS)) {
            $pdoNames[] = $text($i + 3);
        }
    }

    foreach ($tokens as $i => $token) {
        $name = $text($i);
        $previous = $text($i - 1);
        $isName = $token->is($names);

        $getsHandle = $token->is(T_STRING) && in_array($name, $methods, true) && in_array($previous, ['->', '?->', '::'], true);
        $opensPdo = $isName && in_array($name, $pdoNames, true)
            && ($previous === 'new' || ($text($i + 1) === '::' && $text($i + 2) === 'connect'));
        // `Pdo\Pgsql` is one name token; a group import `use Pdo\{…}` is `Pdo` then a separator.
        $namesDriverClass = $isName && (str_starts_with($name, 'pdo\\') || ($name === 'pdo' && ($tokens[$i + 1] ?? null)?->is(T_NS_SEPARATOR)));

        if ($getsHandle || $opensPdo || $namesDriverClass) {
            $offenders[] = $token->line.': '.$token->text;
        }
    }

    return $offenders;
}

it('sends no statement on the raw PDO handle anywhere in app/', function (): void {
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $path = 'app/'.$file->getRelativePathname();
        if (array_key_exists($path, RAW_PDO_ALLOWED)) {
            continue;
        }

        foreach (rawPdoOffenders($file->getContents()) as $offender) {
            $offenders[] = $path.':'.$offender;
        }
    }

    expect($offenders)->toBe([]);
});

it('flags a planted raw PDO call', function (string $planted): void {
    expect(rawPdoOffenders("<?php\n".$planted))->not->toBe([]);
})->with([
    'getPdo' => ['DB::connection()->getPdo()->exec("select 1");'],
    'nullsafe getReadPdo' => ['$connection?->getReadPdo()->query("select 1");'],
    'new PDO' => ['new PDO("pgsql:host=db");'],
    'new \\PDO' => ['new \\PDO("pgsql:host=db");'],
    'PDO::connect' => ['PDO::connect("pgsql:host=db");'],
    '\\PDO::connect' => ['\\PDO::connect("pgsql:host=db");'],
    'new Pdo\\Pgsql' => ['new Pdo\\Pgsql("pgsql:host=db");'],
    'new \\Pdo\\Pgsql' => ['new \\Pdo\\Pgsql("pgsql:host=db");'],
    'Pdo\\Pgsql::connect' => ['\\Pdo\\Pgsql::connect("pgsql:host=db");'],
    'imported Pdo\\Pgsql' => ['use Pdo\\Pgsql; new Pgsql("pgsql:host=db");'],
    'group-imported Pdo subclass' => ['use Pdo\\{Pgsql}; Pgsql::connect("pgsql:host=db");'],
    'PDO under an alias, new' => ['use PDO as Db; new Db("pgsql:host=db");'],
    'PDO under an alias, connect' => ['use PDO as Db; Db::connect("pgsql:host=db");'],
]);

it('lets PDO constants, PDOException and comments through', function (): void {
    $source = <<<'PHP'
        <?php
        use PDO;
        use PDOException;
        // new PDO("pgsql:") and PDO::connect() in a comment are not code
        $style = PDO::FETCH_ASSOC;
        try {
        } catch (PDOException $e) {
        }
        $label = 'new PDO';
        PHP;

    expect(rawPdoOffenders($source))->toBe([]);
});

arch('the aborted-transaction sentinel never leaves Tenancy::enter')
    ->expect(TenantTransactionAborted::class)
    ->toOnlyBeUsedIn(Tenancy::class);
