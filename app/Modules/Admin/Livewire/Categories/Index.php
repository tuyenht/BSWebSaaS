<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Categories;

use App\Modules\Admin\Support\Categories\CategoryDeletePreview;
use App\Modules\Admin\Support\Categories\CategoryMoveTargets;
use App\Modules\Admin\Support\Categories\CategoryScreen;
use App\Modules\Admin\Support\Categories\CategorySections;
use App\Modules\Admin\Support\SeoFields;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\DeleteTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\MergeTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\MoveTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\UndoTaxonomyMove;
use App\Modules\Cms\Actions\Taxonomies\UpdateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Enums\CategoryChildrenOnDelete;
use App\Modules\Cms\Enums\CategoryItemsOnDelete;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Cms\Taxonomy\TaxonomyRules;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The Chuyên mục screen (design 10) for one section (`?section=`, CategorySections): the post
 * categories of a content section, or the product categories (`catalog`). Like the menus screen it
 * holds no state on the server the browser could change: the script calls these methods
 * ($wire.load('tin-tuc'), $wire.move(…)…), each names its section and categories by key (ulid,
 * never a raw id) and returns a small array — {ok: true, …} on success, {ok: false, reason,
 * message} when refused, with a TaxonomyChangeRejectedException reason (or input_invalid,
 * section_invalid) and its plain-language sentence.
 *
 * Every method is an endpoint: boot() re-checks taxonomies.manage on each request, each method
 * authorizes through TaxonomyPolicy and validates every argument. A member limited to some
 * categories is refused outside them with outside_category_limits (the policy's per-category
 * abilities, and CategoryLimitGuard inside the write path).
 *
 * A section is only open to someone who may see its content (`{prefix}.view`, as the admin menu
 * shows it), else section_invalid; a parent, a target or the place an undo goes back to must lie in
 * the same section or in another one the person may see (section_invalid too). Every method that
 * changes the tree takes the tree lock (TaxonomyTree::lock) before it reads anything, so what it
 * checks and what it reports back (the old place of a move, the tree version) is what it changed.
 */
#[Layout('admin::layouts.app')]
final class Index extends Component
{
    /** The fields "save" accepts in its data, each a language → text map. */
    private const array TEXT_MAPS = ['names', 'slugs', 'descriptions', 'seoTitles', 'seoDescriptions'];

    /** The fields "save" accepts in its data, each a string or null. */
    private const array STRINGS = ['listTemplate', 'detailTemplate', 'cover'];

    /** The section the page was opened on, for its title; every method names its own section. */
    #[Locked]
    public string $section = '';

    public function boot(): void
    {
        // The livewire/update endpoint bypasses route middleware: re-check on every request.
        abort_unless(Gate::allows('taxonomies.manage'), 403);
    }

    public function mount(): void
    {
        $this->section = Cast::string(request()->query('section'));
    }

    /**
     * Everything the screen draws for $section (CategoryScreen::load): the rows of the shared tree
     * (TreeRows, each `node` carrying the category), the languages, the limits and the tree version.
     *
     * @return array<string, mixed>
     */
    public function load(mixed $section): array
    {
        Gate::authorize('viewAny', Taxonomy::class);

        return $this->inSection($section, fn (array $found): array => ['ok' => true, ...app(CategoryScreen::class)->load($found, $this->actor())]);
    }

    /**
     * "Lưu": a new category when $key is null — under $parent (a key), or for post categories
     * under the section's root when $parent is null — else the category $key. $data is the whole
     * drawer: names, slugs, descriptions, seoTitles, seoDescriptions (language → text),
     * listTemplate, detailTemplate (a layout name or null for the theme's default; left out, the
     * category keeps its own) and cover (a media key, '' for none, null to keep). Returns the
     * category's key and the new tree version.
     *
     * @return array<string, mixed>
     */
    public function save(mixed $section, mixed $key, mixed $parent, mixed $data): array
    {
        $fields = $this->data($data);
        if (($key !== null && ! $this->isUlid($key)) || ($parent !== null && ! $this->isUlid($parent)) || $fields === null) {
            return $this->invalid();
        }

        return $this->inSection($section, function (array $found) use ($key, $parent, $fields, $data): array {
            if ($key === null) {
                Gate::authorize('create', Taxonomy::class);
                $under = $parent === null ? $this->root($found) : $this->place($found, $parent, 'parent_invalid');
                $saved = app(CreateTaxonomy::class)->handle($found['vocabulary'], $under, $fields);
            } else {
                $category = $this->category($found, $key);
                $this->ensure('update', $category);
                $saved = app(UpdateTaxonomy::class)->handle($category, $this->keepTemplates($fields, is_array($data) ? $data : [], $category));
            }

            return ['ok' => true, 'key' => $saved->ulid, 'version' => TaxonomyTree::version($found['vocabulary'])];
        }, write: true);
    }

