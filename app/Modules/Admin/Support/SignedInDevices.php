<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\Redis;
use SessionHandlerInterface;

/**
 * A member's signed-in devices ("Phiên đăng nhập" on My account): a small index kept next to the
 * sessions in Valkey, so one member's sessions can be listed and ended without scanning the store
 * (sessions are keyed by their id alone).
 *
 *  - `signed_in_devices:{user}` — a sorted set of the member's sessions by last use. Each is named by
 *    an opaque key, a keyed hash of the session id: the id is the sign-in credential and never
 *    leaves the server; a key is worth nothing outside its own member's index.
 *  - `signed_in_device:{user}:{key}` — written once, when the session is first seen: its id, the
 *    kind of device and browser read from the user agent (never the user agent itself, never an IP
 *    address), the website it is on, when it started, and a keyed hash of the password it signed
 *    in with. A support session keeps only its website and code: never the staff member's device.
 *
 * TrackSignedInDevice writes it, at most once every THROTTLE seconds per session (the last-use time
 * is that coarse). Both keys live as long as an idle session can (the session lifetime plus two
 * THROTTLE windows) and stale entries are dropped as they are read: one whose session is gone from
 * the store, or made under a password the member no longer has (that session is ended there and
 * then, as the middleware would at its next request).
 *
 * Ending a device deletes its session from the store and its entry, and leaves a mark
 * (`signed_in_device_ended:{user}:{key}`, ENDED_TTL). A request of that device still in flight can
 * write its old session back when it finishes; the mark keeps that request from indexing it again,
 * and its next request signs it out (TrackSignedInDevice). An entry that is simply missing (expired
 * while the session was kept alive elsewhere, e.g. on the storefront, or dropped from Valkey) is
 * written again instead: only the mark means "signed out". No "remember me" cookie is issued today:
 * if one is added, ending a device must cycle it too.
 *
 * Holds no request state (Octane): every call names the member and the session it is about.
 */
