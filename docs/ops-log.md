# Nhật ký vận hành (ops log)

> Ghi các thao tác vận hành ở tầng **máy chủ / hạ tầng** không thuộc code repo: cài/gỡ gói hệ thống,
> thay đổi thủ công trên host, sự cố + cách xử lý. Quy trình lặp lại nằm ở `docs/runbooks/`; file này
> là NHẬT KÝ theo thời gian (mới nhất trên cùng). Máy chủ dùng chung **dev + prod: `103.82.27.129`**.
> Ràng buộc cài/gỡ gói hệ thống: xem `CLAUDE.md` §7 (Host safety).

## 2026

### 2026-10-29 — Gỡ ClaudeKit khỏi cấu hình Claude Code (quay lại được); MEMORY.md tiếng Anh

- **Owner duyệt trong phiên (29/10, "Làm cả hai"):** gỡ bộ ClaudeKit khỏi `~/.claude` của root theo cách quay lại được. Lý do: 0 lần dùng skill của kit trong 30 ngày; tốn khoảng 8K token mỗi phiên và mỗi tác tử phụ; `scout-block` chặn `vendor/bin/pest`, `phpstan` và `pint`; `session-init` chèn thông tin sai ("monorepo, pnpm").
- **Đã làm:**
  - sao lưu `settings.json`, `metadata.json`, `.ck.json`, `CLAUDE.md`, `rules/`, `agents/` vào `~/.claude/backup-ck-261029/pre/`;
  - gỡ 12 hook của kit khỏi `settings.json`; giữ `review-loop.py`, `claude-context-reminder`, `claude-helper-gate-v2`, hook làm mới hạn mức và `statusline.cjs`;
  - thêm `permissions.deny` cho Read, dạng tuyệt đối `//**/…` (phủ cả ổ đĩa, gồm `.env` của bản prod): `.env*`, `*.pem`, `*.key`, `~/.ssh/**`, `.credentials.json`, `~/.claude.json` và `~/.bashrc`. Đã thử với tệp giả: dạng `**/…` chặn trong thư mục dự án; dạng `//**/…` chặn được cả ở `/opt/bswebsaas/` (ngoài dự án). Việc dạng `**/…` không phủ ngoài dự án là theo tài liệu Claude Code; phép thử trực tiếp bị bộ phân loại an toàn từ chối, vì nó không cho nới luật chặn, kể cả để thử. Đọc lại nhật ký 30 ngày: không có lần Read nào vào `.env` thật. Thư mục scratchpad riêng của phiên được Claude Code miễn trừ;
  - `mv` (không xóa) 13 tác tử, 6 tệp luật, 96 mục trong `skills/` (84 skill, các tệp phụ và `.venv`), 20 mục trong `hooks/` (13 tệp hook và 7 thư mục / tệp dữ liệu), thư mục trống `agent-memory/researcher`, `output-styles`, `scripts`, `.ckignore` và `session-states` sang `~/.claude/backup-ck-261029/moved/` (507 MB);
  - chuyển luật của owner trong tệp kit sang `~/.claude/CLAUDE.md`, mục "Decisions and reviews";
  - tạo tác tử `~/.claude/agents/reviewer.md` thay `code-reviewer`; hook review-loop nhận tên có chữ "review".
- **Giữ nguyên:**
  - `hooks/lib/` và `.ck.json`, vì thanh trạng thái và hook hạn mức cần chúng; `metadata.json` giữ để quay lại (không tệp nào đang đọc nó);
  - `agents/export-checker.md`;
  - `rules/model-routing.md`, `rules/design-quality-bar.md`;
  - `skills/synced`;
- **Gỡ gói npm toàn cục (owner duyệt riêng, 29/10):** `npm rm -g claudekit-cli` (bản 4.4.0, gỡ 206 gói, gồm lệnh `/usr/bin/ck`). Hậu kiểm: `which ck` không còn kết quả; `npm ls -g` không còn claudekit. Cài lại: `npm i -g claudekit-cli@4.4.0`.
- **Hậu kiểm:** `settings.json` là JSON hợp lệ; cả 7 đường dẫn hook đều tồn tại; `statusline.cjs` hiện được ô 5 giờ / tuần; hook hạn mức thoát mã 0; `review-loop.py` biên dịch được. Không mã dự án nào trỏ tới tệp đã chuyển. Còn lại vô hại: khoảng 75 mục `skillOverrides` trong `.claude/settings.local.json` của dự án ghi tên skill đã gỡ. Soát độc lập (Opus, chỉ đọc) xác nhận các điểm trên. Theo kết quả soát: sửa dòng nhắc trong `review-loop.py` (trước nhắc `bk-review`, không có trên máy) để trỏ tới tác tử `reviewer`, bản cũ ở `pre/review-loop.py.bak`; bổ sung 4 luật còn sót vào `~/.claude/CLAUDE.md`.
- **Đường lùi** (`B=~/.claude/backup-ck-261029`):
  1. chép `$B/pre/settings.json` và `$B/pre/CLAUDE.md` về `~/.claude/`, rồi thêm lại khối `permissions.deny` (bản `pre` không có khối này);
  2. với `agents`, `rules`, `skills`, `hooks`: `mv $B/moved/<thư mục>/* $B/moved/<thư mục>/.[!.]* ~/.claude/<thư mục>/`. Không `mv` cả thư mục, vì thư mục đích vẫn còn và không trống;
  3. `mv $B/moved/{output-styles,scripts,.ckignore,session-states} ~/.claude/`;
  4. các dòng quyền đã bỏ nằm ở `$B/pre/settings.local.removed-allow.txt`; `review-loop.py` cũ ở `$B/pre/review-loop.py.bak`;
  5. cài lại `npm i -g claudekit-cli@4.4.0`. Cách khác: cài lại gói rồi chạy `ck init -g` (ghi đè `settings.json`).
