# README — D2-1 · Nhóm quyền & Thành viên (07/10/2026)

## Tổng quan
Hai màn mới cho mặt Website: **Nhóm quyền** (Màn 1 danh sách · Màn 2 tạo/sửa · Màn 3 "Nhóm này làm được gì?") và **Thành viên** (Màn 4, thay màn repo `Users/Index.php`, theo Mẫu 1 `#mau-1`). Luật as-built lấy từ repo `tuyenht/BSWebSaaS@main`; màn chỉ thể hiện, không đặt luật khác.

## File
- `Nhóm quyền.dc.html` — mới. Màn 1 (`view: list`), Màn 2 (`view: edit`, 3 chế độ `new · edit · view`), Màn 3 (bảng trượt phải; prop `explain="<tên>"` để màn khác mount riêng hộp này).
- `Thành viên.dc.html` — mới. Mount Màn 3 bằng `<dc-import name="Nhóm quyền" explain …>`.
- `content-workflow.js` — chỉ THÊM `BSCW.IMPLIES` (chép `PermissionRegistry::IMPLIES`, dòng 71–84).
- `shell-screens.js` — `users`, `roles`: `kind: 'mount'`, `status: 'ready'`, props, help.
- `Admin Shell.dc.html` — chỉ khối gắn: 2 `<sc-if>` (`mRoles`, `mUsers`) + `dc-import … embedded` + cờ `rqNhom`, `rqChe`, `onEmbedDirtyNq/Tv`.
- `SaaS Starter - Bản đồ màn hình.md` dòng 27–30 → ready. `TODO.md`, `LESSONS.md` (3 dòng).

## Mức hoàn thiện
Hi-fi, tương tác đủ luồng chính. Dữ liệu mẫu chép ở 2 tệp (`NQ_SEED`, `TV_CUSTOM`/`TV_SEED`) — bản thật đọc bảng `roles`, `role_user`, `invitations`.

## Màn & trạng thái
| Màn | Trống | Đang tải | Lỗi + Thử lại | Không có quyền | Khóa theo gói | Đăng nhập dưới tên khách hàng | Thành công |
|---|---|---|---|---|---|---|---|
| Nhóm quyền | "Chưa có nhóm tự tạo" + 1 đoạn khi nào cần + Tạo nhóm | khung xám tĩnh | dải đỏ + Thử lại | `roles.manage` (Biên tập trở xuống) | `rbac.custom_roles` tắt: dải + Tạo/Nhân bản khóa, theo Tweak "Lời mời nâng gói"; nhóm đã có vẫn sửa/xóa | dải vàng + mọi nút Tạo/Sửa/Xóa/Nhân bản khóa + câu khóa | toast "Đã lưu…/Đã tạo…/Đã chuyển vào Thùng rác" + Hoàn tác 8 giây |
| Thành viên | "Website mới có mình bạn" + Mời | khung xám | dải đỏ + Thử lại | `users.manage` | module Thành viên tắt (Tweak "Module Thành viên") | dải vàng + Mời / Sửa nhóm / Xóa / hàng loạt khóa | toast; lỗi lưu "Người này không còn là thành viên của website. Hãy tải lại trang." + Tải lại |

Tweaks: Người xem (nhóm quyền đọc `BSCW.ROLES`, "Thiếu 2 việc" mô phỏng người thiếu Trình đơn + Tên miền, đăng nhập dưới tên khách hàng) · Gói (`planKey` đọc `BSAI.PLAN_FEATURES`, "Lời mời nâng gói", Sản phẩm bật/tắt) · Trạng thái · "Hiện ghi chú thiết kế" · Chữ lớn · Khung xem 390/768.

