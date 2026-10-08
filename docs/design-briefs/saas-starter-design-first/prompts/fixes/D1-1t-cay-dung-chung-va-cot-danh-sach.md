# D1-1t · Cây dùng chung, kéo thả một luật và cột danh sách (chat mới)
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
 9. Điện thoại & máy tính bảng: MỌI việc trên màn làm trọn được ở 360–390px và 768px, việc hay làm (trả lời khách liên hệ, duyệt bài, đăng tin nhanh, xem thông báo) thuận tay nhất; vùng bấm ≥ 44px; không thành phần nổi nào che nút Lưu hay nội dung.
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

# D1-1t · Cây dùng chung, kéo thả một luật và cột danh sách (chat mới)

Owner duyệt 08/10 (08:4x) toàn bộ khuyến nghị dưới đây. KHÔNG hỏi lại và không ghi vào "Cần owner chốt". Owner sẽ tự xem trực quan sau lượt này rồi chỉnh tiếp nếu cần.

Chạy ở CHAT MỚI. Prompt này tự đủ, không cần chat cũ.
- Sửa trên tệp HIỆN CÓ trong project (sau 7 prompt sửa D1-1s → D3-1s), không theo trí nhớ về bản cũ. Trước khi sửa, mở các tệp ở dòng "Chỉ sửa" và README D1-1, D1-2, D1-3.
- Số dòng dẫn dưới đây là của zip `D3-1s-0150` Claude Code đã đo. Lệch thì tìm theo nội dung. Chỗ nào tệp đã đúng sẵn thì giữ nguyên và ghi "đã có" trong README.
- Giữ nguyên D0-7: cỡ chữ `calc(… + var(--fsp))` theo 4 mức; biểu tượng `min(24px, calc(… + var(--fsp)))`; không chữ nào dưới 12px. Giữ mọi điều D1-1s, D1-2s, D1-3s đã làm, trừ chỗ prompt này thay rõ.
- KHÔNG gỡ, ẩn hay dời nút / tính năng đang có ngoài những chỗ prompt này nêu.

Owner xem Chuyên mục và danh sách Trang, Tin tức, rồi chốt 5 việc:
- Chuyên mục chưa đẹp → trình bày theo đúng khuôn danh sách Trang, nhưng phân cấp phải nhìn rõ hơn Trang hiện nay.
- Kéo thả: lúc đang kéo phải thấy mục sẽ nằm ở đâu, cấp mấy, trước khi nhả. Thả vào tên = thành mục con. Thả vào khe = ngang hàng. Áp cho mọi cây (Trang, Chuyên mục, Trình đơn, danh mục sản phẩm).
- Menu "Cột": bỏ chọn "Người viết" mà tên người viết vẫn hiện trong ô tiêu đề → sai.
- Thêm "Mô tả" (mặc định ẩn). "Lượt xem" thành biểu tượng mắt + số sau tiêu đề, ở mọi danh sách.
- Nhãn "<tên> đang sửa" trên dòng Trang tràn đè sang cột khác.

Cách làm: Claude Code đã dựng thử trong Chromium cả "Chuyên mục như Trang" lẫn bản cây có đường nối, đọc logic kéo thả của 3 màn, rồi cho một người rà độc lập phản biện. Các quyết định dưới đây đã qua phản biện.

Chỉ sửa: `Admin Shell.dc.html` (CHỈ mục 0 + đúng 1 dòng nạp `tree-dnd.js`) · `Chuyên mục.dc.html` · `Quản lý Bài viết - Options.dc.html` · `Trình đơn.dc.html` (chỉ mục 3 và 3b) · `tree-dnd.js` (MỚI, cạnh `Admin Shell.dc.html`) · `admin-components.css` (lớp cây dùng chung) · `Chuẩn giao diện SaaS Starter.dc.html` (mục "Cây kéo-thả + Nút sắp xếp" và mẫu danh sách) · `README - D1-1 Chuyên mục.md` · `README - D1-2 Trình đơn.md` · `README - D1-3 Danh sách nội dung.md` · `TODO.md` · `LESSONS.md`.

