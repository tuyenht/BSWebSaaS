# D1-1s · Chuyên mục — tìm, lọc và chia tầng hành động theo nhận xét của owner (dán vào chat D1-1)

Sửa thêm trước khi xuất zip. Nếu D1-1 đã xuất zip thì vẫn sửa trong cùng chat rồi xuất lại.

Owner xem `Chuyên mục.dc.html` và chưa ưng ở 3 điểm:
- chưa có tìm kiếm và bộ lọc;
- thao tác hay dùng phải thấy và bấm được ngay;
- chỉ thao tác nâng cao mới vào menu "⋯".

Owner cũng yêu cầu rà kỹ lại toàn màn theo 12 tiêu chí dễ dùng và Chất lượng hoàn thiện A–E trong bối cảnh chung.

Cách sửa:
- SỬA TẠI CHỖ: giữ cây, panel thêm/sửa, luồng xóa, dữ liệu mẫu và các luật as-built đã vẽ.
- Chỉ sửa `Chuyên mục.dc.html`, `README - D1-1 Chuyên mục.md`, `TODO.md`, `LESSONS.md`.

1. Thanh công cụ trên cây luôn hiện, kể cả khi chỉ có 1–3 chuyên mục. Bỏ luật cũ "tìm kiếm chỉ hiện khi > 15 mục". Thanh dùng đúng khuôn của Mẫu danh sách dùng chung, gồm:
   - **Ô tìm** "Tìm theo tên hoặc đường dẫn":
     - Tìm ở mọi ngôn ngữ đang bật và không phân biệt dấu ("khuyen mai" ra "Khuyến mãi").
     - Kết quả giữ dạng cây: mục khớp tô đậm phần chữ khớp; các mục cha hiện mờ làm ngữ cảnh và tự mở ra.
     - Có dòng "Khớp N / M chuyên mục".
     - Không có kết quả thì hiện trạng thái trống "Không có chuyên mục nào khớp "…"" kèm nút "Bỏ tìm".
   - **Nút "Lọc"**: danh sách tick có số đếm, chân "Bỏ lọc · Xong". Có 3 nhóm lọc:
     - "Bản dịch": một dòng "Thiếu bản <ngôn ngữ>" cho mỗi ngôn ngữ đang bật; ẩn cả nhóm khi website chỉ có 1 ngôn ngữ.
     - "Số bài": "Chưa có bài" / "Có bài".
     - "Cấp": 1 / 2 / 3.
   - **Dòng đang lọc**: "Đang lọc: <chip ×> … · Bỏ lọc · Khớp N / M".
   - **Nút "Thu gọn hết" / "Mở hết"** đứng cùng thanh.
   - **Khi đang tìm hoặc lọc**:
     - Tắt kéo-thả (ẩn tay cầm) và hiện 1 dòng "Bỏ tìm hoặc bỏ lọc để kéo-thả sắp xếp".
     - Các nút trong "⋯" vẫn dùng được.
   - **Điện thoại** (khung chứa < 600px):
     - Ô tìm rộng hết hàng.
     - "Lọc" là 1 nút mang số bộ lọc đang bật; bấm vào mở bảng chọn trượt từ đáy.
2. Hành động trên từng dòng chia 2 tầng:
   - **Hiện sẵn**, có chữ chứ không chỉ icon:
     - Bấm tên thì mở panel Sửa.
     - Nút "Sửa".
     - Nút "+ Mục con". Mục đã ở cấp 3 thì nút này khóa, tooltip ghi "Đã ở cấp 3 — tối đa 3 cấp".
     - Số bài là liên kết ("24 bài"), mở danh sách bài đã lọc theo chuyên mục đó qua `bs-goto` (dùng loại nội dung + đường dẫn, không dùng id). Danh sách bài trong repo hiện chưa lọc được theo chuyên mục con (`app/Modules/Admin/Livewire/Entries/Index.php:850` chỉ lọc theo loại nội dung): ghi Việc cho backend, vẫn vẽ liên kết.
     - Tay cầm kéo-thả, dành cho chuột.
   - **Trong menu "⋯"**, đúng thứ tự sau, các nhóm cách nhau bằng vạch ngăn:
     - Xem trên website · Sao chép đường dẫn
     - Chuyển lên · Chuyển xuống · Đưa vào trong… · Đưa ra cấp ngoài
     - Gộp vào chuyên mục khác…
     - Xóa…: luôn đứng cuối, chữ đỏ. Với chuyên mục gốc, mục này khóa và ghi lý do.
   - **Điện thoại**:
     - Mỗi dòng gồm tên + số bài + "⋯"; chạm vào dòng thì mở Sửa.
     - "+ Mục con" chuyển lên đầu menu "⋯".
     - Mọi vùng bấm ≥ 44px.
   - Không thao tác hay dùng nào CHỈ nằm trong "⋯", và không nút nào chỉ hiện khi rê chuột.
