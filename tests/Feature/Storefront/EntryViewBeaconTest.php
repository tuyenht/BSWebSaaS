<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\EntryViewCounter;
use App\Modules\Storefront\Http\Controllers\RecordEntryView;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Cache;

/*
 * A page view is counted by the visitor's browser: the page names its view address in
 * <meta name="sf-view"> and sf-view.js posts to it once, so a copy served from the edge counts too.
 * The address is public like the lead form (no session, no cookie, no CSRF token), resolves the
 * entry in the Host's own site, ignores crawlers and unknown entries, and limits each address.
 * Drawing the page no longer counts, so a view is never counted twice.
 */

beforeEach(function (): void {
    seedTenancy(); // sites 1 (a.test) / 2 (b.test)
    seedHomepage(1, 'Mở đầu Ân Vĩnh');
    seedHomepage(2, 'Mở đầu Bình Định');
    Cache::flush();
    $counter = app(EntryViewCounter::class);
    $counter->drain(1);
    $counter->drain(2);
});

function homeUlidOf(int $site): string
{
    return app(Tenancy::class)->enter($site, fn (): string => Entry::query()->firstOrFail()->ulid);
}

it('names the view address in a page the edge may keep, and counts nothing while drawing it', function (): void {
    $response = $this->get('http://a.test/')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('public')
        ->and($response->getContent())->toContain('<meta name="sf-view" content="/_view/'.homeUlidOf(1).'">')
        ->and(app(EntryViewCounter::class)->drain(1))->toBe([]);
});

it('counts one view per beacon into the site of the Host, with no session and no cookie', function (): void {
    $ulid = homeUlidOf(1);
    $entryId = app(Tenancy::class)->enter(1, fn (): int => Entry::query()->firstOrFail()->id);

    $response = $this->post('http://a.test/_view/'.$ulid)->assertNoContent();
    $this->post('http://a.test/_view/'.$ulid)->assertNoContent();

    expect($response->headers->has('Set-Cookie'))->toBeFalse()
        ->and($response->headers->getCookies())->toBe([])
        ->and(app(EntryViewCounter::class)->drain(1))->toBe([$entryId => 2]);
});

it('ignores an entry of another site, an unknown entry and an unpublished one', function (): void {
    $otherSite = homeUlidOf(2);
    $draft = app(Tenancy::class)->enter(1, fn (): string => Entry::query()->create(['type' => 'page', 'status' => 'draft'])->ulid);

    $this->post('http://a.test/_view/'.$otherSite)->assertNoContent();     // b.test's page, asked on a.test
    $this->post('http://a.test/_view/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNoContent();
    $this->post('http://a.test/_view/'.$draft)->assertNoContent();
    $this->post('http://nope.test/_view/'.$otherSite)->assertNotFound();   // unknown host: no site at all

    expect(app(EntryViewCounter::class)->drain(1))->toBe([])
        ->and(app(EntryViewCounter::class)->drain(2))->toBe([]);
});

it('does not count crawlers, link previews or a request with no browser name', function (string $agent): void {
    $this->withHeaders(['User-Agent' => $agent])->post('http://a.test/_view/'.homeUlidOf(1))->assertNoContent();

    expect(app(EntryViewCounter::class)->drain(1))->toBe([]);
})->with([
    'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
    'a link preview' => ['facebookexternalhit/1.1'],
    'Lighthouse' => ['Mozilla/5.0 Chrome-Lighthouse'],
    'a script' => ['curl/8.5.0'],
    'nothing' => [''],
]);

it('stops counting an address past its limit and answers 429', function (): void {
    $ulid = homeUlidOf(1);
    for ($i = 0; $i < RecordEntryView::MAX_ATTEMPTS; $i++) {
        $this->post('http://a.test/_view/'.$ulid)->assertNoContent();
    }

    $this->post('http://a.test/_view/'.$ulid)->assertStatus(429);

    expect(array_sum(app(EntryViewCounter::class)->drain(1)))->toBe(RecordEntryView::MAX_ATTEMPTS);
});

it('answers only a POST with a well-formed entry key', function (): void {
    $this->post('http://a.test/_view/khong-phai-ma')->assertMethodNotAllowed(); // no such address: only GET pages there
    $this->get('http://a.test/_view/'.homeUlidOf(1))->assertNotFound(); // a GET is an ordinary missing page
});