    /**
     * Move $key — with its branch — under $parent (a key; null = the outer level, product
     * categories only) at $position among its new siblings. $confirm: the person agreed to the
     * posts changing section (section_changed otherwise). Returns the new tree version and what
     * undoMove() needs to put it back ({key, parent, position, version}; null when the old parent
     * has no key).
     *
     * @return array<string, mixed>
     */
    public function move(mixed $section, mixed $key, mixed $parent, mixed $position, mixed $confirm = false): array
    {
        if (! $this->isUlid($key) || ($parent !== null && ! $this->isUlid($parent)) || ! is_int($position) || $position < 0 || ! is_bool($confirm)) {
            return $this->invalid();
        }

        return $this->inSection($section, function (array $found) use ($key, $parent, $position, $confirm): array {
            $category = $this->category($found, $key);
            $this->ensureMovable($category);
            $under = $parent === null ? null : $this->place($found, $parent, 'parent_invalid');
            if ($under === null && $found['root'] !== null) {
                throw new TaxonomyChangeRejectedException('parent_invalid'); // a post category always sits under its section's root
            }

            // Read under the tree lock inSection took, so it is the place this move takes it from.
            $oldParent = $category->parent_id === null ? null : Taxonomy::query()->whereKey($category->parent_id)->value('ulid');
            $oldPosition = $this->positionOf($category);
            app(MoveTaxonomy::class)->handle($category, $under, $position, $confirm);
            $version = TaxonomyTree::version($found['vocabulary']);
            $undoable = $category->parent_id === null || is_string($oldParent);

            return ['ok' => true, 'version' => $version, 'undo' => $undoable ? [
                'key' => $key, 'parent' => is_string($oldParent) ? $oldParent : null, 'position' => $oldPosition, 'version' => $version,
            ] : null];
        }, write: true);
    }

