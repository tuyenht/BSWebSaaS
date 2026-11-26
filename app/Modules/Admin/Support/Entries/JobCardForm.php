<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Cms\Support\JobDeadline;
use App\Modules\Cms\Support\JobFacts;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\PlainText;
use Carbon\CarbonImmutable;

/**
 * The post editor's «Thông tin tuyển dụng» card (board 07) as a form over `meta.facts` and
 * `meta.badge` (JobFacts). The form edits one language: each short fact's text in it and its tone
 * («Nổi bật» = accent), the badge's text in it, and the application deadline as a day. The deadline
 * is stored as one more fact whose text is «Hạn nộp: dd/mm/yyyy» in each language, so it counts as
 * one of the four (JobDeadline reads it back). The other languages' texts are kept apart, by the
 * fact's key, and written back untouched; a form that changed nothing leaves both keys as stored.
 *
 * @phpstan-type STORED array{others: array<string, array<string, string>>, badge: array<string, string>, deadlineDay: string, deadlineTexts: array<string, string>, seq: int, print: string, form: array{facts: list<array{key: string, text: string, accent: bool}>, deadline: string, deadlineAccent: bool, badge: string}}
 */
final class JobCardForm
{
    /**
     * The form of $meta in $locale.
     *
     * @param  array<array-key, mixed>|null  $meta
     * @return array{facts: list<array{key: string, text: string, accent: bool}>, deadline: string, deadlineAccent: bool, badge: string, stored: array{others: array<string, array<string, string>>, badge: array<string, string>, deadlineDay: string, deadlineTexts: array<string, string>, seq: int, print: string, form: array{facts: list<array{key: string, text: string, accent: bool}>, deadline: string, deadlineAccent: bool, badge: string}}}
     */
    public static function load(?array $meta, string $locale): array
    {
        $facts = [];
        $others = [];
        $deadline = '';
        $deadlineAccent = false;
        $deadlineTexts = [];
        $raw = is_array($meta['facts'] ?? null) && array_is_list($meta['facts']) ? array_slice($meta['facts'], 0, JobFacts::FACTS_MAX) : [];
        // the deadline is the last box that reads as one: the form always writes it last, so a box
        // someone typed the same way before it stays a box
        $deadlineAt = null;
        foreach ($raw as $index => $fact) {
            if (JobDeadline::dayOfFact($fact) !== null) {
                $deadlineAt = $index;
            }
        }
        foreach ($raw as $index => $fact) {
            if (! is_array($fact)) {
                continue;
            }
            $texts = self::texts($fact['text'] ?? null);
            $accent = ($fact['tone'] ?? null) === 'accent';
            $day = $index === $deadlineAt ? JobDeadline::dayOfFact($fact) : null;
            if ($day !== null) {
                $deadline = $day;
                $deadlineAccent = $accent;
                $deadlineTexts = $texts;

                continue;
            }
            $key = 'f'.$index;
            $facts[] = ['key' => $key, 'text' => $texts[$locale] ?? '', 'accent' => $accent];
            unset($texts[$locale]);
            $others[$key] = $texts;
        }
        $badge = self::texts($meta['badge'] ?? null);
        $form = ['facts' => $facts, 'deadline' => $deadline, 'deadlineAccent' => $deadlineAccent, 'badge' => $badge[$locale] ?? ''];
        unset($badge[$locale]);

        return $form + ['stored' => [
            'others' => $others,
            'badge' => $badge,
            'deadlineDay' => $deadline,
            'deadlineTexts' => $deadlineTexts,
            'seq' => count($raw),
            'print' => self::print($form['facts'], $deadline, $deadlineAccent, $form['badge']),
            'form' => $form,
        ]];
    }

