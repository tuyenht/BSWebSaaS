<?php

use App\Modules\Admin\Livewire\Forms\Inbox;
use App\Modules\Admin\Support\Dashboard\DashboardReader;
use App\Modules\Admin\Support\Leads\LeadListQuery;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Support\LeadTrash;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * «Thùng rác» of «Khách liên hệ»: a member who manages leads moves one there with no question and
 * may take it back from the message («Hoàn tác») or from its band («Khôi phục»); it keeps its status,
 * so it comes back exactly as it was. A lead in the Trash is in no other group, count, search, bell
 * link or CSV file; the Trash tab lists only those, last trashed first, each with the days left. A
 * member who only reads leads sees the Trash and changes nothing; another website's lead is never
 * reached. Each move writes one audit row without the visitor's data.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

/** A lead of $site's contact form. @param array<string, mixed> $attrs */
function trashLead(int $site, string $name, array $attrs = []): FormSubmission
{
    return app(Tenancy::class)->enter($site, function () use ($name, $attrs): FormSubmission {
        $form = Form::query()->firstOrCreate(['key' => 'contact'], ['name' => ['vi' => 'Liên hệ'], 'status' => 'active']);

        return FormSubmission::query()->create(array_merge([
            'form_id' => $form->id, 'name' => $name, 'status' => 'new',
            'data' => ['name' => $name, 'message' => 'Xin báo giá trục thép'],
        ], $attrs));
    });
}

/** Put $lead in the Trash since $at, past RLS, as moveToTrash leaves it. */
function trashSince(FormSubmission $lead, Carbon $at): void
{
    DB::connection('pgsql_admin')->table('form_submissions')->where('id', $lead->id)->update(['deleted_at' => $at->utc()->toDateTimeString().'+00']);
}

/** A member of $site holding exactly $permissions. @param list<string> $permissions */
function trashMember(array $permissions, int $site = 1): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, 'trash-'.md5(implode(',', $permissions)), $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

function trashedAt(FormSubmission $lead): ?string
{
    $at = DB::connection('pgsql_admin')->table('form_submissions')->where('id', $lead->id)->value('deleted_at');

    return $at === null ? null : (string) $at;
}

function trashAudits(): array
{
    return DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'like', 'forms.lead%')->orderBy('id')->get()->all();
}

it('moves a lead to the Trash with no question, offers Hoàn tác, keeps its status and audits it without the visitor', function (): void {
    $manager = trashMember(['forms.view', 'forms.manage']);
    $lead = trashLead(1, 'Nguyễn Thị Hoa', ['phone' => '0912 345 678', 'email' => 'hoa@example.test', 'status' => 'read']);
    trashLead(1, 'Trần Văn Bình');

    app(Tenancy::class)->enter(1, function () use ($manager, $lead): void {
        Livewire::actingAs($manager)->test(Inbox::class, ['ulid' => $lead->ulid])
            ->assertSee('moveToTrash(', false)
            ->call('moveToTrash', $lead->ulid)
            ->assertSet('toastMsg', __('leads.toast_trashed'))
            ->assertSet('undoOp', ['items' => [$lead->ulid => 'read'], 'set' => LeadListQuery::TRASH])
            ->assertSet('lead', '')
            ->assertSet('eraseOpen', false)
            ->assertDontSee('Nguyễn Thị Hoa')
            ->assertSee('Trần Văn Bình');
    });

    expect(trashedAt($lead))->not->toBeNull()
        ->and(DB::connection('pgsql_admin')->table('form_submissions')->where('id', $lead->id)->value('status'))->toBe('read');
    $audits = trashAudits();
    expect($audits)->toHaveCount(1)
        ->and($audits[0]->action)->toBe('forms.lead.trashed')
        ->and((int) $audits[0]->actor_id)->toBe($manager->id)
        ->and(json_decode((string) $audits[0]->meta, true))->toBe(['form' => 'contact'])
        ->and(json_encode($audits[0], JSON_UNESCAPED_UNICODE))->not->toContain('Nguyễn', '0912', 'hoa@example.test');
});

