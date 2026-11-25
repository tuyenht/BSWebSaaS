<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Admin\Support\Entries\ScheduleInput;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\RestoreEntryRevision;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\RevisionRestoreRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\DisplayDate;
use App\Modules\Cms\Validation\EntryContentRules;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteLocale as SiteLanguages;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteLocale as SiteLocaleRow;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\Checksum;

/*
 * «Thêm tùy chọn» → «Thuộc tính bài» in the post editor (board 07): «Mẫu hiển thị» (the post's own
 * look, entries.template_override: '' = its category's, `hero`, `wide`) and «Ngày đăng hiện trên
 * bài» (meta.display_date, Y-m-d; empty = the real day). Both are every language's, saved live or
 * into the pending change, kept in each version, and checked wherever they are stored.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** The `posts` collection (category «Tin tức», category permalinks); returns the category. */
function poCollection(): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
    ContentSection::query()->create([
        'key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);
    SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);

    return $category;
}

/** A draft post in $category, with $template and $meta. */
function poPost(Taxonomy $category, ?string $template = null, array $meta = []): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'template_override' => $template, 'meta' => $meta]);
    $entry->translations()->create(['locale' => 'vi', 'title' => 'Máy phay mới', 'slug' => 'may-phay-moi', 'status' => 'draft', 'body' => ['blocks' => []]]);
    $entry->assignCategories([$category->id]);

    return $entry->fresh();
}

function poEditor(array $permissions = ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($user, $permissions): void {
        grantRole($user, 'po-editor-'.count($permissions), $permissions);
    });
    app(TeamContext::class)->forget();

    return $user->fresh();
}

it('shows «Thuộc tính bài» behind «Thêm tùy chọn» on a post, and not on a page', function (): void {
    $user = poEditor();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $post = poPost(poCollection());
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSeeHtml('data-post-props')
            ->assertSeeHtml('aria-controls="entry-post-props"')
            // the side column's open/closed state is a well-formed Alpine component (one opening quote)
            ->assertSeeHtml('x-data="{ more: false, init()')
            ->assertDontSeeHtml('x-data=""{')
            ->assertSee(__('entries.ed.more_sub_post'))
            ->assertSee(__('entries.ed.pd_hint'))
            ->assertSeeHtml('<option value="hero">')
            ->assertSeeHtml('<option value="wide">');

        $page = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'draft', 'body' => ['blocks' => []]]);
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $page->ulid])
            ->assertDontSeeHtml('data-post-props')
            ->assertSeeHtml('x-data="{ more: false, init()');
    });
});

it('stores the look and the shown day, shows them again, and empties them back to the defaults', function (): void {
    $user = poEditor();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $post = poPost(poCollection());

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('templateKey', 'hero')
            ->set('displayDate', '2026-09-15')
            ->call('save')
            ->assertHasNoErrors();
        $stored = $post->fresh();
        expect($stored->template_override)->toBe('hero')
            ->and($stored->meta)->toMatchArray(['display_date' => '2026-09-15']);

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSet('templateKey', 'hero')
            ->assertSet('displayDate', '2026-09-15')
            ->assertSeeHtml('data-more-open')
            ->set('templateKey', '')
            ->set('displayDate', '')
            ->call('save')
            ->assertHasNoErrors();
        $stored = $post->fresh();
        expect($stored->template_override)->toBeNull()
            ->and($stored->meta)->not->toHaveKey('display_date');
    });
});

it('keeps a layout the form does not offer when the look is left on the default', function (): void {
    $user = poEditor();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $post = poPost(poCollection(), 'project');

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSet('templateKey', '')
            ->set('title', 'Máy phay năm trục')
            ->call('save')
            ->assertHasNoErrors();
        expect($post->fresh()->template_override)->toBe('project');
    });
});

it('refuses a look the theme does not draw and a day that does not exist, storing nothing', function (string $field, string $value, string $message): void {
    $user = poEditor();
    app(Tenancy::class)->enter(1, function () use ($user, $field, $value, $message): void {
        $post = poPost(poCollection());

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->set($field, $value)
            ->call('save')
            ->assertHasErrors([$field])
            ->assertSee(__($message));
        $stored = $post->fresh();
        expect($stored->template_override)->toBeNull()
            ->and($stored->meta ?? [])->not->toHaveKey('display_date');
    });
})->with([
    'a layout of the theme' => ['templateKey', 'page', 'entries.ed.tpl_invalid'],
    'a made-up name' => ['templateKey', '../evil', 'entries.ed.tpl_invalid'],
    'the 30th of February' => ['displayDate', '2026-02-30', 'entries.ed.pd_invalid'],
    'not a day' => ['displayDate', '15/09/2026', 'entries.ed.pd_invalid'],
]);

