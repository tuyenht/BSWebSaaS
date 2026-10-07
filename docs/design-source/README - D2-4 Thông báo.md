# README — D2-4 · Thông báo trong ứng dụng

## Tổng quan
Chuông trên thanh trên cùng, trang **Thông báo** (dòng thời gian + "Tải thêm") và mục **Thông báo** trong Tài khoản của tôi › Tùy chọn. Cả ba đọc MỘT nguồn: `BSCW.notices` trong `content-workflow.js` (11 loại của repo + 2 loại Chờ backend, câu nguyên văn `lang/vi.json:860–884`). Thông báo thuộc **từng website**: chỉ hiện thông báo của website đang mở.
Gộp luồng phụ D2-2: README D2-2 › "Gộp vào tệp chung" ghi "Chạy một mình", không có dòng "Xong — chờ gộp" → bỏ qua.
Lượt kế tiếp: **D2-5**.

## File
- `Thông báo.dc.html` (mới) — trang Thông báo; link `admin-components.css`; Focus 1b dùng luật chung (không viết focus riêng).
- `Admin Shell.dc.html` — chỉ chuông (template khối `data-dz="notif"`, lớp `.ntf-*` trong `<style>`, `notifDefs` cũ → `BSCW.notices.feed`) + khối gắn `<sc-if mNotif><dc-import name="Thông báo" embedded …>` + bỏ `zoom` Chữ lớn cho `notifications` (màn dùng `--fsp`).
- `Tài khoản của tôi.dc.html` — chỉ mục Thông báo (thay thẻ "Chờ thiết kế · D2-4"), nạp thêm `content-workflow.js`, prop `role` thành Tweak (6 nhóm quyền).
- `content-workflow.js` — chỉ THÊM: `notices.KINDS · GROUPS · VI · feed · unread · badge · markRead · markUnread · markAll · restore · pref · setPref · digest · gets`.
- `shell-screens.js` — `notifications`: `pending` → `mount`, `ready`.
- `SaaS Starter - Bản đồ màn hình.md` dòng 7 · `TODO.md` · `LESSONS.md` (3 dòng) · `github.md`.
- Đo: `_audit/tmp/d24-run.html` (luồng chuông), `_audit/tmp/d24-mob.html` (chuông 360–1440), `_audit/tmp/d24-lay.html` (2 tệp × 5 bề rộng × chữ thường / Chữ lớn).

## Mức hoàn thiện
Hi-fi, tương tác thật trong phiên (đọc / chưa đọc, Hoàn tác, đổi tùy chọn kênh ảnh hưởng ngay chuông và trang). Dữ liệu mẫu sống trong phiên trang (`window.__bsNoticeRead`, `__bsNoticePref`).

## Màn & trạng thái
| Màn | Trạng thái vẽ |
|---|---|
| Chuông | có chưa đọc (số, "99+" khi ≥ 100 qua `badge()`) · không có chưa đọc (ẩn số, ẩn "Đánh dấu đã đọc tất cả") · trống ("Bạn chưa có thông báo mới.") · mặt Nền tảng (ghi chú Chờ backend) · đăng nhập hỗ trợ (nút đánh dấu khóa + lý do) · điện thoại < 600px: toàn màn, nút Đóng |
| Thông báo | đang tải (khung xương) · lỗi + "Thử lại" · trống riêng từng tab (6 câu, mỗi câu có nút đi tiếp) · tìm không ra ("Không có thông báo nào khớp «…»" + "Bỏ tìm") · có dữ liệu + "Tải thêm" · hết ("Đã hiện hết thông báo trong 90 ngày qua") · đăng nhập hỗ trợ · mặt Nền tảng · thông báo dẫn tới việc không còn quyền (dòng "Nhóm quyền của bạn đã đổi…") · thành công (toast + "Hoàn tác" 8 giây) |
| Tùy chọn thông báo | bình thường · dòng khóa (bảo mật) · đăng nhập hỗ trợ (mọi công tắc khóa + lý do) · mặt Nền tảng (thẻ thông tin) · Biên tập (không có dòng tên miền) · lưu thành công ("Đã lưu — áp dụng ngay") |
Không áp dụng: "không có quyền" cho trang Thông báo (ai đăng nhập cũng có thông báo của mình); "khóa theo gói" (thông báo không phải tính năng theo gói).

