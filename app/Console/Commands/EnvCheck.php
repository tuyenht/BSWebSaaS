<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Compares a live .env against .env.example (the canonical key set) and reports keys that are
 * MISSING (documented in the template, absent from .env — the exact drift that silently broke the
 * R2 upload / the SMTP scheme on this project) and EXTRA (present in .env, undocumented in the
 * template). Exits non-zero on any missing key so it can gate a deploy. Compares KEY NAMES ONLY —
 * never reads or prints a value, so it is safe to run against a secret .env.
 */
class EnvCheck extends Command
{
    protected $signature = 'env:check {--env= : .env to check (default: base_path/.env)} {--example= : template (default: base_path/.env.example)}';

    protected $description = 'Check a .env against .env.example — report missing / extra keys (non-zero exit on missing)';

    public function handle(): int
    {
        $envPath = $this->path('env', base_path('.env'));
        $examplePath = $this->path('example', base_path('.env.example'));

        foreach (['.env' => $envPath, '.env.example' => $examplePath] as $label => $path) {
            if (! is_file($path)) {
                $this->error("Không tìm thấy {$label}: {$path}");

                return self::FAILURE;
            }
        }

        $envKeys = $this->keys($envPath);
        $missing = array_values(array_diff($this->keys($examplePath), $envKeys));
        $extra = array_values(array_diff($envKeys, $this->keys($examplePath)));
        sort($missing);
        sort($extra);

        if ($missing !== []) {
            $this->error('THIẾU '.count($missing).' key (.env.example có, .env chưa có):');
            foreach ($missing as $k) {
                $this->line('  - '.$k);
            }
        }
        if ($extra !== []) {
            $this->warn('THỪA '.count($extra).' key (.env có, .env.example chưa ghi — nên bổ sung template):');
            foreach ($extra as $k) {
                $this->line('  - '.$k);
            }
        }

        if ($missing !== []) {
            $this->error('env:check THẤT BẠI — bổ sung các key thiếu vào .env.');

            return self::FAILURE;
        }

        $this->info('env:check OK — .env đủ mọi key của .env.example'.($extra !== [] ? ' ('.count($extra).' key thừa).' : '.'));

        return self::SUCCESS;
    }

    private function path(string $option, string $default): string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Extract KEY names (never values) from a dotenv file — lines like `KEY=…`, ignoring comments/blank.
     *
     * @return list<string>
     */
    private function keys(string $path): array
    {
        $keys = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)=/', $line, $m) === 1) {
                $keys[] = $m[1];
            }
        }

        return array_values(array_unique($keys));
    }
}
