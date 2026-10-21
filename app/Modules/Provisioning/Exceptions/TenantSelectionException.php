<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Exceptions;

use DomainException;

/**
 * Provisioning could not tell which customer a website belongs to, so nothing was created.
 * Each case names its translation (`provisioning.tenant_{reason}`) and the values that sentence
 * needs; the caller explains it in plain words. Never guessed past: a new customer is minted
 * only when asked for explicitly.
 */
final class TenantSelectionException extends DomainException
{
    /** @param  array<string, string|int>  $replace */
    private function __construct(public readonly string $reason, public readonly array $replace = [])
    {
        parent::__construct("Tenant selection failed: {$reason}.");
    }

    public static function required(): self
    {
        return new self('required');
    }

    public static function bothGiven(): self
    {
        return new self('both');
    }

    public static function notFound(string $reference): self
    {
        return new self('not_found', ['reference' => $reference]);
    }

    public static function ambiguous(string $reference, string $candidates): self
    {
        return new self('ambiguous', ['reference' => $reference, 'candidates' => $candidates]);
    }

    public static function didYouMean(string $reference, string $candidates): self
    {
        return new self('did_you_mean', ['reference' => $reference, 'candidates' => $candidates]);
    }

    public static function nameTooLong(int $max): self
    {
        return new self('name_too_long', ['max' => $max]);
    }

    /** A new customer was asked for, but customers with that name (in any letter case) already exist. */
    public static function nameExists(string $name, string $candidates): self
    {
        return new self('name_exists', ['name' => $name, 'candidates' => $candidates]);
    }

    /** The hostname already has a website, owned by another customer than the one chosen. */
    public static function mismatch(string $hostname, string $ownerUlid): self
    {
        return new self('mismatch', ['host' => $hostname, 'tenant' => $ownerUlid]);
    }

    /** A new customer was asked for, but the hostname already has a website (of $ownerUlid). */
    public static function hostTaken(string $hostname, string $ownerUlid): self
    {
        return new self('host_taken', ['host' => $hostname, 'tenant' => $ownerUlid]);
    }

    public function translationKey(): string
    {
        return 'provisioning.tenant_'.$this->reason;
    }
}
