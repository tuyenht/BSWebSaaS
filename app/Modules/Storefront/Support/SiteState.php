<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

/**
 * Why a site that resolved from its host is not open to visitors, one case per lifecycle status
 * other than `active` (design.md §7, `sites.status`). Each case carries what its page answers: the
 * HTTP status (503 while the closure is temporary, so search engines come back; 410 once the site
 * is closed for good), how long to wait before asking again, its icon and its words. The words
 * never give the reason (unpaid, breach, …): the page only says the site is not open.
 */
enum SiteState: string
{
    case Provisioning = 'provisioning';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public function httpStatus(): int
    {
        return $this === self::Archived ? 410 : 503;
    }

    /** Seconds before a crawler should ask again (Retry-After); none once the site is closed. */
    public function retryAfter(): ?int
    {
        return match ($this) {
            self::Provisioning => 300,
            self::Suspended => 3600,
            self::Archived => null,
        };
    }

    /** The glyph key of theme::partials.sf-notice-icon. */
    public function icon(): string
    {
        return match ($this) {
            self::Provisioning => 'rocket',
            self::Suspended => 'pause',
            self::Archived => 'archive',
        };
    }

    public function heading(): string
    {
        return __('storefront.site_state.'.$this->value.'_title');
    }

    /** The line under the heading; a suspended site with a public email also says to write to it. */
    public function text(bool $hasEmail): string
    {
        return $this === self::Suspended && $hasEmail
            ? __('storefront.site_state.suspended_text_email')
            : __('storefront.site_state.'.$this->value.'_text');
    }

    /** A site being set up offers to reload; a suspended or closed one offers its public email (none without one). */
    public function offersReload(): bool
    {
        return $this === self::Provisioning;
    }
}
