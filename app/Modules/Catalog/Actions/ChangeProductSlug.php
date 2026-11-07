<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Exceptions\ProductEditRefused;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Catalog\Support\AppRoutePaths;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Shared\Support\PlainText;
use App\Modules\Shared\Support\Slugger;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The one way a product's address (its slug) changes from a quick edit.
 *
 * The typed text is made into a plain address the way every screen does (Slugger, Vietnamese-aware),
 * then refused in plain words when another product of the language holds it (a product in the Trash
 * keeps its address), or when the public path it would get already belongs to a page or a category
 * (the routes table is the one list of those). A product that is live moves its route in the same
 * transaction, so the old address redirects to the new one; a late route conflict refuses the whole
 * change and nothing is saved.
 */
final class ChangeProductSlug
{
    /** The longest address a product keeps. */
    public const int MAX = 200;

    /**
     * The address the typed text turns into.
     *
     * @throws ProductEditRefused nothing usable is left of it
     */
    public function clean(string $input, string $locale): string
    {
        $slug = Slugger::make(PlainText::line($input), $locale, self::MAX);

        if ($slug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            throw new ProductEditRefused(__('catalog.slug_invalid'));
        }

        return $slug;
    }

    /**
     * Another product of this language that holds $slug (a product in the Trash included, any
     * letter case), or null. $ownId is the product being edited (null: a new one).
     */
    public function otherProduct(string $slug, string $locale, ?int $ownId): ?ProductTranslation
    {
        // Compared as text against the lower-cased value: a citext `=` is not leakproof, so under RLS
        // it would read every product link of the language (the (site_id, locale, (slug::text)) index serves this).
        return ProductTranslation::query()
            ->where('locale', $locale)
            ->whereRaw('slug::text = ?', [mb_strtolower($slug)])
            ->when($ownId !== null, fn ($q) => $q->where('product_id', '!=', $ownId))
            ->first();
    }

    /**
     * The plain sentence saying why $slug cannot be this product's address, or null when it is free.
     */
    public function refusal(string $slug, string $locale, ?int $ownId, ?Taxonomy $category): ?string
    {
        $other = $this->otherProduct($slug, $locale, $ownId);
        if ($other instanceof ProductTranslation) {
            $inTrash = Product::onlyTrashed()->whereKey($other->product_id)->exists();

            return __($inTrash ? 'catalog.slug_owner_product_trash' : 'catalog.slug_owner_product', ['slug' => $slug, 'name' => $other->name]);
        }

        $path = app(PathBuilder::class)->forProduct($category, $slug, $locale);

        // A flat address is one path segment: the application's own paths (login, admin…) answer first.
        if ($path === '/'.$slug && app(AppRoutePaths::class)->owns($slug)) {
            return __('catalog.slug_owner_page', ['slug' => $slug]);
        }

        $route = app(RouteSync::class)->foreignRoute($locale, $path, 'product_id', $ownId ?? 0);
        if (! $route instanceof SiteRoute) {
            return null;
        }

        if ($route->taxonomy_id !== null) {
            $name = TaxonomyTranslation::query()->where('taxonomy_id', $route->taxonomy_id)->where('locale', $locale)->value('name');
            if (is_string($name) && $name !== '') {
                return __('catalog.slug_owner_category', ['slug' => $slug, 'name' => $name]);
            }
        }

        // a page of the website, or another product's path
        return __('catalog.slug_owner_page', ['slug' => $slug]);
    }

    /**
     * Serialize every claim on one address of one language until the surrounding transaction ends,
     * so two requests cannot both pass the "is it free" check for the same address. Call it inside
     * the transaction, before the check.
     */
    public function lock(string $slug, string $locale): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['product_slug:'.app(CurrentSite::class)->id().':'.$locale.':'.mb_strtolower($slug)]);
    }

    /**
     * Save the address in $locale. Returns the old and the new address and whether the old one now
     * redirects to the new (only a live product has a public address to move).
     *
     * @return array{old: string, slug: string, changed: bool, redirected: bool}
     *
     * @throws ProductEditRefused unusable text, an address that is taken, or a late route conflict
     */
    public function handle(Product $product, string $input, string $locale): array
    {
        $slug = $this->clean($input, $locale);

        $translation = $product->translations()->where('locale', $locale)->first();
        if (! $translation instanceof ProductTranslation) {
            throw new ProductEditRefused(__('catalog.row_gone'));
        }
        $old = $translation->slug;
        if ($old === $slug) {
            return ['old' => $old, 'slug' => $slug, 'changed' => false, 'redirected' => false];
        }

        $redirected = false;
        DB::transaction(function () use ($product, $translation, $slug, $locale, &$redirected): void {
            $this->lock($slug, $locale);
            $category = $product->primaryCategory();
            $why = $this->refusal($slug, $locale, $product->id, $category);
            if ($why !== null) {
                throw new ProductEditRefused($why);
            }

            $translation->update(['slug' => $slug]);
            $product->touch();

            if ($product->status !== 'published') {
                return;
            }
            $before = SiteRoute::query()->where('product_id', $product->id)->where('locale', $locale)->value('path');
            try {
                $after = app(RouteSync::class)->syncProduct($product->id, $category, $locale, $slug);
            } catch (RouteConflictException|UniqueConstraintViolationException) {
                throw new ProductEditRefused(__('catalog.slug_conflict'));
            }
            $redirected = is_string($before) && $before !== $after
                && Redirect::query()->where('locale', $locale)->where('from_path', $before)->where('to_path', $after)->exists();
        });

        return ['old' => $old, 'slug' => $slug, 'changed' => true, 'redirected' => $redirected];
    }
}
