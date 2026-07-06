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
