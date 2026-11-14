<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Actions\InviteMemberWithCategories;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\DeleteTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Enums\CategoryChildrenOnDelete;
use App\Modules\Cms\Enums\CategoryItemsOnDelete;
use App\Modules\Cms\Exceptions\CategoryScopeRejectedException;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\InvitationCategoryScope;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\AcceptInvitation;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Exceptions\InvitationRejectedException;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\IssuedInvitation;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The post categories an invitation limits its new member to (invitation_category_scopes): set with
 * the invitation through the same gate as limiting a member later, copied to the member's own limits
 * in the accepting transaction, kept when the invitation is mailed again, and never dropped silently
 * by a category delete — an open invitation's limit moves onto the merge target or the delete is
 * refused, as a member's is.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    DB::connection('pgsql_admin')->statement('truncate invitations, invitation_roles, audit_logs, users restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    fakePwnedVerifier();
});

function icsIn(int $site, Closure $callback): mixed
{
    app(CategoryScopes::class)->forget();

    return app(Tenancy::class)->enter($site, $callback);
}

/** $site's role $name: a corporate template, or an ad-hoc one with $level + $permissions. */
function icsRole(int $site, string $name, ?int $level = null, ?array $permissions = null): Role
{
    $template = PermissionRegistry::tenantRoleTemplates('corporate')[$name] ?? null;
    $level ??= $template['level'] ?? 10;
    $permissions ??= ($template['permissions'] ?? []) === '*' ? PermissionRegistry::TENANT_PERMISSIONS : ($template['permissions'] ?? []);

    return app(Tenancy::class)->enter($site, function () use ($name, $level, $permissions): Role {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $level, 'is_system' => true])->save();
        $role->syncPermissions($permissions);

        return $role;
    });
}

function icsMember(int $site, string $role, ?int $level = null, ?array $permissions = null, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $granted = icsRole($site, $role, $level, $permissions);
    app(Tenancy::class)->enter($site, fn () => $user->assignRole($granted));

    return $user;
}

/** @return list<string> the corporate author's permissions and $extra */
function icsAuthorPlus(string ...$extra): array
{
    return [...PermissionRegistry::tenantRoleTemplates('corporate')['author']['permissions'], ...$extra];
}

function icsCategory(int $site, string $name, ?Taxonomy $parent = null, string $vocabulary = 'post_cat'): Taxonomy
{
    return app(Tenancy::class)->enter($site, fn (): Taxonomy => app(CreateTaxonomy::class)->handle(
        $vocabulary, $parent, new TaxonomyData(names: ['vi' => $name]),
    ));
}

function icsFeatureOn(int $site): void
{
    app(Tenancy::class)->enter($site, fn () => SiteFeature::query()->create(['feature_key' => 'rbac.category_scopes', 'value' => true]));
}

/**
 * $actor (signed in) invites $email to $site as $role, limited to $categories.
 *
 * @param  list<Taxonomy>  $categories
 */
function icsInvite(int $site, User $actor, string $email, string $role, array $categories): IssuedInvitation
{
    test()->actingAs($actor->fresh());
    $granted = icsRole($site, $role);

    return icsIn($site, fn (): IssuedInvitation => app(InviteMemberWithCategories::class)->handle(
        $actor->fresh(), $email, [$granted], null, array_map(fn (Taxonomy $c): int => $c->id, $categories),
    ));
}

/** Why icsInvite() was refused (`denied` for an authorization refusal), or null. */
function icsInviteRefusal(int $site, User $actor, string $email, string $role, array $categories): ?string
{
    try {
        icsInvite($site, $actor, $email, $role, $categories);
    } catch (CategoryScopeRejectedException $e) {
        return $e->reason;
    } catch (AuthorizationException) {
        return 'denied';
    }

    return null;
}

