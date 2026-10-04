# D0-2 · Chuẩn giao diện SaaS Starter — phần 1: Component

## Tổng quan
Một trang chuẩn gom mọi component dùng chung mà trước đây mỗi màn tự chế (công tắc `.dsw`, popover ⓘ, thanh ngôn ngữ,
đường bước duyệt, chip hạn ngạch…). Từ D0-3 trở đi, màn mới **chỉ lấy component ở đây**. Mỗi component có: biến thể,
trạng thái (thường / rê chuột / focus / khóa / lỗi), sáng/tối, cỡ cảm ứng 44px, chế độ "Chữ lớn" (+2px), luật không cắt chữ.

## File
| File | Việc |
|---|---|
| `Chuẩn giao diện SaaS Starter.dc.html` | Trang chuẩn (MỚI) |
| `content-ui.js` (BSUI) | Bổ sung: `brandPalette` (chép BrandPalette.php), `readable/readableOnAll/ratio/mix` (chép ReadableColor.php), `primaryText` / `primaryOnInk` (ThemeResolver.php:85/89), `inkOn`, `vnd/parseVnd/groupVnd`, `fmtDate` (múi giờ site), `slugify`. Bảng `tok` đổi hết sang `var(--token, dự phòng)` để chế độ tối tự lật; bỏ chữ `#adb5bd/#ced4da`, nền `#405189` cứng |
| 15 file `.dc.html` | Remix Icon chốt **4.9.1** (L1-21a-3c); `Trình đơn`: `ri-handshake-line` → `ri-shake-hands-line` (L1-I01); `Page Editor - Options`: `ri-spell-check` → `ri-check-double-line` ×3 (L1-I02) |
| `SaaS Starter - Bản đồ màn hình.md` | Thêm dòng trang chuẩn |

## Mức hoàn thiện
Hoàn thiện cho phần Component. Chưa có: Bảng thuật ngữ, quy ước số rút gọn, mẫu bảng điều khiển (D0-3).
Chưa đăng ký vào registry màn của Admin Shell vì registry chưa có (D0-4b) — đây là trang chuẩn, không phải màn trong menu.

## Màn & trạng thái
Trang có 12 mục (mục lục đầu trang). Mục **Phản hồi › Trạng thái màn** trình diễn đủ: trống (có "Bắt đầu từ mẫu") ·
không có kết quả · lỗi ("Thử lại") · không có quyền · mất mạng · thành công; **Khung đang tải** cho danh sách/thẻ/form;
**Huy hiệu khóa theo gói** cho khóa theo gói.

## Tương tác (làm thật trong prototype)
- Công tắc, ô tích (+ lưng chừng), nhóm lựa chọn, nút chọn đoạn (phím mũi tên, dưới 380px tự đổi sang ô chọn).
- Ô tìm kiếm (phím "/", khớp N / M), thanh lọc (tích có số, Bỏ lọc / Xong, chip bỏ từng điều kiện).
- Ngày & giờ: gõ dd/mm/yyyy + HH:mm, lịch đi bằng phím, lối tắt "Sáng mai 08:00"…, báo lỗi cách sửa.
- Nhập thẻ (Enter/phẩy, Backspace, trùng, tối đa 10), vùng thả tệp (kéo thả + Chọn tệp, sai loại/quá cỡ, tiến độ).
- Màu thương hiệu: chọn màu tự do → dải 10 bậc + chữ đen/trắng + tỷ lệ; `--primary-text` / `--primary-on-ink` tính trực tiếp.
- Toast: Hoàn tác 8 giây (vạch đếm), lỗi giữ tới khi đóng + Thử lại; ở trên thanh Lưu dính (đo chiều cao thật bằng ResizeObserver,
  bám bàn phím ảo qua `visualViewport`).
- Hộp xác nhận / Hộp rời trang / Panel trượt / Trợ giúp: giữ focus, Esc, bấm nền mờ, trả focus về nút đã mở.
- Thanh hàng loạt đếm đúng số làm được; cây kéo-thả + Lên/Xuống/Đưa vào trong/Đưa ra ngoài + Alt+mũi tên, tối đa 3 cấp.
- Ô đường dẫn (tự đổi không dấu, báo tự chuyển hướng khi bài đã đăng), chuyên mục có sao, số tiền VND, địa chỉ 2 cấp.
- Chip Chưa lưu / Đã sửa (sửa ô → thanh Lưu dính hiện, đếm đúng, chặn rời trang).
- Thanh ngôn ngữ ở 3 khung 390/768/1240: pill · +N · ô chọn + panel (tìm từ 6 ngôn ngữ), nút Dịch theo ngữ cảnh, tính từ AI thật (BSAI.translateCost), العربية tự `dir="rtl"`.
- Chú thích hướng dẫn 3 bước (Bỏ qua/Xong → không tự hiện lại; "Xem lại" trong Trợ giúp).

