<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console;

use App\Modules\Cms\Support\EntryCoverUsage;
use App\Modules\Media\Services\SiteMediaRekey;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Media\Support\MediaKeyRewriter;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Moves one website's files filed before website codes to `{code}/{file}` (SiteMediaRekey), so every
 * address it prints is the short one. A dry run by default: it counts the files and, per place, the
 * stored references it would rewrite. With --apply it copies the objects (the old ones stay, so links
 * already shared keep working), then moves the keys a chunk at a time, each chunk in one transaction
 * with the content rows that name its keys; then it rewrites what still names any old key of the
 * website (from this run or an earlier one) and rebuilds the featured-picture usage rows.
 * Idempotent and resumable: a run stopped part way keeps its finished chunks, and the next run
 * moves the rest and repairs an old key written back meanwhile (an editor opened before the run);
 * a dry run counts such a key as a reference to rewrite.
 *
 * The places are where a file is named by its key (or an address built from it) instead of its id:
 * a post's featured picture (entries.meta.image_url) and the drafts, history and pending changes that
 * hold a copy of it, file buttons (`do.file`) and image props in page and shared-block bodies, the
 * heading-decor mask and the old brand addresses in settings, category and product meta. The rewrite
 * is the same for all of them (MediaKeyRewriter): a string that is a moved key, or an address on the
 * media host whose path ends with one. Strings under the website's old folders that name no file of
 * its library are counted as unmatched and left as they are (their objects stay, so they keep
 * working); so are addresses on another host whose path ends with a moved key, counted apart as
 * "unmatched (other host)". A key that cannot be given a new one (no extension) is listed as
 * skipped and left for a person; the rest of the website still moves.
 *
 * Lives in Admin because it writes the content of several modules (Media depends on none of them);
 * the module list here is data, the work is in the Media module.
 */
final class RekeySiteMedia extends Command
{
    /** @var list<array{0: string, 1: string}> [table, jsonb column] that can name a file by its key */
    public const array PLACES = [
        ['entries', 'meta'],
        ['entry_translations', 'body'],
        ['entry_drafts', 'content'],
        ['entry_revisions', 'snapshot'],
        ['content_block_translations', 'body'],
        ['site_settings', 'value'],
        ['taxonomies', 'meta'],
        ['products', 'meta'],
        ['menus', 'meta'],
        ['menu_items', 'meta'],
        ['content_sections', 'meta'],
        ['site_profiles', 'meta'],
        ['forms', 'settings'],
    ];