## Tương tác
- Bấm 1 mục (chuông hoặc trang) → `bs-goto {screen, params}` (params chỉ mã công khai `ma` / `tab` / `section` / `ten_mien`) + đánh dấu đã đọc → số trên chuông giảm.
- Tên miền: mở `settings` mục `domain` kèm `ten_mien` (đúng dòng). Bảo mật: mở `account` tab `security` / `sessions`.
- Đích không còn quyền: vẫn mở; Shell hiện thẻ "không có quyền" của màn đích (luật `noPerm` sẵn có), không ngõ cụt.
- "Đánh dấu đã đọc tất cả" → toast "Đã đánh dấu đã đọc N thông báo" + "Hoàn tác" 8 giây (`restore`). Mục lẻ: nút "Đã đọc".
- Tab: ←/→/Home/End; Esc trong ô tìm = Bỏ tìm. Chuông: Esc / bấm ra ngoài đóng (luật `drop` của Shell).
- "Tải thêm": prototype 8 mục mỗi lần (repo 20 mục, con trỏ ulid).

## Ánh xạ dữ liệu (repo `tuyenht/BSWebSaaS@main`)
- `app/Modules/Notifications/Registry/NotificationKinds.php` — 11 loại, nhóm, `in_app` / `mail` / `locked` → `BSCW.notices.KINDS`.
- `Models/SiteNotification.php` (`summary()` :41–58) — câu `.sentence` → `.change` → câu gốc → `feed()` ghép khóa câu.
- `Support/NotificationFeed.php` — `unreadCount` · `latest(20, afterUlid)` · `markRead(ulid)` · `markAllRead`.
- `Support/NotificationPreferences.php` — `users.preferences.notifications.<loại>.<in_app|mail>`; loại khóa luôn gửi → `pref()` / `setPref()` (khóa thì không ghi).
- `Support/Notify.php` — người nhận theo quyền trong website (`holdersOf`), không báo người gây ra việc.
- `Console/PruneSiteNotifications.php` — giữ 90 ngày.
- `lang/vi.json:565–566, 860–884` — `admin.notifications_empty` + câu `notifications.*`.
- `app/Modules/Forms/Actions/SubmitForm.php:113` — thư báo khách liên hệ theo từng Biểu mẫu.

### 11 loại × nhóm × mặc định × trạng thái backend
| Loại | Nhóm | Trong ứng dụng | Email | Khóa | Backend |
|---|---|---|---|---|---|
| workflow.pending_review | Duyệt bài | bật | tắt | — | đã phát (thư: chưa gửi — Chờ backend) |
| workflow.assigned | Duyệt bài | bật | tắt | — | đã phát (thư: Chờ backend) |
| workflow.returned | Duyệt bài | bật | tắt | — | đã phát (thư: Chờ backend) |
| workflow.published | Duyệt bài | bật | tắt | — | đã phát (thư: Chờ backend) |
| lead.new | Khách liên hệ | bật | tắt | — | đã phát (thư: Cần owner chốt) |
| security.new_device_login | Bảo mật | bật | bật | khóa | có nhưng chưa phát |
| security.two_factor_disabled | Bảo mật | bật | bật | khóa | đã phát |
| security.support_sign_in | Bảo mật | bật | bật | khóa | đã phát (mọi Chủ website) |
| security.domain_taken_over | Bảo mật | bật | bật | khóa | đã phát |
| system.storage_near_limit | Hệ thống | bật | bật | — | có nhưng chưa phát |
| system.domain_problem | Hệ thống | bật | bật | — | đã phát (5 câu failing · expiring · lost · recovered · wait_expired) |
| security.password_changed | Bảo mật | bật | bật | khóa | Chờ backend (đề xuất) |
| system.site_suspended | Hệ thống | bật | bật | — | Chờ backend (đề xuất) |

### 4 câu `.change` (thay đổi của bài đang đăng — không thêm loại mới)
- `notifications.workflow.assigned.change` — ":actor giao thay đổi của bài «:title» cho bạn."
- `notifications.workflow.returned.change` — ":actor trả lại thay đổi của bài «:title» để sửa thêm."
- `notifications.workflow.published.change` — ":actor đã cập nhật thay đổi của bài «:title» lên website."
- `notifications.workflow.pending_review.change` — ":actor gửi thay đổi của bài «:title» — đang chờ bạn duyệt."
Dòng TODO "· D2-4" của D1-4d: mỗi lần `BSCW.notices.push` (Page Editor lưu thay đổi chờ) hiện bằng câu `pending_review.change`, bấm mở đúng bài → đã đóng.

