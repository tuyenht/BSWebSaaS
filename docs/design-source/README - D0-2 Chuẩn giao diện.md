# D0-2 · Chuẩn giao diện SaaS Starter — phần 1: Component

## Tổng quan
Một trang chuẩn gom mọi component dùng chung mà trước đây mỗi màn tự chế (công tắc `.dsw`, popover ⓘ, thanh ngôn ngữ,
đường bước duyệt, chip hạn ngạch…). Từ D0-3 trở đi, màn mới **chỉ lấy component ở đây**. Mỗi component có: biến thể,
trạng thái (thường / rê chuột / focus / khóa / lỗi), sáng/tối, cỡ cảm ứng 44px, chế độ "Chữ lớn" (+2px), luật không cắt chữ.

## File
| File | Việc |
|---|---|
| `Chuẩn giao diện SaaS Starter.dc.html` | Trang chuẩn (MỚI) |
| `content-ui.js` (BSUI) | Bổ sung: `brandPalette` (chép BrandPalette.php), `readable/readableOnAll/ratio/mix` (chép ReadableColor.php), `primaryText` / `primaryOnInk` (ThemeResolver.php:85/89), `inkOn`, `vnd/parseVnd/groupVnd`, `fmtDate` (múi giờ site), `slugify` (cắt 200 ký tự rồi bỏ "-" hai đầu). D0-2s: hai hàm thao tác DOM `trapFocus(e)` (trang gọi lại trong onLayerKey) và `watchBottomBar(el)` (đo `--sbar-h` + `--kb`, trả về hàm gỡ — cho màn khác; trang giữ `watchBar` riêng). Bảng `tok` đổi hết sang `var(--token, dự phòng)` để chế độ tối tự lật; bỏ chữ `#adb5bd/#ced4da`, nền `#405189` cứng |
| 15 file `.dc.html` | Remix Icon chốt **4.9.1** (L1-21a-3c); `Trình đơn`: `ri-handshake-line` → `ri-shake-hands-line` (L1-I01); `Page Editor - Options`: `ri-spell-check` → `ri-check-double-line` ×3 (L1-I02) |
| `SaaS Starter - Bản đồ màn hình.md` | Thêm dòng trang chuẩn |

## Mức hoàn thiện
Hoàn thiện cho phần Component. Bảng thuật ngữ, định dạng (kể cả số rút gọn), giọng văn, tiêu chí, người dùng mẫu: xong ở D0-3 phần 1. Mẫu trang (gồm bảng điều khiển): D0-3 phần 2.
Chưa đăng ký vào registry màn của Admin Shell vì registry chưa có (D0-4b) — đây là trang chuẩn, không phải màn trong menu.

## Màn & trạng thái
Trang có 12 mục (mục lục đầu trang). Mục **Phản hồi › Trạng thái màn** trình diễn đủ: trống (có "Bắt đầu từ mẫu") ·
không có kết quả · lỗi ("Thử lại") · không có quyền · mất mạng · thành công; **Khung đang tải** cho danh sách/thẻ/form;
**Huy hiệu khóa theo gói** cho khóa theo gói.

## Tương tác (làm thật trong prototype)
- **Một kiểu focus cho lớp bật lên (D0-2s2):** mở ra thì focus vào mục đang chọn (không có thì mục đầu) — panel ngôn ngữ, menu; popover lọc focus vào điều khiển đầu tiên; lịch focus vào ngày đang chọn. Trong danh sách: ↑↓ di chuyển, Home / End về đầu / cuối. Esc đóng và trả focus về nút mở (giống `.m5` của Danh sách co giãn). Ô tìm kiếm của panel ngôn ngữ (từ 6 ngôn ngữ) nằm NGOÀI vùng `role="menu"` — menu không được chứa ô nhập; Tab / Shift+Tab đi giữa ô tìm và danh sách.
- Công tắc, ô tích (+ lưng chừng), nhóm lựa chọn, nút chọn đoạn (phím mũi tên, dưới 380px tự đổi sang ô chọn).
- Ô tìm kiếm (phím "/", khớp N / M), thanh lọc (tích có số, Bỏ lọc / Xong, chip bỏ từng điều kiện).
- Ngày & giờ: gõ dd/mm/yyyy + HH:mm, lịch đi bằng phím, lối tắt "Sáng mai 08:00"…, báo lỗi cách sửa.
- Nhập thẻ (Enter/phẩy, Backspace, trùng, tối đa 10), vùng thả tệp (kéo thả + Chọn tệp, sai loại/quá cỡ, tiến độ).
- Màu thương hiệu: chọn màu tự do → dải 10 bậc + chữ đen/trắng + tỷ lệ; `--primary-text` / `--primary-on-ink` tính trực tiếp.
- Toast: Hoàn tác 8 giây (vạch đếm), lỗi giữ tới khi đóng + Thử lại; ở trên thanh Lưu dính (đo chiều cao thật bằng ResizeObserver,
  bám bàn phím ảo qua `visualViewport`).
- Hộp xác nhận / Hộp rời trang / Panel trượt / Trợ giúp: giữ focus, Esc, bấm nền mờ, trả focus về nút đã mở.
- Thanh hàng loạt đếm đúng số làm được theo nhóm quyền thật ("Xem như" Biên tập / Người viết, ngoài component); Chuyển vào Thùng rác không hỏi, dòng biến mất, Hoàn tác 8 giây đưa đúng dòng về chỗ cũ; cây kéo-thả + Lên/Xuống/Đưa vào trong/Đưa ra ngoài + Alt+mũi tên, tối đa 3 cấp; nút khóa bấm vẫn nói lý do ở dòng `role="status"`.
- Ô đường dẫn (tự đổi không dấu khi gõ, rời ô thì `BSUI.slugify` cắt 200 ký tự + bỏ "-" hai đầu; báo tự chuyển hướng khi bài đã đăng), chuyên mục có sao (đường dẫn phẳng + bản theo chuyên mục), số tiền VND (trống = "Liên hệ báo giá", 0 = "0 ₫"), địa chỉ 2 cấp.
- Ngày & giờ theo múi giờ website (`SITE_TZ`, tên tiếng Việt + độ lệch từ Intl); người xem khác múi giờ thì thêm dòng "Bạn đang ở GMT+N…" (Tweak `viewerTz`).
- Hộp thoại trượt đáy chạy thật (mục Co giãn); dưới 768px panel lọc mở dạng trượt đáy, từ 768px neo phải khi nút ở nửa phải.
- Bảng trạng thái: Nút (5 biến thể × thường/rê chuột/focus/khóa/đang xử lý), Ô nhập (thường/focus/lỗi/khóa/chỉ đọc), Focus bàn phím cho Ô tích và Nhóm lựa chọn; Tabs dựng bằng markup trang (←→ Home/End, focus đi theo tab).
- Không gì chỉ hiện khi rê chuột: ô quyền khóa, 4 nút sắp xếp, nút Dịch khóa, "+5" ở Nhóm avatar đều bấm là thấy; Esc đóng popover/lịch/panel/bộ lọc và trả focus về nút đã mở.
- Chip Chưa lưu / Đã sửa (sửa ô → thanh Lưu dính hiện, đếm đúng, chặn rời trang).
- Thanh ngôn ngữ ở 3 khung 390/768/1240: pill · +N · ô chọn + panel (tìm từ 6 ngôn ngữ), nút Dịch theo ngữ cảnh, tính từ AI thật (BSAI.translateCost), العربية tự `dir="rtl"`.
- Chú thích hướng dẫn 3 bước (Bỏ qua/Xong → không tự hiện lại; "Xem lại" trong Trợ giúp).

