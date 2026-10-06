# D0-4at · Khôi phục nút công cụ header + đặt lại bộ chuyển website (dán vào chat D0-4b đang chạy)

Owner quyết định 06/10: GIỮ các nút công cụ bên phải header như thiết kế gốc. D0-4a gỡ chúng theo prompt cũ — prompt đó sai, không phải lỗi của bạn. Làm sau việc D0-4b đang làm, trước khi xuất zip. Đoạn này THẮNG mọi chỗ khác nói khác (D0-4a mục 2 và "Xong khi (3)"; D0-4as điểm 1 về bộ chuyển website và điểm 6).

1. Topbar ở máy tính và máy tính bảng (khung chứa ≥ 768px), thứ tự từ trái sang phải:
   - Tìm · Trợ giúp · **Ngôn ngữ giao diện** (nút cờ như thiết kế gốc) · **Toàn màn hình** · **Sáng / tối** · chuông · menu người dùng. Bộ chuyển website RỜI topbar, xem điểm 5.
   - Nút cờ: bấm mở danh sách ngôn ngữ, mỗi dòng có cờ + tên bản địa ("Tiếng Việt", "English", "中文"…). Nút có `aria-label` và tooltip ghi tên bản địa ngôn ngữ đang dùng, vì cờ không đủ cho trình đọc màn hình.
   - Toàn màn hình: bật / tắt toàn màn hình, icon đổi theo trạng thái, tooltip "Toàn màn hình" / "Thoát toàn màn hình".
   - Sáng / tối: đổi nhanh giữa sáng và tối. Lựa chọn "Theo máy" vẫn ở menu người dùng › Giao diện. Hai nơi dùng chung một trạng thái.
   - Các nút này là nút icon quen thuộc: có tooltip + `aria-label`, vùng bấm ≥ 44px, cách nhau ≥ 8px.
2. Điện thoại (khung chứa dưới 768px): KHÔNG thêm 3 nút này vào topbar, vì không đủ chỗ cho vùng bấm 44px.
   - Ngôn ngữ giao diện và Sáng / tối nằm trong menu người dùng, như hiện nay.
   - Toàn màn hình ẩn trên điện thoại.
3. KHÔNG làm điểm 6 của D0-4as với `onToggleScheme` · `schemeIcon` · `toggleScheme()` · `onToggleFullscreen` · `fsIcon` · `toggleFullscreen()` · listener `fullscreenchange`. Giữ lại, hoặc khôi phục nếu đã xóa, rồi nối lại vào markup. Vẫn xóa `czTriggerStyle`, `czPlane`, `czBrand`: nút Tùy biến nổi vẫn bỏ, vì nó đè nội dung ở màn hẹp, và Tùy biến đã nằm ở menu người dùng › Giao diện.
4. Bộ chuyển website (owner 06/10). Đoạn này thay quy tắc cũ "hiện khi ≥ 2 website HOẶC gói còn chỗ".
   - Người dùng quản trị đúng 1 website: ẨN hẳn bộ chuyển.
   - Từ 2 website trở lên: hiện bộ chuyển, đặt ở ĐẦU vùng điều hướng, ngay dưới logo — đúng chỗ mắt bắt đầu đọc, cho biết "đang ở website nào" trước khi chọn menu. Topbar bên phải chỉ còn công cụ và tài khoản.
   - Theo bố cục:
     · Dọc, thanh bên đầy đủ: khối rộng hết thanh bên, gồm ô chữ cái đầu + tên website (1 dòng, dài thì "…" + tooltip) + tên miền (chữ nhỏ) + mũi tên. Khối nằm trên nhóm menu đầu tiên. Cùng tông với thanh bên Đậm / Sáng / Chuyển màu, tương phản chữ ≥ 4,5:1.
     · Thanh icon thu nhỏ (gồm 1024px): chỉ còn ô chữ cái đầu 44×44, có tooltip + `aria-label` "Đang quản trị <tên> — đổi website".
     · 2 cột: ô chữ cái đầu ở đầu cột icon; khối đầy đủ ở đầu cột menu.
     · Ngang (không có thanh bên): khối gọn ở topbar, ngay sau logo, bên trái ô tìm.
     · Điện thoại: ô chữ cái đầu 44×44 cạnh nút "Menu" trên topbar, bấm mở bảng chọn trượt từ đáy; khối đầy đủ cũng nằm ở đầu ngăn kéo menu.
   - Danh sách khi mở:
     · Mỗi dòng: ô chữ cái đầu, tên, tên miền, vai trò của người dùng ở website đó ("Chủ website", "Biên tập"…).
     · Website đang mở có dấu ✓ và `aria-current="true"`.
     · Từ 6 website có ô tìm ở đầu danh sách. Danh sách dài thì cuộn trong khung, không tràn màn.
     · Chân danh sách có "Tạo website mới" (khóa theo gói khi hết chỗ).
     · Chọn website khác = mở trang quản trị của website đó (giữ như D0-4a, Việc cho backend).
   - Chỉ có 1 website mà gói còn chỗ: "Tạo website mới" nằm trong menu người dùng, vì không còn bộ chuyển để chứa nó.
   - Tweak mới trong cùng data-props: "Số website của người dùng: 1 · 3 · 8" để xem cả ba trạng thái.
5. README D0-4: sửa dòng topbar theo thứ tự ở điểm 1 và ghi chỗ đặt bộ chuyển website theo điểm 4. LESSONS.md: thêm 1 dòng "06/10 owner giữ nút cờ ngôn ngữ, toàn màn hình, sáng/tối trên topbar (≥ 768px); prompt D0-4a gỡ là sai".

Xong khi: ở 1024 và 1440 topbar đúng thứ tự ở điểm 1, 3 nút bấm được và đổi trạng thái thật. Tweak số website = 1 thì không có bộ chuyển ở đâu cả. = 3 và = 8 thì bộ chuyển đúng chỗ theo từng bố cục ở điểm 5; = 8 có ô tìm; mỗi ô bấm ≥ 44px. Ở 390, topbar không có 3 nút đó, mọi nút ≥ 44px và `scrollWidth = clientWidth`. Đo không được thì ghi [CHƯA KIỂM ĐƯỢC].
