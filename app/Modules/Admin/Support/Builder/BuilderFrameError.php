<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Builder;

use App\Modules\Shared\Support\BuilderPreview;
use App\Modules\Storefront\Rendering\EntryPreviewPage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page the builder's preview frame gets when its preview is refused: the frame's parent cannot
 * read the status or headers of a form it sent into a frame, so every refusal of the preview route
 * (BuilderPreviewController for 404 / 413 / 422, AdminErrorPages for the 419 and 429 the middleware
 * raise) answers with the same small page carrying `<meta name="bs-pb-error">` and the preview's own
 * headers and CSP. The nonce the request sent is printed only when well formed.
 */
final class BuilderFrameError
{
    /** The refusals the frame's page exists for. */
    public const array CODES = [404, 413, 419, 422, 429];

    /** The longest wait a 429 names (seconds): the limiter's window. */
    private const int RETRY_MAX = 300;

    public static function response(int $code, Request $request, ?int $retry = null): Response
    {
        $nonce = BuilderPreview::nonceOf($request->input('nonce'));
        $retry = $code === 429 ? max(1, min(self::RETRY_MAX, $retry ?? 60)) : null;
        $headers = EntryPreviewPage::headers() + ($retry !== null ? ['Retry-After' => (string) $retry] : []);

        return response()->view('admin::errors.builder-frame', ['code' => $code, 'nonce' => $nonce, 'retry' => $retry], $code, $headers);
    }
}
