# D0-2s3 · Sửa nhỏ sau kiểm lần 3 (dán vào CHÍNH chat D0-2)

Lượt D0-2s3: các sửa nhỏ sau lần kiểm thứ ba của Claude Code (05/10, bản zip 10:58; số dòng dưới đây là của bản đó). D0-2s2 đã ĐẠT: mọi mục cần dựng hình đều qua khi Claude Code dựng hình thật. Còn 9 chỗ nhỏ, làm xong là chốt D0-2.

**Được sửa (chỉ đúng các chỗ nêu dưới, không thêm gì khác):**
- `Chuẩn giao diện SaaS Starter.dc.html` — gọi tắt "trang": mục 1, 2, 3;
- `Chuẩn giao diện - Component bổ sung.dc.html` — gọi tắt "trang bổ sung": mục 4, 5;
- `README - D0-2 Chuẩn giao diện.md`: mục 6, 7, 8 và dòng "Verifier 05/10" ở cuối prompt;
- `AUDIT.md`: chỉ thêm ở mục 9.

**Không được:** sửa màn khác, TODO.md, LESSONS.md, CLAUDE.md; thêm dòng nào vào README ngoài các chỗ nêu; vẽ lại hay đổi bố cục; dùng màu ngoài token var(--…).

1. **TB — Nút "Sao chép" báo thành công khi không chép được.**
   - Dòng 2079 (`copySlug`) và 2266 (`copyDomain`) gọi thẳng `navigator.clipboard.writeText` rồi báo "Đã sao chép…" ngay, không chờ kết quả. Khi Clipboard bị chặn thì không chép được gì, và console có lỗi `NotAllowedError`.
   - Sửa: hai hàm gọi `window.BSUI.copy(text)` (content-ui.js; trả Promise true/false, không bao giờ lỗi) rồi chờ kết quả.
     - `true` → giữ nguyên toast "Đã sao chép địa chỉ." / "Đã sao chép tên miền.".
     - `false` → bôi đen chữ đang hiện (đường dẫn: `.sl-full` dòng 1091; tên miền: chữ "anvinh.vn" trong `<dd>` dòng 949, thêm `id` nếu cần) và hiện toast `tone: 'info'` đúng câu: "Trình duyệt không cho sao chép tự động. Nội dung đã được bôi đen — bấm Ctrl+C (máy Mac: ⌘+C), hoặc nhấn giữ trên điện thoại." Không thêm khối mới vào trang.
   - Kết quả cần đạt:
     - trang không còn `navigator.clipboard`;
     - chỉ chặn Clipboard API → vẫn chép được (BSUI.copy dùng vùng chọn ẩn trước), vẫn báo "Đã sao chép…", console sạch;
     - chặn cả hai cách (BSUI.copy trả false) → console sạch, không có "Đã sao chép", chữ được bôi đen và có câu chép tay.

2. **Thấp — Hàng tab của mẫu Danh sách co giãn, khung 390 px, khi bật Chữ lớn** (`.dlt-tabs` dòng 826, CSS dòng 174–187). Hàng tab rộng 380px trong khung chỉ có 364px, nên dấu "⌄" của "Khác" bị cắt 3,7px.
   - Kết quả cần đạt: khung 390 + Chữ lớn → hàng tab nằm gọn, không cuộn ngang trong hàng, thấy trọn "Khác ⌄".
   - Khung 390 chữ thường, khung 768 và khung 1240 không đổi gì.

3. **Thấp — Ô chọn màu thương hiệu (dòng 649) gây một cảnh báo console** `{{ brand }}` mỗi lần tải, trước khi có dữ liệu.
   - Kết quả cần đạt: tải trang không còn cảnh báo đó; màu hiện ra vẫn như cũ.
   - Không đổi loại ô, cỡ ô hay cách chọn màu. Nếu khuôn DC không tránh được cảnh báo này thì để nguyên và ghi lý do vào dòng "Verifier 05/10".

4. **Thấp — Trang bổ sung dòng 267, thừa dấu ")".** Thay đúng cụm "không được thì dùng Clipboard API), cả hai đều chặn" bằng "không được thì dùng Clipboard API; cả hai đều bị chặn thì".

5. **Thấp — Công tắc Chữ lớn của trang bổ sung (dòng 189)** vừa có `role="switch"` vừa ghi chữ "Bật/Tắt", nên trình đọc màn hình đọc trạng thái hai lần. Thay đúng cụm `Chữ lớn: {{ bigLabel }}` bằng `Chữ lớn<span aria-hidden="true">: {{ bigLabel }}</span>`. Giữ `role="switch"` + `aria-checked`; người nhìn vẫn thấy "Chữ lớn: Bật/Tắt".

