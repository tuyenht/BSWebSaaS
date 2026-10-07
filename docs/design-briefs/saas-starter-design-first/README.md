# SaaS Starter — thiết kế đủ & chuẩn hoá 100% trước khi build core

**Chốt:** owner 23–24/09/2026 (design-first; khu đăng nhập giữ nguyên; nối mọi màn vào Admin Shell thành prototype liền mạch).
**Đây là bản gốc** của bộ prompt — sửa trực tiếp ở `prompts/`. Trang copy có nút sao chép + đánh dấu tiến độ: https://claude.ai/artifact/ADEfS7nBGv15dM9txkQd2J
**Sửa một prompt ⇒ dựng lại trang copy ngay** (không thì owner chép bản cũ): `python3 scripts/build_prompt_copy_page.py /tmp/saas-starter-prompt-pack.html` rồi publish file đó lên ĐÚNG link trên (Artifact, truyền `url`). Phần BỐI CẢNH CHUNG phải giống hệt nhau ở mọi file — script dừng nếu lệch.
**Sổ đo độ phủ:** `docs/saas-starter-screen-map.md` · **Quyết định dữ liệu đi kèm:** `docs/design.md` changelog v2.23 (quyết định) · v2.25 (as-built sau audit).

## Cách dùng
- **Chạy tự động (từ 06/10):** phiên Claude Code `designbot` (lệnh `ccd`, lái Chrome có Claude Design) làm theo `designbot-runbook.md`: dán prompt, kiểm "Xong khi", tải zip vào `/home/designbot/zips/`. Mọi lượt chạy lần lượt, một mình. Owner giao việc và chuyển prompt sửa bằng một dòng `Đọc và làm theo <tệp>`. Các mục dưới vẫn đúng khi chạy tay.
- Mỗi file trong `prompts/` = **1 chat mới** trong project Claude Design BSWebSaaS: trên trang copy bấm "1 · Chép tên chat" (dòng mở đầu bằng mã lượt) rồi "2 · Sao chép prompt", dán cả hai vào cùng ô, gửi. Không gộp 2 file vào 1 chat. Lượt dừng giữa chừng: owner gửi "Làm <mã> phần 2" trong CÙNG chat (riêng D5-2: mỗi phần một chat mới — prompt ghi rõ).
- Mỗi file đã chứa sẵn phần **BỐI CẢNH CHUNG** (luật cứng + 12 tiêu chí dễ dùng + hợp đồng nhúng Admin Shell + mẫu README handoff). (prompt trong `prompts/fixes/` không có phần này, trừ prompt ghi "(chat mới)")
- Xong mỗi lượt: chat báo "<mã> xong — gửi zip cho Claude Code." → owner export zip → Claude Code nhập vào `docs/design-source/` (`scripts/design_snapshot.py`), đối chiếu repo + 12 tiêu chí, kiểm hiển thị bằng Chromium thật (cả phần môi trường thiết kế không đo được: Safari, Firefox, cảm ứng) → đạt thì chép handoff vào `docs/design_handoff_<slug>/` + cập nhật sổ màn; chưa đạt thì viết prompt sửa ở `prompts/fixes/`.
- Prompt sửa sau kiểm (`prompts/fixes/`; đã chạy: D0-2s → D0-2s5, D0-3s): dán vào chat ghi trong tiêu đề prompt. Prompt ghi "chat của lượt đó" thì chép KHÔNG kèm bối cảnh chung. Prompt ghi "(chat mới)" thì tệp đã có sẵn bối cảnh chung: gửi như lượt thường: dòng 1 = tên chat (dòng `# …` dưới `═══ VIỆC CỦA LƯỢT NÀY ═══`), rồi từ `═══ BỐI CẢNH CHUNG` đến hết. Chat cũ đã quá dài mà prompt chưa ghi "chat mới" thì báo Claude Code viết lại bản tự đủ, không dán vào chat cũ. Tiến độ từng lượt xem ở trang copy, không ghi ở đây.

## Sóng (31 lượt)
| Sóng | Lượt | Cần trước |
|---|---|---|
| D0 Nền | D0-1 Dọn & đồng bộ project · D0-2 Chuẩn giao diện: component · D0-3 Chuẩn giao diện: mẫu trang + Bảng thuật ngữ + tiêu chí dễ dùng · D0-4a Admin Shell v2: menu + topbar + Cài đặt · D0-4b Admin Shell v2: Tweaks + registry màn + điều hướng · D0-5 Nối màn đã có · D0-6a/6b/6c Hiển thị đa thiết bị: rà & sửa mọi màn đã có (6c = bộ AI, không chặn D1–D4) · D0-7 Cỡ chữ theo người (sau D3-1) · D0-8 Đầu màn + Hướng dẫn theo quyền + khuôn danh sách mới (sau D0-7 và 7 prompt sửa, trước D3-2; bản mẫu tham chiếu `reference/d0-8/`) | bắt đầu ngay; chạy đúng thứ tự D0-1 → D0-2 → D0-3 → D0-4a → D0-4b → D0-5 → D0-6a → D0-6b (lượt sau dùng kết quả lượt trước); D0-6c chạy lúc nào cũng được sau D0-6a, không chặn D1–D4 |
| D1 Nội dung | D1-1 Chuyên mục · D1-2 Trình đơn · D1-3 Danh sách nội dung · D1-4a Rà trình soạn chung · D1-4b Trình soạn một khuôn · D1-4c SEO còn lại + Lịch sử phiên bản · D1-4d Rời trang + nháp đè bản công khai · D1-5 Sản phẩm · D1-6 Thư viện ảnh & tệp · D1-7 Khách liên hệ & Biểu mẫu | D0-6b; chạy đúng thứ tự D1-1 → D1-7 (D1-2 cần D1-1, D1-3 cần D1-2, D1-4a cần D1-3, D1-5 cần D1-4b, D1-7 cần D1-3) |
| D2 Người dùng | D2-1 Nhóm quyền & Thành viên · D2-2 Lời mời · D2-3 Tài khoản của tôi · D2-4 Thông báo · D2-5 Tổng quan theo vai trò | D1 (D2-1 cần D1-3, D2-4 cần D1-4d); chạy D2-1 → D2-5 |
| D3 Cài đặt | D3-1 Chung & Nội dung · D3-2 Tiếp thị & Hệ thống | D2-5; D3-1 → D3-2 |
| D4 Nền tảng | D4-1 Tổng quan + Khách hàng + Website · D4-2 Gói & tính năng + Loại website · D4-3 Vận hành viên + Nhật ký + Đăng nhập hỗ trợ + Cài đặt nền tảng | D3-2; D4-1 → D4-3 |
| D5 Hoàn tất | D5-1 Trang lỗi & tiện ích · D5-2 QA liền mạch + thử với 4 người dùng mẫu + chốt Bản đồ màn hình (3 phần, mỗi phần một chat) | D4-3 (D5-1 cần D3-2, D4-1, D4-3); D5-2 sau D5-1 |

