# README — D2-3 · Tài khoản của tôi (07/10/2026)

## Tổng quan
Trang riêng `Tài khoản của tôi.dc.html` thay hộp "Tài khoản của tôi" (3 tab) của Admin Shell. Lấy hợp điểm hay của hộp cũ và `app/Modules/Admin/Livewire/Settings.php`. Một tệp cho 2 mặt (Website · Nền tảng), chạy đứng riêng hoặc nhúng trong Shell (khóa registry `account`, kind `mount`, embed-id `account`). Mẫu trang: mẫu 4 (Mục cài đặt) của trang chuẩn — cột mục bên trái ≥ 760px khung chứa, ô chọn "Mục" khi hẹp hơn.

## File
- `Tài khoản của tôi.dc.html` — mới. Link `admin-base.css` → `admin-components.css` → token DS → `styles.css` → Google Inter (URL chuẩn). Dùng `.sx-card .sx-btn .sx-in .sx-lab .sx-hint .sx-err .sx-chip .sx-note .sx-al .sx-seg .sx-opt .sx-check .dsw .sx-sk .fr` của `admin-components.css`; lớp riêng tiền tố `ac-`.
- `Admin Shell.dc.html` — gỡ hộp Tài khoản (`acctShow` 0, `acctModal` 0, hàm `openAcct/closeAcct/saveProfile/changePassword/toggle2fa/killSession/savePrefs` và state `acctDraft/pw*/twofaOn/killedSessions/pref*` đã bỏ); menu người dùng › "Tài khoản của tôi" phát `bs-goto {screen:'account'}` ở cả 2 mặt; khe mount `<sc-if mAccount>` + `dc-import name="Tài khoản của tôi"`; `UI_DEF`/`UI_OK` thêm `motion`, `contrast`; bỏ `zoom` Chữ lớn cho màn `account` (màn dùng `--fsp`). Giữ nguyên Khóa màn hình và hộp xác nhận Đăng xuất.
- `shell-screens.js` — mục `account`: kind `modal` → `mount`, `dc`, `embedId`, `props`, status `ready`, Trợ giúp mới.
- `SaaS Starter - Bản đồ màn hình.md` dòng 6 · `TODO.md` · `LESSONS.md` (3 dòng).

## Mức hoàn thiện
Hi-fi, tương tác đủ cho 5 mục. Phần repo chưa có gắn "Chờ backend" / "Cần owner chốt" (gom dưới Tweak "Hiện ghi chú thiết kế" = `showNotes`, mặc định bật).

## Màn & trạng thái
| Mục | Nội dung |
|---|---|
| Hồ sơ | Ảnh đại diện "Để sau" (chữ cái đầu) · Họ tên* (≤ 120, đếm ký tự) · Chức danh (≤ 80) · Số điện thoại (10 số) · Email đăng nhập + "Đổi email" (hộp 2 bước). Bỏ câu "Liên hệ quản trị viên…". Nút chính "Lưu hồ sơ" + "Bỏ thay đổi" + dòng trạng thái lưu. |
| Bảo mật | Đổi mật khẩu (hiện/ẩn từng ô, thước độ mạnh bằng chữ + gợi ý, "Mọi thiết bị khác sẽ tự đăng xuất"). Xác thực 2 lớp: Bước 1/3 quét QR (kèm khóa nhập tay + Sao chép khóa) → 2/3 nhập mã 6 số → 3/3 8 mã khôi phục (Tải xuống · Sao chép · ô "Tôi đã lưu" bắt buộc trước "Xong"). Đang bật: "Tạo lại mã khôi phục" (nhập lại mật khẩu) · "Tắt xác thực 2 lớp" (nhập lại mật khẩu, nút đỏ, tách riêng). |
| Phiên đăng nhập | Thiết bị · trình duyệt · website · nơi · lần hoạt động cuối; chip "Thiết bị này"; "Đăng xuất thiết bị này" từng dòng; "Đăng xuất mọi thiết bị khác". Thẻ gọn < 600px: tên + chip / dòng phụ "·" / nút rộng hết. |
| Tài khoản liên kết | Nhúng `Đăng nhập nhanh & Liên kết tài khoản` `screen="profile"` `account-type` = `site` / `platform` theo mặt — không vẽ lại. |
| Tùy chọn | Ngôn ngữ giao diện (cờ + tên bản địa) · Sáng / Tối / Theo máy · Cỡ chữ Bình thường / Lớn · Giảm chuyển động Theo máy / Luôn giảm / Không giảm · Tương phản cao (Chờ backend) · Múi giờ "Theo website" (Cần owner chốt) · Thông báo: 1 dòng + thẻ "Chờ thiết kế · D2-4" (bỏ công tắc email của hộp cũ). Áp dụng ngay, không có nút Lưu (như Tùy biến). |

