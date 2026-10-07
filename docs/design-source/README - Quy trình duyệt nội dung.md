# Quy trình duyệt nội dung — handoff (hi-fi)

3 màn, mỗi màn 1 file `.dc.html`, dùng chung registry `content-workflow.js` (`window.BSCW`).
Stack đích: **Livewire 4 + Blade + Tailwind 4 + `<x-bs-admin::*>`**, icon Remix **inline-SVG** (prototype dùng
remixicon CDN; bảng ánh xạ icon ở §4 để thay bằng inline-SVG khi hiện thực hoá).

| Màn | File | Vai trò thấy được | Nhúng |
|---|---|---|---|
| 1. Quy trình duyệt (Cài đặt) | `Quy trình duyệt.dc.html` | chỉ vận hành viên nền tảng | có (`embedded`, `onDirty`, `embedId`) |
| 2. Khối “Trạng thái duyệt” trong trang soạn thảo | `Trạng thái duyệt (khối soạn thảo).dc.html` | mọi vai trò (nút lọc theo quyền) | có (`embedded`) |
| 3. Hộp duyệt (inbox) | `Hộp duyệt.dc.html` | mọi vai trò có `entries.review`/`entries.approve` | không (màn riêng trong menu Nội dung) |

Bố cục màn 3 đã chốt: **danh sách bên trái (không nút) + khung đọc bài bên phải**, cụm nút duyệt nằm một chỗ duy nhất ở chân khung đọc.

Fidelity: **hi-fi** — token, kiểu chữ, bo góc, shadow, khoảng cách lấy đúng AdminKit; nội dung là dữ liệu mẫu.

---

## 1. Dữ liệu ĐỘNG — không hardcode bước nào

Mọi tên bước, tên nút, số bước đọc từ registry, **không** có chuỗi `Draft/Pending/Published` nào trong markup:

```js
BSCW.templates(planKey)      // danh sách mẫu + steps + transitions + locked theo gói
BSCW.steps(templateName)     // [{key,label,index,initial,published,terminal}] theo thứ tự DB
BSCW.allowed(name,step,role) // nút chuyển ở bước hiện tại, ĐÃ lọc theo quyền của vai trò
BSCW.stepOf(name,step)       // bước hiện tại (rơi về bước đầu nếu bản ghi chưa có bước)
BSCW.can(role,perm)          // PermissionRegistry
BSCW.planName(key)           // tên gói lấy từ window.BSAI (ai-quota.js)
```

Đã kiểm với **2 → 6 bước** và tên bước dài bằng 2 mẫu thử trong `BSCW.DEMO_WF`
(`Duyệt nhanh (2 bước)`, `Quy trình tòa soạn (6 bước)` — đánh dấu `demo: true`, **không** có trong repo,
chỉ để stress-test giao diện; khi hiện thực hoá thì bỏ khỏi danh sách chọn).

## 2. Ánh xạ data-model

| Khái niệm trong brief | Nguồn dữ liệu đề xuất | Nơi dùng trong UI |
|---|---|---|
| Thể loại nội dung của site | `sections` (`id`, `site_id`, `name`, `entries_count`) | Màn 1: mỗi dòng bảng |
| Quy trình của thể loại (tối đa 1) | `section_workflows` (`section_id` **unique**, `template_key`, `enabled`) — không có dòng = tier-1 đăng thẳng | Màn 1: cột Quy trình + công tắc |
| 2 mẫu nền tảng | `WorkflowTemplateRegistry` → `BSCW.WF` (`admin_approval`, `editorial`) | Màn 1: danh sách chọn + xem trước |
| Bước của mẫu | `template.states` (`key`, `label`, `initial`, `published`, `terminal`) | Stepper cả 3 màn; `published` = chip “Công khai” |
| Nút chuyển | `template.transitions` (`key`, `label`, `from`, `to`, `perm`, `note`) | Action-bar màn 2, hành động nhanh màn 3 |
| Quyền của nút | `entries.submit` / `entries.review` / `entries.approve` (PermissionRegistry) | Lọc nút: user chỉ thấy nút mình bấm được |
| `note: true` (dấu \*) | `entry_transition_logs.note` **NOT NULL** cho các transition này | Modal ghi chú bắt buộc |
| Giao việc | `entries.assigned_to` → `users.id` | Chip “Đang giao cho …” (màn 2), tab “Giao cho tôi” (màn 3), dropdown “Giao cho” trong modal |
| Lịch sử chuyển | `entry_transition_logs` (`entry_id`, `user_id`, `transition_key`, `from_state`, `to_state`, `note`, `created_at`) | Timeline màn 2; “ghi chú gần nhất” màn 3 |
| Bước hiện tại của bài | `entries.workflow_state` (null = tạo trước khi bật quy trình) | `stepOf()` rơi về bước đầu + dòng giải thích |
| Trạng thái công khai | `entries.status` suy ra bằng `ApplyEntryWorkflowTransition` (`published`/`draft`/`pending`) | Chip “Khách đang thấy bài này” |
| Mẫu theo gói | `plans.features['workflow.editorial']`, gói hiện tại từ `BSAI.plan()` | Mẫu khoá: mờ + badge “Gói Chuyên nghiệp” → modal nâng gói |
| Thời gian chờ | `now - last_transition.created_at` | Màn 3: “chờ N ngày”, **> 3 ngày** đổi sang màu warning |
| Badge sidebar | count của tab “Hàng chờ duyệt” (cùng truy vấn, cache 60s) | Menu Nội dung › Hộp duyệt |

