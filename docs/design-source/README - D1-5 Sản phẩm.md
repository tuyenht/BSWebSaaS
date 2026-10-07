# D1-5 · Sản phẩm — danh sách + trình sửa (07/10/2026)

## Tổng quan
Hai màn MỚI cho module Sản phẩm (bảng riêng `products`, không quy trình duyệt, không Hẹn giờ đăng, không Không công khai, không người tạo):
- **Danh sách** theo khuôn mẫu 1 (`Quản lý Bài viết - Options`) + mẫu Sản phẩm của `#co-gian` — chỉ thêm cột/bộ lọc riêng.
- **Trình sửa** theo mẫu 3 (`#mau-3`): mặc định gọn = Tên · Ảnh · Giá · Mô tả + thẻ Đăng; phần còn lại trong "Thêm tùy chọn" (nhớ theo người ở `bs-ui.editorMore`).
- Tweak **"Chế độ sản phẩm"** Giới thiệu ↔ Bán hàng ở cả hai màn.

## File
| File | Việc |
|---|---|
| `Sản phẩm - Danh sách.dc.html` | MỚI — danh sách, Thùng rác, hàng loạt, thẻ gọn < 768 khung chứa |
| `Sản phẩm - Trình sửa.dc.html` | MỚI — trình sửa; `Trình soạn nội dung` + `MediaPicker` bằng `dc-import` (không chép) |
| `shell-screens.js` | `catalog.list` · `catalog.create` → mount/ready; thêm `catalog.edit` (mở bằng `bs-goto {screen:'catalog.edit', params:{ma:<ulid>}}`) |
| `Admin Shell.dc.html` | CHỈ khối gắn: 2 `<sc-if>` + `dc-import … embedded`, cờ `mCatList`/`mCatEd`, `onEmbedDirtyCl`/`Ce`, `catModOn` |
| `SaaS Starter - Bản đồ màn hình.md` | dòng 20–21 → ready |
| `TODO.md` · `LESSONS.md` | khối D1-5, 3 dòng bài học |

Link CSS: `admin-base.css` → `admin-components.css` (ngay sau) → `<style>` riêng (chỉ lớp của màn: `ql-*` theo khuôn D1-3, `sp-*`, `spe-*`). Không khai lại component của `admin-components.css`.

## Mức hoàn thiện
Hi-fi, tương tác chạy thật trên kho mẫu `window.__bsCatalog` (dùng chung giữa 2 màn khi nhúng trong Shell; phát `bs-catalog-store`). 12 sản phẩm + 2 trong Thùng rác, có trường hợp xấu (tên 82 ký tự, giá 1.180.000.000 ₫, giá trống, giá 0, không ảnh, không SKU, chuyên mục tên dài).

## Màn & trạng thái
| Trạng thái | Danh sách | Trình sửa |
|---|---|---|
| Có dữ liệu | ✓ | ✓ |
| Trống | "Chưa có sản phẩm nào" + **Thêm sản phẩm đầu tiên** | Thêm mới = form trống |
| Đang tải | khung xám 6 dòng | khung xám |
| Lỗi + Thử lại | ✓ (Tweak) | ✓ (Tweak) |
| Không có quyền | Người viết / Cộng tác viên: "Bạn chưa được xem sản phẩm" + Về Tổng quan | ✓ |
| Chỉ được xem | không Thêm, không ô chọn, không hàng loạt; nút dòng "Xem" | dải "Chỉ được xem", mọi ô chỉ đọc, nút "Quay lại danh sách" |
| Khóa theo gói | Tweak "Module Sản phẩm bật" tắt → theo "Lời mời nâng gói": TẮT "Chưa bật cho website này — liên hệ hỗ trợ" · BẬT lợi ích + "Xem gói nâng cấp" | như danh sách |
| Lưu thành công | toast + Hoàn tác 8 giây | `save-status` "Đã lưu lúc …" + toast |
| Lỗi nhập | đường dẫn trùng báo tại ô (sửa tại dòng) | SKU trùng (kể cả Thùng rác) · đường dẫn trùng (trang có sẵn / chuyên mục / sản phẩm) · giá khuyến mãi ≥ giá bán · ngày kết thúc trước ngày bắt đầu · chuyên mục không hợp lệ (Tweak) · mất mạng (Tweak, có Thử lại) |
| Không tìm thấy / đang ở Thùng rác | — | "Không tìm thấy sản phẩm này" · "Sản phẩm này đang ở Thùng rác" + Khôi phục |

