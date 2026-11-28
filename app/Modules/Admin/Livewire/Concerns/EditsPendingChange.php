<?php

namespace App\Modules\Admin\Livewire\Concerns;

use App\Modules\Admin\Support\Entries\PendingChangeDiff;
use App\Modules\Admin\Support\Entries\SubPageTakenText;
use App\Modules\Admin\Support\EntryProseBody;
use App\Modules\Admin\Support\Locales;
use App\Modules\Cms\Actions\ApplyEntryDraft;
use App\Modules\Cms\Actions\ApplyEntryDraftTransition;
use App\Modules\Cms\Actions\DiscardEntryDraft;
use App\Modules\Cms\Actions\SaveEntryDraft;
use App\Modules\Cms\Exceptions\EntryDraftRejectedException;
use App\Modules\Cms\Exceptions\EntryNotGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Support\DisplayDate;
use App\Modules\Cms\Support\EntryCover;
use App\Modules\Cms\Support\EntryTags;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Support\MediaRefs;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\PlainText;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Theme\Rendering\TemplateCatalog;
use App\Modules\Workflow\Exceptions\InvalidTransitionException;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowTransition;
use App\Modules\Workflow\Services\WorkflowEngine;
use App\Modules\Workflow\Services\WorkflowTrail;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * The pending change of a live entry, in the editor (design.md §8, board 07). While an approval
 * workflow governs a live entry, or the member may not publish it, or it already has a pending
 * change (SaveEntryDraft::holdsBack, judged under the row lock), a save goes into that one change
 * and visitors keep the live version. The form then edits the change: it opens on it, and the band
 * above the form says where the change stands and offers its moves — send, approve, return, a
 * middle step of the workflow through the engine, «Cập nhật website» without a workflow, throw it
 * away, compare it with what visitors see. Every move is a Cms action that authorizes and locks
 * itself; each method here re-reads the entry and authorizes `update` first, and every move hands
 * the action the change's version this form last read (the stale-tab check), never one sent by the
 * browser.
 */
trait EditsPendingChange
{
    /** The pending change's version this form opened on or last saved into; null while the entry has none. */
    #[Locked]
    public ?string $changeSeen = null;

    /** «Duyệt đăng thay đổi?» / «Cập nhật website?» is open. */
    public bool $changeApplyAsk = false;

    /** «Bỏ thay đổi?» is open. */
    public bool $changeDiscardAsk = false;

    /** «So sánh với bản đang trên website» is open. */
    public bool $changeDiffOpen = false;

    /** «Trả lại thay đổi» is open, with its note. */
    public bool $changeReturnOpen = false;

    public string $changeNote = '';

    /**
     * «Chưa làm được» (board 07): a move of the pending change refused for a reason the member can act
     * on — the dialog's title (which move), the reason in plain words, and whether «Tải lại» is offered
     * (the change moved on elsewhere). Null while none is shown.
     *
     * @var array{title: string, text: string, reload: bool}|null
     */
    #[Locked]
    public ?array $refusal = null;

    /**
     * The reasons the dialog says (the board draws these; a schedule refused while a change is open
     * keeps its line on the screen).
     */
    private const array REFUSAL_DIALOG = [
        'change_locked', 'change_pending', 'change_stale', 'not_live', 'content_invalid',
        'category_governed', 'locale_disabled', 'slug_taken', 'subpage_path_taken', 'workflow_governed',
    ];

    /** «Tải bản mới nhất?» is open: «Tải lại» was pressed with edits still in the form. */
    public bool $changeReloadAsk = false;

    /**
     * The fields «Tải lại» would replace, named in «Tải bản mới nhất?»; set only by reloadChange after a
     * refusal that offered it, so the question's answer can never load over a form nobody asked about.
     */
    #[Locked]
    public ?string $reloadReplaces = null;

    /**
     * Focus once «Chưa làm được» or «Tải bản mới nhất?» leaves (Alpine, merged into each one's x-data): back to
     * the button that opened it, unless the focus already sits on something shown (the next dialog). That
     * button may be gone or hidden (it sat in the question before «Duyệt đăng» / «Bỏ thay đổi», or in the
     * dialog before this one): then the band's first move, else the bar's main button, else its «⋯» button.
     */
    public const string REFUSAL_REFOCUS = 'opener: null, init() { this.opener = document.activeElement; }, '
        .'destroy() { const opener = this.opener; setTimeout(() => { '
        .'const shown = (el) => el instanceof HTMLElement && el !== document.body && el.isConnected && el.getClientRects().length > 0; '
        .'if (shown(document.activeElement)) { return; } '
        .'[opener, ...["[data-change-band] [data-change-act]:not([aria-disabled])", "[data-guide=main]", "[data-guide=main-b]", "[data-guide=more]"].flatMap((q) => [...document.querySelectorAll(q)])].find(shown)?.focus(); }); }';

    /** The move this request makes on the change, naming the dialog: save, submit, apply, approve, discard; null for a move the board draws no dialog for (return, a middle step). */
    private ?string $changeAct = 'save';

    /** What this request's save did with an edit held back: 'saved' into the change, 'same' as the website (nothing written). */
    private ?string $heldSave = null;

    /** Whether this request's status move folded the change the form was editing into the entry. */
    private bool $folded = false;

    /** The entry's pending change, read once per request (false: not read yet). */
    private EntryDraft|false|null $changeMemo = false;

