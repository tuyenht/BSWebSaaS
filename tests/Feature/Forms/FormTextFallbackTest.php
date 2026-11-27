<?php

use App\Modules\Forms\Actions\SubmitForm;
use App\Modules\Forms\Components\FormComponent;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormField;
use App\Modules\Forms\Notifications\NewLeadNotification;
use App\Modules\Forms\Support\FormDraft;
use App\Modules\Forms\Support\FormFieldTypes;
use App\Modules\Forms\Support\LeadAnswers;
use App\Modules\Tenancy\Context\SiteLocale as SiteLanguages;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/*
 * A form text with no version in the visitor's language reads in the site's default language
 * (not always Vietnamese), and in any stored one only when the default has none either.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** Makes $code the site's default language. */
function defaultLanguage(string $code): void
{
    SiteLocale::query()->update(['is_default' => false]);
    SiteLocale::query()->updateOrCreate(['locale' => $code], ['is_default' => true, 'enabled' => true, 'is_public' => true]);
    app(SiteLanguages::class)->forget();
}

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

it('keeps a choice and its value when the editor saves it untouched on a site whose default language has no label for it', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        defaultLanguage('ja');
        $existing = [['value' => 'a', 'label' => ['en' => 'Stainless', 'de' => 'Edelstahl']]];

        // the editor shows «Stainless» (visitor language, default, then any stored language)
        expect(FormFieldTypes::choiceLabels(choiceField(['en' => 'Stainless', 'de' => 'Edelstahl']), 'vi'))->toBe(['a' => 'Stainless']);

        expect(FormDraft::choicesFrom('Stainless', 'vi', $existing))->toBe([
            ['value' => 'a', 'label' => ['en' => 'Stainless', 'de' => 'Edelstahl', 'vi' => 'Stainless']],
        ]);
    });
});

it('gives a new line a value of its own, never the value of a choice shown under the same words', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        defaultLanguage('ja');
        $existing = [['value' => 'stainless', 'label' => ['en' => 'Steel']]];

        $out = FormDraft::choicesFrom("Steel\nStainless", 'vi', $existing);
        expect(array_column($out, 'value'))->toBe(['stainless', 'stainless-2']);
    });
});

it('gives the default language its turn before any other stored language, even when Vietnamese is stored', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        defaultLanguage('en');

        expect(FormDraft::text(['vi' => 'Vật liệu', 'en' => 'Material'], 'ja'))->toBe('Material')
            ->and(FormDraft::text(['vi' => 'Vật liệu', 'en' => 'Material'], 'vi'))->toBe('Vật liệu');
    });
});

it('treats an empty text in the visitor language as missing', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        defaultLanguage('en');

        expect(FormDraft::text(['ja' => '', 'en' => 'Material'], 'ja'))->toBe('Material')
            ->and(FormDraft::text(['ja' => '', 'en' => ''], 'ja'))->toBe('')
            ->and(FormFieldTypes::choiceLabels(choiceField(['ja' => '', 'en' => 'Steel']), 'ja'))->toBe(['a' => 'Steel']);
    });
});

/** A form stored in Vietnamese and in the site's default English, to be read by a visitor in Japanese. */
function twoLanguageForm(): Form
{
    defaultLanguage('en');
    $form = Form::query()->create(['key' => 'quote', 'name' => ['vi' => 'Liên hệ', 'en' => 'Contact'], 'status' => 'active', 'notify_emails' => ['sales@example.test']]);
    $form->fields()->create(['key' => 'company', 'type' => 'text', 'label' => ['vi' => 'Công ty', 'en' => 'Company'], 'sort_order' => 1]);
    $form->fields()->create(['key' => 'material', 'type' => 'select', 'label' => ['vi' => 'Vật liệu', 'en' => 'Material'], 'sort_order' => 2,
        'options' => ['choices' => [['value' => 'a', 'label' => ['vi' => 'Thép', 'en' => 'Steel']]]]]);
    $form->fields()->create(['key' => 'consent', 'type' => 'checkbox', 'label' => ['vi' => 'Tôi đồng ý', 'en' => 'I agree'], 'sort_order' => 3]);

    return $form->load('fields');
}

it('records the consent sentence in the site default language for a visitor in a third language', function (): void {
    Notification::fake();
    app(Tenancy::class)->enter(1, function (): void {
        $form = twoLanguageForm();
        app()->setLocale('ja');

        $lead = app(SubmitForm::class)->handle($form, ['name' => 'Khách', 'company' => 'ACME', 'consent' => '1']);

        expect($lead->data['consent'])->toBe('I agree');
    });
});

it('reads the answers of a lead in the default language when its form has no text in the reader language', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $form = twoLanguageForm();

        $answers = LeadAnswers::for($form, ['company' => 'ACME', 'material' => 'a'], 'ja');

        expect(array_column($answers, 'label'))->toBe(['Company', 'Material'])
            ->and(array_column($answers, 'value'))->toBe(['ACME', 'Steel']);
    });
});

it('writes the lead mail in the default language when the form has no text in the mail language', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $form = twoLanguageForm();
        $lead = $form->submissions()->create(['name' => 'Khách', 'data' => ['name' => 'Khách', 'company' => 'ACME', 'material' => 'a']]);
        app()->setLocale('ja');

        $mail = new NewLeadNotification($lead, $form)->toMail(new AnonymousNotifiable);
        $html = (string) $mail->render();

        expect($mail->subject)->toContain('Contact')->not->toContain('Liên hệ')
            ->and($html)->toContain('Company')->toContain('Steel')->not->toContain('Công ty');
    });
});
