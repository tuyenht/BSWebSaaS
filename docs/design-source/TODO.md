# Việc còn lại — cập nhật 07/10/2026

## ~~D2-5~~ — xong 07/10 (README `README - D2-5 Tổng quan.md`): `Tổng quan.dc.html` theo quyền (BSCW.can) — Việc cần làm hôm nay · Bắt đầu · thẻ số liệu · Lượt xem · Duyệt bài · Bài của tôi · Nội dung gần đây / mới đăng · Hoạt động · Làm nhanh · Cần giúp; registry `dashboard` ready (mount, ownHeader)
- Backend: bộ đếm theo website cập nhật cùng giao dịch (trang · bài · sản phẩm · khách liên hệ 7 ngày · chưa xem), dung lượng thư viện đã dùng, lượt xem theo ngày, trạng thái 6 việc "Bắt đầu" + "Ẩn danh sách", việc giao bài cho người duyệt, lý do trả lại đọc từ sự kiện quy trình · Chờ backend (README › Việc cho backend)
- Admin Shell: logic Tổng quan cũ trong renderVals (REVIEW · metrics · dayData · bars · rangeChips · inbox · recent · setChartRef · freshSteps) không còn markup — dọn khi tách Shell (L1-21)
- "Cần giúp?": kênh hỗ trợ đọc `BSPLAT.support()` — bản thật lấy từ Cài đặt nền tảng · D4-3
- "Hoạt động gần đây" đọc nhật ký website · D0-4a / D4-3 (Chờ backend)
- Tab / bàn phím (tab Duyệt bài, hộp Chọn ngày), chế độ tối (đo tương phản), Safari / Firefox / Samsung, cảm ứng thật, 200%, xoay ngang · Claude Code bằng zip
- Lượt kế tiếp: **D3-1**.

## ~~D2-4~~ — xong 07/10 (README `README - D2-4 Thông báo.md`): chuông Shell (đọc `BSCW.notices.feed`, 8 mục theo ngày, "99+", toàn màn < 600px) + `Thông báo.dc.html` (registry `notifications` ready, mount) + mục Thông báo trong `Tài khoản của tôi.dc.html`
- Backend: lọc theo nhóm / chưa đọc + tìm theo nội dung, người liên quan ở máy chủ; phát `security.new_device_login`, `system.storage_near_limit`; thêm `security.password_changed`, `system.site_suspended`; gửi thư cho loại workflow; gom email 1 lần/ngày · Chờ backend (README › Việc cho backend)
- Owner chốt: thư khách liên hệ ở mục Thông báo (thêm một thư ngoài thư của Biểu mẫu); danh sách loại của chuông Nền tảng; gộp thông báo nhiều website; đăng nhập hỗ trợ không đánh dấu đã đọc thay thành viên · Cần owner chốt
- Shell: Tweak mô phỏng ≥ 100 chưa đọc để thấy "99+" (hàm `BSCW.notices.badge` đã có) · lượt sửa Shell kế
- Tab / bàn phím trong hộp chuông, chế độ tối (đo tương phản), Safari / Firefox / Samsung, cảm ứng thật, 200%, xoay ngang · Claude Code bằng zip
- Lượt kế tiếp: **D2-5**.

## ~~D2-2~~ — xong 07/10 (README `README - D2-2 Lời mời thành viên.md`): hộp "Mời thành viên" + tab "Lời mời" (Đang chờ · Hết hạn · Đã hủy · Đã nhận) trong `Thành viên.dc.html`, registry `users.tabs['loi-moi']` ready
- Email Settings: mẫu `team_invite` lệch thư thật (`MemberInvitationNotification`) — đang ghi "nhóm quản trị {team_name}", nút "Chấp nhận lời mời", thiếu nhóm quyền / lời nhắn / hạn; sửa theo README D2-2 › Thư mời (nút "Nhận lời mời", ":inviter mời bạn tham gia quản trị website :site với nhóm quyền: :roles", lời nhắn, "Lời mời có hiệu lực đến :date") · D3-2
- Mời nhiều địa chỉ một lần + danh sách lời mời ở máy chủ · Chờ backend (README › Việc cho backend)
- Owner chốt: "Sao chép liên kết mời" (bảo mật — không vẽ) · Cần owner chốt
- Tab / bàn phím, chế độ tối (tương phản), Safari/Firefox/Samsung, cảm ứng thật, 200%, xoay ngang · Claude Code bằng zip
- Lượt kế tiếp: **D2-3** (đã xong 07/10 — owner xem lại thứ tự; kế tiếp theo lộ trình là D2-4).

## ~~D1-4d~~ — xong 07/10 (README `README - D1-4 Trình soạn nội dung.md` › D1-4d): thay đổi chờ của bài đang công khai (a/b/c) + rời trang + hai người cùng sửa + audit hiển thị
- ~~Chuông: thông báo mẫu "Có thay đổi chờ cập nhật" … · D2-4~~ → xong 07/10 (D2-4): `BSCW.notices.feed` đổi mỗi lần push thành câu `notifications.workflow.pending_review.change` ("… gửi thay đổi của bài «…» — đang chờ bạn duyệt"), bấm mở đúng bài (`<loại>.edit?ma=`); 4 câu `.change` có trong bảng mẫu, không thêm loại mới
- Admin Shell: hộp rời trang của Shell ghi "Ở lại · Bỏ thay đổi · Lưu & tiếp tục" — đổi thành "Ở lại · Thoát không lưu · Lưu & thoát" cho màn soạn (khuôn đứng riêng đã dùng đúng 3 nhãn này) · lượt sửa Shell kế
- Admin Shell: truyền `other-editor` (người đang sửa + giờ) khi backend có "báo người đang sửa" · Chờ backend + lượt sửa Shell kế
- Hộp duyệt: thẻ "Thay đổi của bài đang đăng" chỉ đọc `BSCW.drafts` trong phiên — bản thật đọc `entry_drafts` (state submitted) · Chờ backend
- Safari/Firefox/Samsung, cảm ứng thật, chế độ tối (đo tương phản), 200%, xoay ngang của các khối D1-4d; chạy trong Shell (`posts.edit?ma=`) với Tweak "Duyệt trước khi đăng" · Claude Code bằng zip
- Lượt kế tiếp: **D1-5** (theo prompt D1-4d; D1-5 đã xong 07/10 — owner xem lại thứ tự lượt).

## ~~D2-1~~ — xong 07/10 (README `README - D2-1 Nhóm quyền & Thành viên.md`): `Nhóm quyền.dc.html` (danh sách · tạo/sửa · "làm được gì") + `Thành viên.dc.html` (Mẫu 1), registry `roles` / `users` ready (mount trong Shell), `BSCW.IMPLIES`
- Đo trong Shell `H.mk('s',390,844,0,0,'../Admin Shell.dc.html#/site/roles')` và `#/site/users`; Safari/Firefox/Samsung; cảm ứng thật; chế độ tối · Claude Code bằng zip (môi trường thiết kế: Shell quá 7 giây)
- Admin Shell: bỏ `zoom` Chữ lớn cho `roles` / `users` và truyền `big-text` (2 màn đã dùng `--fsp`) · lượt sửa Shell kế
- Admin Shell: bỏ `pend: true` của `roles` khi repo có mục menu Nhóm quyền · lượt sửa Shell kế
- Nhóm tự tạo, thành viên: dữ liệu mẫu chép ở 2 tệp (`NQ_SEED` · `TV_CUSTOM`) — bản thật đọc bảng `roles` · build
- Owner chốt: Thùng rác cho nhóm tự tạo (bảng `roles` dùng chung mọi website) · Cần owner chốt (COUNCIL)
- ~~Lượt kế tiếp: **D2-2**~~ → xong 07/10.

## ~~D1-4c~~ — xong 07/10 (README `README - D1-4 Trình soạn nội dung.md` › D1-4c): lịch sử phiên bản theo ngôn ngữ (backend có sẵn) + SEO còn lại trong khuôn `Page Editor - Options`
- Bộ AI: bước "Kiểm định" của `AI Writer Panel v2` chấm theo `BSSEO.audit` (tệp chưa gọi BSSEO) + "Tự chỉnh N chỗ" chỉ sửa mục đỏ · 2 điểm còn lại của "Nâng cấp bài này" (xem trước phần sẽ thêm; tiến trình từng việc) · kiểm tay luồng "Việc lớn → Trợ lý viết" · bộ AI (sau core v1)
- Lịch sử phiên bản: Safari/Firefox/Samsung, cảm ứng thật, chế độ tối đo bằng harness, chạy trong Shell (`<loại>.edit?ma=`) · Claude Code bằng zip
- Lượt kế tiếp: **D1-4d**.

