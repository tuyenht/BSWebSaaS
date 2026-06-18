# Admin Architecture Decision Record

> ADR riêng cho **Admin UI** (Livewire 4 + Tailwind 4 + Blade). Bổ sung cho `design.md`.
> Trạng thái: **LOCKED** = đã chốt (không lật) · **OPEN** = việc còn lại.

---

## ADR-A1 — Stack & nguồn thiết kế (LOCKED)

- Admin UI = **Tailwind 4 + Livewire 4 + Blade**, all-in. **KHÔNG Bootstrap**, KHÔNG trộn 2 CSS framework trong 1 project. Builder = React island (Inertia v2) — theo ADR-02.
- **KHÔNG Flux** (lean-deps, own-code). Primitive tự dựng bằng Tailwind + Blade component.
- **Velzon = design reference READ-ONLY**: lấy visual + token, KHÔNG port Bootstrap/jQuery/SCSS, KHÔNG nạp CSS framework thứ 2.
- Icon = inline-SVG `<x-admin::icon>`, KHÔNG nạp icon webfont.

## ADR-A2 — Token SSOT (LOCKED — đã build GĐ1–GĐ3, CI xanh)

- Typography SSOT = `resources/css/fonts.css` (`--font-stack` + `--vz-*`), **self-host Inter, zero third-party CDN**.
- Body **14px** = `--vz-body-font-size`; Tailwind `@theme` đọc từ fonts.css; `bg-body` ← `--vz-body-bg`.
- Brand admin **namespace `--color-admin-*`** (#405189…) — KHÔNG rò sang storefront `--color-primary` (ThemeResolver per-site). Shell scope `.admin-shell`.
- Layout: **Vertical + Light scheme + dark sidebar**. Theme Customizer (Velzon-style: Layout/Scheme/Position/Topbar/Sidebar) = feature **interactive (phase sau)**.

## ADR-A3 — i18n / FONT (LOCKED)

### Fact đính chính
- **An Vinh = vi/en/ja, default vi** (KHÔNG zh-Hans). *Verified (live repo, re-check đầy đủ):* fixture `provisionAnVinh()` = `['vi','en','ja']` (assert `['en','ja','vi']`); `SiteLocale` docblock = "vi + en + ja"; `design.md §10` = vi/en/ja. **Fix đã có sẵn trong live** (snapshot cũ từng là `['vi','zh-Hans']` — đã được sửa trước khi clone). Không test i18n/hreflang nào dựa zh-Hans (machinery hreflang dùng vi+en). zh-Hans còn lại đều hợp lệ: roadmap docs, generic `SiteConfigTest` machinery, comment BCP-47 ví dụ.
- `design.md` "vi+zh-Hans" = **i18n-platform** (tầng nền tảng), KHÔNG phải ngôn ngữ client. Không nhầm 2 tầng.

### Hai trục độc lập
- **Display (chrome UI)** = 4 platform langs: **en/vi/ja/zh**.
- **Content (An Vinh)** = **vi** (P1) → **+en+ja** (P2, copy-gốc-vi → dịch).
- 1 trang CÓ THỂ mix nhiều script (UI ja trong khi sửa content vi…) → thiết kế phải chịu được.

### Font = SCRIPT-DRIVEN (KHÔNG locale-gate)
- Giữ `unicode-range` trong fonts.css.
- **Tách family CJK riêng** (kéo JP/KR/SC ra khỏi family `"Inter"` → đặt tên family riêng).
- `:lang()` chọn font CJK, phủ biến thể:
  - `:lang(ja){ … "Noto Sans JP" … }`
  - `:lang(zh), :lang(zh-Hans), :lang(zh-CN){ … "Noto Sans SC" … }`
  - → khử nhập nhằng Hán (JP + SC chồng dải `U+4E00–9FFF`).
- **Phụ thuộc:** `<html lang=display>` + bọc mỗi khối content dịch bằng `lang=content_locale`.

### Font scope
| Font | Dùng cho |
|---|---|
| **Inter** | en/vi (UI + content) |
| **Noto Sans JP** | ja — UI **+** An Vinh-P2 content |
| **Noto Sans SC** | zh — **chỉ UI**, KHÔNG content |
| **Noto Sans KR** | **HOÃN** (không display, không content) |

- SC + JP serve từ **R2/CDN, KHÔNG commit binary**; `font-display:optional` + preload-display-font + CORS.
- Hiện tại: **chỉ Inter self-host** (`public/fonts/`), CJK rơi system-fallback.

### Switcher (OPEN — chốt khi build)
- Reference lệch: dashboard dùng **tên bản ngữ** (日本語/中文) vs login dùng **mã** (EN/VI/JA/ZH).
- **Khuyến nghị:** tên bản ngữ (endonym) — `Tiếng Việt / English / 日本語 / 中文` (chuẩn UX switcher). Chốt khi build auth/shell.

### Thời điểm (OPEN — hoãn sâu)
- CJK chỉ cần khi UI ja/zh bật functional **HOẶC** An Vinh P2 (ja content).
- **Phase 1 + visual-review = vi thuần, Inter-only, 0 dòng CJK.** KHÔNG đụng refactor family / `:lang()` / R2-font lúc này.

---

## OPEN ITEMS
- [ ] **CJK self-host refactor** (khi UI ja/zh functional / An Vinh P2): tách family CJK + `:lang()` font-family + serve R2 theo ADR-A3.
- [ ] **fonts.css comment** hiện ghi "An Vinh storefront zh-Hans" → SAI theo ADR-A3 (An Vinh không có zh content; zh = UI-only). Sửa comment khi đụng fonts.
- [ ] **CI gate** (GĐ7): thêm `npm ci && npm run build` (hiện CI chỉ pint/phpstan/pest — không build frontend → CSS/Vite không được verify).
- [ ] **Switcher style** — chốt khi build (rec: endonym).
- [x] **Seed An Vinh zh-Hans** — live repo ĐÃ fix sẵn (`provisionAnVinh()`=vi/en/ja; docblock + §10 đồng bộ). Không cần sửa provisioning. (Lần check trước của tôi phán "no bug" là do verify thiếu — may đúng kết quả vì live đã fix.)
