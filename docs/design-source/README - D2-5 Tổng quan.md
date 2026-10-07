# D2-5 · Tổng quan theo quyền (mặt Website) — handoff

## Tổng quan
Bảng điều khiển của mặt Website, chuyển nguyên từ khối Tổng quan của Admin Shell (thẻ số liệu, biểu đồ lượt xem + chọn ngày, hộp duyệt, nội dung gần đây, "Bắt đầu") sang `Tổng quan.dc.html`, rồi mở rộng theo Mẫu 7 (`Chuẩn giao diện SaaS Starter.dc.html#mau-7`). Mọi khối hiện theo **quyền** (`BSCW.has` hoặc danh sách quyền của nhóm tùy chỉnh + `BSCW.IMPLIES`), không theo tên nhóm. Tổng quan nền tảng là D4-1 — không làm ở đây.

Repo hiện chỉ có dòng chào (`app/Modules/Admin/Livewire/Dashboard.php:15–22`, view `admin::livewire.dashboard`). Repo chưa có bộ đếm tổng hợp nào, chưa đo dung lượng thư viện đã dùng (`media.storage_mb` chỉ là giới hạn) → mọi con số là số mẫu, gắn "Chờ backend".

## File
- `Tổng quan.dc.html` — mới (template + logic + Tweaks).
- `Admin Shell.dc.html` — gỡ khối Tổng quan (dòng mô tả, cụm nút khoảng ngày + "Thêm nội dung", "Bắt đầu", lưới số liệu / biểu đồ / hộp duyệt / bảng nội dung); thêm khe `<sc-if mDash>` + `dc-import name="Tổng quan" embedded …`; bỏ `zoom` Chữ lớn cho `dashboard` (màn dùng `--fsp`). Logic cũ trong renderVals chưa dọn — TODO (L1-21).
- `shell-screens.js` — `dashboard`: `kind: 'mount'`, `dc: 'Tổng quan'`, `embedId: 'dashboard'`, `ownHeader: true`, `status: 'ready'`.
- `SaaS Starter - Bản đồ màn hình.md` dòng 4 · `TODO.md` · `LESSONS.md` (3 dòng).

## Mức hoàn thiện
Hi-fi, tương tác được (đổi khoảng ngày, chọn ngày, tab Duyệt bài, Thử lại từng thẻ, Ẩn / Hiện lại "Bắt đầu" + Hoàn tác 8 giây, mọi nút đi màn bằng `bs-goto`). Số liệu mẫu.

## Màn & trạng thái
| Trạng thái | Cách xem |
|---|---|
| Có dữ liệu | mặc định |
| Website mới (trống) | Tweak "Website mới tinh" — số 0, biểu đồ "Chưa có lượt xem nào", "Bắt đầu" hiện, Việc cần làm ẩn |
| Đang tải | 450 ms khi mở (khung xương từng thẻ, không chạy animation) · Tweak "Trạng thái tải = Đang tải" giữ nguyên |
| Lỗi từng thẻ + Thử lại | Tweak "Lỗi từng thẻ" — thẻ Khách liên hệ + biểu đồ lỗi, thẻ khác vẫn đúng; Thử lại → khung xương 700 ms → có số |
| Module tắt theo gói | Tweak "Tắt module" — thẻ Sản phẩm / Khách liên hệ / Dung lượng thành khóa trung tính "Chưa bật cho website này — liên hệ hỗ trợ"; "Lời mời nâng gói" bật → 1 dòng lợi ích + "Xem gói nâng cấp"; nút Làm nhanh của module tắt ẩn |
| Số lớn | Tweak "Website lớn" — 12,5K bài, 2,3K khách liên hệ, biểu đồ đơn vị K; dung lượng 86% + chip "Sắp đầy" |
| Không có quyền | Không áp dụng: ai đăng nhập cũng vào được Tổng quan; khối không có quyền thì ẩn |

