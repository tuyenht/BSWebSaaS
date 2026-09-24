# SaaS Starter — thiết kế đủ & chuẩn hoá 100% trước khi build core

**Chốt:** owner 23–24/09/2026 (design-first; khu đăng nhập giữ nguyên; nối mọi màn vào Admin Shell thành prototype liền mạch).
**Đây là bản gốc** của bộ prompt — sửa trực tiếp ở `prompts/`. Trang copy có nút sao chép + đánh dấu tiến độ: https://claude.ai/artifact/ADEfS7nBGv15dM9txkQd2J
**Sửa một prompt ⇒ dựng lại trang copy ngay** (không thì owner chép bản cũ): `python3 scripts/build_prompt_copy_page.py /tmp/saas-starter-prompt-pack.html` rồi publish file đó lên ĐÚNG link trên (Artifact, truyền `url`). Phần BỐI CẢNH CHUNG phải giống hệt nhau ở mọi file — script dừng nếu lệch.
**Sổ đo độ phủ:** `docs/saas-starter-screen-map.md` · **Quyết định dữ liệu đi kèm:** `docs/design.md` changelog v2.23 (quyết định) · v2.25 (as-built sau audit).

## Cách dùng
- Mỗi file trong `prompts/` = **1 lượt chat mới** trong project Claude Design BSWebSaaS; copy nguyên văn, gửi. Không gộp 2 file vào 1 lượt (hết hạn mức giữa chừng).
- Mỗi file đã chứa sẵn phần **BỐI CẢNH CHUNG** (luật cứng + 12 tiêu chí dễ dùng + hợp đồng nhúng Admin Shell + mẫu README handoff).
- Xong mỗi lượt: export → Claude Code đối chiếu với code + 12 tiêu chí → lưu `docs/design_handoff_<slug>/` → cập nhật sổ màn → mới build.

## Sóng (25 lượt)
| Sóng | Lượt | Cần trước |
|---|---|---|
| D0 Nền | D0-1 Dọn & đồng bộ project · D0-2 Chuẩn giao diện: component · D0-3 Chuẩn giao diện: mẫu trang + Bảng thuật ngữ + tiêu chí dễ dùng · D0-4 Admin Shell v2: điều hướng + registry màn · D0-5 Nối màn đã có | bắt đầu ngay |
| D1 Nội dung | D1-1 Chuyên mục · D1-2 Trình đơn · D1-3 Danh sách nội dung · D1-4a Rà trình soạn chung · D1-4b Trình soạn một khuôn · D1-5 Sản phẩm · D1-6 Thư viện ảnh & tệp · D1-7 Khách liên hệ & Biểu mẫu | D0 |
| D2 Người dùng | D2-1 Nhóm quyền & Thành viên · D2-2 Lời mời · D2-3 Tài khoản của tôi · D2-4 Thông báo · D2-5 Tổng quan theo vai trò | D0 |
| D3 Cài đặt | D3-1 Chung & Nội dung · D3-2 Tiếp thị & Hệ thống | D0 |
| D4 Nền tảng | D4-1 Tổng quan + Khách hàng + Website · D4-2 Gói & tính năng + Loại website · D4-3 Vận hành viên + Nhật ký + Đăng nhập hỗ trợ + Cài đặt nền tảng | D0 |
| D5 Hoàn tất | D5-1 Trang lỗi & tiện ích · D5-2 QA liền mạch + thử với 4 người dùng mẫu | D1–D4 |

Ngoài phạm vi: khu đăng nhập (giữ nguyên) · bộ AI (module sau core v1) · mặt TMĐT · Khối tái dùng (ADR-20 Draft) · Builder v3 (track riêng).

## 12 tiêu chí dễ dùng (mọi màn phải đạt, ghi kết quả vào README handoff)
1. Hiểu trong 5 giây — tiêu đề + 1 dòng mô tả + đúng 1 nút chính.
2. Việc chính xong trong ≤ 3 bước, không cần đọc hướng dẫn.
3. Không thuật ngữ kỹ thuật trần; buộc phải có thì để trong "Nâng cao" kèm giải thích đời thường.
4. Icon luôn kèm chữ (trừ vài icon rất quen, có tooltip + aria-label).
5. Ô nhập có ví dụ thật; lỗi nói cách sửa.
6. Không mất việc — tự lưu nháp, chặn rời trang khi chưa lưu, xoá có "Hoàn tác".
7. Trạng thái = màu + hình + chữ.
8. Không cắt chữ — nút/chip/tab 1 dòng; không thanh cuộn ngang trong thẻ.
9. Việc hay làm làm trọn được trên điện thoại (390px), vùng bấm ≥ 44px, không gì nổi che nội dung.
10. Hai tầng người dùng: mặc định gọn cho người mới; người thạo có phím tắt, thao tác hàng loạt.
11. Chế độ "Chữ lớn" không vỡ bố cục.
12. Đúng Bảng thuật ngữ chung (lượt D0-3).

## Nghiệm thu toàn bộ
Mọi mục menu ở 2 mặt mở ra màn đã thiết kế (chỉ còn ô "Để sau" có lý do) · luồng tạo bài → duyệt → đăng, mời thành viên → gán nhóm, đổi ngôn ngữ, hết hạn mức gói, tạo website → đăng nhập hỗ trợ chạy trọn · 3 bề rộng × sáng/tối × chữ thường/lớn × 6 vai trò × 3 chế độ duyệt × 1/3/8 ngôn ngữ · 4 người dùng mẫu làm việc chính ≤ 3 bước.
