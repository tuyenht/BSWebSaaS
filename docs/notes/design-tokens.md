# Chuẩn tên biến thiết kế (design tokens)

> **Trạng thái:** CHỐT 07/10/2026 22:0x. Owner duyệt phương án D và cách chuyển "tên mới + bí danh, không dừng designbot". Bản này đã sửa theo một vòng phản biện độc lập (Opus) cùng ngày.
> **Normative:** mọi CSS mới trong repo (trang quản trị và website khách) dùng tên trong tài liệu này. Tên cũ chỉ còn là bí danh trong thời gian chuyển (§7).
> **Grounded:** kiểm kê 07/10, gồm 288 tên ở 3 nguồn:
> - code đang chạy: `resources/css/{app,admin-chrome,fonts,storefront}.css`, `ThemeResolver`, `AdminChromeResolver`;
> - bản thiết kế: export Claude Design 07/10 19:17;
> - bộ design system cũ: `docs/design-source/_ds/**/tokens`.
>
> **Quan hệ với ADR-04** (`docs/notes/section-style-system.md`, kiểu khối của website khách):
> - Tài liệu này dùng lại đúng tên vai trò ADR-04 đã đặt (`--color-surface`, `--color-surface-tint`, `--color-dark`, `--color-on-dark`, `--color-text`, `--color-text-muted`, `--color-accent`, `--font-heading`, `--font-body`, `--space-*`, `--radius-soft/round`, `--shadow-soft/medium`, `--gradient-*`, `--motion-*`).
> - Chỉ đổi `--brand-*` thành `--color-brand-*` để Tailwind sinh class.
> - Luật "khách chỉ chọn token trong danh sách cho phép" của ADR-04 giữ nguyên.

## 0. Mục tiêu

- **Một bộ tên cho cả hệ thống.** Trang quản trị và website khách dùng cùng tên cho cùng vai trò. Giá trị khác nhau theo phạm vi; hai bên dùng hai tệp CSS riêng (`app.css` và `storefront.css`), nên không va nhau.
- **Đổi tông / nhận diện bằng một giá trị.** Đặt một màu thương hiệu: dải màu, màu nút, màu chữ thương hiệu đủ tương phản và chế độ tối tự theo. Phần "chọn bậc đủ tương phản" tính bằng PHP (`BrandPalette`, `ReadableColor`), vì CSS không duyệt được các bậc.
- **Bán được dưới tên khách (white-label).** Tên biến KHÔNG mang tên thương hiệu hay nhà cung cấp (`--bs-*`, `--vz-*`); nhận diện nằm ở GIÁ TRỊ.
- **Đúng Tailwind 4 (bản đã chốt).**
  - Biến trong `@theme` theo không gian tên của Tailwind sinh luôn class.
  - Tên mới KHÔNG ghi đè tên có sẵn của Tailwind (`text-sm`, `shadow-md`, `white`, `neutral-*`…) để không đổi hình các chỗ đang dùng class đó.
- **Thang cố định, không giá trị lẻ.**
  - Chuẩn thiết kế của owner: 5–6 cỡ chữ, khoảng cách theo lưới 4/8px, chữ phụ ≥ 12px, giãn dòng ≥ 1,5, đơn vị tương đối.
  - Bản thiết kế 07/10 dùng 12 cỡ chữ; code còn `text-[11px]`, `[10px]`, `[9px]`.
  - §3 nói cách quy về thang.

## 1. Ba tầng

| Tầng | Chứa gì | Ai được dùng |
|---|---|---|
| **1 · Nguồn** | Giá trị thô: dải màu thương hiệu, dải xám, sắc gốc trạng thái, đơn vị | Chỉ tầng 2 và 3. Màn hình KHÔNG dùng trực tiếp. |
| **2 · Vai trò** | Ý nghĩa: nền, chữ, viền, màu chính, focus, trạng thái, cỡ chữ theo vai trò | Mọi màn hình, mọi thành phần |
| **3 · Thành phần** | Biến riêng của thanh trên, thanh bên, chân trang, khối website khách | Chỉ thành phần đó |