6. **README dòng 252 — bảng nuốt mất đoạn văn.** Dòng cuối bảng "D0-2s2 (05/10)" có ô thứ ba bắt đầu bằng "360 và 768 cho panel lọc dạng trượt đáy". Mục 7 thay luôn dòng 252 (chỉ còn 2 ô). Sau bảng: một dòng trống, rồi đoạn văn riêng đúng câu này:
   360 và 768 cho panel lọc dạng trượt đáy: [SUY RA TỪ CODE] — dưới 768px (bề rộng màn) mở `.ov.sheet` cố định trong khung nhìn, không nằm trong luồng trang.

7. **README bảng "D0-2s2 (05/10)" — điền số đo thật.** Claude Code đo bằng Chromium + DevTools Protocol ngày 05/10 trên bản zip 10:58. Thay đúng 6 dòng 247–252 (5 dòng dữ liệu + dòng "Safari iOS / Firefox / máy thật") bằng 6 dòng trong khối dưới đây, giữ nguyên dòng tiêu đề 245–246:

```
| 360 / 390 × sáng + tối × Chữ lớn: scrollWidth = clientWidth | Đạt: scrollWidth = clientWidth ở 360 / 390 / 768 / 1024 / 1240 / 1440 × sáng + tối × chữ thường + Chữ lớn × con trỏ chuột (giả lập, không có hover) + cảm ứng — trang 96/96, trang bổ sung 96/96, Danh sách co giãn 48/48 (trang này không có Chữ lớn); 127 khối .sx-note / .sx-code đều xuống dòng [ĐO BẰNG SCRIPT — Claude Code 05/10] |
| Thanh ngôn ngữ ≥ 44px ở 768 / 1024 cảm ứng · nút hàng ≥ 1024 cảm ứng · ô trang bổ sung 44px | Đạt: thanh ngôn ngữ 44px ở 768 / 1024 / 1240 / 1440 cảm ứng (43,99 ở khung xem thu nhỏ); nút hàng ≥ 1024 cảm ứng hiện .dl-acts 44px; ô trang bổ sung 44px [ĐO BẰNG SCRIPT — Claude Code 05/10] |
| Câu lý do ô khóa · 4 Dải thông báo · chữ tắt avatar ≥ 4,5:1 sáng + tối | Đạt (sáng / tối): câu lý do 6,09 / 6,54 · Dải thông báo 4,95–4,99 / 5,28–7,52 · chữ tắt avatar 6,19 / 5,63; Dải thông báo 14px → 16px khi Chữ lớn [ĐO BẰNG SCRIPT — Claude Code 05/10] |
| Chọn cả 5 bài: Người viết "Gửi duyệt (2)" + "Gửi duyệt: 3 bài…"; Biên tập "Đăng (4)" + "Đăng: 1 bài đã đăng…" | Đạt: Người viết thấy nút Gửi duyệt (2) và câu bỏ qua 3 bài; Biên tập thấy nút Đăng (4) và câu bỏ qua 1 bài đã đăng; N = 0 thì nút khóa [ĐÃ RENDER VÀ THẤY — Claude Code 05/10] |
| `BSSEO.hardCap('slug')` = 200 · `hardCap('excerpt')` = 255 | Đạt: hardCap slug 200 · excerpt 255; gõ 260 ký tự dừng ở 255 (trang bổ sung, Page Editor, Quản lý Bài viết) [ĐO BẰNG SCRIPT — Claude Code 05/10] |
| Safari iOS / Firefox / máy thật | [CHƯA KIỂM ĐƯỢC — không có thiết bị; Chromium không giả lập được chuột có hover ở ≥ 1024] |
```

8. **README "Việc cho backend" — sửa 2 cụm chữ, không thêm dòng:**
   - dòng 267: thay "Claude Code đang làm PR: `EntryPolicy::submit` + quy trình duyệt + thao tác hàng loạt)" bằng "repo ĐÃ CÓ từ 04/10, PR #168: `EntryPolicy::submit` + quy trình duyệt + thao tác hàng loạt)";
   - dòng 277: thay "thêm `max:255` theo cột `entry_translations.title`" bằng "thêm `max:200` như trình soạn (`EntryContentRules.php:25`)".

9. **AUDIT.md: thêm đúng khối sau vào cuối** (nguyên văn khối nằm giữa hai dòng mốc; không chép hai dòng mốc):

▼ BẮT ĐẦU MỤC AUDIT ▼
## Kiểm D0-2s2 bởi Claude Code (05/10/2026)

Bản zip 05/10 10:58. Cách kiểm: so diff với bản 04/10 17:21 + đọc code + dựng hình thật (Chromium + DevTools Protocol) ở 360 / 390 / 768 / 1024 / 1240 / 1440 × sáng + tối × chữ thường + Chữ lớn × con trỏ chuột (giả lập) + cảm ứng.

