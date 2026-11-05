<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Categories;

use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Cms\Taxonomy\CategoryLimitGuard;
use App\Modules\Cms\Taxonomy\TaxonomyRules;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Support\Facades\Gate;

/**
 * Where "Chuyển tới…" may put a category: every possible parent, each with whether it is allowed
 * and, when not, the reason code and sentence the dialog shows — by the same rules MoveTaxonomy
 * holds the move to (it checks them all again when the move is made):
 *
 *  - a category never goes under itself or its own branch (those are not offered);
 *  - the tree stays within TaxonomyRules::MAX_DEPTH levels: the parent's level plus the height of
 *    the moved branch (depth_exceeded);
 *  - posts whose section runs an approval workflow never leave it (workflow_governed);
 *  - a member limited to some categories moves only onto a parent inside them
 *    (outside_category_limits), and nobody takes a branch into or out of another member's limits
 *    without being able to set those limits by hand (changes_member_limits);
 *  - a parent in another section moves the posts with it, which the person confirms (`confirm`).
 *
 * Post categories are grouped by section — the one on screen first, then the others the viewer
 * may see — each starting with the section's root; a post category never goes to the outer
 * level. Product categories may (key null). A section's root itself is never moved
 * (section_bound). A fixed number of queries whatever the number of candidates.
 */
final readonly class CategoryMoveTargets
{
    public function __construct(
        private CategoryScreen $screen,
        private CategoryScopes $scopes,
        private CategoryLimitGuard $guard,
        private EntryWorkflowResolver $workflows,
        private SiteLocale $locales,
    ) {}

    /**
     * @param  array{key: string, label: string, vocabulary: string, root: int|null, prefix: string}  $section
     * @return array{items: int, governed: bool, groups: list<array{section: string|null, label: string, current: bool, locked: bool, options: list<array<string, mixed>>}>}
     *
     * @throws TaxonomyChangeRejectedException section_bound, outside_category_limits
     */
    public function for(array $section, Taxonomy $moving, User $viewer, string $query = ''): array
    {
        $forest = $this->screen->forest($section);
        if ($forest->isSectionRoot($moving->id)) {
            throw new TaxonomyChangeRejectedException('section_bound');
        }
        if (! Gate::forUser($viewer)->allows('move', $moving)) {
            throw new TaxonomyChangeRejectedException('outside_category_limits');
        }

        $branch = TaxonomyTree::branchOf($moving->id);
        $height = TaxonomyTree::heightOf($branch);
        $from = $forest->sectionOf($moving->id);
        // The posts that follow the moved category: its branch minus any sub-branch with a section of its own.
        $following = array_values(array_filter(array_keys($branch), static fn (int $id): bool => $forest->sectionOf($id) === $from));
        $items = $forest->items($following);
        $governed = $from !== null && $items > 0 && $this->workflows->forCategory($moving) !== null;

        $context = [
            'forest' => $forest, 'moving' => $moving, 'branch' => $branch, 'height' => $height, 'from' => $from,
            'items' => $items, 'governed' => $governed, 'limited' => $this->scopes->isLimited($viewer), 'viewer' => $viewer,
            'limits' => $this->scopes->siteLimits(), 'before' => $forest->ancestors($moving->id),
        ];
        $needle = CategoryScreen::fold($query);
        $candidates = $this->screen->candidates($section, $forest, $branch, $viewer, outerLevel: true);
        $names = $this->screen->translations(array_values(array_filter(array_merge(...array_column($candidates, 'ids')), static fn (?int $id): bool => $id !== null)));
        $main = $this->locales->default();
        $memo = [];

        $groups = [];
        foreach ($candidates as $group) {
            $options = [];
            foreach ($group['ids'] as $id) {
                $row = $id === null ? null : $forest->get($id);
                if ($id !== null && $row?->ulid === null) {
                    continue;
                }
                $name = $row === null ? '' : $this->screen->nameOf($names[$id] ?? [], $main);
                if ($needle !== '' && ($row === null || ! str_contains(CategoryScreen::fold($name), $needle))) {
                    continue;
                }
                $options[] = $this->option($context, $row, $name, $memo);
            }
            if ($options !== []) {
                $groups[] = [
                    'section' => $group['section'], 'label' => $group['label'], 'current' => $group['section'] === $section['key'] || $group['section'] === null,
                    'locked' => $governed && $group['section'] !== null && $group['section'] !== $from, 'options' => $options,
                ];
            }
        }

        return ['items' => $items, 'governed' => $governed, 'groups' => $groups];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, bool>  $memo
     * @return array<string, mixed>
     */
    private function option(array $context, ?Taxonomy $parent, string $name, array &$memo): array
    {
        /** @var CategoryForest $forest */
        $forest = $context['forest'];
        /** @var Taxonomy $moving */
        $moving = $context['moving'];
        /** @var User $viewer */
        $viewer = $context['viewer'];
        $height = Cast::int($context['height']);
        $parentId = $parent?->id;
        $level = $parentId === null ? 0 : $forest->level($parentId);
        $to = $parentId === null ? null : $forest->sectionOf($parentId);
        $current = $forest->parentOf($moving->id) === $parentId;
        $changesSection = $to !== $context['from'] && $context['items'] > 0;

        $reason = null;
        if ($current) {
            $reason = null;
        } elseif ($level + $height > TaxonomyRules::MAX_DEPTH) {
            $reason = 'depth_exceeded';
        } elseif ($changesSection && $context['governed']) {
            $reason = 'workflow_governed';
        } elseif ($context['limited'] && ($parentId === null || ! $this->scopes->coversId($viewer, $parentId))) {
            $reason = 'outside_category_limits';
        } elseif (! $this->mayChangeReach($context, $parentId === null ? [] : [...$forest->ancestors($parentId), $parentId], $memo)) {
            $reason = 'changes_member_limits';
        }

        return [
            'key' => $parent?->ulid,
            'name' => $name,
            'level' => $level,
            'newLevel' => $level + 1,
            'root' => $parentId !== null && $forest->isSectionRoot($parentId),
            'current' => $current,
            'ok' => ! $current && $reason === null,
            'reason' => $reason,
            'message' => $reason === null ? null : __('categories.reason_'.$reason, ['depth' => TaxonomyRules::MAX_DEPTH]),
            'confirm' => ! $current && $reason === null && $changesSection,
        ];
    }

    /**
     * CategoryScopes::membersReachChangedBy()'s rule, over the limits read once: the members whose
     * limits cover the branch now but not under the new parent, or the other way round — and
     * whether the viewer may cause that (CategoryLimitGuard::mayChangeLimitsOf, asked once per set).
     *
     * @param  array<string, mixed>  $context
     * @param  list<int>  $after
     * @param  array<string, bool>  $memo
     */
    private function mayChangeReach(array $context, array $after, array &$memo): bool
    {
        /** @var list<int> $before */
        $before = $context['before'];
        /** @var list<array{id: int, name: string, level: int, roots: list<int>}> $limits */
        $limits = $context['limits'];

        $changed = [];
        foreach ($limits as $member) {
            if ((array_intersect($member['roots'], $before) !== []) !== (array_intersect($member['roots'], $after) !== [])) {
                $changed[] = ['id' => $member['id'], 'name' => $member['name'], 'level' => $member['level']];
            }
        }
        if ($changed === []) {
            return true;
        }

        $key = implode(',', array_column($changed, 'id'));

        return $memo[$key] ??= $this->guard->mayChangeLimitsOf($changed);
    }
}
