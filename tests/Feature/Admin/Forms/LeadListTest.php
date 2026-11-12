<?php

use App\Modules\Admin\Livewire\Forms\Inbox;
use App\Modules\Admin\Support\Leads\LeadListQuery;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

function otherSiteLead(string $name): FormSubmission
{
    return app(Tenancy::class)->enter(2, function () use ($name): FormSubmission {
        $form = Form::query()->create(['key' => 'contact', 'name' => ['vi' => 'Liên hệ'], 'status' => 'active']);

        return FormSubmission::query()->create(['form_id' => $form->id, 'data' => ['message' => 'x'], 'name' => $name, 'status' => 'new']);
    });
}

function leadStatusOn(int $site, string $ulid): ?string
{
    $status = DB::connection('pgsql_admin')->table('form_submissions')->where('site_id', $site)->where('ulid', $ulid)->value('status');

    return is_string($status) ? $status : null;
}

it('pages by a cursor of time and code, never a raw numeric id', function (): void {
    $manager = leadManager();
    foreach (range(1, 18) as $i) {
        listLead('contact', ['name' => sprintf('Khách số %02d', $i), 'created_at' => now()->subMinutes(100 - $i)]);
    }
    $oldest = app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->where('name', 'Khách số 01')->firstOrFail());

    app(Tenancy::class)->enter(1, function () use ($manager, $oldest): void {
        $page = Livewire::actingAs($manager)->test(Inbox::class)->call('loadMore');
        $until = $page->get('until');
        expect($until)->toBeString()->toEndWith('|'.$oldest->ulid)->not->toEndWith('|'.$oldest->id);
        $page->assertSee('Khách số 01');
    });
});

it('leaves a lead alone on undo when a colleague changed it after the action', function (): void {
    $manager = leadManager();
    $a = listLead('contact', ['name' => 'Đặng Thu Hà', 'status' => 'new']);
    $b = listLead('contact', ['name' => 'Bùi Quốc Việt', 'status' => 'new']);

    app(Tenancy::class)->enter(1, function () use ($manager, $a, $b): void {
        $page = Livewire::actingAs($manager)->test(Inbox::class)
            ->call('toggleSelect', [$a->ulid, $b->ulid])
            ->call('bulkDone');
        expect([leadStatus($a), leadStatus($b)])->toBe(['archived', 'archived']);

        FormSubmission::query()->whereKey($a->id)->update(['status' => 'spam']);
        $page->call('undoLast')->assertSee(__('leads.undo_partial', ['count' => 1, 'left' => 1]));
        expect([leadStatus($a), leadStatus($b)])->toBe(['spam', 'new']);
    });
});

it('says the undo did nothing when a colleague changed every lead after the action', function (): void {
    $manager = leadManager();
    $a = listLead('contact', ['name' => 'Đặng Thu Hà', 'status' => 'new']);

    app(Tenancy::class)->enter(1, function () use ($manager, $a): void {
        $page = Livewire::actingAs($manager)->test(Inbox::class)
            ->call('toggleSelect', [$a->ulid])
            ->call('bulkDone');

        FormSubmission::query()->whereKey($a->id)->update(['status' => 'spam']);
        $page->call('undoLast')
            ->assertSee(__('leads.undo_changed'))
            ->assertDontSee(__('leads.toast_undone'));
        expect(leadStatus($a))->toBe('spam');
    });
});

it('pages leads sent in the same second by their code, none twice and none skipped', function (): void {
    $manager = leadManager();
    $at = now()->subHour()->startOfSecond();
    foreach (range(1, 18) as $i) {
        listLead('contact', ['name' => sprintf('Cùng lúc %02d', $i), 'created_at' => $at]);
    }

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $page = Livewire::actingAs($manager)->test(Inbox::class);
        $first = preg_match_all('/Cùng lúc \d{2}/', $page->html(), $m) ? array_unique($m[0]) : [];
        expect($first)->toHaveCount(15);

        $page->call('loadMore')->assertDontSee(__('leads.load_more'));
        $all = preg_match_all('/Cùng lúc \d{2}/', $page->html(), $m) ? array_values(array_unique($m[0])) : [];
        sort($all);
        expect($all)->toBe(array_map(fn (int $i): string => sprintf('Cùng lúc %02d', $i), range(1, 18)));
    });
});

it('never falls back to the whole list when a form filter names no known form', function (): void {
    $manager = leadManager();
    listLead('contact', ['name' => 'Lê Hoàng Bảo']);
    $foreign = app(Tenancy::class)->enter(2, fn () => Form::query()->create(['key' => 'contact', 'name' => ['vi' => 'Khác'], 'status' => 'active'])->ulid);

    app(Tenancy::class)->enter(1, function () use ($manager, $foreign): void {
        $page = Livewire::actingAs($manager)->test(Inbox::class)
            ->set('formF', [$foreign, '01ARZ3NDEKTSV4RRFFQ69G5FAV'])
            ->assertDontSee('Lê Hoàng Bảo');
        // the filter stays on, so the next request still matches nothing and "clear filters" stays offered
        expect($page->get('formF'))->toHaveCount(2);
        $page->call('clearSelection')->assertDontSee('Lê Hoàng Bảo');
        $page->call('clearAllFilters')->assertSee('Lê Hoàng Bảo');
    });
});

it('counts a time range to the end of its last day in the site zone', function (): void {
    $manager = leadManager();
    // the site zone is Asia/Ho_Chi_Minh (UTC+7)
    listLead('contact', ['name' => 'Trong ngày cuối', 'created_at' => '2026-07-10 16:30:00']);   // 23:30 on 10/07 local
    listLead('contact', ['name' => 'Sang ngày sau', 'created_at' => '2026-07-10 17:30:00']);     // 00:30 on 11/07 local

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->set('from', '2026-07-10')->set('to', '2026-07-10')
            ->assertSee('Trong ngày cuối')->assertDontSee('Sang ngày sau');
    });
});

