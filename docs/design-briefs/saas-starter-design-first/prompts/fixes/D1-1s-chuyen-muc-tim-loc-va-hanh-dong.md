# D1-1s · Chuyên mục — tìm, lọc, chia tầng hành động, quyền và quy trình duyệt theo nhận xét của owner (dán vào chat D1-1)

Sửa thêm trên bản D1-1 đã xuất zip, trong cùng chat. Sửa xong thì xuất zip lại. Prompt này THAY mục 1 và dòng "Xong khi (1)" của D1-1 ở mọi chỗ khác nhau: bỏ "Lọc (… · cấp)", bỏ "Đưa vào trong…".

Owner xem `Chuyên mục.dc.html` và chưa ưng ở 3 điểm:
- chưa có tìm kiếm và bộ lọc;
- thao tác hay dùng phải thấy và bấm được ngay;
- chỉ thao tác nâng cao mới vào menu "⋯ Khác".

Owner cũng yêu cầu rà kỹ từng nút theo 12 tiêu chí và Chất lượng hoàn thiện A–E, kể cả quyền và quy trình duyệt. Claude Code đã dựng bản zip 23:44 trong Chromium, đối chiếu với repo và với `Trình đơn.dc.html` (màn cây đã chốt), và cho một người rà độc lập phản biện. Mỗi quyết định dưới đây đã qua phản biện; phần "vì" giải thích lý do để không đổi ngược lại.

Cách sửa:
- SỬA TẠI CHỖ: giữ panel Sửa/Thêm, hộp Xóa (đã tốt), dữ liệu mẫu, luật as-built, hộp "Chuyển đến…" sẵn có và câu lý do khóa đang hiện ngay dưới mục bị khóa trong menu.
- Chỉ sửa `Chuyên mục.dc.html`, `README - D1-1 Chuyên mục.md`, `TODO.md`, `LESSONS.md`.

1. **Thanh công cụ trên cây.** Luôn hiện, kể cả khi chỉ có 1 chuyên mục; chỉ ẩn khi cây trống (đã có trạng thái "Tạo chuyên mục đầu tiên"). Cách cư xử chép theo Trình đơn: một nút "Bỏ lọc" xóa cả chữ tìm lẫn chip; một dải ghi chú dưới thanh khi đang lọc. Kích thước theo cảm ứng: mọi ô, chip, nút ≥ 44px khi `pointer: coarse` ở MỌI bề rộng.
   - **Ô tìm** "Tìm chuyên mục…" (có tên cho trình đọc màn hình):
     - Khớp tên ở mọi ngôn ngữ đang bật và đường dẫn.
     - Không phân biệt dấu, và "đ" khớp "d": "khuyen mai" ra "Khuyến mãi", "dao tao" ra "Đào tạo".
     - Khớp ở ngôn ngữ khác thì ghi thêm dòng nhỏ "EN: Machining…".
     - Kết quả là danh sách phẳng; mỗi dòng có đường cha phía trước, không tính gốc ("Kiến thức gia công ›"); tô đậm phần chữ khớp.
     - Không có kết quả thì hiện "Không có chuyên mục nào khớp "…"" + nút "Bỏ lọc".
   - **Chip lọc nhanh**, chọn 1; mỗi chip có biểu tượng + chữ + số, số tính trên cả cây:
     - "Tất cả 8" · "Chưa có bài 1" · "Chưa dịch 3".
     - "Chưa dịch" gồm bản trống, thiếu trường, lỗi thời và AI vừa dịch chưa kiểm. Ẩn chip này khi website có 1 ngôn ngữ.
     - Vì sao không dùng "Lọc ▾" nhiều nhóm và không lọc theo Cấp: cây chỉ có 2 câu hỏi thật ("mục nào trống?", "mục nào chưa dịch?"); 1 chạm dễ hơn mở bảng chọn; cấp đã thấy qua thụt lề.
   - **Bên trái thanh:** "8 chuyên mục".
   - **Khi đang tìm hoặc lọc:** dải ghi chú "Đang xem N / M chuyên mục lọc ra từ cả cây — bỏ lọc để sắp xếp lại thứ tự · Bỏ lọc".
   - **Thu gọn / mở:** 1 nút đổi chữ, thay 2 nút đang có (vì trên 390, 2 nút đang chiếm 2 hàng 44px).
     - Ghi "Mở tất cả" khi có mục đang gập, còn lại ghi "Thu gọn tất cả".
     - Ẩn nút khi đang lọc, hoặc khi không mục nào có mục con.
   - **Khi đang tìm hoặc lọc:**
     - Chỉ ẩn tay cầm kéo.
     - Chỉ khóa Chuyển lên / Chuyển xuống / Alt+↑↓, kèm câu "Bỏ lọc để sắp xếp lại thứ tự".
     - "Chuyển đến…", Gộp, Sửa, + Mục con, Xóa vẫn dùng được (giống Trình đơn).
   - **Điện thoại** (khung chứa < 600px): ô tìm rộng hết hàng; chip xuống dòng, không cuộn ngang.
   - **Không làm "Chọn nhiều":** cây thường dưới 30 mục, mỗi thao tác đã ≤ 2 bước, Trình đơn cũng không có. Ghi README "Chưa làm — thêm khi số liệu thật cho thấy cần".
