<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Settings;

use App\Modules\Admin\Livewire\FloatingContactSettings;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\SafeLink;
use App\Modules\Theme\Chrome\ContactIcons;
use App\Modules\Theme\Chrome\FloatingContact;

/**
 * The checks of the floating-contact setting (`storefront.floating_contact`) for a value written
 * from outside the admin form (the content-pack loader): the same lists, limits and per-type value
 * rules the form and the storefront use (FloatingContact::clean / color / offset, the theme,
 * position, icon and "Mua hàng" colour lists). A value the storefront would drop silently is an
 * error here: a channel that is on needs a value that passes its type's check, whatever the type
 * (a phone, a Zalo number or link, an email, a web link without javascript: or //host).
 */
final class FloatingContactInput
{
    /**
     * @param  array<array-key, mixed>  $value  the setting as it would be stored
     * @param  list<string>  $locales  the languages a label may be given in
     * @return list<string> plain-language problems; empty = the setting is valid
     */
    public static function errors(array $value, array $locales): array
    {
        $errors = [];
        foreach (array_keys($value) as $field) {
            if (! in_array($field, ['enabled', 'theme', 'position', 'offset_x', 'offset_y', 'layout', 'dial', 'bar_tone', 'track', 'buy', 'phone_bar', 'channels'], true)) {
                $errors[] = "'{$field}' is not a floating-contact field.";
            }
        }
        if (isset($value['enabled']) && ! is_bool($value['enabled'])) {
            $errors[] = "'enabled' must be true or false.";
        }
        if (isset($value['theme']) && ! in_array($value['theme'], FloatingContact::THEMES, true)) {
            $errors[] = "'theme' must be one of ".implode(', ', FloatingContact::THEMES).'.';
        }
        if (isset($value['position']) && ! in_array($value['position'], FloatingContact::POSITIONS, true)) {
            $errors[] = "'position' must be one of ".implode(', ', FloatingContact::POSITIONS).'.';
        }
        if (isset($value['layout']) && ! in_array($value['layout'], FloatingContact::LAYOUTS, true)) {
            $errors[] = "'layout' must be one of ".implode(', ', FloatingContact::LAYOUTS).'.';
        }
        if (isset($value['bar_tone']) && ! in_array($value['bar_tone'], FloatingContact::BAR_TONES, true)) {
            $errors[] = "'bar_tone' must be one of ".implode(', ', FloatingContact::BAR_TONES).'.';
        }
        foreach (['dial', 'track'] as $field) {
            if (isset($value[$field]) && ! is_bool($value[$field])) {
                $errors[] = "'{$field}' must be true or false.";
            }
        }
        foreach (['offset_x' => FloatingContact::MAX_OFFSET_X, 'offset_y' => FloatingContact::MAX_OFFSET_Y] as $field => $max) {
            if (isset($value[$field]) && FloatingContact::offset($value[$field], $max, -1) === -1) {
                $errors[] = "'{$field}' must be an even whole number from 0 to {$max}.";
            }
        }
        if (isset($value['buy'])) {
            $buy = $value['buy'];
            if (! is_array($buy) || ! is_bool($buy['enabled'] ?? false)) {
                $errors[] = "'buy' must be {\"enabled\": true|false, \"color\": …}.";
            } elseif (isset($buy['color']) && ! (in_array($buy['color'], FloatingContactSettings::BUY_SWATCHES, true) || FloatingContact::color($buy['color']) !== null)) {
                $errors[] = "'buy.color' must be 'brand' or a #rrggbb colour.";
            }
        }
        if (isset($value['phone_bar'])) {
            array_push($errors, ...self::phoneBarErrors($value['phone_bar'], $locales));
        }

        $channels = $value['channels'] ?? [];
        if (! is_array($channels) || ! array_is_list($channels) || count($channels) > FloatingContact::MAX_CHANNELS) {
            return [...$errors, "'channels' must be a list of at most ".FloatingContact::MAX_CHANNELS.' channels.'];
        }

        $seen = [];
        foreach ($channels as $index => $channel) {
            $at = 'channel '.($index + 1);
            $key = is_array($channel) ? ($channel['key'] ?? null) : null;
            $type = is_string($key) ? FloatingContact::typeOf($key) : null;
            if (! is_array($channel) || $type === null) {
                $errors[] = "{$at}: 'key' must be one of ".implode(', ', array_keys(FloatingContact::CHANNELS)).' or a custom key (c1, c2, …).';

                continue;
            }
            $at = "channel '{$key}'";
            if (isset($seen[$key])) {
                $errors[] = "{$at}: the channel is listed twice.";
            }
            $seen[$key] = true;
            array_push($errors, ...self::channel($at, $channel, $type, $locales));
        }

        return $errors;
    }

