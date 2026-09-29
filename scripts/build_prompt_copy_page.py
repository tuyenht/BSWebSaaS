#!/usr/bin/env python3
"""Build the copy page for the SaaS Starter Claude Design prompt pack (one HTML file).

The prompts' single source is docs/design-briefs/saas-starter-design-first/prompts/ — whoever edits a
prompt rebuilds this page and republishes it to the SAME artifact URL named in that folder's
README, so the owner never copies a stale prompt. The shared preamble is shown once and must be
byte-identical in every prompt file (asserted). Pure stdlib; run:

    python3 scripts/build_prompt_copy_page.py [OUT_HTML]   # default /tmp/saas-starter-prompt-pack.html
"""
import html
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / 'docs' / 'design-briefs' / 'saas-starter-design-first' / 'prompts'
OUT = pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else pathlib.Path('/tmp/saas-starter-prompt-pack.html')
MARK = '═══ VIỆC CỦA LƯỢT NÀY ═══\n'

files = sorted(SRC.glob('D*.md'))
preamble = None
prompts = []
for f in files:
    text = f.read_text(encoding='utf-8')
    head, body = text.split(MARK, 1)
    head = head + MARK
    if preamble is None:
        preamble = head
    assert head == preamble, f'preamble drift in {f.name}'
    title = body.strip().splitlines()[0].lstrip('# ').strip()
    pid, name = title.split(' · ', 1)
    prompts.append({'id': pid, 'name': name, 'file': f.name, 'body': body.strip()})

WAVES = [
    ('D0', 'Nền', 'Làm trước mọi thứ: dọn project, chuẩn giao diện (component + mẫu trang + thuật ngữ + tiêu chí dễ dùng), Shell v2 + registry màn, nối màn đã có.', 'Bắt đầu được ngay'),
    ('D1', 'Nội dung', 'Chuyên mục, trình đơn, danh sách + trình soạn nội dung, sản phẩm, thư viện, liên hệ & biểu mẫu.', 'Cần D0 · backend trình đơn + sản phẩm đã sẵn'),
    ('D2', 'Người dùng', 'Nhóm quyền, lời mời, tài khoản của tôi, thông báo, tổng quan theo vai trò.', 'Cần D0 · backend lời mời + thông báo đã sẵn'),
    ('D3', 'Cài đặt website', 'Hoàn thiện khung Cài đặt: chung, nội dung, tiếp thị, hệ thống, nâng cao.', 'Cần D0 · backend ngôn ngữ + thông tin DN đã sẵn'),
    ('D4', 'Nền tảng', 'Tổng quan, khách hàng, website, gói & tính năng, vận hành viên, nhật ký, đăng nhập hỗ trợ.', 'Cần D0 · Q8'),
    ('D5', 'Hoàn tất', 'Trang lỗi & tiện ích, rồi QA liền mạch toàn prototype.', 'Cần D1–D4'),
]

