<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\RichTextSanitizer;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteIdentity;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Theme\Theming\ReadableColor;
use App\Modules\Theme\Theming\ThemeResolver;
use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
use Throwable;

/**
 * A website's look in the mail it sends («Chữ ký & thương hiệu» on the Email page): the logo at the
 * top of every mail, the colour of its buttons and headings, and the signature at its foot. Stored in
 * the site's settings, group `email`: `brand` = {logo_media_id, color} (null colour = the website's own
 * brand colour, so a later change there carries over) and `signature` = {layout, html} (null html = the
 * layout's default, written with the website's variables, so editing «Thông tin chung» updates it).
 *
 * The signature is stored as HTML cleaned by RichTextSanitizer (the `full` profile) with the variables
 * left as `{site_name}`-style words; they are filled when a mail is drawn, after the HTML is cleaned
 * again, and only as text or as a checked link address (never as markup). The shared mail frame (resources/views/vendor/notifications) asks
 * current() for all of it; a mail sent with no website in context, or one whose look cannot be read,
 * goes out in the plain frame — a look never stops a mail.
 */
final readonly class MailBrand
{
    /** Where the logo sits in the signature: beside the words, above them, or no logo. */
    public const array LAYOUTS = ['left', 'top', 'text'];

    /** The button colours offered (the board's eight), colour → its name's language key. */
    public const array SWATCHES = [
        '#2050a0' => 'navy', '#2f6fd0' => 'blue', '#0e7490' => 'cyan', '#0f766e' => 'teal',
        '#15803d' => 'green', '#4338ca' => 'indigo', '#5b3fb3' => 'violet', '#9d174d' => 'plum',
    ];

    /** The website's variables a signature may hold, in the board's order. */
    public const array VARIABLES = ['{site_name}', '{site_tagline}', '{hotline}', '{contact_email}', '{address}', '{site_url}'];

    /** The longest signature HTML accepted from the editor. */
    public const int SIGNATURE_MAX = 6000;

    /** Below this contrast with its text a button colour only suits large text (WCAG AA). */
    public const float AA = 4.5;

    /** The colour a site without a readable brand colour gets. */
    public const string FALLBACK_COLOR = '#2050a0';

    private const string HEADING = '#212529';

    private const string MUTED = '#5b6275';

    /** The logo's height in a mail (px); the width follows the picture, capped. */
    private const int LOGO_HEIGHT = 44;

    private const int LOGO_MAX_WIDTH = 200;

    public function __construct(
        private CurrentSite $current,
        private SettingsRepository $settings,
        private RichTextSanitizer $sanitizer,
        private MediaUrlResolver $media,
    ) {}

    /**
     * A layout's own signature, with the website's variables (one paragraph per line).
     */
    public static function defaultSignature(string $layout): string
    {
        return match ($layout) {
            'top' => '<p style="text-align:center;"><strong>{site_name}</strong></p><p style="text-align:center;">{hotline} · {contact_email}</p><p style="text-align:center;">{address}</p>',
            'text' => '<p><strong>{site_name}</strong> · {hotline} · {contact_email}</p>',
            default => '<p><strong>{site_name}</strong></p><p>{site_tagline}</p><p>{hotline} · {contact_email}</p><p>{address}</p>',
        };
    }

    /** The text colour a button of this colour carries: the darker heading ink or white, whichever reads better. */
    public static function textOn(string $color): string
    {
        return (ReadableColor::ratio($color, self::HEADING) ?? 0) >= (ReadableColor::ratio($color, '#ffffff') ?? 0) ? self::HEADING : '#ffffff';
    }

    /** How well the button's text reads on this colour (the better of the two inks). */
    public static function contrast(string $color): float
    {
        return max(ReadableColor::ratio($color, self::HEADING) ?? 0, ReadableColor::ratio($color, '#ffffff') ?? 0);
    }

    /** A #rgb / #rrggbb colour as lower-case #rrggbb, or null. */
    public static function hex(mixed $color): ?string
    {
        if (! is_string($color)) {
            return null;
        }
        $color = strtolower(trim($color));
        if (preg_match('/\A#([0-9a-f])([0-9a-f])([0-9a-f])\z/', $color, $m) === 1) {
            return '#'.$m[1].$m[1].$m[2].$m[2].$m[3].$m[3];
        }

        return preg_match('/\A#[0-9a-f]{6}\z/', $color) === 1 ? $color : null;
    }

    /** The website's own brand colour (its theme's primary), as its mail buttons take it by default. */
    public function siteColor(): string
    {
        return self::hex(app(ThemeResolver::class)->tokens()['primary'] ?? null) ?? self::FALLBACK_COLOR;
    }

    /**
     * What is saved, read leniently (anything unknown falls back to the default).
     *
     * @return array{logo_media_id: int|null, color: string|null, layout: string, html: string|null}
     */
    public function stored(): array
    {
        $brand = $this->settings->get('email', 'brand') ?? [];
        $signature = $this->settings->get('email', 'signature') ?? [];
        $logo = $brand['logo_media_id'] ?? null;
        $layout = Cast::string($signature['layout'] ?? null, 'left');
        $html = $signature['html'] ?? null;

        return [
            'logo_media_id' => is_int($logo) && $logo > 0 ? $logo : null,
            'color' => self::hex($brand['color'] ?? null),
            'layout' => in_array($layout, self::LAYOUTS, true) ? $layout : 'left',
            'html' => is_string($html) && trim($html) !== '' ? $html : null,
        ];
    }

    /** A signature as it is stored: cleaned, and null when it is the layout's own. */
    public function clean(string $html, string $layout): ?string
    {
        if (trim($html) === '') {
            return null;
        }
        $clean = trim($this->sanitizer->full($html));
        if ($clean === '' || $clean === $this->cleanDefault($layout)) {
            return null;
        }

        return $clean;
    }

    /** A layout's own signature, cleaned once per worker: fixed text, the same for every site. */
    private function cleanDefault(string $layout): string
    {
        /** @var array<string, string> $done */
        static $done = [];

        return $done[$layout] ??= trim($this->sanitizer->full(self::defaultSignature($layout)));
    }

    /**
     * The website's values for the variables, in a language (blank when «Thông tin chung» has none).
     *
     * @return array<string, string>
     */
    public function values(?string $locale = null): array
    {
        $identity = app(SiteIdentity::class)->for($locale ?? app()->getLocale()) ?? [];
        $name = Cast::string($identity['name'] ?? null);
        if ($name === '' && $this->current->hasId()) {
            $name = Cast::string(Site::query()->whereKey($this->current->id())->value('name'));
        }
        $host = $this->current->hasId() ? app(SiteResolver::class)->primaryHostFor($this->current->id()) : null;
        $phone = Cast::string($identity['hotline'] ?? null);

        return [
            '{site_name}' => $name,
            '{site_tagline}' => Cast::string($identity['tagline'] ?? null),
            '{hotline}' => $phone !== '' ? $phone : Cast::string($identity['phone'] ?? null),
            '{contact_email}' => Cast::string($identity['email'] ?? null),
            '{address}' => Cast::string($identity['address'] ?? null),
            '{site_url}' => is_string($host) && $host !== '' ? 'https://'.$host : '',
        ];
    }

    /**
     * The logo a mail shows: a live picture of this website's library, at the mail's size.
     *
     * @return array{url: string, width: int, height: int}|null
     */
    public function logo(?int $mediaId): ?array
    {
        if ($mediaId === null) {
            return null;
        }
        $media = Media::query()->whereKey($mediaId)->first(['id', 'storage_key', 'mime', 'width', 'height']);
        if (! $media instanceof Media || ! str_starts_with($media->mime, 'image/')) {
            return null;
        }
        $height = self::LOGO_HEIGHT;
        $width = $media->width !== null && $media->height !== null && $media->height > 0
            ? (int) round($height * $media->width / $media->height)
            : $height * 3;
        if ($width > self::LOGO_MAX_WIDTH) {
            $height = max(1, (int) round($height * self::LOGO_MAX_WIDTH / $width));
            $width = self::LOGO_MAX_WIDTH;
        }

        return ['url' => $this->media->url($media->storage_key, 400), 'width' => $width, 'height' => $height];
    }

    /**
     * Everything the mail frame draws for the website in context, or null for the plain frame.
     *
     * @return array{name: string, url: string, logo: array{url: string, width: int, height: int}|null, color: string, text: string, signature: string, signature_text: string}|null
     */
    public function current(): ?array
    {
        if (! $this->current->hasId()) {
            return null;
        }

        try {
            $stored = $this->stored();
            $values = $this->values();
            $logo = $this->logo($stored['logo_media_id']);
            $color = $stored['color'] ?? $this->siteColor();
            $signature = $this->signature($stored['html'] ?? self::defaultSignature($stored['layout']), $values, $stored['layout'] === 'top');

            return [
                'name' => $values['{site_name}'],
                'url' => $values['{site_url}'],
                'logo' => $logo,
                'color' => $color,
                'text' => self::textOn($color),
                'signature' => $this->signatureBlock($signature, $stored['layout'] === 'text' ? null : $logo, $stored['layout'], $values['{site_name}']),
                'signature_text' => self::plainText($signature),
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * A stored signature made ready for a mail: cleaned again, the variables filled (as text), the
     * lines a blank variable leaves empty dropped, and each block given the mail's own inline style
     * (mail programs ignore a stylesheet, and the frame's theme would print paragraphs at 16px).
     *
     * @param  array<string, string>  $values
     */
    public function signature(string $html, array $values, bool $centered = false): string
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="sig">'.$this->sanitizer->full($html).'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $root = self::nodes($xpath, '/div')[0] ?? null;
        if (! $root instanceof DOMElement) {
            return '';
        }

        // The variables are filled after cleaning, so a value only ever lands as text (the DOM escapes it)
        // or, in an address, as a checked link: never as markup or an unchecked scheme.
        foreach (self::nodes($xpath, './/text()', $root) as $text) {
            if ($text instanceof DOMText && str_contains($text->data, '{')) {
                $text->data = strtr($text->data, $values);
            }
        }
        foreach (self::nodes($xpath, './/a[@href]|.//img[@src]', $root) as $el) {
            if ($el instanceof DOMElement) {
                self::fillAddress($el, $el->tagName === 'a' ? 'href' : 'src', $values);
            }
        }

        foreach (self::nodes($xpath, './/p|.//div|.//li', $root) as $block) {
            if (! $block instanceof DOMElement) {
                continue;
            }
            self::trimSeparators($block);
            if (preg_replace('/[\s\x{00a0}·|\-]+/u', '', $block->textContent) === '' && self::nodes($xpath, './/img|.//hr', $block) === []) {
                $block->parentNode?->removeChild($block);

                continue;
            }
            $align = str_contains($block->getAttribute('style'), 'text-align') ? '' : ($centered ? 'text-align:center;' : 'text-align:left;');
            self::style($block, 'margin:0;font-size:13px;line-height:1.6;color:'.self::MUTED.';'.$align);
        }
        foreach (self::nodes($xpath, './/strong|.//b', $root) as $strong) {
            if ($strong instanceof DOMElement && ! str_contains($strong->getAttribute('style'), 'color')) {
                self::style($strong, 'color:'.self::HEADING.';');
            }
        }
        foreach (self::nodes($xpath, './/a', $root) as $link) {
            if ($link instanceof DOMElement) {
                self::style($link, 'color:'.self::MUTED.';text-decoration:underline;');
            }
        }
        foreach (self::nodes($xpath, './/img', $root) as $img) {
            if ($img instanceof DOMElement) {
                self::style($img, 'max-width:100%;height:auto;border:0;');
            }
        }
        foreach (self::nodes($xpath, './/hr', $root) as $hr) {
            if ($hr instanceof DOMElement) {
                self::style($hr, 'border:0;border-top:1px solid #e9ebec;margin:10px 0;');
            }
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * The nodes an XPath finds (none for a bad expression).
     *
     * @return list<\DOMNode|\DOMNameSpaceNode>
     */
    private static function nodes(DOMXPath $xpath, string $expression, ?\DOMNode $context = null): array
    {
        $found = $xpath->query($expression, $context);

        return $found === false ? [] : array_values(iterator_to_array($found));
    }

    /**
     * The signature with the logo where its layout puts it, as mail-safe tables.
     *
     * @param  array{url: string, width: int, height: int}|null  $logo
     */
    public function signatureBlock(string $signature, ?array $logo, string $layout, string $name): string
    {
        $img = $logo === null ? '' : '<img src="'.e($logo['url']).'" width="'.$logo['width'].'" height="'.$logo['height'].'" alt="'.e($name).'" style="display:block;border:0;height:'.$logo['height'].'px;width:'.$logo['width'].'px;">';

        if ($img === '') {
            return $signature;
        }
        if ($layout === 'top') {
            return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:0 0 8px;">'.$img.'</td></tr><tr><td align="center">'.$signature.'</td></tr></table>';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td valign="top" style="padding:0 14px 0 0;vertical-align:top;">'.$img.'</td><td valign="top" style="vertical-align:top;">'.$signature.'</td></tr></table>';
    }

    /** The signature as the plain-text part of a mail prints it: one line per block. */
    public static function plainText(string $html): string
    {
        $text = (string) preg_replace('~<(?:br|/p|/div|/li|hr)[^>]*>~i', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_filter(array_map(trim(...), explode("\n", $text)), fn (string $line): bool => $line !== '');

        return implode("\n", $lines);
    }

    /**
     * A link's or picture's address with the website's variables in it (stored percent-encoded):
     * {site_url} becomes the website's address, {contact_email} its email and {hotline} its phone
     * number (a bare one becomes mailto: / tel:). An address that would carry another variable, a
     * blank one, or a scheme other than https/http/mailto/tel is dropped. Other braces are left alone.
     *
     * @param  array<string, string>  $values
     */
    private static function fillAddress(DOMElement $el, string $attribute, array $values): void
    {
        $raw = trim($el->getAttribute($attribute));
        $named = (string) preg_replace_callback('/%7B([a-z_]+)%7D/i', fn (array $m): string => '{'.strtolower($m[1]).'}', $raw);
        if (! array_any(self::VARIABLES, fn (string $token): bool => str_contains($named, $token))) {
            return;
        }
        $phone = preg_match('/\+?[0-9][0-9 .\-]{5,}[0-9]/', $values['{hotline}'] ?? '', $m) === 1 ? (string) preg_replace('/[^0-9+]/', '', $m[0]) : '';
        $email = $values['{contact_email}'] ?? '';
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
        $address = match ($named) {
            '{contact_email}' => $email !== '' ? 'mailto:'.$email : '',
            '{hotline}' => $phone !== '' ? 'tel:'.$phone : '',
            default => strtr($named, [
                '{site_url}' => $values['{site_url}'] ?? '',
                '{contact_email}' => $email,
                '{hotline}' => $phone,
            ]),
        };
        $blank = ($values['{site_url}'] ?? '') === '' && str_contains($named, '{site_url}')
            || $email === '' && str_contains($named, '{contact_email}')
            || $phone === '' && str_contains($named, '{hotline}');
        if ($blank || $address === '' || array_any(self::VARIABLES, fn (string $token): bool => str_contains($address, $token))
            || preg_match('~^(?:https?://|mailto:|tel:)~i', $address) !== 1) {
            $el->removeAttribute($attribute);

            return;
        }
        $el->setAttribute($attribute, $address);
    }

    /** A blank variable leaves «· ·» or a dangling «·» behind: close the gap. */
    private static function trimSeparators(DOMElement $block): void
    {
        $texts = [];
        foreach ((new DOMXPath($block->ownerDocument ?? new DOMDocument))->query('.//text()', $block) ?: [] as $node) {
            if ($node instanceof DOMText) {
                $texts[] = $node;
            }
        }
        foreach ($texts as $node) {
            $node->data = (string) preg_replace('/·(?:[\s\x{00a0}]*·)+/u', '·', $node->data);
        }
        $first = $texts[0] ?? null;
        $last = $texts[count($texts) - 1] ?? null;
        if ($first instanceof DOMText) {
            $first->data = (string) preg_replace('/\A[\s\x{00a0}]*·[\s\x{00a0}]*/u', '', $first->data);
        }
        if ($last instanceof DOMText) {
            $last->data = (string) preg_replace('/[\s\x{00a0}]*·[\s\x{00a0}]*\z/u', '', $last->data);
        }
    }

    /** Puts these declarations in front of the element's own, so what the member set still wins. */
    private static function style(DOMElement $element, string $base): void
    {
        $own = trim($element->getAttribute('style'));
        $element->setAttribute('style', $base.ltrim($own, ';'));
    }
}
