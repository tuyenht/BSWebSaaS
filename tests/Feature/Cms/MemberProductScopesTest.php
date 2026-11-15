<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Actions\InviteMemberWithCategories;
use App\Modules\Cms\Actions\SetMemberCategoryScopes;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\DeleteTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\MoveTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Enums\CategoryChildrenOnDelete;
use App\Modules\Cms\Enums\CategoryItemsOnDelete;
use App\Modules\Cms\Exceptions\CategoryScopeRejectedException;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\AcceptInvitation;
use App\Modules\Rbac\Actions\UpdateCustomRole;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Exceptions\InvitationRejectedException;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\IssuedInvitation;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/*
 * Member limits on two axes: post categories and product categories. A limit row's axis is the
 * vocabulary of the category it names, and a category's vocabulary never changes (the database
 * refuses it). Each axis is read, set, handed out by invitation and copied on accept on its own: a
 * post limit never reaches products (not even products filed under a post category's product
 * sub-categories), setting one axis never touches the other, and no rows on an axis = every
 * category of it.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    DB::connection('pgsql_admin')->statement('truncate invitations, invitation_roles, audit_logs restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    fakePwnedVerifier();
});

function mpsIn(int $site, Closure $callback): mixed
{
    app(CategoryScopes::class)->forget();

    return app(Tenancy::class)->enter($site, $callback);
}

/** $site's role $name: a corporate template, or an ad-hoc one with $level + $permissions. */
function mpsRole(int $site, string $name, ?int $level = null, ?array $permissions = null): Role
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

function mpsMember(int $site, string $role, ?int $level = null, ?array $permissions = null): User
{
    $user = User::factory()->create();
    $granted = mpsRole($site, $role, $level, $permissions);
    app(Tenancy::class)->enter($site, fn () => $user->assignRole($granted));

    return $user->fresh() ?? $user;
}

/** A category made through the write path (nobody signed in). */
function mpsCategory(int $site, string $name, ?Taxonomy $parent = null, string $vocabulary = 'post_cat'): Taxonomy
{
    return app(Tenancy::class)->enter($site, fn (): Taxonomy => app(CreateTaxonomy::class)->handle(
        $vocabulary, $parent, new TaxonomyData(names: ['vi' => $name]),
    ));
}

/** A product category stored under a POST category — the mixed tree the An Vinh seed builds. */
function mpsProductUnder(int $site, Taxonomy $postParent, string $name): Taxonomy
{
    return app(Tenancy::class)->enter($site, function () use ($postParent, $name): Taxonomy {
        $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'parent_id' => $postParent->id, 'position' => 0]);
        app(TaxonomyPaths::class)->refreshBranch($category->id);
        $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => Str::slug($name)]);

        return $category->fresh() ?? $category;
    });
}

function mpsLimit(int $site, User $user, Taxonomy ...$categories): void
{
    foreach ($categories as $category) {
        DB::connection('pgsql_admin')->table('member_category_scopes')->insert(['site_id' => $site, 'user_id' => $user->id, 'taxonomy_id' => $category->id]);
    }
}

/** @return list<int> */
function mpsRows(int $site, User $user): array
{
    return array_map('intval', DB::connection('pgsql_admin')->table('member_category_scopes')
        ->where('site_id', $site)->where('user_id', $user->id)->orderBy('taxonomy_id')->pluck('taxonomy_id')->all());
}

/** @return list<int> */
function mpsInvitationRows(string $email): array
{
    return array_map('intval', DB::connection('pgsql_admin')->table('invitation_category_scopes as s')
        ->join('invitations as i', 'i.id', '=', 's.invitation_id')
        ->where('i.email', $email)->orderBy('s.taxonomy_id')->pluck('s.taxonomy_id')->all());
}

function mpsFeatureOn(int $site): void
{
    app(Tenancy::class)->enter($site, fn () => SiteFeature::query()->create(['feature_key' => 'rbac.category_scopes', 'value' => true]));
}

/** $actor sets $target's limits on $axis; the refusal reason (`denied` for an authorization one) or null. */
function mpsSet(int $site, User $actor, User $target, array $categories, string $axis): ?string
{
    try {
        mpsIn($site, fn () => app(SetMemberCategoryScopes::class)->handle(
            $actor->fresh() ?? $actor, $target->fresh() ?? $target, array_map(fn (Taxonomy $c): int => $c->id, $categories), $axis,
        ));
    } catch (CategoryScopeRejectedException $e) {
        return $e->reason;
    } catch (AuthorizationException) {
        return 'denied';
    }

    return null;
}

