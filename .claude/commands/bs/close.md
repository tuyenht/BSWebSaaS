---
description: Chốt & bàn giao cuối session — tri thức bền + payload resume
argument-hint: [tiêu điểm — tùy chọn]
---
[/bs:close — CHỐT & BÀN GIAO CUỐI SESSION]

Với vai trò hội đồng chuyên gia cao cấp phù hợp: QUÉT SESSION 1 LẦN rồi in ra 2 khối —
A) tri thức BỀN cần lưu docs/Memory; B) payload RESUME dán vào session mới để nối việc liền mạch.
Đây là TRÍCH + PHÂN LOẠI, KHÔNG diễn kịch nhiều-vai, KHÔNG tự sửa SSOT (Autonomy Gate §10.1).
Mỗi khối TỰ no-op: không có gì thì nói rõ, đừng bịa.
Tiêu điểm ưu tiên khi quét (nếu có): $ARGUMENTS

── DÙNG CHUNG (làm 1 lần cho cả A và B) ──
- Xác minh thực địa, đừng dựa trí nhớ: `git status -s`, `git log --oneline -8`, nhánh, ahead/behind.
- Chỉ xét phần CÒN trong context. Đầu session đã rơi → ghi "có thể sót phần đầu" VÀ bơm cảnh báo
  đó vào payload RESUME (để session mới cũng biết).
- CẤM đưa bí mật vào BẤT KỲ khối nào (mật khẩu · credential · token · IP/host nội bộ · dữ liệu
  khách thật) — nhất là payload RESUME. Bí mật gắn 1 quyết định → ghi "(giá trị nhạy cảm — không chép)".
- KHÔNG khối nào ghi file / sửa SSOT. Cả 2 chỉ IN RA. Diff design.md chỉ soạn SAU khi tôi duyệt.
- Cả session sạch (không delta + không việc dang dở) → chỉ nói "session sạch", bỏ toàn bộ khung dưới.

═══════ KHỐI A · LƯU TRI THỨC BỀN (chờ tôi duyệt để vào docs/Memory) ═══════
Phạm vi: chỉ DELTA của CHÍNH session này — tính năng/nghiệp vụ mới lộ ra · quyết định · ràng buộc/
mìn vừa phát hiện · giả định bị bác bỏ · chỗ design.md/CLAUDE.md LỆCH code thực tế. KHÔNG tính thứ
tài liệu đã ghi sẵn (kể cả mới đọc trong session).

Xác minh & nhãn NGUỒN GỐC (mỗi mục):
- Trước khi nói "chưa có trong tài liệu": grep docs/ + CLAUDE.md (grep code / git log nếu cần) →
  GHI RÕ từ khóa grep đã dùng để tôi tự phán đủ/thiếu.
- Neo mỗi mục vào 1 mốc THẬT trong session rồi gắn: 🟦 tôi đã chốt · ✅ có output thật (trích 1
  dòng bằng chứng) · 🟨 mới đề xuất/chưa chắc. Không neo được → 🟨 hoặc bỏ. TUYỆT ĐỐI không ghi
  "đã quyết/đã xong" cho thứ chỉ mới bàn.

Phân loại rủi ro:
- Đụng DB schema / D1–D15 / các ADR / bảo mật §2 / ranh giới module → [COUNCIL], KHÔNG tự chốt,
  KHÔNG kèm diff — chỉ nêu để chuyển council.
- LẬT một quyết định đã đóng băng trong design.md → [COUNCIL] + ⚠RỦI RO CAO.

Đích đến (chỉ ĐỀ XUẤT):
- design.md → nêu § đích + tóm tắt thay đổi; khi được duyệt mới gói diff dạng mục changelog "vX.Y"
  (theo lệ v2.x); doc-only riêng.
