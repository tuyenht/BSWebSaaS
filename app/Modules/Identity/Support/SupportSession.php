<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/**
 * A support session (design.md v2.33–v2.34): platform staff signed in on a website's host AS one
 * of its members. The facts live in that host's session — never in this object, which Octane may
 * reuse across requests — so a request without a session (a queued job, the console) simply has
 * no support session.
 *
 * Audit reads it to name the staff member behind every row; the account, membership and
 * sign-in changes a staff member must never make on someone's behalf call forbid().
 */
final class SupportSession
{
    private const string KEY = 'support_session';

    /** $sessionUlid: the code the link was issued with; null for a link issued before codes existed. */
    public function start(User $staff, User $member, int $siteId, string $reason, CarbonInterface $expiresAt, ?string $sessionUlid = null): void
    {
        $this->session()?->put(self::KEY, [
            'staff_id' => Cast::int($staff->getKey()),
            'staff_label' => self::staffLabel($staff),
            'member_id' => Cast::int($member->getKey()),
            'reason' => $reason,
            'site_id' => $siteId,
            'expires_at' => $expiresAt->getTimestamp(),
            'session_ulid' => $sessionUlid,
        ]);
    }

    /**
     * @return array{staff_id: int, staff_label: string, reason: string, site_id: int, expires_at: int, session_ulid: string|null}|null
     */
    public function current(): ?array
    {
        $session = $this->session();
        $value = $session?->get(self::KEY);
        if (! is_array($value)) {
            return null;
        }

        // The facts belong to the member the staff member was signed in as. Anyone else signing in
        // on this browser (the login form keeps session data across its regenerate) starts clean.
        if (Auth::id() !== Cast::int($value['member_id'] ?? null)) {
            $session->forget(self::KEY);

            return null;
        }

        return [
            'staff_id' => Cast::int($value['staff_id'] ?? null),
            'staff_label' => Cast::string($value['staff_label'] ?? null),
            'reason' => Cast::string($value['reason'] ?? null),
            'site_id' => Cast::int($value['site_id'] ?? null),
            'expires_at' => Cast::int($value['expires_at'] ?? null),
            'session_ulid' => Cast::string($value['session_ulid'] ?? null) ?: null,
        ];
    }

    public function active(): bool
    {
        return $this->current() !== null;
    }

    /** Refuse an action that must never be done on someone's behalf. */
    public function forbid(): void
    {
        if ($this->active()) {
            throw new AuthorizationException(__('admin.support.locked'));
        }
    }

    public function end(): void
    {
        $this->session()?->forget(self::KEY);
    }

    /** How a member is named: their name, else their email. */
    public static function labelOf(User $user): string
    {
        $name = trim(Cast::string($user->getAttribute('name')));

        return $name !== '' ? $name : Cast::string($user->getAttribute('email'));
    }

    /** How a staff member is named to the website: their name — never their email, which stays with the platform. */
    public static function staffLabel(User $staff): string
    {
        $name = trim(Cast::string($staff->getAttribute('name')));

        return $name !== '' ? $name : __('admin.support.staff');
    }

    private function session(): ?Session
    {
        $request = request();

        return $request->hasSession() ? $request->session() : null;
    }
}