Trạng thái (Tweak "Trạng thái màn"): Bình thường · Đang tải (khung xám, không chạy animation) · Lỗi tải ("Thử lại") · Vừa lưu. Tweak "Mô phỏng mất mạng khi lưu" → "Chưa lưu được vì mất kết nối. Chữ bạn nhập vẫn còn — bấm Thử lại." Tweak "Đăng nhập dưới tên khách hàng" (`impersonating`, chỉ mặt Website): dải cảnh báo đầu trang + mọi ô `disabled`, mọi nút `aria-disabled` + `aria-describedby` trỏ câu repo `admin.support.locked` "Không làm được việc này khi nhân viên hỗ trợ đang đăng nhập thay thành viên."; câu đó lặp ở chân từng thẻ; khung Tài khoản liên kết `inert`. Mặt Nền tảng + 2 lớp "Chưa bật": mở thẳng mục Bảo mật + dải hướng dẫn.
Không áp dụng: "không có quyền" — mọi người đăng nhập đều sửa được tài khoản của chính mình (`Settings.php` chỉ chặn phiên hỗ trợ); "khóa theo gói" — tài khoản cá nhân không thuộc gói. Trống: không có (hồ sơ luôn có email); danh sách phiên chỉ còn thiết bị này → "Chỉ còn thiết bị này đang đăng nhập." + nút "Đăng xuất mọi thiết bị khác" khóa kèm lý do.

## Tương tác (dữ liệu mẫu để thử)
- Mật khẩu hiện tại `sai` → lỗi "Mật khẩu hiện tại chưa đúng…". Mật khẩu mới 9 ký tự → "Mật khẩu mới cần ít nhất 10 ký tự — còn thiếu 1 ký tự." Mật khẩu bị lộ mẫu: `1234567890`, `matkhau123`, `password123`… Mã 6 số `000000` = mã sai (2 lớp và đổi email). Mật khẩu `sai` trong hộp tắt 2 lớp / tạo lại mã / đổi email = sai.
- Kiểm lỗi khi rời ô; bấm Lưu khi còn lỗi → focus ô lỗi đầu tiên. Nút đang chạy đổi chữ ("Đang lưu…") và chặn bấm 2 lần.
- Hộp thoại: focus vào trong (hộp nhập lại mật khẩu: nút Hủy được focus), Tab vòng trong hộp, Esc / bấm nền đóng, trả focus về nút đã mở; < 600px trượt từ đáy, chừa safe-area.
- Bàn phím mục: ↑ ↓ ← → Home End trong cột mục (role tablist).
- Nhúng: `onDirty(n)` = số ô hồ sơ đã đổi + 1 nếu đang gõ mật khẩu; nghe `bs-embed-cmd {id:'account', act:'save'|'reset'}`; chặn `beforeunload` khi còn thay đổi. Mục còn chưa lưu có dấu "Chưa lưu" trên cột mục.
- Tùy chọn khi nhúng: gọi `onPref(k, v)` → Shell `setState` (`scheme`, `fontSize`, `motion`, `contrast`, `lang`) → Shell tự ghi `bs-ui` như Tùy biến → menu người dùng › Giao diện thấy cùng giá trị. Đứng riêng: trang ghi thẳng `bs-ui` (version 1) + `bs-scheme`.
- Shell mở thẳng một mục: `bs-goto {screen:'account', params:{tab:'security'}}` → prop `start-tab`.