Luật:
- Màn hình chỉ viết `var(--<tầng 2 hoặc 3>)` hay class Tailwind tương ứng. Không mã hex, không px lẻ.
- Đổi tông là đổi tầng 1. Chế độ tối và kiểu thanh chỉ đổi tầng 2 và 3.
- Mọi khối đổi giá trị (chế độ tối, thanh Đậm / Sáng / Chuyển màu, ảnh nền thanh bên, ghi đè trong một thành phần) viết lên TÊN MỚI. Không viết lên bí danh.
- Một tên một ý. Không đặt tên theo màu ("blue", "navy") ở tầng 2.

## 2. Danh sách tên

### 2.1 Màu, tầng 1

| Tên | Ý nghĩa | Nguồn giá trị |
|---|---|---|
| `--color-brand-50` … `--color-brand-900` | Dải màu thương hiệu (10 bậc, đúng như `BrandPalette` đang sinh) | Website khách: `ThemeResolver` từ khóa `primary`. Trang quản trị: cùng cách, từ màu thương hiệu của nền tảng (§4). |
| `--color-gray-50` … `--color-gray-900` | Dải xám | Giữ tên đang có (đã ghi đè dải xám mặc định của Tailwind bằng giá trị riêng). Không đổi sang `neutral` để khỏi đè dải `neutral` của Tailwind. |
| `--color-{success,info,warning,danger}-base` | Sắc gốc trạng thái (biểu tượng, viền, nền nhạt) | Thay `--color-admin-{…}` gốc |

### 2.2 Màu, tầng 2

| Tên | Dùng cho | Tương phản tối thiểu |
|---|---|---|
| `--color-bg` | Nền trang | — |
| `--color-surface` · `--color-surface-hover` · `--color-surface-muted` · `--color-surface-tint` | Nền thẻ · khi trỏ chuột · hàng xen kẽ · nền nhuốm màu thương hiệu (ADR-04) | — |
| `--color-text` | Chữ thường | ≥ 4,5:1 trên `bg` và `surface` |
| `--color-text-strong` | Tiêu đề | ≥ 4,5:1 |
| `--color-text-muted` | Chữ phụ người đọc cần | ≥ 4,5:1 |
| `--color-text-faint` | Chỉ chi tiết trang trí, KHÔNG cho chữ có nghĩa | miễn |
| `--color-icon-ghost` | Biểu tượng trang trí của trạng thái trống | miễn |
| `--color-placeholder` | Chữ gợi ý trong ô nhập | ≥ 4,5:1 |
| `--color-border` · `--color-border-strong` · `--color-border-soft` | Vạch chia (trang trí) | miễn |
| `--color-border-input` | Viền ô nhập, nút viền | ≥ 3:1 |
| `--color-primary` | Nền màu thương hiệu (nút chính, huy hiệu) | `on-primary` trên nó ≥ 4,5:1 |
| `--color-on-primary` | Chữ / biểu tượng trên `primary` | PHP chọn trắng hay `--color-dark`, cái nào đọc được. Website khách màu sáng (vàng hổ phách của An Vinh) thì là chữ tối. |
| `--color-primary-text` | Màu thương hiệu làm CHỮ, viền, liên kết | ≥ 4,5:1, có giá trị riêng cho chế độ tối |
| `--color-primary-soft` | Nền nhạt màu thương hiệu (mục đang chọn) | `primary-text` trên nó ≥ 4,5:1 |
| `--color-focus` | Vòng focus (Focus 1b) | ≥ 3:1 |
| `--color-{success,info,danger}` | Nền đặc dưới chữ trắng | ≥ 4,5:1 |
| `--color-warning` · `--color-on-warning` | Nền vàng (= sắc gốc) · chữ tối trên nó | ≥ 4,5:1 |
| `--color-{success,info,warning,danger}-text` | Chữ / kiểu nhạt | ≥ 4,5:1 cả sáng lẫn tối |
| `--color-{success,info,warning,danger}-soft` | Nền nhạt trạng thái | `-text` trên nó ≥ 4,5:1 |
| `--color-secondary` · `--color-secondary-text` | Màu phụ #2f69d4 đang dùng (nhãn "thông tin phụ", liên kết phụ) | ≥ 4,5:1. Chuẩn chỉ cho một màu nhấn: §9. |
| `--color-dark` · `--color-on-dark` · `--color-primary-on-dark` | Dải nền đậm (chân trang website khách) · chữ trên đó · màu thương hiệu làm chữ trên đó (ADR-04) | ≥ 4,5:1 |
| `--color-accent` | CHỈ website khách: màu thứ hai của ADR-04 (gradient thương hiệu → accent) | — |

