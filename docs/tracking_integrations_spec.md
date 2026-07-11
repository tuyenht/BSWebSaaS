# Tích hợp & Theo dõi — Kiến trúc chuẩn (perf · chi phí · bảo mật @scale) + Re‑marketing

> Tổng hợp từ audit hội đồng (2026‑07). Bám code repo `main`: `AddSecurityHeaders` (CSP storefront),
> `ThemeResolver` (token config, ADR‑04), Octane/FrankenPHP, PostgreSQL RLS, Valkey/Horizon, Forms/Turnstile.
> Mục [COUNCIL] = chạm CSP/schema/security → propose‑first khi vào repo.

## 0. Nguyên tắc nền (không thương lượng)
- **ADR‑04:** không chạy code khách tuỳ ý. Snippet = **platform‑curated, tham số hoá theo ID** ⇒ nội dung biết trước ⇒ khoá bằng **nonce/hash**, KHÔNG mở `unsafe-inline`.
- **CSP storefront giữ chặt** (`default-src 'self'`); admin plane vẫn miễn CSP (Livewire/Alpine) — **không lây** luật admin sang storefront.
- **Đa tenant cách ly:** mỗi site có bộ pixel/audience/consent RIÊNG; sự kiện gắn `site_id` + RLS; **không trộn** khách giữa các site (pháp lý + kỹ thuật).
- **Ngân sách hiệu năng:** storefront JS ≤30KB, LCP ≤1.5s. Tracking KHÔNG được phá ngân sách này.

## 1. Kiến trúc "First‑party Server‑side Hub" (trục chính — khuyến nghị)
Khách → **1 collector first‑party** (`/_c` hoặc `track.<tenant>.vn`) thu event → **queue (Valkey/Horizon)** → **fan‑out server‑side** tới nhiều đích qua API chính chủ:
- Meta **CAPI**, Google **Ads/GA4 Measurement Protocol / Enhanced Conversions**, TikTok **Events API**, (Zalo client nếu chưa có API).
- **Lợi ích hội tụ mọi mục tiêu:**
  - *Tốc độ/CWV:* client gần như chỉ 1 beacon first‑party (~8KB) thay vì 4–5 SDK → LCP giữ ≤1.5s.
  - *Chi phí:* fan‑out async không chặn response; cache render mạnh (mục 4) giảm compute; ít script = ít băng thông.
  - *Bảo mật:* CSP gần như `'self'`; không lộ token client; hashing PII ở server; consent enforce 1 chỗ.
  - *Chính xác:* né adblock/ITP (thu thêm ~15–30% sự kiện); dùng lại được khi cookie bên thứ 3 chết.
- Client‑side pixel chỉ giữ tối thiểu để lấy tín hiệu trình duyệt + click‑id (`fbclid/gclid/ttclid`), rồi chuyển server‑side.

## 2. Provider Registry (khai báo — "phủ hết" bằng dữ liệu, không hardcode UI)
Giống `ComponentRegistry`: mỗi provider = 1 entry khai báo `{ id, group, field(s), template, csp[], position, consent_category, serverSide, risky? }`.
Thêm provider mới = thêm **một entry** (+ [COUNCIL] vì đụng CSP). UI tự sinh; tenant chỉ **bật/tắt + nhập ID**.
- **CSP sinh động:** `AddSecurityHeaders` gộp `csp[]` của các provider *đang bật* cho từng site. Bật → thêm origin; server‑side → không thêm origin nào.
- **GTM = escape hatch** cho phần đuôi dài: 1 container để tenant tự quản lý; **client GTM** cần nới CSP (nonce) → **opt‑in, cảnh báo CWV**; ưu tiên **server‑GTM (sGTM)** trên first‑party.
- **Danh mục curate (phủ 90%+ nhu cầu VN):** GA4 · Google Ads · Meta Pixel+CAPI · TikTok Pixel+Events API · Zalo · Microsoft Clarity · Google Search Console (meta) · Bing (meta) · GTM client (nâng cao) · Server container sGTM (khuyến nghị).

## 3. Consent & pháp lý (bắt buộc)
- Nghị định 13/2023 (VN) + GDPR: tag **marketing** chỉ fire **sau đồng ý**. Registry gắn `consent_category`; 1 banner điều phối (Google **Consent Mode v2**: trước đồng ý ở trạng thái `denied`).
- Meta‑verify không cần consent (chỉ thẻ meta, không script).

## 4. Hiệu năng & chi phí @scale (đòn bẩy lớn nhất)
- **Cache render Page‑JSON→HTML** per site+locale (Valkey), bust theo version khi xuất bản; **CDN edge** + ETag → phần lớn request không chạm PHP.
- **Octane/FrankenPHP** giữ app in‑memory (không bootstrap mỗi request).
- **DB:** event KHÔNG ghi Postgres realtime (nặng) → **queue → batch/аналytics store**; truy vấn RLS + keyset, no N+1, no `COUNT(*)` live; media R2 + CF Worker (width 1280).
- **Client budget:** server‑side hub giữ JS ≤30KB; defer/async; cân nhắc **Partytown** (web worker) cho script bẩn còn sót; SRI cho loader ngoài.

