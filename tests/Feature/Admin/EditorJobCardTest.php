<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Support\Locales;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\RestoreEntryRevision;
use App\Modules\Cms\Actions\SaveEntryDraft;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\JobDeadline;
use App\Modules\Cms\Support\JobFacts;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteLocale as SiteLanguages;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteLocale as SiteLocaleRow;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Rendering\TemplateCatalog;
use Livewire\Livewire;

/*
 * «Thông tin tuyển dụng» in the post editor (board 07): the short boxes and the label of a job post's
 * card (meta.facts, meta.badge), shown only while the post's main category lists its posts as jobs.
 * Each box's words are this language's, «Hạn nộp» is one more box (every language's), at most four in
 * all; the save goes through the rule every writer of the meta passes (JobFacts::errors).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** The `posts` collection with its own category «Tin tức» and a «Tuyển dụng» category listing jobs; returns both. */
function jcCategories(): array
{
    $news = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $news->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
    $jobs = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 1, 'list_template' => 'grid-job']);
    $jobs->translations()->create(['locale' => 'vi', 'name' => 'Tuyển dụng', 'slug' => 'tuyen-dung']);
    ContentSection::query()->create([
        'key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $news->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);
    SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);

    return [$news, $jobs];
}

function jcPost(Taxonomy $category, array $meta = []): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => $meta]);
    $entry->translations()->create(['locale' => 'vi', 'title' => 'Kỹ thuật viên CNC', 'slug' => 'ky-thuat-vien-cnc', 'status' => 'draft', 'body' => ['blocks' => []]]);
    $entry->assignCategories([$category->id]);

    return $entry->fresh();
}

function jcUser(array $permissions = ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($user, $permissions): void {
        grantRole($user, 'jc-'.count($permissions), $permissions);
    });
    app(TeamContext::class)->forget();

    return $user->fresh();
}

function jcFact(string $text, string $tone = 'plain'): array
{
    return ['text' => ['vi' => $text], 'tone' => $tone];
}

it('shows the card only for a post filed under a category that lists jobs, its own layout or the one it follows', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        [$news, $jobs] = jcCategories();
        $child = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0, 'parent_id' => $jobs->id, 'list_template' => TemplateCatalog::PARENT]);
        $child->translations()->create(['locale' => 'vi', 'name' => 'Kỹ thuật', 'slug' => 'ky-thuat']);

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => jcPost($jobs)->ulid])
            ->assertSeeHtml('data-entry-job')
            ->assertSee(__('entries.ed.job_card'))
            ->assertSee(__('entries.ed.job_hint', ['cat' => 'Tuyển dụng', 'max' => 4, 'chars' => 40]))
            ->assertSee(__('entries.ed.job_pv_none'));
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => jcPost($child)->ulid])->assertSeeHtml('data-entry-job');
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => jcPost($news)->ulid])->assertDontSeeHtml('data-entry-job');

        // moved to a category that does not list jobs: the card goes, its edits go back to what was read
        // (not saved, so not counted as unsaved), and its methods do nothing
        $form = Livewire::actingAs($user)->test(Editor::class, ['ulid' => jcPost($jobs, ['facts' => [jcFact('Nhà máy 2')], 'badge' => ['vi' => 'Gấp']])->ulid])
            ->set('jobFacts.0.text', 'Nhà máy 3')
            ->call('addJobFact', 'place')
            ->set('jobDeadline', '2026-12-15')
            ->set('jobBadge', '')
            ->set('categoryId', $news->id)
            ->assertDontSeeHtml('data-entry-job')
            ->assertSet('jobDeadline', '')
            ->assertSet('jobBadge', 'Gấp')
            ->call('addJobFact', 'place');
        expect($form->get('jobFacts'))->toBe([['key' => 'f0', 'text' => 'Nhà máy 2', 'accent' => false]]);
    });
});

it('saves the boxes, the deadline and the label in this language, and leaves other languages\' words as they are', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        SiteLocaleRow::query()->updateOrCreate(['locale' => 'en'], ['is_default' => false, 'enabled' => true, 'is_public' => true]);
        app(SiteLanguages::class)->forget();
        [, $jobs] = jcCategories();
        $post = jcPost($jobs);
        $post->translations()->create(['locale' => 'en', 'title' => 'CNC technician', 'slug' => 'cnc-technician', 'status' => 'draft', 'body' => ['blocks' => []]]);

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('addJobFact', 'place')
            ->assertSet('jobFacts.0.text', 'Nơi làm: ')
            ->set('jobFacts.0.text', 'Nơi làm: Nhà máy 2 – Thạch Thất')
            ->call('addJobFact', 'salary')
            ->assertSet('jobFacts.1.accent', true)
            ->set('jobFacts.1.text', '  Lương: 9 – 14 triệu/tháng ')
            ->set('jobDeadline', '2026-12-15')
            ->set('jobBadge', 'Tuyển gấp')
            ->assertSee(__('entries.ed.job_used_dl', ['n' => 3, 'max' => 4]))
            ->call('save')
            ->assertHasNoErrors();

        $meta = $post->fresh()->meta;
        expect($meta['facts'])->toEqual([
            ['text' => ['vi' => 'Nơi làm: Nhà máy 2 – Thạch Thất'], 'tone' => 'plain'],
            ['text' => ['vi' => 'Lương: 9 – 14 triệu/tháng'], 'tone' => 'accent'],
            ['text' => ['vi' => 'Hạn nộp: 15/12/2026', 'en' => 'Apply by: 15/12/2026'], 'tone' => 'plain'],
        ])->and($meta['badge'])->toBe(['vi' => 'Tuyển gấp'])
            ->and(JobFacts::errors($meta))->toBe([])
            ->and(app(JobDeadline::class)->deadlineOf($post->fresh())?->format('Y-m-d'))->toBe('2026-12-15');

        // the English form: the boxes are there, empty in English, the Vietnamese words as the hint
        $english = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en'])
            ->assertSet('jobFacts.0.text', '')
            ->assertSet('jobDeadline', '2026-12-15')
            ->assertSeeHtml('placeholder="Nơi làm: Nhà máy 2 – Thạch Thất"')
            ->set('jobFacts.0.text', 'Location: Plant 2')
            ->set('jobBadge', 'Urgent')
            ->call('save')
            ->assertHasNoErrors();

        $meta = $post->fresh()->meta;
        expect($meta['facts'][0])->toEqual(['text' => ['vi' => 'Nơi làm: Nhà máy 2 – Thạch Thất', 'en' => 'Location: Plant 2'], 'tone' => 'plain'])
            ->and($meta['facts'][1])->toBe(['text' => ['vi' => 'Lương: 9 – 14 triệu/tháng'], 'tone' => 'accent'])
            ->and($meta['facts'][2])->toEqual(['text' => ['vi' => 'Hạn nộp: 15/12/2026', 'en' => 'Apply by: 15/12/2026'], 'tone' => 'plain'])
            ->and($meta['badge'])->toEqual(['vi' => 'Tuyển gấp', 'en' => 'Urgent']);

        // saved again from the same form: what it wrote is not taken for someone else's save
        $english->set('jobFacts.0.text', 'Location: Plant 2, Hanoi')->call('save')->assertHasNoErrors();
        expect($english->get('stale'))->toBeNull()
            ->and($post->fresh()->meta['facts'][0]['text'])->toEqual(['vi' => 'Nơi làm: Nhà máy 2 – Thạch Thất', 'en' => 'Location: Plant 2, Hanoi']);
    });
});

