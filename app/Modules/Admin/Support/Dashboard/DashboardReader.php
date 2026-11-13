<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Dashboard;

use App\Modules\Admin\Support\Entries\ReviewWaiting;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteIdentity;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Domains\DomainGroup;
use App\Modules\Tenancy\Domains\DomainGroups;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Services\ReviewQueue;
use App\Modules\Workflow\Services\WorkflowTrail;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * What the "Tổng quan" screen reads, card by card, from data the platform already keeps. Every
 * read is bounded: lists take the few newest rows through an index that leads with site_id, and
 * a number is counted only up to CAP + 1 ("99+"), so the first screen after signing in costs the
 * same on a website with ten posts as on one with ten million. Nothing here counts a whole table.
 *
 * Runs in the request's site context: every model is BelongsToSite (RLS underneath); `domains`,
 * the one global table, is read by DomainGroups, which names the site. Rows are addressed by ulid.
 * The caller decides who sees which card (permissions, the site's modules); a row's own action
 * (edit or only look) is asked of EntryPolicy here, per row.
 */
final class DashboardReader
{
    /**
     * The section names, read once per reader (one reader serves one render; it is never shared).
     *
     * @var array<string, string>|null
     */
    private ?array $sectionNames = null;

    /**
     * The site's sections (key, name, type, category), read once per reader.
     *
     * @var EloquentCollection<int, ContentSection>|null
     */
    private ?EloquentCollection $sectionRows = null;

    /**
     * The active approval flows with their stages and moves, read once per reader.
     *
     * @var EloquentCollection<int, Workflow>|null
     */
    private ?EloquentCollection $workflows = null;

    /** A count stops here: one more row means "more than this". */
    public const int CAP = 99;

    /** Rows a list card shows. */
    public const int ROWS = 5;

    /** Rows of the review tabs, and of the scheduled tab (design: 3, and 6 for "Đã hẹn giờ"). */
    public const int REVIEW_ROWS = 3;

    public const int SCHEDULED_ROWS = 6;

    /**
     * A member's newest drafts looked at for "Bị trả lại": the drafts come off the author index
     * (entries_post_author_idx, migration 2040), and their last moves off the entry-history index
     * (site_id, entry_id, created_at) of workflow_log (migration 2015, line 111). No index serves
     * "the moves that sent this member's posts back" (workflow_log has no author column), so the
     * read stays bounded: a return older than the member's 50 newest drafts is not listed.
     */
    private const int DRAFTS_READ = 50;

    private const array ENTRY_COLUMNS = ['id', 'ulid', 'site_id', 'type', 'status', 'workflow_state', 'author_id', 'assigned_to', 'published_at', 'updated_at', 'deleted_at'];

    /**
     * Leads nobody has opened yet: how many (up to CAP + 1) and the newest one.
     *
     * @return array{count: int, latest: array{name: string, form: string, at: DateTimeInterface|null}|null}
     */
    public function unreadLeads(): array
    {
        $rows = FormSubmission::query()->where('status', 'new')
            ->orderByDesc('created_at')->orderByDesc('ulid')
            ->limit(self::CAP + 1)
            ->get(['id', 'form_id', 'name', 'created_at']);

        $first = $rows->first();
        if (! $first instanceof FormSubmission) {
            return ['count' => 0, 'latest' => null];
        }

        $names = Cast::arr(Form::query()->whereKey($first->form_id)->value('name'));
        $locale = app()->getLocale();
        $form = Cast::string($names[$locale] ?? $names['vi'] ?? (array_values($names)[0] ?? ''));
        $at = $first->getAttribute('created_at');

        return ['count' => $rows->count(), 'latest' => [
            'name' => trim(Cast::string($first->getAttribute('name'))),
            'form' => $form,
            'at' => $at instanceof DateTimeInterface ? $at : null,
        ]];
    }

    /**
     * The website's own domain names that need the owner: not pointing here yet, or stopped
     * pointing (with the day after which visitors no longer reach the site).
     *
     * @param  list<DomainGroup>  $groups
     * @return list<array{name: string, state: string, by: DateTimeInterface|null}>
     */
    public function domainProblems(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            if (! $group->platform && in_array($group->state, ['wait', 'failing', 'expiring', 'lost'], true)) {
                $out[] = ['name' => $group->name, 'state' => $group->state, 'by' => $group->until ?? $group->removeOn];
            }
        }

        return $out;
    }

    /**
     * "Bắt đầu với website của bạn": each first task the member may do, and whether the site
     * already shows it done. Read from what the site holds — the business identity (phone and
     * address, logo), the home page's saved versions, a domain of its own that points here.
     *
     * @param  list<DomainGroup>|null  $groups  the site's names, when the member may manage them
     * @return list<array{key: string, done: bool, url: string}>
     */
    public function startSteps(User $user, ?array $groups): array
    {
        $steps = [];
        if ($user->can('settings.manage')) {
            $identity = app(SiteIdentity::class)->for(app(SiteLocale::class)->default()) ?? [];
            $phone = trim(Cast::string($identity['phone'] ?? null)) !== '' || trim(Cast::string($identity['hotline'] ?? null)) !== '';
            $address = trim(Cast::string($identity['address'] ?? null)) !== '';
            $logo = isset(Cast::arr($identity['media_ids'] ?? null)['logo']) || isset(Cast::arr($identity['legacy_images'] ?? null)['logo']);
            $steps[] = ['key' => 'general', 'done' => $phone && $address, 'url' => route('admin.settings.general')];
            $steps[] = ['key' => 'logo', 'done' => $logo, 'url' => route('admin.settings.seo')];
        }

        $home = $this->homePage();
        if ($home instanceof Entry && Gate::forUser($user)->allows('update', $home)) {
            $steps[] = ['key' => 'home', 'done' => (bool) $home->getAttribute('revisions_exists'), 'url' => route('admin.entries.edit', ['ulid' => $home->ulid])];
        }

        if ($groups !== null) {
            $own = false;
            foreach ($groups as $group) {
                $own = $own || (! $group->platform && in_array($group->state, ['verified', 'issuing', 'https_err', 'active', 'failing', 'expiring'], true));
            }
            $steps[] = ['key' => 'domain', 'done' => $own, 'url' => route('admin.settings.domains')];
        }

        return $steps;
    }

    /**
     * The "Duyệt bài" card: what waits for this reviewer (all of it, and what is handed to them)
     * and what is scheduled to go live.
     *
     * @return array{
     *     queue: list<array<string, mixed>>, queue_n: int, queue_more: bool,
     *     mine: list<array<string, mixed>>, mine_n: int,
     *     sched: list<array<string, mixed>>, sched_n: int, oldest: string|null,
     * }
     */
    public function review(User $user): array
    {
        // What waits for this reviewer, filtered per (section, stage) in SQL before the cap; a
        // count past CAP reads "99+". Only what the rows show is loaded.
        $reader = app(ReviewWaiting::class);
        $stages = app(ReviewQueue::class)->actionableStagesIn($this->workflows(), $user);
        $relations = $this->rowRelations();
        ['entries' => $waiting, 'full' => $full] = $reader->read($user, self::CAP, $relations, self::ENTRY_COLUMNS, $stages);
        $mine = $waiting->filter(fn (Entry $entry): bool => $entry->assigned_to === $user->id)->values();
        $mineMore = false;
        if ($full) {
            // the queue was cut at CAP: what is handed to this reviewer is read on its own
            ['entries' => $mine, 'full' => $mineMore] = $reader->read($user, self::CAP, $relations, self::ENTRY_COLUMNS, $stages, $user->id);
        }
        $sched = $this->scheduled();
        $labels = $this->stageLabels();
        $oldest = $waiting->last();

        return [
            'queue' => $this->rows($waiting->take(self::REVIEW_ROWS), $user, $labels),
            'queue_n' => $full ? self::CAP + 1 : $waiting->count(),
            'queue_more' => $full,
            'mine' => $this->rows($mine->take(self::REVIEW_ROWS), $user, $labels),
            'mine_n' => $mineMore ? self::CAP + 1 : $mine->count(),
            'sched' => $this->rows($sched->take(self::SCHEDULED_ROWS), $user, $labels),
            'sched_n' => $sched->count(),
            'oldest' => $oldest instanceof Entry ? $this->title($oldest) : null,
        ];
    }

    /**
     * "Bài của tôi": how many of the member's posts sit in each status (each up to CAP + 1), and
     * those a reviewer sent back with a reason.
     *
     * Each tile opens the posts list of the section postsSection() names, filtered to the member
     * and the status, and that list holds the posts whose primary category IS the section's
     * category (EntryListQuery::scoped(); a section built on no category lists every post). So
     * the tiles count exactly that set: a post filed in another section, or under a sub-category,
     * is in neither the number nor the list. The draft tile leaves out the returned posts it
     * counted (the board lists those on their own tile); only returned posts of that same set,
     * among those read, are taken off.
     *
     * @return array{tiles: array<string, int>, returned: list<array<string, mixed>>, any: bool}
     */
    public function mine(User $user, bool $workflowOn): array
    {
        $statuses = $workflowOn ? ['draft', 'pending', 'scheduled', 'published'] : ['draft', 'scheduled', 'published'];
        $listed = $this->postsSectionRow()?->taxonomy_id;
        $listed = $listed === null ? null : Cast::int($listed);
        $parts = [];
        foreach ($statuses as $status) {
            $parts[$status] = $this->inList($this->ownPosts($user)->where('status', $status), $listed);
        }
        $parts['any'] = $this->ownPosts($user);
        $tiles = $this->bounded($parts);
        ['rows' => $returned, 'listed' => $returnedListed] = $this->returned($user, $listed);

        $any = $tiles['any'] > 0;
        unset($tiles['any']);
        if ($tiles['draft'] <= self::CAP) {
            $tiles['draft'] = max(0, $tiles['draft'] - $returnedListed);
        }

        return ['tiles' => $tiles, 'returned' => $returned, 'any' => $any];
    }

    /**
     * The section "Mở danh sách bài" and the tiles open: the first that holds posts.
     */
    public function postsSection(): ?string
    {
        $row = $this->postsSectionRow();

        return $row instanceof ContentSection ? (string) $row->key : null;
    }

    /**
     * "Nội dung gần đây": the newest edits — pages and posts — or, for someone who only reads,
     * the newest published.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(User $user, bool $editor): array
    {
        $read = function (string $type) use ($editor): EloquentCollection {
            $query = Entry::query()->where('type', $type);
            if (! $editor) {
                $query->where('status', 'published');
            }

            // a page is filed in no category, but the row's edit check asks: an empty load, not a query per row
            return $query->with($this->rowRelations())
                ->orderByRaw('updated_at desc nulls last')->orderByDesc('id')->limit(self::ROWS)->get(self::ENTRY_COLUMNS);
        };

        /** @var EloquentCollection<int, Entry> $rows */
        $rows = $read('post')->concat($read('page'))
            ->sortByDesc(fn (Entry $entry): string => sprintf('%020d-%020d', $entry->updated_at?->getTimestamp() ?? 0, $entry->id))
            ->take(self::ROWS)->values();

        return $this->rows($rows, $user, []);
    }

    /** Is an approval flow switched on for any content type of this website? */
    public function workflowOn(): bool
    {
        return $this->workflows()->isNotEmpty();
    }

    /**
     * The active approval flows (ReviewQueue's own read, kept for the review card and the stage
     * names: one read for the three).
     *
     * @return EloquentCollection<int, Workflow>
     */
    private function workflows(): EloquentCollection
    {
        return $this->workflows ??= app(ReviewQueue::class)->activeWorkflows();
    }

    /**
     * The home page of the default language, with whether a person has ever saved it.
     */
    private function homePage(): ?Entry
    {
        $id = SiteRoute::query()->where('locale', app(SiteLocale::class)->default())->where('path', '/')->whereNotNull('entry_id')->value('entry_id');
        if ($id === null) {
            return null;
        }

        return Entry::query()->whereKey(Cast::int($id))->withExists('revisions')->first(self::ENTRY_COLUMNS);
    }

    /**
     * The website's names (DomainGroups: one capped read of `domains`, naming the site). Read once
     * by the caller and handed to domainProblems() and startSteps().
     *
     * @return list<DomainGroup>
     */
    public function domainGroups(): array
    {
        return app(DomainGroups::class)->forSite(app(CurrentSite::class)->id())->groups;
    }

    /**
     * Scheduled pages and posts, the next to go live first (up to CAP + 1).
     *
     * @return EloquentCollection<int, Entry>
     */
    private function scheduled(): EloquentCollection
    {
        return Entry::query()->whereIn('type', ['page', 'post'])->where('status', 'scheduled')->with($this->rowRelations())
            ->orderByRaw('published_at asc nulls last')->orderBy('id')
            ->limit(self::CAP + 1)
            ->get(self::ENTRY_COLUMNS);
    }

    /**
     * The member's drafts a reviewer sent back: the newest drafts (DRAFTS_READ), each with its
     * last move — a return when it brought the post back to the stage it is on, with a reason, by
     * someone else. A pending change to a published post that was turned down is not one: the
     * post stays published, and the board lists drafts only. `listed` counts the returned posts
     * whose primary category is $listed (the set the draft tile counts; null: every post).
     *
     * @return array{rows: list<array<string, mixed>>, listed: int}
     */
    private function returned(User $user, ?int $listed): array
    {
        $drafts = $this->ownPosts($user)->where('status', 'draft')->with($this->rowRelations())
            ->orderByRaw('updated_at desc nulls last')->orderByDesc('id')
            ->limit(self::DRAFTS_READ)->get(self::ENTRY_COLUMNS);
        if ($drafts->isEmpty()) {
            return ['rows' => [], 'listed' => 0];
        }

        $last = app(WorkflowTrail::class)->lastMoves(array_values($drafts->map(fn (Entry $entry): int => $entry->id)->all()));

        $back = [];
        foreach ($drafts as $entry) {
            $move = $last[$entry->id] ?? null;
            if ($move !== null && trim((string) $move['note']) !== '' && $move['actor_id'] !== $user->id
                && $move['to'] === $entry->workflow_state && $move['from'] !== $move['to']) {
                $back[] = [$entry, $move];
            }
        }
        if ($back === []) {
            return ['rows' => [], 'listed' => 0];
        }

        $actors = User::query()->whereIn('id', array_values(array_unique(array_filter(array_map(fn (array $pair): ?int => $pair[1]['actor_id'], $back)))))
            ->pluck('name', 'id');

        $out = [];
        $inList = 0;
        foreach ($back as [$entry, $move]) {
            if ($listed === null || $entry->primaryCategory()?->id === $listed) {
                $inList++;
            }
            $out[] = [
                'ulid' => $entry->ulid,
                'title' => $this->title($entry),
                'reason' => trim((string) $move['note']),
                'by' => $move['actor_id'] !== null ? Cast::string($actors->get($move['actor_id'])) : '',
                'at' => $move['at'],
                'url' => route('admin.entries.edit', ['ulid' => $entry->ulid]),
            ];
        }

        return ['rows' => $out, 'listed' => $inList];
    }

    /**
     * The member's own posts (the author index serves every status).
     *
     * @return Builder<Entry>
     */
    private function ownPosts(User $user): Builder
    {
        return Entry::query()->where('type', 'post')->where('author_id', $user->id);
    }

    /**
     * Posts of the member that the posts list of the linked section shows: primary category
     * $taxonomyId (the list's own test, EntryListQuery::scoped()), or every post when null.
     *
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    private function inList(Builder $query, ?int $taxonomyId): Builder
    {
        if ($taxonomyId === null) {
            return $query;
        }
        $site = app(CurrentSite::class)->id();

        return $query->whereExists(fn (QueryBuilder $primary) => $primary->selectRaw('1')->from('entry_taxonomies')
            ->where('entry_taxonomies.site_id', $site)
            ->whereColumn('entry_taxonomies.entry_id', 'entries.id')
            ->where('entry_taxonomies.taxonomy_id', $taxonomyId)
            ->where('entry_taxonomies.position', 0));
    }

    /**
     * What a row shows, and no more: its title in the default language, its author's name and its
     * primary category (its section, and whether a member limited to some categories may edit it).
     *
     * @return array<int|string, string|Closure>
     */
    private function rowRelations(): array
    {
        $locale = app(SiteLocale::class)->default();

        return [
            'translations' => fn (Relation $q) => $q->where('locale', $locale)->select(['id', 'entry_id', 'site_id', 'locale', 'title']),
            'author:id,name',
            'taxonomies' => static function (Relation $primary): void {
                if ($primary instanceof BelongsToMany) {
                    $primary->wherePivot('position', 0)->select(['taxonomies.id', 'taxonomies.site_id', 'taxonomies.parent_id', 'taxonomies.vocabulary', 'taxonomies.path']);
                }
            },
        ];
    }

    /**
     * @param  EloquentCollection<int, Entry>  $entries
     * @param  array<string, array<string, string>>  $stages  type => state key => label
     * @return list<array<string, mixed>>
     */
    private function rows(EloquentCollection $entries, User $user, array $stages): array
    {
        $sections = $this->sectionLabels();
        $resolver = app(SectionResolver::class);
        $resolver->prime($entries);
        $rows = [];
        foreach ($entries as $entry) {
            $section = $entry->type === 'page' ? 'pages' : ($resolver->forEntry($entry)['key'] ?? null);
            $author = $entry->author;
            $edit = Gate::forUser($user)->allows('update', $entry);
            $rows[] = [
                'ulid' => $entry->ulid,
                'title' => $this->title($entry),
                'type' => $entry->type,
                'status' => (string) $entry->status,
                'stage' => $section !== null ? ($stages[$section][(string) $entry->workflow_state] ?? null) : null,
                'section' => $section !== null ? ($sections[$section] ?? '') : '',
                'author' => $author instanceof User ? $author->name : '',
                'updated_at' => $entry->updated_at,
                'published_at' => $entry->published_at,
                'edit' => $edit,
                'url' => $edit
                    ? route('admin.entries.edit', ['ulid' => $entry->ulid])
                    : route('admin.entries.index', $section !== null && $section !== 'pages' ? ['section' => $section] : []),
            ];
        }

        return $rows;
    }

    private function title(Entry $entry): string
    {
        $translation = $entry->translationFor(app(SiteLocale::class)->default());

        return $translation instanceof EntryTranslation && trim($translation->title) !== '' ? $translation->title : (string) __('entries.unnamed');
    }

    /**
     * Each content section's name, as the admin menu names it.
     *
     * @return array<string, string>
     */
    private function sectionLabels(): array
    {
        return $this->sectionNames ??= $this->sections()
            ->mapWithKeys(fn (ContentSection $section): array => [(string) $section->key => __('section.'.$section->key) === 'section.'.$section->key ? (string) $section->label : (string) __('section.'.$section->key)])
            ->all();
    }

    /**
     * The site's sections in menu order (a site has a handful; 200 is a ceiling, not a page).
     *
     * @return EloquentCollection<int, ContentSection>
     */
    private function sections(): EloquentCollection
    {
        return $this->sectionRows ??= ContentSection::query()->orderBy('sort_order')->orderBy('id')->limit(200)
            ->get(['id', 'key', 'label', 'entry_type', 'taxonomy_id']);
    }

    private function postsSectionRow(): ?ContentSection
    {
        return $this->sections()->first(fn (ContentSection $section): bool => $section->entry_type === 'post');
    }

    /**
     * The names of the stages of each active approval flow.
     *
     * @return array<string, array<string, string>>
     */
    private function stageLabels(): array
    {
        $out = [];
        foreach ($this->workflows() as $workflow) {
            foreach ($workflow->states as $state) {
                $out[(string) $workflow->applies_to_type][$state->key] = (string) __($state->label);
            }
        }

        return $out;
    }

    /**
     * One statement: each query counted up to CAP + 1.
     *
     * @param  array<string, Builder<Entry>>  $parts
     * @return array<string, int>
     */
    private function bounded(array $parts): array
    {
        $select = [];
        $bindings = [];
        $keys = array_keys($parts);
        foreach ($keys as $i => $key) {
            $rows = $parts[$key]->toBase()->select(DB::raw('1'))->limit(self::CAP + 1);
            $select[] = '(select count(*) from ('.$rows->toSql().") as bounded) as c{$i}";
            $bindings = [...$bindings, ...$rows->getBindings()];
        }
        $row = (array) DB::selectOne('select '.implode(', ', $select), $bindings);
        $out = [];
        foreach ($keys as $i => $key) {
            $out[$key] = Cast::int($row["c{$i}"] ?? 0);
        }

        return $out;
    }
}
