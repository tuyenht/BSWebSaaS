<?php

use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\StorefrontCards;

/*
 * Category list paging: no COUNT, a stable newest-first order (id breaks ties), numbered pages up
 * to 50, then a forward-only keyset tail by the last row's public ulid (noindex, with a way back
 * to page 1). A page number outside 1–50 or a bad, unknown or other-site anchor is a 404; every
 * page canonicals to itself.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

/** $count news posts, all published at the same moment (so only the id orders them). */
function pagedNews(int $count, string $name = 'Xưởng Mẫu', string $host = 'mau.platform.test'): int
{
    $siteId = StorefrontCards::site($name, $host);
    app(Tenancy::class)->enter($siteId, function () use ($count): void {
        $news = StorefrontCards::news();
        $at = CarbonImmutable::parse('2026-10-01 08:00', 'UTC');
        foreach (range(1, $count) as $n) {
            StorefrontCards::post($news, "Bài {$n}", "bai-{$n}", $at);
        }
    });

    return $siteId;
}

/** The post numbers listed on a page, in order. */
function listedPosts(string $html): array
{
    preg_match_all('#href="/tin-tuc/bai-(\d+)"#', $html, $matches);

    return array_map('intval', $matches[1]);
}

it('pages without counting the rows', function (): void {
    pagedNews(14);

    DB::enableQueryLog();
    test()->get('http://mau.platform.test/tin-tuc')->assertOk();
    test()->get('http://mau.platform.test/tin-tuc?page=2')->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $sql): bool => (bool) preg_match('/count\s*\(/i', $sql)))->toBeEmpty();
});

it('keeps a stable order among equal publish times, page after page', function (): void {
    pagedNews(14);

    $first = listedPosts(test()->get('http://mau.platform.test/tin-tuc')->getContent());
    $second = listedPosts(test()->get('http://mau.platform.test/tin-tuc?page=2')->getContent());

    expect($first)->toBe(range(14, 3))
        ->and($second)->toBe([2, 1]);
});

it('lists posts without a publish date after the dated ones, also after a keyset anchor', function (): void {
    $siteId = pagedNews(3);
    $ulid = fn (string $slug): string => (string) app(Tenancy::class)->enter($siteId, function () use ($slug): string {
        $id = DB::table('entry_translations')->where('slug', $slug)->value('entry_id');

        return (string) DB::table('entries')->where('id', $id)->value('ulid');
    });
    app(Tenancy::class)->enter($siteId, fn () => DB::table('entries')
        ->whereIn('id', DB::table('entry_translations')->where('slug', 'bai-3')->select('entry_id'))
        ->update(['published_at' => null]));

    expect(listedPosts(test()->get('http://mau.platform.test/tin-tuc')->getContent()))->toBe([2, 1, 3])
        ->and(listedPosts(test()->get('http://mau.platform.test/tin-tuc?sau='.$ulid('bai-2'))->getContent()))->toBe([1, 3])
        ->and(listedPosts(test()->get('http://mau.platform.test/tin-tuc?sau='.$ulid('bai-3'))->getContent()))->toBe([]);
});

it('continues past page 50 by keyset, forward only and not indexed', function (): void {
    pagedNews(601);

    $page50 = test()->get('http://mau.platform.test/tin-tuc?page=50')->assertOk()->getContent();
    expect(listedPosts($page50))->toBe(range(13, 2));

    $lastUlid = app(Tenancy::class)->enter(1, fn () => DB::table('entry_translations')->join('entries', 'entries.id', '=', 'entry_translations.entry_id')->where('entry_translations.slug', 'bai-2')->value('entries.ulid'));
    expect($page50)->toContain('<a class="pagination__next" rel="next" href="http://mau.platform.test/tin-tuc?sau='.$lastUlid.'">')
        ->and($page50)->not->toContain('noindex');

    $tail = test()->get('http://mau.platform.test/tin-tuc?sau='.$lastUlid)->assertOk()->getContent();
    expect(listedPosts($tail))->toBe([1])
        ->and($tail)->toContain('<meta name="robots" content="noindex,follow">')
        ->and($tail)->toContain('<a class="pagination__first" href="http://mau.platform.test/tin-tuc">Về trang đầu</a>')
        ->and($tail)->not->toContain('rel="prev"')
        ->and($tail)->not->toContain('rel="next"')
        ->and($tail)->toContain('<link rel="canonical" href="http://mau.platform.test/tin-tuc?sau='.$lastUlid.'">');

    // A lower-case anchor is the same anchor.
    expect(listedPosts(test()->get('http://mau.platform.test/tin-tuc?sau='.strtolower((string) $lastUlid))->getContent()))->toBe([1]);
});

it('answers 404 for a page outside 1–50 and for a bad, unknown or other-site anchor', function (): void {
    pagedNews(3);
    $other = pagedNews(1, 'Xưởng Khác', 'khac.platform.test');
    $foreign = app(Tenancy::class)->enter($other, fn () => DB::table('entries')->where('type', 'post')->value('ulid'));

    foreach (['?page=51', '?page=0', '?page=-1', '?sau=not-a-ulid', '?sau=01JBXXXXXXXXXXXXXXXXXXXXXX', '?sau=01JC0000000000000000000000', '?sau='.$foreign] as $query) {
        test()->get('http://mau.platform.test/tin-tuc'.$query)->assertNotFound();
    }
    test()->get('http://mau.platform.test/tin-tuc?page=abc')->assertOk();
});

it('canonicals each numbered page to itself', function (): void {
    pagedNews(30);

    expect(test()->get('http://mau.platform.test/tin-tuc?page=3')->getContent())
        ->toContain('<link rel="canonical" href="http://mau.platform.test/tin-tuc?page=3">')
        ->toContain('rel="prev" href="http://mau.platform.test/tin-tuc?page=2"')
        ->not->toContain('rel="next"');
});
