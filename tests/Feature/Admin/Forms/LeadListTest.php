<?php

use App\Modules\Admin\Livewire\Forms\Inbox;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * "Khách liên hệ" as a list and the lead beside it: the search finds a name without its diacritics
 * and a phone by its digits; the forms and the time window narrow the list; ticked leads change
 * status together and come back with "Hoàn tác"; the rows load further by a cursor, never by an
 * offset. A member who can only read never changes a lead.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    // both websites' plans include the contacts module (a test that needs it off takes it away)
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

/**
 * @param  array<string, mixed>  $attrs
 */
function listLead(string $formKey, array $attrs = []): FormSubmission
{
    return app(Tenancy::class)->enter(1, function () use ($formKey, $attrs): FormSubmission {
        $form = Form::query()->firstOrCreate(['key' => $formKey], ['name' => ['vi' => $formKey === 'quote' ? 'Yêu cầu báo giá' : 'Liên hệ'], 'status' => 'active']);

        return FormSubmission::query()->create(array_merge([
            'form_id' => $form->id,
            'data' => ['message' => 'Xin báo giá trục thép'],
            'name' => 'Khách',
            'status' => 'new',
        ], $attrs));
    });
}

function leadManager(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'admin', ['forms.view', 'forms.manage']));

    return $user->fresh() ?? $user;
}

// read inside the site the test is already in: entering it again would end its context (and the
// member's permissions) for the calls that follow
function leadStatus(FormSubmission $lead): ?string
{
    $status = FormSubmission::query()->whereKey($lead->id)->value('status');

    return is_string($status) ? $status : null;
}

it('finds a name typed without diacritics, and a phone by its digits', function (): void {
    $manager = leadManager();
    listLead('contact', ['name' => 'Nguyễn Thị Ánh', 'phone' => '0912 345 678']);
    listLead('contact', ['name' => 'Trần Văn Đức', 'phone' => '0988000111']);

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->set('search', 'nguyen thi anh')
            ->assertSee('Nguyễn Thị Ánh')->assertDontSee('Trần Văn Đức')
            ->set('search', '345678')
            ->assertSee('Nguyễn Thị Ánh')->assertDontSee('Trần Văn Đức')
            ->set('search', 'đức')
            ->assertSee('Trần Văn Đức')->assertDontSee('Nguyễn Thị Ánh');
    });
});

it('narrows the list by form and by time, and says how many of how many it found', function (): void {
    $manager = leadManager();
    listLead('contact', ['name' => 'Lê Hoàng Bảo']);
    $quote = listLead('quote', ['name' => 'Phạm Minh Đạt']);
    listLead('contact', ['name' => 'Võ Thành Đông', 'created_at' => now()->subDays(20)]);
    $quoteForm = app(Tenancy::class)->enter(1, fn () => Form::query()->whereKey($quote->form_id)->value('ulid'));

    app(Tenancy::class)->enter(1, function () use ($manager, $quoteForm): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->set('formF', [$quoteForm])
            ->assertSee('Phạm Minh Đạt')->assertDontSee('Lê Hoàng Bảo')
            ->assertSee(__('leads.match_of', ['n' => 1, 'm' => 3]))
            ->call('clearAllFilters')
            ->set('when', '7')
            ->assertSee('Lê Hoàng Bảo')->assertDontSee('Võ Thành Đông')
            ->set('when', 'range')->set('from', now()->subDays(25)->format('Y-m-d'))->set('to', now()->subDays(15)->format('Y-m-d'))
            ->assertSee('Võ Thành Đông')->assertDontSee('Lê Hoàng Bảo');
    });
});

it('changes the ticked leads together, skipping those already there, and undoes it', function (): void {
    $manager = leadManager();
    $a = listLead('contact', ['name' => 'Đặng Thu Hà', 'status' => 'new']);
    $b = listLead('contact', ['name' => 'Bùi Quốc Việt', 'status' => 'archived']);
    $c = listLead('contact', ['name' => 'Hồ Ngọc Ánh', 'status' => 'read']);

    app(Tenancy::class)->enter(1, function () use ($manager, $a, $b, $c): void {
        $page = Livewire::actingAs($manager)->test(Inbox::class)
            ->call('toggleSelect', [$a->ulid, $b->ulid, $c->ulid])
            ->assertSee(__('leads.bulk_archived', ['count' => 2]))
            ->call('bulkDone')
            ->assertSee(__('leads.bulk_done_archived', ['count' => 2]));
        expect([leadStatus($a), leadStatus($b), leadStatus($c)])->toBe(['archived', 'archived', 'archived']);

        $page->call('undoLast')->assertSee(__('leads.toast_undone'));
        expect([leadStatus($a), leadStatus($b), leadStatus($c)])->toBe(['new', 'archived', 'read']);
    });
});

