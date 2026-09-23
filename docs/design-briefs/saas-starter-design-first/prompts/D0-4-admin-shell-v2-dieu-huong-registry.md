═══ BỐI CẢNH CHUNG (giữ nguyên ở đầu mọi lượt) ═══
Dự án BSWebSaaS trong Claude Design. Trước khi làm: đọc CLAUDE.md, LESSONS.md, github.md, TODO.md; mở Admin Shell.dc.html và design system `_ds/bssaas-adminkit-design-system`; dùng lại nguồn chung ai-quota.js (BSAI) · content-workflow.js (BSCW) · content-ui.js (BSUI) · content-seo.js (BSSEO), và — khi đã có — `Chuẩn giao diện SaaS Starter.dc.html` (lượt D0-2, D0-3) + registry màn của Admin Shell (lượt D0-4) + `SaaS Starter - Bản đồ màn hình.md` (lượt D0-1).
Sản phẩm: SaaS Starter — bộ khung quản trị đa-tenant BÁN ĐƯỢC cho nhiều loại website/SaaS: white-label, đa ngôn ngữ (tiếng Việt trước), phân quyền động, quy trình duyệt động (mặc định TẮT). Người dùng: từ chủ doanh nghiệp nhỏ lớn tuổi, chỉ biết đọc và dùng chuột/điện thoại, tới nhân viên văn phòng và người quản trị thạo việc.
Nguồn sự thật dữ liệu: GitHub tuyenht/BSWebSaaS@main — mở đúng file repo nêu trong prompt để lấy bảng/cột/quyền/trạng thái thật; KHÔNG bịa. Chỗ nào repo chưa có thì vẫn vẽ, gắn nhãn "Chờ backend" và ghi vào mục "Câu hỏi cho backend" của README.
Luật cứng:
- Token AdminKit: navy #405189 · Inter 14px · bo 4px ô nhập / 6px thẻ · 1 bóng thẻ · nền #f3f3f9. Không thêm màu, gradient, bóng mới.
- Đích build: Livewire 4 + Blade + Tailwind 4 + <x-admin::*>; icon Remix inline-SVG (prototype dùng CDN được, README liệt kê tên icon). Không Bootstrap, không Flux.
- Mỗi màn đủ trạng thái: trống · đang tải · lỗi (có "Thử lại") · không có quyền · khoá theo gói · thành công. Không ngõ cụt.
- 3 bề rộng 1240 / 768 / 390, sáng + tối, WCAG 2.2 AA.
- Không hiện mã số nội bộ (id) — chỉ tên, đường dẫn, mã công khai. Không gắn cứng thương hiệu (tên nền tảng/website/"Phát triển bởi" lấy từ cấu hình).
- Dữ liệu động: vai trò, quyền, loại nội dung, ngôn ngữ, gói, bước duyệt đều đọc từ registry/bảng — chịu được 1–20 mục, tên dài.
- Hành động nguy hiểm: hộp xác nhận giữa màn (mẫu SweetAlert2), nút Huỷ được focus.
TIÊU CHÍ DỄ DÙNG — kiểm từng màn trước khi giao, ghi kết quả (đạt/chưa + lý do) vào README:
 1. Hiểu trong 5 giây: tiêu đề + 1 dòng mô tả nói màn này để làm gì; đúng 1 nút chính nổi bật; người mới biết bấm gì tiếp.
 2. Việc chính của màn xong trong ≤ 3 bước từ menu, không cần đọc hướng dẫn.
 3. Không thuật ngữ kỹ thuật trần (SMTP, OAuth, DNS, CNAME, SSL, API, webhook, token, slug, meta, robots…). Buộc phải có thì để trong khối "Nâng cao" và kèm 1 câu giải thích đời thường. Tên thương hiệu quen (Google, Zalo, Facebook) giữ nguyên nhưng nói rõ công dụng ("Facebook Pixel — đo hiệu quả quảng cáo Facebook").
 4. Icon luôn kèm chữ; chỉ icon rất quen (tìm kiếm, chuông, đóng, thêm) mới được đứng một mình, và phải có tooltip + aria-label.
 5. Ô nhập có gợi ý bằng ví dụ thật; lỗi nói cách sửa ("Số điện thoại cần 10 số, ví dụ 0901 234 567"), không nói lỗi kỹ thuật.
 6. Không mất việc: tự lưu nháp, chặn rời trang khi chưa lưu, xoá = vào Thùng rác kèm toast "Hoàn tác".
 7. Trạng thái luôn có màu + hình + chữ — không bao giờ chỉ dùng màu.
 8. Không cắt chữ: nhãn nút, chip, tab, nút lọc luôn 1 dòng (dài thì "…" + tooltip) — nút KHÔNG bao giờ xuống 2 dòng; nhãn ô nhập được xuống dòng nhưng không bị che. Không thanh cuộn ngang trong thẻ/khung đọc.
 9. Điện thoại: việc hay làm (trả lời khách liên hệ, duyệt bài, đăng tin nhanh, xem thông báo) làm trọn được ở 390px; vùng bấm ≥ 44px; không thành phần nổi nào che nút Lưu hay nội dung.