it('reads the stored card back, and a save that changed nothing on it leaves it as stored', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        [, $jobs] = jcCategories();
        $stored = [
            'facts' => [jcFact('Nhà máy 2 – Thạch Thất'), jcFact('Toàn thời gian · SL: 3'), jcFact('Hạn nộp: 15/12/2026'), jcFact('Lương: 9 – 14 triệu/tháng', 'accent')],
            'badge' => ['vi' => 'Tuyển gấp'],
            'image_url' => 'site/a.jpg',
        ];
        $post = jcPost($jobs, $stored);

        $form = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSet('jobDeadline', '2026-12-15')
            ->assertSet('jobBadge', 'Tuyển gấp')
            ->assertSee(__('entries.ed.job_used_dl', ['n' => 4, 'max' => 4]))
            ->assertSee(__('entries.ed.job_full', ['max' => 4]))
            ->assertSeeHtml('aria-disabled="true"');
        expect(array_column($form->get('jobFacts'), 'text'))->toBe(['Nhà máy 2 – Thạch Thất', 'Toàn thời gian · SL: 3', 'Lương: 9 – 14 triệu/tháng']);

        // full: no fifth box
        $form->call('addJobFact', 'blank')->assertCount('jobFacts', 3);

        $form->set('title', 'Kỹ thuật viên vận hành máy CNC')->call('save')->assertHasNoErrors();
        expect($post->fresh()->meta)->toEqual($stored);
    });
});

it('takes the last box that reads as a deadline for «Hạn nộp», so a box typed the same way stays a box', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        [, $jobs] = jcCategories();
        $post = jcPost($jobs, ['facts' => [jcFact('Hạn nộp: 01/12/2026'), jcFact('Nhà máy 2'), jcFact('Hạn nộp: 15/12/2026')]]);

        $form = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])->assertSet('jobDeadline', '2026-12-15');
        expect(array_column($form->get('jobFacts'), 'text'))->toBe(['Hạn nộp: 01/12/2026', 'Nhà máy 2']);
    });
});

it('refuses boxes and labels past their limits, in plain words, and stores nothing', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        [, $jobs] = jcCategories();
        $post = jcPost($jobs, ['facts' => [jcFact('Nhà máy 2')]]);

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('jobFacts.0.text', str_repeat('a', 41))
            ->call('save')
            ->assertHasErrors(['jobFacts.0.text'])
            ->assertSee(__('entries.ed.job_fact_long', ['n' => 1, 'max' => 40]));
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('addJobFact', 'blank')
            ->call('touchJobFact', 1)
            ->assertHasErrors(['jobFacts.1.text'])
            ->assertSee(__('entries.ed.job_fact_empty', ['n' => 2]))
            ->call('save')
            ->assertHasErrors(['jobFacts.1.text']);
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('jobBadge', str_repeat('b', 25))
            ->call('save')
            ->assertHasErrors(['jobBadge'])
            ->assertSee(__('entries.ed.job_badge_long', ['max' => 24]));
        // five boxes sent by the browser anyway
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('jobFacts', array_map(static fn (int $i): array => ['key' => 'n'.$i, 'text' => 'Ô '.$i, 'accent' => false], range(0, 4)))
            ->call('save')
            ->assertHasErrors(['jobFacts'])
            ->assertSee(__('entries.ed.job_full', ['max' => 4]));
        // boxes sent under other keys than the form's list: refused as a whole, nothing stored
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('jobFacts', [7 => ['key' => 'n1', 'text' => '', 'accent' => false], 8 => ['key' => 'n2', 'text' => str_repeat('x', 60), 'accent' => false]])
            ->call('save')
            ->assertHasErrors(['jobFacts'])
            ->assertSee(__('entries.ed.job_invalid'));
        // four boxes and a deadline
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('jobFacts', array_map(static fn (int $i): array => ['key' => 'n'.$i, 'text' => 'Ô '.$i, 'accent' => false], range(0, 3)))
            ->set('jobDeadline', '2026-12-15')
            ->assertSet('jobDeadline', '')
            ->set('jobFacts', array_map(static fn (int $i): array => ['key' => 'n'.$i, 'text' => 'Ô '.$i, 'accent' => false], range(0, 2)))
            ->set('jobDeadline', '2026-02-30')
            ->assertHasErrors(['jobDeadline'])
            ->call('save')
            ->assertHasErrors(['jobDeadline']);

        expect($post->fresh()->meta)->toEqual(['facts' => [jcFact('Nhà máy 2')]]);
    });
});