Tweaks: `theme` (sáng/tối) · `bigText` · `notes` = "Hiện ghi chú thiết kế" (mặc định bật) · `aiGranted` · `upsell` = "Lời mời nâng gói".

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

## Quyền & gói (ai thấy gì)
- Ô quyền: Chủ website luôn có mọi quyền (khóa, có lý do); quyền ngoài gói (Đổi tên miền) khóa + "Gói hiện tại chưa có…".
- Thanh hàng loạt: nút Đăng chỉ đếm bài của chính mình khi vai trò chỉ đăng được bài của mình.
- AI: `aiGranted` bật → nút Dịch + chip hạn ngạch bình thường. Tắt + `upsell` tắt → ẩn hẳn. Tắt + `upsell` bật → nút khóa "Dịch bằng AI".
- Khóa theo gói: `upsell` tắt → "Chưa bật cho website này — liên hệ hỗ trợ"; bật → lợi ích + "Xem gói nâng cấp". Không ghi tên nhà cung cấp.

## Bảng tra cho build: component → Blade
Trạng thái: **Có sẵn** · **Có sẵn · cần sửa** · **DS chưa Blade** · **Blade chưa DS** · **MỚI**.

| Component | Blade đích | Trạng thái | Props chính | Token |
|---|---|---|---|---|
| Button | `<x-admin::button>` | cần sửa | color · variant · size · href · disabled · **loading** · **icon** | admin-primary · accent · -solid/-text |
| Badge | `<x-admin::badge>` | có sẵn | color · soft · pill · **icon** | -text · -solid |
| Alert (Dải thông báo) | `<x-admin::alert>` | cần sửa | color · icon · dismissible · **title** · **action** | -text · nền 10% |
| Avatar | `<x-admin::avatar>` | có sẵn | name · src · size | accent |
| Card | `<x-admin::card>` | có sẵn | title · actions | surface · border · shadow-card |
| Icon | `<x-admin::icon>` | có sẵn | name (Remix 4.9.1) | currentColor |
| Input | `<x-admin::input>` | cần sửa | label · **hint** · **error** · **required** · inputmode | border-input · placeholder |
| Select | `<x-admin::select>` | DS chưa Blade | label · options · hint · error | border-input |
| Textarea | `<x-admin::textarea>` | DS chưa Blade | label · rows · counter · hint · error | border-input |
| Table | `<x-admin::table>` | cần sửa | columns · **sticky-first** · **stack-below="768"** | border · surface-muted |
| Pagination | `<x-admin::pagination>` | có sẵn | per-page · mode pages/cursor | accent |
| Dropdown + Item | `<x-admin::dropdown>` | cần sửa | align · **role="menu" + phím mũi tên** | surface · shadow-pop |
| Modal | `<x-admin::modal>` | cần sửa | title · size · **sheet-below="768"** · **initial-focus** · **giữ focus** | surface |
| Tabs | `<x-admin::tabs>` | có sẵn | items · active · count | accent |
| Tooltip | `<x-admin::tooltip>` | có sẵn | text · placement | heading |
| PageHeader | `<x-admin::page-header>` | cần sửa | title · subtitle · actions · **help** | heading · body-muted |
| Breadcrumb | `<x-admin::breadcrumb>` | DS chưa Blade | items (≥ 3 cấp) | body-muted |
| brand-logo | `<x-admin::brand-logo>` | Blade chưa DS | variant | — |
| rich-editor | `<x-admin::rich-editor>` | Blade chưa DS | wire:model · toolbar | — |
| settings-shell | `<x-admin::settings-shell>` | Blade chưa DS | groups · active (E9) | accent |
| theme-option | `<x-admin::theme-option>` | Blade chưa DS | name · value · preview | — |
| Công tắc | `<x-admin::switch>` | MỚI | label · description · checked · disabled · locked-reason | admin-primary · border-input |
| Ô tích | `<x-admin::checkbox>` | MỚI | label · checked · indeterminate · disabled · error | admin-primary |
| Nhóm lựa chọn | `<x-admin::radio-group>` | MỚI | legend · options · error | accent |
| Nút chọn đoạn | `<x-admin::segmented>` | MỚI | options ≤ 4 · value · fallback-select-below | admin-primary |
| Ô tìm kiếm | `<x-admin::search>` | MỚI | label · placeholder · shortcut | accent |
| Thanh lọc | `<x-admin::filter-bar>` | MỚI | filters · matched · total | accent |
| Chọn ngày & giờ | `<x-admin::date-time>` | MỚI | value · timezone · min · shortcuts | admin-primary |
| Nhập thẻ | `<x-admin::tag-input>` | MỚI | value[] · max | accent |
| Vùng thả tệp | `<x-admin::dropzone>` | MỚI | accept · max-size · multiple | border-input |
| Màu thương hiệu | `<x-admin::brand-color>` | MỚI | value · surface · ink | --brand-50…900 · --primary-text · --primary-on-ink |
| Chọn trong bảng màu | `<x-admin::token-color>` | MỚI | value · tokens | -solid |
| Thông báo nổi | `<x-admin::toast>` | MỚI | tone · message · undo · retry | surface · -text |
| Hộp xác nhận | `<x-admin::confirm>` | MỚI | title · message · confirm-label · danger | -solid |
| Khung đang tải | `<x-admin::skeleton>` | MỚI | variant · rows | surface-hover |
| Trạng thái màn | `<x-admin::empty-state>` | MỚI | variant · action · template-action | body-muted |
| Danh sách co giãn | `<x-admin::data-list>` | MỚI | rows · columns[priority] · primary-action · menu | container 768/1024 |
| Hàng công tắc | `<x-admin::toggle-row>` | MỚI | label · description · checked | border |
| Thanh Lưu dính đáy | `<x-admin::save-bar>` | MỚI | dirty-count · on-save · on-discard | surface · safe-area |
| Panel trượt phải | `<x-admin::drawer>` | MỚI | title · width · footer | surface |
| Thanh hàng loạt | `<x-admin::bulk-bar>` | MỚI | selected · actions[eligible, reason] | accent |
| Popover ⓘ | `<x-admin::info-popover>` | MỚI | text · align | surface · shadow-pop |
| Khóa–giá trị | `<x-admin::kv-list>` | MỚI | items[label, value, copy] | body-muted |
| Thẻ số liệu | `<x-admin::stat-card>` | MỚI | label · value · delta · compare-label | -text |
| Thanh mức dùng | `<x-admin::usage-bar>` | MỚI | used · limit · unit | -text |
| Đường bước | `<x-admin::stepper>` | MỚI | steps · current | admin-primary |
| Dòng thời gian | `<x-admin::timeline>` | MỚI | events · load-more | border |
| Vòng tiến độ | `<x-admin::progress-ring>` | MỚI | done · total · items | admin-primary |
| Cây kéo-thả | `<x-admin::tree>` | MỚI | items · max-depth · on-move | accent |
| Nút sắp xếp | `<x-admin::reorder>` | MỚI | can-up/down/in/out · reasons | — |
| Ô đường dẫn | `<x-admin::slug-input>` | MỚI | value · prefix · published | border-input |
| Chọn chuyên mục | `<x-admin::category-picker>` | MỚI | options · value[] · primary | #e0b028 |
| Ô số tiền | `<x-admin::money-input>` | MỚI | value (VND nguyên) · max | — |
| Chọn địa chỉ | `<x-admin::address-picker>` | MỚI | province · ward · street | — |
| Chip thay đổi | `<x-admin::change-chip>` | MỚI | state · on-undo | warning-text |
| Hộp rời trang | `<x-admin::leave-guard>` | MỚI | dirty · label · on-save · on-discard | -solid |
| Chấm trạng thái | `<x-admin::status-dot>` | MỚI | state · label | 5 màu §3 |
| Chip hạn ngạch | `<x-admin::quota-chip>` | MỚI | ledger · left · enough-text | -text |
| Thanh ngôn ngữ | `<x-admin::lang-bar>` | MỚI | site-langs · pub-langs · def-lang · current · quota | accent |
| Ô quyền | `<x-admin::permission-cell>` | MỚI | checked · locked · reason | admin-primary |
| Khóa theo gói | `<x-admin::plan-lock>` | MỚI | feature · benefit · upsell | body-muted |
| Nhóm avatar | `<x-admin::avatar-group>` | MỚI | users · max | surface |
| Panel Trợ giúp | `<x-admin::help-panel>` | MỚI | screen · faqs · support | surface |
| Chú thích hướng dẫn | `<x-admin::coach-mark>` | MỚI | steps ≤ 3 · key | #e0b028 |

