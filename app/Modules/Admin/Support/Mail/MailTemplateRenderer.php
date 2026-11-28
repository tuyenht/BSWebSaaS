<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Mail;

use App\Modules\Shared\Support\RichTextSanitizer;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

/**
 * Turns a stored mail template into what a mail carries, in the one safe order:
 *
 *  1. the body is cleaned again (RichTextSanitizer, as when it was saved), with the variables still
 *     `{name}` words — so no value ever passes the cleaner, and the cleaner never sees a value;
 *  2. the variables are filled into the cleaned DOM: in text as text (the DOM escapes `<b>` to
 *     `&lt;b&gt;`), in a link's address only as a checked https/http address (a `javascript:` value
 *     or anything else drops the link and keeps its words);
 *  3. a paragraph holding only a link becomes the mail's button, in the website's colour.
 *
 * The subject is plain text: no tag, no line break, at most 200 characters, values filled as text.
 */
final class MailTemplateRenderer
{
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    /** A typed subject as stored: tags and line breaks dropped, spaces collapsed. */
    public static function plainSubject(string $subject): string
    {
        $plain = strip_tags(preg_replace('/<br\s*\/?>/i', ' ', $subject) ?? '');

        return trim(preg_replace('/[\s\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $plain) ?? '');
    }

    /**
     * A body as stored: cleaned, and a variable address kept as its `{name}` word (the cleaner
     * percent-encodes the braces).
     */
    public function clean(string $html): string
    {
        $clean = trim($this->sanitizer->full($html));

        return trim(preg_replace_callback('/href="%7B([a-z_]+)%7D"/i', static fn (array $m): string => 'href="{'.strtolower($m[1]).'}"', $clean) ?? '');
    }

    /**
     * The mail's subject: plain text with the values filled in, at most 200 characters.
     *
     * @param  array<string, string>  $values  `{name}` → value
     */
    public function subject(string $subject, array $values): string
    {
        // the wording loses its tags once; a value is text, so a "<" in it stays (no second strip)
        $oneLine = static fn (string $v): string => trim(preg_replace('/[\s\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $v) ?? '');
        $plainValues = array_map($oneLine, $values);

        return mb_substr($oneLine(strtr(self::plainSubject($subject), $plainValues)), 0, MailTemplateCatalog::SUBJECT_MAX);
    }

    /**
     * The mail's body as one line of HTML.
     *
     * @param  array<string, string>  $values  `{name}` → value (plain text)
     */
    public function html(string $body, array $values, string $color, string $ink): string
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="tpl">'.$this->sanitizer->full($body).'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $root = self::nodes($xpath, '/div')[0] ?? null;
        if (! $root instanceof DOMElement) {
            return '';
        }

        foreach (self::nodes($xpath, './/iframe', $root) as $frame) {
            $frame->parentNode?->removeChild($frame);
        }
        foreach (self::nodes($xpath, './/*[@class]', $root) as $el) {
            if ($el instanceof DOMElement) {
                $el->removeAttribute('class');
            }
        }

        foreach (self::nodes($xpath, './/text()', $root) as $text) {
            if ($text instanceof DOMText && str_contains($text->data, '{')) {
                $text->data = strtr($text->data, $values);
            }
        }
        foreach (self::nodes($xpath, './/a', $root) as $a) {
            if ($a instanceof DOMElement) {
                self::fillLink($a, $values, $color);
            }
        }
        foreach (self::nodes($xpath, './/img[@src]', $root) as $img) {
            if ($img instanceof DOMElement && ! self::safeAddress($img->getAttribute('src'), ['https', 'http'])) {
                $img->parentNode?->removeChild($img);
            }
        }

        // A block a blank variable left with no words (an empty personal message's quotes) goes.
        foreach (self::nodes($xpath, './/p|.//li', $root) as $block) {
            if ($block instanceof DOMElement && preg_match('/[\p{L}\p{N}]/u', $block->textContent) !== 1
                && self::nodes($xpath, './/img|.//a', $block) === []) {
                $block->parentNode?->removeChild($block);
            }
        }

        foreach (self::nodes($xpath, './/p', $root) as $p) {
            if ($p instanceof DOMElement) {
                self::button($dom, $p, $color, $ink);
            }
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim(preg_replace('/\s*[\r\n]+\s*/', ' ', $out) ?? '');
    }

    /**
     * Whether a cleaned body holds a real link to a link variable: an `<a>` whose whole address is
     * the variable and whose own words (not a variable's) have a letter or digit (text that merely
     * reads `href="{x}"`, or a link that may be sent empty, does not count).
     */
    public static function hasLink(string $clean, string $token): bool
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div>'.$clean.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = strtolower(preg_replace('/%7B([a-z_]+)%7D/i', '{$1}', trim($a->getAttribute('href'))) ?? '');
            // the words without their variables (a name or a message can be empty when sent)
            $words = preg_replace('/\{[a-z_]+\}/', '', $a->textContent) ?? '';
            if ($href === $token && preg_match('/[\p{L}\p{N}]/u', $words) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * A cleaned body with the links to these variables in plain sight: every element around such a
     * link loses its inline style but a plain text-align (a size, a colour, a height or a width could
     * shrink or paint the link away and leave a look-alike link beside it), and a sub/sup around it
     * gives way to its contents (nested, they shrink text too). The link itself takes no style (the
     * cleaner keeps none on `<a>`; the mail sets its colour). Unchanged when no link needs it.
     *
     * @param  list<string>  $tokens  link variables, `{name}`
     */
    public static function exposeLinks(string $clean, array $tokens): string
    {
        if ($tokens === [] || ! str_contains($clean, '<a')) {
            return $clean;
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="tpl">'.$clean.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $dom->getElementsByTagName('div')->item(0);
        if (! $root instanceof DOMElement) {
            return $clean;
        }
        $changed = false;
        foreach (self::nodes(new DOMXPath($dom), './/a', $root) as $a) {
            if (! $a instanceof DOMElement || ! in_array(strtolower(preg_replace('/%7B([a-z_]+)%7D/i', '{$1}', trim($a->getAttribute('href'))) ?? ''), $tokens, true)) {
                continue;
            }
            if ($a->hasAttribute('style')) {
                $a->removeAttribute('style');
                $changed = true;
            }
            $node = $a->parentNode;
            while ($node instanceof DOMElement && ! $node->isSameNode($root)) {
                $parent = $node->parentNode;
                if (in_array(strtolower($node->tagName), ['sub', 'sup'], true)) {
                    while ($node->firstChild !== null) {
                        $parent?->insertBefore($node->firstChild, $node);
                    }
                    $parent?->removeChild($node);
                    $changed = true;
                } elseif ($node->hasAttribute('style')) {
                    $keep = preg_match('/(?:^|;)\s*text-align\s*:\s*(left|center|right|justify)\s*(?:;|$)/i', $node->getAttribute('style'), $m) === 1
                        ? 'text-align:'.strtolower($m[1]).';' : '';
                    if ($keep !== $node->getAttribute('style')) {
                        $keep === '' ? $node->removeAttribute('style') : $node->setAttribute('style', $keep);
                        $changed = true;
                    }
                }
                $node = $parent;
            }
        }
        if (! $changed) {
            return $clean;
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim(preg_replace('/href="%7B([a-z_]+)%7D"/i', 'href="{$1}"', $out) ?? '');
    }

    /** The plain-text part of a mail, from its HTML body. */
    public static function text(string $html): string
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="tpl">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $dom->getElementsByTagName('div')->item(0);
        if ($root === null) {
            return '';
        }
        $text = self::textOf($root);
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    private static function textOf(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return preg_replace('/\s+/u', ' ', $node->data) ?? '';
        }
        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= self::textOf($child);
        }
        if (! $node instanceof DOMElement) {
            return $inner;
        }

        return match (strtolower($node->tagName)) {
            'br' => "\n",
            'a' => self::linkText(trim($inner), $node->getAttribute('href')),
            'li' => '- '.trim($inner)."\n",
            'p', 'div', 'table', 'h2', 'h3', 'h4', 'h5', 'blockquote' => "\n".trim($inner)."\n\n",
            'ul', 'ol' => "\n".$inner."\n",
            default => $inner,
        };
    }

    private static function linkText(string $words, string $href): string
    {
        if ($href === '' || $href === $words) {
            return $words !== '' ? $words : $href;
        }

        return $words !== '' ? $words.': '.$href : $href;
    }

    /**
     * A link's address: a `{name}` address takes its value when that is a web address; any other
     * address must be a web, mail or phone one, with no variable inside it (a variable is the whole
     * address or nothing: `https://elsewhere/?u={reset_url}` would carry a sign-in link away).
     * Otherwise the link goes and its words stay.
     *
     * @param  array<string, string>  $values
     */
    private static function fillLink(DOMElement $a, array $values, string $color): void
    {
        $href = preg_replace('/%7B([a-z_]+)%7D/i', '{$1}', trim($a->getAttribute('href'))) ?? '';
        $filled = strtr($href, $values);
        $ok = $href !== '' && (preg_match('/^\{[a-z_]+\}$/', $href) === 1
            ? MailTemplateCatalog::isLinkVar($href) && isset($values[$href]) && self::safeAddress($filled, ['https', 'http'])
            : ! str_contains($href, '{') && self::safeAddress($href, ['https', 'http', 'mailto', 'tel']));
        if (! $ok) {
            while ($a->firstChild !== null) {
                $a->parentNode?->insertBefore($a->firstChild, $a);
            }
            $a->parentNode?->removeChild($a);

            return;
        }
        $a->setAttribute('href', $filled);
        $a->setAttribute('target', '_blank');
        $a->setAttribute('rel', 'noopener');
        $a->setAttribute('style', 'color:'.$color.';text-decoration:underline;');
    }

    /** @param  list<string>  $schemes */
    private static function safeAddress(string $url, array $schemes): bool
    {
        if ($url === '' || preg_match('/[\s<>"\'`\x00-\x1f]/', $url) === 1) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, $schemes, true)) {
            return false;
        }

        return ! in_array($scheme, ['https', 'http'], true) || filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /** A paragraph holding only one link: the mail's button, aligned as the paragraph was. */
    private static function button(DOMDocument $dom, DOMElement $p, string $color, string $ink): void
    {
        $links = [];
        foreach ($p->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if (strtolower($child->tagName) !== 'a') {
                    return;
                }
                $links[] = $child;
            } elseif ($child instanceof DOMText && trim($child->data, " \t\n\r\0\x0B\u{00a0}") !== '') {
                return;
            }
        }
        if (count($links) !== 1 || trim($links[0]->textContent) === '') {
            return;
        }
        $link = $links[0];
        $align = preg_match('/text-align:\s*(left|center|right)/i', $p->getAttribute('style'), $m) === 1 ? strtolower($m[1]) : 'left';

        $outer = $dom->createElement('table');
        foreach (['class' => 'action', 'width' => '100%', 'cellpadding' => '0', 'cellspacing' => '0', 'role' => 'presentation', 'style' => 'margin:24px 0;'] as $k => $v) {
            $outer->setAttribute($k, $v);
        }
        $cell = $dom->createElement('td');
        $cell->setAttribute('align', $align);
        $inner = $dom->createElement('table');
        foreach (['border' => '0', 'cellpadding' => '0', 'cellspacing' => '0', 'role' => 'presentation'] as $k => $v) {
            $inner->setAttribute($k, $v);
        }
        $face = $dom->createElement('td');
        $face->setAttribute('style', 'border-radius:4px;background-color:'.$color.';');
        $a = $dom->createElement('a');
        $a->appendChild($dom->createTextNode(trim($link->textContent)));
        $a->setAttribute('href', $link->getAttribute('href'));
        $a->setAttribute('target', '_blank');
        $a->setAttribute('rel', 'noopener');
        $a->setAttribute('style', 'display:inline-block;border-radius:4px;background-color:'.$color.';border:8px solid '.$color.';border-left-width:18px;border-right-width:18px;color:'.$ink.';font-weight:600;text-decoration:none;');

        $face->appendChild($a);
        $innerRow = $dom->createElement('tr');
        $innerRow->appendChild($face);
        $inner->appendChild($innerRow);
        $cell->appendChild($inner);
        $outerRow = $dom->createElement('tr');
        $outerRow->appendChild($cell);
        $outer->appendChild($outerRow);
        $p->parentNode?->replaceChild($outer, $p);
    }

    /**
     * @return list<DOMNode>
     */
    private static function nodes(DOMXPath $xpath, string $expression, ?DOMNode $context = null): array
    {
        $found = $xpath->query($expression, $context);
        if ($found === false) {
            return [];
        }
        $out = [];
        foreach ($found as $node) {
            if ($node instanceof DOMNode) {
                $out[] = $node;
            }
        }

        return $out;
    }
}
