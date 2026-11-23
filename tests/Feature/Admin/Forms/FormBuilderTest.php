<?php

use App\Modules\Admin\Livewire\Forms\FormEditor;
use App\Modules\Admin\Livewire\Forms\FormList;
use App\Modules\Admin\Livewire\Forms\Inbox;
use App\Modules\Forms\Actions\EnsureContactForm;
use App\Modules\Forms\Actions\SaveForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormField;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Support\FormDraft;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/*
 * «Biểu mẫu»: the site's forms — the list with its switch, and the editor (fields, thanks, emails).
 * Gated by the plan (contacts module), then forms.manage through FormPolicy, on every request and
 * write; forms are RLS-isolated per site.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

/**
 * A form of $site with a name and an email field, then the consent box.
 */
function seedBuilderForm(int $site, string $key, string $name, string $status = 'active', array $emails = []): Form
{
    return app(Tenancy::class)->enter($site, function () use ($key, $name, $status, $emails): Form {
        $form = Form::query()->create(['key' => $key, 'name' => ['vi' => $name, 'en' => $name.' EN'], 'status' => $status, 'notify_emails' => $emails]);
        $form->fields()->create(['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Họ tên', 'en' => 'Name'], 'is_required' => true, 'sort_order' => 1]);
        $form->fields()->create(['key' => 'email', 'type' => 'email', 'label' => ['vi' => 'Email'], 'is_required' => false, 'sort_order' => 2]);
        $form->fields()->create(['key' => 'consent', 'type' => 'checkbox', 'label' => ['vi' => 'Tôi đồng ý'], 'is_required' => true, 'sort_order' => 3]);

        return $form;
    });
}

function builderManager(int $site = 1): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, 'admin', ['forms.view', 'forms.manage']));

    return $user->fresh();
}

/** @return list<string> */
function fieldKeys(Form $form): array
{
    return FormField::query()->where('form_id', $form->id)->orderBy('sort_order')->pluck('key')->all();
}

it('lists the site forms with their lead counts, recipients and the system mark for a manager', function (): void {
    $manager = builderManager();
    $contact = seedBuilderForm(1, 'contact', 'Liên hệ', emails: ['a@congty.vn', 'b@congty.vn']);
    seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá', 'disabled');
    app(Tenancy::class)->enter(1, function () use ($contact): void {
        foreach (['new', 'read', 'spam'] as $status) {
            FormSubmission::query()->create(['form_id' => $contact->id, 'data' => [], 'status' => $status, 'is_spam' => $status === 'spam']);
        }
    });

    $this->actingAs($manager)->get('http://a.test/admin/form-builder')
        ->assertOk()
        ->assertSee(__('form_builder.title'))
        ->assertSee('Liên hệ')
        ->assertSee('Yêu cầu báo giá')
        ->assertSee(trans_choice('form_builder.leads_n', 2, ['n' => 2]))
        ->assertSee(__('form_builder.mail_more', ['first' => 'a@congty.vn', 'n' => 1]))
        ->assertSee(__('form_builder.mail_none'))
        ->assertSee(__('form_builder.system'));
});

it('shows a member who can only read leads the no-permission card, with the way to the leads, and no form', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['forms.view']));
    $form = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá');

    foreach (['http://a.test/admin/form-builder', 'http://a.test/admin/form-builder/'.$form->ulid.'/edit', 'http://a.test/admin/form-builder/create/quote'] as $url) {
        $this->actingAs($viewer)->get($url)
            ->assertOk()
            ->assertSee('data-state="noperm"', false)
            ->assertSee(__('form_builder.open_contacts'))
            ->assertDontSee('Yêu cầu báo giá');
    }
});

it('shows the plan card when the site plan has no contacts module', function (): void {
    DB::connection('pgsql_admin')->table('site_modules')->where('site_id', 1)->delete();
    $manager = builderManager();
    seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá');

    $this->actingAs($manager)->get('http://a.test/admin/form-builder')
        ->assertOk()
        ->assertSee('data-state="plan"', false)
        ->assertDontSee('Yêu cầu báo giá');
});

it('never lists, opens or switches a form of another site', function (): void {
    $manager = builderManager(1);
    seedBuilderForm(1, 'bao-gia', 'Alpha báo giá');
    $other = seedBuilderForm(2, 'tu-van', 'Bravo tư vấn');

    $this->actingAs($manager)->get('http://a.test/admin/form-builder')->assertOk()
        ->assertSee('Alpha báo giá')->assertDontSee('Bravo tư vấn');
    $this->actingAs($manager)->get('http://a.test/admin/form-builder/'.$other->ulid.'/edit')->assertNotFound();

    app(Tenancy::class)->enter(1, function () use ($manager, $other): void {
        Livewire::actingAs($manager)->test(FormList::class)
            ->call('setActive', $other->ulid, false)
            ->assertSet('toastTone', 'error');
    });
    app(Tenancy::class)->enter(2, fn () => expect(Form::query()->find($other->id)?->status)->toBe('active'));
});

