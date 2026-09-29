# Việc đang dở — module Bài viết & Page Editor

Ghi lại để lượt sau làm tiếp (chưa bắt đầu sửa file nào — mọi file vẫn nguyên trạng).

Đọc trước: `CLAUDE.md`, `LESSONS.md`.
Ba file liên quan: `Trình soạn nội dung.dc.html` (trình soạn dùng chung, đã hoàn thiện),
`Quản lý Bài viết - Options.dc.html` (đã dùng trình soạn chung),
`Page Editor - Options.dc.html` (vẫn còn trình soạn riêng — bản đã được người dùng test, chạy đúng).

## 1. Page Editor dùng chung trình soạn
Thay toàn bộ trình soạn riêng bằng:
`<dc-import name="Trình soạn nội dung" value on-change words-left words-limit can-ai read-only on-spend on-notify on-pick-image hint-size="100%,520px"></dc-import>`
Xoá hẳn: thanh `.pe-tb`, vùng `#pe-rte`, hộp thoại video, các hàm `rteCmd` / `rteFixLists` /
`rteIndentBlock` / `rteQuote` / `rteClear` / `rteVideo` / `vidInsert`… **Không** ẩn bằng `display:none`.
Khi hai bản lệch nhau → lấy **hợp** các tính năng tốt của cả hai, không bỏ phần hay của bản nào.

## 2. Rà 1‑1 từng nút của trình soạn chung (test thật, không đoán)
20 nút: đậm/nghiêng/gạch chân/gạch ngang · X²/X₂ · Kiểu (Đoạn văn, H2–H5, Trích dẫn) ·
danh sách gạch đầu dòng và đánh số (bấm lần 2 phải gỡ được) · thụt lề vào/ra · căn lề 4 kiểu ·
liên kết + bỏ liên kết · xoá định dạng · bảng · đường kẻ ngang · chèn ảnh (qua MediaPicker, chọn
nhiều tệp) · chèn video (đủ tuỳ chọn, bấm thẻ để sửa, Delete để xoá) · Ω · màu chữ + màu nền ·
cỡ chữ · xem/sửa HTML · toàn màn hình · hoàn tác/làm lại · thanh đường dẫn thẻ (body › ul › li).
Mỗi nút xác nhận: HTML sinh ra hợp lệ (khối không lồng trong `<b>` hay `<ul>`; khối ảnh/video/bảng/hr
luôn là **con trực tiếp** của vùng soạn), con trỏ không nhảy chỗ, Ctrl+Z trả lại đúng bước.

## 3. Vùng cuộn thẻ Chuyên mục
`.cat-list` đang `max-height:264px`. Tạm nhân `CAT_TREE` lên ~40 mục để xác nhận: thanh cuộn mỏng
hiện đúng, ô tìm và ghi chú sao vàng nằm **ngoài** vùng cuộn, thụt lề theo cấp không vỡ.
Xong **trả `CAT_TREE` về 9 mục**.

## 4. Audit Quản lý Bài viết + Thêm/Sửa bài viết
3 bề rộng 1240 / 768 / 390px × 6 vai trò × 3 chế độ quy trình (tweak `role`, `workflow`).
Đối chiếu CLAUDE.md §0 (gánh việc khó thay khách) · §2 (ngữ cảnh ngôn ngữ) · §7–7b (hai sổ hạn ngạch
chữ/ảnh riêng, so chi phí thực trước khi cho bấm) · §8 (AdminKit) · §15 (AI sửa nội dung đang có).
Báo cáo gap/lỗi kèm đề xuất nâng tầm, rồi sửa những lỗi thật.

## Việc nhỏ
Truyền `d.keyword` (Từ khoá muốn lên top) vào `openAi('title')` và `openAi('seo')` làm nguồn cho AI —
hiện chỉ dùng cho bảng kiểm.