## Khối × điều kiện quyền (không điều kiện nào dùng tên nhóm)
| Khối | Hiện khi |
|---|---|
| Nút chính "Viết bài mới" | `entries.create` |
| Việc cần làm · khách liên hệ chưa xem | `forms.view` + module `contacts` bật + có liên hệ chưa xem |
| Việc cần làm · bài chờ bạn duyệt | quy trình BẬT + `entries.approve` hoặc `entries.review` + hàng chờ (`BSCW.store.queue`) > 0 |
| Việc cần làm · bài của bạn bị trả lại | `entries.edit.own` và KHÔNG `entries.edit.any` + quy trình BẬT + có bài bị trả lại |
| Việc cần làm · tên miền cần sửa "sửa trước <ngày>" | `domains.manage` |
| Bắt đầu (6 việc, vòng tiến độ, Ẩn danh sách này) | Tweak "Website mới tinh" + `settings.manage`; mỗi việc lọc thêm theo quyền làm việc đó (Trang chủ: `entries.edit.any|entries.edit.own`, Trình đơn: `menus.manage`, Tên miền: `domains.manage`) |
| Thẻ Trang · Bài viết | `entries.view` |
| Thẻ Sản phẩm | `catalog.view` (module tắt → khóa) |
| Thẻ Khách liên hệ · 7 ngày | `forms.view` (module tắt → khóa) |
| Thẻ Dung lượng thư viện so với gói | `settings.manage` (module `media` tắt → khóa) |
| Lượt xem website | `settings.manage` hoặc `entries.edit.any` |
| Duyệt bài (Chờ duyệt · Giao cho tôi · Đã hẹn giờ) | quy trình BẬT + `entries.approve` hoặc `entries.review` |
| Bài của tôi (theo trạng thái + bị trả lại kèm lý do) | `entries.edit.own` và KHÔNG `entries.edit.any` |
| Nội dung gần đây | `entries.view` + có một trong `entries.create|edit.own|edit.any`; nút Sửa theo `edit.any` / `edit.own` + của mình |
| Nội dung mới đăng (chỉ xem, không nút tạo) | `entries.view` và không có quyền tạo / sửa — chỉ bài đã đăng, nút "Xem" |
| Hoạt động gần đây | `activity.view` (quyền đề xuất, `BSCW.PROPOSED`: owner · admin) |
| Làm nhanh | từng nút theo quyền + module: Thêm trang `entries.create` · Thêm sản phẩm `catalog.manage` · Tải ảnh lên `media.upload` · Mời thành viên `users.invite` · Sửa trình đơn `menus.manage`; không còn nút nào → thẻ ẩn |
| Cần giúp? | luôn hiện |

Kết quả Tweak (6 nhóm × không duyệt / duyệt trước khi đăng), đọc từ `BSCW.ROLES`:
- Chủ website · Quản trị: Việc cần làm (liên hệ, tên miền; + chờ duyệt khi bật) · Bắt đầu (khi website mới) · 4 thẻ · Lượt xem · Duyệt bài (khi bật) · Nội dung gần đây · Hoạt động · Làm nhanh.
- Biên tập: không có `settings.manage` → không thẻ Dung lượng, không Bắt đầu; có Lượt xem (`edit.any`); bật quy trình → "4 bài đang chờ bạn duyệt" + Duyệt bài.
- Người viết / Cộng tác viên: Bài của tôi + "Viết bài mới"; bật quy trình → tile "Chờ duyệt", "Bị trả lại" + dòng Việc cần làm.
- Chỉ xem: 0 nút tạo — chỉ thẻ Trang · Bài viết · Khách liên hệ (`forms.view`), "Nội dung mới đăng" với nút Xem, Cần giúp.
- Tùy chỉnh · Chỉ duyệt bài (`entries.view/review/approve`): bật quy trình → Duyệt bài + Việc cần làm, không nút tạo, không Bài của tôi. Tùy chỉnh · Chăm sóc khách: thẻ Khách liên hệ + dòng "khách chưa xem".

## Tương tác
- Mọi nút đi màn phát `bs-goto {screen, params}` (đứng riêng: toast "Trong trang quản trị, nút này mở màn …"): `contacts` · `review {ma}` · `<loại>.edit {ma}` · `settings {section: general|appearance|langs|domain|quota}` · `pages.edit {ma}` (Trang chủ) · `menus` · `pages.list` · `posts.list {tac_gia:'toi', trang_thai}` · `catalog.list` · `media` · `users {tab:'loi-moi'}` · `activity`. Params chỉ mã công khai / khóa — 0 id số.
- Biểu đồ: 7 ngày · 30 ngày · Năm · "Chọn ngày" (hộp giữ focus vòng, Esc / bấm ra ngoài đóng và trả focus về nút, kiểm 90 ngày gần nhất, lỗi nói cách sửa).
- Tab Duyệt bài: ←/→/Home/End.
- "Ẩn danh sách này": ẩn ngay + toast "Hoàn tác" 8 giây; mở lại ở thẻ "Cần giúp?".
- Đứng riêng: gieo 4 bài "chờ duyệt" vào `BSCW.store` để xem hàng chờ; khi nhúng đọc đúng kho của Shell (số khớp menu Hộp duyệt — trong Shell hàng chờ chỉ có bài vừa gửi trong phiên).
- Hợp đồng nhúng §13: `embedded` (giữ h1 + nút chính vì `ownHeader`, bỏ khung 100dvh), `onDirty(0)`, nghe `bs-embed-cmd` (không có gì để lưu → trả `onDirty(0)`).

