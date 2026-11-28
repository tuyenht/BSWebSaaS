<?php

use App\Modules\Admin\Support\DisplayName;

/*
 * Topbar short-name heuristic: at most the first two words of the free-text name column
 * (no structured family/given split exists); the user keeps final control by editing
 * their name in Hồ sơ.
 */

it('shortens a long name to its first two words', function (): void {
    expect(DisplayName::short('An Vinh Owner'))->toBe('An Vinh')
        ->and(DisplayName::short('Trần Huy Tuyền Nguyễn'))->toBe('Trần Huy');
});

it('keeps one- and two-word names untouched', function (): void {
    expect(DisplayName::short('Tuyền'))->toBe('Tuyền')
        ->and(DisplayName::short('An Vinh'))->toBe('An Vinh');
});

it('normalizes stray whitespace', function (): void {
    expect(DisplayName::short('  An   Vinh   Owner  '))->toBe('An Vinh')
        ->and(DisplayName::short('   '))->toBe('');
});

it('takes an avatar\'s two letters from the last two words of a Vietnamese name', function (): void {
    expect(DisplayName::initials('Nguyễn Ngọc Anh'))->toBe('NA')
        ->and(DisplayName::initials('Ngọc Anh'))->toBe('NA')
        ->and(DisplayName::initials('An Vinh Owner'))->toBe('VO');
});

it('keeps the diacritics of an avatar\'s letters and writes them in upper case', function (): void {
    expect(DisplayName::initials('đặng ánh'))->toBe('ĐÁ')
        ->and(DisplayName::initials('Trần Thị Ổi'))->toBe('TỔ')
        // a letter typed as a base plus combining marks (decomposed) is still one letter
        ->and(DisplayName::initials("Tr\u{0061}\u{0302}\u{0300}n \u{0041}\u{0301}nh"))->toBe('TÁ');
});

it('gives a one-word name its first two letters and an empty name none', function (): void {
    expect(DisplayName::initials('Tuyền'))->toBe('TU')
        ->and(DisplayName::initials('Ổ'))->toBe('Ổ')
        ->and(DisplayName::initials('  An   Vinh  '))->toBe('AV')
        ->and(DisplayName::initials('   '))->toBe('');
});

it('gives board 05\'s list disc one letter for a one-word name and board 17\'s history disc the last word\'s letter', function (): void {
    // the list's «Người tạo» disc: the last two words, a one-word name one letter
    expect(DisplayName::initials('Lê Văn Bình', fillOneWord: false))->toBe('VB')
        ->and(DisplayName::initials('Tuyền', fillOneWord: false))->toBe('T')
        // the review history's disc: the last word's first letter, a decomposed letter kept whole
        ->and(DisplayName::initials('Lê Văn Bình', words: 1))->toBe('B')
        ->and(DisplayName::initials("Nguy\u{0065}\u{0302}\u{0303}n \u{0041}\u{0301}nh", words: 1))->toBe('Á')
        ->and(DisplayName::initials('   ', words: 1))->toBe('');
});
