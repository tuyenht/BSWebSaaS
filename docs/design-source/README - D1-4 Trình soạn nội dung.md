# README — D1-4 Trình soạn nội dung

D1-4a viết phần đầu. D1-4b, D1-4c, D1-4d viết tiếp vào cùng tệp.

## D1-4a · Rà 1-1 trình soạn chung (07/10/2026)

### Tổng quan
`Trình soạn nội dung.dc.html` là ô soạn thân bài dùng chung cho Trang (Page Editor) và Bài viết (Bản đồ màn hình gọi "Trình soạn chữ"). Lượt này sửa tại chỗ: rà đủ 20 nhóm nút, mặc định thanh gọn cho người mới, thanh cuộn ngang + ngăn trượt đáy trên điện thoại, popup "Viết lại" neo dưới đoạn bôi đen, Focus 1b, đưa thang chữ thân bài về một nơi, link styles.css. Không đổi dữ liệu mẫu, hộp Ảnh / Video / Liên kết / Ω giữ bố cục cũ.

### File
- `Trình soạn nội dung.dc.html` — sửa tại chỗ.
- `TODO.md` · `LESSONS.md` (6 dòng) · `SaaS Starter - Bản đồ màn hình.md` (dòng 12).
- Đo: `_audit/tmp/rte-test.js` (20 nhóm nút), `_audit/tmp/rte-lay.js` (tràn ngang × Chữ lớn, ngăn đáy 390). Ảnh kết quả: `_audit/tmp/rte-test-2.jpg`, `rte-lay.jpg`, `rte-lay2.jpg`.

### Mức hoàn thiện
Prototype tương tác đủ cho build. Đã chạy thật trên Chromium; Safari / Firefox / cảm ứng thật chưa chạy được (mục Kiểm hiển thị).

### 1. Bảng 20 nhóm nút
Cách đo: `_audit/tmp/rte-test.js` chạy trong trang trình soạn, bấm nút thật (mousedown + click), đọc DOM. 3 điều: (1) HTML hợp lệ — ảnh / video / bảng / đường kẻ là con trực tiếp của vùng soạn, không khối lồng trong `<b>`/`<p>`/`<li>`, không còn `#rte-mark`, không còn `<font>`; (2) con trỏ không nhảy — vị trí theo số ký tự trước/sau bằng nhau, hoặc chọn đúng đoạn cũ, hoặc nằm ở đoạn trống ngay sau khối vừa chèn; (3) Hoàn tác một lần trả đúng HTML trước thao tác. Bằng chứng: [ĐO BẰNG SCRIPT] lần chạy 2, sau sửa.

| # | Nhóm nút | HTML hợp lệ | Con trỏ | Hoàn tác 1 bước | Đã sửa gì |
|---|---|---|---|---|---|
| 1 | Đậm / Nghiêng / Gạch chân / Gạch ngang | đạt | đạt | đạt | — |
| 2 | Chỉ số trên / dưới | đạt | đạt | đạt | — |
| 3 | Kiểu (Đoạn văn, H2–H5, Trích dẫn) | đạt | đạt | đạt | Đặt lại con trỏ theo số ký tự sau `formatBlock` (Chrome dời về đầu khối) |
| 4 | Danh sách gạch đầu dòng / đánh số | đạt | đạt | đạt | **Lỗi lần 1:** bấm lần 2 không gỡ được — Chrome để con trỏ ra giữa `</ul>` và đoạn sau, lệnh gộp đoạn sau vào danh sách. Sửa: `cmd()` ghi vị trí chọn trước và đặt lại sau mọi lệnh không đổi chữ |
| 5 | Thụt lề vào / ra | đạt | đạt | đạt | — |
| 6 | Căn lề 4 kiểu | đạt | đạt | đạt | — |
| 7 | Liên kết | đạt | đạt | đạt | **Lỗi 2 chỗ:** ô địa chỉ không nối state (`linkUrl`/`onLinkUrl` không có trong renderVals → nút "Chèn liên kết" luôn mờ); về từ hộp thoại, `el.focus()` đặt con trỏ đầu bài và guard của `restoreRange()` không phục hồi → liên kết chèn ở đầu bài. Sửa: nối `linkVal`/`onLinkInput`, `forceRestore()`. Thêm: chưa bôi đen thì chèn địa chỉ thành chữ liên kết; con trỏ trong liên kết cũ thì điền sẵn và đổi địa chỉ; tự focus ô; Ctrl+K |
| 8 | Bỏ liên kết | đạt | đạt | đạt | **Lỗi:** con trỏ đứng trong liên kết (chưa bôi đen) thì `unlink` của Chrome không làm gì. Sửa: tự gỡ thẻ `<a>` quanh con trỏ, giữ vị trí |
| 9 | Xóa định dạng | đạt | đạt | đạt | — (bài kiểm lần 1 lỗi do chính script, đã sửa script) |
| 10 | Bảng | đạt | đạt | đạt | **Lỗi:** ảnh chụp lịch sử chứa `<span id="rte-mark">` → Hoàn tác trả HTML có thẻ thừa; con trỏ không đặt lại. Sửa: `push()` bỏ dấu chèn; `insertBlock()` đặt con trỏ vào đoạn trống sau khối |
| 11 | Đường kẻ ngang | đạt | đạt | đạt | Như nhóm 10 |
| 12 | Chèn ảnh (Thư viện, nhiều tệp + bảng thuộc tính) | đạt | đạt | đạt | Như nhóm 10. Đo cả 2 ảnh + 1 PDF chèn sau `<li><b>` → 2 `figure` là con trực tiếp |
| 13 | Chèn video (tùy chọn, bấm thẻ để sửa, Backspace / Delete để xóa) | đạt | đạt | đạt | Như nhóm 10 |
| 14 | Ký tự đặc biệt Ω | đạt | đạt | đạt | Dùng `forceRestore()` khi về từ hộp (dòng ghi "con trỏ nhảy 7→11" trong ảnh là giá trị cũ của bài kiểm 12 còn sót; vị trí thật 11 = ngay sau "©", đúng) |
| 15 | Màu chữ + màu nền | đạt | đạt | đạt | — |
| 16 | Cỡ chữ | đạt | đạt | đạt | **Lỗi:** thay `<font>` bằng `innerHTML` làm mất vùng chọn; chưa bôi đen thì Chrome giữ "cỡ 7" cho chữ gõ sau → `<font size=7>` sót lại. Sửa: chuyển nút con sang `<span>`, chọn lại đúng đoạn; chưa bôi đen thì báo "Hãy bôi đen chữ cần đổi cỡ trước." |
| 17 | Xem / sửa HTML | đạt | đạt (focus về vùng soạn) | đạt | **Lỗi:** quay lại từ HTML xóa sạch lịch sử (`hist = [h]`). Sửa: giữ lịch sử, thêm 1 mốc; bỏ `script/iframe/form/on*/javascript:` khi áp HTML |
| 18 | Toàn màn hình | đạt | đạt | không đổi nội dung | Chiều cao `100vh` → `100dvh`; Esc thoát; điện thoại phủ trọn + chừa safe-area |
| 19 | Hoàn tác / làm lại | đạt | đạt | đạt | **Lỗi:** gõ rồi Ctrl+Z trong 400ms đầu (chưa tới nhịp lưu) → "Không còn bước nào", chữ vừa gõ không mất. Sửa: Hoàn tác / Làm lại lưu ngay phần đang gõ trước; giữ con trỏ theo số ký tự |
| 20 | Thanh đường dẫn thẻ (body › ul › li) | đạt | đạt (chọn trọn `ul`) | không đổi nội dung | — |

Bẫy `restoreRange()`: giữ guard "range sống còn trong vùng soạn thì để yên" cho nút thanh công cụ; hộp thoại có ô nhập dùng `forceRestore()` riêng.

**Claude Code kiểm (không chạy được ở đây):** Hoàn tác / làm lại + 20 nhóm trên Safari macOS/iOS và Firefox (TODO "Còn chờ" mục 3) — chạy `_audit/tmp/rte-test.js` trong trang trình soạn (dán vào console). Chú ý Firefox: `execCommand('insertUnorderedList')` và `formatBlock` sinh `<br>` khác Chrome.

### 2. Người mới — thanh gọn
- Mặc định `toolbarFull = false`. Thanh gọn đúng 7 nhóm (`.rte-g`, `role="group"`): Kiểu chữ · Đậm/Nghiêng · Danh sách (gạch đầu dòng, đánh số) · Liên kết · Ảnh · Video · Hoàn tác (hoàn tác, làm lại). Ảnh, Video chuyển từ nhóm đầy đủ sang.
- "Thêm công cụ" (`aria-expanded`) mở 7 nhóm còn lại: gạch chân/gạch ngang/X²/X₂ · cỡ chữ + màu · căn lề · thụt lề · bỏ liên kết + xóa định dạng · bảng/kẻ ngang/Ω · HTML + toàn màn hình.
- Nhớ lựa chọn: prototype ghi `bs-ui.editorToolbar` ('compact'|'full', ghi trộn với khóa khác). Bản thật: `users.preferences` (migration 2008) — Việc cho backend.
- Mọi nút có `title` + `aria-label` tiếng Việt kèm phím tắt nếu có: Ctrl+B, Ctrl+I, Ctrl+U, Ctrl+K (liên kết), Ctrl+Shift+8 / Ctrl+Shift+7 (danh sách), Ctrl+\ (xóa định dạng), Ctrl+Z, Ctrl+Y / Ctrl+Shift+Z. Ô màu có `aria-label` "Màu chữ #…"/"Màu nền #…"; ô chọn màu tùy chọn, ô nhập các hộp, công tắc video đều có tên.