PHẦN 1 — Shell, cây và kéo thả (mục 0–3b). Xong phần 1: kiểm "Xong khi" (0), (1), (2), (3), (7), (9), ghi TODO "D1-1t phần 2", báo "D1-1t phần 1 xong — còn phần 2" và dừng, KHÔNG xuất zip.

0. **Sửa trước tiên: lỗi Shell do D3-1s thêm (Claude Code đo trong Chromium trên zip `D3-1s-0150`).**
   - `Admin Shell.dc.html` dòng ~1422, đầu `componentDidUpdate(pp, ps)`, đọc `ps.settingsValues`. Runtime không truyền `ps` (đúng điều LESSONS đã ghi cho `prevProps`, ngay hai dòng dưới). Nên mỗi lần Shell cập nhật đều ném `TypeError: Cannot read properties of undefined (reading 'settingsValues')`, ở MỌI màn, kể cả khi mới mở `#/site/roles`.
   - Lỗi này dừng cả phần còn lại của `componentDidUpdate`: `saveUi()` không chạy, nên `localStorage['bs-ui']` vẫn là `null`. Cỡ chữ cá nhân và mọi tùy chọn Customizer mất sau khi tải lại trang.
   - Cách sửa: không dựa vào `ps`. Tự giữ giá trị trước, như mẫu `this._pp` ngay dưới, ví dụ `const b = (this.state.settingsValues || {})[k]; if (this._fsPrev !== b) { this._fsPrev = b; …ghi/xóa 'bs-site-fs'… }`, và đặt `this._fsPrev` ban đầu trong constructor bằng giá trị đã nạp. Không sửa gì khác trong Admin Shell ở mục này.
   - Thêm 1 dòng vào LESSONS: "Runtime không truyền prevProps LẪN prevState vào componentDidUpdate — tự giữ bản trước."

1. **Khuôn cây-bảng dùng chung: Chuyên mục trình bày như danh sách Trang.**
   - Chuyên mục dùng CÙNG lớp CSS và cấu trúc dòng với danh sách Trang ở chế độ "Thứ tự trên website": thẻ bảng, hàng tiêu đề cột, cỡ dòng, cỡ và vị trí nút. Thẻ điện thoại cũng giống thẻ Trang. Lớp dùng chung đặt trong `admin-components.css` với tiền tố `tt-` (cây-bảng: dòng, thụt cấp, đường nối, dòng giữ chỗ); không chép CSS riêng cho từng màn. D0-8 sẽ được sửa các lớp `tt-` này khi làm lại dòng / thẻ danh sách.
   - Cột của Chuyên mục, trái sang phải:
     - **Chuyên mục:** dòng 1 là tên. Dòng 2 là đường dẫn (chữ nhỏ, màu phụ) + bản dịch. Ở phần 1 giữ nguyên nút / chữ bản dịch đang có (`cm-ls`) trên dòng 2; phần 2 đổi theo mục 4. Không lúc nào mất lối mở bản dịch.
     - **Mẫu trang:** (cùng chữ với cột "Mẫu trang" của danh sách Trang — một khái niệm một chữ) tên mẫu trang danh sách của chuyên mục (repo: `taxonomies.list_template`; dữ liệu mẫu tự đặt, ví dụ "Danh sách tin", "Lưới ảnh").
     - **Bài:** liên kết "12 bài", 0 thì chữ mờ "Chưa có bài" như D1-1s; Sản phẩm thì "sản phẩm".
     - **Thao tác:** "Sửa" · "+ Mục con" (cấp cuối thì giữ chỗ trống) · "⋮", cùng cỡ với "Sửa · + Trang con · ⋮" của Trang.
   - Chuyên mục KHÔNG có ô chọn, "Chọn tất cả", tab trạng thái, cột Trạng thái / Người viết / Lượt xem. Chuyên mục không có trạng thái, và D1-1s đã chốt chưa làm chọn nhiều.
   - Giữ nguyên của D1-1s:
     - thanh công cụ (ô tìm, chip "Tất cả · Chưa có bài · Chưa dịch", "Phím tắt", nút Mở / Thu gọn);
     - dải "Đang xem N / M… · Bỏ lọc";
     - menu "⋮" (nhóm, thứ tự, "Xóa…" cuối màu đỏ; menu gốc chỉ nhóm Xem + 1 dòng lý do);
     - panel Sửa / Thêm, hộp Xóa / Gộp, "Chuyển đến…";
     - quyền, gói, phiên hỗ trợ, thông báo "Hoàn tác".
   - Dòng chuyên mục gốc của loại: nền phụ nhạt, tên đậm, nhãn đổi "Gốc" → "Trang gốc", không có tay kéo.
   - Đầu cây có dải quyền cùng kiểu dải quyền của danh sách bài:
     - chip nhóm quyền + "Bạn được thêm, sửa, sắp xếp và xóa chuyên mục.";
     - bên phải chữ phụ "Người viết, Cộng tác viên, Chỉ xem: chỉ chọn chuyên mục khi viết bài" (Sản phẩm: "… khi thêm sản phẩm").
   - Nhãn "Ghi chú thiết kế / Chờ backend" của màn gom thành MỘT dòng thu gọn (`<details>`): "Ghi chú thiết kế · N việc chờ backend", bấm mới mở. Bỏ nhãn "Cần owner chốt: đếm theo chuyên mục chính hay mọi chuyên mục". Owner chốt 08/10 (10:13): cột "Bài" = số bài có chuyên mục CHÍNH là đúng chuyên mục đó, không cộng mục con. Chuyên mục chính là chuyên mục đứng đầu của bài (vị trí 0 trong `entry_taxonomies`); trang chuyên mục trên website (`StorefrontController.php:224`) và bộ lọc Chuyên mục của danh sách bài (`Entries/Index.php:867`) đều đếm đúng như vậy, nên số trên màn khớp số bài khách thấy. Thêm câu này vào README.

