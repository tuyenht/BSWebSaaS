<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Concerns;

use App\Modules\Admin\Support\Entries\JobCardForm;
use App\Modules\Admin\Support\Entries\JobFactText;
use App\Modules\Admin\Support\Locales;
use App\Modules\Cms\Support\JobFacts;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use Closure;
use Livewire\Attributes\Locked;

/**
 * «Thông tin tuyển dụng» in the post editor (board 07), behind «Thêm tùy chọn»: the short facts and
 * the badge a job post shows on its card and at the top of its page (JobFacts, `meta.facts` and
 * `meta.badge`), drawn only while the post's main category lists its posts as jobs (the «Tuyển
 * dụng» layout). Up to four boxes, each up to 40 characters in this language, «Nổi bật» for the
 * accent tone; «Hạn nộp» is one more box («Hạn nộp: dd/mm/yyyy») and counts as one of the four;
 * the badge up to 24 characters. Saved with the post (or into its pending change) through the same
 * rule a revision restore and a content pack pass (JobFacts::errors), in plain words first.
 * The card's shape (which boxes, their order and tone, the deadline, whether there is a label) is
 * every language's: like the shown date it changes in the website's default language only, and
 * another language edits only its own words (the save refuses a shape sent from there).
 */
trait EditsJobCard
{
    /**
     * The boxes in this language, in order, each {key, text, accent}: `key` names the box (its other
     * languages' texts are kept under it), `text` this language's words, `accent` «Nổi bật».
     * Client-set: read through jobFactList().
     *
     * @var array<array-key, mixed>
     */
    public array $jobFacts = [];

    /** «Hạn nộp»: the day (Y-m-d), '' for none. Every language's. */
    public string $jobDeadline = '';

    /** «Nổi bật» on the «Hạn nộp» box. */
    public bool $jobDeadlineAccent = false;

    /** «Nhãn trên thẻ» in this language. */
    public string $jobBadge = '';

    /**
     * What the form read (JobCardForm::load()['stored']): the other languages' texts, the deadline
     * as stored and the form's print. Empty: a form opened before the card existed, whose save
     * leaves the facts and the badge as stored. Server-set only.
     *
     * @var array{others: array<string, array<string, string>>, badge: array<string, string>, deadlineDay: string, deadlineTexts: array<string, string>, seq: int, print: string, form: array{facts: list<array{key: string, text: string, accent: bool}>, deadline: string, deadlineAccent: bool, badge: string}}|array{}
     */
    #[Locked]
    public array $jobStored = [];

    /** The deadline as the request found it, before a pick (updatedJobDeadline). */
    private string $jobDeadlineWas = '';

    /** The suggestion buttons under the boxes: [label key, the words put in the box, accent]. */
    private const array JOB_SUGGESTIONS = [
        'place' => ['entries.ed.job_sug_place', 'entries.ed.job_pre_place', false],
        'type' => ['entries.ed.job_sug_type', 'entries.ed.job_pre_type', false],
        'count' => ['entries.ed.job_sug_count', 'entries.ed.job_pre_count', false],
        'salary' => ['entries.ed.job_sug_salary', 'entries.ed.job_pre_salary', true],
        'blank' => ['entries.ed.job_sug_blank', null, false],
    ];

    /** «Thêm ô: …»: a box with the suggestion's words, focused; none past four (said, as the board does). */
    public function addJobFact(string $kind): void
    {
        $this->authorizeEditing();
        $suggestion = self::JOB_SUGGESTIONS[$kind] ?? null;
        if ($suggestion === null || ! $this->jobOn() || $this->jobShapeHeld()) {
            return;
        }
        $facts = $this->jobFactList();
        if ($this->jobUsed() >= JobFacts::FACTS_MAX) {
            $this->dispatch('bs-toast', text: Cast::string(__('entries.ed.job_full', ['max' => JobFacts::FACTS_MAX])));

            return;
        }
        $stored = $this->jobStored;
        if ($stored === []) {
            return;
        }
        $seq = $stored['seq'];
        $stored['seq'] = $seq + 1;
        $this->jobStored = $stored;
        $facts[] = ['key' => 'n'.$seq, 'text' => $suggestion[1] !== null ? Cast::string(__($suggestion[1], [], $this->locale)) : '', 'accent' => $suggestion[2]];
        $this->jobFacts = $facts;
        $this->focusJob('entry-jf-'.(count($facts) - 1));
    }

