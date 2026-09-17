# Handoff: Trình chọn & quản lý ảnh (Media Picker) — BSWebSaaS

## Overview
Trình chọn ảnh dạng modal cho admin BSWebSaaS, dùng khi chèn ảnh vào bài viết / sản phẩm (rich editor Jodit + gallery). Gồm 2 tab cấp cao:
1. **Tải lên tệp mới** — lưới 8 nguồn kiểu Uppy (My Device, Camera, Dropbox, Google Drive, Google Photos, OneDrive, Unsplash, Box); bấm mỗi nguồn mở giao diện riêng, có nút quay lại.
2. **Thư viện ảnh** — cây thư mục, tìm kiếm, sắp xếp 7 chiều, lọc loại tệp + định dạng, xem lưới/danh sách, chọn nhiều + thao tác hàng loạt, panel chi tiết (alt text), kéo-thả sắp xếp.

Thiết kế thay thế `media-picker-modal.blade.php` hiện tại (grid 5 cột đơn giản) và mở rộng trang `admin::livewire.media.library`.

## About the Design Files
Các file trong gói này là **bản thiết kế tham chiếu viết bằng HTML (Design Component)** — thể hiện giao diện và hành vi mong muốn, KHÔNG phải code production để copy trực tiếp. Nhiệm vụ là **tái tạo thiết kế này trong môi trường hiện có của BSWebSaaS**: Laravel 12 + Livewire + Alpine.js + Tailwind CSS 4 (tokens trong `resources/css/app.css`) + Uppy 5 (`resources/js/media-uppy.js`) + Remixicon. Không thêm framework mới.

- `MediaPicker.dc.html` — toàn bộ giao diện picker (template HTML + logic JS mô phỏng). Đọc phần template để lấy markup/style chính xác; phần `renderVals` mô tả state và hành vi.
- `Image Picker.dc.html` — trang trình bày (canvas), chỉ để xem, không cần port.

## Fidelity
**KHÔNG CUỘN ở màn chọn nguồn:** toàn bộ hero + lưới 4×2 + hàng URL phải lọt trong chiều cao modal (compact: icon hero 46px, tile padding 13px 8px, ô icon tile 44px, gap 12px). Nếu viewport thấp (<620px) cho phép cuộn như fallback, nhưng ở kích thước modal chuẩn tuyệt đối không có scrollbar.

**Responsive mọi thiết bị:** modal `width:min(1040px,calc(100vw-32px))`, `height:min(660px,calc(100dvh-32px))`; tab bar overflow-x cuộn mảnh; lưới nguồn giữ 4 cột ≥720px, xuống 3 cột <720px và 2 cột <480px (tile co bằng minmax(0,1fr), nhãn ellipsis); toolbar thư viện flex-wrap; cây thư mục ẩn thành dropdown ở <640px; panel chi tiết thành overlay phủ ở <900px. Test tối thiểu: 360×740 (mobile), 768×1024 (tablet), 1280×800, 1920×1080.

**High-fidelity.** Màu, chữ, khoảng cách, bo góc, trạng thái đều là giá trị chốt — tái tạo pixel-perfect bằng Tailwind utilities/tokens có sẵn của dự án (bảng quy đổi ở mục Design Tokens). Dữ liệu ảnh trong file design là placeholder gradient — thay bằng thumbnail thật qua `MediaUrlResolver` (`$urls->url($storage_key, 320)`).

