<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Catalog\Support\ProductIndexLink;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

/**
 * The phone menu's "Sản phẩm" tab on or off: one key (`drawer_products`) of the site's
 * `storefront.header` setting. The product settings page writes it and the Trình đơn screen reads
 * it, so the rule for when the switch exists lives here only. It exists on a site with the catalog
 * and a products section that has a header setting (without one the website shows no tab). On means
 * the header setting does not switch the tab off. Saving drops the request's settings copy
 * (SiteSetting events), so the website reads the new value on its next page.
 */
final readonly class ProductsTabSwitch
{
    public function __construct(
        private Entitlement $entitlement,
        private ProductIndexLink $products,
        private SettingsRepository $settings,
    ) {}

    /** The switch exists for this site. */
    public function available(): bool
    {
        return $this->header() !== null;
    }

    /** The tab is on: the site has the switch and the header setting does not turn it off. */
    public function isOn(): bool
    {
        $header = $this->header();

        return $header !== null && ($header['drawer_products'] ?? true) !== false;
    }

    /**
     * Write the key, keeping the rest of the header as stored. False when the site has no switch.
     */
    public function set(bool $on): bool
    {
        if (! $this->available()) {
            return false;
        }

        return DB::transaction(function () use ($on): bool {
            // The row is locked while its one key changes, so a header saved meanwhile is not overwritten.
            $row = SiteSetting::query()->where('group_name', 'storefront')->where('key', 'header')->lockForUpdate()->first();
            if ($row === null) {
                return false;
            }
            $row->value = [...$row->value, 'drawer_products' => $on];
            $row->save();

            return true;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function header(): ?array
    {
        $shop = in_array('catalog', $this->entitlement->entitledModuleKeys(), true) && $this->products->catalogRoot() !== null;

        return $shop ? $this->settings->get('storefront', 'header') : null;
    }
}
