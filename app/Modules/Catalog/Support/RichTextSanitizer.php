<?php

namespace App\Modules\Catalog\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Server-side allowlist sanitizer for catalog rich text. Runs ON WRITE so the DB only ever
 * stores clean HTML and the storefront can later render the field raw (its CSP is the second
 * net). Two profiles mirror the two editor toolbars: `basic` for the short summary teaser,
 * `full` for the detail description (headings, blockquote, tables, plus a small inline-style
 * allowlist — HTMLPurifier filters style at the CSS-property level, which is why it was chosen
 * over symfony/html-sanitizer). No <img>/<iframe>: product imagery goes through the media
 * gallery, never inline HTML. Registered as a singleton; it holds no request/tenant state, so
 * reuse across Octane workers is safe and skips rebuilding the purifier per save.
 */
final class RichTextSanitizer
{
    private const string BASIC_ALLOWED = 'p,br,strong,em,b,i,u,ul,ol,li,a[href]';

    private const string FULL_ALLOWED = 'p[style],br,strong,em,b,i,u,s,ul,ol,li,a[href],'
        .'h2,h3,h4,blockquote,table,thead,tbody,tr,td[style],th[style],span[style]';

    private const string ALLOWED_CSS = 'color,background-color,font-size,text-align';

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
        // No filesystem definition cache: saves are low-frequency and this keeps workers free
        // of writable-path coupling under Octane.
        $config->set('Cache.DefinitionImpl', null);

        return $config;
    }
}
