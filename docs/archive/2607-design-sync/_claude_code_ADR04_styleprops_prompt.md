> 🗄 **LƯU TRỮ (24/09/2026)** — tài liệu làm việc của đợt đồng bộ thiết kế tháng 7/2026, giữ làm lịch sử.
> KHÔNG phải nguồn sự thật: có chỗ đã lỗi thời hoặc mâu thuẫn quyết định sau này. SSOT = `docs/design.md`
> (chỉ mục ADR ở Phụ lục A) + `docs/README.md`. Lý do lưu trữ: `docs/archive/README.md`.

Chạy /bs:audit (Council Protocol, propose-first, KHÔNG code) cho đề xuất MỞ RỘNG ADR-04 (design.md §19 + dòng ADR-04 phụ lục): "STYLE-PROPS CHUẨN HOÁ + PRESET REGISTRY" cho Page Builder. DỪNG chờ tôi duyệt diff trước khi ghi.

BỐI CẢNH: Builder v3 (thiết kế) mở rộng Section Library lên ~36 section (danh mục: docs/builder_v3_section_catalog.md phía thiết kế). Yêu cầu sản phẩm: tùy biến mạnh, trực quan, dễ dùng "kiểu Flatsome" — NHƯNG theo cách platform-curated (chúng ta cài sẵn để tenant CHỌN), KHÔNG phải raw CSS/HTML tự nhập. Đề xuất gồm 3 lớp, toàn bộ tương thích tinh thần ADR-04:

LỚP 1 — STYLE-PROPS CHUẨN HOÁ (mọi section, token/enum allow-list, validate Rule::in/§E7):
bg (none|surface|tint|dark|brand|image|gradient-1..3; image ⇒ bg_image:media_id + overlay 0/20/40/60) · spacing_top/bottom (none|s|m|l|xl theo thang theme) · width (contained|narrow|full) · align (left|center) · corner/shadow (none|soft) · hide_on (none|mobile|desktop) · anchor_id (text, slug-sanitize) · motion (none|fade|rise — preset CSS trong theme, tôn trọng prefers-reduced-motion). Render: Blade đọc token → class từ THEME; KHÔNG in raw CSS người nhập.

LỚP 2 — PRESET REGISTRY (điểm "Flatsome-like"): mỗi loại section có 3-6 preset đặt tên (bundle variant+style-props) do PLATFORM định nghĩa; registry PLATFORM-WRITABLE-ONLY, tenant READ-ONLY (cùng nguyên tắc D6 như site_types). Editor áp preset = expand thành props rồi lưu Page-JSON (KHÔNG lưu tên preset) → đổi preset không phá trang cũ.

LỚP 3 — HIỆU ỨNG BỔ SUNG CÓ KIỂM SOÁT: prop `extra_style: list(enum)` — chỉ nhận class đã đăng ký trong theme (vd lift-shadow, border-accent, text-balance); allow-list validate; KHÔNG nhận class/CSS tự nhập.

PHƯƠNG ÁN BỊ LOẠI (nêu minh bạch trong council): raw CSS/HTML per element kiểu Elementor thuần — loại vì tenant-admin là untrusted với platform ở SaaS 10.000+ site (stored-XSS/style-injection), CWV vỡ, nội dung dính CSS cứng không migrate theme, ADR-08 AI-gen cần schema đóng, export/dịch cần typed. (Người dùng ĐỒNG THUẬN hướng curated — không yêu cầu raw CSS.)

YÊU CẦU OUTPUT:
(a) Council các vai (Security · CWV/perf · Theme engine · AI-gen ADR-08 · DX/tenant UX) soi 3 lớp: prop nào rủi ro (vd hide_on ảnh hưởng SEO/CLS? motion và LCP? overlay và contrast AA?) → đề xuất thu hẹp nếu cần.
(b) Nếu chốt → diff đề xuất cho design.md: câu chữ ADR-04/§19 bổ sung "trình bày qua style-props token hoá + preset registry platform-curated; cấm raw CSS/class tự nhập"; vị trí đặt Preset Registry (code registry như PermissionRegistry hay bảng?) — khuyến nghị CODE REGISTRY keyed theo section type (không bảng, khớp lệ role-template §15.2).
(c) Phân loại ACT/COUNCIL cho build: mỗi section mới = registry+§E7+Blade+ValidPageJson+Pest (propose-first từng nhóm); style-props = 1 lần vào SectionComponent base.
(d) KHÔNG ghi file tới khi tôi duyệt. Phát hiện vênh với CODE hiện tại (ThemeResolver/ComponentRegistry/SECTION_SCHEMAS) → báo kèm trích code.
