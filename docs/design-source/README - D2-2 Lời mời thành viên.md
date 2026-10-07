# D2-2 · Lời mời thành viên — handoff

## Tổng quan
Bổ sung vào `Thành viên.dc.html` (không màn trùng): hộp "Mời thành viên" (thay thẻ "Chờ thiết kế · D2-2" của D2-1) và tab "Lời mời" (Mẫu 1, phân trang "‹ Trước · Sau ›"). Đường ghi đã có ở repo (mời, mời lại, hủy, nhận) → không gắn "Chờ backend" cho các việc đó. Thư mời và trang nhận lời mời không vẽ (khu đăng nhập giữ nguyên) — nội dung thật ghi ở mục "Thư mời và trang nhận lời mời".

## File
- `Thành viên.dc.html` — hộp Mời, tab Lời mời, dòng "N lời mời đang chờ — Xem" ở tab Thành viên, trạng thái.
- `shell-screens.js` — `users.tabs['loi-moi']` (status ready, mapRow 31). Mở tab bằng `bs-goto {screen:'users', params:{tab:'loi-moi'}}` hoặc `#/site/users?tab=loi-moi`.
- `SaaS Starter - Bản đồ màn hình.md` dòng 31 · `TODO.md` · `LESSONS.md`.
- Không sửa: Nhóm quyền, Email Settings, Admin Shell (khối mount `users` của D2-1 đã truyền `tab`), admin-base.css, admin-components.css, CLAUDE.md.

## Mức hoàn thiện
Hi-fi, tương tác đủ trong prototype (dữ liệu mẫu 9 lời mời, giờ tính từ 07/10/2026 10:00). Build: Livewire 4 + Blade + Tailwind 4 + `<x-bs-admin::*>`.

## Màn & trạng thái
| Trạng thái | Cách xem (Tweak) | Hiển thị |
|---|---|---|
| Có dữ liệu | Trạng thái › dataState = Có dữ liệu | Tab Thành viên + Lời mời |
| Chưa có lời mời | dataState = Trống, tab = loi-moi | "Chưa có lời mời nào" + "Mời thành viên" |
| Đang tải | dataState = Đang tải | Khung xám giữ chỗ, `aria-busy` |
| Lỗi | dataState = Lỗi | Dải đỏ + "Thử lại" |
| Không có quyền quản lý thành viên | role = Người viết… | Thẻ khóa + "Về Tổng quan" |
| Không có quyền mời (`users.invite`) | noInvitePerm = bật | Ẩn nút Mời; tab Lời mời chỉ xem + 1 câu lý do; không Gửi lại / Hủy |
| Sắp chạm giới hạn ngày | sent24 = 48 | Dải vàng "Hôm nay còn gửi được 2 thư mời." |
| Chạm giới hạn ngày | sent24 = 50 | Dải đỏ; nút Mời / Gửi lại / Mời lại khóa + giờ mở lại |
| Đăng nhập dưới tên khách hàng | impersonating = bật | Dải vàng + mọi nút khóa, câu khóa của D2-1 |
| Khóa theo gói | usersOn = tắt × upsell | Bật: "Xem gói nâng cấp"; tắt: "Chưa bật cho website này — liên hệ hỗ trợ" |
| Lỗi khi hủy (lời mời vừa được nhận) | failSave = bật | Toast `invalid` + "Tải lại" |
| Thành công | Gửi 1 địa chỉ | Toast "Đã gửi lời mời tới … Lời mời có hiệu lực trong 7 ngày." |

Ghi chú thiết kế ("Chờ backend") gom dưới Tweak "showNotes" (Hiện ghi chú thiết kế, mặc định bật).