## ~~D1-7~~ — xong 07/10 (README `README - D1-7 Khách liên hệ & Biểu mẫu.md`): `Khách liên hệ.dc.html` (mẫu 5, khóa `contacts`) + `Biểu mẫu.dc.html` (danh sách + trình sửa, khóa đề xuất `forms`), registry ready + mount trong Shell (`ma` · `form-ma`)
- Admin Shell: bỏ `pend: true` của mục menu `forms` khi repo có mục menu Biểu mẫu; truyền `big-text` + bỏ `zoom` Chữ lớn cho `contacts` / `forms` (2 màn đã dùng `--fsp`); số "12" trên menu đọc từ dữ liệu liên hệ thay vì gắn cứng · lượt sửa Shell kế
- Shell: `mkEmbedDirty('contacts'|'forms')` tạo hàm mới mỗi lần vẽ (như các màn khác) — gom một chỗ khi tách Shell (L1-21)
- Owner chốt: chữ trạng thái Mới · Đã xem · Đã xử lý · Thư rác; nhóm "Chỉ xem" đọc dữ liệu cá nhân; thời hạn lưu giữ (luật sư); liên kết "Chính sách bảo mật" · Cần owner chốt
- Tab / bàn phím toàn trang, Safari / Firefox / Samsung, cảm ứng thật, kéo-thả trên cảm ứng (dùng nút Lên / Xuống), chế độ tối, 200%, xoay ngang · Claude Code bằng zip
- Lượt kế tiếp: **D2-1**.

## ~~D1-5~~ — xong 07/10 (README `README - D1-5 Sản phẩm.md`): `Sản phẩm - Danh sách.dc.html` + `Sản phẩm - Trình sửa.dc.html`, registry `catalog.list` · `catalog.create` · `catalog.edit?ma=` ready (mount trong Shell), Tweak "Chế độ sản phẩm"
- Admin Shell: truyền `mode` (Giới thiệu/Bán hàng, đọc `CatalogMode`) vào 2 màn Sản phẩm; bỏ `zoom` Chữ lớn cho `catalog.*` và truyền `big-text` (2 màn đã dùng `--fsp`) · lượt sửa Shell kế
- Thân mô tả sản phẩm tự `dir="rtl"` cho العربية — đi theo lượt Trình soạn nội dung kế (đã ghi ở D1-4b)
- Tab / bàn phím toàn trang, Safari / Firefox / Samsung, cảm ứng thật (vùng bấm 44px), 200%, xoay ngang · Claude Code bằng zip
- Owner chốt: mặc định Tweak "Chế độ sản phẩm" = Bán hàng (repo chưa đặt = Giới thiệu) · Cần owner chốt
- Lượt kế tiếp: **D1-6**.

## ~~D1-4b~~ — xong 07/10 (README `README - D1-4 Trình soạn nội dung.md` › D1-4b): MỘT khuôn soạn `Page Editor - Options` cho mọi loại (prop `kind`), Quản lý Bài viết bỏ phần Thêm/Sửa (bs-goto `<loại>.create` / `<loại>.edit?ma=`), co theo khung chứa, Focus 1b, mặc định gọn + "Thêm tùy chọn", mẫu trang / Viết mới, Xem trước, câu bước tiếp thay nút im lặng, ô chọn chuyên mục (#h-ca) có tìm + khóa theo quy trình
- Hộp thoại Ảnh / Video / Liên kết / Ω của `Trình soạn nội dung`: Chữ lớn (`--fsp`), trượt đáy ở điện thoại, giữ + trả focus; thân soạn tự `dir="rtl"` cho العربية · lượt sửa Trình soạn nội dung kế (D1-4b không được sửa tệp này; chuyển từ "· D1-4b")
- Admin Shell: truyền `ai-granted` (website được cấp AI — đọc gói) vào khuôn soạn; khuôn đã đọc `aiGranted` + `upsell` (Q12) · lượt sửa Shell kế
- ~~Shell bỏ `zoom` Chữ lớn cho `<loại>.create/.edit` … · D1-4d~~ → đóng ở D1-4d: khuôn vẫn chưa dùng `--fsp` (đo Chữ lớn bằng zoom 1.143 như Shell: 0 tràn ở 360–1440, 3 tệp); chuyển khuôn sang `--fsp` + Shell bỏ zoom · lượt sửa Shell kế
- `Quản lý Bài viết - Options`: logic soạn cũ (openEditorLegacy, giá trị ed* trong renderVals) còn trong lớp nhưng không còn markup — dọn khi build / lượt danh sách kế
- Chọn chuyên mục của loại khác khi loại KHÔNG bật duyệt: prototype chỉ báo "bài chuyển sang <loại>", chưa chuyển bài thật sang danh sách loại đó · Chờ backend (keepsGovernedSection) — phần D1-4d đóng 07/10 (câu lỗi `change_err_category_governed` có trong Tweak "Lỗi thay đổi")
- Luồng (2) D0-5 với Tweak "Duyệt trước khi đăng" + Người viết → Chủ website trong Shell: đã chạy luồng Chủ website (Thêm → Đăng → danh sách "Đã đăng" → Sửa mở `?ma=`); đổi Tweak của Shell bằng script chưa làm được · Claude Code bằng zip (`_audit/d05-run.html`)
- ~~Lượt kế tiếp: **D1-4c** (lịch sử phiên bản + SEO còn lại).~~ → xong 07/10; kế tiếp D1-4d.

## ~~D1-3~~ — xong 07/10 (README `README - D1-3 Danh sách nội dung.md`): một danh sách cho Trang · Tin tức · Dịch vụ · Dự án (`Quản lý Bài viết - Options`, prop `contentType`), quyền từng dòng BSCW, Thùng rác + Hoàn tác, thẻ gọn < 768
- ~~**D1-3 phần 3**~~ → xong 07/10 (README D1-3 › Phần 3: `Chuẩn giao diện SaaS Starter.dc.html#co-gian-tuong-tac`, CSS `dli-` trong `admin-components.css`, đã xóa tệp cũ) · mục 9: dời bản tương tác "Danh sách co giãn" #5a (menu trạng thái, menu bản ngôn ngữ, tab + Khác, Lọc, Sắp xếp, Chọn nhiều, hộp Hẹn giờ đăng) vào mục Co giãn của `Chuẩn giao diện SaaS Starter.dc.html`, kèm mẫu Bài viết / Sản phẩm / dòng Chỉ xem (CSS mới vào `admin-components.css`), rồi xóa `Danh sách co giãn - Đã chốt.dc.html` · D1-3 phần 3
- Lưới mẫu còn lại (README › Lưới mẫu) + luồng D0-5 chạy lại `_audit/d05-run.html` · Safari/Firefox/Samsung, cảm ứng thật, 200% · D5-2 / Claude Code bằng zip
- ~~Lượt kế tiếp: **D1-4a**.~~ → xong 07/10.

## ~~D1-4a~~ — xong 07/10 (README `README - D1-4 Trình soạn nội dung.md`): rà 20 nhóm nút (20/20 đạt sau sửa), thanh gọn 7 nhóm + "Thêm công cụ" nhớ theo người, thanh 1 hàng cuộn ngang ở điện thoại + ngăn trượt đáy, popup "Viết lại" neo dưới đoạn, Focus 1b (`data-focus-wrap`), thang chữ thân bài một nơi
- Hoàn tác / làm lại, bôi đen bằng chạm, bàn phím ảo, ngăn trượt đáy trên Safari iPhone/iPad, Firefox, Samsung Internet · Claude Code bằng zip (môi trường thiết kế chỉ có Chromium)
- Dán thật từ Word / Zalo / trang web (cần bộ nhớ tạm của hệ điều hành) · Claude Code bằng zip — prototype chỉ thử bằng sự kiện dán giả lập
- ~~Page Editor - Options: bỏ bản `.pe-rte` chép riêng · D1-4b~~ → xong 07/10
- ~~Page Editor - Options: khung chứa có `container-type` … · D1-4b~~ → xong 07/10: khuôn co bằng flex-wrap, không đặt container-type lên tổ tiên của ô soạn
- ~~Hộp thoại Ảnh / Video / Liên kết / Ω … · D1-4b~~ → chuyển sang khối D1-4b (lượt sửa Trình soạn nội dung kế)
- ~~Lượt kế tiếp: **D1-4b**.~~ → xong 07/10; kế tiếp D1-4c.

