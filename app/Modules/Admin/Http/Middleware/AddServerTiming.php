<?php

namespace App\Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells a signed-in admin's browser how long the server took to draw the page and how much of it
 * was SQL (the Server-Timing header, shown in DevTools under Network → Timing). With it, a slow
 * page change can be split into server time and everything between the server and the screen
 * (network, Cloudflare, antivirus HTTPS scanning). Guests never get it: timings are not handed to
 * anyone who has not signed in.
 */
final class AddServerTiming
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $start = hrtime(true);
        /** @var Connection $db */
        $db = DB::connection();
        $sqlBefore = $db->totalQueryDuration();

        $response = $next($request);

        if ($request->user() !== null) {
            $response->headers->set('Server-Timing', sprintf(
                'app;dur=%.1f;desc="server", db;dur=%.1f;desc="sql"',
                (hrtime(true) - $start) / 1e6,
                $db->totalQueryDuration() - $sqlBefore,
            ));
        }

        return $response;
    }
}
