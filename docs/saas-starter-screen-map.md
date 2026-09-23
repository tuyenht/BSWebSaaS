# SaaS Starter — sổ đăng ký màn hình (thước đo "đủ 100%")

> Cập nhật mỗi khi nhận handoff hoặc build xong một màn. Nguồn đối chiếu ban đầu: audit 23/09/2026
> (export Claude Design 18/09 ↔ repo `13281cb`). Brief thi công: `design-briefs/saas-starter-design-first/`.
> Khu đăng nhập (login · quên/đặt lại mật khẩu · 2 lớp · mã email) **giữ nguyên** — không nằm trong đếm.

Ký hiệu: ✅ đủ · 🟡 một phần · 🟠 có nhưng lệch repo/quyết định · 🔴 sơ sài · ❌ chưa có · ⏸ ngoài core v1

**Tổng (23/09):** ≈60 màn — thiết kế đủ 28 (8 lệch) · một phần 7 · chưa có 25.

| # | Màn | Thiết kế | Handoff repo | Code | Lượt | Ghi chú |
|---|---|---|---|---|---|---|
| 1 | Admin Shell mặt site | 🟠 menu cũ, 8/13 mục trống | — | ✅ | D0-4, D0-5 | thiếu Hộp duyệt, tab Quy trình duyệt, Sản phẩm trong Cài đặt |
| 2 | Mặt Nền tảng chung Shell | 🟡 | — | 🟠 layout top-nav riêng | D0-4 | không có nút chuyển mặt trong phiên |
| 3 | Tìm kiếm toàn cục | 🟡 | — | ❌ ô chết | D0-4 | |
| 4 | Tổng quan theo vai trò | 🟡 | — | 🔴 stub | D2-5 | |
| 5 | Tổng quan nền tảng | ❌ | — | ❌ | D4-1 | |
| 6 | Tài khoản của tôi | 🟡 modal | — | 🟡 Cài đặt›Bảo mật | D2-3 | quyết định: trang riêng từ menu người dùng |
| 7 | Thông báo | 🟡 chuông | — | ❌ | D2-4 | quyết định: bảng riêng đúng D-rules |
| 8 | Danh sách Trang | 🟠 cờ giả `canPublish` | `design_handoff_pages_admin/` | ✅ | D1-3 | |
| 9 | Danh sách Bài viết/Dịch vụ/Dự án | ✅ | — | ✅ | D1-3 | thiếu lọc chuyên mục |
| 10–11 | Trình soạn Trang / Bài viết | ✅ (TODO đợt 4 còn mở) | — | 🔴 chỉ tiêu đề/đường dẫn/chuyên mục | D1-4a, D1-4b | khoảng cách build lớn nhất |
| 12 | Trình soạn chữ | ✅ | — | ✅ Jodit | D1-4a | |
| 13 | Thanh ngôn ngữ nội dung | ✅ spec | — | 🟡 | D1-4b | |
| 14 | Lịch sử phiên bản | 🟡 | — | ❌ chưa có bảng | D1-4b | `entry_revisions` typed (Phụ lục A) |
| 15 | Chuyên mục | ❌ | — | ❌ trang "sắp có" | D1-1 | cột ltree `path` chưa được ghi |
| 16 | Trình đơn | 🟠 thiếu gán theo ngôn ngữ | — | ❌ trang "sắp có" | D1-2 | quyết định: cho trỏ Sản phẩm |
| 17 | Page builder | ✅ v3 | — | 🟡 | ⏸ track Builder v3 | |
| 18–19 | Hộp duyệt · Khối trạng thái duyệt | ✅ | `design_handoff_workflow/` | ✅ prod | — | |
| 20–21 | Sản phẩm: danh sách · trình sửa | ❌ | — | ✅ · 🟡 | D1-5 | |
| 22 | Thư viện ảnh & tệp (trang) | 🟡 chỉ modal | — | ✅ | D1-6 | ALT mới 1 ngôn ngữ |
| 23 | Trình chọn ảnh | ✅ | `design_handoff_media_picker/` | ✅ P1/P2 | D1-6 | P3/P4 còn |
| 24–25 | Hộp thư liên hệ · chi tiết | ❌ | — | 🟡 | D1-7 | |
| 26 | Trình dựng biểu mẫu | ❌ | — | ❌ | D1-7 | |
| 27–30 | Thành viên · Nhóm quyền · Sửa nhóm · "Nhóm làm được gì" | ❌ | — | 🟡 · ❌ · ❌ · ❌ | D2-1 | backend nhóm tuỳ chỉnh có (`e922240`) |
| 31 | Lời mời thành viên | ❌ | — | ❌ | D2-2 | quyết định: bảng lời mời, trả lời đồng nhất |
| 32 | Khung Cài đặt (1 thanh Lưu) | ✅ | — | 🟡 | D0-4, D3 | |
| 33 | Thông tin chung | 🟠 đa ngữ vs dữ liệu 1 ngữ | — | ❌ | D3-1 | nguồn = `site_profiles` (§14) |
| 34 | Giao diện website | 🟠 6 màu tự do | — | ❌ | D3-1 | đúng 5 quyết định (section-style-system §1) |
| 35 | Giao diện trang quản trị | ✅ | — | ✅ | — | |
| 36 | Ngôn ngữ website | 🟠 3 tầng | — | ❌ | D3-1 | quyết định: `is_public` + `locales.max` |
| 37 | Đường dẫn | ❌ | — | ❌ (engine có) | D3-1 | 5 kiểu đã chốt 09/07 |
| 38 | SEO & Chia sẻ | ✅ | — | ❌ | D3-2 | |
| 39 | Chuyển hướng | ❌ | — | ❌ (bảng có) | D3-2 | |
| 40 | Tích hợp & Theo dõi | 🟠 2 bản trùng | — | ❌ | D0-1, D3-2 | ADR-21/22 Draft |
| 41 | Nút liên hệ nổi | ✅ | — | chưa xác minh | D3-2 | |
| 42 | Email | ✅ | — | 🟡 | D3-2 | 12 mẫu thiết kế / 3 thư có |
| 43 | Đăng nhập nhanh | ✅ | — | ✅ | — | |
| 44 | Tên miền | 🟠 form chung | — | ❌ | D3-2 | |
| 45 | Cài đặt Sản phẩm | ❌ | — | ✅ | D3-1 | |
| 46 | Quy trình duyệt (vận hành viên) | ✅ | `design_handoff_workflow/` | ✅ | D0-5 | |
| 47 | Gói & mức dùng (phía khách) | ❌ | — | ❌ | D3-2 | |
| 48 | Bảo mật & Nâng cao | 🟠 mã `<head>` | — | ❌ | D3-2 | |
| 49 | Dữ liệu & Phục hồi | ✅ | — | ❌ | ⏸ | |
| 50–51 | Nền tảng: Khách hàng · Website (+ tạo) | ❌ | — | 🟡 chỉ-đọc | D4-1 | |
| 52 | Gói & tính năng | 🟠 ≠ schema | — | ❌ | D4-2 | |
| 53 | Loại website · Giao diện | ❌ | — | ❌ | D4-2 | |
| 54 | Nhân sự vận hành | ❌ | — | ❌ | D4-3 | |
| 55 | Nhật ký hệ thống | ❌ | — | 🟡 | D4-3 | |
| 56 | Đăng nhập hỗ trợ | ❌ | — | ❌ | D4-3 | |
| 57 | Cài đặt nền tảng | ✅ | — | 🟡 | D4-3 | |
| 58–59 | Trang lỗi quản trị · website | ❌ | — | 🟡 404 đã white-label (PR #67) | D5-1 | các mã lỗi khác chưa có |
| 60 | Kết quả tìm kiếm | ❌ | — | ❌ | D5-1 | |
| — | Khu đăng nhập | giữ nguyên | — | ✅ + dòng báo thành công (PR #68) | — | màn mới cùng khu: dựng theo khung login |
| ⏸ | Bộ AI · mặt TMĐT · Khối tái dùng | ✅/🟡 | — | ❌ | — | ngoài core v1 |
