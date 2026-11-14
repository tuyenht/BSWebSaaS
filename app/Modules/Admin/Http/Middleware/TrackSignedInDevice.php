<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Support\SignedInDevices;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Keeps the member's signed-in devices index (SignedInDevices) up to date, on the signed-in admin
 * pages, the platform console, /livewire/update and the start of an OAuth flow. Runs after the site
 * is resolved and the member is known.
 *
 * Before the request: a session that was ended (signed out from another device: its ended mark is
 * there) or that signed in under a password the member no longer has is signed out here, as a
 * guest is. So is a session signed in more than ENDED_TTL ago whose entry is gone: its mark may have
 * run out, so nothing tells it apart from an ended one (a member kept signed in that long only by
 * the storefront signs in again; one using the admin keeps the entry). After it: a new session, or
 * one whose id changed, gets its entry (the old one is dropped and marked ended); an entry already
 * written only has its last use moved, at most once every THROTTLE seconds, and is written again if
 * it went missing without being ended (expired while the session lived on elsewhere), unless it
 * signed in more than ENDED_TTL ago (above). An ended session is never indexed again. Which entry the
 * session last wrote and when (SignedInDevices::MARKER), and when it signed in
 * (SignedInDevices::SIGNED_IN_AT, set at sign-in), live in the session itself, never in this object
 * (Octane reuses it).
 *
 * Valkey trouble before the request lets it through (reported): the session store is Valkey too, so
 * such a request fails there anyway.
 *
 * Valkey trouble after the request never breaks the page (the index catches up on a later one).
 */
final readonly class TrackSignedInDevice
{
    public function __construct(
        private SignedInDevices $devices,
        private CurrentSite $current,
        private SupportSession $support,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws AuthenticationException this device was signed out from another one
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $before = self::marker($session);
        $user = Auth::user();
        if ($user instanceof User && $this->signedOut($user, SignedInDevices::keyOf($session->getId()), $before, self::signedInAt($session))) {
            Auth::logout();
            $session->invalidate();
            $session->regenerateToken();

            throw new AuthenticationException;
        }

        $response = $next($request);

        try {
            $this->after($session, $before, $request->userAgent());
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }

    /**
     * Ended (marked, whether or not the session still carries a marker: a request in flight may
     * have saved it without one), signed in so long ago that its mark may have run out and no longer
     * indexed, or the marker this session wrote names a password the member no longer has.
     *
     * @param  array{h: string, u: int, fp: string, at: int}|null  $marker
     */
    private function signedOut(User $user, string $key, ?array $marker, ?int $signedInAt): bool
    {
        $uid = Cast::int($user->getKey());
        if ($marker !== null && $marker['u'] === $uid && $marker['h'] === $key
            && ! hash_equals($marker['fp'], SignedInDevices::fingerprint($user))) {
            return true;
        }

        try {
            if ($this->devices->ended($uid, $key)) {
                return true;
            }

            return $signedInAt !== null
                && now()->getTimestamp() - $signedInAt >= SignedInDevices::ENDED_TTL
                && ! $this->devices->exists($uid, $key);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * @param  array{h: string, u: int, fp: string, at: int}|null  $before
     */
    private function after(Session $session, ?array $before, ?string $userAgent): void
    {
        $user = Auth::user();
        $key = SignedInDevices::keyOf($session->getId());
        $uid = $user instanceof User ? Cast::int($user->getKey()) : null;

        // Signed out, another member signed in, or a new session id: the old entry names nothing now.
        if ($before !== null && ($uid !== $before['u'] || $key !== $before['h'])) {
            $this->devices->forget($before['u'], $before['h']);
        }
        if (! $user instanceof User || $uid === null) {
            $session->forget(SignedInDevices::MARKER);

            return;
        }
        // signed in before the sign-in time was kept: count from now
        if (self::signedInAt($session) === null) {
            $session->put(SignedInDevices::SIGNED_IN_AT, now()->getTimestamp());
        }

        $fingerprint = SignedInDevices::fingerprint($user);
        $now = now()->getTimestamp();
        $same = $before !== null && $before['u'] === $uid && $before['h'] === $key && hash_equals($before['fp'], $fingerprint);
        if ($same && $now - $before['at'] < SignedInDevices::THROTTLE) {
            return;
        }

        if (! $same || ! $this->devices->refresh($uid, $key)) {
            $support = $this->support->current();
            $written = $this->devices->record(
                $user,
                $session->getId(),
                $userAgent,
                $this->current->hasId() ? $this->current->id() : null,
                $support === null ? null : ['site_id' => $support['site_id'], 'session_ulid' => $support['session_ulid']],
            );
            if ($written === null) {
                return; // ended from another device during this request: the next one signs out
            }
        }

        $session->put(SignedInDevices::MARKER, ['h' => $key, 'u' => $uid, 'fp' => $fingerprint, 'at' => $now]);
    }

    /** When this session signed in (SignedInDevices::SIGNED_IN_AT); null when it does not say. */
    private static function signedInAt(Session $session): ?int
    {
        $value = $session->get(SignedInDevices::SIGNED_IN_AT);

        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * Stamp the session that just signed in with the time, before any of its requests can race (a
     * request of it in flight later saves a copy that already carries it).
     */
    public static function signedIn(): void
    {
        $request = request();
        if ($request->hasSession()) {
            $request->session()->put(SignedInDevices::SIGNED_IN_AT, now()->getTimestamp());
        }
    }

    /**
     * @return array{h: string, u: int, fp: string, at: int}|null
     */
    private static function marker(Session $session): ?array
    {
        $value = $session->get(SignedInDevices::MARKER);
        if (! is_array($value) || ! is_string($value['h'] ?? null) || ! is_string($value['fp'] ?? null)) {
            return null;
        }

        return ['h' => $value['h'], 'u' => Cast::int($value['u'] ?? null), 'fp' => $value['fp'], 'at' => Cast::int($value['at'] ?? null)];
    }
}
