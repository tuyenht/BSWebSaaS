<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Concerns;

use App\Modules\Admin\Support\Entries\EntryBodyLines;
use App\Modules\Admin\Support\Locales;
use App\Modules\Cms\Actions\RestoreEntryRevision;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\RevisionRestoreRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Queries\EntryRevisions;
use App\Modules\Cms\Support\DisplayDate;
use App\Modules\Cms\Support\EntryContentWriter;
use App\Modules\Cms\Support\EntryCover;
use App\Modules\Cms\Support\JobFacts;
use App\Modules\Media\Support\MediaRefs;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Locked;

/**
 * «Lịch sử phiên bản» of the post and page editor (board 07): the history of the language being
 * edited, opened from the «Đăng» card — its newest rows in the card, every row in a dialog paged by
 * keyset (EntryRevisions, never OFFSET), one version shown beside the form's current content, and a
 * restore asked first and done by RestoreEntryRevision, which checks the right to edit (EntryPolicy
 * update, own/any) and refuses for one of its reasons, said in words in the question.
 *
 * Every call reads the entry again by its ulid and authorizes `update` (livewire/update skips the
 * route middleware); a revision is taken only by its ulid, within the current site (global scope +
 * RLS), and only when it is this entry's and this language's — another site's or another entry's
 * ulid finds nothing. The list is read when the card's menu opens, not with the editor.
 *
 * @property string|null $ulid
 * @property string $locale
 * @property bool $multiLocale
 * @property string|null $status
 * @property bool $prose
 * @property string $bodyHtml
 * @property list<array<string, mixed>> $blocks
 * @property string $title
 * @property string $slug
 * @property string $excerpt
 * @property string $seoTitle
 * @property string $seoDescription
 * @property string $coverUlid
 * @property string $coverAlt
 */
trait ShowsEntryRevisions
{
    /**
     * History rows shown so far, newest first. Server data: the client pages it, never edits it.
     *
     * @var list<array{ulid: string, kind: string, at: string, who: string}>
     */
    #[Locked]
    public array $revRows = [];

    #[Locked]
    public ?string $revCursor = null;

    #[Locked]
    public int $revTotal = 0;

    /** The full list, the one version and the restore question (open states the dialogs share with the browser). */
    public bool $revAllOpen = false;

    public bool $revViewOpen = false;

    public bool $revAskOpen = false;

    /**
     * The version shown: its fields and content beside the form's (revisionView).
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $revView = null;

    /**
     * The restore question: which version and what it will change (revisionAsk).
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $revAsk = null;

    /** Why the restore was refused, in words; the question then offers only «Đóng». */
    #[Locked]
    public ?string $revRefusal = null;

    /**
     * The last restore of this language, said at the top of the history.
     *
     * @var array{text: string}|null
     */
    #[Locked]
    public ?array $revNote = null;

    /** The card's menu or the dialog opened: the first page of this language's history. */
    public function loadRevisions(): void
    {
        $entry = $this->revisionEntry();
        if ($entry === null) {
            return;
        }
        $this->firstRevisionPage($entry);
    }

    /** «Tải thêm»: the next page after the last row shown. */
    public function moreRevisions(): void
    {
        $entry = $this->revisionEntry();
        if ($entry === null || $this->revCursor === null) {
            return;
        }
        $page = app(EntryRevisions::class)->pageFor($entry, $this->locale, $this->revCursor);
        $this->revRows = [...$this->revRows, ...$this->revisionRows($page['rows'])];
        $this->revCursor = $page['cursor'];
        $this->revTotal = app(EntryRevisions::class)->countFor($entry, $this->locale);
    }

    /** «Xem»: one version beside the content the form holds now. */
    public function viewRevision(string $ulid): void
    {
        $entry = $this->revisionEntry();
        $version = $entry !== null ? app(EntryRevisions::class)->versionOf($entry, $this->locale, $ulid) : null;
        if ($entry === null || $version === null) {
            $this->revisionGone($entry);

            return;
        }
        $this->revView = $this->revisionView($entry, $version);
        $this->revViewOpen = true;
    }