Tweaks: `theme` (sáng/tối) · `bigText` · `viewerTz` (múi giờ người xem, trống = theo máy) · `notes` = "Hiện ghi chú thiết kế" (mặc định bật) · `aiGranted` · `upsell` = "Lời mời nâng gói".

## Ánh xạ dữ liệu (repo `tuyenht/BSWebSaaS@main`)
| Thứ | Nguồn |
|---|---|
| Dải 10 bậc `--brand-50…900` | `app/Modules/Theme/Theming/BrandPalette.php` (LIGHTNESS, CHROMA, closestStep, fromOklchInGamut) → `BSUI.brandPalette` |
| Chữ đọc được trên nền (WCAG, trộn sRGB 20 bước, ngưỡng đen/trắng ≈0,18) | `app/Modules/Theme/Theming/ReadableColor.php` (`onAll`, `ratio`, `mix`) → `BSUI.readableOnAll` |
| `--primary-text`, `--primary-on-ink` | `app/Modules/Theme/Theming/ThemeResolver.php:85`, `:89` |
| Vai trò, quyền (Ô quyền) | `content-workflow.js` ← `PermissionRegistry.php` |
| Bước của Đường bước | `content-workflow.js` `WF['Quy trình biên tập']` ← `WorkflowTemplateRegistry.php` |
| Nhãn/icon trạng thái nội dung | `content-workflow.js` `UI` ← migration 2012 `entries.status` |
| Hạn ngạch từ AI / lượt ảnh | `ai-quota.js` (`plan()`, `enough()`, `enoughImages()`, `translateCost()`) |
| Điện thoại / Zalo / email hỗ trợ, tên bộ khung | `platform-config.js` (`BSPLAT.support()`, `kit`) |
| Danh sách 18 Blade | `app/Modules/Admin/resources/views/components/` |
| Tự tạo chuyển hướng 301 khi đổi đường dẫn bài đã đăng | `database/migrations/2001_01_01_000000_create_redirects_table.php` + `app/Modules/Cms/Routing/RouteSync.php` |
| Địa chỉ 2 cấp | bảng dùng chung `provinces` + `wards`, cột `site_profiles.province_id/ward_id` — `database/migrations/2021_01_01_000000_add_nap_fields_and_admin_units.php`; lệnh nạp `admin-units:import` |
| Đường dẫn bài (mặc định phẳng; kiểu theo chuyên mục tính từ chuyên mục chính) | `app/Modules/Cms/Routing/PathBuilder.php` |
| Quyền thao tác hàng loạt | `app/Modules/Cms/Policies/EntryPolicy.php` (`publish` chỉ xét `entries.publish`; `delete` phân biệt `.own`/`.any`) + `app/Modules/Cms/Actions/PublishEntry.php` (có `entries.submit` mà không có `entries.publish` → Đăng = gửi duyệt) |
| Mốc chặn gõ của đường dẫn | `content-seo.js` `slug.hard = 200` — khớp `BSUI.slugify` (cắt 200) và repo `max:200`; thước vẫn đỏ từ 91 (D0-2s2) |
| Mốc chặn gõ của mô tả ngắn | `content-seo.js` `excerpt.hard = 255` — cột repo `entry_translations.excerpt` dài 255 (`EntryContentWriter.php:68-73` từ chối > 255) (D0-2s2) |
| Giá bán | `app/Modules/Admin/Livewire/Catalog/Editor.php`: `nullable\|integer\|min:0` (số nguyên VND) |
| Múi giờ website | `app/Modules/Tenancy/Context/SiteTime.php` (cài đặt nhóm `general`, khóa `timezone`, mặc định Asia/Ho_Chi_Minh) |

## Quyền & gói (ai thấy gì)
- Ô quyền: Chủ website luôn có mọi quyền (khóa, có lý do); quyền ngoài gói (Đổi tên miền) khóa + "Gói hiện tại chưa có…".
- Thanh hàng loạt (theo EntryPolicy thật, xét bằng `BSCW.can(<nhãn nhóm quyền>, …)`): mỗi nút chỉ đếm dòng thật sự đổi trạng thái (D0-2s2, thay luật "Biên tập: hai nút đếm đủ" của D0-2s mục 3). Nhóm Biên tập — "Đăng (N)", N = số bài chưa đăng (bài chờ duyệt có đếm); K > 0 thì "Đăng: K bài đã đăng sẽ được bỏ qua."; N = 0 thì nút khóa + "Các bài đã chọn đều đã đăng.". Nhóm Người viết (có `entries.delete.own` + `entries.submit`, không có `entries.publish`) — "Gửi duyệt (N)", N = số bản nháp CỦA MÌNH; K > 0 thì "Gửi duyệt: K bài sẽ được bỏ qua — nhóm quyền này chỉ gửi duyệt được bản nháp của mình."; N = 0 thì nút khóa (`aria-disabled`, vẫn nhận focus) + "Không có bản nháp nào của bạn trong các bài đã chọn."; "Chuyển vào Thùng rác (N)" chỉ đếm bài của mình + "Chuyển vào Thùng rác: K bài của người khác sẽ được bỏ qua — …". Mỗi câu "bỏ qua" mở đầu bằng tên nút. Toast dùng N. Mỗi dòng có chip trạng thái `.sc[data-s]`, chữ từ `BSCW.UI[st].label`. Nút "Xem như" chỉ để xem mẫu, không phải prop của component.
- AI: `aiGranted` bật → nút Dịch + chip hạn ngạch bình thường. Tắt + `upsell` tắt → ẩn hẳn. Tắt + `upsell` bật → nút khóa "Dịch bằng AI".
- Khóa theo gói: `upsell` tắt → "Chưa bật cho website này — liên hệ hỗ trợ"; bật → lợi ích + "Xem gói nâng cấp". Không ghi tên nhà cung cấp.
- Hết hạn ngạch (Thanh mức dùng, Chip hạn ngạch, lý do nút Dịch): `upsell` bật → "Mua thêm <loại>" + "Nâng gói" (CLAUDE.md §7a-bis); tắt → không chào mua gì, chỉ "Cần thêm? Liên hệ hỗ trợ" (liên kết mở panel Trợ giúp, kênh từ `BSPLAT.support()`); lý do nút Dịch: "…còn X. Dịch từng bản hoặc liên hệ hỗ trợ."

## Bảng tra cho build: component → Blade
Tên nhóm component: **`bs-admin`** (owner chốt 04/10) — thẻ viết `<x-bs-admin::tên>`, tệp ở `app/Modules/Admin/resources/views/components/<tên>.blade.php`. Repo đổi hẳn thẻ `<x-admin::…>` sang `<x-bs-admin::…>` trong một lần (owner chốt 04/10 tối) — không còn thẻ `<x-admin::…>`; namespace view `admin::` (đường dẫn view) là chuyện khác, giữ nguyên. Storefront về sau dùng `bs-store::`, phần dùng chung `bs::`.

