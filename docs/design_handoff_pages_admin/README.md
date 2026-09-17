# Handoff: Quản lý Trang (Pages Admin — bảng danh sách trang, đa ngôn ngữ)

## Overview
Màn hình quản trị danh sách **Trang** cho **BSWebSaaS** — nền tảng SaaS website builder đa ngôn ngữ,
đa tenant (phong cách Velzon admin). Người quản trị xem, lọc, sắp xếp, chọn hàng loạt, đổi trạng thái,
đổi tên/đường dẫn nhanh, và mở từng bản dịch ngôn ngữ để chỉnh sửa. Đây là bản **tương tác thật**
(không phải ảnh tĩnh): mọi thao tác lọc/sắp xếp/chọn/sửa đều chạy trên dữ liệu mẫu trong bộ nhớ.

> ‼ **Mô hình dữ liệu (đọc mục “Data Model” trước khi code):** trong BSWebSaaS **KHÔNG có bảng/model
> `pages`**. “Trang” = **`entries` với `type='page'`**; đa ngôn ngữ qua **`entry_translations`**; slug/đường
> dẫn qua bảng **`routes`** + PathBuilder. Mọi chỗ README nói “trang”/`pages[]` là **hình dạng dữ liệu của
> prototype**, phải ánh xạ về mô hình thật này.

Bản cần dựng lại là **phương án `3a`** (section trên cùng của file HTML). Section `2a` phía dưới chỉ là
bản cũ để đối chiếu — **bỏ qua khi hiện thực hoá**.

## About the Design Files
Các file trong gói này là **tài liệu thiết kế viết bằng HTML** — nguyên mẫu (prototype) thể hiện _giao diện
và hành vi mong muốn_, **không phải code sản xuất để copy nguyên**. File `.dc.html` chạy trên một runtime
component tuỳ biến (`support.js`) — một hệ template streaming + class logic kiểu React.

Nhiệm vụ của Claude Code: **dựng lại thiết kế này trong codebase BSWebSaaS**, theo đúng stack & quy ước
hiện hành: **Admin = Livewire 4 + Blade + Tailwind 4**, component `<x-admin::*>` sẵn có, icon
`<x-admin::icon>` inline-SVG, token `--color-admin-*` / `--vz-*`. KHÔNG Bootstrap/Flux, KHÔNG icon webfont.

> Ánh xạ nhanh: class `Component extends DCLogic` trong `.dc.html` ⇒ một React component; `this.state`
> ⇒ `useState`/`useReducer`; `renderVals()` ⇒ phần tính toán trong render; các `{{ ... }}` trong template
> ⇒ JSX. Toàn bộ style đang là **inline** — khi port nên chuyển sang hệ styling của dự án (CSS Modules,
> Tailwind, styled-components…) nhưng **giữ nguyên giá trị token** ở mục Design Tokens.

## Fidelity
**Hi-fi (pixel-perfect).** Màu, typography, khoảng cách, trạng thái hover/active và tương tác đã chốt.
Hãy tái tạo trung thực bằng thư viện/component sẵn có của codebase (giữ đúng token bên dưới). Icon dùng
**Remix Icon 4.9.1**; nếu dự án dùng bộ icon khác thì thay bằng icon tương đương (bảng ánh xạ ở mục Assets).

---

## Screen: “Quản lý Trang”

### Layout tổng thể (top → bottom)
Khung nội dung căn giữa, rộng **1240px**, nền trang `#f4f4f5`.

1. **Page header**
   - Breadcrumb (12px, `#adb5bd`): `🏠 Trang chủ  ›  Trang` (icon `ri-home-4-line`, phân tách `ri-arrow-right-s-line`). “Trang chủ” là link về dashboard; “Trang” là mục hiện tại (`#878a99`, weight 500).
   - Hàng tiêu đề: `H1` “Quản lý Trang” (20px/600, `#343a40`) bên trái; nút **“Thêm trang mới”** bên phải (nền `#405189`, chữ trắng 14px/500, radius 4px, icon `ri-add-line`, shadow nhẹ).

