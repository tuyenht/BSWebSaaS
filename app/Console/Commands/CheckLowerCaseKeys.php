<?php

namespace App\Console\Commands;

use App\Modules\Shared\Support\Cast;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * The slug and invitation-address lookups compare the citext column read as text against a
 * lower-cased value, so the index can serve them under RLS; a row stored with capitals is then not
 * found. Each of these columns has a lower-case CHECK, added NOT VALID because rows stored before it
 * were never checked. This reports, per column, how many rows still hold capitals (with a few row
 * ids, never the values) and whether the CHECK is validated. With --validate it validates every CHECK
 * not yet validated, but only when no column has capitals left; otherwise it changes nothing. It
 * exits non-zero while a row holds capitals or a CHECK is missing.
 *
 * Runs on the privileged connection: the tables are under RLS, which shows app_user no rows outside a
 * website, and only the table owner may validate a constraint. VALIDATE takes a lock that lets reads
 * and writes go on while it scans the table.
 */
class CheckLowerCaseKeys extends Command
{
    /** @var array<string, array{string, string}> constraint name => [table, column] */
    public const array CHECKS = [
        'taxonomy_translations_slug_lower_case' => ['taxonomy_translations', 'slug'],
        'entry_translations_slug_lower_case' => ['entry_translations', 'slug'],
        'product_translations_slug_lower_case' => ['product_translations', 'slug'],
        'invitations_email_lower_case' => ['invitations', 'email'],
    ];

    private const int SAMPLE = 5;

    #[\Override]
    protected $signature = 'db:lower-case-keys {--validate : validate the lower-case CHECKs when no row holds capitals}';

    #[\Override]
    protected $description = 'Report rows whose slug or invitation address holds capitals; optionally validate the lower-case CHECKs';

    public function handle(): int
    {
        $db = DB::connection('pgsql_admin');
        $mixed = 0;
        $missing = 0;
        $pending = [];

        foreach (self::CHECKS as $constraint => [$table, $column]) {
            $count = Cast::int($db->scalar(
                "SELECT count(*) FROM {$table} WHERE {$column}::text <> lower({$column}::text)",
            ));
            $mixed += $count;

            $state = $this->checkState($db, $table, $constraint);
            if ($state === null) {
                $missing++;
            } elseif (! $state) {
                $pending[$constraint] = $table;
            }

            $label = match ($state) {
                null => 'CHECK missing',
                true => 'CHECK validated',
                false => 'CHECK not validated',
            };
            $this->line("{$table}.{$column}: {$count} rows with capitals · {$label}");

            if ($count > 0) {
                $rows = $db->table($table)
                    ->select(['site_id', 'id'])
                    ->whereRaw("{$column}::text <> lower({$column}::text)")
                    ->orderBy('site_id')->orderBy('id')
                    ->limit(self::SAMPLE)
                    ->get();
                foreach ($rows as $row) {
                    $this->line('  site '.Cast::int($row->site_id).', row '.Cast::int($row->id));
                }
            }
        }

        if (! $this->option('validate')) {
            return $mixed > 0 || $missing > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($missing > 0) {
            $this->error('A lower-case CHECK is missing: run the migrations first. Nothing validated.');

            return self::FAILURE;
        }

        if ($mixed > 0) {
            $this->error("{$mixed} rows still hold capitals: lower-case them first. Nothing validated.");

            return self::FAILURE;
        }

        foreach ($pending as $constraint => $table) {
            $db->statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
            $this->info("{$table}: {$constraint} validated");
        }

        $this->info('Every lower-case CHECK is validated.');

        return self::SUCCESS;
    }

    /** Whether the CHECK is validated; null when it does not exist. */
    private function checkState(ConnectionInterface $db, string $table, string $constraint): ?bool
    {
        $validated = $db->scalar(
            'SELECT convalidated FROM pg_constraint WHERE conrelid = to_regclass(?) AND conname = ?',
            [$table, $constraint],
        );

        return $validated === null ? null : $validated === true;
    }
}
