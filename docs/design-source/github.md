repo: tuyenht/BSWebSaaS
branch: main

## Last sync
date: 2026-10-07T00:46:07Z

### Updated in this project
- D2-5: `Tổng quan.dc.html` theo quyền — repo chỉ có `Admin/Livewire/Dashboard.php` (tiêu đề + view); nhóm / quyền `PermissionRegistry.php`, giới hạn dung lượng `FeatureRegistry` (`media.storage_mb`); số đếm Chờ backend.

## Sync history

### 2026-10-07T00:13:47Z
- D2-4: chuông Shell + `Thông báo.dc.html` + mục Thông báo của `Tài khoản của tôi.dc.html` theo `Notifications/Registry/NotificationKinds.php` (11 loại), `Models/SiteNotification.php` (`summary()` `.change`), `Support/{NotificationFeed,NotificationPreferences,Notify}.php`, `Console/PruneSiteNotifications.php` (90 ngày), câu `lang/vi.json:860–884` → `BSCW.notices`.

## Sync history

### 2026-10-07T00:02:48Z
- D2-2: hộp Mời + tab Lời mời trong `Thành viên.dc.html` theo `Rbac/Actions/{InviteMember,AcceptInvitation,RevokeInvitation}.php`, `create_invitations_tables.php`, câu `lang/vi.json:812–842` (`rbac.invitation.*`, 10 mã lỗi).

## Sync history

### (trước)
date: 2026-10-06T23:29:47Z

### Updated in this project
- D1-4d: thay đổi chờ của bài đang công khai trong `Page Editor - Options.dc.html` + thẻ thay đổi ở `Hộp duyệt.dc.html` theo `Cms/Actions/{SaveEntryDraft,ApplyEntryDraftTransition,ApplyEntryDraft,DiscardEntryDraft}.php`, `Models/EntryDraft.php` (`version()`), `Exceptions/EntryDraftRejectedException.php` (9 REASONS) → `lang/vi.json:763–771` vào `BSCW.CHANGE_ERR`.

## Sync history

### 2026-10-06T22:56:36Z

### Updated in this project
- D2-1: `Nhóm quyền.dc.html` + `Thành viên.dc.html` theo `Rbac/Support/{CustomRoleRules,RoleHierarchyGuard}.php`, `RoleChangeRejectedException.php` (6 mã), `PermissionRegistry.php:71–84` (`IMPLIES` → `BSCW.IMPLIES`), `Users/Index.php`.

## Sync history

### 2026-10-06T22:06:46Z

### Updated in this project
- D1-7: `Khách liên hệ.dc.html` + `Biểu mẫu.dc.html` theo `Admin/Livewire/Forms/{Inbox,Detail}.php`, `create_forms_tables.php` (6 loại ô, trạng thái, is_spam, cascade), `Forms/Actions/EnsureContactForm.php` (ô consent).

## Sync history

### 2026-10-06T18:48:46Z

### Updated in this project
- D2-3: `Tài khoản của tôi.dc.html` theo `Admin/Livewire/Settings.php` (tên ≤120, đổi mật khẩu, 2 lớp QR → mã → mã khôi phục, tắt 2 lớp, gỡ liên kết, khóa khi đăng nhập hỗ trợ), `AppServiceProvider.php:40` (≥10 ký tự, không bị lộ), `lang/vi.json` 97/116/212/553.

### 2026-10-06T16:51:37Z

### Updated in this project
- D1-6: `Thư viện ảnh & tệp.dc.html` theo `Media/Library.php`, `UploadMedia.php` (MIME_EXT, MAX_KB), `MediaRejectedException.php` (16 REASONS + KEYS), `config/media.php` (trash 15 ngày), `Media.php` (ALT_MAX_LENGTH 188); câu lỗi `lang/*.json:345–361` viết lại đời thường trong `MediaPicker.dc.html`.

### 2026-10-06T09:46:29Z

