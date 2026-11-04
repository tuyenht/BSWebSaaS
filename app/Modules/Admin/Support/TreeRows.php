<?php

namespace App\Modules\Admin\Support;

use InvalidArgumentException;

/**
 * The rows of the shared admin tree (<x-bs-admin::tree>), in the order they are drawn: a record,
 * then its children, each group in the order the screen gave it. Each row knows its level, its
 * place among its siblings (for aria-posinset / aria-setsize) and the guide lines drawn in its
 * indent — "v" a line passing by, "t" ├, "l" └, "down" the start of the line under a parent.
 *
 * A screen hands over its records as `key` (an opaque string it can resolve again, never a raw
 * id) + `parent` (the parent's key or null) + whatever else it draws; that record comes back as
 * `node`; an empty key is refused (it would read as the outer level). A record whose parent is not
 * in the list is drawn at the outer level, and so is every record of a parent chain that loops, each
 * at its own place there — a damaged tree still renders every record once.
 */
final class TreeRows
{
    /**
     * @template TNode of array{key: string, parent: string|null}
     *
     * @param  iterable<TNode>  $nodes  siblings in their order; a parent may come before or after its children
     * @return list<array{node: TNode, key: string, parent: string|null, level: int, kids: int, pos: int, size: int, guides: list<array{d: int, k: string}>}>
     */
    public static function build(iterable $nodes): array
    {
        $byKey = [];
        foreach ($nodes as $node) {
            $key = $node['key'];
            if ($key === '') {
                throw new InvalidArgumentException('A tree record needs a non-empty key.');
            }
            $byKey[$key] ??= $node;
        }

        /** @var array<string, string> $parentOf each key's parent key; '' is the outer level */
        $parentOf = [];
        foreach ($byKey as $key => $node) {
            $key = (string) $key; // a numeric-string key came back from the array as an int
            $parent = $node['parent'];
            $parentOf[$key] = $parent !== null && $parent !== $key && isset($byKey[$parent]) ? (string) $parent : '';
        }
        foreach (self::loops($parentOf) as $key) {
            $parentOf[$key] = '';
        }

        /** @var array<string, list<string>> $children keyed by the parent's key; '' holds the outer level */
        $children = [];
        foreach ($parentOf as $key => $parent) {
            $children[$parent][] = (string) $key;
        }

        $rows = [];
        $walk = function (string $parentKey, int $level) use (&$walk, &$rows, $children, $byKey): void {
            $group = $children[$parentKey] ?? [];
            foreach ($group as $i => $key) {
                $rows[] = [
                    'node' => $byKey[$key],
                    'key' => $key,
                    'parent' => $parentKey === '' ? null : $parentKey,
                    'level' => $level,
                    'kids' => count($children[$key] ?? []),
                    'pos' => $i + 1,
                    'size' => count($group),
                    'guides' => [],
                ];
                $walk($key, $level + 1);
            }
        };
        $walk('', 1);

        $guides = self::guides(array_column($rows, 'level'));
        $drawn = [];
        foreach ($rows as $i => $row) {
            $row['guides'] = $guides[$i] ?? [];
            $drawn[] = $row;
        }

        return $drawn;
    }

    /**
     * The records caught in a parent loop (a → b → a): following their parents comes back to
     * themselves. Each is drawn at the outer level, at its own place among the records given there;
     * what hangs under a loop stays under it. One pass: every key is walked up at most once.
     *
     * @param  array<string, string>  $parentOf
     * @return list<string>
     */
    private static function loops(array $parentOf): array
    {
        $state = []; // key => 'ok' (reaches the outer level or a known record) | 'loop'
        $loops = [];
        foreach (array_keys($parentOf) as $start) {
            $path = [];
            $onPath = [];
            $key = (string) $start;
            while ($key !== '' && ! isset($state[$key]) && ! isset($onPath[$key])) {
                $onPath[$key] = count($path);
                $path[] = $key;
                $key = $parentOf[$key];
            }
            if ($key !== '' && isset($onPath[$key])) {
                foreach (array_slice($path, $onPath[$key]) as $member) {
                    $state[$member] = 'loop';
                    $loops[] = $member;
                }
            }
            foreach ($path as $member) {
                $state[$member] ??= 'ok';
            }
        }

        return $loops;
    }

    /**
     * The guide lines of each row, from the rows' levels in drawing order (the same rule the
     * browser uses after a collapse). Walking up from the last row, `$open[$level]` says a later
     * sibling at that level is still to come, so its parent's line goes on past this row.
     *
     * @param  list<int>  $levels
     * @return list<list<array{d: int, k: string}>>
     */
    public static function guides(array $levels): array
    {
        $open = [];
        $out = [];
        for ($i = count($levels) - 1; $i >= 0; $i--) {
            $level = $levels[$i];
            $guides = [];
            if ($level > 1) {
                for ($d = 1; $d <= $level - 2; $d++) {
                    if ($open[$d + 1] ?? false) {
                        $guides[] = ['d' => $d, 'k' => 'v'];
                    }
                }
                $guides[] = ['d' => $level - 1, 'k' => ($open[$level] ?? false) ? 't' : 'l'];
            }
            if (isset($levels[$i + 1]) && $levels[$i + 1] > $level) {
                $guides[] = ['d' => $level, 'k' => 'down'];
            }
            $out[$i] = $guides;
            $open[$level] = true;
            foreach (array_keys($open) as $deeper) {
                if ($deeper > $level) {
                    $open[$deeper] = false;
                }
            }
        }
        ksort($out);

        return array_values($out);
    }
}