Truy vấn tab của màn 3:
- **Giao cho tôi** = `assigned_to = me` **và** bước hiện tại có ≥ 1 transition mà tôi có quyền.
- **Hàng chờ duyệt** = mọi bài mà bước hiện tại có ≥ 1 transition tôi có quyền (không xét `assigned_to`).
- Phân trang **keyset** theo `(last_transition_at, id)` — nút “Tải thêm”, không đánh số trang.

## 3. Design tokens đã dùng

| Vai trò | Giá trị | Chỗ dùng |
|---|---|---|
| Primary navy | `#405189` | nút Duyệt đăng / Lưu, tab đang chọn, viền focus, bước hiện tại |
| Focus ring | `rgba(64,81,137,.20)` 3px | input, textarea, select |
| Success | `#0ab39c` (chữ trên nền nhạt `#0a6d60` — AA 4.8:1) | bước công khai, chip “Đang bật”, empty state hộp duyệt |
| Warning | `#f7b84b` (chữ trên nền nhạt `#8a5f18` — AA 4.9:1) | chờ quá 3 ngày, “bắt buộc ghi lý do”, cảnh báo tắt quy trình |
| Danger | `#f06548` (chữ `#c0432c`) | nút Trả lại / Gỡ bài (outline), lỗi validate |
| Info | `#299cdb` (chữ `#22688f`) | dòng “Đang chờ duyệt — bạn sẽ được báo…” |
| Canvas / card | `#f3f3f9` / `#fff` | nền trang / thẻ |
| Border | `#eff2f7` (thẻ), `#e9ebec` (control), `#f6f7f9` (kẻ hàng) | — |
| Text | `#343a40` tiêu đề · `#495057` thân · `#878a99` meta · `#adb5bd` phụ · `#5c5f6e` chữ trong chip xám | — |
| Type | Inter 14px/1.5; h1 20px/600; card title 14px/600; meta 11.5–12.5px | — |
| Radius | 4px control · 6px card · pill 20px | — |
| Shadow | `0 1px 2px rgba(56,65,74,.15)` (card) · `0 5px 20px rgba(56,65,74,.28)` (modal) | — |
| Spacing | 4/8/12/16/24 — gutter 24px, card pad 16px | — |

Không có màu, gradient hay elevation mới ngoài bảng trên.

## 4. Ánh xạ icon Remix (thay bằng inline-SVG khi hiện thực hoá)

| Chỗ dùng | Icon |
|---|---|
| Quy trình / sơ đồ bước | `ri-flow-chart` |
| Bước công khai | `ri-global-line` |
| Bước nháp / bước chờ / bước lưu trữ | `ri-draft-line` · `ri-time-line` · `ri-archive-line` |
| Đang bật / Đã tắt | `ri-checkbox-circle-fill` · `ri-pause-circle-line` |
| Chọn / đổi quy trình | `ri-add-line` · `ri-repeat-2-line` |
| Mẫu bị khoá theo gói | `ri-lock-2-line` · `ri-vip-crown-2-line` |
| Nâng gói | `ri-arrow-right-up-line` |
| Cảnh báo confirm | `ri-alert-line` · `ri-error-warning-line` |
| Duyệt đăng | `ri-check-double-line` |
| Gửi duyệt / chuyển bước | `ri-send-plane-line` |
| Trả lại / yêu cầu sửa | `ri-arrow-go-back-line` |
| Gỡ bài | `ri-eye-off-line` |
| Lịch sử duyệt | `ri-history-line` · `ri-arrow-down-line` (xem thêm) |
| Ghi chú | `ri-chat-1-line` · `ri-edit-2-line` |
| Giao cho / người nhận | `ri-user-follow-line` |
| Hộp duyệt / tab | `ri-inbox-line` |
| Chờ quá hạn | `ri-alarm-warning-line` |
| Mở bài viết | `ri-external-link-line` |
| Tải lại | `ri-refresh-line` · Lưu `ri-save-3-line` |
| Thể loại (theo section) | `ri-newspaper-line` · `ri-building-4-line` · `ri-tools-line` · `ri-user-add-line` · `ri-question-answer-line` |
| Không có quyền vào màn 1 | `ri-shield-keyhole-line` · `ri-customer-service-2-line` |
| Bước đã qua / bước hiện tại chờ người soát | `ri-check-line` · `ri-user-search-line` |
| Bước nháp / bước công khai / bước lưu trữ | `ri-draft-line` · `ri-global-line` · `ri-archive-line` |
| Gập/mở thẻ trường | `ri-arrow-down-s-line` (quay −90° khi gập) |
| Ảnh đại diện có / chưa có | `ri-image-2-line` · `ri-image-add-line` |
| Tìm kiếm · không có kết quả | `ri-search-line` · `ri-search-eye-line` |
| Ghi chú của người gửi | `ri-chat-quote-line` |
| Điều hướng bài trước / bài sau | `ri-arrow-up-s-line` · `ri-arrow-down-s-line` |
| Quay lại danh sách (mobile) | `ri-arrow-left-line` |
| Đóng modal | `ri-close-line` |

