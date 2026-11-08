<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Shared\Support\PreviewMode;
use App\Modules\Storefront\Support\PageChrome;
use App\Modules\Storefront\Support\ProductPage;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Theme\Chrome\StorefrontCsp;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin's preview of a product: the real storefront product page (ProductPage) of a product of
 * the current site, whatever its status, for a signed-in member who manages its products. It lives
 * under the admin's protected routes (signed in, a member of this site, catalog.manage — see
 * routes/web.php), so a link to it shows nothing to anyone else, and it is addressed by the
 * product's ulid: a draft has no public address yet.
 *
 * Fails closed and quietly: a site whose plan has no catalog, a product of another site (RLS), one
 * in the Trash, a language the site does not have or a product without that language all answer
 * 404, never a hint that the product exists. The page is never cached or indexed, sends no referrer,
 * carries the storefront's own Content-Security-Policy (the admin plane sends none) and may be
 * framed only by the admin itself; its lead forms are drawn switched off (PreviewMode), so a
 * preview never records a contact.
 */
final readonly class ProductPreviewController
{
    public function __construct(
        private ProductPage $page,
        private PageChrome $chrome,
        private SiteLocale $locale,
        private PathBuilder $paths,
        private Entitlement $entitlement,
    ) {}

    public function __invoke(Request $request, string $ulid): Response
    {
        abort_unless(in_array('catalog', $this->entitlement->entitledModuleKeys(), true), 404);

        $default = $this->locale->default();
        $locale = $request->query('locale', $default);
        abort_unless(is_string($locale) && $this->locale->isEnabled($locale), 404);

        $product = $this->page->forPreview($ulid, $locale);
        abort_if($product === null, 404);
        $translation = $product->translationFor($locale);
        abort_if($translation === null, 404);

        // The page's own strings (form labels, the preview line) in the content's language, as on
        // the public pages; the admin's language was set by the admin middleware.
        app()->setLocale($locale);
        PreviewMode::enable($request);

        $path = $this->page->path($product, $translation, $locale);
        $chrome = $this->chrome->for($locale, $default, $this->paths->publicUrl($path, $locale), []);

        return response($this->page->render($product, $translation, $locale, $path, $chrome, preview: true))->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => StorefrontCsp::header()."; frame-ancestors 'self'",
        ]);
    }
}