### 3. Điện thoại
- Khung thanh công cụ dưới 600px (container query `rtetb` + đo ResizeObserver gắn lại mỗi lần ref chạy): 1 hàng cuộn ngang trong khung, ẩn thanh cuộn, mũi tên ‹ › 44px hiện khi còn nút phía trước/sau, nút 44×44px. "Thêm công cụ" đứng ngoài vùng cuộn (luôn thấy). Ngoại lệ có chủ đích: luật cấm cuộn ngang áp cho thẻ / khung đọc, không áp cho thanh điều khiển.
- Kiểu chữ / Cỡ chữ / Màu: máy tính giữ menu thả; dưới 600px mở thành ngăn trượt từ đáy (đặt ngoài khung, `role="dialog"`, tiêu đề + nút Đóng 44px, mục 48px, ô màu ≥ 44px, `max-height:75dvh`, chừa safe-area).
- Toàn màn hình: `calc(100dvh - 236px)`, `56dvh`.
- Bôi đen bằng chạm: nghe thêm `selectionchange` (gom 120ms) và `touchend` → hiện nút "Viết lại" (44px trên cảm ứng).
- Sửa kèm (lỗi gốc): `.rte-wrap` có `container-type` → mọi hộp thoại `position:fixed` bên trong chỉ phủ khung trình soạn và menu thả nằm dưới lớp phủ. Dời `container-type` xuống thanh công cụ (LESSONS).

### 4. Panel "Viết lại đoạn đã chọn"
- Máy tính: popup neo ngay dưới đoạn bôi đen (tọa độ theo khung `.rte-wrap`; đang toàn màn hình thì theo màn hình), không lớp phủ tối, Esc / bấm ra ngoài / nút Đóng để đóng, focus vào cách viết đầu tiên, đóng thì trả focus về vùng soạn. Phương án AI có `aria-live`.
- Điện thoại: ngăn trượt từ đáy, `max-height:60dvh`, không lớp phủ; đoạn đang sửa tự cuộn lên trên mép ngăn.
- Giữ nguyên §15: chữ hiện tại cạnh phương án AI, bấm "Thay đoạn này" mới đổi, Ctrl+Z trả lại.

### 5. Dán từ Word / Zalo / trang web
Toast "Đã dán và làm sạch định dạng thừa" giữ nguyên. [SUY RA TỪ CODE] `onPaste`: có `text/html` (Word, trang web) → `sanitize()` bỏ `script/iframe/form/style/meta/link/object/embed`, gỡ thẻ ngoài danh sách giữ, bỏ mọi thuộc tính trừ `href`/`src`/`alt`; chỉ `text/plain` (Zalo dán chữ thường) → tách đoạn theo dòng trống. [CHƯA KIỂM ĐƯỢC] dán thật từ 3 nguồn — cần bộ nhớ tạm hệ điều hành; Claude Code kiểm bằng zip.

### 6. Focus 1b
- `data-focus-wrap` gắn trên khung có viền (`.rte-frame`, bao thanh công cụ + vùng soạn + chân). Thân soạn mang `contenteditable="true"`. Bấm vào thân soạn → chỉ khung sáng, đúng một khung ([ĐÃ RENDER VÀ THẤY] sáng + tối).
- `.rte-wrap` (chứa hộp Ảnh / Liên kết / Video / AI) không gắn → ô trong hộp Liên kết tự sáng ([ĐÃ RENDER VÀ THẤY]).
- Bỏ 5 `style-focus` (ô HTML, Mô tả ảnh, Chú thích, Liên kết video, Địa chỉ liên kết) và `outline:none` của chúng. Grep tệp: 0 `style-focus`. `.pe-rte` giữ `outline:2px solid transparent` cho chế độ tương phản cao.

### 7. Thang chữ thân bài một nơi
Đưa thang của Page Editor về tệp này, màu → token: h2 26px/800 · h3 19/700 · h4 16/700 · h5 14/700 chữ in · trích dẫn nghiêng, bo phải 8px · liên kết gạch chân · ảnh bo 8px · ô bảng 13,5px. Cỡ chữ thân bài 14px: Page Editor khai 15px nhưng style inline 14px của tệp này đè lên, nên hình đang thấy là 14px — giữ 14px để D1-4b bỏ bản chép không đổi hình. Mọi cỡ cộng `var(--fsp)` cho Chữ lớn.

### 8. L1-C-09 (phần trình soạn)
Link `styles.css` đặt ngay trước link Google Inter (sau `inter.css`). Ví dụ trong ô: "Ảnh: công trình hoàn thiện, tháng 7/2026" · "https://tenmien.vn/lien-he".

### Màn & trạng thái
Soạn · chỉ xem (`readOnly`) · AI bị khóa theo vai trò / hết từ AI (nút mờ + lý do) · hạn ngạch ≤ 20% / hết (chip) · hộp Ảnh, Video, Liên kết, Ω, Thư viện · HTML · toàn màn hình · thanh gọn / đầy đủ · điện thoại (ngăn đáy).

### Tương tác (mới / đổi)
Thêm công cụ ↔ Thu gọn (nhớ) · mũi tên cuộn thanh · ngăn đáy Kiểu / Cỡ / Màu · popup Viết lại neo · Esc đóng lần lượt menu → AI → hộp → toàn màn hình · phím tắt mục 2.

### Ánh xạ dữ liệu
Thân bài: `entries.body` (HTML, lưu bởi màn cha qua `onChange`). Tùy chọn thanh công cụ: `users.preferences` (migration 2008) khóa mới `editor_toolbar`. Hạn ngạch từ AI: `BSAI` (§7a). Không có bảng / cột mới khác.

### Quyền & gói
`canAi=false` → nút Viết lại mờ + "Vai trò của bạn chưa được dùng trợ lý AI". `readOnly` → không sửa, mọi nút báo "Nội dung này bạn chỉ được xem.". Tweak "Lời mời nâng gói" chưa đọc trong tệp này (Shell truyền vào màn cha) — giữ dòng TODO D0-5.

### Token
`--color-surface|surface-muted|surface-hover|border|border-strong|border-input|heading|body-text|body-muted|accent|admin-primary|admin-success-text|admin-warning-text|admin-danger-text`. Còn hex có chủ đích: bảng 40 màu chữ người dùng chọn (dữ liệu nội dung), nền tối thẻ xem trước video trong nội dung (`#212529`, chữ trắng), chữ trắng trên nền `--color-admin-primary`.

### Icon (Remix)
ri-paragraph · ri-bold · ri-italic · ri-underline · ri-strikethrough · ri-superscript · ri-subscript · ri-list-unordered · ri-list-ordered-2 · ri-link · ri-link-unlink · ri-format-clear · ri-arrow-go-back-line · ri-arrow-go-forward-line · ri-font-size-2 · ri-font-color · ri-palette-line · ri-align-left/center/right/justify · ri-indent-decrease/increase · ri-image-add-line · ri-video-add-line · ri-table-2 · ri-separator · ri-omega · ri-code-s-slash-line · ri-fullscreen-line · ri-fullscreen-exit-line · ri-expand-left-right-line · ri-contract-left-right-line · ri-arrow-left-s-line · ri-arrow-right-s-line · ri-arrow-down-s-line · ri-arrow-up-s-line · ri-close-line · ri-sparkling-2-line · ri-check-line.

### Chuỗi cần dịch (mới)
"Thêm công cụ — gạch chân, cỡ chữ, màu, căn lề, bảng, HTML…" · "Thu gọn — chỉ giữ các nút hay dùng" · "Cuộn thanh công cụ sang trái/phải" · "Xem các nút phía trước" · "Xem thêm nút phía sau" · "Kiểu chữ — đoạn văn, tiêu đề, trích dẫn" · "Màu chữ và màu nền" · "Màu chữ #…" · "Màu nền #…" · "Danh sách gạch đầu dòng (Ctrl+Shift+8) — bấm lần nữa để bỏ" · "Danh sách đánh số (Ctrl+Shift+7) — bấm lần nữa để bỏ" · "Chèn liên kết (Ctrl+K)" · "Bỏ liên kết" · "Xóa định dạng (Ctrl+\)" · "Thoát toàn màn hình (Esc)" · "Đóng (Esc)" · "Chữ hiện tại giữ nguyên tới khi bạn bấm thay." · "Hãy bôi đen chữ cần đổi cỡ trước." · "Đặt con trỏ vào liên kết hoặc bôi đen đoạn có liên kết trước." · "Đã đổi địa chỉ liên kết" · "Không còn bước nào để làm lại." · "Nội dung" · "Mã HTML của nội dung" · "Địa chỉ liên kết" · "Liên kết video" · "Mô tả ảnh" · "Chú thích dưới ảnh".

### Kết quả 12 tiêu chí dễ dùng
1. Hiểu trong 5 giây — đạt: thanh gọn 7 nhóm, chữ chờ hướng dẫn bôi đen để nhờ AI. (Ô soạn là thành phần, tiêu đề/nút chính do màn cha.)
2. ≤ 3 bước — đạt: định dạng 1 bước, chèn ảnh 3 bước (Ảnh → Chọn ảnh có sẵn → Chèn ảnh).
3. Không thuật ngữ trần — chưa trọn: nút "Xem / sửa HTML" và thanh đường dẫn thẻ (body › ul › li) vẫn hiện, nhưng đã nằm sau "Thêm công cụ" / chân khung. Cần owner chốt có ẩn thanh đường dẫn thẻ cho người mới không.
4. Icon kèm chữ — đạt theo ngoại lệ thanh định dạng: nút chỉ icon đều có `aria-label` + tooltip; nút mở rộng có chữ.
5. Ví dụ thật, lỗi nói cách sửa — đạt (liên kết, video).
6. Không mất việc — đạt: sổ hoàn tác 80 mốc, Hoàn tác lưu ngay phần đang gõ, xóa ảnh/video có toast Ctrl+Z.
7. Trạng thái màu + hình + chữ — đạt (chip hạn ngạch, nút bấm giữ `aria-pressed` + nền).
8. Không cắt chữ — đạt: nút `white-space:nowrap`; [ĐO BẰNG SCRIPT] 0 tràn ngang ở 360–1440.
9. Điện thoại — đạt ở Chromium giả lập: mọi nút 44px ở 360/390 (`tbSmall=0`), ngăn đáy đủ ở 390.
10. Hai tầng — đạt: thanh gọn mặc định, phím tắt + thanh đầy đủ cho người thạo.
11. Chữ lớn — đạt: cỡ thân bài 14 → 16px, 0 tràn ở 5 bề rộng. Hộp Ảnh / Video / Liên kết chưa theo `--fsp` (TODO).
12. Đúng thuật ngữ — đạt: "Xóa", "Hủy", "tùy chọn", "Hoàn tác".

