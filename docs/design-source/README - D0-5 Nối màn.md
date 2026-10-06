# README — D0-5 · Tách CSS component + nối màn đã có vào Admin Shell (06/10/2026)

## Tổng quan
Một bộ CSS component dùng chung (`admin-components.css`) trước khi D0-6 sửa từng màn, và luồng viết → duyệt → đăng chạy thông trong Admin Shell không tải lại trang: danh sách, trình soạn và Hộp duyệt cùng đọc/ghi kho bài mẫu `BSCW.store`.

## File
| File | Việc |
|---|---|
| `admin-components.css` (mới) | CSS component của hai trang chuẩn; `:where(:root){--fsp:0px}`; đầu file ghi khung chứa cần `container-type:inline-size` |
| `Chuẩn giao diện SaaS Starter.dc.html` | link `admin-components.css` ngay sau `admin-base.css`; `<style>` chỉ còn reset trang; sửa (a)–(d) |
| `Chuẩn giao diện - Component bổ sung.dc.html` | link file; `<style>` chỉ còn reset + `--fsp`; lớp trùng tên khoanh trong `[data-cs="sup"]` |
| `content-workflow.js` | CHỈ THÊM `BSCW.store` (kho bài mẫu) — khóa cũ giữ nguyên |
| `shell-screens.js` | màn nội dung → `mount`; thêm `<loại>.edit`, `p-workflow`; `embedId`, `shellSave`, `keepAlive`, `picker`, `goWorkflow` |
| `Admin Shell.dc.html` | mount 5 màn, Ctrl/⌘+S theo `embedId`, chặn rời màn, số Hộp duyệt đọc kho, Trình đơn giữ nguyên khi sang Khối tái dùng, nút phụ của thẻ |
| `Quản lý Bài viết - Options` · `Quản lý Trang - Options` · `Page Editor - Options` · `Hộp duyệt` | chỉ phần nhúng + đọc/ghi kho (không đổi bố cục, chữ, co giãn) |
| `_audit/d05-std.html` · `_audit/d05-run.html` | đo trang chuẩn · chạy luồng trong Shell |

## Mức hoàn thiện
Prototype tương tác. Kho chỉ sống trong phiên trang (bản thật: bảng `entries` + `entry_translations`).

## Màn & trạng thái (registry `shell-screens.js`)
| Khóa | Màn | File | Trạng thái |
|---|---|---|---|
| `posts/services/projects.list` | Danh sách | Quản lý Bài viết (prop `content-type`) | ready |
| `…create` · `…edit?slug=` | Thêm mới · Sửa | Quản lý Bài viết (màn Sửa) | ready |
| `pages.list` | Danh sách Trang | Quản lý Trang | ready |
| `pages.create` · `pages.edit?slug=` | Thêm/Sửa trang | Page Editor | ready |
| `review` | Hộp duyệt | Hộp duyệt (`workflow` từ Tweak Shell) | ready |
| `p-workflow` | Nền tảng › Website › Quy trình duyệt | Quy trình duyệt (`embedded`, Shell vẽ cụm Lưu) | ready |
| `menus` | Trình đơn | giữ mount khi sang `blocks` | ready |
| `media` | Thư viện ảnh & tệp | thẻ "Chờ thiết kế · D1-6" + "Mở trình chọn ảnh (bản hiện có)" → `MediaPicker` | chờ thiết kế |
| Cài đặt (6 panel đã nhúng) | Trình đơn · Email · Đăng nhập nhanh · Tích hợp & Theo dõi · Nút liên hệ nổi · Gói & mức dùng | giữ cách nhúng D0-4a | ready (không sửa panel) |

Trạng thái trống/đang tải/lỗi/không quyền/khóa theo gói: giữ của từng màn + thẻ của Shell (D0-4b).

## Tương tác
- Màn nhúng ẩn phần trùng Shell (thanh "1a/3a" của canvas, breadcrumb, h1); giữ thanh thao tác + nút quy trình. Khung canvas (lề 40px, viền, rộng 1240px) bỏ bằng `section[data-emb="1"]`.
- "Viết bài mới" / bút Sửa / "Danh sách" → `bs-goto {screen, params:{slug}, force:true}`; Shell đổi prop `start`/`slug`, không tải lại. Màn tự hỏi "Lưu/Bỏ" trước, `force` bỏ hộp chặn của Shell để không hỏi hai lần.
- `onDirty(0|1)` → Shell chặn rời màn (hộp Lưu / Bỏ / Ở lại) và `beforeunload`; Ctrl/⌘+S → `bs-embed-cmd {id: embedId, act:'save'}` (`content` · `page-editor` · `workflow` · `menus`). Màn nhúng tắt phím tắt riêng để không lưu hai lần.
- Hộp duyệt: Duyệt đăng / Trả lại ghi `st` vào kho → danh sách và số trên menu đổi ngay.

## Ánh xạ dữ liệu
- `BSCW.store` row: `type · slug (khóa công khai) · name · st · cat · langs · author · updated · trashed · img · schedAt/schedText · lastNote` (+ trang: `template · parent · views`). `st` = `entries.status` (migration `2012_…_expand_entry_status…`) hoặc bước quy trình (`WorkflowTemplateRegistry.php`).
- Hàng chờ = bước không phải initial/published/terminal của quy trình đang bật (`ApplyEntryWorkflowTransition.php`); lọc theo `BSCW.allowed(wf, st, nhóm quyền)` (`PermissionRegistry.php`).
- Loại nội dung + chuyên mục theo loại: `BSCW.store.meta(type)` (repo: `content_sections`, `taxonomies`).

