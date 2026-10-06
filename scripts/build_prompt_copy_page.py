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

# Corrective prompts written after auditing a turn's export. They go into the SAME chat as that
# turn, which already holds the shared context, so they are copied without the preamble.
fixes = []
for f in sorted((SRC / 'fixes').glob('*.md')):
    title, body = f.read_text(encoding='utf-8').split('\n', 1)
    pid, name = title.lstrip('# ').strip().split(' · ', 1)
    fixes.append({'id': pid, 'name': name, 'file': 'fixes/' + f.name, 'body': body.strip()})

# Corrective prompts the owner has already run, with the day they ran it. Their cards fold away
# on every device; the browser-local "Xong" boxes would only hide them on one browser.
# The name Claude Design gave each round's chat: it paraphrases the first line and drops the code.
CHAT_NAMES = {'D0-2s5': 'Focus 1b finalization'}
# Two-chat mode (owner 06/10): each side round runs beside its main-lane partner and leaves shared-file edits for the next main round to merge.
PAIRS = {'D0-6c': 'D0-6b', 'D1-6': 'D1-1', 'D2-3': 'D1-2', 'D1-7': 'D1-4b', 'D2-1': 'D1-4c', 'D2-2': 'D1-4d', 'D4-2': 'D4-3'}
PARTNER = {**PAIRS, **{m: s for s, m in PAIRS.items()}}
FIXES_RUN = {'D0-2s': '04/10', 'D0-2s2': '05/10', 'D0-2s3': '05/10', 'D0-2s4': '05/10', 'D0-3s': '06/10', 'D0-2s5': '06/10', 'D0-4as': '06/10', 'D0-4at': '06/10'}

WAVES = [
    ('D0', 'Nền', 'Làm trước mọi thứ: dọn project, chuẩn giao diện (component + mẫu trang + thuật ngữ + tiêu chí dễ dùng), Shell v2 + registry màn, nối màn đã có, rồi rà & sửa hiển thị điện thoại/máy tính bảng cho mọi màn đã có (D0-6a/6b/6c).', 'Bắt đầu được ngay'),
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
    ('Q12', 'Bộ AI có vào core v1?', 'Không: làm module riêng sau core v1 (kéo theo thanh toán). Giữ nguyên 10 màn đã vẽ, ẩn nút AI khi website không được cấp. Bổ sung 29/09 (owner): công tắc nền tảng "Lời mời nâng gói" — mặc định tắt; khi owner bật (sau khi đã dựng gói), tính năng ngoài gói hiện dạng khoá kèm nút Xem gói nâng cấp.'),
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
    ('C1', 'Khoá màn hình', '✅ Chốt theo khuyến nghị: GIỮ (đã vẽ) — khoá thật ở máy chủ, mở bằng mật khẩu, trang đang soạn giữ nguyên; thêm tuỳ chọn tự khoá khi không thao tác (mặc định Tắt) ở Bảo mật & Nâng cao. Hợp máy tính dùng chung ở văn phòng, không làm mất việc như đăng xuất.'),
    ('C2', 'Dữ liệu & Phục hồi', '✅ Chốt theo khuyến nghị: GIỮ màn đã vẽ, build theo thứ tự Xuất dữ liệu → Khôi phục (chạy nền từ bản sao lưu đêm, trích đúng dữ liệu của website đó) → Nhập. Chỉ bước Khôi phục cần họp COUNCIL (đụng tách dữ liệu giữa website) trước khi code.'),
    ('C3', 'Ai được "Đăng nhập hỗ trợ"', '✅ Chốt theo khuyến nghị, làm rõ 30/09 tối (design.md v2.33): nhân viên ĐĂNG NHẬP DƯỚI TÊN KHÁCH (như "Log In as Customer" của Plesk — tài khoản của một thành viên website, mặc định chủ website), không vào bằng tài khoản nhân viên; quyền riêng cho super_admin + support (không billing); bắt lý do, 15/30/60 phút, thanh "Quay lại quản trị" đếm ngược, nhật ký ghi cả khách lẫn nhân viên, báo chủ website; trong phiên không đổi mật khẩu/2 lớp/thành viên/tên miền, không xuất dữ liệu cá nhân; chủ website có công tắc tắt quyền này (mặc định bật). Build qua COUNCIL bảo mật.'),
    ('C4', 'Màu trang quản trị theo từng người', '✅ Chốt theo khuyến nghị: KHÔNG cho từng người — một màu cho cả nền tảng ở Thương hiệu trắng (tự đạt AA). Cá nhân vẫn chọn sáng/tối, cỡ chữ, bố cục.'),
    ('C5', 'Bản nháp đè bản công khai', '✅ Chốt theo khuyến nghị: lai theo chế độ duyệt — website KHÔNG bật quy trình: người có quyền Đăng sửa là lên ngay (A, như WordPress) + lịch sử để khôi phục, người không có quyền Đăng thì thay đổi chờ người có quyền bấm "Cập nhật website" (Q4); website BẬT quy trình: sửa bài đang công khai thành bản chờ duyệt (B), khách vẫn thấy bản cũ. Lý do: hiện lưu bài đang công khai là lên website ngay kể cả khi bật duyệt — quy trình duyệt bị lách. Backend đã dựng 30/09 (#126–#128), chờ màn D1-4d để nối.'),
    ('C6', 'Dữ liệu cá nhân của khách liên hệ (pháp lý)', '✅ Chốt hướng (luật sư xác nhận thời hạn trước khi build): xuất CSV chỉ cho người quản lý biểu mẫu, xác nhận lại mật khẩu/2 lớp, ghi nhật ký; tự xoá liên hệ sau 24 tháng (đổi được), thư rác 30 ngày; "Xoá theo yêu cầu của khách" xoá hẳn ngay (không để 30 ngày trong Thùng rác). Nhờ luật sư xác nhận thời hạn trước khi build.'),
    ('C7', 'CLAUDE.md của project Claude Design', '✅ Đã gộp vào D0-1 (việc số 0, owner uỷ quyền 30/09): Claude Design tự sửa đúng 3 chỗ — token AA, bề rộng kiểm 1240/768/390, cách nhúng màn toàn trang. Owner không phải dán riêng.'),
]


