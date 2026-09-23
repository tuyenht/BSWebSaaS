> 🗄 **LƯU TRỮ (24/09/2026)** — tài liệu làm việc của đợt đồng bộ thiết kế tháng 7/2026, giữ làm lịch sử.
> KHÔNG phải nguồn sự thật: có chỗ đã lỗi thời hoặc mâu thuẫn quyết định sau này. SSOT = `docs/design.md`
> (chỉ mục ADR ở Phụ lục A) + `docs/README.md`. Lý do lưu trữ: `docs/archive/README.md`.

Chạy audit doc‑only (propose‑first, KHÔNG code, KHÔNG migrate) để reconcile 5 điểm dưới đây vào `docs/design.md`. Đây là việc COUNCIL/tài liệu — dừng chờ tôi duyệt diff trước khi ghi. Grounded: verify lại CODE first‑hand, CODE thắng khi mâu thuẫn.

BỐI CẢNH: audit thiết kế đã đối chiếu v2.14 (commit c040caa) với CODE. Doc‑sync phần lớn chuẩn; 4 ADR Draft đúng; "chưa migrate gì" đúng (migration dừng 2013). Nhưng có 5 điểm cần làm rõ:

1) [ĐÁNG CHÚ Ý] ADR‑21 (design.md dòng ~729) viết CSP‑nonce sẽ "vá ... admin‑plane hở hiện tại". Nhưng `app/Http/Middleware/AddSecurityHeaders.php` (docblock) ghi rõ: admin+platform CSP **bỏ CÓ CHỦ ĐÍCH** — Livewire/Alpine cần `unsafe-eval`, đặt CSP chặt sẽ vỡ admin UI, và omission là "acceptable" vì đã gated sau auth. ⇒ Đây KHÔNG phải "hở". Council chốt: (a) chỉ làm CSP‑nonce ĐỘNG cho **storefront** (giữ admin omission như as‑built), hay (b) thêm nonce‑CSP cho admin — nêu rõ nonce KHÔNG loại được `unsafe-eval` của Livewire/Alpine nên lợi ích hạn chế. Nghiêng (a). Sửa lại câu chữ ADR‑21 cho khớp as‑built (đừng gọi omission có chủ đích là "hở").

2) `spatie/laravel-csp` CHƯA có trong `composer.json`. CSP hiện = chuỗi tay trong `AddSecurityHeaders::storefrontCsp()`. ⇒ Ghi rõ trong ADR‑21: adopt spatie/laravel-csp = **thêm dependency** (COUNCIL riêng) + migrate CSP‑tay → policy động; đừng để người đọc tưởng đã cài.

3) ADR‑21 trỏ "hiện thực §29 đã đặc tả" nhưng đặc tả CSP‑động‑per‑site + nonce + spatie nằm ở design.md dòng ~634 (vùng Script Manager/§14 hoặc §18). Sửa cross‑ref về đúng §.

4) ADR‑20 (dòng ~728) viết "giữ entries thuần page|post theo §9" — ràng buộc type∈{page,post} thực ra ở §8 (Content engine), §9 là Taxonomy. Sửa cross‑ref → §8 (hoặc §7/ADR‑14).

5) ADR‑21 đụng cùng `AddSecurityHeaders` với council item đã cắm ở `docs/notes/storefront-instant-nav.md:65` (sửa CSP để cho phép inline `<script type="speculationrules">` khi build L2). ⇒ Ghi chú chéo: khi hiện thực ADR‑21, GỘP nhu cầu nonce của speculationrules‑L2 vào cùng đợt sửa middleware (tránh rework 2 lần, giả định trái nhau).

YÊU CẦU OUTPUT: mỗi điểm → (a) xác nhận/bác bằng trích CODE, (b) câu chữ diff đề xuất cho design.md (chưa ghi), (c) phân loại ACT/COUNCIL. KHÔNG sửa file tới khi tôi duyệt. Nếu tìm thêm vênh khi verify → báo.
