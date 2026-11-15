<?php

namespace App\Modules\Admin\Livewire\Entries;

use App\Modules\Admin\Livewire\Concerns\EditsButtonActions;
use App\Modules\Admin\Livewire\Concerns\WithMediaLibraryPicker;
use App\Modules\Admin\Support\BlockKinds;
use App\Modules\Admin\Support\EntryProseBody;
use App\Modules\Admin\Support\Locales;
use App\Modules\Admin\Support\SeoFields;
use App\Modules\Catalog\Components\ProductGridComponent;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\EntryNotGovernedException;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Exceptions\WorkflowGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Cms\Support\EntryBlockMediaReconciler;
use App\Modules\Cms\Support\EntryCategoryWriter;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Cms\Validation\EntryContentRules;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaLibraryUrls;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\PlainText;
use App\Modules\Shared\Support\Slugger;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SchemaImages;
use App\Modules\Theme\Components\StyleProps;
use App\Modules\Theme\Theming\ThemeResolver;
use App\Modules\Workflow\Exceptions\InvalidTransitionException;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowState;
use App\Modules\Workflow\Models\WorkflowTransition;
use App\Modules\Workflow\Services\WorkflowEngine;
use App\Modules\Workflow\Services\WorkflowTrail;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The content editor for both entry types — a `page` (flat URL) and a `post` (URL prefixed by
 * its primary category, so posts need a category picker). The type is resolved server-side
 * (from the content_section on create, from the entry on edit) and #[Locked] so the client
 * can't flip it. One body, two editors (ADR-19, EntryProseBody): a body that is empty, ONE
 * formatted-text block or only older plain-text blocks is written in the rich editor
 * («Soạn thảo», `bodyHtml`; plain-text blocks are converted when it opens, and the first save
 * keeps a revision of the old body); any other body is a page layout edited block by block over
 * the shared ComponentRegistry («Trình dựng»: add / reorder / remove / edit props). Save
 * persists a draft + its Page-JSON body (validated against the registry allow-list —
 * ValidPageJson; every html prop sanitized by the body's cast); Publish runs the Tầng-1
 * workflow (PublishEntry). Every mutation authorizes through EntryPolicy. The entry is
 * addressed by ulid, never its raw id.
 *
 * Where an ACTIVE workflow governs the post's section (design.md §15.4 tier-2), the Publish
 * button gives way to the "Trạng thái duyệt" block: the flow's stages, the transitions the
 * engine allows this user, a mandatory-note hand-off, and the approval trail. Which flow
 * applies follows the PICKED category — so an unsaved post already shows the right block and
 * a transition persists it first, the same way Publish does.
 */
#[Layout('admin::layouts.app')]
final class Editor extends Component
{
    use AuthorizesRequests;
    use EditsButtonActions;
    use WithMediaLibraryPicker;

    /** How many trail rows the block shows before "Xem thêm" (design: 3, +3 per load). */
    private const int TRAIL_PAGE = 3;

    /** The states a translation row may hold (entry_translations_status_check). */
    private const array TRANSLATION_STATES = ['draft', 'pending', 'published', 'archived'];

    /**
     * Cap on the "Giao cho" list. It rides inside the Livewire snapshot of every governed
     * render, so an unbounded team would grow the payload without bound (ADR-18: paginate
     * everything). A site whose approving team is larger than this needs a picker with search,
     * not a longer <select>.
     */
    private const int ASSIGNEE_LIMIT = 50;

    /**
     * The fields the save state and the leave dialog count against the saved entry. The block list
     * is counted apart (blocksChanged): the browser cannot write it the way the server does.
     */
    public const array TRACKED = ['title', 'slug', 'excerpt', 'bodyHtml', 'seoTitle', 'seoDescription', 'categoryId', 'coverImageKey'];

    /** Which entry is being edited — server-resolved (mount/persist); never client-set. */
    #[Locked]
    public ?string $ulid = null;

    /** Entry type (page|post); server-resolved, never client-set. */
    #[Locked]
    public string $type = 'page';

    /** Server-resolved from the site's default locale; never client-set. */
    #[Locked]
    public string $locale = 'vi';

    public string $title = '';

    public string $slug = '';

    /** The short excerpt of this language (news cards, category pages; the Google description's fallback). */
    public string $excerpt = '';

    /** The title Google shows for this language; empty = the page title. */
    public string $seoTitle = '';

    /** The description Google shows for this language; empty = the excerpt. */
    public string $seoDescription = '';

    /** Whether the site writes in more than one language (the per-language fields then carry its code). */
    #[Locked]
    public bool $multiLocale = false;

    /** Primary category id (posts only; index 0 of entry_taxonomies drives the URL). */
    public ?int $categoryId = null;

    /**
     * The Google preview's address around the slug (SeoFields::address): built on mount and again
     * when the category changes, finished in the browser as the slug is typed. Server data.
     *
     * @var array{base: string, home: string|null, homeSlug: string}
     */
    #[Locked]
    public array $address = ['base' => '', 'home' => null, 'homeSlug' => PathBuilder::HOME_SLUG];

    /** The site's primary domain, kept from the first request for the address above. */
    #[Locked]
    public string $addressHost = '';

    /** Featured/OG image — the picked media's storage_key, persisted to entry.meta['image_url']. */
    public string $coverImageKey = '';

    /** Whether the library picker is open (its media grid is only queried while true). */
    public bool $showMediaPicker = false;

    /** Block image picker: the target prop path inside $blocks (e.g. "0.props.image_url" or
     *  "0.props.logos.1.image_url"), held while the picker is open for a block image field. */
    public bool $showBlockImagePicker = false;

    public string $blockImagePickerPath = '';

    /** @var list<array<string, mixed>> */
    public array $blocks = [];

    /**
     * The «Soạn thảo» document while the body is written as one (`prose`): HTML in the editor's
     * form, pictures by address and never by id (EntryProseBody); '' for a layout body.
     */
    public string $bodyHtml = '';

    /** The body is written as one document (rich editor); false for a page layout edited block by block. */
    #[Locked]
    public bool $prose = true;

    /**
     * The look props the document's block carries (kept as they are on save; the look panel is the builder's).
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $proseLook = [];

    /** The block whose content form is open under its row in «Trình dựng» (one at a time). */
    public ?int $openBlock = null;

    /** This language's body was older plain-text blocks shown converted: the first save keeps a revision of it. */
    #[Locked]
    public bool $converted = false;

    /**
     * Blocks whose "Trình bày" panel shows the top and bottom spacing apart, by index (editor state
     * only, never saved; a block whose two steps differ shows them apart anyway).
     *
     * @var array<int, bool>
     */
    public array $lookSplit = [];

    #[Locked]
    public ?string $status = null;

    /**
     * The tracked values as last saved (Editor::tracked); the browser counts its changes against them.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $saved = [];

    /** 1 while the block list differs from the saved one (drawn by the server, added to the browser's count). */
    #[Locked]
    public int $blocksChanged = 0;

    /** When this entry was last saved (ISO, UTC); null for one never saved. */
    #[Locked]
    public ?string $savedAt = null;

    /** The last save asked for was refused for a field to fix (the save state says so until the next one). */
    #[Locked]
    public bool $saveFailed = false;

    /** The content section whose list this entry belongs to (the crumb and "Quay lại" go there). */
    #[Locked]
    public ?string $sectionKey = null;

    /** The member may publish this entry (the publish right inside their category limits), as of the last open or save. */
    #[Locked]
    public bool $mayPublish = false;

    /**
     * Where visitors reach this language's page (no scheme), read from its route as of the last open,
     * publish or workflow move; null while this language is not live. A save never moves it: only a
     * publish syncs the route, so a changed slug or category is not live until then.
     */
    #[Locked]
    public ?string $liveAddress = null;

    /** That list's name (the crumb), e.g. "Tin tức" or "Trang". */
    #[Locked]
    public string $listLabel = '';

    /** Reason for a return/retract — transitions flagged requires_note refuse without one. */
    public string $transitionNote = '';

    /** Member (users.id) to hand the post to on a note transition; must be able to act at the next stage. */
    public ?int $assignTo = null;

    /**
     * Approval trail rows shown so far (newest first) + the keyset cursor for the next page and
     * the per-entry total — held in state so "Xem thêm" appends instead of re-reading. Server
     * data, so #[Locked]: the client pages it, never edits it.
     *
     * @var list<array{id: int, at: string, actor: string|null, transition: string, from: string, to: string, note: string|null}>
     */
    #[Locked]
    public array $trail = [];

    #[Locked]
    public ?string $trailCursor = null;

    #[Locked]
    public int $trailTotal = 0;

    /**
     * Per-request memo of hand-off candidates by destination stage and entry (protected = not Livewire state).
     *
     * @var array<string, array<int, array{id: int, name: string}>> keyed "{workflow}:{stage}:{entry}"
     */
    protected array $memberMemo = [];

    /** Per-request memo of the governing workflow + the category it was resolved for. */
    protected ?Workflow $workflowMemo = null;

    protected ?int $workflowMemoFor = null;

    public function mount(?string $ulid = null, ?string $section = null, ?string $locale = null): void
    {
        $this->locale = $this->resolveLocale(app(SiteLocale::class), $locale ?? request()->query('locale'));
        $this->multiLocale = count(app(SiteLocale::class)->enabled()) > 1;

        if ($ulid === null) {
            $this->authorize('create', Entry::class);

            // New entry: take the type (and a default category for posts) from the
            // content_section the admin came from (?section=…); default to a page.
            $contentSection = $this->resolveSection($section ?? request()->query('section'));
            $this->type = $contentSection instanceof ContentSection ? ($contentSection->entry_type ?? 'page') : 'page';
            $this->categoryId = $this->type === 'post' && $contentSection instanceof ContentSection && $contentSection->taxonomy_id !== null
                ? (int) $contentSection->taxonomy_id
                : null;
            $draft = new Entry(['type' => $this->type]);
            // The preview files a post under the pre-filled category, so the publish rule below
            // reads the member's category limits off that category, as it will once saved.
            $this->address = $this->previewAddress($draft);
            $this->placeInList($contentSection instanceof ContentSection && $this->type === 'post' ? $contentSection : null);
            $this->mayPublish = $this->user()->can('publish', $draft);
            $this->saved = $this->tracked();

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
            $this->openBody($this->normalizeBlocks($translation->body['blocks'] ?? []));
            $this->excerpt = $translation->excerpt ?? '';
            $this->seoTitle = $translation->seo_title ?? '';
            $this->seoDescription = $translation->seo_description ?? '';
        }

        $cover = data_get($entry->meta, 'image_url');
        $this->coverImageKey = is_string($cover) ? $cover : '';

        $category = $this->type === 'post' ? $entry->primaryCategory() : null;
        $this->categoryId = $category?->id;
        $this->address = $this->previewAddress($entry, $category);
        $this->placeInList($this->type === 'post' ? $this->sectionOf($category) : null);
        // A save that changes no shared field leaves the entry row alone: this language's own row
        // carries when it was last saved.
        $this->savedAt = self::iso($translation?->updated_at);
        $this->mayPublish = $this->user()->can('publish', $entry);
        $this->liveAddress = $this->routedAddress($entry->id);
        $this->saved = $this->tracked();

        if ($this->governingWorkflow() !== null) {
            $this->loadTrail($entry->id);
        }
    }

    /** A post's category is part of its address: the preview follows the pick (posts only). */
    public function updatedCategoryId(): void
    {
        if ($this->type === 'post') {
            $this->address = $this->previewAddress();
        }
    }

    /**
     * The Google preview's address around the slug, composed as publishing will compose it: the
     * site's primary domain, the language prefix and the path before the slug.
     *
     * @return array{base: string, home: string|null, homeSlug: string}
     */
    private function previewAddress(?Entry $entry = null, ?Taxonomy $category = null): array
    {
        if ($this->addressHost === '') {
            $this->addressHost = SeoFields::publicHost();
        }

        return SeoFields::address($this->addressHost, $this->publicPathPrefix($entry, $category), $this->locale, $this->type === 'page');
    }

    /**
     * The locale to edit — a `?locale=` query param when it is one of the site's enabled
     * content-locales (the Pages list's per-language "Sửa"/"Thêm" links carry it), else the site
     * default. #[Locked] $locale then pins it for the component's lifetime so the client can't
     * flip which translation is being written.
     */
    private function resolveLocale(SiteLocale $siteLocale, mixed $requested): string
    {
        if (is_string($requested) && in_array($requested, $siteLocale->enabled(), true)) {
            return $requested;
        }

        return $siteLocale->default();
    }

    public function addBlock(string $type): void
    {
        if (! in_array($type, app(ComponentRegistry::class)->listedTypes(), true)) {
            $this->addError('blocks', __('entries.block_unsupported', ['type' => $type]));

            return;
        }

        $this->resetErrorBag('blocks');
        // A block added to a written body makes it a layout: the document becomes its first block.
        if ($this->prose) {
            $this->leaveProse();
        }
        $this->blocks[] = ['type' => $type, 'props' => $this->defaultProps($type)];
        $this->openBlock = array_key_last($this->blocks);
    }

    /** «Sửa nội dung» on a block row: opens its content form, or closes it when open. */
    public function toggleBlock(int $index): void
    {
        $this->openBlock = $this->openBlock === $index || ! array_key_exists($index, $this->blocks) ? null : $index;
    }

    /**
     * Which editor the body opens in (EntryProseBody::read): the rich editor for a written body, the
     * block list for a layout. Nothing is written: a converted body is saved by the person's save.
     *
     * @param  list<array{type: string, props: array<string, mixed>}>  $blocks
     */
    private function openBody(array $blocks): void
    {
        $this->openBlock = null;
        $prose = app(EntryProseBody::class)->read($blocks);
        if ($prose === null) {
            $this->prose = false;
            $this->blocks = $this->layoutForEditor($blocks);
            $this->bodyHtml = '';
            $this->proseLook = [];
            $this->converted = false;

            return;
        }

        $this->prose = true;
        $this->blocks = [];
        $this->bodyHtml = $prose['html'];
        $this->proseLook = $prose['look'];
        $this->converted = $prose['converted'];
    }

    /**
     * The written body as stored blocks (its one formatted-text block, or none when empty). A picture
     * that is not one of this site's library pictures refuses: saving would drop it.
     *
     * @return list<array{type: string, props: array<string, mixed>}>
     */
    private function proseBlocks(): array
    {
        $written = app(EntryProseBody::class)->write(PlainText::lines($this->bodyHtml, trim: false), $this->proseLook);
        if ($written['foreign'] > 0) {
            throw ValidationException::withMessages(['bodyHtml' => __('entries.ed.body_foreign_images', ['n' => $written['foreign']])]);
        }

        return $written['blocks'];
    }

    /**
     * The formatted-text blocks of a layout in the editor's form: library pictures by address, so no
     * id reaches the browser (as in the written body).
     *
     * @param  list<array{type: string, props: array<string, mixed>}>  $blocks
     * @return list<array{type: string, props: array<string, mixed>}>
     */
    private function layoutForEditor(array $blocks): array
    {
        $prose = app(EntryProseBody::class);
        foreach ($blocks as $index => $block) {
            if ($block['type'] === EntryProseBody::TYPE && is_string($block['props']['html'] ?? null)) {
                $blocks[$index]['props']['html'] = $prose->forEditor($block['props']['html']);
            }
        }

        return $blocks;
    }

    /**
     * The layout as stored: each formatted-text block names its library pictures by id. A picture
     * that is not one of this site's library pictures refuses: saving would drop it.
     *
     * @return list<array{type: string, props: array<string, mixed>}>
     */
    private function layoutForStorage(): array
    {
        $prose = app(EntryProseBody::class);
        $blocks = $this->blocks;
        $foreign = 0;
        foreach ($blocks as $index => $block) {
            $props = $block['props'] ?? null;
            if (($block['type'] ?? null) === EntryProseBody::TYPE && is_array($props) && is_string($props['html'] ?? null)) {
                $stored = $prose->forStorage($props['html']);
                $props['html'] = $stored['html'];
                $blocks[$index]['props'] = $props;
                $foreign += $stored['foreign'];
            }
        }
        if ($foreign > 0) {
            throw ValidationException::withMessages(['blocks' => __('entries.ed.body_foreign_images', ['n' => $foreign])]);
        }

        /** @var list<array{type: string, props: array<string, mixed>}> $blocks */
        return $blocks;
    }

    /**
     * The written body becomes the first block of a layout (the block list takes over), still in the
     * editor's form: its pictures are checked and named by id when the layout is saved.
     */
    private function leaveProse(): void
    {
        $html = PlainText::lines($this->bodyHtml, trim: false);
        $this->blocks = EntryProseBody::isBlank($html) ? [] : [['type' => EntryProseBody::TYPE, 'props' => $this->proseLook + ['html' => $html]]];
        $this->prose = false;
        $this->bodyHtml = '';
        $this->proseLook = [];
        $this->resetErrorBag('bodyHtml');
    }

    public function removeBlock(int $index): void
    {
        if (! array_key_exists($index, $this->blocks)) {
            return;
        }

        $blocks = $this->blocks;
        array_splice($blocks, $index, 1);
        $this->blocks = $blocks;
        $this->lookSplit = [];
        if ($this->openBlock !== null) {
            $this->openBlock = match (true) {
                $this->openBlock === $index => null,
                $this->openBlock > $index => $this->openBlock - 1,
                default => $this->openBlock,
            };
        }
    }

    public function moveUp(int $index): void
    {
        if ($index <= 0 || ! array_key_exists($index, $this->blocks)) {
            return;
        }

        $blocks = $this->blocks;
        [$blocks[$index - 1], $blocks[$index]] = [$blocks[$index], $blocks[$index - 1]];
        $this->blocks = $blocks;
        $this->lookSplit = [];
        $this->followMove($index, $index - 1);
    }

    public function moveDown(int $index): void
    {
        if (! array_key_exists($index + 1, $this->blocks)) {
            return;
        }

        $blocks = $this->blocks;
        [$blocks[$index], $blocks[$index + 1]] = [$blocks[$index + 1], $blocks[$index]];
        $this->blocks = $blocks;
        $this->lookSplit = [];
        $this->followMove($index, $index + 1);
    }

    /** The open content form follows its block when two blocks swap places. */
    private function followMove(int $from, int $to): void
    {
        $this->openBlock = match ($this->openBlock) {
            $from => $to,
            $to => $from,
            default => $this->openBlock,
        };
    }

    /**
     * One choice on a block's "Trình bày" panel. Only a value listed in StyleProps is kept, and only
     * where the block's schema takes the look prop (page_header's own `overlay` is not this one). A
     * colour set paints the band (under a photo it waits until the photo is removed); `spacing` sets
     * the top and bottom step together.
     */
    public function setLook(int $index, string $prop, string $value): void
    {
        if (! in_array($value, StyleProps::CHOICES[$prop] ?? [], true) || ! $this->takesLook($index, $prop === 'spacing' ? 'spacing_top' : $prop)) {
            return;
        }

        $props = $this->lookProps($index);
        if ($prop === 'scheme') {
            $props['scheme'] = $value;
            $props['bg'] = ($props['bg'] ?? null) === 'image' ? 'image' : 'scheme';
        } elseif ($prop === 'spacing') {
            $props['spacing_top'] = $value;
            $props['spacing_bottom'] = $value;
        } else {
            $props[$prop] = $value;
        }
        $this->setBlockProp("{$index}.props", $props);
    }

    /** Show the top and bottom spacing apart, or join them again (bottom takes the top step). */
    public function toggleLookSplit(int $index): void
    {
        if (! array_key_exists($index, $this->blocks)) {
            return;
        }

        if ($this->spacingSplit($index)) {
            unset($this->lookSplit[$index]);
            $props = $this->lookProps($index);
            $top = $props['spacing_top'] ?? null;
            if (is_string($top)) {
                $props['spacing_bottom'] = $top;
            } else {
                unset($props['spacing_bottom']);
            }
            $this->setBlockProp("{$index}.props", $props);
        } else {
            $this->lookSplit[$index] = true;
        }
    }

    /** Take the background photo away: the band goes back to the chosen colour set, if any. */
    public function removeLookImage(int $index): void
    {
        if (! $this->takesLookPhoto($index)) {
            return;
        }

        $props = $this->lookProps($index);
        unset($props['bg_image'], $props['overlay']);
        $props['bg'] = isset($props['scheme']) ? 'scheme' : 'none';
        $this->setBlockProp("{$index}.props", $props);
    }

    /**
     * A typed link anchor becomes a valid one ("Liên hệ" → "lien-he"); blank clears it. One that another
     * block or the page layout already uses gets a number ("lien-he-2"), so a link never lands on the
     * wrong place. Text with no Latin letter or digit (お問い合わせ, emoji) stays as typed, with a message
     * saying how to fix it, instead of vanishing.
     */
    public function updatedBlocks(mixed $value, ?string $key = null): void
    {
        // Rows are drawn by position: a list sent whole keeps positions as its keys.
        /** @var mixed $sent the client sends any shape */
        $sent = $this->blocks;
        if ($key === null && is_array($sent) && ! array_is_list($sent)) {
            /** @var list<array<string, mixed>> $list */
            $list = array_values($sent);
            $this->blocks = $list;
        }
        // A block list sent whole while the body is written: it becomes a layout, the document first.
        if ($key === null && $this->prose && $this->blocks !== []) {
            $sent = $this->blocks;
            $this->leaveProse();
            $this->blocks = array_merge($this->blocks, $sent);

            return;
        }

        if ($key === null || preg_match('/^(\d+)\.props\.anchor_id$/', $key, $match) !== 1) {
            return;
        }

        $index = (int) $match[1];
        $field = "blocks.{$index}.props.anchor_id";
        $typed = is_string($value) ? trim($value) : '';
        $anchor = rtrim(Str::limit(Str::slug($typed), 64, ''), '-');
        if ($typed !== '' && $anchor === '') {
            $this->addError($field, __('entries.look_anchor_invalid'));

            return;
        }

        $this->resetErrorBag($field);
        $props = $this->lookProps($index);
        if ($anchor === '') {
            unset($props['anchor_id']);
        } else {
            $props['anchor_id'] = $this->freeAnchor($anchor, $index);
        }
        $this->setBlockProp("{$index}.props", $props);
    }

    /** Whether the block at $index takes this look prop from the panel (not a same-named prop of its own). */
    private function takesLook(int $index, string $key): bool
    {
        $type = $this->blocks[$index]['type'] ?? null;
        $registry = app(ComponentRegistry::class);

        return is_string($type) && $registry->has($type)
            && ($registry->schemaFor($type)[$key] ?? null) === (StyleProps::SCHEMA[$key] ?? false);
    }

    /**
     * Whether the panel's background photo applies: a block with a photo and darkening of its own
     * (page_header, hero_slider) keeps those, and the panel offers no second photo.
     */
    private function takesLookPhoto(int $index): bool
    {
        return $this->takesLook($index, 'bg_image') && $this->takesLook($index, 'overlay');
    }

    /** $anchor, or it with the first free number, when another block or the layout already uses it. */
    private function freeAnchor(string $anchor, int $index): string
    {
        $taken = StyleProps::LAYOUT_IDS;
        foreach (array_keys($this->blocks) as $other) {
            $used = $this->lookProps($other)['anchor_id'] ?? null;
            if ($other !== $index && is_string($used)) {
                $taken[] = $used;
            }
        }

        $candidate = $anchor;
        for ($n = 2; in_array($candidate, $taken, true); $n++) {
            $suffix = '-'.$n;
            $candidate = rtrim(substr($anchor, 0, 64 - strlen($suffix)), '-').$suffix;
        }

        return $candidate;
    }

    private function spacingSplit(int $index): bool
    {
        $props = $this->lookProps($index);

        return ($this->lookSplit[$index] ?? false) || ($props['spacing_top'] ?? null) !== ($props['spacing_bottom'] ?? null);
    }

    /**
     * A block's props (an empty list for a missing block).
     *
     * @return array<array-key, mixed>
     */
    private function lookProps(int $index): array
    {
        $props = $this->blocks[$index]['props'] ?? null;

        return is_array($props) ? $props : [];
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

    /** "Lưu nháp" / "Lưu" / "Cập nhật" on content that is not live: save, the status kept. */
    public function save(): void
    {
        $this->persist();

        $time = app(SiteTime::class)->forDisplay(CarbonImmutable::now())?->format('H:i') ?? '';
        $this->dispatch('bs-toast', text: $this->status === 'draft'
            ? __('entries.ed.toast_draft', ['time' => $time])
            : __('entries.ed.toast_kept', ['status' => $this->statusPill()['label']]));
    }

    /** "Đăng" / "Cập nhật" (live content) / "Gửi duyệt" (no right to publish): save, then publish or send. */
    public function publish(): void
    {
        $entry = $this->persist();
        $was = $this->status;

        try {
            $this->status = app(PublishEntry::class)->handle($entry, $this->locale, $this->user());
            $this->liveAddress = $this->routedAddress($entry->id);
            $unit = $this->unit();
            $this->dispatch('bs-toast', text: match (true) {
                $this->status === 'published' && $was !== 'published' => __('entries.ed.toast_published', ['unit' => $unit]),
                $this->status === 'published' => __('entries.ed.toast_updated'),
                $this->status === 'pending' => __('entries.ed.toast_sent', ['unit' => $unit]),
                default => __('entries.ed.toast_kept', ['status' => $this->statusPill()['label']]),
            });
        } catch (RouteConflictException) {
            $this->addError('slug', __('entries.slug_conflict'));
        } catch (WorkflowGovernedException) {
            // A tab that still shows the tier-1 button after the flow was switched on: the
            // draft is saved, and the next render swaps in the approval block.
            $this->addError('workflow', __('entries.workflow_governed'));
        }
    }

    /**
     * Move the post along its workflow (design screen 2 action bar). Persists first, exactly
     * like publish(), so an unsaved post can be submitted in one move. The engine is the only
     * judge of the move — the pre-checks below just put the failure on the right field.
     */
    public function applyTransition(string $key): void
    {
        $this->resetErrorBag(['workflow', 'transitionNote', 'assignTo']);

        $entry = $this->persist();
        $this->status = $entry->status;

        $workflow = app(EntryWorkflowResolver::class)->forEntry($entry);
        if ($workflow === null) {
            $this->addError('workflow', __('admin.wfb.err_not_governed'));

            return;
        }

        $transition = $workflow->transitions()->where('key', $key)->first();
        if (! $transition instanceof WorkflowTransition) {
            $this->addError('workflow', __('admin.wfb.err_stale'));

            return;
        }

        $note = trim($this->transitionNote);
        if ($transition->requires_note && $note === '') {
            $this->addError('transitionNote', __('admin.wfb.note_missing'));

            return;
        }

        // A hand-off only exists on the note modal (design screen 2); a value left over from a
        // cancelled modal must not ride along on a plain move. And it may only name someone who
        // can act at the destination — never an arbitrary (or another site's) users.id from the
        // wire payload.
        $assignTo = $transition->requires_note ? $this->assignTo : null;
        if ($assignTo !== null && ! array_key_exists($assignTo, $this->assignableMembers($workflow, $transition->to_state, $entry))) {
            $this->addError('assignTo', __('admin.wfb.assignee_invalid'));

            return;
        }

        try {
            $destination = app(ApplyEntryWorkflowTransition::class)->handle(
                $entry, $this->locale, $this->user(), $key, $note !== '' ? $note : null, $assignTo,
            );
        } catch (RouteConflictException) {
            $this->addError('slug', __('entries.slug_conflict'));

            return;
        } catch (InvalidTransitionException) {
            // Another tab moved the post first, or the flow was reconfigured under this one.
            $this->addError('workflow', __('admin.wfb.err_stale'));

            return;
        } catch (AuthorizationException) {
            $this->addError('workflow', __('admin.wfb.err_forbidden'));

            return;
        } catch (EntryNotGovernedException) {
            // NOT a broad RuntimeException: QueryException is one too, and reporting a deadlock
            // as "no workflow here any more" would push the editor toward publishing directly.
            $this->addError('workflow', __('admin.wfb.err_not_governed'));

            return;
        }

        $entry->refresh();
        $this->status = $entry->status;
        $this->liveAddress = $this->routedAddress($entry->id);
        $this->transitionNote = '';
        $this->assignTo = null;
        $this->loadTrail($entry->id);

        $stepLabel = $workflow->states()->where('key', $destination)->value('label');

        $this->dispatch('workflow-moved');   // closes the note modal only on success
        $this->dispatch('bs-toast', text: __('admin.wfb.moved', [
            'action' => $transition->label,
            'step' => is_string($stepLabel) ? $stepLabel : $destination,
        ]));
    }

    /** "Xem thêm": the next keyset page of the trail, appended below what is already shown. */
    public function loadMoreTrail(): void
    {
        if ($this->ulid === null || $this->trailCursor === null) {
            return;
        }

        // Re-authorize per request (livewire/update skips the route middleware): the trail
        // carries reviewers' notes, so it is read on the same terms as the entry itself.
        $entry = $this->resolveOrFail($this->ulid);
        $this->authorize('update', $entry);

        $page = app(WorkflowTrail::class)->forEntry($entry->id, self::TRAIL_PAGE, $this->trailCursor);
        $this->trail = array_merge($this->trail, $this->trailRows($page['rows']));
        $this->trailCursor = $page['cursor'];
        $this->trailTotal = $page['total'];
    }

    /** (Re)load the first page of the trail — after mount, and after every move. */
    private function loadTrail(int $entryId): void
    {
        $page = app(WorkflowTrail::class)->forEntry($entryId, self::TRAIL_PAGE);
        $this->trail = $this->trailRows($page['rows']);
        $this->trailCursor = $page['cursor'];
        $this->trailTotal = $page['total'];
    }

    /**
     * Trail rows as plain state: the timestamp becomes ISO-8601 so it survives the Livewire
     * snapshot (the view renders it relative, in the site's zone).
     *
     * @param  list<array{id: int, at: CarbonInterface, actor: string|null, transition: string, from: string, to: string, note: string|null}>  $rows
     * @return list<array{id: int, at: string, actor: string|null, transition: string, from: string, to: string, note: string|null}>
     */
    private function trailRows(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'at' => $row['at']->toIso8601String(),
            'actor' => $row['actor'],
            'transition' => $row['transition'],
            'from' => $row['from'],
            'to' => $row['to'],
            'note' => $row['note'],
        ], $rows);
    }

    /**
     * The active workflow governing the PICKED category's section, or null (tier-1). Memoized
     * per request (the component instance lives one request), keyed by the category so a
     * category change inside the same request is never answered from the previous pick.
     */
    private function governingWorkflow(): ?Workflow
    {
        if ($this->type !== 'post' || $this->categoryId === null) {
            return null;
        }

        if ($this->workflowMemoFor !== $this->categoryId) {
            $category = Taxonomy::query()->whereKey($this->categoryId)->first();
            $this->workflowMemo = $category instanceof Taxonomy ? app(EntryWorkflowResolver::class)->forCategory($category) : null;
            $this->workflowMemoFor = $this->categoryId;
        }

        return $this->workflowMemo;
    }

    /**
     * Everything the "Trạng thái duyệt" block renders — stages, the current one, the user's
     * transitions (with hand-off candidates for the note ones) and the hints around them. A
     * flat set of queries: states, allowed transitions, and ONE members lookup per distinct
     * destination that a note transition can reach.
     *
     * @return array{
     *     name: string,
     *     steps: list<array{key: string, label: string, is_published: bool, is_terminal: bool, is_initial: bool, state: 'done'|'current'|'todo'}>,
     *     current: array{index: int, label: string, is_published: bool},
     *     next_label: string|null,
     *     pre_workflow: bool,
     *     is_live: bool,
     *     assignee: string|null,
     *     missing: list<string>,
     *     actions: list<array{key: string, label: string, to_label: string, to_published: bool, note: bool, members: list<array{id: int, name: string}>}>,
     * }
     */
    private function workflowPanel(Workflow $workflow, ?Entry $entry): array
    {
        $states = $workflow->states()->orderBy('sort_order')->orderBy('id')->get()->keyBy('key');
        $initial = $states->first(fn (WorkflowState $state): bool => $state->is_initial);
        $initialKey = $initial instanceof WorkflowState ? $initial->key : (string) $states->keys()->first();

        // The origin the ENGINE will use decides which buttons exist (one definition, shared with
        // ApplyEntryWorkflowTransition); the drawn position falls back to the start when that key
        // is one the (rebuilt) flow no longer defines.
        $fromState = $entry !== null ? app(EntryWorkflowResolver::class)->originFor($workflow, $entry) : $initialKey;
        $currentKey = $states->has($fromState) ? $fromState : $initialKey;

        $keys = $states->keys()->values()->all();
        $currentIndex = max(0, (int) array_search($currentKey, $keys, true));

        $steps = [];
        foreach ($states->values() as $index => $state) {
            $steps[] = [
                'key' => $state->key,
                'label' => $state->label,
                'is_published' => $state->is_published,
                'is_terminal' => $state->is_terminal,
                'is_initial' => $state->is_initial,
                'state' => $index < $currentIndex ? 'done' : ($index === $currentIndex ? 'current' : 'todo'),
            ];
        }

        $current = $states->get($currentKey);
        $nextState = $states->values()->get($currentIndex + 1);

        // A post not saved yet will be this user's own once the move persists it, so it is judged
        // as theirs; the per-entry rule (EntryWorkflowResolver::mayApply) then hides a move the
        // action would refuse.
        $user = $this->user();
        $subject = $entry ?? new Entry(['author_id' => $user->id]);
        $resolver = app(EntryWorkflowResolver::class);

        $actions = [];
        foreach (app(WorkflowEngine::class)->allowedTransitions($workflow, $fromState, $user) as $transition) {
            if (! $resolver->mayApply($transition, $subject, $user)) {
                continue;
            }
            $destination = $states->get($transition->to_state);
            $actions[] = [
                'key' => $transition->key,
                'label' => $transition->label,
                'to_label' => $destination instanceof WorkflowState ? $destination->label : $transition->to_state,
                'to_published' => $destination instanceof WorkflowState && $destination->is_published,
                'note' => $transition->requires_note,
                'members' => $transition->requires_note
                    ? array_values($this->assignableMembers($workflow, $transition->to_state, $subject))
                    : [],
            ];
        }

        $assignee = $entry?->assigned_to !== null ? User::query()->whereKey($entry->assigned_to)->value('name') : null;

        return [
            'name' => $workflow->name,
            'steps' => $steps,
            'current' => [
                'index' => $currentIndex,
                'label' => $current instanceof WorkflowState ? $current->label : $currentKey,
                'is_published' => $current instanceof WorkflowState && $current->is_published,
            ],
            'next_label' => $nextState instanceof WorkflowState ? $nextState->label : null,
            // "Written before the flow existed, so it counts as at the start" — only true for
            // content that actually lands at the start; a live post simply stands at its stage.
            'pre_workflow' => $entry !== null && $entry->workflow_state === null && $currentKey === $initialKey,
            'is_live' => $entry?->status === 'published',
            'assignee' => is_string($assignee) ? $assignee : null,
            'missing' => $this->missingPublicFields(),
            'actions' => $actions,
        ];
    }

    /**
     * Members of THIS site who could move $entry on from $stateKey — the people a returned post
     * can be handed to. A member qualifies through at least one transition out of that stage:
     * its permission, and for a send (entries.submit) also the right to edit THIS entry, so a
     * post is never handed to someone the send would then refuse (EntryWorkflowResolver::
     * membersWhoMayApply, the SQL form of mayApply). Keyed by users.id for the validity check.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function assignableMembers(Workflow $workflow, string $stateKey, Entry $entry): array
    {
        // Memo on the instance (one per request) — never a static: under Octane a static would
        // outlive the request and hand one site's members to the next. The entry is part of the
        // key because who may send depends on its author; an unsaved post is the actor's own.
        $memoKey = $workflow->id.':'.$stateKey.':'.($entry->exists ? $entry->id : 'new:'.$entry->author_id);
        if (isset($this->memberMemo[$memoKey])) {
            return $this->memberMemo[$memoKey];
        }

        $query = app(EntryWorkflowResolver::class)
            ->membersWhoMayApply($workflow->transitions->where('from_state', $stateKey), $entry);

        if ($query === null) {
            return $this->memberMemo[$memoKey] = [];
        }

        // Filtered in SQL before the cap, so the cap never hides a member behind people who
        // could not take the post on.
        $members = [];
        foreach ($query->orderBy('name')->limit(self::ASSIGNEE_LIMIT)->get(['users.id', 'users.name']) as $member) {
            $members[(int) $member->id] = ['id' => (int) $member->id, 'name' => (string) $member->name];
        }

        return $this->memberMemo[$memoKey] = $members;
    }

    /**
     * Public-facing fields still empty on this post — said up front so the author fills them
     * before a reviewer has to send it back. Only what the storefront actually shows for a post.
     *
     * @return list<string>
     */
    private function missingPublicFields(): array
    {
        $missing = [];
        if ($this->coverImageKey === '') {
            $missing[] = __('admin.wfb.missing_cover');
        }
        if ($this->prose ? EntryProseBody::isBlank($this->bodyHtml) : $this->blocks === []) {
            $missing[] = __('admin.wfb.missing_body');
        }

        return $missing;
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

    /** The rich editor shows a library picture at an address the save can read back to the picture. */
    protected function editorImageUrl(Media $media): string
    {
        return app(EntryProseBody::class)->editorUrl($media->storage_key);
    }

    /** The stored body names only library pictures: a picture taken from a link is always copied in. */
    protected function editorHotlinks(): bool
    {
        return false;
    }

    public function openBlockImagePicker(string $path): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        $this->blockImagePickerPath = $path;
        $this->showBlockImagePicker = true;
    }

    public function closeBlockImagePicker(): void
    {
        $this->showBlockImagePicker = false;
        $this->blockImagePickerPath = '';
    }

    /**
     * Set a block image prop to a media_id from THIS site's library (RLS-scoped) — never an
     * arbitrary id from the wire payload. Content references media by id (ADR-17), not a URL.
     */
    public function selectBlockImage(int $mediaId): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        if ($this->blockImagePickerPath !== '' && Media::query()->whereKey($mediaId)->exists()) {
            $this->setBlockProp($this->blockImagePickerPath, $mediaId);
            if (preg_match('/^(\d+)\.props\.bg_image$/', $this->blockImagePickerPath, $match) === 1 && $this->takesLookPhoto((int) $match[1])) {
                $this->setBlockProp("{$match[1]}.props.bg", 'image');
            }
        }

        $this->showBlockImagePicker = false;
        $this->blockImagePickerPath = '';
    }

    public function clearBlockImage(string $path): void
    {
        $this->setBlockProp($path, null);
    }

    /** Set a dotted prop path inside $blocks (e.g. "0.props.logos.1.image_url"), keeping the list type. */
    private function setBlockProp(string $path, mixed $value): void
    {
        $blocks = $this->blocks;
        data_set($blocks, $path, $value);
        /** @var list<array<string, mixed>> $blocks */
        $this->blocks = $blocks;
    }

    public function render(): View
    {
        $urls = app(MediaUrlResolver::class);
        $schemas = $this->schemas();
        $workflow = $this->governingWorkflow();
        $buttonPanel = $this->buttonPanel($schemas);

        $this->blocksChanged = $this->blocksHash() !== ($this->saved['blocks'] ?? '') ? 1 : 0;
        $actions = $this->actions($workflow);

        return view('admin::livewire.entries.editor', [
            'unit' => $this->unit(),
            'listUrl' => route('admin.entries.index', $this->sectionKey !== null ? ['section' => $this->sectionKey] : []),
            'note' => $this->saveNote(),
            'actions' => $actions,
            'leave' => $this->leaveWords($actions),
            'changes' => $this->changeCount(),
            'pill' => $this->statusPill(),
            'liveUrl' => $this->liveUrl(),
            'buttons' => $buttonPanel['buttons'],
            'buttonPanel' => $buttonPanel,
            'availableTypes' => app(ComponentRegistry::class)->listedTypes(),
            'kinds' => $this->blockKinds(),
            'prosePreview' => $this->prose ? EntryProseBody::preview($this->bodyHtml) : '',
            'localeLabel' => Locales::label($this->locale),
            // The writing area's hint: in another language it says that version is still empty.
            'bodyPlaceholder' => $this->locale === app(SiteLocale::class)->default()
                ? __('entries.ed.body_placeholder', ['unit' => mb_strtolower($this->unit())])
                : __('entries.ed.body_placeholder_tr', ['lang' => Locales::label($this->locale)]),
            'schemas' => $schemas,
            'categories' => $this->type === 'post' ? $this->availableCategories() : [],
            'urls' => $urls,
            'coverUrl' => $this->coverImageKey !== '' ? $urls->url($this->coverImageKey, 640) : null,
            'blockImageUrls' => $this->resolveBlockImageUrls($schemas),
            'goneProducts' => $this->goneProductKeys(),
            'looks' => $this->looks(),
            'schemeColours' => $this->schemeColours(),
            'pickerMedia' => $this->showMediaPicker || $this->showBlockImagePicker
                ? Media::query()->orderByDesc('created_at')->limit(60)->get()
                : collect(),
            'workflow' => $workflow !== null
                ? $this->workflowPanel($workflow, $this->ulid !== null ? $this->resolveOrFail($this->ulid) : null)
                : null,
        ]);
    }

    /**
     * The name, chip, glyph and tint of every kind «Trình dựng» shows: the body's blocks and the
     * kinds the add list offers, in the add list's order.
     *
     * @return array<string, array{label: string, chip: string, icon: string, tint: string}>
     */
    private function blockKinds(): array
    {
        $kinds = [];
        foreach ([...app(ComponentRegistry::class)->listedTypes(), ...array_column($this->blocks, 'type')] as $type) {
            if (is_string($type)) {
                $kinds[$type] ??= BlockKinds::of($type);
            }
        }

        return $kinds;
    }

    /** "Bỏ thay đổi" (the leave dialog): back to the saved entry, a new one back to an empty form. */
    public function resetChanges(): void
    {
        $this->resetErrorBag();
        $this->saveFailed = false;
        // A language with no saved version yet loads nothing back: start from an empty form.
        $this->reset('title', 'slug', 'excerpt', 'seoTitle', 'seoDescription', 'coverImageKey', 'blocks', 'bodyHtml', 'prose', 'proseLook', 'converted', 'lookSplit', 'openBlock',
            'transitionNote', 'assignTo', 'showMediaPicker', 'showBlockImagePicker', 'blockImagePickerPath');
        $this->mount($this->ulid, $this->sectionKey, $this->locale);
    }

    /**
     * The tracked values as the server writes them: text as it is, the category id as text, the
     * block list as a fingerprint (the browser leaves it to blocksChanged).
     *
     * @return array<string, string>
     */
    private function tracked(): array
    {
        $values = [];
        foreach (self::TRACKED as $field) {
            $value = $this->{$field};
            $values[$field] = is_scalar($value) ? (string) $value : '';
        }
        $values['blocks'] = $this->blocksHash();

        return $values;
    }

    private function blocksHash(): string
    {
        return md5((string) json_encode($this->blocks));
    }

    /** How many tracked fields, and whether the block list, differ from the saved entry. */
    private function changeCount(): int
    {
        $count = $this->blocksChanged;
        foreach ($this->tracked() as $field => $value) {
            if ($field !== 'blocks' && ($this->saved[$field] ?? '') !== $value) {
                $count++;
            }
        }

        return $count;
    }

    /** The list this entry belongs to: a post's content section, the pages list for a page. */
    private function placeInList(?ContentSection $section): void
    {
        $this->sectionKey = $section?->key;
        $this->listLabel = match (true) {
            $section instanceof ContentSection => $section->localizedLabel(),
            $this->type === 'post' => (string) __('section.posts'),
            default => (string) __('section.pages'),
        };
    }

    /**
     * The post section a category belongs to: the one publishing files the post under (the nearest
     * category on its tree path a section is built on, as SectionResolver reads it; the address
     * above has already asked, so it is remembered), else the site's first post section.
     */
    private function sectionOf(?Taxonomy $category): ?ContentSection
    {
        $posts = ContentSection::query()->where('entry_type', 'post')->orderBy('sort_order')->orderBy('id');
        $key = $category instanceof Taxonomy ? app(SectionResolver::class)->forCategory($category)['key'] ?? null : null;
        if ($key !== null) {
            $found = (clone $posts)->where('key', $key)->first();
            if ($found instanceof ContentSection) {
                return $found;
            }
        }

        return $posts->first();
    }

    /** This entry's edit address, in the language being edited when that is not the site's default. */
    private function editUrl(Entry $entry): string
    {
        $query = $this->locale !== app(SiteLocale::class)->default() ? ['locale' => $this->locale] : [];

        return route('admin.entries.edit', ['ulid' => $entry->ulid] + $query);
    }

    private static function iso(?DateTimeInterface $at): ?string
    {
        return $at !== null ? CarbonImmutable::instance($at)->utc()->toIso8601String() : null;
    }

    /** What is being edited, in the sentence: "trang", or the post section's name ("tin tức"). */
    private function unit(): string
    {
        return $this->type === 'page' ? (string) __('entries.noun_page') : mb_strtolower($this->listLabel);
    }

    /**
     * The status pill of the "Đăng" card: the saved status (a new entry reads as a draft).
     *
     * @return array{status: string, label: string}
     */
    private function statusPill(): array
    {
        $status = $this->status ?? 'draft';
        $key = 'entries.ed.pill_'.$status;

        return ['status' => $status, 'label' => Lang::has($key) ? (string) __($key) : $status];
    }

    /**
     * The save state beside the title while nothing is being typed: a new entry not saved yet,
     * saved at a time, or a field to fix. The browser shows the typing states over it.
     *
     * @return array{key: string, icon: string, text: string, full: string, tip: string}
     */
    private function saveNote(): array
    {
        if ($this->saveFailed) {
            $text = (string) __('catalog.ed.sv_error');

            return ['key' => 'error', 'icon' => 'ri-error-warning', 'text' => $text, 'full' => $text, 'tip' => $text];
        }

        $at = $this->savedAt !== null ? app(SiteTime::class)->forDisplay(CarbonImmutable::parse($this->savedAt)) : null;
        if (! $at instanceof CarbonImmutable) {
            $text = (string) __('entries.ed.sv_new', ['unit' => Str::ucfirst($this->unit())]);

            return ['key' => 'auto', 'icon' => 'ri-draft', 'text' => $text, 'full' => $text, 'tip' => $text];
        }

        $today = $at->isSameDay(app(SiteTime::class)->now());
        $time = $at->format('H:i');
        $full = (string) ($today ? __('catalog.ed.sv_full_today', ['time' => $time]) : __('catalog.ed.sv_full_day', ['time' => $time, 'day' => $at->format('d/m/Y')]));
        $text = (string) ($today ? __('entries.ed.sv_saved', ['when' => $time]) : __('entries.ed.sv_saved', ['when' => $at->format('d/m')]));

        return ['key' => 'saved', 'icon' => 'ri-checkbox-circle', 'text' => $text, 'full' => $full, 'tip' => (string) __('catalog.ed.sv_tip', ['text' => $full])];
    }

    /**
     * The bar's two buttons. The secondary one saves keeping the status; the main one publishes
     * ("Đăng", "Cập nhật" on live content), sends for review for a member who may not publish
     * ("Gửi duyệt"), or saves a scheduled, private or archived entry as it is. Under an approval
     * workflow the moves are the workflow block's, so the bar keeps only the save.
     *
     * @return array{secondary: string|null, primary: string|null, primaryAction: string, primaryIcon: string}
     */
    private function actions(?Workflow $workflow): array
    {
        $status = $this->status ?? 'draft';
        $draftWord = (string) __($status === 'draft' ? 'entries.save_draft' : 'entries.ed.save_plain');
        if ($workflow !== null) {
            return ['secondary' => $draftWord, 'primary' => null, 'primaryAction' => 'save', 'primaryIcon' => 'ri-save-3'];
        }

        $user = $this->user();
        $canPublish = $this->mayPublish;
        if (in_array($status, ['published', 'scheduled', 'private', 'archived'], true)) {
            return [
                'secondary' => null,
                'primary' => (string) __($canPublish ? 'entries.ed.update' : 'entries.ed.save_plain'),
                'primaryAction' => $canPublish && $status === 'published' ? 'publish' : 'save',
                'primaryIcon' => $canPublish ? 'ri-send-plane-fill' : 'ri-save-3',
            ];
        }

        return match (true) {
            $canPublish => ['secondary' => $draftWord, 'primary' => (string) __('entries.publish'), 'primaryAction' => 'publish', 'primaryIcon' => 'ri-send-plane-fill'],
            // Already waiting for review: sending it again moves nothing, so only the save stays.
            $status !== 'pending' && $user->can('entries.submit') => ['secondary' => $draftWord, 'primary' => (string) __('entries.ed.submit'), 'primaryAction' => 'publish', 'primaryIcon' => 'ri-send-plane'],
            default => ['secondary' => $draftWord, 'primary' => null, 'primaryAction' => 'save', 'primaryIcon' => 'ri-save-3'],
        };
    }

    /**
     * The leave dialog's sentence and its buttons, and which save it runs (the bar's save, or its
     * main button when the bar has no other).
     *
     * @param  array{secondary: string|null, primary: string|null, primaryAction: string, primaryIcon: string}  $actions
     * @return array{body: string, save: string, discard: string, method: string}
     */
    private function leaveWords(array $actions): array
    {
        return [
            'body' => (string) __('entries.ed.leave_body', ['unit' => $this->unit()]),
            'save' => (string) __('entries.ed.leave_save'),
            'discard' => (string) __('entries.ed.leave_discard'),
            'method' => $actions['secondary'] !== null ? 'save' : $actions['primaryAction'],
        ];
    }

    /** The live address of published content (no scheme); null when this language is not live. */
    private function liveUrl(): ?string
    {
        return $this->status === 'published' ? $this->liveAddress : null;
    }

    /**
     * Where visitors reach this language's page (no scheme): the path of its route, which only a
     * publish writes, with the language prefix PathBuilder puts on it. Null unless the entry is
     * published and this language's version is published and has a route — a language saved on a
     * live entry but never published has no address yet.
     */
    private function routedAddress(int $entryId): ?string
    {
        if ($this->status !== 'published') {
            return null;
        }

        $path = SiteRoute::query()
            ->where('entry_id', $entryId)
            ->where('locale', $this->locale)
            ->whereExists(fn (QueryBuilder $translation) => $translation->selectRaw('1')->from('entry_translations')
                ->whereColumn('entry_translations.entry_id', 'routes.entry_id')
                ->whereColumn('entry_translations.site_id', 'routes.site_id')
                ->where('entry_translations.locale', $this->locale)
                ->where('entry_translations.status', 'published'))
            ->value('path');
        if (! is_string($path)) {
            return null;
        }

        if ($this->addressHost === '') {
            $this->addressHost = SeoFields::publicHost();
        }

        return $this->addressHost.app(PathBuilder::class)->publicUrl($path, $this->locale);
    }

    /**
     * What each block's "Trình bày" panel shows: the colour set it paints today, whether a heavy set
     * (brand, dark) repeats on a neighbour (two such bands side by side read as one), the photo band,
     * the first block (it never moves) and whether its spacing is shown apart.
     *
     * @return array<int, array{scheme: string, clash: bool, image: bool, first: bool, split: bool}>
     */
    private function looks(): array
    {
        $schemes = [];
        $types = [];
        foreach (array_keys($this->blocks) as $i) {
            $props = $this->lookProps($i);
            $types[$i] = is_string($this->blocks[$i]['type'] ?? null) ? $this->blocks[$i]['type'] : '';
            $schemes[$i] = ($props['bg'] ?? null) === 'image' ? null : StyleProps::schemeOf($props, $types[$i]);
        }

        $looks = [];
        foreach (array_keys($this->blocks) as $i) {
            $scheme = $schemes[$i];
            $heavy = $scheme === 'brand' || $scheme === 'dark';
            $looks[$i] = [
                'scheme' => $scheme ?? StyleProps::schemeOf(['bg' => 'scheme'] + $this->lookProps($i), $types[$i]),
                'clash' => $heavy && (($schemes[$i - 1] ?? null) === $scheme || ($schemes[$i + 1] ?? null) === $scheme),
                'image' => $scheme === null,
                'first' => $i === 0,
                'split' => $this->spacingSplit($i),
            ];
        }

        return $looks;
    }

    /**
     * The site's colours for each set, so a "Bộ màu" card shows the band it paints: `light` is the
     * page itself, the others the roles ThemeResolver derives.
     *
     * @return array<string, array{bg: string, head: string, body: string, button: string, button_text: string}>
     */
    private function schemeColours(): array
    {
        $variables = app(ThemeResolver::class)->cssVariables();
        $colours = ['light' => [
            'bg' => $variables['surface'], 'head' => $variables['ink'], 'body' => $variables['body'],
            'button' => $variables['primary'], 'button_text' => $variables['color-on-primary'],
        ]];
        foreach (['soft', 'brand', 'dark'] as $set) {
            $colours[$set] = [
                'bg' => $variables["sch-{$set}-bg"], 'head' => $variables["sch-{$set}-head"], 'body' => $variables["sch-{$set}-body"],
                'button' => $variables["sch-{$set}-pri-bg"], 'button_text' => $variables["sch-{$set}-pri-fg"],
            ];
        }

        return $colours;
    }

    /**
     * The hand-picked product keys of the product grids in the body that name no published product of
     * this site (deleted, unpublished or another site's), upper case: the list shows them as gone. One query.
     *
     * @return list<string>
     */
    private function goneProductKeys(): array
    {
        $keys = [];
        foreach ($this->blocks as $block) {
            $props = Cast::arr($block['props'] ?? null);
            if (($block['type'] ?? null) === 'product_grid' && ($props['source'] ?? null) === 'picked') {
                $keys = array_merge($keys, Cast::arr($props['product_keys'] ?? null));
            }
        }

        return ProductGridComponent::goneKeys($keys);
    }

    /**
     * Preview URLs for every block image prop, resolved in ONE query (a thumbnail sits next to each
     * picker). A media_id → its URL; unknown ids are simply absent.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $schemas
     * @return array<int, string>
     */
    private function resolveBlockImageUrls(array $schemas): array
    {
        $ids = [];
        foreach ($this->blocks as $block) {
            $type = $block['type'] ?? null;
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            if (is_string($type) && isset($schemas[$type])) {
                $ids = array_merge($ids, SchemaImages::ids($props, $schemas[$type]));
            }
        }

        return app(MediaLibraryUrls::class)->urlsForIds($ids);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = self::onFormFields(app(EntryContentRules::class)->rules());

        // A post's URL is its category slug + post slug, so a post must have a category.
        if ($this->type === 'post') {
            $rules['categoryId'] = [
                'required', 'integer',
                Rule::exists('taxonomies', 'id')->where('vocabulary', 'post_cat'),
                $this->keepsGovernedSection(...),
                $this->staysInCategoryLimits(...),
            ];
        }

        return $rules;
    }

    /**
     * A post an active workflow governs may not be re-filed out of that workflow's section.
     *
     * The gate asks "which workflow governs this entry?" AFTER the save has written the picked
     * category, and `categoryId` is client-owned — so without this rule, re-pointing the picker
     * at a category no section binds, pressing Publish, and re-pointing it back would take the
     * post public with no approval and no trail. Filing it under another category of the SAME
     * section (a sub-category, say) is ordinary editing and stays allowed.
     */
    private function keepsGovernedSection(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->ulid === null || ! is_numeric($value)) {
            return;   // a new post has nothing to escape from; `exists` reports a bad value
        }

        $workflows = app(EntryWorkflowResolver::class);
        $current = $workflows->forEntry($this->resolveOrFail($this->ulid));
        if ($current === null) {
            return;   // tier-1 content: filing is free
        }

        $target = Taxonomy::query()->whereKey((int) $value)->first();
        $destination = $target instanceof Taxonomy ? $workflows->forCategory($target) : null;

        if ($destination?->id !== $current->id) {
            $fail(__('entries.category_governed', ['workflow' => $current->name]));
        }
    }

    /**
     * A member limited to some categories files a post only inside them (EntryPolicy::fileUnder):
     * said on the field here; EntryCategoryWriter refuses the write itself whatever the form sends.
     */
    private function staysInCategoryLimits(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            return;   // `required` / `integer` report it
        }

        $response = Gate::inspect('fileUnder', [Entry::class, 'post', (int) $value]);
        if ($response->denied()) {
            $fail((string) $response->message());
        }
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            ...self::onFormFields(app(EntryContentRules::class)->messages()),
            'categoryId.required' => __('entries.category_required'),
            'categoryId.exists' => __('entries.category_invalid'),
        ];
    }

    /**
     * The content rules (or their messages) keyed by this form's field names: the Google fields are
     * columns `seo_title`/`seo_description` in the content shape and `seoTitle`/`seoDescription` here.
     *
     * @template TValue
     *
     * @param  array<string, TValue>  $byColumn
     * @return array<string, TValue>
     */
    private static function onFormFields(array $byColumn): array
    {
        $fields = ['seo_title' => 'seoTitle', 'seo_description' => 'seoDescription'];
        $out = [];
        foreach ($byColumn as $key => $value) {
            $column = explode('.', $key, 2)[0];
            $out[($fields[$column] ?? $column).substr($key, strlen($column))] = $value;
        }

        return $out;
    }

    /**
     * A blank link is made from the title (any writing system) and made free for this language: no
     * other page's link and no other route (a category, a product) may already hold the public path it would get, and a
     * flat page is never given `home`, which publishing turns into the site's root. A link the
     * person typed is never touched. A title with
     * nothing usable leaves it blank, so validation asks for the link. Returns whether it filled one.
     */
    private function fillBlankSlug(): bool
    {
        if (trim($this->slug) !== '') {
            return false;
        }

        $ownId = $this->ulid !== null ? $this->resolveOrFail($this->ulid)->id : null;
        $prefix = $this->publicPathPrefix();

        $this->slug = Slugger::fromTitle(
            $this->title,
            $this->locale,
            fn (string $base): array => [
                ...Slugger::takenAmong(
                    EntryTranslation::query()
                        ->where('locale', $this->locale)
                        ->when($ownId !== null, fn ($q) => $q->where('entry_id', '!=', $ownId)),
                    'slug',
                    $base,
                ),
                ...Slugger::takenAmong(
                    SiteRoute::query()
                        ->where('locale', $this->locale)
                        ->when($ownId !== null, fn ($q) => $q->where(
                            fn ($own) => $own->whereNull('entry_id')->orWhere('entry_id', '!=', $ownId),
                        )),
                    'path',
                    $base,
                    $prefix,
                ),
                ...($prefix === '/' ? [PathBuilder::HOME_SLUG] : []),
            ],
        );

        return $this->slug !== '';
    }

    /**
     * The part of the public path that comes before the slug, composed by PathBuilder for this
     * entry and its picked category exactly as publishing will ('/' for a flat page). The caller may
     * hand in the entry and its picked category when it already holds them.
     */
    private function publicPathPrefix(?Entry $entry = null, ?Taxonomy $category = null): string
    {
        $entry ??= $this->ulid !== null ? $this->resolveOrFail($this->ulid) : new Entry(['type' => $this->type]);

        if ($this->type === 'post') {
            $category ??= $this->categoryId !== null
                ? Taxonomy::query()->with('translations')->find($this->categoryId)
                : null;
            $categories = new EloquentCollection;
            if ($category instanceof Taxonomy) {
                $category->setRelation('pivot', (new Pivot)->forceFill(['position' => 0]));
                $categories->push($category);
            }
            $entry->setRelation('taxonomies', $categories);
        }

        $path = app(PathBuilder::class)->forEntry($entry, $this->locale, 'x');

        return str_ends_with($path, 'x') ? substr($path, 0, -1) : '/';
    }

    /** Validate + persist the entry and its translation (+ category for posts); returns it. */
    private function persist(): Entry
    {
        // No control character is stored (PostgreSQL refuses a NUL); Google shows its two fields on
        // one line, so a line break or an invisible direction mark never reaches the head either.
        $this->title = PlainText::line($this->title);
        $this->slug = PlainText::line($this->slug);
        $this->excerpt = PlainText::lines($this->excerpt);
        $this->seoTitle = SeoFields::singleLine($this->seoTitle);
        $this->seoDescription = SeoFields::singleLine($this->seoDescription);
        $this->blocks = PlainText::linesIn($this->blocks);

        $filled = $this->fillBlankSlug();
        $proseBody = null;
        $layoutBody = null;
        try {
            // A written body is checked as the block it is stored in (ValidPageJson: an html prop's size).
            if ($this->prose) {
                $proseBody = $this->proseBlocks();
                $this->blocks = $proseBody;
            } else {
                $layoutBody = $this->layoutForStorage();
            }
            $this->validate();
        } catch (ValidationException $e) {
            // A link made for a save that failed must not stay behind: it would pin the old title.
            if ($filled) {
                $this->slug = '';
            }
            $this->saveFailed = true;

            throw $e;
        } finally {
            // The stored form names pictures by id: it never stays in the browser's copy of the form.
            if ($this->prose) {
                $this->blocks = [];
            }
        }
        $wasNew = $this->ulid === null;

        if ($this->ulid === null) {
            $this->authorize('create', Entry::class);
            // Opening the editor needs only `create`; what is saved must lie inside the member's
            // category limits (a page has no category, so a limited member writes no page).
            $this->authorize('fileUnder', [Entry::class, $this->type, $this->type === 'post' ? $this->categoryId : null]);
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
            // The entry row first, as every entry action takes it, before the translation is
            // written: a save that changes no shared field writes nothing to the entry row, and a
            // check of the search words running meanwhile must wait for it.
            // The locked row's values replace the ones read before the wait (a publish or a meta change
            // committed meanwhile is kept, not written over), without a second read.
            $locked = Entry::withTrashed()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $entry->setRawAttributes($locked->getAttributes(), true);
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
        $sharedChanged = $entry->isDirty('meta');
        $entry->save();

        $translation = $entry->translations()->firstOrNew(['locale' => $this->locale]);
        // This language's body was older plain-text blocks, now written as one formatted-text block:
        // the old body is kept as a revision first, so the history can bring it back.
        if ($this->converted && $translation->exists) {
            app(RecordEntryRevision::class)->forLocale($entry, $translation, RevisionKind::Baseline, $this->user());
        }
        $translation->fill([
            'title' => $this->title,
            'slug' => $this->slug,
            'body' => ['blocks' => $proseBody ?? $layoutBody ?? $this->blocks],
            'excerpt' => $this->excerpt !== '' ? $this->excerpt : null,
            'seo_title' => $this->seoTitle !== '' ? $this->seoTitle : null,
            'seo_description' => $this->seoDescription !== '' ? $this->seoDescription : null,
            'status' => $this->translationStatus($entry, $translation),
        ])->save();

        // Materialize block image usage into mediables so it surfaces in "where is this media used".
        app(EntryBlockMediaReconciler::class)->reconcile($entry);

        if ($this->type === 'post') {
            app(EntryCategoryWriter::class)->assign($entry, $this->categoryId !== null ? [$this->categoryId] : [], $this->user());
        }

        // A save on live content is what visitors now see: keep that version (skipped when unchanged).
        // A changed cover is shared by every language's page, so then every language is recorded.
        if ($entry->status === 'published') {
            $revisions = app(RecordEntryRevision::class);
            if ($sharedChanged) {
                $revisions->forAllLocales($entry, RevisionKind::Edit, $this->user());
            } else {
                $revisions->forLocale($entry, $translation, RevisionKind::Edit, $this->user());
            }
        }

        $this->saveFailed = false;
        $this->converted = false;
        $this->savedAt = self::iso(CarbonImmutable::now());
        $this->mayPublish = $this->user()->can('publish', $entry);
        $this->saved = $this->tracked();

        if ($wasNew) {
            // The address becomes this entry's edit address, so a reload (or reopening it from the
            // browser's history) keeps editing this entry instead of starting a second one. The
            // form stays as it is: no new page, the focus and the scroll where they were.
            $this->js('history.replaceState(history.state, "", '.json_encode($this->editUrl($entry)).')');
        }

        return $entry;
    }

    /**
     * The status to store on the saved translation. Translations take only draft · pending ·
     * published · archived (entry_translations_status_check); a scheduled or private entry keeps
     * the translation's current state — the same as TransitionEntryStatus, which never touches
     * translations — and a new translation starts as draft.
     */
    private function translationStatus(Entry $entry, EntryTranslation $translation): string
    {
        if (in_array($entry->status, self::TRANSLATION_STATES, true)) {
            return $entry->status;
        }

        return $translation->exists ? $translation->status : 'draft';
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

    /**
     * The gate of the save path, for an endpoint that changes the page being edited: `create` for a
     * page not saved yet, else `update` on the entry.
     */
    private function authorizeEditing(): void
    {
        if ($this->ulid === null) {
            $this->authorize('create', Entry::class);
        } else {
            $this->authorize('update', $this->resolveOrFail($this->ulid));
        }
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

        $spec = $registry->schemaFor($type)[$key] ?? null;
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
            'image', 'video' => $default,   // a media_id, or null for an unset picker
            default => $default ?? '',
        };
    }

    /**
     * Every registered type's §E7 schema with the look props, for the editor view to render
     * inputs/repeaters (the look props, group `style`, render on the "Trình bày" panel instead).
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function schemas(): array
    {
        $registry = app(ComponentRegistry::class);
        $schemas = [];
        foreach ($registry->types() as $type) {
            $schemas[$type] = $registry->schemaFor($type);
        }

        return $schemas;
    }

    /**
     * A saved number outside its schema's range (a list block's `limit` is 1-50) is pulled into it
     * when the block loads, so saving it again never fails on a value the visitor-facing page already
     * treated as the nearest one. A value that is not a number at all reads as the schema default.
     *
     * @param  array<array-key, mixed>  $props
     * @return array<array-key, mixed>
     */
    private function clampNumbers(string $type, array $props): array
    {
        $registry = app(ComponentRegistry::class);
        if (! $registry->has($type)) {
            return $props;
        }

        foreach ($registry->get($type)->schema() as $prop => $spec) {
            if (($spec['type'] ?? null) !== 'int' || ! array_key_exists($prop, $props)
                || (! isset($spec['min']) && ! isset($spec['max'])) || $props[$prop] === null || $props[$prop] === '') {
                continue;
            }
            $value = $props[$prop];
            $props[$prop] = is_numeric($value)
                ? min($spec['max'] ?? PHP_INT_MAX, max($spec['min'] ?? PHP_INT_MIN, (int) $value))
                : ($spec['default'] ?? $spec['min'] ?? 0);
        }

        return $props;
    }

    /**
     * @return list<array{type: string, props: array<string, mixed>}>
     */
    private function normalizeBlocks(mixed $blocks): array
    {
        if (! is_array($blocks)) {
            return [];
        }

        $normalized = [];
        foreach ($blocks as $block) {
            if (is_array($block) && isset($block['type']) && is_string($block['type'])) {
                /** @var array<string, mixed> $props block props are keyed by prop name (a stored body is JSON objects) */
                $props = $this->clampNumbers($block['type'], is_array($block['props'] ?? null) ? $block['props'] : []);
                $normalized[] = ['type' => $block['type'], 'props' => $props];
            }
        }

        return $normalized;
    }
}
