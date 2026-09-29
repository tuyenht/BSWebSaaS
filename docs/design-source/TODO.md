# Việc còn lại — cập nhật 30/07/2026

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