    /** «Bỏ ô»: the box leaves the card (saved with the next save); the message offers «Hoàn tác». */
    public function removeJobFact(int $index): void
    {
        $this->authorizeEditing();
        $facts = $this->jobFactList();
        if (! $this->jobOn() || ! isset($facts[$index]) || $this->jobShapeHeld()) {
            return;
        }
        $before = $this->jobSnapshot();
        $text = JobCardForm::clean($facts[$index]['text']);
        array_splice($facts, $index, 1);
        $this->jobFacts = $facts;
        $this->resetErrorBag($this->jobErrorKeys());
        $this->formSay(Cast::string(__('entries.ed.job_removed', ['text' => $text !== '' ? $text : __('entries.ed.job_removed_empty')])), 'jobCard', $before);
        $this->focusJob($facts !== [] ? 'entry-jf-'.max(0, $index - 1) : 'entry-job-dl');
    }

    /** «Nổi bật» on a box: the accent tone on the card, or back to plain. */
    public function toggleJobAccent(int $index): void
    {
        $this->authorizeEditing();
        $facts = $this->jobFactList();
        if (! $this->jobOn() || ! isset($facts[$index]) || $this->jobShapeHeld()) {
            return;
        }
        $facts[$index]['accent'] = ! $facts[$index]['accent'];
        $this->jobFacts = $facts;
    }

    /** «Nổi bật» on the «Hạn nộp» box. */
    public function toggleJobDeadlineAccent(): void
    {
        $this->authorizeEditing();
        if ($this->jobOn() && $this->jobDeadline !== '' && ! $this->jobShapeHeld()) {
            $this->jobDeadlineAccent = ! $this->jobDeadlineAccent;
        }
    }

    /** «Bỏ Hạn nộp»: the box leaves the card (saved with the next save); the message offers «Hoàn tác». */
    public function clearJobDeadline(): void
    {
        $this->authorizeEditing();
        if (! $this->jobOn() || $this->jobDeadline === '' || $this->jobShapeHeld()) {
            return;
        }
        $before = $this->jobSnapshot();
        $this->jobDeadline = '';
        $this->jobDeadlineAccent = false;
        $this->resetErrorBag('jobDeadline');
        $this->formSay(Cast::string(__('entries.ed.job_dl_cleared')), 'jobCard', $before);
        $this->focusJob('entry-job-dl');
    }

    /** A box left empty: it says so (as the save would) until it has words or is removed. */
    public function touchJobFact(int $index): void
    {
        $this->authorizeEditing();
        $facts = $this->jobFactList();
        if (! $this->jobOn() || ! isset($facts[$index])) {
            return;
        }
        $this->resetErrorBag('jobFacts.'.$index.'.text');
        $why = $this->jobFactError($facts[$index], $index);
        if ($why !== null) {
            $this->addError('jobFacts.'.$index.'.text', $why);
        }
    }

    /** A day picked in «Hạn nộp»: none while the four boxes are used without it; a day that is not one says so. */
    public function updatedJobDeadline(mixed $value): void
    {
        $this->authorizeEditing();
        $this->resetErrorBag('jobDeadline');
        $day = is_string($value) ? trim($value) : '';
        if ($this->jobStored !== [] && $this->sharedLocked()) {
            // every language's: kept as it is outside the default language
            $this->jobDeadline = $this->jobDeadlineWas;

            return;
        }
        if ($day !== '' && $this->jobDeadlineWas === '' && count($this->jobFactList()) >= JobFacts::FACTS_MAX) {
            $this->jobDeadline = '';

            return;
        }
        $this->jobDeadline = $day;
        if ($day !== '' && ! JobCardForm::validDay($day)) {
            $this->addError('jobDeadline', Cast::string(__('entries.ed.job_dl_invalid')));
        }
    }

    public function updatingJobDeadline(): void
    {
        $this->authorizeEditing();
        $this->jobDeadlineWas = $this->jobDeadline;
    }

