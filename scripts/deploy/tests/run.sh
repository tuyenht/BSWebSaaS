#!/usr/bin/env bash
# Deploy engine tests on a fake prod: a synthetic git history, a clone of it as "prod", stub docker / gh /
# pnpm (tests/stub) that answer from files, the test prefix fake_deploy_ and the real launcher + engine taken
# from a commit. Nothing here reaches a container, GitHub or the real prod checkout.
#   bash scripts/deploy/tests/run.sh [empty work dir]      (exit 0 = every check passed)
set -uo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=${1:-$(mktemp -d)}
mkdir -p "$ROOT"; [ -z "$(ls -A "$ROOT")" ] || { echo "work dir $ROOT is not empty" >&2; exit 2; }
PASS=0 FAIL=0
ok() { if eval "$1"; then PASS=$((PASS + 1)); echo "  ok   $2"; else FAIL=$((FAIL + 1)); echo "  FAIL $2   [$1]"; fi; }
quiet() { "$@" >/dev/null 2>&1; }
gitc() { git -C "$SRC" -c user.name=t -c user.email=t@t commit -q "$@"; }

# ---------------------------------------------------------------- synthetic history + engine commit
SRC=$ROOT/src ORIGIN=$ROOT/origin.git STUB=$ROOT/stub
mkdir -p "$STUB" && cp "$HERE"/stub/* "$STUB"/ && chmod +x "$STUB"/*
git init -q -b main "$SRC"
w() { mkdir -p "$(dirname "$SRC/$1")"; printf '%s\n' "$2" > "$SRC/$1"; }
w composer.json '{"name":"t/t"}'
w composer.lock '{"packages":[{"name":"laravel/framework","version":"v13.1.0"},{"name":"ezyang/htmlpurifier","version":"v4.18.0"}]}'
w package.json '{"name":"t"}'
w vite.config.js "export default { plugins: [laravel({ input: ['resources/css/storefront.css', 'resources/js/app.js'] })] };"
w resources/css/storefront.css 'a{}'
w resources/js/app.js 'x()'
w config/app.php "<?php return ['name' => env('APP_NAME', 'x')];"
w .env.example 'APP_NAME=x'
w routes/console.php "<?php
Schedule::command('audit:months')->daily();"
w routes/web.php '<?php'
w app/Modules/X/Foo.php '<?php'
w app/Modules/Shared/Support/RichTextSanitizer.php '<?php // v1'
w database/migrations/0001_01_01_000000_base.php "<?php Schema::create('sites', fn (\$t) => \$t->id());"
w database/migrations/0002_01_01_000000_more.php "<?php Schema::create('more', fn (\$t) => \$t->id());"
git -C "$SRC" add -A && gitc -m old
OLD=$(git -C "$SRC" rev-parse HEAD)
branch() { git -C "$SRC" checkout -q -B "$1" "$OLD"; }
# the full scenario: two migrations, a new nightly job, the sanitizer, views, text, tests, docs
branch t-full
w database/migrations/2100_01_01_000000_add_col.php "<?php
return new class extends Migration {
    /** a comment that says rename and DROP COLUMN must not count */
    public function up(): void { Schema::table('sites', fn (\$t) => \$t->text('extra')->nullable()); }
    public function down(): void { Schema::table('sites', fn (\$t) => \$t->dropColumn('extra')); }
};"
w database/migrations/2100_01_01_000100_add_idx.php "<?php
return new class extends Migration {
    public \$withinTransaction = false;
    public function up(): void { DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS sites_extra_idx ON sites (extra)'); }
    public function down(): void { DB::statement('DROP INDEX CONCURRENTLY IF EXISTS sites_extra_idx'); }
};"
w routes/console.php "<?php
Schedule::command('audit:months')->daily();
Schedule::command('products:purge-trash')->daily();"
w app/Modules/Shared/Support/RichTextSanitizer.php '<?php // v2'
w themes/default/x.blade.php 'x'; w lang/vi.json '{}'; w tests/Unit/FooTest.php '<?php'; w docs/a.md 'a'
w resources/css/storefront.css 'a{color:red} .sf-newclass{}'
w resources/js/app.js "x(); bsNewThing(); const lazy = () => import('./lazy.js');"
w resources/js/lazy.js 'lazy()'
git -C "$SRC" add -A && gitc -m full
FULL=$(git -C "$SRC" rev-parse HEAD)
# migration shapes for the flag patterns, among them the two real files the first review found unflagged
branch t-flags
w database/migrations/3000_01_01_000000_builder_update.php "<?php
return new class extends Migration {
    public function up(): void {
        Schema::create('site_types', function (Blueprint \$t) { \$t->id(); });
        DB::table('sites')->whereNull('site_type_id')->update(['site_type_id' => 1]);
    }
};"
w database/migrations/3000_01_01_000100_aliased_update.php "<?php
return new class extends Migration {
    public function up(): void {
        foreach (['logo', 'favicon'] as \$column) {
            run_sql(<<<SQL
                    UPDATE site_profiles AS p SET {\$column} = NULL
                    WHERE NOT EXISTS (SELECT 1 FROM media m WHERE m.id = p.{\$column})
                SQL);
        }
    }
};"
w database/migrations/3000_01_01_000200_alias_without_as.php "<?php
return new class extends Migration {
    public function up(): void { run_sql('update site_profiles p set x = null'); }
};"
w database/migrations/3000_01_01_000300_add_only.php "<?php
return new class extends Migration {
    public function up(): void {
        Schema::table('sites', function (Blueprint \$t) {
            \$t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete()->onUpdate('cascade');
            \$t->timestamp('seen_at')->nullable()->useCurrentOnUpdate();
        });
    }
    public function down(): void { DB::table('sites')->update(['x' => 1]); }
};"
w database/migrations/3000_01_01_000400_eloquent.php "<?php
return new class extends Migration {
    public function up(): void { Schema::create('things', fn (\$t) => \$t->id()); Role::create(['name' => 'x']); }
};"
w database/migrations/3000_01_01_000500_pdo.php "<?php
return new class extends Migration {
    public function up(): void { \$this->conn()->getPdo()->exec('delete from things'); }
};"
w database/migrations/3000_01_01_000600_insert_using.php "<?php
return new class extends Migration {
    public function up(): void { \$q->from('a')->insertUsing(['x'], \$sub); }
};"
# the write shapes the second review listed: Eloquent destroy, the quiet and OrFail instance writes, restore,
# touch, and a command or seeder run from a migration
n=0
for body in "Media::destroy([1, 2]);" "\$m->saveQuietly();" "\$m->updateQuietly(['x' => 1]);" "\$m->deleteQuietly();" \
    "\$m->restore();" "\$m->touch();" "Artisan::call('site:link-contact-page');" "\$this->call(RoleSeeder::class);" \
    "\$m->forceDeleteQuietly();" "\$m->updateOrFail(['x' => 1]);"; do
    w "database/migrations/3000_01_01_001${n}00_write_$n.php" "<?php
return new class extends Migration {
    public function up(): void { $body }
};"
    n=$((n + 1))
done
# the third review's shapes: relation writes, statics that load a model to write it, incrementEach, forceDestroy, push
n=0
for body in "\$s->roles()->create(['x' => 1]);" "\$s->roles()->attach(1);" "\$s->roles()->sync([1]);" "\$s->roles()->saveMany([]);" \
    "Site::all();" "Site::find(1);" "Site::findOrFail(1);" "\$m->incrementEach(['n' => 1]);" "Media::forceDestroy([1]);" "\$m->push();"; do
    w "database/migrations/3000_01_01_002${n}00_write2_$n.php" "<?php
return new class extends Migration {
    public function up(): void { $body }
};"
    n=$((n + 1))
done
git -C "$SRC" add -A && gitc -m flags; FLAGSC=$(git -C "$SRC" rev-parse HEAD)
branch t-unknown; w weird/thing.txt x; w app/Modules/X/Foo.php '<?php // 2'; git -C "$SRC" add -A && gitc -m unknown; UNKNOWN=$(git -C "$SRC" rev-parse HEAD)
branch t-sched; w routes/console.php "<?php
Schedule::command('audit:months')->daily();
Schedule::command('x:new')->daily();"; git -C "$SRC" add -A && gitc -m sched; SCHED=$(git -C "$SRC" rev-parse HEAD)
branch t-env; w config/app.php "<?php return ['name' => env('APP_NAME', 'x'), 'k' => env('NEW_KEY', 'd')];"; git -C "$SRC" add -A && gitc -m env; ENVK=$(git -C "$SRC" rev-parse HEAD)
branch t-worker; w deploy/media-worker/src/index.js "export default {};"; git -C "$SRC" add -A && gitc -m worker; WORKER=$(git -C "$SRC" rev-parse HEAD)
branch t-kinds
for p in database/content-packs/p/pages.json package.json resources/js/app.js routes/web.php config/octane.php \
    docker-compose.prod.yml deploy/Caddyfile deploy/media-worker/src/index.js app/Modules/Rbac/Registry/PermissionRegistry.php themes/default/y.blade.php \
    lang/en.json scripts/backup-prod-db.sh scripts/other.mjs tests/Unit/BarTest.php docs/b.md weird/thing.txt; do w "$p" changed; done
w "docs/Biểu mẫu.dc.html" x; w public/x.php '<?php'; w public/.htaccess x; w public/vendor/.htaccess x; w public/robots.txt x
w composer.lock '{"packages":[{"name":"laravel/framework","version":"v13.2.0"}]}'
w database/migrations/0001_01_01_000000_base.php '<?php // edited'
git -C "$SRC" rm -q database/migrations/0002_01_01_000000_more.php
w database/migrations/2200_01_01_000000_x.php '<?php'
git -C "$SRC" add -A && gitc -m kinds; KINDS=$(git -C "$SRC" rev-parse HEAD)
# the engine under test, committed on its own branch (the tmux line pins this commit)
branch engine
mkdir -p "$SRC/scripts/deploy" && cp "$HERE/../deploy.sh" "$HERE/../launch.sh" "$SRC/scripts/deploy/"
git -C "$SRC" add -A && gitc -m engine
ECOMMIT=$(git -C "$SRC" rev-parse HEAD) EBLOB=$(git -C "$SRC" rev-parse HEAD:scripts/deploy/deploy.sh)
git clone -q --bare "$SRC" "$ORIGIN"
# the engine commit is merged: origin/main of the dev repo contains it (launch.sh refuses any other commit)
git -C "$ORIGIN" update-ref refs/heads/main "$ECOMMIT"
git -C "$SRC" remote add origin "$ORIGIN" && git -C "$SRC" fetch -q origin
# the same engine blob on a commit that never reached origin/main
git -C "$SRC" checkout -q -b engine-unmerged engine; w scripts/deploy/x.txt x; git -C "$SRC" add -A && gitc -m unmerged
UNMERGED=$(git -C "$SRC" rev-parse HEAD); git -C "$SRC" checkout -q engine

# ---------------------------------------------------------------- one fake prod per scenario
N=0
fresh() { # fresh <target>: a new fake prod at OLD, its fake DB files, profile and runs folder
    N=$((N + 1)); S=$ROOT/s$N; FAKE=$S/fake PROD=$S/prod RUNS=$S/runs TGT=$1
    mkdir -p "$FAKE" "$S/bk" "$RUNS"
    git clone -q "$ORIGIN" "$PROD" && git -C "$PROD" checkout -q -B main "$OLD"
    printf 'APP_NAME=x\nAPP_KEY=secret-not-printed\n' > "$PROD/.env"
    printf 'DB_ADMIN_PASSWORD=x\n' > "$S/secret.env"
    ls "$PROD/database/migrations" | sed 's/\.php$//' > "$FAKE/migrations.txt"
    printf '%s\n' 'col|sites|id|bigint|notnull' 'idx|sites|sites_pkey|valid' 'con|sites|sites_pkey|p|validated' > "$FAKE/catalog.txt"
    echo 'col|sites|extra|text|null' > "$FAKE/catalog-add-2100_01_01_000000_add_col"
    echo 'idx|sites|sites_extra_idx|valid' > "$FAKE/catalog-add-2100_01_01_000100_add_idx"
    echo 1 > "$FAKE/oneoff-count"; echo 2 > "$FAKE/pre-count"
    printf '/bai-1\n/bai-2\n' > "$FAKE/pages"; echo 404 > "$FAKE/code_bai-1"   # release_pages keeps the first that opens
    cat > "$S/profile" <<EOF
PROD=$PROD PREFIX=fake_deploy_ COMPOSE_PROJECT=fake-deploy BK=$S/bk SECRETS=$S/secret.env DEPLOY_RUNS=$RUNS
REPO=t/t IMAGE=fake:img MIN_DISK_GB=0 LOCK_RETRY_SLEEP=0 STUB_BIN=$STUB
EOF
    release
}
release() { # release [extra lines]: the release file of the scenario
    REL=$S/release.sh
    {
        printf 'OLD=%s\nTARGET=%s\nHOST=fake.test\nPAGES=(/ /lien-he /admin/login)\n' "$OLD" "$TGT"
        if [ "$TGT" = "$FULL" ]; then cat <<'EOF'
DECLARED=(migration schedule sanitizer)
MIGRATIONS=(2100_01_01_000000_add_col 2100_01_01_000100_add_idx)
declare -gA MIG_OK=([2100_01_01_000100_add_idx]="index only, CONCURRENTLY")
declare -gA PGB_SKIP=([2100_01_01_000100_add_idx]="index only")
SCHEDULE_ADDED=(products:purge-trash)
ONEOFFS=(sample)
oneoff_sample() { case "$1" in
    preview) q "SELECT FAKE_ONEOFF" ;;
    apply) [ "$2" = 1 ] || die "apply got '$2', not the preview count"; art fake:oneoff; echo 0 > "$FAKE/oneoff-count" ;;
    verify) touch "$FAKE/verified" ;;
esac; }
ONEOFFS_PRE=(early)
oneoff_early() { case "$1" in
    preview) q "SELECT FAKE_PRE" ;;
    apply) [ "$2" = 2 ] || die "early apply got '$2', not the preview count"; art fake:pre; echo 0 > "$FAKE/pre-count" ;;
    verify) touch "$FAKE/pre-verified" ;;
