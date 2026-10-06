# D1-6s · Thư viện ảnh & tệp — giữ 2 tab và 3 ô lọc như bản đang chạy, sửa panel Chi tiết (dán vào chat D1-6)

Sửa thêm trên bản D1-6 đã xuất zip, trong cùng chat. Sửa xong thì xuất zip lại. Nếu chat đã sửa một phần theo nhận xét owner thì giữ phần đã đúng, làm nốt phần còn thiếu.

Owner xem màn mới và nhận xét (nguyên văn):
- "Đang để theo dạng tab (Thêm mới và Danh sách) thế này tôi đang thấy hợp lý mà, đừng tách nút upload ra ngoài như kìa làm gì, tôi thấy như thế nó bị rối rắm."
- "Tôi đang có 3 loại như ảnh 2 này, sao lại cắt đi mất 1 mục rồi?"
- "Đang tốt mà chỉnh không tốt hơn mà lại còn kém hơn."
- Panel Chi tiết ảnh hiện nhiều thanh cuộn cùng lúc; "popup chi tiết lên thì phải ẩn / đè cái cuộn ngoài".

Luật từ nay (owner 07/10):
- Màn ĐÃ có thiết kế thì GIỮ bố cục, tab, ô lọc, nút và vị trí đang có; chỗ nào bản mới ưu việt hơn thì cũng GIỮ, sau khi đã so kỹ.
- Muốn bớt hoặc dời một điều khiển có sẵn thì ghi "Cần owner chốt", không tự làm.
- Lỗi gốc nằm ở prompt D1-6 (mục 1 bảo đưa "Tải ảnh lên" thành nút chính), không phải lỗi của chat.

Claude Code đã so từng phần giữa màn đang chạy (`app/Modules/Admin/resources/views/livewire/media/library.blade.php` + `partials/media-picker-*.blade.php`) và bản 00:31:
- **GIỮ vì bản mới tốt hơn:**
  - thanh dung lượng có cảnh báo + "Dọn Thùng rác";
  - cột thư mục có số đếm, "⋯" của từng thư mục (đổi tên · sắp xếp · xóa), "Chưa phân loại", "Thùng rác";
  - ô tìm theo tên HOẶC mô tả;
  - nhãn "Dùng ở N nơi" / "Chưa dùng" ngay trên từng ảnh;
  - ô chọn trên từng ảnh + thanh "Đã chọn N";
  - nút chuyển Lưới / Danh sách;
  - panel Chi tiết có mô tả ảnh theo từng ngôn ngữ + "Dịch sang …", bộ đếm 65/188, người tải lên, nơi đang dùng.
- **TRẢ LẠI vì bản mới làm mất** (mục 1, 2, 5 bên dưới):
  - 2 tab;
  - 3 ô chọn;
  - nguồn tải "Từ địa chỉ web" (dán địa chỉ, xem trước, "Lưu bản sao", chữ `media.picker_url_*`) và "Chụp ảnh" (`media.picker_source_camera`);
  - phân trang;
  - cột Loại / Kích thước / Dung lượng ở chế độ Danh sách;
  - "Đưa ra ngoài thư mục" (`media.picker_move_root`).

Chỉ sửa `Thư viện ảnh & tệp.dc.html`, `README - D1-6 Thư viện ảnh & tệp.md`, `TODO.md`, `LESSONS.md`.

1. **Trả lại 2 tab như màn đang chạy** (`app/Modules/Admin/resources/views/livewire/media/library.blade.php`, chữ `media.picker_tab_upload` "Tải lên tệp mới" · `media.picker_tab_library` "Thư viện ảnh"):
   - Thanh dung lượng "Đã dùng … / …" vẫn ở trên cùng, phía trên 2 tab.
   - Tab "Tải lên tệp mới" chứa vùng kéo thả + nút chọn tệp. Ở điện thoại chỉ có nút, tải xong thì tự sang tab "Thư viện ảnh", có ảnh vừa tải sáng nhẹ.
   - Tab "Thư viện ảnh" chứa cột thư mục + ô tìm + ô lọc + lưới / danh sách.
   - Bỏ nút "Tải ảnh lên" ở góc phải.
   - Kéo tệp vào trang khi đang ở tab "Thư viện ảnh" vẫn tải được, tải vào thư mục đang xem.
   - Tab "Tải lên tệp mới" giữ đủ nguồn như màn đang chạy: Từ máy (kéo thả + chọn tệp) · Chụp ảnh (điện thoại) · Từ địa chỉ web (dán địa chỉ → xem trước → "Lưu bản sao").
   - Quyền: tab "Tải lên tệp mới" chỉ hiện với `media.upload` (repo `@can('media.upload')`). Ô chọn để xóa, "Chuyển vào Thùng rác" và "⋯" thư mục chỉ hiện với `media.delete`. Người chỉ có `media.view` xem, tìm, lọc, mở Chi tiết, nhưng các ô sửa chỉ đọc.
