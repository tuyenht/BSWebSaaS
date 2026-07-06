<?php

namespace App\Modules\Shared\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Server-side allowlist sanitizer for admin rich text (Shared: catalog today, CMS bodies
 * later — the admin-rich-editor island is one shared module). Runs ON WRITE so the DB only
 * ever stores clean HTML and the storefront can render the field raw (its CSP is the second
 * net). Two profiles mirror the two toolbars: `basic` for short teaser fields, `full` for
 * article/product bodies (headings, sub/sup for technical units, blockquote, tables, LIBRARY
 * images, youtube/vimeo embeds via SafeIframe, plus a small inline-style allowlist —
 * HTMLPurifier filters style at the CSS-property level, which is why it was chosen over
 * symfony/html-sanitizer). Registered as a singleton; it holds no request/tenant state, so
 * reuse across Octane workers is safe and skips rebuilding the purifier per save.
 */
final class RichTextSanitizer
{
    private const string BASIC_ALLOWED = 'p,br,strong,em,b,i,u,ul,ol,li,a[href]';

    private const string FULL_ALLOWED = 'p[style],br,hr,strong,em,b,i,u,s,sub,sup,ul,ol,li,a[href],'
        .'h2,h3,h4,h5,blockquote,table[style],thead,tbody,tr,td[style],th[style],span[style],'
        .'img[src|alt|width|height],div[class],iframe[src|width|height|allowfullscreen|allow|loading]';

    // Layout props are what the editor's own tools write: indent → margin-left (padding-left
    // inside table cells), column/table drag-resize → width/height. All inert — no script vector.
    private const string ALLOWED_CSS = 'color,background-color,font-size,text-align,'
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

    private function purifier(string $profile, string $allowed): HTMLPurifier
    {
        return $this->purifiers[$profile] ??= new HTMLPurifier($this->config($allowed));
    }

    private function config(string $allowed): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', $allowed);
        $config->set('CSS.AllowedProperties', self::ALLOWED_CSS);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        // Embeds: ONLY youtube (incl. nocookie) and vimeo players survive; every other iframe
        // src is stripped. Images come from the library picker, so src stays plain http(s).
        $config->set('HTML.SafeIframe', true);
        $config->set('URI.SafeIframeRegexp', '%^(?:https?:)?//(?:www\.youtube(?:-nocookie)?\.com/embed/|player\.vimeo\.com/video/)%');
        // No filesystem definition cache: saves are low-frequency and this keeps workers free
        // of writable-path coupling under Octane. MUST precede maybeGetRawHTMLDefinition() —
        // that call finalizes the config and any set() after it throws.
        $config->set('Cache.DefinitionImpl', null);
        // The ONLY class that survives is the video-embed wrapper (VideoEmbed::html) — every
        // other class attribute is stripped, so styles can't be smuggled via class names.
        $config->set('Attr.AllowedClasses', ['video-embed']);
        // HTML5 attributes missing from purifier's stock schema — declared so the youtube
        // player's fullscreen/lazy/permission hints survive sanitizing.
        $config->set('HTML.DefinitionID', 'bsweb-richtext-'.md5($allowed));
        $config->set('HTML.DefinitionRev', 1);
        $definition = $config->maybeGetRawHTMLDefinition();
        if ($definition !== null) {
            $definition->addAttribute('iframe', 'allowfullscreen', 'Bool');
            $definition->addAttribute('iframe', 'allow', 'Text');
            $definition->addAttribute('iframe', 'loading', 'Enum#lazy,eager');
        }

        return $config;
    }
}
