<?php

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Http\Middleware\SetCurrentSite;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * Two-plane routing skeleton (P0.8). Three planes, each with its own middleware
 * stack so future routes land in the right place. Concrete UIs (Livewire admin,
 * Blade storefront, operator console) land on top of these groups in P1.
 */

// ── Platform plane (Bảo Sơn operator) — dedicated host, NO tenant context ──────
Route::domain((string) config('tenancy.platform_host'))
    ->middleware(['web', 'auth', 'platform'])
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

// ── Storefront plane — public, tenant context ─────────────────────────────────
Route::middleware([SetCurrentSite::class, 'site.active'])
    ->group(function (): void {
        Route::get('/', fn () => response()->json([
            'plane' => 'storefront',
            'site_id' => app(CurrentSite::class)->id(),
        ]))->name('storefront.home');
    });

// Auth redirect target (stub until the login UI lands in P1).
Route::get('/login', fn () => response()->json(['message' => 'Authentication required.'], 401))
    ->name('login');
