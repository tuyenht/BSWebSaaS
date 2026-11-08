#!/usr/bin/env bash
# Shared prod deploy engine: one reviewed engine + one small release file per deploy.
#
#   bash scripts/deploy/deploy.sh plan <release file> [commit]   # git only, never touches prod: prints the plan + the owner's line
#   bash scripts/deploy/deploy.sh classify <git dir> <old> <new> # the path → class table (tests, review)
#   run …                                                         # only through launch.sh (the owner's tmux line)
#
# The owner's tmux line runs launch.sh from a pinned commit; launch.sh copies this file out of that commit,
# checks its blob hash and the release file's sha256 (both written in the line), then runs the copy:
#   run <release copy> <engine blob> <release sha256> <commit> [step | dump | rollback]
# Steps (the order of the clean run of 05/11, 58 s): 0 checks · 1 delta · 2 dump · 3 read-only checks ·
# 4 migrate (old code still serves) · 5 code + composer · 6 build · 7 caches + reload + one-off commands ·
# 8 smoke. Rollback moves the code only (migrations must be expand-only; a non-additive one needs review).
# Every changed path must belong to a known class (scripts/deploy/README.md); an unknown path, a server
# file, or an edited or deleted migration stops. Classes that need a human look (migrations, schedule,
# dependencies, permissions, sanitizer, content packs, backup scripts) must be declared in the release file.
# The first run records OLD, TARGET, the engine blob and the release sha256; a resume or rollback reads only
# that record and refuses a different engine or release. Every step checks its own result and stops on the
# first surprise, also on Ctrl-C, TERM or a closed tmux pane. The output goes to a 0600 log; no secret is
# printed (prod .env is read for key names only).

