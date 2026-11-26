<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Shared\Support\Cast;

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
     * $from (an earlier version's meta, restored in $locale) with its `facts` and `badge` brought onto
     * $current the way the editor lets that language change them. The card's shape (which boxes, their
     * order and tone, the deadline, whether there is a badge) is every language's and is set in the
     * website's default language; each language owns only its own words. A box of one card is the
     * box of the other worded the same in the default language at the same place, else one worded the
     * same elsewhere (a box taken out or added before it), else, only while both cards have as many
     * boxes, the one at the same place (the default language reworded it; with a box taken out, added or
     * replaced, a place says nothing); the deadline, the last fact that reads as one, is not a box.
     *  - $locale is not the default: $current's shape stays, with every other language's words; this
     *    language's words come from $from for each box both have (a box that would be left with no
     *    words at all keeps the ones it has), and its badge words only while the badge exists here
     *    (the default language has one, or this language does).
     *  - $locale is the default: $from's shape with its default-language words; the other languages
     *    keep their words from $current for the boxes still there (a box new to them has none yet), and
     *    for the badge while $from has one in the default language (none there: no badge at all). The
     *    deadline keeps $current's words in each language when it names the same day.
     * Every other key of $from is returned as it is, except the shown date (DisplayDate), set in the
     * default language only: another language's version keeps $current's. Every key comes back in the
     * order the database keeps them, so a card the restore leaves as it is does not count as a change.
     *
     * @param  array<array-key, mixed>  $current
     * @param  array<array-key, mixed>  $from
     * @return array<array-key, mixed>
     */
    public static function restored(array $current, array $from, string $locale, string $default): array
    {
        $nowFacts = self::factList($current);
        $nowAt = self::deadlineAt($nowFacts);
        $oldFacts = self::factList($from);
        $oldAt = self::deadlineAt($oldFacts);
        $nowBoxes = self::boxTexts($nowFacts, $nowAt);
        $oldBoxes = self::boxTexts($oldFacts, $oldAt);
        $nowBadge = self::texts($current['badge'] ?? null);
        $oldBadge = self::texts($from['badge'] ?? null);
        $own = static function (array $texts, ?string $word) use ($locale): array {
            unset($texts[$locale]);

            return $word !== null ? [$locale => $word] + $texts : $texts;
        };

        $facts = [];
        $box = 0;
        if ($locale === $default) {
            $pairs = self::pairs($oldBoxes, $nowBoxes, $default);
            foreach ($oldFacts as $index => $fact) {
                if (! is_array($fact)) {
                    $facts[] = $fact;

                    continue;
                }
                if ($index === $oldAt) {
                    $same = $nowAt !== null ? $nowFacts[$nowAt] : null;
                    if (is_array($same) && JobDeadline::dayOfFact($same) === JobDeadline::dayOfFact($fact)) {
                        $fact['text'] = self::texts($same['text'] ?? null);
                    }
                    $facts[] = $fact;

                    continue;
                }
                $mine = $oldBoxes[$box];
                $texts = $own(isset($pairs[$box]) ? $nowBoxes[$pairs[$box]] : [], $mine[$locale] ?? null);
                $fact['text'] = $texts !== [] ? $texts : $mine;   // a box only another language worded: as the version had it
                $facts[] = $fact;
                $box++;
            }
            $badge = isset($oldBadge[$locale]) ? $own($nowBadge, $oldBadge[$locale]) : [];
        } else {
            $pairs = self::pairs($nowBoxes, $oldBoxes, $default);
            foreach ($nowFacts as $index => $fact) {
                if (! is_array($fact) || $index === $nowAt) {
                    $facts[] = $fact;

                    continue;
                }
                if (isset($pairs[$box])) {
                    $texts = $own($nowBoxes[$box], $oldBoxes[$pairs[$box]][$locale] ?? null);
                    $fact['text'] = $texts !== [] ? $texts : $nowBoxes[$box];
                }
                $facts[] = $fact;
                $box++;
            }
            $exists = isset($nowBadge[$default]) || isset($nowBadge[$locale]);
            $badge = $own($nowBadge, $exists ? ($oldBadge[$locale] ?? null) : null);
        }

        $meta = $from;
        unset($meta['facts'], $meta['badge']);
        if ($locale !== $default) {
            unset($meta[DisplayDate::KEY]);
            if (array_key_exists(DisplayDate::KEY, $current)) {
                $meta[DisplayDate::KEY] = $current[DisplayDate::KEY];
            }
        }
        if ($facts !== []) {
            $meta['facts'] = $facts;
        }
        if ($badge !== []) {
            $meta['badge'] = $badge;
        }

        return Cast::arr(self::stored($meta));
    }

    /**
     * Which box of $sources each box of $targets is (target place => source place), as restored() says.
     *
     * @param  list<array<string, string>>  $targets
     * @param  list<array<string, string>>  $sources
     * @return array<int, int>
     */
    private static function pairs(array $targets, array $sources, string $default): array
    {
        $pairs = [];
        $taken = [];
        $steps = [
            static fn (int $t, int $s): bool => $t === $s && ($targets[$t][$default] ?? null) === ($sources[$s][$default] ?? null),
            static fn (int $t, int $s): bool => isset($targets[$t][$default]) && $targets[$t][$default] === ($sources[$s][$default] ?? null),
        ];
        if (count($targets) === count($sources)) {
            $steps[] = static fn (int $t, int $s): bool => $t === $s;
        }
        foreach ($steps as $fits) {
            foreach (array_keys($targets) as $t) {
                if (isset($pairs[$t])) {
                    continue;
                }
                foreach (array_keys($sources) as $s) {
                    if (! isset($taken[$s]) && $fits($t, $s)) {
                        $pairs[$t] = $s;
                        $taken[$s] = true;
                        break;
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * $value with every object's keys in the order PostgreSQL's jsonb keeps them (shorter keys
     * first, then byte order), so it compares equal to the same value read back from the database.
     */
    private static function stored(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::stored(...), $value);
        if (! array_is_list($value)) {
            uksort($value, static fn (int|string $a, int|string $b): int => [strlen((string) $a), (string) $a] <=> [strlen((string) $b), (string) $b]);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $meta
     * @return list<mixed>
     */
    private static function factList(array $meta): array
    {
        $facts = $meta['facts'] ?? null;

        return is_array($facts) && array_is_list($facts) ? $facts : [];
    }

    /**
     * Where the deadline is among $facts: the last fact that reads as one (as the editor reads it).
     *
     * @param  list<mixed>  $facts
     */
    private static function deadlineAt(array $facts): ?int
    {
        $at = null;
        foreach ($facts as $index => $fact) {
            if (JobDeadline::dayOfFact($fact) !== null) {
                $at = $index;
            }
        }

        return $at;
    }

    /**
     * Each box's words by language, in order (the deadline left out).
     *
     * @param  list<mixed>  $facts
     * @return list<array<string, string>>
     */
    private static function boxTexts(array $facts, ?int $deadlineAt): array
    {
        $boxes = [];
        foreach ($facts as $index => $fact) {
            if (is_array($fact) && $index !== $deadlineAt) {
                $boxes[] = self::texts($fact['text'] ?? null);
            }
        }

        return $boxes;
    }

    /**
     * A text map's non-empty words by language.
     *
     * @return array<string, string>
     */
    private static function texts(mixed $map): array
    {
        $texts = [];
        foreach (is_array($map) ? $map : [] as $locale => $text) {
            if (is_string($locale) && $locale !== '' && is_string($text) && trim($text) !== '') {
                $texts[$locale] = $text;
            }
        }

        return $texts;
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