## Ánh xạ dữ liệu (repo `tuyenht/BSWebSaaS@main`)
| Thiết kế | Repo |
|---|---|
| Họ tên ≤ 120 | `app/Modules/Admin/Livewire/Settings.php` `saveProfile()` (`max:120`) |
| Đổi mật khẩu: cần mật khẩu hiện tại, ≥ 10 ký tự, không bị lộ, mọi thiết bị khác đăng xuất | `Settings.php` `changePassword()` (`current_password`, `Password::defaults()`, AuthenticateSession) · `app/Providers/AppServiceProvider.php:40` (`Password::min(10)->uncompromised()`) |
| QR → mã → 8 mã khôi phục hiện 1 lần | `Settings.php` `startTwoFactor()` · `confirmTwoFactor()` · `TwoFactorService` |
| Tắt 2 lớp: ghi nhật ký + cảnh báo chuông và thư | `Settings.php` `disableTwoFactor()` (`Audit::record('auth.2fa.disabled')`, `TwoFactorDisabledNotification`) |
| Gỡ liên kết Google / Facebook | `Settings.php` `disconnect()` (qua màn Đăng nhập nhanh) |
| Khóa cả trang khi đăng nhập hỗ trợ | `Settings.php:52` `SupportSession::forbid()` · chuỗi `lang/vi.json:212` `admin.support.locked` |
| Mã sai | `lang/vi.json:97` `auth.two_factor_invalid` (giữ ý, thêm cách sửa) · `lang/vi.json:116` `admin.recovery_codes_hint` (nguyên văn) · `lang/vi.json:553` `admin.password_changed` |
| 2 lớp bắt buộc với nhân sự nền tảng | `app/Modules/Identity/Http/Middleware/EnsureTwoFactorEnabled.php` · `routes/web.php:281` (lỗi 423) |

## Quyền & gói
Mọi người đăng nhập thấy trang của chính mình. Mặt Nền tảng: không có nút "Tắt xác thực 2 lớp", chip "Bắt buộc với nhân sự nền tảng". Phiên hỗ trợ (mặt Website): chỉ xem. Không phụ thuộc gói, không có nút AI.

## Token
Chỉ token: `--color-surface/-hover/-muted`, `--color-body`, `--color-border/-strong/-input`, `--color-heading`, `--color-body-text`, `--color-body-muted`, `--color-accent`, `--color-admin-primary`, `--color-admin-{success,warning,danger,info}(-text|-solid)`, `--color-placeholder`, `--fsp`. Grep: 0 mã hex ngoài `var(…)`. Toast đảo màu bằng `--color-heading` / `--color-surface`. Focus = luật chung 1b (không viết focus riêng, không `outline:none`; ô mật khẩu dùng khung bao `data-focus-wrap`).

## Icon (Remix 4.9.1)
ri-user-3-line · ri-shield-keyhole-line · ri-computer-line · ri-links-line · ri-settings-3-line · ri-shield-user-line · ri-global-line · ri-server-line · ri-lock-2-line · ri-wifi-off-line · ri-refresh-line · ri-time-line · ri-tools-line · ri-question-line · ri-mail-settings-line · ri-save-3-line · ri-arrow-go-back-line · ri-loader-4-line · ri-edit-line · ri-checkbox-circle-line · ri-error-warning-line · ri-alert-line · ri-information-line · ri-eye-line · ri-eye-off-line · ri-lock-password-line · ri-shield-check-line · ri-shield-line · ri-shield-cross-line · ri-qr-code-line · ri-file-copy-line · ri-arrow-right-line · ri-arrow-left-line · ri-download-2-line · ri-smartphone-line · ri-macbook-line · ri-android-line · ri-logout-box-r-line · ri-sun-line · ri-moon-line · ri-notification-3-line · ri-draft-line · ri-send-plane-line · ri-mail-send-line · ri-tablet-line.

## Chuỗi cần dịch (khóa đề xuất `admin.account.*`)
Tiêu đề 5 mục; nhãn ô; mọi câu lỗi trong `profErrs()`, `pwErrs()`, `tfaConfirm()`, `emSend()`, `emConfirm()`, `dlgGo()`; thước độ mạnh (Yếu · Trung bình · Mạnh · Rất mạnh + gợi ý); 3 bước 2 lớp; 2 hộp thoại; toast; câu phiên đăng nhập; Tùy chọn. Dùng lại khóa có sẵn: `admin.support.locked`, `admin.recovery_codes_hint`, `admin.password_changed`, `auth.two_factor_invalid`. Repo còn "2FA" trong `admin.two_factor_on` / `admin.enable_2fa` / `admin.disable_2fa` → đổi thành "xác thực 2 lớp".

