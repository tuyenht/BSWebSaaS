# Prompt cập nhật design system — Tông Bảo Sơn Hoàng gia (3b) + Bộ màu storefront

> Dán nguyên văn vào dự án **BSSaaS AdminKit Design System** (hoặc giao cho dev sửa repo `tuyenht/BSWebSaaS` trước —
> codebase là nguồn gốc). Lớp chuyển tiếp hiện nằm ở `admin-base.css` của project BSWebSaaS; khi DS đã cập nhật
> thì xoá khối "G2" trong file đó.

---

Cập nhật design system theo đợt **G2 — độ rõ & sang** và bổ sung **Bộ màu storefront**. Không đổi cấu trúc component,
chỉ đổi token và tài liệu. Mọi cặp màu phải đạt WCAG 2.2 AA (chữ ≥ 4,5:1, thành phần giao d## 1. Token admin — chế độ sáng (`tokens/colors.css`, `:root`) — tông **Bảo Sơn Hoàng gia** (duyệt 01/10/2026)
Màu lấy từ logo Bảo Sơn: xanh hoàng gia `#2050a0`, vàng `#e0b028`. Bỏ xám than Velzon và không dùng slate lạnh — chữ là **màu mực xanh**.
| Token | Cũ | Mới | Tương phản |
|---|---|---|---|
| `--color-admin-primary` / `--color-accent` | `#405189` | `#2050a0` | 7,7 : 1 (chữ trắng trên nền · chữ trên trắng) |
| `--color-body` (nền trang) | `#f3f3f9` | `#f4f5fa` | — |
| `--color-heading` | `#343a40` | `#1f2b4d` | 13,9 : 1 |
| `--color-body-text` | `#495057` | `#3c4765` | 9,2 : 1 |
| `--color-body-muted` | `#6b6e7f` | `#5b6275` | 6,1 : 1 trắng · 5,5 : 1 nền trang |
| `--color-border` | `#eff2f7` | `#e9ecf4` | trang trí |
| `--color-border-strong` | `#e9ebec` | `#e3e7f0` | viền thẻ |
| `--color-border-input` | `#878a99` | `#8a90a2` | 3,2 : 1 |
| `--color-surface-hover` | `#f3f6f9` | `#f1f3f9` | — |
| `--color-brand-gold` (mới) | — | `#e0b028` | chỉ trang trí / nền dưới chữ đậm (7,7 : 1 với `#212529`) |
| `--color-brand-gold-text` (mới) | — | `#8a6200` | 5,5 : 1 — khi cần chữ vàng |

Thanh bên tối: nền `color-mix(in srgb, var(--brand) 72%, #0b1d45)` (≈ `#1c3c7a`), chữ trắng .86, tiêu đề nhóm trắng .78,
mục đang chọn nền trắng 12% + **vạch trái 3px vàng `#e0b028`** + `aria-current`. Customizer: bảng 6 màu (Đợt C) — Bảo Sơn `#2050a0` mặc định; bỏ Navy, Xanh dương, Mòng két, Tím than.
Chế độ tối giữ nguyên giá trị PR #87.

trị PR #87.

## 2. Typography (xử lý C4 / B9 / B14)
- Nhãn nhỏ (fieldset legend, tiêu đề nhóm sidebar, đầu bảng): bỏ viết HOA + giãn chữ → viết thường đầu câu, 12–13px, đậm 600.
- Thang tiêu đề: page title 20–22px/700, card title 15–16px/600. Line-height ≥ 1.4.

## 3. Bóng & viền
- Thẻ: giữ viền `--color-border-strong` + shadow `0 1px 2px rgba(15,23,42,.06)`; bỏ viền kép thẻ-trong-thẻ.
- Đường kẻ giữa các dòng chỉ dùng trong bảng/danh sách dài; form dùng khoảng trắng để tách nhóm.

## 4. Bộ màu storefront (mới — mục "Storefront brand")
Thay cho việc cho tenant chọn nền/chữ rời. Từ `--primary` (và `--secondary` nếu có) ThemeResolver sinh các bộ:
`light` · `soft` · `brand` · (`accent` nếu có màu phụ) · `dark`. Mỗi bộ xuất đủ biến:
`--sch-bg, --sch-head, --sch-body, --sch-muted, --sch-link, --sch-pri-bg, --sch-pri-fg, --sch-pri-hover,
--sch-sec-fg, --sch-sec-border, --sch-card-bg, --sch-card-head, --sch-card-body, --sch-input-bg,
--sch-input-border, --sch-line, --sch-icon, --sch-focus`.

Luật sinh (đã chạy thử 4.536 phép đo trên 18 màu thương hiệu × 4 kịch bản, 0 lỗi):
- Chữ đậm dùng ink `#0f172a`; **không pha màu thương hiệu với đen** (ra màu bẩn). Sắc nhạt/đậm sinh bằng **OKLCH**.
- Nút trên nền sáng/tối: nền = màu thương hiệu nếu ≥ 3:1 với nền, nếu không thì bản đậm/nhạt cùng sắc (OKLCH) vừa đủ 3:1; chữ nút tự chọn ink/trắng ≥ 4,5:1.
- Nút trên nền thương hiệu: trắng hoặc ink, chọn cái ≥ 3:1 với nền.
- Hover nút phải giữ chữ ≥ 4,5:1.
- Nền ảnh: chữ trắng, lớp phủ tối tự tăng tới khi chữ đạt ≥ 4,5:1 ở vùng sáng nhất.
- Tenant được ghi đè nền/chữ/nút của từng bộ; hệ thống tự chỉnh về mức đạt và báo "đã tự chỉnh".

Implementation tham chiếu: `Page Builder v3.dc.html` — `schemes()`, `rolesFor()`, `okFit()`, `okTone()`, `inkSet()`.

## 4b. Token màu thương hiệu admin (mới — Đợt A, 01/10/2026)
Màu thương hiệu tenant chọn trong Tùy biến không dùng thô. Mỗi màu có 3 token, tra từ **bảng tĩnh** (repo: một mảng hằng, không tính lúc chạy):
- `--brand-solid` — nền dưới chữ trắng (nút, badge, công tắc, topbar đậm, sidebar đậm/gradient). Trắng và trắng .85 ≥ 4.5, kể cả chip phủ trắng .08; vạch vàng `#e0b028` ≥ 3 trên sidebar đậm/gradient.
- `--brand-ink` — chữ, link, viền nhấn, vòng focus. Light: ≥ 4.5 trên `#fff`, `#f4f5fa`, tint brand 10%. Dark: `color-mix(in oklab, brand 38%, #e6eeff)`.
- `--brand` — màu gốc, chỉ trang trí (tint, viền mảnh). **Không dùng cho chữ hoặc nền có chữ.**
- `--color-accent` = `var(--brand-ink, <mặc định>)`, `--color-admin-primary` = `var(--brand-solid, <mặc định>)`, `--focus-ring-color` = `var(--brand-ink, <mặc định>)`; khai lại trên phần tử khung chứa `--brand-*` (biến `var()` ở `:root` được giải tại `:root`).
- Bảng 6 màu (Đợt C, thay bảng 9 màu của Đợt A): Bảo Sơn `#2050a0`→solid/ink giữ gốc · Xanh cổ vịt `#0e7490`→`#00657f`/`#05708c` · Xanh lá `#15803d`→`#056b30`/`#0a7636` · Chàm `#4338ca`, Mận `#9d174d`, Than chì `#334155` giữ gốc. Nguyên tắc: cùng độ đậm (L solid ±0,06 so với Bảo Sơn, trung tính miễn), khác sắc độ, mỗi họ một màu, một màu trung tính; ΔE OKLab mọi cặp ≥ 0,06 (hiện min 0,091). Ô màu hiển thị solid. Tỷ lệ chi tiết: `AUDIT.md` 5.L1-C.
- Ngữ nghĩa: nút nguy hiểm luôn icon + chữ (+ hộp xác nhận nếu không hoàn tác được); chip trạng thái nền nhạt + chữ đậm + icon, nút chính nền đặc — khác hình thức, không chỉ khác màu.
- Vòng focus trên vùng nền đậm (sidebar Đậm/Gradient, topbar Đậm): trắng; popover khai lại `--brand-ink`.
- Cùng nguyên tắc với `--primary-text` của storefront (B10): màu gốc chỉ dùng trực tiếp khi đạt.

## 5. Cập nhật "Nợ UX đã biết"
- Đóng **C4** (nhãn HOA), **B9** (chữ < 12px) ở phía project; ghi rõ repo cần PR tương ứng.
- Sửa `--color-sf-accent` về `#f59e0b` (đang là `#d6a43a`, lệch repo).