## Quyền & gói
Nhóm quyền, chế độ duyệt, số ngôn ngữ, gói, "Lời mời nâng gói" đi từ Tweak của Shell qua props (`role` · `workflow` · `lang-codes` · `plan-key` · `upsell`); Tweak riêng của màn chỉ dùng khi mở riêng. Hộp duyệt ẩn khi website đăng thẳng hoặc nhóm quyền không duyệt được (D0-4b).

## Token · Icon
Không thêm màu mới; `admin-components.css` chỉ dùng token (giá trị dự phòng = tông 3b). Icon mới trên Shell: `ri-arrow-go-back-line` · `ri-image-2-line` · `ri-git-branch-line`.

## Chuỗi cần dịch (mới)
"Quay lại Trình đơn" · "Mở trình chọn ảnh (bản hiện có)" · "Xem Quy trình duyệt (bản hiện có)" · "Sửa {loại}" · "Đã chọn N ảnh — trang Thư viện đầy đủ sẽ có ở lượt D1-6" · "Không tìm thấy bài này — có thể đã chuyển vào Thùng rác." · Trợ giúp của `…edit` và `p-workflow`.

## Kết quả "Xong khi"
- (0) Đạt — `_audit/d05-std.html`: sơ đồ Mẫu 1 + Mẫu 8 ở 360/390/768/1440 × thường/Chữ lớn: 0 ô cắt, 0 từ vỡ; ô `--brand-*` 0 cắt (bỏ `…`, xuống dòng); bảng định dạng = lưới `role="table"` (hết 6 cảnh báo "never resolved"); tên tệp đang tải cắt giữa, giữ đuôi ("anh-san-pham-qua…dai.jpg"), bấm tên để xem đầy đủ trên cảm ứng.
- (1) Đạt — hai trang link file, `<style>` chỉ còn reset; `scrollWidth = clientWidth` ở 360 (345/345), 390 (375/375), 768 (753/753), 1440 (1425/1425) × thường + Chữ lớn.
- (2) Đạt — `_audit/d05-run.html`: Người viết + "Duyệt trước khi đăng": Tin tức › Danh sách → Viết bài mới (`posts.create`) → Lưu nháp (kho: `/tin-tuc/bai-thu-d0-5-gui-duyet` · draft) → Gửi duyệt (pending) → Tweak Chủ website: menu Hộp duyệt số **1** → Duyệt đăng (published, menu hết số) → Tin tức › Danh sách thấy "Đã đăng"; không tải lại; 0 lỗi console.
- (3) Đạt — Trình đơn → Khối tái dùng (`blocks?region=menu`, thẻ "Để sau") → "Quay lại Trình đơn": cùng phiên bản màn (giữ thay đổi).
- (4) Đạt — Cài đặt (2 ô → "2 thay đổi chưa lưu", hộp Lưu/Bỏ/Ở lại) giữ của D0-4a phần 2; Ctrl+S ở `posts.create` gửi `{"id":"content","act":"save"}`, trình soạn hết "chưa lưu".
- (5) Đạt — màn toàn trang chỉ còn 1 h1 (của Shell); thanh thao tác + nút quy trình còn; Trợ giúp đọc `help` của registry theo đúng khóa màn.
- (6) Đạt — Bản đồ màn hình khớp registry; TODO đóng các dòng "· D0-5"; lượt kế tiếp D0-6a.

## 12 tiêu chí dễ dùng (phần lượt này chạm)
1 Đạt (tiêu đề + mô tả của Shell, 1 nút chính của màn) · 2 Đạt (Tin tức › Thêm mới: 1 bước từ menu) · 3 Đạt · 4 Đạt · 5 Giữ nguyên màn · 6 Đạt (chặn rời màn khi chưa lưu, Ctrl+S) · 7 Đạt (chip trạng thái BSCW.UI) · 8 Chưa kiểm hết — màn nhúng giữ co giãn cũ (D0-6a / D1-3 / D1-4b) · 9 Chưa — Page Editor/Quản lý Trang dưới 768 chưa thành thẻ gọn (D1-3, D1-4b; TODO đã ghi) · 10 Đạt · 11 Chưa — màn nhúng chưa dùng `--fsp` (Shell còn `zoom`, D0-6a) · 12 Đạt.

## Kiểm hiển thị
| Bề rộng | Chrome (môi trường thiết kế) | Safari iOS / Firefox / Samsung |
|---|---|---|
| Trang chuẩn 360 · 390 · 768 · 1440 × thường/Chữ lớn | Đạt (đo script) | Chưa kiểm — Claude Code (zip) |
| Shell + màn nhúng 1440 | Đạt (chạy luồng) | Chưa kiểm |
| Shell + màn nhúng 390 · 768 | Chưa kiểm — co giãn màn nhúng thuộc D0-6a/D1-3/D1-4b | Chưa kiểm |

Verifier: xem dòng cuối.

## Việc cho backend
- Kho bài thật: `entries` (status/bước) + `entry_translations`; API danh sách theo loại + hàng chờ duyệt theo nhóm quyền (`entries.review|approve`) + số đếm cho menu · nhận bài bằng loại + đường dẫn.
- Sự kiện đổi bài cho các tab đang mở (Livewire `dispatch('entry-changed')` hoặc broadcast) để danh sách/Hộp duyệt/menu tự cập nhật.
- Nền tảng › Website › Quy trình duyệt: lưu theo website + loại nội dung (đã ghi ở README Quy trình duyệt).

## Verifier
[CHƯA KIỂM ĐƯỢC] nếu công cụ chụp quá giờ — Claude Code kiểm bằng zip.