/**
 * $actor (signed in) invites $email to $site with $role, limited to $posts and $products (null: that axis left as it is).
 *
 * @param  list<Taxonomy>  $posts
 * @param  list<Taxonomy>|null  $products
 */
function mpsInvite(int $site, User $actor, string $email, Role $role, array $posts, ?array $products): IssuedInvitation
{
    test()->actingAs($actor->fresh());

    return mpsIn($site, fn (): IssuedInvitation => app(InviteMemberWithCategories::class)->handle(
        $actor->fresh(), $email, [$role->fresh()], null,
        array_map(fn (Taxonomy $c): int => $c->id, $posts),
        $products === null ? null : array_map(fn (Taxonomy $c): int => (string) $c->id, $products),
    ));
}

function mpsInviteRefusal(int $site, User $actor, string $email, Role $role, array $posts, ?array $products): ?string
{
    try {
        mpsInvite($site, $actor, $email, $role, $posts, $products);
    } catch (CategoryScopeRejectedException $e) {
        return $e->reason;
    } catch (AuthorizationException) {
        return 'denied';
    }

    return null;
}

/** Why $callback's category change was refused, or null. */
function mpsTreeRefusal(Closure $callback): ?string
{
    try {
        mpsIn(1, $callback);
    } catch (TaxonomyChangeRejectedException $e) {
        return $e->reason;
    }

    return null;
}

/** A catalog manager who may also change the categories (level 40: limitable). */
function mpsCatalogManager(): User
{
    return mpsMember(1, 'catalog-lead', 40, ['catalog.view', 'catalog.manage', 'taxonomies.manage', 'blocks.manage', 'entries.create', 'entries.edit.own']);
}

it('reads each axis from the vocabulary of the category a row names, never from the categories it reaches', function (): void {
    $shop = mpsCategory(1, 'Sản phẩm');                    // a post category …
    $jigs = mpsProductUnder(1, $shop, 'Đồ gá');            // … with a product category under it
    $moulds = mpsCategory(1, 'Khuôn', null, 'product_cat');
    $both = mpsMember(1, 'writer', 30, ['entries.create', 'catalog.manage']);
    $postsOnly = mpsMember(1, 'writer', 30, ['entries.create', 'catalog.manage']);
    $productsOnly = mpsMember(1, 'writer', 30, ['entries.create', 'catalog.manage']);
    mpsLimit(1, $both, $shop, $moulds);
    mpsLimit(1, $postsOnly, $shop);
    mpsLimit(1, $productsOnly, $moulds);

    mpsIn(1, function () use ($both, $postsOnly, $productsOnly, $shop, $jigs, $moulds): void {
        $scopes = app(CategoryScopes::class);

        // the post row on "Sản phẩm" grants no product, not even those under its product sub-category
        expect($scopes->allowedTaxonomyIds($both, CategoryScopes::PRODUCTS))->toBe([$moulds->id])
            ->and($scopes->coversId($both, $jigs->id, CategoryScopes::PRODUCTS))->toBeFalse()
            ->and($scopes->coversId($both, $moulds->id, CategoryScopes::PRODUCTS))->toBeTrue()
            ->and($scopes->rootIds($both, CategoryScopes::POSTS))->toBe([$shop->id])
            ->and($scopes->rootIds($both, CategoryScopes::PRODUCTS))->toBe([$moulds->id])
            // the product row never limits posts
            ->and($scopes->coversId($both, $moulds->id, CategoryScopes::POSTS))->toBeFalse()
            // no rows on an axis = every category of it
            ->and($scopes->isLimited($postsOnly, CategoryScopes::PRODUCTS))->toBeFalse()
            ->and($scopes->coversId($postsOnly, $jigs->id, CategoryScopes::PRODUCTS))->toBeTrue()
            ->and($scopes->isLimited($productsOnly, CategoryScopes::POSTS))->toBeFalse()
            ->and($scopes->isLimited($productsOnly, CategoryScopes::PRODUCTS))->toBeTrue()
            ->and($scopes->isLimitedOnAnyAxis($productsOnly))->toBeTrue()
            // a product with no category is never covered for a member limited on products
            ->and($scopes->coversId($productsOnly, null, CategoryScopes::PRODUCTS))->toBeFalse()
            ->and(fn () => $scopes->isLimited($both, 'facet'))->toThrow(InvalidArgumentException::class);
    });
});

