<?php

namespace App\Console\Commands;

use App\Modules\Shared\Support\Cast;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The slug and invitation-address lookups compare the citext column read as text against a
 * lower-cased value, so the index can serve them under RLS; a row stored with capitals is then not
 * found. This reports, per column, how many rows still hold capitals, with the website and row ids
 * of a few (never the values), and exits non-zero while any remain. It changes nothing; the
 * lower-case CHECKs are added by a later migration once it reports none.
 *
 * Runs on the privileged connection: the tables are under RLS, which shows app_user no rows outside
 * a website.
 */
class CheckLowerCaseKeys extends Command
{
    /** @var list<array{string, string}> [table, column] */
    public const array COLUMNS = [
        ['taxonomy_translations', 'slug'],
        ['entry_translations', 'slug'],
        ['product_translations', 'slug'],
        ['invitations', 'email'],
        ['media_folders', 'slug'],
    ];

    private const int SAMPLE = 5;

    #[\Override]
    protected $signature = 'db:lower-case-keys';

    #[\Override]
    protected $description = 'Report rows whose slug or invitation address holds capitals';

    public function handle(): int
    {
        $db = DB::connection('pgsql_admin');
        $mixed = 0;

        foreach (self::COLUMNS as [$table, $column]) {
            $count = Cast::int($db->scalar(
                "SELECT count(*) FROM {$table} WHERE {$column}::text <> lower({$column}::text)",
            ));
            $mixed += $count;

            $this->line("{$table}.{$column}: {$count} rows with capitals");

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

        if ($mixed > 0) {
            $this->error("{$mixed} rows hold capitals: lower-case them before adding the lower-case CHECKs.");

            return self::FAILURE;
        }

        $this->info('No row holds capitals.');

        return self::SUCCESS;
    }
}
