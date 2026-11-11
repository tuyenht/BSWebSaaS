<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

/**
 * The short facts and the one badge a job post (or any card that wants them) carries in `meta`:
 * `meta.facts` is up to four chips `{text: {locale: string}, tone: plain|accent}` (workplace, type,
 * quantity, salary, "Hạn nộp: dd/mm/yyyy"), `meta.badge` one tag `{locale: string}` ("Tuyển gấp").
 * Neither is filtered or sorted by, so both stay in `meta`. `errors()` is the write rule (an editor
 * save, a revision restore and a content pack all pass it); `read()` is the draw side and keeps only
 * the known keys, so a key nobody validated never reaches a page.
 */
final class JobFacts
{
    public const int FACTS_MAX = 4;

    public const int FACT_TEXT_MAX = 40;

    public const int BADGE_MAX = 24;

    /** @var list<string> */
    public const array TONES = ['plain', 'accent'];

    /**
     * Why `facts` / `badge` of a meta array cannot be stored; empty when they are fine or absent.
     *
     * @param  array<array-key, mixed>  $meta
     * @return list<string>
     */
    public static function errors(array $meta): array
    {
        $errors = [];

        if (array_key_exists('facts', $meta)) {
            $facts = $meta['facts'];
            if (! is_array($facts) || ! array_is_list($facts)) {
                $errors[] = 'facts must be a list.';
            } elseif (count($facts) > self::FACTS_MAX) {
                $errors[] = 'facts holds at most '.self::FACTS_MAX.' items.';
            } else {
                foreach ($facts as $index => $fact) {
                    $at = 'facts item '.($index + 1);
                    if (! is_array($fact)) {
                        $errors[] = "{$at} must be an object with text and tone.";

                        continue;
                    }
                    array_push($errors, ...self::textErrors($fact['text'] ?? null, self::FACT_TEXT_MAX, "{$at} text"));
                    if (array_key_exists('tone', $fact) && ! in_array($fact['tone'], self::TONES, true)) {
                        $errors[] = "{$at} tone must be one of: ".implode(', ', self::TONES).'.';
                    }
                }
            }
        }

        if (array_key_exists('badge', $meta)) {
            array_push($errors, ...self::textErrors($meta['badge'], self::BADGE_MAX, 'badge'));
        }

        return $errors;
    }

    /**
     * The facts and badge of a card in one language. A language with no text falls back to the first
     * one that has some; an item with no text, any key outside text/tone and any tone outside the list
     * (read as plain) are dropped or flattened here. Nothing beyond the first four items is read.
     *
     * @param  array<array-key, mixed>|null  $meta
     * @return array{facts: list<array{text: string, tone: string}>, badge: string}
     */
    public static function read(?array $meta, string $locale): array
    {
        $facts = [];
        $raw = is_array($meta['facts'] ?? null) && array_is_list($meta['facts']) ? array_slice($meta['facts'], 0, self::FACTS_MAX) : [];
        foreach ($raw as $fact) {
            $text = is_array($fact) ? self::pick($fact['text'] ?? null, $locale, self::FACT_TEXT_MAX) : '';
            if ($text === '') {
                continue;
            }
            $tone = $fact['tone'] ?? 'plain';
            $facts[] = ['text' => $text, 'tone' => in_array($tone, self::TONES, true) ? $tone : 'plain'];
        }

        return ['facts' => $facts, 'badge' => self::pick($meta['badge'] ?? null, $locale, self::BADGE_MAX)];
    }

    /**
     * @return list<string>
     */
    private static function textErrors(mixed $map, int $max, string $name): array
    {
        if (! is_array($map) || $map === [] || array_is_list($map)) {
            return ["{$name} must be a text per language."];
        }
        $errors = [];
        foreach ($map as $locale => $text) {
            if (! is_string($locale) || $locale === '' || strlen($locale) > 12) {
                $errors[] = "{$name} has a language key that is not a language.";
            } elseif (! is_string($text) || trim($text) === '') {
                $errors[] = "{$name} ({$locale}) must be a text.";
            } elseif (mb_strlen(trim($text)) > $max) {
                $errors[] = "{$name} ({$locale}) is longer than {$max} characters.";
            }
        }

        return $errors;
    }

    private static function pick(mixed $map, string $locale, int $max): string
    {
        if (! is_array($map)) {
            return '';
        }
        $text = $map[$locale] ?? null;
        if (! is_string($text) || trim($text) === '') {
            $text = '';
            foreach ($map as $other) {
                if (is_string($other) && trim($other) !== '') {
                    $text = $other;
                    break;
                }
            }
        }

        return mb_substr(trim($text), 0, $max);
    }
}