## ~~D2-3~~ — xong 07/10 (README `README - D2-3 Tài khoản của tôi.md`): trang `Tài khoản của tôi.dc.html` (5 mục, 2 mặt) thay hộp Tài khoản của Shell; registry `account` ready (kind mount)
- Đo trong Shell `H.mk('s',390,844,0,0,'../Admin Shell.dc.html#/site/account')` và `#/platform/account`; chế độ tối; Safari/Firefox/Samsung; bàn phím ảo khi nhập mã 6 số · Claude Code bằng zip (môi trường thiết kế: Shell quá 7 giây)
- Shell: Tùy biến (Customizer) chưa có ô "Giảm chuyển động" / "Tương phản cao" — Shell đã nhận 2 khóa `motion`, `contrast` trong `bs-ui` nhưng chưa áp lên khung (`data-motion`, bộ màu tương phản cao) · lượt sửa Shell kế + Chờ backend
- Owner chốt: đổi email tự phục vụ · ô múi giờ riêng theo người · Cần owner chốt
- Lượt kế tiếp: **D2-4** (Thông báo — kèm mục Thông báo trong Tùy chọn của trang Tài khoản).

## ~~D1-2~~ — xong 07/10 (README `README - D1-2 Trình đơn.md`): Trình đơn — trục ngôn ngữ trong "Vị trí trên website", chip nguồn theo loại (trang · sản phẩm · chuyên mục), chữ theo Bảng thuật ngữ, ~90 mã màu → token, vùng bấm cảm ứng 44px
- Đo `_audit/harness.html` `H.layouts(['Trình đơn.dc.html'],[360,390,768,1024,1440])` × thường/Chữ lớn + trong Shell `H.mk('s',390,844,0,0,'../Admin Shell.dc.html#/site/menus')`; chế độ tối; Safari/Firefox/Samsung · Claude Code bằng zip
- Owner chốt: trục ngôn ngữ áp theo từng vị trí (đúng `menu_assignments`) hay một lần cho mọi vị trí · Cần owner chốt
- Lượt kế tiếp: **D1-3**.

## ~~D1-6~~ — xong 06/10 (README `README - D1-6 Thư viện ảnh & tệp.md`): `Thư viện ảnh & tệp.dc.html` (registry `media` ready, mount trong Shell) + MediaPicker (lỗi từng tệp 16 mã, nút "Lọc" ở điện thoại, nền ô tệp) + sửa kèm D0-6c
- Đo `_audit/harness.html` `H.layouts(['Thư viện ảnh & tệp.dc.html','MediaPicker.dc.html'],[360,390,768,1024,1440])` × thường/Chữ lớn + trong Shell `H.mk('s',390,844,0,0,'../Admin Shell.dc.html#/site/media')`; chế độ tối; Safari/Firefox/Samsung · Claude Code bằng zip (môi trường thiết kế: Shell không chụp được — quá 7 giây)
- MediaPicker còn lọc/tùy chọn ngoài phạm vi repo (Video, Âm thanh, MP4, XLSX, MP3) và nút "Xóa" ở thanh chọn nhiều — đổi theo luật Thùng rác khi chạm MediaPicker lần sau · lượt MediaPicker kế
- Owner chốt: SVG (bộ làm sạch) · thư mục: quyền đổi tên/xóa thư mục dùng `media.upload` hay quyền riêng · Cần owner chốt
- Lượt kế tiếp: **D1-7**.

## ~~D1-1~~ — xong 06/10 (README `README - D1-1 Chuyên mục.md`): `Chuyên mục.dc.html` cho mọi loại + Sản phẩm; registry `<loại>.cats` / `catalog.cats` ready
- ~~Đã nối vào Shell 06/10 (kind mount)~~ Admin Shell · thêm khe `<dc-import name="Chuyên mục" embedded embed-id="taxonomy" content-type="{{ ctType }}" role="{{ roleName }}" lang-codes="{{ langCodes }}" on-dirty>` cho `<loại>.cats` + `catalog.cats` (`content-type="products"`), rồi đổi `kind: 'design'` → `'mount'` trong `shell-screens.js`; bỏ `zoom` Chữ lớn cho màn này (màn đã dùng `--fsp`) · lượt sửa Shell kế tiếp
- Đo `_audit/harness.html` 360/390/768/1024/1440 × thường/Chữ lớn + trong Shell 390; chế độ tối; Safari/Firefox/Samsung · Claude Code bằng zip
- Owner chốt: bộ đếm theo chuyên mục chính hay mọi chuyên mục; bài "Bỏ gắn" rời loại nội dung thì theo quyền/quy trình nào · Cần owner chốt
- Lượt kế tiếp: **D1-2**.

## ~~D0-6c~~ — xong 06/10 (README `README - D0-6 Hiển thị đa thiết bị.md` › D0-6c phần 1 + phần 2): 12 màn bộ AI, 120 lượt đo 0 tràn
- Nhãn nút AI còn sót (grep 35 tệp, 06/10) — đổi theo C.15 khi chạm màn:
  - ~~`Quản lý Bài viết - Options` … → D1-4b~~ → đóng 07/10: phần Thêm/Sửa đã gỡ
  - ~~`Page Editor - Options`: "Viết giúp", … · D1-4d~~ → xong 07/10 (grep còn 1 chỗ "Nhờ AI viết N chỗ" → "Nâng cấp trang / Nâng cấp bài viết (N chỗ)"; 4 nhãn kia 0 chỗ)
  - `Admin Shell`: "Dịch bằng AI" → "Dịch" · lượt sửa Shell kế tiếp
- Chip ngôn ngữ tự vẽ (VI/EN/JA, 🔒 Chung) theo mẫu cũ: grep 35 tệp → 0 chỗ còn mẫu cũ (12 màn D0-6c đã dùng `.lc`). Màn danh sách/trình soạn dùng chip riêng của khuôn danh sách — rà lại khi chạm (D1-3 / D1-4b)
- Bộ AI: công tắc trong hàng (Cài đặt nội dung AI, Cài đặt xuất bản tự động) đã dùng `.dsw[aria-checked]` chuẩn nhưng nút bọc chưa có `role="switch"` + `aria-checked` — thêm khi build · Claude Code
- Block Library: xóa khối vẫn hỏi xác nhận (luật mới: vào Thùng rác + toast Hoàn tác, chỉ hỏi khi khối đang dùng ở N nơi) — Chờ backend Thùng rác cho khối · lượt Block Library kế
- Hạn ngạch AI và mua thêm: khi Tweak "Lời mời nâng gói" TẮT, các màn khác không trỏ tới trang này (đã thay bằng "Cần thêm? Liên hệ hỗ trợ"); bản thân trang không ẩn nút mua — owner chốt có cần mặt "tắt" riêng · Cần owner chốt
- Kiểm Safari/Firefox/Samsung, chế độ tối, 200%, cảm ứng thật, bàn phím ảo của D0-6c; đo 12 màn trong Shell (chưa có trong registry) · Claude Code bằng zip
- Màn chủ ngoài phạm vi D0-6c còn hộp trợ lý AI dạng popup giữa màn (chưa trượt đáy ở điện thoại): `Quản lý Bài viết - Options` (dòng ~969, khung `width:min(1080px,100%)`) · `Page Editor - Options` (`writerCardStyle`) — làm như `.tw-ov`/`.tw-modal` của Trợ lý viết v2 · D1-4b (phần soạn, D1-3 không sửa) / ~~D1-4d~~ → Page Editor xong 07/10 (`.pe-wr-ov` dưới 600px: trượt đáy 92dvh, chừa safe-area)