/** @return list<int> the invitation's limits, as stored */
function icsRows(string $email): array
{
    return array_map('intval', DB::connection('pgsql_admin')->table('invitation_category_scopes as s')
        ->join('invitations as i', 'i.id', '=', 's.invitation_id')
        ->where('i.email', $email)->orderBy('s.taxonomy_id')->pluck('s.taxonomy_id')->all());
}

/** @return list<int> */
function icsMemberRows(int $site, User $user): array
{
    return array_map('intval', DB::connection('pgsql_admin')->table('member_category_scopes')
        ->where('site_id', $site)->where('user_id', $user->id)->orderBy('taxonomy_id')->pluck('taxonomy_id')->all());
}

function icsDeleteRefusal(Closure $delete): ?string
{
    try {
        icsIn(1, $delete);
    } catch (TaxonomyChangeRejectedException $e) {
        return $e->reason;
    }

    return null;
}

// ── The table ─────────────────────────────────────────────────────────────────────────────────

it('keeps invitation limits per site (RLS fail-closed), stamps the site and goes with its invitation', function (): void {
    $owner = icsMember(1, 'owner');
    icsFeatureOn(1);
    $news = icsCategory(1, 'Tin tức');
    icsInvite(1, $owner, 'moi@example.test', 'editor', [$news]);

    $inSiteOne = icsIn(1, fn () => InvitationCategoryScope::query()->count());
    $inSiteTwo = icsIn(2, fn () => [InvitationCategoryScope::query()->count(), DB::table('invitation_category_scopes')->count()]);
    expect($inSiteOne)->toBe(1)
        ->and($inSiteTwo)->toBe([0, 0])
        ->and(DB::connection('pgsql_admin')->table('invitation_category_scopes')->value('site_id'))->toBe(1);

    // a row is pinned to its invitation's site: another site's id cannot borrow the invitation
    $invitationId = DB::connection('pgsql_admin')->table('invitations')->value('id');
    expect(fn () => DB::connection('pgsql_admin')->table('invitation_category_scopes')->insert(['site_id' => 2, 'invitation_id' => $invitationId, 'taxonomy_id' => $news->id]))
        ->toThrow(QueryException::class);

    DB::connection('pgsql_admin')->table('invitations')->where('id', $invitationId)->delete();
    expect(DB::connection('pgsql_admin')->table('invitation_category_scopes')->count())->toBe(0);
});

it('refuses at the database to delete a category an invitation limit still names', function (): void {
    $owner = icsMember(1, 'owner');
    icsFeatureOn(1);
    $news = icsCategory(1, 'Tin tức');
    icsInvite(1, $owner, 'moi@example.test', 'editor', [$news]);

    expect(fn () => DB::connection('pgsql_admin')->table('taxonomies')->where('id', $news->id)->delete())
        ->toThrow(QueryException::class);
});

// ── Inviting with limits ──────────────────────────────────────────────────────────────────────

it('stores the categories with the invitation, as one set, and audits it', function (): void {
    $owner = icsMember(1, 'owner');
    icsFeatureOn(1);
    $news = icsCategory(1, 'Tin tức');
    $events = icsCategory(1, 'Sự kiện');

    $issued = icsInvite(1, $owner, 'moi@example.test', 'editor', [$events, $news, $news]);

    expect(icsRows('moi@example.test'))->toBe([$news->id, $events->id])
        ->and(AuditLog::query()->where('action', 'cms.category_scopes.invited')->value('meta'))
        ->toMatchArray(['email' => 'moi@example.test', 'before' => [], 'after' => [$news->id, $events->id]])
        ->and(icsIn(1, fn () => app(CategoryScopes::class)->invitationRootIdsOf([$issued->invitation->id])))
        ->toBe([$issued->invitation->id => [$news->id, $events->id]]);
});