    /**
     * Opening a live entry with a pending change: the form takes the change's values — its text in
     * this language when it holds some, its picture and its main category, filed with the stored
     * others as the applier will file it (EntryDraft::filingUnder). Returns the change's main category.
     */
    private function openPendingChange(EntryDraft $change): ?int
    {
        $this->changeSeen = $change->version();
        $text = $change->content['locales'][$this->locale] ?? null;
        if (is_array($text)) {
            $this->title = Cast::string($text['title'] ?? '');
            $this->slug = Cast::string($text['slug'] ?? '');
            $body = $text['body'] ?? null;
            $this->openBody($this->normalizeBlocks(is_array($body) ? ($body['blocks'] ?? []) : []));
            $this->excerpt = Cast::string($text['excerpt'] ?? '');
            $this->seoTitle = Cast::string($text['seo_title'] ?? '');
            $this->seoDescription = Cast::string($text['seo_description'] ?? '');
            $this->coverAlt = Cast::string($text['cover_alt'] ?? '');
            // a change saved before the focus keyword existed leaves the live one (applying it keeps it)
            if (array_key_exists('focus_keyword', $text)) {
                $this->focusKeyword = Cast::string($text['focus_keyword']);
            }
        }
        $shared = $change->content['shared'] ?? [];
        // the cover: the file its cover_media_id names (read only when it names one; none when it
        // names no live file), else, with no id, the meta's key
        $coverId = $shared['cover_media_id'] ?? null;
        if (array_key_exists('meta', $shared) || EntryCover::named($coverId)) {
            $this->loadCover($coverId, $shared['meta'] ?? null);
        }
        if (array_key_exists('meta', $shared)) {
            if ($this->type === 'post') {
                $this->displayDate = DisplayDate::of($shared['meta']);
                $this->loadJobCard($shared['meta']);
            }
        }
        if ($this->type === 'post' && array_key_exists('template_override', $shared)) {
            $this->templateKey = TemplateCatalog::variant(is_string($shared['template_override']) ? $shared['template_override'] : null) ?? '';
        }
        $primary = $shared['primary_category_id'] ?? null;
        if ($this->type !== 'post' || ! is_int($primary)) {
            return null;
        }
        $this->categoryIds = EntryDraft::filingUnder($this->categoryIds, $primary);
        $this->categoryId = $primary;

        return $primary;
    }

    /** The entry's pending change (none for content that is not live), once per request. */
    private function pendingChange(Entry $entry): ?EntryDraft
    {
        if ($this->changeMemo === false) {
            $this->changeMemo = $entry->status === 'published'
                ? EntryDraft::query()->where('entry_id', $entry->id)->first()
                : null;
        }

        return $this->changeMemo;
    }

    /**
     * The held-back save, under the entry's row lock (persist() took it): the form's values go into
     * the pending change, nothing is written to what visitors see and no revision is recorded. A
     * form that differs in nothing from the website, with no change yet, writes nothing. Returns
     * whether the save got through (said on the field or in the band when not).
     *
     * @param  list<array<string, mixed>>  $blocks  the body as it is stored
     * @param  list<int>  $filing  the categories filedUnder() settled, the main one first
     */
    private function saveIntoChange(Entry $entry, ?EntryTranslation $live, array $blocks, array $filing): bool
    {
        $change = $this->pendingChange($entry);
        if ($this->type === 'post') {
            // the other categories must be the ones the change's main category brings with it
            $others = array_slice(EntryDraft::filingUnder($this->storedCategories($entry), $filing[0] ?? 0), 1);
            $kept = array_slice($filing, 1);
            sort($others);
            sort($kept);
            if ($others !== $kept) {
                $this->addError('categoryIds', Cast::string(__('entries.ed.change_cats')));

                return false;
            }
            // «Thẻ» goes straight to the website, so a held-back save cannot change it (the card says so):
            // judged against what this form read, so a tag another save filed meanwhile is no change of this one
            $tagChanges = EntryTags::changes($this->tagsLoaded, $this->tags, $this->locale);
            if ($tagChanges['added'] !== [] || $tagChanges['removed'] !== []) {
                $this->addError('tagDraft', Cast::string(__('entries.ed.tag_held')));

                return false;
            }
        }

        $text = [
            'title' => $this->title,
            'slug' => $this->slug,
            'body' => ['blocks' => $blocks],
            'excerpt' => $this->excerpt !== '' ? $this->excerpt : null,
            'seo_title' => $this->seoTitle !== '' ? $this->seoTitle : null,
            'seo_description' => $this->seoDescription !== '' ? $this->seoDescription : null,
            'focus_keyword' => $this->focusKeyword !== '' ? $this->focusKeyword : null,
        ];
        // the cover over the one the change holds (else the website's), as the direct save writes it: by its row
        $held = $change !== null ? ($change->content['shared'] ?? []) : [];
        $cover = $this->coverToStore(
            array_key_exists('cover_media_id', $held) ? $held['cover_media_id'] : $entry->cover_media_id,
            Cast::arr(array_key_exists('meta', $held) ? $held['meta'] : $entry->meta),
            $this->coverStoredSame,
        );
        if ($cover === null) {
            return false;
        }
        [$coverId, $meta] = $cover;
        $text['cover_alt'] = ($coverId !== null || EntryCover::metaValue($meta) !== '') && $this->coverAlt !== '' ? $this->coverAlt : null;
        $shared = ['cover_media_id' => $coverId, 'meta' => $meta] + ($this->type === 'post' && $filing !== [] ? ['primary_category_id' => $filing[0]] : []);
        // «Thuộc tính bài» goes into the change too, from the website's default language (every language's)
        if ($this->type === 'post' && ! $this->sharedLocked() && $this->postPropsRead) {
            $before = $change !== null && array_key_exists('template_override', $change->content['shared'] ?? []) ? $change->content['shared']['template_override'] : $entry->template_override;
            $meta = DisplayDate::into($meta, $this->displayDate);
            $shared['meta'] = $meta;
            $shared['template_override'] = $this->formTemplate(is_string($before) ? $before : null);
        }
        // «Thông tin tuyển dụng» goes into the change too (this language's words, every language's boxes),
        // judged as written onto the card the change holds now (a refusal takes the transaction back)
        /** @var array<string, mixed> $meta */
        $meta = $this->jobWritten($meta);
        $shared['meta'] = $meta;

        if ($change === null && $this->sameAsLive($entry, $live, $text, $coverId, $meta, $filing[0] ?? null)) {
            $this->heldSave = 'same';

            return true;
        }

        try {
            $saved = app(SaveEntryDraft::class)->handle($entry, $this->user(), $this->locale, $text, $shared);
        } catch (EntryDraftRejectedException $refused) {
            $this->changeRefused($refused->reason, $refused->path, $refused->locale);

            return false;
        }
        $this->changeMemo = $saved;
        $this->changeSeen = $saved->version();
        $this->heldSave = 'saved';

        return true;
    }

