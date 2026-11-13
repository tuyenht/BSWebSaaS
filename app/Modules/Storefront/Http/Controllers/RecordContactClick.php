<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Storefront\Support\BotAgent;
use App\Modules\Storefront\Support\ContactClickCounter;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * One tap on a storefront contact button, sent by the visitor's browser with navigator.sendBeacon:
 * a text/plain body `{"channel":"zalo","placement":"bar"}` (a form-encoded body is read too). Feeds
 * the Valkey buffer the contact button tallies come from (ContactClickCounter →
 * contact:flush-clicks); the request itself reads and writes no table.
 *
 * The website is the one of the host (SetCurrentSite) — a website named in the body is ignored. The
 * channel must be a built-in one or a website's own (`c1`, `c2`, …) with the placement one of the
 * four, or `buy` (the "Mua hàng" button) with `bar`, where it is drawn; anything else, a crawler or a
 * request with no browser name answers 204 and counts nothing. Never
 * a cookie or a session; an address past its limit on that website gets 429 and is not counted (the
 * address is a rate-limit key only, it never reaches the database).
 */
final readonly class RecordContactClick
{
    /**
     * Taps one address may send to one website in DECAY_SECONDS, as many as page views (/_view):
     * Vietnamese mobile carriers put many visitors behind one address.
     */
    public const int MAX_ATTEMPTS = 60;

    private const int DECAY_SECONDS = 60;

    /** Longest body read: the two short fields with room to spare. */
    private const int MAX_BODY = 512;

    public function __construct(private ContactClickCounter $clicks) {}

    /** The current site arrives per call, never through the constructor: it is request-scoped (Octane). */
    public function __invoke(Request $request, CurrentSite $site): Response
    {
        $throttle = 'contact-click:'.$site->id().':'.($request->ip() ?? 'unknown');
        if (RateLimiter::tooManyAttempts($throttle, self::MAX_ATTEMPTS)) {
            return response()->noContent(Response::HTTP_TOO_MANY_REQUESTS);
        }
        RateLimiter::hit($throttle, self::DECAY_SECONDS);

        if (BotAgent::is($request->userAgent())) {
            return response()->noContent();
        }

        [$channel, $placement] = $this->fields($request);
        if (ContactClickCounter::accepts($channel, $placement)) {
            $this->clicks->record($site->id(), $channel, $placement, now()->getTimestamp());
        }

        return response()->noContent();
    }

    /**
     * The channel and placement of the body: JSON as sendBeacon sends it (text/plain), else form
     * fields. A field that is missing or not a string reads as ''.
     *
     * @return array{0: string, 1: string}
     */
    private function fields(Request $request): array
    {
        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY) {
            return ['', ''];
        }

        $json = json_decode($body, true, 2);
        $data = is_array($json) ? $json : $request->request->all();
        $channel = $data['channel'] ?? '';
        $placement = $data['placement'] ?? '';

        return [is_string($channel) ? $channel : '', is_string($placement) ? $placement : ''];
    }
}