- docs/notes/* → epic / ghi-chú / ADR-nhánh (freeze chính ghi ở changelog design.md).
- CLAUDE.md → chỉ khi là LUẬT thực thi mới.
- Memory (file-based, index `MEMORY.md`): ĐỀ XUẤT ở khối MEMORY UPDATE dưới — mỗi fact bền = 1 file
  + 1 dòng pointer vào `MEMORY.md`; GHI sau khi tôi duyệt (giữ tinh thần propose-only của lệnh này).
  Chỉ fact BỀN (quyết định/quy ước/sở thích lâu dài), KHÔNG trạng thái tạm. Có thể trùng memory sẵn có —
  dedup, đừng cố kiểm.

Bảng (không có delta → ghi "không có delta", bỏ bảng):
| # | Nội dung | Loại (tính-năng/quyết-định/ràng-buộc/lệch-doc) | Nhãn | Đích | Trạng thái (net-new/cập-nhật/⚠xung-đột) | Hành động (diff-sau-duyệt / [COUNCIL] / Memory) |
MEMORY UPDATE: mỗi dòng 1 fact bền, gắn [MỚI] hoặc [THAY ĐỔI: trước X → nay Y] (chỉ [THAY ĐỔI]
khi thấy đảo chiều NGAY trong session này; đảo fact cũ ngoài context → cứ [MỚI], council đối chiếu).

═══════ KHỐI B · BÀN GIAO NỐI VIỆC (payload dán vào session mới) ═══════
Payload phải GỌN & TỰ-ĐỦ: trỏ §/đường-dẫn thay vì dán nguyên nội dung; là bản DÙNG-MỘT-LẦN (vứt
sau khi nối xong). Mục rỗng ghi "—", đừng bịa. Nhãn TIẾN ĐỘ: ✅ xong+bằng chứng · 🔄 đang · ⬜ chưa · ⚠ chặn.

┌──── RESUME — DÁN VÀO SESSION MỚI ────
Dự án BSWebSaaS. Trước khi làm BẤT CỨ GÌ: theo [GIAO THỨC] ở cuối khối này.
[CẢNH BÁO] <"có thể sót phần đầu session" — nếu có; nếu không, bỏ dòng>
[MỐC GIT] nhánh <...> · commit cuối <hash + subject> · tree <sạch / bẩn: file...>
[§ CẦN ĐỌC] design.md <§...> (chỉ mục liên quan việc dưới — KHÔNG đọc cả file)
[VIỆC ĐANG DANG DỞ]  (dừng sạch → "không có việc dang dở; việc kế hoạch kế tiếp = <...>")
  - Mục tiêu: <...>
  - Đã tới đâu (🔄): <...>
  - HÀNH ĐỘNG KẾ TIẾP CHÍNH XÁC: <bước cụ thể + file/§ + lệnh nếu có>
[PLAN / TODO]  ✅ <...> (bằng chứng: <...>) · 🔄 <...> · ⬜ <...> · ⚠ <chặn>: <...>
[QUYẾT ĐỊNH CHỐT — bản rút gọn, CHỈ thứ cần để tiếp việc; bản chính thức ở docs/Memory, grep/hỏi nếu cần]
  - 🟦 <...>
[CHỜ TÔI DUYỆT / CÂU HỎI MỞ] <...>
[DOC/MEMORY CHỜ ÁP] <n> đề xuất docs/Memory đã nêu cuối session trước (chưa chắc đã áp). Trước khi
  làm việc phụ thuộc chúng → `git log`/grep hoặc hỏi tôi xem cái nào đã vào, đừng giả định.
[FILE ĐÃ ĐỘNG] <path + trạng thái>
[BẪY ĐANG CÓ] <vd: test chạy `docker compose run --rm` KHÔNG `exec`; reload octane khi tự mở trình duyệt>
[VIỆC ĐỖ (parked)] <thread phụ — đừng làm ngay>

[GIAO THỨC TIẾP TỤC — session mới PHẢI theo, đúng thứ tự]
1. XÁC MINH THỰC ĐỊA trước khi tin gói: `git status -s` + `git log --oneline -5` + nhánh; có
   test/lệnh nêu trên → chạy lại. Đối chiếu [MỐC GIT].
2. RECONCILE — thực địa THẮNG gói: "HÀNH ĐỘNG KẾ TIẾP" đã xong (evidence trong repo) → bỏ, nhảy
   mục kế. Nếu tree BẨN / `git status` thấy file sửa dở → `git diff` đọc phần đã làm TRƯỚC khi
   tiếp, đừng ghi đè/lặp. Lệch vật chất (quyết định frozen bị đụng / task đổi bản chất) → DỪNG,
   báo tôi. Lệch vặt → ghi chú 1 dòng rồi tiếp.
3. Đọc CHỈ [§ CẦN ĐỌC].
4. PHÂN LOẠI hành động kế tiếp theo §10.1:
   • ACT → nói 1 câu "đang làm X" rồi TỰ LÀM, báo cáo sau (đừng hỏi vụn vặt).
   • COUNCIL (schema/D1–D15/các ADR/bảo mật §2/ranh giới module/LẬT quyết định frozen) → DỪNG,
     propose, chờ tôi.
5. Định XÂY TIẾP lên mục ✅ → tự kiểm bằng chứng trước, đừng tin nhãn suông.
└──── HẾT RESUME ────
═══════ HẾT ═══════