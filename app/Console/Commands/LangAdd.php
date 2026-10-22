<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Shared\Support\Cast;
use Illuminate\Console\Command;

/**
 * Scaffolds a new JSON language by cloning the canonical locale (en) — guaranteeing the
 * exact same key set so translators only replace values. The admin language switcher then
 * picks the new file up automatically (it scans lang/*.json).
 */
class LangAdd extends Command
{
    #[\Override]
    protected $signature = 'lang:add {code : New locale code, e.g. ko} {--base=en} {--blank : Empty values instead of copying base text}';

    #[\Override]
    protected $description = 'Create a new language JSON by cloning the canonical locale (keys guaranteed in sync)';

    public function handle(): int
    {
        $code = Cast::string($this->argument('code'));
        $base = Cast::string($this->option('base'));
        $dir = lang_path();
        $baseFile = $dir.DIRECTORY_SEPARATOR.$base.'.json';
        $target = $dir.DIRECTORY_SEPARATOR.$code.'.json';

        if (! is_file($baseFile)) {
            $this->error("Base locale not found: {$base}.json");

            return self::FAILURE;
        }
        if (is_file($target)) {
            $this->error("{$code}.json already exists.");

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($baseFile), true);
        if (! is_array($data)) {
            $this->error("Could not parse {$base}.json");

            return self::FAILURE;
        }

        if ($this->option('blank')) {
            $data = array_map(static fn (): string => '', $data);
        }

        file_put_contents(
            $target,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );

        $this->info("Created {$code}.json with ".count($data)." keys (cloned from {$base}). Translate the values, then run `php artisan lang:check`.");
        $this->comment("Tip: add a native label in config/locales.php → labels.{$code} for a nicer switcher name.");

        return self::SUCCESS;
    }
}