## ~~D0-6b~~ — xong 06/10 (README `README - D0-6 Hiển thị đa thiết bị.md` › D0-6b)
- Admin Shell · `p-social` mount Đăng nhập nhanh với `account-type="saas"` + `embed-id="social-saas"` → đổi thành `account-type="platform"` (panel đã có nhánh; hiện tự suy từ embed-id) · lượt sửa Shell kế tiếp
- Admin Shell · hộp chọn ảnh (2 chỗ `dc-import name="MediaPicker"`): khung bao `padding:24px; width:min(1020px,100%); height:min(660px,100%)` → dưới 600px toàn màn (padding 0, `height:100dvh`, chừa safe-area) — MediaPicker đã tự bỏ bo/viền khi khung hẹp · lượt sửa Shell kế tiếp
- Nút liên hệ nổi (xóa kênh) · Email (đưa mẫu về gốc): rà theo luật "làm ngay + toast Hoàn tác 8 giây / hỏi khi không hoàn tác được" — lượt D0-6b chỉ sửa co giãn, chưa rà hành vi · khi chạm màn
- Kiểm Safari/Firefox/Samsung, chế độ tối, 200%, cảm ứng thật, bàn phím ảo của D0-6b · Claude Code bằng zip

## D0-6a phần 1 — xong 06/10 (README `README - D0-6 Hiển thị đa thiết bị.md`): Quy trình duyệt (thẻ gọn / 3 cột / hộp trượt đáy) · Admin Shell (safe-area, activeAs, ai-job thanh ngôn ngữ, link admin-components.css) · `.sx-dot` → token
## ~~D0-6a phần 2~~ — xong 06/10 (README D0-6 › Phần 2): Hộp duyệt (mẫu 5, khung đọc 100dvh, h1, emoji, "vừa gửi", length-field + ai-assist, token) · Trạng thái duyệt · Trình đơn (data-focus-wrap, role="status", onDirty(n)/onInvalid(n)/firstError, nút lên/xuống) · Shell "N lỗi · Xem lỗi đầu tiên" cho Trình đơn
- ~~Hộp duyệt: khung so sánh khác biệt chưa có … · D1-4d~~ → xong 07/10 cho thay đổi của bài đang đăng (hộp "So sánh" toàn màn dưới 600px); so sánh cho bài mới gửi lại lần 2 · Chờ backend
- Kiểm Safari/Firefox/Samsung, chế độ tối, 200%, cảm ứng thật, safe-area thật của D0-6a · Claude Code bằng zip
- ~~Lượt kế tiếp: **D0-6b**.~~ → xong 06/10. Lượt kế tiếp: **D1-1** (D0-6c chạy lúc nào cũng được).

## ~~D0-5~~ — xong 06/10 (README `README - D0-5 Nối màn.md`)
- ~~Tách CSS component ra `admin-components.css`~~ → xong: hai trang chuẩn link sau `admin-base.css`, `<style>` chỉ còn reset trang; `--fsp:0px` mặc định; lớp trùng tên của trang bổ sung khoanh trong `[data-cs="sup"]` (không đổi tên). Đo `_audit/d05-std.html`: 0 tràn trang ở 360/390/768/1440 × thường/Chữ lớn; sơ đồ Mẫu 1/8 0 cắt · 0 vỡ từ; ô `--brand-*` 0 cắt.
- Lượt tách CSS · xem bằng mắt vùng cuộn `.tw` ở `#he-thong` (khung 390/768/1240) và phần tử `overflow:hidden` ở `#dieu-khien` khi bật Chữ lớn — chữ có bị cắt không (môi trường thiết kế chỉ đo bằng script) · Claude Code kiểm bằng zip
- ~~Màn link `admin-components.css` lần đầu thì bỏ `.dsw` tự khai trong cùng lượt (~~Shell~~ xong D0-6a · Trình đơn D0-6a phần 2 · Email Settings, Đăng nhập nhanh, Integrations, Floating Contact D0-6b)~~ → xong 06/10 (4 panel D0-6b)
- Chữ lớn: Shell còn phóng `zoom` 16/14 cho cả màn nhúng — màn nhúng đã nối (Bài viết, Trang, Page Editor, Hộp duyệt) chưa dùng `--fsp`; bỏ `zoom` khi màn chuyển sang `--fsp` · ~~D1-3 (danh sách)~~ xong 07/10 (Shell bỏ zoom cho `<loại>.list`) · ~~D1-4d (trình soạn)~~ → chuyển: khuôn chưa dùng --fsp (xem mục D1-4b) · lượt sửa Shell kế
- ~~Ô nhập theo ngôn ngữ tự `dir="rtl"` · D1-4b~~ → xong 07/10 ở khuôn soạn (bộ 14 ngôn ngữ có العربية; tiêu đề, mô tả ngắn, Google, mô tả ảnh tự rtl; đường dẫn giữ ltr). Thân soạn: lượt Trình soạn nội dung kế
- Nút AI ẩn khi "Lời mời nâng gói" TẮT: Shell đã truyền `upsell` + `plan-key` vào Bài viết, Trang, Page Editor; màn chưa đọc prop này để ẩn/khóa nút AI · ~~D1-3 (danh sách)~~ xong 07/10 · ~~D1-4b (trình soạn)~~ xong 07/10 (prop `aiGranted` + `upsell`)
- ~~Màn Sửa (`<loại>.edit`) chưa tô mục menu "Danh sách" đang chọn · D0-6a~~ → xong 06/10 (Shell đọc `activeAs`)
- ~~Kho `BSCW.store`: thân bài (body) chưa lưu vào kho … · D1-4d~~ → xong 07/10 (`writeStore` ghi `bodyText` + `excerpt`)
- Hộp duyệt khi nhúng: "chờ 0 ngày" cho bài vừa gửi trong phiên → "vừa gửi" · ~~D0-6a phần 2~~ xong 06/10
- ~~Lượt kế tiếp: D0-6a.~~ → phần 1 xong 06/10; còn phần 2.

## ~~D0-4b~~ — xong 06/10 (README `README - D0-4 Admin Shell v2.md` › D0-4b phần chính)
- ~~Màn đã có thiết kế nhưng chưa nhận `embedded`: Quản lý Bài viết - Options + Quản lý Trang - Options · D1-3~~ → xong ở D0-5 (phần nhúng)
- ~~Page Editor - Options: nhận `embedded` + `onDirty(n)` + `bs-embed-cmd {act:'save'}` · D1-4d~~ → xong ở D0-5
- ~~Hộp duyệt: nhận `embedded` + đọc chế độ duyệt từ Shell · D0-6a~~ → xong ở D0-5 (Hộp duyệt không có h1 riêng; Shell giữ `ownHeader`)
- Chuẩn giao diện SaaS Starter: nhận `embedded` (Shell đang mount nguyên trang, ẩn h1 của Shell) · lượt tách CSS (sau D0-3)
- Owner chốt bảng `BSAI.PLAN_FEATURES` (giá trị theo gói của workflow.editorial · sites.max · media.storage_mb · locales.max · custom_domains.max · rbac.custom_roles — đề xuất của thiết kế) · Cần owner chốt
- ~~Tweak "Số ngôn ngữ" 8 chưa có العربية (RTL) · D0-5~~ → chuyển D1-4b (xem mục D0-5)
- ~~Lượt kế tiếp: D0-5.~~ → xong 06/10; kế tiếp D0-6a.

## ~~D0-4a phần 2~~ — xong 06/10 (README `README - D0-4 Admin Shell v2.md` › Phần 2)
- ~~Mục 2 topbar · Mục 3 Cài đặt + thanh Lưu dính đáy + onDirty(n)/onInvalid(n)/firstError phía Shell · Mục 5 bản đồ màn hình~~ → xong.
- ~~Phía panel nhúng (Shell đã nhận số/lỗi và gửi `firstError`): Email Settings · Đăng nhập nhanh · Integrations Panel · Floating Contact Panel — `onDirty(n)` + `onInvalid(n)` + nghe `bs-embed-cmd {act:'firstError'}` · D0-6b~~ → xong 06/10 (D0-6b; đo trong Shell: sửa sai 1 ô → "1 lỗi · Xem lỗi đầu tiên" → focus đúng ô)
- Phía panel nhúng: Trình đơn — `onDirty(n)` + `onInvalid(n)` + nghe `firstError` · ~~D0-6a phần 2~~ xong 06/10
- Chữ lớn: Shell đặt `--fsp` trên khung gốc + phóng nội dung 16/14 (`zoom`) vì chữ của Shell viết px; khi màn nhúng dùng `--fsp` thì bỏ `zoom` để không phóng hai lần · ~~D0-5~~ → D0-6a (xem mục D0-5)
- ~~Tweak "Lời mời nâng gói" cho dòng "Tạo website mới — khóa theo gói" + nội dung Trợ giúp theo registry màn · D0-4b~~ → xong 06/10 (bật: "Xem gói nâng cấp"; Trợ giúp đọc `help` của registry)
- ~~Tổng quan ở 360px: lưới giữa đã đổi `minmax(0,…)`; vẽ lại cả màn · D2-5~~ → xong 07/10 (D2-5: `Tổng quan.dc.html`, 0 tràn 360–1440 × thường/Chữ lớn)
- ~~Email Settings ở 390: dải tab cắt chữ ('Chữ ký & thương hiệu' bị che), thẻ trạng thái quá hẹp · D0-6b~~ → xong 06/10 (tab ngắn "Chữ ký" dưới 600px khung chứa; thẻ trạng thái xuống dòng, nút "Gửi thử" rộng hết)
- ~~Tổng quan ở 360: nút '7 ngày / 30 ngày' xuống 2 dòng · D2-5~~ → xong 07/10 (D2-5: nút khoảng ngày `white-space:nowrap`, nhóm nút xuống dòng theo từng nút)
- Tạo website mới khi chỉ có 1 website: đặt ở Cài đặt › Gói & mức dùng, cạnh số website còn được tạo · D3-2
- ~~Lượt kế tiếp: D0-4b~~ → xong 06/10; kế tiếp D0-5.

