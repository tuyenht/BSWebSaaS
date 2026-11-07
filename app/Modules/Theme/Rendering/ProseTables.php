<?php

declare(strict_types=1);

namespace App\Modules\Theme\Rendering;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Lays out the tables of a sanitized formatted-text value when it is printed, so a table pasted
 * from a spreadsheet or inserted with the editor fits every screen without the editor choosing
 * anything (the stored value never changes: old bodies get the same treatment). Pure: no query,
 * no state; it runs only when the value holds a `<table`.
 *
 * Each outermost table:
 * - loses fixed widths and every height (a cell keeps a width in %), and rows with nothing in them
 *   (not when cells are merged: a span may cover the empty row);
 * - gets a header row: its own `thead`, a first row of header cells, or a first row whose cells are
 *   each wholly bold (with at least three rows; the top-left cell may be empty);
 * - is wrapped in `div.sf-table` with one of three layouts: `kv` (two columns or fewer, always fits),
 *   `stack` (3-4 columns with a header and short text: one card per row on a narrow column) or
 *   `scroll` (everything else, every table with merged cells and every table holding another one:
 *   it scrolls inside its frame). Only a scrolling table is a named region a keyboard can reach;
 *   the name is the nearest heading above it.
 * - labels each data cell with its column's name for the card layout (not when cells are merged:
 *   the column under a merged header is ambiguous), and marks a first column of words as row headers.
 * A table nested in a cell stays as it is, inside its outer table. A table already wrapped is left
 * alone, so a second pass changes nothing.
 */
final class ProseTables
{
    /** A span beyond this is a typing slip, not a layout: it counts as this many columns, no more. */
    private const int MAX_SPAN = 20;

    /** Longest column name carried into a card label, and longest region name. */
    private const int LABEL_LENGTH = 40;

    private const int REGION_NAME_LENGTH = 80;

    /** A cell that starts like a measurement or a number (±0.01, ~5, Ø20, ≤ 3…) is data, not a row header. */
    private const string NUMERIC = '/^[\s~±Ø≈<>≤≥+\-–]*\d/u';

    /** Elements whose edges separate words: `Đơn<br>giá` reads "Đơn giá", not "Đơngiá". */
    private const array BREAKS = ['br', 'p', 'div', 'li', 'ul', 'ol', 'h2', 'h3', 'h4', 'h5', 'blockquote', 'figure', 'figcaption', 'table', 'tr', 'td', 'th'];

    private const string WRAPPED = '~<div class="sf-table[ "][^>]*>\s*$~';

