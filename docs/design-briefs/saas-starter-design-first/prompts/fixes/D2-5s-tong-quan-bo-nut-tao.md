# D2-5s · Tổng quan — bỏ nút "Viết bài mới" và mọi nút tạo tự thêm (dán vào chat D2-5)

Sửa thêm trên bản Tổng quan đang làm, trong cùng chat. Nếu đã xuất zip thì sửa rồi xuất lại.

Owner xem màn Tổng quan và nhận xét: nút "Viết bài mới" ở đầu trang "không nên thêm tuỳ tiện, đặc biệt như trang màn hình Tổng quan này".

Lỗi gốc ở prompt, không phải lỗi của chat. Tiêu chí chung số 1 từng ghi "đúng 1 nút chính nổi bật", đọc ra thành màn nào cũng phải có nút chính. Từ nay tiêu chí đó là: tối đa 1 nút chính, CHỈ khi prompt ghi tên nút đó; màn tổng quan không có nút chính ở đầu trang; không tự thêm nút tạo.

Các phương án đã cân nhắc:
- giữ nút cho Người viết;
- chuyển nút vào khối Người viết.

Cả hai bị loại: vẫn là thêm nút tạo trên Tổng quan, và "Viết bài mới" không nói viết bài loại nào khi website có Tin tức, Dịch vụ, Dự án. Việc cần làm trên Tổng quan đã có các dòng "Việc cần làm", mỗi dòng 1 nút đi thẳng tới chỗ làm.

Chỉ sửa `Tổng quan.dc.html`, `README - D2-5 Tổng quan.md`, `TODO.md`, `LESSONS.md`. Riêng mục 2 được sửa thêm `Quản lý Bài viết - Options.dc.html`, nhưng chỉ chỗ nhận bộ lọc người viết khi mở màn.

1. **Bỏ nút "Viết bài mới" ở đầu trang**, với MỌI nhóm quyền. Đầu trang chỉ còn tiêu đề "Tổng quan" + 1 dòng mô tả + dải "Chờ backend" về số mẫu.
2. **Khối Người viết:** bỏ "Viết bài mới". Thay bằng liên kết chữ "Xem bài của tôi". Liên kết mở danh sách bài, nơi đã có nút "Thêm …", với bộ lọc "Người viết: <tên mình>" đã bật sẵn:
   - gửi `bs-goto` với `params.author`;
   - danh sách bài (`Quản lý Bài viết - Options.dc.html`) đã có bộ lọc "Người viết" (`authorF`) nhưng chưa đọc `params` khi mở màn. Thêm đúng chỗ đó: có `params.author` thì bật sẵn bộ lọc và hiện chip "Người viết: …" để bỏ được. Màn đang chạy cũng lọc được qua `?author=` (`Entries/Index.php`, thuộc tính `creatorF`);
   - không bật được bộ lọc thì KHÔNG dùng nhãn "Xem bài của tôi": ghi "Xem tất cả bài" và đưa vào mục "Cần owner chốt".
3. **Câu khi chưa có bài:** 2 câu đang bảo người dùng "Bấm "Viết bài mới" ở đầu trang…" (khối Người viết và khối nội dung mới):
   - khối Người viết: "Bạn chưa có bài nào. Vào Bài viết để viết bài đầu tiên." + liên kết "Mở Bài viết";
   - khối nội dung mới, với người có quyền viết: "Chưa có nội dung nào. Vào Bài viết để bắt đầu." Người không có quyền viết giữ câu "Website chưa đăng nội dung nào."
4. **Rà cả màn:** không còn nút tạo nào ("Thêm…", "Tạo…", "Viết…", "Tải lên…") ngoài các nút đi thẳng tới đúng chỗ làm của từng dòng "Việc cần làm" và của danh sách "Bắt đầu". Hai loại nút đó giữ nguyên.
5. **README:**
   - thêm 1 dòng vào LESSONS: "Không tự thêm nút chính / nút tạo mà prompt không ghi; Tổng quan không có nút chính (owner 07/10)";
   - mục "Cần owner chốt": "Có cần lối tắt tạo bài trên Tổng quan không — nếu có, tạo bài loại nào".

Xong khi:
(1) Tweak cả 6 nhóm quyền × (không duyệt / duyệt trước khi đăng): đầu trang 0 nút; cả màn 0 nút tạo, chỉ còn nút đi tới việc của "Việc cần làm" và "Bắt đầu"; Người viết có liên kết "Xem bài của tôi"; bấm vào mở danh sách bài có sẵn chip "Người viết: …" (trong Shell).
(2) Grep `Tổng quan.dc.html`: 0 "Viết bài mới".
(3) `_audit/harness.html` ở 320 / 360 / 390 / 768 / 1024 / 1440 × cỡ chữ Vừa và Rất lớn, đứng riêng và trong Shell: `scrollWidth = clientWidth`; mở lại tệp và Admin Shell: console 0 lỗi. Xóa sạch `_audit/tmp/` trước khi xuất zip.

Xuất zip project gửi Claude Code kiểm. Xong thì báo owner đúng một câu: "D2-5s xong — gửi zip cho Claude Code."