Ngoài phạm vi: khu đăng nhập (giữ nguyên — trang lỗi D5-1 chỉ dẫn về đó) · bộ AI (module sau core v1; D0-6c chỉ sửa hiển thị) · mặt TMĐT · Khối tái dùng (ADR-20 Draft) · Builder v3 (track riêng).

## 12 tiêu chí dễ dùng (mọi màn phải đạt, ghi kết quả vào README handoff)
1. Hiểu trong 5 giây — tiêu đề + 1 dòng mô tả; tối đa 1 nút chính và chỉ khi prompt ghi tên nút đó (Tổng quan không có).
2. Việc chính xong trong ≤ 3 bước, không cần đọc hướng dẫn.
3. Không thuật ngữ kỹ thuật trần; buộc phải có thì để trong "Nâng cao" kèm giải thích đời thường.
4. Icon luôn kèm chữ (trừ vài icon rất quen, có tooltip + aria-label).
5. Ô nhập có ví dụ thật; lỗi nói cách sửa.
6. Không mất việc — tự lưu nháp, chặn rời trang khi chưa lưu, xoá có "Hoàn tác".
7. Trạng thái = màu + hình + chữ.
8. Không cắt chữ — nút/chip/tab 1 dòng; không thanh cuộn ngang trong thẻ.
9. Mọi việc trên màn làm trọn được ở điện thoại (360–390px) và máy tính bảng (768px); vùng bấm ≥ 44px, không gì nổi che nội dung. Bảng thiết bị: 360·390·768·1024·1240·1440 + xoay ngang, Chrome/Safari iOS/Firefox/Samsung Internet (bối cảnh chung, owner 30/09).
10. Hai tầng người dùng: mặc định gọn cho người mới; người thạo có phím tắt, thao tác hàng loạt.
11. Cỡ chữ theo người (Vừa · Lớn · Rất lớn · Cực lớn) không vỡ bố cục.
12. Đúng Bảng thuật ngữ chung (lượt D0-3).

## Chất lượng hoàn thiện A–E (owner 06/10 — luật chung mọi dự án thiết kế, đi cùng 12 tiêu chí)
Nguồn đầy đủ: luật toàn cục của Claude Code `~/.claude/rules/design-quality-bar.md`; bản rút gọn nằm trong bối cảnh chung của cả 31 prompt.
- A. Đẹp & nhất quán — thang 4/8px, 1 nút chính mỗi vùng, cùng việc cùng component, chuyển động nhẹ (tắt khi giảm chuyển động), không giật khi tải.
- B. Người lớn tuổi & người mới — chữ ≥ 14px (chú thích ≥ 12px), nhãn luôn hiện, (?) giải thích, không việc nào chỉ làm được bằng kéo-thả / nhấn giữ / rê chuột, phản hồi trong 1 giây, báo trước khi hết phiên, mất mạng không mất chữ.
- C. Bàn phím & trình đọc màn hình — Tab, Esc, trả focus, 1 h1, nhãn thật, `aria-live`.
- D. Chế độ tối & trình duyệt — chỉ token (không nền viết thẳng), ô nhập giống nhau giữa trình duyệt, chữ có dấu đúng ở font dự phòng, nhãn dài 40% không vỡ.
- E. Đo thật, không tự khai — tương phản sáng + tối, vùng bấm 44px, bàn phím, console 0 lỗi sau khi sửa JS; tệp tạm chỉ trong `_audit/tmp/`; không đo được thì [CHƯA KIỂM ĐƯỢC]. Claude Code kiểm lại mọi zip theo A–E.

## Nghiệm thu toàn bộ
Mọi mục menu ở 2 mặt mở ra màn đã thiết kế (chỉ còn ô "Để sau" có lý do) · 10 luồng của D5-2 chạy trọn trong Shell · lưới mẫu (không chạy hết tích số): 360–1440 × sáng/tối × chữ thường/lớn, rồi từng trục — 6 nhóm quyền website + 3 nhóm quyền nền tảng · 3 chế độ duyệt · 1/3/8 ngôn ngữ · gói có/không tính năng · 4 người dùng mẫu (`#nguoi-dung-mau`) làm 12 việc chính ≤ 3 bước · Safari, Firefox, Samsung Internet và cảm ứng do Claude Code kiểm bằng zip.
