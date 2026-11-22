<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;

/**
 * Who may change what only the website's owner changes (Cài đặt › Bảo mật & Nâng cao): a member
 * holding the owner role on the current site, or a platform operator. Everyone else who holds
 * settings.manage reads those settings «Chỉ xem».
 */
final class SiteOwner
{
    public const string ROLE = 'owner';

    public static function is(mixed $user): bool
    {
        return $user instanceof User && (app(PlatformAccess::class)->isSuperAdmin($user) || $user->hasRole(self::ROLE));
    }
}
