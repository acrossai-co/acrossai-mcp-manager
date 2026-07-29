# Phase 0 Research — Per-Server Shortcode + Block Embeds Tab

**Feature**: F037 | **Date**: 2026-07-27 | **Plan**: [plan.md](./plan.md)

Four implementation-detail decisions the spec explicitly deferred to plan-phase.

---

## R1 — `_doing_it_wrong` version-tag value

**Decision**: `'0.1.10'`.

**Rationale**: F037 ships in the release immediately following F035's `0.1.9`. Precedent: F034 pinned `'0.1.7'`, F035 pinned `'0.1.9'` — each feature cites the version that introduced its `_doing_it_wrong` call sites. Operator debug-log grep patterns remain consistent (`grep 'acrossai_mcp.*0\.1\.10'` isolates every F037-introduced warning site).

**Alternatives Considered**:
- `'0.1.9'` (current release on `main`) — misleading; F037 code isn't shipped in 0.1.9.
- `'@experimental'` — WP core requires a string version arg; omitting trips PHP notices.

**Impact**: All `_doing_it_wrong()` call sites (AbstractEmbedTransport enumeration validation × 2, GC helper × 0) pass `'0.1.10'` as the third argument.

---

## R2 — Memoization on `is_enabled_for_server()`

**Decision** (revised per Pivots A + B): Static per-request cache keyed on **`"{$server_id}:{$transport_key}:{$dto_slug}"` (3-part string)** with `public static function flush_cache(): void` reset helper. Original 2-part key `"{$server_id}:{$transport_key}"` was widened when Pivot A added the `$dto_slug` gate argument. `flush_cache()` now resets TWO memoized structures — `$enabled_cache` (gate results) AND `$meta_map` (added per Pivot B — transport_key → storage_key + is_single lookup for static callers who don't hold a transport instance).

**Rationale**:
- **Cache scope**: `is_enabled_for_server()` is called multiple times per request (once per DTO occurrence per category). A page with 3 shortcodes rendering 8 DTOs total → 24 lookups; naive impl is 24 DB reads. Memoization collapses to ≤24 reads worst case (one per unique triple on the page); typical duplicate rate collapses it to <10 reads.
- **Cache shape**: `array<string, bool>` keyed by `"{$server_id}:{$transport_key}:{$dto_slug}"`. Simpler than nested array + faster lookup than nested `isset()` chain. Bounded — at most (server_count × transport_count × dto_count) entries per request; typical ceiling ~50 for a busy page.
- **Reset helper naming**: `flush_cache()` per B23 anti-pattern (never `_reset_for_tests()`). Callable by test bootstrap AND by companion plugins that legitimately mutate state mid-request.
- **Static-not-instance**: `AbstractEmbedTransport` subclasses are A11 pure services (stateless value producers per plan §Constitution Check A2 note). The cache is a class-level cache, not an instance concern.

**Alternatives Considered**:
- **WP object cache (`wp_cache_get`/`wp_cache_set`)** — cross-request caching not required (per-request lookup cost is O(1) after first read); adds cache-flush concern for tests. Rejected for scope creep.
- **No memoization** — 9 DB reads per page for a page with 3 shortcodes; unnecessary I/O.
- **Per-instance cache** — subclasses are stateless (A11); introducing per-instance state to hold the cache would violate A11 exemption.

**Impact**: One `private static array $cache = array()` + one 3-line `flush_cache()` method on `AbstractEmbedTransport`. Test `setUp()` calls `AbstractEmbedTransport::flush_cache()` for isolation.

---

## R3 — Observability action firing timing + fail-forward

**Decision**: Fire actions AFTER the DB commit, INSIDE a `try/catch` per-listener. One misbehaving listener MUST NOT block others OR roll back the DB write.

**Rationale**:
- **After DB commit**: The action name past tense (`_toggled`) implies the state change already happened. Firing before commit + rolling back on listener throw would create observability lies ("the toggle changed" fired but the DB says otherwise).
- **`try/catch` per listener**: WP core's `do_action` does NOT wrap listeners — an uncaught exception in listener N kills listener N+1. F037's audit-log integration is second-order (nice-to-have, not correctness-critical); a broken audit-log listener MUST NOT break the plugin's admin UX. Matches F015 D19 fail-open observability pattern.
- **Test coverage per SC-010**: parameterized test with 4 scenarios (master-only, transports-only, both, no-op) asserts exact action fire counts.

**Alternatives Considered**:
- **Fire before commit + rollback on listener throw** — creates observability lies; complicates transaction management; no precedent in the codebase.
- **No try/catch (relying on WP core's do_action)** — a companion plugin's broken listener could crash the admin save UX with a fatal error. Unacceptable for security-adjacent audit.
- **Use `apply_filters` instead of `do_action` so returns can signal cancellation** — semantically wrong; the toggle change already happened. Filters imply mutation of a return value; actions imply notification of a fact.

**Impact**: EmbedsTab save handler wraps each `do_action` call in `try { do_action(...); } catch ( \Throwable $e ) { /* log via error_log; do not rethrow */ }`. Tests can register a listener that throws to verify subsequent listeners still fire.

---

## R4 — Uninstall.php behavior

**Decision**: On F012 opt-in DELETE, DROP the new `wp_acrossai_mcp_server_embed_transports` table but KEEP the `embeds_enabled` column on `wp_acrossai_mcp_servers` untouched.

**Rationale**:
- **Table drop safe on opt-in**: New table + F012 opt-in gate honoured. Companion plugins holding references via `garbage_collect_orphans()` are already handled by the plugin's `uninstall.php` running before their uninstall hooks (WP-standard ordering; documented in the quickstart).
- **Column preservation deliberate**: Removing a column via `ALTER TABLE DROP COLUMN` on uninstall creates two failure modes: (a) if the operator ever reinstalls, D28 reconciliation will re-add the column but the data is gone (worse than not having the column at all — silent data loss); (b) if a companion plugin still holds references to `embeds_enabled` on an active-but-orphaned install (e.g., reinstall pending), the column disappears mid-request → PHP notices. Additive-default-OFF stays even on uninstall — matches D21 fresh-install-only retirement pattern's inverse. Column is 1 byte per server row; keeping it is negligible.

**Alternatives Considered**:
- **Drop both column AND table on opt-in** — creates silent data-loss on reinstall (see above).
- **Keep both on opt-in** — junction table can grow arbitrarily large; F012 opt-in explicitly asks user to clean up.
- **Drop table, migrate column to some historical archive** — overengineered; single-column preservation is fine.

**Impact**: `uninstall.php` gains 3 lines (existence check + DROP TABLE inside the existing F012 opt-in guard); no code touches `wp_acrossai_mcp_servers.embeds_enabled`. Documented in the quickstart.
