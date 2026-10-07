# D2-1s · Nhóm quyền — chữ "thành viên · lời mời" đè lên cột bên cạnh (dán vào chat D2-1)

Sửa thêm trên bản Nhóm quyền hiện có, trong cùng chat. Sửa xong thì xuất zip lại.

Designbot thấy lỗi và Claude Code đã đo lại bằng Chromium (zip D3-1 08:41), cả đứng riêng lẫn trong Shell:
- Từ khung 1280 trở lên, ở các dòng có lời mời đang chờ ("Người viết", "Cộng tác viên dịch thuật"…), chữ "3 thành viên · 1 lời mời" đè lên cột "Việc tiêu biểu" (chồng lên "Viết bài").
- Dưới 1024 thì không đè, vì luật `@container nq (max-width:1023px)` đổi bố cục.

Nguyên nhân trong mã:
- `.nq-row{grid-template-columns:minmax(0,2fr) minmax(0,1.2fr) 120px minmax(0,2fr) auto}`: cột "Thành viên" cố định 120px;
- `.nq-lnk{white-space:nowrap}`: chữ không được xuống dòng;
- `membersText()` ghép "N thành viên · N lời mời" thành một dòng dài hơn 120px.

Chỉ sửa `Nhóm quyền.dc.html`, `README - D2-1 Nhóm quyền & Thành viên.md`, `TODO.md`, `LESSONS.md`.

1. **Cột "Thành viên" không bao giờ đè cột bên cạnh**, ở mọi khung:
   - tách thành 2 dòng nhỏ trong cùng ô: dòng 1 là liên kết "3 thành viên"; dòng 2 là "1 lời mời đang chờ" (chữ phụ, vẫn ≥ 12px, tương phản ≥ 4,5:1);
   - cột dùng `minmax(0, …)` thay cho 120px cố định;
   - chữ dài (ví dụ "128 thành viên · 12 lời mời", hoặc bản tiếng Anh dài hơn 40%) xuống dòng trong ô, không tràn.
2. **Nút "Xem đủ"** đang cao khoảng 20px: vùng bấm ≥ 44 × 44px ở khung < 1024px, ≥ 32px ở máy tính, chữ giữ nguyên.
3. **README:** ghi lại lỗi và cách sửa. LESSONS thêm 1 dòng: "Ô dữ liệu có số biến đổi trong lưới: dùng `minmax(0, …)` và cho xuống dòng; đo chữ đè ở 1280 / 1440 với dữ liệu dài (07/10)".

Xong khi:
(1) Đứng riêng và trong Shell (`#/site/roles`) ở 1024 / 1280 / 1440 / 1920: không ô chữ nào đè ô bên cạnh. Đo bằng hộp chữ của từng ô, không đo bằng mắt.
(2) Tweak dữ liệu dài (thêm 1 nhóm có 128 thành viên và 12 lời mời): ô xuống dòng gọn, không đè, không tràn ngang.
(3) "Xem đủ" ≥ 44px ở 390 và 768.
(4) `_audit/harness.html` ở 360 / 390 / 768 / 1024 / 1440, chữ thường và Chữ lớn, đạt cả 3 điều:
   - `scrollWidth = clientWidth`;
   - tương phản ≥ 4,5:1 cả sáng lẫn tối;
   - console 0 lỗi.
   Tệp đo tạm chỉ ở `_audit/tmp/`, xóa sạch trước khi xuất zip.

Xuất zip project gửi Claude Code kiểm. Xong thì báo owner đúng một câu: "D2-1s xong — gửi zip cho Claude Code."
