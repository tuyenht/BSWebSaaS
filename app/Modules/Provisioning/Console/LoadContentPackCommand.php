<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Provisioning\ContentPack\Applier;
use App\Modules\Provisioning\ContentPack\Backup;
use App\Modules\Provisioning\ContentPack\CanonicalHash;
use App\Modules\Provisioning\ContentPack\Ledger;
use App\Modules\Provisioning\ContentPack\MediaStep;
use App\Modules\Provisioning\ContentPack\Pack;
use App\Modules\Provisioning\ContentPack\PackReader;
use App\Modules\Provisioning\ContentPack\PackRejected;
use App\Modules\Provisioning\ContentPack\PackValidator;
use App\Modules\Provisioning\ContentPack\Plan;
use App\Modules\Provisioning\ContentPack\PlanItem;
use App\Modules\Provisioning\ContentPack\Planner;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Support\SiteWriteLock;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Load a content pack (`database/content-packs/<pack>/`) into one site. A dry run by default: it
 * prints the plan and its fingerprint and writes nothing. `--apply --plan=<fingerprint>` writes
 * exactly that plan — recomputed inside the write transaction; a staff edit or pack change in
 * between changes the fingerprint and the command refuses. Never deletes; never overwrites what
 * staff changed since the last load unless the item is named with --force-item (`-v` lists each
 * changed field trimmed, `-vv` prints it in full: what the site holds next to what the pack would
 * write, to compare before forcing). Runs as the app
 * connection with the site's tenant context (RLS on) — allowed on production.
 *
 * `--media` runs the image step instead (MediaStep): the same dry run / `--apply --plan` pair,
 * uploading the pack's image files to the site's media library. Run it before the content load
 * that should use the images; that load then resolves each slot to its uploaded row.
 */
