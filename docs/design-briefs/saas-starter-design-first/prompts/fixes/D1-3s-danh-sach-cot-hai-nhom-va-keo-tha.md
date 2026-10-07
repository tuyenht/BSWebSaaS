# D1-3s · Danh sách Bài viết, Trang, Sản phẩm — trả lại menu Cột chia 2 nhóm, kéo thả cây Trang theo chuẩn chung (dán vào chat D1-3)

Sửa thêm trên bản danh sách hiện có, trong cùng chat. Sửa xong thì xuất zip lại.

Owner xem danh sách Trang và nhận xét:
- Menu "Cột": "hiển thị cột chưa ưng như thiết kế lúc đầu — chia bắt buộc và tuỳ chọn riêng ra" như `Quản lý Trang - Options.dc.html`.
- Kéo thả: "làm sao để người dùng nhìn rõ ràng khi di chuyển là đang di chuyển trong ra ngoài, hay đang cùng cấp, vào / ra cấp; nhả ra là hiển thị chuẩn luôn".

Claude Code đã đối chiếu:
- Màn đang chạy chia đúng 2 nhóm: chữ `entries.cols_required` "Cột bắt buộc" và `entries.cols_optional` "Tùy chọn".
- `Quản lý Trang - Options.dc.html` (thiết kế owner duyệt) cũng chia 2 nhóm.
- `Quản lý Bài viết - Options.dc.html` và `Sản phẩm - Danh sách.dc.html` lại dùng 1 nhóm "Cột hiển thị" với chữ "Luôn hiện" xen giữa các cột. Đây là thay đổi tự ý, trái luật (e) "giữ bố cục đã duyệt".

Chỉ sửa `Quản lý Bài viết - Options.dc.html`, `Sản phẩm - Danh sách.dc.html`, `README - D1-3 Danh sách nội dung.md`, `TODO.md`, `LESSONS.md`.

1. **Menu "Cột" chia 2 nhóm, đúng như `Quản lý Trang - Options.dc.html` và màn đang chạy**, ở cả 2 tệp:
   - nhóm "Cột bắt buộc": các cột luôn hiện (Tiêu đề · Trạng thái · Hành động; Sản phẩm thì Tên · Trạng thái · Hành động). Ô chọn đã tích nhưng mờ, có biểu tượng ổ khóa, không bấm được, `aria-disabled="true"`;
   - vạch ngăn;
   - nhóm "Tùy chọn": các cột còn lại, bấm để bật / tắt;
   - bỏ chữ "Luôn hiện" trên từng dòng và tiêu đề "Cột hiển thị";
   - giữ hành vi đang có: nhớ lựa chọn theo từng loại nội dung, nút "Cột" có số cột đang ẩn.
