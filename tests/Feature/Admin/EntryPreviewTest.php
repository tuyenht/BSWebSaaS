<?php

use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Forms\Models\Form;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use Illuminate\Support\Str;

/*
 * «Xem trước» of a page or post visitors cannot see yet: the real storefront page of an entry of the
 * current site, any status, for a member who may open it in the editor, or a reviewer while it waits
 * at their step (what their review inbox shows them) — never cached or indexed, no referrer, the
 * storefront's CSP with framing by the admin only, no view counted, its forms switched off, and a band
 * saying it is a preview. Anyone else, another site's entry or one in the Trash answers the same 404,
 * so a ulid probe learns nothing.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** Start the next request from a clean container, as every request does in production. */
function epFresh(): void
{
    app()->forgetScopedInstances();
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
    app(TeamContext::class)->forget();
}

/**
 * A draft page of $site, by $author when given; returns its ulid.
 *
 * @param  list<array<string, mixed>>|null  $blocks
 */
function epDraft(int $site = 1, string $title = 'Giới thiệu xưởng mới', ?User $author = null, ?array $blocks = null): string
{
    return app(Tenancy::class)->enter($site, function () use ($title, $author, $blocks): string {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => [], 'author_id' => $author?->id]);
        $entry->translations()->create([
            'locale' => 'vi', 'title' => $title, 'slug' => Str::slug($title),
            'status' => 'draft', 'body' => ['blocks' => $blocks ?? [['type' => 'text', 'props' => ['html' => '<p>Đoạn mở đầu của bản nháp.</p>']]]],
        ]);

        return $entry->ulid;
    });
}

/** A `posts` section of site 1 governed by the bundled $template approval flow; returns its category id. */
function epSection(string $template): int
{
    return app(Tenancy::class)->enter(1, function () use ($template): int {
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
        ContentSection::query()->create([
            'key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id,
            'permission_prefix' => 'entries', 'sort_order' => 1,
        ]);
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);
        app(InstantiateWorkflow::class)->handle($template, 'posts', active: true);

        return $category->id;
    });
}

/** A post of site 1 by $author filed under $categoryId, sent for review unless $send is false; returns its ulid. */
function epPost(int $categoryId, User $author, bool $send = true, string $title = 'Bản tin chờ duyệt'): string
{
    return app(Tenancy::class)->enter(1, function () use ($categoryId, $author, $send, $title): string {
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
        $entry->translations()->create([
            'locale' => 'vi', 'title' => $title, 'slug' => Str::slug($title), 'status' => 'draft',
            'body' => ['blocks' => [['type' => 'richtext', 'props' => ['body' => 'Nội dung bài']]]],
        ]);
        $entry->assignCategories([$categoryId]);
        if ($send) {
            app(ApplyEntryWorkflowTransition::class)->handle($entry->fresh() ?? $entry, 'vi', $author, 'submit');
        }

        return $entry->ulid;
    });
}

/** A member of $site holding $permissions. */
function epMember(array $permissions, int $site = 1): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, 'role-'.md5(implode(',', $permissions)), $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

it('shows a draft page as the website would draw it to a member who may edit it, never cached, indexed or framed by others', function (): void {
    $ulid = epDraft();
    $editor = epMember(['entries.view', 'entries.edit.any']);

    $response = $this->actingAs($editor)->get("http://a.test/admin/content/{$ulid}/preview")->assertOk();
    $html = (string) $response->getContent();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'")
        ->and($html)->toContain('Giới thiệu xưởng mới')
        ->toContain('<meta name="robots" content="noindex, nofollow">')
        ->toContain('class="sf-preview" role="note"')
        ->toContain(__('storefront.preview_band'))
        ->not->toContain('rel="canonical"');
});

it('answers a member who may only see the list as if the entry did not exist', function (): void {
    $ulid = epDraft();
    $viewer = epMember(['entries.view']);

    $this->actingAs($viewer)->get("http://a.test/admin/content/{$ulid}/preview")->assertNotFound();
});

it('refuses a member who may edit only their own work a colleague’s draft, and shows them their own', function (): void {
    $writer = epMember(['entries.view', 'entries.edit.own']);
    $colleague = epMember(['entries.view', 'entries.edit.own']);
    $theirs = epDraft(title: 'Bản nháp của đồng nghiệp', author: $colleague);
    $mine = epDraft(title: 'Bản nháp của tôi', author: $writer);

    $this->actingAs($writer)->get("http://a.test/admin/content/{$theirs}/preview")->assertNotFound();
    epFresh();
    $this->actingAs($writer)->get("http://a.test/admin/content/{$mine}/preview")->assertOk()->assertSee('Bản nháp của tôi');
});

