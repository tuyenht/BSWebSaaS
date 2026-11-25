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
 * already shared keep working), then in ONE transaction under the website's tenant context moves the
 * keys, rewrites every place below and rebuilds the featured-picture usage rows. Idempotent and
 * resumable: a second run finds nothing to move.
 *
 * The places are where a file is named by its key (or an address built from it) instead of its id:
 * a post's featured picture (entries.meta.image_url) and the drafts, history and pending changes that
 * hold a copy of it, file buttons (`do.file`) and image props in page and shared-block bodies, the
 * heading-decor mask and the old brand addresses in settings, category and product meta. The rewrite
 * is the same for all of them (MediaKeyRewriter): a string that is a moved key, or an address whose
 * path ends with one. Strings under the website's old folders that name no file of its library are
 * counted as unmatched and left as they are (their objects stay, so they keep working).
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

        $plan = $tenancy->enter($siteId, fn (): array => $rekey->plan($siteId, $code));
        $oldFolders = Cast::strings(DB::table('reserved_site_codes')->where('reason', 'legacy_key')->where('site_id', $siteId)->pluck('code')->all());

        $this->line(($apply ? 'Moving' : 'Dry run for')." the files of website #{$siteId} ({$site->name}) to {$code}/.");
        $this->line('Files to move: '.count($plan).'.');

        if (! $apply) {
            $rewriter = new MediaKeyRewriter(array_column($plan, 'to', 'from'), $oldFolders);
            $this->report($tenancy->enter($siteId, fn (): array => $rekey->rewriteReferences($siteId, $rewriter, self::PLACES, false)));
            $this->line('Nothing was written. Run again with --apply to move them.');

            return self::SUCCESS;
        }

        MediaKey::assertConfigured();
        try {
            $copy = $rekey->copyObjects($plan, fn (int $done) => $this->line("  objects: {$done}/".count($plan)));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line("Objects copied: {$copy['copied']}, already there: {$copy['present']}, missing from storage: ".count($copy['missing']).'.');
        foreach (array_slice($copy['missing'], 0, 20) as $key) {
            $this->warn("  missing: {$key} (its key moves; there was no file to copy)");
        }

        try {
            $result = $tenancy->enter($siteId, function () use ($rekey, $siteId, $plan, $oldFolders): array {
                $moved = $rekey->moveKeys($siteId, $plan);
                $references = $rekey->rewriteReferences($siteId, new MediaKeyRewriter($moved, $oldFolders), self::PLACES, true);
                $covers = EntryCoverUsage::reconcileSite($siteId);

                return ['moved' => count($moved), 'references' => $references, 'covers' => $covers];
            });
        } catch (Throwable $e) {
            // One transaction: nothing of it was kept. The copies stay and are reused by the next run.
            $this->error('Nothing was written to the database: '.$e->getMessage());
            $this->line('Run the command again (an upload that took one of the new keys meanwhile is the usual cause).');

            return self::FAILURE;
        }

        $this->report($result['references']);
        $this->line("Keys moved: {$result['moved']} of ".count($plan).'. Featured-picture usage: '.
            "{$result['covers']['inserted']} added, {$result['covers']['deleted']} removed.");
        if ($result['moved'] < count($plan)) {
            $this->warn('Some files changed while this ran and were left as they are; run the command again.');
        }

        return self::SUCCESS;
    }

    /** The website named by its id or its code, the Trash excluded. */
    private function site(string $given): ?Site
    {
        $query = Site::query();

        return ctype_digit($given)
            ? $query->whereKey((int) $given)->first()
            : (preg_match(MediaKey::CODE_PATTERN, $given) === 1 ? $query->where('code', $given)->first() : null);
    }

    /** @param  array<string, array{rows: int, references: int, unmatched: int, samples: list<string>}>  $references */
    private function report(array $references): void
    {
        $this->table(
            ['Place', 'Rows', 'References', 'Unmatched'],
            array_map(static fn (string $place, array $line): array => [$place, $line['rows'], $line['references'], $line['unmatched']], array_keys($references), $references),
        );
        foreach ($references as $place => $line) {
            foreach ($line['samples'] as $sample) {
                $this->warn("  unmatched in {$place}: {$sample}");
            }
        }
    }
}