it('switches a form off and back on with undo for a manager', function (): void {
    $manager = builderManager();
    $form = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá');

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        $page = Livewire::actingAs($manager)->test(FormList::class)
            ->call('setActive', $form->ulid, false)
            ->assertSet('toastMsg', __('form_builder.toast_off', ['name' => 'Yêu cầu báo giá']));
        expect(Form::query()->find($form->id)?->status)->toBe('disabled');

        $page->call('undoLast');
        expect(Form::query()->find($form->id)?->status)->toBe('active');
    });
});

it('refuses the switch to a member without forms.manage', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['forms.view']));
    $form = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá');

    app(Tenancy::class)->enter(1, function () use ($viewer, $form): void {
        Livewire::actingAs($viewer->fresh())->test(FormList::class)
            ->call('setActive', $form->ulid, false)
            ->assertSet('toastTone', 'error');
        expect(Form::query()->find($form->id)?->status)->toBe('active');
    });
});

it('filters by group, by a search that ignores Vietnamese marks, and by leads received', function (): void {
    $manager = builderManager();
    $quote = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá');
    seedBuilderForm(1, 'tu-van', 'Đăng ký tư vấn', 'disabled');
    $advice = seedBuilderForm(1, 'hoi-dap', 'Hỏi đáp kỹ thuật');
    app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->create(['form_id' => $quote->id, 'data' => [], 'status' => 'new']));
    // a form whose only lead is in the Trash has received none, as «Khách liên hệ» shows it
    app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->create(['form_id' => $advice->id, 'data' => [], 'status' => 'read'])
        ->forceFill(['deleted_at' => now()])->save());

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        Livewire::actingAs($manager)->test(FormList::class)
            ->set('has', ['no'])->assertSee('Hỏi đáp kỹ thuật')
            ->set('has', ['yes'])->assertDontSee('Hỏi đáp kỹ thuật')
            ->set('has', [])
            ->set('search', 'bao gia')->assertSee('Yêu cầu báo giá')->assertDontSee('Đăng ký tư vấn')
            ->set('search', 'dang ky')->assertSee('Đăng ký tư vấn')->assertDontSee('Yêu cầu báo giá')
            ->set('search', '')->set('tab', 'off')->assertSee('Đăng ký tư vấn')->assertDontSee('Yêu cầu báo giá')
            ->set('tab', 'all')->set('has', ['yes'])->assertSee('Yêu cầu báo giá')->assertDontSee('Đăng ký tư vấn')
            ->set('has', ['no'])->assertSee('Đăng ký tư vấn')->assertDontSee('Yêu cầu báo giá');
    });
});

it('lists forms on a flat query budget (lead counts in one statement)', function (): void {
    $manager = builderManager();
    foreach (range(1, 8) as $i) {
        $form = seedBuilderForm(1, 'form-'.$i, 'Biểu mẫu '.$i, $i % 2 === 0 ? 'active' : 'disabled', ['x'.$i.'@congty.vn']);
        app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->create(['form_id' => $form->id, 'data' => [], 'status' => 'new']));
    }

    $count = countQueries(fn () => $this->actingAs($manager)->get('http://a.test/admin/form-builder')->assertOk()->assertSee('Biểu mẫu 8'));

    expect($count)->toBeLessThanOrEqual(20);
});

it('creates a form from a starting point, with the consent box last and a key from its name', function (): void {
    $manager = builderManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        Livewire::actingAs($manager)->test(FormEditor::class, ['purpose' => 'quote'])
            ->assertSet('name', __('form_builder.purpose.quote', [], 'vi'))
            ->assertSet('isNew', true)
            ->set('name', 'Báo giá inox')
            ->call('save')
            ->assertSet('toastMsg', __('form_builder.toast_saved'));

        $form = Form::query()->where('key', 'bao-gia-inox')->firstOrFail();
        expect($form->name['vi'])->toBe('Báo giá inox')
            ->and($form->status)->toBe('active')
            ->and($form->settings['edited'] ?? null)->toBeTrue()
            ->and(fieldKeys($form))->toBe(['name', 'phone', 'email', 'company', 'message', 'consent']);
    });
});