it('sets one axis without touching the other, and audits which', function (): void {
    $owner = mpsMember(1, 'owner');
    mpsFeatureOn(1);
    $news = mpsCategory(1, 'Tin tức');
    $moulds = mpsCategory(1, 'Khuôn', null, 'product_cat');
    $jigs = mpsCategory(1, 'Đồ gá', null, 'product_cat');
    $target = mpsMember(1, 'writer', 30, ['entries.create', 'catalog.manage']);
    test()->actingAs($owner);

    expect(mpsSet(1, $owner, $target, [$news], CategoryScopes::POSTS))->toBeNull()
        ->and(mpsSet(1, $owner, $target, [$moulds, $jigs], CategoryScopes::PRODUCTS))->toBeNull()
        ->and(mpsRows(1, $target))->toBe([$news->id, $moulds->id, $jigs->id])
        // lifting the post limits keeps the product limits …
        ->and(mpsSet(1, $owner, $target, [], CategoryScopes::POSTS))->toBeNull()
        ->and(mpsRows(1, $target))->toBe([$moulds->id, $jigs->id])
        // … and the other way round
        ->and(mpsSet(1, $owner, $target, [$news], CategoryScopes::POSTS))->toBeNull()
        ->and(mpsSet(1, $owner, $target, [$jigs], CategoryScopes::PRODUCTS))->toBeNull()
        ->and(mpsRows(1, $target))->toBe([$news->id, $jigs->id])
        ->and(mpsSet(1, $owner, $target, [], CategoryScopes::PRODUCTS))->toBeNull()
        ->and(mpsRows(1, $target))->toBe([$news->id])
        // a category of the other axis, or an axis that is none, is refused
        ->and(mpsSet(1, $owner, $target, [$news], CategoryScopes::PRODUCTS))->toBe('taxonomy_invalid')
        ->and(mpsSet(1, $owner, $target, [$moulds], CategoryScopes::POSTS))->toBe('taxonomy_invalid')
        ->and(mpsSet(1, $owner, $target, [$moulds], 'facet'))->toBe('taxonomy_invalid')
        ->and(mpsRows(1, $target))->toBe([$news->id])
        ->and(AuditLog::query()->where('action', 'cms.category_scopes.changed')->orderBy('id')->get()->map(fn (AuditLog $log): mixed => $log->meta['vocabulary'] ?? null)->all())
        ->toBe(['post_cat', 'product_cat', 'post_cat', 'post_cat', 'product_cat', 'product_cat']);
});

it('keeps a limited member inside their own limits of each axis when they limit someone else', function (): void {
    mpsFeatureOn(1);
    $moulds = mpsCategory(1, 'Khuôn', null, 'product_cat');
    $small = mpsCategory(1, 'Khuôn nhỏ', $moulds, 'product_cat');
    $jigs = mpsCategory(1, 'Đồ gá', null, 'product_cat');
    $news = mpsCategory(1, 'Tin tức');
    $lead = mpsMember(1, 'lead', 60, ['users.manage', 'catalog.manage', 'entries.create']);
    mpsLimit(1, $lead, $moulds);
    $target = mpsMember(1, 'writer', 30, ['entries.create', 'catalog.manage']);
    test()->actingAs($lead);

    expect(mpsSet(1, $lead, $target, [$jigs], CategoryScopes::PRODUCTS))->toBe('outside_own_scope')
        ->and(mpsSet(1, $lead, $target, [$small], CategoryScopes::PRODUCTS))->toBeNull()
        // not limited on posts: any post category
        ->and(mpsSet(1, $lead, $target, [$news], CategoryScopes::POSTS))->toBeNull()
        ->and(mpsRows(1, $target))->toBe([$small->id, $news->id]);
});

