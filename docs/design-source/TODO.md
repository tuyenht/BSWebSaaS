# Việc còn lại — cập nhật 04/10/2026

## Đã xong 04/10/2026 — D0-2 Chuẩn giao diện (phần 1: Component)
Xem `README - D0-2 Chuẩn giao diện.md`. Kèm: L1-21a-3c (Remix 4.9.1 ở 15 file), L1-I01, L1-I02 xong; L1-I04 đóng (grep: mọi .dc.html gốc chỉ còn 4.9.1). X3B2 thanh ngôn ngữ: trang chuẩn không có nhãn chữ "Trình biên tập" — bản mới không xuống dòng.
Còn lại từ lượt này:
- D0-2 · kiểm hiển thị 360 / 1024 / 1440 / xoay ngang / 200% bằng `_audit/harness.html` (công cụ chụp quá giờ với trang ≈3.300 phần tử) · D0-3
- D0-2 · đưa trang chuẩn vào registry màn của Shell khi có D0-4b · D0-4b
- D0-2 · DS: thêm các component MỚI + sửa Button/Input/Modal/Table/Dropdown theo bảng tra · prompt DS riêng

## Lộ trình D0 — chuyển từ track L1-21a (04/10/2026)
Lượt D0 không đọc AUDIT.md — mọi chỉ dẫn cần thiết nằm ở đây. Định dạng: mã · việc · lượt.
- _audit/ · thư mục kiểm thử đóng băng: mọi lượt bỏ qua _audit/ khi grep, sửa, xoá, chuyển, tìm bản trùng; không xoá · áp mọi lượt D0–D5
- L1-21a-4 / L1-C-08 · số rút gọn cho biểu đồ (Tổng quan Shell): một hàm dùng chung trong content-ui.js; vi: K/Tr/Tỷ không khoảng trắng; en/ja/zh: Intl.NumberFormat compact; 1 chữ số thập phân chỉ khi đã rút gọn; 1 đơn vị cho cả biểu đồ theo giá trị lớn nhất; số đầy đủ qua aria-label; quy ước ghi ở trang chuẩn mục C2 + README, KHÔNG ghi CLAUDE.md · D0-3 (C2 + mẫu 7 Bảng điều khiển)
- L1-C-09 · `Nâng cấp nội dung.dc.html` và trình soạn (`Trình soạn nội dung.dc.html`, kiểm cả `Page Editor - Options`) không link styles.css: chèn link styles.css NGAY TRƯỚC link Google URL CHUẨN (Google phải đứng sau inter.css/styles.css theo CLAUDE.md), xong xem tay chữ nút/ô nhập · Nâng cấp nội dung: D0-6c; trình soạn: D1-4d mục 4
- X3B2 · nút "Đi tới ô đó" ở Nâng cấp nội dung cao 19,2 px (< 44 px vùng bấm) · D0-6c
- X3B2 · Language Context @390 "Trình biên tập" xuống 2 dòng: nếu là nút/chip/tab thì sửa để không xuống dòng (tiêu chí 8) khi đưa Thanh ngôn ngữ vào trang chuẩn · D0-2. ("ẩn hẳn" ở Đăng nhập nhanh: nếu chỉ là chữ trong đoạn văn thì không cần làm.)
- Việc cho build (Claude Code xử lý khi dựng Livewire/Tailwind, không làm ở prototype): L1-F-02 chữ nghiêng; L1-F-04 tự host font Google + bỏ inter.css; L1-F-05 link font do helmet DC con gắn vào Shell; Inter-italic.woff2 được tải dù trang không dùng (từ L1-T-08) · build
- L1-T-03 · theo dõi mã chèn của harness · đóng theo track, chỉ mở lại khi đo hồi quy font
- L1-T-08 · face nghiêng inter.css 'loaded' ở Shell (theo dõi thụ động) · đóng theo track, chỉ mở lại khi đo hồi quy font
- L1-C-06 · mở Shell với hash lạ (`#profile`) vào mục không tồn tại: không tiêu đề, không nội dung, không về Tổng quan (`applyHash` không kiểm danh sách mục) · chưa xếp lượt
- L1-C-P01 · Đăng nhập nhanh & Liên kết TK chưa nhận `accountType='platform'` (thêm nhánh platform trong panel) · chưa xếp lượt
- L1-A-P01 · panel nhúng còn hex cứng `#2050a0` cho nền nút/công tắc/viền, chưa theo màu thương hiệu (đổi sang `var(--brand-solid,…)` / `var(--color-accent,…)`) · chưa xếp lượt
- L1-20 · đổi cỡ chữ px → rem, đo lại ở 125% và 200% · chưa xếp lượt
- L1-21 / L1-21b · tách Admin Shell (dữ liệu sang `shell-data.js`, chia `renderVals()`, DC con Settings Form), dựng panel khi cần, chụp ảnh bù · chưa xếp lượt

