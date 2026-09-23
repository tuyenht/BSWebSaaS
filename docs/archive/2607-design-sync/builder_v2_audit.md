> 🗄 **LƯU TRỮ (24/09/2026)** — tài liệu làm việc của đợt đồng bộ thiết kế tháng 7/2026, giữ làm lịch sử.
> KHÔNG phải nguồn sự thật: có chỗ đã lỗi thời hoặc mâu thuẫn quyết định sau này. SSOT = `docs/design.md`
> (chỉ mục ADR ở Phụ lục A) + `docs/README.md`. Lý do lưu trữ: `docs/archive/README.md`.

# Audit — Page Builder v2 (Trình dựng trang)

> Bản ghi nhận để tổng hợp & xử lý sau. Cập nhật: **P1 + P2 đã hoàn thành & kiểm chứng**.
> Thứ tự thực hiện đã chốt với chủ dự án: **1) Hoàn thiện Builder → 2) Nối luồng "Trình dựng" trong thêm/sửa trang → 3) Gói bàn giao (E)**.
>
> ⚠️ **QUAN TRỌNG (2026‑07, sau khi đọc repo thật):** builder prototype hiện tại (Section›Cột›Phần tử + inline‑CSS + 25 phần tử)
> **LỆCH mô hình thật** = **Page‑JSON + Section Library** (ADR‑04/05/08/17). P1/P2 dưới đây là hoàn thiện *tính năng của bản
> prototype*; **hướng đi đúng cho bàn giao = reframe thành "trình soạn Section Library" xuất Page‑JSON**. Nguồn chuẩn:
> **`docs/builder_v2_alignment.md`** (ĐỌC TRƯỚC). P3 (đánh bóng UI) hoãn tới khi chốt hướng reframe.

## ✅ P2 — ĐÃ XONG (phiên này, đã test hành vi)
- **#2 Biểu mẫu — option + validate**: thêm loại trường **Nút chọn (radio)** & **Ô tích nhiều (checkboxes)**; "Ô tích" cũ đổi thành **Ô đồng ý** (consent đơn). Lựa chọn / Nút chọn / Ô tích nhiều có **ô nhập danh sách phương án** (mỗi dòng 1); đổi sang loại chọn tự seed 2 phương án mẫu. Canvas render đúng: dropdown hiện phương án đầu, radio = vòng tròn, checkboxes = ô vuông. Thêm **gợi ý validate** theo loại (email/tel/số/ngày/bắt buộc).
- **#3 Bảng giá — link nút + badge**: mỗi gói thêm **Nhãn nút** + **Link nút** + **Badge** (vd "Phổ biến"). Badge hiển thị pill vàng ở đỉnh thẻ; gói mẫu "Chuyên Nghiệp" có badge sẵn. "Gói nổi bật" (tô đậm) vẫn riêng biệt với badge.
- **#11 Icon-box — vị trí + cỡ icon**: tab Kiểu thêm **Vị trí biểu tượng (Trên / Bên trái)** + **thanh Cỡ biểu tượng (18–56px)**; khối nền icon tự co giãn theo cỡ. (Link Icon-box đã có từ P1.)

