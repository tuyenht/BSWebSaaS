# README — D0-6 Hiển thị đa thiết bị

## D0-6a phần 1 (06/10/2026) — Quy trình duyệt · Admin Shell

### Tổng quan
Sửa tại chỗ cách co giãn của 2 màn; nội dung, dữ liệu mẫu, tương tác giữ nguyên. Mục 3–5 (Hộp duyệt, Trạng thái duyệt, Trình đơn) chuyển sang **D0-6a phần 2** (TODO.md).

### File
- `Quy trình duyệt.dc.html` — thẻ gọn / bảng 3 cột / bảng 4 cột, hộp thoại trượt từ đáy, link `admin-components.css`, bỏ `.dsw` + focus tự khai, mã màu → token, khung xem 390/768 trong canvas.
- `Admin Shell.dc.html` — link `admin-components.css`, công tắc ngôn ngữ dùng `.dsw` chuẩn, safe-area, `activeAs`, dòng việc AI (ai-job) ở thanh ngôn ngữ Cài đặt.
- `admin-components.css` — chỉ dòng `.sx-dot[data-st=ai|miss|full|stale]` (xem Token).

### Mức hoàn thiện
Quy trình duyệt: hoàn thiện co giãn. Shell: sửa chỗ còn lỗi; Tổng quan vẫn chờ D2-5.

### Màn & trạng thái
- Quy trình duyệt: có dữ liệu · đang tải (khung xám tĩnh, bỏ animation chạy vô hạn — LESSONS) · lỗi + "Thử lại" · trống + "Bật cho thể loại đầu tiên" · không có quyền (Quản trị site) · khóa theo gói (quy trình gói cao) · thành công (toast `role="status"`).
- Shell › Cài đặt › thanh ngôn ngữ: việc AI 4 trạng thái — đang chạy "Đang dịch… 2/6 — đang làm bản English" + thanh tiến độ + "Hủy phần còn lại" · xong · lỗi "Lỗi ở bản … — đã hoàn N từ AI" + "Thử lại" · đã hủy "Đã hủy — không trừ từ AI nào cho N ô chưa chạy". Lỗi xem bằng Tweak "Mô phỏng lỗi khi dịch (bản cuối)".

### Tương tác
- Quy trình duyệt theo bề rộng KHUNG CHỨA (`.qd-wrap` là container):
  - ≥ 900px: bảng 4 cột (giữ như cũ).
  - 600–899px: bảng 3 cột — tên + số · quy trình + "Xem sơ đồ các bước" · công tắc trên, nút "Đổi quy trình" dưới (rộng hết cột).
  - < 600px: mỗi thể loại 1 thẻ 4 dòng: (1) icon + tên · công tắc + "Đang bật/Đã tắt" bên phải; (2) "148 bài · 3 người viết · 4 bài chờ duyệt"; (3) tên quy trình + "Xem sơ đồ các bước"; (4) nút "Đổi quy trình" / "Chọn quy trình" rộng hết thẻ, cao 44px. Khoảng cách dọc 8px.
  - Lỗi độ ưu tiên cũ: `.qd-c4 .qd-btn{width:auto}` (mốc 900) đè `.qd-btn{width:100%}` (mốc 560). Đã bỏ quy tắc `width:auto`; quy tắc hẹp viết `.qd-row .qd-c4 .qd-btn` (độ ưu tiên cao hơn) và đứng sau.
  - Công tắc có `aria-label` "Duyệt trước khi đăng cho <tên> · Đang bật".
  - Hộp "Đổi quy trình" (kèm sơ đồ các bước), hộp xác nhận, hộp nâng gói: < 600px trượt từ đáy, bo trên 12px, `max-height:92dvh`, chân hộp chừa safe-area, 2 nút chia đôi.
  - Khi nhúng trong Shell: `.qd-wrap.emb` bỏ lề 24px (Shell đã có lề) — ở 360 thẻ rộng 276px thay vì 228px.
- Shell: màn Sửa (`<loại>.edit`) tô mục "Danh sách" theo `activeAs` của registry (`AM_` thay `S.activeMenu` trong phần dựng menu dọc / 2 cột / ngang / "Khác").

### Ánh xạ dữ liệu
- Quy trình duyệt: `app/Modules/Admin/resources/views/livewire/workflow-settings.blade.php` (màn đã build); dữ liệu quy trình đọc `BSCW.templates()` (content-workflow.js).
- Shell: `shell-screens.js` (`resolve().activeAs`).

### Quyền & gói
Không đổi so với D0-5.

### Token
- Quy trình duyệt: `#5c5f6e` / `#6c6f80` (chữ phụ) → `var(--color-body-muted)`; nền nút chính `#2050a0` → `var(--color-admin-primary)`; viền/chấm chọn `#2050a0` → `var(--color-accent)`; viền radio `#ccd0d8` → `var(--color-border-input)`; dấu "·" → `var(--color-body-faint)` (trang trí); nốt xanh `#0ab39c` → `var(--color-admin-success)`; khung đang tải → `var(--color-surface-hover)`. Còn lại: `#fff` chữ trên nền đặc, toast nền `#1f2b4d` (nền đảo, không lật theo tối).
- Công tắc: dùng `.dsw[aria-checked]` của `admin-components.css` (bật = `--color-admin-primary`). Ở Shell, công tắc ngôn ngữ trước tô xanh lá `#0ab39c` → nay theo chuẩn (màu thương hiệu) và là `<button role="switch">` (trước là `<span>` không dùng được bằng bàn phím).
- `admin-components.css`: `.sx-dot[data-st="ai|miss|full|stale"]` → `var(--color-admin-info|warning|success|danger, <mã cũ>)`. Dùng token màu gốc, không dùng `-solid`, vì chấm trạng thái giữ màu gốc (DS: "status dots keep the base colour") — màu hiển thị không đổi; `-solid` sẽ làm chấm đậm hơn.

### Icon
Không thêm icon mới ngoài `ri-check-double-line`, `ri-error-warning-line`, `ri-close-circle-line`, `ri-sparkling-2-line`, `ri-refresh-line`, `ri-close-line` (dòng việc AI).

### Chuỗi cần dịch
"Đang dịch… {done}/{total} — đang làm bản {lang}" · "Xong — đã dịch {n} trường, dùng {w} từ AI, còn {left}." · "Lỗi ở bản {lang} — đã hoàn {w} từ AI. Các bản đã dịch vẫn giữ; bấm Thử lại để dịch tiếp phần còn trống." · "Đã hủy — không trừ từ AI nào cho {n} ô chưa chạy. Phần đã dịch ({m} ô) vẫn giữ." · "Hủy phần còn lại" · "Thử lại" · "Đóng" · "Duyệt trước khi đăng cho {name} · Đang bật/Đã tắt" · "Khung xem 390px · 768px (ghi chú thiết kế)".

### Kết quả 12 tiêu chí (Quy trình duyệt)
1 đạt (tiêu đề + mô tả + 1 nút mỗi dòng) · 2 đạt (menu → màn → Đổi quy trình → Áp dụng) · 3 đạt · 4 đạt · 5 không áp dụng (không có ô nhập ngoài lý do) · 6 đạt (tắt không xóa bài; hỏi khi còn bài chờ) · 7 đạt (công tắc + chữ Đang bật/Đã tắt) · 8 đạt (nút 1 dòng, không cuộn ngang) · 9 đạt ở 360/390/768 · 10 đạt · 11 đạt (Chữ lớn → 360/390 vẫn thẻ, 768 trong Shell vẫn 3 cột) · 12 đạt.

### Kiểm hiển thị
Đo bằng trang đo tạm `_tmp/m.html` (cùng cách với `_audit/harness.html`: iframe theo bề rộng, so `scrollWidth` với `clientWidth` và đếm phần tử vượt mép phải không bị khung cuộn cắt). Chữ lớn mô phỏng: `--fsp:2px` + `zoom:16/14` trên `body` (Shell thật dùng cùng hệ số).

