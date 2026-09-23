# Section Style System — Style-props token hoá + Preset Registry (ADR-04 mở rộng, v2.15)

> **Trạng thái:** CHỐT 12/07/2026 (owner duyệt sau 2 council hội tụ: audit ADR-04 style-props + audit catalog builder v3 `plans/reports/council-audit-260712-0001-builder-v3-section-catalog-report.md`).
> **Normative:** tài liệu này là spec đầy đủ cho dòng ADR-04 "Chốt (mở rộng)" + design.md §19/§E7 (changelog v2.15). Khi mâu thuẫn: design.md thắng về NGUYÊN TẮC, file này thắng về CHI TIẾT.
> **Grounded as-built @ main:** `ComponentRegistry` · `SectionComponent` · `ValidPageJson` · `BlockRenderer` · `ThemeResolver` · `Editor` (Livewire) · `themes/default/` · `resources/css/storefront.css`.
> **Bất biến tối cao (ADR-04):** trình bày = token/enum allow-list do PLATFORM đăng ký — **CẤM raw CSS/class tự nhập ở MỌI tầng tenant** (Page-JSON, menu meta, mọi chỗ khác).

---

## 0. Kiến trúc 4 tầng + LUẬT THĂNG CẤP

"Phong phú" đạt bằng **nhân bản tổ hợp** (~18 trục × token per-site → hàng nghìn tổ hợp AA-safe/CWV-safe), KHÔNG bằng knob tự do.

| Nhu cầu trình bày | Vào tầng | Ví dụ |
|---|---|---|
| Áp toàn site, đổi 1 chỗ đổi mọi nơi | **Token site-level** (§1) | màu brand, cặp font, mật độ khoảng cách |
| Trục mà đa số section cần, trực giao nội dung | **Base style-prop** (§2) | nền, khoảng thở, khung, ẩn/hiện, chuyển động |
| Thay đổi CẤU TRÚC markup của riêng 1 loại | **`variant` per-section** | hero split vs minimal; grid vs carousel; hero full-screen |
| Tổ hợp thẩm mỹ đã vet sẵn | **Preset** (§3) | "Ảnh tràn tối", "Đậm thương hiệu" |
| Hiệu ứng cộng thêm hiếm dùng | **`extra_style`** (§4) | glass, pattern nền |
| Cả trang | **Template layer** (Đợt 1c, council catalog F2) | mẫu trang theo ngành |

**Luật:** prop mới muốn vào base = COUNCIL (chống phình knob — cùng tinh thần luật "tốt nghiệp" meta JSONB design.md §3). Giá trị enum mới trong prop sẵn có = **append-only**, thêm được không cần council; **bỏ/đổi tên giá trị = việc-có-kế-hoạch** (backfill body — như D13 varchar+CHECK).

---

## 1. TẦNG 0 — Theme token (Đợt 1a; mọi utility class tiêu thụ từ đây)

| Nhóm | Token | Ghi chú |
|---|---|---|
| Màu | `--brand-50..900` (palette generator từ **1 màu brand** — §E7 đã đặc tả) + vai trò `--color-surface / surface-tint / dark / on-dark / text / text-muted / accent` | Chữ trên nền tối/ảnh **tự đổi theo class nền** (CSS đi kèm `sec--bg-dark/image`) — KHÔNG có prop chọn màu chữ |
| Chữ | `--font-heading` + `--font-body` (nâng từ 1 `--font` hiện tại) + scale modular h1–h4/body/small | Font chọn từ danh sách Việt-subset — KHÔNG upload font tự do |
| Khoảng cách | thang `--space-s/m/l/xl` × hệ số mật độ `--space-unit` (compact 0.85 · normal 1 · airy 1.2) | 1 cài đặt site đổi "độ thở" mọi section |
| Bo & bóng | `--radius-soft/round` · `--shadow-soft/medium` | |
| Gradient | `--gradient-1..3` sinh từ brand (brand→đậm · brand→accent · dark→brand) | |
| Motion | `--motion-duration` (0.5s) · `--motion-distance` (24px) | 1 công tắc site tắt toàn bộ motion |