it('sends nothing when the limits are refused', function (string $why): void {
    $owner = icsMember(1, 'owner');
    $news = icsCategory(1, 'Tin tức');
    $other = icsCategory(2, 'Tin tức B');
    $product = icsCategory(1, 'Phụ tùng', vocabulary: 'product_cat');
    if ($why !== 'category_scopes_disabled') {
        icsFeatureOn(1);
    }

    $refusal = match ($why) {
        'category_scopes_disabled' => icsInviteRefusal(1, $owner, 'moi@example.test', 'editor', [$news]),
        'taxonomy_invalid' => icsInviteRefusal(1, $owner, 'moi@example.test', 'editor', [$news, $other]),
        'product' => icsInviteRefusal(1, $owner, 'moi@example.test', 'editor', [$product]),
        'target_exempt' => icsInviteRefusal(1, $owner, 'moi@example.test', 'admin', [$news]),
    };

    expect($refusal)->toBe($why === 'product' ? 'taxonomy_invalid' : $why)
        ->and(DB::connection('pgsql_admin')->table('invitations')->count())->toBe(0)
        ->and(DB::connection('pgsql_admin')->table('invitation_category_scopes')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'rbac.invitation.sent')->count())->toBe(0);
})->with(['category_scopes_disabled', 'taxonomy_invalid', 'product', 'target_exempt']);

it('lets only someone who could set the limits on the member invite with them', function (): void {
    icsFeatureOn(1);
    $news = icsCategory(1, 'Tin tức');
    $inviter = icsMember(1, 'recruiter', 60, icsAuthorPlus('users.invite'));             // may invite, not manage
    $owner = icsMember(1, 'owner');

    expect(icsInviteRefusal(1, $inviter, 'moi@example.test', 'author', [$news]))->toBe('denied')
        ->and(icsInviteRefusal(1, $inviter, 'moi@example.test', 'author', []))->toBeNull()
        ->and(icsRows('moi@example.test'))->toBe([]);

    // the inviter's own session must be the one inviting: limits are never set on someone's behalf
    $this->actingAs($inviter);
    $granted = icsRole(1, 'author');
    expect(fn () => icsIn(1, fn () => app(InviteMemberWithCategories::class)->handle($owner->fresh(), 'khac@example.test', [$granted], null, [$news->id])))
        ->toThrow(AuthorizationException::class);
});

it('keeps a limited inviter inside their own limits, and never lets them invite a writer to every category', function (): void {
    icsFeatureOn(1);
    $news = icsCategory(1, 'Tin tức');
    $local = icsCategory(1, 'Trong nước', $news);
    $events = icsCategory(1, 'Sự kiện');
    $lead = icsMember(1, 'lead', 60, icsAuthorPlus('users.invite', 'users.manage', 'forms.view', 'catalog.view', 'domains.view'));   // may grant viewer too
    DB::connection('pgsql_admin')->table('member_category_scopes')->insert(['site_id' => 1, 'user_id' => $lead->id, 'taxonomy_id' => $news->id]);

    expect(icsInviteRefusal(1, $lead, 'a@example.test', 'author', [$events]))->toBe('outside_own_scope')
        ->and(icsInviteRefusal(1, $lead, 'a@example.test', 'author', []))->toBe('outside_own_scope')
        ->and(icsInviteRefusal(1, $lead, 'a@example.test', 'author', [$local]))->toBeNull()
        ->and(icsRows('a@example.test'))->toBe([$local->id])
        // someone who writes no posts needs no limit
        ->and(icsInviteRefusal(1, $lead, 'b@example.test', 'viewer', []))->toBeNull();
});

