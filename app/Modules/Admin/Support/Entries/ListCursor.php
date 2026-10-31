<?php

namespace App\Modules\Admin\Support\Entries;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * A page cursor of a record list: the run, the sort value and the id of the row a page follows
 * ('after') or precedes ('before'), and the page's number. The anchor's values are kept, not just
 * its id, so the list still pages when that row has been deleted or edited meanwhile.
 *
 * The note is encrypted and signed as a whole: the browser can neither read the id inside nor
 * forge one. It names the view it was made for (a fingerprint of the filters, the search, the sort
 * and the page size): a note made for another view is refused, and the list starts again.
 */
final class ListCursor
{
    public static function make(string $fingerprint, int $group, ?string $value, int $id, string $way, int $page): string
    {
        return Crypt::encryptString(json_encode(['fp' => $fingerprint, 'g' => $group, 'v' => $value, 'id' => $id, 'w' => $way, 'n' => $page], JSON_THROW_ON_ERROR));
    }

    /** @return array{g: int, v: string|null, id: int, w: 'after'|'before', n: int}|null */
    public static function open(string $token, string $fingerprint): ?array
    {
        if ($token === '') {
            return null;
        }
        try {
            $note = json_decode(Crypt::decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }
        if (! is_array($note) || ($note['fp'] ?? null) !== $fingerprint
            || ! is_int($note['g'] ?? null) || ! is_int($note['id'] ?? null) || ! is_int($note['n'] ?? null) || $note['n'] < 1
            || ! in_array($note['w'] ?? null, ['after', 'before'], true)
            || ! (is_string($note['v'] ?? null) || ($note['v'] ?? null) === null)) {
            return null;
        }

        $v = $note['v'] ?? null;

        return ['g' => $note['g'], 'v' => is_string($v) ? $v : null, 'id' => $note['id'], 'w' => $note['w'], 'n' => $note['n']];
    }
}