## Tương tác
**Danh sách**: tab Tất cả · Đã đăng · Bản nháp · Đã gỡ khỏi website · Thùng rác (số đếm); tìm theo tên / SKU (không dấu); lọc Chuyên mục (cây, gồm mục con) · Nhãn (Nổi bật/Bán chạy) · Tồn kho (chỉ Bán hàng) — chân "Bỏ lọc · Xong"; dòng "Đang lọc … Bỏ lọc · Khớp N / M sản phẩm"; dưới 1024 gộp thành Lọc · Sắp xếp · Chọn cả trang; chọn cột (Sản phẩm, Trạng thái khóa; Giá/Tồn kho khóa ở Giới thiệu); sắp xếp đầu cột (Tên, Giá, Tồn kho, Cập nhật). Dòng: ảnh nhỏ · tên (sửa nhanh) · SKU · đường dẫn sửa tại chỗ · chấm ngôn ngữ (bấm → Sửa/Thêm bản) · chuyên mục chính · giá (khuyến mãi + giá gốc gạch; trống "Liên hệ báo giá"; 0 "0 ₫") · tồn kho (0 = "Hết hàng", ≤5 vàng) · nhãn icon + chữ · trạng thái (menu Đăng / Gỡ khỏi website / Đưa về nháp) + "Cập nhật …". "⋯": Xem trên website · Sửa đường dẫn | Gắn/Bỏ nhãn · Đổi chuyên mục… | Chuyển vào Thùng rác (đỏ, cuối). Hàng loạt: "Đăng (N)" · "Gỡ khỏi website (N)" · "Đổi chuyên mục (N)" · "Chuyển vào Thùng rác (N)" + câu bỏ qua. Thùng rác: Khôi phục (về Bản nháp) · Xóa vĩnh viễn (hộp xác nhận, Hủy được focus, "Mã SKU và đường dẫn sẽ được giải phóng") · "Còn N ngày trước khi tự xóa". Esc đóng menu/hộp, trả focus về nút đã mở. Chân bảng Dòng/trang 10/20/50 (mặc định 20 như repo) + số trang.