Trạng thái: **Có sẵn** · **Có sẵn · cần sửa** · **DS chưa Blade** · **Blade chưa DS** · **MỚI**.

| Component | Blade đích | Trạng thái | Props chính | Token |
|---|---|---|---|---|
| Button | `<x-bs-admin::button>` | cần sửa | color · variant · size · href · disabled · **loading** (bản build: icon quay + `aria-busy`; trang chuẩn vẽ tĩnh) · **icon** | admin-primary · accent · -solid/-text |
| Badge | `<x-bs-admin::badge>` | cần sửa | color · soft · pill · **icon** | -text · -solid |
| Alert (Dải thông báo) | `<x-bs-admin::alert>` | cần sửa | color · icon · dismissible · **title** · **action** — Alert của DS tô chữ bằng màu gốc (cảnh báo 1,65:1) và không theo Chữ lớn — trang vẽ bằng markup riêng tới khi DS sửa; Blade ở repo đã đúng (-text, nền 10% — alert.blade.php:13-16). **Ngoại lệ tạm với P1** (không dùng component DS): gỡ markup riêng, quay lại DS Alert khi DS sửa xong (TODO Lộ trình D0 · DS) | -text · nền 10% |
| Avatar | `<x-bs-admin::avatar>` | có sẵn | name · src · size | accent |
| Card | `<x-bs-admin::card>` | có sẵn | title · slot header / footer · **actions** · **padding** | surface · border · shadow-card |
| Icon | `<x-bs-admin::icon>` | cần sửa | name (Remix 4.9.1 — thiết kế dùng tên đủ hậu tố ri-…-line / -fill; cách đặt tên ở repo chốt khi build) — cần sửa: icon.blade.php chỉ có Heroicons + 60 khóa ri-* không hậu tố, tên lạ thành hình tài liệu; 50/74 tên thiết kế dùng chưa có | currentColor |
| Input | `<x-bs-admin::input>` | cần sửa | label · **hint** · **error** · **required** · inputmode | border-input · placeholder |
| Select | `<x-bs-admin::select>` | DS chưa Blade | label · options · hint · error | border-input |
| Textarea | `<x-bs-admin::textarea>` | DS chưa Blade | label · rows · counter · hint · error | border-input |
| Table | `<x-bs-admin::table>` | cần sửa | columns · **sticky-first** · **stack-below="768"** | border · surface-muted |
| Pagination | `<x-bs-admin::pagination>` | cần sửa | per-page 10/20/50 **[MỚI]** · mode pages\|cursor **[MỚI]** (danh sách lớn dùng cursor) — repo chỉ có paginator · current · last | accent |
| Dropdown + Item | `<x-bs-admin::dropdown>` | cần sửa | align · **role="menu" + phím mũi tên** | surface · shadow-pop |
| Modal | `<x-bs-admin::modal>` | cần sửa | title · size · **sheet-below="768"** · **initial-focus** · **giữ focus** | surface |
| Tabs | `<x-bs-admin::tabs>` | cần sửa | items · active · count · **Home/End** · **focus đi theo tab** — Blade `app/Modules/Admin/resources/views/components/tabs.blade.php` thiếu Home/End và focus chưa đi theo tab (màu chữ ở repo đã đúng) | accent · body-muted |
| Tooltip | `<x-bs-admin::tooltip>` | cần sửa | text · position (tên prop ở repo) · **bấm được trên cảm ứng** | heading |
| PageHeader | `<x-bs-admin::page-header>` | cần sửa | title · subtitle · actions · **help** | heading · body-muted |
| Breadcrumb | `<x-bs-admin::breadcrumb>` | DS chưa Blade | items (≥ 3 cấp) — repo đã có một phần: slot breadcrumbs trong page-header | body-muted |
| brand-logo | `<x-bs-admin::brand-logo>` | Blade chưa DS | context sidebar / topbar | — |
| rich-editor | `<x-bs-admin::rich-editor>` | Blade chưa DS | target · profile · value · rows | — |
| settings-shell | `<x-bs-admin::settings-shell>` | Blade chưa DS | groups · active | accent |
| theme-option | `<x-bs-admin::theme-option>` | Blade chưa DS | axis · value · name · label · store | — |
| Công tắc | `<x-bs-admin::switch>` | MỚI | label · description · checked · disabled · locked-reason | admin-primary · border-input |
| Ô tích | `<x-bs-admin::checkbox>` | MỚI | label · checked · indeterminate · disabled · error | admin-primary |
| Nhóm lựa chọn | `<x-bs-admin::radio-group>` | MỚI | legend · options · error | accent |
| Nút chọn đoạn | `<x-bs-admin::segmented>` | MỚI | options ≤ 4 · value · fallback-select-below | admin-primary |
| Ô tìm kiếm | `<x-bs-admin::search>` | MỚI | label · placeholder · shortcut | accent |
| Thanh lọc | `<x-bs-admin::filter-bar>` | MỚI | filters · matched · total | accent |
| Chọn ngày & giờ | `<x-bs-admin::date-time>` | MỚI | value · timezone · min · shortcuts | admin-primary |
| Nhập thẻ | `<x-bs-admin::tag-input>` | MỚI | value[] · max | accent |
| Vùng thả tệp | `<x-bs-admin::dropzone>` | MỚI | accept · max-size · multiple | border-input |
| Màu thương hiệu | `<x-bs-admin::brand-color>` | MỚI | value · surface · ink | --brand-50…900 · --primary-text · --primary-on-ink |
| Chọn trong bảng màu | `<x-bs-admin::token-color>` | MỚI | value · tokens | -solid |
| Thông báo nổi | `<x-bs-admin::toast>` | MỚI | tone · message · undo · retry — repo đã có lớp JS (sweet-toast.js: chỉ chữ, 2,4 giây, không Hoàn tác; sweet-confirm.js) — bản build THAY lớp này, không thêm hệ thứ hai | surface · -text |
| Hộp xác nhận | `<x-bs-admin::confirm>` | MỚI | title · message · confirm-label · danger — repo đã có lớp JS (sweet-toast.js: chỉ chữ, 2,4 giây, không Hoàn tác; sweet-confirm.js) — bản build THAY lớp này, không thêm hệ thứ hai | -solid |
| Khung đang tải | `<x-bs-admin::skeleton>` | MỚI | variant · rows | surface-hover |
| Trạng thái màn | `<x-bs-admin::empty-state>` | MỚI | variant · action · template-action | body-muted |
| Danh sách co giãn | `<x-bs-admin::data-list>` | MỚI | type page/post/product · rows[title, sub, status, date, author, langs, attrs, **can{update,delete,publish}**] · columns[key, priority 1/2/3] · site-langs (ẩn khi 1) · row-actions Sửa · Xem · ⋯ | container 600/1024 · accent · border-input |
| Hàng công tắc | `<x-bs-admin::toggle-row>` | MỚI | label · description · checked | border |
| Thanh Lưu dính đáy | `<x-bs-admin::save-bar>` | MỚI | dirty-count · on-save · on-discard | surface · safe-area |
| Panel trượt phải | `<x-bs-admin::drawer>` | MỚI | title · width · footer | surface |
| Thanh hàng loạt | `<x-bs-admin::bulk-bar>` | MỚI | selected · actions[eligible, reason] | accent |
| Popover ⓘ | `<x-bs-admin::info-popover>` | MỚI | text · align | surface · shadow-pop |
| Khóa–giá trị | `<x-bs-admin::kv-list>` | MỚI | items[label, value, copy] | body-muted |
| Thẻ số liệu | `<x-bs-admin::stat-card>` | MỚI | label · value · delta · compare-label | -text |
| Thanh mức dùng | `<x-bs-admin::usage-bar>` | MỚI | used · limit · unit | -text |
| Đường bước | `<x-bs-admin::stepper>` | MỚI | steps · current | admin-primary |
| Dòng thời gian | `<x-bs-admin::timeline>` | MỚI | events · load-more | border |
| Vòng tiến độ | `<x-bs-admin::progress-ring>` | MỚI | done · total · items | admin-primary |
| Cây kéo-thả | `<x-bs-admin::tree>` | MỚI | items · max-depth · on-move | accent |
| Nút sắp xếp | `<x-bs-admin::reorder>` | MỚI | can-up/down/in/out · reasons | — |
| Ô đường dẫn | `<x-bs-admin::slug-input>` | MỚI | value · prefix · published | border-input |
| Chọn chuyên mục | `<x-bs-admin::category-picker>` | MỚI | options · value[] · primary | --color-brand-gold |
| Ô số tiền | `<x-bs-admin::money-input>` | MỚI | value (VND nguyên, để trống được) · **required** (mặc định tắt) · max | — |
| Chọn địa chỉ | `<x-bs-admin::address-picker>` | MỚI | province · ward · street | — |
| Chip thay đổi | `<x-bs-admin::change-chip>` | MỚI | state · on-undo | warning-text |
| Hộp rời trang | `<x-bs-admin::leave-guard>` | MỚI | dirty · label · on-save · on-discard | -solid |
| Chấm trạng thái | `<x-bs-admin::status-dot>` | MỚI | state · label | 5 màu §3 |
| Chip hạn ngạch | `<x-bs-admin::quota-chip>` | MỚI | ledger · left · enough-text | -text |
| Thanh ngôn ngữ | `<x-bs-admin::lang-bar>` | MỚI | site-langs · pub-langs · def-lang · current · quota | accent |
| Ô quyền | `<x-bs-admin::permission-cell>` | MỚI | checked · locked · reason | admin-primary |
| Khóa theo gói | `<x-bs-admin::plan-lock>` | MỚI | feature · benefit · upsell | body-muted |
| Nhóm avatar | `<x-bs-admin::avatar-group>` | MỚI | users · max | surface |
| Panel Trợ giúp | `<x-bs-admin::help-panel>` | MỚI | screen · faqs · support | surface |
| Chú thích hướng dẫn | `<x-bs-admin::coach-mark>` | MỚI | steps ≤ 3 · key | --color-brand-gold |
| Ô có thước độ dài | `<x-bs-admin::length-field>` | MỚI · Làm ngay | field (mốc ở BSSEO.limits) · label · multiline · lang · shared · ai-action · 6 mức màu + icon + chữ | -text · admin-* |
| Nút AI cạnh nhãn | `<x-bs-admin::ai-assist>` | MỚI · Làm ngay | label · field · source · words · quota · on-apply · on-undo | accent |
| Nút Sao chép | `<x-bs-admin::copy>` | MỚI · Làm ngay | value (chữ cố định) **hoặc** target (id ô nhập / ô nhiều dòng — chép giá trị đang gõ) · label · icon-only · ô trống thì mờ · chép qua `BSUI.copy(text)` (vùng chọn ẩn trước, rồi Clipboard API; cả hai chặn → bôi đen + chỉ cách chép tay) | success-text |
| Dòng việc AI | `<x-bs-admin::ai-job>` | MỚI · Làm ngay | kind · units · cost · state (6) · progress · on-cancel/retry/undo | info · -text |
| Trạng thái lưu | `<x-bs-admin::save-status>` | MỚI · Làm ngay | state (6) · count · at · on-retry | -text |
| Xem trước Google / chia sẻ | `<x-bs-admin::search-preview>` | MỚI · Làm tiếp | title · description · url · image · device | màu Google |
| Ô ảnh + mô tả ảnh | `<x-bs-admin::image-field>` | MỚI · Làm tiếp | value · alt · max-box · sources · image-quota | border-input |

