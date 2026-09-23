> 🗄 **LƯU TRỮ (24/09/2026)** — tài liệu làm việc của đợt đồng bộ thiết kế tháng 7/2026, giữ làm lịch sử.
> KHÔNG phải nguồn sự thật: có chỗ đã lỗi thời hoặc mâu thuẫn quyết định sau này. SSOT = `docs/design.md`
> (chỉ mục ADR ở Phụ lục A) + `docs/README.md`. Lý do lưu trữ: `docs/archive/README.md`.

# Audit v2.14 (doc‑sync + 4 ADR) đối chiếu CODE `main@c040caa` — 2026‑07‑11 (RECONCILED 2 vòng)

> Kiểm từng claim design.md v2.14 bằng CODE first‑hand, reconcile 2 vòng (design ↔ Claude Code council). Kết luận: **doc‑sync CHUẨN**; 4 ADR Draft đúng; "chưa migrate gì" ĐÚNG (migration dừng 2013). **Chốt 3 diff doc‑only** (không lật‑lõi): (i) ADR‑20 §9→§8/ADR‑14 · (ii) ADR‑21 rewrite storefront‑only + nonce×cache + spatie=dep · (iii) §29:633 annotate as‑built. V3 (nghi §29 sai) đã **rút lại** — §29 đúng.

## A. Claim ĐÃ VERIFY ✅ (chính xác)
| Claim v2.14 | Bằng chứng CODE |
|---|---|
| status enum entries có `scheduled/private`, worker `entries:publish-due` **mỗi phút "đã chạy"** | `routes/console.php`: `Schedule::command('entries:publish-due')->everyMinute()->withoutOverlapping()` ✅ (+`PublishDueEntries` cmd, migration 2012) |
| kind `richtext` = `heading + paragraphs[]` plain‑text | `ThemeServiceProvider::SECTION_SCHEMAS['richtext']` = heading(text)+paragraphs(list of textarea) ✅ |
| Registry = allow‑list render+edit+validate | `ThemeServiceProvider::register()` → `ComponentRegistry->register(type, SectionComponent(view, schema))`; module thêm component qua provider (vd CmsServiceProvider `latest_posts`) ✅ |
| sanitizer `description`=full / `summary`=basic | §11 + `ezyang/htmlpurifier ^4.19` (composer) ✅ |
| `BlockRenderer` / `PageRenderer` | `Theme/Rendering/{BlockRenderer,PageRenderer}.php` ✅ |
| white‑label `config('tenancy.base_domains')` | `config/tenancy.php` (env `BASE_DOMAINS`, default `baoson.net`) ✅ |
| `form_submissions` cho attribution | migration `2005_..._create_forms_tables` ✅ (cột attribution = ADR‑22 thêm, defer) |

## B. VÊNH cần council làm rõ
### ⚠ V1 (đáng chú ý) — ADR‑21 "vá admin‑plane hở" MÂU THUẪN as‑built
- **Design row 21** viết: CSP‑nonce sẽ "vá CSP static + **admin‑plane hở hiện tại**" — ngụ ý admin‑plane có **lỗ hổng** cần vá.
- **CODE `AddSecurityHeaders` (docblock nguyên văn)**: admin+platform **CSP omitted ENTIRELY, CÓ CHỦ ĐÍCH** — *"Livewire hydration and Alpine reactivity require 'unsafe-eval'; shipping a strict CSP on those planes would break the admin UI. Omitting CSP there is **acceptable** because access is gated behind authentication."*
- ⇒ CODE coi đây là **bỏ‑CSP có chủ đích + chấp nhận được** (đằng sau auth), KHÔNG phải "hở". Row 21 gọi là "hở cần vá" là **đóng khung sai** → council phải chốt: (a) giữ nguyên omission (chỉ làm CSP‑nonce cho **storefront**), hay (b) thực sự thêm nonce‑CSP cho admin (đánh đổi: `unsafe-eval` Livewire/Alpine vẫn phải mở → nonce **không** loại được `unsafe-eval` → lợi ích hạn chế). Nghiêng (a).

### ⚠ V2 — `spatie/laravel-csp` CHƯA cài
- `composer.json require` **không có** `spatie/laravel-csp`. CSP hiện = **chuỗi tay** trong `AddSecurityHeaders::storefrontCsp()` (`default-src 'self'; img-src files.baoson.net; script-src 'self' challenges.cloudflare.com; …`).
- Design (dòng 634 + 729) nhắc package như "cơ chế sẽ dùng" nhưng **chưa là dependency**. ⇒ ADR‑21 = **thêm package + chuyển CSP tay → policy động** = tự nó là 1 quyết định COUNCIL (thêm dep). Cần ghi rõ, đừng để người đọc tưởng đã có.

