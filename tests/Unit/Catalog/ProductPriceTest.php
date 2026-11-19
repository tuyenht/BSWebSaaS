<?php

use App\Modules\Catalog\Support\ProductPrice;
use Carbon\CarbonImmutable;

/*
 * ProductPrice — how the product editor reads an amount typed the Vietnamese way: whole đồng only
 * (an integer, never a float), digits alone or grouped by threes, the currency mark and spaces
 * dropped; anything that would need a guess is refused.
 */

it('reads an amount typed the Vietnamese way, or plain', function (string $typed, ?int $amount): void {
    expect(ProductPrice::readable($typed))->toBeTrue()
        ->and(ProductPrice::parse($typed))->toBe($amount);
})->with([
    'grouped by dots' => ['1.250.000', 1250000],
    'grouped by commas' => ['1,250,000', 1250000],
    'plain digits' => ['1250000', 1250000],
    'with the đồng sign and spaces' => [' 1.250.000 ₫ ', 1250000],
    'with a đ after it' => ['1250000đ', 1250000],
    'with VND before it' => ['VND 990.000', 990000],
    'a thousand' => ['1.250', 1250],
    'zero' => ['0', 0],
    'leading zeros' => ['007', 7],
    'blank' => ['', null],
    'only spaces' => ['   ', null],
    'thirteen digits' => ['9.999.999.999.999', 9999999999999],
]);

it('refuses what it would have to guess at', function (string $typed): void {
    expect(ProductPrice::readable($typed))->toBeFalse()
        ->and(ProductPrice::parse($typed))->toBeNull();
})->with([
    'a decimal part' => ['1.250.000,50'],
    'a short decimal' => ['12,5'],
    'a dot decimal' => ['12.50'],
    'mixed separators' => ['1.250,000'],
    'a group of two' => ['1.25.000'],
    'a letter' => ['12k'],
    'a minus sign' => ['-5000'],
    'fourteen digits' => ['12345678901234'],
]);

it('tells a long number from one that is not a number', function (): void {
    expect(ProductPrice::wellFormed('12345678901234'))->toBeTrue()
        ->and(ProductPrice::wellFormed('12,5'))->toBeFalse();
});

it('reads a stock count of at most seven digits', function (): void {
    expect(ProductPrice::parse('24', ProductPrice::STOCK_DIGITS))->toBe(24)
        ->and(ProductPrice::readable('9999999', ProductPrice::STOCK_DIGITS))->toBeTrue()
        ->and(ProductPrice::readable('12345678', ProductPrice::STOCK_DIGITS))->toBeFalse();
});

it('writes an amount the Vietnamese way', function (): void {
    expect(ProductPrice::format(1250000))->toBe('1.250.000')
        ->and(ProductPrice::format(0))->toBe('0')
        ->and(ProductPrice::format(999))->toBe('999');
});

it('applies a sale below the price inside its start and end, either side open', function (): void {
    $now = CarbonImmutable::parse('2026-11-19 10:00:00', 'UTC');

    expect(ProductPrice::saleApplies(1000, 900, null, null, $now))->toBeTrue()
        ->and(ProductPrice::saleApplies(1000, 900, $now, $now, $now))->toBeTrue()
        ->and(ProductPrice::saleApplies(1000, 900, $now->addMinute(), null, $now))->toBeFalse()
        ->and(ProductPrice::saleApplies(1000, 900, null, $now->subMinute(), $now))->toBeFalse()
        ->and(ProductPrice::saleApplies(1000, 1000, null, null, $now))->toBeFalse()
        ->and(ProductPrice::saleApplies(null, 900, null, null, $now))->toBeFalse()
        ->and(ProductPrice::saleApplies(1000, null, null, null, $now))->toBeFalse();
});