it('puts back the look and the day kept on this device, and ignores a bad kept value', function (): void {
    $user = poEditor();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $post = poPost(poCollection());

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('restoreKept', ['templateKey' => 'wide', 'displayDate' => '2026-09-15'])
            ->assertSet('templateKey', 'wide')
            ->assertSet('displayDate', '2026-09-15')
            ->call('restoreKept', ['templateKey' => 'evil', 'displayDate' => '2026-13-01'])
            ->assertSet('templateKey', 'wide')
            ->assertSet('displayDate', '2026-09-15');
    });
});

it('holds the look and the day in the pending change of a member who may not publish, until it is applied', function (): void {
    $publisher = poEditor();
    $writer = poEditor(['entries.view', 'entries.edit.any']);
    app(Tenancy::class)->enter(1, function () use ($publisher, $writer): void {
        $post = poPost(poCollection());
        app(PublishEntry::class)->handle($post->fresh(), 'vi', $publisher);

        Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('templateKey', 'wide')
            ->set('displayDate', '2026-09-15')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('bs-toast', text: __('entries.chg.t_saved'));
        $live = $post->fresh();
        $change = EntryDraft::query()->where('entry_id', $post->id)->firstOrFail();
        expect($live->template_override)->toBeNull()
            ->and($live->meta ?? [])->not->toHaveKey('display_date')
            ->and($change->content['shared']['template_override'] ?? null)->toBe('wide')
            ->and($change->content['shared']['meta'] ?? [])->toMatchArray(['display_date' => '2026-09-15']);

        // the change opens with its own values, and applying it puts them live
        Livewire::actingAs($publisher)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSet('templateKey', 'wide')
            ->assertSet('displayDate', '2026-09-15')
            ->call('askApplyChange')
            ->call('applyChange')
            ->assertHasNoErrors();
        $live = $post->fresh();
        expect($live->template_override)->toBe('wide')
            ->and($live->meta)->toMatchArray(['display_date' => '2026-09-15']);
    });
});

it('keeps the look and the day in each version and restores them, refusing a version with a bad day', function (): void {
    $user = poEditor();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $post = poPost(poCollection(), 'hero', ['display_date' => '2026-09-15']);
        app(PublishEntry::class)->handle($post->fresh(), 'vi', $user);
        $post = $post->fresh();
        app(RecordEntryRevision::class)->forLocale($post, $post->translationFor('vi'), RevisionKind::Edit, $user);
        $version = EntryRevision::query()->where('entry_id', $post->id)->orderByDesc('id')->firstOrFail();
        expect($version->snapshot['template_override'] ?? null)->toBe('hero')
            ->and($version->snapshot['meta'] ?? [])->toMatchArray(['display_date' => '2026-09-15']);

        $post->update(['template_override' => null, 'meta' => []]);
        app(RestoreEntryRevision::class)->handle($version->fresh(), $user);
        expect($post->fresh()->template_override)->toBe('hero')
            ->and($post->fresh()->meta)->toMatchArray(['display_date' => '2026-09-15']);

        $bad = $version->snapshot;
        $bad['meta']['display_date'] = '2026-02-30';
        DB::table('entry_revisions')->where('id', $version->id)->update(['snapshot' => json_encode($bad)]);
        expect(fn () => app(RestoreEntryRevision::class)->handle($version->fresh(), $user))->toThrow(RevisionRestoreRejectedException::class);
    });
});

it('leaves the look and the day to the default language: another language\'s form shows them locked and stores nothing', function (): void {
    $user = poEditor();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        SiteLocaleRow::query()->updateOrCreate(['locale' => 'en'], ['is_default' => false, 'enabled' => true, 'is_public' => true]);
        app(SiteLanguages::class)->forget();
        $post = poPost(poCollection(), 'hero', ['display_date' => '2026-09-15']);
        $post->translations()->create(['locale' => 'en', 'title' => 'New milling machine', 'slug' => 'new-milling-machine', 'status' => 'draft', 'body' => ['blocks' => []]]);

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en'])
            ->assertSet('locale', 'en')
            ->assertSeeHtml('<select id="entry-tpl" wire:model="templateKey" disabled')
            ->set('templateKey', 'wide')
            ->set('displayDate', '2026-01-01')
            ->set('title', 'A new milling machine')
            ->call('save')
            ->assertHasNoErrors();
        $stored = $post->fresh();
        expect($stored->template_override)->toBe('hero')
            ->and($stored->meta)->toMatchArray(['display_date' => '2026-09-15'])
            ->and($stored->translationFor('en')?->title)->toBe('A new milling machine');
    });
});

