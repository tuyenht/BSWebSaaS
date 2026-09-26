# Rào chắn Claude Code trên máy chủ chung dev + prod

> Máy `103.82.27.129` chạy cả dev (`/opt/bswebsaas/dev`, project `bsweb-dev`) lẫn prod
> (`/opt/bswebsaas/prod`, project `bsweb-prod`), và Claude Code đang chạy bằng **root**.
> Rào chắn này đặt ở **cấp máy** để một sai sót hoặc một prompt injection không chạm được tới prod,
> bí mật, hay cấu hình máy. Quy tắc tương ứng cho con người: `CLAUDE.md` §7.1.

## ⚠️ Giới hạn — đọc trước

Rào chắn này **chặn SAI SÓT và phần lớn prompt injection**. Nó **KHÔNG chặn được một tiến trình root
cố ý lách**: ví dụ tự viết một script rồi chạy nó, giấu lệnh trong file `.py`/`.php`, mã hoá base64,
hay gọi thẳng syscall. Chính tài liệu Claude Code cũng nói luật `Bash(...)` "không phải ranh giới bảo
mật". Chừng nào Claude còn chạy bằng root, root vẫn là root.

Biện pháp triệt để là **Giai đoạn 1: chạy Claude Code bằng user không phải root**, với dev tách khỏi
prod (xem kế hoạch trong `plans/`). Rào chắn này là lớp bảo vệ cho tới lúc đó, và vẫn có ích sau đó.

## Cơ chế (Claude Code 2.1.282)

| Lớp | Ở đâu | Làm gì |
|---|---|---|
| 1 · hook `PreToolUse` (chính) | `/etc/claude-code/hooks/host_guard.py` | Xét mọi lời gọi Bash, Read, Edit, Write, MultiEdit, NotebookEdit, Glob, Grep, kể cả trong subagent. Exit 2 = chặn, kèm lý do. |
| 2 · `permissions.deny` (phụ) | `/etc/claude-code/managed-settings.json` | `Read()`/`Edit()` cho công cụ file (Read phủ Glob/Grep, Edit phủ Write/MultiEdit/NotebookEdit) và `Bash()` cho các dạng lệnh hay gặp. |

- **Managed settings đứng trên mọi cấp**: user, project, local hay `--settings` đều không gỡ được luật
  deny hoặc hook của managed settings; `disableAllHooks` đặt ở cấp user cũng không tắt được hook managed.
  Áp dụng cho mọi thư mục và mọi worktree.
- **Fail-closed**: nếu hook gặp lỗi bất kỳ thì nó chặn; lệnh cài đặt còn nối thêm `|| exit 2`, nên
  thiếu `python3` hay mất file hook cũng dẫn tới chặn. Hệ quả: nếu file hook hỏng, Claude sẽ **không
  dùng được công cụ nào** cho tới khi owner sửa qua SSH. Đó là chủ ý.
- **Không** bật `allowManagedHooksOnly`, để các hook ClaudeKit ở `~/.claude` vẫn chạy.
- Lệnh owner gõ bằng `!`, hoặc chạy trong phiên SSH riêng, không đi qua công cụ của Claude. Đổi luật
  luôn làm theo đường đó.

## Chặn gì

