<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Guide;

use App\Modules\Shared\Support\Cast;

/**
 * The small word tools a guide's text is built with, in the admin's language: a name always quoted
 * («Biên tập»), a control's label left for the browser to quote when the screen shows it ([[Sửa]]),
 * and lists joined the way the language joins them ("a, b và c").
 */
final class GuideText
{
    /** A name quoted whatever the screen shows (a role, a workflow). */
    public static function quote(string $name): string
    {
        return Cast::string(__('guide.ui.quote', ['x' => $name]));
    }

    /** A control's label: quoted in the browser when a control on the screen carries it. */
    public static function label(string $label): string
    {
        return '[['.trim($label).']]';
    }

    /** The label of the element the step points at. */
    public static function target(): string
    {
        return '[[@]]';
    }

    /**
     * Several labels: "[[a]], [[b]] và [[c]]".
     *
     * @param  list<string>  $labels
     */
    public static function labels(array $labels, string $join = 'and'): string
    {
        return self::join(array_map(self::label(...), $labels), $join);
    }

    /**
     * Several words, each lowered at its start, at most $max of them, then "và N mục khác".
     *
     * @param  list<string>  $words
     */
    public static function words(array $words, string $join = 'and', int $max = 6): string
    {
        $words = array_values(array_filter(array_map(fn (string $w): string => self::lower(trim($w)), $words), fn (string $w): bool => $w !== ''));
        $extra = count($words) > $max ? count($words) - $max : 0;
        if ($extra > 0) {
            return implode(Cast::string(__('guide.list.sep')), array_slice($words, 0, $max)).Cast::string(__('guide.list.more', ['n' => $extra]));
        }

        return self::join($words, $join);
    }

    /**
     * @param  list<string>  $items
     */
    public static function join(array $items, string $join = 'and'): string
    {
        if (count($items) < 2) {
            return implode('', $items);
        }
        $last = array_pop($items);

        return implode(Cast::string(__('guide.list.sep')), $items).Cast::string(__('guide.list.'.($join === 'or' ? 'or' : 'and'))).$last;
    }

    /** The first letter lowered ("Bản nháp" → "bản nháp"); a language without case is left as it is. */
    public static function lower(string $text): string
    {
        return mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /** The first letter raised. */
    public static function upper(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
