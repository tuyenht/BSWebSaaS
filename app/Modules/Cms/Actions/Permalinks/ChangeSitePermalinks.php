<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Permalinks;

use App\Modules\Cms\Exceptions\PermalinkChangeRejectedException;
use App\Modules\Cms\Jobs\ReflowPermalinks;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\PermalinkKinds;
use App\Modules\Cms\Routing\RedirectRules;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The write path of «Đường dẫn»: a member picks, per content section (pages, each post section,
 * products), the preset its addresses follow. The change is not applied in the request — the
 * sections that change are STAGED (PermalinkConfig::stage: the live preset keeps serving, the new
 * one waits in `value.pending`) and ReflowPermalinks moves every route in chunks, each move with its
 * 301, then makes the staged presets live.
 *
 * Refused while an earlier change is still being applied, its settle phase included (one at a time
 * per site: PermalinkConfig::busy), and when the
 * website may not choose (`permalinks.custom` off: only CHANGING is gated, the stored presets keep
 * working). Every choice is checked: the preset is one its section's type allows, and a prefix is
 * lower-case letters, digits and single hyphens, used by no other section, and not a first segment
 * the platform answers on (the admin, sign-in, a language code, the search, tags, system files).
 *
 * Runs inside the caller's Tenancy::enter(); the site's permalink lock serializes it with the run.
 */
