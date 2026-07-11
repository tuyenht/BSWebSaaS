# Tracking — Hiện thực trên Storefront + Server-side (yêu cầu cho Claude Code)

> Bổ sung cho `docs/tracking_integrations_spec.md`. Bám ADR‑04 (không code khách tuỳ ý), `AddSecurityHeaders`
> (CSP storefront chặt), Octane/FrankenPHP, Valkey/Horizon, RLS đa tenant, ngân sách JS ≤30KB / LCP ≤1.5s.
> Mục [COUNCIL] = chạm CSP/hạ tầng/schema → propose‑first.

## 1. "Thu thập phía máy chủ" — bật/tắt làm gì, máy chủ của AI
- **Máy chủ trung chuyển là CỦA CHÚNG TA** (hạ tầng Bảo Sơn: `sgtm.baoson.net` hoặc endpoint first‑party), **KHÔNG phải** máy Google/Facebook. Đích cuối vẫn là API của các nền tảng, nhưng thu thập + chuyển tiếp nằm ở máy mình.
- **TẮT (client‑side thuần):** trình duyệt khách tải SDK từng nền tảng (gtag.js, fbevents.js, ttq…) và gửi thẳng tới Google/FB/TikTok. → nặng, dễ bị adblock/ITP chặn, token/pixel lộ ở client.
- **BẬT (server‑side):**
  1. Client chỉ nạp **một mẩu nhỏ first‑party** (~6–8KB) để thu event + lấy click‑id (`fbclid/gclid/ttclid`) + cookie first‑party.
  2. Event gửi tới **collector first‑party** (same‑origin `/_e` hoặc `sgtm.baoson.net`) → không bị adblock, không vướng CSP.
  3. Máy chủ mình đẩy vào **queue (Valkey/Horizon)** rồi **fan‑out** sang Meta CAPI / GA4 MP / Google Enhanced Conversions / TikTok Events API bằng **token giữ kín server‑side**, kèm **hash PII (SHA‑256)** và **event_id** (dedup với pixel client nếu có).
  4. **Consent gating:** chỉ fan‑out nhóm marketing khi đã có đồng ý (theo chế độ Consent của site).

## 2. Gom 1 chỗ: MỘT Blade partial cho toàn bộ tracking
- Tất cả mã theo dõi đi qua **một** partial: `theme::partials.tracking` (không rải rác trong layout).
- Layout gọi partial ở **3 khe (slot)** đúng vị trí kỹ thuật từng công cụ yêu cầu:
  - `head` — trong `<head>`: thẻ meta‑verify (GSC/Bing/FB Domain), **Consent Mode default `denied`**, `preconnect`/`dns‑prefetch`, loader gtag/GTM (nếu client).
  - `body_start` — ngay sau `<body>`: GTM `<noscript>`, gốc DOM cho widget chat (nếu cần).
  - `body_end` — trước `</body>`: init pixel, **widget chat (lazy)**, script collector first‑party.
- Registry mở rộng mỗi provider thêm: **`position`** (`head|body_start|body_end`) + **`strategy`** (xem §3). Partial lặp qua provider **đang bật ∧ đã đăng ký ∧ hợp lệ ∧ đủ consent** → render đúng khe với nonce.

## 3. Chiến lược nạp — không giảm tốc độ
- **`inline-nonce`**: đoạn init ngắn do server sinh, ký `nonce` (không mở `unsafe-inline`). Dùng cho gtag/pixel init.
- **`async`**: loader ngoài không chặn parse (analytics).
- **`defer`**: chạy sau khi DOM sẵn sàng.
- **`idle`**: nạp khi trình duyệt rảnh (`requestIdleCallback`) — cho công cụ không cấp thiết.
- **`interaction-lazy`**: **chat (Zalo OA/Messenger/Tawk) chỉ tải khi khách bấm nút chat** hoặc cuộn/di chuột lần đầu → **không ảnh hưởng LCP**. Đây là mặc định cho mọi widget nặng.
- **Proxy qua tên miền trung gian** (`sgtm.baoson.net` / first‑party): loader & collector đi qua subdomain của hệ thống để (a) same‑origin → CSP gọn, (b) cache/nén ở edge, (c) né adblock. Cân nhắc **Partytown** (web worker) cho script bên thứ 3 còn sót.
- Luôn: `preconnect` tới origin đang bật; không script render‑blocking; tôn trọng ngân sách JS ≤30KB (bật server‑side để giữ ngưỡng).