## Đã xong 30/09/2026 — Bộ màu storefront + G2 (đợt 5)
- **Bộ màu đủ 18 vai trò** (nền, tiêu đề, chữ, chữ phụ, liên kết, nút chính + hover, nút viền, thẻ con, ô nhập, kẻ, icon, focus),
  sinh bằng OKLCH; đo tự động 4.536 cặp (18 màu thương hiệu × 4 kịch bản, kể cả ghi đè thủ công) — 0 cặp dưới ngưỡng.
- Bộ **"Màu phụ"** khi khai màu thứ hai; **Tùy chỉnh bộ màu** (nền/chữ/nút từng bộ, tự chỉnh + báo); xuất biến `--sch-*` cho storefront.
- Panel Trình bày: di chuột qua bộ màu = xem trước trên trang; khoảng cách gộp 1 hàng (+ công tắc tách); "Nâng cao" thu gọn.
- Chọn/bỏ chọn section: nút "Bỏ chọn", Esc, bấm vùng trống.
- ~~G2 slate~~ gỡ 01/10 (xám, thiếu sức sống). **Áp tông 3b Bảo Sơn Hoàng gia** cho toàn bộ 29 màn + Admin Shell (thanh bên xanh hoàng gia, vạch vàng mục đang chọn, chữ mực xanh) — lớp chuyển tiếp `admin-base.css`.
  Prompt đồng bộ DS: `DS - Prompt G2.md`.
- Còn mở: màu viết cứng trong JS của từng màn (không theo token) — đổi dần khi sửa màn; chạy PR repo theo prompt G2.

## Đã xong 30/09/2026 — Audit giao diện đa thiết bị (đợt 2)
- **Chế độ tối cho mọi màn đứng riêng / nhúng:** ~4.500 mã màu cứng (nền, viền, chữ, màu ngữ nghĩa) đổi sang token
  `var(--color-*, #cũ)` của `colors.css` — light giữ nguyên, dark lật theo `[data-bs-theme=dark]`. Shell ghi
  `localStorage['bs-scheme']`; màn đứng riêng đọc lại khi mở. Giữ nguyên màu thương hiệu bên thứ 3, sao vàng `#f7b84b`.
- **Viền ô nhập** `input/textarea/select` → `--color-border-input` (B4).
- **`admin-base.css`** (nạp ở mọi DC): vùng bấm ≥44px trên cảm ứng bằng `::after` (không đổi bố cục), chữ ô nhập
  16px trên cảm ứng (iOS không phóng to), vòng focus bắt buộc cho nút/liên kết (thắng `outline:none` inline),
  `prefers-reduced-motion`.
- **aria-label** cho ~200 nút chỉ có icon (lấy từ `title`).
- **Cài đặt › Hệ thống › Gói & mức dùng** có trong Shell (nhúng `Hạn ngạch AI và mua thêm`, prop `embedded` ẩn tiêu đề);
  nút "Mua thêm / nâng gói" ở thanh ngôn ngữ mở thẳng mục này.
- Chữ < 12px → 12px; kiểu dấu ó/ủ theo bảng thuật ngữ; bảng nhiều cột → thẻ dưới 768px (Shell, Cài đặt nội dung AI,
  Kế hoạch SEO, Cài đặt xuất bản).