## Ánh xạ dữ liệu (file:dòng repo)
- Nhóm / quyền: `app/Modules/Rbac/Registry/PermissionRegistry.php:141–206` → `BSCW.ROLES`; `IMPLIES` 71–84 → `BSCW.IMPLIES`; `activity.view` đề xuất (Chờ backend).
- Quy trình: `WorkflowTemplateRegistry` → `BSCW.wf`, hàng chờ `BSCW.store.queue`.
- Trang · bài: bảng `entries` (+ `status`, migration 2012) — số đếm Chờ backend.
- Sản phẩm: `products` · Khách liên hệ: `form_submissions` (`Forms/Inbox.php`) · Dung lượng: giới hạn `media.storage_mb` (`FeatureRegistry`, `BSAI.PLAN_FEATURES`), đã dùng Chờ backend.
- Tổng quan hiện có: `app/Modules/Admin/Livewire/Dashboard.php` (chỉ tiêu đề + view).

## Quyền & gói (ai thấy gì)
Xem bảng "Khối × điều kiện quyền". Gói: dung lượng theo `BSAI.feature('media.storage_mb', plan)`; "Quy trình biên tập" khi gói không có `workflow.editorial` tự về "Duyệt trước khi đăng". Module tắt: khóa trung tính / "Xem gói nâng cấp" theo Tweak "Lời mời nâng gói".

## Token
`--color-body · surface · surface-hover · border · border-strong · border-input · heading · body-text · body-muted · accent · admin-primary · brand-solid · brand · admin-{info,warning,danger,success}(-text) · white`. Grep: 0 hex ngoài `var(…)`, 0 `style-focus-within`, 0 `outline:none`. Focus 1b dùng luật chung (`data-focus-kind` solid / outline / ghost; tab dùng `[role=tab]`).

## Icon (Remix 4.9.1)
ri-add-line · ri-checkbox-multiple-line · ri-mail-unread-line · ri-inbox-archive-line · ri-arrow-go-back-line · ri-global-line · ri-arrow-right-line · ri-eye-off-line · ri-file-text-line · ri-article-line · ri-box-3-line · ri-mail-line · ri-hard-drive-2-line · ri-lock-line · ri-error-warning-line · ri-refresh-line · ri-calendar-2-line · ri-bar-chart-2-line · ri-time-line · ri-file-add-line · ri-upload-2-line · ri-user-add-line · ri-menu-2-line · ri-phone-line · ri-chat-3-line · ri-checkbox-circle-line (+ icon loại / trạng thái từ `BSCW`).

## Chuỗi cần dịch
Tổng quan · Đây là tình hình :site hôm nay. · Viết bài mới · Việc cần làm hôm nay · :n việc · :n khách liên hệ chưa xem · Xem khách liên hệ · :n bài đang chờ bạn duyệt · Mở Hộp duyệt · :n bài của bạn bị trả lại · Sửa bài · Tên miền :domain chưa trỏ về website · Sửa trước :date — … · Sửa tên miền · Bắt đầu với website của bạn · Ẩn danh sách này · Đã xong :a/:b việc · 6 việc (tiêu đề, mô tả, nút) · Trang · Bài viết · Sản phẩm · Khách liên hệ · 7 ngày · Dung lượng thư viện · Sắp đầy · Chưa tải được số này. Các thẻ khác vẫn đúng. · Thử lại · Chưa bật cho website này — liên hệ hỗ trợ. · Xem gói nâng cấp · Lượt xem website · 7 ngày · 30 ngày · Năm · Chọn ngày · Chọn khoảng ngày · Từ ngày · Đến ngày · Áp dụng · Hủy · Chưa có lượt xem nào · Cao nhất / Trung bình / Tổng · Làm nhanh · Cần giúp? · Gọi tổng đài · Nhắn Zalo · Gửi email · Hiện lại danh sách Bắt đầu · Duyệt bài · Chờ duyệt · Giao cho tôi · Đã hẹn giờ · Đọc và duyệt · Bài của tôi · Bị trả lại — cần sửa · Lý do: · Xem tất cả bài của tôi · Nội dung gần đây · Nội dung mới đăng · Xem tất cả nội dung · Hoạt động gần đây · Xem nhật ký hoạt động · Hoàn tác.

