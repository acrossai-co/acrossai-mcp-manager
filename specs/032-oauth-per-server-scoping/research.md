# Phase 0: Research

Every `NEEDS CLARIFICATION` from the spec has already been resolved via `/speckit-clarify` (see spec §Clarifications, Session 2026-07-21). This research.md documents the rationale + alternatives for each research task, with cross-references to the durable-memory patterns that govern F032.

## R1 — BerlinDB D28 3-part contract applied to three coordinated tables

**Decision**: Apply the F029-established `D28` schema-drift-reconciliation contract three times, once per OAuth table, with a 6-step callback per table (5 steps + observability action on OAuthClients only).

**Rationale**: D28 is the canonical pattern for BerlinDB column additions on tables that live installs already have. It requires (1) bumped `Table::$version`, (2) registered `$upgrades = ['<v>' => 'upgrade_to_<v>']`, (3) idempotent upgrade callback with `INFORMATION_SCHEMA` gates + return bool. F029's `CliAuthLog\Table::upgrade_to_1_0_1` (MODIFY) and `MCPServer\Table::upgrade_to_1_1_1` + `upgrade_to_1_1_2` (ADD COLUMN with backfill — F029 + F030) are the reference implementations. F032 extends the pattern with an explicit PURGE step + a `MODIFY ... NOT NULL` finalization step (both new to F032 but consistent with the idempotent-gate + bool-return contract).

**Alternatives considered**:
- **dbDelta**: WordPress's default schema migration tool. Rejected — BerlinDB's `upgrade()` mechanism does NOT invoke dbDelta; it walks `$this->upgrades` per D28. Using dbDelta would bypass BerlinDB and re-introduce the `B34` silent-write-loss failure mode.
- **Bare `$version` bump without `$upgrades` entry**: Rejected — B34 documents this as a HIGH-severity silent write-loss failure mode (BerlinDB stamps the new version but touches nothing). Directly caused an OAuth outage on procureco.uk before F029.
- **Manual operator SQL recipe (F016 D21 pattern)**: Rejected — F016's fresh-install-only retirement pattern is scoped to features where operator attestation covers no-live-data. F032 assumes live OAuth data across every deployment; automatic in-plugin migration is the safe choice.
- **Single "big-bang" callback that touches all 3 tables**: Rejected — BerlinDB fires `$upgrades` per Table subclass; the pattern of one callback per Table keeps the responsibility localized and matches every existing per-table upgrade file's convention.

**References**: `DEC-BERLINDB-SCHEMA-DRIFT-RECONCILIATION` (D28), `B34` (bug pattern), `docs/planings-tasks/011-berlindb-migration.md` (template), `includes/Database/CliAuthLog/Table.php::upgrade_to_1_0_1`, `includes/Database/MCPServer/Table.php::upgrade_to_1_1_1` + `upgrade_to_1_1_2`.

---

## R2 — Upgrade callback ordering: tokens/auth codes BEFORE clients

**Decision**: Register OAuthTokens + OAuthAuthCodes Table subclasses in `Main::reconcile_database_schemas()` BEFORE OAuthClients so their JOIN backfill can resolve `client_id → server_id` before the OAuthClients callback's PURGE step deletes the source client rows.

**Rationale**: The tokens/auth-codes backfill uses `INNER JOIN oauth_clients ON t.client_id = c.client_id`; if OAuthClients ran first and purged legacy `server_id IS NULL` client rows, the JOIN would drop rows for whom the resolve was still needed (edge case: legacy admin clients whose prefix parse produced `server_id = 0` because of a malformed prefix — theoretically excluded by `LIKE 'server-%'`, but ordering makes it a non-question). Registration order in `Main::reconcile_database_schemas()` is fully controllable.