Còn mở sau đợt 2 (đã xử lý thêm ở đợt 3: aria-label 24 nút còn lại, viền focus cho mọi ô nhập có viền qua
`admin-base.css`, khung bao cho ô không viền, token tối cho khung quản trị của Page Builder v3 + Block Library):
- ~~`Page Builder v3` trên tablet/mobile~~ → xong đợt 4: dưới 1024px cột trái/phải thành ngăn kéo (nút "Khối" · "Thuộc tính",
  nền mờ, Esc/bấm ra ngoài đóng, thêm khối thì tự đóng); khung xem trước tự chọn cỡ máy theo bề rộng khi mở;
  chip/segmented/tab của khung quản trị đã theo token tối.
- Khung xem trước storefront trong Page Builder cố ý giữ màu sáng (màu thương hiệu tenant, không theo theme admin).
- Màu chữ ghép động trong JS (`'color:' + x`) chưa đổi token — rà khi sửa từng màn.

Chi tiết đợt 2:
- `Page Builder v3`, `Block Library` chưa đổi token tối (khung xem trước là storefront, cần luật riêng); Admin Shell đã có bảng màu tối riêng.
- 23 nút chỉ icon chưa có `title` → chưa có aria-label (nhiều nhất ở Page Builder v3).
- `outline:none` trên ô nhập: chỉ an toàn khi khung ngoài có `:focus-within` — rà từng chỗ.
- Màu chữ ghép động trong JS (`'color:' + x`) chưa đổi token — rà khi sửa từng màn.

## Đã xong 30/09/2026 — D0-1 Dọn & đồng bộ project
Xem `README - D0-1 Dọn project.md`. Còn lại từ lượt này:
- ~~Viền ô nhập `#e9ebec`~~ → xong ở đợt 2.
- `outline:none` còn trong Admin Shell (8), Email Settings (12), Quản lý Bài viết (15)… — rà từng chỗ đã có vòng thay thế (`:focus-within`) chưa.
- `Page Builder v3` còn `anvinh.baoson.net` trong khung xem trước — track Builder v3 riêng, không sửa ở D0-1.
- ~~Mục Cài đặt › Gói & mức dùng chưa có trong Shell~~ → xong ở đợt 2.


## Đã xong phiên này (audit hội đồng, F1–F5)
- **Một nguồn sự thật cho gói và hạn ngạch** — `ai-quota.js` (`window.BSAI`) nay giữ **bảng gói đầy đủ**:
  free / basic / pro / ent × `{words, images, variants, price, fit}` + `plan()` · `plans()` · `variants()` ·
  `enough()` · `enoughImages()` · `cost()` · `imageCost()` · `setPlan()` · `setUsage30()`.
  Sửa giá hay hạn ngạch chỉ sửa ở đây.
- **Bỏ nguồn trùng:** `Hạn ngạch AI và mua thêm.dc.html` (màn chuẩn §7a-bis) trước đây tự khai `PLANS` 4 gói
  → nay đọc từ helper, mảng cũ giữ lại làm `PLANS_FALLBACK` cho khung hình đầu.
- **Hết hardcode `basic`:** `Email Settings`, `Floating Contact Panel`,
  `Page Editor - Options`, `Trợ lý viết nội dung AI v2 - Options`, `AI Writer Panel v2`,
  `Sửa đoạn bôi đen bằng AI` đều đọc `plan().words` (và `variants()` cho số phương án).
  Khách gói Pro nay thấy đúng 200.000 từ, không còn kẹt ở 60.000 → chip và cổng chặn tính đúng.
- **Sổ ảnh vào helper:** `Hàng đợi ảnh` lấy `variants()` thay vì hardcode 3 phương án, `imageQuota.limit`
  theo `plan().images`, tooltip thêm câu "đủ khoảng N ảnh dùng được".
- **Câu chữ:** "ảnh thật không tốn lượt AI" → "không tốn lượt tạo ảnh nào"; "gán một lượt" → "gán một lần"
  (tránh lẫn với đơn vị hạn ngạch).
