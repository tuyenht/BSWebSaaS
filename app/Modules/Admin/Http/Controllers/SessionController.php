<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Errors\AdminErrorPages;
use App\Modules\Admin\Theme\AdminBrandColor;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Http\Middleware\EnsureTenantMember;
use App\Modules\Shared\Support\Cast;
use Illuminate\Http\RedirectResponse;
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

        return $member->handle($request, fn (): RedirectResponse => $this->unknown());
    }

    public function unknown(): RedirectResponse
    {
        return redirect()->to(AdminErrorPages::home())->with('admin.notice', __('errors.unknown_address'));
    }
}