## Kiến trúc tích hợp đề xuất (map vào codebase hiện có)
- **Livewire component**: mở rộng trait `App\Modules\Admin\Livewire\Concerns\WithMediaLibraryPicker` + partial `admin::partials.media-picker-modal` thành modal mới này. Giữ nguyên cơ chế: `openImagePicker(mode)`, `pickImage`, dispatch `admin-editor-insert-image`, re-gate `media.view` mọi đường.
- **Upload**: tab "Thiết bị của tôi" dùng pipeline Uppy hiện có (presign → PUT R2 → register, fallback XHR local) trong `resources/js/media-uppy.js`; render Uppy KHÔNG dùng Dashboard mặc định mà style theo thiết kế (dropzone + progress rows). Đặt island trong `wire:ignore`.
- **Nguồn cloud (Dropbox/Drive/Photos/OneDrive/Box/Unsplash/Camera)**: chuẩn Uppy Companion (`@uppy/dropbox`, `@uppy/google-drive`, `@uppy/google-photos`, `@uppy/onedrive`, `@uppy/box`, `@uppy/unsplash`, `@uppy/webcam`) — cần deploy Companion server hoặc dùng Transloadit-hosted Companion; OAuth keys cấu hình trong `config/services.php`. UI mỗi nguồn theo thiết kế (mục Screens), dữ liệu file list lấy từ Companion API.
- **Thư mục**: cần migration mới `media_folders` (id, site_id, **name** (Unicode tự do), **slug** (ASCII, unique theo site), position) + cột `folder_id` nullable trên `media`. RLS/BelongsToSite như các bảng khác.
  - **Quy tắc slug (BẮT BUỘC)**: sinh tự động từ name bằng transliteration ASCII (tiếng Việt bỏ dấu: "Sản phẩm" → `san-pham`; đ/Đ → d). Nếu kết quả rỗng (tên toàn chữ tượng hình Trung/Nhật/Hàn, emoji…) → fallback `folder-{n}` và cảnh báo cho user sửa tay. Slug chỉ chấp nhận `[a-z0-9-]`, unique theo site (đụng → hậu tố -2, -3…). Form "Thư mục mới" trong thiết kế có preview slug realtime + nút sửa + cảnh báo vàng khi fallback — làm đúng như vậy.
  - **Link ảnh KHÔNG được phụ thuộc slug thư mục**: ảnh vẫn lưu/serve theo `storage_key` hiện có (R2). Slug chỉ dùng cho URL trang quản trị & query filter. Đổi tên/slug thư mục không bao giờ làm vỡ link ảnh đã chèn — viết test khẳng định điều này.
- **Trạng thái "Đã dùng trong bài"**: suy ra từ bảng `mediables` (Mediable model có sẵn) — media có attachment ⇒ "đã dùng".
- **i18n**: mọi chuỗi qua `lang/vi.json` (+ en/ja/zh). Các chuỗi liệt kê ở mục Copy.

## Screens / Views

### 0. Khung modal (mọi màn hình)
- Overlay: `fixed inset-0 bg-black/50`, modal căn giữa.
- Modal: 1020×660px mặc định (max-w ~1040px, max-h 80vh, responsive co lại), nền `#ffffff`, border `1px #e9ebec`, `border-radius:14px`, shadow lớn `0 24px 60px -22px rgba(56,65,74,.4)`, font Inter 14px.
- **Header** (padding 15px 20px 9px): trái = icon vuông 34×34 `border-radius:9px` nền `#405189` @ 8% (`#40518914`) chứa `ri-image-add-line` 19px màu `#405189`; tiêu đề "Chọn ảnh" 15.5px/600 `#343a40`; phụ đề "Chèn ảnh cho bài viết & sản phẩm" 12px `#adb5bd`. Phải = nút đóng 32×32 `border-radius:8px` nền `#f3f3f9`, icon `ri-close-line` 17px `#878a99`.
- **Tab bar** (ngay dưới header, `border-bottom:1px #e9ebec`, padding ngang 20px, gap 24px, `overflow-x:auto` khi hẹp): tab dạng **gạch chân** — padding 12px 4px, chữ 13.5px; active: màu `#405189`, weight 600, gạch chân 2.5px `#405189` đè lên border; inactive: `#878a99`, weight 500. Icon 16px trước chữ (`ri-upload-2-line` / `ri-image-2-line`). Tab "Thư viện ảnh" có **badge đếm tổng số tệp**: 10.5px/600, padding 1.5px 7px, bo 10px, nền `#40518914` chữ `#405189` khi active (inactive: nền `#f3f3f9` chữ `#878a99`).
- **Footer** (padding 12px 18px, `border-top:1px #eff2f7`): trái = khi có chọn: chồng 3-4 thumbnail 32×32 bo 7px viền trắng 2px chồng lệch -8px + "N ảnh đã chọn" 12.5px `#878a99`; khi chưa chọn: "Chọn ảnh để chèn vào nội dung" 12.5px `#adb5bd`. Phải = nút "Hủy" (outline `#e9ebec`, chữ `#495057`) + nút chính "Chèn ảnh"/"Chèn N ảnh" (nền `#405189` chữ trắng, 13px/600, padding 9px 18px, bo 8px; disabled khi chưa chọn: nền `#ced4da`, cursor not-allowed).