# ---------------------------------------------------------------- profile: one place ties prod path ⇔ prefix
PROD_PATH=/opt/bswebsaas/prod
PROD_PREFIX=bsweb_prod_
load_profile() {
    if [ -z "${DEPLOY_PROFILE:-}" ]; then
        PROD=$PROD_PATH PREFIX=$PROD_PREFIX COMPOSE_PROJECT=bsweb-prod
        BK=/opt/bswebsaas/db-backups SECRETS=/opt/bswebsaas/secrets/db-admin.env
        DEPLOY_RUNS=/opt/bswebsaas/dev/plans/reports/deploy-runs
        REPO=tuyenht/BSWebSaaS IMAGE=bsweb/app:prod MIN_DISK_GB=5 LOCK_RETRY_SLEEP=20
    else
        # a test profile: its own prefix, its own checkout, stub commands only
        # shellcheck disable=SC1090
        . "$DEPLOY_PROFILE"
        [ "$PREFIX" != "$PROD_PREFIX" ] || die "hồ sơ thử dùng tiền tố container của prod"
        case "$(readlink -m "$PROD")/" in "$PROD_PATH"/*) die "hồ sơ thử trỏ vào thư mục prod" ;; esac
        case "$COMPOSE_PROJECT" in bsweb-prod*) die "hồ sơ thử dùng tên compose của prod" ;; esac
        [ -n "${STUB_BIN:-}" ] && [ "$(command -v docker)" = "$STUB_BIN/docker" ] \
            || die "hồ sơ thử chỉ chạy với lệnh docker giả ($STUB_BIN/docker)"
    fi
    [ "$PROD" != "$PROD_PATH" ] || [ "$PREFIX" = "$PROD_PREFIX" ] || die "thư mục prod đi với tiền tố khác $PROD_PREFIX"
}

# ---------------------------------------------------------------- output, stops, traps
MODE= CUR=- MIXED= RESUME= SCHEMA= MIGRATING= TICK= LOG= SELF= STATE= TS=
die() { # to stderr, so a stop inside "$(…)" shows; the main shell prints the full stop once
    if [ "${BASH_SUBSHELL:-0}" -gt 0 ]; then printf '!!!!! %s\n' "$*" >&2; exit 1; fi
    trap '' INT TERM HUP; trap - ERR
    tick_stop
    printf '\n!!!!! DỪNG (bước %s): %s\n' "$CUR" "$*" >&2
    if [ "$MODE" = run ]; then
        printf '!!!!! Chưa làm các bước sau. Nhắn AI: "xem log %s"\n' "$LOG" >&2
        [ -z "$MIXED" ] || printf '!!!!! Code trên đĩa đã đổi: website có thể đang lỗi trang hoặc lệch giao diện. Nhắn AI ngay; AI sẽ bảo chạy lại từ một bước hoặc "rollback".\n' >&2
        [ "$CUR" = - ] || printf '!!!!! Khi AI bảo chạy tiếp: chạy lại dòng tmux, thêm "%s" ở cuối (các bước trước đã xong).\n' "${RESUME:-$CUR}" >&2
        [ -z "$MIGRATING" ] || printf '!!!!! Dừng trong lúc migrate: đừng chạy lại ngay, lệnh migrate có thể vẫn chạy trong container. Nhắn AI trước.\n' >&2
        if [ -n "$SCHEMA" ]; then set +e; schema_stop; fi
    fi
    exit 1
}
schema_stop() { # a stop while a flagged migration may have committed part of its change: drop the stale plans first
    local i c=
    if [ -n "$MIGRATING" ]; then
        printf '!!!!! Chờ container migrate dừng hẳn (tối đa 60 giây)...\n' >&2
        for i in $(seq 1 30); do [ -z "$(docker ps -q --filter "name=$COMPOSE_PROJECT-migrate-run")" ] && break; sleep 2; done
        [ -z "$(docker ps -q --filter "name=$COMPOSE_PROJECT-migrate-run")" ] || printf '!!!!! Container migrate vẫn chạy sau 60 giây: nhắn AI ngay, đừng chạy lại lệnh.\n' >&2
    fi
    printf '!!!!! Khởi động lại PgBouncer (khoảng 3 giây) để code cũ chạy được trên cấu trúc bảng có thể đã đổi...\n' >&2
    if docker restart -t 2 "${PREFIX}pgbouncer" >&2; then
        for i in $(seq 1 15); do c=$(code / '%{http_code}' || true); [ "$c" = 200 ] && break; sleep 2; done
        printf '!!!!! Code cũ vẫn phục vụ website (trang chủ trả %s). Migration chạy lại an toàn từ đầu tệp đang dở.\n' "${c:-?}" >&2
    else
        printf '!!!!! KHÔNG khởi động lại được PgBouncer: chạy ngay docker restart %spgbouncer, rồi nhắn AI.\n' "$PREFIX" >&2
    fi
}
joinw() { sed '/^$/d' | paste -sd' ' -; }   # non-empty lines -> one space-separated line
expect() { if [ "$2" = "$3" ]; then printf 'OK   %s = %s\n' "$1" "$2"; else die "$1: được '$2', cần '$3'"; fi; }
note() { printf '     %s\n' "$*"; }

# ---------------------------------------------------------------- prod access (all names come from PREFIX)
plan_guard() { # plan mode is read-only by construction: these die before anything reaches a container or GitHub
    docker() { die "chế độ kế hoạch không được gọi docker ($*)"; }
    gh() { die "chế độ kế hoạch không được gọi gh"; }
    ssh() { die "chế độ kế hoạch không được gọi ssh"; }
    psql() { die "chế độ kế hoạch không được gọi psql"; }
    pnpm() { die "chế độ kế hoạch không được gọi pnpm"; }
}
art() { docker exec "${PREFIX}app" php artisan "$@"; }
code() { docker exec "${PREFIX}app" curl -s -m 10 -o /dev/null -w "$2" -H "Host: $HOST" "http://localhost:80$1"; }
page() { docker exec "${PREFIX}app" curl -s -m 10 -H "Host: $HOST" "http://localhost:80$1"; }
has() { local h; h=$(page "$1") || die "không gọi được trang $1"; printf '%s\n' "$h" | grep -cF -- "$2" || true; }
q() { # read-only SQL as the DB owner over the container's local socket; one value per line
    docker exec -e Q="$1" "${PREFIX}pg" sh -c 'psql -U "$POSTGRES_USER" -d bswebsaas -X -q -tA -v ON_ERROR_STOP=1 -c "SET default_transaction_read_only = on" -c "$Q"'
}
ops_mig() { # the superuser one-off container, with exactly the staged migration file(s) mounted read-only
    ( cd "$PROD" && docker compose --profile ops run --rm --no-deps -v "$MIGDIR:/deploy-migrations:ro" migrate "$@" )
}
ops() { ( cd "$PROD" && docker compose --profile ops run --rm --no-deps migrate "$@" ); }
pgb_drop() { # PgBouncer closes its server connections and the plans prepared on them (runbook Deploy 6).
    # -t 2: PgBouncer waits for its clients on SIGTERM and Octane workers never leave (10.3 s on prod, 04/11)
    echo "Khởi động lại PgBouncer: bỏ truy vấn đã chuẩn bị theo cấu trúc bảng cũ (khoảng 3 giây)"
    docker restart -t 2 "${PREFIX}pgbouncer"
    SCHEMA=
}
tick_start() { # a line a minute while migrate runs: a long CREATE INDEX CONCURRENTLY wait must not look like a hang
    ( exec 9>&-; trap - ERR INT TERM HUP; set +e; n=0   # 9 is the deploy lock: a sleeping child must not hold it
      while sleep 60 >/dev/null 2>&1; do
          n=$((n + 1))
          w=$(q "SELECT count(*) FILTER (WHERE wait_event_type = 'Lock')||' phiên chờ khóa, '||count(*) FILTER (WHERE state LIKE 'idle in transaction%')||' phiên idle in transaction' FROM pg_stat_activity" 2>&1 | tail -n 1)
          printf '...  migrate vẫn đang chạy (%s phút; %s). Chỉ mục CONCURRENTLY đợi các giao dịch đang mở xong rồi tự đi tiếp: cứ để chạy. Quá 15 phút thì nhắn AI.\n' "$n" "$w"
      done ) &
    TICK=$!
}
tick_stop() { if [ -n "${TICK:-}" ]; then kill "$TICK" 2>/dev/null || true; TICK=; fi; }
wait_up() { # postgres answers, the app runs PHP, the home page serves through PgBouncer
    local ok=
    for _ in $(seq 1 60); do
        if docker exec "${PREFIX}pg" pg_isready -q && docker exec "${PREFIX}app" php -r 'echo 1;' >/dev/null 2>&1; then ok=1; break; fi
        sleep 2
    done
    [ -n "$ok" ] || die "container chưa lên sau 120 giây"
    for _ in $(seq 1 30); do
        if [ "$(code / '%{http_code}' || true)" = 200 ]; then echo "OK   container và trang chủ sẵn sàng"; return 0; fi
        sleep 2
    done
    die "trang chủ chưa trả 200 sau 60 giây"
}

# ---------------------------------------------------------------- classification (pure git: plan and run share it)
# class_of <status> <path> → one class. Kinds: auto (no declaration), declare (must be in DECLARED), stop.
class_of() {
    local s=$1 p=$2
    case "$p" in
        database/migrations/*.php) if [ "$s" = A ]; then echo migration; else echo migration-edit; fi ;;
        database/content-packs/*) echo content-pack ;;
        database/seeders/*|database/factories/*) echo code ;;
        database/data/*) echo data ;;
        composer.lock) echo composer-lock ;;
        composer.json) echo composer ;;
        package.json|pnpm-lock.yaml|pnpm-workspace.yaml|.npmrc) echo npm ;;
        vite.config.js|resources/*) echo assets ;;
        routes/console.php) echo schedule ;;
        routes/*.php) echo routes ;;
        config/octane.php) echo server ;;
        config/horizon.php) echo queue ;;
        config/*.php|bootstrap/app.php|bootstrap/providers.php) echo config ;;
        .env.example) echo env-doc ;;
        docker/*|deploy/*|docker-compose*.yml|Dockerfile*|*/Dockerfile*|Caddyfile*|artisan|public/index.php|.dockerignore) echo server ;;
        app/*/PermissionRegistry.php) echo permission ;;
        app/*/RichTextSanitizer.php) echo sanitizer ;;
        app/*/resources/views/*|themes/*) echo view ;;
        app/*.php) echo code ;;
        lang/*.json|lang/*.php|lang/*/*.php) echo text ;;
        public/*) echo public ;;
        scripts/backup-prod-db.sh|scripts/restore-drill-prod-db.sh|scripts/r2_prune_backups.py) echo ops-script ;;
        scripts/*|tests/*|docs/*|plans/*|.github/*|.claude/*|*.md|LICENSE|phpstan.neon|phpunit.xml|pint.json|rector.php|boost.json|.mcp.json|.lighthouserc.cjs|.gitignore|.gitattributes|.editorconfig|storage/*.gitignore|*/.gitkeep|*/.gitignore) echo tooling ;;
        *) echo unknown ;;
    esac
}
kind_of() {
    case "$1" in
        migration|schedule|content-pack|data|composer-lock|npm|permission|sanitizer|ops-script) echo declare ;;
        migration-edit|server|unknown) echo stop ;;
        *) echo auto ;;
    esac
}
classify() { # classify <git dir> <old> <new>: "class<TAB>status<TAB>path" per changed path (renames = delete + add)
    local s p
    git -C "$1" diff --no-renames --name-status "$2" "$3" | while IFS=$'\t' read -r s p; do
        printf '%s\t%s\t%s\n' "$(class_of "$s" "$p")" "$s" "$p"
    done
}
mig_scan() { # mig_scan <git dir> <commit> <name>: the file without its down() body, for the flag patterns
    git -C "$1" show "$2:database/migrations/$3.php" | awk '
        /function down\(/ { skip = 1; next }
        skip && /^    (public|protected|private) function / { skip = 0 }
        /^[[:space:]]*(\*|\/\*|\/\/|#)/ { next }
        !skip { print }'
}
mig_flags() { # mig_flags <git dir> <commit> <name> → space list of: raw nonadd table concurrent
    local t f=
    t=$(mig_scan "$@")
    printf '%s' "$t" | grep -qE 'DB::(statement|unprepared|raw)|->(statement|unprepared)\(' && f="$f raw"
    printf '%s' "$t" | grep -qiE -e '->change\(|->drop[A-Za-z]*\(|Schema::drop|rename|nullable\(false\)|SET NOT NULL|DROP (TABLE|COLUMN|INDEX|CONSTRAINT|VIEW|TYPE)|ALTER COLUMN|TRUNCATE|DELETE FROM|UPDATE [a-z_]+ SET' && f="$f nonadd"
    printf '%s' "$t" | grep -qE 'Schema::(table|create|rename|drop)|ALTER TABLE' && f="$f table"
    case "$f" in *raw*) case "$f" in *table*) ;; *) f="$f table" ;; esac ;; esac   # raw SQL: assume it touches a table
    printf '%s' "$t" | grep -qE '\$withinTransaction[[:space:]]*=[[:space:]]*false' && f="$f concurrent"
    echo "${f# }"
}
env_names() { # env_names <git dir> <commit>: env('X') names in config/bootstrap + keys of .env.example
    { git -C "$1" grep -ohE "env\(['\"][A-Z][A-Z0-9_]*" "$2" -- config bootstrap 2>/dev/null | sed -E "s/^env\(['\"]//"
      git -C "$1" show "$2:.env.example" 2>/dev/null | sed -nE 's/^#?[[:space:]]*([A-Z][A-Z0-9_]*)=.*/\1/p'; } | sort -u
}
vite_inputs() { # vite_inputs <git dir> <commit>: the input files of vite.config.js
    git -C "$1" show "$2:vite.config.js" | grep -E 'input' | grep -oE "resources/[^'\"]+" | sort -u
}
sched_names() { # the jobs a schedule:list output names (artisan commands and job classes), sorted
    { grep -oE 'artisan [a-z][a-z0-9:_-]*|App\\[A-Za-z0-9\\]+' || true; } | sed -E 's/^artisan //' | sort -u
}

