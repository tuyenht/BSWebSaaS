<?php

use App\Modules\Cms\Casts\SanitizedPageBody;
use Illuminate\Database\Eloquent\Model;

/*
 * When a page body counts as unchanged (the save then writes no body and leaves the content mark):
 * the same page, whatever order PostgreSQL's jsonb hands an object's keys back in, and with a number
 * the editor turned from its stored text form. A list keeps its order, and nothing else is equated.
 */

function bodySame(mixed $first, mixed $second): bool
{
    $model = new class extends Model {};

    return (new SanitizedPageBody)->compare($model, 'body', $first, $second);
}

/** @param array<string, mixed> $props */
function bodyWith(array $props): string
{
    return json_encode(['blocks' => [['type' => 'entry_grid', 'props' => $props]]], JSON_THROW_ON_ERROR);
}

it('counts a body whose object keys come back in another order as the same', function (): void {
    $stored = '{"blocks": [{"type": "entry_grid", "props": {"limit": 6, "heading": "Tin mới ở", "variant": "list"}}]}';

    expect(bodySame($stored, bodyWith(['heading' => 'Tin mới ở', 'limit' => 6, 'variant' => 'list'])))->toBeTrue()
        ->and(bodySame(bodyWith(['heading' => 'Tin mới ở', 'limit' => 6, 'variant' => 'list']), $stored))->toBeTrue();
});

it('counts a number and the text that spells exactly it as the same', function (): void {
    expect(bodySame(bodyWith(['limit' => '6']), bodyWith(['limit' => 6])))->toBeTrue()
        ->and(bodySame(bodyWith(['limit' => 6]), bodyWith(['limit' => '6'])))->toBeTrue()
        ->and(bodySame(bodyWith(['ratio' => '1.5']), bodyWith(['ratio' => 1.5])))->toBeTrue();
});

it('tells apart what the page would show differently', function (mixed $first, mixed $second): void {
    expect(bodySame($first, $second))->toBeFalse();
})->with([
    'another value' => [bodyWith(['heading' => 'Tin mới ở']), bodyWith(['heading' => 'Tin khác ở'])],
    'blocks in another order' => [
        json_encode(['blocks' => [['type' => 'hero', 'props' => []], ['type' => 'cta', 'props' => []]]]),
        json_encode(['blocks' => [['type' => 'cta', 'props' => []], ['type' => 'hero', 'props' => []]]]),
    ],
    'a list in another order' => [bodyWith(['paragraphs' => ['Một', 'Hai']]), bodyWith(['paragraphs' => ['Hai', 'Một']])],
    'a key more' => [bodyWith(['heading' => 'Tin ở']), bodyWith(['heading' => 'Tin ở', 'limit' => 6])],
    'a number in another spelling' => [bodyWith(['limit' => '06']), bodyWith(['limit' => 6])],
    'a number against a text that is not it' => [bodyWith(['heading' => '6 tin']), bodyWith(['heading' => 6])],
    'a number against true' => [bodyWith(['limit' => 1]), bodyWith(['limit' => true])],
    'null against an empty text' => [bodyWith(['heading' => null]), bodyWith(['heading' => ''])],
    'a body against none' => [bodyWith([]), null],
]);
