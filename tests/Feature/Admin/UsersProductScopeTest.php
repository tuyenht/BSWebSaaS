<?php

use App\Modules\Admin\Livewire\Users\Index;
use App\Modules\Cms\Actions\InviteMemberWithCategories;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
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
 * The products axis of "Phạm vi làm việc" on the members screen: a member's panel sets the product
 * categories they are limited to beside their post categories, the invite dialog sends them with
 * the invitation, and the rows of both tabs name them. Categories reach the page by ulid only; what
 * may be set is decided by the server on every save.
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

/** Site 1's role $name: a corporate template, or an ad-hoc one with $level + $permissions. */
function upsRole(string $name, ?int $level = null, ?array $permissions = null): Role
{
    $template = PermissionRegistry::tenantRoleTemplates('corporate')[$name] ?? null;
    $level ??= $template['level'] ?? 10;
    $permissions ??= ($template['permissions'] ?? []) === '*' ? PermissionRegistry::TENANT_PERMISSIONS : ($template['permissions'] ?? []);

    return app(Tenancy::class)->enter(1, function () use ($name, $level, $permissions): Role {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $level, 'is_system' => true])->save();
        $role->syncPermissions($permissions);

        return $role;
    });
}

function upsMember(string $role, string $name, ?int $level = null, ?array $permissions = null): User
{
    $user = User::factory()->create(['name' => $name]);
    $granted = upsRole($role, $level, $permissions);
    app(Tenancy::class)->enter(1, fn () => $user->assignRole($granted));

    return $user->fresh() ?? $user;
}

/** Every corporate role on site 1, so the screen offers them. */
function upsRoles(): void
{
    foreach (array_keys(PermissionRegistry::tenantRoleTemplates('corporate')) as $name) {
        upsRole($name);
    }
}

function upsCategory(string $name, string $vocabulary = 'post_cat', ?Taxonomy $parent = null): Taxonomy
{
    return app(Tenancy::class)->enter(1, fn (): Taxonomy => app(CreateTaxonomy::class)->handle($vocabulary, $parent, new TaxonomyData(names: ['vi' => $name])));
}

function upsLimit(User $user, Taxonomy ...$categories): void
{
    foreach ($categories as $category) {
        DB::connection('pgsql_admin')->table('member_category_scopes')->insert(['site_id' => 1, 'user_id' => $user->id, 'taxonomy_id' => $category->id]);
    }
}

/** @return list<int> */
function upsRows(User $user): array
{
    return array_map('intval', DB::connection('pgsql_admin')->table('member_category_scopes')
        ->where('site_id', 1)->where('user_id', $user->id)->orderBy('taxonomy_id')->pluck('taxonomy_id')->all());
}

/** @return list<int> */
function upsInvitationRows(string $email): array
{
    return array_map('intval', DB::connection('pgsql_admin')->table('invitation_category_scopes as s')
        ->join('invitations as i', 'i.id', '=', 's.invitation_id')
        ->where('i.email', $email)->orderBy('s.taxonomy_id')->pluck('s.taxonomy_id')->all());
}

function upsFeatureOn(): void
{
    app(Tenancy::class)->enter(1, fn () => SiteFeature::query()->create(['feature_key' => 'rbac.category_scopes', 'value' => true]));
}