**Hai tầng token (chốt 23/09/2026 — khớp as-built `ThemeResolver`):**
- **Seed = giá trị LƯU per-site** (`site_settings` group `theme` key `tokens`) — contract bền: đổi tên seed = migrate data mọi tenant ⇒ tên giữ nguyên as-built: `primary` (màu thương hiệu — nguồn duy nhất của `--brand-50..900` + gradient) · `surface` (nền) · `ink` (gần-đen: heading, nền tối header/footer, chữ đặt trên nền `primary`) · `body` (màu chữ nội dung dài, mềm hơn `ink`) · `font` (fallback cho tới khi thêm cặp seed `font-heading`/`font-body` — additive). Seed do operator/seeder hoặc panel "Giao diện" (5 quyết định bên dưới) ghi; KHÔNG phải knob per-section.
- **Role = tên trong bảng trên** (`--color-*`, `--brand-*`, `--space-*`, `--radius-*`…) — CSS nội bộ SINH từ seed (`color-mix()` / palette generator) ⇒ đổi tên tự do, KHÔNG đụng data. Utility class + preset CHỈ tiêu thụ role, không đọc seed trực tiếp (`storefront.css` hiện còn đọc seed trực tiếp — chuyển sang role trong PR 1a.1 phần còn lại).
- Thêm seed = key + default ở `ThemeResolver::DEFAULTS` + **tự contrast-check với seed đã có** (bài học 23/09: default `primary` phải đạt AA khi `ink` nằm trên nó). `text` (default cũ, trùng `ink`, chỉ còn `welcome.blade.php` dùng) → gỡ ở PR 1a.1 phần còn lại.

**Cài đặt site-level mở cho tenant (panel "Giao diện" — ngôn ngữ đời thường §24, 5 quyết định):** màu thương hiệu (1 màu → cả palette) · cặp font · mật độ (thoáng/vừa/gọn) · kiểu bo mặc định · bật/tắt hiệu ứng chuyển động. Lưu `site_settings` group `theme` (đường `ThemeResolver` sẵn có; value validate allow-list `Rule::in` — đây là chỗ Rule::in đúng nghĩa, KHÔNG phải trong body).

**Trả nợ white-label:** ~~`storefront.css` hardcode palette An Vinh~~ — **phần MÀU đã xong 23/09** (`5f19003`+`4941016`: 15 hex/101 lần → `var()`/`color-mix()` từ seed; An Vinh giữ diện mạo nhờ seed `{primary,ink,body}` trên prod). CÒN của PR 1a.1: palette generator `--brand-50..900` + thang spacing/radius/shadow/gradient + chuyển CSS sang tầng role.

---

## 2. TẦNG 1 — Style-props per section

### 2.1 Bảng prop đầy đủ (18 tên reserved từ ngày 0; Đợt 1a build 12)