    /** «Khôi phục»: the question, with what the restore will and will not change. */
    public function askRestore(string $ulid): void
    {
        $entry = $this->revisionEntry();
        $version = $entry !== null ? app(EntryRevisions::class)->versionOf($entry, $this->locale, $ulid) : null;
        if ($entry === null || $version === null) {
            $this->revisionGone($entry);

            return;
        }
        $this->revRefusal = null;
        $this->revAsk = $this->revisionAsk($entry, $version);
        $this->revAskOpen = true;
    }

    /** A closed version is not carried in every later request of the editor. */
    public function updatedRevViewOpen(bool $open): void
    {
        if (! $open) {
            $this->revView = null;
        }
    }

    /** Nor a closed question, or the refusal it said. */
    public function updatedRevAskOpen(bool $open): void
    {
        if (! $open) {
            $this->revAsk = null;
            $this->revRefusal = null;
        }
    }

    /**
     * «Khôi phục bản này»: written back by RestoreEntryRevision (which authorizes `update` itself and
     * never changes the status). Then the form takes the restored content, as «Lấy bản mới» does, the
     * history is read again (the restore is its newest row) and the result is said. True only when
     * the version was written back: the page keeps the form's unsaved changes on the device then.
     */
    public function restoreRevision(): bool
    {
        $ulid = $this->revAsk['ulid'] ?? null;
        try {
            $entry = $this->revisionEntry();
        } catch (AuthorizationException) {
            $this->revRefusal = Cast::string(__('entries.err_update_forbidden'));

            return false;
        }
        $revision = $entry !== null && is_string($ulid) ? app(EntryRevisions::class)->findFor($entry, $this->locale, $ulid) : null;
        if ($entry === null || $revision === null) {
            $this->revisionGone($entry);

            return false;
        }

        try {
            $dropped = app(RestoreEntryRevision::class)->handle($revision, $this->user());
        } catch (RevisionRestoreRejectedException $refused) {
            $this->revRefusal = $refused->userMessage();

            return false;
        } catch (AuthorizationException) {
            $this->revRefusal = Cast::string(__('entries.err_update_forbidden'));

            return false;
        }

        $at = $this->revisionTime($revision->created_at);
        $shared = (bool) ($this->revAsk['shared'] ?? false);
        $live = $entry->status === 'published';
        // on live content whose edits are held back, the version went into the pending change (visitors keep theirs)
        $held = $live && EntryDraft::query()->where('entry_id', $entry->id)->exists();
        $droppedLine = $dropped > 0 ? ' '.Cast::string(__('entries.rev.done_dropped', ['count' => $dropped])) : '';

        $this->revAskOpen = false;
        $this->revViewOpen = false;
        $this->revAsk = null;
        $this->revView = null;
        $this->revNote = ['text' => Cast::string(__('entries.rev.note', ['at' => $at])).$droppedLine
            .($shared ? ' '.Cast::string(__('entries.rev.note_shared')) : '')];

        $this->resetChanges(); // mount() authorizes `update` again and loads the restored content
        if ($this->prose) {
            $this->dispatch('admin-editor-set-html', target: 'bodyHtml', html: $this->bodyHtml);
        }
        $fresh = $this->revisionEntry();
        if ($fresh !== null) {
            $this->firstRevisionPage($fresh);
        }
        $this->dispatch('bs-toast', text: Cast::string(__(match (true) {
            $held => 'entries.rev.done_change',
            $live => 'entries.rev.done_live',
            default => 'entries.rev.done_kept',
        }, ['at' => $at])).$droppedLine);

        return true;
    }

