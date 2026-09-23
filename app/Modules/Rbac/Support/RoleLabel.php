<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Support;

use App\Modules\Rbac\Models\Role;

/**
 * The name people read for a role. System roles carry a stable technical name (`admin`,
 * `editor`…) that must never reach a screen or a mail; they are shown by their translated label
 * (`rbac.role.<name>`). A custom role is shown by the name its site gave it.
 */
final class RoleLabel
{
    public static function for(Role $role): string
    {
        return self::forName($role->name, $role->is_system);
    }

    /** For callers that carry a role as plain data (a queued mail renders it in its own locale). */
    public static function forName(string $name, bool $isSystem): string
    {
        if (! $isSystem) {
            return $name;
        }

        $key = 'rbac.role.'.$name;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $name;
    }
}