/** The website sells products: its catalog module is on. */
function upsCatalogOn(): void
{
    DB::connection('pgsql_admin')->table('site_modules')->insert(['site_id' => 1, 'module_key' => 'catalog', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
}

/** The members screen as $actor sees it on site 1; $then runs in the site. */
function upsPage(User $actor, Closure $then): mixed
{
    app(CategoryScopes::class)->forget();

    return app(Tenancy::class)->enter(1, fn () => $then(Livewire::actingAs($actor->fresh())->test(Index::class)));
}

/** A lead who manages members and products, held to $categories on the products axis. */
function upsProductLead(Taxonomy ...$categories): User
{
    $lead = upsMember('lead', 'Trần Minh', 60, ['users.invite', 'users.manage', 'catalog.view', 'catalog.manage', 'entries.create', 'forms.view', 'domains.view']);
    upsLimit($lead, ...$categories);

    return $lead;
}

it('saves a member\'s product limits from the panel and keeps their post limits', function (): void {
    $owner = upsMember('owner', 'Chủ Website');
    upsRoles();
    upsFeatureOn();
    upsCatalogOn();
    $news = upsCategory('Tin tức');
    $jigs = upsCategory('Đồ gá', 'product_cat');
    $moulds = upsCategory('Khuôn mẫu', 'product_cat');
    $editor = upsMember('editor', 'Hà Vũ');
    upsLimit($editor, $news);

    upsPage($owner, fn (Testable $page) => $page
        ->call('openPanel', $editor->ulid)
        ->assertSet('panelScope.post_cat', 'only')
        ->assertSet('panelCategories.post_cat', [$news->ulid])
        ->assertSet('panelScope.product_cat', 'all')
        ->assertSee(__('admin.members.inv_sc_title'))
        ->assertSee(__('admin.members.sc_products'))
        ->assertSee(__('admin.members.sc_pr_all'))
        ->set('panelScope.product_cat', 'only')
        ->assertSee(__('admin.members.sc_pr_tree'))
        ->assertSeeHtml('value="'.$jigs->ulid.'"')
        // "only" with nothing picked is said on its own axis, and nothing is saved
        ->call('saveRoles')
        ->assertSet('panelScopeError.product_cat', __('admin.members.sc_pr_need'))
        ->assertSet('panelScopeError.post_cat', '')
        ->set('panelCategories.product_cat', [$jigs->ulid])
        ->assertSet('panelScopeError.product_cat', '')
        ->call('saveRoles')
        ->assertSet('panelFor', '')
        ->assertSet('toast', __('admin.members.saved_scope', ['scopes' => __('admin.members.sc_said_pr_only', ['name' => 'Vũ', 'names' => 'Đồ gá'])])));

    expect(upsRows($editor))->toBe([$news->id, $jigs->id])
        ->and($moulds->id)->not->toBeIn(upsRows($editor));

    // lifting the product limit leaves the post one
    upsPage($owner, fn (Testable $page) => $page
        ->call('openPanel', $editor->ulid)
        ->assertSet('panelCategories.product_cat', [$jigs->ulid])
        ->set('panelScope.product_cat', 'all')
        ->call('saveRoles')
        ->assertSet('toast', __('admin.members.saved_scope', ['scopes' => __('admin.members.sc_said_pr_all', ['name' => 'Vũ'])])));
    expect(upsRows($editor))->toBe([$news->id]);
});

it('keeps the limits of an axis the roles ticked do not work on, and says the ones a high role drops', function (): void {
    $owner = upsMember('owner', 'Chủ Website');
    upsRoles();
    upsFeatureOn();
    upsCatalogOn();
    $news = upsCategory('Tin tức');
    $jigs = upsCategory('Đồ gá', 'product_cat');
    $editor = upsMember('editor', 'Hà Vũ');
    upsLimit($editor, $news, $jigs);

    $keep = __('admin.members.sc_keep', ['scope' => __('admin.members.sc_cur', ['axis' => __('admin.members.sc_products'), 'names' => 'Đồ gá']), 'again' => __('admin.members.sc_again_products')]);
    $drop = __('admin.members.sc_drop_high', ['scopes' => __('admin.members.sc_cur', ['axis' => __('admin.members.inv_sc_posts'), 'names' => 'Tin tức']).' · '.__('admin.members.sc_cur', ['axis' => __('admin.members.sc_products'), 'names' => 'Đồ gá'])]);
    upsPage($owner, fn (Testable $page) => $page
        ->call('openPanel', $editor->ulid)
        ->set('panelRoles', ['admin'])
        ->assertSee(__('admin.members.inv_sc_high'))
        ->assertSee($drop)
        // an author writes posts but changes no products: the product limits are kept, said so
        ->set('panelRoles', ['author'])
        ->assertSee($keep)
        ->assertDontSee(__('admin.members.sc_pr_all'))
        ->call('saveRoles')
        ->assertHasNoErrors());

    expect(upsRows($editor))->toBe([$news->id, $jigs->id]);
});

it('never lets a member limited on products hand out every product category, nor change limits outside their own', function (): void {
    upsFeatureOn();
    upsCatalogOn();
    $moulds = upsCategory('Khuôn mẫu', 'product_cat');
    $small = upsCategory('Khuôn nhỏ', 'product_cat', $moulds);
    $jigs = upsCategory('Đồ gá', 'product_cat');
    $lead = upsProductLead($moulds);
    $writer = upsMember('shop-writer', 'Lê Hoa', 30, ['catalog.view', 'catalog.manage']);
    upsLimit($writer, $small);
    $outsider = upsMember('shop-writer', 'Phạm Nam', 30, ['catalog.view', 'catalog.manage']);
    upsLimit($outsider, $jigs);

    // "every category" is locked in the panel, and a forged one changes nothing: the limits stay
    upsPage($lead, fn (Testable $page) => $page
        ->call('openPanel', $writer->ulid)
        ->assertSee(__('admin.members.sc_pr_all'))
        ->assertSee(__('admin.members.inv_sc_own'))
        ->set('panelScope.product_cat', 'all')
        ->call('saveRoles')
        ->assertSet('panelError', ''));
    expect(upsRows($writer))->toBe([$small->id]);

    // limits partly outside the lead's own are shown, never changed from here
    $ro = __('admin.members.sc_ro', ['name' => 'Nam']);
    upsPage($lead, fn (Testable $page) => $page
        ->call('openPanel', $outsider->ulid)
        ->assertSee($ro)
        ->assertSee(__('admin.members.sc_cur', ['axis' => __('admin.members.sc_products'), 'names' => 'Đồ gá']))
        ->set('panelCategories.product_cat', [$small->ulid])
        ->call('saveRoles')
        ->assertSet('panelFor', ''));
    expect(upsRows($outsider))->toBe([$jigs->id]);
});

it('sends the product categories picked in the invite dialog to the server, beside the post ones', function (): void {
    $owner = upsMember('owner', 'Chủ Website');
    upsRoles();
    upsFeatureOn();
    upsCatalogOn();
    $news = upsCategory('Tin tức');
    $jigs = upsCategory('Đồ gá', 'product_cat');
    $moulds = upsCategory('Khuôn mẫu', 'product_cat');

    upsPage($owner, fn (Testable $page) => $page
        ->call('openInvite')
        ->set('inviteEmail', 'moi@example.test')
        ->set('inviteRoles', ['editor'])
        ->assertSee(__('admin.members.inv_sc_posts'))
        ->assertSee(__('admin.members.sc_products'))
        ->set('inviteScope.post_cat', 'only')->set('inviteCategories.post_cat', [$news->ulid])
        ->set('inviteScope.product_cat', 'only')
        ->assertSeeHtml('value="'.$moulds->ulid.'"')
        ->call('sendInvites')
        ->assertSet('inviteScopeError.product_cat', __('admin.members.sc_pr_need'))
        ->set('inviteCategories.product_cat', [$jigs->ulid])
        ->call('sendInvites')
        ->assertHasNoErrors());

    expect(upsInvitationRows('moi@example.test'))->toBe([$news->id, $jigs->id]);
});

it('shows no products axis where the website sells no products, and sends none', function (): void {
    $owner = upsMember('owner', 'Chủ Website');
    upsRoles();
    upsFeatureOn();
    $jigs = upsCategory('Đồ gá', 'product_cat');

    upsPage($owner, fn (Testable $page) => $page
        ->call('openInvite')
        ->set('inviteEmail', 'moi@example.test')
        ->set('inviteRoles', ['editor'])
        ->assertSee(__('admin.members.inv_sc_posts'))
        ->assertDontSee(__('admin.members.sc_pr_all'))
        // a forged pick on the hidden axis is not sent
        ->set('inviteScope.product_cat', 'only')->set('inviteCategories.product_cat', [$jigs->ulid])
        ->call('sendInvites')
        ->assertHasNoErrors());

    expect(DB::connection('pgsql_admin')->table('invitations')->count())->toBe(1)
        ->and(upsInvitationRows('moi@example.test'))->toBe([]);
});

it('keeps an invitation\'s product limits when it is refreshed, mailed again, or carried into "Mời lại"', function (): void {
    $owner = upsMember('owner', 'Chủ Website');
    upsRoles();
    upsFeatureOn();
    upsCatalogOn();
    $news = upsCategory('Tin tức');
    $jigs = upsCategory('Đồ gá', 'product_cat');
    $issued = app(Tenancy::class)->enter(1, function () use ($owner, $jigs) {
        $this->actingAs($owner->fresh());

        return app(InviteMemberWithCategories::class)->handle($owner->fresh(), 'moi@example.test', [Role::findByName('editor', PermissionRegistry::GUARD)], null, [], [$jigs->id]);
    });
    $ulid = $issued->invitation->ulid;

    // refreshed from the dialog with a role that changes no products: the product axis is not sent, so it stays
    $this->travel(2)->minutes();
    upsPage($owner, fn (Testable $page) => $page
        ->call('openInvite')
        ->set('inviteEmail', 'moi@example.test')
        ->set('inviteRoles', ['author'])
        ->assertDontSee(__('admin.members.sc_pr_all'))
        ->set('inviteScope.post_cat', 'only')->set('inviteCategories.post_cat', [$news->ulid])
        ->call('sendInvites')
        ->assertHasNoErrors());
    expect(upsInvitationRows('moi@example.test'))->toBe([$news->id, $jigs->id]);

    $this->travel(2)->minutes();
    upsPage($owner, fn (Testable $page) => $page->set('tab', 'loi-moi')->call('resendInvitation', $ulid));
    expect(upsInvitationRows('moi@example.test'))->toBe([$news->id, $jigs->id]);

    app(Tenancy::class)->enter(1, fn () => Invitation::query()->where('ulid', $ulid)->update(['revoked_at' => now()]));
    upsPage($owner, fn (Testable $page) => $page
        ->set('tab', 'loi-moi')->set('state', 'revoked')
        ->call('reinvite', $ulid)
        ->assertSet('inviteOpen', true)
        ->assertSet('inviteScope.post_cat', 'only')
        ->assertSet('inviteCategories.post_cat', [$news->ulid])
        ->assertSet('inviteScope.product_cat', 'only')
        ->assertSet('inviteCategories.product_cat', [$jigs->ulid]));
});

it('lets a member limited on products invite a catalog manager within their limits, and never to every product category', function (): void {
    upsFeatureOn();
    upsCatalogOn();
    $moulds = upsCategory('Khuôn mẫu', 'product_cat');
    $small = upsCategory('Khuôn nhỏ', 'product_cat', $moulds);
    $jigs = upsCategory('Đồ gá', 'product_cat');
    $lead = upsProductLead($moulds);
    upsRole('shop-writer', 30, ['catalog.view', 'catalog.manage']);

    upsPage($lead, fn (Testable $page) => $page
        ->call('openInvite')
        ->assertSet('inviteScope.product_cat', 'only')
        ->set('inviteEmail', 'moi@example.test')
        ->set('inviteRoles', ['shop-writer'])
        ->assertSee(__('admin.members.sc_products'))
        ->assertSee(__('admin.members.inv_sc_own'))
        ->assertSee(__('admin.members.inv_sc_outside'))
        // a forged "every category" stays "only": with nothing picked it is asked for, never sent
        ->set('inviteScope.product_cat', 'all')
        ->call('sendInvites')
        ->assertSet('inviteScopeError.product_cat', __('admin.members.sc_pr_need'))
        ->set('inviteCategories.product_cat', [$small->ulid])
        ->call('sendInvites')
        ->assertHasNoErrors());

    expect(upsInvitationRows('moi@example.test'))->toBe([$small->id])
        ->and($jigs->id)->not->toBeIn(upsInvitationRows('moi@example.test'));
});

it('names each axis\'s limits on the member and invitation rows, and says when an axis is not in effect', function (): void {
    $owner = upsMember('owner', 'Chủ Website');
    upsRoles();
    upsFeatureOn();
    upsCatalogOn();
    $news = upsCategory('Tin tức');
    $jigs = upsCategory('Đồ gá', 'product_cat');
    $moulds = upsCategory('Khuôn mẫu', 'product_cat');
    $editor = upsMember('editor', 'Hà Vũ');
    upsLimit($editor, $news, $jigs, $moulds);
    $author = upsMember('author', 'Lê Hoa');
    upsLimit($author, $jigs);
    app(Tenancy::class)->enter(1, function () use ($owner, $moulds) {
        $this->actingAs($owner->fresh());

        return app(InviteMemberWithCategories::class)->handle($owner->fresh(), 'moi@example.test', [Role::findByName('editor', PermissionRegistry::GUARD)], null, [], [$moulds->id]);
    });

    $html = upsPage($owner, fn (Testable $page) => $page->html());
    expect($html)->toContain(e(__('admin.members.scope_posts', ['names' => 'Tin tức'])))
        ->toContain(e(__('admin.members.scope_products', ['names' => 'Khuôn mẫu, Đồ gá'])))
        // an author changes no products: the saved product limit is shown as not in effect
        ->toContain(e(__('admin.members.scope_products', ['names' => 'Đồ gá'])).' '.e(__('admin.members.scope_off')));

    upsPage($owner, fn (Testable $page) => $page
        ->set('tab', 'loi-moi')
        ->assertSee(__('admin.members.scope_products', ['names' => 'Khuôn mẫu'])));
});

it('hides product limits on the rows where the website sells no products', function (): void {
    $owner = upsMember('owner', 'Chủ Website');
    upsRoles();
    $jigs = upsCategory('Đồ gá', 'product_cat');
    $editor = upsMember('editor', 'Hà Vũ');
    upsLimit($editor, $jigs);

    upsPage($owner, fn (Testable $page) => $page
        ->assertDontSee(__('admin.members.scope_products', ['names' => 'Đồ gá'])));
});

it('tells the bulk panel how many ticked members a role that is never limited takes the limits from', function (): void {
    $owner = upsMember('owner', 'Chủ Website');
    upsRoles();
    upsFeatureOn();
    upsCatalogOn();
    $news = upsCategory('Tin tức');
    $jigs = upsCategory('Đồ gá', 'product_cat');
    $one = upsMember('editor', 'Hà Vũ');
    upsLimit($one, $news);
    $two = upsMember('editor', 'Lê Hoa');
    upsLimit($two, $jigs);
    $three = upsMember('editor', 'Phạm Nam');

    upsPage($owner, fn (Testable $page) => $page
        ->call('togglePick', [$one->ulid, $two->ulid, $three->ulid])
        ->call('openBulkPanel')
        ->set('panelRoles', ['viewer'])
        ->assertDontSee(__('admin.members.pv_drop', ['n' => 2]))
        ->set('panelRoles', ['admin'])
        ->assertSee(__('admin.members.pv_drop_chip'))
        ->assertSee(__('admin.members.pv_drop', ['n' => 2])));
});

/** $user's role names on site 1, sorted. */
function upsRoleNames(User $user): array
{
    $names = app(Tenancy::class)->enter(1, fn (): array => ($user->fresh() ?? $user)->roles()->pluck('name')->all());
    sort($names);

    return $names;
}

it('never lets a member limited on products give a member with no product limits a role that manages products', function (): void {
    upsFeatureOn();
    upsCatalogOn();
    $moulds = upsCategory('Khuôn mẫu', 'product_cat');
    $small = upsCategory('Khuôn nhỏ', 'product_cat', $moulds);
    $lead = upsProductLead($moulds);
    $viewer = upsMember('shop-viewer', 'Lê Hoa', 10, ['catalog.view']);
    upsRole('shop-writer', 30, ['catalog.view', 'catalog.manage']);

    // the panel opens on "only" for the products axis: a forged "every category" asks for the categories
    upsPage($lead, fn (Testable $page) => $page
        ->call('openPanel', $viewer->ulid)
        ->set('panelRoles', ['shop-writer'])
        ->set('panelScope.product_cat', 'all')
        ->call('saveRoles')
        ->assertSet('panelScopeError.product_cat', __('admin.members.sc_pr_need')));
    expect(upsRoleNames($viewer))->toBe(['shop-viewer'])
        ->and(upsRows($viewer))->toBe([]);

    // within the lead's own product categories it is saved
    upsPage($lead, fn (Testable $page) => $page
        ->call('openPanel', $viewer->ulid)
        ->set('panelRoles', ['shop-writer'])
        ->set('panelScope.product_cat', 'only')
        ->set('panelCategories.product_cat', [$small->ulid])
        ->call('saveRoles')
        ->assertSet('panelFor', ''));
    expect(upsRoleNames($viewer))->toBe(['shop-writer'])
        ->and(upsRows($viewer))->toBe([$small->id]);
});

it('never lets a member limited on posts give a member with no post limits a role that writes posts', function (): void {
    upsFeatureOn();
    $news = upsCategory('Tin tức');
    $lead = upsMember('post-lead', 'Trần Minh', 60, ['users.invite', 'users.manage', 'entries.view', 'entries.create', 'forms.view', 'domains.view']);
    upsLimit($lead, $news);
    $reader = upsMember('reader', 'Lê Hoa', 10, ['entries.view']);
    upsRole('post-writer', 30, ['entries.view', 'entries.create']);

    upsPage($lead, fn (Testable $page) => $page
        ->call('openPanel', $reader->ulid)
        ->set('panelRoles', ['post-writer'])
        ->set('panelScope.post_cat', 'all')
        ->call('saveRoles')
        ->assertSet('panelScopeError.post_cat', __('admin.members.inv_sc_err')));
    expect(upsRoleNames($reader))->toBe(['reader'])
        ->and(upsRows($reader))->toBe([]);

    // "Hoàn tác" from a forged toast takes the same check
    upsPage($lead, fn (Testable $page) => $page
        ->set('undoRoles', ['ulid' => $reader->ulid, 'roles' => ['post-writer']])
        ->call('undo'));
    expect(upsRoleNames($reader))->toBe(['reader']);

    upsPage($lead, fn (Testable $page) => $page
        ->call('openPanel', $reader->ulid)
        ->set('panelRoles', ['post-writer'])
        ->set('panelScope.post_cat', 'only')
        ->set('panelCategories.post_cat', [$news->ulid])
        ->call('saveRoles')
        ->assertSet('panelFor', ''));
    expect(upsRoleNames($reader))->toBe(['post-writer'])
        ->and(upsRows($reader))->toBe([$news->id]);
});

it('refuses the whole bulk role change when a limited member would leave someone working in every category', function (): void {
    upsFeatureOn();
    upsCatalogOn();
    $moulds = upsCategory('Khuôn mẫu', 'product_cat');
    $lead = upsProductLead($moulds);
    $limited = upsMember('shop-viewer', 'Lê Hoa', 10, ['catalog.view']);
    upsLimit($limited, $moulds);
    $open = upsMember('shop-viewer', 'Phạm Nam', 10, ['catalog.view']);
    upsRole('shop-writer', 30, ['catalog.view', 'catalog.manage']);

    upsPage($lead, fn (Testable $page) => $page
        ->call('togglePick', [$limited->ulid, $open->ulid])
        ->call('openBulkPanel')
        ->set('panelRoles', ['shop-writer'])
        ->call('saveRoles')
        ->assertSet('panelError', __('admin.members.bulk_sc_err_needs_limits', ['names' => 'Phạm Nam'])));
    // all or nothing: the member who is limited is not changed either
    expect(upsRoleNames($limited))->toBe(['shop-viewer'])
        ->and(upsRoleNames($open))->toBe(['shop-viewer']);

    // once everyone ticked is limited, it goes through
    upsLimit($open, $moulds);
    upsPage($lead, fn (Testable $page) => $page
        ->call('togglePick', [$limited->ulid, $open->ulid])
        ->call('openBulkPanel')
        ->set('panelRoles', ['shop-writer'])
        ->call('saveRoles')
        ->assertSet('panelError', ''));
    expect(upsRoleNames($limited))->toBe(['shop-writer'])
        ->and(upsRoleNames($open))->toBe(['shop-writer']);
});

it('lets a member who is not limited give a role on either axis with every category', function (): void {
    upsFeatureOn();
    upsCatalogOn();
    upsCategory('Khuôn mẫu', 'product_cat');
    $lead = upsMember('lead', 'Trần Minh', 60, ['users.invite', 'users.manage', 'catalog.view', 'catalog.manage', 'entries.view', 'entries.create', 'forms.view', 'domains.view']);
    $viewer = upsMember('shop-viewer', 'Lê Hoa', 10, ['catalog.view']);
    upsRole('shop-writer', 30, ['catalog.view', 'catalog.manage', 'entries.create']);

    upsPage($lead, fn (Testable $page) => $page
        ->call('openPanel', $viewer->ulid)
        ->set('panelRoles', ['shop-writer'])
        ->call('saveRoles')
        ->assertSet('panelFor', ''));
    expect(upsRoleNames($viewer))->toBe(['shop-writer'])
        ->and(upsRows($viewer))->toBe([]);
});

it('refuses on the server a role on an axis a limited member works on when nobody can be limited any more', function (): void {
    // the plan no longer includes "Phạm vi làm việc": the panel offers no categories, the server still refuses
    upsCatalogOn();
    $moulds = upsCategory('Khuôn mẫu', 'product_cat');
    $lead = upsProductLead($moulds);
    $viewer = upsMember('shop-viewer', 'Lê Hoa', 10, ['catalog.view']);
    upsRole('shop-writer', 30, ['catalog.view', 'catalog.manage']);

    upsPage($lead, fn (Testable $page) => $page
        ->call('openPanel', $viewer->ulid)
        ->set('panelRoles', ['shop-writer'])
        ->call('saveRoles')
        // no categories are shown to choose from, so it asks for the website admin, not for a choice
        ->assertSet('panelError', __('admin.members.pn_sc_err_needs_limits_off')));
    expect(upsRoleNames($viewer))->toBe(['shop-viewer'])
        ->and(upsRows($viewer))->toBe([])
        ->and(__('admin.members.pn_sc_err_needs_limits_off'))->not->toBe(__('admin.members.pn_sc_err_needs_limits'))
        ->and(__('admin.members.pn_sc_err_needs_limits_off'))->toContain('quản trị website');
});
