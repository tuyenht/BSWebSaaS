# Concurrency tests (Gate #1)

`ContextIsolationTest` is the **deterministic CI gate**: it interleaves site A/B
contexts over one pooled connection and asserts no leakage, singleton reset, and
that the three documented failure scenarios (design.md §5) are caught. It is fast
and never flaky.

## Real-Octane HTTP hammer (on-demand, gold standard)

To prove isolation under a *live* FrankenPHP/Octane worker pool (the only test that
exercises real cross-request PHP-singleton reset), run the app and hammer two hosts
in parallel against `/_tenancy/probe`, asserting responses never cross:

```bash
docker compose up -d
docker compose run --rm --no-deps app php artisan migrate:fresh --database=pgsql_admin --force
# seed two sites + domains a.test / b.test, then:
seq 1 200 | xargs -P16 -I{} sh -c '
  curl -s -H "Host: a.test" localhost:8080/_tenancy/probe | grep -q "\"site\":1" || echo "LEAK A"
  curl -s -H "Host: b.test" localhost:8080/_tenancy/probe | grep -q "\"site\":2" || echo "LEAK B"
'
```

A leak prints `LEAK A/B`. This is kept out of CI to avoid flakiness; the probe route
and a wired hammer script land with the storefront in a later phase.
