<?php

namespace App\Modules\Admin\Livewire\Entries;

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Identity\Models\User;
use App\Modules\Theme\Components\ComponentRegistry;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The page editor: a block-list builder over the shared ComponentRegistry (add /
 * reorder / remove / edit props). Save persists a draft + its Page-JSON body
 * (validated server-side against the registry allow-list — ValidPageJson); Publish
 * runs the Tầng-1 workflow (PublishEntry). Every mutation authorizes through
 * EntryPolicy (create / update own|any). The entry is addressed by ulid, never its
 * raw id. Puck drag-drop + revisions are a deliberate later step.
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

    public string $locale = 'vi';

    public string $title = '';

    public string $slug = '';

    /** @var list<array<string, mixed>> */
    public array $blocks = [];

    public ?string $status = null;

    public function mount(?string $ulid = null): void
    {
        if ($ulid === null) {
            $this->authorize('create', Entry::class);

            return;
        }

        $entry = $this->resolveOrFail($ulid);
        $this->authorize('update', $entry);

        $this->ulid = $entry->ulid;
        $this->status = $entry->status;

        $translation = $entry->translationFor($this->locale);
        if ($translation !== null) {
            $this->title = $translation->title;
            $this->slug = $translation->slug;
            $this->blocks = $this->normalizeBlocks($translation->body['blocks'] ?? []);
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

        $this->status = app(PublishEntry::class)->handle($entry, $this->locale, $this->user());
    }

    public function render(): View
    {
        return view('admin::livewire.entries.editor', [
            'availableTypes' => app(ComponentRegistry::class)->types(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'blocks' => [app(ValidPageJson::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'slug.regex' => 'Đường dẫn chỉ gồm chữ thường, số và dấu gạch nối.',
        ];
    }

    /** Validate + persist the entry and its translation; returns the entry. */
    private function persist(): Entry
    {
        $this->validate();

        if ($this->ulid === null) {
            $this->authorize('create', Entry::class);
            $entry = Entry::query()->create([
                'type' => 'page',
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

        return $entry;
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
