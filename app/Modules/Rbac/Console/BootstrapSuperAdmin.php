<?php

namespace App\Modules\Rbac\Console;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Registry\PermissionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Genesis: create (or promote) the first Bảo Sơn operator as a platform super_admin.
 * The password is typed into the operator's terminal (secret prompt) — never passed
 * as an argument or pasted into a chat.
 */
final class BootstrapSuperAdmin extends Command
{
    protected $signature = 'rbac:bootstrap-super-admin {--email=} {--name=}';

    protected $description = 'Create or promote a platform super_admin operator.';

    public function handle(PermissionRegistrar $registrar): int
    {
        $this->call('rbac:sync');

        $email = $this->resolveInput('email', 'Operator email');
        $name = $this->resolveInput('name', 'Operator name', 'Operator');

        $user = User::query()->firstWhere('email', $email);

        if ($user === null) {
            $secret = $this->secret('Password');
            $password = is_string($secret) ? $secret : '';
            $confirm = $this->secret('Confirm password');
            if ($password === '' || $password !== (is_string($confirm) ? $confirm : '')) {
                $this->error('Passwords are empty or do not match.');

                return self::FAILURE;
            }

            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ])->save();

            $this->info("Created operator {$email}.");
        } else {
            $this->info("Operator {$email} already exists — promoting.");
        }

        $registrar->setPermissionsTeamId(PermissionRegistry::PLATFORM_TEAM_ID);
        $user->assignRole('super_admin');

        $this->info('Granted super_admin (platform scope).');

        return self::SUCCESS;
    }

    private function resolveInput(string $option, string $question, ?string $default = null): string
    {
        $value = $this->option($option);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $answer = $this->ask($question, $default);

        return is_string($answer) ? $answer : (string) $default;
    }
}