    /**
     * What the «Đăng» card and the dialogs draw that is not held in state.
     *
     * @return array{button: string, head: string, emptyHint: string, allTitle: string, allSub: string, legend: string}|null
     */
    private function revisionPanel(): ?array
    {
        if ($this->ulid === null) {
            return null;
        }
        $lang = Locales::label($this->locale);
        $zone = $this->revisionZone();
        $count = $this->revTotal;
        $head = $count > 0 ? trans_choice('entries.rev.head', $count, ['count' => $count]) : __('entries.rev.head_none');

        return [
            'button' => Cast::string($this->multiLocale ? __('entries.rev.btn_loc', ['code' => strtoupper($this->locale)]) : __('entries.rev.btn')),
            'head' => Cast::string($this->multiLocale ? __('entries.rev.head_loc', ['lang' => $lang, 'rest' => $head]) : $head),
            'emptyHint' => Cast::string(__('entries.rev.empty_hint', ['unit' => mb_strtolower($this->unit())])),
            'allTitle' => Cast::string($this->multiLocale ? __('entries.rev.all_title_loc', ['lang' => $lang]) : __('entries.rev.all_title')),
            'allSub' => Cast::string(__($this->multiLocale ? 'entries.rev.all_sub_multi' : 'entries.rev.all_sub', ['zone' => $zone])),
            'legend' => Cast::string(__('entries.rev.legend', [
                'keep' => max(1, Cast::int(config('cms.revisions.keep', 20))),
                'days' => max(1, Cast::int(config('cms.revisions.days', 90))),
            ])),
        ];
    }

    /**
     * The entry being edited, read again and authorized to edit; null for an entry not saved yet. An
     * entry moved to the Trash since is still found, so a restore can say why it is refused.
     */
    private function revisionEntry(): ?Entry
    {
        if ($this->ulid === null) {
            return null;
        }
        $entry = Entry::withTrashed()->where('ulid', $this->ulid)->firstOrFail();
        $this->authorize('update', $entry);

        return $entry;
    }

    private function firstRevisionPage(Entry $entry): void
    {
        $revisions = app(EntryRevisions::class);
        $page = $revisions->pageFor($entry, $this->locale);
        $this->revRows = $this->revisionRows($page['rows']);
        $this->revCursor = $page['cursor'];
        $this->revTotal = $revisions->countFor($entry, $this->locale);
    }

    /** A version left the history since the list was read (retention, another tab): said, and the list read again. */
    private function revisionGone(?Entry $entry): void
    {
        $this->revAskOpen = false;
        $this->revViewOpen = false;
        $this->revAsk = null;
        $this->revView = null;
        $this->revRefusal = null;
        if ($entry !== null) {
            $this->firstRevisionPage($entry);
        }
        $this->dispatch('bs-toast', text: Cast::string(__('entries.rev.gone')), stay: true, close: Cast::string(__('admin.close')));
    }

    /**
     * @param  list<array{ulid: string, kind: RevisionKind, at: DateTimeInterface, who: string|null}>  $rows
     * @return list<array{ulid: string, kind: string, at: string, who: string}>
     */
    private function revisionRows(array $rows): array
    {
        return array_map(fn (array $row): array => [
            'ulid' => $row['ulid'],
            'kind' => $row['kind']->value,
            'at' => $this->revisionTime($row['at']),
            'who' => $this->revisionWho($row['who']),
        ], $rows);
    }

    /** Who took a version; a version no person took (a loader) is the system's. */
    private function revisionWho(?string $who): string
    {
        return $who ?? Cast::string(__('entries.rev.who_system'));
    }

    /** dd/mm/yyyy hh:mm in the website's clock (board 07). */
    private function revisionTime(DateTimeInterface $at): string
    {
        return app(SiteTime::class)->forDisplay($at)?->format('d/m/Y H:i') ?? '';
    }

    /** The website's clock as GMT+7 (GMT+5:30, GMT-3). */
    private function revisionZone(): string
    {
        $minutes = app(SiteTime::class)->now()->utcOffset();
        $sign = $minutes < 0 ? '-' : '+';
        $minutes = abs($minutes);

        return 'GMT'.$sign.intdiv($minutes, 60).($minutes % 60 !== 0 ? sprintf(':%02d', $minutes % 60) : '');
    }