it('brings the lead back with Hoàn tác, as it was', function (): void {
    $manager = trashMember(['forms.view', 'forms.manage']);
    $lead = trashLead(1, 'Nguyễn Thị Hoa', ['status' => 'archived']);

    app(Tenancy::class)->enter(1, function () use ($manager, $lead): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->call('moveToTrash', $lead->ulid)
            ->call('undoLast')
            ->assertSet('toastMsg', __('leads.toast_undone'))
            ->assertSet('undoOp', null)
            ->set('tab', 'archived')
            ->assertSee('Nguyễn Thị Hoa');
    });

    expect(trashedAt($lead))->toBeNull()
        ->and(DB::connection('pgsql_admin')->table('form_submissions')->where('id', $lead->id)->value('status'))->toBe('archived')
        ->and(array_column(trashAudits(), 'action'))->toBe(['forms.lead.trashed', 'forms.lead.restored']);
});

it('says so when Hoàn tác finds the lead already out of the Trash', function (): void {
    $manager = trashMember(['forms.view', 'forms.manage']);
    $lead = trashLead(1, 'Nguyễn Thị Hoa');

    app(Tenancy::class)->enter(1, function () use ($manager, $lead): void {
        $page = Livewire::actingAs($manager)->test(Inbox::class)->call('moveToTrash', $lead->ulid);
        // a colleague restored it meanwhile
        FormSubmission::query()->whereKey($lead->id)->update(['deleted_at' => null]);
        $page->call('undoLast')->assertSet('toastMsg', __('leads.undo_changed'))->assertSet('toastTone', 'error');
    });
});

it('restores a lead from its band in the Trash, with its status', function (): void {
    $manager = trashMember(['forms.view', 'forms.manage']);
    $lead = trashLead(1, 'Nguyễn Thị Hoa', ['status' => 'read']);
    trashSince($lead, now()->subDays(3));

    app(Tenancy::class)->enter(1, function () use ($manager, $lead): void {
        Livewire::actingAs($manager)->withQueryParams(['status' => 'trash', 'lead' => $lead->ulid])->test(Inbox::class)
            ->assertSee(__('leads.trash_band', ['days' => 30]))
            ->assertSee('restoreLead(', false)
            ->assertSee(__('leads.st_trash'))
            // in the Trash: no status to change, no spam or Trash item in its ⋮, the erase only
            ->assertDontSee(__('leads.status_label'))
            ->assertDontSee('moveToTrash(', false)
            ->assertDontSee(", 'spam')", false)
            ->assertSee('askErase(', false)
            ->call('restoreLead', $lead->ulid)
            ->assertSet('toastMsg', __('leads.toast_restored'))
            ->assertSet('undoOp', null)
            ->assertSet('lead', '')
            ->assertSee(__('leads.trash_empty'));
    });

    expect(trashedAt($lead))->toBeNull()
        ->and(DB::connection('pgsql_admin')->table('form_submissions')->where('id', $lead->id)->value('status'))->toBe('read')
        ->and(array_column(trashAudits(), 'action'))->toBe(['forms.lead.restored']);
});

