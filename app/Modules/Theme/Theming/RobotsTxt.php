<?php

declare(strict_types=1);

namespace App\Modules\Theme\Theming;

/**
 * A robots.txt read the way RFC 9309 says a crawler reads it, for the AI crawler check:
 *   - a group is one or more `User-agent` lines followed by its rules; a bot with a group of its
 *     own ignores the `*` group entirely; groups naming the same bot are merged; names compare
 *     without regard to case;
 *   - the rule whose path matches with the most characters wins; on a tie `Allow` wins (so
 *     `Allow: /` cancels `Disallow: /` in the same group); an empty `Disallow:` allows everything;
 *   - `*` matches any run of characters and a final `$` anchors the end;
 *   - other records (Sitemap, Crawl-delay, Content-Signal…) belong to no group and decide nothing;
 *     Content-Signal lines are kept so the check can name them.
 * The caller bounds what is read (at most 512 KB).
 */
final readonly class RobotsTxt
{
    /** A path nobody links to: "is something below the root still open?" */
    private const string PROBE = '/x-robots-probe';

    /**
     * @param  list<array{agents: list<string>, rules: list<array{allow: bool, path: string}>}>  $groups
     * @param  list<string>  $signals
     */
    private function __construct(private array $groups, private array $signals) {}

    public static function parse(string $text): self
    {
        /** @var list<array{agents: list<string>, rules: list<array{allow: bool, path: string}>}> $groups */
        $groups = [];
        $signals = [];
        /** @var list<string> $agents */
        $agents = [];
        /** @var list<array{allow: bool, path: string}> $rules */
        $rules = [];
        $inAgents = false;

        foreach (self::records($text) as [$field, $value]) {
            if ($field === 'user-agent') {
                if (! $inAgents) {
                    // A User-agent line after rules starts the next group.
                    if ($agents !== []) {
                        $groups[] = ['agents' => $agents, 'rules' => $rules];
                    }
                    $agents = [];
                    $rules = [];
                    $inAgents = true;
                }
                $agents[] = self::agentToken($value);
            } elseif ($field === 'allow' || $field === 'disallow') {
                $inAgents = false;
                if ($agents !== []) {   // a rule before any User-agent line belongs to nobody
                    $rules[] = ['allow' => $field === 'allow', 'path' => $value];
                }
            } elseif ($field === 'content-signal' && $value !== '') {
                $signals[] = $value;
            }
        }
        if ($agents !== []) {
            $groups[] = ['agents' => $agents, 'rules' => $rules];
        }

        return new self($groups, $signals);
    }

    /**
     * The lines that decide something, one spelling each (`field: value`, the field in lower
     * case): comments, blank lines, line-ending style, Sitemap and Content-Signal lines left out —
     * so two files that differ only there compare equal.
     *
     * @return list<string>
     */
    public static function comparable(string $text): array
    {
        $lines = [];
        foreach (self::records($text) as [$field, $value]) {
            if ($field !== 'sitemap' && $field !== 'content-signal') {
                $lines[] = $field.': '.$value;
            }
        }

        return $lines;
    }

    /** @return list<string> the Content-Signal values, as written */
    public function contentSignals(): array
    {
        return $this->signals;
    }

    /** May this bot fetch this path? */
    public function allows(string $agent, string $path): bool
    {
        $bestLength = -1;
        $allowed = true;
        foreach ($this->rulesFor($agent) as $rule) {
            if ($rule['path'] === '' || ! self::matches($rule['path'], $path)) {
                continue;
            }
            $length = strlen($rule['path']);
            if ($length > $bestLength || ($length === $bestLength && $rule['allow'])) {
                $bestLength = $length;
                $allowed = $rule['allow'];
            }
        }

        return $allowed;
    }

    /**
     * How much of the site this bot may read: `blocked` (nothing), `partial` (some paths
     * disallowed, e.g. `/san-pham`) or `allowed` (everything). Judged on the root, a path below it
     * and each path a rule names.
     *
     * @return 'blocked'|'partial'|'allowed'
     */
    public function verdict(string $agent): string
    {
        $paths = ['/', self::PROBE];
        foreach ($this->rulesFor($agent) as $rule) {
            if ($rule['path'] !== '') {
                $prefix = preg_split('/[*$]/', $rule['path'])[0] ?? '';
                $paths[] = '/'.ltrim($prefix, '/');
            }
        }

        $denied = 0;
        foreach ($paths as $path) {
            $denied += $this->allows($agent, $path) ? 0 : 1;
        }

        return match (true) {
            $denied === 0 => 'allowed',
            $denied === count($paths) => 'blocked',
            default => 'partial',
        };
    }

    /**
     * The rules this bot follows: every group naming it, merged; else every `*` group, merged.
     *
     * @return list<array{allow: bool, path: string}>
     */
    private function rulesFor(string $agent): array
    {
        $agent = strtolower($agent);
        $own = null;
        $any = [];
        foreach ($this->groups as $group) {
            if (in_array($agent, $group['agents'], true)) {
                $own = [...$own ?? [], ...$group['rules']];
            } elseif (in_array('*', $group['agents'], true)) {
                array_push($any, ...$group['rules']);
            }
        }

        return $own ?? $any;
    }

    /** `*` = any run of characters, a final `$` = the end of the path; anything else literal. */
    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $body = $anchored ? substr($pattern, 0, -1) : $pattern;
        $regex = implode('.*', array_map(static fn (string $part): string => preg_quote($part, '~'), explode('*', $body)));

        return preg_match('~\A'.$regex.($anchored ? '\z' : '').'~', $path) === 1;
    }

    /** The product token of a User-agent value, lower case (`GPTBot/1.1` → `gptbot`); `*` stays. */
    private static function agentToken(string $value): string
    {
        if (str_starts_with($value, '*')) {
            return '*';
        }

        return preg_match('/\A[A-Za-z0-9_-]+/', $value, $match) === 1 ? strtolower($match[0]) : strtolower($value);
    }

    /**
     * Each `field: value` record: a byte-order mark and comments dropped, any line ending, the
     * field in lower case, both sides trimmed; lines without a colon skipped.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function records(string $text): array
    {
        $text = str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
        $records = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $hash = strpos($line, '#');
            $line = trim($hash === false ? $line : substr($line, 0, $hash));
            $colon = strpos($line, ':');
            if ($line === '' || $colon === false) {
                continue;
            }
            $records[] = [strtolower(trim(substr($line, 0, $colon))), trim(substr($line, $colon + 1))];
        }

        return $records;
    }
}
