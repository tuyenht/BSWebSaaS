<?php

namespace Tests\Fixtures;

use App\Modules\Theme\Rendering\BlockStyles;

/**
 * The whole storefront stylesheet as tests read it: storefront.css followed by every block stylesheet in
 * link order (the text the single file held before the split). BlockStylesTest checks which file a rule
 * sits in; the other tests check the rule itself.
 */
final class StorefrontCss
{
    public static function all(): string
    {
        $css = (string) file_get_contents(resource_path('css/storefront.css'));
        foreach (array_keys(BlockStyles::FILES) as $file) {
            $css .= "\n".file_get_contents(base_path($file));
        }

        return $css;
    }
}