    /**
     * "Hoàn tác" of a move: $undo is what move() returned. Refused as move_stale once the tree
     * changed since.
     *
     * @return array<string, mixed>
     */
    public function undoMove(mixed $section, mixed $undo): array
    {
        if (! is_array($undo) || ! $this->isUlid($undo['key'] ?? null) || (($undo['parent'] ?? null) !== null && ! $this->isUlid($undo['parent']))
            || ! is_int($undo['position'] ?? null) || $undo['position'] < 0 || ! is_string($undo['version'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/', $undo['version']) !== 1) {
            return $this->invalid();
        }

        return $this->inSection($section, function (array $found) use ($undo): array {
            /** @var array{key: string, parent: string|null, position: int, version: string} $undo */
            $category = $this->category($found, $undo['key']);
            $this->ensureMovable($category);
            // A move only ever reparents within one vocabulary (vocabulary_mismatch), so the way back does too.
            $parent = $undo['parent'] === null ? null : $this->place($found, $undo['parent'], 'parent_invalid');
            app(UndoTaxonomyMove::class)->handle($category, $parent, $undo['position'], $undo['version']);

            return ['ok' => true, 'version' => TaxonomyTree::version($found['vocabulary'])];
        }, write: true);
    }

    /**
     * "Chuyển tới…": every place $key could go, with the reason for each that is not allowed
     * (CategoryMoveTargets), filtered by $query on the names.
     *
     * @return array<string, mixed>
     */
    public function moveTargets(mixed $section, mixed $key, mixed $query = ''): array
    {
        if (! $this->isUlid($key) || ! is_string($query) || mb_strlen($query) > TaxonomyRules::NAME_MAX) {
            return $this->invalid();
        }
        Gate::authorize('viewAny', Taxonomy::class);

        return $this->inSection($section, fn (array $found): array => [
            'ok' => true, ...app(CategoryMoveTargets::class)->for($found, $this->category($found, $key), $this->actor(), $query),
        ]);
    }

    /**
     * What the delete or merge dialog shows (CategoryDeletePreview). $children: lift | delete;
     * $target: where the items would go (a key) or null; $merge: the merge dialog.
     *
     * @return array<string, mixed>
     */
    public function deletePreview(mixed $section, mixed $key, mixed $children = 'lift', mixed $target = null, mixed $merge = false): array
    {
        $childrenOnDelete = is_string($children) ? CategoryChildrenOnDelete::tryFrom($children) : null;
        if (! $this->isUlid($key) || $childrenOnDelete === null || ($target !== null && ! $this->isUlid($target)) || ! is_bool($merge)) {
            return $this->invalid();
        }
        Gate::authorize('viewAny', Taxonomy::class);

        return $this->inSection($section, function (array $found) use ($key, $childrenOnDelete, $target, $merge): array {
            $category = $this->category($found, $key);
            $into = $target === null ? null : $this->place($found, $target, 'target_invalid');

            return ['ok' => true, ...app(CategoryDeletePreview::class)->for($found, $category, $this->actor(), $childrenOnDelete, $merge, $into)];
        });
    }

    /**
     * Delete $key. $children: lift | delete | null (refused as has_children when it has some);
     * $items: reassign (onto $target) | detach | null (refused as has_items when it has some);
     * $confirm: the person agreed to posts leaving their section. Returns DeleteTaxonomy's counts.
     *
     * @return array<string, mixed>
     */
    public function delete(mixed $section, mixed $key, mixed $children = null, mixed $items = null, mixed $target = null, mixed $confirm = false): array
    {
        $childrenOnDelete = is_string($children) ? CategoryChildrenOnDelete::tryFrom($children) : null;
        $itemsOnDelete = is_string($items) ? CategoryItemsOnDelete::tryFrom($items) : null;
        if (! $this->isUlid($key) || ($children !== null && $childrenOnDelete === null) || ($items !== null && $itemsOnDelete === null)
            || ($target !== null && ! $this->isUlid($target)) || ! is_bool($confirm)) {
            return $this->invalid();
        }

        return $this->inSection($section, function (array $found) use ($key, $childrenOnDelete, $itemsOnDelete, $target, $confirm): array {
            $category = $this->category($found, $key);
            $this->ensure('delete', $category);
            $into = $target === null ? null : $this->place($found, $target, 'target_invalid');
            $counts = app(DeleteTaxonomy::class)->handle($category, $itemsOnDelete, $into, $childrenOnDelete, $confirm);

            return ['ok' => true, ...$counts, 'version' => TaxonomyTree::version($found['vocabulary'])];
        }, write: true);
    }

    /**
     * "Gộp vào chuyên mục khác": the items of $key move onto $target, its sub-categories go up a
     * level, and $key is removed (MergeTaxonomy). $confirm as for delete().
     *
     * @return array<string, mixed>
     */
    public function merge(mixed $section, mixed $key, mixed $target, mixed $confirm = false): array
    {
        if (! $this->isUlid($key) || ! $this->isUlid($target) || ! is_bool($confirm)) {
            return $this->invalid();
        }

        return $this->inSection($section, function (array $found) use ($key, $target, $confirm): array {
            $category = $this->category($found, $key);
            $this->ensure('delete', $category);
            $into = $this->place($found, $target, 'target_invalid');
            $counts = app(MergeTaxonomy::class)->handle($category, $into, $confirm);

            return ['ok' => true, ...$counts, 'version' => TaxonomyTree::version($found['vocabulary'])];
        }, write: true);
    }

    /**
     * The keys of the section's rows whose name, slug or "Thuộc …" trail holds $query, ignoring
     * case and Vietnamese marks, in drawing order.
     *
     * @return array<string, mixed>
     */
    public function search(mixed $section, mixed $query): array
    {
        if (! is_string($query) || mb_strlen($query) > TaxonomyRules::NAME_MAX) {
            return $this->invalid();
        }
        Gate::authorize('viewAny', Taxonomy::class);

        return $this->inSection($section, fn (array $found): array => ['ok' => true, 'keys' => app(CategoryScreen::class)->search($found, $query)]);
    }

    /** A stub until the board is ported: the title and the empty state. */
    public function render(): View
    {
        Gate::authorize('viewAny', Taxonomy::class);
        $found = $this->visibleSection($this->section);

        return view('admin::livewire.categories.index', ['label' => $found['label'] ?? '']);
    }

    /**
     * Resolve the section the person may see, run $run — for a change ($write), inside one
     * transaction that takes the tree lock first — and turn a refusal into its reason.
     *
     * @param  Closure(array{key: string, label: string, vocabulary: string, root: int|null, prefix: string}): array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function inSection(mixed $section, Closure $run, bool $write = false): array
    {
        $found = is_string($section) ? $this->visibleSection($section) : null;
        if ($found === null) {
            return $this->refused('section_invalid');
        }

        try {
            if (! $write) {
                return $run($found);
            }

            return DB::transaction(function () use ($run, $found): array {
                TaxonomyTree::lock(app(CurrentSite::class)->id());

                return $run($found);
            });
        } catch (TaxonomyChangeRejectedException $refusal) {
            return $this->refused($refusal->reason);
        }
    }

    /**
     * The section with this key, when the person may see its content — the ability the admin menu
     * shows it by (`{prefix}.view`) — else null.
     *
     * @return array{key: string, label: string, vocabulary: string, root: int|null, prefix: string}|null
     */
    private function visibleSection(string $key): ?array
    {
        $found = app(CategorySections::class)->find($key);

        return $found !== null && Gate::allows($found['prefix'].'.view') ? $found : null;
    }

    /**
     * A parent or target named by the screen: a category of the section's vocabulary ($reason
     * when there is none), lying in this section or in another post section the person may see
     * (section_invalid otherwise — also for a post category that belongs to no section).
     *
     * @param  array{key: string, label: string, vocabulary: string, root: int|null, prefix: string}  $section
     *
     * @throws TaxonomyChangeRejectedException
     */
    private function place(array $section, string $ulid, string $reason): Taxonomy
    {
        $category = $this->ofVocabulary($section, $ulid) ?? throw new TaxonomyChangeRejectedException($reason);
        if ($section['root'] === null) {
            return $category; // product categories: the whole vocabulary is the one section
        }

        $roots = [];
        foreach (app(CategorySections::class)->postSections() as $post) {
            $roots[$post['root']] = $post;
        }
        foreach ([$category->id, ...array_reverse(TaxonomyTree::ancestorsOf($category->id))] as $at) {
            if (isset($roots[$at])) {
                $owner = $roots[$at];
                if ($owner['key'] === $section['key'] || Gate::allows($owner['prefix'].'.view')) {
                    return $category;
                }

                break;
            }
        }

        throw new TaxonomyChangeRejectedException('section_invalid');
    }

    /**
     * The category $ulid of this section — the section's root or a category under it, or for
     * product categories any of them; another site's key is never found (BelongsToSite, RLS).
     *
     * @param  array{key: string, label: string, vocabulary: string, root: int|null, prefix: string}  $section
     *
     * @throws TaxonomyChangeRejectedException taxonomy_invalid
     */
    private function category(array $section, string $ulid): Taxonomy
    {
        $category = $this->ofVocabulary($section, $ulid);
        if ($category !== null && $section['root'] !== null && $category->id !== $section['root']
            && ! in_array($section['root'], TaxonomyTree::ancestorsOf($category->id), true)) {
            $category = null;
        }

        return $category ?? throw new TaxonomyChangeRejectedException('taxonomy_invalid');
    }

    /**
     * A category of the section's vocabulary, in any section: a parent or a merge target may sit
     * in another section (the move then changes the posts' section, which the person confirms).
     *
     * @param  array{key: string, label: string, vocabulary: string, root: int|null, prefix: string}  $section
     */
    private function ofVocabulary(array $section, string $ulid): ?Taxonomy
    {
        return Taxonomy::query()->where('ulid', $ulid)->where('vocabulary', $section['vocabulary'])->first();
    }

    /**
     * @param  array{key: string, label: string, vocabulary: string, root: int|null, prefix: string}  $section
     */
    private function root(array $section): ?Taxonomy
    {
        return $section['root'] === null ? null : (Taxonomy::query()->whereKey($section['root'])->first() ?? throw new TaxonomyChangeRejectedException('parent_invalid'));
    }

    /**
     * The person may change categories (else 403) and this one in particular — a member limited
     * to some categories is told it is outside them.
     *
     * @throws TaxonomyChangeRejectedException outside_category_limits
     */
    private function ensure(string $ability, Taxonomy $category): void
    {
        Gate::authorize('manage', Taxonomy::class);
        if (Gate::denies($ability, $category)) {
            throw new TaxonomyChangeRejectedException('outside_category_limits');
        }
    }

    /**
     * A section's root always stays at level 1 (section_bound); otherwise as ensure('move').
     *
     * @throws TaxonomyChangeRejectedException
     */
    private function ensureMovable(Taxonomy $category): void
    {
        if (app(SectionResolver::class)->boundAmong([$category->id]) !== []) {
            throw new TaxonomyChangeRejectedException('section_bound');
        }
        $this->ensure('move', $category);
    }

    /** The category's place among its siblings (0 first), as MoveTaxonomy counts positions. */
    private function positionOf(Taxonomy $category): int
    {
        $siblings = Taxonomy::query()
            ->where('vocabulary', $category->vocabulary)
            ->when($category->parent_id === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $category->parent_id))
            ->orderBy('position')->orderBy('id')
            ->pluck('id')->all();
        $at = array_search($category->id, array_values(array_map(static fn (mixed $id): int => Cast::int($id), $siblings)), true);

        return $at === false ? 0 : $at;
    }

    /**
     * The drawer's fields as TaxonomyData, or null when anything is missing its shape or unknown.
     * The Google fields are made one line first, as on posts (SeoFields::singleLine).
     */
    private function data(mixed $data): ?TaxonomyData
    {
        if (! is_array($data) || array_diff(array_keys($data), [...self::TEXT_MAPS, ...self::STRINGS]) !== []) {
            return null;
        }

        $maps = [];
        foreach (self::TEXT_MAPS as $field) {
            $value = $data[$field] ?? [];
            if (! is_array($value)) {
                return null;
            }
            foreach ($value as $locale => $text) {
                if (! is_string($locale) || ! is_string($text)) {
                    return null;
                }
            }
            /** @var array<string, string> $value */
            $maps[$field] = in_array($field, ['seoTitles', 'seoDescriptions'], true) ? array_map(SeoFields::singleLine(...), $value) : $value;
        }
        foreach (self::STRINGS as $field) {
            if (($data[$field] ?? null) !== null && ! is_string($data[$field])) {
                return null;
            }
        }

        return new TaxonomyData(
            names: $maps['names'],
            slugs: $maps['slugs'],
            listTemplate: Cast::stringOrNull($data['listTemplate'] ?? null),
            detailTemplate: Cast::stringOrNull($data['detailTemplate'] ?? null),
            descriptions: $maps['descriptions'],
            seoTitles: $maps['seoTitles'],
            seoDescriptions: $maps['seoDescriptions'],
            cover: is_string($data['cover'] ?? null) ? $data['cover'] : null,
        );
    }

    /**
     * A save that leaves a template out keeps the category's current one, as it keeps a language
     * left out of the names or the descriptions; null still means the theme's default.
     *
     * @param  array<mixed>  $data
     */
    private function keepTemplates(TaxonomyData $fields, array $data, Taxonomy $category): TaxonomyData
    {
        return new TaxonomyData(
            names: $fields->names,
            slugs: $fields->slugs,
            listTemplate: array_key_exists('listTemplate', $data) ? $fields->listTemplate : $category->list_template,
            detailTemplate: array_key_exists('detailTemplate', $data) ? $fields->detailTemplate : $category->detail_template,
            descriptions: $fields->descriptions,
            seoTitles: $fields->seoTitles,
            seoDescriptions: $fields->seoDescriptions,
            cover: $fields->cover,
        );
    }

    /**
     * @return array{ok: false, reason: string, message: string}
     */
    private function refused(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'message' => __('categories.reason_'.$reason, ['depth' => TaxonomyRules::MAX_DEPTH])];
    }

    /**
     * @return array{ok: false, reason: string, message: string}
     */
    private function invalid(): array
    {
        return $this->refused('input_invalid');
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @phpstan-assert-if-true string $value */
    private function isUlid(mixed $value): bool
    {
        return is_string($value) && Str::isUlid($value);
    }
}
