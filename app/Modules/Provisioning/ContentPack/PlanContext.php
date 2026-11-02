<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

/** What every verdict of one planning run reads: the pack, the ledger's items, the forced keys. */
final readonly class PlanContext
{
    /**
     * @param  array<string, array{hash: string, ref: string}>  $ledger
     * @param  list<string>  $forced
     */
    public function __construct(
        public Pack $pack,
        public array $ledger,
        public array $forced,
    ) {}
}
