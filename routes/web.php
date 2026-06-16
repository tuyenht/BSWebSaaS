<?php

use App\Modules\Admin\Livewire\Dashboard;
use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index as EntriesIndex;
use App\Modules\Admin\Livewire\Login;
use App\Modules\Identity\Models\User;
use App\Modules\Storefront\Http\Controllers\StorefrontController;
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

// ── Tenant admin — login (guest, tenant context) ──────────────────────────────
Route::prefix('admin')
    ->middleware(['web', SetCurrentSite::class, 'site.active'])
    ->group(function (): void {
        Route::get('/login', Login::class)->name('admin.login');
    });

// ── Tenant admin plane — protected (auth + tenant context + site membership) ───
Route::prefix('admin')
    ->middleware(['web', SetCurrentSite::class, 'site.active', 'auth', 'tenant.member'])
    ->group(function (): void {
        Route::get('/', Dashboard::class)->name('admin.dashboard');

        // Pages CRUD (W4). Addressed by ulid (D2: never the raw bigint id).
        Route::get('/pages', EntriesIndex::class)->name('admin.entries.index');
        Route::get('/pages/create', Editor::class)->name('admin.entries.create');
        Route::get('/pages/{ulid}/edit', Editor::class)->name('admin.entries.edit');

        Route::post('/logout', function () {
            Auth::logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();

            return redirect()->route('admin.login');
        })->name('admin.logout');
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