2. **Phân cấp nhìn rõ (Chuyên mục VÀ danh sách Trang ở chế độ cây; với Trang chỉ THÊM, không gỡ hay dời nút nào).**
   - **Đường nối cha – con:**
     - Chuyên mục: đường dọc 1px nằm đúng dưới tâm mũi tên mở / gập của mục cha. Trang KHÔNG có mũi tên mở / gập (cây Trang luôn mở) và KHÔNG thêm nút mở / gập mới: đường dọc của Trang nằm giữa bước thụt của cấp cha, cách mép trái khối tên cha 16px (12px dưới 600px);
     - nhánh ngang tới từng con (├ cho con giữa, └ cho con cuối);
     - mục cha đang mở có đoạn đi xuống từ dưới mũi tên.
   - Màu đường nối = `color-mix(in srgb, var(--color-border-input) 60%, var(--color-surface))`. Đường nối là phần tử trang trí: `aria-hidden`, `pointer-events: none`.
   - Dấu "↳" trước tên trang con thay bằng đường nối (cùng vai trò, không còn cần).
   - CẢ khối tên + dòng đường dẫn thụt theo cấp: 32px mỗi cấp trên máy tính, 24px dưới 600px. Hiện tại Trang chỉ thụt tên, đường dẫn đứng yên ở lề.
   - Độ đậm tên: gốc 700 · cấp đầu của cây 600 · sâu hơn 500.
   - Vạch ngăn: rõ (`--color-border-strong`) trước mỗi mục cấp đầu; mờ giữa các dòng trong cùng nhóm.
   - Thẻ điện thoại (Trang và Chuyên mục): thẻ thụt 16px mỗi cấp, có đường nối bên trái. Mục từ cấp 2 trở xuống có dòng chữ phụ "Thuộc: <tên cha>" trên tên. Bản đo: thẻ Trang hiện mất hẳn thụt lề, chỉ còn "↳".
   - Đang tìm / lọc (danh sách phẳng): không có đường nối, giữ đường cha "A › B ›" như D1-1s.
   - Mức chữ "Cực lớn": mũi tên mở / gập ghim ở dòng chữ đầu (`align-self: flex-start` trên điện thoại), đường nối bám theo mũi tên, không cắt qua nó.