    /**
     * One channel row cast to the shape the form works with: texts trimmed, the colour lower-cased,
     * a label per language trimmed.
     *
     * @return array{key: string, on: bool, value: string, color: string, icon: string, name: string, tips: array<string, string>}
     */
    public static function castRow(mixed $row): array
    {
        $c = Cast::arr($row);
        $tips = [];
        foreach (Cast::arr($c['tips'] ?? null) as $lc => $t) {
            $tips[(string) $lc] = trim(Cast::string($t));
        }

        return [
            'key' => Cast::string($c['key'] ?? null),
            'on' => ($c['on'] ?? false) === true,
            'value' => trim(Cast::string($c['value'] ?? null)),
            'color' => strtolower(Cast::string($c['color'] ?? null)),
            'icon' => Cast::string($c['icon'] ?? null),
            'name' => trim(Cast::string($c['name'] ?? null)),
            'tips' => $tips,
        ];
    }

    /**
     * One channel in the stored shape: a colour only when chosen, an icon and name only on a
     * channel of the site's own, and the labels that are not empty.
     *
     * @param  array{key: string, on: bool, value: string, color: string, icon: string, name: string, tips: array<string, string>}  $c
     * @return array<string, mixed>
     */
    public static function storedRow(array $c): array
    {
        $row = ['key' => $c['key'], 'on' => $c['on'], 'value' => $c['value']];
        if ($c['color'] !== '') {
            $row['color'] = $c['color'];
        }
        if (FloatingContact::typeOf($c['key']) === 'custom') {
            $row['icon'] = $c['icon'];
            $row['name'] = $c['name'];
        }
        $row['tips'] = array_filter($c['tips'], fn (string $t): bool => $t !== '');

        return $row;
    }

    /**
     * The phone bar: {"enabled": bool, "cta": {"label": {locale: text}, "url": link}} — the cell is
     * optional, but a label needs a link that passes SafeLink and the other way round.
     *
     * @param  list<string>  $locales
     * @return list<string>
     */
    private static function phoneBarErrors(mixed $bar, array $locales): array
    {
        if (! is_array($bar) || ! is_bool($bar['enabled'] ?? null) || array_diff(array_keys($bar), ['enabled', 'cta']) !== []) {
            return ["'phone_bar' must be {\"enabled\": true|false, \"cta\": {\"label\": {…}, \"url\": …}}."];
        }
        if (! isset($bar['cta'])) {
            return [];
        }
        $cta = $bar['cta'];
        if (! is_array($cta) || array_diff(array_keys($cta), ['label', 'url']) !== [] || ! is_array($cta['label'] ?? null) || ! is_string($cta['url'] ?? null)) {
            return ["'phone_bar.cta' must be {\"label\": {\"vi\": …}, \"url\": …}."];
        }
        $errors = [];
        foreach ($cta['label'] as $locale => $text) {
            if (! in_array((string) $locale, $locales, true) || ! is_string($text) || trim($text) === '' || mb_strlen(trim($text)) > FloatingContact::MAX_CTA_LABEL) {
                $errors[] = "'phone_bar.cta.label.{$locale}' must be a text of at most ".FloatingContact::MAX_CTA_LABEL.' characters, in a language of the site.';
            }
        }
        if ($cta['label'] === []) {
            $errors[] = "'phone_bar.cta.label' needs a text.";
        }
        if (SafeLink::href(trim($cta['url'])) === null) {
            $errors[] = "'phone_bar.cta.url' must be a safe link (a path on the site or an http(s) link).";
        }

        return $errors;
    }

