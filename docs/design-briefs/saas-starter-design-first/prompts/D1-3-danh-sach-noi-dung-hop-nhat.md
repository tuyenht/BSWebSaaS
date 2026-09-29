═══ BỐI CẢNH CHUNG (giữ nguyên ở đầu mọi lượt) ═══
Dự án BSWebSaaS trong Claude Design. Trước khi làm: đọc CLAUDE.md, LESSONS.md, github.md, TODO.md; mở Admin Shell.dc.html và design system `_ds/bssaas-adminkit-design-system`; dùng lại nguồn chung ai-quota.js (BSAI) · content-workflow.js (BSCW) · content-ui.js (BSUI) · content-seo.js (BSSEO), và — khi đã có — `Chuẩn giao diện SaaS Starter.dc.html` (lượt D0-2, D0-3) + registry màn của Admin Shell (lượt D0-4) + `SaaS Starter - Bản đồ màn hình.md` (lượt D0-1).
Sản phẩm: SaaS Starter — bộ khung quản trị đa-tenant BÁN ĐƯỢC cho nhiều loại website/SaaS: white-label, đa ngôn ngữ (tiếng Việt trước), phân quyền động, quy trình duyệt động (mặc định TẮT). Người dùng: từ chủ doanh nghiệp nhỏ lớn tuổi, chỉ biết đọc và dùng chuột/điện thoại, tới nhân viên văn phòng và người quản trị thạo việc.
Nguồn sự thật dữ liệu: GitHub tuyenht/BSWebSaaS@main — mở đúng file repo nêu trong prompt để lấy bảng/cột/quyền/trạng thái thật; KHÔNG bịa. Chỗ nào repo chưa có thì vẫn vẽ, gắn nhãn "Chờ backend" và ghi vào mục "Câu hỏi cho backend" của README.
Luật cứng:
- Token AdminKit — bậc WCAG AA đã chuẩn hoá (PR #87 trong repo); DÙNG ĐÚNG bộ này, không tự thêm màu ngoài bộ: navy #405189 · Inter 14px · bo 4px ô nhập / 6px thẻ · 1 bóng thẻ · nền #f3f3f9.
  · Chữ phụ: --color-body-muted #6b6e7f (≥4.5:1 trên trắng và trên nền trang). --color-body-faint CHỈ cho chi tiết trang trí, KHÔNG cho chữ có nghĩa.
  · Navy làm CHỮ / VIỀN / VÒNG FOCUS = role "accent" (sáng #405189 · tối #96a1c0); navy làm NỀN dưới chữ trắng vẫn là --color-admin-primary.
  · Màu ngữ nghĩa 2 bậc: --color-admin-{secondary,success,info,warning,danger}-text (chữ + kiểu soft + viền, ≥4.5:1, lật theo sáng/tối) và -solid (nền dưới chữ trắng, ≥4.5:1; secondary nền #2f69d4, KHÔNG dùng #3577f1 làm nền chữ). Nút/badge warning solid dùng chữ #212529. Badge "dark" kiểu soft dùng chữ --color-admin-dark-text (lật sáng ở chế độ tối) (PR #92).
  · Viền ô nhập: --color-border-input (≥3:1 trên nền ô, cả sáng lẫn tối). Placeholder = --color-placeholder (= muted) và CHỈ chứa ví dụ ("0912 345 678"); hướng dẫn/điều kiện đặt ở dòng gợi ý dưới ô, không nhét vào placeholder (PR #92).
  · Focus: --focus-ring-width 2px · --focus-ring-offset 2px · --focus-ring-color (accent; vùng nền đậm như sidebar/header đổi màu vòng theo chữ của vùng). KHÔNG outline:none mà không thay bằng ring.
  · Sidebar: chữ mục thường trắng .85; MỤC ĐANG CHỌN = chữ trắng + nền active + vạch chỉ báo bên trái + aria-current="page" (phân biệt bằng HÌNH, không chỉ bằng màu).
  · Storefront: chữ màu thương hiệu trên nền sáng dùng --primary-text (tự làm tối tới ≥4.5:1 trên thẻ và trên nền trang); chữ màu thương hiệu trên nền tối --ink (link footer, menu con, nút phụ ở hero) dùng --primary-on-ink (tự làm sáng tới ≥4.5:1, PR #92); --primary giữ cho nền/viền.
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
 6. Không mất việc: tự lưu nháp, chặn rời trang khi chưa lưu. Xoá thứ CÓ Thùng rác (bài, trang, sản phẩm, ảnh & tệp) = vào Thùng rác kèm toast "Hoàn tác"; xoá thứ KHÔNG có Thùng rác (trình đơn, mục trình đơn, chuyên mục, nhóm quyền…) = hộp xác nhận nói rõ hệ quả bằng số và tên ("Xoá mục này và 3 mục con", "12 bài sẽ chuyển sang Tin công ty") — không hứa "Hoàn tác" cho thứ đã xoá hẳn.
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

# D1-3 · Danh sách nội dung — một khuôn cho Trang, Tin tức, Dịch vụ, Dự án

Hiện có 2 màn lệch: `Quản lý Bài viết - Options` (đúng repo: BSCW 6 vai trò, 3 chế độ duyệt, trạng thái scheduled/private/archived, thùng rác) và `Quản lý Trang - Options` (dùng cờ giả `canPublish`, không BSCW — trái LESSONS #18). Repo còn 1 bản Trang cũ hơn (`docs/design_handoff_pages_admin/Pages Admin - Options.dc.html`) — lấy HỢP các điểm hay (LESSONS #15), không bỏ tính năng nào.
Dữ liệu thật: `entries` (loại page|post; trạng thái draft|pending|published|scheduled|private|archived — migration 2012; bước duyệt; người được giao; trang cha; tác giả; ngày đăng; lượt xem; thùng rác) + `entry_translations` (tiêu đề, đường dẫn, mô tả ngắn, SEO, trạng thái theo ngôn ngữ). Danh sách đang chạy: `app/Modules/Admin/Livewire/Entries/Index.php` (tab trạng thái + thùng rác, tìm tiêu đề/đường dẫn, lọc ngôn ngữ/người tạo/mẫu, thao tác hàng loạt, Trang dạng cây).
Việc:
1. Một component danh sách dùng chung, cấu hình theo loại: Trang (cây cha-con, không chuyên mục, không mô tả ngắn) · Bài viết/Dịch vụ/Dự án (chuyên mục + chuyên mục chính, thẻ, tác giả).
2. Bỏ `canPublish` → dùng BSCW (quyền của-mình/mọi-bài theo EntryPolicy cho từng dòng; nút hàng loạt đếm đúng số hợp lệ). Tên trạng thái theo Bảng thuật ngữ D0-3 (không hiện "scheduled/private/archived" trần).
3. Bổ sung: lọc theo chuyên mục (repo chưa có), cột "bước duyệt" khi bật quy trình, cột ngôn ngữ (chấm §3), lượt xem, "Tải thêm" kiểu keyset thay đánh số trang khi >100 mục; ở 390px mỗi dòng thành 1 thẻ (tiêu đề + trạng thái + 1 nút "…").
4. Thùng rác: khôi phục, xoá hẳn (xác nhận nguy hiểm), tự dọn sau 30 ngày (nói rõ) — Chờ backend: hiện chỉ Thư viện ảnh tự dọn thùng rác (`media:purge-trash`); bài/trang nằm trong Thùng rác tới khi có người xoá hẳn → ghi vào Câu hỏi cho backend; đưa vào thùng rác có toast "Hoàn tác".
5. Kiểm theo LƯỢT D trong "CÁCH LÀM TIẾP - 4 lượt chat.md": 3 bề rộng × 6 vai trò × 3 chế độ duyệt × 1/2/4 ngôn ngữ; dữ liệu 12/60/180 mục.
Gắn vào Shell: `pages.list`, `posts.list`, `services.list`, `projects.list` dùng chung component.