3. **Kéo thả: MỘT luật, MỘT tệp `tree-dnd.js` cho Trang, Chuyên mục và Trình đơn.**
   - Lỗi hiện tại, cả 3 màn chép cùng logic:
     - `Chuyên mục.dc.html:1001`, `Quản lý Bài viết - Options.dc.html:1137`, `Trình đơn.dc.html:1256` (hàm `dragTarget`);
     - mỗi dòng chia ¼ trên = trước, ½ giữa = vào trong, ¼ dưới = sau.
   - Vì vậy CÙNG một khe, giữa "Tuyển dụng" (cấp 3, con cuối) và "Kiến thức gia công" (cấp 2), cho 2 kết quả khác nhau chỉ vì lệch vài pixel lên hay xuống. Kéo sang trái cũng chỉ ra ngoài được 1 cấp.
   - Luật mới:
     - **Rê vào nửa giữa một dòng (vùng tên):** thành mục con ĐẦU TIÊN của dòng đó. Dòng giữ chỗ hiện ngay dưới dòng cha. Dòng cha có viền nhấn + nền nhạt. Cây có mở / gập (Chuyên mục, Trình đơn) mà cha đang thu gọn thì tự mở sau 0,6 giây giữ chuột.
     - **Khe ngay dưới một mục cha đang mở:** thành con đầu tiên của mục đó (giữ như nay).
     - **Khe giữa 2 dòng:** ¼ dưới của dòng trên cộng ¼ trên của dòng dưới là MỘT vùng. Trong vùng này, lên xuống không đổi kết quả.
     - **Chọn cấp trong khe theo vị trí NGANG của con trỏ:**
       - các cấp được chọn đi từ cấp dòng trên, lùi dần tới cấp dòng dưới;
       - MẶC ĐỊNH là cấp dòng trên, tức vẫn ở trong nhóm;
       - tính theo vị trí ngang của con trỏ SO VỚI lúc vào khe: sang trái mỗi một bước thụt của màn đó thì lùi ra 1 cấp (Chuyên mục, Trang: 32px, 24px dưới 600px; Trình đơn: giữ bước thụt đang có của nó, không đổi thụt Trình đơn), lùi được nhiều cấp, không thấp hơn cấp dòng dưới; sang phải về lại, không cao hơn cấp dòng trên;
       - khe cuối danh sách: lùi được tới cấp 1.
     - **Dòng giữ chỗ = kết quả:** viền nét đứt màu nhấn, cao bằng 1 dòng, thụt đúng cấp sẽ nằm, trượt trái / phải ngay theo con trỏ. Các dòng khác nhường chỗ 150ms (tắt khi giảm chuyển động).
     - **Nhãn theo con trỏ** + `aria-live` nói kết quả bằng câu đủ:
       - "Sẽ thành mục con đầu tiên của «Tin công ty» · cấp 3";
       - "Sẽ nằm ngay sau «Tuyển dụng» · cấp 3";
       - "Sẽ nằm ngay sau «Tin công ty» · cấp 2".
       Trang dùng "trang con", Trình đơn dùng "mục con".
     - **Chỗ không được thả** (quá số cấp, vào chính nó / con của nó, bài theo quy trình duyệt rời loại): dòng giữ chỗ đỏ + biểu tượng ✕ + lý do ("Tối đa 3 cấp"). Nhả ra không đổi gì, báo lý do.
     - Gần mép trên / dưới thì tự cuộn. Esc hủy kéo, mục về chỗ cũ.
     - Sau khi nhả: dòng vừa chuyển sáng nhẹ 1 giây, kèm thông báo "Đã chuyển … · Hoàn tác" ≥ 10 giây, dừng đếm khi rê / focus.
     - Không bắt buộc kéo: giữ ↑ ↓, Alt + mũi tên, "Chuyển đến…". Điện thoại không kéo.
     - Đang tìm / lọc: tắt kéo, hiện câu lý do (giữ như nay).
   - `tree-dnd.js` chỉ tính vị trí thả và vẽ dòng giữ chỗ / nhãn. Mỗi màn truyền vào: danh sách dòng đang hiện (khóa, cấp, có con đang mở), bước thụt (px), số cấp tối đa, hàm kiểm "được thả không", hàm đặt chữ nhãn. Ba màn bỏ hàm `dragTarget` riêng và gọi tệp này. Script được nạp 2 lần (Shell và màn nhúng) thì chỉ khởi tạo 1 lần.
   - Sửa câu luật trong "Chuẩn giao diện" (mục "Cây kéo-thả + Nút sắp xếp", hiện ở dòng 869) theo đúng luật này. Thêm vào mẫu danh sách: "Cây dùng chung: Trang, Chuyên mục, Trình đơn, danh mục sản phẩm".

