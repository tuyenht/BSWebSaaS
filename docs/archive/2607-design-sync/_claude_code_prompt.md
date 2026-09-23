> 🗄 **LƯU TRỮ (24/09/2026)** — tài liệu làm việc của đợt đồng bộ thiết kế tháng 7/2026, giữ làm lịch sử.
> KHÔNG phải nguồn sự thật: có chỗ đã lỗi thời hoặc mâu thuẫn quyết định sau này. SSOT = `docs/design.md`
> (chỉ mục ADR ở Phụ lục A) + `docs/README.md`. Lý do lưu trữ: `docs/archive/README.md`.

# Prompt cho Claude Code — land gói ADR‑19..22 (DOCS‑ONLY)

> 🚫 **LỖI THỜI — ĐÃ THỰC HIỆN.** 4 ADR đã land vào `docs/design.md` v2.14 (commit `c040caa`). Prompt này giữ làm **lịch sử**; ĐỪNG dán lại. Task Claude Code kế tiếp sẽ là **BUILD** (propose‑first per wave), không phải commit doc. Xem `README.md §4`.
>
> ~~Dán vào Claude Code sau khi tôi đã duyệt gói ADR. Commit docs‑only, không migrate.~~

---

Bối cảnh: gói ADR‑19..22 đã được duyệt ở phía thiết kế. Nhiệm vụ của bạn = **đưa quyết định vào tài liệu repo**, đúng convention hiện có, **không chạm code/schema**.

**Convention ADR (đã verify main):** ADR đánh số = **section trong `docs/design.md`** + dòng changelog `v2.x` (KHÔNG có thư mục `docs/adr/`). Hiện cao nhất: **§33 · v2.13 · ADR‑18**. ⇒ thêm **§34–§37 = ADR‑19..22** + changelog **v2.14**. Trạng thái mỗi ADR = **Accepted** (đã duyệt), phần chạm schema/CSP ghi rõ **[COUNCIL] propose‑first, chưa migrate**.

**Việc cần làm:**
1. Nếu bạn đang có **Draft ADR‑19..22 local** (chưa commit): **reconcile** với nội dung dưới (bản đã duyệt là nguồn sự thật), rồi viết vào `docs/design.md`. Nếu khác biệt lớn → nêu diff, đừng ghi đè thầm.
2. Thêm 4 section vào `docs/design.md` (văn phong khớp §19/§20 hiện có, tiếng Việt, có tag `*(ADR‑NN)*`):
   - **§34 ADR‑19 — `richtext_html`:** block prop kiểu §E7 `html`, **sanitize ON‑WRITE bằng `RichTextSanitizer::full` (TÁI DÙNG — app/Modules/Shared/Support/RichTextSanitizer.php, docblock đã ghi "CMS bodies later"; KHÔNG tạo sanitizer mới)**, render `{!! !!}`; `ValidPageJson` thêm nhánh kiểu `html` (cấu trúc: string|null; sanitize không ở rule). Luật **"một body/hai editor"**: suy tab từ body (rỗng/1 block richtext_html→Soạn thảo; có section→Trình dựng), hint `body.editor` chỉ để nhớ tab, payload luôn blocks; round‑trip lossless có điều kiện (không flatten khi có section cấu trúc). **Không migration.**
   - **§35 ADR‑20 — reusable blocks:** `entries_type_check += 'block'` (khối = entries type='block', body per‑locale, KHÔNG routes); block `reusable_ref{block_id:int}`; resolve ở `PageRenderer` theo locale; guard tự‑nhúng/chu trình/độ sâu≤3/ngân sách≤100·256KB; `block_usages(site_id,block_id,entry_id,locale)` + **GIN `entry_translations.body jsonb_path_ops`** cho "dùng ở N nơi" + guard xoá. **[COUNCIL] — mô tả, chưa migrate.**
   - **§36 ADR‑21 — CSP‑nonce/cookieless:** Provider Registry (khai báo) + **CSP động per‑site** trong `AddSecurityHeaders` (gộp `csp[]` provider đang bật; `serverSide`→không thêm origin) + **nonce per‑request** cho init (KHÔNG `unsafe-inline`) + roll‑out `Content-Security-Policy-Report-Only`. **[COUNCIL], không schema.**
   - **§37 ADR‑22 — first‑party tracking:** collector `/_e`→queue→fan‑out server‑side (Meta CAPI/GA4 MP/TikTok Events API, token server, hash PII, event_id dedup); `site_integrations(...secret_enc RLS)`; Consent Mode v2 + consent record + GPC/DNT; attribution lead trên `form_submissions`; Blade partial `theme::partials.tracking`. White‑label: `platform.name` + `sgtm.<PRIMARY_BASE>` **suy từ config sẵn có, zero hardcode**. **[COUNCIL] + Deferred sau An Vinh.**
3. Thêm changelog **v2.14** tóm tắt 4 ADR, ghi rõ "docs‑only; schema/CSP = COUNCIL từng wave; chưa migrate".
4. Đồng bộ các **spec chi tiết** vào repo `docs/` nếu chưa có (từ project thiết kế): `builder_v2_alignment.md`, `builder_v3_spec.md`, `reusable_blocks_spec.md`, `tracking_integrations_spec.md`, `tracking_storefront_impl.md`, `db_audit_storage.md`, `implementation_roadmap.md`. **Đính chính khi đồng bộ `db_audit_storage.md`:** bỏ đề xuất `cover_alt` ở §B2 (alt = `media.alt[locale]`, đã jsonb per‑locale — migration 2006); ghi rõ **revisions là bảng MỚI** (main KHÔNG có `entry_revisions` lẫn `content_revisions`; migration dừng ở 2013).

**Ràng buộc:**
- **KHÔNG** tạo/sửa migration, **KHÔNG** đổi `ComponentRegistry`/`ValidPageJson`/`AddSecurityHeaders` trong commit này. Chỉ tài liệu.
- Mọi mục chạm schema/CSP/registry ghi **[COUNCIL] propose‑first** — chờ /bs:spec từng wave.
- Commit message: `docs(adr): ADR‑19..22 (richtext_html · reusable blocks · CSP‑nonce · first‑party tracking) — v2.14, docs‑only`.
- Xong thì liệt kê: file đã đổi + số §/version đã thêm + xác nhận không có thay đổi schema.

Nếu có mâu thuẫn giữa nội dung này và CODE thật → **CODE thắng**, báo lại trước khi ghi.