**Alternatives considered**:
- **Single-request atomic transaction wrapping all three callbacks**: Rejected — MySQL DDL is auto-commit; ALTER + DELETE cannot be wrapped in a transaction that would roll back on partial failure. The individual idempotency gates + step-by-step design deliver retry-safety instead.
- **Two-pass registration (register-only-add-column pass, then register-only-backfill pass on a later admin_init)**: Rejected — adds 3× the boot complexity; D28 pattern is single-callback-per-version already; no runtime win.
- **Snapshot legacy `client_id → server_id` map into a transient before purge**: Rejected — snapshot adds a failure mode (transient expiry mid-run) and buys nothing over just ordering the callbacks. The JOIN itself is the map.

**References**: `Main::reconcile_database_schemas()` (F029 wiring on `admin_init@3`), spec §Database / Storage upgrade callback ordering, planning-doc TASK-1 ordering note.

---

## R3 — Legacy DCR row auto-purge (accepted tradeoff per Q3)

**Decision**: Every row with `server_id IS NULL` after the backfill step is DELETED during the same upgrade callback (per spec §Clarifications 2026-07-21 Q3, A-aggressive form). No follow-up WP-CLI purge command. Live AI-host sessions bound to legacy rows disconnect on next request; users re-authorize via standard OAuth flow.

**Rationale**: Auto-purge yields three durable wins: (a) unlocks the `NOT NULL` constraint (per R4), enabling SQL-level invariant enforcement; (b) leaves the tables fully server-scoped with zero orphan rows, closing the read-side leak surface completely; (c) eliminates a follow-up feature (`wp acrossai purge-legacy-dcr`) from the roadmap. The single accepted cost is session disconnection on upgrade — mitigated by FR-025's mandatory README + release-note warning.

**Alternatives considered**:
- **A-tight (guard by "no live tokens")**: DELETE only legacy DCR rows whose tokens are all revoked/expired. Rejected — leaves a permanent "half legacy, half migrated" state indefinitely if operators have sticky live sessions; complicates the query invariant post-migration; the SQL guard adds runtime cost + edge-case testing surface. Explicitly rejected by user during clarify Q3.
- **Preserve legacy rows + follow-up WP-CLI purge**: The original planning-doc position. Rejected during clarify Q3 — preserved rows re-open the read-side display leak on every server's tab; the "operator-managed cleanup" story fails without operator visibility into the legacy row count.
- **Invisible-but-alive (Q3 Option A recommended)**: Same as above. Rejected in favor of aggressive purge.
- **Feature flag with default-off legacy purge**: Rejected via Q2 (no feature flags; security fix ships unconditionally).

**References**: spec §Clarifications 2026-07-21 Q3, FR-007 (purge requirement), FR-024 (observability action), FR-025 (README warning), SC-008 (verification).

---

## R4 — `server_id` column nullability: NOT NULL from day one

**Decision**: `server_id` is added as `NULL`-allowed transiently within the upgrade callback, then MODIFYed to `NOT NULL` as the final step after purge (guarded by `INFORMATION_SCHEMA.COLUMNS.IS_NULLABLE = 'YES'` for idempotency). Schema.php declares the final `NOT NULL` state so BerlinDB's SHOW CREATE TABLE diff matches on subsequent boots.

**Rationale**: With Q3's auto-purge guaranteeing zero NULL rows post-migration, the schema itself can enforce the invariant at the SQL layer. This is strictly stronger than PHP-only enforcement (defensive `(int)` casts in Row properties + `if ($server_id <= 0)` checks in Controllers) and closes an entire class of defensive-check-forgotten bugs. Matches how `client_id` is already declared NOT NULL on the same table.

