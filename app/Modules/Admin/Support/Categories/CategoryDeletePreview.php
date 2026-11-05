<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Categories;

use App\Modules\Cms\Actions\Taxonomies\DeleteTaxonomy;
use App\Modules\Cms\Enums\CategoryChildrenOnDelete;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Support\Facades\Gate;

/**
 * What the delete (or merge) dialog shows before DeleteTaxonomy runs, so the person decides with
 * the numbers in front of them:
 *  - children: the direct sub-categories (lifted to the parent, or deleted with it);
 *  - categories: how many categories go (1, or the whole branch when the sub-categories go too);
 *  - items: the items whose main category goes (items_count — the kept counter, no live count);
 *  - leavingItems: of those, the ones that end up in another section (or in none) with the chosen
 *    target, which the person confirms (sectionChange);
 *  - menuLinks: the menu links (in the menu trash too) that follow the items onto $target —
 *    none without a target, as DeleteTaxonomy moves them only then;
 *  - limitedMembers: the names of the members whose limits name a category that goes;
 *  - locked: a section is built on one of them (never deleted); allowed: TaxonomyPolicy delete;
 *    governed: the section runs an approval workflow, so its posts may not leave it;
 *  - targets: where the items can go — same vocabulary, outside what goes (for a merge, outside
 *    the whole branch) — each with its refusal reason (workflow_governed, outside_category_limits).
 * A merge is a delete with the items moved and the sub-categories lifted (MergeTaxonomy).
 * A bounded number of queries, whatever the size of the tree.
 */
final readonly class CategoryDeletePreview
{
    public function __construct(
        private CategoryScreen $screen,
        private DeleteTaxonomy $delete,
        private SectionResolver $sections,
        private EntryWorkflowResolver $workflows,
        private CategoryScopes $scopes,
        private SiteLocale $locales,
    ) {}

    /**
     * @param  array{key: string, label: string, vocabulary: string, root: int|null, prefix: string}  $section
     * @return array<string, mixed>
     */
    public function for(array $section, Taxonomy $taxonomy, User $viewer, CategoryChildrenOnDelete $children, bool $merge = false, ?Taxonomy $target = null): array
    {
        $forest = $this->screen->forest($section);
        $branch = $forest->branch($taxonomy->id);
        $cascade = ! $merge && $children === CategoryChildrenOnDelete::Delete;
        $gone = $cascade ? $branch : [$taxonomy->id];
        $items = $forest->items($gone);
        $from = $forest->sectionOf($taxonomy->id);
        $governed = $from !== null && $items > 0 && $this->workflows->forCategory($taxonomy) !== null;

        $to = $target === null ? null : $forest->sectionOf($target->id);
        $leaving = $from !== null && $items > 0 && ($target === null || $to !== $from) ? $items : 0;
        $limited = $this->scopes->isLimited($viewer);

        $excluded = array_flip($merge || $cascade ? $branch : [$taxonomy->id]);
        $candidates = $this->screen->candidates($section, $forest, $excluded, $viewer, outerLevel: false);
        $names = $this->screen->translations(array_values(array_filter(array_merge(...array_column($candidates, 'ids')), static fn (?int $id): bool => $id !== null)));
        $main = $this->locales->default();

        $groups = [];
        foreach ($candidates as $group) {
            $options = [];
            foreach ($group['ids'] as $id) {
                if ($id === null) {
                    continue; // the outer level: a merge or a move of items needs a category
                }
                $row = $forest->get($id);
                if ($row?->ulid === null) {
                    continue;
                }
                $reason = null;
                if ($governed && $forest->sectionOf($id) !== $from) {
                    $reason = 'workflow_governed';
                } elseif ($limited && ! $this->scopes->coversId($viewer, $id)) {
                    $reason = 'outside_category_limits';
                }
                $options[] = [
                    'key' => $row->ulid,
                    'name' => $this->screen->nameOf($names[$id] ?? [], $main),
                    'level' => $forest->level($id),
                    'root' => $forest->isSectionRoot($id),
                    'ok' => $reason === null,
                    'reason' => $reason,
                    'message' => $reason === null ? null : __('categories.reason_'.$reason),
                ];
            }
            if ($options !== []) {
                $groups[] = ['section' => $group['section'], 'label' => $group['label'], 'options' => $options];
            }
        }

        $members = $this->delete->limitedMembers($taxonomy, $cascade ? CategoryChildrenOnDelete::Delete : CategoryChildrenOnDelete::Lift);

        return [
            'children' => count($forest->children($taxonomy->id)),
            'categories' => count($gone),
            'items' => $items,
            'leavingItems' => $leaving,
            'sectionChange' => $leaving > 0,
            // Exactly what the delete moves: only onto a target, and the links in the menu trash too.
            'menuLinks' => $target === null ? 0 : MenuItem::withTrashed()->where('routable_type', 'taxonomy')->whereIn('routable_id', $gone)->count(),
            'limitedMembers' => array_column($members, 'name'),
            'locked' => $this->sections->boundAmong($gone) !== [],
            'allowed' => Gate::forUser($viewer)->allows('delete', $taxonomy),
            'governed' => $governed,
            'targets' => $groups,
        ];
    }
}
