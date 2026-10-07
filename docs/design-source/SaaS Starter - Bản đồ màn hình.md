# SaaS Starter — Bản đồ màn hình (phía thiết kế)

Gốc: `docs/saas-starter-screen-map.md` trên GitHub `tuyenht/BSWebSaaS@main` (đọc 30/09/2026). Cột **# · Màn · Lượt**
chép nguyên từ bản repo — Claude Code giữ bản repo làm gốc, sửa số thứ tự ở đó trước. Ba cột sau do project thiết kế giữ.

Trạng thái: **ready** = thiết kế xong, dùng được để build · **chờ thiết kế** = chưa có hoặc còn lệch repo/quyết định ·
**để sau** = ngoài core v1 · **giữ nguyên** = không thiết kế lại.

**Registry màn của Admin Shell (D0-4b, 06/10/2026):** `shell-screens.js` (`window.BSSCREENS`) — một bảng khóa màn → tiêu đề · mô tả · breadcrumb · cách hiện · file · trạng thái · mã lượt · Trợ giúp. Cột Trạng thái dưới đây khớp trường `status` của registry (trường `mapRow` của từng khóa trỏ về số dòng ở đây). Chuyển màn chỉ bằng `bs-goto {screen, params}`; hash prototype `#/<mặt>/<màn>[/<mục>]`. **Lượt kế tiếp: D0-5.**

**Trang chuẩn (không phải màn trong menu):** `Chuẩn giao diện SaaS Starter.dc.html` — D0-2 phần Component, ready (04/10/2026); trong registry là khóa `ui-standard` (mở từ ô tìm / Ctrl+K, không vào menu).
Mọi màn từ D0-3 trở đi chỉ dùng component ở đây; bảng tra component → `<x-bs-admin::…>` ở cuối trang + `README - D0-2 Chuẩn giao diện.md`.
D0-3 ready (05/10/2026): phần 1 `#thuat-ngu` · `#dinh-dang` · `#giong-van` · `#tieu-chi` · `#nguoi-dung-mau` + trạng thái màn bổ sung ở `#phan-hoi`; phần 2 `#mau-trang` (`#mau-1` … `#mau-8`: Danh sách · Chi tiết · Soạn/sửa · Mục cài đặt · Hộp hai khung · Hướng dẫn nhiều bước · Bảng điều khiển · Trang lỗi/tiện ích) — `README - D0-3 Chuẩn giao diện.md`. Màn mới chọn một mẫu trang, chỉ thêm phần riêng.