| # | Prop | Giá trị (enum trừ khi ghi khác) | Default | Đợt | Ràng buộc đã chốt |
|---|---|---|---|---|---|
| 1 | `bg` | `none · surface · tint · dark · brand · brand-soft · image · gradient-1 · gradient-2 · gradient-3` | `none` | **1a** | `image` render `<img>` + width/height, KHÔNG CSS-background (LCP); chữ tự đổi theo nền |
| 2 | `bg_image` | media_id (§E7 `image`, int>0) | null | **1a** | đi qua batch-resolve 2-pass sẵn có của BlockRenderer; chỉ hiệu lực khi `bg=image` (render fail-safe bỏ qua nếu lệch) |
| 3 | `overlay` | `0 · 20 · 40 · 60` | `0` | **1a** | chỉ hiệu lực với `bg=image`; AA đảm bảo qua preset + text-scrim tối thiểu của theme |
| 4 | `spacing_top` | `none · s · m · l · xl` | `m` | **1a** | theo thang token × mật độ site |
| 5 | `spacing_bottom` | `none · s · m · l · xl` | `m` | **1a** | |
| 6 | `width` | `contained · narrow · full` | `contained` | **1a** | wrapper mang bg full-bleed, container trong mang width |
| 7 | `align` | `left · center` | `left` | **1a** | section không áp dụng thì render bỏ qua (editor ẩn dần theo hint — UX polish sau) |
| 8 | `corner` | `none · soft` (Đợt 2 +`round`) | `none` | **1a** | |
| 9 | `shadow` | `none · soft` (Đợt 2 +`medium`) | `none` | **1a** | |
| 10 | `hide_on` | `none · mobile · desktop` | `none` | **1a** | CSS-only qua media query; nội dung VẪN trong DOM/index — label editor ghi trung thực; KHÔNG dùng cho nội dung khác nhau per device |
| 11 | `anchor_id` | text, `^[a-z0-9-]{1,64}$` | null | **1a** | sanitize KHI GHI (ValidPageJson) + `e()` KHI RENDER (2 lớp); editor warn trùng id trong trang (không block) |
| 12 | `motion` | `none · fade · rise` (Đợt 2 +`zoom`) | `none` | **1a** | transform/opacity-only (0 CLS); **renderer tự strip ở block đầu** (LCP); gói `prefers-reduced-motion`; trigger = IO module trong `sections.js` (≤6KB foundation, Đợt 1a council catalog); fail-safe: JS không tải → hiện tĩnh (CSS chỉ ẩn dưới `html.js`) |
| 13 | `overlay_tone` | `dark · brand` | `dark` | 2 | overlay màu thương hiệu — look đặc trưng |
| 14 | `divider_top` | `none · slant · wave · curve` | `none` | 2 | SVG shape divider từ theme (inline/mask), height cố định = 0 CLS |
| 15 | `divider_bottom` | `none · slant · wave · curve` | `none` | 2 | |
| 16 | `border` | `none · line · accent-top` | `none` | 2 | chuẩn hóa look "accent section border" An Vinh đang có |
| 17 | `motion_stagger` | `off · on` | `off` | 2 | item con (features/team/stats…) hiện lần lượt — animation-delay CSS, cùng IO trigger |
| 18 | `extra_style` | `list(enum)` — §4 | `[]` | 2 | CHỈ class đã đăng ký trong theme; contract chốt ngay, build Đợt 2 |

**Reserved-names:** cả 18 tên bị cấm trong schema content của mọi section (drift-sentinel test — cùng lệ sink-test `PageJsonValidationTest`). Đặt chỗ đủ 18 NGAY từ Đợt 1a dù build 12.

### 2.2 Cơ chế kỹ thuật (đã chốt qua council — lý do trong report)

- **Khai báo:** style-props = **1 fragment §E7 dùng chung** (`App\Modules\Theme\Components\StyleProps::SCHEMA`, spec đánh dấu `group: style`), merge vào schema mọi type tại **`ComponentRegistry::schemaFor(type)`** — choke point duy nhất (3 consumer đã cầm registry: ValidPageJson / BlockRenderer / Editor). KHÔNG merge ở `SectionComponent` (4 component data-bound implement `Component` trực tiếp sẽ bị sót).
- **Validate:** leaf mới trong walk §E7 của `ValidPageJson`: `enum` (giá trị ∉ values → reject) + `list(enum)`. KHÔNG Rule::in trong body (không với tới). Nhắc: prop ngoài schema hiện PASS im lặng → style phải nằm TRONG schema mới được validate.
- **Render:** wrapper (1 chỗ trong `BlockRenderer`, CSS-safe — đã kiểm 0 direct-child selector, `main:has(.hero)` là descendant) đọc style props qua **bảng tra token→class fail-closed** (match có default; giá trị lạ trong body cũ/đường ghi khác → default, KHÔNG echo). **KHÔNG BAO GIỜ nội suy chuỗi đã lưu vào `class`/`style`.**
- **Ảnh nền:** `bg_image` hưởng batch 2-pass sẵn có (1 query media/trang — giữ contract anti-N+1).
- **Eager/lazy theo VỊ TRÍ:** BlockRenderer truyền index → chỉ block đầu eager/fetchpriority (bỏ hardcode eager theo type ở `about.blade.php`).
- **Storage:** editor lưu **sparse** (prop = default thì bỏ) → không phình trần 100 block/256KB.
- **i18n:** body per-locale ⇒ style theo locale (bản chất mô hình body phẳng, chấp nhận); tiện ích "chép bố cục từ locale mặc định" = backlog editor.
- **Mọi đường ghi qua CÙNG rule** (editor người + AI-gen ADR-08 + import — bài học ADR-19): style enums hưởng miễn phí khi nằm trong ValidPageJson.