it('refuses at the database to change a category\'s vocabulary, and lets an update keep it', function (): void {
    $news = mpsCategory(1, 'Tin tức');

    mpsIn(1, function () use ($news): void {
        // in a savepoint of its own: the refused statement aborts only that
        expect(fn () => DB::transaction(fn () => Taxonomy::query()->whereKey($news->id)->update(['vocabulary' => 'product_cat'])))
            ->toThrow(QueryException::class, 'never changes')
            // naming the column with the same value, or changing another column, passes
            ->and(Taxonomy::query()->whereKey($news->id)->update(['vocabulary' => 'post_cat', 'position' => 3]))->toBe(1);
    });
    // whatever the role: the table owner too
    expect(fn () => DB::connection('pgsql_admin')->table('taxonomies')->where('id', $news->id)->update(['vocabulary' => 'tag']))
        ->toThrow(QueryException::class, '23514')
        ->and(DB::connection('pgsql_admin')->table('taxonomies')->where('id', $news->id)->value('vocabulary'))->toBe('post_cat');
});

it('drops the vocabulary guard on rollback and puts it back', function (): void {
    $migration = require database_path('migrations/2074_01_01_000000_keep_taxonomy_vocabulary_fixed.php');
    $admin = DB::connection('pgsql_admin');
    $guard = fn (): array => [
        'trigger' => $admin->selectOne("select count(*) as n from pg_trigger where tgname = 'taxonomies_keep_vocabulary' and tgrelid = 'public.taxonomies'::regclass")->n,
        'function' => $admin->selectOne("select to_regproc('public.taxonomies_keep_vocabulary') is not null as e")->e,
    ];
    $news = mpsCategory(1, 'Tin tức');
    // a post limit, as every limit was before this release: up() runs past it
    mpsLimit(1, mpsMember(1, 'writer', 30, ['entries.create']), $news);

    $admin->transaction(function () use ($migration, $admin, $guard, $news): void {
        DB::setDefaultConnection('pgsql_admin');
        try {
            $migration->down();
            expect($guard())->toBe(['trigger' => 0, 'function' => false])
                ->and($admin->table('taxonomies')->where('id', $news->id)->update(['vocabulary' => 'tag']))->toBe(1);
            $admin->table('taxonomies')->where('id', $news->id)->update(['vocabulary' => 'post_cat']);
            $migration->up();
        } finally {
            DB::setDefaultConnection('pgsql');
        }
    });

    expect($guard())->toBe(['trigger' => 1, 'function' => true])
        ->and(fn () => $admin->table('taxonomies')->where('id', $news->id)->update(['vocabulary' => 'tag']))->toThrow(QueryException::class);
})->skip(getenv('CI') !== 'true', 'drops and re-creates a trigger: runs on CI\'s own database only');

it('keeps a member limited on products inside their product categories when they change the tree', function (): void {
    $moulds = mpsCategory(1, 'Khuôn', null, 'product_cat');
    $small = mpsCategory(1, 'Khuôn nhỏ', $moulds, 'product_cat');
    $jigs = mpsCategory(1, 'Đồ gá', null, 'product_cat');
    $news = mpsCategory(1, 'Tin tức');
    $lead = mpsCatalogManager();
    mpsLimit(1, $lead, $moulds);
    test()->actingAs($lead);
    $create = fn (?Taxonomy $parent, string $vocabulary = 'product_cat') => fn () => app(CreateTaxonomy::class)->handle($vocabulary, $parent, new TaxonomyData(names: ['vi' => 'Mới '.uniqid()]));

    expect(mpsTreeRefusal($create($moulds)))->toBeNull()
        ->and(mpsTreeRefusal($create(null)))->toBe('outside_category_limits')
        ->and(mpsTreeRefusal($create($jigs)))->toBe('outside_category_limits')
        // not limited on posts: post categories are theirs to change
        ->and(mpsTreeRefusal($create(null, 'post_cat')))->toBeNull()
        ->and(mpsTreeRefusal($create($news, 'post_cat')))->toBeNull()
        ->and(mpsTreeRefusal(fn () => app(MoveTaxonomy::class)->handle($small, $jigs, 0)))->toBe('outside_category_limits')
        ->and(mpsTreeRefusal(fn () => app(MoveTaxonomy::class)->handle($jigs, $moulds, 0)))->toBe('outside_category_limits')
        // the category the limit names never moves or goes
        ->and(mpsTreeRefusal(fn () => app(MoveTaxonomy::class)->handle($moulds, null, 0)))->toBe('outside_category_limits')
        ->and(mpsTreeRefusal(fn () => app(DeleteTaxonomy::class)->handle($jigs, CategoryItemsOnDelete::Detach)))->toBe('outside_category_limits')
        ->and(mpsTreeRefusal(fn () => app(DeleteTaxonomy::class)->handle($moulds, CategoryItemsOnDelete::Detach, null, CategoryChildrenOnDelete::Delete)))->toBe('outside_category_limits')
        // what the screen offers on a row answers the same
        ->and(mpsIn(1, fn (): array => [
            Gate::forUser($lead)->allows('update', $jigs), Gate::forUser($lead)->allows('update', $small), Gate::forUser($lead)->allows('delete', $moulds),
        ]))->toBe([false, true, false]);
});