    /**
     * Whether the form, as it would be stored, is what visitors see now (no pending change yet).
     *
     * @param  array<string, mixed>  $text
     * @param  array<string, mixed>  $meta
     */
    private function sameAsLive(Entry $entry, ?EntryTranslation $live, array $text, ?int $coverId, array $meta, ?int $primary): bool
    {
        if ($live === null) {
            return false;
        }
        $before = ['blocks' => $this->liveBodyAsSaved($live)];
        foreach (['title', 'slug', 'excerpt', 'seo_title', 'seo_description', 'cover_alt', 'focus_keyword'] as $field) {
            if (Cast::string($live->getAttribute($field)) !== Cast::string($text[$field] ?? null)) {
                return false;
            }
        }

        return self::keysSorted($before) === self::keysSorted($text['body'])
            // the same picture, also when the form names by its row the one the website still names by its key
            && EntryCover::same($entry->cover_media_id, $entry->meta, $coverId, $meta)
            && ($this->type !== 'post' || ! $this->postPropsRead || (DisplayDate::of($entry->meta) === DisplayDate::of($meta) && $entry->template_override === $this->formTemplate($entry->template_override)))
            && ($this->type !== 'post' || $primary === ($this->storedCategories($entry)[0] ?? null))
            && ($this->type !== 'post' || [$entry->meta['facts'] ?? null, $entry->meta['badge'] ?? null] === [$meta['facts'] ?? null, $meta['badge'] ?? null]);
    }

    /**
     * The live body as this form would store it unchanged: a body shown in the rich editor goes through
     * the same reading and writing as the form's (older plain-text blocks come back as one formatted-text
     * block), so opening it and saving changes nothing visible and writes no change. A layout is compared
     * as it is stored.
     *
     * @return list<mixed>
     */
    private function liveBodyAsSaved(EntryTranslation $live): array
    {
        $blocks = array_values(Cast::arr($live->body['blocks'] ?? []));
        if (! $this->prose) {
            return $blocks;
        }
        $prose = app(EntryProseBody::class);
        /** @var list<array{type: string, props: array<string, mixed>}> $blocks */
        $shown = $prose->read($blocks);

        return $shown === null ? $blocks : $prose->write(PlainText::lines($shown['html'], trim: false), $shown['look'])['blocks'];
    }

    /**
     * «Gửi duyệt thay đổi»: the form is saved into the change, then the change takes its first move
     * from where it stands — the one that neither publishes nor needs a note (the send).
     */
    public function submitChange(): void
    {
        $this->resetErrorBag(['workflow', 'changeNote']);
        $this->changeAct = 'submit';
        $entry = $this->persist('save');
        if (! $entry instanceof Entry || $this->heldSave === null) {
            return;
        }
        if ($this->heldSave === 'same') {
            $this->dispatch('bs-toast', text: Cast::string(__('entries.chg.t_same')));

            return;
        }
        $this->resetSave();
        [$workflow, $change] = $this->changeContext($entry);
        $send = $workflow !== null && $change !== null
            ? collect(app(EntryWorkflowResolver::class)->changeMovesFor($workflow, $entry, $change, $this->user()))
                ->first(fn (WorkflowTransition $t): bool => ! $t->requires_note && ! $this->publishesAt($workflow, $t->to_state))
            : null;
        if (! $send instanceof WorkflowTransition) {
            $this->addError('workflow', Cast::string(__('admin.wfb.err_forbidden')));

            return;
        }
        if ($this->moveChangeOn($entry, $send->key)) {
            $this->dispatch('bs-toast', text: Cast::string(__('entries.chg.t_sent')));
        }
    }