    /**
     * The name, glyph and explanation of a revision kind (board 07 revKind).
     *
     * @return array{label: string, icon: string, tip: string}
     */
    public static function revisionKind(string $kind): array
    {
        $icon = match (RevisionKind::tryFrom($kind)) {
            RevisionKind::Baseline => 'ri-archive',
            RevisionKind::Publish => 'ri-send-plane',
            RevisionKind::Restore => 'ri-history',
            default => 'ri-edit',
        };

        return [
            'label' => Cast::string(__('entries.rev.kind_'.$kind)),
            'icon' => $icon,
            'tip' => Cast::string(__('entries.rev.tip_'.$kind)),
        ];
    }

    /**
     * One version beside the form's current content (board 07 rvV): each field, marked where it
     * differs, then the content line by line, then what the current content has and this version
     * does not. The cover and the layout are shared by every language and say so.
     *
     * @param  array{ulid: string, kind: RevisionKind, at: DateTimeInterface, who: string|null, snapshot: array<string, mixed>, latest: bool}  $version
     * @return array<string, mixed>
     */
    private function revisionView(Entry $entry, array $version): array
    {
        $snap = $version['snapshot'];
        $str = static fn (mixed $value): string => is_string($value) ? $value : '';
        // the version's cover: the file its cover_media_id names (read only when it names one; none when it
        // names no live file), else, with no id, the meta's key
        $cover = EntryCover::resolve($snap['cover_media_id'] ?? null, $snap['meta'] ?? null, MediaRefs::rows([$snap['cover_media_id'] ?? null]));
        $noCover = Cast::string(__('entries.rev.no_cover'));
        $coverNow = $this->coverUlid !== '' ? $this->revisionCoverName($this->coverKey()) : $noCover;
        $coverThen = $cover !== '' ? $this->revisionCoverName($cover) : $noCover;

        $fields = [];
        foreach (array_filter([
            ['entries.rev.f_title', $str($snap['title'] ?? null), $this->title, false],
            ['entries.rev.f_slug', $str($snap['slug'] ?? null), $this->slug, false],
            ['entries.rev.f_excerpt', $str($snap['excerpt'] ?? null), $this->excerpt, false],
            ['entries.rev.f_seo_title', $str($snap['seo_title'] ?? null), $this->seoTitle, false],
            ['entries.rev.f_seo_desc', $str($snap['seo_description'] ?? null), $this->seoDescription, false],
            ['entries.rev.f_cover', $coverThen, $coverNow, true],
            // the picture's words, when the version holds them (one saved before they existed leaves them as they are)
            array_key_exists('cover_alt', $snap) ? ['entries.ed.cover_alt_label', $str($snap['cover_alt']), $this->coverAlt, false] : null,
            ['entries.rev.f_tpl', $this->revisionTemplate($snap['template_override'] ?? null), $this->revisionTemplate($this->formTemplate($entry->template_override)), true],
            // a post's shown publish day (empty in a version: the real day)
            $entry->type === 'post' ? ['entries.ed.pd_label', $this->revisionDay(DisplayDate::of($snap['meta'] ?? null)), $this->revisionDay($this->displayDate), true] : null,
        ]) as [$label, $then, $now, $shared]) {
            $fields[] = ['label' => Cast::string(__($label, ['doc' => $this->docNoun()])), 'value' => $then, 'now' => $now, 'differs' => $then !== $now, 'shared' => $shared];
        }

        $blocks = data_get($snap, 'body.blocks');
        $then = $this->revisionLines($this->normalizeBlocks(is_array($blocks) ? $blocks : []));
        $now = $this->prose ? $this->revisionHtmlLines($this->bodyHtml) : $this->revisionLines($this->normalizeBlocks($this->blocks));
        $nowTexts = array_flip(array_column($now, 't'));
        $thenTexts = array_flip(array_column($then, 't'));
        $lines = array_map(static fn (array $line): array => $line + ['differs' => ! isset($nowTexts[$line['t']])], $then);
        $gone = array_values(array_filter($now, static fn (array $line): bool => ! isset($thenTexts[$line['t']])));

        $changes = count(array_filter($fields, static fn (array $f): bool => $f['differs']))
            + count(array_filter($lines, static fn (array $l): bool => $l['differs'])) + count($gone);
        // the newest of this language now, read with the version (another tab may have added one since the list was read)
        $latest = $version['latest'];
        $kind = self::revisionKind($version['kind']->value);
        $at = $this->revisionTime($version['at']);
        $subWords = ['kind' => $kind['label'], 'who' => $this->revisionWho($version['who']), 'lang' => Locales::label($this->locale), 'zone' => $this->revisionZone()];

        return [
            'ulid' => $version['ulid'],
            'title' => Cast::string(__('entries.rev.view_title', ['at' => $at])),
            'sub' => Cast::string(__($this->multiLocale ? 'entries.rev.view_sub_loc' : 'entries.rev.view_sub', $subWords)),
            'sum' => Cast::string(match (true) {
                $latest && $changes > 0 => __('entries.rev.sum_latest_diff', ['count' => $changes]),
                $latest => __('entries.rev.sum_latest'),
                $changes > 0 => __('entries.rev.sum_diff', ['count' => $changes]),
                default => __('entries.rev.sum_same'),
            }),
            'fields' => $fields,
            'lines' => $lines,
            'gone' => $gone,
            'canRestore' => ! $latest,
        ];
    }