# analyse <git dir>: everything the plan and step 1 decide from git alone. Sets CLASSES, ADDED_MIGS, FLAGS[],
# NEW_ENV; dies on a stop class, an undeclared or stale declaration, a migration list mismatch or an unreviewed
# raw/non-additive migration.
declare -A FLAGS=()
analyse() {
    local g=$1 rows c k m n x detected want
    git -C "$g" cat-file -e "$TARGET^{commit}" 2>/dev/null || die "chưa thấy commit đích $TARGET"
    git -C "$g" merge-base --is-ancestor "$OLD" "$TARGET" || die "đích không nối tiếp thẳng từ $OLD"
    rows=$(classify "$g" "$OLD" "$TARGET")
    [ -n "$rows" ] || die "không có thay đổi nào giữa $OLD và $TARGET"
    echo "Thay đổi theo nhóm ($(printf '%s\n' "$rows" | wc -l) tệp):"
    printf '%s\n' "$rows" | cut -f1 | sort | uniq -c | while read -r n c; do printf '     %-14s %5s tệp  (%s)\n' "$c" "$n" "$(kind_of "$c")"; done
    x=$(printf '%s\n' "$rows" | awk -F'\t' '$1=="unknown"||$1=="server"||$1=="migration-edit"')
    if [ -n "$x" ]; then printf '%s\n' "$x" | sed 's/^/     DỪNG  /'; die "có tệp thuộc nhóm phải dừng (lạ, cấu hình máy chủ, hoặc migration bị sửa/xóa)"; fi
    CLASSES=$(printf '%s\n' "$rows" | cut -f1 | sort -u | tr '\n' ' ')
    detected=$(for c in $CLASSES; do [ "$(kind_of "$c")" != declare ] || echo "$c"; done | sort -u | joinw)
    want=$(printf '%s\n' "${DECLARED[@]}" | sort -u | joinw)
    [ "$detected" = "$want" ] || die "nhóm cần khai báo: thấy '$detected', tệp khai báo ghi '$want' (DECLARED phải khớp đúng)"
    echo "OK   nhóm cần khai báo khớp tệp khai báo: ${want:-không có}"
    ADDED_MIGS=$(printf '%s\n' "$rows" | awk -F'\t' '$1=="migration" {print $3}' | sed -E 's#^database/migrations/##; s#\.php$##' | sort)
    expect "migration mới (theo thứ tự tên)" "$(printf '%s\n' "$ADDED_MIGS" | joinw)" "$(printf '%s\n' "${MIGRATIONS[@]}" | joinw)"
    for m in "${MIGRATIONS[@]}"; do
        FLAGS[$m]=$(mig_flags "$g" "$TARGET" "$m")
        case " ${FLAGS[$m]} " in *" raw "*|*" nonadd "*)
            [ -n "${MIG_OK[$m]:-}" ] || die "migration $m có SQL viết tay hoặc thay đổi không chỉ thêm (${FLAGS[$m]}): tệp khai báo phải ghi lý do trong MIG_OK"
        esac
        x=restart; case " ${FLAGS[$m]} " in *" table "*) ;; *) x=no-restart ;; esac
        [ -z "${PGB_SKIP[$m]:-}" ] || x="no-restart (PGB_SKIP: ${PGB_SKIP[$m]})"
        note "migration $m: [${FLAGS[$m]:-không cờ}] PgBouncer: $x${MIG_OK[$m]:+; duyệt: ${MIG_OK[$m]}}"
    done
    for m in "${!MIG_OK[@]}" "${!PGB_SKIP[@]}"; do
        printf '%s\n' "${MIGRATIONS[@]}" | grep -qxF "$m" || die "tệp khai báo nêu migration không có trong bản này: $m"
    done
    NEW_ENV=$(comm -13 <(env_names "$g" "$OLD") <(env_names "$g" "$TARGET") | joinw)
    note "khóa .env mới mà code đọc: ${NEW_ENV:-không có}"
    x=$(git -C "$g" diff --name-only "$OLD" "$TARGET" -- routes/console.php)
    if [ -n "$x" ]; then note "lịch chạy: thêm '${SCHEDULE_ADDED[*]:-}', bớt '${SCHEDULE_REMOVED[*]:-}' (bước 8 so với schedule:list thật)"; fi
    if [ "${#SCHEDULE_ADDED[@]}" -gt 0 ] || [ "${#SCHEDULE_REMOVED[@]}" -gt 0 ]; then
        [ -n "$x" ] || die "tệp khai báo ghi đổi lịch chạy nhưng routes/console.php không đổi"
    fi
    case " $CLASSES " in *" sanitizer "*) note "bộ lọc nội dung đổi: chạy thử (không ghi)${RESANITIZE:+, chế độ: $RESANITIZE}" ;; esac
    [ "${RESANITIZE:-dry-run}" = dry-run ] || [ "${RESANITIZE:-}" = apply ] || die "RESANITIZE chỉ nhận dry-run hoặc apply"
    if [ "${RESANITIZE:-dry-run}" = apply ]; then case " $CLASSES " in *" sanitizer "*) ;; *) die "RESANITIZE=apply nhưng bộ lọc nội dung không đổi" ;; esac; fi
    case " $CLASSES " in *" content-pack "*) note "gói nội dung đổi: chỉ báo, bản này không nạp gói" ;; esac
    for k in "${ONEOFFS[@]}"; do declare -F "oneoff_$k" >/dev/null || die "thiếu hàm oneoff_$k trong tệp khai báo"; done
    VITE=$(vite_inputs "$g" "$TARGET" | joinw)
}

