# Storefront Instant Navigation — Quyết định & ràng buộc (L1 View Transitions)

> **Loại:** decision-freeze (note rời, quy ước docs/notes — KHÔNG đánh số ADR-NN; nằm dưới **ADR-02** làm tinh chỉnh tầng theme/vận hành, không phải trụ kiến trúc mới).
> **Trạng thái theo lớp:** **L1 = Accepted** · **L0 = Deferred** (→ §25/ADR-06) · **L2 = Proposed, điều-kiện-benchmark**.
> **Dẫn xuất từ:** design.md **§6** (ADR-02, tầng trình bày) · **§10/ADR-11** (i18n URL/PathBuilder) · **§18** (SLO CWV + benchmark TTFB-VN) · **§24** (UX tối ưu vô hình) · **§25/ADR-06** (cache 5 tầng) · **ADR-15** (hạ tầng/CDN).
> **Nhãn bằng chứng:** 🟦 người/council chốt · 🟨 suy luận chưa kiểm (verify khi build) · ✅ đã verify từ repo.
> **Phạm vi:** doc-only. Chưa có code (greenfield ✅ — 0 match `view-transition`/`speculationrules` trong themes/ & resources/views/). Không đụng schema/RLS/PathBuilder/routes.

---

## 0. TL;DR

Làm storefront chuyển trang **mượt/tức thời**, bật mặc định vô hình cho mọi tenant, mà KHÔNG phá SLO CWV (§18) và KHÔNG vi phạm ADR-02 (Blade SSR + Alpine, không SPA). Kiến trúc **3 lớp theo phụ thuộc hạ tầng**:

| Lớp | Nội dung | Phụ thuộc | Trạng thái |
|---|---|---|---|
| **L1** | Cross-document View Transitions (MPA, CSS thuần, **0 JS**) | không phụ thuộc cache/edge | **Accepted — làm sớm, mặc định vô hình** |
| **L0** | Edge full-page cache | ADR-06 chưa build + benchmark TTFB-VN | **Deferred → §25/ADR-06** |
| **L2** | Speculation Rules (prefetch/prerender) | L0 + cache-hit-ratio đo được + benchmark VN + CSP delta | **Proposed, điều-kiện-benchmark** |

**Vì sao MPA + View Transitions, KHÔNG SPA:** SPA/client-router ăn JS budget, hại INP, phức tạp cache, rủi ro SEO — trái ADR-02 (design.md:165 "KHÔNG dùng React/Next ở storefront" ✅) và §18. View Transitions cho "độ mượt như SPA" với ~0 JS runtime, giữ một-URL-một-document-SSR (tốt SEO/AEO §18, OG/Zalo preview) và **tránh gánh nặng focus-management/aria-live của SPA** (a11y tốt hơn — củng cố ADR-02).

---

## 1. Context / Vấn đề

**SLO CWV (§18, design.md:501–508 ✅) — không được phá:**

| Chỉ số | Ngưỡng p75 |
|---|---|
| LCP (mobile) | ≤ 1.5s |
| INP | ≤ 200ms |
| CLS | < 0.1 |
| TTFB (VN) | ≤ 200ms |
| JS public | ≤ 30KB gzip |
| Lighthouse Perf | ≥ 95 |

**Ràng buộc bối cảnh:**
- **§24 (UX vô hình):** tối ưu chạy tự động sau hậu trường, **bật mặc định — KHÔNG phải toggle chủ site**.
- **§25/ADR-06 "cold-cache long-tail":** host 10k+ site, đa số ít traffic → cache dễ hết hạn GIỮA các lượt xem → tỉ lệ cache-miss cao.
- **§18 cảnh báo benchmark TTFB-VN:** origin Singapore + Cloudflare Anycast; VNPT/Viettel thường route sang SG → **cache-HIT ~40–90ms, cache-MISS >200ms p75**. ⇒ "prefetch/prerender ≈ free" là **SAI** trong bối cảnh VN.
- **ADR-15:** edge-provider **CHƯA khóa cứng** — nhánh có thể chuyển origin về VN (CloudFly) + Bunny/VNCDN, khi đó **Cloudflare chỉ giữ DNS/SSL/WAF** (không Worker trên serving path).