## 4. `sgtm.baoson.net` — cần dựng gì [COUNCIL]
- **Server container dùng chung, đa tenant** (không dựng cho từng site): một dịch vụ chạy **server‑side GTM** (image chính chủ của Google, deploy trên Cloud Run/Docker tự host) **hoặc** một **forwarder tự viết** (route Laravel) nếu không muốn phụ thuộc GTM.
- Trỏ `sgtm.baoson.net` → dịch vụ này. Mỗi request mang **định danh site** (`?s=<site_id>` hoặc theo Host) → service tra **mapping per‑tenant** (GA4/Meta/TikTok IDs + token, đã mã hoá) → fan‑out.
- **Tenant dùng tên miền riêng:** hướng dẫn tạo `CNAME track.<domain> → sgtm.baoson.net`. Lợi ích: **cookie first‑party theo đúng tên miền khách** → matching tốt hơn, sống lâu hơn (né ITP). Có nút "Kiểm tra kết nối" → backend probe DNS/handshake trả trạng thái *Chưa trỏ / Đang chờ DNS / Đã hoạt động*.
- **Tên miền theo dõi:** tự gợi ý `track.<tên‑miền‑khách>` khi site đã có tên miền riêng; nếu chưa, để trống với placeholder `track.your-domain.com` và có thể dùng endpoint dùng chung của hệ thống.
- Chi phí: 1 hạ tầng dùng chung, tự scale theo request (Cloud Run) vs tự host — cân nhắc khi chốt.

## 5. Lưu trữ & bảo mật khi bật (per tenant, RLS)
- Bảng cấu hình tracking per site: `{provider, enabled, public_id, secret_enc, position, strategy, consent_category}` — secret **mã hoá at‑rest**, không trả về client.
- CSP sinh động: `AddSecurityHeaders` gộp `csp[]` của provider đang bật cho từng site (chỉ khi client‑side; server‑side ⇒ không thêm origin).
- Audit log khi bật/tắt & khi chọn Consent "Tắt hỏi".

## 5b. White-label — KHÔNG hardcode thương hiệu/tên miền nền tảng
- Tên thương hiệu nền tảng (prototype đang minh hoạ "Bảo Sơn") phải lấy từ **cấu hình hệ thống**: `config('platform.name')`
  (hoặc platform_settings) — dùng ở mọi câu chữ ("đội ngũ {platform}", "hạ tầng của {platform}"). Zero hardcode (multi-tenant, bán lại).
- Tên miền theo dõi nền tảng (prototype: `sgtm.baoson.net`) lấy từ `config('platform.track_domain')`; **tên miền gốc là tạm**
  và **đã có nơi lưu trong hệ thống** — phải suy ra từ **config base‑domain sẵn có** (`BASE_DOMAINS` / `PRIMARY_BASE` trong `.env`,
  `config('tenancy.platform_host')` / `config('tenancy.primary_base')`), KHÔNG hardcode `baoson.net`. Track domain = `sgtm.<PRIMARY_BASE>`
  suy ra động → khi đổi sang tên miền riêng về sau **không phải sửa lại code**. Claude Code: tra đúng key hiện có trong repo, đừng tạo hằng mới.
- Tên miền theo dõi của tenant tự suy ra `track.<site_domain>` khi site có tên miền riêng; nếu chưa, placeholder `track.your-domain.com`.

## 6. [COUNCIL] chốt trước khi code
Endpoint collector + queue fan‑out · lưu/mã hoá token · event_id dedup · hash PII · Consent Mode v2 store · hạ tầng sGTM (Cloud Run vs tự host) · CSP nonce động · Blade partial `theme::partials.tracking` + registry `position/strategy` · Partytown (nếu dùng) · probe DNS cho "Kiểm tra kết nối".

## 7. Gap bổ sung phát hiện khi audit (CHƯA có ở trên — Claude Code phải xử lý)

### 7.1 Theo chế độ website — `CatalogMode`: lead‑gen (brochure) ↔ commerce (bán online)
> Đây là thiết kế CHUNG cho SaaS đa tenant: mỗi site có thể là **brochure/lead‑gen** (An Vinh — không bán online)
> hoặc **commerce** (có giỏ hàng + đặt hàng). Tracking phải hỗ trợ CẢ HAI, chọn theo `CatalogMode` của site.

**Chung cho mọi site:**
- **Bắt & lưu click‑id** (`gclid/fbclid/ttclid`) + `utm_*` (first‑touch + last‑touch) vào cookie first‑party.
- **event_id** cho mọi sự kiện để dedup client↔server.