## Tương tác
- **Màn 1**: ô tìm + chip "Tất cả · Mặc định · Tự tạo" luôn hiện; dòng hiện sẵn "Sửa" (nhóm tự tạo dưới cấp mình) hoặc "Xem"; "⋯": Nhóm này làm được gì? · Nhân bản · | Xóa… (cuối, đỏ, chỉ nhóm tự tạo quản lý được). Không được làm → ẩn; bị chặn → khóa + câu lý do ngay dưới mục. "Tạo nhóm" → Nhóm trống / Từ nhóm có sẵn (chỉ nhóm mình gán được: cấp thấp hơn + mọi việc của nhóm mình đều có).
- **Màn 2**: tên (1–60, đếm ký tự, kiểm khi rời ô + khi lưu), vị trí cấp (chỉ vị trí dưới mình, hiện "Giữa Biên tập và Người viết", không số), ma trận theo khu vực: tick ô, "Chọn cả hàng", tìm việc, mỗi ô 1 câu giải thích; ô khóa bấm/chạm mở lý do ngay trong ô; ô "Đã đổi" so với nhóm gốc / bản đã lưu; xem trước "Thêm · Bớt · Giữ nguyên · Bỏ khi lưu · Ảnh hưởng tới N thành viên"; thanh Lưu dính đáy. Nhóm hệ thống / nhóm ngang-cao hơn: chỉ xem + "Nhân bản để tùy chỉnh". Dưới 768px khung: mỗi khu vực gập lại, ô thành công tắc. Rời khi chưa lưu → hộp "Bỏ thay đổi?" (nút "Ở lại" được focus).
- **Quyền kéo theo**: tick việc lớn → "Xem" tự tick; bỏ "Xem" khi còn việc lớn → chặn + câu "Chưa bỏ được "Xem": đang tick Tạo, Đăng…". "Sửa mọi bài" không tự tick "Sửa bài của mình" (câu giải thích "gồm cả bài của mình"; Màn 3 ghi "nằm trong Sửa mọi bài").
- **Màn 3**: danh sách đời thường theo khu vực ("Được viết bài mới", "Không được xóa bài người khác"), mở từ Màn 1 (Xem đủ, ⋯), Màn 2 (đầu trang), Màn 4 (panel Sửa nhóm › Xem chi tiết, ⋯).
- **Màn 4**: tab Tất cả · Đang hoạt động · Chờ nhận lời mời; tìm tên/email; nút "Nhóm quyền" (danh sách tick có số; chân Bỏ lọc · Xong); dòng "Đang lọc: chip × … Bỏ lọc · Khớp N / M"; Dòng/trang 10/20/50 + số trang. Dòng hiện sẵn "Sửa nhóm"; "⋯": Gửi lại lời mời (khi chờ / hết hạn) · Nhóm làm được gì? · | Xóa khỏi website… (cuối, đỏ). Người cùng cấp / cao hơn → không nút, câu "Người này cùng cấp hoặc cao hơn bạn."; chính mình → câu riêng. Thanh "Đã chọn N": "Đổi nhóm (k)" · "Xóa khỏi website (k)" + "Bỏ qua 1 người: … (cùng cấp hoặc cao hơn bạn)". Panel Sửa nhóm: nhiều ô chọn (chỉ nhóm mình gán được), mỗi nhóm 1 dòng "Làm được: …" + Xem chi tiết, xem trước quyền thực "Thêm · Bớt", bắt buộc ≥ 1 nhóm. "Xóa khỏi website" → hộp xác nhận nguy hiểm, Hủy được focus, không vào Thùng rác. "Mời thành viên" → thẻ "Chờ thiết kế · D2-2".
- Bàn phím: Esc đóng menu → hộp → bảng; mở hộp focus vào trong, đóng trả focus về nút đã mở; toast `role="status"`.
- Nhúng: `embedded` ẩn tiêu đề/mô tả, giữ nút chính + thanh Lưu; `onDirty(n)`; nghe `bs-embed-cmd {act:'save'|'reset'}`; chuyển Màn 1 ↔ 2 bằng `bs-goto {screen:'roles', params:{nhom, che}, force:true}`; "N thành viên" → `bs-goto {screen:'users', params:{nhom}}`.

## Ánh xạ dữ liệu
| Màn | Repo |
|---|---|
| 24 quyền, 6 nhóm, cấp | `app/Modules/Rbac/Registry/PermissionRegistry.php` (qua `BSCW.PERMS/ROLES`) |
| Quyền kéo theo | `PermissionRegistry.php:71–84` `IMPLIES` → `BSCW.IMPLIES` |
| Tên 1–60, trùng không phân biệt hoa thường, tên dành riêng, cấp < trần, giữ quyền mình không có, bỏ quyền module tắt | `app/Modules/Rbac/Support/CustomRoleRules.php` (`name()`, `assertLevel()`, `permissions()`) |
| Nhóm gán được (cấp thấp hơn + mình có đủ việc) | `app/Modules/Rbac/Support/RoleHierarchyGuard.php` (`assignableRoles`, `assertCanGrant`) |
| Ngoài gói theo module | `Support/RoleCeiling.php` (`forSite`, `grantableBy`) |
| Tạo / sửa / xóa nhóm, gán / bỏ nhóm | `Rbac/Actions/{Create,Update,Delete}CustomRole.php`, `AssignRole.php`, `RevokeRole.php` |
| Gói tắt sau: vẫn sửa/xóa | `UpdateCustomRole.php:19` |
| Mã lỗi | `Rbac/Exceptions/RoleChangeRejectedException.php` |
| Thành viên | `app/Modules/Admin/Livewire/Users/Index.php`, `routes/web.php:216` (gate `users.manage`) |
| Khóa khi hỗ trợ | `SupportSession::forbid()` |