open_rows = ''.join(
    f'<li class="dec"><span class="qid">{q}</span><div><p class="dq">{e(t)}</p><p class="da">{e(a)}</p></div></li>'
    for q, t, a in OPEN)

dec_rows = ''.join(
    f'<li class="dec"><span class="qid">{q}</span><div><p class="dq">{e(t)}</p><p class="da">{e(a)}</p></div></li>'
    for q, t, a in DECISIONS)

def par_tag(p):
    other = PARTNER.get(p['id'])
    if not other:
        return ''
    role = 'luồng phụ' if p['id'] in PAIRS else 'luồng chính'
    return f'<span class="par-tag">Song song với {other} · {role}</span>'


def chat_title(p):
    # Claude Design names a chat after the first typed line, not after a pasted attachment, and
    # lists chats in no useful order, so each card offers a short title that starts with the code.
    return p['id'] + ' · ' + re.sub(r'\s*\([^)]*\)', '', p['name']).strip()

def chat_name(p):
    n = CHAT_NAMES.get(p['id'])
    return f' <span class="file">· chat: “{e(n)}”</span>' if n else ''

def fix_card(p, ran):
    return f'''
<article class="card{'' if ran else ' is-next'}" data-id="{p['id']}">
  <div class="card-head">
    <span class="pid">{p['id']}</span>
    <h3>{e(p['name'])}</h3>
    <span class="btns"><button type="button" class="btn-copy btn-title" data-title="{e(chat_title(p))}">1 · Chép tên chat</button>
    <button type="button" class="btn-copy" data-copy="b-{p['id']}" data-raw="1">2 · Sao chép prompt sửa</button></span>
  </div>
  <details>
    <summary>Xem nội dung <span class="file">{e(p['file'])}</span>{chat_name(p)}</summary>
    <pre id="b-{p['id']}">{e(p['body'])}</pre>
  </details>
</article>'''

fixes_open = [p for p in fixes if p['id'] not in FIXES_RUN]
fixes_ran = [p for p in fixes if p['id'] in FIXES_RUN]
ran_list = ' · '.join(f"{p['id']} ({FIXES_RUN[p['id']]})" for p in fixes_ran)
fix_cards = ''.join(fix_card(p, False) for p in fixes_open) or '<p class="hint">Không có prompt sửa nào đang chờ chạy.</p>'
if fixes_ran:
    fix_cards += f'''
    <details class="ran">
      <summary>Đã chạy ({len(fixes_ran)}): {e(ran_list)} — bấm để xem lại</summary>
      {''.join(fix_card(p, True) for p in fixes_ran)}
    </details>'''
fix_panel = f'''
  <section class="panel" aria-labelledby="fix-h">
    <div class="panel-head"><h2 id="fix-h">Prompt sửa sau kiểm</h2><span class="hint">Dán vào chat ghi trong tiêu đề prompt (chat của lượt đó, hoặc chat mới nếu prompt ghi vậy). Sửa xong thì xuất zip gửi Claude Code kiểm lại rồi mới sang lượt tiếp theo.</span></div>
    {fix_cards}
  </section>''' if fixes else ''