10. Hai tầng người dùng: mặc định gọn cho người mới (tuỳ chọn nâng cao gập lại); người thạo có phím tắt, thao tác hàng loạt, lọc nhanh — nhưng không làm rối tầng người mới.
11. Chế độ "Chữ lớn" (+2px toàn trang) không làm vỡ bố cục.
12. Dùng đúng từ trong "Bảng thuật ngữ" của Chuẩn giao diện (D0-3): cùng một việc luôn cùng một từ ở mọi màn.
Nhúng & điều hướng: màn tuân hợp đồng nhúng §13 (embedded · onDirty · embedId · bs-embed-cmd), đăng ký vào registry màn của Admin Shell (sau D0-4) với trạng thái "ready", chuyển màn bằng sự kiện bs-goto {screen, params}; cập nhật dòng tương ứng trong `SaaS Starter - Bản đồ màn hình.md`.
Giao nộp: 1 file .dc.html mỗi màn (hoặc gộp có anchor) + README handoff theo mẫu: Tổng quan · File · Mức hoàn thiện · Màn & trạng thái · Tương tác · Ánh xạ dữ liệu (file:dòng repo) · Quyền & gói (ai thấy gì) · Token · Icon · Chuỗi cần dịch · Kết quả 12 tiêu chí dễ dùng · Câu hỏi cho backend.
Không sửa CLAUDE.md (chỉ owner sửa) — bài học mới ghi 1 dòng vào LESSONS.md. Làm xong gọi ready_for_verification và sửa hết lỗi verifier báo. Lượt này làm ĐÚNG MỘT việc bên dưới.
═══ VIỆC CỦA LƯỢT NÀY ═══

# D0-4 · Admin Shell v2 — sơ đồ điều hướng chuẩn + registry màn hình + khung dễ dùng

Mục tiêu: Shell là "bản đồ sống" của toàn sản phẩm — mọi mục menu mở đúng màn, menu và quyền phản ánh đúng repo, người mới nhìn là biết đi đâu.
Hiện trạng: `Admin Shell.dc.html` chỉ vẽ Tổng quan / Cài đặt / Trình đơn, mọi mục khác rơi vào khung "đang được xây dựng" (quanh dòng 697-703, 1579-1582); menu còn ghi "đã bỏ Hộp duyệt…" trong khi repo ĐÃ có Hộp duyệt, tab Quy trình duyệt và Sản phẩm trong Cài đặt. Shell chưa có lối Trợ giúp, chưa có chữ lớn, ô tìm kiếm chỉ ghi "Tìm kiếm…", chọn ngôn ngữ dùng cờ quốc gia, nút Customizer nổi góc phải đè lên nội dung trên màn hẹp.

1. Menu 2 mặt, bám code thật (đọc `app/Modules/Admin/Navigation/AdminNav.php` và `PlatformNav.php`):
   - Mặt Site: Tổng quan · NỘI DUNG: mỗi loại nội dung là 1 nhóm {Thêm mới · Danh sách · Chuyên mục nếu loại có chuyên mục}, loại đọc từ dữ liệu (Trang, Tin tức, Dịch vụ, Dự án… — Trang không có Chuyên mục) · Sản phẩm {Thêm mới · Danh sách · Chuyên mục} · Hộp duyệt (CHỈ hiện khi site bật quy trình VÀ người dùng có quyền duyệt; có số đếm) · CÔNG CỤ: Thư viện ảnh & tệp · Khách liên hệ (số mới) · Biểu mẫu · Trình đơn · HỆ THỐNG: Thành viên · Nhóm quyền · Cài đặt. Nhãn theo Bảng thuật ngữ D0-3.
   - Mặt Nền tảng (CÙNG khung sidebar, nhãn mặt ở topbar): Tổng quan · Khách hàng · Website · Gói & tính năng · Loại website · Nhân sự vận hành · Nhật ký hệ thống · Cài đặt nền tảng.
   - BẢO MẬT: 2 mặt chạy ở 2 tên miền khác nhau — KHÔNG vẽ nút "chuyển mặt" trong cùng phiên cho người dùng thật. Công tắc mặt chỉ là Tweak để xem thử prototype (ghi rõ như vậy). Vận hành viên vào website khách chỉ qua "Đăng nhập hỗ trợ" (D4-3).
   - Mặt TMĐT: giữ menu hiện có, gắn nhãn "Để sau".
