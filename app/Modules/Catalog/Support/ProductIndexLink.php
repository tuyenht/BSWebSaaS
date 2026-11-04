<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Tenancy\Context\ContentSections;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Entitlement\Entitlement;

/**
 * The public address of the current site's product list, or null when it has none: the site must
 * be entitled to products, have a products section built on a category with an address in that
 * language, and at least one published product (the storefront lists products on that category
 * only then — before that it lists posts). The address is built like every storefront link
 * (the site route + PathBuilder's language prefix).
 *
 * A SCOPED binding: each answer is worked out at most once per site for one request or job and
 * flushed with it under Octane (never a static or a singleton). The storefront's category pages
 * ask the same two questions (which category the products section is built on, whether a product
 * is published) through catalogRoot() / hasPublishedProducts(), so a page that also shows the
 * "Mua hàng" button reads them once. Up to five reads the first time (entitlement — itself
 * remembered per request — section, product, route), none after.
 */
final class ProductIndexLink
{
    /** @var array<int, int|null> the products section's category, per site id */
    private array $roots = [];

    /** @var array<int, bool> whether a product is published, per site id */
    private array $published = [];

    /** @var array<string, string|null> the address, per "site id|locale" */
    private array $links = [];

    public function for(string $locale): ?string
    {
        $site = $this->site();
        if ($site !== null && array_key_exists($site.'|'.$locale, $this->links)) {
            return $this->links[$site.'|'.$locale];
        }
        $link = $this->resolve($locale);
        if ($site !== null) {
            $this->links[$site.'|'.$locale] = $link;
        }

        return $link;
    }

    /** The category the products section is built on, or null. */
    public function catalogRoot(): ?int
    {
        $site = $this->site();
        if ($site === null) {
            return app(ContentSections::class)->taxonomyIdOf('products');
        }
        if (! array_key_exists($site, $this->roots)) {
            $this->roots[$site] = app(ContentSections::class)->taxonomyIdOf('products');
        }

        return $this->roots[$site];
    }

    /** Whether the site has at least one published product. */
    public function hasPublishedProducts(): bool
    {
        $site = $this->site();
        if ($site === null) {
            return Product::query()->where('status', 'published')->exists();
        }

        return $this->published[$site] ??= Product::query()->where('status', 'published')->exists();
    }

    private function resolve(string $locale): ?string
    {
        if (! in_array('catalog', app(Entitlement::class)->entitledModuleKeys(), true)) {
            return null;
        }
        $root = $this->catalogRoot();
        if ($root === null || ! $this->hasPublishedProducts()) {
            return null;
        }
        $path = SiteRoute::query()->where('taxonomy_id', $root)->where('locale', $locale)->value('path');

        return is_string($path) && $path !== '' ? app(PathBuilder::class)->publicUrl($path, $locale) : null;
    }

    private function site(): ?int
    {
        $current = app(CurrentSite::class);

        return $current->hasId() ? $current->id() : null;
    }
}