- **MEMORY.md của dự án:** viết lại chỉ mục bằng tiếng Anh, chia nhóm (17,8K ký tự; Claude Code nạp tối đa 25K). 3 mục đã có bản thay thế được chuyển sang `ARCHIVE.md`. Bản cũ ở `~/.claude/backup-ck-261029/memory-index/MEMORY.md.bak`.
- **Chặn đọc bản sao cấu hình (owner duyệt, 29/10):** thêm `Read(~/.claude/settings.json.bak*)` và `Read(~/.claude/backup-*/**/settings.json)`, vì các bản sao còn khối `env` chứa token. Đã thử với tệp giả: bị chặn.
- **Dọn thêm:** bỏ 10 quyền `Bash(ck …)` trong `~/.claude/settings.local.json` (`ck init *` được duyệt sẵn có thể cài lại kit) và quyền chạy python của `.venv` kit trong `.claude/settings.local.json` của dự án.
- **Đo ngay sau khi gỡ (29/10 22:49):** phiên mới không tương tác (`claude -p "Reply with the single word OK" --max-turns 1`), ngữ cảnh ở bước đầu trên Haiku là 49,6K token; cùng phép đo ngày 08/10 là 62,5K. Trên Sonnet là 48,8K.
- **Theo dõi:** các phiên đang chạy nhận cấu hình hook mới ngay (nhật ký hook sau 15:13 UTC chỉ còn hook hạn mức). Ngày 31/10–01/11, đo lại bằng `cc-usage 7` và số token ở bước đầu phiên.

### 2026-10-27 — Ổ đĩa 99%: dọn 27,3 GB đồ đo cũ; cài image Playwright (WebKit + Firefox)

- **Sự cố:** lúc 08:1x +07, `/` đã dùng 95G / 96G (99%), còn khoảng 1,7 GB, trên máy dùng chung với prod. Phần lớn là ảnh chụp và bản giải nén thiết kế của các lượt đo cũ trong `plans/reports/d1-261019/` (38 GB).
- **Xử lý (owner chọn cách A trong phiên):** xóa các tệp cũ hơn 3 ngày, giữ báo cáo, số liệu, script và giàn đo đang dùng. Lấy lại 27,3 GB, ổ còn 70%. Chi tiết: `plans/reports/disk-cleanup-261027-0820-d1-261019-old-shots-report.md`.
- **Cài thêm (owner duyệt 27/10 08:17):** image Docker `mcr.microsoft.com/playwright:v1.64.0-noble` (3,59 GB), dùng để đo giao diện trên WebKit (gần Safari) và Firefox. Không cài gói hệ thống nào lên host. Gỡ: `docker rmi mcr.microsoft.com/playwright:v1.64.0-noble`.
- **Theo dõi:** `df -h /`. Thư mục đo mới cộng thêm khoảng 0,6–0,8 GB mỗi đợt; dọn định kỳ đồ đo cũ hơn 7 ngày.

### 2026-10-09 — Tắt dịch vụ in (đóng cổng 631); lệnh đo mức dùng và lệnh vào phòng

- **Dịch vụ in (08/10 22:17 +07, owner duyệt trong phiên):** bản snap `cups` (đi kèm Chromium dùng để kiểm màn hình) nghe cổng 631 trên mọi địa chỉ của máy; máy không in gì. Thao tác: `snap stop --disable cups`. Chọn tắt thay vì gỡ, vì gỡ thì snapd cài lại nó cho Chromium.
- **Hậu kiểm:** `ss -tlnH 'sport = :631'` không còn dòng nào; `snap services cups` báo cả hai dịch vụ `disabled` / `inactive` (xem lại 09/10 11:20: vẫn vậy); Chromium kiểm màn hình vẫn mở được; website prod vẫn trả lời. Quay lui: `snap start --enable cups`.
- **Theo dõi:** sau mỗi lần snap tự cập nhật, xem lại `snap services cups`.
- **Thêm trên host 09/10 (owner duyệt):**
  - `/root/.local/bin/cc-usage` — in mức dùng Claude Code theo ngày, theo dự án và theo phòng, đọc từ nhật ký hội thoại trên máy (chỉ đọc số token). Gỡ: xoá tệp.
  - `/root/.local/bin/claude-room` (lệnh `cc`): khi mở lại một phòng thì chọn hội thoại mới nhất mang tên phòng đó, để sau `/clear` không mở nhầm hội thoại cũ. Bản trước: `claude-room.bak-20261009` cùng thư mục.
  - `/root/.local/bin/claude-context-reminder` — nhắc khi hội thoại dài hoặc nghỉ lâu. **Chưa đăng ký** vào cấu hình: việc đăng ký do owner làm. Gỡ: xoá tệp.
  - Chỉ dẫn cấp người dùng của Claude Code (`~/.claude/CLAUDE.md`, `~/.claude/rules/model-routing.md`): thêm phần giữ hội thoại gọn và một dòng chọn mô hình. Bản trước: các tệp `.bak-20261009` cùng thư mục.

