# `docs/` — bản đồ tài liệu BSWebSaaS

Đọc theo thứ tự: **CLAUDE.md** (quy ước + guardrail; luật theo vùng code ở `.claude/rules/`, agent tự nạp khi đọc file khớp)
→ **`design.md`** (thiết kế hệ thống, nguồn sự thật) → **`roadmap.md`** (đang ở đâu, làm gì tiếp) → phần liên quan bên dưới.

| Đường dẫn | Là gì | Ai sửa / khi nào |
|---|---|---|
| `design.md` | Thiết kế hệ thống — nguồn sự thật. Quyết định kiến trúc = ADR ở Phụ lục A + changelog `v2.x` | Chỉ sửa khi owner chốt (COUNCIL, CLAUDE.md §10) |
| `roadmap.md` | Vị trí hiện tại + các mạch việc + việc kế tiếp | Cập nhật khi một mạch đổi trạng thái |
| `saas-starter-screen-map.md` | Sổ đăng ký màn hình SaaS Starter: thiết kế / handoff / code / prompt — thước đo "đủ 100%" | Mỗi khi nhận handoff hoặc build xong một màn |
| `notes/` | Quyết định chi tiết theo chủ đề (normative) — vd `section-style-system.md`, `data-platform-standards.md` | Theo quyết định owner |
| `notes/admin-ux-conventions.md` | Bản sao luật UX của project thiết kế (CLAUDE.md §0–§15 + LESSONS phía thiết kế) để bản port Livewire trích dẫn | Chủ sở hữu luật là project thiết kế; đồng bộ khi nhận export mới |
| `adr/` | Bản chi tiết ADR-19..22 (Draft). Chỉ mục chính thức vẫn ở `design.md` | Theo từng ADR |
| `builder_v3_spec.md`, `builder_v3_section_catalog.md`, `reusable_blocks_spec.md`, `tracking_*.md`, `admin-architecture-decision.md` | Spec theo mạch (Builder v3, khối tái dùng, tracking, admin) | Theo mạch |
| `design-briefs/` | Brief và bộ prompt gửi Claude Design (đã được owner duyệt) | Khi đặt thiết kế mới |
| `design-source/` | Bản export đầy đủ dự án Claude Design (màn `.dc.html`, script dùng chung, token, ghi chú của dự án thiết kế) — nguồn sự thật về giao diện; mỗi lần export mới = 1 commit, `git diff` thấy đúng phần đổi | Khi owner gửi file .zip export |
| `design_handoff_<slug>/` | Handoff thiết kế đã nhận: `.dc.html` + `README.md` — port 1:1 (CLAUDE.md §8) | Khi nhận export từ Claude Design |
| `runbooks/` | Quy trình vận hành (deploy ứng dụng prod, deploy media worker, backup/restore DB) | Khi quy trình đổi |
| `archive/` | Tài liệu hết vai trò, giữ làm lịch sử — KHÔNG phải nguồn sự thật | Chỉ thêm |

`plans/` (ở gốc repo) bị `.gitignore` — là **nháp cục bộ** (kế hoạch đang làm, báo cáo phiên). Thứ gì cần bền
hoặc cần Claude Design đọc được qua GitHub thì đưa vào `docs/`.

## Quy ước đặt tên
File mới dùng **kebab-case** (`admin-ux-conventions.md`). Không đổi tên hàng loạt file cũ (tránh gãy liên kết).

## Mẫu README cho một handoff thiết kế (`design_handoff_<slug>/README.md`)
1. Tổng quan — màn gì, cho ai, mục tiêu
2. File — danh sách `.dc.html` + file dùng chung
3. Mức hoàn thiện (hi-fi / lo-fi) + trạng thái dữ liệu mẫu
4. Màn & trạng thái — trống · đang tải · lỗi · không có quyền · khoá theo gói · thành công
5. Tương tác — hành vi từng nút, phím tắt, xác nhận
6. Ánh xạ dữ liệu — bảng/cột/registry thật, có `file:dòng`
7. Quyền & gói — ai thấy gì (quyền, tính năng gói)
8. Token — màu/chữ/bo/bóng đã dùng (chỉ token AdminKit)
9. Icon — tên Remix từng chỗ (build bằng inline-SVG)
10. Chuỗi cần dịch
11. Kết quả 12 tiêu chí dễ dùng (`design-briefs/saas-starter-design-first/README.md`)
12. Việc cho backend — tính năng thiết kế có mà repo chưa có (dữ liệu cần lưu · luật); backend làm theo (CLAUDE.md §8)