esac; }
release_guard() { [ ! -e "$FAKE/past-deadline" ] || die "hạn của bản thử đã qua"; }
release_pages() { local p; p=$(first_open "SELECT FAKE_PAGES"); [ -n "$p" ] || die "no page opens"; echo "$p"; }
BUILD_DYNAMIC=(resources/js/lazy.js)
BUILD_MARKERS=("resources/js/app.js bsNewThing" "resources/css/storefront.css sf-newclass")
EOF
        fi
        printf '%s\n' "$@"
    } > "$REL"
}
launch() { # launch [step]: the owner's line, exactly as plan prints it, with the test profile; output in $OUT
    OUT=$S/out-$RANDOM.txt
    ( export DEPLOY_PROFILE=$S/profile DEPLOY_DEV_REPO=$SRC DEPLOY_RUNS_DIR=$RUNS FAKE FAKE_PROD=$PROD PATH="$STUB:$PATH"
      bash -c "$(git -C "$SRC" show "$ECOMMIT:scripts/deploy/launch.sh")" launch "${LBLOB_COMMIT:-$ECOMMIT}" "${LBLOB:-$EBLOB}" "$REL" "${LSHA:-$(sha256sum "$REL" | cut -d' ' -f1)}" "$@" ) > "$OUT" 2>&1
    RC=$?
}
head_is() { [ "$(git -C "$PROD" rev-parse HEAD)" = "$1" ]; }
calls() { grep -c -- "$1" "$FAKE/calls.log" 2>/dev/null || true; }
line_of() { grep -n -- "$1" "$FAKE/calls.log" | sed -n "${2:-1}p" | cut -d: -f1; }
says() { grep -qF -- "$1" "$OUT"; }