## Màn đang chạy · Bản mới · Giữ bản nào · Vì sao
| Màn đang chạy (hộp Shell) | Bản mới | Giữ | Vì sao |
|---|---|---|---|
| 3 tab trong hộp 760px | Trang riêng 5 mục | Bản mới | Prompt chọn trang riêng; hộp không co được trên điện thoại |
| Mật khẩu "Tối thiểu 8 ký tự" | ≥ 10 + không bị lộ | Bản mới | Đúng repo |
| Công tắc 2 lớp bật ngay | 3 bước QR → mã → mã khôi phục | Bản mới | Đúng repo, công tắc cũ bật mà không có mã |
| Email khóa + "Liên hệ quản trị viên site" | "Đổi email" 2 bước | Bản mới | Prompt bỏ câu cũ |
| Danh sách phiên (3 dòng) | Thêm trình duyệt · website · lần cuối · đăng xuất tất cả | Gộp | Giữ dữ liệu mẫu, thêm cột |
| Ngôn ngữ dạng ô chọn chữ | Cờ + tên bản địa | Bản mới | Cùng nguồn nút Ngôn ngữ giao diện |
| Công tắc "Nhận thông báo qua email" | Thẻ "Chờ thiết kế · D2-4" | Bản mới | Prompt bỏ |
| Ô "Múi giờ" 3 lựa chọn | Mặc định "Theo website" + Cần owner chốt | Gộp | Giữ ô, đổi mặc định |

## Kết quả 12 tiêu chí dễ dùng
1. Đạt — h1 + 1 dòng mô tả (đứng riêng; nhúng thì Shell vẽ từ registry); mỗi thẻ 1 nút chính.
2. Đạt — menu tài khoản › Tài khoản của tôi › mục (≤ 3 bước).
3. Đạt — không "2FA", "OAuth", "token"; khóa nhập tay giải thích "Không quét được? Nhập khóa này vào ứng dụng".
4. Đạt — nút chỉ icon (hiện/ẩn mật khẩu) có `aria-label` + `title`.
5. Đạt — placeholder chỉ ví dụ ("0901 234 567", "ten@congty.vn", "123 456"); lỗi nói cách sửa.
6. Đạt — chặn rời trang khi còn thay đổi; tắt 2 lớp / tạo lại mã có hộp xác nhận nói hệ quả; không xóa dữ liệu nào.
7. Đạt — trạng thái có chip icon + chữ (Đang bật · Chưa bật · Thiết bị này · Để sau); thước độ mạnh có chữ.
8. Đạt — nút/chip/tab `white-space:nowrap`; đo 0 tràn ngang (bảng dưới).
9. Đạt (đứng riêng) — mọi việc làm được ở 360–768; vùng bấm 44px (pointer:coarse qua `admin-components.css`); trong Shell [CHỜ GỘP].
10. Đạt — người mới thấy 1 việc mỗi thẻ; phím mũi tên cho cột mục; "Đăng xuất mọi thiết bị khác" cho người thạo.
11. Đạt — Chữ lớn (`--fsp` +2px) đo 0 tràn ở 5 bề rộng.
12. Đạt — Xóa/Hủy/Khóa/tùy theo Bảng thuật ngữ; "Tải xuống", "Sao chép", "Lưu", "Hủy", "Quay lại".

## Kết quả Chất lượng hoàn thiện (A–E)
- A. Đạt — thang 4/8px, cỡ chữ của DS (12/13/14/16/17/20 + `--fsp`); chuyển động 150ms của component, tắt theo `prefers-reduced-motion` và tùy chọn "Luôn giảm" (`data-motion`).
- B. Đạt — chữ ≥ 13px, chú thích 12px, giãn dòng 1,5; nhãn luôn hiện; phản hồi < 1 giây ("Đang lưu…"/toast); mất mạng giữ chữ + Thử lại. Không việc nào chỉ làm bằng rê chuột/kéo thả.
- C. Đạt (suy ra từ code) — 1 h1; hộp thoại có `aria-labelledby`; toast + trạng thái lưu `role="status" aria-live`; Esc / trả focus / Tab vòng. [CHƯA KIỂM ĐƯỢC bằng trình đọc màn hình thật — môi trường thiết kế không có.]
- D. Chưa đo tối — chỉ dùng token, nhưng [CHƯA KIỂM ĐƯỢC] tương phản ở `data-bs-theme="dark"` bằng getComputedStyle (công cụ đo chỉ dùng được qua ảnh chụp lượt này) · Claude Code bằng zip.
- E. Đo bằng `_audit/harness.html` `H.layouts` (đứng riêng): xem bảng dưới. Console mở trang: 0 lỗi. Shell: 0 lỗi sau sửa (chỉ cảnh báo cũ `{{ r.* }} never resolved` có từ trước); đo trong Shell [CHƯA KIỂM ĐƯỢC] — Shell quá 7 giây.

