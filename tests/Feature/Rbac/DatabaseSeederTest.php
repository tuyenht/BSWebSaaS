<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Genesis: running the database seeder creates the Bảo Sơn operator as a platform
 * super_admin — the first account, above every tenant.
 *
 * Regression: the seeder used to mute model events, which silenced the ulid auto-fill
 * hook (HasUlidColumn) and made the operator insert fail the `users.ulid` not-null
 * constraint — so the documented genesis path could never actually run.
 */

beforeEach(function () {
    truncateRbac();
    DB::connection('pgsql_admin')->statement('truncate users restart identity cascade');
});

it('seeds the platform operator with a ulid and the super_admin role', function () {
    $this->seed(DatabaseSeeder::class);

    $operator = User::query()->where('email', 'admin@baoson.net')->firstOrFail();

    expect($operator->ulid)->not->toBeNull()
        ->and(app(PlatformAccess::class)->isSuperAdmin($operator))->toBeTrue();
});
