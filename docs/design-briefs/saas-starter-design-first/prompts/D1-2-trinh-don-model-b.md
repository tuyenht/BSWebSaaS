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

# D1-2 · Trình đơn — khớp mô hình "vị trí × ngôn ngữ" (ADR-11)

Sửa `Trình đơn.dc.html` (KHÔNG vẽ lại — giữ mọi phần đang tốt: kéo-thả, trạng thái mục, hoàn tác, xem trước).
Dữ liệu thật (`database/migrations/2003_01_01_000000_create_menu_tables.php`; bộ đọc `app/Modules/Cms/Navigation/MenuResolver.php`):
- `menus` (tên); `menu_assignments` (vị trí × ngôn ngữ → đúng 1 menu); `menu_items` (cha, thứ tự, loại liên kết: bài/trang · chuyên mục · sản phẩm (có từ 24/09) · động (trang chủ) · địa chỉ ngoài; mở tab mới); `menu_item_translations` (nhãn theo ngôn ngữ; nhãn trống → lấy tên/tiêu đề hiện tại của nguồn).
- Website TỰ ẨN mục trỏ tới trang/bài chưa đăng, đã gỡ hoặc trong thùng rác, sản phẩm chưa đăng, và chuyên mục chưa có bản dịch ở ngôn ngữ đó — khách không bao giờ bấm vào trang lỗi. Người quản trị cần thấy các mục đang bị ẩn và lý do.
- Ngôn ngữ chưa được gán menu → dùng menu của ngôn ngữ mặc định. Độ sâu tối đa 5.
Việc:
1. Thêm lớp "gán theo ngôn ngữ" cho mỗi vị trí: mặc định "Dùng chung cho mọi ngôn ngữ" (nhãn dịch theo từng mục); tuỳ chọn "Dùng trình đơn riêng cho <ngôn ngữ>" (tạo bằng Sao chép + Dịch, hoặc chọn menu khác). Hiện rõ ngôn ngữ nào đang dùng bản mặc định (chip "theo Tiếng Việt").
2. Liên kết tới "Sản phẩm" (backend có từ 24/09 — vẽ như loại chính thức, không gắn "Chờ chốt"): chọn 1 sản phẩm bằng danh sách có tìm; nhãn điền sẵn theo tên sản phẩm ở từng ngôn ngữ (sửa được; để trống thì website tự lấy tên hiện tại). Mục có nguồn chưa đăng / trong thùng rác / đã xoá hẳn → hiện nhãn trạng thái rõ ("Đang ẩn trên website — sản phẩm chưa đăng", "Nguồn đã xoá") kèm nút đi tới nguồn hoặc gỡ mục.
3. "Khối tự dựng" (bảng lớn): gắn "Để sau" (phụ thuộc Khối tái dùng — chưa build); menu vẫn dùng đầy đủ khi tắt.
4. Vị trí lấy từ giao diện đang dùng; repo hiện chỉ hiển thị "Trình đơn chính" → vị trí khác vẽ kèm dòng "Giao diện hiện tại chưa hiển thị vị trí này".
5. Người không quen kéo-thả / điện thoại: mỗi mục có nút "Chuyển lên", "Chuyển xuống", "Đưa vào trong mục trên"; thêm mục bằng "Chọn trang có sẵn" (danh sách có tìm) là đường chính, "Nhập địa chỉ ngoài" là phụ.
6. Thùng rác 30 ngày cho menu/mục đã xoá + Khôi phục (việc còn lại #0 trong TODO.md). "Xem trên website" khi nhúng: đưa lên cụm nút của Shell.
Giữ: Lưu = hiệu lực ngay, không nháp/duyệt; mọi thao tác hoàn tác được.
