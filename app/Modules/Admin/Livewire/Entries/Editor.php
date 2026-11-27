<?php

namespace App\Modules\Admin\Livewire\Entries;

use App\Modules\Admin\Livewire\Concerns\EditsBlockList;
use App\Modules\Admin\Livewire\Concerns\EditsButtonActions;
use App\Modules\Admin\Livewire\Concerns\EditsJobCard;
use App\Modules\Admin\Livewire\Concerns\EditsPageOrder;
use App\Modules\Admin\Livewire\Concerns\EditsPendingChange;
use App\Modules\Admin\Livewire\Concerns\PlacesSharedBlocks;
use App\Modules\Admin\Livewire\Concerns\ShowsEntryRevisions;
use App\Modules\Admin\Livewire\Concerns\StartsPageFromTemplate;
use App\Modules\Admin\Livewire\Concerns\WithMediaLibraryPicker;
use App\Modules\Admin\Support\Categories\CategorySections;
use App\Modules\Admin\Support\Entries\EntryLangStates;
use App\Modules\Admin\Support\Entries\EntryRefusal;
use App\Modules\Admin\Support\Entries\PageMoveWords;
use App\Modules\Admin\Support\Entries\PageParentChoices;
use App\Modules\Admin\Support\Entries\PostCategoryChoices;
use App\Modules\Admin\Support\Entries\ScheduleInput;
use App\Modules\Admin\Support\EntryProseBody;
use App\Modules\Admin\Support\Guide\EditorGuide;
use App\Modules\Admin\Support\LangBar;
use App\Modules\Admin\Support\Locales;
use App\Modules\Admin\Support\SeoFields;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\ApproveEntryForLater;
use App\Modules\Cms\Actions\ChangeEntryExpiry;
use App\Modules\Cms\Actions\MovePage;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\SaveEntryDraft;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\EntryDraftRejectedException;
use App\Modules\Cms\Exceptions\EntryNotGovernedException;
use App\Modules\Cms\Exceptions\ExpiredContentException;
use App\Modules\Cms\Exceptions\HomePageLockedException;
use App\Modules\Cms\Exceptions\PageMoveRejectedException;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Exceptions\SchedulePastException;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Exceptions\WorkflowGovernedException;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Queries\EntryRevisions;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Cms\Support\CategoryItemCounts;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Support\DisplayDate;
use App\Modules\Cms\Support\EntryBlockMediaReconciler;
use App\Modules\Cms\Support\EntryCategoryWriter;
use App\Modules\Cms\Support\EntryCover;
use App\Modules\Cms\Support\EntryTags;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Cms\Support\HomePages;
use App\Modules\Cms\Support\PageRules;
use App\Modules\Cms\Taxonomy\TaxonomyRules;
use App\Modules\Cms\Validation\DisplayDateNotAfterToday;
use App\Modules\Cms\Validation\EntryContentRules;
use App\Modules\Cms\Validation\ExpiryTime;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Media\Support\MediaRefs;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\PlainText;
use App\Modules\Shared\Support\Slugger;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Rendering\TemplateCatalog;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Js;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

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
    use EditsBlockList;
    use EditsButtonActions;
    use EditsJobCard;
    use EditsPageOrder;
    use EditsPendingChange;
    use PlacesSharedBlocks;
    use ShowsEntryRevisions;
    use StartsPageFromTemplate;
    use WithMediaLibraryPicker;

    /** How many trail rows the block shows before "Xem thêm" (design: 3, +3 per load). */
    private const int TRAIL_PAGE = 3;

    /** The states a translation row may hold (entry_translations_status_check). */
    private const array TRANSLATION_STATES = ['draft', 'pending', 'published', 'archived'];

    /** A ulid as the site writes one (Crockford base 32, any case): a cover named otherwise is never looked up. */
    private const string ULID_SHAPE = '/\A[0-9A-HJKMNP-TV-Z]{26}\z/i';

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
    public const array TRACKED = ['title', 'slug', 'excerpt', 'bodyHtml', 'seoTitle', 'seoDescription', 'categoryIds', 'coverUlid', 'coverAlt', 'parentUlid', 'pageOrder', 'scheduleAt', 'statusTo', 'templateKey', 'displayDate', 'tags', 'expiresAt', 'jobFacts', 'jobDeadline', 'jobDeadlineAccent', 'jobBadge'];

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

    /**
     * The language bar of a saved entry on a site with more than one language (LangBar): one item per
     * enabled language, read in one query when the editor opens and again after each save. Empty: no bar.
     *
     * @var list<array{code: string, label: string, kind: string, done: int, total: int, href: string, current: bool, source: bool, public: bool, live: bool}>
     */
    #[Locked]
    public array $langBar = [];

    /**
     * Primary category id (posts only; index 0 of entry_taxonomies drives the URL): the first of
     * categoryIds, kept in step with it. Set on its own (the earlier one-category picker), it files
     * the post under that one category alone.
     */
    public ?int $categoryId = null;

    /**
     * Every category the post is filed under, its main one («Chuyên mục chính») first — the pivot's
     * positions 0..n-1. Changed only by the card's boxes, its «Chuyên mục chính» choice and its
     * «Thêm chuyên mục mới» (toggleCategory, setMainCategory, addCategory), never by the browser.
     * A section's own category at the front means no category is ticked: the post shows only on
     * the section's page.
     *
     * @var list<int>
     */
    #[Locked]
    public array $categoryIds = [];

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

    /**
     * Featured/OG image: the ulid of the picked picture of this site's library, stored as the row's id
     * in entries.cover_media_id (the save resolves it, FOR KEY SHARE, and drops the old
     * meta.image_url). Locked: only a pick from this site's library (pickedSingleImage), «Bỏ ảnh», its
     * «Hoàn tác», a copy kept on this device (checked) and the server's own loads write it, so a value
     * typed into the wire never reaches the save. '' = no picture of the library (none, or a glyph,
     * an outside address or a file in the Trash, kept as stored while the form leaves the cover alone).
     */
    #[Locked]
    public string $coverUlid = '';

    /** The cover was changed in this form since it was loaded or saved (a pick, «Bỏ ảnh», «Hoàn tác», a kept copy). */
    #[Locked]
    public bool $coverTouched = false;

    /**
     * Set on every open: a form opened before covers were named by their row has it false and holds
     * its cover in coverImageKey (a storage key), taken once as its row's ulid (hydrate).
     */
    #[Locked]
    public bool $coverById = false;

    /** The old form's cover (a storage key); only a form opened before covers were named by their row holds one. */
    #[Locked]
    public string $coverImageKey = '';

    /** The cover's library row read in this request (the open reads it, the card draws it): one read. */
    private ?Media $coverRow = null;

    /** Nobody else changed the stored cover since this form read it: set by persist(), read by the held save too. */
    private bool $coverStoredSame = true;

    /**
     * «Mô tả ảnh»: this language's words for the featured image (entry_translations.cover_alt), what
     * Google and a screen reader take from it; empty = the picture's own words, then the title.
     */
    public string $coverAlt = '';

    /**
     * «Mẫu hiển thị» of a post (board 07 «Thuộc tính bài»): '' = the look its category gives it, else one
     * of the post variants (TemplateCatalog::ROLE_POST_VARIANT). One value for every language.
     */
    public string $templateKey = '';

    /**
     * «Ngày đăng hiện trên bài»: the day (Y-m-d, in the website's zone) the post shows as published on
     * its page and its cards; '' = the real publish day (DisplayDate). One value for every language.
     */
    public string $displayDate = '';

    /** The shown day as stored when the form opened: a save that leaves it as it is never has it refused. */
    #[Locked]
    public string $storedDisplayDate = '';

    /** «Gỡ khỏi website lúc» (board 07, the «Đăng» card): the end time in the website's clock (ExpiryTime::FORMAT); '' = it stays. */
    public string $expiresAt = '';

    /** The end time as stored when the form opened or last saved: an unchanged one is never judged again. */
    #[Locked]
    public string $expiresLoaded = '';

    /** The real publish day (d/m/Y, the website's clock) for the «Ngày đăng» line of the «Đăng» card; '' before a first publish. */
    #[Locked]
    public string $publishedDay = '';

    /** The «Đăng» card's own message («Đã bỏ giờ gỡ», «Đã dùng lại ngày đăng thật»); «Hoàn tác» puts formUndo back. */
    public string $formToast = '';

    public int $formToastSeq = 0;

    /** @var array{field: string, value: string}|null */
    #[Locked]
    public ?array $formUndo = null;

    /**
     * «Thẻ» (board 07): the post's tags named in this language, in order (EntryTags). Added with
     * addTag() and taken out with removeTag(); a name no tag has yet makes a tag on save. The tags
     * named in other languages only are not in the list and stay as they are.
     *
     * @var list<string>
     */
    public array $tags = [];

    /**
     * The post's tags in this language as this form last read them (mount, a save): what a save
     * compares the form's tags with, so it files only the tags the member added or took out since
     * (EntryTags::apply) and never undoes a tag another save filed meanwhile. Server-set only.
     *
     * @var list<string>
     */
    #[Locked]
    public array $tagsLoaded = [];

    /** What is typed in the «Thẻ» card's box, not added yet. */
    public string $tagDraft = '';

    /**
     * Whether this form loaded «Mẫu hiển thị» and «Ngày đăng hiện trên bài» (set on every mount). A form
     * opened before they existed comes back from its snapshot without them, its two fields empty: its
     * save then leaves both as stored instead of clearing a look or a day set since.
     */
    #[Locked]
    public bool $postPropsRead = false;

    /**
     * The message under the form after a cover pick or «Bỏ ảnh» (components/toast): its words and its
     * counter (a new number for every message, so a timer only closes its own).
     */
    #[Locked]
    public string $coverToast = '';

    #[Locked]
    public int $coverToastSeq = 0;

    /**
     * What «Hoàn tác» on «Đã gỡ ảnh đại diện» puts back: the picture and this language's words for it.
     *
     * A message shown before covers were named by their row holds the picture's `key` instead.
     *
     * @var array{ulid?: string, key?: string, alt: string}|null
     */
    #[Locked]
    public ?array $coverUndo = null;

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

    /** This language's body was older plain-text blocks shown converted: the first save keeps a revision of it. */
    #[Locked]
    public bool $converted = false;

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

    /**
     * What was stored, field by field, when this form was loaded or last saved: a fingerprint of
     * every value a save writes (this language's title, slug, body, excerpt and Google fields; the
     * shared cover; a post's main category). A save compares it with what is stored by then, after
     * taking the entry's row lock: a difference means someone else saved meanwhile, and the save
     * stops and asks (askBeforeOverwrite). Writes that change none of these values (a status
     * change, a category moved or renamed, a view count) bump `updated_at` but never this. Empty
     * for an entry not saved yet.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $storedPrint = [];

    /** When the form was loaded or last saved (ISO, UTC): who saved after it is read off the history. */
    #[Locked]
    public ?string $loadedAt = null;

    /**
     * The question «… vừa được … lưu lúc …» while it is open: its words, the fields that differ (this
     * form's value and the one just saved), the save it stopped, and the stored fingerprint it showed.
     *
     * @var array{title: string, line: string, name: string|null, rows: list<array{label: string, mine: string, theirs: string}>, action: string, transition: string|null, print: array<string, string>}|null
     */
    #[Locked]
    public ?array $stale = null;

    /**
     * «Giữ bản của tôi»: the stored fingerprint the member saw in the question and chose to write
     * over. The next save may replace exactly that version; a newer one asks again.
     *
     * @var array<string, string>|null
     */
    #[Locked]
    public ?array $overwrite = null;

    /** The last save asked for was refused for a field to fix (the save state says so until the next one). */
    #[Locked]
    public bool $saveFailed = false;

    /** The content section whose list this entry belongs to (the crumb and "Quay lại" go there). */
    #[Locked]
    public ?string $sectionKey = null;

    /** The member may publish this entry (the publish right and the right to edit it, inside their category limits), as of the last open or save. */
    #[Locked]
    public bool $mayPublish = false;

    /** The ⋮ offers «Chuyển vào thùng rác»: a saved entry the member may delete (EntryPolicy), as of the last open or save. */
    #[Locked]
    public bool $mayTrash = false;

    /** The page is the website's Home page: the ⋮ shows the Trash item locked, with the reason. */
    #[Locked]
    public bool $isHome = false;

    /** The live page is the website's Home page (whoever edits it): its status stays «Công khai». Read only when it was opened live or the member may delete it. */
    #[Locked]
    public bool $homePage = false;

    /** «Xem trước» opens the saved version: a saved entry the member may preview (EntryPolicy). */
    #[Locked]
    public bool $mayPreview = false;

    /**
     * «Trang cha» (pages, board 07): the parent page picked, by ulid (null: a top page). Client-set; every
     * value is checked when it arrives (updatedParentUlid) and again by the save, under the page-tree lock.
     */
    public ?string $parentUlid = null;

    /** The parent the form was loaded with (a new page: the one «Thêm trang con» preset): a save moves the page only when the pick differs. */
    #[Locked]
    public ?string $parentLoaded = null;

    /** The picked parent's title, for the button (empty: «Không có (trang gốc)»). */
    #[Locked]
    public string $parentName = '';

    /** This page is the website's Home page: it has no «Trang cha» (it keeps its place in the tree). */
    #[Locked]
    public bool $pageHome = false;

    /**
     * «Hẹn giờ đăng» of a scheduled entry (board 07): when it goes live, in the website's clock (a
     * datetime-local value). Client-set; judged when the field is left (updatedScheduleAt) and again by
     * the save, which moves the time through TransitionEntryStatus.
     */
    public string $scheduleAt = '';

    /** The time the entry was scheduled for as the form opened, in the website's clock ('' when none): kept unchanged, it is not judged again. */
    #[Locked]
    public string $scheduleLoaded = '';

    /** Set while «Đăng» validates its save (this request only): the entry goes live now, whatever its schedule. */
    private bool $goingLive = false;

    /**
     * «Khi lưu, bài sẽ là» (board 07, the "Đăng" card when no approval workflow governs the entry): the status
     * the next save moves the entry to, '' for the saved one. Client-set; judged when it is picked
     * (updatedStatusTo) and again by the save, which moves it through PublishEntry or TransitionEntryStatus.
     */
    public string $statusTo = '';

    /** The last save of this visit was the draft's own (autosave): the save state reads «Đã lưu nháp …». */
    #[Locked]
    public bool $autoSaved = false;

    /** This language's version has never been saved, and this form has not saved: its link may follow the title. */
    #[Locked]
    public bool $slugFresh = false;

    /** The member typed this version's link: it no longer follows the title. */
    #[Locked]
    public bool $slugTouched = false;

    /** The parent picked before this round trip's change (updatingParentUlid), to put back a refused pick. */
    protected ?string $parentBefore = null;

    /** The guide is printed with the first draw only (the browser keeps it; later round trips skip it). */
    private bool $guideDue = false;

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

    /** Per-request memo of the post categories the «Chuyên mục» card offers (protected = not Livewire state). */
    protected ?PostCategoryChoices $choicesMemo = null;

    /** Per-request memo: whether an approval workflow governs the post's section (null = not asked yet). */
    protected ?bool $homeGovernedMemo = null;

    /** The main category and section the memo above was answered for (a change inside the request asks again). */
    protected ?string $homeGovernedFor = null;

    public function mount(?string $ulid = null, ?string $section = null, ?string $locale = null, ?string $parent = null): void
    {
        $this->locale = $this->resolveLocale(app(SiteLocale::class), $locale ?? request()->query('locale'));
        $this->multiLocale = count(app(SiteLocale::class)->enabled()) > 1;
        $this->postPropsRead = true;
        $this->coverById = true;

        if ($ulid === null) {
            $this->authorize('create', Entry::class);

            // New entry: take the type (and a default category for posts) from the
            // content_section the admin came from (?section=…); default to a page.
            $contentSection = $this->resolveSection($section ?? request()->query('section'));
            $this->type = $contentSection instanceof ContentSection ? ($contentSection->entry_type ?? 'page') : 'page';
            $this->categoryId = $this->type === 'post' && $contentSection instanceof ContentSection && $contentSection->taxonomy_id !== null
                ? $this->defaultCategory($contentSection)
                : null;
            $this->categoryIds = $this->categoryId !== null ? [$this->categoryId] : [];
            // Not saved yet: it will be this user's own once the first save persists it, so the
            // publish rule below (which needs the right to edit it) judges it as theirs. The
            // preview files a post under the pre-filled category, so that rule also reads the
            // member's category limits off that category, as it will once saved.
            $draft = new Entry(['type' => $this->type, 'author_id' => $this->user()->id]);
            $this->address = $this->previewAddress($draft);
            $this->placeInList($contentSection instanceof ContentSection && $this->type === 'post' ? $contentSection : null);
            $this->mayPublish = $this->user()->can('publish', $draft);
            // «Thêm trang con»: the parent named by the list (?parent=<ulid>), when it may hold one more level
            if ($this->type === 'page') {
                $this->presetParent($parent ?? request()->query('parent'));
                $this->placePageOrder($this->parentUlid === null ? null : $this->findPage($this->parentUlid)?->id);
            }
            $this->slugFresh = true;
            $this->loadJobCard(null);
            $this->saved = $this->tracked();
            $this->guideDue = true;

            return;
        }

        $entry = $this->openEntry($ulid);
        $placeBefore = $entry->getAttribute('page_place_before');
        // read with the entry as the form opens (openEntry), never written back with it
        $entry->offsetUnset('page_place_before');
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
            $this->coverAlt = $translation->cover_alt ?? '';
        }

        $this->loadCover($entry->cover_media_id, $entry->meta);
        if ($this->type === 'post') {
            $this->templateKey = TemplateCatalog::variant($entry->template_override) ?? '';
            $this->displayDate = $this->storedDisplayDate = DisplayDate::of($entry->meta);
            $this->tags = app(EntryTags::class)->names($entry, $this->locale);
            $this->tagsLoaded = $this->tags;
            $this->loadJobCard($entry->meta);
        }

        // one read for the post's categories in order, the main one (position 0) among them
        $filed = $this->type === 'post'
            ? $entry->taxonomies()->wherePivot('position', '<', Entry::FACET_POSITION_BASE)->orderByPivot('position')->orderBy('taxonomies.id')->get()
            : null;
        $category = $filed?->first(static function (Taxonomy $taxonomy): bool {
            $pivot = $taxonomy->relationLoaded('pivot') ? $taxonomy->getRelation('pivot') : null;
            $position = $pivot instanceof Pivot ? $pivot->getAttribute('position') : null;

            return is_numeric($position) && (int) $position === 0;
        });
        $this->categoryIds = $filed !== null ? array_values($filed->map(static fn (Taxonomy $taxonomy): int => $taxonomy->id)->all()) : [];
        $this->categoryId = $category?->id;
        // a live entry with a pending change: the form edits the change (visitors keep the live version)
        $this->changeMemo = false;
        $change = $this->pendingChange($entry);
        $this->changeSeen = null;
        $liveCategories = $this->categoryIds;
        if ($change !== null && ($primary = $this->openPendingChange($change)) !== null && $primary !== $category?->id) {
            $category = $filed?->firstWhere('id', $primary) ?? Taxonomy::query()->find($primary);
        }
        $this->address = $this->previewAddress($entry, $category);
        $this->placeInList($this->type === 'post' ? $this->sectionOf($category) : null);
        // A save that changes no shared field leaves the entry row alone: this language's own row
        // carries when it was last saved.
        $this->savedAt = self::iso($translation?->updated_at);
        // what visitors see, also while the form edits a pending change: the overwrite check compares the website
        $this->storedPrint = self::printOf($translation, $this->coverPrint($entry->cover_media_id, $entry->meta), $entry->meta, $liveCategories, $entry->template_override);
        $this->loadedAt = self::iso(CarbonImmutable::now());
        $this->mayPublish = $this->user()->can('publish', $entry);
        $this->frameRights($entry);
        $this->liveAddress = $this->routedAddress($entry->id);
        if ($this->type === 'page') {
            $this->loadParent($entry, $translation, is_numeric($placeBefore) ? (int) $placeBefore : null);
        }
        // a language not written yet takes its link from its title, as a new entry does (never the Home page);
        // one the pending change holds text for is written
        $written = $translation instanceof EntryTranslation || ($change !== null && is_array($change->content['locales'][$this->locale] ?? null));
        if ($this->pageHome && ! $written) {
            // the Home page's new language is the root too, never a link made from its title — unless another
            // page already holds the root in this language: then this page is an ordinary page here
            $this->pageHome = ! EntryTranslation::query()->where('locale', $this->locale)->where('entry_id', '!=', $entry->id)
                ->whereIn('entry_id', Entry::query()->select('id')->where('type', 'page'))   // a post's 'home' sits under its category, never the root
                ->whereIn('slug', [PathBuilder::HOME_SLUG, ''])->exists();
            $this->slug = $this->pageHome ? PathBuilder::HOME_SLUG : '';
        }
        $this->slugFresh = ! $written && ! $this->pageHome;
        $this->loadSchedule($entry);
        $this->loadDates($entry);
        $this->saved = $this->tracked();
        $this->guideDue = true;
        $this->readLangBar($entry);

        if ($this->governingWorkflow() !== null) {
            $this->loadTrail($entry->id);
        }
    }

    /**
     * The main category set on its own (the earlier one-category picker): the post is filed under it
     * alone, as that picker did; the save judges it (exists, governed section, the member's limits).
     * A post's category is part of its address: the preview follows the pick (posts only).
     */
    public function updatedCategoryId(): void
    {
        if ($this->type === 'post') {
            $this->categoryIds = $this->categoryId !== null ? [$this->categoryId] : [];
            $this->address = $this->previewAddress();
            $this->jobFollowCategory();
        }
    }

    /**
     * A box in the «Chuyên mục» card: file the post under the category too, or take it out. The first
     * category ticked becomes the main one (a section's own category at the front — no category ticked
     * — gives way to it); when the main one is unticked the next one takes its place, and with none
     * left the post goes back to its section's own category. A member limited to some categories adds
     * or takes out only categories inside them, and a category of another section stays as it is while
     * an approval workflow governs this one (the card locks both rows and says why; asked anyway, one
     * outside the limits is a 403, the other's words come back in a toast and nothing changes).
     * Nothing is saved until the post is.
     */
    public function toggleCategory(string $ulid): void
    {
        $this->authorizeEditing();
        abort_unless($this->type === 'post', 404);
        $choices = $this->choices();
        $id = $choices->idOf($ulid);
        abort_unless($id !== null && ! $choices->isSectionRoot($id), 404);
        if ($this->refuseFiling($choices, $id)) {
            return;
        }
        $ids = $this->categoryIds;
        if (in_array($id, $ids, true)) {
            $ids = array_values(array_filter($ids, static fn (int $at): bool => $at !== $id));
        } elseif ($ids !== [] && $choices->isSectionRoot($ids[0])) {
            $ids = [$id, ...array_slice($ids, 1)];
        } else {
            $ids[] = $id;
        }
        $this->categoriesChanged($choices, $ids);
    }

    /** «Chuyên mục chính»: a ticked category moves to the front — the main one now, its address the post's. */
    public function setMainCategory(string $ulid): void
    {
        $this->authorizeEditing();
        abort_unless($this->type === 'post', 404);
        $choices = $this->choices();
        $id = $choices->idOf($ulid);
        abort_unless($id !== null && in_array($id, $this->categoryIds, true), 404);
        if ($id === $this->categoryId || $this->refuseFiling($choices, $id)) {
            return;
        }
        $this->categoriesChanged($choices, [$id, ...array_values(array_filter($this->categoryIds, static fn (int $at): bool => $at !== $id))]);
        $this->dispatch('bs-toast', text: Cast::string(__('entries.ed.cat_main_set')));
    }

    /**
     * «Thêm chuyên mục mới» (who may manage categories): a category named $name, made at once through
     * the category screen's own write (CreateTaxonomy: the name rules, the depth limit, the member's
     * limits) and ticked for this post. It goes at the top of the post's section, or — for a member
     * limited to some categories — under the main category, which must lie inside their limits.
     * Returns whether it was made; a refusal is said on the name field.
     */
    public function addCategory(mixed $name): bool
    {
        $this->authorizeEditing();
        abort_unless($this->type === 'post', 404);
        Gate::authorize('create', Taxonomy::class);
        $this->resetErrorBag('catNew');
        $name = is_string($name) ? PlainText::line(trim($name)) : '';
        if ($name === '' || mb_strlen($name) > TaxonomyRules::NAME_MAX) {
            $this->addError('catNew', Cast::string(__('categories.reason_name_invalid')));

            return false;
        }
        $choices = $this->choices();
        $plan = $this->addPlan($choices);
        if ($plan['lock'] !== null || $plan['parent'] === null) {
            $this->addError('catNew', $plan['lock'] ?? Cast::string(__('categories.reason_parent_invalid')));

            return false;
        }
        try {
            $made = app(CreateTaxonomy::class)->handle('post_cat', Taxonomy::query()->find($plan['parent']),
                new TaxonomyData(names: [app(SiteLocale::class)->default() => $name]));
        } catch (TaxonomyChangeRejectedException $e) {
            $this->addError('catNew', Cast::string(__('categories.reason_'.$e->reason, ['depth' => TaxonomyRules::MAX_DEPTH])));

            return false;
        }
        $ids = $this->categoryIds;
        $ids = $ids !== [] && $choices->isSectionRoot($ids[0]) ? [$made->id, ...array_slice($ids, 1)] : [...$ids, $made->id];
        $this->choicesMemo = null;
        $this->categoriesChanged($this->choices(), $ids);
        $this->dispatch('bs-toast', text: Cast::string(__($plan['in'] !== null ? 'entries.ed.cat_added_in' : 'entries.ed.cat_added',
            ['name' => $name, 'in' => $plan['in'] ?? '', 'unit' => $this->unit()])));

        return true;
    }

    /**
     * Whether filing under $id (ticking, unticking or making it the main one) is refused. A category
     * outside the member's limits is never theirs to change (the card shows only the ticked ones,
     * locked): 403. One of another section while an approval workflow governs this one is refused
     * in a toast that stays, in the words under its locked row.
     */
    private function refuseFiling(PostCategoryChoices $choices, int $id): bool
    {
        abort_unless(app(CategoryScopes::class)->coversId($this->user(), $id), 403);
        if (! $this->homeGoverned() || $choices->sectionOf($id) === $this->sectionKey) {
            return false;
        }
        $this->dispatch('bs-toast', text: Cast::string(__('entries.ed.cat_why_workflow', ['section' => $choices->sectionLabel($this->sectionKey) ?? $this->listLabel, 'unit' => $this->unit()])),
            stay: true, close: Cast::string(__('admin.close')));

        return true;
    }

    /**
     * Take $ids as the post's categories: with none left, its section's own category (when the member
     * may write there); a main category outside a limited member's limits gives way to the first one
     * inside them. The address follows the main category.
     *
     * @param  list<int>  $ids
     */
    private function categoriesChanged(PostCategoryChoices $choices, array $ids): void
    {
        // a section's own category means «no category»: only ever the main one, whatever path the list came by
        $ids = array_values(array_filter(array_values(array_unique($ids)), static fn (int $id, int $at): bool => $at === 0 || ! $choices->isSectionRoot($id), ARRAY_FILTER_USE_BOTH));
        $user = $this->user();
        $scopes = app(CategoryScopes::class);
        $root = $choices->rootOf($this->sectionKey);
        if ($ids === [] && $root !== null && $scopes->coversId($user, $root)) {
            $ids = [$root];
        } elseif ($ids === [] && $root !== null && $this->categoryIds !== []) {
            // the last category taken out and the section's own one outside the member's limits:
            // the change is refused, the list stays as it was
            $this->addError('categoryIds', $scopes->refusal($user));

            return;
        }
        $previous = $this->categoryId;
        // a category the member may lead with: inside their limits and, while an approval workflow
        // governs this section, in this section (the save refuses any other as the main one)
        $free = fn (int $id): bool => $scopes->coversId($user, $id) && (! $this->homeGoverned() || $choices->sectionOf($id) === $this->sectionKey);
        if ($ids !== [] && ! $scopes->coversId($user, $ids[0]) || $ids !== [] && $ids[0] !== $previous && ! $free($ids[0])) {
            $lead = null;
            foreach ($ids as $at => $id) {
                if ($free($id)) {
                    $lead = $at;
                    break;
                }
            }
            if ($lead !== null) {
                $id = $ids[$lead];
                array_splice($ids, $lead, 1);
                array_unshift($ids, $id);
            } elseif ($root !== null && $free($root)) {
                // none left to lead: the post goes back to its section's own category, the rest kept
                array_unshift($ids, $root);
            } else {
                // nothing the member may lead with: the change is refused, the list stays as it was
                $this->addError('categoryIds', $scopes->coversId($user, $ids[0])
                    ? Cast::string(__('entries.ed.cat_why_workflow', ['section' => $choices->sectionLabel($this->sectionKey) ?? $this->listLabel, 'unit' => $this->unit()]))
                    : $scopes->refusal($user));

                return;
            }
        }
        $this->categoryIds = $ids;
        $this->categoryId = $ids[0] ?? null;
        $this->resetErrorBag(['categoryId', 'categoryIds', 'categoryIds.*']);
        if ($previous !== $this->categoryId) {
            $this->address = $this->previewAddress();
            $this->jobFollowCategory();
        }
    }

    /**
     * The categories kept on this device put back: those that are still post categories of this
     * website; for a limited member only those inside their limits or the post already has.
     *
     * @param  array<array-key, mixed>  $kept
     */
    private function restoreCategories(array $kept): void
    {
        $choices = $this->choices();
        $user = $this->user();
        $scopes = app(CategoryScopes::class);
        $governed = $this->homeGoverned();
        $home = $choices->rootOf($this->sectionKey);
        $ids = [];
        foreach ($kept as $value) {
            $id = self::idOrNull($value);
            $mine = $id !== null && in_array($id, $this->categoryIds, true);
            if ($id === null || ! $choices->has($id)
                // a section's own category is no row: only this section's, as «no category ticked»
                || ($choices->isSectionRoot($id) && $id !== $home)
                || ! ($scopes->coversId($user, $id) || $mine)
                // another section's category stays as it is while a workflow governs this one
                || ($governed && $choices->sectionOf($id) !== $this->sectionKey && ! $mine)) {
                continue;
            }
            $ids[] = $id;
        }
        if ($governed) {
            // and one the post is under is not dropped either
            foreach ($this->categoryIds as $id) {
                if ($choices->sectionOf($id) !== $this->sectionKey && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }
        if ($ids !== []) {
            $this->categoriesChanged($choices, $ids);
        }
    }

    /**
     * A new post's category: its section's own one, or — for a member limited to some categories who
     * may not write there — the first category of the section inside their limits (none: the
     * section's own one, which the save then refuses in words).
     */
    private function defaultCategory(ContentSection $section): int
    {
        $root = (int) $section->taxonomy_id;
        $user = $this->user();
        $scopes = app(CategoryScopes::class);
        if ($scopes->coversId($user, $root)) {
            return $root;
        }

        return $this->choices()->firstIn($section->key, static fn (int $id): bool => $scopes->coversId($user, $id)) ?? $root;
    }

    /**
     * A post's categories as stored, the main one first (facet terms left out).
     *
     * @return list<int>
     */
    private function storedCategories(Entry $entry): array
    {
        $relation = $entry->taxonomies();

        return array_values(array_map(Cast::int(...), $relation->newPivotQuery()
            ->where('position', '<', Entry::FACET_POSITION_BASE)
            ->orderBy('position')
            ->pluck($relation->getRelatedPivotKeyName())
            ->all()));
    }

    /**
     * The categories to store, decided under the post's row lock before anything is written. A member
     * not limited stores the card's list as it is. For a limited member the list is held against what
     * the post is stored under now (a change made elsewhere since the form opened counts): a category
     * outside their limits is kept only while the post is still under it — one taken out elsewhere is
     * not filed again — and EntryCategoryWriter keeps every stored one outside the limits (they never
     * take out one they cannot see). The main category is never dropped that way (it would move the
     * post's address): the save is refused on its field instead.
     *
     * @return list<int>
     *
     * @throws ValidationException
     */
    private function filedUnder(Entry $entry): array
    {
        $ids = array_values(array_unique($this->categoryIds));
        if ($entry->type !== 'post') {
            return $ids;
        }
        $choices = $this->choices();
        // a section's own category means «no category»: only ever the main one
        $ids = array_values(array_filter($ids, static fn (int $id, int $at): bool => $at === 0 || ! $choices->isSectionRoot($id), ARRAY_FILTER_USE_BOTH));
        $stored = null;
        if ($this->homeGoverned()) {
            // while an approval workflow governs the post's section, its categories in other
            // sections stay exactly as stored (read now, under the save), whatever path the list
            // came by: one the form adds is dropped, one it lost is kept (the main one is held by
            // the rules on categoryId)
            $stored = $this->storedCategories($entry);
            $held = [];
            foreach ($ids as $id) {
                if ($choices->sectionOf($id) === $this->sectionKey || in_array($id, $stored, true)) {
                    $held[] = $id;
                }
            }
            foreach ($stored as $id) {
                if ($choices->sectionOf($id) !== $this->sectionKey && ! in_array($id, $held, true)) {
                    $held[] = $id;
                }
            }
            $ids = $held;
        }
        $user = $this->user();
        $scopes = app(CategoryScopes::class);
        if (! $scopes->isLimited($user)) {
            return $ids;
        }
        $stored ??= $this->storedCategories($entry);
        $form = array_values(array_filter($ids, static fn (int $id): bool => $scopes->coversId($user, $id) || in_array($id, $stored, true)));
        if ($ids !== [] && ($form[0] ?? null) !== $ids[0]) {
            throw ValidationException::withMessages(['categoryId' => $scopes->refusal($user)]);
        }

        return $form;
    }

    /** The post categories of this website, read once per request. */
    private function choices(): PostCategoryChoices
    {
        return $this->choicesMemo ??= PostCategoryChoices::read(app(CurrentSite::class)->id(), $this->locale, app(SiteLocale::class)->default(), app(CategorySections::class));
    }

    /** Whether an approval workflow is switched on for the post's section (the card then locks the other sections' categories). */
    private function homeGoverned(): bool
    {
        $for = ($this->categoryId ?? '').'|'.($this->sectionKey ?? '');
        if ($this->homeGovernedMemo !== null && $this->homeGovernedFor === $for) {
            return $this->homeGovernedMemo;
        }
        $this->homeGovernedFor = $for;
        if ($this->sectionKey === null) {
            return $this->homeGovernedMemo = false;
        }
        // the main category still in the post's section: the workflow the page already read for it
        if ($this->categoryId !== null && $this->choices()->sectionOf($this->categoryId) === $this->sectionKey) {
            return $this->homeGovernedMemo = $this->governingWorkflow() !== null;
        }

        // Otherwise the stored post decides, never the section the post is listed under: a post whose
        // main category no section is built on is listed under the site's first post section
        // (sectionOf), but no workflow of that section governs it. A main category emptied or moved out
        // of a governed section by the form (an older copy kept on the device, the one-category field)
        // leaves the post governed until a save, which keepsGovernedSection refuses.
        return $this->homeGovernedMemo = $this->ulid !== null
            && app(EntryWorkflowResolver::class)->forEntry($this->resolveOrFail($this->ulid)) !== null;
    }

    /**
     * Where «Thêm chuyên mục mới» makes the category: at the top of the post's section, or for a
     * limited member under the main category (which must be in this section and inside their limits,
     * and leave room for one more level). `lock` says why it cannot, `in` names the parent when it is
     * not the section's own category.
     *
     * @return array{parent: int|null, lock: string|null, in: string|null}
     */
    private function addPlan(PostCategoryChoices $choices): array
    {
        $root = $choices->rootOf($this->sectionKey);
        $user = $this->user();
        $scopes = app(CategoryScopes::class);
        if (! $scopes->isLimited($user)) {
            return ['parent' => $root, 'lock' => null, 'in' => null];
        }
        $main = $this->categoryId;
        if ($main === null || $choices->sectionOf($main) !== $this->sectionKey || ! $scopes->coversId($user, $main)) {
            return ['parent' => null, 'lock' => Cast::string(__('entries.ed.cat_add_lock_scope')), 'in' => null];
        }
        if ($choices->level($main) + 1 > TaxonomyRules::MAX_DEPTH) {
            return ['parent' => null, 'lock' => Cast::string(__('entries.ed.cat_add_lock_depth', ['depth' => TaxonomyRules::MAX_DEPTH])), 'in' => null];
        }

        return ['parent' => $main, 'lock' => null, 'in' => $main === $root ? null : $choices->name($main)];
    }

    /**
     * What the «Chuyên mục» card draws (posts only): its rows, the limits note, the «Chuyên mục chính»
     * choices (two or more ticked), «Thêm chuyên mục mới» (who may manage categories) with where it
     * makes the category or why it cannot, and the line naming the main category and the address.
     *
     * @return array<string, mixed>|null
     */
    private function categoryCard(): ?array
    {
        if ($this->type !== 'post') {
            return null;
        }
        $choices = $this->choices();
        $user = $this->user();
        $scopes = app(CategoryScopes::class);
        $limited = $scopes->isLimited($user);
        // the same answer the save gives (CategoryScopes::coversId), category by category
        $inside = $limited ? static fn (int $id): bool => $scopes->coversId($user, $id) : null;
        $rows = $choices->rows($this->sectionKey, $this->categoryIds, $inside, $this->homeGoverned(), $this->unit());
        // «Chuyên mục chính» offers the ticked rows in the post's order, the main one first
        $byUlid = array_column($rows, null, 'ulid');
        $primary = [];
        foreach ($this->categoryIds as $id) {
            $row = $byUlid[$choices->ulidOf($id) ?? ''] ?? null;
            if ($row !== null && $row['on']) {
                $primary[] = ['ulid' => $row['ulid'], 'label' => $row['label'].($row['section'] !== null ? ' ('.$row['section'].')' : ''),
                    'main' => $row['main'], 'locked' => $row['locked'] && ! $row['main']];
            }
        }
        $main = $this->categoryId;
        $mainName = $main !== null && ! $choices->isSectionRoot($main) ? $choices->name($main) : null;
        $link = preg_replace('#^https?://#', '', $this->address['base'].$this->slug) ?? '';

        return [
            'rows' => $rows,
            'scopeNote' => $limited ? $scopes->refusal($user) : null,
            'primary' => count($primary) >= 2 ? $primary : [],
            'add' => $user->can('create', Taxonomy::class) ? $this->addPlan($choices) : null,
            'note' => $mainName !== null
                ? Cast::string(__('entries.ed.cat_note_main', ['name' => $mainName, 'link' => $link]))
                : Cast::string(__('entries.ed.cat_note_none', ['unit' => Str::ucfirst($this->unit()), 'section' => $choices->sectionLabel($this->sectionKey) ?? $this->listLabel])),
        ];
    }

    /**
     * The link follows the title (board 07) while this language's version has never been saved, this form
     * has not saved and its link was not typed: each change of title makes it again, free on this website.
     * The Home page's link never changes.
     */
    public function updatedTitle(): void
    {
        if (! $this->slugFresh || $this->slugTouched || $this->pageHome) {
            return;
        }
        $this->authorizeEditing();
        $this->slug = '';
        $this->fillBlankSlug();
    }

    /**
     * The link, as the field is left. The Home page keeps its own: it is the website's root «/». An
     * emptied link is made again from the title, and said; a typed one stops following the title.
     */
    public function updatedSlug(): void
    {
        $this->authorizeEditing();
        if ($this->pageHome) {
            $this->slug = $this->saved['slug'] ?? '';

            return;
        }
        if (trim($this->slug) !== '') {
            $this->slugTouched = true;

            return;
        }
        $this->slugTouched = false;
        $this->resetErrorBag('slug');
        if ($this->fillBlankSlug()) {
            $this->dispatch('bs-toast', text: Cast::string(__('entries.ed.slug_auto', ['slug' => $this->slug])));
        } elseif (trim($this->title) !== '') {
            // the title itself cannot give a link: the person types one, and is told why it is blank
            $this->addError('slug', __('entries.slug_untranslatable'));
        }
    }

    /**
     * «Hẹn giờ đăng», as the field is left: an empty time is refused, and a time changed from the one the
     * entry was scheduled for must be after now; the reason stays under the field until it is put right.
     */
    public function updatedScheduleAt(): void
    {
        $this->authorizeEditing();
        $this->resetErrorBag('scheduleAt');
        if (! $this->schedulable()) {
            $this->scheduleAt = $this->scheduleLoaded;

            return;
        }
        $why = $this->scheduleError();
        if ($why !== null) {
            $this->addError('scheduleAt', $why);
        }
    }

    /**
     * A status picked in «Khi lưu, bài sẽ là»: only one the list offers this member, unlocked; anything else
     * is put back, the lock's reason said. «Hẹn giờ đăng» starts on tomorrow 08:00 in the website's clock
     * unless the field already holds a time. The saved status picked again clears the choice.
     */
    public function updatedStatusTo(): void
    {
        $this->authorizeEditing();
        $this->resetErrorBag(['statusTo', 'scheduleAt']);
        $pick = $this->statusTo;
        $this->statusTo = '';
        $option = null;
        foreach ($this->statusOptions() ?? [] as $candidate) {
            if ($candidate['key'] === $pick) {
                $option = $candidate;
            }
        }
        if ($option === null) {
            return;
        }
        if ($option['why'] !== '') {
            $this->dispatch('bs-toast', text: $option['why'], stay: true, close: Cast::string(__('admin.close')));

            return;
        }
        if ($pick === ($this->status ?? EntryStatus::Draft->value)) {
            // back to the saved status: a scheduled entry's field goes back to its saved time
            $this->scheduleAt = $this->scheduleLoaded;

            return;
        }
        if ($pick === EntryStatus::Scheduled->value && $this->status === EntryStatus::Published->value && $this->textChanges() > 0) {
            // the save would put the edits on the website, then take it off until the time: update or undo them first
            $this->dispatch('bs-toast', text: Cast::string(__('entries.ed.sched_live_dirty', ['unit' => $this->unit()])), stay: true, close: Cast::string(__('admin.close')));

            return;
        }
        $this->statusTo = $pick;
        if ($pick === EntryStatus::Scheduled->value && trim($this->scheduleAt) === '') {
            $this->scheduleAt = ScheduleInput::tomorrowEight();
        }
        if ($pick !== EntryStatus::Scheduled->value) {
            $this->scheduleAt = $this->scheduleLoaded;
        }
    }

    /**
     * «Hủy hẹn giờ» on the band of a scheduled entry (asked first in the browser): it goes back to a draft
     * and will not go live at its time (TransitionEntryStatus::unschedule, the publish right on the locked
     * row). What is typed in the form stays, unsaved.
     */
    public function unschedule(): void
    {
        $this->authorizeEditing();
        if ($this->ulid === null || $this->status !== EntryStatus::Scheduled->value) {
            return;
        }
        $entry = $this->resolveOrFail($this->ulid);
        try {
            $moved = app(TransitionEntryStatus::class)->unschedule($entry, $this->user());
        } catch (AuthorizationException) {
            $this->dispatch('bs-toast', text: EntryRefusal::for($this->user(), $entry, 'status'), stay: true, close: Cast::string(__('admin.close')));

            return;
        }
        $entry->refresh();
        $this->takeStatus($entry);
        if (! $moved) {
            // published or moved meanwhile: left as it is, and the form now says so
            $this->dispatch('bs-toast', text: Cast::string(__('entries.ed.unsched_gone', ['unit' => $this->unit()])), stay: true, close: Cast::string(__('admin.close')));

            return;
        }
        $this->dispatch('bs-toast', text: Cast::string(__('entries.unsched_done', ['noun' => $this->unit(), 'step' => app(ApproveEntryForLater::class)->stepAfterCancel($entry)])));
        // the band and its button are gone: the cursor goes to the title, which can be edited
        $this->js("document.getElementById('entry-title')?.focus()");
    }

    /**
     * A new entry or a draft saves itself 2 seconds after the last change (the browser asks): through the
     * same rights, checks and stale-save question as «Lưu nháp», never a status move. Content that is not a
     * draft never does (live text stays on this device until «Cập nhật»), nor a form with no text changed, nor
     * a new entry with no title, short description or body yet. A field still to fix is left as it is, the
     * messages already shown kept.
     */
    public function autosave(): void
    {
        $this->authorizeEditing();
        $this->saveItself(true);
    }

    /**
     * The draft's own save (see autosave). `$quiet`: a field still to fix leaves what is shown as it is; run
     * again from «Giữ bản của tôi» (not quiet) the reasons show, as a member's own press expects.
     */
    private function saveItself(bool $quiet): void
    {
        if ($this->stale !== null || ($this->ulid !== null && $this->status !== EntryStatus::Draft->value)) {
            return;
        }
        if ($this->textChanges() === 0 || ($this->ulid === null && ! $this->worthKeeping())) {
            return;
        }
        if ($this->ulid === null && ! Gate::allows('fileUnder', [Entry::class, $this->type, $this->type === 'post' ? $this->categoryId : null])) {
            return;   // the member's own save says why; a save by itself does not
        }

        $bag = $this->getErrorBag();
        $shown = new MessageBag($bag instanceof MessageBag ? $bag->getMessages() : []);
        $failed = $this->saveFailed;
        $wasNew = $this->ulid === null;
        $fresh = $this->slugFresh;
        try {
            $entry = $this->persist('autosave');
        } catch (ValidationException $refused) {
            if (! $quiet) {
                throw $refused;
            }
            $this->setErrorBag($shown);
            $this->saveFailed = $failed;

            return;
        }
        if (! $entry instanceof Entry) {
            return;
        }
        // a draft that saved itself keeps its link following the title, as before it was saved
        $this->slugFresh = $fresh && ! $this->slugTouched;
        $this->autoSaved = true;
        if ($wasNew) {
            $this->dispatch('bs-toast', text: Cast::string(__('entries.ed.toast_auto_first', ['unit' => $this->unit()])));
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

    /**
     * The language bar's items for a saved entry, when the site writes in more than one language:
     * each version's state from one read of its stored rows (EntryLangStates), each opening this
     * editor in its language (the default language without `?locale=`).
     */
    private function readLangBar(Entry $entry): void
    {
        if (! $this->multiLocale) {
            $this->langBar = [];

            return;
        }
        $languages = app(SiteLocale::class);
        $default = $languages->default();
        $this->langBar = LangBar::items(
            EntryLangStates::read($entry->id),
            $languages->enabled(),
            $default,
            $languages->public(),
            $this->locale,
            EntryLangStates::FIELDS,
            static fn (string $code): string => route('admin.entries.edit', ['ulid' => $entry->ulid] + ($code !== $default ? ['locale' => $code] : [])),
        );
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

    /** «Xóa khối» on a block's row in «Trình dựng» (asked first in the browser): the block leaves this language's layout, unsaved. */
    public function removeBlock(int $index): void
    {
        $this->authorizeEditing();
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
        $this->dispatch('bs-toast', text: Cast::string(__('entries.ed.block_removed')));
        // No block left, or only one formatted text: the body is written text again, in «Soạn thảo».
        if ($blocks === [] || (count($blocks) === 1 && $blocks[0]['type'] === EntryProseBody::TYPE)) {
            /** @var array<string, mixed> $props */
            $props = $blocks === [] ? [] : $blocks[0]['props'];
            $html = $props['html'] ?? '';
            $this->prose = true;
            $this->blocks = [];
            $this->openBlock = null;
            $this->bodyHtml = is_string($html) ? $html : '';
            $this->proseLook = $props !== [] ? EntryProseBody::look($props) : [];
            $this->resetErrorBag('blocks');
            // the writing area was not on the page: the browser starts it now
            $this->js("document.dispatchEvent(new Event('bs-rich-editors-remount'))");
        }
    }

    /**
     * «Xóa» on the written body's row in «Trình dựng» (asked first in the browser): all of this language's
     * text goes, unsaved; the writing area shows it empty.
     */
    public function clearProse(): void
    {
        $this->authorizeEditing();
        if (! $this->prose) {
            return;
        }
        $this->bodyHtml = '';
        $this->resetErrorBag(['bodyHtml', 'blocks']);
        $this->dispatch('admin-editor-set-html', target: 'bodyHtml', html: '');
        $this->dispatch('bs-toast', text: Cast::string(__('entries.ed.block_removed')));
    }

    /**
     * A block list sent whole keeps positions as its keys; one sent while the body is written makes
     * it a layout. A typed link anchor is settled as «Trình dựng» does everywhere (settleAnchor).
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

        $this->settleAnchor($value, $key);
    }

    /**
     * "Lưu nháp" / "Lưu" / "Cập nhật" on content that is not live: save, then move the entry to the status
     * picked in «Khi lưu, bài sẽ là» (none picked: the status kept). «Công khai» and «Gửi duyệt» are the
     * publish button's own move (PublishEntry); a draft, a schedule and «Gỡ khỏi website» go through
     * TransitionEntryStatus. A picked «Hẹn giờ đăng» with an empty or past time stops the save first.
     */
    public function save(): void
    {
        $to = $this->statusChoice();
        if ($to === EntryStatus::Scheduled->value && $this->status === EntryStatus::Published->value && $this->textChanges() > 0) {
            // edited after «Hẹn giờ đăng» was picked: the save would put the edits on the website and then take it
            // off until the time; the pick is dropped, so the next save updates the website as it is
            $this->forgetChoice();
            $this->dispatch('bs-toast', text: Cast::string(__('entries.ed.sched_live_dirty', ['unit' => $this->unit()])), stay: true, close: Cast::string(__('admin.close')));

            return;
        }
        if ($to === EntryStatus::Published->value || $to === EntryStatus::Pending->value) {
            $this->publish();

            return;
        }
        $schedulable = $this->schedulable();
        if ($schedulable && $this->refuseSchedule()) {
            return;
        }
        $entry = $this->persist('save');
        if (! $entry instanceof Entry) {
            return;
        }
        $this->autoSaved = false;
        if ($this->saveFailed) {
            // made, but a part was refused (a new page's typed place): the form says so, never a success
            return;
        }
        // «Gỡ khỏi website lúc» first; one before a schedule that this save moves earlier, or takes back to a
        // draft, waits for the move
        $expiryWaits = false;
        if (! $this->applyExpiry($entry, $schedulable || $to === EntryStatus::Scheduled->value, $expiryWaits, $to)) {
            return;
        }
        if ($this->heldSave !== null && $to === null) {
            // into the pending change: visitors keep the live version
            $this->dispatch('bs-toast', text: $this->heldToast($this->governingWorkflow()));

            return;
        }
        if ($to !== null) {
            if ($this->moveStatus($entry, $to) && (! $expiryWaits || $this->applyExpiry($entry->refresh()))) {
                $at = $to === EntryStatus::Scheduled->value ? ScheduleInput::parse($this->scheduleLoaded) : null;
                $at = $at !== null ? app(SiteTime::class)->forDisplay($at) : null;
                $this->dispatch('bs-toast', text: match (true) {
                    $at !== null => __('entries.scheduled_set_at', ['date' => $at->format('d/m/Y'), 'time' => $at->format('H:i')]),
                    $this->folded => __('entries.chg.t_folded', ['noun' => $this->changeNoun()]),
                    default => __('entries.ed.toast_kept', ['status' => $this->statusPill()['label']]),
                });
            }

            return;
        }
        if ($schedulable && ! $this->moveSchedule($entry)) {
            return;
        }
        if ($expiryWaits && ! $this->applyExpiry($entry->refresh())) {
            return;
        }

        $time = app(SiteTime::class)->forDisplay(CarbonImmutable::now())?->format('H:i') ?? '';
        $this->dispatch('bs-toast', text: $this->status === 'draft'
            ? __('entries.ed.toast_draft', ['time' => $time])
            : __('entries.ed.toast_kept', ['status' => $this->statusPill()['label']]));
    }

    /** "Đăng" / "Cập nhật" (live content) / "Gửi duyệt" (no right to publish): save, then publish or send. */
    public function publish(): void
    {
        // on live content this is «Cập nhật» (or «Gửi duyệt» without the right to publish): a held save refused names that move
        $this->changeAct = $this->mayPublish ? 'apply' : 'submit';
        $entry = $this->persist('publish');
        if (! $entry instanceof Entry || $this->saveFailed) {
            // nothing put on the website after a save refused in part (a new page's typed place)
            return;
        }
        // a cleared or moved end time comes before the publish, which refuses one already past; one before the
        // schedule of a scheduled post waits for the publish, which puts it up now (PublishEntry)
        $expiryWaits = false;
        if (! $this->applyExpiry($entry, $entry->status === EntryStatus::Scheduled->value, $expiryWaits)) {
            return;
        }
        if ($this->heldSave !== null) {
            // into the pending change: visitors keep the live version
            $this->dispatch('bs-toast', text: $this->heldToast($this->governingWorkflow()));

            return;
        }
        $was = $this->status;

        try {
            $this->status = app(PublishEntry::class)->handle($entry, $this->locale, $this->user());
            // live now: the end time that waited is written; a refusal is said under its field, the input kept
            $expiryRefused = $expiryWaits && ! $this->applyExpiry($entry->refresh());
            $typed = $this->expiresAt;
            $this->loadDates($entry);
            if ($expiryRefused) {
                $this->expiresAt = $typed;
            }
            $this->autoSaved = false;
            $this->forgetChoice();
            $this->liveAddress = $this->routedAddress($entry->id);
            $unit = $this->unit();
            $this->dispatch('bs-toast', text: match (true) {
                $this->status === 'published' && $was !== 'published' => __('entries.ed.toast_published', ['unit' => $unit]),
                $this->status === 'published' => __('entries.ed.toast_updated'),
                $this->status === 'pending' => __('entries.ed.toast_sent', ['unit' => $unit]),
                default => __('entries.ed.toast_kept', ['status' => $this->statusPill()['label']]),
            });
        } catch (RouteConflictException $e) {
            $this->addressTaken($e, $entry);
        } catch (ExpiredContentException $refused) {
            // past its end time: saved, not put back up; the end time is moved or cleared first
            $this->addError('workflow', $refused->getMessage());
        } catch (WorkflowGovernedException) {
            // A tab that still shows the tier-1 button after the flow was switched on: the
            // draft is saved, and the next render swaps in the approval block.
            $this->addError('workflow', __('entries.workflow_governed'));
        }
    }

    /**
     * A publication refused because an address it would write is taken: the page's own (under its link
     * field), or one of its sub-pages' that it moves along (the page's link is free, so the sentence
     * names the sub-page's address instead, above the form).
     */
    private function addressTaken(RouteConflictException $e, Entry $entry): void
    {
        if ($e->subPageOf($entry->id)) {
            $this->addError('workflow', __('entries.subpage_path_taken', ['path' => $e->path]));

            return;
        }
        $this->addError('slug', __($e->reserved() ? 'entries.slug_reserved' : 'entries.slug_conflict'));
    }

    /**
     * Move the post along its workflow (design screen 2 action bar). Persists first, exactly
     * like publish(), so an unsaved post can be submitted in one move. The engine is the only
     * judge of the move — the pre-checks below just put the failure on the right field.
     */
    public function applyTransition(string $key): void
    {
        $this->resetErrorBag(['workflow', 'transitionNote', 'assignTo']);

        // the save this move starts with is what a held change refuses (nothing moved): «Chưa lưu được thay đổi»
        $this->changeAct = 'save';
        $entry = $this->persist('transition', $key);
        if (! $entry instanceof Entry || ! $this->applyExpiry($entry)) {
            return;
        }
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
        } catch (RouteConflictException $e) {
            $this->addressTaken($e, $entry);

            return;
        } catch (InvalidTransitionException) {
            // Another tab moved the post first, or the flow was reconfigured under this one.
            $this->addError('workflow', __('admin.wfb.err_stale'));

            return;
        } catch (ExpiredContentException $refused) {
            $this->addError('workflow', $refused->getMessage());   // a step that publishes, past the end time

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
        $folded = $this->changeFolded($entry);

        $stepLabel = $workflow->states()->where('key', $destination)->value('label');

        $this->dispatch('workflow-moved');   // closes the note modal only on success
        $this->dispatch('bs-toast', text: Cast::string(__('admin.wfb.moved', [
            'action' => $transition->label,
            'step' => is_string($stepLabel) ? $stepLabel : $destination,
        ])).($folded ? ' '.Cast::string(__('entries.chg.t_folded', ['noun' => $this->changeNoun()])) : ''));
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
        // a post approved for later offers no move here: it is locked until «Hủy hẹn giờ» (the band above)
        $moves = $entry?->approvedForLater() === true ? [] : app(WorkflowEngine::class)->allowedTransitions($workflow, $fromState, $user);
        foreach ($moves as $transition) {
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
        if ($this->coverUlid === '') {
            $missing[] = __('admin.wfb.missing_cover');
        }
        if ($this->prose ? EntryProseBody::isBlank($this->bodyHtml) : $this->blocks === []) {
            $missing[] = __('admin.wfb.missing_body');
        }

        return $missing;
    }

    /**
     * «Chọn ảnh» / «Đổi ảnh» on the cover card: the shared «Chọn ảnh» dialog, one picture. The cover is
     * every language's, so it is changed in the website's default language only (design 07 sharedGuard):
     * another language says where to change it and opens nothing.
     */
    public function openCoverPicker(): void
    {
        $this->authorizeEditing();
        abort_unless(Gate::allows('media.view'), 403);
        if ($this->coverRefused()) {
            return;
        }
        $this->blockImagePickerPath = '';   // the pick is the cover's, not a block's
        $this->openImagePicker('single');
    }

    /**
     * Pictures dropped on the empty cover box («Kéo thả ảnh vào đây», design 07): the cover's «Chọn ảnh»
     * opens on its device upload, which takes the dropped files the browser kept for it
     * (window.__pendingPickerFiles, as the picker's own drop area does). Once they are in the library
     * the member picks the cover there, as after any upload. Only for who may add files.
     */
    public function dropCover(): bool
    {
        $this->authorizeEditing();
        abort_unless(Gate::allows('media.view') && Gate::allows('media.upload'), 403);
        if ($this->coverRefused()) {
            return false;
        }
        $this->blockImagePickerPath = '';
        $this->openImagePicker('single');
        $this->setPickerTab('upload');
        $this->openPickerSource('device');

        return true;
    }

    /**
     * «Bỏ ảnh»: the cover leaves the form (saved with «Lưu»), with «Hoàn tác» that puts the picture
     * and this language's words back as they were.
     */
    public function clearCover(): void
    {
        $this->authorizeEditing();
        abort_unless(Gate::allows('media.view'), 403);
        if ($this->coverRefused() || $this->coverUlid === '') {
            return;
        }
        $undo = ['ulid' => $this->coverUlid, 'alt' => $this->coverAlt];
        $this->coverUlid = '';
        $this->coverTouched = true;
        // the words described the picture taken off: a new picture is described afresh
        $this->coverAlt = '';
        $this->sayCover(Cast::string(__('entries.ed.cover_removed')), $undo);
        // the button that held focus is gone: focus moves on to the empty card's «Chọn ảnh»
        $this->js("document.querySelector('[data-cover-pick]')?.focus()");
    }

    /** «Hoàn tác» on «Đã gỡ ảnh đại diện»: the picture comes back if it is still in this site's library. */
    public function undoCoverRemoval(): void
    {
        $this->authorizeEditing();
        abort_unless(Gate::allows('media.view'), 403);
        $undo = $this->coverUndo;
        $this->coverToast = '';
        $this->coverUndo = null;
        // (a message shown before covers were named by their row holds the picture's key)
        $row = $undo === null || $this->coverRefused() ? null : $this->libraryImage($undo['ulid'] ?? null, $undo['key'] ?? null);
        if ($row === null) {
            return;
        }
        $this->coverUlid = $row->ulid;
        $this->coverTouched = true;
        $this->coverAlt = $undo['alt'];
    }

    /** Close the cover message the member (or its timer) names; a newer message stays. */
    public function closeCoverToast(int $seq): void
    {
        $this->authorizeEditing();
        if ($seq === $this->coverToastSeq) {
            $this->coverToast = '';
            $this->coverUndo = null;
        }
    }

    /**
     * The picker's one picture: a block image field's when one opened it, else the cover. The picture
     * comes from this site's live library (the picker re-resolves it); a file that is not a picture is
     * not taken.
     */
    protected function pickedSingleImage(Media $media): void
    {
        if ($this->blockImagePickerPath !== '') {
            $this->placeBlockImage($media);

            return;
        }
        $this->authorizeEditing();
        abort_unless(Gate::allows('media.view'), 403);
        if ($this->coverRefused() || ! str_starts_with($media->mime, 'image/')) {
            return;
        }
        if (strcasecmp($media->ulid, $this->coverUlid) !== 0) {
            // the words described the picture taken off: a new picture is described afresh
            $this->coverAlt = '';
        }
        $this->coverUlid = $media->ulid;
        $this->coverRow = $media;
        $this->coverTouched = true;
        $this->sayCover(Cast::string(__('entries.ed.cover_picked')), null);
    }

    /**
     * The cover's footer words in «Chọn ảnh» (design 07: «Dùng ảnh này»); a block image keeps the picker's.
     *
     * @return array{insert: string, note: string}|null
     */
    protected function singlePickWords(): ?array
    {
        return $this->blockImagePickerPath !== '' ? null : [
            'insert' => Cast::string(__('media.picker_insert_one')),
            'note' => Cast::string(__('media.picker_note_cover')),
        ];
    }

    /** In another language than the website's default the shared cover is not changed: say where it is. */
    private function coverRefused(): bool
    {
        $default = app(SiteLocale::class)->default();
        if ($this->locale === $default) {
            return false;
        }
        $this->dispatch('bs-toast', text: Cast::string(__('entries.ed.shared_guard', ['lang' => Locales::label($default)])), stay: true, close: Cast::string(__('admin.close')));

        return true;
    }

    /**
     * @param  array{ulid: string, alt: string}|null  $undo
     */
    private function sayCover(string $message, ?array $undo): void
    {
        $this->coverToast = $message;
        $this->coverToastSeq++;
        $this->coverUndo = $undo;
    }

    /**
     * The cover as the card shows it: its address, its file name (for the picture's name), and how its
     * shape suits the places it is shown (design 07: «Ngang 16:9, hợp mọi chỗ» or the warning), from
     * the library's width and height; null when there is no cover.
     *
     * @return array{url: string, name: string, ratio: 'ok'|'warn'|null}|null
     */
    private function coverCard(MediaUrlResolver $urls): ?array
    {
        $media = $this->coverMedia();
        if ($media === null) {
            return null;
        }
        $w = $media->width;
        $h = $media->height;
        $ratio = is_int($w) && is_int($h) && $w > 0 && $h > 0
            ? (abs($w / $h - 16 / 9) <= 16 / 9 * 0.02 ? 'ok' : 'warn')
            : null;

        return [
            'url' => $urls->url($media->storage_key, 640),
            'name' => is_string($media->original_filename) && $media->original_filename !== '' ? $media->original_filename : '',
            'ratio' => $ratio,
        ];
    }

    /**
     * The library row of the form's cover (this site's, outside the Trash), read once per request;
     * null without one or when it has left the library since.
     */
    private function coverMedia(): ?Media
    {
        if ($this->coverUlid === '') {
            return null;
        }
        if ($this->coverRow === null || strcasecmp($this->coverRow->ulid, $this->coverUlid) !== 0) {
            $this->coverRow = MediaRefs::rows([], [$this->coverUlid], [], ['original_filename'])->byUlid($this->coverUlid);
        }

        return $this->coverRow;
    }

    /** The storage key of the form's cover, '' without one (the history and the stale question name it by file). */
    private function coverKey(): string
    {
        return $this->coverMedia()->storage_key ?? '';
    }

    /**
     * The form takes a stored cover (an entry's or a pending change's shared fields) as EntryCover reads
     * it: the picture cover_media_id names, else the old key in meta; its library row's ulid, or ''
     * when it is no picture of the library (none, a glyph, an outside address, a file in the Trash).
     * One read, only when the cover names a file.
     */
    private function loadCover(mixed $coverMediaId, mixed $meta): void
    {
        $refs = MediaRefs::rows([$coverMediaId], [], [EntryCover::metaValue($meta)], ['original_filename']);
        $this->coverRow = $refs->byKey(EntryCover::resolve($coverMediaId, $meta, $refs));
        $this->coverUlid = $this->coverRow->ulid ?? '';
        $this->coverTouched = false;
    }

    /**
     * A picture of this site's library outside the Trash, named by its ulid (checked for its shape)
     * or, from a form or message made before covers were named by their row, by its storage key.
     */
    private function libraryImage(mixed $ulid, mixed $key = null): ?Media
    {
        $query = Media::query()->where('mime', 'like', 'image/%');
        if (is_string($ulid)) {
            if (preg_match(self::ULID_SHAPE, $ulid) !== 1) {
                return null;
            }
            $query->where('ulid', strtoupper($ulid));
        } elseif (is_string($key) && MediaRefs::looksLikeKey($key)) {
            $query->where('storage_key', $key);
        } else {
            return null;
        }

        return $query->first(['id', 'ulid', 'storage_key', 'original_filename', 'width', 'height']);
    }

    /**
     * A form opened before covers were named by their row holds its cover as a storage key
     * (coverImageKey): taken once, on its first request after, as the row's ulid. It counts as changed
     * when it is not the key the form read (its stored print), so a save keeps a cover it never touched.
     */
    public function hydrate(): void
    {
        if ($this->coverById) {
            return;
        }
        $this->coverById = true;
        $key = $this->coverImageKey;
        $this->coverImageKey = '';
        $row = $this->libraryImage(null, $key);
        $this->coverRow = $row;
        $this->coverUlid = $row->ulid ?? '';
        $this->coverTouched = md5($key) !== ($this->storedPrint['cover'] ?? md5(''));
        if (! $this->coverTouched) {
            $this->saved['coverUlid'] = $this->coverUlid;
            unset($this->saved['coverImageKey']);
        }
    }

    /**
     * The cover this save stores over the stored pair ($id, $meta): the picture the form names, by its
     * id, the old meta.image_url dropped; none (no id, no meta.image_url); or, when the form left the
     * cover as it read it and nobody else changed it since ($storedSame), the stored pair as it is (a
     * glyph, an outside address, a file in the Trash), with only an old key of the same picture moved
     * to its id. The picture is read FOR KEY SHARE in the save's transaction, so a purge of the file
     * waits for it. Null when the form names a picture that is no longer one of this site's library
     * (another site's, or gone since): said in a message that stays, the card emptied, nothing written.
     *
     * @param  array<string, mixed>  $meta
     * @return array{0: int|null, 1: array<string, mixed>}|null
     */
    private function coverToStore(mixed $id, array $meta, bool $storedSame): ?array
    {
        $keep = ! $this->coverTouched && $storedSame;
        if ($keep && EntryCover::named($id)) {
            unset($meta['image_url']); // a set id decides alone: no old key is kept beside it
        }
        if ($this->coverUlid === '') {
            if ($keep) {
                return [MediaRefs::id($id), $meta];
            }
            unset($meta['image_url']);

            return [null, $meta];
        }
        $row = preg_match(self::ULID_SHAPE, $this->coverUlid) === 1
            ? Media::query()->where('ulid', strtoupper($this->coverUlid))->where('mime', 'like', 'image/%')->lock('for key share')->first(['id'])
            : null;
        if ($row === null) {
            if ($keep) {
                return [MediaRefs::id($id), $meta];
            }
            $this->coverUlid = '';
            $this->coverRow = null;
            $this->dispatch('bs-toast', text: Cast::string(__('entries.ed.cover_gone')), stay: true, close: Cast::string(__('admin.close')));

            return null;
        }
        unset($meta['image_url']);

        return [$row->id, $meta];
    }

    /**
     * The cover kept on this device put back: in the website's default language only (it is every
     * language's) and only a picture still in this site's library — the kept copy is the browser's word.
     */
    private function restoreKeptCover(mixed $ulid, mixed $key): void
    {
        // a copy kept before covers were named by their row holds the picture's key instead
        $value = is_string($ulid) ? $ulid : $key;
        if (! is_string($value) || $this->locale !== app(SiteLocale::class)->default() || ! Gate::allows('media.view')) {
            return;
        }
        if ($value === '') {
            if ($this->coverUlid !== '') {
                $this->coverUlid = '';
                $this->coverTouched = true;
            }

            return;
        }
        $row = $this->libraryImage(is_string($ulid) ? $ulid : null, $key);
        if ($row !== null && strcasecmp($row->ulid, $this->coverUlid) !== 0) {
            $this->coverUlid = $row->ulid;
            $this->coverRow = $row;
            $this->coverTouched = true;
        }
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

    public function render(): View
    {
        $urls = app(MediaUrlResolver::class);
        $schemas = $this->schemas();
        $workflow = $this->governingWorkflow();
        // the saved row, read once for the workflow panel and the schedule band (a governed entry only)
        $row = $workflow !== null && $this->ulid !== null ? $this->resolveOrFail($this->ulid) : null;
        // the same row for the pending change's band and buttons; none once it went to the Trash in another tab.
        // A member who may publish a post no workflow governs, with no change open when the form was read,
        // writes live: nothing to read (a change opened since in another tab refuses the save as stale).
        $heldBack = $workflow !== null || ! $this->mayPublish || $this->changeSeen !== null;
        $liveRow = $this->ulid !== null && $this->status === 'published' && $heldBack ? ($row ?? Entry::query()->where('ulid', $this->ulid)->first()) : null;
        $buttonPanel = $this->buttonPanel($schemas);

        $this->blocksChanged = $this->blocksHash() !== ($this->saved['blocks'] ?? '') ? 1 : 0;
        $actions = $this->actions($workflow, $liveRow);
        $unit = $this->unit();
        $title = Cast::string(__($this->ulid === null ? 'entries.ed.h1_new' : 'entries.ed.h1_edit', ['unit' => $unit]));
        $listUrl = route('admin.entries.index', $this->sectionKey !== null ? ['section' => $this->sectionKey] : []);
        // «Xem trước» opens the saved version in «Xem trước trên website» on this screen (design 07), or is
        // locked with the reason on a record not saved yet; a member without the preview right gets no
        // button, so the guide has no step for it either
        $preview = $this->ulid !== null && $this->mayPreview
            ? ['url' => route('admin.entries.preview', ['ulid' => $this->ulid] + ($this->locale !== app(SiteLocale::class)->default() ? ['locale' => $this->locale] : [])), 'why' => null, 'dialog' => true]
            : ['url' => null, 'why' => $this->ulid === null ? Cast::string(__('admin.editor.preview_new')) : null];

        return view('admin::livewire.entries.editor', [
            'unit' => $unit,
            'frame' => [
                'title' => $title,
                'crumbs' => [
                    ['label' => $this->listLabel, 'url' => $listUrl],
                    ['label' => Cast::string(__($this->ulid === null ? 'admin.editor.crumb_new' : 'admin.editor.crumb_edit'))],
                ],
                'description' => Cast::string(__($this->ulid === null ? 'entries.ed.desc_new' : 'entries.ed.desc_edit', ['unit' => $unit])),
                'guide' => $this->guideDue
                    ? EditorGuide::for('editor-'.$this->type, $title, $unit, $this->ulid === null, $actions, $preview['url'] !== null || $preview['why'] !== null)->toArray($this->user(), app(CurrentSite::class)->id())
                    : null,
                'preview' => $preview,
                'trash' => $this->ulid !== null && $this->mayTrash ? ['why' => $this->isHome ? Cast::string(__('entries.home_trash_why')) : null] : null,
            ],
            'siteZone' => app(SiteTime::class)->zone(),
            'siteDateFormat' => app(SiteTime::class)->dateFormat(),
            'userUlid' => $this->user()->ulid,
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
            // «Khối dùng chung» (board 27): the picker, and what the cards of the blocks this layout places say
            'sharedPicker' => $this->sharedBlocksOffered() ? [
                'library' => Gate::allows('create', ContentBlock::class) ? route('admin.blocks.index') : null,
            ] : null,
            'refs' => $this->placedSharedBlocks(),
            'prosePreview' => $this->prose ? EntryProseBody::preview($this->bodyHtml) : '',
            'localeLabel' => Locales::label($this->locale),
            // «Quản lý ngôn ngữ» on the language bar: the site's languages, for a member who may change them
            'langManage' => $this->langBar !== [] && $this->user()->can('settings.manage') ? route('admin.settings.languages') : null,
            // the Home page's locked link: the website's root in this language
            'homePath' => app(PathBuilder::class)->publicUrl('/', $this->locale),
            // «Trang cha» is one value for every language: it is changed in the website's default language only
            // «Thẻ» is written to the website at once: while this member's saves go into a change, it stays as it is
            'tagsHeld' => $liveRow !== null,
            'sharedLock' => $this->locale !== app(SiteLocale::class)->default(),
            'sharedWhy' => Cast::string(__('entries.ed.shared_guard', ['lang' => Locales::label(app(SiteLocale::class)->default())])),
            // The writing area's hint: in another language it says that version is still empty.
            'bodyPlaceholder' => $this->locale === app(SiteLocale::class)->default()
                ? __('entries.ed.body_placeholder', ['unit' => mb_strtolower($this->unit())])
                : __('entries.ed.body_placeholder_tr', ['lang' => Locales::label($this->locale)]),
            'schemas' => $schemas,
            'categoryCard' => $this->categoryCard(),
            'jobCard' => $this->jobCard(),
            'urls' => $urls,
            'cover' => $this->coverCard($urls),
            'blockImageUrls' => $this->resolveBlockImageUrls($schemas),
            'goneProducts' => $this->goneProductKeys(),
            'looks' => $this->looks(),
            'schemeColours' => $this->schemeColours(),
            'workflow' => $workflow !== null ? $this->workflowPanel($workflow, $row) : null,
            'schedule' => $this->schedulePanel($workflow),
            'expiry' => $this->expiryPanel(),
            'pdLine' => $this->type === 'post' ? $this->pdLine() : null,
            'statusPanel' => $this->statusPanel(),
            'scheduleBand' => $this->scheduleBand($workflow, $row),
            'changeBand' => $this->changeBand($workflow, $liveRow),
            'revisions' => $this->revisionPanel(),
        ]);
    }

    /**
     * "Bỏ thay đổi" on the leave dialog: as resetChanges, and the copy this device kept of the
     * unsaved text goes too (the member chose to drop it).
     */
    public function discardChanges(): void
    {
        $this->resetChanges();
        $this->dispatch('admin-editor-saved');
    }

    /** Back to the saved entry, a new one back to an empty form. */
    public function resetChanges(): void
    {
        $this->resetErrorBag();
        $this->saveFailed = false;
        // A language with no saved version yet loads nothing back: start from an empty form.
        $preset = $this->ulid === null ? $this->parentLoaded : null;
        $this->reset('title', 'slug', 'excerpt', 'seoTitle', 'seoDescription', 'coverUlid', 'coverTouched', 'coverAlt', 'templateKey', 'displayDate', 'storedDisplayDate', 'expiresAt', 'expiresLoaded', 'publishedDay', 'formToast', 'formUndo', 'tags', 'tagsLoaded', 'tagDraft', 'jobFacts', 'jobDeadline', 'jobDeadlineAccent', 'jobBadge', 'jobStored', 'blocks', 'bodyHtml', 'prose', 'proseLook', 'converted', 'lookSplit', 'openBlock',
            'transitionNote', 'assignTo', 'showImagePicker', 'blockImagePickerPath', 'coverToast', 'coverUndo', 'stale', 'overwrite',
            'parentUlid', 'parentLoaded', 'parentName', 'pageHome', 'scheduleAt', 'scheduleLoaded', 'statusTo', 'autoSaved', 'slugFresh', 'slugTouched');
        $this->mount($this->ulid, $this->sectionKey, $this->locale, $preset);
    }

    /**
     * «Giữ bản của tôi»: the member has seen the version just saved and keeps theirs. The save the
     * question stopped runs again and may replace exactly that version (a newer one asks again).
     */
    public function keepMine(): void
    {
        $this->authorizeEditing();
        $stale = $this->stale;
        if ($stale === null) {
            return;
        }

        $this->stale = null;
        $this->overwrite = $stale['print'];
        match ($stale['action']) {
            'publish' => $this->publish(),
            'transition' => $this->applyTransition((string) $stale['transition']),
            // a draft's own save stays one: it never applies a status picked and not yet saved
            'autosave' => $this->saveItself(false),
            default => $this->save(),
        };
    }

    /**
     * «Lấy bản mới»: the form takes the version just saved. The browser has already kept this form's
     * text on this device (the «Khôi phục» band brings it back).
     */
    public function takeNewer(): void
    {
        if ($this->ulid === null) {
            return;
        }
        $name = $this->stale['name'] ?? null;

        $this->resetChanges(); // mount() authorizes `update` again
        if ($this->prose) {
            $this->dispatch('admin-editor-set-html', target: 'bodyHtml', html: $this->bodyHtml);
        }
        $this->dispatch('bs-toast', text: $name !== null
            ? __('entries.ed.stale_taken', ['name' => $name])
            : __('entries.ed.stale_taken_anon'));
    }

    /** The question closed without a choice (Escape, the backdrop): nothing saved, the next save asks again. */
    public function closeStale(): void
    {
        $this->authorizeEditing();
        $this->stale = null;
    }

    /**
     * «Khôi phục» on the band of text kept on this device: put it back in the form, unsaved. The same
     * fields the form sends anyway, each checked for its type; the save checks the rest as always.
     *
     * @param  array<array-key, mixed>  $kept
     */
    public function restoreKept(array $kept): void
    {
        $this->authorizeEditing();
        foreach (['title', 'slug', 'excerpt', 'seoTitle', 'seoDescription', 'coverAlt'] as $field) {
            if (is_string($kept[$field] ?? null)) {
                $this->{$field} = $kept[$field];
            }
        }
        $this->restoreKeptCover($kept['coverUlid'] ?? null, $kept['coverImageKey'] ?? null);
        // a post's own look and shown day, in the website's default language (they are every language's)
        if ($this->type === 'post' && ! $this->sharedLocked()) {
            $template = $kept['templateKey'] ?? null;
            if (is_string($template) && ($template === '' || in_array($template, TemplateCatalog::names(TemplateCatalog::ROLE_POST_VARIANT), true))) {
                $this->templateKey = $template;
            }
            $day = $kept['displayDate'] ?? null;
            if (is_string($day) && ($day === '' || DisplayDate::valid($day))) {
                $this->displayDate = $day;
            }
        }
        if ($this->type === 'post') {
            $this->restoreKeptJob($kept);
        }
        if ($this->type === 'post' && is_array($kept['categoryIds'] ?? null)) {
            $this->restoreCategories($kept['categoryIds']);
        } elseif ($this->type === 'post' && array_key_exists('categoryId', $kept)) {
            $this->categoryId = self::idOrNull($kept['categoryId']);
            $this->updatedCategoryId();
        }
        if ($this->prose && is_string($kept['bodyHtml'] ?? null)) {
            $this->bodyHtml = $kept['bodyHtml'];
            $this->dispatch('admin-editor-set-html', target: 'bodyHtml', html: $this->bodyHtml);
        }
        if (! $this->prose && is_array($kept['blocks'] ?? null)) {
            $this->openBlock = null;
            $this->blocks = $this->normalizeBlocks($kept['blocks']);
        }
    }

    /**
     * The fingerprint of what is stored now, read inside the save's transaction after the entry's
     * row lock: this language's row and, for a post, its categories in order (the main one first:
     * another save that ticked or unticked one is a change too). The cover comes from the
     * locked entry row.
     *
     * @return array{print: array<string, string>, translation: EntryTranslation|null}
     */
    private function storedNow(Entry $entry): array
    {
        // The whole row: the save writes through this same model, so the check costs no extra read.
        $translation = $entry->translations()->where('locale', $this->locale)->first();

        return ['print' => self::printOf($translation, $this->coverPrint($entry->cover_media_id, $entry->meta), $entry->meta, $this->type === 'post' ? $this->storedCategories($entry) : [], $entry->template_override), 'translation' => $translation];
    }

    /**
     * One fingerprint per value a save writes. The body is compared with its keys in a fixed order, so
     * the database's own key order never reads as a change.
     *
     * @param  list<int>  $categoryIds  a post's categories, the main one first (none for a page)
     * @return array<string, string>
     */
    private static function printOf(?EntryTranslation $translation, string $cover, mixed $meta, array $categoryIds, ?string $template): array
    {
        return [
            'title' => md5((string) $translation?->title),
            'slug' => md5((string) $translation?->slug),
            'body' => md5((string) json_encode(self::keysSorted($translation instanceof EntryTranslation ? $translation->body : []))),
            'excerpt' => md5((string) $translation?->excerpt),
            'seo_title' => md5((string) $translation?->seo_title),
            'seo_description' => md5((string) $translation?->seo_description),
            // the file's key (coverPrint): a form opened before covers had rows printed the key alone
            'cover' => md5($cover),
            'category' => implode(',', $categoryIds),
            // last: a form opened before the field existed holds a print without it (see save())
            'cover_alt' => md5((string) $translation?->cover_alt),
            // after it, as later fields: a form opened before them holds a print without them (see save())
            'template' => md5((string) $template),
            'display_date' => md5(DisplayDate::of($meta)),
            // «Thông tin tuyển dụng»: every language's boxes and label (another language's save changes them too),
            // keys in a fixed order like the body's (the database hands the language maps back sorted)
            'job' => md5((string) json_encode(self::keysSorted(is_array($meta) ? [$meta['facts'] ?? null, $meta['badge'] ?? null] : [null, null]))),
        ];
    }

    /**
     * The stored cover as the overwrite check compares it: the storage key of the file the pair names
     * (by its id, the Trash included, else the old key in meta), so the same picture moved from its key
     * to its row in another tab is no change; '#<id>' for an id that names no file of this website.
     * The form's own cover row answers when it is that file, else one read on the media primary key.
     */
    private function coverPrint(mixed $coverMediaId, mixed $meta): string
    {
        $id = MediaRefs::id($coverMediaId);
        if (! EntryCover::named($coverMediaId) || $id === null) {
            return EntryCover::identity($coverMediaId, $meta);
        }
        if ($this->coverRow !== null && MediaRefs::id($this->coverRow->id) === $id) {
            return $this->coverRow->storage_key;
        }
        $key = Media::withTrashed()->whereKey($id)->value('storage_key');

        return is_string($key) ? $key : '#'.$id;
    }

    private static function keysSorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::keysSorted(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Someone else saved since the form was loaded: open the question (board 07's stale dialog) with
     * who saved and when, and each field that differs: this form's value beside the one just saved.
     * Who: the newest history version of this language saved by another member after the form was
     * loaded (history is kept for live content); otherwise the words name no one.
     *
     * @param  array{print: array<string, string>, translation: EntryTranslation|null}  $stored
     */
    private function askBeforeOverwrite(Entry $entry, array $stored, string $action, ?string $transition): void
    {
        // The newest few history rows (one query, the history panel's own read), newest first.
        $me = $this->user()->id;
        $since = $this->loadedAt !== null ? CarbonImmutable::parse($this->loadedAt) : null;
        $revision = app(EntryRevisions::class)->forEntry($entry, $this->locale, limit: 5)['rows']->first(
            static fn ($row): bool => $row->created_by !== null && (int) $row->created_by !== $me
                && ($since === null || $row->created_at->gt($since)),
        );
        $name = $revision?->creator?->name;
        $name = is_string($name) && $name !== '' ? $name : null;

        $translation = $stored['translation'];
        $at = $revision?->created_at;
        if (! $at instanceof DateTimeInterface) {
            $times = array_filter([$translation?->updated_at, $entry->updated_at], static fn (mixed $t): bool => $t instanceof DateTimeInterface);
            $at = $times === [] ? null : max($times);
        }
        $time = $at instanceof DateTimeInterface
            ? (app(SiteTime::class)->forDisplay(CarbonImmutable::instance($at))?->format('H:i') ?? '')
            : '';
        $what = __($this->type === 'page' ? 'entries.ed.stale_what_page' : 'entries.ed.stale_what_post');

        $this->stale = [
            'title' => $name !== null
                ? __('entries.ed.stale_title', ['what' => $what, 'name' => $name, 'time' => $time])
                : __('entries.ed.stale_title_anon', ['what' => $what, 'time' => $time]),
            'line' => $name !== null ? __('entries.ed.stale_line', ['name' => $name]) : __('entries.ed.stale_line_anon'),
            'name' => $name,
            'rows' => $this->staleRows($entry, $stored['print'], $translation),
            'action' => $action,
            'transition' => $transition,
            'print' => $stored['print'],
        ];
    }

    /**
     * The fields another save changed: label, this form's value, the stored one.
     *
     * @param  array<string, string>  $print
     * @return list<array{label: string, mine: string, theirs: string}>
     */
    private function staleRows(Entry $entry, array $print, ?EntryTranslation $translation): array
    {
        $prose = app(EntryProseBody::class);
        $storedBlocks = $this->normalizeBlocks($translation instanceof EntryTranslation ? ($translation->body['blocks'] ?? []) : []);
        $storedBody = $prose->read($storedBlocks);
        // the stored cover as EntryCover reads it (its row, else the old key); read only when it names a file
        $cover = EntryCover::resolve($entry->cover_media_id, $entry->meta, MediaRefs::rows([$entry->cover_media_id]));
        $categories = [];
        $theirIds = array_values(array_filter(array_map(self::idOrNull(...), explode(',', $print['category'] ?? ''))));
        if (($print['category'] ?? '') !== ($this->storedPrint['category'] ?? '')) {
            $categories = TaxonomyTranslation::query()
                ->whereIn('taxonomy_id', array_values(array_unique([...$this->categoryIds, ...$theirIds])))
                ->where('locale', $this->locale)
                ->pluck('name', 'taxonomy_id')->all();
        }
        // a post's categories as the card lists them, the main one first
        $categoryNames = static function (array $ids) use ($categories): string {
            return implode(', ', array_map(static function (mixed $id) use ($categories): string {
                $name = $categories[Cast::int($id)] ?? null;

                return is_string($name) ? $name : (string) __('entries.unnamed');
            }, $ids));
        };

        $fields = [
            'title' => [__($this->type === 'page' ? 'entries.ed.title_page' : 'entries.ed.title_post'), $this->title, (string) $translation?->title],
            'slug' => [__('entries.ed.slug'), $this->slug, (string) $translation?->slug],
            'excerpt' => [__('admin.seo.excerpt_label'), $this->excerpt, (string) $translation?->excerpt],
            'body' => [__('entries.ed.body_label'),
                $this->prose ? EntryProseBody::preview($this->bodyHtml, 160) : __('entries.ed.stale_blocks', ['n' => count($this->blocks)]),
                $storedBody !== null ? EntryProseBody::preview($storedBody['html'], 160) : __('entries.ed.stale_blocks', ['n' => count($storedBlocks)])],
            'seo_title' => [__('admin.seo.title_label'), $this->seoTitle, (string) $translation?->seo_title],
            'seo_description' => [__('admin.seo.description_label'), $this->seoDescription, (string) $translation?->seo_description],
            'cover' => [__('entries.ed.card_cover'), $this->coverName($this->coverKey()), $this->coverName($cover)],
            'cover_alt' => [__('entries.ed.cover_alt_label'), $this->coverAlt, (string) $translation?->cover_alt],
            'category' => [__('entries.ed.card_category'), $categoryNames($this->categoryIds), $categoryNames($theirIds)],
            'template' => [__('entries.rev.f_tpl'), $this->templateName($this->formTemplate($entry->template_override)), $this->templateName($entry->template_override)],
            'display_date' => [__('entries.ed.pd_label', ['doc' => $this->docNoun()]), $this->dayName($this->displayDate), $this->dayName(DisplayDate::of($entry->meta))],
            'job' => [__('entries.ed.job_card'), $this->jobSummary(), $this->jobSummaryOf($entry->meta)],
        ];

        $rows = [];
        $empty = (string) __('entries.ed.stale_empty');
        foreach ($fields as $key => [$label, $mine, $theirs]) {
            if (($print[$key] ?? '') !== ($this->storedPrint[$key] ?? '')) {
                $rows[] = [
                    'label' => (string) $label,
                    'mine' => is_string($mine) && $mine !== '' ? $mine : $empty,
                    'theirs' => is_string($theirs) && $theirs !== '' ? $theirs : $empty,
                ];
            }
        }

        return $rows;
    }

    private static function idOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** A cover as the member knows it: the picture's file name in the library. */
    private function coverName(string $key): string
    {
        if ($key === '') {
            return '';
        }
        $name = Media::query()->where('storage_key', $key)->value('original_filename');

        return is_string($name) && $name !== '' ? $name : $key;
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
            $values[$field] = match (true) {
                // written as the browser's JSON.stringify writes it (admin-editor.js valueText)
                is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS),
                is_scalar($value) => (string) $value,
                default => '',
            };
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
            // «Hẹn giờ đăng» counts only while the entry is (or is set to be) scheduled: otherwise the time does not apply
            if ($field === 'scheduleAt' && $this->effectiveStatus() !== EntryStatus::Scheduled->value) {
                continue;
            }
            if ($field !== 'blocks' && ($this->saved[$field] ?? '') !== $value) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The fields with unsaved edits, by the names the form shows them under, once each and in the
     * form's order; empty when nothing differs from what was loaded or saved (as changeCount counts,
     * the block list judged again now).
     *
     * @return list<string>
     */
    private function unsavedNames(): array
    {
        $labels = [
            'title' => $this->type === 'page' ? 'entries.ed.title_page' : 'entries.ed.title_post',
            'slug' => 'entries.ed.slug', 'excerpt' => 'admin.seo.excerpt_label', 'bodyHtml' => 'entries.ed.body_label',
            'seoTitle' => 'admin.seo.title_label', 'seoDescription' => 'admin.seo.description_label',
        ];
        $blocks = $this->blocksHash() !== ($this->saved['blocks'] ?? '');
        $names = [];
        $choices = false;
        foreach ($this->tracked() as $field => $value) {
            if ($field === 'blocks' || ($field === 'scheduleAt' && $this->effectiveStatus() !== EntryStatus::Scheduled->value)) {
                continue;
            }
            if (($this->saved[$field] ?? '') === $value && ! ($field === 'bodyHtml' && $blocks)) {
                continue;
            }
            if (isset($labels[$field])) {
                $names[] = Cast::string(__($labels[$field], ['doc' => $this->docNoun()]));
            } else {
                $choices = true;
            }
        }
        if ($choices) {
            $names[] = Cast::string(__('entries.refuse.reload_choices'));
        }

        return array_values(array_unique($names));
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

    /**
     * «Chuyển vào thùng rác» in the ⋮, for a saved entry — the same move the list's row makes, through
     * the same policy (EntryPolicy::delete): the entry goes to the Trash (kept EntryTrash::days() days)
     * and the editor leaves for its list, which says so with its own «Hoàn tác». The Home page never
     * goes (another page becomes the Home page first), and a page with sub-pages goes from the Pages
     * list, where its sub-pages go
     * with it; both are refused here with the reason, nothing moved. A right taken away since the
     * screen opened is refused in words, as the list refuses it. An entry already in the Trash (another
     * tab, another member) is not moved again — that would restart its purge clock — and the member is
     * told it changed. A page goes as the list moves it: under the site's page-tree lock (PageRules::lock,
     * taken first), then its row, read again and locked, so no page is moved under it meanwhile.
     */
    public function trash(): void
    {
        abort_if($this->ulid === null, 404);
        $entry = Entry::query()->where('ulid', $this->ulid)->first();
        if ($entry === null) {
            $this->staleRow();

            return;
        }
        try {
            $this->authorize('delete', $entry);
        } catch (AuthorizationException) {
            $this->mayTrash = false;
            $this->refuseTrash(EntryRefusal::for($this->user(), $entry, 'delete'));

            return;
        }

        try {
            $outcome = DB::transaction(fn (): mixed => app(CategoryItemCounts::class)->batch(function () use ($entry): string {
                if ($entry->type === 'page') {
                    PageRules::lock(app(CurrentSite::class)->id());
                }
                $live = Entry::query()->whereKey($entry->id)->lockForUpdate()->first();
                if ($live === null) {
                    return 'gone';
                }
                if ($live->type === 'page' && Entry::query()->where('type', 'page')->where('parent_id', $live->id)->exists()) {
                    return 'kids';
                }
                HomePages::remember([$live]);
                $live->delete();

                return 'done';
            }));
        } catch (HomePageLockedException) {
            $outcome = 'home';
        }

        if ($outcome !== 'done') {
            match ($outcome) {
                'gone' => $this->staleRow(),
                'kids' => $this->refuseTrash(Cast::string(__('admin.editor.trash_kids'))),
                default => $this->refuseHome(),
            };

            return;
        }

        // nothing is left to save: the page leaves without asking about unsaved changes, and is not drawn
        // again for an entry that is now in the Trash
        $this->saved = $this->tracked();
        $this->skipRender();
        if ($this->type === 'post' && $this->sectionKey === null) {
            // a post of no section leaves for the Pages list, which does not list it: the move is said here
            $name = $entry->translationFor($this->locale)?->title;
            $this->js('window.bsLeaveTo('.Js::encode(route('admin.entries.index')).', '.Js::encode(['text' => Cast::string(__('entries.trashed_one', ['name' => $name ?? $this->unit()]))]).')');

            return;
        }
        // the list says the move with its own message and «Hoàn tác» (Index::sayTrashedInEditor)
        $listUrl = route('admin.entries.index', ($this->sectionKey !== null ? ['section' => $this->sectionKey] : []) + [Index::TRASHED_IN_EDITOR => $entry->ulid]);
        $this->js('window.bsLeaveTo('.Js::encode($listUrl).')');
    }

    /** The refusal of a move to the Trash, in a message that stays until it is closed. */
    private function refuseTrash(string $why): void
    {
        $this->dispatch('bs-toast', text: $why, stay: true, close: Cast::string(__('admin.close')));
    }

    /** The Home page never goes to the Trash: the ⋮ item shows locked from now on, and the reason is said. */
    private function refuseHome(): void
    {
        $this->isHome = true;
        $this->refuseTrash(Cast::string(__('entries.home_trash_why')));
    }

    /**
     * The entry is no longer live (in the Trash, or gone): in a message that stays until it is closed.
     * The screen stays as it is, so nothing typed is lost, and is not drawn again from a row that is gone.
     */
    private function staleRow(): void
    {
        $this->skipRender();
        $this->dispatch('bs-toast', text: Cast::string(__('admin.editor.gone')), stay: true, close: Cast::string(__('admin.close')));
    }

    /**
     * «Trang cha» (board 07): what the picker offers, read when it opens (PageParentChoices). Null for a
     * post and for the Home page, which have no parent to pick.
     *
     * @return array{more: bool, opts: list<array{k: string|null, name: string, lv: int, why: string|null}>}|null
     */
    public function parentChoices(): ?array
    {
        if ($this->ulid === null) {
            $this->authorize('create', Entry::class);
            $page = null;
        } else {
            $page = $this->resolveOrFail($this->ulid);
            $this->authorize('update', $page);
        }
        if ($this->type !== 'page' || $this->pageHome) {
            return null;
        }

        return app(PageParentChoices::class)->for($page);
    }

    /** Keeps the pick this change replaces, so a refused one can be put back (updatedParentUlid). */
    public function updatingParentUlid(): void
    {
        $this->parentBefore = $this->parentUlid;
    }

    /**
     * A pick in «Trang cha», checked as it arrives: a live page of this website (looked up by ulid
     * through the website's scope, so another website's page is never found), not the Home page, not
     * this page or a page under it, and room for this page and its sub-pages within three levels. A
     * refused pick is put back and the reason stays under the field. The parent is shared by every
     * language, so it is picked in the website's default language only. The save checks it all again.
     */
    public function updatedParentUlid(mixed $value): void
    {
        $before = $this->parentBefore;
        $this->parentBefore = null;
        $this->authorizeEditing();
        $this->resetErrorBag('parentUlid');
        if ($this->type !== 'page' || $this->pageHome) {
            $this->parentUlid = $before;

            return;
        }
        $default = app(SiteLocale::class)->default();
        if ($this->locale !== $default) {
            $this->parentUlid = $before;
            $this->dispatch('bs-toast', text: Cast::string(__('entries.ed.shared_guard', ['lang' => Locales::label($default)])), stay: true, close: Cast::string(__('admin.close')));

            return;
        }

        $parent = null;
        if ($value !== null && $value !== '') {
            $parent = is_string($value) ? $this->findPage($value) : null;
            $why = $parent instanceof Entry ? $this->parentRefusal($parent) : Cast::string(__('entries.ed.par_gone'));
            if ($why !== null) {
                $this->parentUlid = $before;
                $this->addError('parentUlid', $why);

                return;
            }
        }
        $this->parentUlid = $parent?->ulid;
        $this->parentName = $parent instanceof Entry ? $this->pageTitle($parent) : '';
        $this->address = $this->previewAddress($this->placedDraft($parent));
        $this->placePageOrder($parent?->id);
    }

    /**
     * «Thêm trang con» from the Trang list: the parent it names, kept when it is a live page of this
     * website that may hold one more level (not the Home page, not a third-level page). Anything else —
     * a malformed key, a page of another website, a page in the Trash, a page that cannot take one more
     * level — is not kept: the form starts as a top page, and says why under «Trang cha».
     */
    private function presetParent(mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $parent = is_string($value) ? $this->findPage($value) : null;
        if ($parent === null) {
            $this->addError('parentUlid', Cast::string(__('entries.ed.par_gone')));

            return;
        }
        $why = $this->parentRefusal($parent);
        if ($why !== null) {
            $this->addError('parentUlid', $why);

            return;
        }
        $this->parentUlid = $parent->ulid;
        $this->parentLoaded = $parent->ulid;
        $this->parentName = $this->pageTitle($parent);
        $this->address = $this->previewAddress($this->placedDraft($parent));
    }

    /**
     * The page's place in the tree as the form opens: its parent (in the Trash too: it is still under it),
     * and whether it is the Home page — known already when the frame asked (a member who may delete it),
     * else read from the language being edited (its slug is the Home one), with no query, or asked once when
     * that language is not written yet: a page that is the Home page in another written language only still
     * has its move refused on save (MovePage).
     */
    /** @param  ?int  $placeBefore  the live pages before it, as the open read them (openEntry) */
    private function loadParent(Entry $entry, ?EntryTranslation $translation, ?int $placeBefore = null): void
    {
        $this->pageHome = match (true) {
            $this->mayTrash => $this->isHome,
            $translation instanceof EntryTranslation => in_array($translation->slug, [PathBuilder::HOME_SLUG, ''], true),
            // a language not written yet has no slug to tell: asked once, so its link stays the root
            default => HomePages::is($entry),
        };
        $parent = $entry->parent_id === null ? null : Entry::withTrashed()->whereKey($entry->parent_id)->first(['id', 'site_id', 'ulid', 'type']);
        $this->parentUlid = $parent?->ulid;
        $this->parentLoaded = $parent?->ulid;
        $this->parentName = $parent instanceof Entry ? $this->pageTitle($parent) : '';
        $this->loadPageOrder($entry, $placeBefore);
    }

    /** A live page of this website by its ulid (the website's scope: another website's is never found). */
    private function findPage(string $ulid): ?Entry
    {
        return Str::isUlid($ulid) ? Entry::query()->where('ulid', $ulid)->where('type', 'page')->first() : null;
    }

    /**
     * Why $parent cannot be this page's parent, in the picker's words (board 07), or null: the Home page
     * has no sub-pages; the page itself and the pages under it are refused; the parent's level plus the
     * page's branch (sub-pages in the Trash included, PageRules::heightOf) may not pass three levels. The
     * parent the page has now is always fine: keeping it moves nothing.
     */
    private function parentRefusal(Entry $parent): ?string
    {
        if (HomePages::is($parent)) {
            return Cast::string(__('entries.move_refused_parent_home'));
        }
        $page = $this->ulid !== null ? $this->resolveOrFail($this->ulid) : null;
        if ($page !== null && $parent->id === $page->parent_id) {
            return null;
        }
        if ($page !== null && $parent->id === $page->id) {
            return Cast::string(__('entries.ed.par_why_self'));
        }
        $ancestors = PageRules::ancestorsOf($parent);
        if ($page !== null && in_array($page->id, $ancestors, true)) {
            return Cast::string(__('entries.ed.par_why_sub'));
        }
        $height = $page === null ? 1 : PageRules::heightOf($page);
        if ($height === null) {
            return Cast::string(__('entries.move_refused_branch_too_big'));
        }
        $level = count($ancestors) + 1;
        if ($level + $height > PageRules::MAX_DEPTH) {
            return $height > 1
                ? Cast::string(__('entries.ed.par_why_branch'))
                : Cast::string(__('entries.ed.par_why_level', ['n' => $level + 1]));
        }

        return null;
    }

    /** Does this save change the page's parent? Only a page that is not the Home page, in the default language. */
    private function reparenting(): bool
    {
        return $this->type === 'page' && $this->ulid !== null && ! $this->pageHome
            && $this->parentUlid !== $this->parentLoaded
            && $this->locale === app(SiteLocale::class)->default();
    }

    /**
     * A new page's first save in the website's page tree, in its own savepoint of the request's transaction
     * (the page-tree lock is held to the end of the request, through the translation and a publish): the
     * site's page-tree lock FIRST (PageRules::lock), as every writer of the tree takes it, then the page made
     * the last of the top-level pages, or, under the picked parent, the parent read again and checked — a
     * live page of this website, not the Home page, with room for one more level — and the page made the
     * last of its sub-pages. A refusal writes nothing and keeps the form as typed (null). The address is
     * checked when the page is published (RouteSync), as for every page.
     *
     * @param  array<string, mixed>  $fields
     */
    private function createInTree(array $fields): ?Entry
    {
        try {
            $entry = DB::transaction(function () use ($fields): Entry {
                PageRules::lock(app(CurrentSite::class)->id());
                if ($this->parentUlid === null) {
                    $last = Entry::query()->where('type', 'page')->whereNull('parent_id')->max('position');

                    return Entry::query()->create($fields + ['position' => $last === null ? 0 : Cast::int($last) + 1]);
                }
                $parent = $this->findPage((string) $this->parentUlid) ?? throw new PageMoveRejectedException('parent_invalid');
                if (HomePages::is($parent)) {
                    throw new PageMoveRejectedException('parent_home');
                }
                if (PageRules::levelOf($parent) + 1 > PageRules::MAX_DEPTH) {
                    throw new PageMoveRejectedException('too_deep');
                }
                $last = Entry::query()->where('type', 'page')->where('parent_id', $parent->id)->max('position');

                return Entry::query()->create($fields + ['parent_id' => $parent->id, 'position' => $last === null ? 0 : Cast::int($last) + 1]);
            });
        } catch (PageMoveRejectedException $refused) {
            $this->refuseParent($refused);

            return null;
        }
        $this->parentLoaded = $this->parentUlid;

        return $entry;
    }

    /**
     * Move the page, with its sub-pages, under the picked parent (null: the top level) through MovePage —
     * the one definition of a move: the tree's rules, the addresses and their 301s, the audit — at the
     * place typed in «Thứ tự» ($at, from 0), else at the end of its sub-pages; «Thứ tự» then shows the
     * place it took. False, with the reason shown, when it refuses.
     */
    private function moveToParent(Entry $entry, ?int $at = null): bool
    {
        $parent = null;
        if ($this->parentUlid !== null) {
            $parent = $this->findPage($this->parentUlid);
            if (! $parent instanceof Entry) {
                $this->refuseParent(new PageMoveRejectedException('parent_invalid'));

                return false;
            }
        }
        try {
            $moved = $at === null ? app(MovePage::class)->into($entry, $parent) : app(MovePage::class)->handle($entry, $parent, $at);
            $entry->setRawAttributes(['parent_id' => $moved['parent'], 'position' => $moved['position']] + $entry->getAttributes(), true);
            $entry->unsetRelation('parent');
        } catch (PageMoveRejectedException $refused) {
            if ($refused->reason !== 'here_already') {
                $this->refuseParent($refused);

                return false;
            }
        }
        $this->loadPageOrder($entry);
        $this->parentLoaded = $this->parentUlid;
        $this->liveAddress = $this->routedAddress($entry->id);

        return true;
    }

    /**
     * The rows MovePage locks, taken now, in id order as it takes them, before this save's own lock on the
     * page's row: the page, the parent picked, the parent it has now, the pages above both, the live pages
     * beside it now and beside it there, and the live pages under it (they move with it). A
     * writer that locks several pages in id order (the list's bulk actions) then never holds one of them
     * while waiting for the page this save already holds. Its place is read again under the page-tree
     * lock (a move may have committed while this save waited for it); each list is read as MovePage reads
     * it, at most one past its limit, and a parent that is gone locks nothing (the move is refused).
     */
    private function lockMoveRows(Entry $entry): void
    {
        $parent = $this->parentUlid === null ? null : $this->findPage($this->parentUlid);
        $parentId = $parent?->id;
        if ($this->parentUlid !== null && $parentId === null) {
            return;
        }
        $from = Entry::withTrashed()->whereKey($entry->id)->value('parent_id');
        $from = $from === null ? null : Cast::int($from);
        $beside = static fn (?int $id): array => Entry::query()->where('type', 'page')
            ->when($id === null, fn (Builder $q) => $q->whereNull('parent_id'), fn (Builder $q) => $q->where('parent_id', $id))
            ->orderBy('position')->orderBy('id')->limit(MovePage::SIBLINGS_MAX + 1)->pluck('id')
            ->map(static fn (mixed $sibling): int => Cast::int($sibling))->all();
        $old = $beside($from);
        $new = $beside($parentId);
        if (count($old) > MovePage::SIBLINGS_MAX || count($new) > MovePage::SIBLINGS_MAX) {
            return;   // MovePage refuses the move before it locks a row
        }
        $above = $from === $parentId ? [] : [...PageRules::above($entry->id, $from), ...($parent === null ? [] : PageRules::ancestorsOf($parent))];
        $ids = [$entry->id, ...($parentId === null ? [] : [$parentId]), ...($from === null ? [] : [$from]), ...$above, ...$old, ...$new, ...PageRules::subPageIds($entry)];
        Entry::withTrashed()->whereKey(array_values(array_unique($ids)))->orderBy('id')->lock(MovePage::ROW_LOCK)->pluck('id');
    }

    /** A refused «Trang cha» on save: the reason under the field and in a message that stays; nothing saved. */
    private function refuseParent(PageMoveRejectedException $refused): void
    {
        if ($refused->reason === 'page_invalid') {
            // the page itself went to the Trash meanwhile: said as for any save of a page that is gone
            $this->saveFailed = true;
            $this->staleRow();

            return;
        }
        $why = $refused->reason === 'parent_invalid' ? Cast::string(__('entries.ed.par_gone')) : PageMoveWords::why($refused);
        $this->saveFailed = true;
        $this->addError('parentUlid', $why);
        $this->dispatch('bs-toast', text: $why, stay: true, close: Cast::string(__('admin.close')));
    }

    /** The page's title in the website's default language (another language's when that one has none). */
    private function pageTitle(Entry $page): string
    {
        $title = EntryTranslation::query()->where('entry_id', $page->id)->where('title', '!=', '')
            ->orderByRaw('locale = ? desc', [app(SiteLocale::class)->default()])->orderBy('locale')->value('title');

        return is_string($title) && $title !== '' ? $title : Cast::string(__('entries.untitled'));
    }

    /** This page as it would be under $parent, for the address preview. */
    private function placedDraft(?Entry $parent): Entry
    {
        $entry = $this->ulid !== null ? $this->resolveOrFail($this->ulid) : new Entry(['type' => $this->type]);
        $entry->setRawAttributes(['parent_id' => $parent?->id] + $entry->getAttributes());
        $entry->setRelation('parent', $parent);

        return $entry;
    }

    /** What the frame's «Xem trước» and ⋮ offer for a saved entry: the member's preview and delete rights, and whether it is the Home page. */
    private function frameRights(Entry $entry): void
    {
        $user = $this->user();
        $this->mayPreview = $user->can('preview', $entry);
        $this->mayTrash = $user->can('delete', $entry);
        // asked only where it changes something (the ⋮'s Trash item, the status list of live content): one query
        $home = ($this->mayTrash || $entry->status === EntryStatus::Published->value) && HomePages::is($entry);
        $this->homePage = $home;
        $this->isHome = $this->mayTrash && $home;
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

    /**
     * The template_override this form stores for a post: the variant picked, else none — but a layout
     * stored some other way (a content pack, a copy) is kept, since the form does not offer it.
     */
    private function formTemplate(?string $stored): ?string
    {
        if ($this->type !== 'post') {
            return $stored;
        }
        if ($this->templateKey !== '') {
            return $this->templateKey;
        }

        return TemplateCatalog::variant($stored) !== null ? null : $stored;
    }

    /** A template_override as «Mẫu hiển thị» names it (the stale question, the history). */
    private function templateName(mixed $template): string
    {
        return match ($template) {
            TemplateCatalog::VARIANT_HERO => Cast::string(__('entries.ed.tpl_hero', ['doc' => $this->docNoun()])),
            TemplateCatalog::VARIANT_WIDE => Cast::string(__('entries.ed.tpl_wide')),
            null, '' => Cast::string(__('entries.rev.tpl_default')),
            default => Cast::string($template),
        };
    }

    /** A display day as the website writes dates; '' when none (the real day shows). */
    private function dayName(string $day): string
    {
        $at = $day !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', $day) : null;

        return $at instanceof CarbonImmutable ? SiteTime::formatDay($at, app(SiteTime::class)->dateFormat(), $this->locale) : '';
    }

    /** The short word for the content in «Ảnh lớn đầu …» / «Ngày đăng hiện trên …» (board 07 docN). */
    private function docNoun(): string
    {
        return Cast::string(__($this->type === 'page' ? 'entries.ed.doc_page' : 'entries.ed.doc_post'));
    }

    /** One value for every language (cover, look, shown day): set in the website's default language only. */
    private function sharedLocked(): bool
    {
        return $this->locale !== app(SiteLocale::class)->default();
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
        // the draft's own save reads «Đã lưu nháp …» (board 07); a button's save «Đã lưu …»
        $text = (string) __($this->autoSaved ? 'entries.ed.sv_auto' : 'entries.ed.sv_saved', ['when' => $today ? $time : $at->format('d/m')]);

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
    private function actions(?Workflow $workflow, ?Entry $liveRow): array
    {
        // the status picked in «Khi lưu, bài sẽ là» decides the buttons, as it decides the save (board 07)
        $saved = $this->status ?? 'draft';
        $status = $this->effectiveStatus();
        $draftWord = (string) __($status === 'draft' ? 'entries.save_draft' : 'entries.ed.save_plain');
        // live content whose edits go into its pending change (EditsPendingChange)
        $held = $this->changeButtons($workflow, $liveRow);
        if ($held !== null) {
            return $held;
        }
        if ($workflow !== null) {
            return ['secondary' => $draftWord, 'primary' => null, 'primaryAction' => 'save', 'primaryIcon' => 'ri-save-3'];
        }

        $user = $this->user();
        $canPublish = $this->mayPublish;
        if (in_array($status, ['published', 'scheduled', 'private', 'archived'], true)) {
            return [
                'secondary' => null,
                // a new entry set to «Hẹn giờ đăng» is not updated yet: its button reads «Đăng» (board 07)
                'primary' => (string) __(match (true) {
                    ! $canPublish => 'entries.ed.save_plain',
                    $this->ulid === null => 'entries.publish',
                    default => 'entries.ed.update',
                }),
                'primaryAction' => $canPublish && $status === 'published' ? 'publish' : 'save',
                'primaryIcon' => $canPublish ? 'ri-send-plane-fill' : 'ri-save-3',
            ];
        }

        return match (true) {
            $canPublish => ['secondary' => $draftWord, 'primary' => (string) __('entries.publish'), 'primaryAction' => 'publish', 'primaryIcon' => 'ri-send-plane-fill'],
            // Already waiting for review: sending it again moves nothing, so only the save stays.
            $saved !== 'pending' && $user->can('entries.submit') => ['secondary' => $draftWord, 'primary' => (string) __('entries.ed.submit'), 'primaryAction' => 'publish', 'primaryIcon' => 'ri-send-plane'],
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
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        // a shared block this entry stores now stays allowed once it is gone (read only if one is not found)
        $rules = self::onFormFields(app(EntryContentRules::class)->rules($this->ulid !== null ? EntryContentRules::storedRefs($this->ulid) : []));
        // the featured image's words: one line of plain text (normalized before validation)
        $rules['coverAlt'] = ['nullable', 'string', 'max:'.EntryContentRules::COVER_ALT_MAX, $this->plainCoverAlt(...)];

        // A post's URL is its category slug + post slug, so a post must have a category.
        if ($this->type === 'post') {
            $rules['categoryId'] = [
                'required', 'integer',
                Rule::exists('taxonomies', 'id')->where('vocabulary', 'post_cat'),
                $this->keepsGovernedSection(...),
                $this->staysInCategoryLimits(...),
            ];
            // the other categories: each one a post category of this website, at most what the pivot
            // keeps below the facet terms
            $rules['categoryIds'] = ['array', 'max:'.(Entry::FACET_POSITION_BASE - 1), $this->arePostCategories(...)];
            $rules['categoryIds.*'] = ['integer', 'distinct'];
            // «Thuộc tính bài»: a look the theme draws for posts, a real calendar day
            // «Thẻ»: names a tag can have (EntryTags::clean), at most the tags one post carries
            $rules['tags'] = ['array', 'max:'.EntryTags::MAX_TAGS];
            $rules['tags.*'] = ['string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || EntryTags::clean($value, $this->locale) === null) {
                    $fail(Cast::string(__('entries.ed.tag_invalid', ['max' => EntryTags::NAME_MAX])));
                }
            }];
            $rules['templateKey'] = ['string', Rule::in(TemplateCatalog::names(TemplateCatalog::ROLE_POST_VARIANT))];
            $rules['displayDate'] = ['nullable', 'string', 'date_format:Y-m-d', static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && $value !== '' && ! DisplayDate::valid($value)) {
                    $fail(Cast::string(__('entries.ed.pd_invalid')));
                }
            }, new DisplayDateNotAfterToday(app(SiteTime::class)->zone(), $this->storedDisplayDate, app(SiteTime::class)->dateFormat(), $this->locale)];
        }
        // «Gỡ khỏi website lúc»: judged only when this save changes it (one already past on an entry taken down is normal)
        $rules['expiresAt'] = $this->expiresAt !== $this->expiresLoaded
            ? ['string', new ExpiryTime(app(SiteTime::class)->zone(), $this->formPublishAt())]
            : ['string'];

        return [...$rules, ...$this->jobRules()];
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
     * Every id in the list a post category of this website: one count, scoped to the site, however
     * many there are (`integer` / `max` report a bad list).
     */
    private function arePostCategories(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || $value === [] || count($value) >= Entry::FACET_POSITION_BASE) {
            return;
        }
        $ids = [];
        foreach ($value as $id) {
            if (! is_int($id)) {
                return;
            }
            $ids[$id] = true;
        }
        // BelongsToSite's scope keeps the explicit site_id = ? on the count
        $found = Taxonomy::query()->where('vocabulary', 'post_cat')->whereIn('id', array_keys($ids))->count();
        if ($found !== count($ids)) {
            $fail(__('entries.category_invalid'));
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
            'categoryIds.*' => __('entries.category_invalid'),
            'coverAlt.max' => __('entries.ed.cover_alt_too_long', ['max' => EntryContentRules::COVER_ALT_MAX]),
            'templateKey.in' => __('entries.ed.tpl_invalid'),
            'displayDate.date_format' => __('entries.ed.pd_invalid'),
            'tags.max' => __('entries.ed.tag_full', ['max' => EntryTags::MAX_TAGS]),
        ];
    }

    /** «Mô tả ảnh» is words, never markup (EntryContentRules::coverAlt, as a restored version is held). */
    private function plainCoverAlt(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && PlainText::hasMarkup($value)) {
            $fail(__('entries.ed.cover_alt_markup'));
        }
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
    /**
     * Save the form. `$action` is the button that asked (save · publish · transition, with the
     * transition's key): when someone else saved this entry since the form was loaded, nothing is
     * written, the question opens, and its «Giữ bản của tôi» runs that same action again. Returns
     * null then.
     */
    private function persist(string $action = 'save', ?string $transition = null): ?Entry
    {
        // No control character is stored (PostgreSQL refuses a NUL); Google shows its two fields on
        // one line, so a line break or an invisible direction mark never reaches the head either.
        $this->title = PlainText::line($this->title);
        $this->slug = PlainText::line($this->slug);
        $this->excerpt = PlainText::lines($this->excerpt);
        $this->seoTitle = SeoFields::singleLine($this->seoTitle);
        $this->seoDescription = SeoFields::singleLine($this->seoDescription);
        $this->coverAlt = PlainText::label($this->coverAlt);
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
            // «Đăng» puts a scheduled entry up now: its end time is judged against now, not the old schedule
            $this->goingLive = $action === 'publish';
            try {
                $this->validate();
            } finally {
                $this->goingLive = false;
            }
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
        $current = null;
        $filing = $this->categoryIds;
        $orderRefused = false;

        $cover = null;
        $this->coverStoredSame = true;
        if ($this->ulid === null) {
            $this->authorize('create', Entry::class);
            // Opening the editor needs only `create`; what is saved must lie inside the member's
            // category limits (a page has no category, so a limited member writes no page).
            $this->authorize('fileUnder', [Entry::class, $this->type, $this->type === 'post' ? $this->categoryId : null]);
            // the picture first: one gone from the library since the pick makes no entry
            $cover = $this->coverToStore(null, [], true);
            if ($cover === null) {
                if ($filled) {
                    $this->slug = '';
                }
                $this->saveFailed = true;

                return null;
            }
            $fields = [
                'type' => $this->type,
                'status' => 'draft',
                'author_id' => $this->user()->id,
                'meta' => [], // a fresh instance's meta is null until set; [] keeps the merge below array-typed
            ];
            $entry = $this->type === 'page'
                ? $this->createInTree($fields)
                : Entry::query()->create($fields);
            if (! $entry instanceof Entry) {
                if ($filled) {
                    $this->slug = '';
                }

                return null;
            }
            $this->ulid = $entry->ulid;
            $this->status = $entry->status;
            // a place typed in «Thứ tự»: the new page goes there now; a refusal is said, and the page is
            // still made, at the end of the list, the typed place kept unsaved (keepRefusedPageOrder below)
            $orderRefused = $this->type === 'page' && ! $this->applyPageOrder($entry, true);
        } else {
            $entry = $this->resolveOrFail($this->ulid);
            $this->authorize('update', $entry);
            // A new «Trang cha» moves the page in the site's page tree: that tree's lock comes before any
            // row lock, as every writer of the tree takes it (MovePage), so the two can never wait on each other.
            $reparent = $this->reparenting();
            if ($reparent || $this->reordering()) {
                PageRules::lock(app(CurrentSite::class)->id());
                $this->lockMoveRows($entry);
            }
            // The entry row first, as every entry action takes it, before the translation is
            // written: a save that changes no shared field writes nothing to the entry row, and a
            // check of the search words running meanwhile must wait for it.
            // The locked row's values replace the ones read before the wait (a publish or a meta change
            // committed meanwhile is kept, not written over), without a second read.
            $locked = Entry::withTrashed()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $entry->setRawAttributes($locked->getAttributes(), true);

            // A post approved for later goes live as it was approved: nothing is written to it until «Hủy hẹn giờ».
            if ($entry->approvedForLater()) {
                if ($filled) {
                    $this->slug = '';
                }
                $this->addError('workflow', __('entries.err_approved_later'));

                return null;
            }

            // Live content whose edits this member makes into its pending change, judged now, under the lock
            // (SaveEntryDraft::holdsBack). The change this form read must still be the change: another
            // save, a move or its application came first otherwise, and nothing is written.
            $this->changeMemo = false;
            $held = app(SaveEntryDraft::class)->holdsBack($entry, $this->user());
            if ($held && $this->pendingChange($entry)?->version() !== $this->changeSeen) {
                if ($filled) {
                    $this->slug = '';
                }
                $this->changeRefused('change_stale');

                return null;
            }

            // Ask before writing over someone else's save. Every writer of these values takes the
            // entry's row first, so what is read now stays as it is until this save commits. The
            // stored values decide, never a timestamp: a status change, a category moved or
            // renamed, a cover's content mark or a view count changes none of them.
            $stored = $this->storedNow($entry);
            if ($this->storedPrint !== []) {
                // a form opened before «Mô tả ảnh» existed read it as empty (and so did a question it asked then)
                $this->storedPrint += ['cover_alt' => md5('')];
            }
            if ($this->overwrite !== null) {
                $this->overwrite += ['cover_alt' => md5('')];
            }
            // a form opened before «Mẫu hiển thị», «Ngày đăng hiện trên bài» and «Thông tin tuyển dụng» could not change them: read as stored
            $later = array_intersect_key($stored['print'], ['template' => true, 'display_date' => true, 'job' => true]);
            if ($this->storedPrint !== []) {
                $this->storedPrint += $later;
            }
            if ($this->overwrite !== null) {
                $this->overwrite += $later;
            }
            // in the stored print's order, so a field read as stored above compares as the same print
            $this->storedPrint = array_replace(array_intersect_key($stored['print'], $this->storedPrint), $this->storedPrint);
            if ($this->overwrite !== null) {
                $this->overwrite = array_replace(array_intersect_key($stored['print'], $this->overwrite), $this->overwrite);
            }
            if ($stored['print'] !== $this->storedPrint && $stored['print'] !== $this->overwrite) {
                if ($filled) {
                    $this->slug = '';
                }
                $this->askBeforeOverwrite($entry, $stored, $action, $transition);

                return null;
            }
            // a cover this form left alone keeps what is stored, unless another save changed it and this one is kept over it
            $this->coverStoredSame = ($stored['print']['cover'] ?? null) === ($this->storedPrint['cover'] ?? null);
            if ($held) {
                return $this->persistHeld($entry, $stored['translation'], $proseBody ?? $layoutBody ?? $this->blocks, $reparent, $filled);
            }
            // not held back: no change is open (one put on the website in another tab is gone from the bar too)
            $this->changeSeen = null;
            // the picture before anything is written (the page's move below included): one gone from the
            // library since the pick writes nothing
            $cover = $this->coverToStore($entry->cover_media_id, Cast::arr($entry->meta), $this->coverStoredSame);
            if ($cover === null) {
                if ($filled) {
                    $this->slug = '';
                }
                $this->saveFailed = true;

                return null;
            }
            // «Thông tin tuyển dụng» as this save writes it, onto the card stored now, before anything is written
            try {
                $this->jobWritten(Cast::arr($cover[1]));
            } catch (ValidationException $e) {
                if ($filled) {
                    $this->slug = '';
                }
                $this->saveFailed = true;

                throw $e;
            }
            // the move before anything is written, in one MovePage call (a new «Trang cha» at the place typed
            // in «Thứ tự», or the typed place alone): a refused one leaves the page as it was and the form as typed
            $placed = $reparent ? $this->moveToParent($entry, $this->typedPageIndex()) : ($this->type !== 'page' || $this->applyPageOrder($entry, false));
            if (! $placed) {
                if ($filled) {
                    $this->slug = '';
                }

                return null;
            }
            // where the post is filed, held against its stored categories under the row lock
            try {
                $filing = $this->filedUnder($entry);
            } catch (ValidationException $e) {
                if ($filled) {
                    $this->slug = '';
                }
                $this->saveFailed = true;

                throw $e;
            }
            $current = $stored['translation'] ?? $entry->translations()->make(['locale' => $this->locale]);
        }
        $this->overwrite = null;
        $this->stale = null;

        // Featured/OG image: the picked picture's row in cover_media_id (the storefront resolves it to
        // an absolute og:image + card thumbnail), the old key in meta.image_url dropped in the same save
        // (coverToStore). Any other meta key survives.
        $coverBefore = [$entry->cover_media_id, $entry->meta];
        [$coverId, $meta] = $cover;
        $entry->cover_media_id = $coverId;
        // «Thuộc tính bài»: every language's, so set in the website's default language only
        if ($this->type === 'post' && ! $this->sharedLocked() && $this->postPropsRead) {
            $meta = DisplayDate::into($meta, $this->displayDate);
            $entry->template_override = $this->formTemplate($entry->template_override);
        }
        // «Thông tin tuyển dụng»: this language's words, every language's boxes and deadline
        /** @var array<string, mixed> $meta */
        $meta = $this->jobMeta(Cast::arr($meta));
        $entry->meta = $meta;
        $sharedChanged = $entry->isDirty(['meta', 'template_override', 'cover_media_id']);
        // the same picture moved from its key to its row is no new picture: the other languages' words stay
        $coverChanged = ! EntryCover::same($coverBefore[0], $coverBefore[1], $coverId, $meta);
        $hasCover = $coverId !== null || EntryCover::metaValue($meta) !== '';
        $entry->save();

        $translation = $current ?? $entry->translations()->firstOrNew(['locale' => $this->locale]);
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
            'cover_alt' => $hasCover && $this->coverAlt !== '' ? $this->coverAlt : null,
            'status' => $this->translationStatus($entry, $translation),
        ])->save();
        if ($coverChanged) {
            // the picture is every language's: the other languages' words described the one it replaced
            // (their pages then say the picture's own words, else the title), in this save's transaction
            EntryTranslation::query()->where('entry_id', $entry->id)->where('locale', '!=', $this->locale)
                ->whereNotNull('cover_alt')->update(['cover_alt' => null]);
        }

        // Materialize block image usage into mediables so it surfaces in "where is this media used".
        app(EntryBlockMediaReconciler::class)->reconcile($entry);

        if ($this->type === 'post') {
            $before = $this->saved['categoryIds'] ?? '';
            // the card shows what was stored: a category dropped above leaves it, one kept outside the
            // member's limits stays in it
            $this->categoryIds = app(EntryCategoryWriter::class)->assign($entry, $filing, $this->user());
            $this->categoryId = $this->categoryIds[0] ?? null;
            if ($before !== json_encode($this->categoryIds)) {
                $this->choicesMemo = null;
                // a main category in another section moves the post there: its list and crumb follow
                $this->placeInList($this->sectionOf($this->categoryId !== null ? Taxonomy::query()->find($this->categoryId) : null));
            }
            // «Thẻ»: only what this form added or took out since it read the tags, filed onto the tags stored
            // now; the card then shows them as stored (one another save added among them)
            $this->fileTags($entry);
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
        // saved by the member: the link no longer follows the title
        $this->slugFresh = false;
        // The browser forgets the copy it kept of this text on the device: it is stored now.
        $this->dispatch('admin-editor-saved');
        $this->savedAt = self::iso(CarbonImmutable::now());
        $this->loadedAt = $this->savedAt;
        // What this save stored is what the next one compares against (the written model holds it).
        // The main category just written is the form's own value, so it needs no second read.
        $this->storedPrint = self::printOf($translation, $this->coverPrint($entry->cover_media_id, $entry->meta), $entry->meta, $this->type === 'post' ? $this->categoryIds : [], $entry->template_override);
        $this->coverTouched = false;
        $this->jobSaved($entry->meta);
        $this->mayPublish = $this->user()->can('publish', $entry);
        if ($wasNew) {
            $this->frameRights($entry);
        }
        $this->saved = $this->tracked();
        // what this save stored moves this language's state on the bar, and the others' when it is the source
        $this->readLangBar($entry);
        // the save stores the text; «Hẹn giờ đăng» is moved after it (save → moveSchedule), so until then it is still unsaved
        $this->saved['scheduleAt'] = $this->scheduleLoaded;
        // and so is «Gỡ khỏi website lúc»: ChangeEntryExpiry writes it after the save (applyExpiry)
        $this->saved['expiresAt'] = $this->expiresLoaded;
        // so is a status picked: only the move after the save takes it (an autosave never does), so it still counts
        $this->saved['statusTo'] = '';
        if ($orderRefused) {
            $this->keepRefusedPageOrder($entry);
        }

        if ($wasNew) {
            // The address becomes this entry's edit address, so a reload (or reopening it from the
            // browser's history) keeps editing this entry instead of starting a second one. The
            // form stays as it is: no new page, the focus and the scroll where they were.
            $this->js('history.replaceState(history.state, "", '.json_encode($this->editUrl($entry)).')');
        }

        return $entry;
    }

    /**
     * The save of a live entry whose edits are held back (see persist()): into its pending change,
     * nothing written to what visitors see. A new «Trang cha» is not something a change holds: it is
     * refused, as is a change of the other categories (a change holds the main one only).
     *
     * @param  list<array<string, mixed>>  $blocks  the body as it is stored
     *
     * @throws ValidationException
     */
    private function persistHeld(Entry $entry, ?EntryTranslation $live, array $blocks, bool $reparent, bool $filled): ?Entry
    {
        $refused = function () use ($filled): null {
            if ($filled) {
                $this->slug = '';
            }

            return null;
        };
        if ($reparent) {
            $this->addError('parentUlid', Cast::string(__('entries.ed.change_parent')));

            return $refused();
        }
        // its place among the pages beside it is the tree's, as the Trang list moves it: never held in the
        // change. Moved before the change is written and before the saved values are taken (resetSave), so
        // the field shows the place the page took. The move, the filing and the change are one transaction:
        // a change refused after the move (a link taken…) takes the move and its audit row back with it,
        // and the form shows the place as it was, the typed one still unsaved.
        $db = $entry->getConnection();
        $order = [$this->pageOrder, $this->pageOrderLoaded];
        $place = $entry->getAttributes();
        $undo = function () use ($db, $entry, $order, $place): void {
            $db->rollBack();
            [$this->pageOrder, $this->pageOrderLoaded] = $order;
            $entry->setRawAttributes($place, true);
        };
        $db->beginTransaction();
        try {
            $through = $this->type !== 'page' || $this->applyPageOrder($entry, false);
            $through = $through && $this->saveIntoChange($entry, $live, $blocks, $this->filedUnder($entry));
        } catch (Throwable $e) {
            $undo();
            if ($e instanceof ValidationException) {
                $refused();
                $this->saveFailed = true;
            }

            throw $e;
        }
        if (! $through) {
            $undo();
            $this->saveFailed = true;

            return $refused();
        }
        $db->commit();

        $this->overwrite = null;
        $this->stale = null;
        $this->saveFailed = false;
        $this->converted = false;
        $this->slugFresh = false;
        if ($this->heldSave === 'saved') {
            // the change holds the text now: the copy this device kept of it goes
            $this->dispatch('admin-editor-saved');
            $this->savedAt = self::iso(CarbonImmutable::now());
            $this->loadedAt = $this->savedAt;
            $held = $this->changeMemo instanceof EntryDraft ? ($this->changeMemo->content['shared']['meta'] ?? null) : null;
            $this->jobSaved(is_array($held) ? $held : null);
        }
        $this->resetSave();

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

    /**
     * «Hẹn giờ đăng» as the form opens: the time a scheduled entry goes live, in the website's clock; one
     * scheduled with no time yet starts on tomorrow 08:00 of that clock, as the list's «Hẹn giờ đăng» does.
     */
    private function loadSchedule(Entry $entry): void
    {
        if ($entry->status !== EntryStatus::Scheduled->value) {
            return;
        }
        $siteTime = app(SiteTime::class);
        $this->scheduleLoaded = $siteTime->forDisplay($entry->published_at)?->format('Y-m-d\TH:i') ?? '';
        $this->scheduleAt = $this->scheduleLoaded !== '' ? $this->scheduleLoaded : ScheduleInput::tomorrowEight();
    }

    /**
     * The time can be changed here: an entry scheduled, or with «Hẹn giờ đăng» picked for the next save, by a
     * member who may publish it, that no approval workflow governs (scheduling governed content is refused,
     * TransitionEntryStatus).
     */
    private function schedulable(): bool
    {
        return $this->effectiveStatus() === EntryStatus::Scheduled->value && $this->mayPublish
            && $this->governingWorkflow() === null;
    }

    /** The status the next save leaves the entry in: the one picked in «Khi lưu, bài sẽ là», else the saved one (a new entry: a draft). */
    private function effectiveStatus(): string
    {
        return $this->statusTo !== '' ? $this->statusTo : ($this->status ?? EntryStatus::Draft->value);
    }

    /**
     * Why the time in the field cannot be kept (the list's two sentences), or null: an empty field always,
     * a time changed from the one the entry was scheduled for when it is not after now (ScheduleInput, the
     * list's own sentences).
     */
    private function scheduleError(): ?string
    {
        if (trim($this->scheduleAt) === '') {
            return Cast::string(__('entries.sched_err_empty'));
        }

        return $this->scheduleAt === $this->scheduleLoaded ? null : ScheduleInput::error($this->scheduleAt);
    }

    /** Before a save: a time that cannot be kept stops it, says why under the field and puts the cursor there. */
    private function refuseSchedule(): bool
    {
        $this->resetErrorBag('scheduleAt');
        $why = $this->scheduleError();
        if ($why === null) {
            return false;
        }
        $this->addError('scheduleAt', $why);
        $this->js("document.getElementById('entry-sched-at')?.focus()");

        return true;
    }

    /**
     * After the save: a changed time is set through TransitionEntryStatus (the publish right on the locked
     * row, a time after now). False, the reason under the field, when it refuses.
     */
    private function moveSchedule(Entry $entry): bool
    {
        if ($this->scheduleAt === $this->scheduleLoaded) {
            return true;
        }
        // its status changed since the form opened (published, a draft…): the time no longer applies, never re-scheduled
        // from here; the form takes the stored status, so the field and its unsaved count go
        if ($entry->status !== EntryStatus::Scheduled->value) {
            $this->status = $entry->status;
            $this->scheduleAt = $this->scheduleLoaded;
            $this->saved['scheduleAt'] = $this->scheduleLoaded;
            $this->dispatch('bs-toast', text: Cast::string(__('entries.ed.sched_gone', ['unit' => $this->unit()])), stay: true, close: Cast::string(__('admin.close')));

            return false;
        }
        $why = null;
        try {
            $when = ScheduleInput::parse($this->scheduleAt) ?? throw new SchedulePastException;
            app(TransitionEntryStatus::class)->handle($entry, EntryStatus::Scheduled, $this->user(), $when);
        } catch (SchedulePastException) {
            $why = Cast::string(__('entries.sched_err_past'));   // the time passed while the save waited
        } catch (WorkflowGovernedException) {
            $why = Cast::string(__('entries.workflow_governed'));
        } catch (HomePageLockedException|ExpiredContentException $refused) {
            $why = $refused->getMessage();
        } catch (AuthorizationException) {
            $why = EntryRefusal::for($this->user(), $entry, 'status');
        }
        if ($why !== null) {
            $this->saved['scheduleAt'] = $this->scheduleLoaded;
            $this->addError('scheduleAt', $why);
            $this->js("document.getElementById('entry-sched-at')?.focus()");

            return false;
        }
        $this->scheduleLoaded = $this->scheduleAt;
        $this->saved['scheduleAt'] = $this->scheduleAt;

        return true;
    }

    /**
     * «Gỡ khỏi website lúc» (board 07, the «Đăng» card): none on the Home page (it takes no end time); read
     * only for a member who may not change the status, or in a language other than the website's own. The
     * sentence under it says what the time does, or that the entry came down at it.
     *
     * @return array{zone: string, hint: string, editable: bool}|null
     */
    private function expiryPanel(): ?array
    {
        if ($this->homePage) {
            return null;
        }
        $time = app(SiteTime::class);
        $at = ExpiryTime::parse($this->expiresAt, $time->zone());
        $shown = $at !== null ? $at->setTimezone($time->zone())->format('H:i d/m/Y') : '';
        $past = $at !== null && $at <= CarbonImmutable::now();
        $doc = $this->docNoun();
        $hint = match (true) {
            $at === null || ($past && $this->status !== EntryStatus::Archived->value) => __('entries.ed.exp_hint', ['doc' => $doc]),
            $past => __('entries.ed.exp_hint_done', ['doc' => $doc, 'time' => $shown]),
            default => __('entries.ed.exp_hint_at', ['doc' => $doc, 'time' => $shown]),
        };

        return [
            'zone' => Cast::string(__('entries.ed.exp_zone', ['zone' => app(SiteTime::class)->offsetLabel()])),
            'hint' => Cast::string($hint),
            'editable' => $this->expiryEditable(),
        ];
    }

    /** The «Ngày đăng» line of a post's «Đăng» card: the shown day when one is set, the real (or scheduled) day beside it. */
    private function pdLine(): string
    {
        $real = $this->publishedDay;
        $scheduled = $real === '' && $this->effectiveStatus() === EntryStatus::Scheduled->value
            ? (ScheduleInput::parse($this->scheduleAt) !== null ? substr($this->scheduleAt, 8, 2).'/'.substr($this->scheduleAt, 5, 2).'/'.substr($this->scheduleAt, 0, 4) : '')
            : '';
        $shown = DisplayDate::valid($this->displayDate) ? CarbonImmutable::createFromFormat('!Y-m-d', $this->displayDate)?->format('d/m/Y') ?? '' : '';
        $doc = $this->docNoun();

        return Cast::string(match (true) {
            $shown !== '' && ($real !== '' || $scheduled !== '') => __('entries.ed.pd_line_shown_real', ['doc' => $doc, 'shown' => $shown, 'real' => $real !== '' ? $real : $scheduled]),
            $shown !== '' => __('entries.ed.pd_line_shown', ['doc' => $doc, 'shown' => $shown]),
            $real !== '' => __('entries.ed.pd_line_real', ['real' => $real]),
            $scheduled !== '' => __('entries.ed.pd_line_sched', ['real' => $scheduled]),
            default => __('entries.ed.pd_line_none'),
        });
    }

    /**
     * The «Hẹn giờ đăng» field of the "Đăng" card, or null when the time cannot be changed here.
     *
     * @return array{zone: string, note: string}|null
     */
    private function schedulePanel(?Workflow $workflow): ?array
    {
        if ($this->effectiveStatus() !== EntryStatus::Scheduled->value || ! $this->mayPublish || $workflow !== null) {
            return null;
        }
        $zone = app(SiteTime::class)->offsetLabel();
        $at = preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/', $this->scheduleAt, $m) === 1
            ? $m[4].':'.$m[5].' '.$m[3].'/'.$m[2].'/'.$m[1]
            : Cast::string(__('entries.ed.sched_when_set'));

        return [
            'zone' => Cast::string(__('entries.ed.sched_zone', ['zone' => $zone])),
            'note' => Cast::string(__('entries.ed.sched_note', ['unit' => Str::ucfirst($this->unit()), 'time' => $at])),
        ];
    }

    /**
     * «Khi lưu, bài sẽ là» (board 07, the "Đăng" card; none where an approval workflow governs the entry, whose
     * steps replace it): the statuses this member may pick, in the board's order — «Bản nháp», «Gửi duyệt»
     * (only for a member who may not publish), «Hẹn giờ đăng», «Công khai», and «Gỡ khỏi website» while the
     * entry is live. A status they may not reach stays, locked, with the reason; so does every other status
     * of the live Home page, and every status in a language other than the website's own (it is shared).
     *
     * @return list<array{key: string, short: string, sub: string, why: string}>|null
     */
    private function statusOptions(): ?array
    {
        if ($this->governingWorkflow() !== null) {
            return null;
        }
        $user = $this->user();
        $saved = $this->status ?? EntryStatus::Draft->value;
        $publish = $this->mayPublish;
        // Without the publish right only a draft moves (PublishEntry sends a draft for review; every other move
        // needs the publish right on the row, TransitionEntryStatus): from any other status both stay locked.
        $stuck = ! $publish && $saved !== EntryStatus::Draft->value ? Cast::string(__('entries.ed.st_why_move')) : '';
        $options = [['key' => EntryStatus::Draft->value, 'sub' => Cast::string(__('entries.ed.st_draft_sub')), 'why' => $stuck]];
        if (! $publish) {
            $options[] = ['key' => EntryStatus::Pending->value, 'sub' => '', 'why' => $user->can('entries.submit') ? $stuck : Cast::string(__('entries.ed.st_why_submit'))];
        }
        $options[] = ['key' => EntryStatus::Scheduled->value, 'sub' => '', 'why' => $publish ? '' : Cast::string(__('entries.ed.st_why_schedule'))];
        $options[] = ['key' => EntryStatus::Published->value, 'sub' => '', 'why' => $publish ? '' : Cast::string(__('entries.ed.st_why_publish'))];
        if ($saved === EntryStatus::Published->value) {
            $options[] = ['key' => EntryStatus::Archived->value, 'sub' => Cast::string(__('entries.status_menu_archived_hint')), 'why' => $publish ? '' : Cast::string(__('entries.ed.st_why_archive'))];
        }
        $homeLive = $this->homePage && $saved === EntryStatus::Published->value;
        $shared = $this->locale !== app(SiteLocale::class)->default()
            ? Cast::string(__('entries.ed.shared_guard', ['lang' => Locales::label(app(SiteLocale::class)->default())]))
            : null;

        return array_map(function (array $option) use ($saved, $homeLive, $shared): array {
            $why = $option['key'] === $saved ? '' : $option['why'];
            if ($option['key'] !== $saved && $homeLive) {
                $why = Cast::string(__('entries.home_trash_why'));
            }
            if ($shared !== null && $option['key'] !== $saved) {
                $why = $shared;
            }

            return ['key' => $option['key'], 'short' => Cast::string(__('entries.ed.st_'.$option['key'])), 'sub' => $why !== '' ? $why : $option['sub'], 'why' => $why];
        }, $options);
    }

    /**
     * The words for a status on the chooser's button: its option's, or (a status with no option for this member,
     * such as a publisher's entry waiting for review, or one left in a retired status) the status pill's.
     *
     * @param  list<array{key: string, short: string, sub: string, why: string}>  $options
     */
    private function statusShort(string $status, array $options): string
    {
        foreach ($options as $option) {
            if ($option['key'] === $status) {
                return $option['short'];
            }
        }
        $key = 'entries.ed.pill_'.$status;

        return Lang::has($key) ? Cast::string(__($key)) : $status;
    }

    /** The status picked for the next save when it can still be taken (offered, unlocked, not the saved one); otherwise null, the pick dropped. */
    private function statusChoice(): ?string
    {
        if ($this->statusTo === '' || $this->statusTo === ($this->status ?? EntryStatus::Draft->value)) {
            $this->statusTo = '';

            return null;
        }
        foreach ($this->statusOptions() ?? [] as $option) {
            if ($option['key'] === $this->statusTo && $option['why'] === '') {
                return $option['key'];
            }
        }
        $this->statusTo = '';

        return null;
    }

    /**
     * After the save: the entry moved to the picked status through TransitionEntryStatus (the publish right on
     * the locked row; a time after now; never the live Home page). False, the reason said, when it refuses: the
     * text is saved, the status kept, the pick left for another try.
     */
    private function moveStatus(Entry $entry, string $to): bool
    {
        $transition = app(TransitionEntryStatus::class);
        $field = 'statusTo';
        $why = null;
        try {
            if ($to === EntryStatus::Scheduled->value) {
                $field = 'scheduleAt';
                $transition->handle($entry, EntryStatus::Scheduled, $this->user(), ScheduleInput::parse($this->scheduleAt) ?? throw new SchedulePastException);
            } elseif ($to === EntryStatus::Draft->value) {
                // a schedule taken back drops its time too; anything else (moved meanwhile) is taken to a draft
                if ($entry->status !== EntryStatus::Scheduled->value || ! $transition->unschedule($entry, $this->user())) {
                    $transition->handle($entry, EntryStatus::Draft, $this->user());
                }
            } else {
                $transition->handle($entry, EntryStatus::Archived, $this->user());
            }
        } catch (SchedulePastException) {
            $why = Cast::string(__('entries.sched_err_past'));   // the time passed while the save waited
        } catch (WorkflowGovernedException) {
            $why = Cast::string(__('entries.workflow_governed'));
        } catch (HomePageLockedException|ExpiredContentException $refused) {
            $why = $refused->getMessage();
        } catch (EntryDraftRejectedException $refused) {
            $why = $refused->userMessage();   // a schedule while a pending change is open
        } catch (AuthorizationException) {
            $why = EntryRefusal::for($this->user(), $entry, 'status');
        }
        if ($why !== null) {
            $this->addError($field, $why);
            if ($field === 'scheduleAt') {
                $this->js("document.getElementById('entry-sched-at')?.focus()");
            } else {
                $this->dispatch('bs-toast', text: $why, stay: true, close: Cast::string(__('admin.close')));
            }

            return false;
        }
        $entry->refresh();
        $this->takeStatus($entry);
        $this->folded = $this->changeFolded($entry);

        return true;
    }

    /** The form takes the stored status: no pick left, the live address, and «Hẹn giờ đăng» as stored (none unless scheduled). */
    private function takeStatus(Entry $entry): void
    {
        $this->status = $entry->status;
        $this->liveAddress = $this->routedAddress($entry->id);
        $this->scheduleAt = '';
        $this->scheduleLoaded = '';
        $this->loadSchedule($entry);
        $this->saved['scheduleAt'] = $this->scheduleLoaded;
        $this->statusTo = '';
        $this->saved['statusTo'] = '';
    }

    /** After «Đăng» / «Gửi duyệt»: the pick is used up, and «Hẹn giờ đăng» goes unless the entry is still scheduled. */
    private function forgetChoice(): void
    {
        $this->statusTo = '';
        $this->saved['statusTo'] = '';
        if ($this->status !== EntryStatus::Scheduled->value) {
            $this->scheduleAt = '';
            $this->scheduleLoaded = '';
            $this->saved['scheduleAt'] = '';
        }
    }

    /** How many of the fields a save writes differ from the saved entry (the status pick and its time are not text). */
    private function textChanges(): int
    {
        $count = $this->blocksHash() !== ($this->saved['blocks'] ?? '') ? 1 : 0;
        foreach ($this->tracked() as $field => $value) {
            if (! in_array($field, ['blocks', 'scheduleAt', 'statusTo'], true) && ($this->saved[$field] ?? '') !== $value) {
                $count++;
            }
        }

        return $count;
    }

    /** A new entry becomes a draft by itself only once its title, short description or body holds text (board 07). */
    private function worthKeeping(): bool
    {
        if (trim($this->title) !== '' || trim($this->excerpt) !== '') {
            return true;
        }
        if ($this->prose) {
            return trim(html_entity_decode(strip_tags($this->bodyHtml), ENT_QUOTES | ENT_HTML5)) !== '';
        }

        return $this->blocks !== [];
    }

    /**
     * The "Đăng" card's «Khi lưu, bài sẽ là», or null where it is not drawn: the label, the options, the one
     * picked, and the line under it (the saved status's meaning, or «Chưa áp dụng…» while a pick waits).
     *
     * @return array{label: string, options: list<array{key: string, short: string, sub: string, why: string}>, current: string, currentShort: string, pending: string|null, vis: array{icon: string, text: string}, homeWhy: string|null}|null
     */
    private function statusPanel(): ?array
    {
        $options = $this->statusOptions();
        if ($options === null) {
            return null;
        }
        $saved = $this->status ?? EntryStatus::Draft->value;
        $current = $this->effectiveStatus();
        $icons = ['published' => 'ri-global', 'draft' => 'ri-draft', 'pending' => 'ri-inbox-unarchive', 'scheduled' => 'ri-time', 'archived' => 'ri-archive'];

        return [
            'label' => Cast::string(__($this->type === 'page' ? 'entries.ed.st_label_page' : 'entries.ed.st_label_post')),
            'options' => $options,
            'current' => $current,
            'currentShort' => $this->statusShort($current, $options),
            'pending' => $current !== $saved ? Cast::string(__('entries.ed.st_not_yet', ['status' => $this->statusShort($current, $options)])) : null,
            'vis' => ['icon' => $icons[$saved] ?? 'ri-draft', 'text' => Cast::string(__('entries.ed.vis_'.(isset($icons[$saved]) ? $saved : 'draft')))],
            'homeWhy' => $this->homePage && $saved === EntryStatus::Published->value ? Cast::string(__('entries.home_trash_why')) : null,
        ];
    }

    /**
     * The band of a scheduled entry (board 07): when it goes live, and «Hủy hẹn giờ» with the question the list
     * asks first. Only for a member who may publish it, where no approval workflow governs it; under one, for
     * a post approved for later, to a member who could approve it again (it goes back to waiting at its stage).
     *
     * @return array{head: string, sub: string, ask: array{title: string, text: string, confirm: string, cancel: string}}|null
     */
    private function scheduleBand(?Workflow $workflow, ?Entry $entry): ?array
    {
        if ($this->ulid === null || $this->status !== EntryStatus::Scheduled->value) {
            return null;
        }
        if ($workflow === null) {
            if (! $this->mayPublish) {
                return null;
            }
            $step = Cast::string(__('entries.status_draft'));
        } else {
            $later = app(ApproveEntryForLater::class);
            if (! $entry instanceof Entry || ! $later->mayCancel($entry, $this->user(), $workflow)) {
                return null;
            }
            $step = $later->stepAfterCancel($entry, $workflow);
        }
        $at = preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/', $this->scheduleLoaded, $m) === 1 ? $m : null;
        $time = $at !== null ? $at[4].':'.$at[5] : '';
        $date = $at !== null ? $at[3].'/'.$at[2].'/'.$at[1] : '';
        $unit = $this->unit();

        return [
            'head' => Cast::string(__('entries.ed.sched_band', ['unit' => Str::ucfirst($unit), 'time' => $at !== null ? $time.' '.$date : __('entries.ed.sched_when_set')])),
            'sub' => Cast::string(__('entries.ed.sched_band_sub', ['unit' => $unit, 'step' => $step])),
            'ask' => [
                'title' => Cast::string(__('entries.unsched_title')),
                'text' => Cast::string(__('entries.unsched_text', ['noun' => $unit, 'time' => $time, 'date' => $date, 'step' => $step])),
                'confirm' => Cast::string(__('entries.unsched')),
                'cancel' => Cast::string(__('entries.unsched_keep')),
            ],
        ];
    }

    private function resolveSection(?string $key): ?ContentSection
    {
        if ($key === null || $key === '') {
            return null;
        }

        return ContentSection::query()->where('key', $key)->first();
    }

    /**
     * «Thêm» / Enter in the «Thẻ» card: the names typed (several at once, split at commas) join the
     * post's tags, each once (one address, one tag). Stored on save; a name no tag has yet makes a
     * tag then, which needs nothing beyond the right to edit the post.
     */
    public function addTag(): void
    {
        $this->authorizeEditing();
        if ($this->type !== 'post') {
            return;
        }
        $this->resetErrorBag(['tagDraft', 'tags', 'tags.*']);
        $have = [];
        foreach ($this->tags as $name) {
            $have[EntryTags::slugOf($name, $this->locale)] = true;
        }
        $tags = app(EntryTags::class);
        $added = $this->tags;
        foreach (explode(',', $this->tagDraft) as $typed) {
            if (trim($typed) === '') {
                continue;
            }
            $name = EntryTags::clean($typed, $this->locale);
            if ($name === null) {
                $this->addError('tagDraft', Cast::string(__('entries.ed.tag_invalid', ['max' => EntryTags::NAME_MAX])));

                return;
            }
            $slug = EntryTags::slugOf($name, $this->locale);
            if (isset($have[$slug])) {
                continue;
            }
            if (count($added) >= EntryTags::MAX_TAGS) {
                $this->addError('tagDraft', Cast::string(__('entries.ed.tag_full', ['max' => EntryTags::MAX_TAGS])));

                return;
            }
            if (! $tags->addressFree($name, $this->locale)) {
                $this->addError('tagDraft', Cast::string(__('entries.ed.tag_taken', ['name' => $name])));

                return;
            }
            $have[$slug] = true;
            $added[] = $name;
        }
        $this->tags = $added;
        $this->tagDraft = '';
    }

    /** The «Bỏ thẻ» button on a chip: the tag leaves the post on save (the tag itself stays). */
    public function removeTag(int $index): void
    {
        $this->authorizeEditing();
        if (! array_key_exists($index, $this->tags)) {
            return;
        }
        $tags = $this->tags;
        unset($tags[$index]);
        $this->tags = array_values($tags);
    }

    /**
     * The save's «Thẻ» write (EntryTags::apply). A name it could not file (its address became another
     * page's, or the post reached its limit through another save) is not lost: it goes back into the
     * card's box with the reason, and the rest of the save stands, so a save by itself does not try
     * it again and again.
     */
    private function fileTags(Entry $entry): void
    {
        ['tags' => $stored, 'refused' => $refused] = app(EntryTags::class)->apply($entry, $this->locale, $this->tagsLoaded, $this->tags);
        $this->tags = $stored;
        $this->tagsLoaded = $stored;
        if ($refused === []) {
            return;
        }
        $names = array_column($refused, 'name');
        $draft = trim($this->tagDraft);
        $this->tagDraft = implode(', ', $draft === '' ? $names : [...$names, $draft]);
        $first = $refused[0];
        $this->addError('tagDraft', Cast::string($first['why'] === 'full'
            ? __('entries.ed.tag_full', ['max' => EntryTags::MAX_TAGS])
            : __('entries.ed.tag_taken', ['name' => $first['name']])));
    }

    /**
     * «Gỡ khỏi website lúc», as the field is left: the reason under it when the time is past or not after
     * the scheduled publish time; nothing for a time left as it was stored. A member who may not change
     * the entry's status, or a language other than the website's own (the end time is every language's),
     * gets the stored time back.
     */
    public function updatedExpiresAt(): void
    {
        $this->authorizeEditing();
        $this->resetErrorBag('expiresAt');
        if (! $this->expiryEditable()) {
            $this->expiresAt = $this->expiresLoaded;

            return;
        }
        if ($this->expiresAt === $this->expiresLoaded) {
            return;
        }
        $zone = app(SiteTime::class)->zone();
        $at = ExpiryTime::parse($this->expiresAt, $zone);
        $why = trim($this->expiresAt) === '' ? null
            : ($at === null ? Cast::string(__('entries.expiry_invalid')) : ExpiryTime::error($at, $this->formPublishAt(), $zone));
        if ($why !== null) {
            $this->addError('expiresAt', $why);
        }
    }

    /** «Bỏ giờ gỡ»: the field empties (saved with the next save) and the message offers «Hoàn tác». */
    public function clearExpiry(): void
    {
        $this->authorizeEditing();
        if (! $this->expiryEditable() || $this->expiresAt === '') {
            return;
        }
        $this->resetErrorBag('expiresAt');
        $this->formSay(Cast::string(__('entries.ed.exp_cleared', ['doc' => $this->docNoun()])), 'expiresAt', $this->expiresAt);
        $this->expiresAt = '';
    }

    /** «Dùng ngày đăng thật»: the shown day empties (saved with the next save) and the message offers «Hoàn tác». */
    public function useRealDate(): void
    {
        $this->authorizeEditing();
        if ($this->type !== 'post' || $this->sharedLocked() || $this->displayDate === '') {
            return;
        }
        $this->resetErrorBag('displayDate');
        $this->formSay(Cast::string(__('entries.ed.pd_used_real')), 'displayDate', $this->displayDate);
        $this->displayDate = '';
    }

    /** «Hoàn tác» on the «Đăng» card's message: the value it emptied comes back into the form. */
    public function undoFormClear(): void
    {
        $this->authorizeEditing();
        $undo = $this->formUndo;
        $this->formToast = '';
        $this->formUndo = null;
        if ($undo === null) {
            return;
        }
        if ($undo['field'] === 'expiresAt' && $this->expiryEditable()) {
            $this->expiresAt = $undo['value'];
        } elseif ($undo['field'] === 'displayDate' && $this->type === 'post' && ! $this->sharedLocked()) {
            $this->displayDate = $undo['value'];
        } elseif ($undo['field'] === 'jobCard') {
            $this->jobUndo($undo['value']);
        }
    }

    /** Close the «Đăng» card's message the member (or its timer) names; a newer one stays. */
    public function closeFormToast(int $seq): void
    {
        $this->authorizeEditing();
        if ($seq === $this->formToastSeq) {
            $this->formToast = '';
            $this->formUndo = null;
        }
    }

    private function formSay(string $text, string $field, string $value): void
    {
        $this->formToast = $text;
        $this->formToastSeq++;
        $this->formUndo = ['field' => $field, 'value' => $value];
    }

    /** The end time and the real publish day as stored, in the website's clock. */
    private function loadDates(Entry $entry): void
    {
        $time = app(SiteTime::class);
        $this->expiresAt = $this->expiresLoaded = $time->forDisplay($entry->expires_at)?->format(ExpiryTime::FORMAT) ?? '';
        $live = in_array($entry->status, [EntryStatus::Published->value, EntryStatus::Archived->value], true);
        $this->publishedDay = $live ? ($time->forDisplay($entry->published_at)?->format('d/m/Y') ?? '') : '';
    }

    /** Whether this member may set the end time here: the right to change the status, in the website's own language, not on the Home page. */
    private function expiryEditable(): bool
    {
        return $this->mayPublish && ! $this->sharedLocked() && ! $this->homePage;
    }

    /**
     * The publish time the end time must come after, as the entry will be after this save: the schedule picked
     * in the form when it is (or will be) scheduled; none for a draft (taking a schedule back drops its time)
     * nor for a scheduled entry put up now, by «Công khai» or by «Đăng» ($goingLive): PublishEntry writes now
     * for a schedule still to come, and the end time must come after now anyway.
     */
    private function formPublishAt(): ?CarbonImmutable
    {
        return ! $this->goingLive && $this->effectiveStatus() === EntryStatus::Scheduled->value ? ScheduleInput::parse($this->scheduleAt) : null;
    }

    /**
     * Writes a changed «Gỡ khỏi website lúc» through ChangeEntryExpiry after the save; a refusal is said
     * under the field and stops what follows. A time still to come but not after the stored schedule waits
     * for the move this save makes ($waits, the caller calling again after it) when that move frees it: a
     * schedule moved ($mayWait), a scheduled entry put up now ($mayWait: PublishEntry writes now for its
     * schedule), or a schedule taken back to a draft ($moveTo), which drops its time. A pair still wrong
     * after the move is refused before anything moves.
     */
    private function applyExpiry(Entry $entry, bool $mayWait = false, bool &$waits = false, ?string $moveTo = null): bool
    {
        if ($this->expiresAt === $this->expiresLoaded || ! $this->expiryEditable()) {
            return true;
        }
        $at = ExpiryTime::parse($this->expiresAt, app(SiteTime::class)->zone());
        $freed = $mayWait || ($moveTo === EntryStatus::Draft->value && $entry->status === EntryStatus::Scheduled->value);
        if ($freed && $at !== null && $at > now() && $entry->published_at !== null && $entry->published_at > now() && $at <= $entry->published_at) {
            $waits = true;

            return true;
        }
        try {
            app(ChangeEntryExpiry::class)->handle($entry, $at, $this->user());
        } catch (ValidationException $e) {
            $this->addError('expiresAt', Cast::string($e->validator->errors()->first('expiresAt')));

            return false;
        } catch (HomePageLockedException $refused) {
            $this->addError('expiresAt', $refused->getMessage());

            return false;
        } catch (AuthorizationException) {
            $this->addError('expiresAt', EntryRefusal::for($this->user(), $entry, 'status'));

            return false;
        }
        $this->expiresLoaded = $this->expiresAt;
        $this->saved['expiresAt'] = $this->expiresLoaded;

        return true;
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

    /**
     * The entry the form opens, read with its place among the live pages beside it (loadParent): a page's
     * «Thứ tự» costs the open no query of its own. Counted in the database for a page only (a post's is null).
     */
    private function openEntry(string $ulid): Entry
    {
        return Entry::query()->where('ulid', $ulid)->select('entries.*')->selectRaw(
            "(select count(*) from entries as beside where entries.type = 'page' and beside.site_id = entries.site_id"
            .' and beside.deleted_at is null and beside.type = \'page\''
            .' and (beside.parent_id = entries.parent_id or (beside.parent_id is null and entries.parent_id is null))'
            .' and (beside.position < entries.position or (beside.position = entries.position and beside.id < entries.id))) as page_place_before'
        )->firstOrFail();
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
}
