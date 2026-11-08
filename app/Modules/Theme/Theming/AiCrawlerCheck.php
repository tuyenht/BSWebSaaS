<?php

declare(strict_types=1);

namespace App\Modules\Theme\Theming;

use App\Modules\Shared\Http\SafeHttp;
use App\Modules\Shared\Http\SafeHttpException;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * "Do AI bots reach the website?" — the check behind the SEO settings status line. It looks at the
 * site's own address only (the primary host SiteResolver serves its pages on, always https; never
 * a URL anyone typed) and makes at most six requests, each through the shared SSRF guard, held to
 * five seconds and to redirects on the same host: robots.txt, the home page as a browser, and the
 * home page as four representative AI crawlers (bodies discarded; robots.txt read up to 512 KB).
 *
 * What it reports, each finding at one level — `info` (worth knowing, often a choice the owner
 * made), `warning` (something the site did not ask for is in the way) or `unchecked` (nothing
 * could be concluded):
 *   1. the site is hidden from search engines (not "blocking AI");
 *   2. the site's own robots.txt text blocks a known bot (info: some owners mean to);
 *   3. the robots.txt actually served differs from the one the platform makes for this host in a
 *      way that blocks a bot — something in front of the site added it (warning); Content-Signal
 *      lines are named but block nothing (info);
 *   4. the browser does not get 200 (unchecked, nothing compared); a bot is refused while the
 *      browser is not — only info behind Cloudflare, which treats a bot name from a server IP as
 *      an impostor, a warning without it;
 *   5. a `noindex` X-Robots-Tag the platform did not set (warning); the platform's own on its
 *      temporary address (info);
 *   6. the address cannot be reached (unchecked); 7. a redirect to another host (unchecked).
 *
 * The result is kept 24 hours in the shared cache under the site id AND the host checked, so a new
 * primary address starts afresh and two sites never share one; saving the search settings drops it.
 * Run by CheckAiCrawlers: the site is read in a short tenant step, the requests run with no
 * transaction open.
 */
