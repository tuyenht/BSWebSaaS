# Gói ADR‑19..22 — RECONCILED theo commit đã land (design.md v2.14)

> ⚠️ **ĐÃ ĐÍNH CHÍNH 2026‑07‑11 sau commit `c040caa`.** 4 ADR **ĐÃ LAND** vào `docs/design.md` (Phụ lục A "Chỉ mục ADR" dòng 19–22 + changelog **v2.14**), trạng thái **Đề xuất (Draft)**. Gói này KHÔNG còn là "staging chờ commit" — nó là **bản chi tiết design‑side + log reconcile + task build cho Claude Code**. Grounded CODE `tuyenht/BSWebSaaS@main c040caa`. **CODE/design.md thắng khi mâu thuẫn.**

## 0. Convention ADR (verify) — GIỮ NGUYÊN
- ADR chính thức = **dòng trong bảng chỉ mục `docs/design.md`** (Phụ lục A) + changelog `v2.x`. Thư mục `docs/adr/` (nhập từ project thiết kế ngày 12/07) chỉ giữ **bản chi tiết** của ADR‑19..22 — không phải nơi ghi quyết định. *(Câu cũ "không có `docs/adr/` trong repo" đã sai từ khi gói này được nhập — sửa 24/09; các prompt đã dùng chuyển sang `docs/archive/2607-design-sync/`.)*

## 1. Trạng thái ĐÃ LAND (đọc design.md v2.14, không đoán)
| ADR | Dòng design.md (nguyên văn rút gọn) | Trạng thái |
|---|---|---|
| **19** | Page Builder — **section kind `html`** (khối rich‑HTML): reuse `RichTextSanitizer::full` **sanitize‑KHI‑GHI ở MỌI đường ghi** (editor người **+ AI‑gen Page‑JSON ADR‑08 + import** — sót = stored‑XSS) + phủ `ValidPageJson`; render qua `BlockRenderer`. Khác kind `richtext` hiện có (`paragraphs` plain‑text). | Draft |
| **20** | Khối tái dùng: **bảng typed riêng `content_blocks`(+`content_block_translations`)** — **KHÔNG mở `entries.type='block'`** (giữ entries thuần `page\|post` theo §8/§9); `reusable_ref{block_id}` + guard đệ quy/độ sâu/ngân sách; `block_usages` + GIN `entry_translations.body`. **Build HOÃN**. | Draft |
| **21** | Tracking seam (near‑term): **CSP nonce ĐỘNG per‑request** (qua `spatie/laravel-csp`, vá CSP static + admin‑plane hở) + **analytics cookieless `page_views`**. Kích hoạt khi Script‑Manager/tag 3rd‑party xuất hiện. | Draft |
| **22** | Tracking 3rd‑party (pha thương mại, DEFER): `site_integrations`(secret mã hoá + RLS) + consent record + collector/queue fan‑out + attribution lead (gclid/fbclid/utm trên `form_submissions`) + offline‑conversion; tracking‑domain từ `config('tenancy.base_domains')`. | Draft |

Changelog v2.14 ghi rõ: **doc‑sync as‑built + 4 ADR Draft**, "**revisions giữ typed**", "lõi KHÔNG đổi, **chưa migrate gì**".

## 2. Ba LỆCH giữa staging (bản trước) và bản LAND — đã sửa trong gói này
1. **⚠ ADR‑20 ĐẢO CHIỀU (quan trọng nhất).** Staging tôi đề xuất `entries_type_check += 'block'`. **Council đã chốt KHÁC: bảng typed riêng `content_blocks` + `content_block_translations`**, **KHÔNG** đụng `entries.type` (giữ §8 "type dùng tiết kiệm, chỉ page|post; thực thể khác hành vi = bảng riêng" — nhất quán với `products`). → ADR‑20 trong gói đã **viết lại** theo `content_blocks`. Ảnh hưởng build reusable blocks về sau: khối = **thực thể typed**, không phải entry.
2. **ADR‑19 tên kind + phạm vi sanitize.** Land gọi **kind `html`** (không phải `richtext_html`); và **sanitize ở MỌI đường ghi** = editor người **+ AI‑gen Page‑JSON (ADR‑08) + import**, không chỉ FormRequest. → đã sửa ADR‑19.
3. **Revisions = typed `entry_revisions`** (design giữ typed), KHÔNG phải `content_revisions` generic như staging viết. Migration vẫn CHƯA tồn tại (main dừng ở 2013) ⇒ vẫn là bảng **sẽ tạo mới**, COUNCIL — nhưng **tên/kiểu = `entry_revisions` typed**.

Giữ nguyên (khớp land): ADR‑21 dùng `spatie/laravel-csp` + `page_views` cookieless (bỏ ý "Provider Registry" của staging — không có trong land; provider‑config thuộc `site_integrations`/ADR‑22). ADR‑22 khớp.

## 3. Bốn điểm hoà giải as‑built (giữ) 
- Bỏ `cover_alt` → `media.alt[locale]` (jsonb per‑locale, migration 2006). Nợ prototype `featuredAlt` map về đây.
- `richtext_html`/kind `html` **reuse** `RichTextSanitizer::full` — KHÔNG sanitizer mới (docblock đã ghi "CMS bodies later").
- Form field CHECK 6 loại → mở rộng (radio/checkboxes/number/date) = **COUNCIL riêng**, NGOÀI 4 ADR.
- Migration: **chưa cái nào chạy** (2013 là mới nhất) — mọi schema ADR‑20/22 + `entry_revisions` = COUNCIL propose‑first từng wave.

## 4. Gói này dùng để làm gì tiếp
- **Tham chiếu build** (chi tiết Pest + UX "một body/hai editor" mà dòng design.md quá ngắn không chứa).
- Việc kế: **dọn nợ prototype §4** (dùng kind `html` + `media.alt[locale]`), rồi **prototype ADR‑19 + thiết kế lại 2 tab Soạn thảo/Trình dựng**.
- `docs/archive/2607-design-sync/_claude_code_prompt.md` = **ĐÃ DÙNG/lỗi thời** (commit doc‑only đã land) — giữ làm lịch sử; task Claude Code mới sẽ là **build** (propose‑first per wave), không phải commit doc.
