# README — D1-1 Chuyên mục (06/10/2026)

## Tổng quan
Một màn quản lý cây chuyên mục cho từng loại nội dung (Tin tức, Dịch vụ, Dự án… — lọc theo `?section=`) và cho Sản phẩm (bộ `product_cat`). Mẫu trang: mẫu 1 (Danh sách) của `Chuẩn giao diện SaaS Starter.dc.html`, phần thân là cây thay cho bảng. Đường ghi đã có trong repo (`app/Modules/Cms/Actions/Taxonomies/{Create,Update,Move,Delete}Taxonomy.php`) → tạo, đổi tên, đổi đường dẫn, chuyển, xóa KHÔNG gắn "Chờ backend".

Gộp luồng phụ D0-6c: không có `README - D0-6c Bộ AI.md` (D0-6c đã gộp trong `README - D0-6 Hiển thị đa thiết bị.md`) → bỏ qua.

## File
- `Chuyên mục.dc.html` (mới) — link `admin-base.css` → `admin-components.css`, không tự khai lại component.
- `shell-screens.js` — khóa sẵn có `<loại>.cats` (TYPE.cats) và `catalog.cats`: `kind: 'mount'`, `status: 'ready'`, `dc: 'Chuyên mục'`, `embedId: 'taxonomy'`, `repoKey` (`<loại>.taxonomy` / `catalog.taxonomy`).
- `Admin Shell.dc.html` (sửa theo yêu cầu owner) — khe `<dc-import name="Chuyên mục" embedded embed-id="taxonomy" content-type role lang-codes upsell on-dirty>` mount khi `<loại>.cats` / `catalog.cats` đang mở; `catalog.cats` → `content-type="products"`; `onEmbedDirtyTx = mkEmbedDirty('taxonomy')` chặn rời màn khi panel chưa lưu. Khóa theo gói do Shell xử lý trước khi mount.
- `SaaS Starter - Bản đồ màn hình.md` dòng 15 → ready.

## Mức hoàn thiện
Hi-fi, tương tác thật trên dữ liệu mẫu (cây, kéo-thả, menu ⋯, panel, xóa/gộp, toast Hoàn tác cho chuyển chỗ). Số xem trước khi xóa là số mẫu (Chờ backend).

## Màn & trạng thái
Tweak "Trạng thái màn" (`screenState`) + các Tweak khác:
- Có dữ liệu · Trống lần đầu ("Tạo chuyên mục đầu tiên" + 3 ví dụ theo loại, bấm là tạo) · Đang tải (khung xương tĩnh, không chạy animation — LESSONS) · Lỗi + "Thử lại".
- Không có quyền: nhóm quyền thiếu `taxonomies.manage` (Người viết, Cộng tác viên, Chỉ xem — đọc `BSCW.can`).
- Khóa theo gói: `section=products` + `catalogOn=false`; Tweak "Lời mời nâng gói" TẮT → "Chưa bật cho website này — liên hệ hỗ trợ"; BẬT → 1 dòng lợi ích (`BSAI.MODULES.catalog`) + "Xem gói nâng cấp" (`bs-goto settings {section:'plan'}`).
- Site 1 ngôn ngữ (`langCount=1`): ẩn thanh ngôn ngữ, chip mã, cột chấm ngôn ngữ. 8 ngôn ngữ: có العربية — ô tên/mô tả/SEO tự `dir="rtl"`, đường dẫn luôn `ltr`.
- Thành công: toast `role="status"` nói kết quả bằng số.
- Tweak "Mô phỏng lỗi khi lưu": `network` (giữ chữ, "Thử lại") · `taxonomy_invalid` ("Tải lại").
- Tweak "Hiện ghi chú thiết kế" (mặc định bật) gom mọi nhãn "Chờ backend"/"Cần owner chốt". Tweak "Xem khung 390 · 768": ba khung 390 / 768 / 1240 cạnh nhau.

