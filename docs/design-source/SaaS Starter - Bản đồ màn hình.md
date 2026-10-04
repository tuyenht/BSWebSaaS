# SaaS Starter — Bản đồ màn hình (phía thiết kế)

Gốc: `docs/saas-starter-screen-map.md` trên GitHub `tuyenht/BSWebSaaS@main` (đọc 30/09/2026). Cột **# · Màn · Lượt**
chép nguyên từ bản repo — Claude Code giữ bản repo làm gốc, sửa số thứ tự ở đó trước. Ba cột sau do project thiết kế giữ.

Trạng thái: **ready** = thiết kế xong, dùng được để build · **chờ thiết kế** = chưa có hoặc còn lệch repo/quyết định ·
**để sau** = ngoài core v1 · **giữ nguyên** = không thiết kế lại.

**Trang chuẩn (không phải màn trong menu):** `Chuẩn giao diện SaaS Starter.dc.html` — D0-2 phần Component, ready (04/10/2026).
Mọi màn từ D0-3 trở đi chỉ dùng component ở đây; bảng tra component → `<x-admin::…>` ở cuối trang + `README - D0-2 Chuẩn giao diện.md`.
Phần 2 (Bảng thuật ngữ, quy ước số rút gọn, mẫu bảng điều khiển) = D0-3.