---

## 2. Decision

### 2.1 · L1 — Cross-document View Transitions — **Accepted** 🟦

- Opt-in bằng CSS: `@view-transition { navigation: auto; }` (xem Phụ lục §8).
- **Bật mặc định vô hình** cho mọi tenant (§24) — không phải lựa chọn của chủ site.
- **CSS thuần, 0 JS** ở GĐ1 (chỉ `vi`). Bám vùng cố định **đã tồn tại** trong theme: `header.site-header`, `nav.site-nav`, `footer.site-footer` (✅ header.blade.php:6,15 · footer.blade.php:7 — chưa có `view-transition-name`, sẵn sàng đặt tên).
- **CSP-sạch:** `@view-transition`/`view-transition-name` là **CSS** → hợp `style-src` hiện tại (`'unsafe-inline'` cho style ✅) hoặc stylesheet ngoài `'self'`. **L1 KHÔNG cần đổi CSP.**
- **Kill-switch cấp nền tảng:** gỡ at-rule `@view-transition` khỏi theme CSS = tắt toàn cục tức thì, **0 migration, 0 rủi ro dữ liệu** (reversible tuyệt đối).

### 2.2 · L0 — Edge full-page cache — **Deferred**

Nền tốc độ cho L2. Thuộc lộ trình cache §25/ADR-06. **Hiện DESIGN-ONLY** (✅ không có middleware full-page-cache / `Cache-Control` / tag-invalidation trong code; chỉ comment `bootstrap/app.php` + prose design.md:586–592). Không nằm trong scope note này.

### 2.3 · L2 — Speculation Rules — **Proposed, điều-kiện-benchmark**

- **Chốt sau** benchmark TTFB-VN 3 ISP + cache-hit-ratio đo được (§18/§25). CHƯA thiết kế cứng.
- **Injection provider-agnostic:** server-side qua **Blade** (`<script type="speculationrules">` trong `<head>`), **KHÔNG** qua Cloudflare Worker 🟦 (edge-provider chưa chốt — nhánh ADR-15 có thể bỏ Worker khỏi serving path).
- **🔴 Ràng buộc CSP (verify khi build L2):** CSP hiện tại `script-src 'self' https://challenges.cloudflare.com` (✅ AddSecurityHeaders.php:98–107) **CHẶN** cả inline `<script type="speculationrules">` lẫn inline JS. ⇒ Khi tới L2 phải (council riêng, sửa AddSecurityHeaders):
  - thêm **`'inline-speculation-rules'`** vào `script-src` cho phần rules (keyword hẹp — vẫn chặn inline-JS thường, ~0 tăng attack surface);
  - mọi JS thực thi (skip-script Phase-2, xem §4) ship **bundle ngoài `/build/*`** (khớp `'self'`), **KHÔNG** inline, **KHÔNG** nonce (nonce phải đổi mỗi response → **xung khắc L0 full-page-cache** trang cache dùng lại nonce cũ 🟨).

---

## 3. Ranh giới & bất biến

- **VT giữ *vị trí/hình ảnh* vùng cố định (header/footer/sidebar/nav), KHÔNG giữ *DOM/state sống*.** Vùng cố định có state sống liên tục (mini-cart đang mở, **mobile-menu đang mở**, media player, filter đang chọn) → cần **L3 (soft-nav morph)** — **DEFERRED**. Tiêu chí L3 = "vùng cố định *chứa state sống*", KHÔNG phải "brochure vs app".
- **PathBuilder:** L1 chỉ **đọc** output (default-locale unprefixed + non-default `/{locale}`), **KHÔNG sửa**. `data-spec` (§5) là thay đổi **Phase-2/L2** *mở rộng* PathBuilder/menu-renderer → **council riêng khi tới L2**, ngoài scope L1.
- **Cô lập tenant:** speculation/prefetch chỉ **same-origin** (storefront per-host) → **không prefetch chéo tenant**; request speculative đi qua middleware Gate#1 như thường → **RLS vẫn áp** (✅ bất biến §2).
- **Bất biến "speculative-request side-effect-free"** (mở rộng từ "không đếm view"): mọi GET mang `Sec-Purpose: prefetch` **KHÔNG được mutate state** — view-counter, "recently viewed", analytics beacon server-side, rate-limit, A/B assign, **regenerate session / rotate CSRF token / tiêu one-time token** (rotate token khi prerender sẽ làm hỏng form user đang mở ở trang hiện tại 🟨).