3. Chọn nhiều, dành cho người thạo và không làm rối người mới:
   - Nút "Chọn nhiều" trên thanh công cụ bật ô tích ở mỗi dòng và thanh "Đã chọn N" với 2 nút "Chuyển vào…" và "Xóa…".
   - Mỗi nút ghi số dòng nó làm được. Dòng không làm được (chuyên mục gốc, hoặc sẽ vượt 3 cấp) thì bỏ qua và nói lý do.
   - "Xóa…" nhiều mục dùng chung hộp xác nhận, nói tổng hệ quả bằng số.
   - Repo chưa có thao tác hàng loạt: gắn "Chờ backend" và ghi vào Việc cho backend.
4. Bàn phím và trình đọc màn hình:
   - Cây dùng role `tree` / `treeitem` với `aria-level` và `aria-expanded`.
   - Phím: ↑↓ chuyển dòng, → mở, ← đóng, Enter mở Sửa, Alt+↑/↓ đổi vị trí (giống Trình đơn).
   - Đóng panel bằng Esc thì focus trả về đúng dòng vừa sửa.
   - Dưới cây có 1 dòng gợi ý phím tắt.
5. Rà toàn màn theo 12 tiêu chí và A–E, ít nhất các điểm sau:
   - Tên dài cắt bằng "…" kèm tooltip, không đẩy số bài hay nút xuống dòng.
   - Màn có đúng 1 nút chính "Thêm chuyên mục".
   - Thêm thanh công cụ xong, các trạng thái trống, đang tải, lỗi, không có quyền và khóa theo gói vẫn đủ và đúng chỗ.
   - Chế độ tối chỉ dùng token.
   - Đo thật trước khi ghi "đạt".
   - README thêm mục "D1-1s", gồm:
     - bảng hành động 3 cột: hiện sẵn · trong "⋯" · điện thoại;
     - kết quả 12 tiêu chí và A–E kèm số đo.

Xong khi:
(1) Với 3 chuyên mục mẫu, ô tìm và nút "Lọc" vẫn hiện. Tìm "khuyen mai" ra "Khuyến mãi", có cha hiện mờ. Lọc "Chưa có bài" ra đúng số mục, có dòng "Đang lọc" và "Khớp N / M".
(2) Máy tính 1280: mỗi dòng thấy "Sửa", "+ Mục con", "24 bài" và "⋯". Menu "⋯" đúng thứ tự, "Xóa…" đứng cuối màu đỏ. Ở 390: dòng chỉ còn tên + số bài + "⋯", chạm dòng mở Sửa, mọi vùng bấm ≥ 44px.
(3) Bàn phím: Tab vào cây, dùng được ↑↓→←, Enter mở Sửa, Esc đóng panel và focus về đúng dòng.
(4) `_audit/harness.html` ở 360 / 390 / 768 / 1024 / 1440, chữ thường và Chữ lớn, đứng riêng và trong Shell, đạt cả 3 điều:
   - `scrollWidth = clientWidth`;
   - tương phản ≥ 4,5:1 ở cả sáng lẫn tối;
   - mở lại tệp và Admin Shell: console 0 lỗi.
   Tệp đo tạm chỉ đặt trong `_audit/tmp/`.

Xuất zip project gửi Claude Code kiểm. Xong thì báo owner đúng một câu: "D1-1s xong — gửi zip cho Claude Code."