## ~~D0-3 phần 2~~ — xong 05/10: 8 mẫu trang `#mau-trang` (`#mau-1` … `#mau-8`) + kiểm hiển thị đo bằng `_audit/w360.html` — xem README D0-3 › Phần 2.

## D0-3 — chuyển màn sang chuẩn về bố cục (05/10, từ `#mau-trang`)
- ~~Admin Shell: cụm "Chưa lưu · Bỏ thay đổi · Lưu thay đổi" ở đầu trang Cài đặt → thanh Lưu dính đáy ("N thay đổi chưa lưu · N lỗi · Xem lỗi đầu tiên · Bỏ thay đổi · Lưu"); `onDirty` nhận SỐ (Shell đang ép true/false) + `onInvalid(n)` + lệnh `bs-embed-cmd {act:'firstError'}` · D0-4a phần 2 (mẫu 4)~~ → xong 06/10
- Hộp duyệt: khung đọc giãn hết chiều cao (`100dvh` trừ khung Shell), bỏ `max-height:calc(100vh - 250px)` · ~~D0-6a phần 2~~ xong 06/10 (mẫu 5)
- ~~Quản lý Bài viết + Quản lý Trang: chuyển vào Thùng rác không hỏi + toast "Hoàn tác" 8 giây; khung chứa dưới 768px → thẻ gọn (600–767 hai cột) · lượt sửa màn danh sách (mẫu 1)~~ → xong 07/10 ở D1-3 (một danh sách)
- ~~Page Editor - Options: co theo khung chứa + bỏ "Bản nháp #id" · D1-4d (mẫu 3)~~ → xong 07/10 ở D1-4b
- Trang chuẩn: vùng cuộn bên trong `.tw` ở `#he-thong` (khung 390/768/1240) và phần tử `overflow:hidden` ở `#dieu-khien` khi bật "Chữ lớn" — soát bằng mắt xem có cắt chữ không · lượt tách CSS (sau D0-3)

## D0-3 — chuyển màn sang chuẩn về chữ (05/10, từ Bảng thuật ngữ `#thuat-ngu`)
- ~~Quản lý Bài viết - Options + Quản lý Trang - Options: "Xóa tất cả" → "Bỏ lọc"; "Đặt lịch" → "Hẹn giờ đăng"; nhãn trạng thái đọc từ BSCW.UI · lượt sửa màn danh sách~~ → xong 07/10 (D1-3, grep 0)
- ~~Quản lý Trang - Options: bỏ nhãn tự khai "Đã xuất bản / Đã lên lịch / Riêng tư" → BSCW.UI · lượt sửa màn danh sách~~ → xong 07/10 (D1-3)
- ~~Page Editor - Options: "Lưu trữ" → "Đã gỡ khỏi website" · D1-4d~~ → xong 07/10 ở D1-4b
- ~~Admin Shell: mục "Khách hàng" của nhóm bán hàng (người đặt hàng) → "Người mua" · D0-4a~~ → xong 06/10 (D0-4a phần 1, kèm "Nhóm người mua" + kết quả tìm mẫu)
- ~~18 màn còn lại: nhãn nút AI theo C.15 (gộp vào dòng "D0-2 · component bổ sung" — lượt riêng sau D0-3)~~ → đóng 06/10 (D0-6c): 32/35 tệp sạch; 3 tệp còn sót ghi ở mục D0-6c
- Đồng bộ Bảng thuật ngữ (P15) của design system theo `#thuat-ngu` (bản xuất của project không có file đó) · prompt DS riêng
- ~~Quản lý Bài viết - Options: câu "Vai trò … không được tạo bài" (addTitle, onAdd) → "Nhóm quyền …"; chế độ tối còn nền/chữ gắn cứng → token · lượt sửa màn danh sách~~ → xong 07/10 (D1-3; tối: 0 chữ dưới ngưỡng ở 1240/390)
- ~~Owner xem lại: repo có 3 nhóm quyền nền tảng~~ → chốt 06/10: `super_admin` Quản trị nền tảng · `support` Nhân viên hỗ trợ · `billing` Nhân viên thanh toán (bảng thuật ngữ + README D0-3)
- Owner chốt "Đề xuất chờ owner" của bảng thuật ngữ (README D0-3)
- Hộp duyệt: trạng thái trống "Không có bài nào chờ bạn — thành thơi nhé" có emoji (CLAUDE/DS: không emoji trong khung quản trị) · ~~D0-6a phần 2~~ xong 06/10

## Cập nhật 04/10/2026

## Đã xong 04/10/2026 — D0-2 Chuẩn giao diện (phần 1: Component)
Xem `README - D0-2 Chuẩn giao diện.md`. Kèm: L1-21a-3c (Remix 4.9.1 ở 15 file), L1-I01, L1-I02 xong; L1-I04 đóng (grep: mọi .dc.html gốc chỉ còn 4.9.1). X3B2 thanh ngôn ngữ: trang chuẩn không có nhãn chữ "Trình biên tập" — bản mới không xuống dòng.
Còn lại từ lượt này: đã chuyển vào "Lộ trình D0" (D0-2s, 04/10).