## Kết quả 12 tiêu chí dễ dùng
1. Hiểu trong 5 giây — đạt: h1 + 1 dòng mô tả, 1 nút chính; "Việc cần làm" đứng đầu.
2. ≤ 3 bước — đạt: mỗi việc 1 nút đi thẳng màn làm.
3. Không thuật ngữ — đạt ("Tên miền chưa trỏ về website", không DNS).
4. Icon kèm chữ — đạt (mọi nút có chữ).
5. Ô nhập có ví dụ, lỗi nói cách sửa — đạt (hộp Chọn ngày).
6. Không mất việc — đạt: màn chỉ đọc; "Ẩn danh sách" có Hoàn tác 8 giây.
7. Trạng thái màu + hình + chữ — đạt (chip có icon + chữ; lỗi có icon + câu).
8. Không cắt chữ — đạt: nút 1 dòng (`nowrap` + "…" + title), nhãn thẻ số được xuống dòng; 0 tràn đo bằng harness.
9. Điện thoại & máy tính bảng — đạt ở 360/390/768 (thẻ số 2 cột, việc cần làm lên đầu, nút rộng hết dưới 520px khung).
10. Hai tầng — đạt: mặc định gọn; tab Duyệt bài + phím mũi tên cho người thạo.
11. Chữ lớn — đạt: `--fsp` 2px, 0 tràn 360–1440.
12. Bảng thuật ngữ — đạt (Hủy, Thử lại, Hoàn tác, Sửa, Xem).

## Kết quả Chất lượng hoàn thiện (A–E)
- A — đạt: thang 4/8px, 1 nút chính mỗi vùng, khung xương giữ chỗ (không giật), không animation liên tục.
- B — đạt: chữ ≥ 13px (chú thích 12px chỉ ở nhãn trục), giãn dòng 1,5; mọi việc có nút (không kéo-thả / rê chuột); số trên cột chỉ trong title khi dày → có dòng tóm tắt hiện sẵn.
- C — đạt theo code: 1 h1 + h2 từng thẻ, hộp Chọn ngày có tên, giữ focus, Esc trả focus; toast `role="status"`; [CHƯA KIỂM ĐƯỢC] đo Tab bằng bàn phím thật.
- D — [CHƯA KIỂM ĐƯỢC] tương phản chế độ tối bằng `_audit/harness.html` (chỉ dùng token, không nền trắng viết thẳng) → Claude Code.
- E — đã đo: `H.layouts(['Tổng quan.dc.html'],[360,390,768,1024,1440])` × thường + Chữ lớn (bs-ui `fontSize:'large'`, trả lại giá trị cũ sau khi đo): `scrollWidth` = bề rộng − 15px thanh cuộn, 0 phần tử tràn ở cả 10 lượt. Trong Shell `H.mk('s',390,844,0,0,'../Admin Shell.dc.html#/site/dashboard')`: `documentElement.scrollWidth = clientWidth = 390`, Tổng quan mount, đúng 1 h1.

## Kiểm hiển thị
| Bề rộng | Chrome (thiết kế) | Safari iOS / Firefox / Samsung |
|---|---|---|
| 360 · 390 | đạt (đo) | [CHƯA KIỂM ĐƯỢC] — Claude Code bằng zip |
| 768 · 1024 · 1440 | đạt (đo) | [CHƯA KIỂM ĐƯỢC] |
| Xoay ngang 844×390 · 200% | [CHƯA KIỂM ĐƯỢC] | [CHƯA KIỂM ĐƯỢC] |
Khung xem 390 · 768 nằm cuối trang khi mở đứng riêng (khung chứa ≥ 1240px; tắt khi nhúng).

Verifier: xem kết quả gọi ready_for_verification của lượt này.

## Việc cho backend
- **Bộ đếm theo website** (bảng `site_counters` hoặc cột trên `sites`): trang · bài theo loại · đã đăng · sản phẩm · khách liên hệ 7 ngày + 7 ngày trước · liên hệ chưa xem — tăng / giảm trong CÙNG giao dịch với thao tác (tạo, đổi trạng thái, Thùng rác, khôi phục), không đếm sống bảng lớn (CLAUDE.md §3.1 của repo).
- **Dung lượng thư viện đã dùng**: cộng `size` khi tải lên / trừ khi xóa vĩnh viễn (tệp trong Thùng rác vẫn tính); so với `media.storage_mb` của gói; ≥ 80% → "Sắp đầy" + thông báo `system.storage_near_limit`.
- **Lượt xem theo ngày** (90 ngày + gộp theo tháng): bảng tổng hợp theo website / ngày.
- **Trạng thái "Bắt đầu"**: 6 việc tự kiểm từ dữ liệu (thông tin chung đủ, có logo, ngôn ngữ đã chọn, trang chủ đã sửa khỏi mẫu, trình đơn đã lưu, tên miền riêng đã trỏ); "Ẩn danh sách" lưu `users.preferences.dashboard_start_hidden`.
- **Bài giao cho người duyệt** (tab "Giao cho tôi") + **lý do trả lại** đọc từ sự kiện quy trình của bài.
- Quyền `activity.view` + nhật ký website (D0-4a); kênh hỗ trợ từ Cài đặt nền tảng (D4-3).
- Cảnh báo tên miền "sửa trước <ngày>": kiểm định kỳ, ngày = hạn chứng chỉ / hạn giữ tên miền phụ.

Lượt kế tiếp: **D3-1**.
