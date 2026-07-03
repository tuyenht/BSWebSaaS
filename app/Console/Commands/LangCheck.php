<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Validates the JSON language files against the canonical locale (en): reports missing
 * keys, orphan/extra keys, duplicate keys, and values identical to the base (likely
 * untranslated). Keeps every language in lockstep so cloning + translating stays safe.
 * Exits non-zero on structural problems (CI-friendly).
 */
class LangCheck extends Command
{
    #[\Override]
    protected $signature = 'lang:check {--base=en : Canonical locale to compare against}';

    #[\Override]
    protected $description = 'Check JSON language files for missing / extra / duplicate / untranslated keys';

    public function handle(): int
    {
        $base = (string) $this->option('base');
        $dir = lang_path();
        $files = glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [];

        $baseFile = $dir.DIRECTORY_SEPARATOR.$base.'.json';
        if (! is_file($baseFile)) {
            $this->error("Base locale file not found: {$base}.json");

            return self::FAILURE;
        }

        $baseData = $this->load($baseFile);
        $baseKeys = array_keys($baseData);
        sort($baseKeys);
        $problems = 0;

        foreach ($files as $file) {
            $code = basename($file, '.json');
            $data = $this->load($file);
            $keys = array_keys($data);

            $dupes = $this->duplicateKeys($file);
            if ($dupes !== []) {
                $problems++;
                $this->error("[{$code}] duplicate keys: ".implode(', ', $dupes));
            }

            if ($code === $base) {
                continue;
            }

            $missing = array_values(array_diff($baseKeys, $keys));
            $extra = array_values(array_diff($keys, $baseKeys));
            $untranslated = [];
            foreach ($baseData as $k => $v) {
                if (array_key_exists($k, $data) && $data[$k] === $v && $v !== '') {
                    $untranslated[] = $k;
                }
            }

            if ($missing !== []) {
                $problems++;
                $this->error("[{$code}] MISSING ".count($missing).' keys: '.$this->preview($missing));
            }
            if ($extra !== []) {
                $problems++;
                $this->error("[{$code}] EXTRA ".count($extra)." keys (not in {$base}): ".$this->preview($extra));
            }
            if ($untranslated !== []) {
                $this->warn("[{$code}] ".count($untranslated)." value(s) identical to {$base} (maybe untranslated)");
            }
            if ($missing === [] && $extra === []) {
                $this->info("[{$code}] OK — ".count($keys)." keys in sync with {$base}".($untranslated !== [] ? ' ('.count($untranslated).' to translate)' : ''));
            }
        }

        if ($problems > 0) {
            $this->error("lang:check found {$problems} problem(s).");

            return self::FAILURE;
        }

        $this->info('lang:check passed — all locales in sync.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function load(string $file): array
    {
        $json = json_decode((string) file_get_contents($file), true);

        if (! is_array($json)) {
            return [];
        }

        /** @var array<string, string> $json */
        return $json;
    }

    /**
     * @param  list<string>  $keys
     */
    private function preview(array $keys): string
    {
        return implode(', ', array_slice($keys, 0, 20)).(count($keys) > 20 ? ' …' : '');
    }

    /**
     * @return list<string>
     */
    private function duplicateKeys(string $file): array
    {
        preg_match_all('/^\s*"([^"]+)"\s*:/m', (string) file_get_contents($file), $m);
        $counts = array_count_values($m[1]);

        return array_keys(array_filter($counts, static fn (int $c): bool => $c > 1));
    }
}