### Kết quả Chất lượng hoàn thiện (A–E)
- A. Đẹp & nhất quán — đạt: nhóm nút ngăn bằng vạch, ngăn đáy 200ms tắt theo `prefers-reduced-motion`.
- B. Người lớn tuổi & người mới — đạt: chữ ≥ 12px, mọi việc có nút (không chỉ kéo-thả / rê chuột); tooltip không phải đường duy nhất (có `aria-label`).
- C. Bàn phím & trình đọc màn hình — chưa trọn: Esc đóng menu / popup AI / hộp; popup AI focus vào trong và trả focus. Hộp Ảnh / Video / Liên kết chưa bẫy focus, chưa trả focus về nút đã mở (TODO D1-4b). Thanh có `role="toolbar"`, nhóm có tên; chưa có phím mũi tên đi giữa nút (Tab đi từng nút).
- D. Chế độ tối & trình duyệt — đạt ở Chromium [ĐÃ RENDER VÀ THẤY]: khung, thanh, hộp Liên kết dùng token; ô nhập có nền `--color-surface` (trước đó nền mặc định trình duyệt ở tối).
- E. Đo thật — (1) tương phản: [CHƯA KIỂM ĐƯỢC] chưa chạy đo tương phản bằng harness — chỉ dùng token đã đạt ở `admin-base.css`; (2) vùng bấm 390: [ĐO BẰNG SCRIPT] 0 nút thanh công cụ < 44px; (3) Esc: [ĐO BẰNG SCRIPT] thoát toàn màn hình; Tab/trả focus hộp thoại: chưa (mục C); (4) console: 0 lỗi khi mở tệp.

### Kiểm hiển thị
Đo bằng `_audit/tmp/rte-lay.js` (iframe đúng bề rộng, đọc `scrollWidth`/`clientWidth`), không dùng `H.layouts` vì cần bật Chữ lớn trong iframe mà không ghi `bs-ui` của người dùng (bật qua state `fsL` của chính trình soạn).

| Bề rộng | Chữ thường | Chữ lớn | Ghi chú |
|---|---|---|---|
| 360 | 360 = 360 | 360 = 360 | nút 44px |
| 390 | 390 = 390 | 390 = 390 | ngăn Màu đủ 40 ô, ngăn Kiểu chữ đủ 6 mục, không cắt |
| 768 | 768 = 768 | 768 = 768 | thanh xuống dòng, nút 32px (chuột) |
| 1024 | 1024 = 1024 | 1024 = 1024 | |
| 1440 | 1440 = 1440 | 1440 = 1440 | |

Chrome/Edge: đạt (Chromium của môi trường thiết kế). Safari iPhone/iPad, Firefox, Samsung Internet, xoay ngang 844×390, phóng 200%: [CHƯA KIỂM ĐƯỢC] — không có trình duyệt đó ở đây; Claude Code kiểm bằng zip. Container query dùng `@container` (Safari 16+); `100dvh` (Safari 15.4+); `color-mix` (Safari 16.2+).

### Việc cho backend
- `users.preferences.editor_toolbar` ('compact'|'full', mặc định 'compact') — đọc khi mở trình soạn, ghi khi bấm "Thêm công cụ"/"Thu gọn". Prototype tạm ghi `bs-ui.editorToolbar`.
- Áp HTML từ chế độ "Xem / sửa HTML" phải làm sạch lại phía máy chủ (bỏ `script/iframe/form`, thuộc tính `on*`, `javascript:`) — không tin client.

### Việc còn lại (đã ghi TODO.md)
Page Editor bỏ `.pe-rte` chép riêng · khung chứa Page Editor có `container-type` (hộp thoại fixed của trình soạn bị giữ trong khung đó khi nhúng) · hộp Ảnh / Video / Liên kết / Ω theo Chữ lớn, trượt đáy ở điện thoại, bẫy + trả focus · kiểm Safari/Firefox/cảm ứng — D1-4b / Claude Code.

### Verifier
Gọi ready_for_verification 07/10 — kết quả ghi bên dưới nếu có báo lỗi.

---

## D1-4b · Khuôn soạn nội dung — một khuôn cho mọi loại (07/10/2026)

### Tổng quan
`Page Editor - Options.dc.html` (giữ tên tệp) LÀ khuôn soạn cho Trang · Tin tức · Dịch vụ · Dự án và loại mới khai trong registry. Prop `kind` = khóa loại (`pages` · `posts` · `services` · `projects` …). Tiêu đề màn theo loại: "Thêm trang", "Sửa tin tức", "Thêm dự án". `Quản lý Bài viết - Options` không còn markup Thêm/Sửa — nút Thêm mới / bấm dòng phát `bs-goto {screen:'<loại>.create' | '<loại>.edit', params:{ma}}`. Ô soạn thân bài vẫn là `Trình soạn nội dung.dc.html` (không sửa).

### File
- `Page Editor - Options.dc.html` — khuôn (sửa tại chỗ, giữ bố cục cũ, thêm phần còn thiếu).
- `Quản lý Bài viết - Options.dc.html` — gỡ khối `isEdit` (≈594 dòng), hộp Trợ lý viết / Nâng cấp / chặn rời trình soạn; `openEditor` → `bs-goto` + `maOf()`.
- `content-workflow.js` — chỉ THÊM: `store.byMa` · `store.maOf` · `store.catTree` · `store.kinds`; mã công khai 26 ký tự kiểu ulid gắn cho mọi bài (`ma`), giữ nguyên khi đổi đường dẫn.
- `shell-screens.js` — `create` / `edit` của mọi loại → `dc: 'Page Editor - Options'`, `embedId: 'page-editor'`, `turn: 'D1-4b'`, `status: 'ready'`, props có `kind`, `ma`, `parent`, `lang`.
- `Admin Shell.dc.html` — chỉ khối gắn: `kind="{{ ctType }}" ma="{{ ctMa }}" parent="{{ ctParent }}" lang="{{ ctLang }}"`.
- `Chuẩn giao diện SaaS Starter.dc.html` — chỉ `#h-ca`.
- `SaaS Starter - Bản đồ màn hình.md` (dòng 10–11, 13) · `TODO.md` · `LESSONS.md` (6 dòng).

### Mức hoàn thiện
Prototype tương tác, đủ để build. Phần 1 (mục 1–3) và phần 2 (mục 4–8) làm trong cùng lượt.

### Màn đang chạy · Bản mới · Giữ bản nào · Vì sao
| Màn đang chạy | Bản mới | Giữ | Vì sao |
|---|---|---|---|
| Thanh thao tác dính trên (Xem trước · ⋯ · nút chính) | Giữ nguyên, thêm câu bước tiếp khi không có nút | Bản cũ + thêm | Không dời nút chính |
| Thanh ngôn ngữ, Dịch, hạn ngạch | Giữ nguyên; ô chọn co chữ, ai-job khi đang dịch | Bản cũ + thêm | Luật §2–§11 đã đạt |
| Tab Soạn thảo / Trình dựng | Trình dựng chỉ hiện với Trang | Bản cũ | Bài không có bố cục Trình dựng |
| Thẻ Ảnh đại diện ở cột phải | Chuyển sang cột chính (mục 4), dạng image-field 240×240 | Bản mới | Prompt: cột chính = Tiêu đề · Nội dung · Ảnh |
| Ảnh "Gần đây" dưới ô ảnh trống | Bỏ — Thư viện mở bằng "Chọn từ thư viện" | Bản mới | Gọn cho người mới · Cần owner chốt nếu muốn giữ |
| Tweak "Thiết bị" (máy tính / máy tính bảng / điện thoại) | Bỏ; khuôn co theo khung chứa | Bản mới | Prompt mục 2 |
| Bảng kiểm SEO mở sẵn 3 nhóm | Điểm + nút sửa nhanh hiện sẵn, danh sách chi tiết gập | Bản mới | Prompt mục 4 |
| Phần Thêm/Sửa của Quản lý Bài viết (`.cat-list`, dòng "Hiển thị", AI Writer) | Chuyển vào khuôn (ô chọn chuyên mục có vùng cuộn, dòng Hiển thị đã có, AI Writer theo `kind`) | Lấy HỢP | LESSONS #15 |

### Màn & trạng thái
Thêm (mẫu trang / Viết mới · Tự viết) · Sửa · tự lưu (6 trạng thái save-status) · Đã đăng (dải "Xem trên website") · Đã hẹn giờ / Chờ duyệt / Không công khai / Đã gỡ khỏi website với Người viết (câu bước tiếp, không có nút) · quy trình duyệt bật/tắt · AI: được cấp / khóa + "Xem gói nâng cấp" (Lời mời nâng gói BẬT) / ẩn (TẮT) · hết từ AI (luật cũ) · đang dịch (ai-job, Hủy) · Xem trước Máy tính / Điện thoại · 1 / 3 / 14 ngôn ngữ (có العربية → ô tự `dir="rtl"`).
Không có quyền / đang tải / lỗi tải bài: do Shell vẽ (khóa không quyền, khung chờ) — khuôn nhận bài không tìm thấy thì mở dạng Thêm mới. Chưa vẽ trạng thái "lỗi lưu / mất mạng" riêng của khuôn → D1-4d (cùng rời trang, nháp đè).