2. **Kéo thả cây Trang** (danh sách Trang ở chế độ cây; Bài viết không có cây):
   **Kéo thả trong cây: chuẩn chung cho Chuyên mục, Trình đơn và danh sách Trang** (owner 07/10). Người kéo phải thấy rõ mục sẽ nằm cùng cấp, vào trong hay ra ngoài; nhả ra là đúng ngay, không phải thử lại nhiều lần. Hiện 3 màn kéo thả 3 kiểu khác nhau, nên dùng đúng một kiểu này:
   - **Dòng giữ chỗ = kết quả.** Khi kéo, một dòng giữ chỗ (viền nét đứt màu nhấn, cao bằng một dòng thật) hiện đúng vị trí và đúng thụt lề mà mục sẽ nằm khi nhả. Các dòng khác dịch ra nhường chỗ trong 150 ms (tắt khi máy đặt giảm chuyển động). Nhả ra thì mục nằm đúng chỗ dòng giữ chỗ, không nhảy đi đâu khác.
   - **Ba vùng trên mỗi dòng đích:**
     - 1/4 trên: cùng cấp, đặt trước dòng đó;
     - 1/2 giữa: vào trong, thành mục con. Dòng cha được viền nhấn và nền nhạt; nếu đang thu gọn thì tự mở sau 0,6 giây giữ chuột;
     - 1/4 dưới: cùng cấp, đặt sau dòng đó.
   - **Ra ngoài một cấp:** đang ở dưới mục con cuối cùng của một nhóm, kéo sang trái quá 1 bậc thụt lề thì dòng giữ chỗ lùi ra 1 cấp, thành cùng cấp với cha.
   - **Nhãn đi theo con trỏ, nói kết quả bằng lời:**
     - "Đặt trước "Giới thiệu" · cấp 1";
     - "Thành mục con của "Giới thiệu" · cấp 2";
     - "Ra ngoài nhóm "Dịch vụ" · cấp 1".
     Vùng `aria-live` đọc đúng câu đó.
   - **Chỗ không được thả:** quá số cấp tối đa, thả vào chính nó hay mục con của nó, hoặc bài đang theo quy trình duyệt rời loại nội dung. Khi đó dòng giữ chỗ đỏ, nhãn nêu lý do ("Tối đa 3 cấp"); nhả ra không đổi gì và báo lý do.
   - **Trong lúc kéo:** đến gần mép trên hay dưới thì tự cuộn; Esc hủy kéo, mục về chỗ cũ.
   - **Sau khi nhả:** dòng vừa chuyển sáng nhẹ 1 giây, và có thông báo "Đã chuyển "X" vào trong "Y" · Hoàn tác".
   - **Không bắt buộc phải kéo:** vẫn giữ ↑ ↓ và "Chuyển đến…" (chọn chỗ đích trong cây, có ô tìm), cho điện thoại, bàn phím và người lớn tuổi.
   - Khi đang tìm hoặc lọc, kéo thả tắt như luật đang có, và hiện câu lý do.
   Riêng danh sách Trang:
   - repo không giới hạn số cấp của trang. Chỉ chặn thả vào chính nó hoặc trang con của nó;
   - kéo thả chỉ bật khi danh sách đang xếp theo cây, không tìm, không lọc. Khi đang xếp theo cột khác thì nút kéo ⠿ ẩn, kèm 1 dòng "Xếp theo cây để kéo đổi vị trí";
   - kéo đổi cha thì đường dẫn trang đổi theo. Nhãn nói thêm điều đó: "Thành trang con của "Giới thiệu" · đường dẫn mới /gioi-thieu/doi-ngu". Nhả xong, thông báo nói tự chuyển địa chỉ cũ sang địa chỉ mới, có "Hoàn tác".
3. **Tương phản:** nhãn trạng thái "Đã hẹn giờ" đang 4,42:1 trên nền nhạt (đo bằng Chromium, chế độ sáng). Nâng lên ≥ 4,5:1 bằng token, không dùng mã màu riêng.
4. **README:** mục "D1-3s" với bảng "Màn đang chạy · Bản mới · Giữ bản nào · Vì sao" cho menu Cột và kéo thả. Thêm 1 dòng LESSONS: "Menu Cột chia "Cột bắt buộc" / "Tùy chọn" như màn đang chạy; không gộp làm một nhóm (owner 07/10)".

Xong khi:
(1) Bài viết, Trang, Sản phẩm: mở "Cột" thấy 2 nhóm "Cột bắt buộc" (mờ, có ổ khóa, không bấm được) và "Tùy chọn", có vạch ngăn; không còn chữ "Luôn hiện"; tắt một cột tùy chọn rồi tải lại vẫn nhớ.
(2) Trang ở chế độ cây, 1280: kéo "Đội ngũ" lên 1/4 trên của "Dịch vụ" thì nhãn "Đặt trước "Dịch vụ" · cấp 1", dòng giữ chỗ không thụt; kéo vào nửa giữa "Dịch vụ" thì nhãn "Thành trang con của "Dịch vụ" · cấp 2 · đường dẫn mới /dich-vu/doi-ngu". Nhả ra đúng chỗ dòng giữ chỗ, có "Hoàn tác".
(3) Kéo "Giới thiệu" vào trang con của chính nó: dòng giữ chỗ đỏ, nhả ra không đổi gì. Esc khi đang kéo trả về chỗ cũ. Xếp theo "Lượt xem": không có nút kéo, có câu "Xếp theo cây để kéo đổi vị trí".
(4) "Đã hẹn giờ" ≥ 4,5:1 cả sáng lẫn tối.
(5) `_audit/harness.html` ở 360 / 390 / 768 / 1024 / 1440, cỡ chữ Vừa và Rất lớn, đứng riêng và trong Shell (`#/site/posts.list`, `#/site/pages.list`, `#/site/catalog.list`), đạt cả 3 điều:
   - `scrollWidth = clientWidth`;
   - tương phản ≥ 4,5:1 cả sáng lẫn tối;
   - mở lại tệp và Admin Shell: console 0 lỗi.
   Tệp đo tạm chỉ ở `_audit/tmp/`, xóa sạch trước khi xuất zip.

Xuất zip project gửi Claude Code kiểm. Xong thì báo owner đúng một câu: "D1-3s xong — gửi zip cho Claude Code."