## Prompt tự-đứng gửi Claude Design (UI chưa có handoff — CLAUDE.md §8)
Claude Design đọc repo qua GitHub (nhánh `main`) nhưng không chạy code; prompt vẫn phải đứng một mình và gồm đủ:
1. Bối cảnh: BSWebSaaS — SaaS website builder đa tenant, đa ngôn ngữ, admin phong cách Velzon (AdminKit).
2. Màn hình/luồng cần thiết kế + mục tiêu của người dùng.
3. Đối tượng không rành công nghệ: trạng thái trống · đang tải · lỗi bằng lời thường · không ngõ cụt · mobile-first ·
   tương phản WCAG AA (CLAUDE.md §10.3 mục 6).
4. Mô hình dữ liệu thật của repo (bảng/cột/registry; không lộ id số).
5. Ràng buộc kỹ thuật: Livewire 4 + Blade + Tailwind 4, component `<x-bs-admin::*>`, icon Remix dạng inline-SVG,
   KHÔNG Bootstrap/Flux; chỉ dùng token AdminKit (`admin-architecture-decision.md`).
6. Đầu ra: prototype tương tác `.dc.html` + README theo mẫu ở trên, lưu vào `design_handoff_<slug>/`.

Trong báo cáo: đánh dấu phần "⏸ chờ design" và dán nguyên văn prompt trong một khối code để owner chuyển tay.

## Nối Claude Code ↔ Claude Design (không để vênh)
Luật: **thiết kế dẫn, backend theo** (CLAUDE.md §8). Mỗi bên chỉ ghi phần của mình, nên không bao giờ hai bên cùng sửa một chỗ.

| Chiều | Kênh | Ai ghi |
|---|---|---|
| Thiết kế → code | File .zip export cả dự án Claude Design (đã gửi 17/09, 18/09, 23/09) → `design-source/` | Owner export; Claude Code nhập |
| Thiết kế → code | Link publish một màn (claude.ai/artifact) — đường tắt khi chỉ sửa 1 màn | Owner publish; Claude Code nhập |
| Thiết kế → code | README `design_handoff_<slug>/` — bảng "Việc cho backend" của từng màn | Claude Code |
| Code → thiết kế | `main` trên GitHub: prompt `design-briefs/`, README handoff, mã lỗi, bảng/cột | Claude Code (owner merge) |
| Trạng thái | `saas-starter-screen-map.md` — mục "Nguồn thiết kế" | Claude Code |

Vòng nhận thiết kế (Claude Code làm, mở đầu mỗi `/bs:next` và khi owner gửi link):
1. Đọc `design-source/` trước mọi audit/prompt/build một màn — thiết kế đã có thì KHÔNG đặt vẽ lại.
2. Nhận bản mới: file .zip → `python3 scripts/design_snapshot.py <file.zip>`; link publish một màn → đọc bằng Artifact `read`
   rồi `python3 scripts/design_snapshot.py <file đã lưu> "docs/design-source/<Màn>.dc.html"`. `git diff docs/design-source`
   = đúng phần thiết kế đổi. Mở đầu mỗi `/bs:next`: liệt kê bản publish (Artifact `list`) — bản nào mới hơn ngày trong sổ màn → nhập.
3. Cập nhật bảng "Việc cho backend" của màn; phần đổi cấu trúc dữ liệu → đặc tả COUNCIL (bàn cách làm, không bàn có làm hay không).
4. Làm backend → build giao diện 1:1 → đánh dấu ✅ trong README và sổ màn.
5. Thiết kế cần một điều backend không được phép (tách dữ liệu giữa website, bảo mật, pháp lý, danh sách rất lớn) → không tự cắt: ghi "Cần owner chốt" và hỏi owner.