| Màn | 360 | 390 | 768 | 1024 | 1440 | 844×390 | Chữ lớn |
|---|---|---|---|---|---|---|---|
| Quy trình duyệt — đứng riêng | đạt · thẻ 4 dòng, nút 245px = thẻ − lề | đạt · thẻ, nút 275/301 | đạt · 3 cột | đạt · 4 cột | đạt · 4 cột | đạt · 3 cột | đạt (360/390 thẻ, 768 3 cột) |
| Quy trình duyệt — trong Shell (`#/platform/workflow`) | đạt · thẻ, nút 250px | đạt · thẻ, nút 280px | đạt · 3 cột | đạt · 3 cột | đạt · 4 cột | — | đạt |
| Shell › Tổng quan | đạt | đạt | đạt | đạt | đạt | — | đạt |
| Shell › Bài viết (nhúng) | đạt (không cuộn ngang trang) | đạt | đạt | đạt | đạt | — | đạt |
| Shell › Trang (nhúng) | đạt (không cuộn ngang trang) | đạt | đạt | đạt | đạt | — | đạt |
| Shell › Cài đặt | đạt | đạt | đạt | đạt | đạt | — | đạt |

`scrollWidth = clientWidth` ở mọi ô (50 lượt đo Shell + 21 lượt Quy trình duyệt, 0 phần tử tràn, 0 lỗi JS).
Trình duyệt: Chrome (môi trường thiết kế) — đạt. Safari iPhone/iPad, Firefox, Samsung Internet, chế độ tối, phóng to 200%, `pointer:coarse` (nút 44px), safe-area thật: **[CHƯA KIỂM ĐƯỢC]** — môi trường thiết kế không giả lập được; Claude Code kiểm bằng zip.
CSS mới: container query (Safari 16+), `env(safe-area-inset-*)`, `dvh` có giá trị `vh` dự phòng đứng trước.

### Shell — kết quả kiểm lại sau D0-4a/4b
- Ngăn kéo dưới 1024px, nút "Menu" có chữ: đã có, không sửa.
- Topbar 390: không tràn (đo).
- Khung chứa màn nhúng 390/768: không cuộn ngang (đo, Bài viết + Trang + Quy trình duyệt).
- Safe-area (mới): `viewport-fit=cover`; khung gốc chừa trái/phải; topbar thêm `padding-top` + cao `--topbar-h + inset-top`; ngăn kéo menu chừa trên/dưới/trái; toast chừa đáy. Thanh Lưu dính đáy, ô tìm toàn màn, Trợ giúp, bảng chọn website đã có sẵn.
- CSS component trùng: chỉ `.dsw` (đã bỏ). Không lớp nào khác của `admin-components.css` trùng tên lớp trong Shell (đo bằng script).
- Chữ lớn: Shell vẫn phóng `zoom` 16/14 cho màn nhúng, vì Bài viết / Trang / Page Editor / Hộp duyệt chưa dùng `--fsp` — điều kiện bỏ `zoom` chưa có, chuyển D1-3 / D1-4d.

### Việc cho backend
1. **Quy trình duyệt** — `app/Modules/Admin/resources/views/livewire/workflow-settings.blade.php` (đã build). Port:
   - Danh sách thể loại co theo khung chứa (`@container` trên khung màn, không `@media`): ≥ 900px 4 cột; 600–899px 3 cột (cột 3 = công tắc trên, nút dưới rộng hết cột); < 600px thẻ 4 dòng bằng `grid-template-areas: "name sw" "sub sub" "wf wf" "btn btn"`, khối tên dùng `display:contents` để tên và dòng số là 2 ô lưới riêng.
   - Bỏ mọi quy tắc `width:auto` cho nút ở mốc rộng; nút hẹp `width:100%`, cao ≥ 44px.
   - Công tắc dùng `<x-bs-admin::switch>` (lớp `.dsw[aria-checked]`), nút bọc có `role="switch"` + `aria-label` "Duyệt trước khi đăng cho {tên} · {trạng thái}"; không tự viết focus.
   - Hộp thoại `<x-bs-admin::modal>` dạng trượt từ đáy khi khung < 600px (`align-items:flex-end`, bo trên 12px, `max-height:92dvh`, chân chừa `env(safe-area-inset-bottom)`).
   - Khi trang nằm trong khung quản trị, bỏ lề riêng của khung màn.
2. **Hộp duyệt** (`…/livewire/entries/review-inbox.blade.php`) và **khối Trạng thái duyệt** (`…/livewire/entries/editor.blade.php`): ghi ở D0-6a phần 2.
3. Shell (`app/Modules/Admin/resources/views/layouts/app.blade.php`): `viewport-fit=cover` + safe-area như trên; mục menu đang chọn đọc `activeAs` của sổ màn (màn Sửa tô "Danh sách"); dòng việc AI khi dịch nhãn cài đặt: mỗi ngôn ngữ một giao dịch (idempotency key theo bản), hủy = không trừ phần chưa chạy, lỗi = bút toán đảo đúng phần đã giữ.

### Verifier
Phần 1: verifier không báo lỗi.

## D0-6a phần 2 (06/10/2026) — Hộp duyệt · Trạng thái duyệt · Trình đơn

### File
- `Hộp duyệt.dc.html` — mẫu 5: thẻ hàng chờ, một khung + "Quay lại", khung đọc giãn hết chiều cao, xem bài toàn màn, nút quyết định dính đáy, hộp thoại trượt đáy, length-field + ai-assist, h1, token chữ phụ, bỏ emoji, "vừa gửi".
- `Trạng thái duyệt (khối soạn thảo).dc.html` — token, bỏ focus tự viết, hộp thoại trượt đáy, khung đang tải tĩnh.
- `Trình đơn.dc.html` — link `admin-components.css`, 3 `style-focus-within` → `data-focus-wrap`, toast `role="status"`, `onDirty(n)` + `onInvalid(n)` + `firstError`, lưới theo khung chứa, `100dvh`.
- `Admin Shell.dc.html` — Trình đơn nhận `on-invalid`; cụm Lưu đầu trang Trình đơn hiện "N lỗi · Xem lỗi đầu tiên"; 2 `max-height` thêm `dvh`.

### Màn & tương tác
- **Hộp duyệt**
  - ≥ 940px khung chứa: 2 khung; khung đọc `position:sticky`, cao `100dvh − --sticky-top − 32px` (Shell cấp `--sticky-top`, đứng riêng = 0), cuộn bên trong, chân nút luôn ở đáy khung. Bỏ `max-height:calc(100vh − 250px)`.
  - < 940px: chỉ một khung. Chọn bài → khung đọc phủ toàn màn (`position:fixed`, chừa safe-area), nút "Quay lại" ở đầu, "Duyệt đăng"/"Trả lại" dính đáy, chừa thanh home.
  - < 600px: mỗi bài trong hàng chờ là một thẻ (viền + bo 6px, cách 8px); hộp "Trả lại"/"Duyệt đăng" trượt từ đáy.
  - So sánh khác biệt: màn này chưa có khung so sánh (không thêm tính năng mới ở lượt co giãn) — khi D1-4d làm so sánh, khung đó theo đúng luật toàn màn ở trên.
  - length-field (chỉ đọc): thước độ dài cho Mô tả ngắn (`excerpt`), Tiêu đề trên Google (`seoTitle`), Mô tả trên Google (`seoDesc`), mốc đọc từ `BSSEO.meter` (content-seo.js), không tự khai số.
  - ai-assist: chỉ hiện khi ô Google còn TRỐNG (§15: ô trống điền thẳng) — "Viết tiêu đề bằng AI" / "Viết mô tả bằng AI", tooltip nói trước số từ AI; sau khi điền có chip "Hoàn tác gợi ý AI". Bài không có chữ → "không trừ từ AI nào".
  - Tiêu đề "Hộp duyệt" là `<h1>` (cỡ 20px, vị trí giữ nguyên). Trong Shell đo được đúng 1 h1.
  - Trống: "Không có bài nào chờ bạn — thảnh thơi nhé" (bỏ ☕). Bài vừa gửi trong phiên: "vừa gửi" thay "chờ 0 ngày".
  - "Xóa lọc" → "Bỏ lọc" (Bảng thuật ngữ).
