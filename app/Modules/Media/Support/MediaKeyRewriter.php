<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use stdClass;

/**
 * Rewrites the storage keys a stored JSON document names, after the files moved (media:rekey-site).
 * A string is rewritten when it IS a moved key (`an-vinh/hero/x-ab12.jpg`) or a full address whose
 * path ends with one (`https://files.example/an-vinh/hero/x-ab12.jpg?w=640`, the base path and the
 * query kept); every other string is left as it is, so text that merely mentions a key never
 * changes. Strings that look like a key under one of the website's old folders but name no moved
 * file are counted as unmatched (the caller reports them), never guessed at.
 *
 * Works on the document as json_decode(…, false) returns it, so `{}` stays an object and `[]` a list.
 * Stateless apart from the counters of one rewrite() call; Octane-safe (made per run, never bound).
 */
final class MediaKeyRewriter
{
    /** References rewritten by the last rewrite() call. */
    public int $rewritten = 0;

    /** @var list<string> strings under an old folder that name no moved file, from the last call */
    public array $unmatched = [];

    /** @var array<string, true> */
    private readonly array $folders;

    /**
     * @param  array<string, string>  $moves  old storage key => new storage key
     * @param  list<string>  $oldFolders  the website's old first folders, for the unmatched count
     */
    public function __construct(private readonly array $moves, array $oldFolders = [])
    {
        $folders = [];
        foreach ([...$oldFolders, ...array_map(static fn (string $key): string => explode('/', $key, 2)[0], array_keys($moves))] as $folder) {
            $folders[$folder] = true;
        }
        $this->folders = $folders;
    }

    /**
     * The first folders a document must mention to need a look (the SQL pre-filter).
     *
     * @return list<string>
     */
    public function folders(): array
    {
        return array_map(strval(...), array_keys($this->folders)); // a numeric folder became an int key
    }

    public function rewrite(mixed $document): mixed
    {
        $this->rewritten = 0;
        $this->unmatched = [];

        return $this->walk($document);
    }

    /** The rewritten string, or null when it names no moved key. */
    public function rewriteString(string $value): ?string
    {
        if (isset($this->moves[$value])) {
            return $this->moves[$value];
        }

        $path = $this->addressPath($value);
        if ($path === null) {
            return null;
        }

        // The key is the path's tail: try every tail that starts after a slash, longest first.
        $offset = 0;
        while (($slash = strpos($path['path'], '/', $offset)) !== false) {
            $tail = substr($path['path'], $slash + 1);
            if (isset($this->moves[$tail])) {
                return substr($value, 0, $path['at'] + $slash + 1).$this->moves[$tail].substr($value, $path['at'] + strlen($path['path']));
            }
            $offset = $slash + 1;
        }

        return null;
    }

    private function walk(mixed $value): mixed
    {
        if (is_string($value)) {
            $new = $this->rewriteString($value);
            if ($new !== null) {
                $this->rewritten++;

                return $new;
            }
            if ($this->underOldFolder($value)) {
                $this->unmatched[] = $value;
            }

            return $value;
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->walk($item), $value);
        }

        if ($value instanceof stdClass) {
            $out = new stdClass;
            foreach (get_object_vars($value) as $name => $item) {
                $out->{$name} = $this->walk($item);
            }

            return $out;
        }

        return $value;
    }

    /**
     * The path of a web address (`https://host/…`, `//host/…` or `/…`) and where it starts in the
     * string, or null for anything else.
     *
     * @return array{path: string, at: int}|null
     */
    private function addressPath(string $value): ?array
    {
        if (! str_starts_with($value, '/') && preg_match('#\Ahttps?://#i', $value) !== 1) {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (! is_string($path) || $path === '' || ! str_starts_with($path, '/')) {
            return null;
        }

        $after = str_starts_with($value, '//') || ! str_starts_with($value, '/') ? (int) strpos($value, '//') + 2 : 0;
        $at = strpos($value, $path, $after);

        return $at === false ? null : ['path' => $path, 'at' => $at];
    }

    /** A bare key or an address whose path has a segment that is one of the old folders. */
    private function underOldFolder(string $value): bool
    {
        if (preg_match(MediaKey::CLEAN_PATH_PATTERN, $value) === 1) {
            return isset($this->folders[explode('/', $value, 2)[0]]);
        }

        $path = $this->addressPath($value);
        if ($path === null) {
            return false;
        }

        $segments = explode('/', trim($path['path'], '/'));
        array_pop($segments); // the file name itself is never a folder

        return array_filter($segments, fn (string $segment): bool => isset($this->folders[$segment])) !== [];
    }
}
