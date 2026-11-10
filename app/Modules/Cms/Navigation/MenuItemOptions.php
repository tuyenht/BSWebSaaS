<?php

declare(strict_types=1);

namespace App\Modules\Cms\Navigation;

use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
use App\Modules\Theme\Chrome\StorefrontIcons;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * A menu item's display options, kept in menu_items.meta because the website only draws them and
 * nothing filters on them. Three shapes of the same thing:
 *  - the screen's (draft) shape, see normalize(): {hidden, new_tab, icon, description{locale},
 *    cta, mega{type, columns}, badge{kind, until}, device};
 *  - the stored keys: target ('_blank'), hidden, icon, description{locale}, cta,
 *    mega{type, columns}, badge, badge_until (UTC ISO), device;
 *  - what the website draws for one language at one moment (forRender()).
 * Every key is checked strictly; a key the screen sends that is not listed here is dropped, and
 * stored keys this class does not own are kept as they are.
 */
final class MenuItemOptions
{
    /**
     * A hard ceiling on a description, per language. The screen only suggests 90 characters for the
     * line under an item and 200 for the lead of a large panel (the theme cuts the rest visually);
     * this cap stops a pasted page of text from being carried in every page's menu.
     */
    public const int DESCRIPTION_MAX = 500;

    /** Badges the theme has words for in every language (storefront.menu_badge_*). */
    public const array BADGES = ['new', 'hot', 'sale', 'soon'];

    public const array DEVICES = ['all', 'desktop', 'phone'];

    /** Columns of a large panel built from the item's children. The block-built panel waits for the block library. */
    public const array MEGA_COLUMNS = [2, 3, 4];

    /** The stored keys this class owns. */
    private const array OWNED = ['target', 'hidden', 'icon', 'description', 'cta', 'mega', 'badge', 'badge_until', 'device'];

    /**
     * The stored meta after applying the screen's options to $stored. Mega is kept only for a
     * top-level item of a menu that may open one ($megaAllowed); elsewhere it is dropped, since no
     * area could draw it.
     *
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $options
     * @param  list<string>  $locales  the languages the site writes in
     * @param  string  $zone  the site's time zone, for a badge that ends on a date
     * @return array<string, mixed>
     *
     * @throws MenuChangeRejectedException option_invalid / locale_invalid
     */
    public static function normalize(array $stored, array $options, array $locales, bool $topLevel, bool $megaAllowed, string $zone): array
    {
        $meta = array_diff_key($stored, array_flip(self::OWNED));

        if (self::flag($options, 'new_tab')) {
            $meta['target'] = '_blank';
        }
        if (self::flag($options, 'hidden')) {
            $meta['hidden'] = true;
        }
        if (self::flag($options, 'cta')) {
            $meta['cta'] = true;
        }

        $icon = $options['icon'] ?? null;
        if ($icon !== null && $icon !== '') {
            if (! is_string($icon) || ! StorefrontIcons::has($icon)) {
                throw new MenuChangeRejectedException('option_invalid');
            }
            $meta['icon'] = $icon;
        }

        $descriptions = self::descriptions($options['description'] ?? null, $locales);
        if ($descriptions !== []) {
            $meta['description'] = $descriptions;
        }

        $mega = $options['mega'] ?? null;
        if ($mega !== null) {
            if (! is_array($mega) || ($mega['type'] ?? 'columns') !== 'columns' || ! in_array($mega['columns'] ?? null, self::MEGA_COLUMNS, true)) {
                throw new MenuChangeRejectedException('option_invalid');
            }
            if ($topLevel && $megaAllowed) {
                $meta['mega'] = ['type' => 'columns', 'columns' => $mega['columns']];
            }
        }

        $badge = $options['badge'] ?? null;
        if ($badge !== null) {
            if (! is_array($badge) || ! in_array($badge['kind'] ?? null, self::BADGES, true)) {
                throw new MenuChangeRejectedException('option_invalid');
            }
            $meta['badge'] = $badge['kind'];
            $until = self::until($badge['until'] ?? null, $zone);
            if ($until !== null) {
                $meta['badge_until'] = $until;
            }
        }

        $device = $options['device'] ?? 'all';
        if (! is_string($device) || ! in_array($device, self::DEVICES, true)) {
            throw new MenuChangeRejectedException('option_invalid');
        }
        if ($device !== 'all') {
            $meta['device'] = $device;
        }

        return $meta;
    }

