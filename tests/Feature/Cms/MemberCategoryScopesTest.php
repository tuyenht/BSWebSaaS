<?php

use App\Modules\Admin\Livewire\Users\Index;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Actions\SetMemberCategoryScopes;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\DeleteTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Enums\CategoryChildrenOnDelete;
use App\Modules\Cms\Enums\CategoryItemsOnDelete;
use App\Modules\Cms\Exceptions\CategoryScopeRejectedException;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Category-scoped write rights: a member of a site may be limited to some categories (and all below
 * them). No row = every category. Owners, admins (role level ≥ 70) and platform super_admins are
 * never limited. Limits keep applying when the plan feature is off; the feature only gates setting
 * them. The rows never vanish by accident: a role change keeps them, only leaving the site or
 * becoming never-limited clears them, and deleting a scoped category moves them onto the merge
 * target or is refused.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
});

/** Run inside $site, with nothing remembered from an earlier step. */
function csIn(int $site, Closure $callback): mixed
{
    app(CategoryScopes::class)->forget();

    return app(Tenancy::class)->enter($site, $callback);
}

/** A role in $site's team: a corporate template by name, or an ad-hoc one with $level + $permissions. */
function csRole(int $site, string $name, ?int $level = null, ?array $permissions = null): Role
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

function csMember(int $site, string $role, ?int $level = null, ?array $permissions = null): User
{
    $user = User::factory()->create();
    $granted = csRole($site, $role, $level, $permissions);
    app(Tenancy::class)->enter($site, fn () => $user->assignRole($granted));

    return $user;
}

function csCategory(int $site, string $name, ?Taxonomy $parent = null, string $vocabulary = 'post_cat'): Taxonomy
{
    return app(Tenancy::class)->enter($site, fn (): Taxonomy => app(CreateTaxonomy::class)->handle(
        $vocabulary, $parent, new TaxonomyData(names: ['vi' => $name]),
    ));
}

/** Limit $user on $site to $categories directly (the reader's tests do not go through the action). */
function csLimit(int $site, User $user, Taxonomy ...$categories): void
{
    app(Tenancy::class)->enter($site, function () use ($user, $categories): void {
        foreach ($categories as $category) {
            MemberCategoryScope::query()->create(['user_id' => $user->id, 'taxonomy_id' => $category->id]);
        }
    });
}

/** @return list<int> */
function csRows(int $site, User $user): array
{
    return array_map('intval', DB::connection('pgsql_admin')->table('member_category_scopes')
        ->where('site_id', $site)->where('user_id', $user->id)->orderBy('taxonomy_id')->pluck('taxonomy_id')->all());
}

function csFeatureOn(int $site): void
{
    app(Tenancy::class)->enter($site, fn () => SiteFeature::query()->create(['feature_key' => 'rbac.category_scopes', 'value' => true]));
}

/**
 * Set limits through the action inside $site; returns why it was refused (`denied` for an
 * authorization refusal), or null.
 *
 * @param  list<Taxonomy>  $categories
 */
function csSet(int $site, User $actor, User $target, array $categories): ?string
{
    try {
        csIn($site, fn () => app(SetMemberCategoryScopes::class)->handle(
            $actor->fresh() ?? $actor, $target->fresh() ?? $target, array_map(fn (Taxonomy $c): int => $c->id, $categories),
        ));
    } catch (CategoryScopeRejectedException $e) {
        return $e->reason;
    } catch (AuthorizationException) {
        return 'denied';
    }

    return null;
}

function csDeleteRefusal(Closure $delete): ?string
{
    try {
        csIn(1, $delete);
    } catch (TaxonomyChangeRejectedException $e) {
        return $e->reason;
    }

    return null;
}

// ── The table ─────────────────────────────────────────────────────────────────────────────────

