<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

/**
 * Tells a person's browser from a crawler, a link preview, a measuring tool or a script, for the
 * tallies the storefront keeps from browser beacons (page views, contact button taps): their
 * requests are not counted. A request with no browser name is not counted either.
 */
final class BotAgent
{
    /** User agents of crawlers, link previews and measuring tools. */
    private const string PATTERN = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|headless|lighthouse|pingdom|uptime|monitor|curl|wget|python|go-http|java\/|httpclient|okhttp|axios|node-fetch|php/i';

    public static function is(?string $agent): bool
    {
        return $agent === null || $agent === '' || preg_match(self::PATTERN, $agent) === 1;
    }
}