### 2026-10-08 — Nâng RAM 8 → 12 GB, khởi động lại máy lần đầu kể từ 17/07; công cụ dọn dẹp và lệnh vào phòng

- **Lý do:** từ khi có Chrome của designbot và trình duyệt kiểm màn hình, nhu cầu bộ nhớ lúc đỉnh lên khoảng 11 GB trên máy 8 GB (đêm 07/10 chỉ còn trống 127 MB, swap 2 GB đầy; ngày 08/10 dùng 3,5 GB swap). CPU không nghẽn (bận trung bình 6–20% mỗi ngày, mẫu 10 phút cao nhất 64%), giữ 6 nhân.
- **Nâng (owner, khoảng 17:00 +07):** RAM 8 → 12 GB tại nhà cung cấp, máy khởi động lại. Trước đó đã kiểm: 7 dịch vụ prod đặt `unless-stopped`, ảnh còn đủ, Docker bật cùng máy.
- **Hậu kiểm 17:06:** `free` 11.960 MB, swap 0 / 6 GB; 7 dịch vụ prod tự lên, 4 dịch vụ có kiểm sức khoẻ đều `healthy`; `/` → 200, `/lien-he` → 200, `/up` → 200; dịch vụ dev lên (`bsweb_dev_horizon` vẫn báo `unhealthy` như trước, do phép kiểm của nó). `/tmp` tự sạch khi khởi động: ổ từ 42 GB xuống 29 GB đã dùng.
- **Phát hiện về ổ:** trình duyệt kiểm màn hình (Chromium bản snap, chạy ẩn) để lại một hồ sơ tạm mỗi lần mở trong `/tmp/snap-private-tmp/snap.chromium/tmp/` — 12 GB sau 5 ngày, 3–5 GB mỗi ngày có kiểm. Luật dọn `/tmp` của hệ thống không tới được thư mục này (`/usr/lib/tmpfiles.d/snapd.conf` loại trừ nó).
- **Thêm trên host (owner duyệt 08/10):**
  - `/usr/local/sbin/bsweb-daily-housekeeping` — xoá hồ sơ tạm nói trên khi quá 2 ngày; lưu lịch sử các tệp chỉ dẫn của Claude Code (chỉ trên máy). **Chưa đặt lịch chạy**: việc đặt lịch do owner làm. Gỡ: xoá tệp này và dòng lịch tương ứng.
  - `/root/.local/bin/ccd` — lệnh vào phòng tmux của tài khoản `designbot`. Gỡ: xoá tệp.
  - `/root/.local/bin/claude-room` (lệnh `cc`): khớp đúng tên phòng tmux (`-t "=tên"`), và gõ lại lệnh mở Claude khi phòng còn mà shell của phòng không chạy gì. Bản trước: `claude-room.bak-20261008` cùng thư mục.
- **Theo dõi:** 12 GB chỉ dư khoảng 1 GB so với đỉnh đã đo; mỗi lúc chỉ một trình duyệt kiểm màn hình. Xét nâng 16 GB nếu trong 7 ngày swap dùng quá 1 GB hoặc bộ nhớ trống dưới 1,5 GB.

### 2026-10-07 — Thêm 4 GB swap (`/swapfile2`) vì tab Chrome của designbot bị tắt do hết bộ nhớ

- **Sự cố:** tab Claude Design của designbot hiện "Aw, Snap! Error code: 9". Nhật ký kernel ghi `Out of memory: Killed process … (chrome)`, UID 1000 = `designbot`. Hôm nay bị 5 lần: 00:40, 02:27, 05:23, 06:48, 23:24. RAM riêng của tab lúc bị tắt là 3,3–4,4 GB. Lúc đó swap 2 GB đã dùng 1,5 GB; dev, prod và các phiên Claude dùng thường trực khoảng 2 GB.
- **Thao tác:** `23:5x (+07)`, owner duyệt trong phiên ("thêm swap").
  - `fallocate -l 4G /swapfile2 && chmod 600 /swapfile2 && mkswap /swapfile2 && swapon /swapfile2`
  - Thêm dòng `/swapfile2 none swap sw 0 0` vào `/etc/fstab` (bản sao lưu `/etc/fstab.bak-20261007`), rồi `systemctl daemon-reload`.
  - Không `swapoff` tệp cũ, vì làm vậy sẽ đẩy 1,5 GB đang ở swap về RAM. Giữ `vm.swappiness=10`. Không khởi động lại dịch vụ nào.
- **Sau thao tác:** swap tổng 6 GB (`/swapfile` 2 GB + `/swapfile2` 4 GB); ổ `/` còn khoảng 60 GB trống.
- **Gỡ:** `swapoff /swapfile2 && rm /swapfile2`, rồi xóa dòng `/swapfile2` trong `/etc/fstab`.

### 2026-10-06 — Cài màn hình ảo + Google Chrome cho "designbot" (thử cho Claude Code điều khiển Claude Design)

