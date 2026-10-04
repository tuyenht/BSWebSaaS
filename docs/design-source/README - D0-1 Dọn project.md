# README — D0-1 · Dọn & đồng bộ project thiết kế

Lượt này không vẽ màn mới. Mọi thay đổi là sửa tại chỗ.

## 0. CLAUDE.md (owner uỷ quyền 30/09)
Sửa đúng 3 chỗ: §8 dòng Token AdminKit (PR #87/#92) · §8 bề rộng kiểm popover 1240/768/390 + dòng ma trận thiết bị · cuối §13 màn toàn trang nhận `embedded`.

## 1. Bản trùng / lưu trữ
- Xoá `Website Config - Integrations.dc.html` (trùng `Integrations Panel`, thiếu hợp đồng nhúng §13). Không còn `dc-import` hay liên kết nào tới nó.
- Chuyển `Page Builder v2.dc.html`, `Page Builder - Options.dc.html` → `_archive/`. Không có `dc-import` nào trỏ tới. `Page Builder v3` giữ nguyên chỗ cũ.
- `Cài đặt - Đề xuất giao diện.dc.html` giữ nguyên, không có trong menu Shell.
- Tham chiếu còn lại chỉ nằm trong `uploads/` (dữ liệu người dùng, không sửa).

## 2. Hạn ngạch AI
- `ai-quota.js` thêm `BSAI.words(text)` và `BSAI.translateCost(texts)`: số từ thật của bản nguồn mỗi ô còn trống (tối thiểu 1) × 1,15.
- `Admin Shell` (thanh ngôn ngữ ở Cài đặt): hạn mức = `BSAI.plan().words`, đã dùng = `aiWordsUsed` (mẫu 41.500 → "Còn 18.500 từ AI"), chi phí = `translateCost`, tooltip "đủ khoảng N nhãn dịch" (`BSAI.enough`). Không đủ từ → nút mờ + chặn trước khi bấm, dòng trạng thái đỏ gợi ý dịch từng ngôn ngữ hoặc mua thêm.
- Đổi chữ: `Cài đặt xuất bản tự động`, `Vận hành nội dung AI`, `Kế hoạch nội dung SEO`, `Duyệt & xuất bản nội dung`, `AI Writer Panel v2` → "từ AI"; `Hàng đợi ảnh`, `Hạn ngạch AI và mua thêm`, `Quản lý Bài viết - Options` (ô ảnh) → "lượt tạo ảnh". Grep toàn project: không còn "lượt AI" ngoài CLAUDE.md (câu cấm) và `uploads/`.

## 3. Thương hiệu nền tảng — `platform-config.js` (`window.BSPLAT`)
Nguồn duy nhất: `name` · `kit` · `version` · `rootDomain` · `logo {light,dark,sm}` · `builtBy {show,name,url}` · `support {phone,zalo,email}`; hàm `sub(tên)`, `noReply()`, `set({...})` (phát `bs-platform-ready`).
Đã nối: Admin Shell (alt logo, logo nhỏ, chân trang, "Đăng xuất khỏi …?", Cài đặt nền tảng › Thông tin, ghi chú tên miền, email nền tảng), `Email Settings` ("Hộp thư dùng chung của nền tảng", địa chỉ no-reply, màn đồng ý của Google), `Integrations Panel` (`platform`, `sgtm.<gốc>`), `Đăng nhập nhanh…` (`website.<gốc>`), `Floating Contact Panel` (mã nhúng).
Dòng "Phát triển bởi" hiện khi `builtBy.show` VÀ công tắc Thương hiệu trắng `p-branding` › "Hiện dòng “Phát triển bởi”" chưa tắt.

**Dữ liệu mẫu giữ nguyên (không phải câu chữ giao diện):** giá trị trong `platform-config.js`; tên người "Nguyễn Bảo Sơn"/"Bảo Sơn" (tác giả, người duyệt) trong `Cài đặt nội dung AI`, `Kế hoạch nội dung SEO`, `Duyệt & xuất bản nội dung`, `Cài đặt xuất bản tự động`; doanh nghiệp mẫu "Bảo Sơn Home" trong `AI Writer Panel v2`; tên công ty trên hoá đơn trong Cài đặt nền tảng.

## 4. Design system & token (đồng bộ repo PR #87/#92)
`_ds/…` sửa được trực tiếp nên không cần `tokens-pr87.css`.
- `tokens/colors.css`: muted `#6b6e7f` · placeholder = muted · faint chỉ trang trí · `--color-border-input` · `--color-accent` · bậc `-solid` / `-text` · `--focus-ring-*` · sidebar/topbar ≥4.5:1; chế độ tối theo đúng bảng của prompt.
- `readme.md`: mục Colors + States viết lại theo bậc AA; storefront ghi đúng token `--color-sf-accent #d6a43a` (giá trị xem trước).
- `content-ui.js` (bảng T): muted `#6b6e7f`, secondary `#2f69d4`, warnInk `#906405`, dangerInk `#bf371a`, thêm `successInk`/`infoInk`; chip/banner dùng bậc -text; `focusRing()` = outline 2px cách 2px.
- `content-workflow.js` STATUS: chữ lấy bậc -text (published `#047767`, scheduled `#2f69d4`, pending `#906405`, reviewing `#026fa2`, archived `#6b6e7f`).
- Admin Shell: bảng K/tb/sb (muted `#6b6e7f` / tối `#98a0ae`, mục sidebar trắng .85 · sáng `#6c6f80` · tối `#8f94a5`, tiêu đề nhóm ≥4.5:1, điểm sáng gradient làm tối), placeholder ô tìm = `--color-placeholder`.
- File sửa trong lượt: chữ `#878a99/#adb5bd/#9aa1ac/#ced4da` → `var(--color-body-muted,#6b6e7f)` (Email Settings 84, AI Writer Panel v2 77, Kế hoạch nội dung SEO 85, Quản lý Bài viết 124, Hạn ngạch AI 45, Hàng đợi ảnh 33, Vận hành nội dung AI 31, Đăng nhập nhanh 28, Duyệt & xuất bản 21, Cài đặt xuất bản tự động 19).

## 5–6. Tài liệu
- `github.md`: bỏ mục lỗi NewLead cũ; ngôn ngữ người dùng = khoá `locale` trong `users.preferences`; 5 notification thật; lời mời thành viên có backend; giữ ghi chú `archived → pending`; Screen map theo repo; Last sync 30/09.
- `SaaS Starter - Bản đồ màn hình.md`: 61 dòng theo bản repo + mục menu · file thiết kế · trạng thái.

## Còn mở (ghi ở TODO.md)
Viền ô nhập `#e9ebec` chưa tách khỏi viền thẻ · `outline:none` cần rà từng chỗ · `Page Builder v3` còn `anvinh.baoson.net` (track riêng) · chưa có mục Cài đặt › Gói & mức dùng để nút "Mua thêm" mở tới.

## Icon mới dùng
Không có.
