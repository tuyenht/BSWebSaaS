<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Support\EntryProseBody;
use App\Modules\Admin\Support\LangBar;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteLocale as SiteLanguages;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The language bar of the post and page editor (board 07): one item per enabled language with the
 * state of its version, read from what is stored (no column of its own), shown only when the site
 * writes in more than one language, each version opening through the page's unsaved-changes question.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

function lbMember(array $permissions = ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish'], string $role = 'editor'): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, $role, $permissions));

    return $user->fresh() ?? $user;
}

/** Turn these languages on for the current site (Vietnamese stays the default). */
function lbLanguages(string ...$codes): void
{
    foreach ($codes as $code) {
        SiteLocale::query()->updateOrCreate(['locale' => $code], ['is_default' => false, 'enabled' => true, 'is_public' => $code !== 'ko']);
    }
    app(SiteLanguages::class)->forget();
}

/** A version with every translatable field filled. */
function lbFull(string $title, string $slug): array
{
    return ['title' => $title, 'slug' => $slug, 'status' => 'draft', 'excerpt' => 'Tóm tắt '.$title, 'seo_title' => 'SEO '.$title,
        'seo_description' => 'Mô tả '.$title, 'body' => ['blocks' => [['type' => EntryProseBody::TYPE, 'props' => ['html' => '<p>Nội dung '.$title.'</p>']]]]];
}

/** A post (or a page) whose Vietnamese version is complete. */
function lbPost(string $type = 'post'): Entry
{
    $post = Entry::query()->create(['type' => $type, 'status' => 'draft', 'meta' => []]);
    $post->translations()->create(['locale' => 'vi'] + lbFull('Bài', 'bai'));

    return $post->fresh() ?? $post;
}

/** @return array<string, array<string, mixed>> the bar's items by language */
function lbItems(mixed $component): array
{
    return collect($component->get('langBar'))->keyBy('code')->all();
}

it('shows nothing on a site that writes in one language', function (): void {
    $editor = lbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $post = lbPost();
        $c = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])->assertSet('langBar', []);
        expect($c->html())->not->toContain('data-lang-bar');
    });
});

it('shows nothing on an entry not saved yet', function (): void {
    $editor = lbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        lbLanguages('en');
        $c = Livewire::actingAs($editor)->test(Editor::class)->assertSet('langBar', []);
        expect($c->html())->not->toContain('data-lang-bar');
    });
});

it('shows the bar once a new entry has been saved', function (): void {
    $editor = lbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        lbLanguages('en');
        $c = Livewire::actingAs($editor)->test(Editor::class)->assertSet('langBar', [])
            ->set('title', 'Giới thiệu')->call('save')->assertHasNoErrors();
        expect(lbItems($c)['vi']['kind'])->toBe('source')->and(lbItems($c)['en']['kind'])->toBe('empty')
            ->and($c->html())->toContain('data-lang-bar', 'data-bs-lazy="bsLangBar"');
    });
});

it('gives each language the state of its version', function (): void {
    $editor = lbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        lbLanguages('en', 'ja', 'zh', 'ko', 'th');
        $post = lbPost();
        $post->translations()->create(['locale' => 'en'] + lbFull('Post', 'post'));
        // title, link and an empty paragraph: the body does not count
        $post->translations()->create(['locale' => 'ja', 'title' => '記事', 'slug' => 'kiji', 'status' => 'draft', 'body' => ['blocks' => [['type' => EntryProseBody::TYPE, 'props' => ['html' => '<p>&nbsp;</p>']]]]]);
        // a version with nothing filled is empty, as one never written (zh)
        $post->translations()->create(['locale' => 'th', 'title' => "\u{00A0} \u{00A0}", 'slug' => '', 'status' => 'draft', 'body' => ['blocks' => [['type' => EntryProseBody::TYPE, 'props' => ['html' => "<p>\u{00A0}</p>"]]]]]);
        $post->translations()->create(['locale' => 'ko'] + lbFull('게시물', 'gesimul'));
        // Korean was last written before the source changed
        EntryTranslation::query()->where('entry_id', $post->id)->where('locale', 'ko')->toBase()->update(['content_changed_at' => CarbonImmutable::now()->subDay()]);

        $items = lbItems(Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid]));

        expect(array_map(fn (array $i): string => $i['kind'], $items))
            ->toBe(['vi' => 'source', 'en' => 'saved', 'ja' => 'partial', 'zh' => 'empty', 'ko' => 'stale', 'th' => 'empty'])
            ->and(array_map(fn (array $i): int => $i['done'], $items))->toBe(['vi' => 6, 'en' => 6, 'ja' => 2, 'zh' => 0, 'ko' => 6, 'th' => 0])
            ->and($items['vi']['source'])->toBeTrue()->and($items['vi']['current'])->toBeTrue()->and($items['en']['current'])->toBeFalse()
            ->and($items['ko']['public'])->toBeFalse()->and($items['en']['label'])->toBe(__('entries.language_en'))
            ->and($items['vi']['href'])->toBe(route('admin.entries.edit', ['ulid' => $post->ulid]))
            ->and($items['ja']['href'])->toBe(route('admin.entries.edit', ['ulid' => $post->ulid, 'locale' => 'ja']));
    });
});

