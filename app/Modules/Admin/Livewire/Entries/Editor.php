<?php

namespace App\Modules\Admin\Livewire\Entries;

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Theme\Components\ComponentRegistry;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The content editor: a block-list builder over the shared ComponentRegistry (add /
 * reorder / remove / edit props). It serves both entry types — a `page` (flat URL) and
 * a `post` (URL prefixed by its primary category, so posts need a category picker). The
 * type is resolved server-side (from the content_section on create, from the entry on
 * edit) and #[Locked] so the client can't flip it. Save persists a draft + its Page-JSON
 * body (validated against the registry allow-list — ValidPageJson, D1: posts reuse the
 * block-list); Publish runs the Tầng-1 workflow (PublishEntry). Every mutation authorizes
 * through EntryPolicy. The entry is addressed by ulid, never its raw id.
 */
#[Layout('admin::layouts.app')]
final class Editor extends Component
{
    use AuthorizesRequests;

    public ?string $ulid = null;

    /** Entry type (page|post); server-resolved, never client-set. */
    #[Locked]
    public string $type = 'page';

    /** Server-resolved from the site's default locale; never client-set. */
    #[Locked]
    public string $locale = 'vi';

    public string $title = '';

    public string $slug = '';

    /** Primary category id (posts only; index 0 of entry_taxonomies drives the URL). */
    public ?int $categoryId = null;

    /** Featured/OG image — the picked media's storage_key, persisted to entry.meta['image_url']. */
    public string $coverImageKey = '';

    /** Whether the library picker is open (its media grid is only queried while true). */
    public bool $showMediaPicker = false;

    /** @var list<array<string, mixed>> */
    public array $blocks = [];

    #[Locked]
    public ?string $status = null;

    public function mount(?string $ulid = null, ?string $section = null): void
    {
        $this->locale = app(SiteLocale::class)->default();

        if ($ulid === null) {
            $this->authorize('create', Entry::class);

            // New entry: take the type (and a default category for posts) from the
            // content_section the admin came from (?section=…); default to a page.
            $contentSection = $this->resolveSection($section ?? request()->query('section'));
            $this->type = $contentSection instanceof ContentSection ? ($contentSection->entry_type ?? 'page') : 'page';
            $this->categoryId = $this->type === 'post' && $contentSection instanceof ContentSection && $contentSection->taxonomy_id !== null
                ? (int) $contentSection->taxonomy_id
                : null;

            return;
        }

        $entry = $this->resolveOrFail($ulid);
        $this->authorize('update', $entry);

        $this->ulid = $entry->ulid;
        $this->type = $entry->type;
        $this->status = $entry->status;

        $translation = $entry->translationFor($this->locale);
        if ($translation instanceof EntryTranslation) {
            $this->title = $translation->title;
            $this->slug = $translation->slug;
            $this->blocks = $this->normalizeBlocks($translation->body['blocks'] ?? []);
        }

        $cover = data_get($entry->meta, 'image_url');
        $this->coverImageKey = is_string($cover) ? $cover : '';

        if ($this->type === 'post') {
            $this->categoryId = $entry->taxonomies()->first()?->id;
        }
    }

    public function addBlock(string $type): void
    {
        if (! app(ComponentRegistry::class)->has($type)) {
            $this->addError('blocks', __('entries.block_unsupported', ['type' => $type]));

            return;
        }

        $this->resetErrorBag('blocks');
        $this->blocks[] = ['type' => $type, 'props' => $this->defaultProps($type)];
    }

    public function removeBlock(int $index): void
    {
        if (! array_key_exists($index, $this->blocks)) {
            return;
        }

        $blocks = $this->blocks;
        array_splice($blocks, $index, 1);
        $this->blocks = $blocks;
    }

    public function moveUp(int $index): void
    {
        if ($index <= 0 || ! array_key_exists($index, $this->blocks)) {
            return;
        }

        $blocks = $this->blocks;
        [$blocks[$index - 1], $blocks[$index]] = [$blocks[$index], $blocks[$index - 1]];
        $this->blocks = $blocks;
    }

    public function moveDown(int $index): void
    {
        if (! array_key_exists($index + 1, $this->blocks)) {
            return;
        }

        $blocks = $this->blocks;
        [$blocks[$index], $blocks[$index + 1]] = [$blocks[$index + 1], $blocks[$index]];
        $this->blocks = $blocks;
    }

    /** Append a blank item (shaped by the list's `of` schema) to a list-typed prop. */
    public function addItem(int $index, string $key): void
    {
        $list = $this->resolveList($index, $key);
        if ($list === null) {
            return;
        }

        // Clear a stale save-time error so it doesn't linger over valid edits (mirrors addBlock).
        $this->resetErrorBag('blocks');
        $items = $list['items'];
        $items[] = $list['blank'];
        $this->putItems($index, $key, $items);
    }

