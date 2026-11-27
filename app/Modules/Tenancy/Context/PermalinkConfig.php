<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use App\Modules\Tenancy\Models\SiteSetting;
use Closure;

/**
 * Resolves the current site's permalink pattern per content section — the per-site policy that
 * decides how a URL path is composed. Source of truth = `site_settings` (group `permalinks`, key =
 * section, value `{"pattern": …, "base": …}`); an absent row means the section default (flat).
 * Mirrors SiteLocale/SiteTime: a thin per-site context both the write path (RouteSync/PathBuilder)
 * and the storefront read the same way, so they never disagree.
 *
 * Presets and where each is valid (per section TYPE — a preset outside its type's set is ignored and
 * falls back to flat, never acted on):
 *   - pages    : flat (default) · nested · base
 *   - posts    : flat (default) · base · category · category_path
 *   - products : flat (default) · base · category · category_path
 * Every section defaults to `flat` (no prefix, no parent- or group-inheritance); prefixing is
 * strictly opt-in. The `base` preset reads its static segment from the same row's `value.base`.
 *
 * A change of preset is staged first: the row keeps its live `pattern`/`base` and gains
 * `value.pending` = {pattern, base, started_at} (stage()). The storefront and every save keep composing
 * from the live preset while a background run moves the routes, composing from the pending one only
 * inside withTargets(); promote() then makes the pending preset the live one.
 *
 * Memoized per-site for the request and negative-cached — a section with no row costs at most one
 * query. Registered scoped (TenancyServiceProvider) so the memo is flushed between Octane operations
 * and never leaks a stale pattern across requests.
 */
final class PermalinkConfig
{
    private const string GROUP = 'permalinks';

    /** The universal default: every section is flat unless the site opts into a prefixing preset. */
    public const string DEFAULT_PATTERN = 'flat';

    /**
     * Valid presets per section type. `flat` is always valid (and the default); the rest are opt-in.
     * A stored pattern outside its type's set is ignored → flat. An unknown type is fail-closed to
     * flat only.
     *
     * @var array<string, list<string>>
     */
    private const array VALID = [
        'pages' => ['flat', 'nested', 'base'],
        'posts' => ['flat', 'base', 'category', 'category_path'],
        'products' => ['flat', 'base', 'category', 'category_path'],
    ];

    /** @var array<string, array<mixed>|null> memoized stored value per "{siteId}:{section}" */
    private array $memo = [];

    /** @var array<string, array{pattern: string, base: string|null}> sections composed from a target preset for the length of withTargets() */
    private array $targets = [];

    public function __construct(private readonly CurrentSite $current) {}

    /**
     * The permalink pattern for a section of the given type (e.g. `('posts', 'posts')` — `$section`
     * is the stable ContentSection.key, the same identifier pages use, NOT a taxonomy slug),
     * validated against the type's allowed set and falling back to `flat`. No site context, no row,
     * or an unrecognised/invalid stored value → flat (fail-safe: never prefixes unexpectedly).
     */
    public function pattern(string $section, string $type): string
    {
        $stored = $this->targets[$section] ?? $this->stored($section) ?? [];
        $pattern = $stored['pattern'] ?? null;

        if (is_string($pattern) && in_array($pattern, $this->allowedPatterns($type), true)) {
            return $pattern;
        }

        return self::DEFAULT_PATTERN;
    }

    /**
     * The static base segment for the `base` preset (`value.base`, e.g. `san-pham`), or null when
     * unset/blank. Surrounding whitespace and slashes are trimmed; canonical slug-normalisation
     * belongs to the settings writer.
     */
    public function base(string $section): ?string
    {
        $stored = $this->targets[$section] ?? $this->stored($section) ?? [];
        $base = $stored['base'] ?? null;

        if (! is_string($base)) {
            return null;
        }

        $base = trim(trim($base), '/');

        return $base === '' ? null : $base;
    }

    /**
     * The presets a section of this type may use (the validity matrix) — for the settings UI and for
     * validating a stored value. An unknown type is fail-closed to flat only.
     *
     * @return list<string>
     */
    public function allowedPatterns(string $type): array
    {
        return self::VALID[$type] ?? [self::DEFAULT_PATTERN];
    }

    /**
     * Run $run with these sections composed from the given presets instead of their stored ones —
     * what a permalink run uses to compose the address an item WILL have, through the one builder
     * (PathBuilder), while everything else keeps reading the live preset. A target outside its
     * type's set falls back to flat like a stored one. The previous targets come back afterwards.
     *
     * @template TReturn
     *
     * @param  array<string, array{pattern: string, base: string|null}>  $targets  section => preset
     * @param  Closure(): TReturn  $run
     * @return TReturn
     */
    public function withTargets(array $targets, Closure $run): mixed
    {
        $before = $this->targets;
        $this->targets = $targets + $before;

        try {
            return $run();
        } finally {
            $this->targets = $before;
        }
    }