echo "== classify: every kind"
EXP="migration A database/migrations/2200_01_01_000000_x.php
migration-edit M database/migrations/0001_01_01_000000_base.php
migration-edit D database/migrations/0002_01_01_000000_more.php
content-pack A database/content-packs/p/pages.json
composer-lock M composer.lock
npm M package.json
assets M resources/js/app.js
routes M routes/web.php
server A config/octane.php
server A docker-compose.prod.yml
server A deploy/Caddyfile
worker A deploy/media-worker/src/index.js
permission A app/Modules/Rbac/Registry/PermissionRegistry.php
view A themes/default/y.blade.php
text A lang/en.json
ops-script A scripts/backup-prod-db.sh
tooling A scripts/other.mjs
tooling A tests/Unit/BarTest.php
tooling A docs/b.md
tooling A docs/Biểu mẫu.dc.html
server A public/x.php
server A public/.htaccess
server A public/vendor/.htaccess
public A public/robots.txt
unknown A weird/thing.txt"
GOT=$(bash "$SRC/scripts/deploy/deploy.sh" classify "$SRC" "$OLD" "$KINDS" | tr '\t' ' ' | sort)
ok '[ "$GOT" = "$(printf "%s\n" "$EXP" | sort)" ]' "path → class table ($(printf '%s\n' "$EXP" | wc -l) kinds incl. unknown, edited and deleted migration)"
[ "$GOT" = "$(printf '%s\n' "$EXP" | sort)" ] || diff <(printf '%s\n' "$EXP" | sort) <(printf '%s\n' "$GOT")
GOT=$(bash "$SRC/scripts/deploy/deploy.sh" classify "$SRC" "$OLD" "$FULL" | cut -f1 | sort -u | tr '\n' ' ')
ok '[ "$GOT" = "assets migration sanitizer schedule text tooling view " ]' "full range classes: $GOT"