## 5. Hành vi từng state

### Màn 1 — Quy trình duyệt
- **Ready**: bảng thể loại (grid 4 cột; ≤900px gập 1 cột thành card). Mỗi dòng: tên + số bài · quy trình + link “Xem sơ đồ các bước” · chip Đang bật/Đã tắt + “N bài đang chờ” · Chọn/Đổi quy trình + công tắc.
- **Xem trước mẫu**: modal — **đường tiến độ có mốc** giống màn 2/3 (mốc 26px có icon theo loại bước; bước công khai = mốc xanh `ri-global-line` kèm câu giải thích bên dưới), ≤640px đổi về dải pill. Danh sách nút chuyển **chia hai nhóm**: “Đưa bài đi tiếp” và “Trả lại hoặc gỡ xuống — luôn phải ghi lý do”; mỗi nút một thẻ có tên · `từ → đến` · chip quyền · chip “bắt buộc ghi lý do”. Nút áp dụng mờ khi chưa chọn hoặc chọn đúng mẫu đang dùng.
- **Mẫu khoá theo gói**: opacity .62 + badge gói; bấm mở modal nâng gói (nói rõ gói hiện tại, gói cần, và các thể loại đang chạy không bị ảnh hưởng) → **không dead-end**.
- **Tắt quy trình đang có bài chờ**: confirm giữa màn theo pattern SweetAlert2, **nút huỷ được focus** (`ref` → `focus()`), copy: “N bài đang chờ duyệt sẽ quay về chế độ đăng thường, không mất dữ liệu.”
- **Empty** (`loadState = Chưa bật quy trình nào`): 1 đoạn giải thích quy trình duyệt là gì + CTA “Bật cho thể loại đầu tiên”.
- **Loading**: skeleton 4 dòng. **Error**: thẻ viền danger + “Thử lại”.
- **Không phải vận hành viên nền tảng** (`viewer = Quản trị site`): thẻ giải thích + “Gửi yêu cầu cho nhà cung cấp” + “Xem Hộp duyệt của tôi”.
- **Nhúng Admin Shell** (CLAUDE.md §13): `embedded` ẩn header + thanh Lưu; `onDirty(dirty)` báo lên Shell; nghe `bs-embed-cmd {id, act:'save'|'reset'}`; chặn `beforeunload` khi dirty; Esc đóng modal.

### Màn 2 — Khối “Trạng thái duyệt”
- Thay cho nút Đăng. **Thanh bước = đường tiến độ có mốc** giống màn 3 (dòng `Bước 3/5 — <tên bước> · <tên quy trình>`, mốc 26px có icon, đường nối tô theo tiến độ); ≤560px (container query) đổi sang thanh tiến độ mảnh + **“Bước 3/5 — Đang phản biện · kế tiếp: Đã đăng”**.
- **Bài còn trường trống sẽ công khai** (prop `missingFields` — tên trường từ DB, không hardcode): dải vàng ngay trên cụm nút — “Bài còn thiếu ảnh đại diện, mô tả SEO. Chuyển bước vẫn được, nhưng bổ sung trước thì người duyệt không phải trả lại.” Không chặn nút.
- Action-bar chỉ chứa `BSCW.allowed(...)`: transition tới bước `published` = **primary navy**; transition có `note` = **outline danger**; còn lại outline thường. Tooltip nói trước hệ quả (“Bài sẽ sang bước … và hiện ra ngoài cho khách”).
- Nút có `note` → modal: textarea bắt buộc (rỗng → viền danger + “Bạn cần ghi rõ lý do để người viết biết đường sửa.”) + dropdown “Giao cho” lọc theo quyền của bước kế tiếp. Nút huỷ được focus khi mở.
- **Gỡ bài**: dải cảnh báo “Khách truy cập sẽ không thấy bài này nữa. Nội dung vẫn còn nguyên trong bản nháp.”
- **Không có nút nào**: chỉ stepper + “Đang chờ duyệt — bạn sẽ được báo khi có thay đổi.” (bài đã đăng mà vai trò không đổi được thì đổi câu tương ứng).
- **Bài tạo trước khi bật quy trình** (`preWorkflow`): hiện ở bước đầu + dòng giải thích.
- **Timeline**: avatar chữ cái + tên, hành động, `từ → đến`, ghi chú trong khối xám, thời gian tương đối; “Xem thêm N lần chuyển” (keyset, +3 mỗi lần).
- **Loading**: skeleton. **Error**: “Thử lại” + “Lưu nháp” (không ngõ cụt, không mất chữ).