- Mục 1–14 của D0-2s2: ĐẠT (mục cần dựng hình đều qua; mục 7, 14 và phần chữ kiểm bằng đọc). Chỉ sửa trong 8 file được phép; cú pháp JS đúng; không mất tính năng. Bước cuối CHƯA ĐẠT: README không có kết quả verifier mà zip vẫn được xuất.
- Lệch quy trình (giữ nội dung): lượt tự sửa 1 dòng và thêm 4 dòng "Việc cho backend", thêm "Ngoại lệ tạm với P1" ở bảng tra README, thêm một mục TODO — lấy từ khối AUDIT. Nội dung khớp repo ở chỗ đã kiểm, trừ `saveTitle max:255` (đúng là 200) → sửa ở D0-2s3 mục 8. Một sửa ngoài yêu cầu ở trang dòng 1757 (`'theme' in pp`) → kiểm ở D0-2s3 "Xong khi" (7).
- Lỗi mới, sửa ở D0-2s3: TB 1 (nút Sao chép đường dẫn / tên miền ở trang báo thành công khi không chép được — có từ trước, các lần kiểm trước bỏ sót) · Thấp 5 (tab "Khác ⌄" tràn ở khung 390 khi Chữ lớn; cảnh báo console `{{ brand }}`; dấu ")" thừa ở trang bổ sung; công tắc Chữ lớn đọc trạng thái hai lần; bảng README nuốt đoạn văn).
- Mang sang lượt sau (không sửa ở D0-2s3): 6 màn gọi `BSUI.copy` nhưng không chờ kết quả, nên vẫn báo "Đã sao chép" khi không chép được — Floating Contact Panel:292 · Đăng nhập nhanh & Liên kết tài khoản:469, 475 · Page Editor - Options:2026, 2719 · Trình đơn:1303 · Quản lý Bài viết - Options:2259 · Email Settings:950. Trang bổ sung bật Chữ lớn bằng `data-bs-big`, trang bằng `--fsp` — nên thống nhất một cách.
- Ghi chú: zip thiếu `assets/` (3 logo), owner đã gửi lại. Ô email ở 360 + Chữ lớn cuộn chữ bên trong ô là hành vi bình thường của ô nhập, không tính lỗi.
- Chưa kiểm được: Safari iOS, Firefox, máy thật; chuột có hover ở ≥ 1024 (Chromium không giả lập được); trình đọc màn hình thật (VoiceOver / NVDA); popover "+N" của thanh ngôn ngữ; đổi Tweak theme khi trang đang mở.
▲ HẾT MỤC AUDIT ▲

**Xong khi:**
- (1) `copySlug` / `copyDomain` đi qua `BSUI.copy`: chép được thì báo như cũ; `BSUI.copy` trả false thì không lỗi console, không báo "Đã sao chép", chữ được bôi đen + có câu chép tay.
- (2) Khung danh sách 390 + Chữ lớn: hàng tab không tràn, không cuộn, đọc đủ "Khác ⌄"; 390 chữ thường, 768, 1240 không đổi.
- (3) Tải trang không còn cảnh báo `{{ brand }}` (hoặc dòng Verifier ghi lý do không tránh được).
- (4) Trang bổ sung dòng 267 đúng câu mới; công tắc Chữ lớn có `aria-hidden` ở phần ": Bật/Tắt".
- (5) README: 6 dòng 247–252 đúng khối ở mục 7; đoạn "360 và 768…" đứng riêng dưới bảng; 2 cụm chữ ở mục 8 đã thay; ngoài đoạn "360 và 768…" và dòng "Verifier 05/10" thì không có dòng mới nào.
- (6) AUDIT.md có mục "Kiểm D0-2s2 bởi Claude Code (05/10/2026)" ở cuối.
- (7) Đổi Tweak theme sáng ↔ tối khi trang đang mở vẫn áp ngay.

Gọi ready_for_verification, sửa hết lỗi verifier báo. Rồi ghi một dòng "Verifier 05/10: …" vào README ngay SAU đoạn "360 và 768…", cách một dòng trống (dòng chữ sát bảng sẽ bị nuốt thành dòng bảng). Verifier quá giờ (trang nặng) thì ghi "Verifier 05/10: quá giờ — [CHƯA KIỂM ĐƯỢC]" kèm các mục đã tự kiểm.

KHÔNG cần xuất zip riêng cho lượt này: owner chạy tiếp D0-3, Claude Code kiểm D0-2s3 cùng bản zip sau D0-3. Xong thì báo owner đúng một câu: "D0-2s3 xong — mở D0-3 được."