it('replaces the limits when the open invitation is refreshed, and keeps them when it is mailed again', function (): void {
    $owner = icsMember(1, 'owner');
    icsFeatureOn(1);
    $news = icsCategory(1, 'Tin tức');
    $events = icsCategory(1, 'Sự kiện');
    icsInvite(1, $owner, 'moi@example.test', 'editor', [$news]);

    $this->travel(2)->minutes();
    $issued = icsInvite(1, $owner, 'moi@example.test', 'editor', [$events]);
    expect(icsRows('moi@example.test'))->toBe([$events->id]);

    $this->travel(2)->minutes();
    $invitation = icsIn(1, fn (): Invitation => Invitation::query()->findOrFail($issued->invitation->id));
    $editor = icsRole(1, 'editor');
    icsIn(1, fn () => app(InviteMemberWithCategories::class)->resend($owner->fresh(), $invitation, [$editor]));
    expect(icsRows('moi@example.test'))->toBe([$events->id])
        ->and(AuditLog::query()->where('action', 'rbac.invitation.sent')->count())->toBe(3)
        ->and(AuditLog::query()->where('action', 'cms.category_scopes.invited')->count())->toBe(2);

    $this->travel(1)->days();   // an address is mailed at most three times a day
    icsInvite(1, $owner, 'moi@example.test', 'editor', []);
    expect(icsRows('moi@example.test'))->toBe([]);
});

it('lets only someone who could set the limits lift them by refreshing the invitation', function (): void {
    $owner = icsMember(1, 'owner');
    icsFeatureOn(1);
    $news = icsCategory(1, 'Tin tức');
    $inviter = icsMember(1, 'recruiter', 60, icsAuthorPlus('users.invite'));
    icsInvite(1, $owner, 'moi@example.test', 'author', [$news]);

    $this->travel(2)->minutes();
    expect(icsInviteRefusal(1, $inviter, 'moi@example.test', 'author', []))->toBe('denied')
        ->and(icsRows('moi@example.test'))->toBe([$news->id]);
});

it('mails nothing again for an invitation withdrawn meanwhile, instead of a new one without its limits', function (): void {
    $owner = icsMember(1, 'owner');
    icsFeatureOn(1);
    $news = icsCategory(1, 'Tin tức');
    $issued = icsInvite(1, $owner, 'moi@example.test', 'author', [$news]);
    $invitation = icsIn(1, fn (): Invitation => Invitation::query()->findOrFail($issued->invitation->id));
    DB::connection('pgsql_admin')->table('invitations')->where('id', $invitation->id)->update(['revoked_at' => now()]);
    $this->travel(2)->minutes();
    $author = icsRole(1, 'author');

    expect(fn () => icsIn(1, fn () => app(InviteMemberWithCategories::class)->resend($owner->fresh(), $invitation, [$author])))
        ->toThrow(InvitationRejectedException::class, 'invalid')
        ->and(DB::connection('pgsql_admin')->table('invitations')->count())->toBe(1);
});

it('refuses a limited sender re-mailing an invitation outside their own limits', function (): void {
    $owner = icsMember(1, 'owner');
    icsFeatureOn(1);
    $news = icsCategory(1, 'Tin tức');
    $events = icsCategory(1, 'Sự kiện');
    $lead = icsMember(1, 'lead', 60, icsAuthorPlus('users.invite', 'users.manage'));
    DB::connection('pgsql_admin')->table('member_category_scopes')->insert(['site_id' => 1, 'user_id' => $lead->id, 'taxonomy_id' => $news->id]);
    $issued = icsInvite(1, $owner, 'moi@example.test', 'author', [$events]);
    $this->travel(2)->minutes();

    $invitation = icsIn(1, fn (): Invitation => Invitation::query()->findOrFail($issued->invitation->id));
    $author = icsRole(1, 'author');
    $this->actingAs($lead->fresh());
    expect(fn () => icsIn(1, fn () => app(InviteMemberWithCategories::class)->resend($lead->fresh(), $invitation, [$author])))
        ->toThrow(CategoryScopeRejectedException::class, 'outside_own_scope');
});

// ── Accepting ─────────────────────────────────────────────────────────────────────────────────

