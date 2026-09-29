repo: tuyenht/BSWebSaaS
branch: main

## Last sync
date: 2026-08-03T15:56:00Z

### Updated in this project
- Tách bảng vai trò · quy trình · trạng thái ra `content-workflow.js` (`window.BSCW`) — một nguồn cho cả
  màn Bài viết và Page Editor, bám `PermissionRegistry` · `EntryPolicy` · `WorkflowTemplateRegistry` ·
  `ApplyEntryWorkflowTransition`.
- Đổi khoá trạng thái theo migration 2012 (`entries.status`): thêm `scheduled` và `private`, bỏ khoá tự
  đặt `sched`/`pub`/`priv`; `reviewing` được ghi nhận là **bước của quy trình**, không phải entries.status.
- Page Editor bỏ mô hình quyền giả (2 vai trò, luật "editor không được đăng" — sai so với repo) → 6 vai
  trò + 3 chế độ duyệt, nút Đăng và danh sách trạng thái sinh từ transition có thật + guard quyền.
- Hành động hàng loạt ở danh sách bài nay canh `own/any` theo `EntryPolicy` cho từng dòng.

### Cần đối chiếu lại ở backend (phát hiện khi đọc repo)
- `ApplyEntryWorkflowTransition` map `archived` (is_terminal, không published/initial) → `status = 'pending'`.
  Bài đã lưu trữ sẽ nằm lẫn với bài chờ duyệt trong mọi truy vấn theo `status`; nên map sang `'archived'`.
- `scheduled` và `private` không có trong máy trạng thái của workflow → khi một site bật quy trình, bài đang
  hến lịch/riêng tư không có cạnh nào để đi. Prototype tạm tính ở bước đầu (`draft`); backend nên
  quyết định rõ: hoặc hến giờ là thuộc tính của bước `approve`, hoặc thêm cạnh riêng.

## Sync history

### Updated in this project
- Đối chiếu mô hình ngôn ngữ của `SetLocale.php` và `Locales::codes()` vào màn Cài đặt email.
- Xác định 2 mẫu nội bộ là thư hệ thống (dựng từ file dịch), chuyển sang chỉ đọc.
- Ghi nhận `NewLeadNotification` thiếu `->locale()` — thư thông báo đang dịch theo ngôn ngữ khách.

## Screen map
| Màn hình | Nguồn trong repo |
| --- | --- |
| Email Settings — Gửi thư | `app/Modules/Forms/Actions/SubmitForm.php`, `app/Modules/Forms/Notifications/NewLeadNotification.php` |
| Email Settings — Mẫu thư | `lang/{vi,en,ja,zh}.json` (`forms.lead_email_*`), `app/Modules/Auth/Notifications/*` |
| Ngôn ngữ | `app/Modules/Admin/Http/Middleware/SetLocale.php`, `app/Modules/Admin/Support/Locales.php`, `config/locales.php` |
| Quản lý Bài viết — phân quyền | `app/Modules/Rbac/Registry/PermissionRegistry.php`, `app/Modules/Cms/Policies/EntryPolicy.php` |
| Trạng thái nội dung (cả hai màn) | `database/migrations/2000_01_01_000000_create_content_tables.php`, `database/migrations/2012_01_01_000000_expand_entry_status_and_add_view_count.php` |
| Page Editor — phân quyền & quy trình | `PermissionRegistry.php`, `EntryPolicy.php`, `WorkflowTemplateRegistry.php` (qua `content-workflow.js`) |
| Quản lý Bài viết — quy trình duyệt | `app/Modules/Workflow/Registry/WorkflowTemplateRegistry.php`, `app/Modules/Cms/Actions/ApplyEntryWorkflowTransition.php` |

## Việc backend cần làm (từ prototype này)

### 0. Còn treo trong prototype
- Liên kết "Đổi màu thương hiệu" (tab Chữ ký & thương hiệu) hiện chỉ hiện toast — phải mở đúng
  Cài đặt › Thương hiệu & Giao diện khi màn này ghép vào Admin Shell (§2 cấm dừng ở thông báo).
- Màu nút thư mặc định = màu thương hiệu website (`site.brand_color`); chỉ ghi `email.brand_color`
  khi admin bật "Dùng màu khác cho email". Màu chữ trên nút tính theo tương phản, không lưu.

### 1. Ngôn ngữ theo người dùng
- Thêm cột `users.locale` (nullable, lọc qua `Locales::codes()`).
- `User implements HasLocalePreference` → `preferredLocale()` trả `users.locale`.
- `SetLocale` đổi thứ tự: `session('locale')` → `users.locale` → `SiteLocale::default()` → `APP_LOCALE`.
- Trang hồ sơ người dùng: chọn ngôn ngữ mặc định; bộ đổi ở topbar chỉ đè trong session.

### 2. Thư thông báo nội bộ
- `SubmitForm::notify()` hiện dùng `Notification::route('mail', $recipients)` → notifiable ẩn danh, không có ngôn ngữ.
  Sửa: địa chỉ nào khớp tài khoản của site thì gửi tới **model User** (tự theo `preferredLocale`);
  địa chỉ còn lại giữ `route()` nhưng phải `->locale($ownerLocale)` với `$ownerLocale` = `users.locale` của chủ site.
- **Bug hiện tại:** `NewLeadNotification` không gọi `->locale()`, chạy trong request storefront
  → thư dịch theo ngôn ngữ **khách đang xem web**. So sánh `EmailOtpNotification` có gọi `->locale(app()->getLocale())`.
- Giữ `replyTo($submission->email)` — đã đúng.

### 3. Mẫu thư — trạng thái backend
Prototype hiển thị 12 mẫu; thực tế repo chỉ có 3 notification.
- Có sẵn: `EmailOtpNotification`, `ResetPasswordLinkNotification` (thư cho người quản trị), `NewLeadNotification` (nội bộ, dựng từ file dịch).
- **Chưa có backend (9):** Chào mừng, Xác minh địa chỉ email, Mật khẩu vừa được đổi, Xác nhận đơn hàng,
  Đã nhận thanh toán, Cập nhật vận chuyển, Đã giao thành công, Đơn hàng bị huỷ, Có đơn hàng mới.
- Mẫu `internal` (`ntf_contact`, `ntf_order`) là **thư hệ thống**: nội dung từ file dịch, admin không sửa,
  không dịch theo `siteLangs`, loại khỏi mọi phép đếm dịch.

### 4. OAuth email (Google / Microsoft)
- App OAuth do nền tảng sở hữu (multi-tenant), khách không tạo app.
- Route `GET /oauth/{provider}/redirect` + `/callback` (Socialite); scope `gmail.send` / `Mail.Send offline_access`.
- Lưu `refresh_token` mã hoá theo site, tự làm mới, tự gửi thư kiểm tra sau khi kết nối.
- Google yêu cầu xác minh ứng dụng trước khi mở `gmail.send` ra ngoài danh sách thử nghiệm.
