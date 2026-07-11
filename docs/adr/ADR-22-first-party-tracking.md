# ADR‑22 — First‑party collector + fan‑out server‑side + Consent Mode v2 + `site_integrations`

- **Trạng thái:** **Đề xuất (Draft)** — ĐÃ LAND design.md dòng 22 + v2.14 (khớp staging, không lệch) · **Deferred** cho An Vinh (brochure không bật). **Chạm schema + hạ tầng** → COUNCIL.
- **Hiện thực:** pha thương mại (Wave 4). tracking‑domain suy từ `config('tenancy.base_domains')` (zero hardcode).
- **Liên quan:** ADR‑04 · ADR‑21 (CSP/nonce) · Forms/Turnstile · RLS đa tenant. **Nguồn spec:** `docs/tracking_integrations_spec.md`, `docs/tracking_storefront_impl.md`.

## Bối cảnh
- Re‑marketing đa nền tảng (Meta/Google/TikTok) cần né adblock/ITP + sống khi 3p‑cookie chết. Đa tenant **cách ly tuyệt đối** (pixel/audience/consent per `site_id` + RLS). VN Nghị định 13/2023 + GDPR: tag marketing chỉ fire **sau đồng ý**.
- `CatalogMode` = **brochure** (An Vinh, lead‑gen — ẩn giá/đặt hàng) / **commerce** (P2). Tracking hỗ trợ cả hai.

## Quyết định
1. **Collector first‑party** (`/_e` hoặc `sgtm.<PRIMARY_BASE>`) → **queue Valkey/Horizon** → **fan‑out server‑side** (Meta CAPI · GA4 MP/Enhanced Conversions · TikTok Events API) với **token giữ server**, **hash PII SHA‑256**, **event_id** dedup client↔server. Client chỉ 1 beacon first‑party (~6–8KB) + bắt click‑id (`gclid/fbclid/ttclid`).
2. **`site_integrations(site_id, provider, enabled, public_id, secret_enc, position, strategy, consent_category, updated_at)`** — `secret_enc` **mã hoá at‑rest, không lộ client**; RLS. ADR‑21 đọc CSP động từ đây.
3. **Consent Mode v2** (4 tín hiệu `ad_storage/analytics_storage/ad_user_data/ad_personalization`) + **consent record** + banner **đa ngôn ngữ theo locale** (deliverable riêng) + tôn trọng **GPC/DNT**. Nhóm marketing chờ đồng ý; analytics có thể chạy sớm (per nhóm).
4. **Attribution lead:** `gclid/fbclid/ttclid` + `utm_*` (first+last touch) đính vào `form_submissions` (cột riêng có index **hoặc** `data.attribution` JSONB shape chuẩn) → **Offline Conversion Upload** (cấu hình trạng thái lead nào = chuyển đổi).
5. **Storefront:** MỘT Blade partial `theme::partials.tracking` (khe `head/body_start/body_end`) + `strategy` (ADR‑21). Hardening `/_e` (rate‑limit/size/schema/CORS); **retry + Dead‑Letter + idempotency** theo `event_id`; **preview không bắn tracking**.
6. **Commerce (P2 — dự phòng schema, KHÔNG dựng GĐ1):** event item‑level (`view_item/add_to_cart/purchase…`), `value+currency=VND integer`, **`purchase` từ ĐƠN HÀNG server** (authoritative, dedup `order_id`); feed catalog khớp `product_grid` IDs.

## [COUNCIL] Thay đổi
- **Schema:** `site_integrations` (+RLS, secret mã hoá at‑rest); attribution trên `form_submissions`.
- Collector endpoint + queue fan‑out jobs + event‑name mapping table (schema chung → tên riêng mỗi nền tảng).
- Consent store (Consent Mode v2). Hạ tầng **sGTM** (Cloud Run vs tự host — chi phí). **Analytics store tách Postgres OLTP** (event không ghi OLTP realtime).
- **White‑label:** brand/tên miền nền tảng = **config** (`platform.name`, `sgtm.<PRIMARY_BASE>` suy động) — **zero hardcode** `baoson.net`. Claude Code tra key sẵn có (`BASE_DOMAINS`/`config('tenancy.*')`), không tạo hằng mới.

## Hệ quả
- **+** Đo lường bền cookieless; cách ly tenant; re‑marketing đa kênh; offline conversion cho lead‑gen; CWV giữ ≤30KB/LCP≤1.5s nhờ server‑side.
- **−** Hạ tầng nặng (collector/queue/sGTM/analytics store/consent); nhiều COUNCIL → **hoãn sau An Vinh brochure**.

## Test‑first (Pest)
- `SiteIntegrationsRlsTest` — RLS cách ly; `secret_enc` không trả client.
- `ConsentGatingTest` — marketing bị chặn trước đồng ý; Consent Mode v2 set 4 tín hiệu.
- `EventDedupTest` — `event_id` chống đếm đôi client↔server.
- `LeadAttributionTest` — click‑id/utm lưu vào `form_submissions`; offline‑conversion mapping theo trạng thái lead.
- `CollectorHardeningTest` — rate‑limit/size/schema/CORS; re‑validate config server (id regex, webhook scheme, provider allow‑list).
- `PreviewNoTrackingTest` — admin preview không bắn event.

## Phụ thuộc
ADR‑21 (CSP/nonce). Deferred đến Wave 4; An Vinh GĐ1 không bật (chỉ giữ Draft + schema dự phòng nếu gộp Wave 0).