it('keeps limits per site (RLS fail-closed) and stamps the site from the context', function (): void {
    $member = csMember(1, 'editor');
    $news = csCategory(1, 'Tin tức');
    csLimit(1, $member, $news);

    $inSiteOne = csIn(1, fn () => MemberCategoryScope::query()->count());
    $inSiteTwo = csIn(2, fn () => [MemberCategoryScope::query()->count(), DB::table('member_category_scopes')->count()]);

    expect($inSiteOne)->toBe(1)
        ->and($inSiteTwo)->toBe([0, 0])
        ->and(DB::connection('pgsql_admin')->table('member_category_scopes')->value('site_id'))->toBe(1);
});

it('refuses at the database to delete a category a limit still names', function (): void {
    $member = csMember(1, 'editor');
    $news = csCategory(1, 'Tin tức');
    csLimit(1, $member, $news);

    expect(fn () => csIn(1, fn () => Taxonomy::query()->whereKey($news->id)->delete()))
        ->toThrow(QueryException::class, 'member_category_scopes');
});

// ── Reading the limits ────────────────────────────────────────────────────────────────────────

it('leaves a member without rows unlimited, in one query for every question', function (): void {
    $member = csMember(1, 'editor');
    $news = csCategory(1, 'Tin tức');

    [$answers, $queries] = csIn(1, function () use ($member, $news): array {
        $scopes = app(CategoryScopes::class);
        $answers = [];
        $queries = countQueries(function () use ($scopes, $member, $news, &$answers): void {
            $answers = [
                $scopes->isLimited($member), $scopes->covers($member, $news), $scopes->covers($member, null),
                $scopes->allowedTaxonomyIds($member), $scopes->rootIds($member),
            ];
        });

        return [$answers, $queries];
    });

    expect($answers)->toBe([false, true, true, null, null])
        ->and($queries)->toBe(1);
});

it('covers the scoped category and everything below it, nothing else and no content without a category', function (): void {
    $member = csMember(1, 'editor');
    $jobs = csCategory(1, 'Tuyển dụng');
    $it = csCategory(1, 'Kỹ thuật', $jobs);
    $backend = csCategory(1, 'Lập trình', $it);
    $news = csCategory(1, 'Tin tức');
    csLimit(1, $member, $it);

    $answers = csIn(1, function () use ($member, $jobs, $it, $backend, $news): array {
        $scopes = app(CategoryScopes::class);
        $allowed = $scopes->allowedTaxonomyIds($member) ?? [];
        sort($allowed);

        return [
            $scopes->isLimited($member), $scopes->covers($member, $it), $scopes->covers($member, $backend),
            $scopes->covers($member, $jobs), $scopes->covers($member, $news), $scopes->covers($member, null),
            $allowed, $scopes->rootIds($member),
        ];
    });

    expect($answers)->toBe([true, true, true, false, false, false, [$it->id, $backend->id], [$it->id]]);
});

it('still covers a category whose path is not written yet, and stays limited when the scoped one has none', function (): void {
    $member = csMember(1, 'editor');
    $jobs = csCategory(1, 'Tuyển dụng');
    $it = csCategory(1, 'Kỹ thuật', $jobs);
    $news = csCategory(1, 'Tin tức');
    DB::connection('pgsql_admin')->table('taxonomies')->whereIn('id', [$jobs->id, $it->id])->update(['path' => null]);
    csLimit(1, $member, $jobs);

    $answers = csIn(1, fn (): array => [
        app(CategoryScopes::class)->isLimited($member),
        app(CategoryScopes::class)->covers($member, $jobs->fresh()),
        app(CategoryScopes::class)->covers($member, $it->fresh()),
        app(CategoryScopes::class)->covers($member, $news),
    ]);

    expect($answers)->toBe([true, true, true, false]);
});