**Alternatives considered**:
- **`NULL`-allowed permanently, enforce in PHP only**: Rejected via Q4 — weaker enforcement + relies on every future dev remembering to guard. The `int` cast in Row's constructor is defensive-only and does not prevent NULL rows from being INSERTed via `$wpdb->insert()` if the caller forgets the field.
- **`NULL`-allowed now, `NOT NULL` in a future F033 migration**: Rejected — defers commitment for no gain; another migration doubles the operational touchpoints; the same auto-purge step is required either way.
- **Non-idempotent `ALTER MODIFY`**: Rejected — repeated `maybe_upgrade()` calls (on every admin request until stamped) MUST be idempotent per D28. The `IS_NULLABLE = 'YES'` guard makes step 5 a no-op when column is already NOT NULL.

**References**: spec §Clarifications Q4, FR-026 (MODIFY step + guard), SC-009 (`IS_NULLABLE = 'NO'` verification + constraint-violation test), D28 (idempotency requirement).

---

## R5 — Cross-server bypass observability signal (D19 pattern) — REVISED 2026-07-21 per SEC-032-001

**Decision**: Every 403 `acrossai_mcp_oauth_cross_server` response fires `do_action( 'acrossai_mcp_oauth_cross_server_attempted', string $client_id, int $server_id_requested, int $user_id, int $timestamp )` immediately BEFORE returning the `WP_Error`. **4-arg signature** — the action does NOT include the actual owning server_id of the requested client. Follows D19 fail-open observability pattern (F015 `acrossai_mcp_access_control_denied`, F029 audit-recorder actions, F030 `acrossai_mcp_permission_override_toggled`).

