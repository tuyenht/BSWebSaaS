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
 * The caller bounds what is read (at most 512 KB). Judging is bounded too, whatever the file holds
 * (it may come from a server the site's owner runs): at most MAX_RULES rules each at most MAX_PATH
 * bytes, at most MAX_PROBES paths per bot, at most BUDGET rule checks for all the bots asked
 * about together, and no regular expression that can backtrack. A file past any bound is not
 * judged (verdicts() gives null) rather than judged slowly.
 *
 * @phpstan-type Rule array{allow: bool, length: int, literal: int, segments: non-empty-list<string>, anchored: bool, probe: string}
 */
final readonly class RobotsTxt
{
    /** A path nobody links to: "is something below the root still open?" */
    private const string PROBE = '/x-robots-probe';

    /**
     * Rules kept from one file. A file people write holds tens of rules, the site's own text (at
     * most SiteSeo::ROBOTS_MAX characters, seven or more per rule line) fewer than 1,500; judging
     * costs rules × paths per bot, so a longer file is not judged rather than judged slowly.
     */
    public const int MAX_RULES = 2000;

    /** The longest rule path judged, in bytes: no real address is longer, and matching costs its length. */
    public const int MAX_PATH = 512;

    /** Paths tried per bot: the root, one below it, and the first distinct paths the rules name. */
    public const int MAX_PROBES = 100;

    /**
     * Rule checks (one rule against one path) for one verdicts() call. Bots that follow the same
     * groups share one judgement, and a real file decides most paths on its first few rules, so a
     * file people wrote stays far below this.
     */
    public const int BUDGET = 100000;

    /**
     * @param  list<array{agents: list<string>, rules: list<Rule>}>  $groups
     * @param  list<string>  $signals
     */
    private function __construct(private array $groups, private array $signals, private bool $judgeable) {}

    public static function parse(string $text): self
    {
        /** @var list<array{agents: list<string>, rules: list<Rule>}> $groups */
        $groups = [];
        $signals = [];
        /** @var list<string> $agents */
        $agents = [];
        /** @var list<Rule> $rules */
        $rules = [];
        $inAgents = false;
        $kept = 0;
        $judgeable = true;

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
                // A rule before any User-agent line belongs to nobody; an empty one decides nothing.
                if ($agents === [] || $value === '' || ! $judgeable) {
                    continue;
                }
                if (++$kept > self::MAX_RULES || strlen($value) > self::MAX_PATH) {
                    $judgeable = false;

                    continue;
                }
                $rules[] = self::rule($field === 'allow', $value);
            } elseif ($field === 'content-signal' && $value !== '') {
                $signals[] = $value;
            }
        }
        if ($agents !== []) {
            $groups[] = ['agents' => $agents, 'rules' => $rules];
        }

        return new self($groups, $signals, $judgeable);
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
        $budget = self::BUDGET;

        return self::decide(self::ranked($this->rulesFor($agent)[1]), $path, $budget) !== false;
    }

    /**
     * How much of the site each bot may read: `blocked` (nothing), `partial` (some paths
     * disallowed, e.g. `/san-pham`) or `allowed` (everything). Null when the file is past a bound
     * (see the class): nothing can be concluded from it.
     *
     * @param  list<string>  $agents
     * @return array<string, 'blocked'|'partial'|'allowed'>|null
     */
    public function verdicts(array $agents): ?array
    {
        if (! $this->judgeable) {
            return null;
        }
        $budget = self::BUDGET;
        $judged = [];
        $verdicts = [];
        foreach ($agents as $agent) {
            [$key, $rules] = $this->rulesFor($agent);
            $verdict = $judged[$key] ?? self::judge($rules, $budget);
            if ($verdict === null) {
                return null;
            }
            $judged[$key] = $verdicts[$agent] = $verdict;
        }

        return $verdicts;
    }

    /** @return 'blocked'|'partial'|'allowed'|null one bot's verdict (see verdicts()) */
    public function verdict(string $agent): ?string
    {
        return $this->verdicts([$agent])[$agent] ?? null;
    }

    /**
     * Judged on the root, a path below it and each path a rule names; it stops once it has seen
     * both an open and a closed path. Null once the budget is spent, or when the rules name more
     * than MAX_PROBES paths and the ones tried do not already show `partial`.
     *
     * @param  list<Rule>  $rules
     * @return 'blocked'|'partial'|'allowed'|null
     */
    private static function judge(array $rules, int &$budget): ?string
    {
        $paths = ['/' => true, self::PROBE => true];
        $cut = false;
        foreach ($rules as $rule) {
            if (isset($paths[$rule['probe']])) {
                continue;
            }
            if (count($paths) >= self::MAX_PROBES) {
                $cut = true;
                break;
            }
            $paths[$rule['probe']] = true;
        }

        $ranked = self::ranked($rules);
        $open = 0;
        $closed = 0;
        foreach (array_keys($paths) as $path) {
            $allowed = self::decide($ranked, (string) $path, $budget);
            if ($allowed === null) {
                return null;
            }
            $allowed ? $open++ : $closed++;
            if ($open > 0 && $closed > 0) {
                return 'partial';
            }
        }

        // A path left untried could still be the open (or closed) one: not judged.
        if ($cut) {
            return null;
        }

        return $closed === 0 ? 'allowed' : 'blocked';
    }

    /**
     * The rules this bot follows: every group naming it, merged; else every `*` group, merged —
     * with a key naming those groups, the same for every bot that follows the same ones.
     *
     * @return array{0: string, 1: list<Rule>}
     */
    private function rulesFor(string $agent): array
    {
        $agent = strtolower($agent);
        $own = null;
        $ownKey = '';
        $any = [];
        $anyKey = '*';
        foreach ($this->groups as $index => $group) {
            if (in_array($agent, $group['agents'], true)) {
                $own = [...$own ?? [], ...$group['rules']];
                $ownKey .= $index.',';
            } elseif (in_array('*', $group['agents'], true)) {
                array_push($any, ...$group['rules']);
                $anyKey .= $index.',';
            }
        }

        return $own !== null ? [$ownKey, $own] : [$anyKey, $any];
    }

    /**
     * The rules in the order they win: the longest path first and, at the same length, `Allow`
     * first — so the first rule that matches a path decides it.
     *
     * @param  list<Rule>  $rules
     * @return list<Rule>
     */
    private static function ranked(array $rules): array
    {
        usort($rules, static fn (array $a, array $b): int => [$b['length'], $b['allow']] <=> [$a['length'], $a['allow']]);

        return $rules;
    }

    /**
     * Whether the path is open (the first ranked rule that matches decides; none = open); null once
     * the budget is spent.
     *
     * @param  list<Rule>  $ranked
     */
    private static function decide(array $ranked, string $path, int &$budget): ?bool
    {
        $size = strlen($path);
        foreach ($ranked as $rule) {
            if (--$budget < 0) {
                return null;
            }
            if ($rule['literal'] <= $size && self::matches($rule, $path)) {
                return $rule['allow'];
            }
        }

        return true;
    }

    /**
     * One rule, ready to match: its pieces between `*`, whether a final `$` anchors it, its length
     * (what decides between rules), how many plain characters it needs, and the path before its
     * first `*` or `$` (one of the paths the verdict tries).
     *
     * @return Rule
     */
    private static function rule(bool $allow, string $path): array
    {
        $anchored = str_ends_with($path, '$');
        $body = $anchored ? substr($path, 0, -1) : $path;

        return [
            'allow' => $allow,
            'length' => strlen($path),
            'literal' => strlen($body) - substr_count($body, '*'),
            'segments' => explode('*', $body),
            'anchored' => $anchored,
            'probe' => '/'.ltrim(substr($path, 0, strcspn($path, '*$')), '/'),
        ];
    }

    /**
     * `*` = any run of characters, a final `$` = the end of the path; anything else literal.
     * Matched piece by piece, each at its first place after the one before (enough for `*`-only
     * patterns), so the work is linear in the path — never a regular expression that can backtrack.
     *
     * @param  Rule  $rule
     */
    private static function matches(array $rule, string $path): bool
    {
        $segments = $rule['segments'];
        $last = count($segments) - 1;
        if (! str_starts_with($path, $segments[0])) {
            return false;
        }
        $at = strlen($segments[0]);
        if ($last === 0) {
            return ! $rule['anchored'] || strlen($path) === $at;
        }
        for ($i = 1; $i < $last; $i++) {
            if ($segments[$i] === '') {
                continue;
            }
            $found = strpos($path, $segments[$i], $at);
            if ($found === false) {
                return false;
            }
            $at = $found + strlen($segments[$i]);
        }
        $end = $segments[$last];
        if (! $rule['anchored']) {
            return $end === '' || strpos($path, $end, $at) !== false;
        }

        return strlen($path) - strlen($end) >= $at && str_ends_with($path, $end);
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
