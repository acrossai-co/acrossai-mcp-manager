# Phase 0 Research — Connection Method Discovery API

**Feature**: F035 | **Date**: 2026-07-26 | **Plan**: [plan.md](./plan.md)

Three implementation-detail decisions the spec explicitly deferred to plan-phase. Each is documented below with Decision / Rationale / Alternatives Considered.

---

## R1 — `_doing_it_wrong` version-tag value

**Decision**: `'0.1.9'`.

**Rationale**: F035 targets the release immediately following F034's `0.1.8`. Precedent: F034 pinned its `_doing_it_wrong` version tag to `'0.1.7'` (its own target release) so operator-visible debug output cites the version that introduced the semantic. Matching precedent keeps debug-log grep patterns consistent across features (operators can `grep 'acrossai_mcp.*0\.1\.9'` to find every F035-introduced warning site).

**Alternatives Considered**:
- `'0.1.8'` (current release) — misleading; F035 isn't shipped in 0.1.8.
- `'@experimental'` / omit the version — WP core `_doing_it_wrong` requires a `$version` string; omitting would trip PHP notices.
- `'1.0.0'` (frozen-API target) — future-tense; obscures the actual introduction release for debugging.

**Impact**: Two `_doing_it_wrong()` call sites (one in `get_npm_methods()` for FR-009b, one in `get_all()` for FR-012a) both pass `'0.1.9'` as the third argument.

---

## R2 — Memoization implementation shape

**Decision**: Non-static instance property `private ?array $assembled_cache = null` on the singleton `ConnectionMethodRegistry`, reset via `public function flush_cache(): void`.

**Rationale**:
- **Non-static instance property** — the class is a singleton (FR-002), so instance-scoped and class-scoped state are functionally equivalent at runtime. Non-static plays better with PHPUnit test isolation (a rare `new self()` bypass in tests would still get a fresh cache) and PHPStan level 8 (fewer static-analysis edge cases around static-state mutation).
- **`?array` nullable init** — the null sentinel distinguishes "not yet computed" from "computed to empty" cleanly, matching the WP-idiomatic `if ( null === $this->assembled_cache )` guard shape.
- **`flush_cache()` production-shape name** — B23 anti-pattern warns against `_reset_for_tests()` / `_for_testing()` naming; production code never depends on the reset, but the name reads sensibly if some future consumer legitimately needs to invalidate the cache mid-request (e.g., a companion plugin dynamically registering a new filter callback). Test bootstrap calls `ConnectionMethodRegistry::instance()->flush_cache()` in `setUp()`.

**Alternatives Considered**:
- **`private static array $cache`** — introduces static-state pollution across tests; requires reflection or a class-level reset helper. Rejected for testability + PHPStan cleanliness.
- **WP object cache (`wp_cache_get`/`wp_cache_set`)** — cross-request caching is not required (each admin request cost is already ≤13 DTOs); adds a WP-cache-flush concern for tests. Rejected for scope creep.
- **No memoization** — FR-005 explicitly mandates memoization; the constraint is spec-locked.
- **`_reset_for_tests()`** — B23 anti-pattern; explicitly rejected.

**Impact**:
- One private property + one 3-line public method on `ConnectionMethodRegistry`.
- Test `setUp()` calls `flush_cache()` once per test method for isolation.
- Zero production callers of `flush_cache()` (companion plugins CAN call it if they need mid-request invalidation — it's a supported but rarely-used surface).

---

## R3 — Test file layout

**Decision**: Two test files under `tests/phpunit/Public/Discovery/`:

1. `ConnectionMethodRegistryTest.php` — one test class covering all behaviours of the registry via ~10 focused test methods (data-provider-parameterized where appropriate for the "unified DTO shape stable across all three categories" assertion — SC-002).
2. `NpmDefaultHelperTest.php` — one small test class asserting `NpmClientBlock::get_default_npm_method()` returns the expected DTO shape (guards SC-007 template drift; separate file because it lives in a different SUT namespace and shouldn't clutter the primary registry-focused file).

**Rationale**:
- Two files is the natural cleavage: registry-focused vs helper-focused. Splitting further (per-method micro-files) fragments the test surface for readers who want to understand "how is the registry supposed to behave?" — one primary file answers that question completely.
- Consolidating into one file would mix `ConnectionMethodRegistry` SUT with `NpmClientBlock` SUT, muddying "what is being tested?" at a glance.
- F034 established the same shape (one primary `GetAllRegisteredClientsTest.php` + one data-provider `ConcreteClientMetadataTest.php`). Consistent with in-project precedent.

**Alternatives Considered**:
- **One combined file** — mixes SUTs.
- **Per-method micro-files** — 8+ tiny files; readers can't grok the contract from any one file.
- **Per-category files** (`NpmDiscoveryTest.php` + `ClientsDiscoveryTest.php` + `AiConnectorsDiscoveryTest.php`) — mirrors the DTO categories but hides cross-category invariants (SC-002 unified shape, SC-004 filter-fires-once-only-in-get_all). Cross-category tests would have no natural home.

**Impact**: Two new files in `tests/phpunit/Public/Discovery/`; test count ~10–12 methods total; total assertions ~30–40 (bounded by SC coverage).