final readonly class SignedInDevices
{
    /** Seconds between two writes of the same session's last use. */
    public const int THROTTLE = 300;

    /** The most rows the page lists (newest first); ending every other device still reaches them all. */
    public const int LIST_MAX = 50;

    /** Where the middleware keeps, in the session itself, which entry it last wrote and when. */
    public const string MARKER = 'signed_in_device';

    public const string KIND_MEMBER = 'member';

    public const string KIND_SUPPORT = 'support';

    /** @var list<string> */
    public const array DEVICES = ['windows', 'mac', 'linux', 'chromeos', 'iphone', 'ipad', 'android', 'android_tablet', 'other'];

    /** @var list<string> */
    public const array BROWSERS = ['chrome', 'safari', 'firefox', 'edge', 'samsung', 'opera', 'coccoc', 'other'];

    private const string SET = 'signed_in_devices:';

    private const string ENTRY = 'signed_in_device:';

    private const string ENDED = 'signed_in_device_ended:';

    /**
     * How long an ended session's mark lasts. Only a session written back by a request in flight
     * can carry an ended key, and only while something keeps it alive; a month outlasts any such
     * use by far, and a mark is a few bytes.
     */
    public const int ENDED_TTL = 30 * 86400;

    /** An entry younger than this is not checked against the store: its session may not be saved yet. */
    private const int YOUNG = 120;

    /**
     * Writes a new entry, adds it to the member's set and drops the set's members that cannot be
     * alive any more; nothing for a session that was ended (its mark is there).
     * KEYS: entry, set, ended mark · ARGV: key, now, ttl, cutoff, then field/value pairs.
     */
    private const string CREATE_SCRIPT = <<<'LUA'
        if redis.call('exists', KEYS[3]) == 1 then
            return 0
        end
        redis.call('del', KEYS[1])
        redis.call('hset', KEYS[1], unpack(ARGV, 5))
        redis.call('expire', KEYS[1], ARGV[3])
        redis.call('zadd', KEYS[2], ARGV[2], ARGV[1])
        redis.call('zremrangebyscore', KEYS[2], '-inf', '(' .. ARGV[4])
        redis.call('expire', KEYS[2], ARGV[3])
        return 1
        LUA;

    /**
     * Moves an entry's last use forward only while the entry still exists, so a request in flight
     * never brings back a device that was just signed out. KEYS: entry, set · ARGV: key, now, ttl.
     */
    private const string REFRESH_SCRIPT = <<<'LUA'
        if redis.call('exists', KEYS[1]) == 0 then
            return 0
        end
        redis.call('expire', KEYS[1], ARGV[3])
        redis.call('zadd', KEYS[2], ARGV[2], ARGV[1])
        redis.call('expire', KEYS[2], ARGV[3])
        return 1
        LUA;

    public function __construct(private SupportSignIn $support) {}

    /** The opaque key of a session: what the page and the index name it by. */
    public static function keyOf(string $sessionId): string
    {
        return substr(hash_hmac('sha256', 'signed-in-device|'.$sessionId, self::secret()), 0, 32);
    }

    /** A keyed hash of the member's current password: an entry made under another one is stale. */
    public static function fingerprint(User $user): string
    {
        return substr(hash_hmac('sha256', 'signed-in-password|'.Cast::string($user->getAuthPassword()), self::secret()), 0, 16);
    }

    public static function isKey(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{32}\z/', $value) === 1;
    }

    /**
     * The kind of device and of browser a user agent names, as two of DEVICES and BROWSERS.
     *
     * @return array{0: string, 1: string}
     */
    public static function describe(?string $userAgent): array
    {
        $ua = (string) $userAgent;
        $has = static fn (string ...$needles): bool => array_any($needles, static fn (string $n): bool => str_contains($ua, $n));

        $device = match (true) {
            $has('iPad') => 'ipad',
            $has('iPhone', 'iPod') => 'iphone',
            $has('Android') => $has('Mobile') ? 'android' : 'android_tablet',
            $has('CrOS') => 'chromeos',
            $has('Windows') => 'windows',
            $has('Macintosh', 'Mac OS X') => 'mac',
            $has('Linux', 'X11') => 'linux',
            default => 'other',
        };

        $browser = match (true) {
            $has('coc_coc_browser') => 'coccoc',
            $has('SamsungBrowser') => 'samsung',
            $has('Edg/', 'EdgA/', 'EdgiOS/', 'Edge/') => 'edge',
            $has('OPR/', 'Opera') => 'opera',
            $has('Firefox/', 'FxiOS/') => 'firefox',
            $has('Chrome/', 'CriOS/', 'Chromium/') => 'chrome',
            $has('Safari/') => 'safari',
            default => 'other',
        };

        return [$device, $browser];
    }

    /**
     * Index a session the member was just seen on (or that changed id). $support is the support
     * session's facts when staff are signed in as the member.
     *
     * @param  array{site_id: int, session_ulid: string|null}|null  $support
     * @return string|null the session's key; null when the session was ended (nothing written)
     */
    public function record(User $user, string $sessionId, ?string $userAgent, ?int $siteId, ?array $support = null): ?string
    {
        $key = self::keyOf($sessionId);
        $now = now()->getTimestamp();
        $fields = ['sid' => $sessionId, 'fp' => self::fingerprint($user), 'since' => (string) $now];
        if ($support !== null) {
            $fields += ['kind' => self::KIND_SUPPORT, 'site' => (string) $support['site_id'], 'code' => (string) $support['session_ulid']];
        } else {
            [$device, $browser] = self::describe($userAgent);
            $fields += ['kind' => self::KIND_MEMBER, 'site' => $siteId === null ? '' : (string) $siteId, 'device' => $device, 'browser' => $browser];
        }

        $pairs = [];
        foreach ($fields as $field => $value) {
            $pairs[] = $field;
            $pairs[] = $value;
        }

        $uid = Cast::int($user->getKey());
        $written = Redis::eval(self::CREATE_SCRIPT, 3, self::entryKey($uid, $key), self::setKey($uid), self::endedKey($uid, $key), $key, $now, self::ttl(), $now - self::ttl(), ...$pairs);

        return Cast::int($written) === 1 ? $key : null;
    }

    /** Move the entry's last use to now; false when it is gone (the device was signed out). */
    public function refresh(int $userId, string $key): bool
    {
        return Cast::int(Redis::eval(self::REFRESH_SCRIPT, 2, self::entryKey($userId, $key), self::setKey($userId), $key, now()->getTimestamp(), self::ttl())) === 1;
    }

    /** Whether the entry is there. */
    public function exists(int $userId, string $key): bool
    {
        return Cast::int(Redis::exists(self::entryKey($userId, $key))) > 0;
    }

    /** Whether this session was ended (signed out from another device, signed out, or changed id). */
    public function ended(int $userId, string $key): bool
    {
        return Cast::int(Redis::exists(self::endedKey($userId, $key))) > 0;
    }

    /**
     * Drop an entry. $ended (the default): its session ended or changed id, so it is marked and never
     * indexed again; false only when its session is already gone from the store.
     */
    public function forget(int $userId, string $key, bool $ended = true): void
    {
        if ($ended) {
            Redis::setex(self::endedKey($userId, $key), self::ENDED_TTL, '1');
        }
        Redis::zrem(self::setKey($userId), $key);
        Redis::del(self::entryKey($userId, $key));
    }

    /**
     * The member's live devices, newest first, at most LIST_MAX. The session asking is always
     * listed first, even before its entry is written (its first request).
     *
     * @return list<array{key: string, current: bool, kind: string, device: string, browser: string, site_id: int|null, last: int}>
     */
    public function rows(User $user, string $currentSessionId, ?string $userAgent, ?int $siteId): array
    {
        $currentKey = self::keyOf($currentSessionId);
        $current = null;
        $rows = [];
        foreach ($this->entries($user, $currentKey, self::LIST_MAX) as $key => $entry) {
            $row = [
                'key' => $key,
                'current' => $key === $currentKey,
                'kind' => $entry['kind'],
                'device' => $entry['device'],
                'browser' => $entry['browser'],
                'site_id' => $entry['site'],
                'last' => $entry['last'],
            ];
            if ($row['current']) {
                $current = $row;
            } else {
                $rows[] = $row;
            }
        }

        if ($current === null) {
            [$device, $browser] = self::describe($userAgent);
            $current = ['key' => $currentKey, 'current' => true, 'kind' => self::KIND_MEMBER, 'device' => $device, 'browser' => $browser, 'site_id' => $siteId, 'last' => now()->getTimestamp()];
        }

        return [$current, ...$rows];
    }

    /**
     * Sign one of the member's other devices out. A support session ends on record, on its own
     * website. Returns what was ended (null: no such device of this member, or the asking one).
     *
     * @return array{kind: string, device: string, browser: string}|null
     */
    public function signOut(User $user, string $currentSessionId, string $key): ?array
    {
        if (! self::isKey($key) || hash_equals(self::keyOf($currentSessionId), $key)) {
            return null;
        }

        $entry = $this->entry(Cast::int($user->getKey()), $key);
        if ($entry === null) {
            return null;
        }

        $this->end($user, $key, $entry);

        return ['kind' => $entry['kind'], 'device' => $entry['device'], 'browser' => $entry['browser']];
    }

    /**
     * Sign every other device out, a support session included. Returns how many of the member's own
     * devices were signed out and how many support sessions ended (kept apart: the member's count
     * never includes a support session).
     *
     * @return array{members: int, support: int}
     */
    public function signOutOthers(User $user, string $currentSessionId): array
    {
        $currentKey = self::keyOf($currentSessionId);
        $ended = ['members' => 0, 'support' => 0];
        foreach ($this->entries($user, $currentKey, null) as $key => $entry) {
            if ($key === $currentKey) {
                continue;
            }
            $this->end($user, $key, $entry);
            $ended[$entry['kind'] === self::KIND_SUPPORT ? 'support' : 'members']++;
        }

        return $ended;
    }

    /**
     * @param  array{sid: string, kind: string, code: string}  $entry
     */
    private function end(User $user, string $key, array $entry): void
    {
        // The support session's end row first: if it cannot be written the session stays as it is.
        if ($entry['kind'] === self::KIND_SUPPORT && $entry['code'] !== '') {
            $this->support->endAsMember($user, $entry['code']);
        }
        self::handler()->destroy($entry['sid']);
        $this->forget(Cast::int($user->getKey()), $key);
    }

    /**
     * The member's live entries, newest first (all of them when $limit is null); dead ones are
     * dropped from the index on the way.
     *
     * @return array<string, array{sid: string, kind: string, code: string, device: string, browser: string, site: int|null, last: int}>
     */
    private function entries(User $user, string $currentKey, ?int $limit): array
    {
        $uid = Cast::int($user->getKey());
        $set = self::setKey($uid);
        Redis::zremrangebyscore($set, '-inf', '('.(now()->getTimestamp() - self::ttl()));
        // a few more than shown, so a dead entry or two does not shorten the list
        $scores = Redis::zrevrange($set, 0, $limit === null ? -1 : 4 * $limit - 1, true);
        $scores = is_array($scores) ? $scores : [];
        $keys = array_map(strval(...), array_keys($scores));
        // one round trip for every entry, not one per entry
        $raws = $keys === [] ? [] : Redis::pipeline(function (\Redis $pipe) use ($uid, $keys): void {
            foreach ($keys as $key) {
                $pipe->hgetall(self::entryKey($uid, $key));
            }
        });
        $fingerprint = self::fingerprint($user);
        $handler = self::handler();
        $now = now()->getTimestamp();

        $entries = [];
        foreach ($keys as $i => $key) {
            $entry = self::parse(is_array($raws) ? ($raws[$i] ?? null) : null);
            if ($entry === null) {
                $this->forget($uid, $key, ended: false);

                continue;
            }
            if (! hash_equals($entry['fp'], $fingerprint)) {
                // signed in under a password the member no longer has: out now rather than at its next
                // request (a support session on record, as any end); the asking one is re-seeded by its own request
                if ($key !== $currentKey) {
                    $this->end($user, $key, $entry);
                }

                continue;
            }
            if ($key !== $currentKey && $now - $entry['since'] >= self::YOUNG && $handler->read($entry['sid']) === '') {
                $this->forget($uid, $key, ended: false); // its session is gone from the store

                continue;
            }
            unset($entry['fp'], $entry['since']);
            $entries[$key] = [...$entry, 'last' => Cast::int($scores[$key] ?? null)];
        }

        return $limit === null ? $entries : array_slice($entries, 0, $limit, true);
    }

    /**
     * @return array{sid: string, fp: string, kind: string, code: string, device: string, browser: string, site: int|null, since: int}|null
     */
    private function entry(int $userId, string $key): ?array
    {
        return self::parse(Redis::hgetall(self::entryKey($userId, $key)));
    }

    /**
     * @return array{sid: string, fp: string, kind: string, code: string, device: string, browser: string, site: int|null, since: int}|null
     */
    private static function parse(mixed $raw): ?array
    {
        if (! is_array($raw) || Cast::string($raw['sid'] ?? null) === '') {
            return null;
        }

        $kind = Cast::string($raw['kind'] ?? null) === self::KIND_SUPPORT ? self::KIND_SUPPORT : self::KIND_MEMBER;
        $device = Cast::string($raw['device'] ?? null);
        $browser = Cast::string($raw['browser'] ?? null);
        $site = Cast::string($raw['site'] ?? null);

        return [
            'sid' => Cast::string($raw['sid']),
            'fp' => Cast::string($raw['fp'] ?? null),
            'kind' => $kind,
            'code' => $kind === self::KIND_SUPPORT ? Cast::string($raw['code'] ?? null) : '',
            'device' => in_array($device, self::DEVICES, true) ? $device : 'other',
            'browser' => in_array($browser, self::BROWSERS, true) ? $browser : 'other',
            'site' => ctype_digit($site) ? (int) $site : null,
            'since' => Cast::int($raw['since'] ?? null),
        ];
    }

    private static function handler(): SessionHandlerInterface
    {
        return app('session.store')->getHandler();
    }

    /** How long an entry can matter: an idle session lives `lifetime` minutes after its last request. */
    private static function ttl(): int
    {
        return max(1, Cast::int(config('session.lifetime'))) * 60 + 2 * self::THROTTLE;
    }

    private static function secret(): string
    {
        return Cast::string(config('app.key'));
    }

    private static function setKey(int $userId): string
    {
        return self::SET.$userId;
    }

    private static function entryKey(int $userId, string $key): string
    {
        return self::ENTRY.$userId.':'.$key;
    }

    private static function endedKey(int $userId, string $key): string
    {
        return self::ENDED.$userId.':'.$key;
    }
}