`--color-accent` của trang quản trị hôm nay (= chữ màu thương hiệu) đổi thành `--color-primary-text`. Tên cũ chỉ còn là bí danh trong `app.css` đến khi gỡ (§6), sau đó `--color-accent` chỉ còn nghĩa của ADR-04.

### 2.3 Màu, tầng 3

Giữ không gian tên `--color-<thành phần>-<phần>` để Tailwind vẫn sinh class:
- **thanh bên:** `--color-sidebar-bg`, `-fg`, `-fg-hover`, `-fg-active`, `-hover`, `-active`, `-title`, `-brand`;
- **thanh trên:** `--color-topbar-bg`, `-border`, `-fg`, `-fg-strong`, `-fg-hover`, `-hover`, `-brand`, `-search`, `-search-fg`, `-search-border`, `-search-placeholder`, `-search-focus`, `-search-accent`, `-user`, `-user-block`, `-user-fg`;
- **chân trang:** `--color-footer-fg`.

Giá trị đặt thẳng ở đây, bỏ lớp `--vz-*` trung gian. Các bậc màu của thanh Đậm / Chuyển màu viết bằng bậc dải thương hiệu (`--color-brand-700`…), không viết mã màu, nên đổi màu thương hiệu thì thanh đổi theo.

### 2.4 Chữ

| Tên | Giá trị | Ghi chú |
|---|---|---|
| `--user-text-boost` | `0` · `0.125rem` · `0.25rem` · `0.375rem` | Cỡ chữ theo người (D0-7): Vừa · Lớn · Rất lớn · Cực lớn (+2 / +4 / +6px). Đặt ở `:root` và ở khung xem trước nào đổi mức riêng. Tên nằm NGOÀI không gian tên `--text-*` để Tailwind không sinh class `text-boost`. Thay `--fsp`. |
| `--text-caption` | `calc(0.75rem + var(--user-text-boost))`, giãn dòng 1,5 | Chú thích, gợi ý: nhỏ nhất, 12px |
| `--text-small` | `calc(0.8125rem + var(--user-text-boost))`, 1,5 | Chữ phụ, nhãn bảng (13px) |
| `--text-body` | `calc(0.875rem + var(--user-text-boost))`, 1,5 | Chữ nội dung mặc định (14px) |
| `--text-lead` | `calc(1rem + var(--user-text-boost))`, 1,5 | Tiêu đề thẻ, chữ nhấn (16px) |
| `--text-heading` | `calc(1.125rem + var(--user-text-boost))`, 1,4 | Tiêu đề mục (18px) |
| `--text-title` | `calc(1.375rem + var(--user-text-boost))`, 1,3 | Tiêu đề màn h1 (22px) |
| `--font-sans` | `var(--font-stack)` (Inter + dự phòng đủ dấu tiếng Việt) | Một họ chữ cho trang quản trị |
| `--font-heading` · `--font-body` | Website khách, theo theme (ADR-04) | Tối đa 2 họ chữ |
| `--font-weight-heading` | `var(--ui-heading-weight)` (700; chữ Trung / Nhật / Hàn 750 / 800 như `fonts.css`) | Độ đậm số dùng class có sẵn của Tailwind (`font-normal/medium/semibold/bold`), không định nghĩa lại |
| `--icon-sm` · `--icon-md` | `min(1.25rem, calc(1rem + var(--user-text-boost)))` · `min(1.5rem, calc(1.125rem + var(--user-text-boost)))` | Biểu tượng to theo chữ nhưng có trần 20 / 24px, như D0-7 |