it('refuses an unknown starting point', function (): void {
    $manager = builderManager();

    $this->actingAs($manager)->get('http://a.test/admin/form-builder/create/nope')->assertNotFound();
});

it('saves renamed, reordered, removed and new fields in place and keeps the consent box last', function (): void {
    $manager = builderManager();
    $form = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá');
    $nameId = app(Tenancy::class)->enter(1, fn () => FormField::query()->where('form_id', $form->id)->where('key', 'name')->value('id'));

    app(Tenancy::class)->enter(1, function () use ($manager, $form, $nameId): void {
        $page = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])
            ->assertSet('saved.name', 'Yêu cầu báo giá');
        expect($page->instance()->changeCount())->toBe(0);

        $page->set('fields.0.label', 'Tên của bạn')
            ->call('move', 'email', -1)
            ->call('addField', 'select');
        $key = $page->get('openKey');
        $i = count($page->get('fields')) - 1;
        $page->set("fields.{$i}.label", 'Vật liệu')
            ->set("fields.{$i}.choices", "Inox 304\nNhôm\n\nInox 304")
            ->call('removeField', 'email');
        // name, new field, removed field, order = 3 (the order change is between the kept fields only)
        expect($page->instance()->changeCount())->toBe(3);
        $page->call('save')->assertSet('toastMsg', __('form_builder.toast_saved'));

        $fields = FormField::query()->where('form_id', $form->id)->orderBy('sort_order')->get()->keyBy('key');
        expect(fieldKeys($form))->toBe(['name', $key, 'consent'])
            ->and($fields['name']->id)->toBe($nameId)
            ->and($fields['name']->label)->toEqual(['vi' => 'Tên của bạn', 'en' => 'Name'])
            ->and(array_column($fields[$key]->options['choices'], 'value'))->toBe(['inox-304', 'nhom'])
            ->and($fields['consent']->is_required)->toBeTrue();
        expect($page->instance()->changeCount())->toBe(0);
    });
});

it('puts a removed field back with undo', function (): void {
    $manager = builderManager();
    $form = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá');

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        $page = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])
            ->call('removeField', 'name')
            ->assertSet('toastMsg', __('form_builder.toast_field_removed', ['label' => 'Họ tên']))
            ->call('undoLast');
        expect(array_column($page->get('fields'), 'key'))->toBe(['name', 'email'])
            ->and($page->instance()->changeCount())->toBe(0);
    });
});

it('keeps an empty name or field label from being saved and shows where', function (): void {
    $manager = builderManager();
    $form = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá');

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])
            ->set('name', '  ')
            ->assertSee(__('form_builder.name_error'))
            ->set('fields.1.label', '')
            ->call('save')
            ->assertSet('toastMsg', '')
            ->assertSee(__('form_builder.field_label_error'))
            ->assertDispatched('bm-focus', id: 'bm-name');

        expect(Form::query()->find($form->id)?->name['vi'])->toBe('Yêu cầu báo giá');
    });
});

it('keeps a select field without choices from being saved', function (): void {
    $manager = builderManager();
    $form = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá');

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        $page = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])->call('addField', 'select');
        $page->set('fields.2.label', 'Vật liệu')->call('save')->assertSee(__('form_builder.choices_error'));

        expect(fieldKeys($form))->toBe(['name', 'email', 'consent']);
    });
});

it('adds lead email recipients, refusing a malformed or repeated one', function (): void {
    $manager = builderManager();
    $form = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá', emails: ['kd@congty.vn']);

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])
            ->set('newEmail', 'not-an-email')->call('addEmail')->assertSet('emailError', __('form_builder.email_invalid'))
            ->set('newEmail', 'KD@congty.vn')->call('addEmail')->assertSet('emailError', __('form_builder.email_dup'))
            ->set('newEmail', 'ban-hang@congty.vn')->call('addEmail')->assertSet('emailError', '')
            ->call('removeEmail', 0)
            ->call('save');

        expect(Form::query()->find($form->id)?->notify_emails)->toBe(['ban-hang@congty.vn']);
    });
});

it('refuses a save from a member without forms.manage', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['forms.view']));
    $form = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá');

    app(Tenancy::class)->enter(1, function () use ($viewer, $form): void {
        Livewire::actingAs($viewer->fresh())->test(FormEditor::class, ['ulid' => $form->ulid])
            ->assertSee('data-state="noperm"', false)
            ->set('name', 'Đổi tên')
            ->call('save');

        expect(Form::query()->find($form->id)?->name['vi'])->toBe('Yêu cầu báo giá');
    });
});