---

## 4. i18n × instant-nav (path-based) 🟦

- **Same-locale** (duyệt cùng ngôn ngữ): VT đầy đủ (persistent + content cross-fade).
- **Locale-switch** (đổi ngôn ngữ, vd `/gioi-thieu` → `/en/about-us`): **skip VT → load bình thường**. Điểm skip = **`pageswap`** của trang đi (đọc `event.activation.from` + `event.activation.entry` **TRƯỚC** khi trang cũ bị snapshot), **KHÔNG** phải `pagereveal`.
- **An Vinh Phase 1 chỉ `vi`** → locale-switch chưa tồn tại → **skip-script CHƯA cần build**. Chỉ ghi thiết kế cho Phase 2.
- **Sequencing Phase 2 (quy tắc cứng 🟦):** skip-script phải deploy **CÙNG PR** với việc bật `en`/`ja`. Cấm bật locale mới trước khi có skip-script (nếu không → header snap text giữa 2 PR).
- **Phân loại cần CẢ locale-hiện-tại + manifest (không chỉ prefix-đích):** link tới **default-locale không có prefix** → chỉ so prefix-đích không đủ để phân biệt. Vd: `/en/about` → `/gioi-thieu` (switch, phải skip) vs `/gioi-thieu` → `/lien-he` (same, không skip) — chỉ khác nếu biết current=en hay current=vi. ⇒ Server inject **`data-locale` (locale trang hiện tại)** + **manifest danh sách locale-prefix enabled** (từ `site_locales`, **cùng nguồn PathBuilder**); script so khớp **chính xác** (KHÔNG heuristic "2 ký tự đầu" — tránh nhầm `/en/` với slug `/en-nghiep-vu/`). **Arch-test** canh đồng bộ CẢ HAI ↔ PathBuilder.
- **⚠️ Open-question Phase 2 (CHƯA chốt — chốt sau khi test Safari 26.2):** khi `event.activation` null (Safari thiếu `NavigationActivation`), làm sao degrade êm?
  - **(a) name TĨNH trong CSS + zero-JS** — chấp nhận header snap `0s` khi activation null (hiếm, chỉ browser thiếu API).
  - **(b) JIT name qua JS + mặc định cross-fade** — degrade êm nhưng **mất zero-JS** cho cả nav same-locale.
  - **(c) bắt intent lúc `click`/`pointerdown`** (đọc locale đích trước khi điều hướng) — chạy được cả khi thiếu Navigation API, nhưng cần JS (ngoài, per CSP §2.3).
  - **GĐ1 An Vinh chỉ `vi` → mọi nav là same-locale → name tĩnh chạy hoàn hảo, tension KHÔNG phát sinh.**

---

## 5. Speculation contract (L2, Phase sau — lock ý định) 🟦 provisional

- Đánh dấu link speculatable bằng thuộc tính server-side **`data-spec`** (đặt qua PathBuilder/menu-renderer), chỉ **internal same-locale**.
- **Loại khỏi speculation:** language-switcher (KHÔNG `data-spec`), URL 301-bound (`/{default}/*`), form/CSRF/Turnstile, search, mọi mutation, logout.
- **Eagerness bảo thủ:** `conservative`/pointerdown (chạy cả mobile) mặc định; `moderate`/hover chỉ bổ trợ desktop. **Prerender rất dè dặt** (đính chính VN latency §1).
- **Tôn trọng dữ liệu người dùng:** khi request mang `Save-Data: on` / `prefers-reduced-data` → **KHÔNG phát speculation** (VN mobile nhạy chi phí data; đừng ngốn data plan của khách) 🟨.
- Áp **bất biến side-effect-free** §3 cho mọi request speculative.