it('judges every category of a moved or deleted branch on its own axis, in a mixed tree', function (): void {
    $shop = mpsCategory(1, 'Sản phẩm');
    $jigs = mpsProductUnder(1, $shop, 'Đồ gá');
    $other = mpsCategory(1, 'Khác');
    $moulds = mpsCategory(1, 'Khuôn', null, 'product_cat');
    $outsider = mpsCatalogManager();
    mpsLimit(1, $outsider, $moulds);            // limited on products, not on posts
    $insider = mpsCatalogManager();
    mpsLimit(1, $insider, $jigs);

    test()->actingAs($outsider);
    // the post category is theirs, but it takes a product category outside their limits along
    expect(mpsTreeRefusal(fn () => app(MoveTaxonomy::class)->handle($shop, $other, 0)))->toBe('outside_category_limits')
        ->and(mpsTreeRefusal(fn () => app(DeleteTaxonomy::class)->handle($shop, CategoryItemsOnDelete::Detach, null, CategoryChildrenOnDelete::Delete)))->toBe('outside_category_limits')
        ->and(DB::connection('pgsql_admin')->table('taxonomies')->whereIn('id', [$shop->id, $jigs->id])->whereNull('parent_id')->count())->toBe(1);

    test()->actingAs($insider);
    expect(mpsTreeRefusal(fn () => app(MoveTaxonomy::class)->handle($shop, $other, 0)))->toBeNull()
        ->and((int) DB::connection('pgsql_admin')->table('taxonomies')->where('id', $shop->id)->value('parent_id'))->toBe($other->id);
});

it('hands out product limits with an invitation, on their own axis, and refreshes one axis without the other', function (): void {
    $owner = mpsMember(1, 'owner');
    mpsFeatureOn(1);
    $news = mpsCategory(1, 'Tin tức');
    $moulds = mpsCategory(1, 'Khuôn', null, 'product_cat');
    $jigs = mpsCategory(1, 'Đồ gá', null, 'product_cat');
    $writer = mpsRole(1, 'shop-writer', 30, ['catalog.view', 'catalog.manage', 'entries.create']);

    mpsInvite(1, $owner, 'moi@example.test', $writer, [], [$jigs, $moulds]);
    expect(mpsInvitationRows('moi@example.test'))->toBe([$moulds->id, $jigs->id])
        ->and(AuditLog::query()->where('action', 'cms.category_scopes.invited')->value('meta'))
        ->toMatchArray(['before' => [], 'after' => [$moulds->id, $jigs->id], 'vocabulary' => 'product_cat']);

    // the post axis given, the product axis not: the product limits stay
    $this->travel(2)->minutes();
    mpsInvite(1, $owner, 'moi@example.test', $writer, [$news], null);
    expect(mpsInvitationRows('moi@example.test'))->toBe([$news->id, $moulds->id, $jigs->id]);
    $this->travel(2)->minutes();
    mpsInvite(1, $owner, 'moi@example.test', $writer, [$news], [$jigs]);
    expect(mpsInvitationRows('moi@example.test'))->toBe([$news->id, $jigs->id]);
    // lifting the product axis keeps the post one
    $this->travel(1)->days();   // an address is mailed at most three times a day
    mpsInvite(1, $owner, 'moi@example.test', $writer, [$news], []);
    expect(mpsInvitationRows('moi@example.test'))->toBe([$news->id])
        ->and(mpsInviteRefusal(1, $owner, 'khac@example.test', $writer, [], [$news]))->toBe('taxonomy_invalid')
        ->and(mpsInviteRefusal(1, $owner, 'khac@example.test', $writer, [$moulds], null))->toBe('taxonomy_invalid')
        ->and(mpsInvitationRows('khac@example.test'))->toBe([]);
});

