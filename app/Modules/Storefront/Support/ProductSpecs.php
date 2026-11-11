<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use DOMDocument;
use DOMElement;

/**
 * The specification rows of a product's description (its sanitized HTML): every table row that pairs
 * a name with a value ("Chất liệu | Thép SKD11"), read as schema.org PropertyValue entries for the
 * product's structured data. Only what the reader sees in the table; a product without a table has none.
 */
final class ProductSpecs
{
    /** The most rows kept (a long table is a list, not a specification). */
    private const int MAX = 30;

    /**
     * @return list<array{'@type': string, name: string, value: string}>
     */
    public static function properties(string $descriptionHtml): array
    {
        if (! str_contains($descriptionHtml, '<tr')) {
            return [];
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><body>'.$descriptionHtml.'</body>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $rows = [];
        foreach ($dom->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array($cell->tagName, ['th', 'td'], true)) {
                    $cells[] = trim((string) preg_replace('/\s+/u', ' ', $cell->textContent));
                }
            }
            // A specification row has exactly a name and a value (a header row of three columns is not one).
            if (count($cells) === 2 && $cells[0] !== '' && $cells[1] !== '') {
                $rows[] = ['@type' => 'PropertyValue', 'name' => $cells[0], 'value' => $cells[1]];
            }
        }

        return array_slice($rows, 0, self::MAX);
    }
}