### 1. Tab "Tải lên tệp mới" — màn hình chọn nguồn (mặc định)
- Toàn vùng nội dung là một **dropzone**: margin 18px, `border:2px dashed #dfe3ea`, bo 16px, nền `#fbfcfd`, nội dung căn giữa dọc.
- Giữa trên: hình tròn 56px nền `#40518914` chứa `ri-upload-cloud-2-line` 27px `#405189`; "Kéo & thả ảnh vào đây" 16px/600 `#343a40`; dòng phụ "hoặc **chọn tệp** · nhập từ nguồn bên dưới" 13px `#878a99` ("chọn tệp" là link `#405189`/600 gạch chân → mở file dialog).
- **Lưới nguồn: cố định 4 cột × 2 dòng**, gap 12px, max-width 640px căn giữa. Thứ tự: Thiết bị của tôi, Máy ảnh, Dropbox, Google Drive / Google Photos, OneDrive, Unsplash, Box.
- Tile nguồn: nền trắng, border `1px #eff2f7`, bo 13px, padding 18px 10px, cột căn giữa gap 11px. Icon đặt trong ô 52×52 bo 14px nền = màu thương hiệu @10% (`{color}1a`), icon 26px màu thương hiệu. Nhãn 12.5px/500 `#495057`. Hover: border `#405189`, shadow `0 6px 16px -8px rgba(64,81,137,.4)`, translateY(-2px), transition .15s.
- Nguồn **đã kết nối** có chấm 9px `#0ab39c` viền trắng 2px góc trên-phải tile.
- Màu thương hiệu + icon Remixicon: Thiết bị `#405189` `ri-computer-line` · Máy ảnh `#0ab39c` `ri-camera-line` · Dropbox `#0061FF` `ri-dropbox-fill` · Google Drive `#1a73e8` `ri-drive-fill` · Google Photos `#EA4335` `ri-google-fill` · OneDrive `#0364B7` `ri-microsoft-fill` · Unsplash `#111827` `ri-camera-lens-line` · Box `#0061D5` `ri-inbox-archive-line`. (Có thể thay bằng SVG logo chính thức nếu pháp lý cho phép.)

### 1b. Ảnh từ URL (thay cho link "Dán URL ảnh ngoài…" ở footer — BỎ link footer nếu đã có)
- Trên màn chọn nguồn, dưới lưới 8 tile: divider "— hoặc dán liên kết ảnh —" (11.5px `#adb5bd`, 2 vạch `#e9ebec`) + hàng URL: input có icon `ri-link` (placeholder "https://vi-du.com/anh-san-pham.jpg", padding 9px, bo 8px) + nút "Xem trước" (outline tint: viền `#40518933`, nền `#40518908`, chữ `#405189`/600, icon `ri-eye-line`). Enter = Xem trước.
- Validate client: URL http(s) đuôi jpg/jpeg/png/webp/gif/avif — sai thì báo đỏ `#f06548` kèm `ri-error-warning-line` "Liên kết chưa hợp lệ — cần là URL ảnh công khai (jpg, png, webp, gif)." Server-side PHẢI fetch qua proxy (chống SSRF: chặn IP private, giới hạn size, sniff magic-byte) rồi mới trả preview.
- Bấm Xem trước → mở màn nguồn "Ảnh từ URL" (header nguồn như mục 2, icon `ri-link` màu `#6559cc`): input + nút "Tải xem trước" (primary `#405189`); card preview bo 13px viền `#eff2f7`: ảnh 16:9 + badge "✓ Đã chọn để chèn" (11px `#0ab39c` nền trắng 90% bo 20px, góc trên-phải); dưới: tên tệp 13px/600 + "W × H · ~X MB" 11.5px `#adb5bd`; checkbox "Lưu bản sao vào Thư viện ảnh (khuyên dùng — tránh ảnh hỏng khi liên kết gốc bị xóa)" — mặc định BẬT; khi bật: tải ảnh về pipeline upload như tệp thường; khi tắt: chèn hotlink trực tiếp.
- Ảnh preview hợp lệ tự động vào danh sách đã chọn (footer "Chèn 1 ảnh" bật). Khi chưa có preview: khung dashed hướng dẫn (icon `ri-image-2-line` 30px `#ced4da`).

