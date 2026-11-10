<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domains;

use Carbon\CarbonImmutable;

/**
 * One name on a site's Domains screen (design.md §28): the platform subdomain, or a customer's
 * name with its two spellings (with and without `www.`), the one that runs and the one that
 * redirects to it. `state` is the one thing to know about the name:
 *
 *   wait       attached, not pointed here yet (dropped after a week if it never is);
 *   claim      pointed here, but another site holds the name: its owner must prove it is theirs;
 *   verified   pointed here, HTTPS not on yet;
 *   issuing    the certificate is on its way;
 *   https_err  the certificate could not be issued (`httpsError` says why);
 *   active     live, with HTTPS;
 *   failing    live, but no longer pointed here (`until` is when it stops being served);
 *   expiring   failing, with a week or less left (`daysLeft`);
 *   lost       stopped being served after a month of failing; checking it again brings it back.
 *
 * The other spelling has its own state too: `altVerified` (it points here and is this site's),
 * `altHttps` (its certificate is on) and `altClaim` (another site holds it as well, so it needs its
 * own TXT proof — the proof is per hostname, whatever the running spelling's state).
 * `byNumber` / `byName` are the owner's stored answer for "@": by the server's number, or moved to
 * a place that points by name; neither while they have not said.
 */
final readonly class DomainGroup
{
    public function __construct(
        public string $name,
        public bool $platform,
        public string $state,
        public string $runs,
        public ?string $alt,
        public bool $primary,
        public bool $altOptional = false,
        public ?int $daysLeft = null,
        public ?CarbonImmutable $removeOn = null,
        public ?CarbonImmutable $until = null,
        public ?string $httpsError = null,
        public bool $byNumber = false,
        public bool $byName = false,
        public bool $altVerified = false,
        public bool $altHttps = false,
        public bool $altClaim = false,
    ) {}

    /** The `www.` spelling runs. */
    public function www(): bool
    {
        return str_starts_with($this->runs, 'www.');
    }

    /**
     * Only the other spelling needs its own TXT line (another site holds it); the running one is not
     * contested, whatever its state (design 21: the "half" proof block, with its own "Kiểm tra lại").
     */
    public function altClaimOnly(): bool
    {
        return $this->altClaim && $this->state !== 'claim';
    }

    /** A check may still change what the screen says: the name waits, or another site holds it. */
    public function checkable(): bool
    {
        return in_array($this->state, ['wait', 'claim', 'failing', 'expiring', 'lost'], true);
    }

    /**
     * What one spelling's row says: ok · pointed · issuing · wait · nolock · lost · failing ·
     * optional (the redirecting spelling of a live name, not pointed yet — it may be pointed later).
     */
    public function rowState(bool $running): string
    {
        if (! $running && $this->altClaim && $this->state !== 'claim') {
            return 'claim';
        }

        if (! $running && $this->altOptional) {
            return 'optional';
        }

        return match ($this->state) {
            'active' => 'ok',
            'verified' => 'pointed',
            'issuing' => 'issuing',
            'wait', 'claim' => 'wait',
            'https_err' => 'nolock',
            'lost' => 'lost',
            default => 'failing',
        };
    }
}
