<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\ChangeProductExpiry;
use App\Modules\Catalog\Actions\ChangeProductStatus;
use App\Modules\Catalog\Actions\UnpublishExpiredProduct;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Exceptions\ExpiredContentException;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\StorefrontCards;

/*
 * A product's end time («Gỡ khỏi website lúc», products.expires_at): a visitor gets 404 from then
 * on, the edge keeps the page no longer than until then, and the every-minute sweep
 * (products:unpublish-due) archives it (out of the sitemap, the edge purged, a status audit row by
 * «scheduler»). Staff still preview it; it cannot go back online past its end; every change of a date
 * writes an audit row with from, to and who.
 */

const PEXP_HOST = 'http://mau.platform.test';

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    StorefrontCards::reset();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00:00', 'UTC'));
});

/** The provisioned site (catalog on) with one live product at /san-pham/may-han-tig ending at $expiresAt. */
function pexpSite(?CarbonImmutable $expiresAt): array
{
    $siteId = StorefrontCards::site();
    enableCatalog($siteId);
    $product = app(Tenancy::class)->enter($siteId, function () use ($expiresAt): Product {
        SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'products'], ['value' => ['pattern' => 'base', 'base' => 'san-pham']]);
        $product = Product::query()->create(['status' => 'published', 'published_at' => now()->subDay(), 'expires_at' => $expiresAt]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Máy hàn TIG', 'slug' => 'may-han-tig']);
        app(RouteSync::class)->syncProduct($product->id, null, 'vi', 'may-han-tig');

        return $product->refresh();
    });

    return [$siteId, $product];
}

