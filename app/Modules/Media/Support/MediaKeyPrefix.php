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
            // The database accepts a first folder that is a collection only for these names.
            if (! in_array($collection, MediaKey::COLLECTIONS, true)) {
                throw MediaKeyRejectedException::malformed('single-site collection (not one of '.implode(', ', MediaKey::COLLECTIONS).')', $collection);
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
