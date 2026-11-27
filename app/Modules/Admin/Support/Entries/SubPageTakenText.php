<?php

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Admin\Support\LangBar;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Shared\Support\Cast;

/**
 * The sentence for a sub-page whose new address is taken, as the editor's «Chưa làm được» dialog and the
 * review inbox's card both say it: the address as visitors see it (PathBuilder: the default language
 * unprefixed, any other `/{locale}`); when it is in another language than the one the person is working in,
 * that language is named as the language bar names it, since the page's own link in this language is free.
 */
final class SubPageTakenText
{
    /**
     * @param  string  $path  the taken address, unprefixed as a route stores it
     * @param  string  $clashLocale  the language it is in ('' when unknown: the working language)
     * @param  string  $workingLocale  the language the person has open
     */
    public static function say(string $path, string $clashLocale, string $workingLocale): string
    {
        $locale = $clashLocale !== '' ? $clashLocale : $workingLocale;
        $url = app(PathBuilder::class)->publicUrl($path, $locale);

        return Cast::string($locale === $workingLocale
            ? __('entries.change_err_subpage_path_taken', ['path' => $url])
            : __('entries.change_err_subpage_path_taken_lang', ['path' => $url, 'lang' => LangBar::name($locale)]));
    }
}
