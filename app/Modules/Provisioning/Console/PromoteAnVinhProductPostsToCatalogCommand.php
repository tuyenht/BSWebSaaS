<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Provisioning\Actions\PromoteAnVinhProductPostsToCatalog;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Domain;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * One-time data migration: promote An Vinh's product-post entries to real catalog Product rows,
 * preserving their live URL. Idempotent — a post already promoted is trashed and no longer
 * matches the source query, and a Product already migrated is updated rather than duplicated, so
 * a re-run is always safe.
 *
 * Always run --dry-run first and review the printed table: it previews every write a real run
 * would make (same code path, rolled back) with nothing persisted. The real run soft-deletes
 * posts and moves their routes — recoverable via Trash, but not a trivial undo — so it prompts
 * for confirmation in production unless --force is given (ConfirmableTrait's standard behavior).
 */
final class PromoteAnVinhProductPostsToCatalogCommand extends Command
{
    use ConfirmableTrait;

    #[\Override]
    protected $signature = 'anvinh:promote-products
        {--host=av1.baoson.net : Primary hostname of the An Vinh site}
        {--dry-run : Preview the migration — no changes are written}
        {--force : Skip the confirmation prompt}';

    #[\Override]
    protected $description = 'Promote An Vinh product-posts to catalog Product rows, preserving their URL (idempotent).';

    public function handle(Tenancy $tenancy, PromoteAnVinhProductPostsToCatalog $action): int
    {
        $host = $this->option('host');
        $host = is_string($host) ? $host : '';

        $domain = Domain::query()->where('hostname', $host)->first();
        if ($domain === null) {
            $this->error("No site found for host '{$host}'.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->confirmToProceed('This soft-deletes product posts and moves their routes to new Product rows.')) {
            return self::FAILURE;
        }

        /** @var list<array{postSlug: string, productUrl: string, action: string, error?: string}> $summary */
        $summary = $tenancy->enter($domain->site_id, fn (): array => $action->handle($dryRun));

        $this->table(
            ['Post slug', 'Product URL', 'Action', 'Error'],
            array_map(
                static fn (array $row): array => [$row['postSlug'], $row['productUrl'], $row['action'], $row['error'] ?? ''],
                $summary,
            ),
        );

        $failed = array_filter($summary, static fn (array $row): bool => str_contains($row['action'], 'fail'));

        if ($failed !== []) {
            $this->error(count($failed).' item(s) failed — see the table above.');

            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'Dry run complete — no changes were written. Re-run without --dry-run to apply.'
            : count($summary).' product(s) promoted.');

        return self::SUCCESS;
    }
}