    /**
     * «Duyệt đăng» / «Cập nhật website» asks first. Edits still in the form go into the change before
     * the question (what is asked about is what goes live).
     */
    public function askApplyChange(): void
    {
        $this->resetErrorBag('workflow');
        $entry = $this->changeEntry();
        $this->changeAct = app(EntryWorkflowResolver::class)->forEntry($entry) === null ? 'apply' : 'approve';
        if ($this->changeCount() > 0 && $this->pendingChangeIsOpen($entry)) {
            $entry = $this->persist('save');
            if (! $entry instanceof Entry || $this->heldSave !== 'saved') {
                return;
            }
            $this->resetSave();
        }
        if ($this->pendingChange($entry) === null) {
            $this->dispatch('bs-toast', text: Cast::string(__('entries.chg.t_same')));

            return;
        }
        $this->changeApplyAsk = true;
    }

    /**
     * The change goes live, in every language it holds: through the workflow's move to its published
     * stage (ApplyEntryDraftTransition), or — with no workflow — by the publish right
     * (ApplyEntryDraft). Then the form shows the website's version again.
     */
    public function applyChange(): void
    {
        $this->changeApplyAsk = false;
        $entry = $this->changeEntry();
        [$workflow, $change] = $this->changeContext($entry);
        $this->changeAct = $workflow === null ? 'apply' : 'approve';
        if ($change === null || $this->changeSeen === null) {
            $this->changeRefused('change_stale');

            return;
        }
        $langs = $this->localeNames(array_map('strval', array_keys($change->content['locales'])));
        if ($workflow === null) {
            try {
                app(ApplyEntryDraft::class)->handle($entry, $this->user(), $this->changeSeen);
            } catch (EntryDraftRejectedException $refused) {
                $this->changeRefused($refused->reason, $refused->path, $refused->locale);

                return;
            } catch (AuthorizationException) {
                $this->addError('workflow', Cast::string(__('admin.wfb.err_forbidden')));

                return;
            }
            $this->changeSettled(Cast::string(__('entries.chg.t_applied', ['langs' => $langs])));

            return;
        }
        $approve = collect(app(EntryWorkflowResolver::class)->changeMovesFor($workflow, $entry, $change, $this->user()))
            ->first(fn (WorkflowTransition $t): bool => $this->publishesAt($workflow, $t->to_state));
        if (! $approve instanceof WorkflowTransition) {
            $this->addError('workflow', Cast::string(__('admin.wfb.err_forbidden')));

            return;
        }
        if ($this->moveChangeOn($entry, $approve->key)) {
            $this->changeSettled(Cast::string(__('entries.chg.t_approved', ['langs' => $langs])));
        }
    }

    public function cancelChangeAsk(): void
    {
        $this->changeApplyAsk = false;
        $this->changeDiscardAsk = false;
    }

    public function askDiscardChange(): void
    {
        $this->changeAct = 'discard';
        $entry = $this->changeEntry();
        if ($this->pendingChange($entry) === null) {
            $this->changeRefused('change_stale');

            return;
        }
        $this->changeDiscardAsk = true;
    }

    /** «Bỏ thay đổi»: the change is thrown away (DiscardEntryDraft); the form shows the website's version again. */
    public function discardChange(): void
    {
        $this->changeDiscardAsk = false;
        $this->changeAct = 'discard';
        $entry = $this->changeEntry();
        try {
            app(DiscardEntryDraft::class)->handle($entry, $this->user(), (string) $this->changeSeen);
        } catch (EntryDraftRejectedException $refused) {
            $this->changeRefused($refused->reason, $refused->path, $refused->locale);

            return;
        } catch (AuthorizationException) {
            $this->addError('workflow', Cast::string(__('entries.err_update_forbidden')));

            return;
        }
        $this->changeSettled(Cast::string(__('entries.chg.t_discarded', ['noun' => $this->changeNoun()])));
    }

    public function openChangeDiff(): void
    {
        $this->changeEntry();
        $this->changeDiffOpen = true;
    }

    public function openChangeReturn(): void
    {
        $this->changeEntry();
        $this->resetErrorBag('changeNote');
        $this->changeNote = '';
        $this->changeReturnOpen = true;
    }

    /** «Trả lại»: the change goes back to its writer with the note (the workflow's move that needs one). */
    public function returnChange(): void
    {
        $this->changeAct = null;
        $entry = $this->changeEntry();
        $this->resetErrorBag('changeNote');
        $note = trim($this->changeNote);
        if ($note === '') {
            $this->addError('changeNote', Cast::string(__('entries.chg.return_err')));

            return;
        }
        if (mb_strlen($note) > 2000) {
            $this->addError('changeNote', Cast::string(__('validation.max.string', ['attribute' => __('admin.wfb.note_label'), 'max' => 2000])));

            return;
        }
        [$workflow, $change] = $this->changeContext($entry);
        $back = $workflow !== null && $change !== null
            ? collect(app(EntryWorkflowResolver::class)->changeMovesFor($workflow, $entry, $change, $this->user()))
                ->first(fn (WorkflowTransition $t): bool => $t->requires_note && ! $this->publishesAt($workflow, $t->to_state))
            : null;
        if (! $back instanceof WorkflowTransition) {
            $this->addError('changeNote', Cast::string(__('admin.wfb.err_forbidden')));

            return;
        }
        $writer = $change->updated_by !== null ? User::query()->whereKey($change->updated_by)->value('name') : null;
        if ($this->moveChangeOn($entry, $back->key, $note)) {
            $this->changeReturnOpen = false;
            $this->changeNote = '';
            $this->dispatch('bs-toast', text: Cast::string(__('entries.chg.t_returned', ['by' => is_string($writer) ? $writer : __('entries.chg.someone')])));
        }
    }

