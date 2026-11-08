<?php

use App\Modules\Theme\Theming\RobotsTxt;

/*
 * robots.txt read the way RFC 9309 says a crawler reads it: a bot with its own group ignores `*`,
 * groups naming the same bot merge, names compare without case, the longest matching path wins,
 * a tie goes to Allow, an empty Disallow allows everything, `*` and a final `$` are patterns, and
 * records outside the rules (Sitemap, Content-Signal) decide nothing.
 */

it('lets a bot with a group of its own ignore the * group entirely', function (): void {
    $robots = RobotsTxt::parse("User-agent: *\nDisallow: /\n\nUser-agent: GPTBot\nDisallow: /riêng-tư-đ");

    expect($robots->verdict('GPTBot'))->toBe('partial')
        ->and($robots->allows('GPTBot', '/'))->toBeTrue()
        ->and($robots->verdict('ClaudeBot'))->toBe('blocked');
});

it('merges every group that names the same bot', function (): void {
    $robots = RobotsTxt::parse("User-agent: GPTBot\nDisallow: /a\n\nUser-agent: *\nDisallow:\n\nUser-agent: GPTBot\nDisallow: /b");

    expect($robots->allows('GPTBot', '/a'))->toBeFalse()
        ->and($robots->allows('GPTBot', '/b'))->toBeFalse()
        ->and($robots->allows('GPTBot', '/c'))->toBeTrue();
});

it('shares one group among consecutive User-agent lines', function (): void {
    $robots = RobotsTxt::parse("User-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /");

    expect($robots->verdict('GPTBot'))->toBe('blocked')
        ->and($robots->verdict('ClaudeBot'))->toBe('blocked')
        ->and($robots->verdict('CCBot'))->toBe('allowed');
});

it('compares bot names without regard to case, and reads the product token of a versioned name', function (): void {
    expect(RobotsTxt::parse("user-agent: gptbot\nDISALLOW: /")->verdict('GPTBot'))->toBe('blocked')
        ->and(RobotsTxt::parse("User-Agent: GPTBot/1.1\nDisallow: /")->verdict('gptbot'))->toBe('blocked');
});

it('lets the longest matching path win, and Allow win a tie', function (): void {
    $robots = RobotsTxt::parse("User-agent: *\nDisallow: /san-pham\nAllow: /san-pham/mới");

    expect($robots->allows('GPTBot', '/san-pham/cu'))->toBeFalse()
        ->and($robots->allows('GPTBot', '/san-pham/mới'))->toBeTrue()
        ->and($robots->allows('GPTBot', '/gioi-thieu'))->toBeTrue();

    // Allow: / cancels Disallow: / in the same group.
    expect(RobotsTxt::parse("User-agent: *\nDisallow: /\nAllow: /")->verdict('GPTBot'))->toBe('allowed');
});

it('reads an empty Disallow as allowing everything', function (): void {
    expect(RobotsTxt::parse("User-agent: *\nDisallow:")->verdict('GPTBot'))->toBe('allowed')
        ->and(RobotsTxt::parse("User-agent: GPTBot\nDisallow:\n\nUser-agent: *\nDisallow: /")->verdict('GPTBot'))->toBe('allowed');
});

it('calls a block of some paths partial, not blocked', function (): void {
    expect(RobotsTxt::parse("User-agent: GPTBot\nDisallow: /san-pham")->verdict('GPTBot'))->toBe('partial')
        ->and(RobotsTxt::parse("User-agent: GPTBot\nDisallow: /\nAllow: /tin-tuc")->verdict('GPTBot'))->toBe('partial')
        ->and(RobotsTxt::parse("User-agent: GPTBot\nDisallow: /")->verdict('GPTBot'))->toBe('blocked')
        ->and(RobotsTxt::parse("User-agent: GPTBot\nDisallow: /*")->verdict('GPTBot'))->toBe('blocked');
});

