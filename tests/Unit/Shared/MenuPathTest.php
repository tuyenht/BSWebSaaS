<?php

use App\Modules\Shared\Support\MenuPath;

/*
 * The one rule for "this link is the page the visitor is on": both sides reduced to a plain path of this site.
 */

it('reduces an address to a plain path of this site', function (string $url, ?string $path): void {
    expect(MenuPath::normalize($url, 'shop.test'))->toBe($path);
})->with([
    'a path' => ['/gioi-thieu', '/gioi-thieu'],
    'home' => ['/', '/'],
    'absolute on the request host' => ['https://shop.test/gioi-thieu', '/gioi-thieu'],
    'absolute, host in capitals, with a port' => ['HTTP://Shop.test:8080/gioi-thieu', '/gioi-thieu'],
    'protocol-relative' => ['//shop.test/gioi-thieu', '/gioi-thieu'],
    'the bare host is home' => ['https://shop.test', '/'],
    'query string dropped' => ['/tin-tuc?page=2&q=a', '/tin-tuc'],
    'fragment dropped' => ['/tin-tuc#dau-trang', '/tin-tuc'],
    'trailing slash dropped' => ['/tin-tuc/', '/tin-tuc'],
    'several trailing slashes dropped' => ['/tin-tuc//', '/tin-tuc'],
    'percent-encoding decoded' => ['/s%E1%BA%A3n-ph%E1%BA%A9m', '/sản-phẩm'],
    'a lower-case escape equals the upper-case one' => ['/s%e1%ba%a3n', '/sản'],
    'external host' => ['https://other.test/gioi-thieu', null],
    'a look-alike host' => ['https://shop.test.evil.test/x', null],
    'a sub-domain is another host' => ['https://www.shop.test/x', null],
    'a bare anchor' => ['#', null],
    'an anchor on the page' => ['#lien-he', null],
    'a call link' => ['tel:0901234567', null],
    'a mail link' => ['mailto:a@shop.test', null],
    'a script scheme' => ['javascript:alert(1)', null],
    'empty' => ['', null],
    'a relative word is no path' => ['gioi-thieu', null],
]);

it('treats an external address with the same path as another page', function (): void {
    expect(MenuPath::same('https://other.test/gioi-thieu', '/gioi-thieu', 'shop.test'))->toBeFalse()
        ->and(MenuPath::same('/gioi-thieu', 'https://other.test/gioi-thieu', 'shop.test'))->toBeFalse()
        ->and(MenuPath::same('https://other.test/a', 'https://other.test/a', 'shop.test'))->toBeFalse();   // two foreign links never "match" a page of this site
});

it('matches the same page written in different ways', function (): void {
    expect(MenuPath::same('https://shop.test/gioi-thieu/', '/gioi-thieu?utm=x#top', 'shop.test'))->toBeTrue()
        ->and(MenuPath::same('/s%E1%BA%A3n', '/sản', 'shop.test'))->toBeTrue()
        ->and(MenuPath::same('/', 'https://shop.test', 'shop.test'))->toBeTrue()
        ->and(MenuPath::same('/a', '/b', 'shop.test'))->toBeFalse();
});
