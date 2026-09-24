═══ BỐI CẢNH CHUNG (giữ nguyên ở đầu mọi lượt) ═══
Dự án BSWebSaaS trong Claude Design. Trước khi làm: đọc CLAUDE.md, LESSONS.md, github.md, TODO.md; mở Admin Shell.dc.html và design system `_ds/bssaas-adminkit-design-system`; dùng lại nguồn chung ai-quota.js (BSAI) · content-workflow.js (BSCW) · content-ui.js (BSUI) · content-seo.js (BSSEO), và — khi đã có — `Chuẩn giao diện SaaS Starter.dc.html` (lượt D0-2, D0-3) + registry màn của Admin Shell (lượt D0-4) + `SaaS Starter - Bản đồ màn hình.md` (lượt D0-1).
Sản phẩm: SaaS Starter — bộ khung quản trị đa-tenant BÁN ĐƯỢC cho nhiều loại website/SaaS: white-label, đa ngôn ngữ (tiếng Việt trước), phân quyền động, quy trình duyệt động (mặc định TẮT). Người dùng: từ chủ doanh nghiệp nhỏ lớn tuổi, chỉ biết đọc và dùng chuột/điện thoại, tới nhân viên văn phòng và người quản trị thạo việc.
Nguồn sự thật dữ liệu: GitHub tuyenht/BSWebSaaS@main — mở đúng file repo nêu trong prompt để lấy bảng/cột/quyền/trạng thái thật; KHÔNG bịa. Chỗ nào repo chưa có thì vẫn vẽ, gắn nhãn "Chờ backend" và ghi vào mục "Câu hỏi cho backend" của README.
Luật cứng:
- Token AdminKit: navy #405189 · Inter 14px · bo 4px ô nhập / 6px thẻ · 1 bóng thẻ · nền #f3f3f9. Không thêm màu, gradient, bóng mới.
- Đích build: Livewire 4 + Blade + Tailwind 4 + <x-admin::*>; icon Remix inline-SVG (prototype dùng CDN được, README liệt kê tên icon). Không Bootstrap, không Flux.
- Mỗi màn đủ trạng thái: trống · đang tải · lỗi (có "Thử lại") · không có quyền · khoá theo gói · thành công. Không ngõ cụt.
- 3 bề rộng 1240 / 768 / 390, sáng + tối, WCAG 2.2 AA.
- Không hiện mã số nội bộ (id) — chỉ tên, đường dẫn, mã công khai. Không gắn cứng thương hiệu (tên nền tảng/website/"Phát triển bởi" lấy từ cấu hình).
- Dữ liệu động: vai trò, quyền, loại nội dung, ngôn ngữ, gói, bước duyệt đều đọc từ registry/bảng — chịu được 1–20 mục, tên dài.
- Hành động nguy hiểm: hộp xác nhận giữa màn (mẫu SweetAlert2), nút Huỷ được focus.
TIÊU CHÍ DỄ DÙNG — kiểm từng màn trước khi giao, ghi kết quả (đạt/chưa + lý do) vào README:
 1. Hiểu trong 5 giây: tiêu đề + 1 dòng mô tả nói màn này để làm gì; đúng 1 nút chính nổi bật; người mới biết bấm gì tiếp.
 2. Việc chính của màn xong trong ≤ 3 bước từ menu, không cần đọc hướng dẫn.
 3. Không thuật ngữ kỹ thuật trần (SMTP, OAuth, DNS, CNAME, SSL, API, webhook, token, slug, meta, robots…). Buộc phải có thì để trong khối "Nâng cao" và kèm 1 câu giải thích đời thường. Tên thương hiệu quen (Google, Zalo, Facebook) giữ nguyên nhưng nói rõ công dụng ("Facebook Pixel — đo hiệu quả quảng cáo Facebook").
 4. Icon luôn kèm chữ; chỉ icon rất quen (tìm kiếm, chuông, đóng, thêm) mới được đứng một mình, và phải có tooltip + aria-label.
 5. Ô nhập có gợi ý bằng ví dụ thật; lỗi nói cách sửa ("Số điện thoại cần 10 số, ví dụ 0901 234 567"), không nói lỗi kỹ thuật.
 6. Không mất việc: tự lưu nháp, chặn rời trang khi chưa lưu, xoá = vào Thùng rác kèm toast "Hoàn tác".
 7. Trạng thái luôn có màu + hình + chữ — không bao giờ chỉ dùng màu.
 8. Không cắt chữ: nhãn nút, chip, tab, nút lọc luôn 1 dòng (dài thì "…" + tooltip) — nút KHÔNG bao giờ xuống 2 dòng; nhãn ô nhập được xuống dòng nhưng không bị che. Không thanh cuộn ngang trong thẻ/khung đọc.
 9. Điện thoại: việc hay làm (trả lời khách liên hệ, duyệt bài, đăng tin nhanh, xem thông báo) làm trọn được ở 390px; vùng bấm ≥ 44px; không thành phần nổi nào che nút Lưu hay nội dung.
