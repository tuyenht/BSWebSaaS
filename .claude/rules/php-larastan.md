---
paths:
  - "app/**/*.php"
---

# PHP + Larastan max

- `$x?->prop ?? $default` → `nullsafe.neverNull` (a recurring failure). Narrow first: `$t = …; $v = $t !== null ? $t->prop : $default;`. Never switch to `->` — the value really is null at runtime.
- Model datetime columns compared in PHP need an `@property` PHPDoc, or Larastan reads them as strings and "proves" the comparison false.
- `max(...$list)` on a list that may be empty → seed a floor: `max(0, ...$list)`. `Collection::map()->all()` is `array<int, X>` → wrap it in `array_values()` for `list<X>`.
- `Collection::filter()` with a builtin → wrap it: `fn (mixed $v): bool => is_string($v)` (filter passes both value and key).
- Numeric ids in cache or rate-limit keys use `Cast::int()`, never `Cast::string()`. It returns `''` for ints, merging every user into one slot — the cross-user 2FA bypass fixed in `ba09a92`.
- Cache values:
  - Cast what you read back (Valkey returns strings).
  - Changing the SHAPE of a cached value → version the key; the old value outlives the deploy.
- A guard that reads state a transaction then writes: take `lockForUpdate` FIRST, then read the guarded value inside the transaction. Refresh-then-lock lets two callers pass.
- A domain condition gets its own exception class — never catch `RuntimeException` for it. `QueryException` extends it, so DB failures would surface as the domain message.
- Import classes instead of inline FQCNs in signatures/PHPDoc (Pint `fully_qualified_strict_types`).
