# D0-4at · Khôi phục nút công cụ header + đặt lại bộ chuyển website (dán vào chat D0-4b, NGAY sau D0-4as)

Owner quyết định 06/10, sau khi xem D0-4a: GIỮ các nút công cụ bên phải header như thiết kế gốc, và đổi quy tắc bộ chuyển website. D0-4a gỡ các nút này là làm theo prompt cũ — prompt đó sai, không phải lỗi của bạn. Làm sau việc D0-4b đang làm và sau D0-4as, trước khi xuất zip. Đoạn này THẮNG mọi chỗ nói khác: D0-4a mục 2 và "Xong khi (3)"; D0-4as điểm 1 (phần bộ chuyển website), điểm 6 và dòng grep trong "Xong khi" của D0-4as; README D0-4 các dòng nói đã bỏ 3 nút và quy tắc bộ chuyển cũ.

1. Topbar từ 768px khung chứa trở lên, thứ tự từ trái sang phải:
   - Tìm · Trợ giúp · **Ngôn ngữ giao diện** · **Toàn màn hình** · **Sáng / tối** · chuông · menu người dùng.
   - Bộ chuyển website KHÔNG ở topbar bên phải nữa (xem điểm 4).
   - Ngôn ngữ giao diện:
     · Nút mở danh sách ngôn ngữ của trang quản trị. Mỗi dòng ghi tên bản địa ("Tiếng Việt", "English", "日本語", "中文"), có `lang` đúng ngôn ngữ đó. Dòng đang dùng có dấu ✓ và `aria-current="true"`. Dùng phím mũi tên để đi qua các dòng; Esc đóng danh sách và trả focus về nút.
     · Mặt nút theo Tweak "Kiểu nút ngôn ngữ":
       - **Cờ** (mặc định, như thiết kế gốc của owner): cờ tròn. Vẽ cờ bằng SVG nội tuyến; repo có sẵn `public/images/admin/flags/{vi,en,ja,zh}.svg`, ngôn ngữ chưa có cờ thì hiện mã. Danh sách: cờ + tên bản địa.
       - **Mã**: icon dịch + mã ngắn "VI", để owner so sánh.
     · Luật "Không dùng cờ quốc gia" (CLAUDE.md §2) chỉ áp cho thanh ngôn ngữ NỘI DUNG — đề mục §2 tự loại trừ bộ chọn ngôn ngữ giao diện — nên nút này dùng cờ được.
     · Nút luôn có `aria-label` và tooltip "Ngôn ngữ giao diện: <tên bản địa>".
     · Thanh ngôn ngữ của NỘI DUNG (chip VI/EN trong trình soạn) không đổi, vẫn không dùng cờ.
   - Toàn màn hình:
     · Bật / tắt toàn màn hình. Icon đổi theo trạng thái. Tooltip "Toàn màn hình" / "Thoát toàn màn hình".
     · Trình duyệt không hỗ trợ (`document.fullscreenEnabled` = false, ví dụ iPhone) thì ẩn nút.
   - Sáng / tối:
     · Đổi giữa giao diện sáng và tối, tính theo giao diện ĐANG HIỆN (kể cả khi đang "Theo máy"), để bấm là thấy đổi ngay.
     · "Theo máy" vẫn chọn ở menu người dùng › Giao diện. Hai nơi dùng chung một trạng thái.
   - Nút chỉ có icon: tooltip + `aria-label`, vùng bấm ≥ 44px, cách nhau ≥ 8px. Đây là ngoại lệ owner duyệt cho tiêu chí 4 (ghi vào README).
   - Ô tìm luôn rộng ≥ 240px. Thiếu chỗ (ví dụ chữ lớn ở 768px) thì ô tìm thu thành nút kính lúp, bấm mở ô tìm toàn màn. Không bỏ nút công cụ nào.
2. Dưới 768px:
   - Topbar giữ: Menu · Tìm · Trợ giúp · chuông · menu người dùng. Mọi nút ≥ 44×44px, cách nhau ≥ 8px.
   - Ngôn ngữ giao diện và Sáng / tối nằm trong menu người dùng. Ở mọi bề rộng, hai mục này vẫn có trong menu người dùng; mục Ngôn ngữ giao diện ở đó đọc cùng Tweak "Kiểu nút ngôn ngữ" (thay câu "tên bản địa, KHÔNG cờ" của D0-4a mục 2).
   - Ẩn nút Toàn màn hình.
3. Mã:
   - GIỮ, hoặc khôi phục nếu đã xóa, `onToggleScheme` · `schemeIcon` · `toggleScheme()` · `onToggleFullscreen` · `fsIcon` · `toggleFullscreen()` · listener `fullscreenchange`, rồi nối lại vào markup.
   - Vẫn xóa `czTriggerStyle`, `czPlane`, `czBrand`. Nút Tùy biến nổi vẫn bỏ, vì nó đè nội dung ở màn hẹp và Tùy biến đã nằm ở menu người dùng › Giao diện.
   - Mảng cờ: thay `flagUrl = () => ''` bằng nguồn cờ thật cho kiểu Cờ.
