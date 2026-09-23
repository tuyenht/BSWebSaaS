> 📎 **THAM CHIẾU (brief v3, 17/07/2026)** — giữ vì bản đồ 46 màn và ma trận vai trò × màn còn giá trị.
> Bản THI CÔNG hiện hành là `saas-starter-design-first/` (25 lượt, chốt 24/09) — dùng bản đó để gửi Claude Design.

# YÊU CẦU THIẾT KẾ — Toàn bộ màn hình "SaaS Starter" (admin multi-tenant)
> Bản v3 (17/07) — sửa ma trận quyền theo code thật + Workflow/Entitlement theo backend ĐÃ BUILD + thêm bản-đồ-màn-hình. Dán nguyên khối vào Claude Design; nếu bị giới hạn độ dài, §0 là bắt buộc kèm mọi lần, rồi chạy từng cụm theo index dưới.

## 🗺 Bản đồ màn hình (46 màn — thiết kế mỗi màn 1 artifact)
- **Auth/tài khoản:** 1 Đăng nhập · 2 Thử-thách 2FA (TOTP+OTP) · 3 Quên/đặt-lại mật khẩu · 4 Xác minh email · 5 Hồ sơ cá nhân · 6 Bảo mật (2FA enroll + phiên)
- **Khung:** 7 Chrome (sidebar+topbar) · 8 Dashboard
- **Nội dung:** 9 Danh sách nội dung · 10 Trình soạn (+panel workflow) · 11 Thùng rác · 12 Taxonomy · 13 Menu builder
- **Catalog:** 14 Danh sách SP · 15 Trình sửa SP · 16 Cài đặt catalog
- **Media:** 17 Thư viện DAM (+detail/ALT/usage) · 18 Modal picker
- **Forms:** 19 Hộp thư lead · 20 Chi tiết lead
- **Workflow:** 21 Hộp duyệt (inbox) · 22 Panel workflow · 23 Cấu hình workflow
- **Users/RBAC:** 24 Thành viên · 25 Gán role · 26 RBAC Console · 27 Lời mời
- **Entitlement:** 28 Quản lý Gói · 29 Gán gói + override feature per-site · 30 Hub cài đặt (tabs)
- **Notifications:** 31 Trung tâm thông báo · 32 Tùy chọn thông báo
- **Domain:** 33 Quản lý tên miền
- **Platform (operator):** 34 Tenants · 35 Sites · 36 Audit toàn hệ · 37 site_types · 38 Themes · 39 Operators · 40 Cài đặt platform · 41 Operator console (provision) · 42 Impersonation
- **v1.1/tiện ích:** 43 Audit-cho-khách · 44 GDPR export/xóa tài khoản · 45 Trang lỗi (403/404/500/tạm-ngưng/bảo-trì) · 46 Kết quả tìm kiếm

## 0. Bối cảnh & ràng buộc (ĐỌC KỸ)
- Sản phẩm: **SaaS Starter** — bộ khung admin **multi-tenant bán được**, dùng cho MỌI loại SaaS (không chỉ website). Đặc tính lõi: **đa ngôn ngữ (VN-first), phân cấp/phân quyền động, duyệt tin/bài đa cấp**.
- 🔴 **DÙNG ĐÚNG KHUNG ADMIN HIỆN CÓ (Velzon).** Scan admin live tại `https://av1.baoson.net/admin` (đã bật auto-login). GIỮ NGUYÊN & tái dùng: sidebar navy `#405189`, topbar, Customizer, design-tokens, icon **Remix inline-SVG**, component `x-admin::*`. **KHÔNG chế shell/hệ màu/typography mới** — chỉ thiết kế NỘI DUNG từng màn trong khung đó.
- Mọi màn: **light/dark · mobile-first · WCAG AA · đủ 4 state (empty/loading/error/success)** · lỗi bằng **ngôn ngữ đời thường** · **i18n-ready VN/EN** · không ngõ cụt.
- 🔴 **Thiết kế theo CAPABILITY, KHÔNG theo tên role.** Phân quyền là ĐỘNG (super_admin/owner sửa được) → mỗi nút/cột/tab render theo *permission thực* người dùng đang giữ + *cấp (level)*, không hardcode "if role == editor". Ma trận role ở §15 chỉ là *mặc định minh hoạ*.

### Mô hình quyền (đúng code — `PermissionRegistry`)
- **2 mặt phẳng:** **Platform plane** (host riêng) — operator Bảo Sơn; **Tenant admin plane** (per-site) — admin sản phẩm.
- **Role platform:** `super_admin`(mọi quyền) · `support`(chỉ xem tenants/sites/audit) · `billing`(quản lý billing + xem tenants).
- **Role tenant (site_type `corporate`):** `owner`(90, mọi quyền) · `admin`(70, **cùng bộ quyền owner**, khác ở *cấp* — không quản được owner/admin khác) · `editor`(50) · `author`(30) · `contributor`(20) · `viewer`(10).
- **Quyền:** entries.{view,create,edit.own,edit.any,delete.own,delete.any,submit,review,approve,publish} · media.{view,upload,delete} · taxonomies.manage · menus.manage · forms.{view,manage} · catalog.{view,manage} · settings.manage · users.{invite,manage} · roles.manage · domains.manage. (own/any CHỈ áp cho `entries`; media/catalog/forms KHÔNG có own/any.)
- **Luật cấp:** chỉ cấp/thu role **dưới cấp mình** + chỉ quyền mình đang giữ.

