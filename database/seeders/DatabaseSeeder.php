<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Registry\PermissionRegistry;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Genesis: the platform super_admin (Bảo Sơn operator) is the FIRST account — the
     * system's root authority, above every tenant — created before any tenant
     * provisioning. Credentials come from env (never hardcoded; this repo is public):
     * SUPER_ADMIN_EMAIL / SUPER_ADMIN_NAME / SUPER_ADMIN_PASSWORD. With no password set,
     * a random one is used and the operator sets theirs via the password-reset flow.
     */
    public function run(): void
    {
        Artisan::call('rbac:sync');

        $email = (string) env('SUPER_ADMIN_EMAIL', 'admin@baoson.net');
        $user = User::query()->firstWhere('email', $email);

        if ($user === null) {
            $envPassword = (string) env('SUPER_ADMIN_PASSWORD', '');
            $password = $envPassword !== ''
                ? $envPassword
                : (app()->environment('local') ? 'password' : Str::random(40));

            $user = User::query()->create([
                'name' => (string) env('SUPER_ADMIN_NAME', 'Bảo Sơn Operator'),
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