2. **Dòng trên máy tính và tablet** (khung chứa ≥ 720px), từ trái sang phải:
   - Tay cầm ⠿: chỉ khi có chuột; dòng gốc không có tay cầm.
   - Nút mở/gập.
   - Tên (bấm là mở Sửa) + đường dẫn mờ bên dưới.
     - Tên dài xuống tối đa 2 dòng rồi mới "…".
     - Tên nút đọc được: "<tên>, cấp N, 24 bài".
     - Không đẩy số bài hay nút xuống dòng.
   - Nhãn "Gốc" giữ nguyên.
   - **Số bài là liên kết** "24 bài":
     - Mở danh sách bài đã lọc ĐÚNG những bài được đếm (cùng luật đếm ở D1-1 mục 6), qua `bs-goto`. Params gồm loại nội dung + đường dẫn ở ngôn ngữ mặc định, không dùng id.
     - 0 bài thì là chữ mờ "Chưa có bài", không phải liên kết, vì liên kết tới danh sách rỗng là ngõ cụt.
     - Sản phẩm ghi "12 sản phẩm" / "Chưa có sản phẩm".
     - Việc cho backend: danh sách bài hiện CHỈ lọc theo chuyên mục gốc của loại (`app/Modules/Admin/Livewire/Entries/Index.php:850-866`), nên bài ở chuyên mục con không hiện ở danh sách nào. Cần lọc theo từng chuyên mục.
   - **Trạng thái ngôn ngữ bằng CHỮ** (website nhiều ngôn ngữ), thay dãy chip VI/EN/JA trên dòng:
     - Một nút chữ mờ: "Đủ 3 ngôn ngữ", hoặc nêu cụ thể "Thiếu JA", "Thiếu JA · EN lỗi thời". Bấm là mở panel Sửa ở ngôn ngữ đầu tiên còn thiếu.
     - Vì chip chỉ khác nhau bằng màu (WCAG 1.4.1, tooltip chỉ hiện khi rê chuột), và 8 ngôn ngữ thì không đủ chỗ.
     - Chấm màu giữ trong panel Sửa đúng như CLAUDE.md §3 của project.
   - **Nút "Sửa"** có chữ, HIỆN SẴN.
   - **Nút "+ Mục con"** có chữ, thay nút "+" chỉ có biểu tượng.
     - Mục cấp 3 không có nút này, chỗ trống giữ nguyên để cột thẳng hàng.
     - Lý do nằm ở mục khóa trong "⋯ Khác", vì 1 nút khóa lặp trên mọi dòng cấp 3 là nhiễu.
   - Nút "⋯ Khác".
   - Khung 600–719px: "+ Mục con" chuyển lên đầu menu "⋯ Khác", "Sửa" vẫn hiện.
   - **Dòng gốc** (Tin tức, Dịch vụ…): có Sửa, + Mục con, "N bài", "⋯ Khác"; không có tay cầm.
