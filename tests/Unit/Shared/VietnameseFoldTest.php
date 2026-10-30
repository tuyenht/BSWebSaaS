<?php

use App\Modules\Shared\Support\VietnameseFold;

it('folds Vietnamese letters and case away', function (string $text, string $folded): void {
    expect(VietnameseFold::text($text))->toBe($folded);
})->with([
    ['Nguyễn Đức', 'nguyen duc'],
    ['TRÌNH ĐƠN', 'trinh don'],
    ['Lê Hoàng Phúc', 'le hoang phuc'],
    ['Ỹ ỷ ự ợ ậ', 'y y u o a'],
    ['abc 113.161.72.18', 'abc 113.161.72.18'],
    ['東京 Straße', '東京 straße'],
]);

it('folds combining marks the same as precomposed letters', function (): void {
    // "ễ" typed as e + circumflex + tilde, as some keyboards send it
    expect(VietnameseFold::text("Nguye\u{0302}\u{0303}n"))->toBe('nguyen');
});

it('maps every accented letter to one plain letter', function (): void {
    $from = (new ReflectionClassConstant(VietnameseFold::class, 'FROM'))->getValue();
    $to = (new ReflectionClassConstant(VietnameseFold::class, 'TO'))->getValue();

    expect(mb_strlen($from))->toBe(mb_strlen($to))
        ->and(mb_str_split($from))->toBe(array_values(array_unique(mb_str_split($from))))
        ->and(preg_match('/^[a-zA-Z]+$/', $to))->toBe(1);
});

it('escapes LIKE wildcards in a needle', function (): void {
    expect(VietnameseFold::like('50%_off\\'))->toBe('%50\\%\\_off\\\\%');
});
