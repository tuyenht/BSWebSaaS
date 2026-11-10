<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Settings;

use App\Modules\Admin\Livewire\FloatingContactSettings;
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
            if (! in_array($field, ['enabled', 'theme', 'position', 'offset_x', 'offset_y', 'buy', 'channels'], true)) {
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