### 2. Header nguồn (khi đã vào 1 nguồn)
Thanh 1 dòng (padding 11px 18px, border-bottom `#eff2f7`): nút "← Nguồn" (outline, 12.5px, `ri-arrow-left-line`) quay về lưới nguồn · ô icon thương hiệu 30×30 bo 8px · tên nguồn 14px/600 `#343a40` · phải: chip "Đã kết nối" (11.5px `#0ab39c` nền `#0ab39c14` bo 20px, icon `ri-checkbox-circle-fill`) nếu nguồn có OAuth và đã kết nối.

### 3. Nguồn "Thiết bị của tôi"
- Dropzone lớn: `2px dashed #ced4da`, bo 14px, nền `#f8f9fb`, padding 40px 24px, căn giữa: tròn 64px `#40518914` + `ri-upload-cloud-2-line` 30px `#405189`; "Kéo & thả ảnh vào đây" 16px/600; "hoặc chọn tệp từ thiết bị của bạn" 13px `#878a99`; nút "Chọn tệp" primary (icon `ri-folder-open-line`); hint "JPG, PNG, WEBP, GIF · tối đa 10MB mỗi tệp" 11.5px `#adb5bd` (lấy từ `UploadMedia::MIME_EXT` + `MAX_KB` thật).
- Dưới: nhãn "ĐANG TẢI LÊN · N TỆP" 11px/600 `#adb5bd` letter-spacing .05em; mỗi hàng upload: thumb 38×38 bo 8px + tên tệp 12.5px + % hoặc trạng thái; progress bar cao 5px bo 5px, nền `#eff2f7`, fill `#405189` (đang tải) / `#0ab39c` + `ri-checkbox-circle-fill` "Xong" (hoàn tất). Border hàng `1px #eff2f7` bo 10px, padding 10px 12px.

### 4. Nguồn "Máy ảnh" (Uppy Webcam)
- Khung preview 16:10 max-width 520px bo 14px nền tối `radial-gradient(#2b3138→#16191d)` + lưới mờ 32px; badge "LIVE" đỏ `#f06548` @60% góc trên-trái.
- Điều khiển: nút tròn 42px outline (đổi camera `ri-camera-switch-line`, hẹn giờ `ri-timer-line`) hai bên; nút chụp giữa 64px nền `#405189` viền `#40518933` 4px, icon `ri-camera-fill` 26px trắng.
- Dải "ẢNH ĐÃ CHỤP": thumb 64×64 bo 9px + ô cuối dashed `ri-add-line`.

### 5. Nguồn cloud (Dropbox / Google Drive / OneDrive / Box)
- **Chưa kết nối**: căn giữa — ô icon 78×78 bo 20px nền brand@10%, icon 38px; "Kết nối {Tên}" 17px/600; mô tả 13px `#878a99` max 320px; nút "Kết nối tài khoản" nền **màu thương hiệu** chữ trắng (icon `ri-links-line`); chú thích "🔒 Kết nối an toàn qua OAuth" 11px `#adb5bd`.
- **Đã kết nối**: thanh breadcrumb (11-12px `#878a99`, icon `ri-folder-3-line`, "Trang chủ / {Tên}", phải: link "Ngắt kết nối" gạch chân) + danh sách hàng: thư mục = ô 38×38 `#f3f3f9` icon `ri-folder-3-fill` màu `#f7b84b`, tên 13px + "N mục" 11.5px `#adb5bd`, mũi tên `ri-arrow-right-s-line` phải, click đi sâu vào; tệp = thumb 38×38 bo 8px, tên + dung lượng, checkbox vuông 20px bo 5px phải (checked: nền `#405189` icon `ri-check-line` trắng; unchecked: viền `#ced4da`). Click hàng tệp = toggle chọn. Hàng padding 9px 10px bo 9px, hover nền `#f3f6f9`.

### 6. Nguồn "Google Photos"
Lưới ảnh nhóm theo tháng: heading "Tháng M, YYYY" 13px/600 kèm `ri-calendar-line`; grid `auto-fill minmax(104px,1fr)` gap 8px, tile vuông bo 9px; chọn = vòng tròn checkbox 20px góc trên-phải (như mục 8) + viền 2.5px `#405189`.