it('never limits an owner, an admin or a platform super_admin, whatever rows exist', function (): void {
    $news = csCategory(1, 'Tin tức');
    $other = csCategory(1, 'Sự kiện');
    $owner = csMember(1, 'owner');
    $admin = csMember(1, 'admin');
    $editor = csMember(1, 'editor');
    $staff = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $staff->assignRole('super_admin'));
    foreach ([$owner, $admin, $editor, $staff] as $user) {
        csLimit(1, $user, $news);
    }

    $limited = csIn(1, fn (): array => array_map(
        fn (User $user): array => [app(CategoryScopes::class)->isLimited($user), app(CategoryScopes::class)->covers($user, $other)],
        [$owner, $admin, $editor, $staff],
    ));

    expect($limited)->toBe([[false, true], [false, true], [true, false], [false, true]]);
});

it('enforces limits while the plan feature is off', function (): void {
    $member = csMember(1, 'editor');
    $news = csCategory(1, 'Tin tức');
    $other = csCategory(1, 'Sự kiện');
    csLimit(1, $member, $news);

    $answers = csIn(1, fn (): array => [
        app(Entitlement::class)->featureEnabled('rbac.category_scopes'),
        app(CategoryScopes::class)->covers($member, $other),
    ]);

    expect($answers)->toBe([false, false]);
});

// ── Setting the limits ────────────────────────────────────────────────────────────────────────

it('replaces a member\'s limits as a whole, clears them on request, and audits each change', function (): void {
    csFeatureOn(1);
    $owner = csMember(1, 'owner');
    $editor = csMember(1, 'editor');
    $news = csCategory(1, 'Tin tức');
    $jobs = csCategory(1, 'Tuyển dụng');
    $events = csCategory(1, 'Sự kiện');

    expect(csSet(1, $owner, $editor, [$news, $jobs]))->toBeNull()
        ->and(csRows(1, $editor))->toBe([$news->id, $jobs->id])
        ->and(csSet(1, $owner, $editor, [$jobs, $events]))->toBeNull()
        ->and(csRows(1, $editor))->toBe([$jobs->id, $events->id])
        ->and(csSet(1, $owner, $editor, []))->toBeNull()
        ->and(csRows(1, $editor))->toBe([]);

    $trail = AuditLog::query()->where('action', 'cms.category_scopes.changed')->orderBy('id')->pluck('meta')->all();
    expect($trail)->toHaveCount(3)
        ->and($trail[1])->toMatchArray(['before' => [$news->id, $jobs->id], 'after' => [$jobs->id, $events->id]])
        ->and($trail[2])->toMatchArray(['before' => [$jobs->id, $events->id], 'after' => []]);
});

it('refuses whoever may not manage members, themselves, or a member at or above their level', function (): void {
    csFeatureOn(1);
    $news = csCategory(1, 'Tin tức');
    $owner = csMember(1, 'owner');
    $admin = csMember(1, 'admin');
    $otherAdmin = csMember(1, 'admin');
    $editor = csMember(1, 'editor');
    $author = csMember(1, 'author');

    expect(csSet(1, $editor, $author, [$news]))->toBe('denied')        // no users.manage
        ->and(csSet(1, $owner, $owner, [$news]))->toBe('denied')       // never yourself
        ->and(csSet(1, $admin, $otherAdmin, [$news]))->toBe('denied')  // same level
        ->and(csSet(1, $admin, $owner, []))->toBe('denied')            // above you
        ->and(csSet(1, $admin, $author, [$news]))->toBeNull();
});

it('refuses limits for someone who is not a member here or is never limited', function (): void {
    csFeatureOn(1);
    $news = csCategory(1, 'Tin tức');
    $owner = csMember(1, 'owner');
    $admin = csMember(1, 'admin');
    $outsider = csMember(2, 'editor');
    $staff = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $staff->assignRole('super_admin'));

    expect(csSet(1, $owner, $outsider, [$news]))->toBe('not_member')
        ->and(csSet(1, $owner, $admin, [$news]))->toBe('target_exempt')
        ->and(csSet(1, $staff, $admin, [$news]))->toBe('target_exempt')
        ->and(csRows(1, $admin))->toBe([]);
});

