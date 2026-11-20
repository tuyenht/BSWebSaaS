<?php

use App\Modules\Shared\Support\SafeLink;

/*
 * The one form another website's address takes in a redirect: https, a plain ASCII host name with a
 * letter TLD, and visible ASCII after it. Anything that could dress a link up — a user name before
 * the host, a protocol-relative or backslashed address, a control character or space, an IP, a port,
 * a single-label host, a punycode or non-ASCII (look-alike) host — is refused. Only the scheme and the
 * host are lower-cased; a stored value is followed only when it is exactly that form.
 */

it('accepts a plain https address and keeps everything after the host as typed', function (string $typed, string $stored): void {
    expect(SafeLink::externalHttps($typed))->toBe($stored)
        ->and(SafeLink::isExternalHttps($stored))->toBeTrue();
})->with([
    'host only' => ['https://tencongty.vn', 'https://tencongty.vn'],
    'path, query and anchor' => ['https://www.doi-tac.com.vn/San-Pham?ma=A1&b=2#gia', 'https://www.doi-tac.com.vn/San-Pham?ma=A1&b=2#gia'],
    'upper-case scheme and host' => ['HTTPS://Example.COM/Path', 'https://example.com/Path'],
    'outer spaces' => ['  https://example.com/a  ', 'https://example.com/a'],
    'an @ inside the path' => ['https://example.com/u/@me', 'https://example.com/u/@me'],
]);

it('refuses every address that is not plainly another website over https', function (string $typed): void {
    expect(SafeLink::externalHttps($typed))->toBeNull()
        ->and(SafeLink::isExternalHttps($typed))->toBeFalse();
})->with([
    'plain http' => ['http://example.com/a'],
    'protocol-relative' => ['//example.com/a'],
    'no scheme' => ['example.com/a'],
    'a path of this site' => ['/gioi-thieu'],
    'javascript' => ['javascript:alert(1)'],
    'data' => ['data:text/html,x'],
    'user name' => ['https://user@example.com/'],
    'user and password' => ['https://user:pw@example.com/'],
    'look-alike user part' => ['https://tencongty.vn@evil.example/'],
    'encoded slash before @' => ['https://tencongty.vn%2F@evil.example/'],
    'backslash in host' => ['https://evil.example\\@tencongty.vn/'],
    'backslash in path' => ['https://example.com/a\\b'],
    'backslash after scheme' => ['https:\\\\evil.example/'],
    'three slashes' => ['https:///example.com/'],
    'port' => ['https://example.com:8443/'],
    'IPv4' => ['https://127.0.0.1/'],
    'IPv6' => ['https://[::1]/'],
    'single label' => ['https://localhost/'],
    'punycode host' => ['https://xn--80ak6aa92e.com/'],
    'punycode TLD' => ['https://example.xn--p1ai/'],
    'non-ASCII host' => ['https://аpple.com/'],
    'Vietnamese host' => ['https://côngty.vn/'],
    'space in path' => ['https://example.com/a b'],
    'tab' => ["https://example.com/a\tb"],
    'newline' => ["https://example.com/a\nLocation: x"],
    'NUL' => ["https://example.com/a\0b"],
    'non-ASCII path' => ['https://example.com/giới-thiệu'],
    'trailing dot host' => ['https://example.com./'],
    'leading hyphen label' => ['https://-example.com/'],
    'empty' => [''],
    'scheme only' => ['https://'],
]);

it('follows a stored address only when it is exactly the form it is stored in', function (): void {
    expect(SafeLink::isExternalHttps('HTTPS://example.com/'))->toBeFalse()
        ->and(SafeLink::isExternalHttps(' https://example.com/'))->toBeFalse()
        ->and(SafeLink::isExternalHttps(null))->toBeFalse()
        ->and(SafeLink::isExternalHttps('https://example.com/'))->toBeTrue();
});