| # | Màn | Lượt | Mục menu | File thiết kế | Trạng thái |
|---|---|---|---|---|---|
| 1 | Admin Shell mặt site | D0-4a, D0-4b, D0-5 | (khung) | `Admin Shell.dc.html` | chờ thiết kế — menu cũ, thiếu Hộp duyệt · Quy trình duyệt · Sản phẩm trong Cài đặt |
| 2 | Mặt Nền tảng chung Shell | D0-4a | (khung, mặt Nền tảng) | `Admin Shell.dc.html` (plane `platform`) | chờ thiết kế |
| 3 | Tìm kiếm toàn cục | D0-4a | ô tìm trên thanh trên cùng | `Admin Shell.dc.html` | chờ thiết kế |
| 4 | Tổng quan theo vai trò | D2-5 | Tổng quan | `Admin Shell.dc.html` (bảng tin mẫu) | chờ thiết kế |
| 5 | Tổng quan nền tảng | D4-1 | Nền tảng › Tổng quan | — | chờ thiết kế |
| 6 | Tài khoản của tôi | D2-3 | menu người dùng › Tài khoản của tôi | `Admin Shell.dc.html` (hộp "Tài khoản của tôi") | chờ thiết kế — quyết định: trang riêng |
| 7 | Thông báo | D2-4 | chuông › Thông báo | `Admin Shell.dc.html` (danh sách chuông) | chờ thiết kế |
| 8 | Danh sách Trang | D1-3 | Trang › Danh sách | `Quản lý Trang - Options.dc.html` | ready (khuôn danh sách dùng chung) |
| 9 | Danh sách Bài viết/Dịch vụ/Dự án | D1-3 | [loại nội dung] › Danh sách | `Quản lý Bài viết - Options.dc.html` | ready (khuôn danh sách dùng chung) |
| 10–11 | Trình soạn Trang / Bài viết | D1-4a → D1-4d | [loại nội dung] › Thêm mới | `Page Editor - Options.dc.html`, `Trình soạn nội dung.dc.html` | ready (TODO đợt 4 còn mở) |
| 12 | Trình soạn chữ | D1-4a | (trong trình soạn) | `Trình soạn nội dung.dc.html` | ready |
| 13 | Thanh ngôn ngữ nội dung | D1-4b | (trong trình soạn và Cài đặt) | `Language Context - Design Spec.dc.html` | ready |
| 14 | Lịch sử phiên bản | D1-4c | (trong trình soạn) | `Page Editor - Options.dc.html` (một phần) | chờ thiết kế |
| 15 | Chuyên mục | D1-1 | [loại nội dung] › Chuyên mục · Sản phẩm › Chuyên mục | — | chờ thiết kế |
| 16 | Trình đơn | D1-2 | Trình đơn | `Trình đơn.dc.html` | ready — còn thiếu gán theo ngôn ngữ |
| 17 | Page builder | ⏸ track Builder v3 | — | `Page Builder v3.dc.html` | để sau (track riêng, không sửa) |
| 18–19 | Hộp duyệt · Khối trạng thái duyệt | — | Hộp duyệt | `Hộp duyệt.dc.html`, `Trạng thái duyệt (khối soạn thảo).dc.html` | ready |
| 20–21 | Sản phẩm: danh sách · trình sửa | D1-5 | Sản phẩm › Danh sách · Thêm mới | — | chờ thiết kế |
| 22 | Thư viện ảnh & tệp (trang) | D1-6 | Thư viện ảnh & tệp | — (mới có hộp chọn) | chờ thiết kế |
| 23 | Trình chọn ảnh | D1-6 | (hộp thoại dùng chung) | `MediaPicker.dc.html`, `Image Picker.dc.html` | ready |
| 24–25 | Hộp thư liên hệ · chi tiết | D1-7 | Khách liên hệ | — | chờ thiết kế |
| 26 | Trình dựng biểu mẫu | D1-7 | Biểu mẫu | — | chờ thiết kế |
| 27–30 | Thành viên · Nhóm quyền · Sửa nhóm · "Nhóm làm được gì" | D2-1 | Thành viên · Nhóm quyền | — | chờ thiết kế |
| 31 | Lời mời thành viên | D2-2 | Thành viên › Lời mời | — | chờ thiết kế |
| 32 | Khung Cài đặt (1 thanh Lưu) | D0-4a, D3 | Cài đặt | `Admin Shell.dc.html` | ready |
| 33 | Thông tin chung | D3-1 | Cài đặt › Chung › Thông tin chung | `Admin Shell.dc.html` (form chung) | chờ thiết kế — lệch đa ngữ/1 ngữ |
| 34 | Giao diện website | D3-1 | Cài đặt › Chung › Giao diện website | `Admin Shell.dc.html` (mục "Thương hiệu & Giao diện") | chờ thiết kế — 6 màu tự do ≠ 5 quyết định |
| 35 | Giao diện trang quản trị | — | Cài đặt › Chung › Giao diện trang quản trị | `Admin Shell.dc.html` (bộ tuỳ chỉnh giao diện) | ready |
| 36 | Ngôn ngữ website | D3-1 | Cài đặt › Chung › Ngôn ngữ website | `Admin Shell.dc.html` | chờ thiết kế — 3 tầng |
| 37 | Đường dẫn | D3-1 | Cài đặt › Nội dung › Đường dẫn | — | chờ thiết kế |
| 38 | SEO & Chia sẻ | D3-2 | Cài đặt › Tiếp thị & Kết nối › SEO & Chia sẻ | `Admin Shell.dc.html` (mục `seo`) + `content-seo.js` | ready |
| 39 | Chuyển hướng | D3-2 | Cài đặt › Tiếp thị & Kết nối › Chuyển hướng | — | chờ thiết kế |
| 40 | Tích hợp & Theo dõi | D0-1, D3-2 | Cài đặt › Tiếp thị & Kết nối › Tích hợp & Theo dõi | `Integrations Panel.dc.html` | ready — hết bản trùng (D0-1 xoá `Website Config - Integrations`) |
| 41 | Nút liên hệ nổi | D3-2 | Cài đặt › Tiếp thị & Kết nối › Nút liên hệ nổi | `Floating Contact Panel.dc.html` | ready |
| 42 | Email | D3-2 | Cài đặt › Hệ thống › Email | `Email Settings.dc.html` | ready |
| 43 | Đăng nhập nhanh | — | Cài đặt › Hệ thống › Đăng nhập nhanh | `Đăng nhập nhanh & Liên kết tài khoản.dc.html` | ready |
| 44 | Tên miền | D3-2 | Cài đặt › Hệ thống › Tên miền | `Admin Shell.dc.html` (form chung) | chờ thiết kế |
| 45 | Cài đặt Sản phẩm | D3-1 | Cài đặt › Nội dung › Sản phẩm | — | chờ thiết kế |
| 46 | Quy trình duyệt (vận hành viên) | D0-5 | Cài đặt › Nội dung › Quy trình duyệt | `Quy trình duyệt.dc.html` | ready |
| 47 | Gói & mức dùng (phía khách) | D3-2 | Cài đặt › Hệ thống › Gói & mức dùng | `Hạn ngạch AI và mua thêm.dc.html` (chỉ phần AI) | chờ thiết kế |
| 48 | Bảo mật & Nâng cao | D3-2 | Cài đặt › Nâng cao › Bảo mật & Nâng cao | `Admin Shell.dc.html` (form chung) | chờ thiết kế |
| 49 | Dữ liệu & Phục hồi | D3-2 | Cài đặt › Nâng cao › Dữ liệu & Phục hồi | `Admin Shell.dc.html` | ready — owner chốt giữ hay hoãn build |
| 50–51 | Nền tảng: Khách hàng · Website (+ tạo) | D4-1 | Nền tảng › Khách hàng · Website (+ Tạo website) | — | chờ thiết kế |
| 52 | Gói & tính năng | D4-2 | Nền tảng › Gói & tính năng | `Admin Shell.dc.html` (mục `p-plans`) | chờ thiết kế — ≠ schema |
| 53 | Loại website · Giao diện | D4-2 | Nền tảng › Loại website | — | chờ thiết kế |
| 54 | Nhân sự vận hành | D4-3 | Nền tảng › Nhân sự vận hành | — | chờ thiết kế |
| 55 | Nhật ký hệ thống | D4-3 | Nền tảng › Nhật ký hệ thống | — | chờ thiết kế |
| 56 | Đăng nhập hỗ trợ | D4-3 | Nền tảng › Đăng nhập hỗ trợ | — | chờ thiết kế |
| 57 | Cài đặt nền tảng | D4-3 | Nền tảng › Cài đặt nền tảng (Thông tin · Thương hiệu trắng · Email hệ thống · Mẫu email · Đăng nhập nhanh · Bảo mật) | `Admin Shell.dc.html` (`p-general` · `p-branding` · `p-mail` · `p-mail-tpl` · `p-social` · `p-security`), `Email Settings.dc.html` (platform), `Đăng nhập nhanh & Liên kết tài khoản.dc.html` (saas) | ready |
| 58–59 | Trang lỗi quản trị · website | D5-1 | Trang lỗi | — | chờ thiết kế |
| 60 | Kết quả tìm kiếm | D5-1 | Kết quả tìm kiếm | — | chờ thiết kế |
| 61 | Nhật ký hoạt động của website | D4-3 | Nhật ký hoạt động | — | chờ thiết kế |
| — | Khu đăng nhập | — | đăng nhập · quên/đặt lại mật khẩu · xác thực 2 lớp · mã qua email | — | giữ nguyên |
| ⏸ | Bộ AI · mặt TMĐT · Khối tái dùng | — | Bộ AI · Đơn hàng · Khách hàng · Khuyến mãi · Kho · Báo cáo · Thanh toán · Vận chuyển · Thuế · Khối tái dùng | Bộ AI: `AI Writer Panel v2`, `Trợ lý viết nội dung AI v2 - Options`, `Nâng cấp nội dung`, `Hàng đợi ảnh`, `Kế hoạch nội dung SEO`, `Vận hành nội dung AI`, `Duyệt & xuất bản nội dung`, `Cài đặt nội dung AI`, `Cài đặt xuất bản tự động`, `Hạn ngạch AI và mua thêm` · Khối tái dùng: `Block Library - Options` · TMĐT: `Admin Shell.dc.html` (plane `shop`, chỉ menu) | để sau |

