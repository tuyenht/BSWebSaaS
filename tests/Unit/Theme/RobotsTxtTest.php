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
