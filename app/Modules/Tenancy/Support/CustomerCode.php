<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Shared\Support\Cast;

/**
 * The code staff read for a customer ("KH-000123"): the configured prefix, then the customer's
 * number (tenants.customer_no) zero-padded to the configured width (config/customers.php). Only the
 * number is stored; the code is built here so a white-label deployment shows its own prefix.
 */
final class CustomerCode
{
    public static function format(int $customerNo): string
    {
        $prefix = Cast::string(config('customers.code_prefix'));
        $pad = max(1, Cast::int(config('customers.code_pad'), 1));

        return $prefix.str_pad((string) $customerNo, $pad, '0', STR_PAD_LEFT);
    }
}