function pexpManager(int $siteId, array $permissions = ['catalog.view', 'catalog.manage']): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($siteId, fn () => grantRole($user, 'role-'.md5(implode(',', $permissions)), $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

function pexpFresh(): void
{
    app()->forgetScopedInstances();
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
    app(TeamContext::class)->forget();
}

it('answers 404 to a visitor from the end time on, before the sweep, and keeps the page at the edge only until then', function (): void {
    pexpSite(CarbonImmutable::now()->addSeconds(90));

    $cache = (string) $this->get(PEXP_HOST.'/san-pham/may-han-tig')->assertOk()->headers->get('Cache-Control');
    expect($cache)->toContain('s-maxage=90')->toContain('stale-while-revalidate=0');

    $this->travel(2)->minutes();
    pexpFresh();
    $this->get(PEXP_HOST.'/san-pham/may-han-tig')->assertNotFound();
});

it('takes a due product down at the sweep: archived, out of the sitemap, the edge purged, a scheduler audit row', function (): void {
    [$siteId, $product] = pexpSite(CarbonImmutable::now()->addMinute());
    expect((string) $this->get(PEXP_HOST.'/sitemap.xml')->getContent())->toContain('/san-pham/may-han-tig');

    $this->travel(2)->minutes();
    pexpFresh();
    $edge = edgeCacheRecorder();
    $this->artisan('products:unpublish-due')->assertSuccessful();
    $this->artisan('products:unpublish-due')->assertSuccessful();

    expect($edge->calls)->not->toBe([]);
    app(Tenancy::class)->enter($siteId, function () use ($product): void {
        expect($product->fresh()->status)->toBe('archived');
        $row = AuditLog::query()->where('action', UnpublishExpiredProduct::STATUS_CHANGED)->sole();
        expect($row->actor_id)->toBeNull()
            ->and($row->actor_label)->toBe('scheduler')
            ->and($row->auditable_id)->toBe($product->id)
            ->and($row->meta)->toMatchArray(['from' => 'published', 'to' => 'archived', 'via' => 'scheduler', 'reason' => 'expired']);
    });

    pexpFresh();
    expect((string) $this->get(PEXP_HOST.'/sitemap.xml')->getContent())->not->toContain('/san-pham/may-han-tig');
});

it('leaves a product whose end time is still to come', function (): void {
    [$siteId, $product] = pexpSite(CarbonImmutable::now()->addHour());

    $this->artisan('products:unpublish-due')->assertSuccessful();

    app(Tenancy::class)->enter($siteId, fn () => expect($product->fresh()->status)->toBe('published')
        ->and(AuditLog::query()->where('action', UnpublishExpiredProduct::STATUS_CHANGED)->exists())->toBeFalse());
});

it('still shows the staff preview of a product past its end time', function (): void {
    [$siteId, $product] = pexpSite(CarbonImmutable::now()->subSeconds(30));
    $manager = pexpManager($siteId);

    $this->get(PEXP_HOST.'/san-pham/may-han-tig')->assertNotFound();
    pexpFresh();
    $this->actingAs($manager)->get(PEXP_HOST.'/admin/catalog/'.$product->ulid.'/preview')->assertOk()->assertSee('Máy hàn TIG');
});

it('refuses to put a product back online past its end time', function (): void {
    [$siteId, $product] = pexpSite(CarbonImmutable::now()->addMinute());
    $manager = pexpManager($siteId);
    $this->travel(2)->minutes();
    $this->artisan('products:unpublish-due')->assertSuccessful();

    Auth::login($manager);
    app(Tenancy::class)->enter($siteId, function () use ($product): void {
        expect(fn () => app(ChangeProductStatus::class)->handle($product->fresh(), 'published'))->toThrow(ExpiredContentException::class)
            ->and($product->fresh()->status)->toBe('archived');

        app(ChangeProductExpiry::class)->handle($product->fresh(), null);
        app(ChangeProductStatus::class)->handle($product->fresh(), 'published');
        expect($product->fresh()->status)->toBe('published');
    });
});

it('sets and clears an end time with one audit row each, and refuses a past one or a member who may not change products', function (): void {
    [$siteId, $product] = pexpSite(null);
    $manager = pexpManager($siteId);
    $viewer = pexpManager($siteId, ['catalog.view']);
    $at = CarbonImmutable::parse('2026-12-01 10:00:00', 'UTC');

    Auth::login($viewer);
    app(Tenancy::class)->enter($siteId, fn () => expect(fn () => app(ChangeProductExpiry::class)->handle($product, $at))->toThrow(AuthorizationException::class));

    Auth::login($manager);
    app(Tenancy::class)->enter($siteId, function () use ($product, $at, $manager): void {
        expect(fn () => app(ChangeProductExpiry::class)->handle($product, now()->subMinute()))->toThrow(ValidationException::class);
        app(ChangeProductExpiry::class)->handle($product, $at);
        app(ChangeProductExpiry::class)->handle($product, null);

        $rows = AuditLog::query()->where('action', Product::DATES_CHANGED)->orderBy('id')->get();
        expect($rows->map(fn (AuditLog $row): array => [$row->meta['field'], $row->meta['from'], $row->meta['to'], $row->meta['via'], $row->actor_id])->all())->toBe([
            ['expires_at', null, $at->toIso8601String(), 'admin', $manager->id],
            ['expires_at', $at->toIso8601String(), null, 'admin', $manager->id],
        ])->and($rows->first()->meta['ulid'])->toBe($product->ulid);
    });
});

it('records a change of the publish time', function (): void {
    [$siteId, $product] = pexpSite(null);
    $was = $product->published_at;

    app(Tenancy::class)->enter($siteId, function () use ($product, $was): void {
        $product->update(['published_at' => now()->subDays(3)]);

        $row = AuditLog::query()->where('action', Product::DATES_CHANGED)->sole();
        expect($row->meta)->toMatchArray(['field' => 'published_at', 'from' => $was->toIso8601String(), 'to' => now()->subDays(3)->toIso8601String(), 'via' => 'system'])
            ->and($row->actor_label)->toBe('system');
    });
});

it('reads the due products batch after batch until none is left', function (): void {
    [$siteId] = pexpSite(CarbonImmutable::now()->addMinute());
    app(Tenancy::class)->enter($siteId, function (): void {
        foreach (['may-han-mig', 'may-cat-plasma'] as $slug) {
            $product = Product::query()->create(['status' => 'published', 'published_at' => now()->subDay(), 'expires_at' => now()->addMinute()]);
            $product->translations()->create(['locale' => 'vi', 'name' => $slug, 'slug' => $slug]);
        }
    });
    $this->travel(2)->minutes();

    $this->artisan('products:unpublish-due', ['--batch' => 2])->assertSuccessful()->expectsOutputToContain('Took 3 product(s)');

    app(Tenancy::class)->enter($siteId, fn () => expect(Product::query()->where('status', 'published')->count())->toBe(0));
});

it('stores an end time given in the site\'s own zone as the same instant', function (): void {
    [$siteId, $product] = pexpSite(null);
    $manager = pexpManager($siteId);
    // 23:30 on 1/12 in Hà Nội is 16:30 UTC; written without its zone it would read 23:30 UTC, 7 hours late
    $local = CarbonImmutable::parse('2026-12-01 23:30:00', 'Asia/Ho_Chi_Minh');

    Auth::login($manager);
    app(Tenancy::class)->enter($siteId, function () use ($product, $local): void {
        app(ChangeProductExpiry::class)->handle($product, $local);

        expect(DB::selectOne("SELECT to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS at FROM products WHERE id = ?", [$product->id])->at)->toBe('2026-12-01 16:30');
    });
});