### 7. Nguồn "Unsplash"
Ô tìm kiếm lớn (padding 11px, bo 9px, icon `ri-search-line`) placeholder "Tìm ảnh miễn phí trên Unsplash…"; hàng chip gợi ý (Thiên nhiên, Sản phẩm, Văn phòng, Ẩm thực, Công nghệ, Kiến trúc) — pill 12.5px viền `#e9ebec` bo 20px; kết quả dạng **masonry 3 cột** (`column-count:3`, gap 10px), mỗi ảnh bo 10px, overlay gradient đáy ghi "📷 Tên tác giả" 10.5px trắng (bắt buộc attribution theo Unsplash API), chọn như tile ảnh thường.

### 8. Tab "Thư viện ảnh"
Bố cục 2 cột:
- **Cây thư mục trái** (rộng 184px, border-right `#eff2f7`): nhãn "THƯ MỤC" 11px/600 `#adb5bd`; mục: icon (`ri-stack-line` cho "Tất cả ảnh", `ri-folder-3-line` còn lại) + tên 13px + số đếm 11px phải; active: nền `#40518914` chữ `#405189`/600. Cuối: nút "＋ Thư mục mới" dashed `#ced4da` chữ `#878a99` — bấm mở form inline: input tên (Unicode tự do) + dòng preview slug `/{slug}` (11px, slug `#405189`/600, icon `ri-link` + nút sửa `ri-pencil-line` cho phép sửa tay) + cảnh báo vàng `#f7b84b` 10.5px khi tên không chuyển được sang Latinh (fallback `folder-{n}`) + nút "Tạo thư mục" primary nhỏ. Enter = tạo; tạo xong tự chuyển vào thư mục mới.
- **Vùng chính**:
  - **Toolbar** (padding 12px 16px, border-bottom, flex-wrap): ô tìm kiếm (flex:1, min 150px, icon search, placeholder "Tìm theo tên ảnh…", lọc realtime theo tên) · select **Sắp xếp**: Mới nhất / Cũ nhất / Tên A→Z / Tên Z→A / Kích thước lớn→nhỏ / Kích thước nhỏ→lớn / Theo đuôi tệp · select **Loại tệp**: Tất cả loại tệp / Hình ảnh / Video / Âm thanh / Tài liệu / Đã dùng trong bài / Chưa sử dụng · select **Định dạng**: Mọi định dạng / JPG / PNG / MP4 / PDF / XLSX / MP3 (danh sách build từ dữ liệu thật) · toggle **lưới/danh sách** (2 nút 34×32 trong khung viền chung bo 8px; active nền `#405189` icon trắng; icons `ri-grid-fill`, `ri-list-check-2`). Tất cả select: padding 8px 10px, viền `#e9ebec`, bo 8px, 12.5px. Bộ lọc kết hợp AND với nhau + thư mục + tìm kiếm.
  - **Thanh chọn nhiều** (chỉ hiện khi có chọn; nền `#40518908`, border-bottom): "N đã chọn" 12.5px/600 `#405189`; phải: nút "Di chuyển" (`ri-folder-transfer-line`), "Xóa" (viền/chữ `#f06548`, `ri-delete-bin-line` — soft delete như `Library::delete`), "Bỏ chọn".
  - **Lưới** (`auto-fill minmax(118px,1fr)` gap 12px): tile vuông bo 11px viền `#eff2f7`; ảnh phủ kín (object-cover); dải tên tệp đáy: gradient đen 55%, chữ 10px trắng ellipsis; **checkbox tròn 20px** góc trên-phải (unchecked: viền trắng 1.5px nền đen 18%; checked: nền + viền `#405189`, `ri-check-line` trắng); **nút ⓘ chi tiết 22×22** góc trên-trái (nền đen 32%, bo 6px, `ri-information-line` trắng — stopPropagation, mở panel chi tiết); khi chọn: viền phủ 2.5px `#405189`. Click tile = toggle chọn. Kéo-thả đổi vị trí (HTML5 drag) — CHỈ khi sort = "Mới nhất"; lưu `position`.
  - **Tệp không phải ảnh**: tile nền màu-loại@8% + icon giữa 34px: video `ri-film-line` `#299cdb` · audio `ri-music-2-line` `#8b5cf6` · PDF `ri-file-pdf-2-line` `#f06548` · Excel `ri-file-excel-2-line` `#0ab39c`.
  - **Danh sách**: header cột 11px/600 `#adb5bd` (TÊN TỆP / KÍCH THƯỚC / DUNG LƯỢNG / LOẠI); hàng: checkbox vuông 22px + thumb 42×42 bo 8px + tên 13px (flex:1 ellipsis) + "W × H" 12.5px `#878a99` (100px) + "X.X MB" (70px) + badge đuôi tệp (10px/600 `#405189` nền `#40518914` bo 5px) + nút ⓘ. Hàng đã chọn: nền `#40518908`.
  - **Empty state**: `ri-image-line` 40px `#ced4da` + "Không tìm thấy ảnh phù hợp" 13.5px `#adb5bd`, căn giữa padding 50px.
