# D0-2s5 · Sửa cuối Focus 1b, chốt D0-2 (dán vào một CHAT MỚI, SAU khi D0-3 xong)

Trước khi làm: đọc CLAUDE.md, LESSONS.md, AUDIT.md, `README - D0-2 Chuẩn giao diện.md` và `admin-base.css`. Prompt này tự đủ, không cần ngữ cảnh của chat D0-2 cũ.

Lượt D0-2s5: Claude Code kiểm bản zip 05/10 22:04 bằng Chromium thật. Số dòng dưới đây là của bản đó; D0-3 đã sửa trang chuẩn và README sau đó, nên số dòng lệch thì tìm theo nội dung được nêu. D0-2s4 đạt gần hết:
- trong khung 1b của trang Focus, Tab tới mọi phần tử có mẫu tĩnh thì khác 0 pixel, cả sáng lẫn tối (trừ liên kết);
- ô lỗi giữ đỏ; hết khung đôi;
- mọi chỉ báo đạt 4,55–9,7:1;
- hộp thoại dưới 768px, Sao chép, console đều đạt.

Còn 7 chỗ nhỏ. Làm xong là chốt D0-2.

**Được sửa:** `admin-base.css` (mục 1–4, 6); `Admin Shell.dc.html` (chỉ chỗ đặt `--focus-inner` cho thanh bên, mục 6); trang chuẩn `Chuẩn giao diện SaaS Starter.dc.html` (chỉ mục 5); `CLAUDE.md` (chỉ dòng 226, mục 2 và 7); `README - D0-2 Chuẩn giao diện.md` (dòng Verifier); `AUDIT.md` (mục 8).
**Không được:** sửa màn khác; thêm màu viết thẳng; thêm `outline: none` / `outline: 0`; đổi hình của các phần tử đã đạt.

1. **TB — Nút ghost vẽ vạch 3px thay vì 2px.** `.sx-btn.gho` (trang chuẩn) và `.b.gh` (trang bổ sung) khớp cả luật nút viền (`admin-base.css` dòng 41, đổi viền sang màu vòng) lẫn luật ghost (viền trong 2px), nên thành 3px. Lỗi lan sang các nút ghost dùng chung: nút đóng toast, nút Sao chép, nút ×. Luật nút viền không được áp lên ghost và nút đặc.
   - Kết quả cần đạt: Tab tới `.sx-btn.gho` và `.b.gh` → đúng một đường 2px như mẫu ghost; viền của nút giữ như lúc chưa focus.
   - `.b.dan` (trang bổ sung) là nút viền đỏ, giữ kiểu nút viền như hiện tại.

2. **Thấp — Nút "Chữ lớn" (`.b.bigsw`, `role="switch"`, trang bổ sung) có hai đường nhấn**: viền nút đổi màu, cộng thêm vòng tách.
   - Kết quả cần đạt: đúng một chỉ báo. Phần tử có `role="switch"` mà hình là nút có chữ thì vẽ như nút viền, không thêm vòng tách. Ghi quy định này vào câu điều khiển nhỏ của mô tả Focus 1b (CLAUDE.md dòng 226).

3. **Thấp — Liên kết mất gạch chân dày khi focus.** Trang ghi `text-decoration: underline` dạng rút gọn (trang Focus `.lnk`; 26 tệp màn khác cũng viết vậy), nên đè mất `text-decoration-thickness: 2px` của luật liên kết (dòng 38).
   - Kết quả cần đạt: Tab tới `.lnk` ở trang Focus → khác 0 pixel so với mẫu tĩnh; gạch chân 2px.

4. **Thấp — Ngày vừa là hôm nay vừa đã chọn mất viền "hôm nay" khi focus.** Luật nút đặc (dòng 57) đặt `border-color: transparent !important`.
   - Kết quả cần đạt: ngày đó khi focus vẫn thấy dấu hôm nay; các nút đặc khác giữ như hiện tại.

5. **TB — Hộp thoại 768–799px thành tấm trượt nổi giữa màn.** Ở bề rộng này, nó rộng 736–767px, có thanh kéo, chỉ bo góc trên, không sát đáy (iPad dọc 768px rơi đúng vào đây). Nguyên nhân: `.ov` có `padding: 16px` và là container, nên `@container (max-width:767px)` đo bề rộng nội dung (màn − 32px), không đo bề rộng màn.
   - Kết quả cần đạt (bề rộng cửa sổ trình duyệt): 767px trở xuống là tấm trượt đáy tràn bề rộng, sát đáy; 768px trở lên (thử ở 768, 780, 799, 800) là hộp thoại giữa màn như hiện tại. Đúng cho cả hai tấm trượt (Đặt lịch, Bộ lọc). Mẫu hộp thoại vẽ trong khung thiết bị ở trang chuẩn vẫn đổi theo bề rộng khung như hiện tại.

