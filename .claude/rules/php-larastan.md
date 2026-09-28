---
paths:
  - "app/**/*.php"
---

# PHP + Larastan max

- `$x?->prop ?? $default` → `nullsafe.neverNull` (a recurring failure). Narrow first: `$t = …; $v = $t !== null ? $t->prop : $default;`. Never switch to `->` — the value really is null at runtime.
- A cast attribute read in PHP needs an `@property` PHPDoc of its cast type on the model (datetime → `Carbon`, array cast → `array<string, string>`…). Without it Larastan reads a string and "proves" comparisons false (`identical.alwaysFalse`). Declare it on the model; never cast `(array)` at the call site.
  - A runtime guard on an attribute the PHPDoc already narrows: read it through `getAttribute()` (`mixed`) and guard that value. `is_array()` on the typed property fails `function.alreadyNarrowedType`.
- `max(...$list)` on a list that may be empty → seed a floor: `max(0, ...$list)`. `Collection::map()->all()` is `array<int, X>` → wrap it in `array_values()` for `list<X>`.
- `Collection::filter()` with a builtin → wrap it: `fn (mixed $v): bool => is_string($v)` (filter passes both value and key).
- Numeric ids in cache or rate-limit keys use `Cast::int()`, never `Cast::string()`. It returns `''` for ints, merging every user into one slot — the cross-user 2FA bypass fixed in `ba09a92`.
- Cache values:
  - Cast what you read back (Valkey returns strings).
  - Changing the SHAPE of a cached value → version the key; the old value outlives the deploy.
- A guard that reads state a transaction then writes: take `lockForUpdate` FIRST, then read the guarded value inside the transaction. Refresh-then-lock lets two callers pass.
- A domain condition gets its own exception class — never catch `RuntimeException` for it. `QueryException` extends it, so DB failures would surface as the domain message.
- Import classes instead of inline FQCNs in signatures/PHPDoc (Pint `fully_qualified_strict_types`).