echo "== migration flags: data writes are non-additive"
GOT=$(bash "$SRC/scripts/deploy/deploy.sh" flags "$SRC" "$FLAGSC" | grep '^3000_' | tr '\t' ' ')
EXP="3000_01_01_000000_builder_update nonadd table
3000_01_01_000100_aliased_update nonadd
3000_01_01_000200_alias_without_as nonadd
3000_01_01_000300_add_only table
3000_01_01_000400_eloquent nonadd table
3000_01_01_000500_pdo nonadd
3000_01_01_000600_insert_using nonadd
3000_01_01_001000_write_0 nonadd
3000_01_01_001100_write_1 nonadd
3000_01_01_001200_write_2 nonadd
3000_01_01_001300_write_3 nonadd
3000_01_01_001400_write_4 nonadd
3000_01_01_001500_write_5 nonadd
3000_01_01_001600_write_6 nonadd
3000_01_01_001700_write_7 nonadd
3000_01_01_001800_write_8 nonadd
3000_01_01_001900_write_9 nonadd
3000_01_01_002000_write2_0 nonadd
3000_01_01_002100_write2_1 nonadd
3000_01_01_002200_write2_2 nonadd
3000_01_01_002300_write2_3 nonadd
3000_01_01_002400_write2_4 nonadd
3000_01_01_002500_write2_5 nonadd
3000_01_01_002600_write2_6 nonadd
3000_01_01_002700_write2_7 nonadd
3000_01_01_002800_write2_8 nonadd
3000_01_01_002900_write2_9 nonadd"
ok '[ "$GOT" = "$EXP" ]' "builder update (0008 shape), aliased UPDATE (2051 shape), alias without AS, Eloquent create, PDO exec, insertUsing, ::destroy, saveQuietly/updateQuietly/deleteQuietly/forceDeleteQuietly, updateOrFail, restore, touch, Artisan::call, \$this->call → nonadd; onUpdate/nullOnDelete/useCurrentOnUpdate and a write in down() stay additive"
[ "$GOT" = "$EXP" ] || diff <(printf '%s\n' "$EXP") <(printf '%s\n' "$GOT")

echo "== plan: git only, flags, hash line"
fresh "$FULL"; : > "$FAKE/calls.log"
git -C "$SRC" checkout -q engine
PLAN=$S/plan.txt
( export PATH="$STUB:$PATH" FAKE; bash "$SRC/scripts/deploy/deploy.sh" plan "$REL" ) > "$PLAN" 2>&1; RC=$?
ok '[ "$RC" = 0 ]' "plan exits 0"
ok '[ ! -s "$FAKE/calls.log" ]' "plan called no docker, gh or pnpm"
ok 'grep -q "2100_01_01_000000_add_col: \[table\] PgBouncer: restart" "$PLAN"' "add_col flagged table only (down() and comments ignored), restart"
ok 'grep -q "add_idx: \[raw nonadd table concurrent\] PgBouncer: no-restart (PGB_SKIP: index only)" "$PLAN"' "add_idx raw + concurrent, restart narrowed"
ok 'grep -qF "launch $ECOMMIT $EBLOB $REL $(sha256sum "$REL" | cut -d" " -f1)" "$PLAN"' "the owner line carries commit, engine blob and release sha256"
ok 'grep -q "một lần trước khi nạp lại.*: early" "$PLAN" && grep -q "optimize, một lần trước khi nạp lại (code mới, worker cũ còn phục vụ): early, octane:reload" "$PLAN"' "the plan names the pre-reload one-off between optimize and octane:reload"
release 'unset "MIG_OK[2100_01_01_000100_add_idx]"'
( export PATH="$STUB:$PATH" FAKE; bash "$SRC/scripts/deploy/deploy.sh" plan "$REL" ) > "$PLAN" 2>&1; RC=$?
ok '[ "$RC" = 1 ] && grep -q "MIG_OK" "$PLAN"' "raw SQL migration without a reviewed reason stops"
release 'docker() { echo LEAK; }; gh run list'
( export PATH="$STUB:$PATH" FAKE; bash "$SRC/scripts/deploy/deploy.sh" plan "$REL" ) > "$PLAN" 2>&1; RC=$?
ok '[ "$RC" = 1 ] && grep -q "không được gọi gh" "$PLAN" && [ ! -s "$FAKE/calls.log" ]' "plan mode blocks gh even from the release file"
release 'SCHEMA_REMOVED_OK=("sites extra")'
( export PATH="$STUB:$PATH" FAKE; bash "$SRC/scripts/deploy/deploy.sh" plan "$REL" ) > "$PLAN" 2>&1; RC=$?
ok '[ "$RC" = 1 ] && grep -q "SCHEMA_REMOVED_OK: dòng" "$PLAN"' "a SCHEMA_REMOVED_OK line not in snapshot form stops the plan"
release 'SCHEMA_REMOVED_OK=("col|sites|created_at|timestamp(0) with time zone|null" "col|sites|name|character varying(255)|notnull" "con|sites|sites_owner_fk|f|NOT-VALID")'
( export PATH="$STUB:$PATH" FAKE; bash "$SRC/scripts/deploy/deploy.sh" plan "$REL" ) > "$PLAN" 2>&1; RC=$?
ok '[ "$RC" = 0 ] && grep -qF "timestamp(0) with time zone|null" "$PLAN"' "SCHEMA_REMOVED_OK lines with spaces in the column type (as format_type prints them) pass the plan"
release 'SCHEMA_REMOVED_OK=("col|sites|created_at|timestamp(0) with time zone")'
( export PATH="$STUB:$PATH" FAKE; bash "$SRC/scripts/deploy/deploy.sh" plan "$REL" ) > "$PLAN" 2>&1; RC=$?
ok '[ "$RC" = 1 ] && grep -q "SCHEMA_REMOVED_OK: dòng" "$PLAN"' "a col| line without its null/notnull field stops the plan"
release 'ONEOFFS_PRE=(early missing)'
( export PATH="$STUB:$PATH" FAKE; bash "$SRC/scripts/deploy/deploy.sh" plan "$REL" ) > "$PLAN" 2>&1; RC=$?
ok '[ "$RC" = 1 ] && grep -q "thiếu hàm oneoff_missing" "$PLAN"' "a pre-reload one-off without its function stops the plan"
release 'ONEOFFS_PRE=(sample)'
( export PATH="$STUB:$PATH" FAKE; bash "$SRC/scripts/deploy/deploy.sh" plan "$REL" ) > "$PLAN" 2>&1; RC=$?
ok '[ "$RC" = 1 ] && grep -q "cả ONEOFFS và ONEOFFS_PRE" "$PLAN"' "a one-off in both lists stops the plan"
release; touch "$FAKE/past-deadline"
( export PATH="$STUB:$PATH" FAKE; bash "$SRC/scripts/deploy/deploy.sh" plan "$REL" ) > "$PLAN" 2>&1; RC=$?
rm -f "$FAKE/past-deadline"
ok '[ "$RC" = 1 ] && grep -q "hạn của bản thử đã qua" "$PLAN"' "the plan runs the release guard"
release 'BUILD_MARKERS=("resources/js/app.js notInTheSource")'
( export PATH="$STUB:$PATH" FAKE; bash "$SRC/scripts/deploy/deploy.sh" plan "$REL" ) > "$PLAN" 2>&1; RC=$?
ok '[ "$RC" = 1 ] && grep -q "không có trong resources/" "$PLAN"' "a build marker missing from resources/ at TARGET stops the plan"
release 'BUILD_MARKERS=("resources/js/app.js x()")'
( export PATH="$STUB:$PATH" FAKE; bash "$SRC/scripts/deploy/deploy.sh" plan "$REL" ) > "$PLAN" 2>&1; RC=$?
ok '[ "$RC" = 1 ] && grep -q "đã có ở" "$PLAN"' "a build marker already at OLD (proves nothing) stops the plan"
release
( export PATH="$STUB:$PATH" FAKE; bash "$SRC/scripts/deploy/deploy.sh" plan "$REL" ) > "$PLAN" 2>&1
INNER=$(sed -n "s/^  ! tmux new-session -d -s deploy-[0-9a-f]\{8\}-\$(date +%d%H%M%S) '\(.*\); exec bash'\$/\1/p" "$PLAN")
ok '[ -n "$INNER" ]' "the owner line names its own tmux session (target + start time), never a fixed name"
BADC=$(printf '%040d' 7)
( export DEPLOY_PROFILE=$S/profile DEPLOY_RUNS_DIR=$S/runs FAKE PATH="$STUB:$PATH"
  bash -c "${INNER//show $ECOMMIT:/show $BADC:}" ) > "$S/line.txt" 2>&1; RC=$?
