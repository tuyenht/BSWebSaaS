# Trình đơn — thiết kế đã duyệt + việc backend làm theo

- **Thiết kế:** `docs/design-source/Trình đơn.dc.html` (bản export dự án Claude Design 18/09/2026, owner gửi
  17–18/09 và 23/09). Bản owner publish https://claude.ai/artifact/72uxdN6nMYG1RJhthnjV7F trùng khớp bản này.
- **Còn chờ thiết kế (lượt D1-2):** chọn trình đơn theo từng ngôn ngữ ở mỗi vị trí; nhãn trạng thái cho mục trỏ
  tới sản phẩm. Phần còn lại của thiết kế giữ nguyên.
- **Luật:** thiết kế dẫn, backend theo (CLAUDE.md §8). Không cắt tính năng, không dựng trạng thái tạm.

## Việc cho backend

Ký hiệu: ✅ có · 🔧 code, không đổi cấu trúc dữ liệu · 🏛 đổi cấu trúc dữ liệu — COUNCIL (bàn cách làm, không bàn
có làm hay không) · ⏸ phụ thuộc module chưa có.

| Tính năng trong thiết kế | Backend | Ghi chú |
|---|---|---|
| Cây mục, kéo thả, lên/xuống, thành mục con / đưa ra ngoài | ✅ | `Cms/Actions/Menus/MoveMenuItem.php` |
| Nguồn trang/bài · chuyên mục · sản phẩm · địa chỉ ngoài | ✅ | sản phẩm đăng ký ở `CatalogServiceProvider.php:49` |
| Gán trình đơn theo vị trí × ngôn ngữ, dự phòng ngôn ngữ mặc định | ✅ | thiết kế chưa có — lượt D1-2 |
| Lưu = hiệu lực ngay, hoàn tác trong phiên | ✅ | hoàn tác là trạng thái phía trình duyệt |
| Biểu tượng · mô tả mục · nút nổi bật · nhãn có hạn · chỉ máy tính/điện thoại · ẩn mục bằng tay | 🔧 | lưu vào `menu_items.meta` (không lọc/sắp theo các khoá này nên không vi phạm luật tách cột); cần luật kiểm + `MenuResolver` hiển thị |
| Bảng lớn chia cột tự động từ mục con | 🔧 | `meta` + giao diện website |
| Liên kết hệ thống: tìm kiếm, gọi điện, Zalo, bản đồ, giỏ hàng, tài khoản, sơ đồ trang | 🔧 | mở rộng `MenuRules::DYNAMIC` (hiện chỉ `home`), lấy số/đường dẫn từ Cài đặt |
| Nhân bản trình đơn / mục | 🔧 | action mới sao chép cả nhánh + bản dịch |
| Tự thêm trang mới đăng vào cuối trình đơn | 🔧 | listener khi bài/trang lên công khai + cờ trên trình đơn (cờ có thể ở `menus` → 🏛 nếu thành cột) |
| "Hệ thống tự kiểm": nguồn hỏng/chưa đăng, quá sâu, thiếu bản dịch, trang chưa vào trình đơn | 🔧 | đọc từ dữ liệu có sẵn; admin cần thấy lý do mục bị ẩn |
| Thùng rác 30 ngày cho trình đơn và mục | 🏛 | thêm `deleted_at` + RLS/scope + lệnh dọn; hiện `DeleteMenu`/`DeleteMenuItem` xoá hẳn |
| Vị trí do giao diện khai báo (9 vị trí, số cấp riêng từng vị trí) | 🏛 | sổ vị trí theo theme; website hiện chỉ dựng `primary`, chân trang dùng lại nó (`StorefrontController.php:79`) — theme phải dựng thêm các vị trí |
| Số cấp theo từng vị trí (bảng lớn được miễn) | 🏛 | hiện một giới hạn chung `MenuRules::MAX_DEPTH = 5` |
| Mã nhúng trình đơn giữa trang | ⏸ | cần khối "Trình đơn" trong Trình dựng trang |
| Bảng lớn chứa khối tự dựng | ⏸ | cần Khối tái dùng |
| AI dịch nhãn/mô tả | ⏸ | bộ AI để sau core |

Mã lỗi đường ghi: `app/Modules/Cms/Exceptions/MenuChangeRejectedException.php` — câu đời thường cho từng mã
nằm trong README của lượt D1-2.
