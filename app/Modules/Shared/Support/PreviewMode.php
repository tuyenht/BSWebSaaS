<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Http\Request;

/**
 * Whether the page being drawn is the admin's preview of a storefront page. Set by the preview
 * controller on its own request and read by the parts of a page that would do something real for a
 * visitor (the lead form, which would record a contact): in a preview they are drawn, switched off.
 * Kept on the request itself, so it never outlives the request under Octane.
 */
final class PreviewMode
{
    private const string KEY = 'storefront.preview';

    public static function enable(Request $request): void
    {
        $request->attributes->set(self::KEY, true);
    }

    public static function on(): bool
    {
        return request()->attributes->getBoolean(self::KEY);
    }
}