ok '[ -n "$INNER" ] && [ "$RC" != 0 ] && grep -q "DỪNG: không đọc được launch.sh" "$S/line.txt" && [ ! -s "$FAKE/calls.log" ]' "the owner line with an unreadable launcher stops (rc $RC), no empty bash -c"

echo "== launcher: pinned hashes"
fresh "$FULL"
LBLOB=0123456789012345678901234567890123456789 launch
ok '[ "$RC" != 0 ] && says "khác blob đã duyệt" && head_is "$OLD"' "wrong engine blob refuses before anything runs"
LSHA=$(printf '%064d' 0) launch
ok '[ "$RC" != 0 ] && says "khác mã kiểm đã duyệt" && head_is "$OLD"' "wrong release sha256 refuses"
ok '[ ! -s "$FAKE/calls.log" ]' "no stub call before the hash checks pass"
LBLOB_COMMIT=$UNMERGED launch
ok '[ "$RC" != 0 ] && says "chưa nằm trên origin/main" && head_is "$OLD" && [ ! -s "$FAKE/calls.log" ]' "an engine commit not on origin/main is refused (same engine blob)"

echo "== full run on the fake prod"
fresh "$FULL"; launch
ok '[ "$RC" = 0 ] && says "===== XONG"' "deploy ends XONG (rc $RC)"
[ "$RC" = 0 ] || tail -25 "$OUT"
ok 'head_is "$FULL"' "prod HEAD is TARGET"
ok 'grep -qx 2100_01_01_000100_add_idx "$FAKE/migrations.txt" && grep -qx 2100_01_01_000000_add_col "$FAKE/migrations.txt"' "both migrations recorded"
A=$(line_of "MIGRATED 2100_01_01_000000_add_col"); R=$(line_of "restart -t 2 fake_deploy_pgbouncer"); B=$(line_of "MIGRATED 2100_01_01_000100_add_idx")
ok '[ -n "$A" ] && [ -n "$R" ] && [ -n "$B" ] && [ "$A" -lt "$R" ] && [ "$R" -lt "$B" ]' "PgBouncer restarted right after the column migration, before the index one ($A < $R < $B)"
ok '[ "$(calls "restart -t 2 fake_deploy_pgbouncer")" = 2 ]' "two PgBouncer restarts in all (after add_col, at the reload)"
M=$(line_of "MIGRATED 2100_01_01_000100_add_idx"); G=$(line_of "pnpm run build")
ok '[ "$M" -lt "$G" ]' "migrations ran before the code build"
ok 'says "OK   lịch chạy thêm = products:purge-trash"' "schedule diff equals the declared job"
ok 'says "0 would change on 1 site(s)." && [ "$(calls "cms:resanitize-bodies --dry-run")" -ge 1 ] && [ "$(calls "cms:resanitize-bodies$")" = 0 ]' "sanitizer: dry run only"
ok '[ "$(cat "$FAKE/oneoff-count")" = 0 ] && says "OK   sample xem lại sau khi chạy (còn đổi) = 0"' "one-off: preview → apply → re-preview 0"
ok 'says "chỉ mục mới hợp lệ" && says "+ idx|sites|sites_extra_idx|valid"' "schema delta printed and checked"
ok '[ -e "$FAKE/verified" ]' "one-off: apply got the preview count, verify ran"
O=$(line_of "artisan optimize"); P=$(line_of "artisan fake:pre"); L=$(line_of "artisan octane:reload"); Q1=$(line_of "artisan fake:oneoff")
ok '[ -n "$O" ] && [ -n "$P" ] && [ -n "$L" ] && [ "$O" -lt "$P" ] && [ "$P" -lt "$L" ] && [ "$L" -lt "$Q1" ]' "pre-reload one-off ran after optimize, before octane:reload; the other one-off after it ($O < $P < $L < $Q1)"
ok '[ "$(cat "$FAKE/pre-count")" = 0 ] && [ -e "$FAKE/pre-verified" ] && says "OK   early xem lại sau khi chạy (còn đổi) = 0" && [ -s "$RUNS/${OLD:0:7}-${FULL:0:8}/oneoff-pre-early" ]' "pre-reload one-off: preview 2 → apply 2 → re-preview 0 → verify, its own mark"
PB=$RUNS/${OLD:0:7}-${FULL:0:8}/pages-before
ok 'grep -qx "/bai-2 200" "$PB" && ! grep -q "^/bai-1 " "$PB" && says "OK   trang /bai-2 (code cũ trên cấu trúc bảng mới; trước: 200) = 200"' "release_pages: the first page that opens is kept and re-checked on the old code in step 4"
ok 'says "OK   bản build có phần tải sau resources/js/lazy.js = true" && says "OK   bản build: resources/js/app.js có '"'"'bsNewThing'"'"'" && says "OK   bản build: resources/css/storefront.css có '"'"'sf-newclass'"'"'"' "build: lazy chunk and the new strings checked"
LOGF=$(ls "$RUNS"/*-run-*.log | head -1)
ok '[ "$(stat -c %a "$LOGF")" = 600 ] && [ "$(stat -c %a "$RUNS/${OLD:0:7}-${FULL:0:8}")" = 700 ]' "log 0600, state 0700"
ok '! grep -q "secret-not-printed" "$LOGF"' "no .env value in the log"
DUMPS=$(ls "$S/bk" | wc -l)
ok '[ "$DUMPS" = 1 ] && [ "$(stat -c %a "$S/bk/$(ls "$S/bk")")" = 600 ]' "one 0600 dump"
echo "== same deploy: resume refuses another release file, rollback reads the record"
release '# edited after the review'
launch 8
ok '[ "$RC" = 1 ] && says "khác bản ghi lần đầu"' "a resume with a changed release file is refused"
release; launch rollback
ok '[ "$RC" = 0 ] && head_is "$OLD"' "rollback puts the code back on OLD"
ok '[ "$(calls "MIGRATED")" = 2 ]' "rollback ran no migration"
ok '[ "$(calls "artisan fake:pre")" = 1 ] && [ "$(calls "artisan octane:reload")" = 2 ]' "rollback reloads without the pre-reload one-off"

echo "== pre-reload one-off: a stop at the reload, then resume at 7"
fresh "$FULL"; touch "$FAKE/fail-reload-once"; launch
ok '[ "$RC" = 1 ] && says "DỪNG (bước 7)" && [ "$(calls "artisan fake:pre")" = 1 ]' "octane:reload fails after the pre-reload one-off applied"
launch 7
ok '[ "$RC" = 0 ] && says "Bỏ qua early: đã chạy" && [ "$(calls "artisan fake:pre")" = 1 ] && [ "$(calls "artisan fake:oneoff")" = 1 ]' "resume at 7 skips the marked pre-reload one-off and finishes"

echo "== release guard before every step, a resume included"
fresh "$FULL"; touch "$FAKE/past-deadline"; launch
ok '[ "$RC" = 1 ] && says "DỪNG (bước 0)" && says "hạn của bản thử đã qua" && [ ! -s "$FAKE/calls.log" ]' "a first run past the release deadline stops before step 0 does anything"
fresh "$FULL"; touch "$FAKE/bad-dump"; launch
ok '[ "$RC" = 1 ] && says "DỪNG (bước 2)"' "a run stops in step 2"
rm -f "$FAKE/bad-dump"; touch "$FAKE/past-deadline"; launch 2
ok '[ "$RC" = 1 ] && says "DỪNG (bước 2)" && says "hạn của bản thử đã qua" && [ "$(calls "pg_dump")" = 1 ] && head_is "$OLD" && ! says "NGAY"' "a resume from step 2 after the deadline stops before the step runs (no rollback advice: the old code is still on disk)"
rm -f "$FAKE/past-deadline"; launch 2
ok '[ "$RC" = 0 ] && head_is "$FULL"' "the same resume before the deadline finishes"
fresh "$FULL"; touch "$FAKE/fail-reload-once"; launch
ok '[ "$RC" = 1 ] && says "DỪNG (bước 7)" && head_is "$FULL"' "a run stops in step 7 with the new code on disk"
touch "$FAKE/past-deadline"; launch 7
ok '[ "$RC" = 1 ] && says "DỪNG (bước 7)" && says "hạn của bản thử đã qua" && says "chạy \"rollback\" NGAY" && [ "$(calls "artisan fake:pre")" = 1 ]' "a resume after step 5 past the deadline stops before the step and says to roll back now"
rm -f "$FAKE/past-deadline"
fresh "$FULL"; touch "$FAKE/fail-reload-once"; launch
echo 500 > "$FAKE/code_bai-2"; launch 7
ok '[ "$RC" = 1 ] && says "DỪNG (bước 8)" && ! says "Code trên đĩa đã đổi" && ! says "NGAY"' "a step-8 stop after a passing guard does not say the code is half-updated"
rm -f "$FAKE/code_bai-2"

echo "== lock timeout: retried, PgBouncer restarted after every attempt"
fresh "$FULL"; echo 1 > "$FAKE/lockfail-2100_01_01_000000_add_col"; launch
ok '[ "$RC" = 0 ] && says "Chưa lấy được khóa bảng (lần 1)"' "one lock timeout, then success"
ok '[ "$(calls "restart -t 2 fake_deploy_pgbouncer")" = 3 ]' "three restarts (failed attempt, good attempt, reload)"

echo "== failed column migration: PgBouncer restarted, code untouched"
fresh "$FULL"; touch "$FAKE/fail-2100_01_01_000000_add_col"; launch
ok '[ "$RC" = 1 ] && says "DỪNG (bước 4)" && head_is "$OLD"' "stop in step 4, prod still on OLD"
ok '[ "$(calls "restart -t 2 fake_deploy_pgbouncer")" -ge 1 ]' "PgBouncer restarted after the failed attempt"
rm -f "$FAKE/fail-2100_01_01_000000_add_col"; launch 4
ok '[ "$RC" = 0 ] && head_is "$FULL"' "resume at step 4 finishes"
ok '[ "$(calls "MIGRATED 2100_01_01_000000_add_col")" = 1 ]' "each migration ran once"

echo "== stop in step 6, resume at 6"
fresh "$FULL"; touch "$FAKE/fail-build-once"; launch
ok '[ "$RC" = 1 ] && says "DỪNG (bước 6)" && says "Code trên đĩa đã đổi"' "build failure stops with the half-updated warning"
launch 6
ok '[ "$RC" = 0 ] && head_is "$FULL" && [ "$(calls "MIGRATED")" = 2 ]' "resume at 6 finishes without re-running migrations"

echo "== invalid new index stops before the code moves"
fresh "$FULL"; echo 'idx|sites|sites_extra_idx|INVALID' > "$FAKE/catalog-add-2100_01_01_000100_add_idx"; launch
ok '[ "$RC" = 1 ] && says "chỉ mục mới chưa hợp lệ" && head_is "$OLD"' "INVALID index → stop in step 4, code untouched"

echo "== step 4 on the old code: every page of step 0, the exact removal list, no column change without a restart"
fresh "$FULL"; mkdir -p "$FAKE/after-2100_01_01_000000_add_col"; echo 500 > "$FAKE/after-2100_01_01_000000_add_col/code_bai-2"; launch
ok '[ "$RC" = 1 ] && says "DỪNG (bước 4)" && says "trang /bai-2 (code cũ trên cấu trúc bảng mới; trước: 200): được '"'"'500'"'"'" && head_is "$OLD"' "a post that breaks on the old code after the migrations stops in step 4, code untouched"
fresh "$FULL"; echo 'idx|sites|sites_pkey|valid' > "$FAKE/catalog-del-2100_01_01_000000_add_col"; launch
ok '[ "$RC" = 1 ] && says "bớt/đổi mà không khai báo: idx|sites|sites_pkey|valid" && says "SCHEMA_REMOVED_OK" && head_is "$OLD"' "a dropped index stops although MIG_OK names another file"
fresh "$FULL"; echo 'idx|sites|sites_pkey|valid' > "$FAKE/catalog-del-2100_01_01_000000_add_col"; release 'SCHEMA_REMOVED_OK=("idx|sites|sites_pkey|valid")'; launch
ok '[ "$RC" = 0 ] && says "bớt/đổi đúng SCHEMA_REMOVED_OK (1 dòng)"' "the same drop listed exactly passes"
fresh "$FULL"; echo 'idx|sites|sites_pkey|valid' > "$FAKE/catalog-del-2100_01_01_000000_add_col"; release 'SCHEMA_REMOVED_OK=("idx|sites|sites_pkey|valid" "col|sites|id|bigint|notnull")'; launch
ok '[ "$RC" = 1 ] && says "khai báo mà không bớt/đổi: col|sites|id|bigint|notnull"' "a listed line that did not go stops (exact compare)"
fresh "$FULL"; echo 'col|sites|created_at|timestamp(0) with time zone|null' >> "$FAKE/catalog.txt"; echo 'col|sites|created_at|timestamp(0) with time zone|null' > "$FAKE/catalog-del-2100_01_01_000000_add_col"
release 'SCHEMA_REMOVED_OK=("col|sites|created_at|timestamp(0) with time zone|null")'; launch
ok '[ "$RC" = 0 ] && says "- col|sites|created_at|timestamp(0) with time zone|null" && says "bớt/đổi đúng SCHEMA_REMOVED_OK (1 dòng)"' "a dropped timestamptz column listed exactly (type with spaces) passes step 4"
fresh "$FULL"; echo 'col|sites|extra2|text|null' >> "$FAKE/catalog-add-2100_01_01_000100_add_idx"; launch
ok '[ "$RC" = 1 ] && says "đổi cột của bảng nhưng không khởi động lại PgBouncer" && says "PGB_SKIP: index only" && says "+ col|sites|extra2|text|null" && head_is "$OLD"' "PGB_SKIP on a file that changed a column is refused"
ok '[ "$(calls "restart -t 2 fake_deploy_pgbouncer")" = 2 ]' "the refusal restarts PgBouncer (after add_col, at the stop)"
fresh "$FULL"; touch "$FAKE/build-empty"; launch
ok '[ "$RC" = 1 ] && says "DỪNG (bước 6)" && says "chưa có chuỗi mới '"'"'bsNewThing'"'"'"' "a build without the new strings stops in step 6"

echo "== the worked release file's own checks (sourced with stub helpers)"
REAL=$HERE/../releases/534b709-6e4febb6.sh
TOP=$(git -C "$HERE" rev-parse --show-toplevel)
rel_unit() { # rel_unit <snippet>: the real release file with stub die/expect/note, then the snippet; output in $OUT
    OUT=$ROOT/rel-$RANDOM.txt
    ( set -Eeuo pipefail
      die() { echo "DIE: $*"; exit 1; }
      expect() { if [ "$2" = "$3" ]; then echo "OK   $1 = $2"; else die "$1: được '$2', cần '$3'"; fi; }
      note() { :; }
      # shellcheck disable=SC1090
      . "$REAL"
      eval "$1" ) > "$OUT" 2>&1
    RC=$?
}
rel_unit 'date() { echo 2026-12-09; }; trash_deadline'
ok '[ "$RC" = 1 ] && says "DIE: hôm nay 2026-12-09"' "deploy on the trash deadline (2026-12-09) stops"
rel_unit 'date() { echo 2026-12-08; }; trash_deadline'
ok '[ "$RC" = 0 ]' "the day before the deadline passes"
rel_unit 'date() { echo 2026-12-10; }; release_guard'
ok '[ "$RC" = 1 ] && says "DIE: hôm nay 2026-12-10"' "the release guard (run before every step) is the trash deadline"
rel_unit '[ "${#ONEOFFS_PRE[@]}" = 0 ] && echo empty'
ok '[ "$RC" = 0 ] && says empty' "the worked release has no pre-reload one-off"
rel_unit "date() { echo 2026-11-20; }; git() { echo \"    public const string SINCE = '2026-11-10';\"; }; release_git x"
ok '[ "$RC" = 1 ] && says "ProductTrash::SINCE"' "a ProductTrash::SINCE other than 2026-11-09 stops"
rel_unit "date() { echo 2026-11-20; }; release_git '$TOP'"
ok '[ "$RC" = 0 ] && says "= 2026-11-09"' "ProductTrash::SINCE at the real TARGET is 2026-11-09"
rel_unit "art() { printf '| logo | filled | /a.png | k1 |\n| favicon | not found | /b.png | |\n'; }; oneoff_fill_brand apply 2"
ok '[ "$RC" = 1 ] && says "bằng số xem trước"' "brand fill: filled ≠ would fill stops"
rel_unit "art() { printf '| logo | filled | /a.png | k1 |\n'; }; oneoff_fill_brand apply 1"
ok '[ "$RC" = 0 ]' "brand fill: filled = would fill passes"
rel_unit 'q() { case "$1" in *contact_page*) echo 01AAA ;; *) echo 01BBB ;; esac; }; oneoff_link_contact verify'
ok '[ "$RC" = 1 ] && says "catalog/contact_page"' "contact page: a recorded ulid other than the /lien-he page stops"
rel_unit 'q() { echo 01AAA; }; oneoff_link_contact verify'
ok '[ "$RC" = 0 ]' "contact page: the /lien-he ulid recorded passes"
rel_unit "q() { printf '%s\n' \"\$1\" > '$ROOT/layout.sql'; echo '0|1'; }; release_rollback"
ok '[ "$RC" = 0 ] && says "LƯU Ý" && grep -q "list_template <> '"''"'" "$ROOT/layout.sql" && grep -q "detail_template <> '"''"'" "$ROOT/layout.sql" && grep -q "template_override <> '"''"'" "$ROOT/layout.sql"' "rollback warning counts list/detail layouts and entry overrides, empty values excluded"

echo "== unknown kind, undeclared class, env key"
fresh "$UNKNOWN"; launch
ok '[ "$RC" = 1 ] && says "DỪNG (bước 1)" && says "weird/thing.txt" && head_is "$OLD" && [ "$(calls "pg_dump")" = 0 ]' "unknown path stops in step 1, before any dump or write"
fresh "$SCHED"; launch
ok '[ "$RC" = 1 ] && says "nhóm cần khai báo: thấy '"'"'schedule'"'"'"' "an undeclared schedule change stops"
fresh "$SCHED"; release 'DECLARED=(schedule)' 'SCHEDULE_ADDED=(x:new)'; launch
ok '[ "$RC" = 0 ] && says "OK   lịch chạy thêm = x:new"' "declared schedule change passes and is checked after the reload"
fresh "$WORKER"; launch
ok '[ "$RC" = 1 ] && says "nhóm cần khai báo: thấy '"'"'worker'"'"'"' "an undeclared media Worker change stops"
fresh "$WORKER"; release 'DECLARED=(worker)'; launch
ok '[ "$RC" = 0 ]' "a declared media Worker change lets the app deploy go on"
fresh "$ENVK"; launch
ok '[ "$RC" = 1 ] && says "thiếu khóa mới: NEW_KEY"' "a new env key missing on prod stops"
fresh "$ENVK"; release 'ENV_DEFAULT_OK=(NEW_KEY)'; launch
ok '[ "$RC" = 0 ]' "the same key allowed to default passes"

echo "== step 0 guards"
fresh "$FULL"; echo '[{"event":"pull_request","status":"completed","conclusion":"success"}]' > "$FAKE/ci.json"; launch
ok '[ "$RC" = 1 ] && says "CI đầy đủ" && head_is "$OLD"' "no full CI run on TARGET stops"
fresh "$FULL"; touch "$FAKE/bad-dump"; launch
ok '[ "$RC" = 1 ] && says "DỪNG (bước 2)"' "a broken dump stops in step 2"
fresh "$FULL"; flock "$RUNS/.lock" sleep 5 & LP=$!; sleep 0.5; launch; wait "$LP"
ok '[ "$RC" = 1 ] && says "một lần deploy khác đang chạy"' "a second deploy at the same time is refused"

echo "== profile guards (never the prod prefix, path or real docker)"
fresh "$FULL"; sed -i 's/PREFIX=fake_deploy_/PREFIX=bsweb_prod_/' "$S/profile"; launch
ok '[ "$RC" = 1 ] && says "tiền tố container của prod"' "test profile with the prod prefix refused"
fresh "$FULL"; sed -i "s#^PROD=[^ ]*#PROD=/opt/bswebsaas/prod/x#" "$S/profile"; launch
ok '[ "$RC" = 1 ] && says "trỏ vào thư mục prod"' "test profile pointing into the prod folder refused"
fresh "$FULL"; sed -i "s#STUB_BIN=.*#STUB_BIN=$ROOT/elsewhere#" "$S/profile"; launch
ok '[ "$RC" = 1 ] && says "lệnh docker giả" && [ ! -s "$FAKE/calls.log" ]' "test profile without the stub docker refused"

echo
echo "passed $PASS, failed $FAIL   (work dir $ROOT)"
[ "$FAIL" = 0 ]
