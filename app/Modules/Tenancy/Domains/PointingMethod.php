<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domains;

/**
 * How a customer pointed their domain at the platform (design.md §28). Alias follows the target
 * host by name (CNAME, ALIAS, CNAME flattening) and survives a server move; ARecord copies the
 * server's IP and must be changed by hand when the server moves — the rows to warn beforehand.
 */
enum PointingMethod: string
{
    case Alias = 'alias';
    case ARecord = 'a_record';
}
