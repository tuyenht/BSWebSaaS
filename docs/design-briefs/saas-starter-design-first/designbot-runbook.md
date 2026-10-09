# Runbook cho phiên "designbot" — tự chạy gói prompt trong Claude Design

Phiên Claude Code của tài khoản `designbot` (lệnh `ccd`), nối Chrome trên màn hình ảo qua Claude in Chrome, đọc file này và chạy các lượt thiết kế thay owner. Owner giao việc bằng một dòng: `Đọc và làm theo /opt/bswebsaas/dev/docs/design-briefs/saas-starter-design-first/designbot-runbook.md`.

Vai trò: **người chuyển prompt và người kiểm bước đầu**. Không tự thiết kế, không tự viết prompt, không quyết thay owner. Kiểm sâu (dựng hình mọi cỡ màn, đo tương phản, nhập vào kho) là việc của phiên Claude Code chính của owner, làm từ zip bạn tải.

## Trước khi bắt đầu
1. Đọc `README.md` cùng thư mục: cách dùng gói, phần 2, prompt sửa.
2. Chrome đang kết nối phải là cửa sổ đã đăng nhập claude.ai, đang mở project **BSWebSaaS** trong Claude Design. Không thấy thì dừng và báo owner.
3. Tạo `/home/designbot/zips/` nếu chưa có. Nhật ký ghi ở `/home/designbot/zips/log.md`.
4. **Chỉ làm 1 lần, khi `log.md` chưa có khối `## D0-1…D0-4at`:** các lượt D0-1 → D0-4a và hai prompt sửa D0-4as, D0-4at đã chạy xong (owner chạy tay) → ghi khối log `## D0-1…D0-4at · ĐẠT (owner chạy tay)`; rồi tải zip project (mục "Tải zip") với mã `D0-4at`, chưa chạy lượt mới. `log.md` đã có khối đó thì BỎ QUA bước này — không tải lại zip, không ghi lại khối.
5. **Chạy tiếp từ đâu:** đọc `log.md`, lấy khối log cuối cùng của từng mã. Một mã là XONG khi có khối ĐẠT, hoặc có khối DỪNG mà prompt của lượt kế nêu rõ owner đã chốt chỗ dừng đó (ví dụ D3-1 DỪNG → prompt D0-7 ghi owner đã chốt). Lượt bắt đầu = mã ĐẦU TIÊN trong "Thứ tự lượt" chưa XONG; mã đã XONG thì không chạy lại. Gặp khối DỪNG chưa được chốt hoặc CHƯA ĐẠT thì dừng và báo owner (khối "Kiểm trước D0-8" theo luật riêng ở mục ⏸ dưới). Riêng khối DỪNG có lý do "cần bản sửa tự đủ cho chat mới": nếu dòng 1 của tệp sửa mã đó nay ghi "(chat mới)" thì chỗ dừng đã được gỡ — mã đó CHƯA XONG, chạy nó từ đầu ở chat mới; ghi 1 dòng vào log.

## Thứ tự lượt
Chạy lần lượt, mỗi lượt một **chat mới** trong project:

D0-4b → D0-5 → D0-6a → D0-6b → D0-6c → D1-1 → D1-6 → D1-2 → D2-3 → D1-3 → D1-4a → D1-4b → D1-5 → D1-7 → D1-4c → D2-1 → D1-4d → D2-2 → D2-4 → D2-5 → D3-1 → **D0-7** (3 phần, cùng chat) → **7 prompt sửa theo thứ tự D1-1s · D1-6s · D2-5s · D1-2s · D1-3s · D2-1s · D3-1s** (mỗi prompt một **chat mới**, vì chat cũ của 7 lượt đó đã quá dài; gửi như bước 2 và làm như mục "Prompt sửa từ Claude Code") → **⏸ KIỂM TRƯỚC D0-8** (điểm dừng, xem dưới) → **D1-1t** (`prompts/fixes/D1-1t-*.md`, **chat mới**, owner duyệt 08/10) → **D0-8** (3 phần, cùng chat; owner chốt 07/10) → D3-2 → D4-1 → D4-3 → D4-2 → D5-1 → **SG1** (`prompts/fixes/SG1-*.md`, **chat mới**, 4 phần cùng chat; sửa gộp sau khi Claude Code kiểm zip D1-1t · D0-8 · D3-2 · D4-1 · D4-3 · D4-2 · D5-1) → **SG2** (`prompts/fixes/SG2-*.md`, **chat mới**, 2 phần cùng chat; sửa nốt sau khi Claude Code kiểm zip SG1) → **D2-1u** (`prompts/fixes/D2-1u-*.md`, **chat mới**, 4 phần cùng chat; owner duyệt 08/10 17:37, đã viết lại theo hai trục «Nhóm quyền × Phạm vi làm việc»; chạy sau khi Claude Code đã kiểm zip SG2) → **SG3** (`prompts/fixes/SG3-*.md`, **chat mới**, 4 phần cùng chat; sửa nốt sau khi Claude Code kiểm zip SG2 và D2-1u) → **SG4** (`prompts/fixes/SG4-*.md`, **chat mới**, 2 phần cùng chat; sửa nốt sau khi Claude Code kiểm zip SG3) → D5-2 → **SG5** (`prompts/fixes/SG5-*.md`, **chat mới**, 5 phần cùng chat; hai việc owner chốt 09/10: bong bóng Hướng dẫn có nền mờ, "Hẹn giờ đăng" ở bước duyệt cuối; và sửa nốt sau khi Claude Code kiểm zip D5-2 phần 3) → **SG6** (`prompts/fixes/SG6-*.md`, **chat mới**, 2 phần cùng chat; sửa nốt sau khi Claude Code kiểm zip SG5: hộp "Thay đổi chưa được lưu" bị bóp nút từ 600px, bong bóng Hướng dẫn khi tải thẳng màn và ở giao diện tối, dải tab và mấy chỗ nhỏ của "Hẹn giờ đăng" khi duyệt)

