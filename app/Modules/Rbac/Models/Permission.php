<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Fine-grained permission (resource.action). Global catalog (one guard 'web');
 * what a role can do is the same everywhere — WHERE it applies is the team (site).
 */
class Permission extends SpatiePermission {}