- **Trạng thái duyệt (khối soạn thảo)**: 390/768 không tràn; nút 44px ở < 560px và cảm ứng (đã có); hộp thoại < 560px trượt từ đáy.
- **Trình đơn**
  - Đổi thứ tự bằng nút lên/xuống ở mọi dòng (đo ở 390: 12 nút, rộng 44px); kéo-thả bằng tay cầm vẫn giữ cho chuột, cảm ứng ẩn tay cầm (đã có).
  - Lỗi: mục chưa có nhãn ở bản nguồn. Ô Nhãn có `aria-invalid` + dòng lỗi "Mục này chưa có tên. Nhập chữ khách sẽ thấy, ví dụ: Liên hệ." Bấm Lưu khi còn lỗi → không lưu, nhảy tới lỗi đầu tiên.
  - Shell: cụm Lưu ở đầu trang Trình đơn hiện "1 lỗi · Xem lỗi đầu tiên"; bấm → Shell gửi `bs-embed-cmd {id:'menus', act:'firstError'}` → panel chọn đúng trình đơn + mục, về bản nguồn, focus ô Nhãn. Đo bằng script: xóa nhãn → "1 lỗi", nút có, focus rơi vào ô `aria-invalid="true"`.
  - Lưới 3/2/1 cột theo khung chứa (`@container` 1210 / 860) thay `@media` 1260 / 920.

### Token
Hộp duyệt + Trạng thái duyệt: chữ `#5c5f6e` / `#6c6f80` (`.sp-cap`, `.sp-hint`, `.sp-flabel`, `.sp-wfname`, `.sp-chip.sec`, `.sp-fhd`, chữ phụ khác) → `var(--color-body-muted)` (≥ 4,5:1 sáng và tối); nền đặc `#2050a0` → `var(--color-admin-primary)`; viền/vạch `#2050a0` → `var(--color-accent)`; `#ccd0d8` → `--color-border-input` (ô tích) / `--color-body-faint` (mũi tên trang trí); `#f06548` viền ô lỗi → `--color-admin-danger-text`; `#0ab39c`, `#f7b84b`, `#e6cf9b` → token màu gốc. Bỏ focus tự viết (`.sp-item`, `.sp-fhd`, `.sp-ta`, `.ws-ta`, `.ws-sel`) — dùng luật chung Focus 1b.

### Kiểm hiển thị (phần 2)
Đo bằng `_tmp/m.html` (50 lượt: 5 bề rộng × chữ thường/Chữ lớn × đứng riêng/trong Shell).

| Màn | 360 | 390 | 768 | 1024 | 1440 | Chữ lớn |
|---|---|---|---|---|---|---|
| Hộp duyệt — đứng riêng | đạt · thẻ 286px, đọc toàn màn | đạt · thẻ 316px | đạt · 1 khung, đọc toàn màn | đạt · 2 khung, khung đọc cao 770px | đạt · 2 khung | đạt (1024 → 1 khung) |
| Hộp duyệt — trong Shell (`#/site/review`) | đạt · 1 h1 | đạt | đạt | đạt | đạt | đạt |
| Trạng thái duyệt | đạt | đạt | đạt | đạt | đạt | đạt |
| Trình đơn — đứng riêng | đạt · nút lên/xuống 44px | đạt | đạt | đạt | đạt | đạt |
| Trình đơn — trong Shell (`#/site/menus`) | đạt | đạt | đạt | đạt | đạt | đạt |

`scrollWidth = clientWidth`, 0 phần tử tràn ở mọi ô. Hộp duyệt trong Shell: kho bài mẫu không có bài chờ nên chỉ đo được trạng thái trống — thẻ và khung đọc toàn màn đo ở bản đứng riêng.
Grep 5 màn: 0 `style-focus-within`, 0 "☕", `100vh` chỉ còn làm giá trị dự phòng đứng trước `100dvh`.
Trình duyệt khác, chế độ tối, 200%, cảm ứng thật: **[CHƯA KIỂM ĐƯỢC]** — Claude Code kiểm bằng zip.

### Việc cho backend (phần 2)
1. **Hộp duyệt** — `app/Modules/Admin/resources/views/livewire/entries/review-inbox.blade.php` (đã build). Port:
   - Khung màn là container; ≥ 940px 2 khung, khung đọc sticky cao `100dvh − chiều cao topbar − 32px`, cuộn trong; bỏ `max-height:calc(100vh − 250px)`.
   - < 940px: một khung; bài mở toàn màn (`fixed inset:0`, safe-area), nút "Quay lại", chân nút quyết định dính đáy.
   - < 600px: hàng chờ dạng thẻ; `<x-bs-admin::modal>` trượt từ đáy.
   - Thước độ dài `<x-bs-admin::length-field readonly>` cho mô tả ngắn + tiêu đề/mô tả Google (mốc từ cấu hình SEO); `<x-bs-admin::ai-assist>` chỉ cho ô trống, ghi vào bài + nhật ký duyệt, hoàn tác được.
   - Tiêu đề trang là `<h1>`; "chờ 0 ngày" → "vừa gửi"; chữ trống không emoji; "Bỏ lọc".
2. **Khối Trạng thái duyệt** — `…/livewire/entries/editor.blade.php`: hộp thoại trả lại/gỡ xuống trượt từ đáy ở < 560px; bỏ focus tự viết của ô lý do/ô chọn.
3. Trình đơn chưa build (`routes/web.php:237` Placeholder) — không có việc port.

### Verifier
Gửi verifier sau phần 2 (Hộp duyệt). Trình đơn ≈190 KB, Shell ≈340 KB: nếu công cụ chụp quá giờ thì **[CHƯA KIỂM ĐƯỢC]**, Claude Code kiểm bằng zip.


## D0-6b (06/10/2026) — Panel Cài đặt nhúng · Trình chọn ảnh

### Tổng quan
Sửa tại chỗ cách co giãn của 4 panel Cài đặt và trình chọn ảnh; nội dung, dữ liệu mẫu, tương tác giữ nguyên. Thêm phía panel của hợp đồng mẫu 4: `onDirty(n)` + `onInvalid(n)` + nghe `bs-embed-cmd {act:'firstError'}`. Đóng các dòng TODO "· D0-6b", L1-C-P01 (phía panel) và L1-A-P01 (4 panel).

### File
- `Email Settings.dc.html` (cả 3 cách nhúng: Email website · Email hệ thống `only-tab="send"` · Mẫu email hệ thống).
- `Đăng nhập nhanh & Liên kết tài khoản.dc.html` (mặt website/cửa hàng, SaaS, **nền tảng mới**, hồ sơ cá nhân).
- `Integrations Panel.dc.html` · `Floating Contact Panel.dc.html`.
- `MediaPicker.dc.html` (thêm prop `startTab`) · `Image Picker.dc.html` (canvas: khung 1020 · 768 · 390 Tải lên · 390 Thư viện).
- Đo: `_tmp/m6b.html` (`?set=lay` · `shell` · `sl` · `err`).

### Mức hoàn thiện
Co giãn + hợp đồng nhúng: hoàn thiện. Thẻ kết nối dịch vụ, ô khóa bí mật: không dựng (D3-2).

