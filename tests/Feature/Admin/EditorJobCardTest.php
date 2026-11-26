<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Cms\Models\Entry;
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

        // moved to a category that does not list jobs: the card goes, and its methods do nothing
        $form = Livewire::actingAs($user)->test(Editor::class, ['ulid' => jcPost($jobs)->ulid])
            ->set('categoryId', $news->id)
            ->assertDontSeeHtml('data-entry-job')
            ->call('addJobFact', 'place');
        expect($form->get('jobFacts'))->toBe([]);
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
            ->call('toggleJobDeadlineAccent')
            ->call('save')
            ->assertHasNoErrors();

        $meta = $post->fresh()->meta;
        expect($meta['facts'][0])->toEqual(['text' => ['vi' => 'Nơi làm: Nhà máy 2 – Thạch Thất', 'en' => 'Location: Plant 2'], 'tone' => 'plain'])
            ->and($meta['facts'][1])->toBe(['text' => ['vi' => 'Lương: 9 – 14 triệu/tháng'], 'tone' => 'accent'])
            ->and($meta['facts'][2])->toEqual(['text' => ['vi' => 'Hạn nộp: 15/12/2026', 'en' => 'Apply by: 15/12/2026'], 'tone' => 'accent'])
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