it('refuses a field key, a field kind or the recipients set straight from the page', function (string $property, mixed $value): void {
    $manager = builderManager();
    $form = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá');

    app(Tenancy::class)->enter(1, function () use ($manager, $form, $property, $value): void {
        Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])
            ->set($property, $value)
            ->assertForbidden();
    });
})->with([
    'key' => ['fields.0.key', 'consent'],
    'kind' => ['fields.0.type', 'file'],
    'all fields' => ['fields', []],
    'a row the draft has not' => ['fields.9.label', 'Thêm'],
    'a list as a label' => ['fields.0.label', ['x']],
    'a word as required' => ['fields.0.required', 'yes'],
]);

it('keeps a form with nothing to fill in but tick boxes from being saved', function (): void {
    $manager = builderManager();
    $form = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá');

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        $page = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])
            ->call('removeField', 'name')
            ->call('removeField', 'email')
            ->assertSee(__('form_builder.fields_none'))
            ->call('addField', 'checkbox');
        $page->set('fields.0.label', 'Nhận bản tin')
            ->call('save')
            ->assertSee(__('form_builder.fields_none'))
            ->assertDispatched('bm-focus', id: 'bm-add');

        expect(fieldKeys($form))->toBe(['name', 'email', 'consent']);
        expect(fn () => app(SaveForm::class)->handle($form, [
            'name' => 'X', 'success' => '', 'active' => true, 'emails' => [],
            'fields' => [['key' => 'tick', 'type' => 'checkbox', 'label' => 'Đồng ý nhận tin', 'required' => false, 'choices' => '']],
        ], 'vi'))->toThrow(InvalidArgumentException::class);
    });
});

it('puts the cursor on the message when a full form holds only tick boxes', function (): void {
    $manager = builderManager();
    $form = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá');

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        $page = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])
            ->call('removeField', 'name')
            ->call('removeField', 'email');
        foreach (range(1, FormDraft::MAX_FIELDS) as $i) {
            $page->call('addField', 'checkbox');
        }
        $page->call('save')->assertDispatched('bm-focus', id: 'bm-add-err');
    });
});

it('gives an added email or phone box the key the inbox reads, once', function (): void {
    $manager = builderManager();
    $form = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá');

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        $page = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])
            ->call('addField', 'tel')
            ->call('addField', 'email');
        $keys = array_column($page->get('fields'), 'key');

        expect($keys[2])->toBe('phone')
            ->and($keys[3])->not->toBe('email')
            ->and($keys[3])->toStartWith('email_');
    });
});

it('keeps a removed email box\'s key for its undo when another email box is added', function (): void {
    $manager = builderManager();
    $form = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá');

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        $page = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])
            ->call('removeField', 'email')
            ->call('addField', 'email')
            ->call('undoLast');
        $keys = array_column($page->get('fields'), 'key');

        expect($keys)->toContain('email')
            ->and(count($keys))->toBe(3);
    });
});

it('lets the page change a field\'s kind, required mark and choices', function (): void {
    $manager = builderManager();
    $form = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá');

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid])
            ->set('fields.0.type', 'select')
            ->set('fields.0.choices', "Một\nHai")
            ->set('fields.0.required', false)
            ->set('active', false)
            ->call('save')
            ->assertSet('toastMsg', __('form_builder.toast_saved'));

        $field = FormField::query()->where('form_id', $form->id)->where('key', 'name')->firstOrFail();
        expect($field->type)->toBe('select')
            ->and($field->is_required)->toBeFalse()
            ->and(Form::query()->find($form->id)?->status)->toBe('disabled');
    });
});

it('refuses a new form once the site holds the most forms', function (): void {
    $manager = builderManager();
    app(Tenancy::class)->enter(1, function (): void {
        foreach (range(1, FormDraft::MAX_FORMS) as $i) {
            Form::query()->create(['key' => 'f-'.$i, 'name' => ['vi' => 'F '.$i]]);
        }
    });

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        Livewire::actingAs($manager)->test(FormEditor::class, ['purpose' => 'quote'])
            ->call('save')
            ->assertSet('toastMsg', __('form_builder.forms_max', ['max' => FormDraft::MAX_FORMS]));

        expect(Form::query()->count())->toBe(FormDraft::MAX_FORMS);
    });
});

it('never gives a new form the system contact form key', function (): void {
    $manager = builderManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        Livewire::actingAs($manager)->test(FormEditor::class, ['purpose' => 'custom'])
            ->set('name', 'Contact')
            ->call('save');

        expect(Form::query()->where('key', 'contact')->exists())->toBeFalse()
            ->and(Form::query()->where('key', 'contact-2')->exists())->toBeTrue();
    });
});