### Màn & tương tác
- **Chung 4 panel**
  - Link `admin-components.css`; bỏ `.dsw` tự khai — công tắc dùng `.dsw[aria-checked]` chuẩn. Công tắc trước là `<span onClick>` (Nút liên hệ nổi) hoặc nút tô bằng hole style (Integrations) → nay `<button role="switch">` có `aria-label` nói rõ việc ("Hiện kênh Gọi điện · Đang bật"). Vùng bấm 44px bằng `.dsw::after` (cao thêm 10px trên/dưới), hình công tắc không đổi.
  - Lỗi: Email = "Khách trả lời về" + danh sách "Gửi thông báo tới"; Đăng nhập nhanh (SaaS/nền tảng) = Client ID / khóa bí mật sai dạng; Integrations = ID công cụ đang bật sai dạng; Nút liên hệ nổi = giá trị kênh sai dạng. Ô lỗi có `aria-invalid="true"` + `data-err`. Bấm Lưu khi còn lỗi → không lưu, toast "Còn N ô chưa đúng — sửa rồi bấm Lưu lại", nhảy tới lỗi đầu tiên (mở đúng tab/nhóm/khối cấu hình rồi mới focus).
  - Đo trong Shell (1240 và 390): sửa sai 1 ô → thanh Lưu "1 thay đổi chưa lưu · 1 lỗi · Xem lỗi đầu tiên" → bấm → focus đúng ô (`INPUT aria-invalid=true`) ở cả 4 panel.
  - Toast: vùng `role="status" aria-live="polite"` ẩn đọc chữ + hộp hiển thị `aria-hidden`; khi nhúng, toast nâng lên `88px + safe-area` để không che thanh Lưu của Shell. Thanh Lưu của Shell là `position:sticky` trong luồng, nên cuộn hết thì ô cuối nằm trên thanh [SUY RA TỪ CODE].
  - Hộp thoại (Email: kết nối hộp thư, đưa mẫu về gốc, chèn liên kết, chặn rời trang; Đăng nhập nhanh: tắt/ngắt kết nối/ngắt liên kết; Integrations: đặt lại): < 600px trượt từ đáy, bo trên 12px, `max-height:92dvh`, chân chừa safe-area, 2 nút chia đôi cao 44px. Đăng nhập nhanh + Integrations: nút **Hủy** được focus khi hộp mở.
  - `min-height:100vh` → `100vh` dự phòng + `100dvh`; khi nhúng = 0. Bỏ mọi `outline:none` trên ô nhập.
  - Khung xem 390 · 768 (ghi chú thiết kế) ở cuối mỗi panel đứng riêng, tải lười; tắt bằng Tweak `showFrames` (mục Ghi chú thiết kế); không hiện khi nhúng hoặc trong chính khung xem (`#frame`).
- **Email**: dải tab dưới 600px khung chứa đổi nhãn ngắn ("Chữ ký" thay "Chữ ký & thương hiệu", tên đủ ở `title`), dưới 400px bỏ icon tab — hết cắt chữ ở 360/390. Thẻ trạng thái kết nối xuống dòng < 560px, nút "Gửi thử" rộng hết. Ô soạn (tiêu đề, nội dung thư, chữ ký) bỏ luật focus riêng `.em-ce`, khung bao từng ô có `data-focus-wrap`. Ô tìm biến, ô "Gửi thông báo tới" cũng `data-focus-wrap`. Nút chép biến: `aria-label`/`title` "Sao chép {biến}" → "Đã sao chép {biến}" trong 1,6 giây. Lưới 2 cột → 1 cột dưới 900px khung chứa (đã có, giữ).
- **Đăng nhập nhanh**: dưới 600px khung chứa mỗi nhà cung cấp là thẻ gọn `grid-template-areas: "ic nl sw" "ic mt mt" "ac ac ac"` — (1) icon + tên + trạng thái · công tắc bên phải (2) dòng phụ (3) "Cấu hình/Sửa cấu hình" rộng hết. Hai ô khóa 1 cột dưới 768px (trước 640). Khung ô khóa bí mật `style-focus-within` → `data-focus-wrap`. **Mặt nền tảng** (`accountType='platform'`, Cài đặt nền tảng › Đăng nhập nhanh): breadcrumb "Cài đặt nền tảng", banner vàng "Bắt buộc cấu hình…", mỗi nhà cung cấp có chip loại tài khoản áp dụng (Website · Cửa hàng · SaaS); hành vi cấu hình như SaaS. Shell hiện mount `p-social` với `account-type="saas" embed-id="social-saas"` → panel tự hiểu là nền tảng (TODO đổi thuộc tính ở Shell). Chip ngôn ngữ: không áp dụng (panel không có trường đa ngữ).
- **Integrations**: trạng thái lưu `.sv` (`role="status"`) ở đầu cột: "Đã lưu lúc 10:42" / "Thay đổi chưa lưu" / "Đang lưu…" — có cả khi nhúng. Tiêu đề nhóm gập dùng được bằng bàn phím (Enter/Space). Dưới 600px: lề 12px, ô ID rộng hết, nút 44px, chữ ô 16px. Cột tóm tắt xuống dưới khi khung < 820px (đã có). Khối giới thiệu bỏ nền gradient → `--color-admin-primary`. Bỏ emoji "🎉".
- **Nút liên hệ nổi**: dưới 600px ô giá trị kênh và ô nhãn xếp dọc rộng hết; nút lên/xuống nằm ngang, 44px; lưới 9 biểu tượng tự chia ô 44px; tay cầm kéo ẩn khi cảm ứng (đổi thứ tự bằng nút). ≤ 900px khung xem trước hết dính. Ô tìm ngôn ngữ `data-focus-wrap`. Xóa mã chết `copyLabel` (+ `copyBtn`, `copyIcon`, `onCopy`).
- **MediaPicker** (theo khung chứa của chính picker, chạy cả trong hộp thoại):
  - < 600px: hai nút lớn "Chụp ảnh" (mở nguồn Máy ảnh) và "Ảnh trong máy" (mở nguồn Thiết bị) ở đầu; chữ "Kéo & thả" ẩn; **11 nguồn** thành dải cuộn ngang trong khung (thẻ 96px, bắt điểm cuộn) — đo 390: dải 852px cuộn trong 322px, trang không cuộn ngang.
  - Thư viện: cột thư mục thành dải cuộn ngang ở trên; lưới ảnh **2 cột** (đo); dạng danh sách ẩn cột Kích thước + Loại; ô tìm 100%, 3 ô chọn 2 cột cao 44px chữ 16px.
  - Xem ảnh: khung Chi tiết phủ toàn picker (ảnh cao `min(56dvh,420px)`), nút đóng 44px; nút "Chi tiết" trên ô ảnh 44px.
  - Chân: ảnh đã chọn 1 dòng, "Hủy" + "Chèn" chia đôi cao 44px, chừa safe-area. Picker bỏ bo/viền khi khung hẹp. Bo 14px → 6px; `100vh` → `100dvh`.
- **Image Picker** (canvas): khung máy tính 1020 × 660 + máy tính bảng 768 × 900 + điện thoại 390 × 844 (Tải lên) + 390 × 844 (Thư viện).

### Ánh xạ dữ liệu
- Email: `app/Modules/Admin/resources/views/livewire/email-settings.blade.php` (đã build).
- Đăng nhập nhanh: `app/Modules/Admin/resources/views/livewire/social-settings.blade.php` (đã build).
- Trình chọn ảnh: `app/Modules/Admin/Livewire/Concerns/WithMediaLibraryPicker.php` (đã build).
- Tích hợp & Theo dõi, Nút liên hệ nổi: chưa build.

### Quyền & gói
Không đổi. Mặt nền tảng của Đăng nhập nhanh: `super_admin` (như mục `p-social` của Shell).

### Token
- `#2050a0` nền dưới chữ trắng → `var(--color-admin-primary,#2050a0)`; chữ/viền/chấm chọn → `var(--color-accent,#2050a0)`; `rgba(32,80,160,a)` và hex 8 chữ số `#2050a0XX` → `color-mix(in srgb,var(--color-accent,#2050a0) a%,transparent)`. Integrations/Nút liên hệ nổi: `background:var(--brand)` dưới chữ trắng → `--color-admin-primary`; `--brand-fg` → `--color-accent`; `--brand` chỉ còn ở tint trang trí.
- Chữ phụ `#878a99` / `#5b6270` / `#9aa0ab` / `--color-body-faint` dùng làm chữ → `var(--color-body-muted)`; faint chỉ còn ở viền/chấm trang trí.
- Viền ô `#e9ebec` / `#ced4da` / `#b9c0cf` → `--color-border-input`; ô lỗi `#f06548` → `--color-admin-danger-text`.
- Màu ngữ nghĩa: chip/chấm → token màu gốc; chữ → `-text`; nền dưới chữ trắng → `-solid` (nút "Nhờ cài đặt" Integrations `#0ab39c` 2,6:1 → `--color-admin-success-solid`; nút "Đặt lại" → `--color-admin-danger-solid`).
- **Ngoại lệ giữ hex (dữ liệu, không phải màu giao diện quản trị)**: Email `siteBrand`, `brandColor` mặc định và bảng `BRANDS` (màu thương hiệu in vào thư HTML gửi khách — thư không đọc được biến CSS); Nút liên hệ nổi bảng `pool` (màu nút trên website khách). Grep (4) vì vậy còn 3 dòng ở Email + 1 dòng ở Nút liên hệ nổi, đều là dữ liệu. Màu nhận diện Google/Facebook/Zalo giữ nguyên. Toast nền `#212529` là nền đảo, giữ (như D0-6a).