## Token
Chỉ dùng token AdminKit (+ lớp chuyển tiếp `admin-base.css`): `--color-surface/-muted/-hover`, `--color-border/-strong/-input`,
`--color-heading`, `--color-body-text`, `--color-body-muted`, `--color-placeholder`, `--color-accent` (chữ/viền/focus),
`--color-admin-primary` (nền dưới chữ trắng), `--color-admin-*-text` / `-solid`, `--color-on-warning`, `--focus-ring-*`,
`--color-brand-gold-text`. Màu gốc ngữ nghĩa chỉ cho chấm trạng thái và nền 10%. Hex còn lại có chủ đích: khung xem trước
storefront (nền trắng `#fff`, nền tối `#0f172a`) và màu thương hiệu do khách chọn.

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
ri-arrow-right-line · ri-inbox-unarchive-line · ri-draft-line.

## Chuỗi cần dịch
Toàn bộ chữ trên trang là chuỗi mẫu của component; chuỗi hệ thống cần vào `lang/*.json` khi build:
"Đang bật" · "Đã tắt" · "Chọn tất cả" · "Bỏ lọc" · "Xong" · "Đang lọc:" · "Khớp :n / :m" · "Lịch" · "Tháng trước" · "Tháng sau" ·
"Chọn tệp" · "Kéo tệp vào đây hoặc" · "Đang tải lên :p%" · "Thử lại" · "Bỏ" · "Hoàn tác" · "Đóng thông báo" · "Hủy" ·
"Đã chọn :n" · "Bỏ chọn" · "Lên" · "Xuống" · "Đưa vào trong" · "Đưa ra ngoài" · "Mục chính" · "Đặt làm chính" ·
"Chưa lưu" · "Đã sửa" · "Thay đổi chưa được lưu" · "Ở lại" · "Bỏ thay đổi" · "Lưu & tiếp tục" · "Lưu — hiệu lực ngay" ·
"Còn :n từ AI" · "Còn :n lượt tạo ảnh" · "Mua thêm từ AI" · "Nâng gói" · "Quản lý ngôn ngữ" · "Dịch sang :n ngôn ngữ" ·
"Dịch bản :lang" · "Chung" · "Chưa bật cho website này — liên hệ hỗ trợ" · "Gói hiện tại chưa có :feature" ·
"Xem gói nâng cấp" · "Bỏ qua" · "Tiếp" · "Xem lại hướng dẫn" · "Liên hệ hỗ trợ" · "Tải thêm".

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
| 12 | Chờ D0-3 | Bảng thuật ngữ chưa có; trang dùng bảng thuật ngữ của design system (Bỏ lọc, Xóa, Hủy) |