3. **Menu "⋯ Khác"** gồm các nhóm cách nhau bằng vạch ngăn. Trên máy tính menu không lặp lại nút đã hiện sẵn trên dòng:
   - Xem trên website (mở tab mới) · Sao chép đường dẫn.
     - Sao chép xong có toast "Đã sao chép". Không chép được thì hiện ô chứa địa chỉ đã bôi đen sẵn.
     - Vì chủ shop hay dán link chuyên mục vào Zalo / Facebook.
   - Chuyển lên (Alt+↑) · Chuyển xuống (Alt+↓) · Chuyển đến…
     - "Chuyển đến…" thay cả "Đưa vào trong…" lẫn "Đưa ra ngoài một cấp", và dùng lại hộp chọn chỗ đã có.
     - Tiêu đề hộp "Chuyển "Vật liệu" đến đâu?". Chỗ đặt liệt kê theo cây.
     - Trên màn của một loại nội dung, chỗ cao nhất là "Ngay dưới Tin tức". "Cấp ngoài cùng" CHỈ có ở Sản phẩm.
       - Vì chuyên mục ra khỏi gốc thì không thuộc loại nào: không màn nào hiện nó, và bài của nó rơi khỏi mọi danh sách.
       - Kéo ra ngang hàng gốc = chỗ thả không hợp lệ.
       - Alt+← ở cấp 2 báo "Đã ở cấp ngoài cùng của Tin tức".
     - Chỗ không đủ cấp thì khóa, kèm lý do "Chỉ được lồng tối đa 3 cấp".
     - Chỗ thuộc loại nội dung khác xếp dưới tiêu đề nhóm của loại đó, CHỈ những loại người dùng có quyền. Khi loại hiện tại đang bật quy trình duyệt và nhánh có bài, các chỗ của loại khác KHÓA kèm lý do (mục 5).
     - Vì 1 lệnh rõ nghĩa dễ hiểu hơn 2 lệnh "vào trong / ra ngoài" với người lớn tuổi; phím Alt+→ / Alt+← vẫn giữ như Trình đơn.
   - Gộp vào chuyên mục khác…
     - Không gộp được vào chính mục con của nó.
     - Hộp gộp nói rõ mục con đi đâu: "2 mục con chuyển lên dưới "Tin tức" (không vào "X")". Repo xóa chỉ đưa mục con lên cấp trên hoặc xóa theo (`app/Modules/Cms/Actions/Taxonomies/DeleteTaxonomy.php:31-33`).
     - Hộp gộp không có lựa chọn "Xóa theo".
   - Xóa…: luôn cuối, chữ và biểu tượng màu đỏ (token danger, đo tương phản ≥ 4,5:1 cả sáng lẫn tối).
   - **Chuyên mục gốc:** menu chỉ có nhóm Xem + 1 dòng chữ "Chuyên mục gốc của Tin tức: luôn ở cấp 1, không chuyển, gộp hay xóa được — đổi tên thì bấm Sửa." Bỏ 6 mục khóa đang hiện, vì lặp cùng một lý do 6 lần.
   - **Điện thoại:** "⋯ Khác" mở bảng chọn trượt từ đáy, tiêu đề là tên chuyên mục. Thứ tự: + Mục con · Xem 24 bài | Xem trên website · Sao chép đường dẫn | Chuyển lên · Chuyển xuống · Chuyển đến… | Gộp… | Xóa…. Với gốc: + Mục con · Xem bài | nhóm Xem | dòng lý do.