### Icon
Thêm: `ri-camera-line`, `ri-image-line` (MediaPicker), `ri-global-line`, `ri-store-2-line`, `ri-apps-2-line`, `ri-error-warning-line` (mặt nền tảng), `ri-loader-4-line` (trạng thái lưu), `ri-refresh-line` (nút Đặt lại).

### Chuỗi cần dịch
"Chữ ký" (tab ngắn) · "Còn {n} ô chưa đúng — sửa rồi bấm Lưu lại" · "Còn {n} ô chưa đúng định dạng — sửa rồi bấm Lưu lại" · "Còn {n} kênh chưa đúng định dạng — sửa rồi bấm Lưu lại" · "Đã sao chép {biến}" · "Thêm địa chỉ nhận thông báo" · "Bắt buộc cấu hình. Đây là kết nối gốc của nền tảng — website và cửa hàng của khách chỉ thấy nút Google/Facebook khi mục này đã kết nối. Mỗi dòng ghi rõ loại tài khoản được áp dụng." · "Cài đặt nền tảng" · "Kết nối Google và Facebook một lần cho mọi website, cửa hàng và SaaS của khách, và chọn cách xử lý khi email đăng nhập trùng tài khoản đã có." · "Website · Cửa hàng · SaaS" · "Hiện kênh {tên} · Đang bật/Đã tắt" · "Hiện nút liên hệ nổi trên website · Đang bật/Đã tắt" · "Nhãn khi di chuột của {tên}" · "Đã lưu lúc {giờ}" · "Đặt lại về mẫu ban đầu" · "Không mở nguồn ngoài nào" · "Chụp ảnh" · "Ảnh trong máy" · "Hoặc lấy từ nguồn khác — vuốt ngang để xem hết" · "Thư mục" · "Tìm ảnh theo tên" · "Khung xem 390px · 768px (ghi chú thiết kế)".

### Kết quả 12 tiêu chí (4 panel + trình chọn ảnh)
1 đạt (tiêu đề/mô tả do Shell; Lưu là nút chính duy nhất) · 2 đạt (Cài đặt › mục › sửa › Lưu) · 3 chưa hết: Integrations còn "CSP", "Server-side", "Consent Mode v2", "webhook", "CNAME" ngoài khối Nâng cao; Đăng nhập nhanh còn "Client ID/Secret" (tên Google bắt buộc) — thuộc D3-2 · 4 đạt · 5 đạt (lỗi nói định dạng đúng kèm ví dụ) · 6 đạt phần chặn rời trang + chặn Lưu khi lỗi; hành vi xóa chưa rà (TODO) · 7 đạt · 8 đạt (tab Email hết cắt; nút 1 dòng; dải nguồn ảnh cuộn trong khung là cuộn có chủ đích của danh sách nguồn) · 9 đạt ở 360/390/768 · 10 đạt · 11 đạt (đo Chữ lớn) · 12 đạt.

### Kiểm hiển thị (D0-6b)
Đo bằng `_tmp/m6b.html` (iframe theo bề rộng, so `scrollWidth` với `clientWidth` của trang, đếm phần tử vượt `clientWidth` không bị khung cuộn cắt). Chữ lớn = `--fsp:2px` + `zoom:16/14` như Shell.

| Màn | 360 | 390 | 768 | 1024 | 1440 | Chữ lớn |
|---|---|---|---|---|---|---|
| Email — đứng riêng | đạt · tab ngắn | đạt | đạt · 1 cột | đạt · 2 cột | đạt | đạt |
| Email — trong Shell (`#settings/email`, `#p-settings/p-mail`, `#p-settings/p-mail-tpl`) | đạt | đạt | đạt | đạt | đạt | đạt |
| Đăng nhập nhanh — đứng riêng | đạt · thẻ 3 dòng (lần đo đầu tràn 11px → sửa) | đạt | đạt · ô 1 cột | đạt | đạt | đạt |
| Đăng nhập nhanh — trong Shell (`#settings/social`, `#p-settings/p-social`) | đạt | đạt | đạt | đạt | đạt | đạt |
| Integrations — đứng riêng / trong Shell | đạt | đạt | đạt · tóm tắt xuống dưới | đạt | đạt | đạt |
| Nút liên hệ nổi — đứng riêng / trong Shell | đạt | đạt | đạt · 1 cột | đạt | đạt | đạt |
| MediaPicker | đạt · 2 cột, dải nguồn cuộn trong khung | đạt · dải 852 > 322 | đạt | đạt | đạt | đạt |
| Image Picker (canvas) | canvas cố định 1020 + 768 + 390 + 390px, cuộn ngang có chủ đích | — | — | — | — | — |

Tổng: 60 lượt đứng riêng + 70 lượt trong Shell (7 địa chỉ × 5 bề rộng × chữ thường/Chữ lớn) → `scrollWidth = clientWidth`, 0 phần tử tràn, 0 lỗi JS (trừ canvas Image Picker). Lỗi → "Xem lỗi đầu tiên": 5/5 đạt (4 panel ở 1240 + Email ở 390).
Trình duyệt: Chrome (môi trường thiết kế) — đạt. Safari iPhone/iPad, Firefox, Samsung Internet, chế độ tối, phóng to 200%, cảm ứng thật, bàn phím ảo, safe-area thật: **[CHƯA KIỂM ĐƯỢC]** — môi trường không giả lập được; Claude Code kiểm bằng zip.
CSS mới: `@container` (Safari 16+), `:has` chỉ qua luật chung `data-focus-wrap` của `admin-base.css`, `dvh` có `vh` dự phòng (trừ `max-height` cột tóm tắt Integrations viết trong chuỗi style — Safari 15.4+).

### Việc cho backend (D0-6b)
1. **Email** — `…/livewire/email-settings.blade.php` (đã build). Port:
   - Khung màn là container; tab 2 nhãn (dài/ngắn): `@container (max-width:600px)` hiện nhãn ngắn, `(max-width:400px)` ẩn icon; nhãn đủ ở `title`.
   - Thẻ trạng thái kết nối `flex-wrap` < 560px, nút "Gửi thử" `width:100%`.
   - Ô soạn `contenteditable` (tiêu đề, thân thư, chữ ký): bỏ CSS focus riêng, bọc khung `data-focus-wrap`.
   - Validation server cho "Khách trả lời về" + "Gửi thông báo tới" trả lỗi theo ô; đẩy số lỗi lên khung (`onInvalid`), nhận "Xem lỗi đầu tiên" → focus ô.
   - Hộp thoại `<x-bs-admin::modal>` trượt từ đáy < 600px; toast `role="status"`; nút chép biến đổi `aria-label` sau khi chép.
2. **Đăng nhập nhanh** — `…/livewire/social-settings.blade.php` (đã build). Port:
   - Danh sách nhà cung cấp thành thẻ gọn < 600px (grid areas như trên; `<x-bs-admin::switch>` cạnh tên).
   - **Mặt nền tảng**: cùng component nhận `accountType='platform'` (không fork): banner bắt buộc cấu hình, chip loại tài khoản (`site`/`ecommerce`/`saas`) từng dòng, breadcrumb "Cài đặt nền tảng". Dữ liệu: một bộ khóa Google/Facebook cấp nền tảng cho tên miền gốc + loại tài khoản được áp dụng — **Chờ backend** nếu repo chưa có cột loại tài khoản.
   - Validation Client ID / khóa theo định dạng nhà cung cấp, lỗi theo ô + đếm lỗi lên khung.