2. **Card** (nền trắng, viền `#eff2f7`, radius 6px, shadow `0 1px 2px rgb(56 65 74 / .15)`) chứa:
   - **Thanh tab trạng thái** (viền dưới `#e9ebec`): Tất cả · Đã xuất bản · Bản nháp · Đã lên lịch · Riêng tư, và **Thùng rác** đẩy về phải. Tab đang chọn: chữ + gạch chân 2px màu `#405189` (Thùng rác dùng `#f06548`). Mỗi tab có badge số đếm (nền nhạt theo màu trạng thái).
   - **Toolbar**: ô tìm kiếm (trái, tối đa 340px, icon `ri-search-line`) + nhóm nút phải: **Ngôn ngữ**, **Người tạo**, **Mẫu trang** (dropdown lọc), một vạch ngăn, và nút **Cột** (bộ chọn cột).
   - **Chip điều kiện lọc** (chỉ hiện khi có lọc): “Đang lọc:” + các chip có nút ✕ + “Xóa tất cả”, bên phải là “Khớp N / M trang”.
   - **Thanh hành động hàng loạt** (chỉ hiện khi đã chọn ≥1 dòng): “Đã chọn N trang” + các nút; khác nhau theo tab (xem Interactions).
   - **Bảng** (chi tiết cột bên dưới).
   - **Footer phân trang**: “Hiển thị a–b trong N trang…”, chọn “Dòng/trang”, và cụm nút số trang + prev/next.
3. **Toast** xác nhận (fixed, giữa-dưới, nền `#212529`, chữ trắng, icon `ri-checkbox-circle-line` màu `#0ab39c`), tự ẩn sau ~2.4s.

### Cấu trúc cột của bảng
Thứ tự: **[checkbox] · Tiêu đề · [Đường dẫn] · Trạng thái · [Ngôn ngữ] · [Người tạo] · Hành động**.

- Cột **Tiêu đề** chiếm hết khoảng trống còn lại (`width:100%`). Mọi cột khác rộng **vừa đủ nội dung**.
- 3 cột trong `[…]` (Đường dẫn, Ngôn ngữ, Người tạo) **bật/tắt được** qua nút “Cột”.
- 3 cột **bắt buộc, không tắt được**: Tiêu đề, Trạng thái, Hành động.

Chi tiết từng cột:

1. **Checkbox** (rộng 40px): ô chọn dòng. Header là ô chọn-tất-cả (tick khi tất cả dòng của trang hiện chọn, dấu trừ `ri-subtract-line` khi chọn một phần). Ô đã chọn: nền `#405189`, tick trắng.

2. **Tiêu đề** (sortable):
   - Tên trang (13px/500, `#495057`), **sửa tại chỗ** (xem Inline edit). Trang con thụt vào 18px, có icon `ri-corner-down-right-line` (`#ced4da`).
   - Dòng meta ngay dưới tên (11.5px, `#adb5bd`): icon `ri-eye-line` + **lượt xem** — “12.480 lượt xem”, hoặc “Chưa có lượt xem” nếu = 0.

3. **Đường dẫn** (slug, tắt được):
   - Hiển thị 1 dòng, tối đa **240px**, quá dài thì cắt bằng `…` (`text-overflow:ellipsis`); tooltip hiện full path. Font monospace 12px, `#878a99`.
   - **Sửa tại chỗ** (xem Inline edit) — **trừ trang chủ**: trang chủ (slug `/`) hiển thị dạng khoá (icon `ri-lock-line`, `#ced4da`, không bấm được, tooltip “Đường dẫn trang chủ — không thể thay đổi”).

4. **Trạng thái** (sortable):
   - Badge bấm được (mở menu đổi trạng thái): nền nhạt + chấm tròn + nhãn + `ri-arrow-down-s-line`. Bảng màu ở Design Tokens.
   - Dòng meta dưới badge (11px, `#adb5bd`): **ngày cập nhật** định dạng theo locale (mặc định `vi-VN` → “2 thg 7, 2026”, icon `ri-history-line`); riêng trạng thái **Đã lên lịch** hiển thị lịch đăng “Đăng 8 thg 7, 2026 · 09:00” (icon `ri-time-line`).

