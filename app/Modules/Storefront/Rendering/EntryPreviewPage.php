<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Rendering;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\ResolvedContent;
use App\Modules\Shared\Support\PreviewMode;
use App\Modules\Storefront\Support\PageChrome;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Chrome\StorefrontCsp;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin's previews of a page or post: «Xem trước» (StorefrontController::preview, the saved
 * version) and the page builder's frame (BuilderPreviewController, the editor's unsaved blocks in a
 * translation that lives only in memory). The caller has found the entry and checked who may see it;
 * this draws the real storefront page of it in $locale, exactly as a published one would be drawn,
 * in preview mode (PreviewMode: forms switched off, no view counted, a band saying it is a preview,
 * nothing for search engines or link previews), and answers with the preview headers: never cached
 * or indexed, no referrer, the storefront's own Content-Security-Policy, framed by the admin only.
 */
final readonly class EntryPreviewPage
{
    public function __construct(
        private EntryPage $pages,
        private PathBuilder $paths,
        private PageChrome $pageChrome,
        private SiteLocale $locales,
    ) {}

    /**
     * The headers of every preview answer, and of the builder frame's error pages: the same CSP, so
     * the frame never runs anything a preview would not.
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => StorefrontCsp::header()."; frame-ancestors 'self'",
        ];
    }

    public function respond(Request $request, Entry $entry, EntryTranslation $translation, string $locale): Response
    {
        // The page's own strings in the content's language, as on the public pages.
        app()->setLocale($locale);
        PreviewMode::enable($request);

        $path = $this->paths->forEntry($entry, $locale, (string) $translation->slug);
        $chrome = $this->pageChrome->for($locale, $this->locales->default(), $this->paths->publicUrl($path, $locale), []);

        $response = $this->pages->render(new ResolvedContent($entry, $translation), $locale, $path, $chrome, preview: true);
        $response->headers->add(self::headers());

        return $response;
    }
}