Bạn luôn chạy **một chat một lúc**, nên mọi lượt đều là "chạy một mình": đoạn "CHẠY SONG SONG …" trong prompt **không áp** (prompt tự ghi "chạy một mình thì bỏ qua đoạn này"). Đoạn "Đầu lượt — gộp luồng phụ" sẽ không thấy mục cần gộp và tự bỏ qua. Vẫn dán nguyên văn cả hai đoạn, không xóa.

**⏸ KIỂM TRƯỚC D0-8** (owner chốt 07/10 22:0x): D0-8 sửa cùng tệp với 7 prompt sửa, nên Claude Code kiểm 7 zip sửa, gộp lỗi còn sót vào D1-1t / D0-8, rồi mới cho chạy tiếp.
- Tới bước này (7 prompt sửa đã có khối log ĐẠT): mở `prompts/D0-8-*.md`. Có dòng bắt đầu bằng `Đã kiểm 7 prompt sửa:` thì ghi 1 dòng log "Kiểm trước D0-8: đã có" rồi chạy tiếp theo thứ tự: D1-1t (chat mới, như mục "Prompt sửa từ Claude Code") rồi D0-8.
- Chưa có dòng đó: ghi khối log `## Kiểm trước D0-8 · <HH:MM dd/mm> · DỪNG` với lý do "chờ Claude Code kiểm 7 zip sửa", báo owner một câu "7 prompt sửa xong — chờ Claude Code kiểm trước D0-8", rồi dừng. Không mở chat D0-8.
- Lần chạy sau: khối DỪNG này được gỡ khi tệp D0-8 đã có dòng `Đã kiểm 7 prompt sửa:`. Dòng đó do phiên Claude Code chính thêm ở DÒNG 1 của tệp, trên `═══ BỐI CẢNH CHUNG`, nên không bị dán vào chat (bước 2 dán từ `═══ BỐI CẢNH CHUNG`). Đây là ngoại lệ của bước 5: khối DỪNG này không chờ owner chốt.

## Mỗi lượt
1. **Đọc lại tệp prompt ngay lúc chạy** (`prompts/<mã>-*.md`; prompt sửa ở `prompts/fixes/<mã>-*.md`). Tệp có thể vừa được sửa, không dùng bản nhớ từ trước. Đối chiếu mục "Cần trước" với `log.md`: lượt cần trước chưa đạt thì dừng và báo owner. Ngoại lệ: lượt cần trước ghi DỪNG nhưng prompt hiện tại nêu rõ owner đã chốt chỗ dừng đó (ví dụ D0-7 với D3-1) thì coi là đủ điều kiện; ghi 1 dòng vào log.
2. **Mở chat mới** trong project BSWebSaaS. Nội dung gửi:
   - dòng 1: tên chat = dòng `# <mã> · <tên>` ngay dưới `═══ VIỆC CỦA LƯỢT NÀY ═══`, bỏ dấu `#` và phần trong ngoặc tròn;
   - xuống dòng, rồi **toàn bộ tệp prompt nguyên văn**, từ `═══ BỐI CẢNH CHUNG` đến hết.
   Không thêm, bớt hay sửa chữ nào. Trước khi bấm Send, kiểm ô nhập có đủ câu **cuối cùng** của tệp. Thiếu thì xóa và dán lại.
3. **Chờ chạy xong**, kiểm tra vài phút một lần bằng ảnh chụp, không bấm vào khung thiết kế khi đang chạy.
   - Bị ngắt ("We got interrupted") thì bấm **Resume**.
   - Tab hiện **"Aw, Snap!"** hoặc trang trắng (Chrome bị hệ điều hành tắt vì máy chung hết RAM): bấm **Reload**, mở lại đúng chat của lượt, bấm **Resume** nếu có, rồi chờ tiếp. Ghi giờ sập vào log. Sập 3 lần trong một lượt thì DỪNG và báo owner.
   - Chat báo còn phần 2 (hoặc TODO.md có dòng `<mã> phần 2`) thì gửi `Làm <mã> phần 2` trong **cùng** chat. Riêng D5-2: mỗi phần một chat mới, theo prompt.
   - Claude Design hỏi lại thì trả lời theo đúng chữ trong prompt. Prompt không nói thì chọn phương án **giữ nguyên thứ đang có**, ghi câu hỏi vào log. Câu hỏi về gỡ hay ẩn một nút hoặc tính năng đang có thì **dừng**, không trả lời.
