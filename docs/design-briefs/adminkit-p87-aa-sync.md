# Prompt đồng bộ AdminKit theo PR #87 (dán vào khung chat của design system BSSaaS AdminKit)

> Prompt tự chứa — không tham chiếu tin nhắn nào trước. Dán nguyên văn phần trong khung dưới vào
> chat của design system. Giá trị token lấy từ code đã merge; nguồn sự thật:
> GitHub `tuyenht/BSWebSaaS@main` sau PR #87 (`resources/css/app.css`, `resources/css/admin-chrome.css`,
> `app/Modules/Admin/resources/views/components/*.blade.php`, `.../layouts/app.blade.php`,
> `resources/css/storefront.css`, `app/Modules/Theme/Theming/ReadableColor.php`).

---

Cập nhật design system BSSaaS AdminKit cho khớp bản Blade sau PR #87 của repo `tuyenht/BSWebSaaS@main`
(đợt vá tương phản/focus WCAG 2.2 AA). KHÔNG đổi tên component hay tên prop; chỉ đổi giá trị token và
cách các component dùng token. KHÔNG đụng cảnh báo "17 components" (đó là cảnh báo đã biết, ghi trong
CLAUDE.md của design system) — bỏ qua.

## 1. Token màu — thêm/đổi các bậc AA (nguồn: app.css @theme + admin-chrome.css)

