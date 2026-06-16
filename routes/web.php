<?php

use App\Modules\Identity\Models\User;
use App\Modules\Storefront\Http\Controllers\StorefrontController;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Http\Middleware\SetCurrentSite;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * Two-plane routing (P0.8) + dynamic storefront render (P1.3). Order matters: the
 * storefront catch-all is LAST so the platform/admin/login routes win first.
 */

// ── Platform plane (Bảo Sơn operator) — dedicated host, NO tenant context ──────
Route::domain((string) config('tenancy.platform_host'))
    ->middleware(['web', 'auth', 'platform', '2fa'])
    ->group(function (): void {
        Route::get('/', function () {
            $user = Auth::user();

            return response()->json([
                'plane' => 'platform',
                'operator' => $user instanceof User ? $user->email : null,
            ]);
        })->name('platform.dashboard');
    });

// ── Tenant admin plane — /admin on the tenant's own domain (auth + tenant context) ─
Route::prefix('admin')
    ->middleware(['web', SetCurrentSite::class, 'site.active', 'auth'])
    ->group(function (): void {
        Route::get('/', fn () => response()->json([
            'plane' => 'admin',
            'site_id' => app(CurrentSite::class)->id(),
        ]))->name('admin.dashboard');
    });

// ── Auth + 2FA-setup redirect targets (stubs until P1 UIs; precede the catch-all) ─
Route::get('/login', fn () => response()->json(['message' => 'Authentication required.'], 401))
    ->name('login');

Route::get('/two-factor/setup', fn () => response()->json(['message' => 'Two-factor authentication setup required.'], 423))
    ->name('two-factor.setup');

// ── Storefront plane — public, tenant context. A fallback so it never shadows a
//    defined route; resolves dynamic content for any other path (404 if none). ─────
Route::fallback(StorefrontController::class)
    ->middleware([SetCurrentSite::class, 'site.active']);
