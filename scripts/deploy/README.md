# Prod deploy engine

One reviewed engine (`deploy.sh`) runs every prod deploy; each deploy adds only a small release file
(`releases/<old7>-<target8>.sh`: data plus one-off commands). It replaces the hand-written script per deploy.
The steps and their order are those of `docs/runbooks/prod-deploy.md`, which stays the reference.

## Per deploy

1. Write the release file (copy the newest one under `releases/`, change `OLD`, `TARGET` and the lists).
2. Print the plan. It reads git on the dev machine only; docker, gh, ssh, psql and pnpm are blocked:
   `bash scripts/deploy/deploy.sh plan scripts/deploy/releases/<file>.sh [engine commit]`
3. Review the release file and the plan. The plan ends with the owner's line. That line pins the engine
   commit, the engine's blob hash and the release file's sha256. A file changed after the review is refused.
4. The owner runs the line in tmux. To resume after a stop, he runs the same line with the step number
   added. `rollback` moves the code back to OLD. `dump` takes a fresh database dump.

The first run records OLD, TARGET, the engine blob and the release sha256 in
`plans/reports/deploy-runs/<old7>-<target8>/run.env` (mode 0700). A resume or rollback must match that
record. The log is written next to it, mode 0600. Only one deploy runs at a time (`flock`).

## Classes of changed paths

Every changed path must belong to a class (`class_of` in `deploy.sh`). Otherwise the run stops in step 1,
before the dump.

| Kind | Classes | What the engine does |
|---|---|---|
| auto | code, view, text, assets, config, routes, queue, composer, env-doc, public, tooling | always: `composer install`, `pnpm install --frozen-lockfile` + build, `view:clear`, `optimize`, `octane:reload`, restart horizon + scheduler |
| declare (must be listed in `DECLARED`) | migration | stage each file from TARGET (blob checked) and run it alone while the old code serves; PgBouncer `restart -t 2` after every attempt of a flagged file; retry a lock timeout 3 times; schema delta before/after (new indexes valid, new FKs validated); home + admin login 200 on the old code |
| | schedule | `schedule:list` before and after: the added and removed jobs must equal `SCHEDULE_ADDED` / `SCHEDULE_REMOVED` |
| | sanitizer | `cms:resanitize-bodies --dry-run`; it writes only with `RESANITIZE=apply`, then runs `media:reconcile-content-usage` |
| | permission | `rbac:sync` after the reload |
| | composer-lock, npm, data, content-pack, ops-script | listed for review; content packs are reported only, never loaded |
| stop | migration-edit (edited or deleted migration), server (docker, deploy/, compose, Dockerfile, octane, artisan, public/index.php), unknown | stop |

Migration flags come from the file without its `down()`: `raw` (hand-written SQL), `nonadd` (drop, rename,
change, NOT NULL…), `table` (touches a table: PgBouncer restart), and `concurrent`
(`$withinTransaction = false`). A `raw` or `nonadd` file needs a reviewed reason in `MIG_OK`. `PGB_SKIP` may
only narrow the restart, with a reason. New `env('X')` names in config, and new keys in `.env.example`, are
compared with the key names of prod `.env` (values are never read). A missing key stops unless it is listed
in `ENV_DEFAULT_OK`.

One-off commands (`ONEOFFS`) run after the reload. Each one follows preview → apply → preview again, and the
second preview must print 0. A mark makes sure a resume never applies twice.

## Tests

`bash scripts/deploy/tests/run.sh [empty dir]` builds a fake prod: a synthetic git history and its clone,
stub `docker`/`gh`/`pnpm` under `tests/stub`, and the container prefix `fake_deploy_`. Through the real
launcher it then runs: every class, the plan guard, the hash checks, a full run, a lock-timeout retry, a
failed migration, resumes, an invalid index, an unknown path, an undeclared schedule, a missing env key, the
CI, dump and lock guards, and the profile guards. A test profile may never use the prod prefix, the prod
folder or the real `docker`.

What the stubs cannot show is the real PostgreSQL and PgBouncer behaviour: the "cached plan must not change
result type" error, a CONCURRENTLY wait, a real lock wait. The first deploy with migrations through the
engine therefore gets a line-by-line check of its plan against a hand-made list.