it('removes a box or the deadline with «Hoàn tác», and toggles «Nổi bật»', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        [, $jobs] = jcCategories();
        $post = jcPost($jobs, ['facts' => [jcFact('Nhà máy 2'), jcFact('Toàn thời gian'), jcFact('Hạn nộp: 15/12/2026')]]);

        $form = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('removeJobFact', 0)
            ->assertSet('formToast', __('entries.ed.job_removed', ['text' => 'Nhà máy 2']))
            ->assertCount('jobFacts', 1)
            ->call('undoFormClear')
            ->assertCount('jobFacts', 2)
            ->assertSet('jobFacts.0.text', 'Nhà máy 2')
            ->call('clearJobDeadline')
            ->assertSet('jobDeadline', '')
            ->assertSet('formToast', __('entries.ed.job_dl_cleared'))
            ->call('undoFormClear')
            ->assertSet('jobDeadline', '2026-12-15')
            ->call('toggleJobAccent', 1)
            ->call('removeJobFact', 0)
            ->call('save')
            ->assertHasNoErrors();
        expect($post->fresh()->meta['facts'])->toBe([jcFact('Toàn thời gian', 'accent'), jcFact('Hạn nộp: 15/12/2026')]);
        expect($form->get('jobFacts'))->toHaveCount(1);
    });
});

it('changes the card\'s boxes, tone, deadline and label only in the default language; another language edits its own words', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        SiteLocaleRow::query()->updateOrCreate(['locale' => 'en'], ['is_default' => false, 'enabled' => true, 'is_public' => true]);
        app(SiteLanguages::class)->forget();
        [, $jobs] = jcCategories();
        $stored = ['facts' => [jcFact('Nhà máy 2'), jcFact('Toàn thời gian'), ['text' => ['vi' => 'Hạn nộp: 15/12/2026', 'en' => 'Apply by: 15/12/2026'], 'tone' => 'plain']]];
        $post = jcPost($jobs, $stored);
        $post->translations()->create(['locale' => 'en', 'title' => 'CNC technician', 'slug' => 'cnc-technician', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $why = __('entries.ed.shared_guard', ['lang' => Locales::label('vi')]);

        // English: the shape's buttons are not drawn, the reason is; asked anyway, nothing changes
        $english = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en'])
            ->assertSeeHtml('data-job-shared')
            ->assertSee($why)
            ->assertDontSeeHtml('wire:click="removeJobFact(0)"')
            ->call('removeJobFact', 0)->assertDispatched('bs-toast')
            ->call('toggleJobAccent', 1)
            ->call('addJobFact', 'place')
            ->call('clearJobDeadline')
            ->call('toggleJobDeadlineAccent')
            ->set('jobDeadline', '2026-12-31')
            ->assertSet('jobDeadline', '2026-12-15')
            ->assertSet('jobDeadlineAccent', false);
        expect(array_column($english->get('jobFacts'), 'key'))->toBe(['f0', 'f1'])
            ->and(array_column($english->get('jobFacts'), 'accent'))->toBe([false, false]);

        // a box taken out by a crafted request: the save refuses it, nothing is stored
        $english->set('jobFacts', [$english->get('jobFacts')[1]])->call('save')->assertHasErrors(['jobFacts'])->assertSee($why);
        // a label turned on from English: refused too
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en'])
            ->assertSeeHtml('id="entry-job-bd"')
            ->set('jobBadge', 'Urgent')->call('save')->assertHasErrors(['jobFacts']);
        expect($post->fresh()->meta)->toEqual($stored);

        // English words save
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en'])
            ->set('jobFacts.0.text', 'Plant 2')->set('jobFacts.1.text', 'Full time')->call('save')->assertHasNoErrors();
        expect($post->fresh()->meta['facts'])->toEqual([
            ['text' => ['vi' => 'Nhà máy 2', 'en' => 'Plant 2'], 'tone' => 'plain'],
            ['text' => ['vi' => 'Toàn thời gian', 'en' => 'Full time'], 'tone' => 'plain'],
            $stored['facts'][2],
        ]);

        // Vietnamese, the default: the shape changes and saves
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertDontSeeHtml('data-job-shared')
            ->call('removeJobFact', 0)
            ->call('toggleJobAccent', 0)
            ->call('toggleJobDeadlineAccent')
            ->set('jobBadge', 'Gấp')
            ->call('save')->assertHasNoErrors();
        expect($post->fresh()->meta)->toEqual([
            'facts' => [
                ['text' => ['vi' => 'Toàn thời gian', 'en' => 'Full time'], 'tone' => 'accent'],
                ['text' => ['vi' => 'Hạn nộp: 15/12/2026', 'en' => 'Apply by: 15/12/2026'], 'tone' => 'accent'],
            ],
            'badge' => ['vi' => 'Gấp'],
        ]);
    });
});

