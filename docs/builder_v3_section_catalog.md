# Builder v3 — Danh mục Section & ma trận Variant/Prop (BẢN DUYỆT đợt mở rộng Wave 1)

> Mục tiêu: phủ nhu cầu số đông website doanh nghiệp VN (giới thiệu/B2B · sản phẩm · dịch vụ · tin tức · landing · nhà hàng‑du lịch · BĐS) bằng **Section Library có kiểm soát** — KHÔNG Elementor tự do. Mỗi section = đăng ký registry + §E7 + Blade + phủ ValidPageJson + test (**propose‑first**). Trạng thái: **CHỜ DUYỆT** → duyệt xong build vào prototype v3 theo đợt.

## 0. STYLE‑PROPS CHUẨN HOÁ (áp cho MỌI section) — [COUNCIL: chỉnh ADR‑04, prompt kèm §4]
Bộ prop trình bày chung, toàn bộ là **token/enum allow‑list** (server render từ theme, KHÔNG raw CSS):
| Prop | Kiểu §E7 | Giá trị |
|---|---|---|
| `variant` | enum | theo từng section (dưới) |
| `bg` | enum | `none · surface · tint · dark · brand · image · gradient-1..3` (image ⇒ kèm `bg_image:media_id` + `overlay: 0/20/40/60`) |
| `spacing_top` / `spacing_bottom` | enum | `none · s · m · l · xl` (thang theme) |
| `width` | enum | `contained · narrow · full` |
| `align` | enum | `left · center` |
| `corner` | enum | `none · soft` · `shadow`: `none · soft` |
| `hide_on` | enum | `none · mobile · desktop` |
| `anchor_id` | text | id cuộn tới (#lien-he) |
| `motion` | enum | `none · fade · rise` (preset, tôn trọng prefers‑reduced‑motion) |

### 0b. PRESET TRÌNH BÀY — lớp "Flatsome‑like", platform cài sẵn để chọn [COUNCIL cùng §0]
- **Preset per‑section:** mỗi loại section có 3–6 preset đặt tên (vd hero: "Tối giản sáng" · "Đậm thương hiệu" · "Ảnh tràn tối") = bundle variant + style‑props, do **PLATFORM định nghĩa** (registry, tenant READ‑ONLY — cùng nguyên tắc D6 như site_types). Chọn 1 chạm trong inspector, sau đó vẫn tinh chỉnh từng prop.
- **Lưu trữ:** Page‑JSON lưu prop đã expand (KHÔNG lưu tên preset) → platform đổi preset không phá trang cũ; preset chỉ là "bút" ở editor.
- **Hiệu ứng bổ sung có kiểm soát:** prop `extra_style: list(enum)` — CHỈ các class/hiệu ứng đã đăng ký trong theme (vd `lift-shadow` · `border-accent` · `text-balance`), validate allow‑list; **KHÔNG** nhận class/CSS tự nhập. ⇒ đáp ứng "thiết lập class cài sẵn để chọn" mà vẫn kín về bảo mật/CWV.

## 1. HIỆN CÓ (12) — bổ sung variant
`hero` (+video‑bg, minimal, split‑form) · `about` (+stats‑kèm, quote‑kèm) · `features` (+icon‑list, checklist, 2‑cột‑ảnh) · `richtext_html` · `richtext` · `cta` (+split, nền‑ảnh) · `partners` (+marquee chạy) · `entry_grid` (+list‑ngang, nổi‑bật‑1+N) · `latest_posts` (+tin‑chính‑phụ) · `product_grid` (+carousel) · `contact` (+card‑3‑cột, split‑map) · `form` (+2‑cột, inline‑hẹp)

## 2. MỚI — NHÓM NỘI DUNG & MEDIA (8)
| Section | Variants | Props chính (§E7) |
|---|---|---|
| `gallery` | grid · masonry · carousel · lightbox | `images: list(image)` + caption/cột 2‑4 |
| `hero_slider` | fade · slide · autoplay+dots | `slides: list({image, heading, sub, cta_label, cta_url})` — rất phổ biến ở site doanh nghiệp VN |
| `video` | single · split‑text · playlist | `url(youtube/vimeo qua SafeIframe)` · `poster:image` · autoplay‑muted |
| `image_banner` | full‑bleed · contained · parallax‑nhẹ | `image` · `caption` · `cta` |
| `faq` | accordion‑1‑cột · 2‑cột · nhóm‑chủ‑đề | `items: list({q,a:textarea})` — feed FAQPage schema |
| `tabs` | ngang · dọc | `tabs: list({label, html})` (html qua sanitizer) |
| `timeline` | dọc · ngang‑mốc | `items: list({year, title, text, image?})` |
| `testimonial` | carousel · grid‑2/3 · single‑nổi | `items: list({quote, name, role, avatar:image, rating?})` |
| `downloads` | list · card | `files: list({label, media_id, size?})` — hồ sơ năng lực/catalogue |

## 3. MỚI — NHÓM CHUYỂN ĐỔI (4)
| Section | Variants | Props chính |
|---|---|---|
| `pricing` | 2‑4 gói · so‑sánh‑bảng · nổi‑bật‑1‑gói | `plans: list({name, price:int, unit, features:list, cta, featured:bool})` — VND integer |
| `offer` | băng‑ưu‑đãi · card + `countdown_to: datetime` | tôn trọng brochure mode (ẩn giá) |
| `subscribe` | inline · card | `heading` · `form_key` (Forms module) |
| `steps_cta` | 3‑bước+CTA | dẫn khách theo phễu (landing) |

## 4. MỚI — NHÓM NIỀM TIN (5)
| Section | Variants | Props chính |
|---|---|---|
| `team` | grid‑3/4 · carousel · lãnh‑đạo‑nổi | `members: list({name, role, photo:image, social?})` |
| `certificates` | lưới‑logo · ảnh‑lightbox | `items: list({image, label})` — chứng nhận/giải thưởng/MOIT |
| `stats` | 3‑4‑số‑đếm · kèm‑ảnh‑nền | `items: list({value:int, suffix, label})` — đếm tăng khi cuộn |
| `process` | 3‑6‑bước‑ngang · dọc‑chi‑tiết | `steps: list({num, title, text, icon?})` |
| `logos_strip` | tĩnh · marquee | khách hàng/báo chí nhắc tới (tách khỏi partners) |

## 5. MỚI — NHÓM LIÊN HỆ/VỊ TRÍ (3)
| Section | Variants | Props chính |
|---|---|---|
| `map` | 1‑điểm · đa‑chi‑nhánh‑tab | `embed(url allow‑list google map)` · NAP từ `site_profiles` |
| `branches` | card‑lưới · list+giờ‑mở‑cửa | `items: list({name, address, phone, hours})` |
| `notice_bar` | hẹp‑đầu‑section | thông báo/khuyến mãi ngắn + link |

## 6. MỚI — NHÓM BỐ CỤC/TIỆN ÍCH (4) — ⚠ 2 mục cần COUNCIL riêng
| Section | Variants | Ghi chú |
|---|---|---|
| `spacer_divider` | spacer S/M/L · kẻ mảnh · kẻ‑chữ‑giữa | thuần trình bày |
| `breadcrumb` | mặc‑định | tự sinh từ cây trang (data‑bound) |
| `columns` ⚠ | 2 · 3 · 4 cột | **giữ body PHẲNG**: mỗi cột chỉ chứa item đơn giản (icon+title+text+image+cta) theo §E7 list — KHÔNG nested section tự do (giữ D1/BlockRenderer phẳng). [COUNCIL] |
| `embed` ⚠ | iframe‑allow‑list | CHỈ nguồn đăng ký (YouTube/Vimeo/Google Map/Forms) — KHÔNG raw HTML. [COUNCIL] |

## 7. MỚI — NHÓM DỮ LIỆU (data‑bound, +3)
`product_featured` (1 SP nổi bật hero‑style) · `category_grid` (lưới danh mục taxonomy, ảnh đại diện) · `related_entries` (bài liên quan theo taxonomy) — đều đọc từ Catalog/Cms qua §E7 `taxonomy_slug/limit`, không nhập tay.

## 8. Phân đợt build prototype v3 (sau khi bạn duyệt)
- **Đợt 1 (phổ dụng nhất):** style‑props chuẩn + **preset per‑section (§0b)** — panel "Trình bày" trong inspector; **rail "Thêm Section" thêm tìm kiếm + nhóm gập** (chuẩn bị ~36 loại); + `gallery` · `faq` · `testimonial` · `team` · `stats` · `process` · `pricing` · `video`
- **Đợt 2:** `hero_slider` · `map` · `branches` · `tabs` · `timeline` · `certificates` · `subscribe` · `notice_bar` · `spacer_divider` · `breadcrumb` · `image_banner` · `downloads` · `logos_strip` · variant bổ sung nhóm hiện có
- **Đợt 3 (cần COUNCIL trước):** `columns` · `embed` · `offer(countdown)` · nhóm dữ liệu (+3) · `steps_cta`
- Mỗi đợt: build v3 → bạn duyệt UX → cập nhật spec + task Claude Code (registry+§E7+Blade+ValidPageJson+Pest per block).

## 9. Nguyên tắc giữ nguyên (không đổi)
Caps 100 block/256KB · image = media_id · html qua `RichTextSanitizer::full` · url chặn scheme · mọi type ∈ registry · body phẳng per‑locale · brochure mode ẩn giá.