it('keeps a lead in the Trash out of every group and count, and counts it in the Trash', function (): void {
    foreach (['new', 'read', 'archived', 'spam'] as $status) {
        trashLead(1, "Giữ {$status}", ['status' => $status]);
        trashSince(trashLead(1, "Bỏ {$status}", ['status' => $status]), now()->subDay());
    }

    app(Tenancy::class)->enter(1, function (): void {
        $list = new LeadListQuery('', [], '', '', '', app(SiteTime::class), hiddenFormIds: []);
        expect($list->tabCounts())->toBe(['all' => 3, 'new' => 1, 'read' => 1, 'archived' => 1, 'spam' => 1, 'trash' => 4]);
        foreach ([...LeadListQuery::TABS, LeadListQuery::TRASH] as $tab) {
            $names = $list->rows($tab, null, 50, 50)->pluck('name')->all();
            $want = $tab === LeadListQuery::TRASH ? 'Bỏ ' : 'Giữ ';
            expect($names)->not->toBeEmpty();
            foreach ($names as $name) {
                expect($name)->toStartWith($want);
            }
            expect($list->total($tab))->toBe(count($tab === 'all' ? [1, 2, 3] : ($tab === LeadListQuery::TRASH ? [1, 2, 3, 4] : [1])));
        }
        // the time and form counts beside the filters, the dashboard's unread leads, the "any lead?" check
        expect($list->whenCounts('all')[''])->toBe(3)
            ->and($list->whenCounts(LeadListQuery::TRASH)[''])->toBe(4)
            ->and(array_sum($list->formCounts('all', Form::query()->pluck('id')->all(), 3) ?? []))->toBe(3)
            ->and(app(DashboardReader::class)->unreadLeads()['count'])->toBe(1)
            ->and(LeadListQuery::leads()->count())->toBe(4)
            ->and(LeadListQuery::leads(trashed: true)->count())->toBe(4)
            ->and(LeadListQuery::leads(trashed: null)->count())->toBe(8);
    });
});

it('finds a lead in the Trash by search only in the Trash', function (): void {
    trashLead(1, 'Nguyễn Thị Hoa');
    trashSince(trashLead(1, 'Nguyễn Văn Hòa', ['phone' => '0912 345 678']), now()->subDay());

    app(Tenancy::class)->enter(1, function (): void {
        $list = new LeadListQuery('hoa', [], '', '', '', app(SiteTime::class), hiddenFormIds: []);
        expect($list->rows('all', null, 50, 50)->pluck('name')->all())->toBe(['Nguyễn Thị Hoa'])
            ->and($list->rows(LeadListQuery::TRASH, null, 50, 50)->pluck('name')->all())->toBe(['Nguyễn Văn Hòa'])
            ->and($list->tabCounts())->toMatchArray(['all' => 1, 'new' => 1, 'trash' => 1]);
        $phone = new LeadListQuery('0912', [], '', '', '', app(SiteTime::class), hiddenFormIds: []);
        expect($phone->tabCounts())->toMatchArray(['all' => 0, 'trash' => 1]);
    });
});

it('lists the Trash last trashed first, a page at a time by its cursor, each row with its days left', function (): void {
    // well past the day the Trash opened, so every lead's days count from when it was trashed
    $this->travelTo(Carbon::parse(LeadTrash::SINCE, 'UTC')->addDays(45)->setTime(3, 0));
    $viewer = trashMember(['forms.view']);
    $old = trashLead(1, 'Khách cũ');
    $mid = trashLead(1, 'Khách giữa');
    $new = trashLead(1, 'Khách mới bỏ');
    trashSince($old, now()->subDays(30)->subHour());
    trashSince($mid, now()->subDays(10));
    trashSince($new, now()->subMinutes(5));

    app(Tenancy::class)->enter(1, function (): void {
        $list = new LeadListQuery('', [], '', '', '', app(SiteTime::class), hiddenFormIds: []);
        expect($list->rows(LeadListQuery::TRASH, null, 50, 50)->pluck('name')->all())->toBe(['Khách mới bỏ', 'Khách giữa', 'Khách cũ']);
        $until = $list->nextCursor(LeadListQuery::TRASH, null, 1, 1);
        expect($list->rows(LeadListQuery::TRASH, $until, 1, 50)->pluck('name')->all())->toBe(['Khách mới bỏ', 'Khách giữa'])
            ->and($list->nextCursor(LeadListQuery::TRASH, $until, 1, 5))->not->toBe($until);
    });

    $this->actingAs($viewer)->get('http://a.test/admin/forms?status=trash')
        ->assertOk()
        ->assertSeeInOrder(['Khách mới bỏ', __('leads.trash_days_left', ['n' => 30]), 'Khách giữa', __('leads.trash_days_left', ['n' => 20]), 'Khách cũ', __('leads.trash_goes_today')]);
    expect(LeadTrash::daysLeft(now()->subDays(10), now()))->toBe(20)
        ->and(LeadTrash::daysLeft(now()->subDays(40), now()))->toBe(0);
});

