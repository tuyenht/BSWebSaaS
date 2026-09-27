<?php

declare(strict_types=1);

namespace App\Modules\Media\Exceptions;

use DomainException;

/**
 * A file or a link refused for a reason the person adding it can act on, shown to them in their own
 * language. Its own type, so the upload endpoints show exactly these — never the text of an
 * unexpected failure, which can name buckets, paths or hosts. The message IS the reason key, one of
 * REASONS.
 */
final class MediaRejectedException extends DomainException
{
    public const array REASONS = [
        'unsupported_type', 'too_large', 'image_too_large', 'file_invalid',
        'upload_session_invalid', 'upload_missing', 'storage_failed',
        'url_invalid', 'host_unresolvable', 'private_address', 'redirect_invalid', 'too_many_redirects',
        'fetch_failed', 'fetch_unreachable', 'empty_response', 'not_an_image',
    ];

    /** Reasons whose sentence already existed under another key (the upload form's own rules). */
    private const array KEYS = [
        'unsupported_type' => 'media.err_mimes',
        'too_large' => 'media.err_max',
        'url_invalid' => 'media.picker_url_invalid',
    ];

    /**
     * @param  array<string, int|string>  $replace  placeholders of the sentence, e.g. the HTTP status
     */
    public function __construct(public readonly string $reason, private readonly array $replace = [])
    {
        parent::__construct($reason);
    }

    /** The sentence to show, in the current locale. */
    public function userMessage(): string
    {
        return __(self::KEYS[$this->reason] ?? 'media.err_'.$this->reason, $this->replace);
    }
}