### V3 (cross‑ref) — ADR‑21 "§29" → **BÁC (tôi sai, §29 ĐÚNG)**
- Reconcile vòng 2 (Claude Code council, verify exhaustive grep `CSP` cả file): spec CSP‑động+nonce+spatie ở **design.md 632–634 NẰM TRONG §29** (§29 = 620–637, §30 = 638); §14/§18 = **0 dòng CSP**; R24 + changelog v2.6 cũng trỏ "Mục 29". ⇒ ADR‑21 trỏ **§29 ĐÚNG, không sửa**. Điểm V3 ở bản audit trước (nghi §29 sai) **rút lại**.

### ⚠ V5+ (nâng cấp) — nonce per‑request XUNG KHẮC L0 full‑page‑cache
- `storefront-instant-nav.md:65‑67`: L2 Speculation Rules phải dùng **keyword `'inline-speculation-rules'`, KHÔNG nonce** — vì **nonce đổi mỗi response ⇒ xung khắc L0 full‑page‑cache (§25/ADR‑06)** (trang cache dùng lại nonce cũ = vỡ). ⇒ ADR‑21 KHÔNG được mặc định nonce mọi nơi: **ưu tiên hash/keyword‑source cho inline đã‑biết; nonce chỉ ở response KHÔNG‑cache.** GỘP đợt sửa `AddSecurityHeaders` với speculationrules‑L2.

### NEW#2 — §29:633 design‑intent lệch as‑built (đi CÙNG ADR‑21)
- §29:633 ghi "**Admin:** CSP chặt + nonce inline" = design‑intent, nhưng as‑built **OMIT admin CSP có chủ đích** (`AddSecurityHeaders:20‑25`). ⇒ annotate §29:633 (as‑built + storefront‑only) **cùng lúc** với ADR‑21 rewrite, nếu không doc mâu thuẫn nội bộ. Caveat honesty: `unsafe-eval` là as‑built, có `@alpinejs/csp` build → ghi "re‑visit", đừng "bất khả".

### NEW#3 — `page_views` cross‑ref §29→§27
- ADR‑21 cũ ghi `page_views` "intent §27/§29"; §29 KHÔNG có page_views (ở **§27**, ~dòng 609). Sửa → "intent §27".

### V4 (cross‑ref) — ADR‑20 "theo §9"
- Row 20: "giữ entries thuần page|post **theo §9**". Ràng buộc `entries.type ∈ {page,post}` đặc tả ở **§8** (Content engine: Type×Category×Template); §9 = Taxonomy. ⇒ nên trỏ **§8** (hoặc §7/ADR‑14). Nhẹ.

## C. GAP phối hợp (không phải lỗi doc)
### V5 — ADR‑21 chồng lấn council item "instant‑nav L2"
- `docs/notes/storefront-instant-nav.md:65` đã cắm **council item riêng** sửa `AddSecurityHeaders` khi build **L2 Speculation Rules**: CSP static hiện `script-src 'self' challenges.cloudflare.com` **CHẶN** inline `<script type="speculationrules">`.
- ADR‑21 (CSP động + nonce) đụng **cùng** `AddSecurityHeaders`. ⇒ Hai council item phải **gộp/điều phối** để không rework middleware 2 lần với giả định trái nhau. Khi ADR‑21 chốt → cuốn nhu cầu nonce của speculationrules L2 vào chung.

## D. Chốt (reconciled 2 vòng — 3 diff doc‑only, áp CÙNG NHAU)
- **(i) ADR‑20 dòng 728:** `theo §9` → `theo §8/ADR‑14` (ràng buộc type∈{page,post} ở §8, không phải §9). ACT.
- **(ii) ADR‑21 dòng 729 (cell gọn) + block `> Ghi chú`:** cell = CSP động **storefront‑only** + admin giữ omission có chủ đích + `page_views` §27 + spatie=**dependency COUNCIL**; Ghi chú = ⚠ nonce×L0‑cache → ưu tiên hash/keyword (`'inline-speculation-rules'`), nonce chỉ response không‑cache + GỘP speculationrules‑L2 (`storefront-instant-nav.md:65`). ACT (adopt spatie = COUNCIL riêng).
- **(iii) §29:633:** annotate as‑built (admin OMIT CSP có chủ đích, storefront‑only) — **bắt buộc đi cùng (ii)** để doc tự‑nhất‑quán; caveat `@alpinejs/csp` re‑visit. ACT.
- **§29 ref: KHÔNG sửa** (đúng). Commit: branch→PR doc‑only, cross‑link `storefront-instant-nav.md` 2 chiều.