## 5. Re‑marketing đa nền tảng (bài toán "bám đuổi khách")
1. **Thu hành vi** trên storefront: `page_view, view_content(sản phẩm/bài), search, add_to_cart, begin_contact, submit_form, purchase` (schema event chung).
2. **Định danh chống mất mát:** click‑id (`fbclid/gclid/ttclid`) + PII **hash SHA‑256** (email/phone) cho Advanced/Enhanced Matching — làm ở **server**.
3. **Fan‑out server‑side** cùng 1 event tới Meta/Google/TikTok → mỗi nền tảng **tự dựng Custom Audience** theo tenant (vd "đã xem sản phẩm X, chưa mua").
4. **Chạy quảng cáo bám đuổi** trên FB/IG/Google/YouTube/TikTok theo tệp đó; đo vòng lại bằng chính event server‑side.
- **Cách ly tenant tuyệt đối:** audience/pixel theo `site_id`; không dùng chung; tôn trọng consent per người dùng.
- **Không cookie bên thứ 3:** dựa first‑party + server‑side ⇒ bền với ITP/Chrome deprecation.

## 6. UI (deliverable) — "Cấu hình website → Tích hợp & Theo dõi"
`Website Config - Integrations.dc.html` (đã dựng & kiểm chứng): ~16 provider theo 5 nhóm (Đo lường · Quảng cáo & Re‑marketing · **Hỗ trợ & Chat** · Xác minh · Nâng cao & Kết nối) — toggle a11y (`role="switch"`+`aria-checked`) + ô ID (validate) + **ô Token/API Secret** khi server‑side (Meta/TikTok/GA4/Ads) + badge (Khuyến nghị/Server‑side/Cần đồng ý/Thẻ meta/Nặng/Nới CSP, có tooltip); **icon "i"** mỗi công cụ mở giải thích + link "Hướng dẫn lấy ID"; nút **"Gửi sự kiện thử"**.
- **Consent 3 chế độ:** *Tắt hỏi (thu ngay — cảnh báo rủi ro, KHÔNG chặn)* · **Consent Mode v2 (khuyến nghị — hỏi nhẹ, từ chối vẫn có số liệu mô hình hoá)** · *Chặt opt‑in*.
- **Right rail động:** Thu thập phía máy chủ (toggle) · Consent · CWV (KB vs 30KB, đỏ khi vượt) · CSP origins tự sinh · nhóm dữ liệu đang thu · checklist bảo mật. Bật server‑side → origins co về `'self'`, CWV≈0 (minh hoạ trực quan). Responsive stack khi hẹp.
- **Chat widget** (Zalo OA/Messenger/Tawk) = script nặng, luôn client → CWV cao; ghi chú phân biệt với **Nút liên hệ nổi** (chỉ mở link). **Webhook (lead)** nối CRM/Zapier — gửi server‑side, không CSP.

## 6b. Trợ giúp lấy ID cho người không chuyên (đã dựng trên UI)
- **Tầng 0 — dán là xong:** ô ID **tự trích giá trị** khi khách dán cả đoạn (script/thẻ `<meta content="…">`/`fbq('init',…)`)
  → chỉ giữ ID sạch (`G-`, `GTM-`, `AW-`, Pixel số, URL webhook…) + thông báo "Đã tự nhận diện". Chỉ trích bằng regex, không thực thi.
- **Tầng 1 "i":** công cụ là gì / để làm gì (1–2 câu).
- **Tầng 2 "Cách lấy {ID/mã/URL}?"** ngay dưới ô nhập: **3–4 bước tiếng Việt** + nút "Mở đúng trang ↗" (deep‑link) + "Chưa có? Tạo mới ↗".
- **Tầng 3:** "Hướng dẫn chi tiết ↗" (doc chính thức) + "Gửi sự kiện thử".
- **Chip "Hợp lệ"** khi ID đúng định dạng; cảnh báo đỏ khi sai. **"Nhờ Bảo Sơn cài giúp"** (dịch vụ) cho người không rành.
- **Cảnh báo double‑tracking:** bật GTM cùng GA4/Pixel riêng → nhắc khai báo trong GTM, tránh đếm trùng.
- a11y: nút "i"/"Cách lấy" có `aria-expanded`; **i18n**: nội dung hướng dẫn cần dịch theo locale admin (en/vi/ja/zh) khi hiện thực.

## 7. [COUNCIL] cần chốt khi hiện thực
- **Dedup đo lường:** khi vừa client pixel vừa server CAPI/Events API → gắn **event_id** chống đếm đôi.
- **Consent theo nhóm** (analytics chạy sớm, marketing chờ đồng ý) thay vì công tắc toàn cục; **audit log** khi tenant chọn
  "Tắt hỏi"; cân nhắc platform ép mức consent tối thiểu theo vùng (trách nhiệm liên đới).
- **Preview không bắn tracking** (admin tự xem không làm bẩn số liệu).
- **Tag health:** định kỳ kiểm tra pixel còn nhận event, cảnh báo khi ngừng.
- **sGTM = dịch vụ platform‑managed:** mặc định dùng endpoint first‑party của nền tảng (khách không cài gì); tên miền riêng =
  tuỳ chọn nâng cao (1 bước CNAME + "Kiểm tra kết nối": Chưa trỏ / Đang chờ DNS / Đã hoạt động). *(Đã dựng trên UI — hạ tầng chờ chốt.)*
- **Công cụ quốc tế** (Pinterest/LinkedIn/X/Snapchat/Reddit): nhóm ẩn, mở bằng header có chevron, kèm cảnh báo "chỉ khi chạy quảng cáo
  thị trường nước ngoài". *(Đã dựng trên UI.)*
- **Phát hiện dán nhầm ID:** ô nhập tự nhận ID dán cả đoạn; nếu sai định dạng và khớp mẫu công cụ khác → báo "có vẻ là ID của {X}". *(Đã dựng.)*
CSP động theo provider + **nonce cho snippet init** · collector first‑party + queue fan‑out · lưu token provider (mã hoá at‑rest, KHÔNG lộ client) · consent store + Consent Mode v2 · sGTM hạ tầng (tự host vs Cloud Run — chi phí) · analytics store cho event (tách Postgres OLTP).