2. Topbar gọn cho người mới: ô tìm kiếm LUÔN hiện với placeholder "Tìm trang, bài viết, sản phẩm, cài đặt…" (⌘K/Ctrl+K chỉ là phím tắt phụ; 390px = biểu tượng kính lúp mở ô tìm toàn màn) · nút "Trợ giúp" (panel D0-2, nội dung theo màn đang mở + Liên hệ hỗ trợ) · chuông thông báo · bộ chuyển website (khi có ≥2 website) · menu người dùng. Menu người dùng (cả 2 mặt): Tài khoản của tôi · Giao diện (sáng / tối / theo máy · cỡ chữ Bình thường / Lớn · tuỳ biến trang quản trị) · Ngôn ngữ giao diện (tên bản địa, KHÔNG cờ) · Đăng xuất (có xác nhận). BỎ khỏi topbar: nút toàn màn hình, nút sáng/tối riêng, nút Customizer nổi góc phải (chuyển vào menu người dùng › Giao diện). Không có "Khoá màn hình" (chưa có backend, ít giá trị).
3. Cài đặt (khung section-nav hiện có) — nhóm & mục chuẩn: Chung {Thông tin chung · Giao diện website · Giao diện trang quản trị · Ngôn ngữ website} · Nội dung {Đường dẫn · Sản phẩm · Quy trình duyệt — nhãn "Vận hành viên", chỉ hiện với vận hành viên nền tảng} · Tiếp thị & Kết nối {SEO & Chia sẻ · Chuyển hướng · Tích hợp & Theo dõi · Nút liên hệ nổi} · Hệ thống {Tên miền · Email · Đăng nhập nhanh · Gói & mức dùng} · Nâng cao — gập mặc định {Bảo mật & Nâng cao · Dữ liệu & Phục hồi (Để sau)}. Mặt Nền tảng giữ 7 mục hiện có.
4. Mô phỏng 3 vòng chặn bằng Tweaks — KHÔNG bịa cờ boolean (LESSONS #18); đọc `app/Modules/Rbac/Registry/PermissionRegistry.php`, `app/Modules/Tenancy/Registry/FeatureRegistry.php`, `app/Modules/Tenancy/Registry/ModuleCatalog.php`: vai trò site (Chủ website 90 · Quản trị 70 · Biên tập 50 · Tác giả 30 · Cộng tác viên 20 · Chỉ xem 10) · vai trò nền tảng (super_admin · support · billing) · tính năng gói (`workflow.editorial`, `sites.max`, `media.storage_mb`, `rbac.custom_roles` — có từ 23/09) · module bật/tắt · chế độ duyệt (không duyệt / duyệt trước khi đăng / biên tập) · số ngôn ngữ (1/3/8) · số website của người dùng (1/3) · website mới tinh (hiện "Bắt đầu") · sáng/tối · chữ lớn. Menu, nút, tab ẩn/khoá đúng theo quyền; module ngoài gói hiện đúng mẫu "khoá theo gói".
5. Registry màn hình: MỘT bảng `SCREENS` — khoá menu → {tiêu đề, mô tả 1 dòng, breadcrumb, dc-import, props, embedId, trạng thái: ready | chờ thiết kế | để sau, mã lượt phụ trách, câu hỏi Trợ giúp}. Chỉ MOUNT màn đang mở (sc-if theo màn); trước khi gỡ màn phải qua chặn rời trang khi chưa lưu (navGuard). Thay khung trống bằng thẻ "Chờ thiết kế" chuẩn: tên màn · 1 câu mục đích · dữ liệu sẽ hiện · mã lượt.
6. Điều hướng liền mạch: `bs-goto {screen, params}` là cách duy nhất chuyển màn (Trình đơn đã dùng); đường dẫn hash `#/<mặt>/<màn>?…` để mở thẳng + lùi/tiến của trình duyệt; breadcrumb sinh từ registry; bảng lệnh tìm được màn + nội dung mẫu; chuông → "Xem tất cả" mở màn Thông báo; khe banner "Đang hỗ trợ website X" (dùng ở D4-3).
7. Hiệu năng prototype: đo thời gian mở Shell trước/sau; không import sẵn mọi màn.

Xong khi: bấm hết mọi mục menu ở 2 mặt không lỗi; đổi vai trò/gói/ngôn ngữ/chữ lớn thấy đúng; ở 390px không có gì che nội dung; Bản đồ màn hình cập nhật cột trạng thái.