- **Panel chi tiết** (trượt phải, rộng 278px, border-left, nền `#fbfcfd`): header "Chi tiết ảnh" 13px/600 + nút đóng; preview 4:3 bo 10px; tên tệp 13.5px/600 word-break; bảng thuộc tính 12.5px (nhãn `#adb5bd` trái, giá trị `#495057` phải): Kích thước ("1600 × 1067 px"), Dung lượng, Định dạng, Thư mục; textarea "VĂN BẢN THAY THẾ (ALT)" (min-height 64px, lưu vào cột `alt` JSON theo locale của Media model); nút "Lưu thông tin" outline full-width.

## Interactions & Behavior
- Mở modal: từ `openImagePicker(mode)` (mode `editor` | `gallery` như trait hiện có). Đóng: nút ✕, nút Hủy, phím Esc, click overlay.
- Chuyển tab reset về màn nguồn (src=null) và đóng panel chi tiết.
- Chọn: single-select (mode editor) chỉ giữ 1; multi-select (gallery) toggle từng ảnh. Nút "Chèn" disabled khi rỗng; label "Chèn N ảnh".
- Chèn: editor → resolve URL 1200w qua `MediaUrlResolver`, dispatch `admin-editor-insert-image`; gallery → `addGalleryImage(id)` từng ảnh.
- Nguồn cloud: chưa kết nối → nút Kết nối mở OAuth popup (Companion); thành công → đổi sang màn duyệt tệp + chấm xanh trên tile nguồn. "Ngắt kết nối" → revoke + quay về màn kết nối.
- Import từ cloud/Unsplash: sau khi chọn + bấm Chèn, Companion tải tệp về pipeline upload (presign/register) rồi media mới xuất hiện trong Thư viện.
- Upload xong batch → dispatch `media-uploaded` (grid Livewire refresh, như hiện tại).
- Tìm kiếm: debounce ~250ms. Toàn bộ lọc/sắp xếp nên là query param Livewire (server-side, có phân trang thật thay vì limit 60).
- Hover tile ảnh: hiện rõ nút ⓘ (có thể ẩn mặc định, hiện khi hover — thiết kế hiện để luôn hiện cho khả năng chạm).
- Transition: hover tile nguồn .15s; các thay đổi khác không cần animation phức tạp.

## State Management (Livewire public props gợi ý)
- `tab` ('upload'|'library'), `source` (null|device|camera|dropbox|gdrive|gphotos|onedrive|unsplash|box)
- `view` ('grid'|'list'), `sort` (recent|oldest|nameAsc|nameDesc|sizeDesc|sizeAsc|ext), `typeFilter` (all|image|video|audio|doc|used|unused), `formatFilter`, `search`, `folderId`
- `selected: array<int>` (media ids), `detailId` (?int)
- `connections: array<string,bool>` (trạng thái OAuth từng provider, đọc từ bảng oauth tokens)
- Server: query media theo site (RLS sẵn có), eager count mediables cho used/unused.