    /** The card's state read from $meta (a post's, a pending change's). */
    private function loadJobCard(mixed $meta): void
    {
        if ($this->type !== 'post') {
            return;
        }
        $form = JobCardForm::load(is_array($meta) ? $meta : null, $this->locale);
        $this->jobFacts = $form['facts'];
        $this->jobDeadline = $form['deadline'];
        $this->jobDeadlineAccent = $form['deadlineAccent'];
        $this->jobBadge = $form['badge'];
        $this->jobStored = $form['stored'];
    }

    /** Whether the card shows and is saved: a post read with it, filed under a job list. */
    private function jobOn(): bool
    {
        return $this->type === 'post' && $this->jobStored !== [] && $this->categoryId !== null && $this->choices()->isJobList($this->categoryId);
    }

    /**
     * The card's shape is every language's: outside the website's default language a change to it
     * is not made, and the editor says where to make it.
     */
    private function jobShapeHeld(): bool
    {
        if (! $this->sharedLocked()) {
            return false;
        }
        $this->dispatch('bs-toast', text: $this->jobSharedWhy());

        return true;
    }

    /**
     * Outside the default language, a badge the default language does not have and this one had not
     * either: its field is read-only (wording it would start a badge; JobCardForm::sameShape).
     */
    private function jobBadgeHeld(): bool
    {
        return $this->sharedLocked() && $this->jobStored !== [] && JobCardForm::badgeHeld($this->jobStored, app(SiteLocale::class)->default());
    }

    /** Why the shape stays: the words the shown date uses («… hãy sửa ở bản Tiếng Việt»). */
    private function jobSharedWhy(): string
    {
        return Cast::string(__('entries.ed.shared_guard', ['lang' => Locales::label(app(SiteLocale::class)->default())]));
    }

    /**
     * The main category moved to one that does not list jobs: the card goes, and its edits with it,
     * back to what was read (they are not saved, so they do not count as unsaved either).
     */
    private function jobFollowCategory(): void
    {
        $stored = $this->jobStored;
        if ($stored === [] || $this->jobOn()) {
            return;
        }
        $this->jobFacts = $stored['form']['facts'];
        $this->jobDeadline = $stored['form']['deadline'];
        $this->jobDeadlineAccent = $stored['form']['deadlineAccent'];
        $this->jobBadge = $stored['form']['badge'];
        $this->resetErrorBag([...$this->jobErrorKeys(), 'jobDeadline', 'jobBadge']);
    }

    /**
     * $meta with the card's facts and badge, as a save stores them (unchanged when the card is off or
     * the form is as read).
     *
     * @param  array<array-key, mixed>  $meta
     * @return array<array-key, mixed>
     */
    private function jobMeta(array $meta): array
    {
        $stored = $this->jobStored;
        if ($stored === [] || ! $this->jobOn()) {
            return $meta;
        }

        $locales = app(SiteLocale::class);

        return JobCardForm::into($meta, $this->jobFactList(), $this->jobDeadline, $this->jobDeadlineAccent, $this->jobBadge, $stored, $this->locale, $locales->enabled(), $locales->default());
    }

    /**
     * After a save wrote $meta: the form is what is stored now, its fields kept as they are (what was
     * typed while the save ran stays). Outside the default language what the form remembers is read
     * again from $meta, whose shape may be another save's (JobCardForm::words): only then (boxes
     * added, taken out or moved meanwhile) do the boxes and the deadline come from $meta, and the badge
     * only when the save could not keep it (the default language took the badge off meanwhile).
     *
     * @param  array<array-key, mixed>|null  $meta
     */
    private function jobSaved(?array $meta): void
    {
        $stored = $this->jobStored;
        if ($stored === [] || ! $this->jobOn()) {
            return;
        }
        if ($this->sharedLocked() && $meta !== null) {
            $now = JobCardForm::load($meta, $this->locale);
            $default = app(SiteLocale::class)->default();
            if (! JobCardForm::holdsBoxes($this->jobFactList(), $this->jobDeadline, $this->jobDeadlineAccent, $stored, $now['stored'], $this->locale, $default)) {
                $this->jobFacts = $now['facts'];
                $this->jobDeadline = $now['deadline'];
                $this->jobDeadlineAccent = $now['deadlineAccent'];
            }
            if (JobCardForm::clean($this->jobBadge) !== JobCardForm::clean($now['badge'])) {
                $this->jobBadge = $now['badge'];
            }
            $stored = $now['stored'];
        }
        $this->jobStored = JobCardForm::saved($meta ?? [], $this->jobFactList(), $this->jobDeadline, $this->jobDeadlineAccent, $this->jobBadge, $stored);
    }