---

## 6. Consequences / Rủi ro

- **(a)** VT là *chi phí* CWV nhẹ (**~+70ms LCP mobile 🟨 ước lượng, phải đo**, có thể tăng INP) → bù bằng L0 khi có.
- **(b)** form + full-page-cache → form phải là **fragment động** (ESI, §25), không full-cache, không prerender (phá CSRF/Turnstile).
- **(c)** **Firefox** chưa hỗ trợ VT cross-doc → progressive enhancement, fallback `@supports`.
- **(d)** **Safari** có thể thiếu `NavigationActivation` → fallback cross-fade/snap (§4 open-question).
- **(e)** **"L1 độc lập cache/TTFB" chỉ đúng ở mức *ship*, KHÔNG ở mức *UX*:** cross-doc VT **giữ ảnh trang CŨ đóng băng** tới khi trang mới render xong (hoặc ~4s bailout Chrome). Trên cold-cache VN→SG miss (>200ms, render lạnh còn tệ hơn) → cảm giác **treo/lag**, có khi tệ hơn nav thường 🟨. ⇒ payoff thật của L1 vẫn muốn L0.
- **(f)** **Prerender khuếch đại tải origin:** mỗi prerender = 1 lần SSR đầy đủ; trên cold-cache long-tail (§25), prerender trang không ai xem = nhân CPU/DB origin → rủi ro **chi phí/định mức** (tách biệt với latency) 🟨. ⇒ prefetch-hơn-prerender.
- **(g)** Analytics **bên thứ 3 của tenant** (GA/GTM) có thể đếm sai dưới prerender (ngoài tầm kiểm soát nền tảng) — **known limitation**.

---

## 7. Verification (theo CI THẬT, không tô hồng)