    /**
     * The phone bar as the form keeps it: the switch and, when it passes, the extra cell.
     *
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    public static function storedPhoneBar(mixed $raw, array $locales): array
    {
        $bar = Cast::arr($raw);
        $out = ['enabled' => ($bar['enabled'] ?? false) === true];
        $cta = Cast::arr($bar['cta'] ?? null);
        $labels = [];
        foreach (Cast::arr($cta['label'] ?? null) as $locale => $text) {
            if (is_string($text) && trim($text) !== '' && in_array((string) $locale, $locales, true)) {
                $labels[(string) $locale] = mb_substr(trim($text), 0, FloatingContact::MAX_CTA_LABEL);
            }
        }
        $url = Cast::string($cta['url'] ?? null);
        if ($labels !== [] && SafeLink::href(trim($url)) !== null) {
            $out['cta'] = ['label' => $labels, 'url' => trim($url)];
        }

        return $out;
    }

    /**
     * A valid value as the form stores it after a save with no change: the form's load (defaults for
     * what is missing, the built-in channels added switched off, labels of a language the site does
     * not list dropped) followed by its save (trimmed, colours lower-cased, empty labels dropped).
     * The loader writes this, so an admin save with no change reads back the same.
     *
     * @param  array<array-key, mixed>  $raw  a value that passed errors()
     * @param  list<string>  $locales  the site's languages
     * @return array<string, mixed>
     */
    public static function stored(array $raw, array $locales): array
    {
        $buy = Cast::arr($raw['buy'] ?? null);
        $buyColor = Cast::string($buy['color'] ?? null);
        $rows = [];
        $seen = [];
        foreach (array_slice(array_values(Cast::arr($raw['channels'] ?? null)), 0, FloatingContact::MAX_CHANNELS) as $c) {
            $c = Cast::arr($c);
            $key = Cast::string($c['key'] ?? null);
            if (FloatingContact::typeOf($key) === null || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $tips = [];
            foreach (Cast::arr($c['tips'] ?? null) as $lc => $tip) {
                if (is_string($tip) && in_array((string) $lc, $locales, true)) {
                    $tips[(string) $lc] = $tip;
                }
            }
            $rows[] = self::castRow([
                'key' => $key,
                'on' => ($c['on'] ?? false) === true,
                'value' => Cast::string($c['value'] ?? null),
                'color' => FloatingContact::color($c['color'] ?? null) ?? '',
                'icon' => in_array($c['icon'] ?? null, ContactIcons::CUSTOM, true) ? Cast::string($c['icon']) : 'ri-global-line',
                'name' => Cast::string($c['name'] ?? null),
                'tips' => $tips,
            ]);
        }
        foreach (FloatingContact::CORE as $key) {
            if (! isset($seen[$key])) {
                $rows[] = self::castRow(['key' => $key]);
            }
        }

        // The phone bar is kept only where a site set it, so a site that never did stores what it always did.
        $phoneBar = isset($raw['phone_bar']) ? ['phone_bar' => self::storedPhoneBar($raw['phone_bar'], $locales)] : [];

        $layout = FloatingContact::layout($raw);
        $givenLayout = in_array($raw['layout'] ?? null, FloatingContact::LAYOUTS, true);

        return [
            'enabled' => ($raw['enabled'] ?? false) === true,
            'theme' => $givenLayout ? FloatingContact::legacyTheme($layout) : (in_array($raw['theme'] ?? null, FloatingContact::THEMES, true) ? Cast::string($raw['theme']) : 'vertical'),
            'position' => in_array($raw['position'] ?? null, FloatingContact::POSITIONS, true) ? Cast::string($raw['position']) : 'right',
            'offset_x' => FloatingContact::offset($raw['offset_x'] ?? null, FloatingContact::MAX_OFFSET_X, FloatingContact::DEFAULT_OFFSET_X),
            'offset_y' => FloatingContact::offset($raw['offset_y'] ?? null, FloatingContact::MAX_OFFSET_Y, FloatingContact::DEFAULT_OFFSET_Y),
            'layout' => $layout,
            'dial' => FloatingContact::dial($raw),
            'bar_tone' => FloatingContact::barTone($raw),
            'track' => FloatingContact::track($raw),
            'buy' => [
                'enabled' => ($buy['enabled'] ?? false) === true,
                'color' => in_array($buyColor, FloatingContactSettings::BUY_SWATCHES, true) || FloatingContact::color($buyColor) !== null ? $buyColor : FloatingContact::BUY_COLOR,
            ],
            ...$phoneBar,
            'channels' => array_map(self::storedRow(...), $rows),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $channel
     * @param  list<string>  $locales
     * @return list<string>
     */
    private static function channel(string $at, array $channel, string $type, array $locales): array
    {
        $errors = [];
        foreach (array_keys($channel) as $field) {
            if (! in_array($field, ['key', 'on', 'value', 'color', 'icon', 'name', 'tips'], true)) {
                $errors[] = "{$at}: '{$field}' is not a channel field.";
            }
        }
        if (! is_bool($channel['on'] ?? null)) {
            $errors[] = "{$at}: 'on' must be true or false.";
        }

        $value = $channel['value'] ?? '';
        if (! is_string($value)) {
            $errors[] = "{$at}: 'value' must be text.";
        } elseif (mb_strlen(trim($value)) > FloatingContact::MAX_VALUE) {
            $errors[] = "{$at}: 'value' is longer than ".FloatingContact::MAX_VALUE.' characters.';
        } elseif (($channel['on'] ?? false) === true && FloatingContact::clean($type, $value) === null) {
            $errors[] = "{$at}: 'value' is not a valid {$type} (a phone number, a zalo.me link or number, an email, or a safe http(s) link — never javascript: or //host); a channel that is on needs one.";
        }

        if (isset($channel['color']) && FloatingContact::color($channel['color']) === null) {
            $errors[] = "{$at}: 'color' must be a #rrggbb colour.";
        }
        if ($type === 'custom') {
            if (! in_array($channel['icon'] ?? null, ContactIcons::CUSTOM, true)) {
                $errors[] = "{$at}: 'icon' must be one of the icons offered for a custom channel.";
            }
            if (! is_string($channel['name'] ?? null) || mb_strlen(trim($channel['name'])) > FloatingContact::MAX_LABEL) {
                $errors[] = "{$at}: 'name' must be text of at most ".FloatingContact::MAX_LABEL.' characters.';
            }
        }

        $tips = $channel['tips'] ?? [];
        if (! is_array($tips)) {
            $errors[] = "{$at}: 'tips' must be {language: label}.";
        } else {
            foreach ($tips as $locale => $tip) {
                if (! in_array((string) $locale, $locales, true) || ! is_string($tip)) {
                    $errors[] = "{$at}: tips.{$locale} must be a label (text) in a language the pack lists.";
                } elseif (mb_strlen(trim($tip)) > FloatingContact::MAX_LABEL) {
                    $errors[] = "{$at}: tips.{$locale} is longer than ".FloatingContact::MAX_LABEL.' characters.';
                }
            }
        }

        return $errors;
    }
}