| # | Màn | Lượt | Mục menu | File thiết kế | Trạng thái |
|---|---|---|---|---|---|
| 1 | Admin Shell mặt site | D0-4a, D0-4b, D0-5 | (khung) | `Admin Shell.dc.html` | ready (khung, D0-4b 06/10) — menu ẩn theo nhóm quyền (Tweak, BSCW) + khóa theo gói/module (BSAI); registry `shell-screens.js`; `bs-goto` + hash `#/site/…`; khe thanh đăng nhập hỗ trợ. Kế tiếp: D0-5 |
| 2 | Mặt Nền tảng chung Shell | D0-4a | (khung, mặt Nền tảng — tên miền riêng, không nút chuyển mặt) | `Admin Shell.dc.html` (plane `platform`) | ready (khung, D0-4b — menu theo nhóm quyền nền tảng super_admin · support · billing) |
| 3 | Tìm kiếm toàn cục | D0-4a | ô tìm trên thanh trên cùng (Ctrl/⌘+K; dưới 640px kính lúp → tìm toàn màn) | `Admin Shell.dc.html` | ready (ô tìm + kết quả nhanh: màn trong registry + nội dung mẫu, đã lọc quyền; Enter → `search`); trang kết quả: #60 |
| 4 | Tổng quan theo quyền | D2-5 | Tổng quan (khóa `dashboard`, mount, màn tự vẽ h1 — ownHeader) | `Tổng quan.dc.html` (khối hiện theo `BSCW.can`, không theo tên nhóm; số qua `BSUI.fmtCompact`) | ready (D2-5, 07/10 — số liệu Chờ backend) |
| 5 | Tổng quan nền tảng | D4-1 | Nền tảng › Tổng quan | `Admin Shell.dc.html` (mục `p-dashboard`, khung trống) | chờ thiết kế |
| 6 | Tài khoản của tôi | D2-3 | menu người dùng › Tài khoản của tôi (cả 2 mặt; kèm Bảo mật cá nhân — chuyển từ Cài đặt › Bảo mật của repo) | `Tài khoản của tôi.dc.html` (khóa `account`, kind mount, embed-id `account`; hộp Tài khoản cũ của Shell đã gỡ) | ready (D2-3 07/10) — 5 mục Hồ sơ · Bảo mật · Phiên đăng nhập · Tài khoản liên kết · Tùy chọn; phần chờ backend ghi ở `README - D2-3 Tài khoản của tôi.md` |
| 7 | Thông báo | D2-4 | chuông › Thông báo ("Xem tất cả thông báo" → khóa `notifications`, mount) · Tài khoản của tôi › Tùy chọn › Thông báo | `Thông báo.dc.html` + chuông trong `Admin Shell.dc.html` + mục Thông báo của `Tài khoản của tôi.dc.html`; nguồn chung `BSCW.notices` (`content-workflow.js`) | ready — D2-4: 11 loại repo + 2 Chờ backend, tab theo nhóm, ô tìm, "Tải thêm", bảng loại × kênh |
| 8 | Danh sách Trang | D1-3 | Trang › Danh sách | `Quản lý Bài viết - Options.dc.html` (`content-type="pages"`: cây cha – con, cột Mẫu trang). `Quản lý Trang - Options.dc.html` chỉ để đối chiếu | ready — D1-3: gộp vào một khuôn, khóa `pages.list` |
| 9 | Danh sách Bài viết/Dịch vụ/Dự án | D1-3 | [loại nội dung] › Danh sách | `Quản lý Bài viết - Options.dc.html` | ready — D1-3: quyền theo từng dòng (BSCW), bước duyệt, lượt xem, chọn cột, Thùng rác + Hoàn tác, thẻ gọn dưới 768px; khóa `<loại>.list` |
| 10–11 | Khuôn soạn nội dung (Trang · Tin tức · Dịch vụ · Dự án …) | D1-4a → D1-4b → D1-4c → D1-4d | [loại nội dung] › Thêm mới · Sửa (mở từ dòng) | MỘT khuôn `Page Editor - Options.dc.html` (prop `kind` = khóa loại) + ô soạn thân bài `Trình soạn nội dung.dc.html`; `Quản lý Bài viết - Options` không còn phần Thêm/Sửa | ready — D1-4b 07/10: khóa `<loại>.create` · `<loại>.edit?ma=<mã công khai>` (không id số); mặc định gọn + "Thêm tùy chọn", mẫu trang / Viết mới, Xem trước (Chờ backend), Thẻ (Chờ backend). D1-4c: lịch sử phiên bản + SEO còn lại. D1-4d 07/10: thay đổi chờ của bài đang công khai (entry_drafts — TẮT duyệt: Cập nhật / Lưu thay đổi; BẬT duyệt: Gửi duyệt thay đổi, khóa tới khi trả lại), hộp rời trang 3 nút, bản tạm trên máy cho bài công khai, dải "đang sửa" + hộp "Bài vừa được … lưu". Kế tiếp: D1-5 |
| 12 | Trình soạn chữ | D1-4a | (trong trình soạn) | `Trình soạn nội dung.dc.html` | ready — D1-4a 07/10: 20 nhóm nút rà 1-1, thanh gọn 7 nhóm, điện thoại 1 hàng cuộn + ngăn đáy, popup Viết lại neo dưới đoạn, Focus 1b |
| 13 | Thanh ngôn ngữ nội dung | D1-4b | (trong trình soạn và Cài đặt) | `Language Context - Design Spec.dc.html` · bản trong khuôn: `Page Editor - Options.dc.html` | ready — D1-4b: ô tìm ngôn ngữ dùng `data-focus-wrap`, ô chọn co chữ ở 360px, việc dịch đang chạy hiện dòng ai-job (Hủy = không trừ từ AI); ô của bản العربية tự `dir="rtl"` (tiêu đề, mô tả, Google, mô tả ảnh) |
| 14 | Lịch sử phiên bản | D1-4c | (trong trình soạn) | `Page Editor - Options.dc.html` (thẻ Đăng › "Lịch sử phiên bản") | ready — D1-4c 07/10: theo ngôn ngữ đang sửa, 3 bản gần nhất + "Xem tất cả" (Tải thêm, điện thoại toàn màn), xem 1 bản tô khác biệt, hộp Khôi phục (trượt đáy ở điện thoại; câu ảnh/mẫu dùng chung), 4 lỗi `lang/vi.json:759–762`, ảnh đã xóa bỏ trống; Chỉ xem không có Khôi phục. Backend có sẵn (prod 28/09). Kế tiếp: D1-4d |
| 15 | Chuyên mục | D1-1 | [loại nội dung] › Chuyên mục · Sản phẩm › Chuyên mục | `Chuyên mục.dc.html` (một file cho mọi loại + Sản phẩm `product_cat`) | ready (D1-1, 06/10) — registry `<loại>.cats` / `catalog.cats` (khóa repo `<loại>.taxonomy` / `catalog.taxonomy`); kind `mount` — Shell mount `Chuyên mục.dc.html` (embedded, embed-id `taxonomy`); README `README - D1-1 Chuyên mục.md` |
| 16 | Trình đơn | D1-2 | Trình đơn | `Trình đơn.dc.html` | ready (D1-2 07/10) — gán theo ngôn ngữ (chung · riêng · chưa gán), chip nguồn theo loại, token tối; phần backend chưa có ghi ở `README - D1-2 Trình đơn.md` |
| 17 | Page builder | ⏸ track Builder v3 | — (Khối tái dùng: khóa `blocks`, Trình đơn mở tới) | `Page Builder v3.dc.html` | để sau (track riêng, không sửa) |
| 18–19 | Hộp duyệt · Khối trạng thái duyệt | — | Hộp duyệt | `Hộp duyệt.dc.html`, `Trạng thái duyệt (khối soạn thảo).dc.html` | ready — nối vào Shell (D0-5: hàng chờ + số trên menu đọc kho BSCW.store). D1-4d: thẻ "Thay đổi của bài đang đăng" (BSCW.drafts) + So sánh · Duyệt đăng · Trả lại kèm ghi chú — Chờ backend (Hộp duyệt repo chưa liệt kê thay đổi) |
| 20–21 | Sản phẩm: danh sách · trình sửa | D1-5 | Sản phẩm › Danh sách · Thêm mới (khóa `catalog.list` · `catalog.create` · `catalog.edit?ma=<ulid>`) | `Sản phẩm - Danh sách.dc.html` (mount, `embedId: 'catalog-list'`) · `Sản phẩm - Trình sửa.dc.html` (mount, `embedId: 'catalog'`) | ready (D1-5 07/10) — Tweak "Chế độ sản phẩm" Giới thiệu/Bán hàng, Thùng rác + Hoàn tác, thẻ gọn < 768, "Chỉ được xem"; phần backend ở `README - D1-5 Sản phẩm.md` |
| 22 | Thư viện ảnh & tệp (trang) | D1-6 | Thư viện ảnh & tệp | `Thư viện ảnh & tệp.dc.html` (mount, `embedId: 'media'`; nút "Tải ảnh lên" mở `MediaPicker` tab Tải lên) | ready |
| 23 | Trình chọn ảnh | D1-6 | (hộp thoại dùng chung) | `MediaPicker.dc.html`, `Image Picker.dc.html` | ready |
| 24–25 | Hộp thư liên hệ · chi tiết | D1-7 | Khách liên hệ (khóa repo `contacts`; mở một liên hệ `?ma=<ulid>`, lọc theo biểu mẫu `?form=<ulid>`) | `Khách liên hệ.dc.html` (mount, `embedId: 'contacts'`, mẫu 5 Hộp hai khung) | ready (D1-7 07/10) — hai khung từ 940px khung chứa, dưới 940 chi tiết toàn màn + "Quay lại" + nút Gọi dính đáy; Gọi · Zalo · Email một chạm; đồng ý của khách chỉ đọc; Tải danh sách / thời hạn lưu giữ / Xóa theo yêu cầu (Chờ backend) — `README - D1-7 Khách liên hệ & Biểu mẫu.md` |
| 26 | Trình dựng biểu mẫu | D1-7 | Biểu mẫu (khóa đề xuất `forms` — repo chưa có mục menu, Chờ backend) | `Biểu mẫu.dc.html` (mount, `embedId: 'forms'`; danh sách + trình sửa `?ma=<ulid>`) | ready (D1-7 07/10) — 6 loại ô, kéo-thả + Lên / Xuống, ô đồng ý khóa ở cuối, theo thanh ngôn ngữ, xóa nói số liên hệ bị xóa theo; "Liên hệ" của hệ thống không có Xóa |
| 27–30 | Thành viên · Nhóm quyền · Sửa nhóm · "Nhóm làm được gì" | D2-1 | Thành viên (khóa `users`; lọc nhóm `?nhom=<tên>`) · Nhóm quyền (khóa `roles`; mở nhóm `?nhom=<tên>&che=sua\|xem\|moi`) | `Thành viên.dc.html` (mount, `embedId: 'users'`) · `Nhóm quyền.dc.html` (mount, `embedId: 'roles'`; Màn 1 danh sách · Màn 2 tạo/sửa · Màn 3 "làm được gì" — cũng mở trong Thành viên bằng prop `explain`) | ready (D2-1 07/10) |
| 31 | Lời mời thành viên | D2-2 | Thành viên › Lời mời (khóa `users`, mở tab bằng `?tab=loi-moi`) | `Thành viên.dc.html` (hộp "Mời thành viên" + tab "Lời mời"; cùng mount `embedId: 'users'`) | ready (D2-2 07/10) — dán nhiều email (mỗi địa chỉ một lời mời, báo kết quả từng địa chỉ), chọn nhóm (chỉ nhóm mình gán được), lời nhắn ≤ 500, hạn 7 ngày; tab Đang chờ · Hết hạn · Đã hủy · Đã nhận, "‹ Trước · Sau ›"; Gửi lại (khóa 60 giây, liên kết cũ hết hiệu lực) · Hủy lời mời (hộp xác nhận); thư mời + trang nhận lời mời không vẽ (khu đăng nhập) — `README - D2-2 Lời mời thành viên.md` |
| 32 | Khung Cài đặt (1 thanh Lưu dính đáy) | D0-4a, D3 | Cài đặt | `Admin Shell.dc.html` | ready — thanh Lưu "N thay đổi chưa lưu · N lỗi · Xem lỗi đầu tiên · Bỏ thay đổi · Lưu"; dưới 768px danh sách trang + "‹ Cài đặt"; nhóm Nâng cao gập |
| 33 | Thông tin chung | D3-1 | Cài đặt › Chung › Thông tin chung | `Admin Shell.dc.html` (form chung) | chờ thiết kế — lệch đa ngữ/1 ngữ |
| 34 | Giao diện website | D3-1 | Cài đặt › Chung › Giao diện website | `Admin Shell.dc.html` (mục `appearance`) | chờ thiết kế — 6 màu tự do ≠ 5 quyết định |
| 35 | Giao diện trang quản trị | — | Cài đặt › Chung › Giao diện trang quản trị (mặc định website) · menu người dùng › Giao diện (lựa chọn riêng, "Về mặc định") | `Admin Shell.dc.html` (mục `admin-ui` + bảng Giao diện) | ready (D0-4a) — không có ô màu; màu ở Cài đặt nền tảng › Thương hiệu trắng |
| 36 | Ngôn ngữ website | D3-1 | Cài đặt › Chung › Ngôn ngữ website | `Admin Shell.dc.html` | chờ thiết kế — 3 tầng |
| 37 | Đường dẫn | D3-1 | Cài đặt › Nội dung › Đường dẫn | `Admin Shell.dc.html` (mục `permalinks`, khung) | chờ thiết kế |
| 38 | SEO & Chia sẻ | D3-2 | Cài đặt › Tiếp thị & Kết nối › SEO & Chia sẻ | `Admin Shell.dc.html` (mục `seo`) + `content-seo.js` | ready |
| 39 | Chuyển hướng | D3-2 | Cài đặt › Tiếp thị & Kết nối › Chuyển hướng | `Admin Shell.dc.html` (mục `redirects`, khung) | chờ thiết kế |
| 40 | Tích hợp & Theo dõi | D0-1, D3-2 | Cài đặt › Tiếp thị & Kết nối › Tích hợp & Theo dõi | `Integrations Panel.dc.html` | ready — hết bản trùng (D0-1 xoá `Website Config - Integrations`) |
| 41 | Nút liên hệ nổi | D3-2 | Cài đặt › Tiếp thị & Kết nối › Nút liên hệ nổi | `Floating Contact Panel.dc.html` | ready |
| 42 | Email | D3-2 | Cài đặt › Hệ thống › Email | `Email Settings.dc.html` | ready |
| 43 | Đăng nhập nhanh | — | Cài đặt › Hệ thống › Đăng nhập nhanh | `Đăng nhập nhanh & Liên kết tài khoản.dc.html` | ready |
| 44 | Tên miền | D3-2 | Cài đặt › Hệ thống › Tên miền (quyền `domains.manage`) | `Admin Shell.dc.html` (mục `domain`) | chờ thiết kế |
| 45 | Cài đặt Sản phẩm | D3-1 | Cài đặt › Nội dung › Sản phẩm | `Admin Shell.dc.html` (mục `catalog`, khung) | chờ thiết kế |
| 46 | Quy trình duyệt (vận hành viên) | D0-5, D4-1 | Nền tảng › Website › Quy trình duyệt (khóa `p-workflow`, mở từ thẻ Website bằng "Xem Quy trình duyệt (bản hiện có)"; D4-1 đặt vào tab của trang chi tiết website) | `Quy trình duyệt.dc.html` | ready — không còn trong Cài đặt website (owner 30/09) |
| 47 | Gói & mức dùng (phía khách) | D3-2 | Cài đặt › Hệ thống › Gói & mức dùng | `Hạn ngạch AI và mua thêm.dc.html` (chỉ phần AI) | chờ thiết kế |
| 48 | Bảo mật & Nâng cao | D3-2 | Cài đặt › Nâng cao › Bảo mật & Nâng cao (nhóm gập) | `Admin Shell.dc.html` (mục `advanced`) | chờ thiết kế |
| 49 | Dữ liệu & Phục hồi | D3-2 | Cài đặt › Nâng cao › Dữ liệu & Phục hồi | `Admin Shell.dc.html` | ready — owner chốt giữ hay hoãn build |
| 50–51 | Nền tảng: Khách hàng · Website (+ tạo) | D4-1 | Nền tảng › Khách hàng · Website (+ Tạo website) | — | chờ thiết kế |
| 52 | Gói & tính năng | D4-2 | Nền tảng › Gói & tính năng (rời Cài đặt nền tảng) | `Admin Shell.dc.html` (mục `p-plans`, tạm hiện phần đã vẽ) | chờ thiết kế — ≠ schema |
| 53 | Loại website · Giao diện | D4-2 | Nền tảng › Loại website | — | chờ thiết kế |
| 54 | Nhân sự vận hành | D4-3 | Nền tảng › Nhân sự vận hành | — | chờ thiết kế |
| 55 | Nhật ký hệ thống | D4-3 | Nền tảng › Nhật ký hệ thống | — | chờ thiết kế |
| 56 | Đăng nhập hỗ trợ | D4-3 | Nền tảng › Đăng nhập hỗ trợ · Đăng nhập dưới tên khách hàng (khóa `impersonate`: hộp xác nhận + thanh đầu trang — Shell đã có khe thanh) | — | chờ thiết kế |
| 57 | Cài đặt nền tảng | D4-3 | Nền tảng › Cài đặt nền tảng (Thông tin · Thương hiệu trắng · Email hệ thống · Mẫu email · Đăng nhập nhanh · Bảo mật & Nhật ký) | `Admin Shell.dc.html` (`p-general` · `p-branding` · `p-mail` · `p-mail-tpl` · `p-social` · `p-security`), `Email Settings.dc.html` (platform), `Đăng nhập nhanh & Liên kết tài khoản.dc.html` (saas) | ready |
| 58–59 | Trang lỗi quản trị · website | D5-1 | Trang lỗi | — | chờ thiết kế |
| 60 | Kết quả tìm kiếm | D5-1 | Kết quả tìm kiếm | — | chờ thiết kế |
| 61 | Nhật ký hoạt động của website | D4-3 | Hệ thống › Nhật ký hoạt động (quyền đề xuất `activity.view`) | `Admin Shell.dc.html` (mục `activity`, khung) | chờ thiết kế — Chờ backend |
| — | Khu đăng nhập | — | đăng nhập · quên/đặt lại mật khẩu · xác thực 2 lớp · mã qua email | — | giữ nguyên |
| ⏸ | Bộ AI · mặt TMĐT · Khối tái dùng | — | Bộ AI · Đơn hàng · Khách hàng · Khuyến mãi · Kho · Báo cáo · Thanh toán · Vận chuyển · Thuế · Khối tái dùng | Bộ AI: `AI Writer Panel v2`, `Trợ lý viết nội dung AI v2 - Options`, `Nâng cấp nội dung`, `Hàng đợi ảnh`, `Kế hoạch nội dung SEO`, `Vận hành nội dung AI`, `Duyệt & xuất bản nội dung`, `Cài đặt nội dung AI`, `Cài đặt xuất bản tự động`, `Hạn ngạch AI và mua thêm` · Khối tái dùng: `Block Library - Options` · TMĐT: `Admin Shell.dc.html` (plane `shop`, chỉ menu) | để sau |