3b. **Sửa kèm từ kiểm zip `D3-1s-0150` (Claude Code đo 08/10 trong Chromium, có ảnh chụp):**
   - Trình đơn, phiên hỗ trợ: các nút trong khung "Cần bạn xem" ("Gỡ mục này", "Giữ ẩn cả sau khi đăng", "Thêm vào cuối", "Dịch ngay") và "Thêm vào trình đơn" vẫn bấm được. Bấm "Gỡ mục này" báo "Đã gỡ … giữ trong Thùng rác 30 ngày" mà không gỡ gì. Báo thành công khi không có gì đổi là sai. Khóa các nút này như mọi nút ghi khác của phiên hỗ trợ (tắt + đúng câu khóa phiên hỗ trợ màn đang dùng, `this.SUP`, cạnh nút). `mutate()` khi bị chặn trả về `false`; nơi gọi chỉ báo thành công khi nhận `true`.
   - Trình đơn, thông báo lặp chữ: "Đã thêm 2 trang vào cuối trình đơn. vào cuối trình đơn." (`Trình đơn.dc.html` ~dòng 1467 nối thêm " vào cuối trình đơn." vào câu đã có sẵn). Câu nào đã đủ thì không nối thêm.
   - Chuyên mục, "Chuyển đến…": dòng gốc ghi "Thành cấp 1", trong khi dòng và nhãn kéo thả gọi cùng chỗ đó là cấp 2. Dùng MỘT cách đếm cấp ở mọi chỗ (dòng, nhãn kéo thả mục 3, "Chuyển đến…", câu "Tối đa 3 cấp"): chuyên mục gốc của loại = cấp 1, như hàm `check()` đang đếm. Chỗ lệch nằm ở ~dòng 1289 (`x.sec ? 0 : lv`) và ~1295 ("Thành cấp " + (lv + 1)).
   - Trình đơn: với chuột ở 768px trở lên có 63 điều khiển chỉ cao 34–38px. Vùng bấm ≥ 44px ở MỌI loại con trỏ, không chỉ cảm ứng, cho điều khiển RIÊNG của Trình đơn (lớp `mn-…`), nới bằng `::before` như `.sx-btn.sm` để hình giữ nguyên. KHÔNG sửa `.sx-btn` chung: D0-8 mục 9 lo vùng bấm khi dùng chuột cho mọi nút chung.
   - Chuyên mục: liên kết số bài ("4 bài", "5 bài"…) chỉ 42 × 44px từ 768 trở lên → vùng bấm ≥ 44 × 44px.
   - Chuyên mục, kéo thả: CSS tô dòng cha (`.cm-row[data-drop="in"]`) có sẵn nhưng dòng không bao giờ nhận `data-drop`, nên khi kéo vào trong không thấy dòng cha được tô. Với `tree-dnd.js` ở mục 3, dòng cha PHẢI thật sự được tô khi rê vào nửa giữa.
   - Danh sách Trang ở 1280 trở lên: không có nút "Sắp xếp" (chỉ hiện ≤ 1024). Sau khi bấm tiêu đề cột để sắp, câu gợi ý bảo chọn «Thứ tự trên website», nhưng người dùng máy tính không có chỗ nào để chọn → ngõ cụt. Không thêm nút mới vào thanh công cụ: đổi câu gợi ý thành có nút "Về thứ tự trên website" (sắp lại theo cây, bật lại kéo thả), hiện ở mọi khổ.

PHẦN 2 — gửi "Làm D1-1t phần 2" trong CÙNG chat: cột và dòng của danh sách (mục 4–8)