Mẫu chạy thật + bảng chấm 3 vòng (30 màn, 70 bộ trọng số): `Chuẩn giao diện - Component bổ sung.dc.html` (phần 2 trang chuẩn — bảng tra và mục lục trang chuẩn trỏ tới). Đã áp ngay 04/10: `content-seo.js` BANDS sang token + trả `icon`/`band` (chữ đếm 13px; 4 màn dùng BSSEO.meter tự đúng màu sáng/tối); Page Editor "Mô tả ngắn" bỏ /160 gắn cứng → BSSEO.meter('excerpt') có thước; "Đã chép" → "Đã sao chép" ở Trình đơn, Page Editor, Block Library, Đăng nhập nhanh; 6 màn tự gọi lệnh chép (Floating Contact, Trình đơn, Quản lý Bài viết, Email, Đăng nhập nhanh, Page Editor) chuyển sang `BSUI.copy` (nạp thêm content-ui.js) ("chép trình bày" ở Page Builder là cặp chép/dán định dạng — giữ). Prop bắt buộc `lang` · `shared` cho Input/Textarea/length-field thay chip tự vẽ ở 12 màn.

## Danh sách co giãn — đã chốt 4a (04/10), bổ sung 5a
Nguồn đề xuất và mẫu chạy thật: ~~`Danh sách co giãn - Đã chốt.dc.html`~~ → từ 07/10 (D1-3 phần 3) bản tương tác nằm ở `Chuẩn giao diện SaaS Starter.dc.html#co-gian-tuong-tac`, tệp cũ đã xóa. (#4a bố cục, #5a thao tác tại dòng + đầu danh sách; các lượt 1–3 đã bỏ vì bị thay thế). Khớp với `Quản lý Trang - Options` / `Quản lý Bài viết - Options`.
- **Từ 1024px — bảng:** Tên (+ đường dẫn mono) · Trạng thái (chip chấm + dòng ngày có icon) · Ngôn ngữ (chip mã) · Người tạo (avatar + tên) · 4 nút icon có tooltip (Xem · Sửa · Nhân bản · Chuyển vào Thùng rác).
- **600–1023px — thẻ 3 cột:** trái tên + đường dẫn/SKU (Bài viết bỏ đường dẫn) + hàng khối thuộc tính; giữa trạng thái + ngày + người tạo xếp dọc; phải Sửa · Xem · ⋯. Cột trạng thái rộng bằng chip dài nhất (subgrid) — chip thẳng cột, không khoảng thừa.
- **Dưới 600px — thẻ dọc:** tên → trạng thái + ngày → khối thuộc tính (xuống dòng nguyên khối, không nối bằng "·") → Sửa · Xem · ⋯.
- **Nút:** Sửa = viền màu nhấn + nền 8%, chữ đậm; Xem = viền xám ≥3:1; cùng rộng tối thiểu 76px, cao 36 (chuột) / 44 (cảm ứng), cách 8px; ⋯ chỉ icon, sát phải. "Xem" tự đổi: đã đăng → mở website (`ri-external-link-line`), chưa đăng → xem trước (`ri-eye-line`).
- **Tiêu đề:** màu mực; rê chuột hoặc focus → màu nhấn + gạch chân 1px cách 3px (không đè dấu) + nền dòng nhạt; bấm tên = Sửa.
- **Thuộc tính theo loại:** Trang — ngôn ngữ, người tạo. Bài viết — ảnh nhỏ, chuyên mục chính, ngôn ngữ, tác giả. Sản phẩm — ảnh, SKU, giá (KM + gạch gốc; trống = "Liên hệ báo giá"), tồn kho (hết = đỏ, sắp hết = vàng, có icon), danh mục, ngôn ngữ. Mô tả ngắn không đưa vào danh sách.
- **Màu trạng thái:** theo `BSCW.UI` (nguồn duy nhất) — Đã đăng xanh lá · Chờ duyệt vàng · Đã lên lịch xanh dương (secondary) · Bản nháp xám · Riêng tư xanh dương. Màn Quản lý Trang đang tô "Đã lên lịch" vàng `#c78729` (trùng màu Chờ duyệt, chữ ~2,8:1) và ghi "Đã xuất bản" — cần theo BSCW.UI (đã ghi TODO). Nền nhạt pha từ token `--color-admin-*` **với `--color-surface`** (không pha với trong suốt — để nền dòng khi rê chuột không làm tụt tương phản), chữ dùng `--color-admin-*-text`. Đã lên lịch dùng tông 8% (12% chỉ đạt 4,44:1). Đo [ĐO BẰNG SCRIPT]: Sáng — Đã đăng 4,87 · Chờ duyệt 4,68 · Đã lên lịch 4,65 · Bản nháp 8,3; Tối — Đã lên lịch 5,48 trên thẻ `#16203a`.
- **Cỡ chữ:** tiêu đề và thuộc tính 14px (cần đọc để thao tác, P11); ngày 13px; đường dẫn/SKU, chữ avatar, mã chip 12px (chú thích).
- **Ngôn ngữ:** chip mã đọc từ `siteLangs`; có bản = chấm xanh, chưa có = viền đứt ≥3:1; website 1 ngôn ngữ → ẩn hẳn (CLAUDE.md §2).
- **Phân quyền:** dòng sửa được: Sửa · Xem · ⋯; dòng không sửa được: khối "🔒 Chỉ xem" + Xem làm việc chính; việc phụ không làm được nằm trong ⋯, mờ + lý do. Quyền xét bằng `BSCW.can(<nhãn nhóm quyền>, quyền)` — đọc từ `can{update,delete,publish}` của từng dòng khi có API. Không ẩn bài của người khác; đề xuất chip lọc "Của tôi" (chưa vẽ).
- **Kiểm cuối 04/10 [ĐO BẰNG SCRIPT]:** 111 phần tử chữ ở #5a, sáng và tối: 0 cặp dưới 4,5:1, 0 chữ dưới 12px, 0 nút dưới 32px (chuột), không tràn ở 390/768; trang chuẩn 390/768/1240 không tràn. [CHƯA KIỂM ĐƯỢC] Safari iOS / Firefox và cảm ứng thật (44px) — môi trường chỉ có Chromium, chuột.
- **Đổi trạng thái — cập nhật 5a:** chip trạng thái là **nút** có ⌄ ở mọi bề rộng (vùng bấm 32 / 44px). Dưới 600px mở trượt từ đáy, từ 600px mở menu cạnh chip; mục không đủ quyền mờ + lý do hiện sẵn (BSCW.tier1 / transitions). "Hẹn giờ đăng" mở hộp Đặt lịch (Chọn ngày & giờ, giờ website). Dòng chỉ xem: chip không ⌄, không mở.
- **Bản ngôn ngữ tại dòng:** cả nhóm chip mã là một nút → menu "Sửa bản <tên>" / "Thêm bản <tên>" (AI dịch, chỉ điền ô trống). Màn Quản lý Trang hiện chỉ mở Sửa/Thêm khi **rê chuột** lên chip mã — trái P14.f, cần sửa ngược theo cách này (đã ghi TODO).
- **⋯:** Sửa nhanh tiêu đề & đường dẫn (Panel trượt phải; bảng từ 1024px giữ sửa ngay trong ô) · Nhân bản · — · Chuyển vào Thùng rác (đỏ, tách cuối, làm ngay + Hoàn tác). Mục không làm được: mờ + lý do.
- **Đầu danh sách (tab · tìm · lọc):** từ 1024px như màn gốc. 600–1023px: 5 tab (Tất cả · Đã đăng · Chờ duyệt · Bản nháp · Đã lên lịch) + "Khác ⌄"; dưới 600px 3 tab (Tất cả · Đã đăng · Bản nháp) + "Khác ⌄" (còn lại + Thùng rác); đang ở tab trong "Khác" thì nút hiện đúng tên + số tab đó. Ô tìm co giãn (dưới 600px trọn hàng, chữ 16px). Các nút lọc theo tiêu chí gộp thành **một nút "Lọc (N)"** → panel nhóm tích có số đếm, chân "Bỏ lọc" / "Xong · Khớp N / M" (dưới 600px trượt đáy). Hàng "Đang lọc" chip bỏ từng điều kiện + Bỏ lọc + Khớp N / M. "Sắp xếp" là nút menu (thẻ không có tiêu đề cột); "Cột" ẩn. "Chọn" bật chế độ chọn nhiều (ô tích 20px + thanh Đã chọn N + "Chọn tất cả N" theo số dòng đang khớp). Thanh hàng loạt theo đúng luật D0-2s mục 3: Người viết → "Gửi duyệt (N)", Thùng rác bỏ qua bài của người khác và báo số. Không khớp → "Không có … nào khớp" + "Bỏ lọc và tìm kiếm". Mẫu chạy thật: `Danh sách co giãn - Đã chốt.dc.html` #5a.

## Token
Chỉ dùng token AdminKit (+ lớp chuyển tiếp `admin-base.css`): `--color-surface/-muted/-hover`, `--color-border/-strong/-input`,
`--color-heading`, `--color-body-text`, `--color-body-muted`, `--color-placeholder`, `--color-accent` (chữ/viền/focus),
`--color-admin-primary` (nền dưới chữ trắng), `--color-admin-*-text` / `-solid`, `--color-on-warning`, `--focus-ring-*`,
`--color-brand-gold` (sao bản nguồn, sao mục chính, chip vàng, khung chú thích hướng dẫn — dự phòng `#e0b028`), `--color-brand-gold-text`. Màu gốc ngữ nghĩa chỉ cho chấm trạng thái và nền 10%. Hex còn lại có chủ đích: khung xem trước
storefront (nền trắng `#fff`, nền tối = ink của bộ màu storefront `#0f172a`, CLAUDE.md §8) và màu thương hiệu do khách chọn.
Focus 1b (chốt 05/10): luật chung ở `admin-base.css`, màu vòng `--focus-ring-color`, khe/viền trong `--focus-inner`. Nút đặc = viền trắng 2px trong + quầng 30% · nút viền = viền 2px màu vòng + quầng 18% · ghost/tab = nền 8% + viền trong 2px · liên kết = nền 10% + viền ngoài 2px + gạch chân 2px · checkbox/radio/công tắc = vòng tách quanh phần hình (`[data-focus-shape]`) · ô nhập/`[contenteditable]` = viền 2px + quầng · khung bao chỉ sáng khi ô bên trong focus · ô lỗi giữ đỏ · không nhận ra loại = outline 2px lệch −1px + viền trong + quầng. Luôn giữ outline (trong suốt nếu vẽ bằng box-shadow).

## Icon (Remix 4.9.1)
ri-sun-line · ri-moon-line · ri-question-line · ri-table-line · ri-lock-2-line · ri-lock-2-fill · ri-error-warning-line/fill ·
ri-search-line · ri-close-line · ri-calendar-line · ri-calendar-schedule-line · ri-arrow-left-s-line · ri-arrow-right-s-line ·
ri-arrow-down-s-line · ri-filter-3-line · ri-upload-cloud-2-line · ri-folder-open-line · ri-checkbox-circle-fill/line ·
ri-loader-4-line · ri-magic-line · ri-map-pin-2-fill · ri-server-line · ri-user-star-line · ri-check-line · ri-wifi-off-line ·
ri-delete-bin-line · ri-delete-bin-2-line · ri-translate-2 · ri-information-line · ri-alert-line · ri-file-add-line ·
ri-add-line · ri-layout-masonry-line · ri-search-eye-line · ri-cloud-off-line · ri-refresh-line · ri-external-link-line ·
ri-device-line · ri-pencil-line · ri-more-2-fill · ri-save-3-line · ri-time-line · ri-archive-line · ri-layout-right-2-line ·
ri-global-line · ri-file-copy-line · ri-arrow-up-line · ri-arrow-down-line · ri-subtract-line · ri-forbid-line ·
ri-send-plane-line · ri-chat-check-line · ri-edit-line · ri-checkbox-blank-circle-line · ri-draggable · ri-indent-increase ·
ri-indent-decrease · ri-route-line · ri-arrow-go-back-line · ri-star-fill · ri-star-line · ri-sparkling-2-line · ri-image-2-line ·
ri-settings-3-line · ri-checkbox-fill · ri-checkbox-blank-line · ri-guide-line · ri-phone-line · ri-chat-3-line · ri-mail-line ·
ri-arrow-right-line · ri-inbox-unarchive-line · ri-draft-line · ri-layout-bottom-2-line.

## Chuỗi cần dịch
Toàn bộ chữ trên trang là chuỗi mẫu của component; chuỗi hệ thống cần vào `lang/*.json` khi build:
"Đang bật" · "Đã tắt" · "Chọn tất cả" · "Bỏ lọc" · "Xong" · "Đang lọc:" · "Khớp :n / :m" · "Lịch" · "Tháng trước" · "Tháng sau" ·
"Chọn tệp" · "Kéo tệp vào đây hoặc" · "Đang tải lên :p%" · "Thử lại" · "Bỏ" · "Hoàn tác" · "Đóng thông báo" · "Hủy" ·
"Đã chọn :n" · "Bỏ chọn" · "Lên" · "Xuống" · "Đưa vào trong" · "Đưa ra ngoài" · "Mục chính" · "Đặt làm chính" ·
"Chưa lưu" · "Đã sửa" · "Thay đổi chưa được lưu" · "Ở lại" · "Bỏ thay đổi" · "Lưu & tiếp tục" · "Lưu — hiệu lực ngay" ·
"Còn :n từ AI" · "Còn :n lượt tạo ảnh" · "Mua thêm từ AI" · "Nâng gói" (hai chuỗi này chỉ hiện khi "Lời mời nâng gói" BẬT) · "Cần thêm? Liên hệ hỗ trợ" (khi TẮT) · "Quản lý ngôn ngữ" · "Dịch sang :n ngôn ngữ" ·
"Dịch bản :lang" · "Chung" · "Chưa bật cho website này — liên hệ hỗ trợ" · "Gói hiện tại chưa có :feature" ·
"Xem gói nâng cấp" · "Bỏ qua" · "Tiếp" · "Xem lại hướng dẫn" · "Liên hệ hỗ trợ" · "Tải thêm" · "Gửi duyệt (:n)" · "Xem như" ·
"Theo giờ của website: :tz — đổi ở Cài đặt › Thông tin chung" · "Bạn đang ở :offset — giờ trên đây là giờ của website." ·
"Để trống nếu chưa muốn hiện giá." · "Liên hệ báo giá" · "Chưa :action được: :reason.".

## Kết quả 12 tiêu chí dễ dùng
| # | Kết quả | Ghi chú |
|---|---|---|
| 1 | Đạt | Tiêu đề + 1 dòng mô tả + 1 nút chính "Xem bảng tra Blade" |
| 2 | Đạt | Tra một component: mục lục → mục → thẻ (2 bước) |
| 3 | Đạt | Không thuật ngữ trần trong chữ hiển thị cho người dùng; tên token/Blade chỉ ở nhãn mã dành cho build |
| 4 | Đạt | Icon đứng một mình chỉ: đóng, tìm, ⓘ, ?, ⋯, tháng trước/sau — đều có aria-label + tooltip |
| 5 | Đạt | Placeholder là ví dụ; lỗi nói cách sửa (ngày, giờ, mã màu, giá, tệp) |
| 6 | Đạt | Thùng rác + Hoàn tác 8 giây; xác nhận chỉ cho xóa vĩnh viễn/tắt ngôn ngữ; chặn rời trang khi còn Chưa lưu |
| 7 | Đạt | Chấm/chip/bước/thanh mức dùng đều có hình + chữ |
| 8 | Đạt (DOM) | Nút/chip/tab `white-space:nowrap`; nhãn có thể dài dùng `.sx-t.el` ("…" + title). Đo `scrollWidth = clientWidth` ở nút đầu trang. Ảnh chụp bằng công cụ html-to-image hiện "…" giả do thay font khi chụp — trình duyệt thật không cắt |
| 9 | Đạt có điều kiện | Mọi việc làm được ở 390 (khung xem); hộp thoại trượt đáy, panel ngôn ngữ ≥ 44px dưới 520px. Chưa thử trên máy thật |
| 10 | Đạt | Phím "/", Alt+mũi tên ở cây, thao tác hàng loạt; nâng cao không chen vào luồng chính |
| 11 | Đạt có điều kiện | Mọi cỡ chữ là `calc(Npx + var(--fsp))`; "Chữ lớn" đặt `--fsp: 2px`. Đã xem ở 909px; chưa đo tràn ở 360 |
| 12 | Đạt (D0-3, 05/10) | Bảng thuật ngữ ở `#thuat-ngu` của trang chuẩn; đã đổi "Đặt lịch" → "Hẹn giờ đăng", "Đã lên lịch" → "Đã hẹn giờ", "Riêng tư" → "Không công khai", "Vai trò" → "Nhóm quyền", "Dịch bằng AI" → "Dịch". Xem `README - D0-3 Chuẩn giao diện.md` |

## Kiểm hiển thị
Bằng chứng theo AUDIT §17. Công cụ chụp của môi trường mất > 7 giây mỗi ảnh với trang này (≈3.300 phần tử), nên mới chụp được vài ảnh.

| Bề rộng | Chrome (preview) | Safari iOS / iPad | Firefox | Samsung Internet |
|---|---|---|---|---|
| 909 (preview) sáng | Đạt [ĐÃ RENDER VÀ THẤY]: đầu trang, Điều khiển, Phản hồi, khung 390 của mục Co giãn | chưa kiểm — không có thiết bị | chưa kiểm | chưa kiểm |
| 390 / 768 / 1240 (khung xem trong trang) | Đạt [ĐÃ RENDER VÀ THẤY] ở khung 390 (thẻ gọn, nút Sửa rộng hết + ⋯) | chưa kiểm | chưa kiểm | chưa kiểm |
| 360 · 1024 · 1440 · xoay ngang 844×390 · 200% | chưa kiểm — chờ verifier / harness `_audit/harness.html` | chưa kiểm | chưa kiểm | chưa kiểm |
| Tối · Chữ lớn | [SUY RA TỪ CODE]: mọi màu qua token, mọi cỡ chữ qua `--fsp` | chưa kiểm | chưa kiểm | chưa kiểm |

**Thanh ngôn ngữ — ô đổi ngôn ngữ (D0-2s mục 1)** [ĐO BẰNG SCRIPT] `getComputedStyle(...).display`, Chrome preview 924px, 04/10:

| Khung | Khung chứa `.lbw` | `.lb-sel` | `.lb-more` | pill đầu | Kết quả |
|---|---|---|---|---|---|
| 390 | 364px | flex | none | none | Đạt — còn ô chọn + panel |
| 768 | 742px (prompt dự kiến 734; khung 768 áp đệm 12px của mốc ≤767) | flex | none | none | Đạt — còn ô chọn + panel |
| 1240 | 1206px | none | none | flex | Đạt — dải pill |

Trang không cuộn ngang ở 924px: `scrollWidth` 909 ≤ `innerWidth` 924 [ĐO BẰNG SCRIPT] — **phép đo này SAI cách** (so với `innerWidth`, AUDIT "Kiểm D0-2" C1); từ D0-2s2 đo `document.documentElement.scrollWidth` so với `clientWidth`.

**D0-2s2 (05/10):**

| Kiểm | Kết quả |
|---|---|
| 360 / 390 × sáng + tối × Chữ lớn: scrollWidth = clientWidth | Đạt: scrollWidth = clientWidth ở 360 / 390 / 768 / 1024 / 1240 / 1440 × sáng + tối × chữ thường + Chữ lớn × con trỏ chuột (giả lập, không có hover) + cảm ứng — trang 96/96, trang bổ sung 96/96, Danh sách co giãn 48/48 (trang này không có Chữ lớn); 127 khối .sx-note / .sx-code đều xuống dòng [ĐO BẰNG SCRIPT — Claude Code 05/10] |
| Thanh ngôn ngữ ≥ 44px ở 768 / 1024 cảm ứng · nút hàng ≥ 1024 cảm ứng · ô trang bổ sung 44px | Đạt: thanh ngôn ngữ 44px ở 768 / 1024 / 1240 / 1440 cảm ứng (43,99 ở khung xem thu nhỏ); nút hàng ≥ 1024 cảm ứng hiện .dl-acts 44px; ô trang bổ sung 44px [ĐO BẰNG SCRIPT — Claude Code 05/10] |
| Câu lý do ô khóa · 4 Dải thông báo · chữ tắt avatar ≥ 4,5:1 sáng + tối | Đạt (sáng / tối): câu lý do 6,09 / 6,54 · Dải thông báo 4,95–4,99 / 5,28–7,52 · chữ tắt avatar 6,19 / 5,63; Dải thông báo 14px → 16px khi Chữ lớn [ĐO BẰNG SCRIPT — Claude Code 05/10] |
| Chọn cả 5 bài: Người viết "Gửi duyệt (2)" + "Gửi duyệt: 3 bài…"; Biên tập "Đăng (4)" + "Đăng: 1 bài đã đăng…" | Đạt: Người viết thấy nút Gửi duyệt (2) và câu bỏ qua 3 bài; Biên tập thấy nút Đăng (4) và câu bỏ qua 1 bài đã đăng; N = 0 thì nút khóa [ĐÃ RENDER VÀ THẤY — Claude Code 05/10] |
| `BSSEO.hardCap('slug')` = 200 · `hardCap('excerpt')` = 255 | Đạt: hardCap slug 200 · excerpt 255; gõ 260 ký tự dừng ở 255 (trang bổ sung, Page Editor, Quản lý Bài viết) [ĐO BẰNG SCRIPT — Claude Code 05/10] |
| Safari iOS / Firefox / máy thật | [CHƯA KIỂM ĐƯỢC — không có thiết bị; Chromium không giả lập được chuột có hover ở ≥ 1024] |

360 và 768 cho panel lọc dạng trượt đáy: [SUY RA TỪ CODE] — dưới 768px (bề rộng màn) mở `.ov.sheet` cố định trong khung nhìn, không nằm trong luồng trang.

Verifier 05/10 (D0-2s5): [SUY RA TỪ CODE] `admin-base.css` — luật nút viền không áp lên ghost/nút đặc (`.sx-btn:not(.gho,.pri,.dan)`, `.b:not(.gh,.pri)`; `.b.dan` vẫn nút viền) → `.sx-btn.gho`/`.b.gh` một đường 2px; `[role=switch]` mang `.b/.sx-btn/.btn` không vào luật vòng tách → "Chữ lớn" một chỉ báo; `text-decoration-thickness: 2px !important` ở luật liên kết; nút đặc bỏ `border-color: transparent` khi `[aria-current=date]` → ngày hôm nay + đã chọn giữ viền; khối tối có `--color-admin-primary: var(--brand-solid, #2050a0)`; Admin Shell tối đặt `--focus-inner: transparent` cho thanh bên; trang chuẩn thêm `@media (min-width:768px)` cho `.ov.sheet .dlg` (thắng container query vì `.ov` đệm 16px). [CHƯA KIỂM ĐƯỢC] `.lnk` giống hệt mẫu tĩnh khi Tab, vạch dưới của tab đang chọn, hộp thoại ở 767/768/780/799/800, vạch vàng thanh bên tối 2px — chưa đo lại trong lượt này; Claude Code kiểm bằng zip.

Tương thích: container query + `:has()` cần Safari iOS 16+; `:has()` chỉ dùng cho tô đậm lựa chọn và đẩy toast lên — thiếu thì
nút radio vẫn hiện trạng thái, toast vẫn hiện (có thể nằm sát thanh Lưu). `zoom` cho khung xem chỉ dùng trên trang chuẩn.

## Owner đã chốt (04/10)
- **Chính tả:** kiểu dấu cũ cho vần KHÔNG có phụ âm cuối oa · oe · uy: Xóa · Hủy · Khóa · tùy (không Xoá · Huỷ · Khoá · tuỳ); vần có phụ âm cuối viết như thường (Hoàn tác, Thoát); chip lọc "Bỏ lọc"; chữ cái đầu viết hoa theo vị trí (đầu nhãn/câu viết hoa, giữa câu viết thường). Trang đang đúng — không đổi chữ.
- **"Lời mời nâng gói" TẮT:** không chào mua gì — ẩn cả "Mua thêm…" lẫn "Nâng gói" ở Thanh mức dùng và Chip hạn ngạch, thay bằng "Cần thêm? Liên hệ hỗ trợ" (liên kết mở panel Trợ giúp, kênh từ `BSPLAT.support()`); lý do nút Dịch bỏ vế "Mua thêm từ AI hoặc". BẬT: giữ "Mua thêm <loại>" + "Nâng gói" (CLAUDE.md §7a-bis). Prompt khác CLAUDE.md ở điểm này — ghi ở LESSONS.md.

## Việc cho backend
| Tính năng | Dữ liệu cần lưu | Luật |
|---|---|---|
| Chữ trên từng bậc màu thương hiệu | `--brand-N-ink` (đen/trắng) cạnh `BrandPalette::from()` | Theo `ReadableColor` (đen khi độ sáng ≥ ≈0,18), tính lại khi đổi màu |
| Lưu tỉnh/phường ở Thông tin chung | `site_profiles.province_id/ward_id` | Lưu province_id/ward_id ở Thông tin chung (kiểm phường thuộc tỉnh) + danh sách phường theo tỉnh cho ô chọn — bảng đã có, chưa có đường ghi. |
| Chuyển vào Thùng rác hàng loạt | — | (a) mọi thao tác hàng loạt (Thùng rác · Khôi phục · Xóa vĩnh viễn · Đưa về nháp · Nhân bản) bỏ qua dòng không đủ quyền rồi báo số — hiện cả 5 hàm dừng ở dòng đầu tiên không đủ quyền và giữ thay đổi của các dòng trước (`app/Modules/Admin/Livewire/Entries/Index.php` `bulkApply`); (b) toast có Hoàn tác khôi phục đúng các bài vừa chuyển; (c) bỏ hộp xác nhận trước khi chuyển vào Thùng rác |
| Gửi duyệt / Đăng hàng loạt | — | (1) Gửi duyệt = có quyền gửi duyệt VÀ được sửa đúng bài đó, và chỉ từ Bản nháp (owner chốt theo khuyến nghị 04/10 tối; repo ĐÃ CÓ từ 04/10, PR #168: `EntryPolicy::submit` + quy trình duyệt + thao tác hàng loạt) — với nhóm Người viết / Cộng tác viên nghĩa là chỉ bản nháp của mình. (2) Đăng / Gửi duyệt hàng loạt: dòng không làm được thì bỏ qua và báo số; chỉ đếm dòng thật sự đổi trạng thái — Gửi duyệt chỉ đếm bản nháp; Đăng không đếm bài đã đăng (bài đang chờ duyệt được Đăng thì có đếm); không đụng bài trong Thùng rác. |
| Tên nhóm quyền `author` | `lang/vi.json` khóa `rbac.role.author` | Thiết kế (BSCW) là "Người viết", repo là "Tác giả" — D0-3 đã chốt "Người viết"; repo đổi giá trị (xem README D0-3 › Việc cho backend) |
| Giá trống ngoài website | — | Trang sản phẩm hiện "Liên hệ báo giá" khi giá trống (website chưa có chỗ hiện giá). Chế độ "giới thiệu" của cả website (ẩn mọi giá) là công tắc khác, không phải giá trống |
| Múi giờ website | `site_settings` nhóm `general` khóa `timezone`, giá trị dạng `{"tz":"<tên IANA>"}` (vd. `{"tz":"Asia/Ho_Chi_Minh"}`) — SiteTime (`app/Modules/Tenancy/Context/SiteTime.php`) chỉ nhận dạng này; ghi chuỗi trần sẽ bị bỏ qua mà không báo lỗi | (a) ô chọn múi giờ ở Cài đặt › Thông tin chung ghi khóa này — SiteTime đã đọc và trang quản trị đã dùng, chưa có chỗ ghi; (b) nhãn tên múi giờ cho dòng gợi ý; (c) múi giờ riêng của người dùng trong `users.preferences` (quyết định Q6) |
| Danh sách theo quyền từng dòng | API danh sách trả `can.update · can.delete · can.publish` cho mỗi bản ghi | Tính từ EntryPolicy; giao diện không tự đoán theo tên nhóm quyền (Data-list 4a) |
| Tên nhóm component `bs-admin` | `Blade::anonymousComponentPath(app_path('Modules/Admin/resources/views/components'), 'bs-admin')` (hoặc `componentNamespace` nếu có class) | Đổi hẳn `<x-admin::…>` → `<x-bs-admin::…>` trong một PR, không giữ thẻ `<x-admin::…>` (namespace view `admin::` giữ nguyên); test chặn thẻ `<x-admin::` mới. Chỉ ảnh hưởng code — khách không thấy, Thương hiệu trắng không đổi |
| Câu hỏi thường gặp theo màn | `help_faqs` (screen_key, locale, q, a, order) | Nền tảng soạn; 3–5 câu/màn |
| Đã xem hướng dẫn | `users.preferences.coach[screen]` | Đã tắt thì không tự hiện lại |
| Thùng rác cho loại chưa có | — | Chỉ bài/trang/sản phẩm/ảnh & tệp đã có; loại khác dùng toast Hoàn tác nhưng cần thêm |
| Cần owner chốt (COUNCIL) | quyền mới thêm vào registry + danh sách loại trừ cho Quản trị | Đề xuất mặc định TẮT cho mọi vai trò trừ Chủ website (an toàn nhất). **Mâu thuẫn với repo hiện tại:** `PermissionRegistry.php:191` cho Quản trị mọi quyền website, nên quyền mới tự BẬT cho Quản trị — cần danh sách loại trừ nếu chốt mặc định TẮT (AUDIT Kiểm D0-2 TB 23) |
| Giới hạn độ dài ở máy chủ | — | Từ Kiểm D0-2 (TB 15–17): `saveTitle` (sửa nhanh, `Index.php:257-271`) thêm `max:200` như trình soạn (`EntryContentRules.php:25`); `saveSlug` (`Index.php:278-300`) áp `max:200` của `EntryContentRules.php:26`; alt ảnh (`WithMediaLibraryPicker.php:343-358`) thêm giới hạn — đề xuất `max:188` khớp mốc chặn của thước (`BSSEO.hardCap('alt')`) |
| Giờ hiện theo SiteTime ở mọi trang | — | Từ Kiểm D0-2 (TB 20): `forms/inbox.blade.php:35`, `forms/detail.blade.php:16`, `platform/audit/index.blade.php:25` đang hiện giờ UTC — đổi sang SiteTime |
| Nút theo hàng xét quyền từng dòng | — | Từ Kiểm D0-2 (TB 14): `livewire/entries/index.blade.php` hiện nút theo cờ chung (`Index.php:573-576`) — dùng `can.*` theo dòng (dòng "Danh sách theo quyền từng dòng") |
| Việc nhỏ ở repo | — | Từ Kiểm D0-2 (Thấp 16/18/21): `theme-option.blade.php:13` bỏ `#405189` cứng; `pagination.blade.php:26` `aria-label` qua `__()`; `lang/vi.json:550` `catalog.price_hint` bỏ "chế độ giới thiệu sản phẩm" (giá trống ≠ chế độ giới thiệu) |