2. **Trả lại 3 ô chọn như màn đang chạy**, đặt cạnh ô tìm, ở mọi khung chứa ≥ 600px:
   - Sắp xếp: "Mới nhất"…, chữ `media.picker_sort_*`.
   - Loại tệp: "Tất cả loại tệp"…, chữ `media.picker_type_*`.
   - Định dạng: "Mọi định dạng"…, chữ `media.picker_format_*`.
   - Bỏ nút "Lọc" gộp ở máy tính và tablet.
   - Chỉ khi khung chứa < 600px mới gom Loại tệp + Định dạng vào 1 nút "Lọc (N)" mở bảng chọn trượt từ đáy. Sắp xếp vẫn là 1 ô riêng.
   - Đây đúng là ý của Sửa kèm D0-6b: chỉ gom trên điện thoại.
3. **Panel "Chi tiết ảnh": chỉ 1 thanh cuộn.**
   - Khi panel mở, trang phía sau KHÔNG cuộn: khóa cuộn trang, và `overscroll-behavior: contain` trong panel.
   - Đầu panel (tiêu đề, ⋯, ×) và chân panel (Đóng · Lưu) đứng yên; chỉ phần giữa cuộn.
   - Đóng panel thì trả lại cuộn trang ở đúng chỗ cũ, focus về đúng ảnh vừa mở.
   - Đo bản 00:31: panel cuộn 770/1487px trong khi trang sau vẫn cuộn 2460px.
   - Điện thoại: panel toàn màn hình, cũng chỉ 1 thanh cuộn.
4. **Lỗi ô "Địa chỉ tệp":** đang hiện `https://files.function () { return C.rootDomain; }/an-vinh/library/…`, tức mã nguồn hàm bị chèn vào địa chỉ. Phải GỌI hàm, ra `https://files.<tên miền gốc>/…`. Rà cả tệp xem còn chỗ nào chèn hàm thay vì giá trị không.
5. **Phân trang và chế độ Danh sách:** 1 website có thể có hàng nghìn ảnh, nên không vẽ một lưới dài vô tận.
   - Chân lưới theo Mẫu danh sách: "Ảnh / trang 24 · 48 · 96" + số trang. Repo đang có Trước / Sau.
   - Chế độ Danh sách có cột Tên · Loại · Kích thước · Dung lượng · Dùng ở, như repo + nhãn mới.
   - "⋯" của từng ảnh có "Đưa ra ngoài thư mục" khi ảnh đang ở trong thư mục.
6. **So lại với màn đang chạy:** mọi thứ màn trong repo có (2 tab, 3 ô chọn, ô tìm, chọn nhiều, lưới / danh sách) đều phải còn. README thêm bảng 4 cột "Màn đang chạy · Bản mới · Giữ bản nào · Vì sao", dùng đúng 2 danh sách GIỮ / TRẢ LẠI ở trên.

Xong khi:
(1) 1280 và 768: có 2 tab "Tải lên tệp mới" · "Thư viện ảnh"; không còn nút "Tải ảnh lên" ở góc; tab Thư viện có 3 ô chọn Sắp xếp / Loại tệp / Định dạng cạnh ô tìm, đúng chữ repo.
(2) 390: vẫn 2 tab; "Lọc (N)" gom Loại tệp + Định dạng, mở bảng trượt từ đáy; Sắp xếp là ô riêng; vùng bấm ≥ 44px.
(3) Mở Chi tiết ảnh rồi lăn chuột trên panel và trên nền mờ: vị trí cuộn của trang không đổi; chỉ phần giữa panel cuộn; đóng panel thì focus về đúng ảnh.
(4) Ô "Địa chỉ tệp" không chứa chữ "function"; grep tệp không còn chỗ nối hàm vào chuỗi.
(5) Tab "Tải lên tệp mới" có đủ 3 nguồn (Từ máy · Chụp ảnh · Từ địa chỉ web); Tweak quyền chỉ `media.view` thì không có tab này, không có ô chọn để xóa, không có "⋯" thư mục. Lưới có chân "Ảnh / trang" + số trang. Mọi phần trong danh sách GIỮ vẫn còn.
(6) `_audit/harness.html` ở 360 / 390 / 768 / 1024 / 1440, chữ thường và Chữ lớn, đứng riêng và trong Shell (`#/site/media`), đạt cả 3 điều:
   - `scrollWidth = clientWidth`;
   - tương phản ≥ 4,5:1 cả sáng lẫn tối;
   - mở lại tệp và Admin Shell: console 0 lỗi.
   Tệp đo tạm chỉ đặt trong `_audit/tmp/`.

Xuất zip project gửi Claude Code kiểm. Xong thì báo owner đúng một câu: "D1-6s xong — gửi zip cho Claude Code."
