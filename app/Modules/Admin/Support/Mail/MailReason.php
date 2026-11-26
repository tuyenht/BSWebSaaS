<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Mail;

use Illuminate\Contracts\Encryption\DecryptException;
use Symfony\Component\Mailer\Exception\InvalidArgumentException as MailerArgumentException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Why a mail failed, as one of the codes `mail_logs.reason_code` accepts, told from the exception
 * the send threw (and the ones it wraps). Never the server's own words: those stay in the error log.
 *
 *  - auth: the server refused the sign-in (SMTP 530/534/535, "authenticate").
 *  - connection: the server could not be reached or stopped answering.
 *  - rejected: any other 5xx answer (an address or the message refused).
 *  - refused_host / config: no exception — the website's own server is not allowed, or nothing could
 *    be set up, and the mail went to the log mailer (TenantMailer::apply(), MailSendContext).
 *    A stored secret that no longer decrypts or an unusable setting thrown at send time is `config`.
 *  - other: anything else.
 */
final class MailReason
{
    public const array CODES = ['auth', 'connection', 'rejected', 'refused_host', 'config', 'other'];

    public static function of(Throwable $e): string
    {
        for ($cause = $e, $depth = 0; $cause !== null && $depth < 5; $cause = $cause->getPrevious(), $depth++) {
            $reason = self::one($cause);
            if ($reason !== null) {
                return $reason;
            }
        }

        return 'other';
    }

    private static function one(Throwable $e): ?string
    {
        if ($e instanceof DecryptException || $e instanceof MailerArgumentException) {
            return 'config';
        }

        if (! $e instanceof TransportExceptionInterface) {
            return null;
        }

        $code = (int) $e->getCode();
        $message = strtolower($e->getMessage());

        if (in_array($code, [530, 534, 535], true) || str_contains($message, 'authenticat')) {
            return 'auth';
        }

        if ($code >= 500 && $code <= 599) {
            return 'rejected';
        }

        foreach (['connection', 'could not be established', 'timed out', 'timeout', 'unable to connect', 'stream', 'ssl', 'tls'] as $word) {
            if (str_contains($message, $word)) {
                return 'connection';
            }
        }

        return null;
    }
}