final readonly class AiCrawlerCheck
{
    /** Bump when the SHAPE of the cached value changes — the old value outlives the deploy. */
    private const string KEY = 'seo-ai-check:v1:';

    public const int TTL = 86400;

    /** How long a queued check counts as running (it also ends when the check does). */
    public const int RUNNING_TTL = 600;

    public const int ROBOTS_MAX_BYTES = 512 * 1024;

    /** Per request, in seconds, every redirect hop included. */
    public const float REQUEST_SECONDS = 5.0;

    /** What a bot refused at the door gets (Cloudflare's challenge pages included). */
    public const array REFUSED = [401, 402, 403, 429, 503];

    private const array LEVELS = ['ok' => 0, 'info' => 1, 'unchecked' => 2, 'warning' => 3];

    public function __construct(
        private SiteResolver $sites,
        private SiteSeo $seo,
        private Tenancy $tenancy,
        private SafeHttp $http,
        private Cache $cache,
    ) {}

    /** The address the check looks at: the site's primary host; null while it has none. */
    public function host(int $siteId): ?string
    {
        return $this->sites->primaryHostFor($siteId);
    }

    /**
     * The kept result for this site and host, or null (none yet, expired, or the cache is down).
     *
     * @return array{host: string, checked_at: int, level: string, findings: list<array{level: string, code: string, params: array<string, mixed>}>}|null
     */
    public function result(int $siteId, string $host): ?array
    {
        try {
            $value = $this->cache->get(self::key($siteId, $host));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
        if (! is_array($value) || ($value['host'] ?? null) !== $host || ! is_array($value['findings'] ?? null)) {
            return null;
        }

        $findings = [];
        foreach ($value['findings'] as $finding) {
            $finding = Cast::arr($finding);
            $findings[] = ['level' => Cast::string($finding['level'] ?? null), 'code' => Cast::string($finding['code'] ?? null), 'params' => Cast::arr($finding['params'] ?? null)];
        }

        return ['host' => $host, 'checked_at' => Cast::int($value['checked_at'] ?? null), 'level' => Cast::string($value['level'] ?? null), 'findings' => $findings];
    }

    /** Mark a check queued for the site; false when one already is. */
    public function markRunning(int $siteId): bool
    {
        try {
            return $this->cache->add(self::KEY.$siteId.':running', 1, self::RUNNING_TTL);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    public function isRunning(int $siteId): bool
    {
        try {
            return $this->cache->has(self::KEY.$siteId.':running');
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    public function clearRunning(int $siteId): void
    {
        try {
            $this->cache->forget(self::KEY.$siteId.':running');
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Check the site and keep the result, in three steps so no database transaction (nor a pooled
     * server connection) is held while the site is called: a short tenant step reads what the check
     * needs (prepare) and commits; the requests run outside any transaction; the result goes to the
     * cache. Call it outside a tenant context.
     *
     * @return array{host: string, checked_at: int, level: string, findings: list<array{level: string, code: string, params: array<string, mixed>}>}|null null while the site has no address
     */
    public function run(int $siteId): ?array
    {
        try {
            $input = $this->tenancy->enter($siteId, fn (): ?AiCheckInput => $this->prepare($siteId));
            if ($input === null) {
                return null;
            }

            $findings = $input->findings;
            try {
                $this->probe($input, $findings);
            } catch (SafeHttpException $e) {
                $findings[] = self::finding('unchecked', match ($e->reason) {
                    'host_unresolvable', 'fetch_unreachable' => 'not_pointing',
                    'private_address' => 'private_address',
                    'redirect_off_host' => 'redirect_off_host',
                    'too_many_redirects' => 'too_many_redirects',
                    default => 'bad_redirect',
                }, $e->host !== null ? ['host' => $e->host] : []);
            }

            $result = ['host' => $input->host, 'checked_at' => now()->getTimestamp(), 'level' => self::overall($findings), 'findings' => $findings];
            $this->cache->put(self::key($siteId, $input->host), $result, self::TTL);

            return $result;
        } finally {
            $this->clearRunning($siteId);
        }
    }

    /**
     * The tenant step: what the check needs from the site (the current context must be this site).
     * Null while the site has no address.
     */
    public function prepare(int $siteId): ?AiCheckInput
    {
        $host = $this->host($siteId);
        if ($host === null) {
            return null;
        }
        $settings = $this->seo->settings();
        $resolved = $this->sites->resolve($host);

        return new AiCheckInput(
            siteId: $siteId,
            host: $host,
            expectedRobots: $this->seo->robotsTxt('https://'.$host.'/sitemap.xml'),
            indexable: $settings['indexable'],
            sitemap: $settings['sitemap'],
            platformNoindex: $resolved !== null && $resolved->isPlatformSubdomain && Cast::bool(config('tenancy.noindex_platform_subdomains')),
            findings: $this->ownRobots(),
        );
    }

    /** Drop the kept result for this site and host (its search settings changed). */
    public function forget(int $siteId, string $host): void
    {
        try {
            $this->cache->forget(self::key($siteId, $host));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return list<string> every bot the check knows, crawlers first */
    public static function bots(): array
    {
        return [
            ...Cast::strings(config('seo.ai_crawlers.crawlers')),
            ...Cast::strings(config('seo.ai_crawlers.robots_only')),
            ...Cast::strings(config('seo.ai_crawlers.user_triggered')),
        ];
    }

    private static function key(int $siteId, string $host): string
    {
        return self::KEY.$siteId.':'.$host;
    }

    /**
     * Checks 1 and 2, from the settings alone.
     *
     * @return list<array{level: string, code: string, params: array<string, mixed>}>
     */
    private function ownRobots(): array
    {
        $settings = $this->seo->settings();
        if (! $settings['indexable']) {
            return [self::finding('info', 'hidden')];
        }
        if ($settings['robots'] === '') {
            return [];
        }

        $own = RobotsTxt::parse($settings['robots']);
        $blocked = [];
        $partial = [];
        foreach (self::bots() as $bot) {
            match ($own->verdict($bot)) {
                'blocked' => $blocked[] = $bot,
                'partial' => $partial[] = $bot,
                'allowed' => null,
            };
        }

        $findings = [];
        if ($blocked !== []) {
            $findings[] = self::finding('info', 'own_blocks', ['bots' => $blocked]);
        }
        if ($partial !== []) {
            $findings[] = self::finding('info', 'own_partial', ['bots' => $partial]);
        }

        return $findings;
    }

    /**
     * Checks 3 to 7, over the network. A refused or failed request throws and ends the check;
     * what was found before it stays.
     *
     * @param  list<array{level: string, code: string, params: array<string, mixed>}>  $findings
     *
     * @throws SafeHttpException
     */
    private function probe(AiCheckInput $input, array &$findings): void
    {
        $base = 'https://'.$input->host;
        $browser = Cast::string(config('seo.ai_crawlers.browser'));

        $home = $this->fetch($base.'/', $browser);
        SafeHttp::discard($home);
        if ($home->status() !== 200) {
            $findings[] = self::finding('unchecked', 'site_status', ['status' => $home->status()]);

            return;
        }
        $answers = [$home];

        $robots = $this->fetch($base.'/robots.txt', $browser);
        $answers[] = $robots;
        if ($robots->status() !== 200) {
            SafeHttp::discard($robots);
            $findings[] = self::finding('unchecked', 'robots_status', ['status' => $robots->status()]);
        } else {
            [$text, $more] = SafeHttp::readUpTo($robots, self::ROBOTS_MAX_BYTES);
            if ($more) {
                $findings[] = self::finding('unchecked', 'robots_too_long');
            } else {
                array_push($findings, ...$this->compareRobots($text, $input->expectedRobots));
            }
        }

        $refused = [];
        $odd = [];
        foreach (Cast::arr(config('seo.ai_crawlers.probes')) as $bot => $agent) {
            $answer = $this->fetch($base.'/', Cast::string($agent));
            SafeHttp::discard($answer);
            $answers[] = $answer;
            $status = $answer->status();
            if (in_array($status, self::REFUSED, true) || $answer->header('cf-mitigated') !== '') {
                $refused[] = $bot.':'.$status;
            } elseif ($status !== 200) {
                $odd[] = $bot.':'.$status;
            }
        }

        $cloudflare = false;
        $noindex = false;
        foreach ($answers as $answer) {
            $cloudflare = $cloudflare || strtolower(trim($answer->header('Server'))) === 'cloudflare';
            $noindex = $noindex || preg_match('/\b(noindex|none)\b/i', implode(',', $answer->toPsrResponse()->getHeader('X-Robots-Tag'))) === 1;
        }

        if ($refused !== []) {
            $findings[] = $cloudflare
                ? self::finding('info', 'cloudflare_challenge', ['bots' => $refused])
                : self::finding('warning', 'bot_refused', ['bots' => $refused]);
        }
        if ($odd !== []) {
            $findings[] = self::finding('info', 'bot_odd', ['bots' => $odd]);
        }
        if ($noindex) {
            $findings[] = $input->platformNoindex ? self::finding('info', 'platform_hidden') : self::finding('warning', 'noindex_header');
        }
    }

    /**
     * Check 3: the robots.txt served against the one the platform makes for this host.
     *
     * @return list<array{level: string, code: string, params: array<string, mixed>}>
     */
    private function compareRobots(string $served, string $ours): array
    {
        $live = RobotsTxt::parse($served);
        $mine = RobotsTxt::parse($ours);
        $findings = [];

        if (RobotsTxt::comparable($served) !== RobotsTxt::comparable($ours)) {
            $rank = ['allowed' => 0, 'partial' => 1, 'blocked' => 2];
            $added = [];
            foreach (self::bots() as $bot) {
                if ($rank[$live->verdict($bot)] > $rank[$mine->verdict($bot)]) {
                    $added[] = $bot;
                }
            }
            if ($added !== []) {
                $findings[] = self::finding('warning', 'proxy_blocks', ['bots' => $added]);
            }
        }

        $signals = array_values(array_diff($live->contentSignals(), $mine->contentSignals()));
        if ($signals !== []) {
            $findings[] = self::finding('info', 'content_signal', ['signals' => $signals]);
        }

        return $findings;
    }

    /** @throws SafeHttpException */
    private function fetch(string $url, string $agent): Response
    {
        return $this->http->get(
            $url,
            ['User-Agent' => $agent],
            timeout: self::REQUEST_SECONDS,
            connectTimeout: self::REQUEST_SECONDS,
            sameHost: true,
            budget: self::REQUEST_SECONDS,
        )['response'];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{level: string, code: string, params: array<string, mixed>}
     */
    private static function finding(string $level, string $code, array $params = []): array
    {
        return ['level' => $level, 'code' => $code, 'params' => $params];
    }

    /** @param  list<array{level: string, code: string, params: array<string, mixed>}>  $findings */
    private static function overall(array $findings): string
    {
        $level = 'ok';
        foreach ($findings as $finding) {
            if ((self::LEVELS[$finding['level']] ?? 0) > self::LEVELS[$level]) {
                $level = $finding['level'];
            }
        }

        return $level;
    }
}
