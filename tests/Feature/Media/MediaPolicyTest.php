<?php

use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * MediaPolicy::forceDelete — deleting a file for good is the website admin's right (media.purge):
 * the shipped owner and admin templates hold it, the editor and the author do not, and a grant on
 * one site gives nothing on another site's file.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A member of site 1 holding exactly what the shipped role template of that name grants. */
function policyTemplateUser(string $role): User
{
    $spec = PermissionRegistry::tenantRoleTemplates('corporate')[$role]['permissions'];
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, $role, $spec === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $spec));

    return $user->fresh() ?? $user;
}

function policyTrashedMedia(int $site): Media
{
    return app(Tenancy::class)->enter($site, function () use ($site): Media {
        $media = Media::query()->create([
            'collection' => 'library',
            'slug' => "thung-rac-{$site}",
            'hash' => hash('sha256', "policy-{$site}"),
            'storage_key' => "s{$site}/library/thung-rac.png",
            'original_filename' => 'thung-rac.png',
            'mime' => 'image/png',
            'size' => 1024,
        ]);
        $media->delete();

        return $media;
    });
}

it('lets only the owner and the website admin delete a file for good', function (string $role, bool $allowed): void {
    $user = policyTemplateUser($role);
    $media = policyTrashedMedia(1);

    app(Tenancy::class)->enter(1, function () use ($user, $media, $allowed): void {
        expect($user->can('forceDelete', $media))->toBe($allowed);
    });
})->with([
    'owner' => ['owner', true],
    'admin' => ['admin', true],
    'editor' => ['editor', false],
    'author' => ['author', false],
]);

it('never lets a site 1 owner delete for good a file of another site', function (): void {
    $user = policyTemplateUser('owner');
    $theirs = policyTrashedMedia(2);

    app(Tenancy::class)->enter(2, function () use ($user, $theirs): void {
        expect($user->can('forceDelete', $theirs))->toBeFalse();
    });
});
