<?php

declare(strict_types=1);

namespace App\Modules\Cms\Navigation;

use App\Modules\Theme\Chrome\StorefrontIcons;
use Normalizer;

/**
 * The icon the phone menu draws by itself for a top-level row whose editor chose none: first by the
 * kind of link (home, search, cart…), then by the words of the row's label in any language the site
 * ships, else none. The keyword rules are the same as the admin screen's "Gợi ý" button
 * (resources/js/admin-menus.js, suggestIconOf), so what the screen previews is what the phone shows.
 *
 * Latin words: accents and case ignored (đ is d), the label split into words, and the icon whose keyword
 * list holds the longest run of the label's whole words in a row wins; a lone word counts only from three
 * letters ("Đối tác" is "đối tác", never "đổi trả"); on a tie the run with more letters wins, then the
 * first icon in StorefrontIcons::MENU_GROUPS order.
 *
 * Scripts written without spaces (Japanese, Chinese): a keyword of two or more such characters counts when
 * the label contains it anywhere. It scores as a run of one word with as many letters as it has
 * characters, so it never beats a Latin run of two or more words, and ties with a Latin word of the same
 * length go to the first icon in picker order.
 */
final class MenuIconGuess
{
    /** The special link kinds that have an icon of their own, by the key of MenuRules::DYNAMIC. */
    private const array KIND = [
        'home' => 'home',
        'search' => 'search',
        'cart' => 'cart',
        'account' => 'user',
        'login' => 'user',
        'call' => 'phone',
        'zalo' => 'zalo',
        'mail' => 'email',
        'map' => 'map',
        'sitemap' => 'sitemap',
    ];

    /**
     * Constant data from the language files, built once per process: per icon (in picker order), one
     * keyword list per language, each as Latin words and as unspaced-script keywords.
     *
     * @var list<array{icon: string, latin: list<list<string>>, unspaced: list<string>}>|null
     */
    private static ?array $index = null;

    /** @return string|null a name of StorefrontIcons */
    public static function for(string $linkType, ?string $dynamicKey, string $label): ?string
    {
        if ($linkType === 'dynamic' && $dynamicKey !== null && isset(self::KIND[$dynamicKey])) {
            return self::KIND[$dynamicKey];
        }

        return self::byWords($label);
    }

    private static function byWords(string $label): ?string
    {
        $name = self::words($label);
        $composed = self::lower($label);
        $best = null;
        $top = [0, 0];

        foreach (self::index() as $entry) {
            foreach ($entry['latin'] as $kw) {
                for ($i = 0; $i < count($name); $i++) {
                    for ($j = 0; $j < count($kw); $j++) {
                        $n = 0;
                        $chars = 0;
                        while (isset($name[$i + $n], $kw[$j + $n]) && $name[$i + $n] === $kw[$j + $n]) {
                            $chars += strlen($name[$i + $n]);
                            $n++;
                        }
                        if (($n > 1 || $chars > 2) && self::beats([$n, $chars], $top)) {
                            $top = [$n, $chars];
                            $best = $entry['icon'];
                        }
                    }
                }
            }
            foreach ($entry['unspaced'] as $word) {
                $chars = mb_strlen($word);
                if (str_contains($composed, $word) && self::beats([1, $chars], $top)) {
                    $top = [1, $chars];
                    $best = $entry['icon'];
                }
            }
        }

        return $best;
    }

    /**
     * @param  array{0: int, 1: int}  $score
     * @param  array{0: int, 1: int}  $top
     */
    private static function beats(array $score, array $top): bool
    {
        return $score[0] > $top[0] || ($score[0] === $top[0] && $score[1] > $top[1]);
    }

    /** @return list<array{icon: string, latin: list<list<string>>, unspaced: list<string>}> */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $locales = [];
        foreach (glob(lang_path('*.json')) ?: [] as $file) {
            $locales[] = basename($file, '.json');
        }
        sort($locales);

        $seen = [];
        $index = [];
        foreach (StorefrontIcons::MENU_GROUPS as $names) {
            foreach ($names as $icon) {
                if (isset($seen[$icon])) {
                    continue;   // a name in two groups keeps its first place
                }
                $seen[$icon] = true;
                $latin = [];
                $unspaced = [];
                foreach ($locales as $locale) {
                    $key = 'menus.icon_kw_'.$icon;
                    $text = __($key, [], $locale);
                    if (! is_string($text) || $text === $key) {
                        continue;
                    }
                    $words = self::words($text);
                    if ($words !== []) {
                        $latin[] = $words;
                    }
                    foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                        if (mb_strlen($token) >= 2 && preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $token) === 1) {
                            $unspaced[] = self::lower($token);
                        }
                    }
                }
                $index[] = ['icon' => $icon, 'latin' => $latin, 'unspaced' => array_values(array_unique($unspaced))];
            }
        }

        return self::$index = $index;
    }

    /** @return list<string> the Latin words of $text: lowercase, accents gone, split on anything but a-z and 0-9 */
    private static function words(string $text): array
    {
        $decomposed = class_exists(Normalizer::class) ? Normalizer::normalize($text, Normalizer::FORM_D) : false;
        $plain = preg_replace('/\p{Mn}/u', '', is_string($decomposed) ? $decomposed : $text) ?? $text;
        $plain = strtr(mb_strtolower($plain), ['đ' => 'd']);

        return array_values(array_filter(preg_split('/[^a-z0-9]+/', $plain) ?: [], static fn (string $w): bool => $w !== ''));
    }

    /** Lowercase, composed (a combining mark in Japanese kana must stay with its letter). */
    private static function lower(string $text): string
    {
        $composed = class_exists(Normalizer::class) ? Normalizer::normalize($text, Normalizer::FORM_C) : false;

        return mb_strtolower(is_string($composed) ? $composed : $text);
    }
}
