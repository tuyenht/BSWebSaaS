# ADR‑21 — CSP động CHO STOREFRONT (`spatie/laravel-csp`) + cookieless `page_views`

> ⚠️ **RECONCILED 2 vòng (design ↔ Claude Code council, 2026‑07‑11).** Sửa khung: **storefront‑only** (admin giữ omission có chủ đích) + phát hiện **xung khắc nonce×L0‑cache**. Ref §29 của ADR‑21 **ĐÚNG** (spec ở design.md 632–634 nằm trong §29 — verify exhaustive; điểm "V3" nghi §29 sai của bản audit trước đã BÁC).

- **Trạng thái:** **Đề xuất (Draft)** — LAND design.md dòng 21 + v2.14. **Chạm security (CSP) + thêm dependency** → COUNCIL. Không schema.
- **Kích hoạt:** khi Script‑Manager / tag 3rd‑party xuất hiện (near‑term seam).
- **Liên quan:** ADR‑04 · **§29 Bảo mật** (đặc tả CSP‑động+nonce+spatie, dòng 632–634) · §27 (`page_views` cookieless) · §25/ADR‑06 (L0 full‑page‑cache) · `storefront-instant-nav.md:65` (speculationrules‑L2) · ADR‑22 (nguồn cấu hình tag).

## Bối cảnh (grounded CODE `main@c040caa`)
- `AddSecurityHeaders`: CSP **chỉ storefront** (`storefrontCsp()` = **chuỗi tay tĩnh**: `default-src 'self'; img-src files.baoson.net data:; style-src 'unsafe-inline'; script-src 'self' challenges.cloudflare.com; frame-src google.com challenges.cloudflare.com; …`). **Admin+platform OMIT CSP** — docblock ghi **có chủ đích**: Livewire/Alpine cần `unsafe-eval`, CSP chặt sẽ vỡ admin UI, chấp nhận vì gated sau auth.
- `composer.json` require = **KHÔNG có `spatie/laravel-csp`** (chỉ `spatie/laravel-permission`). CSP hiện hoàn toàn hand‑rolled.
- §27 đã intent analytics cookieless (`page_views` ở §16 schema). §29 (632–634) đặc tả: *"Storefront: CSP sinh ĐỘNG per‑site = allowlist… + nonce; quản lý qua spatie/laravel-csp, policy per‑route/per‑site."*
- ⚠ `storefront-instant-nav.md:65‑67`: CSP static hiện **CHẶN** inline `<script type="speculationrules">`; L2 phải dùng **keyword `'inline-speculation-rules'`, KHÔNG nonce** — vì **nonce đổi mỗi response ⇒ xung khắc L0 full‑page‑cache** (trang cache dùng lại nonce cũ).

## Quyết định (khớp design.md §21 + reconcile)
1. **CSP động CHO STOREFRONT:** nâng `storefrontCsp()` (chuỗi tĩnh) → **policy per‑site + allowlist tag đã‑biết** (GTM/GA4/Pixel/Zalo/chat) qua `spatie/laravel-csp`. Cookieless `page_views` (§27) đo nội bộ; fan‑out 3rd‑party = ADR‑22.
2. **Admin+platform GIỮ CSP‑omission có chủ đích** (as‑built) — KHÔNG "vá". Lý do: nonce **không** loại được `unsafe-eval` (Livewire/Alpine) → admin‑CSP lợi ích cận biên, rủi ro vỡ UI. *Re‑visit nếu sau này ưu tiên admin‑CSP: Alpine có build `@alpinejs/csp` (cú pháp hạn chế, né eval) — cần verify tương thích Livewire 4.*
3. **⚠ nonce per‑request XUNG KHẮC L0 full‑page‑cache (§25/ADR‑06).** ⇒ **ưu tiên hash / keyword‑source** cho inline **đã‑biết** (vd `'inline-speculation-rules'` cho Speculation Rules; hash cho init snippet cố định); **nonce CHỈ dùng ở response KHÔNG‑cache**. Đây là ràng buộc thiết kế cứng — không mặc định nonce mọi nơi.
4. **Adopt `spatie/laravel-csp` = thêm dependency → COUNCIL riêng** (CLAUDE.md pin‑version/không thêm dep không bàn) + migrate CSP‑tay → policy.
5. **Roll‑out:** `Content-Security-Policy-Report-Only` + `report-uri` trước khi enforce; CSP/cookie phủ mọi alias (`config('tenancy.base_domains')`).
6. **GỘP đợt sửa `AddSecurityHeaders`:** khi chạm middleware, làm chung với **speculationrules‑L2** (`storefront-instant-nav.md:65`) — tránh rework 2 lần với giả định trái nhau.

## [COUNCIL] Thay đổi
- Thêm `spatie/laravel-csp` (dependency — COUNCIL). `AddSecurityHeaders::storefrontCsp()` tĩnh → policy động per‑site + Report‑Only; **admin nhánh giữ nguyên omission**.
- `page_views` cookieless collector (nhẹ, first‑party) — dùng bảng đã có (§16), không schema mới.
- Đồng bộ doc: NEW#2 annotate §29:633 (design‑intent "admin nonce" ↔ as‑built omission) để doc tự‑nhất‑quán.

## Hệ quả
- **+** Bật tag mà không phá CWV; storefront có allowlist/nonce đúng chỗ; không đụng admin (an toàn as‑built); cookieless bền ITP; điều phối 1 đợt với L2.
- **−** Thêm dependency (COUNCIL); CSP động phức tạp; phải phân biệt response cache‑được/không để chọn hash vs nonce; test kỹ Report‑Only trước enforce.

## Test‑first (Pest)
- `DynamicStorefrontCspTest` — storefront policy gộp đúng allowlist per‑site; admin+platform response **KHÔNG** có CSP header (giữ omission).
- `CspCacheSafetyTest` — response cache‑được KHÔNG mang nonce per‑request (dùng hash/keyword); chỉ response không‑cache mới nonce.
- `CspReportOnlyRolloutTest` — Report‑Only phát `report-uri`, không chặn khi roll‑out.
- `PageViewsCookielessTest` — đếm lượt xem không set cookie theo dõi; cách ly per site_id + RLS.

## Phụ thuộc
Cơ chế độc lập, hiện thực khi bật tracking. Danh mục origin/tag lấy từ ADR‑22 (`site_integrations`). Sửa middleware gộp với speculationrules‑L2.
