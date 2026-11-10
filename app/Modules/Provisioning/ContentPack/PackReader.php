<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

use App\Modules\Shared\Support\Cast;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reads a content pack folder (`database/content-packs/<code>/`) into a Pack: pack.json plus the
 * optional item files, each `{"items": [...]}` (media.json: `{"slots": [...]}`). Only the shape
 * needed to sort items into kinds is checked here; PackValidator checks the rest. The content hash
 * covers every file in the folder, so any change to the pack changes the plan fingerprint.
 */
final readonly class PackReader
{
    /** Item file → kind of its items (settings.json also holds the one `profile` item). */
    private const array FILES = [
        'settings.json' => 'setting',
        'taxonomies.json' => 'taxonomy',
        'products.json' => 'product',
        'posts.json' => 'post',
        'pages.json' => 'page',
        'forms.json' => 'form',
        'menus.json' => 'menu',
        'redirects.json' => 'redirect',
    ];

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? database_path('content-packs'), '/');
    }

    /**
     * @throws PackRejected the folder is missing or a file is not valid JSON of the expected shape
     */
    public function read(string $code): Pack
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/', $code) !== 1) {
            throw new PackRejected("Refused: '{$code}' is not a pack name (lowercase letters, digits and hyphens).");
        }

        $dir = $this->root.'/'.$code;
        if (! is_file($dir.'/pack.json')) {
            throw new PackRejected("Refused: no content pack '{$code}' (expected {$dir}/pack.json).");
        }

        $errors = [];
        $header = $this->decode($dir, 'pack.json', $errors);

        $byKind = array_fill_keys(Pack::KINDS, []);
        foreach (self::FILES as $file => $kind) {
            if (! is_file($dir.'/'.$file)) {
                continue;
            }
            $decoded = $this->decode($dir, $file, $errors);
            $items = $decoded['items'] ?? null;
            if (! is_array($items) || ! array_is_list($items)) {
                $errors[] = "{$file}: expected an object with an \"items\" list.";

                continue;
            }
            foreach ($items as $index => $raw) {
                $data = Cast::arr($raw);
                $key = $data['key'] ?? null;
                if (! is_array($raw) || ! is_string($key) || $key === '') {
                    $errors[] = "{$file}: item ".($index + 1).' has no "key".';

                    continue;
                }
                $itemKind = $kind === 'setting' && $key === 'profile' ? 'profile' : $kind;
                $byKind[$itemKind][] = new PackItem($itemKind, $key, $data);
            }
        }

        $slots = [];
        if (is_file($dir.'/media.json')) {
            $media = $this->decode($dir, 'media.json', $errors);
            $list = $media['slots'] ?? null;
            if (! is_array($list) || ! array_is_list($list)) {
                $errors[] = 'media.json: expected an object with a "slots" list.';
            } else {
                foreach ($list as $index => $raw) {
                    $slot = Cast::arr($raw);
                    $name = $slot['slot'] ?? null;
                    if (! is_string($name) || $name === '') {
                        $errors[] = 'media.json: slot '.($index + 1).' has no "slot" name.';

                        continue;
                    }
                    if (isset($slots[$name])) {
                        $errors[] = "media.json: slot '{$name}' is listed twice.";
                    }
                    $slots[$name] = $slot;
                }
            }
        }

        if ($errors !== []) {
            throw new PackRejected("Refused: the pack '{$code}' cannot be read.", $errors);
        }

        return new Pack(
            code: Cast::string($header['code'] ?? null),
            version: is_int($header['version'] ?? null) ? $header['version'] : 0,
            hosts: array_map(strtolower(...), Cast::strings($header['hosts'] ?? null)),
            defaultLocale: Cast::string($header['default_locale'] ?? null),
            locales: Cast::strings($header['locales'] ?? null),
            items: array_merge(...array_values($byKind)),
            slots: $slots,
            dir: $dir,
            contentHash: $this->contentHash($dir),
        );
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function decode(string $dir, string $file, array &$errors): array
    {
        try {
            $decoded = json_decode((string) file_get_contents($dir.'/'.$file), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $errors[] = "{$file}: not valid JSON ({$e->getMessage()}).";

            return [];
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            $errors[] = "{$file}: expected a JSON object.";

            return [];
        }

        return Cast::arr($decoded);
    }

    /** SHA-256 over every file of the pack (path + content hash), in path order. */
    private function contentHash(string $dir): string
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $path = $file->getPathname();
                $files[substr($path, strlen($dir) + 1)] = (string) hash_file('sha256', $path);
            }
        }
        ksort($files, SORT_STRING);

        return CanonicalHash::of($files);
    }
}
