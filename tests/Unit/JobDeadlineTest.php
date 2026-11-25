<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\JobDeadline;
use Carbon\CarbonImmutable;

/*
 * The deadline state of a job post: open, closing soon, today, expired or no date. Only the day
 * counts, in the site's time zone.
 */

it('names the state of a deadline from the day it is seen', function (string $deadline, string $expected): void {
    $today = CarbonImmutable::parse('2026-11-07 23:30', 'Asia/Ho_Chi_Minh');

    expect((new JobDeadline)->state(CarbonImmutable::parse($deadline, 'Asia/Ho_Chi_Minh'), $today))->toBe($expected);
})->with([
    'yesterday' => ['2026-11-06 23:59', JobDeadline::EXPIRED],
    'later today' => ['2026-11-07 00:00', JobDeadline::TODAY],
    'tomorrow' => ['2026-11-08 08:00', JobDeadline::CLOSING_SOON],
    'in seven days' => ['2026-11-14 08:00', JobDeadline::CLOSING_SOON],
    'in eight days' => ['2026-11-15 08:00', JobDeadline::OPEN],
]);

it('has no date when the post carries none', function (): void {
    expect((new JobDeadline)->state(null, CarbonImmutable::now()))->toBe(JobDeadline::NO_DATE);
});

function postWithFacts(array $facts): Entry
{
    return (new Entry)->forceFill(['meta' => ['facts' => $facts]]);
}

function chip(string $text, string $locale = 'vi'): array
{
    return ['text' => [$locale => $text], 'tone' => 'plain'];
}

it('reads the deadline from the Hạn nộp chip, in the d/m form too', function (string $text, string $day): void {
    $deadline = (new JobDeadline)->deadlineOf(postWithFacts([chip('Lương: Thỏa thuận'), chip($text)]));

    expect($deadline?->format('Y-m-d'))->toBe($day)->and($deadline?->timezoneName)->toBe('Asia/Ho_Chi_Minh');
})->with([
    'dd/mm/yyyy' => ['Hạn nộp: 31/12/2026', '2026-12-31'],
    'd/m/yyyy' => ['Hạn nộp: 5/1/2027', '2027-01-05'],
    'no space after the colon' => ['Hạn nộp:05/01/2027', '2027-01-05'],
]);

it('reads the deadline chip of the other languages and uses the given zone', function (): void {
    $deadline = (new JobDeadline)->deadlineOf(postWithFacts([chip('Application deadline: 01/02/2027', 'en')]), 'UTC');

    expect($deadline?->format('Y-m-d e'))->toBe('2027-02-01 UTC')
        ->and((new JobDeadline)->deadlineOf(postWithFacts([chip('応募締切: 01/02/2027', 'ja')]))?->format('Y-m-d'))->toBe('2027-02-01')
        ->and((new JobDeadline)->deadlineOf(postWithFacts([chip('截止日期：01/02/2027', 'zh')]))?->format('Y-m-d'))->toBe('2027-02-01');
});

it('gives null for a bad date, a missing chip, other text around the date or odd meta', function (array $facts): void {
    expect((new JobDeadline)->deadlineOf(postWithFacts($facts)))->toBeNull();
})->with([
    'impossible day' => [[['text' => ['vi' => 'Hạn nộp: 31/02/2026']]]],
    'month 13' => [[['text' => ['vi' => 'Hạn nộp: 01/13/2026']]]],
    'not a date' => [[['text' => ['vi' => 'Hạn nộp: sớm nhất có thể']]]],
    'two-digit year' => [[['text' => ['vi' => 'Hạn nộp: 01/02/26']]]],
    'no deadline chip' => [[['text' => ['vi' => 'Lương: Thỏa thuận']]]],
    'text around the date' => [[['text' => ['vi' => 'Nộp trước Hạn nộp: 01/02/2027 nhé']]]],
    'text is not a map' => [[['text' => 'Hạn nộp: 01/02/2027']]],
    'fact is not an array' => [['Hạn nộp: 01/02/2027']],
    'no facts' => [[]],
]);

it('gives null when meta carries no facts list', function (): void {
    expect((new JobDeadline)->deadlineOf((new Entry)->forceFill(['meta' => ['facts' => 'x']])))->toBeNull()
        ->and((new JobDeadline)->deadlineOf(new Entry))->toBeNull();
});