Cách khai báo:
- Các biến `--text-*` nằm trong `@theme inline`, mỗi cỡ kèm `--text-<tên>--line-height`, để `var(--user-text-boost)` được tính tại phần tử dùng nó. Khung xem trước đặt mức riêng vẫn đổi cỡ.
- Tên vai trò (`caption`, `small`, `body`…) không trùng tên có sẵn của Tailwind (`xs`, `sm`, `base`…), nên các class `text-sm`… đang dùng không đổi hình.
- Website khách thêm cỡ tiêu đề lớn theo ADR-04 ("scale modular h1–h4"), không dùng ở trang quản trị.

### 2.5 Khoảng cách, bo góc, bóng, chuyển động, lớp, khung

| Tên | Giá trị |
|---|---|
| Khoảng cách | Dùng đơn vị có sẵn của Tailwind (`--spacing` = 0.25rem = 4px; `p-2` = 8px…), KHÔNG định nghĩa lại. Mọi khoảng cách trang quản trị là bội số 4. Website khách: `--space-s/m/l/xl` × `--space-unit` của ADR-04 (§9). |
| Bo góc | Dùng thang có sẵn của Tailwind: `rounded-sm` 4 · `rounded-md` 6 · `rounded-lg` 8 · `rounded-xl` 12 · `rounded-full`. Website khách: `--radius-soft/round` (ADR-04). |
| Bóng | `--shadow-card` (giữ tên đang có) cho thẻ trang quản trị. Menu thả, hộp thoại dùng `shadow-md` / `shadow-lg` có sẵn của Tailwind. Website khách: `--shadow-soft/medium` (ADR-04). |
| Chuyển động | `--duration-fast` 150ms · `--duration-base` 200ms · `--ease-standard` `cubic-bezier(.2,0,0,1)`; giảm chuyển động thì về 0. Website khách: `--motion-duration` (ADR-04, §9). |
| Lớp chồng | `--z-sticky` 10 · `--z-dropdown` 20 · `--z-overlay` 30 · `--z-modal` 40 · `--z-toast` 50 |
| Khung | `--sidebar-width` 250px · `--sidebar-width-collapsed` 70px · `--topbar-height` 70px · `--container-boxed` 1300px |

## 3. Quy về thang khi chuyển thiết kế sang code

Đây là thay đổi NHÌN THẤY ĐƯỢC. Làm ở bước 4 (§8) cùng lúc chuyển từng màn, kiểm bằng ảnh chụp trước/sau ở 390 và 1366. Không làm ở bước 2.

| Loại | Đang có | Quy về |
|---|---|---|
| Cỡ chữ (thiết kế) | 12,5 · 13,5 · 15 · 17 · 19 · 20px | `small` · `body` · `body` (chữ thường) hoặc `lead` (tiêu đề) · `lead` hoặc `heading` · `heading` · `title` (h1) hoặc `heading` |
| Cỡ chữ (code) | `text-[9px]` · `[10px]` · `[10.5px]` · `[11px]` · `[11.5px]` · `[12.5px]` | `caption` (12px; chuẩn cấm chữ dưới 12px) · `small` cho 12,5 |
| Khoảng cách | 2 · 5 · 6 · 7 · 9 · 10 · 11 · 14px | 4 · 4 · 4 hoặc 8 · 8 · 8 · 8 hoặc 12 · 12 · 16. Gần nhất; bằng nhau thì lên cho khoảng giữa khối, xuống cho khoảng trong chip / nút. |
| Bo góc | 3 · 5 · 7 · 20px | 4 · 4 · 8 · `full` |

## 4. Đổi tông, nhận diện