---

## 3. TẦNG 2 — Preset Registry (điểm "Flatsome-like")

- **Vị trí:** `App\Modules\Theme\Components\SectionPresetRegistry` — **CODE registry platform-writable-only** (lệ role-template §15.2 — "CODE REGISTRY keyed by site_type, KHÔNG bảng"). Tenant READ-ONLY **by construction** (không tồn tại đường ghi). KHÔNG bảng DB: preset tham chiếu token/class → phải deploy atomic cùng theme CSS; DB-hóa chỉ khi tenant được tự tạo preset (không phải mô hình sản phẩm — platform-curated).
- **Shape:** `for(string $type): array<presetKey, {label: {vi,en,ja}, variant?: string, style: array<prop, value>}>` — 3–6 preset/loại.
- **Áp preset = expand-on-apply:** editor expand bundle thành props rồi lưu Page-JSON — **KHÔNG lưu tên preset** → platform đổi/xóa preset không phá trang cũ; preset chỉ là "bút" ở editor.
- **Checklist BẮT BUỘC mỗi preset khi đăng ký:** (1) cặp chữ/nền đạt **WCAG AA** (axe không đo được text-over-image — đây là curation duty); (2) KHÔNG set `motion` trong preset nhắm vị trí block đầu; (3) đẹp mobile-first trước.
- **AI-gen (ADR-08):** pipeline sinh trang ưu tiên **sinh preset key → server expand qua cùng registry** (không tự phối 12 prop) — giảm hallucination + thẩm mỹ curated by construction; backstop = ValidPageJson.
- **Ví dụ chuẩn `hero` (5):** *Tối giản sáng* (surface, spacing-l, center) · *Đậm thương hiệu* (gradient-1, on-dark) · *Ảnh tràn tối* (image + overlay-40, full) · *Chia đôi gọn* (variant split, tint) · *Băng hẹp* (spacing-s, narrow).

---

## 4. TẦNG 3 — `extra_style`: hiệu ứng đăng ký (build Đợt 2)

Cơ chế: `list(enum)` — chỉ nhận key đã đăng ký trong theme (`StyleProps::EXTRA_STYLES` map key→class); validate allow-list; render qua cùng bảng tra fail-closed. **Danh sách khởi điểm (7):**

| Key | Hiệu ứng | Ghi chú |
|---|---|---|
| `lift-hover` | card nâng nhẹ khi hover | desktop-only (hover-capable media query) |
| `border-accent` | viền accent | |
| `divider-accent` | gạch ngắn màu accent dưới heading | |
| `text-balance` | `text-wrap: balance` cho heading | |
| `glass` | card kính mờ trên nền ảnh | backdrop-filter, có fallback màu đặc |
| `pattern-dots` | họa tiết chấm mờ nền | CSS thuần từ token |
| `pattern-grid` | họa tiết lưới mờ nền | CSS thuần từ token |