it('leaves the look and the day as stored when a form opened before they existed is saved', function (): void {
    $user = poEditor();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $post = poPost(poCollection());
        $form = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid]);

        // another member sets both meanwhile
        $post->update(['template_override' => 'hero', 'meta' => ['display_date' => '2026-09-15']]);

        // the form as the editor before these fields left it in the browser: neither field nor their marks in its snapshot
        $snapshot = $form->snapshot;
        unset($snapshot['checksum'], $snapshot['data']['templateKey'], $snapshot['data']['displayDate'], $snapshot['data']['postPropsRead'],
            $snapshot['data']['storedPrint'][0]['template'], $snapshot['data']['storedPrint'][0]['display_date']);
        $snapshot['checksum'] = Checksum::generate($snapshot);
        $form->snapshot = $snapshot;

        $form->assertSet('templateKey', '')
            ->set('title', 'Máy phay năm trục')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('stale', null);
        $stored = $post->fresh();
        expect($stored->template_override)->toBe('hero')
            ->and($stored->meta)->toMatchArray(['display_date' => '2026-09-15'])
            ->and($stored->translationFor('vi')?->title)->toBe('Máy phay năm trục');
    });
});

it('reads only real days written as YYYY-MM-DD', function (mixed $value, bool $ok): void {
    expect(DisplayDate::valid($value))->toBe($ok)
        ->and(app(EntryContentRules::class)->metaErrors(['display_date' => $value]) === [])->toBe($ok);
})->with([
    ['2026-09-15', true],
    ['2024-02-29', true],
    ['2026-02-29', false],
    ['2026-9-15', false],
    ['15/09/2026', false],
    ['', false],
    [20260915, false],
    [null, false],
]);

it('lets a stored shown day after today through a save that keeps it, and refuses one this save moves there', function (): void {
    $user = poEditor();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $zone = app(SiteTime::class)->zone();
        $later = CarbonImmutable::now()->addDays(5)->setTimezone($zone)->format('Y-m-d');
        $other = CarbonImmutable::now()->addDays(6)->setTimezone($zone)->format('Y-m-d');
        $post = poPost(poCollection(), meta: ['display_date' => $later]);

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSet('storedDisplayDate', $later)
            ->set('title', 'Máy phay mới về')
            ->call('save')
            ->assertHasNoErrors('displayDate')
            ->set('displayDate', $other)
            ->call('save')
            ->assertHasErrors('displayDate');
        expect($post->fresh()->meta)->toMatchArray(['display_date' => $later]);
    });
});

it('says a post past its end time cannot go live, from «Đăng», a schedule and the list\'s bulk «Đăng»', function (string $road): void {
    $user = poEditor();
    app(Tenancy::class)->enter(1, function () use ($user, $road): void {
        $post = poPost(poCollection());
        $why = __('entries.expired_publish_why');
        $post->forceFill(['expires_at' => $road === 'schedule' ? now()->addHour() : now()->subMinute()])->save();

        match ($road) {
            'publish' => expect(Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
                ->call('publish')
                ->assertHasErrors('workflow')
                ->errors()->first('workflow'))->toBe($why),
            'schedule' => expect(Livewire::actingAs($user)->test(Editor::class, ['ulid' => $post->ulid])
                ->set('statusTo', 'scheduled')
                ->set('scheduleAt', ScheduleInput::tomorrowEight())
                ->call('save')
                ->errors()->all())->toContain($why),
            'bulk' => Livewire::actingAs($user)->test(Index::class)
                ->set('selected', [$post->ulid])
                ->call('bulkPublish')
                ->assertDispatched('bs-toast', text: __('entries.bulk_published', ['count' => 0]).' '.__('entries.bulk_expired', ['count' => 1])),
        };
        expect($post->fresh()->status)->toBe('draft');
    });
})->with(['publish', 'schedule', 'bulk']);

it('says an approver\'s publish move past the end time is refused for that reason, not as a missing right', function (): void {
    $approver = poEditor(['entries.view', 'entries.edit.any', 'entries.review', 'entries.approve']);
    app(Tenancy::class)->enter(1, function () use ($approver): void {
        $post = poPost(poCollection());
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        $post->forceFill(['status' => 'pending', 'workflow_state' => 'pending', 'expires_at' => now()->subMinute()])->save();

        expect(Livewire::actingAs($approver)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('applyTransition', 'approve')
            ->assertHasErrors('workflow')
            ->errors()->first('workflow'))->toBe(__('entries.expired_publish_why'))
            ->and($post->fresh()->status)->toBe('pending');
    });
});
