<?php

namespace Database\Seeders;

use App\Modules\Admin\Support\Cast;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Registry\PermissionRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    // NB: do NOT mute model events here — User auto-fills its external `ulid` on the
    // `creating` event (HasUlidColumn). Muting events leaves ulid NULL and the very first
    // (operator) insert fails the not-null constraint. No seeded model has an observer that
    // would otherwise warrant silencing.

    /**
     * Seed the application's database.
     *
     * Genesis: the platform super_admin (Bảo Sơn operator) is the FIRST account — the
     * system's root authority, above every tenant — created before any tenant
     * provisioning. Credentials come from config/admin.php (env-sourced; never hardcoded,
     * this repo is public). With no password set, a random one is used and the operator
     * sets theirs via the password-reset flow.
     */
    public function run(): void
    {
        Artisan::call('rbac:sync');

        $email = Cast::string(config('admin.super_admin.email'), 'admin@baoson.net');
        $user = User::query()->firstWhere('email', $email);

        if ($user === null) {
            $envPassword = Cast::string(config('admin.super_admin.password'));
            $password = $envPassword !== ''
                ? $envPassword
                : (app()->environment('local') ? 'password' : Str::random(40));

            $user = User::query()->create([
                'name' => Cast::string(config('admin.super_admin.name'), 'Bảo Sơn Operator'),
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]);
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId(PermissionRegistry::PLATFORM_TEAM_ID);
        $user->assignRole('super_admin');

        // Local/CI convenience fixture only — never in production.
        if (! app()->environment('production')) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }
}
