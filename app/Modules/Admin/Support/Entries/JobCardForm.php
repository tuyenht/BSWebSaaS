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
 */
final class JobCardForm
{
    /**
     * The form of $meta in $locale.
     *
     * @param  array<array-key, mixed>|null  $meta
     * @return array{facts: list<array{key: string, text: string, accent: bool}>, deadline: string, deadlineAccent: bool, badge: string, stored: array{others: array<string, array<string, string>>, badge: array<string, string>, deadlineDay: string, deadlineTexts: array<string, string>, seq: int, print: string}}
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
        ]];
    }

    /**
     * $meta with the form's facts and badge in it; as it was when the form is as loaded.
     *
     * @param  array<array-key, mixed>  $meta
     * @param  list<array{key: string, text: string, accent: bool}>  $facts
     * @param  array{others: array<string, array<string, string>>, badge: array<string, string>, deadlineDay: string, deadlineTexts: array<string, string>, seq: int, print: string}  $stored
     * @param  list<string>  $locales  the website's languages (the deadline is written in each)
     * @return array<array-key, mixed>
     */
    public static function into(array $meta, array $facts, string $deadline, bool $deadlineAccent, string $badge, array $stored, string $locale, array $locales): array
    {
        if (self::print($facts, $deadline, $deadlineAccent, $badge) === $stored['print']) {
            return $meta;
        }

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

        $texts = $stored['badge'];
        $text = self::clean($badge);
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
     * What the stored form remembers after a save of $facts / $deadline / $badge: the deadline texts
     * that save wrote, and the form as stored now.
     *
     * @param  array<array-key, mixed>  $meta  the meta the save wrote
     * @param  list<array{key: string, text: string, accent: bool}>  $facts
     * @param  array{others: array<string, array<string, string>>, badge: array<string, string>, deadlineDay: string, deadlineTexts: array<string, string>, seq: int, print: string}  $stored
     * @return array{others: array<string, array<string, string>>, badge: array<string, string>, deadlineDay: string, deadlineTexts: array<string, string>, seq: int, print: string}
     */
    public static function saved(array $meta, array $facts, string $deadline, bool $deadlineAccent, string $badge, array $stored): array
    {
        $texts = [];
        foreach (is_array($meta['facts'] ?? null) ? $meta['facts'] : [] as $fact) {
            if (JobDeadline::dayOfFact($fact) === $deadline && is_array($fact)) {
                $texts = self::texts($fact['text'] ?? null);
            }
        }

        return ['deadlineDay' => $deadline, 'deadlineTexts' => $deadline !== '' ? $texts : [], 'print' => self::print($facts, $deadline, $deadlineAccent, $badge)] + $stored;
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
