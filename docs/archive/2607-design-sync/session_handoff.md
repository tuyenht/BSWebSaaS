> 🗄 **LƯU TRỮ (24/09/2026)** — tài liệu làm việc của đợt đồng bộ thiết kế tháng 7/2026, giữ làm lịch sử.
> KHÔNG phải nguồn sự thật: có chỗ đã lỗi thời hoặc mâu thuẫn quyết định sau này. SSOT = `docs/design.md`
> (chỉ mục ADR ở Phụ lục A) + `docs/README.md`. Lý do lưu trữ: `docs/archive/README.md`.

# Session handoff — BSWebSaaS design (bs:close)

> Bản ghi để nối tiếp ở session mới không đứt mạch. ĐỌC TRƯỚC: `CLAUDE.md` + `docs/implementation_roadmap.md`
> + doc này. Trạng thái: 2026‑07. Người dùng làm việc tiếng Việt; propose‑first cho schema; code > doc.

## 1. Tài sản thiết kế (đã dựng & verify, trong project này)
- **`Page Builder v3.dc.html`** — trình soạn **Section Library → Page‑JSON** (bản CHÍNH). Có: 7 mẫu trang · biến thể layout (hero/about/features/cta) · chèn section xen giữa + bộ chọn · hover‑label · brand token · caps 100/256KB · locale VI/EN/JA · **ribbon ngữ cảnh** ("Lưu & quay lại" cho khối, "Thu nhỏ/Lưu & thu nhỏ" cho trang) qua param `?block=&name=&return=` và `?label=&return=`. Autosave key `bswebsaas:pb:v3:draft`.
- **`Page Editor - Options.dc.html`** — thêm/sửa trang; 2 tab Soạn thảo/Trình dựng (nhúng v3 + "Mở toàn màn hình" có đường về); AI‑translate; SEO; featuredAlt (đã vá per‑locale — xem nợ kỹ thuật §4).
- **`Block Library - Options.dc.html`** — khối tái dùng: danh sách + Thêm/Sửa (modal) + "Dùng ở N nơi" (popover liệt kê đầy đủ) + quy ước cột ngôn ngữ/hành động.
- **`Website Config - Integrations.dc.html`** — Tích hợp & Theo dõi: ~16 provider + intl group · server‑side toggle · Consent 3 mức · CSP động minh hoạ · tooltip "i" + hướng dẫn lấy ID + tự trích ID/dán‑nhầm · sGTM managed · "Nhờ cài giúp".
- **`Page Builder v2.dc.html`** = bản KHÁM PHÁ (Elementor), LỆCH — chỉ tham khảo UX. Pages Admin (3a), Floating Contact, MediaPicker (dùng chung).

## 2. Tài liệu đã viết (nguồn sự thật)
`docs/`: `builder_v2_audit.md` · `builder_v2_alignment.md` · `builder_v3_spec.md` · `reusable_blocks_spec.md` ·
`tracking_integrations_spec.md` · `tracking_storefront_impl.md` · `db_audit_storage.md` · **`implementation_roadmap.md`** (tổng hợp).
`CLAUDE.md` đã bổ sung: mô hình Page‑JSON/Section Library · quy ước màn danh sách · chặn thoát khi dirty · **quy trình Design↔Repo↔Claude Code**.

## 3. Kiến trúc chốt từ CODE (đã verify, doc có thể cũ hơn)
Page‑JSON = `entry_translations.body` per‑locale · RLS+FORCE mọi bảng · `RichTextSanitizer` (full/basic) sẵn có, dùng ON‑WRITE ·
`ValidPageJson` chặn scheme url · **`media.alt`/`title` jsonb per‑locale** · hreflang+fallback+homepage đã có ·
catalog brochure/commerce một‑hình · forms **6 field type CHECK** (text,email,tel,textarea,select,checkbox) · OAuth nonce · SVG loại khi upload ·
Claude Code nhắc có **`entry_revisions`** (đối chiếu lại khi làm revisions).