wave_html = []
for wid, wname, wdesc, wneed in WAVES:
    cards = []
    for p in [p for p in prompts if p['id'].startswith(wid)]:
        body_id = 'b-' + p['id']
        cards.append(f'''
<article class="card" data-id="{p['id']}" data-par="{PARTNER.get(p['id'], '')}">
  <div class="card-head">
    <label class="done"><input type="checkbox" id="done-{p['id']}" data-done="{p['id']}"><span>Xong</span></label>
    <span class="pid">{p['id']}</span><span class="next-tag" hidden>Lượt tiếp theo</span>
    <h3>{e(p['name'])}</h3>{par_tag(p)}
    <span class="btns"><button type="button" class="btn-copy btn-title" data-title="{e(chat_title(p))}">1 · Chép tên chat</button>
    <button type="button" class="btn-copy" data-copy="{body_id}">2 · Sao chép prompt</button></span>
  </div>
  <details>
    <summary>Xem nội dung <span class="file">{e(p['file'])}</span>{chat_name(p)}</summary>
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
  --heading:#343a40; --text:#495057; --muted:#6b6e7f; --brand:#405189; --brand-soft:rgba(64,81,137,.10);
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
.ran {{ display:flex; flex-direction:column; gap:10px; }}
.ran > summary {{ font-size:13px; color:var(--muted); cursor:pointer; min-height:34px; display:flex; align-items:center; }}
.ran .card {{ opacity:.75; }}
.card-head {{ display:grid; grid-template-columns:auto auto auto 1fr auto; align-items:center; gap:10px; padding:10px 12px; }}
.card.is-next {{ border-color:var(--brand); box-shadow:0 0 0 2px var(--brand-soft); }}
.par-tag {{ font-size:11px; font-weight:700; color:#405189; background:#eef1fa; padding:2px 8px; border-radius:20px; white-space:nowrap; }}
.next-tag {{ font-size:11px; font-weight:700; color:#fff; background:#405189; padding:2px 8px; border-radius:20px; white-space:nowrap; }}
.done {{ display:inline-flex; align-items:center; gap:6px; font-size:12.5px; color:var(--muted); cursor:pointer; white-space:nowrap; min-height:34px; }}
.howto {{ margin:0; padding-left:20px; display:flex; flex-direction:column; gap:6px; }}
.howto li {{ padding-left:2px; }}
.pid {{ font:500 12px/1 "JetBrains Mono",ui-monospace,monospace; color:var(--muted); }}
.done input {{ width:20px; height:20px; accent-color:var(--brand); margin:0; cursor:pointer; }}
.sr {{ position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); }}
.btns {{ display:flex; gap:8px; flex-wrap:wrap; }}
.btn-title {{ background:#fff !important; color:#405189 !important; box-shadow:inset 0 0 0 1px #405189; }}
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
  .btns {{ grid-column:1 / -1; }} .btns .btn-copy {{ flex:1; min-height:44px; }}
}}
@media (prefers-reduced-motion: reduce) {{ .bar i {{ transition:none; }} }}
</style>

<div class="wrap">
  <header class="top">
    <span class="eyebrow">BSWebSaaS · Gói prompt Claude Design · cập nhật 04/10/2026</span>
    <h1>Gói prompt SaaS Starter</h1>
    <p class="lede">{len(prompts)} lượt chat cho Claude Design để hoàn thiện và chuẩn hoá toàn bộ màn hình của bộ khung SaaS Starter, rồi nối tất cả vào Admin Shell thành một prototype bấm xuyên được. Mỗi nút <b>Sao chép prompt</b> chép đủ phần bối cảnh chung + việc của lượt đó. Chạy đúng thứ tự, mỗi prompt một cửa sổ chat mới.</p>
    <p class="meta">Nguồn: export Claude Design 18/09 (32 màn .dc.html, lưu ở <code>docs/design-source/</code>) đối chiếu repo main · Audit 29/09: <code>plans/reports/from-design-audit-*-260929-1600-*</code> · Audit gốc 23/09: <code>plans/reports/council-audit-260923-2254-…</code></p>
  </header>

  <section class="panel steps" aria-labelledby="how-h">
    <h2 id="how-h">Cách dùng — 3 bước cho mỗi lượt</h2>
    <ol class="howto">
      <li><b>Bấm "Sao chép prompt"</b> ở lượt được đánh dấu <span class="next-tag">Lượt tiếp theo</span>.</li>
      <li><b>Mở một cửa sổ chat MỚI</b> trong project Claude Design BSWebSaaS, dán và gửi. Không gộp 2 lượt vào 1 cửa sổ.</li>
      <li><b>Xong thì tích ô "Xong"</b> và gửi bản export cho Claude Code để đối chiếu với code trước khi build.</li>
    </ol>
    <p class="hint">Bản 3.28 (06/10 22:5x) — CHẤT LƯỢNG HOÀN THIỆN (owner, áp mọi dự án thiết kế): bối cảnh chung của cả 31 prompt có thêm khối A–E — đẹp &amp; nhất quán (thang 4/8px, 1 nút chính, chuyển động nhẹ, không giật khi tải) · người lớn tuổi &amp; người mới (chữ ≥ 14px, nhãn luôn hiện, (?) giải thích, không việc nào chỉ làm được bằng kéo-thả/rê chuột, phản hồi trong 1 giây, báo trước khi hết phiên, mất mạng không mất chữ) · bàn phím &amp; trình đọc màn hình · chế độ tối chỉ dùng token + ô nhập giống nhau giữa trình duyệt + nhãn dài 40% · đo thật cả sáng lẫn tối trước khi ghi "đạt". README mỗi lượt có mục "Kết quả Chất lượng hoàn thiện". Áp từ lượt chưa chạy (D1-1 trở đi).</p>
    <p class="hint">Bản 3.27 (06/10 16:0x) — CHẠY TỰ ĐỘNG: phiên designbot (lệnh <code>ccd</code>) tự dán prompt, kiểm "Xong khi", tải zip theo <code>designbot-runbook.md</code> — mọi lượt chạy lần lượt, một mình (đoạn "CHẠY SONG SONG" không áp). Owner giao việc bằng một dòng: <code>Đọc và làm theo /opt/bswebsaas/dev/docs/design-briefs/saas-starter-design-first/designbot-runbook.md</code>; prompt sửa cũng một dòng <code>Đọc và làm theo &lt;tệp fixes&gt;</code>. Trong lúc designbot chạy, owner không gõ vào Claude Design. D1-5 không còn chạy cặp với D1-4a (D1-5 cần D1-4b).</p>
    <p class="hint">Bản 3.26 (06/10 13:1x) — OWNER GIỮ NÚT CÔNG CỤ HEADER: nút cờ Ngôn ngữ giao diện, Toàn màn hình, Sáng/tối quay lại topbar (≥ 768px; điện thoại vẫn ở menu người dùng vì không đủ chỗ cho vùng bấm 44px). Prompt D0-4a gỡ chúng là SAI của Claude Code (gộp vào "topbar gọn" từ 24/09 mà không hỏi owner). Chép <b>D0-4at</b> ở panel "Prompt sửa sau kiểm" dán vào chat <b>D0-4b</b> (cùng D0-4as; D0-4at thắng điểm 6 của D0-4as). Nút Tùy biến nổi vẫn bỏ (đè nội dung màn hẹp; Tùy biến ở menu người dùng › Giao diện). Bộ chuyển website (owner 06/10): 1 website = ẩn; ≥ 2 = đầu vùng điều hướng dưới logo (thanh icon / 768–1023: ô chữ cái đầu 44×44; điện thoại: chỉ đầu ngăn kéo; ngang: sau logo); không có ở mặt Nền tảng và phiên hỗ trợ. D0-4at đã qua 2 vòng rà (Opus + Sonnet, 13:40). THỨ TỰ: D0-4as (đã chạy 06/10) → D0-4at trong CÙNG chat đó → rồi mở CHAT MỚI chạy prompt D0-4b (việc chính: Tweak, registry màn, bs-goto, ẩn menu theo quyền).</p>
    <p class="hint">Bản 3.25 (06/10 12:4x): KIỂM ZIP D0-4a (11:53) — ĐẠT cả 7 mục "Xong khi" (Chromium thật: 3 bố cục × thanh Đậm/Sáng/Chuyển màu × 360/768/1024/1440 × sáng/tối; thanh Lưu "1 thay đổi chưa lưu · 1 lỗi · Xem lỗi đầu tiên" đưa con trỏ đúng ô; Cài đặt 390 = danh sách + "‹ Cài đặt"; tương phản mục đang chọn 6,61–7,05, tối 12,15). Còn 8 điểm nhỏ trong Shell → panel "Prompt sửa sau kiểm" có <b>D0-4as</b>: chép và dán vào CHÍNH chat <b>D0-4b</b> đang chạy (làm sau việc D0-4b, trước khi xuất zip). Chưa mở chat D0-4b thì dán D0-4as ngay sau prompt D0-4b.</p>
    <p class="hint">Bản 3.24 (06/10 11:4x) — TĂNG TỐC: (1) Từ D0-4b: lượt báo "xong — gửi zip" thì gửi zip cho Claude Code rồi MỞ NGAY lượt kế ở chat mới, không chờ kiểm; lỗi tìm thấy sẽ có đoạn "Sửa thêm trước khi xuất zip: …" để dán vào chat ĐANG chạy. (2) Từ D0-6b: chạy 2 chat cùng lúc theo cặp có nhãn "Song song với …" (7 cặp — 06/10 16:0x bỏ cặp D1-4a‖D1-5 vì D1-5 cần D1-4b: D0-6b‖D0-6c · D1-1‖D1-6 · D1-2‖D2-3 · D1-4b‖D1-7 · D1-4c‖D2-1 · D1-4d‖D2-2 · D4-3‖D4-2). Mở 2 chat cùng lúc, mỗi chat một lượt; xong CẢ HAI mới sang cặp kế; gửi zip của chat xong SAU (zip chứa cả hai). Lượt luồng phụ không sửa tệp chung (TODO, LESSONS, Bản đồ, shell-screens.js) mà ghi mục "Gộp vào tệp chung" trong README của nó; lượt luồng chính kế tiếp tự gộp. Muốn chạy từng lượt như cũ thì cứ chạy — đoạn song song tự bỏ qua. Tối đa 2 chat; hạn mức Claude Design dùng nhanh hơn (tổng không đổi).</p>
    <p class="hint">Bản 3.23 (06/10 11:0x): ĐÃ RÀ KỸ CẢ 28 LƯỢT CHƯA CHẠY (D0-4a → D5-2; mỗi nhóm Opus rà + Sonnet kiểm vòng 2, đối chiếu zip 06/10 và repo). Mọi prompt có: Cần trước · Được sửa · Sắp hết lượt (chia phần 2) · Xong khi đánh số · verifier quá giờ thì ghi [CHƯA KIỂM ĐƯỢC] · xuất zip + câu chốt. Sửa sự thật về repo (gói & tính năng ĐÃ có đường ghi; đăng nhập hỗ trợ ĐÃ có lõi; gửi mail Google/Microsoft ĐÃ có; link lời mời KHÔNG chép được — chỉ lưu mã băm). Câu "Cần owner chốt" ghi trong README của từng lượt, không chặn lượt. README gói prompt: thứ tự đúng D0-4a → D5-2.</p>
    <p class="hint">Bản 3.22 (06/10 10:2x): đã rà kỹ thêm nhóm D1 (D1-1 · D1-2 · D1-3 · D1-4a · D1-4b · D1-4c · D1-4d — Opus rà + Sonnet kiểm vòng 2): đủ khung kết lượt; Trình đơn: mỗi ngôn ngữ chọn rõ trình đơn riêng / dùng của ngôn ngữ mặc định (đúng cách repo hiện trình đơn); `Quản lý Bài viết - Options` = khuôn DANH SÁCH chung, `Page Editor - Options` = khuôn SOẠN chung (gỡ form Thêm/Sửa khỏi danh sách); D1-4b chia 2 phần. Còn chưa rà: D1-5 → D5-2 (15 lượt) — Claude Code rà dần trước khi tới.</p>
    <p class="hint">Bản 3.21 (06/10 10:xx): Claude Code kiểm zip 06/10 bằng Chromium thật — D0-2 (đến D0-2s5) và D0-3 ĐẠT, chốt cả hai; 4 lỗi nhỏ còn lại của trang chuẩn gộp vào D0-5. Đã rà kỹ (2 vòng mỗi nhóm) và viết lại D0-4a · D0-4b · D0-5 · D0-6a · D0-6b · D0-6c: đúng menu/quyền theo repo, việc trong TODO.md chia đúng lượt (không lượt nào sửa một màn hai lần), mỗi prompt có danh sách file được sửa, điểm chia khi sắp hết lượt, luật verifier quá giờ, xuất zip + câu chốt. LƯỢT TIẾP THEO: D0-4a — chat MỚI, bấm "1 · Chép tên chat" rồi "2 · Sao chép prompt".</p>
    <p class="hint">Cách dán (06/10): mỗi thẻ có 2 nút. Bấm "1 · Chép tên chat" → dán vào ô nhập của chat (dòng có mã lượt, vd "D0-2s5 · …", để Claude Design đặt tên chat theo mã — tìm lại dễ). Rồi bấm "2 · Sao chép prompt" → dán tiếp vào cùng ô (đoạn dài tự thành tệp đính kèm "Pasted text") → Send.</p>
    <p class="hint">Bản 3.20 (05/10 23:xx) — THỨ TỰ: (1) dán D0-3s vào CHÍNH chat D0-3 (sửa 24 điểm bản tự rà theo chốt của Claude Code, rồi làm luôn phần 2; nếu dừng giữa chừng gửi "Làm D0-3 phần 2"); (2) khi chat báo "D0-3 xong — dán D0-2s5 vào chat D0-2." thì dán D0-2s5 vào một CHAT MỚI (06/10: chat D0-2 cũ đã quá dài; prompt tự đủ); (3) xuất MỘT zip chung gửi Claude Code. Không chạy hai chat cùng lúc. Bản 3.19 (05/10 22:5x): D0-2s4 đạt gần hết (Claude Code dựng hình zip 22:04: khung 1b trang Focus khác 0 pixel khi Tab, ô lỗi giữ đỏ, hết khung đôi, mọi chỉ báo 4,55–9,7:1). Còn 7 chỗ nhỏ (nút ghost 3px, hộp thoại 768–799, màu nút đặc chế độ tối…) → D0-2s5 (đã qua 2 vòng kiểm). D0-6a, D1-2, D1-4a, D1-4b, D3-2 nhận thêm việc focus của màn mình (thân trình soạn chưa có chỉ báo focus; khung tự vẽ focus bằng màu viết thẳng). <p class="hint">Bản 3.18b (05/10 21:4x): D0-2s4 viết lại sau 4 vòng kiểm của Claude Code — nêu HÌNH từng loại phần tử + phép thử thay vì chỉ selector (bản 20:5x làm mất focus ở ~63 nút đặc viết style inline), thêm vạch vàng thanh bên không mất khi focus; dòng bối cảnh chung về màu vòng focus (var(--focus-ring-color)) và Focus 1b sửa ở cả 31 lượt. Nếu đã dán bản D0-2s4 20:5x thì báo Claude Code để nhận prompt bù ngắn. Bản 3.18 (05/10 tối): D0-2s3 ĐẠT cả 9 mục (Claude Code dựng hình thật zip 20:05: hàng tab 390 + Chữ lớn vừa khít, Sao chép đúng cả 5 tình huống, console sạch). Owner chốt chuẩn focus mới "Focus 1b" — nhưng luật chung trong admin-base.css vẽ khác hình 1b đã duyệt, ô lỗi khi focus thành xanh, khung tìm kiếm sáng đôi, hộp thoại 480–767 không còn tràn bề rộng → dán D0-2s4 vào CHÍNH chat D0-2. KHÔNG cần xuất zip riêng: khi chat báo "D0-2s4 xong — mở D0-3 được." thì mở D0-3 (chat mới); Claude Code kiểm D0-2s4 cùng zip sau D0-3. Mọi lượt từ D0-3 trở đi: dòng Focus trong bối cảnh chung đổi sang Focus 1b (dùng lại luật chung, không vẽ focus riêng từng màn).</p>
    <p class="hint">Bản 3.17 (05/10 trưa): D0-2s2 ĐẠT — kiểm lần 3 dựng hình thật 360–1440 × sáng/tối × Chữ lớn × chuột/cảm ứng, mọi mục cần dựng hình đều qua. Còn 1 lỗi TB (nút Sao chép đường dẫn / tên miền báo thành công khi không chép được — có từ trước) + 5 lỗi Thấp → dán D0-2s3 vào CHÍNH chat D0-2 (prompt tự điền số đo của Claude Code vào README và tự chép mục kiểm vào AUDIT.md). KHÔNG cần xuất zip riêng: khi chat D0-2 báo "D0-2s3 xong — mở D0-3 được." thì mở D0-3 (chat mới); Claude Code kiểm D0-2s3 cùng bản zip sau D0-3. D1-3 / D1-4b nhận thêm luật mới của backend (đổi tab thì bỏ chọn; không có nút bấm mà không có gì xảy ra).</p>
    <p class="hint">Bản 3.16 (04/10 tối): D0-2s đã kiểm — đủ 16/16 mục, nhưng lần kiểm 2 (dựng hình thật 360–1440 × sáng/tối × Chữ lớn + đối chiếu repo) còn 12 lỗi Cao (D0-2s2 sửa 6 lỗi ở trang chuẩn + vẽ tạm Alert/Avatar; phần còn lại vào D0-2 phần 3, prompt DS, repo) → dán D0-2s2 vào CHÍNH chat D0-2 (prompt này cũng tự chép mục kiểm vào AUDIT.md), xuất zip gửi Claude Code kiểm, đạt thì sang D0-3. Mọi lượt: tên thẻ component là &lt;x-bs-admin::…&gt;; D0-3 chốt thêm bộ từ nút AI (Gợi ý … · Viết lại · Viết mới · Nâng cấp bài viết / trang · Dịch · Tạo ảnh, không "bằng AI") và "Sao chép / Đã sao chép". Bản 3.15: D0-3 viết lại sau 4 vòng audit: làm 2 phần trong cùng một chat (phần 1 chữ & luật: thuật ngữ · định dạng · giọng văn · trạng thái · tiêu chí · người dùng mẫu; phần 2 mẫu trang — nếu lượt dừng sau phần 1, nhắn "Làm D0-3 phần 2"); bảng thuật ngữ đã chốt sẵn: nhóm quyền theo thiết kế (Chủ website · Quản trị · Biên tập · Người viết · Cộng tác viên · Chỉ xem), "Hẹn giờ đăng", "Đưa về nháp" ≠ "Gỡ khỏi website", "Không công khai", Hạn ngạch ≠ Hạn mức, "Đăng nhập dưới tên khách hàng". Bối cảnh chung mọi lượt: màu CHỈ qua token (tông Bảo Sơn Hoàng gia); chính tả Xóa · Hủy · Khóa · tùy · Bỏ lọc (kiểu dấu cũ cho vần oa/oe/uy không có phụ âm cuối; viết hoa theo vị trí); mẫu danh sách "Bỏ lọc" + "Hẹn giờ đăng"; dòng TODO.md gắn tên lượt là việc của lượt đó.</p>
    <p class="hint">Bản 3.13 (04/10): theo code 01–03/10, CHỈ đổi các lượt D2–D4 (D0, D1 không đổi — cứ chạy tiếp). D3-2 mục Tên miền và D4-1 tab Tên miền: khách TỰ gắn tên miền riêng, trỏ về MỘT địa chỉ chung (không ghi IP), cặp có / không www, Bật HTTPS là bước riêng, tranh chấp chỉ xử bằng bản ghi TXT, vẽ đủ trạng thái tên miền hỏng / còn 7 ngày / đã ngừng / hoạt động lại. D2-4 thêm thông báo tên miền (chuông + thư) và thông báo đăng nhập dưới tên khách đã có thật (11 loại). D4-1 tab Tính năng và D4-2: ghi đè theo website có nút "Bỏ ghi đè", "Số website tối đa" chỉ đặt theo gói khách hàng, thêm "Số tên miền riêng tối đa"; hạ hạn mức không gỡ thứ đang có. D2-5 thêm việc "tên miền cần sửa".</p>
    <p class="hint">Bản 3.12 (30/09 22:5x): QUY TRÌNH DUYỆT dời lên console — nền tảng cấp riêng cho từng website ở Nền tảng › Website › chi tiết › tab "Quy trình duyệt" (D4-1), KHÔNG còn ở Cài đặt website (D0-4a, D0-5, D3-1 sửa theo); người của nền tảng chỉ vào admin website bằng "Đăng nhập dưới tên khách" (design.md v2.36). D0-1 đã chạy: dán đoạn bổ sung owner nhận trong phiên R43.</p>
    <p class="hint">Bản 3.11 (30/09 tối): đăng nhập hỗ trợ = ĐĂNG NHẬP DƯỚI TÊN KHÁCH (owner làm rõ C3, design.md v2.33 — kiểu "Log In as Customer" của Plesk): D4-3 vẽ lại hộp, thanh "Đang đăng nhập với tên … · Quay lại quản trị", danh sách các lần đăng nhập, nhật ký "thay mặt"; D4-1 thêm hành động trên từng dòng Website; D3-2 đổi tên công tắc của chủ website; D2-4 thêm loại thông báo bảo mật; D0-4b, D5-2 đổi tên theo. D0-1, D0-2, D0-3, D0-4a, D0-5, D0-6 KHÔNG đổi. Bản 3.10 (30/09 chiều): luật bảng cuộn trong khung ghi rõ là ngoại lệ của tiêu chí 8 (chỉ bảng dữ liệu); D1-2 kiểm hồi quy theo bảng thiết bị; D1-4d mục 1 khớp quyết định Q4. Bản 3.9 (30/09 chiều): HIỂN THỊ ĐA THIẾT BỊ (owner báo màn Quy trình duyệt vỡ trên điện thoại) — bối cảnh chung của mọi lượt có bảng thiết bị 360→1440 + xoay ngang × Chrome/Safari iOS/Firefox/Samsung Internet, luật "bảng → thẻ gọn, không xếp mỗi cột một dòng", công tắc đứng cạnh tên việc, hộp thoại trượt từ đáy, README có bảng Kiểm hiển thị; D0-2 thêm mẫu co giãn dùng chung; thêm D0-6a/6b/6c rà & sửa toàn bộ 32 màn đã vẽ (trình soạn ở D1-4d, 6c bộ AI không chặn D1–D4). Cũng trong bản này: D1-4d vẽ đủ ba trường hợp của thay đổi chờ — tắt duyệt có quyền Đăng (lên ngay), tắt duyệt không có quyền Đăng ("Lưu thay đổi" → chờ "Cập nhật website", theo quyết định Q4), bật duyệt (gửi duyệt, khoá sau khi gửi) — cùng câu báo lỗi thật của backend C5. Bản 3.8 (30/09): owner chốt C1–C7 theo khuyến nghị; D1-4d vẽ thẳng hai chế độ tắt/bật duyệt (không còn A/B để chọn); Bước 0 gộp vào D0-1 (Claude Design tự sửa CLAUDE.md đúng 3 chỗ) — chỉ còn việc chép–dán từng prompt. Bản 3.7 (29/09 khuya): công tắc "Lời mời nâng gói" theo ý owner (mặc định tắt, vẽ ở D4-2, hiện ở mọi màn có tính năng ngoài gói); thêm màn Nhật ký hoạt động của website (D4-3, menu Hệ thống); báo khi hai người cùng sửa một bài (D1-4d); ô nhập tiếng Ả Rập viết phải-sang-trái; chuẩn định dạng ngày/giờ/tiền (D0-3); trợ năng cho component (D0-2); bắt buộc thành viên bật 2 lớp (D3-2). Bản 3.6 (29/09 tối): soát lại lần 2 — luật AI theo quyết định Q12 vào bối cảnh chung (nút AI chỉ hiện khi website được cấp); mọi mã màu cứng ở phần lượt chạm đổi sang token; 7 mục "Cần owner chốt" có khuyến nghị và prompt vẽ đúng khuyến nghị (khoá màn hình khoá thật ở máy chủ, khôi phục chạy nền, phiên hỗ trợ bị giới hạn + chủ website tắt được, xoá theo yêu cầu khách là xoá hẳn); D4 ghi đúng quyền registry; thêm Bước 0 cho CLAUDE.md của project. Bản 3.5 (29/09, chưa chạy lượt nào): audit khắt khe cả 25 prompt với bản export thiết kế + repo. Tách D0-4 → D0-4a/4b và D1-4b → D1-4b/4c/4d cho vừa một lượt; D0-1 đồng bộ token AA vào design system + nguồn thương hiệu duy nhất (platform-config.js) + nối "lượt AI" của Shell với ai-quota.js; giữ Khoá màn hình, Dữ liệu & Phục hồi, 9 nguồn ảnh của MediaPicker (không cắt vì backend); xoá vào Thùng rác không hỏi (Hoàn tác 8 giây), chỉ hỏi khi kéo theo mục khác hoặc không hoàn tác được; màn toàn trang khi nhúng GIỮ thanh thao tác của mình; thêm mã lỗi thật còn thiếu (Chuyên mục, Thư viện 16/16, Lời mời); kiểm bằng lưới mẫu thay tích số đầy đủ; mỗi lượt lớn có điểm dừng "phần 2". Bản 3.4 (29/09): THIẾT KẾ DẪN, BACKEND THEO — màn cần gì mà repo chưa có thì cứ vẽ, ghi vào "Việc cho backend" của README; không cắt tính năng, không vẽ trạng thái tạm. mọi lượt nhận token #92 (màu secondary/dark 2 bậc, placeholder chỉ chứa ví dụ, --primary-on-ink) và "Mẫu DANH SÁCH dùng chung" lấy từ thiết kế Bài viết/Trang đã build (mọi màn danh sách dùng lại, không chế mới); tiêu chí 6 giữ Thùng rác + Hoàn tác, chỗ backend chưa có thì ghi Chờ backend; D1-2 Trình đơn = thiết kế đã có, chỉ khớp (trục ngôn ngữ, trạng thái nguồn sản phẩm, bảng tính năng chờ backend); D1-1 Chuyên mục, D1-4b Lịch sử phiên bản theo backend đã lên prod; D1-3, D1-6, D1-7 (ô đồng ý), D0-2/D3-1 (bảng màu 10 bậc), D3-2 (tên miền riêng để sau), D4-1 (cấp thêm ngôn ngữ). Bản 3.2 (26/09): D0-4, D3-1, D4-2 — website mặc định 1 ngôn ngữ, thêm ngôn ngữ do Bảo Sơn cấp riêng. Bản 3 (24/09): 10 lượt (D0-1, D0-4, D1-2, D1-5, D2-2, D2-3, D2-4, D3-1, D4-2, D5-1) đã sửa theo backend vừa lên prod — bỏ các nhãn "Chờ backend" không còn đúng, thêm giới hạn và thông báo lỗi thật mà màn phải hiện. Bản 2 (23/09): 12 tiêu chí dễ dùng cho người không rành CNTT ở mọi lượt.</p>
  </section>
{fix_panel}
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
    <div class="panel-head"><h2 id="open-h">✅ Đã chốt 30/09 — C1–C7 theo khuyến nghị</h2><span class="hint">Prompt vẽ đúng các quyết định này. C2 (khôi phục), C3 (đăng nhập hỗ trợ) vẫn qua COUNCIL về cách làm trước khi code; C5 (thay đổi chờ duyệt) đã qua COUNCIL, backend dựng xong 30/09, chờ màn D1-4d.</span></div>
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
    var first = null;
    boxes.forEach(function (b) {{ if (!first && !b.checked && b.closest('.wave')) first = b.getAttribute('data-done'); }});
    boxes.forEach(function (b) {{
      var card = b.closest('.card'); if (!card) return;
      var id = b.getAttribute('data-done'), par = card.getAttribute('data-par');
      // A side round is "next" beside its partner, and stays flagged until ticked once its partner is done.
      var isNext = !b.checked && card.closest('.wave') !== null && (id === first || (par && (par === first || !!done[par])));
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
  document.querySelectorAll('[data-title]').forEach(function (btn) {{
    btn.addEventListener('click', function () {{
      var t = btn.getAttribute('data-title');
      var fin = function (ok) {{ flash(btn, ok ? 'Đã chép tên' : 'Chưa chép được — gõ tay: ' + t); }};
      if (navigator.clipboard && navigator.clipboard.writeText) {{
        navigator.clipboard.writeText(t).then(function () {{ fin(true); }}, function () {{ fin(fallbackCopy(t)); }});
      }} else {{ fin(fallbackCopy(t)); }}
    }});
  }});
  document.querySelectorAll('[data-copy]').forEach(function (btn) {{
    btn.addEventListener('click', function () {{
      var pre = document.getElementById(btn.getAttribute('data-copy'));
      var text = btn.hasAttribute('data-raw') ? pre.textContent + '\\n' : preamble + '\\n' + pre.textContent + '\\n';
      var done2 = function (ok) {{
        if (ok) {{ flash(btn, 'Đã sao chép'); return; }}
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
