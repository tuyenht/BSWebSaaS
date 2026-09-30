═══ BỐI CẢNH CHUNG (giữ nguyên ở đầu mọi lượt) ═══
Dự án BSWebSaaS trong Claude Design. Trước khi làm: đọc CLAUDE.md, LESSONS.md, github.md, TODO.md; mở Admin Shell.dc.html (từ D0-5 trở đi chỉ cần bảng SCREENS + phần liên quan tới màn của lượt) và design system `_ds/bssaas-adminkit-design-system`; dùng lại nguồn chung ai-quota.js (BSAI) · content-workflow.js (BSCW) · content-ui.js (BSUI) · content-seo.js (BSSEO), và — khi đã có — `Chuẩn giao diện SaaS Starter.dc.html` (lượt D0-2, D0-3) + registry màn của Admin Shell (lượt D0-4b) + `SaaS Starter - Bản đồ màn hình.md` (lượt D0-1).
Sản phẩm: SaaS Starter — bộ khung quản trị đa-tenant BÁN ĐƯỢC cho nhiều loại website/SaaS: white-label, đa ngôn ngữ (tiếng Việt trước), phân quyền động, quy trình duyệt động (mặc định TẮT). Người dùng: từ chủ doanh nghiệp nhỏ lớn tuổi, chỉ biết đọc và dùng chuột/điện thoại, tới nhân viên văn phòng và người quản trị thạo việc.
Nguồn sự thật dữ liệu: GitHub tuyenht/BSWebSaaS@main — phần repo ĐÃ CÓ thì mở đúng file nêu trong prompt để lấy bảng/cột/quyền/trạng thái/mã lỗi thật; KHÔNG bịa.
THIẾT KẾ DẪN, BACKEND THEO (owner 29/09): màn cần tính năng mà repo chưa có thì cứ vẽ đầy đủ, gắn "Chờ backend" và ghi vào mục "Việc cho backend" của README (tính năng · dữ liệu cần lưu · luật) — backend sẽ được làm theo thiết kế. KHÔNG tự cắt tính năng, KHÔNG vẽ trạng thái tạm cho vừa backend. Khi tính năng đụng tách dữ liệu giữa các website/bảo mật, pháp lý (đồng ý, dữ liệu cá nhân) hoặc làm chậm danh sách hàng chục nghìn dòng: KHÔNG dừng lượt — vẽ phương án an toàn nhất (mặc định tắt/kín), gắn "Cần owner chốt" kèm 1 câu lý do trong README. Nhãn "Chờ backend"/"Cần owner chốt" là ghi chú thiết kế: gom dưới một Tweak "Hiện ghi chú thiết kế" (mặc định bật); tắt thì màn trông như bản thật.
MÀN ĐÃ CÓ THIẾT KẾ = SỬA TẠI CHỖ, KHÔNG VẼ LẠI: chỉ chạm đúng phần prompt nêu; giữ bố cục, dữ liệu mẫu, tương tác đang có. Chỉ màn chưa có mới được vẽ mới.
Hạn mức lượt: làm theo đúng thứ tự việc trong prompt; mỗi phần xong trọn mới sang phần sau. Nếu sắp hết lượt: chốt phần đang làm, ghi phần còn lại vào TODO.md với tên "<mã lượt> phần 2" — không bỏ dở một thành phần.
Luật cứng:
- Token AdminKit — bậc WCAG AA đã chuẩn hoá (PR #87 trong repo); DÙNG ĐÚNG bộ này, không tự thêm màu ngoài bộ: navy #405189 · Inter 14px · bo 4px ô nhập / 6px thẻ · 1 bóng thẻ · nền #f3f3f9.
  · Chữ phụ: --color-body-muted #6b6e7f (≥4.5:1 trên trắng và trên nền trang). --color-body-faint CHỈ cho chi tiết trang trí, KHÔNG cho chữ có nghĩa.
  · Navy làm CHỮ / VIỀN / VÒNG FOCUS = role "accent" (sáng #405189 · tối #96a1c0); navy làm NỀN dưới chữ trắng vẫn là --color-admin-primary.
  · Màu ngữ nghĩa 2 bậc: --color-admin-{secondary,success,info,warning,danger}-text (chữ + kiểu soft + viền, ≥4.5:1, lật theo sáng/tối) và -solid (nền dưới chữ trắng, ≥4.5:1; secondary nền #2f69d4, KHÔNG dùng #3577f1 làm nền chữ). Nút/badge warning solid dùng chữ #212529. Badge "dark" kiểu soft dùng chữ --color-admin-dark-text (lật sáng ở chế độ tối) (PR #92).
  · Viền ô nhập: --color-border-input (≥3:1 trên nền ô, cả sáng lẫn tối). Placeholder = --color-placeholder (= muted) và CHỈ chứa ví dụ ("0912 345 678"); hướng dẫn/điều kiện đặt ở dòng gợi ý dưới ô, không nhét vào placeholder (PR #92).
  · Focus: --focus-ring-width 2px · --focus-ring-offset 2px · --focus-ring-color (accent; vùng nền đậm như sidebar/header đổi màu vòng theo chữ của vùng). KHÔNG outline:none mà không thay bằng ring.
  · Sidebar: chữ mục thường trắng .85 (sidebar sáng: #6c6f80; chế độ tối: #8f94a5); MỤC ĐANG CHỌN = chữ trắng + nền active + vạch chỉ báo bên trái + aria-current="page" (phân biệt bằng HÌNH, không chỉ bằng màu).
  · Storefront: chữ màu thương hiệu trên nền sáng dùng --primary-text (tự làm tối tới ≥4.5:1 trên thẻ và trên nền trang); chữ màu thương hiệu trên nền tối --ink (link footer, menu con, nút phụ ở hero) dùng --primary-on-ink (tự làm sáng tới ≥4.5:1, PR #92); --primary giữ cho nền/viền.
  · File cũ còn màu trước PR #87 (chữ #878a99 / #adb5bd / #9aa1ac / #ced4da; nền dưới chữ trắng #3577f1; viền ô nhập #e9ebec): file nào lượt này sửa thì đổi hết sang token ở trên (chữ phụ → var(--color-body-muted); faint chỉ giữ cho chi tiết trang trí). Mọi mã màu hex gắn cứng khác ở phần lượt này chạm cũng đổi sang token, để chế độ tối đúng. File lượt này không đụng thì để nguyên.
- Đích build: Livewire 4 + Blade + Tailwind 4 + <x-admin::*>; icon Remix inline-SVG (prototype dùng CDN được, README liệt kê tên icon). Không Bootstrap, không Flux.
- Mỗi màn đủ trạng thái: trống · đang tải · lỗi (có "Thử lại") · không có quyền · khoá theo gói · thành công. Không ngõ cụt.
- HIỂN THỊ ĐẠT TRÊN MỌI THIẾT BỊ (owner 30/09 — mọi màn, kể cả màn quản trị và nền tảng; màn đẹp trên máy tính mà vỡ trên điện thoại = CHƯA XONG):
  · Kiểm ở 360 · 390 · 768 · 1024 · 1240 · 1440px + điện thoại xoay ngang (844×390) × sáng + tối × chữ thường + "Chữ lớn" + phóng to trình duyệt 200%; WCAG 2.2 AA.
  · Trình duyệt: Chrome/Edge, Safari iPhone/iPad, Firefox, Samsung Internet. Chiều cao dùng 100dvh (không 100vh); chừa safe-area (tai thỏ, thanh home). CSS mới (container query, :has, subgrid) phải chạy trên Safari iOS 16+, không thì có dự phòng.
  · Bố cục co theo KHUNG CHỨA (container query trên khung màn — màn còn được nhúng trong Shell), không theo cửa sổ. Quy tắc cho bề rộng hẹp phải THẮNG quy tắc rộng — kiểm độ ưu tiên CSS (lỗi thật: `Quy trình duyệt` ở 390px, nút "Đổi quy trình" không rộng hết vì `.qd-c4 .qd-btn{width:auto}` đè `.qd-btn{width:100%}`).
  · Bảng/hàng nhiều cột dưới 768px → THẺ GỌN, KHÔNG xếp mỗi cột thành một dòng: dòng 1 tên + trạng thái/công tắc bên phải · dòng 2 thông tin phụ nối bằng "·" · dòng 3 hành động (1 nút chính rộng hết, nút phụ vào menu "⋯"); khoảng cách dọc trong thẻ ≤ 12px. Công tắc luôn đứng cạnh tên việc nó bật/tắt, nhãn nói rõ việc ("Duyệt trước khi đăng · Đang bật"). 768–1023px: 2 cột hoặc bảng rút gọn, không phải 1 cột dài. Bảng dữ liệu thật sự cần nhiều cột thì cuộn TRONG khung bảng + cột tên dính trái (ngoại lệ có chủ đích của tiêu chí 8, chỉ cho bảng dữ liệu — thẻ và khung đọc vẫn không cuộn ngang); trang không bao giờ cuộn ngang.
  · Điện thoại: hộp thoại/bảng chọn = trượt từ đáy hoặc toàn màn; thanh Lưu dính đáy không che nội dung và ô đang nhập (bàn phím ảo); menu trái thành ngăn kéo; không gì chỉ hiện khi rê chuột (hover) — phải bấm được; vùng bấm ≥ 44px, cách nhau ≥ 8px.
  · Canvas giao nộp: mỗi màn có khung xem 390 và 768 cạnh khung máy tính; README có bảng "Kiểm hiển thị" (bề rộng × trình duyệt → đạt/chưa + lý do).
- Không hiện mã số nội bộ (id) — chỉ tên, đường dẫn, mã công khai. Không gắn cứng thương hiệu (tên nền tảng/website/"Phát triển bởi" lấy từ cấu hình).
- Tính năng ngoài gói (Q12 owner 24/09, bổ sung 29/09): website được cấp AI → nút AI (Dịch, Viết bằng AI, Nâng cấp nội dung…) hiện bình thường, hạn ngạch theo §7/§7a (ai-quota.js). Nền tảng có công tắc "Lời mời nâng gói" (Cài đặt nền tảng › Gói & tính năng — mặc định TẮT, owner bật khi đã dựng xong gói): TẮT → nút AI ẩn hẳn, module ngoài gói chỉ hiện khoá trung tính "Chưa bật cho website này — liên hệ hỗ trợ", không chào mua; BẬT → mọi tính năng ngoài gói (kể cả AI) hiện dạng khoá + 1 dòng lợi ích + nút "Xem gói nâng cấp" (mở Cài đặt › Gói & mức dùng). Vẽ cả hai bằng Tweak "Lời mời nâng gói".
- Ngôn ngữ nội dung viết phải-sang-trái (العربية có trong `config/locales.php`): ô nhập và nội dung của ngôn ngữ đó tự đặt dir="rtl" + căn phải (tiêu đề, nội dung, đường dẫn giữ trái-sang-phải); khung quản trị luôn trái-sang-phải.
- Dữ liệu động: vai trò, quyền, loại nội dung, ngôn ngữ, gói, bước duyệt đều đọc từ registry/bảng — chịu được 1–20 mục, tên dài. Vai trò, gói, chế độ duyệt, số ngôn ngữ mô phỏng bằng Tweaks đọc registry thật (LESSONS #18) — không bịa cờ kiểu canPublish.
- Hành động nguy hiểm: chuyển vào Thùng rác thì KHÔNG hỏi — làm ngay + toast "Hoàn tác" 8 giây; chỉ hỏi khi kéo theo mục khác ("Xoá mục này và 3 mục con") hoặc không hoàn tác được (xoá vĩnh viễn, tắt ngôn ngữ, đổi tên miền): hộp xác nhận giữa màn kiểu SweetAlert2, nút Huỷ được focus, nút làm ghi đúng việc sẽ xảy ra.
- Mẫu DANH SÁCH dùng chung (đã có thiết kế + đã build): lấy đúng khuôn của `Quản lý Bài viết - Options` và `Quản lý Trang - Options` (code: `app/Modules/Admin/Livewire/Entries/Index.php`) — tiêu đề + 1 dòng mô tả + 1 nút chính · tab trạng thái có số đếm + tab Thùng rác · ô tìm + các nút lọc (danh sách tick có số, "Đặt lại"/"Xong") · dòng "Đang lọc: … Xoá tất cả · Khớp N / M" · chọn cột (cột bắt buộc khoá) · thanh "Đã chọn N" chỉ đếm dòng làm được · dòng có tiêu đề + đường dẫn sửa tại chỗ, trạng thái bấm đổi được (chỉ các bước quy trình + quyền cho phép — BSCW) + "Đặt lịch", mã ngôn ngữ bấm để sửa bản dịch, người tạo (chữ cái đầu) · chân bảng "Dòng/trang" 10/20/50 + số trang. Màn danh sách mới CHỈ thêm cột/bộ lọc riêng của nó, không chế khuôn khác. Ngoại lệ phân trang: danh sách không giới hạn (nhật ký hệ thống, khách hàng/website của nền tảng) giữ "Dòng/trang" nhưng thay số trang bằng "‹ Trước · Sau ›" và không hiện tổng; dòng thông báo/hộp thư dạng dòng thời gian dùng "Tải thêm".
TIÊU CHÍ DỄ DÙNG — kiểm từng màn trước khi giao, ghi kết quả (đạt/chưa + lý do) vào README:
 1. Hiểu trong 5 giây: tiêu đề + 1 dòng mô tả nói màn này để làm gì; đúng 1 nút chính nổi bật; người mới biết bấm gì tiếp.
 2. Việc chính của màn xong trong ≤ 3 bước từ menu, không cần đọc hướng dẫn.
 3. Không thuật ngữ kỹ thuật trần (SMTP, OAuth, DNS, CNAME, SSL, API, webhook, token, slug, meta, robots…). Buộc phải có thì để trong khối "Nâng cao" và kèm 1 câu giải thích đời thường. Tên thương hiệu quen (Google, Zalo, Facebook) giữ nguyên nhưng nói rõ công dụng ("Facebook Pixel — đo hiệu quả quảng cáo Facebook").
 4. Icon luôn kèm chữ; chỉ icon rất quen (tìm kiếm, chuông, đóng, thêm) mới được đứng một mình, và phải có tooltip + aria-label.
 5. Ô nhập có gợi ý bằng ví dụ thật; lỗi nói cách sửa ("Số điện thoại cần 10 số, ví dụ 0901 234 567"), không nói lỗi kỹ thuật.
 6. Không mất việc: tự lưu nháp, chặn rời trang khi chưa lưu, xoá = vào Thùng rác kèm toast "Hoàn tác"; hộp xác nhận nói rõ hệ quả bằng số ("Xoá mục này và 3 mục con"). Backend hiện chỉ có Thùng rác cho bài, trang, sản phẩm, ảnh & tệp — loại khác mà màn cần Thùng rác thì VẪN vẽ, ghi "Chờ backend" + mục Việc cho backend (không tự bỏ).
 7. Trạng thái luôn có màu + hình + chữ — không bao giờ chỉ dùng màu.
 8. Không cắt chữ: nhãn nút, chip, tab, nút lọc luôn 1 dòng (dài thì "…" + tooltip) — nút KHÔNG bao giờ xuống 2 dòng; nhãn ô nhập được xuống dòng nhưng không bị che. Không thanh cuộn ngang trong thẻ/khung đọc.
 9. Điện thoại & máy tính bảng: MỌI việc trên màn làm trọn được ở 360–390px và 768px, việc hay làm (trả lời khách liên hệ, duyệt bài, đăng tin nhanh, xem thông báo) thuận tay nhất; vùng bấm ≥ 44px; không thành phần nổi nào che nút Lưu hay nội dung.
10. Hai tầng người dùng: mặc định gọn cho người mới (tuỳ chọn nâng cao gập lại); người thạo có phím tắt, thao tác hàng loạt, lọc nhanh — nhưng không làm rối tầng người mới.
11. Chế độ "Chữ lớn" (+2px toàn trang) không làm vỡ bố cục.
12. Dùng đúng từ trong "Bảng thuật ngữ" của Chuẩn giao diện (D0-3): cùng một việc luôn cùng một từ ở mọi màn.
Nhúng & điều hướng: panel Cài đặt tuân hợp đồng nhúng §13 (embedded · onDirty · embedId · bs-embed-cmd — không tự vẽ header/nút Lưu); màn toàn trang (danh sách, trình soạn, hộp thư, thư viện) nhận embedded nhưng chỉ ẩn phần trùng Shell (breadcrumb, tiêu đề trang, khung 100vh), GIỮ thanh thao tác + nút quy trình của chính màn, báo onDirty, nhận bs-embed-cmd {act:'save'}; đăng ký vào registry màn của Admin Shell (sau D0-4b) với trạng thái "ready", chuyển màn bằng sự kiện bs-goto {screen, params}; cập nhật dòng tương ứng trong `SaaS Starter - Bản đồ màn hình.md`.
Giao nộp: 1 file .dc.html mỗi màn (hoặc gộp có anchor) + README handoff theo mẫu: Tổng quan · File · Mức hoàn thiện · Màn & trạng thái · Tương tác · Ánh xạ dữ liệu (file:dòng repo) · Quyền & gói (ai thấy gì) · Token · Icon · Chuỗi cần dịch · Kết quả 12 tiêu chí dễ dùng · Kiểm hiển thị (bề rộng × trình duyệt) · Việc cho backend.
Không sửa CLAUDE.md (chỉ owner sửa). Prompt khác CLAUDE.md ở điểm nào thì lượt này làm theo prompt (owner đã duyệt) và ghi 1 dòng LESSONS.md nêu điểm khác để owner sửa CLAUDE.md; bài học mới cũng ghi 1 dòng vào LESSONS.md. Làm xong gọi ready_for_verification và sửa hết lỗi verifier báo. Lượt này làm ĐÚNG MỘT việc bên dưới.
═══ VIỆC CỦA LƯỢT NÀY ═══

# D2-1 · Nhóm quyền & Thành viên (4 màn)

Thiết kế **4 màn "Nhóm quyền & Thành viên"** cho admin BSWebSaaS (bộ khung SaaS đa-tenant, admin kiểu Velzon), xuất `.dc.html` + `README.md` ánh xạ data-model — đúng format các handoff trước (MediaPicker, Pages Admin, Workflow).

## Ràng buộc chung (BẮT BUỘC)
- Đúng design system `bssaas-adminkit-design-system`: navy `--color-admin-primary #405189`, Inter 14px, radius 4/6px, shadow card nhẹ — KHÔNG chế màu/elevation/gradient mới. Giữ nguyên sidebar/topbar hiện có, chỉ thiết kế NỘI DUNG màn.
- Stack đích: **Livewire 4 + Blade + Tailwind 4 + `<x-admin::*>`**, icon **Remix inline-SVG** (prototype dùng remixicon CDN được, README liệt kê tên icon từng chỗ). KHÔNG Bootstrap, KHÔNG Flux.
- Người dùng là **chủ doanh nghiệp không rành công nghệ**: copy tiếng Việt đời thường, KHÔNG thuật ngữ "RBAC/permission/role" trên giao diện (dùng "nhóm quyền", "được phép…"). Mobile-first, WCAG AA, mỗi màn đủ **empty / loading / error**, không ngõ cụt.
- Xác nhận nguy hiểm theo pattern SweetAlert2 (modal giữa màn; hành động nguy hiểm → nút Hủy được focus).
- **Dữ liệu ĐỘNG:** số nhóm, tên nhóm (tới 60 ký tự), danh sách quyền đều từ hệ thống — chịu được 3–20 nhóm, tên dài. Không hiển thị mã số nội bộ nào.

## Data model thật (README phải ánh xạ đúng)
- Mỗi website có **6 nhóm hệ thống** theo cấp: Chủ sở hữu (90) · Quản trị (70) · Biên tập (50) · Tác giả (30) · Cộng tác viên (20) · Người xem (10). Nhóm hệ thống **khóa cứng**: xem được, KHÔNG sửa/xóa/đổi tên.
- **Nhóm tùy chỉnh** (chỉ khi gói website có tính năng "Nhóm quyền tùy chỉnh" — mặc định TẮT): tạo **từ một nhóm có sẵn** (sao chép quyền rồi chỉnh — ghi nhớ "tạo từ: Biên tập") hoặc **trống**. Sửa tên / cấp / quyền; xóa được khi **không còn ai** trong nhóm. Xoá = vào Thùng rác + toast "Hoàn tác" (giữ 30 ngày) như mọi thứ khác — backend hiện xoá hẳn (`roles` chưa có `deleted_at`) → ghi "Việc cho backend": thêm Thùng rác cho nhóm tuỳ chỉnh + lệnh tự dọn.
- **Luật cấp:** người dùng chỉ tạo/sửa/gán nhóm **thấp hơn cấp của mình**, và chỉ cấp được quyền **mình đang có** (quyền mình không có → ô bị khóa + giải thích).
- **Quyền theo khu vực** (khu vực website không được bật trong gói → ẩn/khóa cả hàng, nhãn "Ngoài gói"):
  - Nội dung: Xem · Tạo · Sửa bài của mình · Sửa mọi bài · Xóa bài của mình · Xóa mọi bài · Gửi duyệt · Phản biện · Duyệt · Đăng
  - Thư viện ảnh/tệp: Xem · Tải lên · Xóa
  - Chuyên mục: Quản lý · Menu: Quản lý · Tên miền: Quản lý · Cài đặt website: Quản lý
  - Liên hệ/khách để lại thông tin: Xem · Quản lý · Sản phẩm: Xem · Quản lý
  - Người dùng: Mời · Quản lý · Nhóm quyền: Quản lý
- **Quyền kéo theo:** tick "Sửa mọi bài / Xóa / Quản lý / Tải lên" (và mọi quyền hành động: Tạo, Gửi duyệt, Phản biện, Duyệt, Đăng) tự tick "Xem" tương ứng — không bỏ được "Xem" khi quyền lớn còn tick. "Sửa mọi bài" KHÔNG tự tick "Sửa bài của mình" (đã bao hàm — chỉ hiện gợi ý "gồm cả bài của mình"; nhóm Biên tập hệ thống đúng như vậy).
- Một thành viên có thể thuộc **nhiều nhóm**; quyền thực = hợp các nhóm.
- Mọi thay đổi nhóm/quyền/gán đều được ghi nhật ký (ai, lúc nào, đổi gì).

## Màn 1 — Danh sách nhóm quyền
- Bảng: tên nhóm · badge "Hệ thống" (khóa) / "Tùy chỉnh" (+ "tạo từ …") · cấp · số thành viên · mô tả ngắn "làm được gì" (3–4 quyền tiêu biểu) · hành động (Xem / Sửa / Nhân bản / Xóa — theo luật ở trên, không có quyền → ẩn).
- Nút chính "Tạo nhóm" → chọn "Từ nhóm có sẵn" (liệt kê nhóm thấp hơn cấp mình) hoặc "Nhóm trống".
- Gói chưa bật nhóm tùy chỉnh: vẫn xem nhóm hệ thống; nút Tạo hiện khóa + dòng "Gói hiện tại chưa có nhóm quyền tùy chỉnh — liên hệ bộ phận hỗ trợ để bật" (không dead-end; KHÔNG ghi tên thương hiệu nhà cung cấp — sản phẩm white-label).
- Empty (chưa có nhóm tùy chỉnh): 1 đoạn giải thích khi nào cần nhóm riêng (ví dụ "người chỉ duyệt bài, không sửa bài") + CTA.

## Màn 2 — Tạo / sửa nhóm (trọng tâm)
- Tên nhóm, chọn **cấp** (chỉ các cấp dưới mình, giải thích 1 dòng "Nhóm cấp cao quản lý được nhóm cấp thấp").
- **Ma trận khu vực × hành động**: tick từng ô, **tick cả hàng**, ô tìm kiếm quyền, mỗi ô có tooltip giải thích đời thường; ô khóa (không có quyền / ngoài gói) có lý do khi hover/tap.
- Nhóm tạo từ nhóm khác: đánh dấu ô **khác với nhóm gốc** (chấm/nhãn "đã đổi").
- **Xem trước thay đổi trước khi lưu**: "Thêm: Duyệt, Đăng · Bớt: Sửa mọi bài" + "Ảnh hưởng tới N thành viên đang trong nhóm".
- Nhóm hệ thống mở ở chế độ chỉ xem (ma trận read-only) + nút "Nhân bản để tùy chỉnh".
- Mobile: ma trận gập theo khu vực (accordion), mỗi khu vực là danh sách công tắc.

## Màn 3 — "Nhóm này làm được gì?" (xem như nhóm)
- Checklist đời thường theo khu vực ("Được viết bài mới", "Không được xóa bài người khác"…) cho 1 nhóm, mở từ Màn 1/2. Dành cho chủ website kiểm tra trước khi gán người.

## Màn 4 — Thành viên (thay màn hiện tại)
- Bảng: tên · email · các nhóm (chip) · trạng thái · hành động. Thêm thành viên bằng email + chọn **nhiều nhóm** (checkbox, chỉ nhóm thấp hơn cấp mình).
- Sửa nhóm của 1 thành viên = panel checkbox nhiều nhóm + xem trước "quyền thực sau khi đổi".
- Gỡ khỏi website (confirm nguy hiểm). Không sửa được người cấp bằng/cao hơn mình → nút ẩn, hiện dòng "Người này cùng cấp hoặc cao hơn bạn".
- Lỗi đời thường, ví dụ: "Người này không còn là thành viên của website. Hãy tải lại trang."

## Deliverable
1 file `.dc.html`/màn (hoặc gộp có anchor) + `README.md`: hi-fi, bảng Design Tokens dùng, bảng icon Remix, ánh xạ data-model theo mục trên, hành vi từng state (empty/loading/error/khóa theo gói/khóa theo cấp), biến thể desktop + mobile.

Bổ sung cho lượt này (audit 23/09):
- Dùng Chuẩn giao diện SaaS Starter (D0-2, D0-3): ma trận quyền dùng component "Ô quyền"; hộp xác nhận chuẩn.
- Người mới: màn Thành viên là màn chính (nhóm quyền là màn phụ); khi gán nhóm, mỗi nhóm có 1 dòng "làm được gì" và nút "Xem chi tiết" mở Màn 3; ma trận quyền mặc định hiện theo khu vực, gập các khu vực không liên quan.
- Màn 4 Thành viên: dùng khuôn "Mẫu DANH SÁCH dùng chung" (tìm + lọc nhóm + Dòng/trang; repo hiện không phân trang, không tìm); tab "Lời mời" để lượt D2-2.
- Backend đã có (23/09, commit e922240): đường ghi nhóm quyền tuỳ chỉnh có kiểm cấp/trần quyền — đọc `app/Modules/Rbac/Registry/PermissionRegistry.php` (24 quyền site, 6 nhóm mẫu) và `app/Modules/Rbac/Support/RoleHierarchyGuard.php` (luật cấp), **`app/Modules/Rbac/Support/CustomRoleRules.php`** (luật nhóm tuỳ chỉnh: trần theo gói + theo người sửa, cấp, tên trùng/dành riêng, chỉ xoá khi không còn ai giữ nhóm VÀ không còn lời mời đang chờ nào cấp nhóm đó — `in_use` phủ cả hai) + `PermissionRegistry::IMPLIES` (quyền kéo theo). Lỗi nghiệp vụ trả về mã `RoleChangeRejectedException::reason` — `custom_roles_disabled` · `name_invalid` · `name_reserved` · `name_taken` · `level_invalid` · `in_use` — README phải có câu đời thường cho TỪNG mã.
- Thứ tự: Màn 1 → 2 → 3 → 4. Sắp hết lượt sau Màn 3 thì chốt, ghi "D2-1 phần 2" (= Màn 4) vào TODO.md.
- Đăng ký vào registry Shell: `users`, `roles`. Handoff đặt tên `design_handoff_rbac`.
