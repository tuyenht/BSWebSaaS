# D0-2s4 · Chốt Focus 1b + sửa nhỏ sau kiểm lần 4 (dán vào CHÍNH chat D0-2)

Lượt D0-2s4: Claude Code kiểm bản zip 05/10 20:05 bằng Chromium thật (số dòng dưới đây là của bản đó). D0-2s3 ĐẠT cả 9 mục: hàng tab 390 + Chữ lớn nằm gọn (364/364px), nút Sao chép đúng ở cả 5 tình huống, console trang chuẩn sạch. Còn lại là Focus 1b: luật chung trong `admin-base.css` vẽ khác hình 1b owner đã duyệt ở `Focus - Đề xuất.dc.html`, và có vài lỗi. Lượt này đưa luật chung về đúng hình đã duyệt, sửa lỗi, rồi chốt D0-2.

**Được sửa (chỉ đúng các chỗ nêu dưới):**
- `admin-base.css` — mục 1, 2, 3, 5;
- `Focus - Đề xuất.dc.html` — gọi tắt "trang Focus": mục 1 (dòng 24, 55–64, 96);
- `Chuẩn giao diện SaaS Starter.dc.html` — gọi tắt "trang": mục 1 (dòng 63), 3, 5, 6, 7, 8;
- `Chuẩn giao diện - Component bổ sung.dc.html` — gọi tắt "trang bổ sung": mục 3;
- `Admin Shell.dc.html`: mục 4;
- `Danh sách co giãn - Đã chốt.dc.html` và `Hộp duyệt.dc.html`: chỉ những gì mục 3 nêu;
- `CLAUDE.md`: chỉ dòng Focus (mục 9); `README - D0-2 Chuẩn giao diện.md`: mục 9 và dòng Verifier; `AUDIT.md`: mục 10.

**Không được:**
- sửa màn khác, TODO.md, LESSONS.md;
- vẽ lại hay đổi bố cục ngoài mục 6;
- dùng màu ngoài token var(--…) hoặc `currentColor`;
- dùng `outline: none` / `outline: 0` ở bất kỳ đâu. Nơi vẽ focus bằng box-shadow thì giữ `outline: 2px solid transparent`, để chế độ tương phản cao của Windows vẫn thấy focus.

1. **TB — Luật chung phải vẽ đúng hình 1b đã duyệt.** Hình owner chốt là các mẫu tĩnh 1b ở trang Focus (`.fb … .f`, dòng 55–64; ghi chú dòng 134–138). Luật chung (`admin-base.css` dòng 16–42) đang vẽ khác. Từng loại phải như sau:
   - **Nút đặc** (Chính, Đỏ — trang Focus dòng 57): viền trong 2px màu chữ của nút (`currentColor`, tức trắng, cả sáng lẫn tối) + quầng ngoài 3px 30%, không outline màu. Luật chung hiện vẽ outline đè viền + vòng màu nền thẻ + quầng 4px 18%.
   - **Nút viền** (Thường, Viền đỏ — dòng 58): viền đổi sang màu vòng + bóng trong 1px + quầng 3px 18%, giống ô nhập. Nút viền đỏ cũng đổi sang màu vòng.
   - **Ghost, tab** (dòng 59, 63): nền màu vòng 8% + viền trong 2px màu vòng, không quầng.
   - **Liên kết** (dòng 62): nền 10% + viền NGOÀI 2px (`0 0 0 2px`) + gạch chân dày 2px.
   - **Công tắc, ô chọn, radio** (dòng 64): vòng tách gồm khe 2px màu nền + vòng 2px màu vòng. Checkbox/radio gốc của trình duyệt vẽ bằng `outline: 2px solid <màu vòng>; outline-offset: 2px`, không còn vòng đen mặc định.
   - **Phần tử bấm được khác** (mục lục, mục thanh bên, thẻ chọn, ngày lịch, dòng cây, mục menu): kiểu ghost. Riêng khi đang ở trạng thái nền đặc (ngày đã chọn, đoạn đang chọn của Nút chọn đoạn) thì dùng kiểu nút đặc. Không đặt `background` bằng `!important`.
   - **Màu vòng:** luôn lấy `var(--focus-ring-color)`, không lấy `--color-accent`, để vùng nền đậm của Admin Shell vẫn ra vòng trắng. Trang Focus dòng 24: thêm `--focus-ring-color: var(--brand-ink)` vào khối tối.
   - **Cách gắn:** theo vai trò trước, class sau — `[role=tab]`, `[role=switch]`, `[role=checkbox]`, `input[type=checkbox]`, `input[type=radio]`; nút theo class của trang (`.sx-btn`, `.pri`, `.dan`, `.dano`, `.gho`). Ô soạn thảo `[contenteditable]` (dòng 43–46) theo kiểu ô nhập.
   - **Trang dòng 63:** mẫu tĩnh `.sx-btn.fx`, `.dsw.fx` (dùng ở dòng 392, 399, 451) đổi cho khớp luật mới, mỗi loại một kiểu như trên.
   - **Trang Focus:**
     - bỏ phần `:focus-visible` / `:focus` / `:focus-within` khỏi các luật `.fb` ở dòng 55–64 (giữ phần `.f`);
     - đổi mọi selector mẫu `.fb … .f` thành `.fb … .f:not(:focus)`, để lúc Tab tới thì mẫu tĩnh tự tắt và chỉ còn luật chung vẽ;
     - thêm `data-focus-wrap` cho `.srch` ở dòng 96.
   - **Kết quả cần đạt:** trong khung 1b của trang Focus (sáng và tối), Tab tới phần tử nào thì hình của nó KHÔNG đổi so với lúc chưa Tab. Ô "Thường" khi Tab tới trông y như ô "Ô nhập · focus".