it('keeps an inviter limited on products inside their product limits, and never lets them hand out every product', function (): void {
    mpsFeatureOn(1);
    $moulds = mpsCategory(1, 'Khuôn', null, 'product_cat');
    $small = mpsCategory(1, 'Khuôn nhỏ', $moulds, 'product_cat');
    $jigs = mpsCategory(1, 'Đồ gá', null, 'product_cat');
    $lead = mpsMember(1, 'lead', 60, ['users.invite', 'users.manage', 'catalog.view', 'catalog.manage', 'entries.create', 'forms.view', 'domains.view']);
    mpsLimit(1, $lead, $moulds);
    $writer = mpsRole(1, 'shop-writer', 30, ['catalog.view', 'catalog.manage']);
    $viewer = mpsRole(1, 'shop-viewer', 10, ['catalog.view']);

    expect(mpsInviteRefusal(1, $lead, 'a@example.test', $writer, [], [$jigs]))->toBe('outside_own_scope')
        ->and(mpsInviteRefusal(1, $lead, 'a@example.test', $writer, [], []))->toBe('outside_own_scope')
        ->and(mpsInviteRefusal(1, $lead, 'a@example.test', $writer, [], null))->toBe('outside_own_scope')
        ->and(mpsInviteRefusal(1, $lead, 'a@example.test', $writer, [], [$small]))->toBeNull()
        ->and(mpsInvitationRows('a@example.test'))->toBe([$small->id])
        // someone who manages no products needs no product limit
        ->and(mpsInviteRefusal(1, $lead, 'b@example.test', $viewer, [], null))->toBeNull()
        ->and(mpsInvitationRows('b@example.test'))->toBe([]);
});

it('copies each axis of an invitation on accept, never letting one wipe the other', function (): void {
    $owner = mpsMember(1, 'owner');
    mpsFeatureOn(1);
    $news = mpsCategory(1, 'Tin tức');
    $events = mpsCategory(1, 'Sự kiện');
    $moulds = mpsCategory(1, 'Khuôn', null, 'product_cat');
    $writer = mpsRole(1, 'shop-writer', 30, ['catalog.view', 'catalog.manage', 'entries.create']);

    $fresh = mpsInvite(1, $owner, 'moi@example.test', $writer, [$news], [$moulds]);
    $person = User::factory()->create(['email' => 'da-co@example.test']);
    $known = mpsInvite(1, $owner, 'da-co@example.test', $writer, [$news], [$moulds]);
    // a member already, limited on posts by someone allowed to: those stay, the product limit is added
    app(Tenancy::class)->enter(1, fn () => $person->assignRole($writer));
    mpsLimit(1, $person, $events);
    auth()->logout();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');

    $user = mpsIn(1, fn (): User => app(AcceptInvitation::class)->withNewAccount($fresh->token, 'Nguyễn Văn An', 'Mat-khau-rat-manh-2026'));
    mpsIn(1, fn () => app(AcceptInvitation::class)->asSignedInUser($known->token, $person));

    expect(mpsRows(1, $user))->toBe([$news->id, $moulds->id])
        ->and(mpsRows(1, $person))->toBe([$events->id, $moulds->id])
        ->and(AuditLog::query()->where('action', 'cms.category_scopes.changed')->orderBy('id')->get()->map(fn (AuditLog $log): array => [$log->auditable_id, $log->meta['vocabulary'] ?? null])->all())
        ->toBe([[$user->id, 'post_cat'], [$user->id, 'product_cat'], [$person->id, 'product_cat']]);
});

it('keeps a member limited on any axis out of the shared block library', function (): void {
    $moulds = mpsCategory(1, 'Khuôn', null, 'product_cat');
    $lead = mpsCatalogManager();
    $free = mpsCatalogManager();
    mpsLimit(1, $lead, $moulds);

    mpsIn(1, function () use ($lead, $free): void {
        expect(Gate::forUser($lead)->allows('manage', ContentBlock::class))->toBeFalse()
            ->and(Gate::forUser($free)->allows('manage', ContentBlock::class))->toBeTrue();
    });
});