    /** A middle step of the workflow on the change (one that neither publishes nor needs a note: «Chuyển duyệt»). */
    public function moveChange(string $key): void
    {
        $this->changeAct = null;
        $entry = $this->changeEntry();
        [$workflow, $change] = $this->changeContext($entry);
        $move = $workflow !== null && $change !== null
            ? collect(app(EntryWorkflowResolver::class)->changeMovesFor($workflow, $entry, $change, $this->user()))
                ->first(fn (WorkflowTransition $t): bool => $t->key === $key && ! $t->requires_note && ! $this->publishesAt($workflow, $t->to_state))
            : null;
        if (! $move instanceof WorkflowTransition) {
            $this->addError('workflow', Cast::string(__('admin.wfb.err_stale')));

            return;
        }
        if ($this->moveChangeOn($entry, $move->key)) {
            $label = $workflow->states()->where('key', $move->to_state)->value('label');
            $this->dispatch('bs-toast', text: Cast::string(__('admin.wfb.moved', ['action' => $move->label, 'step' => is_string($label) ? $label : $move->to_state])));
        }
    }

    /** One move of the change through the engine; the refusal is said in the band. Returns whether it moved. */
    private function moveChangeOn(Entry $entry, string $key, ?string $note = null): bool
    {
        try {
            $to = app(ApplyEntryDraftTransition::class)->handle($entry, $this->locale, $this->user(), $key, (string) $this->changeSeen, $note);
        } catch (EntryDraftRejectedException $refused) {
            $this->changeRefused($refused->reason, $refused->path, $refused->locale);

            return false;
        } catch (InvalidTransitionException) {
            $this->addError('workflow', Cast::string(__('admin.wfb.err_stale')));

            return false;
        } catch (AuthorizationException) {
            $this->addError('workflow', Cast::string(__('admin.wfb.err_forbidden')));

            return false;
        } catch (EntryNotGovernedException) {
            $this->addError('workflow', Cast::string(__('admin.wfb.err_not_governed')));

            return false;
        }
        $this->changeMemo = false;
        $this->loadTrail($entry->id);
        $this->status = $entry->status;
        unset($to);

        return true;
    }

    /** After the change went live or was thrown away: the form shows the website's version, its history read again. */
    private function changeSettled(string $toast): void
    {
        $this->changeSeen = null;
        $this->reopenForm();
        $this->dispatch('bs-toast', text: $toast);
    }

    /**
     * The form opened again as it opens (mount): the website's version, then the pending change as it
     * stands now, which also sets the version this form saw from what it loaded (openPendingChange).
     * The rich text editor takes the new text, and the browser forgets the copy it kept on the device.
     */
    private function reopenForm(): void
    {
        $this->changeMemo = false;
        $this->resetChanges();
        if ($this->prose) {
            $this->dispatch('admin-editor-set-html', target: 'bodyHtml', html: $this->bodyHtml);
        }
        $this->dispatch('admin-editor-saved');
    }

    /**
     * The refusal in plain words. A save, a send, «Cập nhật website» / «Duyệt đăng» or «Bỏ thay đổi»
     * refused for a reason the board draws opens «Chưa làm được», titled by that move (a save or a send
     * refused because the change was sent is «Chưa sửa được»); a taken address of the change's own also stays
     * under the link field. Any other (a return or a middle step refused) is the line above the form.
     */
    private function changeRefused(string $reason, string $path = '', string $locale = ''): void
    {
        $text = $reason === 'subpage_path_taken'
            ? SubPageTakenText::say($path, $locale, $this->locale)
            : Cast::string(__('entries.change_err_'.$reason, ['path' => $path]));
        if ($reason === 'slug_taken') {
            $this->addError('slug', $text);
        }
        if ($this->changeAct === null || ! in_array($reason, self::REFUSAL_DIALOG, true)) {
            if ($reason === 'change_stale') {
                // no dialog, so no «Tải lại» button: the line says to reload the page
                $text = Cast::string(__('entries.change_err_change_stale_page'));
            }
            if ($reason !== 'slug_taken') {
                $this->addError('workflow', $text);
            }

            return;
        }
        $title = $reason === 'change_locked' && in_array($this->changeAct, ['save', 'submit'], true) ? 'entries.refuse.t_locked' : 'entries.refuse.t_'.$this->changeAct;
        $this->refusal = ['title' => Cast::string(__($title)), 'text' => $text, 'reload' => $reason === 'change_stale'];
    }

    /** «Đóng» (or Escape, the backdrop) on «Chưa làm được»: nothing else changes. */
    public function closeRefusal(): void
    {
        $this->refusal = null;
    }

    /**
     * «Tải lại» on «Chưa làm được» (the change moved on elsewhere): the form takes the change as it
     * stands now. Typed edits still in the form are never kept over it (they would be saved over
     * another member's edits nobody here has seen): with some, «Tải bản mới nhất?» asks first and
     * names the fields it replaces; with none, it loads at once. Only after a refusal that offered it.
     */
    public function reloadChange(): void
    {
        $this->changeEntry();
        if (($this->refusal['reload'] ?? false) !== true) {
            return;
        }
        $this->refusal = null;
        $names = $this->unsavedNames();
        if ($names !== []) {
            $this->reloadReplaces = implode(', ', $names);
            $this->changeReloadAsk = true;

            return;
        }
        $this->loadNewestChange();
    }

