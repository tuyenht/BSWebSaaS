<?php

namespace App\Modules\Identity\Hashing;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\Argon2IdHasher;
use RuntimeException;

/**
 * Argon2id with a global pepper (design.md §29 / CLAUDE.md §2.6).
 *
 * The secret is HMAC-SHA256'd with the pepper BEFORE Argon (base64 so the Argon
 * input is NUL-safe), then Argon salts + stretches. A stored hash is tagged with
 * an 8-hex fingerprint of the pepper that produced it, enabling zero-downtime
 * rotation: rotated-out peppers still verify and their hashes report needsRehash.
 *
 * Pepper is NEVER derived from email/id (an email change must not invalidate the
 * password). Fail-closed: refuses to operate with no pepper configured.
 */
final readonly class PepperedArgon2IdHasher implements Hasher
{
    private Argon2IdHasher $argon;

    /** @var non-empty-list<string> valid peppers, current first (rest = rotation history) */
    private array $peppers;

    /**
     * @param  list<string>  $peppers  valid peppers, current first
     * @param  array<string, mixed>  $argonOptions
     */
    public function __construct(array $peppers, array $argonOptions = [])
    {
        $peppers = array_values(array_filter($peppers, static fn (string $p): bool => $p !== ''));

        if ($peppers === []) {
            throw new RuntimeException('AUTH_PASSWORD_PEPPER must be configured (fail-closed).');
        }

        $this->peppers = $peppers;
        $this->argon = new Argon2IdHasher($argonOptions);
    }

    /**
     * @param  string  $hashedValue
     * @return array<string, mixed>
     */
    public function info($hashedValue): array
    {
        /** @var array<string, mixed> $info */
        $info = $this->argon->info($this->stripVersion($hashedValue)[1]);

        return $info;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function make(#[\SensitiveParameter] $value, array $options = []): string
    {
        $pepper = $this->peppers[0];

        return $this->fingerprint($pepper).':'.$this->argon->make($this->prehash((string) $value, $pepper), $options);
    }

    /**
     * @param  string|null  $hashedValue
     * @param  array<string, mixed>  $options
     */
    public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        if (! is_string($hashedValue) || $hashedValue === '') {
            return false;
        }

        [$tag, $hash] = $this->stripVersion($hashedValue);

        return array_any($this->candidatePeppers($tag), fn (string $pepper) => $this->argon->check($this->prehash((string) $value, $pepper), $hash, $options));
    }

    /**
     * @param  string  $hashedValue
     * @param  array<string, mixed>  $options
     */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        [$tag, $hash] = $this->stripVersion($hashedValue);

        if ($tag !== $this->fingerprint($this->peppers[0])) {
            return true; // hashed under a rotated-out (or unversioned) pepper
        }

        return $this->argon->needsRehash($hash, $options);
    }

    /**
     * Bind the secret to the pepper before Argon. base64 keeps the Argon input
     * printable + NUL-safe.
     */
    private function prehash(string $value, string $pepper): string
    {
        return base64_encode(hash_hmac('sha256', $value, $pepper, true));
    }

    private function fingerprint(string $pepper): string
    {
        return substr(hash('sha256', $pepper), 0, 8);
    }

    /**
     * @return array{0: string|null, 1: string} [versionTag|null, argonHash]
     */
    private function stripVersion(string $hashedValue): array
    {
        if (preg_match('/^([0-9a-f]{8}):(.+)$/s', $hashedValue, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return [null, $hashedValue];
    }

    /**
     * @return non-empty-list<string>
     */
    private function candidatePeppers(?string $tag): array
    {
        if ($tag === null) {
            return $this->peppers;
        }

        foreach ($this->peppers as $pepper) {
            if ($this->fingerprint($pepper) === $tag) {
                return [$pepper];
            }
        }

        return $this->peppers; // unknown tag → try all (defensive)
    }
}
