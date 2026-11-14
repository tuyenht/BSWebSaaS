<?php

use App\Modules\Admin\Livewire\Users\Index;
use App\Modules\Cms\Actions\InviteMemberWithCategories;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * "Phạm vi làm việc" in the invite dialog of the members screen: the post categories the invited
 * person will be limited to, sent with the invitation, shown on the invitation's row, and carried
 * by "Mời lại". Categories reach the page by ulid only.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate invitations, invitation_roles, audit_logs, users restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    Notification::fake();
    fakePwnedVerifier();
});

function iscMember(string $name, int $site = 1): User
{
    $tpl = PermissionRegistry::tenantRoleTemplates('corporate')[$name];
    $perms = $tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions'];
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, function () use ($name, $tpl, $perms, $user): void {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
        $role->syncPermissions($perms);
        $user->assignRole($role);
    });

    return $user;
}

/** Every corporate role on site 1, so the dialog offers them. */
function iscRoles(): void
{
    foreach (PermissionRegistry::tenantRoleTemplates('corporate') as $name => $tpl) {
        app(Tenancy::class)->enter(1, function () use ($name, $tpl): void {
            $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
            $role->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
            $role->syncPermissions($tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions']);
        });
    }
}

function iscCategory(string $name, ?Taxonomy $parent = null): Taxonomy
{
    return app(Tenancy::class)->enter(1, fn (): Taxonomy => app(CreateTaxonomy::class)->handle('post_cat', $parent, new TaxonomyData(names: ['vi' => $name])));
}

function iscFeatureOn(): void
{
    app(Tenancy::class)->enter(1, fn () => SiteFeature::query()->create(['feature_key' => 'rbac.category_scopes', 'value' => true]));
}

/** The members screen as $actor sees it on site 1, with the invite dialog open; $then runs in the site. */
function iscDialog(User $actor, Closure $then): mixed
{
    return app(Tenancy::class)->enter(1, fn () => $then(Livewire::actingAs($actor->fresh())->test(Index::class)->call('openInvite')));
}

/** @return list<int> */
function iscRows(string $email): array
{
    return array_map('intval', DB::connection('pgsql_admin')->table('invitation_category_scopes as s')
        ->join('invitations as i', 'i.id', '=', 's.invitation_id')
        ->where('i.email', $email)->orderBy('s.taxonomy_id')->pluck('s.taxonomy_id')->all());
}

it('sends the categories picked with the invitation, a category standing for everything below it', function (): void {
    $owner = iscMember('owner');
    iscRoles();
    iscFeatureOn();
    $jobs = iscCategory('Tuyển dụng');
    $it = iscCategory('Kỹ thuật', $jobs);
    $news = iscCategory('Tin tức');

    iscDialog($owner, fn (Testable $page) => $page
        ->set('inviteEmail', 'moi@example.test')
        ->set('inviteRoles', ['editor'])
        ->assertSee(__('admin.members.inv_sc_title'))
        ->assertSee(__('admin.members.inv_sc_all'))
        ->set('inviteScope', 'only')
        ->assertSee('Tuyển dụng')->assertSee('Kỹ thuật')
        ->assertSeeHtml('value="'.$jobs->ulid.'"')
        ->set('inviteCategories', [$jobs->ulid, $it->ulid])
        ->assertSee(__('admin.members.inv_sc_included'))
        ->set('inviteCatQ', 'ky thuat')
        ->assertSee('Tuyển dụng › ')
        ->assertDontSeeHtml('value="'.$news->ulid.'"')
        ->call('sendInvites')
        ->assertHasNoErrors()
        ->assertSet('inviteScopeError', ''));

    expect(iscRows('moi@example.test'))->toBe([$jobs->id])
        ->and($news->id)->not->toBeIn(iscRows('moi@example.test'));
});

it('asks for a category when "only" is chosen with none picked, and sends nothing', function (): void {
    $owner = iscMember('owner');
    iscRoles();
    iscFeatureOn();
    iscCategory('Tin tức');

    iscDialog($owner, fn (Testable $page) => $page
        ->set('inviteEmail', 'moi@example.test')
        ->set('inviteRoles', ['editor'])
        ->set('inviteScope', 'only')
        ->call('sendInvites')
        ->assertSet('inviteScopeError', __('admin.members.inv_sc_err'))
        ->assertSee(__('admin.members.inv_sc_err'))
        ->set('inviteScope', 'all')
        ->assertSet('inviteScopeError', ''));

    expect(DB::connection('pgsql_admin')->table('invitations')->count())->toBe(0);
});

it('follows the roles ticked: no scope for a role that is never limited or writes no posts', function (): void {
    $owner = iscMember('owner');
    iscRoles();
    iscFeatureOn();
    $news = iscCategory('Tin tức');

    iscDialog($owner, fn (Testable $page) => $page
        ->set('inviteRoles', ['admin'])->assertSee(__('admin.members.inv_sc_high'))->assertDontSee(__('admin.members.inv_sc_all'))
        ->set('inviteRoles', ['viewer'])->assertSee(__('admin.members.inv_sc_none'))->assertDontSee(__('admin.members.inv_sc_all'))
        // a pick left over from another role is never sent with one that takes no scope
        ->set('inviteRoles', ['editor'])->set('inviteScope', 'only')->set('inviteCategories', [$news->ulid])
        ->set('inviteRoles', ['admin'])
        ->set('inviteEmail', 'moi@example.test')
        ->call('sendInvites')
        ->assertHasNoErrors());

    expect(DB::connection('pgsql_admin')->table('invitations')->count())->toBe(1)
        ->and(iscRows('moi@example.test'))->toBe([]);
});

it('locks the choice without the plan feature and invites to every category', function (): void {
    $owner = iscMember('owner');
    iscRoles();
    $news = iscCategory('Tin tức');

    iscDialog($owner, fn (Testable $page) => $page
        ->set('inviteEmail', 'moi@example.test')
        ->set('inviteRoles', ['editor'])
        ->assertSee(__('admin.members.inv_sc_plan_off'))
        // a forged "only" is not honoured: the categories are not sent
        ->set('inviteScope', 'only')->set('inviteCategories', [$news->ulid])
        ->assertDontSeeHtml('value="'.$news->ulid.'"')
        ->call('sendInvites')
        ->assertHasNoErrors());

    expect(DB::connection('pgsql_admin')->table('invitations')->count())->toBe(1)
        ->and(iscRows('moi@example.test'))->toBe([]);
});

it('shows an invitation\'s limits on its row, keeps them when it is mailed again, and carries them into "Mời lại"', function (): void {
    $owner = iscMember('owner');
    iscRoles();
    iscFeatureOn();
    $news = iscCategory('Tin tức');
    $issued = app(Tenancy::class)->enter(1, function () use ($owner, $news) {
        $this->actingAs($owner->fresh());

        return app(InviteMemberWithCategories::class)->handle($owner->fresh(), 'moi@example.test', [Role::findByName('editor', PermissionRegistry::GUARD)], null, [$news->id]);
    });
    $ulid = $issued->invitation->ulid;

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner->fresh())->test(Index::class)
        ->set('tab', 'loi-moi')
        ->assertSee(__('admin.members.scope_posts', ['names' => 'Tin tức'])));

    $this->travel(2)->minutes();
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner->fresh())->test(Index::class)
        ->set('tab', 'loi-moi')->call('resendInvitation', $ulid));
    expect(iscRows('moi@example.test'))->toBe([$news->id])
        ->and(app(Tenancy::class)->enter(1, fn (): string => Invitation::query()->where('ulid', $ulid)->value('token_hash')))
        ->not->toBe(Invitation::hashToken($issued->token));

    app(Tenancy::class)->enter(1, fn () => Invitation::query()->where('ulid', $ulid)->update(['revoked_at' => now()]));
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner->fresh())->test(Index::class)
        ->set('tab', 'loi-moi')->set('state', 'revoked')
        ->call('reinvite', $ulid)
        ->assertSet('inviteOpen', true)
        ->assertSet('inviteScope', 'only')
        ->assertSet('inviteCategories', [$news->ulid]));
});
