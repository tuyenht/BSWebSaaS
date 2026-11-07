<?php

use App\Modules\Forms\Models\FormField;
use App\Modules\Forms\Support\FormFieldTypes;
use App\Modules\Tenancy\Context\SiteTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/*
 * The rules of the field types form_fields cannot hold yet (number, date, radio, checkboxes):
 * written and checked now so that widening the type CHECK is their only remaining schema step.
 * Fields are built in memory — no form row can carry these types today.
 */

/**
 * @param  array<string, mixed>  $attributes
 */
function typedField(string $type, array $attributes = []): FormField
{
    return new FormField(['key' => 'answer', 'type' => $type, 'label' => ['vi' => 'Câu trả lời'], 'is_required' => false, ...$attributes]);
}

function fieldPasses(FormField $field, mixed $value): bool
{
    return Validator::make(
        ['answer' => $value],
        ['answer' => FormFieldTypes::rules($field), ...FormFieldTypes::nestedRules($field)],
    )->passes();
}

const PICKS = ['options' => ['choices' => [
    ['value' => 'cnc', 'label' => ['vi' => 'CNC']],
    ['value' => 'mold', 'label' => ['vi' => 'Khuôn']],
    ['value' => 'laser', 'label' => ['vi' => 'Laser']],
]]];

it('takes each pick of a checkboxes field once', function (): void {
    $field = typedField('checkboxes', PICKS);

    expect(fieldPasses($field, ['cnc', 'mold']))->toBeTrue()
        ->and(fieldPasses($field, ['cnc', 'cnc']))->toBeFalse()
        ->and(fieldPasses($field, array_fill(0, 5000, 'cnc')))->toBeFalse();
});

it('never takes more picks than a checkboxes field has choices, nor more than the site allows', function (): void {
    expect(fieldPasses(typedField('checkboxes', PICKS), ['cnc', 'mold', 'laser', 'other']))->toBeFalse()
        ->and(fieldPasses(typedField('checkboxes', [...PICKS, 'validation' => ['max_choices' => 2]]), ['cnc', 'mold', 'laser']))->toBeFalse()
        ->and(fieldPasses(typedField('checkboxes', [...PICKS, 'validation' => ['max_choices' => 2]]), ['cnc', 'laser']))->toBeTrue();
});

it('takes a checkboxes pick only from its choices', function (): void {
    expect(fieldPasses(typedField('checkboxes', PICKS), ['welding']))->toBeFalse()
        ->and(fieldPasses(typedField('checkboxes', PICKS), ['any' => 'cnc']))->toBeFalse()
        ->and(fieldPasses(typedField('checkboxes', PICKS), 'cnc'))->toBeFalse();
});

it('takes a radio answer only from its choices', function (): void {
    expect(fieldPasses(typedField('radio', PICKS), 'mold'))->toBeTrue()
        ->and(fieldPasses(typedField('radio', PICKS), 'welding'))->toBeFalse();
});

it('caps a number answer at 15 digits and 6 decimals', function (mixed $value, bool $ok): void {
    expect(fieldPasses(typedField('number'), $value))->toBe($ok);
})->with([
    'plain' => ['1200', true],
    'negative decimal' => ['-3.25', true],
    '15 digits' => [str_repeat('9', 15), true],
    '16 digits' => [str_repeat('9', 16), false],
    '20,000 digits' => [str_repeat('9', 20000), false],
    'overflowing exponent' => ['1e309', false],
    'exponent' => ['1e3', false],
    '7 decimals' => ['1.1234567', false],
    'words' => ['twelve', false],
]);

it('keeps a number answer inside the bounds the site set', function (): void {
    $field = typedField('number', ['validation' => ['min' => 1, 'max' => 500]]);

    expect(fieldPasses($field, '0'))->toBeFalse()
        ->and(fieldPasses($field, '501'))->toBeFalse()
        ->and(fieldPasses($field, '250'))->toBeTrue();
});

it('refuses a past date by the site\'s calendar, not UTC\'s', function (): void {
    $field = typedField('date', ['validation' => ['not_past' => true]]);
    $siteZone = app(SiteTime::class)->zone();

    // 00:30 on the 10th in the site's zone is still the 9th in UTC for any zone east of UTC.
    $siteMidnight = CarbonImmutable::parse('2026-11-10 00:30', $siteZone);
    expect($siteMidnight->utc()->toDateString())->toBe('2026-11-09'); // the case this test is about
    $this->travelTo($siteMidnight);

    expect(fieldPasses($field, '2026-11-09'))->toBeFalse()
        ->and(fieldPasses($field, '2026-11-10'))->toBeTrue()
        ->and(fieldPasses($field, '10/11/2026'))->toBeFalse();
});

it('drops a choice without a value and a value listed twice', function (): void {
    $field = typedField('select', ['options' => ['choices' => [
        ['value' => 'cnc', 'label' => ['vi' => 'CNC']],
        ['value' => '', 'label' => ['vi' => 'Trống']],
        ['value' => 'cnc', 'label' => ['vi' => 'CNC lần hai']],
    ]]]);

    expect(FormFieldTypes::choiceValues($field))->toBe(['cnc']);
});

it('shows only the types the storefront form block can draw', function (): void {
    expect(FormFieldTypes::isCollectable(typedField('text')))->toBeTrue()
        ->and(FormFieldTypes::isCollectable(typedField('select', PICKS)))->toBeTrue()
        ->and(FormFieldTypes::isCollectable(typedField('select')))->toBeFalse()
        ->and(FormFieldTypes::isCollectable(typedField('radio', PICKS)))->toBeFalse()
        ->and(FormFieldTypes::isCollectable(typedField('number')))->toBeFalse();
});

it('reads each choice in the visitor\'s language, else Vietnamese, else its value', function (): void {
    $field = typedField('select', ['options' => ['choices' => [
        ['value' => 'cnc', 'label' => ['vi' => 'Gia công CNC', 'en' => 'CNC machining']],
        ['value' => 'mold', 'label' => ['vi' => 'Khuôn mẫu']],
        ['value' => 'laser', 'label' => ['vi' => '']],
    ]]]);

    expect(FormFieldTypes::choiceLabels($field, 'en'))->toBe(['cnc' => 'CNC machining', 'mold' => 'Khuôn mẫu', 'laser' => 'laser']);
});