## Mục menu chưa có dòng trong bản repo (đối chiếu danh sách owner 30/09)
Không đánh số — Claude Code thêm số ở bản repo trước, rồi chép về đây.

| Màn | Mục menu | File thiết kế | Trạng thái |
|---|---|---|---|
| Trợ giúp | thanh trên cùng › Trợ giúp (cùng một chỗ trên mọi màn — P4) | `Admin Shell.dc.html` (panel Trợ giúp, `<x-bs-admin::help-panel>`) | ready (D0-4b) — 3 câu hỏi theo màn đọc từ trường `help` của registry |

## Tài liệu đối chiếu (không nằm trong menu)
| File | Vai trò |
|---|---|
| `Cài đặt - Đề xuất giao diện.dc.html` | chuẩn đối chiếu cho Cài đặt (CLAUDE.md §1) — giữ nguyên, không sửa |
| `Language Context - Design Spec.dc.html` | tham chiếu gốc ngữ cảnh ngôn ngữ (CLAUDE.md §1) |
| `_archive/Page Builder v2.dc.html`, `_archive/Page Builder - Options.dc.html` | bản khám phá cũ, đã lưu trữ (D0-1) |

## Nguồn dùng chung
`ai-quota.js` (BSAI — gói, hạn ngạch, chi phí) · `platform-config.js` (BSPLAT — thương hiệu nền tảng) ·
`content-workflow.js` (BSCW; D0-5 thêm `BSCW.store` — kho bài mẫu dùng chung, sự kiện `bs-cw-store`) · `admin-components.css` (D0-5 — CSS component của hai trang chuẩn; màn chưa link) · `content-ui.js` (BSUI) · `content-seo.js` (BSSEO) · `block-library.js`.