it('takes only post categories of this site', function (): void {
    csFeatureOn(1);
    $owner = csMember(1, 'owner');
    $editor = csMember(1, 'editor');
    $elsewhere = csCategory(2, 'Tin tức B');
    $products = csCategory(1, 'Máy móc', vocabulary: 'product_cat');

    expect(csSet(1, $owner, $editor, [$elsewhere]))->toBe('taxonomy_invalid')
        ->and(csSet(1, $owner, $editor, [$products]))->toBe('taxonomy_invalid')
        ->and(csRows(1, $editor))->toBe([]);
});

it('lets a limited manager hand out only what lies inside their own limits', function (): void {
    csFeatureOn(1);
    $owner = csMember(1, 'owner');
    $lead = csMember(1, 'hr_lead', 60, ['users.manage', 'entries.view', 'entries.create', 'entries.edit.any']);
    $author = csMember(1, 'author');
    $jobs = csCategory(1, 'Tuyển dụng');
    $it = csCategory(1, 'Kỹ thuật', $jobs);
    $news = csCategory(1, 'Tin tức');
    expect(csSet(1, $owner, $lead, [$jobs]))->toBeNull();

    expect(csSet(1, $lead, $author, [$news]))->toBe('outside_own_scope')
        ->and(csSet(1, $lead, $author, [$it, $news]))->toBe('outside_own_scope')
        ->and(csSet(1, $lead, $author, []))->toBe('outside_own_scope')   // "every category" is wider
        ->and(csSet(1, $lead, $author, [$it]))->toBeNull()
        ->and(csRows(1, $author))->toBe([$it->id]);
});

it('needs the plan feature to set limits, but not to clear them', function (): void {
    $owner = csMember(1, 'owner');
    $editor = csMember(1, 'editor');
    $news = csCategory(1, 'Tin tức');
    csLimit(1, $editor, $news);

    expect(csSet(1, $owner, $editor, [$news]))->toBe('category_scopes_disabled')
        ->and(csRows(1, $editor))->toBe([$news->id])
        ->and(csSet(1, $owner, $editor, []))->toBeNull()
        ->and(csRows(1, $editor))->toBe([]);
});

// ── Limits follow the membership ──────────────────────────────────────────────────────────────

it('keeps a member\'s limits when their role changes', function (): void {
    $owner = csMember(1, 'owner');
    $member = csMember(1, 'editor');
    csRole(1, 'author');
    $news = csCategory(1, 'Tin tức');
    csLimit(1, $member, $news);

    csIn(1, fn () => Livewire::actingAs($owner->fresh())->test(Index::class)->call('changeRole', $member->ulid, 'author'));

    expect(csRows(1, $member))->toBe([$news->id])
        ->and(csIn(1, fn () => $member->fresh()?->roles()->pluck('name')->all()))->toBe(['author']);
});

it('clears a member\'s limits when they leave the site, in the same step', function (): void {
    $owner = csMember(1, 'owner');
    $member = csMember(1, 'editor');
    $news = csCategory(1, 'Tin tức');
    csLimit(1, $member, $news);

    csIn(1, fn () => Livewire::actingAs($owner->fresh())->test(Index::class)->call('removeMember', $member->ulid));

    expect(csRows(1, $member))->toBe([])
        ->and(AuditLog::query()->where('action', 'cms.category_scopes.cleared')->value('meta'))
        ->toMatchArray(['taxonomy_ids' => [$news->id], 'reason' => 'left_site']);
});

it('clears a member\'s limits when they are promoted to a role never limited, and a demotion does not bring them back', function (): void {
    $owner = csMember(1, 'owner');
    $member = csMember(1, 'editor');
    csRole(1, 'admin');
    $news = csCategory(1, 'Tin tức');
    csLimit(1, $member, $news);

    csIn(1, fn () => Livewire::actingAs($owner->fresh())->test(Index::class)->call('changeRole', $member->ulid, 'admin'));
    $afterPromotion = csRows(1, $member);
    csIn(1, fn () => Livewire::actingAs($owner->fresh())->test(Index::class)->call('changeRole', $member->ulid, 'editor'));

    expect($afterPromotion)->toBe([])
        ->and(csRows(1, $member))->toBe([])
        ->and(csIn(1, fn () => app(CategoryScopes::class)->isLimited($member->fresh() ?? $member)))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'cms.category_scopes.cleared')->value('meta'))
        ->toMatchArray(['taxonomy_ids' => [$news->id], 'reason' => 'exempt_role']);
});