4. **Bản dịch trên dòng: một luật cho mọi danh sách dùng khuôn này (Tin tức, Trang, Dịch vụ, Dự án, Chuyên mục).**
   - Website có ≤ 4 ngôn ngữ: dãy nhãn như danh sách Trang đang có ("● VI ★" bản nguồn, "◌ EN" viền đứt = chưa có). Các nhãn khác nhau bằng hình chứ không chỉ bằng màu.
   - Website có > 4 ngôn ngữ: một dòng chữ tóm tắt, ví dụ:
     - "Đủ 8 ngôn ngữ";
     - "Thiếu 2: tiếng Nhật, tiếng Trung";
     - "Thiếu 5 ngôn ngữ" (khi nhiều hơn 2).
     Bấm vào thì mở bản thiếu đầu tiên.
   - Câu chữ dùng tên ngôn ngữ tiếng Việt ("tiếng Anh"), KHÔNG dùng mã ("EN") trong câu.
   - Tweak số ngôn ngữ của danh sách hiện chỉ có 1 / 2 / 4: thêm 3 và 8 (8 ngôn ngữ mẫu có tên tiếng Việt) để kiểm được cả hai luật.
   - Ngưỡng 4 lấy từ bản đo: 4 nhãn rộng khoảng 220px, vừa 1 hàng ở 390; 5 nhãn xuống hàng.
   - Luật này THAY quyết định D1-1s "Chuyên mục chỉ ghi bằng chữ" (owner duyệt 08/10). Ghi vào bảng quyết định trong README D1-1.

5. **Menu "Cột" của danh sách: mỗi mục điều khiển dữ liệu đó ở MỌI chỗ nó hiện.**
   - Lỗi: `Quản lý Bài viết - Options.dc.html`:
     - dòng 227 ẩn cột riêng (`.c-au`…) khi bỏ chọn;
     - dòng 311–313 lại dồn cùng dữ liệu vào dòng phụ dưới tiêu đề (`.m-au`, `.m-views`, `.m-cat`, `.m-step`) khi khung hẹp, mà không xét lựa chọn.
     Vì vậy bỏ chọn "Người viết" mà tên vẫn hiện.
   - Sửa: bỏ chọn một mục thì dữ liệu đó ẩn ở cột riêng, ở dòng phụ và ở thẻ điện thoại. Áp cho mọi mục Cột của `Quản lý Bài viết - Options.dc.html` (Tin tức, Trang, Dịch vụ, Dự án). `Sản phẩm - Danh sách.dc.html` có cùng lỗi nhưng không thuộc lượt này: D0-8 phần 2 sửa.
   - Thêm mục **"Mô tả"** vào nhóm tùy chọn, MẶC ĐỊNH TẮT.
     - Bật lên thì hiện 1 dòng tóm tắt của đúng ngôn ngữ đang xem, ngay dưới tiêu đề, trên dòng đường dẫn. Chữ phụ, 1 dòng, dài thì "…", đủ chữ trong `title`.
     - Bài chưa có tóm tắt thì không hiện gì.
     - Repo: `entry_translations.excerpt`. Dữ liệu mẫu thêm tóm tắt cho khoảng một nửa số bài.
   - Thêm mục **"Bản dịch"** (MẶC ĐỊNH BẬT): bật / tắt nhãn ngôn ngữ của mục 4.
   - Tiêu đề cột cuối dùng chữ "Thao tác", theo trang chuẩn (trang chuẩn dùng "Thao tác" 11 lần), thay "Hành động" ở danh sách và trong menu Cột.

6. **"Lượt xem" ở mọi danh sách nội dung (Tin tức, Dịch vụ, Dự án, Trang):** bỏ cột "Lượt xem".
   - Thay bằng nhãn **biểu tượng mắt + số** đứng ngay sau khối tiêu đề, cùng hàng dòng 1 của tiêu đề. Ví dụ: `👁 1.906` (`ri-eye-line` + số theo định dạng Việt Nam).
   - Không lẫn vào tiêu đề:
     - chữ nhỏ hơn tiêu đề 1 bậc, màu phụ, KHÔNG đậm, KHÔNG viền (chữ không bấm được thì không được trông như nút);
     - cách tiêu đề ≥ 8px;
     - là phần tử RIÊNG, không nằm trong khối tiêu đề bị cắt 2 dòng, nên không bao giờ bị "…" che.
   - Rê chuột hoặc focus: `title` = "1.906 lượt xem từ khi đăng". Trình đọc màn hình đọc "1.906 lượt xem". Bài chưa đăng thì không hiện nhãn.
   - Mục "Lượt xem" vẫn ở menu Cột (tắt thì ẩn nhãn). Sắp xếp theo lượt xem vẫn ở menu Sắp xếp ("Lượt xem nhiều nhất", dòng 2035).
   - Ghi chú vào README: biểu tượng mắt không nằm trong danh sách biểu tượng được đứng một mình của chuẩn; owner chọn có chủ ý 08/10.