- **Cài:** `2026-10-06 ~13:55 (+07)` — owner chạy `bash /root/designbot-setup.sh` (Claude Code soạn; lệnh cài trực tiếp của Claude Code bị bộ kiểm soát quyền chặn). Owner duyệt trong phiên: "cài tự động cho tôi mọi thứ phù hợp".
- **Gói:** 41 gói mới cho `xvfb openbox x11vnc fonts-noto-core fonts-noto-cjk` + `google-chrome-stable` 154.0.8037.97 và `fonts-liberation` từ nguồn apt Google (`/etc/apt/sources.list.d/google-chrome.list`, khóa `/etc/apt/keyrings/google-chrome.gpg`). **0 gói nâng cấp, 0 gỡ** (script tự dừng nếu có); bỏ `dbus-x11` vì nó kéo nâng cấp 7 gói dbus. `NEEDRESTART_SUSPEND=1` — không dịch vụ nào khởi động lại. ~684 MB đĩa.
- **Người dùng:** `designbot` (không sudo). Chrome + Claude Code (2.1.291, `~/.local/bin`) chạy dưới user này; `~/desk-start.sh` / `~/desk-stop.sh` bật/tắt Xvfb `:99` + openbox + x11vnc (chỉ `127.0.0.1:5901`, mật khẩu ở `/root/designbot-vnc-password.txt` 600) + Chrome (`~/chrome-profile`).
- **Hậu kiểm:** container prod giữ nguyên thời gian chạy (app 8 ngày, pg 2 tháng) — không restart; cổng 5901 chỉ nghe localhost.
- **Gỡ:** `apt-get remove google-chrome-stable xvfb openbox x11vnc fonts-noto-core fonts-noto-cjk && rm /etc/apt/sources.list.d/google-chrome.list /etc/apt/keyrings/google-chrome.gpg && userdel -r designbot` (không `autoremove` — chỉ đúng bộ đã cài).
- **Còn chưa biết:** tiện ích Claude in Chrome có thao tác được trên claude.ai không — thử sau khi owner đăng nhập qua VNC. Không chạy được thì gỡ theo dòng trên.

### 2026-10-05 — Deploy #161–#175 (sửa CMS + nút quản trị, gói và ghi đè tính năng theo website, 301 tên miền `private`)

- **Deploy:** `13:38 (+07)`, owner chạy. Prod `1b34c7c` → `57dc5ea` (28 commit).
  - Không có migration. Không đổi config, routes, dependency.
  - Dump: `prod-predeploy-1b34c7c-20261005-063832.sql.gz`.
