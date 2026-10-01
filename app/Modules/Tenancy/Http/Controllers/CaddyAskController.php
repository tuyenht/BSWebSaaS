<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Context\SiteResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caddy on_demand_tls "ask" gate. Caddy gọi GET /caddy/ask?domain=<sni> TRƯỚC khi
 * xin cert cho một host. Fail-closed: chỉ trả 200 khi host là tên miền phụ của nền tảng, hoặc
 * tên miền riêng ĐÃ xác minh và đã bật HTTPS (design.md §28) → Caddy mới chạm Let's Encrypt. Một
 * tên miền chỉ mới được gắn, chưa trỏ về hệ thống, không xin được chứng chỉ. Câu trả lời đi qua
 * SiteResolver nên host lạ được nhớ 60 giây: dội SNI lạ không thành dội truy vấn.
 *
 * KHÔNG qua SetCurrentSite: Caddy gọi trước khi có cert và host có thể chưa biết.
 * platform_host (website.baoson.net) được phục vụ bằng block Caddy managed riêng
 * (Phương án A) nên KHÔNG đi qua đây — không cần whitelist ở controller.
 */
final readonly class CaddyAskController
{
    public function __construct(private SiteResolver $sites) {}

    public function __invoke(Request $request): Response
    {
        $domain = $request->query('domain');
        $host = SiteResolver::normalize(is_string($domain) ? $domain : '');

        // Fail-closed: host rỗng / không phải FQDN hợp lệ → từ chối (đừng đụng ACME).
        if ($host === null) {
            return response('', 400);
        }

        $allowed = $this->sites->resolve($host)?->mayHaveCertificate() === true;

        return response('', $allowed ? 200 : 404);
    }
}