7. **Dòng đang có người khác sửa (khóa sửa), mọi danh sách:**
   - Lỗi: nút "🔒 <tên> đang sửa" thay vào chỗ nút "Sửa" (khoảng 70px), chữ dài đè sang cột bên cạnh (dòng 604).
   - Sửa:
     - Nút giữ ĐÚNG cỡ và chỗ của "Sửa", ghi "🔒 Xem". Là nút thường bấm được (bỏ `aria-disabled`), làm đúng việc của mục "Xem" / "Xem trước" đang có trong "⋮" (~dòng 2010: bài đang hiển thị mở bản trên website, bài chưa đăng mở bản xem trước).
     - Ô Trạng thái, dòng 2: "🔒 <tên> đang sửa" (màu cảnh báo + biểu tượng + chữ), thay "Sửa … trước". Thời điểm sửa gần nhất chuyển vào `title`.
     - Menu "⋮" giữ các mục khóa kèm lý do như nay.
     - Thẻ điện thoại cũng làm như vậy.

8. **README** (D1-1, D1-2, D1-3) thêm mục "D1-1t":
   - bảng "Màn đang chạy · Bản mới · Giữ bản nào · Vì sao" cho mọi chỗ đổi;
   - luật kéo thả mục 3;
   - số đo;
   - Việc cho backend:
     - đọc `list_template` cho cột Mẫu trang;
     - `excerpt` cho Mô tả;
     - danh sách trả `view_count` và người đang giữ khóa sửa;
     - một component cây Blade / Livewire dùng chung cho Trang, Chuyên mục và Trình đơn (`<x-bs-admin::tree>` đã có trong trang chuẩn).

Xong khi:
(0) Mở `Admin Shell.dc.html#/site/roles` và `#/site/settings` ở 1366: console 0 lỗi. Chọn cỡ chữ "Vừa" trong Customizer → `localStorage['bs-ui']` có giá trị → tải lại trang vẫn là "Vừa". Đổi cỡ chữ của website trong Cài đặt rồi Lưu → `bs-site-fs` đổi theo.
(1) Chuyên mục Tin tức ở 1366, trong Shell, đặt cạnh danh sách Trang:
   - cùng chiều cao hàng tiêu đề cột, cùng cỡ dòng, cùng cỡ và vị trí nút;
   - cột "Chuyên mục · Mẫu trang · Bài · Thao tác";
   - không ô chọn, không tab;
   - dải quyền + 1 dòng "Ghi chú thiết kế".
   Chuyên mục Sản phẩm: chữ "sản phẩm" đúng mọi chỗ.
(2) Đường nối, đo bằng script ở 1366 và 390:
   - Chuyên mục: đường dọc dưới mỗi mục cha lệch tâm mũi tên ≤ 2px; Trang: đường dọc cách mép trái khối tên cha 16 ± 2px, không có nút mở / gập mới;
   - khối tên + đường dẫn của mục cấp 3 thụt 64px so với cấp 1;
   - thẻ điện thoại cấp 2 có "Thuộc: …".