## Quyền & gói (ai thấy gì)
- Duyệt bài chờ / được giao: `entries.review|entries.approve` (Chủ website, Quản trị, Biên tập). Bị trả lại / đã đăng: người viết bài (Người viết, Cộng tác viên).
- Khách liên hệ: `forms.view`. Tên miền (2 loại): `domains.manage` (Chủ website + Quản trị) — Biên tập không thấy dòng này ở Tùy chọn.
- Nhân viên hỗ trợ đăng nhập: chỉ Chủ website. Bảo mật còn lại: mọi người (thông báo của chính tài khoản).
- Website đăng thẳng: không có thông báo bài chờ duyệt; tab Duyệt bài nói rõ lý do.
- Gói: không đổi gì (thông báo có ở mọi gói).

## Token
`--color-surface · surface-muted · surface-hover · border · border-strong · heading · body-text · body-muted · accent · admin-primary · admin-danger-solid · admin-{warning,success,danger,info}(-text) · placeholder · focus-ring-color · focus-inner`. Grep: 0 hex ngoài `var(…)` ở `Thông báo.dc.html`, mục Thông báo của Tài khoản, khối chuông của Shell.

## Icon (Remix 4.9.1)
`ri-notification-3-line · ri-notification-off-line · ri-check-double-line · ri-check-line · ri-close-line · ri-settings-3-line · ri-search-line · ri-time-line · ri-arrow-right-s-line · ri-arrow-down-line · ri-refresh-line · ri-wifi-off-line · ri-lock-line · ri-git-pull-request-line · ri-user-received-2-line · ri-arrow-go-back-line · ri-global-line · ri-mail-line · ri-device-line · ri-shield-cross-line · ri-shield-check-line · ri-shield-keyhole-line · ri-user-shared-line · ri-error-warning-line · ri-lock-password-line · ri-hard-drive-3-line · ri-pause-circle-line · ri-store-2-line · ri-bank-card-line · ri-send-plane-line · ri-chat-3-line · ri-information-line · ri-tools-line · ri-question-line · ri-checkbox-circle-line`.

## Chuỗi cần dịch (mới, ngoài `notifications.*` có sẵn)
Thông báo · Việc cần bạn chú ý trên website này… · Tùy chọn thông báo · Đánh dấu đã đọc tất cả · Đã đọc · Chưa đọc · Tất cả · Duyệt bài · Khách liên hệ · Bảo mật · Hệ thống · Thông báo được giữ 90 ngày, sau đó tự xóa. · Tải thêm · Đang hiện N / M thông báo · Đã hiện hết thông báo trong 90 ngày qua · Không có thông báo nào khớp «…» · Bỏ tìm · 6 câu trống theo tab · Đã đánh dấu đã đọc N thông báo · Hoàn tác · Nhóm quyền của bạn đã đổi… · Xem tất cả thông báo · nhãn việc (Mở bài · Sửa bài · Xem liên hệ · Mở hộp duyệt · Mở Tên miền · Bật lại xác thực 2 lớp · Xem thiết bị · Mở thư viện · Xem nhật ký hoạt động) · 13 tên loại + mô tả ở Tùy chọn · Luôn bật cả hai kênh — không tắt được. · Gom email thành 1 thư mỗi ngày · Báo qua Zalo: Để sau. · 2 câu đề xuất `security.password_changed`, `system.site_suspended`.

## Kết quả 12 tiêu chí dễ dùng
1. Đạt — tiêu đề + 1 dòng mô tả; nút chính duy nhất "Đánh dấu đã đọc tất cả".
2. Đạt — chuông → bấm mục = 2 bước tới việc cần làm.
3. Đạt — "bản ghi DNS" chỉ nằm trong câu repo `domain_problem.*` (nguyên văn) — Cần owner chốt câu đời thường hơn.
4. Đạt — chuông, đóng, tìm có tooltip + aria-label; mọi nút khác có chữ.
5. Đạt — ô tìm có ví dụ thật ("Anh Nam, máy phay 5 trục…").
6. Đạt — đọc tất cả có "Hoàn tác"; không có xóa.
7. Đạt — chưa đọc = chấm + chữ "Chưa đọc" + chữ đậm + nền; khóa = ổ khóa + chữ.
8. Đạt — tab 1 dòng (cuộn trong dải khi hẹp), nút `sx-t el` 1 dòng.
9. Đạt — chuông toàn màn ở 360/390, mục ≥ 64px; trang thẻ gọn < 600px.
10. Đạt — người mới: một nút chính; người thạo: tab theo nhóm, tìm, phím mũi tên.
11. Đạt — Chữ lớn (`--fsp`) 0 tràn ở 5 bề rộng.
12. Đạt — Bỏ tìm, Hoàn tác, Xóa (không dùng), kiểu dấu "Tùy".