**Rationale**: (a) Cross-server bypass attempts are security-relevant events that operators need forensic visibility into; (b) the fire-and-forget shape imposes no runtime cost when no listener is attached and no hard listener dependency on the plugin; (c) matches the established convention across F015/F020/F029/F030 so operators can attach a single logger across all security signals; (d) **the 4-arg signature (SEC-032-001 remediation) removes the originally-planned `$server_id_actual` arg**: any WordPress plugin can hook any action, so emitting the actual owning server_id to listeners would recreate a cross-server oracle for hostile plugins. Operators who need the owning server for forensic analysis can query the DB directly from within their listener (they have full DB access from inside a hook callback; the plugin doesn't need to help). This change also eliminates the originally-planned `ClientsQuery::find_by_client_id_any_server()` helper entirely — the only caller was this observability path.

**Alternatives considered**:
- **5-arg signature with `$server_id_actual`** (original plan): Rejected via SEC-032-001 — cross-server oracle to hostile listeners.
- **5-arg signature with `bool $exists_on_another_server`** (SEC-032-001 Option B): Rejected in favor of Option A — the boolean still leaks binary cross-server existence info + retains the helper. Option A removes both surfaces at once.
- **B — Persist to `wp_acrossai_mcp_oauth_audit` table** (Q1 Option B): Rejected — hard-couples the observability signal to a specific table schema; operators without the audit table (or with alternative loggers like Query Monitor) get nothing.
- **C — Silent 403 with no signal** (Q1 Option C): Rejected — matches the pre-F032 baseline where nothing was logged either, but F032 is the right moment to establish the observability signal for future review + intrusion-detection integrations.
- **`_log_error()` / `error_log()` direct write**: Rejected — no operator-attachable listener; harder to route to structured logging.

**References**: spec §Clarifications Q1, FR-023 (revised 4-arg requirement), SC-007 (verification), D19 (pattern), F015 / F029 / F030 reference impls, plan-review SEC-032-001 remediation 2026-07-21.

---

## R6 — Legacy purge aggregate observability signal (D19 sibling)

**Decision**: The OAuthClients upgrade callback fires `do_action( 'acrossai_mcp_oauth_legacy_dcr_purged', int $clients_deleted, int $tokens_deleted, int $auth_codes_deleted )` exactly once per upgrade run, as its final step, iff any purge count > 0. The three tokens/auth_codes tables expose their purge counts via `get_last_purge_count(): int` helper methods (per-Table state, populated at end of each table's callback, read by OAuthClients callback for the aggregate fire).

**Rationale**: Purge is a one-shot destructive operation that operators want confirmation of + count-of-affected-rows for. Fire-once (not per-row) matches D19 shape (F029's audit-recorder fires once per operation, not per affected row). Aggregate signal from OAuthClients means one listener registration catches all three tables' purge counts.

**Alternatives considered**:
- **Per-table observability action** (`..._clients_purged`, `..._tokens_purged`, `..._auth_codes_purged`): Rejected — 3 listener registrations for one logical event; harder to correlate.
- **INSERT into `wp_acrossai_mcp_oauth_audit`**: Rejected — same tradeoff as R5-B; hard-couples the signal to a specific persistence layer.
- **Log via WP-CLI-visible summary in `admin_init` transient**: Rejected — no operator-attachable listener; transient expiry adds a failure mode; ephemeral by design.

**References**: spec §Clarifications Q3, FR-024 (action requirement), SC-008 (verification), planning-doc TASK-1 aggregate-fire code snippet.

---

## R7 — Unconditional rollout, no feature flag

**Decision**: F032 ships unconditionally. No `acrossai_mcp_manager_oauth_per_server_scoping_enabled` toggle. Rollback is via composer package downgrade if operationally required.

**Rationale**: The cross-server bypass is a security vulnerability, not a documented feature. Shipping the fix behind an opt-out flag would (a) legitimise the vulnerable behaviour, (b) create a permanent "insecure-mode" code branch requiring perpetual maintenance + security audit, (c) defer real remediation indefinitely. Convention across F029 (Basic-auth default) and F030 (operator opt-in bypass) is unconditional shipping.

**Alternatives considered**:
- **Ship behind default-on flag for one release cycle**: Rejected — every "one release cycle" flag becomes permanent; the flag adds a code path + test matrix + eventual removal task.
- **Ship behind default-off flag**: Rejected — extremely conservative; leaves the vulnerability exposed by default until operators explicitly enable the fix. Wrong shape for a security fix.

**References**: spec §Clarifications Q2, spec §Assumptions (post-clarify), planning-doc Pre-flight Attestation (post-reconcile).

---

## R8 — REST endpoint 403 vs 404 for cross-server mismatch

**Decision**: `WP_Error( 'acrossai_mcp_oauth_cross_server', array( 'status' => 403 ) )` — 403 Forbidden, NOT 404 Not Found.

**Rationale**: 404 would leak "this client exists on some other server" via response-shape difference (404 for cross-server + 404 for genuinely-missing → indistinguishable; 200 for own-server valid → 3-state signal). 403 is opaque to cross-server existence: the response body says "This client does not belong to the specified server" without confirming whether the client exists at all. This is the RFC 7231 §6.5.3 semantic (server understood the request but refuses to authorize it) — correct for the "you may or may not have access to this resource; either way I'm not telling you" case.

**Alternatives considered**:
- **404 with generic "not found" message**: Rejected — leaks existence via response-shape.
- **401 Unauthorized**: Rejected — 401 is for missing/invalid auth; the caller IS authenticated (`manage_options` verified upstream), just not authorized for this specific resource. RFC 7235.
- **400 Bad Request**: Rejected — request IS syntactically valid + semantically parseable; the failure is authorization, not parsing.

**References**: RFC 7231 §6.5.3, spec §User Story 1 acceptance scenarios, planning-doc CONSTRAINTS "Do not use 404 on cross-server mismatch — use 403".

---

---

## R9 — DCR resource URL origin verification (SEC-032-002 remediation, 2026-07-21)

**Decision**: `resolve_server_id_from_resource_url()` MUST perform a two-step check: (1) origin (scheme + host + port) match against `home_url()`; on mismatch return 0 (→ 400 `invalid_target`) + fire `acrossai_mcp_oauth_dcr_resource_url_origin_mismatch` action for observability differentiation from generic path-mismatch; (2) path resolution via `MCPServerQuery` route matcher. Step 1 precedes step 2.

**Rationale**: DCR endpoint is body-authenticated per S8 (RFC 6749 §2.3.1 exception) — no nonce or cap check gates request formation, so anyone reachable to the endpoint can submit a `resource` URL. Without explicit origin verification, an attacker submitting `https://evil.attacker.com/wp-json/mcp/server-1-slug` (path structurally matches wp-json convention, origin is attacker-controlled) could resolve to `server_id = 1` on THIS site. That produces a client row bound to an attacker origin — a URL-confusion / SSRF-adjacent attack surface. Origin verification is the primary defence; MCPServerQuery's own path-shape assumptions are insufficient because the query only looks at path segments, not origin.

**Alternatives considered**:
- **Trust MCPServerQuery's own URL matching**: Rejected — MCPServerQuery is designed to match paths (`/wp-json/mcp/<slug>`), not full URLs. Any URL with a matching path segment resolves to the same server row regardless of origin.
- **Whitelist a set of allowed origins**: Rejected — F032 is single-site (multisite disabled); `home_url()` IS the single allowed origin. Adding a filter for future multisite support is premature.
- **Wildcard origin match on scheme+host** (ignore port): Rejected — port matters (differentiates canonical HTTPS 443 from a proxied 8443, etc.).
- **Fire the origin-mismatch action + still resolve**: Rejected — observability without enforcement is a partial fix; return 0 (fail-closed) is the correct posture for a security check.

**References**: FR-027 (spec.md), SC-010 (spec.md), contracts/dcr-register.md §Resource URL Resolution, plan-review SEC-032-002 remediation 2026-07-21.

---

## R10 — Backfill orphan-server-id guard (SEC-032-003 remediation, 2026-07-21)

**Decision**: The OAuthClients backfill UPDATE (Step 2 of upgrade callback) MUST include an `AND CAST(...) IN (SELECT id FROM {$wpdb->prefix}acrossai_mcp_servers)` clause. Rows whose parsed `client_id` prefix produces a server_id that does not exist in `oauth_servers` are left with `server_id IS NULL` and correctly PURGED by Step 3 alongside legacy DCR rows.

**Rationale**: The original backfill trusted `SUBSTRING_INDEX(SUBSTRING_INDEX(client_id, '-', 2), '-', -1) AS UNSIGNED` on `client_id LIKE 'server-%'` without verifying the parsed server_id exists. Failure modes:
- A legacy admin client whose server row was previously deleted (server row gone, client row lingered) gets assigned to a non-existent `server_id`. Post-PURGE (which only targets `server_id IS NULL`), this orphan survives with a phantom `server_id`.
- Test/staging clones with different real server IDs post-clone produce phantom assignments.
- Coincidental prefix matches on DCR clients whose randomly-generated slug happens to satisfy `server-<digits>-` would be assigned to a wrong server.

The guard makes the backfill self-verifying: parse ⇒ verify against oauth_servers ⇒ either legitimate assignment OR leave NULL (which becomes a purge target). Preserves the D28 idempotency contract (subsequent runs are no-ops because affected rows are already correct-or-purged).

**Alternatives considered**:
- **Post-backfill sweep to null-out orphan server_ids**: Rejected — extra step; less atomic; UPDATE-based sweep can't leverage the same idempotent WHERE-clause guard.
- **Purge orphan-server rows in Step 3 via `server_id NOT IN (SELECT id FROM oauth_servers)` clause**: Considered but rejected — mixes two concerns (legacy DCR purge + orphan admin purge) in one DELETE. Cleaner to have backfill leave NULL and Step 3 purge NULLs.
- **Fail the migration if orphan servers detected**: Rejected — creates a support burden; auto-purging orphan admin clients matches the aggressive-cleanup posture agreed in Q3.

**References**: FR-005 amendment (spec.md), SC-011 (spec.md), data-model.md Upgrade callback ordering Step 2, plan-review SEC-032-003 remediation 2026-07-21.

---

## R11 — DCR endpoint 503 during pre-migration race window (SEC-032-005 remediation, 2026-07-21)

**Decision**: `ClientRegistrationController::handle_register()` MUST verify `server_id` column presence on `wp_acrossai_mcp_oauth_clients` via `INFORMATION_SCHEMA.COLUMNS` (per-request cached) BEFORE INSERT. Column absent → return `WP_Error( 'service_unavailable', 503 )` — do NOT INSERT.

**Rationale**: There is a narrow race window between plugin file replacement and `Main::reconcile_database_schemas()` firing on the next `admin_init@3`. During this window, the new plugin code is running but the OAuth tables still have the pre-F032 schema (no `server_id` column). A DCR request arriving in this window would (a) successfully INSERT a client row (pre-migration schema accepts INSERTs without server_id), (b) then the migration fires on the next admin request and PURGES that row (which now has `server_id IS NULL`). The legitimate user's OAuth registration is silently destroyed.

The 503 response prevents silent destruction: compliant AI-host clients that honor Retry-After semantics will succeed on retry once an admin loads any wp-admin page (which triggers migration completion). Clients that don't retry will surface an operator-visible failure ("registration temporarily unavailable") rather than a silent success-then-loss.

**Alternatives considered**:
- **Force-run migration inline at plugin bootstrap**: Rejected — expensive on every request; changes the plugin's boot semantics; adds request-scope failure modes if migration takes >30s.
- **Set a transient during file replacement + gate DCR on transient**: Rejected — file replacement is not plugin-controlled (WP admin or composer does it); no hook we can attach to.
- **Silently accept DCR then re-populate server_id post-migration**: Rejected — requires stashing the intended server_id somewhere the migration can find + re-resolve; complex, race-prone.
- **Return 500 instead of 503**: Rejected — 500 signals a server-side error to retry indefinitely; 503 with Retry-After is the canonical "temporarily unavailable" shape that clients understand.

**References**: FR-028 (spec.md), SC-012 (spec.md), contracts/dcr-register.md §503 response, plan-review SEC-032-005 remediation 2026-07-21.

---

## Consolidated Findings Summary

| Research Task | Decision | Primary Rationale | Governing Memory |
|---|---|---|---|
| R1 | D28 3-part contract × 3 tables | Canonical pattern; F029/F030 references | D28, B34 |
| R2 | Tokens/AuthCodes callback BEFORE Clients | JOIN backfill needs source rows | (F032-new ordering note) |
| R3 | Auto-purge legacy DCR rows on upgrade | Unlocks NOT NULL + closes read-side leak | Q3 clarification |
| R4 | `server_id` NOT NULL from day one | Schema-level invariant enforcement | Q4 clarification |
| R5 | Cross-server 403 → 4-arg `acrossai_mcp_oauth_cross_server_attempted` action | D19 fail-open observability, no owning-server oracle | D19, SEC-032-001 |
| R6 | Aggregate legacy-purge action from OAuthClients callback | Fire-once operational signal | D19 |
| R7 | Unconditional rollout, no feature flag | Security fix ≠ documented feature | Q2 clarification |
| R8 | 403 (not 404) on cross-server mismatch | Prevents cross-server existence leak | RFC 7231 §6.5.3 |
| R9 | DCR `resource` URL two-step check (origin + path) | Prevents URL-confusion / SSRF-adjacent attacks | SEC-032-002 |
| R10 | Backfill `IN (SELECT id FROM oauth_servers)` guard | Prevents phantom-server-id orphan rows | SEC-032-003 |
| R11 | DCR endpoint 503 if `server_id` column absent | Prevents silent destruction of race-window registrations | SEC-032-005 |

**All NEEDS CLARIFICATION items resolved.** No open research questions. Ready for Phase 1 design artifacts (plus post-remediation contracts + data-model updates already applied).