it('lets the system form be changed but never deleted', function (): void {
    $manager = builderManager();
    $system = seedBuilderForm(1, 'contact', 'Liên hệ');
    $other = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá');

    app(Tenancy::class)->enter(1, function () use ($manager, $system, $other): void {
        expect(Gate::forUser($manager)->allows('update', $system))->toBeTrue()
            ->and(Gate::forUser($manager)->allows('delete', $system))->toBeFalse()
            ->and(Gate::forUser($manager)->allows('delete', $other))->toBeTrue();
    });
});

it('no longer brings back a default field the site removed from its contact form', function (): void {
    $manager = builderManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $form = app(EnsureContactForm::class)->handle([]);
        $page = Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $form->ulid]);
        $page->call('removeField', 'message')->call('save');

        app(EnsureContactForm::class)->handle([]);

        expect(fieldKeys($form))->not->toContain('message')
            ->and(fieldKeys($form))->toContain('consent');
    });
});

it('tells the leads inbox the contact form is off and lets a manager turn it back on', function (): void {
    $manager = builderManager();
    $form = seedBuilderForm(1, 'contact', 'Liên hệ', 'disabled');

    $this->actingAs($manager)->get('http://a.test/admin/forms')->assertOk()
        ->assertSee('data-state="form-off"', false)
        ->assertSee(__('leads.form_off_band', ['name' => 'Liên hệ']))
        ->assertSee(__('leads.form_on'));

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->call('turnFormOn', $form->ulid)
            ->assertSet('toastMsg', __('leads.form_on_toast', ['name' => 'Liên hệ']))
            ->assertDontSee('data-state="form-off"', false);
        expect(Form::query()->find($form->id)?->status)->toBe('active');
    });
});

it('speaks of the contact form only, whatever form the list is filtered to', function (): void {
    $manager = builderManager();
    seedBuilderForm(1, 'contact', 'Liên hệ', 'disabled');
    $quote = seedBuilderForm(1, 'bao-gia', 'Yêu cầu báo giá', 'disabled');

    $this->actingAs($manager)->get('http://a.test/admin/forms?form[]='.$quote->ulid)->assertOk()
        ->assertSee(__('leads.form_off_band', ['name' => 'Liên hệ']))
        ->assertDontSee(__('leads.form_off_band', ['name' => 'Yêu cầu báo giá']));

    app(Tenancy::class)->enter(1, fn () => Form::query()->where('key', 'contact')->update(['status' => 'active']));
    $this->actingAs($manager)->get('http://a.test/admin/forms?form[]='.$quote->ulid)->assertOk()
        ->assertDontSee('data-state="form-off"', false);
});

it('changes nothing when the form to turn on is not there', function (string $ulid): void {
    $manager = builderManager();
    $form = seedBuilderForm(1, 'contact', 'Liên hệ', 'disabled');

    app(Tenancy::class)->enter(1, function () use ($manager, $form, $ulid): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->call('turnFormOn', $ulid)
            ->assertSet('toastTone', 'error');
        expect(Form::query()->find($form->id)?->status)->toBe('disabled');
    });
})->with(['an unknown ulid' => ['01JZZZZZZZZZZZZZZZZZZZZZZZ'], 'not a ulid' => ['nope']]);

it('shows a member who only reads leads the band without the button, and refuses the turn on', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['forms.view']));
    $form = seedBuilderForm(1, 'contact', 'Liên hệ', 'disabled');

    $this->actingAs($viewer)->get('http://a.test/admin/forms')->assertOk()
        ->assertSee(__('leads.form_on_ask'))
        ->assertDontSee('turnFormOn', false);

    app(Tenancy::class)->enter(1, function () use ($viewer, $form): void {
        Livewire::actingAs($viewer->fresh())->test(Inbox::class)
            ->call('turnFormOn', $form->ulid)
            ->assertForbidden();
        expect(Form::query()->find($form->id)?->status)->toBe('disabled');
    });
});

it('never turns on another site\'s form from the inbox', function (): void {
    $manager = builderManager(1);
    $other = seedBuilderForm(2, 'contact', 'Liên hệ B', 'disabled');

    app(Tenancy::class)->enter(1, function () use ($manager, $other): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->call('turnFormOn', $other->ulid)
            ->assertSet('toastTone', 'error');
    });
    app(Tenancy::class)->enter(2, fn () => expect(Form::query()->find($other->id)?->status)->toBe('disabled'));
});