**Trình sửa**: thanh dính (save-status · Lưu nháp / ⋯ · nút chính "Đăng" hoặc "Cập nhật"); bản nháp tự lưu sau 3 giây, sản phẩm đang đăng không tự lưu (thay đổi lên website ngay) → bấm Cập nhật; chặn rời trang khi còn thay đổi; `onDirty(n)` số thay đổi; nghe `bs-embed-cmd {save|reset|firstError}`. Thanh ngôn ngữ (`.lbw/.lb` của component chung): pill → `+N` → ô chọn theo container query, sao vàng bản nguồn, chấm 5 trạng thái, panel có tìm từ 6 ngôn ngữ, "Dịch N bản còn thiếu" + "Quản lý ngôn ngữ"; dòng trạng thái §10. Trường theo ngôn ngữ (chip mã): tên, đường dẫn, tóm tắt, mô tả, Google. Trường dùng chung khóa + chip "Chung" ở bản khác nguồn: ảnh, giá, khuyến mãi, tồn kho, SKU, nhãn, chuyên mục. العربية: ô chữ tự `dir="rtl"`, đường dẫn giữ ltr. Ảnh (#h-tr): chọn bằng MediaPicker, kéo-thả + "Chuyển lên / Chuyển xuống / Đặt làm ảnh đại diện / Bỏ khỏi sản phẩm" (Hoàn tác), khung fit theo tỷ lệ thật (ngang/vuông/dọc). Giá (#h-mo): gõ 1250000 → "1.250.000" + "₫", dòng "Khách thấy: …". Mô tả = ô soạn chung + "Chèn bảng thông số". Chuyên mục (#h-ca): ô tìm luôn hiện, cây / danh sách phẳng có đường cha khi tìm, sao "Mục chính". Google: search-preview + 2 length-field (fallback từ tên / tóm tắt).

**AI (C.15, §7, §15)** — chỉ khi website được cấp AI: "Gợi ý mô tả ngắn" (ô trống → điền thẳng + "Hoàn tác gợi ý AI"; có chữ → so "Chữ hiện tại" cạnh "Gợi ý của AI"), "Viết mới" (chỉ khi mô tả trống; có chữ → khóa + câu lý do hiện sẵn), "Dịch sang N ngôn ngữ" / "Dịch bản <tên>" (chỉ điền ô trống, chi phí = số từ thật `BSAI.translateCost`, chặn trước khi bấm, việc AI đang chạy có Hủy — "không trừ từ AI nào"). Không cấp AI: "Lời mời nâng gói" TẮT → ẩn hẳn; BẬT → nút khóa mở Cài đặt › Gói & mức dùng.

## Ánh xạ dữ liệu
| Thiết kế | Repo |
|---|---|
| `ma` (ulid) trong `bs-goto` / hash | `products.ulid` — `database/migrations/2009_01_01_000000_create_catalog_tables.php` |
| SKU duy nhất kể cả Thùng rác | `products.sku` unique theo website (soft delete vẫn giữ) — cùng migration |
| giá · giá khuyến mãi · bắt đầu/kết thúc (số nguyên đồng, trống = Liên hệ báo giá) | `products.price`, `sale_price`, `sale_starts_at`, `sale_ends_at` — 2009 + `2010…`, `2013…` |
| tồn kho (trống = không theo dõi, không âm) · nổi bật · bán chạy | `products.stock`, `is_featured`, `is_bestseller` — 2013… |
| trạng thái draft/published/archived · ngày đăng tự ghi · Thùng rác | `products.status`, `published_at`, `deleted_at` |
| tên · đường dẫn · tóm tắt · mô tả · tiêu đề/mô tả Google theo ngôn ngữ | `product_translations` |
| ảnh + thứ tự, ảnh đầu = đại diện | `product_images` (`sort`) |
| chuyên mục (nhiều, có thứ tự) | `product_taxonomies` — repo mới sửa 1 |
| Quyền xem / quản lý | `catalog.view` · `catalog.manage` — `app/Modules/Catalog/Policies/CatalogPolicy.php`, đọc qua `BSCW.can` |
| Chế độ Giới thiệu / Bán hàng (chưa đặt = Giới thiệu) | `app/Modules/Catalog/Support/CatalogMode.php` |
| Danh sách tìm tên/SKU, 20/trang, Thùng rác | `app/Modules/Admin/Livewire/Catalog/Index.php` |
| Trình sửa (mặc định 1 ngôn ngữ, 1 chuyên mục) | `app/Modules/Admin/Livewire/Catalog/Editor.php` |
| Chỉ xem bị chặn khỏi trình sửa | `routes/web.php:226` |
| Module bật/tắt + lợi ích | `ModuleCatalog.php` → `BSAI.MODULES.catalog` |

## Quyền & gói (ai thấy gì)
| Nhóm quyền | Danh sách | Trình sửa |
|---|---|---|
| Chủ website · Quản trị | đủ + nút "Đổi ở Cài đặt › Sản phẩm" (`settings.manage`) | đủ |
| Biên tập | đủ (không có nút Cài đặt) | đủ |
| Người viết · Cộng tác viên | "Bạn chưa được xem sản phẩm" | như trái |
| Chỉ xem | xem, lọc, mở "Xem"; ẩn Thêm / chọn / hàng loạt / Thùng rác | "Chỉ được xem" |
Module tắt → khóa theo "Lời mời nâng gói". AI theo "Website được cấp AI" × "Lời mời nâng gói".

## Token
Chỉ `var(--…)`: `--color-surface/-muted/-hover`, `--color-body`, `--color-border/-strong/-input`, `--color-heading`, `--color-body-text`, `--color-body-muted`, `--color-accent`, `--color-admin-primary`, `--color-admin-{success,warning,danger,info,secondary,dark}-text`, `--color-admin-{success,warning,danger,info}` (chấm, vạch), `--color-brand-gold` / `-gold-text`, `--focus-*` (từ `admin-base.css`). Grep 2 tệp: **0 hex ngoài `var(…)`**.

## Icon (Remix 4.9.1)
ri-add-line · ri-search-line · ri-filter-3-line · ri-arrow-up-down-line · ri-checkbox-multiple-line · ri-layout-column-line · ri-list-check · ri-global-line · ri-draft-line · ri-archive-line · ri-delete-bin-line · ri-delete-bin-2-line · ri-arrow-go-back-line · ri-pencil-line · ri-eye-line · ri-more-2-fill · ri-external-link-line · ri-link · ri-star-line/-fill · ri-fire-line · ri-folder-3-line · ri-folder-transfer-line · ri-folder-settings-line · ri-price-tag-3-line · ri-stack-line · ri-image-2-line · ri-image-add-line · ri-close-circle-line · ri-checkbox-circle-line · ri-error-warning-line · ri-information-line · ri-time-line · ri-wifi-off-line · ri-refresh-line · ri-lock-line/-2-line · ri-vip-crown-2-line · ri-customer-service-2-line · ri-shield-user-line · ri-shopping-bag-3-line · ri-presentation-line · ri-settings-3-line · ri-translate-2 · ri-sparkling-2-line · ri-table-line · ri-route-line · ri-history-line · ri-save-3-line · ri-loader-4-line · ri-arrow-left/right-line.

## Chuỗi cần dịch (`lang/vi.json` — đổi theo Bảng thuật ngữ)
| Khóa | Hiện | Đổi thành |
|---|---|---|
| `catalog.status_archived` | Lưu trữ | Đã gỡ khỏi website |
| `catalog.status_draft` | Nháp | Bản nháp |
| `catalog.category_label` | Danh mục | Chuyên mục |
| `catalog.mode_brochure_label` | (brochure) | Giới thiệu |
| mới | — | Đã đăng · Đăng · Gỡ khỏi website · Đưa về nháp · Chuyển vào Thùng rác · Khôi phục · Xóa vĩnh viễn · Liên hệ báo giá · Hết hàng · Còn {n} · Không theo dõi · Nổi bật · Bán chạy · Chuyên mục chính · Mục chính · Thêm tùy chọn · Chèn bảng thông số · Gợi ý mô tả ngắn · Viết mới · Chỉ được xem · Mã SKU · Giá khuyến mãi · Tồn kho · Chế độ Giới thiệu · Chế độ Bán hàng · các câu lỗi ở mục "Màn & trạng thái" |

## Màn đang chạy · Bản mới · Giữ bản nào · Vì sao
| Màn đang chạy (repo) | Bản mới | Giữ | Vì sao |
|---|---|---|---|
| Index: tìm tên/SKU, 20/trang, Thùng rác | giữ đủ + tab trạng thái, lọc, hàng loạt, sửa tại dòng | Bản mới (giữ mặc định 20/trang) | khuôn mẫu 1 đã chốt |
| Editor 1 ngôn ngữ, 1 chuyên mục | thanh ngôn ngữ, nhiều chuyên mục + mục chính | Bản mới | luật §2, §5, #h-ca |
Không bớt / dời điều khiển nào của màn đang chạy.

## Kết quả 12 tiêu chí dễ dùng
1 Đạt — tiêu đề + 1 dòng mô tả + 1 nút chính (Thêm sản phẩm / Đăng–Cập nhật). · 2 Đạt — Thêm: menu → Thêm mới → nhập tên/giá → Đăng (3). · 3 Đạt — không thuật ngữ trần (SKU giữ vì là tên quen trong bán hàng, có câu giải thích dưới ô). · 4 Đạt — nút icon đứng riêng (⋯, đóng, trang trước/sau, sửa nhanh) có tooltip + aria-label. · 5 Đạt — ví dụ thật trong ô; lỗi nói cách sửa. · 6 Đạt — tự lưu nháp, chặn rời trang, Thùng rác + Hoàn tác 8 giây, xác nhận chỉ cho Xóa vĩnh viễn. · 7 Đạt — trạng thái/tồn kho/nhãn/chấm ngôn ngữ đều màu + icon + chữ. · 8 Đạt — nút/chip/tab 1 dòng (… + tooltip), trang không cuộn ngang (đo bên dưới). · 9 Đạt (Chromium) — thẻ gọn < 768, menu trượt đáy, thanh hàng loạt dính đáy. · 10 Đạt — mặc định gọn + "Thêm tùy chọn"; người thạo có lọc, sắp xếp, chọn cột, hàng loạt. · 11 Đạt — Chữ lớn đo 0 tràn. · 12 Đạt — từ theo Bảng thuật ngữ (grep 0 "Danh mục", "Lưu trữ", "tuỳ", "khoá", "xoá").

## Kết quả Chất lượng hoàn thiện (A–E)
- A Đạt — thang 4/8, cỡ chữ của DS, chuyển động 160 ms tắt khi reduced-motion, khung tải giữ chỗ.
- B Đạt — chữ ≥ 14px (chú thích 12px), nhãn luôn hiện, kéo-thả ảnh có nút thay thế, phản hồi < 1 giây, chặn bấm 2 lần (`busy`), mất mạng giữ chữ + Thử lại.
- C Đạt một phần — 1 h1 (ẩn khi nhúng, Shell giữ h1), Esc đóng menu/hộp, trả focus, hộp có tên, toast `aria-live`. Tab qua toàn trang: [CHƯA KIỂM ĐƯỢC — môi trường thiết kế không giả lập phím Tab].
- D Đạt — [ĐO BẰNG SCRIPT] `H.measure` sáng + tối (`data-bs-theme="dark"`) ở 1440 và 390: tương phản 0 lỗi sau sửa (chip "Bán chạy" 4,49 → nền 6%); ô ngày dùng bộ chọn gốc.
- E [ĐO BẰNG SCRIPT] `_audit/harness.html` — xem bảng dưới; console 0 lỗi ở 2 tệp và Admin Shell. Vùng bấm 44px chỉ áp ở `pointer:coarse` (+ `admin-base.css`): [CHƯA KIỂM ĐƯỢC — iframe harness là pointer:fine].

## Kiểm hiển thị
`H.layouts([2 tệp],[360,390,768,1024,1440])` × chữ thường + Chữ lớn (`bs-ui.fontSize='large'`, trả lại giá trị cũ bằng setItem). `sw` = scrollWidth của trang; luôn = vw − 15 (thanh cuộn) → 0 tràn.
| Bề rộng | Danh sách thường / lớn | Trình sửa thường / lớn |
|---|---|---|
| 360 | 345 / 345 ✓ | 345 / 345 ✓ |
| 390 | 375 / 375 ✓ | 375 / 375 ✓ |
| 768 | 753 / 753 ✓ | 753 / 753 ✓ |
| 1024 | 1009 / 1009 ✓ | 1009 / 1009 ✓ |
| 1440 | 1425 / 1425 ✓ | 1425 / 1425 ✓ |
Trong Shell: `H.mk('s',390,844,0,0,'../Admin Shell.dc.html#/site/catalog.list')` sw 390 = cw 390 ✓ · `#/site/catalog.edit?ma=…` 390 = 390 ✓ · `#/site/catalog.create` 390 = 390 ✓.
Lỗi đã sửa trong lượt: tên chuyên mục dài (`nowrap`) ở thẻ gọn tràn 28px; 3 nút "Lọc · Sắp xếp · Chọn cả trang" ở 360 tràn; khung xem 390/768 kéo rộng trang < 1200.
| Trình duyệt | Kết quả |
|---|---|
| Chrome (môi trường thiết kế) | đạt |
| Safari iPhone/iPad · Firefox · Samsung Internet · xoay ngang 844×390 · 200% · cảm ứng thật | [CHƯA KIỂM ĐƯỢC] — Claude Code kiểm bằng zip |

## Việc cho backend
| Tính năng | Dữ liệu | Luật |
|---|---|---|
| Kiểm giá khuyến mãi < giá bán, kết thúc > bắt đầu | `sale_price`, `sale_starts_at/ends_at` | báo tại ô, không lưu (repo chưa kiểm) |
| Nhiều chuyên mục + chuyên mục chính (#h-ca) | `product_taxonomies` (sort, cờ chính) | mục đầu = chính; đổi chính của sản phẩm đã đăng → tự chuyển hướng |
| Chỉ xem mở được trình sửa ở chế độ đọc | `catalog.view` | bỏ chặn `routes/web.php:226`, trả form chỉ đọc |
| Trình sửa nhiều ngôn ngữ | `product_translations` cho mọi `siteLangs` | trường dùng chung chỉ sửa ở bản nguồn |
| Tự xóa vĩnh viễn sau 30 ngày | lệnh dọn Thùng rác sản phẩm | giải phóng SKU + đường dẫn |
| Đổi trạng thái tại dòng · hàng loạt Đăng / Gỡ / Đổi chuyên mục / Thùng rác · Khôi phục về Bản nháp | — | bỏ qua dòng không làm được, trả số đã làm |
| Sửa nhanh tên / đường dẫn tại dòng | — | kiểm trùng như trình sửa |
| Đường dẫn trùng trang / chuyên mục có sẵn → báo tại ô | — | as-built 24/09 đã có; trả mã lỗi để màn dịch câu đời thường |
| SKU trùng kể cả Thùng rác → câu nêu tên sản phẩm đang giữ mã | — | |
| AI cho sản phẩm (Gợi ý mô tả ngắn, Viết mới, Dịch) | sổ `words` | §7 / §15: không sinh chữ thì không trừ |
| Chế độ Giới thiệu/Bán hàng truyền vào 2 màn khi nhúng | `CatalogMode` | Shell chưa truyền `mode` → trong Shell 2 màn dùng mặc định Tweak (Bán hàng); bản thật đọc `CatalogMode` (chưa đặt = Giới thiệu) |

## Verifier
Xem dòng cuối của lượt (cập nhật sau khi verifier trả lời).