# ---------------------------------------------------------------- state
need_state() { [ -s "$STATE/$1" ] || die "thiếu $STATE/$1 (bước $2 chưa chạy xong lần nào); chạy lại từ bước $2"; }
fresh() { local age; age=$(( $(date +%s) - $(stat -c %Y "$1") )); [ "$age" -le "$MAX_AGE" ] || die "$2 đã cũ ($(( age / 60 )) phút, quá $(( MAX_AGE / 3600 )) giờ). $3"; }
dump_fresh() {
    if [ "$(git -C "$PROD" rev-parse HEAD)" != "$OLD" ]; then echo "Bỏ qua kiểm tra bản sao lưu: code đã rời ${OLD:0:7}."; return 0; fi
    need_state dump 2
    local f; f=$(cat "$STATE/dump")
    [ -s "$f" ] || die "không thấy bản sao lưu $f. Chạy lại dòng tmux với \"dump\", rồi với \"$CUR\""
    fresh "$f" "Bản sao lưu $f" "Chạy lại dòng tmux với \"dump\", rồi với \"$CUR\""
    echo "OK   bản sao lưu còn mới: $f"
}
record_run() { # the first run fixes what every later run of this deploy must match
    local want
    want=$(printf 'OLD=%s\nTARGET=%s\nENGINE_BLOB=%s\nRELEASE_SHA=%s\n' "$OLD" "$TARGET" "$ENGINE_BLOB" "$RELEASE_SHA")
    if [ -s "$STATE/run.env" ]; then
        [ "$(cat "$STATE/run.env")" = "$want" ] || { diff <(cat "$STATE/run.env") <(printf '%s\n' "$want") || true; die "lần chạy này khác bản ghi lần đầu (bộ khung hoặc tệp khai báo đã đổi): từ chối"; }
        echo "OK   cùng bộ khung và tệp khai báo với lần chạy đầu"
    else
        printf '%s\n' "$want" > "$STATE/run.env"; echo "Ghi lần chạy đầu: $STATE/run.env"
    fi
}
has_class() { case " $(cat "$STATE/classes" 2>/dev/null) " in *" $1 "*) return 0 ;; esac; return 1; }
restart_needed() { local m; for m in "${MIGRATIONS[@]}"; do [ "$(pgb_needed "$m")" = 1 ] && return 0; done; return 1; }
pgb_needed() { [ -z "${PGB_SKIP[$1]:-}" ] && case " $(cat "$STATE/flags-$1" 2>/dev/null) " in *" table "*) true ;; *) false ;; esac && echo 1 || echo 0; }

# ---------------------------------------------------------------- migrations
pending() { # pending [runner [args]]: sets PENDING; a failing migrate:status stops with its output
    local run=${1:-ops} st; shift || true
    if ! st=$("$run" php artisan migrate:status --database=pgsql_admin "$@" 2>&1); then printf '%s\n' "$st"; die "migrate:status lỗi (thông báo ở trên)"; fi
    PENDING=$(printf '%s\n' "$st" | grep Pending | grep -oE '[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+' | sort || true)
}
stage() { # stage <name>…: exactly those files of TARGET, byte for byte, into MIGDIR
    local m
    rm -rf "$MIGDIR"; ( umask 077 && mkdir -p "$MIGDIR" )
    for m in "$@"; do
        git -C "$PROD" show "$TARGET:database/migrations/$m.php" > "$MIGDIR/$m.php"
        expect "tệp $m.php giống bản ở ${TARGET:0:8}" "$(git hash-object --no-filters "$MIGDIR/$m.php")" "$(git -C "$PROD" rev-parse "$TARGET:database/migrations/$m.php")"
    done
    expect "số tệp migration đem chạy" "$(find "$MIGDIR" -maxdepth 1 -type f -name '*.php' | wc -l)" "$#"
}
run_migrate() { # run_migrate <name> <restart 0|1>: one file; lock timeout → retry (3 tries, 20 s apart);
    # a flagged file restarts PgBouncer after EVERY attempt, failed ones included (it may have committed a step)
    local try rc out=$STATE/migrate-out
    MIGRATING=1; tick_start
    for try in 1 2 3; do
        [ "$2" = 0 ] || SCHEMA=plans
        rc=0
        ops_mig php artisan migrate --database=pgsql_admin --force --path=/deploy-migrations --realpath 2>&1 | tee "$out" || rc=$?
        MIGRATING=
        [ "$2" = 0 ] || pgb_drop
        [ "$rc" != 0 ] || break
        grep -qiE 'lock timeout|55P03' "$out" || { rm -f "$out"; die "migrate $1 lỗi (không phải hết giờ chờ khóa); xem log"; }
        [ "$try" -lt 3 ] || { rm -f "$out"; die "migrate $1 chưa lấy được khóa bảng sau 3 lần"; }
        echo "Chưa lấy được khóa bảng (lần $try); thử lại sau $LOCK_RETRY_SLEEP giây"; sleep "$LOCK_RETRY_SLEEP"
        MIGRATING=1
    done
    tick_stop; rm -f "$out"
}
CATALOG_SQL="SELECT 'col|'||c.relname||'|'||a.attname||'|'||format_type(a.atttypid, a.atttypmod)||'|'||CASE WHEN a.attnotnull THEN 'notnull' ELSE 'null' END
    FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public' AND c.relkind IN ('r','p') AND NOT c.relispartition AND a.attnum > 0 AND NOT a.attisdropped
  UNION ALL SELECT 'idx|'||t.relname||'|'||i.relname||'|'||CASE WHEN x.indisvalid AND x.indisready THEN 'valid' ELSE 'INVALID' END
    FROM pg_index x JOIN pg_class i ON i.oid = x.indexrelid JOIN pg_class t ON t.oid = x.indrelid JOIN pg_namespace n ON n.oid = t.relnamespace
    WHERE n.nspname = 'public' AND NOT t.relispartition
  UNION ALL SELECT 'con|'||t.relname||'|'||k.conname||'|'||k.contype||'|'||CASE WHEN k.convalidated THEN 'validated' ELSE 'NOT-VALID' END
    FROM pg_constraint k JOIN pg_class t ON t.oid = k.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace
    WHERE n.nspname = 'public' AND NOT t.relispartition
  ORDER BY 1"
catalog() { q "$CATALOG_SQL" > "$STATE/$1"; [ -s "$STATE/$1" ] || die "không đọc được cấu trúc bảng"; }
catalog_delta() { # print what step 4 changed; every NEW index must be valid and every NEW foreign key validated
    local added removed bad
    added=$(comm -13 "$STATE/catalog-before" "$STATE/catalog-after")
    removed=$(comm -23 "$STATE/catalog-before" "$STATE/catalog-after")
    echo "Cấu trúc bảng thêm ($(printf '%s' "$added" | grep -c . || true) dòng):"; printf '%s\n' "$added" | sed '/^$/d; s/^/     + /'
    if [ -n "$removed" ]; then echo "Cấu trúc bảng bớt/đổi:"; printf '%s\n' "$removed" | sed 's/^/     - /'; fi
    bad=$(printf '%s\n' "$added" | grep -E '^idx\|.*\|INVALID$|^con\|[^|]*\|[^|]*\|f\|NOT-VALID$' || true)
    [ -z "$bad" ] || { printf '%s\n' "$bad" | sed 's/^/     LỖI /'; die "chỉ mục mới chưa hợp lệ hoặc khóa ngoại mới chưa VALIDATE"; }
    if [ -n "$removed" ] && [ "${#MIG_OK[@]}" = 0 ]; then die "migration bớt hoặc đổi cấu trúc nhưng tệp khai báo không ghi migration nào được duyệt"; fi
    echo "OK   chỉ mục mới hợp lệ, khóa ngoại mới đã VALIDATE"
}