### Updated in this project
- D0-4b: nhóm quyền nền tảng (`PermissionRegistry.php` platformRoles) vào `content-workflow.js`; tính năng gói (`FeatureRegistry.php`) + module (`ModuleCatalog.php`) vào `ai-quota.js`; Admin Shell ẩn/khóa menu theo Tweak.
- Registry màn `shell-screens.js` + điều hướng `bs-goto` / hash `#/<mặt>/<màn>`.

## Sync history (cũ)

### 2026-10-06T07:46:22Z

### Updated in this project
- D0-4at: cờ nút Ngôn ngữ giao diện lấy từ `public/images/admin/flags/{vi,en,ja,zh}.svg` (nhúng SVG trong Admin Shell, bản sao ở `assets/flags/`).

### 2026-10-06T04:09:41Z

### Updated in this project
- D0-4a phần 1: menu Admin Shell 2 mặt bám `AdminNav.php` (loại nội dung, catalog, Hộp duyệt theo `reviewQueue`, feature leaves) và `PlatformNav.php`; khóa quyền/module gắn trên từng mục.

### 2026-10-05T16:33:17Z

### Updated in this project
- D0-3s: bảng thuật ngữ + README D0-3 đối chiếu `lang/vi.json` (số dòng từng khóa), `PermissionRegistry.php:141–206` (6 nhóm website, 3 nhóm nền tảng), migration trạng thái 2005/2009/2012.

### 2026-10-03T18:01:38Z

### Updated in this project
- D0-2: `Chuẩn giao diện SaaS Starter.dc.html` — thuật toán màu chép từ `BrandPalette.php`, `ReadableColor.php`, `ThemeResolver.php:85/89` vào `content-ui.js`; bảng 18 Blade từ `app/Modules/Admin/resources/views/components/`.

