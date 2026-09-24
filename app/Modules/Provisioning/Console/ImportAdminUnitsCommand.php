<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Tenancy\Models\Province;
use App\Modules\Tenancy\Models\Ward;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads Vietnam's two-level administrative units (in force since 01/07/2025 — province/city and
 * ward/commune, the district level abolished) into the GLOBAL `provinces` + `wards` catalogs, from
 * a CSV of the official list — Quyết định 19/2025/QĐ-TTg "Danh mục và mã số các đơn vị hành chính
 * Việt Nam" (34 provincial units with 2-digit codes, 3,321 commune units with 5-digit codes) —
 * with the header `level,code,name,parent_code,valid_from`:
 *  - level: `province` or `ward`;
 *  - code: the official unit code; a ward's `parent_code` is its province's code;
 *  - valid_from: the date the list takes effect (Y-m-d).
 *
 * The file may come straight from Excel: a UTF-8 byte-order mark, Windows line ends and the `;`
 * separator Excel uses under Vietnamese regional settings are all accepted.
 *
 * Upserts by the official code, so re-running with a newer list renames and adds units. With
 * --retire-missing, a unit absent from the file gets valid_to = today instead of being deleted:
 * site profiles may still point at it. One transaction — a bad row rolls the whole file back.
 */
final class ImportAdminUnitsCommand extends Command
{
    #[\Override]
    protected $signature = 'admin-units:import
        {file : CSV path — header level,code,name,parent_code,valid_from}
        {--retire-missing : Mark units absent from the file as retired (valid_to = today)}';

    #[\Override]
    protected $description = 'Load the official two-level administrative units (provinces + wards) from a CSV.';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $rows = $this->readCsv($path);
        if ($rows === null) {
            return self::FAILURE;
        }

        $provinces = array_values(array_filter($rows, fn (array $row): bool => $row['level'] === 'province'));
        $wards = array_values(array_filter($rows, fn (array $row): bool => $row['level'] === 'ward'));

        DB::transaction(function () use ($provinces, $wards): void {
            foreach ($provinces as $row) {
                Province::query()->updateOrCreate(
                    ['code' => $row['code']],
                    ['name' => $row['name'], 'valid_from' => $row['valid_from'], 'valid_to' => null],
                );
            }

            $provinceIds = Province::query()->pluck('id', 'code');
            foreach ($wards as $row) {
                $provinceId = $provinceIds[$row['parent_code']] ?? null;
                if ($provinceId === null) {
                    throw new RuntimeException("Ward {$row['code']} names an unknown province {$row['parent_code']}.");
                }

                Ward::query()->updateOrCreate(
                    ['code' => $row['code']],
                    ['province_id' => $provinceId, 'name' => $row['name'], 'valid_from' => $row['valid_from'], 'valid_to' => null],
                );
            }

            if ($this->option('retire-missing')) {
                Province::query()->whereNotIn('code', array_column($provinces, 'code'))->whereNull('valid_to')->update(['valid_to' => now()->toDateString()]);
                Ward::query()->whereNotIn('code', array_column($wards, 'code'))->whereNull('valid_to')->update(['valid_to' => now()->toDateString()]);
            }
        });

        $this->info(sprintf('Imported %d provinces and %d wards.', count($provinces), count($wards)));

        return self::SUCCESS;
    }

    /**
     * @return list<array{level: string, code: string, name: string, parent_code: string, valid_from: string}>|null
     */
    private function readCsv(string $path): ?array
    {
        $handle = is_readable($path) ? fopen($path, 'r') : false;
        if ($handle === false) {
            $this->error("Cannot read {$path}.");

            return null;
        }

        // Excel's "CSV UTF-8" starts with a byte-order mark, and under Vietnamese regional settings
        // it separates fields with ';' — read the header line raw to settle both.
        $first = fgets($handle);
        $first = is_string($first) ? (string) preg_replace('/^\xEF\xBB\xBF/', '', rtrim($first, "\r\n")) : '';
        $separator = str_contains($first, ';') && ! str_contains($first, ',') ? ';' : ',';
        $header = array_map(fn (?string $cell): string => trim((string) $cell), str_getcsv($first, separator: $separator, escape: ''));

        if ($header !== ['level', 'code', 'name', 'parent_code', 'valid_from']) {
            fclose($handle);
            $this->error('Expected the header: level,code,name,parent_code,valid_from');

            return null;
        }

        $rows = [];
        $line = 1;
        while (($fields = fgetcsv($handle, separator: $separator, escape: '')) !== false) {
            $line++;
            if ($fields === [null]) {
                continue;   // blank line
            }

            [$level, $code, $name, $parentCode, $validFrom] = array_map(fn (mixed $value): string => trim((string) $value), array_pad($fields, 5, ''));

            if (! in_array($level, ['province', 'ward'], true) || $code === '' || $name === ''
                || ($level === 'ward' && $parentCode === '') || preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom) !== 1) {
                fclose($handle);
                $this->error("Line {$line} is not a valid unit row.");

                return null;
            }

            $rows[] = ['level' => $level, 'code' => $code, 'name' => $name, 'parent_code' => $parentCode, 'valid_from' => $validFrom];
        }

        fclose($handle);

        return $rows;
    }
}