**Site lead‑gen (brochure):**
- Đính kèm click‑id + utm + event_id vào `form_submissions` (JSONB attribution).
- **Offline Conversion Upload:** chuyển đổi thật xảy ra offline (gọi/chốt deal) → đẩy ngược lên Meta CAPI offline /
  Google Offline Conversions / TikTok bằng click‑id đã lưu. Cấu hình **trạng thái lead nào = chuyển đổi** (vd "đã chốt").

**Site commerce (bán online) — GĐ nâng cấp về sau (P2), thiết kế dự phòng sẵn từ giờ:**
> GĐ1 chỉ brochure/lead‑gen (An Vinh). Thương mại điện tử là **giai đoạn nâng cấp sau** — registry & schema event
> chừa sẵn để bật khi tới P2, KHÔNG dựng ngay.
- **Sự kiện thương mại điện tử** item‑level: `view_item, add_to_cart, begin_checkout, add_payment_info, purchase, refund`
  kèm `items[]` (id/tên/giá/số lượng), **`value` + `currency` = VND (integer)**, `transaction_id`.
- **`purchase` lấy từ ĐƠN HÀNG ở server (authoritative)**, dedup theo `order_id` — không tin sự kiện client. COD/VietQR:
  cấu hình **trạng thái đơn nào tính là chuyển đổi** (đặt hàng vs đã xác nhận/đã thanh toán); `refund` khi huỷ/hoàn.
- **Dynamic remarketing / catalog feed:** `content_ids`/`item_id` phải **khớp ID sản phẩm của Catalog** (block `product_grid`);
  xuất **feed sản phẩm** cho Meta Catalog / Google Merchant Center để chạy quảng cáo động "sản phẩm đã xem".
- **Enhanced Ecommerce (GA4)** + giá trị giỏ hàng cho tối ưu đấu giá.

### 7.2 Consent — còn thiếu phần "thực thi"
- **Banner Consent là deliverable riêng** (chưa spec): nội dung + 3 nút (Đồng ý / Từ chối / Tuỳ chỉnh theo nhóm), **đa ngôn ngữ theo locale**,
  lưu lựa chọn (cookie + bản ghi), hết hạn re‑ask (vd 6–12 tháng), phát tín hiệu cho tag.
- **Consent Mode v2 = 4 tín hiệu** phải set: `ad_storage, analytics_storage, ad_user_data, ad_personalization`.
- **Tôn trọng GPC / Do‑Not‑Track** khi có.
- **Trang Chính sách quyền riêng tư / Cookie** là điều kiện pháp lý khi bật tag marketing → nên **bắt buộc/nhắc** tenant có trang này (liên kết ở banner).

### 7.3 Collector & fan‑out — độ bền + bảo mật (chưa ghi)
- **Hardening endpoint `/_e`** (công khai): rate‑limit theo IP/site, giới hạn kích thước payload, validate schema, chống bot/spam, CORS chặt.
- **Retry + Dead‑Letter** cho job fan‑out khi API nền tảng lỗi/timeout; **idempotency** theo `event_id`.
- **Re‑validate phía server** toàn bộ cấu hình tracking (id theo regex, `webhook url` chặn scheme, provider ∈ allow‑list) — KHÔNG tin client.

### 7.4 Tối ưu nạp — chi tiết còn thiếu
- **Gộp thẻ Google:** GA4 + Google Ads dùng CHUNG một `gtag.js`/GTM (một loader, nhiều `config`) — tránh tải trùng.
- **CSP roll‑out an toàn:** bật `Content-Security-Policy-Report-Only` + `report-uri` khi thêm origin động để bắt vỡ trước khi enforce.
- **Đa tên miền / alias per tenant:** track‑domain + CSP + cookie phải phủ mọi domain/alias của site (khớp `BASE_DOMAINS`).
- **Map icon nhà cung cấp sang SVG thật** (KHÔNG icon‑font `ri-*brand*` ở production — theo CLAUDE.md).

### 7.5 Quy trình repo (bắt buộc theo CLAUDE.md)
- **RBAC/Policy:** chỉ vai trò quản trị site được sửa Tích hợp; mọi thay đổi qua Policy + **audit log**.
- **Test‑first (Pest):** phủ builder CSP động, bộ trích ID (`extractId`), phát hiện dán nhầm, consent gating, guard cấu hình (giống `ValidPageJson`), offline‑conversion mapping.
- **Bảng event‑name mapping per nền tảng** (schema chung → tên riêng): vd `view_content` → Meta `ViewContent` / GA4 `view_item` / TikTok `ViewContent`; `submit_form` → `Lead`. Cần bảng tra để fan‑out đúng.
