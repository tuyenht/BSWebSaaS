<?php

declare(strict_types=1);

namespace App\Modules\Theme\Theming;

/**
 * Everything the AI crawler check needs from the site, read in one short tenant step
 * (AiCrawlerCheck::prepare) so the outbound requests run with no database transaction open: the
 * address to check, the robots.txt the platform serves for it, the two search switches, whether
 * the platform itself sends `noindex` there, and the findings the settings alone give (checks 1-2).
 */
final readonly class AiCheckInput
{
    /**
     * @param  list<array{level: string, code: string, params: array<string, mixed>}>  $findings
     */
    public function __construct(
        public int $siteId,
        public string $host,
        public string $expectedRobots,
        public bool $indexable,
        public bool $sitemap,
        public bool $platformNoindex,
        public array $findings,
    ) {}
}