DECISIONS = [
    ('Q1', 'Thông tin doanh nghiệp (NAP) lưu ở đâu?', '✅ Đã làm, lên prod 24/09: đủ cột theo design.md §14 + danh mục Tỉnh/Phường; website đọc theo ngôn ngữ trang. Tên công ty: web dùng bản viết tắt theo ngôn ngữ, bản đầy đủ theo giấy phép là tên pháp lý.'),
    ('Q2', 'Tách "ngôn ngữ đang soạn" khỏi "ngôn ngữ hiển thị cho khách"?', '✅ Đã làm, lên prod 24/09: cờ is_public + hạn mức locales.max (mặc định 2 → 1 từ 26/09: website bắt đầu với 1 ngôn ngữ, cấp thêm theo gói/website). Ngôn ngữ chưa hiển thị không lộ ra sitemap, hreflang hay địa chỉ /en.'),
    ('Q3', 'Trình đơn được trỏ tới Sản phẩm?', '✅ Đã làm, lên prod 24/09: có loại liên kết Sản phẩm; website tự ẩn mục trỏ tới trang/sản phẩm chưa đăng hoặc trong thùng rác.'),
    ('Q4', 'Lời mời thành viên?', '✅ Đã làm, lên prod 24/09: bảng lời mời, luôn báo cùng một câu, hết hạn 7 ngày; mỗi website tối đa 50 thư/ngày (tính cả thư gửi lại) và 3 thư/địa chỉ/ngày. Màn nhận lời mời dựng theo khung đăng nhập.'),
    ('Q5', 'Thông báo trong ứng dụng?', '✅ Đã làm, lên prod 24/09: module Notifications, đã nối duyệt bài · khách liên hệ mới · tắt 2 lớp; giữ 90 ngày. Chuông + trang chờ thiết kế D2-4.'),
    ('Q6', 'Ngôn ngữ giao diện theo từng người?', 'Đổi so với bản trước: lưu trong users.preferences cùng cỡ chữ, múi giờ — không cần thêm cột.'),
    ('Q7', '"Tài khoản của tôi" nằm đâu?', 'Trang riêng từ menu người dùng, cho cả vận hành viên nền tảng; Cài đặt chỉ còn việc toàn website.'),
    ('Q8', 'Mặt Nền tảng dùng khung nào?', 'Một khung Shell dùng chung. Không có nút chuyển mặt trong cùng phiên — vào website khách chỉ qua Đăng nhập hỗ trợ.'),
    ('Q9', 'Màn mới trong khu đăng nhập (chọn website, nhận lời mời…)?', 'Dựng thẳng theo khung login hiện có, gửi ảnh chụp để duyệt trong PR.'),
    ('Q10', 'Hiện dòng báo thành công ở khu đăng nhập?', 'Có: thông báo đang bị nuốt; dùng đúng kiểu hộp báo đang có.'),
    ('Q11', 'Áp palette An Vinh v2 (vàng đồng)?', 'Chưa: vàng trên nền trắng chỉ 2,28:1 (trượt AA). Bộ sinh bảng màu đã có (26/09) — chờ khách An Vinh duyệt. Không chặn việc thiết kế.'),
    ('Q12', 'Bộ AI có vào core v1?', 'Không: làm module riêng sau core v1 (kéo theo thanh toán). Giữ nguyên 10 màn đã vẽ, ẩn nút AI khi website không được cấp.'),
    ('Q13', 'Tài liệu thiết kế để đâu?', 'Đưa brief + prompt + roadmap + sổ màn vào docs/ (có trong git) để Claude Design đọc được; plans/ vẫn là nháp cục bộ.'),
    ('Q14', 'Luật kiến trúc module đang vô hiệu — xử lý?', 'Sửa test thành 1 luật mỗi module + ghi nợ 8 chỗ vi phạm hiện có, chặn ngay vi phạm mới; trả nợ dần. ✅ Hết nợ 27/09.'),
]

# (module, screen, design, code) — design/code status keys: ok · part · off · bad · none · later · keep
MATRIX = [
    ('Khung', 'Admin Shell mặt site', 'off', 'ok'),
    ('Khung', 'Mặt Nền tảng trong cùng Shell', 'part', 'off'),
    ('Khung', 'Tìm kiếm toàn cục ⌘K', 'part', 'bad'),
    ('Khung', 'Tổng quan theo vai trò', 'part', 'bad'),
    ('Khung', 'Tài khoản của tôi', 'part', 'part'),
    ('Khung', 'Thông báo', 'part', 'part'),
    ('Nội dung', 'Danh sách Trang', 'off', 'ok'),
    ('Nội dung', 'Danh sách Bài viết / Dịch vụ / Dự án', 'ok', 'ok'),
    ('Nội dung', 'Trình soạn Trang / Bài viết', 'ok', 'bad'),
    ('Nội dung', 'Lịch sử phiên bản', 'part', 'none'),
    ('Nội dung', 'Chuyên mục', 'none', 'none'),
    ('Nội dung', 'Trình đơn', 'off', 'part'),
    ('Nội dung', 'Hộp duyệt · Trạng thái duyệt · Quy trình duyệt', 'ok', 'ok'),
    ('Sản phẩm', 'Danh sách + trình sửa', 'none', 'part'),
    ('Media', 'Thư viện (trang)', 'part', 'ok'),
    ('Media', 'Trình chọn ảnh', 'ok', 'ok'),
    ('Liên hệ', 'Hộp thư + chi tiết', 'none', 'part'),
    ('Liên hệ', 'Trình dựng biểu mẫu', 'none', 'none'),
    ('Người dùng', 'Thành viên · Nhóm quyền (prompt có sẵn)', 'none', 'part'),
    ('Người dùng', 'Lời mời', 'none', 'part'),
    ('Cài đặt', 'Thông tin chung', 'off', 'part'),
    ('Cài đặt', 'Giao diện website', 'off', 'none'),
    ('Cài đặt', 'Ngôn ngữ website', 'off', 'part'),
    ('Cài đặt', 'Đường dẫn · Chuyển hướng', 'none', 'none'),
    ('Cài đặt', 'SEO · Email · Đăng nhập nhanh · Tích hợp · Nút nổi', 'ok', 'part'),
    ('Cài đặt', 'Tên miền', 'off', 'none'),
    ('Cài đặt', 'Gói & mức dùng', 'none', 'none'),
    ('Nền tảng', 'Khách hàng · Website · Nhật ký', 'none', 'part'),
    ('Nền tảng', 'Gói & tính năng', 'off', 'none'),
    ('Nền tảng', 'Nhân sự vận hành · Đăng nhập hỗ trợ', 'none', 'none'),
    ('Nền tảng', 'Cài đặt nền tảng', 'ok', 'part'),
    ('Tiện ích', 'Trang lỗi · Kết quả tìm kiếm', 'none', 'bad'),
    ('Đăng nhập', 'Login · quên/đặt lại · 2FA · OTP', 'keep', 'ok'),
    ('Để sau', 'Bộ AI · mặt TMĐT · Khối tái dùng', 'later', 'none'),
]
LABEL = {'ok': 'Đủ', 'part': 'Một phần', 'off': 'Lệch', 'bad': 'Sơ sài', 'none': 'Chưa có', 'later': 'Để sau', 'keep': 'Giữ nguyên'}

