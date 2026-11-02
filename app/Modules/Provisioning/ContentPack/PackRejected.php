<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

use RuntimeException;

/**
 * The loader refuses: the pack is unreadable or invalid, the plan changed, a conflict, a backup
 * that could not be written. Carries every reason, so the operator fixes them in one pass. Nothing
 * has been written when this is thrown (inside the apply transaction it rolls everything back).
 */
final class PackRejected extends RuntimeException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(string $message, public readonly array $reasons = [])
    {
        parent::__construct($message);
    }
}