## Kiểm hiển thị
Bằng chứng theo AUDIT §17. Công cụ chụp của môi trường mất > 7 giây mỗi ảnh với trang này (≈3.300 phần tử), nên mới chụp được vài ảnh.

| Bề rộng | Chrome (preview) | Safari iOS / iPad | Firefox | Samsung Internet |
|---|---|---|---|---|
| 909 (preview) sáng | Đạt [ĐÃ RENDER VÀ THẤY]: đầu trang, Điều khiển, Phản hồi, khung 390 của mục Co giãn | chưa kiểm — không có thiết bị | chưa kiểm | chưa kiểm |
| 390 / 768 / 1240 (khung xem trong trang) | Đạt [ĐÃ RENDER VÀ THẤY] ở khung 390 (thẻ gọn, nút Sửa rộng hết + ⋯) | chưa kiểm | chưa kiểm | chưa kiểm |
| 360 · 1024 · 1440 · xoay ngang 844×390 · 200% | chưa kiểm — chờ verifier / harness `_audit/harness.html` | chưa kiểm | chưa kiểm | chưa kiểm |
| Tối · Chữ lớn | [SUY RA TỪ CODE]: mọi màu qua token, mọi cỡ chữ qua `--fsp` | chưa kiểm | chưa kiểm | chưa kiểm |

Tương thích: container query + `:has()` cần Safari iOS 16+; `:has()` chỉ dùng cho tô đậm lựa chọn và đẩy toast lên — thiếu thì
nút radio vẫn hiện trạng thái, toast vẫn hiện (có thể nằm sát thanh Lưu). `zoom` cho khung xem chỉ dùng trên trang chuẩn.

## Việc cho backend
| Tính năng | Dữ liệu cần lưu | Luật |
|---|---|---|
| Chữ trên từng bậc màu thương hiệu | `--brand-N-ink` (đen/trắng) cạnh `BrandPalette::from()` | Theo `ReadableColor` (đen khi độ sáng ≥ ≈0,18), tính lại khi đổi màu |
| Tự chuyển hướng khi đổi đường dẫn bài đã đăng | bảng `redirects` (site_id, from, to, code 301) | Tạo khi lưu; không tạo vòng lặp; xóa bài không xóa redirect |
| Danh mục địa chỉ 2 cấp | bảng dùng chung tỉnh/thành + phường/xã/đặc khu (sau sắp xếp 2025) | Không có quận/huyện |
| Câu hỏi thường gặp theo màn | `help_faqs` (screen_key, locale, q, a, order) | Nền tảng soạn; 3–5 câu/màn |
| Đã xem hướng dẫn | `users.preferences.coach[screen]` | Đã tắt thì không tự hiện lại |
| Thùng rác cho loại chưa có | — | Chỉ bài/trang/sản phẩm/ảnh & tệp đã có; loại khác dùng toast Hoàn tác nhưng cần thêm |
| Cần owner chốt | quyền mới thêm vào registry | Đề xuất mặc định TẮT cho mọi vai trò trừ Chủ website (an toàn nhất) |