## Mục menu chưa có dòng trong bản repo (đối chiếu danh sách owner 30/09)
Không đánh số — Claude Code thêm số ở bản repo trước, rồi chép về đây.

| Màn | Mục menu | File thiết kế | Trạng thái |
|---|---|---|---|
| Trợ giúp | Trợ giúp (cùng một chỗ trên mọi màn — P4) | — | chờ thiết kế |

## Tài liệu đối chiếu (không nằm trong menu)
| File | Vai trò |
|---|---|
| `Cài đặt - Đề xuất giao diện.dc.html` | chuẩn đối chiếu cho Cài đặt (CLAUDE.md §1) — giữ nguyên, không sửa |
| `Language Context - Design Spec.dc.html` | tham chiếu gốc ngữ cảnh ngôn ngữ (CLAUDE.md §1) |
| `_archive/Page Builder v2.dc.html`, `_archive/Page Builder - Options.dc.html` | bản khám phá cũ, đã lưu trữ (D0-1) |

## Nguồn dùng chung
`ai-quota.js` (BSAI — gói, hạn ngạch, chi phí) · `platform-config.js` (BSPLAT — thương hiệu nền tảng) ·
`content-workflow.js` (BSCW) · `content-ui.js` (BSUI) · `content-seo.js` (BSSEO) · `block-library.js`.