4. **Dòng trên điện thoại** (khung < 600px):
   - Tên + 1 dòng phụ "/kien-thuc · 24 bài". Dòng phụ chỉ thêm "· Thiếu JA" khi còn thiếu bản dịch, để không bị gãy.
   - Nút chữ "Sửa" 44px HIỆN SẴN + nút "⋯ Khác" 44px.
   - Chạm vào chỗ trống của dòng cũng mở Sửa. Đo bản 23:44: chỉ chữ tên 21px bấm được, chạm chỗ trống của dòng không làm gì.
5. **Đổi chỗ, đổi cha, gộp và xóa: chỉ hỏi khi có hệ quả thật, và nói bằng số.**
   - **Đổi thứ tự trong cùng cha** (kéo, Chuyển lên/xuống, Alt+↑/↓): làm ngay, không hỏi.
     - Toast "Đã chuyển" có "Hoàn tác" (aria-live), đứng ≥ 8 giây, dừng đếm khi rê chuột hoặc focus vào. Ctrl+Z hoàn tác như Trình đơn.
     - Bấm Alt+↑ nhiều lần liền thì chạy lần lượt, không mất lần nào.
   - **Đổi cha trong cùng loại** (kéo, Chuyển đến…, ô "Chuyên mục cha" trong panel):
     - Nếu kiểu đường dẫn bài của loại có chứa chuyên mục và nhánh có bài: hỏi 1 lần "Đổi địa chỉ 14 bài và tự tạo chuyển hướng để link cũ vẫn mở được".
     - Website đường dẫn phẳng (mặc định) thì đổi cha không đổi địa chỉ bài nào (`app/Modules/Cms/Routing/CategoryRelinker.php:24-25`): làm ngay + toast có "Hoàn tác".
     - Thêm Tweak "Kiểu đường dẫn bài: phẳng / có chuyên mục" để vẽ đủ 2 trường hợp.
     - Số bài đổi địa chỉ cần truy vấn xem trước: ghi Việc cho backend.
   - **Ra khỏi loại nội dung** (đổi cha, gộp, hay xóa kèm chuyển bài / "Bỏ gắn" làm bài không còn thuộc loại cũ). Owner chốt 07/10: bài đang theo quy trình duyệt KHÔNG được rời loại nội dung của nó. Cùng luật với trình soạn bài: `app/Modules/Admin/Livewire/Entries/Editor.php::keepsGovernedSection`, câu repo `entries.category_governed`.
     - **Loại hiện tại đang bật quy trình duyệt và nhánh có bài: CHẶN, không cho xác nhận để vượt.**
       - Trong "Chuyển đến…", ô "Chuyên mục cha", danh sách đích của Gộp / Xóa: mọi chỗ thuộc loại khác khóa.
       - Kéo sang cây loại khác là chỗ thả không hợp lệ.
       - Trong hộp Xóa, lựa chọn "Bỏ gắn" khóa.
       - Lý do đọc được ngay tại chỗ: "Tin tức đang bật quy trình duyệt nên bài không chuyển sang loại khác được. Chọn chuyên mục trong Tin tức, hoặc nhờ người quản trị tắt quy trình cho Tin tức nếu thật sự cần chuyển."
       - Nhánh không có bài thì chuyển tự do.
     - **Loại hiện tại không bật quy trình duyệt:** cho chuyển, kèm hộp xác nhận thêm các dòng sau, mỗi dòng CHỈ hiện khi đúng:
       - Khi 2 loại khác quyền hoặc khác quy trình duyệt: "N bài sẽ chuyển từ Tin tức sang Dịch vụ — quyền và quy trình duyệt sẽ theo Dịch vụ."
       - Khi Dịch vụ đang bật quy trình duyệt: "K bài chưa đăng sẽ bắt đầu từ bước đầu của quy trình Dịch vụ; bài đã đăng vẫn hiện trên website." Repo: `app/Modules/Cms/Support/EntryWorkflowResolver.php::originFor`.
       - Làm xong, dòng rời khỏi cây này: toast "Đã chuyển sang Dịch vụ · Mở Chuyên mục Dịch vụ", focus về dòng kế tiếp.
     - Việc cho backend:
       - ĐÃ LÀM (PR #207, 07/10): `MoveTaxonomy` / `DeleteTaxonomy` từ chối bằng mã `workflow_governed` khi loại đang bật quy trình duyệt, kể cả khi đã xác nhận; không vượt được. Còn thiếu câu riêng cho mã này ở màn Chuyên mục: thêm vào README mục Chuỗi cần dịch.
       - Số N, K cần truy vấn xem trước.
   - **Chuyên mục không qua quy trình duyệt** (repo không có trạng thái cho chuyên mục): mọi thay đổi có hiệu lực ngay và ghi nhật ký. Ghi dòng này vào README phần luật.
   - **Lỗi:**
     - Người khác vừa đổi cây (`taxonomy_invalid`): câu đời thường + nút "Tải lại".
     - Lưu hỏng hoặc mạng chậm: giữ nguyên chữ đã gõ, báo bằng câu đời thường + "Thử lại"; dòng đã kéo về chỗ cũ.
   - **Điện thoại:** hộp Xóa và hộp Gộp mở toàn màn hoặc trượt từ đáy, nút ghim ở đáy.
6. **Quyền theo loại nội dung, gói và phiên hỗ trợ** (Tweak đổi được, đọc registry thật):
   - Màn cần quyền `taxonomies.manage`. Mặc định Chủ website, Quản trị, Biên tập có quyền này (`app/Modules/Rbac/Registry/PermissionRegistry.php:191-197`).
     - Thiếu quyền thì menu không hiện mục Chuyên mục (`app/Modules/Admin/Navigation/AdminNav.php:117`).
     - Mở thẳng địa chỉ màn thì hiện "Bạn chưa có quyền quản lý chuyên mục — nhờ Chủ website hoặc Quản trị cấp quyền". Không hiện cây.
   - Quyền bài đã tách theo loại nội dung (`content_sections.permission_prefix`, `AdminNav.php:83`):
     - "Chuyển đến…", ô "Chuyên mục cha" và chuyên mục đích khi xóa / gộp chỉ liệt kê loại mà người dùng có quyền.
     - Việc cho backend (làm ngay, không phải "sau này"): chuyển bài sang loại khác phải kiểm quyền ở CẢ hai loại. `MoveTaxonomy.php` để việc kiểm quyền cho nơi gọi.
   - Liên kết "N bài" mở danh sách bài; danh sách đó tự giới hạn theo quyền xem của người bấm.
   - Phiên hỗ trợ (nhân viên đăng nhập thay thành viên):
     - Tìm, lọc, xem vẫn dùng được.
     - Mọi nút ghi (Thêm, Lưu, + Mục con, Chuyển…, Gộp, Xóa, kéo) khóa, kèm câu repo `admin.support.locked`.
   - Sản phẩm khi module tắt theo gói: dùng trạng thái khóa theo gói đã có.
7. **Panel Sửa / Thêm** (giữ bố cục):
   - Thanh ngôn ngữ đang cắt chữ "Tiếng" ở panel 480px: hiện đủ tên, hoặc mã + chấm. Không cắt chữ ở 360 và ở cỡ chữ Rất lớn (CLAUDE.md §4: không bao giờ để pill bị cắt nửa chữ).
   - Hộp vàng "Đổi đường dẫn… tự tạo chuyển hướng" chỉ hiện khi ô Đường dẫn ĐÃ khác bản đã lưu.
   - Khi Sửa, đổi tên KHÔNG tự đổi đường dẫn; muốn đổi thì bấm "Tạo lại từ tên".
   - Ô "Chuyên mục cha":
     - Nhóm theo loại nội dung (`<optgroup>`), chỉ loại người dùng có quyền.
     - Chọn chỗ của loại khác thì khi Lưu hiện hộp xác nhận ở mục 5.
   - Mở từ "+ Mục con": tiêu đề "Thêm mục con vào "Kiến thức gia công"", chọn sẵn đúng cha.
   - Chế độ Thêm có nút phụ "Lưu và thêm mục khác": giữ panel mở, xóa trống ô tên, giữ cha.
     - Vì lúc dựng web người dùng tạo 5–10 chuyên mục liền.
     - Ở 360, 3 nút xếp dọc: Lưu (rộng hết) · Lưu và thêm mục khác · Hủy.
   - Có thay đổi chưa lưu mà bấm × / Esc / Hủy, chuyển màn trong Shell (onDirty) hay đóng tab: hỏi "Bỏ thay đổi chưa lưu?" (Ở lại sửa · Bỏ thay đổi).
   - Lưu: nút báo đang lưu, chặn bấm 2 lần. Xong thì toast; dòng mới / dòng vừa sửa sáng nhẹ 2 giây; focus về dòng đó.
8. **Bàn phím và trình đọc màn hình** (giữ kiểu danh sách như Trình đơn; KHÔNG dùng `tree` / `treeitem`, vì dòng có nhiều nút bên trong):
   - Danh sách `role=list`; mỗi dòng đọc "<tên>, cấp N, 24 bài, Thiếu JA"; các nút đi theo Tab bình thường.
   - Khi focus ở tên dòng:
     - Alt+↑/↓ đổi thứ tự;
     - Alt+→ thành mục con của mục ngay trên;
     - Alt+← đưa ra ngoài một cấp (không ra khỏi gốc).
   - Esc đóng panel, menu hoặc hộp thoại, rồi trả focus về đúng nút đã mở nó.
   - Phím tắt nằm sau nút "(?) Phím tắt" ở thanh công cụ (chỉ khi có chuột / bàn phím), không rải dòng gợi ý dài dưới cây.
     - Dòng gợi ý ngắn dưới cây giữ: "Kéo ⠿ hoặc dùng ⋯ › Chuyển lên, Chuyển xuống, Chuyển đến… · Tối đa 3 cấp".
     - Trên điện thoại bỏ phần "Kéo ⠿".
   - Kéo thả:
     - Có vạch báo chỗ thả; báo đỏ "Chỉ được lồng tối đa 3 cấp" ngay khi rê vào chỗ không hợp lệ.
     - Tự cuộn khi kéo gần mép.
     - Tắt chuyển động khi `prefers-reduced-motion`.
   - Viền focus và các phần giao diện có nghĩa (chip, nút viền) tương phản ≥ 3:1.
9. **Sản phẩm** dùng đúng màn này: chữ "sản phẩm" ở mọi chỗ ("Chưa có sản phẩm", "Xem 12 sản phẩm"); không có gốc; "Cấp ngoài cùng" có trong "Chuyển đến…"; không có dòng quyền / quy trình duyệt.
10. **Kéo thả trong cây: chuẩn chung cho Chuyên mục, Trình đơn và danh sách Trang** (owner 07/10). Người kéo phải thấy rõ mục sẽ nằm cùng cấp, vào trong hay ra ngoài; nhả ra là đúng ngay, không phải thử lại nhiều lần. Hiện 3 màn kéo thả 3 kiểu khác nhau, nên dùng đúng một kiểu này:
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
11. **README** thêm mục "D1-1s", gồm:
    - bảng quyết định: từng nút / chức năng · chọn gì · phương án bỏ · lý do;
    - bảng hành động 3 cột: hiện sẵn · trong "⋯ Khác" · điện thoại;
    - bảng quyền: có quyền · không quyền · phiên hỗ trợ · khóa theo gói;
    - kết quả 12 tiêu chí và A–E kèm số đo;
    - Việc cho backend: lọc bài theo từng chuyên mục; xem trước số bài đổi địa chỉ và số bài chưa đăng khi chuyển; kiểm quyền 2 loại khi chuyển bài; mã `workflow_governed` chặn bài rời loại đang bật quy trình duyệt.
    - Ghi cho owner: chấm ngôn ngữ ở CLAUDE.md §3 chỉ khác nhau bằng màu, tooltip chỉ hiện khi rê chuột. Đề xuất thêm hình hoặc chữ ở một lượt component; owner chốt.

Xong khi:
(1) Với 1 chuyên mục, ô tìm và chip vẫn hiện. Tìm "khuyen mai" ra "Khuyến mãi" có đường cha (không có gốc). Chip "Chưa có bài" ra đúng số. Khi đang lọc: có dải "Đang xem N / M… · Bỏ lọc", tay cầm ẩn, Chuyển lên khóa kèm lý do, "Chuyển đến…" vẫn dùng được.
(2) 1280: mỗi dòng không phải gốc thấy "Sửa", "+ Mục con" (trừ cấp 3), "N bài" (0 = "Chưa có bài"), trạng thái ngôn ngữ bằng chữ, "⋯ Khác". Menu đúng nhóm, đúng thứ tự, "Xóa…" cuối màu đỏ. Menu gốc chỉ có nhóm Xem + 1 dòng lý do. Tweak 8 ngôn ngữ: dòng không tràn, không cắt chữ.
(3) 390: dòng = tên + dòng phụ không gãy + "Sửa" + "⋯ Khác"; chạm chỗ trống của dòng mở Sửa; "⋯ Khác" là bảng trượt từ đáy. Quét vùng bấm ≥ 44px ở 390 VÀ ở 768 cảm ứng = 0 lỗi.
(4) "Chuyển đến…" trên Tin tức không có "Cấp ngoài cùng"; trên Sản phẩm thì có. Alt+← ở cấp 2 báo "Đã ở cấp ngoài cùng của Tin tức".
(5) Tweak đường dẫn phẳng: đổi cha không hỏi, có toast "Hoàn tác". Tweak đường dẫn có chuyên mục: hỏi kèm số bài đổi địa chỉ. Tweak Tin tức BẬT quy trình duyệt: "Chuyển đến…" / Gộp / Xóa → mọi chỗ của Dịch vụ khóa kèm lý do, "Bỏ gắn" khóa, kéo sang loại khác bị từ chối. Tweak Tin tức TẮT quy trình: chuyển sang Dịch vụ được, hộp xác nhận đủ các dòng ở mục 5 kèm số, xong có toast "Mở Chuyên mục Dịch vụ".
(6) Tweak "Người viết": trạng thái không có quyền. Phiên hỗ trợ: mọi nút ghi khóa kèm câu khóa, ô tìm vẫn gõ được. Lưu hỏng: chữ đã gõ còn nguyên, có "Thử lại". `taxonomy_invalid`: có "Tải lại".
(7) Bàn phím: Esc đóng panel và focus về đúng nút. Alt+↑ đổi chỗ. Panel có thay đổi chưa lưu mà bấm Esc thì có câu hỏi "Bỏ thay đổi chưa lưu?". Thanh ngôn ngữ trong panel không cắt chữ ở 360 và ở cỡ chữ Rất lớn.
(8) `_audit/harness.html` ở 360 / 390 / 768 / 1024 / 1440, cỡ chữ Vừa và Rất lớn, đứng riêng và trong Shell, đạt cả 3 điều:
   - `scrollWidth = clientWidth`;
   - tương phản chữ ≥ 4,5:1, phần giao diện và focus ≥ 3:1, ở cả sáng lẫn tối;
   - mở lại tệp và Admin Shell: console 0 lỗi.
   Tệp đo tạm chỉ đặt trong `_audit/tmp/`.
(9) Kéo "Khuyến mãi" lên nửa giữa dòng "Tin tức": dòng giữ chỗ thụt vào 1 bậc, nhãn "Thành mục con của "Tin tức" · cấp 2"; nhả ra đúng chỗ đó, có "Hoàn tác". Kéo một mục cấp 3 vào trong một mục cấp 3: dòng giữ chỗ đỏ, nhãn "Tối đa 3 cấp", nhả ra không đổi gì. Esc trong lúc kéo trả mục về chỗ cũ.

Xuất zip project gửi Claude Code kiểm. Xong thì báo owner đúng một câu: "D1-1s xong — gửi zip cho Claude Code."
