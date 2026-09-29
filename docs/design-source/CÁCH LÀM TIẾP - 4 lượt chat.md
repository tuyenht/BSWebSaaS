# Cách làm tiếp module Bài viết — chia 4 cửa sổ chat

Mỗi cửa sổ chat mới: **copy nguyên văn một khối bên dưới, dán vào, gửi**. Làm theo đúng thứ tự A → B → C → D.
Không gộp nhiều khối vào một cửa sổ (gộp là hết quota giữa chừng, chưa kịp ghi file nào).

Trạng thái hiện tại: **chưa file nào bị sửa**, mọi thứ nguyên trạng.

---

## LƯỢT A — Page Editor dùng chung trình soạn

```
Dự án BSWebSaaS. Đọc CLAUDE.md và LESSONS.md, rồi làm ĐÚNG MỘT việc này, không hỏi lại,
không khảo sát lại (mọi toạ độ đã có sẵn dưới đây — dùng thẳng):

Trong `Page Editor - Options.dc.html`, thay trình soạn riêng bằng trình soạn dùng chung.

1. Template — thay khối từ dòng ~284 đến ~400 (bắt đầu ở
   `<div style="display:flex;align-items:center;gap:2px;flex-wrap:wrap;padding:7px 10px;border-bottom:1px solid #eff2f7;background:#fbfcfd;">`,
   kết thúc ở `</div>` đóng thanh đếm ký tự/từ) bằng:
   - GIỮ NGUYÊN banner bài trắng (`sc-if bodyEmpty` + `onWriteBody` + `writeBodyTip`) đặt ở TRÊN;
   - `<dc-import name="Trình soạn nội dung" value on-change words-left words-limit can-ai
     read-only on-spend on-notify on-pick-image hint-size="100%,520px"></dc-import>`;
   - GIỮ NGUYÊN thanh đếm dưới cùng (`activeLangLabel` + `charCount` + `wordCount`) đặt ở DƯỚI.
   Khối này nằm trong `<sc-if value="{{ proseUnlocked }}">` — giữ nguyên nhánh `proseLocked`.
   Grep `renderVals` lấy tên biến thật cho các prop, đừng đoán tên.

2. CSS trong helmet — XOÁ HẲN `.pe-embed`, `.pe-video*`, `.pe-tb`, `.pe-tb-txt`, `.pe-tb-sep`
   (dòng ~45–61). GIỮ NGUYÊN thang typography `.pe-rte h2/h3/h4/h5/p/ul/ol/li/blockquote/a/img/table/hr`
   (dòng ~30–44) vì bản chung chưa đẹp bằng.

3. Lớp logic — xoá hàm chết: rteCmd, rteFixLists, rteIndentBlock, rteQuote, rteClear, rteVideo, vidInsert.
   Xoá biến chết: onTogglePara, paraArrow, paraMenuOpen, paraItems, rte.*, aiToolOn, onAiKeep, onAiRewrite,
   aiBtnTitle, aiToolOff, aiToolOffTitle, toolbarFull, onToggleSize, sizeArrow, sizeMenuOpen, sizeItems,
   onToggleColor, colorMenuOpen, colorTabTextStyle, colorTabBgStyle, onColorText, onColorBg, colorItems,
   onCustomColor, onOpenMedia, onToggleSource, sourceBtnStyle, onToggleRteFull, rteFullIcon, onToggleToolbar,
   toolbarToggleIcon, toolbarToggleLabel, editorDisp, onContentInput, rtePlaceholder, editorMinH, aiBubbleOn,
   aiBubbleStyle, aiBubbleTitle, aiSelWords, rteSource, sourceHtml, onSourceInput.
   GIỮ: bodyEmpty, onWriteBody, writeBodyTip, activeLangLabel, charCount, wordCount, proseLocked,
   proseUnlocked, sectionCount, onBuilderTab.

Hợp đồng prop của `Trình soạn nội dung.dc.html`: value · onChange(html) · wordsLeft · wordsLimit(60000)
· canAi · readOnly · onSpend(n) · onNotify(msg,icon) · onPickImage() · placeholder.

Bẫy đã mắc, đừng lặp: (a) ghi DC mà thiếu lớp logic → mọi hole rỗng: ghi xong PHẢI grep `renderVals`;
(b) đừng ẩn code cũ bằng `display:none`, phải xoá hẳn: ghi xong grep `display:none` và `pe-tb|rteCmd|vidInsert`;
(c) thay thế chứ không chèn thêm, tránh khối trùng lặp; (d) đừng đọc biến trước khi khai báo trong renderVals.

Xong gọi ready_for_verification và sửa hết lỗi verifier báo.
```