    #[\Override]
    protected $signature = 'media:rekey-site
        {site : The website\'s id or code}
        {--apply : Copy the files and write the new keys (without it, only count)}';

    #[\Override]
    protected $description = 'Move a website\'s files filed before website codes to {code}/{file} and rewrite every stored reference';

    public function handle(Tenancy $tenancy, SiteMediaRekey $rekey): int
    {
        if (config('media.tenant_prefix', true) !== true) {
            $this->error('Refused: this install keeps every website\'s files under its collections (media.tenant_prefix is off); there is nothing to move.');

            return self::FAILURE;
        }

        $site = $this->site(Cast::string($this->argument('site')));
        if ($site === null) {
            $this->error('No website with that id or code.');

            return self::FAILURE;
        }
        $siteId = Cast::int($site->getKey());
        $code = $site->code;
        $apply = $this->option('apply') === true;

        ['moves' => $plan, 'skipped' => $skipped] = $rekey->plan($siteId, $code);
        $oldFolders = Cast::strings(DB::table('reserved_site_codes')->where('reason', 'legacy_key')->where('site_id', $siteId)->pluck('code')->all());

        $this->line(($apply ? 'Moving' : 'Dry run for')." the files of website #{$siteId} ({$site->name}) to {$code}/.");
        $this->line('Files to move: '.count($plan).'.');
        if ($skipped !== []) {
            $this->warn('Files skipped (move them by hand): '.count($skipped).'.');
            foreach (array_slice($skipped, 0, 20) as $skip) {
                $this->warn("  skipped: {$skip['key']} ({$skip['reason']})");
            }
        }

        if (! $apply) {
            $rewriter = new MediaKeyRewriter([...$rekey->previousKeys($siteId), ...array_column($plan, 'to', 'from')], $oldFolders);
            $this->report($rekey->rewriteReferences($siteId, $rewriter, self::PLACES, false));
            $this->line('Nothing was written. Run again with --apply to move them.');

            return self::SUCCESS;
        }

        MediaKey::assertConfigured();
        try {
            $copy = $rekey->copyObjects($siteId, $code, $plan, fn (int $done) => $this->line("  objects: {$done}/".count($plan)));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line("Objects copied: {$copy['copied']}, already there: {$copy['present']}, missing from storage: ".count($copy['missing']).
            ", new key grown past another object: {$copy['regrown']}.");
        foreach (array_slice($copy['missing'], 0, 20) as $key) {
            $this->warn("  missing: {$key} (its key moves; there was no file to copy)");
        }

        $moved = 0;
        $references = [];
        try {
            foreach (array_chunk($copy['moves'], SiteMediaRekey::MOVE_CHUNK) as $chunk) {
                $done = $rekey->moveChunk($siteId, $chunk, self::PLACES);
                $moved += count($done['moved']);
                $references = self::add($references, $done['references']);
                $this->line("  keys: {$moved}/".count($plan));
            }
            $repair = $rekey->rewriteReferences($siteId, new MediaKeyRewriter($rekey->previousKeys($siteId), $oldFolders), self::PLACES, true);
            $references = self::add($references, $repair, unmatchedFrom: $repair);
            $covers = $tenancy->enter($siteId, fn (): array => EntryCoverUsage::reconcileSite($siteId));
        } catch (Throwable $e) {
            // Each chunk is one transaction: those before the failure stay (keys and references
            // together), the failed one left nothing. The copies stay and are reused by the next run.
            $this->error("Stopped after {$moved} of ".count($plan).' keys: '.$e->getMessage());
            $this->line('Run the command again to finish (an upload that took one of the new keys meanwhile is the usual cause).');

            return self::FAILURE;
        }

        $this->report($references);
        $this->line("Keys moved: {$moved} of ".count($plan).'. Featured-picture usage: '.
            "{$covers['inserted']} added, {$covers['deleted']} removed.");
        if ($moved < count($plan)) {
            $this->warn('Some files changed while this ran and were left as they are; run the command again.');
        }

        return self::SUCCESS;
    }

    /**
     * Per-place sums of two reports; the unmatched strings come from $unmatchedFrom only (the pass
     * that knew every moved key), else they are kept from $sum.
     *
     * @param  array<string, array{rows: int, references: int, unmatched: int, samples: list<string>, other_host: int, other_samples: list<string>}>  $sum
     * @param  array<string, array{rows: int, references: int, unmatched: int, samples: list<string>, other_host: int, other_samples: list<string>}>  $more
     * @param  array<string, array{rows: int, references: int, unmatched: int, samples: list<string>, other_host: int, other_samples: list<string>}>|null  $unmatchedFrom
     * @return array<string, array{rows: int, references: int, unmatched: int, samples: list<string>, other_host: int, other_samples: list<string>}>
     */
    private static function add(array $sum, array $more, ?array $unmatchedFrom = null): array
    {
        foreach ($more as $place => $line) {
            $old = $sum[$place] ?? ['rows' => 0, 'references' => 0, 'unmatched' => 0, 'samples' => [], 'other_host' => 0, 'other_samples' => []];
            $from = $unmatchedFrom !== null ? ($unmatchedFrom[$place] ?? null) : $old;
            $sum[$place] = [
                'rows' => $old['rows'] + $line['rows'],
                'references' => $old['references'] + $line['references'],
                'unmatched' => $from['unmatched'] ?? 0,
                'samples' => $from['samples'] ?? [],
                'other_host' => $from['other_host'] ?? 0,
                'other_samples' => $from['other_samples'] ?? [],
            ];
        }

        return $sum;
    }

    /**
     * The website named by its code or its id, the Trash excluded: a value that can be a code is
     * looked up as one first (a code may be all digits), then as an id.
     */
    private function site(string $given): ?Site
    {
        $byCode = preg_match(MediaKey::CODE_PATTERN, $given) === 1 ? Site::query()->where('code', $given)->first() : null;

        return $byCode ?? (ctype_digit($given) ? Site::query()->whereKey((int) $given)->first() : null);
    }

    /** @param  array<string, array{rows: int, references: int, unmatched: int, samples: list<string>, other_host: int, other_samples: list<string>}>  $references */
    private function report(array $references): void
    {
        $this->table(
            ['Place', 'Rows', 'References', 'Unmatched', 'Unmatched (other host)'],
            array_map(static fn (string $place, array $line): array => [$place, $line['rows'], $line['references'], $line['unmatched'], $line['other_host']], array_keys($references), $references),
        );
        foreach ($references as $place => $line) {
            foreach ($line['samples'] as $sample) {
                $this->warn("  unmatched in {$place}: {$sample}");
            }
            foreach ($line['other_samples'] as $sample) {
                $this->warn("  unmatched (other host) in {$place}: {$sample} (not rewritten)");
            }
        }
    }
}