it('reads * as any run of characters and a final $ as the end of the path', function (): void {
    $robots = RobotsTxt::parse("User-agent: *\nDisallow: /*.pdf$\nDisallow: /tìm*kiếm");

    expect($robots->allows('GPTBot', '/tai-lieu/bảng-giá.pdf'))->toBeFalse()
        ->and($robots->allows('GPTBot', '/tai-lieu/bảng-giá.pdf?x=1'))->toBeTrue()
        ->and($robots->allows('GPTBot', '/tìm-nhanh-kiếm'))->toBeFalse();
});

it('allows everything when no group applies, and ignores rules before any User-agent', function (): void {
    expect(RobotsTxt::parse("Disallow: /\n\nUser-agent: Bytespider\nDisallow: /")->verdict('GPTBot'))->toBe('allowed')
        ->and(RobotsTxt::parse('')->verdict('GPTBot'))->toBe('allowed');
});

it('leaves Sitemap and Content-Signal out of the rules, and names the Content-Signal values', function (): void {
    $robots = RobotsTxt::parse("User-agent: *\nContent-Signal: search=yes, ai-train=no\nSitemap: https://ví-dụ.vn/sitemap.xml\nDisallow:");

    expect($robots->verdict('GPTBot'))->toBe('allowed')
        ->and($robots->contentSignals())->toBe(['search=yes, ai-train=no']);
});

it('compares two files without comments, blank lines, line endings, Sitemap or Content-Signal', function (): void {
    $ours = "User-agent: *\nDisallow:\nSitemap: https://a.test/sitemap.xml";
    $served = "\xEF\xBB\xBF# Đây là chú thích\r\nUser-Agent:*\r\n\r\nContent-Signal: ai-train=no\r\nDisallow: \r\nSitemap: https://khác.vn/sitemap.xml\r\n";

    expect(RobotsTxt::comparable($served))->toBe(RobotsTxt::comparable($ours))
        ->and(RobotsTxt::comparable($ours))->toBe(['user-agent: *', 'disallow: ']);
});

it('reads the robots.txt Cloudflare serves for a managed zone as comments only — no bot is blocked', function (): void {
    $robots = RobotsTxt::parse((string) file_get_contents(__DIR__.'/../../Fixtures/robots-cloudflare-managed.txt'));

    foreach (['GPTBot', 'ClaudeBot', 'Google-Extended', 'ChatGPT-User'] as $bot) {
        expect($robots->verdict($bot))->toBe('allowed');
    }
    expect(RobotsTxt::comparable((string) file_get_contents(__DIR__.'/../../Fixtures/robots-cloudflare-managed.txt')))->toBe([]);
});

/*
 * The served robots.txt may come from a server the site's owner runs, up to 512 KB: judging it
 * must stay cheap whatever it holds — a file past the bounds is not judged rather than judged slowly.
 */
const ROBOTS_BOTS = ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'Claude-User', 'PerplexityBot', 'Perplexity-User', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Bytespider', 'meta-externalagent', 'Amazonbot'];

/** @return array{0: mixed, 1: float} what the call gave and how long it took, in seconds */
function robotsTimed(Closure $call): array
{
    $started = hrtime(true);
    $result = $call();

    return [$result, (hrtime(true) - $started) / 1e9];
}

it('gives up at once on a 512 KB robots.txt of Disallow rules instead of judging it for every bot slowly', function (): void {
    $text = "User-agent: *\nDisallow: /\n";
    for ($i = 0; strlen($text) < 512 * 1024; $i++) {
        $text .= "Disallow: /trang-{$i}/x*y\$\n";
    }

    [$verdicts, $seconds] = robotsTimed(fn () => RobotsTxt::parse($text)->verdicts(ROBOTS_BOTS));

    expect($verdicts)->toBeNull()->and($seconds)->toBeLessThan(2.0);
});