    /** «Tải bản mới nhất» on «Tải bản mới nhất?»: the typed edits go, the change as it stands now comes in. */
    public function confirmReloadChange(): void
    {
        $this->changeEntry();
        if ($this->reloadReplaces === null) {
            return;
        }
        $this->loadNewestChange();
    }

    /** «Ở lại» (or Escape, the backdrop) on «Tải bản mới nhất?»: the form keeps what was typed, nothing loaded. */
    public function keepTypedText(): void
    {
        $this->changeReloadAsk = false;
        $this->reloadReplaces = null;
    }

    private function loadNewestChange(): void
    {
        $this->changeReloadAsk = false;
        $this->reloadReplaces = null;
        $this->reopenForm();
        // the change may be gone (applied or discarded elsewhere): the form then shows the website's version
        $this->dispatch('bs-toast', text: Cast::string(__($this->changeSeen === null ? 'entries.refuse.reloaded_gone' : 'entries.refuse.reloaded')));
    }

    /** The saved entry, re-read and authorized for every call (livewire/update skips the route middleware). */
    private function changeEntry(): Entry
    {
        abort_if($this->ulid === null, 404);
        $entry = $this->resolveOrFail($this->ulid);
        $this->authorize('update', $entry);

        return $entry;
    }

    /** @return array{0: Workflow|null, 1: EntryDraft|null} */
    private function changeContext(Entry $entry): array
    {
        $this->changeMemo = false;

        return [app(EntryWorkflowResolver::class)->forEntry($entry), $this->pendingChange($entry)];
    }

    private function pendingChangeIsOpen(Entry $entry): bool
    {
        $change = $this->pendingChange($entry);

        return $change === null || app(EntryWorkflowResolver::class)->changeIsOpen(app(EntryWorkflowResolver::class)->forEntry($entry), $change);
    }

    private function publishesAt(Workflow $workflow, string $state): bool
    {
        return $workflow->states->firstWhere('key', $state)?->is_published === true;
    }

    /**
     * The bar's buttons on live content whose edits are held back (board 07); null when edits go
     * straight to the website (a member who may publish, no workflow, no change), as before.
     * «Gửi duyệt thay đổi» under a workflow (only for one who may send it), «Lưu thay đổi» for a
     * member who may not publish, «Cập nhật website» for one who may and finds a change; none while
     * the change waits for review (it is locked).
     *
     * @return array{secondary: string|null, primary: string|null, primaryAction: string, primaryIcon: string}|null
     */
    private function changeButtons(?Workflow $workflow, ?Entry $entry): ?array
    {
        if (! $entry instanceof Entry || $this->status !== 'published' || $this->statusTo !== '') {
            return null;
        }
        $save = Cast::string(__('entries.chg.btn_save'));
        if ($workflow === null) {
            return match (true) {
                ! $this->mayPublish => ['secondary' => null, 'primary' => $save, 'primaryAction' => 'save', 'primaryIcon' => 'ri-save-3'],
                $this->changeSeen !== null => ['secondary' => $save, 'primary' => Cast::string(__('entries.chg.btn_apply')), 'primaryAction' => 'askApplyChange', 'primaryIcon' => 'ri-global'],
                default => null,
            };
        }
        $change = $this->pendingChange($entry);
        $resolver = app(EntryWorkflowResolver::class);
        if ($change !== null && ! $resolver->changeIsOpen($workflow, $change)) {
            return ['secondary' => null, 'primary' => null, 'primaryAction' => 'save', 'primaryIcon' => 'ri-save-3'];
        }
        $from = $change !== null ? $resolver->changeOriginFor($workflow, $change) : $workflow->initialState()?->key;
        $user = $this->user();
        $send = $from !== null && collect(app(WorkflowEngine::class)->allowedTransitions($workflow, $from, $user))
            ->contains(fn (WorkflowTransition $t): bool => ! $t->requires_note && ! $this->publishesAt($workflow, $t->to_state) && $resolver->mayApply($t, $entry, $user));

        return $send
            ? ['secondary' => Cast::string(__('entries.ed.save_plain')), 'primary' => Cast::string(__('entries.chg.btn_send')), 'primaryAction' => 'submitChange', 'primaryIcon' => 'ri-send-plane']
            : ['secondary' => null, 'primary' => $save, 'primaryAction' => 'save', 'primaryIcon' => 'ri-save-3'];
    }

    /** What a held-back save says (the bar's save, the main button). */
    private function heldToast(?Workflow $workflow): string
    {
        return Cast::string(__(match (true) {
            $this->heldSave === 'same' => 'entries.chg.t_same',
            $workflow !== null => 'entries.chg.t_saved_wf',
            default => 'entries.chg.t_saved',
        }));
    }

    /**
     * After the entry left the website by a status or workflow move: the pending change the form was
     * editing went into it (EntryDraftFolder), so the form's values are now the entry's own. Returns
     * whether that happened (the caller says so).
     */
    private function changeFolded(Entry $entry): bool
    {
        if ($this->changeSeen === null || $entry->status === 'published') {
            return false;
        }
        $this->changeSeen = null;
        $this->changeMemo = false;
        $this->storedPrint = $this->storedNow($entry)['print'];

        return true;
    }

    /** The save state after a save into the change, as after a save to the website. */
    private function resetSave(): void
    {
        $this->saved = $this->tracked();
        $this->saved['scheduleAt'] = $this->scheduleLoaded;
        $this->saved['statusTo'] = '';
    }