    /**
     * The card's rules, in plain words (the save checks them with the rest of the form).
     *
     * @return array<string, list<mixed>>
     */
    private function jobRules(): array
    {
        if (! $this->jobOn()) {
            return [];
        }

        return [
            'jobFacts' => ['array', function (string $attribute, mixed $value, Closure $fail): void {
                // the boxes as the form draws them: a list of boxes, so each box's own check below reads the
                // box it names (a request with other keys would slip past those checks)
                if (! array_is_list($this->jobFacts) || array_filter($this->jobFacts, is_array(...)) !== $this->jobFacts) {
                    $fail(Cast::string(__('entries.ed.job_invalid')));

                    return;
                }
                $facts = $this->jobFactList();
                $stored = $this->jobStored;
                $locales = app(SiteLocale::class);
                if ($stored !== [] && $this->sharedLocked() && ! JobCardForm::sameShape($facts, $this->jobDeadline, $this->jobDeadlineAccent, $this->jobBadge, $stored, $locales->default())) {
                    $fail($this->jobSharedWhy());

                    return;
                }
                if ($this->jobUsed() > JobFacts::FACTS_MAX) {
                    $fail(Cast::string(__('entries.ed.job_full', ['max' => JobFacts::FACTS_MAX])));

                    return;
                }
                foreach ($facts as $index => $fact) {
                    if ($this->jobFactError($fact, $index) !== null) {
                        return;   // said on that box
                    }
                }
                if (JobCardForm::length($this->jobBadge) > JobFacts::BADGE_MAX || ($this->jobDeadline !== '' && ! JobCardForm::validDay($this->jobDeadline))) {
                    return;   // said on that field
                }
                // the rule the stored meta must pass, whoever writes it (checked with the other languages'
                // words as the form read them: the save takes them as stored then, each passed by its own save)
                if ($stored !== [] && JobFacts::errors(JobCardForm::build([], $facts, $this->jobDeadline, $this->jobDeadlineAccent, $this->jobBadge, $stored, $this->locale, $locales->enabled(), $locales->default())) !== []) {
                    $fail(Cast::string(__('entries.ed.job_invalid')));
                }
            }],
            // judged when empty too (a box with no words is refused): an implicit rule
            'jobFacts.*.text' => ['string', new JobFactText(function (string $attribute, Closure $fail): void {
                $index = (int) explode('.', $attribute)[1];
                $fact = $this->jobFactList()[$index] ?? null;
                $why = $fact !== null ? $this->jobFactError($fact, $index) : null;
                if ($why !== null) {
                    $fail($why);
                }
            })],
            'jobFacts.*.accent' => ['boolean'],
            'jobDeadline' => ['string', static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && $value !== '' && ! JobCardForm::validDay($value)) {
                    $fail(Cast::string(__('entries.ed.job_dl_invalid')));
                }
            }],
            'jobDeadlineAccent' => ['boolean'],
            'jobBadge' => ['string', static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && JobCardForm::length($value) > JobFacts::BADGE_MAX) {
                    $fail(Cast::string(__('entries.ed.job_badge_long', ['max' => JobFacts::BADGE_MAX])));
                }
            }],
        ];
    }

    /**
     * Why a box cannot be stored: no words in any language, or too many in this one.
     *
     * @param  array{key: string, text: string, accent: bool}  $fact
     */
    private function jobFactError(array $fact, int $index): ?string
    {
        $length = JobCardForm::length($fact['text']);
        if ($length > JobFacts::FACT_TEXT_MAX) {
            return Cast::string(__('entries.ed.job_fact_long', ['n' => $index + 1, 'max' => JobFacts::FACT_TEXT_MAX]));
        }
        if ($length === 0 && ($this->jobStored['others'][$fact['key']] ?? []) === []) {
            return Cast::string(__('entries.ed.job_fact_empty', ['n' => $index + 1]));
        }

        return null;
    }

    /**
     * The boxes as the form holds them, each with a key, words and a tone (whatever the browser sent).
     *
     * @return list<array{key: string, text: string, accent: bool}>
     */
    private function jobFactList(): array
    {
        $facts = [];
        $seen = [];
        foreach (array_values($this->jobFacts) as $index => $fact) {
            if (! is_array($fact)) {
                continue;
            }
            $key = $fact['key'] ?? null;
            $key = is_string($key) && preg_match('/^[fn]\d{1,4}$/', $key) === 1 && ! isset($seen[$key]) ? $key : 'x'.$index;
            $seen[$key] = true;
            $text = $fact['text'] ?? '';
            $facts[] = ['key' => $key, 'text' => is_string($text) ? $text : '', 'accent' => ($fact['accent'] ?? false) === true];
        }

        return $facts;
    }

    /** The boxes used: the short boxes and «Hạn nộp». */
    private function jobUsed(): int
    {
        return count($this->jobFactList()) + ($this->jobDeadline !== '' ? 1 : 0);
    }

    /** @return list<string> */
    private function jobErrorKeys(): array
    {
        $keys = ['jobFacts'];
        for ($i = 0; $i <= JobFacts::FACTS_MAX; $i++) {
            $keys[] = 'jobFacts.'.$i.'.text';
        }

        return $keys;
    }

    /** The card's form, for «Hoàn tác». */
    private function jobSnapshot(): string
    {
        return (string) json_encode(['facts' => $this->jobFactList(), 'deadline' => $this->jobDeadline, 'deadlineAccent' => $this->jobDeadlineAccent]);
    }

    /** «Hoàn tác» after «Bỏ ô» / «Bỏ Hạn nộp»: the boxes as they were. */
    private function jobUndo(string $snapshot): void
    {
        $before = json_decode($snapshot, true);
        if (! is_array($before) || ! $this->jobOn()) {
            return;
        }
        $this->jobFacts = is_array($before['facts'] ?? null) ? array_values($before['facts']) : [];
        $this->jobFacts = $this->jobFactList();
        $this->jobDeadline = is_string($before['deadline'] ?? null) ? $before['deadline'] : '';
        $this->jobDeadlineAccent = ($before['deadlineAccent'] ?? false) === true;
    }

    /**
     * «Khôi phục» of the text kept on this device: the card's fields, each checked for its type.
     *
     * @param  array<array-key, mixed>  $kept
     */
    private function restoreKeptJob(array $kept): void
    {
        if ($this->jobStored === []) {
            return;
        }
        if ($this->sharedLocked()) {
            // another language: only its words come back, box by box (the shape is every language's)
            $words = [];
            foreach (is_array($kept['jobFacts'] ?? null) ? $kept['jobFacts'] : [] as $fact) {
                if (is_array($fact) && is_string($fact['key'] ?? null) && is_string($fact['text'] ?? null)) {
                    $words[$fact['key']] = $fact['text'];
                }
            }
            $this->jobFacts = array_map(static fn (array $fact): array => ['text' => $words[$fact['key']] ?? $fact['text']] + $fact, $this->jobFactList());
            if (is_string($kept['jobBadge'] ?? null) && ! $this->jobBadgeHeld()) {
                $this->jobBadge = $kept['jobBadge'];
            }

            return;
        }
        if (is_array($kept['jobFacts'] ?? null)) {
            $this->jobFacts = array_values($kept['jobFacts']);
            $this->jobFacts = array_slice($this->jobFactList(), 0, JobFacts::FACTS_MAX);
        }
        if (is_string($kept['jobDeadline'] ?? null) && ($kept['jobDeadline'] === '' || JobCardForm::validDay($kept['jobDeadline']))) {
            $this->jobDeadline = $kept['jobDeadline'];
        }
        if (is_bool($kept['jobDeadlineAccent'] ?? null)) {
            $this->jobDeadlineAccent = $kept['jobDeadlineAccent'];
        }
        if (is_string($kept['jobBadge'] ?? null)) {
            $this->jobBadge = $kept['jobBadge'];
        }
    }

    /** The card's boxes as words, for the stale-save question: this form's. */
    private function jobSummary(): string
    {
        $parts = [];
        foreach ($this->jobFactList() as $fact) {
            $text = JobCardForm::clean($fact['text']);
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        if ($this->jobDeadline !== '' && JobCardForm::validDay($this->jobDeadline)) {
            $parts[] = JobCardForm::deadlineText($this->jobDeadline, $this->locale);
        }
        $badge = JobCardForm::clean($this->jobBadge);

        return implode(' · ', $parts).($badge !== '' ? ' ['.$badge.']' : '');
    }

    /**
     * The same words for a stored meta.
     *
     * @param  array<array-key, mixed>|null  $meta
     */
    private function jobSummaryOf(?array $meta): string
    {
        $read = JobFacts::read($meta, $this->locale);

        return implode(' · ', array_column($read['facts'], 'text')).($read['badge'] !== '' ? ' ['.$read['badge'].']' : '');
    }

    /**
     * What the card draws (null: it does not show).
     *
     * @return array<string, mixed>|null
     */
    private function jobCard(): ?array
    {
        if (! $this->jobOn() || $this->categoryId === null) {
            return null;
        }
        $default = app(SiteLocale::class)->default();
        $stored = $this->jobStored;
        $facts = [];
        foreach ($this->jobFactList() as $index => $fact) {
            $source = $this->jobStored['others'][$fact['key']][$default] ?? null;
            $facts[] = $fact + [
                'n' => $index + 1,
                'placeholder' => $this->locale !== $default && is_string($source) && $source !== ''
                    ? $source
                    : ($index < 4 ? Cast::string(__('entries.ed.job_ph_'.($index + 1))) : ''),
            ];
        }
        $used = $this->jobUsed();
        $full = $used >= JobFacts::FACTS_MAX;
        $chip = $this->jobDeadline !== '' && JobCardForm::validDay($this->jobDeadline) ? JobCardForm::deadlineText($this->jobDeadline, $this->locale) : '';
        $suggestions = [];
        foreach (self::JOB_SUGGESTIONS as $kind => [$label, $words, $accent]) {
            $text = $words !== null ? trim(Cast::string(__($words, [], $this->locale))) : '';
            $suggestions[] = [
                'kind' => $kind,
                'label' => Cast::string(__($label)),
                'tip' => match (true) {
                    $full => Cast::string(__('entries.ed.job_full_tip', ['max' => JobFacts::FACTS_MAX])),
                    $kind === 'blank' => Cast::string(__('entries.ed.job_add_blank_tip')),
                    default => Cast::string(__($accent ? 'entries.ed.job_add_tip_acc' : 'entries.ed.job_add_tip', ['text' => $text])),
                },
            ];
        }

        return [
            'category' => $this->choices()->name($this->categoryId) ?? '',
            'facts' => $facts,
            'used' => $used,
            'full' => $full,
            'usedText' => Cast::string(__($this->jobDeadline !== '' ? 'entries.ed.job_used_dl' : 'entries.ed.job_used', ['n' => $used, 'max' => JobFacts::FACTS_MAX])),
            'suggestions' => $suggestions,
            'chip' => $chip,
            'deadlineLocked' => $full && $this->jobDeadline === '',
            'deadlineHint' => match (true) {
                $chip !== '' => Cast::string(__('entries.ed.job_dl_hint_set', ['chip' => $chip, 'max' => JobFacts::FACTS_MAX])),
                $full && $this->jobDeadline === '' => Cast::string(__('entries.ed.job_dl_hint_full', ['max' => JobFacts::FACTS_MAX])),
                default => Cast::string(__('entries.ed.job_dl_hint', ['chip' => __('entries.ed.job_dl_hint_sample'), 'max' => JobFacts::FACTS_MAX])),
            },
            'set' => $facts !== [] || $this->jobDeadline !== '' || trim($this->jobBadge) !== '',
            // another language than the default: only this language's words change (the shape is every language's)
            'locked' => $this->sharedLocked(),
            'badgeLocked' => $this->jobBadgeHeld(),
            // the default language: other languages word the badge too, and emptying it here takes it off theirs
            'badgeOthers' => ! $this->sharedLocked() && $stored !== [] && $stored['badge'] !== [],
            'why' => $this->jobSharedWhy(),
        ];
    }

    /** Puts the caret at the end of a field once the card is drawn again (board: the new box, the box before). */
    private function focusJob(string $id): void
    {
        $this->js('requestAnimationFrame(() => { const el = document.getElementById('.json_encode($id).'); if (el) { el.focus(); try { const n = el.value.length; el.setSelectionRange(n, n) } catch (e) {} } })');
    }
}
