<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Errors\AdminErrorPages;
use App\Modules\Admin\Support\AccessRequests;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Nhờ cấp quyền" on the no-permission page (a plain form, so it works without script): the member
 * names the screen they were refused (its route), the server finds the permission again and asks
 * once (AccessRequests). The browser goes back to that screen, whose page now reads "Đã gửi yêu
 * cầu" (or why no more requests go out today).
 */
final class AccessRequestController
{
    public function store(Request $request, AccessRequests $requests): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $request->attributes->get('admin.member') === true, 403);

        $input = Cast::arr($request->validate([
            'screen' => ['required', 'string', 'max:100'],
            'back' => ['nullable', 'string', 'max:2000'],
        ]));
        $screen = Cast::string($input['screen'] ?? '');
        $permission = $requests->askable($user, $screen);
        abort_if($permission === null || $requests->recipients($user) === [], 422);

        $requests->ask($user, $screen, $permission, AdminErrorPages::screenTitleOf($screen) ?? __('errors.this_item'));

        $back = AdminErrorPages::safeReturnPath($request, Cast::string($input['back'] ?? ''));

        return redirect()->to($back ?? AdminErrorPages::home(), 303);
    }
}