# ---------------------------------------------------------------- steps
s0() {
    local head x p runs
    for x in docker git gh jq pnpm gzip flock sha256sum; do command -v "$x" >/dev/null || die "máy chưa có lệnh $x"; done
    [ -z "$(git -C "$PROD" status --porcelain --untracked-files=no)" ] || die "cây prod có tệp sửa tay"
    head=$(git -C "$PROD" rev-parse HEAD); echo "Prod đang ở $head"
    case "$head" in "$OLD") ;; "$TARGET") echo "Code đã ở đích (chạy lại sau một lần dừng)."; MIXED=1 ;; *) die "prod ở commit lạ $head (cần $OLD)" ;; esac
    docker image inspect "$IMAGE" >/dev/null || die "chưa có image $IMAGE"
    [ -s "$SECRETS" ] || die "thiếu tệp mật khẩu superuser cho bước migrate"
    for x in app horizon scheduler pg pgbouncer valkey; do expect "container $PREFIX$x đang chạy" "$(docker inspect -f '{{.State.Running}}' "$PREFIX$x")" "true"; done
    runs=$(gh run list -R "$REPO" --workflow ci.yml --commit "$TARGET" --json event,status,conclusion) || die "không đọc được CI trên GitHub (gh)"
    x=$(printf '%s' "$runs" | jq -r 'if length > 0 and all(.status == "completed" and .conclusion == "success") and any(.event == "schedule" or .event == "workflow_dispatch") then "xanh" else "chưa" end')
    expect "CI đầy đủ trên ${TARGET:0:8}" "$x" "xanh"
    x=$(df -Pk "$BK" | awk 'NR==2 {print int($4/1048576)}'); [ "$x" -ge "$MIN_DISK_GB" ] || die "ổ sao lưu còn ${x} GB (cần $MIN_DISK_GB GB)"; echo "OK   ổ sao lưu còn ${x} GB"
    if [ "$head" = "$OLD" ]; then
        art --version > "$STATE/laravel-version"; echo "Laravel: $(cat "$STATE/laravel-version")"
        : > "$STATE/pages-before"
        for p in "${PAGES[@]}"; do x=$(code "$p" '%{http_code}'); [ "$x" != 000 ] || die "trang $p không trả lời"; printf '%s %s\n' "$p" "$x" >> "$STATE/pages-before"; done
        cat "$STATE/pages-before"
        for p in / /admin/login; do expect "trang $p trước khi triển khai" "$(awk -v p="$p" '$1==p {print $2}' "$STATE/pages-before")" "200"; done
        docker exec "${PREFIX}scheduler" php artisan schedule:list | sched_names > "$STATE/schedule-before"
        echo "Lịch chạy hiện có: $(tr '\n' ' ' < "$STATE/schedule-before")"
        if declare -F release_before >/dev/null; then release_before; fi
    else
        for x in laravel-version pages-before schedule-before; do need_state "$x" 0; done
    fi
}
s1() {
    local x k missing=
    git -C "$PROD" fetch -q origin
    analyse "$PROD"
    printf '%s\n' "$CLASSES" > "$STATE/classes"
    for k in "${MIGRATIONS[@]}"; do printf '%s\n' "${FLAGS[$k]}" > "$STATE/flags-$k"; done
    # new env keys: names only, against prod .env (a missing one would silently fall back to its default)
    for k in $NEW_ENV; do
        if ! sed -nE 's/^([A-Za-z_][A-Za-z0-9_]*)=.*/\1/p' "$PROD/.env" | grep -qxF "$k"; then
            printf '%s\n' "${ENV_DEFAULT_OK[@]}" | grep -qxF "$k" && { note "khóa .env $k chưa có trên prod: dùng mặc định (tệp khai báo cho phép)"; continue; }
            missing="$missing $k"
        fi
    done
    [ -z "$missing" ] || die "prod .env thiếu khóa mới:$missing (thêm vào .env rồi tạo lại container, hoặc ghi vào ENV_DEFAULT_OK nếu mặc định là đúng)"
    echo "OK   khóa .env mới: ${NEW_ENV:-không có}"
    local new old
    new=$(git -C "$PROD" show "$TARGET:composer.lock" | jq -c '[.packages[] | {name, version}]' | sha256sum | cut -c1-16)
    old=$(git -C "$PROD" show "$OLD:composer.lock" | jq -c '[.packages[] | {name, version}]' | sha256sum | cut -c1-16)
    if has_class composer-lock; then
        note "gói PHP đổi (khai báo composer-lock):"
        diff <(git -C "$PROD" show "$OLD:composer.lock" | jq -r '.packages[] | .name + " " + .version') \
             <(git -C "$PROD" show "$TARGET:composer.lock" | jq -r '.packages[] | .name + " " + .version') | grep '^[<>]' | sed 's/^/     /' || true
        case "$(diff <(git -C "$PROD" show "$OLD:composer.lock" | jq -r '.packages[] | .name + " " + .version') <(git -C "$PROD" show "$TARGET:composer.lock" | jq -r '.packages[] | .name + " " + .version') || true)" in
            *ezyang/htmlpurifier*) has_class sanitizer || die "ezyang/htmlpurifier đổi: phải khai báo thêm nhóm sanitizer" ;;
        esac
    else
        expect "gói PHP chạy trên prod (không tính gói dev)" "$new" "$old"
    fi
}
s2() {
    local f n parts prev min=20480 made copies
    f=$BK/prod-predeploy-${TARGET:0:8}-$TS.sql.gz
    parts=$(q "SELECT count(*) FROM pg_class WHERE relkind = 'p' AND relnamespace = 'public'::regnamespace")
    ( umask 077; docker exec "${PREFIX}pg" sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump --no-password -U "$POSTGRES_USER" bswebsaas' | gzip -9 > "$f" )
    chmod 600 "$f"; gzip -t "$f"
    # at least 90% of the largest earlier pre-deploy dump (a fixed floor misfired on a 65 KB DB)
    prev=$(find "$BK" -maxdepth 1 -name 'prod-predeploy-*.sql.gz' ! -path "$f" -printf '%s %p\n' | sort -n | tail -1 | cut -d' ' -f2- || true)
    [ -z "$prev" ] || min=$(( $(stat -c %s "$prev") * 9 / 10 ))
    n=$(stat -c %s "$f"); [ "$n" -ge "$min" ] || die "bản sao lưu nhỏ bất thường ($n byte; cần ít nhất $min byte)"
    expect "bản sao lưu đủ tới cuối" "$(gzip -dc "$f" | tail -8 | grep -c '^-- PostgreSQL database dump complete$' || true)" "1"
    # every plain table has its data block (a partitioned parent has none: its months carry the rows)
    made=$(gzip -dc "$f" | grep -cE '^CREATE TABLE public\.' || true)
    copies=$(gzip -dc "$f" | grep -cE '^COPY public\.' || true)
    expect "bản sao lưu: số khối dữ liệu = số bảng − bảng cha chia tháng" "$copies" "$(( made - parts ))"
    expect "bản sao lưu có bảng migrations" "$(gzip -dc "$f" | grep -c '^COPY public\.migrations ' || true)" "1"
    printf '%s\n' "$f" > "$STATE/dump"
    echo "OK   sao lưu: $f ($(stat -c %s "$f") byte)"
}
s3() {
    local x
    dump_fresh
    need_state classes 1
    if [ "${#MIGRATIONS[@]}" = 0 ]; then
        q "SELECT max(migration) FROM migrations" > "$STATE/prev-migration"
        echo "Bản này không có migration. Migration mới nhất trên prod: $(cat "$STATE/prev-migration")"
        date '+%F %T' > "$STATE/s3-ok"; return 0
    fi
    if [ -s "$STATE/s3-ok" ]; then echo "Bỏ qua bước 3: đã qua lúc $(cat "$STATE/s3-ok") (bước 4 chạy lại an toàn)."; return 0; fi
    pending; expect "migration của bản cũ còn chờ" "${PENDING:-không}" "không"
    stage "${MIGRATIONS[@]}"
    pending ops_mig --path=/deploy-migrations --realpath
    expect "migration chờ trên prod = tệp mới của bản này" "$(printf '%s\n' "$PENDING" | joinw)" "$(printf '%s\n' "${MIGRATIONS[@]}" | sort | joinw)"
    expect "phiên 'idle in transaction' quá 60 giây" "$(q "SELECT count(*) FROM pg_stat_activity WHERE state LIKE 'idle in transaction%' AND xact_start < now() - interval '60 seconds'")" "0"
    catalog catalog-before
    echo "OK   chụp cấu trúc bảng trước migrate: $(wc -l < "$STATE/catalog-before") dòng"
    if declare -F release_check >/dev/null; then release_check; fi
    rm -rf "$MIGDIR"
    date '+%F %T' > "$STATE/s3-ok"
}
s4() {
    local m r head
    rm -f "$STATE/s4-ok"
    need_state s3-ok 3
    head=$(git -C "$PROD" rev-parse HEAD)
    if [ "${#MIGRATIONS[@]}" = 0 ]; then date +%s > "$STATE/s4-ok"; echo "Không có migration."; return 0; fi
    if [ "$head" = "$TARGET" ]; then
        MIXED=1; echo "Code đã ở đích: migration đã chạy ở lần trước, chỉ kiểm tra lại."
        expect "đã ghi đủ migration của bản này" "$(q "SELECT count(*) FROM migrations WHERE migration IN ($(printf "'%s'," "${MIGRATIONS[@]}" | sed 's/,$//'))")" "${#MIGRATIONS[@]}"
        date +%s > "$STATE/s4-ok"; return 0
    fi
    expect "commit prod (code cũ vẫn chạy trong bước này)" "$head" "$OLD"
    dump_fresh
    for m in "${MIGRATIONS[@]}"; do
        r=$(pgb_needed "$m")
        if [ -s "$STATE/mig-$m" ]; then echo "Bỏ qua $m: đã chạy lúc $(cat "$STATE/mig-$m")"; continue; fi
        stage "$m"
        pending ops_mig --path=/deploy-migrations --realpath
        if [ -z "$PENDING" ]; then
            echo "$m đã được ghi (lần trước dừng sau khi chạy xong)"; [ "$r" = 0 ] || pgb_drop
        else
            expect "chờ chạy" "$PENDING" "$m"
            case " $(cat "$STATE/flags-$m") " in *" concurrent "*)
                note "phiên idle in transaction lúc này: $(q "SELECT count(*) FROM pg_stat_activity WHERE state LIKE 'idle in transaction%'") (chỉ mục CONCURRENTLY chờ chúng xong)" ;;
            esac
            echo "Chạy $m (PgBouncer khởi động lại sau đó: $([ "$r" = 1 ] && echo có || echo không))"
            run_migrate "$m" "$r"
            pending ops_mig --path=/deploy-migrations --realpath
            expect "$m còn chờ" "${PENDING:-không}" "không"
        fi
        date '+%F %T' > "$STATE/mig-$m"
    done
    rm -rf "$MIGDIR"
    catalog catalog-after
    catalog_delta
    if declare -F release_schema >/dev/null; then release_schema; fi
    wait_up   # the OLD code on the new schema: the rollback verdict, live
    expect "trang /admin/login (code cũ trên cấu trúc bảng mới)" "$(code /admin/login '%{http_code}')" "200"
    SCHEMA=
    date +%s > "$STATE/s4-ok"
}
s5() { # from the merge to octane:reload in step 7 the old workers serve the new files: no slow call in between
    dump_fresh; need_state s4-ok 4
    MIXED=1
    [ "$(git -C "$PROD" rev-parse HEAD)" = "$TARGET" ] || git -C "$PROD" merge -q --ff-only "$TARGET"
    expect "commit prod" "$(git -C "$PROD" rev-parse HEAD)" "$TARGET"
    composer_install
}
composer_install() { # always: also refreshes the optimized class map (a moved class must not keep a stale path)
    docker exec "${PREFIX}app" composer install --no-dev --optimize-autoloader --no-interaction
    if has_class composer-lock; then echo "Laravel: trước $(cat "$STATE/laravel-version"), bây giờ $(art --version)"
    else expect "phiên bản Laravel sau composer" "$(art --version)" "$(cat "$STATE/laravel-version")"; fi
}
build() {
    pushd "$PROD" >/dev/null
    pnpm install --frozen-lockfile
    pnpm run build
    popd >/dev/null
}
s6() {
    local m x
    MIXED=1
    RESUME=5; expect "commit prod" "$(git -C "$PROD" rev-parse HEAD)" "$TARGET"; RESUME=
    need_state s4-ok 4
    build
    m=$PROD/public/build/manifest.json
    for x in $(vite_inputs "$PROD" HEAD); do expect "bản build có $x" "$(jq -r --arg k "$x" 'has($k)' "$m")" "true"; done
}
reload() {
    date -u +%FT%TZ > "$STATE/t7"
    art view:clear
    art optimize
    art octane:reload
    docker restart "${PREFIX}horizon" "${PREFIX}scheduler"
    if restart_needed; then pgb_drop; fi
    wait_up
}
oneoff() { # oneoff <name>: preview → apply → re-preview must be 0; a mark stops a resume from applying twice
    local k=$1 n
    if [ -s "$STATE/oneoff-$k" ]; then echo "Bỏ qua $k: đã chạy lúc $(cat "$STATE/oneoff-$k")"; return 0; fi
    n=$("oneoff_$k" preview | tail -n 1); [[ "$n" =~ ^[0-9]+$ ]] || die "$k (xem trước) không in số dòng sẽ đổi"
    echo "$k: xem trước sẽ đổi $n"
    if [ "$n" != 0 ]; then "oneoff_$k" apply; fi
    expect "$k xem lại sau khi chạy (còn đổi)" "$("oneoff_$k" preview | tail -n 1)" "0"
    date '+%F %T' > "$STATE/oneoff-$k"
}
s7() {
    local x c k
    MIXED=1
    RESUME=5; expect "commit prod" "$(git -C "$PROD" rev-parse HEAD)" "$TARGET"; RESUME=
    need_state s4-ok 4
    rm -f "$STATE/data-ok"
    reload
    MIXED=
    pending; expect "migration còn chờ" "${PENDING:-không}" "không"
    if [ "${#MIGRATIONS[@]}" -gt 0 ]; then expect "migration mới nhất đã chạy" "$(q "SELECT max(migration) FROM migrations")" "${MIGRATIONS[-1]}"
    else expect "migration mới nhất đã chạy" "$(q "SELECT max(migration) FROM migrations")" "$(cat "$STATE/prev-migration")"; fi
    if has_class permission; then
        if [ -s "$STATE/rbac-ok" ]; then echo "Bỏ qua rbac:sync: đã chạy"; else art rbac:sync; date '+%F %T' > "$STATE/rbac-ok"; fi
    fi
    if has_class sanitizer; then
        x=$(art cms:resanitize-bodies --dry-run 2>&1) || { printf '%s\n' "$x"; die "cms:resanitize-bodies --dry-run lỗi"; }
        printf '%s\n' "$x" | tail -3
        c=$(printf '%s\n' "$x" | sed -nE 's/^([0-9]+) would change on [0-9]+ site\(s\)\.$/\1/p')
        [ -n "$c" ] || die "cms:resanitize-bodies --dry-run không in dòng tổng kết như mong đợi"
        echo "Nội dung sẽ được làm sạch lại: $c dòng (chế độ ${RESANITIZE:-dry-run})"
        if [ "${RESANITIZE:-dry-run}" = apply ] && [ "$c" != 0 ] && [ ! -s "$STATE/resanitize-ok" ]; then
            art cms:resanitize-bodies; art media:reconcile-content-usage
            x=$(art cms:resanitize-bodies --dry-run 2>&1 | sed -nE 's/^([0-9]+) would change on [0-9]+ site\(s\)\.$/\1/p')
            expect "làm sạch lại lần 2 còn đổi" "$x" "0"; date '+%F %T' > "$STATE/resanitize-ok"
        fi
    fi
    for k in "${ONEOFFS[@]}"; do oneoff "$k"; done
    date +%s > "$STATE/data-ok"
}
s8() {
    local t7 x p want c css added removed
    need_state t7 7; need_state data-ok 7
    fresh "$STATE/t7" "Mốc nạp lại của bước 7" "Chạy lại dòng tmux với \"7\""
    t7=$(cat "$STATE/t7")
    while read -r p want; do expect "trang $p (trước: $want)" "$(code "$p" '%{http_code}')" "$want"; done < "$STATE/pages-before"
    for p in / /admin/login; do expect "trang $p" "$(code "$p" '%{http_code}')" "200"; done
    css=$(jq -er '."resources/css/storefront.css".file' "$PROD/public/build/manifest.json")
    [ "$(has / "/build/$css")" -ge 1 ] || die "trang chủ chưa dùng CSS của bản build mới ($css)"
    echo "OK   trang chủ dùng CSS bản build mới ($css)"
    docker exec "${PREFIX}scheduler" php artisan schedule:list | sched_names > "$STATE/schedule-after"
    added=$(comm -13 "$STATE/schedule-before" "$STATE/schedule-after" | joinw)
    removed=$(comm -23 "$STATE/schedule-before" "$STATE/schedule-after" | joinw)
    expect "lịch chạy thêm" "$added" "$(printf '%s\n' "${SCHEDULE_ADDED[@]}" | sort | joinw)"
    expect "lịch chạy bớt" "$removed" "$(printf '%s\n' "${SCHEDULE_REMOVED[@]}" | sort | joinw)"
    x=$(docker logs --since "$t7" "${PREFIX}app" 2>&1 | grep -c 'superuser database password' || true)
    expect "cảnh báo mật khẩu superuser từ lúc nạp lại" "$x" "0"
    for c in app horizon scheduler; do
        x=$(docker exec "$PREFIX$c" sh -c 'echo "env=${DB_ADMIN_PASSWORD:+SET} dotenv-lines=$(grep -c "^DB_ADMIN_PASSWORD=." /app/.env)"')
        expect "$PREFIX$c: mật khẩu trong env/.env" "$x" "env= dotenv-lines=0"
        x=$(docker exec "$PREFIX$c" php -r '$c = @include "/app/bootstrap/cache/config.php"; echo empty($c["database"]["connections"]["pgsql_admin"]["password"]) ? "unset" : "SET";')
        expect "$PREFIX$c: mật khẩu trong config cache" "$x" "unset"
        x=$(docker logs --since "$t7" "$PREFIX$c" 2>&1 | grep -c 'cached plan must not change result type' || true)
        expect "lỗi 'cached plan' của $PREFIX$c từ lúc nạp lại" "$x" "0"
    done
    if declare -F release_smoke >/dev/null; then release_smoke; fi
    echo "Dòng lỗi trong log app từ lúc nạp lại (vài dòng lúc nạp lại là bình thường): $(docker logs --since "$t7" "${PREFIX}app" 2>&1 | grep -ciE 'error|exception' || true)"
}
rollback() { # CODE only: the schema stays (expand-only migrations; step 4 proved the old code runs on it)
    local head p
    [ -z "$(git -C "$PROD" status --porcelain --untracked-files=no)" ] || die "cây prod có tệp sửa tay"
    need_state laravel-version 0; need_state classes 1
    head=$(git -C "$PROD" rev-parse HEAD)
    case "$head" in
        "$TARGET") MIXED=1; git -C "$PROD" reset -q --hard "$OLD" ;;
        "$OLD") echo "Code đã ở bản cũ: cài, build và nạp lại."; MIXED=1 ;;
        *) die "prod ở commit lạ $head" ;;
    esac
    docker exec "${PREFIX}app" composer install --no-dev --optimize-autoloader --no-interaction
    build
    reload
    MIXED=
    for p in / /admin/login; do expect "trang $p" "$(code "$p" '%{http_code}')" "200"; done
    pending; expect "migration còn chờ" "${PENDING:-không}" "không"
    echo "Lưu ý: cơ sở dữ liệu giữ nguyên ${#MIGRATIONS[@]} migration của bản này (không lùi; bản cũ không dùng phần mới)."
    if declare -F release_rollback >/dev/null; then release_rollback; fi
}