e = html.escape


def pill(k):
    return f'<span class="pill pill-{k}">{LABEL[k]}</span>'


rows = []
last = None
for mod, screen, d, c in MATRIX:
    m = e(mod) if mod != last else ''
    last = mod
    rows.append(f'<tr><td class="mod">{m}</td><td>{e(screen)}</td><td>{pill(d)}</td><td>{pill(c)}</td></tr>')

# Open calls the 29/09 audit left for the owner: the prompts draw the safest default and tag it "Cần owner chốt".
OPEN = [
    ('C1', 'Khoá màn hình', 'Prompt giữ như đã vẽ (menu người dùng) và ghi Việc cho backend. Bỏ thì owner nói — vì lý do dễ dùng, không phải vì thiếu backend.'),
    ('C2', 'Dữ liệu & Phục hồi', 'Màn đã vẽ (khôi phục về thời điểm, xuất, nhập). Prompt giữ + Việc cho backend; sổ màn đang ghi ⏸ — owner chọn giữ hay hoãn.'),
    ('C3', 'Ai được "Đăng nhập hỗ trợ"', 'Mặc định vẽ: quyền riêng cho super_admin + support, không cho billing; bắt lý do, có thời hạn, ghi nhật ký. Quyết định bảo mật — build chỉ sau khi owner duyệt.'),
    ('C4', 'Màu trang quản trị theo từng người', 'Mặc định vẽ: một màu cho cả nền tảng (Thương hiệu trắng, tự đạt AA); tuỳ biến cá nhân không có ô màu.'),
    ('C5', 'Bản nháp đè bản công khai', 'D1-4d vẽ cả hai phương án A/B kèm so sánh — owner chọn một rồi mới build.'),
    ('C6', 'Dữ liệu cá nhân của khách liên hệ (pháp lý)', 'D1-7 vẽ mặc định: chỉ người quản lý biểu mẫu được xuất CSV, có xác nhận + ghi nhật ký; thời hạn tự xoá để trống (giữ); xoá theo yêu cầu khách = Thùng rác 30 ngày. Owner chốt theo tư vấn pháp lý.'),
    ('C7', 'Việc owner làm trong project Claude Design', 'Sửa CLAUDE.md của project: §8 thêm token AA (muted #6b6e7f, bậc -text/-solid, vòng focus 2px) và bộ bề rộng 1240/768/390; §13 thêm cách nhúng màn toàn trang. Claude Design không được tự sửa file này.'),
]

open_rows = ''.join(
    f'<li class="dec"><span class="qid">{q}</span><div><p class="dq">{e(t)}</p><p class="da">{e(a)}</p></div></li>'
    for q, t, a in OPEN)

dec_rows = ''.join(
    f'<li class="dec"><span class="qid">{q}</span><div><p class="dq">{e(t)}</p><p class="da">{e(a)}</p></div></li>'
    for q, t, a in DECISIONS)