### Tương tác (mới / đổi)
1. Một khuôn: `kind()` đọc prop (nhúng) hoặc nút "Loại" ở thanh Tweak (đứng riêng). Trang = không mô tả ngắn, không chuyên mục; có Loại trang (mẫu hiển thị), Trang cha, Thứ tự, Trình dựng. Loại bài = mô tả ngắn 120–200 (dừng 255) + xem trước thẻ tin, chuyên mục (sao = chính), Thẻ, Mẫu hiển thị, Tác giả, Ngày đăng hiện trên bài.
2. Co theo khung chứa: khung `#1a` rộng 100% tối đa 1240px; hai cột bằng flex-wrap (cột chính `flex:999 1 520px`, cột phụ `flex:1 1 300px`) — không đặt `container-type` lên tổ tiên của ô soạn (hộp thoại fixed của Trình soạn nội dung). Thanh ngôn ngữ giữ container query sẵn có.
3. Focus 1b: bỏ `.pe-rte` chép riêng và `.pe-sbox:focus-within`; ô Đường dẫn, ô tìm ngôn ngữ, ô tìm chuyên mục gắn `data-focus-wrap`; bỏ 8 `style-focus` + 1 `style-focus-within`, bỏ 10 `outline:none`.
4. Mặc định gọn: cột chính Tiêu đề · Nội dung · Ảnh đại diện; cột phải thẻ Đăng. Nút "Thêm tùy chọn — …" (aria-expanded) mở Đường dẫn, Mô tả ngắn, SEO, Chuyên mục, Thẻ, Thuộc tính; nhớ ở `bs-ui.editorMore`.
5. Tạo mới: Trang → "Bắt đầu từ mẫu" (Giới thiệu · Dịch vụ · Liên hệ · Trang trống — điền bố cục + chữ gợi ý, đặt Loại trang); bài → "Viết mới" (AI, chỉ khi được cấp) + "Tự viết" (focus ô soạn).
6. Xem trước (nút ở thanh trên): hộp toàn màn ở điện thoại, Máy tính / Điện thoại, giả lập trang website (tiêu đề, mô tả, ảnh, thân bài), "Chờ backend". Esc đóng, Tab vòng trong hộp, đóng trả focus về nút đã mở. Sau khi Đăng: dải "Đã đăng — Xem trên website" + đường dẫn công khai theo ngôn ngữ.
7. Không nút im lặng: nhóm quyền không có `entries.publish` mở bài Đã hẹn giờ · Chờ duyệt · Không công khai · Đã gỡ khỏi website → ẩn nút chính + danh sách bước, hiện 1 câu, ví dụ "Tin tức đã hẹn giờ — sẽ tự lên website lúc 09:00 15/07/2026. Không cần bấm gì thêm."
8. Ô chọn chuyên mục: ô tìm luôn hiện; cây thụt lề 18px/cấp; đang tìm → phẳng + đường cha ("Kiến thức kỹ thuật › Gia công CNC ›"); loại khác hiện chip tên loại — loại hiện tại bật duyệt thì khóa + câu lý do ngay tại dòng (`aria-describedby`), không bật thì chọn được + "Đặt làm chuyên mục chính thì bài chuyển sang <loại>"; "+ Thêm chuyên mục mới" chỉ với `taxonomies.manage` (Người viết, Cộng tác viên không thấy), Enter thêm, Esc hủy.

### Ánh xạ dữ liệu
- Mở bài: `routes/web.php:231` `/admin/content/{ulid}/edit` → `params.ma`. Kho prototype: `BSCW.store.byMa(kind, ma)`.
- Đã có trong repo (không gắn Chờ backend): mô tả ngắn, tiêu đề + mô tả Google, ảnh chia sẻ theo ngôn ngữ, trang cha, mẫu hiển thị, tác giả, ngày đăng, Hẹn giờ đăng, Không công khai, nhiều chuyên mục (đứng đầu = chính) — `editor.blade.php` chưa nối.
- Khóa chuyên mục theo quy trình: `app/Modules/Admin/Livewire/Entries/Editor.php::keepsGovernedSection`, câu `entries.change_err_category_governed`.
- Gửi duyệt chỉ cho bản nháp: `PublishEntry.php` (04/10); `Editor.php:303` lưu rồi im lặng với bài khác → xem Việc cho backend.

### Quyền & gói
Người viết / Cộng tác viên: không có "+ Thêm chuyên mục mới", không có nút chính khi bài không ở Bản nháp (câu bước tiếp). Quy trình duyệt bật: chuyên mục loại khác khóa. AI (Q12): prop `aiGranted` (website được cấp AI) + `upsell` (Lời mời nâng gói) → hiện / khóa + "Xem gói nâng cấp" (mở `settings` › `plan`) / ẩn. Tweak đứng riêng: Loại · Nhóm quyền (Tweak + ô chọn) · Quy trình · Trạng thái · aiGranted · upsell · Hiện ghi chú thiết kế.

### Token
`--color-surface|surface-muted|surface-hover|body|border|border-strong|border-input|heading|body-text|body-muted|accent|admin-primary|admin-secondary-text|admin-success-text|admin-danger-text|brand-gold|brand-gold-text`. 35 chỗ `#2050a0` gắn cứng → `--color-accent` (chữ/viền) / `--color-admin-primary` (nền). Còn hex có chủ đích: khung xem trước website (storefront, giữ màu sáng), màu mẫu ảnh (dữ liệu), chấm trạng thái ngôn ngữ trong JS (đã có từ trước — đổi ở lượt thanh ngôn ngữ kế).

### Icon (Remix, mới)
ri-add-line · ri-subtract-line · ri-upload-2-line · ri-folder-image-line · ri-image-2-line · ri-aspect-ratio-line · ri-close-circle-line · ri-information-line · ri-file-line · ri-briefcase-line · ri-map-pin-line · ri-computer-line · ri-smartphone-line · ri-server-line · ri-star-line · ri-star-fill · ri-lock-line · ri-search-line · ri-loader-4-line · ri-edit-circle-line · ri-checkbox-circle-line.

### Chuỗi cần dịch (mới)
"Thêm tùy chọn" · "Thu gọn tùy chọn" · "Bắt đầu từ mẫu" · "Trang trống" · "Bắt đầu <loại>" · "Viết mới" · "Tự viết" · "Xem gói nâng cấp" · "Xem trước trên website" · "Máy tính" · "Điện thoại" · "Đã đăng — Xem trên website" · "<Loại> đã hẹn giờ — sẽ tự lên website lúc … Không cần bấm gì thêm." · "… đang chờ duyệt — người duyệt sẽ đăng hoặc trả lại kèm lý do." · "Chuyên mục này sẽ đưa bài ra khỏi quy trình duyệt của <loại> — …" · "Thêm chuyên mục mới" · "Thêm và chọn" · "Thẻ gom các bài cùng chủ đề…" · "Mẫu hiển thị" · "Ngày đăng hiện trên bài" · "Đổi ở đây là đổi cho mọi nơi dùng ảnh này." · "Đã lưu nháp · HH:MM" · "Xem danh sách chi tiết (N mục)".

### Kết quả 12 tiêu chí dễ dùng
1. Đạt — tiêu đề theo loại + 1 nút chính; người mới chỉ thấy 3 ô + thẻ Đăng.
2. Đạt — menu › Thêm mới → nhập tiêu đề → Đăng (3 bước).
3. Đạt — "Đường dẫn", "Mô tả trên Google"; chữ HTML chỉ trong "Thêm công cụ" của ô soạn.
4. Đạt — nút mới đều có chữ; nút chỉ icon (bỏ thẻ) có aria-label + tooltip.
5. Đạt — ví dụ thật trong ô (placeholder chỉ ví dụ); hướng dẫn ở dòng gợi ý.
6. Chưa trọn — tự lưu + chặn rời trang có từ trước; nháp đè/rời trang theo luật mới → D1-4d.
7. Đạt — câu bước tiếp, khóa chuyên mục có icon khóa + chữ.
8. Đạt — nút nowrap + ellipsis; 0 tràn ngang 360–1440.
9. Đạt ở Chromium giả lập — mọi việc có ở 360/390; Xem trước toàn màn.
10. Đạt — "Thêm tùy chọn" cho người thạo, nhớ lựa chọn.
11. Đạt — đo zoom 1.143 (cách Shell bật Chữ lớn): 0 tràn.
12. Đạt — Hủy, Xóa, tùy chọn, "Đã gỡ khỏi website".

### Kết quả Chất lượng hoàn thiện (A–E)
- A. Đạt — khoảng cách 4/8px, 1 nút chính mỗi vùng; chuyển động tắt theo `prefers-reduced-motion`.
- B. Đạt — chữ ≥ 12px, mọi việc có nút (kéo thả ảnh có nút thay).
- C. Một phần — hộp Xem trước: focus vào nút Đóng, Tab vòng, Esc, trả focus [SUY RA TỪ CODE]; hộp Ảnh/Video/Liên kết của ô soạn chưa (TODO). Toast `role="status"` luôn có trong DOM.
- D. [CHƯA KIỂM ĐƯỢC] tương phản chế độ tối bằng harness — chỉ dùng token.
- E. (1) tương phản [CHƯA KIỂM ĐƯỢC]; (2) vùng bấm 390: nút mới 36px chuột / 44px `pointer:coarse` [SUY RA TỪ CODE]; (3) Esc/Tab hộp Xem trước [SUY RA TỪ CODE]; (4) console: Page Editor 0 lỗi, Admin Shell 0 lỗi (6 cảnh báo `r.*` có từ trước) [ĐÃ RENDER VÀ THẤY].

### Kiểm hiển thị
[ĐO BẰNG SCRIPT] `_audit/harness.html` `H.layouts(['Page Editor - Options.dc.html'],[360,390,768,1024,1440])` + iframe tự đo với `zoom:1.143` (Chữ lớn của Shell):

| Bề rộng | Chữ thường sw = cw | Chữ lớn sw = cw | Ghi chú |
|---|---|---|---|
| 360 | 345 = 345 | 345 = 345 | lần đo đầu 365 > 345 (thanh thao tác + ô chọn ngôn ngữ) → đã sửa |
| 390 | 375 = 375 | 375 = 375 | |
| 768 | 753 = 753 | 753 = 753 | |
| 1024 | 1009 = 1009 | 1009 = 1009 | |
| 1440 | 1425 = 1425 | 1425 = 1425 | |
Grep khuôn: 0 `style-focus` · 0 `:focus-within{` · 0 `.pe-rte{` · 0 "Bản nháp #" · 0 "/admin/pages" · 0 "Lưu trữ". Safari/Firefox/Samsung, xoay ngang, 200%: [CHƯA KIỂM ĐƯỢC] — Claude Code bằng zip.