    /**
     * Mega dropped from an item that is no longer at the top level (or whose menu can no longer
     * open one); the rest untouched.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function placed(array $stored, bool $topLevel, bool $megaAllowed): array
    {
        if (isset($stored['mega']) && ! ($topLevel && $megaAllowed)) {
            unset($stored['mega']);
        }

        return $stored;
    }

    /**
     * The screen's shape of the stored meta (what normalize() takes back).
     *
     * @param  array<string, mixed>  $meta
     * @return array{hidden: bool, new_tab: bool, icon: string|null, description: array<string, string>, cta: bool, mega: array{type: string, columns: int}|null, badge: array{kind: string, until: string|null}|null, device: string}
     */
    public static function forScreen(array $meta): array
    {
        $mega = is_array($meta['mega'] ?? null) && in_array($meta['mega']['columns'] ?? null, self::MEGA_COLUMNS, true)
            ? ['type' => 'columns', 'columns' => (int) $meta['mega']['columns']]
            : null;
        $badge = is_string($meta['badge'] ?? null) && in_array($meta['badge'], self::BADGES, true)
            ? ['kind' => $meta['badge'], 'until' => is_string($meta['badge_until'] ?? null) ? $meta['badge_until'] : null]
            : null;
        $device = is_string($meta['device'] ?? null) && in_array($meta['device'], self::DEVICES, true) ? $meta['device'] : 'all';

        return [
            'hidden' => ($meta['hidden'] ?? false) === true,
            'new_tab' => ($meta['target'] ?? null) === '_blank',
            'icon' => is_string($meta['icon'] ?? null) && StorefrontIcons::has($meta['icon']) ? $meta['icon'] : null,
            'description' => self::storedDescriptions($meta),
            'cta' => ($meta['cta'] ?? false) === true,
            'mega' => $mega,
            'badge' => $badge,
            'device' => $device,
        ];
    }

    /**
     * What the website draws for an item in $locale at $now: a badge past its end date is gone, a
     * description in another language is not shown.
     *
     * @param  array<string, mixed>  $meta
     * @return array{icon: string|null, description: string|null, cta: bool, mega: int|null, badge: string|null, device: string}
     */
    public static function forRender(array $meta, string $locale, DateTimeInterface $now): array
    {
        $screen = self::forScreen($meta);
        $badge = $screen['badge'];
        if ($badge !== null && $badge['until'] !== null) {
            try {
                $expired = CarbonImmutable::parse($badge['until'])->lessThan($now);
            } catch (Throwable) {
                $expired = true;
            }
            if ($expired) {
                $badge = null;
            }
        }

        return [
            'icon' => $screen['icon'],
            'description' => $screen['description'][$locale] ?? null,
            'cta' => $screen['cta'],
            'mega' => $screen['mega']['columns'] ?? null,
            'badge' => $badge['kind'] ?? null,
            'device' => $screen['device'],
        ];
    }

    /** @param array<string, mixed> $options */
    private static function flag(array $options, string $key): bool
    {
        $value = $options[$key] ?? false;
        if (! is_bool($value)) {
            throw new MenuChangeRejectedException('option_invalid');
        }

        return $value;
    }

    /**
     * Plain text per language: tags removed, runs of white space made one space, at most
     * DESCRIPTION_MAX letters (counted as letters, not bytes). An empty one is dropped.
     *
     * @param  list<string>  $locales
     * @return array<string, string>
     */
    private static function descriptions(mixed $input, array $locales): array
    {
        if ($input === null) {
            return [];
        }
        if (! is_array($input)) {
            throw new MenuChangeRejectedException('option_invalid');
        }

        $clean = [];
        foreach ($input as $locale => $text) {
            if (! is_string($locale) || ! in_array($locale, $locales, true)) {
                throw new MenuChangeRejectedException('locale_invalid');
            }
            if (! is_string($text) || mb_check_encoding($text, 'UTF-8') === false) {
                throw new MenuChangeRejectedException('option_invalid');
            }
            $text = self::flat($text);
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) > self::DESCRIPTION_MAX) {
                throw new MenuChangeRejectedException('option_invalid');
            }
            $clean[$locale] = $text;
        }

        return $clean;
    }

    /**
     * A source's own text (a page's excerpt, a category's description, a product's summary) as an
     * item description could hold it: plain text, at most DESCRIPTION_MAX letters; null when empty.
     * The screen offers it as "Lấy từ mô tả trang".
     */
    public static function suggestedDescription(?string $text): ?string
    {
        if ($text === null || mb_check_encoding($text, 'UTF-8') === false) {
            return null;
        }
        $text = mb_substr(self::flat($text), 0, self::DESCRIPTION_MAX);
        $text = (string) preg_replace('/ +$/u', '', $text);

        return $text === '' ? null : $text;
    }

    /** Tags removed, entities read, runs of white space and control characters made one space, ends trimmed. */
    private static function flat(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[\p{Cc}\p{Z}\s]+/u', ' ', $text);

        return (string) preg_replace('/^ +| +$/u', '', $text);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, string>
     */
    private static function storedDescriptions(array $meta): array
    {
        $out = [];
        foreach (is_array($meta['description'] ?? null) ? $meta['description'] : [] as $locale => $text) {
            if (is_string($locale) && is_string($text) && $text !== '') {
                $out[$locale] = $text;
            }
        }

        return $out;
    }

    /**
     * When a badge stops showing, as UTC ISO: a date ('2026-12-31') is the last day it shows, so it
     * ends at the end of that day in the site's time zone; a full time with its offset is taken as it is.
     */
    private static function until(mixed $value, string $zone): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new MenuChangeRejectedException('option_invalid');
        }

        try {
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1) {
                $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, $zone);
                // 2026-02-31 would roll over into March: only a real date is taken.
                $at = $day instanceof CarbonImmutable && $day->format('Y-m-d') === $value ? $day->endOfDay() : null;
            } else {
                $at = preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})\z/', $value) === 1 ? CarbonImmutable::parse($value) : null;
            }
        } catch (Throwable) {
            $at = null;
        }
        if (! $at instanceof CarbonImmutable) {
            throw new MenuChangeRejectedException('option_invalid');
        }

        return $at->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
