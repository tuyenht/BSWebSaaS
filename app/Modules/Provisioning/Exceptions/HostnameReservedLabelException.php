<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Exceptions;

/**
 * A provision refused because its hostname is `{label}.{base}` with a label kept for the
 * platform's own services (`tenancy.reserved_labels`: admin, api, mail, sgtm…). Still a refusal of
 * a non-platform name, so a caller catching HostnameNotPlatformSubdomainException catches this
 * too; the command catches it first to name the label. Nothing is written.
 */
final class HostnameReservedLabelException extends HostnameNotPlatformSubdomainException
{
    public function __construct(string $hostname, public readonly string $label)
    {
        parent::__construct($hostname, "The label {$label} of {$hostname} is reserved for the platform.");
    }
}
