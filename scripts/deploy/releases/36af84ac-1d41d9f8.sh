# Release file: prod 36af84ac → 1d41d9f8 (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/36af84ac-1d41d9f8.sh
# Range:
#   #680 (Design room) own profile: migration 2064 adds users.job_title + users.phone (nullable, a CHECK on the
#        phone form; each step its own 5 s lock_timeout transaction; down() refuses while any value exists);
#   #682 phone menu: desktop bar states no longer leak into the phone menu (a tapped or keyboard-focused row stayed
#        dark under dark text); tap feedback + focus ring; the chosen tab gets a 4px ink underline, the tab row
#        sticks at the top on screens ≥ 480px tall; CI guards (tap-check drawer pass, sf-nav scope check);
#   #683 the phone menu's "Tab Sản phẩm" switch moves from Trình đơn to Cài đặt › Sản phẩm (same setting key);
#   #678 storefront search word tables: migration 2062 creates entry_search_words, product_site_words,
#        taxonomy_search_words (RLS + FORCE, empty; nothing reads them yet), every save keeps them, the
#        command `search:words` fills/checks them and runs nightly (--rolling=10000, 21:00 UTC). Runbook:
#        "The deploy that carries migration 2062": migrate → reload → fill (exit 0, a re-run corrects 0).
# No library change, no .env key.
# shellcheck shell=bash disable=SC2034
OLD=36af84acc10f6faf87d8c5bc914732de889053e2
TARGET=1d41d9f8917c093d35426a501562acebeecc67a6
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration schedule)

MIGRATIONS=(2062_01_01_000000_create_site_search_word_tables 2064_01_01_000000_add_job_title_and_phone_to_users)
declare -gA MIG_OK=(
    [2062_01_01_000000_create_site_search_word_tables]="3 new empty tenant tables (RLS + FORCE, grants to app_user); foreign keys to sites/entries/products/taxonomies take a brief write lock, 5s lock_timeout then re-run; no column of an existing table changes; down() drops the three derived tables"
    [2064_01_01_000000_add_job_title_and_phone_to_users]="2 nullable columns on users + a phone CHECK, no default, no rewrite; each step its own transaction with a 5s lock_timeout, guarded IF [NOT] EXISTS; down() refuses while any row holds a value"
)
# 2064 adds columns to users: PgBouncer must drop its cached plans (restart after up()); 2062 only adds tables.
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=(search:words)
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
# #682: the scoped desktop states and the sticky tab row are in the built storefront CSS (checked at step 6).
BUILD_MARKERS=("resources/css/storefront.css :where(.sf-nav--desk)")

ONEOFFS_PRE=()
ONEOFFS=(search_words)
# #678: fill the word tables after the reload (runbook "The deploy that carries migration 2062"): every site, every
# kind; items saved meanwhile by old-code workers are among those it fills. Safe to re-run; a second run corrects 0.
oneoff_search_words() {
    local x n k all
    case "$1" in
        preview) if [ -s "$STATE/search-words-filled" ]; then echo 0; else echo 1; fi ;;
        apply) all=$(q "SELECT (SELECT count(*) FROM entries) + (SELECT count(*) FROM products) + (SELECT count(*) FROM taxonomies)")
            x=$(art search:words 2>&1) || { printf '%s\n' "$x" | tail -20; die "search:words lỗi (mục hỏng được ghi trong log): chưa chạy lại, nhắn AI"; }
            n=$(printf '%s\n' "$x" | sed -nE 's/^Checked ([0-9]+) item\(s\) on [0-9]+ site\(s\); corrected [0-9]+\.$/\1/p' | tail -1)
            k=$(printf '%s\n' "$x" | sed -nE 's/^Checked [0-9]+ item\(s\) on [0-9]+ site\(s\); corrected ([0-9]+)\.$/\1/p' | tail -1)
            [[ "$n" =~ ^[0-9]+$ && "$k" =~ ^[0-9]+$ ]] || die "search:words không in dòng \"Checked … corrected …\""
            # every row of every site is checked, the Trash too: N below the count taken before means a site was skipped
            [ "$n" -ge "$all" ] || die "search:words chỉ kiểm $n mục, prod có $all (bài + sản phẩm + chuyên mục)"
            echo "OK   search:words đã kiểm $n mục (prod có $all lúc bắt đầu), ghi từ cho $k"
            date '+%F %T' > "$STATE/search-words-filled" ;;
        verify) x=$(art search:words 2>&1) || die "search:words lần 2 lỗi: $(tail -3 <<< "$x")"
            expect "chạy lại search:words không còn gì để sửa (#678)" "$(grep -E '^Checked ' <<< "$x" | tail -1 | grep -c 'corrected 0\.$' || true)" "1" ;;
    esac
}
release_smoke() {
    local r html code css
    # #678: the fill command is in the running code, and the nightly check is scheduled
    r=$(art list --raw 2>&1 | grep -c '^search:words ' || true)
    expect "lệnh search:words có trong bản chạy (#678)" "$r" "1"
    # #682: the served storefront CSS carries the scoped desktop states and the 4px ink tab underline
    html=$(curl -s -m 20 "https://$HOST/?m7=$RANDOM")
    css=$({ grep -oE '/build/assets/storefront-[A-Za-z0-9_-]+\.css' <<< "$html" || true; } | head -1)
    [ -n "$css" ] || die "trang chủ không trỏ tới file CSS storefront nào (#682)"
    r=$(curl -s -m 20 "https://$HOST$css" | grep -c 'inset 0 -4px 0 var(--color-text-strong)' || true)
    expect "CSS website có gạch tab 4px màu chữ đậm (#682)" "$r" "1"
    # earlier releases stay as they were
    expect "cỡ chữ trong menu điện thoại vẫn là nút trượt (#673)" "$(grep -c 'class="sf-pill sf-pill--fs"' <<< "$html" || true)" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