it('adds the last day in the site zone before turning to UTC across a daylight-saving change', function (): void {
    $zone = 'America/New_York';
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('general', 'timezone', ['tz' => $zone]));
    app(SiteTime::class)->forget();
    $manager = leadManager();
    // 2026-11-01 is 25 hours long in New York: its end is 2026-11-02 05:00 UTC
    listLead('contact', ['name' => 'Cuối ngày đổi giờ', 'created_at' => '2026-11-02 04:30:00']);
    listLead('contact', ['name' => 'Đã sang ngày sau', 'created_at' => '2026-11-02 05:30:00']);

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->set('from', '2026-11-01')->set('to', '2026-11-01')
            ->assertSee('Cuối ngày đổi giờ')->assertDontSee('Đã sang ngày sau');
    });
});

it('puts the address and the subject into the mail link encoded, so no header can be added', function (): void {
    $manager = leadManager();
    $lead = listLead('contact', ['name' => 'Khách thư', 'email' => 'a@b.test?bcc=x@y.test&cc=z']);

    app(Tenancy::class)->enter(1, function () use ($manager, $lead): void {
        $html = Livewire::actingAs($manager)->test(Inbox::class, ['ulid' => $lead->ulid])->html();
        expect($html)->toContain('mailto:a@b.test%3Fbcc%3Dx@y.test%26cc%3Dz?subject=')
            ->not->toContain('mailto:a@b.test?bcc');
    });
});

it('changes nothing of another site\'s lead from the ticks or the status buttons, and the undo cannot be set from the page', function (): void {
    $manager = leadManager();
    $mine = listLead('contact', ['name' => 'Của tôi', 'status' => 'new']);
    $theirs = otherSiteLead('Của người khác');

    app(Tenancy::class)->enter(1, function () use ($manager, $mine, $theirs): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->call('toggleSelect', [$mine->ulid, $theirs->ulid])
            ->call('bulkSpam')
            ->assertOk()
            ->assertSee(__('leads.bulk_done_spam', ['count' => 1]))
            ->call('setStatus', $theirs->ulid, 'archived')
            ->assertSee(__('leads.gone'))
            ->assertDontSee('Của người khác');
        expect(leadStatus($mine))->toBe('spam');

        // the page cannot hand the undo another website's lead: the undo is filled only by the server
        expect(fn () => Livewire::actingAs($manager)->test(Inbox::class)
            ->set('undoOp', ['set' => 'new', 'items' => [$theirs->ulid => 'archived']]))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });
    expect(leadStatusOn(2, $theirs->ulid))->toBe('new');
});

it('refuses every write once the plan loses the contacts module', function (): void {
    $manager = leadManager();
    $lead = listLead('contact', ['name' => 'Tạ Minh Khôi', 'status' => 'new']);

    app(Tenancy::class)->enter(1, function () use ($manager, $lead): void {
        $pages = [];
        foreach (range(1, 3) as $i) {
            $pages[] = Livewire::actingAs($manager)->test(Inbox::class)->call('toggleSelect', [$lead->ulid]);
        }
        DB::connection('pgsql_admin')->table('site_modules')->where('site_id', 1)->delete();
        DB::connection('pgsql_admin')->table('sites')->where('id', 1)->update(['site_type_id' => null]);
        app()->forgetInstance(Entitlement::class);

        $pages[0]->call('setStatus', $lead->ulid, 'spam')->assertForbidden();
        $pages[1]->call('bulkDone')->assertForbidden();
        $pages[2]->call('toggleSelect', [$lead->ulid])->assertForbidden();
        expect(leadStatus($lead))->toBe('new');
    });
});

it('reads the search text literally: a percent sign or an underscore is no wildcard', function (): void {
    $manager = leadManager();
    listLead('contact', ['name' => 'Giảm 50% hôm nay']);
    listLead('contact', ['name' => 'Giảm 505 hôm nay']);
    listLead('contact', ['name' => 'Mã a_b']);
    listLead('contact', ['name' => 'Mã axb']);

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->set('search', '50%')
            ->assertSee('Giảm 50% hôm nay')->assertDontSee('Giảm 505 hôm nay')
            ->set('search', 'a_b')
            ->assertSee('Mã a_b')->assertDontSee('Mã axb');
    });
});

it('asks the read permission on every screen-state call, not only on render', function (): void {
    $author = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($author, 'author', ['entries.view']));

    app(Tenancy::class)->enter(1, function () use ($author): void {
        foreach ([['closeLead', []], ['removeForm', ['x']], ['resetFilterGroup', ['form']], ['clearAllFilters', []], ['clearSelection', []], ['closeToast', [1]]] as [$method, $args]) {
            Livewire::actingAs($author)->test(Inbox::class)->call($method, ...$args)->assertForbidden();
        }
    });
});

it('prints no per-form numbers when the site is past the cap, even with a form ticked', function (): void {
    $lead = listLead('contact', ['name' => 'Một']);
    DB::connection('pgsql_admin')->statement(
        "insert into form_submissions (site_id, ulid, form_id, data, status, is_spam, created_at, updated_at)
         select 1, left(md5(g::text), 26), ?, '{}', 'new', false, now(), now() from generate_series(1, ?) g",
        [$lead->form_id, LeadListQuery::CAP + 1],
    );

    app(Tenancy::class)->enter(1, function () use ($lead): void {
        $query = new LeadListQuery('', [$lead->form_id], '', '', '', app(SiteTime::class));
        // the ticked form alone looks small to the caller; the site as a whole is past the cap
        expect($query->formCounts('all', [$lead->form_id], 1))->toBeNull();
    });
});