    /**
     * $meta with the form's facts and badge in it; as it was when the form is as loaded. In the
     * website's default language ($default) the form's shape is the card's, with the other languages'
     * words kept by box, and an empty badge takes the badge off in every language (a save that changed
     * the card there takes off a badge only other languages have). In another language only its words go
     * in, onto the card $meta holds now (words()): a shape another save gave the card meanwhile stays.
     *
     * @param  array<array-key, mixed>  $meta  the stored meta the save writes over
     * @param  list<array{key: string, text: string, accent: bool}>  $facts
     * @param  STORED  $stored
     * @param  list<string>  $locales  the website's languages (the deadline is written in each)
     * @return array<array-key, mixed>
     */
    public static function into(array $meta, array $facts, string $deadline, bool $deadlineAccent, string $badge, array $stored, string $locale, array $locales, string $default): array
    {
        if (self::print($facts, $deadline, $deadlineAccent, $badge) === $stored['print']) {
            return $meta;
        }

        return $locale === $default
            ? self::build($meta, $facts, $deadline, $deadlineAccent, $badge, $stored, $locale, $locales, $default)
            : self::words($meta, $facts, $badge, $stored, $locale, $default);
    }

    /**
     * $meta with the form's card as the form holds it (its shape, this language's words, the other
     * languages' words as the form read them): what into() writes in the default language, and what
     * the save checks against the stored rule in any language.
     *
     * @param  array<array-key, mixed>  $meta
     * @param  list<array{key: string, text: string, accent: bool}>  $facts
     * @param  STORED  $stored
     * @param  list<string>  $locales
     * @return array<array-key, mixed>
     */
    public static function build(array $meta, array $facts, string $deadline, bool $deadlineAccent, string $badge, array $stored, string $locale, array $locales, string $default): array
    {
        $out = [];
        foreach ($facts as $fact) {
            $texts = $stored['others'][$fact['key']] ?? [];
            $text = self::clean($fact['text']);
            if ($text !== '') {
                $texts[$locale] = $text;
            }
            $out[] = ['text' => $texts, 'tone' => $fact['accent'] ? 'accent' : 'plain'];
        }
        if ($deadline !== '') {
            $texts = $deadline === $stored['deadlineDay'] && $stored['deadlineTexts'] !== [] ? $stored['deadlineTexts'] : self::deadlineTexts($deadline, [$locale, ...$locales]);
            $out[] = ['text' => $texts, 'tone' => $deadlineAccent ? 'accent' : 'plain'];
        }
        if ($out === []) {
            unset($meta['facts']);
        } else {
            $meta['facts'] = $out;
        }

        $text = self::clean($badge);
        // the default language's badge is the card's: none there is none in any language
        $texts = $locale === $default && $text === '' ? [] : $stored['badge'];
        if ($text !== '') {
            $texts[$locale] = $text;
        }
        if ($texts === []) {
            unset($meta['badge']);
        } else {
            $meta['badge'] = $texts;
        }

        return $meta;
    }

