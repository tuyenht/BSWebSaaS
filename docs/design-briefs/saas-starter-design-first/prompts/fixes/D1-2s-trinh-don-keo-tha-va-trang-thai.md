# D1-2s · Trình đơn — kéo thả nhìn rõ cấp, hộp xác nhận đúng chuẩn, đủ trạng thái (dán vào chat D1-2)

Sửa thêm trên bản Trình đơn hiện có (bản đã qua D1-2 và các lượt sau), trong cùng chat. Sửa xong thì xuất zip lại.

Owner xem màn Trình đơn và hỏi:
- "Xử lý làm sao để người dùng nhìn rõ ràng khi di chuyển là đang di chuyển trong ra ngoài, hay đang cùng cấp, vào / ra cấp; nhả ra là hiển thị chuẩn luôn; tránh mất nhiều thời gian kéo / thả mà không như ý."
- "Có nên để thêm tick chọn giống danh sách Trang không?"

Claude Code đã dựng màn bằng Chromium (zip D3-1 08:41) và đọc mã.

**GIỮ nguyên, không đổi chỗ:**
- thanh tìm + chip "Tất cả · Cần xem · Đang ẩn";
- "Thu gọn hết", Hoàn tác / Làm lại, Ctrl+Z;
- nút ↑ ↓ và "⋯" trên từng dòng;
- nhãn loại ("Trang", "Sản phẩm", "Liên kết") và nhãn lỗi ("Trang không còn"…);
- "Gỡ khỏi trình đơn" (vào Thùng rác 30 ngày, đứng cuối, màu đỏ).

**KHÔNG thêm ô chọn (tick) trên từng dòng.** Danh sách Trang có ô chọn vì người dùng hay làm cùng lúc trên nhiều trang (đăng, chuyển vào Thùng rác). Trình đơn thường chỉ 5–30 mục và là cây sắp xếp, giống Chuyên mục (cũng không có ô chọn). Ô chọn sẽ chen giữa nút kéo ⠿ và tên, làm dòng rối hơn. Việc làm cùng lúc thật sự cần là dọn các mục hỏng, nên làm thành mục 4 bên dưới.

Chỉ sửa `Trình đơn.dc.html`, `README - D1-2 Trình đơn.md`, `TODO.md`, `LESSONS.md`.

1. **Kéo thả trong cây: chuẩn chung cho Chuyên mục, Trình đơn và danh sách Trang** (owner 07/10). Người kéo phải thấy rõ mục sẽ nằm cùng cấp, vào trong hay ra ngoài; nhả ra là đúng ngay, không phải thử lại nhiều lần. Hiện 3 màn kéo thả 3 kiểu khác nhau, nên dùng đúng một kiểu này:
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
   Riêng Trình đơn:
   - tối đa **5 cấp** (repo `MenuRules::MAX_DEPTH = 5`). Nhãn khi vượt: "Tối đa 5 cấp".
   - Bỏ các ô "Thả vào đây" và vùng thả cuối danh sách đang có. Dòng giữ chỗ thay cho cả hai.
2. **Hộp xác nhận (`askOn`) đúng chuẩn hộp thoại.** Esc đã đóng được hộp này (giữ nguyên), nhưng:
   - hộp không có `role="dialog"` hay tên đọc;
   - focus không vào hộp, Tab chạy ra ngoài, và khi đóng focus không về lại nút đã mở.
   Sửa: `role="dialog" aria-modal="true" aria-labelledby` trỏ vào tiêu đề; mở thì focus vào nút an toàn ("Hủy"); Tab chỉ chạy trong hộp; đóng (Esc, "Hủy", bấm nền mờ) thì focus về đúng nút đã mở.
