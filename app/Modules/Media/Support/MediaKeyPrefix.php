<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Modules\Media\Exceptions\MediaKeyRejectedException;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteDirectory;

/**
 * The prefix of a new media key for the current website: `{code}/`, its permanent code. In
 * single-site mode (`media.tenant_prefix` false) it is `{collection}/`, the layout that mode always had.
 */
final readonly class MediaKeyPrefix
{
    public function __construct(
        private CurrentSite $current,
        private SiteDirectory $sites,
    ) {}

    public function forNewObject(string $collection): string
    {
        if (config('media.tenant_prefix', true) !== true) {
            // A collection shaped like a website code could sit under a code issued later: the
            // database refuses it, so it is refused here with the reason.
            if (preg_match(MediaKey::CODE_PATTERN, $collection) === 1) {
                throw MediaKeyRejectedException::malformed('single-site collection (it looks like a website code)', $collection);
            }
            if (MediaKey::isReservedFirstSegment($collection)) {
                throw MediaKeyRejectedException::malformed('single-site collection (the folder is the platform\'s)', $collection);
            }
            $prefix = $collection.'/';
        } else {
            $code = $this->sites->codeOfCurrent($this->current);
            if (preg_match(MediaKey::CODE_PATTERN, $code) !== 1) {
                throw MediaKeyRejectedException::malformed('website code', $code);
            }
            $prefix = $code.'/';
        }

        if (preg_match(MediaKey::PREFIX_PATTERN, $prefix) !== 1) {
            throw MediaKeyRejectedException::malformed('key prefix', $prefix);
        }

        return $prefix;
    }
}