3. **Trình chọn ảnh** — `app/Modules/Admin/Livewire/Concerns/WithMediaLibraryPicker.php` + view picker. Port:
   - Picker là container; < 600px: "Chụp ảnh" = `<input type="file" accept="image/*" capture="environment">`, "Ảnh trong máy" = `<input type="file" accept="image/*" multiple>`; nguồn khác dải cuộn ngang; thư mục dải cuộn; lưới 2 cột; chi tiết ảnh phủ toàn picker; chân 2 nút chia đôi + safe-area.
   - Hộp thoại chứa picker trong khung quản trị: < 600px toàn màn `100dvh` (Shell prototype chưa sửa — TODO).
4. Tích hợp & Theo dõi, Nút liên hệ nổi: chưa build — khi build theo prototype (công tắc `role="switch"`, `.sv` trạng thái lưu, đếm lỗi theo ô).

### Verifier
Gửi verifier sau D0-6b. Email ≈200 KB: nếu công cụ chụp quá giờ thì **[CHƯA KIỂM ĐƯỢC]**, Claude Code kiểm bằng zip.

Lượt kế tiếp: **D1-1** (D0-6c chạy lúc nào cũng được).

## D0-6c phần 1 (06/10/2026) — bộ AI: AI Writer Panel v2 · Trợ lý viết v2 · Nâng cấp nội dung · Language Context

### Tổng quan
Sửa tại chỗ cách co giãn của 4 màn đầu; nội dung, dữ liệu mẫu, tương tác giữ nguyên. 8 màn còn lại + quét nhãn AI cuối lượt: **D0-6c phần 2** (TODO.md).

### File
- `AI Writer Panel v2.dc.html` — link `admin-components.css` + `content-seo.js`; khung gốc `data-cs="sup"` để dùng `.lc` · `.ai` · `.cnt` · `.trk` · `.jb` · `.grab` chuẩn; co theo khung `.aw-wrap`; length-field, ai-assist, ai-job; prop mới `langCode` · `langDir` · `upsell` · `simulateError`; token.
- `Trợ lý viết nội dung AI v2 - Options.dc.html` — artboard co giãn (rộng 100%, tối đa 1180px), lưới 2 cột → 1 cột dưới 820px khung, trợ lý trượt từ đáy dưới 600px, nhãn nút AI theo C.15, chip `.lc`, thước độ dài, khung xem 390 · 768 (`#frame`, Tweak `showFrames`), Tweak `upsell` truyền xuống panel.
- `Nâng cấp nội dung.dc.html` — link `styles.css` ngay trước link Google URL CHUẨN (L1-C-09), "Đi tới ô đó" 44px (X3B2), hộp trượt từ đáy dưới 600px, prop `upsell`, token.
- `Language Context - Design Spec.dc.html` (tham chiếu gốc — chỉ sửa đúng việc được nêu): thanh ngôn ngữ đo bề rộng thật, ai-job, `role="status"`, nhãn nút "Dịch", các chỗ bố cục trang tràn ở 360.
- Đo: `_tmp/m6c.html?f=<chỉ số tệp>&w=<bề rộng>` · đổi màu: `_tmp/tok.js` (bảng hex → token theo vai trò nền / viền / chữ).

### Màn & tương tác
- **AI Writer Panel v2** (theo khung chứa `.aw-wrap`, chạy trong hộp thoại của mọi màn chủ)
  - Dưới 600px: nút đầu panel (thu nhỏ, phóng to, đóng) 44px; chữ ô nhập 16px.
  - Dưới 560px: đầu panel 2 hàng — (1) icon + tên + nút; (2) "Yêu cầu chất lượng" + chip "Còn N từ AI" (không cắt). Tay nắm `.grab`. Chân: nút chính rộng hết, cao 44px, chừa safe-area; ẩn dòng phím tắt. 3 hộp thoại con (Yêu cầu chất lượng · Đóng khi chưa chèn · Trùng nội dung) trượt từ đáy, bo trên 12px, cao tối đa 92%.
  - Thước độ dài (`<x-bs-admin::length-field>`): Mô tả ngắn, Tiêu đề SEO, Mô tả SEO ở bước Bản nháp — mốc từ `BSSEO.meter`, chữ + icon + dải màu, `maxlength` = `BSSEO.hardCap`. Nhãn có chip mã ngôn ngữ `.lc` (prop `langCode`); ô theo `langDir` (RTL), đường dẫn luôn `dir="ltr"`.
  - ai-assist: 4 nút làm mới phương án (Tiêu đề, Mô tả ngắn, Nội dung, SEO) trước chỉ là icon → nút "Viết lại" có chữ; tooltip nói trước số từ AI và số còn lại ("lần đầu nửa giá").
  - ai-job khi viết nội dung: "Đang viết… 4/12 đoạn" + thanh tiến độ + "Hủy phần còn lại" · "Xong — đã viết N đoạn" · "Lỗi ở đoạn N — đã hoàn X từ AI" + "Thử lại" (viết tiếp phần còn lại) · "Đã hủy — không trừ từ AI nào cho N đoạn chưa viết". Xem lỗi bằng Tweak "Mô phỏng lỗi khi viết". Nút "Dừng" cũ ở đầu thẻ gộp vào dòng này.
  - Hủy giữa chừng: trước giữ tối thiểu 20% số từ đã giữ tạm → nay chỉ tính phần đã viết, hoàn đủ phần chưa chạy (§7a).
  - Câu "mua thêm": Tweak "Lời mời nâng gói" (`upsell`, mặc định TẮT) — tắt: "Cần thêm? Liên hệ hỗ trợ"; bật: "mua thêm hoặc nâng gói ở Cài đặt › Gói & mức dùng".
  - Bỏ `outline:none`, `style-focus` và `.aw-in:focus` tự viết — dùng luật Focus 1b chung.
- **Trợ lý viết v2 (canvas)**: dưới 820px khung: 1 cột (SEO, Thuộc tính xuống dưới). Dưới 600px: đầu trang 2 hàng, 3 nút chia đều cao 44px; trợ lý là ngăn trượt từ đáy (rộng hết, cao 92%); thanh thu nhỏ trải ngang, chừa safe-area; toast `role="status"`. Nút AI theo C.15: đầu trang "Viết mới" (bài trống) / "Nâng cấp bài viết" (có nội dung); cạnh ô: "Gợi ý tiêu đề" · "Gợi ý mô tả ngắn" · "Gợi ý tiêu đề & mô tả" (ô trống) / "Viết lại" (ô có chữ). Placeholder chỉ còn ví dụ; "Tối đa 160 ký tự" thành thước độ dài, "Để trống thì dùng tiêu đề bài viết." thành dòng gợi ý.
- **Nâng cấp nội dung**: dưới 600px cửa sổ: ngăn trượt từ đáy (`max-height:92dvh`, tay nắm, bo trên 12px), đầu hộp 2 hàng (chip hạn ngạch xuống hàng 2, không cắt), chân 2 nút chia đôi 44px + safe-area, nút đóng 44px, "Chọn việc nên làm"/"Bỏ chọn tất cả" 44px. Tiêu đề hộp khi trống: "Viết mới bài này/trang này" (bỏ "… bằng AI").
- **Language Context**: biến thể thanh (dải pill / "+N" / ô chọn) trước tính theo thanh trượt demo (900px) nên ở 390 vẫn vẽ dải pill → tràn 54px. Nay bề rộng hiệu lực = min(thanh trượt, bề rộng khung đo bằng ResizeObserver + `resize`) → 360/390 = ô chọn + panel, 768 = dải pill vừa (280/280). ai-job thay chip "Đang dịch bằng AI…": "Đang dịch… 4/6" + "Hủy phần còn lại" · "Lỗi — đã hoàn N từ AI" + "Thử lại" · "Đã hủy — không trừ từ AI nào cho N ô chưa chạy" · "Xong"; vùng `role="status"` đọc dòng này. Nhãn "Dịch bằng AI" → "Dịch". Bố cục trang: `minmax(min(Npx,100%),1fr)`, cột chú thích co được, bảng 2 cột `minmax(96px,…) minmax(0,1fr)` + `overflow-wrap:anywhere`, bảng so sánh theo trường cuộn trong khung (`min-width:560px`).