it('makes the invitation\'s limits the new member\'s own when they join, on either road', function (): void {
    $owner = icsMember(1, 'owner');
    icsFeatureOn(1);
    $news = icsCategory(1, 'Tin tức');
    $events = icsCategory(1, 'Sự kiện');
    $fresh = icsInvite(1, $owner, 'moi@example.test', 'author', [$news, $events]);
    $existing = User::factory()->create(['email' => 'da-co@example.test']);
    $known = icsInvite(1, $owner, 'da-co@example.test', 'author', [$events]);
    auth()->logout();

    $user = icsIn(1, fn (): User => app(AcceptInvitation::class)->withNewAccount($fresh->token, 'Nguyễn Văn An', 'Mat-khau-rat-manh-2026'));
    icsIn(1, fn () => app(AcceptInvitation::class)->asSignedInUser($known->token, $existing));

    expect(icsMemberRows(1, $user))->toBe([$news->id, $events->id])
        ->and(icsMemberRows(1, $existing))->toBe([$events->id])
        ->and(icsIn(1, fn () => app(CategoryScopes::class)->isLimited($user->fresh())))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'cms.category_scopes.changed')->where('auditable_id', $user->id)->value('meta'))
        ->toMatchArray(['before' => [], 'after' => [$news->id, $events->id], 'invitation' => $fresh->invitation->ulid]);
});

it('keeps the limits of someone who is a member already when they accept a limited invitation', function (): void {
    $owner = icsMember(1, 'owner');
    icsFeatureOn(1);
    $news = icsCategory(1, 'Tin tức');
    $events = icsCategory(1, 'Sự kiện');
    $person = User::factory()->create(['email' => 'da-co@example.test']);
    $issued = icsInvite(1, $owner, 'da-co@example.test', 'author', [$events]);
    // they became a member another way meanwhile, limited by someone allowed to limit them
    $author = icsRole(1, 'author');
    app(Tenancy::class)->enter(1, fn () => $person->assignRole($author));
    DB::connection('pgsql_admin')->table('member_category_scopes')->insert(['site_id' => 1, 'user_id' => $person->id, 'taxonomy_id' => $news->id]);
    auth()->logout();

    icsIn(1, fn () => app(AcceptInvitation::class)->asSignedInUser($issued->token, $person));

    expect(icsMemberRows(1, $person))->toBe([$news->id])
        ->and(AuditLog::query()->where('action', 'cms.category_scopes.changed')->count())->toBe(0);
});

it('joins without limits when the invitation has none', function (): void {
    $owner = icsMember(1, 'owner');
    $issued = icsInvite(1, $owner, 'moi@example.test', 'author', []);
    auth()->logout();

    $user = icsIn(1, fn (): User => app(AcceptInvitation::class)->withNewAccount($issued->token, 'An', 'Mat-khau-rat-manh-2026'));

    expect(icsMemberRows(1, $user))->toBe([])
        ->and(AuditLog::query()->where('action', 'cms.category_scopes.changed')->count())->toBe(0);
});

it('copies no limits to a member who joins with a role that is never limited', function (): void {
    $owner = icsMember(1, 'owner');
    $news = icsCategory(1, 'Tin tức');
    $issued = icsInvite(1, $owner, 'moi@example.test', 'admin', []);
    // rows left on such an invitation (the action refuses to write them) are ignored, never trusted
    DB::connection('pgsql_admin')->table('invitation_category_scopes')->insert(['site_id' => 1, 'invitation_id' => $issued->invitation->id, 'taxonomy_id' => $news->id]);
    auth()->logout();

    $user = icsIn(1, fn (): User => app(AcceptInvitation::class)->withNewAccount($issued->token, 'An', 'Mat-khau-rat-manh-2026'));

    expect(icsMemberRows(1, $user))->toBe([]);
});

