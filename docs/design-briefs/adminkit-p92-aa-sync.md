# Prompt đồng bộ AdminKit theo PR #92 (dán vào khung chat của design system BSSaaS AdminKit)

> Prompt tự chứa — không tham chiếu tin nhắn nào trước. Dán nguyên văn phần dưới vào chat của design
> system. Giá trị token lấy từ code đã merge; nguồn sự thật: GitHub `tuyenht/BSWebSaaS@main` sau PR #92
> (`resources/css/app.css`, `resources/css/admin-chrome.css`, `resources/css/storefront.css`,
> `app/Modules/Admin/resources/views/components/{button,badge}.blade.php`,
> `app/Modules/Theme/Theming/ThemeResolver.php`). Đây là đợt đóng nốt AA (P1.7) sau #87/#89/#91:
> chỉ MÀU / TƯƠNG PHẢN / FOCUS — không đổi bố cục, cỡ chữ, kích thước.

---

Cập nhật design system BSSaaS AdminKit cho khớp bản Blade sau PR #92 của repo `tuyenht/BSWebSaaS@main`
(đóng nốt vá tương phản WCAG 2.2 AA cho các bậc còn thiếu). KHÔNG đổi tên component hay tên prop; chỉ đổi
giá trị token và cách component dùng token. KHÔNG đụng cảnh báo "17 components" (đã biết) — bỏ qua.

## 1. Token màu — 4 token MỚI + 5 token ĐỔI giá trị (nguồn: app.css + admin-chrome.css + storefront.css)