Thêm/bớt hiệu ứng = 1 entry registry + CSS + 1 test (append-only như mọi enum). Hoãn build tới Đợt 2 vì: cần theme utility layer (1a) ổn định trước + tránh chồng lấn `corner/shadow` ngày đầu (§24).

---

## 5. BIÊN CẤM — không bao giờ vào hệ thống (đóng biên Elementor-creep)

| Loại | Vì (1 câu) |
|---|---|
| Color-picker per-section | phá palette AA + re-theme; `brand-soft/tint/dark` phủ nhu cầu |
| Font/cỡ chữ per-section | phá hierarchy + CLS font-swap; token site lo |
| Padding/margin nhập số px | phá nhịp thang spacing, không migrate theme |
| `min_height`/full-screen ở base | chỉ hero/banner cần → `variant` của riêng chúng |
| Breakpoint tùy chỉnh | `hide_on` 2 giá trị đủ; thêm = ma trận QA nổ |
| position/z-index/absolute | vỡ layout mobile |
| Duration/easing per-section | token site + preset đủ; nhiều knob = §24 vỡ |
| Nested section/columns tự do | D1 body phẳng (columns = item-list, COUNCIL Đợt 3) |
| Raw class/CSS tự nhập | ADR-04 — bất biến (áp cả menu `css_class` meta §13 khi build) |

---

## 6. Trình tự build & DoD

- **Đợt 1a (NỀN — 2 PR tuần tự, cùng arc):**
  - **PR 1a.1 — token hóa `storefront.css`** (visual no-op): CSS custom properties + palette generator + thang spacing/radius/shadow/gradient; **invariant: An Vinh render giống hệt** (regression DOM/screenshot); gỡ hardcode brand.
  - **PR 1a.2 — style-props plumbing:** `StyleProps` fragment + `ComponentRegistry::schemaFor()` + leaf `enum`/`list(enum)` ValidPageJson + wrapper BlockRenderer + utility classes + eager-theo-vị-trí + panel "Trình bày" trong Editor (group=style) + `SectionPresetRegistry` + preset cho 11 type hiện có.
  - (Các hạng mục 1a khác của council catalog — §E7 `html/datetime/bool/file`, `sections.js` foundation + size-gate CI, srcset, ADR-19 — theo report catalog F3, plan riêng.)
- **Đợt 2:** +6 prop (`overlay_tone/divider×2/border/motion_stagger/extra_style`) + giá trị mở rộng (`corner:round`, `shadow:medium`, `motion:zoom`, cân nhắc `overlay:80`).
- **Phân loại:** base arc + preset content = **ACT** (contract đã chốt v2.15, guardrail §6 đầy đủ); mỗi WAVE section mới = **COUNCIL nhẹ theo nhóm**; `columns`/`embed`/`offer`/data-bound = **COUNCIL riêng** (giữ nguyên catalog Đợt 3).
- **DoD style-arc (Pest):** enum ngoài danh sách → reject · `anchor_id` bẩn → reject, render escape · reserved-name khai trùng → arch test đỏ · trang An Vinh cũ (không style key) render như trước (modulo wrapper) · block đầu bị strip motion · sink-test giữ xanh · Query Budget không tăng (bg_image đi batch chung).

## 7. Log quyết định (phương án bị loại — 1 câu)

Elementor raw-CSS/HTML per element (untrusted tenant 10k+ site, CWV, không migrate theme, phá ADR-08/export) · bảng DB `section_presets` (drift với CSS, cần gate platform-only thêm) · lưu preset-name trong body (đổi preset phá trang cũ) · style = object sibling ngoài props (mất batch-resolve media + 3 nhánh code mới) · bỏ `hide_on`/`motion` (nhu cầu thật, giữ với ràng buộc enforce) · per-element style (Elementor-creep, §24).

*Nguồn quyết định: council ADR-04 style-props 12/07 (session này) + council catalog 00:01 12/07 (`plans/reports/council-audit-260712-0001-…`) — 2 council hội tụ, owner chốt 12/07/2026.*
