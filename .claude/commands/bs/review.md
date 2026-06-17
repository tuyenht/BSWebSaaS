---
description: Senior reviewer soi diff theo Diamond++ — tìm vi phạm §2/§3, N+1, thiếu UX/SEO
argument-hint: [diff / file / đề xuất cần review]
---
Với vai trò senior reviewer, soi diff/đề xuất dưới đây theo Diamond++ (CLAUDE.md §10.3). Tìm cụ thể:

- Vi phạm bảo mật §2: thiếu `site_id` · bỏ RLS · lộ bigint thô · upload không sniff magic-bytes.
- Vi phạm DB §3: float cho tiền · `timestamp` naive · native ENUM · EAV trá hình · reserved word làm tên cột.
- N+1 ẩn / vượt Query Budget · migration không đường lùi.
- Thiếu UX/SEO: empty/loading/error state · meta/canonical/JSON-LD/sitemap · breadcrumb · pagination rel=prev/next.

Với mỗi quyết định có trade-off → hỏi "tại sao cách này thay vì phương án khác?".
Nêu rõ: vấn đề + mức độ nghiêm trọng + cách sửa. KHÔNG rubber-stamp.

$ARGUMENTS
