# Release file: prod f57bf58e → b6d0b97d (main on 14/11, after #668). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/f57bf58e-b6d0b97d.sh
# Range: #669 (release file of the previous deploy), #667 (indexes on the plan references + Hoàn tác after
# "Thêm gói"/"Nhân bản"), #670 (hourly mail when the Cloudflare Workers free allowance is getting used up),
# #671 (a category's short card label and a post's free-text byline: two nullable columns), #672 (two
# indexes for the Notifications page tabs, replacing the old unread index), #668 (platform staff change no
# member or role on a customer website; `site:recover-owner` is the audited owner recovery).
# The An Vinh pack's new card label and bylines are loaded after this deploy with site:load-content, not here.
# A pending invitation is refused at acceptance from now on (#668) when its inviter may no longer manage that
# website's members (platform staff with no role there, or a member holding users.invite without users.manage);
# av1's invitations were sent by its own owner, so none is expected to be affected.
# shellcheck shell=bash disable=SC2034
OLD=f57bf58e633eadfef192e80a515d1c661f02024e
TARGET=b6d0b97d462da1df825c451e655c527e4c1e58d9
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration content-pack schedule)

MIGRATIONS=(
    2059_01_01_000000_add_plan_reference_indexes
    2060_01_01_000000_add_card_label_and_author_name_to_translations
    2061_01_01_000000_add_notification_page_indexes
)
declare -gA MIG_OK=(
    [2059_01_01_000000_add_plan_reference_indexes]="3 partial indexes CONCURRENTLY (tenants.plan_id, tenant_history.from_plan_id, tenant_history.to_plan_id WHERE … IS NOT NULL); drops only its own INVALID leftover; down() drops the three"
    [2060_01_01_000000_add_card_label_and_author_name_to_translations]="2 nullable columns, no default, no backfill: taxonomy_translations.card_label varchar(40), entry_translations.author_name varchar(80); each ALTER in its own transaction with a 5s lock timeout; down() refuses while any value is held"
    [2061_01_01_000000_add_notification_page_indexes]="site_notifications_unread_feed (site_id, user_id, id DESC) WHERE read_at IS NULL and site_notifications_kind_feed (site_id, user_id, kind, id DESC), CONCURRENTLY, then DROP INDEX CONCURRENTLY site_notifications_unread (replaced by the first); down() restores it"
)
declare -gA PGB_SKIP=(
    [2059_01_01_000000_add_plan_reference_indexes]="indexes only"
    [2061_01_01_000000_add_notification_page_indexes]="indexes only"
)
# #670: the hourly Workers allowance check (it mails only at 50/80/100 percent of the day's allowance)
SCHEDULE_ADDED=(monitoring:worker-quota)
SCHEDULE_REMOVED=()
# #670: all three have working defaults (account id read from R2_ENDPOINT, 100000 requests, 50,80,100)
ENV_DEFAULT_OK=(CLOUDFLARE_ACCOUNT_ID MONITORING_WORKER_QUOTA_LIMIT MONITORING_WORKER_QUOTA_THRESHOLDS)
# #672: the one index replaced by site_notifications_unread_feed
SCHEMA_REMOVED_OK=("idx|site_notifications|site_notifications_unread|valid")
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local r
    # the engine's step 4 already checks the new columns and indexes; these check the new commands are in the running code
    r=$(art list --raw 2>&1 | grep -c '^monitoring:worker-quota ' || true)
    expect "lệnh đo hạn mức Cloudflare Workers có trong bản chạy (#670)" "$r" "1"
    r=$(art list --raw 2>&1 | grep -c '^site:recover-owner ' || true)
    expect "lệnh lấy lại quyền chủ có ghi vết có trong bản chạy (#668)" "$r" "1"
}
# Log: plans/reports/deploy-runs/f57bf58-b6d0b97d-run-20261114-203241.log (XONG, 141 OK, 0 stops; 3 migrations, PgBouncer restarted after 2060 only; schema diff = the one SCHEMA_REMOVED_OK line; old code served all 18 pages on the new schema; release smoke 2/2; full CI on main 43599172657 green).