    /**
     * $meta (the card as stored now) with this language's words from the form and nothing else: the
     * boxes, their order and tone, the deadline and whether there is a badge stay as $meta has them,
     * as do the other languages' words. A form box goes to the stored box it was read as: the one at
     * its key still worded the same in the default language, else another box worded as it was (a box
     * before it was taken out), else the one at its key (the default language reworded it). A box the
     * form does not have keeps this language's words; one taken out since takes the form's words with
     * it. The badge words go in while the badge exists (the default language has one, or this language
     * does); empty, this language's words come off.
     *
     * @param  array<array-key, mixed>  $meta
     * @param  list<array{key: string, text: string, accent: bool}>  $facts
     * @param  STORED  $stored
     * @return array<array-key, mixed>
     */
    public static function words(array $meta, array $facts, string $badge, array $stored, string $locale, string $default): array
    {
        $now = self::load($meta, $locale);
        $nowOthers = $now['stored']['others'];

        // stored box key => the form's words for it
        $matched = [];
        $used = [];
        $match = static function (callable $fits) use (&$matched, &$used, $now, $facts): void {
            foreach ($now['facts'] as $box) {
                if (isset($matched[$box['key']])) {
                    continue;
                }
                foreach ($facts as $at => $fact) {
                    if (! isset($used[$at]) && $fits($box['key'], $fact['key'])) {
                        $matched[$box['key']] = $fact['text'];
                        $used[$at] = true;
                        break;
                    }
                }
            }
        };
        $was = $stored['others'];
        $match(static fn (string $box, string $form): bool => $box === $form && self::sourceOf($nowOthers, $box, $default) === self::sourceOf($was, $form, $default));
        $match(static fn (string $box, string $form): bool => self::sourceOf($was, $form, $default) !== null && self::sourceOf($nowOthers, $box, $default) === self::sourceOf($was, $form, $default));
        $match(static fn (string $box, string $form): bool => $box === $form);

        $raw = is_array($meta['facts'] ?? null) && array_is_list($meta['facts']) ? $meta['facts'] : [];
        foreach ($now['facts'] as $box) {
            $index = (int) substr($box['key'], 1);
            if (! isset($matched[$box['key']]) || ! is_array($raw[$index] ?? null)) {
                continue;
            }
            $texts = $nowOthers[$box['key']] ?? [];
            $text = self::clean($matched[$box['key']]);
            if ($text !== '') {
                $texts[$locale] = $text;
            }
            if ($texts !== []) {   // a box no language would word keeps the words it has
                $raw[$index]['text'] = $texts;
            }
        }
        if ($raw !== []) {
            $meta['facts'] = $raw;
        }

        $texts = self::texts($meta['badge'] ?? null);
        $exists = isset($texts[$default]) || isset($texts[$locale]);
        unset($texts[$locale]);
        $text = self::clean($badge);
        if ($exists && $text !== '') {
            $texts[$locale] = $text;
        }
        if ($texts === []) {
            unset($meta['badge']);
        } else {
            $meta['badge'] = $texts;
        }

        return $meta;
    }

    /**
     * A box's words in the default language, from the other languages' words kept by key.
     *
     * @param  array<string, array<string, string>>  $others
     */
    private static function sourceOf(array $others, string $key, string $default): ?string
    {
        return $others[$key][$default] ?? null;
    }

    /**
     * What the stored form remembers after a save of $facts / $deadline / $badge: the deadline texts
     * that save wrote, and the form as stored now.
     *
     * @param  array<array-key, mixed>  $meta  the meta the save wrote
     * @param  list<array{key: string, text: string, accent: bool}>  $facts
     * @param  array{others: array<string, array<string, string>>, badge: array<string, string>, deadlineDay: string, deadlineTexts: array<string, string>, seq: int, print: string, form: array{facts: list<array{key: string, text: string, accent: bool}>, deadline: string, deadlineAccent: bool, badge: string}}  $stored
     * @return array{others: array<string, array<string, string>>, badge: array<string, string>, deadlineDay: string, deadlineTexts: array<string, string>, seq: int, print: string, form: array{facts: list<array{key: string, text: string, accent: bool}>, deadline: string, deadlineAccent: bool, badge: string}}
     */
    public static function saved(array $meta, array $facts, string $deadline, bool $deadlineAccent, string $badge, array $stored): array
    {
        // the other languages' badge words the save left (none once the default language took it off)
        $stored['badge'] = array_intersect_key(self::texts($meta['badge'] ?? null), $stored['badge']);
        $texts = [];
        foreach (is_array($meta['facts'] ?? null) ? $meta['facts'] : [] as $fact) {
            if (JobDeadline::dayOfFact($fact) === $deadline && is_array($fact)) {
                $texts = self::texts($fact['text'] ?? null);
            }
        }

        return [
            'deadlineDay' => $deadline,
            'deadlineTexts' => $deadline !== '' ? $texts : [],
            'print' => self::print($facts, $deadline, $deadlineAccent, $badge),
            'form' => ['facts' => $facts, 'deadline' => $deadline, 'deadlineAccent' => $deadlineAccent, 'badge' => $badge],
        ] + $stored;
    }

