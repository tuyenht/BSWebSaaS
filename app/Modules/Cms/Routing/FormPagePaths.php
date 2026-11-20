<?php

declare(strict_types=1);

namespace App\Modules\Cms\Routing;

use App\Modules\Forms\Models\FormPlacement;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Database\Query\JoinClause;

/**
 * Where a visitor sees each form: for «Xem trang có biểu mẫu» on the «Biểu mẫu» list and «Đang có
 * trên trang» in the form's editor. Reads `form_placements` (kept by the page save), never page
 * bodies, in one query for the whole list. Only a switched-on form, and only a page a visitor can open, counts — published, its
 * translation in that language published, not in the Trash, with its route, in a language visitors
 * can open (public) — the same test as
 * EntryPageLinks; the locale prefix comes from PathBuilder. Every table is site-scoped (the
 * placement's global scope, the joins on site_id, RLS behind them), so another site's page is never
 * named.
 */
final readonly class FormPagePaths
{
    public function __construct(private PathBuilder $paths, private SiteLocale $locales) {}

    /**
     * Form id => the public path of one page showing it: a page in $preferredLocale first, then the
     * page that got the form first.
     *
     * @param  list<int>  $formIds
     * @return array<int, string>
     */
    public function of(array $formIds, string $preferredLocale): array
    {
        if ($formIds === []) {
            return [];
        }

        $rows = FormPlacement::query()
            // a switched-off form shows nothing on its pages (FormComponent renders active forms only)
            ->join('forms', fn (JoinClause $join) => $join->on('forms.id', '=', 'form_placements.form_id')
                ->whereColumn('forms.site_id', 'form_placements.site_id'))
            ->where('forms.status', 'active')
            ->join('entries', fn (JoinClause $join) => $join->on('entries.id', '=', 'form_placements.entry_id')
                ->whereColumn('entries.site_id', 'form_placements.site_id'))
            ->join('entry_translations', fn (JoinClause $join) => $join->on('entry_translations.entry_id', '=', 'form_placements.entry_id')
                ->whereColumn('entry_translations.locale', 'form_placements.locale')
                ->whereColumn('entry_translations.site_id', 'form_placements.site_id'))
            ->join('routes', fn (JoinClause $join) => $join->on('routes.entry_id', '=', 'form_placements.entry_id')
                ->whereColumn('routes.locale', 'form_placements.locale')
                ->whereColumn('routes.site_id', 'form_placements.site_id'))
            ->whereIn('form_placements.form_id', $formIds)
            // a language visitors cannot open yet (enabled, not public) has no page to link to
            ->whereIn('form_placements.locale', $this->locales->public())
            ->where('entries.status', 'published')
            ->whereNull('entries.deleted_at')
            ->where('entry_translations.status', 'published')
            ->selectRaw('distinct on (form_placements.form_id) form_placements.form_id, form_placements.locale, routes.path')
            ->orderBy('form_placements.form_id')
            ->orderByRaw('(form_placements.locale = ?) desc', [$preferredLocale])
            ->orderBy('form_placements.id')
            ->toBase()
            ->get();

        $found = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $found[Cast::int($row['form_id'] ?? 0)] = $this->paths->publicUrl(Cast::string($row['path'] ?? ''), Cast::string($row['locale'] ?? ''));
        }

        return $found;
    }
}
