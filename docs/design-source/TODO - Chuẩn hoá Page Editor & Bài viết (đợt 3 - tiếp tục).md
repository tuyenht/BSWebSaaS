# Trạng thái chuẩn hoá Page Editor ↔ Quản lý Bài viết — tiếp tục ở đây

File liên quan: `Page Editor - Options.dc.html`, `Quản lý Bài viết - Options.dc.html`,
`Trình soạn nội dung.dc.html`. Đọc trước: `CLAUDE.md`, `LESSONS.md`.
Kế hoạch gốc (4 cụm, đã xác định "hợp không giao"): `TODO - Chuẩn hoá Page Editor & Bài viết (đợt 2).md`.

## ĐÃ XONG (đã ready_for_verification, không cần làm lại)
1. Page Editor dùng trình soạn nội dung chung (`Trình soạn nội dung.dc.html`) — xong hẳn.
2. Trình soạn nội dung chung: chuẩn hoá `<p>` (trước bị `<div>` khi Enter vì thiếu
   `defaultParagraphSeparator`), gộp nút AI toolbar tĩnh vào bong bóng nổi khi bôi đen, bỏ đếm
   ký tự/từ trùng lặp (đã có sẵn trong khung soạn) — xong.
3. Cụm 1 (nút AI cạnh Tiêu đề/Mô tả ngắn): Page Editor đổi "AI" mơ hồ → "Gợi ý tiêu đề"/"Viết mô
   tả từ nội dung trang" (icon ✨), đồng bộ Bài viết — xong ở Page Editor.
4. Cụm 4 (Đăng/Trạng thái, Page Editor): đổi dropdown → danh sách trạng thái luôn hiện + banner
   "Cần xong mấy việc này mới đăng được" — xong.
5. Cụm 2 (Ảnh đại diện), CẢ HAI FILE:
   - Page Editor: thêm nút "Tạo N phương án ảnh bằng AI" + sổ hạn ngạch ảnh (`imgUsed`/`imgLeft()`,
     dùng chung `window.BSAI`), thêm chip tỷ lệ + "Thử tỷ lệ khác".
   - Bài viết: port "Gần đây" (4 ảnh mẫu chọn nhanh, `pickRecentImg`) từ Page Editor sang.
   - SỬA LỖI tỷ lệ không thấy tác dụng: khung ảnh giờ đổi HÌNH DẠNG thật theo tỷ lệ (trước chỉ đổi
     chữ chip, khung không đổi) — `imgBoxStyle`/`featThumbStyle` giờ đọc `S.imgRatio` trực tiếp.
   - Bài viết: gọn hoá trạng thái đã-có-ảnh — nút Tải lên/Tạo AI/ghi chú hạn ngạch chỉ hiện khi
     CHƯA có ảnh; khi đã có ảnh thì đổi bằng 3 link nhỏ "Thử tỷ lệ khác · Đổi ảnh · Xoá" cạnh tên
     file (thêm method `removeSharedImg()`).
6. Cụm 3 (SEO), CẢ HAI FILE:
   - Page Editor: thêm ô "Từ khoá muốn lên top" (`d.seoKeyword`, ưu tiên trong `aiSeoKw()`) + bảng
     kiểm "Trang đã sẵn sàng lên Google chưa" (7 mục thật, không bịa).
   - Bài viết: thêm nút gập/mở thẻ (`seoOpen`/`seoChevron`/`onToggleSeo`, mặc định mở) + khối
     "Ảnh chia sẻ (OG image)" lấy theo ảnh đại diện (`ogThumbStyle`).
7. Đã sửa 2 lỗi cú pháp tự gây ra trong lúc làm cụm 2/6 ở Bài viết (dấu `;` lẫn `,` trong
   `imgBoxStyle`, và một `sc-if` chưa đóng) — đã xác nhận qua verifier, hết lỗi console.

