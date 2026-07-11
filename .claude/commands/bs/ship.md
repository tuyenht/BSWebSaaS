---
description: Chốt theo khuyến nghị Council + thực thi đạt Diamond++, tự chứng minh xanh
argument-hint: [tùy chọn — để trống nếu tiếp nối council ở trên]
---
Chốt theo khuyến nghị Council ở trên — nếu CHƯA có council/khuyến nghị ở trên, tự chạy Council (§10.2) chốt phương án TRƯỚC. Rồi thực thi đạt Diamond++ (CLAUDE.md §10.3):

- Tuân thủ §2 (bảo mật), §3 (D1–D15), §5 (coding standards).
- Tự chạy guardrail §6: Pest · Larastan max · Pint · Rector · Query Budget · Arch · Gate #1.
  Nếu chạm storefront/UI → thêm Lighthouse + axe. Nếu chạm class trong baseline mutation → `pest --mutate`.
- Nếu đỏ → tự sửa và chạy lại đến khi TẤT CẢ xanh.
- DÁN output cuối cùng làm bằng chứng. Chưa xanh = chưa được báo done (§10.4).

$ARGUMENTS
