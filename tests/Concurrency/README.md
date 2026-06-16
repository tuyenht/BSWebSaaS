# Concurrency tests (Gate #1)

`ContextIsolationTest` is the **deterministic CI gate**: it interleaves site A/B
contexts over one pooled connection and asserts no leakage, singleton reset, and
that the three documented failure scenarios (design.md §5) are caught. It is fast
and never flaky.

## Real-Octane HTTP hammer (on-demand, gold standard) — `octane-hammer.sh`

The wired hammer (P1.4) proves isolation under a *live* FrankenPHP/Octane worker
pool — the only test that exercises real cross-request PHP-singleton reset. It
provisions two sites, boots Octane, and fires interleaved requests across both
hostnames against the **real storefront render route**, asserting every response
carries only its own site's content:

```bash
docker compose run --rm --no-deps app bash tests/Concurrency/octane-hammer.sh
```

Tunables: `HAMMER_REQUESTS` (default 200), `HAMMER_CONCURRENCY` (24), `HAMMER_PORT`
(8088). A leak prints `❌ TENANT LEAK DETECTED` and exits non-zero. Kept out of the
default CI suite (which runs against Postgres directly, single process) to avoid
flakiness; run it in the dev loop / a dedicated CI stage.