### Ánh xạ dữ liệu
Bộ AI chưa build (Laravel AI SDK chưa cài) — không có file repo. Hạn ngạch đọc `BSAI` (ai-quota.js), mốc độ dài `BSSEO.LIMITS` (content-seo.js).

### Quyền & gói
Không đổi. `upsell` theo công tắc "Lời mời nâng gói" của Nền tảng › Gói & tính năng.

### Token
Theo bảng `_tmp/tok.js`: `#2050a0` nền → `--color-admin-primary`, chữ/viền → `--color-accent`; `rgba(32,80,160,a)` → `color-mix(in srgb,var(--color-accent) a%,transparent)` (tương tự success/info/warning/danger); chữ `#878a99`/`#adb5bd`/`#c3c8d0`/`#b9bec7` → `--color-body-muted`; viền ô `.aw-in`/`.tw-in` → `--color-border-input`; placeholder → `--color-placeholder`. AI Writer 184 chỗ · Trợ lý 20 · Nâng cấp 26. Giữ hex có chủ đích: `#fff` chữ trên nền đặc, toast nền đảo `#1f2b4d`, màu khung xem trước Google (`#1a0dab` · `#4d5156` · `#0d7a3e`), `#e6c98a` (trang trí). Language Context: chỉ đổi token ở khối vừa sửa (tham chiếu gốc).

### Icon
Thêm: `ri-sparkling-2-line` (nút AI thay `ri-quill-pen-line`/`ri-refresh-line`), `ri-check-double-line`, `ri-error-warning-line`, `ri-close-circle-line`, `ri-close-line`, `ri-refresh-line` (ai-job).

### Chuỗi cần dịch
"Viết mới" · "Nâng cấp bài viết" · "Gợi ý tiêu đề" · "Gợi ý mô tả ngắn" · "Gợi ý tiêu đề & mô tả" · "Gợi ý tiêu đề & mô tả SEO" · "Viết lại" · "Viết lại — dùng khoảng {n} từ AI (lần đầu nửa giá), còn {left} sau khi chạy" · "Đang viết… {at}/{total} đoạn" · "Xong — đã viết {n} đoạn" · "Lỗi ở đoạn {n} — đã hoàn {w} từ AI" · "Đã hủy — không trừ từ AI nào cho {n} đoạn chưa viết" · "Đang dịch… {d}/{t}" · "Đã hủy — không trừ từ AI nào cho {n} ô chưa chạy" · "Hủy phần còn lại" · "Thử lại" · "Đóng" · "Cần thêm? Liên hệ hỗ trợ" · "Viết mới bài này" / "Viết mới trang này" · "Để trống thì dùng tiêu đề bài viết." · "Khung xem 390px · 768px (ghi chú thiết kế)".

### Kết quả 12 tiêu chí (4 màn)
1 đạt · 2 đạt (Sửa bài → "Viết mới" → 5 bước; trường đơn: 1 bấm) · 3 chưa hết: AI Writer còn "IndexNow", "llms.txt", "GPTBot…" trong gợi ý sau khi đăng (khối cho người thạo — chuyển D1-4d) · 4 đạt (nút làm mới trước chỉ icon → có chữ) · 5 đạt (placeholder là ví dụ) · 6 đạt (hủy hoàn đủ; đóng khi chưa chèn có hỏi) · 7 đạt · 8 đạt · 9 đạt ở 360/390/768 · 10 đạt (⌘/Ctrl + Enter giữ cho chuột) · 11 đạt (đo Chữ lớn) · 12 đạt (C.15; grep 4 tệp: 0 nhãn ở cột "Không dùng").

### Kiểm hiển thị (D0-6c phần 1)
Đo bằng `_tmp/m6c.html` (cách đo như `_audit/harness.html`). Chữ lớn = `--fsp:2px` + `zoom:16/14`.

| Màn | 360 | 390 | 768 | 1024 | 1440 | Chữ lớn |
|---|---|---|---|---|---|---|
| AI Writer Panel v2 (đứng riêng) | đạt | đạt | đạt | đạt | đạt | đạt |
| Trợ lý viết v2 (canvas) | đạt · trước tràn 875px (artboard 1220px cố định) | đạt · trước tràn | đạt · 1 cột | đạt · 2 cột | đạt | đạt |
| Nâng cấp nội dung | đạt | đạt | đạt | đạt | đạt | đạt |
| Language Context | đạt · trước tràn 20–72px → ô chọn | đạt · ô chọn | đạt · dải pill 280/280 | đạt | đạt | đạt |

40 lượt → `scrollWidth = clientWidth`, 0 phần tử tràn (Language Context đo lại 4 lượt 360/390 sau lần sửa cuối). Bảng so sánh theo trường (Language Context mục 9) cuộn trong khung — ngoại lệ bảng dữ liệu.
Trong Shell: 4 màn chưa có trong registry `shell-screens.js` (bộ AI ngoài core v1) → **[CHỜ GỘP]**, Claude Code đo bằng zip. Panel AI trong Shell chỉ mở qua `Quản lý Bài viết` / `Page Editor` (hộp popup, chưa trượt đáy — TODO D1-3 / D1-4d).
Trình duyệt khác, chế độ tối, 200%, cảm ứng thật, bàn phím ảo: **[CHƯA KIỂM ĐƯỢC]** — Claude Code kiểm bằng zip.

### Việc cho backend (D0-6c phần 1)
Không có màn đã build. Khi build bộ AI:
1. Panel trợ lý viết = `<x-bs-admin::ai-writer>` trong `<x-bs-admin::modal sheet>`: dưới 600px trượt từ đáy (cao 92%, safe-area); đầu panel 2 hàng dưới 560px; nút chính rộng hết.
2. Viết nội dung theo đoạn: mỗi lần chạy giữ tạm số từ mục tiêu (ledger `words`, `source` gói/addon); hủy = bút toán đảo phần chưa sinh; lỗi = đảo phần chưa sinh + "Thử lại" viết tiếp từ đoạn hỏng (idempotency key theo lần chạy + đoạn). Bỏ mức giữ tối thiểu 20%.
3. Thước độ dài + `maxlength` đọc cấu hình SEO (LIMITS); ô theo ngôn ngữ RTL tự `dir="rtl"`.
4. Language Context: biến thể thanh tính theo bề rộng khung thật (Alpine + ResizeObserver), không theo cấu hình; dịch theo từng trường, mỗi trường một giao dịch.

### Verifier
Gửi verifier sau phần 1. AI Writer ≈ 200 KB: nếu công cụ chụp quá giờ thì **[CHƯA KIỂM ĐƯỢC]**, Claude Code kiểm bằng zip.

## D0-6c phần 2 (06/10/2026) — 8 màn còn lại của bộ AI

### File
`Cài đặt nội dung AI` · `Vận hành nội dung AI` · `Hạn ngạch AI và mua thêm` · `Hàng đợi ảnh` · `Kế hoạch nội dung SEO` · `Cài đặt xuất bản tự động` · `Duyệt & xuất bản nội dung` · `Block Library - Options`. Công cụ: `_tmp/fix6c.js` (phần co giãn chung) · `_tmp/job6c.js` (dòng việc AI) · `_tmp/tok.js`.

### Chung 8 màn
- Link `admin-components.css`; khung gốc `.s6-root` là container (`container-type:inline-size`) + `data-cs="sup"`; mọi `@media (max-width)` cũ → `@container` theo khung gốc (chạy đúng khi nhúng).
- Dưới 600px: lề khung 12px; nút trong khung ≥ 44px; chữ ô nhập 16px (cả `pointer:coarse`).
- Hộp thoại (`position:fixed`) dưới 600px trượt từ đáy: rộng hết, `max-height:92dvh`, bo trên 12px, chừa safe-area, `role="dialog" aria-modal`. Toast `role="status"` + chừa safe-area.
- Công tắc tự khai (`.dsw.on`/`.dsk`, `.px-sw`/`.px-sk`) → `.dsw[aria-checked]` chuẩn. Bỏ `outline:none`, luật `:focus` tự viết, `style-focus`. Placeholder → `--color-placeholder`; viền ô → `--color-border-input`.
- Khung xem 390 · 768 cuối mỗi màn (Tweak `showFrames`, ẩn trong `#frame`).
- Màu: 73 · 27 · 37 · 42 · 86 · 23 · 31 · 56 chỗ đổi sang token (giữ `#fff` chữ trên nền đặc, toast nền đảo).