it('lets only a member who may edit the post change its card', function (): void {
    $editor = jcUser();
    $viewer = jcUser(['entries.view']);
    app(Tenancy::class)->enter(1, function () use ($editor, $viewer): void {
        [, $jobs] = jcCategories();
        $post = jcPost($jobs, ['facts' => [jcFact('Nhà máy 2')]]);

        // the editor does not open for them …
        Livewire::actingAs($viewer)->test(Editor::class, ['ulid' => $post->ulid])->assertForbidden();
        // … and a form opened before their right went cannot change the card or save it

        foreach ([['addJobFact', 'place'], ['removeJobFact', 0], ['toggleJobAccent', 0], ['clearJobDeadline'], ['touchJobFact', 0], ['save']] as $call) {
            $form = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])->set('jobFacts.0.text', 'Khác');
            $this->actingAs($viewer);
            $form->call(...$call)->assertForbidden();
        }
        expect($post->fresh()->meta)->toEqual(['facts' => [jcFact('Nhà máy 2')]]);
    });
});

it('reads the deadline box in each language the editor writes it', function (): void {
    expect(JobDeadline::dayOfText('Hạn nộp: 15/12/2026'))->toBe('2026-12-15')
        ->and(JobDeadline::dayOfText('Apply by: 1/2/2027'))->toBe('2027-02-01')
        ->and(JobDeadline::dayOfText('応募締切: 15/12/2026'))->toBe('2026-12-15')
        ->and(JobDeadline::dayOfText('截止日期: 15/12/2026'))->toBe('2026-12-15')
        ->and(JobDeadline::dayOfText('Hạn nộp: 30/02/2026'))->toBeNull()
        ->and(JobDeadline::dayOfText('Lương: 9 – 14 triệu'))->toBeNull();
});

/** The website's languages: Vietnamese (the default) and these others, enabled. */
function jcLanguages(string ...$locales): void
{
    foreach ($locales as $locale) {
        SiteLocaleRow::query()->updateOrCreate(['locale' => $locale], ['is_default' => false, 'enabled' => true, 'is_public' => true]);
    }
    app(SiteLanguages::class)->forget();
}

/** A box worded in Vietnamese and English. */
function jcFact2(string $vi, string $en, string $tone = 'plain'): array
{
    return ['text' => ['vi' => $vi, 'en' => $en], 'tone' => $tone];
}

/** jcPost() with an English page too ($status: both languages and the post). */
function jcPostEn(Taxonomy $category, array $meta = [], string $status = 'draft'): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => $status, 'meta' => $meta]);
    $entry->translations()->create(['locale' => 'vi', 'title' => 'Kỹ thuật viên CNC', 'slug' => 'ky-thuat-vien-cnc', 'status' => $status, 'body' => ['blocks' => []]]);
    $entry->translations()->create(['locale' => 'en', 'title' => 'CNC technician', 'slug' => 'cnc-technician', 'status' => $status, 'body' => ['blocks' => []]]);
    $entry->assignCategories([$category->id]);

    return $entry->fresh();
}

/** The card as it is later: on the website, or (held) in the post's pending change, the website keeping the older one. */
function jcNewer(Entry $post, array $meta, User $actor, bool $held): void
{
    if ($held) {
        app(SaveEntryDraft::class)->handle($post->fresh(), $actor, 'vi', [], ['meta' => $meta]);
    } else {
        $post->update(['meta' => $meta]);
    }
}

/** The post's history version of $locale as it is now. */
function jcRevision(Entry $post, string $locale, User $actor): EntryRevision
{
    $post = $post->fresh();
    app(RecordEntryRevision::class)->forLocale($post, $post->translationFor($locale), RevisionKind::Edit, $actor);

    return EntryRevision::query()->where('entry_id', $post->id)->where('locale', $locale)->latest('id')->firstOrFail();
}

it('keeps the card\'s shape on «Nổi bật» of «Hạn nộp» sent from another language, and on a kept copy brought back there', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        jcLanguages('en');
        [, $jobs] = jcCategories();
        $deadline = ['text' => ['vi' => 'Hạn nộp: 15/12/2026', 'en' => 'Apply by: 15/12/2026'], 'tone' => 'plain'];
        $stored = ['facts' => [jcFact('Nhà máy 2'), jcFact('Toàn thời gian'), $deadline]];
        $post = jcPostEn($jobs, $stored);
        $why = __('entries.ed.shared_guard', ['lang' => Locales::label('vi')]);

        // the accent set straight on the property: refused at the save
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en'])
            ->set('jobDeadlineAccent', true)->call('save')->assertHasErrors(['jobFacts'])->assertSee($why);
        expect($post->fresh()->meta)->toEqual($stored);

        // «Khôi phục» of a copy kept on this device: the English words come back box by box; the
        // boxes, their tone, the deadline and a label Vietnamese does not have stay as stored
        $english = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en'])
            ->call('restoreKept', [
                'jobFacts' => [
                    ['key' => 'f1', 'text' => 'Full time', 'accent' => true],
                    ['key' => 'f0', 'text' => 'Plant 2', 'accent' => true],
                    ['key' => 'n7', 'text' => 'Extra box', 'accent' => false],
                ],
                'jobDeadline' => '2027-01-31',
                'jobDeadlineAccent' => true,
                'jobBadge' => 'Urgent',
            ])
            ->assertSet('jobDeadline', '2026-12-15')
            ->assertSet('jobDeadlineAccent', false)
            ->assertSet('jobBadge', '');
        expect($english->get('jobFacts'))->toEqual([
            ['key' => 'f0', 'text' => 'Plant 2', 'accent' => false],
            ['key' => 'f1', 'text' => 'Full time', 'accent' => false],
        ]);
        $english->call('save')->assertHasNoErrors();
        expect($post->fresh()->meta)->toEqual(['facts' => [jcFact2('Nhà máy 2', 'Plant 2'), jcFact2('Toàn thời gian', 'Full time'), $deadline]]);

        // with a Vietnamese label, the English one comes back too
        $post->update(['meta' => $stored + ['badge' => ['vi' => 'Gấp']]]);
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en'])
            ->call('restoreKept', ['jobBadge' => 'Urgent'])
            ->assertSet('jobBadge', 'Urgent')
            ->call('save')->assertHasNoErrors();
        expect($post->fresh()->meta['badge'])->toEqual(['vi' => 'Gấp', 'en' => 'Urgent']);
    });
});

