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
use Spatie\Permission\PermissionRegistrar;

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
            ->assertSee(__('form_builder.title_edit', ['name' => 'Yêu cầu báo giá']))
            // the texts are typed in that language's direction, and every field row's markup is whole
            ->assertSee('wire:model.blur="name" maxlength="200" dir="ltr" lang="en"', false)
            ->assertDontSee('"" title=', false);
        expect($page->instance()->changeCount())->toBe(0);

        // the up/down buttons are shown but cannot be pressed in another language (they can in the main one)
        $moves = fn (string $html): array => preg_match_all('/<button\b[^>]*wire:click="move\([^>]*>/', $html, $m) > 0 ? $m[0] : [];
        $disabled = fn (string $tag): bool => preg_match('/\sdisabled(="[^"]*")?(?=[\s>\/])/', $tag) === 1;
        expect(array_map($disabled, $moves($page->html())))->toBe([true, true, true, true])
            ->and(array_map($disabled, $moves(Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])->html())))->toBe([false, false, false, false]);

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

it('types the texts of a right-to-left language right to left', function (): void {
    $manager = ftMember();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        ftLanguages('ar');
        $form = ftForm();

        Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid, 'locale' => 'ar'])
            ->assertSet('locale', 'ar')
            ->assertSee('wire:model.blur="name" maxlength="200" dir="rtl" lang="ar"', false)
            ->assertSee('dir="rtl" lang="ar"', false)
            ->assertDontSee('dir="ltr"', false);
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
    'a file kind' => ['fields.0.accept', 'img'],
    'how many files' => ['fields.0.max_files', 2],
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

it('refuses a translation save once the member has lost forms.manage, and changes nothing', function (): void {
    $manager = ftMember();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        ftLanguages('en');
        $form = ftForm();

        $page = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid, 'locale' => 'en'])
            ->set('name', 'Changed')
            ->set('fields.1.label', 'Material')
            ->set('fields.1.choices', 'Stainless 304');
        // the editor was opened while the member could change forms; the permission goes before «Lưu»
        $manager->roles()->firstOrFail()->revokePermissionTo('forms.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        auth()->setUser($manager->fresh());
        $page->call('save')
            ->assertSet('toastMsg', __('form_builder.toast_cannot'))
            ->assertSet('toastTone', 'error')
            // what was typed stays to copy or save later
            ->assertSet('name', 'Changed');

        $form->refresh();
        $material = FormField::query()->where('form_id', $form->id)->where('key', 'material')->firstOrFail();
        expect($form->name)->toEqual(['vi' => 'Yêu cầu báo giá', 'en' => 'Quote request'])
            ->and($material->label)->toEqual(['vi' => 'Vật liệu'])
            ->and($material->options['choices'][0]['label'])->toEqual(['vi' => 'Inox 304']);
    });
});

it('keeps each translated choice on its own choice when the main language reorders or removes choices meanwhile', function (): void {
    $manager = ftMember();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        ftLanguages('en');
        $form = ftForm();

        $english = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid, 'locale' => 'en'])
            ->set('fields.1.choices', "Stainless 304\nAluminium\nCopper");
        // meanwhile the Vietnamese editor puts «Đồng» first, drops «Nhôm» and adds «Thép»
        Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])
            ->set('fields.1.choices', "Đồng\nInox 304\nThép")->call('save')->assertSet('toastMsg', __('form_builder.toast_saved'));

        // «Aluminium» was typed for «Nhôm», which is gone: saved, and the notice says one line was left out
        $english->call('save')->assertSet('toastMsg', trans_choice('form_builder.toast_saved_dropped', 1, ['n' => 1, 'lang' => LangBar::name('vi')]))
            ->assertSet('toastTone', 'info')
            // the box now follows the stored choices, so the next save stays right too
            ->assertSet('fields.1.choices', "Copper\nStainless 304")
            ->assertSet('choiceValues.material', ['dong', 'inox', 'thep']);
        expect($english->instance()->changeCount())->toBe(0);

        $choices = FormField::query()->where('form_id', $form->id)->where('key', 'material')->firstOrFail()->options['choices'];
        expect($choices)->toEqual([
            ['value' => 'dong', 'label' => ['vi' => 'Đồng', 'en' => 'Copper']],
            ['value' => 'inox', 'label' => ['vi' => 'Inox 304', 'en' => 'Stainless 304']],
            ['value' => 'thep', 'label' => ['vi' => 'Thép']],
        ]);

        $english->set('fields.1.choices', "Copper\nStainless 304\nSteel")->call('save')->assertSet('toastMsg', __('form_builder.toast_saved'));
        expect(array_column(FormField::query()->where('form_id', $form->id)->where('key', 'material')->firstOrFail()->options['choices'], 'label'))
            ->toEqual([['vi' => 'Đồng', 'en' => 'Copper'], ['vi' => 'Inox 304', 'en' => 'Stainless 304'], ['vi' => 'Thép', 'en' => 'Steel']]);
    });
});

