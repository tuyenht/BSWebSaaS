<?php

use Illuminate\Support\Facades\File;

/*
 * A hostname becomes a site in exactly one place, Tenancy's SiteResolver (design.md §5, §28): it
 * holds the rule "only a verified domain resolves" and the cache. Code that read `domains` by
 * hostname on its own would resolve a name that is merely waiting on a site — and, once two sites
 * may wait for the same name, pick one of them at random. So nothing outside the Tenancy module
 * touches the Domain model, except ProvisionSite, which creates a site's platform subdomain, and
 * the operator's site:domain command, which lists one site's rows.
 */

it('resolves hostnames only through SiteResolver', function (): void {
    $allowed = [
        'app/Modules/Provisioning/Actions/ProvisionSite.php',
        // Lists one site's rows by site_id for the operator; it resolves its host through SiteResolver.
        'app/Modules/Provisioning/Console/SiteDomainCommand.php',
        // Names the rows the domain actions hand back; it never queries them.
        'app/Modules/Admin/Support/SiteDomains.php',
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

it('keeps the request path of Tenancy on SiteResolver', function (): void {
    // The write actions (Tenancy\Actions) read rows by hostname to change them, under a lock. What
    // serves a request — middleware, controllers, context readers — must not: it asks SiteResolver.
    $violations = [];

    foreach (['Http', 'Context'] as $area) {
        foreach (File::allFiles(base_path('app/Modules/Tenancy/'.$area)) as $file) {
            if ($file->getFilename() === 'SiteResolver.php') {
                continue;
            }

            if (preg_match('/[\'"]hostname[\'"]\s*,\s*\$/', File::get($file->getPathname())) === 1) {
                $violations[] = $area.'/'.$file->getRelativePathname();
            }
        }
    }

    expect($violations)->toBe([]);
});

it('changes a site\'s domains only behind the permission check and the audit trail', function (): void {
    // The domain actions trust their caller to have asked "may this person?". Two callers do:
    // SiteDomains (screens and console, gated and audited) and the operator's command.
    $allowed = [
        'app/Modules/Admin/Support/SiteDomains.php',
        'app/Modules/Provisioning/Console/SiteDomainCommand.php',
    ];

    $violations = [];

    foreach (File::allFiles(base_path('app')) as $file) {
        $relative = 'app/'.str_replace('\\', '/', $file->getRelativePathname());

        if ($file->getExtension() !== 'php' || str_starts_with($relative, 'app/Modules/Tenancy/') || in_array($relative, $allowed, true)) {
            continue;
        }

        if (preg_match('/Tenancy\\\\Actions\\\\(AttachDomain|CheckDomain|ChooseRunningSpelling|EnableHttps|SetPrimaryDomain|RemoveDomain)\b/', File::get($file->getPathname())) === 1) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([], 'Change a site\'s domains through App\Modules\Admin\Support\SiteDomains.');
});
