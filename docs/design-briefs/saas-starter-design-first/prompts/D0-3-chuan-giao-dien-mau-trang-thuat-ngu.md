═══ BỐI CẢNH CHUNG (giữ nguyên ở đầu mọi lượt) ═══
Dự án BSWebSaaS trong Claude Design. Trước khi làm: đọc CLAUDE.md, LESSONS.md, github.md, TODO.md; mở Admin Shell.dc.html và design system `_ds/bssaas-adminkit-design-system`; dùng lại nguồn chung ai-quota.js (BSAI) · content-workflow.js (BSCW) · content-ui.js (BSUI) · content-seo.js (BSSEO), và — khi đã có — `Chuẩn giao diện SaaS Starter.dc.html` (lượt D0-2, D0-3) + registry màn của Admin Shell (lượt D0-4) + `SaaS Starter - Bản đồ màn hình.md` (lượt D0-1).
Sản phẩm: SaaS Starter — bộ khung quản trị đa-tenant BÁN ĐƯỢC cho nhiều loại website/SaaS: white-label, đa ngôn ngữ (tiếng Việt trước), phân quyền động, quy trình duyệt động (mặc định TẮT). Người dùng: từ chủ doanh nghiệp nhỏ lớn tuổi, chỉ biết đọc và dùng chuột/điện thoại, tới nhân viên văn phòng và người quản trị thạo việc.
Nguồn sự thật dữ liệu: GitHub tuyenht/BSWebSaaS@main — mở đúng file repo nêu trong prompt để lấy bảng/cột/quyền/trạng thái thật; KHÔNG bịa. Chỗ nào repo chưa có thì vẫn vẽ, gắn nhãn "Chờ backend" và ghi vào mục "Câu hỏi cho backend" của README.
Luật cứng:
- Token AdminKit — bậc WCAG AA đã chuẩn hoá (PR #87 trong repo); DÙNG ĐÚNG bộ này, không tự thêm màu ngoài bộ: navy #405189 · Inter 14px · bo 4px ô nhập / 6px thẻ · 1 bóng thẻ · nền #f3f3f9.
  · Chữ phụ: --color-body-muted #6b6e7f (≥4.5:1 trên trắng và trên nền trang). --color-body-faint CHỈ cho chi tiết trang trí, KHÔNG cho chữ có nghĩa.
  · Navy làm CHỮ / VIỀN / VÒNG FOCUS = role "accent" (sáng #405189 · tối #96a1c0); navy làm NỀN dưới chữ trắng vẫn là --color-admin-primary.
  · Màu ngữ nghĩa 2 bậc: --color-admin-{success,info,warning,danger}-text (chữ + kiểu soft + viền, ≥4.5:1, lật theo sáng/tối) và -solid (nền dưới chữ trắng, ≥4.5:1). Nút/badge warning solid dùng chữ #212529.
  · Viền ô nhập: --color-border-input (≥3:1 trên nền ô, cả sáng lẫn tối).
  · Focus: --focus-ring-width 2px · --focus-ring-offset 2px · --focus-ring-color (accent; vùng nền đậm như sidebar/header đổi màu vòng theo chữ của vùng). KHÔNG outline:none mà không thay bằng ring.
  · Sidebar: chữ mục thường trắng .85; MỤC ĐANG CHỌN = chữ trắng + nền active + vạch chỉ báo bên trái + aria-current="page" (phân biệt bằng HÌNH, không chỉ bằng màu).
  · Storefront: chữ màu thương hiệu trên nền sáng dùng --primary-text (tự làm tối tới ≥4.5:1 trên thẻ và trên nền trang); --primary giữ cho nền/viền và chữ trên nền tối (header/hero/footer).
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

# D0-3 · Chuẩn giao diện SaaS Starter — phần 2: mẫu trang · bảng thuật ngữ · tiêu chí dễ dùng

Thêm vào `Chuẩn giao diện SaaS Starter.dc.html` (dùng component của D0-2):
A. 8 mẫu trang (vẽ đủ 3 bề rộng + trạng thái):
   1 Danh sách (tiêu đề + mô tả + 1 nút chính · tab trạng thái · lọc · bảng table-layout:fixed theo LESSONS #5 · chọn nhiều · "Tải thêm" kiểu keyset cho bảng lớn; ở 390px bảng đổi thành thẻ) · 2 Chi tiết (đầu trang + tab + panel phải) · 3 Soạn/sửa (cột chính gọn cho người mới — tuỳ chọn phụ trong "Thêm tuỳ chọn" gập lại; cột phải các thẻ; thanh Lưu dính dưới; chặn rời trang khi chưa lưu; tự lưu nháp) · 4 Mục cài đặt (điều hướng nhóm bên trái, 1 thanh Lưu chung + đếm "chưa lưu" theo §7c; nhóm "Nâng cao" gập mặc định) · 5 Hộp hai khung (danh sách trái + khung đọc phải — khung đọc giãn hết chiều cao màn, không cuộn ngang) · 6 Hướng dẫn nhiều bước (wizard) · 7 Bảng điều khiển (lưới thẻ số liệu + việc cần làm + hoạt động) · 8 Trang lỗi/tiện ích.
B. Trạng thái chuẩn: trống lần đầu (nói lợi ích + nút bắt đầu + ví dụ) · trống do lọc (nút Xoá lọc) · đang tải · lỗi mạng · không có quyền (nói ai cấp được) · khoá theo gói · chỉ xem.
C. Bảng thuật ngữ & động từ (một nguồn cho mọi màn; đối chiếu nhãn trạng thái trong content-workflow.js để khớp): ví dụ Đăng · Lưu nháp · Hẹn giờ đăng (thay "Lên lịch/scheduled") · Chỉ người quản trị xem (thay "Riêng tư/private") · Gỡ khỏi website (giữ lại) (thay "Lưu trữ/archived") · Thùng rác · Khôi phục · Xoá vĩnh viễn · Gửi duyệt · Duyệt đăng · Trả lại · Chuyên mục · Thẻ · Đường dẫn · Mô tả hiện trên Google · Ảnh khi chia sẻ lên Facebook/Zalo · Trình đơn · Thư viện ảnh & tệp · Khách liên hệ · Thành viên · Nhóm quyền · Gói · Hạn mức · Tên miền · Trỏ tên miền · Bảo mật https. Mỗi từ: nghĩa 1 dòng + từ KHÔNG dùng.
D. Giọng văn: câu ≤ 20 từ; nói hệ quả trước rồi thao tác; nút là động từ; không "Bước 1/2" khi số bước có thể đổi (§14); số và ngày theo kiểu Việt Nam (1.250.000 ₫, 31/12/2026).
E. Trang "Tiêu chí dễ dùng" (12 tiêu chí trong phần bối cảnh chung) trình bày thành bảng kiểm có ví dụ đúng/sai bằng hình — các lượt sau đối chiếu vào đây.
F. Người dùng mẫu để thử: (1) chủ xưởng 55 tuổi, dùng điện thoại là chính; (2) nhân viên văn phòng đăng tin 2 lần/tuần; (3) cộng tác viên viết bài; (4) người quản trị thạo việc quản 5 website. Mỗi người 3 việc tiêu biểu — D5-2 dùng để thử nghiệm.

Xong khi: mẫu trang + bảng thuật ngữ + bảng kiểm dễ dùng nằm trong trang chuẩn; README ghi rõ quy tắc; TODO.md liệt kê các màn cần chuyển sang dùng chuẩn này.