- **Một nguồn:** một màu thương hiệu.
  - Trang quản trị: màu của nền tảng, sau này là ô "màu trang quản trị" ở Cài đặt nền tảng › Nhận diện (D4-3).
    - Bảng 9 màu được duyệt nằm ở `resources/admin-brands.json` (màu gốc, nền dưới chữ trắng, mực chữ chế độ sáng; mực chế độ tối = `color-mix(in oklab, gốc 38%, #e6eeff)` trong CSS). PHP (`AdminBrandColor`) và `scripts/contrast-check.mjs` cùng đọc bảng này.
    - Nền tảng chọn bằng khóa `config('admin.brand.color')` (`ADMIN_BRAND_COLOR`, mặc định `bao-son`); khóa lạ về mặc định.
  - Website khách: khóa `primary` trong `site_settings` (giữ nguyên, không migration).
- **Tính bằng PHP, in một khối `<style>:root{…}</style>` ở đầu trang**, không in vào thuộc tính `style` của `<html>` (thuộc tính đó thắng cả khối chế độ tối):
  - `BrandPalette` sinh `--color-brand-50…900`;
  - `ReadableColor` chọn `--color-primary`, `--color-on-primary`, `--color-primary-text` (sáng và tối), `--color-primary-soft`, `--color-primary-on-dark`.
  - Website khách: `ThemeResolver` đã làm phần lớn việc này với tên cũ. Trang quản trị: `AdminChromeResolver` làm cùng cách.
- **Không còn mã màu thương hiệu gắn cứng trong CSS.** Hôm nay có 12 chỗ:
  - `#405189` và `rgb(64 81 137 / …)` ở `app.css` và `admin-chrome.css`;
  - các bậc tự pha `#495c99`, `#52639c`, `#bccdde`, `#b0c4d9`, `#96a1c0` của thanh bên, thanh trên và chế độ tối.
  
  Tất cả thành bậc của `--color-brand-*`.
- **`@theme` chỉ giữ giá trị MẶC ĐỊNH lúc build.** Giá trị theo nền tảng hay theo website luôn đến từ khối `<style>` lúc chạy, vì Vite dựng `@theme` một lần lúc build.
- **Kiểm tự động:** `scripts/contrast-check.mjs` đo các cặp tầng 2 theo tên mới cho mọi màu thương hiệu nằm trong danh sách cho chọn.

## 5. Chế độ tối, kiểu thanh, cỡ chữ theo người

- **Chế độ tối:** thuộc tính `data-theme="dark"` trên `<html>`, thay `data-bs-theme` (code) và `data-scheme` (bản thiết kế). Trong thời gian chuyển, CSS nhận cả hai tên cũ.
- **Chỉ tầng 2 và tầng 3 đổi giá trị** dưới chế độ tối và các kiểu thanh (`data-topbar`, `data-sidebar`, `data-layout`… giữ tên thuộc tính đang có).
- **Cỡ chữ theo người:** `--user-text-boost` đặt trên `<html>` theo thứ tự lựa chọn của người đó › mặc định của website › Vừa (D0-7, D3-1s).

## 6. Bí danh và luật chuyển

- **Bí danh một chiều:** tên cũ trỏ sang tên mới (`--color-accent: var(--color-primary-text)`), không bao giờ ngược lại, để không có vòng lặp.
  - Bí danh nằm trong CÙNG khối `@theme` (cùng lớp CSS với tên mới), ở mục "Bí danh — sẽ gỡ".
  - Ghi đè đặt ở phần tử con (ví dụ `.admin-sidebar` đổi màu focus, ô nhập đổi viền) viết lên tên mới; bí danh tính lại theo.