final class LoadContentPackCommand extends Command
{
    #[\Override]
    protected $signature = 'site:load-content
        {pack : Folder name under database/content-packs/}
        {--host= : The site\'s hostname}
        {--apply : Write the plan (needs --plan)}
        {--plan= : The fingerprint the dry run printed}
        {--force-item=* : Overwrite an item the plan skips (exact key; repeatable)}
        {--media : Upload the pack\'s image files (image step)}
        {--claims : Print every unverified claim of the pack and stop}
        {--json : Print the plan as JSON}';

    #[\Override]
    protected $description = 'Load a content pack into a site (dry run unless --apply --plan=<fingerprint>).';

    public function handle(PackReader $reader, PackValidator $validator, SiteResolver $resolver, Tenancy $tenancy): int
    {
        try {
            return $this->load($reader, $validator, $resolver, $tenancy);
        } catch (PackRejected $e) {
            $this->error($e->getMessage());
            foreach ($e->reasons as $reason) {
                $this->line('  - '.$reason);
            }

            return self::FAILURE;
        }
    }

    private function load(PackReader $reader, PackValidator $validator, SiteResolver $resolver, Tenancy $tenancy): int
    {
        $code = Cast::string($this->argument('pack'));
        $pack = $reader->read($code);

        $errors = $validator->validate($pack, $code);
        if ($errors !== []) {
            throw new PackRejected("Refused: the pack '{$code}' is not valid (".count($errors).' problems).', $errors);
        }

        if ($this->option('claims') === true) {
            $this->printClaims($pack);

            return self::SUCCESS;
        }

        $host = SiteResolver::normalize(Cast::string($this->option('host'))) ?? '';
        $resolved = $host === '' ? null : $resolver->resolve($host);
        if ($resolved === null) {
            throw new PackRejected("Refused: no site answers to '{$host}'.");
        }
        if (! in_array($host, $pack->hosts, true)) {
            throw new PackRejected("Refused: the pack '{$code}' is not meant for {$host} (its hosts: ".implode(', ', $pack->hosts).').');
        }

        $site = Site::query()->whereKey($resolved->siteId)->first(['id', 'ulid', 'name']);
        if ($site === null) {
            throw new PackRejected("Refused: no site answers to '{$host}'.");
        }
        $forced = array_values(array_unique(Cast::strings($this->option('force-item'))));
        $siteId = $site->id;

        if ($this->option('media') === true) {
            return $this->media($pack, $site, $host, $forced, $tenancy);
        }

        $planner = app(Planner::class);
        $plan = $tenancy->enter($siteId, fn (): Plan => $planner->plan($pack, $site->ulid, $site->name, $host, $forced));

        if ($this->option('apply') !== true) {
            $this->option('json') === true
                ? $this->line(CanonicalHash::encode($plan->toArray()))
                : $this->printPlan($plan);

            return $plan->conflicts() === [] ? self::SUCCESS : self::FAILURE;
        }

        $expected = Cast::string($this->option('plan'));
        if ($expected === '') {
            throw new PackRejected('Refused: --apply needs --plan=<fingerprint> from a dry run.');
        }
        if ($plan->conflicts() !== []) {
            throw new PackRejected('Refused: the plan has conflicts; fix them and run the dry run again.', array_map(
                fn (PlanItem $item): string => $item->item->key.': '.implode('; ', $item->notes),
                $plan->conflicts(),
            ));
        }
        if (! hash_equals($plan->fingerprint, $expected)) {
            throw new PackRejected('Refused: plan changed, run the dry run again.');
        }

        $backupFile = null;
        if ($plan->writes() !== []) {
            $backup = app(Backup::class);
            $snapshot = $tenancy->enter($siteId, fn (): array => $backup->snapshot($plan, $planner));
            $backupFile = $backup->write($plan, $snapshot);
            $this->line("Backup: {$backupFile}");
        }

        try {
            $written = $tenancy->enter($siteId, function () use ($planner, $pack, $site, $host, $forced, $expected, $siteId, $backupFile): int {
                TaxonomyTree::lock($siteId);
                // Same order as every settings and profile save, so a staff save cannot deadlock with it.
                SiteWriteLock::take();
                // Re-planned with the rows it rests on locked, so nothing changes under the write.
                $plan = $planner->plan($pack, $site->ulid, $site->name, $host, $forced, lock: true);
                if (! hash_equals($plan->fingerprint, $expected)) {
                    throw new PackRejected('Refused: plan changed, run the dry run again.');
                }

                $written = app(Applier::class)->apply($plan, $planner);
                $this->recordLedger($plan, $planner);

                if ($written > 0) {
                    Audit::record('content_pack.applied', ['meta' => [
                        'pack' => $pack->code,
                        'version' => $pack->version,
                        'fingerprint' => $plan->fingerprint,
                        'backup' => $backupFile === null ? null : basename($backupFile),
                        'totals' => $plan->totals(),
                    ]]);
                }

                return $written;
            });
        } catch (PackRejected $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PackRejected('Refused: the load failed and was rolled back; nothing was changed. ('.$e->getMessage().')');
        }

        $this->info("Applied {$pack->code} v{$pack->version} to {$host}: {$written} items written.");

        return self::SUCCESS;
    }

    /**
     * Read every item back with the planner's own readers and record its hash. A written item must
     * read back exactly as planned, or the load is rolled back. A page's line also records its parent
     * page (the live one, unless the item names none and the line already recorded one), so a
     * later plan can tell a page staff moved. Skipped and orphaned items keep
     * their previous ledger line (a renamed category's under its new key), so a staff edit is never
     * taken for the loader's own write.
     */
    private function recordLedger(Plan $plan, Planner $planner): void
    {
        $after = $planner->plan($plan->pack, $plan->siteUlid, $plan->siteName, $plan->host, []);
        $parents = $planner->pageParents();
        $previous = $plan->ledger['items'] ?? [];
        $items = [];
        $drift = [];

        foreach ($after->items as $readBack) {
            $key = $readBack->item->key;
            $before = $plan->item($key);
            if ($before === null) {
                continue;
            }
            $hash = $readBack->currentHash();
            if ($before->writes() || $before->verdict === PlanItem::UNCHANGED) {
                if ($hash === null || $hash !== $before->desiredHash()) {
                    $diff = new PlanItem($readBack->item, $readBack->verdict, $readBack->current ?? [], $before->desired, null, null);
                    $drift[] = $key.' ('.implode(', ', array_keys($diff->changes())).')';

                    continue;
                }
                $line = ['hash' => $hash, 'ref' => (string) $readBack->ref];
                if ($readBack->item->kind === 'page' && $readBack->recordId !== null && array_key_exists($readBack->recordId, $parents)) {
                    // an item that names no parent keeps the parent its line recorded, so a later move by staff stays visible
                    $line['parent'] = ! array_key_exists('parent', $readBack->item->data) && isset($previous[$key]) && array_key_exists('parent', $previous[$key])
                        ? $previous[$key]['parent']
                        : $parents[$readBack->recordId];
                }
                $items[$key] = $line;
            } elseif (isset($previous[$key])) {
                $items[$key] = $previous[$key];
            } elseif (isset($previous[$plan->carried[$key] ?? ''])) {
                $items[$key] = $previous[$plan->carried[$key]];
            }
        }
        foreach ($plan->orphans as $key) {
            if (isset($previous[$key])) {
                $items[$key] = $previous[$key];
            }
        }

        if ($drift !== []) {
            throw new PackRejected('Refused: these items did not read back as planned, so the load was rolled back.', $drift);
        }

        Ledger::write($plan->pack->code, $plan->pack->version, $items, $plan->ledger);
    }

    /**
     * The image step: plan every slot with a file, print it (dry run), or upload exactly that plan.
     * Refused before anything is read from the site's media when another website shares its
     * storage folder.
     *
     * @param  list<string>  $forced
     */
    private function media(Pack $pack, Site $site, string $host, array $forced, Tenancy $tenancy): int
    {
        foreach ($forced as $key) {
            $slot = str_starts_with($key, MediaStep::KEY_PREFIX) ? substr($key, strlen(MediaStep::KEY_PREFIX)) : null;
            if ($slot === null || ! is_string($pack->slots[$slot]['file'] ?? null)) {
                throw new PackRejected("Refused: --force-item with --media takes media:<slot> keys of slots with a file ({$key} is not one).");
            }
        }

        $step = app(MediaStep::class);
        $step->assertFolderNotShared($site);
        $lines = $tenancy->enter($site->id, fn (): array => $step->plan($pack, $site, $forced));
        $fingerprint = MediaStep::fingerprint($pack, $site->ulid, $forced, $lines);

        if ($this->option('apply') !== true && $this->option('json') === true) {
            $this->line(CanonicalHash::encode(['site' => ['name' => $site->name, 'host' => $host, 'ulid' => $site->ulid], 'pack' => ['code' => $pack->code, 'version' => $pack->version], 'media' => $lines, 'fingerprint' => $fingerprint]));

            return self::SUCCESS;
        }

        $this->line("Site: {$site->name} ({$host})   Pack: {$pack->code} v{$pack->version}   Image step");
        foreach ($lines as $index => $line) {
            $this->line(sprintf('%-10s %-19s %-44s %s', $index === 0 ? 'MEDIA' : '', $line['forced'] ? $line['verdict'].' (forced)' : $line['verdict'], $line['key'], $this->mediaDetail($line)));
        }
        $counts = array_count_values(array_column($lines, 'verdict'));
        $totals = [];
        foreach ([MediaStep::UPLOAD, MediaStep::REUSE, MediaStep::IN_TRASH, MediaStep::RESTORE, MediaStep::REPAIR] as $verdict) {
            $totals[] = $verdict.' '.($counts[$verdict] ?? 0);
        }
        $this->line('Totals: '.implode(' · ', $totals));

        if ($this->option('apply') !== true) {
            $this->line("Plan fingerprint: {$fingerprint}   →  --media --apply --plan={$fingerprint}");

            return self::SUCCESS;
        }

        $expected = Cast::string($this->option('plan'));
        if ($expected === '') {
            throw new PackRejected('Refused: --apply needs --plan=<fingerprint> from a dry run.');
        }
        if (! hash_equals($fingerprint, $expected)) {
            throw new PackRejected('Refused: plan changed, run the dry run again.');
        }

        // One transaction per file: R2 is not transactional, so a failure keeps every file written
        // before it (its row with its object), and the next run reuses those by hash.
        $written = 0;
        try {
            foreach ($lines as $line) {
                if (! in_array($line['verdict'], MediaStep::WRITES, true)) {
                    continue;
                }
                $tenancy->enter($site->id, fn () => $step->write($pack, $site, $line, $pack->slots[$line['slot']]));
                $written++;
                $done = [MediaStep::UPLOAD => 'Uploaded', MediaStep::RESTORE => 'Restored', MediaStep::REPAIR => 'Repaired'][$line['verdict']];
                $this->line("{$done} {$line['key']} → {$line['storage_key']}");
            }
        } catch (Throwable $e) {
            throw new PackRejected("Refused: the image step stopped part-way ({$written} written); the files written are kept and the next run reuses them. ({$e->getMessage()})");
        } finally {
            // Also after a failure: the files written before it are on the site. Best-effort, so
            // an audit failure never hides why the run stopped.
            if ($written > 0) {
                try {
                    $tenancy->enter($site->id, fn () => Audit::record('content_pack.media_applied', ['meta' => [
                        'pack' => $pack->code,
                        'version' => $pack->version,
                        'fingerprint' => $fingerprint,
                        'written' => $written,
                    ]]));
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
        $this->info("Image step of {$pack->code} v{$pack->version} on {$host}: {$written} files written. Run the content load again so the pages use them.");

        return self::SUCCESS;
    }

    /** @param  array{verdict: string, key: string, file: string, size: int, storage_key: string}  $line */
    private function mediaDetail(array $line): string
    {
        $size = $line['size'] < 1024 ? $line['size'].' B' : number_format($line['size'] / 1024, 0).' KB';

        return match ($line['verdict']) {
            MediaStep::UPLOAD => "{$line['file']} ({$size}) → {$line['storage_key']}",
            MediaStep::REUSE => "the site already has this file ({$line['storage_key']})",
            MediaStep::IN_TRASH => "the site has this file in the Trash; left there (restore it with --force-item={$line['key']})",
            MediaStep::REPAIR => "the site has this file but its stored copy is missing; it is put back ({$line['storage_key']})",
            default => "taken back out of the Trash ({$line['storage_key']})",
        };
    }

    private function printClaims(Pack $pack): void
    {
        $claims = $pack->claims();
        if ($claims === []) {
            $this->line('No unverified claims.');

            return;
        }
        foreach ($claims as $key => $lines) {
            $this->line($key);
            foreach ($lines as $line) {
                $this->line('  - '.$line);
            }
        }
    }

    private function printPlan(Plan $plan): void
    {
        $ledger = $plan->ledger === null ? 'none (first load)' : "v{$plan->ledger['pack_version']} loaded {$plan->ledger['loaded_at']}";
        $this->line("Site: {$plan->siteName} ({$plan->host})   Pack: {$plan->pack->code} v{$plan->pack->version}   Ledger: {$ledger}");

        $labels = ['setting' => 'SETTINGS', 'profile' => 'PROFILE', 'taxonomy' => 'TAXONOMY', 'product' => 'PRODUCTS', 'post' => 'POSTS', 'page' => 'PAGES', 'menu' => 'MENUS', 'redirect' => 'REDIRECTS'];
        $verbose = $this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;
        // -vv: each changed field in full, so what staff wrote can be compared with the pack before a --force-item
        $full = $this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE;
        $shown = '';
        foreach ($plan->items as $planned) {
            $kind = $labels[$planned->item->kind] ?? strtoupper($planned->item->kind);
            $head = $kind === $shown ? '' : $kind;
            $shown = $kind;
            $detail = $planned->notes;
            if (in_array($planned->verdict, [PlanItem::UPDATE, PlanItem::ADOPT], true)) {
                $changed = array_keys($planned->changes());
                array_unshift($detail, (count($changed) > 3 ? implode(', ', array_slice($changed, 0, 3)).' …' : implode(', ', $changed)).' ('.count($changed).' changed)');
            }
            $verdict = $planned->forced ? $planned->verdict.' (forced)' : $planned->verdict;
            $this->line(sprintf('%-10s %-19s %-44s %s', $head, $verdict, $planned->item->key, implode(' · ', $detail)));

            if ($verbose && in_array($planned->verdict, [PlanItem::UPDATE, PlanItem::ADOPT, PlanItem::SKIP_EDITED], true)) {
                foreach ($planned->changes() as $field => [$old, $new]) {
                    $this->line($full
                        ? sprintf('%31s%s: %s', '', $field, CanonicalHash::encode(['site' => $old, 'pack' => $new]))
                        : sprintf('%31s%s: %s → %s', '', $field, self::trim($old), self::trim($new)));
                }
            }
        }

        foreach ($plan->orphans as $key) {
            $this->line(sprintf('%-10s %-19s %s', 'ORPHANS', 'orphan', $key.' (in the ledger, no longer in the pack; left as it is)'));
        }
        $media = $plan->media;
        $legacy = $media['resolved'] - $media['packed'];
        $none = $media['slots'] - $media['resolved'];
        $this->line(sprintf('%-10s %s', 'MEDIA', "{$media['slots']} slots: {$media['packed']} with the pack's own image, {$legacy} using existing images, {$none} with none (the pack's images are uploaded by --media)"));
        foreach ($plan->warnings as $index => $warning) {
            $this->line(sprintf('%-10s %s', $index === 0 ? 'WARNINGS' : '', $warning));
        }

        $totals = [];
        foreach ($plan->totals() as $verdict => $count) {
            $totals[] = "{$verdict} {$count}";
        }
        $this->line('Totals: '.implode(' · ', $totals));
        $this->line("Plan fingerprint: {$plan->fingerprint}   →  --apply --plan={$plan->fingerprint}");
        $this->line('Before --apply on production: take the pre-deploy database dump (docs/runbooks/prod-deploy.md).');
    }

    /** A value for the field diff: long text trimmed in the middle, with its full length. */
    private static function trim(mixed $value): string
    {
        $text = is_string($value) ? $value : CanonicalHash::encode($value);
        $length = mb_strlen($text);

        return $length > 80 ? mb_substr($text, 0, 38).' … '.mb_substr($text, -30)." ({$length} chars)" : $text;
    }
}