wave_html = []
for wid, wname, wdesc, wneed in WAVES:
    cards = []
    for p in [p for p in prompts if p['id'].startswith(wid)]:
        body_id = 'b-' + p['id']
        cards.append(f'''
<article class="card" data-id="{p['id']}">
  <div class="card-head">
    <label class="done"><input type="checkbox" id="done-{p['id']}" data-done="{p['id']}"><span>Xong</span></label>
    <span class="pid">{p['id']}</span><span class="next-tag" hidden>Lượt tiếp theo</span>
    <h3>{e(p['name'])}</h3>
    <button type="button" class="btn-copy" data-copy="{body_id}">Sao chép prompt</button>
  </div>
  <details>
    <summary>Xem nội dung <span class="file">{e(p['file'])}</span></summary>
    <pre id="{body_id}">{e(p['body'])}</pre>
  </details>
</article>''')
    wave_html.append(f'''
<section class="wave" id="wave-{wid}">
  <header class="wave-head">
    <span class="wid">{wid}</span>
    <div><h2>{e(wname)}</h2><p>{e(wdesc)}</p></div>
    <span class="need">{e(wneed)}</span>
  </header>
  {''.join(cards)}
</section>''')

page = f'''<title>Gói prompt SaaS Starter</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap">
<style>
:root {{
  --canvas:#f3f3f9; --surface:#ffffff; --surface-2:#f8f9fb; --border:#e9ebec; --line:#eff2f7;
  --heading:#343a40; --text:#495057; --muted:#878a99; --brand:#405189; --brand-soft:rgba(64,81,137,.10);
  --ok:#0a6d60; --ok-bg:rgba(10,179,156,.14); --warn:#8a5f18; --warn-bg:rgba(247,184,75,.20);
  --bad:#c0432c; --bad-bg:rgba(240,101,72,.14); --info:#22688f; --info-bg:rgba(41,156,219,.14);
  --neutral:#5c5f6e; --neutral-bg:#eef0f4; --shadow:0 1px 2px rgba(56,65,74,.15); --code:#f4f6fa;
}}
@media (prefers-color-scheme: dark) {{
  :root:not([data-theme="light"]) {{
    color-scheme:dark; --canvas:#1a1d21; --surface:#292e33; --surface-2:#24282d; --border:#3a4149; --line:#32383e;
    --heading:#e9ecef; --text:#ced4da; --muted:#98a0ae; --brand:#96a1c0; --brand-soft:rgba(150,161,192,.16);
    --ok:#5fd1bf; --ok-bg:rgba(10,179,156,.18); --warn:#f3c77a; --warn-bg:rgba(247,184,75,.16);
    --bad:#f4927c; --bad-bg:rgba(240,101,72,.18); --info:#7cc3ea; --info-bg:rgba(41,156,219,.18);
    --neutral:#b5bcc8; --neutral-bg:#32383e; --shadow:0 1px 2px rgba(0,0,0,.4); --code:#202428;
  }}
}}
:root[data-theme="dark"] {{
  color-scheme:dark; --canvas:#1a1d21; --surface:#292e33; --surface-2:#24282d; --border:#3a4149; --line:#32383e;
  --heading:#e9ecef; --text:#ced4da; --muted:#98a0ae; --brand:#96a1c0; --brand-soft:rgba(150,161,192,.16);
  --ok:#5fd1bf; --ok-bg:rgba(10,179,156,.18); --warn:#f3c77a; --warn-bg:rgba(247,184,75,.16);
  --bad:#f4927c; --bad-bg:rgba(240,101,72,.18); --info:#7cc3ea; --info-bg:rgba(41,156,219,.18);
  --neutral:#b5bcc8; --neutral-bg:#32383e; --shadow:0 1px 2px rgba(0,0,0,.4); --code:#202428;
}}
* {{ box-sizing:border-box; }}
body {{ margin:0; background:var(--canvas); color:var(--text); font:14px/1.55 Inter,"Segoe UI",Roboto,"Noto Sans",Arial,sans-serif; }}
.wrap {{ max-width:1040px; margin:0 auto; padding-inline:20px; padding-block:28px 56px; display:flex; flex-direction:column; gap:28px; }}
h1,h2,h3 {{ color:var(--heading); text-wrap:balance; margin:0; }}
h1 {{ font-size:24px; font-weight:700; letter-spacing:-.01em; }}
h2 {{ font-size:17px; font-weight:600; }}
h3 {{ font-size:14px; font-weight:600; }}
p {{ margin:0; }}
.top {{ display:flex; flex-direction:column; gap:10px; }}
.eyebrow {{ font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--brand); }}
.lede {{ max-width:68ch; color:var(--text); }}
.meta {{ font-size:12px; color:var(--muted); font-variant-numeric:tabular-nums; }}
.facts {{ display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:1px; background:var(--border); border:1px solid var(--border); border-radius:6px; overflow:hidden; }}
.fact {{ background:var(--surface); padding:12px 14px; display:flex; flex-direction:column; gap:2px; }}
.fact b {{ font-size:20px; color:var(--heading); font-variant-numeric:tabular-nums; }}
.fact span {{ font-size:12px; color:var(--muted); }}
.panel {{ background:var(--surface); border:1px solid var(--border); border-radius:6px; box-shadow:var(--shadow); padding:18px; display:flex; flex-direction:column; gap:14px; }}
.panel-head {{ display:flex; flex-wrap:wrap; align-items:baseline; justify-content:space-between; gap:8px; }}
.hint {{ font-size:12.5px; color:var(--muted); }}
.decs {{ list-style:none; margin:0; padding:0; display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px 18px; }}
.dec {{ display:flex; gap:10px; align-items:flex-start; }}
.qid {{ flex:0 0 auto; font:600 12px/1 "JetBrains Mono",ui-monospace,monospace; color:var(--brand); background:var(--brand-soft); padding:5px 7px; border-radius:4px; }}
.dq {{ font-weight:600; color:var(--heading); }}
.da {{ color:var(--text); font-size:13px; }}
.table-wrap {{ overflow-x:auto; }}
table {{ width:100%; border-collapse:collapse; font-size:13px; }}
th {{ text-align:left; font-size:11px; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); font-weight:700; padding:8px 10px; border-bottom:1px solid var(--border); }}
td {{ padding:7px 10px; border-bottom:1px solid var(--line); vertical-align:top; }}
td.mod {{ color:var(--muted); font-weight:600; width:110px; }}
.pill {{ display:inline-block; font-size:11.5px; font-weight:600; padding:2px 8px; border-radius:20px; white-space:nowrap; }}
.pill-ok {{ color:var(--ok); background:var(--ok-bg); }}
.pill-part {{ color:var(--info); background:var(--info-bg); }}
.pill-off {{ color:var(--warn); background:var(--warn-bg); }}
.pill-bad, .pill-none {{ color:var(--bad); background:var(--bad-bg); }}
.pill-later, .pill-keep {{ color:var(--neutral); background:var(--neutral-bg); }}
.progress {{ display:flex; align-items:center; gap:12px; font-size:13px; }}
.bar {{ flex:1; height:6px; background:var(--line); border-radius:6px; overflow:hidden; }}
.bar i {{ display:block; height:100%; width:0; background:var(--brand); transition:width .2s; }}
.wave {{ display:flex; flex-direction:column; gap:8px; }}
.wave-head {{ display:grid; grid-template-columns:auto 1fr auto; align-items:center; gap:12px; padding:4px 2px 6px; }}
.wid {{ font:700 13px/1 "JetBrains Mono",ui-monospace,monospace; color:#fff; background:var(--brand); padding:7px 9px; border-radius:4px; }}
:root[data-theme="dark"] .wid {{ color:#1a1d21; }}
@media (prefers-color-scheme: dark) {{ :root:not([data-theme="light"]) .wid {{ color:#1a1d21; }} }}
.wave-head p {{ font-size:13px; color:var(--muted); }}
.need {{ font-size:12px; color:var(--muted); border:1px solid var(--border); border-radius:20px; padding:3px 10px; white-space:nowrap; }}
.card {{ background:var(--surface); border:1px solid var(--border); border-radius:6px; }}
.card.is-done {{ opacity:.62; }}
.card-head {{ display:grid; grid-template-columns:auto auto auto 1fr auto; align-items:center; gap:10px; padding:10px 12px; }}
.card.is-next {{ border-color:var(--brand); box-shadow:0 0 0 2px var(--brand-soft); }}
.next-tag {{ font-size:11px; font-weight:700; color:#fff; background:#405189; padding:2px 8px; border-radius:20px; white-space:nowrap; }}
.done {{ display:inline-flex; align-items:center; gap:6px; font-size:12.5px; color:var(--muted); cursor:pointer; white-space:nowrap; min-height:34px; }}
.howto {{ margin:0; padding-left:20px; display:flex; flex-direction:column; gap:6px; }}
.howto li {{ padding-left:2px; }}
.pid {{ font:500 12px/1 "JetBrains Mono",ui-monospace,monospace; color:var(--muted); }}
.done input {{ width:20px; height:20px; accent-color:var(--brand); margin:0; cursor:pointer; }}
.sr {{ position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); }}
.btn-copy {{ font:inherit; font-size:12.5px; font-weight:600; color:#fff; background:#405189; border:0; border-radius:4px; padding:7px 12px; cursor:pointer; min-height:34px; white-space:nowrap; }}
.btn-copy:hover {{ filter:brightness(.95); }}
.btn-copy:focus-visible, summary:focus-visible, .done input:focus-visible {{ outline:3px solid rgba(64,81,137,.35); outline-offset:2px; }}
.btn-copy.is-copied {{ background:#0a7d6d; }}
details {{ border-top:1px solid var(--line); }}
summary {{ cursor:pointer; padding:8px 12px; font-size:12.5px; color:var(--brand); font-weight:500; list-style:none; }}
summary::-webkit-details-marker {{ display:none; }}
summary::before {{ content:"▸ "; }}
details[open] summary::before {{ content:"▾ "; }}
.file {{ color:var(--muted); font:400 11.5px "JetBrains Mono",ui-monospace,monospace; margin-left:6px; }}
pre {{ margin:0; padding:12px 14px 16px; background:var(--code); border-top:1px solid var(--line); font:12.5px/1.6 Inter,"Segoe UI",sans-serif; white-space:pre-wrap; word-break:break-word; color:var(--text); max-height:520px; overflow:auto; }}
.pre-note {{ font-size:12.5px; color:var(--muted); }}
.foot {{ font-size:12px; color:var(--muted); display:flex; flex-direction:column; gap:4px; }}
code {{ font:12px "JetBrains Mono",ui-monospace,monospace; background:var(--code); padding:1px 5px; border-radius:4px; }}
@media (max-width:760px) {{
  .facts {{ grid-template-columns:repeat(2,minmax(0,1fr)); }}
  .decs {{ grid-template-columns:1fr; }}
  .wave-head {{ grid-template-columns:auto 1fr; }}
  .need {{ grid-column:1 / -1; justify-self:start; }}
  .card-head {{ grid-template-columns:auto auto 1fr; }}
  .card-head h3 {{ grid-column:1 / -1; }}
  .btn-copy {{ grid-column:1 / -1; min-height:44px; }}
}}
@media (prefers-reduced-motion: reduce) {{ .bar i {{ transition:none; }} }}
</style>

<div class="wrap">
  <header class="top">
    <span class="eyebrow">BSWebSaaS · Audit độ phủ thiết kế · 23/09/2026</span>
    <h1>Gói prompt SaaS Starter</h1>
    <p class="lede">{len(prompts)} lượt chat cho Claude Design để hoàn thiện và chuẩn hoá toàn bộ màn hình của bộ khung SaaS Starter, rồi nối tất cả vào Admin Shell thành một prototype bấm xuyên được. Mỗi nút <b>Sao chép prompt</b> chép đủ phần bối cảnh chung + việc của lượt đó. Chạy đúng thứ tự, mỗi prompt một cửa sổ chat mới.</p>
    <p class="meta">Nguồn: export Claude Design 18/09 (32 màn .dc.html) đối chiếu repo main <code>13281cb</code> · Báo cáo đầy đủ: <code>plans/reports/council-audit-260923-2254-saas-starter-design-coverage-and-docs-report.md</code></p>
  </header>

  <section class="panel steps" aria-labelledby="how-h">
    <h2 id="how-h">Cách dùng — 3 bước cho mỗi lượt</h2>
    <ol class="howto">
      <li><b>Bấm "Sao chép prompt"</b> ở lượt được đánh dấu <span class="next-tag">Lượt tiếp theo</span>.</li>
      <li><b>Mở một cửa sổ chat MỚI</b> trong project Claude Design BSWebSaaS, dán và gửi. Không gộp 2 lượt vào 1 cửa sổ.</li>
      <li><b>Xong thì tích ô "Xong"</b> và gửi bản export cho Claude Code để đối chiếu với code trước khi build.</li>
    </ol>
    <p class="hint">Bản 3.5 (29/09, chưa chạy lượt nào): audit khắt khe cả 25 prompt với bản export thiết kế + repo. Tách D0-4 → D0-4a/4b và D1-4b → D1-4b/4c/4d cho vừa một lượt; D0-1 đồng bộ token AA vào design system + nguồn thương hiệu duy nhất (platform-config.js) + nối "lượt AI" của Shell với ai-quota.js; giữ Khoá màn hình, Dữ liệu & Phục hồi, 9 nguồn ảnh của MediaPicker (không cắt vì backend); xoá vào Thùng rác không hỏi (Hoàn tác 8 giây), chỉ hỏi khi kéo theo mục khác hoặc không hoàn tác được; màn toàn trang khi nhúng GIỮ thanh thao tác của mình; thêm mã lỗi thật còn thiếu (Chuyên mục, Thư viện 16/16, Lời mời); kiểm bằng lưới mẫu thay tích số đầy đủ; mỗi lượt lớn có điểm dừng "phần 2". Bản 3.4 (29/09): THIẾT KẾ DẪN, BACKEND THEO — màn cần gì mà repo chưa có thì cứ vẽ, ghi vào "Việc cho backend" của README; không cắt tính năng, không vẽ trạng thái tạm. mọi lượt nhận token #92 (màu secondary/dark 2 bậc, placeholder chỉ chứa ví dụ, --primary-on-ink) và "Mẫu DANH SÁCH dùng chung" lấy từ thiết kế Bài viết/Trang đã build (mọi màn danh sách dùng lại, không chế mới); tiêu chí 6 giữ Thùng rác + Hoàn tác, chỗ backend chưa có thì ghi Chờ backend; D1-2 Trình đơn = thiết kế đã có, chỉ khớp (trục ngôn ngữ, trạng thái nguồn sản phẩm, bảng tính năng chờ backend); D1-1 Chuyên mục, D1-4b Lịch sử phiên bản theo backend đã lên prod; D1-3, D1-6, D1-7 (ô đồng ý), D0-2/D3-1 (bảng màu 10 bậc), D3-2 (tên miền riêng để sau), D4-1 (cấp thêm ngôn ngữ). Bản 3.2 (26/09): D0-4, D3-1, D4-2 — website mặc định 1 ngôn ngữ, thêm ngôn ngữ do Bảo Sơn cấp riêng. Bản 3 (24/09): 10 lượt (D0-1, D0-4, D1-2, D1-5, D2-2, D2-3, D2-4, D3-1, D4-2, D5-1) đã sửa theo backend vừa lên prod — bỏ các nhãn "Chờ backend" không còn đúng, thêm giới hạn và thông báo lỗi thật mà màn phải hiện. Bản 2 (23/09): 12 tiêu chí dễ dùng cho người không rành CNTT ở mọi lượt.</p>
  </section>

  <div class="facts" role="list">
    <div class="fact" role="listitem"><b>≈60</b><span>màn cần cho core v1 (không tính khu đăng nhập)</span></div>
    <div class="fact" role="listitem"><b>28</b><span>đã có thiết kế — 8 trong số đó lệch repo/quyết định</span></div>
    <div class="fact" role="listitem"><b>25</b><span>chưa có thiết kế</span></div>
    <div class="fact" role="listitem"><b>14</b><span>quyết định đã chốt 24/09</span></div>
  </div>

  <section class="panel" aria-labelledby="dec-h">
    <div class="panel-head"><h2 id="dec-h">✅ Đã chốt 24/09 — 14 quyết định theo khuyến nghị</h2><span class="hint">Đã làm và lên prod 24/09: Q1–Q5 (5 migration, PR #70–#74) cùng các bản sửa sau audit (#75–#83). Q6–Q9 làm cùng màn tương ứng; Q11, Q12 để sau.</span></div>
    <ul class="decs">{dec_rows}</ul>
  </section>

  <section class="panel" aria-labelledby="open-h">
    <div class="panel-head"><h2 id="open-h">🟡 Cần owner chốt — prompt đã vẽ mặc định an toàn</h2><span class="hint">Không chặn việc chạy prompt: Claude Design vẽ mặc định dưới đây và gắn "Cần owner chốt" trong README. Owner trả lời bất cứ lúc nào trước khi build màn đó.</span></div>
    <ul class="decs">{open_rows}</ul>
  </section>

  <section class="panel" aria-labelledby="run-h">
    <div class="panel-head"><h2 id="run-h">Tiến độ chạy prompt</h2><span class="hint">Đánh dấu ô vuông khi xong một lượt — chỉ lưu trên trình duyệt này</span></div>
    <div class="progress"><div class="bar"><i id="bar"></i></div><span id="count">0/{len(prompts)}</span></div>
    <details>
      <summary>Bối cảnh chung (đã nằm sẵn trong mỗi lần sao chép)</summary>
      <pre id="preamble">{e(preamble)}</pre>
    </details>
  </section>

  {''.join(wave_html)}

  <section class="panel" aria-labelledby="mx-h">
    <div class="panel-head"><h2 id="mx-h">Độ phủ hiện tại</h2><span class="hint">Thiết kế = bộ export 18/09 · Code = repo main</span></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Nhóm</th><th>Màn</th><th>Thiết kế</th><th>Code</th></tr></thead>
      <tbody>{''.join(rows)}</tbody>
    </table></div>
  </section>

  <footer class="foot">
    <span>Prompt gốc (trong git, Claude Design đọc được): <code>docs/design-briefs/saas-starter-design-first/prompts/</code> · Sổ màn: <code>docs/saas-starter-screen-map.md</code></span>
    <span>Khu đăng nhập giữ nguyên theo chỉ đạo 23/09. Bộ AI, mặt TMĐT, Khối tái dùng và Trình dựng v3 không nằm trong gói này.</span>
  </footer>
</div>

<script>
(function () {{
  var KEY = 'bs-prompt-pack-done-v2';
  var done = {{}};
  try {{ done = JSON.parse(localStorage.getItem(KEY) || '{{}}') || {{}}; }} catch (err) {{ done = {{}}; }}
  var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-done]'));
  function paint() {{
    var n = 0;
    boxes.forEach(function (b) {{
      var on = !!done[b.getAttribute('data-done')];
      b.checked = on; if (on) n++;
      var card = b.closest('.card'); if (card) card.classList.toggle('is-done', on);
    }});
    var nextSet = false;
    boxes.forEach(function (b) {{
      var card = b.closest('.card'); if (!card) return;
      var isNext = !nextSet && !b.checked;
      if (isNext) nextSet = true;
      card.classList.toggle('is-next', isNext);
      var tag = card.querySelector('.next-tag'); if (tag) tag.hidden = !isNext;
    }});
    document.getElementById('count').textContent = n + '/' + boxes.length;
    document.getElementById('bar').style.width = (boxes.length ? (n / boxes.length * 100) : 0) + '%';
  }}
  boxes.forEach(function (b) {{
    b.addEventListener('change', function () {{
      done[b.getAttribute('data-done')] = b.checked;
      try {{ localStorage.setItem(KEY, JSON.stringify(done)); }} catch (err) {{}}
      paint();
    }});
  }});
  paint();

  var preamble = document.getElementById('preamble').textContent;
  function fallbackCopy(text) {{
    var ta = document.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    var ok = false; try {{ ok = document.execCommand('copy'); }} catch (err) {{ ok = false; }}
    document.body.removeChild(ta); return ok;
  }}
  function flash(btn, label) {{
    var old = btn.getAttribute('data-label') || btn.textContent;
    btn.setAttribute('data-label', old);
    btn.textContent = label; btn.classList.add('is-copied');
    setTimeout(function () {{ btn.textContent = old; btn.classList.remove('is-copied'); }}, 1800);
  }}
  document.querySelectorAll('[data-copy]').forEach(function (btn) {{
    btn.addEventListener('click', function () {{
      var pre = document.getElementById(btn.getAttribute('data-copy'));
      var text = preamble + '\\n' + pre.textContent + '\\n';
      var done2 = function (ok) {{
        if (ok) {{ flash(btn, 'Đã chép'); return; }}
        var det = pre.closest('details'); if (det) det.open = true;
        var r = document.createRange(); r.selectNodeContents(pre);
        var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
        flash(btn, 'Đã bôi đen — nhấn Ctrl+C');
      }};
      if (navigator.clipboard && navigator.clipboard.writeText) {{
        navigator.clipboard.writeText(text).then(function () {{ done2(true); }}, function () {{ done2(fallbackCopy(text)); }});
      }} else {{ done2(fallbackCopy(text)); }}
    }});
  }});
}})();
</script>
'''

OUT.write_text(page, encoding='utf-8')
print('wrote', OUT, len(page) // 1024, 'KB', len(prompts), 'prompts')
