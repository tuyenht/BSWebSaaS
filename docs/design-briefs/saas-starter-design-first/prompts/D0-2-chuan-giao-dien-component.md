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
- Mẫu DANH SÁCH dùng chung (đã có thiết kế + đã build): lấy đúng khuôn của `Quản lý Bài viết - Options` và `Quản lý Trang - Options` (code: `app/Modules/Admin/Livewire/Entries/Index.php`) — tiêu đề + 1 dòng mô tả + 1 nút chính · tab trạng thái có số đếm + tab Thùng rác · ô tìm + các nút lọc (danh sách tick có số, "Đặt lại"/"Xong") · dòng "Đang lọc: … Xoá tất cả · Khớp N / M" · chọn cột (cột bắt buộc khoá) · thanh "Đã chọn N" chỉ đếm dòng làm được · dòng có tiêu đề + đường dẫn sửa tại chỗ, trạng thái bấm đổi được + "Đặt lịch", mã ngôn ngữ bấm để sửa bản dịch, người tạo (chữ cái đầu) · chân bảng "Dòng/trang" 10/20/50 + số trang. Màn danh sách mới CHỈ thêm cột/bộ lọc riêng của nó, không chế khuôn khác. Ngoại lệ phân trang: danh sách không giới hạn (nhật ký hệ thống, khách hàng/website của nền tảng) giữ "Dòng/trang" nhưng thay số trang bằng "‹ Trước · Sau ›" và không hiện tổng; dòng thông báo/hộp thư dạng dòng thời gian dùng "Tải thêm".
TIÊU CHÍ DỄ DÙNG — kiểm từng màn trước khi giao, ghi kết quả (đạt/chưa + lý do) vào README:
 1. Hiểu trong 5 giây: tiêu đề + 1 dòng mô tả nói màn này để làm gì; đúng 1 nút chính nổi bật; người mới biết bấm gì tiếp.
 2. Việc chính của màn xong trong ≤ 3 bước từ menu, không cần đọc hướng dẫn.
 3. Không thuật ngữ kỹ thuật trần (SMTP, OAuth, DNS, CNAME, SSL, API, webhook, token, slug, meta, robots…). Buộc phải có thì để trong khối "Nâng cao" và kèm 1 câu giải thích đời thường. Tên thương hiệu quen (Google, Zalo, Facebook) giữ nguyên nhưng nói rõ công dụng ("Facebook Pixel — đo hiệu quả quảng cáo Facebook").
 4. Icon luôn kèm chữ; chỉ icon rất quen (tìm kiếm, chuông, đóng, thêm) mới được đứng một mình, và phải có tooltip + aria-label.
 5. Ô nhập có gợi ý bằng ví dụ thật; lỗi nói cách sửa ("Số điện thoại cần 10 số, ví dụ 0901 234 567"), không nói lỗi kỹ thuật.
 6. Không mất việc: tự lưu nháp, chặn rời trang khi chưa lưu, xoá = vào Thùng rác kèm toast "Hoàn tác"; hộp xác nhận nói rõ hệ quả bằng số ("Xoá mục này và 3 mục con"). Backend hiện chỉ có Thùng rác cho bài, trang, sản phẩm, ảnh & tệp — loại khác mà màn cần Thùng rác thì VẪN vẽ, ghi "Chờ backend" + mục Câu hỏi cho backend (không tự bỏ).
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

# D0-2 · Chuẩn giao diện SaaS Starter — phần 1: component

Vì sao: design system mới có 18 component nền (Alert, Avatar, Badge, Button, Card, Icon, Pagination, Table, Input, Select, Textarea, PageHeader, Breadcrumb, Dropdown, DropdownItem, Modal, Tabs, Tooltip). Phần còn lại mỗi màn đang tự chế (công tắc `.dsw`, popover ⓘ, thanh ngôn ngữ, đường bước duyệt, chip hạn ngạch…); ảnh chụp các màn còn lỗi chữ trong nút/chip bị xuống 2 dòng hoặc bị cắt. LESSONS #14/#15 đã chỉ ra hậu quả. Lượt này gom về MỘT trang chuẩn; mọi lượt sau chỉ dùng lại.