## D0-2 phần 3 — áp component bổ sung vào màn thật (rà 04/10; đuôi lượt gắn 06/10 ở D0-4a)
Nguồn mẫu: `Chuẩn giao diện - Component bổ sung.dc.html`. Mỗi màn sửa một lần ở đúng lượt ghi đuôi; mỗi màn kiểm 390 / 768 / 1240 × sáng + tối, ghi README "Kiểm hiển thị".
- ~~Page Editor - Options · length-field · search-preview · ai-assist · save-status · image-field · D1-4b~~ → xong 07/10 (Mô tả ngắn dừng ở 255)
- ~~Quản lý Bài viết - Options (phần Thêm/Sửa) · D1-4b~~ → đóng 07/10: phần này đã gỡ, khuôn soạn chung có đủ 5 component
- ~~Page Editor - Options · ai-job · D1-4b~~ → xong 07/10 (dịch nhiều bản)
- ~~Page Editor - Options · khung gốc dòng 66 · D1-4b~~ → xong 07/10 (0 tràn 360–1440)
- ~~Page Editor - Options · toast `role="status"` · D1-4b~~ → xong 07/10
- Quản lý Bài viết - Options · toast có vùng `role="status"` · ~~D1-3~~ xong 07/10
- Page Builder v3 · toast có vùng `role="status"` · track Builder v3
- Hộp duyệt · length-field + ai-assist · ~~D0-6a phần 2~~ xong 06/10
- Trình đơn · toast có vùng `role="status"` · ~~D0-6a phần 2~~ xong 06/10
- ~~Admin Shell · thanh ngôn ngữ: việc AI đang chạy (ai-job) · D0-6a~~ → xong 06/10 (Tweak "Mô phỏng lỗi khi dịch")
- ~~Integrations Panel · save-status · D0-6b~~ → xong 06/10 (`.sv` role="status" đầu cột + thanh riêng)
- ~~Email Settings · toast `role="status"` + nút chép biến (dòng 549, JS 1472–1474) `aria-label`/`title` "Đã sao chép <biến>" · D0-6b~~ → xong 06/10
- ~~Floating Contact Panel · toast `role="status"` + xóa mã chết `copyLabel` (dòng 439, chữ "Đã chép") · D0-6b~~ → xong 06/10 (bỏ copyLabel/copyBtn/copyIcon/onCopy)
- ~~Đăng nhập nhanh & Liên kết tài khoản · chip ngôn ngữ (prop lang/shared) khi chạm màn · D0-6b~~ → không áp dụng 06/10 — panel không có trường đa ngữ (khóa, công tắc dùng chung)
- Đợt sau: Thẻ kết nối dịch vụ (6 màn) · Ô khóa bí mật (3 màn) — dựng khi sửa Email / Đăng nhập nhanh / Integrations · D3-2
- ~~AI Writer Panel v2 · length-field + ai-assist + ai-job · D0-6c~~ → xong 06/10 (D0-6c phần 1: thước độ dài mô tả ngắn + tiêu đề/mô tả SEO, nút "Viết lại" kèm chi phí, dòng việc AI khi viết nội dung + Tweak "Mô phỏng lỗi khi viết", "mua thêm" theo Tweak "Lời mời nâng gói")
- ~~Block Library · toast `role="status"` + nút chép mã tham chiếu chép thật bằng `BSUI.copy` (`copyRef` dòng 203), nhãn "Sao chép mã tham chiếu" · D0-6c~~ → xong 06/10
- ~~Language Context · toast `role="status"` + ai-job + mẫu thanh ngôn ngữ dòng 274–283 ở 390 chuyển sang "+N" / ô chọn (tràn 54px) · D0-6c~~ → xong 06/10 (đo bề rộng thật bằng ResizeObserver; 360/390 = ô chọn, 0 tràn)
- ~~Việc AI đang chạy (ai-job) ở các màn AI: Hàng đợi ảnh, Kế hoạch SEO, Vận hành AI, Cài đặt nội dung AI · D0-6c~~ → xong 06/10 (Tweak "Mô phỏng lỗi khi chạy AI")
- ~~Mục 6 (prop `lang` · `shared` thay chip tự vẽ) — phần chưa làm kèm ở các màn trên: quét cuối · D0-6c~~ → đóng 06/10 (xem mục D0-6c)
Bảng từ nút AI: theo Bảng thuật ngữ D0-3. Câu "mua thêm" trong ai-assist / ai-job đi theo Tweak "Lời mời nâng gói" (tắt → "Cần thêm? Liên hệ hỗ trợ").