## ĐÃ KIỂM, CHƯA CHẮC LÀ LỖI THẬT (cần người dùng xác nhận lại, đừng tự đoán sửa tiếp)
- Nút "Viết tiêu đề & mô tả" (Bài viết, `onAiSeo`) — code có handler thật (`openAi('seo')`), mở
  panel "Phương án AI" ngay dưới field (không tự điền thẳng vào ô — giống cách title/excerpt hoạt
  động). Nếu người dùng bấm mà "không thấy tác dụng", có thể là panel xuất hiện nhưng không để ý,
  hoặc bị chặn bởi kiểm tra hạn ngạch (`left < 45`) chỉ báo qua toast. CẦN: người dùng thử lại, xem
  kỹ có panel hiện dưới ô Tiêu đề/Mô tả không, và có toast báo lỗi không — rồi mới sửa tiếp.
- Cảnh báo "never resolved" ở màn DANH SÁCH bài viết (`r.authorInit`, `r.metaCompact`, `r.cat`,
  `r.views`, `r.statusLabel`, `r.metaText`, `r.author`) — đã grep `rowsD` (dòng ~1546), TẤT CẢ
  trường này ĐÃ có trong object trả về, không thiếu. Nhiều khả năng cảnh báo đó là dư ảnh của lỗi
  cú pháp `;`/`,` (lúc đó sập cả renderVals nên mọi `{{ }}` — cả màn danh sách — đều trống). CẦN:
  tải lại màn danh sách, nếu vẫn trống thì báo lại kèm ảnh chụp cụ thể ô nào trống.

## CHƯA LÀM (người dùng đã nhắc "còn chỗ nào nữa thì kiểm tra kỹ" nhưng chỉ mới xong đúng 4 cụm trên)
- Chưa audit toàn diện NGOÀI 4 cụm (SEO / Ảnh đại diện / nút AI Tiêu đề-Mô tả / Đăng-Trạng thái).
  Có thể còn khác biệt style/trình bày ở: khối "Thuộc tính trang" (Page Editor) so với khối tương
  đương ở Bài viết (chuyên mục/thẻ/tác giả), khối lịch sử phiên bản, khối ngôn ngữ, các modal AI
  (viết lại đoạn, AI Writer Panel) — CHƯA SO SÁNH CẶP NÀO Ở TRÊN, đừng giả định đã ổn.
- Chưa rà 1-1 từng nút của TRÌNH SOẠN NỘI DUNG DÙNG CHUNG (20 nhóm nút: đậm/nghiêng/gạch chân,
  danh sách, thụt lề, căn lề, bảng, hr, chèn ảnh/video, xem HTML, toàn màn hình, undo/redo...) —
  đây là việc còn treo từ trước, xem thêm `TODO - Bài viết & Page Editor.md` mục cũ nếu còn.
- Chưa audit responsive (mobile/tablet) cho các khối mới thêm (checklist SEO, banner blockers,
  danh sách trạng thái, nút Đổi/Xoá ảnh gọn) ở CẢ HAI file.

## Bẫy đã mắc trong đợt này — đừng lặp lại
- KHÔNG str_replace bằng đoạn text bị CẮT NGẮN (do grep hiển thị "..." ở giữa dòng dài) — luôn
  `read_file` lấy dòng ĐẦY ĐỦ trước khi sửa, nhất là các dòng renderVals dài (style string nối
  bằng `+`). Cắt ngắn → dễ tạo biến chết `OLDxxx`/`xxxOLD` hoặc quên đổi `;`→`,`.
- Sau MỖI cụm sửa ở 1 file: `ready_for_verification` ngay, đừng dồn nhiều cụm rồi mới kiểm — lỗi
  cú pháp phát hiện sớm, sửa rẻ hơn.
- `activeIsDefault`/`DEF` khai báo TRỄ trong renderVals của Page Editor — nếu cần biến "đang sửa
  bản gốc" ở đoạn code TRƯỚC chỗ khai báo đó, dùng `S.loc === this.defLang` (thuộc tính class, không
  bị temporal-dead-zone) thay vì đợi đến `const activeIsDefault`.

## Việc nhỏ còn treo từ đợt trước (chưa liên quan cụm 1-4)
- Truyền `d.keyword` (Page Editor) / đã có `fKeyword` (Bài viết) vào các lời gọi AI viết tiêu
  đề/mô tả làm nguồn — Page Editor đã nối `aiSeoKw()` ưu tiên `d.seoKeyword`, NHỚ kiểm lại các chỗ
  gọi AI viết tiêu đề trang/mô tả ngắn (không chỉ SEO) đã dùng đúng `aiSeoKw()` chưa.