it('refuses an invitation on accept when its inviter has since been limited on an axis the roles work on', function (): void {
    mpsFeatureOn(1);
    $moulds = mpsCategory(1, 'Khuôn', null, 'product_cat');
    $lead = mpsMember(1, 'lead', 60, ['users.invite', 'users.manage', 'catalog.view', 'catalog.manage', 'entries.create', 'forms.view', 'domains.view']);
    $writer = mpsRole(1, 'shop-writer', 30, ['catalog.view', 'catalog.manage']);
    $viewer = mpsRole(1, 'shop-viewer', 10, ['catalog.view']);

    // sent while the lead worked in every product category: no product limit asked for
    $email = 'moi-'.Str::lower(Str::random(8)).'@example.test';
    $open = mpsInvite(1, $lead, $email, $writer, [], null);
    $looker = mpsInvite(1, $lead, 'xem-'.Str::lower(Str::random(8)).'@example.test', $viewer, [], null);
    mpsLimit(1, $lead, $moulds);
    auth()->logout();

    expect(fn () => mpsIn(1, fn (): User => app(AcceptInvitation::class)->withNewAccount($open->token, 'Nguyễn Văn An', 'Mat-khau-rat-manh-2026')))
        ->toThrow(InvitationRejectedException::class, 'no_longer_valid')
        ->and(User::query()->where('email', $email)->exists())->toBeFalse();

    // a role that manages no products needs no product limit
    $user = mpsIn(1, fn (): User => app(AcceptInvitation::class)->withNewAccount($looker->token, 'Lê Hoa', 'Mat-khau-rat-manh-2026'));
    expect(mpsRows(1, $user))->toBe([]);
});

it('tells which axes a limited actor would leave open for a member', function (): void {
    $news = mpsCategory(1, 'Tin tức');
    $moulds = mpsCategory(1, 'Khuôn', null, 'product_cat');
    $lead = mpsMember(1, 'lead', 60, ['users.manage', 'catalog.manage', 'entries.create']);
    mpsLimit(1, $lead, $news, $moulds);
    $free = mpsMember(1, 'free-lead', 60, ['users.manage', 'catalog.manage', 'entries.create']);
    $both = mpsRole(1, 'both', 30, ['catalog.manage', 'entries.create']);
    $target = mpsMember(1, 'both', 30, ['catalog.manage', 'entries.create']);
    $high = mpsMember(1, 'site-admin', 70, ['catalog.manage', 'entries.create']);
    $open = fn (User $actor, User $member): array => mpsIn(1, fn (): array => DB::transaction(
        fn (): array => app(SetMemberCategoryScopes::class)->axesLeftOpen($actor->fresh(), $member->fresh(), [$both->fresh()]),
    ));

    expect($open($lead, $target))->toBe([CategoryScopes::POSTS, CategoryScopes::PRODUCTS])
        ->and($open($free, $target))->toBe([])
        ->and($open($lead, $high))->toBe([]);
    mpsLimit(1, $target, $moulds);
    expect($open($lead, $target))->toBe([CategoryScopes::POSTS]);
});

it('refuses to add the vocabulary guard while a limit names a category that is not a post category', function (): void {
    $migration = require database_path('migrations/2074_01_01_000000_keep_taxonomy_vocabulary_fixed.php');
    $admin = DB::connection('pgsql_admin');
    mpsFeatureOn(1);
    $news = mpsCategory(1, 'Tin tức');
    $moulds = mpsCategory(1, 'Khuôn', null, 'product_cat');
    $member = mpsMember(1, 'writer', 30, ['entries.create']);
    mpsLimit(1, $member, $news);
    // what a post limit on a category re-typed afterwards looks like
    mpsLimit(1, $member, $moulds);
    $up = function () use ($migration, $admin): void {
        $admin->transaction(function () use ($migration): void {
            DB::setDefaultConnection('pgsql_admin');
            try {
                $migration->up();
            } finally {
                DB::setDefaultConnection('pgsql');
            }
        });
    };

    // the refusal comes before any statement that changes the schema
    expect($up)->toThrow(RuntimeException::class, 'Refused: 1 category limit row(s)');

    $admin->table('member_category_scopes')->where('taxonomy_id', $moulds->id)->delete();
    $owner = mpsMember(1, 'owner');
    $invited = mpsInvite(1, $owner, 'gioi-han-'.Str::lower(Str::random(8)).'@example.test', mpsRole(1, 'writer'), [$news], null);
    $admin->table('invitation_category_scopes')->insert(['site_id' => 1, 'invitation_id' => $invited->invitation->id, 'taxonomy_id' => $moulds->id]);

    expect($up)->toThrow(RuntimeException::class, 'Refused: 1 category limit row(s)');
});

