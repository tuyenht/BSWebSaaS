<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Modules\Media\Exceptions\MediaKeyRejectedException;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteDirectory;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Entitlement\Entitlement;

/**
 * The prefix of a new media key for the current site: `{code}/`, or `{code}/{yyyy}/{mm}/` when the
 * platform turned year/month folders on for the site (feature `media.year_month_folders`, off by
 * default). The month is the site's own clock (SiteTime), so a file uploaded just after midnight on
 * the 1st in Hà Nội lands in the new month.
 */
final readonly class MediaKeyPrefix
{
    public const string YEAR_MONTH_FEATURE = 'media.year_month_folders';

    public function __construct(
        private CurrentSite $current,
        private SiteDirectory $sites,
        private Entitlement $entitlement,
        private SiteTime $time,
    ) {}

    public function forNewObject(): string
    {
        $code = $this->sites->codeOfCurrent($this->current);
        $prefix = $code.'/';

        if ($this->entitlement->featureEnabled(self::YEAR_MONTH_FEATURE)) {
            $prefix .= $this->time->now()->format('Y/m').'/';
        }

        if (preg_match(MediaKey::PREFIX_PATTERN, $prefix) !== 1) {
            throw MediaKeyRejectedException::malformed('key prefix', $prefix);
        }

        return $prefix;
    }
}