| Luật | Chặn | Ví dụ lách vẫn bị chặn |
|---|---|---|
| `pkg` | apt/apt-get/aptitude/dpkg/snap (trừ lệnh chỉ đọc như `apt list`, `apt-cache`, `dpkg -l`, `apt-get -s`); `pip install` ngoài venv; npm/pnpm/yarn/bun `-g`; curl\|sh, wget\|bash, `bash <(curl …)`, `sh -c "$(curl …)"` | `sudo …`, `/usr/bin/apt-get`, `bash -c '…'`, `X=apt-get; $X install`, symlink đổi tên binary, heredoc đưa vào `bash`, gọi qua `os.system` |
| `prod-write` | ghi/xoá/chmod/chown/sed -i/ln/git làm thay đổi trong `/opt/bswebsaas/prod` (git chỉ được lệnh chỉ đọc); chạy lệnh không chỉ-đọc khi cwd nằm trong prod | đường dẫn tương đối (`../prod`, `cd prod && …`), symlink tới prod, biến `P=…; rm $P/x`, `find -delete/-exec`, vòng `for` |
| `secret-read` | đọc `.env*` của prod, các dump/sao lưu DB (`db-backups/`, `*.sql*`, `*.tgz` ở `/opt/bswebsaas`), `storage/app/private`; `.env` của dev và worktree; đọc đệ quy cả cây prod (grep -r, tar, rsync, cp -r); gửi file ra ngoài (`curl -d @file`); in môi trường của container dev (`printenv TOKEN`, `env`, `/proc/*/environ`, tinker `env()`) | glob `pro?/.e*`, symlink, `$(find …)`, `find … \| xargs cat`, `F=…; cat $F`, `--env-file` của prod |
| `docker-prod` | mọi lệnh docker/compose vào project `bsweb-prod` hoặc container `bsweb_prod_*`, **trừ** `ps`, `logs`, `inspect`. Riêng `inspect` container thì phải có `--format` và không lộ biến môi trường. Cấm mount prod hoặc `/` vào container, cấm `prune`, cấm thao tác hàng loạt trên danh sách container tính ra bằng lệnh | `COMPOSE_PROJECT_NAME=bsweb-prod`, cwd trong prod, `-f` trỏ vào file compose của prod (kể cả qua symlink), `$(docker ps -q)`, `xargs docker rm` |
| `sysadmin` | crontab, systemctl, service, useradd/usermod/passwd, visudo, iptables/nft/ufw, at; ghi `/etc/crontab`, `/etc/cron.*`, `/etc/sudoers*`, `/etc/systemd`, `/etc/apt`, `/root/.ssh` | `sudo`, đường dẫn tuyệt đối, `bash -c` |
| `rules` | sửa/xoá `/etc/claude-code/**`, mọi `.claude/settings*.json`, mọi `.claude/hooks/**` | cwd tương đối, symlink, biến, `python -c` có ghi file |
| `deploy-script` | Claude chạy `scripts/deploy/prod-deploy.sh` hoặc `prod-rollback.sh` (chỉ owner chạy, qua `!`) | `bash …`, `./…`, `sudo`, biến, `bash -c` |

**Vẫn cho qua** (có test): `docker compose -p bsweb-dev …`, Pest/PHPStan trong container dev, công cụ
#88 (`RenderAdminPreviewTest` + `scripts/admin-preview/*.mjs`), `pnpm install/build` trong repo dev,
git và gh, `docker compose -p bsweb-prod logs`/`ps`, `docker logs bsweb_prod_*`, đọc file thường
trong prod (không phải bí mật), `git -C prod log/status`, `ls/stat` ngay cả trên file bí mật (chỉ
metadata). Worktree vẫn được `cp`/`ln -s` `dev/.env` sang và mount `-v dev/.env:/app/.env` cho
container test: bí mật được chuyển chỗ nhưng không bị đọc ra.

## `.env` của dev

Script sao lưu DB prod đọc `CLOUDFLARE_API_TOKEN` từ `dev/.env`, và token này mở được bucket R2 chứa
các bản sao lưu DB prod. Vì vậy `dev/.env` được bảo vệ như bí mật của prod. Tuy nhiên, **container app
dev nạp sẵn các biến này vào môi trường của nó** (`env_file: .env`), nên rào chắn chỉ chặn được những
cách in ra lộ liễu. **Khuyến nghị:** tách khoá backup ra một file riêng chỉ root đọc được, ví dụ
`/etc/bsweb/backup.env` mode 600, rồi cho `backup-prod-db.sh` đọc từ đó. Việc này thuộc đợt sau.

## Khi Claude bị chặn nhầm