### Màn 3 — Hộp duyệt
- **Bố cục hai khung** (desktop `352px | 1fr`): trái là danh sách **không có nút nào** (tiêu đề · `● bước hiện tại · thể loại` · người gửi · thời gian chờ · vạch navy cho bài đang xem); phải là khung đọc bài. ≤940px: chỉ hiện danh sách, chạm một bài mở khung đọc toàn màn có nút “← Danh sách”.
- **Thanh bước = đường tiến độ có mốc**: dòng tiêu đề `Bước 3/5 — <tên bước> · <tên quy trình>`; mỗi mốc là vòng 26px có icon theo loại bước (đã qua = ✓ nền navy, hiện tại = viền navy + vòng sáng, bước công khai = `ri-global-line` viền xanh, bước cuối = `ri-archive-line`); đường nối tô navy phần đã đi. ≤600px đổi sang thanh tiến độ mảnh + “Bước kế tiếp: …”.
- **Nội dung bài chia thành thẻ trường gập/mở được** (`aria-expanded`, ghi nhớ trong `localStorage: bs-inbox-fold`): Ảnh đại diện (fit tỷ lệ gốc, không crop) → Thông tin chính theo thể loại (từ dữ liệu) → Mô tả ngắn (kèm số ký tự) → Nội dung chi tiết (N từ · N đoạn) → Khi đăng, khách và Google sẽ thấy (đường dẫn + SEO) → Lịch sử duyệt. Mặc định gập hai thẻ cuối; thẻ nào thiếu dữ liệu thì thanh tiêu đề mang chip vàng (“chưa có”/“chưa nhập”/“cần xem”) kể cả khi đang gập, và dải cảnh báo tổng ở đầu khung liệt kê đủ.
- **Cụm nút chỉ ở chân khung đọc** (sticky): transition tới bước `published` = primary navy (qua confirm), transition có `note` = outline danger (qua modal ghi chú). Kèm ∧ ∨ + “bài n/N” để duyệt liên tục; xử lý xong tự nhảy bài kế tiếp.
- **Duyệt hàng loạt**: ô chọn 44×44 trên mỗi bài; thanh “Đã chọn N bài” + nút đếm **đúng số bài thực sự duyệt được** theo quyền (“2/3 bài đang ở bước bạn duyệt đăng được”), không hợp lệ thì nút mờ.
- **Tìm kiếm + lọc thể loại** (danh sách thể loại sinh từ dữ liệu). Lọc ra 0 kết quả vẫn giữ nguyên thẻ danh sách + hiện “Không có bài nào khớp” + nút **Xoá lọc** (không ngõ cụt).
- **Phím tắt**: ↑↓ chuyển bài; Enter chạy việc chính (luôn qua confirm). Tự bỏ qua khi focus nằm trên nút/liên kết/ô nhập.
- **Empty riêng từng tab** (“Không có bài nào chờ bạn — thảnh thơi nhé ☕”), **loading** skeleton hai khung, **error** “Thử lại”, **khung đọc trống** khi chưa chọn bài. **Tải thêm** +5 bài/lần, không đánh số trang.

## 6. Việc còn lại khi hiện thực hoá
1. ~~Nối màn 1 vào Admin Shell (`settingsGroupsAll()` + `embed:'workflow'` + `mkEmbedDirty('workflow')`).~~ → đã đổi chỗ theo owner 30/09 (D0-5, 06/10): màn nằm ở mặt Nền tảng › Website › Quy trình duyệt (khóa `p-workflow` trong `shell-screens.js`, `embed-id="workflow"`, `mkEmbedDirty('workflow')`), KHÔNG nằm trong Cài đặt website; D4-1 đặt vào tab của trang chi tiết website.
2. Thêm “Hộp duyệt” vào menu Nội dung + badge count trên sidebar.
3. Bỏ `BSCW.DEMO_WF` khỏi danh sách chọn (chỉ giữ để test N bước).
4. Thay remixicon CDN bằng inline-SVG theo bảng §4.