it('never puts a line on a later choice that took a removed choice\'s value again', function (): void {
    $manager = ftMember();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        ftLanguages('en');
        $form = ftForm();

        $english = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid, 'locale' => 'en'])
            ->set('fields.1.choices', "Stainless 304\nAluminium\nCopper");
        // meanwhile the Vietnamese editor saves twice: first «Nhôm» goes, then «Nhóm» comes, whose value is «nhom» again
        Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])
            ->set('fields.1.choices', "Inox 304\nĐồng")->call('save')->assertSet('toastMsg', __('form_builder.toast_saved'));
        Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])
            ->set('fields.1.choices', "Inox 304\nĐồng\nNhóm")->call('save')->assertSet('toastMsg', __('form_builder.toast_saved'));
        $material = fn (): array => FormField::query()->where('form_id', $form->id)->where('key', 'material')->firstOrFail()->options['choices'];
        expect(array_column($material(), 'value'))->toBe(['inox', 'dong', 'nhom']);

        $english->call('save')
            ->assertSet('toastMsg', trans_choice('form_builder.toast_saved_dropped', 1, ['n' => 1, 'lang' => LangBar::name('vi')]))
            ->assertSet('toastTone', 'info')
            ->assertSet('fields.1.choices', "Stainless 304\nCopper")
            ->assertSet('choiceValues.material', ['inox', 'dong', 'nhom'])
            ->assertSet('choiceSources.material', ['Inox 304', 'Đồng', 'Nhóm']);
        expect($material())->toEqual([
            ['value' => 'inox', 'label' => ['vi' => 'Inox 304', 'en' => 'Stainless 304']],
            ['value' => 'dong', 'label' => ['vi' => 'Đồng', 'en' => 'Copper']],
            ['value' => 'nhom', 'label' => ['vi' => 'Nhóm']],
        ]);

        // from the refreshed box the new choice takes its own line
        $english->set('fields.1.choices', "Stainless 304\nCopper\nGroup")->call('save')->assertSet('toastMsg', __('form_builder.toast_saved'));
        expect($material()[2]['label'])->toEqual(['vi' => 'Nhóm', 'en' => 'Group']);
    });
});

it('skips a field the main language removed while another language was open', function (): void {
    $manager = ftMember();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        ftLanguages('en');
        $form = ftForm();

        $english = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid, 'locale' => 'en'])
            ->set('name', 'Request a quote')
            ->set('fields.1.label', 'Material');
        FormField::query()->where('form_id', $form->id)->where('key', 'material')->delete();

        $english->call('save')->assertSet('toastMsg', __('form_builder.toast_saved'))
            ->assertSet('fields', fn (array $fields): bool => array_column($fields, 'key') === ['name']);
        expect($form->refresh()->name)->toEqual(['vi' => 'Yêu cầu báo giá', 'en' => 'Request a quote'])
            ->and(FormField::query()->where('form_id', $form->id)->orderBy('sort_order')->pluck('key')->all())->toBe(['name', 'consent']);
    });
});

it('never opens another site\'s form in another language', function (): void {
    $manager = ftMember();
    $other = app(Tenancy::class)->enter(2, fn (): Form => ftForm());
    $own = app(Tenancy::class)->enter(1, function (): Form {
        ftLanguages('en');

        return ftForm();
    });

    // the same address opens the site's own form, so the 404 below is the other site's form
    $this->actingAs($manager)->get('http://a.test/admin/form-builder/'.$own->ulid.'/edit?locale=en')->assertOk()->assertSee('Quote request');
    $this->actingAs($manager)->get('http://a.test/admin/form-builder/'.$other->ulid.'/edit?locale=en')->assertNotFound();
    app(Tenancy::class)->enter(2, fn () => expect(Form::query()->find($other->id)?->name)->toEqual(['vi' => 'Yêu cầu báo giá', 'en' => 'Quote request']));
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

it('counts the lines typed for a choice field the main language removed meanwhile', function (): void {
    $manager = ftMember();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        ftLanguages('en');
        $form = ftForm();

        $english = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid, 'locale' => 'en'])
            ->set('fields.1.choices', 'Stainless 304');
        FormField::query()->where('form_id', $form->id)->where('key', 'material')->delete();

        $english->call('save')
            ->assertSet('toastMsg', trans_choice('form_builder.toast_saved_dropped', 1, ['n' => 1, 'lang' => LangBar::name('vi')]))
            ->assertSet('toastTone', 'info');
    });
});

