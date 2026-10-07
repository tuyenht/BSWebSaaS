# D3-1s · Cài đặt — công tắc "Hiện giá tham khảo", cỡ chữ mặc định của website, bỏ tên thương hiệu còn sót (dán vào chat D3-1)

Sửa thêm trên bản Cài đặt hiện có (sau D0-7), trong cùng chat. Sửa xong thì xuất zip lại.

Owner chốt 07/10 cho 2 câu "Cần owner chốt" của D3-1 và cho chỗ designbot dừng lượt:
- **Công tắc "Hiện giá"** (chat đã bỏ vì "trùng nghĩa với chế độ"). Trong repo, chế độ Giới thiệu luôn ẩn giá và chế độ Bán hàng luôn hiện giá (`app/Modules/Admin/Livewire/CatalogSettings.php`, `CatalogMode`). Nhưng website giới thiệu vẫn có thể cần hiện giá tham khảo, nên giữ công tắc theo phương án sau:
  - chỉ hiện khi chọn chế độ **Giới thiệu**. Nhãn: "Hiện giá tham khảo". Gợi ý dưới: "Khách thấy giá nhưng vẫn bấm "Liên hệ báo giá", không đặt hàng." Mặc định tắt;
  - chế độ **Bán hàng**: không có công tắc này, vì giá luôn hiện;
  - đặt ngay dưới ô chọn chế độ, trước "Hiện số lượng còn" và "Khi hết hàng";
  - gắn "Chờ backend": khóa cài đặt `catalog.show_reference_price`; trang khách đọc khóa này khi ở chế độ Giới thiệu. Ghi vào "Việc cho backend".
- **(a) Nhóm "Hiển thị"** của Giao diện website (chế độ tối của website, thanh thông báo): giữ nguyên chỗ cũ, không chuyển.
- **(b) Ô "Cỡ chữ"** ở Giao diện trang quản trị: đây là **mặc định của website**. Mỗi thành viên vẫn tự chọn đè lên (D0-7).
  - Đổi lựa chọn thành 4 mức đúng như D0-7: "Vừa (mặc định) · Lớn · Rất lớn · Cực lớn". Ô đang chọn "Vừa (mặc định)".
  - Gợi ý dưới ô: "Áp cho thành viên chưa tự chọn cỡ chữ. Mỗi người vẫn đổi được ở menu tài khoản › Giao diện."
  - Repo: tầng mặc định của website là `site_settings` nhóm `admin_theme` (`AdminChromeResolver`).

Chỉ sửa `Admin Shell.dc.html` (chỉ `FIELDS.catalog`, ô "Cỡ chữ" của giao diện trang quản trị, `PAL()` và dòng chú thích ngay trên nó, ô "Tên công ty trên hóa đơn" trong `FIELDS['p-general']`), `README - D3-1 Cài đặt Chung & Nội dung.md`, `TODO.md`, `LESSONS.md`.

1. Công tắc "Hiện giá tham khảo" như trên. Đổi ô "Chế độ sản phẩm" giữa Giới thiệu và Bán hàng thì công tắc hiện / ẩn đúng.
2. Ô "Cỡ chữ" mặc định của website như trên.
3. **Không gắn cứng thương hiệu** (mục "Xong khi" (6) của D3-1 chưa đạt, còn 3 chỗ):
   - bảng màu `PAL()` có tên "Bảo Sơn" hiện trên màn: đổi thành tên màu trung tính "Xanh hoàng gia". Mã màu và thứ tự giữ nguyên;
   - ô "Tên công ty trên hóa đơn" ở phần Nền tảng (`FIELDS['p-general']`, là công ty vận hành nền tảng, không phải khách) đang gắn cứng "Công ty TNHH Quảng cáo Bảo Sơn": đọc từ cấu hình nền tảng `platform-config.js` như "Tên nền tảng" (`P0.name`). Nếu cấu hình chưa có trường tên pháp lý thì thêm `legalName` vào cấu hình mẫu, không ghi tên vào Shell;
   - chú thích mã có chữ "Bảo Sơn" (không hiện trên màn): đổi thành "màu mặc định của nền tảng".
4. **Các sửa ngoài danh sách được phép mà chat đã tự khai ở D3-1** (nạp `content-ui.js` trong Shell, 3 kiểu ô mới trong `mapField`, phần kiểm lỗi): KHÔNG gỡ. README thêm mục "Sửa ngoài danh sách" nêu từng chỗ, lý do, và màn nào dùng, để Claude Code kiểm.
5. README: ghi 3 quyết định của owner ở trên vào mục "Cần owner chốt" với trạng thái "Đã chốt 07/10".

Xong khi:
(1) Cài đặt › Sản phẩm: chế độ Giới thiệu có "Hiện giá tham khảo" (mặc định tắt); chế độ Bán hàng không có. "Hiện số lượng còn", "Khi hết hàng", "Chữ trên nút liên hệ" vẫn đúng như trước.
(2) Giao diện trang quản trị: "Cỡ chữ" có 4 mức, mặc định "Vừa", có gợi ý về lựa chọn riêng.
(3) Grep `Admin Shell.dc.html`: 0 "Bảo Sơn".
(4) README có mục "Sửa ngoài danh sách" và 3 quyết định "Đã chốt 07/10".
(5) `_audit/harness.html` ở 320 / 390 / 768 / 1024 / 1440 × cỡ chữ Vừa và Cực lớn × sáng + tối, trong Shell (`#/site/settings`), đạt cả 3 điều:
   - `scrollWidth = clientWidth`;
   - tương phản ≥ 4,5:1;
   - console 0 lỗi.
   Tệp đo tạm chỉ ở `_audit/tmp/`, xóa sạch trước khi xuất zip.

Xuất zip project gửi Claude Code kiểm. Xong thì báo owner đúng một câu: "D3-1s xong — gửi zip cho Claude Code."