it('rolls the limits back with the role when the role change fails', function (): void {
    $owner = csMember(1, 'owner');
    $member = csMember(1, 'editor');
    csRole(1, 'admin');
    $boss = csRole(1, 'boss', 95, ['entries.view']);
    app(Tenancy::class)->enter(1, fn () => $member->assignRole($boss));
    $news = csCategory(1, 'Tin tức');
    csLimit(1, $member, $news);

    // Granting admin clears the limits, then the owner cannot revoke a role above them: the whole
    // change rolls back, the limits included.
    csIn(1, fn () => Livewire::actingAs($owner->fresh())->test(Index::class)->call('changeRole', $member->ulid, 'admin'));

    expect(csRows(1, $member))->toBe([$news->id])
        ->and(csIn(1, fn () => $member->fresh()?->roles()->orderBy('name')->pluck('name')->all()))->toBe(['boss', 'editor']);
});

// ── Deleting a scoped category ────────────────────────────────────────────────────────────────

it('refuses to delete a scoped category with nowhere for the limit to go', function (): void {
    $member = csMember(1, 'editor');
    $jobs = csCategory(1, 'Tuyển dụng');
    $it = csCategory(1, 'Kỹ thuật', $jobs);
    csLimit(1, $member, $it);

    expect(csDeleteRefusal(fn () => app(DeleteTaxonomy::class)->handle($it)))->toBe('has_scoped_members')
        ->and(csDeleteRefusal(fn () => app(DeleteTaxonomy::class)->handle($it, CategoryItemsOnDelete::Detach)))->toBe('has_scoped_members')
        ->and(csDeleteRefusal(fn () => app(DeleteTaxonomy::class)->handle($jobs, CategoryItemsOnDelete::Detach, children: CategoryChildrenOnDelete::Delete)))->toBe('has_scoped_members')
        ->and(csRows(1, $member))->toBe([$it->id])
        ->and(csIn(1, fn () => Taxonomy::query()->whereKey([$jobs->id, $it->id])->count()))->toBe(2);
});

it('moves limits on every deleted category onto the merge target, once per member', function (): void {
    $lan = csMember(1, 'editor');
    $minh = csMember(1, 'author');
    $jobs = csCategory(1, 'Tuyển dụng');
    $it = csCategory(1, 'Kỹ thuật', $jobs);
    $news = csCategory(1, 'Tin tức');
    csLimit(1, $lan, $jobs, $it);
    csLimit(1, $minh, $it, $news);

    $result = csIn(1, fn (): array => app(DeleteTaxonomy::class)->handle($jobs, CategoryItemsOnDelete::Reassign, $news, CategoryChildrenOnDelete::Delete));

    expect($result['categories'])->toBe(2)
        ->and(csRows(1, $lan))->toBe([$news->id])
        ->and(csRows(1, $minh))->toBe([$news->id])
        ->and(AuditLog::query()->where('action', 'cms.taxonomy.deleted')->value('meta'))->toMatchArray(['scopes_moved' => 3]);
});

it('deletes a category below a scoped one without touching the limit', function (): void {
    $member = csMember(1, 'editor');
    $jobs = csCategory(1, 'Tuyển dụng');
    $it = csCategory(1, 'Kỹ thuật', $jobs);
    csLimit(1, $member, $jobs);

    expect(csDeleteRefusal(fn () => app(DeleteTaxonomy::class)->handle($it)))->toBeNull()
        ->and(csRows(1, $member))->toBe([$jobs->id]);
});