5. **Ngôn ngữ** (tắt được):
   - Bốn mã cố định **VI · EN · JA · ZH** dạng chip nhỏ (10px/700). Ngôn ngữ đã có nội dung: chip đặc (`#495057` trên `#f3f6f9`, viền `#e9ebec`). Ngôn ngữ chưa có: chip nhạt nét đứt (`#c8ccd2`, viền `#e3e6ea`).
   - **Bản thân mã KHÔNG bấm** để tránh nhầm. Khi **di chuột vào một mã**, một **nút nổi hiện phía trên** mã đó: “✎ Sửa” (nền `#405189`) nếu ngôn ngữ đã có, hoặc “+ Thêm” (nền `#0ab39c`) nếu chưa có. Bấm nút nổi mới thực thi. (Header cột có icon `ri-question-line` + tooltip giải thích.)

6. **Người tạo** (tắt được, sortable): avatar tròn 26px với **màu riêng theo từng người** (2 chữ cái) + tên. Bảng màu avatar ở Design Tokens.

7. **Hành động** (rộng 132px, căn giữa header): cụm nút icon 28×28.
   - Dòng thường: **Xem trước** (`ri-eye-line`), **Sửa nội dung** (`ri-pencil-line`), **Nhân bản** (`ri-file-copy-line`), **Thùng rác** (`ri-delete-bin-line`, hover đỏ `#f06548`).
   - Dòng trong Thùng rác: **Khôi phục** (`ri-arrow-go-back-line`), **Xoá vĩnh viễn** (`ri-delete-bin-2-line`).
   - Nút luôn hiển thị (ghost `#878a99`), sáng màu khi hover.

---

## Interactions & Behavior

- **Tìm kiếm**: lọc theo tên **hoặc** slug (không phân biệt hoa/thường), cập nhật tức thời; đặt lại về trang 1.
- **Tab trạng thái**: lọc theo trạng thái; “Tất cả” = mọi trang chưa xoá; “Thùng rác” = trang đã xoá. Đổi tab sẽ bỏ chọn hiện tại và đóng các menu. Số đếm badge tính động từ dữ liệu.
- **Dropdown lọc** (Ngôn ngữ / Người tạo / Mẫu trang): chọn nhiều (checkbox), có số lượng từng mục; nút “Đặt lại” và “Xong”. Nút lọc sáng màu `#405189` + badge số khi đang áp dụng. Ngữ nghĩa: **hợp (OR)** trong cùng một nhóm; kết quả = giao của các nhóm.
- **Chip điều kiện**: mỗi chip ✕ để bỏ một điều kiện; “Xóa tất cả” xoá toàn bộ lọc + tìm kiếm.
- **Nút “Cột”**: dropdown 2 nhóm — “Cột bắt buộc” (Tiêu đề, Trạng thái, Hành động: có icon khoá `ri-lock-line`, không tắt) và “Tùy chọn” (Đường dẫn, Ngôn ngữ, Người tạo: bật/tắt). `colspan` của dòng rỗng tính động theo số cột đang hiện.
- **Sắp xếp**: bấm tiêu đề cột **Tiêu đề / Trạng thái / Người tạo** để đổi chiều (mũi tên `ri-arrow-up-line`/`ri-arrow-down-line`; chưa sắp: `ri-expand-up-down-line`). Mặc định sắp theo “cập nhật” giảm dần.
  - **QUAN TRỌNG — cây cha/con**: sắp xếp theo **2 cấp**. Chỉ sắp các trang **cha**; trang **con luôn bám ngay dưới cha của nó** (con cũng được sắp trong nội bộ nhóm). Con của “Dịch vụ” không bao giờ trôi xuống dưới trang khác. Nếu một bộ lọc ẩn mất trang cha nhưng con vẫn khớp, con được “nâng” lên cấp gốc (không thụt) để không mồ côi dưới nhầm cha.