2. **TB — Ô lỗi khi focus đổi sang xanh, phải giữ đỏ** (dòng 28 so với 33). Luật ô nhập chung có độ ưu tiên (0,7,0) vì chuỗi `:not()`, nên thắng luật ô lỗi (0,3,0); cả hai đều `!important`.
   - Sửa: bọc 5 điều kiện `:not(…)` sau `:not(:is(…) *)` của luật chung trong `:where(…)`; độ ưu tiên còn (0,2,0), các loại trừ vẫn chạy như cũ.
   - Ô tích / radio lỗi khi focus: vòng tách như mục 1 nhưng màu đỏ (`--color-admin-danger-text`); luật ô lỗi không vẽ bóng trong hay quầng lên ô tích.
   - Khung bao có ô lỗi (`[data-focus-wrap]:has([aria-invalid="true"]:focus)`, ví dụ ô tiền `#mo-in`) cũng đỏ.
   - Kết quả cần đạt: ô có `aria-invalid="true"`, kể cả ô tích, khi focus vẫn đỏ, cả sáng (#bf371a) lẫn tối (#ff7355).

3. **TB — Khung bao sáng hai lần.** `.cp:focus-within` (trang bổ sung) và `.sx-search:focus-within` (trang) sáng lên cả khi focus đang ở một NÚT bên trong (nút "Sao chép" trong `.cp`, nút × bỏ thẻ trong `.sx-search`), nên thành hai khung lồng nhau.
   - Sửa: khung bao chỉ sáng khi ô nhập bên trong được focus: `:has(:is(input, textarea):focus)`. Nút bên trong tự vẽ focus của nó.
   - `Danh sách co giãn - Đã chốt.dc.html`: thêm `data-focus-wrap` cho `.srch5` (dòng 265) và xóa luật riêng `.srch5:focus-within` (dòng 213).
   - `Hộp duyệt.dc.html`: thêm `data-focus-wrap` cho `.sp-search` (dòng 230) và xóa luật riêng `.sp-search:focus-within` (dòng 42).
   - Ngoài các việc trên, không sửa gì ở hai màn này.
   - Kết quả cần đạt: Tab vào ô nhập trong khung → chỉ khung sáng; Tab vào nút trong khung → chỉ nút có focus.

4. **Thấp — `.pop` của thanh trên cùng nền đậm** (`Admin Shell.dc.html` dòng 108) thừa hưởng `--focus-inner: transparent` của thanh trên. Thêm `--focus-inner: var(--color-surface)` cho `.pop`, để khe vòng tách và các chỗ khác dùng biến này lấy đúng màu nền popover.

5. **Thấp — Focus xóa mất trạng thái.** `box-shadow … !important` của luật chung thay luôn box-shadow trạng thái khi phần tử đang focus:
   - `.tok[aria-checked]` (trang dòng 303): mục đã chọn trông như chưa chọn;
   - `.cal-d[aria-current]` (dòng 312): mất dấu hôm nay;
   - `.tr-row[data-over]` (dòng 294–297).
   - Sửa: trạng thái và focus phải cùng thấy được, bằng cách ghép hai box-shadow, hoặc chuyển trạng thái sang viền/nền.
   - Kết quả cần đạt: Tab vào một thẻ đã chọn, ngày hôm nay, hay dòng đang kéo vẫn thấy rõ trạng thái của nó; ngày đã chọn và đoạn đang chọn vẫn giữ nền đặc, chữ đọc được.

6. **TB — Hộp thoại 480–767px không còn trượt đáy tràn bề rộng** (trang dòng 216, 221–222). Lượt trước đổi `.dlg` sang `fit-content` (360–560px) và bỏ `max-width:none` ở khối ≤767px. Vì vậy từ 480 đến 767px, tấm trượt đáy thành một khối hẹp ở giữa, trái với luật `sheet-below="768"`.
   - Sửa: giữ bề rộng mới từ 768px trở lên. Trong khối ≤767px cho `.dlg` tràn hết bề rộng như trước (`width:100%; min-width:0; max-width:none`), rồi xóa khối ≤479px (dòng 222) vì trùng.
   - Kết quả cần đạt: dưới 768px tấm trượt đáy tràn hết bề rộng; từ 768px trở lên hộp thoại như bản hiện tại.

7. **Thấp — Chữ còn mô tả focus kiểu cũ:**
   - Dòng 1379: "Vòng focus 2px cách 2px trên mọi phần tử bấm được." → "Focus 1b: viền nhấn 2px sát phần tử + quầng nhạt; công tắc và ô chọn dùng vòng tách. Luật chung trong admin-base.css."
   - Dòng 510: chú thích "Focus: vòng 2px" → "Focus 1b".
   - Mẫu `.sx-seg button.fx` ở dòng 510 không có luật CSS nào nên không hiện focus. Đoạn đang chọn là nền đặc, nên cho mẫu này dùng kiểu nút đặc của mục 1.

8. **Thấp — Nút Sao chép đường dẫn, nhánh không chép được** (`copySlug` dòng 2119, `copyOr` dòng 1834–1844):
   - Chuỗi được bôi đen phải đúng bằng chuỗi sẽ chép (`https://anvinh.vn/<đường dẫn>`). Hiện `slugFull` (dòng 2114) thiếu `https://`. Cách gọn nhất là cho dòng đường dẫn hiện đủ `https://`, không đổi bố cục dòng đó.
   - Toast câu chép tay (hàm `toast` dòng 1849, tone `info`) đang tự đóng sau 5000ms, không đủ để làm theo. Toast này phải đứng đến khi bấm Đóng.

9. **CLAUDE.md và README.**
   - CLAUDE.md dòng 226: thay phần "focus (1b …)" bằng mô tả đúng luật sau khi sửa mục 1–5. Phải gồm:
     - từng loại phần tử: nút đặc, nút viền, ghost/tab, liên kết, điều khiển nhỏ, ô nhập, ô lỗi, khung bao;
     - màu vòng qua `--focus-ring-color`, và `--focus-inner` cho vùng nền đậm;
     - danh sách selector luật chung bám vào;
     - luôn giữ outline (trong suốt nếu vẽ bằng box-shadow).
   - Không đổi gì khác trong CLAUDE.md.
   - README: thêm MỘT dòng "Focus 1b (chốt 05/10): …" vào mục `## Token`, ngay sau đoạn có `--focus-ring-*` (dòng 176–178). Nội dung giống CLAUDE.md, viết gọn.

10. **AUDIT.md: thêm đúng khối sau vào cuối** (nguyên văn khối nằm giữa hai dòng mốc; không chép hai dòng mốc):

----- BẮT ĐẦU KHỐI -----
## Kiểm D0-2s3 bởi Claude Code (05/10/2026)
- Bản zip 05/10 20:05, kiểm bằng Chromium + DevTools Protocol, đối chiếu từng mục với prompt D0-2s3.
- ĐẠT cả 9 mục và "Xong khi" (1)–(6). Mục (7) đạt theo code: bấm chế độ tối / Chữ lớn ngay trong trang thì console sạch; đổi Tweak lúc trang đang mở chưa dựng hình.
  - Hàng tab 390 + Chữ lớn: 364/364px, 1 dòng, đệm 4/2/3px. 390 chữ thường, 768, 1240 không đổi.
  - Sao chép: 5 tình huống, 0 lỗi. Khi bị chặn thì không báo "Đã sao chép", chữ được bôi đen, có câu chép tay.
  - Console trang chuẩn 0 lỗi, 0 cảnh báo (hết cảnh báo `{{ brand }}`).
- Admin Shell còn 9 cảnh báo khuôn chữ (có từ trước, chưa sửa ở lượt này).
- Ngoài phạm vi D0-2s3 nhưng giữ lại:
  - kéo thả cây có thả trước / sau / vào trong (đọc code thấy đúng);
  - hộp thoại từ 768px trở lên rộng theo nội dung 360–560px.
- Focus theo luật chung CŨ (trước D0-2s4), trên trang chuẩn, trang bổ sung và Admin Shell: mọi chỉ báo ≥ 3:1 với nền kề (7,1–10,6:1; vòng mặc định của ô tích 13,9–19:1). Trang Focus: bấm Tab thật trong khung 1b chỉ còn quầng ≈1,34:1, khác ảnh tĩnh.
- Lỗi sửa ở D0-2s4:
  - luật chung khác hình đã duyệt; Tab thật ở trang Focus;
  - ô lỗi (cả ô tích) thành xanh; vòng đen mặc định ở ô tích / radio;
  - khung bao sáng đôi; `.pop`; focus xóa trạng thái;
  - hộp thoại 480–767;
  - chữ dòng 1379/510;
  - chép tay đường dẫn thiếu `https://`, và toast tự đóng.
- Chưa kiểm được: chuột có hover, chế độ tương phản cao của Windows, Safari / Firefox, trình đọc màn hình thật.
----- HẾT KHỐI -----

**Xong khi:**
- (1) Khung 1b của trang Focus, sáng và tối: Tab tới phần tử nào thì hình không đổi so với lúc chưa Tab; mỗi phần tử đúng một khung.
- (1b) Trang chuẩn:
  - Tab vào ô tích, radio, `.dsw` → vòng tách màu vòng, không còn vòng đen mặc định;
  - mẫu tĩnh dòng 392/399/451 khớp luật mới;
  - Tab trong thanh bên nền đậm của Admin Shell vẫn ra vòng trắng.
- (2) Ô lỗi (cả ô tích) và khung bao có ô lỗi khi focus vẫn đỏ, sáng và tối.
- (3) Khung bao chỉ sáng khi ô nhập bên trong được focus; `.srch5` và `.sp-search` có `data-focus-wrap` và không còn luật `:focus-within` riêng.
- (4) `.pop` có `--focus-inner`. Thẻ đã chọn, ngày hôm nay, dòng đang kéo khi focus vẫn thấy trạng thái; ngày đã chọn và đoạn đang chọn giữ nền đặc.
- (5) Hộp thoại dưới 768px tràn bề rộng, từ 768px trở lên như hiện tại; không còn khối ≤479px.
- (6) Dòng 1379 và 510 đúng chữ mới; mẫu `.sx-seg` dòng 510 hiện focus. Khi không chép được, chuỗi bôi đen có `https://` và toast chép tay đứng đến khi bấm Đóng.
- (7) CLAUDE.md dòng Focus khớp luật thật; README mục `## Token` có một dòng Focus 1b; AUDIT.md có khối "Kiểm D0-2s3 bởi Claude Code (05/10/2026)".

Gọi ready_for_verification, sửa hết lỗi verifier báo. Rồi thay dòng "Verifier 05/10" trong README bằng một dòng "Verifier 05/10 (D0-2s4): …". Dòng này phải nói rõ mục nào verifier đã thấy tận mắt và mục nào chỉ suy ra từ code; chỗ verifier quá giờ thì ghi đúng như vậy.

KHÔNG cần xuất zip riêng: focus là luật chung, D0-3 dùng lại chứ không vẽ focus riêng, nên Claude Code kiểm D0-2s4 cùng bản zip sau D0-3. Xong thì báo owner đúng một câu: "D0-2s4 xong — mở D0-3 được."