4. **Kiểm "Xong khi"**: đọc từng mục "Xong khi" của prompt, đối chiếu với câu trả lời **và** màn hình thật trong khung xem trước (chụp ảnh).
   - Mục chưa đạt: gửi trong cùng chat `Mục <số> "Xong khi" chưa đạt: <điều thấy trên màn>. Sửa đúng mục này.` Tối đa 2 vòng.
   - Mục cần cỡ màn khác, trình duyệt khác hoặc verifier quá giờ ([CHƯA KIỂM ĐƯỢC]) thì không ép Claude Design. Ghi vào log để Claude Code kiểm bằng zip.
5. **Tải zip** (mục dưới) với mã lượt.
6. **Ghi log**, rồi sang lượt kế.

## Giữ Chrome nhẹ (máy chủ dùng chung với website thật, RAM có hạn)
- Chỉ giữ **một tab**: project BSWebSaaS trong Claude Design. Đóng mọi tab khác (Design - Claude, Claude, tab tải xuống…) trước mỗi lượt.
- Sau mỗi lần tải zip: chuyển zip đi (không để bản thừa trong `~/Downloads`), rồi **tải lại tab project** (Reload) trước khi mở chat lượt kế. Xuất zip làm tab phình bộ nhớ, tải lại sẽ giải phóng.
- Không mở cùng lúc nhiều file thiết kế nặng trong khung xem trước.

## Tải zip
Trong Claude Design: Share → Download project (zip cả project). Chuyển tệp vừa tải (thường ở `~/Downloads/`) sang `/home/designbot/zips/<mã>-<HHMM>.zip`. Kiểm `unzip -l` mở được và có `Admin Shell.dc.html`. Không có thì tải lại một lần, vẫn lỗi thì dừng và báo owner.

## Log (`/home/designbot/zips/log.md`, thêm cuối tệp)
```
## <mã> · <HH:MM dd/mm> · ĐẠT | CHƯA ĐẠT | DỪNG
- Xong khi: <các mục đạt> · chưa đạt: <mục + lý do> · [CHƯA KIỂM ĐƯỢC]: <mục>
- Câu Claude Design hỏi / bạn đã trả lời: <…>
- zip: <tên tệp> · bất thường: <…>
```

## Prompt sửa từ Claude Code
Owner gõ `Đọc và làm theo <đường dẫn tệp trong prompts/fixes/>`, hoặc prompt sửa tới lượt trong thứ tự chạy ở đầu tệp:
- Dòng 1 của tệp (`# <mã> · <tên>`) cho biết chat nào nhận. Tiêu đề hoặc đầu tệp ghi rõ chat cần dán, ví dụ "dán vào chat D0-4b" hoặc "chat mới".
- Dán phần **từ dòng 2 trở đi**, nguyên văn. Prompt sửa dán vào chat cũ **không** kèm bối cảnh chung.
- Tệp ghi "(chat mới)": tệp đã có bối cảnh chung từ dòng 2. Gửi đúng như bước 2: dòng 1 = tên chat (dòng `# <mã> · <tên>` ngay dưới `═══ VIỆC CỦA LƯỢT NÀY ═══`, bỏ `#` và phần trong ngoặc tròn), xuống dòng, rồi từ `═══ BỐI CẢNH CHUNG` đến hết. Không mở hay bấm "Continue here" ở chat cũ của lượt đó.
- Chat cần dán đã quá dài (Claude Design hiện "Start a new chat to save … tokens of context") mà prompt sửa không ghi "chat mới": **không** tự chuyển sang chat mới, vì prompt sửa thiếu bối cảnh. Ghi DỪNG vào log với lý do "cần bản sửa tự đủ cho chat mới" để Claude Code viết lại.
- Kiểm "Xong khi" của prompt sửa, tải zip mã `<mã>`, ghi log, rồi quay lại lượt đang dở.

## Dừng và báo owner (ghi DỪNG vào log)
- Claude Design định gỡ, ẩn hoặc đổi chỗ một nút hay tính năng **đang có** mà prompt không yêu cầu.
- 2 vòng sửa vẫn chưa đạt một mục "Xong khi".
- Bị hỏi về tài khoản, thanh toán, chia sẻ, quyền truy cập, hoặc cần đăng nhập lại.
- Hết hạn mức Claude Design: ghi giờ, chờ 30 phút thì thử lại; sau 3 lần vẫn hết thì dừng.

## Không bao giờ
- Đổi cài đặt tài khoản hay project, chia sẻ hoặc publish project, xóa tệp thiết kế hay chat cũ.
- Sửa bất cứ gì trong `/opt/bswebsaas` (chỉ đọc).
- Tự trả lời mục "Cần owner chốt": prompt đã có phương án an toàn mặc định, Claude Design tự ghi vào README.
- Thao tác cùng một chat với owner. Thấy owner đang gõ trong chat (tin nhắn mới không phải của bạn) thì dừng và báo.