| Bị chặn | Cách làm đúng |
|---|---|
| `git fetch` trong prod để xem delta | `git -C /opt/bswebsaas/dev fetch && git -C /opt/bswebsaas/dev diff <sha-prod> origin/main` |
| `printenv`/`env` trong container | `php artisan config:show <key>`, hoặc `printenv TEN_BIEN_KHONG_BI_MAT` |
| lệnh vận hành prod (tinker, migrate, reload, deploy) | owner chạy qua `!`, hoặc dùng `scripts/deploy/prod-*.sh` |
| script Python viết qua heredoc có nhắc `/etc/claude-code` (khi sửa chính mã nguồn guard) | sửa bằng công cụ Edit/Write |

Mỗi lần chặn đều in rõ `host-guard BLOCKED [luật]: lý do`. Nếu thấy chặn nhầm lặp lại, hãy thêm một
ca vào `ALLOWED` trong test, sửa hook, rồi cài lại (mục dưới).

## Cài đặt / cập nhật / gỡ — chỉ owner (qua `!` hoặc SSH)

Bản gốc nằm trong repo ở `.claude/guard/`. Luôn cài từ một **commit đã review**, không cài từ file đang sửa dở:

```bash
REF=<commit-hoặc-nhánh-đã-review>; REPO=/opt/bswebsaas/dev
install -d -m 755 /etc/claude-code/hooks
git -C $REPO show $REF:.claude/guard/host_guard.py > /etc/claude-code/hooks/host_guard.py
git -C $REPO show $REF:.claude/guard/managed-settings.json > /etc/claude-code/managed-settings.json
chmod 644 /etc/claude-code/hooks/host_guard.py /etc/claude-code/managed-settings.json
python3 -m json.tool /etc/claude-code/managed-settings.json >/dev/null && sha256sum /etc/claude-code/managed-settings.json /etc/claude-code/hooks/host_guard.py
```

- Có hiệu lực từ **phiên Claude Code mới**. Phiên đang mở thì cần `/exit` rồi mở lại.
- **Kiểm sau khi cài** (trong phiên Claude mới): `crontab -l`, `docker top bsweb_prod_app` và
  `Read /opt/bswebsaas/prod/.env.example` phải bị chặn; `docker compose -p bsweb-dev ps` và
  `git status` phải chạy được. Owner thử thêm `! crontab -l | head -1`: lệnh này phải chạy được,
  nghĩa là đường `!` không bị rào.
- **Kiểm độ lệch** giữa bản cài và repo:
  `diff <(git -C $REPO show origin/main:.claude/guard/host_guard.py) /etc/claude-code/hooks/host_guard.py`.
- **Gỡ** (khẩn cấp): `rm -rf /etc/claude-code`, rồi mở phiên mới.

## Test

`python3 .claude/guard/test_host_guard.py -v`, chạy trong job CI `ops-guards`. Bộ test chạy entrypoint
thật của hook trên:
- mọi luật chặn, mỗi luật có ca lách (đường dẫn tương đối, symlink, `bash -c`, biến môi trường, glob,
  subshell, xargs, heredoc);
- mọi lệnh thường ngày ở trên (phải được cho qua).

Hook còn được thử bằng cách chạy lại **4 583 lời gọi công cụ thật** trong transcript của dự án
(26/09/2026). 199 lời gọi bị chặn, tất cả đúng chính sách: 140 lệnh docker vào prod (có cả tinker
`update` DB prod), 20 lệnh git fetch/merge trong prod, `apt-get install`, sửa settings, đọc `.env`.
Ngoại lệ duy nhất là heredoc sửa chính mã nguồn guard, đã ghi ở bảng trên.

## Tham chiếu

- Claude Code docs: managed settings (`/etc/claude-code/managed-settings.json`, có `managed-settings.d/`),
  permissions (`//` = đường dẫn tuyệt đối; chỉ `Read()`/`Edit()` được xét cho công cụ file), hooks
  (`PreToolUse`, exit 2 = chặn; hook lỗi thì không chặn, nên phải bọc `|| exit 2`):
  https://code.claude.com/docs/en/settings · /permissions · /hooks · /sandboxing