Tạo `Chuẩn giao diện SaaS Starter.dc.html` (phần Component) + README. Mỗi component: biến thể, hover/focus/disabled/lỗi, sáng/tối, cỡ cảm ứng, chế độ "Chữ lớn", và luật không cắt chữ (tiêu chí 8):
- Điều khiển: Switch · Checkbox · Radio group · Segmented control · Ô tìm kiếm (khung sáng khi focus, placeholder nói rõ tìm gì) · Thanh lọc (chip lọc + "Xoá lọc") · Chọn ngày & giờ (theo múi giờ site, dd/mm/yyyy) · Nhập thẻ · Vùng thả tệp.
- Màu: HAI loại tách bạch — (a) "Màu thương hiệu": chọn 1 màu tự do + tự kiểm tương phản chữ trên màu, gợi ý màu an toàn nếu không đạt (chỉ dùng ở Giao diện website); hiện dải 10 bậc tự sinh `--brand-50 … --brand-900` — backend ĐÃ CÓ 26/09 (`app/Modules/Theme/Theming/BrandPalette.php`: giữ sắc màu, bậc sáng đều, màu gốc nằm nguyên ở bậc gần nhất; đầu sáng đi chữ tối, đầu tối đi chữ trắng, ≥4.5:1) — vẽ đúng thuật toán đó, không tự chế bảng; (b) "Chọn trong bảng màu": chỉ các ô token (dùng ở mọi chỗ khác).
- Phản hồi: Toast (biến thể có nút "Hoàn tác" 8 giây) · Hộp xác nhận (mẫu SweetAlert2, nút Huỷ focus, biến thể nguy hiểm) · Dải thông báo trong trang (thông tin / cảnh báo / lỗi / thành công) · Skeleton (danh sách, thẻ, form) · Trạng thái trống (có ví dụ + nút "Bắt đầu từ mẫu" khi hợp).
- Bố cục: Panel trượt phải (drawer) · Thanh thao tác hàng loạt ("Đã chọn N" + nút đếm đúng số hợp lệ) · Popover ⓘ (theo §8) · Danh sách khoá–giá trị · Thẻ số liệu · Thanh mức dùng (ngưỡng 80% / 100%).
- Tiến độ & lịch sử: Stepper (đường tiến độ có mốc — lấy từ bộ Quy trình duyệt) · Timeline · Vòng tiến độ "Hoàn thiện website 3/6".
- Dữ liệu cây: Cây kéo-thả (thụt cấp, giới hạn độ sâu, kéo bằng bàn phím được).
- Riêng hệ thống: Chấm trạng thái (§3) · Chip hạn ngạch (§7 — đúng đơn vị) · Thanh ngôn ngữ (lấy nguyên từ `Language Context - Design Spec`) · Ô quyền (tick có trạng thái khoá + lý do) · Huy hiệu "khoá theo gói" ("Gói hiện tại chưa có …" + đường liên hệ, không ghi tên nhà cung cấp) · Nhóm avatar.
- Trợ giúp: Nút "?" cạnh tiêu đề trang → panel Trợ giúp (3–5 câu hỏi thường gặp của màn đang mở + "Liên hệ hỗ trợ": điện thoại / Zalo / email lấy từ cài đặt nền tảng) · Chú thích hướng dẫn tối đa 3 bước (tắt được, không tự hiện lại).
Bảng tra cho build: component → tên Blade đích `<x-admin::…>`. Repo đã có 18: alert, avatar, badge, brand-logo, button, card, dropdown, icon, input, modal, page-header, pagination, rich-editor, settings-shell, table, tabs, theme-option, tooltip (`app/Modules/Admin/resources/views/components/`). Ghi rõ cái nào MỚI cần build, props chính, token dùng.
Đưa pattern của `content-ui.js` (BSUI) vào trang này làm nguồn trình diễn; bổ sung vào `content-ui.js` phần còn thiếu — không khai lại token ở chỗ khác.

Xong khi: trang chuẩn duyệt được bằng mắt ở 1240/768/390, sáng/tối, chữ thường/chữ lớn; không nút/chip nào xuống dòng; README có bảng component → Blade.
