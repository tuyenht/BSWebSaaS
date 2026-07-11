Chạy /bs:spec cho ADR‑19 (kind `html` + "một body/hai editor"). Đây là spec‑first: nói lại hiểu + edge case + giả định + phân loại ACT/COUNCIL, rồi DỪNG chờ tôi duyệt. Verify CODE first‑hand (CODE thắng). KHÔNG code/migrate tới khi tôi chốt.

BỐI CẢNH: ADR‑19 đã land design.md §19 (Draft). Prototype thiết kế `Page Editor` đã dựng hành vi tham chiếu: body per‑locale + alt per‑locale + hợp đồng một‑body/hai‑editor. Spec chi tiết ở docs/adr/ADR-19-spec.md (bản thiết kế). Nhiệm vụ: đối chiếu CODE thật rồi chốt kế hoạch build.

Verify & xác nhận từng điểm (trích CODE):
1) `app/Modules/Theme/Providers/ThemeServiceProvider.php::SECTION_SCHEMAS` — thêm kind `'html' => ['html'=>['type'=>'html'],'heading'=>['type'=>'text']]` có phải chỗ đúng (vs đăng ký ở CmsServiceProvider)? `richtext` hiện = heading+paragraphs(list textarea) — GIỮ, `html` là sibling KHÔNG thay thế.
2) `app/Modules/Cms/Validation/ValidPageJson.php::valueSafeForSpec` — thêm nhánh `$specType==='html'` (null|string, raw‑render, KHÔNG rơi vào scalar). Rule thuần cấu trúc, KHÔNG chạy HTMLPurifier ở đây. Cap 100/256KB giữ.
3) SANITIZE ON‑WRITE MỌI ĐƯỜNG GHI (bảo mật — trọng tâm): một PageJsonWriter/pipeline chung chạy `RichTextSanitizer::full` (TÁI DÙNG, app/Modules/Shared/Support/RichTextSanitizer.php — docblock "CMS bodies later") trên mọi prop kiểu `html`, phủ (a) editor người (b) AI‑gen Page‑JSON ADR‑08 (c) import. Xác nhận hiện có chỗ ghi body nào (grep) để đặt pipeline, tránh sót.
4) Blade `themes/default/sections/html.blade.php` render `{!! $props['html'] !!}` — an toàn vì sanitize on‑write + CSP storefront. Đối chiếu cách `product_translations.description` render (precedent).
5) Hợp đồng một‑body/hai‑editor: suy tab từ `entry_translations.body` (rỗng|1 block `html`→prose; có section→builder); hint `body.editor` chỉ nhớ tab, payload luôn blocks; round‑trip lossless có điều kiện, KHÔNG flatten khi có section cấu trúc.
6) Alt per‑locale: cover = `entries.cover_media_id` (global), alt = `media.alt[locale]` (jsonb per‑locale, migration 2006) — KHÔNG trường alt toàn cục/`cover_alt`.

OUTPUT: (a) xác nhận/bác từng điểm bằng trích CODE; (b) phân loại ACT vs COUNCIL (registry/ValidPageJson/sanitize‑pipeline = COUNCIL propose‑first); (c) danh sách file sẽ đụng + thứ tự; (d) test‑first Pest (tên test: HtmlKindRegisteredTest · ValidPageJsonHtmlTypeTest · HtmlWriteSanitizeAllPathsTest · HtmlSectionRenderTest · OneBodyTwoEditorTest · CoverAltPerLocaleTest). Tìm thêm vênh khi verify → báo. KHÔNG viết code tới khi tôi duyệt.