- **Gồm:**
  - Sửa CMS và màn quản trị (#167–#174): nút viết dưới dạng component bấm được trở lại (Tắt 2 lớp, Ngắt MXH, Xóa ghi đè email) · thao tác hàng loạt chỉ chạm đúng các dòng của tab đang mở ("Xóa vĩnh viễn" không còn xóa bài đang hiển thị) · gửi duyệt cần quyền sửa chính bài đó, không đăng hay gửi duyệt từ Thùng rác, xét bài dưới khóa dòng · bộ hẹn giờ chỉ đăng bài còn đến hạn · bài bị trả về chỉ giao cho người gửi duyệt được · giờ hiển thị theo múi giờ website · component quản trị chỉ còn tiền tố `<x-bs-admin::…>`.
  - Tên miền (#162): 301 về tên chính trả `Cache-Control: private, max-age=3600` (trước là `public`).
  - Gói + ghi đè tính năng theo website, chỉ backend, chưa có màn (#163, #165, #175): lệnh `site:feature` và `platform:plan`; công tắc "Lời mời nâng gói" mặc định tắt.
  - Docs và gói prompt thiết kế (#161, #164, #166, #169).
- **Trước deploy:** CI của main xanh ở `57dc5ea`. QA cục bộ 1733 test.
- **Hậu kiểm:**
  - Script: `/` 200 · `/lien-he` 200 · `/admin/login` 200 · `/admin` 302 · qua Caddy `https://av1.baoson.net/` 200 · 0 dòng lỗi trong log 10 phút.
  - `https://giuonggapdep.com/lien-he` → 301 `https://av1.baoson.net/lien-he`; header `cache-control: max-age=3600, private`.
  - `site:feature av1.baoson.net` in đủ 6 tính năng, chưa có giá trị riêng nào; `platform:plan` in "Upgrade invitation: off", chưa có gói nào.
  - Chưa kiểm bằng tay trên trình duyệt: các nút đã sửa ở #172 và thao tác hàng loạt ở #173.

### 2026-10-03 — Deploy #158–#159 (giữ `www` chờ trỏ + báo khi tên miền gặp vấn đề)

- **Deploy:** `15:51 (+07)`, owner chạy. Prod `8089e28` → `1b34c7c`.
  - Migration `2033_01_01_000000_add_domain_notification_kinds` (8 ms): thêm `system.domain_problem` và `security.domain_taken_over` vào CHECK `site_notifications.kind`.
  - Dump: `prod-predeploy-8089e28-20261003-085125.sql.gz`.
- **Gồm:** #158 cách viết chưa trỏ được giữ khi cách viết kia đang chạy (trước đó bị xoá sau 7 ngày, không thêm lại được) · #159 người quản lý tên miền của website được báo bằng chuông + mail (hỏng, còn 7 ngày, đã ngừng, bị lấy lại; khỏi lại và dòng chờ bị xoá chỉ báo chuông) · #156 script thử tải báo rõ bước lỗi · #157 docs.
- **Trước deploy:** CI của main xanh ở `1b34c7c`. QA cục bộ 1642 test. #159 được rà soát độc lập (Opus, chỉ đọc), các lỗi tìm ra đã sửa trong cùng PR.
- **Hậu kiểm:**
  - Script: `/` 200 · `/lien-he` 200 · `/admin/login` 200 · `/admin` 302 · qua Caddy `https://av1.baoson.net/` 200 · 0 dòng lỗi trong log 10 phút.
  - CHECK mới có đủ 2 loại và đã VALIDATE; `event:list` có `TellSiteAboutDomain` (hàng đợi) nghe `DomainNeedsAttention`.
  - Website An Vinh có 1 người giữ quyền `domains.manage` (người sẽ nhận thông báo).
  - `giuonggapdep.com` và `www.giuonggapdep.com` vẫn 301 về `av1.baoson.net` giữ đường dẫn và tham số.

### 2026-10-03 — Deploy #155 (có `www` / không `www`: chỉ một cách viết chạy, cách kia chuyển 301) + hoàn tất `www.giuonggapdep.com`

- **Deploy:** `12:18 (+07)`, owner chạy. Prod `e32e138` → `8089e28`.
  - Migration `2032_01_01_000000_add_redirect_flag_to_domains` (11 ms): thêm `domains.is_redirect` + 2 CHECK; tự đánh dấu `www.giuonggapdep.com` là cách viết chuyển hướng.
  - Script tự restart PgBouncer sau migration; `PermissionRegistry` không đổi.
  - Dump: `prod-predeploy-e32e138-20261003-051837.sql.gz`.
- **Trước deploy:** CI của main xanh ở `8089e28`. QA cục bộ 1623 test. Bài thử tải Octane đạt (lần chạy đầu dừng ngay sau `migrate:fresh`; restart PgBouncer rồi chạy lại thì đạt — #156 làm script báo rõ bước nào lỗi).
- **Hậu kiểm (script):** `/` 200 · `/lien-he` 200 · `/admin/login` 200 · `/admin` 302 · qua Caddy `https://av1.baoson.net/` 200 · 0 dòng lỗi trong log 10 phút.
- **Hoàn tất `www` (`12:33`, owner chạy `site:domain … check` rồi `https`):**
  - Lệnh kiểm xác minh cả hai cách viết một lượt; lệnh HTTPS chỉ xin chứng chỉ cho `www` (tên gốc đã có).
  - Chứng chỉ Let's Encrypt cho `www.giuonggapdep.com` cấp ngay, hiệu lực 03/10/2026 → 01/01/2027; `ssl_status` = `active`.
  - `http://www.giuonggapdep.com/lien-he?a=1` → 308 (Caddy) → `https://www.giuonggapdep.com/lien-he?a=1` → **301 thẳng** `https://av1.baoson.net/lien-he?a=1` (trang công khai đi một bước tới tên chính của website), `Cache-Control: max-age=3600, public`, HSTS `max-age=2592000`.
  - `https://www.giuonggapdep.com/admin/login` → 301 `https://giuonggapdep.com/admin/login` (khu quản trị về cách viết đang chạy, không về tên chính).
- **Còn lại:** `giuonggapdep.com` vẫn là tên miền thử — gỡ bằng `site:domain av1.baoson.net remove giuonggapdep.com` khi không dùng nữa (gỡ cả hai cách viết).

### 2026-10-03 — Tên miền riêng đầu tiên chạy thật trên prod (`giuonggapdep.com`, gắn thử vào An Vinh)

- **Thao tác:** `00:47–00:48 (+07)`, owner chạy lệnh `site:domain` trên `bsweb_prod_app`: gắn → kiểm → bật HTTPS. Không đặt làm tên miền chính.
- **DNS của tên miền** (máy chủ DNS `dns1/dns2.baoson.net`): tên gốc → `103.82.27.129` (trùng `website.baoson.net`); `www` → CNAME `website.baoson.net`; không có CAA.
- **Kết quả (đọc từ prod):**
  - Xác minh tự động đạt ngay khi kiểm.
  - Chứng chỉ Let's Encrypt cấp qua Caddy on-demand trong 7 giây (job `IssueDomainCertificate` DONE), hiệu lực 02/10 → 31/12/2026. Không cần đặt `CUSTOM_DOMAIN_TLS_ENDPOINT`: container tự gọi được địa chỉ công khai của máy chủ.
  - `http://giuonggapdep.com/` → 308 sang HTTPS (Caddy) → 301 sang `https://av1.baoson.net/` giữ nguyên đường dẫn và tham số; `/admin/login` mở tại chỗ (200), không bị chuyển.
  - HSTS trên tên miền khách: `max-age=2592000`; trên `av1.baoson.net` giữ `max-age=31536000; includeSubDomains`.
  - Nhật ký có đủ 3 dòng `tenancy.domain.attached` / `verified` / `https_requested`. 0 dòng lỗi trong log app và Horizon.
- **Còn lại:** `www.giuonggapdep.com` đã gắn nhưng chưa kiểm + chưa bật HTTPS (mở bằng HTTPS sẽ lỗi bắt tay cho tới khi làm; để nguyên thì dòng chờ tự xoá sau 7 ngày). Đây là tên miền thử — gỡ bằng `site:domain av1.baoson.net remove giuonggapdep.com` khi không dùng nữa.

### 2026-10-02 — Deploy lô #142–#152 (backend tên miền riêng, chưa có màn)

- **Deploy:** `23:52 (+07)`, owner chạy. Prod `1dc066c` → `e32e138`. Gồm:
  - #143: migration `2030_01_01_000000_add_verification_and_https_state_to_domains` (21 ms) — thêm cột xác minh / HTTPS / cách trỏ, đánh dấu tên miền phụ nền tảng là đã xác minh, 3 index duy nhất.
  - #145: migration `2031_01_01_000000_let_sites_wait_for_the_same_domain` (2 ms) — bỏ ràng buộc duy nhất cũ trên `hostname`.
  - #144 một chỗ phân giải host → website (chỉ tên đã xác minh, có cache) · #146 bật HTTPS + tên miền chính + `domains:recheck` hằng ngày · #148 cổng quyền + nhật ký · #149 chuyển 301 về tên miền chính · #151 HSTS theo loại tên miền · #152 sửa sau rà soát độc lập · #147 sửa khoá Pest · còn lại là docs.
  - Script tự restart PgBouncer sau migration. `PermissionRegistry` không đổi nên không chạy `rbac:sync`.
  - Dump: `prod-predeploy-1dc066c-20261002-165243.sql.gz`.
- **Trước deploy:**
  - CI của main xanh ở `e32e138`.
  - QA cục bộ: 1603 test.
  - Bài thử tải Octane trên nhánh #149: 200 + 200 lượt xen kẽ hai site, không lộ dữ liệu.
  - Prod chỉ có một dòng `domains` (`av1.baoson.net`, tên miền phụ, tên chính); không website nào có hai tên chính.
- **Hậu kiểm (23:53):**
  - `/` 200 · `/lien-he` 200 · `/admin/login` 200 · `/admin` 302 · qua Caddy `https://av1.baoson.net/` 200 · 0 dòng lỗi trong log 10 phút.
  - `av1.baoson.net` đã được đánh dấu xác minh; đủ 3 index mới.
  - `/caddy/ask`: `av1.baoson.net` 200, tên chưa gắn 404. Host lạ 404.
  - HSTS trên `av1.baoson.net` giữ `max-age=31536000; includeSubDomains`; không có `X-Robots-Tag` (công tắc `NOINDEX_PLATFORM_SUBDOMAINS` chưa bật).
  - `domains:recheck` có trong lịch (00:00 hằng ngày theo giờ container).
- **Trạng thái tính năng:** ngủ. Chưa có màn; chỉ lệnh `site:domain` gắn được tên miền. Cấp chứng chỉ thật cho tên miền khách chưa từng chạy — lần gắn đầu tiên dùng một tên miền thử. `.env` prod chưa đặt `CUSTOM_DOMAIN_TARGET` (mặc định = `PLATFORM_HOST`).
- **Sự cố trên dev trong ngày:** hai lượt Pest chồng nhau treo 4 giờ (lượt chờ khoá chặn `CREATE INDEX CONCURRENTLY` của lượt đang chạy), chặn mọi lượt Pest trên máy. Owner dừng hai container; #147 sửa cách chờ khoá, đã kiểm lại bằng hai lượt chồng nhau.

### 2026-10-01 — Deploy lô #135–#137 (lõi đăng nhập dưới tên khách, chưa có nút ở console)

- **Deploy:** `16:12 (+07)`, owner chạy. Prod `ff75886` → `1dc066c`. Gồm:
  - #135: 2 migration chỉ thêm, đều xong dưới 5 ms. `2028_01_01_000000_add_impersonator_to_audit_logs` thêm 2 cột nullable. `2029_01_01_000000_add_support_sign_in_notification_kind`: CHECK `NOT VALID` → `VALIDATE`.
  - #136: kết nối hộp thư gửi của site cần quyền `settings.manage`.
  - #137: lõi đăng nhập dưới tên thành viên, thêm quyền `platform.sites.impersonate`.
  - Còn lại là commit docs.
  - Script deploy lần đầu tự restart PgBouncer sau migration và tự chạy `rbac:sync`: 36 quyền, 3 vai trò nền tảng.
  - Dump: `prod-predeploy-ff75886-20261001-091207.sql.gz`.
- **Trước deploy:**
  - CI của main xanh.
  - QA cục bộ trên mã đã gộp #136 + #137: 1436 test.
  - Bài thử tải Octane có thêm nhánh phiên hỗ trợ: 200 + 200 lượt xen kẽ hai site, không lộ dữ liệu.
- **Hậu kiểm:**
  - `/`, `/lien-he` (có ô đồng ý), `/admin/login` → 200; `/admin` → 302; qua Caddy → 200.
  - `audit_logs.impersonator_id/_label` có mặt; CHECK loại thông báo mới đã validate; quyền mới có trong `permissions`; 0 index INVALID.
  - Log app + horizon 0 lỗi, không có lỗi "cached plan".
  - Khách chưa thấy gì mới: chưa có nút cấp link ở console (PR3 chờ thiết kế D3-2/D4-1).

### 2026-09-30 — Deploy lô #124–#130 (thay đổi chờ duyệt, còn trơ) + siết cấu hình auto-mode

- **Deploy:** `15:30 (+07)` — prod `a1bc675` → `ff75886` (23 commit: gói prompt thiết kế + nguồn thiết kế #124/#130 · test #125 · C5 PR1–3 #126–#128 = bảng + action cho thay đổi chờ duyệt, chưa nối vào trình soạn · docs #123 #129). 2 migration chỉ thêm: `2026_01_01_000000_create_entry_drafts_table` (26 ms) · `2027_01_01_000000_add_target_to_workflow_log` (5 ms, DEFAULT hằng số + CHECK `NOT VALID` → `VALIDATE`). Dump: `prod-predeploy-a1bc675-20260930-083043.sql.gz`. Rà phản biện C5 trước deploy: 0 critical/high; lỗi medium M1 đã sửa ở `668d3b0`.
- **Hậu kiểm:** `/` `/lien-he` (có ô đồng ý) `/admin/login` → 200, `/admin` → 302, qua Caddy → 200; 30/30 địa chỉ sitemap → 200; `entry_drafts` bật RLS + FORCE, 1 policy; `workflow_log_target_check` đã validate; 0 index INVALID; log app + horizon 0 lỗi, `laravel.log` không có dòng mới.
- **Cấu hình auto-mode (owner, 13:41):** `~/.claude/settings.json` cấp user — thêm 3 luật soft_deny (đổi máy chủ chung · thao tác prod ngoài đọc · xoá sạch DB dev dùng chung) + 1 hard_deny (huỷ dữ liệu prod), viết lại 5 mục môi trường; sao lưu `settings.json.bak-automode-20260930-134106`.

### 2026-09-28 — Deploy 3 lần (Q14 · lịch sử phiên bản · index tra FK) + dọn phiên Claude nhàn rỗi

- **Máy chủ (sáng):** swap đầy (2042/2047 MB) vì 12 phiên Claude nhàn rỗi trong tmux (~3,2 GB, mỗi phiên kèm 1 container `boost:mcp`). Owner cho đóng hết, chỉ giữ phiên đang làm → RAM dùng 5,3 → 2,4 GB, swap 2,0 → 0,7 GB. Prod không bị đụng.
- **Deploy 1:** `10:30 (+07)` — prod `0cd245e` → `d9eded6` (6 commit: 5 tài liệu + module tính năng đọc qua `Tenancy\Context`), 0 migration. Dump: `prod-predeploy-0cd245e-20260928-033043.sql.gz`. Hậu kiểm: 30/30 địa chỉ trong sitemap → 200, log 0 lỗi.
- **Deploy 2:** `14:18 (+07)` — prod `d9eded6` → `405afac` (2 commit: lưu trang hẹn giờ/riêng tư #117 + lịch sử phiên bản #118). 1 migration `2023_01_01_000000_create_entry_revisions_table` (34 ms, chạy bằng `pgsql_admin`). Dump: `prod-predeploy-d9eded6-20260928-071835.sql.gz`. Dữ liệu: `cms:snapshot-published` ghi 20 bản gốc, khớp 20 bản dịch đang công khai (1 site, 0 lỗi).
- **Hậu kiểm deploy 2:** `/` `/lien-he` (có ô đồng ý) `/admin/login` → 200, `/admin` → 302, qua Caddy → 200; 30/30 địa chỉ sitemap → 200; log app + horizon 0 lỗi; `entry_revisions` bật RLS + FORCE; `cms:prune-revisions` chạy 00:00 hằng ngày.
- **Script deploy (bản cục bộ):** lệnh rollback chỉ gỡ ô đồng ý khi quay về code trước `bfaf682` — trước đó nó luôn gỡ, kể cả khi code đích vẫn hỗ trợ ô này (bắt buộc về pháp lý); thêm bước `cms:snapshot-published` khi lệnh có trong bản mới. Rollback về `d9eded6` không cần khôi phục dump (lô chỉ thêm bảng).
- **Deploy 3:** `23:47 (+07)` — prod `405afac` → `a1bc675` (5 commit: ops-log #119 · index tra FK #120 · bỏ index trùng #121 · Pint + rules #122). 2 migration chỉ đụng index, chạy bằng `pgsql_admin`: `2024_01_01_000000_add_foreign_key_lookup_indexes` (34,69 ms — 15 index `CONCURRENTLY`, 8 partial) · `2025_01_01_000000_drop_duplicate_prefix_indexes` (6,55 ms — bỏ 4 index trùng tiền tố). Dump: `prod-predeploy-405afac-20260928-164724.sql.gz`. Lệnh dữ liệu idempotent: 0 thay đổi.
- **Hậu kiểm deploy 3:** `/` `/lien-he` (có ô đồng ý) `/admin/login` → 200, `/admin` → 302, qua Caddy → 200; 15/15 index mới hợp lệ, 0 index trùng còn lại, 0 index INVALID, 2 migration ở batch 15; 30/30 địa chỉ sitemap → 200; log container app/horizon/scheduler 0 dòng lỗi. Rollback chỉ cần code (index mới vô hại; 4 index đã bỏ vốn thừa) — không cần dump.
- **Log app prod:** kênh `stack → single` = `storage/logs/laravel.log` (app ghi được); dòng cuối 03/08 ⇒ 8 tuần không có warning/lỗi. Hậu kiểm log xem cả file này lẫn `docker logs`.

### 2026-09-27 — Deploy lô #94–#113 + diễn tập khôi phục lần đầu

- **Deploy:** `23:29 (+07)` — owner chạy script deploy có ghim SHA: prod `9daf094` → `0cd245e` (33 commit, 0 migration, không đổi dependency). Dump trước deploy: `/opt/bswebsaas/db-backups/prod-predeploy-9daf094-20260927-162912.sql.gz`. Dữ liệu: ô đồng ý thêm vào 1 form liên hệ (`forms:sync-contact-fields`), 7 chuyên mục được ghi `path` (`cms:rebuild-taxonomy-paths`).
- **Hậu kiểm:** `/`→200, `/lien-he`→200 (có ô đồng ý), `/tin-tuc`→200, `/admin`→302, `/admin/login`→200; log app 0 dòng lỗi; Horizon chạy.
- **Lần preflight đầu (23:22) dừng:** tra CI của commit (`gh run list --commit`) trả rỗng dù CI đã xanh; cùng lệnh chạy lại vài phút sau ra `completed:success`. Nguyên nhân chưa xác định (không tái hiện được); prod không bị đụng. Script deploy giờ in lỗi thật của `gh` và thử lại 3 lần.
- **Diễn tập khôi phục lần đầu:** `23:30 (+07)` — owner chạy tay `scripts/restore-drill-prod-db.sh` trên bản prod: đạt (chi tiết: `runbooks/prod-db-backup-restore.md` §9). Log `/var/log/bsweb-restore-drill.log` (600).
- **Crontab (owner, 23:4x):** backup `15 3 * * *` chạy từ `/opt/bswebsaas/prod/scripts/` (đọc `.env` prod — đủ 6 biến bắt buộc) + diễn tập `45 4 1 * *` → `/var/log/bsweb-restore-drill.log` (runbook §3); dòng cũ chạy script từ `dev/` đã bỏ.
- **Tạo lại container (owner, 23:5x):** `docker compose up -d` trong bản prod — app/horizon/scheduler chạy `bsweb/app:prod` (`e00aba…`, PHP 8.5.7), PgBouncer theo digest; valkey/pg/caddy không đổi. Hậu kiểm: app + horizon healthy, 0 restart; `/` `/lien-he` (có ô đồng ý) `/tin-tuc` `/admin/login` → 200, `/admin` → 302; log 0 lỗi. Build cũ `da1e043…` đã không còn trong kho image từ trước (không giữ được làm đường lùi); đường lùi = `bsweb/app:prod` hiện tại, cùng runtime đã đo.
- **Giám sát (owner):** healthchecks.io 2 check (backup đêm `15 3 * * *` grace 2h · diễn tập `45 4 1 * *` grace 1 ngày, múi `Asia/Ho_Chi_Minh`), URL trong `.env` prod (`BACKUP_`/`RESTORE_HEALTHCHECK_URL`, script host tự đọc); diễn tập chạy lại 23:52 để thử ping — đạt, không có cảnh báo ping. UptimeRobot cho av1.baoson.net + website.baoson.net.
- **Backup đêm đầu từ bản prod (28/09 03:15 +07):** upload `db-bswebsaas-20260927-201501.sql.gz`, prune giữ 12 / xoá 2 (bản 23/08 quá 35 ngày; bản 19/09 là bản thừa trong tuần ISO của 20/09 — đúng chính sách §5), không có cảnh báo ping healthcheck.

### 2026-09-25 — Cài rồi gỡ ImageMagick (công cụ đo một lần cho PR #92)

- **Cài:** `2026-09-25 11:48 (+07)` — `apt-get install -y imagemagick` trên host `103.82.27.129`, kéo theo tổng **38 gói** (1 chính + 37 phụ thuộc).
- **Lý do:** pixel-diff before/after cho PR #92 (AA màu + focus) — dùng `compare` / `identify` / `convert` để chứng minh thay đổi navy→accent KHÔNG đổi light-mode. Công cụ đo một lần, **không** phải phụ thuộc của app (app chạy trong container, không có extension `imagick`; composer/config không tham chiếu ImageMagick).
- **Gỡ:** `2026-09-25 17:16 (+07)` — `apt-get remove -y <38 gói>` (KHÔNG `autoremove`, chỉ đúng bộ đã cài). Mô phỏng `apt-get -s remove` + thực tế đều gỡ đúng 38 gói: **docker-ce không bị đụng** (bản đang cài không phụ thuộc `libltdl7`; tên xuất hiện trong `apt-cache rdepends` chỉ là cross-version artifact), `fontconfig-config` vẫn thoả nhờ `fonts-dejavu-core` đã cài. Giải phóng ~99.9 MB.
- **Hậu kiểm:** dpkg còn 0/38 gói; `convert`/`compare`/`identify` biến mất; docker daemon + prod containers Up/healthy; `/`→200, `/lien-he`→200, `/admin`→302, `/admin/login`→200; app/horizon 0 lỗi.
- **38 gói:** `fonts-droid-fallback`, `fonts-noto-mono`, `fonts-urw-base35`, `ghostscript`, `imagemagick`, `imagemagick-6-common`, `imagemagick-6.q16`, `libdjvulibre-text`, `libdjvulibre21`, `libfftw3-double3`, `libfontenc1`, `libgs-common`, `libgs10`, `libgs10-common`, `libidn12`, `libijs-0.35`, `libimath-3-1-29t64`, `libjbig2dec0`, `libjxr-tools`, `libjxr0t64`, `liblqr-1-0`, `libltdl7`, `libmagickcore-6.q16-7-extra`, `libmagickcore-6.q16-7t64`, `libmagickwand-6.q16-7t64`, `libnetpbm11t64`, `libopenexr-3-1-30`, `libopenjp2-7`, `libpaper-utils`, `libpaper1`, `libraw23t64`, `libwebpdemux2`, `libwebpmux3`, `libwmflite-0.2-7`, `netpbm`, `poppler-data`, `xfonts-encodings`, `xfonts-utils`.
- **Rút ra → `CLAUDE.md` §7 (Host safety):** không cài/gỡ gói hệ thống trên host chung khi chưa được owner duyệt; công cụ đo phải nằm trong repo hoặc container.