### Luồng trong Shell (đã chạy)
Tin tức › Danh sách → "Viết bài mới" → `#/site/posts.create` → nhập tiêu đề → tự lưu nháp → Cập nhật → kho `published` → danh sách "Đã đăng" → bấm Sửa → `#/site/posts.edit?ma=01J…` mở đúng bài; không tải lại trang, `params` không có id số [ĐÃ RENDER VÀ THẤY]. Nhánh Tweak "Duyệt trước khi đăng" + Người viết → Chủ website: chạy được ở khuôn đứng riêng (câu bước tiếp, chuyên mục khóa); trong Shell cần đổi Tweak của Shell — [CHƯA KIỂM ĐƯỢC], Claude Code chạy `_audit/d05-run.html`.

### Việc cho backend
- Thẻ (Chờ backend): nhóm thẻ cho entries (bảng `tags` + `entry_tag`, theo website, nhãn theo ngôn ngữ); luật: tên không trùng trong website, tự tạo khi gõ thẻ mới nếu người dùng có `entries.create`.
- Xem trước bài chưa đăng (Chờ backend): đường xem trước có chữ ký, hết hạn ngắn (đề xuất 30 phút), chỉ trong website đó, header `X-Robots-Tag: noindex`, không ghi lượt xem.
- Gửi duyệt bài không ở Bản nháp: thiết kế chọn **không có nút** — câu bước tiếp theo trạng thái (hẹn giờ: hệ thống tự đăng). `Editor.php:303` hiện lưu rồi im lặng — đổi thành trả về câu tương ứng nếu vẫn gọi được.
- `users.preferences.editor_more_options` (true|false, mặc định false) — khóa mới.
- Câu `entries.category_governed` dùng "thể loại" → "loại nội dung" (Bảng thuật ngữ).
- Thêm chuyên mục tại chỗ từ khuôn: gọi cùng action với màn Chuyên mục, kiểm `taxonomies.manage` phía máy chủ.

### Verifier
Gọi ready_for_verification 07/10 (D1-4b) — kết quả ghi bên dưới nếu có báo lỗi.

---

## D1-4c · Lịch sử phiên bản + SEO còn lại (07/10/2026)

### Tổng quan
Làm trên khuôn `Page Editor - Options.dc.html` (không tách màn). Mở rộng dropdown "Lịch sử phiên bản" có sẵn trong thẻ Đăng (`onToggleRev`) thành tính năng đủ theo backend đã lên prod 28/09 — **không gắn "Chờ backend"**. Kèm 4 việc SEO còn lại của TODO đợt 4 nằm trong khuôn. Gộp luồng phụ D1-7: README D1-7 không có mục "Gộp vào tệp chung" → bỏ qua.

### File
- `Page Editor - Options.dc.html` — sửa tại chỗ (logic lịch sử, 3 hộp thoại, dải cảnh báo SEO, lớp CSS `.rv-*`, Tweak "Lịch sử").
- `TODO.md` · `TODO - Chuẩn hoá Page Editor & Bài viết (đợt 4).md` (đánh dấu 5 mục đã đóng) · `SaaS Starter - Bản đồ màn hình.md` (dòng 14 → ready) · `LESSONS.md` (3 dòng).
- Đo: `_audit/tmp/d14c-lay.html`, ảnh `_audit/tmp/d14c-lay.jpg`.

### Mức hoàn thiện
Prototype tương tác đủ để build. Dữ liệu lịch sử là mẫu sinh theo nội dung hiện tại (27 bản VI · 6 EN · 2 JA · 1 cho ngôn ngữ khác) để thấy "Tải thêm" và khác biệt thật.

### Màn đang chạy · Bản mới · Giữ bản nào · Vì sao
| Màn đang chạy | Bản mới | Giữ | Vì sao |
|---|---|---|---|
| Nút "Lịch sử phiên bản" trong thẻ Đăng, mở danh sách thả | Giữ nút + vị trí; danh sách thả thành khối trong thẻ: 3 bản gần nhất + "Xem tất cả (N bản)" | Bản cũ + thêm | Prompt: mở rộng, không vẽ lại |
| Nhãn nút | Thêm mã ngôn ngữ khi website có ≥ 2 ngôn ngữ ("Lịch sử phiên bản · EN") | Bản mới | Mỗi dòng lịch sử = 1 ngôn ngữ |
| SEO bắt buộc đỏ nằm trong danh sách chặn Đăng | Dải cảnh báo vàng trong thẻ Đăng, nút Đăng vẫn bấm được | Bản mới | Prompt mục 2 — người mới không bị kẹt |

### 1. Lịch sử phiên bản (as-built)
Nguồn: `docs/design.md` §8; `app/Modules/Cms/Queries/EntryRevisions.php` (keyset, 20 bản/lượt) · `Actions/RecordEntryRevision.php` · `Actions/RestoreEntryRevision.php` · `Enums/RevisionKind.php`.
- **Theo ngôn ngữ**: danh sách đọc `revList(S.loc)` — đổi ngôn ngữ ở thanh ngôn ngữ thì chỉ còn bản của ngôn ngữ đó. Khôi phục bản EN không đụng bản VI.
- **Chỉ ghi khi đang công khai**: bài mới / Bản nháp / Chờ duyệt / Đã hẹn giờ chưa từng lên website → trống "Lịch sử bắt đầu từ lần đăng đầu tiên" + 1 câu giải thích. Đăng → ghi 1 bản "Đăng" cho mọi ngôn ngữ có nội dung; lưu khi đang công khai → 1 bản "Sửa khi đang công khai" cho ngôn ngữ đang sửa; bản giống hệt bản mới nhất thì không ghi.
- **4 lý do** (chip có icon + chữ, tooltip): Bản gốc (`baseline`) · Đăng (`publish`) · Sửa khi đang công khai (`edit`) · Khôi phục (`restore`). Mỗi dòng: lý do · ngày giờ (giờ website GMT+7) · người. Bản đầu có chip "Mới nhất".
- **Thẻ**: 3 bản gần nhất (Xem · Khôi phục) + "Xem tất cả (N bản)".
- **Danh sách đầy đủ**: hộp thoại (máy tính 640px; ≤ 600px **toàn màn** 100dvh + safe-area); đang tải (3 khung giữ chỗ tĩnh) · lỗi tải + "Thử lại" · trống · 20 bản/lượt + "Tải thêm (còn N)" (không số trang). Chân hộp: 4 lý do + "giữ ít nhất 20 bản gần nhất; bản cũ hơn 90 ngày tự dọn".
- **Xem 1 bản**: nội dung đầy đủ (Tiêu đề · Đường dẫn · Mô tả ngắn (bài) · Tiêu đề / Mô tả trên Google · Ảnh đại diện + Mẫu hiển thị (chip "Dùng chung mọi ngôn ngữ") · thân bài theo khối). Chỗ khác hiện tại: nền vàng + chip "Khác hiện tại" + dòng "Hiện tại: …"; khối có ở hiện tại mà bản này không có: nền đỏ nhạt + chip "Không có trong bản này". Câu tóm tắt đầu hộp đếm số chỗ khác.
- **Hộp xác nhận Khôi phục** (`role="alertdialog"`, nút Hủy được focus; ≤ 600px trượt từ đáy):
  - đang công khai: "… đang công khai — khách thấy nội dung này ngay khi bạn bấm Khôi phục, như khi bấm Lưu."; không công khai: câu trạng thái giữ nguyên;
  - luôn có: "Hệ thống tạo một bản mới trong lịch sử, không xóa bản nào …";
  - nhiều ngôn ngữ: chỉ đổi bản <ngôn ngữ>, bản khác giữ nguyên;
  - ảnh đại diện / mẫu hiển thị khác hiện tại: dải vàng "Ảnh đại diện và mẫu hiển thị dùng chung — mọi ngôn ngữ sẽ đổi theo.";
  - đang có thay đổi chưa lưu: cảnh báo sẽ bị thay; bản có ảnh đã xóa: báo trước số ảnh.
  - Nút "Khôi phục bản này" → "Đang khôi phục…" (chặn bấm 2 lần). Xong: không đổi trạng thái, ghi bản "Khôi phục" (mọi ngôn ngữ nếu đổi phần dùng chung), dải xanh trong thẻ "Đã khôi phục bản … N ảnh không còn trong thư viện đã được bỏ trống."
- **4 lỗi từ chối** — nguyên văn `lang/vi.json` 759–762, không hiện mã: `slug_taken` "Đường dẫn của phiên bản này nay đã được nội dung khác dùng. Đổi đường dẫn của một trong hai rồi khôi phục lại." · `locale_disabled` "Phiên bản này thuộc một ngôn ngữ đang tắt trên website. Bật lại ngôn ngữ đó để khôi phục." · `snapshot_incompatible` "Không thể khôi phục phiên bản này: bố cục của nó dùng những phần trình soạn thảo không còn hỗ trợ." · `entry_trashed` "Nội dung này đang ở Thùng rác. Khôi phục nó khỏi Thùng rác trước, rồi khôi phục phiên bản." Hiện trong hộp xác nhận (`role="alert"`), nút còn "Đóng".
- **Tweak "Lịch sử"** (thanh Tweak đứng riêng, ghi chú thiết kế): Bình thường · Lỗi tải · Khôi phục: 2 ảnh đã xóa · Từ chối × 4.