**Thêm mới (4):**
- `--color-admin-secondary-solid`: SÁNG `#2f69d4` · TỐI `#2f69d4` — nền đặc dưới chữ trắng (chữ trắng 5.13:1). (Base `--color-admin-secondary: #3577f1` GIỮ NGUYÊN cho chấm trạng thái / viền trang trí — chữ trắng trên #3577f1 chỉ 4.14, KHÔNG dùng làm nền chữ.)
- `--color-admin-secondary-text`: SÁNG `#2f69d4` (đúng bằng -solid) · TỐI `#6e9df5` — chữ / soft / outline (5.13 trên trắng · 4.64 trên nền trang · 4.53 trên tint /10 ở sáng; 5.09 / 4.58 ở tối).
- `--color-admin-dark-text`: SÁNG `#212529` · TỐI `#dce0e6` — badge/soft "dark" khi làm CHỮ (ở tối, #212529 trên nền tint tối chỉ 1.11 → phải lật sang `#dce0e6` = 10.48).
- `--primary-on-ink` (storefront, dẫn xuất per-site trong `ThemeResolver::cssVariables()`): brand được LÀM SÁNG (giữ hue, qua `ReadableColor::onAll`) tới ≥4.5:1 trên `--ink`; brand đã đủ sáng (vd amber `#f59e0b` = 8.31 trên ink) giữ nguyên. Bổ trợ cho `--primary-text` (chữ brand trên nền SÁNG của #87): đây là chữ brand trên nền TỐI `--ink` (link NAP footer + hover, menu con hover, CTA phụ hero).

**Đổi giá trị (5) — ưu tiên DÙNG LẠI `--color-body-muted` = #6b6e7f sáng / #98a0ae tối:**
| Token | Cũ | Mới SÁNG | Mới TỐI | Tỉ lệ mới |
|---|---|---|---|---|
| `--color-placeholder` | #ced4da / #98a0ae | `var(--color-body-muted)` #6b6e7f | `var(--color-body-muted)` #98a0ae | 4.56 / 5.20 |
| `--vz-header-item-sub-color` (topbar sáng+tối) | #878a99 | `var(--color-body-muted)` #6b6e7f | `var(--color-body-muted)` #98a0ae | 5.04 / 5.20 |
| `--vz-header-item-sub-color` (topbar navy · `data-topbar='dark'`) | #b0c4d9 | `#bccdde` | `#bccdde` | 4.68 trên #405189 |
| `--vz-vertical-menu-title-color` (sidebar navy mặc định) | `rgb(255 255 255 / .60)` | `rgb(255 255 255 / .72)` | — | 4.85 trên #405189 |
| `--vz-vertical-menu-title-color` (sidebar tối · scheme dark) | #7d8294 | — | `var(--color-body-muted)` #98a0ae | 5.85 trên #212529 |

Quy ước: placeholder là CHỮ → phải ≥4.5 (dùng bậc muted, không dùng gray nhạt). Chữ đã gõ trong ô giữ `#495057`.

## 2. Component — khớp Blade sau #92 (giữ nguyên tên + props)

- **Button — secondary** (khớp mẫu success/info/danger đã làm ở #87): solid = nền `--color-admin-secondary-solid` #2f69d4 + chữ trắng; soft = `tint /10` + chữ `--color-admin-secondary-text`, hover → nền `-solid` + chữ trắng; outline = viền + chữ `-text`, hover → nền `-solid` + chữ trắng. KHÔNG vòng focus riêng — dùng vòng focus chung của #87.
- **Badge**: secondary solid = `--color-admin-secondary-solid` + trắng; secondary subtle/soft = `tint /10` + `--color-admin-secondary-text`. Badge **"dark" soft** = chữ `--color-admin-dark-text` (#212529 ở chế độ sáng, trên nền tint sáng / #dce0e6 ở chế độ tối, trên nền tint tối).
- **Avatar** (chữ viết tắt / initials): màu chữ = `--color-accent` (token của #87: #405189 sáng / #96a1c0 tối). Design system có thể ĐÃ đổi Avatar sang accent từ lần đồng bộ trước (ghi ở mục "Design system đã sửa, code chưa sửa") — nếu đã đúng thì chỉ xác nhận, không sửa lại.
- **Input**: `placeholder` màu = `--color-placeholder` (= `--color-body-muted`). **Ghi vào tài liệu Input một quy ước:** placeholder CHỈ chứa VÍ DỤ (vd "0912 345 678"), KHÔNG nhồi hướng dẫn; hướng dẫn/điều kiện đặt ở DÒNG GỢI Ý dưới ô (hint/help text), không đặt trong placeholder. (Lý do: placeholder biến mất khi gõ và độ tương phản thấp hơn chữ thường.)

- **Kit storefront** (`ui_kits/storefront/storefront.css`): thêm vào khối `:root` của kit `--primary-on-ink: #f59e0b` (giá trị ThemeResolver trả cho An Vinh — amber đã đạt 8.31 trên `--ink`, giữ nguyên) và chuyển các chữ màu brand TRÊN NỀN TỐI `--ink` (hover menu con, `.hero__cta--secondary`, `.site-footer__nap a` và hover) từ `var(--primary)` sang `var(--primary-on-ink)`. Ghi chú đầu file: giá trị lấy theo kết quả ThemeResolver của repo, không tự tính. Không đổi gì khác trong file.

## 3. Khung Admin Console (shell)

- **Dòng phụ topbar** (vai trò dưới tên người dùng + link điều hướng platform, và màu icon-only ở topbar): sáng/tối = `--color-body-muted` (#6b6e7f / #98a0ae); topbar navy (`data-topbar='dark'`) = `#bccdde` (4.68).
- **Tiêu đề nhóm sidebar**: sidebar navy (mặc định) = trắng .72 (`rgb(255 255 255 / .72)`, 4.85 trên #405189); sidebar tối (scheme dark) = `#98a0ae` (= body-muted, 5.85 trên #212529). (Sidebar SÁNG giữ `#6c6f80` như #87.)
- **Mục đang chọn của settings-shell** (menu dọc trong trang Cài đặt) = chữ `--color-accent`. **CHỈ sửa nếu design system ĐÃ có phần settings-shell**; nếu design system CHƯA có phần này thì BỎ QUA — KHÔNG tạo component mới.

## 4. README — mục "Nợ UX đã biết" (đối chiếu THEO NỘI DUNG, không theo số thứ tự — số trong audit có thể khác)

Đánh dấu trạng thái đúng theo nội dung từng mục:
- **Placeholder ô nhập ~1.49** → **ĐÃ XỬ LÝ (#92)** (placeholder = `--color-placeholder` = body-muted, 4.56/5.20).
- **Nút secondary solid, chữ trắng ~4.14** → **ĐÃ XỬ LÝ (#92)** (nền `--color-admin-secondary-solid` #2f69d4, 5.13).
- **Topbar dòng vai trò / link platform ~3.42–4.00** → **ĐÃ XỬ LÝ (#92)** (body-muted; navy #bccdde).
- **Chế độ TỐI: avatar và settings-shell ~1.69; badge "dark" soft ~1.11** → **ĐÃ XỬ LÝ (#92)** (avatar/settings-shell → accent #96a1c0 = 5.02; badge dark → `--color-admin-dark-text` #dce0e6 = 10.48).
- **Sidebar TỐI: tiêu đề nhóm ~3.90–4.03** → **ĐÃ XỬ LÝ (#92)** (navy .72 = 4.85; sidebar tối = body-muted 5.85).
- **Ảnh nền sidebar + gradient ~4.0** → **CÒN MỞ.** Đo lại (25/09): lớp ảnh nền là texture 7% opacity (z-index:-1) mà `scripts/contrast-check.mjs` KHÔNG mô hình hóa (nó chỉ gate dải gradient, không có ảnh). Khi CÓ ảnh sáng, MỤC ĐANG CHỌN ở điểm sáng nhất của dải tụt: worst-case (điểm ảnh trắng) ~4.01–4.02 ở gradient 1/2/3 và 4.49 ở gradient-4; thực tế với ảnh sáng nhất (img-3) ~4.36–4.37 ở gradient 1/2/3. KHÔNG phải mọi điểm ≥4.5 ⇒ chưa đánh ĐÃ XỬ LÝ. (Chữ thường white/.85 cũng dưới ngưỡng ở trường hợp xấu nhất: ~4.3 bound / ~4.7 thực tế với img-3.) Mục đang chọn vẫn phân biệt bằng nền active + vạch trái + `aria-current` — không chỉ bằng màu. Hướng xử lý tương lai (chưa làm): giảm opacity ảnh, làm tối thêm dải, hoặc cho contrast-check gate cả lớp ảnh.
- **View còn dùng faint làm chữ / màu ngữ nghĩa thô / navy làm chữ-viền / `outline-none` không kèm ring** → **ĐÃ XỬ LÝ (#89 màu ngữ nghĩa, #91 faint + outline-none, #92 navy→accent + faint triệt để).**
- **Cỡ chữ và kích thước (các mục cỡ-chữ/kích-thước trong audit — vd nhóm C4, B6, B9, B14)** → **CÒN MỞ, chờ đợt G2** (P1.7 không đổi cỡ chữ/kích thước).
- **"Design system đã sửa nhưng code chưa sửa" (avatar, settings-shell active)** → **ĐÃ XỬ LÝ (#92) — code đã khớp** (avatar + settings-shell active dùng accent ở cả hai chế độ).
- **Thêm mục mới vào README:**
  - **Cột "Hành động" chỉ có icon, không có chữ** → CÒN MỞ, ưu tiên P3 (thêm nhãn/aria-label; không đổi bố cục).
  - **Nợ nhãn a11y (axe)**: 6 mục / 46 phần tử trên 4 màn × 2 chế độ — `button-name` (nút icon topbar), `label` (ô sửa-tại-dòng danh sách + mật khẩu SMTP), `select-name` (select mã hóa Email). Không phải màu/focus; xử lý ở đợt nhãn riêng.

## 5. docs/ux-audit-2026-09-24.md (trong repo design system)

Thêm MỘT dòng vào mục **"Tiến độ"**: các PR #89, #91, #92 đã xử lý những mục nào (đối chiếu theo nội dung như mục 4 ở trên) —
> #89: màu ngữ nghĩa thô trong các màn nghiệp vụ. #91: chữ faint làm chữ + `outline-none` không kèm ring. #92: placeholder, nút/badge secondary, dòng phụ topbar, chế độ tối (avatar, settings-shell, badge "dark"), tiêu đề nhóm sidebar tối. CÒN MỞ: cỡ chữ/kích thước (G2), ảnh nền sidebar + gradient (~4.0 ở mục đang chọn), cột Hành động chỉ-icon, nợ nhãn axe.

KHÔNG sửa nội dung audit gốc phía trên; chỉ thêm dòng tiến độ.

## 6. KHÔNG làm

- KHÔNG xử lý cảnh báo "17 components" (đã biết).
- KHÔNG đổi tên component hay tên prop.
- KHÔNG đổi bố cục, cỡ chữ, kích thước — chỉ màu / tương phản / focus.
- KHÔNG tạo component mới cho settings-shell nếu design system chưa có phần đó.

Xong thì liệt kê TỪNG FILE đã sửa và KHÔNG làm gì thêm.