## Tương tác
1. **Hộp "Mời thành viên"** (giữa màn ≥ 600px; toàn màn dưới 600px, chừa safe-area): ô email (dán nhiều địa chỉ, phân tách bằng phẩy / chấm phẩy / xuống dòng / dấu cách; bỏ trùng; báo địa chỉ sai dạng kèm ví dụ) · chọn nhóm (chỉ nhóm mình gán được, mỗi nhóm 1 dòng "Làm được: …" + "Xem chi tiết" mở hộp của `Nhóm quyền`) · lời nhắn ≤ 500 ký tự có bộ đếm · dòng "Lời mời có hiệu lực 7 ngày…" · dòng hạn mức ngày. Nút "Mời vào website" khóa (`aria-disabled`) + câu lý do ngay cạnh: "Chọn ít nhất 1 nhóm" / "Nhập ít nhất 1 email" / "Sửa địa chỉ email chưa đúng". Bấm khi đang gửi → bỏ qua (chặn bấm 2 lần, "Đang gửi…").
   - 1 địa chỉ thành công → đóng hộp + toast câu chuẩn. Nhiều địa chỉ hoặc có lỗi → màn kết quả từng địa chỉ (Đã gửi · Đã là thành viên · Đã nhận đủ thư hôm nay · Vừa gửi…) + "Mời thêm" (giữ lại địa chỉ chưa gửi được) · "Xong".
   - Địa chỉ đã có tài khoản và địa chỉ mới nhận **cùng một câu** — màn không biết, không lộ.
   - Đóng hộp khi còn chữ → giữ nháp + toast "Đã giữ lại email và lời nhắn…"; `onDirty(1)` + chặn rời trang.
2. **Tab "Lời mời"**: 4 nút trạng thái Đang chờ · Hết hạn · Đã hủy · Đã nhận (có số); cột Email (+ lời nhắn) · Nhóm quyền · Người mời · Gửi lúc · Hết hạn (+ chip "Còn N giờ" khi < 2 ngày); tìm theo email, lọc nhóm, chip "Đang lọc … Bỏ lọc · Khớp N / M", Dòng/trang 10/20/50 + "‹ Trước · Sau ›".
   - Đang chờ / Hết hạn: "Gửi lại" hiện sẵn (aria-label + dòng chú thích: liên kết cũ sẽ hết hiệu lực; khóa 60 giây sau khi gửi, nhãn đếm "Gửi lại sau N giây"; bấm trong lúc khóa → câu `resend_too_soon`). Menu "⋯": "Nhóm này làm được gì?" · vạch · "Hủy lời mời…" (đỏ, cuối) → hộp xác nhận, nút "Giữ lời mời" được focus, nút làm "Hủy lời mời".
   - Lời mời có nhóm ngang/cao hơn người xem → nút khóa + câu "nhờ <người mời> gửi lại".
   - Đã hủy: "Mời lại" (mở hộp điền sẵn). Đã nhận: "Xem thành viên".
3. **Tab Thành viên**: người đang chờ không nằm trong danh sách; đầu bảng 1 dòng "3 lời mời đang chờ — người được mời chỉ vào danh sách này sau khi nhận lời mời. Xem".
4. Esc đóng hộp/menu; mở hộp focus vào ô email, đóng trả focus về nút đã mở; toast `role="status"` `aria-live`.

