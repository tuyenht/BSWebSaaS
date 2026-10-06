# D0-4as · Sửa thêm sau khi kiểm zip D0-4a (dán vào chat D0-4b đang chạy)

Sửa thêm trước khi xuất zip. Claude Code đã kiểm zip D0-4a bằng Chromium thật: 3 kiểu bố cục × thanh Đậm / Sáng / Chuyển màu × 360 / 768 / 1024 / 1440 × sáng / tối, cùng thanh Lưu, "Xem lỗi đầu tiên" và Cài đặt ở 390. D0-4a ĐẠT cả 7 mục "Xong khi". Còn các điểm dưới đây, đều nằm trong `Admin Shell.dc.html`, thuộc phạm vi D0-4b. Làm xong việc đang làm của D0-4b rồi sửa các điểm này. Không chia lượt riêng, và không sửa tệp ngoài "Được sửa" của D0-4b.

1. Vùng bấm trên topbar ở điện thoại (dưới 768px khung chứa):
   - Tìm kiếm, Trợ giúp, chuông và bộ chuyển website đang chỉ 38×38px, cách nhau 4px.
   - Đổi thành ≥ 44×44px, cách nhau ≥ 8px.
   - Nếu 390px không đủ chỗ, đưa bộ chuyển website vào menu người dùng ở bề rộng đó, không thu nhỏ nút.
   - Kiểm: ở 360 và 390, mọi nút topbar ≥ 44px và `scrollWidth = clientWidth`.
2. Kiểu 2 cột ở 1024px (thanh icon, cột mục con đang ẩn):
   - Ô icon của nhóm đang chọn có vạch và nền nhưng KHÔNG có `aria-current="page"`, nên trang không có mục nào mang `aria-current`.
   - Khi cột mục con ẩn, gắn `aria-current="page"` lên ô icon đó. Khi cột mục con hiện, gắn lên mục con như hiện nay.
   - Kiểm: ở mọi bề rộng, luôn có đúng 1 phần tử đang hiện mang `aria-current="page"`.
3. Kiểu ngang:
   - Khi trang đang mở nằm trong menu thả "Khác" (ví dụ Cài đặt) hoặc trong một nhóm thả xuống, thanh menu không còn dấu nào cho biết đang ở đâu.
   - Nút cha ("Khác" hoặc tên nhóm) nhận kiểu "đang ở trong": cùng màu chữ và vạch như mục đang chọn, nhưng KHÔNG gắn `aria-current`. `aria-current` vẫn chỉ nằm ở mục con.
4. Thanh bên dọc:
   - Khi mở trang có mục đang chọn nằm dưới tầm nhìn (ví dụ Cài đặt ở cao 900px), thanh bên không cuộn tới mục đó.
   - Khi nạp trang và khi đổi trang, cuộn thanh bên sao cho mục đang chọn nằm trong tầm nhìn. Chỉ cuộn khi mục đang ngoài tầm nhìn.
5. Cài đặt ở điện thoại:
   - Khi đang hiện danh sách nhóm/mục, hash vẫn là `#settings/general`, nên nạp lại trang sẽ mở thẳng "Thông tin chung" thay vì danh sách.
   - Ở dạng danh sách, đặt hash `#settings`; bấm một mục thì đặt `#settings/<mục>`; nút "‹ Cài đặt" thì quay về `#settings`.
   - Ở máy tính, `#settings` mở mục đầu tiên như cũ.
6. Mã chết còn sót sau khi gỡ 3 nút topbar: `onToggleScheme` · `schemeIcon` · `toggleScheme()` · `onToggleFullscreen` · `fsIcon` · `toggleFullscreen()` và listener `fullscreenchange` · `czTriggerStyle`, cùng các prop của nút Tùy biến nổi đã gỡ. Ngoài ra còn `czPlane` và `czBrand`: tính ra nhưng không còn markup nào dùng. Xóa hết.
7. README D0-4: thêm một dòng nói rằng mục (4) "Xong khi" của D0-4a chỉ áp cho mô tả một dòng của mục Cài đặt và nhãn mặt. Chữ SMTP / OAuth / SSL / sitemap / robots trong NỘI DUNG các panel Email, Tên miền, SEO, Tài khoản mạng xã hội là chữ có sẵn, do D0-6b và D3-2 sửa.
8. TODO.md: thêm 2 dòng.
   - "Email Settings ở 390: dải tab cắt chữ ('Chữ ký & thương hiệu' bị che), thẻ trạng thái quá hẹp · D0-6b".
   - "Tổng quan ở 360: nút '7 ngày / 30 ngày' xuống 2 dòng · D2-5".

Xong khi: (1)–(5) đo lại bằng script ở 360 / 390 / 1024 / 1440 × 3 kiểu bố cục và ghi số vào README D0-4, phần D0-4b. Grep 0 lần các tên ở điểm 6. Đo không được thì ghi [CHƯA KIỂM ĐƯỢC], Claude Code kiểm bằng zip.