## Kết quả Chất lượng hoàn thiện (A–E)
- A. Đạt — thang 4/8px, cỡ chữ 12/13/14/16/20 có sẵn; chuyển động 150ms, tắt khi `prefers-reduced-motion`.
- B. Đạt — chữ nội dung 14px, thời gian 12–13px, giãn dòng 1,45–1,5; phản hồi tức thì (toast `role="status"`).
- C. Một phần — 1 h1, ngày là h2; tab role="tablist" + mũi tên; toast `aria-live`. [CHƯA KIỂM ĐƯỢC] Tab trong hộp chuông (hộp không giữ focus — dùng luật `.pop` chung của Shell).
- D. [CHƯA KIỂM ĐƯỢC] đo tương phản chế độ tối bằng harness — chỉ dùng token, chờ Claude Code đo.
- E. Đo `_audit/tmp/d24-lay.html` (getBoundingClientRect / scrollWidth): 2 tệp × 360/390/768/1024/1440 × thường/Chữ lớn = 20/20 `scrollWidth = clientWidth`, 0 phần tử vượt khung. Chuông `_audit/tmp/d24-mob.html`: 360/390 hộp 0,0 toàn màn; 768/1024/1440 hộp 380px; 0 tràn; 0 lỗi console. Luồng `_audit/tmp/d24-run.html`: chuông 8 → 9 khi Người viết gửi duyệt → bấm mục mở `posts.edit?ma=<ulid>` đúng bài → chuông 8; màn Thông báo mount trong Shell; console 0 lỗi.

## Kiểm hiển thị
| Bề rộng | Chrome (môi trường thiết kế) | Safari iOS/iPad · Firefox · Samsung |
|---|---|---|
| 360 · 390 | đạt (đo) | chưa — Claude Code bằng zip |
| 768 · 1024 · 1440 | đạt (đo) | chưa — Claude Code bằng zip |
| Chữ lớn | đạt (đo, 5 bề rộng) | chưa |
| Xoay ngang 844×390 · 200% · tối | chưa — Claude Code bằng zip | chưa |

## Cần owner chốt
- Thư khách liên hệ ở Tùy chọn: Biểu mẫu đã gửi thư báo riêng (`SubmitForm.php:113`) — bật ở đây là thêm một thư. Mặc định tắt như repo.
- Chuông mặt Nền tảng: 4 loại đề xuất (website mới, tên miền lỗi hàng loạt, gói sắp hết hạn, phiên hỗ trợ) — nền tảng chưa có kho thông báo.
- Gộp thông báo nhiều website vào một chuông: cần đọc chéo website → không làm.
- Đăng nhập hỗ trợ: nhân viên xem được thông báo của thành viên nhưng không đánh dấu đã đọc thay họ (đề xuất an toàn).
- Câu `domain_problem.*` có "bản ghi DNS" — đổi câu đời thường hơn ở repo?

## Việc cho backend
- Lọc theo nhóm và chưa đọc ở máy chủ (`NotificationFeed::latest` thêm `group`, `unread`) — prototype lọc 20 mục đã tải.
- Tìm theo nội dung và người liên quan (lưu chữ tìm được của câu + `data.actor`) — ô tìm luôn hiện.
- Phát `security.new_device_login` (nhận diện thiết bị mới khi đăng nhập) và `system.storage_near_limit` (khi dung lượng ≥ 80%).
- Thêm loại `security.password_changed` (khóa, cả 2 kênh) và `system.site_suspended` (Chủ website + Quản trị) — câu đề xuất ở `BSCW.notices.VI`.
- Gửi thư cho 4 loại Duyệt bài khi người dùng bật Email (hiện repo chỉ gửi thư cho bảo mật + tên miền).
- Gom email 1 lần/ngày (`users.preferences.notifications.digest`), trừ bảo mật và tên miền.
- Kho thông báo mặt Nền tảng (sau khi owner chốt danh sách loại).
- `system.domain_problem`: gửi 1 lần cho cả cặp có / không `www`; người nhận = quyền `domains.manage`.

## Màn đang chạy · Bản mới · Giữ bản nào · Vì sao
| Màn đang chạy | Bản mới | Giữ | Vì sao |
|---|---|---|---|
| Chuông 4 mục cố định (`notifDefs`) | Chuông đọc `BSCW.notices.feed`, nhóm theo ngày | Bản mới | số đếm thật, khớp trang Thông báo |
| Đầu hộp chuông nền gradient | Đầu hộp nền thẻ + chip "N chưa đọc" | Bản mới | gradient trái DS (không gradient trên khung); vị trí giữ nguyên |
| Nút "Xem tất cả thông báo" cuối hộp | Giữ đúng chỗ, chữ | Giữ | không đổi |

## Verifier
Xem kết quả lượt kiểm ở dưới (cập nhật khi verifier trả về).