---
## 1. Auth & tài khoản (mọi role)
Đăng nhập (email+mật khẩu) · 2FA challenge (TOTP + email-OTP) · quên/đặt-lại mật khẩu · xác minh email · social login (OAuth) · **Hồ sơ cá nhân** · **Bảo mật** (bật/tắt 2FA + enroll QR + mã khôi phục, danh sách phiên + thu hồi) · chuyển ngôn ngữ.

## 2. Khung & điều hướng
Sidebar **lọc theo quyền** · Topbar (**tìm kiếm toàn cục**, chuông thông báo, menu user, **site-switcher** cho owner nhiều site, badge plane) · Customizer · breadcrumb.

## 3. Dashboard (widget đổi theo role)
Thẻ số liệu · hoạt động gần đây · **tóm tắt hộp-duyệt** · quick-actions. Vẽ biến thể owner / editor / author / viewer.

## 4. Nội dung (CMS)
- **Danh sách nội dung** section-driven (Trang/Tin/Dịch vụ/Dự án…): filter+search+bulk, cột **trạng thái** (draft/pending/published/scheduled/private/archived) + **badge trạng-thái-workflow** khi section có quy trình. author thấy `own`, editor+ thấy `any`.
- **Trình soạn nội dung:** page-builder (section library ADR-04) · SEO (title/desc/OG/canonical/slug) · **tab dịch i18n per-locale** · **lên lịch đăng** (scheduled + ngày/giờ) · **công tắc riêng tư** (private) · **panel workflow** (xem §8) · **revision history** (backlog — vẽ khung).
- **Thùng rác.**
- **Quản lý Taxonomy** *(MỚI — nay là placeholder):* cây danh mục đa cấp, thêm/sửa/xóa/kéo-thả, gán template.
- **Menu builder** *(MỚI — placeholder):* kéo-thả, region × locale, fallback locale mặc định.

## 5. Catalog (sản phẩm)
Danh sách (search+budget) · Trình sửa (gallery, thông số, danh mục, SEO, publish) · Cài đặt catalog.

## 6. Media (DAM)
Thư viện (grid + thư mục + upload Uppy) · panel chi tiết + **ALT** · panel **usage** · **modal picker**.

## 7. Forms / Leads
Hộp thư lead (list + trạng thái + spam) · Chi tiết lead.

## 8. 🚩 Workflow duyệt bài (đã build backend — thiết kế KHỚP data thật)
Backend đã chạy: quy trình **data-driven per-site × per-content-type**, **mặc định TẮT**. State có cờ **`is_published`** quyết định ra-công-khai (KHÔNG theo tên). 2 template chuẩn:
- **`admin_approval`** (1 cửa duyệt): `draft → pending → published` (+`archived`). Nút: Gửi duyệt · Duyệt đăng · Trả lại(bắt buộc ghi chú) · Gỡ bài(ghi chú) · Lưu trữ.
- **`editorial`** (đa cấp — gửi→biên tập→phản biện→duyệt): `draft → pending(chờ biên tập) → reviewing(phản biện) → published` (+`archived`). Nút: Gửi biên tập · Chuyển phản biện · Duyệt đăng · Yêu cầu sửa(ghi chú) · Trả lại tác giả(ghi chú) · Gỡ bài · Lưu trữ.

Màn cần thiết kế:
- **Hộp duyệt (Workflow Inbox):** tab "Việc của tôi" / "Chờ tôi duyệt" / "Đã giao" — lọc theo *người-được-giao* + *trạng thái hiện tại*; biến thể theo role (author: bài mình + yêu-cầu-sửa; editor/reviewer: hàng chờ; approver: chờ phê duyệt).
- **Panel workflow trong editor:** **stepper trạng thái hiện tại** · **CHỈ hiện nút mà quyền người dùng cho phép** (backend đã lọc theo `required_permission` từng transition) · trường **ghi chú/phản biện** (bắt buộc ở một số nút) · **người được giao** (assign) · **lịch sử** (ai/lúc-nào/ghi-chú, từ log).
- **Cấu hình workflow** *(super_admin/owner):* chọn template (`none`/`admin_approval`/`editorial`) cho từng content-type × site · **công tắc Bật/Tắt** (mặc định Tắt) · (nâng cao) builder sửa state + transition + gán *permission-cho-mỗi-transition*. **Lưu ý:** template `editorial` chỉ chọn được khi site **được cấp feature `workflow.editorial`** (xem §10) — nếu không, disable + tooltip "gói chưa hỗ trợ".

