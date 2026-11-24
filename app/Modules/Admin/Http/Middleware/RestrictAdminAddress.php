<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Errors\AdminAddressNotAllowed;
use App\Modules\Admin\Support\AdminAddressLimit;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Tenancy\Context\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Giới hạn nơi được vào trang quản trị»: while a website limits its admin to a list of network
 * addresses, a request to that admin (its pages, its sign-in pages, its Livewire updates) from any
 * other address is refused with the plain «Không vào được trang quản trị từ nơi này» page. It holds
 * for every member, the owner included; the storefront and the platform console never pass here.
 *
 * The address is $request->ip() and nothing else: the app trusts only the private proxy ranges
 * (bootstrap/app.php), so a visitor's own X-Forwarded-For or CF-Connecting-IP header never chooses
 * it.
 *
 * The one way past is a live «Đăng nhập hỗ trợ» session for this very website (support is how a
 * locked-out owner gets back in): never "any platform account". On the admin pages and Livewire
 * updates EnforceSupportSession runs first and ends a session that is out of time, for another
 * website, of staff who lost the right or switched off by the owner, so only a live one reaches
 * here; the sign-in pages check its time and website here.
 */
final class RestrictAdminAddress
{
    public function __construct(
        private readonly AdminAddressLimit $limit,
        private readonly SupportSession $support,
        private readonly CurrentSite $current,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->current->hasId() || $this->limit->allows($request->ip()) || $this->inSupport()) {
            return $next($request);
        }

        throw new AdminAddressNotAllowed;
    }

    private function inSupport(): bool
    {
        $session = $this->support->current();

        return $session !== null
            && $session['expires_at'] > now()->getTimestamp()
            && $session['site_id'] === $this->current->id();
    }
}