3. **Đủ trạng thái** (hiện màn chỉ có trạng thái có dữ liệu):
   - **Đang tải:** khung xương 5 dòng, giữ chỗ cho thanh công cụ.
   - **Lỗi tải:** "Chưa tải được trình đơn. Kiểm tra mạng rồi thử lại." + nút "Thử lại".
   - **Lưu lỗi:** giữ nguyên thay đổi trên màn, báo "Chưa lưu được — thử lại", không mất gì.
   - **Không có quyền:** repo dùng quyền `menus.manage`; thiếu quyền thì mục "Trình đơn" ẩn khỏi menu trái. Mở thẳng bằng địa chỉ thì hiện "Bạn chưa có quyền sửa trình đơn. Hỏi chủ website nếu cần." + nút "Về Tổng quan".
   - **Phiên hỗ trợ:** mọi nút ghi khóa, kèm câu khóa chung.
   - Thêm Tweak "Trạng thái màn" để xem từng trạng thái.
4. **Dọn mục hỏng một lần:** khi chip "Cần xem" đang bật và có ≥ 2 mục trỏ tới nội dung không còn, hiện 1 nút viền (không phải nút chính) "Gỡ N mục không còn".
   - Bấm thì hỏi, nói rõ số và tên: "Gỡ 3 mục: Kiểm tra chất lượng, Phụ tùng thay thế, … khỏi trình đơn? Vào Thùng rác 30 ngày."
   - Gỡ xong có "Hoàn tác".
   - Mục "Sản phẩm chưa đăng" KHÔNG nằm trong số này, vì sản phẩm vẫn còn.
5. **Tên đọc cho nút chỉ có biểu tượng:** ở khung < 380px, nút "Xem trên website" (`mn-btn`, chữ `mn-blab` bị ẩn bằng `@container`) chỉ còn biểu tượng và không có tên đọc. Thêm `aria-label` + `title` "Xem trên website". Rà thêm mọi nút khác có chữ bị ẩn ở khung hẹp.
6. **Vùng bấm:** nút "Hoàn tác" trong thông báo đang cao 30px; mọi nút bấm ≥ 44 × 44px ở khung < 1024px.
7. **README:**
   - mục "D1-2s" với bảng "Màn đang chạy · Bản mới · Giữ bản nào · Vì sao";
   - câu trả lời cho câu hỏi ô chọn (không thêm, lý do như trên);
   - "Cần owner chốt": "Có cần ô chọn để ẩn / gỡ nhiều mục cùng lúc không".

Xong khi:
(1) 1280: kéo "Fanpage" lên nửa giữa "Chính sách bảo hành" thì dòng giữ chỗ thụt vào 1 bậc, nhãn "Thành mục con của "Chính sách bảo hành" · cấp 2"; nhả ra đúng chỗ đó, có "Hoàn tác". Kéo lên 1/4 trên thì nhãn "Đặt trước …", không thụt. Không còn ô "Thả vào đây".
(2) Kéo một nhánh làm tổng số cấp vượt 5: dòng giữ chỗ đỏ, nhãn "Tối đa 5 cấp", nhả ra không đổi gì. Esc khi đang kéo trả mục về chỗ cũ.
(3) Hộp xác nhận: trình đọc màn hình đọc được tên hộp; Esc vẫn đóng; Tab không thoát ra ngoài hộp; đóng xong focus về đúng nút. Ở 360, nút "Xem trên website" có tên đọc.
(4) Tweak "Trạng thái màn": đủ Đang tải · Lỗi tải · Lưu lỗi · Không có quyền · Phiên hỗ trợ. Chip "Cần xem" có "Gỡ N mục không còn"; gỡ xong có "Hoàn tác".
(5) Không có ô chọn trên dòng. Mọi nút chỉ có biểu tượng đều có tên đọc. Ở 390, mọi vùng bấm ≥ 44px.
(6) `_audit/harness.html` ở 360 / 390 / 768 / 1024 / 1440, chữ thường và Chữ lớn, đứng riêng và trong Shell (`#/site/menus`), đạt cả 3 điều:
   - `scrollWidth = clientWidth`;
   - tương phản ≥ 4,5:1 cả sáng lẫn tối;
   - mở lại tệp và Admin Shell: console 0 lỗi.
   Tệp đo tạm chỉ ở `_audit/tmp/`, xóa sạch trước khi xuất zip.

Xuất zip project gửi Claude Code kiểm. Xong thì báo owner đúng một câu: "D1-2s xong — gửi zip cho Claude Code."
