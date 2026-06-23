# Customer-auth (storefront) — Quyết định & bất biến (TRẠNG THÁI: HOÃN)

## Trạng thái
HOÃN xây. Lý do: ngoài phase order; chưa có consumer thật; đụng stateless storefront (CWV §18/§25); phương thức định danh nền chưa chốt.

## Trigger để xây thật (cần ĐỦ CẢ HAI)
1. Có nhu cầu member-area/commerce THẬT, và
2. Đã chốt phương thức định danh nền (khả năng cao: phone + OTP).

Thiếu 1 → tiếp tục hoãn.

## Scope dự kiến khi xây
- Anchor định danh VN = phone + OTP (verified phone). Google OAuth = add-on (verified email).
- Zalo-OAuth: LOẠI (web-login không trả phone → giá trị cận biên ~0 khi đã có phone+OTP).
- Zalo-ZNS: chỉ TÙY CHỌN transport gửi OTP (khác Zalo-login) — cần OA + duyệt template.

## Bất biến kiến trúc (nếu/khi xây)
1. Callback đa-tenant = 1 URL CỐ ĐỊNH + ẨN cho TOÀN hệ thống (Google không cho wildcard redirect_uri — VERIFY khi xây). site_id đi trong `state`, không lộ URL. [OPEN] host platform sẵn có vs host accounts.* riêng (gọn vs sạch trust-domain).
2. `state` + handoff-ticket lưu Valkey, TTL ngắn, SINGLE-USE — KHÔNG session (cross-host + per-host cookie + Octane). PHỤ THUỘC feat/per-host-session-cookie ship trước.
3. Resolve/link/create khách chạy TRÊN TENANT-HOST (đã có tenant context), KHÔNG trên platform-host (tránh context-switch nhạy Gate#1).
4. Guard `customer` RIÊNG (tách `web`); resolve SAU khi tenant-context lập (site_customers có RLS). Phụ thuộc: cơ chế per-host session phải guard-agnostic (xem báo cáo survey Việc 1).
5. site_customers + customer_social_accounts: BelongsToSite + RLS fail-closed + FORCE + soft-delete (deleted_at IS NULL) + ulid (D2). phone/email = citext. Login-only ⇒ KHÔNG lưu token provider.
6. Dedup = Google `sub` (KHÔNG phải email — email đổi được); kiểm `email_verified=true` trước khi tin.
7. [G1] HTML cache giữ USER-AGNOSTIC; "tài khoản của tôi" hydrate qua island/XHR auth riêng; edge BỎ QUA cookie cho anonymous; cookie khách chỉ set SAU login.
8. Validate `return_url` theo bảng `domains` của site_id (chống open-redirect).

## MOOT ở single-provider (ĐỪNG xây v1)
Linking-by-email/phone, màn confirm, OTP-ownership, quản lý đa-provider, dedup chéo-provider — chỉ cần khi có ≥2 phương thức TẠO tài khoản.
