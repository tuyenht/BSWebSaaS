<?php

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Hashing\PepperedArgon2IdHasher;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Hash::extend('peppered-argon', function (): PepperedArgon2IdHasher {
            $config = config('hashing');
            $config = is_array($config) ? $config : [];

            $current = is_string($config['pepper'] ?? null) ? $config['pepper'] : '';
            $previous = is_array($config['previous_peppers'] ?? null) ? $config['previous_peppers'] : [];
            /** @var array<string, mixed> $argon */
            $argon = is_array($config['argon'] ?? null) ? $config['argon'] : [];

            /** @var list<string> $peppers */
            $peppers = array_values(array_filter(
                array_merge([$current], array_map(
                    static fn ($p): string => is_scalar($p) ? (string) $p : '',
                    $previous,
                )),
                static fn (string $p): bool => $p !== '',
            ));

            return new PepperedArgon2IdHasher($peppers, $argon);
        });
    }
}