## Tương tác
1. Cây: cấp 1 = chuyên mục gốc của loại (chip "Gốc", không kéo, không xóa). Kéo ⠿ (chuột): trên 28% dòng = đặt trước, dưới 28% = đặt sau, giữa = đưa vào trong. Không cần kéo: ⋯ › Chuyển lên · Chuyển xuống · Đưa vào trong… · Đưa ra ngoài một cấp. Dưới 600px khung chứa: không kéo, menu ⋯ là tấm trượt đáy.
   Vượt 3 cấp (TaxonomyRules::MAX_DEPTH) → toast "Chỉ được lồng tối đa 3 cấp" + lý do theo vị trí thật, cây giữ nguyên. Hộp "Đưa vào trong…" liệt kê mọi chỗ, chỗ hết cấp ghi "hết chỗ: chỉ được lồng tối đa 3 cấp"; chọn vào thì báo lỗi, nút chính khóa.
   Chuyển sang nhánh của loại khác → hỏi: "N bài sẽ chuyển từ Tin tức sang Dịch vụ — quyền và quy trình duyệt sẽ theo Dịch vụ". Sản phẩm không có câu này.
   Đổi chỗ/chuyển cha xong: toast "Đã cập nhật địa chỉ N bài, tự tạo chuyển hướng" + "Hoàn tác" 8 giây.
   Tìm kiếm tự hiện khi loại có > 15 chuyên mục (mẫu Sản phẩm có 17); lọc theo tên mọi bản và đường dẫn, giữ nhánh cha. Mở tất cả / Thu gọn tất cả.