## 4. Nợ kỹ thuật / cần dọn ở prototype (khi vào build)
- **`featuredAlt` (Page Editor)**: tôi vá tạo **nguồn‑kép** (global + per‑locale) → phải map về **`media.alt[locale]`**, bỏ trường riêng.
- **RTE body chưa per‑locale** → "nội dung không dịch" là do đây (schema đã đúng). Cần bind body per‑locale (nạp khi đổi locale, lưu vào `data[loc].body`).
- **Form builder P2** có radio/checkboxes → **lệch CHECK 6 loại** → bỏ hoặc COUNCIL mở rộng.
- **`richtext_html`**: spec ghi "tạo sanitizer mới" → sửa thành **dùng lại `RichTextSanitizer::full`**.

## 5. Trạng thái repo (Claude Code) — ĐÃ LAND
- **v2.14 ĐÃ LAND** (commit `c040caa`): doc‑sync as‑built + **4 ADR Draft** trong bảng chỉ mục `docs/design.md` dòng 19–22 + changelog v2.14. Trạng thái mỗi ADR = **Đề xuất (Draft)**. **Chưa migrate gì.**
- Phân kỳ build: ADR‑19/21 near‑term; ADR‑20/22 hoãn cho An Vinh (Draft giữ).

### 5b. RECONCILE 2026‑07‑11 (CODE `main@c040caa`) — v2.14 đã land, sửa lệch staging
- **Convention ADR (verify):** không có `docs/adr/` trong repo. ADR = **dòng bảng chỉ mục `docs/design.md`** + changelog `v2.x`. `docs/adr/` chỉ sống ở project thiết kế (bản chi tiết + reconcile log).
- **⚠ ADR‑20 ĐẢO CHIỀU:** land chốt **bảng typed `content_blocks`(+`content_block_translations`)**, **KHÔNG** `entries.type='block'` (giữ §8: type chỉ page|post; thực thể khác hành vi = bảng riêng như products). Staging cũ (entries.type) **SAI** → đã sửa `docs/adr/ADR-20`.
- **ADR‑19:** land gọi **kind `html`** (không phải `richtext_html`) + **sanitize ở MỌI đường ghi** (editor người + **AI‑gen ADR‑08 + import**) — không chỉ FormRequest. Đã sửa.
- **ADR‑21:** land = `spatie/laravel-csp` nonce động + cookieless `page_views` (bỏ "Provider Registry" của staging). Đã sửa.
- **Migration dừng ở `2013`** → Wave 0 chưa chạy. `entries_type_check` vẫn `('page','post')`. `entries_status_check` có `scheduled/private` (2012). `media.alt`/`title` = **jsonb per‑locale** (2006) ✓ → nợ `featuredAlt` map về `media.alt[locale]`, bỏ `cover_alt`.
- **Revisions = typed `entry_revisions`** (design giữ typed, changelog v2.14 xác nhận); migration CHƯA có → vẫn tạo mới, COUNCIL.
- **Verify class sẽ sửa:** `ComponentRegistry` (allow‑list) · `ValidPageJson` (caps 100/256KB, theo KIỂU §E7, **chưa nhánh `html`**) · `RichTextSanitizer::full` (đã có, reuse). 
- **Gói `docs/adr/` đã reconcile** khớp land — dùng làm tham chiếu BUILD, không phải chờ duyệt commit.

