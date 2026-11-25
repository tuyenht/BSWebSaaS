<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A post, page or product cannot go live (now, or at a scheduled time) at or after its end time
 * («Gỡ khỏi website lúc»): it would be taken down again within a minute, and the database refuses an
 * end that is not after the publish time. The member moves or clears the end time first. A refusal of
 * the request (403 when nothing catches it), carrying the sentence a member reads.
 */
final class ExpiredContentException extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct(__('entries.expired_publish_why'));
    }

    /**
     * Refuse when $expiresAt is set and not after $liveFrom (now when not given).
     *
     * @throws self
     */
    public static function guard(?DateTimeInterface $expiresAt, ?DateTimeInterface $liveFrom = null): void
    {
        if ($expiresAt !== null && $expiresAt <= ($liveFrom ?? now())) {
            throw new self;
        }
    }
}
