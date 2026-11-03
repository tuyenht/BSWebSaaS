<?php

namespace App\Modules\Shared\Support;

use HTMLPurifier;
use HTMLPurifier_AttrDef_Enum;
use HTMLPurifier_AttrDef_Integer;
use HTMLPurifier_AttrTransform_ImgRequired;
use HTMLPurifier_Config;
use HTMLPurifier_ElementDef;
use HTMLPurifier_HTMLDefinition;

/**
 * Server-side allowlist sanitizer for admin rich text (Shared: catalog and CMS bodies — the
 * admin-rich-editor island is one shared module). Runs ON WRITE so the DB only ever stores clean
 * HTML and the storefront can render the field raw (its CSP is the second net). Three profiles:
 * `basic` for short teaser fields, `full` for product bodies (headings, sub/sup for technical
 * units, blockquote, tables, LIBRARY images by URL, youtube/vimeo embeds via SafeIframe, plus a
 * small inline-style allowlist — HTMLPurifier filters style at the CSS-property level, which is
 * why it was chosen over symfony/html-sanitizer), and `cms` for page/post bodies: `full` with
 * images that name a library picture by id instead of an address (figure/figcaption +
 * `img[data-media-id|data-size|data-align|alt]`, never a `src`) — the address is built by the
 * server when the page is drawn. Registered as a singleton; it holds no request/tenant state, so
 * reuse across Octane workers is safe and skips rebuilding the purifier per save.
 */
final class RichTextSanitizer
{
    /** How wide a picture in a CMS body is drawn, as a share of the text column (the editor's choices). */
    public const array IMAGE_SIZES = ['full', 'lg', 'md', 'sm'];

    /** Where a picture narrower than the column sits. */
    public const array IMAGE_ALIGNS = ['left', 'center', 'right'];

    /** The video players a frame may load (RichHtml holds stored frames to the same rule). */
    public const string SAFE_IFRAME = '%^(?:https?:)?//(?:www\.youtube(?:-nocookie)?\.com/embed/|player\.vimeo\.com/video/)%';

    private const string BASIC_ALLOWED = 'p,br,strong,em,b,i,u,ul,ol,li,a[href]';

    private const string TEXT_ALLOWED = 'p[style],br,hr,strong,em,b,i,u,s,sub,sup,ul,ol,li,a[href],'
        .'h2,h3,h4,h5,blockquote,table[style],thead,tbody,tr,td[style],th[style],span[style],'
        .'div[class],iframe[src|width|height|allowfullscreen|allow|loading]';

    private const string FULL_ALLOWED = self::TEXT_ALLOWED.',img[src|alt|width|height]';

    /** The CMS allow-list, `tag[attr|attr]` comma-separated (RichHtml reads it to recognise clean output). */
    public const string CMS_ALLOWED = self::TEXT_ALLOWED.',figure,figcaption,img[data-media-id|data-size|data-align|alt]';

    /**
     * The inline-style properties every profile keeps, comma-separated (RichHtml reads it to
     * recognise clean output). Layout props are what the editor's own tools write: indent →
     * margin-left (padding-left inside table cells), column/table drag-resize → width/height.
     * All inert — no script vector.
     */
    public const string ALLOWED_CSS = 'color,background-color,font-size,text-align,'
        .'margin-left,padding-left,width,height';

    /** @var array<string, HTMLPurifier> */
    private array $purifiers = [];

    public function basic(string $html): string
    {
        return $this->purifier('basic', self::BASIC_ALLOWED)->purify($html);
    }

    public function full(string $html): string
    {
        return $this->purifier('full', self::FULL_ALLOWED)->purify($html);
    }

    /** A page or post body's formatted text (the `html` prop of a page-builder block). */
    public function cms(string $html): string
    {
        return $this->purifier('cms', self::CMS_ALLOWED, libraryImages: true)->purify($html);
    }

    private function purifier(string $profile, string $allowed, bool $libraryImages = false): HTMLPurifier
    {
        return $this->purifiers[$profile] ??= new HTMLPurifier($this->config($allowed, $libraryImages));
    }