# ---------------------------------------------------------------- modes
load_release() { # data + one-off functions only; arrays default to empty
    DECLARED=() MIGRATIONS=() SCHEDULE_ADDED=() SCHEDULE_REMOVED=() ENV_DEFAULT_OK=() ONEOFFS=() PAGES=(/ /admin/login)
    declare -gA MIG_OK=() PGB_SKIP=()
    RESANITIZE=dry-run HOST= OLD= TARGET=
    # shellcheck disable=SC1090
    . "$1"
    [[ "$OLD" =~ ^[0-9a-f]{40}$ ]] && [[ "$TARGET" =~ ^[0-9a-f]{40}$ ]] || die "tệp khai báo cần OLD và TARGET đủ 40 ký tự"
    [ -n "$HOST" ] || die "tệp khai báo thiếu HOST"
}
mode_plan() { # git only, on the dev repo; prod is never touched (docker/gh/ssh/psql/pnpm die here)
    local rel=$1 commit=${2:-} dev blob relsha pin
    MODE=plan; plan_guard
    trap 'die "lệnh ở dòng $LINENO lỗi (mã $?)"' ERR
    dev=$(git -C "$(dirname "$SELF")" rev-parse --show-toplevel)
    commit=$(git -C "$dev" rev-parse "${commit:-HEAD}^{commit}")
    blob=$(git -C "$dev" rev-parse -q --verify "$commit:scripts/deploy/deploy.sh" || echo "chưa-commit")
    [ "$(git hash-object --no-filters "$SELF")" = "$blob" ] || echo "LƯU Ý tệp đang chạy khác bộ khung ở ${commit:0:8} (blob $blob): kế hoạch dưới đây theo tệp đang chạy; chưa chạy thật được."
    load_release "$rel"
    plan_guard   # again: a release file must not lift the guard by redefining a wrapper
    relsha=$(sha256sum "$rel" | cut -d' ' -f1)
    echo "===== Kế hoạch deploy ${OLD:0:8} → ${TARGET:0:8} (chỉ đọc git trên máy dev; không chạm prod) ====="
    echo "Bộ khung: commit $commit, blob $blob"
    echo "Tệp khai báo: $rel, sha256 $relsha"
    pin=$(git -C "$PROD_PATH" log -1 --format=%H 2>/dev/null || true)
    [ -z "$pin" ] || { [ "$pin" = "$OLD" ] && echo "OK   prod đang ở OLD" || echo "LƯU Ý prod đang ở $pin, khác OLD ${OLD:0:8}: bước 0 sẽ dừng"; }
    git -C "$dev" merge-base --is-ancestor "$commit" origin/main 2>/dev/null || echo "LƯU Ý commit bộ khung chưa nằm trên origin/main: chưa dùng cho prod"
    analyse "$dev"
    echo "Các bước:"
    note "0 kiểm tra (CI đầy đủ, container, ổ đĩa, ảnh trước: ${#PAGES[@]} trang, lịch chạy$(declare -F release_before >/dev/null && echo ', release_before'))"
    note "1 so thay đổi trên prod (như trên) + khóa .env mới: ${NEW_ENV:-không có}"
    note "2 sao lưu DB"
    if [ "${#MIGRATIONS[@]}" -gt 0 ]; then
        note "3 kiểm chỉ đọc: chờ = ${#MIGRATIONS[@]} tệp mới, idle in transaction, chụp cấu trúc bảng"
        note "4 migrate từng tệp (code cũ vẫn phục vụ), rồi so cấu trúc bảng, trang chủ + đăng nhập 200 trên code cũ"
    else note "3–4 không có migration (chỉ kiểm không còn chờ)"; fi
    note "5 đổi code + composer install · 6 pnpm install + build ($(wc -w <<< "$VITE") input)"
    note "7 view:clear, optimize, octane:reload, khởi động lại horizon + scheduler$(restart_needed_plan && echo ', PgBouncer')$(case " $CLASSES " in *" permission "*) echo ', rbac:sync' ;; esac)$(case " $CLASSES " in *" sanitizer "*) echo ", resanitize ${RESANITIZE}" ;; esac)${ONEOFFS[*]:+, một lần: ${ONEOFFS[*]}}"
    note "8 kiểm sau: trang như trước, CSS mới, lịch chạy (+${SCHEDULE_ADDED[*]:-} −${SCHEDULE_REMOVED[*]:-}), mật khẩu superuser, cached plan"
    echo "Dòng anh chạy (giữ nguyên; muốn chạy tiếp từ một bước thì thêm số bước trước dấu ;):"
    local envp=; [ "$dev" = /opt/bswebsaas/dev ] || envp="DEPLOY_DEV_REPO=$dev "
    printf "  ! tmux new-session -d -s deploy '%sbash -c \"\$(git -C %s show %s:scripts/deploy/launch.sh)\" launch %s %s %s %s; exec bash'\n" "$envp" "$dev" "$commit" "$commit" "$blob" "$(readlink -f "$rel")" "$relsha"
    echo "Lùi code: cùng dòng, thêm \"rollback\". Sao lưu lại: thêm \"dump\"."
}
restart_needed_plan() { local m; for m in "${MIGRATIONS[@]}"; do [ -z "${PGB_SKIP[$m]:-}" ] && case " ${FLAGS[$m]} " in *" table "*) return 0 ;; esac; done; return 1; }
mode_run() {
    local rel=$1 start=${5:-0} s on=
    ENGINE_BLOB=$2 RELEASE_SHA=$3 ENGINE_COMMIT=$4
    MAX_AGE=7200
    load_profile
    [ "$(git hash-object --no-filters "$SELF")" = "$ENGINE_BLOB" ] || die "bộ khung đang chạy khác blob đã duyệt"
    [ "$(sha256sum "$rel" | cut -d' ' -f1)" = "$RELEASE_SHA" ] || die "tệp khai báo khác mã kiểm đã duyệt"
    load_release "$rel"
    NAME=${OLD:0:7}-${TARGET:0:8}
    STATE=$DEPLOY_RUNS/$NAME
    MIGDIR=$STATE/migrations
    TS=$(date +%Y%m%d-%H%M%S)
    LOG=$DEPLOY_RUNS/$NAME-run-$TS.log
    ( umask 077 && mkdir -p "$STATE" && chmod 700 "$DEPLOY_RUNS" "$STATE" && : > "$LOG" )
    MODE=run
    exec > >(trap '' INT TERM HUP; exec tee -a "$LOG") 2>&1
    TEE=$!
    trap 'tick_stop; exec >&- 2>&-; wait "$TEE"' EXIT
    trap 'die "lệnh ở dòng $LINENO lỗi (mã $?)"' ERR
    trap 'die "bị ngắt bằng Ctrl-C (INT)"' INT
    trap 'die "bị dừng từ bên ngoài (TERM)"' TERM
    trap 'die "cửa sổ hoặc phiên tmux bị đóng (HUP)"' HUP
    export GIT_TERMINAL_PROMPT=0 CI=true
    exec 9> "$DEPLOY_RUNS/.lock"
    flock -n 9 || die "một lần deploy khác đang chạy (khóa $DEPLOY_RUNS/.lock)"
    echo "Log: $LOG   Bắt đầu từ: $start   Bộ khung ${ENGINE_BLOB:0:12} (commit ${ENGINE_COMMIT:0:8})   Tệp khai báo ${RELEASE_SHA:0:12}"
    case "$start" in
        rollback)
            CUR=rollback; [ -s "$STATE/run.env" ] || die "chưa có lần chạy nào của bản này: không có gì để lùi"
            record_run; printf '\n===== Lùi code về %s =====\n' "${OLD:0:7}"; rollback
            trap - ERR INT TERM HUP; printf '\n===== XONG: code prod đã lùi về %s. Nhắn AI: "đã lùi, xem log %s" =====\n' "$(git -C "$PROD" log -1 --format=%h)" "$LOG"; return 0 ;;
        dump)
            CUR=2; RESUME=dump; record_run; s2
            trap - ERR INT TERM HUP; printf '\n===== XONG sao lưu. Nhắn AI: "đã sao lưu lại, xem log %s" =====\n' "$LOG"; return 0 ;;
    esac
    [ "$start" = 0 ] || [ -s "$STATE/run.env" ] || die "chưa có lần chạy đầu: chạy từ bước 0"
    record_run
    for s in 0 1 2 3 4 5 6 7 8; do
        [ "$s" = "$start" ] && on=1
        [ -n "$on" ] || continue
        CUR=$s; printf '\n===== Bước %s =====\n' "$s"; "s$s"
    done
    [ -n "$on" ] || die "không có bước '$start' (0–8, dump, rollback)"
    trap - ERR INT TERM HUP
    printf '\n===== XONG: prod ở %s. Nhắn AI: "xong, xem log %s" =====\n' "$(git -C "$PROD" log -1 --format=%h)" "$LOG"
}

main() {
    set -Eeuo pipefail
    SELF=$(readlink -f "$0")
    PROD=$PROD_PATH
    case "${1:-}" in
        plan) [ -n "${2:-}" ] || die "cần tệp khai báo"; mode_plan "$2" "${3:-}" ;;
        classify) classify "$2" "$3" "$4" ;;
        run) shift; mode_run "$@" ;;
        *) echo "dùng: deploy.sh plan <tệp khai báo> [commit] | classify <git dir> <old> <new>  (run chỉ qua launch.sh)" >&2; exit 2 ;;
    esac
}
main "$@"; exit