it('joins with the roles and the limits together, or with neither', function (): void {
    $owner = icsMember(1, 'owner');
    icsFeatureOn(1);
    $news = icsCategory(1, 'Tin tức');
    $issued = icsInvite(1, $owner, 'moi@example.test', 'author', [$news]);
    auth()->logout();

    // the copy fails (a member row that cannot be written): the whole join is undone
    DB::connection('pgsql_admin')->statement('alter table member_category_scopes add constraint ics_test_refuse check (taxonomy_id <> '.$news->id.')');
    try {
        expect(fn () => icsIn(1, fn () => app(AcceptInvitation::class)->withNewAccount($issued->token, 'An', 'Mat-khau-rat-manh-2026')))
            ->toThrow(QueryException::class);
    } finally {
        DB::connection('pgsql_admin')->statement('alter table member_category_scopes drop constraint ics_test_refuse');
    }

    expect(DB::connection('pgsql_admin')->table('users')->where('email', 'moi@example.test')->exists())->toBeFalse()
        ->and(DB::connection('pgsql_admin')->table('invitations')->whereNotNull('accepted_at')->count())->toBe(0);
})->skip(getenv('CI') !== 'true', 'alters a table: runs on CI\'s own database only');

// ── Deleting a category ───────────────────────────────────────────────────────────────────────

it('refuses to delete a category an open invitation is limited to with nowhere for the limit to go', function (): void {
    $owner = icsMember(1, 'owner');
    icsFeatureOn(1);
    $jobs = icsCategory(1, 'Tuyển dụng');
    $it = icsCategory(1, 'Kỹ thuật', $jobs);
    icsInvite(1, $owner, 'moi@example.test', 'author', [$it]);

    expect(icsDeleteRefusal(fn () => app(DeleteTaxonomy::class)->handle($it)))->toBe('has_scoped_members')
        ->and(icsDeleteRefusal(fn () => app(DeleteTaxonomy::class)->handle($jobs, CategoryItemsOnDelete::Detach, children: CategoryChildrenOnDelete::Delete)))->toBe('has_scoped_members')
        ->and(icsRows('moi@example.test'))->toBe([$it->id])
        ->and(icsIn(1, fn () => app(DeleteTaxonomy::class)->limitedInvitations($jobs, CategoryChildrenOnDelete::Delete)))
        ->toHaveCount(1)
        ->and(icsIn(1, fn () => app(DeleteTaxonomy::class)->limitedInvitations($jobs, CategoryChildrenOnDelete::Delete))[0]['name'])->toBe('moi@example.test');
});

it('moves an invitation\'s limits onto the merge target, once per invitation', function (): void {
    $owner = icsMember(1, 'owner');
    icsFeatureOn(1);
    $jobs = icsCategory(1, 'Tuyển dụng');
    $it = icsCategory(1, 'Kỹ thuật', $jobs);
    $news = icsCategory(1, 'Tin tức');
    icsInvite(1, $owner, 'a@example.test', 'author', [$jobs, $it]);
    icsInvite(1, $owner, 'b@example.test', 'author', [$it, $news]);

    $this->actingAs($owner->fresh());
    icsIn(1, fn (): array => app(DeleteTaxonomy::class)->handle($jobs, CategoryItemsOnDelete::Reassign, $news, CategoryChildrenOnDelete::Delete));

    expect(icsRows('a@example.test'))->toBe([$news->id])
        ->and(icsRows('b@example.test'))->toBe([$news->id])
        ->and(AuditLog::query()->where('action', 'cms.taxonomy.deleted')->value('meta'))->toMatchArray(['invitation_scopes_moved' => 3, 'scopes_moved' => 0]);
});

it('moves an invitation\'s limits only for someone who could set them, as a member\'s', function (): void {
    $owner = icsMember(1, 'owner');
    icsFeatureOn(1);
    $jobs = icsCategory(1, 'Tuyển dụng');
    $it = icsCategory(1, 'Kỹ thuật', $jobs);
    icsInvite(1, $owner, 'moi@example.test', 'editor', [$it]);                                  // level 50
    $peer = icsMember(1, 'hr_lead', 50, ['users.manage', 'taxonomies.manage', 'entries.view']);

    $this->actingAs($peer->fresh());
    expect(icsDeleteRefusal(fn () => app(DeleteTaxonomy::class)->handle($it, CategoryItemsOnDelete::Reassign, $jobs)))->toBe('has_scoped_members')
        ->and(icsRows('moi@example.test'))->toBe([$it->id]);
});

