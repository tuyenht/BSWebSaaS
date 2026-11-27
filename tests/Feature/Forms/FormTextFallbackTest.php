<?php

use App\Modules\Forms\Components\FormComponent;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormField;
use App\Modules\Forms\Support\FormFieldTypes;
use App\Modules\Tenancy\Context\SiteLocale as SiteLanguages;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;

/*
 * A form text with no version in the visitor's language reads in the site's default language
 * (not always Vietnamese), and in any stored one only when the default has none either.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** A site whose default language is English, with a form stored in English only. */
function englishOnlyForm(): Form
{
    SiteLocale::query()->update(['is_default' => false]);
    SiteLocale::query()->updateOrCreate(['locale' => 'en'], ['is_default' => true, 'enabled' => true, 'is_public' => true]);
    app(SiteLanguages::class)->forget();

    $form = Form::query()->create(['key' => 'quote', 'name' => ['en' => 'Quote request'], 'success_message' => ['en' => 'Thank you!'], 'status' => 'active']);
    $form->fields()->create(['key' => 'material', 'type' => 'select', 'label' => ['en' => 'Material'], 'sort_order' => 1,
        'options' => ['choices' => [['value' => 'inox', 'label' => ['en' => 'Stainless']]], 'placeholder' => ['en' => 'Pick one']]]);

    return $form->load('fields');
}

it('shows a text stored only in the site default language to a visitor in a third language', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $form = englishOnlyForm();
        app()->setLocale('vi');

        $data = (new FormComponent)->data(['form_key' => 'quote']);
        expect($data['heading'])->toBe('Quote request')
            ->and($data['fields'][0]['label'])->toBe('Material')
            ->and($data['fields'][0]['choices'])->toBe(['inox' => 'Stainless'])
            ->and($data['fields'][0]['placeholder'])->toBe('Pick one')
            ->and($form->successMessageFor('vi'))->toBe('Thank you!');
    });
});

it('prefers the visitor language, then the default language, then any stored one', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        englishOnlyForm();
        $field = choiceField(['de' => 'Werkstoff', 'en' => 'Material']);

        expect(FormFieldTypes::choiceLabels($field, 'de'))->toBe(['a' => 'Werkstoff'])
            ->and(FormFieldTypes::choiceLabels($field, 'vi'))->toBe(['a' => 'Material'])
            ->and(FormFieldTypes::choiceLabels(choiceField(['de' => 'Werkstoff']), 'vi'))->toBe(['a' => 'Werkstoff']);
    });
});

/** @param  array<string, string>  $label */
function choiceField(array $label): FormField
{
    return new FormField(['key' => 'k', 'type' => 'select', 'options' => ['choices' => [['value' => 'a', 'label' => $label]]]]);
}