it('lets a reviewer preview a post waiting at their step, in the post layout, with no view counted', function (): void {
    $categoryId = epSection('admin_approval');
    $author = epMember(['entries.view', 'entries.create', 'entries.edit.own', 'entries.submit']);
    $approver = epMember(['entries.view', 'entries.review', 'entries.approve']);
    $ulid = epPost($categoryId, $author);
    epFresh();

    $html = (string) $this->actingAs($approver)->get("http://a.test/admin/content/{$ulid}/preview")->assertOk()->getContent();

    expect($html)->toContain('<article class="post">')
        ->toContain('<h1 class="post-band__title">Bản tin chờ duyệt</h1>')
        ->toContain('class="sf-preview" role="note"')
        ->not->toContain('name="sf-view"')
        ->not->toContain('rel="canonical"');
});

it('refuses a reviewer a colleague’s unsent draft and a post waiting at another person’s step', function (): void {
    // editorial: an editor (entries.review) moves «pending» on to «reviewing»; an approver acts only there
    $categoryId = epSection('editorial');
    $author = epMember(['entries.view', 'entries.create', 'entries.edit.own', 'entries.submit']);
    $editor = epMember(['entries.view', 'entries.review']);
    $approver = epMember(['entries.view', 'entries.approve']);
    $draft = epPost($categoryId, $author, send: false, title: 'Bản nháp chưa gửi');
    $sent = epPost($categoryId, $author, title: 'Bài đã gửi');
    epFresh();

    foreach ([$editor, $approver] as $reviewer) {
        $this->actingAs($reviewer)->get("http://a.test/admin/content/{$draft}/preview")->assertNotFound();
        epFresh();
    }
    // at «pending»: the editor's step, not yet the approver's
    $this->actingAs($approver)->get("http://a.test/admin/content/{$sent}/preview")->assertNotFound();
    epFresh();
    $this->actingAs($editor)->get("http://a.test/admin/content/{$sent}/preview")->assertOk()->assertSee('Bài đã gửi');
    epFresh();

    app(Tenancy::class)->enter(1, fn () => app(ApplyEntryWorkflowTransition::class)->handle(Entry::query()->where('ulid', $sent)->firstOrFail(), 'vi', $editor, 'to_review'));
    epFresh();
    $this->actingAs($approver)->get("http://a.test/admin/content/{$sent}/preview")->assertOk()->assertSee('Bài đã gửi');
});

it('draws a form on the page switched off: nowhere to send it', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        Form::query()->create(['key' => 'contact', 'name' => ['vi' => 'Gửi yêu cầu báo giá'], 'status' => 'active'])->fields()->createMany([
            ['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Họ tên'], 'is_required' => true, 'sort_order' => 1],
        ]);
    });
    $ulid = epDraft(blocks: [['type' => 'form', 'props' => ['form_key' => 'contact']]]);
    $editor = epMember(['entries.view', 'entries.edit.any']);

    $html = (string) $this->actingAs($editor)->get("http://a.test/admin/content/{$ulid}/preview")->assertOk()->getContent();

    expect($html)->toContain('<form class="lead-form__form" novalidate>')
        ->toContain('Gửi yêu cầu báo giá')
        ->toContain('<button type="submit" class="lead-form__submit" disabled >')
        ->not->toContain('action="/forms/contact"')
        ->not->toContain('name="sf-view"');
});

it('answers 404 for another site’s entry and for one in the Trash', function (): void {
    $other = epDraft(site: 2, title: 'Trang của site khác');
    $editor = epMember(['entries.view', 'entries.edit.any']);

    $this->actingAs($editor)->get("http://a.test/admin/content/{$other}/preview")->assertNotFound();

    $mine = epDraft();
    app(Tenancy::class)->enter(1, fn () => Entry::query()->where('ulid', $mine)->firstOrFail()->delete());
    epFresh();
    $this->actingAs($editor)->get("http://a.test/admin/content/{$mine}/preview")->assertNotFound();
});

it('keeps the draft’s public address a 404: the preview opens no door on the website', function (): void {
    epDraft();

    $this->get('http://a.test/gioi-thieu-xuong-moi')->assertNotFound();
});
