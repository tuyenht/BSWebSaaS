<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\EntryViewCounter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * One view of a published page or post, sent by the visitor's browser (sf-view.js) once the page
 * has loaded, so a page served from the edge cache counts as well as one drawn here. Feeds the same
 * Valkey buffer the admin's "Lượt xem" comes from (EntryViewCounter → entries:flush-views).
 *
 * Runs in the host's site context (SetCurrentSite), so the entry is looked up among that site's own
 * rows only. Answers 204 whatever it decides (an unknown or unpublished entry, a crawler), never a
 * cookie or a session; a visitor past the per-IP limit gets 429 and is not counted.
 */
final readonly class RecordEntryView
{
    /** Views one address may send in DECAY_SECONDS; a person reading pages stays far below it. */
    public const int MAX_ATTEMPTS = 60;

    private const int DECAY_SECONDS = 60;

    /** User agents of crawlers, link previews and measuring tools: their visits are not views. */
    private const string BOT_AGENT = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|headless|lighthouse|pingdom|uptime|monitor|curl|wget|python|go-http|java\/|httpclient|okhttp|axios|node-fetch|php/i';

    public function __construct(private EntryViewCounter $views) {}

    public function __invoke(Request $request, string $entry): Response
    {
        $throttle = 'entry-view:'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttle, self::MAX_ATTEMPTS)) {
            return response()->noContent(Response::HTTP_TOO_MANY_REQUESTS);
        }
        RateLimiter::hit($throttle, self::DECAY_SECONDS);

        $agent = (string) $request->userAgent();
        if ($agent === '' || preg_match(self::BOT_AGENT, $agent) === 1) {
            return response()->noContent();
        }

        $row = Entry::query()
            ->select(['id', 'site_id'])
            ->where('ulid', strtoupper($entry))
            ->where('status', 'published')
            ->first();

        if ($row !== null) {
            $this->views->record($row->site_id, $row->id);
        }

        return response()->noContent();
    }
}
