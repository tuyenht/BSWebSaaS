# Builder v3 — Danh mục Section & ma trận Variant/Prop (v3.1 — BẢN SAU AUDIT, CHỜ DUYỆT CUỐI)

> Mục tiêu: phủ nhu cầu số đông website doanh nghiệp VN (giới thiệu/B2B · sản phẩm · dịch vụ · tin tức · landing · nhà hàng‑du lịch · BĐS) bằng **Section Library có kiểm soát** — KHÔNG Elementor tự do. Mỗi section = đăng ký registry + §E7 + Blade + phủ ValidPageJson + test (**propose‑first**). Trạng thái: **v3.1 — BẢN SAU AUDIT COUNCIL 12/07/2026, CHỜ DUYỆT CUỐI** → duyệt xong build vào prototype v3 theo đợt.
>
> **Ràng buộc tối cao (không đổi):** storefront Blade SSR · JS ≤30KB (bundle section mới **≤6KB gz**, gate CI 8KB) · **LCP mobile p75 ≤1.5s** · mọi giá trị trình bày là **token/enum allow‑list**, không raw CSS/class tự nhập.

## 0. STYLE‑PROPS CHUẨN HOÁ (áp cho MỌI section) — [COUNCIL: chỉnh ADR‑04]
Bộ prop trình bày chung, toàn bộ là **token/enum allow‑list** (server render từ theme, KHÔNG raw CSS):
| Prop | Kiểu §E7 | Giá trị |
|---|---|---|
| `variant` | enum | theo từng section (dưới) |
| `bg` | enum | `none · surface · tint · dark · brand · image · gradient-1..3` (image ⇒ kèm `bg_image:media_id` + `overlay: 0/20/40/60`; đi qua **batched media‑resolve** sẵn có — 1 query/trang) |
| `spacing_top` / `spacing_bottom` | enum | `none · s · m · l · xl` (thang theme) |
| `width` | enum | `contained · narrow · full` |
| `align` | enum | `left · center` |
| `corner` | enum | `none · soft` |
| `shadow` | enum | `none · soft` |
| `hide_on` | enum | `none · mobile · desktop` — **CSS‑only** (nội dung vẫn trong HTML — lưu ý SEO/payload) |
| `anchor_id` | text | id cuộn tới (#lien-he) — **format slug (a‑z0‑9‑)**, cảnh báo mềm khi trùng trong 1 trang |
| `motion` | enum | `none · fade · rise` — preset; **dùng CHUNG 1 IntersectionObserver util** với count‑up/marquee; tôn trọng prefers‑reduced‑motion |

**Validation & render:** enum validate **SERVER‑SIDE trong ValidPageJson** (giá trị ∉ allow‑list → fail lúc lưu); render qua **ánh xạ class tường minh trong theme** (match có default) — không bao giờ nội suy chuỗi prop vào class.

**Diễn giải ADR‑04 (amendment chờ chốt):** *"Section nhận style‑props dạng token/enum allow‑list do platform đăng ký; giá trị validate server‑side ∈ registry, render qua ánh xạ class tường minh — tenant không bao giờ nhập class/CSS thô. Preset trình bày = bundle prop expand khi lưu; Page‑JSON không lưu tên preset."*

### 0b. PRESET TRÌNH BÀY — lớp "Flatsome‑like", platform cài sẵn để chọn [COUNCIL cùng §0]
- **Preset per‑section = STYLE‑only** (variant + style‑props): mỗi loại section có 3–6 preset đặt tên (vd hero: "Tối giản sáng" · "Đậm thương hiệu" · "Ảnh tràn tối"), do **PLATFORM định nghĩa** (registry, tenant READ‑ONLY — cùng nguyên tắc D6 như site_types). Chọn 1 chạm trong inspector, sau đó vẫn tinh chỉnh từng prop. **Mỗi preset có thumbnail** hiển thị trong inspector.
- **Nội dung mẫu khi chèn** thuộc **TEMPLATE LAYER (§7b)** — không trộn 2 khái niệm.
- **Lưu trữ:** Page‑JSON lưu prop đã expand (KHÔNG lưu tên preset) → platform đổi preset không phá trang cũ; preset chỉ là "bút" ở editor.
- **Hiệu ứng bổ sung có kiểm soát:** prop `extra_style: list(enum)` — CHỈ các class/hiệu ứng đã đăng ký trong theme (vd `lift-shadow` · `border-accent` · `text-balance`), validate allow‑list; **KHÔNG** nhận class/CSS tự nhập.

### 0c. TIỀN ĐỀ AS‑BUILT (build ở Đợt 1a — hiện CHƯA tồn tại trong code)
- **§E7 as‑built** mới có `text / textarea / image(media_id) / url / list / int` — cần thêm **`enum · html · datetime · bool · file`** + cap **`max` per‑list**.
- **Theme CSS đang hardcode palette** (chưa có token layer) — cần **token hoá + palette generator** brand→50‑900.
- **Chưa có style‑prop/variant nào as‑built** — §0 là tầng **net‑new hoàn toàn**.
- **Ảnh content đang render 1 width cố định 1280** — cần **srcset/per‑context width** trước gallery/banner/slider.
- **Storefront hiện 0 JS và ngân sách 30KB CHƯA có gate CI** — cần bundle `sections.js` **≤6KB gz** + size‑gate.

## 1. HIỆN CÓ (11) — bổ sung variant
`hero` (+minimal, split‑form; variant `video‑bg` → **defer backlog**, rủi ro LCP — đo sau) · `about` (+stats‑kèm, quote‑kèm) · `features` (+icon‑list, checklist, 2‑cột‑ảnh) · `richtext` · `cta` (+split, nền‑ảnh) · `partners` (+marquee chạy — dùng CHUNG Blade partial với `logos_strip`) · `entry_grid` (+list‑ngang, nổi‑bật‑1+N) · `latest_posts` (+tin‑chính‑phụ) · `product_grid` (+carousel) · `contact` (+card‑3‑cột, split‑map) · `form` (+2‑cột, inline‑hẹp)

`richtext_html` = **ADR‑19 (Draft) — chờ chốt, dự kiến chốt trong Đợt 1a** (kiểu §E7 `html`, sanitize bằng `RichTextSanitizer::full` trên MỌI đường ghi: người nhập + AI sinh + import).

## 2. MỚI — NHÓM NỘI DUNG & MEDIA (9)
| Section | Variants | Props chính (§E7) |
|---|---|---|
| `gallery` | grid · masonry · carousel · lightbox | `images: list(image)` **≤48** + caption/cột 2‑4 — lightbox = native `<dialog>` nạp lazy; masonry = CSS columns (0 JS); ALT lấy từ thư viện media |
| `hero_slider` | fade · slide · autoplay+dots | `slides: list({image, heading, sub, cta_label, cta_url})` **≤5** — slide 1 render SSR eager + `fetchpriority=high`; autoplay mặc định TẮT (nếu bật: ≥5s + pause hover + reduced‑motion); dùng chung snap‑controller, KHÔNG thư viện carousel |
| `video` | single · split‑text | `url(youtube/vimeo qua SafeIframe)` · `poster:image` — **façade bắt buộc** (poster + click mới nạp iframe); variant playlist & autoplay‑muted → **defer backlog** |
| `image_banner` | full‑bleed · contained | `image` · `caption` · `cta` — full‑bleed phụ thuộc srcset (Đợt 1a); variant parallax‑nhẹ → **defer backlog** (CLS/mobile jank) |
| `faq` | accordion‑1‑cột · 2‑cột · nhóm‑chủ‑đề | `items: list({q, a: html(basic — chỉ p/br/b/i/u/ul/ol/li/a)})` — FAQPage JSON‑LD sinh từ bản plain‑text |
| `tabs` | ngang · dọc | `tabs: list({label, html})` (html qua sanitizer full) — a11y tablist keyboard (JS nhỏ dùng chung) |
| `timeline` | dọc · ngang‑mốc | `items: list({year: text (cho phép "2020–2023"), title, text, image?})` |
| `testimonial` | carousel · grid‑2/3 · single‑nổi | `items: list({quote, name, role, avatar:image, rating?})` — **KHÔNG xuất JSON‑LD Review/star** (chính sách self‑serving reviews của Google); sao chỉ render UI |
| `downloads` | list · card | `files: list({label, media_id})` — dung lượng lấy tự động từ record media; cần kiểu §E7 **`file`** (media kind=document; PDF đã có whitelist upload) + URL tải riêng cho non‑image |

## 3. MỚI — NHÓM CHUYỂN ĐỔI (5)
| Section | Variants | Props chính |
|---|---|---|
| `pricing` | 2‑4 gói · so‑sánh‑bảng · nổi‑bật‑1‑gói | `plans: list({name, price: int \| null, unit, features:list, cta, featured:bool})` — VND integer; **null → render "Liên hệ"** (thực tế VN, hợp brochure‑mode); KHÔNG toggle tháng/năm ở GĐ1 |
| `price_list` | list‑2‑cột · card‑ảnh · nhóm‑theo‑danh‑mục | `items: list({name, desc?, price: int \| null, image?})` — null → "Liên hệ"; phục vụ nhà hàng–du lịch/spa/dịch vụ |
| `offer` | băng‑ưu‑đãi · card | `countdown_to: datetime` (lưu **UTC timestamptz**, nhập theo timezone site) — **hết hạn = tự ẩn section**; tôn trọng brochure mode (ẩn giá) |
| `subscribe` | inline · card | `heading` · `form_key` (Forms module) |
| `steps_cta` | 3‑bước+CTA | dẫn khách theo phễu (landing) |

## 4. MỚI — NHÓM NIỀM TIN (5)
| Section | Variants | Props chính |
|---|---|---|
| `team` | grid‑3/4 · carousel · lãnh‑đạo‑nổi | `members: list({name, role, photo:image, social?})` |
| `certificates` | lưới‑logo · ảnh‑lightbox | `items: list({image, label})` — chứng nhận/giải thưởng/MOIT |
| `stats` | 3‑4‑số‑đếm · kèm‑ảnh‑nền | `items: list({value:int, suffix, label})` — đếm tăng khi cuộn (IO util dùng chung §0) |
| `process` | 3‑6‑bước‑ngang · dọc‑chi‑tiết | `steps: list({num, title, text, icon?})` |
| `logos_strip` | tĩnh · marquee | khách hàng/báo chí nhắc tới — implementation dùng CHUNG Blade partial với `partners` (DRY), giữ 2 type riêng cho rõ nghĩa người dùng |

## 5. MỚI — NHÓM LIÊN HỆ/VỊ TRÍ (3)
| Section | Variants | Props chính |
|---|---|---|
| `map` | 1‑điểm · đa‑chi‑nhánh‑tab | `embed(url allow‑list google map)` · NAP từ `site_profiles` — phải **mở rộng iframe allow‑list** (hiện chỉ YouTube/Vimeo) thêm `google.com/maps/embed`; iframe lazy hoặc façade |
| `branches` | card‑lưới · list+giờ‑mở‑cửa | `items: list({name, address, phone, hours})` |
| `notice_bar` | hẹp‑đầu‑section | dải thông báo **TRONG TRANG**; announcement bar toàn‑site = backlog tầng chrome (không thuộc Page‑JSON) |

## 6. MỚI — NHÓM BỐ CỤC/TIỆN ÍCH (4) — ⚠ 2 mục cần COUNCIL riêng
| Section | Variants | Ghi chú |
|---|---|---|
| `spacer_divider` | spacer S/M/L · kẻ mảnh · kẻ‑chữ‑giữa | thuần trình bày |
| `breadcrumb` | mặc‑định | tự sinh từ cây trang (data‑bound) + **BreadcrumbList JSON‑LD**; kiểm overlap với layout post/category khi spec |
| `columns` ⚠ | 2 · 3 · 4 cột | **giữ body PHẲNG** — chốt trước ràng buộc item‑list phẳng `{icon?, title, text, image?, cta?}`; KHÔNG nested section (giữ D1/BlockRenderer phẳng). [COUNCIL — Đợt 3] |
| `embed` ⚠ | iframe‑allow‑list | chốt trước hướng **provider‑registry**: prop = enum provider + tham số per‑provider; render per‑provider Blade + façade; **KHÔNG prop URL iframe thô**. [COUNCIL — Đợt 3] |

## 7. MỚI — NHÓM DỮ LIỆU (data‑bound, +3)
`product_featured` (1 SP nổi bật hero‑style — **tôn trọng catalog_mode**) · `category_grid` (lưới danh mục taxonomy, cần ảnh đại diện taxonomy — kiểm khi spec Đợt 3) · `related_entries` (bài liên quan theo taxonomy) — đều đọc từ Catalog/Cms qua §E7 `taxonomy_slug/limit`, không nhập tay. **Mỗi section data‑bound kèm Query Budget test.**

## 7b. TEMPLATE LAYER (Đợt 1c) — mẫu trang click‑là‑dùng
- **6–10 mẫu trang platform‑defined theo ngành VN** (giới thiệu công ty · sản phẩm cơ khí/B2B · dịch vụ · nhà hàng–du lịch · landing khuyến mãi · liên hệ) = page‑JSON preset, **expand‑on‑insert** (cùng nguyên tắc §0b — không lưu tên template trong Page‑JSON), registry platform (tenant read‑only).
- **Nội dung mẫu khi chèn section**: copy tiếng Việt thật theo ngành — KHÔNG lorem; chèn xong sửa ngay.
- ⚠ **Điểm mở duy nhất: ảnh mẫu** — media per‑site nên khi áp template phải **materialize ảnh vào media của site** (tiền lệ demo‑adopt, design.md Mục 21) → **[COUNCIL riêng khi spec Đợt 1c]**.
- Tầng này là móng cho AI onboarding "<10 phút có site" (design.md Mục 20–21) — không thay thế, không chặn.

## 8. Phân đợt build (sau khi duyệt) — chuẩn bị ~40 loại
- **Đợt 1a — NỀN (không section mới):** §E7 types mới (`enum · html · datetime · bool · file` + `list.max`) · token hoá CSS theme (palette generator) · **section‑shell wrapper** render style‑props · **preset engine** (expand‑on‑save) · srcset/per‑context width · **`sections.js` foundation ≤6KB gz** (snap‑slider · dialog‑lightbox · IO reveal/count‑up · tabs · countdown · façade‑loader) + size‑gate CI · **chốt ADR‑19** (richtext_html) · editor rail: tìm kiếm + nhóm gập + thumbnail.
- **Đợt 1b — 8 section phổ dụng:** `gallery` · `faq` · `testimonial` · `team` · `stats` · `process` · `pricing` · `video` + variant bổ sung nhóm hiện có (TRỪ các variant đã defer: hero video‑bg, video playlist/autoplay‑muted, image_banner parallax).
- **Đợt 1c — Template Layer v1 (§7b).**
- **Đợt 2:** `hero_slider` · `map` · `branches` · `tabs` · `timeline` · `certificates` · `subscribe` · `notice_bar` · `spacer_divider` · `breadcrumb` · `image_banner` · `downloads` · `logos_strip` · `price_list`. **Ưu tiên đầu đợt: `hero_slider` + `price_list` + `downloads`.**
- **Đợt 3 (COUNCIL riêng trước khi code):** `columns` · `embed` · `offer(countdown)` · `steps_cta` · `product_featured` · `category_grid` · `related_entries`.
- **DoD mỗi section:** registry + §E7 schema (enum/list.max đầy đủ) · Blade SSR heading đúng cấp · phủ ValidPageJson + sink‑test · Pest per section · **axe pass trên kitchen‑sink page** · Query Budget test nếu data‑bound · reduced‑motion nếu có chuyển động · empty‑state trong editor · không vượt size‑gate.
- **Kitchen‑sink QA page:** 1 trang seed chứa mọi section × variant chính (axe + đo CWV + regression + demo nội bộ).

## 9. Nguyên tắc giữ nguyên (không đổi)
Caps 100 block/256KB · image = media_id · html qua `RichTextSanitizer::full` · url chặn scheme · mọi type ∈ registry · body phẳng per‑locale · brochure mode ẩn giá.
1. **KHÔNG thư viện carousel/slider ngoài** (Swiper/Slick/Glide…) — CSS scroll‑snap + micro‑controller chung; tổng `sections.js` ≤6KB gz, gate CI 8KB.
2. **Enum/style‑prop validate server‑side** + render qua ánh xạ class tường minh.
3. **Iframe/embed CHỈ qua provider allow‑list đã đăng ký** (mở rộng từng provider một).

## 10. Changelog v3 → v3.1 (sau audit council 12/07/2026)
- **A (as‑built):** HIỆN CÓ 12→11 (richtext_html tách thành dòng ADR‑19 Draft, chốt Đợt 1a); nhóm 2 đếm đúng (9); §8 "~36"→"~40 loại"; thêm §0c Tiền đề as‑built (5 điểm: §E7 types mới · token hoá theme · style‑props net‑new · srcset · sections.js + size‑gate).
- **B (§0):** tách `corner`/`shadow`; ghi chú validate server‑side + ánh xạ class tường minh; anchor_id slug + cảnh báo trùng; hide_on CSS‑only; bg:image qua batched media‑resolve; motion dùng chung IO util; thêm đoạn diễn giải ADR‑04 amendment.
- **C (§0b):** preset = STYLE‑only (nội dung mẫu → §7b); preset có thumbnail.
- **D (nhóm 1–7):** hero video‑bg defer; gallery cap 48 + dialog lazy + masonry 0 JS; hero_slider guardrails (≤5, SSR eager, autoplay tắt); video façade bắt buộc + defer playlist/autoplay; image_banner defer parallax; faq a=html(basic) + JSON‑LD từ plain‑text; tabs a11y; timeline year=text; testimonial không JSON‑LD sao; downloads bỏ size? + kiểu `file`; pricing price int|null "Liên hệ"; offer datetime UTC + tự ẩn; logos_strip/partners chung partial; map mở allow‑list maps + façade; notice_bar in‑page; breadcrumb JSON‑LD; columns chốt item phẳng; embed chốt provider‑registry; data‑bound + Query Budget.
- **E:** thêm `price_list` (nhóm 3, Đợt 2).
- **F (§8):** chia Đợt 1a (nền) / 1b (8 section) / 1c (Template Layer); Đợt 2 + ưu tiên; Đợt 3 COUNCIL; thêm DoD + Kitchen‑sink QA page.
- **G (§9):** +3 nguyên tắc (không lib carousel; validate server + class map; embed provider allow‑list).
- **H:** thêm §7b Template Layer (Đợt 1c) — page‑JSON preset expand‑on‑insert, ảnh mẫu materialize [COUNCIL].
- **I:** trạng thái file → "v3.1 — BẢN SAU AUDIT, CHỜ DUYỆT CUỐI".