### 2. SEO còn lại (trong khuôn)
- **SEO bắt buộc còn đỏ → chỉ cảnh báo**: dải vàng trong thẻ Đăng "Chưa đạt N mục SEO bắt buộc — vẫn đăng được" + tối đa 3 mục + nút mở bảng kiểm SEO (mở "Thêm tùy chọn" và cuộn tới bảng kiểm). Nút Đăng vẫn bấm được. Lý do: người mới không bị kẹt; SEO sửa được sau khi đăng. Chặn Đăng chỉ còn: thiếu tiêu đề bản nguồn · thiếu nội dung · hẹn giờ chưa chọn giờ.
- **Phân vai 2 công cụ AI** (chỉ khi website được cấp AI — `aiGranted`/`upsell`, Q12): `openBodyAi()` là cửa duy nhất — bài có chữ → hộp `Nâng cấp nội dung`; bài trống → `AI Writer Panel v2`. Trong hộp Nâng cấp, việc "Viết lại toàn bài/trang" đóng hộp và mở AI Writer với nội dung hiện tại làm nguyên liệu (`source-text` · `source-heads` · `source-words`). Chỉ sửa chỗ khuôn mở hai hộp; hai tệp bộ AI không sửa.
- **Đã đóng (xác nhận)**: panel cũ "AI giúp gì cho bài này?" không còn cửa mở (`openBodyAi()` là cửa duy nhất); ô "Mô tả ảnh" ảnh đại diện cho Trang có (`featuredAlt`, image-field D1-4b).
- **Từ khóa làm nguồn**: `aiMakeField` (tiêu đề / mô tả Google / mô tả ngắn), `aiMake`, `aiBest` đều lấy `aiSeoKw()` — từ khóa chính, thiếu thì suy từ tiêu đề.
- **Không làm ở lượt này** (TODO.md "· bộ AI (sau core v1)"): bước "Kiểm định" của AI Writer chấm theo `BSSEO.audit`; 2 điểm còn lại của "Nâng cấp bài này"; kiểm tay luồng "Việc lớn → Trợ lý viết".

### Màn & trạng thái (mới)
Lịch sử: trống · đang tải · lỗi + Thử lại · có bản (3 trong thẻ) · danh sách đầy đủ + Tải thêm · xem 1 bản (khác biệt) · hỏi khôi phục (công khai / không; khác ảnh-mẫu dùng chung; có thay đổi chưa lưu; ảnh đã xóa) · đang khôi phục · thành công (dải xanh) · 4 lỗi từ chối · Chỉ xem (không có Khôi phục). SEO: dải cảnh báo.

### Tương tác
Esc đóng lần lượt hỏi → xem → danh sách; mở hộp focus vào nút đầu (Đóng / Hủy), Tab vòng trong hộp (`revTrap`), đóng trả focus về nút đã mở (`revPush/revPop`); bấm nền mờ đóng (trừ khi đang khôi phục).

### Ánh xạ dữ liệu
| Màn | Repo |
|---|---|
| Danh sách theo ngôn ngữ, 20 bản/lượt, "Tải thêm" | `app/Modules/Cms/Queries/EntryRevisions.php` |
| Lý do | `app/Modules/Cms/Enums/RevisionKind.php` (baseline · publish · edit · restore) |
| Ghi bản (công khai; phần dùng chung → mọi ngôn ngữ) | `app/Modules/Cms/Actions/RecordEntryRevision.php` |
| Khôi phục: quyền sửa, không đổi trạng thái, ảnh đã xóa bỏ trống + đếm, 4 lý do từ chối | `app/Modules/Cms/Actions/RestoreEntryRevision.php` |
| Câu lỗi | `lang/vi.json:759–762` `entries.revision_err_*` |
| Mã bản | `entry_revisions.ulid` (prototype sinh mã 26 ký tự; không hiện id số) |

### Quyền & gói
| Việc | Cần | Nhóm quyền (BSCW) |
|---|---|---|
| Xem lịch sử, xem 1 bản | quyền xem bài (`entries.view`) | mọi nhóm, kể cả **Chỉ xem** |
| Khôi phục | quyền sửa bài (`entries.edit.any`, hoặc `entries.edit.own` với bài của mình — máy chủ kiểm qua `EntryPolicy::update`) | nhóm có quyền sửa. **Chỉ xem: không thấy nút Khôi phục** (thẻ, danh sách, hộp xem) |

Lịch sử không theo gói. Nút AI theo Q12 như D1-4b.

### Token
Lớp `.rv-*` mới chỉ dùng token: `--color-surface|surface-hover|border|border-input|heading|body-text|body-muted|accent|admin-success-text|admin-warning-text|admin-danger-text`; nền tô pha với `--color-surface`. Lớp phủ `rgba(33,37,41,.5)` như hộp Xem trước có sẵn.

### Icon (Remix, mới)
ri-history-line · ri-archive-line · ri-send-plane-line · ri-edit-line · ri-eye-line · ri-list-check-2 · ri-arrow-down-line · ri-refresh-line · ri-wifi-off-line · ri-global-line · ri-git-branch-line · ri-file-copy-2-line · ri-translate-2 · ri-image-line · ri-error-warning-line · ri-search-eye-line.

### Chuỗi cần dịch (mới)
"Lịch sử phiên bản" · "Lịch sử bắt đầu từ lần đăng đầu tiên" · "Bản gốc" · "Đăng" · "Sửa khi đang công khai" · "Khôi phục" · "Mới nhất" · "Xem tất cả (N bản)" · "Tải thêm (còn N)" · "Đang tải lịch sử…" · "Không tải được lịch sử phiên bản — kiểm tra kết nối mạng rồi thử lại." · "Khác hiện tại" · "Hiện tại: …" · "Không có trong bản này" · "Dùng chung mọi ngôn ngữ" · "Khôi phục bản …?" · "Khôi phục bản này" · "Đang khôi phục…" · "Hệ thống tạo một bản mới trong lịch sử, không xóa bản nào — muốn quay lại thì khôi phục bản đó." · "Ảnh đại diện và mẫu hiển thị dùng chung — mọi ngôn ngữ sẽ đổi theo." · "N ảnh không còn trong thư viện đã được bỏ trống." · "Chưa đạt N mục SEO bắt buộc — vẫn đăng được". 4 câu lỗi đã có ở `lang/*.json:759–762`.

### Kết quả 12 tiêu chí dễ dùng
1. Đạt — lịch sử nằm cạnh việc chính (thẻ Đăng), không thêm nút chính mới.
2. Đạt — khôi phục 3 bước: Lịch sử phiên bản → Khôi phục → Khôi phục bản này.
3. Đạt — không có "revision/snapshot"; lý do bằng chữ đời thường.
4. Đạt — Xem / Khôi phục / Đóng có chữ.
5. Đạt — lỗi nói cách sửa (nguyên văn repo).
6. Đạt — khôi phục tạo bản mới, không xóa bản nào; cảnh báo thay đổi chưa lưu.
7. Đạt — chip lý do, khác biệt: nền + chip chữ + icon.
8. Đạt — chip/nút nowrap; [ĐO BẰNG SCRIPT] 0 tràn 360–1440.
9. Đạt (Chromium giả lập) — 390: danh sách toàn màn, hỏi khôi phục trượt đáy, nút 44px khi `pointer:coarse`.
10. Đạt — 3 bản trong thẻ cho người mới, "Xem tất cả" cho người thạo.
11. Đạt — đo zoom 1.143 (cách Shell bật Chữ lớn): 0 tràn.
12. Đạt — "Khôi phục", "Hủy", "Thùng rác", "Đường dẫn".

### Kết quả Chất lượng hoàn thiện (A–E)
- A. Đạt — thang 4/8px, 1 nút chính mỗi hộp; khung đang tải tĩnh (LESSONS: không animation liên tục trên trang nhiều container query).
- B. Đạt — chữ nội dung 14px, chú thích ≥ 12px, giãn dòng 1,5; "Đang khôi phục…" ngay khi bấm, chặn bấm 2 lần; lỗi tải có "Thử lại".
- C. Đạt [ĐO BẰNG SCRIPT] ở 390: mở danh sách → focus `pe-rv-all-x` (Đóng); mở hỏi → focus `pe-rv-ask-x` (Hủy). Tab vòng + Esc + trả focus [SUY RA TỪ CODE]. Hộp có `aria-labelledby`; danh sách `aria-live="polite"`; dải thành công `role="status"`, lỗi `role="alert"`.
- D. [CHƯA KIỂM ĐƯỢC] tương phản chế độ tối bằng harness — lớp mới chỉ dùng token đã đạt ở `admin-base.css`.
- E. (1) tương phản [CHƯA KIỂM ĐƯỢC] (như D); (2) vùng bấm 390: nút `.rv-acts` 44px khi `pointer:coarse` [SUY RA TỪ CODE]; (3) focus mở hộp [ĐO BẰNG SCRIPT]; (4) console Page Editor: chờ verifier.

### Kiểm hiển thị
[ĐO BẰNG SCRIPT] `_audit/tmp/d14c-lay.html` (iframe đúng bề rộng; Chữ lớn = `zoom:1.143` như Shell, không ghi `bs-ui` của người dùng):

| Bề rộng | Chữ thường sw = cw | Chữ lớn sw = cw | Ghi chú |
|---|---|---|---|
| 360 | 345 = 345 | 345 = 345 | |
| 390 | 375 = 375 | 375 = 375 | thẻ: 3 bản; "Xem tất cả" → hộp 375×844 (toàn màn), 20 dòng + "Tải thêm"; "Khôi phục" → hộp 375×344 ở đáy (top 501) |
| 768 | 753 = 753 | 753 = 753 | |
| 1024 | 1009 = 1009 | 1009 = 1009 | |
| 1440 | 1425 = 1425 | 1425 = 1425 | |

Safari iPhone/iPad, Firefox, Samsung Internet, xoay ngang, 200%: [CHƯA KIỂM ĐƯỢC] — Claude Code bằng zip.

### Việc cho backend
Không có việc mới (backend lịch sử đã lên prod 28/09). Ghi chú: để hộp xác nhận nói câu "mọi ngôn ngữ sẽ đổi theo" TRƯỚC khi bấm, màn cần so `template_override` + `cover_media_id` của bản với hiện tại — `EntryRevisions` trả kèm 2 giá trị này (hoặc 1 cờ).