it('drops a closed invitation\'s limits with no target instead of keeping the category', function (): void {
    $owner = icsMember(1, 'owner');
    icsFeatureOn(1);
    $news = icsCategory(1, 'Tin tức');
    icsInvite(1, $owner, 'huy@example.test', 'author', [$news]);
    DB::connection('pgsql_admin')->table('invitations')->where('email', 'huy@example.test')->update(['revoked_at' => now()]);

    $this->actingAs($owner->fresh());
    expect(icsDeleteRefusal(fn () => app(DeleteTaxonomy::class)->handle($news)))->toBeNull()
        ->and(icsRows('huy@example.test'))->toBe([])
        ->and(icsIn(1, fn () => Taxonomy::query()->whereKey($news->id)->exists()))->toBeFalse();
});

it('moves the limits of a member who joined from a limited invitation with the category, like any member\'s', function (): void {
    $owner = icsMember(1, 'owner');
    icsFeatureOn(1);
    $news = icsCategory(1, 'Tin tức');
    $events = icsCategory(1, 'Sự kiện');
    $issued = icsInvite(1, $owner, 'moi@example.test', 'author', [$news]);
    auth()->logout();
    $user = icsIn(1, fn (): User => app(AcceptInvitation::class)->withNewAccount($issued->token, 'An', 'Mat-khau-rat-manh-2026'));

    $this->actingAs($owner->fresh());
    icsIn(1, fn (): array => app(DeleteTaxonomy::class)->handle($news, CategoryItemsOnDelete::Reassign, $events));

    expect(icsMemberRows(1, $user))->toBe([$events->id])
        ->and(icsRows('moi@example.test'))->toBe([$events->id])
        ->and(icsIn(1, fn () => MemberCategoryScope::query()->count()))->toBe(1);
});

// ── The migration ─────────────────────────────────────────────────────────────────────────────

it('drops the table on rollback and builds it again with its keys and policy', function (): void {
    $migration = require database_path('migrations/2073_01_01_000000_create_invitation_category_scopes_table.php');
    $admin = DB::connection('pgsql_admin');
    $schema = fn (): array => [
        'exists' => $admin->selectOne("select to_regclass('public.invitation_category_scopes') is not null as e")->e,
        'rls' => $admin->selectOne("select relrowsecurity and relforcerowsecurity as f from pg_class where oid = to_regclass('public.invitation_category_scopes')")?->f,
        'policy' => $admin->selectOne("select count(*) as n from pg_policies where tablename = 'invitation_category_scopes'")->n,
        'indexes' => array_map(fn ($r) => $r->indexdef, $admin->select("select indexdef from pg_indexes where tablename = 'invitation_category_scopes' order by indexname")),
        'fks' => collect($admin->select("select pg_get_constraintdef(oid) as d from pg_constraint where conrelid = to_regclass('public.invitation_category_scopes') and contype = 'f'"))->pluck('d')->sort()->values()->all(),
    ];
    $before = $schema();

    DB::connection('pgsql_admin')->transaction(function () use ($migration): void {
        DB::setDefaultConnection('pgsql_admin');
        try {
            $migration->down();
            expect(DB::selectOne("select to_regclass('public.invitation_category_scopes') is null as gone")->gone)->toBeTrue();
            $migration->up();
        } finally {
            DB::setDefaultConnection('pgsql');
        }
    });

    expect($schema())->toBe($before)
        ->and($before['exists'])->toBeTrue()
        ->and($before['rls'])->toBeTrue()
        ->and($before['policy'])->toBe(1)
        ->and($before['fks'])->toBe([
            'FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE',
            'FOREIGN KEY (site_id, invitation_id) REFERENCES invitations(site_id, id) ON DELETE CASCADE',
            'FOREIGN KEY (taxonomy_id) REFERENCES taxonomies(id) ON DELETE RESTRICT',
        ]);
})->skip(getenv('CI') !== 'true', 'drops and re-creates a table: runs on CI\'s own database only');