---

## LƯỢT B — Vùng cuộn thẻ Chuyên mục

```
Dự án BSWebSaaS, file `Quản lý Bài viết - Options.dc.html`. Làm đúng một việc, không hỏi lại:

`.cat-list` đang `max-height:264px`. Tạm nhân `CAT_TREE` lên ~40 mục để kiểm tra thật:
- thanh cuộn mỏng hiện đúng;
- ô tìm kiếm và ghi chú sao vàng nằm NGOÀI vùng cuộn (không cuộn theo);
- thụt lề theo cấp không vỡ.
Sửa lỗi nếu có, rồi TRẢ `CAT_TREE` VỀ ĐÚNG 9 MỤC như cũ.

Xong gọi ready_for_verification và sửa hết lỗi verifier báo.
```

---

## LƯỢT C — Rà 1‑1 từng nút của trình soạn chung

```
Dự án BSWebSaaS, file `Trình soạn nội dung.dc.html`. Làm đúng một việc, test thật, không đoán:

Rà 1‑1 đủ 20 nhóm nút: đậm/nghiêng/gạch chân/gạch ngang · X²/X₂ · Kiểu (Đoạn văn, H2–H5, Trích dẫn)
· danh sách gạch đầu dòng và đánh số (bấm lần 2 phải gỡ được) · thụt lề vào/ra · căn lề 4 kiểu
· liên kết + bỏ liên kết · xoá định dạng · bảng · đường kẻ ngang · chèn ảnh (qua MediaPicker, chọn nhiều tệp)
· chèn video (đủ tuỳ chọn, bấm thẻ để sửa, Delete để xoá) · Ω · màu chữ + màu nền · cỡ chữ
· xem/sửa HTML · toàn màn hình · hoàn tác/làm lại · thanh đường dẫn thẻ (body › ul › li).

Mỗi nút phải xác nhận đủ 3 điều:
1. HTML sinh ra hợp lệ — khối không lồng trong `<b>` hay `<ul>`; ảnh/video/bảng/hr luôn là
   CON TRỰC TIẾP của vùng soạn;
2. con trỏ không nhảy chỗ sau thao tác;
3. Ctrl+Z trả lại đúng một bước.

Lưu ý bẫy: `restoreRange()` phải có guard "range còn sống trong vùng soạn thì để yên",
nếu không danh sách và định dạng sẽ nhảy chỗ.

Báo cáo nút nào lỗi rồi sửa. Xong gọi ready_for_verification và sửa hết lỗi verifier báo.
```

---

## LƯỢT D — Audit toàn module

```
Dự án BSWebSaaS. Đọc CLAUDE.md trước. Audit `Quản lý Bài viết - Options.dc.html` và màn Thêm/Sửa bài viết:

Kiểm ở 3 bề rộng 1240 / 768 / 390px × 6 vai trò × 3 chế độ quy trình (tweak `role`, `workflow`).
Đối chiếu CLAUDE.md: §0 gánh việc khó thay khách · §2 ngữ cảnh ngôn ngữ · §7–7b hai sổ hạn ngạch
chữ/ảnh riêng và so chi phí thực trước khi cho bấm · §8 AdminKit · §15 AI sửa nội dung đang có.

Ràng buộc phải giữ:
- ô Từ khoá KHÔNG phải thẻ meta — chỉ để AI viết tiêu đề/mô tả và kiểm bài;
- chip hạn ngạch AI ở status bar chỉ nổi màu khi còn ≤20% hoặc đã hết;
- khối (ảnh/video/bảng/hr) luôn là con trực tiếp của vùng soạn.

Việc nhỏ kèm theo: truyền `d.keyword` vào `openAi('title')` và `openAi('seo')` làm nguồn cho AI
(hiện mới chỉ dùng cho bảng kiểm).

Báo cáo gap/lỗi kèm đề xuất nâng tầm, rồi sửa những lỗi thật.
Xong gọi ready_for_verification và sửa hết lỗi verifier báo.
```
