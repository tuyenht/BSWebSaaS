<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Provisioning\Actions\ReflowSitePermalinks;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * Reflow a site's opted-in permalink presets back to the flat default, moving every published
 * post + product route to `/{slug}` and recording a 301 from each old prefixed path.
 *
 * Always run --dry-run first and review the printed table: it previews every route move a real run
 * would make (same code path, rolled back) with nothing persisted, and flags any slug collision as
 * `conflict`. The real run moves live URLs — recoverable via the pre-run DB backup, not a trivial
 * undo — so it prompts for confirmation in production unless --force is given.
 */
final class ReflowSitePermalinksCommand extends Command
{
    use ConfirmableTrait;

    // --host is REQUIRED (no default): a default would let `reflow --force` with a forgotten flag
    // silently rewrite one specific site's live URLs, and hardcoding a tenant host would breach the
    // zero-hardcoded-tenant rule (CLAUDE.md §0). Missing host fails closed.
    #[\Override]
    protected $signature = 'permalinks:reflow
        {--host= : Primary hostname of the site to reflow (required)}
        {--dry-run : Preview the route moves — no changes are written}
        {--force : Skip the confirmation prompt}';

    #[\Override]
    protected $description = 'Reflow a site permalinks to the flat default, moving post + product routes to /{slug} with 301s.';

    public function handle(Tenancy $tenancy, ReflowSitePermalinks $action): int
    {
        $host = $this->option('host');
        $host = is_string($host) ? trim($host) : '';

        if ($host === '') {
            $this->error('Pass --host (the hostname of the site to reflow). There is no default, so a forgotten flag cannot rewrite the wrong site.');

            return self::FAILURE;
        }

        $domain = Domain::query()->where('hostname', $host)->first();
        if ($domain === null) {
            $this->error("No site found for host '{$host}'.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        // Name the target in the prompt so an operator confirms the site they meant, not a default.
        $name = Site::query()->whereKey($domain->site_id)->value('name');
        $siteName = is_string($name) ? $name : '(site)';
        if (! $dryRun && ! $this->confirmToProceed(
            "This moves live post + product URLs to /{slug} on {$siteName} (site #{$domain->site_id} · {$host}). Old paths keep working via 301."
        )) {
            return self::FAILURE;
        }

        /** @var list<array{type: string, title: string, locale: string, old: string, new: string, status: string}> $summary */
        $summary = $tenancy->enter($domain->site_id, fn (): array => $action->handle($dryRun));

        $this->table(
            ['Type', 'Title', 'Locale', 'Old path', 'New path', 'Status'],
            array_map(
                static fn (array $row): array => [$row['type'], $row['title'], $row['locale'], $row['old'], $row['new'], $row['status']],
                $summary,
            ),
        );

        $conflicts = array_filter($summary, static fn (array $row): bool => $row['status'] === 'conflict');
        $moved = array_filter($summary, static fn (array $row): bool => $row['status'] === 'moved');

        if ($conflicts !== []) {
            $this->error(count($conflicts).' route(s) collide on their flat slug — resolve those slugs, then re-run. Nothing was changed for them.');

            return self::FAILURE;
        }

        $this->info($dryRun
            ? count($moved).' route(s) would move to /{slug} — no changes were written. Re-run without --dry-run to apply.'
            : count($moved).' route(s) moved to /{slug}; old paths now 301 to them.');

        return self::SUCCESS;
    }
}
