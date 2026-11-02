<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Provisioning\ContentPack\Backup;
use App\Modules\Provisioning\ContentPack\PackReader;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Content-pack test helpers: copy a pack (the generic `demo-co` fixture, or the real one under
 * database/content-packs) into a scratch folder the loader reads from, edit it in place for a
 * "v2", run `site:load-content`, and read back what it printed.
 */
final class ContentPacks
{
    public const string HOST = 'demo.platform.test';

    /** @var list<string> scratch roots made by use(), removed by cleanup() */
    private static array $roots = [];

    /** Copy a pack into a fresh scratch root and point the loader (and its backups) at it; returns the pack's folder. */
    public static function use(string $code = 'demo-co', ?string $from = null): string
    {
        $root = storage_path('framework/testing/content-packs/'.Str::random(10));
        self::$roots[] = $root;
        $source = ($from ?? base_path('tests/Fixtures/content-packs')).'/'.$code;
        File::copyDirectory($source, $root.'/'.$code);
        app()->instance(PackReader::class, new PackReader($root));
        app()->bind(Backup::class, fn (): Backup => new Backup(app(CurrentSite::class), $root.'/backups'));

        return $root.'/'.$code;
    }

    /** Remove every scratch copy (and its backups) made since the last cleanup. */
    public static function cleanup(): void
    {
        foreach (self::$roots as $root) {
            File::deleteDirectory($root);
        }
        self::$roots = [];
    }

    /** Where the loader writes backups in tests (next to the copied pack). */
    public static function backups(string $folder): string
    {
        return dirname($folder).'/backups';
    }

    /**
     * Rewrite one JSON file of a copied pack.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $edit
     */
    public static function edit(string $folder, string $file, callable $edit): void
    {
        $path = $folder.'/'.$file;
        $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        file_put_contents($path, json_encode($edit($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * Change one item of a copied pack (found by key).
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    public static function item(string $folder, string $file, string $key, callable $change): void
    {
        self::edit($folder, $file, function (array $data) use ($key, $change): array {
            $found = false;
            foreach ($data['items'] as $index => $item) {
                if ($item['key'] === $key) {
                    $data['items'][$index] = $change($item);
                    $found = true;
                }
            }
            if (! $found) {
                throw new RuntimeException("No item {$key} in {$file}.");
            }

            return $data;
        });
    }

    /**
     * Run the loader; returns [exit code, output].
     *
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: string}
     */
    public static function run(string $code = 'demo-co', array $options = []): array
    {
        $exit = Artisan::call('site:load-content', ['pack' => $code, '--host' => self::HOST] + $options);

        return [$exit, Artisan::output()];
    }

    public static function fingerprint(string $output): string
    {
        if (preg_match('/Plan fingerprint: ([0-9a-f]{12})/', $output, $m) !== 1) {
            throw new RuntimeException("No fingerprint in:\n".$output);
        }

        return $m[1];
    }

    /**
     * Dry run, then apply what it printed; returns the apply's [exit code, output].
     *
     * @param  list<string>  $force
     * @return array{0: int, 1: string}
     */
    public static function load(string $code = 'demo-co', array $force = []): array
    {
        [$exit, $output] = self::run($code, ['--force-item' => $force]);
        if ($exit !== 0) {
            throw new RuntimeException("Dry run failed:\n".$output);
        }

        return self::run($code, ['--apply' => true, '--plan' => self::fingerprint($output), '--force-item' => $force]);
    }

    /** The verdict the last plan printed for one key. */
    public static function verdict(string $output, string $key): ?string
    {
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\S*\s+(\S+(?: \(forced\))?)\s+'.preg_quote($key, '/').'(\s|$)/', $line, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /** Run $callback in the site's tenant context. */
    public static function in(int $siteId, callable $callback): mixed
    {
        return app(Tenancy::class)->enter($siteId, $callback(...));
    }

    /** Every INSERT / UPDATE / DELETE the callback issues. @return list<string> */
    public static function writes(callable $callback): array
    {
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });
        $callback();

        return $writes;
    }
}