    public function removeItem(int $index, string $key, int $itemIndex): void
    {
        $list = $this->resolveList($index, $key);
        if ($list === null || ! array_key_exists($itemIndex, $list['items'])) {
            return;
        }

        $items = $list['items'];
        array_splice($items, $itemIndex, 1);
        $this->putItems($index, $key, $items);
    }

    public function moveItemUp(int $index, string $key, int $itemIndex): void
    {
        $this->swapItems($index, $key, $itemIndex, $itemIndex - 1);
    }

    public function moveItemDown(int $index, string $key, int $itemIndex): void
    {
        $this->swapItems($index, $key, $itemIndex, $itemIndex + 1);
    }

    public function save(): void
    {
        $this->persist();
        session()->flash('saved', true);
    }

    public function publish(): void
    {
        $entry = $this->persist();

        try {
            $this->status = app(PublishEntry::class)->handle($entry, $this->locale, $this->user());
        } catch (RouteConflictException) {
            $this->addError('slug', __('entries.slug_conflict'));
        }
    }

    public function openCoverPicker(): void
    {
        abort_unless(Gate::allows('media.view'), 403);
        $this->showMediaPicker = true;
    }

    public function closeCoverPicker(): void
    {
        $this->showMediaPicker = false;
    }

    public function selectCover(string $key): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        // Only accept a storage_key from THIS site's library (RLS-scoped) — never an arbitrary or
        // cross-site string injected via the wire payload.
        if (Media::query()->where('storage_key', $key)->exists()) {
            $this->coverImageKey = $key;
        }