### Riêng từng màn
- **Cài đặt nội dung AI**: ma trận "Mặc định theo loại bài" dưới 768px là thẻ gọn — dòng 1 tên loại + nút Sửa 44px · dòng 2 người đọc · giọng · độ dài nối "·" · dòng 3 khối bật sẵn (trước: mỗi cột một dòng có nhãn). Nút "Dịch sang N ngôn ngữ" chạy dòng việc AI dưới dòng trạng thái thanh ngôn ngữ. "Nâng gói AI" theo Tweak "Lời mời nâng gói".
- **Vận hành nội dung AI**: "Đưa N mục vào hàng đợi" → mở thẻ Hàng đợi + dòng việc AI "Đang viết… x/N mục". Nhãn "Bổ sung bằng AI" → "Nâng cấp bài viết", "Làm mới bằng AI" → "Viết lại", "Làm tất cả bằng AI" → "Nâng cấp tất cả". "Mua thêm từ AI" theo Tweak.
- **Hạn ngạch AI và mua thêm**: bảng gói và bảng mức dùng dưới 900px: tên + giá/số trên 1 dòng, cột mô tả xuống dòng 2 (trước bị ẩn — mất thông tin trên điện thoại); dưới 600px nút chọn gói rộng hết; chip "Gói hiện tại" 1 dòng, dài thì "…".
- **Hàng đợi ảnh**: "Tạo hết N ô" chạy dòng việc AI theo **lượt tạo ảnh** (đơn vị = phương án, §7a). "Vẽ" → "Tạo" (§7a); nhãn "Tạo N ô bằng AI" → "Tạo ảnh cho N ô". Nút hàng dưới 600px xuống dòng được (trước tràn 8px ở 360 Chữ lớn).
- **Kế hoạch nội dung SEO**: bảng từ khóa dưới 600px là thẻ gọn — dòng 1 từ khóa · dòng 2 lượt tìm · độ khó · ý định · cụm nối "·" (trước ẩn 2 cột) · dòng 3 hành động. "Đưa phần đầu vào hàng đợi" chạy dòng việc AI. "Mở rộng lại bằng AI" → "Mở rộng lại". Câu "mua thêm" theo Tweak.
- **Cài đặt xuất bản tự động**: lỗi độ ưu tiên — quy tắc 520px đứng trước quy tắc 1000px nên bị đè (bảng quyền 4 cột 56px tràn ở 360/390 Chữ lớn). Đã chuyển quy tắc hẹp xuống sau; bảng quyền dưới 520px: tên co được + 4 ô công tắc 48px; hàng có công tắc cho chữ xuống dòng.
- **Duyệt & xuất bản nội dung**: chỉ phần chung.
- **Block Library**: bảng khối (rộng tối thiểu 920px, cuộn ngang) dưới 1024px khung → lưới thẻ 2 cột, dưới 600px 1 cột: dòng 1 icon + tên · trạng thái · dòng 2 mã tham chiếu + "Sao chép" 44px · dòng 3 vùng dùng · ngôn ngữ · số nơi dùng · dòng 4 "Sửa" (có chữ) + Nhân bản + Xóa 44px. Nút chỉ hiện khi rê chuột → luôn hiện trên cảm ứng, khi focus và ở dạng thẻ. "Sao chép mã tham chiếu" chép thật bằng `BSUI.copy` (mã công khai `key`, không phải id); không chép được thì toast chỉ cách bôi đen. Màn không có khung xem trước khối nên không có việc "xem trước toàn màn".

### Dòng việc AI (mẫu `#h-jb`, 4 màn)
"<việc>… x/N" + thanh tiến độ + "Hủy phần còn lại" · "Xong — N <đơn vị>" · "Lỗi — đã hoàn X từ AI / lượt tạo ảnh" + "Thử lại" (chạy tiếp phần còn lại) · "Đã hủy — không trừ từ AI nào cho N ô chưa chạy". Vùng `role="status"` đọc dòng này. Lỗi xem bằng Tweak "Mô phỏng lỗi khi chạy AI".

### Kiểm hiển thị (D0-6c phần 2)
| Màn | 360 | 390 | 768 | 1024 | 1440 | Chữ lớn |
|---|---|---|---|---|---|---|
| Cài đặt nội dung AI | đạt · ma trận thẻ | đạt | đạt | đạt | đạt | đạt |
| Vận hành nội dung AI | đạt | đạt | đạt | đạt | đạt | đạt |
| Hạn ngạch AI và mua thêm | đạt | đạt | đạt | đạt | đạt | đạt |
| Hàng đợi ảnh | đạt · trước tràn 8px (Chữ lớn) | đạt | đạt | đạt | đạt | đạt |
| Kế hoạch nội dung SEO | đạt · thẻ | đạt | đạt | đạt | đạt | đạt |
| Cài đặt xuất bản tự động | đạt · trước tràn 37px (Chữ lớn) | đạt · trước tràn 7px | đạt | đạt | đạt | đạt |
| Duyệt & xuất bản nội dung | đạt | đạt | đạt | đạt | đạt | đạt |
| Block Library | đạt · 1 cột thẻ | đạt | đạt · 2 cột thẻ | đạt · bảng | đạt | đạt |

Đo `_tmp/m6c.html`: 80 lượt + 30 lượt đo lại sau lần sửa cuối → `scrollWidth = clientWidth`, 0 phần tử tràn. Cả lượt D0-6c: 12 tệp × 5 bề rộng × chữ thường/Chữ lớn = 120 ô đạt.
Trong Shell: chưa có trong registry → **[CHỜ GỘP]**. Trình duyệt khác, tối, 200%, cảm ứng thật: **[CHƯA KIỂM ĐƯỢC]** — Claude Code kiểm bằng zip.

### Quét cuối lượt (35 tệp)
- Nhãn nút AI thuộc cột "Không dùng": còn ở 3 tệp ngoài phạm vi — `Quản lý Bài viết - Options` (D1-3), `Page Editor - Options` (D1-4d), `Admin Shell` ("Dịch bằng AI", lượt Shell kế). 12 tệp của lượt: 0.
- Chip ngôn ngữ tự vẽ mẫu cũ: 0. Đóng TODO "18 màn còn lại" và mục 6.

### Kết quả 12 tiêu chí (8 màn)
1 đạt · 2 đạt · 3 chưa hết: Cài đặt nội dung AI còn tên bot (GPTBot, ClaudeBot…) ở mục quyền đọc — tên riêng, có giải thích · 4 đạt (Block Library: Sửa có chữ ở dạng thẻ; Nhân bản/Xóa icon có tooltip + aria-label) · 5 đạt · 6 chưa hết: Block Library xóa vẫn hỏi (TODO, Chờ backend Thùng rác khối) · 7 đạt · 8 đạt · 9 đạt · 10 đạt · 11 đạt · 12 đạt.

### Việc cho backend (D0-6c phần 2)
Bộ AI chưa build. Khi build: khung màn là container, `@container` thay `@media`; bảng → thẻ gọn như trên (Block Library dùng `<x-bs-admin::table>` dạng thẻ dưới 1024px); `<x-bs-admin::modal>` trượt đáy dưới 600px; `<x-bs-admin::ai-job>` một giao dịch mỗi ô/phương án, hủy = đảo phần chưa chạy, lỗi = đảo + chạy tiếp; ảnh trừ sổ `images` theo phương án; `<x-bs-admin::switch>` thay công tắc tự vẽ; Block Library: chép mã `key` công khai.

### Verifier
Gửi verifier sau phần 2.