it('says the Trash is empty, and offers no download there', function (): void {
    $manager = trashMember(['forms.view', 'forms.manage']);
    trashLead(1, 'Nguyễn Thị Hoa');

    $this->actingAs($manager)->get('http://a.test/admin/forms?status=trash')
        ->assertOk()
        ->assertSee(__('leads.tab_trash'))
        ->assertSee(__('leads.trash_empty'))
        ->assertDontSee('Nguyễn Thị Hoa')
        ->assertDontSee('wire:click="openExport"', false);
    $this->actingAs($manager)->get('http://a.test/admin/forms')->assertOk()->assertSee('wire:click="openExport"', false);

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        Livewire::actingAs($manager)->withQueryParams(['status' => 'trash'])->test(Inbox::class)
            ->call('openExport')->assertSet('exportOpen', false)
            ->set('exportOpen', true)->call('confirmExport', 'password')->assertNotDispatched('leads-export-ready');
    });
});

it('opens no lead in the Trash from a bell link outside the Trash, and marks nothing read', function (): void {
    $manager = trashMember(['forms.view', 'forms.manage']);
    $lead = trashLead(1, 'Nguyễn Thị Hoa', ['phone' => '0987 654 321']);
    trashSince($lead, now()->subDay());

    $this->actingAs($manager)->get('http://a.test/admin/forms/'.$lead->ulid)
        ->assertOk()
        ->assertDontSee('Nguyễn Thị Hoa')
        ->assertDontSee('0987 654 321');
    expect(DB::connection('pgsql_admin')->table('form_submissions')->where('id', $lead->id)->value('status'))->toBe('new');
});

it('leaves a lead in the Trash out of the CSV file', function (): void {
    $manager = trashMember(['forms.view', 'forms.manage']);
    trashLead(1, 'Nguyễn Thị Hoa');
    trashSince(trashLead(1, 'Khách đã bỏ', ['phone' => '0911 000 111']), now()->subDay());

    $url = app(Tenancy::class)->enter(1, function () use ($manager): string {
        $url = '';
        Livewire::actingAs($manager)->test(Inbox::class)->call('openExport')->call('confirmExport', 'password')
            ->assertDispatched('leads-export-ready', function (string $event, array $params) use (&$url): bool {
                $url = (string) ($params['url'] ?? '');

                return true;
            });

        return $url;
    });
    $csv = $this->actingAs($manager)->get('http://a.test'.$url)->assertOk()->streamedContent();

    expect($csv)->toContain('Nguyễn Thị Hoa')->not->toContain('Khách đã bỏ', '0911 000 111');
});