it('counts a body of blocks, and a picture in written text, as filled', function (): void {
    $editor = lbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        lbLanguages('en', 'ja');
        $post = lbPost();
        $post->translations()->create(['locale' => 'en', 'title' => 'Post', 'slug' => 'post', 'status' => 'draft', 'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'A']]]]]);
        $post->translations()->create(['locale' => 'ja', 'title' => '記事', 'slug' => 'kiji', 'status' => 'draft', 'body' => ['blocks' => [['type' => EntryProseBody::TYPE, 'props' => ['html' => '<p><img data-media-id="7" alt=""></p>']]]]]);

        $items = lbItems(Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en']));

        expect($items['en']['done'])->toBe(3)->and($items['ja']['done'])->toBe(3)
            ->and($items['en']['current'])->toBeTrue()->and($items['en']['kind'])->toBe('partial');
    });
});

it('moves the states after a save: the version saved, and the others when the source changes', function (): void {
    $editor = lbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        lbLanguages('en', 'ja');
        $post = lbPost('page');
        $post->translations()->create(['locale' => 'en'] + lbFull('Post', 'post'));
        EntryTranslation::query()->where('entry_id', $post->id)->where('locale', 'vi')->toBase()->update(['content_changed_at' => CarbonImmutable::now()->subDay()]);

        $ja = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'ja']);
        expect(lbItems($ja)['ja']['kind'])->toBe('empty');
        $ja->set('title', '記事')->set('slug', 'kiji')->call('save')->assertHasNoErrors();
        expect(lbItems($ja)['ja'])->toMatchArray(['kind' => 'partial', 'done' => 2]);

        // marks are kept to the second: the translations were written a minute before the source changes
        EntryTranslation::query()->where('entry_id', $post->id)->whereIn('locale', ['en', 'ja'])->toBase()->update(['content_changed_at' => CarbonImmutable::now()->subMinute()]);
        $vi = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid]);
        expect(lbItems($vi)['en']['kind'])->toBe('saved');
        $vi->set('title', 'Bài đã sửa')->call('save')->assertHasNoErrors();
        expect(lbItems($vi)['en']['kind'])->toBe('stale')->and(lbItems($vi)['ja']['kind'])->toBe('stale');
    });
});

it('opens another version through the unsaved-changes question, in the words of a switch', function (): void {
    $editor = lbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        lbLanguages('en');
        $post = lbPost();
        $c = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])->set('title', 'Đang gõ dở');
        $html = $c->html();
        $en = e(route('admin.entries.edit', ['ulid' => $post->ulid, 'locale' => 'en']));
        $body = e(__('admin.lb.sw_body', ['from' => __('entries.language_vi'), 'to' => __('entries.language_en')]));

        // the other version is a wire:navigate link, so the shared dialog stands between the typed text and it
        expect($html)->toContain('data-unsaved-guard', 'data-lang-bar')
            ->and(preg_match('/<a href="'.preg_quote($en, '/').'" class="lb-pill"[^>]*wire:navigate[^>]*data-ud-body="'.preg_quote($body, '/').'"/', $html))->toBe(1)
            ->and($html)->toContain('data-ud-save="'.e(__('admin.lb.sw_save')).'"', 'data-ud-discard="'.e(__('admin.lb.sw_discard')).'"')
            // the open version is not a way out
            ->and(preg_match('/class="lb-pill"[^>]*aria-current="true"[^>]*>/', $html, $open))->toBe(1)
            ->and($open[0])->not->toContain('wire:navigate')
            // the unsaved count the dialog reads still says the title is changed
            ->and($html)->toMatch('/data-unsaved-count="[1-9]/')
            // the editor's title takes the focus once the other version is open
            ->and($html)->toContain('id="entry-title"', '\\u0022focus\\u0022:\\u0022entry-title\\u0022');
    });
});

