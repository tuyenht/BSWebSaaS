<?php

use Illuminate\Support\Facades\File;

/*
 * A hostname becomes a site in exactly one place, Tenancy's SiteResolver (design.md §5, §28): it
 * holds the rule "only a verified domain resolves" and the cache. Code that read `domains` by
 * hostname on its own would resolve a name that is merely waiting on a site — and, once two sites
 * may wait for the same name, pick one of them at random. So nothing outside the Tenancy module
 * touches the Domain model, except ProvisionSite, which creates a site's platform subdomain.
 */

it('resolves hostnames only through SiteResolver', function (): void {
    $allowed = [
        'app/Modules/Provisioning/Actions/ProvisionSite.php',
    ];

    $violations = [];

    foreach (File::allFiles(base_path('app')) as $file) {
        $path = str_replace('\\', '/', $file->getRelativePathname());
        $relative = 'app/'.$path;

        if ($file->getExtension() !== 'php' || str_starts_with($relative, 'app/Modules/Tenancy/') || in_array($relative, $allowed, true)) {
            continue;
        }

        if (preg_match('/Tenancy\\\\Models\\\\Domain\b/', File::get($file->getPathname())) === 1) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([], 'Ask App\Modules\Tenancy\Context\SiteResolver instead of reading domains directly.');
});

it('keeps the hostname lookup inside SiteResolver within Tenancy', function (): void {
    $violations = [];

    foreach (File::allFiles(base_path('app/Modules/Tenancy')) as $file) {
        if ($file->getFilename() === 'SiteResolver.php') {
            continue;
        }

        if (preg_match('/[\'"]hostname[\'"]\s*,\s*\$/', File::get($file->getPathname())) === 1) {
            $violations[] = $file->getRelativePathname();
        }
    }

    expect($violations)->toBe([]);
});