        $this->showMediaPicker = false;
    }

    public function clearCover(): void
    {
        $this->coverImageKey = '';
    }

    public function render(): View
    {
        $urls = app(MediaUrlResolver::class);

        return view('admin::livewire.entries.editor', [
            'availableTypes' => app(ComponentRegistry::class)->types(),
            'schemas' => $this->schemas(),
            'categories' => $this->type === 'post' ? $this->availableCategories() : [],
            'urls' => $urls,
            'coverUrl' => $this->coverImageKey !== '' ? $urls->url($this->coverImageKey, 640) : null,
            'pickerMedia' => $this->showMediaPicker
                ? Media::query()->orderByDesc('created_at')->limit(60)->get()
                : collect(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'blocks' => [app(ValidPageJson::class)],
        ];

        // A post's URL is its category slug + post slug, so a post must have a category.
        if ($this->type === 'post') {
            $rules['categoryId'] = ['required', 'integer', Rule::exists('taxonomies', 'id')->where('vocabulary', 'post_cat')];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'slug.regex' => __('entries.slug_format'),
            'categoryId.required' => __('entries.category_required'),
            'categoryId.exists' => __('entries.category_invalid'),
        ];
    }

    /** Validate + persist the entry and its translation (+ category for posts); returns it. */
    private function persist(): Entry
    {
        $this->validate();

        if ($this->ulid === null) {
            $this->authorize('create', Entry::class);
            $entry = Entry::query()->create([
                'type' => $this->type,
                'status' => 'draft',
                'author_id' => $this->user()->id,
                'meta' => [], // a fresh instance's meta is null until set; [] keeps the merge below array-typed
            ]);
            $this->ulid = $entry->ulid;
            $this->status = $entry->status;
        } else {
            $entry = $this->resolveOrFail($this->ulid);
            $this->authorize('update', $entry);
        }

        // Featured/OG image: store the picked media's storage_key in entry.meta['image_url'] (the
        // storefront resolves it to an absolute og:image + card thumbnail). Merge so any other meta
        // key survives; an empty pick clears it.
        $meta = $entry->meta;
        if ($this->coverImageKey !== '') {
            $meta['image_url'] = $this->coverImageKey;
        } else {
            unset($meta['image_url']);
        }
        $entry->meta = $meta;
        $entry->save();

        $entry->translations()->updateOrCreate(
            ['locale' => $this->locale],
            [
                'title' => $this->title,
                'slug' => $this->slug,
                'body' => ['blocks' => $this->blocks],
                'status' => $entry->status,
            ],
        );

        if ($this->type === 'post') {
            $entry->assignCategories($this->categoryId !== null ? [$this->categoryId] : []);
        }

        return $entry;
    }

    private function resolveSection(?string $key): ?ContentSection
    {
        if ($key === null || $key === '') {
            return null;
        }

        return ContentSection::query()->where('key', $key)->first();
    }

    /**
     * Post categories (post_cat) for the picker, mapped to id + localized name.
     *
     * @return list<array{id: int, name: string}>
     */
    private function availableCategories(): array
    {
        $categories = Taxonomy::query()
            ->where('vocabulary', 'post_cat')
            ->with('translations:id,taxonomy_id,locale,name,slug')
            ->get()
            ->map(function (Taxonomy $taxonomy): array {
                $translation = $taxonomy->translationFor($this->locale);

                return [
                    'id' => $taxonomy->id,
                    'name' => $translation instanceof TaxonomyTranslation ? $translation->name : __('entries.unnamed'),
                ];
            })
            ->all();

        return array_values($categories);
    }

    private function resolveOrFail(string $ulid): Entry
    {
        return Entry::query()->where('ulid', $ulid)->firstOrFail();
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Resolve a list-typed prop: its current items (re-indexed) + a blank item template
     * shaped by the schema `of`. Returns null when #index.$key isn't an authorable list.
     *
     * @return array{items: array<int, mixed>, blank: mixed}|null
     */
    private function resolveList(int $index, string $key): ?array
    {
        if (! array_key_exists($index, $this->blocks)) {
            return null;
        }

        $type = $this->blocks[$index]['type'] ?? null;
        $registry = app(ComponentRegistry::class);
        if (! is_string($type) || ! $registry->has($type)) {
            return null;
        }

        $spec = $registry->get($type)->schema()[$key] ?? null;
        if (! is_array($spec) || ($spec['type'] ?? null) !== 'list') {
            return null;
        }

        $props = $this->blocks[$index]['props'] ?? null;
        $current = is_array($props) ? ($props[$key] ?? null) : null;

        return [
            'items' => is_array($current) ? array_values($current) : [],
            'blank' => $this->blankFromOf($spec['of'] ?? null),
        ];
    }

    /** A blank item value for a list's `of` schema (a scalar default, or a field map). */
    private function blankFromOf(mixed $of): mixed
    {
        if (! is_array($of)) {
            return '';
        }

        return isset($of['type']) && is_string($of['type'])
            ? $this->defaultForSpec($of)
            : $this->defaultsFromSchema($of);
    }

    /**
     * Write items back into a block's list prop (copy-narrow-reassign for static safety).
     *
     * @param  array<int, mixed>  $items
     */
    private function putItems(int $index, string $key, array $items): void
    {
        $blocks = $this->blocks;
        $block = $blocks[$index];
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];
        $props[$key] = $items;
        $block['props'] = $props;
        $blocks[$index] = $block;
        $this->blocks = $blocks;
    }

    private function swapItems(int $index, string $key, int $a, int $b): void
    {
        $list = $this->resolveList($index, $key);
        if ($list === null || ! array_key_exists($a, $list['items']) || ! array_key_exists($b, $list['items'])) {
            return;
        }

        $items = $list['items'];
        [$items[$a], $items[$b]] = [$items[$b], $items[$a]];
        $this->putItems($index, $key, $items);
    }

    /**
     * Starter props for a freshly added block, derived from its §E7 schema.
     *
     * @return array<string, mixed>
     */
    private function defaultProps(string $type): array
    {
        $registry = app(ComponentRegistry::class);

        return $registry->has($type) ? $this->defaultsFromSchema($registry->get($type)->schema()) : [];
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @return array<string, mixed>
     */
    private function defaultsFromSchema(array $schema): array
    {
        $props = [];
        foreach ($schema as $prop => $spec) {
            $props[(string) $prop] = $this->defaultForSpec($spec);
        }

        return $props;
    }

    /** A prop's starter value from its spec (array with a `type`); safe default otherwise. */
    private function defaultForSpec(mixed $spec): mixed
    {
        $type = is_array($spec) ? ($spec['type'] ?? 'text') : 'text';
        $default = is_array($spec) ? ($spec['default'] ?? null) : null;

        return match ($type) {
            'list' => [],
            'int' => $default ?? 0,
            'image' => $default,   // a media_id, or null for an unset picker
            default => $default ?? '',
        };
    }

    /**
     * Every registered type's §E7 schema, for the editor view to render inputs/repeaters.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function schemas(): array
    {
        $registry = app(ComponentRegistry::class);
        $schemas = [];
        foreach ($registry->types() as $type) {
            $schemas[$type] = $registry->get($type)->schema();
        }

        return $schemas;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeBlocks(mixed $blocks): array
    {
        if (! is_array($blocks)) {
            return [];
        }

        $normalized = [];
        foreach ($blocks as $block) {
            if (is_array($block) && isset($block['type']) && is_string($block['type'])) {
                $normalized[] = [
                    'type' => $block['type'],
                    'props' => is_array($block['props'] ?? null) ? $block['props'] : [],
                ];
            }
        }

        return $normalized;
    }
}
