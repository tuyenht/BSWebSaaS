<?php

declare(strict_types=1);

use App\Modules\Shared\Support\SafeLink;

it('keeps site paths and absolute web links', function (string $url): void {
    expect(SafeLink::href($url))->toBe($url);
})->with(['/lien-he', '/a/b?c=1#d', 'https://example.com', 'http://example.com/x?y=1']);

it('rejects links that are not plain site paths or web links', function (mixed $url): void {
    expect(SafeLink::href($url))->toBeNull();
})->with([
    'javascript' => 'javascript:alert(1)',
    'data' => 'data:text/html,<b>x</b>',
    'protocol-relative' => '//evil.example',
    'backslash' => '/\\evil.example',
    'other scheme' => 'ftp://example.com',
    'no host' => 'https://',
    'whitespace inside' => 'https://exa mple.com',
    'control character' => "https://example.com/\x01",
    'blank' => '   ',
    'not a string' => 42,
    'too long' => 'https://example.com/'.str_repeat('a', 2048),
]);

it('limits web links to the given hosts and their subdomains', function (): void {
    $hosts = ['google.com'];

    expect(SafeLink::href('https://maps.google.com/?q=1', $hosts))->toBe('https://maps.google.com/?q=1')
        ->and(SafeLink::href('https://google.com/maps', $hosts))->toBe('https://google.com/maps')
        ->and(SafeLink::href('https://evilgoogle.com/maps', $hosts))->toBeNull()
        ->and(SafeLink::href('https://google.com.evil.example/', $hosts))->toBeNull();
});

it('builds tel and mailto links from safe values only', function (): void {
    expect(SafeLink::tel('+84 901-234-567'))->toBe('tel:+84901234567')
        ->and(SafeLink::tel('0901 234 567'))->toBe('tel:0901234567')
        ->and(SafeLink::tel('+84 (0)90 123 4567'))->toBe('tel:+84901234567')
        ->and(SafeLink::tel('+84 ( 0 ) 90 123 4567'))->toBe('tel:+84901234567')
        ->and(SafeLink::tel('(0)28 3822 1234'))->toBe('tel:02838221234')
        ->and(SafeLink::tel('12'))->toBeNull()
        ->and(SafeLink::tel('call me'))->toBeNull()
        ->and(SafeLink::mailto('lienhe@example.com'))->toBe('mailto:lienhe@example.com')
        ->and(SafeLink::mailto('not-an-email'))->toBeNull()
        ->and(SafeLink::mailto(null))->toBeNull();
});