10. Hai tầng người dùng: mặc định gọn cho người mới (tuỳ chọn nâng cao gập lại); người thạo có phím tắt, thao tác hàng loạt, lọc nhanh — nhưng không làm rối tầng người mới.
11. Chế độ "Chữ lớn" (+2px toàn trang) không làm vỡ bố cục.
12. Dùng đúng từ trong "Bảng thuật ngữ" của Chuẩn giao diện (D0-3): cùng một việc luôn cùng một từ ở mọi màn.
Nhúng & điều hướng: màn tuân hợp đồng nhúng §13 (embedded · onDirty · embedId · bs-embed-cmd), đăng ký vào registry màn của Admin Shell (sau D0-4) với trạng thái "ready", chuyển màn bằng sự kiện bs-goto {screen, params}; cập nhật dòng tương ứng trong `SaaS Starter - Bản đồ màn hình.md`.
Giao nộp: 1 file .dc.html mỗi màn (hoặc gộp có anchor) + README handoff theo mẫu: Tổng quan · File · Mức hoàn thiện · Màn & trạng thái · Tương tác · Ánh xạ dữ liệu (file:dòng repo) · Quyền & gói (ai thấy gì) · Token · Icon · Chuỗi cần dịch · Kết quả 12 tiêu chí dễ dùng · Câu hỏi cho backend.
Không sửa CLAUDE.md (chỉ owner sửa) — bài học mới ghi 1 dòng vào LESSONS.md. Làm xong gọi ready_for_verification và sửa hết lỗi verifier báo. Lượt này làm ĐÚNG MỘT việc bên dưới.
═══ VIỆC CỦA LƯỢT NÀY ═══

# D2-2 · Lời mời thành viên & vòng đời thành viên

Hiện trạng (backend xong 24/09 — `invitations` + `invitation_roles`, `app/Modules/Rbac/Actions/{InviteMember,AcceptInvitation,RevokeInvitation}.php`): màn Thành viên đã đổi "Thêm thành viên" thành "Mời thành viên" (1 email + nhóm) và LUÔN trả cùng một câu "Đã gửi lời mời tới …"; lời mời hết hạn sau 7 ngày; mỗi website tối đa 50 thư mời / 24 giờ (thư gửi lại cũng tính); mỗi địa chỉ nhận tối đa 3 thư mời / 24 giờ từ cùng website (chống spam hộp thư — mọi website gửi chung một người gửi); cùng một lời mời không gửi lại trong 60 giây; mời lại một địa chỉ đang chờ = làm mới lời mời (liên kết cũ hết hiệu lực); chỉ được mời vào nhóm thấp hơn cấp mình. Người nhận: đã có tài khoản → đăng nhập bình thường (mật khẩu + lớp 2) rồi bấm "Tham gia"; chưa có → tạo tài khoản ngay trên trang nhận lời mời. Màn nhận lời mời đã dựng theo khung đăng nhập (`app/Modules/Admin/Livewire/AcceptInvitation.php`). Lỗi có sẵn để hiển thị: đã là thành viên · chạm giới hạn thư/ngày của website · địa chỉ này đã nhận đủ thư trong 24 giờ (gợi ý người nhận xem mục Thư rác) · gửi lại quá sớm · liên kết hỏng/đã dùng · hết hạn · sai tài khoản · người mời không còn quyền cấp nhóm đó. Việc của lượt này là GIAO DIỆN cho các hành động đó.
Vẽ — bổ sung vào màn Thành viên của D2-1, KHÔNG làm màn trùng:
1. Mời bằng email: 1 hoặc nhiều email, chọn nhóm (chỉ nhóm thấp hơn cấp mình, mỗi nhóm có 1 dòng "làm được gì"), lời nhắn tuỳ chọn, hạn lời mời (mặc định 7 ngày). Người đã có tài khoản → nhận thông báo tham gia; người mới → email tạo mật khẩu. Một nút duy nhất "Mời vào website" — không bắt người dùng phân biệt 2 trường hợp; sau khi gửi LUÔN báo cùng một câu "Đã gửi lời mời tới …" dù email đã có tài khoản hay chưa (không để lộ email nào đã có tài khoản trên nền tảng).
2. Tab "Lời mời": đang chờ · hết hạn · đã huỷ; gửi lại, huỷ, sao chép liên kết (cảnh báo không chia sẻ công khai).
3. Email lời mời (dùng mẫu của Email Settings) + màn nhận lời mời ở khu đăng nhập: CHỈ mô tả nội dung/trạng thái trong README — khu đăng nhập giữ nguyên giao diện hiện có, không vẽ lại.
4. Trạng thái thành viên: đang hoạt động · chờ nhận lời mời; gỡ khỏi website (xác nhận nguy hiểm). "Khoá tài khoản" để sau.
Gắn vào Shell: tab trong `users`.