## Design Tokens (map vào token dự án)
| Giá trị thiết kế | Token BSWebSaaS (`app.css`) |
|---|---|
| `#405189` (accent, active, nút chính) | `--color-admin-primary` / `text-accent` |
| `#40518914` (nền tint 8%) | `bg-admin-primary/8` |
| `#0ab39c` (thành công/kết nối) | `--color-admin-success` |
| `#f06548` (xóa/LIVE) | `--color-admin-danger` |
| `#299cdb` (video) | `--color-admin-info` |
| `#f7b84b` (icon thư mục) | `--color-admin-warning` |
| `#ffffff` nền modal | `--color-surface` |
| `#fbfcfd`/`#f8f9fb` nền phụ | `--color-surface-muted` |
| `#f3f3f9`/`#f3f6f9` nền nút phụ/hover | `--color-surface-hover` |
| `#eff2f7` divider | `--color-border` |
| `#e9ebec` viền input/select | `--color-border-strong` |
| `#343a40` heading | `--color-heading` |
| `#495057` chữ chính | `--color-body-text` |
| `#878a99` chữ phụ | `--color-body-muted` |
| `#adb5bd` chữ mờ/nhãn nhóm | `--color-body-faint` |
| `#ced4da` placeholder/disabled | `--color-placeholder` |
| Font Inter, base 14px | `--font-stack`, `--vz-body-font-size` (đã self-host) |
| Bo góc: modal 14px; card/tile 10-13px; nút/input 8px; chip 20px | — |
| Nhãn nhóm: 11px/600, letter-spacing .05em, uppercase | — |
| Brand colors (Dropbox `#0061FF`, Drive `#1a73e8`, Photos `#EA4335`, OneDrive `#0364B7`, Box `#0061D5`, Unsplash `#111827`) | chỉ dùng cục bộ trong picker, không đưa vào theme |

## Copy (chuỗi cần i18n)
"Chọn ảnh", "Chèn ảnh cho bài viết & sản phẩm", "Tải lên tệp mới", "Thư viện ảnh", "Kéo & thả ảnh vào đây", "hoặc chọn tệp · nhập từ nguồn bên dưới", "Chọn tệp", "Nguồn", "Đã kết nối", "Kết nối tài khoản", "Kết nối an toàn qua OAuth", "Ngắt kết nối", "ĐANG TẢI LÊN", "ẢNH ĐÃ CHỤP", "THƯ MỤC", "Tất cả ảnh", "Thư mục mới", "Tìm theo tên ảnh…", các option sắp xếp/lọc như mục 8, "N đã chọn", "Di chuyển", "Xóa", "Bỏ chọn", "Không tìm thấy ảnh phù hợp", "Chi tiết ảnh", "Kích thước", "Dung lượng", "Định dạng", "Thư mục", "VĂN BẢN THAY THẾ (ALT)", "Lưu thông tin", "hoặc dán liên kết ảnh", "Xem trước", "Tải xem trước", "Ảnh từ URL", "Đã chọn để chèn", "Lưu bản sao vào Thư viện ảnh", "khuyên dùng — tránh ảnh hỏng khi liên kết gốc bị xóa", "Liên kết chưa hợp lệ — cần là URL ảnh công khai (jpg, png, webp, gif).", "Hủy", "Chèn ảnh"/"Chèn :count ảnh", "Chọn ảnh để chèn vào nội dung", "N ảnh đã chọn".

## Assets
- Icon: Remixicon (đã có trong `package.json`) — tên icon liệt kê theo từng mục ở trên.
- Ảnh trong file design là gradient placeholder — production dùng thumbnail qua `MediaUrlResolver` (320w cho tile, 640w cho list/detail, 1200w khi chèn editor).
- Logo thương hiệu nguồn: dùng Remixicon brand glyphs như thiết kế, hoặc SVG chính thức của từng dịch vụ (theo brand guideline của họ).

## Files
- `MediaPicker.dc.html` — thiết kế chính (markup + hành vi mô phỏng).
- `Image Picker.dc.html` — trang trình bày canvas.
- `OAUTH-SETUP.md` — cấu hình Uppy Companion + OAuth từng nguồn cloud (Docker service, env, redirect URI, scope).

## Gợi ý thứ tự triển khai
1. Migration `media_folders` + `media.folder_id` + `media.position`.
2. Livewire `MediaPicker` component + Blade modal theo Screens 0–1, 8 (thư viện + device upload trước — giá trị cao nhất, không phụ thuộc Companion).
3. Nối Uppy hiện có vào tab Thiết bị (bỏ Dashboard UI, dùng UI thiết kế).
4. Panel chi tiết + lưu alt (cột `alt` JSON có sẵn).
5. Companion + từng provider cloud (Dropbox/Drive trước), Webcam, Unsplash.
6. Thay `admin::partials.media-picker-modal` cũ bằng modal mới; regression test quyền `media.view/upload/delete`.
