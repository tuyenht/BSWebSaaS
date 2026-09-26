---
description: Council audit — propose-first, KHÔNG tự thực thi
argument-hint: [vấn đề cần audit]
---
Audit kỹ vấn đề dưới đây theo Council Protocol (CLAUDE.md §10.2) và checklist Diamond++ (§10.3).

Đây là việc COUNCIL — propose-first, KHÔNG tự thực thi, DỪNG chờ tôi xác nhận.
- Neo MỖI phát hiện vào bằng chứng thật (file:dòng / lệnh / grep đã chạy); không neo được → ghi "chưa xác minh", không tính defect.
- Mỗi vai trò chuyên gia liên quan nêu 1 phương án + trade-off.
- Tranh biện điểm xung đột → 1 khuyến nghị duy nhất.
- Nêu rõ phương án bị loại + lý do (1 câu/phương án).
- Săn lỗi MỚI thật (đọc code/runtime), không tóm tắt lại backlog đã biết. Kết bằng 1 khuyến nghị dứt khoát bằng lời — không kết bằng danh sách lựa chọn AskUserQuestion.
- Tự phản biện trước khi trình:
  - Đọc script/lệnh mình định đề xuất chạy (tác dụng phụ lên DB dùng chung, prod).
  - Tra quyết định cũ của owner (design.md, git log, memory) trước khi đề xuất đảo — nếu đảo, nêu quyết định gốc + lý do mới.
  - Đo thay vì ước lượng khi con số quyết định khuyến nghị.
- Báo cáo: chat = kết luận + bằng chứng then chốt. Chỉ ghi file `plans/reports/` khi cần bàn giao sang phiên khác; khi đó chat trỏ tới file, không chép lại toàn bộ.

$ARGUMENTS