    /**
     * Every stored section row of the current site in one read: its live preset and, while a change
     * is being applied, the staged one. Empty with no site context.
     *
     * @return array<string, array{pattern: string|null, base: string|null, pending: array{pattern: string, base: string|null, started_at: string}|null}>
     */
    public function sections(): array
    {
        if (! $this->current->hasId()) {
            return [];
        }

        $out = [];
        foreach (SiteSetting::query()->where('group_name', self::GROUP)->get(['key', 'value']) as $row) {
            $value = $row->value;
            $pending = is_array($value['pending'] ?? null) && is_string($value['pending']['pattern'] ?? null) ? $value['pending'] : null;
            $out[$row->key] = [
                'pattern' => is_string($value['pattern'] ?? null) ? $value['pattern'] : null,
                'base' => is_string($value['base'] ?? null) ? $value['base'] : null,
                'pending' => $pending === null ? null : [
                    'pattern' => (string) $pending['pattern'],
                    'base' => is_string($pending['base'] ?? null) ? $pending['base'] : null,
                    'started_at' => is_string($pending['started_at'] ?? null) ? $pending['started_at'] : '',
                ],
            ];
        }

        return $out;
    }

    /**
     * The staged presets of the current site (section => preset), empty when no change is waiting.
     *
     * @return array<string, array{pattern: string, base: string|null}>
     */
    public function pending(): array
    {
        $out = [];
        foreach ($this->sections() as $section => $row) {
            if ($row['pending'] !== null) {
                $out[$section] = ['pattern' => $row['pending']['pattern'], 'base' => $row['pending']['base']];
            }
        }

        return $out;
    }

    /**
     * Stage new presets (section => preset) on the current site: each row keeps its live preset and
     * gains `pending`. The caller holds the site's permalink lock and has validated the presets.
     *
     * @param  array<string, array{pattern: string, base: string|null}>  $changes
     */
    public function stage(array $changes, string $startedAt): void
    {
        foreach ($changes as $section => $preset) {
            $row = SiteSetting::query()->where('group_name', self::GROUP)->where('key', $section)->lockForUpdate()->first();
            $value = is_array($row?->value) ? $row->value : [];
            $value['pending'] = ['pattern' => $preset['pattern'], 'base' => $preset['base'], 'started_at' => $startedAt];

            SiteSetting::query()->updateOrCreate(['group_name' => self::GROUP, 'key' => $section], ['value' => $value]);
        }

        $this->flush();
    }

    /**
     * Make every staged preset the live one (the run has moved the routes): `pattern` and `base`
     * take the pending values and `pending` goes. Idempotent: a section with nothing staged is left.
     */
    public function promote(): void
    {
        $rows = SiteSetting::query()->where('group_name', self::GROUP)->lockForUpdate()->get();

        foreach ($rows as $row) {
            $value = $row->value;
            $pending = $value['pending'] ?? null;
            if (! is_array($pending) || ! is_string($pending['pattern'] ?? null)) {
                continue;
            }

            $live = ['pattern' => $pending['pattern']];
            if (is_string($pending['base'] ?? null) && $pending['base'] !== '') {
                $live['base'] = $pending['base'];
            }
            $row->update(['value' => $live]);
        }

        $this->flush();
    }

    /**
     * Every stored section of the current site back to the flat default, anything staged dropped
     * (the operator's «everything flat» reflow). Idempotent: a row already exactly flat is left.
     */
    public function flattenStored(): void
    {
        foreach (SiteSetting::query()->where('group_name', self::GROUP)->get() as $row) {
            if ($row->value !== ['pattern' => self::DEFAULT_PATTERN]) {
                $row->update(['value' => ['pattern' => self::DEFAULT_PATTERN]]);
            }
        }

        $this->flush();
    }

    /** Whether pages nest their URL under the parent page's path (default: no). */
    public function pagesNested(): bool
    {
        return $this->pattern('pages', 'pages') === 'nested';
    }

    /**
     * Drop the memoized rows so the next read reflects settings written earlier in the SAME
     * request/command — e.g. a permalink reflow rewrites `site_settings[permalinks]` and then
     * re-syncs routes in one process; without this the re-sync would compose from the stale
     * pattern this instance already cached. Scoped instance, so callers share the cleared memo.
     */
    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * The memoized raw `value` row for a section (null with no site context or no row). Negative-
     * cached so a section without a setting costs at most one query per request.
     *
     * @return array<mixed>|null
     */
    private function stored(string $section): ?array
    {
        if (! $this->current->hasId()) {
            return null;
        }

        $key = $this->current->id().':'.$section;

        if (! array_key_exists($key, $this->memo)) {
            $value = SiteSetting::query()
                ->where('group_name', self::GROUP)
                ->where('key', $section)
                ->value('value');

            $this->memo[$key] = is_array($value) ? $value : null;
        }

        return $this->memo[$key];
    }
}