/** Site 1's custom role $name (not a system one), at $level with $permissions; the site may use every module. */
function mpsCustomRole(string $name, int $level, array $permissions): Role
{
    DB::connection('pgsql_admin')->table('sites')->where('id', 1)
        ->update(['site_type_id' => DB::table('site_types')->where('key', 'corporate')->value('id')]);
    $role = mpsRole(1, $name, $level, $permissions);
    DB::connection('pgsql_admin')->table('roles')->where('id', $role->id)->update(['is_system' => false]);

    return $role->fresh() ?? $role;
}

/** $actor gives the custom role $role exactly $permissions; the refusal reason or null. */
function mpsUpdateRole(User $actor, Role $role, array $permissions): ?string
{
    try {
        mpsIn(1, fn () => app(UpdateCustomRole::class)->handle($actor->fresh() ?? $actor, $role->fresh() ?? $role, Cast::string($role->name), $role->level, $permissions));
    } catch (RoleChangeRejectedException $e) {
        return $e->reason;
    }

    return null;
}

/** @return list<string> */
function mpsRolePermissions(Role $role): array
{
    return mpsIn(1, fn (): array => ($role->fresh() ?? $role)->permissions->pluck('name')->sort()->values()->all());
}

it('never lets a member limited on an axis make a custom role work on that axis', function (): void {
    $news = mpsCategory(1, 'Tin tức');
    $moulds = mpsCategory(1, 'Khuôn', null, 'product_cat');
    $perms = ['roles.manage', 'users.manage', 'entries.view', 'entries.create', 'catalog.view', 'catalog.manage', 'media.view'];
    $postsLead = mpsMember(1, 'posts-lead', 60, $perms);
    mpsLimit(1, $postsLead, $news);
    $shopLead = mpsMember(1, 'shop-lead', 60, $perms);
    mpsLimit(1, $shopLead, $moulds);
    $helper = mpsCustomRole('helper', 20, ['media.view']);

    // posts: refused to the member limited on posts, nothing written
    expect(mpsUpdateRole($postsLead, $helper, ['media.view', 'entries.create']))->toBe('outside_category_scope')
        ->and(mpsRolePermissions($helper))->toBe(['media.view'])
        ->and(__('rbac.role_change.outside_category_scope'))->not->toBe('rbac.role_change.outside_category_scope');
    // products: refused to the member limited on products, nothing written
    expect(mpsUpdateRole($shopLead, $helper, ['media.view', 'catalog.manage']))->toBe('outside_category_scope')
        ->and(mpsRolePermissions($helper))->toBe(['media.view']);
    // each is judged on their own axis: the other axis is not theirs to keep
    expect(mpsUpdateRole($shopLead, $helper, ['media.view', 'entries.create']))->toBeNull()
        ->and(mpsRolePermissions($helper))->toBe(['entries.create', 'entries.view', 'media.view']);
});

it('lets a member who is not limited add work on any axis, and a limited one remove it or keep it', function (): void {
    $news = mpsCategory(1, 'Tin tức');
    $perms = ['roles.manage', 'users.manage', 'entries.view', 'entries.create', 'catalog.view', 'catalog.manage', 'media.view', 'media.upload'];
    $free = mpsMember(1, 'free-lead', 60, $perms);
    $limited = mpsMember(1, 'posts-lead', 60, $perms);
    mpsLimit(1, $limited, $news);
    $helper = mpsCustomRole('helper', 20, ['media.view']);

    expect(mpsUpdateRole($free, $helper, ['media.view', 'entries.create', 'catalog.manage']))->toBeNull()
        ->and(mpsRolePermissions($helper))->toBe(['catalog.manage', 'catalog.view', 'entries.create', 'entries.view', 'media.view']);
    // the role works on posts already: what it adds there widens nobody it did not already
    expect(mpsUpdateRole($limited, $helper, ['media.view', 'media.upload', 'entries.create', 'catalog.manage']))->toBeNull()
        ->and(mpsRolePermissions($helper))->toBe(['catalog.manage', 'catalog.view', 'entries.create', 'entries.view', 'media.upload', 'media.view']);
    // removing is always allowed
    expect(mpsUpdateRole($limited, $helper, ['media.view']))->toBeNull()
        ->and(mpsRolePermissions($helper))->toBe(['media.view'])
        ->and(DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'rbac.role.updated')->count())->toBe(3);
});