## ✅ P1 — ĐÃ XONG (đã test hành vi)
- **#13 Kiểu eyebrow tách khỏi màu**: bỏ hẳn suy-từ-màu (`el.color===AMBER` → không còn tự IN HOA). Thêm **công tắc "Kiểu nhãn nhỏ (eyebrow)"** ở tab Kiểu của Văn bản (bật/tắt độc lập với màu). Có **migration draft cũ**: text màu vàng chưa có cờ → tự gán `eyebrow:true` khi nạp, giữ nguyên diện mạo. Seed + preset Hero đã gắn `eyebrow:true`.
- **#6 Alt text ảnh**: thêm trường **"Văn bản thay thế (Alt) · SEO"** cho phần tử Ảnh, kèm **cảnh báo WCAG** khi ảnh có mà thiếu Alt. (Chú thích ảnh giữ nguyên.)
- **#1 Link phổ biến**: Tiêu đề / Văn bản / Icon / Icon-box nay có khối **"Liên kết khi bấm"** + công tắc **"Mở link ở tab mới"** (hiện khi có link). Canvas hiển thị **glyph 🔗** trên phần tử đang chọn khi có link (áp cả Ảnh/Nút). Ảnh giữ khối link riêng — không trùng lặp.
- **#8/#14 Empty / loading / error**:
  - *Empty*: trang 0 Section → **empty-state** có hướng dẫn + 2 nút "Thêm Section" / "Chọn mẫu dựng sẵn". Nút "Thêm khối vào cuối trang" chỉ hiện khi đã có Section.
  - *Loading*: **spinner "Đang mở thư viện ảnh…"** phía sau MediaPicker trong lúc dc-import tải.
  - *Error*: autosave hỏng (localStorage đầy/lỗi) → **trạng thái "Lưu lỗi — bấm để thử lại"** (chấm đỏ) trên thanh trên + toast; bấm để lưu lại.
- Autosave key giữ nguyên `bswebsaas:pb:draft:v2`; đã test không phá bản nháp cục bộ.

## ✅ Đã hoàn thiện & đã kiểm chứng trong phiên
- Cấu trúc **Section › Cột › Phần tử**; kéo‑thả sắp xếp phần tử trên canvas (khe chèn + làm mờ); thêm / nhân bản / xoá / lên‑xuống.
- **20+ phần tử**: Tiêu đề, Văn bản, Ảnh, Nút, Đường kẻ, Khoảng cách, Biểu tượng, Danh sách, Slider, Icon‑box, Bộ sưu tập, Bảng giá, FAQ, Video, Bản đồ, Biểu mẫu, Đếm số, Cảm nhận KH, Logo đối tác, Thanh tiến trình, Đếm ngược, Tabs, Nhúng HTML, Sản phẩm, Bài viết.
- **Mẫu** trang / bố cục / khối dựng sẵn (có ảnh minh hoạ wireframe).
- Nền khối (màu / ảnh / lớp phủ / vị trí / parallax) cho Section & Cột.
- **Responsive**: container query, lưới auto‑fit, cuộn ngang, cột stack trên mobile.
- **Autosave + khôi phục** (localStorage, chỉ chạy sau khi mount, lưu trọn vẹn — đã fix lỗi cũ); Undo/Redo.
- **Màu thương hiệu website** (chảy vào các bảng chọn màu + màu nút mặc định).
- **Override theo thiết bị** (cỡ chữ tiêu đề, đệm Section riêng desktop/tablet/mobile); **ẩn theo thiết bị** (làm mờ đúng thiết bị).
- Hiệu ứng xuất hiện (fade/zoom/slide…); MediaPicker (chọn/đổi/tải ảnh) dùng chung.
- Đã sửa **bug gốc `cols`** (số vs mảng) an toàn cả Gallery/Sản phẩm/Counter/Testimonial.

## ⚠️ Có rồi nhưng chưa đủ
1. ~~**Link chưa phổ biến**~~ — ✅ XONG (P1): Tiêu đề / Văn bản / Icon / Icon‑box đã gắn link + tab mới + chỉ báo trên canvas.
2. ~~**Biểu mẫu**~~ — ✅ XONG (P2): thêm radio + checkboxes + option list + gợi ý validate.
3. ~~**Bảng giá**~~ — ✅ XONG (P2): link nút + nhãn nút + badge "Phổ biến" mỗi gói.
4. **Section** — chưa có **khoảng cách giữa cột (gap)** & **chiều cao tối thiểu**; chưa **kéo‑thả sắp xếp Section** (chỉ nút ↑↓).
5. **Cột** — chưa **kéo đổi tỉ lệ** (chỉ preset 1:1, 1:2…).

