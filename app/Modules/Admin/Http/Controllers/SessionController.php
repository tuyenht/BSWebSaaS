<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Errors\AdminErrorPages;
use App\Modules\Admin\Theme\AdminBrandColor;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Http\Middleware\EnsurePlatformAccess;
use App\Modules\Rbac\Http\Middleware\EnsureTenantMember;
use App\Modules\Shared\Support\Cast;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * The admin session's own endpoints (design board 25):
 *  - ping: "Tiếp tục làm việc" in the session-ending dialog — any signed-in request keeps the
 *    session, so this one only answers "204, still yours".
 *  - expired: the "Phiên làm việc đã hết" page a Livewire request refused with 419 (or the dialog
 *    at 0:00) opens. It remembers the admin page the member was on, on this host only, so signing
 *    in again lands there.
 *  - unknown: an /admin address that is no screen goes back to the first page with a notice.
 */
final class SessionController
{
    public function ping(): Response
    {
        return response()->noContent();
    }

    public function expired(Request $request): BaseResponse
    {
        $back = AdminErrorPages::safeReturnPath($request, Cast::string($request->query('to')));
        if ($back !== null) {
            $request->session()->put('url.intended', $request->getSchemeAndHttpHost().$back);
        }

        return response()->view('admin::errors.page', [
            'page' => AdminErrorPages::expiredPage(),
            'chrome' => [],
            'brandTokens' => AdminBrandColor::cssVariables(config('admin.brand.color')),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * An address under a website's /admin that is no screen. A guest gets "not found" (a mistyped
     * invitation link must not turn into a sign-in); someone signed in must still pass the same
     * membership check as every admin screen (a non-member gets "no permission").
     */
    public function unknownOnSite(Request $request, EnsureTenantMember $member): BaseResponse
    {
        abort_unless($request->user() instanceof User, 404);

        return $member->handle($request, fn (Request $request): BaseResponse => $this->unknown($request));
    }

    /**
     * An address on the platform console that is no screen: the same split as on a website. A
     * guest gets "not found", a signed-in user must hold a platform role ("no permission" if not).
     */
    public function unknownOnPlatform(Request $request, EnsurePlatformAccess $platform): BaseResponse
    {
        abort_unless($request->user() instanceof User, 404);

        return $platform->handle($request, fn (Request $request): BaseResponse => $this->unknown($request));
    }

    /**
     * Back to the plane's first page with a notice, but only for an address the member opened
     * (a top-level navigation). A request the browser makes on its own (an icon, a manifest, a
     * DevTools probe, a fetch) gets "not found", so no notice turns up on the next page for nothing.
     */
    private function unknown(Request $request): BaseResponse
    {
        abort_unless(self::isNavigation($request), 404);

        return redirect()->to(AdminErrorPages::home())->with('admin.notice', __('errors.unknown_address'));
    }

    /** A page the member opened: a top-level browser navigation, or a `wire:navigate` click (Livewire fetches the page and shows it). */
    private static function isNavigation(Request $request): bool
    {
        if ($request->header('X-Livewire-Navigate') === '1') {
            return true;
        }

        return $request->header('Sec-Fetch-Mode') === 'navigate'
            && in_array($request->header('Sec-Fetch-Dest', 'document'), ['document'], true);
    }
}