it('judges a file at the rule bound for every bot quickly, even one built to make every path try every rule', function (): void {
    // Each probe path is named by an Allow rule that does not match it, so every wildcard rule is
    // tried on it before `Disallow: /` closes it (about 80,000 checks, inside the budget); the
    // wildcards would backtrack in a regex.
    $text = "User-agent: *\nDisallow: /\n";
    for ($i = 0; $i < 40; $i++) {
        $text .= 'Allow: /'.str_repeat('a', 300).$i."*z\n";
    }
    for ($i = 0; $i < RobotsTxt::MAX_RULES - 41; $i++) {
        $text .= 'Disallow: /*'.str_repeat('a*', 20).'b'.$i."\n";
    }

    [$verdicts, $seconds] = robotsTimed(fn () => RobotsTxt::parse($text)->verdicts(ROBOTS_BOTS));

    expect($verdicts)->toBe(array_fill_keys(ROBOTS_BOTS, 'blocked'))->and($seconds)->toBeLessThan(2.0);
});

it('stops judging once the rule checks for all the bots together pass the budget', function (): void {
    // One big group every bot follows, plus a small group of its own for each, so no two bots
    // share a judgement.
    $text = "User-agent: *\nDisallow: /\n";
    foreach (ROBOTS_BOTS as $bot) {
        $text .= "User-agent: {$bot}\nAllow: /chi-{$bot}*z\n\n";
    }
    $text .= 'User-agent: '.implode("\nUser-agent: ", ROBOTS_BOTS)."\nDisallow: /\n";
    for ($i = 0; $i < 100; $i++) {
        $text .= 'Allow: /'.str_repeat('a', 300).$i."*z\n";
    }
    for ($i = 0; $i < 1800; $i++) {
        $text .= 'Disallow: /*'.str_repeat('a', 30).'b'.$i."\n";
    }

    [$verdicts, $seconds] = robotsTimed(fn () => RobotsTxt::parse($text)->verdicts(ROBOTS_BOTS));

    expect($verdicts)->toBeNull()->and($seconds)->toBeLessThan(2.0);
});

it('does not judge a file with more rules, or a longer rule, than it keeps', function (): void {
    $many = "User-agent: *\n".str_repeat("Disallow: /a\n", RobotsTxt::MAX_RULES + 1);
    $long = "User-agent: *\nDisallow: /".str_repeat('a', RobotsTxt::MAX_PATH)."\n";

    expect(RobotsTxt::parse($many)->verdicts(ROBOTS_BOTS))->toBeNull()
        ->and(RobotsTxt::parse($long)->verdict('GPTBot'))->toBeNull()
        ->and(RobotsTxt::parse("User-agent: *\n".str_repeat("Disallow: /a\n", RobotsTxt::MAX_RULES))->verdict('GPTBot'))->toBe('partial');
});

it('does not judge when the rules name more paths than it tries, unless those already show a part blocked', function (): void {
    $allows = '';
    for ($i = 0; $i < RobotsTxt::MAX_PROBES + 20; $i++) {
        $allows .= "Allow: /p{$i}\n";
    }

    expect(RobotsTxt::parse("User-agent: *\n{$allows}Disallow: /private\n")->verdict('GPTBot'))->toBeNull()
        ->and(RobotsTxt::parse("User-agent: *\nDisallow: /\n{$allows}Allow: /late\n")->verdict('GPTBot'))->toBe('partial');
});

it('matches a pattern full of * without backtracking, and still gives the right answer', function (): void {
    $robots = RobotsTxt::parse("User-agent: *\nDisallow: /".str_repeat('a*', 40)."b\nDisallow: /x*y*z\$");

    [$open, $seconds] = robotsTimed(fn () => $robots->allows('GPTBot', '/'.str_repeat('a', 500)));

    expect($open)->toBeTrue()->and($seconds)->toBeLessThan(0.5)
        ->and($robots->allows('GPTBot', '/'.str_repeat('a', 60).'b'))->toBeFalse()
        ->and($robots->allows('GPTBot', '/x-1-y-2-z'))->toBeFalse()
        ->and($robots->allows('GPTBot', '/x-1-y-2-z/'))->toBeTrue()
        ->and($robots->allows('GPTBot', '/xzy'))->toBeTrue();
});
