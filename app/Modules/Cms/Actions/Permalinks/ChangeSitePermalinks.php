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
 * Refused while an earlier change is still being applied (one at a time per site), and when the
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

    /** A run whose change was staged this long ago and is still pending is taken as lost. */
    public const int STALE_MINUTES = 60;

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
     * section that files posts, and each registered kind's section (products).
     *
     * @return array<string, string> section => type
     */
    public function sections(): array
    {
        $out = ['pages' => 'pages'];
        foreach ($this->kinds->all() as $kind) {
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
        if ($this->permalinks->pending() !== []) {
            throw new PermalinkChangeRejectedException('pending');
        }
        if (! $this->entitlement->featureEnabled(self::FEATURE)) {
            throw new PermalinkChangeRejectedException('off');
        }
        $problems = $this->problems($changes);
        if ($problems !== []) {
            throw new PermalinkChangeRejectedException('invalid', $problems);
        }

        $this->permalinks->stage($changes, Carbon::now()->utc()->toIso8601String());
        ReflowPermalinks::dispatch($siteId);

        return array_keys($changes);
    }

    /**
     * Start the run again when a change has been pending for STALE_MINUTES (its run was lost: a
     * worker restart past its retries). The run skips what already moved. True when one was started.
     */
    public function resumeIfStale(): bool
    {
        $oldest = null;
        foreach ($this->permalinks->sections() as $row) {
            $started = $row['pending']['started_at'] ?? null;
            if (is_string($started) && $started !== '' && ($oldest === null || $started < $oldest)) {
                $oldest = $started;
            }
        }
        if ($oldest === null || Carbon::parse($oldest)->gt(Carbon::now()->subMinutes(self::STALE_MINUTES))) {
            return false;
        }

        ReflowPermalinks::dispatch($this->current->id());

        return true;
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
