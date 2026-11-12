<?php

declare(strict_types=1);

namespace App\Modules\Admin\Errors;

use Illuminate\Support\Facades\Context;
use Throwable;

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

    /** Which fault the reference belongs to (hidden: never written to the log). */
    private const string OWNER = 'error_ref_of';

    /**
     * A new reference for this fault, put in the log context before its log line is written. Each
     * fault gets its own: a second one in the same request does not reuse the first one's.
     */
    public static function assign(Throwable $e): string
    {
        $ref = self::make();
        Context::add(self::KEY, $ref);
        Context::addHidden(self::OWNER, self::fingerprint($e));

        return $ref;
    }

    /** The reference this fault was logged with, or null when it was not (or another fault was). */
    public static function of(Throwable $e): ?string
    {
        $ref = Context::get(self::KEY);

        return is_string($ref) && $ref !== '' && Context::getHidden(self::OWNER) === self::fingerprint($e) ? $ref : null;
    }

    /**
     * The object id alone could be handed to a later fault once an earlier one is freed; with the
     * class and the place it was thrown, two different faults never share it.
     */
    private static function fingerprint(Throwable $e): string
    {
        return spl_object_id($e).'|'.$e::class.'|'.$e->getFile().':'.$e->getLine();
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