## Mã lỗi (`InvitationRejectedException`, `lang/vi.json:827–836`) — câu đời thường
| Mã | Câu trên màn | Nơi hiện |
|---|---|---|
| no_roles | Hãy chọn ít nhất một nhóm quyền. (màn chặn trước: nút khóa + "Chọn ít nhất 1 nhóm") | Hộp Mời |
| already_member | Người này đã là thành viên của website. | Kết quả từng địa chỉ |
| resend_too_soon | Vừa gửi lời mời tới địa chỉ này. Hãy đợi một phút rồi gửi lại. | Gửi lại / Hộp Mời |
| address_daily_limit | Địa chỉ này đã nhận đủ số thư mời cho phép trong 24 giờ qua. Hãy nhắc người nhận xem cả mục Thư rác, hoặc gửi lại sau. | Gửi lại / Hộp Mời (mẫu: thu.mai@gmail.com đã nhận 3 thư) |
| daily_limit | Website đã gửi đủ 50 thư mời trong 24 giờ qua. Hãy thử lại sau HH:MM. | Dải đỏ + nút khóa |
| invalid | Admin: "Lời mời này vừa được nhận hoặc đã hủy. Hãy tải lại danh sách." · Trang nhận: "Liên kết mời này đã được dùng hoặc đã bị hủy. Hãy nhờ người mời gửi lời mời mới." | Toast / trang nhận |
| expired | Lời mời này đã hết hạn. Hãy nhờ người mời gửi lại. | Trang nhận |
| wrong_account | Lời mời này gửi tới một địa chỉ email khác. Hãy đăng nhập đúng tài khoản được mời. (+ nút Đăng xuất) | Trang nhận |
| no_longer_valid | Lời mời này không còn hiệu lực vì quyền của người mời đã thay đổi. Hãy nhờ gửi lời mời mới. | Trang nhận |
| account_exists | Địa chỉ này đã có tài khoản — hãy đăng nhập. + nút "Đăng nhập" | Trang nhận |

Repo còn chữ "vai trò" ở `error_no_roles`, `roles_label`, `mail_intro` → đổi thành "nhóm quyền"; `error_invalid` "huỷ" → "hủy" (Việc cho backend).

## Thư mời và trang nhận lời mời (không vẽ — nội dung thật)
- Thư (`MemberInvitationNotification`, `lang/vi.json:837–842`): tiêu đề "Lời mời tham gia quản trị website :site" · "Xin chào," · ":inviter mời bạn tham gia quản trị website :site với nhóm quyền: :roles." · lời nhắn (nếu có) · nút "Nhận lời mời" · "Lời mời có hiệu lực đến :date." · "Nếu bạn không mong đợi lời mời này, hãy bỏ qua email — không có gì thay đổi." Mọi người nhận cùng một thư.
- Trang nhận (`Admin/Livewire/AcceptInvitation.php`, `lang/vi.json:812–825`): đã có tài khoản → "Đăng nhập để nhận lời mời" (mật khẩu + lớp 2) → "Tham gia :site"; chưa có → "Tạo tài khoản và tham gia" (họ tên + mật khẩu); sai tài khoản → câu `wrong_account` + "Đăng xuất".
- Mẫu `team_invite` của `Email Settings.dc.html` lệch thư thật — ghi TODO "· D3-2".

## Ánh xạ dữ liệu
- `database/migrations/2020_01_01_000000_create_invitations_tables.php`: `invitations` (site_id, email, token băm, invited_by, expires_at, accepted_at, revoked_at, created_at, lời nhắn) + `invitation_roles`.
- `app/Modules/Rbac/Actions/InviteMember.php`: thứ tự kiểm no_roles → already_member → resend_too_soon (60 giây) → address_daily_limit (3 / 24 giờ) → daily_limit (50 / 24 giờ); hạn 7 ngày; mời lại địa chỉ đang chờ = làm mới dòng cũ. Prototype: `sendMany()`.
- `RevokeInvitation.php` → `askRevoke()`; `AcceptInvitation.php` → trang nhận.
- Trạng thái suy ra: accepted_at → Đã nhận · revoked_at → Đã hủy · expires_at ≤ now → Hết hạn · còn lại Đang chờ (`ist()`).
- Câu: `lang/vi.json:812–842` (`rbac.invitation.*`).

## Quyền & gói
- Xem màn: `users.manage`. Mời / Gửi lại / Hủy / Mời lại: `users.invite` (thiếu → ẩn nút, câu lý do 1 dòng).
- Nhóm gán được: thấp hơn cấp mình và không có việc mình không có (Quản trị không thấy Chủ website / Quản trị / Trợ lý chủ website).
- Đăng nhập hỗ trợ: mọi nút khóa + câu khóa. Module tắt: theo Tweak "Lời mời nâng gói".

