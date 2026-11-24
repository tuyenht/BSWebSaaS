<?php

use App\Modules\Admin\Support\AdminAddressLimit;

/*
 * The addresses «Giới hạn nơi được vào trang quản trị» keeps: one IPv4 or IPv6 address or a CIDR
 * range, in one written form (a range keeps its network part, an IPv4 address written as IPv6 is
 * that IPv4 address), and never a /0 range, which would let every address in, nor one wider than
 * /8 (IPv4) or /16 (IPv6): a couple of those would let almost every address in too.
 */

it('keeps an address in one written form', function (string $input, string $kept): void {
    expect(AdminAddressLimit::normalize($input))->toBe(['ok' => true, 'address' => $kept]);
})->with([
    'IPv4' => ['203.0.113.24', '203.0.113.24'],
    'IPv4 with spaces around' => ['  203.0.113.24 ', '203.0.113.24'],
    'IPv4 /32 is the address' => ['203.0.113.24/32', '203.0.113.24'],
    'IPv4 range' => ['203.0.113.0/24', '203.0.113.0/24'],
    'IPv4 range written from a host' => ['203.0.113.77/24', '203.0.113.0/24'],
    'IPv4 odd range' => ['203.0.113.77/27', '203.0.113.64/27'],
    'IPv4 /8, the widest kept' => ['203.0.113.77/8', '203.0.0.0/8'],
    'IPv6 upper case, long form' => ['2001:DB8:0:0:0:0:0:1', '2001:db8::1'],
    'IPv6 /128 is the address' => ['2001:db8::1/128', '2001:db8::1'],
    'IPv6 range' => ['2001:db8:1234:5678::/64', '2001:db8:1234:5678::/64'],
    'IPv6 range written from a host' => ['2001:db8:1234:5678:9abc::1/64', '2001:db8:1234:5678::/64'],
    'IPv6 odd range' => ['2001:db8:1234:56ff::/60', '2001:db8:1234:56f0::/60'],
    'IPv6 /16, the widest kept' => ['2001:db8::1/16', '2001::/16'],
    'IPv4 written as IPv6' => ['::ffff:203.0.113.24', '203.0.113.24'],
    'IPv4 range written as IPv6' => ['::ffff:203.0.113.0/120', '203.0.113.0/24'],
    'IPv4 /8 written as IPv6 is held to the IPv4 floor' => ['::ffff:10.1.2.3/104', '10.0.0.0/8'],
]);

it('says why something is not an address it keeps', function (string $input, string $error): void {
    expect(AdminAddressLimit::normalize($input))->toBe(['ok' => false, 'error' => $error]);
})->with([
    'empty' => ['   ', 'empty'],
    'three groups' => ['113.161.42', 'invalid'],
    'a group over 255' => ['203.0.113.256', 'invalid'],
    'a leading zero' => ['203.0.113.024', 'invalid'],
    'a word' => ['văn phòng', 'invalid'],
    'a host name' => ['office.example.vn', 'invalid'],
    'an IPv6 zone' => ['fe80::1%eth0', 'invalid'],
    'two slashes' => ['203.0.113.0/24/8', 'prefix'],
    'IPv4 over /32' => ['203.0.113.0/33', 'prefix'],
    'IPv6 over /128' => ['2001:db8::/129', 'prefix'],
    'a signed prefix' => ['203.0.113.0/+8', 'prefix'],
    'a padded prefix' => ['203.0.113.0/024', 'prefix'],
    'an empty prefix' => ['203.0.113.0/', 'prefix'],
    'a broken address with a prefix' => ['203.0.113/24', 'invalid'],
    'every IPv4 address' => ['0.0.0.0/0', 'everywhere'],
    'every IPv4 address from a host' => ['10.1.2.3/0', 'everywhere'],
    'every IPv6 address' => ['::/0', 'everywhere'],
    'every IPv4 address written as IPv6' => ['::ffff:0.0.0.0/96', 'everywhere'],
    'a mapped range wider than IPv4, which no visitor could match' => ['::ffff:0.0.0.0/95', 'prefix'],
    'IPv4 /1, half of every address' => ['0.0.0.0/1', 'wide'],
    'the other IPv4 /1' => ['128.0.0.0/1', 'wide'],
    'IPv4 /7, just wider than kept' => ['10.0.0.0/7', 'wide'],
    'IPv6 /1' => ['::/1', 'wide'],
    'IPv6 /15, just wider than kept' => ['2001:db8::/15', 'wide'],
    'IPv4 /7 written as IPv6 uses the IPv4 floor' => ['::ffff:10.0.0.0/103', 'wide'],
    'IPv4 /1 written as IPv6' => ['::ffff:128.0.0.0/97', 'wide'],
    'the old IPv4-compatible form' => ['::203.0.113.24', 'invalid'],
    'the old IPv4-compatible form as a range' => ['::203.0.113.0/120', 'invalid'],
]);

it('matches a visitor against the list in every written form', function (array $list, ?string $ip, bool $in): void {
    expect(AdminAddressLimit::covers($list, $ip))->toBe($in);
})->with([
    'the address itself' => [['203.0.113.24'], '203.0.113.24', true],
    'inside a range' => [['203.0.113.0/24'], '203.0.113.200', true],
    'outside a range' => [['203.0.113.0/24'], '203.0.112.200', false],
    'IPv6 inside a range' => [['2001:db8:1234:5678::/64'], '2001:db8:1234:5678:ffff::9', true],
    'IPv6 written another way' => [['2001:db8::1'], '2001:0db8:0000::0001', true],
    'a visitor on IPv4 written as IPv6' => [['203.0.113.0/24'], '::ffff:203.0.113.5', true],
    'an empty list lets no one in' => [[], '203.0.113.5', false],
    'no address' => [['203.0.113.0/24'], null, false],
    'not an address' => [['203.0.113.0/24'], 'unknown', false],
]);

it('keeps the stored value in its one shape', function (): void {
    expect(AdminAddressLimit::shape(['on' => true, 'entries' => [
        ['address' => '203.0.113.77/24', 'note' => '  Văn phòng  ', 'extra' => 'x'],
        ['address' => 'broken', 'note' => 'dropped'],
        ['address' => '0.0.0.0/0'],
        'not a row',
    ], 'other' => 1]))->toBe(['on' => true, 'entries' => [['address' => '203.0.113.0/24', 'note' => 'Văn phòng']]])
        ->and(AdminAddressLimit::shape(null))->toBe(['on' => false, 'entries' => []])
        ->and(AdminAddressLimit::shape(['on' => 'yes']))->toBe(['on' => false, 'entries' => []]);
});