it('puts the card back to what was read when «Chuyên mục chính» moves to a category that does not list jobs', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        [, $jobs] = jcCategories();
        $events = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 2]);
        $events->translations()->create(['locale' => 'vi', 'name' => 'Sự kiện', 'slug' => 'su-kien']);
        $post = jcPost($jobs, ['facts' => [jcFact('Nhà máy 2')], 'badge' => ['vi' => 'Gấp']]);

        $form = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('jobFacts.0.text', 'Nhà máy 3')
            ->set('jobDeadline', '2026-12-15')
            ->set('jobBadge', '')
            ->call('toggleCategory', $events->ulid)
            ->assertSeeHtml('data-entry-job')
            ->assertSet('jobBadge', '')
            ->call('setMainCategory', $events->ulid)
            ->assertSet('categoryId', $events->id)
            ->assertDontSeeHtml('data-entry-job')
            ->assertSet('jobDeadline', '')
            ->assertSet('jobBadge', 'Gấp');
        expect($form->get('jobFacts'))->toBe([['key' => 'f0', 'text' => 'Nhà máy 2', 'accent' => false]]);
    });
});

it('takes the label off in every language when the default language empties it, and says so first', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        jcLanguages('en');
        [, $jobs] = jcCategories();
        $post = jcPostEn($jobs, ['facts' => [jcFact2('Nhà máy 2', 'Plant 2')], 'badge' => ['vi' => 'Gấp', 'en' => 'Urgent']]);

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSee(__('entries.ed.job_badge_others'))
            ->set('jobBadge', '')
            ->call('save')->assertHasNoErrors();
        expect($post->fresh()->meta)->toEqual(['facts' => [jcFact2('Nhà máy 2', 'Plant 2')]]);

        // no other language has one: no such line
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])->assertDontSee(__('entries.ed.job_badge_others'));

        // a label only another language has (left from before): the line shows; a save that leaves the
        // card as it is keeps it, and a save that changes the card, its label empty, takes it off
        $post->update(['meta' => ['facts' => [jcFact2('Nhà máy 2', 'Plant 2')], 'badge' => ['en' => 'Urgent']]]);
        $form = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSee(__('entries.ed.job_badge_others'))
            ->set('title', 'Kỹ thuật viên CNC (ca đêm)')
            ->call('save')->assertHasNoErrors();
        expect($post->fresh()->meta)->toEqual(['facts' => [jcFact2('Nhà máy 2', 'Plant 2')], 'badge' => ['en' => 'Urgent']]);
        $form->set('jobFacts.0.text', 'Nhà máy 2 – Thạch Thất')->call('save')->assertHasNoErrors()
            ->assertDontSee(__('entries.ed.job_badge_others'));
        expect($post->fresh()->meta)->toEqual(['facts' => [jcFact2('Nhà máy 2 – Thạch Thất', 'Plant 2')]]);
    });
});

it('lets another language empty a label the default language does not have, and never start one', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        jcLanguages('en', 'ja');
        [, $jobs] = jcCategories();
        $facts = [['text' => ['vi' => 'Nhà máy 2', 'en' => 'Plant 2', 'ja' => '第2工場'], 'tone' => 'plain']];
        $post = jcPostEn($jobs, ['facts' => $facts, 'badge' => ['en' => 'Urgent']]);
        $badgeField = static fn (string $html): bool => preg_match('/<input[^>]*id="entry-job-bd"[^>]*aria-readonly="true"/', $html) === 1;

        // English has one, Vietnamese none: English may empty its own (towards the shape Vietnamese has)
        $english = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en']);
        expect($badgeField($english->html()))->toBeFalse();
        $english->set('jobBadge', '')->call('save')->assertHasNoErrors();
        expect($post->fresh()->meta)->toEqual(['facts' => $facts]);

        // English none and Vietnamese none: the field is read-only, and a label sent anyway is refused
        // (Japanese having one, left from before, does not make it every language's)
        $post->update(['meta' => ['facts' => $facts, 'badge' => ['ja' => '急募']]]);
        $english = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en']);
        expect($badgeField($english->html()))->toBeTrue();
        $english->set('jobBadge', 'Urgent')->call('save')->assertHasErrors(['jobFacts']);
        expect($post->fresh()->meta)->toEqual(['facts' => $facts, 'badge' => ['ja' => '急募']]);

        // with a Vietnamese label, English writes its own
        $post->update(['meta' => ['facts' => $facts, 'badge' => ['vi' => 'Gấp', 'ja' => '急募']]]);
        $english = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en']);
        expect($badgeField($english->html()))->toBeFalse();
        $english->set('jobBadge', 'Urgent')->call('save')->assertHasNoErrors();
        expect($post->fresh()->meta['badge'])->toEqual(['vi' => 'Gấp', 'en' => 'Urgent', 'ja' => '急募']);
    });
});

