# D1-6s · Thư viện ảnh & tệp — làm nốt phần còn thiếu sau bản sửa 01:06 (dán vào chat D1-6)

Sửa thêm trên bản D1-6 đã xuất lúc 01:06 (bản đã sửa theo nhận xét owner), trong cùng chat. Sửa xong thì xuất zip lại.

Claude Code đã dựng bản 01:06 bằng Chromium ở khổ 1280 và 390, so với màn đang chạy (`app/Modules/Admin/resources/views/livewire/media/library.blade.php` + `partials/media-picker-*.blade.php`).

**Các phần sau ĐÃ ĐẠT. Giữ nguyên, không làm lại, không đổi chỗ:**
- 2 tab "Tải lên tệp mới" · "Thư viện ảnh"; không còn nút tải ảnh ở góc phải.
- Tab "Tải lên tệp mới" có đủ nguồn như màn đang chạy:
  - Thiết bị của tôi, Máy ảnh;
  - Dropbox, Google Drive, Google Photos, OneDrive, Unsplash, Box;
  - dán địa chỉ web rồi bấm "Xem trước".
- 3 ô chọn Sắp xếp · Loại tệp · Định dạng nằm cạnh ô tìm.
  - Ở 390 vẫn là 3 ô riêng xếp 2 hàng, không tràn. GIỮ đúng như vậy, KHÔNG gom vào nút "Lọc", vì owner muốn thấy đủ 3 ô.
  - Câu "chỉ gom trên điện thoại" ở bản D1-6s trước đã bỏ.
- Panel Chi tiết chỉ có 1 thanh cuộn ở phần giữa, và trang phía sau bị khóa cuộn.
- Ô "Địa chỉ tệp" ra đúng `https://files.<tên miền>/…`.
- "Di chuyển sang thư mục…" có đích "Chưa phân loại". Việc này thay cho "Đưa ra ngoài thư mục" của repo.
- Phân quyền: `media.view` chỉ xem; `media.upload` mới có tab tải lên; `media.delete` mới có ô chọn để xóa và "⋯" của thư mục.
- Các phần khác của bản mới:
  - thanh dung lượng + "Dọn Thùng rác";
  - cột thư mục có số đếm;
  - nhãn "Dùng ở N nơi" / "Chưa dùng";
  - "Đã chọn N";
  - Lưới / Danh sách;
  - mô tả ảnh theo từng ngôn ngữ.

Chỉ sửa `Thư viện ảnh & tệp.dc.html`, `README - D1-6 Thư viện ảnh & tệp.md`, `TODO.md`, `LESSONS.md`.

1. **Đóng panel Chi tiết thì trở về đúng chỗ cũ.**
   - Đo bản 01:06, đứng riêng:
     - trang đang cuộn ở 400px, mở Chi tiết thì trang nhảy về đầu (0);
     - đóng bằng Esc thì trang vẫn ở 0;
     - focus về vùng nội dung chính, không về ảnh vừa mở.
   - Cần sửa thành:
     - mở panel thì trang phía sau đứng yên tại chỗ;
     - đóng bằng ×, "Đóng", Esc hay bấm nền mờ thì trang ở đúng vị trí cũ và focus về đúng nút của ảnh vừa mở.
   - Trong Shell (`#/site/media`), vùng cuộn là khung nội dung của Shell, không phải cả trang. Khóa và trả lại đúng vùng đó.
2. **Phân trang.** Bản 01:06 vẽ mọi ảnh trong một lưới, không có chân trang, trong khi 1 website có thể có hàng nghìn ảnh.
   - Thêm chân lưới theo Mẫu danh sách: "Ảnh / trang 24 · 48 · 96" + số trang. Repo hiện chỉ có Trước / Sau.
   - Thêm Tweak "Nhiều ảnh" (ví dụ 130 ảnh) để thấy chân trang. Với 21 ảnh mẫu thì chỉ có 1 trang.
   - Đổi thư mục, ô tìm, ô chọn hay số ảnh / trang thì về trang 1.
   - Ảnh đã chọn vẫn giữ khi chuyển trang; thanh "Đã chọn N" đếm cả các trang.
   - Chân trang dùng được cả ở chế độ Danh sách.
3. **Chế độ Danh sách: thêm cột "Loại".**
   - Bản 01:06 có Tên tệp · Kích thước · Dung lượng · Nơi dùng · Ngày tải, nhưng thiếu cột Loại mà repo đang có (`media.picker_col_type`).
   - Thêm cột Loại (JPG, PNG, WEBP, PDF…) ngay sau Tên tệp.
   - Dưới 768px vẫn là thẻ gọn, không thành bảng cuộn ngang.
4. **README:** thêm bảng 4 cột "Màn đang chạy · Bản mới · Giữ bản nào · Vì sao". Các dòng lấy từ danh sách ĐÃ ĐẠT ở trên và 3 mục vừa sửa.

Xong khi:
(1) Đứng riêng và trong Shell: cuộn xuống khoảng 400px rồi mở Chi tiết một ảnh. Đóng bằng Esc, mở lại rồi đóng bằng nút "Đóng". Cả hai lần, vị trí cuộn không đổi và focus về đúng nút của ảnh vừa mở.
(2) Tweak "Nhiều ảnh": chân lưới có "Ảnh / trang 24 · 48 · 96" + số trang. Đổi ô chọn thì về trang 1. Ở 390, chân trang không tràn và vùng bấm ≥ 44px.
(3) Chế độ Danh sách có cột Loại.
(4) Mọi phần trong danh sách ĐÃ ĐẠT vẫn còn y như bản 01:06. Ở 390 vẫn đủ 3 ô chọn riêng, không có nút "Lọc".
(5) `_audit/harness.html` ở 360 / 390 / 768 / 1024 / 1440, chữ thường và Chữ lớn, đứng riêng và trong Shell (`#/site/media`), đạt cả 3 điều:
   - `scrollWidth = clientWidth`;
   - tương phản ≥ 4,5:1 cả sáng lẫn tối;
   - mở lại tệp và Admin Shell: console 0 lỗi.
   Tệp đo tạm chỉ đặt trong `_audit/tmp/`, xóa sạch trước khi xuất zip.

Xuất zip project gửi Claude Code kiểm. Xong thì báo owner đúng một câu: "D1-6s xong — gửi zip cho Claude Code."