## ❌ Còn thiếu (khuyến nghị bổ sung)
6. ~~**Alt text cho ảnh**~~ — ✅ XONG (P1): trường Alt + cảnh báo WCAG khi thiếu.
7. **Sửa chữ trực tiếp trên canvas** (double‑click gõ ngay) — hiện sửa qua panel phải.
8. ~~**Empty / loading / error state**~~ — ✅ XONG (P1): empty-state trang trống + spinner MediaPicker + trạng thái lưu lỗi.
9. **Gallery lightbox / chú thích ảnh**.
10. **Sao chép ↔ dán** phần tử giữa cột / section (hiện chỉ nhân bản tại chỗ).
11. ~~**Icon‑box**~~ — ✅ XONG (P1 link + P2 vị trí/cỡ icon): link (P1) + vị trí icon (trên/trái) + cỡ icon (P2).
12. **"Xem trước" thật** — hiện chỉ hiện thông báo; nên mở bản render sạch (không khung sửa).

## 🔎 Rà soát vòng 2 — phát hiện thêm
13. ~~**Quirk màu chữ "vàng"**~~ — ✅ XONG (P1): eyebrow là công tắc riêng, không còn suy từ màu; có migration draft cũ.
14. ~~**Xoá hết Section → trang trống**~~ — ✅ XONG (P1): đã có empty‑state hướng dẫn (gộp #8).
15. **Tương phản màu tự đặt** — nút có auto‑tương phản, nhưng chữ/nền do người dùng đặt tay **không cảnh báo** khi tương phản kém (WCAG AA).
16. **Bàn phím** — chưa có phím **Delete** xoá phần tử đang chọn, **Esc** bỏ chọn (mới có Ctrl+Z/Y, Ctrl+S).
17. **Kéo‑thả trong panel Lớp** — chưa kéo sắp xếp trong Lớp (chỉ trên canvas).
18. **Sao chép Section / Cột** sang vị trí khác (mới có nhân bản liền kề).
19. **Slider / carousel tự chạy** khi không chọn — có thể gây nhiễu khi đang sửa phần tử khác; nên có **nút tạm dừng tổng** hoặc dừng khi đang chỉnh.
20. **Kiểm chứng full‑width** — xác nhận Section "Tràn viền" chạm sát mép trên mọi thiết bị (từng có phản hồi trước; cần test lại sau khi thêm gap/override).
21. **Video / Bản đồ** — chỉ hiển thị đại diện trong builder; cần ghi rõ "chạy thật khi xuất bản".
22. **Nhất quán căn lề & màu** cho các phần tử mới (Counter/Testimonial/Partners/Progress/Countdown/Tabs) — đã có align/margin/hide, nhưng nên rà lại "Kiểu" cho từng loại (một số ẩn tab Kiểu là đúng chủ đích).

## Đề xuất ưu tiên xử lý
- ~~**P1 — chất lượng & SEO**~~: ✅ HOÀN THÀNH (#6 · #1 · #8/#14 · #13).
- ~~**P2 — hoàn thiện phần tử hay dùng**~~: ✅ HOÀN THÀNH (#2 Option Biểu mẫu · #3 Bảng giá link+badge · #11 Icon‑box vị trí+cỡ icon).
- **P3 — thao tác nâng cao** *(tiếp theo)*: #4 kéo‑thả Section + gap + min‑height · #5 kéo đổi tỉ lệ cột · #7 sửa chữ inline · #10/#18 copy‑paste · #9 gallery lightbox · #12 Xem trước thật · #16 phím tắt · #17 kéo trong Lớp · #19 tạm dừng slider · #15 cảnh báo tương phản.

## Ghi chú tích hợp (cho bước bàn giao E)
- MediaPicker: prop `onInsert(items)` / `onClose()` — nơi nhúng phải truyền vào.
- Autosave key `bswebsaas:pb:draft:v2` là bản nháp cục bộ → thay bằng lưu server theo `site_id`/entry khi tích hợp.
- Màu thương hiệu → lấy tự động từ logo mỗi tenant khi tích hợp.
- "Trình dựng" mở từ Trình sửa trang (đã nối điều hướng file‑to‑file); cần truyền ngữ cảnh entry qua route/Inertia khi code thật.