it('restores another language\'s version with only its words on the card: the shape and the other languages\' words stay', function (array $permissions, bool $held): void {
    $user = jcUser($permissions);
    app(Tenancy::class)->enter(1, function () use ($user, $held): void {
        jcLanguages('en');
        [, $jobs] = jcCategories();
        $deadline = ['text' => ['vi' => 'Hạn nộp: 15/12/2026', 'en' => 'Apply by: 15/12/2026'], 'tone' => 'plain'];
        $post = jcPostEn($jobs, ['facts' => [jcFact2('Nhà máy 2', 'Plant 2'), jcFact2('Toàn thời gian', 'Full time'), $deadline], 'badge' => ['vi' => 'Gấp', 'en' => 'Urgent']], 'published');
        $old = jcRevision($post, 'en', $user);

        // later: Vietnamese drops the second box, accents the first, rewords the label; English rewords
        // its first box and its label (a box reworded in Vietnamese with another taken out is no longer
        // known by its place: see the test of pairing boxes by place)
        $now = ['facts' => [['text' => ['vi' => 'Nhà máy 2', 'en' => 'Plant 3'], 'tone' => 'accent'], $deadline], 'badge' => ['vi' => 'Gấp lắm', 'en' => 'Very urgent']];
        $live = $post->fresh()->meta;
        jcNewer($post, $now, $user, $held);

        app(RestoreEntryRevision::class)->handle($old, $user);

        $expected = ['facts' => [['text' => ['vi' => 'Nhà máy 2', 'en' => 'Plant 2'], 'tone' => 'accent'], $deadline], 'badge' => ['vi' => 'Gấp lắm', 'en' => 'Urgent']];
        if ($held) {
            expect($post->fresh()->meta)->toEqual($live)
                ->and(EntryDraft::query()->where('entry_id', $post->id)->sole()->content['shared']['meta'])->toEqual($expected);
        } else {
            expect($post->fresh()->meta)->toEqual($expected);
        }
    });
})->with([
    'on the website' => [['entries.view', 'entries.edit.any', 'entries.publish'], false],
    'into the pending change' => [['entries.view', 'entries.edit.any'], true],
]);

it('restores a default-language version with its shape and words, keeping other languages\' words for the boxes still there', function (array $permissions, bool $held): void {
    $user = jcUser($permissions);
    app(Tenancy::class)->enter(1, function () use ($user, $held): void {
        jcLanguages('en');
        [, $jobs] = jcCategories();
        $deadline = ['text' => ['vi' => 'Hạn nộp: 15/12/2026', 'en' => 'Apply by: 15/12/2026'], 'tone' => 'accent'];
        $post = jcPostEn($jobs, ['facts' => [jcFact2('Nhà máy 2', 'Plant 2'), jcFact2('Toàn thời gian', 'Full time'), $deadline], 'badge' => ['vi' => 'Gấp', 'en' => 'Urgent']], 'published');
        $old = jcRevision($post, 'vi', $user);

        // later: one box left (accented) and no deadline; English rewords that box and the label
        $now = ['facts' => [jcFact2('Nhà máy 2', 'Plant 3', 'accent')], 'badge' => ['vi' => 'Gấp lắm', 'en' => 'Very urgent']];
        $live = $post->fresh()->meta;
        jcNewer($post, $now, $user, $held);

        app(RestoreEntryRevision::class)->handle($old, $user);

        // the version's boxes, tone, deadline and label, in its Vietnamese words; English keeps its
        // words for the box still there, and the box back again has none in English yet
        $expected = ['facts' => [jcFact2('Nhà máy 2', 'Plant 3'), jcFact('Toàn thời gian'), $deadline], 'badge' => ['vi' => 'Gấp', 'en' => 'Very urgent']];
        $stored = $held ? EntryDraft::query()->where('entry_id', $post->id)->sole()->content['shared']['meta'] : $post->fresh()->meta;
        expect($stored)->toEqual($expected);
        if ($held) {
            expect($post->fresh()->meta)->toEqual($live);
        }
    });
})->with([
    'on the website' => [['entries.view', 'entries.edit.any', 'entries.publish'], false],
    'into the pending change' => [['entries.view', 'entries.edit.any'], true],
]);

it('restores a default-language version without a label: the label goes in every language', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        jcLanguages('en');
        [, $jobs] = jcCategories();
        $post = jcPostEn($jobs, ['facts' => [jcFact2('Nhà máy 2', 'Plant 2')]], 'published');
        $old = jcRevision($post, 'vi', $user);
        $post->update(['meta' => ['facts' => [jcFact2('Nhà máy 2', 'Plant 2')], 'badge' => ['vi' => 'Gấp', 'en' => 'Urgent']]]);

        app(RestoreEntryRevision::class)->handle($old, $user);

        expect($post->fresh()->meta)->toEqual(['facts' => [jcFact2('Nhà máy 2', 'Plant 2')]]);
    });
});

it('keeps the shape the default language saved when another language\'s older form keeps its own words', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        jcLanguages('en');
        [, $jobs] = jcCategories();
        $post = jcPostEn($jobs, ['facts' => [jcFact2('Nhà máy 2', 'Plant 2'), jcFact2('Toàn thời gian', 'Full time')], 'badge' => ['vi' => 'Gấp', 'en' => 'Urgent']]);

        // the English form is open; meanwhile Vietnamese drops the first box and accents the other
        $english = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en'])
            ->set('jobFacts.1.text', 'Full-time')
            ->set('jobBadge', 'Very urgent');
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('removeJobFact', 0)->call('toggleJobAccent', 0)
            ->call('save')->assertHasNoErrors();
        $shaped = ['facts' => [['text' => ['vi' => 'Toàn thời gian', 'en' => 'Full time'], 'tone' => 'accent']], 'badge' => ['vi' => 'Gấp', 'en' => 'Urgent']];
        expect($post->fresh()->meta)->toEqual($shaped);

        // the English form's own save by itself stops at the question
        $english->call('autosave');
        expect($english->get('stale'))->not->toBeNull()
            ->and($post->fresh()->meta)->toEqual($shaped);

        // «Giữ bản của tôi»: the English words go into the boxes Vietnamese left; the shape stays Vietnamese's
        $english->call('keepMine')->assertHasNoErrors();
        expect($post->fresh()->meta)->toEqual(['facts' => [['text' => ['vi' => 'Toàn thời gian', 'en' => 'Full-time'], 'tone' => 'accent']], 'badge' => ['vi' => 'Gấp', 'en' => 'Very urgent']])
            ->and(array_column($english->get('jobFacts'), 'text'))->toBe(['Full-time']);

        // and after a label Vietnamese took off meanwhile, the English one is not started again
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])->set('jobBadge', '')->call('save')->assertHasNoErrors();
        $english->set('jobFacts.0.text', 'Full time job')->call('save');
        $english->call('keepMine')->assertHasNoErrors();
        expect($post->fresh()->meta)->toEqual(['facts' => [['text' => ['vi' => 'Toàn thời gian', 'en' => 'Full time job'], 'tone' => 'accent']]])
            ->and($english->get('jobBadge'))->toBe('');
    });
});

