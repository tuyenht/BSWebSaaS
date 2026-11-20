<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Support\EntryFormPlacements;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormPlacement;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Check `form_placements` (which pages show which form) against the «Biểu mẫu» blocks of every page
 * language, and write again the ones that differ; rows of a language that no longer exists go. Every
 * save keeps the rows (EntryTranslation's model events), so this covers what never passed through a
 * save: the fill after the deploy that adds the table, a form created after a page already named its
 * key, or a drift. Safe to re-run: a page language whose rows are right is not written, so a second
 * run corrects 0.
 *
 * Site by site in each site's tenant context, --chunk page languages at a time by id (keyset), each
 * chunk in a transaction of its own; the Trash and drafts too (what is live is decided when reading).
 * A site that fails is logged and skipped, and the command exits non-zero.
 */
final class ReconcileFormPlacements extends Command
{
    #[\Override]
    protected $signature = 'forms:placements
        {--site= : Only this site (its id); default every site}
        {--chunk=500 : Page languages read per step}';

    #[\Override]
    protected $description = 'Check which pages show which form against the page bodies, and write again the ones that differ (safe to re-run)';

    public function handle(Tenancy $tenancy): int
    {
        $chunk = max(1, Cast::int($this->option('chunk'), 500));
        $only = $this->option('site');
        $siteIds = $only !== null ? [Cast::int($only)] : $tenancy->siteIds();
        $sites = $checked = $corrected = $failed = 0;

        foreach ($siteIds as $siteId) {
            $sites++;
            try {
                $after = 0;
                do {
                    [$read, $after, $fixed] = $tenancy->enter($siteId, fn (): array => $this->step($siteId, $after, $chunk));
                    $checked += $read;
                    $corrected += $fixed;
                } while ($read === $chunk);

                $corrected += $tenancy->enter($siteId, fn (): int => EntryFormPlacements::dropOrphans());
            } catch (Throwable $e) {
                $failed++;
                Log::error('Form placements check failed for a site', ['site_id' => $siteId, 'exception' => $e]);
                $this->error("Site {$siteId}: {$e->getMessage()}");
            }
        }

        $this->info("Checked {$checked} page language(s) on {$sites} site(s); corrected {$corrected}.");
        if ($failed > 0) {
            $this->error("Failed on {$failed} site(s); see the log.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * One chunk of the current site's page languages after id $after: each one whose rows differ from
     * its body is written again.
     *
     * @return array{0: int, 1: int, 2: int} [read, last id read, corrected]
     */
    private function step(int $siteId, int $after, int $chunk): array
    {
        $rows = EntryTranslation::query()->where('id', '>', $after)->orderBy('id')->limit($chunk)
            ->get(['id', 'site_id', 'entry_id', 'locale', 'body']);
        if ($rows->isEmpty()) {
            return [0, $after, 0];
        }

        // the rows these languages have now, as sorted form-id lists per "entry|locale"
        $have = [];
        foreach (FormPlacement::query()->whereIn('entry_id', $rows->pluck('entry_id')->unique()->values()->all())
            ->orderBy('form_id')->get(['entry_id', 'locale', 'form_id']) as $placement) {
            $have[$placement->entry_id.'|'.$placement->locale][] = Cast::int($placement->form_id);
        }

        // the site's forms by key (a handful: FormDraft::MAX_FORMS)
        $formIds = [];
        foreach (Form::query()->get(['id', 'key']) as $form) {
            $formIds[$form->key] = Cast::int($form->id);
        }

        $fixed = 0;
        foreach ($rows as $row) {
            $keys = EntryFormPlacements::keysIn($row->body);
            $want = array_values(array_filter(array_map(fn (string $key): ?int => $formIds[$key] ?? null, $keys), fn (?int $id): bool => $id !== null));
            sort($want);
            $rowsNow = $have[$row->entry_id.'|'.$row->locale] ?? [];
            if ($want !== $rowsNow && EntryFormPlacements::write($siteId, $row->entry_id, $row->locale, $want, $rowsNow)) {
                $fixed++;
            }
        }

        return [$rows->count(), Cast::int($rows->last()->id), $fixed];
    }
}