2. Panel thêm/sửa: máy tính trượt từ phải (`.ov.right .drw`), dưới 480px tràn toàn màn. Thanh ngôn ngữ: giữ bản nguồn (sao vàng) + bản đang sửa, phần còn lại vào "+N" (danh sách có tìm từ 6 ngôn ngữ, "Quản lý ngôn ngữ" → `bs-goto settings {section:'languages'}`), bên phải chip "Còn N từ AI" + nút Dịch (§9: ở nguồn "Dịch sang N ngôn ngữ", ở đích "Dịch bản <tên>", chỉ điền ô trống, tooltip nêu số từ sẽ dùng và còn lại). Dòng trạng thái §10 dưới thanh.
   Tên (bắt buộc ở bản nguồn) · Đường dẫn trong khung có tiền tố `data-focus-wrap` (tự sinh từ tên, sửa được, kiểm `slug_invalid`/`slug_taken` ngay tại ô; nút "Tạo lại từ tên"); chuyên mục đang có bài: báo trước "Đổi đường dẫn của chuyên mục đang có bài sẽ tự tạo chuyển hướng", khi đã đổi thì nói số bài sẽ đổi địa chỉ (LESSONS #12) · Chuyên mục cha: chỉ liệt kê chỗ còn đủ cấp (cấp chỗ đặt + chiều cao nhánh ≤ 3); gốc của loại thì khóa · "Thêm tùy chọn": mẫu trang danh sách + chi tiết (mặc định "Theo giao diện"), mô tả, ảnh, tiêu đề/mô tả Google (Chờ backend).
   Nút lưu: "Thêm chuyên mục" / "Lưu" / "Lưu và chuyển N bài" (đổi loại) · "Đang lưu…" chặn bấm 2 lần. Đóng khi chưa lưu → hỏi "Bỏ thay đổi / Tiếp tục sửa"; chặn rời trang (`beforeunload`).
3. Xóa (⋯ › Xóa) và Gộp (⋯ › Gộp vào chuyên mục khác — cùng luồng, bài buộc chuyển sang chuyên mục đích): hộp giữa màn kiểu SweetAlert2, nút Hủy được focus. Mục con: "Đưa lên cấp trên" (mặc định) / "Xóa theo". Bài: "Chuyển sang chuyên mục …" (mặc định = cha) / "Bỏ gắn". Hệ quả bằng số: chuyển hướng trang chuyên mục (đích → cha gần nhất → trang chủ), số bài đổi địa chỉ, số chuyển hướng tạo, mục trình đơn trỏ theo đích / tự ẩn. Nút ghi đúng việc ("Xóa và chuyển 24 bài", "Xóa 3 chuyên mục và chuyển 39 bài", "Gộp và chuyển 24 bài"). Không vào Thùng rác, không toast Hoàn tác (không hoàn tác được).
4. Sản phẩm: cùng màn, `contentType='products'` → bộ `product_cat`, không có chuyên mục gốc, có "Cấp cao nhất", không có câu đổi quyền/quy trình.
5. Bàn phím: Esc đóng lớp trên cùng; Tab bị giữ trong hộp đang mở; đóng thì trả focus về nút đã mở; mỗi lúc một menu.

## Ánh xạ dữ liệu
| Thiết kế | Repo |
|---|---|
| Bộ phân loại post_cat / product_cat, cha, thứ tự, mẫu danh sách/chi tiết | `database/migrations/2002_01_01_000000_create_taxonomy_tables.php` bảng `taxonomies` |
| Tên + đường dẫn theo ngôn ngữ | bảng `taxonomy_translations` |
| Số bài / sản phẩm | `entry_taxonomies` / `product_taxonomies`, thứ tự ≥ 1000 là thẻ lọc (`HasCategoryTaxonomies::FACET_POSITION_BASE`) — không tính |
| Chuyên mục gốc của loại | `content_sections.taxonomy_id` |
| Luật 3 cấp, đường dẫn duy nhất theo ngôn ngữ | `app/Modules/Cms/Taxonomy/TaxonomyRules.php` (`MAX_DEPTH`) |
| Tạo / sửa / chuyển / xóa | `app/Modules/Cms/Actions/Taxonomies/{Create,Update,Move,Delete}Taxonomy.php` |
| Mã lỗi | `app/Modules/Cms/Exceptions/TaxonomyChangeRejectedException.php` |
| Menu "Chuyên mục" theo loại + Sản phẩm | `app/Modules/Admin/Navigation/AdminNav.php:116-124,162-170` |
| Trang hiện "sắp có" | `routes/web.php:236` (`admin.taxonomies.index`) |
Chuyên mục nhận ra bằng loại + đường dẫn: `bs-goto` chỉ gửi `{screen:'<loại>.list'|'catalog.list', params:{cat:'<đường-dẫn>'}}`, không id số. Khóa `k` trong dữ liệu mẫu chỉ là khóa nội bộ của prototype, không hiện ra màn và không vào params/hash.

## Quyền & gói
- `taxonomies.manage` (BSCW): Chủ website, Quản trị, Biên tập → dùng đủ; nhóm khác → trạng thái "không có quyền" + "Về danh sách …".
- Sản phẩm cần module `catalog`; khóa theo Tweak "Lời mời nâng gói" như trên.
- Nút Dịch hiện khi website có ≥ 2 ngôn ngữ; số từ đọc `BSAI.plan()`; hết từ → nút khóa, tooltip nêu cách mua thêm (Lời mời nâng gói BẬT) hoặc "Cần thêm? Liên hệ hỗ trợ" (TẮT).

## Token
Chỉ token qua `admin-base.css` / `admin-components.css`: `--color-surface/-muted/-hover`, `--color-border/-strong/-input`, `--color-heading`, `--color-body-text`, `--color-body-muted`, `--color-accent`, `--color-admin-primary`, `--color-admin-{danger,warning,info,success}{,-text}`, `--color-brand-gold`, `--color-placeholder`. Grep tệp: 0 mã hex ngoài `var(…)`, 0 `style-focus-within`, 0 id số trong `bs-goto`/hash. Chữ lớn: `--fsp:2px` trên khung gốc (Tweak hoặc `bs-ui.fontSize='large'`).

## Icon (Remix 4.9.1)
ri-add-line · ri-arrow-right-s-line · ri-arrow-down-s-line · ri-arrow-up-s-line · ri-draggable · ri-more-2-fill · ri-pencil-line · ri-arrow-up-line · ri-arrow-down-line · ri-corner-down-right-line · ri-corner-up-left-line · ri-file-list-3-line · ri-external-link-line · ri-git-merge-line · ri-delete-bin-line · ri-home-4-line · ri-search-line · ri-expand-up-down-line · ri-collapse-vertical-line · ri-translate-2 · ri-star-fill · ri-sparkling-2-line · ri-close-line · ri-save-3-line · ri-loader-4-line · ri-lock-2-line · ri-lock-line · ri-wifi-off-line · ri-refresh-line · ri-folders-line · ri-image-add-line · ri-route-line · ri-error-warning-line · ri-checkbox-circle-line · ri-information-line · ri-vip-crown-line · ri-customer-service-2-line · ri-settings-3-line · ri-tools-line · ri-question-line

## Chuỗi cần dịch — câu đời thường cho 16 mã lỗi
| Mã | Câu |
|---|---|
| name_invalid | Nhập tên chuyên mục, ví dụ "Tin công ty". |
| slug_invalid | Đường dẫn chỉ gồm chữ thường không dấu, số và dấu gạch nối, ví dụ tin-cong-ty. |
| slug_taken | Đường dẫn này đã có chuyên mục/trang khác dùng — thử thêm -2, ví dụ tin-cong-ty-2. |
| parent_invalid | Không đặt được vào chỗ này — chọn chuyên mục cha khác. |
| depth_exceeded | Chỉ được lồng tối đa 3 cấp. |
| path_taken | Ở chỗ mới đã có chuyên mục cùng đường dẫn — đổi đường dẫn rồi chuyển lại. |
| has_children | Chuyên mục còn mục con — chọn "Đưa lên cấp trên" hoặc "Xóa theo" rồi xóa lại. |
| has_items | Chuyên mục còn bài — chọn chuyển sang chuyên mục khác hoặc bỏ gắn rồi xóa lại. |
| section_bound | Đây là chuyên mục gốc của một loại nội dung — không xóa được, chỉ đổi tên được. |
| section_changed | Thao tác này chuyển bài sang loại nội dung khác — xác nhận để tiếp tục. (Câu trên màn: "N bài sẽ chuyển từ A sang B — quyền và quy trình duyệt sẽ theo B.") |
| template_invalid | Mẫu trang này không còn trong giao diện đang dùng — chọn mẫu khác. |
| locale_invalid | Ngôn ngữ này chưa bật cho website — bật ở Cài đặt › Ngôn ngữ website. |
| target_invalid | Chuyên mục đích không hợp lệ — có thể vừa bị xóa, hãy chọn lại. |
| vocabulary_mismatch | Chỉ chuyển được sang chuyên mục cùng loại nội dung. |
| taxonomy_invalid | Chuyên mục này vừa được người khác đổi hoặc xóa — tải lại trang rồi thử lại. |
| vocabulary_invalid | Loại chuyên mục này không còn dùng được — tải lại trang rồi thử lại. |
Chuỗi khác của màn: nằm trong template + `renderVals()` (tiêu đề, mô tả, menu ⋯, hộp xóa, toast).

## Kết quả 12 tiêu chí dễ dùng
1. Đạt — tiêu đề "Chuyên mục · Tin tức" + 1 dòng mô tả + 1 nút chính "Thêm chuyên mục".
2. Đạt — thêm: menu › Chuyên mục › Thêm chuyên mục › nhập tên › Thêm (3 bước).
3. Đạt — không thuật ngữ trần (slug → "Đường dẫn", SEO → "Tiêu đề/Mô tả trên Google", redirect → "chuyển hướng").
4. Đạt — nút chỉ icon (⋯, +, đóng, mở/thu) đều có `aria-label` + `title`.
5. Đạt — placeholder là ví dụ; lỗi nói cách sửa.
6. Đạt một phần — đóng khi chưa lưu bị chặn; chuyển chỗ có Hoàn tác; xóa không vào Thùng rác theo luật as-built (hộp xác nhận nói hệ quả bằng số). Panel chưa tự lưu nháp (chỉ 2–6 ô ngắn).
7. Đạt — chấm ngôn ngữ = hình (nét đứt / đặc) + tooltip chữ; chip "Gốc" có icon + chữ.
8. Đạt — nút/chip một dòng; tên chuyên mục dài xuống dòng (là nội dung, không phải nhãn nút); nút xóa dài có "…" + `title`.
9. [CHƯA KIỂM ĐƯỢC] trên máy thật — thiết kế: 390 tấm trượt đáy, nút ≥ 44px khi cảm ứng, panel toàn màn.
10. Đạt — người thạo: kéo-thả, tìm, mở/thu tất cả; người mới: menu ⋯ có chữ, tùy chọn nâng cao gập.
11. [CHƯA KIỂM ĐƯỢC] — chữ theo `--fsp`, cần đo harness.
12. Đạt — Thêm · Sửa · Xóa · Lưu · Hủy · Bỏ lọc · Hoàn tác · Thử lại theo Bảng thuật ngữ.

## Kết quả Chất lượng hoàn thiện (A–E)
- A. Đạt theo code — thang 4/8px, cỡ chữ 12/13/14/16/20 của DS, chuyển động của component chung (150ms), khung tải không animation.
- B. Đạt theo code — nhãn luôn hiện; mọi việc kéo-thả có nút thay; "Đang lưu…" chặn bấm 2 lần; mất mạng giữ chữ + "Thử lại".
- C. Đạt theo code — 1 h1 (ẩn khi nhúng, Shell giữ h1); hộp thoại có tên (`aria-labelledby`), Esc, giữ Tab, trả focus; toast `aria-live`.
- D. [CHƯA KIỂM ĐƯỢC] chế độ tối bằng số đo — chỉ dùng token; Safari/Firefox chưa mở.
- E. [CHƯA KIỂM ĐƯỢC] — chưa chạy `_audit/harness.html` trong lượt này; Claude Code đo bằng zip: `H.layouts(['Chuyên mục.dc.html'],[360,390,768,1024,1440])` × thường/Chữ lớn; trong Shell `H.mk('s',390,844,0,0,'../Admin Shell.dc.html#/site/posts.cats')` (mount màn thật).

## Kiểm hiển thị
| Bề rộng | Chrome | Safari iOS | Firefox | Samsung | Ghi chú |
|---|---|---|---|---|---|
| 360 / 390 | [CHƯA KIỂM ĐƯỢC] | [CHƯA KIỂM ĐƯỢC] | [CHƯA KIỂM ĐƯỢC] | [CHƯA KIỂM ĐƯỢC] | dưới 600px khung: ẩn ⠿, số + ngôn ngữ dồn xuống dòng 2, menu = tấm trượt đáy |
| 768 / 1024 | [CHƯA KIỂM ĐƯỢC] | [CHƯA KIỂM ĐƯỢC] | [CHƯA KIỂM ĐƯỢC] | — | cây một hàng, panel 480px phải |
| 1240 / 1440 | [CHƯA KIỂM ĐƯỢC] | — | [CHƯA KIỂM ĐƯỢC] | — | khung tối đa 1240px |
| 844×390 ngang | [CHƯA KIỂM ĐƯỢC] | [CHƯA KIỂM ĐƯỢC] | — | — | hộp xóa cuộn trong `max-height:100dvh-32px` |
Verifier: xem dòng cuối.

## Việc cho backend
1. **Mô tả, ảnh, SEO của chuyên mục** — `taxonomy_translations` thêm `description` (text), `seo_title` (≤ 60 gợi ý), `seo_description` (≤ 160 gợi ý) theo từng ngôn ngữ; `taxonomies.image_media_id` (ảnh dùng chung). Đi qua Create/UpdateTaxonomy, ghi nhật ký.
2. **Xem trước hệ quả trước khi xóa/gộp/chuyển** — truy vấn chỉ đọc trả: số mục con, số bài/sản phẩm gắn trực tiếp và cả nhánh (không tính thẻ ≥ 1000), số bài có chuyên mục chính trong nhánh, số chuyển hướng sẽ tạo, số mục trình đơn trỏ tới, có đổi loại nội dung không + số bài rời loại. Hiện repo chỉ trả số sau khi xóa (`DeleteTaxonomy.php:64`).
3. **Bộ đếm số bài theo chuyên mục** — cột/bảng đếm theo website, cập nhật trong cùng giao dịch gắn/bỏ gắn/xóa/chuyển (không đếm sống, CLAUDE.md §3.1 của repo). **Cần owner chốt:** đếm theo chuyên mục chính hay mọi chuyên mục được gắn (thiết kế đang hiện "mọi chuyên mục, không tính thẻ").
4. **Bỏ gắn khi xóa** — bài chỉ thuộc nhánh bị xóa thì rơi về loại nội dung nào. **Cần owner chốt** (thiết kế ghi "theo mặc định của website" — phương án an toàn: bài giữ trạng thái, chỉ mất chuyên mục).
5. Trang `admin.taxonomies.index` (`routes/web.php:236`) thay "sắp có" bằng màn này; Livewire nhận `?section=`.

Verifier: [CHƯA KIỂM ĐƯỢC] — cập nhật sau khi verifier trả kết quả.
