<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Str;

/*
 * «Xem trước» of a page or post visitors cannot see yet: the real storefront page of an entry of the
 * current site, any status, for a member who may open it in the editor (or review it) — never cached
 * or indexed, no referrer, the storefront's CSP with framing by the admin only, and a band saying it is
 * a preview. A member who may only see the list is refused; another site's entry, or one in the
 * Trash, answers 404 as if it did not exist.
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

/** A draft page of $site; returns its ulid. */
function epDraft(int $site = 1, string $title = 'Giới thiệu xưởng mới'): string
{
    return app(Tenancy::class)->enter($site, function () use ($title): string {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
        $entry->translations()->create([
            'locale' => 'vi', 'title' => $title, 'slug' => Str::slug($title),
            'status' => 'draft', 'body' => ['blocks' => [['type' => 'text', 'props' => ['html' => '<p>Đoạn mở đầu của bản nháp.</p>']]]],
        ]);

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

it('refuses a member who may only see the list', function (): void {
    $ulid = epDraft();
    $viewer = epMember(['entries.view']);

    $this->actingAs($viewer)->get("http://a.test/admin/content/{$ulid}/preview")->assertForbidden();
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
