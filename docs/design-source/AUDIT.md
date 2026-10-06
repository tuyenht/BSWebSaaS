# AUDIT — Rà soát toàn diện BSWebSaaS Admin

Giai đoạn 1/3: **Kiểm kê** (01/10/2026). Chưa sửa file nào.
Căn cứ: `CLAUDE.md` (§0–§15) và design system BSSaaS AdminKit (UX v2.4.1, P1–P16, "Áp dụng riêng", "Nợ UX đã biết").

---

## 1. Năng lực kiểm tra

| Thao tác | Làm được? | Cách làm / giới hạn |
|---|---|---|
| Render từng trang và xem | **Có** | Mở trang trong khung xem trước của tôi rồi chụp ảnh. Ảnh chụp dựng lại từ DOM, nên `filter`, `clip-path`, `backdrop-filter`, bóng phức tạp và `<input type=color>` có thể hiển thị sai. |
| Đổi bề rộng khung xem | **Có, gián tiếp** | Khung của tôi có bề rộng cố định. Để thử các bề rộng khác, tôi nhúng trang vào iframe kích thước bất kỳ (360, 390, 740×360, 768, 1024, 1280, 1440, 1920) rồi đo `scrollWidth`, chiều cao và vị trí phần tử. Ảnh của iframe có thể bị thu nhỏ. Đây **không phải** giả lập thiết bị: không có DPR thật, tai thỏ/safe-area hay bàn phím ảo. |
| Chuyển thuộc tính Tùy biến rồi xem | **Có, có rủi ro** | Bấm nút trong panel bằng JS, chờ vẽ lại rồi chụp và đo. Lưu ý: Admin Shell ~300KB đã nhiều lần làm lệnh JS quá thời gian 7 giây trong khung của tôi. Khi đó tôi dùng khung xem của bạn (chỉ đọc/bấm, không chụp được ảnh) hoặc iframe con. |
| Tính tỷ lệ tương phản bằng code | **Có** | Công thức WCAG 2.x trên màu tính toán (`getComputedStyle`), hoặc trên mã hex trong nguồn. Giới hạn: chữ trên ảnh hoặc chuyển màu chỉ ước lượng theo điểm xấu nhất; màu `color-mix()`/`oklab` phải đọc giá trị tính toán, không đọc được từ nguồn. |
| Quét nguồn hàng loạt | **Có** | Tìm hex cứng, `outline:none`, chữ < 12px, `nowrap`, độ rộng cố định, thiếu `aria-*`… trên toàn bộ file. Riêng file tên tiếng Việt phải sao sang tên tạm mới đọc được bằng script; vẫn làm được. |
| Trạng thái rê chuột (`:hover`) | **Chỉ mô phỏng** | Không có con trỏ thật. Tôi ép class/kiểu tương đương để xem, nên không bắt được lỗi "khe hở giữa menu cha và menu con" như ảnh bạn gửi. Lỗi loại này cần bạn rê thử. |
| Bàn phím / focus | **Một phần** | Gọi `focus()` rồi kiểm tra vòng focus và việc topbar có che không. Không thử được thứ tự Tab tự nhiên của trình duyệt. |
| Chạm thật, `pointer: coarse`, `hover: none` | **Không** | Không giả lập được media query thiết bị. Chỉ kiểm được bằng cách đọc CSS và đo kích thước vùng bấm. |
| `prefers-reduced-motion`, chữ hệ thống lớn | **Không trực tiếp** | Chỉ đọc CSS. Phóng to 200% mô phỏng được bằng cách tăng `font-size` gốc hoặc thu iframe còn 640/320px. |
| Toàn màn hình (Fullscreen API) | **Không** | Trình duyệt chặn trong iframe xem trước. Shell sẽ dùng chế độ "phóng to vùng làm việc" thay thế, và chế độ đó kiểm được. |
| Safari iOS, Firefox | **Không** | Chỉ có một nhân trình duyệt. Các lỗi riêng của Safari (`100vh`, `position:sticky` trong `overflow`, tự phóng to ô < 16px) chỉ kiểm qua đọc code. |
| Xem đúng màn bạn đang thấy | **Có** | Đọc DOM và chạy JS trên khung xem của bạn, nhưng không chụp ảnh được. |

---

## 2. Danh sách trang

Ký hiệu loại tài khoản: **Site** = website nội dung (`plane: tenant`), **TMĐT** = shop (`plane: shop`), **SaaS** = console nền tảng (`plane: platform`). Shell truyền `emAccType`: shop→`ecom`, platform→`saas`, còn lại `site`.
Dùng chung: mọi DC đều nạp `admin-base.css` và đọc `localStorage['bs-scheme']`. Cột "DS" cho biết trang có nạp bộ component `_ds_bundle.js` hay không.

### 2a. Khung và panel nhúng trong Shell

| Trang | Loại TK | Nhúng ở đâu | Dùng chung | DS |
|---|---|---|---|---|
| **Admin Shell** | Site · TMĐT · SaaS | — (khung chính) | `ai-quota.js`, `platform-config.js`, nhúng 7 DC con | ✓ |
| Email Settings | Site · TMĐT · SaaS (`site/ecom/saas/platform`) | Cài đặt › Email; SaaS: Email hệ thống + Mẫu email hệ thống | `image-slot.js`, `ai-quota.js`, `platform-config.js` | ✗ |
| Integrations Panel | Site · TMĐT | Cài đặt › Tích hợp & Theo dõi | `platform-config.js` | ✗ |
| Floating Contact Panel | Site · TMĐT | Cài đặt › Nút liên hệ nổi | `platform-config.js`, `ai-quota.js` | ✓ |
| Đăng nhập nhanh & Liên kết tài khoản | Site · TMĐT · SaaS | Cài đặt › Đăng nhập nhanh; SaaS: Đăng nhập nhanh; Tài khoản của tôi (`screen=profile`) | `platform-config.js` | ✗ |
| Hạn ngạch AI và mua thêm | Site · TMĐT | Cài đặt › Gói & mức dùng | (`window.BSAI` từ Shell) | ✓ |
| Trình đơn | Site · TMĐT | Menu "Trình đơn" (màn toàn trang) | `ai-quota.js`, `block-library.js` | ✓ |
| MediaPicker | Site · TMĐT | Hộp thoại chọn ảnh (logo, ảnh chia sẻ…) | — | ✗ |

### 2b. Màn đứng riêng, **chưa nối vào Shell**
Các mục Trang, Tin tức, Dịch vụ… trong Shell hiện chỉ có màn trống "đang được xây dựng" (`showBlank`). Theo §13, các màn toàn trang dưới đây phải nhận `embedded` và được Shell mount.

| Trang | Loại TK | Vai trò | Dùng chung | DS | `embedded` |
|---|---|---|---|---|---|
| Page Builder v3 | Site · TMĐT | Trình dựng trang (màu storefront chuẩn) | MediaPicker | ✗ | ✗ |
| Page Editor - Options | Site · TMĐT | Trình soạn trang (màn chuẩn §15) | Trình soạn nội dung, Nâng cấp nội dung, AI Writer Panel v2, MediaPicker; `ai-quota`, `content-workflow`, `content-seo` | ✗ | ✗ |
| Quản lý Bài viết - Options | Site · TMĐT | Danh sách và soạn bài (canvas) | Trình soạn nội dung, AI Writer Panel v2, Nâng cấp nội dung; 3 file js trên | ✗ | ✗ |
| Quản lý Trang - Options | Site · TMĐT | Danh sách trang (canvas) | — | ✗ | ✗ |
| Trình soạn nội dung | Site · TMĐT | Ô soạn rich-text dùng chung | MediaPicker, `ai-quota` | ✗ | ✗ |
| AI Writer Panel v2 | Site · TMĐT | Panel trợ lý viết AI | `ai-quota` | ✓ | ✗ |
| Nâng cấp nội dung | Site · TMĐT | Hộp "Nâng cấp bài viết" | `ai-quota` | ✗ | ✗ |
| Hộp duyệt | Site · TMĐT | Hộp duyệt nội dung | `content-workflow` | ✓ | ✗ |
| Quy trình duyệt | Site · TMĐT | Cài đặt quy trình duyệt | `ai-quota`, `content-workflow` | ✓ | ✓ |
| Duyệt & xuất bản nội dung | Site · TMĐT | Màn duyệt/xuất bản | — | ✓ | ✗ |
| Trạng thái duyệt (khối soạn thảo) | Site · TMĐT | Khối trạng thái gắn vào trình soạn | `content-workflow` | ✓ | ✓ |
| Vận hành nội dung AI | Site · TMĐT | Tổng quan vận hành AI | — | ✓ | ✗ |
| Hàng đợi ảnh | Site · TMĐT | Hàng đợi tạo ảnh AI | `ai-quota` | ✓ | ✗ |
| Kế hoạch nội dung SEO | Site · TMĐT | Lập kế hoạch bài SEO | — | ✓ | ✗ |
| Cài đặt nội dung AI | Site · TMĐT | Cài đặt AI | — | ✓ | ✗ |
| Cài đặt xuất bản tự động | Site · TMĐT | Cài đặt lịch xuất bản | — | ✓ | ✗ |
| Trợ lý viết nội dung AI v2 - Options | Site · TMĐT | Trang trình bày phương án (canvas) | AI Writer Panel v2, `ai-quota` | ✓ | ✗ |
| Image Picker | Site · TMĐT | Trang thử MediaPicker | MediaPicker | ✗ | ✗ |

### 2c. Tham chiếu và đặc tả (không phải màn sản phẩm)

| Trang | Ghi chú |
|---|---|
| Language Context - Design Spec | Tham chiếu gốc §1 (thanh ngôn ngữ) |
| Cài đặt - Đề xuất giao diện | Đối chiếu Cài đặt (canvas) |
| Block Library - Options | Thư viện khối (`block-library.js`) |
| Admin Palette - Options, Admin Palette Sample | **Lưu trữ (Q8)** — không kiểm, không sửa. |

### 2d. File dùng chung (không phải trang)
`admin-base.css` (token chuyển tiếp, vùng bấm 44px, vòng focus, tông tối) · `ai-quota.js` (`window.BSAI`) · `platform-config.js` (`window.BSPLAT`) · `block-library.js` · `content-seo.js` · `content-ui.js` · `content-workflow.js` · `image-slot.js` · `_ds/…` (design system).

---

## 3. Thuộc tính hiển thị

Nguồn: `Admin Shell.dc.html`. Mọi thuộc tính Tùy biến là **state React của Shell** (`this.state.*`). Shell tính chuỗi `style` trong `renderVals()`, không dùng class. Từ Đợt C: 9 thuộc tính lưu ở `localStorage['bs-ui']` (`version: 1`), vẫn giữ `bs-scheme` + `data-bs-theme` trên `<html>`. (Trước Đợt C chỉ `scheme` được lưu.)

### 3a. Panel "Tùy biến giao diện" (theo thứ tự hiện, kể cả phần phải cuộn)

| # | Thuộc tính | State | Giá trị | Hiện thực | Ảnh hưởng tới |
|---|---|---|---|---|---|
| 1 | Loại tài khoản | `plane` | Site `tenant` · TMĐT `shop` · Nền tảng `platform` (nhãn cũ "SaaS", đổi ở Đợt C) | JS: đổi menu (`tenantMenu`/`shopMenu`/`platformMenu`), nhóm Cài đặt (`settingsGroupsAll`), `emAccType` truyền xuống panel; có chặn khi còn thay đổi chưa lưu (`navGuard`) | Toàn Shell, Email Settings, Đăng nhập nhanh |
| 2 | Màu thương hiệu | `brandColor` | 6 màu (Đợt C): Bảo Sơn `#2050a0` (mặc định) · Xanh cổ vịt `#0e7490` · Xanh lá `#15803d` · Chàm `#4338ca` · Mận `#9d174d` · Than chì `#334155`. (Đợt A–B: 9 màu gồm cả Navy, Xanh dương, Mòng két, Tím than) | Biến `--brand` và `--brand-ink` (tối: `color-mix(in oklab, brand 38%, #e6eeff)`) trên khung Shell; thanh bên pha `#0b1d45` | Chrome Shell (thanh bên, topbar đậm, mục đang chọn, liên kết, nút chính). **Cần kiểm:** panel nhúng dùng `--color-accent` (cố định `#2050a0` trong `admin-base.css`) chứ không dùng `--brand`, nên đổi màu thương hiệu có thể không áp vào nội dung panel. |
| 3 | Bố cục | `layout` | Dọc `vertical` · Ngang `horizontal` · 2 cột `twocolumn` (bảng cũ ghi sai `twocol`) | JS: dựng thanh bên, menu ngang (`.hn`/`.hdd` + đo `hVisible`, mục "Khác") hoặc rail 2 cột (`twoColDef`) | Toàn Shell. **< 992px luôn ép về Dọc**. |
| 4 | Chế độ màu | `scheme` | Sáng `light` · Tối `dark` | `data-bs-theme` trên `<html>` + bảng `K` (nền/chữ/viền) + biến `--tx-*`, `--brand-ink`; khối `[data-scheme="dark"]` trong Shell; `admin-base.css` `[data-bs-theme="dark"]` | **Mọi trang** (mỗi DC đọc `bs-scheme`; panel nhúng lật theo `<html>`) |
| 5 | Chiều rộng bố cục | `width` | Tràn `fluid` · Hộp `boxed` | JS: `boxed` → khung có max-width, nền ngoài khung | Shell + vùng nội dung |
| 6 | Vị trí bố cục | `layoutPosition` | Cố định `fixed` · Cuộn `scrollable` | JS: `scrollable` → topbar/thanh bên cuộn theo trang (bỏ qua trên mobile) | Shell |
| 7 | Kích thước Sidebar | `sidebarSize` | Mặc định `default` (258px) · Thu nhỏ `small` (76px, rail + menu bung `.rfly`) | JS `sidebarW`; CSS `.sbw/.rfly` | Shell; mờ đi khi bố cục Ngang |
| 8 | Màu Sidebar | `sidebarColor` | Sáng `light` · Đậm `dark` (mặc định) · Gradient `gradient` | Bảng `sb` (nền/chữ/active/hover); biến `--navHoverBg/--navHoverFg` | Thanh bên + rail 2 cột; mờ đi khi Ngang. Khi `scheme=dark`, **cả 3 màu** dùng chung nền cố định `#111a30` (bảng cũ chỉ ghi bản Sáng) |
| 9 | Màu Topbar | `topbarColor` | Sáng `light` · Đậm `dark` (nền `--brand`) | Bảng `tb` | Topbar (ô tìm kiếm, icon, chọn site, chọn ngôn ngữ, tài khoản) |
| — | Nút "Đặt lại" / "Xong" | `resetCustomizer()` / đóng panel | — | JS | Đưa 1–9 về mặc định |

Không có trong panel (khác Velzon gốc): Sidebar Images, Preloader, Sidebar View (detached). Design system có liệt kê "Sidebar Images" là một trong 8 trục; Shell hiện không có.

### 3b. Điều khiển khác ảnh hưởng tới hiển thị

| Điều khiển | Vị trí | State / hiện thực | Ảnh hưởng |
|---|---|---|---|
| Thu/mở sidebar (nút ☰ `onHamburger`) | Topbar trái | ≥ 992px: bật/tắt `sidebarSize` default↔small. < 992px: `mobileNavOpen` (drawer 268px + lớp phủ) | Shell. **Cần kiểm:** nút chỉ có icon, aria-label "Mở menu"; design system yêu cầu chữ "Menu" (P14.e, C2). |
| Sáng/Tối (`onToggleScheme`) | Topbar phải | Cùng state `scheme` với mục 4 | Mọi trang |
| Toàn màn hình (`onToggleFullscreen`) | Topbar phải | Fullscreen API thật; bị chặn thì dùng `focusMode` (ẩn thanh bên, nội dung tràn; tắt dưới 992px) | Shell |
| Đổi ngôn ngữ giao diện | Topbar, menu EN/VI/JA/ZH | `lang`, `prefLang` | Nhãn Shell; gây đo lại menu ngang (`lk = layout|plane|lang`). Khác thanh ngữ cảnh ngôn ngữ nội dung (§2). |
| Ngữ cảnh ngôn ngữ nội dung | Trong Cài đặt (thanh pill) | `cLang`, `siteLangs`, `pubLangs`, `defLang` | Các trường i18n, chip VI/EN, khoá trường dùng chung |
| Đổi site | Topbar, chỉ hiện khi `plane=tenant` | `site`: An Vinh · Green Farm Co. · MediLab Clinic | Tên/miền/ô chữ viết tắt; tên site dài kiểm tràn topbar |
| Tìm kiếm nhanh, Thông báo, Hộp duyệt, Tài khoản | Topbar | `drop` (một popover mở tại một thời điểm) | Lớp popover, z-index, tràn mép khi hẹp |
| Bề rộng cửa sổ | — | `winW` (ResizeObserver/resize). Ngưỡng JS **992px**; CSS `@media (pointer:coarse)` và `(prefers-reduced-motion)` trong `admin-base.css` | Toàn Shell. **Cần kiểm:** design system đặt ngưỡng 1024px, rail tự động ở 1024–1279px và BottomNav dưới 1024px. Shell đang dùng 992px, không có rail tự động và không có BottomNav. |
| Khoá màn hình / Đăng xuất | Menu tài khoản | `screenLocked`, `logoutAsk`, `loggedOut` | Lớp phủ toàn khung |

### 3c. Thuộc tính hiển thị trong trang (ngoài Shell)
- **Page Builder v3:** bộ màu section (Sáng · Nhạt · Thương hiệu · Tối, + Màu phụ / Tùy chỉnh), ảnh nền + lớp phủ, khoảng cách, độ rộng, căn chữ, bo góc, đổ bóng, hiện trên thiết bị, hiệu ứng, chế độ xem Máy tính/Máy tính bảng/Điện thoại. Chuẩn hàm: `schemes()`, `inkOn()`, `fitInk()`.
- **Mọi màn có nội dung đa ngữ:** thanh ngôn ngữ thích ứng (pill → `+N` → ô chọn + panel) theo §4 và §11.

---

## 4. Kế hoạch kiểm

### 4a. Trục kiểm (theo CLAUDE.md §8 + P14.n)
- **Bề rộng (8):** 360 · 390 · 740×360 (xoay ngang) · 768 · 1024 · 1280/1366 · 1440 · 1920
- **Chế độ màu (2):** sáng · tối
- **Cỡ chữ (2):** thường · lớn (`font-size` gốc 125%), mô phỏng phóng to 200% ở 640px
- **Loại tài khoản (3):** chỉ với Shell và các panel nhận `accountType`
- **Không làm được:** Safari, Firefox, chạm thật. Tôi đánh dấu các mục này "cần bạn thử trên máy".

Tổ hợp đầy đủ của 9 thuộc tính Tùy biến là khoảng 7.800 trường hợp, không thể duyệt hết. Đề xuất **kiểm theo cặp (pairwise)**: 14 tổ hợp phủ mọi cặp giá trị, cộng 6 tổ hợp rủi ro cao, gồm:
- Ngang + Tối + Topbar đậm
- Thu nhỏ + Gradient + màu Mận
- Hộp + Cuộn + 1920
- Sidebar sáng + Tối
- 2 cột + 1024
- TMĐT + Ngang ở 1280, nhiều mục nên phải dồn vào "Khác"

### 4b. Mỗi trang được kiểm những gì
1. **Bố cục:** không cuộn ngang, không phần tử tràn khỏi thẻ, không chữ bị cắt hoặc `…` mất nghĩa, lưới 2 cột → 1 cột, đầu trang theo §8.
2. **Tương phản (script):** mọi nút chữ, đạt 4,5:1; chữ lớn, icon và viền ô đạt 3:1. Đo ở cả sáng và tối.
3. **Vùng bấm:** ≥ 44px dưới 768px và với `coarse` (đọc CSS); ≥ 32px với chuột.
4. **Focus:** có vòng focus, không bị topbar che; quét `outline:none` còn sót.
5. **Popover, modal, menu:** neo đúng, không tràn mép ở 390px, đóng được bằng Esc hoặc bấm ra ngoài, chỉ một cái mở.
6. **Chữ:** không dưới 12px, ô nhập 16px, line-height ≥ 1,4.
7. **Đúng quy ước CLAUDE.md:**
   - token màu
   - thanh ngôn ngữ (§2–4, §10–11)
   - quota chữ/ảnh (§7, §7a)
   - hợp đồng nhúng (§13)
   - câu chữ và bảng thuật ngữ, gồm kiểu bỏ dấu "Xóa/khóa/Hủy" (P15)
8. **Trạng thái P7:** trống, tải, lỗi, quá dài (chỉ đọc nguồn và bật thử nếu có công tắc).

### 4c. Chia lô (≤ 6 trang/lô)

| Lô | Trang | Trọng tâm | Số lượt (ước tính) |
|---|---|---|---|
| **L1 — Khung Shell** | Admin Shell (chrome, menu, topbar, popover, panel Tùy biến) | 20 tổ hợp Tùy biến × 8 bề rộng; menu ngang/rail/drawer; thu/mở, toàn màn hình, đổi site/ngôn ngữ; 3 loại tài khoản | 3–4 |
| **L2 — Cài đặt nhúng** | Email Settings · Integrations Panel · Floating Contact Panel · Đăng nhập nhanh & Liên kết TK · Hạn ngạch AI và mua thêm · Trình đơn | Hợp đồng nhúng §13, `accountType`, màu thương hiệu có truyền xuống không, thanh ngôn ngữ, quota | 3 |
| **L3 — Trình soạn** | Page Builder v3 · Page Editor - Options · Trình soạn nội dung · AI Writer Panel v2 · Nâng cấp nội dung · MediaPicker (+ Image Picker) | §15, bộ màu storefront, drawer mobile, popup bôi đen, hộp thoại → toàn màn hình trên mobile | 3–4 |
| **L4 — Quy trình duyệt** | Hộp duyệt · Quy trình duyệt · Duyệt & xuất bản nội dung · Trạng thái duyệt · Vận hành nội dung AI · Hàng đợi ảnh | Bảng → thẻ dưới 768px, chip trạng thái, quota ảnh §7a | 2–3 |
| **L5 — Danh sách và cài đặt AI** | Quản lý Bài viết - Options · Quản lý Trang - Options · Kế hoạch nội dung SEO · Cài đặt nội dung AI · Cài đặt xuất bản tự động · Trợ lý viết AI v2 - Options | Bảng, lọc, thao tác hàng loạt, form 2 cột, `embedded` | 2–3 |
| **L6 — Tham chiếu** | Language Context Spec · Cài đặt - Đề xuất giao diện · Block Library - Options · Admin Palette - Options/Sample | Chỉ kiểm nhanh: có lỗi, còn khớp quy ước không. Đề xuất lưu trữ những file đã hết vai trò. | 1 |
| **Tổng hợp** | `AUDIT.md` mục 5 (bảng lỗi theo mức độ) | Xếp ưu tiên, chuẩn bị giai đoạn 2 (sửa) | 1 |

**Tổng ước tính: 15–19 lượt** cho giai đoạn 2 (kiểm). Thứ tự đề xuất là L1 → L2 → L3 → L4 → L5 → L6, vì L1 và L2 là phần dùng chung và ảnh hưởng mọi trang.

### 4d. Kết quả mỗi lô
Mỗi lô ghi vào `AUDIT.md` (mục 5.Lx) một bảng:

| Mã | Trang | Bề rộng / chế độ / tổ hợp | Lỗi | Số đo | Quy tắc vi phạm | Mức độ |
|---|---|---|---|---|---|---|

Mức độ gồm: Chặn · Cao · Trung bình · Thấp. Mỗi lô kèm danh sách "cần bạn thử trên máy thật".

### 4e. Điểm cần kiểm ghi nhận khi kiểm kê (chưa xác minh, chưa sửa)
1. Màu thương hiệu có thể chỉ áp cho chrome Shell, chưa áp vào panel nhúng (`--brand` và `--color-accent` tách nhau).
2. Ngưỡng mobile 992px trong JS lệch với 1024px của design system. Chưa có rail tự động ở 1024–1279px, chưa có BottomNav, nút menu chưa có chữ "Menu".
3. Các thuộc tính Tùy biến ngoài Sáng/Tối không được lưu sau khi tải lại.
4. Các màn nội dung (2b) chưa được Shell mount, mục menu tương ứng vẫn là màn trống (§13).
5. Tên loại tài khoản: panel ghi "SaaS" cho console nền tảng, trong khi CLAUDE.md §7b tách `saas` và `platform`. Cần chốt cách gọi.
6. 10 DC chưa nạp `_ds_bundle.js`, tức chưa dùng component design system (P1).
7. Admin Palette - Options/Sample không có chế độ tối.

### 4f. Quyết định & điều chỉnh (duyệt 01/10/2026)

**Đính chính số liệu:** tổng số trang là **31** (8 nhúng + 18 đứng riêng + 5 tham chiếu), không phải 29 như tóm tắt trong chat. Từ nay mọi tóm tắt trong chat phải khớp số liệu với file này.

**Quyết định đã chốt**
- **Q1** Màu thương hiệu áp cho toàn bộ admin, kể cả panel nhúng: `admin-base.css` cho `--color-accent` và các biến dẫn xuất (hover, nhạt, viền) lấy theo `--brand`, dự phòng `#2050a0`. Kiểm lại tương phản của cả 9 màu sau khi áp.
- **Q2** Ngưỡng mobile là 1024px. Sửa `winW` của Shell và mọi media query liên quan từ 992 thành 1024; rà các DC khác xem có hard-code 992 không.
- **Q3** Rail tự động ở 1024–1279px:
  - Dọc: thu về rail 76px; người dùng mở rộng tạm được, mở dạng phủ lên nội dung, không đẩy nội dung.
  - 2 cột: rail + cột menu con dạng phủ.
  - Ngang: dồn mục thừa vào "Khác" như hiện tại.
- **Q4** Không làm BottomNav. Dưới 1024px dùng drawer; nút mở menu có icon + chữ "Menu". Bỏ BottomNav khỏi quy định của design system, lý do: admin có nhiều mục chính, BottomNav 4–5 ô không chứa đủ.
- **Q5** Lưu cả 9 thuộc tính Tùy biến vào `localStorage['bs-ui']` (có `version`; đọc lỗi hoặc sai version thì dùng mặc định). Giữ `bs-scheme` và `data-bs-theme`. "Đặt lại" xoá key. Ghi chú trong code: bản thật lưu phía server theo người dùng.
- **Q6** Không kiểm L3–L5 trước khi các màn ở mục 2b được mount vào Shell. Việc mount là task riêng, giao sau L2.
- **Q7** Đổi nhãn "SaaS" (plane `platform`) trong panel Tùy biến thành "Nền tảng". Báo cáo sự khác nhau giữa `saas` và `platform`; **không** tự thêm lựa chọn thứ 4.
- **Q8** Dọn dẹp:
  - Bỏ "Sidebar Images" khỏi design system.
  - Admin Palette - Options / Sample chuyển vào nhóm lưu trữ, không kiểm, không sửa.
  - 10 DC chưa nạp `_ds_bundle.js` = **NỢ KỸ THUẬT**, chuyển đổi trong lô chứa trang đó.

**Thứ tự làm (kiểm và sửa xen kẽ)**
1. L1 kiểm → báo cáo → **DỪNG**
2. Sửa lớp dùng chung (Q1–Q5 + lỗi Chặn/Cao của L1) → kiểm lại L1
3. L2 kiểm → báo cáo → **DỪNG** → sửa → kiểm lại
4. **DỪNG**, chờ task mount các màn 2b
5. L3 → L4 → L5, mỗi lô: kiểm → báo cáo → **DỪNG** → sửa → kiểm lại
6. L6 kiểm nhanh, dọn dẹp

**Ma trận kiểm (thay mục 4a)**

L1 — Admin Shell:
- 20 tổ hợp Tùy biến (14 pairwise + 6 rủi ro cao), **chỉ ở 1440px**.
- Mỗi bố cục (Dọc/Ngang/2 cột) ở chế độ Sáng × 8 bề rộng (360, 390, 740×360, 768, 1024, 1280, 1440, 1920). Thêm chế độ Tối ở 390 và 1024.
- 3 loại tài khoản ở Dọc-Sáng-1440: menu, nhóm Cài đặt, route lộ qua tìm kiếm hoặc link nhanh.
- Chữ lớn 125% và phóng to 200% (iframe 640px): chỉ Dọc-Sáng ở 1440 và 390.
- Tương phản 9 màu thương hiệu bằng script, Sáng + Tối, mọi vai trò (nút, link, chữ menu trên 3 màu sidebar, vạch active, focus ring, topbar đậm). Render thật 2 màu tệ nhất.

L2–L5 — mỗi trang, tối thiểu:
- (Dọc, Sáng, 1440) · (Ngang, Tối, 1280) · (2 cột, Sáng, 1024) · 390 Sáng · 390 Tối · 768 Sáng
- Chữ lớn 125%: một lần ở 390 Sáng.
- Màu thương hiệu tệ nhất từ L1: ở 1440 Sáng + Tối.
- Mọi giá trị `accountType` ở 1440 Sáng; nếu bố cục khác nhau giữa các giá trị thì thêm 390.
- Chỉ mở rộng ma trận khi cần khoanh vùng lỗi, và ghi lý do.
- Script quá thời gian thì thử lại bằng iframe con. Vẫn không được thì ghi rõ tổ hợp bị bỏ. **Không bỏ qua ngầm.**

**Mẫu báo cáo (mục 5.Lx)**
- Cột: Mã | Trang | Tổ hợp | Lỗi | Số đo | Quy tắc vi phạm | Nguyên nhân gốc | Đề xuất sửa | Bằng chứng | Mức độ
- Bằng chứng, chọn đúng một: [ĐÃ RENDER VÀ THẤY] / [ĐO BẰNG SCRIPT] / [SUY RA TỪ CODE] / [CHƯA KIỂM ĐƯỢC + lý do]
- Mức độ:
  - Chặn = không dùng được, mất chức năng hoặc dữ liệu
  - Cao = sai hoặc khó đọc ở tổ hợp phổ biến
  - Trung bình = lệch design system, hoặc sai ở tổ hợp hiếm
  - Thấp = thẩm mỹ
- Đầu báo cáo: tóm tắt theo mức độ và theo nguyên nhân gốc. Cuối báo cáo: các tổ hợp chưa kiểm được và mục "CẦN TÔI THỬ TRÊN MÁY THẬT" (thao tác, trình duyệt, kết quả kỳ vọng).
- Bổ sung: kích thước Shell là một lỗi hiệu năng (phân tích, chưa tách). Các quan sát cần xác nhận: nút Tùy biến đè nội dung, biểu đồ không trục, "5 chờ xử lý" mà hiện 3 mục, "7 ngày" lệch khoảng 01–17 Th7.

**Quy tắc khi sửa**
- Sửa ở tầng gốc theo thứ tự: token → `admin-base.css` → component dùng chung → shell → trang.
- Không đổi nhãn, menu, luồng ngoài Q1–Q8. Cần quyết định thiết kế mới thì **DỪNG**, đưa 2 phương án kèm khuyến nghị.
- Sau mỗi đợt sửa, kiểm lại mọi trang phụ thuộc, cộng Shell ở 4 tổ hợp: Dọc-Sáng-1440, Ngang-Tối-1280, 2 cột-Sáng-1024, Dọc-Tối-390.
- Changelog: Mã lỗi | File | Thay đổi | Trang ảnh hưởng | Đã kiểm lại (bằng chứng).

### Duyệt L1 (01/10/2026)

Báo cáo 5.L1 được duyệt cùng các quyết định và điều chỉnh dưới đây. Áp dụng cho cả các đợt sau.

**Quyết định**
- **Q7 → phương án B.** Giữ 3 plane (Site · TMĐT · Nền tảng). Console nền tảng luôn truyền `platform` (sửa map `emAccType`, L1-15). Thiết kế cho tenant SaaS ghi vào Việc tồn đọng.
- **Q3 làm rõ.** Ở 1024–1279px luôn là rail 76px, bất kể giá trị "Kích thước Sidebar" đã lưu. Nút ☰ mở sidebar đầy đủ dạng phủ (không đẩy nội dung); đóng khi bấm ra ngoài hoặc nhấn Esc. "Kích thước Sidebar" chỉ có hiệu lực từ ≥ 1280px; panel Tùy biến ghi chú một dòng điều này.
- **L1-16.** Thêm khoảng đệm ở chân trang (chiều cao nút + 16px), giữ nút nổi. Nếu nút nổi chồng thanh Lưu dính đáy của Cài đặt (kiểm ở 390 và 1440) thì nút nổi nằm trên thanh Lưu, cách 16px.
- **L1-17.** Giữ badge "5 chờ xử lý"; liệt kê 3 mục mới nhất + dòng "và 2 mục khác" dẫn tới Hộp duyệt.
- **L1-08 + L1-19.** Chip là `<button aria-pressed>` trong `role="group"`. Một nguồn state cho chip, nhãn khoảng ngày và dữ liệu biểu đồ; chỉnh tay khoảng ngày thì bỏ chọn chip. "Năm" gộp theo tháng (12 cột).
- **L1-18.** Nền cột đặc theo token brand. ≤ 12 cột: ghi giá trị trên mọi cột (≥ 12px). > 12 cột: chỉ ghi cột cao nhất + đường trung bình có nhãn. Không thêm trục y đầy đủ. Kiểm cả 3 chip ở 390 và 1440.
- **L1-22.** Chữ nhiều dòng line-height ≥ 1,4; nhãn một dòng trong vùng cao cố định ≥ 1,3, và đo bằng script là không cắt dấu tiếng Việt.
- **L1-06 bổ sung.** Mọi popover có max-height theo chiều cao khung nhìn (trừ topbar + 16px), cuộn bên trong; kiểm cả 740×360.
- **Vạch active vàng.** Ngưỡng ≥ 3:1 chỉ áp cho sidebar Đậm và Gradient. Với sidebar Sáng, nếu vạch vàng không đạt thì DỪNG, đưa 2 phương án.

**Hoãn thành task riêng sau L2**
- L1-20 (px → rem).
- L1-21 (tách Admin Shell; đo trước và sau bằng `_audit/harness.html`).

**Ghi chú:** `_audit/harness.html` là công cụ đo, không phải trang sản phẩm (không tính vào 31 trang, không kiểm).

**Chia đợt sửa L1**
- **Đợt A — token màu thương hiệu:** L1-01 → L1-05, sửa cùng nhau. (Đã làm, xem 5.L1-A.)
- Các đợt sau: L1-06…L1-19, L1-22 theo quyết định trên. Chờ lệnh.

### Duyệt Đợt A (01/10/2026)
- **L1-23: không sửa, không phải lỗi.** Theo WCAG 1.4.11, chỉ báo so với màu kề bên: vạch vàng kề nền sidebar ở mép ngoài đã đạt ≥ 3:1 (3,18–3,92 Đậm · 3,29–3,92 Gradient). Trạng thái đang chọn còn có chữ đậm và nền phủ. Giữ nền phủ mục đang chọn nhất quán giữa Đậm và Gradient.
- **L1-24: phương án A.** Badge trên nền tối dùng nền đen .22. Sửa ở Đợt B.
- **L1-01:** trạng thái "đóng ở tầng token, còn mở ở tầng trang qua L1-A-P01". L1-A-P01 là việc đầu tiên của L2.
- **L1-25** (ô màu trong panel Tùy biến hiển thị `--brand-solid`, tooltip giữ tên màu): làm ở Đợt C.
- **L1-21** (tách Admin Shell): làm sau Đợt C, **trước** L2.

### Duyệt Đợt B (01/10/2026)
- **`--topbar-h` dự phòng 0px khi DC chạy độc lập: CHẤP NHẬN.** 2 chỗ 70px trong Page Editor (thanh công cụ top 12px, cột phải top 78px) ghi vào Việc tồn đọng của task mount.
- **L1-11 đính chính:** Page Builder v3 không có "992" như báo cáo L1 đã ghi.
- **L1-21 đổi trọng tâm** theo chẩn đoán: (1) bỏ font nạp trùng (Inter ×3, Remix Icon ×2); (2) chỉ dựng panel Tùy biến và các panel Cài đặt khi mở; (3) tách dữ liệu chỉ khi vẫn cần. Làm sau Đợt C, trước L2.
- **Bảng màu thương hiệu:** cùng độ đậm, khác sắc độ, mỗi họ một màu, có một màu trung tính. Bộ mục tiêu 6 màu: Bảo Sơn `#2050a0` (mặc định, cố định, mốc độ đậm) · Xanh ngọc (chọn Xanh cổ vịt hoặc Mòng két bằng số đo) · Xanh lá · Tím (chọn Chàm hoặc Tím than bằng số đo) · Mận · Than chì (mới, khởi điểm `#334155`). Bỏ Navy, Xanh dương và màu thua trong mỗi cặp.
- **Vạch active vàng** giữ chung cho mọi màu; không đổi.
- **Quy tắc ngữ nghĩa** (CLAUDE.md + design system, áp mọi màn về sau): nút nguy hiểm (Xóa, Hủy, Khóa…) luôn icon + chữ, không hoàn tác được thì có hộp xác nhận; chip trạng thái nền nhạt + chữ đậm + icon, nút chính nền đặc — khác hình thức, không chỉ khác màu.

### Duyệt Đợt C (02/10/2026)
- **Bảng 6 màu** (Bảo Sơn, Xanh cổ vịt, Xanh lá, Chàm, Mận, Than chì): CHẤP NHẬN.
- **Không xoá storage dùng chung khi đo:** CHẤP NHẬN. Kiểm tải lại / Đặt lại và phím Tab thật do bạn tự làm trên máy, không làm trong lượt đang chạy.
- **Kế hoạch L1-A-P01:** CHẤP NHẬN thứ tự (6 panel nhúng ở L2; màn đứng riêng ghép vào task mount). Lưu trữ 3 file tham chiếu: **chưa làm**. File đang được CLAUDE.md hoặc design system dẫn chiếu (vd. Language Context - Design Spec cho §1) **không được** lưu trữ.
- Mọi file trong `_audit/` là công cụ đo, không phải trang sản phẩm.
- **L1-21 tách 2 lượt:** L1-21a (chuẩn bị an toàn, bộ hồi quy, đo mốc, L1-C-04, bỏ font nạp trùng) · L1-21b (dựng panel khi cần, tách dữ liệu, chụp ảnh bù).

### Duyệt L1-21a lượt 1 (02/10/2026)
Kết quả L1-21a lượt 1 đã duyệt (dừng an toàn, không file sản phẩm nào bị đổi). Phần còn lại chia 2 lượt: **L1-21a-2** (quy tắc thường trực, sửa phép kiểm, đo nhiễu, L1-C-04 + L1-C-05, kiểm kê font/icon, đóng băng harness; dừng sau Bước E) · **L1-21a-3** (mốc "before" + baseline 29 DC + hiệu năng TRƯỚC, bỏ font nạp trùng, đo sau — cùng một phiên harness).
- **Nguồn Inter: phương án A** — chỉ dùng `inter.css` của design system (tự host), bỏ Google Fonts. Miễn điều kiện "sau ⊇ trước" cho tập con cyrillic và greek. Mọi điều kiện khác giữ nguyên, kể cả weight 800 mà Integrations đang nạp. Làm ở lượt sau.
- **Remix Icon:** thống nhất về 4.9.1 ở lượt sau, với điều kiện kiểm kê Bước D đạt.
- **L1-C-05 gộp vào L1-C-04**, sửa ở tầng gốc bằng MỘT quy tắc nhãn chung cho biểu đồ, áp cho cả 3 chip: (1) nhãn vừa cột → đầy đủ; (2) không vừa → rút gọn số (CLAUDE.md không có quy ước → "1,2K"); (3) vẫn không vừa, hoặc > 12 cột → chỉ nhãn cột cao nhất + đường trung bình. "Vừa cột" = bề rộng nhãn ≤ bề rộng thanh cột, không dung sai. Tầng chọn cho CẢ biểu đồ. "Nhãn" = nhãn giá trị; nhãn trục chỉ đo và ghi. Không nhãn giá trị nào xoay. Phạm vi đóng mã: 390 và 1440.
- Mở Shell không qua cách ly sẽ ghi storage thật — hành vi bình thường của sản phẩm; chỉ bị cấm khi đo.

### Duyệt L1-21a-2 lần 1 (02/10/2026)
- **Hash:** từ nay chỉ dùng SHA-256. Khác thuật toán giữa 1a (FNV-1a) và 1a-2 (SHA-256): chấp nhận.
- **Link thẳng:** chấp nhận thay bằng 2 phép kiểm `#profile` và `#settings/appearance`. Shell không có đường dẫn hash tới "Tài khoản của tôi": ghi nhận cho L2 (không sửa, không tính lỗi ở lượt này).
- Quy tắc nhãn biểu đồ 3 tầng: đã có đủ ở mục "Duyệt L1-21a lượt 1" ngay trên — không chép thêm.

### Duyệt L1-21a-2 lần 2 (02/10/2026, ghi khi vào Bước H của lần 3)
- **Q6 có ngoại lệ chế độ ẩn (L1-21a-2 lần 3), chỉ hợp lệ cho [ĐO-ẨN].** Trong Bước H không áp dụng cho phép đo [ĐO-ẨN]: Q6; mục "không đo khi ẩn"; điều kiện "khung xem bị ẩn" trong ĐIỂM DỪNG. Q1–Q5, Q7–Q10 và vế đầu Q11 giữ nguyên; vế "giữ khung xem hiển thị" của Q11 không áp dụng cho [ĐO-ẨN]. Chế độ (thường/ẩn) cố định cho cả phiên harness; không so chéo hai chế độ.
- **Phạm vi "DC đang dùng"** = mọi `*.dc.html` của dự án, trừ file lưu trữ theo Q8 (`Admin Palette - Options`, `Admin Palette Sample`) và trừ `_audit/`. Số thật: **29** [ĐO, list_files 02/10/2026 10:0x UTC, 31 − 2]: AI Writer Panel v2 · Admin Shell · Block Library - Options · Cài đặt - Đề xuất giao diện · Cài đặt nội dung AI · Cài đặt xuất bản tự động · Duyệt & xuất bản nội dung · Email Settings · Floating Contact Panel · Hàng đợi ảnh · Hạn ngạch AI và mua thêm · Hộp duyệt · Image Picker · Integrations Panel · Kế hoạch nội dung SEO · Language Context - Design Spec · MediaPicker · Nâng cấp nội dung · Page Builder v3 · Page Editor - Options · Quy trình duyệt · Quản lý Bài viết - Options · Quản lý Trang - Options · Trình soạn nội dung · Trình đơn · Trạng thái duyệt (khối soạn thảo) · Trợ lý viết nội dung AI v2 - Options · Vận hành nội dung AI · Đăng nhập nhanh & Liên kết tài khoản. So với "29 DC có class ri-*" ở Bước D lượt 2: **thêm `Image Picker`** (không có class ri-*), **bớt `Admin Palette Sample`** (lưu trữ). Mọi chỗ ghi "29 DC" hiểu theo định nghĩa này.
- **Mã icon:** L1-I01 `ri-handshake-line` (Trình đơn) — hỏng sẵn · L1-I02 `ri-spell-check` (Page Editor) — hỏng sẵn · L1-I03 `ri-error-warning` (Floating Contact Panel qua `Alert`) · L1-I04 nhiều bản Remix cùng tài liệu với Shell. Sửa I01–I04 ở L1-21a-3 cùng việc thống nhất 4.9.1; I01/I02 chỉ đề xuất tên thay thế, không sửa ở lượt này. Kết luận I03/I04: xem 5.L1-21a-2 "Lượt 3".

### Duyệt L1-21a-2 lần 3 (02/10/2026, ghi đầu lần 4, ngay sau Bước CĐ)
**Chế độ của lần 4: CHẾ ĐỘ THƯỜNG** (Bước CĐ 13:37:15 UTC: `visibilityState = 'visible'`). Ngoại lệ chế độ ẩn của Bước CĐ (không áp dụng phần kiểm visibility/sự kiện 'hidden' của Q6, vế "giữ khung xem hiển thị" của Q11, điều kiện "khung xem bị ẩn" ở ĐIỂM DỪNG; vẫn ghi mọi visibilitychange; lô đổi trạng thái giữa chừng = KHÔNG ĐO ĐƯỢC) đã duyệt, chỉ áp khi chạy [ĐO-ẨN] — lần này không dùng trừ khi chuyển chế độ (khi đó ghi thời điểm và lý do ở đây).
1. Chế độ ẩn được dùng cho B, C, E, với điều kiện ĐỐI CHIẾU (so từng số đo gốc Q-lab-* với lần bấm nút chế độ thường) đạt.
2. **L1-T-01:** làm trong lớp cách ly, TRƯỚC Bước E. Lớp chặn áp cho iframe đo, mọi iframe con (kể cả tạo sau khi nạp) và cửa sổ harness (chỉ bật trong lúc chạy lô và phép thử Bước T, gỡ khi xong); cài sau `document.open`, trước script đầu. Navigation API: `navigate` → `preventDefault()` khi `e.cancelable && !e.destination.sameDocument` (cho hash, pushState, replaceState; chặn location.href/assign/replace, link, form sang tài liệu mới). LUÔN ghi đè `window.open` (ghi nhật ký, trả null) và chặn click pha capture vào `a[target]` khác `_self`. Lớp dự phòng (bắt click/submit pha capture, ghi đè `HTMLFormElement.prototype.submit`) bật khi không có `navigation` HOẶC Bước T cho thấy Navigation API không chặn được. Không ghi đè thuộc tính của `location`. Phần không chặn được ghi theo từng cửa sổ, giữ Q3 + phát hiện sau khi nạp. Nhật ký điều hướng ghi riêng, không vào kết quả/digest phép kiểm khác. Không chặn việc AI chủ động nạp lại harness; mỗi lần nạp lại phải xác nhận bằng mã phiên mới.
3. Icon thay thế (sửa ở L1-21a-3): L1-I01 → `ri-shake-hands-line`; L1-I02 → `ri-check-double-line`.
4. **MediaPicker:** đọc code nút Bước U để biết "dat" của Q-b-picker-logo nghĩa là gì; nút đã chạm được → "chỉ đo được ở chế độ thường"; không chạm/chưa rõ → áp cách sửa lần 3 (mở nhóm "Nhận diện" trước khi tìm ô ảnh) cho harness và nút; nút in giá trị đầy đủ Q-b-picker-logo. Chỉ ghi KHÔNG ĐO ĐƯỢC khi bản sửa lần 3 không chạm được ở chế độ thường.
5. Ghi nhận: L1-C-06 xử lý ở L2. Inter hiện chỉ tải file Google (file tự host không tải) → L1-21a-3: đổi sang `inter.css` sẽ đổi nguồn font thật, có thể đổi số đo chữ; L1-21a-3 phải xử lý riêng điểm này.

### Duyệt L1-21a-2 lần 4 (02/10/2026, ghi ở lần 5)
1. **Kết quả lần 4 được duyệt.** Lần bấm nút xác nhận của người dùng (chế độ thường, 14:10–14:12 UTC): hash Shell = `.step-c04` (8188b8c7…), 41/41 đạt, 6/6 Q-lab đạt, khác NGOÀI biểu đồ 0, KHÔNG ĐO ĐƯỢC 0, storage thật trùng, `Q-b-picker-logo` opened = true, "XÁC NHẬN: ĐẠT". Khối chữ chép nguyên văn ở 5.L1-21a-2, mục "Xác nhận độc lập lần 4". L1-C-04/05 giữ trạng thái đóng.
2. **Biên an toàn 0,25px trong Shell: CHẤP NHẬN** — sai khác có chủ đích so với quy tắc 3 tầng: trong Shell "vừa cột" = bề rộng nhãn ≤ bề rộng thanh − 0,25px; phép kiểm Q-lab vẫn không dung sai (Shell chặt hơn phép kiểm nên không gây lỗi giả). Lý do: code Shell không ghi lý do [ĐỌC CODE: dòng `fitAll` không có chú thích]; [SUY LUẬN] Shell đo bằng canvas `measureText` còn phép kiểm đo hộp DOM `getBoundingClientRect` (DPR 1,25 ở khung xem) → hai cách đo có thể chênh do làm tròn điểm ảnh con và dựng chữ khác nhau giữa máy/DPI; biên 0,25px hấp thụ chênh đó.
3. **Bảng đóng băng:** số đo qua máy chủ xem trước (có mã chèn, đổi theo phiên bản app) không dùng làm chuẩn lâu dài. Làm theo a–e; kết quả ở 5.L1-21a-2 lần 5. Từ nay Q9 so theo bảng mới (nội dung gốc) cho các file đã thay; file còn lại theo bảng cũ.
4. **L1-T-01:** sau KIỂM D và F — không phát hiện → đóng theo D(1); có phát hiện ở D(2)/F → vẫn đóng phần đã chặn, phát hiện mang mã riêng.

### Duyệt lượt chốt L1-21a-2 (03/10/2026, ghi ở L1-21a-3a)
1. **Quy ước hash:** "hash gốc" = SHA-256 trên byte thô của file đọc bằng công cụ file (không chuẩn hoá CRLF/BOM); ghi 12 ký tự đầu và hash đầy đủ. Dùng cho file sản phẩm, sao lưu Q8, bảng đóng băng Q9. "Hash phục vụ" (qua máy chủ xem trước) chỉ để suy ra hash mã chèn. Bảng đối chiếu "gốc"/"phục vụ" chỉ đo lại cho 6 file đóng băng, Shell, `.before`, `.step-c04` và các sao lưu Q8 còn tồn tại; số cũ khác gắn nhãn "chưa rõ gốc/phục vụ (chưa đo lại)"; digest JSON ngoài phạm vi; không xoá dòng cũ. Giá trị lấy bằng đo lại.
2. **L1-T-03:** giữ mã chèn, KHÔNG mở băng lúc này; làm Bước I của L1-21a-3a. Phạm vi mở rộng: đường điều hướng của chính mã chèn và trường hợp DC con mang mã chèn. So sánh trước/sau từ trước tới nay vẫn hợp lệ (hai bên cùng mã chèn).
3. **L1-C-07:** định nghĩa Bước L thêm: "Tầng 1/2: đường TB không bắt buộc (có hay không đều đạt), phép kiểm vẫn in cờ. Tầng 3: BẮT BUỘC có đường TB." → **L1-C-07 đóng** (lỗ hổng spec, không sửa code).
4. **Bảng ngưỡng nhãn biểu đồ:** Shell chọn tầng với biên −0,25px · Q-lab 0px (phép có thẩm quyền) · Q-chart +0,5px (phép cũ đã đóng băng, chỉ tham khảo). Khác biệt Q-chart không tự nó làm bước nào KHÔNG ĐẠT.
5. Khối xác nhận lần 4: thêm ghi chú về `&gt;`/`&amp;`. Không bấm lại nút.
6. **Định dạng số rút gọn — mã L1-C-08, làm ở L1-21a-4 (không làm bây giờ):** một hàm dùng chung (file .js dùng chung) + quy ước trong CLAUDE.md; vi: bảng tự định nghĩa K / Tr / Tỷ, không khoảng trắng; en/ja/zh: `Intl.NumberFormat` compact theo locale; chỉ dùng 1 chữ số thập phân khi số được rút gọn (không dùng `minimumFractionDigits` chung — ở ja/zh sinh "3470.0"); dấu thập phân theo locale; một đơn vị cho cả biểu đồ, chọn theo giá trị lớn nhất; trùng nhãn do làm tròn là chấp nhận được; số đầy đủ cho từng cột qua `aria-label` và roving tabindex (cả biểu đồ là 1 điểm dừng Tab, phím mũi tên chuyển cột).
7. **Nguồn Inter (phương án A giữ nguyên):** chỉ dùng `inter.css` tự host. Nếu Bước II cho thấy `inter.css` khác phiên bản/metric so với file Google đang thắng thì đổi nguồn là THAY ĐỔI GIAO DIỆN, cần quyết định riêng trước 3b. Không nạp `inter.css` bằng `@import` mới.
8. **Tách L1-21a-3:** 3a (chuẩn bị, đo thử, không sửa sản phẩm) → 3b (bỏ font trùng + Remix 4.9.1, đo TRƯỚC và SAU cùng phiên) → 3c (icon L1-I01/I02/I04). Định dạng số rút gọn ở L1-21a-4, sau 3c.

### Duyệt L1-21a-3a (03/10/2026, ghi ở L1-21a-3a-2)
1. **L1-T-03:** mã chèn không ảnh hưởng phép đo ở 3a (0 @font-face, 0 FontFace; tắt CSS chèn thì 0/3.038 phần tử Shell đổi style; DC con không chạy mã chèn; storage giả cài trước mã chèn). Giữ mã chèn, vẫn ghi hash mã chèn ở mọi mốc; L1-T-03 hạ xuống "theo dõi".
2. **L1-F-01 (Inter) — THAY phương án A (QĐ7 của 3a). Phương án được chọn: (b′)** — chữ thường dùng Google Inter (như Shell đang dùng); chữ nghiêng giữ đúng khối @font-face chữ nghiêng hiện tại của `inter.css` (cùng file `Inter-italic.woff2`, giữ mọi thuộc tính), chuyển sang một file CSS font nhỏ của dự án; `inter.css` của design system không còn được nạp ở trang nào; KHÔNG sửa `inter.css`. Lý do: nơi Google đang thắng thì không đổi gì; tránh size-adjust 105 % của `inter.css`; ít việc nhất. Tự host đúng file Google (đóng đinh phiên bản) là việc tuỳ chọn về sau (**L1-F-04**), làm khi có lý do cụ thể. Mở **L1-F-02** (đồng bộ chữ nghiêng, sau khi người dùng xem tay).
3. **Bằng chứng an toàn cho 3b = TƯƠNG ĐƯƠNG Ở MỨC FACE:** trước và sau khi sửa, cùng tập FontFace 'loaded' (family, weight, style, unicodeRange, mọi descriptor, URL, hash file) và cùng font tính được (font-family, font-weight, font-style) của các phần tử có chữ, ở Shell và từng DC. Cùng face + cùng file + cùng descriptor, VÀ cùng bề rộng và chiều cao chuỗi mẫu đo bằng span (Bước F f, trùng tới 0,01 px) thì hiển thị trùng. Chỉ áp cho trang mà Bước P dự báo KHÔNG đổi. Trang trong danh sách P: 3b đo trước/sau chuỗi mẫu để định lượng, chạy lại 6 Q-lab (đạt quy tắc 3 tầng, không đòi trùng trước), người dùng duyệt riêng sau khi xem tay. Baseline bố cục 29 DC và thời gian tải KHÔNG còn là tiêu chí cho 3b; kèm theo người dùng xem tay Shell và 2 DC. Không đo "tải xong" ở lượt này. Trang ngoài P mà 3b thấy khác: xử lý như trang trong P, ghi "P dự báo sai". Khối chữ nghiêng chuyển nguyên trạng (kể cả size-adjust) là giữ hiện trạng; bỏ size-adjust ở chữ nghiêng thuộc L1-F-02.
4. **L1-T-04:** script bản 1 bị bỏ. Viết script mới (Bước F) theo QĐ 3; không cần bản 2 của m3a-perf/m3a-baseline.
5. Sửa `H.stable`/bộ hồi quy (nếu Bước B cho thấy cần) là việc riêng (**L1-T-06**), KHÔNG chặn bỏ font trùng; cần cho Remix (3c) và định dạng số (L1-21a-4).
6. "35/6" trên `.before`: chỉ tham khảo (nguồn: khối chữ dán ở chat lần 3). Từ nay mọi khối chữ của nút mà người dùng dán được lưu nguyên văn vào `_audit/user-run-<tên lượt>.txt`.

### Duyệt L1-21a-3a-2 (03/10/2026, ghi ở L1-21a-3b)
1. **Phương án 3b-tối-giản**, THAY phần "bỏ nạp inter.css" của QĐ2 lượt 3a-2: 19 trang (gồm Shell) có cả Google Inter lẫn `inter.css`, Google thắng cho chữ thường; 10 trang chỉ có `inter.css` nên chữ rộng hơn ~4–4,5 %. Mục tiêu 3b: NHẤT QUÁN — đưa 10 trang đó về đúng trạng thái của 19 trang.
   a) URL CHUẨN = URL link Google Inter hiện tại của Shell, thêm weight 800 nếu chưa có (giữ mọi tham số khác, weight tăng dần). Trước khi sửa: fetch phải trả 200 và có face 800; không đạt → không sửa, DỪNG.
   b) Shell: đổi link Google Inter thành URL CHUẨN (Shell tự khai 800).
   c) 10 trang NHÓM Đ (Cài đặt - Đề xuất, Đăng nhập nhanh, Email Settings, Floating Contact, Hộp duyệt, Language Context, Nâng cấp, Quy trình duyệt, Trạng thái duyệt, Trình soạn): thêm 1 link URL CHUẨN ở đúng chỗ trang đang khai link `styles.css`/`inter.css`, đặt SAU các link đó (theo thứ tự `document.styleSheets` lúc chạy).
   d) Giữ `inter.css` ở mọi trang đang có. Bỏ hẳn `inter.css` chuyển vào L1-F-04 (cùng việc tự host Google).
   e) Không file nào khác bị sửa.
2. **Tiêu chí lượt 3b** (thay QĐ3 lượt 3a-2): Shell không đổi hiển thị (tập face khai báo như TẬP trùng trước, chỉ thêm face 800 của URL CHUẨN; tập 'loaded' trùng ngoài 800; font tính được trùng; CHUỖI A trùng 0,01 px ở các weight Shell đã có face Google; 800 chỉ ghi; RÚT GỌN không khác ngoài 3 phép L1-T-06; 6 Q-lab như Bước 1); NHÓM Đ: CHUỖI A mỗi weight = Shell SAU tới 0,01 px, chữ nghiêng không đổi, ghi chênh CHUỖI ĐỦ / đổi dòng / tràn mới (không tự sửa bố cục); lối ra "không kết luận được (khác CSS)" theo điều kiện của lệnh; trang không ổn định ở 3a-2: chỉ áp CHUỖI A, phần phụ thuộc nội dung ghi "không kết luận được"; mọi file khác trùng hash Bước 0.
3. Link font do helmet DC con gắn vào Shell: không xử lý (mở **L1-F-05**).
4. Sau khi ĐẠT: ghi quy ước vào CLAUDE.md (sao lưu `.pre-3b` trước), chỉ các cơ chế đã đo.
5. L1-T-06 sửa ở lượt riêng ngay sau 3b, trước 3c.

### Duyệt L1-21a-3b — làm rõ (03/10/2026, chỉ đọc + ghi AUDIT.md)
**Ghi nhận**
- **Cách đo chiều cao span** [ĐỌC FILE]:
  - `m3b.js` dòng 19 (nguyên văn phần đầu): `sp.style.cssText = 'position:absolute;left:-10000px;top:0;display:inline;font-family:' + fam + ';font-weight:' + wt + …`; dòng 20: `d.documentElement.appendChild(sp); var r = sp.getBoundingClientRect()`. Span có `position:absolute` → `display` tính được thành `block` → chiều cao span = chiều cao dòng (line box), `line-height` kế thừa từ `html`.
  - `line-height` tính được của `html`: Shell **21px** (AUDIT.md 5.L1-21a-3b Bước S "html/body `line-height: 21px`"; `face-L1-21a3b-before.json` Shell.body.line-height 21px) → chiều cao 21 **không phụ thuộc font**. Nâng cấp nội dung, Trình soạn nội dung: **normal** (`face-L1-21a3b-before.json` "html line-height":"normal") → chiều cao phụ thuộc metric font chính, **không so được với Shell**.
  - → Bằng chứng "đồng nhất với Shell" của 3b **chỉ dựa trên bề rộng** CHUỖI A (vẫn hợp lệ). Ở 8 trang có `html` 21px, "chiều cao trùng" không chứng minh gì về font.
  - `f3a2-face.js` dòng 69 đo **khác cách**: `sp.style.cssText = 'display:inline;white-space:nowrap;font-family:' + q[0] + …` + `d.body.appendChild(sp)`, không có position → hộp inline, chiều cao = vùng nội dung của font (không theo line-height). → Không ghi điều tương tự cho 3a-2.
  - Mở **L1-T-07**: sửa cách đo chiều cao trong `m3b.js` (span không absolute, hoặc ghi riêng chiều cao vùng nội dung) ở lượt có đóng băng lại script. Không sửa lượt này.
- Mở **L1-C-09**: `Nâng cấp nội dung` và `Trình soạn nội dung` không link `styles.css` [ĐỌC FILE helmet 2 file] → thiếu các quy tắc của `styles.css`: `html, body { font-family: var(--font-stack); font-size: var(--text-body-size); line-height: var(--text-body-lh); color: var(--color-body-text); -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; text-rendering: optimizeLegibility; font-synthesis: none; }`, `a { … }`, `a:hover { … }` (+ `@import` tokens: Nâng cấp tự link colors/typography/shape; Trình soạn chỉ link colors). Đây là lý do 2 trang có line-height `normal`, `font-synthesis: weight style small-caps`, `text-rendering: auto`. Không sửa lúc này.

**Trả lời**
1. **"38 file"** [ĐO-ẨN, nguồn: lần băm sau sửa 04:29 UTC, "Nhật ký sửa 3b lần 1"]. Lần băm so **51 mục** đã băm ở Bước 0: 41 file sản phẩm + 8 file audit (6 đóng băng, `f3a2-face.js`, `user-run.js`) + `CLAUDE.md` + `styles.css`. Kết quả: đúng 11 mục khác (11 file đã sửa), 40 mục trùng. "38" = 49 − 11, đã bỏ quên `CLAUDE.md` và `styles.css` (cũng trùng lúc đó) → ghi sai cách đếm. **30 file sản phẩm không sửa trùng hash Bước 0**: `Inter-italic.woff2`, `Inter-roman.woff2`, `inter.css`, `admin-base.css`, `ai-quota.js`, `block-library.js`, `content-seo.js`, `content-ui.js`, `content-workflow.js`, `image-slot.js`, `platform-config.js`, `support.js` (12 file khác) + AI Writer Panel v2, Block Library, Cài đặt nội dung AI, Cài đặt xuất bản tự động, Duyệt & xuất bản, Hạn ngạch AI, Hàng đợi ảnh, Image Picker, Integrations, Kế hoạch SEO, MediaPicker, Page Builder v3, Page Editor, Quản lý Bài viết, Quản lý Trang, Trình đơn, Trợ lý viết v2, Vận hành (18 trang). Lưu ý [ĐỌC FILE]: cột "không đổi" của bảng hash sau 3b chép lại giá trị bảng 3a, dựa trên lần băm này — không phải lần băm thứ hai độc lập.
2. **"Chữ thấp hơn 1,6 px"** [ĐO-ẨN, `face-L1-21a3b-before.json` NHÓM_Đ.Nâng cấp/Trình soạn.A = [786.78, **18.4**] (400) · [797.92, 18.4] (600); `face-L1-21a3b-after.json` "cao **16.8**"]. Đây là **chiều cao span m3b** (line box với `line-height: normal`, xem Ghi nhận), trước 18,4 → sau 16,8 = −1,6 px. Không phải cỡ chữ hay chiều cao glyph. Câu "chữ thấp hơn 1,6 px" trong báo cáo diễn đạt sai. Ảnh hưởng thật: dòng chữ `line-height: normal` ở 2 trang này có thể thấp hơn mỗi dòng [SUY LUẬN].
3. **Số lần đo** [ĐỌC FILE `f3a2-face.js` hàm `FF.go`: mỗi trang luôn đo **2 lần** (r1, r2) trong một lần gọi; `m3b.js` `M3.go(…, 2)`]. Bước 3 đã gọi `FF.go` + `M3.go(…,2)` cho Shell và cả 10 trang → mỗi trang NHÓM Đ đo 2 lần ngay từ đầu, **lệch prompt** ("mỗi trang NHÓM Đ 1 lần, lần 2 chỉ ở trang chưa đạt"). Sau đó gọi `FF.go('a3', [Shell, Đăng nhập nhanh])` → thêm 2 lần mỗi trang.
   - Shell: "lần 3 và 4" là r1, r2 của lần gọi `a3`; lần 4 không phải quyết định riêng. sau-1 (a1 r1): khác trước ở face nghiêng `inter.css` 'loaded' · sau-2 (a1 r2): trùng trước · sau-3, sau-4 (a3 r1, r2): trùng trước.
   - Đăng nhập nhanh: sau-1 **không trượt tiêu chí nào** (CHUỖI A = Shell, nguồn Google). Đo thêm vì r1 ≠ r2 của f3a2, **không có căn cứ trong prompt** cho trang không trượt. Kết quả: sau-1 có `inter.css` khai báo (roman + nghiêng, unloaded ×2), bảng đếm 400:13 · 500:3 · 600:14 · 700:3 · sau-2 **không có `inter.css`**, bảng đếm 600:11 · 700:6 · sau-3, sau-4 trùng nhau, có `inter.css` (×1), bảng đếm = sau-1. CHUỖI A cả 4 lần = Shell (m3b 2 lần trùng).
4. **Trang NHÓM Đ có 2 lần đo TRƯỚC khác nhau**: Bước 1 lượt 3b = **0 trang** [ĐO-ẨN, `face-L1-21a3b-before.json` ghiChú "Mọi trang: 2 lần f3a2 trùng…"]. Theo dữ liệu 3a-2 (prompt 3b, QĐ2 dẫn "3a-2 ghi 4 trang không ổn định"), trong NHÓM Đ có **Cài đặt - Đề xuất giao diện** [ĐO-ẨN, 5.L1-21a-3a-2 Bước F]. **Quyết định bổ sung của lượt này** cho trang đó: CHUỖI A giữ kết quả đạt; phép span chữ nghiêng giữ nguyên (= trước, 789,64); font tính được, 8 phần tử đổi dòng, tràn → **"không kết luận được"**; trạng thái face nghiêng → **"không kết luận được"**. Dòng cũ của 3b không sửa.
5. Mục báo cáo chưa nêu:
   - (a) Link 800 của Integrations [ĐO-ẨN, before.json Shell.sheets "có ngay lúc nạp ở cả 2 lần"; AUDIT Bước S]: Bước 1 có **ngay khi `H.openShell` trả về**, không cần điều hướng (m3b không điều hướng). Lần chạy thử 1 (04:21): chưa có lúc trả về, có sau khi chờ. "Lúc nạp" = sau `H.stable`, khi Shell mới có 766/3.038 phần tử (L1-T-06). [ĐỌC FILE Shell dòng 2611–2618: mọi panel nhúng luôn được mount, chỉ ẩn bằng `display:none`].
   - (b) 3 phép L1-T-06 [ĐO-ẨN, before.json digest; after: so digest]: `00-open` af2037138781 · `Z-b-hash-profile` 7266ec838e34 · `Z-b-hash-settings` 87f17238171b — sau = trước (không nằm trong danh sách khác). `00-open` sau lần chạy lại cũng trùng. Digest sau không lưu riêng vào JSON.
   - (c) U+1EC7 và font dự phòng [ĐO-ẨN, phiên đo 04:29–04:34; after.json ghi cho Shell và Cài đặt - Đề xuất; AUDIT Bước 3]: sau `fonts.load`, mọi weight chữ thường đang dùng của Shell + 10 trang có FontFace Inter 'loaded' phủ U+1EC7, nhãn "Google". Nhãn Google/`inter.css` của m3b suy theo `sizeAdjust` ≠ 100% [ĐỌC FILE m3b.js]. "Không rơi về font dự phòng" kết luận từ f3a2: mọi bộ (Inter, weight, normal) đang dùng có face Inter 'loaded' (Google) [SUY LUẬN theo cách của 3a-2 Bước F d]. Kết quả từng trang ngoài 2 trang trên chưa lưu vào file.
   - (d) `m3b.js` đóng băng [ĐỌC FILE AUDIT 5.L1-21a-3b bảng đầu]: 9.884 B · 9d888ab2f112…, ghi vào AUDIT trước lệnh khởi chạy Bước 1 (Bước 1 bắt đầu 04:23:48 UTC; lệnh khởi chạy in hash bản phục vụ 9d888ab2f112). Thời điểm ghi dòng đóng băng không được ghi.
   - (e) File tên có dấu đã sửa: bảng ánh xạ tên gốc ↔ `.pre-3b` ở mục "Sao lưu `.pre-3b`" [ĐỌC FILE]. Liệt kê thư mục gốc sau sửa: 31 file `.dc.html`, mỗi tên có dấu 1 file [ĐO]. Kiểm NFC sau sửa: **chưa có dữ liệu** (không chạy lại `normalize` sau sửa; tìm trong AUDIT 5.L1-21a-3b và 2 file JSON 3b).
   - (f) Shell: kết luận "font tính được trùng", "tập 'loaded' trùng" dùng **sau-2, sau-3, sau-4** [ĐO-ẨN, after.json Shell.lầnĐo]. Sau-1 có thêm face nghiêng `inter.css` 'loaded', nghĩa là sau-1 **không đạt** tiêu chí "tập 'loaded' trùng trước" nếu xét riêng. Tôi coi đó là dao động theo thời điểm (prompt cho đo lần 3 khi 2 lần SAU khác nhau; lần 3 trùng lần 2 và trùng trước) → không tính trượt. Đây là cách hiểu của tôi, cần anh xác nhận. Dữ liệu hỗ trợ: trước khi sửa, face nghiêng này cũng từng 'loaded' trong Shell (3a Bước II a: `Inter-italic.woff2` được tải) và từng unloaded (3a-2, Bước 1 lượt 3b) [ĐO-ẨN].
8. **CLAUDE.md** — nguyên văn (§8, dòng 203–208):
```
- **Font Inter (chốt L1-21a-3b, 03/10/2026)**: chữ thường Inter dùng Google qua đúng URL CHUẨN
  `https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap`. Trang mới phải có link URL CHUẨN
  trong helmet, đặt **sau** link `fonts/inter.css` / `styles.css` của design system. Giữ `inter.css` (chữ nghiêng hiện lấy từ
  khối nghiêng của file này; Google URL CHUẨN không có chữ nghiêng). Lý do: `inter.css` có `size-adjust: 105%` + ascent/descent
  override, trang chỉ có `inter.css` rộng hơn trang dùng Google ~4–4,5 %. Ký tự `→` (U+2192) nằm ngoài dải của cả hai nguồn
  nên vẽ bằng font dự phòng. Bỏ hẳn `inter.css` / tự host Google: việc L1-F-04, chưa làm.
```
   Câu chưa có số đo chứng minh đầy đủ:
   - "chữ nghiêng hiện lấy từ khối nghiêng của file này": chỉ đo được ở Cài đặt - Đề xuất (3b) và Page Editor lần 1 (3a-2). **Page Builder v3 dùng nghiêng giả từ Google** (3a-2 Bước F) → câu không đúng cho mọi trang.
   - "Lý do: `inter.css` có `size-adjust: 105%` + ascent/descent override": các descriptor đọc từ file [ĐỌC FILE]; chênh bề rộng đo được, nhưng **chưa tách** được phần do size-adjust và phần do khác file font (`Inter-roman.woff2` ≠ file Google) [SUY LUẬN].
   - "~4–4,5 %": CHUỖI ĐỦ 4,03–4,49 % (400–800) [ĐO-ẨN]; **CHUỖI A ở 800: 815,23 / 778,66 = 4,70 %**, vượt 4,5 %.
   - Có số đo: URL CHUẨN (200, có 800, không có nghiêng); link đặt sau → Google thắng (10 trang); → vẽ bằng font dự phòng (span = dự phòng, trước/sau).
9. **Inter-italic.woff2 ở NHÓM Đ**: dữ liệu theo trang có ở 3a-2 [ĐO-ẨN, `face-L1-21a3a2.json` pages.*.perf]: **Cài đặt - Đề xuất giao diện** (2 entry, kiểm lại cache 300 B, thân 0) và **Quy trình duyệt** (thân **387.976 B**, tải thật dù trang không có chữ nghiêng). 8 trang còn lại: không có entry. Lượt 3b: **chưa có dữ liệu** theo trang (perf của Bước 1/3 chỉ lưu tổng số entry woff2, không tách tên file; tìm trong 2 file JSON 3b và AUDIT 5.L1-21a-3b). Lợi ích byte của 3b không gồm file nghiêng (`inter.css` vẫn nạp).

6. **Thứ tự `document.styleSheets`** — JSON Bước 1/3 chỉ ghi 1 thứ tự mỗi trang (không phải mọi lần) → đo lại [ĐO-ẨN, 05:20–05:21 UTC, lớp ẩn bật; `f3a2-face.js` v4 đóng băng, `FF.wait`, trần 15 s]: mỗi trang 5 lần nạp qua `H.iso`, cả 5 lần `wait.ok = true`.
   - Nâng cấp nội dung: 5/5 lần `6:inter.css 7:G URL CHUẨN` (139 phần tử).
   - Trạng thái duyệt: 5/5 lần `5:inter.css 9:styles.css 10:G URL CHUẨN` (161 phần tử).
   - **Không lần nào link URL CHUẨN đứng trước `inter.css`.**
   Lệnh (nguyên văn):
```
window.__Q6=null;(async()=>{const ld=async f=>(0,eval)(await (await fetch(f,{cache:'no-store'})).text());if(!window.HM)await ld('hidden-mode.js');HM.enable();if(!window.FF)await ld('f3a2-face.js');const map=d=>[].map.call(d.styleSheets,(s,i)=>{const h=s.href||'';return /fonts\.googleapis/.test(h)?i+':G:'+(h.match(/wght@([\d;]+)/)||[])[1]:/fonts\/inter\.css/.test(h)?i+':inter.css':/styles\.css/.test(h)?i+':styles.css':null}).filter(Boolean).join(' ');const R={};for(const n of ['Nâng cấp nội dung.dc.html','Trạng thái duyệt (khối soạn thảo).dc.html']){R[n]=[];for(let k=0;k<5;k++){const fr=await H.iso('b',1440,900,'../'+encodeURIComponent(n));const t=performance.now();while(!fr.__loaded&&performance.now()-t<20000)await FF.sleep(100);const w=await FF.wait(fr,performance.now(),false);R[n].push([w.ok,w.nEls,map(fr.contentDocument)]);}}document.getElementById('b')&&document.getElementById('b').remove();window.__Q6={R,ff:FF.ver,t:new Date().toISOString(),vis:document.visibilityState}})().catch(e=>window.__Q6={err:String(e)});'s'
```
7. **NHÓM Đ là DC con mở trong Shell** — 3 trang: Email Settings (mục `email`), Đăng nhập nhanh (`social`), Floating Contact (`floating`). [ĐỌC FILE Shell dòng 2612–2618: cả 3 panel luôn được mount, chỉ ẩn bằng `display:none`.] Đo [ĐO-ẨN, 05:21 UTC]: `H.openShell(1440, 900)` → `FF.wait` → mốc; rồi điều hướng như bộ hồi quy (`H.st(H.A({activeMenu:'settings', settingsSection:k}), 900)` → `FF.wait`) lần lượt 3 mục.
   - Mốc: 69 FontFace khai báo, 39 face khác nhau (như TẬP). Link font trong `<head>` theo thứ tự: `1:G URL CHUẨN · 3:inter.css · 7:styles.css · 8:G 400–700`. Link URL CHUẨN chỉ có **1 thẻ**.
   - Mở `email`, `social`, `floating`: mỗi bước 69 / 39, **0 face thêm, 0 face bớt** so với mốc và với bước trước; danh sách link không đổi.
   - Link do helmet của 3 trang gắn vào đúng URL CHUẨN, nhưng runtime gộp theo URL → trùng thẻ link của Shell (và của Integrations, cũng là URL này). **Không phân biệt được** thẻ nào do helmet gắn. Link `8:G 400–700` là của DC con khác (Hạn ngạch AI, Trình đơn) [SUY LUẬN từ 3a-2 Bước F].
   Lệnh (nguyên văn):
```
window.__Q7=null;(async()=>{const links=d=>[].map.call(d.querySelectorAll('head link[rel="stylesheet"]'),(l,i)=>{const h=l.getAttribute('href')||'';return /fonts\.googleapis/.test(h)?i+':G:'+(h.match(/wght@([\d;]+)/)||[])[1]+(h===M3URL?'(CHUẨN)':''):/fonts\/inter\.css/.test(h)?i+':inter.css':/styles\.css/.test(h)?i+':styles.css':null}).filter(Boolean).join(' ');const M3URL='https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap';
const snap=async fr=>{const d=fr.contentDocument;const decl=await FF.decl(d),faces=FF.faces(d,decl).filter(f=>!f.inj);return {set:[...new Set(faces.map(f=>FF.fid(f)))].sort((a,b)=>a-b),n:faces.length,links:links(d),nG800:d.querySelectorAll('link[href="'+M3URL+'"]').length}};
const fd=i=>{const f=FF.FACES[i];return f[0]+'/'+f[1]+'/'+f[2]+'/'+String(f[12]||'').slice(-18)};const df=(a,b)=>({them:b.set.filter(x=>!a.set.includes(x)).map(fd),bot:a.set.filter(x=>!b.set.includes(x)).map(fd)});
const fr=await H.openShell(1440,900);await FF.wait(fr,performance.now(),true);const base=await snap(fr);const out={base:{n:base.n,nSet:base.set.length,links:base.links,nCHUAN:base.nG800}},steps=[];let prev=base;
for(const k of ['email','social','floating']){await H.st(H.A({activeMenu:'settings',settingsSection:k}),900);await FF.wait(fr,performance.now(),true);const I=H.inst(fr);const s=await snap(fr);steps.push({k,sec:I.state.settingsSection,n:s.n,nSet:s.set.length,links:s.links,nCHUAN:s.nG800,vsBase:df(base,s),vsPrev:df(prev,s)});prev=s;}
out.steps=steps;document.getElementById('s')&&document.getElementById('s').remove();window.__Q7=Object.assign(out,{t:new Date().toISOString(),vis:document.visibilityState})})().catch(e=>window.__Q7={err:String(e)});'s'
```
- **Storage thật**: đầu lượt (05:17:21 UTC) và cuối lượt (05:22:18 UTC) trùng mốc "1a-3b" [ĐO, chỉ đọc]; 0 iframe còn lại.
- **Mã mới**: L1-T-07 (cách đo chiều cao span của m3b) · L1-C-09 (Nâng cấp, Trình soạn thiếu `styles.css`).
- **Kết luận làm rõ**: không có điểm đo nào trượt. Còn 1 điểm cần anh xác nhận: cách hiểu ở câu 5(f) (Shell sau-1 có face nghiêng 'loaded'). Có 2 chỗ lệch prompt về số lần đo (câu 3). Có 3 câu trong CLAUDE.md chưa đủ số đo chứng minh (câu 8).

### Duyệt L1-21a-3b — quyết định cho L1-T-06 (03/10/2026, ghi ở T06)
1. **Câu 5(f): CHẤP NHẬN** theo cách hiểu "vẫn khác" = lần đo 3 khác lần 2. Ở 3b, lần 3–4 trùng lần 2 và trùng TRƯỚC → Shell đạt; 3b giữ "ĐẠT về đo". NHÓM Đ vẫn chờ duyệt sau xem tay — T06 không đóng việc duyệt. **Luật cho các lượt sau:** 2 lần đo SAU khác nhau → chạy lại 1 lệnh; chỉ ĐẠT nếu cả 2 lần đo của lệnh chạy lại (f3a2 luôn đo 2 lần) trùng TRƯỚC ở mọi tiêu chí VÀ lần lệch chỉ khác ở trạng thái 'loaded' của face (tập khai báo, font tính được, bề rộng CHUỖI A, số dòng, tràn đều trùng); ghi "dao động loaded: k/n lần"; mọi khác biệt khác → "không ổn định". Không đổi tiêu chí sau khi đã thấy kết quả.
2. **Face nghiêng `inter.css` 'loaded' ở Shell, lượt 3b** [ĐỌC FILE JSON]: SAU **1/4** lần (`face-L1-21a3b-after.json` Shell.lầnĐo: sau1 loaded, sau2–4 trùng TRƯỚC); TRƯỚC **0/2** lần [SUY RA: before.json "2 lần f3a2 trùng" + after "sau2 (unloaded) trùng TRƯỚC ở tập loaded"]. Khớp dự kiến. Nguyên nhân chưa xác định (nghi L1-T-06).
3. **Lệch thủ tục 3b** (không đổi kết luận): "38 file" đếm sai (đúng 30); f3a2 đo mỗi trang NHÓM Đ 2 lần ở Bước 3 (prompt cho 1); đo thêm Đăng nhập nhanh ngoài phép. Từ nay prompt tính f3a2 luôn đo 2 lần mỗi lệnh.
4. **Nếu sau này không duyệt 3b:** hoàn tác 11 file và CLAUDE.md từ `.pre-3b` (CLAUDE.md mất luôn phần sửa của T06); khi đó `regress-L1-T06.json` hết hiệu lực làm mốc cho 3c. JSON mốc cũ (k0, pre/post-c04, 3b) đo bằng H.stable cũ — k0, pre/post-c04: **(bản thường)** (lần 4 L1-21a-2, chế độ thường); 3b: **(bản ẩn)** — không so trực tiếp với số đo bằng H.stable mới.
5. **Hướng (b) có điều chỉnh:** CHỈ MỘT bản `H.stable`, đặt trong `harness.html`, chạy đúng ở cả chế độ thường và ẩn; `hidden-mode.js` gỡ mọi dòng gán/lưu/khôi phục `H.stable`, được thêm ≤ 2 lối xuất. Ghi nhận (xác nhận lại ở B(a)): 3a, 3a-2, 3b chờ bằng bản trong `hidden-mode.js` (chỉ toast) rồi `FF.wait`; nút ở chế độ thường chờ bằng bản trong `harness.html` (fonts.ready đọc một lần + toast, trần 6 s). Không sửa/đo lại số cũ; các nhận định "do L1-T-06" giữ là nghi vấn.

### Duyệt L1-T-06 (03/10/2026, ghi ở T08 sau phần CÂU)
1. **Chấp nhận B, C, D của T06. A: chấp nhận** (câu 4: mọi số % lấy từ lần đo face 'loaded'; không câu nào sai số đo). Thiếu sót của T06 (không đổi kết luận): "ĐANG SỬA T06" ghi muộn ~1 phút — từ nay dòng "ĐANG SỬA" ghi trước mọi sao lưu hay sửa; D(a) chưa ghi số lần nạp thật và nguồn 800; B(d) 2 trang NHÓM Đ chưa ghi thời gian trả về, cờ, đọc lại sau 3 s _(đính chính [ĐỌC FILE]: bảng B(d) 5.L1-T-06 có ghi 827 / 770 ms, không cờ, đọc lại = — ghi theo QĐ, không sửa)_; so RÚT GỌN với post-c04 chỉ để tham khảo; RÚT GỌN bằng H.stable cũ cũng 41/41 dù H.stable cũ trả về ở 768 phần tử → bộ RÚT GỌN không phát hiện được L1-T-06 trong phiên T06; bằng chứng sửa L1-T-06 là phép nạp B(d).
2. **L1-T-06** = "đóng ở chế độ thường (theo B(d) lượt T06, khung visible), chế độ ẩn thật chưa kiểm". Luật kiểm ẩn (một lần, lượt đầu có `hidden`): sau `HM.enable()` nạp Shell 1 lần, N = số phần tử (trừ toast) ở giây 15; nạp 3 lần, mỗi lần trả về ≥ N − 30, không cờ, đọc lại sau ≥ 3.000 ms không đổi, log 'ẩn' → "đóng ở chế độ ẩn"; không đạt → mở lại. _(Kết quả: đạt ở lượt T08, xem 5.L1-T-08.)_
3. **Bỏ bước bấm nút xác nhận T06: ĐỦ ĐIỀU KIỆN** — câu 6: `user-run.js` đi qua `H.openShell` → `H.stable` (harness con nạp `harness.html` mới); log phiên T06 không còn, nhưng AUDIT ghi khung visible suốt lượt T06 (0 sự kiện ẩn mỗi bước).
4. **Tham số `max`: chấp nhận bỏ qua** — câu 2: max lớn nhất 6.000 (hằng số), không chỗ nào > 15.000.
5. Ghi `devicePixelRatio` cùng mọi số đo chiều cao từ nay; chỉ so chiều cao khi cùng máy, cùng DPR. (T06 D(c) và C: DPR 1,25 [SUY RA: cùng máy, cùng ngày; đo ở T08].)

T08-QĐ XONG

### Duyệt L1-T-08 (03/10/2026, ghi ở T10 sau Bước 0)
1. **Ghi nhận lượt T08:** L1-T-06 đóng ở cả 2 chế độ (kiểm ẩn chạy khi khung chuyển `hidden` giữa lượt, không ở Bước 0 như prompt — chấp nhận, bằng chứng [ĐO-ẨN] hợp lệ).
   - **Lệnh tạm chặn `H.openShell` lúc dừng T08** (nguyên văn, lấy từ nhật ký lệnh của lượt T08; không chạm storage): (1) `window.__ABORT=Math.round(performance.now());const f=H.fr('s');if(f)f.remove();setTimeout(()=>{},0);JSON.stringify({vis:document.visibilityState,removed:!!f})` · (2) `if(!window.__OS0){window.__OS0=H.openShell;H.openShell=async function(){throw new Error('T08-b huỷ (khung ẩn)')}};'ok'` · (3) khôi phục, đầu lệnh kiểm ẩn: `H.openShell=window.__OS0;window.__OS0=null;…`.
   - Harness đã nạp lại sau T08 [ĐO, Bước 0 T10]: `performance.timeOrigin` của harness = 1791018660539 (≈ 09:11:00 UTC, theo mốc sự kiện `H.VIS` 1791018208039 ≈ 09:03:28 của T08) > mốc cuối T08 (09:08:20 UTC); `HM` chưa có, `H.stable.log` rỗng → **không còn kiểm được việc khôi phục; không ảnh hưởng đo vì harness đã nạp lại.** (`H.openShell.toString()` ở Bước 0 T10 trùng mã trong `harness.html`.) Từ nay chỉ bọc/chặn hàm trong bộ nhớ khi prompt cho phép.
   - **Sửa dòng "thiếu sót B(d)" trong QĐ1 lượt T08:** B(d) T06 đã ghi thời gian trả về (827 / 770 ms), cờ (không) **và có ghi "đọc lại sau 3 s"** (1.505 · 8 · loaded; 320 · 8 · loaded) [ĐỌC FILE bảng B(d) 5.L1-T-06] → không phải thiếu sót.
2. **Face nghiêng:** ở đường Shell, f3a2 không trực tiếp yêu cầu face nghiêng [ĐỌC CODE, a lượt T08]; đường DC đọc ở V5(a); `FF.wait` ép bố cục nên có thể ảnh hưởng thời điểm trang yêu cầu font [SUY LUẬN]; nguồn yêu cầu chưa xác định. Mẫu "chỉ r1 của lệnh 1 và 2" chỉ dựa trên các lần đã ghi (8 lần còn lại chưa có trạng thái).
3. **L1-T-08 → "THEO DÕI"**: không chạy thí nghiệm riêng; ghi thụ động qua f3a2-v5. Luật dao động của QĐ1 T06 vẫn áp dụng.
4. **L1-T-10:** f3a2 đo DC qua H.stable (bản 5 là file mới), trước 3c — vì bản 4 mở DC bằng `H.iso` + `__loaded` + `FF.wait`, không chờ link stylesheet hay toast [ĐỌC CODE dòng 50, 52]; Đăng nhập nhanh từng có lần đo thiếu `inter.css`; 3c sẽ đổi stylesheet Remix. Từ khi f3a2-v5 ĐẠT: mọi phép đo face dùng v5; bản 4 chỉ để tham chiếu; kết quả DC trong JSON cũ không so trực tiếp với v5; 3c phải đo mốc "trước" bằng v5, cùng phiên, cho mọi DC sẽ so.

T10-QĐ XONG

### Quy tắc thường trực L1-21
  QUY TẮC THƯỜNG TRỰC
  Q1. Storage thật (localStorage, sessionStorage): không ghi, không xoá; chỉ đọc. Khoá dự án = bs-scheme, bs-ui (theo kiểm kê 1b). Đầu mỗi lượt đọc storage thật, ghi thành mốc của lượt (khoá dự án: đủ giá trị; khoá khác: tên + độ dài + hash). Mốc của lượt khác mốc lượt trước: chỉ ghi lại, không dừng. Sau mỗi lô đọc lại và so với mốc của lượt:
      - khác ở khoá dự án: không ghi lại gì; chép cả hai bản vào AUDIT.md; ngừng mọi phép kiểm; xử lý theo ĐIỂM DỪNG của lượt; báo rồi DỪNG lượt;
      - khác ở khoá khác: ghi tên + độ dài + hash cả hai bản, tiếp tục.
  Q2. Mọi phép đo trên Shell và DC qua harness đã cách ly. Đầu mỗi lần chạy kiểm:
      [M] window.__mock === true ở trang được đo và mọi iframe DC con; bản giả ghi nhật ký mọi truy cập; mọi lượt đọc khoá dự án nằm trong nhật ký và trả giá trị của bản giả; khoá đọc trong script chạy trước DOMContentLoaded (như bs-scheme ở script helmet) thì lượt đọc đầu phải nằm trước DOMContentLoaded của chính tài liệu đó; khoá đọc sau đó (như bs-ui trong constructor) chỉ cần có trong nhật ký và nhận giá trị của bản giả; xác định "không mang giá trị thật" qua nhật ký.
      [C] admin-base.css đã nạp.
      [U] location.pathname, search và hash đúng kỳ vọng của lần chạy.
      Hỏng: thử lại tối đa 2 lần. Vẫn hỏng [M] trên Shell: không chạy phép kiểm nào trên Shell, xử lý theo ĐIỂM DỪNG, báo, DỪNG lượt. Hỏng [C]: mọi phép kiểm của lần chạy đó = KHÔNG ĐO ĐƯỢC, tiếp tục. Hỏng [U]: chỉ phép kiểm phụ thuộc URL = KHÔNG ĐO ĐƯỢC.
  Q3. Điều hướng: harness chặn mọi điều hướng iframe sang tài liệu mới (đổi hash thì cho phép). Không chặn được thì không chạy phép kiểm nào có thể bấm vào chỗ code đặt location.href. Nếu vẫn có tài liệu được nạp không qua cách ly: ngừng lô, đọc ngay storage thật và so với mốc của lượt; giống thì lô đó = KHÔNG ĐO ĐƯỢC, nạp lại harness rồi tiếp; khác ở khoá dự án thì theo Q1.
  Q4. Hộp thoại: navGuard hoặc mã nào dùng window.confirm/alert/prompt thì thay bằng hàm giả trong harness và ghi lại; không để hộp thoại thật xuất hiện.
  Q5. Thời gian: mỗi lệnh JS < 6 giây; phép đo nặng chạy bất đồng bộ trong trang, chia lô, nhường luồng sau mỗi lô ≤ 1 giây. Chỉ số hiệu năng quá thời gian: ghi ">6s" — số đo hợp lệ. Phép kiểm hồi quy quá thời gian: KHÔNG ĐO ĐƯỢC. Khi quyết định đạt/khôi phục/hoàn tác: KHÔNG ĐO ĐƯỢC không tính là giống hay khác; nếu thuộc tiêu chí đạt của bước thì bước đó KHÔNG ĐẠT.
  Q6. Khung xem bị ẩn: harness ghi mọi sự kiện visibilitychange kèm thời điểm. Đầu mỗi lô kiểm document.visibilityState; 'hidden' thì không chạy, kiểm lại tối đa 3 lần (mỗi lần một lệnh), vẫn ẩn thì dừng ở điểm dừng an toàn gần nhất, báo "khung xem bị ẩn". Lô có sự kiện 'hidden' giữa lúc bắt đầu và kết thúc: cả lô = KHÔNG ĐO ĐƯỢC, chạy lại khi hiện.
  Q7. Kết quả đo: so sánh trước/sau làm TRONG CÙNG một phiên harness, trên bộ nhớ. Nếu harness bị nạp lại giữa chừng (mất bộ nhớ): chỉ đo lại mốc khi file sản phẩm đang khớp hash với bản lúc đo mốc. Nếu file đã bị sửa: chép bản đang sửa thành _audit/backup/<tên file>.wip (theo Q8), khôi phục bản của mốc (theo Q8), đo lại mốc, rồi chép .wip trở lại (theo Q8). KHÔNG BAO GIỜ đo lại mốc trên file đã sửa.
      File _audit/*.json chỉ lưu tóm tắt: với mỗi phép kiểm hoặc mỗi DC một digest, kèm giá trị đầy đủ của các mục có khác biệt. Digest = SHA-256 của JSON.stringify với khoá sắp theo thứ tự chữ cái và số làm tròn 0,1. Harness tạo sẵn chuỗi JSON đó; bạn chép nguyên khối chuỗi vào file, không soạn lại, không làm tròn, không sửa. Sau khi ghi, đọc lại file và so digest với bộ nhớ harness; lệch thì ghi lại 1 lần; vẫn lệch thì ghi "file tóm tắt không khớp" — không ảnh hưởng kết luận (kết luận dựa trên so sánh trong bộ nhớ).
  Q8. Sao lưu/khôi phục chỉ áp cho file sản phẩm (DC, admin-base.css, .js dùng chung): trước khi sửa, chép bản gốc thành _audit/backup/<tên file>.before (nếu chưa có); chép nguyên byte bằng công cụ chép file, không gõ lại; sau mỗi lần chép so kích thước byte và hash với nguồn, lệch thì DỪNG. AUDIT.md và _audit/ không bao giờ bị khôi phục.
  Q9. Đóng băng harness: kể từ khi một lượt ghi "harness đóng băng" kèm hash, không đổi phần cách ly (Q2–Q4, Q6), H.check, H.stable và các phép kiểm của bộ hồi quy. Được THÊM script chỉ đọc cho phép kiểm mới, nhưng script thêm phải nằm ở FILE RIÊNG, nạp bằng lệnh JS lúc chạy, không sửa file đã đóng băng; ghi tên, kích thước byte và hash của file mới vào danh sách đóng băng trong AUDIT.md. Đầu mỗi lượt so hash từng file trong danh sách; file đóng băng nào khác hash đã ghi: báo, không đo.
  Q10. Nhãn bằng chứng [ĐO] / [ĐỌC CODE] / [SUY LUẬN] / [CHƯA KIỂM]. Theo mục 6 trong 4f. Gặp chỗ phải đổi hành vi mới làm được thì DỪNG, đưa phương án.
  Q11. Không làm khi người dùng đang thao tác trên Shell; người dùng giữ khung xem hiển thị trong lúc chạy.

### Việc tồn đọng
| Việc | Nguồn | Khi nào |
|---|---|---|
| Thiết kế màn hình cho tenant SaaS (menu, Cài đặt gói/thanh toán thuê bao/thành viên, bảng tin) | Q7 phương án B | Khi có bản đồ màn hình tenant SaaS |
| L1-20: đổi cỡ chữ px → rem, đo lại ở 125% và 200% | Duyệt L1 | Task riêng sau L2 |
| L1-21: tách Admin Shell (dữ liệu sang `shell-data.js`, chia `renderVals()`, DC con Settings Form); đo trước/sau bằng `_audit/harness.html` | Duyệt L1, Duyệt Đợt A | Sau Đợt C, **trước** L2 |
| Panel nhúng còn hex cứng `#2050a0` cho nền nút/công tắc/viền, chưa theo màu thương hiệu (L1-A-P01); đầu vào: bảng 5.L1-B mục 1b | Kiểm lại Đợt A | Việc đầu tiên của L2 |
| ~~L1-25: ô màu hiển thị `--brand-solid`~~ — xong Đợt C | Duyệt Đợt A | Đã làm |
| ~~Badge số trên mục đang chọn (L1-24)~~ — xong Đợt B | Duyệt Đợt A | Đã làm |
| Page Editor - Options: 2 chỗ 70px (thanh công cụ top 12px, cột phải top 78px) đổi sang `--sticky-top` khi mount | Duyệt Đợt B | Task mount |
| Ô "Màu riêng": nhập mã màu, tự làm đậm bằng hàm dự phòng, cảnh báo nếu gần màu trạng thái | Duyệt Đợt B | Chưa xếp |
| Page Builder: gợi ý màu phụ tự động từ màu chính của site khách theo quy tắc phối màu (bổ túc / tương đồng / bộ ba), có kiểm tương phản | Duyệt Đợt B | Chưa xếp |
| L1-C-P01: Đăng nhập nhanh & Liên kết TK chưa nhận `accountType='platform'` | Đợt C Bước 4.2 | L2 |

### Duyệt L1-T-10 (03/10/2026, ghi ở X3B sau Bước 0)
1. **Chấp nhận f3a2-v5** với nhận xét: KIỂM chỉ chạy ở chế độ thường, mẫu nhỏ (3 DC × 1 lệnh mỗi bản), bằng chứng lưu (`f3a2-v5-check.json`) là bản tóm tắt AI soạn. Lệch thủ tục chấp nhận: chỗ đọc (i) trong `FF.wait` (dòng 44) không chèn ghi thụ động; thêm điều kiện nạp `ver === 5` (thay đổi thứ 3); JSON kết quả là tóm tắt.
   - Tiêu chí "số phần tử trong log ≥ số ở (2) − 30" [ĐỌC FILE `f3a2-v5-check.json` dòng 10–11, 14–15, 18–19]: `stable.n` / `wait` = Đăng nhập nhanh 131 / 129 (≥ 99) · Nâng cấp 141 / 139 (≥ 109) · Cài đặt - Đề xuất 1.505 / 1.503 (≥ 1.473), cả r1 và r2 → **đạt ở 6/6**, không trượt → ghi chấp nhận. (Số log lớn hơn `FF.wait` 2 phần tử ở cả 3 trang; nguồn chênh chưa xác định, không ảnh hưởng tiêu chí.)
2. [SUY LUẬN] Nhận định "bản 4 đo DC quá sớm (Nâng cấp r1: 7 face 'loaded' so với 10)" có thể do hiệu ứng lần nạp đầu (cache lạnh); n = 1.
3. Từ nay mọi file kết quả kiểm là **dữ liệu thô** (`JSON.stringify` đối tượng kết quả do script trả về); tóm tắt ghi riêng trong AUDIT; file dẫn xuất ghi kèm tên và hash file nguồn.
4. **Cổng chế độ ẩn cho 3c:** trước phép đo đầu tiên ở chế độ ẩn (chế độ có thể đổi giữa lượt) phải chạy f3a2-v5 trên Đăng nhập nhanh × 1 lệnh với đủ tiêu chí KIỂM(b) của lượt T10, kể cả số phần tử; trượt → không dùng số đo ở chế độ ẩn của lượt đó, DỪNG (đã sửa file thì xử lý theo phần hoàn tác của lượt đó). Cả lượt ở chế độ thường → ghi "v5 ở chế độ ẩn chưa kiểm".
5. **L1-T-08 (theo dõi):** Resource Timing có `Inter-italic.woff2` ở cả lần face nghiêng 'unloaded' (Shell, T10) — mạng hay cache chưa phân biệt — dù đối tượng FontFace quan sát được chưa tải. Nguồn chưa xác định (khả năng: stylesheet bị gỡ/gắn lại tạo FontFace mới; face trỏ cùng file nhưng style khác 'italic' hoặc nằm ở sheet khác; tải ngoài FontFace). [SUY LUẬN, chưa đo] chi phí là một lần mỗi khi cache lạnh (cache theo URL), không phải mỗi trang. Gắn vào L1-F-04/L1-F-02; không điều tra riêng.

**Câu hỏi đọc mã — bản 4 tạo lại FF hay gán thêm?** [ĐỌC CODE]
- Bản 4 `f3a2-face.js` dòng 10: `if (window.FF) return; var FF = window.FF = { ver: 4, R: {}, S: {}, FACES: [], FKEY: {}, HASH: {}, CSS: {} };`
- v5 `f3a2-v5.js` dòng 12: `if (window.FF && window.FF.ver === 5) return; var FF = window.FF = { ver: 5, R: {}, S: {}, FACES: [], FKEY: {}, HASH: {}, CSS: {} };`
- → Cả hai **tạo đối tượng mới** (object literal), không gán thêm vào FF đã có. Bản 4 gặp `window.FF` có sẵn thì **không nạp gì** (return). Mọi hàm dùng biến cục bộ `var FF` của IIFE → mỗi bản gắn với đối tượng của chính nó qua closure. Thứ tự T10: bản 4 nạp trước (lúc chưa có FF), v5 nạp sau và thay `window.FF` → bản 4 không thể mang phần còn lại của v5. Phép so bản 4/v5 ở T10 không bị nhiễm qua FF. Còn dùng chung: `window.H`, iframe `'b'` (H.iso thay iframe mỗi lần) và bộ nhớ đệm HTTP của phiên — không thuộc câu hỏi FF.

X3B-QĐ XONG

### Duyệt X3B (03/10/2026, ghi ở X3B2 sau Bước 0)
1. **Đo lại danh sách; BỎ trang so.** X3B-SO BỎ (thay bằng danh sách đo lại). Lý do: việc cần quyết là "trạng thái SAU có chấp nhận được không"; danh sách chỉ ra chỗ cần nhìn.
2. **TRƯỚC = file hiện tại với link Google URL CHUẨN bị tắt** (`link.disabled = true`) trong cùng lần nạp. Hợp lệ VỀ FILE (X3B Bước 0: 10 file `.pre-3b` chỉ khác đúng 1 dòng link URL CHUẨN). NGUỒN FONT kiểm bằng tỉ lệ CHUỖI A ở mỗi lần nạp (SCRIPT bước 3). Tương đương hiển thị không chứng minh được. Hạn chế: thành phần đo chữ lúc mount không đo lại khi tắt link [SUY LUẬN].
3. **Các lượt sau:** ngưỡng số phần tử dùng tỉ lệ **≥ 98%** thay cho −30 (trang nhỏ, −30 quá lỏng).
4. Câu hỏi mở về T10: làm sau phần ĐO nếu còn lượt (mốc riêng "X3B2-QĐ4 XONG").

X3B2-QĐ XONG

---


### Duyệt L1-21a-3b (04/10/2026, ghi ở L1-21a — đóng track)
- 3b ĐƯỢC DUYỆT (owner, sau xem tay 3 chỗ của X3B2). Owner thu hẹp tiêu chí so với X3B2: bỏ xem 10 trang × 2 bề rộng, xem trong Shell và trên máy thật; phần đó do D0-6a/6b/6c rà ở mọi bề rộng, trong Shell và mở riêng.
- NHÓM Đ đóng. L1-F-01 giữ trạng thái đóng.
- 3 chỗ X3B2: Language Context @390 "Trình biên tập" 1→2 dòng; Đăng nhập nhanh @1440 "ẩn hẳn" 1→2 dòng; Nâng cấp nội dung, nút "Đi tới ô đó" cao 17,6→19,2 px. Xử lý tiếp ghi ở TODO.md (mục "Lộ trình D0 — chuyển từ track L1-21a").
- Track L1-21a đóng ở đây. Harness, f3a2-v5, m3b-v3, x3b-lines.js giữ nguyên, đóng băng, chỉ dùng lại khi cần đo hồi quy font. Bảng hash 5.L1-21a-3b hết hiệu lực khi lượt D0 đầu tiên sửa file; đo hồi quy font lần sau phải lập mốc mới.
- Câu hỏi mở T10 (X3B2-QĐ4): phỏng đoán, đóng không kiểm.
- Mã L1-T-* còn mở/theo dõi (L1-T-03, L1-T-08): đóng theo track, chỉ mở lại khi đo hồi quy font.

L1-21a-QĐ XONG

## 5. Kết quả kiểm

### 5.L1 — Admin Shell (kiểm 01/10/2026)

**Tóm tắt: 22 lỗi**
- Theo mức độ: Chặn 0 · Cao 6 · Trung bình 13 · Thấp 3.
- Theo nguyên nhân gốc: token / `admin-base.css` 2 (L1-01, L1-04) · shell 20 · component dùng chung 0 · trang 0.
- Không tìm thấy lỗi tràn ngang trang ở cả 24 tổ hợp bố cục × bề rộng, cũng như ở phóng to 640px và 320px.

**Cách đo:** dùng `_audit/harness.html` mở Admin Shell trong iframe đúng bề rộng, đặt state bằng chính instance logic của Shell, tắt transition rồi đo:
- tương phản WCAG trên màu tính toán, có ghép lớp alpha và opacity; nền gradient tính theo điểm xấu nhất
- tràn khỏi khung nhìn, chữ bị cắt, cỡ chữ < 12px, vùng bấm
- Đối chứng: một phần tử thử `#aaa/#fff` được bắt đúng 2,32:1.

**Phạm vi đã chạy**
- 8 bề rộng × 3 bố cục ở chế độ Sáng: 24 tổ hợp.
- Tối ở 390 và 1024 × 3 bố cục: 6 tổ hợp.
- 20 tổ hợp Tùy biến ở 1440: 14 pairwise (P01–P14) + 6 rủi ro (R1–R6).
- 3 loại tài khoản.
- Tương phản 9 màu thương hiệu: 20 vai trò, tính bằng script. Render thật 2 màu tệ nhất (Xanh dương, Xanh lá) + Bảo Sơn × 4 tổ hợp.
- Popover ở 390 và 320; phóng to 640/320; chữ 125%.

**Mã lỗi**

| Mã | Tổ hợp | Lỗi | Số đo | Quy tắc vi phạm | Gốc | Đề xuất sửa | Bằng chứng | Mức độ |
|---|---|---|---|---|---|---|---|---|
| L1-01 | Site / Dọc / Sáng / 1440 / brand Mận `#9d174d` / Cài đặt › Email | Màu thương hiệu không vào panel nhúng: tab, viền, link của Email Settings vẫn xanh Bảo Sơn | `--brand`=`#9d174d` nhưng `--color-accent`=`#2050a0`; tab "Gửi thư" `rgb(32,80,160)` | Q1, CLAUDE §8 (màu qua token) | token (`admin-base.css` cố định accent) | Q1: `--color-accent` = `var(--brand, #2050a0)` + biến dẫn xuất | [ĐO BẰNG SCRIPT] | Cao |
| L1-02 | Topbar Đậm, Sáng; brand Xanh dương / Xanh lá / Xanh cổ vịt / Mòng két | Chữ phụ topbar ("Operator · mọi khách hàng") và chip người dùng khó đọc | XD: chữ phụ 4,00, chip 3,52, tên 4,22 · XL: 4,09 / 3,60 / 4,34 · Cổ vịt 4,35 / 3,79 · Mòng két 4,43 / 3,86. Render thật P06 (Xanh lá): 4,09 / 4,34 / 3,6 | WCAG 1.4.3; DS "Customizer: lựa chọn không đạt phải tự đổi sang biến thể đậm hơn" | shell (`tb` dùng brand thuần + chữ trắng .85, chip phủ trắng .08) | Nền topbar đậm = brand làm đậm (giữ sắc) tới khi trắng .85 trên nền chip đạt ≥ 4,5 | [ĐO BẰNG SCRIPT] | Trung bình |
| L1-03 | Sidebar Gradient, Sáng, kích thước mặc định; 4 màu như trên | Chữ menu, tiêu đề nhóm, mục đang chọn và vạch vàng ở điểm sáng của gradient không đạt | XD: chữ 4,00 · nhóm 3,48 · active 3,41 · vạch vàng 2,42. XL: 4,09 / 3,54 / 3,49 / 2,49. Render thật XD: "Tổng quan" 3,93, "Hệ thống" 3,48 | WCAG 1.4.3 / 1.4.11; DS B20 | shell (gradient bắt đầu từ brand thuần) | Điểm sáng của gradient = brand làm đậm tới khi trắng .85 ≥ 4,5 và vàng ≥ 3 (như cách B20 đã làm) | [ĐO BẰNG SCRIPT] | Trung bình |
| L1-04 | Sidebar Sáng; link trên nền trang; brand XD, XL | Màu thương hiệu dùng làm chữ trên nền sáng không đạt 4,5 | Mục đang chọn: XD 4,28 · XL 4,39 (render thật 4,34 / 4,45). Link trên `#f4f5fa`: XD 4,48 | WCAG 1.4.3; DS B10 (`--primary-text`) | token (`--brand-ink` ở chế độ sáng = brand thô) | `--brand-ink` sáng = brand làm đậm tới ≥ 4,5 trên `#f4f5fa` và nền brand 10% | [ĐO BẰNG SCRIPT] | Trung bình |
| L1-05 | Sidebar Đậm / Sáng; brand XD, XL | Badge "12" (Liên hệ) chữ trắng không đạt | XD trên sidebar đậm 4,17 · XL trên sidebar sáng 4,27 | WCAG 1.4.3 | shell (màu badge dẫn xuất từ brand) | Nền badge dùng bậc `-solid` cố định hoặc brand đã làm đậm | [ĐO BẰNG SCRIPT] | Trung bình |
| L1-06 | Site / Dọc / Sáng / 390 và 320 | Popover Thông báo và Ngôn ngữ tràn ra ngoài mép trái màn hình | Thông báo ở 390: x = −59…281 ("Thông báo" bắt đầu ở −42). Ngôn ngữ: 390 → −22…158; 320 → −92…88 | P14.a, CLAUDE §8 (không tràn, chỉ một popover) | shell (popover `right:0` neo theo nút, độ rộng cố định) | Dưới 520px: popover `position:fixed; left:8px; right:8px`, chiều cao tối đa theo màn hình | [ĐO BẰNG SCRIPT] | Cao |
| L1-07 | Mọi bố cục / ≤ 1024 | Nút mở menu chỉ có icon, vùng bấm nhỏ | 31×38 (360–390) · 38×38 (≥ 740); chữ = "" | Q4, P14.e, DS C2 | shell | Q4: icon + chữ "Menu", cao ≥ 44 | [ĐO BẰNG SCRIPT] | Cao |
| L1-08 | Dashboard, mọi bề rộng | Chip "7 ngày / 30 ngày / Năm" là `<span onClick>`: không focus được bằng bàn phím, không được quy tắc mở rộng vùng chạm của `admin-base`, và bấm không có tác dụng (`onStub`) | 61×28, 69×28, 49×28 | P10 (bàn phím), P5, P14.f | shell | Đổi thành `<button aria-pressed>` trong nhóm `role="group"`; đổi được khoảng thời gian thật | [ĐO BẰNG SCRIPT] + [SUY RA TỪ CODE] | Cao |
| L1-09 | 360 / 390 / 768 và 740×360 | Topbar giữ 70px trên mobile và màn thấp | topbarH = 70 ở mọi bề rộng | DS "Áp dụng riêng": 56px trên mobile và màn thấp; P14.h | shell | ≤ 1023px hoặc chiều cao ≤ 480: 56px | [ĐO BẰNG SCRIPT] | Trung bình |
| L1-10 | Dọc / 1024–1279 | Hiện sidebar đầy đủ, chưa có rail | asideW = 258 ở 1024 và 1280 | Q3 | shell | Q3 | [ĐO BẰNG SCRIPT] | Trung bình |
| L1-11 | — | Ngưỡng mobile là 992px | `isMobile = winW < 992` | Q2, DS (1024) | shell | Q2 (cả `Page Builder v3` có 2 chỗ "992", rà ở bước sửa) | [SUY RA TỪ CODE] | Trung bình |
| L1-12 | — | Thuộc tính Tùy biến mất khi tải lại | Chỉ lưu `bs-scheme` | Q5 | shell | Q5 | [SUY RA TỪ CODE] | Trung bình |
| L1-13 | Panel Tùy biến | Plane nền tảng ghi "SaaS" | Nhãn đo được: Site · TMĐT · SaaS | Q7 | shell | Đổi thành "Nền tảng" | [ĐO BẰNG SCRIPT] | Thấp |
| L1-14 | Nền tảng / 1440 / ô tìm kiếm | Tìm kiếm ở console nền tảng trả nội dung của website An Vinh | Kết quả: "Trang chủ · đã xuất bản", "Máy lọc nước An Vinh Pro", "Nguyễn Văn B — cần báo giá"… giống hệt Site và TMĐT | CLAUDE §7b (danh sách mục lọc theo loại tài khoản), P16 | shell (mảng `searchResults` tĩnh, không theo plane) | Kết quả mẫu theo plane: Nền tảng = khách hàng, website, nhật ký; TMĐT thêm đơn hàng, khách hàng | [ĐO BẰNG SCRIPT] | Cao |
| L1-15 | Nền tảng › Đăng nhập nhanh | Shell truyền `account-type="saas"` cho console nền tảng (Email hệ thống truyền đúng `platform`) | `embed-id="social-saas" account-type="saas"` | CLAUDE §7b | shell | Truyền `platform` sau khi chốt Q7 | [SUY RA TỪ CODE] | Trung bình |
| L1-16 | Dọc / Sáng / 1440 và 390, cuộn hết trang | Nút tròn Tùy biến đè dòng "Phát triển bởi Bảo Sơn" ở chân trang | Nút 40×40 tại (1380, 840) chồng lên "Phát triển bởi", "Bảo Sơn"; ở 390 chồng lên "Phát triển bởi" | P14.d, CLAUDE §8 | shell (`padding-bottom` 76px chỉ có ở vùng nội dung, chưa có ở chân trang) | Thêm khoảng đệm ở chân trang bằng chiều cao nút + 16px, hoặc đưa nút vào topbar ở ≤ 768 | [ĐO BẰNG SCRIPT] | Cao |
| L1-17 | Dashboard | "Hộp duyệt" ghi "5 chờ xử lý" nhưng chỉ liệt kê 3 mục | 3 dòng | P12 | shell (dữ liệu mẫu) | Ghi "3 trong 5" hoặc liệt kê đủ; nút "Mở hộp duyệt" giữ nguyên | [ĐO BẰNG SCRIPT] | Thấp |
| L1-18 | Dashboard | Biểu đồ "Lượt xem nội dung" không có trục, không ghi giá trị; số liệu chỉ có trong `title` (rê chuột mới thấy); thanh dùng gradient với hex cứng `#5b6fb8` | Không có SVG; chỉ có nhãn ngày 12px | P14.f, DS N2 (gắn nhãn trực tiếp), CLAUDE §8 (token) | shell | Ghi giá trị trên đầu cột (hoặc chỉ cột cao nhất + trung bình), thêm trục y tối giản, nền cột đặc `--brand` | [SUY RA TỪ CODE] | Trung bình |
| L1-19 | Dashboard | Chip "7 ngày" đang chọn nhưng bộ chọn ngày ghi "01–17 Th7, 2026" (17 ngày) | — | P12 | shell (dữ liệu mẫu) | Đồng bộ một nguồn: chọn chip thì khoảng ngày đổi theo | [ĐO BẰNG SCRIPT] | Thấp |
| L1-20 | Chữ lớn 125% (390, 640) | Đổi cỡ chữ gốc không có tác dụng | 370 khai báo `font-size` bằng px, 0 bằng rem; đo lại không thay đổi gì | P14.k | shell | Đổi dần cỡ chữ sang rem (ưu tiên chữ nội dung, nhãn, nút); đo lại ở 125% và 200% | [ĐO BẰNG SCRIPT] + [SUY RA TỪ CODE] | Trung bình |
| L1-21 | — | **Hiệu năng:** Admin Shell 305.790 B, làm script đo quá thời gian. Phân tích ở dưới bảng. | — | P14.m | shell | Xem phân tích; chưa tách trong L1 | [ĐO BẰNG SCRIPT] | Trung bình |
| L1-22 | Mọi bề rộng | Line-height < 1,3 ở 5–9 phần tử chữ (chip người dùng 1,2; nhãn nền tảng 1,1) | Đếm `lh` = 5 (mobile), 9 (desktop) | P11, DS B7 (cắt dấu tiếng Việt) | shell | Line-height ≥ 1,4 cho mọi dòng chữ | [ĐO BẰNG SCRIPT] | Trung bình |

**Phân tích L1-21: Admin Shell nặng ở đâu**

Tổng 305.790 B, gồm template 138.214 B và JS 167.576 B.
- **Template:**
  - thuộc tính `style` inline: 68,6 KB, trong đó ~15,5 KB là chuỗi style lặp lại (một style 251 B lặp 16 lần)
  - CSS trong `<helmet>`: 7 KB
  - SVG inline: 0
- **JS:**
  - `renderVals()` chiếm 140 KB trong một hàm
  - chuỗi dài: ~36 KB, chủ yếu là schema các mục Cài đặt (`backup`, `p-plans`…), dữ liệu mẫu (thông báo, tìm kiếm, hộp duyệt) và chuỗi style ghép trong JS (~12 KB)
  - `settingsGroupsAll` 4,4 KB, menu 3 plane ~6 KB
- **Đề xuất tách (không đổi hành vi):**
  1. Đưa schema form Cài đặt và dữ liệu mẫu sang `shell-data.js` (`window.BSSHELL`), đọc như `BSAI`/`BSPLAT`.
  2. Chia `renderVals()` thành các hàm `vChrome()`, `vDashboard()`, `vSettings()`, `vModals()` theo vùng, mỗi vùng chỉ tính khi đang hiện.
  3. Tách form Cài đặt chung (khối `showSettings` + renderer schema) thành DC con `Settings Form`. Khối này đã có ranh giới props rõ (schema, values, onChange) và lặp cho mọi mục.
  4. Gom các style lặp ≥ 4 lần thành class trong `<helmet>` ở mức tối thiểu cho phần không stream; giữ inline cho phần còn lại theo quy ước DC.

**Không phải lỗi**
- Không tràn ngang (`scrollWidth` = bề rộng) ở 24 tổ hợp Sáng, 6 tổ hợp Tối, 20 tổ hợp Tùy biến và phóng to 640/320. [ĐO BẰNG SCRIPT]
- Tương phản với màu mặc định Bảo Sơn: không lỗi ở mọi tổ hợp Sáng/Tối, mọi màu sidebar và topbar. Navy, Chàm, Tím than, Mận: không lỗi ở 20 vai trò. [ĐO BẰNG SCRIPT]
- Menu Ngang dồn mục đúng: 1024 = 7 mục + "Khác"; 1280 = 9 + "Khác"; 1440/1920 = đủ 11 mục; Nền tảng 4 mục. [ĐO BẰNG SCRIPT]
- Dưới 992px bố cục Ngang và 2 cột tự về Dọc + drawer. [ĐO BẰNG SCRIPT]
- Panel Tùy biến vừa khung ở 320 và 390 (0…320, 2…390). [ĐO BẰNG SCRIPT]
- Ô nhập 13–14px trên desktop: `admin-base.css` nâng lên 16px khi `pointer: coarse`. [SUY RA TỪ CODE] Cần thử iOS.
- Vùng bấm < 44px của các `<button>` topbar (38×38): `admin-base.css` mở rộng lên 44px bằng `::after` khi `pointer: coarse`. [SUY RA TỪ CODE] Riêng các `<span onClick>` không được mở rộng: xem L1-08.
- Link "Bảo Sơn" / "SaaS Starter" cao 15px ở chân trang: link nằm trong dòng chữ, được miễn theo WCAG 2.5.8.
- Nhóm Cài đặt theo plane đúng: Site có Email, Gói & mức dùng, Nút liên hệ nổi; TMĐT thêm Thanh toán, Vận chuyển, Thuế; Nền tảng có Gói & Giới hạn, Email hệ thống, Mẫu email hệ thống, Đăng nhập nhanh, và không có mục của tenant. [ĐO BẰNG SCRIPT]

**Chuyển đổi các điểm ở mục 4e**
1. → L1-01.
2. → L1-07, L1-10, L1-11. BottomNav bỏ theo Q4.
3. → L1-12.
4. Màn 2b chưa mount → chuyển sang task mount (Q6), không tính lỗi L1.
5. → L1-13, L1-15 + báo cáo Q7 bên dưới.
6. Admin Shell đã nạp `_ds_bundle.js`. 10 DC còn lại ghi nợ kỹ thuật theo lô.
7. Admin Palette → lưu trữ (Q8).

**Báo cáo Q7: `saas` khác `platform` ra sao** [SUY RA TỪ CODE]
- `saas` là **khách hàng (tenant) tự kinh doanh một sản phẩm thuê bao**, ví dụ một phần mềm bán theo gói. Trong Email Settings:
  - có nhóm mẫu "Gói dịch vụ & thanh toán": Dùng thử sắp hết hạn, Hóa đơn gia hạn gói, Thanh toán thất bại, Hủy/hạ gói, Sắp vượt hạn mức, Mời thành viên
  - không có hộp thư dùng chung của nhà cung cấp (`noParentMailbox`); mặc định Google
  - địa chỉ nhận gợi ý `billing@`, `support@`
- `platform` là **console của chính Bảo Sơn** (nhà vận hành):
  - cấu hình máy chủ gửi dùng chung và mẫu email hệ thống cho mọi khách
  - mỗi mẫu có chip loại khách (Site · TMĐT · SaaS) để biết áp cho ai
  - nhận cảnh báo hệ thống
- **Trong Shell:**
  - panel Tùy biến có 3 lựa chọn `tenant`/`shop`/`platform`, không có lựa chọn cho tenant kiểu `saas`, nên không xem thử được menu của loại khách này
  - Shell hiện **không có menu riêng** cho tenant SaaS (menu, nhóm Cài đặt và bảng tin đều chưa thiết kế)
  - `emAccType` map `platform → saas` cho các panel dùng biến này, trong khi Email hệ thống dùng đúng `platform` (xem L1-15)
- **Có cần lựa chọn thứ 4?** Hai phương án:
  - **A.** Thêm plane "SaaS" để xem thử. Cần thiết kế menu, Cài đặt (gói, thanh toán thuê bao, thành viên) và bảng tin cho loại khách này trước, nếu không plane này chỉ là bản sao của Site.
  - **B.** Giữ 3 plane. Loại `saas` chỉ xem thử qua Tweaks `accountType` của từng panel. Sửa map `emAccType` để console nền tảng luôn truyền `platform`.
  - **Khuyến nghị: B ngay bây giờ.** Chuyển sang A khi có bản đồ màn hình cho tenant SaaS. Chờ bạn quyết.

**Tổ hợp đã định kiểm nhưng chưa kiểm được**
- Focus bàn phím (vòng focus, topbar có che không): `:focus` không khớp khi iframe không giữ focus cửa sổ, kết quả đo không tin được. Hiện mới [SUY RA TỪ CODE]: có quy tắc `:focus-visible` toàn cục, `.sInput:focus`, `.sBox:focus-within`. **[CHƯA KIỂM ĐƯỢC]**
- Popover Thông báo ở 320: không tìm thấy phần tử popover sau khi mở. Chưa rõ bị ẩn hay chỉ báo sai. **[CHƯA KIỂM ĐƯỢC]**, khoanh lại ở bước kiểm lại.
- Rê chuột thật (khe hở menu cha/con), chạm thật, Safari/Firefox, `prefers-reduced-motion`: ngoài khả năng của công cụ. Xem danh sách bên dưới.

**CẦN TÔI THỬ TRÊN MÁY THẬT**
1. **Ngang:** Chrome và Safari desktop, ≥ 1280px. Rê chuột vào "Trang" rồi kéo chậm xuống mục con đầu tiên, sau đó sang mục "Khác" ở 1024px. Kỳ vọng: menu con không đóng khi chuột đi qua khe 6px; menu cha vẫn sáng.
2. **Dọc thu nhỏ (rail 76px):** Chrome desktop. Rê vào icon "Tin tức" rồi kéo ngang sang khung bung ra. Kỳ vọng: khung không đóng giữa chừng; đóng sau khoảng 0,25 giây khi rời hẳn.
3. **2 cột:** Chrome desktop 1280px. Bấm lần lượt các nhóm ở cột icon, rê sang cột menu con. Kỳ vọng: cột con giữ nguyên, mục đang chọn có vạch vàng.
4. **Safari iOS (iPhone):**
   - Mở drawer bằng nút menu, cuộn danh sách, xoay ngang. Kỳ vọng: drawer cao đúng màn hình, không bị thanh địa chỉ che đáy (`100vh`).
   - Chạm ô "Tìm kiếm" trên topbar và ô trong Cài đặt. Kỳ vọng: trang không tự phóng to.
   - Cuộn Cài đặt. Kỳ vọng: thanh mục Cài đặt và thanh Lưu dính đúng chỗ, không nhảy (sticky trong vùng cuộn).
   - Mở Thông báo. Kỳ vọng hiện tại: popover lệch trái (L1-06); sau khi sửa phải nằm gọn trong màn hình.
5. **Android Chrome:** chạm các icon topbar. Kỳ vọng: chạm trúng dễ, vùng chạm ~44px.
6. **Firefox desktop:** đổi Sáng/Tối và màu thương hiệu. Kỳ vọng: `color-mix()` ở sidebar và topbar hiển thị như trên Chrome.
7. **Bàn phím (Chrome):** Tab từ đầu trang qua topbar, menu, nội dung. Kỳ vọng: vòng focus 2px luôn thấy rõ, không bị topbar che; menu con Ngang mở khi Tab vào.


---

### 5.L1-A — Đợt A: token màu thương hiệu (sửa + kiểm lại 01/10/2026)

**Kết quả**
- Lỗi đóng: **5/5** (L1-01 → L1-05) ở tầng token + shell. L1-01 đóng ở tầng token; phần hex cứng còn lại trong các panel nhúng tách thành L1-A-P01 (lô L2).
- Hồi quy mới: **0** (L1-R chưa có mã nào).
- Bảng tương phản 9 màu × 20 vai trò: **31** ô không đạt trước khi sửa → **0** sau khi sửa.
- Baseline token: **31/31** DC mở độc lập giữ nguyên `--color-accent` và biến dẫn xuất (Sáng và Tối).
- Ghi nhận mới, chưa sửa (cần quyết định): L1-23, L1-24. Việc cho L2: L1-A-P01.

**0. Baseline** — `_audit/baseline-L1.json`, chụp trước khi sửa. [ĐO BẰNG SCRIPT]
- 31 DC mở độc lập (1280×800, chờ 3,5 s). Đọc màu tính toán của `--color-accent`, `--color-admin-primary`, `--focus-ring-color`, `--color-sidebar-fg-active/-hover`, `--brand`, `--brand-solid`, `--brand-ink` ở `<body>`; Sáng và Tối (đặt `data-bs-theme` trên `<html>`).
- 29 trang: Sáng `#2050a0` / `#2050a0` / `#2050a0`; Tối `#8fb0ec` / `#405189` / `#8fb0ec`. Admin Palette - Options/Sample (lưu trữ, không nạp `admin-base.css`): Sáng `#405189`, Tối `#96a1c0`.
- Kèm bảng tương phản 9 màu × 20 vai trò trước khi sửa (cột "trước" trong bảng dưới).

**1. Bảng tĩnh 9 màu** — tính sẵn trong OKLCH (giữ hue và chroma; chroma chỉ giảm khi ra ngoài gam sRGB), hạ L từng bước 0,002 tới khi vừa đạt. Màu gốc đã đạt thì giữ nguyên. Nằm trong `BRAND_TOKENS` của Admin Shell; Shell chỉ tra bảng. Tương phản tính bằng công thức WCAG 2.x. [ĐO BẰNG SCRIPT]
- `--brand-solid`: chữ trắng và trắng .85 ≥ 4,5 trên nền, kể cả trên chip phủ trắng .08.
- `--brand-ink` (Sáng): ≥ 4,5 trên `#fff`, `#f4f5fa` và nền brand 10% (cả tint của màu gốc lẫn của chính ink).
- `--brand-ink` (Tối): giữ `color-mix(in oklab, brand 38%, #e6eeff)`; đã kiểm ≥ 4,5 trên `#0f1729`, `#16203a`, `#1a2440` và tint brand 10%.
- Vạch vàng `#e0b028`: ≥ 3 trên nền sidebar Đậm (`color-mix(solid 72%, #0b1d45)`) và điểm sáng Gradient (= solid). Đạt với solid, không cần làm đậm thêm.

| Màu | Gốc | `--brand-solid` | `--brand-ink` (Sáng) | `--brand-ink` (Tối, color-mix giữ nguyên) | Solid: min trắng / trắng .85 trên chip .08 | Ink: min trên #fff · #f4f5fa · brand 10% | Tối: min trên #0f1729 · #16203a · #1a2440 · tint 10% | Vạch vàng: sidebar Đậm / Gradient |
|---|---|---|---|---|---|---|---|---|
| Bảo Sơn | `#2050a0` | `#2050a0` (giữ gốc) | `#2050a0` (giữ gốc) | `#98b1dc` | 5,12 | 6,09 | 7 | 4,81 / 3,83 |
| Navy | `#405189` | `#405189` (giữ gốc) | `#405189` (giữ gốc) | `#a3b0d2` | 5,02 | 6,04 | 6,99 | 4,77 / 3,78 |
| Xanh dương | `#2f6fd0` | `#1859b9` | `#2465c5` | `#a0beef` | 4,52 | 4,54 | 7,76 | 4,3 / 3,29 |
| Xanh cổ vịt | `#0e7490` | `#00657f` | `#05708c` | `#9cbfd4` | 4,51 | 4,56 | 7,59 | 4,33 / 3,29 |
| Mòng két | `#0f766e` | `#036861` | `#0b746c` | `#9cbfc5` | 4,52 | 4,52 | 7,56 | 4,36 / 3,3 |
| Xanh lá | `#15803d` | `#056b30` | `#0a7636` | `#9ec4b3` | 4,54 | 4,63 | 7,75 | 4,4 / 3,3 |
| Chàm | `#4338ca` | `#4338ca` (giữ gốc) | `#4338ca` (giữ gốc) | `#a0adef` | 5,27 | 6,19 | 7,06 | 4,92 / 3,92 |
| Tím than | `#5b3fb3` | `#5b3fb3` (giữ gốc) | `#5b3fb3` (giữ gốc) | `#acace5` | 4,99 | 5,91 | 7,09 | 4,74 / 3,71 |
| Mận | `#9d174d` | `#9d174d` (giữ gốc) | `#9d174d` (giữ gốc) | `#d2a2b7` | 5,36 | 6,1 | 6,98 | 5,25 / 3,91 |
**Sidebar Sáng:** vạch mục đang chọn ở sidebar Sáng là màu brand (`--brand-ink`), không phải vàng. Không có vạch vàng ở sidebar Sáng nên không phát sinh điều kiện DỪNG.

**2. Bảng tương phản 9 màu × 20 vai trò, trước → sau** (in đậm = không đạt; một số = không đổi). R01–R18 là chế độ Sáng, R19–R20 là chế độ Tối. Ở chế độ Tối, sidebar và topbar dùng màu cố định (`#111a30`, `#16203a`), không phụ thuộc brand; nút chính dùng solid như ở Sáng (R01).

| Vai trò | Ngưỡng | Bảo Sơn | Navy | Xanh dương | Xanh cổ vịt | Mòng két | Xanh lá | Chàm | Tím than | Mận |
|---|---|---|---|---|---|---|---|---|---|---|
| R01 Nút chính: trắng/solid | 4,5 | 7,73 | 7,62 | 4,88 → 6,63 | 5,36 → 6,63 | 5,47 → 6,65 | 5,02 → 6,66 | 7,9 | 7,48 | 7,88 |
| R02 Badge menu: trắng/solid | 4,5 | 8 → 7,73 | 7,89 → 7,62 | 5,13 → 6,63 | 5,62 → 6,63 | 5,74 → 6,65 | 5,28 → 6,66 | 8,18 → 7,9 | 7,76 → 7,48 | 8,25 → 7,88 |
| R03 Topbar đậm: tên (trắng) | 4,5 | 7,73 | 7,62 | 4,88 → 6,63 | 5,36 → 6,63 | 5,47 → 6,65 | 5,02 → 6,66 | 7,9 | 7,48 | 7,88 |
| R04 Topbar đậm: chữ phụ (trắng .85) | 4,5 | 6,09 | 6,05 | **4** → 5,27 | **4,35** → 5,27 | **4,43** → 5,29 | **4,09** → 5,29 | 6,17 | 5,91 | 6,04 |
| R05 Topbar đậm: chip (trắng .85 / phủ .08) | 4,5 | 5,12 | 5,02 | **3,52** → 4,52 | **3,79** → 4,51 | **3,86** → 4,52 | **3,6** → 4,54 | 5,27 | 4,99 | 5,36 |
| R06 Sidebar Đậm: chữ (.86) | 4,5 | 7,64 | 7,61 | 5,57 → 6,87 | 5,97 → 6,91 | 6,09 → 6,96 | 5,77 → 7,02 | 7,77 | 7,53 | 8,19 |
| R07 Sidebar Đậm: tiêu đề nhóm (.78) | 4,5 | 6,59 | 6,59 | 4,89 → 5,96 | 5,22 → 5,99 | 5,32 → 6,04 | 5,06 → 6,08 | 6,68 | 6,51 | 6,98 |
| R08 Sidebar Đậm: mục chọn (trắng / phủ .12) | 4,5 | 7 | 6,85 | 5,23 → 6,41 | 5,59 → 6,43 | 5,67 → 6,44 | 5,4 → 6,5 | 7,27 | 6,91 | 7,89 |
| R09 Sidebar Đậm: vạch vàng ≥3 | 3 | 4,81 | 4,77 | 3,41 → 4,3 | 3,69 → 4,33 | 3,76 → 4,36 | 3,55 → 4,4 | 4,92 | 4,74 | 5,25 |
| R10 Gradient: chữ (.85) ở điểm sáng | 4,5 | 6,09 | 6,05 | **4** → 5,27 | **4,35** → 5,27 | **4,43** → 5,29 | **4,09** → 5,29 | 6,17 | 5,91 | 6,04 |
| R11 Gradient: tiêu đề nhóm ở điểm sáng (trước .75 → sau .85) | 4,5 | 5,13 → 6,09 | 5,12 → 6,05 | **3,48** → 5,27 | **3,75** → 5,27 | **3,82** → 5,29 | **3,54** → 5,29 | 5,16 → 6,17 | 4,99 → 5,91 | 4,99 → 6,04 |
| R12 Gradient: mục chọn (trắng / phủ: trước .2 → sau .12) | 4,5 | 4,76 → 5,77 | 4,6 → 5,6 | **3,41** → 5,12 | **3,66** → 5,11 | **3,71** → 5,11 | **3,49** → 5,14 | 4,98 → 6,02 | 4,65 → 5,63 | 5,31 → 6,31 |
| R13 Gradient: vạch vàng trên nền sidebar ≥3 | 3 | 3,83 | 3,78 | **2,42** → 3,29 | **2,66** → 3,29 | **2,71** → 3,3 | **2,49** → 3,3 | 3,92 | 3,71 | 3,91 |
| R14 Sidebar Sáng: mục chọn (ink / brand 10%) | 4,5 | 6,61 | 6,55 | **4,28** → 4,92 | 4,67 → 4,94 | 4,76 → 4,9 | **4,39** → 5,02 | 6,72 | 6,41 | 6,62 |
| R15 Link trên #fff | 4,5 | 7,73 | 7,62 | 4,88 → 5,61 | 5,36 → 5,67 | 5,47 → 5,63 | 5,02 → 5,74 | 7,9 | 7,48 | 7,88 |
| R16 Link trên #f4f5fa | 4,5 | 7,1 | 7 | **4,48** → 5,15 | 4,92 → 5,21 | 5,03 → 5,17 | 4,61 → 5,27 | 7,26 | 6,87 | 7,24 |
| R17 Tab Cài đặt (ink / brand 10% trên #f4f5fa) | 4,5 | 6,09 | 6,04 | **3,95** → 4,54 | **4,31** → 4,56 | **4,4** → 4,52 | **4,05** → 4,63 | 6,19 | 5,91 | 6,1 |
| R18 Vòng focus trên #fff ≥3 | 3 | 7,73 | 7,62 | 4,88 → 5,61 | 5,36 → 5,67 | 5,47 → 5,63 | 5,02 → 5,74 | 7,9 | 7,48 | 7,88 |
| R19 Tối: accent trên thẻ #16203a / panel #1a2440 | 4,5 | 7,06 | 7,07 | 8,14 | 7,87 | 7,82 | 8,04 | 7,08 | 7,15 | 6,98 |
| R20 Tối: accent trên nền #0f1729 / tint brand 10% | 4,5 | 7 | 6,99 | 7,76 | 7,59 | 7,56 | 7,75 | 7,06 | 7,09 | 7,17 |

**3. Kiểm trên DOM đã render** (Admin Shell trong `_audit/harness.html`, 1440×900, đo màu tính toán) [ĐO BẰNG SCRIPT]
- 9 màu × 3 màu sidebar (Đậm/Gradient/Sáng), topbar Đậm, Dashboard: **0** chữ không đạt. Lần đo đầu bắt được badge "12" trên Gradient 4,11–4,44 (6 màu; badge phủ trắng .22) → đổi .22 → .12, đo lại 9/9 Gradient: 0 lỗi.
- Tối (sidebar Đậm) với Bảo Sơn, Xanh dương, Xanh lá: 0 lỗi.
- **Xanh dương** trong Cài đặt › Email (nhúng): token khung `--brand-solid` `#1859b9`, `--brand-ink` `#2465c5`, `--color-accent` `#2465c5`. 13 phần tử chữ (link, tab "Email" của thanh mục Cài đặt, "Cách lấy ID?", "Gửi sự kiện thử") theo ink; 1 nền theo solid. Còn 3 chữ, 3 nền, 3 viền `#2050a0` cứng trong Email Settings: tab "Gửi thư", chip "lienhe@anvinh.vn", nút "×", nút chính (→ L1-A-P01).
- **Xanh lá** cùng vị trí: ink `#0a7636` 13 chữ, solid `#056b30` 1 nền; cùng 9 phần tử cứng như trên.
- **Không chụp được ảnh** Admin Shell: lệnh chụp quá thời gian 7 giây ở cả 3 lần thử (đã biết từ L1-21). Kết quả trên là DOM đã render và đo bằng script, không phải ảnh. [CHƯA KIỂM ĐƯỢC ảnh chụp + lý do trên]

**4. So sánh baseline (31 DC mở độc lập)** [ĐO BẰNG SCRIPT]
- 31/31 trùng baseline ở cả Sáng và Tối. Không có lỗi hồi quy.
- Riêng khung `[data-brand-scope]` của Admin Shell đổi như dự kiến: nay có `--brand-solid`; ở tổ hợp giả "`<html>` Tối + state Shell Sáng" khung giữ màu Sáng `#2050a0` (trước là `#8fb0ec` thừa kế từ `:root`). Tổ hợp này không xảy ra khi dùng thật vì Shell luôn đặt cả hai cùng lúc.

**5. 4 tổ hợp hồi quy của Shell** (Bảo Sơn) [ĐO BẰNG SCRIPT]
| Tổ hợp | Tràn ngang | Phần tử tràn | Chữ không đạt | Ghi chú |
|---|---|---|---|---|
| Dọc-Sáng-1440 | không | 0 | 0 | sidebar 258 |
| Ngang-Tối-1280 | không | 0 | 0 | 9 mục + "Khác", như baseline L1 |
| 2 cột-Sáng-1024 | không | 0 | 0 | rail + cột con 388 |
| Dọc-Tối-390 | không | 0 | 0 | drawer 268 |
Lần chạy hàng loạt bị kẹt ở 2 tổ hợp cuối; chạy lại riêng từng tổ hợp thì đo được (đã ghi kết quả lần chạy lại).

**6. Panel nhúng, màu Mận `#9d174d`, 1440 Sáng** [ĐO BẰNG SCRIPT] — đếm phần tử nhìn thấy trong vùng nội dung (ngoài sidebar/topbar).
| Panel | Theo Mận (chữ / nền / viền) | Còn `#2050a0` cứng (chữ / nền / viền) | Kết luận |
|---|---|---|---|
| Email Settings | 13 / 1 / 0 | 3 / 3 / 3 | Một phần |
| Đăng nhập nhanh & Liên kết TK | 5 / 1 / 0 | 0 / 1 / 0 | Một phần (nút "Lưu") |
| Hạn ngạch AI và mua thêm | 15 / 1 / 0 | 0 / 14 / 5 | Một phần (nút chính, công tắc, thanh mức dùng, gói đang chọn) |
| Integrations Panel | 17 / 2 / 1 | 0 / 0 / 0 | **Đạt** |
| Floating Contact Panel | 9 / 1 / (không đọc được, log bị cắt) | 0 / 5 / 0 | Một phần |
| Trình đơn | 9 / 1 / 0 | 0 / 8 / 5 | Một phần (nút chính, công tắc, ô chọn, tab đang chọn) |
Chữ dùng `--color-accent` đã theo màu thương hiệu ở cả 6 panel. Phần còn lại là hex cứng trong file của từng panel (tầng trang), không phải lỗi token → L1-A-P01, sửa trong lô L2. Các lỗi tương phản khác đo được trong panel (`#878a99`, `#adb5bd`, màu ngữ nghĩa thô) có từ trước, không đổi theo Đợt A, thuộc phạm vi kiểm L2. MediaPicker là hộp thoại, chưa mở trong lần kiểm này.

**Mã mới**
| Mã | Tổ hợp | Lỗi | Số đo | Quy tắc | Gốc | Đề xuất | Bằng chứng | Mức độ |
|---|---|---|---|---|---|---|---|---|
| L1-A-P01 | Shell 1440 Sáng, brand ≠ Bảo Sơn, 5 panel nhúng | Nền nút chính, công tắc, ô chọn, thanh mức dùng, tab đang chọn và một số chữ dùng `#2050a0` cứng nên không theo màu thương hiệu | xem bảng mục 6 | Q1, CLAUDE §8 | trang | Thay bằng `var(--brand-solid,#2050a0)` cho nền dưới chữ trắng, `var(--color-accent,#2050a0)` cho chữ/viền. Giá trị dự phòng giữ nguyên màu khi mở độc lập | [ĐO BẰNG SCRIPT] | Trung bình |
| L1-23 | Sidebar Gradient, mục đang chọn | Vạch vàng so với nền mục đang chọn (trắng .12 phủ lên điểm sáng) < 3:1. So với nền sidebar thì đạt | Trước (phủ .2, màu gốc) → sau (phủ .12, solid): Bảo Sơn 2,36 → 2,86 · Navy 2,28 → 2,78 · Xanh dương 1,69 → 2,54 · Xanh cổ vịt 1,82 → 2,53 · Mòng két 1,84 → 2,53 · Xanh lá 1,73 → 2,55 · Chàm 2,47 → 2,98 · Tím than 2,31 → 2,79 · Mận 2,63 → 3,13 | WCAG 1.4.11 | shell | Chờ quyết định (2 phương án dưới) | [ĐO BẰNG SCRIPT] | Trung bình |
| L1-24 | Sidebar Đậm/Gradient, badge số trên mục đang chọn | Badge phủ trắng .12 nằm trên nền mục đang chọn (phủ .12) → trắng trên nền phủ 2 lớp | 4,06–5,01; 7/9 màu < 4,5 | WCAG 1.4.3 | shell | Chờ quyết định (2 phương án dưới) | [SUY RA TỪ CODE] + công thức | Thấp |

**Cần quyết định (chưa sửa)**
- **L1-23** — A. Vạch vàng chỉ áp ≥ 3:1 so với nền sidebar (như hiện tại), chấp nhận nền mục đang chọn là phần của chính chỉ báo. B. Mục đang chọn trên Gradient bỏ nền phủ, chỉ còn vạch vàng + chữ đậm (vạch so với nền sidebar 3,29–3,92). **Khuyến nghị B**: một chỉ báo rõ, không thêm màu.
- **L1-24** — A. Badge trên nền tối dùng nền `rgba(0,0,0,.22)` (luôn ≥ 4,5, khác màu badge hiện tại). B. Giữ phủ trắng .12, mục có badge khi đang chọn thì badge đổi sang nền `--brand-solid` viền trắng .4. **Khuyến nghị A**: một kiểu, không phụ thuộc trạng thái.

**Changelog Đợt A**
| Mã | File | Thay đổi | Trang ảnh hưởng | Đã kiểm lại + bằng chứng |
|---|---|---|---|---|
| L1-01 | `admin-base.css` | `:root` Sáng: `--color-accent`/`--focus-ring-color` = `var(--brand-ink, #2050a0)`, `--color-admin-primary` = `var(--brand-solid, #2050a0)`; Tối: `var(--brand-ink, #8fb0ec)`. Thêm khối `[data-brand-scope][data-brand-scope]` khai lại 3 biến trên khung (biến var() ở `:root` được giải tại `:root`, nên phải khai lại ở khung thì panel nhúng mới nhận) | 31 DC (qua `admin-base.css`) | Baseline 31/31 không đổi; panel nhúng nhận ink/solid (mục 3, 6) [ĐO BẰNG SCRIPT] |
| L1-01 | `Admin Shell.dc.html` | Khung thêm `data-brand-scope`, đặt `--brand-solid` + `--brand-ink` từ `BRAND_TOKENS`; khối `[data-scheme="dark"]` đổi accent/focus cứng `#8fb0ec` → `var(--brand-ink,#8fb0ec)` | Shell + 7 panel nhúng | Mục 3, 5, 6 [ĐO BẰNG SCRIPT] |
| L1-02 | `Admin Shell.dc.html` | Nền topbar Đậm `--brand` → `--brand-solid` | Shell | R03–R05: 4 màu hết lỗi [ĐO BẰNG SCRIPT] |
| L1-03 | `Admin Shell.dc.html` | Gradient bắt đầu từ `--brand-solid`; tiêu đề nhóm trắng .75 → .85; nền mục đang chọn trắng .2 → .12 (bằng sidebar Đậm); badge phủ trắng .22 → .12; ô xem trước Gradient trong panel Tùy biến dùng cùng công thức | Shell | R10–R13 hết lỗi; DOM 9/9 Gradient 0 lỗi [ĐO BẰNG SCRIPT] |
| L1-04 | `Admin Shell.dc.html` | Chữ dùng màu brand → `--brand-ink`: `--brand-fg` (Sáng), `--navHoverFg` sidebar Sáng, vạch mục đang chọn sidebar Sáng, icon thông báo/tìm kiếm/thẻ số liệu, chữ nút Dịch; hover link `color-mix(brand 78%, #000)` → `color-mix(ink 80%, --color-heading)` (đậm hơn ở Sáng, sáng hơn ở Tối) | Shell | R14–R17: 3 màu hết lỗi [ĐO BẰNG SCRIPT] |
| L1-05 | `Admin Shell.dc.html` | Nền dưới chữ trắng `var(--brand)` → `var(--brand-solid,var(--brand))` (32 chỗ: nút chính, nút Lưu, công tắc, nút trong hộp thoại, chip, nút Tùy biến); badge menu con `color-mix(brand 85%)` → solid; sidebar Đậm và cột con 2 cột pha từ solid; ô chữ viết tắt site, đầu popover Thông báo từ solid | Shell | R01–R02 đạt; DOM 0 lỗi [ĐO BẰNG SCRIPT] |
| — | `_audit/harness.html` | Thêm `H.tokens()`/`H.snap()`/`H.resolve()` để chụp token của nhiều DC | Công cụ đo | Dùng cho baseline + so sánh |
| — | `CLAUDE.md` §16, `DS - Prompt G2.md` | Ghi cơ chế token `--brand` / `--brand-solid` / `--brand-ink` | Tài liệu | — |

Chưa đổi (ngoài phạm vi Đợt A): gradient cột biểu đồ `#5b6fb8` (L1-18), viền/tint/thanh tiến độ dùng `--brand` thô (không có chữ trên đó).


---

### 5.L1-B — Đợt B: khung và bố cục (01/10/2026)

**Tiến độ:** Bước 1 xong · Bước 2 xong · Bước 3 xong (01/10/2026).

#### Bước 1 — Baseline và quét (chỉ đo, không sửa)

Phạm vi: 29 DC đang dùng (trừ Admin Palette - Options/Sample) + 8 file dùng chung (`admin-base.css`, `ai-quota.js`, `platform-config.js`, `block-library.js`, `content-seo.js`, `content-ui.js`, `content-workflow.js`, `image-slot.js`). File tên tiếng Việt được sao sang `_audit/tmp/` để script đọc được.

**1a. Baseline bố cục** → `_audit/baseline-layout.json`. [ĐO BẰNG SCRIPT] 29 DC × 390 / 1024 / 1440, mở độc lập. Ghi `scrollWidth`, chiều cao topbar, vị trí/kích thước header, aside, footer và mọi phần tử sticky/fixed nhìn thấy.
- Chỉ Admin Shell có topbar (70px ở cả 3 bề rộng).
- Phần tử dính khi đứng riêng: Cài đặt nội dung AI (thanh đáy, bottom 12px), Floating Contact (cột xem trước, top 22px), Hộp duyệt (thanh đáy), Integrations (cột phải, top 16px), Language Context (đầu trang, top 0), Nâng cấp nội dung (lớp phủ fixed), Page Builder v3 (2 ngăn kéo fixed ở 390), Page Editor (thanh công cụ top 12px, cột phải top 78px), Đăng nhập nhanh (khung xem trước, top 18px).
- `scrollWidth` > bề rộng ở trang canvas trình bày phương án (Cài đặt - Đề xuất 1222, Image Picker 1074, Trợ lý viết v2 - Options 1220) và ở 390 của Language Context 444, Page Editor 599, Quản lý Bài viết 595, Quản lý Trang 741 (+1058 ở 1024). Đây là hiện trạng trước Đợt B, thuộc L3/L5, không phải hồi quy.

**1b. Hex cứng màu thương hiệu** [ĐO BẰNG SCRIPT] — tìm `#2050a0`, `#2050a0XX`, `rgb/rgba(32,80,160,…)`, dẫn xuất trong baseline-L1 (`#8fb0ec`, `#98b1dc`) và mọi màu có ΔE OKLab < 0,03 so với `#2050a0` (không thấy màu gần nào khác ngoài chính nó). Vai trò đoán theo thuộc tính CSS ngay trước mã màu; "ghép chuỗi JS" là mã màu nằm trong chuỗi style ghép bằng JS. Đầu vào cho L1-A-P01 và task mount, **chưa sửa**.

| File | Tổng | Cứng (trừ dự phòng var()) | Dạng: hex / rgb·rgba / hex 8 số / dẫn xuất | Vai trò (số chỗ) |
|---|---|---|---|---|
| Cài đặt - Đề xuất giao diện | 259 | 141 | 191 / 68 / 0 / 0 | nền tint 67 · nút/nền dưới chữ trắng 41 · viền 24 · nền 8 · bóng/vòng 1 · (dự phòng var() 118) |
| Quản lý Bài viết - Options | 179 | 122 | 118 / 61 / 0 / 0 | ghép chuỗi JS / chưa phân loại 34 · viền 28 · nền tint 23 · nút/nền dưới chữ trắng 21 · bóng/vòng 16 · (dự phòng var() 57) |
| Page Editor - Options | 151 | 105 | 96 / 55 / 0 / 0 | ghép chuỗi JS / chưa phân loại 28 · nền tint 26 · viền 24 · bóng/vòng 13 · nút/nền dưới chữ trắng 11 · nền 3 · (dự phòng var() 46) |
| Page Builder v3 | 117 | 83 | 105 / 5 / 7 / 0 | ghép chuỗi JS / chưa phân loại 28 · viền 21 · chữ 13 · nút/nền dưới chữ trắng 7 · bóng/vòng 7 · nền 3 · nền tint 3 · khác (accent-color) 1 · (dự phòng var() 34) |
| Email Settings | 90 | 70 | 64 / 26 / 0 / 0 | ghép chuỗi JS / chưa phân loại 33 · viền 14 · nền tint 8 · bóng/vòng 7 · nút/nền dưới chữ trắng 6 · công tắc 1 · nền 1 · (dự phòng var() 20) |
| AI Writer Panel v2 | 94 | 56 | 62 / 32 / 0 / 0 | ghép chuỗi JS / chưa phân loại 24 · nền tint 15 · viền 12 · nền 2 · nút/nền dưới chữ trắng 2 · bóng/vòng 1 · (dự phòng var() 38) |
| Quản lý Trang - Options | 62 | 43 | 49 / 13 / 0 / 0 | nút/nền dưới chữ trắng 13 · ghép chuỗi JS / chưa phân loại 11 · viền 10 · nền tint 5 · bóng/vòng 4 · (dự phòng var() 19) |
| Trình soạn nội dung | 58 | 41 | 43 / 15 / 0 / 0 | ghép chuỗi JS / chưa phân loại 15 · viền 9 · bóng/vòng 5 · nền tint 5 · nút/nền dưới chữ trắng 4 · nền 3 · (dự phòng var() 17) |
| Trình đơn | 55 | 40 | 36 / 19 / 0 / 0 | ghép chuỗi JS / chưa phân loại 17 · viền 10 · nền tint 7 · công tắc 4 · nút/nền dưới chữ trắng 1 · bóng/vòng 1 · (dự phòng var() 15) |
| MediaPicker | 46 | 38 | 34 / 1 / 11 / 0 | ghép chuỗi JS / chưa phân loại 20 · nền tint 6 · viền 6 · nút/nền dưới chữ trắng 4 · bóng/vòng 1 · nền 1 · (dự phòng var() 8) |
| Cài đặt nội dung AI | 51 | 35 | 34 / 17 / 0 / 0 | ghép chuỗi JS / chưa phân loại 12 · nền tint 7 · viền 6 · nút/nền dưới chữ trắng 4 · công tắc 3 · nền 2 · bóng/vòng 1 · (dự phòng var() 16) |
| Language Context - Design Spec | 84 | 28 | 78 / 6 / 0 / 0 | ghép chuỗi JS / chưa phân loại 8 · viền 7 · nút/nền dưới chữ trắng 5 · khác (accent-color) 4 · bóng/vòng 3 · nền 1 · (dự phòng var() 56) |
| Hộp duyệt | 36 | 26 | 26 / 10 / 0 / 0 | viền 8 · nền tint 5 · công tắc 3 · nút/nền dưới chữ trắng 3 · bóng/vòng 3 · nền 3 · ghép chuỗi JS / chưa phân loại 1 · (dự phòng var() 10) |
| Kế hoạch nội dung SEO | 62 | 24 | 45 / 17 / 0 / 0 | nền tint 13 · viền 5 · công tắc 3 · bóng/vòng 1 · nút/nền dưới chữ trắng 1 · ghép chuỗi JS / chưa phân loại 1 · (dự phòng var() 38) |
| Hạn ngạch AI và mua thêm | 40 | 20 | 30 / 10 / 0 / 0 | nền tint 7 · viền 5 · nền 4 · công tắc 3 · nút/nền dưới chữ trắng 1 · (dự phòng var() 20) |
| Hàng đợi ảnh | 33 | 18 | 22 / 11 / 0 / 0 | nền tint 7 · viền 5 · công tắc 3 · nút/nền dưới chữ trắng 1 · bóng/vòng 1 · ghép chuỗi JS / chưa phân loại 1 · (dự phòng var() 15) |
| Trợ lý viết nội dung AI v2 - Options | 24 | 18 | 11 / 13 / 0 / 0 | nền tint 8 · viền 6 · nút/nền dưới chữ trắng 3 · bóng/vòng 1 · (dự phòng var() 6) |
| Quy trình duyệt | 26 | 16 | 18 / 8 / 0 / 0 | viền 7 · nền tint 4 · bóng/vòng 2 · nút/nền dưới chữ trắng 1 · công tắc 1 · nền 1 · (dự phòng var() 10) |
| Đăng nhập nhanh & Liên kết tài khoản | 25 | 15 | 17 / 8 / 0 / 0 | viền 4 · nút/nền dưới chữ trắng 4 · nền tint 4 · bóng/vòng 3 · (dự phòng var() 10) |
| Admin Shell | 16 | 14 | 11 / 0 / 0 / 5 | ghép chuỗi JS / chưa phân loại 14 · (dự phòng var() 2) |
| Trạng thái duyệt (khối soạn thảo) | 21 | 14 | 16 / 5 / 0 / 0 | viền 4 · bóng/vòng 3 · nút/nền dưới chữ trắng 2 · nền tint 2 · nền 2 · ghép chuỗi JS / chưa phân loại 1 · (dự phòng var() 7) |
| Vận hành nội dung AI | 24 | 14 | 19 / 5 / 0 / 0 | viền 5 · công tắc 3 · nền tint 2 · nền 2 · nút/nền dưới chữ trắng 1 · ghép chuỗi JS / chưa phân loại 1 · (dự phòng var() 10) |
| Duyệt & xuất bản nội dung | 22 | 13 | 15 / 7 / 0 / 0 | viền 4 · nền tint 4 · công tắc 3 · nút/nền dưới chữ trắng 1 · ghép chuỗi JS / chưa phân loại 1 · (dự phòng var() 9) |
| Block Library - Options | 16 | 11 | 16 / 0 / 0 / 0 | ghép chuỗi JS / chưa phân loại 6 · chữ 3 · nút/nền dưới chữ trắng 2 · (dự phòng var() 5) |
| Nâng cấp nội dung | 16 | 11 | 12 / 4 / 0 / 0 | nút/nền dưới chữ trắng 4 · nền tint 3 · ghép chuỗi JS / chưa phân loại 3 · viền 1 · (dự phòng var() 5) |
| Cài đặt xuất bản tự động | 19 | 9 | 15 / 4 / 0 / 0 | công tắc 4 · viền 3 · nút/nền dưới chữ trắng 1 · nền tint 1 · (dự phòng var() 10) |
| Floating Contact Panel | 14 | 3 | 12 / 2 / 0 / 0 | ghép chuỗi JS / chưa phân loại 2 · nền tint 1 · (dự phòng var() 11) |
| Image Picker | 1 | 1 | 1 / 0 / 0 / 0 | nút/nền dưới chữ trắng 1 |
| admin-base.css | 9 | 1 | 7 / 0 / 0 / 2 | khác (logo) 1 · (dự phòng var() 8) |
| **Tổng (29 file)** | **1650** | **1030** | | |

**Token thay thế theo vai trò**
- nút/nền dưới chữ trắng → `var(--brand-solid, #2050a0)`
- công tắc → `var(--brand-solid, #2050a0)`
- viền → `var(--color-accent, #2050a0)`
- chữ → `var(--color-accent, #2050a0)`
- nền → `var(--brand-solid, #2050a0)` nếu có chữ trắng, không thì tint
- nền tint → `color-mix(in srgb, var(--brand, #2050a0) N%, transparent)`
- bóng/vòng → `color-mix(… var(--brand, #2050a0) …)` (trang trí) hoặc `--focus-ring-color` nếu là vòng focus
- ghép chuỗi JS / chưa phân loại → đọc tay từng chỗ khi sửa (mã màu nằm trong chuỗi style ghép bằng JS nên script không xác định được thuộc tính).
- dự phòng `var(--x, #2050a0)` → không cần đổi; chỉ kiểm tên biến đúng vai trò (vd. `var(--color-accent,…)` mà dùng làm nền dưới chữ trắng thì đổi sang `--brand-solid`).

- Admin Shell: 16 chỗ đều là dữ liệu (bảng `BRAND_TOKENS`, ô màu Tùy biến, giá trị mặc định của trường "Màu thương hiệu" trong Cài đặt), không phải style cứng.
- `admin-base.css`: 8 chỗ là giá trị dự phòng có chủ đích (Q1), 1 chỗ là chú thích.

**1c. "992"** [ĐO BẰNG SCRIPT + SUY RA TỪ CODE] — 1 chỗ trong 37 file.
| File | Chỗ | Loại | Lý do |
|---|---|---|---|
| Admin Shell | `const isMobile = winW < 992;` | [ngưỡng admin] | Quyết định drawer/ép bố cục Dọc của khung admin |
- Đính chính L1-11: Page Builder v3 **không** còn "992"; ngưỡng của nó là `max-width:1023px` (xem trước thiết bị/ngăn kéo), đã đúng 1024. Không có "991".

**1d. "70px" và phép tính theo chiều cao topbar** [SUY RA TỪ CODE] — 26 kết quả chứa "70" + rà `position:sticky`, `100vh`, `calc(`, `scroll-padding`.
Topbar Shell là `position:sticky; top:0` **bên trong** cột cuộn `mainCol`, nên mọi phần tử sticky của panel nhúng có `top` tính từ mép trên cột cuộn, tức là nằm dưới topbar.
| File | Chỗ | Loại |
|---|---|---|
| Admin Shell | `topbarStyle` height:70px | [phụ thuộc topbar] (chính là topbar) |
| Admin Shell | `userBtnStyle` height:70px (nút tài khoản trên topbar) | [phụ thuộc topbar] |
| Admin Shell | `sidebarLogoStyle` height:70px; rail logo 70px; đầu cột con 2 cột height:70px | [phụ thuộc topbar] (canh thẳng hàng với topbar) |
| Admin Shell | thanh mục Cài đặt `navWrap` sticky top:0 | [phụ thuộc topbar] — bị topbar (z-index 70) che khi cuộn |
| Admin Shell | menu "Khác" (Ngang) max-height `calc(100vh - 180px)` | [phụ thuộc topbar] |
| Integrations Panel | cột phải sticky top:16px, max-height `calc(100vh - 104px)` | [phụ thuộc topbar] khi nhúng |
| Floating Contact Panel | cột xem trước sticky top:22px | [phụ thuộc topbar] khi nhúng |
| Đăng nhập nhanh & Liên kết TK | `.sl-prevwrap` sticky top:18px | [phụ thuộc topbar] khi nhúng |
| Trình đơn | thanh công cụ cây menu sticky top:0 | [phụ thuộc topbar] khi nhúng |
| Page Editor - Options | thanh công cụ top:12px, cột phải top:78px | [phụ thuộc topbar] khi mount (2b, chưa mount) — chưa đổi |
| Còn lại: AI Writer (`words: 70`), Shell (dữ liệu biểu đồ `p: 70`, `z-index:70` ×2), MediaPicker (cột 70px, oklch … 70), Page Builder (vòng 70×70), Page Editor (`trim(gist,70)`, bóng 70px), Cài đặt xuất bản (lưới `minmax(70px…)` ×2), Quản lý Bài viết, Trình soạn nội dung (`hsl(h,70,…)`), Trợ lý viết v2 (bóng 70px ×2), content-seo.js (`min: 70`) | [không liên quan] |
- MediaPicker `calc(100vh - 96px)`, Quy trình duyệt `calc(100vh - 32px)`: hộp thoại fixed, [không liên quan].
- Thanh dính đáy: trong Shell, chỉ Trình đơn có `position:sticky;bottom:0` (thanh Lưu của Cài đặt nằm ở đầu trang, không dính đáy).

**1e. ΔE OKLab giữa các `--brand-solid`** [ĐO BẰNG SCRIPT] — 36 cặp, **13 cặp < 0,10**.
- 9 solid đều có L ≈ 0,45–0,48 (vì cùng được làm đậm tới vừa ngưỡng tương phản), nên chỉ khác nhau ở sắc độ và độ bão hòa.
- Gần nhất: Bảo Sơn–Xanh dương 0,044 · Bảo Sơn–Navy 0,050 · Xanh cổ vịt–Mòng két 0,054 · Chàm–Tím than 0,057 · Navy–Xanh cổ vịt 0,075 · Mòng két–Xanh lá 0,077 · Xanh dương–Chàm 0,081 · Navy–Xanh dương 0,082 · Bảo Sơn–Tím than 0,088 · Xanh dương–Tím than 0,089 (+3 cặp 0,09–0,10).
- Ở ngưỡng 0,10, tập lớn nhất giữ được Bảo Sơn chỉ có 3 màu: Bảo Sơn, Xanh lá, Mận (min 0,218).
- **Phương án A — bỏ bớt:** còn 3 màu (Bảo Sơn · Xanh lá · Mận). Chắc chắn phân biệt được, nhưng mất phần lớn lựa chọn.
- **Phương án B — dịch sắc độ/độ đậm:** giữ 9 tên, tách L (solid chỉ cần ≥ ngưỡng nên có thể đậm hơn, vd. Navy, Tím than, Mòng két xuống L ≈ 0,36–0,40) và dịch hue cho các cặp < 0,06. Phải tính lại bảng tĩnh và kiểm lại 20 vai trò.
- **Khuyến nghị:** B cho 4 cặp < 0,06; với các cặp 0,06–0,10 thì giữ nguyên, vì ô màu luôn kèm tên (tooltip/nhãn, L1-25) và người dùng chọn có chủ đích. Ngưỡng 0,10 là ngưỡng tự đặt, khắt khe hơn nhiều so với mức phân biệt được khi đặt cạnh nhau (~0,02–0,03). Chưa đổi bảng màu.

**1f. Vì sao chụp ảnh Admin Shell quá 7 giây** → ghi ở mục L1-21 bên dưới.

#### L1-21 — Chẩn đoán chụp ảnh quá thời gian (bổ sung 01/10/2026) [ĐO BẰNG SCRIPT]
Admin Shell 1440×900, Dashboard, sau khi tải xong:
- DOM: **3.014 phần tử**, 2.044 phần tử có `style` inline; HTML tuần tự hóa **682 KB**.
- Font: **69 `@font-face`** đã đăng ký (Inter nạp 3 lần: Google Fonts `css2` ×2 với bộ trọng lượng khác nhau + `inter.css` của design system; Remix Icon nạp 2 lần). Phần lớn ở trạng thái `unloaded`.
- 21 stylesheet, 2 ảnh (đã tải), 0 SVG, 0 iframe, **0 animation đang chạy**, 0 animation vô hạn. Transition chỉ chạy khi đổi state.
- `renderVals()`: < 1 ms/lần (không phải nguyên nhân). Đọc 1 thuộc tính tính toán của mọi phần tử: 17 ms.
- **Đọc toàn bộ 589 thuộc tính tính toán của 2.978 phần tử: 5.396 ms.** Công cụ chụp ảnh dựng lại DOM bằng cách sao chép từng phần tử kèm toàn bộ style tính toán rồi nhúng font, nên riêng bước này đã gần hết 7 giây; cộng nhúng 69 font (tải và mã hóa base64) là quá thời gian.
- Phần DOM không nhìn thấy vẫn được dựng: panel Tùy biến (`aside` 388px nằm ngoài màn hình), drawer, và các panel Cài đặt nhúng giữ bằng `xWrapStyle` (ẩn nhưng vẫn mount).
- **Đề xuất cho L1-21:** không dựng panel Tùy biến khi đóng (`sc-if`); không mount panel Cài đặt đang ẩn (hoặc chỉ giữ panel còn thay đổi chưa lưu); bỏ nạp trùng Inter/Remix (giữ `inter.css` của DS + 1 Remix); gom style lặp như phân tích L1-21. Mục tiêu: < 1.200 phần tử ở Dashboard, < 25 `@font-face`.

#### Bước 2 — Sửa (Đợt B)

| # | Việc | Cách làm |
|---|---|---|
| 1 | L1-24 | Badge trên nền tối (sidebar Đậm/Gradient, cả mục thường lẫn mục đang chọn): nền `rgba(255,255,255,.12)` → `rgba(0,0,0,.22)` |
| 2 | `--topbar-h` | Khai trên khung `[data-brand-scope]`: 70px; 56px khi `max-width:1023px` hoặc `max-height:480px`. Topbar, nút tài khoản, ô logo sidebar, logo rail 2 cột, đầu cột con 2 cột, menu "Khác", popover đều tính từ biến này (dự phòng 70px). Thêm `--sticky-top` = `--topbar-h` (= 0 khi "Vị trí bố cục: Cuộn") cho phần tử dính của panel nhúng. |
| 3 | Q2 | `isMobile = winW < 1024` (chỗ [ngưỡng admin] duy nhất). |
| 4 | Q3 | 1024–1279: Dọc luôn là rail 76px, bỏ qua "Kích thước Sidebar"; ☰ mở sidebar 258px **dạng phủ** (`position:absolute`, giữ chỗ 76px để nội dung không bị đẩy) + lớp mờ; đóng khi bấm lớp mờ, nhấn Esc hoặc chọn mục. 2 cột: cột icon giữ nguyên, cột menu con 236px chỉ hiện khi bấm nhóm/☰, dạng phủ. focusMode ẩn cả rail; thoát thì về rail. Panel Tùy biến thêm 1 dòng dưới "Kích thước Sidebar": "Chỉ có hiệu lực khi màn hình rộng từ 1280px; hẹp hơn luôn thu gọn." |
| 5 | Q4 | < 1024: nút ☰ có icon + chữ "Menu", cao 44px, không co. ≥ 1024: icon, 44×44, `aria-label` theo hành động ("Mở rộng menu"/"Thu gọn menu"/"Thu gọn/mở rộng menu"). |
| 6 | L1-06 | Class `.pop` cho 5 popover topbar (Chuyển site, Tìm kiếm, Ngôn ngữ, Thông báo, Tài khoản): `max-width: calc(100vw − 16px)`, `max-height: calc(100vh − --topbar-h − 16px)`, cuộn bên trong; < 520px: `position:fixed`, cách mép trái/phải 8px, ngay dưới topbar 8px. |
| 7 | L1-16 | Chân trang `padding-bottom: 76px` (= khoảng cách đáy của nút 20 + chiều cao nút 40 + 16) và cho xuống dòng. Nút nổi đọc `--fab-bottom`: Shell đo phần tử `[data-sticky-savebar]` đang dính ở đáy (khi cuộn, đổi cỡ, sau mỗi lần vẽ) và đặt nút cách mép trên thanh 16px. Gắn `data-sticky-savebar` cho thanh "Chưa lưu" của Trình đơn. |
| 8 | L1-22 | Nhãn một dòng trong vùng cao cố định ≥ 1,3: tên site/plan (1,1 → 1,3), nhãn "Nền tảng" (1,1 → 1,3), chip tài khoản (1,2 → 1,3), số liệu thẻ Dashboard (1 → 1,3). Tiêu đề trang có thể xuống dòng: 1,25 → 1,4. |
| 9 | Khe hở hover | Đọc code 3 bố cục, **không phải sửa** (xem dưới). |
| — | L1-26 (mới) | Thanh mục Cài đặt `top:0` → `top: var(--sticky-top)`; Integrations (cột phải), Floating Contact (cột xem trước), Đăng nhập nhanh (khung xem trước), Trình đơn (dải kéo-thả): `top: calc(var(--sticky-top, 0px) + N)`. |

**Lệch so với yêu cầu, có lý do:** panel nhúng dùng `var(--sticky-top, 0px)` chứ không dự phòng 70px. Khi DC chạy độc lập thì không có topbar; dự phòng 70px sẽ đẩy phần tử dính xuống 70px so với baseline (là hồi quy). Trong Shell thì biến luôn có giá trị.

**Khe hở hover menu con** [SUY RA TỪ CODE] — không có lỗi, không sửa:
- Ngang: menu con `.hdd` cách nút cha 6px, có cầu nối `::before` cao 10px phủ khe; đóng trễ 0,25s (`visibility 0s linear .25s`); mở cả khi Tab (`:focus-within`).
- Dọc thu nhỏ (rail): khung bung `.rfly` là con của mục (`.sbw`), đặt `position:fixed` sát mép phải mục (`--flyLeft = r.right − over`, phần khe bù bằng `padding-left: --flyGap` nằm trong chính khung) nên rê ngang không rời vùng hover; đóng trễ 0,25s.
- 2 cột: bấm để mở, không phụ thuộc hover.
- Vẫn cần thử bằng chuột thật (mục 6 bên dưới).

#### Bước 3 — Kiểm lại [ĐO BẰNG SCRIPT trừ khi ghi khác]

Công cụ: `_audit/harness.html`, thêm `H.lite` (đo tràn có tính vùng cắt của tổ tiên), `H.tick` (chạy từng bước theo nhịp của công cụ chụp, vì bộ hẹn giờ của trang bị trình duyệt hãm khi khung xem trước ở nền).

**Đính chính Đợt A:** tổ hợp "2 cột-Sáng-1024" ở 5.L1-A mục 5 đã đặt `layout:'twocol'`, không phải giá trị thật `'twocolumn'`, nên thực ra đo bố cục không có sidebar. Đợt B đo lại đúng giá trị (dưới đây).

1. **24 tổ hợp Sáng + 6 Tối** (Dọc/Ngang/2 cột × 360, 390, 740×360, 768, 1024, 1280, 1440, 1920; Tối ở 390 và 1024): 30/30 không tràn ngang, 0 phần tử tràn khỏi khung nhìn.
   - Topbar 56px ở 360/390/740×360/768, 70px ở ≥ 1024.
   - Nút menu < 1024: 85×44, chữ "Menu"; ≥ 1024: 44×44.
   - < 1024: Ngang và 2 cột tự về Dọc + drawer (sidebar 268px, ẩn ngoài trái).
   - Dọc: 1024 → rail 76; 1280/1440/1920 → 258. 2 cột: 1024 → cột nội dung bắt đầu ở 76 (cột con ẩn); ≥ 1280 → 312 (76 + 236). Ngang: không có sidebar.
2. **Rail 1024–1279** (1100, 1024): "Kích thước Sidebar" Mặc định và Thu nhỏ đều cho rail 76. ☰ → sidebar 258 `absolute`, nội dung vẫn bắt đầu ở 76 (không bị đẩy), có lớp mờ. Esc → về 76. 2 cột + ☰ → cột con `absolute` ở x=76, có lớp mờ. focusMode → không còn rail, nội dung ở x=0; tắt → rail 76. 1024 + Thu nhỏ + ☰ → 258 phủ. 1280: Thu nhỏ → 76, Mặc định → 258.
3. **Popover** Thông báo / Ngôn ngữ / Tài khoản ở 320, 390, 600, 740×360, 1024: 15/15 nằm trọn trong khung nhìn, cách mép ≥ 8px.
   - 320 và 390: rộng toàn khung, cách trái/phải đúng 8px, mép trên 64 = topbar 56 + 8.
   - 740×360: `max-height` 288 (= 360 − 56 − 16); Thông báo và Tài khoản cuộn bên trong, mép dưới cách 17 và 8px.
   - **Vụ "popover Thông báo ở 320 không tìm thấy" (L1):** nay tìm thấy ở 320 (8…312). Lần trước popover `right:0` rộng 340px nằm hẳn ngoài mép trái nên bộ đo bỏ qua; đây chính là L1-06, đã sửa.
4. **Phần tử dính dưới topbar** (cuộn 700px): thanh mục Cài đặt dính ở y=70, sát mép dưới topbar 70 (trước: y=0, bị topbar che). Integrations cột phải dính ở y=86 (= 70 + 16). Floating Contact cột xem trước `top` = 92px ở 1440 và 78px (= 56 + 22) ở 768. Ở 768, Integrations/Đăng nhập nhanh chuyển về một cột, không còn dính (đúng thiết kế).
5. **L1-24** — 9 màu × Đậm/Gradient × mục thường/đang chọn ("Liên hệ" có badge 12), 1440: 36/36 đạt, thấp nhất 7,39 (Xanh cổ vịt, Gradient, đang chọn), cao nhất 13,15. Trước khi sửa: 4,06–5,01.
6. **Nút nổi và chân trang:** cuộn hết trang Dashboard ở 1440 và 390: 0 chữ chân trang chồng nút.
   - Trong Shell **không có thanh Lưu dính đáy thật**: Trình đơn ẩn thanh "Chưa lưu" khi nhúng (`barOn = … && !embedded`, nút Lưu nằm ở đầu trang Shell), Cài đặt cũng lưu ở đầu trang. Kiểm cơ chế bằng thanh giả `[data-sticky-savebar]` cao 64px: 1440 và 390 → nút nổi cách mép trên thanh **16px**; cuộn tới cuối (thanh hết dính) → nút về 20px, không chồng chân trang.
7. **L1-22:** sau sửa, 0 phần tử chữ có dấu bị cắt (`scrollHeight > clientHeight` trong vùng `overflow` ≠ visible) ở 1440 và 390. Còn lại dưới 1,3 khi đo: "An Vinh", "Ngọc Anh" (1,3 bị làm tròn xuống khi tính px) và 4 số liệu thẻ Dashboard (1,0) — số liệu đã sửa lên 1,3 sau lần đo này [SUY RA TỪ CODE, chưa đo lại].
8. **4 tổ hợp hồi quy Shell** (đo đủ: tràn, tương phản, cỡ chữ): Dọc-Sáng-1440, Ngang-Tối-1280, 2 cột-Sáng-1024 (giá trị đúng `twocolumn`), Dọc-Tối-390: 0 tràn, 0 chữ không đạt tương phản ở cả 4.
9. **So với `baseline-layout.json`** (29 DC mở độc lập, 390/1024/1440): 26/29 trùng hoàn toàn. Khác:
   - Admin Shell: topbar 56 ở 390; rail 76 ở 1024; chân trang cao hơn (đệm 76px) ở cả 3 bề rộng → **đúng chủ đích Đợt B**.
   - Page Builder v3: thêm 1 phần tử `fixed` ở đáy (`bottom:24px`, 195×52 / 243×38) ở cả 3 bề rộng. File **không sửa** trong Đợt B và không phụ thuộc `--topbar-h`; dạng thông báo nổi (toast) theo trạng thái lưu cục bộ của lần mở trước. [SUY RA TỪ CODE] Không tính hồi quy; cần nhìn ở lượt sau nếu bạn muốn chắc.
   - Floating Contact, Integrations, Đăng nhập nhanh, Trình đơn (có sửa `top`): trùng baseline, vì `--sticky-top` không có khi đứng riêng.
   - **Hồi quy L1-R: 0.**

**Mã mới**
| Mã | Tổ hợp | Lỗi | Số đo | Quy tắc | Gốc | Đề xuất | Bằng chứng | Mức độ | Trạng thái |
|---|---|---|---|---|---|---|---|---|---|
| L1-26 | Mọi bố cục, ≥ 1024, Cài đặt cuộn xuống | Thanh mục Cài đặt (sticky `top:0`) và cột dính của panel nhúng (`top` 16/18/22px) bị topbar dính (z-index 70) che, vì sticky tính từ mép trên cột cuộn có chứa topbar | trước: thanh mục ở y=0 dưới topbar 70px | P10 (focus không bị che), CLAUDE §8 | shell + trang | `--sticky-top` | [SUY RA TỪ CODE] + đo sau sửa | Cao | Đóng |

**Trạng thái lỗi L1 sau Đợt B**
- Đóng ở Đợt B (9): L1-06, L1-07, L1-09, L1-10, L1-11, L1-16, L1-22, L1-24, L1-26.
- Đã đóng trước (Đợt A, 4): L1-02, L1-03, L1-04, L1-05. L1-01: đóng ở tầng token, còn mở ở tầng trang qua L1-A-P01. L1-23: không phải lỗi.
- Còn mở (11): L1-08, L1-12, L1-13, L1-14, L1-15, L1-17, L1-18, L1-19, L1-20, L1-21, L1-25 (+ L1-A-P01 cho L2).

**Changelog Đợt B**
| Mã | File | Thay đổi | Trang ảnh hưởng | Đã kiểm lại + bằng chứng |
|---|---|---|---|---|
| L1-24 | Admin Shell | Badge nền tối `rgba(0,0,0,.22)` | Shell | Bước 3.5: 36/36 ≥ 7,39 [ĐO] |
| L1-09 | Admin Shell | `--topbar-h` + media query; 5 chỗ chiều cao 70px, menu "Khác", popover tính từ biến | Shell + panel nhúng | 3.1: 56/70 đúng ở 8 bề rộng [ĐO] |
| L1-11 | Admin Shell | `winW < 1024` | Shell | 3.1 [ĐO] |
| L1-10 | Admin Shell | Rail 1024–1279, mở dạng phủ, Esc/bấm ngoài đóng, focusMode ẩn rail, 2 cột cột con phủ, ghi chú trong Tùy biến | Shell | 3.2: 11 trường hợp [ĐO] |
| L1-07 | Admin Shell | Nút "Menu" < 1024, 44px | Shell | 3.1: 85×44 / 44×44 [ĐO] |
| L1-06 | Admin Shell | `.pop` cho 5 popover | Shell | 3.3: 15/15 [ĐO] |
| L1-16 | Admin Shell, Trình đơn | Đệm chân trang 76px; `--fab-bottom` đo `[data-sticky-savebar]`; gắn thuộc tính cho thanh "Chưa lưu" của Trình đơn | Shell, Trình đơn | 3.6 [ĐO] |
| L1-22 | Admin Shell | 5 line-height | Shell | 3.7 [ĐO] + 1 chỗ [SUY RA] |
| L1-26 | Admin Shell, Integrations Panel, Floating Contact Panel, Đăng nhập nhanh & Liên kết TK, Trình đơn | `--sticky-top` trên khung; `top` của phần tử dính tính từ biến | Shell + 4 panel | 3.4 + 3.9 (đứng riêng không đổi) [ĐO] |
| — | `_audit/harness.html` | `H.lay/H.layouts`, `H.diag`, `H.lite`, `H.tick`, `H.dumpL` | Công cụ đo | — |

**Cần bạn xem bằng mắt (Đợt B)**
1. Dọc, Sáng, cửa sổ 1100px: rail 76px; bấm ☰ → sidebar đầy đủ phủ lên, nội dung không xê dịch; bấm ra ngoài / Esc → đóng.
2. 2 cột, 1100px: bấm một nhóm ở cột icon → cột menu con phủ lên; chọn một mục → cột tự đóng.
3. Dọc, 390px: nút trái topbar có icon + chữ "Menu", topbar thấp hơn (56px), logo/chip tài khoản không bị cắt.
4. Điện thoại xoay ngang 740×360: topbar 56px; mở Thông báo → danh sách cuộn bên trong, không tràn đáy.
5. 320–390px: mở Thông báo, Ngôn ngữ, Tài khoản → popover rộng toàn màn hình, cách mép 8px.
6. Dọc, 1440 và 390: cuộn hết Dashboard → dòng "Phát triển bởi…" ở chân trang không bị nút Tùy biến đè.
7. Cài đặt › Tích hợp & Theo dõi, 1440: cuộn xuống → thanh mục bên trái và cột phải dính ngay dưới topbar, không bị che.
8. Ngang, ≥ 1280 (Chrome + Safari): rê chuột từ "Trang" xuống mục con đầu, và rail Dọc thu nhỏ: rê ngang sang khung bung → không đóng giữa chừng.

**Cần quyết**
- **ΔE màu thương hiệu** (1e): khuyến nghị phương án B cho 4 cặp < 0,06 (Bảo Sơn–Xanh dương, Bảo Sơn–Navy, Xanh cổ vịt–Mòng két, Chàm–Tím than); giữ nguyên các cặp 0,06–0,10.

---

### 5.L1-C — Đợt C: bảng màu, trạng thái, dữ liệu, loại tài khoản (01/10/2026)

**Tiến độ:** Bước 1 xong · Bước 2 xong · Bước 3 xong · Bước 4 xong · Bước 5 xong (trừ 2 mục [CHƯA KIỂM ĐƯỢC] ghi rõ bên dưới). Kế hoạch L1-A-P01: có (cuối mục). L1-21: chưa làm (theo lệnh).

#### Bước 1 — Harness [ĐO BẰNG SCRIPT]
**1a.** Thêm vào `_audit/harness.html`:
- `H.check(id, want)`: đọc lại `state` của Shell và so với giá trị đặt; kiểm giá trị có trong danh sách hợp lệ (`H.VALID`, lấy từ code); đọc **dấu hiệu DOM** (`H.sig`): bố cục (có `aside` sidebar < 300px → Dọc; có `.hn` → Ngang; có cột 76px chứa nút → 2 cột), bề rộng sidebar, `data-bs-theme`/`data-scheme`, `--brand` trên khung, nền sidebar/topbar sáng hay đậm hay gradient, `max-width:1180px` của vùng nội dung (Hộp), `--sticky-top` (Cuộn), menu theo plane. So với giá trị kỳ vọng đã tính quy tắc ép (`< 1024` → Dọc + drawer 268; 1024–1279 → rail 76; Tối → sidebar luôn nền cố định). Không khớp → `valid:false` = **[KHÔNG HỢP LỆ]**, không tính đạt.
- Lần chạy thử đầu bắt được 3 lỗi của chính bộ đo: (1) panel Tùy biến cũng là `<aside>` 388px nên bị nhận nhầm là sidebar; (2) dò "Hộp" trên mọi `div` (có `div` khác 1180px); (3) plane TMĐT ở 2 cột không có chữ "Kho hàng" trong DOM khi nhóm đóng. Đã sửa (lọc `aside` < 300px; chỉ đọc vùng nội dung chứa `h1`; đọc thêm `aria-label`/`title`), chạy lại.
- `H.stable(fr)`: bước ổn định trước khi đo (xem Bước 2). `H.C20`/`H.qC20`: 20 tổ hợp Tùy biến với tên giá trị đúng.

**1b. Đối chiếu tên giá trị mục 3a với code** (`CZ`, `czPlane`, state khởi tạo):
| Thuộc tính | Bảng 3a ghi | Code | Kết luận |
|---|---|---|---|
| `layout` | `twocol` | `twocolumn` | **Sai**, đã sửa bảng |
| `plane` | nhãn "SaaS" | nhãn "Nền tảng" (đổi ở Đợt C) | Cập nhật bảng |
| `brandColor` | 9 màu | 6 màu (Đợt C) | Cập nhật bảng |
| `sidebarColor` khi Tối | "bản Sáng bị ép thành tối" | cả 3 màu dùng nền cố định `#111a30` | Bảng thiếu, đã bổ sung |
| `scheme`, `width`, `layoutPosition`, `sidebarSize`, `topbarColor` | `light/dark`, `fluid/boxed`, `fixed/scrollable`, `default/small`, `light/dark` | trùng | Đúng |

**1c. Chạy lại 20 tổ hợp bố cục ở 1440** (14 pairwise P01–P14 + 6 rủi ro R1–R6, 7 tổ hợp có 2 cột; định nghĩa trong `H.C20`; danh sách L1 không được lưu nên dựng lại đủ 9 trục, tên đúng):
- **20/20 hợp lệ** sau khi đặt state (P01 lần đầu [KHÔNG HỢP LỆ]: lệnh đặt state chạy trước khi Shell mount xong, state còn của tổ hợp trước; chạy lại riêng → hợp lệ). Thêm bước chờ `H.inst()` trước lệnh đầu.
- Tràn ngang 0/20 · phần tử tràn 0/20 · chữ bị cắt `…` 0/20.
- Vị trí: Dọc mặc định → topbar từ x=258, `h1` x=282; Dọc thu nhỏ → 76/100; Ngang → topbar x=0, `h1` x=24 (Hộp: 149); **2 cột → topbar x=312 (76 + 236), `h1` x=336** ở cả 7 tổ hợp 2 cột. Topbar 70px ở cả 20.
- **So với cũ:** L1 và Đợt A đặt `layout:'twocol'` (giá trị không tồn tại) → Shell vẽ như không có sidebar, nên mọi kết quả "2 cột" của L1 pairwise/rủi ro thực ra là bố cục khác. Nay đo đúng: không phát sinh lỗi bố cục mới ở 2 cột. (Đợt B đã dùng đúng `twocolumn`.)

#### Bước 2 — Thông báo nổi ở Page Builder v3 [ĐO BẰNG SCRIPT + SUY RA TỪ CODE]
- **Kết luận: baseline không ổn định, không phải hồi quy.** File dùng chung không liên quan.
- **Cơ chế:** `componentDidMount` → nếu `localStorage['bswebsaas:pb:v3:draft']` có bản nháp (`loadDraft()` đặt `_restored`) thì sau 300ms gọi `notify('Đã khôi phục bản nháp cục bộ')`; thông báo tự tắt sau 2.200ms (`setTimeout`). Baseline Đợt B chụp lúc 3,5s: lần chụp trước chưa có bản nháp (không có thông báo); lần kiểm lại đã có bản nháp do một phiên mở Page Builder ghi trong lúc đó → bắt được thông báo. Hẹn giờ của khung xem trước bị trình duyệt hãm khi ở nền nên thông báo còn ở 3,5s.
- **Chứng minh: đo 2 lần liên tiếp** (1440×900, cùng khoá storage `bs-scheme`, `bswebsaas:pb:v3:draft`, `bswebsaas:pe:handoff`): cả 2 lần không có thông báo ở ~0,8–1,0s, **có** "Đã khôi phục bản nháp cục bộ" ở ~1,8s, 2,8s, 3,8s, **hết** ở ~5,8s.
- **Harness:** `H.stable(fr)` chờ tới khi không còn phần tử `position:fixed` sát đáy có `z-index ≥ 500` (tối đa 6s) rồi mới đo; ghi lại danh sách khoá storage `bs*`/`bswebsaas*` vào kết quả để chỉ so baseline cùng điều kiện; animation/transition đã tắt sẵn trong `H.mk`. **Lệch so với yêu cầu, có lý do:** không xoá storage tạm, vì storage dùng chung với màn xem thật của bạn (có thể chứa bản nháp thật) và công cụ chặn xoá khoá không do lượt này tạo.

#### Bước 3 — Bảng màu [ĐO BẰNG SCRIPT]
**3.1 Chọn đại diện** (ΔE OKLab trên `--brand-solid`; Bảo Sơn, Xanh lá, Mận, Than chì cố định):
| Tổ hợp | ΔE nhỏ nhất | Cặp gần nhất |
|---|---|---|
| **Xanh cổ vịt + Chàm** | **0,091** | Bảo Sơn–Xanh cổ vịt (Bảo Sơn–Chàm cũng 0,091) |
| Xanh cổ vịt + Tím than | 0,088 | Bảo Sơn–Tím than |
| Mòng két + Chàm | 0,078 | Xanh lá–Mòng két |
| Mòng két + Tím than | 0,078 | Xanh lá–Mòng két |
→ Chọn **Xanh cổ vịt** và **Chàm**. Bảng ΔE đầy đủ (6 màu, solid):
| | Xanh cổ vịt | Xanh lá | Chàm | Mận | Than chì |
|---|---|---|---|---|---|
| Bảo Sơn | 0,091 | 0,218 | 0,091 | 0,245 | 0,125 |
| Xanh cổ vịt | — | 0,130 | 0,178 | 0,244 | 0,115 |
| Xanh lá | | — | 0,306 | 0,282 | 0,168 |
| Chàm | | | — | 0,266 | 0,197 |
| Mận | | | | — | 0,204 |
Mọi cặp ≥ 0,06 → **áp dụng bộ 6 màu**.

**3.2 Cân độ đậm** (L OKLCH của solid; Bảo Sơn 0,446): Xanh cổ vịt 0,471 (+0,025) · Xanh lá 0,462 (+0,017) · Chàm 0,457 (+0,011) · Mận 0,459 (+0,013) · Than chì 0,372 (−0,074, miễn). **Không màu nào phải chỉnh L.**

**3.3 Bảng token cuối** (tính theo cơ chế Đợt A; 3 màu đã có giữ đúng giá trị Đợt A):
| Màu | Gốc | `--brand-solid` | `--brand-ink` Sáng | `--brand-ink` Tối | Vạch vàng: Đậm / Gradient |
|---|---|---|---|---|---|
| Bảo Sơn | `#2050a0` | `#2050a0` | `#2050a0` | `#98b1dc` | 4,81 / 3,83 |
| Xanh cổ vịt | `#0e7490` | `#00657f` | `#05708c` | `#9cbfd4` | 4,33 / 3,29 |
| Xanh lá | `#15803d` | `#056b30` | `#0a7636` | `#9ec4b3` | 4,40 / 3,30 |
| Chàm | `#4338ca` | `#4338ca` | `#4338ca` | `#a0adef` | 4,92 / 3,92 |
| Mận | `#9d174d` | `#9d174d` | `#9d174d` | `#d2a2b7` | 5,25 / 3,91 |
| Than chì (mới) | `#334155` | `#334155` (đạt sẵn) | `#334155` | `#9da8bb` | 5,94 / 5,13 |
Đã áp: `Component.PAL()` trong Admin Shell (một nguồn cho `BRAND_TOKENS`, ô màu Tùy biến và danh sách giá trị hợp lệ khi đọc `bs-ui`), CLAUDE.md §16, `DS - Prompt G2.md` §4b.

**3.4 Xung đột ngữ nghĩa** — ΔE solid ↔ màu trạng thái (bậc `-solid` / màu gốc) và vạch vàng:
| Màu | Thành công | Nguy hiểm | Cảnh báo | Thông tin | Vạch vàng |
|---|---|---|---|---|---|
| Bảo Sơn | 0,192 / 0,297 | 0,324 / 0,372 | 0,470 | 0,126 / 0,220 | 0,442 |
| Xanh cổ vịt | 0,109 / 0,233 | 0,289 / 0,335 | 0,415 | **0,102** / 0,197 | 0,381 |
| Xanh lá | **0,108** / 0,233 | 0,287 / 0,336 | 0,393 | 0,201 / 0,268 | 0,349 |
| Chàm | 0,267 / 0,349 | 0,358 / 0,400 | 0,507 | 0,174 / 0,245 | 0,486 |
| Mận | 0,286 / 0,372 | 0,155 / 0,235 | 0,409 | 0,282 / 0,337 | 0,385 |
| Than chì | 0,207 / 0,338 | 0,298 / 0,369 | 0,485 | 0,210 / 0,305 | 0,449 |
- **0 cặp < 0,10.** Gần nhất: Xanh cổ vịt–Thông tin 0,102, Xanh lá–Thành công 0,108, Xanh cổ vịt–Thành công 0,109 (ngoài 4 màu yêu cầu: `secondary-solid` `#2f69d4` với Bảo Sơn 0,104, Chàm 0,108).
- Không phát sinh kiểm bắt buộc. Vẫn rà Shell theo quy tắc mới: nút "Đăng xuất" có icon + chữ + hộp xác nhận (đạt); **chip trạng thái ở Hộp duyệt và bảng "Nội dung gần đây" thiếu icon** (chỉ nền nhạt + chữ, bảng có chấm tròn) → **L1-C-03**, đã thêm icon.

**3.5 L1-25:** ô màu hiển thị `--brand-solid`, vòng chọn theo solid, `title`/`aria-label` giữ tên màu. Màu đã lưu không còn trong bảng (vd. Navy, Xanh dương) → về Bảo Sơn khi đọc `bs-ui`.

#### Bước 4 — Đợt C
1. **Q5 / L1-12:** `loadUi()` trong constructor, `saveUi()` trong `componentDidUpdate`. Khoá `bs-ui` = `{version:1, 9 thuộc tính}`; JSON hỏng / không phải object / `version ≠ 1` → toàn bộ mặc định; từng giá trị lạ (kể cả màu ngoài bảng, sai kiểu) → mặc định của khoá đó. Không có `bs-ui` → `scheme` đọc từ `bs-scheme`. Khi cả 9 về mặc định thì xoá khoá; "Đặt lại" xoá khoá và đưa cả plane về Site. Ghi chú trong code: bản thật lưu phía server theo người dùng; plane chỉ là bộ chọn xem thử của prototype, bản thật lấy theo tài khoản.
2. **Q7 / L1-13 / L1-15:** nhãn "Nền tảng". Kiểm panel trước khi đổi map [SUY RA TỪ CODE]:
   - Email Settings: nhận `platform` (`isPlatform`, tab mặc định `tpl`, ẩn hộp thư dùng chung) → map `emAccType` đổi `platform → 'platform'`.
   - Đăng nhập nhanh & Liên kết TK: chỉ phân biệt `saas`/khác (`isSaas`), **không có nhánh `platform`** → giữ `account-type="saas"` cứng ở mục "Đăng nhập nhanh" của Nền tảng; ghi **L1-C-P01**, chuyển L2, không tự thêm nhánh.
   - Các panel nhúng còn lại (Hạn ngạch AI, Integrations, Floating Contact, Trình đơn, MediaPicker) không nhận `accountType` từ Shell.
3. **L1-08 + L1-19:** 3 chip là `<button class="rgc" aria-pressed>` trong `role="group"` (đang chọn = nền đặc + chữ đậm; trạng thái qua thuộc tính `aria-pressed`, không qua hole style). Một nguồn `S.range` (+ `rangeFrom/rangeTo`) cho chip, nhãn nút khoảng ngày và cột. Nút khoảng ngày mở hộp chọn "Từ ngày/Đến ngày" (90 ngày gần nhất, báo lỗi ngay tại hộp); áp dụng → bỏ chọn cả 3 chip. "Năm" = 12 cột tháng (Th8/2025–Th7/2026). Dữ liệu cố định 01/08/2025–17/07/2026, sinh bằng công thức, không ngẫu nhiên, không đọc ngày hiện tại.
4. **L1-18:** cột nền đặc `--brand-solid`. ≤ 12 cột: ghi giá trị mọi cột (12px); cột hẹp hơn chữ → nhãn xoay dọc. > 12 cột: chỉ ghi cột cao nhất + đường trung bình nét đứt có nhãn "TB … /ngày". Nhãn trục ngày đặt theo bề rộng đo bằng ResizeObserver: luôn có ngày đầu và ngày cuối, bỏ bớt nhãn giữa để không chồng. Khung biểu đồ có `role="img"` + `aria-label` tóm tắt (khoảng ngày, số cột, cao nhất, trung bình). Không thêm trục y.
5. **L1-14:** kết quả tìm kiếm mẫu theo plane — Site: trang, sản phẩm, lead, thành viên · TMĐT: đơn hàng, khách hàng, sản phẩm, trang · Nền tảng: khách hàng, 2 website, nhật ký hệ thống.
6. **L1-17:** một mảng `REVIEW` (5 mục: 3 chờ duyệt + 2 phản biện, 2 quá hạn) cho thẻ số liệu (đổi nhãn "Chờ duyệt" → **"Chờ xử lý"**, phụ đề "chờ duyệt + phản biện", "2 quá hạn"), badge thẻ Hộp duyệt ("5 chờ xử lý", thêm icon), danh sách 3 mục mới nhất + liên kết "và 2 mục khác". Thông báo đầu ("Thu Hà đã gửi 'Bảng giá dịch vụ 2026' chờ bạn duyệt") khớp mục đầu. **Topbar không có lối vào Hộp duyệt** (đã gỡ theo AdminNav, ghi chú trong code) → không thêm (P16).

#### Bước 5 — Kiểm lại
1. **Lưu tùy biến** [ĐO BẰNG SCRIPT trên chính mã `PAL/UI_DEF/UI_OK/loadUi/saveUi` trích từ file, chạy với kho lưu giả]: lưu 9 thuộc tính → đọc lại khớp 9/9; về mặc định → xoá khoá; `'{bad'`, `'null'`, `version:2` → mặc định 9/9; `brandColor:'#2f6fd0'`, `scheme:'purple'`, `sidebarColor:42` → từng khoá về mặc định, `layout`/`plane` hợp lệ giữ nguyên; không có `bs-ui` → `scheme` theo `bs-scheme`. Không lỗi.
   **Tải lại thật trong iframe: [CHƯA KIỂM ĐƯỢC]** — storage dùng chung với màn xem của bạn; công cụ chặn ghi đè/xoá `bs-ui` (có thể là tùy biến thật của bạn). Cần bạn thử (mục 6).
2. **Tương phản** — bảng công thức 6 màu × 20 vai trò (R18 nay gồm vòng focus trắng trên nền đậm):
| Vai trò | Ngưỡng | Bảo Sơn | Xanh cổ vịt | Xanh lá | Chàm | Mận | Than chì |
|---|---|---|---|---|---|---|---|
| R01 Nút chính: trắng/solid | 4,5 | 7,73 | 6,63 | 6,66 | 7,90 | 7,88 | 10,35 |
| R02 Badge menu: trắng/solid | 4,5 | 7,73 | 6,63 | 6,66 | 7,90 | 7,88 | 10,35 |
| R03 Topbar đậm: tên | 4,5 | 7,73 | 6,63 | 6,66 | 7,90 | 7,88 | 10,35 |
| R04 Topbar đậm: chữ phụ .85 | 4,5 | 6,09 | 5,27 | 5,29 | 6,17 | 6,04 | 8,01 |
| R05 Topbar đậm: chip .85/.08 | 4,5 | 5,12 | 4,51 | 4,54 | 5,27 | 5,36 | 6,45 |
| R06 Sidebar Đậm: chữ .86 | 4,5 | 7,64 | 6,91 | 7,02 | 7,77 | 8,19 | 9,32 |
| R07 Sidebar Đậm: tiêu đề .78 | 4,5 | 6,59 | 5,99 | 6,08 | 6,68 | 6,98 | 7,98 |
| R08 Sidebar Đậm: mục chọn trắng/.12 | 4,5 | 7,00 | 6,43 | 6,50 | 7,27 | 7,89 | 8,28 |
| R09 Sidebar Đậm: vạch vàng | 3 | 4,81 | 4,33 | 4,40 | 4,92 | 5,25 | 5,94 |
| R10 Gradient: chữ .85 | 4,5 | 6,09 | 5,27 | 5,29 | 6,17 | 6,04 | 8,01 |
| R11 Gradient: tiêu đề .85 | 4,5 | 6,09 | 5,27 | 5,29 | 6,17 | 6,04 | 8,01 |
| R12 Gradient: mục chọn trắng/.12 | 4,5 | 5,77 | 5,11 | 5,14 | 6,02 | 6,31 | 7,25 |
| R13 Gradient: vạch vàng | 3 | 3,83 | 3,29 | 3,30 | 3,92 | 3,91 | 5,13 |
| R14 Sidebar Sáng: mục chọn ink/10% | 4,5 | 6,61 | 4,94 | 5,02 | 6,72 | 6,62 | 8,75 |
| R15 Link trên #fff | 4,5 | 7,73 | 5,67 | 5,74 | 7,90 | 7,88 | 10,35 |
| R16 Link trên #f4f5fa | 4,5 | 7,10 | 5,21 | 5,27 | 7,26 | 7,24 | 9,51 |
| R17 Tab Cài đặt ink/10% trên #f4f5fa | 4,5 | 6,09 | 4,56 | 4,63 | 6,19 | 6,10 | 8,07 |
| R18 Vòng focus (min: ink/#fff, ink/#f4f5fa, trắng/sidebar Đậm, trắng/solid) | 3 | 7,10 | 5,21 | 5,27 | 7,26 | 7,24 | 9,51 |
| R19 Tối: accent trên #16203a/#1a2440 | 4,5 | 7,06 | 7,87 | 8,04 | 7,08 | 6,98 | 6,39 |
| R20 Tối: accent trên #0f1729/tint 10% | 4,5 | 7,75 | 8,44 | 8,61 | 7,82 | 7,89 | 7,15 |
**0/240 ô không đạt.** Vòng focus Tối (ink tối trên sidebar `#111a30`, cột con `#1a1e22`, topbar `#16203a`): min 6,72 (Than chì).
   **DOM đã render** (1440, Sáng trừ khi ghi khác; `H.measure`, mọi chữ nhìn thấy): 6 màu × {Dọc + Gradient + topbar Đậm, 2 cột Đậm, 2 cột Sáng, 2 cột Gradient, Tối + topbar Đậm} = **30/30 tổ hợp hợp lệ, 0 chữ không đạt, 0 chữ < 12px** (gồm rail và cột menu con của 2 cột ở 3 màu sidebar).
3. **3 plane** (1440): tìm kiếm Site "Trang chủ | Máy lọc nước An Vinh Pro | Nguyễn Văn B — cần báo giá | Thu Hà"; TMĐT "Đơn hàng DH-10482 · 2.450.000 ₫ | Trần Thị Mai | Máy lọc nước An Vinh Pro | Trang chủ"; Nền tảng "Cơ Khí Chính Xác An Vinh | anvinh.vn | greenfarm.vn | Đổi gói dịch vụ — MediLab Clinic". `emAccType`: site / ecom / platform. Nhãn Tùy biến: Site/TMĐT/Nền tảng. Nhóm Cài đặt theo plane không đổi so với L1 (không sửa `settingsGroupsAll`). account-type cứng: Email hệ thống ×2 = `platform`, Đăng nhập nhanh (Nền tảng) = `saas` (L1-C-P01).
4. **Biểu đồ** (cột / nhãn giá trị / chồng nhãn giá trị / chồng nhãn trục / nhãn trục tràn khung / đường TB / nhãn nút khoảng ngày):
| Bề rộng | Chip | Cột | Nhãn giá trị | Chồng | Nhãn trục | Chồng / tràn | TB | Khoảng ngày |
|---|---|---|---|---|---|---|---|---|
| 1440 (khung 644px) | 7 ngày | 7 | 7 | 0 | 11/7…17/7 (7) | 0 / 0 | — | 11/07–17/07/2026 |
| 1440 | 30 ngày | 30 | 1 (cao nhất) | 0 | 18/6 … 17/7 (11) | 0 / 0 | có | 18/06–17/07/2026 |
| 1440 | Năm | 12 | 12 | 0 | Th8…Th7 (12) | 0 / 0 | — | 01/08/2025–17/07/2026 |
| 1440 | tự chọn 01/05–15/06 | 46 | 1 | 0 | 1/5 … 15/6 (12) | 0 / 0 | có | 01/05–15/06/2026, 3 chip đều `aria-pressed=false` |
| 390 (khung 299px) | 7 ngày | 7 | 7 | 0 | 11/7, 13/7, 15/7, 17/7 | 0 / 0 | — | 11/07–17/07/2026 |
| 390 | 30 ngày | 30 | 1 | 0 | 18/6, 28/6, 3/7, 8/7, 17/7 | 0 / 0 | có | 18/06–17/07/2026 |
| 390 | Năm | 12 | 12 (xoay dọc) | 0 | Th8, Th12, Th2, Th4, Th7 | 0 / 0 | — | 01/08/2025–17/07/2026 |
Nhãn giá trị 12px. Chip cao 32px (1440) và 43px (390, nhờ quy tắc vùng chạm của `admin-base.css`). Mọi tổ hợp `H.check` hợp lệ.
5. **Số đếm duyệt** (1440): thẻ "Chờ xử lý" = 5 · badge "5 chờ xử lý" · danh sách 3 mục + "và 2 mục khác" (3 + 2 = 5) · thông báo đầu khớp mục đầu. Topbar không có Hộp duyệt.
6. **Focus:**
   - Phần tử cuộn của Shell = `div` cột chính (`overflow-y:auto`, chứa topbar dính). Trước Đợt C **không có** `scroll-padding-top` ở đâu cả (DS B5 ghi đã xử lý, Shell thì chưa) → **L1-C-02**, thêm `scroll-padding-top: var(--sticky-top, var(--topbar-h))`. Đo: **70px** (topbar 70) ở 1440, **56px** (topbar 56) ở 390.
   - Tương phản vòng focus: trước Đợt C vòng focus = `--brand-ink` ở mọi nơi, kể cả trên sidebar Đậm/Gradient và topbar Đậm (vd. Bảo Sơn ink `#2050a0` trên sidebar Đậm ≈ 1,6) → **L1-C-01**: vùng nền đậm ở chế độ Sáng đổi `--focus-ring-color: #fff`; popover `.pop` khai lại `--brand-ink` (popover nền sáng nằm trong topbar Đậm). Sau sửa: R18 ≥ 5,21 (mọi màu, mọi nền Sáng); Tối ≥ 6,72.
   - `focus()` + `matches(':focus-visible')`: `document.activeElement` đúng nút nhưng `:focus-visible` = false vì iframe không giữ focus cửa sổ (`hasFocus()=false`). **[CHƯA KIỂM ĐƯỢC]** — giới hạn của khung xem trước; cần thử bằng bàn phím thật (mục 6).
7. **4 tổ hợp hồi quy Shell** (đã qua `H.check`, 4/4 hợp lệ): Dọc-Sáng-1440 · Ngang-Tối-1280 · 2 cột-Sáng-1024 · Dọc-Tối-390 → tràn ngang 0, phần tử tràn 0, chữ không đạt 0, chữ < 12px 0. Bố cục: sidebar 258 / Ngang / 2 cột / drawer 268.
8. **DC đứng riêng:** Đợt C không chạm file dùng chung (`admin-base.css`, `*.js`) và không chạm panel nhúng → không cần so baseline. File đã sửa: Admin Shell, `_audit/harness.html`, CLAUDE.md, `DS - Prompt G2.md`, AUDIT.md.

**Mã mới**
| Mã | Tổ hợp | Lỗi | Số đo | Quy tắc | Gốc | Đề xuất / đã làm | Bằng chứng | Mức độ | Trạng thái |
|---|---|---|---|---|---|---|---|---|---|
| L1-C-01 | Sáng; sidebar Đậm/Gradient, rail/cột con 2 cột, topbar Đậm | Vòng focus = `--brand-ink` trên nền đậm cùng sắc | Bảo Sơn ≈ 1,6 trên sidebar Đậm | P10, WCAG 2.4.11/1.4.11, DS B5 | shell | Vòng trắng trên vùng đậm; `.pop` khai lại ink | [SUY RA TỪ CODE] + công thức | Cao | Đóng |
| L1-C-02 | Mọi bố cục, cuộn bằng Tab | Phần tử cuộn của Shell không có `scroll-padding-top` → phần tử được focus có thể nằm dưới topbar dính | không có khai báo nào trong 37 file | P10 ("focus không bị header che"), DS B5 | shell | `scroll-padding-top: var(--sticky-top)` | [ĐO BẰNG SCRIPT] | Trung bình | Đóng |
| L1-C-03 | Dashboard | Chip trạng thái (Hộp duyệt, bảng Nội dung gần đây) không có icon | — | Quy tắc ngữ nghĩa Duyệt Đợt B | shell | Thêm icon theo trạng thái | [SUY RA TỪ CODE] | Thấp | Đóng |
| L1-C-P01 | Nền tảng › Đăng nhập nhanh | Panel không có nhánh `platform`; Shell vẫn truyền `saas` | — | CLAUDE §7b | trang | Thêm nhánh `platform` trong panel (L2) | [SUY RA TỪ CODE] | Trung bình | Mở → L2 |
Hồi quy L1-R: **0**.

**Tổng kết L1: 30 lỗi** (L1-01…22, L1-24, 25, 26, L1-A-P01, L1-C-01, C-02, C-03, C-P01; L1-23 không phải lỗi):
- Đóng **26**: Đợt A 5 (L1-01 ở tầng token, L1-02…05) · Đợt B 9 (L1-06, 07, 09, 10, 11, 16, 22, 24, 26) · Đợt C 12 (L1-08, 12, 13, 14, 15, 17, 18, 19, 25, C-01, C-02, C-03; L1-15 đóng ở Shell, phần panel → L1-C-P01).
- Còn mở **4**: L1-20 (px → rem, task riêng sau L2) · L1-21 (sau Đợt C, trước L2) · L1-A-P01 (L2) · L1-C-P01 (L2).

**Changelog Đợt C**
| Mã | File | Thay đổi | Trang ảnh hưởng | Đã kiểm lại |
|---|---|---|---|---|
| Bảng màu, L1-25 | Admin Shell | `Component.PAL()` 6 màu; `BRAND_TOKENS` và ô màu đọc từ PAL; ô màu nền solid | Shell + panel nhúng (qua token) | 5.2 [ĐO] |
| L1-12 (Q5) | Admin Shell | `UI_DEF/UI_OK/loadUi/saveUi`, `bs-ui` v1, "Đặt lại" xoá khoá + plane về Site | Shell | 5.1 [ĐO logic] + [CHƯA KIỂM ĐƯỢC tải lại thật] |
| L1-13, L1-15 | Admin Shell | Nhãn "Nền tảng"; `emAccType` platform → `platform` | Shell, Email Settings | 5.3 [ĐO] |
| L1-08, 18, 19 | Admin Shell | Chip `<button aria-pressed>` + `role=group`; `S.range`; hộp chọn khoảng ngày; dữ liệu cố định; cột solid, nhãn giá trị/TB, nhãn trục theo bề rộng; CSS `.rgc/.cv/.cvv` | Shell | 5.4 [ĐO] |
| L1-14 | Admin Shell | `SR` theo plane | Shell | 5.3 [ĐO] |
| L1-17 | Admin Shell | `REVIEW` một nguồn; nhãn "Chờ xử lý"; badge; "và N mục khác" | Shell | 5.5 [ĐO] |
| L1-C-01 | Admin Shell | `ringOnDark` trên sidebar/rail/cột con; topbar Đậm; `.pop` | Shell | 5.2, 5.6 [công thức] |
| L1-C-02 | Admin Shell | `scroll-padding-top` trên cột cuộn | Shell | 5.6 [ĐO] |
| L1-C-03 | Admin Shell | Icon trên chip trạng thái | Shell | [SUY RA TỪ CODE] |
| — | `_audit/harness.html` | `H.check/H.sig/H.expect`, `H.stable/H.toasts/H.storeKeys`, `H.C20/H.qC20/H.layX/H.res`, `H.chartX/H.reviewX/H.planeX/H.focusX/H.ctr` | Công cụ đo | — |
| — | CLAUDE.md §16, `DS - Prompt G2.md` | Bảng 6 màu + điều kiện thêm màu; quy tắc ngữ nghĩa; vòng focus vùng đậm; plane | Tài liệu | — |

**Cần bạn xem bằng mắt (Đợt C)**
1. Panel Tùy biến, 1440 Sáng: 6 ô màu đúng thứ tự Bảo Sơn · Xanh cổ vịt · Xanh lá · Chàm · Mận · Than chì; rê chuột thấy tên; ô đang chọn có dấu ✓ và vòng cùng màu.
2. Mỗi màu × sidebar Gradient + topbar Đậm, 1440: 6 màu nhìn khác nhau rõ (nhất là Bảo Sơn với Xanh cổ vịt và Chàm, ΔE 0,091); chữ menu, vạch vàng, chip tài khoản đọc rõ.
3. Biểu đồ ở 390: bấm 7 ngày / 30 ngày / Năm → nhãn giá trị và nhãn ngày không chồng; "Năm" có số xoay dọc; nút khoảng ngày đổi theo chip.
4. Chọn khoảng ngày tự do (bấm nút ngày) → cả 3 chip bỏ chọn; biểu đồ chỉ còn cột cao nhất + đường TB.
5. Số đếm duyệt: thẻ "Chờ xử lý 5", badge "5 chờ xử lý", 3 dòng + "và 2 mục khác".
6. Đổi vài thuộc tính Tùy biến → tải lại trang → giữ nguyên; bấm "Đặt lại" → tải lại → về mặc định. Tab bằng bàn phím trên sidebar Đậm và topbar Đậm → vòng focus trắng, không bị topbar che khi cuộn.

**Kế hoạch L1-A-P01 (chưa sửa)**
- **Bảng ánh xạ** (giá trị + vai trò → token), dùng lại phân loại 5.L1-B 1b: nền dưới chữ trắng / công tắc → `var(--brand-solid,#2050a0)` · chữ, viền, link → `var(--color-accent,#2050a0)` · nền tint `rgba(32,80,160,a)` / `#2050a0XX` → `color-mix(in srgb, var(--brand,#2050a0) a%, transparent)` · vòng focus → `var(--focus-ring-color)`, bóng trang trí → `color-mix(… var(--brand) …)` · "ghép chuỗi JS" (≈ 290 chỗ) → đọc tay, gán một trong các vai trò trên · dự phòng `var(--x,#2050a0)` → chỉ kiểm tên biến đúng vai trò.
- **Lưu trữ thay vì sửa (2c):** Cài đặt - Đề xuất giao diện (141), Language Context - Design Spec (28), Block Library - Options (11) → 180 chỗ không sửa; ghi chú "tham chiếu, màu cố định Bảo Sơn".
- **Thứ tự:** (1) panel nhúng đang dùng thật: Email Settings 70 · Trình đơn 40 · MediaPicker 38 · Hạn ngạch AI 20 · Đăng nhập nhanh 15 · Floating Contact 3 (Integrations đã đạt); (2) màn 2b theo thứ tự task mount: Page Builder v3 83 → Page Editor 105 → Quản lý Bài viết 122 → Quản lý Trang 43 → AI Writer v2 56 → Trình soạn 41 → 12 file nhỏ còn lại (≈ 210).
- **Sau mỗi file:** `H.tokens` (Sáng/Tối) + `H.layouts` 390/1024/1440 với `H.stable` so baseline; mở trong Shell với Mận và Than chì, đếm `#2050a0` còn lại trong vùng nội dung = 0; tương phản R01/R15/R17 bằng `H.measure`.
- **Ước lượng:** nhóm (1) 186 chỗ ≈ 2 lượt; nhóm (2) ≈ 660 chỗ ≈ 4–5 lượt, nên ghép vào task mount từng màn. Tổng 6–7 lượt.

---

### 5.L1-21a — Chuẩn bị an toàn, bộ hồi quy, L1-C-04, font (02/10/2026)

**Tiến độ (dừng ở điểm dừng an toàn "Bước 2 đã khôi phục"):**
- Xong: 1a · 1b · 1c · 1d (định nghĩa) · 1f (bộ hồi quy) · 1g · 1e phần font (đọc code).
- Bước 2 (L1-C-04): đã sửa 2 lần nhưng **không kiểm được** (xem dưới) → **đã khôi phục từ `.before`**, L1-C-04 còn mở. Không có `.step-c04`.
- Chưa làm: Bước 3 (đo mốc) · 1e phần hộp thoại + 3 file tham chiếu · Bước 4 · Bước 5. Harness **chưa** đóng băng (chưa tới Bước 3).
- **Lý do dừng:** từ giữa lượt, khung xem trước chạy harness ở trạng thái ẩn (`document.visibilityState = 'hidden'`) [ĐO]. Trình duyệt hãm hẹn giờ và tạm dừng ResizeObserver/sự kiện resize của trang ẩn, nên bộ RÚT GỌN từ 55 giây (34/34) chậm thành ~40 giây/phép kiểm và quá thời gian hàng loạt (4/34 sau 159 giây). Mở harness trong tab của bạn cũng không đổi được trạng thái ẩn. Kết quả trong điều kiện này không tin được, nên không tính.

**Sao lưu (chép nguyên byte bằng công cụ chép file; so kích thước + SHA-256 8 byte đầu):**
| File | Bản sao | Kích thước | Hash | Ghi chú |
|---|---|---|---|---|
| Admin Shell.dc.html | `_audit/backup/Admin Shell.dc.html.before` | 340.041 B | b15f73debb672316 | trùng nguồn |
| Admin Shell.dc.html (bản thử L1-C-04) | `_audit/backup/Admin Shell.dc.html.c04-attempt` | 340.789 B | 3e103a771dc55b15 | chỉ để tham khảo, không phải mốc |
| Khôi phục: Admin Shell.dc.html ← .before | — | 340.041 B | b15f73debb672316 | trùng `.before` [ĐO] |

#### 1a. Storage thật lúc bắt đầu L1-21a [ĐO, chỉ đọc]
- localStorage: `bs-scheme` = `"light"` (5 ký tự, hash e29d1e2f) — **khoá dự án**. `bs-ui`: **không có**.
- Khoá khác (tên · độ dài · FNV-1a): `bswebsaas:pb:v3:draft` · 1.854 · 7396aa4b — `bswebsaas:pe:handoff` · 1.130 · f9d73787 — `__audit_layout_baseline` · 8.265 · a3709bbc.
- sessionStorage: trống.
- So sau mỗi lô (`H.realCmp()`): **0 khác biệt** ở mọi lô đã chạy (q1, q2, pre, c04, lần chạy hỏng) [ĐO].

#### 1b. Kiểm kê [ĐỌC CODE]
- **7 DC con** (Email Settings, Đăng nhập nhanh & Liên kết TK, Hạn ngạch AI và mua thêm, Integrations Panel, Floating Contact Panel, Trình đơn, MediaPicker): đều nạp bằng `<dc-import>` → **cùng tài liệu** với Shell; Shell không có `<iframe>`.
- **Khoá dự án:** `bs-scheme` (đọc ở script trong `<helmet>` của Shell và cả 7 DC con, lúc phân tích tài liệu; Shell ghi trong `syncScheme()` ở `componentDidMount`/`componentDidUpdate`) · `bs-ui` (Shell đọc trong constructor; ghi/xoá trong `saveUi()` ở `componentDidUpdate`, xoá trong `resetCustomizer()`). 7 DC con chỉ đọc `bs-scheme`, không ghi lúc khởi tạo.
- **location/history:** Shell đọc `location.hash` (khôi phục mục đang mở) và ghi `location.hash` khi đổi mục; không dùng `search`, `history.*State`, `URLSearchParams`. Trình đơn: `location.href = 'Block Library - Options…'` (chỉ khi bấm "Sửa khối"). 6 DC con còn lại: không dùng.
- **Hệ quả cho đo:** mở Shell không cách ly là **ghi storage thật** (`bs-scheme` mỗi lần cập nhật; `removeItem('bs-ui')` ở lần cập nhật đầu). Các lượt trước đã mở Shell không cách ly trong harness; giá trị `bs-scheme` hiện tại vẫn là "light".

#### 1c. Cách ly [ĐO]
- **Cơ chế:** iframe `about:blank` cùng origin → `document.open()` → `history.replaceState` về đúng đường dẫn thật (+ `search` của lần chạy) → cài `localStorage`/`sessionStorage` giả (`Object.defineProperty` trên window, ghi nhật ký mọi truy cập) → `document.write(HTML lấy bằng fetch)`. Storage giả có trước script đầu tiên; đường dẫn tương đối giải theo URL thật. 7 DC con cùng tài liệu nên dùng chung bản giả. Tuỳ chọn `H.SRC`: nạp nội dung từ bản sao lưu nhưng URL vẫn là đường dẫn thật (để chạy "trước" và "sau" trong cùng phiên).
- **Kết quả 3 phép kiểm** (Shell, 1440, mọi lần mở hợp lệ): [M] `__mock = true`, đọc `bs-scheme` lần đầu lúc `readyState = loading` (trước DOMContentLoaded), `bs-ui` đọc sau DOMContentLoaded (constructor; runtime dựng component sau khi phân tích xong) và có trong nhật ký; ghi của Shell (`set:bs-scheme`, `del:bs-ui`) chỉ vào bản giả · [C] đạt (lần đầu có lúc chưa nạp xong → thêm thử lại tối đa 2 lần, cách 1 giây, đúng quy tắc 1c) · [U] `/…/serve/Admin%20Shell.dc.html` và `?screen=profile` đúng.
- DC con: không có iframe riêng nên [M] của Shell phủ cả 7. **Không DC nào cách ly thất bại.**
- Quy ước [M]: "đọc lúc khởi tạo trước DOMContentLoaded" chỉ áp cho `bs-scheme` (script helmet). `bs-ui` chỉ yêu cầu có trong nhật ký.

#### 1d. Định nghĩa chỉ số (chưa đo đủ)
Như lệnh 1d. Thêm vào `H.stable`: chờ `document.fonts.ready` rồi chờ hết toast (tối đa 6 giây). "Tải xong" = từ lúc tạo iframe đo tới khi `H.stable` trả về. Một lần đo duy nhất ở trạng thái hiển thị: **Shell tải xong 8.366 ms** (1 mẫu, chưa phải trung vị 3 lần) [ĐO]. Các chỉ số còn lại: [CHƯA KIỂM].

#### 1e. Kiểm kê font [ĐỌC CODE]
- **Shell:** Inter ×3 nguồn — Google Fonts `css2?family=Inter:wght@400;500;600;700` (thẻ link; tập con Google: cyrillic, cyrillic-ext, greek, greek-ext, latin, latin-ext, vietnamese) · `_ds/…/fonts/inter.css` (thẻ link) · `_ds/…/styles.css` → `@import "./fonts/inter.css"` (cùng URL, sheet thứ hai). `inter.css`: family `Inter`, 2 face roman + italic, weight dải 100–900, `unicode-range` U+0000-00FF, 0100-024F, 0300-036F, 1E00-1EFF, 20AB… (latin + latin-ext + vietnamese; **không có** cyrillic/greek). Remix Icon ×1: `remixicon@4.9.1`. Weight Inter Shell dùng: 400, 500, 600, 700; không dùng italic.
- **DC con cùng tài liệu** (thêm nguồn khi Shell mở panel): Integrations Panel — Google Inter 400–800 (URL khác Shell → nguồn mới) · Hạn ngạch AI, Trình đơn — Google Inter 400–700 (trùng URL Shell) + **Remix 4.2.0** (URL khác → nguồn Remix thứ 2) · MediaPicker — Google Inter 400–700 · Email, Floating, Đăng nhập nhanh — `inter.css` + `styles.css` + Remix 4.9.1.
- **29 DC** (bảng rút gọn: Google Inter · inter.css · styles.css · Remix): AI Writer Panel v2 ✓·✓·✓·4.2.0 — Admin Shell ✓·✓·✓·4.9.1 — Block Library ✓(400–800)·—·—·4.9.1 — Cài đặt - Đề xuất —·✓·✓·4.9.1 — Cài đặt nội dung AI ✓·✓·✓·4.2.0 — Cài đặt xuất bản tự động ✓·✓·✓·4.2.0 — Duyệt & xuất bản ✓·✓·✓·4.2.0 — Email Settings —·✓·✓·4.9.1 — Floating Contact —·✓·✓·4.9.1 — Hàng đợi ảnh ✓·✓·✓·4.2.0 — Hạn ngạch AI ✓·✓·✓·4.2.0 — Hộp duyệt —·✓·✓·4.2.0 — Image Picker ✓·—·—·— — Integrations ✓(400–800)·—·—·4.9.1 — Kế hoạch SEO ✓·✓·✓·4.2.0 — Language Context —·✓·✓·4.9.1 — MediaPicker ✓·—·—·4.9.1 — Nâng cấp nội dung —·✓·—·4.9.1 — Page Builder v3 ✓(Inter 400–800 + 7 họ khác cho chọn phông site)·—·—·4.9.1 — Page Editor ✓·—·—·4.9.1 — Quy trình duyệt —·✓·✓·4.2.0 — Quản lý Bài viết ✓·✓·—·4.9.1 — Quản lý Trang ✓·—·—·4.9.1 — Trình soạn nội dung —·✓·—·4.9.1 — Trình đơn ✓·✓·✓·4.2.0 — Trạng thái duyệt —·✓·✓·4.2.0 — Trợ lý viết v2 ✓·✓·✓·4.2.0 — Vận hành nội dung AI ✓·✓·✓·4.2.0 — Đăng nhập nhanh —·✓·✓·4.9.1. (Admin Palette ×2: lưu trữ, không tính.)
- **Phát hiện cần quyết trước Bước 4** (xem mục Cần quyết): không nguồn Inter nào hiện có tự phủ được hợp các nguồn — `inter.css` có 100–900 + italic nhưng thiếu cyrillic/greek; Google 400–700 có cyrillic/greek nhưng thiếu 100–300, 800–900, italic. Yêu cầu "sau ⊇ trước" với đúng một nguồn chỉ đạt nếu đổi nguồn.
- Hộp thoại/lớp phủ của Shell và 3 file tham chiếu: **để L1-21b** (được phép theo thứ tự ưu tiên).

#### 1f. Bộ hồi quy `regress-L1` (`H.suite('quick'|'full')`, chạy bằng `H.go(tên, H.suite(mức))`, đọc `H.prog(tên)`, so `H.cmp(A, B)`)
- **RÚT GỌN: 34 phép kiểm** — mở trang + [M][C][U] (1) · 4 tổ hợp hồi quy (4) · 3 bố cục × 390/1440 (6) · popover Thông báo 390 (1) · tương phản Bảo Sơn + Mận × {Đậm, Gradient + topbar Đậm, Tối} (6) · 3 plane × account-type (3) · 3 chip × 390/1440 (6) · hành vi: navGuard chuyển mục Cài đặt ở lại/rời đi (2), navGuard đổi plane ở lại/rời đi (2), MediaPicker từ chỗ chọn logo (1), tìm kiếm nhanh (1), mở thẳng `?screen=profile` (1).
- **ĐẦY ĐỦ: 159 phép kiểm** _(đính chính L1-21a-3a-2, L1-T-05: ghi cũ "157" là cộng sai; các số hạng liệt kê dưới đây cộng ra 159)_ = RÚT GỌN 34 + 24 bố cục × bề rộng Sáng + 6 Tối + 20 tổ hợp Tùy biến + 48 tương phản DOM (6 màu × 8 biến thể gồm rail/cột con 2 cột và Tối 2 cột) + 15 popover (3 popover × 320/390/600/740×360/1024) + 6 rail 1024–1279 + 3 plane × tìm kiếm/nhóm Cài đặt/account-type + số đếm duyệt + scroll-padding-top 1440/390. Bảng tương phản theo công thức 6 màu × 20 vai trò × Sáng/Tối giữ ở 5.L1-C (tĩnh, không đổi theo trang).
- **Ngưỡng:** vị trí/kích thước ±0,5px; tương phản ±0,01; số đếm, văn bản, font, hành vi trùng khớp. Mỗi phép kiểm tối đa 15 giây, quá → KHÔNG ĐO ĐƯỢC.
- Dialog chuyển mục là dialog trong ứng dụng (không dùng `window.confirm/alert`); harness vẫn thay `confirm/alert` bằng hàm giả: **0 lần gọi** [ĐO].

#### 1g. Đo nhiễu [ĐO]
- Lần 1–2: 43 khác biệt. Nguyên nhân: sau khi đổi bề rộng iframe, Shell chưa nhận `winW` mới trong 500ms (bố cục 390 vẫn là Ngang/2 cột; biểu đồ còn bề rộng cũ) và mốc thời gian trong nhật ký đọc storage. **Sửa harness 1 lần:** chờ tới khi `winW` = bề rộng iframe (tối đa 4 giây), bỏ mốc thời gian khỏi kết quả.
- Lần 3–4: 3 khác biệt — [C] lúc mở (2 lần, stylesheet do helmet gắn nạp xong sau sự kiện load) và 1 lần quá thời gian (Q-twocolumn-390). Thêm thử lại [C] theo quy tắc 1c (không tính là lần sửa của 1g).
- **Lần 5–6: 0 khác biệt, 0 KHÔNG ĐO ĐƯỢC** (34/34 mỗi lần, ~55 giây). Lần 6 → `_audit/regress-L1-pre-c04.json` (digest 8e70c42f/5702). **Không phép kiểm nào bị loại.**
- **Giới hạn:** harness không ghi được file vào dự án; file JSON chứa digest + các giá trị đọc được trên màn hình kết quả, giá trị đầy đủ chỉ ở bộ nhớ harness.

**"Hành vi hiện tại" (bản trước, đọc từ pre-c04)** [ĐO]
- navGuard khi **đổi plane** có thay đổi chưa lưu: hiện dialog; "Ở lại" → giữ Site, giữ giá trị trường ("abc"); "Rời đi" → sang TMĐT, về Tổng quan, giá trị chưa lưu bị bỏ.
- navGuard khi **chuyển mục Cài đặt**: phép thử gọi `settingsNav[].onClick` **không mở dialog** (dialog = false ở cả 2 lựa chọn) → phép thử chưa chạm đúng đường dẫn chuyển mục; ghi là [CHƯA KIỂM] hành vi thật, giữ trong bộ để so bản trước/sau. Đề xuất sửa phép thử ở L1-21b (bấm nút mục trên DOM).
- **MediaPicker từ chỗ chọn logo:** phép thử bấm nhầm nút "Nhận diện" (tab) → `pickerFor = null`, không thấy hộp chọn ảnh → [CHƯA KIỂM] hành vi thật; cùng đề xuất.
- **Tìm kiếm nhanh** "email": 4 kết quả mẫu tĩnh theo plane (Trang chủ, Máy lọc nước An Vinh Pro, Nguyễn Văn B, Thu Hà); **không có kết quả thuộc Cài đặt**.
- **Mở thẳng `?screen=profile`:** Shell bỏ qua tham số (không đọc `search`), mở Tổng quan, hash thành `#dashboard`, không mở hộp Tài khoản.

#### Bước 2 — L1-C-04 (chưa đạt, đã khôi phục)
- **Số đo trước sửa** (pre-c04) [ĐO]: chip "Năm" ở 390 và 1440 dùng **số xoay dọc** (12 nhãn, 0 nhãn vượt cột theo chiều ngang vì đã xoay). "7 ngày" ở 390 và 1440: **2 nhãn rộng hơn cột** ("4.040" 34,9 > 34px; "4.240" 34,6 > 34px). "30 ngày": nhãn cột cao nhất "5.430" 34,4px > cột 9,6px (390) / 18,6px (1440), không chồng nhãn khác. → mở **L1-C-05** (7 ngày + 30 ngày), không sửa trong lượt này.
- **Cách sửa đã thử** (chỉ chip "Năm", trong `_audit/backup/Admin Shell.dc.html.c04-attempt`): CLAUDE.md không có quy ước rút gọn số → dạng "97,5K"; không vừa thì "97K"; vẫn không vừa thì chỉ nhãn cột cao nhất + đường TB; bỏ xoay dọc cho "Năm". Ước lượng bề rộng: chữ số 7,7px, "K" 8,3px, dấu phẩy 3,6px (theo số đo "4.040" = 34,9px). Dự kiến: 1440 → "97K…132K" (cột 34px); 390 → dự phòng (cột 22,75px).
- **Lần sửa 1:** lỗi khai báo trùng tên biến (`barW`) làm Shell không chạy → 34/34 KHÔNG ĐO ĐƯỢC. **Lần sửa 2:** hết lỗi cú pháp, nhưng khung xem trước đã chuyển sang trạng thái ẩn nên bộ RÚT GỌN không chạy xong (xem Tiến độ). Không có kết quả để so → theo quy tắc: **khôi phục từ `.before`** (đã xác nhận trùng byte), **L1-C-04 còn mở**.
- Harness thêm cho Bước 2 (trước Bước 3, chưa đóng băng): `labChk` (mỗi nhãn: trong vùng biểu đồ, chồng cột khác, chồng nhãn TB, bị cắt) và `vMode`.

**Mã mới**
| Mã | Tổ hợp | Lỗi | Số đo | Mức độ | Trạng thái |
|---|---|---|---|---|---|
| L1-C-04 | Dashboard, chip "Năm", 390/1440 | Nhãn giá trị xoay dọc | 12/12 nhãn xoay | Thấp | Mở (đã thử, chưa kiểm được) |
| L1-C-05 | Dashboard, chip "7 ngày" và "30 ngày", 390/1440 | Nhãn giá trị rộng hơn cột | 7 ngày: 2 nhãn 34,6–34,9 > 34px · 30 ngày: 34,4 > 9,6 / 18,6px | Thấp | Mở, không sửa lượt này |
Hồi quy L1-R: không có (chưa sửa file sản phẩm nào còn hiệu lực).

**Changelog L1-21a**
| Mã | File | Thay đổi | Đã kiểm lại |
|---|---|---|---|
| — | `_audit/harness.html` | Cách ly `H.iso/H.mock/H.chkMCU`, `H.realSnap/H.realCmp`; runner `H.go/H.prog/H.cmp/H.hash`; bộ `H.suite`; phép kiểm `H.kLay/kCtr/kPop/kChart/kRev/kPlane/kSpt`, hành vi `H.bNav/bPlane/bPicker/bSearch/bProfile`; `H.fp` (baseline DC), `H.fontInv/faceSet/loadFaces` (font); `H.stable` chờ `fonts.ready`; `H.size` chờ `winW`; `H.SRC` | 1g [ĐO] |
| L1-C-04 | Admin Shell.dc.html | Sửa thử rồi **khôi phục** từ `.before` | trùng byte [ĐO] |
| — | `_audit/regress-L1-pre-c04.json` | Mốc RÚT GỌN (digest + giá trị đọc được) | — |

**Cần quyết (L1-21a)**
1. **Nguồn Inter duy nhất cho Bước 4.** Không nguồn hiện có nào phủ được hợp "weight × tập con". **A.** Giữ `_ds/…/inter.css` tự host (đúng design system, 100–900 + italic, latin/latin-ext/vietnamese), bỏ Google Fonts; chấp nhận mất cyrillic/greek (giao diện không dùng: VI/EN/JA/ZH) — cần bạn miễn điều kiện "⊇" cho 2 tập con này. **B.** Một link Google Fonts dải đủ (`Inter:ital,wght@0,100..900;1,100..900`), bỏ `inter.css` khỏi helmet; đạt "⊇" nhưng lệch design system (tự host) và phụ thuộc mạng ngoài; `styles.css` vẫn `@import inter.css` nên phải bỏ `styles.css` hoặc sửa file DS. **Khuyến nghị A.**
2. **Chạy lại L1-21a phần còn lại** khi khung xem trước hiển thị (không ở nền): Bước 2 (lần sửa đã có sẵn trong `.c04-attempt`) → 3 → 4 → 5.
(Cả hai đã quyết — xem 4f "Duyệt L1-21a lượt 1".)

---

### 5.L1-21a-2 — Quy tắc thường trực, sửa phép kiểm (02/10/2026)

**Tiến độ: 0 xong · A xong phần sửa code, CHƯA chạy thử · B, C, D, E chưa làm.** Dừng ở điểm dừng an toàn "trước Bước B" vì **khung xem bị ẩn** (Q6). Không file sản phẩm nào bị đổi; không khôi phục gì (không ở Bước C).

**Khung xem bị ẩn** [ĐO]: `document.visibilityState = 'hidden'` ở cả 3 lần kiểm trên khung xem của bạn (01:10–01:12 UTC, `hasFocus() = false`, kể cả sau khi mở lại harness trong tab của bạn) và ở khung xem trước của tôi (01:12:12 UTC). Theo Q6: không chạy lô nào. Harness chưa nạp lại trong khung của bạn (vẫn bản cũ: `H.kLab` chưa có), khung của tôi đã nạp bản mới (`H.kLab`, `H.navTest` = function → script phân tích không lỗi [ĐO]).

#### Bước 0
- Đã chép nguyên văn "QUY TẮC THƯỜNG TRỰC" vào 4f, mục "Quy tắc thường trực L1-21"; quyết định ghi ở 4f "Duyệt L1-21a lượt 1".
- **Mốc storage "1a-2"** [ĐO, chỉ đọc; hash = SHA-256, 16 ký tự đầu]:
  - localStorage: `bs-scheme` = `"light"` (khoá dự án) · `bs-ui`: không có · `bswebsaas:pb:v3:draft` 1.854 · b7d0db7845108a20 · `bswebsaas:pe:handoff` 1.130 · cdbfc5d68c54df1a · `__audit_layout_baseline` 8.265 · 56696db1ec94c7e3.
  - sessionStorage: trống.
  - So với mốc 1a: khoá dự án trùng; khoá khác trùng tên và độ dài. Hash không so được trực tiếp vì 1a dùng FNV-1a, 1a-2 dùng SHA-256 (đổi thuật toán, ghi lại theo Q1, không dừng).
- **Admin Shell.dc.html so với `.before`** [ĐO, byte thật qua fetch + SHA-256]: cả hai **360.410 B · a40b60473d9afd88…f7706cb** → **khớp**. `.c04-attempt`: 361.158 B · fbc9e6dbd53ebfbd. Ghi chú: bảng sao lưu lượt 1 ghi 340.041 / b15f73debb672316 — số đó tính trên chuỗi ký tự đã giải mã, không phải byte; từ lượt này mọi kích thước/hash tính trên byte.

#### Bước A — sửa phép kiểm (harness chưa đóng băng) [ĐỌC CODE; chưa chạy thử]
- **Link thẳng:** Shell không đọc `search`. `applyHash()` đọc `location.hash` (lúc mount và sự kiện `hashchange`), cú pháp `#<menu>` hoặc `#settings/<mục>` / `#p-settings/<mục>` (tiền tố `p-` chuyển plane sang Nền tảng); `syncHash()` ghi lại hash khi đổi mục. **Không có route `profile`** — hộp Tài khoản là state `acctModal`, không có trên URL. Phép kiểm cũ `Z-b-profile` thay bằng 2 phép: `Z-b-hash-profile` (`#profile`, ghi hành vi hiện tại) và `Z-b-hash-settings` (`#settings/appearance`). `H.iso` nhận `opt.hash`; [U] so cả hash lúc khởi tạo (`fr.__hash0`, đọc ngay sau `replaceState`, trước script đầu).
- **navGuard khi chuyển mục Cài đặt:** nguyên nhân phép kiểm cũ không chạm: đọc `it.k` trong khi mục của `settingsNav` có khoá `key` → chọn trúng chính mục đang mở → `trySwitchSection` trả về ngay. Sửa (lần 1/2): bấm nút DOM `button.snav[data-secitem]` khác mục hiện tại; ghi thêm `dialogDom`.
- **MediaPicker từ chỗ chọn logo:** ô ảnh chưa có ảnh là `<div onClick=f.onPick>` viền nét đứt (icon `ri-image-add-line`), không phải `<button>` → phép kiểm cũ bấm nhầm tab "Nhận diện". Sửa (lần 1/2): tìm `span` "Logo" → khối cha → phần tử chứa `ri-image-add-line` (hoặc nút "Đổi ảnh" nếu đã có ảnh).
- **12 nhãn xoay dọc ở chip "Năm" = NHÃN GIÁ TRỊ** (span `.cv.cvv` trong cột, `vCls` ở `renderVals`); nhãn trục là `xLabs`, khối riêng bên dưới, không xoay. → Không chặn Bước C.
- **Phép kiểm nhãn biểu đồ** `H.kLab` (chỉ đọc): tầng (1/2/3 suy từ DOM: số nhãn = số cột, có "K" hay không, 1 nhãn + đường TB), số nhãn có bề rộng > thanh cột (không dung sai), số nhãn xoay (`writing-mode`, `transform`, class `cvv`), tầng 3: nhãn cột cao nhất trong vùng biểu đồ, bị tổ tiên `overflow` cắt, chồng phần tử khác trong thẻ; nhãn trục ghi riêng (số, chữ, chồng nhau, tràn, xoay). Thêm `Q-lab-{7d,30d,year}-{390,1440}` vào cả 2 mức.
- **Số phép kiểm:** RÚT GỌN **41** (34 − 1 + 2 hash + 6 nhãn) · ĐẦY ĐỦ **166** (159 − 1 + 2 + 6; ghi cũ "164 (157 − 1 + 2 + 6)" sai theo L1-T-05).
- **Cách ly/an toàn thêm vào harness:** [M] ghi giá trị trả về của mỗi lượt đọc khoá dự án và so lượt đọc đầu với giá trị gieo của bản giả, đối chiếu giá trị thật (`valOk`, `realDiff`) · Q3: chặn điều hướng bằng Navigation API trong iframe (`navigate` → `preventDefault`, cho qua `hashChange`) + sau mỗi phép kiểm phát hiện tài liệu không cách ly (`__mock` mất) → ngừng lô, chụp `realCmp` · Q4: `confirm/alert/prompt` giả cài trước script đầu · Q6: nhật ký `visibilitychange` (`H.VIS`), `H.go` không chạy khi đang ẩn và đếm sự kiện ẩn trong lô · Q2: [M] hỏng ở `00-open` → các phép sau "KHÔNG CHẠY"; [C] hỏng → cả lần chạy KHÔNG ĐO ĐƯỢC · Q7: `H.canon/H.cjson/H.sha/H.summary/H.fileCheck` (SHA-256 trên JSON khoá sắp xếp, số làm tròn 0,1), `H.fileHash` (byte + SHA-256) · `H.navTest` (đích là tệp không tồn tại, không có script).
- **Chưa kiểm:** cả 3 phép kiểm sửa ở A, `H.kLab` và chặn điều hướng **chưa chạy thử** [CHƯA KIỂM — khung xem bị ẩn]. Rủi ro đã biết [SUY LUẬN]: Navigation API có thể không phát sự kiện trong tài liệu khởi tạo từ `about:blank` — nếu `H.navTest` cho thấy không chặn được thì theo Q3 không chạy phép kiểm nào bấm vào "Sửa khối" (Trình đơn); bộ hồi quy hiện không có phép kiểm nào bấm vào đó [ĐỌC CODE].

**Lượt sau (tiếp L1-21a-2, khi khung xem hiển thị):** nạp lại harness → mốc storage → chạy thử A (`H.navTest`, `Q-b-nav-*`, `Q-b-picker-logo`, `Z-b-hash-*`, `Q-lab-*`; còn 1 lần sửa cho nav/picker) → B → C → D → E.

#### Lượt 2 (02/10/2026, 03:44–03:47 UTC) — dừng vì khung xem bị ẩn
**Tiến độ: P xong · A chưa chạy thử · B, C, E chưa làm · D: phần Remix xong, phần Inter chưa làm.** Theo Phần I "Nếu khung xem bị ẩn": Q6 bắt dừng trước lô đầu → chỉ làm P.3 và Remix của D, rồi DỪNG. Không file sản phẩm nào bị đổi; không khôi phục gì.

**P.1 — Mốc storage "1a-2b"** [ĐO, chỉ đọc, SHA-256 16 ký tự đầu, đọc trong trang harness ở khung xem của bạn]:
- localStorage: `bs-scheme` = `"light"` · `bs-ui`: không có · `bswebsaas:pb:v3:draft` 1.854 · b7d0db7845108a20 · `bswebsaas:pe:handoff` 1.130 · cdbfc5d68c54df1a · `__audit_layout_baseline` 8.265 · 56696db1ec94c7e3. sessionStorage: trống.
- So với 1a-2: **trùng hoàn toàn** (khoá dự án, tên, độ dài, hash). Không có lô nào chạy nên không có lần so sau lô.
- **Admin Shell.dc.html so với `.before`** [ĐO, byte qua fetch + SHA-256, cùng cách đo với 1a-2]: cả hai **360.410 B · a40b60473d9afd8813b9e255b5aecbb98959ad8ef6768987ee6781d58f7706cb** → **khớp**, và khớp số ghi ở Bước 0. (Đọc qua công cụ script cho 340.041 · b15f73debb672316 ở cả hai — cùng con số "chuỗi đã giải mã" ghi ở Bước 0; không dùng để kết luận.)
- `_audit/harness.html` hiện tại [ĐO, fetch]: 93.942 B · c6d4ad2e2305a130…66e4da5 (chưa đóng băng).

**P.2:** harness đang mở trong khung xem của bạn (`…/_audit/harness.html`, `H` có sẵn) [ĐO].

**P.4 — visibilityState** [ĐO]: trang harness `hidden` ở 3 lần kiểm (03:44:0x, 03:44:18, 03:44:27 UTC), mỗi lần chờ `visibilitychange` 5 giây → hết giờ, không có sự kiện; `hasFocus() = false`. Lần đọc thêm lúc 03:46:43 vẫn `hidden`. Trang chứa: **không đọc được** (khác origin). → Q6: không chạy lô nào; khung xem bị ẩn suốt lượt.

**P.3 — Phụ thuộc trang đang hiển thị** [ĐỌC CODE]:
- **Shell — `requestAnimationFrame` (5 chỗ):** `fabSync` (vị trí nút nổi), `recalcHNav` (dồn mục menu Ngang vào "Khác"), 2 rAF lồng nhau `centerSecTab` (cuộn tab mục Cài đặt về giữa sau khi chuyển mục — liên quan navGuard), `_lbRaf` → `_lbSync` (**đo nhãn biểu đồ và chọn tầng**). Trang ẩn thì rAF không chạy → các bước này không xảy ra.
- **Shell — `ResizeObserver` (3):** `_chartRO` → `state.chartW` (bề rộng biểu đồ), `_barRO` → `_lbSync` (đo lại nhãn khi thanh cột đổi cỡ). RO chỉ gọi lại theo nhịp vẽ → trang ẩn thì không gọi.
- **Shell — sự kiện `resize` (5 chỗ), `setTimeout` (1).** Không dùng `visibilitychange`, `IntersectionObserver`, `document.fonts`.
- **Harness:** không dùng rAF/RO; dùng `setTimeout` (7, gồm `H.sleep`) — trình duyệt hãm hẹn giờ ở trang ẩn (≥1 giây/lần, có thể chậm hơn) → lô dễ vượt Q5; 1 chỗ phát `resize` cho iframe; 3 chỗ `visibilitychange`/`visibilityState` (Q6, `H.VIS`).
- **Hệ quả [SUY LUẬN]:** mọi phép kiểm nhãn biểu đồ (`Q-lab-*`), bố cục menu Ngang, nút nổi, cuộn tab Cài đặt và mọi số đo kích thước sau khi đổi bề rộng iframe đều phụ thuộc trang hiển thị; đo khi ẩn sẽ ra giá trị cũ của lần vẽ trước. Q6 hiện chặn đúng chỗ này.

**P.5 — Ghi file có làm khung xem rời harness không:** **không** [ĐO, 03:47:33 UTC sau lần ghi AUDIT.md: khung xem vẫn ở `_audit/harness.html`, `H` còn trong bộ nhớ; vẫn `hidden`].

**Bước D — Remix** [ĐỌC CODE + ĐO CSS; CSS 4.2.0 / 4.3.0 / 4.9.1 tải từ jsDelivr, đếm 2.850 / 2.892 / 3.229 class]:
- **Số DC: 31**, không phải 29 (đếm `*.dc.html` ở gốc dự án). 29 DC có class `ri-*`; `Admin Palette - Options` và `Image Picker` không có. 18 DC tên có dấu đọc qua bản chép tạm `_audit/tmp/dc/01–18.txt` (công cụ script không mở được đường dẫn có dấu).
- **Phiên bản đang nạp:** 4.9.1 — 15 DC (Shell, Block Library, Email Settings, Floating Contact, Integrations, Language Context, MediaPicker, Page Builder v3, Page Editor, Quản lý Bài viết, Quản lý Trang, Trình soạn nội dung, Đăng nhập nhanh, Cài đặt - Đề xuất giao diện, Nâng cấp nội dung) · 4.2.0 — 13 DC (AI Writer Panel v2, Quy trình duyệt, Trình đơn, Trạng thái duyệt, Trợ lý viết AI v2, Vận hành nội dung AI, Cài đặt nội dung AI, Cài đặt xuất bản tự động, Duyệt & xuất bản, Hàng đợi ảnh, Hạn ngạch AI, Hộp duyệt, Kế hoạch nội dung SEO) · 4.3.0 — 1 DC (Admin Palette Sample).
- **428 class `ri-*` khác nhau.** Không có trong CSS 4.9.1: **3** —
  - `ri-handshake-line` (Trình đơn, bảng gợi ý icon theo chủ đề "đối tác hợp tác") — cũng không có trong 4.2.0 đang nạp → **hiện đã hỏng**, không phải do nâng bản.
  - `ri-spell-check` (Page Editor, `AI_INTENTS` "Sửa lỗi chính tả") — không có trong 4.9.1 mà DC này đang nạp → **hiện đã hỏng**.
  - `ri-error-warning` (Floating Contact Panel, thuộc tính `icon` của `Alert` design system) — không có trong cả 3 bản; Alert có tự ánh xạ tên hay không: [CHƯA KIỂM — chưa đọc mã Alert].
- **Tên ghép động / lấy từ dữ liệu:** không có chỗ ghép chuỗi (`'ri-'+`, `` `ri-${…}` ``, `ri-{{ }}`, `+'-line'`) [ĐỌC CODE, quét regex]. Tên lấy từ dữ liệu đều là chuỗi `ri-…` đầy đủ trong mảng hằng → đã nằm trong 428 class trên. Tên đến từ dữ liệu lúc chạy (API/storage) ngoài các mảng hằng: [CHƯA KIỂM — không suy ra được].
- **Mã ký tự Remix dùng trực tiếp:** không có (`content:"\e…/\f…"`, `&#xe…;`, `\u{e,f}…`) [ĐỌC CODE].
- **So mã ký tự 4.2.0 ↔ 4.9.1 của class đang dùng:** **22 class lệch mã** (lệch 1, ví dụ `ri-robot-2-line` f3d9→f3d8, `ri-webhook-line` f520→f51f, `ri-twitter-x-line` f3e7→f3e6, `ri-shapes-line`, `ri-inbox-2-line`, `ri-information-2-line`…); 4.3.0 ↔ 4.9.1 lệch đúng 22 class đó. Danh sách đủ: archive-2-line, calendar-schedule-line, circle-fill, circle-line, collapse-diagonal-line, crosshair-2-line, expand-diagonal-line, file-check-line, file-pdf-2-line, folder-image-line, hand, inbox-2-line, information-2-line, list-check-3, list-ordered-2, robot-2-line, shapes-line, text-block, twitter-x-fill, twitter-x-line, verified-badge-line, webhook-line.
  - Vì không có mã ký tự dùng trực tiếp, đổi hẳn sang 4.9.1 không làm lệch icon [SUY LUẬN]. **Rủi ro hiện tại** [SUY LUẬN]: khi một DC 4.2.0 nhúng trong Shell (4.9.1) cùng tài liệu, hai `@font-face 'remixicon'` trùng tên — bản nạp sau thắng font, còn `content` lấy theo CSS thắng → 22 class trên có thể hiện sai icon. Thống nhất 4.9.1 sẽ xoá rủi ro này.
- **Kết luận điều kiện Remix:** đạt có điều kiện — 0 class mất thêm do nâng bản; 2 class hỏng sẵn (`ri-handshake-line`, `ri-spell-check`) cần đổi tên khi thống nhất; `ri-error-warning` chờ đọc `Alert`.

**Bước D — Inter đang thắng:** chưa làm — cần performance resource entries của trang đang hiển thị [CHƯA KIỂM — khung xem bị ẩn].

**Chưa làm lượt này:** chạy thử A (`Z-b-hash-profile`, `Z-b-hash-settings`, `Q-b-nav-*`, `Q-b-picker-logo`, `Q-lab-*`, `H.navTest`; navGuard và MediaPicker còn lần sửa 2/2) · B · C (L1-C-04/05 còn mở — không đo được) · E. Không đụng Admin Shell.

**Changelog L1-21a-2**
| Mã | File | Thay đổi | Đã kiểm lại |
|---|---|---|---|
| — | AUDIT.md | Quy tắc thường trực L1-21; Duyệt L1-21a lượt 1 | — |
| — | `_audit/harness.html` | Bước A (như trên) | Script phân tích không lỗi [ĐO]; hành vi [CHƯA KIỂM] |
| — | AUDIT.md | Duyệt L1-21a-2 lần 1; Lượt 2 (P, Remix của D) | — |
| — | `_audit/tmp/dc/01–18.txt` | Bản chép tạm 18 DC tên có dấu để đọc (không phải file sản phẩm) | — |

#### Lượt 3 (02/10/2026, 09:58–10:10 UTC) — khung xem ẩn → Bước H (chế độ đo khi ẩn)
**Tiến độ: V xong · H xong (kiểm hợp lệ ĐẠT) · A chạy thử ở [ĐO-ẨN] · U xong · D xong (Inter [SUY LUẬN], I03/I04) · B, C, E KHÔNG làm (Phần I, H.5).** Không file sản phẩm nào bị đổi; không khôi phục gì. L1-C-04/05 còn mở (C không chạy).

**Bước V** [ĐO, trang harness ở khung xem của bạn]
- 09:58:54 UTC: `visibilityState = 'hidden'`, chờ `visibilitychange` 5 giây → không có sự kiện, cuối vẫn `hidden`; `hasFocus() = false`. Người dùng báo đang nhìn khung xem; nguyên nhân [CHƯA KIỂM].
- **Mốc storage "1a-2c"** (SHA-256 16 ký tự): localStorage `bs-scheme` = `"light"` · `bs-ui`: không có · `bswebsaas:pb:v3:draft` 1.854 · b7d0db7845108a20 · `bswebsaas:pe:handoff` 1.130 · cdbfc5d68c54df1a · `__audit_layout_baseline` 8.265 · 56696db1ec94c7e3; sessionStorage trống. **Trùng hoàn toàn 1a-2b.** Đọc lại 10:07:24 UTC sau mọi lô: trùng 1a-2c.
- **Admin Shell.dc.html** = `.before`: cả hai 360.410 B · a40b60473d9afd8813b9e255b5aecbb98959ad8ef6768987ee6781d58f7706cb [ĐO]; đo lại 10:07:24 vẫn trùng.
- **Lệnh chẩn đoán V.3** (09:59:14 UTC, chỉ đọc): harness `hidden` / `hasFocus false`; iframe Shell: **chưa có** (0 iframe trong harness lúc đó → không đo được visibility/rect của iframe); `innerWidth × innerHeight` 1126 × 732; `devicePixelRatio` 1,25; **rAF thật trong 1 giây: 0**; MessageChannel: 0,3 ms; `setTimeout(0)`: 0,2 ms; `document.fonts.status = 'loaded'`. Trang ẩn vẫn có layout khi được hỏi (iframe sau đó có kích thước thật, xem H.4).

**Bước H.1 — bổ sung P.3** [ĐỌC CODE]
- **Đính chính P.3 lượt 2:** `_lbRaf → _lbSync` là đo **thanh ngôn ngữ** (Cài đặt), không phải nhãn biểu đồ. Nhãn biểu đồ tính trong `renderVals` từ `state.chartW` (chọn xoay/tầng theo biểu thức, không đo DOM); `chartW` lấy từ `_chartRO` (ResizeObserver) + một lần gọi trực tiếp khi gắn ref.
- **setTimeout/setInterval:** Shell — 1 `setTimeout` (`notify`, tắt toast sau 2.200 ms; ảnh hưởng `H.stable` vì harness chờ toast tắt), 0 `setInterval`. `support.js` — `setTimeout(tick, 50)` lặp (chờ global của `x-import component-from-global-scope`, hạn 30 s; ảnh hưởng lúc mount component DS). Harness: `H.sleep`, `H.stable` (chờ), `H.go` (hạn 15 s).
- IntersectionObserver: không có. CSS transition/animation trên phần tử biểu đồ: không có (`.cv`, `.cvv`, thanh cột dòng 351 không có transition); transition có ở `.dsw`, `.hdd/.rfly`, `.hcar`, caret menu, nút Lưu, thanh cuộn — harness đã tắt bằng `transition:none` khi nạp.
- React **18.3.1** (UMD production, `support.js`); scheduler dùng MessageChannel (không phụ thuộc rAF). Shell đọc từ entry RO: **không đọc entry** — cả `_chartRO` và `_barRO` đọc `el.clientWidth` trong callback.
- Dữ liệu 3 chip: `Component._dd` sinh từ `Date.UTC(2025,7,1)`…`Date.UTC(2026,6,17)` với công thức sin cố định → **không phụ thuộc `Date.now` hay `Math.random`**. `new Date()` duy nhất khác là giờ lưu ở Cài đặt (`stamp`).
- Cách đo lượt 1: `H.kChart` (còn nguyên trong harness) — nhãn `.cv` trong cột con của `[role="img"][aria-label^="Lượt xem"]`, so `getBoundingClientRect().width` với thanh `[title]` (dung sai 0,5); "xoay" = có class `cvv`. → đối chiếu **đủ** (không phải một phần).

**Bước H.2 — lớp thay thế** `_audit/hidden-mode.js` (file riêng, nạp lúc chạy bằng fetch + script nội tuyến; KHÔNG có thẻ script trong harness). Bật bằng `HM.enable()`: bọc `H.mock` (H.iso gọi trước `document.write` → cài trước script đầu tiên của trang), thay `H.sleep`, `H.stable`, bọc `H.kLab`/`H.kChart` (đo font trước) và `H.openShell` (thử lại [C] theo thời gian thực).
- rAF: hàng đợi; mỗi lần xả = 1 khung, chỉ chạy callback có trong hàng trước khi xả; tham số = đồng hồ giả +16,7 ms/khung; nhường luồng bằng MessageChannel; tối đa 120 khung/thao tác (vượt → ghi "rAF lặp liên tục", phép kiểm = KHÔNG ĐO ĐƯỢC).
- ResizeObserver giả: callback lần đầu ngay sau `observe()` (microtask); sau mọi bước thời gian và mỗi lần xả rAF đo lại mọi phần tử đang theo dõi, gọi callback cho phần tử đổi; lặp tới khi yên, tối đa 10 vòng. Entry: `contentRect` + `contentBoxSize` = hộp nội dung (từ `getComputedStyle` trừ padding/border theo `box-sizing`, không tính transform), kèm `borderBoxSize`, `devicePixelContentBoxSize`.
- Hẹn giờ: `setTimeout/setInterval/clear*` của iframe chạy theo đồng hồ do harness điều khiển (bước cố định 50 ms, tối đa 200 callback/bước, tối đa 400 bước/lần chờ). Harness chờ bằng MessageChannel; chờ ≥ 10 s = hạn thời gian thực (Q5), không tiến đồng hồ; việc mạng ([C], fonts.ready) chờ theo thời gian thực.
- Hoạt ảnh/font: sau mỗi thao tác `getAnimations().forEach(finish)` (vô hạn thì `cancel`); trước khi đo nhãn: cưỡng bức layout, chờ `fonts.ready`, `document.fonts.check()` đúng weight/size/family của từng nhãn `.cv`, không còn FontFace Inter `loading` — không đạt → KHÔNG ĐO ĐƯỢC.
- Ổn định DOM: chờ 3 lần nhường luồng liên tiếp không có mutation (tối đa 60). Mọi vòng lặp có giới hạn cứng. Runner `HM.go` = `H.go` bỏ chặn ẩn (ngoại lệ Q6), vẫn ghi sự kiện hiện/ẩn.
- **Hai lần sửa lớp thay thế trong lúc kiểm hợp lệ** (ghi trung thực): phiên V1 — `V-7d-390` đo bằng `H.kChart` thấy DOM cũ (nhãn xoay, 0 vượt cột) vì một lần nhường luồng chưa đủ cho React commit → thêm chờ DOM yên. Phiên V2 — [C] hỏng (stylesheet chưa nạp vì thử lại [C] chạy theo đồng hồ ảo) → 8/8 KHÔNG ĐO ĐƯỢC → thử lại [C] theo thời gian thực (2 × 2 s). V1, V2 loại khỏi kết luận.
- Ghi chú: trong phiên V1/V2 `H.realCmp` báo mọi khoá "khác" vì `H.REAL0` chưa đặt (phiên mới) — không phải storage đổi (đã đọc SHA-256 trực tiếp: trùng 1a-2c). Từ V3 `H.openShell` bọc đặt `H.REAL0`.

**Bước H.4 — kiểm hợp lệ** trên Shell = `.before`, [ĐO-ẨN], phiên **V3** và **V4** (nạp lại harness giữa 2 phiên), [M]/[C]/[U] = đạt/đạt/đạt cả hai (bs-scheme đọc đầu `loading/trướcDCL`, bs-ui `complete/sauDCL`, giá trị bản giả). Không sự kiện hiện/ẩn trong lô. `chartW` 317 (390) / 644 (1440).
| Chip × bề rộng | Lượt 1 (hiển thị) | V3 = V4 [ĐO-ẨN], cách đo lượt 1 (`H.kChart`) | `H.kLab` (không dung sai) |
|---|---|---|---|
| 7 ngày · 390 | "4.040" 34,9 > 34 · "4.240" 34,6 > 34 | 4.040 34,9 > 34 · 4.240 34,6 > 34 · 7/7 nhãn, không xoay | tầng 1, 3 vượt (thêm 3.980 34,4 > 34), 0 xoay |
| 7 ngày · 1440 | như trên | như trên | như trên |
| 30 ngày · 390 | "5.430" 34,4 > cột 9,6 | 5.430 34,4 > 9,6 · 1 nhãn + TB | tầng 3, 0 xoay |
| 30 ngày · 1440 | "5.430" 34,4 > cột 18,6 | 5.430 34,4 > 18,6 · 1 nhãn + TB | tầng 3, 0 xoay |
| Năm · 390 | 12/12 nhãn xoay | 12/12 `.cv.cvv` (nhãn **giá trị**), thanh 22,8 | 12 xoay, 0 vượt |
| Năm · 1440 | 12/12 nhãn xoay | 12/12 `.cv.cvv`, thanh 34 | 12 xoay, 0 vượt |
Nội dung chữ nhãn không phụ thuộc Date/random → so ±0,2px áp cho mọi nhãn. (a) mọi số trùng (chênh 0,0) · (b) kết luận vừa/không vừa và số nhãn xoay trùng tuyệt đối · (c) V3 = V4 (digest từng phép trùng; V4: 00-open 0560ff2d4740 · 7d-390 02149030f57c · 7d-1440 7da030f9856f · 30d-390 8f0ed778b7d5 · 30d-1440 cdcc2f1d5313 · year-390 55f941a74a52 · year-1440 995c275982e0 · center-tab 339b2631fe68). **→ chế độ ẩn hợp lệ (đối chiếu số lượt 1), đối chiếu đủ.**
- Nhãn trục (chỉ ghi): 390 — 7d "11/7…17/7" 7 nhãn · 30d "18/6, 28/6, 3/7, 8/7, 17/7" · Năm "Th8, Th10, Th12, Th2, Th4, Th7"; 1440 — 7d 7 · 30d 10 · Năm 12; 0 chồng, 0 tràn, 0 xoay. (Bảng L1-18 cũ ghi "khung 299px" và ít nhãn trục hơn ở 390 — số của đợt trước L1-21; thanh 30 ngày 9,6px khớp với biểu đồ 317px, không phải 299px.)
- **Phép kiểm có rAF — căn giữa tab Cài đặt** [ĐO-ẨN], 390: chuyển `general → domain` (tab 7/12): hàng tab cuộn `scrollLeft` 999 / `scrollWidth` 1999 / `clientWidth` 314; tâm tab lệch −32,8px so với tâm hàng; đã xả 26 khung rAF. Để đối chiếu với chế độ thường ở lượt sau (không so chéo ở lượt này).

**Bước A — chạy thử ở [ĐO-ẨN]** (phiên V4, lô A1; MediaPicker + chặn điều hướng chạy lại ở phiên mới, lô A2 sau khi sửa harness)
- `Z-b-hash-settings` (`#settings/appearance`): mở đúng Cài đặt › Thương hiệu & Giao diện, hash giữ nguyên, [M][C][U] đạt.
- `Z-b-hash-profile` (`#profile`): **hành vi hiện tại** — `activeMenu = 'profile'` (mục không tồn tại), không có `h1`, không mở hộp Tài khoản, hash giữ `#profile`. [ĐỌC CODE] `applyHash()` nhận mọi tên mục, không kiểm danh sách → mã mới **L1-C-06**.
- **navGuard khi chuyển mục Cài đặt** (sửa 1/2, đủ): ở lại → có hộp thoại trong app (`role=dialog`), vẫn ở `general`, nháp `__probe` còn; rời đi → sang `appearance`, bỏ nháp; 0 hộp thoại gốc (Q4).
- **MediaPicker từ chỗ chọn logo:** sửa 2/2 (chọn đúng span "Logo" có ô ảnh) vẫn **không chạm**: 1 span "Logo" tìm thấy nhưng khối cha không có `ri-image-add-line` hay nút "Đổi ảnh" → **KHÔNG ĐO ĐƯỢC**, giữ trong bộ. Lý do [CHƯA KIỂM]; giả thuyết [SUY LUẬN]: nhóm "Nhận diện" đang gập (`secGroup`) nên ô ảnh chưa vẽ, hoặc span đó thuộc phần khác. Lô A2 còn bị phép chặn điều hướng đưa tài liệu không cách ly vào (dưới đây) → theo Q3 cả lô = KHÔNG ĐO ĐƯỢC.
- **Chặn điều hướng (Q3) — KHÔNG chặn được** [ĐO-ẨN, chờ thời gian thực 3 s]: Navigation API có (`api = true`), đổi hash cho qua; gán `location.href` sang `_audit/__navtest_khong_ton_tai.txt` → iframe **đã sang tài liệu mới** (`__mock` mất), `navigate` không phát sự kiện (`blocked` rỗng) — đúng rủi ro đã ghi (tài liệu tạo từ `about:blank` + `document.open`). Đích không có script; storage thật so ngay: trùng 1a-2c. Bản A1 (đồng hồ ảo 1,5 s) báo `stillMock = true` vì chưa kịp điều hướng → không dùng. **Theo Q3: không chạy phép kiểm nào bấm vào chỗ code đặt `location.href`** (bộ hồi quy hiện không có phép nào như vậy [ĐỌC CODE]).
- `Q-lab-*` (6 phép, [ĐO-ẨN]): 7d tầng 1, 3 nhãn vượt cột 34px, 0 xoay · 30d tầng 3, nhãn cao nhất "5.430" trong vùng biểu đồ, 0 chồng (390: 2 tổ tiên có overflow cắt hộp nhãn theo tọa độ — `clipAnc` = DIV, DIV; 1440: 0) · Năm tầng 1, **12 nhãn giá trị xoay**, 0 vượt. Nhãn trục: 0 chồng/tràn/xoay. → 12 nhãn xoay = **nhãn giá trị** (xác nhận đo, khớp đọc code).
- Số phép kiểm không đổi: RÚT GỌN **41** · ĐẦY ĐỦ **166** (ghi cũ 164, đính chính L1-T-05) (`A-navTest` chạy riêng, không nằm trong bộ).

**Bước U — nút "Chạy kiểm RÚT GỌN"** — `_audit/user-run.js`, nạp bằng thẻ `<script src="user-run.js">` cuối `harness.html` (đã thấy nút sau khi nạp lại [ĐO]).
- **Cách dùng:** mở `_audit/harness.html` ở tab đang xem (không thu nhỏ, không chuyển tab) → bấm nút xanh góc dưới phải → chờ "Xong" (khoảng 1–2 phút; màn hình hiện Shell chạy phía sau) → chép toàn bộ khối chữ trong ô bên trên nút, dán lại cho AI. Không thao tác trên Shell trong lúc chạy.
- Mỗi lần bấm: iframe riêng nạp lại `harness.html` = phiên harness MỚI (bộ nhớ riêng), chế độ thường, không cài lớp thay thế; Shell mở qua `H.iso` (Q2) trong phiên đó; chạy `H.go('U', H.suite('quick'))` (có sẵn chặn ẩn Q6, [M] hỏng → không chạy phép Shell). Không đọc/ghi `H.REG`/`H.STAT` của phiên AI. Khoá hai chiều: nút từ chối khi `H.__aiBusy` (AI đặt trong mọi lệnh); AI kiểm `__URUN.busy` trước khi chạy. (Khi trang ẩn, hẹn giờ làm mờ nút cập nhật chậm — đã thấy nút chưa mờ sau 0,4 s; khoá trong `run()` vẫn chặn [ĐỌC CODE].) AI không bấm nút.
- Ẩn lúc bắt đầu → chỉ hiện "Khung xem bị ẩn"; có sự kiện ẩn giữa chừng → cả lần chạy = KHÔNG ĐO ĐƯỢC.
- Khối chữ gồm: thời điểm; byte + SHA-256 Shell; visibility đầu/cuối + sự kiện; [M]/[C]/[U]; tài liệu không cách ly; SHA-256 (16) của `bs-scheme`, `bs-ui` thật trước/sau (chỉ đọc); số đạt/khác/KHÔNG ĐO ĐƯỢC; digest phiên (Q7, `H.summary`); số đo nhãn 3 chip × 2 bề rộng; digest 12 ký tự từng phép.
- **Tiêu chí đạt/khác trong một phiên** (không có mốc so chéo phiên): `00-open`, `Z-b-hash-*` đạt khi [M][C][U] đạt · bố cục đạt khi `H.check` hợp lệ, không tràn trang, 0 phần tử tràn · `Q-lab-*` đạt khi 0 xoay, 0 vượt cột (tầng 3: nhãn cao nhất trong vùng, không bị cắt, không chồng) — với Shell = `.before` cả 6 phép này sẽ là "khác" (đúng lỗi L1-C-04/05) · phép hành vi (ghi hiện trạng) đạt khi đo được. So sánh với mốc thật dùng digest từng phép.

**Bước D — phần còn lại**
- **L1-I03 — đóng, không phải lỗi** [ĐỌC CODE]: `Alert` chuyển `icon` cho `Icon` của design system (SVG nội tuyến, không dùng font Remix); bảng đường vẽ có sẵn khoá `"ri-error-warning"` (`_ds_bundle.js` dòng 340).
- **L1-I04 — xác nhận nạp 2 bản, hiện chưa hiện sai icon:** [ĐỌC CODE] DC con nạp Remix khác 4.9.1 vào tài liệu Shell: `Hạn ngạch AI và mua thêm` (4.2.0, luôn mount — khung `qtWrapStyle` chỉ `display:none`) và `Trình đơn` (4.2.0, khi `showMenus`); 5 DC con còn lại (MediaPicker, Integrations, Floating Contact, Email Settings, Đăng nhập nhanh) dùng 4.9.1. [ĐO-ẨN] Trên Tổng quan: 2 stylesheet Remix theo thứ tự 4.9.1 → 4.2.0, 2 FontFace `remixicon` đều `loaded`; `content` của `ri-robot-2-line`/`ri-webhook-line`/`ri-inbox-2-line`/`ri-shapes-line` = f3d9/f520/f3a9/f3db (**mã 4.2.0 thắng**); so nét vẽ trên canvas: font `remixicon` của tài liệu trùng từng điểm ảnh với file 4.2.0, khác 4.9.1 → cả mã lẫn font đều 4.2.0, nhất quán. Class chỉ có trong 4.9.1 mà 8 DC cùng tài liệu Shell đang dùng: **0** (Shell 106 · MediaPicker 46 · Integrations 74 · Floating 50 · Email 79 · Đăng nhập nhanh 28 · Hạn ngạch 27 · Trình đơn 145 tên) → 0 icon sai nét. Rủi ro còn lại [SUY LUẬN]: đúng‑sai phụ thuộc thứ tự nạp; thêm class mới của 4.9.1 vào Shell sẽ hiện sai ngay. Giữ mở, sửa ở L1-21a-3.
- **Inter đang thắng** [SUY LUẬN, từ performance resource entries + `document.fonts` của Shell cách ly, [ĐO-ẨN]]: tải 2 link Google (`wght@400;500;600;700` của Shell và `wght@400;500;600;700;800` của DC con) + `inter.css` tự host (3 lần). File font thực tải: **3 woff2 từ fonts.gstatic.com** (biến thiên, dùng chung mọi weight) — không có entry nào cho `Inter-roman.woff2`/`Inter-italic.woff2` tự host. 67 FontFace Inter; các face `loaded` đều là face Google (unicode-range latin `U+0-FF…`, latin-ext `U+100-2BA…`, vietnamese `U+102-103…`) cho weight 400/500/600/700. Face tự host khai `src: local("Inter"), url(./Inter-roman.woff2)`, weight 100–900 — không được tải. → hiện Google Inter đang vẽ chữ; phương án A (bỏ Google) sẽ chuyển sang file tự host.

**Mã mới**
| Mã | Tổ hợp | Lỗi | Bằng chứng | Mức độ | Trạng thái |
|---|---|---|---|---|---|
| L1-C-06 | Mở Shell với hash lạ (`#profile`) | Vào mục không tồn tại: không tiêu đề, không nội dung, không về Tổng quan | [ĐO-ẨN] `activeMenu='profile'`, `h1` không có · [ĐỌC CODE] `applyHash` không kiểm danh sách mục | Trung bình | Mở |
| L1-T-01 | Harness — chặn điều hướng Q3 | Navigation API không phát `navigate` trong iframe tạo bằng `document.open` → không chặn được | [ĐO-ẨN] lô A2 | Cao (công cụ đo) | Mở — đề xuất ở mục Quyết |
| L1-I01 | Trình đơn | `ri-handshake-line` không có trong 4.2.0/4.9.1 | [ĐO CSS] | Thấp | Mở (L1-21a-3) |
| L1-I02 | Page Editor | `ri-spell-check` không có trong 4.2.0/4.9.1 | [ĐO CSS] | Thấp | Mở (L1-21a-3) |
| L1-I03 | Floating Contact Panel | `ri-error-warning` qua Alert | [ĐỌC CODE] Icon SVG có khoá này | — | **Đóng — không phải lỗi** |
| L1-I04 | Shell + Hạn ngạch AI / Trình đơn | 2 bản Remix cùng tài liệu; 4.2.0 thắng | [ĐO-ẨN] 0 icon sai nét hiện tại | Thấp | Mở (L1-21a-3) |

**Tên icon thay thế đề xuất** (có trong cả 4.2.0 và 4.9.1 [ĐO CSS]): L1-I01 → `ri-shake-hands-line` (đúng nghĩa "bắt tay hợp tác"; dự phòng `ri-team-line`) · L1-I02 → `ri-check-double-line` ("Sửa lỗi chính tả" = rà và duyệt; dự phòng `ri-text-spacing` hoặc `ri-file-check-line`). Chưa sửa.

**File** [ĐO, fetch + SHA-256, 10:07:24 UTC]: `_audit/harness.html` 94.267 B · f31af3ec4a4729587d8529516eb384e4817feab0f95cd78edc8178db8f176953 (sửa `H.bPicker` 2/2 + thẻ script nút; chưa đóng băng) · `_audit/hidden-mode.js` 15.588 B · 0c51ade00ba79b7167afe83884e37b20f598a2671f56b31baa0d69b4bf97fcdb · `_audit/user-run.js` 8.515 B · 11c71842373c05a4faaa754bb87dacae2d465c2c74eadae8979e6973a69cca51. Không tạo JSON tóm tắt mới (B không chạy).

**Changelog L1-21a-2 lần 3**
| Mã | File | Thay đổi | Đã kiểm lại |
|---|---|---|---|
| — | `_audit/hidden-mode.js` (mới) | Lớp đo khi ẩn (Bước H), 2 lần sửa trong lúc kiểm hợp lệ | V3 = V4 [ĐO-ẨN] |
| — | `_audit/user-run.js` (mới) | Nút Bước U; sửa lỗi đọc `window.parent` khác origin | Nút hiện [ĐO]; chạy thật [CHƯA KIỂM — AI không bấm] |
| — | `_audit/harness.html` | `H.bPicker` sửa 2/2; thẻ `<script src="user-run.js">` | Phân tích không lỗi [ĐO] |
| — | AUDIT.md | 4f "Duyệt L1-21a-2 lần 2"; Lượt 3 | — |

**Lần chạy nút Bước U của người dùng — CHẾ ĐỘ THƯỜNG** (02/10/2026 10:48:06 → 10:49:15 UTC; khối chữ do bạn dán lại, AI chỉ chép) [ĐO]
- Shell 360.410 B · a40b6047…f7706cb = `.before` (không phải `.step-c04` → **không dùng để đóng L1-C-04/05**, chỉ là mốc chế độ thường của bản gốc).
- visibility đầu/cuối `visible`, không sự kiện ẩn · [M]/[C]/[U] đạt · không tài liệu nạp ngoài cách ly.
- Storage thật: `bs-scheme` 99a7026172d42714 (= SHA-256 của `"light"`, khớp mốc 1a-2c) · `bs-ui` không có — trước = sau.
- 41 phép: **35 đạt · 6 khác · 0 KHÔNG ĐO ĐƯỢC**. 6 "khác" đúng là 6 phép `Q-lab-*` (theo tiêu chí ở mục Bước U: 7d vượt cột 3 nhãn; 30d tầng 3 vượt cột; Năm 12 nhãn xoay) = hiện trạng L1-C-04/05.
- Digest phiên (Q7): 6cbce70824b75067/3515. Digest từng phép: giữ nguyên khối chữ của bạn (dán ở chat 02/10/2026 10:49 UTC) — dùng làm mốc chế độ thường cho bản `.before`.
- Nhãn biểu đồ (chế độ thường): 7d 390/1440 — tầng 1, 3 vượt (3.980 34,4 · 4.040 34,9 · 4.240 34,6 > 34), 0 xoay · 30d 390 — tầng 3, "5.430" 34,4 > 9,6, trong vùng, 2 tổ tiên overflow, 0 chồng · 30d 1440 — tầng 3, 34,4 > 18,6, 0 cắt, 0 chồng · Năm 390 — 12 xoay, thanh 22,8 · Năm 1440 — 12 xoay, thanh 34. Nhãn trục 0 chồng/0 xoay.
- Hạn chế: khối chữ chỉ có digest của `Q-b-picker-logo` (đánh giá "đạt" = đo được), không có giá trị → chưa biết ở chế độ thường ô ảnh Logo có chạm được không [CHƯA KIỂM]. Không so chéo với số [ĐO-ẨN] (Q-chế độ).

**Cần quyết (L1-21a-2 lần 3)**
1. **Dùng chế độ ẩn cho B–E?** Khuyến nghị: **có cho B, C, E** (hợp lệ đủ, 2 phiên trùng digest), với điều kiện đóng L1-C-04/05 như Phần I: cần thêm một lần bạn bấm nút Bước U với hash `.step-c04`, hoặc bạn chấp nhận chỉ [ĐO-ẨN].
2. **L1-T-01 (chặn điều hướng).** A: chặn tại nguồn trong lớp cách ly — thay `location.assign/replace` và bắt `click` trên `a[href]` / submit form trước script đầu (setter `location.href` không ghi đè được nên vẫn giữ phát hiện sau phép kiểm) · B: giữ nguyên, tiếp tục cấm mọi phép bấm vào chỗ đặt `location.href`. Khuyến nghị **A**, làm trước Bước E (đóng băng).
3. **Tên icon** cho L1-I01/I02 (đề xuất trên).
4. **MediaPicker:** đã hết 2 lần sửa — cho phép lần 3 (mở nhóm "Nhận diện" trước khi tìm ô ảnh) hay giữ KHÔNG ĐO ĐƯỢC?

#### Lần 4 (02/10/2026, 13:37–14:03 UTC) — CHẾ ĐỘ THƯỜNG, chạy hết tới E
**Tiến độ: CĐ · QUYẾT ĐỊNH (4f) · K0 · T · M · L · K · B · C (ĐẠT, đóng L1-C-04/05) · cập nhật nút (tự chạy: XÁC NHẬN ĐẠT, "Xong") · E (đóng băng) — xong.** ĐỐI CHIẾU: không áp dụng (không dùng chế độ ẩn). Một file sản phẩm bị đổi: `Admin Shell.dc.html` (Bước C).

**CĐ** [ĐO, 13:37:15 UTC, khung xem của bạn]: `visibilityState = 'visible'` → cả lượt CHẾ ĐỘ THƯỜNG. Mốc **"1a-2d"**: `bs-scheme` = `"light"` · 99a7026172d42714 · `bs-ui` không có · `bswebsaas:pb:v3:draft` 1.854 · b7d0db7845108a20 · `bswebsaas:pe:handoff` 1.130 · cdbfc5d68c54df1a · `__audit_layout_baseline` 8.265 · 56696db1ec94c7e3; sessionStorage trống → **trùng 1a-2c**. Shell = `.before` (360.410 B · a40b6047…f7706cb). Đọc lại 14:02:31 UTC: trùng 1a-2d; mọi lô `H.realCmp()` = 0 khác biệt.
- **Khung xem ẩn thoáng qua** [ĐO]: lúc 13:44:4x UTC (ngay sau lần nạp lại harness đầu Bước T) `hidden`; lần kiểm lại thứ 1 (13:44:54) nhận `visibilitychange → visible` trong 5 giây. Theo Q6 ("ẩn thoáng qua rồi hiện lại") chỉ chạy lại lô: lần T đầu (bắt đầu lúc ẩn) không dùng làm kết luận, đã chạy lại T khi hiển thị. Không chuyển chế độ. Mọi lô còn lại: 0 sự kiện ẩn.

**K0** [ĐO, harness f31af3ec… chưa sửa, Shell `.before`, phiên sid-1790948296727]: 41 phép · 0 KHÔNG ĐO ĐƯỢC · [M][C][U] đạt · 0 tài liệu ngoài cách ly. → mốc dùng được. `_audit/regress-L1-k0.json` (digest phiên 5ebbfcb12719f0c4/3599) + `_audit/regress-L1-k0-full.json` (390c59ccccb312a4/10646) — đọc lại khớp bộ nhớ.

**T — L1-T-01** [ĐO, trang thử `_audit/navtest-page.html` (có iframe con `navtest-child.html`), đích vô hại `navtest-target.html` (không đụng storage, nạp được thì postMessage về harness)]
- **Nguyên nhân L1-T-01** [ĐO + SUY LUẬN]: iframe đo trước đây là `about:blank` khởi tạo → Navigation API không phát `navigate` cho tài liệu này. **Sửa:** `H.iso` nạp trước tài liệu trống THẬT `_audit/iso-blank.html` (không script) rồi mới `document.open` → Navigation API phát sự kiện bình thường.
- **Lớp chặn `H.navLayer`** (cài sau `document.open`, trước script đầu): `navigate` → `preventDefault()` khi `cancelable && !destination.sameDocument`; luôn ghi đè `window.open` (trả null); bắt click pha capture vào `a[target]` ≠ `_self`; lớp dự phòng (click/submit capture + `HTMLFormElement.prototype.submit`) chỉ bật khi không có `navigation` hoặc `H.NAVFB = true` — **lần này không cần bật** (Navigation API chặn được). Iframe con: `MutationObserver` + `load` → cài lớp cho mọi iframe con kể cả tạo sau. Cửa sổ harness: `H.navGuardOn/Off` (navigate + `window.open`), chỉ bật trong `H.go` và `H.tNav`; không chặn khi AI chủ động nạp lại (guard tắt ngoài lô; cờ `H.__allowReload`). Nhật ký riêng `H.NAVLOG` (không vào kết quả/digest). Mã phiên `H.SID` mới mỗi lần nạp: sid-mur0lnct-pr9zqx → sid-mur0p1gr-yroc8b → sid-mur14b9e-1afu08 (đã xác nhận nạp lại thật).
- **Kết quả lần 2 (khi hiển thị, 17 phép, mỗi phép một trang thử nạp mới):**
| Phép | Cửa sổ | Kết quả |
|---|---|---|
| link ngoài | iframe đo | **chặn** (navigate:push) |
| link `target=_blank` | iframe đo | **chặn** (click capture) — "không mở ngữ cảnh mới" [CHƯA KIỂM — harness không tự xác nhận được cửa sổ mới] |
| form `requestSubmit()` / `form.submit()` | iframe đo | **chặn** (navigate:push) cả hai |
| `window.open()` | iframe đo · iframe con | **chặn** (trả null) cả hai |
| `location.href=` / `assign()` / `replace()` | iframe đo · iframe con | **chặn** cả 6 (push/push/replace) |
| `parent.location=` | iframe đo → harness | trình duyệt ném **SecurityError** (không có quyền điều hướng cửa sổ cha) — ghi riêng, không tính là lớp chặn chặn nhầm; harness không bị điều hướng (mã phiên giữ nguyên) |
| `top.location=` | — | [CHƯA KIỂM]: `window.top` không phải cửa sổ harness (harness nằm trong khung xem) |
| đổi hash · `pushState` chỉ khác hash | iframe đo · iframe con | **cho phép** cả 4 |
| `history.back()/go()` | — | không thử: không DC nào dùng [ĐỌC CODE, grep 0 chỗ] |
  Mọi phép: tài liệu đo và iframe con giữ nguyên, 0 lần nạp đích, storage thật 0 khác biệt. Lần 1 (bắt đầu lúc ẩn thoáng qua) cho cùng kết quả 16/16, không dùng làm kết luận. **L1-T-01 đóng** (phạm vi: iframe đo + iframe con + cửa sổ harness; `top` và "không mở ngữ cảnh mới" chờ bạn xem trên máy).

**M — MediaPicker** [ĐỌC CODE + ĐO]
- **Nghĩa của "dat" ở nút cũ** [ĐỌC CODE `user-run.js` bản lần 3]: hàm chấm trả "dat" cho mọi kết quả dạng object không thuộc nhóm bố cục/hash/nhãn → "dat" chỉ là **đo được, không lỗi**, không nói đã chạm ô ảnh. K0 (chế độ thường, bản sửa 2/2) cho `btn: null` → bản cũ **không chạm**.
- **Nguyên nhân** [ĐO]: span lá "Logo" là `span.sc-interp` nằm trong span nhãn, nên "khối cha" là span nhãn chứ không phải khối trường. Nhóm "Nhận diện" vốn mở mặc định (`secGroup` rỗng → nhóm đầu).
- **Sửa 3/3:** mở nhóm "Nhận diện" (`secGroup.appearance`) trước khi tìm + leo tối đa 3 tầng tổ tiên để tìm ô ảnh; khôi phục `secGroup` sau phép. Thêm trường `group`, `touched`, `opened`.
- **Kết quả (chế độ thường) [ĐO]:** chạm ô `DIV "Chọn từ Thư viện ảnh hoặc tải…"` → `pickerFor = 'appearance::Logo'`, hộp "Chọn ảnh" hiện (`opened = true`) — ở K, B1, B2, C2 và lần tự chạy nút. → **hành vi hiện tại: mở được MediaPicker từ ô Logo.** Không còn KHÔNG ĐO ĐƯỢC.

**L — phép kiểm nhãn biểu đồ** (định nghĩa ghi tại đây, dùng cho Bước C mọi chế độ)
- Tầng 1/2: nhãn giá trị rộng hơn thanh cột = "vượt", **không dung sai**.
- Tầng 3: nhãn cột cao nhất ĐƯỢC rộng hơn thanh; chỉ xét (a) **cắt** = phần hộp nhãn (px, ngang và dọc) nằm ngoài hộp thẻ biểu đồ hoặc ngoài hộp tổ tiên BÊN TRONG thẻ có overflow ≠ visible — không xét tổ tiên ngoài thẻ, nên không tính phần khuất do cuộn trang; đạt khi cả hai chiều = 0 (làm tròn 0,1px); "nằm trong vùng biểu đồ" = cắt bằng 0; (b) **chồng** với nhãn giá trị khác, cột khác, đường TB và nhãn TB, nhãn trục (không tính chính cột của nhãn; Shell không có đường lưới nền); (c) **có đường TB** (không thấy = `false`, đo được).
- Chấm `H.labPass`: tầng 1/2 → 0 xoay và 0 vượt; tầng 3 → 0 xoay, cắt 0/0, chồng 0, có đường TB.
- **Bổ sung (L1-C-07, duyệt 03/10/2026):** Tầng 1/2: đường TB không bắt buộc (có hay không đều đạt), phép kiểm vẫn in cờ. Tầng 3: BẮT BUỘC có đường TB. Thêm số đo gốc `lw` = [chữ, bề rộng nhãn, bề rộng thanh] từng nhãn; tầng 3 thêm `top.{rect, lw, bw, clip, clipBy, overlap, overlapEx, avg}` (thay `inChart`/`clipAnc` cũ).
- **Bề rộng tối đa thanh cột** [ĐỌC CODE]: `max-width:34px` (dòng 351 template); thanh = `min(34, colW)`.
- Đính chính lượt 3: "2 tổ tiên overflow cắt hộp nhãn" ở 30 ngày · 390 là 2 tổ tiên **ngoài thẻ** (vùng cuộn của trang; nhãn ở y = 885,8 > chiều cao khung 844) → theo định nghĩa mới **không phải cắt** (clip 0/0).

**K** [ĐO, harness đã sửa T+M+L, Shell `.before`, sid-mur0p1gr-yroc8b]: 41 phép · 0 KHÔNG ĐO ĐƯỢC · [M][C][U] đạt. So K0 (digest + giá trị đầy đủ k0-full): chỉ lệch ở `Q-b-picker-logo` (bản sửa M) và 6 `Q-lab-*` (bản sửa L: thêm `lw`, thay trường tầng 3) — đều thuộc phép được miễn. **33 phép còn lại trùng digest** → (a) không "khác", không tác dụng phụ của T/M/L. (b) Q-lab số đo gốc so với khối chữ lần bấm nút chế độ thường (cùng Shell `.before`): 7 ngày 390/1440 — thanh 34, 7 nhãn, 3 vượt 3.980 34,4 · 4.040 34,9 · 4.240 34,6 (trùng ±0,0) · 30 ngày — tầng 3, "5.430" 34,4 so thanh 9,6 / 18,6 (trùng) · Năm — 12 nhãn xoay, thanh 22,8 / 34 (trùng) · trục 0 chồng / 0 xoay (trùng). Kết luận "trong vùng, 2 tổ tiên overflow" của nút cũ ↔ "cắt 0/0" bây giờ = **khác tiêu chí**, không phải lệch. → **không lệch**.
- Theo Bước L, ở bản `.before` 30 ngày (390, 1440) đã **đạt** tầng 3; 7 ngày và Năm không đạt.

**B — đo nhiễu** [ĐO, cùng phiên K]: B1 và B2 liên tiếp, mỗi lần 41 phép, 0 KHÔNG ĐO ĐƯỢC, `H.cmp(B1,B2)` = **0 khác biệt**, digest 41/41 trùng → **0 phép không ổn định, không loại phép nào**. Mốc pre-c04 = B2 → `_audit/regress-L1-pre-c04.json` (a8871966b6a246c4/3587, đọc lại khớp). Bản lượt 1 của file này chép sang `_audit/tmp/regress-L1-pre-c04.luot1.json`.

**C — L1-C-04 + L1-C-05** [ĐO, cùng phiên B, chế độ thường]
- **Cách sửa** (`Admin Shell.dc.html`, chỉ phần tính nhãn trong `renderVals` + hàm mới `textW` + 1 dòng trong `setChartRef`; không xuất phát từ `.c04-attempt`): bỏ nhãn xoay (`vert`, `cvv`); thanh = `min(34, colW)`; đo bề rộng chữ bằng canvas `measureText` với đúng `font-family` của biểu đồ, `600 12px` (= `.cv`); tầng chọn cho CẢ biểu đồ: (1) mọi số đầy đủ ≤ thanh − 0,25px → đầy đủ; (2) mọi số rút gọn ≤ thanh − 0,25px → rút gọn "1,2K" (`toLocaleString('vi-VN', {maximumFractionDigits:1}) + 'K'`; CLAUDE.md không có quy ước riêng); (3) còn lại hoặc > 12 cột → chỉ nhãn cột cao nhất (số đầy đủ) + đường TB. `lblSpace` cố định 22px. Cache bề rộng xoá và vẽ lại khi `document.fonts.ready` / `loadingdone`. Biên 0,25px là biên an toàn phía sản phẩm (chặt hơn quy tắc), phép kiểm vẫn không dung sai.
- **Lần sửa 1/2 không đạt**: khai trùng tên `barW` trong `renderVals` → Shell báo lỗi, lô C1 = 41/41 KHÔNG ĐO ĐƯỢC. **Lần 2/2**: đổi tên `lbBarW` → lô C2.
- **C2:** 41 phép · 0 KHÔNG ĐO ĐƯỢC · [M][C][U] đạt · 0 ẩn · storage 0 khác.
| Chip × bề rộng | Tầng | Nhãn (rộng / thanh, px) | Xoay | Kết quả |
|---|---|---|---|---|
| 7 ngày · 390 | 2 | 3,5K 26,9 · 2,7K 26,4 · 4K 16,4 · 4K 16,4 · 4,1K 23,9 · 4,2K 27,3 · 4,4K 27,9 / 34 — 0 vượt | 0 | ĐẠT |
| 7 ngày · 1440 | 2 | như trên | 0 | ĐẠT |
| 30 ngày · 390 | 3 | "5.430" 34,4 / 9,6 · hộp [147,2; 885,8; 34,4; 15,6] · cắt 0/0 · chồng 0 · có TB | 0 | ĐẠT |
| 30 ngày · 1440 | 3 | "5.430" 34,4 / 18,6 · hộp [530,1; 389,8; 34,4; 15,6] · cắt 0/0 · chồng 0 · có TB | 0 | ĐẠT |
| Năm · 390 | 3 | "131.540" 44,8 / 22,8 · hộp [299,7; 905,3; 44,8; 15,6] · cắt 0/0 · chồng 0 · có TB | 0 | ĐẠT |
| Năm · 1440 | 3 | "131.540" 44,8 / 34 · hộp [845,7; 389,8; 44,8; 15,6] · cắt 0/0 · chồng 0 · có TB | 0 | ĐẠT |
  Nhãn trục (chỉ ghi): không đổi so với pre-c04 (390: 7 · 5 · 6 nhãn; 1440: 7 · 10 · 12), 0 chồng, 0 tràn, 0 xoay.
- **pre-c04 → post-c04:** khác chỉ ở ~~10~~ **8** phép phần biểu đồ (`Q-chart-7d/year-390/1440`, `Q-lab-7d/year-390/1440`); 30 ngày không đổi; **0 khác ngoài phần biểu đồ** (~~31~~ **33** phép còn lại trùng digest) — *đính chính (L1-21a-3a, nguồn: `regress-L1-pre-c04.json` ↔ `regress-L1-post-c04.json`)*. Chiều cao thẻ biểu đồ không đổi (khối 180px cố định) → **không có phần "hệ quả"**.
- **Đạt** → giữ Shell đã sửa: **361.640 B · 8188b8c714b6959328218e240d6febcc5b08e486213c7c368d43df16610e68cc**; chép `_audit/backup/Admin Shell.dc.html.step-c04` (công cụ chép, so byte + SHA-256 = nguồn [ĐO]); `_audit/regress-L1-post-c04.json` (f0fa1ffa6caa4ee2/13531, đọc lại khớp). **Đóng L1-C-04 và L1-C-05** (phạm vi 390 và 1440, chế độ thường).

**Cập nhật nút Bước U** (`_audit/user-run.js`, bản "lần4")
- (a) Q-lab chấm theo `H.labPass` + in số đo gốc (bề rộng từng nhãn, thanh, hộp nhãn cao nhất, cắt, chồng, cờ đường TB). (b) In giá trị đầy đủ `Q-b-*`, `Z-b-*` (gồm `Q-b-picker-logo`). (c) Trong một lần bấm: bộ RÚT GỌN trên Shell hiện tại và trên `.before` (nạp qua `H.SRC`, cùng URL/base của Shell, cùng cách ly + lớp chặn, storage giả khởi tạo lại mỗi lần nạp); so từng phép theo ngưỡng 1f; phép khác ngoài phần biểu đồ → chạy lại cặp 1 lần, chỉ tính khác khi khác cả 2 lần; KHÔNG ĐO ĐƯỢC một bên → chạy lại 1 lần; [M]/[C] hỏng → thử lại tối đa 2 lần rồi dừng `[M]`/`[C]`; storage thật trước/sau. (d) Hash Shell + `.before` + `.step-c04`. (e) Dòng cuối "Xong" hoặc "DỪNG: <mã>" (`ẩn`, `[M]`, `[C]`, `storage`, `lỗi của nút`). Danh sách loại trừ Bước B: rỗng. Phần biểu đồ = `Q-chart-*`, `Q-lab-*`.
- **Tự chạy 1 lần** [ĐO, 13:59:12 → 14:01:37 UTC, `visible` suốt, chế độ thường]: [M][C][U] đạt cả hai bên · 41 đạt / 0 khác / 0 KHÔNG ĐO ĐƯỢC · Q-lab 6/6 đạt · so `.before` ↔ hiện tại: 8 khác ở phần biểu đồ, **0 ngoài biểu đồ**, 0 KHÔNG ĐO ĐƯỢC · hash Shell = `.step-c04` · storage trùng · **"XÁC NHẬN: ĐẠT" · "Xong"**. Digest từng phép trùng C2. → **nút sẵn sàng.** `Q-b-picker-logo` (in đầy đủ): chạm được, `opened = true`.

**E — HARNESS ĐÓNG BĂNG** (02/10/2026 14:02 UTC; byte + SHA-256 đo qua fetch từ máy chủ xem trước — file `.html` được máy chủ chèn thêm mã xem trước nên kích thước lớn hơn nội dung gốc [SUY LUẬN từ số đo]; đầu lượt sau so đúng cách đo này):
| File | Byte (fetch) | SHA-256 |
|---|---|---|
| `_audit/harness.html` | 104.188 | 08a7ba3d3d32de743502b8bf2b5198ef646a0cd573dc0e2592ee20309b2660e5 · **đã thay (lần 5, xem bảng mới)** |
| `_audit/hidden-mode.js` | 15.588 | 0c51ade00ba79b7167afe83884e37b20f598a2671f56b31baa0d69b4bf97fcdb · **đã thay (lần 5, xem bảng mới)** |
| `_audit/user-run.js` | 14.220 | a8c67799899367a964b9e181c8c58344c392efabf5260c873044b7e7be7cf3e1 · **đã thay (lần 5, xem bảng mới)** |
| `_audit/iso-blank.html` | 20.470 | 48946726549810684a2124ff5cad7506f0cf7c100381e87ea1081803fde534c8 · **đã thay (lần 5, xem bảng mới)** |
| `_audit/navtest-page.html` | 20.931 | a95a7c6f6ad57294e557c1c5754ae22714dd5983bf8de68abe1463bb5d419d16 · **đã thay (lần 5, xem bảng mới)** |
| `_audit/navtest-child.html` | 20.509 | cc69b41e42998faa1a9af968d414f1feb2db995da7c48bd63104319e46d4cb99 · **đã thay (lần 5, xem bảng mới)** |
| `_audit/navtest-target.html` | 20.958 | 5e645c44d9503b716b077c90d6bd891b79d97d437d988d1df47a2593d7cbf332 · **đã thay (lần 5, xem bảng mới)** |
Từ nay theo Q9: không đổi phần cách ly (Q2–Q4, Q6, gồm `H.navLayer`/`H.navGuard*`), `H.check`, `H.stable`, bộ hồi quy (41/166 phép; ghi cũ 41/164, đính chính L1-T-05). Nút và lớp thay thế chế độ ẩn: ngoài phạm vi đóng băng.

**Số phép kiểm:** RÚT GỌN **41** · ĐẦY ĐỦ **166** (ghi cũ 164, đính chính L1-T-05; không đổi; `H.tNav/H.tNavAll` chạy riêng, không nằm trong bộ).

**File JSON lượt này:** `regress-L1-k0.json` · `regress-L1-k0-full.json` · `regress-L1-pre-c04.json` (ghi đè; bản cũ ở `_audit/tmp/`) · `regress-L1-post-c04.json` — cả 4 đọc lại khớp digest bộ nhớ.

**Trạng thái mã**
| Mã | Trạng thái |
|---|---|
| L1-C-04 | **Đóng** (390, 1440; chế độ thường; nút xác nhận đạt) |
| L1-C-05 | **Đóng** (gộp L1-C-04) |
| L1-T-01 | **Đóng** (iframe đo, iframe con, cửa sổ harness); `top.location` và "không mở cửa sổ mới" chờ thử máy thật |
| L1-C-06 | Mở — xử lý ở L2 |
| L1-I01, I02, I04 | Mở — sửa ở L1-21a-3 (I01 → `ri-shake-hands-line`, I02 → `ri-check-double-line`) |
| L1-I03 | Đóng (lượt 3) |

**Changelog L1-21a-2 lần 4**
| Mã | File | Thay đổi | Trang ảnh hưởng | Đã kiểm lại |
|---|---|---|---|---|
| L1-C-04/05 | `Admin Shell.dc.html` | Quy tắc nhãn 3 tầng, đo chữ bằng canvas, bỏ nhãn xoay | Tổng quan (biểu đồ "Lượt xem nội dung") | C2 + nút tự chạy [ĐO] |
| L1-T-01 | `_audit/harness.html` + `iso-blank.html` | Tài liệu trống thật trước `document.open`; `H.navLayer`, `H.navGuardOn/Off`, `H.SID`; `H.tNav/H.tNavAll` | — | Bước T + K [ĐO] |
| — | `_audit/harness.html` | `H.bPicker` sửa 3/3; `H.kLab` tầng 3 + `lw`; `H.labPass` | — | K, B, C2 [ĐO] |
| — | `_audit/user-run.js` | Bản lần 4 (a)–(e) | — | Tự chạy: ĐẠT, Xong [ĐO] |
| — | `_audit/navtest-*.html` (mới) | Trang thử / iframe con / đích | — | Bước T |
| — | AUDIT.md | 4f "Duyệt L1-21a-2 lần 3"; mục này | — | — |

**CẦN BẠN THỬ TRÊN MÁY THẬT (cập nhật)**
1. Bấm nút "Chạy kiểm RÚT GỌN" ở `_audit/harness.html` (tuỳ chọn, để có lần xác nhận do bạn bấm): giữ khung xem hiển thị, không thao tác tới dòng "Xong"; mong đợi hash `.step-c04` = 8188b8c714b6959328218e240d6febcc5b08e486213c7c368d43df16610e68cc, 6 Q-lab ĐẠT, "khác NGOÀI biểu đồ 0", "XÁC NHẬN: ĐẠT".
2. Bước T: xác nhận không có cửa sổ/tab mới bật lên khi harness chạy phép `blank` / `open` (`H.tNavAll()`), và `top.location` (chưa kiểm được trong khung xem).
3. Nhìn biểu đồ Tổng quan ở 390 và 1440: 7 ngày hiện "3,5K…4,4K"; 30 ngày và Năm chỉ một nhãn cột cao nhất + đường "TB …".
4. Các mục còn lại từ lượt trước: tải lại / Đặt lại tùy biến, phím Tab thật.

#### Xác nhận độc lập lần 4
Khối chữ người dùng dán (lần bấm nút chế độ thường 14:10–14:12 UTC), chép nguyên khối, đúng ký tự nhận được:

Ghi chú: `&amp;gt;` = `&gt;`, `&amp;amp;` = `&amp;`, phát sinh khi dán; không ảnh hưởng hash và số đếm.
```text
== L1 RÚT GỌN · nút Bước U (bản lần 4) · chế độ THƯỜNG ==
Thời điểm: 2026-10-02T14:10:03.561Z → 2026-10-02T14:12:28.635Z
Admin Shell.dc.html (hiện tại): 361640 B · 8188b8c714b6959328218e240d6febcc5b08e486213c7c368d43df16610e68cc
.before: 360410 B · a40b60473d9afd8813b9e255b5aecbb98959ad8ef6768987ee6781d58f7706cb
.step-c04: 361640 B · 8188b8c714b6959328218e240d6febcc5b08e486213c7c368d43df16610e68cc · hash Shell = .step-c04: CÓ
visibilityState: đầu visible · cuối visible · sự kiện giữa chừng: không
[M]/[C]/[U] hiện tại: true/true/true · .before: true/true/true
Tài liệu nạp không qua cách ly: không · nhật ký chặn điều hướng: 0 mục
Storage thật (SHA-256 16) trước: bs-scheme 99a7026172d42714 · bs-ui không có · sau: bs-scheme 99a7026172d42714 · bs-ui không có (trùng)
Phép kiểm (hiện tại): 41 · đạt 41 · khác 0 · KHÔNG ĐO ĐƯỢC 0
Phép loại khỏi so sánh (Bước B): không có
Nhãn biểu đồ (hiện tại, chấm theo Bước L): 6/6 đạt
  Q-lab-7d-390: ĐẠT · tầng 2 · cột 7 · nhãn 7 · xoay 0 · vượt 0 · thanh 34px · có đường TB false · nhãn [chữ, rộng, thanh] [["3,5K",26.9,34],["2,7K",26.4,34],["4K",16.4,34],["4K",16.4,34],["4,1K",23.9,34],["4,2K",27.3,34],["4,4K",27.9,34]] · trục 7 nhãn, chồng 0, xoay 0
  Q-lab-7d-1440: ĐẠT · tầng 2 · cột 7 · nhãn 7 · xoay 0 · vượt 0 · thanh 34px · có đường TB false · nhãn [chữ, rộng, thanh] [["3,5K",26.9,34],["2,7K",26.4,34],["4K",16.4,34],["4K",16.4,34],["4,1K",23.9,34],["4,2K",27.3,34],["4,4K",27.9,34]] · trục 7 nhãn, chồng 0, xoay 0
  Q-lab-30d-390: ĐẠT · tầng 3 · cột 30 · nhãn 1 · xoay 0 · vượt 1 · thanh 9.6px · có đường TB true · nhãn [chữ, rộng, thanh] [["5.430",34.4,9.6]] · cao nhất 5.430 hộp [147.2,885.8,34.4,15.6] rộng 34.4 thanh 9.6 cắt 0/0 [] chồng 0 [] đường TB true · trục 5 nhãn, chồng 0, xoay 0
  Q-lab-30d-1440: ĐẠT · tầng 3 · cột 30 · nhãn 1 · xoay 0 · vượt 1 · thanh 18.6px · có đường TB true · nhãn [chữ, rộng, thanh] [["5.430",34.4,18.6]] · cao nhất 5.430 hộp [530.1,389.8,34.4,15.6] rộng 34.4 thanh 18.6 cắt 0/0 [] chồng 0 [] đường TB true · trục 10 nhãn, chồng 0, xoay 0
  Q-lab-year-390: ĐẠT · tầng 3 · cột 12 · nhãn 1 · xoay 0 · vượt 1 · thanh 22.8px · có đường TB true · nhãn [chữ, rộng, thanh] [["131.540",44.8,22.8]] · cao nhất 131.540 hộp [299.7,905.3,44.8,15.6] rộng 44.8 thanh 22.8 cắt 0/0 [] chồng 0 [] đường TB true · trục 6 nhãn, chồng 0, xoay 0
  Q-lab-year-1440: ĐẠT · tầng 3 · cột 12 · nhãn 1 · xoay 0 · vượt 1 · thanh 34px · có đường TB true · nhãn [chữ, rộng, thanh] [["131.540",44.8,34]] · cao nhất 131.540 hộp [845.7,389.8,44.8,15.6] rộng 44.8 thanh 34 cắt 0/0 [] chồng 0 [] đường TB true · trục 12 nhãn, chồng 0, xoay 0
Giá trị đầy đủ phép HÀNH VI (hiện tại):
  Q-b-nav-stay = {"dialog":true,"dialogDom":true,"pendingSection":"appearance","target":"appearance","from":"general","after":"general","dirtyAfter":true,"probeAfterBack":"abc","nativeDlg":0}
  Q-b-nav-leave = {"dialog":true,"dialogDom":true,"pendingSection":"appearance","target":"appearance","from":"general","after":"appearance","dirtyAfter":false,"probeAfterBack":null,"nativeDlg":0}
  Q-b-plane-stay = {"dialog":true,"planeDuring":"tenant","planeAfter":"tenant","menuAfter":"settings","probe":"abc","nativeDlg":0}
  Q-b-plane-leave = {"dialog":true,"planeDuring":"tenant","planeAfter":"shop","menuAfter":"dashboard","probe":null,"nativeDlg":0}
  Q-b-picker-logo = {"section":"appearance","group":"Nhận diện","labelFound":true,"nLogoSpans":1,"btn":"DIV:Chọn từ Thư viện ảnh hoặc tải ","touched":true,"pickerFor":"appearance::Logo","pickerVisible":true,"opened":true}
  Q-b-search = {"q":"email","results":["Trang chủ (Trang · đã xuất bản)","Máy lọc nước An Vinh Pro (Sản phẩm)","Nguyễn Văn B — cần báo giá (Lead · mới)","Thu Hà (Thành viên · Tác giả)"],"settingsHit":false}
  Z-b-hash-profile = {"want":"#profile","hash0":"#profile","hashAfter":"#profile","activeMenu":"profile","settingsSection":"general","plane":"tenant","acctModal":null,"h1":null,"M":true,"C":true,"U":true}
  Z-b-hash-settings = {"want":"#settings/appearance","hash0":"#settings/appearance","hashAfter":"#settings/appearance","activeMenu":"settings","settingsSection":"appearance","plane":"tenant","acctModal":null,"h1":"Thương hiệu &amp; Giao diện","M":true,"C":true,"U":true}
So sánh .before ↔ hiện tại (ngưỡng 1f): khác ở phần biểu đồ 8 · khác NGOÀI biểu đồ 0 · KHÔNG ĐO ĐƯỢC một bên 0 · cả hai bên 0
  Q-chart-7d-390 [biểu đồ] [["x.labOverCol",2,0],["x.labOverEx.0","\"4.040 34.9&gt;34\"","undefined"],["x.labOverEx.1","\"4.240 34.6&gt;34\"","undefined"],["x.labChk.0.t","\"3.470\"","\"3,5K\""],["x.labChk.1.t","\"2.650\"","\"2,7K\""],["x.labChk.2.t","\"3.980\"","\"4K\""],["x.labChk.3.t","\"4.040\"","\"4K\""],["x.labChk.4.t","\"4.120\"","\"4,1K\""],["x.labChk.5.t","\"4.240\"","\"4,2K\""],["x.labChk.6.t","\"4.370\"","\"4,4K\""],["
  Q-chart-7d-1440 [biểu đồ] [["x.labOverCol",2,0],["x.labOverEx.0","\"4.040 34.9&gt;34\"","undefined"],["x.labOverEx.1","\"4.240 34.6&gt;34\"","undefined"],["x.labChk.0.t","\"3.470\"","\"3,5K\""],["x.labChk.1.t","\"2.650\"","\"2,7K\""],["x.labChk.2.t","\"3.980\"","\"4K\""],["x.labChk.3.t","\"4.040\"","\"4K\""],["x.labChk.4.t","\"4.120\"","\"4,1K\""],["x.labChk.5.t","\"4.240\"","\"4,2K\""],["x.labChk.6.t","\"4.370\"","\"4,4K\""],["
  Q-chart-year-390 [biểu đồ] [["x.vLab",12,1],["x.avg","false","true"],["x.labOverCol",0,1],["x.labOverEx.0","undefined","\"131.540 44.8&gt;22.8\""],["x.vMode","\"xoay\"","\"cao-nhat+TB\""],["x.labChk.0.t","\"97.490\"","\"131.540\""],["x.labChk.1","{\"t\":\"84.520\",\"inChart\":true,\"ovBar\":0,\"ovAvg\":false,\"clip\":false}","undefined"],["x.labChk.2","{\"t\":\"89.520\",\"inChart\":true,\"ovBar\":0,\"ovAvg\":false,\"clip\":fal
  Q-chart-year-1440 [biểu đồ] [["x.vLab",12,1],["x.avg","false","true"],["x.labOverCol",0,1],["x.labOverEx.0","undefined","\"131.540 44.8&gt;34\""],["x.vMode","\"xoay\"","\"cao-nhat+TB\""],["x.labChk.0.t","\"97.490\"","\"131.540\""],["x.labChk.1","{\"t\":\"84.520\",\"inChart\":true,\"ovBar\":0,\"ovAvg\":false,\"clip\":false}","undefined"],["x.labChk.2","{\"t\":\"89.520\",\"inChart\":true,\"ovBar\":0,\"ovAvg\":false,\"clip\":false
  Q-lab-7d-390 [biểu đồ] [["x.tier",1,2],["x.over",3,0],["x.overEx.0","\"3.980 34.4&gt;34\"","undefined"],["x.overEx.1","\"4.040 34.9&gt;34\"","undefined"],["x.overEx.2","\"4.240 34.6&gt;34\"","undefined"],["x.labs","\"3.470,2.650,3.980,4.040,4.120,4.240,4.370\"","\"3,5K,2,7K,4K,4K,4,1K,4,2K,4,4K\""],["x.lw.0.0","\"3.470\"","\"3,5K\""],["x.lw.0.1",33.9,26.9],["x.lw.1.0","\"2.650\"","\"2,7K\""],["x.lw.1.1",33.9,26.4],["x.lw.2.0","\
  Q-lab-7d-1440 [biểu đồ] [["x.tier",1,2],["x.over",3,0],["x.overEx.0","\"3.980 34.4&gt;34\"","undefined"],["x.overEx.1","\"4.040 34.9&gt;34\"","undefined"],["x.overEx.2","\"4.240 34.6&gt;34\"","undefined"],["x.labs","\"3.470,2.650,3.980,4.040,4.120,4.240,4.370\"","\"3,5K,2,7K,4K,4K,4,1K,4,2K,4,4K\""],["x.lw.0.0","\"3.470\"","\"3,5K\""],["x.lw.0.1",33.9,26.9],["x.lw.1.0","\"2.650\"","\"2,7K\""],["x.lw.1.1",33.9,26.4],["x.lw.2.0","\
  Q-lab-year-390 [biểu đồ] [["x.nLab",12,1],["x.tier",1,3],["x.rot",12,0],["x.over",0,1],["x.overEx.0","undefined","\"131.540 44.8&gt;22.8\""],["x.labs","\"97.490,84.520,89.520,103.660,105.660,92.290,98.810,122.090,109.400,11","\"131.540\""],["x.avg","false","true"],["x.lw.0.0","\"97.490\"","\"131.540\""],["x.lw.0.1",15.6,44.8],["x.lw.1","[\"84.520\",15.6,22.8]","undefined"],["x.lw.2","[\"89.520\",15.6,22.8]","undefined"],["x.
  Q-lab-year-1440 [biểu đồ] [["x.nLab",12,1],["x.tier",1,3],["x.rot",12,0],["x.over",0,1],["x.overEx.0","undefined","\"131.540 44.8&gt;34\""],["x.labs","\"97.490,84.520,89.520,103.660,105.660,92.290,98.810,122.090,109.400,11","\"131.540\""],["x.avg","false","true"],["x.lw.0.0","\"97.490\"","\"131.540\""],["x.lw.0.1",15.6,44.8],["x.lw.1","[\"84.520\",15.6,34]","undefined"],["x.lw.2","[\"89.520\",15.6,34]","undefined"],["x.lw.3",
Q-b-picker-logo đánh giá riêng (không chặn xác nhận).
Digest từng phép (hiện tại, 12 ký tự) · đánh giá:
  00-open 3d3cdf927675 dat
  Q-dsl1440 2b822001a273 dat
  Q-ntt1280 807c34c2a86b dat
  Q-2cs1024 4d445628829c dat
  Q-dtt390 cfe9da543252 dat
  Q-vertical-390 cfe9da543252 dat
  Q-vertical-1440 2b822001a273 dat
  Q-horizontal-390 cfe9da543252 dat
  Q-horizontal-1440 1e788d63414a dat
  Q-twocolumn-390 cfe9da543252 dat
  Q-twocolumn-1440 65eaeff20bb6 dat
  Q-pop-notif-390 10af63ae0360 dat
  Q-cr-bs-D 77131fbb447f dat
  Q-cr-bs-GT 77131fbb447f dat
  Q-cr-bs-T 77131fbb447f dat
  Q-cr-man-D 77131fbb447f dat
  Q-cr-man-GT 77131fbb447f dat
  Q-cr-man-T 77131fbb447f dat
  Q-plane-tenant db9ce1c6fc08 dat
  Q-plane-shop 7681e7d045ab dat
  Q-plane-platform c51730e82d58 dat
  Q-chart-7d-390 7b2207253ea0 dat
  Q-chart-7d-1440 20bae07b77f1 dat
  Q-chart-30d-390 7e35e2203e4a dat
  Q-chart-30d-1440 2f1b5c772aa9 dat
  Q-chart-year-390 6282f4b52bba dat
  Q-chart-year-1440 e03bacef10bc dat
  Q-b-nav-stay 586e795ded75 dat
  Q-b-nav-leave a668114f5d70 dat
  Q-b-plane-stay 77210417d54f dat
  Q-b-plane-leave afd0d18799b6 dat
  Q-b-picker-logo 60a5c26cb0dc dat
  Q-b-search 9048c586b92e dat
  Q-lab-7d-390 966d7503e72e dat
  Q-lab-7d-1440 966d7503e72e dat
  Q-lab-30d-390 890ec68a41c6 dat
  Q-lab-30d-1440 010faeb1610f dat
  Q-lab-year-390 271b12d4815c dat
  Q-lab-year-1440 859aef735081 dat
  Z-b-hash-profile 2f532b7673f7 dat
  Z-b-hash-settings 8bfdac70db02 dat
XÁC NHẬN: ĐẠT
Xong
```

#### Lần 5 (02/10/2026, 16:29–16:31 UTC) — chỉ đọc + ghi AUDIT.md
Không sửa file sản phẩm, không sửa harness, không chạy lô nào. Storage thật: không đọc/ghi trong lượt này (không cần cho các kiểm). Mọi phép đo file chạy bằng `fetch(…, {cache:'no-store'})` trong trang `_audit/harness.html` đang mở sẵn ở khung xem của bạn (`visible`, `__URUN.busy = false`); không mở/nạp file cần đo trong khung xem.

**1. Hash Shell / .before / .step-c04** [ĐO, 16:30:06 UTC]
| File | Bản phục vụ (fetch, cách ở 3b) | Nội dung gốc (byte file) | Mã chèn |
|---|---|---|---|
| `Admin Shell.dc.html` | 361.640 · 8188b8c714b6… | **341.271 · 587879556559aed3d901c13eb5a4818a050597b8fc4db2c9472537cd5ac11396** | 20.369 B ở vị trí 29 |
| `.before` | 360.410 · a40b60473d9a… | **340.041 · b15f73debb672316c42563bc470e1fff9de42513067312a71ade9523b8f82fd7** | 20.369 B ở vị trí 29 |
| `.step-c04` | 361.640 · 8188b8c714b6… | **341.271 · 587879556559…** | 20.369 B ở vị trí 29 |
- Bản phục vụ **trùng khối chữ** (cùng cách đo). Nội dung gốc **khác số trong khối chữ chỉ do cách đo**: khối chữ đo bản phục vụ (= nội dung gốc + khối mã chèn). Shell gốc = `.step-c04` gốc (byte trùng) [ĐO].
- **Cách đo nội dung gốc:** đọc byte file từ kho dự án bằng công cụ script (`readFileBinary` → `ArrayBuffer`) + `crypto.subtle.digest('SHA-256')`. Đối chiếu độc lập: bản phục vụ bỏ đúng khối mã chèn (tìm bằng so khớp byte, 1 lần xuất hiện/file) → trùng byte với nội dung gốc ở cả 10 file đo [ĐO].
- **Khối mã chèn** [ĐO]: lấy từ `iso-blank.html` (nội dung gốc 101 B đã biết) — 20.369 B, SHA-256 865c3fd34b884f5018dba662c9fcc3ed0c52990298995494410ddc381cbe9111, gồm `<style data-omelette-injected>` + `<script data-omelette-injected>`, chèn ngay sau `<head>`. Máy chủ chèn vào mọi tệp trả về `text/html` — kể cả `.before`/`.step-c04` (không đuôi `.html` nhưng phục vụ `text/html`); `.js` không bị chèn.
- **Đính chính Bước 0 (lần 1) và P.1 (lượt 2):** "340.041 / b15f73debb672316 là chuỗi ký tự đã giải mã, không phải byte" là **sai**. Đó là byte nội dung gốc; 360.410 là bản phục vụ có mã chèn [ĐO].

**3a–3e. Bảng đóng băng** [ĐO, 16:30:06 UTC]
- **a)** 7/7 file: byte + SHA-256 bản phục vụ **trùng bảng cũ** → không file nào "lệch". Không cần bằng chứng độc lập.
- **b)** 7/7: bản phục vụ bỏ khối mã chèn trùng byte với nội dung gốc (2 file `.js` không có mã chèn: bản phục vụ = nội dung gốc). → bảng mới (nội dung gốc, cách đo ở mục 1):

| File | Byte (gốc) | SHA-256 (gốc) | Thay dòng cũ |
|---|---|---|---|
| `_audit/harness.html` | 83.819 | 23e212d898efa6bc4adf07cd7226f8d4507cb85c2975aca83c0dd8ac1988cb8a | 104.188 · 08a7ba3d… |
| `_audit/hidden-mode.js` | 15.588 | 0c51ade00ba79b7167afe83884e37b20f598a2671f56b31baa0d69b4bf97fcdb | trùng (không mã chèn) |
| `_audit/user-run.js` | 14.220 | a8c67799899367a964b9e181c8c58344c392efabf5260c873044b7e7be7cf3e1 | trùng (không mã chèn) — ngoài phạm vi đóng băng, chỉ ghi số đo |
| `_audit/iso-blank.html` | 101 | 5aed1a831e515f99b102a205578de6026823ba7d228bdd4941ea177ef2eab1a8 | 20.470 · 48946726… |
| `_audit/navtest-page.html` | 562 | b4d5c354fe8fcd135925d7a39a45a06957edd65d79be0dc0fe2a84e34604dfb6 | 20.931 · a95a7c6f… |
| `_audit/navtest-child.html` | 140 | 1af65a423f16f0ee95f60e1626b1faf5af968263bf7c1ab57c0ea518cd667086 | 20.509 · cc69b41e… |
| `_audit/navtest-target.html` | 589 | 46a39304f58772d1bb234716e0f63f1b7ae19b3293227ee8d3a19e7141e0efc2 | 20.958 · 5e645c44… |
  Từ nay Q9 so 7 file này theo bảng mới (nội dung gốc).
- **c)** File harness đang dùng mà chưa có trong bảng: **không có** [ĐỌC CODE: `harness.html` nạp `user-run.js` (thẻ script), `hidden-mode.js` (fetch), `iso-blank.html` (`H.ISO_BLANK`), `navtest-page/child/target.html` (`H.tNav`); tài liệu trống của bản sửa L1-T-01 = `iso-blank.html`, đã có]. `_audit/tmp/harness.k0.html` là bản lưu, không được nạp.
- **d)** Việc so Q9 **không** nằm trong code harness [ĐỌC CODE: không có hash bảng đóng băng trong `harness.html`/`user-run.js`; `H.fileCheck` chỉ so file JSON với `H.SUMS`] → so theo bảng trong AUDIT.md. Không áp dụng.
- **e)** Không có file nào không đọc được nội dung gốc.

**A. Đối soát số phép khác** [ĐO trên JSON]
- `regress-L1-pre-c04.json` (B2) ↔ `regress-L1-post-c04.json` (C2): khác **8** phép, không phải 10 — `Q-chart-7d-390`, `Q-chart-7d-1440`, `Q-chart-year-390`, `Q-chart-year-1440`, `Q-lab-7d-390`, `Q-lab-7d-1440`, `Q-lab-year-390`, `Q-lab-year-1440`; `diffs` trong post-c04 cũng đúng 8 khoá. → Số "10" ở Bước C lần 4 là **lỗi ghi** (chính câu đó liệt kê 8 tên). Không có 2 phép chênh; 8 = 8 của lần bấm nút. **Đính chính:** "khác chỉ ở 10 phép" → **8 phép**; "31 phép còn lại" → **33 phép**.
- k0 ↔ pre-c04 (để đối chiếu): khác 7 phép — `Q-b-picker-logo` (bản sửa M) + 6 `Q-lab-*` (bản sửa L, thêm `lw`/trường tầng 3). 4 phép `Q-chart-7d/year` **trùng** k0 ↔ pre → đổi harness (T/M/L) không đụng `Q-chart-*`; 8 phép khác pre → post là do C.
- **2 vs 3 nhãn vượt ở 7 ngày** [ĐỌC CODE]: `H.kChart` (`Q-chart-*`, cách đo lượt 1) tính "vượt" khi `rộng nhãn > rộng thanh + 0,5` → "3.980" 34,4 > 34 chỉ hơn 0,4 nên không đếm; `H.kLab` (`Q-lab-*`) không dung sai → đếm 3. **Q-lab là phép có thẩm quyền cho quy tắc nhãn**; `labOverCol` của Q-chart chỉ để so lịch sử.

**B. Cơ chế chọn tầng nhãn** [ĐỌC CODE, `Admin Shell.dc.html`]
- Chọn **lúc chạy, theo đo bề rộng chữ thật**: `textW(s)` đo bằng canvas `measureText` với `font = '600 12px ' + font-family` của khối biểu đồ (`getComputedStyle(el).fontFamily`, đọc trong `setChartRef`); không theo số ký tự (chỉ dùng `s.length × …` khi canvas lỗi). `fitAll` kiểm mọi cột ≤ `min(34, colW) − 0,25`; tầng chọn trong `renderVals` mỗi lần vẽ.
- **Font tải xong:** có — `document.fonts.ready.then(re)` + `document.fonts.addEventListener('loadingdone', re)`; `re` xoá bộ nhớ đệm bề rộng (`Component._tw = {}`) và `forceUpdate()`.
- **Đổi kích thước:** có — `ResizeObserver` trên khối biểu đồ → `state.chartW` → vẽ lại → `colW`/thanh tính lại; bộ nhớ đệm khoá theo font + chuỗi (không phụ thuộc bề rộng) nên không cần xoá.
- Lưu ý cho L1-21a-3 [SUY LUẬN]: đổi nguồn Inter giữ tên họ "Inter" → `_twFont` không đổi; bề rộng đo lại khi font mới tải xong (`loadingdone`). Tầng có thể đổi → phải chạy lại 6 Q-lab.

**C. Rút gọn số ở tầng 2** [ĐỌC CODE]
- `fmtK(n) = n < 1000 ? fmtN(n) : (n/1000).toLocaleString('vi-VN', {maximumFractionDigits: 1}) + 'K'` → tối đa 1 chữ số thập phân, **tự bỏ ",0"** (không đặt `minimumFractionDigits`); dấu thập phân phẩy theo `vi-VN`. Hậu tố **"K" cố định trong code Shell** (CLAUDE.md không có quy ước; theo quyết định 4f "Duyệt L1-21a lượt 1"). Không có bậc "M" (≥ 1.000.000 sẽ thành "1.000K").
- Hệ quả: 3.980 và 4.040 đều hiện "4K" trên cùng biểu đồ 7 ngày → mục 5.
- Giá trị đầy đủ hiện có: `title` của thanh cột ("<ngày>: 4.040 lượt") — chỉ hiện khi rê chuột, không có trên cảm ứng/bàn phím (P14.f); `aria-label` của biểu đồ chỉ nêu cao nhất + trung bình.

**D. Điều hướng**
- **(1)** Nút RÚT GỌN **không** chạy phép chặn điều hướng [ĐỌC CODE `user-run.js`: chỉ `H.suite('quick')`, không gọi `H.tNav`/`H.tNavAll`/`H.navTest`]. → **L1-T-01 đóng dựa trên đo lần 4 của AI + đọc code, chưa có xác nhận độc lập.**
- **(2)** Quét 29 DC đang dùng + file `.js`/`.css` dùng chung (`admin-base.css`, `ai-quota.js`, `platform-config.js`, `block-library.js`, `content-seo.js`, `content-ui.js`, `support.js`…) [ĐỌC CODE, regex `top.location`, `parent.location`, `window.top`, `window.parent`, `parent.`, `frameElement`, `frames[`, `_top`/`_parent` (a/form/base/`window.open`), `<base`, `http-equiv`]: **0 lệnh điều hướng ra khung ngoài/tài liệu mới ngoài đường đã kiểm**. Chỉ có `window.parent.postMessage` trong `support.js` (không tính) và biến tên `parent` (không liên quan). Chỗ điều hướng còn lại đều là `window.location.href =` trên chính cửa sổ (đường đã kiểm, bị chặn ở Bước T): Trình đơn (`openBlocks`, chỉ khi không nhúng), Page Editor (`openBuilder`, `openBuilderAdd`), Quy trình duyệt (`onPending`, `onOpenInbox`). → **`top.location`: không áp dụng. Không mở L1-T-02.**

**E. Định nghĩa Bước L** [ĐỌC AUDIT.md, trích nguyên văn mục "L — phép kiểm nhãn biểu đồ" lần 4]
- (b) **ghi rõ:** "Tầng 3: nhãn cột cao nhất ĐƯỢC rộng hơn thanh" → "vượt 1" ở tầng 3 vẫn ĐẠT.
- (a) **không ghi rõ:** chỉ có "Chấm `H.labPass`: tầng 1/2 → 0 xoay và 0 vượt; tầng 3 → 0 xoay, cắt 0/0, chồng 0, có đường TB." — đường TB không nằm trong tiêu chí tầng 1/2 nhưng không có câu nói tầng 1/2 không yêu cầu đường TB (quy tắc ở 4f "Duyệt L1-21a lượt 1" cũng chỉ gắn đường TB với tầng 3). Code chấm khớp cách hiểu này [ĐỌC CODE `H.labPass`: tầng 1/2 chỉ xét `over === 0`]. → mục 5; mã **L1-C-07**.

**F. Tài liệu trống của bản sửa L1-T-01**
- File: `_audit/iso-blank.html` (101 B, không có script) — nạp qua **máy chủ xem trước** (`H.iso`: `fr.src = 'iso-blank.html?' + Date.now()`), nên **có mã chèn** 20.369 B [ĐO].
- Nội dung mã chèn [ĐO, quét văn bản]: không có `reload`, `EventSource`, `WebSocket`, `setInterval`, `document.write`, `beforeunload`, `top.` → **không có cơ chế tự tải lại khi file đổi**. Có: 5 `setTimeout` (một vòng lặp 100 ms × tối đa 30 lần), 1 `MutationObserver` trên `documentElement`, 11 `addEventListener` (load, error, DOMContentLoaded, visibilitychange, message `__om_eval`…), bộ nhớ "omelette.storage" (`data/storage.json`, lưu qua `window.parent.postMessage` tới máy chủ), một bộ bắt click vào link khác origin có target `_self/_top/_parent` → `preventDefault` + `window.open(_blank, noopener)`, và style `html,body{background:transparent}`.
- **Sau `document.open`** [SUY LUẬN theo chuẩn HTML hiện hành]: listener trên document và window của tài liệu trống bị xoá; hẹn giờ đang chờ vẫn chạy trên cùng window; MutationObserver còn gắn với nút cũ đã tách.
- **Rủi ro chính — mã chèn nằm trong chính tài liệu đo** [ĐỌC CODE `H.iso`]: Shell được lấy bằng `fetch` qua máy chủ xem trước rồi `document.write` → khối mã chèn (của Shell) cũng chạy trong tài liệu đo, sau lớp cách ly. Lớp chặn vẫn che được click-link (`window.open` đã bị thay trước khi mã chèn chạy) [SUY LUẬN]. Nhưng khối này đổi theo phiên bản app → digest phép kiểm và số đo bản phục vụ có thể đổi dù file không đổi. → mã **L1-T-03**.

**G. Lần sửa Shell bị lỗi** [ĐỌC AUDIT.md + ĐO danh sách file]
- Bước C: lần sửa 1/2 lỗi (khai trùng `barW`), lần 2/2 đạt → **2 lần, trong giới hạn tối đa 2**.
- Lô C1 (41/41 KHÔNG ĐO ĐƯỢC) **không lưu file nào**: `_audit/` chỉ có `regress-L1-{k0,k0-full,pre-c04,post-c04}.json` + `tmp/regress-L1-pre-c04.luot1.json`; `post-c04.json` ghi `run: "C2"`, `ref: "B2"`; nút so `.before` trực tiếp, không đọc JSON [ĐỌC CODE]. → C1 không bị dùng làm mốc.

**Mã mới / trạng thái**
| Mã | Tổ hợp | Lỗi | Bằng chứng | Mức độ | Trạng thái |
|---|---|---|---|---|---|
| L1-T-03 | Harness — mọi phép trên Shell | Khối mã chèn của máy chủ xem trước (20.369 B, đổi theo phiên bản app) nằm trong `iso-blank` và trong tài liệu đo (qua `document.write`) → digest/số đo bản phục vụ có thể đổi dù file không đổi | [ĐO] khối chèn ở 8/8 file `text/html` · [ĐỌC CODE] `H.iso` | Trung bình (công cụ đo) | Mở — L1-21a-3 |
| L1-C-07 | Định nghĩa Bước L | Chưa ghi rõ tầng 1/2 không yêu cầu đường TB | [ĐỌC AUDIT.md] mục E | Thấp | **Đóng** (quyết định 03/10/2026, 4f "Duyệt lượt chốt L1-21a-2") |
| L1-T-01 | — | — | — | — | **Đóng phần đã chặn** (đo lần 4 của AI + đọc code, chưa có xác nhận độc lập); phát hiện F mang mã L1-T-03 |
| L1-T-02 | — | Không mở (D(2) không có phát hiện) | — | — | — |
| L1-C-04, L1-C-05 | — | — | — | — | Đóng (giữ) |

**Trạng thái: L1-21a-2 xong, còn mục mở: L1-T-03, L1-C-07** (và mục 5: "4K" trùng nhãn).

**Việc cho L1-21a-3**
1. Bỏ font nạp trùng + chuyển Inter sang `inter.css` (đổi nguồn font thật).
2. Thống nhất Remix 4.9.1.
3. Sửa L1-I01 (`ri-shake-hands-line`), L1-I02 (`ri-check-double-line`), L1-I04.
4. Đo mốc before + baseline 29 DC + hiệu năng TRƯỚC rồi SAU trong cùng một phiên.
5. Chạy lại 6 Q-lab sau khi đổi font (tầng có thể đổi).
6. L1-T-03, L1-C-07.
7. Mục người dùng chưa thử tay: tải lại / Đặt lại tùy biến · phím Tab thật · nhìn biểu đồ Tổng quan ở 390 và 1440.
   - Bỏ mục "xem có cửa sổ/tab mới bật lên khi chặn điều hướng": D(1) — nút không chạy phép này, nên không có cách chạy đã thống nhất cho người dùng.

**Changelog L1-21a-2 lần 5**
| Mã | File | Thay đổi | Đã kiểm lại |
|---|---|---|---|
| — | AUDIT.md | 4f "Duyệt L1-21a-2 lần 4"; Xác nhận độc lập lần 4; bảng đóng băng mới (nội dung gốc), đánh dấu 7 dòng bảng cũ; đính chính "10 → 8 phép" và "chuỗi đã giải mã"; L1-T-03, L1-C-07 | — |


---

### 5.L1-21a-3a — Chuẩn bị và đo thử cho việc bỏ font nạp trùng (03/10/2026)

**Danh sách đóng băng — file đo mới của lượt này** (hash gốc, ghi TRƯỚC khi dùng để đo)
| File | Byte (gốc) | SHA-256 (gốc) | Dùng cho |
|---|---|---|---|
| `_audit/inj-probe.js` | 7.895 | ef51e97c8f28f4df198d11b68a6995c91a2df09c53cb076245da744a39849dce | Bước I (dấu chân, cắt khối chèn, ngưỡng (2)) |
| `_audit/inter-metric.html` | 5.030 | 7b48b55f78cc4b359771a79b6dee70e02ff2a6961e02e8cbb488f6a6c30b95c8 | Bước II(c) (trang thử metric, nạp qua iframe đo) |
| `_audit/m3a-core.js` | 3.047 | dfe3300b71c0bcd394b119ae9fff774c2b341a7aceafd30778031dafb365d11b | Bước III — chung: chờ ổn định, lọc chỉ sản phẩm, đếm nguồn font |
| `_audit/m3a-perf.js` | 1.669 | a75762ba786d81cf7667b2c938975d1edd81f0265c0b0291fd4b44adfef9123a | Bước III — perf |
| `_audit/m3a-baseline.js` | 1.539 | e5ec18dc3e1002460eca133d3f75469aec3f8fad76829402bc288eb2979e6e08 | Bước III — baseline |
| `_audit/m3a-font.js` | 2.734 | e61a9ab98b09564d95041a6dad36a100bc3c397dce5f91058b5adf56ae5f9ec8 | Bước III — font-check |
| `_audit/m3a-run.js` | 2.838 | a16e3ffbbe6c524bf74ee81dcc49a9945e6fc28f7d77b4f65e3c52c8d3d640e9 | Bước III/IV — bộ chạy 29 DC |
| `_audit/user-run.js` (nút, ngoài phạm vi đóng băng) | trước 14.220 · a8c67799899367a964b9e181c8c58344c392efabf5260c873044b7e7be7cf3e1 → sau 17.806 · 65999b24216f3f723f60580da56fcfc038f01eb7e9dc6df44d1e4426b9188830 | — | Bước V |

**Tiến độ:** 0 xong · I xong (không chạm ngưỡng nâng cấp) · II xong ([ĐO-ẨN], kết luận "khác — đổi giao diện") · III xong (5 script, đã đóng băng) · V xong (nút sẵn sàng) · IV(a–b) chạy 2 lần nhưng **số đo không dùng được** (lỗi script bản 1, xem IV) · IV(c) chỉ có bảng nguồn font · IV(d) không làm · bảng đối chiếu hash QĐ 1: xong (mục 0b/0c). Không sửa file sản phẩm, không sửa harness.

#### Bước 0
- **e) Chế độ đo** [ĐO, 17:42:29 UTC]: `visibilityState = 'hidden'`, chờ 5 giây không có sự kiện, `hasFocus() = false` → **CHẾ ĐỘ ẨN cả lượt** (nhãn [ĐO-ẨN]; lớp `hidden-mode.js` bật cho mọi lô của AI). Sau đó khung xem đổi qua lại hidden/visible; theo quy định giữ chế độ ẩn cho phép đo của AI. Riêng nút Bước V tự quyết theo lúc bấm: lúc tự chạy là `visible` suốt → chạy chế độ thường.
- **a) Storage thật, mốc "1a-3a"** [ĐO, chỉ đọc, SHA-256 16]: `bs-scheme` 5 · 99a7026172d42714 · `bs-ui` không có · `bswebsaas:pb:v3:draft` 1.854 · b7d0db7845108a20 · `bswebsaas:pe:handoff` 1.130 · cdbfc5d68c54df1a · `__audit_layout_baseline` 8.265 · 56696db1ec94c7e3; sessionStorage trống → trùng 1a-2d. **Cuối lượt** (18:00:37 UTC): trùng hoàn toàn.
- **b) Bảng đóng băng** [ĐO, hash gốc bằng công cụ file]: 6/6 file đóng băng trùng bảng mới (harness 23e212d898ef · hidden-mode 0c51ade00ba7 · iso-blank 5aed1a831e51 · navtest-page b4d5c354fe8f · navtest-child 1af65a423f16 · navtest-target 46a39304f587); `user-run.js` đầu lượt 14.220 · a8c677998993 (chỉ ghi).
- **c) File sản phẩm** [ĐO]: Shell gốc 341.271 · 587879556559 = `.step-c04` gốc → đạt. Không file sản phẩm nào khác có `.before` tạo ở L1-21a-2 (thư mục `_audit/backup/` chỉ có 3 file của Shell). Bảng hash gốc mọi file sản phẩm (mốc cho 3b) ở cuối mục này. 18 DC tên có dấu: công cụ file không mở được đường dẫn có dấu → đọc byte qua bản chép `_audit/tmp/h/NN.bin` (công cụ chép); đối chiếu độc lập với bản phục vụ đã cắt khối chèn: [CHƯA KIỂM] cho 18 file này (đã kiểm cho mọi file tên không dấu).
- **Bảng đối chiếu gốc/phục vụ (QĐ 1)** [ĐO, 17:43 UTC, cắt theo chuỗi đánh dấu ở Bước I b]:
| File | Phục vụ | Gốc (công cụ file) | Gốc (phục vụ − khối chèn) |
|---|---|---|---|
| Admin Shell.dc.html | 361.640 · 8188b8c714b6 | 341.271 · 587879556559 | trùng |
| .before | 360.410 · a40b60473d9a | 340.041 · b15f73debb67 | trùng |
| .step-c04 | 361.640 · 8188b8c714b6 | 341.271 · 587879556559 | trùng |
| .c04-attempt (Q8) | chưa đo phục vụ | 340.789 · 3e103a771dc5 | — |
| harness.html | 104.188 · 08a7ba3d3d32 | 83.819 · 23e212d898ef | trùng |
| hidden-mode.js | 15.588 · 0c51ade00ba7 (không chèn) | 15.588 · 0c51ade00ba7 | — |
| iso-blank.html | 20.470 · 489467265498 | 101 · 5aed1a831e51 | trùng |
| navtest-page / child / target | 20.931 · a95a7c6f6ad5 / 20.509 · cc69b41e4299 / 20.958 · 5e645c44d950 | 562 · b4d5c354fe8f / 140 · 1af65a423f16 / 589 · 46a39304f587 | trùng |
  Mọi kích thước/hash khác ghi trước lượt này trong AUDIT.md: **chưa rõ gốc/phục vụ (chưa đo lại)**, trừ các số đã gắn nhãn ở lần 5.
- **d) Đối soát số liệu:** 33 trùng + 8 khác = 41 ✓ (JSON pre/post-c04, đã sửa văn bản ở Bước C lần 4: "10 → 8", "31 → 33", ghi đính chính kèm nguồn). Nhãn vượt 7 ngày: Q-lab 3 · Q-chart 2 ✓ (`regress-L1-k0-full.json`: `over 3`, `labOverCol 2`). Phép loại ở Bước B: rỗng ✓ (`regress-L1-pre-c04.json` `extra.excl = []`). RÚT GỌN 41 ✓ (`H.suite('quick').length`). **ĐẦY ĐỦ: đính chính 164 → 166** [ĐO `H.suite('full').length` trên harness đóng băng 23e212d898ef; 166 khoá khác nhau, 125 phép chỉ có ở bộ đầy đủ; không có JSON nào chứa bộ đầy đủ — `regress-L1-k0-full.json` là **giá trị đầy đủ của 41 phép RÚT GỌN**, không phải bộ ĐẦY ĐỦ]. Nguyên nhân chênh 2 [CHƯA KIỂM]. "35 đạt / 6 khác" trên `.before`: **không đối soát được** — nguồn là khối chữ lượt 3 dán trong chat, không lưu trong AUDIT.md hay JSON.

#### Bước I — Mã chèn (L1-T-03) [ĐO-ẨN trừ khi ghi khác]
- **a) Dấu chân** (`iso-blank.html` nạp qua `H.iso`, đo sau load + 2 giây theo đồng hồ của lớp ẩn; 2 lần nạp): 8 phần tử = 5 gốc (html, head, meta, title, body) + 1 `<style>` của harness (transition:none, thêm lúc load) + **2 của mã chèn** (`STYLE[data-omelette-injected]`, `SCRIPT[data-omelette-injected]`) · stylesheet của mã chèn 1 (1 quy tắc) · @font-face 0 · FontFace 0 · `<html>` không có style nội tuyến · toàn cục `omelette`, `claude` có. **Lần 1 = lần 2** → (3) không chạm. Phần của lần chạy đầu (trong iso-blank trước `document.open`) không còn phần tử nào.
- **b) Hash mã chèn**: chuỗi mở `"\n<style data-omelette-injected>"`, chuỗi đóng `"u({ok:0,e:String(s)})}})}})();\n</script>\n"`; mỗi chuỗi xuất hiện đúng 1 lần ở 12/12 file `text/html` đã đo (iso-blank, Shell, .before, .step-c04, harness, 3 navtest, Email Settings, MediaPicker, Integrations, Floating Contact); cắt xong trùng từng byte với gốc. **Hash mã chèn = 865c3fd34b884f5018dba662c9fcc3ed0c52990298995494410ddc381cbe9111 (20.369 B)**, Shell trùng iso-blank. Mốc: đầu lượt 17:43 · trước IV 17:56 · nút 17:53 và 17:56 → trùng ở mọi mốc.
- **c) Đọc khối chèn** [ĐỌC CODE]:
| Loại | Nội dung | Ảnh hưởng sản phẩm |
|---|---|---|
| Phần tử DOM | 1 style + 1 script; chỉ khi `?_omthumb=1`: MutationObserver gỡ `autofocus`, đặt `inert` | Không (không có tham số này) |
| CSS | `html, body { background: transparent }` | Đo: 0/3.038 phần tử Shell đổi computed (589 thuộc tính) khi tắt sheet — sản phẩm tự đặt nền sau |
| JS ghi lên phần tử sản phẩm | `?theme=` → `data-theme` (không có tham số); lúc `load`: nếu không có `meta[color-scheme]`, `color-scheme` computed = normal, nền `body`/`html` đục → ghi `html.style.color-scheme = light/dark`; lúc `DOMContentLoaded`: gắn `data-plugins`/`data-filename` cho `script[type=text/babel]` (sản phẩm không có) | Đo Shell lúc load + 2 giây: `html` **không** mang `color-scheme` nội tuyến (lúc `load` nền `body` còn trong suốt vì stylesheet helmet nạp sau load) → không tính. [SUY LUẬN] phụ thuộc thời điểm: nếu stylesheet nạp xong trước `load`, mã chèn sẽ ghi `color-scheme: light` ở chế độ Sáng (ở Tối thì `admin-base.css` đã đặt `dark` → mã chèn bỏ qua) |
| Font | không có @font-face / FontFace / Inter / Remix | (1) không chạm |
| Bộ nghe | window: error, unhandledrejection, load, click (link khác origin, target `_self/_top/_parent` → `preventDefault` + `window.open(_blank)`), message (chỉ từ origin máy chủ và `source = window.parent`); document: DOMContentLoaded, visibilitychange; `console.log/warn/error` bọc để gửi về cửa sổ cha | Click cần thao tác; `window.open` của tài liệu đo đã bị lớp chặn thay trước khi mã chèn chạy → không mở được |
| Hẹn giờ/observer | ResizeObserver trên `<html>` (gửi chiều cao về cha), `setTimeout` vòng 100 ms × ≤30 (chỉ khi có script babel), hẹn giờ của `claude.complete`/ghi file khi được gọi | Chỉ đọc |
| Storage | không đọc/ghi localStorage/sessionStorage/indexedDB; `document.cookie` chỉ ghi khi nhận message "omelette-preview-refresh" từ máy chủ; `omelette.storage` đọc `data/storage.json` khi được gọi | (5) không chạm |
| Mạng/điều hướng | bọc `fetch` và `img.src` chỉ khi `K === true` (đang `false` → không bật); không có `location`/`history`/`top.`; `eval` mã từ message `__om_eval` của cửa sổ cha (cách công cụ của AI chạy lệnh) | (4) không chạm |
- **d) DC con** [ĐỌC CODE `support.js` + ĐO]: Shell lấy HTML DC con bằng `fetch(url)` qua máy chủ xem trước → văn bản **có** khối chèn (đo 4 DC con: có, cùng hash); `parseDcText` chỉ lấy phần giữa `<x-dc>`…`</x-dc>` (khối chèn nằm ở `<head>`, ngoài phần đó) → **không chạy, CSS không vào tài liệu**. Đo trong Shell: đúng 2 phần tử `[data-omelette-injected]` (của chính Shell).
- **e) Thứ tự** [ĐỌC CODE `H.iso` + ĐO]: storage giả cài **trước** `document.write`; trong tài liệu đo thứ tự là `STYLE` (`support.js` chèn đầu `<head>`) → `STYLE[inj]` → `SCRIPT[inj]` → `support.js` …; lượt đọc khoá dự án đầu tiên là script helmet của Shell (`bs-scheme`, lúc `loading`), giá trị bản giả. Lần chạy đầu của mã chèn (trong iso-blank trước `document.open`) không truy cập storage [ĐỌC CODE].
- **Ngưỡng nâng cấp (1)–(5): KHÔNG chạm.** Ghi nhận rủi ro [SUY LUẬN] ở dòng `color-scheme` trên: thuộc L1-T-03.

#### Bước II — Inter [ĐO-ẨN; số metric không phụ thuộc thời gian]
- **a) Font đang thắng ở Shell:** FontFace Inter `loaded` = các face Google weight 400/500/600/700 × tập con latin, latin-ext, vietnamese (12 bộ). Ngoài ra **`Inter-italic.woff2` tự host (387.976 B) cũng được tải** (resource entry, initiator `css`) → chữ nghiêng trong Shell đang vẽ bằng file tự host [ĐO]. `Inter-roman.woff2` tự host không tải. Các DC nạp Google Inter dùng cùng 2 URL CSS (`wght@400;500;600;700` hoặc `…;800`) → cùng bộ file biến thiên mỗi tập con. File Google (fonts.gstatic.com/s/inter/v20/…, 1 file mỗi tập con dùng cho mọi weight) [ĐO byte + SHA-256 12]: cyrillic-ext 25.960 · ca157063339a · cyrillic 18.748 · 71d5ee93cc1e · greek-ext 11.232 · 6e9e020a25f9 · greek 18.996 · 1be3448e292f · vietnamese 10.252 · 5c66f9e07e90 · latin-ext 85.068 · 34b9c504cab7 · latin 48.256 · 3100e775e861. **Phiên bản Inter: không rõ** (bảng name nằm trong dữ liệu nén brotli của woff2; trình duyệt không có `DecompressionStream('brotli')`). "v20" trong URL không phải phiên bản Inter.
- **b) `inter.css` tự host** [ĐỌC CODE]: 2 face (roman, italic), `font-weight: 100 900` (có 800), `unicode-range: U+0000-00FF, U+0100-024F, U+0300-036F, U+1E00-1EFF, U+20AB, U+0400-04FF, U+0500-052F` (có U+1EC7; **không có** U+2000-206F, U+2190-21FF), `src: local("Inter"), url(…)` (máy có cài Inter thì dùng bản cài), **`ascent-override: 90%; descent-override: 22%; line-gap-override: 0%; size-adjust: 105%`**. Phiên bản: không rõ (như trên). File: roman 352.240 · 693b77d4f32e · italic 387.976 · e564f652916d.
- **c) Metric** (`_audit/inter-metric.html`, family tạm BSG = Google 35 face, BSL = tự host; mọi face normal `loaded`): chuỗi "viet", "so" (`3.470 4,4K 131.540`), "hoa" hợp lệ ở 5 weight × 13/14/16px; chuỗi "dash" (`— · ₫ → …`) **thiếu glyph**: BSG thiếu `→` U+2192; BSL thiếu `—` U+2014, `→` U+2192, `…` U+2026 → ô "dash" không kết luận.
| Chuỗi | weight | 13px G → L (chênh) | 14px | 16px |
|---|---|---|---|---|
| viet | 400 | 311,79 → 327,38 (+15,59) | 335,77 → 351,19 (+15,42) | 383,74 → 397,48 (+13,74) |
| viet | 600 | 314,80 → 330,54 (+15,74) | 339,01 → 354,64 (+15,63) | 387,45 → 401,97 (+14,52) |
| viet | 800 | 318,02 → 333,92 (+15,90) | 342,48 → 358,57 (**+16,09**) | 391,41 → 407,03 (+15,62) |
| so | 400 | 117,86 → 123,76 (+5,90) | 126,93 → 132,81 (+5,88) | 145,06 → 150,43 (+5,37) |
| so | 700 | 123,87 → 130,07 (+6,20) | 133,40 → 139,59 (+6,19) | 152,46 → 158,14 (+5,68) |
| hoa | 400 | 230,27 → 241,79 (+11,52) | 247,99 → 259,76 (+11,77) | 283,41 → 295,32 (+11,91) |
| hoa | 800 | 239,58 → 251,55 (+11,97) | 258,00 → 270,38 (+12,38) | 294,86 → 307,91 (+13,05) |
  (500/700 nằm giữa; span DOM trùng `measureText` ±0,6.) Ascent/descent (`fontBoundingBox`): G 13/3 · 14/3 · 16/4 → L 12/3 · 13/3 · 15/4. Chiều cao span 13px: 16 → 15,2.
- **d) Kết luận: "khác — đổi giao diện"** — chữ rộng hơn ~4–5 % (lớn nhất +16,09px trên chuỗi 52 ký tự), dòng thấp hơn 1px, thiếu 3 dấu câu thường dùng. Nguyên nhân [ĐỌC CODE]: `size-adjust: 105%` + `ascent/descent-override` trong `inter.css`; dải mã không gồm U+2000-206F. → Mục cần quyết.

#### Bước III — Script đo (đóng băng, bảng ở đầu mục)
- **Định nghĩa** (theo spec gốc 1d, tinh chỉnh ở đây): *DOM* = số phần tử; cột "chỉ sản phẩm" bỏ phần tử `[data-omelette-injected]` (+ con), `<style>` transition của harness, phần tử `[data-audit-measure]` (script đo không thêm phần tử nào). *Font (a)* = số @font-face đọc được qua `cssRules`; *(b)* = stylesheet không đọc được + URL; *(c)* = `document.fonts.size` (khối chèn không tạo FontFace → tổng = chỉ sản phẩm). *Nguồn* = 1 link / 1 @import / 1 stylesheet hoặc `<style>` chứa @font-face của họ đó; stylesheet không đọc được tính theo tên họ trong URL. *Quét style* = `getComputedStyle` + `.color` + `.fontFamily` mọi phần tử sản phẩm. *Tải xong* = từ lúc tạo iframe đo tới khi chờ ổn định xong. *Baseline* = số phần tử sản phẩm + [đường dẫn, x, y, w, h (0,1), font-family, font-weight] của mọi phần tử đang hiển thị có chữ trực tiếp, class `ri-*`, `svg` hoặc `img`. *Chờ ổn định* = `H.stable` + `fonts.ready` + 2 khung rAF (ẩn: `HM.settle` ×2) sau `loadingdone` cuối; >5 giây hoặc `fonts.status ≠ loaded` → "chưa ổn định". Lọc "chỉ sản phẩm": theo `ownerNode` mang `data-omelette-injected`.

#### Bước IV — Đo thử (bản 1 các script) [ĐO-ẨN]
- Hash mã chèn trước = sau (865c3fd34b88). 2 lần × 29 DC (17:56:58 → 18:00:00 UTC), [M]/[U] đạt, 0 tài liệu ngoài cách ly, 0 lỗi, 0 sự kiện ẩn trong lô.
- **Số đo KHÔNG dùng được — lỗi của script bản 1** (phát hiện khi đọc kết quả):
  1. *Chờ ổn định ra quá sớm*: Shell đo được 765 phần tử sản phẩm, trong khi Bước I đo 3.038 trên cùng thiết lập; `document.fonts.size` 29 so với 69. Nguyên nhân [SUY LUẬN]: `RN.one` chỉ chờ sự kiện `load`; điều kiện ổn định không chờ DOM yên, stylesheet helmet và React vẽ xong; chỉ báo "chưa ổn định" không bắt được.
  2. *Không đi vào @import*: (a) và "nguồn" không đếm @font-face nằm trong stylesheet @import (vd. `styles.css` → `fonts/inter.css`) → 12 DC báo "0 nguồn Inter" có thể sai.
  → Theo quy tắc: không sửa bản 1; cần **bản 2** của `m3a-core.js`, `m3a-perf.js`, `m3a-font.js`, `m3a-run.js` (còn 2 bản mới mỗi script). Chưa làm (hết lượt). Không lưu `baseline/perf/font-L1-21a3a.json` (không lưu số của bản lỗi).
  3. Không ổn định giữa 2 lần (bản 1): chỉ Shell (baseline 184 ↔ 183 mục) — cùng nguyên nhân 1.
- **c) Bảng font hiện tại — chỉ nguồn nạp bằng link (đọc được chắc chắn ở bản 1)** [ĐO-ẨN + ĐỌC CODE]:
  - Google Inter `wght@400;500;600;700` + Remix 4.2.0: AI Writer Panel v2, Cài đặt nội dung AI, Cài đặt xuất bản tự động, Duyệt & xuất bản, Hàng đợi ảnh, Hạn ngạch AI, Kế hoạch nội dung SEO, Trình đơn, Trợ lý viết AI v2, Vận hành nội dung AI.
  - Google Inter `wght@400;500;600;700` + Remix 4.9.1: Admin Shell (+ `inter.css`), MediaPicker, Page Editor, Quản lý Bài viết (+ `inter.css`), Quản lý Trang.
  - Google Inter `…;800` + Remix 4.9.1: Block Library, Integrations; Page Builder v3 (Google Inter + Lora).
  - Chỉ Remix (không có link Inter riêng): Cài đặt - Đề xuất giao diện, Email Settings, Floating Contact, Language Context, Nâng cấp nội dung, Trình soạn nội dung (+ `inter.css`), Đăng nhập nhanh (4.9.1); Hộp duyệt, Quy trình duyệt, Trạng thái duyệt (4.2.0). Những DC này mở độc lập **không có FontFace Inter nào** `loaded` theo bản 1 (`vietOk = false`) — cần bản 2 xác nhận (có thể đến từ @import).
  - Image Picker: chỉ Google Inter.

#### Bước V — Nút
- (a) In thêm hash phục vụ, hash mã chèn, hash gốc (cắt theo chuỗi đánh dấu) của Shell và `.step-c04`, hash mã chèn iso-blank, cảnh báo "mã chèn khác mốc"; phép so XÁC NHẬN dùng hash gốc. (b) Thời gian ở chế độ thường: 1 lần làm nóng + 3 lần, trung vị.
- **Tự chạy** (17:53:26 → 17:56:07 UTC, `visible` suốt → chế độ thường) [ĐO]: hash gốc Shell = `.step-c04` = 587879556559 (trùng công cụ file) · mã chèn Shell = iso-blank = mốc · [M][C][U] đạt hai bên · 41/41 đạt · Q-lab 6/6 · .before ↔ hiện tại: 8 khác trong biểu đồ, 0 ngoài · storage trùng · **"XÁC NHẬN: ĐẠT" · "Xong"** → **nút sẵn sàng**. Thời gian: làm nóng 14.490 ms / 22,3 ms; tải xong 14.567 · 14.538 · 14.506 (trung vị **14.538 ms**); quét style 41,2 · 21,7 · 16,1 (trung vị **21,7 ms**). [SUY LUẬN] "tải xong" qua `H.openShell` gồm các khoảng chờ cố định của harness (`untilLoaded`, thử lại [C], `H.stable`) → chỉ để so trước/sau cùng cách đo, không phản ánh tốc độ thật.

**Mã**
| Mã | Trạng thái |
|---|---|
| L1-C-07 | Đóng (quyết định) |
| L1-C-08 | Mở — định dạng số rút gọn, L1-21a-4 |
| L1-T-03 | Mở — không chạm ngưỡng; rủi ro `color-scheme` phụ thuộc thời điểm [SUY LUẬN] |
| L1-F-01 (mới) | Mở — `inter.css` khác metric Google (size-adjust 105 %, ascent/descent override) và thiếu U+2014/U+2026/U+2192; Shell đang trộn nguồn (roman Google, italic tự host). Cần quyết trước 3b |
| L1-T-04 (mới) | Mở — script đo bản 1: chờ ổn định quá sớm, không đi vào @import; cần bản 2 trước khi IV có giá trị |
| L1-T-05 (mới) | Mở — văn bản ghi ĐẦY ĐỦ 164, harness đo 166; nguyên nhân chênh 2 chưa kiểm |

**Hash gốc mọi file sản phẩm (mốc cho 3b)** [ĐO 17:42 UTC]
| File | Byte (gốc) | SHA-256 (gốc) |
|---|---|---|
| `_ds/…/fonts/Inter-italic.woff2` | 387.976 | e564f652916db6c139570fefb9524a77c4d48f30c92928de9db19b6b5c7a262a |
| `_ds/…/fonts/Inter-roman.woff2` | 352.240 | 693b77d4f32ee9b8bfc995589b5fad5e99adf2832738661f5402f9978429a8e3 |
| `_ds/…/fonts/inter.css` | 971 | f8be4168a737256dc3fb399214572feed2b797df557ef0e7b9db87bc8f46329b |
| `Admin Shell.dc.html` | 341.271 | 587879556559aed3d901c13eb5a4818a050597b8fc4db2c9472537cd5ac11396 |
| `admin-base.css` | 5.328 | d0e9649f7543c64041aef1121ab8c91a25978251e667353927d60ffff5acd8f4 |
| `AI Writer Panel v2.dc.html` | 213.908 | 5cb07a594af1645d7d8d40fd0ac956274f70491a3f6bbc380ffb5c1b7a7759ff |
| `ai-quota.js` | 5.458 | deb179be1e8bee6b7b4bd966404e4019ec002bea2b137f7aedbb0edde714a7a5 |
| `Block Library - Options.dc.html` | 29.798 | af06c769ba0a58dd2896a406a76ac259708d7e61ea7d90b696bd21bb7e2b3a61 |
| `block-library.js` | 4.585 | 0b91700abdfef5d0b6fc291981bb775bc156701a4ff30260f781bcaa0e5252e1 |
| `Cài đặt - Đề xuất giao diện.dc.html` | 216.830 | dfe0157d0111d173ae55aed580d6119155ed9fb6a306180476bd29d3c7d67bb1 (đọc qua bản chép _audit/tmp/h/01.bin) |
| `Cài đặt nội dung AI.dc.html` | 73.050 | 1f2062ed41aa1e8ca9e2d3e302a6c9fb8dc4f55608c0432bf3b08bf052204c9e (đọc qua bản chép _audit/tmp/h/02.bin) |
| `Cài đặt xuất bản tự động.dc.html` | 41.407 | 4718035f324f92d039f02e197c4883127150a85b02d9b4d4518ba7667da2d6f1 (đọc qua bản chép _audit/tmp/h/03.bin) |
| `content-seo.js` | 22.947 | 3d514a65e673e035c13cdfbfb456760aa8116da5b69048607792cf9305cf93b6 |
| `content-ui.js` | 13.337 | ea6f8bb64d67883d6bf4e2ed887e0eb9b7c95459cd40faab8578b1ad6ec5b9f2 |
| `content-workflow.js` | 15.065 | 4fd411fc3d11b6b15ae2bec0825ac80fd0b4abf3babdd2930b3573b0fed483b3 |
| `Duyệt & xuất bản nội dung.dc.html` | 40.688 | 2e82d486e39d51fc132343b3851800d666e85b5613987e294b8b764d5f3990ac (đọc qua bản chép _audit/tmp/h/04.bin) |
| `Đăng nhập nhanh & Liên kết tài khoản.dc.html` | 64.213 | f1295d36bd582ff56d65bcb564c4845b4d2242dd5c0235d1fb2cc7107df57b8c (đọc qua bản chép _audit/tmp/h/18.bin) |
| `Email Settings.dc.html` | 208.303 | d2e4823bd2a890b2edc0e7fb8cf5795c23c7c7aa65c22c629f73f02a25b23b7e |
| `Floating Contact Panel.dc.html` | 71.711 | 6a9070b33e5d5a7a531049a4a73c5bb2c0b33db0b60b313d7b95861a60cb942b |
| `Hạn ngạch AI và mua thêm.dc.html` | 62.599 | 10c2b67ad4291f9ec05ee3c92802ca8236c14cf996a2e787ab9cc4c645805bc9 (đọc qua bản chép _audit/tmp/h/06.bin) |
| `Hàng đợi ảnh.dc.html` | 68.754 | b6d22bec4e2c975dc25379a46ffcf534b13277dab8174e5cbc9c677f67ff6511 (đọc qua bản chép _audit/tmp/h/05.bin) |
| `Hộp duyệt.dc.html` | 70.382 | a7cf311b8432fe4e26a82d5a50f30e493553c24d7f31998df986569c7d10d98d (đọc qua bản chép _audit/tmp/h/07.bin) |
| `Image Picker.dc.html` | 2.001 | d8690baf35c1a7fd0f9580df285a12513728b8ac8332bd58dbf904785da94e7c |
| `image-slot.js` | 65.350 | fff26d081c8d9d60870f86c7539a5d179b9cdab15e67f2b205508a068e7c7ff6 |
| `Integrations Panel.dc.html` | 86.559 | d5f4dcef2c0e588d9e23220cc07e13b46bfe0386eb079a5b0752f253e1054e37 |
| `Kế hoạch nội dung SEO.dc.html` | 158.995 | 38746fc9eeb70206d60d4eb2b37f2fbbc63c4a1b3b21f8f43b34696978ca5ed5 (đọc qua bản chép _audit/tmp/h/08.bin) |
| `Language Context - Design Spec.dc.html` | 102.089 | fb170794f5c8edb6fb8a37ada54343ba69c86164ebb23039b4dde1093d205445 |
| `MediaPicker.dc.html` | 62.031 | a479dadea42c735153fe2507f508f8cd99675ea9380db652e4ae27d7a7a0e698 |
| `Nâng cấp nội dung.dc.html` | 29.757 | e4936c6a0297335c084ef31cc57f04697a288a9cc332db374deba272a2355048 (đọc qua bản chép _audit/tmp/h/09.bin) |
| `Page Builder v3.dc.html` | 219.281 | 8737fbd2c87f8ef05d083a70099b77f6a818b79a572f1803d49e314d4b95549d |
| `Page Editor - Options.dc.html` | 299.741 | 43755ae84df11ceb6e6759200a087252affd24f5ba3fa4a33b6810335fa23370 |
| `platform-config.js` | 2.065 | 9f6434e27f023325a5ed4f9ec2173d47f3a22f0104b8f492be6a629f76ffc36e |
| `Quản lý Bài viết - Options.dc.html` | 260.154 | b273317b6032e04ac6845c6a949ad157d3064f7aa974470b5c3b9174db9530c9 (đọc qua bản chép _audit/tmp/h/11.bin) |
| `Quản lý Trang - Options.dc.html` | 78.246 | bb05b0a7b87a67f684a4f94fc6503e4049c768ae2a52b5c40ae95d5bfd309763 (đọc qua bản chép _audit/tmp/h/12.bin) |
| `Quy trình duyệt.dc.html` | 44.509 | 8aa00f811e7ae25f51b7c82c74a073a9e45d5adebc8e8d88dc096d1148dc6799 (đọc qua bản chép _audit/tmp/h/10.bin) |
| `support.js` | 69.150 | 8fe7df74405f3c55f49b7249c74ea1397e65d07dea2b1bd3b4a489bec2e28cbe |
| `Trạng thái duyệt (khối soạn thảo).dc.html` | 31.876 | 57beef1eaefe0b40b2ba0b4e3e1cdc7ba6bae700a01f3f494dd4d37a1da0b5e7 (đọc qua bản chép _audit/tmp/h/15.bin) |
| `Trình đơn.dc.html` | 189.505 | 7693457f4cc2639c800d58e429d7de8bfb0380454a7b41ba496afe72284940fd (đọc qua bản chép _audit/tmp/h/14.bin) |
| `Trình soạn nội dung.dc.html` | 95.975 | f4d1ef27e31d37d380376a2bb91715b497c9ed1391f77f76b02ebad72c4231f0 (đọc qua bản chép _audit/tmp/h/13.bin) |
| `Trợ lý viết nội dung AI v2 - Options.dc.html` | 39.010 | 4c190a5ba8878fdf7304dffc39122c2542f562673bc02052b37e4c3471ce3b3a (đọc qua bản chép _audit/tmp/h/16.bin) |
| `Vận hành nội dung AI.dc.html` | 47.516 | c3c4ac11ba7b9782a205eb73df90c048269aaaec55e633a07cf6feff241bed61 (đọc qua bản chép _audit/tmp/h/17.bin) |

---

### 5.L1-21a-3a-2 — Kiểm kê font ở mức face và điều kiện cho 3b (03/10/2026)

**Danh sách đóng băng — file đo mới của lượt này** (hash gốc = SHA-256 byte thô qua công cụ file, ghi TRƯỚC khi dùng để đo)
| File | Byte (gốc) | SHA-256 (gốc) | Dùng cho |
|---|---|---|---|
| `_audit/f3a2-face.js` (bản 4, sau 3 lần chạy thử) | 16.276 | ad8b983a943e876b1e954a26d2f9d11057a26bc39199a97fbbca4189e325976d | Bước F (và lần đọc của Bước B) |

**Tiến độ:** 0 xong · QUYẾT ĐỊNH đã ghi (4f "Duyệt L1-21a-3a") · A ĐẠT · I xong · F xong (Shell ổn định; 4 DC không ổn định, liệt kê) · P xong · B xong (mở L1-T-06) · G xong (đóng L1-T-05). Không sửa file sản phẩm, không sửa harness, không sửa `user-run.js`. Chế độ đo: **[ĐO-ẨN]** (02:44:54 UTC `visibilityState = 'hidden'`, `hasFocus() = false`; sau đó đổi qua lại, giữ chế độ ẩn cho mọi lô của AI; lớp `hidden-mode.js` bật bằng `HM.enable()`). Mọi lần nạp Shell/DC qua iframe đo của harness (`H.openShell` / `H.iso`).

#### Bước 0 [ĐO-ẨN]
- **Storage thật, mốc "1a-3a2"** (02:44:54 UTC, chỉ đọc, SHA-256 16): `bs-scheme` 5 · 99a7026172d42714 · `bs-ui` không có · `bswebsaas:pb:v3:draft` 1.854 · b7d0db7845108a20 · `bswebsaas:pe:handoff` 1.130 · cdbfc5d68c54df1a · `__audit_layout_baseline` 8.265 · 56696db1ec94c7e3 · sessionStorage trống → **trùng mốc 1a-3a**. Cuối lượt (03:04:51 UTC): trùng hoàn toàn; 0 iframe còn lại trong harness.
- **Bảng đóng băng** (6 file) + `user-run.js`: hash gốc (bản phục vụ đã cắt khối chèn) trùng bảng ở 5.L1-21a-3a: harness 23e212d898ef · hidden-mode 0c51ade00ba7 · iso-blank 5aed1a831e51 · navtest-page b4d5c354fe8f · navtest-child 1af65a423f16 · navtest-target 46a39304f587 · user-run 65999b24216f.
- **Shell:** hash gốc = 587879556559aed3d901c13eb5a4818a050597b8fc4db2c9472537cd5ac11396 (= `.step-c04`) → đạt. Cuối lượt: vẫn 587879556559.
- **41 file sản phẩm:** **41/41 trùng** bảng hash cuối 5.L1-21a-3a (thuật toán: SHA-256 trên byte bản phục vụ sau khi cắt đúng khối từ chuỗi mở `"\n<style data-omelette-injected>"` tới hết chuỗi đóng; file không có khối chèn thì băm nguyên; cùng định dạng hex 64 ký tự với bảng). Trong đó 18 file tên có dấu: trùng (xem A).
- **Hash mã chèn:** 865c3fd34b88… (20.369 B), như nhau ở 34/34 file text/html đo được (Shell, 28 DC, harness, iso-blank, 3 navtest) → trùng mốc. Cuối lượt: 865c3fd34b88.

#### Bước A — File tên có dấu [ĐO-ẨN]
- **a)** 18/18 DC tên có dấu: hash bản phục vụ đã cắt khối chèn **trùng** hash gốc đã tính qua bản chép (`_audit/tmp/h/NN.bin`), cùng thuật toán và định dạng. 0 khác, 0 "chưa đối chiếu". Cả 18 tên đều ở dạng **NFC** (`name.normalize('NFC') === name`); máy chủ xem trước chỉ phục vụ dạng NFC (thử NFD: 404).
- **b)** Bản chép không dấu: `_audit/tmp/h/01–18.bin` (byte) và `_audit/tmp/dc/01–18.txt` (văn bản) — đều trong `_audit/`. Ngoài `_audit/`: **không có** (gốc dự án chỉ có file thật; `_archive/` chỉ có 2 bản Page Builder cũ, không phải bản chép).
- **c) Chép:** `Trình đơn.dc.html` → `_audit/_test/trinh-don.dc.html.before` bằng công cụ chép: 189.505 B · 7693457f4cc2 (công cụ file) = bản phục vụ đã cắt khối chèn của file thật → **đạt**.
- **d) Ghi:** `_audit/_test/thử-ghi.html` (NFC): tạo bằng công cụ ghi → sửa 1 dòng bằng công cụ sửa → đọc qua máy chủ: có dòng đã sửa, không còn dòng cũ → ghi đè bằng công cụ ghi → đọc lại: chỉ có nội dung lần 2 → liệt kê `_audit/_test/`: đúng 2 file (`thử-ghi.html`, `trinh-don.dc.html.before`), không có bản trùng tên. **Ghi/sửa/ghi đè tên có dấu: dùng được.** Riêng công cụ chạy script (`readFile`) từ chối đường dẫn có dấu ("disallowed characters") → đọc tên có dấu qua máy chủ xem trước hoặc bản chép. File thử để lại: 2 file trên.
- **A ĐẠT.**

#### Bước I — Nội dung `inter.css` [ĐỌC CODE]
- `inter.css` (971 B · f8be4168a737): **chỉ có 2 khối @font-face**, không có `@import`, không có quy tắc nào khác (không selector, không biến CSS, không font-feature-settings). → Bỏ nạp `inter.css` không mất quy tắc nào của chính file này.
- **Nhưng `inter.css` còn được nạp gián tiếp qua `styles.css` của design system** (`@import "./fonts/inter.css";` dòng 3). `styles.css` có quy tắc ngoài @font-face: `html, body { font-family: var(--font-stack); font-size: var(--text-body-size); line-height: var(--text-body-lh); color: var(--color-body-text); -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; text-rendering: optimizeLegibility; font-synthesis: none; }` · `a { color: var(--color-admin-primary); text-decoration: none; }` · `a:hover { color: var(--color-admin-secondary); }` (+ 3 `@import` token: colors/typography/shape). → mục 7.
- **Khối chữ nghiêng, nguyên văn** (`inter.css` dòng 16–27):
```
@font-face {
    font-family: "Inter";
    src: local("Inter"), url("./Inter-italic.woff2") format("woff2");
    font-style: italic;
    font-weight: 100 900;
    font-display: swap;
    unicode-range: U+0000-00FF, U+0100-024F, U+0300-036F, U+1E00-1EFF, U+20AB, U+0400-04FF, U+0500-052F;
    ascent-override: 90%;
    descent-override: 22%;
    line-gap-override: 0%;
    size-adjust: 105%;
}
```
  URL đã phân giải: đường dẫn dự án `_ds/bssaas-adminkit-design-system-60ba9c0b-5497-49a2-b7c1-ba0772d8cf30/fonts/Inter-italic.woff2` (387.976 B · e564f652916d) — khi chép sang file CSS ở gốc dự án, `url()` phải đổi thành đường dẫn này (tương đối với vị trí file mới). Không có khối oblique.

#### Bước F — Kiểm kê face [ĐO-ẨN]
- **Script:** `_audit/f3a2-face.js` bản 4 · 16.276 B · ad8b983a943e (đóng băng trước lần đo chính; 3 lần chạy thử: t1 Shell, t2 Shell + Integrations + Nâng cấp, t3 Shell + Hộp duyệt; sửa giữa các lần: so tập khai báo không phụ thuộc thứ tự, chọn url woff2 trong `src`, bỏ chú thích CSS trước khi phân tích). Đo trên `fr.contentDocument`/`fr.contentWindow.performance` của iframe đo. Lần đo chính: 29 trang × 2 lần nạp, 02:53:59 → 02:58:04 UTC, 0 lỗi, 0 trang "chưa ổn định" theo điều kiện chờ (4 lần đếm, khoảng cách nhỏ nhất 250 ms; 2 vòng đọc tập loaded). Shell: **3.038 phần tử** (đếm `getElementsByTagName('*')` trừ `[data-omelette-injected]` + con, như Bước I lượt 3a) ở cả 2 lần.
- **Kết quả lưu:** `_audit/face-L1-21a3a2.json` (Shell đầy đủ; trang trùng cấu hình được ghi gọn có tham chiếu).
- **Shell — 2 lần đo trùng ở a, c, d, e, f.** Khai báo (không tính mã chèn; mã chèn 0 face): 69 FontFace = Google Inter 63 (link 400–700 của Shell: 28; link 400–800 do helmet của DC con Integrations gắn vào: 35) + `inter.css` roman 2 + italic 2 (link + @import qua `styles.css`) + Remix 4.9.1 1 + Remix 4.2.0 1 (do helmet DC con gắn). 'loaded' (26): Google 400/500/600/700 × {latin, latin-ext, vietnamese} = 12 face × 2 bản khai báo + Remix 4.9.1 + Remix 4.2.0. **`inter.css` roman và italic: unloaded.** Phần tử có chữ: 157 — Inter 400: 42 · 500: 31 · 600: 51 · 700: 10; Arial (ô điều khiển không kế thừa font) 400: 2 · 500: 9 · 600: 9 · 700: 3; **không có chữ nghiêng đang hiển thị** ở Tổng quan 1440. Bước f: 0 face chuyển 'loaded'.
- **File font đang tải** (hash qua fetch lại URL): Google latin 48.256 · 3100e775e861 · latin-ext 85.068 · 34b9c504cab7 · vietnamese 10.252 · 5c66f9e07e90 (trùng 3a) · Remix 4.9.1 woff2 189.216 · e92dc38bfb1d · Remix 4.2.0 woff2 163.200 · 7c1b0867b618 · `Inter-roman.woff2` 352.240 · 693b77d4f32e · `Inter-italic.woff2` 387.976 · e564f652916d. CSS Google (fetch lại + phân tích): mỗi weight 7 tập con, mọi weight dùng chung 1 file mỗi tập con.
- **d) Nguồn Inter đang dùng** — [SUY LUẬN] theo FontFace có `status = 'loaded'` khớp family/style/weight và phủ ký tự 'a' (face chỉ tải khi trình duyệt cần để vẽ hoặc khi `fonts.load` gọi; a) đọc trước f)). Trường `win` trong script (chọn theo thứ tự khai báo) **không dùng được**: ở Shell `inter.css` khai báo SAU Google mà vẫn unloaded. Kết quả:
| Nhóm | Trang | Chữ thường | Chữ nghiêng đang hiển thị |
|---|---|---|---|
| G | Shell, AI Writer, Block Library, Cài đặt nội dung AI, Cài đặt xuất bản, Duyệt & xuất bản, Hạn ngạch AI, Hàng đợi ảnh, Image Picker, Integrations, Kế hoạch SEO, MediaPicker, Page Builder v3, Page Editor, Quản lý Bài viết, Quản lý Trang, Trình đơn, Trợ lý viết v2, Vận hành (19) | Google | Page Builder v3: 1 phần tử 400 nghiêng, không có face nghiêng → nghiêng giả từ Google (span = 590,99 = bản thường) · Page Editor: 1 phần tử, `inter.css` italic ở lần 1 (xem không ổn định) |
| L | Cài đặt - Đề xuất giao diện, Đăng nhập nhanh, Email Settings, Floating Contact, Hộp duyệt, Language Context, Nâng cấp nội dung, Quy trình duyệt, Trạng thái duyệt, Trình soạn nội dung (10) | `inter.css` | Cài đặt - Đề xuất: 2 phần tử 400 nghiêng, `inter.css` italic |
  Không trang nào đang dùng font dự phòng cho Inter.
- **f) Chuỗi mẫu 14px** (span, 0,01 px; chiều cao 16,8 ở mọi bộ Inter): Google 400 590,99 · 500 594,65 · 600 598,20 · 700 601,73 · 800 606,08 — `inter.css` 400 614,83 · 500 619,16 · 600 622,53 · 700 627,29 · 800 633,31 · nghiêng 400 618,59. Chênh `inter.css` − Google: +23,84 · +24,51 · +24,33 · +25,56 · +27,23 px (≈ +4,0–4,5 %). Mọi trang cùng nhóm cho đúng các số này.
- **e) Remix:** link 4.2.0 ở 13 DC (AI Writer, Cài đặt nội dung AI, Cài đặt xuất bản, Duyệt & xuất bản, Hạn ngạch AI, Hàng đợi ảnh, Hộp duyệt, Kế hoạch SEO, Quy trình duyệt, Trạng thái duyệt, Trình đơn, Trợ lý viết v2, Vận hành) · 4.9.1 ở 15 (Shell, Block Library, Cài đặt - Đề xuất, Đăng nhập nhanh, Email Settings, Floating Contact, Integrations, Language Context, MediaPicker, Nâng cấp, Page Builder v3, Page Editor, Quản lý Bài viết, Quản lý Trang, Trình soạn) · **Image Picker không có link Remix trong file** → 13 + 15 + 1 = 29. Khi chạy, Image Picker có Remix 4.9.1 'loaded' do helmet của DC con MediaPicker; Shell có cả 4.9.1 lẫn 4.2.0 'loaded' (4.2.0 do helmet DC con). Face 'loaded' = file woff2 của đúng bản.
- **Không ổn định giữa 2 lần (chỉ liệt kê, theo tiêu chí lượt này):**
  1. **Cài đặt - Đề xuất giao diện** — a: `inter.css` khai báo 2 lần (link + @import) ở lần 1, 1 lần ở lần 2 (lần 2 chưa có face từ @import); c, d, e, f trùng.
  2. **Page Builder v3** — a: số face 'loaded' của 7 họ font xem trước (Be Vietnam Pro, Lora, Noto Serif, Space Grotesk…) khác nhau; Inter, Remix, c, d, f trùng.
  3. **Page Editor** — lần 2 chỉ 511 phần tử (lần 1: 625), không có `inter.css` (lần 1 có, do helmet DC con "Trình soạn nội dung" gắn [SUY LUẬN từ code: dc-import dòng 291]) → chữ nghiêng: `inter.css` italic 618,59 ↔ nghiêng giả Google 590,99 (a, d, f khác).
  4. **Vận hành nội dung AI** — a: `inter.css` khai báo 1 ↔ 2 lần (unloaded cả hai); c, d, e, f trùng.
- **Phát hiện ngoài dự kiến** [ĐO]: helmet của DC con **có** vào tài liệu Shell (link Google 400–800 của Integrations, Remix 4.2.0, `inter.css` của các DC con; runtime gộp trùng theo URL). Ghi chú của lệnh "face khi chạy thật là face của Shell" đúng về kết quả nhưng tập face của Shell gồm cả link do DC con gắn.

#### Bước P — Dự báo khi áp (b′) [SUY LUẬN từ F, I]
1. **Chữ thường sẽ đổi khi áp (b′)** (mở riêng; `inter.css` → Google, mỗi chuỗi mẫu hẹp lại 23,8–27,2 px, chiều cao không đổi): Cài đặt - Đề xuất giao diện (400/500/600/700) · Đăng nhập nhanh (400–700) · Email Settings (400–700) · Floating Contact (400–700, **800**) · Hộp duyệt (400–700) · Language Context (400–700) · Nâng cấp nội dung (400, 600) · Quy trình duyệt (400–700) · Trạng thái duyệt (400, 600, 700) · Trình soạn nội dung (400, 600). **10 trang**, cả 10 **không có link Google Inter** → 3b phải thêm link Google cho cả 10 (không thì rơi về font dự phòng).
2. **7 DC con khi chạy trong Shell:** chữ thường đã là Google (Shell) → **không đổi**, với điều kiện Shell vẫn giữ link Google 400–700 và còn nguồn 800 (hiện do Integrations gắn; Floating Contact dùng 800). Khi mở riêng: Email Settings, Đăng nhập nhanh, Floating Contact đổi (mục 1); Hạn ngạch AI, Trình đơn, Integrations, MediaPicker không đổi.
3. **Chữ nghiêng sẽ đổi:** Page Builder v3 chỉ khi 3b gắn file CSS chữ nghiêng mới cho trang này (hiện nghiêng giả từ Google, không nạp `inter.css`, không có `font-synthesis: none`); nếu 3b chỉ thay ở nơi đang nạp `inter.css` thì không đổi. Page Editor: phụ thuộc DC con Trình soạn (không ổn định). Các trang có chữ nghiêng từ `inter.css` (Cài đặt - Đề xuất) giữ nguyên nếu khối nghiêng chuyển nguyên trạng.
4. **L1-F-03 (font dự phòng):** không có trang nào → không mở.
5. **Cần thêm weight vào link Google ở 3b:** 800 cho Floating Contact (link Google 400–700 hiện dùng không phục vụ 800). Weight khác của 10 trang ⊂ 400–700.
6. **`inter.css` đang nạp ở đâu** (số dòng theo file gốc = dòng bản phục vụ − 3): link trực tiếp ở **22 DC** — Shell 16 · AI Writer 14 · Cài đặt - Đề xuất 16 · Cài đặt nội dung AI 14 · Cài đặt xuất bản 14 · Duyệt & xuất bản 14 · Đăng nhập nhanh 14 · Email Settings 15 · Floating Contact 14 · Hạn ngạch AI 14 · Hàng đợi ảnh 14 · Hộp duyệt 14 · Kế hoạch SEO 14 · Language Context 14 · Nâng cấp 14 · Quản lý Bài viết 17 · Quy trình duyệt 14 · Trạng thái duyệt 14 · Trình đơn 14 · Trình soạn 15 · Trợ lý viết v2 15 · Vận hành 14; và **@import trong `styles.css`** (dòng 3) ở **19 DC** có link `styles.css` (22 DC trên trừ Nâng cấp, Quản lý Bài viết, Trình soạn). **Không** nằm trong `admin-base.css`, `support.js` hay file .js dùng chung. Không có `inter.css`: Block Library, Image Picker, Integrations, MediaPicker, Page Builder v3, Page Editor (chỉ qua DC con), Quản lý Trang.
7. **Lợi ích thực của việc bỏ nạp trùng** [ĐO, performance entries]: file roman `inter.css` chỉ thật sự tải thân file (352.240 B) **1 lần** trong cả lô (Đăng nhập nhanh, lần đầu); các lần sau là kiểm lại bộ nhớ đệm 300 B / thân 0 B. Ở **19 trang nhóm G** (gồm Shell) roman `inter.css` không bao giờ 'loaded' → **0 byte font bớt được**; chỉ bớt 1–3 request `inter.css` (≤ 687 B truyền) + 1–2 request `styles.css` mỗi trang. Ở 10 trang nhóm L: tải lạnh đổi 352.240 B (roman) lấy 143.576 B (3 file Google) + 742 B CSS Google → **≈ −208 KB**, đổi lại thêm 1 nguồn ngoài (fonts.googleapis/gstatic). Chữ nghiêng không đổi byte.

#### Bước B — `H.stable` (thông tin) [ĐO-ẨN]
- Gọi đúng `H.stable` của harness đóng băng (`H.iso` → `H.untilLoaded` → `H.stableP`), 3 lần: lúc trả về Shell có **766** phần tử (cả 3 lần; chờ 2 ms, 0 toast); khi điều kiện chờ Bước F đạt: **3.038** (cả 3 lần). Thấp hơn 74,8 % > 1 % → **mở L1-T-06**.
- Phép kiểm đo ngay sau `H.stable` [ĐỌC CODE]: **`00-open`** và **`Z-b-hash-profile`, `Z-b-hash-settings`** (đều gọi `H.openShell` rồi `H.chkMCU` ngay; [C] có thử lại tới 2 lần × 1 s). Mọi phép còn lại của RÚT GỌN/ĐẦY ĐỦ chạy sau `H.size` (chờ `winW` + 450 ms) và `H.st` (≥ 900 ms), hoặc trên Shell đã mở từ phép trước. Rủi ro: [M]/[U] đọc nhật ký storage/đường dẫn nên ít phụ thuộc DOM; hai phép `Z-b-hash-*` đọc trạng thái mục sau hash nên có thể đọc sớm [SUY LUẬN].

#### Bước G — Số phép kiểm (L1-T-05) [ĐỌC CODE + ĐO]
- `H.suite('quick').length` = 41, `H.suite('full').length` = 166; `H.C20.length` = 20. Phần chỉ có ở ĐẦY ĐỦ (đọc code): 24 + 6 + 20 + 48 + 15 + 6 + 3 + 1 + 2 = **125** → 41 + 125 = 166.
- Nguyên nhân chênh 2: văn bản 5.L1-21a mục 1f ghi "ĐẦY ĐỦ: 157" nhưng chính các số hạng liệt kê ở đó cộng ra **159** (34 + 24 + 6 + 20 + 48 + 15 + 6 + 3 + 1 + 2) → cộng sai; "164 = 157 − 1 + 2 + 6" kéo theo sai. Code không đổi số phép. Đã sửa văn bản ở 1f, Bước A lượt 2, lượt 3, lần 4 (ghi kèm "ghi cũ … đính chính L1-T-05"). **Đóng L1-T-05.**

**Mã**
| Mã | Trạng thái |
|---|---|
| L1-T-03 | Theo dõi (QĐ 1) — mã chèn không đổi (865c3fd34b88) |
| L1-T-04 | Đóng — script bản 1 bỏ, thay bằng `f3a2-face.js` (QĐ 4) |
| L1-T-05 | Đóng — 166 đúng; 164 là cộng sai trong văn bản |
| L1-F-01 | Mở — chọn (b′); chờ 3b |
| L1-F-02 (mới) | Mở — đồng bộ chữ nghiêng (sau khi người dùng xem tay) |
| L1-F-04 (mới) | Mở, tuỳ chọn — tự host đúng file Google, đóng đinh phiên bản |
| L1-T-06 (mới) | Mở — `H.stable` trả về khi Shell mới có 766/3.038 phần tử; không chặn 3b (QĐ 5) |
| L1-F-03 | Không mở — không trang nào dùng font dự phòng cho Inter |
| L1-C-08 | Mở — L1-21a-4 |

**Việc cần quyết trước 3b**
1. `styles.css` @import `inter.css` ở 19 DC (gồm Shell): không sửa `inter.css` thì phải bỏ link `styles.css` ở 19 DC. Mất `html, body {…; font-synthesis: none}` và `a`/`a:hover`. **Khuyến nghị:** bỏ link `styles.css`, chép nguyên văn 3 quy tắc đó vào file CSS font mới của dự án (cùng khối nghiêng); token đã được link riêng ở các DC này. Phương án khác: sửa `styles.css` của design system (lệch nguồn design system).
2. 10 trang nhóm L đổi chữ thường (hẹp lại ≈ 4 %): thêm link Google `wght@400;500;600;700` (Floating Contact: `…;800`); 3b đo trước/sau, chạy lại 6 Q-lab, người dùng duyệt (QĐ 3).
3. Nguồn 800 trong Shell hiện đến từ helmet của Integrations; nếu L1-21b thôi mount panel ẩn thì Floating Contact trong Shell mất 800. **Khuyến nghị:** Shell tự khai 800 trong link Google của mình ở 3b (Shell không dùng 800 nên face Shell không đổi).
4. Page Builder v3: có gắn file chữ nghiêng mới không (đổi nghiêng giả → nghiêng thật, thuộc L1-F-02). **Khuyến nghị:** không gắn ở 3b.
5. L1-T-06: sửa `H.stable` ở lượt riêng (cần cho 3c).

---

### 5.L1-21a-3b — Thống nhất chữ thường Inter theo Google (03/10/2026)

**Danh sách đóng băng — file đo mới** (hash gốc, ghi TRƯỚC Bước 1)
| File | Byte (gốc) | SHA-256 (gốc) | Dùng cho |
|---|---|---|---|
| `_audit/m3b.js` (bản 2, sau 2 lần chạy thử) | 9.884 | 9d888ab2f112881b0de4a07f6a2f641106c1976cf2b19870261ae4918e892c6b | Bước 1, Bước 3 |

**ĐANG SỬA 3b lần 1** (04:28 UTC) — file sẽ sửa: `Admin Shell.dc.html` + 10 trang NHÓM Đ (Cài đặt - Đề xuất giao diện, Đăng nhập nhanh & Liên kết tài khoản, Email Settings, Floating Contact Panel, Hộp duyệt, Language Context - Design Spec, Nâng cấp nội dung, Quy trình duyệt, Trạng thái duyệt (khối soạn thảo), Trình soạn nội dung) và `CLAUDE.md` (chỉ khi ĐẠT).

**Sao lưu `.pre-3b`** (công cụ chép; so byte + hash gốc với Bước 0: 11/11 trùng) — ánh xạ tên:
| File gốc | Bản sao lưu | Byte · hash |
|---|---|---|
| Admin Shell.dc.html | `_audit/backup/Admin Shell.dc.html.pre-3b` | 341.271 · 587879556559 |
| Cài đặt - Đề xuất giao diện.dc.html | `cai-dat-de-xuat-giao-dien.dc.html.pre-3b` | 216.830 · dfe0157d0111 |
| Đăng nhập nhanh & Liên kết tài khoản.dc.html | `dang-nhap-nhanh-lien-ket-tai-khoan.dc.html.pre-3b` | 64.213 · f1295d36bd58 |
| Email Settings.dc.html | `Email Settings.dc.html.pre-3b` | 208.303 · d2e4823bd2a8 |
| Floating Contact Panel.dc.html | `Floating Contact Panel.dc.html.pre-3b` | 71.711 · 6a9070b33e5d |
| Hộp duyệt.dc.html | `hop-duyet.dc.html.pre-3b` | 70.382 · a7cf311b8432 |
| Language Context - Design Spec.dc.html | `Language Context - Design Spec.dc.html.pre-3b` | 102.089 · fb170794f5c8 |
| Nâng cấp nội dung.dc.html | `nang-cap-noi-dung.dc.html.pre-3b` | 29.757 · e4936c6a0297 |
| Quy trình duyệt.dc.html | `quy-trinh-duyet.dc.html.pre-3b` | 44.509 · 8aa00f811e7a |
| Trạng thái duyệt (khối soạn thảo).dc.html | `trang-thai-duyet-khoi-soan-thao.dc.html.pre-3b` | 31.876 · 57beef1eaefe |
| Trình soạn nội dung.dc.html | `trinh-soan-noi-dung.dc.html.pre-3b` | 95.975 · f4d1ef27e31d |

**Nhật ký sửa 3b lần 1** (04:29 UTC; hash gốc sau sửa = bản phục vụ đã cắt khối chèn, SHA-256) — 11 file đổi, 38 file còn lại trong bảng Bước 0 (gồm 6 file đóng băng, `f3a2-face.js`, `user-run.js`) không đổi [ĐO]:
| File | Dòng đã đổi (file gốc) | Byte · SHA-256 sau sửa |
|---|---|---|
| Admin Shell.dc.html | 14: `wght@400;500;600;700` → `wght@400;500;600;700;800` | 341.275 · 66220e60d86befdd15461d9d561d8e95a22842e4248da7f658a9ac3c2a7de99f |
| Cài đặt - Đề xuất giao diện.dc.html | +21 (sau `styles.css` dòng 20, trong helmet) | 216.946 · 98ef65c3f6191d27d0f2288ed43723580b4aa93984c22c66598d2eb87eedcb57 |
| Đăng nhập nhanh & Liên kết tài khoản.dc.html | +19 (sau `styles.css` 18, helmet) | 64.329 · 6b7f95aba9b21b7569761610ff84c944152ffec24833e456ad92231cdf40f15b |
| Email Settings.dc.html | +18 (sau `styles.css` 17, helmet) | 208.419 · 1fb8f6575c4ec4b05433b46fc6d3079fbd22f138cf8d5055e6e344eb50727c61 |
| Floating Contact Panel.dc.html | +19 (sau `styles.css` 18, helmet) | 71.827 · 2f2996caf5bb3afe2788ee5f57ed7a3c43e85ccce7a123cb27ef046a0041a050 |
| Hộp duyệt.dc.html | +19 (sau `styles.css` 18, helmet) | 70.498 · e83ab31c020471d46215d23c180f577512b9aff1a923d57b39c05c74b42572bc |
| Language Context - Design Spec.dc.html | +19 (sau `styles.css` 18, helmet) | 102.205 · 36f05b025e084e3bc9f28de920a0c1335658c110879fb77b40ea0c085c73e95e |
| Nâng cấp nội dung.dc.html | +15 (sau `inter.css` 14, helmet; trang không có `styles.css`) | 29.873 · e1a4de824e23882469297cc0152396c8389a152e37dbf202245120fbcc4db264 |
| Quy trình duyệt.dc.html | +19 (sau `styles.css` 18, helmet) | 44.625 · 47b1a7a7a005d851000238fd0203ffaee72770521c9a1a133719633621b3ec4f |
| Trạng thái duyệt (khối soạn thảo).dc.html | +19 (sau `styles.css` 18, helmet) | 31.992 · 2469d2d9edfff993f18634ac3e2096d48b001169a85def2313d0ef228079f296 |
| Trình soạn nội dung.dc.html | +16 (sau `inter.css` 15, helmet; không có `styles.css`) | 96.091 · 069dc6d69d613ad6b53d91a6d3e426150c8258ffc81b156054ab0312c1cf958b |
Dòng thêm (nguyên văn, mọi trang NHÓM Đ): `<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">`.

**3b lần 1 KIỂM ĐẠT** (04:34 UTC, [ĐO-ẨN]) — chi tiết ở "Bước 3" bên dưới.

#### Bước 0 [ĐO-ẨN]
- Nhật ký 3b trước lượt này: không có dòng "ĐANG SỬA 3b" → lần 1. Chế độ: `visibilityState = 'hidden'` (04:19:33 UTC), `hasFocus() = false` → **[ĐO-ẨN]**, lớp `hidden-mode.js` bật.
- **Storage thật, mốc "1a-3b"** (chỉ đọc, SHA-256 16): `bs-scheme` 5 · 99a7026172d42714 · `bs-ui` không có · `bswebsaas:pb:v3:draft` 1.854 · b7d0db7845108a20 · `bswebsaas:pe:handoff` 1.130 · cdbfc5d68c54df1a · `__audit_layout_baseline` 8.265 · 56696db1ec94c7e3 · sessionStorage trống → trùng mốc 1a-3a2.
- Bảng đóng băng (6 file + `f3a2-face.js` ad8b983a943e) + `user-run.js`: trùng. **41/41 file sản phẩm trùng bảng 3a**. Ghi thêm (không thuộc 41): `CLAUDE.md` 37.391 · 48e1db7a53e1 · `_ds/…/styles.css` 765 · 652802fef093. Hash mã chèn 865c3fd34b88 (34 file).
- **URL CHUẨN** = `https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap` (link Shell `wght@400;500;600;700` + 800). Fetch [ĐO]: 200, 35 face (5 weight × 7 tập con), **7 face 800**, không có face nghiêng.

#### Bước S — `_audit/m3b.js` (bản 2, đóng băng ở đầu mục) [ĐO-ẨN]
- 2 lần chạy thử (Shell + Nâng cấp; Shell + Cài đặt - Đề xuất + Floating Contact). Ghi cứng từ Shell: body `font-family: Inter, "Segoe UI", sans-serif` · `font-synthesis: none` · html/body `line-height: 21px`.
- **CHUỖI A** (mọi ký tự nằm trong unicode-range của URL CHUẨN — m3b tự kiểm, 0 ký tự ngoài dải): `Quản lý nội dung đa ngôn ngữ — Tiếng Việt: ắ ặ ề ộ ữ ỹ Đ ƯỜNG HÀ NỘI 3.470 4,4K 131.540 ₫ (…) “Lưu”, 100% · ?!`. CHUỖI ĐỦ = chuỗi mẫu của `f3a2-face.js`.
- Span: con của `documentElement`, `position:absolute; left:-10000px`; vì absolute nên chiều cao span = line-height kế thừa từ `html` (Shell 21px). Đo thêm `A_lh` (cùng span, `line-height: 21px`) chỉ để dùng lối ra "khác CSS".
- **→ (U+2192)**: không nằm trong dải của URL CHUẨN lẫn `inter.css`; span "→" = span "→" với family chỉ gồm font dự phòng (12,09 px) ở Shell và cả 10 trang, trước và sau → **vẽ bằng font dự phòng** [ĐO]. — (U+2014) và … (U+2026) nằm trong dải latin của Google (U+2000-206F).
- **Link 800 do Integrations gắn**: lần thử 1 (04:21) chưa có lúc nạp, có sau khi chờ, Shell 800 = `inter.css` (CHUỖI A 815,23); các lần sau (Bước 1, 3) có ngay lúc nạp, Shell 800 = Google (778,66) → **nguồn 800 của Shell trước 3b phụ thuộc thời điểm** [ĐO]. Sau 3b Shell tự khai 800.

#### Bước 1 — Đo TRƯỚC (04:23:48 → 04:26:28 UTC) [ĐO-ẨN]
- Shell + 10 trang NHÓM Đ, mỗi trang 2 lần `f3a2-face.js` + 2 lần `m3b.js`: **mọi trang 2 lần trùng** (f3a2 diff rỗng; m3b A/FULL/nghiêng/weight trùng; 0 phần tử đổi số dòng). Shell 3.038 phần tử. RÚT GỌN 41/41, 0 KHÔNG ĐO ĐƯỢC; 6 Q-lab: 7d tầng 2 (7 nhãn, 0 vượt) · 30d/Năm tầng 3 (1 nhãn + TB). Lưu `_audit/face-L1-21a3b-before.json`.
- CHUỖI A trước: Shell (Google) 400 755,75 · 500 761,26 · 600 766,69 · 700 772,04 · 800 778,66 (cao 21). NHÓM Đ (`inter.css`) 400 786,78 · 500 793,55 · 600 797,92 · 700 805,48 · 800 815,23.

#### Bước 2 — Sửa: xem "ĐANG SỬA 3b lần 1" và "Nhật ký sửa 3b lần 1" ở trên. Thứ tự lúc chạy (`document.styleSheets`, Bước 3): link URL CHUẨN đứng SAU `inter.css`/`styles.css` ở cả 10 trang [ĐO]. Liệt kê gốc dự án: 31 file `.dc.html`, mỗi tên có dấu đúng 1 file.

#### Bước 3 — Đo SAU và kiểm (04:29:21 → 04:34:11 UTC) [ĐO-ẨN]
**Shell — ĐẠT**
- Tập face khai báo (như TẬP): **trùng trước** — 0 thêm, 0 bớt (face 800 Google đã có trước do helmet Integrations). Thứ tự stylesheet: `6:G URL CHUẨN · 8:inter.css · 12:styles.css · 16:G 400–700` (link 400–700 nay tách riêng là link do helmet DC con gắn — trước đây trùng URL của Shell nên bị gộp; L1-F-05).
- Tập 'loaded', font tính được: sau-1 khác sau-2 chỉ ở **trạng thái face nghiêng `inter.css`** (loaded ↔ unloaded; không có chữ nghiêng đang hiển thị); theo quy định đo lần 3 (+4): **sau-2 = sau-3 = sau-4 = trước** (tập khai báo, tập loaded, bảng đếm, nguồn, span) → ổn định.
- CHUỖI A 400–700 **trùng trước tới 0,01 px**; 800 trước = sau = 778,66 (Google, xem Bước S). Đổi dòng 0, tràn 0. U+1EC7: Google ở 400–800.
- RÚT GỌN 41/41: khác trước đúng 1 phép **Q-2cs1024** (lần 1 ghi 1 cặp tương phản "Chờ duyệt"); chạy lại 1 lần → **trùng trước**. `00-open`, `Z-b-hash-profile`, `Z-b-hash-settings` trùng trước. **6 Q-lab trùng trước** (digest).

**NHÓM Đ — 10/10 đạt tiêu chí CHUỖI A**
| Trang | CHUỖI A = Shell SAU | Chữ nghiêng | Đổi số dòng | Tràn mới | Nguồn chữ thường | Ổn định sau |
|---|---|---|---|---|---|---|
| Cài đặt - Đề xuất giao diện | = (400–700) | = trước (`inter.css`, 789,64) | 8 | 0 | Google | trùng |
| Đăng nhập nhanh | = (400–700) | — | 2 (1 phần tử 1→2 dòng) | 0 | Google | sau-1 ≠ sau-2 (sau-2 thiếu `inter.css`, bảng đếm khác); đo thêm 2 lần: trùng nhau và trùng sau-1 → xem tay |
| Email Settings | = | — | 0 | 0 | Google | trùng |
| Floating Contact | = (400–800) | — | 0 | 0 | Google (cả 800) | trùng |
| Hộp duyệt | = | — | 2 | 0 | Google | trùng |
| Language Context | = | — | 8 | 0 | Google | trùng |
| Nâng cấp nội dung | rộng =; cao 16,8 ≠ 21 → **không kết luận được (khác CSS)**: `html` line-height `normal`; đặt 21px thì trùng | — | 1 | 0 | Google | a khác (trạng thái face `inter.css`); A, nguồn trùng |
| Quy trình duyệt | = | — | 0 | 0 | Google | trùng |
| Trạng thái duyệt | = (400/600/700) | — | 0 | 0 | Google | ghi chú thứ tự khai báo; A, nguồn trùng |
| Trình soạn nội dung | rộng =; cao 16,8 ≠ 21 → **không kết luận được (khác CSS)**, như Nâng cấp | — | 0 | 0 | Google | trùng |
- Chênh CHUỖI ĐỦ (mọi trang): 400 −23,84 · 500 −24,51 · 600 −24,33 · 700 −25,56 · 800 −27,23 px. Chiều cao span 18,4 → 16,8 ở 2 trang line-height `normal` (metric Google khác `inter.css`); 21 không đổi ở 8 trang còn lại. Số phần tử mỗi trang +1 (thẻ link mới).
- Đổi số dòng: tổng **21 phần tử** (đều giảm dòng, trừ 1 phần tử ở Đăng nhập nhanh tăng 1→2); 0 tràn mới. Đường dẫn ở phiên đo; ví dụ Cài đặt - Đề xuất `section:nth-of-type(3)>div>p` 3→2.
- Kiểm thêm: 38 file không sửa (18 trang Google còn lại + 12 file khác + 6 đóng băng + 2 script) **trùng hash Bước 0**; 0/11 trang có chữ thường Inter rơi về font dự phòng; mọi weight đang dùng có FontFace 'loaded' phủ U+1EC7 (Google) sau `fonts.load`.
- **Lợi ích đo được** (performance entries, có bộ nhớ đệm): NHÓM Đ trước tải `Inter-roman.woff2` (kiểm lại cache 300 B, thân 0) + Remix; sau tải 3 file Google (thân 143.576 B, truyền 0 vì đã cache) + Remix, `Inter-roman.woff2` không còn request. Tải lạnh [SUY LUẬN từ byte file]: 352.240 B → 143.576 B + CSS Google 778 B.
- **Ký tự ngoài dải Google đang hiển thị** (chỉ ghi): Cài đặt - Đề xuất: → ×14, 日本語 ×11, 中文 ×3, 한국어 ×2, ≤, ≈ ×3, ⓘ ×5, ↔ · Floating Contact: 日本語 · Language Context: → ×31, 中文, （）, 繁體, ≥ ×4, ←, ⌥ ⌘ ⇧, 日本語 ×5, 简体, 한국어 ×3, العربية, עברית · Nâng cấp: → ×1, ≈ ×6 · Trạng thái duyệt: → ×3 · Shell và 5 trang còn lại: không có.
- Lưu `_audit/face-L1-21a3b-after.json`.

**Sao lưu bước** `.step-font` (11 file, công cụ chép, hash trùng sau sửa 11/11); `CLAUDE.md.pre-3b` (37.391 · 48e1db7a53e1, trùng file trước sửa).

**CLAUDE.md (QĐ 4):** thêm 1 mục "Font Inter (chốt L1-21a-3b)" ở §8, trước mục "Màu luôn qua token": URL CHUẨN; link đặt sau `inter.css`/`styles.css`; giữ `inter.css` cho chữ nghiêng; lý do size-adjust 105 % (~4–4,5 %); → vẽ bằng font dự phòng; bỏ hẳn `inter.css` = L1-F-04.

**Hash gốc mọi file sản phẩm (mốc sau 3b — từ lượt sau Bước 0 so bảng này)** [ĐO 04:35 UTC]
| File | Byte (gốc) | SHA-256 (gốc) | 3b |
|---|---|---|---|
| `_ds/…/fonts/Inter-italic.woff2` | 387.976 | e564f652916db6c139570fefb9524a77c4d48f30c92928de9db19b6b5c7a262a | không đổi |
| `_ds/…/fonts/Inter-roman.woff2` | 352.240 | 693b77d4f32ee9b8bfc995589b5fad5e99adf2832738661f5402f9978429a8e3 | không đổi |
| `_ds/…/fonts/inter.css` | 971 | f8be4168a737256dc3fb399214572feed2b797df557ef0e7b9db87bc8f46329b | không đổi |
| `Admin Shell.dc.html` | 341.275 | 66220e60d86befdd15461d9d561d8e95a22842e4248da7f658a9ac3c2a7de99f | **đổi ở 3b** |
| `admin-base.css` | 5.328 | d0e9649f7543c64041aef1121ab8c91a25978251e667353927d60ffff5acd8f4 | không đổi |
| `AI Writer Panel v2.dc.html` | 213.908 | 5cb07a594af1645d7d8d40fd0ac956274f70491a3f6bbc380ffb5c1b7a7759ff | không đổi |
| `ai-quota.js` | 5.458 | deb179be1e8bee6b7b4bd966404e4019ec002bea2b137f7aedbb0edde714a7a5 | không đổi |
| `Block Library - Options.dc.html` | 29.798 | af06c769ba0a58dd2896a406a76ac259708d7e61ea7d90b696bd21bb7e2b3a61 | không đổi |
| `block-library.js` | 4.585 | 0b91700abdfef5d0b6fc291981bb775bc156701a4ff30260f781bcaa0e5252e1 | không đổi |
| `Cài đặt - Đề xuất giao diện.dc.html` | 216.946 | 98ef65c3f6191d27d0f2288ed43723580b4aa93984c22c66598d2eb87eedcb57 | **đổi ở 3b** |
| `Cài đặt nội dung AI.dc.html` | 73.050 | 1f2062ed41aa1e8ca9e2d3e302a6c9fb8dc4f55608c0432bf3b08bf052204c9e | không đổi |
| `Cài đặt xuất bản tự động.dc.html` | 41.407 | 4718035f324f92d039f02e197c4883127150a85b02d9b4d4518ba7667da2d6f1 | không đổi |
| `content-seo.js` | 22.947 | 3d514a65e673e035c13cdfbfb456760aa8116da5b69048607792cf9305cf93b6 | không đổi |
| `content-ui.js` | 13.337 | ea6f8bb64d67883d6bf4e2ed887e0eb9b7c95459cd40faab8578b1ad6ec5b9f2 | không đổi |
| `content-workflow.js` | 15.065 | 4fd411fc3d11b6b15ae2bec0825ac80fd0b4abf3babdd2930b3573b0fed483b3 | không đổi |
| `Duyệt & xuất bản nội dung.dc.html` | 40.688 | 2e82d486e39d51fc132343b3851800d666e85b5613987e294b8b764d5f3990ac | không đổi |
| `Đăng nhập nhanh & Liên kết tài khoản.dc.html` | 64.329 | 6b7f95aba9b21b7569761610ff84c944152ffec24833e456ad92231cdf40f15b | **đổi ở 3b** |
| `Email Settings.dc.html` | 208.419 | 1fb8f6575c4ec4b05433b46fc6d3079fbd22f138cf8d5055e6e344eb50727c61 | **đổi ở 3b** |
| `Floating Contact Panel.dc.html` | 71.827 | 2f2996caf5bb3afe2788ee5f57ed7a3c43e85ccce7a123cb27ef046a0041a050 | **đổi ở 3b** |
| `Hạn ngạch AI và mua thêm.dc.html` | 62.599 | 10c2b67ad4291f9ec05ee3c92802ca8236c14cf996a2e787ab9cc4c645805bc9 | không đổi |
| `Hàng đợi ảnh.dc.html` | 68.754 | b6d22bec4e2c975dc25379a46ffcf534b13277dab8174e5cbc9c677f67ff6511 | không đổi |
| `Hộp duyệt.dc.html` | 70.498 | e83ab31c020471d46215d23c180f577512b9aff1a923d57b39c05c74b42572bc | **đổi ở 3b** |
| `Image Picker.dc.html` | 2.001 | d8690baf35c1a7fd0f9580df285a12513728b8ac8332bd58dbf904785da94e7c | không đổi |
| `image-slot.js` | 65.350 | fff26d081c8d9d60870f86c7539a5d179b9cdab15e67f2b205508a068e7c7ff6 | không đổi |
| `Integrations Panel.dc.html` | 86.559 | d5f4dcef2c0e588d9e23220cc07e13b46bfe0386eb079a5b0752f253e1054e37 | không đổi |
| `Kế hoạch nội dung SEO.dc.html` | 158.995 | 38746fc9eeb70206d60d4eb2b37f2fbbc63c4a1b3b21f8f43b34696978ca5ed5 | không đổi |
| `Language Context - Design Spec.dc.html` | 102.205 | 36f05b025e084e3bc9f28de920a0c1335658c110879fb77b40ea0c085c73e95e | **đổi ở 3b** |
| `MediaPicker.dc.html` | 62.031 | a479dadea42c735153fe2507f508f8cd99675ea9380db652e4ae27d7a7a0e698 | không đổi |
| `Nâng cấp nội dung.dc.html` | 29.873 | e1a4de824e23882469297cc0152396c8389a152e37dbf202245120fbcc4db264 | **đổi ở 3b** |
| `Page Builder v3.dc.html` | 219.281 | 8737fbd2c87f8ef05d083a70099b77f6a818b79a572f1803d49e314d4b95549d | không đổi |
| `Page Editor - Options.dc.html` | 299.741 | 43755ae84df11ceb6e6759200a087252affd24f5ba3fa4a33b6810335fa23370 | không đổi |
| `platform-config.js` | 2.065 | 9f6434e27f023325a5ed4f9ec2173d47f3a22f0104b8f492be6a629f76ffc36e | không đổi |
| `Quản lý Bài viết - Options.dc.html` | 260.154 | b273317b6032e04ac6845c6a949ad157d3064f7aa974470b5c3b9174db9530c9 | không đổi |
| `Quản lý Trang - Options.dc.html` | 78.246 | bb05b0a7b87a67f684a4f94fc6503e4049c768ae2a52b5c40ae95d5bfd309763 | không đổi |
| `Quy trình duyệt.dc.html` | 44.625 | 47b1a7a7a005d851000238fd0203ffaee72770521c9a1a133719633621b3ec4f | **đổi ở 3b** |
| `support.js` | 69.150 | 8fe7df74405f3c55f49b7249c74ea1397e65d07dea2b1bd3b4a489bec2e28cbe | không đổi |
| `Trạng thái duyệt (khối soạn thảo).dc.html` | 31.992 | 2469d2d9edfff993f18634ac3e2096d48b001169a85def2313d0ef228079f296 | **đổi ở 3b** |
| `Trình đơn.dc.html` | 189.505 | 7693457f4cc2639c800d58e429d7de8bfb0380454a7b41ba496afe72284940fd | không đổi |
| `Trình soạn nội dung.dc.html` | 96.091 | 069dc6d69d613ad6b53d91a6d3e426150c8258ffc81b156054ab0312c1cf958b | **đổi ở 3b** |
| `Trợ lý viết nội dung AI v2 - Options.dc.html` | 39.010 | 4c190a5ba8878fdf7304dffc39122c2542f562673bc02052b37e4c3471ce3b3a | không đổi |
| `Vận hành nội dung AI.dc.html` | 47.516 | c3c4ac11ba7b9782a205eb73df90c048269aaaec55e633a07cf6feff241bed61 | không đổi |
| `CLAUDE.md` | ~~38.170 · d4fddf485fd1…~~ → 38.337 | 4f41b9ecfb0eaba2ad0c7169a12b96f44fa16175270ba420eddeac963ef12305 | **đổi ở 3b** (trước: 37.391 · 48e1db7a53e1…, `.pre-3b`); **cập nhật ở T06** (`.pre-T06` = 38.170 · d4fddf485fd1) |

**Changelog 3b**
| Mã | File | Thay đổi | Trang ảnh hưởng | Đã kiểm lại |
|---|---|---|---|---|
| L1-F-01 | Admin Shell.dc.html | link Google Inter → URL CHUẨN (+800) | Shell (+ DC con trong Shell) | [ĐO-ẨN] tập face, loaded, span, RÚT GỌN, 6 Q-lab trùng trước |
| L1-F-01 | 10 trang NHÓM Đ | +1 link URL CHUẨN sau `styles.css`/`inter.css` (helmet) | 10 trang (mở riêng; 3 DC con khi mở riêng) | [ĐO-ẨN] CHUỖI A = Shell; nguồn Google; nghiêng không đổi |
| — | CLAUDE.md | quy ước Font Inter | mọi trang mới | đọc lại |

**Mã**
| Mã | Trạng thái |
|---|---|
| L1-F-01 | **Đóng** (3b ĐẠT; NHÓM Đ chờ duyệt xem tay) |
| L1-F-02 | Mở — đồng bộ chữ nghiêng |
| L1-F-04 | Mở, tuỳ chọn — tự host Google đóng đinh phiên bản **và bỏ hẳn `inter.css`** (kèm 3 quy tắc ngoài font của `styles.css`) |
| L1-F-05 (mới) | Mở — link font do helmet DC con gắn vào Shell (Integrations 400–800, Hạn ngạch/Trình đơn 400–700, `inter.css`, Remix 4.2.0); nguồn 800 của Shell trước 3b phụ thuộc thời điểm |
| L1-T-06 | Mở — làm ngay sau 3b, trước 3c |
| L1-T-03 | Theo dõi — mã chèn 865c3fd34b88 không đổi |

**3b ĐẠT [ĐO-ẨN], NHÓM Đ chờ tôi duyệt** (04:35 UTC). Trang đã hoàn tác: không có.

---

### 5.L1-T-06 — Sửa H.stable, đo chiều cao, CLAUDE.md (03/10/2026)

**Lượt trước (dừng, không phải dòng nhật ký T06):** dừng trước "ĐANG SỬA", không sửa gì. Lý do dừng (Q10, gặp chỗ phải đổi phạm vi mới làm được) [ĐỌC CODE]:
- `H.stable` có **2 định nghĩa**: `_audit/harness.html` dòng 260 (chế độ thường: chờ `fonts.ready` cũ rồi chờ hết toast, trần 6 s theo `Date.now`) và `_audit/hidden-mode.js` dòng 70 — `HM.enable()` **ghi đè** `H.stable` (chế độ ẩn: chỉ chờ hết toast, tối đa 40 × 150 ms, không chờ font). Cả hai đều trong bảng đóng băng.
- Mọi lượt đo gần đây (3a, 3a-2, 3b) là [ĐO-ẨN] → bản thật sự chạy là bản của `hidden-mode.js`. Chỉ sửa `harness.html` thì ở [ĐO-ẨN] không có tác dụng; sửa `hidden-mode.js` thì vượt phạm vi "phá băng DUY NHẤT phần H.stable trong file harness chứa nó".
- Chỗ gọi: `H.stableP` (harness dòng 314) ← `H.openShell` (dòng 316); `m3a-core.js` `MC.settle` gọi `H.stable(fr, r, 5000)`. `f3a2-face.js` chờ bằng **`FF.wait` riêng** (fonts.ready + số phần tử yên 4 lần × 250 ms + chữ ký 'loaded', trần 15 s, Shell ≥ 3.008), nhưng mở Shell qua `H.openShell` nên `H.stable` vẫn chạy trước. `user-run.js` (nút) gọi `H.openShell` → dùng `H.stable` của chế độ đang bật.
→ Quyết định 5 (4f): hướng (b) có điều chỉnh.

#### Bước 0 (lượt đầu T06; nhật ký T06 trước đó: không có dòng nào)
- **Chế độ đo** [ĐO, 08:19 UTC, khung xem của người dùng mở `_audit/harness.html`]: `visibilityState = 'visible'`, `hasFocus() = false` → **CHẾ ĐỘ THƯỜNG** (nhãn [ĐO]); Q6 áp dụng đầy đủ.
- **Storage thật, mốc "1a-T06"** (08:19:34 UTC, chỉ đọc, SHA-256 16): `bs-scheme` "light" · `bs-ui` không có · `bswebsaas:pb:v3:draft` 1.854 · b7d0db7845108a20 · `bswebsaas:pe:handoff` 1.130 · cdbfc5d68c54df1a · `__audit_layout_baseline` 8.265 · 56696db1ec94c7e3 · sessionStorage trống → **trùng mốc 1a-3b**.
- **Băm mới 41 file sản phẩm + CLAUDE.md** [ĐO, fetch `no-store`, file `.dc.html` cắt khối chèn theo chuỗi mở/đóng, SHA-256]: **42/42 trùng** bảng hash 5.L1-21a-3b (byte + 12 ký tự đầu, cả 11 file đổi ở 3b và CLAUDE.md 38.170 · d4fddf485fd1). Hash mã chèn: **865c3fd34b88** ở 28/28 file `.dc.html` (trùng mốc).
- **NFC** [ĐO, liệt kê thư mục gốc qua công cụ file]: 7 tên có dấu đã sửa ở 3b (Cài đặt - Đề xuất, Đăng nhập nhanh, Hộp duyệt, Nâng cấp, Quy trình duyệt, Trạng thái duyệt, Trình soạn) đều **NFC**, mỗi tên đúng 1 file; tổng 31 `.dc.html`.
- **Bảng đóng băng** [ĐO, cùng lệnh]: harness 83.819 · 23e212d898ef · hidden-mode 15.588 · 0c51ade00ba7 · iso-blank 101 · 5aed1a831e51 · navtest-page/child/target 562 · b4d5c354fe8f / 140 · 1af65a423f16 / 589 · 46a39304f587 · inj-probe ef51e97c8f28 · inter-metric 7b48b55f78cc · m3a-core dfe3300b71c0 · m3a-perf a75762ba786d · m3a-baseline e5ec18dc3e10 · m3a-font e61a9ab98b09 · m3a-run a16e3ffbbe6c · f3a2-face ad8b983a943e · m3b 9d888ab2f112 → **trùng**. `user-run.js` 17.806 · 65999b24216f (trùng). `m3b-v3.js`: chưa có (404).
- **Hash trước T06** (cả 2 file đều có trong bảng đóng băng → lấy từ bảng): `harness.html` 83.819 · 23e212d898efa6bc4adf07cd7226f8d4507cb85c2975aca83c0dd8ac1988cb8a · `hidden-mode.js` 15.588 · 0c51ade00ba79b7167afe83884e37b20f598a2671f56b31baa0d69b4bf97fcdb · `CLAUDE.md` 38.170 · d4fddf485fd1ae18a490320ecab91bc2e041af6c0489579305a0cdc2f0d375f6.
- File chứa `H.stable`: `_audit/harness.html` (dòng 260) và `_audit/hidden-mode.js` (dòng 70, ghi đè trong `HM.enable`).

**ĐANG SỬA T06** — file sẽ sửa/tạo: `CLAUDE.md`, `_audit/harness.html`, `_audit/hidden-mode.js`, `_audit/m3b-v3.js` (mới). _Lệch thứ tự: dòng này ghi sau khi đã sao lưu `.pre-T06` và sửa CLAUDE.md (~1 phút); không ảnh hưởng nội dung._

**Sao lưu `.pre-T06`** (công cụ chép, so byte + SHA-256 [ĐO]): `_audit/backup/CLAUDE.md.pre-T06` 38.170 · d4fddf485fd1 · `harness.html.pre-T06` 83.819 · 23e212d898ef · `hidden-mode.js.pre-T06` 15.588 · 0c51ade00ba7 → 3/3 trùng nguồn và trùng hash trước T06.

#### Bước A — CLAUDE.md
- **a)** Bỏ câu quy nguyên nhân "Lý do: `inter.css` có `size-adjust: 105%` + ascent/descent override, trang chỉ có `inter.css` rộng hơn trang dùng Google ~4–4,5 %." → thay bằng "inter.css cho chữ thường rộng hơn Google Inter khoảng 4,07–4,70 % tuỳ weight (đo bề rộng CHUỖI A cỡ 14px, lượt 3b, H.stable cũ (bản ẩn))." Nguồn [ĐỌC FILE `face-L1-21a3b-before.json`, lượt 3b Bước 1, [ĐO-ẨN]]: CHUỖI A inter.css (NHÓM Đ; 800 từ Floating Contact) / Google (Shell): 400 786,78/755,75 = **4,11 %** · 500 793,55/761,26 = **4,24 %** · 600 797,92/766,69 = **4,07 %** · 700 805,48/772,04 = **4,33 %** · 800 815,23/778,66 = **4,70 %**. 'loaded': m3b gọi `fonts.load` trước khi đo; trường U+1EC7 cùng lần đo ghi face 'loaded' của đúng nguồn ở mọi weight (NHÓM Đ "inter.css", Shell "Google ở 400–800"). Mọi weight có lần 'loaded', inter.css đều rộng hơn.
- **b)** Câu "(chữ nghiêng hiện lấy từ khối nghiêng của file này; …)" → "Chữ nghiêng giữ nguyên nguồn hiện có của từng trang: trang có inter.css dùng Inter-italic.woff2; Page Builder v3 dùng nghiêng tổng hợp từ Google Inter (không có face nghiêng)." (giữ phần "Google URL CHUẨN không có chữ nghiêng" làm lý do giữ `inter.css`). Dữ liệu khớp câu mẫu ở các trang đã đo (Cài đặt - Đề xuất, Page Editor: Inter-italic.woff2; Page Builder v3: nghiêng tổng hợp — 3a-2 Bước F); các trang có inter.css khác chưa đo riêng chữ nghiêng.
- **c)** CLAUDE.md **không có** câu nói inter.css cấp ký tự ngoài dải Google. Câu hiện có "Ký tự `→` (U+2192) nằm ngoài dải của cả hai nguồn nên vẽ bằng font dự phòng" khớp số đo (3b Bước S) → giữ, không sửa.
- Dòng đã đổi: 205–208 (§8, mục Font Inter). Hash: trước 38.170 · d4fddf485fd1 → sau **38.337 · 4f41b9ecfb0eaba2ad0c7169a12b96f44fa16175270ba420eddeac963ef12305** [ĐO, công cụ file]. Bảng hash 5.L1-21a-3b: dòng CLAUDE.md cập nhật ở T06.

T06-A XONG

#### Bước B — Sửa H.stable (L1-T-06)
**a) Đọc mã** [ĐỌC CODE, bản `.pre-T06`]
- Bản thường (`harness.html` dòng 260): đợi `fonts.ready` đọc **một lần** lúc gọi → thăm dò toast mỗi 150 ms (`setTimeout`) → trả về khi hết toast hoặc quá `max` (mặc định 6.000; tính bằng `Date.now`). Không chờ số phần tử, không chờ stylesheet.
- Bản ẩn (`hidden-mode.js` dòng 70, gán trong `HM.enable`): tối đa 40 vòng × `HM.sleep(150)`, dừng khi hết toast. Không chờ font, không chờ số phần tử, bỏ qua `max`. `HM.sleep` = hẹn giờ của shim: thuộc **cửa sổ harness** (đối tượng `HM`, nhường luồng bằng `MessageChannel` của harness), mỗi bước 50 ms tiến đồng hồ ảo của các iframe đo (`w.__HM.clock`) rồi chạy hẹn giờ đến hạn + `HM.settle`.
- Nhận diện toast: **cả hai bản cùng gọi `H.toasts`** (dòng 258: `body div` có `position:fixed`, `bottom ≤ 40`, `z-index ≥ 500`, kích thước > 1 px) → chỉ có 1 cách, không cần lấy hợp.
- Chữ ký `(fr, cb, max)`, trả về bằng `cb({ waitedMs, toastLeft, keys })` — giống nhau ở 2 bản. Khác: bản thường dùng `max` làm trần, bản ẩn bỏ qua. Chỗ gọi: `H.stableP` (`max` 6.000) ← `H.openShell` (lưu kết quả vào `fr.__stable`; không chỗ nào đọc `fr.__stable`/`waitedMs`/`toastLeft` [ĐỌC CODE, grep `_audit/`]); `m3a-core.js` `MC.settle` (`max` 5.000, bỏ qua kết quả). Bản mới giữ chữ ký/giá trị trả về; trần cố định 15 s theo yêu cầu c) → `max` không còn tác dụng (lệch so với bản thường cũ — báo mục 7).
- `f3a2-face.js`: `FF.one` mở Shell bằng `H.openShell` (→ `H.stable`) rồi `FF.wait` (fonts.ready + số phần tử yên 4 lần × 250 ms + chữ ký 'loaded' của face, trần 15 s, Shell ≥ 3.008); mở DC bằng `H.iso` + chờ `__loaded` + `FF.wait` — **không gọi `H.stable`**. 2 lần đo của một lệnh (`r1`, `r2`) **có nạp lại trang** (mỗi lần gọi `FF.one`). `user-run.js` (nút): `H.openShell` → `H.stable` của chế độ đang bật; ở chế độ ẩn nút tự nạp `hidden-mode.js` vào harness con.
- Ghi nhận QĐ 5: **khớp** — 3a, 3a-2, 3b [ĐO-ẨN] chờ bằng bản ẩn rồi `FF.wait`; nút ở chế độ thường chờ bằng bản thường.
- Vì sao có bản ẩn [ĐỌC AUDIT 5.L1-21a-2 lượt 2, dòng "Harness: … dùng `setTimeout` … trình duyệt hãm hẹn giờ ở trang ẩn (≥ 1 giây/lần) → lô dễ vượt Q5"]: **hẹn giờ của harness bị hãm ở trang ẩn**. Không có chú thích riêng trong mã cho dòng 70.
- Thử `fonts.ready` ở chế độ ẩn (theo bổ sung 3, vì lý do là hẹn giờ) [ĐO, 08:24:42 UTC; `HM.enable()` trong phiên cũ SAU khi đã chạy xong mốc b); khung xem `visible`]: Shell nạp qua `H.iso`, chờ 15,5 s bằng `HM.sleep`, rồi `await document.fonts.ready` (đọc mới) → **resolve ngay (0 ms)**, `fonts.status = 'loaded'`, 3.040 phần tử. Đạt → được sửa.
- Hash gốc trước sửa + `.pre-T06`: xem Bước 0.

**b) Mốc trước sửa** [ĐO, chế độ thường, phiên harness nạp 08:19 UTC, H.stable cũ (bản thường)]
- Shell nạp 1 lần (`H.iso` 1440×900, đếm mỗi 250 ms; t tính từ lúc tạo iframe): `2.208 ms 7` · `2.738 20` · `3.003 24` · **`3.256 768`** · `3.521 768` · `3.775 768` · `4.026 1.221` · `4.301 3.040` → **3.040 không đổi tới 15.166 ms**. Phần tử khớp toast: 0 ở mọi mốc. `fonts.status = 'loaded'` từ mốc đầu.
- **H.stable cũ (bản thường) trả về ở 3.248 ms với 768 phần tử** (chờ 2 ms, 0 toast).
- Chỉ 1 cách nhận diện toast → không loại cách nào. **N_ref = 3.040** (giây 15, trừ toast = 0); giây 13–15: không đổi. Trong khoảng dự kiến 3.000–3.040.
- RÚT GỌN bằng H.stable cũ (bản thường), `T06-old` (08:22:56 → 08:24:05 UTC): **41/41, 0 KHÔNG ĐO ĐƯỢC**, 0 sự kiện ẩn.

**c) Cách sửa** — diff [ĐỌC FILE]:
- `hidden-mode.js`: **gỡ dòng 70** (`H.stable = function (fr, cb, max) { (async function () { … HM.sleep(150) … })(); };`). Không có `HM.disable`; không có thân hàm nào khác chỉ phục vụ dòng đó. **Không thêm lối xuất** — `H.stable` dùng `HM.on`, `HM.sleep`, `HM.real`, `HM.yieldP` vốn đã có.
- `harness.html`: dòng 260 (bản thường) **thay bằng** khối mới: 4 dòng chú thích · `H.stableEls(fr)` (số phần tử trừ phần tử toast + con; tiêu chí y hệt `H.toasts`) · `H.stableLinks(fr)` (link `rel` chứa `stylesheet`, bỏ `alternate`/`disabled`; xong = sự kiện load/error, hoặc `link.sheet`, hoặc mục Resource Timing của chính iframe có `responseEnd > 0`) · `H.stable(fr, cb, max)` · `H.stable.log = []`. Mọi dòng khác giữ nguyên byte.
- `H.stable` mới: `hid = !!(window.HM && HM.on)` đọc **lúc gọi**. Vòng: đếm phần tử → link → toast; khi đủ 4 lần đếm trùng liên tiếp + 0 link chờ + 0 toast → `await fonts.ready` (đọc mới, đua với thời gian còn lại) → `fonts.status === 'loaded'` và đếm lại vẫn trùng → trả về. Giữa 2 lần kiểm: chế độ ẩn `HM.sleep(250)` (hẹn giờ shim) + bù thời gian thực bằng `HM.real` cho đủ ≥ 250 ms; chế độ thường `setTimeout(250)`. Khoảng cách và trần 15 s đo bằng `performance.now`; tài liệu đo đổi (iframe nạp lại) → đếm lại từ đầu. Hết trần → trả về với cờ "chưa ổn định" + lý do (số phần tử chưa yên k/4 · link chờ kèm href · còn toast · fonts). Hẹn giờ dự phòng của harness 18 s để không treo. Mỗi lần gọi: `H.stable.last` + 1 bản ghi vào `H.stable.log` `{t, url, mode: 'ẩn'|'thường', flag, why, n, ms}`. Không dùng số phần tử/selector/tên riêng của trang nào.
- Phiên bản 1 (duy nhất): `harness.html` **88.101 · 4f866f80bf13ec018c77fa8bc1746dcbdf1e72580fbfbabe338bffeb1b424a0f** · `hidden-mode.js` **15.293 · 6a16a8073be12a95faa6a5646f42491e65a12db1d30fad8e0f0c97b30699dc15** [ĐO, ghi trước khi chạy kiểm]. Nạp lại harness (08:25 UTC) → phiên mới.

**d) Kiểm** [ĐO, chế độ thường, 08:25–08:26 UTC, 0 sự kiện ẩn]
- `H.stable.toString()` có nguyên văn trong `harness.html` (fetch) → đúng hàm của harness.
| Lần | Trả về (ms từ lúc gọi) | Phần tử lúc trả về | Cờ | Đọc lại sau 3 s (phần tử · link · fonts) | Đạt |
|---|---|---|---|---|---|
| Shell 1 | 2.126 | 3.040 | — | 3.040 · 10 · loaded (=) | ✓ |
| Shell 2 | 2.107 | 3.040 | — | 3.040 · 10 · loaded (=) | ✓ |
| Shell 3 | 2.090 | 3.040 | — | 3.040 · 10 · loaded (=) | ✓ |
| Shell 4 | 1.925 | 3.040 | — | 3.040 · 10 · loaded (=) | ✓ |
| Shell 5 | 1.894 | 3.040 | — | 3.040 · 10 · loaded (=) | ✓ |
| Cài đặt - Đề xuất giao diện | 827 | 1.505 | — | 1.505 · 8 · loaded (=) | ✓ |
| Floating Contact Panel | 770 | 320 | — | 320 · 8 · loaded (=) | ✓ |
  Ngưỡng Shell: ≥ N_ref − 30 = 3.010. 2 trang NHÓM Đ đều có link do helmet gắn (URL CHUẨN, `inter.css`, `styles.css`). `H.openShell` tổng 4,5–4,7 s (trước: H.stable trả về ở ~3,2 s sau khi tạo iframe với 768 phần tử).
- **Kiểm chế độ ẩn** [ĐO, 08:34:40–08:34:58 UTC, cùng phiên, SAU khi xong Bước D; khung xem vẫn `visible` → lớp ẩn bật bằng `HM.enable()`, tài liệu không thật sự ẩn]: sau `HM.enable()` (`'bật'`), `H.stable.toString()` có nguyên văn trong `harness.html` → đúng hàm của harness (không bị ghi đè). `HM.disable`: không có. Shell 3 lần: trả về 2.293 / 2.036 / 2.035 ms, 3.040 phần tử, không cờ, `mode: 'ẩn'`; đọc lại sau 3 s (đo bằng `performance.now`): 3.040 · 10 link · loaded — trùng cả 3 lần. **Chưa kiểm khi tài liệu thật sự ẩn** (`visibilityState = 'hidden'`).

**e) RÚT GỌN ×2 bằng H.stable mới** [ĐO, chế độ thường, 08:26:30 → 08:28:52 UTC, 0 sự kiện ẩn]
- `T06-n1`, `T06-n2`: **41/41, 0 KHÔNG ĐO ĐƯỢC** mỗi lần; **0 phép khác giữa 2 lần** → không có phép nhiễu. `00-open` 3d3cdf927675 · `Z-b-hash-profile` 2f532b7673f7 · `Z-b-hash-settings` 8bfdac70db02 — trùng giữa 2 lần. H.stable.log: 3 bản ghi mỗi lần chạy, **0 cờ**, chế độ 'thường'.
- Lưu `_audit/regress-L1-T06.json` (lần 2, `H.summary('T06','T06-n2','T06-n1')`, chép nguyên chuỗi): 3.646 ký tự · digest b15d7648e89c6527; đọc lại `H.fileCheck` → **khớp**. Đây là mốc hồi quy mới cho 3c.
- So với `T06-old` (H.stable cũ, bản thường): **khác phiên** (sửa `harness.html` bắt buộc nạp lại harness → Q7 mất cùng phiên; chỉ tham khảo): **41/41 digest trùng**, 0 phép khác.
- So với `regress-L1-post-c04.json` (chế độ thường, H.stable cũ bản thường, tham khảo): **41/41 trùng**. So với RÚT GỌN của 3b (mã chèn 865c3fd34b88 như nhau, chỉ có 3 digest trong AUDIT): `00-open` af2037138781 → 3d3cdf927675, `Z-b-hash-profile` 7266ec838e34 → 2f532b7673f7, `Z-b-hash-settings` 87f17238171b → 8bfdac70db02 — khác; 3b đo ở [ĐO-ẨN] còn các số này ở chế độ thường, và các số chế độ thường trùng hẳn k0/post-c04 [ĐO] → khác do chế độ đo [SUY LUẬN]; không tách được phần (nếu có) do H.stable.

**f) Bảng đóng băng mới** (hash gốc, công cụ file)
| File | Cũ | Mới |
|---|---|---|
| `_audit/harness.html` | 83.819 · 23e212d898ef | **88.101 · 4f866f80bf13ec018c77fa8bc1746dcbdf1e72580fbfbabe338bffeb1b424a0f** |
| `_audit/hidden-mode.js` | 15.588 · 0c51ade00ba7 | **15.293 · 6a16a8073be12a95faa6a5646f42491e65a12db1d30fad8e0f0c97b30699dc15** |
Các file đóng băng khác: trùng hash cũ (Bước 0; kiểm lại cuối lượt). Nút `user-run.js`: không có bản H.stable riêng (dùng `H.openShell`) → không sửa.

T06-B XONG

#### Bước C — `_audit/m3b-v3.js` (L1-T-07)
- Tạo từ `m3b.js` bản 2 (m3b.js giữ nguyên byte, vẫn đóng băng). Chỉ đổi `M3.span`: span `display:inline; padding:0; border:0` nằm trong container `position:absolute; left:-10000px; top:0; white-space:nowrap; display:block` gắn vào `documentElement`; trước khi đo `await d.fonts.load('<style> <weight> 14px <family>', txt)` của chính tài liệu đo (chuỗi y như bản 2) — reject / mảng rỗng / có face chưa 'loaded' → trả "face chưa tải"; trả `[rộng, cao span inline, fontBoundingBoxAscent + fontBoundingBoxDescent]` (canvas tạo bằng `document` của tài liệu đo, `ctx.font` = cùng chuỗi). Thêm: chú thích đầu file, `ver: 3`, điều kiện nạp `M3.ver === 3` (để nạp được sau bản 2 trong cùng phiên), `M3.lastSpan` (display + số client rect của span vừa đo, phục vụ kiểm tự chứng).
- Phiên bản 1 (duy nhất): **11.307 · b68e332bb18c1bbc148922dd1c8aa683311f9a47ad8b06fb60e90679ba227ee9** [ĐO, ghi trước khi chạy kiểm].
- Kiểm tự chứng trên Shell [ĐO, chế độ thường, 08:30:04 UTC; H.stable mới trả về 3.040 phần tử, không cờ; family `Inter, "Segoe UI", sans-serif`, CHUỖI A]:
| Weight | bản 3 [rộng, cao, canvas] | (1) cao lh 1 = lh 3 | (2) client rect | (3) rộng bản 3 = bản 2 | (4) display |
|---|---|---|---|---|---|
| 400 | 755,75 · 16,8 · 17 | 16,8 = 16,8 ✓ | 1 ✓ | 755,75 = 755,75 ✓ | inline ✓ |
| 500 | 761,26 · 16,8 · 17 | ✓ | 1 ✓ | 761,26 ✓ | inline ✓ |
| 600 | 766,69 · 16,8 · 17 | ✓ | 1 ✓ | 766,69 ✓ | inline ✓ |
| 700 | 772,04 · 16,8 · 17 | ✓ | 1 ✓ | 772,04 ✓ | inline ✓ |
| 800 | 778,66 · 16,8 · 17 | ✓ | 1 ✓ | 778,66 ✓ | inline ✓ |
  Face 'loaded' ở cả 2 lần đo bề rộng (bản 2: `fonts.load` trả mảng toàn 'loaded' ngay trước khi đo). Bản 2 cùng phiên cho chiều cao 21 (line-height kế thừa, như đã ghi ở "làm rõ").
- **Bảng đóng băng thêm:** `_audit/m3b-v3.js` 11.307 · b68e332bb18c1bbc148922dd1c8aa683311f9a47ad8b06fb60e90679ba227ee9 (Bước D c).

T06-C XONG

#### Bước D — Đo lại để quan sát (không lật 3b, không làm 3c) [ĐO, chế độ thường, H.stable mới; mọi lần nạp qua iframe đo; 0 sự kiện ẩn]
Chuỗi chờ: Shell = `H.openShell` → `H.stable` (mới) → `FF.wait` (**FF.wait chưa đổi**). DC (nhóm b) = `H.iso` → `FF.wait`: **f3a2 không gọi H.stable cho DC** → "f3a2 chưa dùng H.stable mới" cho nhóm b, cờ không đọc được. 1 lệnh f3a2 = 2 lần nạp thật (`FF.one` nạp lại) → 5 lệnh = 10 lần nạp mỗi nhóm.

**a) Shell — 10 lần nạp** (08:30:15 → ~08:31:30 UTC; 5 lệnh `Da1–Da5`)
- H.stable.log: 10 bản ghi (2/lệnh), **0 cờ**, chế độ 'thường'; số phần tử lúc trả về **3.040 ở 10/10**; thời gian trả về 1.825–2.141 ms. `FF.wait`: ok 10/10, 3.038 phần tử (cách đếm riêng của f3a2).
- **Face nghiêng `inter.css` 'loaded': 2/10 lần** (lệnh 1 r1, lệnh 2 r1; 8 lần còn lại unloaded). Có 2 face nghiêng khai báo ở mọi lần (`inter.css` nạp 2 nguồn). f3a2 diff: lệnh 1 và 2 `a.khai báo`, `a.loaded` (chỉ do trạng thái face nghiêng); lệnh 3–5 `[]`.
- Nguồn 800: link URL CHUẨN có ở 10/10 lần, 7 face Google 800 khai báo ở 10/10, **không lần nào face 800 'loaded'** (Shell không có chữ 800) → f3a2 không cho biết face nào thắng. Theo m3b-v3 ở c) (lần nạp riêng) bề rộng 800 = 778,66 = Google.
- Truy face nghiêng (2 lệnh, chỉ ghi; 3 lần nạp `H.iso` + `H.stable`, không tính vào 10 lần): face nghiêng 'loaded' 2/3 lần; bộ nghe `document.fonts` 'loading' gắn sau `H.iso` không bắt được sự kiện nào; lúc `H.stable` trả về **không có phần tử nào** có `font-style` khác normal với family Inter. → **chưa chỉ ra được** phần tử/quy tắc yêu cầu face này.
- **Bất ổn: CÓ** (2/10 lần khác các lần còn lại ở face nghiêng 'loaded'). Điều kiện: H.stable mới ✓ · f3a2 dùng H.stable (theo bổ sung 6) ✓ · đọc được cờ ở 10/10 lần (0 cờ) ✓ → **"bất ổn không giải thích được bằng L1-T-06"** (FF.wait chưa đổi). Đề xuất đưa vào 3c (mục 7). Không có khác biệt ở việc có `inter.css` hay nguồn 800.
- T06-Da XONG

**b) Đăng nhập nhanh — 10 lần nạp** (5 lệnh `Db1–Db5`, ~08:31:30 → 08:32:05 UTC)
- "f3a2 chưa dùng H.stable mới" (không có bản ghi H.stable.log nào trong nhóm này; cờ không đọc được → tính mọi lần nạp). `FF.wait` ok 10/10, 129 phần tử.
- **Có stylesheet `inter.css` (face khai báo qua `inter.css`): 10/10.** Nguồn chữ thường 400/500/600/700: **Google 'loaded' ở 10/10**. Face nghiêng 'loaded': 0/10. f3a2 diff: `[]` ở 5/5 lệnh. (Số mục Resource Timing chứa `inter.css`: 2 ở 9 lần, 3 ở lệnh 5 r2 — chỉ ghi, không phải tiêu chí.)
- **Không thấy bất ổn trong 10 lần** (khác 3b sau-2, khi đó thiếu `inter.css`). Không kết luận nguyên nhân (f3a2 chưa dùng H.stable cho DC; không có nhóm đối chứng).
- T06-Db XONG

**c) Chiều cao CHUỖI A (m3b-v3), 400–800** (08:33:10 → 08:34:04 UTC; mỗi trang 1 lần nạp riêng, chờ H.stable mới rồi mới đo; 11/11 lần 0 cờ)
- **Shell và cả 10 trang NHÓM Đ: mọi weight = [rộng, cao span, canvas] y hệt** — 400 755,75 · 500 761,26 · 600 766,69 · 700 772,04 · 800 778,66; **cao span 16,8**; **canvas (ascent + descent) 17**. Không có "face chưa tải".
- → **metric dọc đồng nhất** (chiều cao span và số canvas đều trùng Shell tới 0,01 px). Chiều cao span không phải số nguyên (16,8) → không cần ghi chú độ phân giải.
- Family dùng đo: body của trang (Inter …); Trình soạn nội dung: body đầu là `Times New Roman` → dùng family body của Shell (theo quy tắc m3b). Số phần tử lúc H.stable trả về: Shell 3.040 · Cài đặt - Đề xuất 1.505 · Đăng nhập nhanh 131 · Email 253 · Floating 320 · Hộp duyệt 315 · Language Context 611 · Nâng cấp 141 · Quy trình 149 · Trạng thái duyệt 163 · Trình soạn 132.
- T06-Dc XONG

#### Cuối lượt
- **Storage thật** (08:36:12 UTC, chỉ đọc): trùng mốc "1a-T06" (`bs-scheme` "light", `bs-ui` không có, 3 khoá khác cùng độ dài + hash, sessionStorage trống); 0 iframe còn lại.
- **Băm lại** (cùng lệnh): 42/42 file (41 sản phẩm + CLAUDE.md) = bảng 5.L1-21a-3b với dòng CLAUDE.md đã cập nhật ở T06 (38.337 · 4f41b9ecfb0e); 41 file sản phẩm không đổi trong cả lượt. Bảng đóng băng: harness 4f866f80bf13 · hidden-mode 6a16a8073be1 · m3b-v3 b68e332bb18c (mới) · 14 file còn lại + `user-run.js` trùng hash cũ.
- **Mã:** L1-T-06 **đóng ở chế độ thường** (B đạt; 3 phép L1-T-06 trùng giữa 2 lần) — chế độ ẩn: đạt 3/3 khi lớp ẩn bật nhưng tài liệu không thật sự ẩn; chờ kết quả nút ở chế độ thường để xác nhận độc lập. L1-T-07 **đóng** (C đạt). **Mã mới L1-T-08**: face nghiêng `inter.css` ở Shell 'loaded' 2/10 lần nạp với H.stable mới, chưa chỉ ra phần tử yêu cầu — đề xuất xử lý ở 3c.

**T06 XONG** — 0 ✓ · A XONG · B XONG (đạt) · C XONG (đạt) · D đủ (a, b, c).

---

### 5.L1-T-08 — L1-T-06 chốt + điều tra face nghiêng (03/10/2026, chỉ đọc + ghi AUDIT.md)

#### Bước 0
- **Chế độ** [ĐO, 09:03:05 UTC]: `visibilityState = 'visible'`, `hasFocus() = false` → **chế độ thường**. `devicePixelRatio` = **1,25**.
- **Storage thật, mốc "1a-T08"** (chỉ đọc): `bs-scheme` "light" · `bs-ui` không có · `bswebsaas:pb:v3:draft` 1.854 · b7d0db7845108a20 · `bswebsaas:pe:handoff` 1.130 · cdbfc5d68c54df1a · `__audit_layout_baseline` 8.265 · 56696db1ec94c7e3 · sessionStorage trống → trùng mốc 1a-T06.
- **Băm mới** [ĐO, fetch `no-store`, cắt khối chèn]: 42/42 (41 file sản phẩm + CLAUDE.md 38.337 · 4f41b9ecfb0e) **trùng** bảng 5.L1-21a-3b (đã cập nhật T06). Bảng đóng băng: harness 4f866f80bf13 · hidden-mode 6a16a8073be1 · m3b-v3 b68e332bb18c · 14 file còn lại + `user-run.js` **trùng**. Mã chèn 865c3fd34b88.
- Harness đang mở là phiên mới (đã nạp lại sau T06): `H.stable.log` rỗng, `HM` chưa bật.

T08-0 XONG (kiểm ẩn: chưa — khung visible)

#### Trả lời câu hỏi
1. **f3a2, 2 lần đo / lệnh** [ĐỌC CODE `f3a2-face.js` dòng 76–78, 49–50]: `FF.go` gọi `FF.one` 2 lần (`r1`, `r2`); mỗi lần `FF.one` gọi `H.openShell` → `H.iso('s', …)` → **nạp lại trang**. D(a) T06: **5 lệnh (Da1–Da5) = 10 lần nạp thật = 10 lần đo** (1 lần đo / lần nạp) → 2/10 theo lần đo = **2/10 theo lần nạp**. 'loaded' ở **lệnh 1 r1 (lần nạp 1)** và **lệnh 2 r1 (lần nạp 3)** [ĐỌC FILE: kết quả D(a) trong chat lượt T06; AUDIT 5.L1-T-06 D(a)]. Status của 8 lần còn lại: kết quả T06 chỉ đếm số face 'loaded' (`italLoaded`) trên 2 face nghiêng khai báo, **không ghi status cụ thể** (unloaded/loading/error) → **chưa có dữ liệu**; f3a2 diff các lệnh 3–5 = `[]` và lệnh 1, 2 r2 = trạng thái cùng với lệnh 3–5 → 8 lần đó có cùng status với nhau [SUY RA]. Phiên harness T06 đã nạp lại → không đọc lại được.
2. **Mọi chỗ gọi H.stable** [ĐỌC CODE, grep `_audit/` trừ `backup/`, `tmp/`]: `harness.html` dòng 337 `H.stableP` → `H.stable(fr, r, 6000)` · `harness.html` dòng 339 `H.openShell` → `H.stableP(fr)` · `m3a-core.js` dòng 12 `MC.settle` → `H.stable(fr, r, 5000)`. `hidden-mode.js`: không còn (dòng 92 chỉ gọi `H.openShell`). `user-run.js`, `f3a2-face.js`, `m3b.js`, `m3b-v3.js`: chỉ qua `H.openShell`. **max lớn nhất = 6.000** (hằng số), không truyền bằng biến.
3. **Mốc t = 0** [ĐỌC FILE lệnh B(b)/B(d) T06]:
   - B(b) tiến trình: t = 0 = `fr.__t0` (gán trong `H.iso` lúc tạo iframe, trước khi fetch HTML) → 3.040 phần tử ở **4.301 ms sau khi tạo iframe**.
   - B(d) "trả về": số ms trong `H.stable.log` = từ **lúc gọi `H.stable`** (sau `H.iso` + `H.untilLoaded`), không phải từ lúc tạo iframe. Thời gian cả `H.openShell` (từ trước khi tạo iframe) = **4.505–4.723 ms** — tương thích với 3.040 ở ~4,3 s + ≥ 0,75 s chờ 4 lần kiểm [SUY LUẬN].
   - Bộ nhớ đệm: lần nạp B(b) là lần nạp Shell đầu tiên **của phiên harness đó**, nhưng không phải bộ nhớ đệm lạnh (các lượt trước đã nạp cùng file) [SUY LUẬN]. → Giải thích: **khác mốc t = 0**, không mâu thuẫn.
4. **CLAUDE.md, câu về `→` / ký tự ngoài dải** [ĐỌC FILE]:
   - `.pre-T06` dòng 207–208 và hiện tại dòng 208–209 (giống nhau): "Ký tự `→` (U+2192) nằm ngoài dải của cả hai nguồn nên vẽ bằng font dự phòng." Số đo: 3b Bước S — span "→" = span family chỉ font dự phòng (12,09 px) ở Shell và 10 trang, trước và sau; U+2192 không nằm trong unicode-range của URL CHUẨN lẫn `inter.css` [ĐO-ẨN, lượt 3b]. → **Đúng số đo.** Không có câu nào khác về ký tự ngoài dải.
   - Số % ở A(a) [ĐỌC FILE `face-L1-21a3b-before.json`; `m3b.js` dòng 18–19 `M3.span` gọi `d.fonts.load(…)` ngay trước khi đo]: mỗi weight đo **sau `fonts.load`** của chính weight đó; trường `U+1EC7` cùng lần đo ghi face **'loaded'** phủ U+1EC7 ở mọi weight: Shell "Google ở 400–800"; NHÓM Đ "inter.css" (400–700; 800 = Floating Contact, có dùng 800). Face roman `inter.css` là **một face 100–900** → 'loaded' một weight = 'loaded' mọi weight [ĐỌC FILE `inter.css`]. → **Mọi số % lấy từ lần đo có face 'loaded'** (400 4,11 · 500 4,24 · 600 4,07 · 700 4,33 · 800 4,70). Không câu nào sai số đo.
5. **devicePixelRatio = 1,25** [ĐO, 09:03:05]. [SUY LUẬN] Google Inter UPM 2048, ascent 1984, descent 494 ở 14 px: ascent 13,5625 px, descent 3,377 px. Làm tròn theo pixel thiết bị: round(13,5625 × 1,25 = 16,95) = 17; round(3,377 × 1,25 = 4,22) = 4 → (17 + 4) / 1,25 = **16,8** = số đo span (16,8). Canvas: round(13,5625) + round(3,377) = 14 + 3 = **17** = số đo. → **khớp làm tròn theo DPR.** D(c) T06 phù hợp với cùng metric dọc Inter ở mọi trang, ở độ phân giải ~1 px thiết bị; không phân biệt được `inter.css` với Google (tỷ lệ metric gần như trùng). (Ở D(c) mọi trang đã dùng Google cho chữ thường → so sánh này không cần phân biệt.)
6. **Nút** [ĐỌC CODE `user-run.js`]: dòng 64 `fr.src = 'harness.html#urun-' + Date.now()` (harness con nạp lại `harness.html` → dùng `H.stable` mới); dòng 67 `var CH = fr.contentWindow.H`; dòng 40/45 `CH.go(name, CH.suite('quick'))` (bộ RÚT GỌN mở Shell bằng `H.openShell`); dòng 95 `var tf = await CH.openShell(1440, 900)` → **đi qua `H.openShell` → `H.stable`**. `H.stable.log` của phiên T06: **không còn** (harness đã nạp lại, log rỗng). AUDIT 5.L1-T-06: Bước 0 "visible" (08:19), B(b)/B(d)/B(e)/C/D đều ghi "chế độ thường … 0 sự kiện ẩn", "Kiểm chế độ ẩn … khung xem vẫn `visible`", cuối lượt 08:36 visible → **AUDIT ghi khung visible suốt lượt T06** (mỗi bước có ghi 0 sự kiện ẩn).
7. **f3a2 mở DC** [ĐỌC CODE `f3a2-face.js` dòng 50, 52]: `fr = await H.iso('b', 1440, 900, '../' + encodeURIComponent(name)); … while (!fr.__loaded && performance.now() - tl < 20000) await FF.sleep(100);` rồi `R.wait = await FF.wait(fr, t0, isShell)` — **không gọi `H.stable`**. `FF.wait` (dòng 41–46): fonts.ready → số phần tử không đổi qua 4 lần kiểm × 250 ms → ép bố cục + chờ tập 'loaded' yên; **không chờ link stylesheet, không chờ toast**, không yêu cầu số phần tử tối thiểu cho DC. [SUY LUẬN] Có thể đo khi DC chưa nạp đủ nếu trang dừng ≥ 750 ms giữa chừng (Shell từng dừng ở 768 phần tử ~520 ms — gần ngưỡng) hoặc link do helmet gắn còn đang tải. D(c) T06 dùng `H.stableP` cho DC nên không bị ảnh hưởng; f3a2 ở 3a-2/3b/D(b) có thể bị. **Phải quyết trước 3c** (mục 5).

T08-CÂU XONG

#### L1-T-08 — a) Thao tác chạm chữ nghiêng trong f3a2 (theo thứ tự `FF.one`, Shell) [ĐỌC CODE `f3a2-face.js`]
1. Dòng 50: `H.openShell(1440, 900)` → `H.iso` + `H.untilLoaded` + `H.stable` (mới) + thử lại [C].
2. Dòng 52 → `FF.wait` (dòng 41–46): `fonts.ready`; đếm phần tử; **ép bố cục** `getBoundingClientRect()` mọi phần tử có chữ (`FF.textEls`); `loadedSig` **đọc status** (chỉ lấy face 'loaded') — đọc, không kích hoạt.
3. Dòng 53: `FF.decl` (đọc `cssRules`; stylesheet khác nguồn: `fetch(…, {cache:'force-cache'})` văn bản CSS) → `FF.faces` **đọc `f.status` lần đầu được lưu** (`R.decl`, dòng 54).
4. Dòng 57: `fetch` (force-cache) URL của face **đã 'loaded'** để băm — sau lần đọc.
5. Dòng 58: tally theo `fontStyle` của phần tử có chữ (Shell: 0 phần tử nghiêng — before.json `italic: 0`).
6. Dòng 66: với mỗi khoá tally `d.fonts.load(style + ' ' + weight + ' 14px ' + family, SAMPLE)` + span — **chỉ tạo span/`fonts.load` nghiêng nếu trang có phần tử nghiêng**; Shell không có → **không có thao tác nghiêng**.
7. Dòng 67: đọc status lần 2 (`R.fLoaded` = face chuyển sang 'loaded' trong bước 6).
→ f3a2 **không tự kích hoạt chữ nghiêng** ở Shell, trước hay sau lần đọc đầu. Lần đọc đầu nằm sau ép bố cục (bước 2), là thao tác của trang thật (chỉ vẽ phần tử đang có).
- **T_read** ước theo mã: ≥ ~0,8 s (fonts.ready + 3 × 250 ms + ≥ 2 vòng × 50 ms), trần 15 s (tính từ khi `H.openShell` trả về) + thời gian `FF.decl`. Cận trên: lấy ở b (cặp đầu, F trước).

T08-a XONG

#### Kiểm L1-T-06 ở chế độ ẩn (QĐ 2) [ĐO-ẨN, 09:06:30 → 09:07:37 UTC]
Khung xem chuyển `hidden` lúc ~09:03:28 UTC (sự kiện `H.VIS` 1791018208039; trước đó `visible` từ 1791018091852) → lượt này là lượt đầu có `hidden` → chạy kiểm ẩn. `visibilityState = 'hidden'` đầu và cuối lệnh, 0 sự kiện đổi trong lệnh.
- `HM.enable()` → 'bật'; `H.stable.toString()` có nguyên văn trong `harness.html` (fetch) → đúng hàm của harness.
- Nạp Shell 1 lần (`H.iso`, đếm trừ toast bằng `H.stableEls`, t từ lúc tạo iframe, hẹn giờ shim + `HM.real`): `1.748 ms 7` · `4.165 768` · `7.738 3.040` → 3.040 tới 14.991 ms → **N = 3.040**, ngưỡng 3.010.
- 3 lần `H.openShell`: trả về 2.207 / 2.373 / 2.253 ms, **3.040** phần tử, **không cờ**, log `mode: 'ẩn'`; đọc lại sau 3.005–3.007 ms (`performance.now`): 3.040 · 10 link · loaded = lúc trả về (cả 3 lần); tài liệu đo `hidden`.
- → **L1-T-06 đóng ở chế độ ẩn.**
- Ghi chú: trước khi bật lớp ẩn, 1 lần `H.openShell` của thí nghiệm b chạy khi trang đã ẩn nhưng H.stable ở `mode: 'thường'` (09:04:26 UTC): trả về 3.040, không cờ, 4.662 ms (hẹn giờ bị hãm) — vẫn đúng, chỉ chậm.

#### L1-T-08 — b) Thí nghiệm xen kẽ: **T08-b để lượt sau**
- Bắt đầu ~09:04:20 UTC (thứ tự F, B, F, B…; cặp đầu F trước để đo T_read). Khung xem đã `hidden` từ ~09:03:28 → **b không hợp lệ ngay từ đầu**; 0/20 lần nạp hoàn tất (F1 đang trong `FF.wait`). Dừng bằng cách gỡ iframe đo và tạm thay `H.openShell` trong bộ nhớ phiên bằng hàm ném lỗi (không sửa file) cho tới khi lệnh b dừng (lỗi `Cannot read properties of null`), rồi khôi phục hàm gốc trước kiểm ẩn. Không có số liệu nào của b được giữ.
- Ước số lệnh cho lượt sau: 1 lệnh khởi chạy + 4–6 lệnh đọc (20 lần nạp × ~6–8 s ≈ 2–3 phút), khung phải `visible` suốt thời gian đó. T_read: lấy ở b.
- c) chưa làm (cần b).
- **T08-b, T08-c THAY bởi theo dõi thụ động (T10)** (QĐ 3 "Duyệt L1-T-08").

#### Cuối lượt
- Storage thật (09:08:20 UTC, chỉ đọc): trùng mốc "1a-T08"; 0 iframe còn lại. `HM` đang bật trong phiên harness này (lượt sau nạp lại harness = phiên mới).
- Mã: L1-T-06 **đóng** (thường: B(d) T06; ẩn: kiểm trên). L1-T-08 còn mở (b, c chưa làm). Mới **L1-T-10**: f3a2 đo DC không qua `H.stable` (câu 7) — quyết trước 3c.

---

### 5.L1-T-10 — f3a2 bản 5: đo DC qua H.stable, ghi thụ động face nghiêng (03/10/2026)

#### Bước 0 [ĐO-ẨN về chế độ; băm không phụ thuộc]
- **Chế độ** (09:53:08 UTC): `visibilityState = 'hidden'` → **[ĐO-ẨN]**. `devicePixelRatio` = 1,25. Harness: phiên nạp ~09:11:00 UTC (`timeOrigin` 1791018660539), `HM` chưa bật.
- **Storage, mốc "1a-T10"** (chỉ đọc): `bs-scheme` "light" · `bs-ui` không có · `bswebsaas:pb:v3:draft` 1.854 · b7d0db7845108a20 · `bswebsaas:pe:handoff` 1.130 · cdbfc5d68c54df1a · `__audit_layout_baseline` 8.265 · 56696db1ec94c7e3 · sessionStorage trống → trùng 1a-T08.
- **Băm** [ĐO]: 42/42 trùng bảng 5.L1-21a-3b (CLAUDE.md 4f41b9ecfb0e); bảng đóng băng trùng (f3a2-face ad8b983a943e, harness 4f866f80bf13, hidden-mode 6a16a8073be1, m3b-v3 b68e332bb18c, 13 file còn lại + `user-run.js`); mã chèn 865c3fd34b88; `f3a2-v5.js`: chưa có (404).
- `H.openShell.toString()` và `H.stable.toString()` có nguyên văn trong `harness.html` → trùng.

**ĐANG LÀM T10** — file sẽ tạo: `_audit/f3a2-v5.js`, `_audit/f3a2-v5-check.json`.

T10-0 XONG

#### Phần V5
**a) Đọc mã** [ĐỌC CODE]
- Bản 4 mở DC (`f3a2-face.js` dòng 50): `fr = await H.iso('b', 1440, 900, '../' + encodeURIComponent(name)); … while (!fr.__loaded && …) await FF.sleep(100);` → dòng 52 `FF.wait`. Không gọi `H.stable`.
- `H.stable(fr, cb, max)` (`harness.html` dòng ~266): nhận **chính iframe** `fr`; đếm `fr.contentDocument` (`H.stableEls`: mọi phần tử trừ toast + con), link (`H.stableLinks`), toast (`H.toasts`: `body div` fixed, `bottom ≤ 40`, `z-index ≥ 500`, > 1 px), `fonts.ready`/`status`. Không có số cố định hay tên trang. `H.stableP(fr)` = `H.stable(fr, r, 6000)` (dòng 337). → **áp được cho iframe DC `'b'` mà không đổi trạng thái harness.** Toast lúc nạp ở 3 DC kiểm: không có (B(d) T06 Cài đặt: 0; lần chạy này: không cờ ở cả 3).
- `H.iso(id, …)` (dòng 294): `var old = document.getElementById(id); if (old) old.remove();` rồi tạo iframe mới cùng `id` → iframe cũ (vd. của lần chạy bản 4 ngay trước) **bị thay**; `H.stable` nhận đúng đối tượng `fr` do `H.iso` trả.
- Log: `H.stable.last` và `H.stable.log[]`, mỗi bản ghi `{t, url, mode, flag, why, n, ms}` — **có trường `url`** (`finish` lấy `d.location.href`).
- Số link stylesheet mong đợi (HTML nguồn; không trang nào tạo link bằng script — grep `createElement('link')` 0 kết quả): **Đăng nhập nhanh 8** (dòng 14, 17–23: admin-base, inter.css, colors, typography, shape, styles, URL CHUẨN, Remix) · **Nâng cấp nội dung 7** (14, 17–22: admin-base, inter.css, URL CHUẨN, colors, typography, shape, Remix) · **Cài đặt - Đề xuất 8** (14, 18–24: admin-base, Remix, inter.css, colors, typography, shape, styles, URL CHUẨN) · Shell 8 trong nguồn (14, 17–23) + link do helmet DC con gắn → **10** lúc chạy (thêm Google 400–700 và Remix của DC con). Dòng theo bản phục vụ (có khối chèn).
- Bản 4 ghi trạng thái face: `R.decl = [[fid, status, declI, via], …]`, `R.loadedIds`, `R.fLoaded`. **Mọi chỗ đọc `FontFace.status`**: (i) `loadedSig` trong `FF.wait` dòng 44 (đường chung, nhiều lần, chỉ lấy 'loaded'); (ii) `FF.faces` dòng 53 → `o.status` (đường chung) — **lần đọc được lưu đầu tiên**; dòng 54–59 dùng lại `faces` đã đọc (không đọc lại); (iii) dòng 67 `d.fonts.forEach(… f.status …)` + `FF.faces(d, decl)` (đường chung). Thao tác chạm chữ nghiêng ở đường DC: dòng 66 — với mỗi khoá tally có `fontStyle` italic: `d.fonts.load('italic <w> 14px <family>', SAMPLE)` + span nghiêng (Cài đặt - Đề xuất có 2 phần tử nghiêng 400); nằm **sau** (ii), **trước** (iii).
**b) Tạo `_audit/f3a2-v5.js`** — phiên bản 1 (duy nhất). T10-v5 thử 1: **18.725 B · 60693f4e826e16c147f7827bf947b10b1e0d04788df85a837f395e072ed015c4** (công cụ file = bản phục vụ, file .js không có mã chèn). Diff so bản 4 (+ dòng v5 / − dòng bản 4):
- +1–3 chú thích đầu (thay −1).
- +12 / −10: `if (window.FF && window.FF.ver === 5) return; … ver: 5` (để nạp được cạnh bản 4 trong cùng phiên).
- +42–46: thêm `FF.pas(d, w, where)` — ghi url, `timeOrigin`, `fonts.status`, số phần tử (`FF.count`), trạng thái mọi face nghiêng, đếm face không nghiêng theo trạng thái, mục Resource Timing chứa "italic", tổng số mục resource, số link stylesheet (bỏ disabled/alternate), số link có `.sheet`, mục RT của từng link. Không span, không `fonts.load`, không đọc bố cục.
- +56–58 / −49–50 (thay đổi 1): đường DC thêm `dcPre = { url, to: timeOrigin, frId, sameFrame }`, `await H.stableP(fr)`, `dcStable = { nNew, rec: bản ghi log của đúng lần gọi, sameDoc, sameFrame }`. **Đường Shell nguyên văn như bản 4** (`if (isShell) fr = await H.openShell(1440, 900);`).
- +60 / −52: `R` thêm `dcPre`, `dcStable`.
- +61 / −53 (thay đổi 2): `R.pas = [FF.pas(…'đọc 1')]` ngay trước `FF.faces`.
- +78 / −70 (thay đổi 2): `R.pas.push(FF.pas(…'đọc 2'))` ngay trước `d.fonts.forEach`.
- (i) `loadedSig` trong `FF.wait` **không** chèn ghi (nằm trong `FF.wait`, prompt chỉ cho chèn sau FF.wait; FF.wait giữ nguyên).

T10-V5 XONG

#### Phần KIỂM [ĐO, chế độ thường — khung chuyển `visible` trước khi bắt đầu; 09:55:35 → 09:56:28 UTC; 14 lần nạp, mọi lần `visible` (harness trước/sau, tài liệu đo); 0 sự kiện ẩn; `HM` không bật]
Bản 4 và v5 nạp cùng phiên (bản 4 nạp trước, giữ tham chiếu, rồi nạp v5). Thứ tự: Shell v5 ×1 lệnh → mỗi DC: bản 4 ×1 lệnh, v5 ×1 lệnh. Kết quả đầy đủ: `_audit/f3a2-v5-check.json` (tóm tắt soạn từ bộ nhớ phiên).
**a) Shell (v5, 2 lần nạp)** — đường mở/chờ Shell không đổi (diff ở trên). Không lỗi; `FF.wait` ok, 3.038; phần ghi (2) đủ trường ở 4/4 lần đọc; 10 link, 10 có `.sheet`; `fonts.status` loaded.
| Lần nạp | timeOrigin | Face nghiêng (đọc 1 = đọc 2) | RT italic [start, end] ms | Không nghiêng |
|---|---|---|---|---|
| r1 | 1791021335863,5 | unloaded, **loaded** | Inter-italic.woff2 [3.228, 3.509] | 26 loaded · 41 unloaded |
| r2 | 1791021342175,3 | unloaded, unloaded | Inter-italic.woff2 [2.845, 3.109] | 26 loaded · 41 unloaded |
→ **dao động loaded: 1/2 lần** (cmp `a.khai báo`, `a.loaded`; mọi trường khác trùng). Ghi nhận cho L1-T-08: file `Inter-italic.woff2` **có mục Resource Timing ở cả 2 lần** (kể cả lần cả 2 face 'unloaded') [ĐO].
**b) DC** — v5 ĐẠT ở 6/6 lần đo:
| DC | Bản | Lần | Log H.stable (n · cờ · ms · url) | to trước gọi = to mọi lần đọc | Link (mong đợi) · có sheet | Không nghiêng | Face nghiêng | cmp r1/r2 |
|---|---|---|---|---|---|---|---|---|
| Đăng nhập nhanh | 4 | r1, r2 | — | — | không có dữ liệu | 13 loaded · 25 unloaded (×2) | unloaded ×2 | [] |
| | v5 | r1 | 131 · — · 782 · đúng | 1791021353856,2 ✓ | 8 (8) · 8 | 13 · 25 | unloaded ×2 | |
| | v5 | r2 | 131 · — · 781 · đúng | 1791021357590,3 ✓ | 8 (8) · 8 | 13 · 25 | unloaded ×2 | [] |
| Nâng cấp nội dung | 4 | r1 | — | — | không có dữ liệu | **7 loaded · 30 unloaded** | unloaded | |
| | 4 | r2 | — | — | không có dữ liệu | 10 · 27 | unloaded | `a.khai báo`, `a.loaded` |
| | v5 | r1 | 141 · — · 776 · đúng | 1791021366646,1 ✓ | 7 (7) · 7 | 10 · 27 | unloaded | |
| | v5 | r2 | 141 · — · 766 · đúng | 1791021370304,8 ✓ | 7 (7) · 7 | 10 · 27 | unloaded | [] |
| Cài đặt - Đề xuất | 4 | r1, r2 | — | — | không có dữ liệu | 13 · 25 (×2) | loaded ×2 | [] |
| | v5 | r1 | 1.505 · — · 845 · đúng | 1791021380500,5 ✓ | 8 (8) · 8 | 13 · 25 | loaded ×2 | |
| | v5 | r2 | 1.505 · — · 802 · đúng | 1791021384417,8 ✓ | 8 (8) · 8 | 13 · 25 | loaded ×2 | [] |
- Mọi lần v5: `dcStable.nNew = 1` (đúng 1 bản ghi mới), `sameDoc`, `sameFrame` = true; log `n` ≥ số phần tử (2) − 30 (131 ≥ 99 · 141 ≥ 109 · 1.505 ≥ 1.473); không cờ; mode 'thường'. Face không nghiêng chỉ có 'loaded'/'unloaded' (0 'loading', 0 'error'). `fonts.status` loaded ở mọi lần đọc.
- **Khác biệt bản 4 vs v5 trên DC** (thay đổi có chủ đích, không tính trượt): (1) v5 có thêm `dcPre`, `dcStable`, `pas` — có chủ đích. (2) **Nâng cấp nội dung bản 4 r1: 7 face Google 'loaded' (6 G + Remix) vs 10 ở bản 4 r2 và cả 2 lần v5** → bản 4 r1 khác r2 (`a.loaded`, `a.khai báo`), v5 r1 = r2. Phù hợp với việc bản 4 đọc khi trang chưa nạp đủ font — đúng lý do của L1-T-10 [SUY LUẬN]; không có dữ liệu link của bản 4 để chứng minh. (3) Các trường còn lại (số phần tử FF.wait, face nghiêng, nguồn chữ thường) trùng.
- Face nghiêng mọi lần đọc v5: Đăng nhập nhanh unloaded ×2 (4/4 lần đọc) — có RT `Inter-italic.woff2` với start = end (từ bộ nhớ đệm) [ĐO]; Nâng cấp unloaded (4/4); Cài đặt loaded ×2 (4/4) — trang có chữ nghiêng.
**d) ĐẠT** → bảng đóng băng thêm: **`_audit/f3a2-v5.js` 18.725 · 60693f4e826e16c147f7827bf947b10b1e0d04788df85a837f395e072ed015c4** (đo face từ nay). `_audit/f3a2-v5-check.json` đã lưu.

T10-KIỂM XONG

#### Cuối lượt
- Storage thật (09:58:55 UTC, chỉ đọc): trùng mốc "1a-T10"; 0 iframe còn lại; khung `visible`.
- Mã: **L1-T-10 đóng** (v5 ĐẠT). L1-T-08: **THEO DÕI** (ghi thụ động bằng v5). Không có mã mới.

---

### 5.X3B — Chuẩn bị xem tay NHÓM Đ: danh sách đổi dòng + trang so (03/10/2026)

#### Bước 0 [ĐO, chế độ thường]
- **Chế độ** (11:08:06 UTC): `visibilityState = 'visible'`, `hasFocus() = false`, DPR 1,25; harness, 0 iframe, `HM` không bật.
- **Storage thật, mốc "1a-X3B"** (chỉ đọc, `H.sha` 16 ký tự): `bs-scheme` 5 · 99a7026172d42714 · `bswebsaas:pb:v3:draft` 1.854 · b7d0db7845108a20 · `bswebsaas:pe:handoff` 1.130 · cdbfc5d68c54df1a · `__audit_layout_baseline` 8.265 · 56696db1ec94c7e3 · `bs-ui` không có · sessionStorage trống → trùng 1a-T10.
- **Băm** (hash gốc SHA-256): 24 file tên không dấu + `CLAUDE.md` đọc byte thô bằng công cụ file; 18 file tên có dấu (công cụ file từ chối đường dẫn có dấu) băm qua `IP.cut` (`inj-probe.js` đóng băng ef51e97c8f28, cắt khối chèn theo chuỗi đánh dấu; mỗi file đúng 1 khối, hash khối 865c3fd34b88). **42/42 trùng bảng 5.L1-21a-3b** (CLAUDE.md 4f41b9ecfb0e). Bảng đóng băng **17/17 trùng** (f3a2-v5 60693f4e826e · f3a2-face ad8b983a943e · harness 4f866f80bf13 · hidden-mode 6a16a8073be1 · m3b-v3 b68e332bb18c · m3b 9d888ab2f112 · iso-blank · 3 navtest · inj-probe · inter-metric · 5 m3a-*); `user-run.js` (ngoài đóng băng) 17.806 · 65999b24216f.
- **10 file `.pre-3b`** (đường dẫn theo bảng ánh xạ ở 5.L1-21a-3b; byte thô bằng công cụ file): **10/10 trùng hash ghi lúc 3b** — cai-dat 216.830 · dfe0157d0111 · dang-nhap 64.213 · f1295d36bd58 · Email 208.303 · d2e4823bd2a8 · Floating 71.711 · 6a9070b33e5d · hop-duyet 70.382 · a7cf311b8432 · Language 102.089 · fb170794f5c8 · nang-cap 29.757 · e4936c6a0297 · quy-trinh 44.509 · 8aa00f811e7a · trang-thai 31.876 · 57beef1eaefe · trinh-soan 95.975 · f4d1ef27e31d.
- **So byte `.pre-3b` ↔ hiện tại** (cả hai qua fetch + cắt khối chèn cùng quy tắc; bản `.pre-3b` sau cắt = byte thô, hash trùng dòng trên): tìm dòng khác đầu tiên, bỏ dòng đó ở bản hiện tại, so phần còn lại. **10/10: khác đúng 1 dòng thêm = link URL CHẨN**, phần còn lại trùng, số dòng +1, byte +116 — vị trí dòng: Cài đặt 21 · Đăng nhập 19 · Email 18 · Floating 19 · Hộp duyệt 19 · Language 19 · Nâng cấp 15 · Quy trình 19 · Trạng thái 19 · Trình soạn 16 (trùng "Nhật ký sửa 3b lần 1"). → cả 10 trang dùng được cho phần SO.
- Ghi chú phương pháp: lần so đầu fetch `.pre-3b` không cắt (bản phục vụ cũng có khối chèn, +20.369 B) nên lệch từ dòng 4; chạy lại với cắt cả hai phía → kết quả trên. Không ghi file.

**ĐANG LÀM X3B** — file được tạo: `_audit/xem-3b-list.json`, `_audit/xem-3b.html`; ghi AUDIT.md.

X3B-0 XONG

#### Phần QĐ — ghi ở 4f "Duyệt L1-T-10" (kèm trả lời câu hỏi đọc mã). X3B-QĐ XONG.

#### Phần DS — KHÔNG LÀM
- **Ứng viên nguồn** [ĐỌC FILE]: `_audit/face-L1-21a3b-before.json` và `face-L1-21a3b-after.json` (công cụ ghi trong file: f3a2-face v4 ad8b983a943e + m3b v2 9d888ab2f112). Cả hai chỉ có **số đếm mức trang** (`"đổiDòng": 8 / 2 / 0 / 2 / 8 / 1 / 0 …`), **không có dữ liệu mức phần tử** (không CSS path, không số dòng trước/sau từng phần tử). AUDIT 3b cũng ghi "Đường dẫn ở phiên đo" (chỉ lưu trong bộ nhớ phiên 04:29–04:34, đã mất), duy nhất một ví dụ `section:nth-of-type(3)>div>p` 3→2 (Cài đặt - Đề xuất). Không có JSON nào khác trong `_audit/` chứa CSS path kiểu m3b (grep `nth-of-type` chỉ khớp mã nguồn m3b.js / m3b-v3.js).
- **Phương pháp đếm dòng của script nguồn** (`m3b.js` dòng 16, nguyên văn): `var lines = function (d, e) { var tops = {}; for (var c = e.firstChild; c; c = c.nextSibling) if (c.nodeType === 3 && c.nodeValue.trim()) { var r = d.createRange(); r.selectNodeContents(c); [].forEach.call(r.getClientRects(), function (q) { if (q.width > 0) tops[Math.round(q.top)] = 1; }); } return Object.keys(tops).length; };` — chỉ text node con trực tiếp, gộp theo `top` làm tròn. CSS path: dòng 15 (`tag:nth-of-type(i)` từ dưới `body`).
- Tổng dự kiến 21 (Cài đặt 8, Language 8, Đăng nhập 2, Hộp duyệt 2, Nâng cấp 1) trùng số đếm mức trang của `after.json`, nhưng không lập được danh sách phần tử. Ghi kèm: Cài đặt - Đề xuất là trang "không kết luận được" ở các phép phụ thuộc nội dung (làm rõ 3b).
- `_audit/xem-3b-list.json`: **không tạo**.

X3B-DS XONG (KHÔNG LÀM)

#### Phần SO — để lượt sau
- Không tạo `_audit/xem-3b.html`. Lý do: tiêu chí ĐẠT của SO cần định vị phần tử theo `xem-3b-list.json` (Nâng cấp 1, Đăng nhập nhanh 2), mà danh sách không có → trang so chỉ có thể kết thúc ở "đánh dấu không dùng được" hoặc CHỜ QUYẾT; cộng với khối lượng kiểm (2 trang × 2 bề rộng × 2 trạng thái + đối chứng + chặn điều hướng) không đủ trong lượt này. Khung chuyển `hidden` lúc 11:11:42 UTC (phần kiểm c cũng bắt chờ chế độ thường).
- Chưa đọc mã harness cho phần a (không trích dòng).

X3B-SO để lượt sau

#### Cuối lượt
- Storage thật (11:11:42 UTC, chỉ đọc): **trùng mốc "1a-X3B"**; 0 iframe; khung `hidden` lúc đọc. Harness có thêm `inj-probe.js` nạp trong phiên (chỉ đọc, không chạm storage).
- File đã ghi: chỉ `AUDIT.md`. Không sửa sản phẩm, CLAUDE.md, harness, hidden-mode, script đóng băng, sao lưu.
- Cần quyết: (a) cho phép **đo lại** danh sách phần tử đổi dòng (m3b đóng băng, TRƯỚC = tắt link Google, SAU = bật; lưu JSON thô) — hoặc (b) bỏ DS, làm SO không có đánh dấu.

---

### 5.X3B2 — Đo danh sách chỗ cần xem tay NHÓM Đ (03/10/2026, chỉ đọc sản phẩm)

#### Bước 0 [ĐO, chế độ thường]
- **Chế độ** (14:46:28 UTC): `visibilityState = 'visible'`, `hasFocus() = false`, DPR **1,25**; harness phiên `timeOrigin` 1791032025641, 0 iframe, `HM` không bật, `IP` chưa nạp.
- **Storage thật, mốc "1a-X3B2"** (14:46:28 UTC, chỉ đọc, `H.sha` 16): `bs-scheme` 5 · "light" · 99a7026172d42714 · `bswebsaas:pb:v3:draft` 1.854 · b7d0db7845108a20 · `bswebsaas:pe:handoff` 1.130 · cdbfc5d68c54df1a · `__audit_layout_baseline` 8.265 · 56696db1ec94c7e3 · `bs-ui` không có · sessionStorage trống → **trùng mốc 1a-X3B**.
- **Băm 42 file** (hash gốc SHA-256): 23 file tên không dấu + `CLAUDE.md` đọc byte thô bằng công cụ file (`readFileBinary`, băm trên ArrayBuffer); **18 file tên có dấu** qua `IP.cut` của `inj-probe.js` (nạp vào harness bằng fetch `no-store`, kiểm hash ef51e97c8f28 trước khi chạy; `IP.cut` fetch `cache:'no-store'`, băm trên ArrayBuffer sau khi cắt khối chèn theo chuỗi mở/đóng; 18/18: HTTP 200, đúng 1 mở / 1 đóng, khối 865c3fd34b88). **42/42 trùng bảng 5.L1-21a-3b** (CLAUDE.md 38.337 · 4f41b9ecfb0e).
- **Bảng đóng băng 17/17 trùng** (f3a2-v5 60693f4e826e · f3a2-face ad8b983a943e · harness 4f866f80bf13 · hidden-mode 6a16a8073be1 · m3b-v3 b68e332bb18c · m3b 9d888ab2f112 · iso-blank 5aed1a831e51 · navtest-page b4d5c354fe8f · navtest-child 1af65a423f16 · navtest-target 46a39304f587 · inj-probe ef51e97c8f28 · inter-metric 7b48b55f78cc · m3a-baseline e5ec18dc3e10 · m3a-core dfe3300b71c0 · m3a-font e61a9ab98b09 · m3a-perf a75762ba786d · m3a-run a16e3ffbbe6c). `x3b-lines.js` chưa có (lượt đầu) → 17. `user-run.js` (ngoài đóng băng) 17.806 · 65999b24216f.
- Ghi chú: lệnh băm 18 file lần 1 quá 6 s (công cụ báo hết giờ) → chạy lại bất đồng bộ, đọc kết quả sau (Q5).

**ĐANG LÀM X3B2** — file được tạo: `_audit/x3b-lines.js`, `_audit/x3b-lines/*.json`; ghi AUDIT.md.

X3B2-0 XONG

#### Phần SCRIPT — `_audit/x3b-lines.js`
**Đọc mã** [ĐỌC CODE]
- `H.iso` (`harness.html` dòng 294–303): chữ ký `H.iso = function (id, w, h, path, opt)` — **nhận bề rộng khung** (`fr.style.cssText = 'left:0;top:0;width:' + w + 'px;height:' + h + 'px;'`, đặt TRƯỚC khi nạp); `document.body.appendChild(fr)` và trả `fr` sau `d.write(html); d.close(); return fr;` — **giữ iframe** tới khi gọi lại `H.iso` cùng `id` (`if (old) old.remove();`). → không DỪNG.
- Đếm phần tử của log `H.stable`: `H.stableEls` (dòng 264) — `d.getElementsByTagName('*').length - S.size`, `S` = phần tử `position: fixed`, `bottom ≤ 40`, `z-index ≥ 500` (toast) và hậu duệ. Script dùng đúng hàm này; span/div tạm của script được xoá TRƯỚC mỗi lần đếm.
- Tiêu chí chọn phần tử có chữ của m3b bản 2 (9d888ab2f112): `m3b.js` gọi `FF.textEls(d)` của `f3a2-face.js` dòng 17 — phần tử trong `body`, không thuộc khối chèn, không trong `[data-audit-measure]`, có **text node con trực tiếp** khác rỗng, `getClientRects().length > 0`. Script chép nguyên văn hàm này; CSS path chép `m3b.js` dòng 15.
- Đếm dòng `m3b.js` dòng 16: chỉ text node **con trực tiếp**, gộp theo `Math.round(q.top)` → khoảng cách giữa các dòng là line-height (phụ thuộc line-height: hai dòng gộp khi `top` cách < 0,5 px); không dùng span absolute. → **THAY**: mọi text node hậu duệ (bỏ text trong `svg`), `Range.selectNodeContents(text).getClientRects()`, bỏ rect rộng 0; cùng dòng khi |tâm y₁ − tâm y₂| < 0,5 × chiều cao nhỏ hơn. **Khác phương pháp 3b.** Chữ trong `::before/::after` không đo được.

**Cách chạy**: lệnh ngoài fetch `x3b-lines.js` `no-store`, so SHA-256 đầy đủ với phiên bản đang dùng, rồi mới chèn script nội tuyến; `X3.run(key, trang, bềRộng)` chạy bất đồng bộ, kết quả ở `X3.R[key] = {json, sha}`. Mỗi bề rộng = một lần `H.iso('x3', W, 900, …)` mới (bề rộng đặt trước nạp). Lệnh ngoài ghi file bằng công cụ file, rồi fetch lại `no-store` và so `sha`. Tên file dùng tên không dấu theo bảng ánh xạ `.pre-3b` (công cụ file từ chối đường dẫn có dấu); `Email Settings`, `Floating Contact Panel`, `Language Context` viết thường gạch nối.

**Phiên bản thử**: **1** (trong giới hạn 3). `x3b-lines.js` bản 1 = **11.627 B · 9bf3bb56516d6d129f1bca2b32d5b3773856e05f5a9b832faca37b48f3936ecb**.

**Chạy thử** [ĐO, chế độ thường, 14:50 UTC]
- Nâng cấp@390-thu: không ngoại lệ; `innerWidth` 390; cổng ổn định 141 = 141 (cách 1.005 ms); số phần tử 141/141/141; đối chứng dương 5/5 ở cả 3 trạng thái (cao 100 px); SAU ≠ SAU-2: 0; CHUỖI A 863,71 / 897,67 / 863,71 → tỉ lệ 1,03932 / 1; 1 link URL CHUẨN; cờ: không. File sha 04037c5453b5 — fetch lại KHỚP.
- Đăng nhập nhanh@390-thu: tương tự (131/131/131; 5/5; 0; tỉ lệ 1,03932 / 1; cờ: không). sha 4a072614a0d5 — KHỚP.
- **Storage sau lần chạy thử đầu** (14:50:51 UTC): trùng mốc 1a-X3B2.

X3B2-SCRIPT XONG

#### ĐÓNG BĂNG
| File | Byte | SHA-256 | Dùng cho |
|---|---|---|---|
| `_audit/x3b-lines.js` (bản 1) | 11.627 | 9bf3bb56516d6d129f1bca2b32d5b3773856e05f5a9b832faca37b48f3936ecb | X3B2 phần ĐO |

Tham khảo (không phải tiêu chí): 3b ghi số đổi dòng mức trang (1440) bằng phương pháp khác và H.stable cũ bản ẩn: Cài đặt 8 · Language 8 · Đăng nhập nhanh 2 · Hộp duyệt 2 · Nâng cấp 1 · 5 trang còn lại 0. Số phần tử đổi dòng ở 1440 của lượt này (tăng + giảm, sau lọc): **trùng cả 10 trang** (8 · 8 · 2 · 2 · 1 · 0…).

X3B2-ĐÓNG BĂNG XONG

#### Phần ĐO [ĐO, chế độ thường cả 22 lần nạp — `visibilityState` 'visible' lúc bắt đầu và kết thúc mỗi lần; DPR 1,25; harness `timeOrigin` 1791032025641]
- Ước lượt: ~25 s/lần nạp × 18 lần ≈ 8 phút, chạy một lệnh khởi động (hàng đợi ad-hoc gọi `X3.run` tuần tự), đọc định kỳ → đủ trong lượt. 14:51:47 → 14:59:02 UTC.
- Hai file chạy thử dùng làm file ĐO (hash script lúc thử = hash đóng băng): `nang-cap-noi-dung@390.json`, `dang-nhap-nhanh-lien-ket-tai-khoan@390.json` chép nguyên byte bằng công cụ chép; fetch lại `no-store`: KHỚP sha.
- **Mọi lần nạp:** `innerWidth` = bề rộng đích; nạp lại 0; cổng ổn định đạt ở lần đọc đầu (2 lần đọc cách 1.005–1.012 ms bằng nhau); số phần tử SAU = TRƯỚC = SAU-2; đúng 1 link URL CHUẨN, giữ được trạng thái ở cả 3 bước; đối chứng dương 5/5 (cao 100 px) ở 3 trạng thái; SAU ≠ SAU-2: 0 phần tử; phần tử bị thay: 0; `fonts.status` loaded; `fonts.check` không false. **Cờ: 0/20.**
- **Tỉ lệ CHUỖI A** (span Inter 16px 400): SAU 863,71 · TRƯỚC 897,67 · SAU-2 863,71 ở **20/20** lần nạp → TRƯỚC/SAU **1,03932**, SAU-2/SAU **1** (trong 1,03–1,06 và 0,999–1,001).
- Ghi file: 20/20 fetch lại `no-store` **KHỚP** sha script trả về (+2 file -thu). Lệnh ghi bằng công cụ file.

**Bảng tóm tắt** — tóm tắt từ `_audit/x3b-lines/*.json` (sha = SHA-256 của nội dung file, 12 ký tự). Xếp: (1) TĂNG dòng · (2) điều khiển đổi cao > 0,5 px · (3) giảm dòng (phụ). Số dòng ghi TRƯỚC → SAU (SAU-2 = SAU ở mọi mục).

| Trang @ bề rộng | sha file | Tăng | Điều khiển | Giảm |
|---|---|---|---|---|
| Nâng cấp @390 | 04037c5453b5 | 0 | 1 | 5 |
| Nâng cấp @1440 | edb1ef50a30b | 0 | 1 | 1 |
| Trình soạn @390 | 3f60017abb20 | 0 | 0 | 0 |
| Trình soạn @1440 | cd96e1b4f4f0 | 0 | 0 | 0 |
| Cài đặt - Đề xuất @390 | aa972d3cfe1a | 0 | 0 (trang không có điều khiển nào theo tiêu chí) | 11 |
| Cài đặt - Đề xuất @1440 | 65b81d0d10c6 | 0 | 0 | 8 |
| Language Context @390 | fe69e9d5d73d | **1** | 0 | 16 |
| Language Context @1440 | 8f36890b27df | 0 | 0 | 8 |
| Đăng nhập nhanh @390 | 4a072614a0d5 | 0 | 0 | 1 |
| Đăng nhập nhanh @1440 | 0f02619ddbc7 | **1** | 0 | 1 |
| Hộp duyệt @390 | 345f63bacd3d | 0 | 0 | 0 |
| Hộp duyệt @1440 | 3b4818f3c2c9 | 0 | 0 | 2 |
| Email Settings @390 | fe62ab989a5a | 0 | 0 | 2 |
| Email Settings @1440 | 523ba8d708ee | 0 | 0 | 0 |
| Quy trình duyệt @390 | 5ac273e322cf | 0 | 0 | 1 |
| Quy trình duyệt @1440 | 927384ac5963 | 0 | 0 | 0 |
| Floating Contact @390 | 46449ccd1f2f | 0 | 0 | 2 |
| Floating Contact @1440 | d53fb323e10e | 0 | 0 | 0 |
| Trạng thái duyệt @390 | 299677a3a0ae | 0 | 0 | 1 |
| Trạng thái duyệt @1440 | a3cdd873308d | 0 | 0 | 0 |
| **Tổng** | | **2** | **2** (1 nút × 2 bề rộng) | **59** |

**(1) Tăng dòng — cần xem tay**
1. **Language Context @390** — chữ "Trình biên tập" (span thứ 2), mục **"6 · Component trực tiếp"**, khối thứ 2 dưới vùng demo trang "/ve-chung-toi": 1 → **2** dòng. Xem: chữ có bị ngắt giữa cụm hay đẩy chiều cao khối không.
2. **Đăng nhập nhanh @1440** — chữ đậm **"ẩn hẳn"** trong đoạn thứ 2 của khối thứ 2 dưới tiêu đề "Đăng nhập nhanh & liên kết tài khoản": 1 → **2** dòng (cụm đậm bị tách qua cuối dòng; đoạn chứa nó không đổi tổng số dòng).

**(2) Điều khiển đổi chiều cao**
1. **Nâng cấp @390 và @1440** — nút **"Đi tới ô đó"** (khối thứ 6 trong cột nội dung, sau danh sách việc): cao 17,6 → **19,2** px, `line-height: normal`, padding 2px/2px. Do metric "normal" của Google Inter cao hơn `inter.css`. Xem: chữ trong nút có cân giữa không, hàng chứa nút có lệch không.

**(3) Giảm dòng (ghi phụ — chữ hẹp hơn ~3,8 %, dòng ngắn lại)**
- Nâng cấp @390: "Nâng cấp bài này" 3→2 · "Đang có 420 từ · 4 mục · 1 ảnh — AI lấy chính nội dung này…" 8→6 · "Một đoạn khép bài — đặt ở cuối…" 3→2 · "— chạy ở chế độ đề xuất, giữ/hoàn tác từng đoạn" 2→1 · "Chữ cũ không mất: mỗi việc đều giữ hoặc hoàn tác được…" 3→2. @1440: "Thay chữ ở mọi đoạn — sẽ hỏi xác nhận…" 2→1.
- Cài đặt @390 (11, đều 2→1 trừ ghi khác): "Cùng một thành phần, tự đổi hình thức…" (Thanh ngôn ngữ — 3 mức) · "Dùng chung:" (Thông tin chung) · "Khuyến nghị: một tiền tệ gốc…" (Tiền tệ theo ngôn ngữ) · "Hàng ngôn ngữ website nằm ngay trên vùng soạn…" (Tab "Mẫu thư") · "Bạn nhận thư này vì đã liên hệ với anvinh.vn…" (Tab "Chữ ký") · "Phạm vi (Site · TMĐT · SaaS)…" 8→7 · "Ba khối theo đúng thứ tự người dùng nghĩ…" · "Không cần cài gì. Thư gửi ngay…" (Trang Email — bản chính) · "Thư gửi từ tên miền này dễ vào hộp thư rác." 5→4 (Trang Tổng quan) · "Nhập tài khoản và mật khẩu ứng dụng của hộp thư." 3→2 (Chia mục dài thành thẻ con) · "Hướng dẫn thêm bản ghi DKIM" (Tìm kiếm tới từng trường). @1440 (8): 7 mục cùng tên như trên (trừ "Phạm vi…", "Thư gửi từ tên miền…", "Nhập tài khoản…", "Hướng dẫn DKIM") + "Website một ngôn ngữ không thấy gì thay đổi…" 3→2.
- Language Context @390 (16): mục 1 "Không hỏi “ghi đè hay merge”…" 2→1, "Luôn mở diff theo trường…" 3→2, "P6 · Hành động AI phải nói trước hệ quả" 2→1 · tiêu đề "3 · Máy trạng thái" 3→2, "Cập nhật từ nguồn (diff theo trường)…" 4→3, "Trạng thái của component…" 5→4 · mục 4 "Inline đầy đủ — mọi tên bản địa…" 3→2 · mục 5 "Giữ nguyên vị trí cuộn…" 5→4 · mục 6 "Trang · /ve-chung-toi" 2→1 · mục 7 "1 Nhãn ngữ cảnh · 2 Điểm trạng thái 8px…" 4→3 · tiêu đề "8 · Bốn biến thể trong ngữ cảnh thật" 2→1, "Cùng một component, cùng một anatomy…" 5→4, "B · Inline + tràn — 14 ngôn ngữ…" 2→1 · mục 10 "empty #adb5bd · mt #299cdb…" 8→7 · mục 12 "Server trả localeSummary…" 4→3, "Xóa ngôn ngữ khỏi website…" 3→2. @1440 (8): "Không hỏi “ghi đè…”" 2→1 · "Luôn mở diff…" 3→2 · "Trang, Sản phẩm, Bài viết, Form, SEO, Menu…" 4→3 · mục 2 "Xóa một bản dịch là hoàn tác được trong 30 ngày…" 2→1 · "Trạng thái của component…" 2→1 · mục 5 "Mở panel từ nút hiện tại hoặc chip +N…" 3→2 · mục 10 "empty #adb5bd…" 5→4 · mục 12 "Ngôn ngữ bị tắt ở website…" 3→2.
- Đăng nhập nhanh: "Người đã có tài khoản email, khi đăng nhập bằng Google hoặc…" @390 6→5, @1440 3→2.
- Hộp duyệt @1440: trong bài mẫu "Tháng 8 vừa qua, xưởng đã hoàn tất lắp đặt…" 3→2 · "Dây chuyền này là hạng mục thứ ba…" 2→1.
- Email Settings @390: "Hộp thư gửi đi — dùng chung cho toàn hệ thống" 2→1 · "Thư kiểm tra dùng đúng hộp thư và chữ ký đang cài…" 3→2.
- Quy trình duyệt @390: dải đầu trang "Tắt quy trình không xóa bài nào…" 3→2.
- Floating Contact @390: dòng trạng thái "Đây là bản nguồn — nhập đủ và chuẩn các nhãn có chip VI…" 4→3 · nhãn nút "Tự đặt icon + liên kết" 2→1.
- Trạng thái duyệt @390: "Bài còn thiếu ảnh đại diện, mô tả SEO…" 4→3.
- Số phần tử ≥ 2 dòng ở SAU (thông tin): Nâng cấp 16/2 · Trình soạn 1/0 · Cài đặt 91/50 · Language 122/73 · Đăng nhập 8/6 · Hộp duyệt 3/5 · Email 12/4 · Quy trình 2/2 · Floating 16/1 · Trạng thái 5/1 (@390/@1440).

**Hạn chế**
- Email Settings, Đăng nhập nhanh, Floating Contact đo khi **mở riêng**; trong Shell bề rộng vùng nội dung khác → xem trên máy thật.
- Không đo cân dọc thị giác (rect của Range là vùng nội dung font, không phải nét chữ) → cân dọc phải xem bằng mắt. Chữ trong `::before/::after` không đo được.
- Thành phần đo chữ lúc mount không đo lại khi tắt link [SUY LUẬN] → TRƯỚC có thể khác bố cục thật của `.pre-3b` ở các thành phần đó.
- TRƯỚC = tắt link trong cùng lần nạp; tương đương hiển thị với `.pre-3b` không chứng minh được (chỉ tương đương về file + nguồn font qua tỉ lệ CHUỖI A).
- Đếm dòng khác phương pháp 3b; điều khiển chỉ theo tiêu chí bước 6 (Cài đặt - Đề xuất: 0 điều khiển khớp tiêu chí).

X3B2-ĐO Nâng cấp@390 XONG · X3B2-ĐO Nâng cấp@1440 XONG · X3B2-ĐO Trình soạn@390 XONG · X3B2-ĐO Trình soạn@1440 XONG · X3B2-ĐO Cài đặt - Đề xuất@390 XONG · X3B2-ĐO Cài đặt - Đề xuất@1440 XONG · X3B2-ĐO Language Context@390 XONG · X3B2-ĐO Language Context@1440 XONG · X3B2-ĐO Đăng nhập nhanh@390 XONG · X3B2-ĐO Đăng nhập nhanh@1440 XONG · X3B2-ĐO Hộp duyệt@390 XONG · X3B2-ĐO Hộp duyệt@1440 XONG · X3B2-ĐO Email Settings@390 XONG · X3B2-ĐO Email Settings@1440 XONG · X3B2-ĐO Quy trình duyệt@390 XONG · X3B2-ĐO Quy trình duyệt@1440 XONG · X3B2-ĐO Floating Contact@390 XONG · X3B2-ĐO Floating Contact@1440 XONG · X3B2-ĐO Trạng thái duyệt@390 XONG · X3B2-ĐO Trạng thái duyệt@1440 XONG

#### QĐ4 — câu hỏi mở về T10 [ĐỌC CODE + ĐỌC FILE AUDIT]
- Điều kiện nạp bản 4 (`f3a2-face.js` dòng 10): `if (window.FF) return; var FF = window.FF = { ver: 4, … };` · v5 (`f3a2-v5.js` dòng 12): `if (window.FF && window.FF.ver === 5) return; var FF = window.FF = { ver: 5, … };`
- Chỗ gọi FF bên trong: `FF.go` gọi `FF.one`, `FF.cmp` qua biến cục bộ `var FF` của IIFE (bản 4 dòng 76–78, v5 dòng 84–86); không có `window.FF` nào khác ngoài dòng 10/12 (grep). → gọi qua tham chiếu đã giữ của bản 4 thì chạy bản 4; gọi `window.FF.go` sau khi v5 nạp thì chạy v5.
- Thứ tự gọi ở KIỂM T10 (AUDIT 5.L1-T-10, Phần KIỂM): "bản 4 nạp trước, giữ tham chiếu, rồi nạp v5. Thứ tự: Shell v5 ×1 lệnh → mỗi DC: bản 4 ×1 lệnh, v5 ×1 lệnh". Lệnh nguyên văn **không được lưu** (`f3a2-v5-check.json` là tóm tắt) → không kiểm được lệnh "bản 4" gọi qua tham chiếu đã giữ hay qua `window.FF`.
- Kết luận: **KHÔNG XÁC ĐỊNH**. Nhận định "bản 4 đo DC quá sớm" hạ xuống **[PHỎNG ĐOÁN]**. Không ảnh hưởng tiêu chí ĐẠT của v5.

X3B2-QĐ4 XONG

#### Cuối lượt
- **Storage thật** (15:00:14 UTC, chỉ đọc): **trùng mốc "1a-X3B2"** (`bs-scheme` "light" · 99a7026172d42714, `bs-ui` không có, 3 khoá khác cùng độ dài + hash, sessionStorage trống). Các lần so: 14:50:51 (sau chạy thử đầu) trùng · 15:00:14 trùng. 0 iframe còn lại.
- Harness có thêm trong phiên: `IP` (inj-probe, đóng băng), `X3` (x3b-lines bản 1, đóng băng). Không chạm storage.
- File đã ghi: `_audit/x3b-lines.js` (mới, đóng băng), `_audit/x3b-lines/*.json` (20 file ĐO + 2 file -thu), `AUDIT.md`. Không sửa sản phẩm, CLAUDE.md, harness, hidden-mode, script đóng băng khác, sao lưu.
- Lệch thủ tục: tên file kết quả dùng tên không dấu (công cụ file từ chối đường dẫn có dấu); 3 file ghi đầu qua `saveFile` bị đổi `@` thành `-` rồi chuyển lại đúng tên bằng công cụ chép (fetch lại KHỚP sha). Trường `near` (tiêu đề gần nhất) rỗng ở một số trang không có thẻ heading — vị trí ghi theo CSS path + đoạn chữ.
- **Chờ duyệt:** NHÓM Đ — xem tay cả 10 trang ở 390 và 1440 (ưu tiên 2 mục TĂNG + nút "Đi tới ô đó") và các phép thử trên máy thật; phải xong trước 3c.

## Kiểm D0-2 bởi Claude Code (04/10/2026)

Bản kiểm: zip xuất 04/10 17:21, sau D0-2s và D0-2 phần 3 (Component bổ sung). Chỉ kiểm và báo — không sửa file thiết kế. Bỏ qua `_audit/` và `uploads/`. Repo đối chiếu: `tuyenht/BSWebSaaS` nhánh main `70afa11` (chỉ đọc).
Cách kiểm: Chromium 154 không giao diện, điều khiển qua DevTools Protocol. Bấm, gõ, rê chuột và nhấn phím đều là sự kiện nhập thật, không gọi `element.click()`. Giả lập `prefers-color-scheme`, `pointer:coarse` / `hover:none` và viewport điện thoại. Đã chạy 56 lượt cho 3 trang chuẩn (28 + 14 + 14 — hai trang sau không có Chữ lớn): 7 bề rộng (360 / 390 / 768 / 1024 / 1024 cảm ứng / 1240 / 1440) × sáng + tối × chữ thường + Chữ lớn. Với 9 màn đã chạm, đo ở 1240 và 390. Danh sách D0-2s được kiểm từng mục.
Bằng chứng: [ĐÃ RENDER VÀ THẤY] = đã chụp ảnh và nhìn · [ĐO BẰNG SCRIPT] = số đo của script · [SUY RA TỪ CODE] = đọc mã · [CHƯA KIỂM ĐƯỢC + lý do].
Chưa kiểm được: Safari iOS / iPadOS / Firefox / máy cảm ứng thật, xoay ngang, phóng 200%, trình đọc màn hình (chỉ có một nhân Chromium giả lập). Chữ lớn ở `Chuẩn giao diện - Component bổ sung` và `Danh sách co giãn - Đã chốt` không đo được vì hai trang không có công tắc này.

**Tổng: Chặn 0 · Cao 12 · Trung bình 23 · Thấp 21 (56 dòng).**
- D0-2s: đủ 16/16 mục và 8/8 tiêu chí "Xong khi", nhưng mục 2 (nhãn hẹp tỉnh/phường) gây cuộn ngang ở 360/390 (dòng C1).
- Kết luận của bước kiểm D0-2s về mục 11 "không cuộn ngang" là SAI: bước đó so `scrollWidth` với `innerWidth`, mà `innerWidth` đã bị nới theo chỗ tràn.

### Đã đạt (đo thật)
- Nút Sao chép mục 3 trang Component bổ sung, đủ 4 kiểu (chữ cố định · chỉ icon · ô nhập sửa được · ô nhiều dòng chỉ đọc), ở 1240 (chuột) và 390 (cảm ứng):
  - chữ đổi "Đã sao chép", `aria-label` đổi theo, sau 2,0 giây trở về "Sao chép";
  - clipboard khớp đúng từng ký tự;
  - sửa ô nhập rồi bấm chép thì ra đúng chữ VỪA GÕ;
  - bấm Space trên nút được, `role="status"` đọc đúng câu;
  - khi chặn cả hai cách chép: tự bôi đen + hiện câu hướng dẫn, Esc đóng;
  - chép được cả khi trang không được cấp quyền Clipboard. [ĐO BẰNG SCRIPT]
- `content-ui.js`:
  - `BSUI.slugify` cắt 200 rồi mới bỏ "-" hai đầu, đúng với tiếng Việt có dấu, đ/Đ, chuỗi 250 ký tự, ký tự thứ 200 là dấu cách, chuỗi toàn ký hiệu, emoji;
  - `BSUI.trapFocus`: Tab / Shift+Tab đi vòng, Esc đóng, trả focus về nút đã mở;
  - `BSUI.watchBottomBar`: đặt `--sbar-h` và `--kb`, hàm gỡ đưa cả hai về 0. Chưa màn nào gọi, đúng như README ghi.
  [ĐO BẰNG SCRIPT]
- `content-seo.js`:
  - BANDS chỉ còn `var(--token, dự phòng)`;
  - `meter()` trả đủ icon / band / lo / hi / tick ở mọi mốc biên của 6 trường: 0 ký tự luôn `empty`, vượt max luôn `over`, lo ≤ hi, tick nằm trong 0–100.
  [ĐO BẰNG SCRIPT]
- Page Editor, ô "Mô tả ngắn": gõ thật 150 / 199 / 200 / 201 / 260 ký tự. Thước có mốc 200, chữ và màu khớp `content-seo.js` (đạt ≤ 200, vàng 201–240, đỏ > 240). [ĐÃ RENDER VÀ THẤY]
- Thứ tự Tab = thứ tự nhìn ở mọi bề rộng, 0 chỗ đảo. [ĐO BẰNG SCRIPT]
- Esc đóng popover / menu / hộp thoại / trượt đáy / lịch và trả focus về nút đã mở: đạt 236/238 lượt. 2 lượt trượt là do trang rộng hơn màn ở 390 (dòng C1); Enter / Space trên đúng 2 nút đó vẫn đạt. [ĐO BẰNG SCRIPT]
- Không còn chức năng chỉ hiện khi rê chuột: 0 luật `:hover` làm hiện phần tử, 0 trình nghe `mouseenter`. [ĐO BẰNG SCRIPT]
- Chữ < 12px: 0, trừ chữ trong khung xem thu nhỏ (có chủ ý). [ĐO BẰNG SCRIPT]
- Lỗi console:
  - 0 lỗi JS ở 3 trang chuẩn và 9 màn đã chạm;
  - chỉ có cảnh báo "does not conform" lúc nạp trước khi JS gán giá trị (DOM cuối đúng) và favicon 404.
  [ĐO BẰNG SCRIPT]
- Chữ "Đã sao chép" đã thống nhất ở các nút chép thật: Hàng đợi ảnh, Block Library, Page Editor, Trình đơn, Floating Contact (toast), Quản lý Bài viết, Đăng nhập nhanh, 2 trang chuẩn. "Đã chép trình bày" ở Page Builder là cặp chép/dán định dạng, README giữ có chủ ý. [ĐO BẰNG SCRIPT]

### Lỗi — Cao (12)
1. **C1 · `Chuẩn giao diện SaaS Starter.dc.html`** · `.sx-note` (CSS dòng 90, `white-space:nowrap`), chỗ dùng ở dòng 1133 "Chờ backend: lưu tỉnh/phường đã chọn ở Thông tin chung"
   - Trang cuộn ngang ở 360 và 390, cả sáng lẫn tối: scrollWidth 396.
   - Bật Chữ lớn: scrollWidth 454, thêm `.sx-code` (dòng 89, nowrap) ở "Bảng tra cho build" (dòng 1368).
   - Toast `.tst-wrap` cũng lòi theo.
   - Cao · [ĐO BẰNG SCRIPT]
2. **C2 · trang chuẩn** · "Dải thông báo trong trang", dòng 725–728 (DS `Alert`)
   - Chữ dùng màu gốc thay cho `-text`. Tương phản nền sáng: cảnh báo 1,65:1 · thành công 2,35 · thông tin 2,70 · nguy hiểm 2,77. Nền tối: thông tin 4,43 · nguy hiểm 4,45.
   - Bảng tra (dòng 1560) ghi "-text", tức mẫu vẽ trái với chính chuẩn.
   - Cao · [ĐO BẰNG SCRIPT]
3. **C3 · trang chuẩn** · Thanh ngôn ngữ `.lb-pill` (dòng 244, cao 36px)
   - Chỉ lên 44px qua `@container (max-width:520px)` (dòng 257), tức xét theo bề rộng chứ không xét `pointer:coarse`.
   - Vùng bấm nới bằng `::after` bị `.lb-l{overflow:hidden}` (dòng 242) cắt mất.
   - Hậu quả: trên tablet cảm ứng (768 / 1024), pill và `.lb-sel` chỉ bấm được 36px.
   - Cao · [ĐO BẰNG SCRIPT]
4. **C4 · trang chuẩn** · Ô tích khóa, dòng 447 (`.sx-check.off{opacity:.6}`, dòng 102)
   - Câu lý do "Gói hiện tại chưa có bình luận." chỉ đạt 2,59:1 (sáng) / 3,25:1 (tối).
   - Đây là thông tin người dùng cần đọc, không phải nút tắt.
   - Cao · [ĐO BẰNG SCRIPT]
5. **C5 · trang chuẩn** · DS `Avatar` ở nhóm "Xem cả 8 người", dòng 1299
   - Nền tối: chữ tắt `#405189` trên `#1b2643` chỉ đạt 1,96:1.
   - Nguồn màu là design system: `_ds/…/tokens/colors.css` còn `#405189`, chưa sang G2.
   - Cao · [ĐO BẰNG SCRIPT]
6. **C6 · `Block Library - Options.dc.html`** · `copyRef` dòng 203 + nút dòng 91
   - Nút báo "Đã sao chép mã tham chiếu…" nhưng không chép gì. Thử: đặt sẵn chữ mẫu vào clipboard, bấm nút, clipboard vẫn giữ chữ mẫu.
   - Nhãn nút còn "Chép mã tham chiếu".
   - Có từ trước; lượt này chỉ đổi chữ.
   - Cao · [ĐO BẰNG SCRIPT]
7. **C7 · 8/9 màn đã chạm** · Page Editor, Quản lý Bài viết, Page Builder v3, Email Settings, Trình đơn, Floating Contact, Block Library, Language Context
   - Không có `role="status"` / `aria-live` cho toast, nên "Đã sao chép…" và mọi toast khác không được trình đọc màn hình đọc.
   - Chỉ Đăng nhập nhanh (dòng 334) làm đúng.
   - Có từ trước.
   - Cao · [ĐO BẰNG SCRIPT]
8. **C8 · `Page Editor - Options.dc.html`** · dòng 66 `id="1a"`
   - Khung gốc rộng cứng `width:1240px`, trong khi màn chị em Quản lý Bài viết (dòng 100) dùng `min(1240px,100%)`.
   - Ở 390 tràn ngang 185px.
   - Có từ trước.
   - Cao · [ĐO BẰNG SCRIPT]
9. **C9 · trang chuẩn + README** · Bảng tra dòng 1563 "Icon · có sẵn" là sai
   - Repo `app/Modules/Admin/resources/views/components/icon.blade.php` chỉ có Heroicons + 60 khóa `ri-*` không hậu tố (`ri-close`).
   - Tên lạ tự thành hình tài liệu (dòng 114).
   - Trong 74 tên icon thiết kế dùng, 50 tên không có.
   - Đúng phải là "cần sửa".
   - Cao · [ĐO BẰNG SCRIPT]
10. **C10 · trang chuẩn + README** · Bảng tra dòng 1568 "Pagination · có sẵn, per-page 10/20/50 · mode pages|cursor" là sai
    - `pagination.blade.php:1-5` chỉ có paginator / current / last: không chọn số dòng, không có kiểu cursor.
    - Danh sách lớn phải dùng cursor (CLAUDE.md §3.1).
    - Đúng phải là "cần sửa".
    - Cao · [SUY RA TỪ CODE]
11. **C11 · trang chuẩn** · Thanh hàng loạt, Xem như "Người viết", dòng 2007 (`'Gửi duyệt (' + nSel + ')'`)
    - Đếm mọi dòng đang chọn, kể cả bài của người khác và bài đã đăng / đang chờ duyệt.
    - Ở repo:
      - `EntryPolicy::submit` (`app/Modules/Cms/Policies/EntryPolicy.php:58-61`) không xét bài của ai;
      - `Index::changeStatus` / `bulkPublish` không gọi `authorize('update')`;
      - bài đã đăng vẫn bị đếm là "xong" (`PublishEntry.php:86-88`, `Index.php:390-391`).
    - Thiết kế đang chép đúng lỗ hổng quyền này của repo.
    - Owner đã chốt (04/10 tối, theo khuyến nghị): gửi duyệt = có `entries.submit` VÀ được sửa bài đó, chỉ từ Bản nháp; Claude Code làm PR riêng.
    - Cao · [SUY RA TỪ CODE]
12. **C12 · repo `app/Modules/Admin/Livewire/Entries/Index.php:457-466`** · `bulkApply`
    - Dòng đầu tiên không đủ quyền là dừng (403).
    - Các dòng trước đó đã đổi vẫn được lưu: Laravel biến lỗi thành phản hồi 403 ngay bên trong transaction của request, nên transaction không bị hủy.
    - Không có toast, ô chọn vẫn giữ.
    - Cùng mẫu này ở 5 hàm: bulkTrash, bulkRestore, bulkForceDelete, bulkDraft, bulkDuplicate. README mới ghi bulkTrash.
    - Cao · [SUY RA TỪ CODE]

### Lỗi — Trung bình (23)
1. Trang chuẩn · danh sách "Chuyển vào Thùng rác" dòng 707 · Ở 360 / 390 tên bài bị cắt còn "Khuyến m…", không có cách xem đủ tên nên người dùng không biết mình đang xóa bài nào · TB · [ĐÃ RENDER VÀ THẤY]
2. Component bổ sung · `.cp code` dòng 131 (nowrap + ellipsis) · Ở 360 / 390, giá trị cần chép (đường dẫn, bản ghi SPF) bị cắt và không có cách xem đủ · TB · [ĐÃ RENDER VÀ THẤY]
3. Component bổ sung · ô `.in` dòng 79 (`min-height:40px`) · Ô của length-field / alt ảnh cao 42px trên cảm ứng · TB · [ĐO BẰNG SCRIPT]
4. Component bổ sung · ô `.cp-in` dòng 128 / 253 · Cao 28px, nằm trong `div.cp` không phải `label` nên chạm vào phần đệm sẽ trượt · TB · [ĐO BẰNG SCRIPT]
5. Trang chuẩn · 4 nút chỉ có icon ở hàng bảng ≥ 1024 (Xem · Sửa · Nhân bản · Thùng rác), dòng 831–832 · Tên nút chỉ nằm trong `title`, mà `title` không hiện trên cảm ứng · Bảng tra dòng 1572 hứa Tooltip "bấm được trên cảm ứng" · TB · [SUY RA TỪ CODE]
6. Trang chuẩn + Danh sách co giãn · Có 2 kiểu focus cho menu: ở trang chuẩn (lọc dòng 563, panel ngôn ngữ dòng 1209–1225, lịch) focus ở lại nút mở; ở Danh sách co giãn (`.m5` dòng 305) focus vào mục đầu · TB · [ĐO BẰNG SCRIPT]
7. Trang chuẩn dòng 32 + Danh sách co giãn dòng 24 · `a:hover{color:var(--color-admin-primary)}` ở nền tối chỉ đạt 2,12:1 (đo ở Danh sách co giãn) · TB · [ĐO BẰNG SCRIPT]
8. Danh sách co giãn · `.srch5 input` dòng 214 · Chữ gợi ý không theo token, ở nền tối chỉ đạt 3,5:1 · TB · [ĐO BẰNG SCRIPT]
9. Trang chuẩn · chữ DS `Alert` · Không lớn lên khi bật Chữ lớn (12/1468 nút chữ không đổi, đều là mẫu Alert) · TB · [ĐO BẰNG SCRIPT]
10. Component bổ sung · cả trang · Không có công tắc Chữ lớn, 0 chỗ dùng `--fsp`, mọi cỡ chữ là px cố định → 7 component "Làm ngay" chưa có hành vi chữ lớn · TB · [SUY RA TỪ CODE]
11. `Email Settings.dc.html` · nút chép biến `.em-vcopy` dòng 549 / JS 1472–1474 · Chép xong chỉ đổi icon; `title` / `aria-label` vẫn là "Sao chép {token}" và trang không có vùng `role="status"` → không có phản hồi bằng chữ · TB · [ĐÃ RENDER VÀ THẤY]
12. `Quản lý Bài viết - Options.dc.html` · `.ptabs` dòng 129 · Ở 390 tràn ngang 228px. Đã có trong TODO "đồng bộ thẻ gọn dưới 1024px" · TB · [ĐO BẰNG SCRIPT]
13. `Language Context - Design Spec.dc.html` · mẫu Thanh ngôn ngữ `.lc-pill` dòng 274–283 · Ở 390 năm pill không chuyển sang "+N" / ô chọn, trang tràn 54px · TB · [ĐO BẰNG SCRIPT]
14. Repo `livewire/entries/index.blade.php:184-214, 330, 450` · Nút theo hàng và nút hàng loạt hiện theo cờ chung của website (`Index.php:573-576`), không xét quyền từng hàng → nhóm quyền author vẫn thấy "Chuyển vào Thùng rác" ở bài người khác và bị 403 · TB · [SUY RA TỪ CODE]
15. Repo `Index.php:257-271` · `saveTitle` (sửa nhanh) không giới hạn độ dài, cột `entry_translations.title` là varchar(255) → tiêu đề > 255 ký tự gây lỗi DB · TB · [SUY RA TỪ CODE]
16. Repo `Index.php:278-300` · `saveSlug` bỏ qua `max:200` của `EntryContentRules.php:26`; `routes.path` là varchar(255) · TB · [SUY RA TỪ CODE]
17. Repo `Concerns/WithMediaLibraryPicker.php:343-358` · Alt ảnh không có giới hạn ở máy chủ · TB · [SUY RA TỪ CODE]
18. `content-seo.js:69` / `content-ui.js` slugify / repo · Đường dẫn có 3 mức trần khác nhau: thước max 90 (chặn cứng 113), slugify 200, repo `max:200` → mốc chặn của thước (113) lệch với repo (200); hiện chưa ô đường dẫn nào áp mốc chặn · TB · [SUY RA TỪ CODE]
19. `content-seo.js:67,88` · Mô tả ngắn chặn cứng ở 300 (240 × 1,25), nhưng cột `entry_translations.excerpt` là varchar(255) và `EntryContentWriter.php:68-73` từ chối > 255 · TB · [SUY RA TỪ CODE]
20. Repo `forms/inbox.blade.php:35`, `forms/detail.blade.php:16`, `platform/audit/index.blade.php:25` · Hiện giờ theo UTC (`config/app.php` timezone UTC), lệch 7 tiếng · Chỉ 3 chỗ này bỏ qua SiteTime · TB · [SUY RA TỪ CODE]
21. README dòng 111–112 + bảng tra dòng 1590–1591 · Thông báo nổi / Hộp xác nhận ghi "MỚI", nhưng repo đã có lớp JS (`resources/js/sweet-toast.js`: chỉ chữ, 2,4 giây, không Hoàn tác; `resources/js/sweet-confirm.js`) → bản build phải THAY, không thêm hệ thứ hai · TB · [SUY RA TỪ CODE]
22. Bảng tra dòng 1572 "Tooltip · có sẵn · placement" · Repo `tooltip.blade.php` dùng prop `position`, chỉ hiện khi rê chuột / focus, không chạm được → phải là "cần sửa" · TB · [SUY RA TỪ CODE]
23. README "Quyền & gói" (quyền mới mặc định TẮT trừ Chủ website) · `PermissionRegistry.php:191` cho Quản trị mọi quyền website, nên quyền mới tự BẬT cho Quản trị → cần danh sách loại trừ (đổi RBAC, COUNCIL) · TB · [SUY RA TỪ CODE]

### Lỗi — Thấp (21)
1. Trang chuẩn · Chữ bị cắt "…" mà không có `title`: đường dẫn dòng 1083 (360 / 390); nhãn bậc màu dòng 653 khi bật Chữ lớn; mục cây dòng 1056 khi bật Chữ lớn ở 360 · Thấp · [ĐO BẰNG SCRIPT]
2. Danh sách co giãn · `.ttl` (line-clamp 2, dòng 41) · Cắt gọn 2 dòng, không cắt nửa chữ, nhưng không có `title` · Thấp · [ĐÃ RENDER VÀ THẤY]
3. Component bổ sung · menu mục lục đầu trang (link cao 21px) · Ở 360 / 390 vùng bấm 44px của các link liền nhau chồng lên nhau (6 cặp) · Thấp · [ĐO BẰNG SCRIPT]
4. Trang chuẩn · `.bt-row` dòng 270 (`order:3/4/5` dưới 640px) · Thứ tự nhìn ô bảng tra khác thứ tự DOM; mỗi hàng chỉ có 1 điểm focus nên Tab không bị đảo · Thấp · [SUY RA TỪ CODE]
5. Trang chuẩn · dòng 641 `<input type="color" value="{{ brand }}">` · Cảnh báo console mỗi lần nạp · Thấp · [ĐO BẰNG SCRIPT]
6. Trang chuẩn · dòng 1636 · `props.theme` thắng `localStorage['bs-scheme']` → mở riêng không theo nền đã chọn ở Shell · Thấp · [ĐO BẰNG SCRIPT]
7. Trang chuẩn · thanh "Xem thử" dính (khoảng dòng 327) · Ở 360 + Chữ lớn cao khoảng 190px, chiếm 22% màn · Thấp · [ĐÃ RENDER VÀ THẤY]
8. Trang chuẩn · ô `.sPlain` (dòng 116 / 819) cao 38px nằm trong `div` cao 44px không phải `label` → chạm mép trượt · Thấp · [ĐO BẰNG SCRIPT]
9. Trang chuẩn · khung 390 · Vùng bấm của `a.dl-tl` chồng lên chip trạng thái `button.sc.bt` (mẫu tĩnh) · Thấp · [ĐO BẰNG SCRIPT]
10. `Floating Contact Panel.dc.html:439` · Mã chết `copyLabel` còn chữ "Đã chép" · Thấp · [SUY RA TỪ CODE]
11. Component bổ sung dòng 262 · Props của copy ghi "thử Clipboard API, không được thì vùng chọn ẩn", ngược với `content-ui.js` và README (vùng chọn ẩn trước, Clipboard API sau) · Thấp · [SUY RA TỪ CODE]
12. Bảng tra dòng 1559 "Badge · có sẵn" thêm `icon` [MỚI] · Repo `badge.blade.php` chưa có icon → "cần sửa" · Thấp · [SUY RA TỪ CODE]
13. Bảng tra dòng 1562 "Card · title · actions · padding" · Repo có `title` + slot `header` / `footer`, chưa có actions / padding · Thấp · [SUY RA TỪ CODE]
14. Bảng tra dòng 1577 settings-shell "cần sửa chữ mục đang chọn → accent (E9)" · Đã lỗi thời: repo đã dùng `text-accent` (`settings-shell.blade.php:61`) · Thấp · [SUY RA TỪ CODE]
15. Bảng tra dòng 1575 / 1576 / 1578 · Props ghi sai so với file Blade: brand-logo là `context` (sidebar / topbar); rich-editor là `target · profile · value · rows`; theme-option là `axis · value · name · label · store` · Thấp · [SUY RA TỪ CODE]
16. Repo `theme-option.blade.php:13` · Ảnh thu nhỏ gắn cứng `#405189` (màu cũ) · Thấp · [SUY RA TỪ CODE]
17. Bảng tra dòng 1574 "Breadcrumb · DS chưa Blade" · Repo đã có một phần: slot `breadcrumbs` trong `page-header.blade.php:12-16` · Thấp · [SUY RA TỪ CODE]
18. Repo `pagination.blade.php:26` · `aria-label="Pagination"` tiếng Anh cứng, không qua `__()` · Thấp · [SUY RA TỪ CODE]
19. Trang chuẩn dòng 2152 · Danh sách nhóm quyền dự phòng `['…','Tác giả','Hỗ trợ','Xem']` không khớp BSCW / registry · Thấp · [SUY RA TỪ CODE]
20. README dòng 255 (múi giờ) · Không ghi dạng giá trị: SiteTime chỉ nhận `{"tz":"<IANA>"}` (`SiteTime.php:86-95`); ghi chuỗi trần sẽ bị bỏ qua mà không báo lỗi · Thấp · [SUY RA TỪ CODE]
21. Repo `lang/vi.json:550` `catalog.price_hint` · Gọi giá trống là "chế độ giới thiệu sản phẩm", trái README dòng 254 (giá trống ≠ chế độ giới thiệu) · Thấp · [SUY RA TỪ CODE]

### Đối chiếu repo — trả lời từng câu
- **`bs-admin`:**
  - Repo chưa khai. Hiện chỉ có `Blade::anonymousComponentPath(…/components, 'admin')` ở `app/Modules/Admin/Providers/AdminServiceProvider.php:84`. Namespace view `admin::` (dòng 77) là chuyện khác, không đổi.
  - Thêm `Blade::anonymousComponentPath($components, 'bs-admin')` là đủ. Đã thử: `<x-bs-admin::badge>` ra HTML giống `<x-admin::badge>`.
  - Cách Claude Code khuyến nghị: đổi một lần trong một PR (393 thẻ / 34 file + test), kèm test chặn `<x-admin::` mới. Lý do: README đang định giữ hai tên song song rồi chuyển dần, nghĩa là cùng một component có hai tên mà không có gì ngăn code mới dùng tên cũ.
  - Owner đã chốt (04/10 tối): đổi hẳn thẻ trong một PR, không giữ thẻ `<x-admin::…>` (namespace view `admin::` giữ nguyên). Claude Code làm PR #167.
- **Bảng tra ↔ `components/`:**
  - Có 18 file Blade, bảng tra có đủ cả 18; 50 dòng MỚI đều chưa có file.
  - Sai trạng thái: Icon, Pagination (Cao), Tooltip (TB), Badge (Thấp).
  - Chưa chính xác: Card, Breadcrumb, Toast / Hộp xác nhận (có JS), props của 3 dòng "Blade chưa DS", ghi chú E9.
- **Quyền theo dòng:**
  - README đúng ở cả 3 ý: `publish` chỉ xét `entries.publish` (`EntryPolicy.php:64-67`); `delete` phân biệt `.own` / `.any` (`:39-43`); người không có `entries.publish` mà có `entries.submit` bấm Đăng thì bài thành chờ duyệt (`PublishEntry.php:83-99`).
  - Kèm 3 lưu ý: bài đã đăng vẫn bị đếm là xong; bài theo quy trình duyệt bị bỏ qua; `submit` không xét bài của ai (C11).
  - `bulkTrash` có xét quyền từng dòng nhưng dừng ở dòng đầu bị từ chối (C12).
- **Giới hạn ký tự** (thiết kế max / chặn cứng → repo):

  | Trường | Thiết kế | Repo |
  |---|---|---|
  | title | 100 / 125 | `max:200`, cột 255 (sửa nhanh không giới hạn) |
  | seoTitle | 80 / 100 | sản phẩm `max:255` |
  | seoDesc | 200 / 250 | 255 |
  | excerpt | 240 / **300** | cột **255** |
  | alt | 150 / 188 | không giới hạn |
  | slug | 90 / **113** | **200** |

  Lệch ở excerpt, slug và alt (TB 17–19).
- **Múi giờ:**
  - SiteTime đọc nhóm `general`, khóa `timezone`, giá trị `{"tz":…}`, mặc định Asia/Ho_Chi_Minh (`app/Modules/Tenancy/Context/SiteTime.php:28-33,79-95`).
  - Đã dùng ở danh sách bài, hẹn giờ, Cài đặt và mail mời.
  - Chưa có chỗ ghi. 3 trang hiện giờ UTC (TB 20).
- **"Việc cho backend" trong README:**

  | Tình trạng | Mục |
  |---|---|
  | Đã có ở repo | Thùng rác cho bài / trang / sản phẩm / ảnh (SoftDeletes); khóa `rbac.role.author` (chờ chốt tên) |
  | Có một phần | Hoàn tác (đã có restore, chưa có toast kèm nút); `users.preferences` (đã có cột, chưa có khóa tz / coach) |
  | Còn thiếu thật | `--brand-N-ink` · đường ghi tỉnh / phường · bỏ qua dòng không đủ quyền và báo số · bỏ hộp xác nhận Thùng rác · "Liên hệ báo giá" ngoài website · chỗ ghi + nhãn múi giờ · `can.*` theo dòng · `bs-admin` · `help_faqs` |
  | Mâu thuẫn | "quyền mới mặc định TẮT" (TB 23) |

### Việc cần sửa — xếp theo mức độ
**Cao**
- Lượt sửa trang chuẩn kế tiếp (D0-2s2):
  - C1 cuộn ngang;
  - C3 pill 44px trên cảm ứng;
  - C4 câu lý do ô khóa;
  - C9 / C10 sửa trạng thái Icon / Pagination trong bảng tra;
  - C11 "Gửi duyệt (N)" / "Đăng (N)" chỉ đếm dòng làm được.
- Prompt DS (G2):
  - C2 Alert dùng `-text` và theo `--fsp`;
  - C5 token chữ tắt Avatar.
  Trong lúc chờ, trang chuẩn vẽ mẫu Alert bằng markup của trang.
- D0-2 phần 3, theo từng màn:
  - C6 Block Library chép thật bằng `BSUI.copy`, nhãn "Sao chép mã tham chiếu";
  - C7 vùng `role="status"` cho toast ở 8 màn;
  - C8 Page Editor `width:min(1240px,100%)`.
- Repo:
  - C12 bulk bỏ qua dòng không đủ quyền và báo số (5 hàm);
  - C11 phía repo: `submit` đòi quyền sửa bài + chỉ từ Bản nháp (đã chốt, PR riêng).

**Trung bình**
- D0-2s2: TB 1–10, 18, 19 (độ dài đường dẫn / mô tả ngắn trong `content-seo.js`), 21, 22.
- D0-2 phần 3: TB 11, 13. TB 12 đã có trong TODO.
- Repo: TB 14–17, 20, 23 (COUNCIL).

**Thấp**
- D0-2s2: Thấp 1, 3, 6, 8, 11–15, 17, 19, 20.
- Màn: Thấp 10.
- Repo: Thấp 16, 18, 21.
- Ghi nhận, không sửa: Thấp 2, 4, 5, 7, 9.

### Chuyển sang D0-3 (Bảng thuật ngữ)
- **Tên nhóm quyền `author`.**
  - Thiết kế dùng "Người viết" 23 lần / 7 file (`content-workflow.js:33`). Repo `lang/vi.json:793` `rbac.role.author` = "Tác giả" (ja 投稿者, zh 作者). Repo cũng dùng "Trả lại tác giả" (`:841`) và "người viết" trong 5 câu.
  - D0-3 chốt hai từ khác nhau:
    - tên nhóm quyền: "Người viết" — đã chốt ở D0-3 mục C.1;
    - người đứng tên một bài: "tác giả", giữ "Trả lại tác giả".
  - Repo đổi giá trị khóa theo sau.
- **Bộ từ nút AI.**
  - Hiện có hơn 20 nhãn cho vài việc: "Viết lại" 31 · "AI dịch" 16 · "Dịch bằng AI" 9 · "Viết mô tả" 8 · "Gợi ý tiêu đề" 6 · "Viết bằng AI" 4 · "Nhờ AI" 4 · "Viết cả bài bằng AI" 2 · "Mở rộng lại bằng AI" 2 · "Bổ sung / Làm mới bằng AI" · "Viết tiêu đề & mô tả" · "Tạo bằng AI" · "Gợi ý bằng AI" · "AI gợi ý" · "Viết giúp" · "Viết lại đoạn bằng AI" · "Ghi đè bằng bản AI".
  - Đề xuất theo TODO "D0-2 phần 3": "<động từ> <đối tượng>", không thêm "AI" / "bằng AI" vì icon đã nói. Cụ thể:
    - ô trống → "Gợi ý <tên trường>";
    - ô có chữ → "Viết lại";
    - bài có chữ → "Nâng cấp bài viết";
    - bài trống → "Viết mới";
    - dịch → "Dịch";
    - ảnh → "Tạo ảnh".
  - D0-3 chốt, rồi gom ở lượt riêng sau D0-3.
- Chữ nút chép đã thống nhất "Sao chép" / "Đã sao chép" — đưa vào bảng thuật ngữ.
- Tên định dạng icon (`ri-x-line` như Remix, hay `ri-x` như repo): chốt ở lượt build. Claude Code khuyến nghị dùng tên Remix đủ hậu tố, vì thiết kế đã dùng và gói `remixicon` đã có trong `package.json`.

## Kiểm D0-2s2 bởi Claude Code (05/10/2026)

Bản zip 05/10 10:58. Cách kiểm: so diff với bản 04/10 17:21 + đọc code + dựng hình thật (Chromium + DevTools Protocol) ở 360 / 390 / 768 / 1024 / 1240 / 1440 × sáng + tối × chữ thường + Chữ lớn × con trỏ chuột (giả lập) + cảm ứng.

- Mục 1–14 của D0-2s2: ĐẠT (mục cần dựng hình đều qua; mục 7, 14 và phần chữ kiểm bằng đọc). Chỉ sửa trong 8 file được phép; cú pháp JS đúng; không mất tính năng. Bước cuối CHƯA ĐẠT: README không có kết quả verifier mà zip vẫn được xuất.
- Lệch quy trình (giữ nội dung): lượt tự sửa 1 dòng và thêm 4 dòng "Việc cho backend", thêm "Ngoại lệ tạm với P1" ở bảng tra README, thêm một mục TODO — lấy từ khối AUDIT. Nội dung khớp repo ở chỗ đã kiểm, trừ `saveTitle max:255` (đúng là 200) → sửa ở D0-2s3 mục 8. Một sửa ngoài yêu cầu ở trang dòng 1757 (`'theme' in pp`) → kiểm ở D0-2s3 "Xong khi" (7).
- Lỗi mới, sửa ở D0-2s3: TB 1 (nút Sao chép đường dẫn / tên miền ở trang báo thành công khi không chép được — có từ trước, các lần kiểm trước bỏ sót) · Thấp 5 (tab "Khác ⌄" tràn ở khung 390 khi Chữ lớn; cảnh báo console `{{ brand }}`; dấu ")" thừa ở trang bổ sung; công tắc Chữ lớn đọc trạng thái hai lần; bảng README nuốt đoạn văn).
- Mang sang lượt sau (không sửa ở D0-2s3): 6 màn gọi `BSUI.copy` nhưng không chờ kết quả, nên vẫn báo "Đã sao chép" khi không chép được — Floating Contact Panel:292 · Đăng nhập nhanh & Liên kết tài khoản:469, 475 · Page Editor - Options:2026, 2719 · Trình đơn:1303 · Quản lý Bài viết - Options:2259 · Email Settings:950. Trang bổ sung bật Chữ lớn bằng `data-bs-big`, trang bằng `--fsp` — nên thống nhất một cách.
- Ghi chú: zip thiếu `assets/` (3 logo), owner đã gửi lại. Ô email ở 360 + Chữ lớn cuộn chữ bên trong ô là hành vi bình thường của ô nhập, không tính lỗi.
- Chưa kiểm được: Safari iOS, Firefox, máy thật; chuột có hover ở ≥ 1024 (Chromium không giả lập được); trình đọc màn hình thật (VoiceOver / NVDA); popover "+N" của thanh ngôn ngữ; đổi Tweak theme khi trang đang mở.

## Kiểm D0-2s3 bởi Claude Code (05/10/2026)
- Bản zip 05/10 20:05, kiểm bằng Chromium + DevTools Protocol, đối chiếu từng mục với prompt D0-2s3.
- ĐẠT cả 9 mục và "Xong khi" (1)–(6). Mục (7) đạt theo code: bấm chế độ tối / Chữ lớn ngay trong trang thì console sạch; đổi Tweak lúc trang đang mở chưa dựng hình.
  - Hàng tab 390 + Chữ lớn: 364/364px, 1 dòng, đệm tab 4px, khoảng cách trong tab 2px, đệm số đếm 3px. 390 chữ thường, 768, 1240 không đổi.
  - Sao chép: 5 tình huống, 0 lỗi. Khi bị chặn thì không báo "Đã sao chép", chữ được bôi đen, có câu chép tay.
  - Console trang chuẩn 0 lỗi, 0 cảnh báo (hết cảnh báo `{{ brand }}`).
- Admin Shell còn 9 cảnh báo khuôn chữ (có từ trước, chưa sửa ở lượt này).
- Ngoài phạm vi D0-2s3 nhưng giữ lại:
  - kéo thả cây có thả trước / sau / vào trong (đọc code thấy đúng);
  - hộp thoại từ 768px trở lên rộng theo nội dung 360–560px.
- Focus theo luật chung CŨ (trước D0-2s4), trên trang chuẩn, trang bổ sung và Admin Shell: mọi chỉ báo ≥ 3:1 với nền kề (đo được 7,1–9,7:1; vòng mặc định của ô tích 13,9–19:1). Trang Focus: bấm Tab thật trong khung 1b thì ghost, tab, liên kết, công tắc, ô chọn chỉ còn quầng ≈1,34:1, khác ảnh tĩnh; ô lỗi thành xanh.
- Lỗi sửa ở D0-2s4:
  - luật chung khác hình đã duyệt; Tab thật ở trang Focus;
  - ô lỗi (cả ô tích) thành xanh; vòng đen mặc định ở ô tích / radio;
  - khung bao sáng đôi; `.pop`; focus xóa trạng thái;
  - hộp thoại 480–767;
  - chữ dòng 1379/510;
  - chép tay đường dẫn thiếu `https://`, và toast tự đóng.
- Chưa kiểm được: chuột có hover, chế độ tương phản cao của Windows, Safari / Firefox, trình đọc màn hình thật.

## Tự rà lượt D0-3 phần 1 (05/10/2026, Claude Design)
Rà quá trình làm và câu trả lời trong chat của lượt D0-3 phần 1, đối chiếu với prompt D0-3. Lượt này chưa sửa gì: các lỗi dưới đây chờ owner cho sửa.

**A. Quy trình (làm sai hoặc làm thiếu so với bối cảnh chung)**
1. Không mở repo GitHub, không đọc `github.md`, không mở Admin Shell. Nhãn trạng thái khách liên hệ (`new · read · spam`), tên khóa nền tảng và các khóa/giá trị `lang/vi.json` ở README D0-3 chỉ chép theo prompt hoặc tự đặt, không đối chiếu repo. Trái luật "KHÔNG bịa" · Cao · [SUY RA TỪ CODE]
2. LESSONS.md chỉ đọc 3 dòng cuối (lần đọc đầu bị cắt), nên có thể bỏ sót luật ở các dòng #5 / #27 / #28 · TB · [SUY RA TỪ CODE]
3. Dừng ở phần 1 khi chưa hết lượt. Prompt chỉ cho dừng khi sắp hết lượt. Cũng chưa xuất .zip gửi Claude Code dù prompt yêu cầu · Cao · [SUY RA TỪ CODE]
4. Câu trong chat "phần 1 đã xong" nói trước khi verifier báo kết quả. README ghi "Đạt" cho tiêu chí 1–8, 10–12 mà không dựng hình, không đo · TB · [SUY RA TỪ CODE]
5. Tiêu chí 4 của prompt (mở Hộp duyệt + Quản lý Bài viết, xem Tweak nhóm quyền và huy hiệu ở sáng/tối) chưa làm · Cao · [CHƯA KIỂM ĐƯỢC — chưa mở 2 màn]
6. Chỉ gọi verifier cho trang chuẩn. Trang Component bổ sung đã sửa mà không ai kiểm · TB · [SUY RA TỪ CODE]

**B. Lỗi trong file**
7. Trang chuẩn dòng 889: ô `#sf-t` có `value` nhưng không có `onChange` / `readOnly` (React sẽ cảnh báo, ô không gõ được) → đổi sang `defaultValue` · Cao · [ĐO BẰNG SCRIPT — grep]
8. Trạng thái "Không lưu được": nút "Lưu lại" gọi `scrRetry`, toast hiện "Đang thử tải lại…" là sai việc · TB · [SUY RA TỪ CODE]
9. Trạng thái "Chỉ được xem" dùng chip "Chỉ đọc", là từ mới chưa có trong Bảng thuật ngữ (dễ lẫn với nhóm "Chỉ xem") · TB · [SUY RA TỪ CODE]
10. `#tieu-chi` tiêu chí 12, ví dụ "Sai" chứa chữ "Đặt lịch" và "Lên lịch" nằm ngoài cột "Không dùng". Vì vậy "Xong khi" 3 không đạt nguyên văn, và README D0-3 ghi "grep 0" là sai · Cao · [SUY RA TỪ CODE]
11. `BSUI.fmtCompact` với ja / zh sai: Intl compact của tiếng Nhật và tiếng Trung không rút gọn hàng nghìn, và dùng 万 / 億 (1e4 / 1e8). Cách lấy hậu tố rồi chia cho 1e3 / 1e6 ra số sai bậc · Cao · [SUY RA TỪ CODE]
12. `fmtCompact(45000, {max: 2,5e9})` ra "0Tỷ". README coi là chủ đích, nhưng giá trị thật mà hiện 0 trên biểu đồ dễ gây hiểu nhầm. Cần owner chọn: "<0,1Tỷ" hay giữ 1 chữ số có nghĩa · TB · [SUY RA TỪ CODE]
13. `fmtAgo` từ 24 giờ trở đi (và `fmtDate`) luôn ra dd/mm/yyyy cả với en, chưa "theo ngôn ngữ giao diện" như C2 yêu cầu · TB · [SUY RA TỪ CODE]
14. Số rút gọn mẫu: `aria-label` đặt trên `<span>` không có role nên trình đọc màn hình không chắc đọc. Cần `role="img"` hoặc chữ ẩn `.sr` · TB · [SUY RA TỪ CODE]
15. `content-workflow.js`: màu `c` / `bg` đổi thành chuỗi `var(…)` / `color-mix(…)`. Mới grep một mẫu nối chuỗi; chưa rà chỗ dùng làm thuộc tính SVG `fill` hoặc chỗ tính tương phản. `color-mix` cần Safari 16.2+, chưa có dự phòng · Cao · [CHƯA KIỂM ĐƯỢC — chưa mở các màn dùng BSCW.ui]
16. `content-workflow.js` đổi thêm mấy chỗ prompt không yêu cầu: "Đăng ngay" → "Đăng", "Để nháp" → "Lưu nháp"; màu Bản nháp `#495057` → `--color-admin-dark-text` (đậm hơn) · Thấp · [SUY RA TỪ CODE]
17. Mục tiêu chí E: tiêu chí 2, 3, 9, 11 và vài ví dụ "Sai" chỉ có chữ, không có "hình nhỏ dựng từ component" như prompt · TB · [SUY RA TỪ CODE]
18. Tooltip `.tp-tip` dùng bóng `rgba(0,0,0,.18)` ngoài bộ bóng chuẩn. `.tp-ok{border-left-width:1px}` là mã thừa · Thấp · [SUY RA TỪ CODE]

**C. Nội dung cần owner chốt**
19. Cột "Không dùng" có nhiều từ tự thêm ngoài danh sách của owner: Admin · Editor · Trình duyệt · Phê duyệt · Từ chối · Phục hồi · Xóa hẳn · OK · Cập nhật · Tenant · Visitor · Lead… Riêng "Cập nhật" và "Từ chối" có thể đang dùng đúng ở màn khác · TB
20. Tự đặt chữ cho `archived` của sản phẩm ("Đã gỡ khỏi website") và tên 3 nhóm quyền nền tảng (Quản trị nền tảng · Hỗ trợ khách hàng · Thanh toán) · TB
21. Bảng thuật ngữ của DS ghi "Không dùng động từ 'Gỡ'", còn prompt chốt "Gỡ khỏi website". Chưa ghi dòng LESSONS nêu điểm khác này · TB
22. Khối "Từ không dùng" của README có mục grep sẽ trúng nhầm: `site` (tên prop `site-langs`), `chép` (trúng "Chép trình bày" được phép giữ), `slug`, `media` (tên khóa, đường dẫn file) → cần ghi phạm vi "chỉ chữ hiện trên màn" · TB
23. README D0-3 mục "Ánh xạ dữ liệu" thiếu số dòng `file:dòng`. AUDIT mục "Kiểm D0-2" đã có sẵn số dòng cho 3 chỗ giờ UTC (dòng 2626) nhưng không được dùng · Thấp
24. TODO.md có 2 tiêu đề cấp 1 "Việc còn lại" (05/10 và 04/10) · Thấp

**Tổng:** 24 điểm: Cao 7 · TB 13 · Thấp 4

### Kết quả D0-3s (05/10/2026, theo chốt của Claude Code)
1. Đã sửa — đối chiếu repo f0a78d79cee1: PermissionRegistry.php, 3 migration trạng thái, `lang/vi.json` (bảng số dòng ở README D0-3). Chỗ không đối chiếu được ghi [CHƯA ĐỐI CHIẾU REPO]. **Phát hiện:** repo có 3 nhóm quyền nền tảng (`super_admin` · `support` · `billing`, PermissionRegistry.php:141–152), khác giả định "chỉ có super_admin" của chốt A2 — đã làm theo chốt, ghi lệch vào README.
2. Đã sửa — LESSONS.md đọc đủ 37 dòng đầu lượt (dòng 1–37).
3. Đã sửa — lượt D0-3s làm tiếp phần 2 sau khi sửa (theo chốt); zip không xuất ở chat này.
4. Đã sửa — README chỉ ghi "Đạt" cho tiêu chí đã đo (12); còn lại [SUY RA TỪ CODE] / [CHƯA KIỂM ĐƯỢC].
5. Đã sửa — [ĐÃ RENDER VÀ THẤY] Hộp duyệt + Quản lý Bài viết sáng/tối, console 0 lỗi.
6. Đã sửa — verifier gọi cho cả 2 trang.
7. Đã sửa — `defaultValue`.
8. Đã sửa — nút gọi `scrSave`, toast "Đã lưu thay đổi…".
9. Đã sửa — chip "Chỉ được xem"; cặp "Chỉ được xem" ≠ "Chỉ xem" có trong bảng thuật ngữ.
10. Đã sửa — luật `data-term-bad`; grep 0 [ĐO BẰNG SCRIPT].
11. Đã sửa — ja 12.3万 · zh 2.5亿 · en 1.2M [ĐO BẰNG SCRIPT].
12. Đã sửa — "<0,1Tỷ".
13. Giữ theo prompt — C2 chốt dd/mm/yyyy cho mọi ngôn ngữ; repo chưa có định dạng ngày theo ngôn ngữ.
14. Đã sửa — chữ rút gọn `aria-hidden` + chữ ẩn `.sr`.
15. Đã sửa — rà nơi đọc `c`/`bg` (3 nơi, đều chuỗi CSS); không cần dự phòng Safari < 16.2.
16. Giữ theo prompt — nhãn "Đăng", "Lưu nháp…" khớp bảng thuật ngữ đã chốt; ghi vào "Chuỗi cần dịch".
17. Đã sửa — 12 tiêu chí đều có hình nhỏ đúng/sai dựng từ component của trang.
18. Đã sửa — bóng `--shadow-pop`; bỏ `.tp-ok{border-left-width}`.
19. Chờ owner — các từ không gắn điểm nào chuyển sang "Đề xuất chờ owner" (README D0-3); bỏ "Cập nhật".
20. Đã sửa — sản phẩm `archived` = "Đã gỡ khỏi website" (giữ); `catalog.status_archived` vào Việc cho backend; `forms.status_archived`, `workflow.state_archived` "rà theo nghĩa".
21. Đã sửa — dòng LESSONS "Gỡ khỏi website" khác Bảng thuật ngữ DS.
22. Đã sửa — "Từ không dùng" chỉ cụm sai mọi ngữ cảnh; từ ngắn sang "Soát bằng mắt" kèm ví dụ sai/đúng.
23. Đã sửa — Ánh xạ dữ liệu ghi `file:dòng`.
24. Đã sửa — TODO.md gộp tiêu đề cấp 1.
Thêm khi rà: trang Component bổ sung còn nút "Tạo bằng AI" → "Tạo ảnh" (lượt phần 1 grep sót vì chỉ xét JS). (mục C không gắn bằng chứng vì là việc chờ owner chốt).

## Kiểm D0-2s4 bởi Claude Code (05/10/2026)
- Bản zip 05/10 22:04, kiểm bằng Chromium + DevTools Protocol (bấm Tab thật), sáng và tối, đối chiếu với prompt D0-2s4.
- Đúng 10 tệp được phép; không thêm màu viết thẳng hay `outline: none` mới.
- Trang Focus, khung 1b: Tab tới mọi phần tử có mẫu tĩnh thì khác 0 pixel, trừ liên kết (gạch chân mỏng lại).
- Đạt:
  - ô lỗi (cả ô tích, khung bao) giữ đỏ: #bf371a sáng, #ff7355 tối;
  - hết khung đôi ở `.sx-search` / `.cp`;
  - ô tích / radio gốc dùng vòng tách; thanh bên Shell nền đậm (chế độ sáng) ra vòng trắng; nút "Áp dụng" viết style inline vẫn thấy focus;
  - giữ trạng thái khi focus (thẻ đã chọn, ngày hôm nay, dòng kéo, mục đang chọn), trừ hai ca ghi ở dòng "Sửa ở D0-2s5";
  - hộp thoại dưới 768px tràn bề rộng;
  - chép tay đường dẫn có `https://`, toast đứng đến khi đóng;
  - console 0 lỗi (Admin Shell vẫn 9 cảnh báo khuôn chữ có từ trước);
  - hàng tab không đổi.
- Mọi chỉ báo đạt 4,55–9,7:1 với nền kề.
- Sửa ở D0-2s5: ghost 3px, "Chữ lớn" hai đường, gạch chân liên kết, viền "hôm nay", hộp thoại 768–799, màu nút đặc chế độ tối, vạch vàng thanh bên tối, chữ `&gt;` và README.
- Để lượt sau (ngoài D0-2): thân trình soạn `.pe-rte` (đo) và `.pb3-ce` (đọc code) khi focus không có chỉ báo (luật chung đang loại trừ); `.em-ce` (Email) còn vòng cũ viết thẳng `#2050a0` lệch 2px, ở tối khoảng 2:1 (đọc code); khung tự vẽ focus viết thẳng (`style-focus-within`) ở Trình đơn (3, có `.mn-rt` hai khung — đo), Quản lý Bài viết - Options (2), Page Editor - Options (1), Page Builder v3 (3), Đăng nhập nhanh (1) — đã ghi vào D0-6a, D1-2, D1-4a, D1-4b, D3-2.
- Chưa kiểm được: chuột có hover, Safari / Firefox (vòng tách trên ô tích gốc), chế độ tương phản cao của Windows, trình đọc màn hình thật.
