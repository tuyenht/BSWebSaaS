<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Something happened to a website's own domain that its managers should hear about (design.md
 * §28). Tenancy only announces it — who is told, and how, is the admin's business. One event per
 * site and name: both spellings (with and without `www.`) travel together.
 *
 * Dispatched after the change commits, so a rolled-back change tells no one.
 */
final readonly class DomainNeedsAttention implements ShouldDispatchAfterCommit
{
    /**
     * @param  'failing'|'expiring'|'lost'|'recovered'|'wait_expired'|'taken_over'  $variant
     * @param  list<string>  $hostnames
     * @param  CarbonImmutable|null  $until  when a failing domain stops being served
     */
    public function __construct(
        public int $siteId,
        public string $variant,
        public array $hostnames,
        public ?CarbonImmutable $until = null,
    ) {}
}