## 6. Việc ĐANG DỞ / kế tiếp (theo roadmap — waves)
- **Chờ:** verifier prototype `Page Editor` (nền); người dùng duyệt spec ADR‑19 để dán Claude Code.
- **✅ ĐÃ LÀM (session 2026‑07‑11):** dọn nợ §4 + ADR‑19 trên `Page Editor - Options.dc.html` — body per‑locale (`data[loc].body`, `syncBody`/`captureBodyNow`), alt per‑locale (bỏ `featuredAlt` toàn cục → `media.alt[locale]`), hợp đồng một‑body/hai‑editor. Spec đủ 3‑phần: `docs/adr/ADR-19-spec.md` + `_claude_code_ADR19_prompt.md`.
- **✅ CHỐT GIAO DIỆN Thêm/Sửa trang + Trình dựng (vòng review người dùng, 2026‑07‑11):**
  - **Một body/hai view end‑to‑end:** tab Trình dựng = outline suy từ body thật (blocks hoặc prose); prose KHOÁ khi có section cấu trúc (no‑flatten ADR‑19); đồng bộ 2 chiều editor↔builder qua localStorage `bswebsaas:pe:handoff`/`pe:handback` (autosave builder cũng đổ về); "Thêm section" mở builder + bật hộp chọn (`&add=1`); ribbon "Đang sửa trang · “{title}”".
  - **Per‑locale đúng chuẩn:** khối Ngôn ngữ + công cụ dịch CHỈ hiện khi site >1 content‑locale (demo 1/3/14); check trùng slug theo locale (`routes UNIQUE(site,locale,path)`); ô Đường dẫn hiện tiền tố khoá `/{locale}/` cho bản không mặc định (trang chủ EN = `/en`); 301 hiện cũ→mới; dịch AI/sao chép bản gốc mang cả `blocks` + alt dịch riêng (không lấy title).
  - **UI chuẩn hoá:** bỏ jargon (ComponentRegistry/§E7/site token/Page‑JSON khỏi UI khách); modal xoá + modal rời‑khi‑chưa‑lưu 3 nút (Ở lại/Thoát không lưu/Lưu & thoát) thay confirm trình duyệt; `beforeunload` khi dirty; typography RTE = builder = storefront (`.pe-rte`≡`.pb3-prose`); dblclick sửa khối văn bản trên canvas; copy/paste section xuyên trang (`bswebsaas:pb:clip`); "Mẫu trang" ở rail trái, mẫu CHÈN nối (không thay thế); clipboard đường dẫn thật.
  - **Ghi cho spec khi build thật:** router‑guard nội bộ 3 nút (Livewire) thay beforeunload; aiSeo đọc text từ blocks khi prose khoá; Xem trước/Thùng rác/Lịch sử = mô phỏng.
- **Wave 0 (COUNCIL migration, test‑first):** **`content_blocks`+`content_block_translations`** (typed, KHÔNG entries.type='block') · `entry_revisions` (typed) · `site_integrations` · `block_usages`+GIN body · partial index published_at · chốt form field types (mở rộng CHECK = COUNCIL riêng).
- **Wave 1:** builder Section‑Library (hiện thực từ v3) · **RTE body per‑locale** · kind `html` (reuse sanitizer, sanitize MỌI đường ghi)+ValidPageJson.
- **Wave 2:** reusable_ref + guard + Block Library + chèn/lưu/tách + "dùng ở N nơi".
- **Wave 3:** AI‑translate cả bản ghi (body, alt từ media, không ghi đè bản sửa tay, async/cache) + xuất bản độc lập từng ngôn ngữ.
- **Wave 4:** tracking backend (collector/queue/CSP‑nonce/consent record/attribution/offline).
- **Wave 5:** menu builder · redirects+auto‑301 · scheduled publish · draft↔published safety.

## 7. PROMPT NỐI TIẾP (dán vào session THIẾT KẾ mới)
> **Bạn là trợ lý THIẾT KẾ (làm `.dc.html`, inline‑style, streaming), KHÔNG phải Claude Code.** `CLAUDE.md` + `docs/*` nằm trong project thiết kế này.
> Tiếp tục dự án **BSWebSaaS (GĐ1 An Vinh)**. **Đọc trước:** `CLAUDE.md`, `docs/implementation_roadmap.md`, `docs/session_handoff.md`.
> Bám quy trình **Design↔Repo↔Claude Code** (code > doc; propose‑first cho schema; test‑first). Repo `github.com/tuyenht/BSWebSaaS@main`.
> **Trạng thái:** prototype đã **DỰNG** (Page Builder v3 = Section‑Library→Page‑JSON, Block Library, Integrations, Page Editor) — **CÒN NỢ KỸ THUẬT ở §4, phải dọn khi build, đừng coi là hoàn hảo**. Repo: 4 Draft ADR (19‑22) đã tạo, commit doc chờ duyệt.
> **Trước khi bắt tay (bắt buộc):** (a) **đọc CODE repo + kiểm tra trạng thái ĐÃ ĐỔI chưa** (cần **GitHub đã kết nối** — nếu chưa, yêu cầu người dùng kết nối trước; commit doc đã land? ADR 19‑22 tới đâu? schema/`entry_revisions` có gì mới?); (b) rà việc **đã xong để không làm lại**; (c) tóm tắt trạng thái + **hỏi 3–5 câu chốt phạm vi** rồi mới làm.
> **Việc tiếp theo tôi muốn làm:** [ĐIỀN]. *(Nếu để trống, mặc định đề xuất: dọn nợ prototype §4 hoặc `/bs:spec` cho ADR‑19 — hỏi tôi chọn.)*