- **Chọn dòng**: checkbox từng dòng + checkbox header (chọn/bỏ chọn tất cả dòng đang hiển thị) ⇒ hiện **thanh hành động hàng loạt**:
  - Tab thường: **Xuất bản**, **Chuyển nháp**, **Nhân bản**, **Thùng rác**, và “Bỏ chọn”.
  - Tab Thùng rác: **Khôi phục**, **Xoá vĩnh viễn**, “Bỏ chọn”.
- **Đổi trạng thái theo dòng**: bấm badge trạng thái mở menu 4 lựa chọn (Đã xuất bản / Bản nháp / Đã lên lịch / Riêng tư), mục hiện tại có tick.
- **Hành động dòng**: Xem trước & Sửa nội dung → hiện toast (chỗ này nối vào điều hướng/editor thật của app); Nhân bản → tạo bản sao “(bản sao)” trạng thái nháp ngay dưới; Thùng rác → đánh dấu đã xoá; trong Thùng rác: Khôi phục / Xoá vĩnh viễn.
- **Thêm trang mới**: chèn một bản nháp “Trang mới” lên đầu, reset lọc/tìm kiếm về mặc định.
- **Sửa nhanh tại chỗ (Inline edit) — Tiêu đề & Đường dẫn**:
  - Affordance: di chuột vào ⇒ nền bo góc nhạt `#eef2fb` + icon `ri-pencil-line` hiện gọn ở mép phải (không xô chữ) + con trỏ `text`.
  - Bấm ⇒ chuyển thành ô nhập (`autoFocus`, tự bôi chọn toàn bộ chữ).
  - **Tự lưu khi rời ô** (blur); **Enter** = lưu; **Esc** = huỷ.
  - **Slug**: dấu `/` (và với **trang con** là cả tiền tố cha, vd `/dich-vu/`) hiển thị **cố định, không sửa được**; người dùng chỉ nhập **phần slug riêng**. Khi lưu, phần riêng được **chuẩn hoá slug**: bỏ dấu tiếng Việt (NFD + strip combining marks, `đ→d`), về chữ thường, đổi khoảng trắng/`_` → `-`, loại ký tự lạ (giữ `a-z 0-9 / -`), gộp `//`/`--`, cắt `-`/`/` ở đầu-cuối; rồi ghép lại với tiền tố. Nên dán cả tiêu đề vào cũng ra slug sạch.
- **Đóng menu khi bấm ra ngoài**: khi có dropdown/menu mở, một lớp phủ trong suốt (fixed, `z-index:40`) bắt click để đóng.
- **Toast**: xác nhận mọi thao tác thay đổi (đổi trạng thái, xoá, khôi phục, nhân bản, đổi tên/đường dẫn, tạo bản dịch…), tự ẩn ~2.4s.

## Data Model (BẮT BUỘC — theo repo BSWebSaaS)
> Đây là phần GHI ĐÈ mọi mô tả “Page” generic trong tài liệu. Trước khi code, dùng công cụ khảo sát
> schema thật của repo để xác nhận tên bảng/cột.

- **KHÔNG tạo bảng/model `pages`** (vi phạm anti-pattern của dự án). **“Trang” = `entries` với `type='page'`.**
  “Bài” = `type='post'`; `product` là bảng riêng. Các “loại” (tin/dịch vụ/dự án/tuyển dụng…) đều là `post`,
  phân biệt bằng **category (taxonomy ltree) + template + meta**.
- **Template gán theo category** (không phải cột tự do trên entry). Bộ lọc “Mẫu trang” trong UI ánh xạ về đây.
- **Đa ngôn ngữ = bảng `entry_translations`** (một hàng / (entry, locale)); KHÔNG JSONB cho nội dung dài, KHÔNG
  nhân bản entry theo ngôn ngữ. Slug bản địa hoá qua bảng **`routes`** + **PathBuilder** duy nhất
  (default-locale không prefix; non-default `/{locale}`).