## Token
Chỉ `var(--…)`: color-surface/-muted/-hover, body, border/-strong/-input, heading, body-text, body-muted, accent, admin-primary, admin-{success,warning,danger,info}-text, brand-gold(-text), on-solid. Focus 1b từ `admin-base.css` (`data-focus-kind="outline"` cho nút trạng thái). 0 hex ngoài `var()`.

## Icon (Remix 4.9.1)
ri-user-add-line · ri-mail-send-line · ri-mail-close-line · ri-mail-line · ri-mail-forbid-line · ri-time-line · ri-close-circle-line · ri-checkbox-circle-line · ri-error-warning-line · ri-information-line · ri-lock-2-line · ri-more-2-fill · ri-file-list-3-line · ri-loader-4-line · ri-close-line · ri-search-line · ri-shield-user-line · ri-refresh-line.

## Chuỗi cần dịch
Mời thành viên · Mời vào website · Đang gửi… · Email người được mời · Dán được nhiều địa chỉ… · Nhóm quyền · Lời nhắn (không bắt buộc) · Lời mời có hiệu lực 7 ngày… · Hôm nay website còn gửi được N thư mời… · Chọn ít nhất 1 nhóm · Nhập ít nhất 1 email · Sửa địa chỉ email chưa đúng · Đã gửi N / M lời mời · Mời thêm · Xong · Lời mời · Đang chờ · Hết hạn · Đã hủy · Đã nhận · Gửi lại · Gửi lại sau N giây · Hủy lời mời… · Giữ lời mời · Mời lại · Xem thành viên · Còn N giờ / ngày · N lời mời đang chờ · Chưa có lời mời nào · 10 câu mã lỗi.

## Kết quả 12 tiêu chí dễ dùng
1. Đạt — tiêu đề + mô tả; 1 nút chính "Mời thành viên"; hộp có 1 nút chính.
2. Đạt — Menu › Thành viên › Mời thành viên › Mời vào website (3 bước).
3. Đạt — không thuật ngữ kỹ thuật ("liên kết", "thư mời").
4. Đạt — nút có chữ; "⋯" và "Đóng" có aria-label + tooltip.
5. Đạt — ví dụ thật (ha.vu@anvinh.vn); lỗi nói cách sửa.
6. Đạt — giữ nháp khi đóng hộp, chặn rời trang, Hủy lời mời có hộp xác nhận (không hoàn tác).
7. Đạt — chip trạng thái màu + icon + chữ.
8. Đạt — nút 1 dòng (`nowrap`), email dài xuống dòng `overflow-wrap:anywhere`, lời nhắn "…" + title.
9. Đạt — thẻ gọn dưới 768px khung chứa, hộp toàn màn dưới 600px, vùng bấm 44px khi cảm ứng.
10. Đạt — người mới: 3 ô; người thạo: dán nhiều địa chỉ, lọc nhóm, tìm.
11. Đạt — Chữ lớn (`--fsp`, đọc cả `bs-ui.fontSize`) 0 tràn (đo dưới).
12. Đạt — "nhóm quyền", "Hủy", "Bỏ lọc", "Xóa khỏi website" theo Bảng thuật ngữ.

