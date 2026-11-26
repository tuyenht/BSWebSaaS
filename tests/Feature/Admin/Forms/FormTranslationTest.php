<?php

use App\Modules\Admin\Livewire\Forms\FormEditor;
use App\Modules\Admin\Support\LangBar;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormField;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteLocale as SiteLanguages;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * «Biểu mẫu» in the site's other languages (board 15): a saved form opens in one of the site's
 * enabled languages (`?locale=`, anything else the main one) under the shared language bar; there
 * only that language's texts are written (name, labels, choices line by line, thanks), an empty one
 * removes that language's version, and what every language shares — switch, emails, fields, their
 * kinds, order and settings — stays locked and is never changed by that language's save.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

/** Turn these languages on for the current site (Vietnamese stays the main one). */
function ftLanguages(string ...$codes): void
{
    foreach ($codes as $code) {
        SiteLocale::query()->updateOrCreate(['locale' => $code], ['is_default' => false, 'enabled' => true, 'is_public' => true]);
    }
    app(SiteLanguages::class)->forget();
}

/** @param  list<string>  $permissions */
function ftMember(array $permissions = ['forms.view', 'forms.manage']): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'admin', $permissions));

    return $user->fresh() ?? $user;
}

/** A quote form: its name in Vietnamese and English, a name field in both, a material select in Vietnamese only, the consent box. */
function ftForm(): Form
{
    $form = Form::query()->create(['key' => 'bao-gia', 'name' => ['vi' => 'Yêu cầu báo giá', 'en' => 'Quote request'], 'success_message' => ['vi' => 'Cảm ơn bạn!'],
        'status' => 'active', 'notify_emails' => ['kinhdoanh@congty.vn']]);
    $form->fields()->create(['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Họ tên', 'en' => 'Name'], 'is_required' => true, 'sort_order' => 1]);
    $form->fields()->create(['key' => 'material', 'type' => 'select', 'label' => ['vi' => 'Vật liệu'], 'is_required' => false, 'sort_order' => 2,
        'options' => ['choices' => [['value' => 'inox', 'label' => ['vi' => 'Inox 304']], ['value' => 'nhom', 'label' => ['vi' => 'Nhôm']], ['value' => 'dong', 'label' => ['vi' => 'Đồng']]]]]);
    $form->fields()->create(['key' => 'consent', 'type' => 'checkbox', 'label' => ['vi' => 'Tôi đồng ý', 'en' => 'I agree'], 'is_required' => true, 'sort_order' => 3]);

    return $form;
}

it('opens another enabled language with its own texts, and the main one for anything else', function (): void {
    $manager = ftMember();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        ftLanguages('en');
        $form = ftForm();

        $page = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid, 'locale' => 'en'])
            ->assertSet('locale', 'en')
            ->assertSet('name', 'Quote request')
            ->assertSet('success', '')
            ->assertSet('fields.0.label', 'Name')
            ->assertSet('fields.1.label', '')
            ->assertSet('fields.1.choices', '')
            ->assertSet('sourceTexts.name', 'Yêu cầu báo giá')
            ->call('toggleField', 'material')
            // the main language's words are the examples; what every language shares is locked
            ->assertSee('placeholder="Vật liệu"', false)
            ->assertSee(__('form_builder.shared_fields_hint'))
            ->assertSee(__('form_builder.field_missing_lang', ['lang' => LangBar::name('en')]))
            ->assertSee(__('entries.ed.shared_chip'))
            ->assertSee(__('form_builder.title_edit', ['name' => 'Yêu cầu báo giá']));
        expect($page->instance()->changeCount())->toBe(0);

        foreach (['xx', 'fr', 'EN', ''] as $asked) {
            Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid, 'locale' => $asked])
                ->assertSet('locale', 'vi')->assertSet('name', 'Yêu cầu báo giá');
        }
        // the browser never changes the language a component writes
        expect(fn () => Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])->set('locale', 'en'))->toThrow(Exception::class);
        // a new form is written in the main language first, and has no language bar until it is saved
        Livewire::actingAs($manager)->test(FormEditor::class, ['purpose' => 'quote', 'locale' => 'en'])
            ->assertSet('locale', 'vi')->assertSet('langBar', []);
    });
});

it('shows the language bar with how far each language is written', function (): void {
    $manager = ftMember();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        ftLanguages('en', 'ja');
        $form = ftForm();

        $bar = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid, 'locale' => 'en'])->get('langBar');
        // the main language's texts: name, thanks, two labels, the choices = 5
        $byCode = collect($bar)->keyBy('code');
        expect($byCode->keys()->all())->toBe(['vi', 'en', 'ja'])
            ->and($byCode['vi']['kind'])->toBe('source')
            ->and($byCode['en']['kind'])->toBe('partial')
            ->and($byCode['en']['done'])->toBe(2)
            ->and($byCode['en']['total'])->toBe(5)
            ->and($byCode['en']['current'])->toBeTrue()
            ->and($byCode['ja']['kind'])->toBe('empty')
            ->and($byCode['ja']['href'])->toBe(route('admin.form-builder.edit', ['ulid' => $form->ulid, 'locale' => 'ja']))
            ->and($byCode['vi']['href'])->toBe(route('admin.form-builder.edit', ['ulid' => $form->ulid]));
    });
});