### Lượt kế tiếp
**D1-4d**.

### Verifier
Gọi ready_for_verification 07/10 (D1-4c) — kết quả ghi bên dưới nếu có báo lỗi; không trả kết quả thì [CHƯA KIỂM ĐƯỢC], Claude Code kiểm bằng zip.

---

## D1-4d · Nháp đè bản công khai + rời trang + hai người cùng sửa + audit hiển thị (07/10/2026)

### Tổng quan
Làm trên khuôn `Page Editor - Options.dc.html` (sửa tại chỗ, giữ bố cục). Luật một câu: chỉ người có quyền Đăng (TẮT duyệt) hoặc bước duyệt (BẬT duyệt) mới đổi được thứ khách thấy. Bài đang công khai mà người sửa không đổi được thứ khách thấy → mọi lần lưu thành MỘT thay đổi chờ (repo `entry_drafts`, backend đã dựng — không gắn Chờ backend cho luồng). Thêm hộp rời trang 3 nút, bản tạm trên máy cho bài công khai, dải "đang sửa" + hộp "Bài vừa được … lưu". `Hộp duyệt` có thẻ "Thay đổi của bài đang đăng" + So sánh · Duyệt đăng · Trả lại (Chờ backend). Gộp luồng phụ D2-1: README D2-1 › "Gộp vào tệp chung" dòng cuối là "Xong." (không phải "Xong — chờ gộp") → bỏ qua.

### File
- `Page Editor - Options.dc.html` — logic `chg*` (thay đổi chờ), `stale*` (người khác lưu trước), `local*` (bản tạm trên máy), `leave*` (rời trang), hộp thoại chung `dlg`, 3 dải `.pe-chg`, 2 ô Tweak ở thanh demo ("Lỗi thay đổi", "Người khác"); sửa kèm: thanh thao tác dính không còn đẩy nút chính xuống hàng 2; hộp Trợ lý viết trượt đáy dưới 600px; "Nhờ AI viết N chỗ" → "Nâng cấp trang / Nâng cấp bài viết (N chỗ)"; thân bài vào kho (`bodyText`).
- `Hộp duyệt.dc.html` — chỉ thẻ thay đổi + hộp So sánh / Duyệt đăng / Trả lại (lớp `hd-*`), prop `showNotes`.
- `content-workflow.js` (chỉ THÊM): `BSCW.drafts` (get · put · del · list · person), `BSCW.CHANGE_ERR` (9 câu nguyên văn), `BSCW.notices` (SAMPLES "Có thay đổi chờ cập nhật" + push).
- `Trình soạn nội dung.dc.html` — không sửa (audit 0 lỗi hiển thị, xem Kiểm hiển thị).
- `SaaS Starter - Bản đồ màn hình.md` (dòng 10–11, 18–19) · `TODO.md` · `LESSONS.md` (4 dòng) · đo: `_audit/tmp/d14d-lay.html`, ảnh `_audit/tmp/*d14d-*.jpg`.

### Mức hoàn thiện
Prototype tương tác đủ để build. Đã chạy thật 3 luồng (a)(b)(c) + hẹn giờ bị từ chối + hộp người khác lưu + bản tạm sau tải lại + rời trang trên Chromium (ảnh `_audit/tmp/0?-d14d-{b,c,e,f,hd}.jpg`).

### Màn đang chạy · Bản mới · Giữ bản nào · Vì sao
| Màn đang chạy | Bản mới | Giữ | Vì sao |
|---|---|---|---|
| Bài đang công khai: "Cập nhật" lưu thẳng lên website với mọi nhóm, kể cả khi bật duyệt (`Entries/Editor.php` save) | (a) giữ "Cập nhật" cho người có quyền Đăng khi TẮT duyệt; (b) "Lưu thay đổi"; (c) "Gửi duyệt thay đổi" | Bản mới | Quy trình duyệt bị lách ở bản đang chạy |
| Tự lưu nháp mọi bài | Tự lưu chỉ cho bài chưa công khai; bài công khai giữ bản tạm trên máy | Bản mới | Tự lưu bài công khai = khách thấy chữ đang gõ dở |
| Nút chính, ⋯, Xem trước, thẻ Đăng, Lịch sử phiên bản | Giữ nguyên vị trí, chỉ đổi nhãn nút chính theo trường hợp | Giữ bản đang chạy | Luật (e): không dời điều khiển có sẵn |
| Hộp rời trang của Shell "Ở lại · Bỏ thay đổi · Lưu & tiếp tục" | Khuôn đứng riêng: "Ở lại · Thoát không lưu · Lưu & thoát" | Cả hai (chưa sửa Shell) | Không được sửa Shell lượt này — TODO lượt Shell kế |

### Màn & trạng thái
- (a) TẮT duyệt + có quyền Đăng (Chủ website · Quản trị · Biên tập): nút "Cập nhật" → khách thấy ngay, toast "Đã cập nhật — khách thấy ngay. Bản trước nằm trong Lịch sử phiên bản."; lịch sử ghi bản mới (`revRecord` theo `saveSeq`).
- (b) TẮT duyệt + không có quyền Đăng (Người viết · Cộng tác viên): sửa → dải "Bạn đang sửa bài đang đăng — khách vẫn thấy bản cũ" (Xem trước · So sánh); nút "Lưu thay đổi" → dải "Thay đổi của bạn đang chờ người có quyền Đăng cập nhật lên website" (Xem trước · Bỏ thay đổi). Người có quyền Đăng mở bài → dải "Có thay đổi chờ cập nhật (Chị Lan, 10:32)" với Xem trước · So sánh · Cập nhật website · Bỏ thay đổi; sửa tiếp thì gộp vào thay đổi; nút chính thành "Cập nhật website" → hộp "Cập nhật website?" ghi "Sẽ lên website: Tiếng Việt, English" (+ "Bản 日本語 đang là nháp — cũng lên website cùng lần này" khi có).
- (c) BẬT duyệt: nút "Gửi duyệt thay đổi" → dải "Bài có thay đổi đang chờ duyệt (…)"; ô nhập + thân soạn khóa (`bodyReadOnly`, `upd` từ chối kèm câu `change_locked`); "Bỏ thay đổi" khóa + lý do `change_pending` đọc được (không chỉ tooltip). Người có quyền duyệt thấy thêm So sánh · Duyệt đăng · Trả lại (ghi chú bắt buộc). Trả lại → dải vàng "Người duyệt trả lại thay đổi (…) Ghi chú: “…”", sửa tiếp được. Chưa gửi mà bấm ⋯ › Lưu ngay → "Bài có thay đổi chưa gửi duyệt".
- Gỡ khỏi website / đưa về nháp khi còn thay đổi chờ → thay đổi gộp vào bài, toast "Thay đổi chờ đã gộp vào bài — không mất chữ nào."
- Hẹn giờ bài đang công khai khi còn thay đổi chờ → hộp "Chưa hẹn giờ được — Bài đang có thay đổi chờ. Hãy Cập nhật website hoặc Bỏ thay đổi trước."
- 9 lý do từ chối (Tweak "Lỗi thay đổi"): hộp lỗi dùng NGUYÊN VĂN `lang/vi.json:763–771` (`BSCW.CHANGE_ERR`), không hiện mã; `change_stale` có nút "Tải lại".
- Rời trang khi chưa lưu (đứng riêng: bấm breadcrumb loại nội dung): hộp "Thay đổi chưa được lưu" — Ở lại (focus) · Thoát không lưu · Lưu & thoát. Nhúng: Shell `navGuard` hỏi, "Lưu" gửi `bs-embed-cmd {act:'save'}` → `save()` đi đúng nhánh (a)/(b)/(c).
- Bài công khai: trạng thái lưu "Chưa lưu — giữ tạm trên máy này, chưa lên website"; mở lại → dải "Có thay đổi chưa lưu từ 10:42 — Khôi phục / Bỏ".
- Hai người cùng sửa (Tweak "Người khác"): "Đang sửa cùng lúc" → dải "Anh Nam đang sửa bài này (từ 10:42)"; "Vừa lưu trước bạn" → lần lưu đầu mở hộp "Bài vừa được Anh Nam lưu lúc 10:45" — Xem khác biệt · Lấy bản mới (bản của mình giữ tạm trên máy, Khôi phục được) · Giữ bản của tôi.
- Hộp duyệt: thẻ "Thay đổi của bài đang đăng" (chip riêng, khác bài mới) — mỗi dòng: tiêu đề · loại · người sửa · giờ · số chỗ sửa · ngôn ngữ; So sánh (toàn màn dưới 600px) · Trả lại (ghi chú bắt buộc) · Duyệt đăng ("Sẽ lên website: …"). Nhóm không có quyền duyệt: "Cần quyền duyệt đăng để xử lý thay đổi này." Không có thay đổi: thẻ ẩn.
- Trống / đang tải / lỗi / không có quyền / khóa theo gói: do khung sẵn có (D1-4b) — thay đổi chờ không có trạng thái gói riêng.

### Tương tác
Esc đóng hộp thoại chung (trước hộp lịch sử); mở hộp → focus nút an toàn (Hủy / Ở lại / Đóng / ô ghi chú), Tab vòng trong hộp (`revTrap` dùng lại), đóng → trả focus. Dải có `role="status"` + `aria-live`. Dưới 600px: nút trong dải rộng đều, hộp xác nhận trượt đáy, hộp So sánh toàn màn.