it('matches a restored version\'s boxes to the card by their default-language words, and leaves an unchanged card unchanged', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        jcLanguages('en');
        [, $jobs] = jcCategories();
        $post = jcPostEn($jobs, ['facts' => [jcFact2('Nhà máy 2', 'Plant 2'), jcFact2('Toàn thời gian', 'Full time')], 'badge' => ['vi' => 'Gấp', 'en' => 'Urgent']], 'published');
        $english = jcRevision($post, 'en', $user);
        $vietnamese = jcRevision($post, 'vi', $user);

        // a card the version has as it is now comes back exactly as stored, key order too (the entry's
        // meta compares strictly: anything else would count as a change of every language's fields)
        $meta = $post->fresh()->meta;
        expect(JobFacts::restored($meta, $english->snapshot['meta'], 'en', 'vi'))->toBe($meta)
            ->and(JobFacts::restored($meta, $vietnamese->snapshot['meta'], 'vi', 'vi'))->toBe($meta);

        // Vietnamese took the first box out and English reworded the other: the English version's words
        // go to the box worded the same in Vietnamese, not to the box now first
        $post->update(['meta' => ['facts' => [jcFact2('Toàn thời gian', 'Full-time')]]]);
        app(RestoreEntryRevision::class)->handle($english, $user);
        expect($post->fresh()->meta)->toEqual(['facts' => [jcFact2('Toàn thời gian', 'Full time')]]);

        // the Vietnamese version brings the first box back: new to English, the other keeps its English
        $post->fresh()->update(['meta' => ['facts' => [jcFact2('Toàn thời gian', 'Full-time')]]]);
        app(RestoreEntryRevision::class)->handle($vietnamese, $user);
        expect($post->fresh()->meta)->toEqual(['facts' => [jcFact('Nhà máy 2'), jcFact2('Toàn thời gian', 'Full-time')], 'badge' => ['vi' => 'Gấp']]);
    });
});

it('keeps the words another language saved since, when the default language keeps its own card over them', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        jcLanguages('en');
        [, $jobs] = jcCategories();
        $post = jcPostEn($jobs, ['facts' => [jcFact2('Nhà máy 2', 'Plant 2'), jcFact2('Toàn thời gian', 'Full time')], 'badge' => ['vi' => 'Gấp', 'en' => 'Urgent']]);

        // the Vietnamese form is open; meanwhile English rewords both boxes and its label
        $vietnamese = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid]);
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en'])
            ->set('jobFacts.0.text', 'Plant 2 – Hanoi')->set('jobFacts.1.text', 'Full-time')->set('jobBadge', 'Very urgent')
            ->call('save')->assertHasNoErrors();

        // Vietnamese takes the first box out and rewords the other; its own save stops at the question
        $vietnamese->call('removeJobFact', 0)->set('jobFacts.0.text', 'Toàn thời gian (ca ngày)')->call('autosave');
        expect($vietnamese->get('stale'))->not->toBeNull();

        // «Giữ bản của tôi»: Vietnamese's card, with the English words as they are stored now
        $vietnamese->call('keepMine')->assertHasNoErrors();
        expect($post->fresh()->meta)->toEqual(['facts' => [jcFact2('Toàn thời gian (ca ngày)', 'Full-time')], 'badge' => ['vi' => 'Gấp', 'en' => 'Very urgent']]);
    });
});

it('pairs boxes by place only while both cards have as many boxes, on a restore and on another language\'s save', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        jcLanguages('en');
        [, $jobs] = jcCategories();
        $read = ['facts' => [jcFact2('Nhà máy 2', 'Plant 2'), jcFact2('Toàn thời gian', 'Full time')]];
        // Vietnamese replaced the first box with another and added one: no box is the first one reworded
        $replaced = ['facts' => [jcFact('Lương 15 triệu'), jcFact2('Toàn thời gian', 'Full-time'), jcFact('Ca đêm')]];

        // a restore of English words: the replacing box does not take the first box's
        expect(JobFacts::restored($replaced, $read, 'en', 'vi'))
            ->toEqual(['facts' => [jcFact('Lương 15 triệu'), jcFact2('Toàn thời gian', 'Full time'), jcFact('Ca đêm')]])
            // as many boxes: the one at the same place is the same box, reworded in Vietnamese
            ->and(JobFacts::restored(['facts' => [jcFact('Nhà máy 3'), jcFact2('Toàn thời gian', 'Full-time')]], $read, 'en', 'vi'))
            ->toEqual(['facts' => [jcFact2('Nhà máy 3', 'Plant 2'), jcFact2('Toàn thời gian', 'Full time')]]);

        // an English form read before the change keeps its words: they go only to the box worded the same
        $post = jcPostEn($jobs, $read);
        $english = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en'])
            ->set('jobFacts.0.text', 'Plant 2 – Hanoi')->set('jobFacts.1.text', 'Full time job');
        $post->update(['meta' => $replaced]);
        $english->call('autosave');
        expect($english->get('stale'))->not->toBeNull();
        $english->call('keepMine')->assertHasNoErrors();
        expect($post->fresh()->meta)->toEqual(['facts' => [jcFact('Lương 15 triệu'), jcFact2('Toàn thời gian', 'Full time job'), jcFact('Ca đêm')]]);
    });
});