    private function changeNoun(): string
    {
        return Cast::string(__($this->type === 'page' ? 'entries.chg.noun_page' : 'entries.chg.noun_post'));
    }

    /**
     * @param  list<string>  $locales
     */
    private function localeNames(array $locales): string
    {
        return implode(', ', array_map(static fn (string $code): string => Locales::label($code), $locales));
    }

    /**
     * The band above the form while the entry is live and its edits are held back (board 07): where
     * the change stands, what visitors see, and the moves this member may make — or the line that
     * says an edit will be held back, before there is a change. Null when edits go straight live.
     *
     * @return array{tone: 'info'|'warning', icon: string, head: string, sub: string, acts: list<array{do: string, label: string, icon: string, soft: bool, why: string|null, key: string|null}>, ask: array{title: string, ok: string, lines: list<string>}, discard: list<string>, return: array{by: string}, diff: array{sub: string, rows: list<array{lang: string|null, label: string, now: string, seen: string}>}}|null
     */
    private function changeBand(?Workflow $workflow, ?Entry $entry): ?array
    {
        if (! $entry instanceof Entry || $this->status !== 'published') {
            return null;
        }
        $change = $this->pendingChange($entry);
        $held = $workflow !== null || ! $this->mayPublish;
        if ($change === null && ! $held) {
            return null;
        }
        $noun = $this->changeNoun();
        $old = Cast::string(__('entries.chg.sub_old'));
        $act = static fn (string $do, string $label, string $icon, bool $soft = false, ?string $why = null, ?string $key = null): array => ['do' => $do, 'label' => Cast::string(__($label)), 'icon' => $icon, 'soft' => $soft, 'why' => $why, 'key' => $key];
        $none = ['tone' => 'info', 'icon' => 'ri-edit', 'acts' => [], 'ask' => ['title' => '', 'ok' => '', 'lines' => []], 'discard' => [], 'return' => ['by' => ''], 'diff' => ['sub' => '', 'rows' => []]];
        if ($change === null) {
            return ['head' => Cast::string(__('entries.chg.head_editing', ['noun' => $noun])),
                'sub' => Cast::string(__($workflow !== null ? 'entries.chg.sub_editing_wf' : 'entries.chg.sub_editing', ['noun' => $noun]))] + $none;
        }

        $diff = $this->changeDiff($entry, $change);
        $langs = $this->localeNames(array_values(array_unique(array_filter(array_column($diff['rows'], 'code')))));
        $count = $diff['rows'] !== [] ? ' '.Cast::string(__('entries.chg.sub_count', ['n' => count($diff['rows']), 'langs' => $langs !== '' ? $langs : Locales::label($this->locale)])) : '';
        $writer = $change->updated_by !== null ? User::query()->whereKey($change->updated_by)->value('name') : null;
        $by = is_string($writer) ? $writer : Cast::string(__('entries.chg.someone'));
        $at = app(SiteTime::class)->forDisplay($change->updated_at)?->format('H:i d/m') ?? '';
        $compare = $act('diff', 'entries.chg.btn_compare', 'ri-git-commit');
        $discard = $act('discard', 'entries.chg.btn_discard', 'ri-arrow-go-back');
        $resolver = app(EntryWorkflowResolver::class);
        $moves = $workflow !== null ? $resolver->changeMovesFor($workflow, $entry, $change, $this->user()) : [];

        $band = match (true) {
            $workflow === null && ! $this->mayPublish => ['tone' => 'info', 'icon' => 'ri-time',
                'head' => Cast::string(__('entries.chg.head_wait')),
                'sub' => $old.$count.' '.Cast::string(__($change->updated_by === $this->user()->id ? 'entries.chg.sub_saved_me' : 'entries.chg.sub_saved', ['at' => $at, 'by' => $by])),
                'acts' => [$discard]],
            $workflow === null => ['tone' => 'info', 'icon' => 'ri-git-pull-request',
                'head' => Cast::string(__('entries.chg.head_open', ['by' => $by, 'at' => $at])),
                'sub' => $old.$count.' '.Cast::string(__('entries.chg.sub_merge')),
                'acts' => [$compare, $act('apply', 'entries.chg.btn_apply', 'ri-global', true), $discard]],
            ! $resolver->changeIsOpen($workflow, $change) => ['tone' => 'warning', 'icon' => 'ri-lock',
                'head' => Cast::string(__('entries.chg.head_sent', ['noun' => $noun, 'by' => $by, 'at' => $at])),
                'sub' => $old.' '.Cast::string(__('entries.chg.sub_locked')).$count,
                'acts' => [...($moves !== [] ? [$compare] : []), ...$this->moveActs($workflow, $moves, $act),
                    $act('discard', 'entries.chg.btn_discard', 'ri-arrow-go-back', false, Cast::string(__('entries.change_err_change_pending')))]],
            default => $this->openBand($entry, $change, $noun, $by, $at, $old, [$compare, $discard]),
        };

        $draftLangs = $this->localeNames($diff['drafts']);
        $changeLangs = $this->localeNames(array_map('strval', array_keys($change->content['locales'])));

        return $band + [
            'ask' => [
                'title' => Cast::string(__($workflow !== null ? 'entries.chg.ask_approve' : 'entries.chg.ask_apply')),
                'ok' => Cast::string(__($workflow !== null ? 'entries.chg.btn_approve' : 'entries.chg.btn_apply')),
                'lines' => array_values(array_filter([
                    Cast::string(__('entries.chg.ask_langs', ['langs' => $changeLangs])),
                    Cast::string(__('entries.chg.ask_now')),
                    $draftLangs !== '' ? Cast::string(__('entries.chg.ask_draft', ['langs' => $draftLangs])) : '',
                    // a sent change takes no more edits: what was typed into the form since is left out, said so
                    $workflow !== null && $this->changeCount() > 0 && ! $resolver->changeIsOpen($workflow, $change)
                        ? Cast::string(__('entries.chg.ask_unsaved')) : '',
                ])),
            ],
            'discard' => [
                Cast::string(__('entries.chg.discard_what', ['n' => count($diff['rows']), 'by' => $by, 'langs' => $changeLangs])),
                Cast::string(__('entries.chg.discard_live', ['noun' => $noun])),
                Cast::string(__('entries.chg.discard_gone')),
            ],
            'return' => ['by' => $by],
            'diff' => [
                'sub' => Cast::string(__('entries.chg.diff_sub', ['n' => count($diff['rows']), 'langs' => $langs !== '' ? $langs : $changeLangs])),
                'rows' => array_map(static fn (array $row): array => ['lang' => $row['code'] !== null ? strtoupper($row['code']) : null, 'label' => $row['label'], 'now' => $row['now'], 'seen' => $row['seen']], $diff['rows']),
            ],
        ];
    }