- **Content-locale theo cấu hình từng site** — **KHÔNG hardcode 4 mã** VI/EN/JA/ZH trong prototype. Đọc
  content-locale thật của site (vd site An Vinh = **vi/en/ja**, default **vi**; **zh chỉ là UI-locale, KHÔNG
  phải content-locale**). Cột “Ngôn ngữ” render đúng tập content-locale của site; nút hover “Sửa”/“Thêm” thao
  tác trên `entry_translations` của locale tương ứng.
- **Trạng thái**: chuyển qua **workflow engine** (không set cột thô); ánh xạ pub/draft/sched/priv về trạng thái thật.
- **Thùng rác** = **soft-delete `deleted_at`** (scope/RLS luôn kèm `deleted_at IS NULL`).
- **Trang chủ**: là entry được đánh dấu homepage; **đường dẫn `/` KHOÁ, không cho sửa slug** (chỉ sửa được tiêu đề).
- **Lượt xem / analytics**: kiểm tra xem cột/nguồn số liệu đã tồn tại chưa. **Nếu chưa có → COUNCIL propose-first**,
  KHÔNG tự thêm cột/bảng. Trong lúc chưa có, có thể ẩn phần lượt xem.
- **Đa tenant**: mọi truy vấn qua `site_id` + RLS + trait `BelongsToSite`; KHÔNG lộ `bigint id` thô (dùng slug/code/hash).
  Mọi action qua **Policy**. Thời gian **timestamptz** render theo tz site. **Query Budget**: eager-load,
  phân trang/keyset phía server, KHÔNG N+1, KHÔNG `COUNT(*)` live bảng lớn.

## State Management
Các biến state dưới đây là **hình dạng của prototype** — ánh xạ sang Livewire component (public properties +
`#[Computed]`) và mô hình dữ liệu ở mục trên, KHÔNG dùng như schema.
- `pages[]`: (prototype) mỗi phần tử ⇔ **một `entry type=page`** đã eager-load bản dịch/route/trạng thái. Các field
  prototype: `id, name, slug, st('pub'|'draft'|'sched'|'priv'), parentId?, template, langs[], creator, updatedExact('dd/mm/yyyy'), views(string), viewsNum, trashed(bool)` — ánh xạ: `name/slug`⇒`entry_translations`+`routes` theo locale; `st`⇒trạng thái workflow; `parentId`⇒quan hệ cha-con của entry; `template`⇒category→template; `langs`⇒các locale có `entry_translations`; `trashed`⇒`deleted_at`.
- `search`, `tab`, `langF[]`, `creatorF[]`, `templateF[]` — điều kiện lọc.
- `openFilter` (`'lang'|'creator'|'template'|'cols'|null`) — dropdown đang mở.
- `sortKey` (`'name'|'status'|'creator'|'updated'`), `sortDir` (`'asc'|'desc'`).
- `selected{}` — map id→true các dòng đã chọn.
- `statusMenu` — id dòng đang mở menu trạng thái.
- `page`, `perPage` (10/20/50).
- `cols{slug,langs,creator}` — cột nào đang hiện.
- `edit{id,field}` + `draft` — trạng thái đang sửa tại chỗ.
- `toast`, `seq` (bộ đếm sinh id khi nhân bản/thêm), `locale` (mặc định `'vi-VN'`).

Data đang là **mẫu trong bộ nhớ, không lưu khi tải lại** — cần nối API thật (list + phân trang/lọc phía server nếu dữ liệu lớn; các action: đổi trạng thái, nhân bản, xoá/khôi phục/xoá vĩnh viễn, đổi tên/slug, tạo bản dịch).

## Design Tokens

