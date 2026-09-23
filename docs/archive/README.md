# `docs/archive/` — tài liệu lưu trữ

Tài liệu đã hết vai trò nhưng giữ lại để tra lịch sử. **Không phải nguồn sự thật** — khi mâu thuẫn,
`docs/design.md` (+ chỉ mục ADR ở Phụ lục A) và code thắng.

## `2607-design-sync/` (lưu trữ 24/09/2026)

Tài liệu làm việc của đợt đồng bộ project thiết kế ↔ repo (tháng 7/2026), nhập vào `docs/` hôm 12/07.

| File | Vì sao lưu trữ |
|---|---|
| `implementation_roadmap.md` | Wave 0 ghi `entries.type += 'block'` và bảng `content_revisions` — trái quyết định đã chốt (ADR-20 dùng bảng typed `content_blocks`; lịch sử giữ `entry_revisions` typed — `design.md` Phụ lục A). Roadmap hiện hành: `docs/roadmap.md`. |
| `session_handoff.md` | Bàn giao + prompt cho một phiên THIẾT KẾ (không phải cho repo). |
| `db_audit_storage.md` | Tự ghi đã bị thay bởi hai file trên. |
| `builder_v2_audit.md`, `builder_v2_alignment.md` | Đối chiếu bản khám phá Page Builder v2; bản thi công là v3 (`docs/builder_v3_spec.md`). |
| `_claude_code_prompt.md`, `_claude_code_ADR19_prompt.md`, `_claude_code_ADR04_styleprops_prompt.md`, `_claude_code_audit_prompt.md` | Prompt đã dùng xong. |
| `_audit_v2.14_findings.md` | Biên bản audit v2.14 — kết quả đã gập vào `design.md` (changelog v2.14). |
