# Chuẩn SEO cho 2 luồng biên tập — ĐÃ ÁP (04/08/2026)

Nguồn duy nhất: **`content-seo.js` (`window.BSSEO`)**. Không màn nào khai lại bảng kiểm.
Bộ nguồn dùng chung hiện có: `ai-quota.js` (BSAI) · `content-workflow.js` (BSCW) · `content-ui.js` (BSUI) · **`content-seo.js` (BSSEO)**.

## A. API

```js
BSSEO.audit({ kind:'post'|'page', keyword, title, seoTitle, seoDesc, excerpt, slug, body,
              host, hasImage, alt, ogImage, cats, tags, author, otherTitles })
  → { items, tiers:[must|should|plus], ok, total, mustMissing, blocking, metrics }
BSSEO.plan(report) → { free[], paid[], manual[], words, total }
```

Mỗi mục có `fix`: **`auto`** (máy tự làm, KHÔNG trừ từ AI) · **`ai`** (phải sinh chữ, kèm `words`) · **`manual`** (người tự làm, kèm câu `how` một dòng).
Chưa nhập từ khoá → các mục bám từ khoá (tiêu đề, đường dẫn, mở bài, tiêu đề phụ, mật độ) tự chuyển sang `manual` với câu "Nhập từ khoá chính ở ô trên trước đã" — không mời AI làm việc bất khả thi.

## A-bis. Giới hạn ký tự — chỉ khai ở `BSSEO.LIMITS`

```js
BSSEO.limits(field)        → { min, best:[lo,hi], display, max, cut }
BSSEO.meter(field, len)    → { band, text, ink, fill, pct,
                               countStyle, trackStyle, fillStyle, tickStyle }
```

| Trường | Quá ngắn | Vùng đẹp | Mốc hiển thị | Mốc nên dừng |
|---|---|---|---|---|
| `title` tiêu đề bài/trang | < 20 | 30–60 | 60 | 100 |
| `seoTitle` tiêu đề Google | < 20 | 35–60 | 60 | 80 |
| `seoDesc` mô tả Google | < 70 | 150–160 | **160** | **200** |
| `excerpt` mô tả ngắn | < 80 | 120–200 | 200 | 240 |
| `alt` mô tả ảnh | < 10 | 25–125 | 125 | 150 |
| `slug` đường dẫn | < 3 | 10–60 | 75 | 90 |

**Nhập được dài hơn mốc hiển thị** (mô tả Google tới 200 ký tự) — chỉ cảnh báo, không chặn gõ.
Dải màu 6 mức, luôn kèm nhãn chữ (không bao giờ chỉ dùng màu):

- trống → xám `#dee2e6` · "Nên 150–160 ký tự"
- quá ngắn (< min) → vàng `#f7b84b` · "còn quá ngắn"
- gần đủ (min → dưới vùng đẹp) → xanh dương `#299cdb` · "gần đủ"
- **vùng đẹp → xanh lá `#0ab39c` · "vừa đẹp"**
- vượt mốc hiển thị (≤ max) → vàng `#f7b84b` · "Google chỉ hiện khoảng 160 ký tự đầu"
- **vượt max → đỏ `#f06548` · "quá dài, nên rút bớt"**

Dưới mỗi ô là dải 4px: rãnh xám có **vùng đẹp tô xanh mờ** làm đích, thanh chạy đổi màu theo mức,
vạch xám mảnh đúng mốc hiển thị. Đã áp cho tiêu đề · mô tả ngắn · tiêu đề Google · mô tả Google
ở **cả hai màn**; mọi câu chấm điểm gợi ý AI cũng đọc `lim(field)` nên số không bao giờ lệch nhau.

**Chặn gõ chỉ ở mốc cứng `BSSEO.hardCap(field)` = `max × 1,25`** (mô tả Google 250), không bao giờ ở
mốc hiển thị — nhờ vậy mức cảnh báo đỏ vẫn chạm tới được và hai màn nhận cùng một lượng ký tự.
Màn dùng `this.cap(field)` cho mọi chỗ cắt ô nhập; chỉ chữ **do AI sinh** mới cắt theo `display`.

## B. 20 mục · 3 nhóm

**Bắt buộc (7)** từ khoá · tiêu đề ≤ 60 có từ khoá · mô tả Google 150–160 có từ khoá ·
đường dẫn ≤ 5 từ, không từ nối · từ khoá trong 100 từ đầu · ≥ 300 từ · ảnh đại diện + mô tả ảnh.

**Nên có (8)** ≥ 2 tiêu đề phụ không nhảy cấp, 1 mục chứa từ khoá · ≥ 1 liên kết nội bộ ·
≥ 1 nguồn ngoài mở an toàn · đoạn trả lời nhanh 40–60 từ · ≥ 1 danh sách/bảng ·
mọi ảnh trong bài có mô tả · mật độ từ khoá 0,5–2,5% · mô tả ngắn 120–200 *(chỉ bài viết)*.

**Nâng cao (5)** 2–4 câu hỏi thường gặp · đoạn ≤ 120 từ và câu TB < 25 từ ·
tiêu đề không trùng · ảnh chia sẻ · chuyên mục + ≥ 2 thẻ + tác giả *(chỉ bài viết)*.

→ Bài viết **20 mục**, Trang **18 mục** (bỏ mô tả ngắn và chuyên mục/thẻ).

## C. Cửa SEO sau khi chèn — đã dựng ở cả hai màn

Dải ngay dưới điểm số, đổi màu theo mức chặn (đỏ khi còn mục bắt buộc, vàng khi chỉ còn mục nên có,
xanh khi đủ), nói rõ **tách hai đường**:

- **"Tự hoàn thiện N mục"** — máy làm ngay, **không trừ từ AI**: cắt mô tả quá dài về ≤ 160, rút đường dẫn
  về từ khoá, viết mô tả cho ảnh đại diện và ảnh trong bài, gắn `rel=noopener` + mở tab mới cho liên kết
  ngoài, chèn khối liên kết tới bài cùng chuyên mục / trang khác của website.
  **Không bao giờ đệm chữ để đủ ký tự** — mô tả ngắn quá là việc của AI, không phải của máy nối chuỗi.
- **"Nhờ AI viết N chỗ"** — mở hộp `Nâng cấp nội dung` với nhóm **"Việc SEO còn thiếu"** tick sẵn, mỗi việc
  ghi ≈ số từ AI, cộng dồn trước khi bấm; thiếu hạn ngạch thì nút mờ và nói rõ số từ còn thiếu.
- Từng mục còn đỏ cũng có nút riêng **Tự sửa** / **Nhờ AI** ngay trên dòng đó.

Chạy xong → bảng kiểm tính lại ngay, điểm số và số việc còn lại đổi theo (đã kiểm: 6/20 → 8/20 sau tự
hoàn thiện → 10/20 sau khi AI chạy 8 việc, trừ đúng 823 từ AI).

## D. Còn lại

- Bước "Kiểm định" của `AI Writer Panel v2` vẫn chấm theo bộ tiêu chí riêng → phải gọi `BSSEO.audit`.
- Trang: mô tả ảnh đại diện (`imgAlt`) mới chỉ sửa được qua nút "Tự sửa" trong bảng kiểm, chưa có ô nhập
  ở thẻ ảnh đại diện.
- Chưa chặn nút Đăng khi nhóm 1 còn đỏ (mới chỉ cảnh báo) — chờ chốt có nên chặn cứng hay không.
