# D2-5s · Tổng quan — bỏ nút "Viết bài mới" và mọi nút tạo tự thêm (dán vào chat D2-5)

Sửa thêm trên bản Tổng quan đang làm, trong cùng chat. Nếu đã xuất zip thì sửa rồi xuất lại.

Owner xem màn Tổng quan và nhận xét: nút "Viết bài mới" ở đầu trang "không nên thêm tuỳ tiện, đặc biệt như trang màn hình Tổng quan này".

Lỗi gốc ở prompt, không phải lỗi của chat. Tiêu chí chung số 1 từng ghi "đúng 1 nút chính nổi bật", đọc ra thành màn nào cũng phải có nút chính. Từ nay tiêu chí đó là: tối đa 1 nút chính, CHỈ khi prompt ghi tên nút đó; màn tổng quan không có nút chính ở đầu trang; không tự thêm nút tạo.

Các phương án đã cân nhắc:
- giữ nút cho Người viết;
- chuyển nút vào khối Người viết.

Cả hai bị loại: vẫn là thêm nút tạo trên Tổng quan, và "Viết bài mới" không nói viết bài loại nào khi website có Tin tức, Dịch vụ, Dự án. Việc cần làm trên Tổng quan đã có các dòng "Việc cần làm", mỗi dòng 1 nút đi thẳng tới chỗ làm.

Chỉ sửa `Tổng quan.dc.html`, `README - D2-5 Tổng quan.md`, `TODO.md`, `LESSONS.md`.

1. **Bỏ nút "Viết bài mới" ở đầu trang**, với MỌI nhóm quyền. Đầu trang chỉ còn tiêu đề "Tổng quan" + 1 dòng mô tả + dải "Chờ backend" về số mẫu.
2. **Khối Người viết:** bỏ "Viết bài mới". Thay bằng liên kết chữ "Xem bài của tôi" (mở danh sách bài đã lọc theo người viết qua `bs-goto`, nơi đã có nút "Thêm …").
3. **Rà cả màn:** không còn nút tạo nào ("Thêm…", "Tạo…", "Viết…", "Tải lên…") ngoài các nút đi thẳng tới đúng chỗ làm của từng dòng "Việc cần làm" và của danh sách "Bắt đầu". Hai loại nút đó giữ nguyên.
4. **README:**
   - thêm 1 dòng vào LESSONS: "Không tự thêm nút chính / nút tạo mà prompt không ghi; Tổng quan không có nút chính (owner 07/10)";
   - mục "Cần owner chốt": "Có cần lối tắt tạo bài trên Tổng quan không — nếu có, tạo bài loại nào".

Xong khi:
(1) Tweak cả 6 nhóm quyền × (không duyệt / duyệt trước khi đăng): đầu trang 0 nút; cả màn 0 nút tạo, chỉ còn nút đi tới việc của "Việc cần làm" và "Bắt đầu"; Người viết có liên kết "Xem bài của tôi".
(2) Grep `Tổng quan.dc.html`: 0 "Viết bài mới".
(3) `_audit/harness.html` ở 360 / 390 / 768 / 1024 / 1440, đứng riêng và trong Shell: `scrollWidth = clientWidth`; mở lại tệp và Admin Shell: console 0 lỗi. Xóa sạch `_audit/tmp/` trước khi xuất zip.

Xuất zip project gửi Claude Code kiểm. Xong thì báo owner đúng một câu: "D2-5s xong — gửi zip cho Claude Code."