    /**
     * A change at the workflow's first stage: sent back with a note, or not sent yet.
     *
     * @param  list<array{do: string, label: string, icon: string, soft: bool, why: string|null, key: string|null}>  $acts
     * @return array{tone: 'info'|'warning', icon: string, head: string, sub: string, acts: list<array{do: string, label: string, icon: string, soft: bool, why: string|null, key: string|null}>}
     */
    private function openBand(Entry $entry, EntryDraft $change, string $noun, string $by, string $at, string $old, array $acts): array
    {
        $last = $change->workflow_state !== null
            ? (app(WorkflowTrail::class)->lastMoves([$entry->id], WorkflowEngine::TARGET_CHANGE)[$entry->id] ?? null)
            : null;
        if ($last !== null && $last['note'] !== null && $last['to'] === $change->workflow_state) {
            $who = $last['actor_id'] !== null ? User::query()->whereKey($last['actor_id'])->value('name') : null;

            return ['tone' => 'warning', 'icon' => 'ri-reply',
                'head' => Cast::string(__('entries.chg.head_returned', [
                    'by' => is_string($who) ? $who : __('entries.chg.someone'),
                    'at' => app(SiteTime::class)->forDisplay($last['at'])?->format('H:i d/m') ?? $at,
                ])),
                'sub' => Cast::string(__('entries.chg.sub_returned', ['note' => Str::limit($last['note'], 300)])),
                'acts' => $acts];
        }

        return ['tone' => 'info', 'icon' => 'ri-draft',
            'head' => Cast::string(__('entries.chg.head_draft', ['noun' => $noun, 'by' => $by, 'at' => $at])),
            'sub' => Cast::string(__('entries.chg.sub_draft')),
            'acts' => $acts];
    }

    /**
     * The band's buttons for a change waiting at a review stage: approve (asked first), return (with
     * its note), and any middle step, each the engine's own move.
     *
     * @param  list<WorkflowTransition>  $moves
     * @return list<array{do: string, label: string, icon: string, soft: bool, why: string|null, key: string|null}>
     */
    private function moveActs(Workflow $workflow, array $moves, \Closure $act): array
    {
        $acts = [];
        foreach ($moves as $move) {
            /** @var array{do: string, label: string, icon: string, soft: bool, why: string|null, key: string|null} $one */
            $one = match (true) {
                $this->publishesAt($workflow, $move->to_state) => $act('apply', 'entries.chg.btn_approve', 'ri-check-double', true),
                $move->requires_note => $act('return', 'entries.chg.btn_return', 'ri-reply'),
                default => ['do' => 'move', 'label' => $move->label, 'icon' => 'ri-send-plane', 'soft' => false, 'why' => null, 'key' => $move->key],
            };
            if (! in_array($one['do'], array_column($acts, 'do'), true) || $one['do'] === 'move') {
                $acts[] = $one;
            }
        }

        return $acts;
    }

    /**
     * What differs between the change and what visitors see (PendingChangeDiff), read against the
     * entry's translations and main category as stored now.
     *
     * @return array{rows: list<array{code: string|null, label: string, now: string, seen: string}>, drafts: list<string>}
     */
    private function changeDiff(Entry $entry, EntryDraft $change): array
    {
        $locales = array_map('strval', array_keys($change->content['locales']));
        /** @var Collection<string, EntryTranslation> $live */
        $live = EntryTranslation::query()->where('entry_id', $entry->id)->whereIn('locale', $locales)->get()->keyBy('locale');
        $livePrimary = $this->type === 'post' ? ($this->storedCategories($entry)[0] ?? null) : null;
        $names = static fn (array $ids): array => array_map(Cast::string(...), TaxonomyTranslation::query()->whereIn('taxonomy_id', $ids)
            ->where('locale', app(SiteLocale::class)->default())->pluck('name', 'taxonomy_id')->all());

        // the covers named by id (a read only when one is)
        $covers = MediaRefs::rows([$change->content['shared']['cover_media_id'] ?? null, $entry->cover_media_id]);

        return app(PendingChangeDiff::class)->between($entry, $change, $live, $livePrimary, $names, app(SiteLocale::class)->enabled(), $covers);
    }
}
