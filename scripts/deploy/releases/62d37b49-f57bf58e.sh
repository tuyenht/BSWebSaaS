# Release file: prod 62d37b49 → f57bf58e (main on 14/11, after #666). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/62d37b49-f57bf58e.sh
# Range: #660 (release file of the previous deploy), #661 #662 #665 (docs: media host, Worker runbook),
# #663 #664 (the media Worker's own code under deploy/media-worker/, deployed with wrangler on 14/11, not by this engine),
# #666 (the error-notice mailer falls back to the platform's MAIL_* connection as one group when it has no
# host of its own). Config and tests: no migration, no schedule, no new env name. Notices go out once
# MONITORING_MAIL_TO is set (the owner asked for them on 14/11): plans/tools/prod-enable-error-mail-261114.sh adds it
# if missing and refuses a different existing address; if the name was already set, they start at this deploy.
# shellcheck shell=bash disable=SC2034
OLD=62d37b4968eb73f894f9a9892067925f42944ef0
TARGET=f57bf58e633eadfef192e80a515d1c661f02024e
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(worker)   # workers/media: already live through wrangler (version fa87199a), nothing for this engine to run

MIGRATIONS=()
declare -gA MIG_OK=()
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
    local r
    # #666: the notice mailer now has the platform sender's host and sender address (values never printed)
    r=$(art tinker --execute='echo (config("mail.mailers.monitoring.host") ? "H" : "-").(config("mail.mailers.monitoring.from.address") ? "F" : "-");' 2>&1 | tail -1 || true)
    expect "hộp báo lỗi có máy chủ thư và địa chỉ gửi của nền tảng (#666)" "$r" "HF"
}
# Log: plans/reports/deploy-runs/62d37b4-f57bf58e-run-20261114-171646.log (XONG, 98 OK, release smoke 1/1 = HF; full CI on main 43583371584 green).
# Live after: plans/tools/prod-enable-error-mail-261114.sh added MONITORING_MAIL_TO, app and horizon report the notice mailer ON, one test notice sent (log plans/reports/prod-enable-error-mail-261114-171849.log).