## 9. Users & Phân quyền (Console MỚI)
- **Danh sách thành viên** (role, trạng thái, **mời**, khóa/mở).
- **Chi tiết user + gán role** (chỉ cấp role thấp hơn mình).
- **RBAC Console** *(owner/admin — `roles.manage`):* danh sách role · **trình sửa role** (ma trận permission + level + scope own/any cho entries) · **tạo role tùy biến** (copy-on-write — DB đã có `forked_from_role_id`, entitlement-gated) · **Xem trước quyền** ("role này làm được gì" dạng checklist). *admin bị chặn theo cấp: chỉ đụng role dưới level 70.*
- **Lời mời** (pending/expired/resend).

## 10. Entitlement / Gói / Tính năng / Cài đặt (backend plan/feature ĐÃ build)
- **Quản lý Gói (Plans)** *(super_admin):* danh mục gói (global) + gói cấp những **feature** nào. *(Không có giá — billing hoãn.)*
- **Gán gói cho tenant** + **override feature per-site** (bật/tắt/đặt hạn mức từng site). Feature thật hiện có: `workflow.editorial` (bool), `sites.max` (limit), `media.storage_mb` (limit).
- **Bật/tắt module per-site** (site_modules) — lớp thô, song song với feature.
- **Hub cài đặt (tenant, gated `settings.manage`):** Bảo mật · Email/SMTP · Social/OAuth · Giao diện/Customizer · Catalog · **Thương hiệu/NAP/Hồ sơ site + favicon** · **Ngôn ngữ (locales)** · (tương lai) Gói & thanh toán.

## 11. Notifications
**Trung tâm thông báo** (dropdown chuông + trang đầy đủ, đọc/chưa-đọc) · **Tùy chọn thông báo** (theo kênh × theo loại sự kiện, gồm sự kiện workflow: được-giao-việc, đổi-trạng-thái, yêu-cầu-sửa).

## 12. Domain (MỚI — schema đã sẵn: kind subdomain/custom · is_primary · ssl_status · verified_at)
Danh sách tên miền của site · thêm tên miền tùy chỉnh · trạng thái xác minh + TLS · đặt primary.

## 13. Platform plane (operator — biến thể theo super_admin / support / billing)
Tenants list/detail · Sites list/detail · **Audit log toàn hệ** · **Quản lý site_types** · **Quản lý theme platform** · **Quản lý operator** (tạo/sửa super_admin/support/billing) · Cài đặt platform (email/security) · **Operator console** (provision tenant/site mới — chọn site_type) · **Impersonation** (đăng-nhập-hộ có consent + banner + log). *support: chỉ xem; billing: billing + xem tenants.*

## 14. Tuân thủ + Tiện ích (v1.1 — vẽ khung, đánh dấu "sau")
Audit trail cho khách (per-tenant, xuất được) · Xuất dữ liệu / Xóa tài khoản (GDPR/PDPD) · **Trang lỗi/tiện ích: 403 / 404 / 500 / site tạm ngưng / bảo trì** · **Kết quả tìm kiếm toàn cục.**

## 15. MA TRẬN ROLE × MÀN HÌNH — tenant plane (F đầy đủ · own của-mình · U upload · V xem · – ẩn)
| Màn / chức năng | owner | admin | editor | author | contributor | viewer |
|---|---|---|---|---|---|---|
| Nội dung — xem | F | F | F | F | F | V |
| Nội dung — tạo/sửa | F | F | any | own | own | – |
| Nội dung — xóa | F | F | any | own | – | – |
| Gửi duyệt (submit) | F | F | F | F | F | – |
| Duyệt/phản biện/publish | F | F | F | – | – | – |
| Catalog | F | F | F | – | – | V |
| Media | F | F | F | U | V | V |
| Forms/Leads | F | F | F | – | – | V |
| Taxonomy / Menu | F | F | F | – | – | – |
| Users (invite/manage) | F | F* | – | – | – | – |
| RBAC Console (roles.manage) | F | F* | – | – | – | – |
| Settings hub (settings.manage) | F | F | – | – | – | – |
| Domains (domains.manage) | F | F | – | – | – | – |
| Notifications (của mình) | F | F | F | F | F | F |

`*` admin bị **chặn theo cấp** (chỉ role/user < level 70) + chỉ cấp quyền mình giữ.
**Platform plane (riêng):** super_admin = toàn quyền · support = xem tenants/sites/audit · billing = billing + xem tenants.

## 16. Deliverables
- Mỗi màn: desktop + mobile · light + dark · đủ 4 state.
- Màn có biến thể role: vẽ ≥2–3 biến thể tiêu biểu + chú thích permission cạnh hành động nhạy cảm.
- Tái dùng đúng component Velzon; component mới phải theo token/phong cách hiện có + nêu lý do.
- **Ưu tiên:** (1) màn MỚI khớp backend đã build — **Workflow (inbox/panel/config §8)**, **RBAC Console §9**, **Plans/Feature §10**, **Notifications §11**, **Domain §12**, Taxonomy/Menu §4; (2) tinh chỉnh màn đang có; (3) Platform plane §13; (4) Tuân thủ/tiện ích §14.