    private function config(string $allowed, bool $libraryImages): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', $allowed);
        $config->set('CSS.AllowedProperties', self::ALLOWED_CSS);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        // Embeds: ONLY youtube (incl. nocookie) and vimeo players survive; every other iframe
        // src is stripped. Images come from the library picker, so src stays plain http(s).
        $config->set('HTML.SafeIframe', true);
        $config->set('URI.SafeIframeRegexp', self::SAFE_IFRAME);
        // No filesystem definition cache: saves are low-frequency and this keeps workers free
        // of writable-path coupling under Octane. MUST precede maybeGetRawHTMLDefinition() —
        // that call finalizes the config and any set() after it throws.
        $config->set('Cache.DefinitionImpl', null);
        // The ONLY class that survives is the video-embed wrapper (VideoEmbed::html) — every
        // other class attribute is stripped, so styles can't be smuggled via class names.
        $config->set('Attr.AllowedClasses', ['video-embed']);
        // HTML5 attributes missing from purifier's stock schema — declared so the youtube
        // player's fullscreen/lazy/permission hints survive sanitizing. Bump the revision when a
        // custom definition below changes.
        $config->set('HTML.DefinitionID', 'bsweb-richtext-'.md5($allowed));
        $config->set('HTML.DefinitionRev', 2);
        $definition = $config->maybeGetRawHTMLDefinition();
        if ($definition !== null) {
            $definition->addAttribute('iframe', 'allowfullscreen', 'Bool');
            $definition->addAttribute('iframe', 'allow', 'Text');
            $definition->addAttribute('iframe', 'loading', 'Enum#lazy,eager');
            if ($libraryImages) {
                self::libraryImages($definition);
            }
        }
        if ($libraryImages) {
            self::noInventedAlt($config);
        }

        return $config;
    }

    /**
     * The CMS picture: a figure (with an optional caption first or last) around an img that names a
     * library picture by a positive whole-number id, with an enumerated size and alignment. The
     * stock img requires `src` and `alt`; here `src` is removed outright (so no address, of any
     * scheme, can be stored), `data-media-id` is required (an img without a valid id is dropped)
     * and `alt` is optional (the library's alt text fills in at print).
     * Core.RemoveInvalidImg (on by default) keeps purifier from inventing a src; noInventedAlt()
     * keeps it from inventing an alt.
     */
    private static function libraryImages(HTMLPurifier_HTMLDefinition $definition): void
    {
        // A caption lives only inside a figure: it joins no content set, so anywhere else it is unwrapped.
        $definition->addElement('figure', 'Block', 'Optional: figcaption | Flow', 'Common');
        $definition->addElement('figcaption', false, 'Flow', 'Common');
        $definition->addElement('img', 'Inline', 'Empty', 'Common', [
            // Purifier expands each module's attributes before merging the next, so the stock
            // img's `src*`/`alt*` are already plain `src`/`alt` here: false removes, a type replaces.
            'src' => false,
            'alt' => 'Text',
            // required: an img that names no library picture (or a malformed id) is dropped whole
            'data-media-id*' => new HTMLPurifier_AttrDef_Integer(false, false, true),
            'data-size' => new HTMLPurifier_AttrDef_Enum(self::IMAGE_SIZES),
            'data-align' => new HTMLPurifier_AttrDef_Enum(self::IMAGE_ALIGNS),
        ]);
    }

    /**
     * The stock img carries a transform that, before the attributes are checked, fills a missing
     * alt from the file name of the src — so `<img data-media-id="5" src="https://evil/x.png">`
     * would be stored with `alt="x.png"` (the src itself then dropped), and that alt would beat the
     * library's at print. A library picture has no src to name, so the CMS img goes without it: a
     * missing alt stays missing. Transforms merge by appending, so they are filtered out of the
     * finished definition (this config's own; the other profiles keep the stock img).
     */
    private static function noInventedAlt(HTMLPurifier_Config $config): void
    {
        $definition = $config->getHTMLDefinition();
        $elements = $definition instanceof HTMLPurifier_HTMLDefinition && is_array($definition->info) ? $definition->info : [];
        $img = $elements['img'] ?? null;
        if (! $img instanceof HTMLPurifier_ElementDef || ! is_array($img->attr_transform_pre) || ! is_array($img->attr_transform_post)) {
            return;
        }
        $keep = static fn (mixed $transform): bool => ! $transform instanceof HTMLPurifier_AttrTransform_ImgRequired;
        $img->attr_transform_pre = array_values(array_filter($img->attr_transform_pre, $keep));
        $img->attr_transform_post = array_values(array_filter($img->attr_transform_post, $keep));
    }
}