    /**
     * Whether the form, in a language other than the website's default ($default), keeps the card's
     * shape as stored: the same boxes in the same order with the same tone, the same deadline and its
     * tone, and no badge started. The shape is every language's, so such a language changes only its
     * own words: it may word a badge the default language has, and empty its own (with none in the
     * default language that is the card's shape coming back), but not start one the default language
     * does not have.
     *
     * @param  list<array{key: string, text: string, accent: bool}>  $facts
     * @param  STORED  $stored
     */
    public static function sameShape(array $facts, string $deadline, bool $deadlineAccent, string $badge, array $stored, string $default): bool
    {
        $was = $stored['form'];

        return self::shapeOf($facts) === self::shapeOf($was['facts'])
            && $deadline === $was['deadline']
            && $deadlineAccent === $was['deadlineAccent']
            && ! (self::badgeHeld($stored, $default) && self::clean($badge) !== '');
    }

    /**
     * Whether a language other than the default may not word the badge: the default language has none
     * and this one had none either (wording it would start one).
     *
     * @param  STORED  $stored
     */
    public static function badgeHeld(array $stored, string $default): bool
    {
        return ($stored['badge'][$default] ?? '') === '' && self::clean($stored['form']['badge']) === '';
    }

    /**
     * @param  list<array{key: string, text: string, accent: bool}>  $facts
     * @return list<array{string, bool}>
     */
    private static function shapeOf(array $facts): array
    {
        $shape = [];
        foreach ($facts as $fact) {
            $shape[] = [$fact['key'], $fact['accent']];
        }

        return $shape;
    }

    /** The deadline fact's text in one language: «Hạn nộp: dd/mm/yyyy». */
    public static function deadlineText(string $day, string $locale): string
    {
        $at = CarbonImmutable::createFromFormat('!Y-m-d', $day);

        return Cast::string(__('entries.ed.job_dl_chip', ['date' => $at instanceof CarbonImmutable ? $at->format('d/m/Y') : $day], $locale));
    }

    /** A real calendar day written Y-m-d. */
    public static function validDay(string $day): bool
    {
        $at = CarbonImmutable::createFromFormat('!Y-m-d', $day);

        return $at instanceof CarbonImmutable && $at->format('Y-m-d') === $day;
    }

    /** A fact's or the badge's text as it is stored: one line, trimmed. */
    public static function clean(string $text): string
    {
        return trim(PlainText::line($text));
    }

    /** How many characters a text counts against its limit (the stored form's, as JobFacts counts). */
    public static function length(string $text): int
    {
        return mb_strlen(self::clean($text));
    }

    /**
     * @param  list<string>  $locales
     * @return array<string, string>
     */
    private static function deadlineTexts(string $day, array $locales): array
    {
        $texts = [];
        foreach (array_unique($locales) as $locale) {
            $texts[$locale] = self::deadlineText($day, $locale);
        }

        return $texts;
    }

    /**
     * @param  list<array{key: string, text: string, accent: bool}>  $facts
     */
    private static function print(array $facts, string $deadline, bool $deadlineAccent, string $badge): string
    {
        $parts = [];
        foreach ($facts as $fact) {
            $parts[] = [$fact['key'], self::clean($fact['text']), $fact['accent']];
        }

        return md5((string) json_encode([$parts, $deadline, $deadlineAccent, self::clean($badge)]));
    }

    /**
     * @return array<string, string>
     */
    private static function texts(mixed $map): array
    {
        $texts = [];
        if (! is_array($map)) {
            return $texts;
        }
        foreach ($map as $locale => $text) {
            if (is_string($locale) && $locale !== '' && is_string($text) && trim($text) !== '') {
                $texts[$locale] = $text;
            }
        }

        return $texts;
    }
}