- **Không làm bí danh cho tên có sẵn của Tailwind** (`--color-white`, `--color-gray-*`, `text-sm`…): các tên đó giữ nguyên.
- **Không gộp hai tên khác giá trị.** Ví dụ `--color-body-faint` (#adb5bd / tối #98a0ae) và `--color-ink-ghost` (#e9ebec / tối #4a5058) thành hai tên riêng (`--color-text-faint`, `--color-icon-ghost`).
- **Chặn tên cũ trong code mới:** kiểm trong CI (bước `frontend`). Dòng MỚI thêm vào `resources/` hay `app/**/*.blade.php` dùng tên cũ ở §7 thì báo lỗi:
  - so khớp cả tên, không so tiền tố (`--ink` không bắt nhầm `--ink-x`);
  - chặn cả class sinh từ tên cũ (`text-body-muted`, `text-accent`, `bg-admin-primary`…).

  Tệp cũ được sửa dần.
- **Thiết kế:**
  - Bản thiết kế (Claude Design) giữ tên cũ trong suốt đợt chạy designbot.
  - Khi chuyển từng màn sang code: đổi tên theo §7 và quy về thang theo §3.
  - Sau đợt chạy: một lượt Claude Design đổi tên trong bản thiết kế.
- **Gỡ bí danh:** khi grep tên cũ = 0, trong một PR riêng.

## 7. Bảng tên cũ → tên mới

| Tên cũ (nơi dùng) | Tên mới |
|---|---|
| `--color-admin-primary` (code, thiết kế) · `--brand-solid` (thiết kế) | `--color-primary` |
| `--primary` (website khách) | `--color-primary`. Chữ trên nó là `--color-on-primary` (PHP chọn), không mặc định trắng. |
| `--color-accent` (trang quản trị, thiết kế) · `--brand-ink` (thiết kế) · `--primary-text` (website khách) | `--color-primary-text` |
| `--brand` (thiết kế, 225 chỗ) | `--color-primary-text` khi là chữ / viền; `--color-primary` khi là nền (xem từng chỗ khi chuyển) |
| `--ink` (website khách, 45 chỗ) | Theo vai trò: tiêu đề → `--color-text-strong`; dải nền đậm → `--color-dark`; chữ trên nền màu → `--color-on-primary` |
| `--primary-on-ink` · `--surface` · `--body` · `--font` (website khách) | `--color-primary-on-dark` · `--color-surface` · `--color-text` · `--font-body` |
| `--brand-50` … `--brand-900` (website khách, ADR-04) | `--color-brand-50` … `--color-brand-900` |
| `--color-on-solid` (thiết kế) | `--color-on-primary`. `--color-white` giữ nguyên (tên có sẵn của Tailwind). |
| `--color-body` · `--vz-body-bg` | `--color-bg` |
| `--color-body-text` | `--color-text` |
| `--color-heading` | `--color-text-strong` |
| `--color-body-muted` · `--s-muted` | `--color-text-muted` |
| `--color-body-faint` · `--color-ink-ghost` | `--color-text-faint` · `--color-icon-ghost` (hai tên, không gộp) |
| `--color-admin-light` · `--color-admin-dark` | `--color-gray-100` · `--color-gray-900` |
| `--color-admin-{success,info,danger,secondary}-solid` | `--color-{success,info,danger,secondary}` |
| `--color-admin-{success,info,warning,danger,secondary}-text` · `--tone-*-fg` · `--tx-*` (thiết kế) | `--color-{…}-text` |
| `--color-admin-dark-text` | `--color-text-strong` |
| `--color-admin-{success,info,warning,danger}` (sắc gốc) | `--color-{…}-base`; vàng còn là `--color-warning` |
| `--color-admin-secondary` | `--color-secondary` |
| `--focus-ring-color` | `--color-focus` (`--focus-ring-width`, `--focus-ring-offset` giữ tên) |
| `--color-sidebar` · `--color-topbar` · `--color-footer` | `--color-sidebar-bg` · `--color-topbar-bg` · `--color-footer-fg` |
| `--vz-vertical-menu-*` · `--vz-header-*` · `--vz-topbar-*` · `--vz-footer-color` | giá trị chuyển thẳng vào `--color-sidebar-*` · `--color-topbar-*` · `--color-footer-fg` |
| `--vz-font-*`, `--vz-body-font-*`, `--vz-headings-*` | `--text-*` (vai trò), `--font-weight-heading`, class có sẵn của Tailwind; `--font-stack` giữ (nguồn của `--font-sans`) |
| `--fsp` (thiết kế, 3.362 chỗ) | `--user-text-boost`; mỗi `calc(Npx + var(--fsp))` thành `var(--text-…)` theo §2.4 và §3. Từ 27/10/2026 canvas thiết kế đã dùng `--user-text-boost` và rem (gốc 100 %), nên `calc(0.875rem + var(--user-text-boost))` → `text-body` là đổi thẳng. Canvas đăng ký `@property --user-text-boost` (kiểu `<length>`) để script đọc ra px; script nào của mã thật đọc biến này cũng cần dòng đăng ký đó trong admin-chrome.css |
| `--spacing-sidebar` · `--spacing-sidebar-collapsed` · `--spacing-header` · `--topbar-h` · `--vz-boxed-width` | `--sidebar-width` · `--sidebar-width-collapsed` · `--topbar-height` · `--topbar-height` · `--container-boxed` |
| `data-bs-theme` · `data-scheme` | `data-theme` |
| Tên của ADR-04 (`--space-*`, `--radius-soft/round`, `--shadow-soft/medium`, `--gradient-*`, `--motion-*`, `--color-surface-tint`, `--color-dark`, `--color-on-dark`, `--font-heading/body`) | Giữ nguyên |

Biến làm việc cục bộ trong một thành phần (`--c`, `--bs-fr`, `--focus-inner`, `--sticky-top`…) không phải token. Đổi tên khi chuyển thành phần đó, không cần bí danh.

## 8. Thứ tự làm

1. Tài liệu này.
2. **Code, KHÔNG đổi hình.**
   - Tên mới với đúng giá trị đang chạy: chữ 14px vẫn 14px; không đụng thang có sẵn của Tailwind.
   - Viết lại mọi khối chế độ tối, thanh trên, thanh bên lên tên mới trong cùng commit; tên cũ thành bí danh một chiều.
   - `ThemeResolver` in cả tên mới lẫn tên cũ; `contrast-check` theo tên mới; kiểm chặn tên cũ trong CI.
   - Kiểm: màu và cỡ tính được của các màn, sáng / tối × mọi kiểu thanh, trước/sau giống hệt.
3. **Một nguồn màu thương hiệu cho trang quản trị.**
   - `AdminChromeResolver` tính dải màu bằng PHP; 12 mã màu gắn cứng thành bậc của dải.
   - Rồi đưa tông "Bảo Sơn Hoàng gia" (owner duyệt 01/10) và Focus 1b vào code bằng cách đổi đúng màu nguồn đó.
4. Chuyển từng màn thiết kế sang code theo §3 và §7. Thang cỡ chữ / khoảng cách mới bắt đầu có hiệu lực ở bước này.
5. Sau đợt chạy designbot: lượt Claude Design đổi tên trong bản thiết kế; gỡ bí danh khi grep = 0.

## 9. Câu hỏi cho owner

Chuẩn thiết kế của owner (06–07/10) chặt hơn vài chỗ đã chốt trước đó. Theo luật, chuẩn chặt hơn áp dụng và chỗ khác nhau được báo owner:
- **Màu nhấn thứ hai.**
  - Chuẩn chỉ cho một màu nhấn. Trang quản trị đang có màu phụ `--color-secondary` (#2f69d4); bản thiết kế có `--color-brand-gold` / `-gold-text` (28 chỗ).
  - Đề xuất: màu phụ chỉ dùng cho trạng thái "thông tin"; vàng chỉ làm trang trí của nhận diện. Owner chọn giữ hay bỏ từng màu.
- **Website khách, ADR-04 (12/07):**
  - khoảng cách nhân hệ số mật độ 0,85 / 1,2 (không còn bội số 4);
  - hiệu ứng xuất hiện 0,5 giây (chuẩn cho 150–250ms).
  
  Đề xuất: giữ hệ số mật độ nhưng làm tròn kết quả về bội số 4; hiệu ứng xuất hiện tối đa 250ms và tắt khi máy đặt giảm chuyển động.
