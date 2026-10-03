<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Tenancy\Domains\DnsLookup;

/**
 * Public DNS for tests: answers only what a test put in. The platform's target host resolves to
 * 203.0.113.10 unless a test says otherwise.
 */
final class FakeDns implements DnsLookup
{
    public const string PLATFORM_IP = '203.0.113.10';

    /** @var array<string, list<string>> */
    public array $addresses = [];

    /** @var array<string, list<string>> */
    public array $txt = [];

    public static function install(): self
    {
        $dns = new self;
        $dns->addresses[(string) config('tenancy.custom_domains.target')] = [self::PLATFORM_IP];
        app()->instance(DnsLookup::class, $dns);

        return $dns;
    }

    /** The domain resolves to the platform's server. */
    public function pointHere(string ...$hosts): self
    {
        foreach ($hosts as $host) {
            $this->addresses[$host] = [self::PLATFORM_IP];
        }

        return $this;
    }

    #[\Override]
    public function addresses(string $host): array
    {
        return $this->addresses[$host] ?? [];
    }

    #[\Override]
    public function txt(string $host): array
    {
        return $this->txt[$host] ?? [];
    }
}