### Ánh xạ dữ liệu
- `entry_drafts` (docs/design.md v2.32): `Cms\Actions\SaveEntryDraft.php:72` (`handle(entry, actor, locale, text, shared)`; khóa → `change_locked` :97) · `ApplyEntryDraftTransition.php:61` (duyệt / trả lại; `change_stale` :88) · `ApplyEntryDraft.php:50` (`seenVersion`; `change_stale` :73) · `DiscardEntryDraft.php:34` (`change_stale` :46 · `change_pending` :50) · `Models/EntryDraft.php:60` `version()` · `Exceptions/EntryDraftRejectedException.php:27` REASONS → `entries.change_err_*` (`lang/vi.json:763–771`).
- Thông báo: `EntryNotifier` gắn `change => true` trên 4 loại thông báo quy trình (câu `.change`) — prototype: `BSCW.notices`.
- Prototype: `BSCW.drafts` khóa = loại + mã công khai / đường dẫn; `state` open · submitted · returned; giữ `data` (bản mới) + `live` (bản khách đang thấy) để So sánh.

### Quyền & gói
Đọc `BSCW.can` + chế độ duyệt: `entries.publish` → (a)/(b-người đăng); không có → (b-người viết); BẬT duyệt → (c) cho mọi nhóm, Duyệt đăng · Trả lại chỉ với `entries.approve`. Chỉ xem: không có nút lưu (blocker cũ). Không phụ thuộc gói.

### Token
`--color-surface|heading|body-text|body-muted|border|border-input|accent|admin-primary|admin-secondary-text|admin-warning-text|admin-danger-text|admin-danger-solid|surface-hover`. 0 hex mới ngoài `var(…, dự phòng)`; chữ trắng trên nền đặc giữ `#fff` như nút chính sẵn có.

### Icon (Remix, mới)
ri-git-pull-request-line · ri-git-commit-line · ri-git-merge-line · ri-global-line · ri-user-shared-line · ri-hard-drive-2-line · ri-reply-line · ri-check-double-line · ri-logout-box-r-line · ri-arrow-go-back-line · ri-time-line.

### Chuỗi cần dịch (mới)
"Lưu thay đổi" · "Gửi duyệt thay đổi" · "Cập nhật website" · "Bỏ thay đổi" · "So sánh" · "Duyệt đăng" · "Trả lại" · "Thay đổi của bạn đang chờ người có quyền Đăng cập nhật lên website" · "Có thay đổi chờ cập nhật (<người>, <giờ>)" · "Bài có thay đổi đang chờ duyệt (…)" · "Bài có thay đổi chưa gửi duyệt (…)" · "Người duyệt trả lại thay đổi (…)" · "Bạn đang sửa bài đang đăng — khách vẫn thấy bản cũ" · "Sẽ lên website: …" · "Chưa hẹn giờ được" · "Thay đổi chưa được lưu" · "Ở lại" · "Thoát không lưu" · "Lưu & thoát" · "Có thay đổi chưa lưu từ <giờ>" · "Khôi phục" · "Bỏ" · "<người> đang sửa bài này (từ <giờ>)" · "Bài vừa được <người> lưu lúc <giờ>" · "Xem khác biệt" · "Lấy bản mới" · "Giữ bản của tôi" · "Thay đổi của bài đang đăng" · "Cần quyền duyệt đăng để xử lý thay đổi này." (+ 9 câu `entries.change_err_*` đã có trong lang).

### Kết quả 12 tiêu chí dễ dùng
1. Đạt — dải nói ngay ai làm gì tiếp; 1 nút chính đổi nhãn theo trường hợp.
2. Đạt — (b) sửa → Lưu thay đổi (2 bước); người đăng: Cập nhật website → xác nhận (2 bước).
3. Đạt — không thuật ngữ; "thay đổi chờ", "khách vẫn thấy bản cũ".
4. Đạt — mọi nút trong dải có chữ + icon.
5. Đạt — ghi chú Trả lại có ví dụ + lỗi nói cách sửa.
6. Đạt — không mất việc: gộp khi gỡ, bản tạm trên máy, "Lấy bản mới" vẫn giữ bản của mình; hộp nêu hệ quả bằng số ("Bỏ 3 chỗ sửa …").
7. Đạt — dải có màu + icon + chữ; khóa có icon khóa + câu lý do.
8. Đạt — nút `white-space:nowrap`, dải xuống dòng ở phần chữ.
9. Đạt [ĐO BẰNG SCRIPT] 360/390: 0 tràn; nút ≥ 44px ở `pointer:coarse` (`.pe-b` có sẵn).
10. Đạt — Tweak lỗi / người khác chỉ ở thanh demo.
11. Đạt [ĐO BẰNG SCRIPT] Chữ lớn (zoom 1.143) 0 tràn.
12. Đạt — Hủy, Xóa, "Bỏ thay đổi", "Trả lại", "Duyệt đăng" (đúng nhãn BSCW).

### Kết quả Chất lượng hoàn thiện (A–E)
- A. Đạt — thang 4/8, 1 nút chính mỗi vùng (nút trong dải là `soft`).
- B. Đạt — chữ dải 13,5px / giãn 1,5; phản hồi "Đang lưu…" + chặn bấm 2 lần (`saving`).
- C. Đạt theo mã — hộp có tên (`aria-labelledby`), Esc, focus vào/trả về, Tab vòng; `role="status"` cho dải. [CHƯA KIỂM ĐƯỢC] trình đọc màn hình thật.
- D. Một phần — chỉ token; tương phản tối [CHƯA KIỂM ĐƯỢC] (chưa chạy harness tối).
- E. (1) tương phản [CHƯA KIỂM ĐƯỢC]; (2) vùng bấm 390 [SUY RA TỪ CODE]; (3) Esc/Tab/trả focus [ĐÃ RENDER VÀ THẤY] focus vào "Hủy"/"Ở lại"/"Xem khác biệt"; (4) console: 0 lỗi khi chạy các luồng trên (Chromium); kiểm cú pháp lớp logic 2 tệp bằng `new Function` → ok.

### Kiểm hiển thị
[ĐO BẰNG SCRIPT] `_audit/tmp/d14d-lay.html` (iframe đúng bề rộng; Chữ lớn = `zoom:1.143` như Shell — không dùng `H.layouts` vì phải bật Chữ lớn trong iframe mà không ghi `bs-ui` của người dùng). Page Editor đo ở trạng thái nặng nhất: dải "Có thay đổi chờ cập nhật" 4 nút + dải "Anh Nam đang sửa", tiêu đề dài 58 ký tự.

| Tệp | 360 | 390 | 768 | 1024 | 1440 | Chữ lớn (cả 5) |
|---|---|---|---|---|---|---|
| Page Editor - Options | 345=345 | 375=375 | 753=753 | 1009=1009 | 1425=1425 | 0 tràn |
| Trình soạn nội dung | 360=360 | 390=390 | 768=768 | 1024=1024 | 1440=1440 | 0 tràn |
| Hộp duyệt (thẻ thay đổi hiện) | 345=345 | 375=375 | 768=768 | 1009=1009 | 1425=1425 | 0 tràn (768: 753=753) |

Sửa trong lượt: nút chính "Cập nhật" / "Gửi duyệt thay đổi" rơi xuống hàng 2 và đè dải bên dưới ở ~900px (cụm nút bị bóp) → cụm nút `flex:1 1 auto`; hộp So sánh có thanh cuộn ngang → `overflow-x:hidden` cho thân hộp (`.rv-b`, `.hd-db`). Trình soạn nội dung: 0 lỗi hiển thị nên không sửa. Chrome/Edge: đạt (Chromium). Safari iPhone/iPad, Firefox, Samsung Internet, xoay ngang 844×390, 200%, chế độ tối: [CHƯA KIỂM ĐƯỢC] — không có trình duyệt đó ở môi trường thiết kế, Claude Code kiểm bằng zip.

### Việc cho backend
- Nối màn với `SaveEntryDraft` / `ApplyEntryDraftTransition` / `ApplyEntryDraft` / `DiscardEntryDraft` (đã dựng): `Editor.php` save khi bài published → nếu không có quyền Đăng hoặc loại bật duyệt thì gọi `SaveEntryDraft` thay vì ghi thẳng; gửi `seenVersion` (`EntryDraft::version()`) với mọi lần áp / bỏ.
- Gỡ / đưa về nháp khi còn thay đổi chờ: gộp `entry_drafts` vào bài trong cùng giao dịch (không mất chữ). Hẹn giờ bài published khi còn thay đổi chờ: từ chối phía máy chủ.
- Hộp duyệt (Chờ backend): `ReviewInbox.php` liệt kê `entry_drafts` state submitted thành loại dòng riêng + so sánh theo trường × ngôn ngữ; Duyệt đăng / Trả lại (ghi chú bắt buộc) qua `ApplyEntryDraftTransition`.
- Hai người cùng sửa (Chờ backend): kiểm phiên bản khi lưu bài (`entries.updated_at` hoặc cột `lock_version`) → trả "bài vừa được <người> lưu lúc <giờ>" kèm bản mới; báo người đang sửa (nhịp 30–60 giây, theo bài, hết hạn khi rời màn).
- Tự lưu nháp cho bài chưa công khai (Chờ backend, từ D1-4b); bài công khai KHÔNG tự lưu lên máy chủ — bản tạm chỉ ở trình duyệt.
- Port khối "Trạng thái duyệt" đã build (`app/Modules/Admin/resources/views/livewire/entries/editor.blade.php`): khác biệt so với thiết kế — (1) nhãn nút chính theo 3 trường hợp (Cập nhật · Lưu thay đổi · Gửi duyệt thay đổi · Cập nhật website) thay cho một nút "Cập nhật"; (2) thêm dải thay đổi chờ dưới thanh thao tác (không nằm trong thẻ Đăng) với các nút Xem trước · So sánh · Cập nhật website / Duyệt đăng · Trả lại · Bỏ thay đổi; (3) khi thay đổi đã gửi duyệt: khóa ô nhập + thân soạn, ẩn nút chính, câu `change_locked` thay chỗ nút; (4) "Bỏ thay đổi" khóa + câu `change_pending` hiển thị (không chỉ tooltip); (5) hộp xác nhận áp thay đổi liệt kê ngôn ngữ sẽ lên website, kể cả bản đang nháp.

### Verifier
Gọi ready_for_verification 07/10 (D1-4d) — kết quả ghi bên dưới nếu có báo lỗi.
