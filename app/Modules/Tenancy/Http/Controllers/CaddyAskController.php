<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\Domain;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caddy on_demand_tls "ask" gate. Caddy gọi GET /caddy/ask?domain=<sni> TRƯỚC khi
 * xin cert cho một host. Fail-closed: chỉ trả 200 khi host chắc chắn là tenant
 * domain đã đăng ký trong bảng domains → Caddy mới chạm Let's Encrypt.
 *
 * KHÔNG qua SetCurrentSite: Caddy gọi trước khi có cert và host có thể chưa biết.
 * platform_host (website.baoson.net) được phục vụ bằng block Caddy managed riêng
 * (Phương án A) nên KHÔNG đi qua đây — không cần whitelist ở controller.
 */
final class CaddyAskController
{
    public function __invoke(Request $request): Response
    {
        $host = strtolower(trim((string) $request->query('domain', '')));

        // Fail-closed: host rỗng / không phải FQDN hợp lệ → từ chối (đừng đụng ACME).
        if ($host === '' || preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', $host) !== 1) {
            return response('', 400);
        }

        // CHỈ cấp cert cho hostname có thật trong domains table (custom + subdomain).
        $allowed = Domain::query()->where('hostname', $host)->exists();

        return response('', $allowed ? 200 : 404);
    }
}