it('moves a lead into spam and back, the toast offering undo', function (): void {
    $manager = leadManager();
    $lead = listLead('contact', ['name' => 'Lý Gia Hân', 'status' => 'read']);

    app(Tenancy::class)->enter(1, function () use ($manager, $lead): void {
        $page = Livewire::actingAs($manager)->test(Inbox::class, ['ulid' => $lead->ulid])
            ->call('setStatus', $lead->ulid, 'spam')
            ->assertSee(__('leads.toast_spam'))
            ->assertSee(__('leads.spam_note'));
        expect(leadStatus($lead))->toBe('spam');

        $page->call('undoLast');
        expect(leadStatus($lead))->toBe('read');
    });
});

it('refuses a status the lead cannot have', function (): void {
    $manager = leadManager();
    $lead = listLead('contact', ['status' => 'new']);

    app(Tenancy::class)->enter(1, function () use ($manager, $lead): void {
        Livewire::actingAs($manager)->test(Inbox::class)->call('setStatus', $lead->ulid, 'deleted')->assertStatus(422);
        expect(leadStatus($lead))->toBe('new');
    });
});

it('never lets a view-only member tick leads or change them in bulk', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['forms.view']));
    $lead = listLead('contact', ['name' => 'Mai Phương Thảo', 'status' => 'new']);

    app(Tenancy::class)->enter(1, function () use ($viewer, $lead): void {
        Livewire::actingAs($viewer->fresh())->test(Inbox::class)
            ->assertSee('Mai Phương Thảo')
            ->assertDontSee(__('leads.select_all'))
            ->set('selected', [$lead->ulid])
            ->call('bulkSpam')
            ->assertForbidden();
        expect(leadStatus($lead))->toBe('new');
    });
});

it('loads further rows down a cursor, newest first', function (): void {
    $manager = leadManager();
    foreach (range(1, 18) as $i) {
        listLead('contact', ['name' => sprintf('Khách số %02d', $i), 'created_at' => now()->subMinutes(100 - $i)]);
    }

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->assertSee('Khách số 18')->assertSee('Khách số 04')->assertDontSee('Khách số 03')
            ->assertSee(__('leads.shown_of', ['n' => 15, 'm' => 18]))
            ->call('loadMore')
            ->assertSee('Khách số 03')->assertSee('Khách số 01')
            ->assertDontSee(__('leads.load_more'));
    });
});

it('flags a lead the automatic filter took for spam, and a blank answer as not filled', function (): void {
    $manager = leadManager();
    $lead = app(Tenancy::class)->enter(1, function (): FormSubmission {
        $form = Form::query()->create(['key' => 'quote', 'name' => ['vi' => 'Yêu cầu báo giá'], 'status' => 'active']);
        $form->fields()->createMany([
            ['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Họ tên'], 'sort_order' => 1],
            ['key' => 'company', 'type' => 'text', 'label' => ['vi' => 'Tên công ty'], 'sort_order' => 2],
        ]);

        return $form->submissions()->create(['data' => ['name' => 'Ông Đỗ Tiến'], 'name' => 'Ông Đỗ Tiến', 'status' => 'read', 'is_spam' => true]);
    });

    app(Tenancy::class)->enter(1, function () use ($manager, $lead): void {
        Livewire::actingAs($manager)->test(Inbox::class, ['ulid' => $lead->ulid])
            ->assertSee(__('leads.flagged_off'))
            ->assertSeeInOrder(['Tên công ty', __('leads.not_filled')])
            ->assertSee(__('leads.no_contact_left'));
    });
});

it('never opens another site\'s lead by its code', function (): void {
    $manager = leadManager();
    listLead('contact', ['name' => 'Lâm Khánh Ngọc']);
    $other = app(Tenancy::class)->enter(2, function (): FormSubmission {
        $form = Form::query()->create(['key' => 'contact', 'name' => ['vi' => 'Liên hệ'], 'status' => 'active']);

        return $form->submissions()->create(['data' => [], 'name' => 'Khách của site khác', 'phone' => '0977111222', 'status' => 'new']);
    });

    $this->actingAs($manager)->get('http://a.test/admin/forms/'.$other->ulid)
        ->assertOk()
        ->assertSee('Lâm Khánh Ngọc')
        ->assertDontSee('Khách của site khác')
        ->assertDontSee('0977111222');
    expect(app(Tenancy::class)->enter(2, fn () => FormSubmission::query()->whereKey($other->id)->value('status')))->toBe('new');
});

it('says so when the site has no lead yet', function (): void {
    $manager = leadManager();

    $this->actingAs($manager)->get('http://a.test/admin/forms')
        ->assertOk()
        ->assertSee('data-state="empty"', false)
        ->assertSee(__('leads.empty_title'));
});

it('shows the plan card, and reads no lead, when the site has no contacts module', function (): void {
    $manager = leadManager();
    listLead('contact', ['name' => 'Tạ Minh Khôi']);
    DB::connection('pgsql_admin')->table('site_modules')->where('site_id', 1)->delete();
    DB::connection('pgsql_admin')->table('sites')->where('id', 1)->update(['site_type_id' => null]);
    app()->forgetInstance(Entitlement::class);

    $this->actingAs($manager)->get('http://a.test/admin/forms')
        ->assertOk()
        ->assertSee('data-state="plan"', false)
        ->assertSee(__('leads.plan_title'))
        ->assertDontSee('Tạ Minh Khôi');
});