## Quyền & gói (ai thấy gì)
- Chủ website: quản lý mọi nhóm dưới cấp 90 (kể cả "Trợ lý chủ website" 80); Quản trị: dưới 70 — "Từ nhóm có sẵn" không có Chủ website / Quản trị / Trợ lý chủ website; vị trí cấp bắt đầu "Giữa Quản trị và Biên tập".
- Biên tập, Người viết, Cộng tác viên, Chỉ xem: trạng thái "không có quyền" ở cả 2 màn.
- Gói free/basic: `rbac.custom_roles` tắt → Tạo/Nhân bản khóa; pro/ent mở. Sản phẩm tắt → hàng Sản phẩm khóa "Ngoài gói".

## Token
`--color-heading · --color-body-text · --color-body-muted · --color-surface(-muted/-hover) · --color-border(-strong/-input) · --color-accent · --color-admin-primary · --color-admin-{success,info,warning,danger}(-text) · --color-admin-danger-solid · --color-brand-gold(-text) · --color-placeholder · --color-on-solid (dự phòng #fff)`. Lớp dùng lại: `.sx-btn`, `.sx-chip`, `.sx-search`, `.sx-in`, `.sx-lab`, `.sx-err`, `.sx-hint`, `.sx-note`, `.avi` (admin-components.css). Focus: luật chung admin-base.css (ô tích = vòng tách; khung bao `.sx-search`), không viết focus riêng.

## Icon (Remix 4.9.1)
add-line · arrow-down-s-line · arrow-left-s-line · arrow-right-s-line · lock-2-line/-fill · user-settings-line · shield-user-line · eye-line · pencil-line · more-2-fill · file-copy-line · file-add-line · file-list-3-line · delete-bin-line · search-line · checkbox-fill · checkbox-blank-line · checkbox-circle-fill/-line · close-circle-line · close-line · add-circle-line · indeterminate-circle-line · error-warning-line · information-line · history-line · team-line · save-3-line · git-branch-line · edit-circle-line · user-shared-line · user-add-line · user-unfollow-line · mail-send-line · time-line · refresh-line · home-4-line · tools-line · draft-line.

## Chuỗi cần dịch — 6 mã `RoleChangeRejectedException::reason`
| Mã | Câu đời thường |
|---|---|
| custom_roles_disabled | Gói hiện tại chưa có nhóm tự tạo nên chưa tạo nhóm mới được. Nhóm đã có vẫn sửa, xóa được. |
| name_invalid | Tên nhóm cần từ 1 đến 60 ký tự, ví dụ "Người duyệt bài". |
| name_reserved | "Biên tập" là tên nhóm có sẵn của hệ thống. Hãy đặt tên khác, ví dụ "Biên tập tin tức". |
| name_taken | Website đã có nhóm "Nhóm A". Hãy đặt tên khác — chữ hoa hay chữ thường đều tính là trùng. |
| level_invalid | Vị trí cấp phải thấp hơn cấp của bạn. Hãy chọn một vị trí trong danh sách. |
| in_use | Nhóm "X" còn N thành viên và M lời mời đang chờ. Chuyển họ sang nhóm khác hoặc hủy lời mời rồi xóa lại. |
Khác: câu khóa hỗ trợ "Không làm được việc này khi nhân viên hỗ trợ đang đăng nhập thay thành viên."; "Người này cùng cấp hoặc cao hơn bạn."; "Người này không còn là thành viên của website. Hãy tải lại trang."; 24 câu "Được …/Không được …" (`NQ_AREAS`).

## Màn đang chạy · Bản mới · Giữ bản nào · Vì sao
| Màn đang chạy (repo) | Bản mới | Giữ | Vì sao |
|---|---|---|---|
| Thành viên: 1 ô chọn nhóm mỗi người | Panel nhiều ô chọn + xem trước | Bản mới | Repo đã cho một người nhiều nhóm; 1 ô chọn không thể hiện được |
| Không tìm, không phân trang | Tìm + lọc nhóm + Dòng/trang | Bản mới | Mẫu 1 |
| Nút "Mời thành viên" ở đầu | Giữ đúng vị trí, nút chính | Giữ | — |
| Nhóm quyền: chưa có màn | Mới | — | — |