it('links «Quản lý ngôn ngữ» for a member who may change the site\'s languages only', function (): void {
    $editor = lbMember();
    $admin = lbMember(['entries.view', 'entries.edit.any', 'settings.manage'], 'manager');

    app(Tenancy::class)->enter(1, function () use ($editor, $admin): void {
        lbLanguages('en');
        $post = lbPost();
        $manage = 'href="'.e(route('admin.settings.languages')).'"';
        expect(Livewire::actingAs($admin)->test(Editor::class, ['ulid' => $post->ulid])->html())->toContain($manage, __('admin.lb.manage'))
            ->and(Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])->html())->not->toContain($manage);
    });
});

it('draws the selector alone, with a search box, past ten languages', function (): void {
    $editor = lbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        lbLanguages('en', 'ja', 'zh', 'ko', 'fr', 'de', 'th', 'es', 'it', 'pt');
        $html = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => lbPost()->ulid])->html();
        expect(substr_count($html, 'data-lb-row='))->toBe(11)
            ->and($html)->toMatch('/class="lb"[^>]*data-compact/')->toContain('aria-label="'.e(__('admin.lb.search_aria')).'"');
    });
});

it('reads every language\'s state in one query, however many languages the site has', function (): void {
    $editor = lbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        lbLanguages('en', 'ja');
        $few = lbPost();
        $few->translations()->create(['locale' => 'en'] + lbFull('Post', 'post'));
        $open = function (Entry $post) use ($editor): array {
            $reads = 0;
            $all = countQueries(function () use ($editor, $post, &$reads): void {
                DB::listen(function ($query) use (&$reads): void {
                    $reads += str_contains($query->sql, 'coalesce(content_changed_at, updated_at)') ? 1 : 0;
                });
                Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid]);
            });

            return [$all, $reads];
        };
        // the first editor opened after a change of languages fills the request's caches (site, languages, permissions)
        $open($few);
        [$fewQueries, $fewReads] = $open($few);

        lbLanguages('zh', 'ko', 'fr');
        $many = lbPost();
        foreach (['en', 'ja', 'zh', 'ko'] as $code) {
            $many->translations()->create(['locale' => $code] + lbFull('Post '.$code, 'post-'.$code));
        }
        $open($many);
        [$manyQueries, $manyReads] = $open($many);

        expect($fewReads)->toBe(1)->and($manyReads)->toBe(1)->and($manyQueries)->toBe($fewQueries);
    });
});

it('names a version stale only when the source changed after it, never on a status change', function (): void {
    $then = CarbonImmutable::parse('2026-11-01 10:00:00');

    expect(LangBar::kind(false, true, 6, 6, $then, $then->addMinute()))->toBe('stale')
        ->and(LangBar::kind(false, true, 6, 6, $then, $then))->toBe('saved')
        ->and(LangBar::kind(false, true, 3, 6, $then->addMinute(), $then))->toBe('partial')
        ->and(LangBar::kind(false, true, 0, 6, $then, $then->addDay()))->toBe('empty')
        ->and(LangBar::kind(true, true, 1, 6, $then, $then))->toBe('source')
        ->and(LangBar::kind(true, false, 0, 6, null, null))->toBe('empty');

    $editor = lbMember();
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        lbLanguages('en');
        $post = lbPost();
        $post->translations()->create(['locale' => 'en'] + lbFull('Post', 'post'));
        // a later change of the source's status alone moves its updated_at, not its content mark
        EntryTranslation::query()->where('entry_id', $post->id)->where('locale', 'vi')->firstOrFail()->forceFill(['status' => 'pending'])->save();

        expect(lbItems(Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid]))['en']['kind'])->toBe('saved');
    });
});