## Lộ trình D0 — chuyển từ track L1-21a (04/10/2026)
Lượt D0 không đọc AUDIT.md — mọi chỉ dẫn cần thiết nằm ở đây. Định dạng: mã · việc · lượt.
- _audit/ · thư mục kiểm thử đóng băng: mọi lượt bỏ qua _audit/ khi grep, sửa, xoá, chuyển, tìm bản trùng; không xoá · áp mọi lượt D0–D5
- uploads/ · tệp đính kèm cũ của các chat (có bản sao màn cũ ở uploads/import_ready/ và tệp của dự án khác), không phải thiết kế: mọi lượt bỏ qua uploads/ khi grep, sửa, tìm bản trùng hay đối chiếu; không xóa · áp mọi lượt D0–D5
- ~~D0-2 · kiểm hiển thị 360 / 1024 / 1440 / xoay ngang / 200% · D0-3~~ → đo xong 05/10 bằng `_audit/w360.html` (360 · 390 · 640 · 768 · 844 · 1024 · 1440, chữ thường + Chữ lớn): trang không cuộn ngang; chế độ tối, Safari/Firefox chưa kiểm — README D0-3
- ~~D0-2 · đưa trang chuẩn vào registry màn của Shell khi có D0-4b · D0-4b~~ → xong 06/10: khóa `ui-standard`, mở từ ô tìm / Ctrl+K
- D0-2 · DS: thêm các component MỚI + sửa Button/Input/Modal/Table/Dropdown theo bảng tra · prompt DS riêng · Alert: chữ dùng -text (hiện màu gốc, cảnh báo 1,65:1) và theo --fsp · Avatar: chữ tắt dùng token G2 (tokens/colors.css còn #405189, nền tối 1,96:1)
- ~~D0-2 · component bổ sung — ĐÃ CHỐT 04/10 … Còn: …~~ → chia theo khối D0-2 phần 3 (06/10)
- ~~D0-2 · đưa bản tương tác của Danh sách co giãn (#5a) vào trang chuẩn mục Co giãn … xóa `Danh sách co giãn - Đã chốt.dc.html` · D1-3 phần 3~~ → xong 07/10
- Trang chuẩn › `#co-gian-tuong-tac`: đo chế độ tối, Chữ lớn, Safari/Firefox, cảm ứng thật · Claude Code bằng zip
- ~~D0-2 · Quản lý Trang: chip mã bấm mở menu Sửa bản / Thêm bản; thẻ gọn dưới 1024px; màu + nhãn trạng thái theo BSCW.UI · lượt sửa màn danh sách~~ → xong 07/10 ở D1-3 cho Trang / Bài viết (Sản phẩm: lượt Sản phẩm)
- ~~D0-2 · tách CSS component của trang chuẩn ra admin-components.css · D0-5~~ → xong 06/10 (không đổi tên lớp — owner: `.dsw` của trang chuẩn là bản chuẩn; khung chứa ghi ở đầu file)
- ~~L1-21a-4 / L1-C-08 · số rút gọn cho biểu đồ~~ → xong ở D0-3 phần 1 (05/10): `BSUI.fmtCompact(n, {locale, max})` trong content-ui.js + quy ước ở trang chuẩn `#dinh-dang` + README D0-3. ~~Áp vào Tổng quan Shell: số rút gọn cho Tổng quan Shell · D2-5~~ → xong 07/10 (D2-5: mọi số của `Tổng quan.dc.html` qua `BSUI.fmtCompact`; trục + nhãn biểu đồ một đơn vị theo mốc trên cùng của trục).
- L1-C-09 · `Nâng cấp nội dung.dc.html` và trình soạn (`Trình soạn nội dung.dc.html`, kiểm cả `Page Editor - Options`) không link styles.css: chèn link styles.css NGAY TRƯỚC link Google URL CHUẨN (Google phải đứng sau inter.css/styles.css theo CLAUDE.md), xong xem tay chữ nút/ô nhập · ~~Nâng cấp nội dung: D0-6c~~ xong 06/10; ~~trình soạn (`Trình soạn nội dung.dc.html`): D1-4a~~ xong 07/10; ~~`Page Editor - Options`: D1-4b~~ xong 07/10
- ~~X3B2 · nút "Đi tới ô đó" ở Nâng cấp nội dung cao 19,2 px (< 44 px vùng bấm) · D0-6c~~ → xong 06/10 (44px)
- ~~X3B2 · Language Context @390 "Trình biên tập" xuống 2 dòng: nếu là nút/chip/tab thì sửa để không xuống dòng (tiêu chí 8) khi đưa Thanh ngôn ngữ vào trang chuẩn · D0-2. ("ẩn hẳn" ở Đăng nhập nhanh: nếu chỉ là chữ trong đoạn văn thì không cần làm.)~~ → xong ở D0-2
- Việc cho build (Claude Code xử lý khi dựng Livewire/Tailwind, không làm ở prototype): L1-F-02 chữ nghiêng; L1-F-04 tự host font Google + bỏ inter.css; L1-F-05 link font do helmet DC con gắn vào Shell; Inter-italic.woff2 được tải dù trang không dùng (từ L1-T-08) · build
- L1-T-03 · theo dõi mã chèn của harness · đóng theo track, chỉ mở lại khi đo hồi quy font
- L1-T-08 · face nghiêng inter.css 'loaded' ở Shell (theo dõi thụ động) · đóng theo track, chỉ mở lại khi đo hồi quy font
- ~~L1-C-06 · mở Shell với hash lạ (`#profile`) vào mục không tồn tại: không tiêu đề, không nội dung, không về Tổng quan (`applyHash` không kiểm danh sách mục) · chưa xếp lượt~~ → xong 06/10 ở D0-4a (hash lạ bị bỏ qua); D0-4b đổi luật: hash lạ / màn không có trong registry → về Tổng quan + toast "Không tìm thấy trang này"
- ~~L1-C-P01 · Đăng nhập nhanh & Liên kết TK chưa nhận `accountType='platform'` (thêm nhánh platform trong panel) · D0-6b~~ → xong 06/10 phía panel (nhánh `platform`: banner "Bắt buộc cấu hình" + chip loại tài khoản từng dòng). Shell còn truyền `account-type="saas"` cho `social-saas` — panel tự hiểu là mặt nền tảng; đổi thuộc tính ở lượt Shell kế (dòng dưới)
- ~~L1-A-P01 · panel nhúng còn hex cứng `#2050a0` cho nền nút/công tắc/viền, chưa theo màu thương hiệu (đổi sang `var(--brand-solid,…)` / `var(--color-accent,…)`) · chưa xếp lượt~~ → xong 06/10 ở 4 panel (Email, Đăng nhập nhanh, Integrations, Nút liên hệ nổi) + MediaPicker
- L1-20 · đổi cỡ chữ px → rem, đo lại ở 125% và 200% · chưa xếp lượt
- L1-21 / L1-21b · tách Admin Shell (dữ liệu sang `shell-data.js`, chia `renderVals()`, DC con Settings Form), dựng panel khi cần, chụp ảnh bù · chưa xếp lượt

## Đã xong 30/09/2026 — Bộ màu storefront + G2 (đợt 5)
- **Bộ màu đủ 18 vai trò** (nền, tiêu đề, chữ, chữ phụ, liên kết, nút chính + hover, nút viền, thẻ con, ô nhập, kẻ, icon, focus),
  sinh bằng OKLCH; đo tự động 4.536 cặp (18 màu thương hiệu × 4 kịch bản, kể cả ghi đè thủ công) — 0 cặp dưới ngưỡng.
- Bộ **"Màu phụ"** khi khai màu thứ hai; **Tùy chỉnh bộ màu** (nền/chữ/nút từng bộ, tự chỉnh + báo); xuất biến `--sch-*` cho storefront.
- Panel Trình bày: di chuột qua bộ màu = xem trước trên trang; khoảng cách gộp 1 hàng (+ công tắc tách); "Nâng cao" thu gọn.
- Chọn/bỏ chọn section: nút "Bỏ chọn", Esc, bấm vùng trống.
- ~~G2 slate~~ gỡ 01/10 (xám, thiếu sức sống). **Áp tông 3b Bảo Sơn Hoàng gia** cho toàn bộ 29 màn + Admin Shell (thanh bên xanh hoàng gia, vạch vàng mục đang chọn, chữ mực xanh) — lớp chuyển tiếp `admin-base.css`.
  Prompt đồng bộ DS: `DS - Prompt G2.md`.
- Còn mở: màu viết cứng trong JS của từng màn (không theo token) — đổi dần khi sửa màn; chạy PR repo theo prompt G2.

## Đã xong 30/09/2026 — Audit giao diện đa thiết bị (đợt 2)
- **Chế độ tối cho mọi màn đứng riêng / nhúng:** ~4.500 mã màu cứng (nền, viền, chữ, màu ngữ nghĩa) đổi sang token
  `var(--color-*, #cũ)` của `colors.css` — light giữ nguyên, dark lật theo `[data-bs-theme=dark]`. Shell ghi
  `localStorage['bs-scheme']`; màn đứng riêng đọc lại khi mở. Giữ nguyên màu thương hiệu bên thứ 3, sao vàng `#f7b84b`.
- **Viền ô nhập** `input/textarea/select` → `--color-border-input` (B4).
- **`admin-base.css`** (nạp ở mọi DC): vùng bấm ≥44px trên cảm ứng bằng `::after` (không đổi bố cục), chữ ô nhập
  16px trên cảm ứng (iOS không phóng to), vòng focus bắt buộc cho nút/liên kết (thắng `outline:none` inline),
  `prefers-reduced-motion`.
- **aria-label** cho ~200 nút chỉ có icon (lấy từ `title`).
- **Cài đặt › Hệ thống › Gói & mức dùng** có trong Shell (nhúng `Hạn ngạch AI và mua thêm`, prop `embedded` ẩn tiêu đề);
  nút "Mua thêm / nâng gói" ở thanh ngôn ngữ mở thẳng mục này.
- Chữ < 12px → 12px; kiểu dấu ó/ủ theo bảng thuật ngữ; bảng nhiều cột → thẻ dưới 768px (Shell, Cài đặt nội dung AI,
  Kế hoạch SEO, Cài đặt xuất bản).

Còn mở sau đợt 2 (đã xử lý thêm ở đợt 3: aria-label 24 nút còn lại, viền focus cho mọi ô nhập có viền qua
`admin-base.css`, khung bao cho ô không viền, token tối cho khung quản trị của Page Builder v3 + Block Library):
- ~~`Page Builder v3` trên tablet/mobile~~ → xong đợt 4: dưới 1024px cột trái/phải thành ngăn kéo (nút "Khối" · "Thuộc tính",
  nền mờ, Esc/bấm ra ngoài đóng, thêm khối thì tự đóng); khung xem trước tự chọn cỡ máy theo bề rộng khi mở;
  chip/segmented/tab của khung quản trị đã theo token tối.
- Khung xem trước storefront trong Page Builder cố ý giữ màu sáng (màu thương hiệu tenant, không theo theme admin).
- Màu chữ ghép động trong JS (`'color:' + x`) chưa đổi token — rà khi sửa từng màn.

Chi tiết đợt 2:
- `Page Builder v3`, `Block Library` chưa đổi token tối (khung xem trước là storefront, cần luật riêng); Admin Shell đã có bảng màu tối riêng.
- 23 nút chỉ icon chưa có `title` → chưa có aria-label (nhiều nhất ở Page Builder v3).
- `outline:none` trên ô nhập: chỉ an toàn khi khung ngoài có `:focus-within` — rà từng chỗ.
- Màu chữ ghép động trong JS (`'color:' + x`) chưa đổi token — rà khi sửa từng màn.

## Đã xong 30/09/2026 — D0-1 Dọn & đồng bộ project
Xem `README - D0-1 Dọn project.md`. Còn lại từ lượt này:
- ~~Viền ô nhập `#e9ebec`~~ → xong ở đợt 2.
- `outline:none` còn trong Admin Shell (8), Email Settings (12), Quản lý Bài viết (15)… — rà từng chỗ đã có vòng thay thế (`:focus-within`) chưa.
- `Page Builder v3` còn `anvinh.baoson.net` trong khung xem trước — track Builder v3 riêng, không sửa ở D0-1.
- ~~Mục Cài đặt › Gói & mức dùng chưa có trong Shell~~ → xong ở đợt 2.


## Đã xong phiên này (audit hội đồng, F1–F5)
- **Một nguồn sự thật cho gói và hạn ngạch** — `ai-quota.js` (`window.BSAI`) nay giữ **bảng gói đầy đủ**:
  free / basic / pro / ent × `{words, images, variants, price, fit}` + `plan()` · `plans()` · `variants()` ·
  `enough()` · `enoughImages()` · `cost()` · `imageCost()` · `setPlan()` · `setUsage30()`.
  Sửa giá hay hạn ngạch chỉ sửa ở đây.
- **Bỏ nguồn trùng:** `Hạn ngạch AI và mua thêm.dc.html` (màn chuẩn §7a-bis) trước đây tự khai `PLANS` 4 gói
  → nay đọc từ helper, mảng cũ giữ lại làm `PLANS_FALLBACK` cho khung hình đầu.
- **Hết hardcode `basic`:** `Email Settings`, `Floating Contact Panel`,
  `Page Editor - Options`, `Trợ lý viết nội dung AI v2 - Options`, `AI Writer Panel v2`,
  `Sửa đoạn bôi đen bằng AI` đều đọc `plan().words` (và `variants()` cho số phương án).
  Khách gói Pro nay thấy đúng 200.000 từ, không còn kẹt ở 60.000 → chip và cổng chặn tính đúng.
- **Sổ ảnh vào helper:** `Hàng đợi ảnh` lấy `variants()` thay vì hardcode 3 phương án, `imageQuota.limit`
  theo `plan().images`, tooltip thêm câu "đủ khoảng N ảnh dùng được".
- **Câu chữ:** "ảnh thật không tốn lượt AI" → "không tốn lượt tạo ảnh nào"; "gán một lượt" → "gán một lần"
  (tránh lẫn với đơn vị hạn ngạch).
- **CLAUDE.md:** sửa §7 (đơn vị theo sổ, thêm luật "so chi phí thực trước khi cho bấm"), §9, §10 về đúng
  đơn vị *từ AI*; §7a-bis thêm điều khoản `ai-quota.js` là nguồn duy nhất của bảng gói.
- **Dọn bản cũ sai luật** (tính 1 lượt/bài, chip "Còn N/M bài AI" — trái §7a): xoá `AI Writer Panel.dc.html`,
  `AI Writer Panel v1.dc.html`, `AI Writer.dc.html`, `Trợ lý viết nội dung AI - Options.dc.html`,
  `Trợ lý viết nội dung AI v1 - Options.dc.html`. Không còn tham chiếu treo; bản đang dùng là **v2**.
- **Xóa bản trùng `Floating Contact - Settings.dc.html`** — cùng chức năng với `Floating Contact Panel`
  nhưng thiếu hợp đồng nhúng §13 (`embedded` / `onDirty` / `bs-embed-cmd`); bản đang nhúng trong Admin Shell
  là `Floating Contact Panel`.
- **Xoá `Sửa đoạn bôi đen bằng AI.dc.html`** — `Page Editor - Options` nay đã có đủ cả ba thứ từng chỉ
  có ở màn đó: nhiều phương án theo `variants()` của gói, xem khác biệt từng từ (`aiDiffParts`, nút
  "So với bản gốc"), và hoàn tác riêng cho từng lần viết lại. Giữ hai màn song song chỉ gây lệch nhau.

## Đã xong 02/08/2026 — Trình đơn
- **Nối `Trình đơn` vào Admin Shell** (§13): mục "Trình đơn" trước đây rơi vào màn trống.
  Nay Shell dựng `<dc-import name="Trình đơn" embedded embed-id="menus" on-dirty>`; panel ẩn header +
  thanh Lưu riêng, Shell hiện chip "Chưa lưu" + Bỏ thay đổi / Lưu thay đổi ngang tiêu đề.
- `navGuard` và `beforeunload` của Shell nay tính cả màn Trình đơn (`onDirtyScreen()`); Ctrl/⌘+S trên màn này
  bắn `bs-embed-cmd {id:'menus', act:'save'}`. Đã thử: sửa → chip hiện → Lưu ở Shell → panel sạch.
- `.mn-emb` bỏ lề 24px và `max-width` khi nhúng (Shell đã có lề riêng).

## Còn chờ
0. ~~**Trình đơn — còn thiếu**~~ → đóng ở D1-2 (07/10): UI Thùng rác đã có; "Khối tự dựng" chuyển vào bảng "Thiết kế có · backend chưa có" của `README - D1-2 Trình đơn.md`; khi nhúng, "Xem trên website" nằm ở đầu thẻ "Khách sẽ thấy".
1. **Áp §15 sang ~~Sản phẩm~~ (xong D1-5 07/10: ô soạn chung + Gợi ý mô tả ngắn · Viết mới · Dịch) / Chuyên mục / Bài viết** — hiện chỉ `Page Editor - Options` đủ luật (không ghi đè
   im lặng, popup dưới đoạn bôi đen, giữ cấu trúc khi viết lại, dán làm sạch, sổ hoàn tác riêng).
   Đây là việc lớn nhất còn lại và giờ đã đủ nền để nhân bản.
2. **Backend cấp số thật:** `BSAI.setPlan(<gói>)` + `BSAI.setUsage30({post:{n,words}, image:{n,runs}, …})`.
   Hiện `CURRENT='basic'` và `USAGE30` là số mẫu → câu "đủ khoảng N …" chưa theo mức dùng thật của website.
3. **Kiểm tra hoàn tác/làm lại trên Safari + Firefox** — sổ 80 mốc của Page Editor mới thử trên Chrome. D1-4a (07/10): đã chạy 20 nhóm nút trên Chromium (`_audit/tmp/rte-test.js`); Safari/Firefox ghi README D1-4 để Claude Code kiểm.
4. **`Vận hành nội dung AI` và `Cài đặt xuất bản tự động`** chưa nối helper — cần rà xem có chỗ nào tự tính
   số từ hoặc tự khai hạn ngạch.
5. **Dòng seed dự phòng** `(window.BSAI ? … : 60000)` còn lặp ở 6 file — chỉ để vẽ khung hình đầu trước khi
   `ai-quota.js` nạp xong. Bỏ được nếu runtime cho phép chờ script helmet trước lần vẽ đầu.

## D0-2s2 — đã sửa, chờ đo (05/10)
Đã sửa đủ mục 0–14 của prompt D0-2s2 + B1–B5 (rà 05/10). Trạng thái "[~]" = đã sửa trong file, chưa có số đo; chỉ đổi sang [x] khi có số đo ghi ở README "Kiểm hiển thị".
- [x] 0 · AUDIT.md có "Kiểm D0-2 bởi Claude Code (04/10/2026)" (ký tự &lt; &gt; &amp; của bản dán đã giải mã thành < > &).
- [~] 1 · cuộn ngang 360/390 (.sx-note, .sx-code) — chờ đo scrollWidth = clientWidth × sáng/tối × Chữ lớn.
- [~] 2 · pill / +N / nút Dịch 44px khi pointer:coarse (.lb-sel là .lb-pill nên đã phủ) — chờ đo 768/1024 cảm ứng.
- [~] 3 · ô tích khóa: chỉ ô mờ, tên việc màu chữ phụ — chờ đo ≥ 4,5:1.
- [~] 4 · Dải thông báo vẽ bằng markup trang (.sx-al) + bảng tra/README ghi lý do + ngoại lệ tạm P1 — chờ đo 4 loại × sáng/tối.
- [~] 5 · nhóm avatar dùng .avi (nền pha surface để chồng không lộ) — chờ đo.
- [~] 6 · thanh hàng loạt chỉ đếm dòng làm được (chọn cả 5: Người viết "Gửi duyệt (2)" + bỏ qua 3; Biên tập "Đăng (4)" + bỏ qua 1); nút khóa dùng aria-disabled.
- [x] 7 · bảng tra + README: 12 dòng.
- [~] 8 · hover:none ở ≥1024: hiện .dl-acts thay .dl-icons — chờ đo 1024 cảm ứng.
- [~] 9 · focus lớp bật lên (panel ngôn ngữ, Home/End, lọc, lịch); ô tìm của panel nằm ngoài role="menu" (B3 phương án A, đã đúng sẵn).
- [x] 10 · Danh sách co giãn a:hover + placeholder; trang chuẩn a:hover.
- [~] 11 · content-seo.js slug.hard 200 · excerpt.hard 255 — chờ kiểm BSSEO.hardCap + ô Mô tả ngắn dừng 255 ở trang bổ sung, Page Editor, Quản lý Bài viết.
- [~] 12 · trang bổ sung: Chữ lớn (--fsp, trừ khung xem trước Google giữ cỡ thật để mô phỏng chỗ cắt), .cp code xuống dòng < 480, ô 44px khi cảm ứng, bấm đệm khung focus ô, mục lục 44px, dòng Props Sao chép.
- [~] 13 · trang: Thùng rác xuống dòng đủ tên, đường dẫn xuống dòng < 480, title cho chỗ "…", theme đọc bs-scheme trước, bấm khung .sx-search focus ô, bỏ danh sách nhóm quyền dự phòng; README 73/255/257; LESSONS thêm 1 dòng.
- [x] 14 · TODO: dòng DS + 6 việc "Sửa kèm khi chạm màn".
- [x] B1 · README Việc cho backend: mâu thuẫn "quyền mới mặc định TẮT" vs PermissionRegistry.php:191 (COUNCIL).
- [x] B2 · README Việc cho backend: các việc repo TB 14–17, 20, Thấp 16/18/21 (alt đề xuất max:188).
- [x] B4 · nút hàng loạt khóa bằng aria-disabled + câu lý do.
- [x] B5 · README Alert: ngoại lệ tạm P1, gỡ khi DS sửa.
- [ ] Đo: 360/390/768/1024/1240/1440 × sáng/tối × Chữ lớn (harness `_audit/harness.html`); pointer:coarse / hover:none → Claude Code (DevTools Protocol) vì môi trường thiết kế không giả lập được.
- [ ] Xuất zip gửi Claude Code sau khi verifier không còn báo lỗi.