it('lets a member who only reads leads see the Trash, and refuses each of its writes', function (): void {
    $viewer = trashMember(['forms.view']);
    $kept = trashLead(1, 'Nguyễn Thị Hoa');
    $gone = trashLead(1, 'Khách đã bỏ');
    trashSince($gone, now()->subDay());

    $this->actingAs($viewer)->get('http://a.test/admin/forms?status=trash&lead='.$gone->ulid)
        ->assertOk()
        ->assertSee('Khách đã bỏ')
        ->assertSee(__('leads.trash_band', ['days' => 30]))
        ->assertDontSee('restoreLead(', false)
        ->assertDontSee('askErase(', false);

    $manager = trashMember(['forms.view', 'forms.manage']);
    app(Tenancy::class)->enter(1, function () use ($viewer, $manager, $kept, $gone): void {
        Livewire::actingAs($viewer)->test(Inbox::class)->call('moveToTrash', $kept->ulid)->assertForbidden();
        Livewire::actingAs($viewer)->test(Inbox::class)->call('restoreLead', $gone->ulid)->assertForbidden();
        // the policy itself, apart from the screen's own guard
        $lead = FormSubmission::query()->whereKey($kept->id)->firstOrFail();
        expect(Gate::forUser($viewer)->allows('trash', $lead))->toBeFalse()
            ->and(Gate::forUser($viewer)->allows('restore', $lead))->toBeFalse()
            ->and(Gate::forUser($manager)->allows('trash', $lead))->toBeTrue()
            ->and(Gate::forUser($manager)->allows('restore', $lead))->toBeTrue();
    });
    expect(trashedAt($kept))->toBeNull()->and(trashedAt($gone))->not->toBeNull()->and(trashAudits())->toBe([]);
});

it('never reaches another website\'s lead, in or out of the Trash (RLS)', function (): void {
    $manager = trashMember(['forms.view', 'forms.manage']);
    trashLead(1, 'Khách site A');
    $foreign = trashLead(2, 'Khách site B');
    $foreignTrashed = trashLead(2, 'Khách site B đã bỏ');
    trashSince($foreignTrashed, now()->subDay());

    app(Tenancy::class)->enter(1, function () use ($manager, $foreign, $foreignTrashed): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->call('moveToTrash', $foreign->ulid)->assertSet('toastMsg', __('leads.gone'))
            ->call('restoreLead', $foreignTrashed->ulid)->assertSet('toastMsg', __('leads.gone'))
            ->set('tab', 'trash')
            ->assertDontSee('Khách site B')
            ->assertSee(__('leads.trash_empty'));
        expect(LeadListQuery::leads(trashed: null)->count())->toBe(1);
    });
    expect(trashedAt($foreign))->toBeNull()->and(trashedAt($foreignTrashed))->not->toBeNull()->and(trashAudits())->toBe([]);
});

it('erases a lead in the Trash at the visitor\'s request', function (): void {
    $manager = trashMember(['forms.view', 'forms.manage']);
    $lead = trashLead(1, 'Nguyễn Thị Hoa');
    trashSince($lead, now()->subDay());

    app(Tenancy::class)->enter(1, function () use ($manager, $lead): void {
        Livewire::actingAs($manager)->withQueryParams(['status' => 'trash'])->test(Inbox::class)
            ->call('askErase', $lead->ulid)->assertSet('eraseOpen', true)
            ->call('confirmErase')->assertSet('toastMsg', __('leads.toast_erased'));
    });

    expect(DB::connection('pgsql_admin')->table('form_submissions')->where('id', $lead->id)->exists())->toBeFalse();
});

it('shows the Trash on a flat query budget (no N+1 across rows)', function (): void {
    $viewer = trashMember(['forms.view', 'forms.manage']);
    $seed = function (int $n): void {
        foreach (range(1, $n) as $i) {
            trashSince(trashLead(1, 'Khách '.Str::random(6)), now()->subMinutes($i));
        }
    };
    $seed(3);
    $cold = countQueries(fn () => $this->actingAs($viewer)->get('http://a.test/admin/forms?status=trash')->assertOk());
    $few = countQueries(fn () => $this->actingAs($viewer)->get('http://a.test/admin/forms?status=trash')->assertOk());
    $seed(14);
    $many = countQueries(fn () => $this->actingAs($viewer)->get('http://a.test/admin/forms?status=trash')->assertOk());

    // the same statements as any other group (LeadInboxTest's ceilings): one list read, one count
    // statement holding every group and the Trash
    expect($cold)->toBeLessThanOrEqual(24)
        ->and($many)->toBe($few)->toBeLessThanOrEqual(14);
});