**Màu thương hiệu / trạng thái**
- Primary (indigo): `#405189` (hover đậm `#364574`)
- Success: `#0ab39c` · Warning: `#c78729` (nền vàng `#f7b84b` / `rgba(247,184,75,.15)`) · Info: `#3577f1` · Danger: `#f06548`
- Badge trạng thái (chữ / nền): pub `#0ab39c` / `rgba(10,179,156,.1)`; draft `#495057` / `rgba(33,37,41,.08)`; sched `#c78729` / `rgba(247,184,75,.15)`; priv `#3577f1` / `rgba(53,119,241,.1)` (chấm tròn = màu chữ).

**Chữ / nền / viền**
- Text chính `#495057`, đậm `#343a40`; phụ `#878a99`; nhạt `#adb5bd`; rất nhạt `#ced4da` / `#c8ccd2`.
- Nền trang `#f4f4f5`; nền khung canvas `#f3f3f9`; nền input `#f8f9fb`; highlight hover ô sửa `#eef2fb`; hover dòng `#f8f9fa`.
- Viền `#e9ebec` / `#eff2f7` / `#ced4da` / `#e3e6ea`.

**Avatar người tạo** (nền / chữ)
- Ngọc Anh `#e0e7ff` / `#4338ca` (NA) · Minh Trí `#fce7f3` / `#be185d` (MT) · Thu Hà `#d1fae5` / `#047857` (TH). Viền avatar = màu chữ + alpha `33`.

**Typography**: Inter (400/500/600/700). H1 20/600. Tab 13.5px. Ô/nhãn 13px. Header cột 12.5px. Meta 11–11.5px. Slug monospace 12px. Icon Remix 15px (nút), 12–13px (phụ).

**Bo góc**: nút 4px; input/ô sửa 5–6px; card 6px; dropdown 8px; chip/badge tab 4px; pill/chip lọc 20px.
**Shadow**: card `0 1px 2px rgb(56 65 74 / .15)`; dropdown `0 10px 30px rgba(30,32,37,.18)`; menu `0 5px 15px rgba(30,32,37,.15)`; nút nổi ngôn ngữ `0 6px 16px rgba(30,32,37,.28)`; focus ring `0 0 0 3px rgba(64,81,137,.12)`.

## Assets
- **Font**: Google Fonts — Inter (`https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700`).
- **Icon**: Remix Icon 4.9.1 (`https://cdn.jsdelivr.net/npm/remixicon@4.9.1/fonts/remixicon.css`). Các icon dùng: `ri-home-4-line, ri-arrow-right-s-line, ri-add-line, ri-file-list-3-line, ri-delete-bin-line, ri-delete-bin-2-line, ri-search-line, ri-translate-2, ri-user-3-line, ri-layout-2-line, ri-layout-column-line, ri-lock-line, ri-check-line, ri-subtract-line, ri-arrow-up-s-line, ri-arrow-down-s-line, ri-arrow-up-line, ri-arrow-down-line, ri-expand-up-down-line, ri-corner-down-right-line, ri-eye-line, ri-pencil-line, ri-file-copy-line, ri-history-line, ri-time-line, ri-arrow-go-back-line, ri-close-line, ri-refresh-line, ri-inbox-2-line, ri-check-double-line, ri-draft-line, ri-loop-right-line, ri-checkbox-circle-line, ri-question-line, ri-arrow-left-s-line`. Thay bằng icon tương đương nếu codebase dùng bộ khác.
- Không dùng ảnh/bitmap. Avatar là chữ viết tắt, không phải ảnh.

## Files
- `Pages Admin - Options.dc.html` — nguyên mẫu (dùng **section `3a`**; bỏ qua `2a`).
- `support.js` — runtime của prototype (chỉ để chạy bản mẫu; **không port** — chỉ tham chiếu cách logic hoạt động).

> Mẹo đọc nhanh: phần `<script data-dc-script>` cuối file `.dc.html` chứa toàn bộ logic (state, bộ lọc,
> sắp xếp theo cây cha/con, chọn hàng loạt, inline edit + slugify, định dạng ngày theo locale). Đây là
> nguồn chuẩn xác nhất về hành vi — đọc nó song song với README này.
