<?php

namespace App\Modules\Admin\Support;

/**
 * Discovers the locales available to the admin UI from the translation files on disk
 * (lang/{code}.json). Dropping a new {code}.json file makes that language appear in the
 * switcher automatically — no code change required.
 */
final class Locales
{
    /**
     * @return list<array{code: string, label: string, active: bool}>
     */
    public static function available(): array
    {
        $files = glob(lang_path('*.json')) ?: [];
        $codes = array_values(array_map(
            static fn (string $path): string => basename($path, '.json'),
            $files,
        ));

        $order = (array) config('locales.order', []);
        usort($codes, static function (string $a, string $b) use ($order): int {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);
            $ia = $ia === false ? PHP_INT_MAX : $ia;
            $ib = $ib === false ? PHP_INT_MAX : $ib;

            return $ia === $ib ? strcmp($a, $b) : $ia <=> $ib;
        });

        $current = app()->getLocale();

        return array_map(static fn (string $code): array => [
            'code' => $code,
            'label' => self::label($code),
            'active' => $code === $current,
        ], $codes);
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_column(self::available(), 'code');
    }

    public static function label(string $code): string
    {
        $configured = config("locales.labels.$code");
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (class_exists(\Locale::class)) {
            $native = \Locale::getDisplayLanguage($code, $code);
            if (is_string($native) && $native !== '' && $native !== $code) {
                return ucfirst($native);
            }
        }

        return strtoupper($code);
    }
}
