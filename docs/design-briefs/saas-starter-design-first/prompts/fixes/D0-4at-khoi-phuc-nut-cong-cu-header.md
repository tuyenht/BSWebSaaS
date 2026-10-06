# D0-4at · Khôi phục các nút công cụ bên phải header (dán vào chat D0-4b đang chạy)

Owner quyết định 06/10: GIỮ các nút công cụ bên phải header như thiết kế gốc. D0-4a gỡ chúng theo prompt cũ — prompt đó sai, không phải lỗi của bạn. Làm sau việc D0-4b đang làm, trước khi xuất zip. Đoạn này THẮNG mọi chỗ khác nói bỏ các nút này (D0-4a mục 2 và "Xong khi (3)", D0-4as điểm 6).

1. Topbar ở máy tính và máy tính bảng (khung chứa ≥ 768px), thứ tự từ trái sang phải:
   - Tìm · Trợ giúp · **Ngôn ngữ giao diện** (nút cờ như thiết kế gốc) · **Toàn màn hình** · **Sáng / tối** · chuông · bộ chuyển website · menu người dùng.
   - Nút cờ: bấm mở danh sách ngôn ngữ, mỗi dòng có cờ + tên bản địa ("Tiếng Việt", "English", "中文"…). Nút có `aria-label` và tooltip ghi tên bản địa ngôn ngữ đang dùng, vì cờ không đủ cho trình đọc màn hình.
   - Toàn màn hình: bật / tắt toàn màn hình, icon đổi theo trạng thái, tooltip "Toàn màn hình" / "Thoát toàn màn hình".
   - Sáng / tối: đổi nhanh giữa sáng và tối. Lựa chọn "Theo máy" vẫn ở menu người dùng › Giao diện. Hai nơi dùng chung một trạng thái.
   - Các nút này là nút icon quen thuộc: có tooltip + `aria-label`, vùng bấm ≥ 44px, cách nhau ≥ 8px.
2. Điện thoại (khung chứa dưới 768px): KHÔNG thêm 3 nút này vào topbar, vì không đủ chỗ cho vùng bấm 44px.
   - Ngôn ngữ giao diện và Sáng / tối nằm trong menu người dùng, như hiện nay.
   - Toàn màn hình ẩn trên điện thoại.
3. KHÔNG làm điểm 6 của D0-4as với `onToggleScheme` · `schemeIcon` · `toggleScheme()` · `onToggleFullscreen` · `fsIcon` · `toggleFullscreen()` · listener `fullscreenchange`. Giữ lại, hoặc khôi phục nếu đã xóa, rồi nối lại vào markup. Vẫn xóa `czTriggerStyle`, `czPlane`, `czBrand`: nút Tùy biến nổi vẫn bỏ, vì nó đè nội dung ở màn hẹp, và Tùy biến đã nằm ở menu người dùng › Giao diện.
4. README D0-4: sửa dòng topbar theo thứ tự ở điểm 1. LESSONS.md: thêm 1 dòng "06/10 owner giữ nút cờ ngôn ngữ, toàn màn hình, sáng/tối trên topbar (≥ 768px); prompt D0-4a gỡ là sai".

Xong khi: ở 1024 và 1440 topbar đúng thứ tự ở điểm 1, 3 nút bấm được và đổi trạng thái thật. Ở 390, topbar không có 3 nút đó, mọi nút ≥ 44px và `scrollWidth = clientWidth`. Đo không được thì ghi [CHƯA KIỂM ĐƯỢC].