it('keeps what another language typed in its card fields after its save, and saves the next change', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        jcLanguages('en');
        [, $jobs] = jcCategories();
        $post = jcPostEn($jobs, ['facts' => [jcFact2('Nhà máy 2', 'Plant 2'), jcFact2('Toàn thời gian', 'Full time')], 'badge' => ['vi' => 'Gấp', 'en' => 'Urgent']]);

        // halfway through a word when the draft saves itself: the fields stay as typed
        $english = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en'])
            ->set('jobFacts.0.text', 'Plant 2 ')->set('jobBadge', 'Very ')
            ->call('autosave')->assertHasNoErrors()
            ->assertSet('jobFacts.0.text', 'Plant 2 ')
            ->assertSet('jobBadge', 'Very ')
            ->assertSet('stale', null);
        expect($post->fresh()->meta)->toEqual(['facts' => [jcFact2('Nhà máy 2', 'Plant 2'), jcFact2('Toàn thời gian', 'Full time')], 'badge' => ['vi' => 'Gấp', 'en' => 'Very']]);

        // the next words go in as the next save's
        $english->set('jobFacts.0.text', 'Plant 2 Hanoi')->set('jobBadge', 'Very urgent')->call('autosave')->assertHasNoErrors()->assertSet('stale', null);
        expect($post->fresh()->meta)->toEqual(['facts' => [jcFact2('Nhà máy 2', 'Plant 2 Hanoi'), jcFact2('Toàn thời gian', 'Full time')], 'badge' => ['vi' => 'Gấp', 'en' => 'Very urgent']]);
    });
});

it('restores another language\'s version without its shown date, and a default-language version with it', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        jcLanguages('en');
        [, $jobs] = jcCategories();
        $facts = [jcFact2('Nhà máy 2', 'Plant 2')];
        $post = jcPostEn($jobs, ['facts' => $facts, 'display_date' => '2026-10-01'], 'published');
        $english = jcRevision($post, 'en', $user);
        $vietnamese = jcRevision($post, 'vi', $user);

        // later, Vietnamese moves the shown date
        $post->update(['meta' => ['facts' => $facts, 'display_date' => '2026-10-20']]);
        app(RestoreEntryRevision::class)->handle($english, $user);
        expect($post->fresh()->meta)->toEqual(['facts' => $facts, 'display_date' => '2026-10-20']);

        // none now: an English version does not bring one back either
        $post->fresh()->update(['meta' => ['facts' => $facts]]);
        app(RestoreEntryRevision::class)->handle($english, $user);
        expect($post->fresh()->meta)->toEqual(['facts' => $facts]);

        app(RestoreEntryRevision::class)->handle($vietnamese, $user);
        expect($post->fresh()->meta)->toEqual(['facts' => $facts, 'display_date' => '2026-10-01']);
    });
});

it('asks about another language\'s version as its restore writes it: a shown date of its own is no change of every language', function (): void {
    $user = jcUser();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        jcLanguages('en');
        [, $jobs] = jcCategories();
        $post = jcPostEn($jobs, ['facts' => [jcFact2('Nhà máy 2', 'Plant 2')], 'display_date' => '2026-10-01'], 'published');
        $old = jcRevision($post, 'en', $user);
        $dayLabel = __('entries.ed.pd_label', ['doc' => __('entries.ed.doc_post')]);

        // Vietnamese moved the shown date since: the English version changes nothing every language has
        $post->update(['meta' => ['facts' => [jcFact2('Nhà máy 2', 'Plant 2')], 'display_date' => '2026-10-20']]);
        $ask = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en'])
            ->call('loadRevisions')->call('askRestore', $old->ulid)->get('revAsk');
        expect($ask['shared'])->toBeFalse()
            ->and($ask['oneLocale'])->toBe(__('entries.rev.ask_one_loc', ['lang' => Locales::label('en')]));
        // and the version shown beside the form does not list the date it leaves as it is
        $view = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en'])
            ->call('loadRevisions')->call('viewRevision', $old->ulid)->get('revView');
        expect(array_column($view['fields'], 'label'))->not->toContain($dayLabel);

        // Vietnamese reworded the box too: the English words go onto the card as it is, still no change
        // of every language
        $post->update(['meta' => ['facts' => [jcFact2('Nhà máy 3', 'Plant 2')], 'display_date' => '2026-10-20']]);
        $ask = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en'])
            ->call('loadRevisions')->call('askRestore', $old->ulid)->get('revAsk');
        expect($ask['shared'])->toBeFalse();

        // English reworded its box since: the version's English words come back onto the card, which
        // every language's page shows
        $post->update(['meta' => ['facts' => [jcFact2('Nhà máy 3', 'Plant 2 – Hanoi')], 'display_date' => '2026-10-20']]);
        $ask = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en'])
            ->call('loadRevisions')->call('askRestore', $old->ulid)->get('revAsk');
        expect($ask['shared'])->toBeTrue()->and($ask['oneLocale'])->toBeNull();

        // in the default language the version beside the form lists its shown date
        $view = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('loadRevisions')->call('viewRevision', jcRevision($post, 'vi', $user)->ulid)->get('revView');
        expect(array_column($view['fields'], 'label'))->toContain($dayLabel);
    });
});
