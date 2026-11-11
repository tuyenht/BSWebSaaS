<?php

use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Support\EntryContentWriter;
use App\Modules\Cms\Support\JobFacts;
use App\Modules\Cms\Validation\EntryContentRules;
use App\Modules\Provisioning\ContentPack\PackReader;
use App\Modules\Provisioning\ContentPack\PackValidator;
use Tests\Fixtures\ContentPacks;

/*
 * The short facts and the badge of a card (`meta.facts`, `meta.badge`): what may be stored, what a
 * page draws from what is stored. Unknown keys and unknown tones never reach a page.
 */

beforeEach(function (): void {
    seedTenancy();
});

function fact(string $text, string $tone = 'plain'): array
{
    return ['text' => ['vi' => $text], 'tone' => $tone];
}

it('accepts up to four facts and a badge within their limits', function (): void {
    $meta = [
        'facts' => [fact('Trung Giã'), fact('Toàn thời gian · SL: 3'), fact('Lương: 9 – 14 triệu/tháng', 'accent'), fact(str_repeat('a', 40))],
        'badge' => ['vi' => str_repeat('b', 24)],
        'image_url' => 'x/y',
    ];

    expect(JobFacts::errors($meta))->toBe([])
        ->and(app(EntryContentRules::class)->metaErrors($meta))->toBe([])
        ->and(JobFacts::errors(['image_url' => 'x/y']))->toBe([]);
});

it('refuses facts and badges that break the limits', function (array $meta, string $why): void {
    expect(implode(' ', JobFacts::errors($meta)))->toContain($why)
        ->and(app(EntryContentRules::class)->metaErrors($meta))->not->toBe([]);
})->with([
    'five facts' => [['facts' => array_fill(0, 5, ['text' => ['vi' => 'a'], 'tone' => 'plain'])], 'at most 4'],
    'a fact of 41 characters' => [['facts' => [['text' => ['vi' => str_repeat('a', 41)], 'tone' => 'plain']]], 'longer than 40'],
    'a badge of 25 characters' => [['badge' => ['vi' => str_repeat('a', 25)]], 'longer than 24'],
    'an unknown tone' => [['facts' => [['text' => ['vi' => 'a'], 'tone' => 'loud']]], 'tone must be one of'],
    'a text that is not a language map' => [['facts' => [['text' => 'a', 'tone' => 'plain']]], 'text per language'],
    'an empty text' => [['facts' => [['text' => ['vi' => '  '], 'tone' => 'plain']]], 'must be a text'],
    'facts that are not a list' => [['facts' => ['a' => 1]], 'must be a list'],
    'a badge that is a string' => [['badge' => 'Tuyển gấp'], 'text per language'],
]);

it('counts characters, not bytes, against the limit', function (): void {
    expect(JobFacts::errors(['facts' => [fact(str_repeat('Ế', 40))]]))->toBe([])
        ->and(JobFacts::errors(['badge' => ['vi' => str_repeat('Ế', 25)]]))->not->toBe([]);
});

it('draws only the known keys, falls back to another language and defaults the tone', function (): void {
    $meta = [
        'facts' => [
            ['text' => ['vi' => 'Một', 'en' => 'One'], 'tone' => 'accent', 'html' => '<b>x</b>', 'onclick' => 'x()'],
            ['text' => ['en' => 'Two only in English']],
            ['text' => ['vi' => 'Ba'], 'tone' => 'loud'],
            ['text' => ['vi' => ''], 'tone' => 'plain'],
            ['text' => ['vi' => 'Năm, past the fourth']],
        ],
        'badge' => ['en' => 'Urgent'],
        'secret' => 'never',
    ];

    $vi = JobFacts::read($meta, 'vi');

    expect($vi['facts'])->toBe([
        ['text' => 'Một', 'tone' => 'accent'],
        ['text' => 'Two only in English', 'tone' => 'plain'],
        ['text' => 'Ba', 'tone' => 'plain'],
    ])->and($vi['badge'])->toBe('Urgent')
        ->and(JobFacts::read($meta, 'en')['facts'][0]['text'])->toBe('One')
        ->and(JobFacts::read(null, 'vi'))->toBe(['facts' => [], 'badge' => '']);
});

it('cuts a stored text that is longer than the limit when it is drawn', function (): void {
    $read = JobFacts::read(['facts' => [fact(str_repeat('a', 60))], 'badge' => ['vi' => str_repeat('b', 60)]], 'vi');

    expect(mb_strlen($read['facts'][0]['text']))->toBe(40)->and(mb_strlen($read['badge']))->toBe(24);
});

it('refuses to restore a revision whose meta carries bad facts', function (): void {
    $snapshot = fn (array $meta): array => ['v' => RecordEntryRevision::FORMAT, 'title' => 'T', 'slug' => 't', 'body' => ['blocks' => []], 'meta' => $meta];
    $writer = app(EntryContentWriter::class);

    expect($writer->read($snapshot(['facts' => [fact('Ok')]]), 'post'))->not->toBeNull()
        ->and($writer->read($snapshot(['facts' => array_fill(0, 5, fact('Ok'))]), 'post'))->toBeNull()
        ->and($writer->read($snapshot(['badge' => ['vi' => str_repeat('x', 25)]]), 'post'))->toBeNull();
});

describe('content pack', function (): void {
    beforeEach(function (): void {
        $this->folder = ContentPacks::use();
    });

    afterEach(fn () => ContentPacks::cleanup());

    it('refuses a post whose facts break the same rules', function (): void {
        $post = collect(json_decode(file_get_contents($this->folder.'/posts.json'), true)['items'] ?? [])->first(fn (array $item): bool => str_starts_with($item['key'], 'post:'));
        expect($post)->not->toBeNull();

        ContentPacks::item($this->folder, 'posts.json', $post['key'], fn (array $item): array => ['facts' => array_fill(0, 5, fact('A')), 'badge' => ['vi' => str_repeat('b', 25)]] + $item);

        $problems = implode("\n", app(PackValidator::class)->validate(app(PackReader::class)->read('demo-co'), 'demo-co'));

        expect($problems)->toContain("{$post['key']}: facts holds at most 4 items.")->toContain("{$post['key']}: badge (vi) is longer than 24 characters.");
    });
});
