<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Taxonomies;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Actions\Taxonomies\Concerns\EditsTaxonomyTree;
use App\Modules\Cms\Exceptions\LayoutBlocksRejectedException;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\BlockMediaUsage;
use App\Modules\Cms\Taxonomy\CategoryLimitGuard;
use App\Modules\Cms\Validation\LayoutSlotInput;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Theme\Rendering\LayoutSlots;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A category's own blocks for the slots around its posts, its list and its products
 * (LayoutSlots), in `taxonomies.meta.layout_blocks.<slot>`: per slot either one block list per
 * language, `{locale: {"blocks": […]}}`, or "none" (the slot stays empty in every language); null
 * removes the category's choice, so the site's default applies again ("Theo website"). Slots the
 * call does not name keep what they have. Only post and product categories carry slots.
 *
 * Every value is checked as a page body is (LayoutSlotInput: a known slot, the site's languages,
 * ValidPageJson in the site's context, the slot's block limit); a refusal is a
 * TaxonomyChangeRejectedException whose previous exception names the slot and the plain-language
 * text. Only `meta.layout_blocks` is written (a JSON path update), so every other key of `meta`
 * stays as it is, and the other category writers never touch `meta`, so they keep this one.
 *
 * Asks TaxonomyPolicy first (`taxonomies.manage`, then the update ability for this category: a
 * member limited to some categories stays inside them, held again inside the lock by
 * CategoryLimitGuard). One transaction under the tree lock, with the library's "in use" rows of the
 * category (BlockMediaUsage, owner `taxonomy`) written in it.
 */
final readonly class SetCategoryLayoutBlocks
{
    use EditsTaxonomyTree;

    /** The vocabularies whose categories have posts or products to put slots around. */
    private const array VOCABULARIES = ['post_cat', 'product_cat'];

    public function __construct(
        private LayoutSlotInput $input,
        private BlockMediaUsage $usage,
        private CurrentSite $site,
        private CategoryLimitGuard $limits,
    ) {}

    /**
     * @param  array<array-key, mixed>  $slots  slot → {locale: {"blocks": […]}} | "none" | null
     *
     * @throws AuthorizationException
     * @throws TaxonomyChangeRejectedException
     */
    public function handle(Taxonomy $taxonomy, array $slots): Taxonomy
    {
        $this->authorizeManage();
        $user = Auth::user();
        if ($user instanceof User) {
            Gate::forUser($user)->authorize('update', $taxonomy);
        }
        if (! in_array($taxonomy->vocabulary, self::VOCABULARIES, true)) {
            throw new TaxonomyChangeRejectedException('vocabulary_invalid');
        }
        if ($slots === []) {
            return $taxonomy;
        }

        try {
            $checked = $this->input->check($slots, allowNone: true);
        } catch (LayoutBlocksRejectedException $e) {
            throw new TaxonomyChangeRejectedException($e->reason, $e);
        }

        return DB::transaction(function () use ($taxonomy, $checked): Taxonomy {
            $this->lockTree($this->site->id());
            $taxonomy = $this->reload($taxonomy, 'taxonomy_invalid');
            $this->limits->change($taxonomy);

            $layout = Cast::arr(Cast::arr($taxonomy->meta)[LayoutSlots::META_KEY] ?? null);
            foreach ($checked as $slot => $value) {
                if ($value === null) {
                    unset($layout[$slot]);
                } else {
                    $layout[$slot] = $value;
                }
            }

            $row = Taxonomy::query()->whereKey($taxonomy->id);
            if ($layout === []) {
                $row->update(['meta' => DB::raw("meta - '".LayoutSlots::META_KEY."'")]);
            } else {
                $row->update(['meta->'.LayoutSlots::META_KEY => $layout]);
            }

            $this->usage->sync('taxonomy', $taxonomy->id, LayoutSlots::blockLists($layout));

            Audit::record('cms.taxonomy.updated', [
                'auditable_type' => Taxonomy::class,
                'auditable_id' => $taxonomy->id,
                'meta' => ['layout_blocks' => array_keys($checked)],
            ]);

            return $taxonomy->refresh();
        });
    }
}
