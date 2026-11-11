<?php

use App\Modules\Theme\Components\ReadingTime;

it('rounds the reading time up to whole minutes, never below one, and is zero without text', function (): void {
    $words = fn (int $n): string => '<p>'.implode(' ', array_fill(0, $n, 'từ')).'</p>';

    expect(ReadingTime::minutes($words(5)))->toBe(1)
        ->and(ReadingTime::minutes($words(200)))->toBe(1)
        ->and(ReadingTime::minutes($words(201)))->toBe(2)
        ->and(ReadingTime::minutes('<p> </p><script>alert(1)</script>'))->toBe(0)
        ->and(ReadingTime::minutes(''))->toBe(0);
});

it('takes the initials of the last two words of a name', function (): void {
    expect(ReadingTime::initials('An Vinh'))->toBe('AV')
        ->and(ReadingTime::initials('Công ty Cơ khí An Vinh'))->toBe('AV')
        ->and(ReadingTime::initials('đạt'))->toBe('Đ')
        ->and(ReadingTime::initials('  '))->toBe('');
});

it('does not glue the last word of one element to the first word of the next', function (): void {
    $words = implode(' ', array_fill(0, 199, 'từ'));

    expect(ReadingTime::minutes('<h2>'.$words.' mục</h2><p>Nội dung</p>'))->toBe(2)
        ->and(ReadingTime::minutes('<p>'.$words.' mục</p><p>Nội</p>'))->toBe(2)
        ->and(ReadingTime::minutes('<p>'.$words.' mục</p>'))->toBe(1);
});

it('counts characters, not words, for text written without spaces', function (): void {
    expect(ReadingTime::minutes('<p>'.str_repeat('字', 500).'</p>'))->toBe(1)
        ->and(ReadingTime::minutes('<p>'.str_repeat('字', 501).'</p>'))->toBe(2)
        ->and(ReadingTime::minutes('<p>'.str_repeat('あ', 1200).'</p>'))->toBe(3);
});