it('counts the lines typed for a field the main language turned into a kind without choices', function (): void {
    $manager = ftMember();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        ftLanguages('en');
        $form = ftForm();

        $english = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid, 'locale' => 'en'])
            ->set('fields.1.choices', "Stainless 304\nCopper");
        FormField::query()->where('form_id', $form->id)->where('key', 'material')->update(['type' => 'text']);

        $english->call('save')
            ->assertSet('toastMsg', trans_choice('form_builder.toast_saved_dropped', 2, ['n' => 2, 'lang' => LangBar::name('vi')]))
            ->assertSet('toastTone', 'info');
    });
});

it('keeps the dropped-lines notice until it is closed, and lets other notices hide by themselves', function (): void {
    $manager = ftMember();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        ftLanguages('en');
        $form = ftForm();

        $english = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid, 'locale' => 'en'])
            ->set('fields.1.choices', 'Stainless 304');
        FormField::query()->where('form_id', $form->id)->where('key', 'material')->delete();

        // the info toast's own opening tag (its x-data holds the timer, or does not)
        $html = $english->call('save')->assertSet('toastTone', 'info')->html();
        $at = strpos($html, 'data-tone="info"');
        expect($at)->not->toBeFalse();
        $start = (int) strrpos(substr($html, 0, (int) $at), '<div');
        $notice = substr($html, $start, (int) strpos($html, '>', (int) $at) - $start);
        expect($notice)->toContain('data-tone="info"')->not->toContain('arm(');
        // a plain saved message still hides by itself
        $english->set('name', 'Another name')->call('save')->assertSet('toastTone', 'success')->assertSeeHtml('arm(');
    });
});

it('keeps both choices on a main-language save that leaves them untouched when two choices show the same words', function (): void {
    $manager = ftMember();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        SiteLocale::query()->update(['is_default' => false]);
        SiteLocale::query()->updateOrCreate(['locale' => 'ja'], ['is_default' => true, 'enabled' => true, 'is_public' => true]);
        app(SiteLanguages::class)->forget();
        $form = Form::query()->create(['key' => 'steel', 'name' => ['ja' => 'Quote'], 'status' => 'active']);
        // a has Japanese words, b only English ones: in Japanese both read «Steel»
        $form->fields()->create(['key' => 'material', 'type' => 'select', 'label' => ['ja' => 'Material'], 'is_required' => false, 'sort_order' => 1,
            'options' => ['choices' => [['value' => 'a', 'label' => ['ja' => 'Steel']], ['value' => 'b', 'label' => ['en' => 'Steel']]]]]);

        $page = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])
            ->assertSet('locale', 'ja')
            ->assertSet('fields.0.choices', "Steel\nSteel");
        expect($page->instance()->changeCount())->toBe(0);
        // only the form's name changes; the choices box is saved as it was shown
        $page->set('name', 'Quote request');
        expect($page->instance()->changeCount())->toBe(1);
        $page->call('save')->assertSet('toastMsg', __('form_builder.toast_saved'));
        expect($page->instance()->changeCount())->toBe(0);

        expect(Form::query()->whereKey($form->id)->value('name'))->toEqual(['ja' => 'Quote request'])
            ->and(FormField::query()->where('form_id', $form->id)->where('key', 'material')->value('options')['choices'])->toEqual([
                ['value' => 'a', 'label' => ['ja' => 'Steel']],
                ['value' => 'b', 'label' => ['en' => 'Steel', 'ja' => 'Steel']],
            ]);
    });
});

it('stores a line typed twice once when no other choice shows those words', function (): void {
    $manager = ftMember();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $form = ftForm();

        Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])
            ->set('fields.1.choices', "Inox 304\nNhôm\nĐồng\nĐồng\nGang\nGang")
            ->call('save')->assertSet('toastMsg', __('form_builder.toast_saved'));

        expect(FormField::query()->where('form_id', $form->id)->where('key', 'material')->value('options')['choices'])->toEqual([
            ['value' => 'inox', 'label' => ['vi' => 'Inox 304']],
            ['value' => 'nhom', 'label' => ['vi' => 'Nhôm']],
            ['value' => 'dong', 'label' => ['vi' => 'Đồng']],
            ['value' => 'gang', 'label' => ['vi' => 'Gang']],
        ]);
    });
});