6. **Thấp — Chế độ tối:**
   - Khối tối của luật chung (dòng 116–130) không đặt `--color-admin-primary`. Vì vậy trang mở riêng ở chế độ tối lấy màu cũ #405189 của DS cho nút đặc, công tắc, đoạn chọn, ngày chọn. Thêm `--color-admin-primary: var(--brand-solid, #2050a0)` như khối sáng (dòng 105).
   - Shell tối: mục đang chọn ở thanh bên khi focus chỉ còn 1px vạch vàng, vì khe `--focus-inner` 2px (#16203a) phủ lên. Ở sáng còn 2px vì Shell bỏ khe cho thanh bên (Admin Shell dòng 1472, hiện chỉ khi không tối). Ở tối cũng bỏ khe cho thanh bên; màu vòng tối giữ như hiện tại.
   - Kết quả cần đạt: ở chế độ tối không còn #405189 ở nút đặc; vạch vàng của mục đang chọn khi focus rộng 2px như ở sáng; cạnh trên, phải, dưới của vòng vẫn ≥ 3:1 với nền thanh bên.

7. **Chữ:**
   - CLAUDE.md dòng 226: `&gt;` → `>`; bỏ "(tối `#96a1c0`)" sau primary/accent — nền dưới chữ trắng là `--brand-solid` (#2050a0) cả sáng lẫn tối, chữ/viền/vòng ở tối là `--brand-ink`.
   - README: dòng Verifier mới (xem cuối prompt) chỉ ghi `lnk` "giống hệt lúc chưa Tab" khi đã đo lại sau mục 3, và ghi vạch dưới của tab đang chọn đã đo lại.

8. **AUDIT.md: thêm đúng khối sau vào cuối** (nguyên văn khối nằm giữa hai dòng mốc; không chép hai dòng mốc):

----- BẮT ĐẦU KHỐI -----
## Kiểm D0-2s4 bởi Claude Code (05/10/2026)
- Bản zip 05/10 22:04, kiểm bằng Chromium + DevTools Protocol (bấm Tab thật), sáng và tối, đối chiếu với prompt D0-2s4.
- Đúng 10 tệp được phép; không thêm màu viết thẳng hay `outline: none` mới.
- Trang Focus, khung 1b: Tab tới mọi phần tử có mẫu tĩnh thì khác 0 pixel, trừ liên kết (gạch chân mỏng lại).
- Đạt:
  - ô lỗi (cả ô tích, khung bao) giữ đỏ: #bf371a sáng, #ff7355 tối;
  - hết khung đôi ở `.sx-search` / `.cp`;
  - ô tích / radio gốc dùng vòng tách; thanh bên Shell nền đậm (chế độ sáng) ra vòng trắng; nút "Áp dụng" viết style inline vẫn thấy focus;
  - giữ trạng thái khi focus (thẻ đã chọn, ngày hôm nay, dòng kéo, mục đang chọn), trừ hai ca ghi ở dòng "Sửa ở D0-2s5";
  - hộp thoại dưới 768px tràn bề rộng;
  - chép tay đường dẫn có `https://`, toast đứng đến khi đóng;
  - console 0 lỗi (Admin Shell vẫn 9 cảnh báo khuôn chữ có từ trước);
  - hàng tab không đổi.
- Mọi chỉ báo đạt 4,55–9,7:1 với nền kề.
- Sửa ở D0-2s5: ghost 3px, "Chữ lớn" hai đường, gạch chân liên kết, viền "hôm nay", hộp thoại 768–799, màu nút đặc chế độ tối, vạch vàng thanh bên tối, chữ `&gt;` và README.
- Để lượt sau (ngoài D0-2): thân trình soạn `.pe-rte` (đo) và `.pb3-ce` (đọc code) khi focus không có chỉ báo (luật chung đang loại trừ); `.em-ce` (Email) còn vòng cũ viết thẳng `#2050a0` lệch 2px, ở tối khoảng 2:1 (đọc code); khung tự vẽ focus viết thẳng (`style-focus-within`) ở Trình đơn (3, có `.mn-rt` hai khung — đo), Quản lý Bài viết - Options (2), Page Editor - Options (1), Page Builder v3 (3), Đăng nhập nhanh (1) — đã ghi vào D0-6a, D1-2, D1-4a, D1-4b, D3-2.
- Chưa kiểm được: chuột có hover, Safari / Firefox (vòng tách trên ô tích gốc), chế độ tương phản cao của Windows, trình đọc màn hình thật.
----- HẾT KHỐI -----

**Xong khi:**
- (0) Khung 1b trang Focus, Tab tới 13 phần tử `.f`, sáng + tối: vẫn 0 pixel khác mẫu tĩnh (kể cả `.lnk`).
- (1) `.sx-btn.gho`, `.b.gh` khi focus: một đường 2px.
- (2) "Chữ lớn": một chỉ báo; CLAUDE.md dòng 226 có câu "`[role=switch]` dạng nút có chữ vẽ như nút viền".
- (3) Tab tới `.lnk` ở trang Focus: không khác mẫu tĩnh.
- (4) Ngày hôm nay + đã chọn khi focus vẫn thấy dấu hôm nay.
- (5) Hộp thoại, đổi bề rộng cửa sổ: 390 và 767 → tấm trượt đáy; 768, 780, 799, 800 → hộp thoại giữa màn. Mẫu trong khung thiết bị không đổi.
- (6) Chế độ tối không còn #405189 ở nút đặc; vạch vàng thanh bên tối khi focus rộng 2px, vòng vẫn ≥ 3:1 với nền thanh bên.
- (7) CLAUDE.md hết `&gt;` và hết "tối `#96a1c0`"; README dòng Verifier đúng; AUDIT.md có khối "Kiểm D0-2s4 bởi Claude Code (05/10/2026)".

Gọi ready_for_verification, sửa hết lỗi verifier báo. Verifier không trả kết quả (trang nặng, công cụ chụp quá giờ) thì không chờ: ghi [CHƯA KIỂM ĐƯỢC] vào dòng Verifier, Claude Code kiểm bằng zip. Thay dòng "Verifier 05/10 (D0-2s4)" trong README bằng dòng "Verifier 05/10 (D0-2s5): …", ghi rõ mục nào thấy tận mắt, mục nào suy ra từ code.

Xuất zip project (một zip chung cho D0-3 và D0-2s5) gửi Claude Code kiểm lần chốt D0-2. Xong thì báo owner đúng một câu: "D0-2s5 xong — gửi zip cho Claude Code."