- **CLAUDE.md:** sửa §7 (đơn vị theo sổ, thêm luật "so chi phí thực trước khi cho bấm"), §9, §10 về đúng
  đơn vị *từ AI*; §7a-bis thêm điều khoản `ai-quota.js` là nguồn duy nhất của bảng gói.
- **Dọn bản cũ sai luật** (tính 1 lượt/bài, chip "Còn N/M bài AI" — trái §7a): xoá `AI Writer Panel.dc.html`,
  `AI Writer Panel v1.dc.html`, `AI Writer.dc.html`, `Trợ lý viết nội dung AI - Options.dc.html`,
  `Trợ lý viết nội dung AI v1 - Options.dc.html`. Không còn tham chiếu treo; bản đang dùng là **v2**.
- **Xóa bản trùng `Floating Contact - Settings.dc.html`** — cùng chức năng với `Floating Contact Panel`
  nhưng thiếu hợp đồng nhúng §13 (`embedded` / `onDirty` / `bs-embed-cmd`); bản đang nhúng trong Admin Shell
  là `Floating Contact Panel`.
- **Xoá `Sửa đoạn bôi đen bằng AI.dc.html`** — `Page Editor - Options` nay đã có đủ cả ba thứ từng chỉ
  có ở màn đó: nhiều phương án theo `variants()` của gói, xem khác biệt từng từ (`aiDiffParts`, nút
  "So với bản gốc"), và hoàn tác riêng cho từng lần viết lại. Giữ hai màn song song chỉ gây lệch nhau.

## Đã xong 02/08/2026 — Trình đơn
- **Nối `Trình đơn` vào Admin Shell** (§13): mục "Trình đơn" trước đây rơi vào màn trống.
  Nay Shell dựng `<dc-import name="Trình đơn" embedded embed-id="menus" on-dirty>`; panel ẩn header +
  thanh Lưu riêng, Shell hiện chip "Chưa lưu" + Bỏ thay đổi / Lưu thay đổi ngang tiêu đề.
- `navGuard` và `beforeunload` của Shell nay tính cả màn Trình đơn (`onDirtyScreen()`); Ctrl/⌘+S trên màn này
  bắn `bs-embed-cmd {id:'menus', act:'save'}`. Đã thử: sửa → chip hiện → Lưu ở Shell → panel sạch.
- `.mn-emb` bỏ lề 24px và `max-width` khi nhúng (Shell đã có lề riêng).

## Còn chờ
0. **Trình đơn — còn thiếu:** thùng rác 30 ngày (§12) chưa có UI khôi phục; "Khối tự dựng" còn dữ liệu mẫu,
   chờ `GET /api/page-builder/blocks?usage=menu`; nút "Xem trên website" biến mất khi nhúng — cân nhắc đưa lên
   cụm nút của Shell.
1. **Áp §15 sang Sản phẩm / Danh mục / Bài viết** — hiện chỉ `Page Editor - Options` đủ luật (không ghi đè
   im lặng, popup dưới đoạn bôi đen, giữ cấu trúc khi viết lại, dán làm sạch, sổ hoàn tác riêng).
   Đây là việc lớn nhất còn lại và giờ đã đủ nền để nhân bản.
2. **Backend cấp số thật:** `BSAI.setPlan(<gói>)` + `BSAI.setUsage30({post:{n,words}, image:{n,runs}, …})`.
   Hiện `CURRENT='basic'` và `USAGE30` là số mẫu → câu "đủ khoảng N …" chưa theo mức dùng thật của website.
3. **Kiểm tra hoàn tác/làm lại trên Safari + Firefox** — sổ 80 mốc của Page Editor mới thử trên Chrome.
4. **`Vận hành nội dung AI` và `Cài đặt xuất bản tự động`** chưa nối helper — cần rà xem có chỗ nào tự tính
   số từ hoặc tự khai hạn ngạch.
5. **Dòng seed dự phòng** `(window.BSAI ? … : 60000)` còn lặp ở 6 file — chỉ để vẽ khung hình đầu trước khi
   `ai-quota.js` nạp xong. Bỏ được nếu runtime cho phép chờ script helmet trước lần vẽ đầu.