(3) Kéo thả, kiểm trên cả 3 màn bằng kéo thật (chuột) ở 1366:
   - Kéo "Khuyến mãi" vào nửa giữa "Tin công ty": dòng giữ chỗ ngay dưới "Tin công ty", thụt cấp 3, nhãn "Sẽ thành mục con đầu tiên của «Tin công ty» · cấp 3". Nhả ra đúng chỗ, có "Hoàn tác".
   - Rê vào khe giữa "Tuyển dụng" và "Kiến thức gia công": dòng giữ chỗ cấp 3 "Sẽ nằm ngay sau «Tuyển dụng» · cấp 3". Rê lên / xuống trong khe: không đổi. Con trỏ sang trái 32px: dòng giữ chỗ lùi ra cấp 2, "Sẽ nằm ngay sau «Tin công ty» · cấp 2".
   - Kéo một mục có con vào trong một mục cấp 3: dòng giữ chỗ đỏ "Tối đa 3 cấp", nhả ra không đổi gì.
   - Esc trả mục về chỗ cũ.
   - Trang (nhóm "Giới thiệu") và Trình đơn (nhóm có con) cho kết quả tương ứng.
   - Ba màn gọi chung `tree-dnd.js`, không còn hàm tính vị trí thả riêng.
(4) Tweak 3 ngôn ngữ: nhãn VI / EN / JA trên dòng. Tweak 8 ngôn ngữ: chữ tóm tắt bằng tên tiếng Việt, không mã. Cả hai không tràn, không cắt ở 1280 và 390.
(5) Danh sách Tin tức:
   - bỏ chọn "Người viết" → tên người viết biến mất ở 1440 (cột), 1024 (dòng phụ) và 390 (thẻ); tương tự cho "Chuyên mục" và "Lượt xem";
   - bật "Mô tả" → 1 dòng dưới tiêu đề; tắt (mặc định) → không có;
   - nhãn 👁 + số sau tiêu đề, không viền, có `title`;
   - tiêu đề 2 dòng bị "…" vẫn thấy đủ nhãn 👁;
   - không còn cột "Lượt xem"; tiêu đề cột cuối là "Thao tác".
(6) Dòng có khóa sửa (dữ liệu mẫu đã có: Trang "Thiết kế website") ở 1366, 1024 và 390: nút "🔒 Xem" cùng cỡ "Sửa", không đè cột nào; ô trạng thái có "🔒 <tên> đang sửa".
(7) Trang không mất điều khiển nào so với zip `D3-1s-0150`: ô chọn, "Sửa", "+ Trang con", "⋮", tab, bộ lọc, menu Cột, "Chuyển đến…".
(8) `_audit/harness.html` ở 360 / 390 / 768 / 1024 / 1440, cỡ chữ Vừa và Cực lớn, sáng và tối, đứng riêng và trong Shell, cho cả 3 màn:
   - không cuộn ngang;
   - tương phản chữ ≥ 4,5:1, viền điều khiển ≥ 3:1;
   - vùng bấm ≥ 44px ở 390 (cảm ứng); với chuột chỉ đo các điều khiển nêu ở mục 3b (vùng bấm chung khi dùng chuột là việc của D0-8 mục 9);
   - console 0 lỗi.
   Tệp đo tạm chỉ ở `_audit/tmp/`, xóa trước khi xuất zip.
(9) Mục 3b: Trình đơn trong phiên hỗ trợ: mọi nút của "Cần bạn xem" và "Thêm vào trình đơn" tắt, có câu lý do; không có thông báo thành công nào. Thêm trang vào cuối: câu thông báo không lặp chữ. Chuyên mục: "Chuyển đến…" và nhãn kéo thả nói cùng một số cấp; liên kết số bài 44 × 44px; rê vào nửa giữa thấy dòng cha được tô. Trình đơn ở 1366 với chuột: mọi điều khiển có vùng bấm ≥ 44px. Trang ở 1440: sắp theo "Lượt xem" rồi bấm "Về thứ tự trên website" → cây và tay kéo trở lại.

Gọi ready_for_verification, sửa hết lỗi verifier báo. Verifier không trả kết quả thì ghi [CHƯA KIỂM ĐƯỢC], không ép. Mục nào cần kéo thật mà công cụ không làm được thì ghi [CHƯA KIỂM ĐƯỢC] và ghi rõ đã kiểm được tới đâu; Claude Code sẽ đo bằng zip.
Xuất zip project gửi Claude Code kiểm. Xong thì báo owner đúng một câu: "D1-1t xong — gửi zip cho Claude Code."