it('saves only that language\'s texts, choices line by line, and removes a version left empty', function (): void {
    $manager = ftMember();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        ftLanguages('en');
        $form = ftForm();

        $page = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid, 'locale' => 'en'])
            ->set('name', 'Request a quote')
            ->set('success', 'Thank you!')
            ->set('fields.0.label', '')
            ->set('fields.1.label', 'Material')
            ->set('fields.1.choices', "Stainless 304\n\nCopper\n\n");
        expect($page->instance()->changeCount())->toBe(4);
        $page->call('save')->assertSet('toastMsg', __('form_builder.toast_saved'));
        expect($page->instance()->changeCount())->toBe(0);

        $form->refresh();
        $fields = FormField::query()->where('form_id', $form->id)->orderBy('sort_order')->get()->keyBy('key');
        expect($form->name)->toEqual(['vi' => 'Yêu cầu báo giá', 'en' => 'Request a quote'])
            ->and($form->success_message)->toEqual(['vi' => 'Cảm ơn bạn!', 'en' => 'Thank you!'])
            ->and($form->status)->toBe('active')
            ->and($form->notify_emails)->toBe(['kinhdoanh@congty.vn'])
            ->and($fields['name']->label)->toEqual(['vi' => 'Họ tên'])
            ->and($fields['material']->label)->toEqual(['vi' => 'Vật liệu', 'en' => 'Material'])
            ->and($fields['material']->options['choices'])->toEqual([
                ['value' => 'inox', 'label' => ['vi' => 'Inox 304', 'en' => 'Stainless 304']],
                ['value' => 'nhom', 'label' => ['vi' => 'Nhôm']],
                ['value' => 'dong', 'label' => ['vi' => 'Đồng', 'en' => 'Copper']],
            ])
            ->and(array_keys($fields->all()))->toBe(['name', 'material', 'consent'])
            ->and($fields['consent']->label)->toEqual(['vi' => 'Tôi đồng ý', 'en' => 'I agree']);

        // the main language's save keeps the English words
        Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])
            ->set('fields.1.label', 'Chất liệu')->call('save')->assertSet('toastMsg', __('form_builder.toast_saved'));
        expect(FormField::query()->where('form_id', $form->id)->where('key', 'material')->value('label'))->toEqual(['vi' => 'Chất liệu', 'en' => 'Material']);
    });
});

it('keeps more choice lines than the field has from being saved, and says how many', function (): void {
    $manager = ftMember();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        ftLanguages('en');
        $form = ftForm();

        Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid, 'locale' => 'en'])
            ->set('fields.1.choices', "A\nB\nC\nD")
            ->call('save')
            ->assertSet('toastMsg', '')
            ->assertSee(trans_choice('form_builder.choices_count_error', 3, ['n' => 3, 'lang' => LangBar::name('vi')]))
            ->assertDispatched('bm-focus', id: 'bm-o-material');

        expect(FormField::query()->where('form_id', $form->id)->where('key', 'material')->value('options')['choices'][0]['label'])->toEqual(['vi' => 'Inox 304']);
    });
});

it('never changes what every language shares from another language', function (string $property, mixed $value): void {
    $manager = ftMember();

    app(Tenancy::class)->enter(1, function () use ($manager, $property, $value): void {
        ftLanguages('en');
        $form = ftForm();

        Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid, 'locale' => 'en'])
            ->set($property, $value)
            ->assertForbidden();
    });
})->with([
    'the switch' => ['active', false],
    'a kind' => ['fields.0.type', 'textarea'],
    'required' => ['fields.0.required', false],
    'an email being typed' => ['newEmail', 'a@b.vn'],
]);

it('refuses adding, removing, moving fields and changing emails from another language, and says where', function (): void {
    $manager = ftMember();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        ftLanguages('en');
        $form = ftForm();
        $why = __('entries.ed.shared_guard', ['lang' => LangBar::name('vi')]);

        $page = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid, 'locale' => 'en']);
        foreach ([['addField', 'text'], ['removeField', 'name'], ['move', 'material', -1], ['sortField', 'material', 0], ['removeEmail', 0], ['addEmail']] as $call) {
            $page->call('closeToast')->call(...$call)->assertSet('toastMsg', $call[0] === 'addEmail' ? '' : $why);
        }
        expect(array_column($page->get('fields'), 'key'))->toBe(['name', 'material'])
            ->and($page->get('emails'))->toBe(['kinhdoanh@congty.vn'])
            ->and($page->instance()->changeCount())->toBe(0);
    });
});

it('refuses a translation save from a member without forms.manage', function (): void {
    $viewer = ftMember(['forms.view']);

    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        ftLanguages('en');
        $form = ftForm();

        Livewire::actingAs($viewer)->test(FormEditor::class, ['ulid' => $form->ulid, 'locale' => 'en'])
            ->assertSee('data-state="noperm"', false)
            ->set('name', 'Changed')
            ->call('save');

        expect($form->refresh()->name)->toEqual(['vi' => 'Yêu cầu báo giá', 'en' => 'Quote request']);
    });
});

it('shows neither the language bar nor the language chips on a site with one language', function (): void {
    $manager = ftMember();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $form = ftForm();

        Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid, 'locale' => 'en'])
            ->assertSet('locale', 'vi')
            ->assertSet('langBar', [])
            ->assertDontSee('data-lang-bar', false)
            ->assertDontSee('data-lang-chip', false);
    });
});
