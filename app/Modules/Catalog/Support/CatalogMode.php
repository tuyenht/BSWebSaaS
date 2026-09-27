<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Tenancy\Context\SiteSettings;

/**
 * The per-site catalog mode flag (site_settings: group `catalog`, key `mode`) — THE single
 * switch the storefront (P2) and admin read to decide whether prices/ordering surface.
 * `brochure` = show the catalog, hide price & ordering (An Vinh's target state);
 * `commerce` = full selling, phase-2. Reads fail closed to brochure: a missing or
 * malformed setting can only ever HIDE commercial surfaces, never expose them.
 * Reads through Tenancy's SiteSettings (the base module's public surface) — not the Admin
 * settings repository — so the Catalog module keeps the acyclic dependency direction.
 */
final readonly class CatalogMode
{
    public const string BROCHURE = 'brochure';

    public const string COMMERCE = 'commerce';

    /** @var list<string> */
    public const array MODES = [self::BROCHURE, self::COMMERCE];

    public function __construct(private SiteSettings $settings) {}

    /** The current site's mode (site-scoped by BelongsToSite + RLS). */
    public function current(): string
    {
        $mode = $this->settings->value('catalog', 'mode')['value'] ?? null;

        return in_array($mode, self::MODES, true) ? $mode : self::BROCHURE;
    }

    public function isBrochure(): bool
    {
        return $this->current() === self::BROCHURE;
    }
}
