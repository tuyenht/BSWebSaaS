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
use App\Modules\Workflow\Models\WorkflowLog;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

    /** A count stops here: one more row means "more than this". */
    public const int CAP = 99;

    /** Rows a list card shows. */
    public const int ROWS = 5;

    /** Rows of the review tabs, and of the scheduled tab (design: 3, and 6 for "Đã hẹn giờ"). */
    public const int REVIEW_ROWS = 3;

    public const int SCHEDULED_ROWS = 6;

    /** How much of a review queue one dashboard read hydrates (Hộp duyệt reads up to 500). */
    private const int QUEUE_READ = 100;

    /** A member's newest drafts looked at for "Bị trả lại". */
    private const int DRAFTS_READ = 20;

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
        ['entries' => $waiting, 'full' => $full] = app(ReviewWaiting::class)->read($user, self::QUEUE_READ);
        $mine = $waiting->filter(fn (Entry $entry): bool => $entry->assigned_to === $user->id)->values();
        $sched = $this->scheduled();
        $stages = $this->stageLabels();
        $oldest = $waiting->last();

        return [
            'queue' => $this->rows($waiting->take(self::REVIEW_ROWS), $user, $stages),
            'queue_n' => $waiting->count(),
            'queue_more' => $full,
            'mine' => $this->rows($mine->take(self::REVIEW_ROWS), $user, $stages),
            'mine_n' => $mine->count(),
            'sched' => $this->rows($sched->take(self::SCHEDULED_ROWS), $user, $stages),
            'sched_n' => $sched->count(),
            'oldest' => $oldest instanceof Entry ? $this->title($oldest) : null,
        ];
    }

    /**
     * "Bài của tôi": how many of the member's posts sit in each status (each up to CAP + 1), and
     * those a reviewer sent back with a reason.
     *
     * @return array{tiles: array<string, int>, returned: list<array<string, mixed>>, any: bool}
     */
    public function mine(User $user, bool $workflowOn): array
    {
        $statuses = $workflowOn ? ['draft', 'pending', 'scheduled', 'published'] : ['draft', 'scheduled', 'published'];
        $parts = [];
        foreach ($statuses as $status) {
            $parts[$status] = $this->ownPosts($user)->where('status', $status);
        }
        $parts['any'] = $this->ownPosts($user);
        $tiles = $this->bounded($parts);
        $returned = $this->returned($user);

        $any = $tiles['any'] > 0;
        unset($tiles['any']);
        if ($tiles['draft'] <= self::CAP) {
            $tiles['draft'] = max(0, $tiles['draft'] - count($returned));
        }

        return ['tiles' => $tiles, 'returned' => $returned, 'any' => $any];
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

            return $this->withRow($query)->orderByRaw('updated_at desc nulls last')->orderByDesc('id')->limit(self::ROWS)->get(self::ENTRY_COLUMNS);
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
        return Workflow::query()->where('is_active', true)->exists();
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
        return $this->withRow(Entry::query()->whereIn('type', ['page', 'post'])->where('status', 'scheduled'))
            ->orderByRaw('published_at asc nulls last')->orderBy('id')
            ->limit(self::CAP + 1)
            ->get(self::ENTRY_COLUMNS);
    }

    /**
     * The member's drafts a reviewer sent back: the newest drafts, each with its last move — a
     * return when it brought the post back to the stage it is on, with a reason, by someone else.
     *
     * @return list<array<string, mixed>>
     */
    private function returned(User $user): array
    {
        $drafts = $this->withRow($this->ownPosts($user)->where('status', 'draft'))
            ->orderByRaw('updated_at desc nulls last')->orderByDesc('id')
            ->limit(self::DRAFTS_READ)->get(self::ENTRY_COLUMNS);
        if ($drafts->isEmpty()) {
            return [];
        }

        /** @var EloquentCollection<int, WorkflowLog> $logs */
        $logs = WorkflowLog::query()
            ->selectRaw('distinct on (entry_id) entry_id, actor_id, from_state, to_state, note, created_at')
            ->whereIn('entry_id', $drafts->pluck('id')->all())
            ->where('target', 'entry')
            ->orderBy('entry_id')->orderByDesc('created_at')->orderByDesc('id')
            ->get();
        $last = $logs->keyBy('entry_id');

        $back = [];
        foreach ($drafts as $entry) {
            $log = $last->get($entry->id);
            if ($log instanceof WorkflowLog && trim((string) $log->note) !== '' && $log->actor_id !== $user->id
                && $log->to_state === $entry->workflow_state && $log->from_state !== $log->to_state) {
                $back[] = [$entry, $log];
            }
        }
        if ($back === []) {
            return [];
        }

        $actors = User::query()->whereIn('id', array_values(array_unique(array_filter(array_map(fn (array $pair): ?int => $pair[1]->actor_id, $back)))))
            ->pluck('name', 'id');

        $out = [];
        foreach ($back as [$entry, $log]) {
            $at = $log->getAttribute('created_at');
            $out[] = [
                'ulid' => $entry->ulid,
                'title' => $this->title($entry),
                'reason' => trim((string) $log->note),
                'by' => $log->actor_id !== null ? Cast::string($actors->get($log->actor_id)) : '',
                'at' => $at instanceof DateTimeInterface ? $at : null,
                'url' => route('admin.entries.edit', ['ulid' => $entry->ulid]),
            ];
        }

        return $out;
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
     * What a row shows: its title in the default language, its author and its primary category
     * (its section, and whether a member limited to some categories may edit it).
     *
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    private function withRow(Builder $query): Builder
    {
        $locale = app(SiteLocale::class)->default();

        return $query->with([
            'translations' => fn ($q) => $q->where('locale', $locale)->select(['id', 'entry_id', 'site_id', 'locale', 'title']),
            'author:id,name',
            'taxonomies',
        ]);
    }

    /**
     * @param  iterable<Entry>  $entries
     * @param  array<string, array<string, string>>  $stages  type => state key => label
     * @return list<array<string, mixed>>
     */
    private function rows(iterable $entries, User $user, array $stages): array
    {
        $sections = $this->sectionLabels();
        $resolver = app(SectionResolver::class);
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
        return $this->sectionNames ??= ContentSection::query()->orderBy('sort_order')->orderBy('id')->limit(200)->get(['id', 'key', 'label'])
            ->mapWithKeys(fn (ContentSection $section): array => [(string) $section->key => __('section.'.$section->key) === 'section.'.$section->key ? (string) $section->label : (string) __('section.'.$section->key)])
            ->all();
    }

    /**
     * The names of the stages of each active approval flow.
     *
     * @return array<string, array<string, string>>
     */
    private function stageLabels(): array
    {
        $out = [];
        foreach (Workflow::query()->where('is_active', true)->with('states')->get() as $workflow) {
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