## Ràng buộc phải giữ
- Ô từ khoá **không** phải thẻ meta (Google đã bỏ meta keywords) — chỉ để AI viết tiêu đề/mô tả và kiểm bài.
- Chip hạn ngạch AI ở status bar chỉ nổi màu khi còn ≤20% hoặc hết.
- Khối (ảnh/video/bảng/hr) luôn là con trực tiếp của vùng soạn.

## Bẫy đã mắc — đừng lặp lại
a. DC bị ghi thiếu hẳn lớp logic → mọi `{{ hole }}` rỗng, hàng công cụ không hiện. **Ghi xong luôn grep `renderVals`.**
b. Ẩn code cũ bằng `display:none` thay vì xoá → còn 42 nút chết và hai `contenteditable`.
c. Chèn thêm thay vì thay thế → khối trùng lặp, hộp thoại hiện hai lớp.
d. Đọc biến trước khi khai báo trong `renderVals()` → trắng trang.
e. Bê hàm từ file khác mà bỏ sót hàm phụ thuộc: `restoreRange()` phải có guard
   "range còn sống trong vùng soạn thì để yên", nếu không danh sách và định dạng nhảy chỗ.

Sau mỗi thay đổi: `ready_for_verification`, sửa hết lỗi verifier báo trước khi coi là xong.

---

## ĐÃ KHẢO SÁT (làm tiếp từ đây, khỏi dò lại)

### Hợp đồng prop thật của `Trình soạn nội dung.dc.html`
`value` · `onChange(html)` · `wordsLeft` · `wordsLimit` (mặc định 60000) · `canAi` (false = chặn)
· `readOnly` · `onSpend(n)` · `onNotify(msg, icon)` · `onPickImage()` (không truyền → tự mở MediaPicker nội bộ)
· `placeholder`.
Trong template Page Editor viết kebab: `on-change` `words-left` `words-limit` `can-ai` `read-only`
`on-spend` `on-notify` `on-pick-image`, kèm `hint-size="100%,520px"`.

### Bản đồ cắt trong `Page Editor - Options.dc.html`
- **CSS (helmet), dòng ~30–61**: `.pe-rte` + mọi biến thể con (`h2 h3 h4 h5 p ul ol li blockquote a img
  table td th hr`, `:empty:before`), `.pe-embed`, `.pe-video*`, `.pe-tb`, `.pe-tb-txt`, `.pe-tb-sep`.
  → xoá phần thanh công cụ (`.pe-tb*`) và khối video; **giữ lại** phần typography `.pe-rte h2/h3/h4/h5…`
  nếu trình soạn chung chưa có thang chữ đẹp bằng (h2 26px/800 `#1b2a4a`, blockquote viền `#405189`).
- **Template, dòng ~284–320**: cả `<div>` thanh công cụ (menu Kiểu + `paraMenuOpen`, các nút
  `rte.bold/italic/underline/ul/ol/link/unlink/clear/undo/redo`, nút AI `aiToolOn`/`onAiKeep`/`onAiRewrite`,
  nhánh `aiToolOff`) → thay bằng **một** `dc-import`.
- **Quan trọng**: thanh công cụ Page Editor chỉ có **~15 nút**, là **tập con** của trình soạn chung (20 nút —
  thiếu X²/X₂, H4/H5 đầy đủ, thụt lề, 4 căn lề, bảng, hr, Ω, màu chữ/nền, cỡ chữ, xem HTML, toàn màn hình,
  thanh đường dẫn thẻ). ⇒ Hợp nhất = **giữ trình soạn chung**, chỉ mang sang những gì Page Editor có
  mà bản chung thiếu: thang typography `.pe-rte`, và nút AI viết lại đã map sẵn vào AI nội bộ của bản chung
  (`canAi` + bôi đen → popup). Không có tính năng nào của Page Editor bị mất.

### Đã sửa trong lượt này
`Quản lý Bài viết - Options.dc.html` → `mockOpts()`: nhánh `seo` giờ dựng tiêu đề + mô tả từ `d.keyword`
(biến `kt`/`kl`), trước đó chỉ dùng `d.title`. Nhánh `title` vốn đã dùng `k`.