### Trước đó (30/09/2026)
- D0-1: dọn bản trùng (`Website Config - Integrations` xoá, `Page Builder v2`/`- Options` vào `_archive/`), token `_ds` + `content-ui.js` + `content-workflow.js` + Admin Shell đồng bộ bậc AA của repo (PR #87/#92).
- Thanh ngôn ngữ của Cài đặt (Admin Shell) tính hạn ngạch theo **từ AI thật** qua `ai-quota.js` (`BSAI.translateCost`), chặn trước khi bấm.
- Thương hiệu nền tảng đọc từ `platform-config.js` (`window.BSPLAT`) — không còn tên nền tảng gắn cứng trong câu chữ.
- Tạo `SaaS Starter - Bản đồ màn hình.md` từ `docs/saas-starter-screen-map.md`.

### Cần đối chiếu lại ở backend (phát hiện khi đọc repo)
- `ApplyEntryWorkflowTransition` map `archived` (is_terminal, không published/initial) → `status = 'pending'`
  (`app/Modules/Cms/Actions/ApplyEntryWorkflowTransition.php`). Bài đã lưu trữ sẽ nằm lẫn với bài chờ duyệt
  trong mọi truy vấn theo `status`; nên map sang `'archived'`.
- `scheduled` và `private` không có trong máy trạng thái của workflow → khi một site bật quy trình, bài đang
  hẹn lịch/riêng tư không có cạnh nào để đi. Prototype tạm tính ở bước đầu (`draft`); backend nên
  quyết định rõ: hoặc hẹn giờ là thuộc tính của bước `approve`, hoặc thêm cạnh riêng.

## Sync history

### 2026-08-03T15:56:00Z
- Tách bảng vai trò · quy trình · trạng thái ra `content-workflow.js` (`window.BSCW`), bám `PermissionRegistry` ·
  `EntryPolicy` · `WorkflowTemplateRegistry` · `ApplyEntryWorkflowTransition`.
- Đổi khoá trạng thái theo migration 2012 (`entries.status`): thêm `scheduled` và `private`.
- Page Editor bỏ mô hình quyền giả → 6 vai trò + 3 chế độ duyệt; hành động hàng loạt canh `own/any` theo `EntryPolicy`.

### Trước 08/2026
- Đối chiếu mô hình ngôn ngữ của `SetLocale.php` và `Locales::codes()` vào màn Cài đặt email.
- Xác định 2 mẫu nội bộ là thư hệ thống (dựng từ file dịch), chuyển sang chỉ đọc.

## Screen map
| Màn hình | Nguồn trong repo |
| --- | --- |
| Admin Shell — menu mặt site / nền tảng | `app/Modules/Admin/Navigation/AdminNav.php`, `app/Modules/Admin/Navigation/PlatformNav.php` |
| Admin Shell — nút Ngôn ngữ giao diện (cờ) | `public/images/admin/flags/{vi,en,ja,zh}.svg` |
| Quản lý Bài viết / Trang (danh sách) | `app/Modules/Admin/Livewire/Entries/Index.php` |
| Page Editor / Trình soạn nội dung | `app/Modules/Admin/Livewire/Entries/Editor.php` |
| Hộp duyệt | `app/Modules/Admin/Livewire/Entries/ReviewInbox.php` |
| Quy trình duyệt | `app/Modules/Admin/Livewire/WorkflowSettings.php`, `app/Modules/Workflow/Registry/WorkflowTemplateRegistry.php`, `app/Modules/Cms/Actions/ApplyEntryWorkflowTransition.php` |
| Phân quyền nội dung (mọi màn) | `app/Modules/Rbac/Registry/PermissionRegistry.php`, `app/Modules/Cms/Policies/EntryPolicy.php` |
| Admin Shell — ẩn/khóa theo nhóm quyền · gói · module (Tweaks D0-4b) | `app/Modules/Rbac/Registry/PermissionRegistry.php`, `app/Modules/Tenancy/Registry/FeatureRegistry.php`, `app/Modules/Tenancy/Registry/ModuleCatalog.php` |
| Trạng thái nội dung (mọi màn) | `database/migrations/2000_01_01_000000_create_content_tables.php`, `database/migrations/2012_01_01_000000_expand_entry_status_and_add_view_count.php` |
| Thành viên (+ lời mời) | `app/Modules/Admin/Livewire/Users/Index.php`, `app/Modules/Admin/Livewire/AcceptInvitation.php` |
| Tài khoản của tôi | `app/Modules/Admin/Livewire/Settings.php`, `app/Providers/AppServiceProvider.php`, `app/Modules/Identity/Http/Middleware/EnsureTwoFactorEnabled.php` |
| Thông báo (chuông · trang · Tùy chọn) | `app/Modules/Notifications/Registry/NotificationKinds.php`, `app/Modules/Notifications/Support/NotificationFeed.php`, `app/Modules/Notifications/Support/NotificationPreferences.php`, `app/Modules/Notifications/Models/SiteNotification.php`, `lang/vi.json` (`notifications.*`) |
| Tổng quan (mặt Website) | `app/Modules/Admin/Livewire/Dashboard.php`, `app/Modules/Rbac/Registry/PermissionRegistry.php`, `app/Modules/Tenancy/Registry/FeatureRegistry.php` |
| Thư viện ảnh & tệp · Trình chọn ảnh | `app/Modules/Admin/Livewire/Media/Library.php` |
| Khách liên hệ (hộp thư · chi tiết) | `app/Modules/Admin/Livewire/Forms/Inbox.php`, `app/Modules/Admin/Livewire/Forms/Detail.php` |
| Sản phẩm (danh sách · trình sửa) | `app/Modules/Admin/Livewire/Catalog/Index.php`, `app/Modules/Admin/Livewire/Catalog/Editor.php` |
| Nền tảng — Khách hàng · Website · Nhật ký | `app/Modules/Admin/Livewire/Platform/Tenants/*`, `app/Modules/Admin/Livewire/Platform/Sites/*`, `app/Modules/Admin/Livewire/Platform/Audit/*` |
| Email Settings — Gửi thư | `app/Modules/Forms/Actions/SubmitForm.php`, `app/Modules/Forms/Notifications/NewLeadNotification.php`, `app/Modules/Storefront/Http/Requests/StoreLeadRequest.php` |
| Email Settings — Mẫu thư | `app/Modules/Admin/Notifications/*`, `lang/{vi,en,ja,zh}.json` (`forms.lead_email_*`) |
| Ngôn ngữ | `app/Modules/Admin/Http/Middleware/SetLocale.php`, `app/Modules/Admin/Support/Locales.php`, `config/locales.php` |

## Việc backend cần làm (từ prototype này)

### 0. Còn treo trong prototype
- Liên kết "Đổi màu thương hiệu" (tab Chữ ký & thương hiệu) hiện chỉ hiện toast — phải mở đúng
  Cài đặt › Giao diện website khi màn này ghép vào Admin Shell (§2 cấm dừng ở thông báo).
- Màu nút thư mặc định = màu thương hiệu website (`site.brand_color`); chỉ ghi `email.brand_color`
  khi admin bật "Dùng màu khác cho email". Màu chữ trên nút tính theo tương phản, không lưu.
- Thương hiệu nền tảng: cấp `BSPLAT.set({name, kit, rootDomain, logo, builtBy, support})` từ Cài đặt nền tảng ›
  Thông tin + Thương hiệu trắng; `builtBy.show` theo công tắc "Hiện dòng “Phát triển bởi”".
- Hạn ngạch: cấp `BSAI.setPlan()` + số từ đã dùng trong tháng cho thanh ngôn ngữ của Cài đặt (prototype mẫu 41.500).

### 1. Ngôn ngữ theo người dùng
- Ngôn ngữ riêng của người dùng lưu ở khoá `locale` trong `users.preferences` (quyết định 24/09) — không thêm cột riêng;
  lọc qua `Locales::codes()`.
- `User implements HasLocalePreference` → `preferredLocale()` đọc `preferences.locale`.
- `SetLocale` đổi thứ tự: `session('locale')` → `preferences.locale` → `SiteLocale::default()` → `APP_LOCALE`.
- Trang hồ sơ người dùng: chọn ngôn ngữ mặc định; bộ đổi ở topbar chỉ đè trong session.

### 2. Thư thông báo nội bộ
- Từ 24/09: form trả lời khách (lỗi, lời cảm ơn) bằng ngôn ngữ của trang khách đang xem; email báo khách mới gửi chủ
  website luôn bằng **ngôn ngữ mặc định của site** (`app/Modules/Storefront/Http/Requests/StoreLeadRequest.php`,
  `app/Modules/Forms/Actions/SubmitForm.php`).
- Giữ `replyTo($submission->email)` — đã đúng.

### 3. Mẫu thư — trạng thái backend
Prototype hiển thị 12 mẫu. Repo hiện có 5 notification:
- `app/Modules/Admin/Notifications/`: `EmailOtpNotification`, `ResetPasswordLinkNotification`,
  `MemberInvitationNotification`, `TwoFactorDisabledNotification`.
- `app/Modules/Forms/Notifications/NewLeadNotification.php` (nội bộ, dựng từ file dịch).
- Lời mời thành viên: backend đã có (`app/Modules/Admin/Livewire/AcceptInvitation.php`).
- **Chưa có backend:** Chào mừng, Xác minh địa chỉ email, Mật khẩu vừa được đổi, Xác nhận đơn hàng,
  Đã nhận thanh toán, Cập nhật vận chuyển, Đã giao thành công, Đơn hàng bị hủy, Có đơn hàng mới.
- Mẫu `internal` (`ntf_contact`, `ntf_order`) là **thư hệ thống**: nội dung từ file dịch, admin không sửa,
  không dịch theo `siteLangs`, loại khỏi mọi phép đếm dịch.

### 4. Gửi thư qua Google / Microsoft
- Ứng dụng kết nối do nền tảng sở hữu (multi-tenant), khách không tạo app.
- Route `GET /oauth/{provider}/redirect` + `/callback` (Socialite); scope `gmail.send` / `Mail.Send offline_access`.
- Lưu `refresh_token` mã hoá theo site, tự làm mới, tự gửi thư kiểm tra sau khi kết nối.
- Google yêu cầu xác minh ứng dụng trước khi mở `gmail.send` ra ngoài danh sách thử nghiệm.