    /**
     * What the restore will change (board 07 rvA): live content changes at once, any other status is
     * kept; the shared cover and layout change every language; media gone from the library is left
     * empty (counted the way the restore drops it); unsaved changes in the form are replaced.
     *
     * @param  array{ulid: string, kind: RevisionKind, at: DateTimeInterface, who: string|null, snapshot: array<string, mixed>, latest: bool}  $version
     * @return array<string, mixed>
     */
    private function revisionAsk(Entry $entry, array $version): array
    {
        $writer = app(EntryContentWriter::class);
        $content = $writer->read($version['snapshot'], $entry->type, null, true);
        $missing = 0;
        $shared = false;
        if ($content !== null) {
            [$content, $missing] = $writer->withoutMissingMedia($content, lock: false);
            // the job card as the restore writes it: this language's words onto the card as it is (in the
            // pending change, when there is one: a restore held back goes there)
            $held = EntryDraft::query()->where('entry_id', $entry->id)->first()?->content['shared'] ?? null;
            $current = is_array($held) && is_array($held['meta'] ?? null) ? $held['meta'] : $entry->meta;
            $content['meta'] = JobFacts::restored($current, $content['meta'], $this->locale, app(SiteLocale::class)->default());
            $shared = $writer->changesShared($entry, $content);
        }
        $status = $entry->status;

        return [
            'ulid' => $version['ulid'],
            'title' => Cast::string(__('entries.rev.ask_title', ['at' => $this->revisionTime($version['at'])])),
            'live' => $status === 'published',
            'liveLine' => Cast::string(__('entries.rev.ask_pub', ['unit' => $this->unit()])),
            'statusLine' => Cast::string(__('entries.rev.ask_status', ['status' => Cast::string(__('entries.ed.pill_'.$status))])),
            'oneLocale' => $this->multiLocale && ! $shared ? Cast::string(__('entries.rev.ask_one_loc', ['lang' => Locales::label($this->locale)])) : null,
            'shared' => $shared,
            'dirty' => $this->changeCount() > 0,
            'missing' => $missing > 0 ? Cast::string(__('entries.rev.ask_missing', ['count' => $missing])) : null,
        ];
    }

    private function revisionTemplate(mixed $template): string
    {
        return $this->templateName($template);
    }

    private function revisionDay(string $day): string
    {
        return $day !== '' ? $this->dayName($day) : Cast::string(__('entries.ed.pd_real'));
    }

    private function revisionCoverName(string $key): string
    {
        $name = $this->coverName($key);

        return $name !== '' ? $name : $key;
    }

    /**
     * A stored body as lines of text (EntryBodyLines).
     *
     * @param  list<array{type: string, props: array<string, mixed>}>  $blocks
     * @return list<array{k: string, t: string}>
     */
    private function revisionLines(array $blocks): array
    {
        return app(EntryBodyLines::class)->lines($blocks);
    }

    /**
     * @return list<array{k: string, t: string}>
     */
    private function revisionHtmlLines(string $html): array
    {
        return app(EntryBodyLines::class)->htmlLines($html);
    }
}