## Kiểm hiển thị
| Bề rộng | Chữ thường (scrollWidth / clientWidth) | Chữ lớn | Ghi chú |
|---|---|---|---|
| 360 | 345 / 345 · đạt | 345 / 345 · đạt | ô chọn "Mục", thẻ gọn |
| 390 | 375 / 375 · đạt | 375 / 375 · đạt | |
| 768 | 753 / 753 · đạt | 753 / 753 · đạt | cột mục trái |
| 1024 | 1009 / 1009 · đạt | 1009 / 1009 · đạt | |
| 1440 | 1425 / 1425 · đạt | 1425 / 1425 · đạt | |
| 844×390 ngang · 200% | [CHƯA KIỂM ĐƯỢC] | | Claude Code |
| Trong Shell 390 `#/site/account`, `#/platform/account` | [CHƯA KIỂM ĐƯỢC] | | Shell quá giờ công cụ chụp |
| Safari iPhone/iPad · Firefox · Samsung Internet | [CHƯA KIỂM ĐƯỢC] | | `:has()` cho viền lỗi ô mật khẩu cần Safari 15.4+ (có), container query Safari 16+ |

Khung xem 390 và 768: cuối trang đứng riêng (tắt khi nhúng hoặc `#frame`).

## Việc cho backend
| Tính năng | Dữ liệu cần lưu | Luật |
|---|---|---|
| Hồ sơ: chức danh, số điện thoại | `users.preferences.profile.{title, phone}` hoặc cột riêng | chức danh ≤ 80; điện thoại 10 số bắt đầu 0 |
| Ảnh đại diện (để sau) | tệp ảnh trong Thư viện | fit theo tỷ lệ gốc (§7d) |
| Đổi email tự phục vụ | `pending_email`, mã 6 số (hết hạn 15 phút), mã chặn cho địa chỉ cũ | nhập lại mật khẩu → mã tới địa chỉ mới → đổi → thư báo địa chỉ cũ kèm nút "Không phải tôi" (khôi phục email cũ + đăng xuất mọi phiên) — Cần owner chốt |
| Danh sách phiên + đăng xuất từng thiết bị / mọi thiết bị khác | bảng phiên (thiết bị, trình duyệt, website, nơi, lần hoạt động cuối) | đăng xuất = hủy phiên; ghi nhật ký |
| Tạo lại mã khôi phục | `two_factor_recovery_codes` mới | nhập lại mật khẩu; mã cũ hết hiệu lực ngay; ghi nhật ký |
| Nhập lại mật khẩu khi tắt 2 lớp | — | `disableTwoFactor()` thêm `current_password` (repo chưa đòi) |
| Trang bật 2 lớp cho nhân sự nền tảng | — | `routes/web.php:281` thay lỗi 423 bằng chuyển tới trang này, mục Bảo mật, dải hướng dẫn |
| Ngôn ngữ giao diện theo tài khoản | `users.preferences.locale` | `SetLocale`: session → preferences.locale → mặc định website (github.md mục 1) |
| Tùy chọn giao diện theo tài khoản | `users.preferences.theme` (đang có) + `fontSize`, `motion`, `contrast` | cùng nguồn `admin.theme.save` / `admin.theme.reset` |
| Tương phản cao | bộ màu tương phản cao | áp qua token, đạt AAA cho chữ thân |

## Cần owner chốt
- Đổi email tự phục vụ — email là tên đăng nhập trên mọi website và là nơi nhận lời mời; phương án vẽ: nhập lại mật khẩu + mã xác minh địa chỉ mới + thư báo địa chỉ cũ có "Không phải tôi".
- Ô múi giờ riêng theo người — CLAUDE.md D11: giờ hiện theo múi giờ website; mặc định "Theo website".

## Verifier
Xem kết quả lượt ready_for_verification bên dưới (cập nhật khi có).
