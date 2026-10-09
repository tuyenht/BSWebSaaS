# D1-1s · Chuyên mục — tìm, lọc, chia tầng hành động, quyền và quy trình duyệt theo nhận xét của owner (chat mới)
═══ BỐI CẢNH CHUNG (giữ nguyên ở đầu mọi lượt) ═══
Dự án BSWebSaaS trong Claude Design. Trước khi làm: đọc CLAUDE.md, LESSONS.md, github.md, TODO.md; mở Admin Shell.dc.html (từ D0-5 trở đi chỉ cần registry `shell-screens.js` + phần Shell liên quan tới màn của lượt) và design system `_ds/bssaas-adminkit-design-system`; dùng lại nguồn chung ai-quota.js (BSAI) · content-workflow.js (BSCW) · content-ui.js (BSUI) · content-seo.js (BSSEO), và — khi đã có — `Chuẩn giao diện SaaS Starter.dc.html` (lượt D0-2, D0-3) + registry màn của Admin Shell (lượt D0-4b) + `SaaS Starter - Bản đồ màn hình.md` (lượt D0-1).
Sản phẩm: SaaS Starter — bộ khung quản trị đa-tenant BÁN ĐƯỢC cho nhiều loại website/SaaS: white-label, đa ngôn ngữ (tiếng Việt trước), phân quyền động, quy trình duyệt động (mặc định TẮT). Người dùng: từ chủ doanh nghiệp nhỏ lớn tuổi, chỉ biết đọc và dùng chuột/điện thoại, tới nhân viên văn phòng và người quản trị thạo việc.
Nguồn sự thật dữ liệu: GitHub tuyenht/BSWebSaaS@main — phần repo ĐÃ CÓ thì mở đúng file nêu trong prompt để lấy bảng/cột/quyền/trạng thái/mã lỗi thật; KHÔNG bịa.
THIẾT KẾ DẪN, BACKEND THEO (owner 29/09): màn cần tính năng mà repo chưa có thì cứ vẽ đầy đủ, gắn "Chờ backend" và ghi vào mục "Việc cho backend" của README (tính năng · dữ liệu cần lưu · luật) — backend sẽ được làm theo thiết kế. KHÔNG tự cắt tính năng, KHÔNG vẽ trạng thái tạm cho vừa backend. Khi tính năng đụng tách dữ liệu giữa các website/bảo mật, pháp lý (đồng ý, dữ liệu cá nhân) hoặc làm chậm danh sách hàng chục nghìn dòng: KHÔNG dừng lượt — vẽ phương án an toàn nhất (mặc định tắt/kín), gắn "Cần owner chốt" kèm 1 câu lý do trong README. Nhãn "Chờ backend"/"Cần owner chốt" là ghi chú thiết kế: gom dưới một Tweak "Hiện ghi chú thiết kế" (mặc định bật); tắt thì màn trông như bản thật.
MÀN ĐÃ CÓ THIẾT KẾ = SỬA TẠI CHỖ, KHÔNG VẼ LẠI: chỉ chạm đúng phần prompt nêu; giữ bố cục, dữ liệu mẫu, tương tác đang có. Chỉ màn chưa có mới được vẽ mới.
Hạn mức lượt: làm theo đúng thứ tự việc trong prompt; mỗi phần xong trọn mới sang phần sau. Nếu sắp hết lượt: chốt phần đang làm, ghi phần còn lại vào TODO.md với tên "<mã lượt> phần 2" — không bỏ dở một thành phần. Dòng TODO.md gắn "· <mã lượt này>" (lượt trước chuyển sang) cũng là việc của lượt này: làm xong thì đóng dòng.
Luật cứng:
- Màu: CHỈ DÙNG TOKEN, KHÔNG GẮN CỨNG MÃ HEX (owner chốt 04/10). Giá trị do tông "Bảo Sơn Hoàng gia (3b)" (owner duyệt 01/10) cấp qua `admin-base.css` + `--brand-solid`/`--brand-ink` của Shell; nguồn giá trị = CLAUDE.md §8/§16 của project (prompt không chép lại giá trị để khỏi lệch). Inter 14px · bo 4px ô nhập / 6px thẻ · 1 bóng thẻ · nền trang var(--color-body).
  · Chữ phụ: var(--color-body-muted) (≥4.5:1 trên trắng và trên nền trang). --color-body-faint CHỈ cho chi tiết trang trí, KHÔNG cho chữ có nghĩa.
  · Màu thương hiệu làm CHỮ / VIỀN = var(--color-accent) (= --brand-ink); VÒNG FOCUS = var(--focus-ring-color) (thường bằng --brand-ink; vùng nền đậm của Admin Shell tự đổi thành trắng); làm NỀN dưới chữ trắng = var(--color-admin-primary) (= --brand-solid); --brand chỉ để trang trí. Vàng thương hiệu var(--color-brand-gold) chỉ điểm nhấn; chữ vàng dùng var(--color-brand-gold-text).
  · Màu ngữ nghĩa 2 bậc: --color-admin-{secondary,success,info,warning,danger}-text (chữ + kiểu soft + viền, ≥4.5:1, lật theo sáng/tối) và -solid (nền dưới chữ trắng, ≥4.5:1; secondary nền #2f69d4, KHÔNG dùng #3577f1 làm nền chữ). Nút/badge warning solid dùng chữ #212529. Badge "dark" kiểu soft dùng chữ --color-admin-dark-text (lật sáng ở chế độ tối) (PR #92).
  · Viền ô nhập: --color-border-input (≥3:1 trên nền ô, cả sáng lẫn tối). Placeholder = --color-placeholder (= muted) và CHỈ chứa ví dụ ("0912 345 678"); hướng dẫn/điều kiện đặt ở dòng gợi ý dưới ô, không nhét vào placeholder (PR #92).
  · Focus = **Focus 1b** (chốt 05/10; luật chung trong `admin-base.css`, mô tả trong CLAUDE.md của project, mẫu ở `Focus - Đề xuất.dc.html`): nút viền và ô nhập: viền thành 2px màu vòng + quầng nhạt; nút đặc: viền trắng 2px bên trong + quầng; ghost, tab, liên kết: nền nhạt + viền 2px; công tắc, ô chọn: vòng tách quanh phần hình; ô nằm trong khung bao thì khung bao vẽ. Dùng lại luật chung, KHÔNG viết focus riêng từng màn, KHÔNG outline:none.
  · Sidebar: theo CLAUDE.md §16 của project; MỤC ĐANG CHỌN = nền nổi + vạch chỉ báo bên trái + aria-current="page" (phân biệt bằng HÌNH, không chỉ bằng màu).
  · Storefront: chữ màu thương hiệu trên nền sáng dùng --primary-text (tự làm tối tới ≥4.5:1 trên thẻ và trên nền trang); chữ màu thương hiệu trên nền tối --ink (link footer, menu con, nút phụ ở hero) dùng --primary-on-ink (tự làm sáng tới ≥4.5:1, PR #92); --primary giữ cho nền/viền.
  · File cũ còn mã màu gắn cứng — kể cả bộ PR #87 cũ (#405189 · #96a1c0 · #f3f3f9 · #6b6e7f) và màu trước PR #87 (chữ #878a99 / #adb5bd / #9aa1ac / #ced4da; nền dưới chữ trắng #3577f1; viền ô nhập #e9ebec): file nào lượt này sửa thì đổi hết sang token ở trên (chữ phụ → var(--color-body-muted); faint chỉ giữ cho chi tiết trang trí). Mọi mã màu hex gắn cứng khác ở phần lượt này chạm cũng đổi sang token, để chế độ tối và màu thương hiệu đúng. File lượt này không đụng thì để nguyên.
- Chính tả câu chữ trên màn theo Bảng thuật ngữ của design system (owner chốt 04/10): kiểu dấu cũ cho vần KHÔNG có phụ âm cuối oa · oe · uy: "Xóa · Hủy · Khóa · tùy" (không "Xoá · Huỷ · Khoá · tuỳ"); vần có phụ âm cuối viết như thường ("Hoàn tác", "Thoát"); chip lọc dùng "Bỏ lọc". Chữ cái đầu viết hoa theo vị trí (đầu nhãn/đầu câu viết hoa, giữa câu viết thường: "khóa theo gói"). Chữ trong prompt có thể viết kiểu khác — câu chữ trên màn vẫn theo luật này.
- Đích build: Livewire 4 + Blade + Tailwind 4 + <x-bs-admin::*> (tên nhóm component owner chốt 04/10); icon Remix inline-SVG (prototype dùng CDN được, README liệt kê tên icon). Không Bootstrap, không Flux.
- Mỗi màn đủ trạng thái: trống · đang tải · lỗi (có "Thử lại") · không có quyền · khóa theo gói · thành công. Không ngõ cụt.
- HIỂN THỊ ĐẠT TRÊN MỌI THIẾT BỊ (owner 30/09 — mọi màn, kể cả màn quản trị và nền tảng; màn đẹp trên máy tính mà vỡ trên điện thoại = CHƯA XONG):
  · Kiểm ở 320 · 360 · 390 · 768 · 1024 · 1280 · 1440px + điện thoại xoay ngang (844×390) × sáng + tối × cỡ chữ "Vừa" (mặc định) + "Cực lớn" + phóng to trình duyệt 200%; WCAG 2.2 AA.
  · Trình duyệt: Chrome/Edge, Safari iPhone/iPad, Firefox, Samsung Internet. Chiều cao dùng 100dvh (không 100vh); chừa safe-area (tai thỏ, thanh home). CSS mới (container query, :has, subgrid) phải chạy trên Safari iOS 16+, không thì có dự phòng.
  · Bố cục co theo KHUNG CHỨA (container query trên khung màn — màn còn được nhúng trong Shell), không theo cửa sổ. Quy tắc cho bề rộng hẹp phải THẮNG quy tắc rộng — kiểm độ ưu tiên CSS (lỗi thật: `Quy trình duyệt` ở 390px, nút "Đổi quy trình" không rộng hết vì `.qd-c4 .qd-btn{width:auto}` đè `.qd-btn{width:100%}`).
  · Bảng/hàng nhiều cột dưới 768px → THẺ GỌN, KHÔNG xếp mỗi cột thành một dòng: dòng 1 tên + trạng thái/công tắc bên phải · dòng 2 thông tin phụ nối bằng "·" · dòng 3 hành động (1 nút chính rộng hết, nút phụ vào menu "⋯"); khoảng cách dọc trong thẻ ≤ 12px. Công tắc luôn đứng cạnh tên việc nó bật/tắt, nhãn nói rõ việc ("Duyệt trước khi đăng · Đang bật"). 768–1023px: 2 cột hoặc bảng rút gọn, không phải 1 cột dài. Bảng dữ liệu thật sự cần nhiều cột thì cuộn TRONG khung bảng + cột tên dính trái (ngoại lệ có chủ đích của tiêu chí 8, chỉ cho bảng dữ liệu — thẻ và khung đọc vẫn không cuộn ngang); trang không bao giờ cuộn ngang.
  · Điện thoại: hộp thoại/bảng chọn = trượt từ đáy hoặc toàn màn; thanh Lưu dính đáy không che nội dung và ô đang nhập (bàn phím ảo); menu trái thành ngăn kéo; không gì chỉ hiện khi rê chuột (hover) — phải bấm được; vùng bấm ≥ 44px, cách nhau ≥ 8px.
  · Canvas giao nộp: mỗi màn có khung xem 390 và 768 cạnh khung máy tính; README có bảng "Kiểm hiển thị" (bề rộng × trình duyệt → đạt/chưa + lý do).
- Không hiện mã số nội bộ (id) — chỉ tên, đường dẫn, mã công khai. Không gắn cứng thương hiệu (tên nền tảng/website/"Phát triển bởi" lấy từ cấu hình).
- Tính năng ngoài gói (Q12 owner 24/09, bổ sung 29/09): website được cấp AI → nút AI (Dịch, Viết mới, Nâng cấp bài viết…) hiện bình thường, hạn ngạch theo §7/§7a (ai-quota.js). Nền tảng có công tắc "Lời mời nâng gói" (Nền tảng › Gói & tính năng — mặc định TẮT, owner bật khi đã dựng xong gói): TẮT → nút AI ẩn hẳn, module ngoài gói chỉ hiện khóa trung tính "Chưa bật cho website này — liên hệ hỗ trợ", không chào mua; BẬT → mọi tính năng ngoài gói (kể cả AI) hiện dạng khóa + 1 dòng lợi ích + nút "Xem gói nâng cấp" (mở Cài đặt › Gói & mức dùng). Vẽ cả hai bằng Tweak "Lời mời nâng gói".
- Ngôn ngữ nội dung viết phải-sang-trái (العربية có trong `config/locales.php`): ô nhập và nội dung của ngôn ngữ đó tự đặt dir="rtl" + căn phải (tiêu đề, nội dung, đường dẫn giữ trái-sang-phải); khung quản trị luôn trái-sang-phải.
- Dữ liệu động: nhóm quyền, quyền, loại nội dung, ngôn ngữ, gói, bước duyệt đều đọc từ registry/bảng — chịu được 1–20 mục, tên dài. Nhóm quyền, gói, chế độ duyệt, số ngôn ngữ mô phỏng bằng Tweaks đọc registry thật (LESSONS #18) — không bịa cờ kiểu canPublish.
- Hành động nguy hiểm: chuyển vào Thùng rác thì KHÔNG hỏi — làm ngay + toast "Hoàn tác" 10 giây; chỉ hỏi khi kéo theo mục khác ("Xóa mục này và 3 mục con") hoặc không hoàn tác được (xóa vĩnh viễn, tắt ngôn ngữ, đổi tên miền): hộp xác nhận giữa màn kiểu SweetAlert2, nút Hủy được focus, nút làm ghi đúng việc sẽ xảy ra.
- Mẫu DANH SÁCH dùng chung (đã có thiết kế; chuẩn đầy đủ = mẫu 1 của `Chuẩn giao diện SaaS Starter.dc.html` từ D0-3, phần build còn thiếu ghi ở đó): lấy khuôn của `Quản lý Bài viết - Options` (`Quản lý Trang - Options` chỉ để đối chiếu; code: `app/Modules/Admin/Livewire/Entries/Index.php`) — tiêu đề + 1 dòng mô tả + 1 nút chính · tab trạng thái có số đếm + tab Thùng rác · ô tìm + các nút lọc (danh sách tick có số; chân "Bỏ lọc" · "Xong") · dòng "Đang lọc: <chip ×> … Bỏ lọc · Khớp N / M" · chọn cột (cột bắt buộc khóa) · thanh "Đã chọn N": mỗi nút hàng loạt ghi số dòng nó làm được ("Đăng (3)"), dòng không làm được thì bỏ qua và nói lý do · dòng có tiêu đề + đường dẫn sửa tại chỗ, trạng thái bấm đổi được (chỉ các bước quy trình + quyền cho phép — BSCW) + "Hẹn giờ đăng", mã ngôn ngữ bấm để sửa bản dịch, người tạo (chữ cái đầu) · chân bảng "Dòng/trang" 10/20/50 + số trang. Màn danh sách mới CHỈ thêm cột/bộ lọc riêng của nó, không chế khuôn khác. Ngoại lệ phân trang: danh sách không giới hạn (nhật ký hệ thống, khách hàng/website của nền tảng) giữ "Dòng/trang" nhưng thay số trang bằng "‹ Trước · Sau ›" và không hiện tổng; dòng thông báo/hộp thư dạng dòng thời gian dùng "Tải thêm".
- Hành động trên dòng, cây và quyền (owner 06/10 — mọi danh sách, bảng, cây, lưới): (a) thao tác hay dùng hiện sẵn trên dòng, có chữ; thao tác hiếm / nâng cao / nguy hiểm vào menu "⋯" (biểu tượng + chú thích "Thao tác khác", không thêm chữ), các nhóm cách nhau bằng vạch ngăn, "Chuyển vào Thùng rác" hoặc "Xóa…" luôn đứng cuối, màu đỏ; dưới 768px khung chứa, dòng thành thẻ gọn: tiêu đề + 1 dòng phụ + "⋯" (mở bảng chọn trượt từ đáy), chạm thẻ là làm việc chính. (b) Cây (Chuyên mục, Trình đơn, Trang cha-con): ô tìm + chip lọc nhanh LUÔN hiện, như `Trình đơn.dc.html`; khi đang tìm hoặc lọc thì kết quả là danh sách phẳng có đường cha phía trước ("Tin tức › Sự kiện ›"), kéo-thả tắt kèm lý do. (c) Quyền: người KHÔNG có quyền làm việc đó thì ẩn nút; có quyền nhưng đang bị chặn (bước quy trình duyệt, phiên hỗ trợ, người khác đang sửa, đã tới giới hạn) thì hiện nút khóa + 1 câu lý do đọc được bằng chạm và bàn phím, không chỉ nằm trong title khi rê chuột; ngoài gói thì theo công tắc "Lời mời nâng gói". Đổi Tweak nhóm quyền / chế độ duyệt / gói phải đổi đúng bộ nút. (d) Bài thuộc loại nội dung đang BẬT quy trình duyệt không được rời loại đó (owner chốt 07/10): đổi chuyên mục chính, chuyển / gộp / xóa chuyên mục kèm bài sang loại khác → chỗ đích của loại khác khóa + lý do, không có nút xác nhận để vượt. Loại không bật quy trình thì cho chuyển, hộp xác nhận nói trước hệ quả quyền / quy trình duyệt bằng số. (e) Màn ĐÃ có thiết kế (owner 07/10 về D1-6: "đang tốt mà chỉnh không tốt hơn mà lại còn kém hơn"): GIỮ bố cục, tab, ô lọc / ô chọn, nút và vị trí đang có. Chỉ THÊM phần còn thiếu theo các luật trên. Không gộp nhiều ô lọc thành 1 nút "Lọc" trên máy tính, không bỏ tab, không dời nút chính ra chỗ khác. Chỗ nào bản mới ưu việt hơn sau khi so kỹ thì cũng GIỮ. README ghi bảng "Màn đang chạy · Bản mới · Giữ bản nào · Vì sao". Muốn bớt hoặc đổi chỗ một điều khiển có sẵn thì ghi "Cần owner chốt", không tự làm.
TIÊU CHÍ DỄ DÙNG — kiểm từng màn trước khi giao, ghi kết quả (đạt/chưa + lý do) vào README:
 1. Hiểu trong 5 giây: tiêu đề + 1 dòng mô tả nói màn này để làm gì; người mới biết bấm gì tiếp. Tối đa 1 nút chính nổi bật, và CHỈ khi prompt ghi tên nút đó hoặc màn có đúng 1 việc chính (danh sách → "Thêm …", biểu mẫu → "Lưu"). Màn tổng quan, bảng điều khiển, trang trạng thái: không nút chính ở đầu trang. Không tự thêm nút tạo hay nút chính mà prompt không ghi (owner 07/10, màn Tổng quan).
 2. Việc chính của màn xong trong ≤ 3 bước từ menu, không cần đọc hướng dẫn.
 3. Không thuật ngữ kỹ thuật trần (SMTP, OAuth, DNS, CNAME, SSL, API, webhook, token, slug, meta, robots…). Buộc phải có thì để trong khối "Nâng cao" và kèm 1 câu giải thích đời thường. Tên thương hiệu quen (Google, Zalo, Facebook) giữ nguyên nhưng nói rõ công dụng ("Facebook Pixel — đo hiệu quả quảng cáo Facebook").
 4. Icon kèm chữ hiện trên màn, trừ biểu tượng ai cũng hiểu (owner 07/10): đóng ✕, tìm, quay lại, mũi tên trước/sau và mở/gập, hiện/ẩn mật khẩu, xóa chữ trong ô, ☰ menu, chuông (kèm số), ảnh đại diện tài khoản, sáng/tối, cờ ngôn ngữ, toàn màn hình, (?) trợ giúp, định dạng chữ quen như Word (B, I, U, danh sách, liên kết), bút chì sửa nhanh ngay cạnh chữ nó sửa, và nút ⋮ / ⋯ menu thao tác khác (KHÔNG thêm chữ "Khác"). Biểu tượng đứng riêng phải có chú thích khi trỏ chuột (`title`) + `aria-label` nói rõ việc ("Thao tác khác cho “<tên>”") + vùng bấm ≥ 44px; trên điện thoại (không có chú thích khi trỏ) menu bật lên có dòng tiêu đề nói rõ đang thao tác với gì. Thao tác đổi dữ liệu (Lưu, Đăng, Xóa, Gửi duyệt…), việc riêng của sản phẩm (Hẹn giờ, Dịch, Duyệt) và nút lọc luôn có chữ.
 5. Ô nhập có gợi ý bằng ví dụ thật; lỗi nói cách sửa ("Số điện thoại cần 10 số, ví dụ 0901 234 567"), không nói lỗi kỹ thuật.
 6. Không mất việc: tự lưu nháp, chặn rời trang khi chưa lưu, xóa = vào Thùng rác kèm toast "Hoàn tác"; hộp xác nhận nói rõ hệ quả bằng số ("Xóa mục này và 3 mục con"). Backend hiện chỉ có Thùng rác cho bài, trang, sản phẩm, ảnh & tệp — loại khác mà màn cần Thùng rác thì VẪN vẽ, ghi "Chờ backend" + mục Việc cho backend (không tự bỏ).
 7. Trạng thái luôn có màu + hình + chữ — không bao giờ chỉ dùng màu.
 8. Không cắt chữ: nhãn nút, chip, tab, nút lọc luôn 1 dòng (dài thì "…" + tooltip) — nút KHÔNG bao giờ xuống 2 dòng; nhãn ô nhập được xuống dòng nhưng không bị che. Không thanh cuộn ngang trong thẻ/khung đọc.
 9. Điện thoại & máy tính bảng: MỌI việc trên màn làm trọn được ở 360–390px và 768px, việc hay làm (trả lời khách liên hệ, duyệt bài, đăng tin nhanh, xem thông báo) thuận tay nhất; vùng bấm ≥ 44px; không thành phần nổi nào che nút Lưu hay nội dung (ngoại lệ, owner 09/10: bong bóng gợi ý lần đầu của Hướng dẫn hiện như một hộp thoại có nền mờ nên được phép che).
10. Hai tầng người dùng: mặc định gọn cho người mới (tùy chọn nâng cao gập lại); người thạo có phím tắt, thao tác hàng loạt, lọc nhanh — nhưng không làm rối tầng người mới.
11. Cỡ chữ theo người (owner 07/10): mặc định "Vừa" = chữ nội dung 14px, chú thích / diễn giải 12–13px (không chữ nào dưới 12px); mỗi người tự chọn Lớn · Rất lớn · Cực lớn (+2 / +4 / +6px), lưu theo tài khoản. MỌI chữ đi theo token cỡ chữ của Shell (`calc(<cỡ>px + var(--fsp))`), không cỡ px cố định; ở "Cực lớn" và 320px không vỡ, không tràn, vùng bấm vẫn ≥ 44px.
12. Dùng đúng từ trong "Bảng thuật ngữ" của Chuẩn giao diện (D0-3): cùng một việc luôn cùng một từ ở mọi màn.
CHẤT LƯỢNG HOÀN THIỆN (owner 06/10 — áp mọi màn, cùng mức với 12 tiêu chí trên; đích: đẹp · hiện đại · trực quan; người mới, người không rành CNTT, người lớn tuổi dùng được mà không cần đọc hướng dẫn; đẹp trên mọi thiết bị và trình duyệt):
 A. Đẹp & nhất quán: khoảng cách theo thang 4/8px, cỡ chữ theo thang của DS (không thêm cỡ lẻ mới); mỗi vùng tối đa 1 nút chính, không tự thêm; căn thẳng hàng; cùng một việc = cùng component, cùng vị trí ở mọi màn. Chuyển động 150–250ms và tắt khi `prefers-reduced-motion`; nội dung không giật khi tải (giữ chỗ cho ảnh, số đếm); khung đang tải hiện trong 300ms.
 B. Người lớn tuổi & người mới: ở cỡ mặc định "Vừa": chữ nội dung 14px, chú thích và diễn giải 12–13px, không chữ nào dưới 12px, giãn dòng ≥ 1,5; người cần chữ to tự chọn Lớn / Rất lớn / Cực lớn trong Tài khoản (owner 07/10); nhãn ô luôn hiện (không chỉ có placeholder); mặc định hợp lý — để nguyên vẫn chạy đúng; chỗ khó hiểu có nút (?) giải thích 1 câu đời thường. Không việc nào CHỈ làm được bằng kéo-thả, nhấn giữ, vuốt, nhấp đúp hay rê chuột — luôn có nút làm được việc đó. Mọi thao tác có phản hồi trong 1 giây ("Đang lưu…", toast), chặn bấm 2 lần. Không giới hạn thời gian ngầm: sắp hết phiên đăng nhập thì báo trước và cho gia hạn. Mạng chậm / mất mạng: báo bằng lời thường, giữ chữ đã nhập, có "Thử lại".
 Hành động chia 2 tầng (owner 06/10): việc hay làm (Sửa, Thêm mục con, xem số liệu, Lưu) HIỆN SẴN, có chữ; việc ít làm / nâng cao / nguy hiểm vào menu "⋯" (biểu tượng + chú thích) theo nhóm có vạch ngăn, Xóa đứng cuối màu đỏ — không việc hay làm nào chỉ nằm trong "⋯". Mọi danh sách và cây có ô tìm + Lọc LUÔN hiện, không đợi nhiều mục mới hiện.
 C. Bàn phím & trình đọc màn hình (WCAG 2.2 AA): thứ tự Tab theo thứ tự đọc; Esc đóng hộp thoại; mở hộp thì focus vào trong, đóng thì trả focus về nút đã mở; đúng 1 h1 và cấp tiêu đề đúng; ô nhập có nhãn thật; toast và trạng thái lưu có `aria-live`; hộp thoại có tên.
 D. Chế độ tối & trình duyệt: chế độ tối chỉ dùng token — nền trắng / tối viết thẳng dưới chữ theo token là LỖI (Claude Code đo 06/10: Email 1,12:1, Trình đơn 1,16:1). Ô chọn, ô tích, ô ngày trông giống nhau trên Chrome, Safari, Firefox (vẽ trạng thái đóng, giữ bộ chọn gốc trên điện thoại). Chữ tiếng Việt có dấu hiện đúng cả khi rơi về font dự phòng. Nhãn dài hơn 40% (bản dịch) không vỡ bố cục.
 E. Đo thật, không tự khai: trước khi ghi "đạt", đo trong `_audit/harness.html` bằng getComputedStyle — (1) tương phản mọi chữ có nghĩa ở CẢ sáng lẫn tối (`data-bs-theme="dark"`) ≥ 4,5:1 (chữ ≥ 24px hoặc ≥ 18,66px đậm: ≥ 3:1); (2) vùng bấm ≥ 44px ở 390; (3) Tab / Esc / trả focus của mọi hộp thoại; (4) mở lại từng tệp đã sửa và Admin Shell — console 0 lỗi (nhất là "logic class eval FAILED": sửa JS xong phải chạy lại). Tệp đo / ảnh tạm chỉ đặt trong `_audit/tmp/`, không tạo thư mục mới ở gốc project; xóa sạch `_audit/tmp/` trước khi xuất zip (mỗi zip đang mang thêm khoảng 14 MB tệp tạm). Không đo được thì ghi [CHƯA KIỂM ĐƯỢC] kèm lý do, không ghi "đạt". README có mục "Kết quả Chất lượng hoàn thiện" (A–E: đạt/chưa + số đo).
Nhúng & điều hướng: panel Cài đặt tuân hợp đồng nhúng §13 (embedded · onDirty · embedId · bs-embed-cmd — không tự vẽ header/nút Lưu); màn toàn trang (danh sách, trình soạn, hộp thư, thư viện) nhận embedded nhưng chỉ ẩn phần trùng Shell (breadcrumb, tiêu đề trang, khung 100vh), GIỮ thanh thao tác + nút quy trình của chính màn, báo onDirty, nhận bs-embed-cmd {act:'save'}; đăng ký vào registry màn của Admin Shell (sau D0-4b) với trạng thái "ready", chuyển màn bằng sự kiện bs-goto {screen, params}; cập nhật dòng tương ứng trong `SaaS Starter - Bản đồ màn hình.md`. Gắn màn mới vào Shell: ngoài registry còn phải thêm khối gắn trong `Admin Shell.dc.html` (1 `<sc-if>` + `dc-import … embedded` + cờ và props của nó, theo mẫu khối "Chuyên mục" của D1-1) — được sửa Admin Shell CHỈ cho khối này, kể cả khi dòng "Được sửa" ghi không sửa Admin Shell. Từ D0-8 trở đi (Shell đã có cụm đầu màn "Hướng dẫn" + nút chính): màn nhúng có nút tạo chính thì báo nút đó qua `bs-embed-hdr {id, primary: {label, title, icon?, menu?, locked?} | null}` để Shell vẽ, không tự vẽ nút chính ở đầu màn; màn mới có danh sách `help` trong registry và một bộ bước trong `GUIDES` của `guide.js` (cách làm ở D0-8 phần 3).
Giao nộp: 1 file .dc.html mỗi màn (hoặc gộp có anchor) + README handoff theo mẫu: Tổng quan · File · Mức hoàn thiện · Màn & trạng thái · Tương tác · Ánh xạ dữ liệu (file:dòng repo) · Quyền & gói (ai thấy gì) · Token · Icon · Chuỗi cần dịch · Kết quả 12 tiêu chí dễ dùng · Kết quả Chất lượng hoàn thiện (A–E) · Kiểm hiển thị (bề rộng × trình duyệt) · Việc cho backend.
Không sửa CLAUDE.md (chỉ owner sửa). Prompt khác CLAUDE.md ở điểm nào thì lượt này làm theo prompt (owner đã duyệt) và ghi 1 dòng LESSONS.md nêu điểm khác để owner sửa CLAUDE.md; bài học mới cũng ghi 1 dòng vào LESSONS.md. Làm xong gọi ready_for_verification và sửa hết lỗi verifier báo. Lượt này làm ĐÚNG MỘT việc bên dưới.
═══ VIỆC CỦA LƯỢT NÀY ═══

# D1-1s · Chuyên mục — tìm, lọc, chia tầng hành động, quyền và quy trình duyệt theo nhận xét của owner (chat mới)

Sửa trên `Chuyên mục.dc.html` hiện có. Prompt này THAY mục 1 và dòng "Xong khi (1)" của prompt D1-1 ở mọi chỗ khác nhau: bỏ "Lọc (… · cấp)", bỏ "Đưa vào trong…". Sửa xong thì xuất zip.

Chạy ở CHAT MỚI vì chat D1-1 cũ đã quá dài. Prompt này tự đủ, không cần chat cũ.
- Sửa trên tệp HIỆN CÓ trong project, không theo trí nhớ về bản cũ. Trước khi sửa: mở tệp màn cần sửa và README của lượt D1-1 (tên ở dòng "Chỉ sửa" bên dưới). Prompt gốc của lượt D1-1 ở repo: `docs/design-briefs/saas-starter-design-first/prompts/D1-1-chuyen-muc-taxonomy.md` — đọc khi cần đối chiếu.
- Giờ zip và số dòng nêu dưới đây là của bản Claude Code đã kiểm trước đó; lệch thì tìm theo nội dung. Mục nào tệp hiện có đã đúng sẵn thì giữ nguyên và ghi "đã có" trong README.
- Giữ nguyên mọi thứ D0-7 đã làm: cỡ chữ viết bằng `calc(… + var(--fsp))` theo 4 mức Vừa · Lớn · Rất lớn · Cực lớn; không thêm cỡ chữ px cố định mới; không chữ nào dưới 12px.

Owner xem `Chuyên mục.dc.html` và chưa ưng ở 3 điểm:
- chưa có tìm kiếm và bộ lọc;
- thao tác hay dùng phải thấy và bấm được ngay;
- chỉ thao tác nâng cao mới vào menu "⋯".

Owner cũng yêu cầu rà kỹ từng nút theo 12 tiêu chí và Chất lượng hoàn thiện A–E, kể cả quyền và quy trình duyệt. Claude Code đã dựng bản zip 23:44 trong Chromium, đối chiếu với repo và với `Trình đơn.dc.html` (màn cây đã chốt), và cho một người rà độc lập phản biện. Mỗi quyết định dưới đây đã qua phản biện; phần "vì" giải thích lý do để không đổi ngược lại.

Owner đã duyệt 07/10 (21:2x) 4 thay đổi điều khiển đang có, nên KHÔNG hỏi lại và không ghi vào "Cần owner chốt": (1) 2 nút "Mở tất cả" / "Thu gọn tất cả" thành 1 nút tự đổi chữ; (2) "Đưa vào trong…" và "Đưa ra ngoài một cấp" gộp vào "Chuyển đến…" (phím Alt+→ / Alt+← làm như Trình đơn — màn này chưa có thì THÊM); (3) dãy chip ngôn ngữ trên dòng thành trạng thái bằng chữ ở đúng chỗ đó; (4) menu "⋯" của chuyên mục gốc: 6 mục khóa thành 1 dòng giải thích. README ghi 4 dòng này vào bảng quyết định với "Owner duyệt 07/10".

Cách sửa:
- SỬA TẠI CHỖ: giữ panel Sửa/Thêm, hộp Xóa (đã tốt), dữ liệu mẫu, luật as-built, hộp "Chuyển đến…" sẵn có và câu lý do khóa đang hiện ngay dưới mục bị khóa trong menu.
- Chỉ sửa `Chuyên mục.dc.html`, `README - D1-1 Chuyên mục.md`, `TODO.md`, `LESSONS.md`.

1. **Thanh công cụ trên cây.** Luôn hiện, kể cả khi chỉ có 1 chuyên mục; chỉ ẩn khi cây trống (đã có trạng thái "Tạo chuyên mục đầu tiên"). Cách cư xử chép theo Trình đơn: một nút "Bỏ lọc" xóa cả chữ tìm lẫn chip; một dải ghi chú dưới thanh khi đang lọc. Vùng bấm: mọi ô, chip, nút ≥ 44px ở MỌI bề rộng, cả chuột lẫn cảm ứng.
   - **Ô tìm** "Tìm chuyên mục…" (có tên cho trình đọc màn hình):
     - Khớp tên ở mọi ngôn ngữ đang bật và đường dẫn.
     - Không phân biệt dấu, và "đ" khớp "d": "khuyen mai" ra "Khuyến mãi", "gia cong" ra "Gia công CNC" và "Kiến thức gia công".
     - Khớp ở ngôn ngữ khác thì ghi thêm dòng nhỏ "EN: Machining…".
     - Kết quả là danh sách phẳng; mỗi dòng có đường cha phía trước, không tính gốc ("Kiến thức gia công ›"); tô đậm phần chữ khớp.
     - Không có kết quả thì hiện "Không có chuyên mục nào khớp "…"" + nút "Bỏ lọc".
   - **Chip lọc nhanh**, chọn 1; mỗi chip có biểu tượng + chữ + số, số tính trên cả cây:
     - "Tất cả" · "Chưa có bài" · "Chưa dịch", số đếm đúng theo dữ liệu mẫu (ví dụ 3 ngôn ngữ: "Chưa dịch" gồm cả bản lỗi thời).
     - "Chưa dịch" gồm bản trống, thiếu trường, lỗi thời và AI vừa dịch chưa kiểm. Ẩn chip này khi website có 1 ngôn ngữ.
     - Vì sao không dùng "Lọc ▾" nhiều nhóm và không lọc theo Cấp: cây chỉ có 2 câu hỏi thật ("mục nào trống?", "mục nào chưa dịch?"); 1 chạm dễ hơn mở bảng chọn; cấp đã thấy qua thụt lề.
   - **Bên trái thanh:** "8 chuyên mục".
   - **Khi đang tìm hoặc lọc:** dải ghi chú "Đang xem N / M chuyên mục lọc ra từ cả cây — bỏ lọc để sắp xếp lại thứ tự · Bỏ lọc".
   - **Thu gọn / mở:** 1 nút đổi chữ, thay 2 nút đang có (vì trên 390, 2 nút đang chiếm 2 hàng 44px).
     - Ghi "Mở tất cả" khi có mục đang gập, còn lại ghi "Thu gọn tất cả".
     - Ẩn nút khi đang lọc, hoặc khi không mục nào có mục con.
   - **Khi đang tìm hoặc lọc:**
     - Chỉ ẩn tay cầm kéo.
     - Chỉ khóa Chuyển lên / Chuyển xuống / Alt+↑↓, kèm câu "Bỏ lọc để sắp xếp lại thứ tự".
     - "Chuyển đến…", Gộp, Sửa, + Mục con, Xóa vẫn dùng được (giống Trình đơn).
   - **Điện thoại** (khung chứa < 600px): ô tìm rộng hết hàng; chip xuống dòng, không cuộn ngang.
   - **Không làm "Chọn nhiều":** cây thường dưới 30 mục, mỗi thao tác đã ≤ 2 bước, Trình đơn cũng không có. Ghi README "Chưa làm — thêm khi số liệu thật cho thấy cần".
2. **Dòng trên máy tính và tablet** (khung chứa ≥ 720px), từ trái sang phải. Cây là danh sách dòng, không phải bảng nhiều cột, nên dùng mốc riêng 600 / 720px của prompt này thay cho mốc "thẻ gọn dưới 768px" của bối cảnh chung:
   - Tay cầm ⠿: chỉ khi có chuột; dòng gốc không có tay cầm.
   - Nút mở/gập.
   - Tên (bấm là mở Sửa) + đường dẫn mờ bên dưới.
     - Tên dài xuống tối đa 2 dòng rồi mới "…".
     - Tên nút đọc được: "<tên>, cấp N, 24 bài".
     - Không đẩy số bài hay nút xuống dòng.
   - Nhãn "Gốc" giữ nguyên.
   - **Số bài là liên kết** "24 bài":
     - Mở danh sách bài đã lọc ĐÚNG những bài được đếm (cùng luật đếm ở D1-1 mục 6), qua `bs-goto`. Params gồm loại nội dung + đường dẫn ở ngôn ngữ mặc định, không dùng id.
     - 0 bài thì là chữ mờ "Chưa có bài", không phải liên kết, vì liên kết tới danh sách rỗng là ngõ cụt.
     - Sản phẩm ghi "12 sản phẩm" / "Chưa có sản phẩm".
     - Việc cho backend: danh sách bài hiện CHỈ lọc theo chuyên mục gốc của loại (`app/Modules/Admin/Livewire/Entries/Index.php:850-866`), nên bài ở chuyên mục con không hiện ở danh sách nào. Cần lọc theo từng chuyên mục.
   - **Trạng thái ngôn ngữ bằng CHỮ** (website nhiều ngôn ngữ), thay dãy chip VI/EN/JA trên dòng:
     - Một nút chữ mờ: "Đủ 3 ngôn ngữ", hoặc nêu cụ thể "Thiếu JA", "Thiếu JA · EN lỗi thời". Bấm là mở panel Sửa ở ngôn ngữ đầu tiên còn thiếu.
     - Vì chip chỉ khác nhau bằng màu (WCAG 1.4.1, tooltip chỉ hiện khi rê chuột), và 8 ngôn ngữ thì không đủ chỗ.
     - Chấm màu giữ trong panel Sửa đúng như CLAUDE.md §3 của project.
   - **Nút "Sửa"** có chữ, HIỆN SẴN.
   - **Nút "+ Mục con"** có chữ, thay nút "+" chỉ có biểu tượng.
     - Mục cấp 3 không có nút này, chỗ trống giữ nguyên để cột thẳng hàng.
     - Lý do nằm ở mục khóa trong "⋯", vì 1 nút khóa lặp trên mọi dòng cấp 3 là nhiễu.
   - Nút "⋯".
   - Khung 600–719px: trạng thái ngôn ngữ xuống dòng phụ dưới tên; "Sửa" và "+ Mục con" vẫn hiện (việc hay làm luôn hiện, theo bối cảnh chung).
   - **Dòng gốc** (Tin tức, Dịch vụ…): có Sửa, + Mục con, "N bài", "⋯"; không có tay cầm.
3. **Menu "⋯"** gồm các nhóm cách nhau bằng vạch ngăn. Trên máy tính menu không lặp lại nút đã hiện sẵn trên dòng; riêng mục «Xem bài trong chuyên mục» đang có thì GIỮ trong menu (cùng việc với liên kết "N bài", không gỡ):
   - Xem trên website (mở tab mới) · Sao chép đường dẫn.
     - Sao chép xong có toast "Đã sao chép". Không chép được thì hiện ô chứa địa chỉ đã bôi đen sẵn.
     - Vì chủ shop hay dán link chuyên mục vào Zalo / Facebook.
   - Chuyển lên (Alt+↑) · Chuyển xuống (Alt+↓) · Chuyển đến…
     - "Chuyển đến…" thay cả "Đưa vào trong…" lẫn "Đưa ra ngoài một cấp", và dùng lại hộp chọn chỗ đã có (hiện tiêu đề `Đưa "{{ mvName }}" vào trong…`, đổi thành tiêu đề bên dưới).
     - Tiêu đề hộp "Chuyển "Vật liệu" đến đâu?". Chỗ đặt liệt kê theo cây.
     - Trên màn của một loại nội dung, chỗ cao nhất là "Ngay dưới Tin tức". "Cấp ngoài cùng" CHỈ có ở Sản phẩm.
       - Vì chuyên mục ra khỏi gốc thì không thuộc loại nào: không màn nào hiện nó, và bài của nó rơi khỏi mọi danh sách.
       - Kéo ra ngang hàng gốc = chỗ thả không hợp lệ.
       - Alt+← ở cấp 2 báo "Đã ở cấp ngoài cùng của Tin tức".
     - Chỗ không đủ cấp thì khóa, kèm lý do "Chỉ được lồng tối đa 3 cấp".
     - Chỗ thuộc loại nội dung khác xếp dưới tiêu đề nhóm của loại đó, CHỈ những loại người dùng có quyền. Khi loại hiện tại đang bật quy trình duyệt và nhánh có bài, các chỗ của loại khác KHÓA kèm lý do (mục 5).
     - Vì 1 lệnh rõ nghĩa dễ hiểu hơn 2 lệnh "vào trong / ra ngoài" với người lớn tuổi; phím Alt+→ / Alt+← vẫn giữ như Trình đơn.
   - Gộp vào chuyên mục khác…
     - Không gộp được vào chính mục con của nó.
     - Hộp gộp nói rõ mục con đi đâu: "2 mục con chuyển lên dưới "Tin tức" (không vào "X")". Repo xóa chỉ đưa mục con lên cấp trên hoặc xóa theo (`app/Modules/Cms/Actions/Taxonomies/DeleteTaxonomy.php:31-33`).
     - Hộp gộp không có lựa chọn "Xóa theo".
   - Xóa…: luôn cuối, chữ và biểu tượng màu đỏ (token danger, đo tương phản ≥ 4,5:1 cả sáng lẫn tối).
   - **Chuyên mục gốc:** menu chỉ có nhóm Xem + 1 dòng chữ "Chuyên mục gốc của Tin tức: luôn ở cấp 1, không chuyển, gộp hay xóa được — đổi tên thì bấm Sửa." Bỏ 6 mục khóa đang hiện, vì lặp cùng một lý do 6 lần.
   - **Điện thoại:** "⋯" mở bảng chọn trượt từ đáy, tiêu đề là tên chuyên mục. Thứ tự: Xem 24 bài | Xem trên website · Sao chép đường dẫn | Chuyển lên · Chuyển xuống · Chuyển đến… | Gộp… | Xóa…. Với gốc: + Mục con · Xem bài | nhóm Xem | dòng lý do.
4. **Dòng trên điện thoại** (khung < 600px):
   - Tên + 1 dòng phụ "/kien-thuc · 24 bài". Dòng phụ chỉ thêm "· Thiếu JA" khi còn thiếu bản dịch, để không bị gãy.
   - Nút chữ "Sửa" 44px + "+ Mục con" 44px HIỆN SẴN (trừ cấp 3) + nút "⋯" 44px, cùng một hàng gọn bên phải, giống thẻ Trang của bản mẫu đã duyệt ở repo `docs/design-briefs/saas-starter-design-first/reference/d0-8/` ("Sửa · Trang con · ⋮").
   - Chạm vào chỗ trống của dòng cũng mở Sửa. Đo bản 23:44: chỉ chữ tên 21px bấm được, chạm chỗ trống của dòng không làm gì.
5. **Đổi chỗ, đổi cha, gộp và xóa: chỉ hỏi khi có hệ quả thật, và nói bằng số.**
   - **Đổi thứ tự trong cùng cha** (kéo, Chuyển lên/xuống, Alt+↑/↓): làm ngay, không hỏi.
     - Toast "Đã chuyển" có "Hoàn tác" (aria-live), đứng ≥ 10 giây, dừng đếm khi rê chuột hoặc focus vào. Ctrl+Z hoàn tác như Trình đơn.
     - Bấm Alt+↑ nhiều lần liền thì chạy lần lượt, không mất lần nào.
   - **Đổi cha trong cùng loại** (kéo, Chuyển đến…, ô "Chuyên mục cha" trong panel):
     - Nếu kiểu đường dẫn bài của loại có chứa chuyên mục và nhánh có bài: hỏi 1 lần "Đổi địa chỉ 14 bài và tự tạo chuyển hướng để link cũ vẫn mở được".
     - Website đường dẫn phẳng (mặc định) thì đổi cha không đổi địa chỉ bài nào (`app/Modules/Cms/Routing/CategoryRelinker.php:24-25`): làm ngay + toast có "Hoàn tác".
     - Thêm Tweak "Kiểu đường dẫn bài: phẳng / có chuyên mục" để vẽ đủ 2 trường hợp.
     - Số bài đổi địa chỉ cần truy vấn xem trước: ghi Việc cho backend.
   - **Ra khỏi loại nội dung** (đổi cha, gộp, hay xóa kèm chuyển bài / "Bỏ gắn" làm bài không còn thuộc loại cũ). Owner chốt 07/10: bài đang theo quy trình duyệt KHÔNG được rời loại nội dung của nó. Cùng luật với trình soạn bài: `app/Modules/Admin/Livewire/Entries/Editor.php::keepsGovernedSection`, câu repo `entries.category_governed`.
     - **Loại hiện tại đang bật quy trình duyệt và nhánh có bài: CHẶN, không cho xác nhận để vượt.**
       - Trong "Chuyển đến…", ô "Chuyên mục cha", danh sách đích của Gộp / Xóa: mọi chỗ thuộc loại khác khóa.
       - Kéo sang cây loại khác là chỗ thả không hợp lệ.
       - Trong hộp Xóa, lựa chọn "Bỏ gắn" khóa.
       - Lý do đọc được ngay tại chỗ: "Tin tức đang bật quy trình duyệt nên bài không chuyển sang loại khác được. Chọn chuyên mục trong Tin tức, hoặc nhờ người quản trị tắt quy trình cho Tin tức nếu thật sự cần chuyển."
       - Nhánh không có bài thì chuyển tự do.
     - **Loại hiện tại không bật quy trình duyệt:** cho chuyển, kèm hộp xác nhận thêm các dòng sau, mỗi dòng CHỈ hiện khi đúng:
       - Khi 2 loại khác quyền hoặc khác quy trình duyệt: "N bài sẽ chuyển từ Tin tức sang Dịch vụ — quyền và quy trình duyệt sẽ theo Dịch vụ."
       - Khi Dịch vụ đang bật quy trình duyệt: "K bài chưa đăng sẽ bắt đầu từ bước đầu của quy trình Dịch vụ; bài đã đăng vẫn hiện trên website." Repo: `app/Modules/Cms/Support/EntryWorkflowResolver.php::originFor`.
       - Làm xong, dòng rời khỏi cây này: toast "Đã chuyển sang Dịch vụ · Mở Chuyên mục Dịch vụ", focus về dòng kế tiếp.
     - Việc cho backend:
       - ĐÃ LÀM (PR #207, 07/10): `MoveTaxonomy` / `DeleteTaxonomy` từ chối bằng mã `workflow_governed` khi loại đang bật quy trình duyệt, kể cả khi đã xác nhận; không vượt được. Còn thiếu câu riêng cho mã này ở màn Chuyên mục: thêm vào README mục Chuỗi cần dịch.
       - Số N, K cần truy vấn xem trước.
   - **Chuyên mục không qua quy trình duyệt** (repo không có trạng thái cho chuyên mục): mọi thay đổi có hiệu lực ngay và ghi nhật ký. Ghi dòng này vào README phần luật.
   - **Lỗi:**
     - Người khác vừa đổi cây (`taxonomy_invalid`): câu đời thường + nút "Tải lại".
     - Lưu hỏng hoặc mạng chậm: giữ nguyên chữ đã gõ, báo bằng câu đời thường + "Thử lại"; dòng đã kéo về chỗ cũ.
   - **Điện thoại:** hộp Xóa và hộp Gộp mở toàn màn hoặc trượt từ đáy, nút ghim ở đáy.
6. **Quyền theo loại nội dung, gói và phiên hỗ trợ** (Tweak đổi được, đọc registry thật):
   - Màn cần quyền `taxonomies.manage`. Mặc định Chủ website, Quản trị, Biên tập có quyền này (`app/Modules/Rbac/Registry/PermissionRegistry.php:191-197`).
     - Thiếu quyền thì menu không hiện mục Chuyên mục (`app/Modules/Admin/Navigation/AdminNav.php:117`).
     - Mở thẳng địa chỉ màn thì hiện "Bạn chưa có quyền quản lý chuyên mục — nhờ Chủ website hoặc Quản trị cấp quyền". Không hiện cây.
   - Quyền bài đã tách theo loại nội dung (`content_sections.permission_prefix`, `AdminNav.php:83`):
     - "Chuyển đến…", ô "Chuyên mục cha" và chuyên mục đích khi xóa / gộp chỉ liệt kê loại mà người dùng có quyền.
     - Việc cho backend (làm ngay, không phải "sau này"): chuyển bài sang loại khác phải kiểm quyền ở CẢ hai loại. `MoveTaxonomy.php` để việc kiểm quyền cho nơi gọi.
   - Liên kết "N bài" mở danh sách bài; danh sách đó tự giới hạn theo quyền xem của người bấm.
   - Phiên hỗ trợ (nhân viên đăng nhập thay thành viên):
     - Tìm, lọc, xem vẫn dùng được.
     - Mọi nút ghi (Thêm, Lưu, + Mục con, Chuyển…, Gộp, Xóa, kéo) khóa, kèm câu repo `admin.support.locked`.
   - Sản phẩm khi module tắt theo gói: dùng trạng thái khóa theo gói đã có.
7. **Panel Sửa / Thêm** (giữ bố cục):
   - Thanh ngôn ngữ đang cắt chữ "Tiếng" ở panel 480px: hiện đủ tên, hoặc mã + chấm. Không cắt chữ ở 360 và ở cỡ chữ Cực lớn (CLAUDE.md §4: không bao giờ để pill bị cắt nửa chữ).
   - Hộp vàng "Đổi đường dẫn… tự tạo chuyển hướng" chỉ hiện khi ô Đường dẫn ĐÃ khác bản đã lưu.
   - Khi Sửa, đổi tên KHÔNG tự đổi đường dẫn; muốn đổi thì bấm "Tạo lại từ tên".
   - Ô "Chuyên mục cha":
     - Nhóm theo loại nội dung (`<optgroup>`), chỉ loại người dùng có quyền.
     - Chọn chỗ của loại khác thì khi Lưu hiện hộp xác nhận ở mục 5.
   - Mở từ "+ Mục con": tiêu đề "Thêm mục con vào "Kiến thức gia công"", chọn sẵn đúng cha.
   - Chế độ Thêm có nút phụ "Lưu và thêm mục khác": giữ panel mở, xóa trống ô tên, giữ cha.
     - Vì lúc dựng web người dùng tạo 5–10 chuyên mục liền.
     - Ở 360, 3 nút xếp dọc: Lưu (rộng hết) · Lưu và thêm mục khác · Hủy.
   - Có thay đổi chưa lưu mà bấm × / Esc / Hủy, chuyển màn trong Shell (onDirty) hay đóng tab: hỏi "Bỏ thay đổi chưa lưu?" (Ở lại sửa · Bỏ thay đổi).
   - Lưu: nút báo đang lưu, chặn bấm 2 lần. Xong thì toast; dòng mới / dòng vừa sửa sáng nhẹ 2 giây; focus về dòng đó.
8. **Bàn phím và trình đọc màn hình** (giữ kiểu danh sách như Trình đơn; KHÔNG dùng `tree` / `treeitem`, vì dòng có nhiều nút bên trong):
   - Danh sách `role=list`; mỗi dòng đọc "<tên>, cấp N, 24 bài, Thiếu JA"; các nút đi theo Tab bình thường.
   - Khi focus ở tên dòng:
     - Alt+↑/↓ đổi thứ tự;
     - Alt+→ thành mục con của mục ngay trên;
     - Alt+← đưa ra ngoài một cấp (không ra khỏi gốc).
   - Esc đóng panel, menu hoặc hộp thoại, rồi trả focus về đúng nút đã mở nó.
   - Phím tắt nằm sau nút "(?) Phím tắt" ở thanh công cụ (chỉ khi có chuột / bàn phím), không rải dòng gợi ý dài dưới cây.
     - Dòng gợi ý ngắn dưới cây giữ: "Kéo ⠿ hoặc dùng ⋯ › Chuyển lên, Chuyển xuống, Chuyển đến… · Tối đa 3 cấp".
     - Trên điện thoại bỏ phần "Kéo ⠿".
   - Kéo thả:
     - Có vạch báo chỗ thả; báo đỏ "Chỉ được lồng tối đa 3 cấp" ngay khi rê vào chỗ không hợp lệ.
     - Tự cuộn khi kéo gần mép.
     - Tắt chuyển động khi `prefers-reduced-motion`.
   - Viền focus và các phần giao diện có nghĩa (chip, nút viền) tương phản ≥ 3:1.
9. **Sản phẩm** dùng đúng màn này: chữ "sản phẩm" ở mọi chỗ ("Chưa có sản phẩm", "Xem 12 sản phẩm"); không có gốc; "Cấp ngoài cùng" có trong "Chuyển đến…"; không có dòng quyền / quy trình duyệt.
10. **Kéo thả trong cây: chuẩn chung cho Chuyên mục, Trình đơn và danh sách Trang** (owner 07/10). Người kéo phải thấy rõ mục sẽ nằm cùng cấp, vào trong hay ra ngoài; nhả ra là đúng ngay, không phải thử lại nhiều lần. Hiện 3 màn kéo thả 3 kiểu khác nhau, nên dùng đúng một kiểu này:
   - **Dòng giữ chỗ = kết quả.** Khi kéo, một dòng giữ chỗ (viền nét đứt màu nhấn, cao bằng một dòng thật) hiện đúng vị trí và đúng thụt lề mà mục sẽ nằm khi nhả. Các dòng khác dịch ra nhường chỗ trong 150 ms (tắt khi máy đặt giảm chuyển động). Nhả ra thì mục nằm đúng chỗ dòng giữ chỗ, không nhảy đi đâu khác.
   - **Ba vùng trên mỗi dòng đích:**
     - 1/4 trên: cùng cấp, đặt trước dòng đó;
     - 1/2 giữa: vào trong, thành mục con. Dòng cha được viền nhấn và nền nhạt; nếu đang thu gọn thì tự mở sau 0,6 giây giữ chuột;
     - 1/4 dưới: cùng cấp, đặt sau dòng đó.
   - **Ra ngoài một cấp:** đang ở dưới mục con cuối cùng của một nhóm, kéo sang trái quá 1 bậc thụt lề thì dòng giữ chỗ lùi ra 1 cấp, thành cùng cấp với cha.
   - **Nhãn đi theo con trỏ, nói kết quả bằng lời:**
     - "Đặt trước "Giới thiệu" · cấp 1";
     - "Thành mục con của "Giới thiệu" · cấp 2";
     - "Ra ngoài nhóm "Dịch vụ" · cấp 1".
     Vùng `aria-live` đọc đúng câu đó.
   - **Chỗ không được thả:** quá số cấp tối đa, thả vào chính nó hay mục con của nó, hoặc bài đang theo quy trình duyệt rời loại nội dung. Khi đó dòng giữ chỗ đỏ, nhãn nêu lý do ("Tối đa 3 cấp"); nhả ra không đổi gì và báo lý do.
   - **Trong lúc kéo:** đến gần mép trên hay dưới thì tự cuộn; Esc hủy kéo, mục về chỗ cũ.
   - **Sau khi nhả:** dòng vừa chuyển sáng nhẹ 1 giây, và có thông báo "Đã chuyển "X" vào trong "Y" · Hoàn tác", đứng ≥ 10 giây, dừng đếm khi rê chuột hoặc focus vào (thông báo có "Hoàn tác" đang có của màn — ví dụ 4,2 giây ở Trình đơn, 8 giây ở danh sách — nâng lên cùng mức).
   - **Không bắt buộc phải kéo:** vẫn giữ ↑ ↓ và "Chuyển đến…" (chọn chỗ đích trong cây, có ô tìm), cho điện thoại, bàn phím và người lớn tuổi.
   - Khi đang tìm hoặc lọc, kéo thả tắt như luật đang có, và hiện câu lý do.
11. **README** thêm mục "D1-1s", gồm:
    - bảng quyết định: từng nút / chức năng · chọn gì · phương án bỏ · lý do;
    - bảng hành động 3 cột: hiện sẵn · trong "⋯" · điện thoại;
    - bảng quyền: có quyền · không quyền · phiên hỗ trợ · khóa theo gói;
    - kết quả 12 tiêu chí và A–E kèm số đo;
    - Việc cho backend: lọc bài theo từng chuyên mục; xem trước số bài đổi địa chỉ và số bài chưa đăng khi chuyển; kiểm quyền 2 loại khi chuyển bài; mã `workflow_governed` chặn bài rời loại đang bật quy trình duyệt.
    - Ghi cho owner: chấm ngôn ngữ ở CLAUDE.md §3 chỉ khác nhau bằng màu, tooltip chỉ hiện khi rê chuột. Đề xuất thêm hình hoặc chữ ở một lượt component; owner chốt.

Xong khi:
(1) Tweak "Chỉ 1 chuyên mục" (thêm nếu chưa có): với 1 chuyên mục, ô tìm và chip vẫn hiện. Tìm "gia cong" ra "Gia công CNC" với đường cha "Kiến thức gia công ›" (không có gốc); tìm "khuyen mai" ra "Khuyến mãi" (con trực tiếp của gốc nên không có đường cha). Chip "Chưa có bài" ra đúng số. Khi đang lọc: có dải "Đang xem N / M… · Bỏ lọc", tay cầm ẩn, Chuyển lên khóa kèm lý do, "Chuyển đến…" vẫn dùng được.
(2) 1280: mỗi dòng không phải gốc thấy "Sửa", "+ Mục con" (trừ cấp 3), "N bài" (0 = "Chưa có bài"), trạng thái ngôn ngữ bằng chữ, "⋯". Menu đúng nhóm, đúng thứ tự, "Xóa…" cuối màu đỏ. Menu gốc chỉ có nhóm Xem + 1 dòng lý do. Tweak 8 ngôn ngữ: dòng không tràn, không cắt chữ.
(3) 390: dòng = tên + dòng phụ không gãy + "Sửa" + "+ Mục con" (trừ cấp 3) + "⋯"; chạm chỗ trống của dòng mở Sửa; "⋯" là bảng trượt từ đáy. Quét vùng bấm ≥ 44px ở 390, 768 VÀ 1280 = 0 lỗi.
(4) "Chuyển đến…" trên Tin tức không có "Cấp ngoài cùng"; trên Sản phẩm thì có. Alt+← ở cấp 2 báo "Đã ở cấp ngoài cùng của Tin tức".
(5) Tweak đường dẫn phẳng: đổi cha không hỏi, có toast "Hoàn tác". Tweak đường dẫn có chuyên mục: hỏi kèm số bài đổi địa chỉ. Tweak Tin tức BẬT quy trình duyệt: "Chuyển đến…" / Gộp / Xóa → mọi chỗ của Dịch vụ khóa kèm lý do, "Bỏ gắn" khóa, kéo sang loại khác bị từ chối. Tweak Tin tức TẮT quy trình: chuyển sang Dịch vụ được, hộp xác nhận đủ các dòng ở mục 5 kèm số, xong có toast "Mở Chuyên mục Dịch vụ".
(6) Tweak "Người viết": trạng thái không có quyền. Phiên hỗ trợ: mọi nút ghi khóa kèm câu khóa, ô tìm vẫn gõ được. Lưu hỏng: chữ đã gõ còn nguyên, có "Thử lại". `taxonomy_invalid`: có "Tải lại".
(7) Bàn phím: Esc đóng panel và focus về đúng nút. Alt+↑ đổi chỗ. Panel có thay đổi chưa lưu mà bấm Esc thì có câu hỏi "Bỏ thay đổi chưa lưu?". Thanh ngôn ngữ trong panel không cắt chữ ở 360 và ở cỡ chữ Cực lớn.
(8) `_audit/harness.html` ở 360 / 390 / 768 / 1024 / 1440, cỡ chữ Vừa và Cực lớn, đứng riêng và trong Shell, đạt cả 3 điều:
   - `scrollWidth = clientWidth`;
   - tương phản chữ ≥ 4,5:1, phần giao diện và focus ≥ 3:1, ở cả sáng lẫn tối;
   - mở lại tệp và Admin Shell: console 0 lỗi.
   Tệp đo tạm chỉ đặt trong `_audit/tmp/`.
(9) Kéo "Khuyến mãi" lên nửa giữa dòng "Tin công ty": dòng giữ chỗ thụt vào 1 bậc, nhãn "Thành mục con của "Tin công ty" · cấp 3"; nhả ra đúng chỗ đó, có "Hoàn tác". Kéo một mục cấp 3 vào trong một mục cấp 3: dòng giữ chỗ đỏ, nhãn "Tối đa 3 cấp", nhả ra không đổi gì. Esc trong lúc kéo trả mục về chỗ cũ.

Xuất zip project gửi Claude Code kiểm. Xong thì báo owner đúng một câu: "D1-1s xong — gửi zip cho Claude Code."