## Kết quả Chất lượng hoàn thiện (A–E)
- A. Đạt — thang 4/8px, cỡ chữ DS, chuyển động theo `admin-base.css` (reduced-motion).
- B. Đạt — chữ ≥ 14px, chú thích ≥ 12px, giãn dòng 1,5; nhãn ô luôn hiện; phản hồi < 1 giây ("Đang gửi…", toast).
- C. Đạt [SUY RA TỪ CODE] — Esc đóng, focus vào ô email khi mở, trả focus khi đóng, hộp có `aria-labelledby`, 1 h1 (ẩn khi nhúng). Tab thật trên bàn phím: [CHƯA KIỂM ĐƯỢC — môi trường chỉ đo bằng script] → Claude Code.
- D. [CHƯA KIỂM ĐƯỢC] tương phản chế độ tối bằng harness — chỉ dùng token đã đạt ở D2-1; Claude Code đo bằng zip.
- E. [ĐO BẰNG SCRIPT] `_audit/harness.html` `H.layouts(['Thành viên.dc.html'],[360,390,768,1024,1440])` đứng riêng: scrollWidth = clientWidth ở cả 5 bề rộng × chữ thường + Chữ lớn (`bs-ui.fontSize='large'`, đã trả giá trị cũ). Trong Shell `H.mk('s',390,844,0,0,'../Admin Shell.dc.html#/site/users?tab=loi-moi')`: sw 390 = cw 390, tab "Lời mời 4" mở đúng.

## Kiểm hiển thị
| Bề rộng | Chrome (thiết kế) | Safari iOS / Firefox / Samsung |
|---|---|---|
| 360 · 390 | Đạt — thẻ gọn, hộp toàn màn, 0 tràn | Chưa — Claude Code bằng zip |
| 768 · 1024 | Đạt — bảng rút gọn (Người mời vào dòng phụ < 1024) | Chưa — Claude Code |
| 1240 · 1440 | Đạt — bảng 6 cột | Chưa — Claude Code |
| 844×390 xoay ngang · 200% | Chưa kiểm được | Claude Code |

Verifier: xem kết quả lượt cuối; nếu công cụ quá giờ → [CHƯA KIỂM ĐƯỢC].

## Màn đang chạy · Bản mới · Giữ bản nào · Vì sao
| Màn đang chạy (D2-1) | Bản mới | Giữ | Vì sao |
|---|---|---|---|
| Tab "Lời mời đang chờ" | Tab "Lời mời" (đếm số đang chờ) | Mới | Tab chứa cả 4 trạng thái; prompt gọi "Lời mời" |
| Thẻ "Chờ thiết kế · D2-2" | Hộp Mời đầy đủ | Mới | Việc của lượt |
| Bố cục, nút, tìm, lọc của tab Thành viên | Không đổi | Cũ | Luật (e) |

## Cần owner chốt
- "Sao chép liên kết mời": KHÔNG vẽ. Lý do bảo mật: người cầm liên kết tạo được tài khoản cho địa chỉ đó mà không cần hộp thư; repo chỉ lưu liên kết dạng băm nên không lấy lại được.

## Việc cho backend
- Mời nhiều địa chỉ một lần (Chờ backend): repo nhận 1 địa chỉ / lần. Cần: một yêu cầu nhận danh sách, trả kết quả từng địa chỉ (mã lỗi riêng), áp các hạn mức theo thứ tự như gửi lần lượt; idempotency theo lần bấm.
- Danh sách lời mời theo trạng thái + tìm + phân trang "‹ Trước · Sau ›" ở máy chủ (chỉ mục `site_id, created_at`); repo chưa có màn danh sách lời mời.
- Trả `retry_at` cho `daily_limit` để màn ghi giờ mở lại; trả số thư đã gửi trong 24 giờ cho dòng hạn mức.
- `lang/*.json`: "vai trò" → "nhóm quyền" (`error_no_roles`, `roles_label`, `mail_intro`); "huỷ" → "hủy"; thêm câu admin cho `invalid` khi hủy lời mời vừa được nhận.
- Thư mời cần lời nhắn (≤ 500) và hạn — kiểm `MemberInvitationNotification` đã in lời nhắn.

## Gộp vào tệp chung
Chạy một mình — đã sửa thẳng `TODO.md`, `LESSONS.md`, `SaaS Starter - Bản đồ màn hình.md`; `shell-screens.js` đã có tab `loi-moi` từ đầu lượt.