## Kết quả 12 tiêu chí dễ dùng
1 đạt (tiêu đề + 1 dòng + 1 nút chính mỗi màn) · 2 đạt (Thêm nhóm: menu → Tạo nhóm → chọn → Lưu = 3 bước; đổi nhóm: Sửa nhóm → tick → Lưu) · 3 đạt (không thuật ngữ; "vai trò" → "nhóm quyền") · 4 đạt (icon kèm chữ; ⋯/đóng có aria-label + title) · 5 đạt (lỗi tên nói cách sửa) · 6 đạt (chặn rời trang khi chưa lưu, beforeunload; xóa nhóm → Thùng rác + Hoàn tác — Chờ backend) · 7 đạt (trạng thái thành viên: màu + icon + chữ) · 8 đạt (nút/chip 1 dòng, tên nhóm dài cắt "…" + title) · 9 đạt (thẻ gọn < 768, menu/lọc trượt đáy < 600, vùng bấm 44px khi cảm ứng) · 10 đạt (tìm, chip, chọn nhiều cho người thạo; mặc định gọn) · 11 [CHƯA KIỂM ĐƯỢC] Chữ lớn (--fsp) chưa đo bằng harness · 12 đạt (Thêm/Sửa/Xóa/Lưu/Hủy/Bỏ lọc).

## Kết quả Chất lượng hoàn thiện (A–E)
- A đạt — thang 4/8, cỡ chữ 12/13/14/16/18/20 của DS; chuyển động 150ms, tắt khi reduced-motion; khung tải tĩnh (LESSONS: không animation trên trang nhiều container).
- B đạt — chữ nội dung 14px, chú thích ≥ 12px, giãn dòng 1,5; không việc nào chỉ làm bằng rê chuột/kéo-thả.
- C đạt theo mã — 1 h1/màn (nhúng: h2), hộp có tên (`aria-labelledby`), Esc, focus vào/trả về; toast `aria-live`. Tab thật trong Shell: [CHỜ GỘP].
- D một phần — chỉ token (0 hex ngoài `var()`); đo tương phản chế độ tối: [CHƯA KIỂM ĐƯỢC] (chưa chạy harness tối).
- E — đo tràn ngang bằng iframe tự dựng (07/10, chữ thường, đứng riêng): 360/390/768/1024/1440 × 2 tệp → `scrollWidth = clientWidth` cả 10 lượt. Chữ lớn, tối, Shell 390: [CHƯA KIỂM ĐƯỢC] — công cụ chụp quá 7 giây với Shell; Claude Code đo bằng zip.

## Kiểm hiển thị
| Bề rộng | Chrome (môi trường thiết kế) | Safari iOS / Firefox / Samsung |
|---|---|---|
| 360 · 390 | đạt (0 tràn, thẻ gọn) | [CHƯA KIỂM ĐƯỢC] chỉ có Chromium |
| 768 | đạt (bảng rút gọn) | [CHƯA KIỂM ĐƯỢC] |
| 1024 · 1440 | đạt | [CHƯA KIỂM ĐƯỢC] |
| 844×390 xoay ngang · 200% · Chữ lớn · tối | [CHƯA KIỂM ĐƯỢC] | [CHƯA KIỂM ĐƯỢC] |
| Trong Shell `#/site/roles`, `#/site/users` @390 | [CHỜ GỘP] — Shell quá giờ công cụ | — |

Grep (5): 0 id số trong `bs-goto`/hash · 0 `style-focus-within` · 0 hex ngoài `var(…)` · 0 "Vai trò" · "Tác giả" · "RBAC" · "permission" trên màn.

Verifier: xem lượt verifier của D2-1 (ghi lại nếu quá giờ).

## Việc cho backend
1. **Thùng rác nhóm tự tạo** — bảng `roles` dùng chung mọi website (D6, không RLS) → cách làm: COUNCIL. Cần: cột `deleted_at` hoặc bảng thùng rác riêng có `site_id`, giữ quyền + `forked_from_role_id`, khôi phục kiểm lại tên trùng + cấp. Gắn "Cần owner chốt".
2. **Tìm + phân trang Thành viên ở CSDL** — repo tải cả danh sách (`Users/Index.php`); cần `?q=` (tên, email, không dấu), lọc nhóm, `per_page` 10/20/50.
3. **Gán nhiều nhóm một lần + hàng loạt** — repo 1 ô chọn (`Users/Index.php:102`); cần action đặt tập nhóm cho 1 người (AssignRole/RevokeRole trong 1 giao dịch) và cho N người, bỏ qua người cùng cấp / cấp trên / chính mình, trả về danh sách bị bỏ qua + lý do.
4. **Chữ**: `rbac.role.author` "Tác giả" → "Người viết"; mọi "vai trò" → "nhóm quyền" trong `lang/vi.json`.
5. Nhật ký: ghi `role.created/updated/deleted` kèm diff quyền thêm / bớt; `member.roles_changed`, `member.removed`.
6. Danh sách nhóm cần số thành viên + số lời mời đang chờ theo nhóm (để báo `in_use` trước khi bấm).

## Gộp vào tệp chung
Lượt chạy một mình — đã sửa trực tiếp `TODO.md`, `LESSONS.md`, `SaaS Starter - Bản đồ màn hình.md`, `shell-screens.js`. Xong.
