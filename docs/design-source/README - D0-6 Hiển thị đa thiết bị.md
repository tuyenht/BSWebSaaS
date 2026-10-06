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

Lượt kế tiếp: **D0-6b**.