- **CI thật hiện chạy** (✅ .github/workflows/ci.yml): Pint · Larastan(max) · Pest (unit·feature·arch·query-budget·**concurrency=Gate#1**) · Vite build. **KHÔNG có Lighthouse-CI / axe gate** (chỉ prose design.md). ⇒ **KHÔNG tuyên CWV là CI-gate.**
- **Near-term nghiệm thu L1** = **Lighthouse/PageSpeed thủ công** (before/after) + **cross-browser thủ công** (Chrome ✓ · Firefox fallback · Safari) + **test low-end Android** (VN phổ biến — VT có thể jank máy yếu). Pest **không** test được VT/prerender.
- **Tiền đề PHẢI dựng (hiện chưa có):**
  - **RUM ingest** — `web_vitals_samples` (§18) **DESIGN-ONLY** ✅ (0 migration/model/endpoint). Nghiệm thu "RUM thực địa" chỉ khả thi sau khi dựng.
  - **E2E harness** — repo **chưa có** Dusk/Playwright/Cypress ✅ (chỉ Pest). Cần dựng khi build L1 để test VT/skip/traverse/bfcache.
- **Success criteria (khung đánh giá đúng):** L1 "thành công" = **KHÔNG regression CWV quá budget + mượt định tính**; **KHÔNG kỳ vọng CWV cải thiện** (VT có thể hơi *tăng* LCP/INP — đừng đánh giá L1 thất bại vì Lighthouse không tăng) 🟨.
- **Safari 26.2:** cần **test thật** xem có expose `NavigationActivation` không → quyết fallback §4.
- **Benchmark TTFB-VN 3 ISP (§18):** **chốt chặn cho L2** — việc Phase 0-1 phải làm độc lập.

---

## 8. Phụ lục — pattern CSS phủ vùng cố định (giữ để khi build L1 không khám phá lại)

```css
/* Bật VT toàn cục (cả trang đi & đến) */
@view-transition { navigation: auto; }

/* Mỗi vùng cố định: view-transition-name DUY NHẤT + class chung "persistent" */
header.site-header { view-transition-name: site-header; view-transition-class: persistent; }
footer.site-footer { view-transition-name: site-footer; view-transition-class: persistent; }
nav.site-nav      { view-transition-name: site-nav;    view-transition-class: persistent; }
/* … thêm vùng nào chỉ cần thêm 1 name + class persistent … */

/* Giữ CWV: duration ngắn, chỉ animate transform/opacity (compositor) */
::view-transition-group(*) { animation-duration: 250ms; }
/* Cô lập layout cho element mang view-transition-name (gán class ở server, tránh selector [style*=]) */
.vt-named { contain: layout; }

/* MỘT rule phủ mọi vùng cố định → đứng yên. ĐẶT SAU để 0s thắng 250ms.
   ⚠️ Chỉ tắt animation của *group*; cross-fade ở ::view-transition-old/new là pseudo RIÊNG.
   Vùng giống-hệt thì vô hình; vùng lệch nhẹ (vd active-nav) có thể còn nháy →
   VALIDATE bằng E2E, có thể phải thêm rule ::view-transition-old/new(*.persistent). */
::view-transition-group(*.persistent) { animation-duration: 0s; }

/* Lưu ý: name TĨNH ở trên = zero-JS, tối ưu GĐ1 (chỉ vi).
   Nếu Phase 2 chọn (b) "JIT name để degrade cross-fade khi đổi ngôn ngữ"
   thì name chuyển sang set bằng JS qua pageswap/pagereveal (bundle NGOÀI, per CSP §2.3). */

/* Fallback trình duyệt chưa hỗ trợ (vd Firefox) */
@supports not (view-transition-name: none) { /* style dự phòng, không phụ thuộc VT */ }

/* Tôn trọng giảm chuyển động */
@media (prefers-reduced-motion: reduce) {
  ::view-transition-group(*), ::view-transition-old(*), ::view-transition-new(*) { animation: none !important; }
}
```

**Sổ mìn thực thi** (chuyển thành issue khi build L1, đặt ở `plans/`):
- **Tên VT duy nhất mỗi trang** — BlockRenderer lặp → suffix **globally-unique `ulid`/block-id**, **KHÔNG dùng ordinal per-page** (nếu block#3 trang A và block#3 trang B trùng name → browser morph nhầm 2 block không liên quan). Bản Chrome mới có thể *bỏ qua* tên trùng thay vì hủy transition.
- Vùng chỉ xuất hiện ở vài trang → CSS enter/exit riêng, KHÔNG gộp `persistent`.
- **Prerender side-effect** → gate bằng `document.prerendering`/`prerenderingchange` (client) + server đọc `Sec-Purpose: prefetch` để **không đếm view / không mutate** (§3).
- **4-giây bailout** của Chrome cần trang nhanh → nối L0.
- **Font CJK** khi sang `/ja/` cần preload/render-block trước snapshot (Phase 2).
- **CSP delta** cho L2 (`'inline-speculation-rules'` + bundle ngoài) — §2.3, council riêng.

---

## 9. Bước kế

1. **Benchmark TTFB-VN 3 ISP** (VNPT/Viettel/FPT, cả cache-hit & miss) — **chốt chặn L2**, Phase 0-1, độc lập note này.
2. Khi build L1: mở issue từ **sổ mìn §8** (đặt `plans/`); dựng **E2E harness** + (song song) **RUM ingest** như tiền đề nghiệm thu §7.
3. L2 chỉ mở khi có **L0 + benchmark đạt + council CSP** (§2.3).

## Câu hỏi mở (chưa giải — chờ dữ liệu/thử nghiệm)
- Safari 26.2 có expose `NavigationActivation`? → quyết fallback §4.
- Phase-2 fallback đổi ngôn ngữ: (a) tĩnh-zero-JS / (b) JIT-JS / (c) click-capture — chốt sau test Safari.
- L2 provider + eagerness cuối — chốt sau benchmark TTFB-VN.
