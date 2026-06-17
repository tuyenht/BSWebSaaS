<?php

namespace App\Modules\Admin\Livewire\Entries;

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Theme\Components\ComponentRegistry;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
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

    /** Sensible starting props per known type, so a freshly added block is editable. */
    private const DEFAULT_PROPS = [
        'hero' => ['heading' => '', 'subheading' => ''],
        'richtext' => ['body' => ''],
        'features' => ['heading' => ''],
        'cta' => ['heading' => '', 'button_label' => '', 'button_url' => ''],
        'latest_posts' => ['heading' => '', 'limit' => 3],
    ];

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
            $this->type = $contentSection?->entry_type ?? 'page';
            $this->categoryId = $this->type === 'post' && $contentSection?->taxonomy_id !== null
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
        if ($translation !== null) {
            $this->title = $translation->title;
            $this->slug = $translation->slug;
            $this->blocks = $this->normalizeBlocks($translation->body['blocks'] ?? []);
        }

        if ($this->type === 'post') {
            $this->categoryId = $entry->taxonomies()->first()?->id;
        }
    }

    public function addBlock(string $type): void
    {
        if (! app(ComponentRegistry::class)->has($type)) {
            $this->addError('blocks', "Khối \"{$type}\" không được hỗ trợ.");

            return;
        }

        $this->resetErrorBag('blocks');
        $this->blocks[] = ['type' => $type, 'props' => self::DEFAULT_PROPS[$type] ?? []];
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
            $this->addError('slug', 'Đường dẫn này đã được dùng cho nội dung khác.');
        }
    }

    public function render(): View
    {
        return view('admin::livewire.entries.editor', [
            'availableTypes' => app(ComponentRegistry::class)->types(),
            'categories' => $this->type === 'post' ? $this->availableCategories() : [],
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
            'slug.regex' => 'Đường dẫn chỉ gồm chữ thường, số và dấu gạch nối.',
            'categoryId.required' => 'Vui lòng chọn danh mục cho bài viết.',
            'categoryId.exists' => 'Danh mục không hợp lệ.',
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
            ]);
            $this->ulid = $entry->ulid;
            $this->status = $entry->status;
        } else {
            $entry = $this->resolveOrFail($this->ulid);
            $this->authorize('update', $entry);
        }

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
        return Taxonomy::query()
            ->where('vocabulary', 'post_cat')
            ->with('translations:id,taxonomy_id,locale,name,slug')
            ->get()
            ->map(fn (Taxonomy $taxonomy): array => [
                'id' => $taxonomy->id,
                'name' => $taxonomy->translationFor($this->locale)?->name ?? '(không tên)',
            ])
            ->all();
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
