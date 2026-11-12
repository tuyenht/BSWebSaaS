<?php

declare(strict_types=1);

namespace App\Modules\Admin\Errors;

use Illuminate\Support\Facades\Context;

/**
 * A short reference for one fault, the one the admin's error page shows ("ABC-DEF"): six letters
 * and digits that cannot be misheard over the phone (no 0/O, 1/I/L, 2/Z, 5/S, 8/B), random, never
 * an internal number. It rides in the request's log context, so the log line of the fault carries
 * it and support finds the fault from what the member reads out.
 */
final class ErrorReference
{
    public const string ALPHABET = 'ACDEFHJKMNPRTUVWXY34679';

    private const string KEY = 'error_ref';

    /** This request's reference, made (and put in the log context) on first use. */
    public static function current(): string
    {
        $ref = Context::get(self::KEY);
        if (is_string($ref) && $ref !== '') {
            return $ref;
        }

        $ref = self::make();
        Context::add(self::KEY, $ref);

        return $ref;
    }

    /** Whether this request already has a reference (its fault was logged with it). */
    public static function exists(): bool
    {
        return Context::has(self::KEY);
    }

    public static function make(): string
    {
        $code = '';
        $last = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < 6; $i++) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return substr($code, 0, 3).'-'.substr($code, 3);
    }
}