4. Bộ chuyển website. Thay quy tắc cũ "hiện khi ≥ 2 website HOẶC gói còn chỗ".
   - Chỉ có trên mặt Website.
   - KHÔNG có trên mặt Nền tảng, và KHÔNG có khi đang "Đăng nhập dưới tên khách hàng": phiên hỗ trợ gắn với đúng một website.
   - Người dùng quản trị đúng 1 website: ẨN hẳn.
   - Từ 2 website: hiện ở ĐẦU vùng điều hướng, ngay dưới logo — chỗ mắt bắt đầu đọc, cho biết "đang ở website nào" trước khi chọn menu.
   - Khối nằm NGOÀI vùng menu cuộn, nên luôn thấy. Bảng chọn dùng `position:fixed` như bảng nổi của thanh icon (`.rfly`), không bị cắt.
   - Theo bố cục:
     · Dọc, thanh bên đầy đủ (≥ 1024px): khối rộng hết thanh bên, gồm ô chữ cái đầu (hoặc icon của website nếu có) + tên website (1 dòng, dài thì "…" + tooltip) + tên miền chữ nhỏ + mũi tên. Cùng tông với thanh bên Đậm / Sáng / Chuyển màu, chữ tương phản ≥ 4,5:1. Trên nền Đậm / Chuyển màu có viền mảnh sáng để tách khỏi menu.
     · Thanh icon thu nhỏ: chỉ ô chữ cái đầu 44×44 ở đầu thanh, tooltip + `aria-label` "Đang quản trị <tên> — đổi website".
     · 2 cột: ô chữ cái đầu ở đầu cột icon. Khi cột menu hiện thì khối đầy đủ ở đầu cột menu và ô ở cột icon ẩn đi — luôn chỉ MỘT bộ chuyển.
     · Ngang: ngay sau logo, bên trái ô tìm. Dưới 1280px: ô chữ cái đầu 44×44. Từ 1280px: khối gọn = ô chữ cái đầu + tên website 1 dòng, KHÔNG tên miền, KHÔNG mũi tên, rộng tối đa 200px. Ô tìm luôn ≥ 240px.
     · 768–1023px (menu là ngăn kéo): ô chữ cái đầu 44×44 ngay sau nút "Menu"; khối đầy đủ cũng ở đầu ngăn kéo.
     · Dưới 768px: CHỈ ở đầu ngăn kéo menu, không thêm vào topbar (360px không đủ chỗ cho vùng bấm 44px).
   - Danh sách khi mở:
     · Mỗi dòng: ô chữ cái đầu, tên, tên miền, vai trò của người dùng ở website đó ("Chủ website", "Biên tập"…).
     · Website đang mở có dấu ✓ và `aria-current="true"`.
     · Từ 6 website có ô tìm ở đầu danh sách. Danh sách dài thì cuộn trong khung.
     · Dùng phím mũi tên để đi qua các dòng; Esc đóng danh sách và trả focus về ô / khối. Dưới 1024px, danh sách là bảng trượt từ đáy.
     · Chân danh sách có "Tạo website mới" (khóa theo gói khi hết chỗ).
     · Chọn website khác = mở trang quản trị của website đó (như D0-4a, Việc cho backend).
   - Chỉ 1 website: không thêm "Tạo website mới" vào menu người dùng. Ghi TODO.md "Tạo website mới khi chỉ có 1 website: đặt ở Cài đặt › Gói & mức dùng, cạnh số website còn được tạo · D3-2".
   - Tweak: đổi Tweak có sẵn "Số website của người dùng (1/3)" thành 1 · 3 · 8. Không thêm Tweak mới trùng việc.
5. Tài liệu:
   - README D0-4: sửa các dòng nói đã bỏ 3 nút và quy tắc bộ chuyển cũ; ghi thứ tự topbar ở điểm 1, chỗ đặt bộ chuyển ở điểm 4, và ngoại lệ tiêu chí 4.
   - LESSONS.md, 2 dòng:
     · "06/10 owner giữ nút Ngôn ngữ giao diện, Toàn màn hình, Sáng/tối trên topbar (≥ 768px); prompt D0-4a gỡ là sai".
     · "Nút Ngôn ngữ giao diện mặc định dạng cờ (thiết kế gốc), Tweak Mã để so — luật 'Không dùng cờ quốc gia' (CLAUDE.md §2) chỉ áp cho thanh ngôn ngữ NỘI DUNG".

Xong khi:
(1) 1024, 1280 và 1440 × 3 bố cục: topbar đúng thứ tự ở điểm 1; 3 nút bấm được và đổi trạng thái thật; ô tìm ≥ 240px, kể cả bật Chữ lớn.
(2) 768 và 844×390: đúng điểm 1–2; nút Toàn màn hình ẩn khi trình duyệt không hỗ trợ.
(3) 360 và 390: topbar chỉ còn các nút ở điểm 2, mọi nút ≥ 44px, `scrollWidth = clientWidth`.
(4) Tweak số website = 1: không có bộ chuyển ở đâu cả. = 3 và = 8: đúng chỗ theo từng bố cục ở điểm 4, chỉ một bộ chuyển trên màn; = 8 có ô tìm.
(5) Mặt Nền tảng, và lúc đang đăng nhập dưới tên khách hàng: không có bộ chuyển.
(6) Bàn phím: Esc đóng danh sách ngôn ngữ và danh sách website, rồi focus quay về nút đã mở.
(7) Grep 0 lần `czTriggerStyle` · `czPlane` · `czBrand`; còn `toggleScheme` và `toggleFullscreen` và đều được markup dùng.
Đo không được thì ghi [CHƯA KIỂM ĐƯỢC], Claude Code kiểm bằng zip.