final readonly class ChangeSitePermalinks
{
    public const string FEATURE = 'permalinks.custom';

    /** A run whose last progress (its heartbeat, or the staging before the first chunk) is this old is taken as lost. */
    public const int STALE_MINUTES = 15;

    public const int BASE_MAX = 60;

    private const string BASE_SHAPE = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /** First segments of platform addresses served outside the router's fixed routes. */
    private const array RESERVED_BASES = ['sys'];

    public function __construct(
        private CurrentSite $current,
        private PermalinkConfig $permalinks,
        private Entitlement $entitlement,
        private SectionResolver $sections,
        private PermalinkKinds $kinds,
        private RedirectRules $rules,
    ) {}

    /** The site's permalink lock, held to the end of the current transaction. */
    public static function lock(int $siteId): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['permalinks:'.$siteId]);
    }

    /**
     * Every section whose address format the current site may set, with its type: pages, each
     * section that files posts, and each registered kind's section (products) when the website has
     * that kind's module (the same rule the screen shows its card by).
     *
     * @return array<string, string> section => type
     */
    public function sections(): array
    {
        $out = ['pages' => 'pages'];
        $modules = null;
        foreach ($this->kinds->all() as $kind) {
            if ($kind['module'] !== null && ! in_array($kind['module'], $modules ??= $this->entitlement->entitledModuleKeys(), true)) {
                continue;
            }
            $out[$kind['section']] = $kind['section'];
        }
        foreach ($this->sections->postSections() as $section) {
            $out[$section['key']] ??= $section['type'];
        }

        return $out;
    }

    /**
     * What is wrong with each of $wanted's choices, keyed by section: `pattern` (not one its type
     * allows), `base_empty`, `base_format` (letters, digits, single hyphens; at most BASE_MAX),
     * `base_taken` (another section's prefix, named in `other`) or `base_reserved`. A section the
     * site does not have is `pattern`. Sections not in $wanted keep their stored choice and count
     * for `base_taken`.
     *
     * @param  array<string, array{pattern: string, base: string|null}>  $wanted
     * @return array<string, array{code: string, other: string|null}>
     */
    public function problems(array $wanted): array
    {
        $types = $this->sections();
        $bases = [];
        foreach ($types as $section => $type) {
            $choice = $wanted[$section] ?? ['pattern' => $this->permalinks->pattern($section, $type), 'base' => $this->permalinks->base($section)];
            if ($choice['pattern'] === 'base') {
                $bases[$section] = trim((string) $choice['base']);
            }
        }

        $problems = [];
        foreach ($wanted as $section => $choice) {
            $type = $types[$section] ?? null;
            if ($type === null || ! in_array($choice['pattern'], $this->permalinks->allowedPatterns($type), true)) {
                $problems[$section] = ['code' => 'pattern', 'other' => null];

                continue;
            }
            if ($choice['pattern'] !== 'base') {
                continue;
            }
            $base = $bases[$section] ?? '';
            $other = null;
            foreach ($bases as $otherSection => $otherBase) {
                if ($otherSection !== $section && $otherBase === $base) {
                    $other = $otherSection;
                    break;
                }
            }
            $code = match (true) {
                $base === '' => 'base_empty',
                mb_strlen($base) > self::BASE_MAX || preg_match(self::BASE_SHAPE, $base) !== 1 => 'base_format',
                $this->reservedBase($base) => 'base_reserved',
                $other !== null => 'base_taken',
                default => null,
            };
            if ($code !== null) {
                $problems[$section] = ['code' => $code, 'other' => $code === 'base_taken' ? $other : null];
            }
        }

        return $problems;
    }

    /**
     * Stage the sections of $wanted whose choice differs from the stored one and start the run.
     *
     * @param  array<string, array{pattern: string, base: string|null}>  $wanted  section => choice
     * @return list<string> the sections staged (empty: nothing changed)
     *
     * @throws PermalinkChangeRejectedException
     */
    public function handle(array $wanted): array
    {
        $siteId = $this->current->id();
        self::lock($siteId);
        $this->permalinks->flush(); // read what the lock now guards, not what this request cached

        $types = $this->sections();
        $changes = [];
        foreach ($wanted as $section => $choice) {
            $type = $types[$section] ?? null;
            $base = $choice['pattern'] === 'base' ? trim((string) $choice['base']) : null;
            if ($type !== null && $choice['pattern'] === $this->permalinks->pattern($section, $type)
                && ($base === null || $base === $this->permalinks->base($section))) {
                continue;
            }
            $changes[$section] = ['pattern' => $choice['pattern'], 'base' => $base];
        }
        if ($changes === []) {
            return [];
        }
        if ($this->permalinks->busy()) {
            throw new PermalinkChangeRejectedException('pending');
        }
        if (! $this->entitlement->featureEnabled(self::FEATURE)) {
            throw new PermalinkChangeRejectedException('off');
        }
        $problems = $this->problems($changes);
        if ($problems !== []) {
            throw new PermalinkChangeRejectedException('invalid', $problems);
        }

        if ($this->permalinks->run() !== null) {
            $this->permalinks->saveRun(null); // what an earlier run could not move is superseded by this one
        }
        $this->permalinks->stage($changes, Carbon::now()->utc()->toIso8601String());
        ReflowPermalinks::dispatch($siteId);

        return array_keys($changes);
    }

    /**
     * Start the run again when its last progress is STALE_MINUTES old (it was lost: a worker restart
     * past its retries, a queued run that never came): the run carries on from its stored cursor.
     * A run that is still working, however long it has run, is left alone. True when one was started.
     */
    public function resumeIfStale(): bool
    {
        $run = $this->permalinks->run();
        $walking = in_array($run['phase'] ?? null, [ReflowPermalinks::MOVE, ReflowPermalinks::SETTLE], true);
        $last = $walking ? ($run['beat'] ?? '') : null;
        if (! $walking) {
            foreach ($this->permalinks->sections() as $row) {
                $started = $row['pending']['started_at'] ?? null;
                if (is_string($started) && $started !== '' && ($last === null || $started > $last)) {
                    $last = $started;
                }
            }
        }
        if ($last === null || ($last !== '' && Carbon::parse($last)->gt(Carbon::now()->subMinutes(self::STALE_MINUTES)))) {
            return false;
        }

        ReflowPermalinks::dispatch($this->current->id());

        return true;
    }

    /**
     * «Thử lại» for the items the last run could not move: walk their sections once more on the live
     * format (a settle run), now that the member may have shortened or renamed them. False when
     * there is nothing to retry or a run is under way.
     */
    public function retryFailed(): bool
    {
        self::lock($this->current->id());
        $this->permalinks->flush();
        $run = $this->permalinks->run();
        if ($run === null || $run['phase'] !== PermalinkConfig::RUN_DONE || $this->permalinks->busy()) {
            return false;
        }
        $sections = array_values(array_intersect(array_unique(array_column($run['failures'], 'section')), array_keys($this->sections())));
        if ($sections === []) {
            $sections = array_values(array_intersect($run['sections'], array_keys($this->sections())));
        }
        if ($sections === []) {
            $this->permalinks->saveRun(null);

            return false;
        }
        $this->permalinks->saveRun([
            'phase' => ReflowPermalinks::SETTLE, 'sections' => $sections, 'kind' => 0, 'after' => 0,
            'beat' => Carbon::now()->utc()->toIso8601String(), 'failures' => [], 'failed' => 0,
        ]);
        ReflowPermalinks::dispatch($this->current->id());

        return true;
    }

    /** «Đã hiểu»: forget the items the last run could not move (they keep their old address). */
    public function dismissFailed(): void
    {
        self::lock($this->current->id());
        $this->permalinks->flush();
        if (($this->permalinks->run()['phase'] ?? null) === PermalinkConfig::RUN_DONE) {
            $this->permalinks->saveRun(null);
        }
    }

    /** Whether a prefix would take a first segment the platform answers on. */
    private function reservedBase(string $base): bool
    {
        $platform = [...self::RESERVED_BASES];
        foreach (PathBuilder::RESERVED_PATHS as $path) {
            $platform[] = explode('/', trim($path, '/'), 2)[0];
        }

        return SiteLocale::looksLikeCode($base) || in_array($base, $platform, true) || in_array($base, $this->rules->reservedSegments(), true);
    }
}