    public static function apply(string $html): string
    {
        if (stripos($html, '<table') === false) {
            return $html;
        }

        $tables = self::outermost($html);
        if ($tables === []) {
            return $html;
        }

        preg_match_all('~<h[2-4]\b[^>]*>(.*?)</h[2-4]>~is', $html, $headings, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $out = '';
        $done = 0;
        foreach ($tables as [$start, $end]) {
            $name = null;
            foreach ($headings as $heading) {
                if ($heading[0][1] >= $start) {
                    break;
                }
                $name = self::text(html_entity_decode(strip_tags((string) preg_replace('~<(?:br|/?(?:p|div|li))\b[^>]*>~i', ' ', $heading[1][0])), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $before = substr($html, $done, $start - $done);
            $fragment = substr($html, $start, $end - $start);
            $out .= $before.(preg_match(self::WRAPPED, $before) === 1 ? $fragment : self::table($fragment, $name));
            $done = $end;
        }

        return $out.substr($html, $done);
    }

    /**
     * [start, end) byte offsets of each table that no other table holds. A table that never closes
     * (not purifier output) is left alone.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function outermost(string $html): array
    {
        preg_match_all('~<(/?)table\b[^>]*>~i', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $found = [];
        $depth = 0;
        $start = 0;
        foreach ($tags as $tag) {
            if ($tag[1][0] === '') {
                if ($depth++ === 0) {
                    $start = $tag[0][1];
                }
            } elseif ($depth > 0 && --$depth === 0) {
                $found[] = [$start, $tag[0][1] + strlen($tag[0][0])];
            }
        }

        return $found;
    }

    private static function table(string $fragment, ?string $heading): string
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$fragment.'</body></html>', LIBXML_NOERROR);
        $table = $document->body?->firstElementChild;
        if (! $table instanceof Element || $table->localName !== 'table') {
            // not a table the parser recognises: still keep it inside its frame
            return '<div class="sf-table sf-table--scroll" role="region" aria-label="'.e(self::attribute($heading ?? __('storefront.table'), self::REGION_NAME_LENGTH)).'" tabindex="0">'.$fragment.'</div>';
        }

        $rows = self::rows($table);
        $merged = false;
        foreach ($rows as $row) {
            foreach (self::cellsOf($row) as $cell) {
                if (self::span($cell, 'colspan') > 1 || self::span($cell, 'rowspan') > 1) {
                    $merged = true;
                    break 2;
                }
            }
        }

        self::dropFixedSizes($table, $rows);
        if (! $merged) {
            $rows = array_values(array_filter($rows, static function (Element $row): bool {
                if (self::words($row) !== '' || $row->querySelector('img, iframe') !== null) {
                    return true;
                }
                $row->remove();

                return false;
            }));
        }

        $head = self::header($document, $table, $rows);
        $names = [];
        foreach ($rows as $row) {
            if ($row->parentElement?->localName !== 'thead') {
                continue;
            }
            $names = [];
            foreach (self::cellsOf($row) as $cell) {
                $names[] = self::words($cell);
                if (! $cell->hasAttribute('scope')) {
                    $cell->setAttribute('scope', 'col');
                }
            }
        }

        $body = array_values(array_filter($rows, static fn (Element $row): bool => $row->parentElement?->localName !== 'thead'));
        foreach ($body as $row) {
            foreach (self::cellsOf($row) as $cell) {
                if ($cell->localName === 'th' && ! $cell->hasAttribute('scope')) {
                    $cell->setAttribute('scope', 'row');
                }
            }
        }
        $columns = 1;
        foreach ($rows as $row) {
            $width = 0;
            foreach (self::cellsOf($row) as $cell) {
                $width += min(self::span($cell, 'colspan'), self::MAX_SPAN);
            }
            $columns = max($columns, $width);
        }

        $lengths = [];
        $firsts = [];
        foreach ($body as $row) {
            foreach (self::cellsOf($row) as $index => $cell) {
                $text = self::words($cell);
                $lengths[] = mb_strlen($text);
                if ($index === 0) {
                    $firsts[] = $text;
                }
            }
        }
        $average = $lengths === [] ? 0 : array_sum($lengths) / count($lengths);
        $longest = $lengths === [] ? 0 : max($lengths);

        $layout = match (true) {
            $merged, $table->querySelector('table') !== null => 'scroll',
            $columns <= 2 => 'kv',
            $columns <= 4 && $head !== null && count($body) <= 10 && $average <= 40 && $longest <= 120 => 'stack',
            default => 'scroll',
        };

        $words = count(array_filter($firsts, static fn (string $text): bool => $text !== '' && preg_match(self::NUMERIC, $text) !== 1));
        $rowHeaders = $head !== null && $columns >= 3 && $firsts !== [] && $words >= 0.8 * count($firsts);

        if ($head !== null && ! $merged) {
            foreach ($body as $row) {
                foreach (self::cellsOf($row) as $index => $cell) {
                    if ($cell->localName === 'td' && ($names[$index] ?? '') !== '') {
                        $cell->setAttribute('data-label', self::attribute($names[$index], self::LABEL_LENGTH));
                    }
                }
            }
        }

        $wrap = $document->createElement('div');
        $wrap->setAttribute('class', 'sf-table sf-table--'.$layout.' sf-cols-'.min($columns, 12)
            .($rowHeaders ? ' sf-table--rowhead' : '').($merged ? ' sf-table--merged' : ''));
        if ($layout === 'scroll') {
            $name = $heading ?? (($names[0] ?? '') !== '' ? __('storefront.table_named', ['name' => $names[0]]) : __('storefront.table'));
            $wrap->setAttribute('role', 'region');
            $wrap->setAttribute('aria-label', self::attribute($name, self::REGION_NAME_LENGTH));
            $wrap->setAttribute('tabindex', '0');
        }
        $table->before($wrap);
        $wrap->append($table);

        return $document->saveHtml($wrap);
    }

    /**
     * The table's header row, moving its first row into a new `thead` when that row is a header:
     * all header cells, or (three rows or more) every cell wholly bold, the top-left one allowed empty.
     *
     * @param  list<Element>  $rows
     */
    private static function header(HTMLDocument $document, Element $table, array $rows): ?Element
    {
        foreach ($rows as $row) {
            if ($row->parentElement?->localName === 'thead') {
                return $row;
            }
        }

        $first = $rows[0] ?? null;
        if ($first === null) {
            return null;
        }
        $cells = self::cellsOf($first);
        $allHeaders = $cells !== [] && array_filter($cells, static fn (Element $cell): bool => $cell->localName !== 'th') === [];
        if (! $allHeaders) {
            $rest = $cells !== [] && self::words($cells[0]) === '' ? array_slice($cells, 1) : $cells;
            if (count($rows) < 3 || $rest === [] || array_filter($rest, static fn (Element $cell): bool => ! self::whollyBold($cell)) !== []) {
                return null;
            }
            foreach ($cells as $cell) {
                foreach ($cell->querySelectorAll('strong, b') as $bold) {
                    $bold->replaceWith(...$bold->childNodes);
                }
                if ($cell->localName === 'td') {
                    $th = $document->createElement('th');
                    foreach ($cell->attributes as $attribute) {
                        $th->setAttribute($attribute->name, $attribute->value);
                    }
                    $th->append(...$cell->childNodes);
                    $cell->replaceWith($th);
                }
            }
        }

        $thead = $document->createElement('thead');
        $table->prepend($thead);
        $thead->append($first);

        return $first;
    }

    private static function whollyBold(Element $cell): bool
    {
        $text = self::words($cell);
        $bold = $cell->querySelector('strong, b');

        return $bold !== null && $text !== '' && mb_strlen($text) <= self::LABEL_LENGTH && self::words($bold) === $text;
    }

    /**
     * Widths in px and every height go (the table always spans its column); a cell keeps a width in %.
     *
     * @param  list<Element>  $rows
     */
    private static function dropFixedSizes(Element $table, array $rows): void
    {
        foreach ([$table, ...$rows, ...array_merge([], ...array_map(self::cellsOf(...), $rows))] as $element) {
            $style = $element->getAttribute('style');
            if ($style === null) {
                continue;
            }
            $kept = '';
            foreach (explode(';', $style) as $declaration) {
                [$property, $value] = array_map(trim(...), explode(':', $declaration, 2)) + [1 => ''];
                $property = strtolower($property);
                if ($property === '' || $property === 'height'
                    || ($property === 'width' && ($element === $table || ! str_ends_with($value, '%')))) {
                    continue;
                }
                $kept .= $property.':'.$value.';';
            }
            $kept === '' ? $element->removeAttribute('style') : $element->setAttribute('style', $kept);
        }
    }

    /**
     * The table's own rows (in thead, tbody or directly under it), never a nested table's.
     *
     * @return list<Element>
     */
    private static function rows(Element $within): array
    {
        $table = $within->localName === 'table' ? $within : $within->closest('table');

        return array_values(array_filter(
            iterator_to_array($within->querySelectorAll('tr'), false),
            static fn (Element $row): bool => $row->closest('table') === $table,
        ));
    }

    /** @return list<Element> */
    private static function cellsOf(Element $row): array
    {
        return array_values(array_filter(
            iterator_to_array($row->children, false),
            static fn (Element $cell): bool => $cell->localName === 'td' || $cell->localName === 'th',
        ));
    }

    private static function span(Element $cell, string $attribute): int
    {
        $span = filter_var($cell->getAttribute($attribute), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($span) ? $span : 1;
    }

    /** The node's text, a space at each edge of a line break or block, runs of whitespace as one space. */
    private static function words(Node $node): string
    {
        return self::text(self::collect($node));
    }

    private static function collect(Node $node): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof Text) {
                $text .= $child->data;
            } elseif ($child instanceof Element) {
                $edge = in_array($child->localName, self::BREAKS, true) ? ' ' : '';
                $text .= $edge.self::collect($child).$edge;
            }
        }

        return $text;
    }

    /** Text with runs of whitespace (non-breaking spaces too) as one space, trimmed. */
    private static function text(string $text): string
    {
        return trim((string) preg_replace('~[\s\x{00A0}]+~u', ' ', $text));
    }

    /**
     * A name for an attribute, cut to length. The serializer leaves `<` raw inside attribute values;
     * one that could open a tag goes, so no later pass over the HTML can read a tag there.
     */
    private static function attribute(string $value, int $length): string
    {
        return mb_substr((string) preg_replace('~<(?=[a-z/!?])~i', '', $value), 0, $length);
    }
}
