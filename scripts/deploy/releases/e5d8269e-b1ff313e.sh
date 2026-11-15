# Release file: prod e5d8269e → b1ff313e (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/e5d8269e-b1ff313e.sh
# Range:
#   #721 a website may hold at most `users.max` people (plan feature, scope site, default 10, overridable
#        per site): inviting is refused when members + pending invitations (without this address's own)
#        fill it, accepting when the members alone do; both under the site's invitation lock. The platform
#        plan editor keeps users.max out of its generic feature table (value kept on save).
#        Migration 2076 (data only, no schema change) seeds plan_features.users.max on every plan =
#        max(10, largest website headcount on that plan), and for a website of a customer with NO plan that
#        holds more than 10 people, a site_features override of exactly its headcount plus a tenant_history
#        row saying why. Idempotent (ON CONFLICT DO NOTHING); down() leaves the rows (old code never reads them).
# No library change, no .env key, no schedule change, no CSS/JS change (no new build string).
# shellcheck shell=bash disable=SC2034
OLD=e5d8269ed5e6cc4609a954f5be659581e54a3c50
TARGET=b1ff313e94610a6121fbd6d184f0cc7ee1f0024a
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration)

MIGRATIONS=(2076_01_01_000000_seed_user_limit_into_plans)
declare -gA MIG_OK=(
    [2076_01_01_000000_seed_user_limit_into_plans]="data only, as pgsql_admin: two reads of model_has_roles + users + invitations (the head count is inlined in both statements) (site_id > 0, websites in the trash left out); INSERT INTO plan_features (plan_id, 'users.max', max(10, largest headcount on the plan)) ON CONFLICT DO NOTHING for every plan; for each website of a customer with no plan whose headcount is above 10, INSERT INTO site_features (its headcount) ON CONFLICT DO NOTHING + one tenant_history row per override actually inserted (RETURNING); no DDL, no lock beyond row inserts; down() is a no-op"
)
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local html code r ua
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    # #721: every plan carries the people limit, never below 10
    expect "gói nào cũng có giới hạn số người (#721)" \
        "$(q "SELECT count(*) FROM plans p WHERE NOT EXISTS (SELECT 1 FROM plan_features f WHERE f.plan_id = p.id AND f.feature_key = 'users.max')")" "0"
    expect "không gói nào dưới 10 người (#721)" \
        "$(q "SELECT count(*) FROM plan_features WHERE feature_key = 'users.max' AND (jsonb_typeof(value) <> 'number' OR value::numeric < 10)")" "0"
    # the seed's outcome: no website already holds more members than its limit (site override, else plan, else 10)
    expect "không website nào vượt giới hạn số người (#721)" \
        "$(q "SELECT count(*) FROM sites s JOIN tenants t ON t.id = s.tenant_id LEFT JOIN plan_features pf ON pf.plan_id = t.plan_id AND pf.feature_key = 'users.max' LEFT JOIN site_features sf ON sf.site_id = s.id AND sf.feature_key = 'users.max' WHERE s.deleted_at IS NULL AND (SELECT count(DISTINCT lower(u.email::text)) FROM model_has_roles m JOIN users u ON u.id = m.model_id WHERE m.site_id = s.id AND m.model_type = 'App\\Modules\\Identity\\Models\\User') > coalesce(sf.value::numeric, pf.value::numeric, 10)")" "0"
    r=$(art tinker --execute='echo __("rbac.invitation.error_user_limit") !== "rbac.invitation.error_user_limit" && __("rbac.invitation.error_user_limit_join") !== "rbac.invitation.error_user_limit_join" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "hai câu báo đủ số người đã có trong bản dịch (#721)" "$r" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/users")
    expect "trang Thành viên chuyển người chưa đăng nhập đi đăng nhập (giữ như cũ)" "$code" "302"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    # earlier releases stay as they were
    r=$(art tinker --execute='echo __("entries.restored", ["noun" => "x"]) === "Đã khôi phục x" || __("entries.restored", ["noun" => "x"]) === "The x was restored" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "câu báo danh sách bài gọi đúng tên (#719)" "$r" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m15=$RANDOM")
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