Chế độ SÁNG (:root):
- `--color-body-muted: #6b6e7f` (chữ phụ có nghĩa — breadcrumb, footer, tab chưa chọn, đầu cột; 5.04:1 trên trắng, 4.56:1 trên nền trang #f3f3f9).
- `--color-body-faint: #adb5bd` — CHỈ còn cho chi tiết trang trí, KHÔNG dùng cho chữ có nghĩa.
- `--color-accent: #405189` — navy khi làm CHỮ / VIỀN / VÒNG FOCUS (không phải nền). Navy làm NỀN dưới chữ trắng vẫn là `--color-admin-primary: #405189`.
- Màu ngữ nghĩa, mỗi màu 2 bậc mới:
  - `--color-admin-success-text: #047767` · `--color-admin-success-solid: #038573`
  - `--color-admin-info-text: #026fa2` · `--color-admin-info-solid: #057db6`
  - `--color-admin-warning-text: #906405` · (warning KHÔNG có -solid: nút/badge solid giữ nền `#f7b84b` + chữ `#212529`)
  - `--color-admin-danger-text: #bf371a` · `--color-admin-danger-solid: #d0472b`
- `--color-border-input: #878a99` (viền ô nhập; ≥3:1 trên trắng và trên nền trang).
- Focus: `--focus-ring-width: 2px` · `--focus-ring-offset: 2px` · `--focus-ring-color: var(--color-accent)`.

Chế độ TỐI (:root[data-bs-theme="dark"]) — lật các bậc trên:
- `--color-body-muted: #98a0ae` · `--color-accent: #96a1c0` (navy-as-ink 5.32:1 trên nền tối #292e33).
- `--color-admin-success-text: #0db49d` · `--color-admin-info-text: #39a8e8` · `--color-admin-warning-text: #f7b84b` · `--color-admin-danger-text: #ff7355`.
- `--color-border-input: #71777f`.
- Bậc `-solid` giữ nguyên giá trị ở cả hai chế độ (nền dưới chữ trắng).

Quy tắc dùng: chữ/viền/kiểu soft của một màu ngữ nghĩa = bậc `-text`; nền đặc dưới chữ trắng = bậc `-solid`
(warning solid = nền `#f7b84b` + chữ `#212529`). Fill mềm (tint 10%) và chấm trạng thái vẫn dùng màu gốc.

## 2. Component React — khớp Blade sau #87 (giữ nguyên tên + props)

- **Button**: solid primary = nền `--color-admin-primary` + chữ trắng; solid success/info/danger = nền `*-solid` + chữ trắng; solid warning = nền `--color-admin-warning` + chữ `--color-admin-dark` (#212529). Soft = `tint/10` + chữ `*-text` (primary → `accent`), hover đổi sang nền đặc + chữ trắng. Outline = viền + chữ `*-text`/`accent`. Ghost = chữ `*-text`/`accent`. BỎ vòng focus riêng từng màu — focus dùng vòng chung (xem mục 3).
- **Badge**: solid = `*-solid` + trắng (warning = nền gốc + chữ #212529); soft/subtle = `tint/10` + `*-text` (primary → `accent`).
- **Alert**: soft = `tint/10` + `*-text` (primary → `accent`). Nút đóng: dùng đúng màu chữ (KHÔNG opacity 70%), hover nền `currentColor/10`.
- **Input**: viền = `--color-border-input`; focus = viền `accent` + vòng focus chung; BỎ `outline:none` không kèm ring; disabled giữ như cũ.
- **Tabs**: tab đang chọn = chữ `accent` + gạch chân `accent`; tab chưa chọn = `--color-body-muted`; focus dùng vòng chung.
- **Modal**: nút đóng = `--color-body-muted` (không phải màu placeholder), hover → màu chữ thân; focus vòng chung.
- **Sidebar (Admin Console shell)**:
  - Chữ mục thường = trắng .85 (`rgb(255 255 255 / .85)`, 6.05:1 trên navy #405189).
  - MỤC ĐANG CHỌN = chữ trắng + nền active (`rgb(255 255 255 / .15)`) + **vạch chỉ báo 3px bên trái** (inset shadow màu chữ-active) + `aria-current="page"` trên link (nút mở nhóm KHÔNG gắn aria-current nhưng vẫn hiện nền+vạch để rail thu gọn thấy vị trí). Phân biệt bằng HÌNH, không chỉ bằng màu.
  - Sidebar sáng: tiêu đề nhóm = `#6c6f80` (4.97:1).
  - Gradient (làm tối điểm sáng, chữ trắng .85 đạt ≥4.5 ở mọi điểm): gradient `#405189→#056d5e` · gradient-2 `#036797→#185ad2` · gradient-3 `#036797→#056d5e` · gradient-4 `#1a1d21→#405189`.

## 3. Vòng focus chung

Một vòng focus cho toàn admin: `outline: 2px solid var(--focus-ring-color); outline-offset: 2px` áp `:focus-visible`.
`--focus-ring-color` = accent (navy sáng / #96a1c0 tối). Vùng nền đậm đổi màu vòng theo chữ của vùng
(sidebar → trắng; header Customizer nền navy → chữ trắng). Component không tự tắt outline.

## 4. Storefront (nếu design system có phần storefront)

Chữ màu thương hiệu trên nền sáng = `--primary-text` (được làm tối từ `--primary`, giữ hue, tới khi ≥4.5:1
trên nền thẻ `--surface` VÀ trên nền trang `color-mix(in srgb, --surface 97%, --ink)`). `--primary` giữ cho
nền/viền và cho chữ trên nền tối (header/hero/footer).

## 5. README — mục "Nợ UX đã biết"

Đánh dấu **ĐÃ XỬ LÝ (PR #87)** cho các mục sau, ghi rõ #87 làm gì:
- **B1** (dark mode primary làm chữ/viền/focus) — navy-as-ink chuyển role `accent`, tối #96a1c0.
- **B2** (chữ phụ) — `--color-body-muted` #6b6e7f; faint chỉ trang trí.
- **B3** (màu ngữ nghĩa) — bậc `-text`/`-solid`; warning solid chữ #212529.
- **B4** (viền ô nhập) — `--color-border-input`.
- **B5** (focus) — token vòng focus + `:focus-visible` toàn cục + scroll-padding-top.
- **B10** (storefront) — `--primary-text` đạt trên thẻ và nền trang.
- **B12 / C5** (sidebar) — chữ trắng .85; mục đang chọn nền + vạch trái + aria-current.
- **B20 / C6** (gradient sidebar) — làm tối điểm sáng, chữ trắng .85 đạt mọi điểm.
- **B8 (phần nút đóng)** — nút đóng modal ≥3:1 (chỉ phần nút đóng; các phần khác của B8 nếu có thì giữ trạng thái cũ).
- Sửa **A1 (dark mode)** theo hiện trạng: bộ component + shell trong dark mode giờ AA sạch (accent, bậc ngữ nghĩa, sidebar, focus đều đạt). Độ phủ ở TỪNG MÀN nghiệp vụ được xử lý tiếp phía repo ứng dụng (P1.5 đã làm cho màu ngữ nghĩa; P1.6 cho chữ faint + outline-none) — ghi chú ngắn để không hiểu nhầm là "toàn bộ mọi màn đã xong".

## 6. docs/ux-audit-2026-09-24.md

Thêm mục **"Tiến độ"** ở CUỐI file: liệt kê các mục đã xử lý ở PR #87 (B1, B2, B3, B4, B5, B10, B12, B20, C5, C6 và phần nút đóng của B8), kèm 1 dòng cho A1 như trên. KHÔNG sửa nội dung audit gốc phía trên; chỉ thêm mục tiến độ.

## 7. KHÔNG làm

- KHÔNG đổi tên component/prop.
- KHÔNG đụng cảnh báo "17 components" (đã biết).
- KHÔNG đổi bố cục, cỡ chữ, kích thước — chỉ màu/tương phản/focus.

Xong thì chạy phần kiểm của design system và báo lại các mục đã cập nhật.
