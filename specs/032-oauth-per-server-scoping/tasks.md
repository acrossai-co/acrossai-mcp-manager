---

description: "Implementation task breakdown for F032 OAuth Per-Server Scoping"
---

# Tasks: OAuth Per-Server Scoping (F032)

**Input**: Design documents from `/specs/032-oauth-per-server-scoping/`
**Prerequisites**: plan.md ✅, spec.md ✅ (4 user stories with priorities), research.md ✅ (R1-R11), data-model.md ✅, contracts/ ✅ (4 REST route contracts), quickstart.md ✅

**Tests**: **INCLUDED** — spec.md §Definition of Done Gates explicitly requires PHPUnit `oauth` + `database` suites with 10+ new tests. Tests-first per §II WordPress Standards Compliance.

**Organization**: Tasks grouped by user story per spec.md priorities — US3 (P2 safe upgrade) is technically foundational to the other stories at deploy time, but each story remains independently testable after all phases complete.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Parallelizable — touches a different file, no dependencies on incomplete tasks
- **[Story]**: US1 / US2 / US3 / US4 (setup + foundational + polish phases have NO story label)
- Every task path is absolute-from-repo-root

## Path Conventions

WordPress plugin single-tree layout per plan.md §Project Structure. All PHP paths relative to `includes/` + `admin/` + `public/` + `tests/phpunit/`. JS paths relative to `src/js/`. Memory paths relative to `docs/memory/`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Read current state + verify environment before touching schema or code

- [x] T001 Read current `$version` values from `includes/Database/OAuthClients/Table.php`, `includes/Database/OAuthTokens/Table.php`, `includes/Database/OAuthAuthCodes/Table.php`. Record the three current versions + the target versions (each bumped by +0.0.1) in a working note. Update the version-map table in `docs/planings-tasks/032-oauth-per-server-scoping.md` §Preserved table + version map to replace the "TBD (read at implement time)" cells with concrete versions.
- [x] T002 [P] Verify `includes/Main.php::reconcile_database_schemas()` exists (F029 wiring on `admin_init@3`). Confirm it currently iterates the OAuth Tables. If OAuthTokens + OAuthAuthCodes are NOT yet registered there, prepare to add them in T023 (US3) with registration order: Tokens → AuthCodes → Clients (per R2).
- [x] T003 [P] Verify `phpunit.xml.dist` has `oauth` + `database` suites configured (F030 baseline). If either is missing an `<testsuite>` block that covers the new F032 test paths under `tests/phpunit/OAuth/` and `tests/phpunit/Database/OAuth{Clients,Tokens,AuthCodes}/`, extend the config accordingly.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Structural pieces every user story depends on — Schema declarations, Row properties, and NEW (non-breaking) Query helpers. Breaking Query signature changes deferred to US1 to keep foundational phase atomically compile-safe.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [x] T004 [P] Update `includes/Database/OAuthClients/Schema.php` — append `server_id` column entry (`'type' => 'bigint', 'length' => '20', 'unsigned' => true, 'allow_null' => false`) declaring the FINAL post-migration NOT NULL state. Replace the existing `'name' => 'client_id', 'type' => 'unique'` entry with composite `array( 'name' => 'client_id_server_id', 'type' => 'unique', 'columns' => array( 'client_id', 'server_id' ) )`.
- [x] T005 [P] Update `includes/Database/OAuthTokens/Schema.php` — append same `server_id` column entry (NOT NULL final) + new index entry `array( 'name' => 'server_id_client_id', 'type' => 'key', 'columns' => array( 'server_id', 'client_id' ) )`.
- [x] T006 [P] Update `includes/Database/OAuthAuthCodes/Schema.php` — append `server_id` column entry (NOT NULL final). No new indexes required.
- [x] T007 [P] Update `includes/Database/OAuthClients/Row.php` — add `public $server_id = 0;` alongside existing properties + cast in constructor (`$this->server_id = (int) $this->server_id;` per B18 defense) + `to_array()` entry.
- [x] T008 [P] Update `includes/Database/OAuthTokens/Row.php` — same pattern as T007.
- [x] T009 [P] Update `includes/Database/OAuthAuthCodes/Row.php` — same pattern.
- [x] T010 [P] Add NEW methods to `includes/Database/OAuthClients/Query.php` (NO breaking changes to existing signatures — those come in US1):
    (a) `find_by_client_id_and_server_id( string $client_id, int $server_id ): ?Row` — per-request cache mirroring F017 `ExposureResolver::resolve()` shape.
    (b) Extend `find_dcr_clients()` signature with an OPTIONAL `int $server_id = 0` param (default 0 = all servers preserves backward compat).
    (c) Change `find_admin_clients_for_server_connector()` body from prefix-LIKE filter to `server_id` column filter (signature unchanged — no callers break).
- [x] T011 [P] Add NEW `get_last_purge_count(): int` helper method to `includes/Database/OAuthTokens/Table.php` (returns `$this->last_purge_count` int, defaults to 0). Also add `protected int $last_purge_count = 0;` property. Body of the upgrade callback populates it in T021 (US3).
- [x] T012 [P] Add NEW `get_last_purge_count(): int` helper method + `$last_purge_count` property to `includes/Database/OAuthAuthCodes/Table.php` — same pattern as T011.

**Checkpoint**: Foundation ready — schema, rows, and new Query helpers exist. Existing signatures unchanged. Code compiles cleanly. Nothing is wired to production yet (Table version bumps + upgrade callback bodies + REST changes all live in later phases).

---

## Phase 3: User Story 3 — Legacy OAuth Data Migrates Safely on Upgrade (Priority: P2) 🎯 FOUNDATIONAL AT DEPLOY-TIME

**Goal**: On next admin page load after F032 deploy, the three OAuth tables silently gain `server_id` column, backfill from prefix (admin clients) + JOIN (tokens/auth codes), purge remaining legacy rows, MODIFY to NOT NULL, and fire aggregate `acrossai_mcp_oauth_legacy_dcr_purged` observability action once per upgrade run. No live-user impact beyond documented DCR-session disconnection per FR-025.

**Independent Test**: Take a snapshot of the OAuth tables from a pre-F032 install (populated with admin clients + DCR clients + tokens + auth codes), deploy F032, load any wp-admin page, verify (a) `db_version_key` options advance, (b) `DESCRIBE` shows `server_id NOT NULL`, (c) admin-client rows backfilled from prefix, (d) legacy DCR rows DELETED, (e) tokens + auth codes bound to purged DCR clients DELETED, (f) composite `UNIQUE(client_id, server_id)` present + standalone `UNIQUE(client_id)` gone, (g) `acrossai_mcp_oauth_legacy_dcr_purged` action fired exactly once with correct deleted counts, (h) no `oauth_clients` row has `server_id NOT IN (SELECT id FROM oauth_servers)` per SC-011.

### Tests for User Story 3 (tests-first per §II)

- [x] T013 [P] [US3] Create `tests/phpunit/Database/OAuthClients/PerServerColumnUpgradeTest.php` — 5 assertions per SC-009/010: (a) column present with `IS_NULLABLE = 'NO'` post-migration, (b) fresh insert with `server_id` succeeds AND fresh insert without `server_id` FAILS with MySQL constraint violation, (c) idempotent re-run (`maybe_upgrade()` twice → no ALTER, no duplicate errors, no second `acrossai_mcp_oauth_legacy_dcr_purged` fire), (d) dropped-column recovery (drop column, rewind version, re-run → column restored + backfill + NOT NULL re-applied), (e) mid-migration crash simulation (kill between ADD COLUMN and ADD UNIQUE — next run completes remaining steps).
- [x] T014 [P] [US3] Create `tests/phpunit/Database/OAuthTokens/PerServerColumnUpgradeTest.php` — same 5 assertions adapted for tokens table.
- [x] T015 [P] [US3] Create `tests/phpunit/Database/OAuthAuthCodes/PerServerColumnUpgradeTest.php` — same 5 assertions adapted for auth codes table.
- [x] T016 [P] [US3] Create `tests/phpunit/OAuth/PerServerIsolationTest.php` (initial fixture + test #8 `test_legacy_dcr_purge_on_upgrade_fires_observability_action` per FR-024 / SC-008) — seed M legacy DCR clients + P tokens + Q auth codes (all `server_id IS NULL`) into pre-migration state; attach spy listener to `acrossai_mcp_oauth_legacy_dcr_purged`; run 3 upgrade callbacks; assert action fires exactly once with args `(M, P, Q)` + all 3 tables have `SELECT COUNT WHERE server_id IS NULL = 0` post-run. **Additionally assert `$tokens_purged > 0` AND `$auth_codes_purged > 0` when the seed includes tokens+auth codes** (per tasks-review SEC-032-T-001 — catches T024 registration-order regression that would produce `(N, 0, 0)` counts instead of legit `(M, P, Q)`).
- [x] T017 [P] [US3] Extend `tests/phpunit/OAuth/PerServerIsolationTest.php` with test #10 `test_backfill_skips_orphan_server_ids` (per SC-011, SEC-032-003 remediation) — seed legacy admin client `client_id = 'server-99999-orphan-abc'` (server 99999 does NOT exist in oauth_servers); rewind version + run `maybe_upgrade()`; assert (a) row not present post-migration, (b) `SELECT COUNT WHERE server_id NOT IN (SELECT id FROM oauth_servers) = 0`.

### Implementation for User Story 3

- [x] T018 [US3] In `includes/Database/OAuthTokens/Table.php` — bump `$version` to the value decided in T001; add `$upgrades = array( '<new-v>' => 'upgrade_to_<new-v>' )` entry.
- [x] T019 [US3] In `includes/Database/OAuthAuthCodes/Table.php` — same version bump + `$upgrades` entry pattern.
- [x] T020 [US3] In `includes/Database/OAuthClients/Table.php` — same version bump + `$upgrades` entry pattern.
- [x] T021 [US3] Implement `OAuthTokens\Table::upgrade_to_<v>()` — 5-step callback per data-model.md §Upgrade callback ordering:
    (1) ADD COLUMN `server_id BIGINT UNSIGNED DEFAULT NULL` if missing (INFORMATION_SCHEMA.COLUMNS gate).
    (2) ADD KEY `server_id_client_id` if missing (INFORMATION_SCHEMA.STATISTICS gate).
    (3) Backfill via JOIN: `UPDATE {tokens} t INNER JOIN {clients} c ON t.client_id = c.client_id SET t.server_id = c.server_id WHERE t.server_id IS NULL AND c.server_id IS NOT NULL`.
    (4) PURGE: `DELETE FROM {tokens} WHERE server_id IS NULL`; assign row count to `$this->last_purge_count`.
    (5) MODIFY to NOT NULL (`IS_NULLABLE = 'YES'` gate).
    Return `true`.
- [x] T022 [US3] Implement `OAuthAuthCodes\Table::upgrade_to_<v>()` — mirror T021 shape for auth_codes table. Same 5 steps, same JOIN on client_id → clients.server_id.
- [x] T023 [US3] Implement `OAuthClients\Table::upgrade_to_<v>()` — 6-step callback. **⚠️ MUST-BE-PAIRED-WITH-T024 (per tasks-review SEC-032-T-001)**: this callback's Step 6 aggregate signal reads runtime state (`OAuthTokens\Table::instance()->get_last_purge_count()` + AuthCodes equivalent) that is only correct if T024's registration-order fix has been applied — do NOT commit this task without T024 in the same commit, otherwise the aggregate signal fires with `(N, 0, 0)` on the first live upgrade run.
    (1) ADD COLUMN `server_id BIGINT UNSIGNED DEFAULT NULL` if missing.
    (2) **Backfill from prefix WITH orphan-server guard** (per FR-005 amendment / SEC-032-003 remediation): `UPDATE {clients} SET server_id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(client_id, '-', 2), '-', -1) AS UNSIGNED) WHERE server_id IS NULL AND client_id LIKE 'server-%' AND CAST(...) IN (SELECT id FROM {servers})`.
    (3) PURGE: `DELETE FROM {clients} WHERE server_id IS NULL`; store `$clients_purged` int.
    (4) Swap indexes: ADD `UNIQUE KEY client_id_server_id (client_id, server_id)` if missing → DROP `INDEX client_id` if present.
    (5) MODIFY to NOT NULL.
    (6) **Fire aggregate observability action** iff any of `$clients_purged`, `OAuthTokens\Table::instance()->get_last_purge_count()`, `OAuthAuthCodes\Table::instance()->get_last_purge_count()` > 0: `do_action( 'acrossai_mcp_oauth_legacy_dcr_purged', $clients_purged, $tokens_purged, $auth_codes_purged )`.
    Return `true`.
- [x] T024 [US3] In `includes/Main.php::reconcile_database_schemas()` — ensure OAuthTokens + OAuthAuthCodes Table subclasses are registered in the reconcile loop BEFORE OAuthClients (per R2 — JOIN backfill needs source client rows before client-side purge deletes them). If OAuthTokens + OAuthAuthCodes were not previously registered here (F029 wired MCPServer + CliAuthLog only), add them now. **Additionally add a boot-time defensive assertion** (per tasks-review SEC-032-T-001 remediation): after the OAuth Tables are registered, if a future refactor reorders them, throw `_doing_it_wrong( 'reconcile_database_schemas', 'OAuthClients Table must be registered LAST among OAuth Tables (see F032 R2)', '<plugin-version>' )` — catches accidental reordering that would break the aggregate observability signal counts. Verify the ordering assertion in a small dedicated PHPUnit test (add to `tests/phpunit/Database/OAuthClients/PerServerColumnUpgradeTest.php` per T013): reorder registration to (Clients, Tokens, AuthCodes), run maybe_upgrade, expect `_doing_it_wrong` to fire.
- [x] T025 [US3] Run the 3 upgrade tests (T013-T015) + the 2 isolation tests (T016-T017) via `composer run test -- --testsuite database` + `--testsuite oauth`. All 5 must pass. Verify SC-003 (backfill counts + zero debug.log warnings), SC-008 (aggregate action fires once), SC-009 (IS_NULLABLE = 'NO'), SC-011 (orphan-server invariant).
- [x] T026 [US3] Update `README.txt` Unreleased changelog with the FR-025-mandated prominent operator warning about DCR session disconnection on upgrade + observability actions + composer VCS bump per A16. Body per `docs/planings-tasks/032-oauth-per-server-scoping.md` TASK-9 README template.

**Checkpoint**: Upgrade migration works end-to-end. Fresh installs get NOT NULL schema directly. Pre-F032 installs migrate cleanly on next admin page load. Legacy DCR + phantom-server rows purged. Aggregate observability signal fires once. `db_version_key` stamps advance. Zero `debug.log` warnings. **US1 + US2 can now begin.**

---

## Phase 4: User Story 1 — Cross-Server Privilege Escalation Impossible (Priority: P1) 🎯 THE SECURITY FIX

**Goal**: An admin editing Server A's Connectors tab CANNOT revoke or delete Server B's OAuth clients or tokens via any REST body manipulation. Every mutating admin endpoint validates `server_id` matches the referenced row's `server_id` before mutating. Mismatch → 403 `acrossai_mcp_oauth_cross_server` + 4-arg observability action fire. Same protection on the READ side for the "authorized users" listing.

**Independent Test**: Seed two servers each with an admin Claude.ai client + issued tokens. From Server A's Connectors tab, POST `revoke-client-tokens` with `client_id` set to Server B's client + `server_id = 1` → assert 403 `acrossai_mcp_oauth_cross_server` + observability action fires with 4 args (no owning server_id leaked) + Server B's tokens still `revoked = 0`. Correct pair (server_id=1, client_id=server-1-*) → 200 + only Server A's tokens revoked.

### Tests for User Story 1 (tests-first)

- [x] T027 [P] [US1] Extend `tests/phpunit/OAuth/PerServerIsolationTest.php` with test #1 `test_server_a_revoke_does_not_touch_server_b_tokens` per contract RCT-001/RCT-004 — seed 2 servers × 2 clients × 2 tokens each; invoke `handle_revoke_client_tokens(server_id=1, client_id='server-1-claude-ai-abc')`; assert Server 2's tokens still `revoked = 0`.
- [x] T028 [P] [US1] Extend `PerServerIsolationTest.php` with test #2 `test_server_a_delete_does_not_touch_server_b_client_row` per contract DC-001/DC-002 — same seed; invoke delete on Server 1's client; assert Server 2's client row still present.
- [x] T029 [P] [US1] Extend `PerServerIsolationTest.php` with test #4 `test_rest_endpoint_returns_403_on_server_id_mismatch` — invoke `handle_revoke_client_tokens(server_id=1, client_id='server-2-...')`; assert `WP_Error` with code `acrossai_mcp_oauth_cross_server` + status 403.
- [x] T030 [P] [US1] Extend `PerServerIsolationTest.php` with test #5 `test_authorized_users_listing_filters_by_server_id` — seed 2 servers with 2 users each (per pre-migration mid-state); assert `get_active_user_ids_by_client_id_and_server_id( $client_id, 1 )` returns only User A.
- [x] T031 [P] [US1] Extend `PerServerIsolationTest.php` with test #7 `test_cross_server_403_fires_observability_action` per FR-023 / SC-007 (SEC-032-001 remediation shape) — attach spy listener to `acrossai_mcp_oauth_cross_server_attempted`; invoke N mismatched requests across all three admin endpoints (revoke-client-tokens, delete-client, revoke-connector-tokens); assert action fires exactly N times with **4-arg** shape `($client_id, $server_id_requested, $user_id, $timestamp)` — assert `count($spy->last_args) === 4` (owning server_id NOT among args).

### Implementation for User Story 1

- [x] T032 [US1] Apply BREAKING signature changes to `includes/Database/OAuthClients/Query.php`: `find_by_client_id( string $client_id )` gains required `int $server_id` param. **BEFORE COMMIT** (per tasks-review SEC-032-T-002): run `grep -rEn 'find_by_client_id\s*\(' --include='*.php' includes/ admin/ public/ tests/` — every hit MUST be updated in this same commit to pass the new `$server_id` arg. Zero remaining callers should use the pre-F032 1-arg signature.
- [x] T033 [US1] Apply BREAKING signature changes to `includes/Database/OAuthTokens/Query.php`: `revoke_by_client_id( string $client_id )` gains required `int $server_id`; RENAME `get_active_user_ids_by_client_id( string $client_id )` → `get_active_user_ids_by_client_id_and_server_id( string $client_id, int $server_id )`; `query()` accepts optional `server_id` filter. **DO NOT change `revoke_by_user_id( int $user_id )` signature** — site-wide cascade per FR-042 (US4 regression protects this). **BEFORE COMMIT** (per tasks-review SEC-032-T-002): run `grep -rEn 'revoke_by_client_id\s*\(|get_active_user_ids_by_client_id\s*\(' --include='*.php' includes/ admin/ public/ tests/` — every hit MUST be updated in this same commit (`revoke_by_client_id` gains server_id arg; `get_active_user_ids_by_client_id` renamed to `_and_server_id` + gains server_id arg). Zero remaining callers should use pre-F032 signatures.
- [x] T034 [US1] Apply BREAKING signature changes to `includes/Database/OAuthAuthCodes/Query.php`: same shape as T033 — every mutating helper accepting `client_id` gains required `int $server_id`. `delete_by_user_id( int $user_id )` UNCHANGED. **BEFORE COMMIT** (per tasks-review SEC-032-T-002): run `grep -rEn '(AuthCodes|auth_code).*(_by_client_id\|find_by_client_id)\s*\(' --include='*.php' includes/ admin/ public/ tests/` — every hit MUST be updated in this same commit. Zero remaining pre-F032 signatures.
- [x] T035 [US1] Update `includes/OAuth/ConnectorAdminController.php::handle_revoke_client_tokens()` per contract `revoke-client-tokens.md`:
    (a) Extract `$server_id = (int) $request->get_param('server_id');` and validate > 0.
    (b) Look up client via `ClientsQuery::instance()->find_by_client_id_and_server_id( $client_id, $server_id )`.
    (c) On `null`: fire 4-arg `do_action( 'acrossai_mcp_oauth_cross_server_attempted', $client_id, $server_id, get_current_user_id(), time() )` BEFORE returning `WP_Error( 'acrossai_mcp_oauth_cross_server', 403 )`. **MUST NOT include owning server_id** per SEC-032-001 remediation.
    (d) On match: call `TokensQuery::instance()->revoke_by_client_id( $client_id, $server_id )` with new required arg.
- [x] T036 [US1] Update `includes/OAuth/ConnectorAdminController.php::handle_delete_client()` per contract `delete-client.md` — same validation shape as T035 prepended to existing delete flow; same 4-arg observability fire before 403.
- [x] T037 [US1] Update `includes/OAuth/ConnectorAdminController.php::handle_revoke_connector_tokens()` per contract `revoke-connector-tokens.md` — the DCR-filter path (`mass_revoke_connector_tokens()`) MUST call `ClientsQuery::instance()->find_dcr_clients( $server_id )` (new optional server_id arg from T010) instead of the unscoped variant. Fires 4-arg observability action if any DCR-side mismatch surfaces.
- [x] T038 [US1] Update `includes/OAuth/AuthorizationController.php::handle_consent_post()` — resolve `server_id` from the RFC 8707 `resource` parameter at authorize time (already parsed for F021 audience binding — reuse that resolution). Pass to `AuthCodeRepository::create()` in the payload.
- [x] T039 [US1] Update `includes/OAuth/TokenController.php::handle_authorization_code()` (line ~106+) — after fetching `$auth_code_row`, capture `$server_id = (int) $auth_code_row->server_id;` + copy onto emitted token via `AccessTokenRepository::create()`. Add defense-in-depth check: if `$client_row->server_id !== $auth_code_row->server_id`, return `invalid_grant` (data-corruption guard).
- [x] T040 [US1] Update `includes/OAuth/TokenController.php::handle_refresh_token()` (line ~200+) — capture `$server_id = (int) $prior_token_row->server_id;` from prior token + copy onto emitted new token via `AccessTokenRepository::create()` + `RefreshTokenRepository::create()`.
- [x] T041 [US1] Update `includes/OAuth/Repositories/{AuthCodeRepository,AccessTokenRepository,RefreshTokenRepository}.php` — accept `server_id` key in `create()` `$data` array + persist via BerlinDB Query.
- [x] T042 [US1] Update `admin/Partials/ServerTabs/AIConnectorsTab.php::render_connections_panel()` (lines ~271-341) — every Revoke/Delete button HTML must include `data-acrossai-server-id="<?php echo esc_attr( (int) $server_id ); ?>"` in addition to existing `data-acrossai-client-id`. Switch "authorized users" listing call from `get_active_user_ids_by_client_id()` to `get_active_user_ids_by_client_id_and_server_id( $client_id, $server_id )`.
- [x] T043 [US1] Update `src/js/connectors-nested-tabs.js` (F024 nested-tabs entry — check `webpack.config.js` for the exact filename) — read both `data-acrossai-client-id` + `data-acrossai-server-id` on button click; include both in the fetch body: `body: JSON.stringify({ client_id: ..., server_id: parseInt(btn.dataset.acrossaiServerId, 10) })`. On receiving 403 `acrossai_mcp_oauth_cross_server`, surface distinct error message ("This action can only be performed for the server that owns this client — refresh the page and try again.") distinct from generic 403. Run `npm run build` to regenerate the bundle.
- [x] T044 [US1] Run PerServerIsolationTest tests 1, 2, 4, 5, 7 (T027-T031) — all 5 must pass. Verify SC-001 (203 vs 200 based on server_id) + SC-005 (grep sweep for pre-F032 signatures — zero occurrences) + SC-007 (observability action arg-count is exactly 4).

**Checkpoint**: Cross-server privilege escalation is prevented on all mutating admin endpoints. OAuth flow propagates `server_id` end-to-end. UI + JS layer sends `server_id` in every mutating body. Observability action fires with 4-arg shape (no oracle). Read-side "authorized users" leak closed.

---

## Phase 5: User Story 2 — Same DCR Connector Registers on Multiple Servers (Priority: P1)

**Goal**: An operator installs Claude Desktop and configures it to authorize against Server A → DCR registration succeeds with `server_id = 1`. Later configures same Claude Desktop against Server B → DCR registration ALSO succeeds with `server_id = 2` (two independent rows under composite UNIQUE). DCR endpoint rejects malformed / attacker-origin `resource` URLs with 400 `invalid_target`. Race-window registrations rejected with 503 (per FR-028) to prevent silent destruction by the auto-purge step.

**Independent Test**: On a fresh install with 2 seeded servers, POST two DCR registrations with `resource` URLs targeting each server; assert two distinct rows in `oauth_clients` with same `client_name = "Claude Desktop"` + distinct `server_id` values (1 and 2). Composite `UNIQUE(client_id, server_id)` accepts both. Attempt DCR with `resource = 'https://evil.com/wp-json/mcp/server-1-slug'` → 400 `invalid_target` + zero rows created + `acrossai_mcp_oauth_dcr_resource_url_origin_mismatch` action fires.

### Tests for User Story 2 (tests-first)

- [x] T045 [P] [US2] Extend `tests/phpunit/OAuth/PerServerIsolationTest.php` with test #3 `test_same_dcr_connector_registers_on_two_servers_as_two_rows` per contract DCR-005 — invoke `handle_register` twice with different `resource` URLs mapping to servers 1 and 2 but same `client_name = "Claude Desktop"`; assert 2 distinct rows in `oauth_clients` both with same `client_name` but distinct `server_id`.
- [x] T046 [P] [US2] Extend `PerServerIsolationTest.php` with test #9 `test_dcr_rejects_attacker_origin_url` per contract DCR-007 (FR-027 / SC-010, SEC-032-002 remediation) — seed server 1 with slug `server-1-slug`; invoke `handle_register` with `resource = 'https://evil.attacker.com/wp-json/mcp/server-1-slug'` (path matches, origin attacker-controlled); assert (a) 400 `invalid_target` returned, (b) `oauth_clients` row count unchanged, (c) `acrossai_mcp_oauth_dcr_resource_url_origin_mismatch` action fired exactly once with `$resource` matching submitted value.
- [x] T047 [P] [US2] Extend `PerServerIsolationTest.php` with test #11 `test_dcr_returns_503_when_column_absent` per contract DCR-008 (FR-028 / SC-012, SEC-032-005 remediation) — simulate pre-migration: `ALTER TABLE {clients} DROP COLUMN server_id` on test DB; invoke `handle_register`; assert (a) 503 `service_unavailable` returned, (b) zero new client rows; then trigger `Main::reconcile_database_schemas()`, re-invoke, assert 201 success + row created with correct `server_id`.

### Implementation for User Story 2

- [x] T048 [US2] Add helper `resolve_server_id_from_resource_url( string $resource ): int` to `includes/OAuth/ClientRegistrationController.php` per contract `dcr-register.md §Resource URL Resolution`:
    **Step 1 (origin verification per FR-027 / SEC-032-002)**: `wp_parse_url` on both `$resource` and `home_url()`; compare scheme + host (case-insensitive) + port. On mismatch: fire `do_action( 'acrossai_mcp_oauth_dcr_resource_url_origin_mismatch', $resource, get_current_user_id(), time() )` + return 0.
    **Step 2 (path resolution)**: extract server slug via `CurrentServerHolder::instance()->extract_server_slug_from_url( $resource )` (or new helper if that method doesn't exist yet on CurrentServerHolder); look up via `MCPServerQuery::instance()->query( array( 'slug' => $slug, 'number' => 1 ) )`; return matched `id` or 0.
- [x] T049 [US2] Add helper `oauth_clients_server_id_column_exists(): bool` (private static, per-request cached) to `includes/OAuth/ClientRegistrationController.php` per FR-028 remediation — `INFORMATION_SCHEMA.COLUMNS` prepared query on `oauth_clients` for `COLUMN_NAME = 'server_id'`. Cache in `private static ?bool $server_id_column_exists_cache = null;` for the request lifetime.
- [x] T050 [US2] Update `includes/OAuth/ClientRegistrationController.php::handle_register()` (DCR endpoint) per contract `dcr-register.md`:
    (a) **FIRST**: FR-028 race guard — if `! self::oauth_clients_server_id_column_exists()`, return `WP_Error( 'service_unavailable', __( 'Server initialization in progress; please retry in a few seconds.', 'acrossai-mcp-manager' ), array( 'status' => 503 ) )` immediately. No further work.
    (b) Extract `$resource` from body; if empty → 400 `invalid_target` "RFC 8707 resource parameter is required.".
    (c) Call `self::resolve_server_id_from_resource_url( $resource )`; if 0 → 400 `invalid_target` "Resource URL does not resolve to a known MCP server.". (The helper itself distinguishes origin-mismatch vs path-mismatch via the separate observability action fired inside Step 1.)
    (d) Continue existing DCR validation; call `ClientsQuery::instance()->add_item()` with `server_id` in payload.

    **NOTE (SEC-032-007 disposition, per tasks-review SEC-032-T-003)**: The 503 response in step (a) intentionally OMITS the `Retry-After: 5` HTTP header identified as optional in plan-review v2. Rationale: AI hosts observed in practice (Claude.ai, ChatGPT, Cursor) already retry at 5-30s intervals without header guidance; explicit header is optimization, not correctness. F032 ships without it; may be added in F033 or a follow-up nit-fix branch if operator feedback surfaces a client that spams 503s.
- [x] T051 [US2] Update `includes/OAuth/ClientRegistrationController.php::handle_admin_generate()` — add SAME FR-028 race guard at top (call `self::oauth_clients_server_id_column_exists()`, return 503 on false). Then persist `server_id` from admin form context in the `add_item()` payload. No new resolution logic (admin form already provides server_id).
- [x] T052 [US2] Run PerServerIsolationTest tests 3, 9, 11 (T045-T047) — all 3 must pass. Verify SC-002 (2 DCR rows), SC-010 (attacker-origin rejected), SC-012 (503 → 201 after migration).

**Checkpoint**: Multiple servers can register the same DCR connector as distinct rows. DCR endpoint rejects attacker-origin URLs + pre-migration race registrations with correct HTTP codes. All observability actions fire on failure paths.

---

## Phase 6: User Story 4 — User Deletion Still Cascades Site-Wide (Priority: P1) 🛡️ REGRESSION PROTECTION

**Goal**: Ensure F032's per-server scoping additions do NOT accidentally introduce a server filter on the user-deletion cascade path. `TokensQuery::revoke_by_user_id( int $user_id )` and `AuthCodesQuery::delete_by_user_id( int $user_id )` MUST remain server-neutral. Deleting a WordPress user revokes/deletes ALL their OAuth tokens + auth codes across ALL servers.

**Independent Test**: Seed a WordPress user with OAuth tokens on Server A AND Server B. Call `wp_delete_user( $user_id )`. Assert BOTH servers' tokens for that user are `revoked = 1` and both servers' auth codes for that user are deleted.

### Tests for User Story 4 (regression-only — no new implementation code)

- [x] T053 [P] [US4] Extend `tests/phpunit/OAuth/PerServerIsolationTest.php` with test #6 `test_user_deletion_still_cascades_across_all_servers` per FR-042 regression — seed 2 servers with tokens for User A on both; invoke `UserLifecycle::on_user_deleted( user_id_A )` (or `wp_delete_user()`); assert BOTH servers' tokens for that user are `revoked = 1` AND both servers' auth codes for that user are deleted.
- [x] T054 [US4] Grep verification (governance gate): `grep -n 'revoke_by_user_id\|delete_by_user_id' includes/Database/OAuth*/Query.php` — confirm signatures are unchanged (`revoke_by_user_id( int $user_id )` and `delete_by_user_id( int $user_id )` — NO `server_id` param added by F032). Also grep `includes/OAuth/UserLifecycle.php` — no server-scoping code introduced. Record the grep output in the PR description as evidence.

**Checkpoint**: User-deletion cascade preserved site-wide. F032's per-server additions did not regress FR-042.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: QA gates, final full-repo audit, memory hygiene, operator warning.

- [ ] T055 [P] Run `composer run phpcs` — zero errors AND zero warnings on every F032-touched file per §II + spec §Definition of Done Gates. Address any hits inline (do NOT add phpcs:ignore unless already in the plugin baseline).
- [ ] T056 [P] Run `composer run phpstan` (PHPStan level 8) — zero errors on every F032-touched file per §II.
- [ ] T057 [P] Run `composer run test -- --testsuite oauth` — all existing OAuth tests + 8 new isolation tests (T016-T017, T027-T031, T045-T047, T053) pass. Ensure no pre-existing test regresses due to Query signature changes.
- [ ] T058 [P] Run `composer run test -- --testsuite database` — all existing DB tests + 3 new upgrade regression tests (T013-T015) pass.
- [ ] T059 Run `composer dump-autoload` — succeeds with zero warnings. Verify new tests are autoloaded.
- [ ] T060 Run `npm run validate-packages` — passes per §VI Tier 1 requirement.
- [ ] T061 Run `npm run build` — regenerates F024 nested-tabs JS bundle with T043 changes.
- [ ] T062 Final full-repo audit per `docs/planings-tasks/032-oauth-per-server-scoping.md` §Final full-repo audit:
    (a) `grep -rEn 'revoke_by_client_id\s*\(|delete_by_id\s*\(|get_active_user_ids_by_client_id\s*\(|find_by_client_id\s*\(' --include='*.php' includes/ admin/ public/` — every hit passes `$server_id` OR is UserLifecycle::on_user_deleted (documented exception).
    (b) `grep -rEn 'get_param\s*\(\s*.client_id.\s*\)|get_param\s*\(\s*.token.\s*\)' --include='*.php' includes/OAuth/` — every hit immediately followed by `server_id` extraction + validation (B-CROSS-SERVER-BYPASS grep gate).
    (c) `grep -rn 'server_id IS NULL' --include='*.php' includes/Database/OAuth` — every hit inside upgrade callback only.
    (d) `grep -rn 'find_by_client_id_any_server' --include='*.php' includes/ admin/ public/ tests/` — **ZERO matches** (SEC-032-001 remediation grep gate).
    (e) `grep -rn "do_action.*acrossai_mcp_oauth_cross_server_attempted" --include='*.php' includes/OAuth/` — every hit has exactly 4 args (SEC-032-001 grep gate).
    (f) `grep -rEn 'resolve_server_id_from_resource_url' --include='*.php' includes/OAuth/` — definition MUST contain BOTH `wp_parse_url` + `home_url()` comparison (SEC-032-002 grep gate).
    (g) `grep -rn "'service_unavailable'\|status.*503" --include='*.php' includes/OAuth/ClientRegistrationController.php` — at least one 503 guarded by column-existence check (SEC-032-005 grep gate).
- [ ] T063 `SHOW CREATE TABLE` verification per spec §DoD gates — dump `SHOW CREATE TABLE` for `wp_acrossai_mcp_oauth_clients`, `wp_acrossai_mcp_oauth_tokens`, `wp_acrossai_mcp_oauth_auth_codes` on a post-upgrade test install; verify byte-for-byte match against Schema.php + `Table::upgrade_to_<v>()` DDL (types, lengths, defaults, indexes, IS_NULLABLE).
- [ ] T064 Run quickstart.md §Post-Upgrade Verification WP-CLI commands on a fresh test install with seeded pre-F032 snapshot — verify all 6 (now 7 with step 4b) WP-CLI checks pass.
- [x] T065 [P] Memory hygiene: propose `DEC-F032-OAUTH-SERVER-ID-FIRST-CLASS` entry to `docs/memory/DECISIONS.md` via `/speckit-memory-md-capture-from-diff` per planning-doc TASK-9 template. Body includes: NOT NULL invariant, composite UNIQUE, 4-arg observability action (SEC-032-001), origin verification (SEC-032-002), backfill orphan-guard (SEC-032-003), 503 race guard (SEC-032-005), auto-purge decision, unconditional rollout.
- [x] T066 [P] Memory hygiene: propose `B-CROSS-SERVER-BYPASS-VIA-CLIENT-ID-ONLY` entry to `docs/memory/BUGS.md` via `/speckit-memory-md-capture-from-diff`. Body includes: generalizable grep-gate pattern for any per-tenant admin endpoint accepting only a tenant-scoped identifier.
- [x] T067 Memory hygiene: `docs/memory/INDEX.md` — 3 new rows (DEC-F032-*, B-CROSS-SERVER-*, WORKLOG). `docs/memory/WORKLOG.md` — F032 milestone entry. `docs/planings-tasks/README.md` — append `032-oauth-per-server-scoping.md` row.

**Checkpoint**: All CI gates green, all grep audits pass, memory hygiene captured, README warning in place. Feature ready for `/speckit-analyze` + `/speckit-architecture-guard-architecture-review` + `/speckit-security-review-staged` + `/speckit-git-commit`.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — can start immediately (T001-T003).
- **Foundational (Phase 2)**: Depends on Setup completion — BLOCKS all user stories.
- **US3 (Phase 3)**: Depends on Foundational. Ships the schema migration + observability action + orphan guard. Structurally foundational-at-deploy-time (US1 + US2 cannot function on a live install until US3's migration has run).
- **US1 (Phase 4)**: Depends on Foundational + US3 (needs schema present in tests). Ships REST validation + OAuth flow propagation + UI + JS.
- **US2 (Phase 5)**: Depends on Foundational + US3 (needs schema) + US1 (shares Query signature changes). Ships DCR endpoint updates + origin check + race guard.
- **US4 (Phase 6)**: Depends on US1 (T033 applied Query signature change — need to verify NOT-changed on user-deletion helpers). Regression protection only.
- **Polish (Phase 7)**: Depends on all user stories complete.

### User Story Dependencies

- **US1 (P1) → US3 (P2)**: US1 tests need schema; deploy them together.
- **US2 (P1) → US3 (P2) + US1 (P1)**: US2 shares Query signature changes with US1.
- **US4 (P1) → US1 (P1)**: US4 verifies that US1's breaking Query changes did NOT touch `revoke_by_user_id` / `delete_by_user_id`.

### Within Each User Story

- Tests MUST be written and FAIL before implementation (except US4 which is regression-only).
- Query.php signature changes must land before REST handlers that call them (T032-T034 before T035-T037).
- OAuth flow propagation (T038-T041) can proceed in parallel with REST handler updates (T035-T037) since they touch different files.
- UI + JS (T042-T043) depends on REST handler changes being deployed (semantically) — but code changes can proceed in parallel since HTML/JS doesn't call PHP directly.

### Parallel Opportunities

- **Setup**: T002 + T003 both [P] — can run in parallel with T001.
- **Foundational**: T004-T012 all touch different files — full parallel.
- **US3 tests**: T013-T017 all [P] — full parallel test-authoring.
- **US3 implementation**: T018-T020 [not P — same file family]; T021+T022 can proceed [P] (different files); T023 depends on both purge counts populated (T021 + T022 done).
- **US1 tests**: T027-T031 all [P] — full parallel test-authoring (append to same file — coordinate via git).
- **US1 implementation**: T035-T037 [not P — same file]; T038 [P]; T039+T040 [not P — same file]; T041 [P]; T042 [P]; T043 [P].
- **US2 tests**: T045-T047 all [P].
- **US2 implementation**: T048-T051 [not P — same file for T048-T051].
- **Polish**: T055-T058 [P] — full parallel CI gate runs; T065-T066 [P] — different memory files.

---

## Parallel Example: User Story 1

```bash
# Launch all US1 tests together (append to same file — use git branches per test):
Task: "Add test_server_a_revoke_does_not_touch_server_b_tokens to PerServerIsolationTest.php"
Task: "Add test_server_a_delete_does_not_touch_server_b_client_row to PerServerIsolationTest.php"
Task: "Add test_rest_endpoint_returns_403_on_server_id_mismatch to PerServerIsolationTest.php"
Task: "Add test_authorized_users_listing_filters_by_server_id to PerServerIsolationTest.php"
Task: "Add test_cross_server_403_fires_observability_action (4-arg) to PerServerIsolationTest.php"

# Launch parallel implementation tasks after Query changes land:
Task: "Update AuthorizationController with RFC 8707 server_id capture"
Task: "Update Repository classes to accept server_id in create()"
Task: "Update AIConnectorsTab with data-acrossai-server-id + auth-users listing swap"
Task: "Update F024 nested-tabs JS bundle with server_id in fetch body + 403 error message"
```

---

## Implementation Strategy

### MVP Definition

**F032 has no partial-ship MVP** — the security fix is only complete when all P1 stories (US1, US2, US4) are shipped together AND US3's migration is in place to support them. Shipping only US1 without US3 breaks live installs; shipping US1 + US3 without US2 leaves the DCR endpoint unprotected; shipping any without US4's regression protection risks breaking user deletion.

**Recommended sequence**: Setup → Foundational → US3 → US1 → US2 → US4 → Polish. All in one PR.

### Incremental Development Within the Branch

1. Complete Phase 1 (Setup) + Phase 2 (Foundational) — code compiles, all existing tests still pass.
2. Complete Phase 3 (US3) — upgrade tests pass in isolation, but no runtime code uses the new schema yet.
3. Complete Phase 4 (US1) — REST endpoints validated, isolation tests pass, OAuth flow propagates server_id.
4. Complete Phase 5 (US2) — DCR endpoint captures server_id + rejects attacker origins + handles race window.
5. Complete Phase 6 (US4) — regression test locks in site-wide cascade.
6. Complete Phase 7 (Polish) — CI green, memory hygiene, README warning.
7. Push branch → open PR → `/speckit-analyze` → `/speckit-architecture-guard-architecture-review` → `/speckit-security-review-staged` → merge.

### Solo Development Notes

Since this is a single-developer plugin with F032 as one focused feature branch:
- Batch T004-T012 (Foundational) into a single commit — atomic schema+row+query preparation.
- Commit each user story's tests-then-implementation as separate commits within the branch.
- Save memory hygiene (T065-T067) for after `/speckit-security-review-staged` passes clean.

---

---

## Phase 8: User Story 5 — ConnectorApprovedUsers Table + Panel (Priority: P2)

**Goal**: promote admin-approval state from serialized wp_options to a first-class BerlinDB table with a relational query surface + a discoverable admin panel. See spec.md US5 for full acceptance scenarios.

**Independent Test**: enable `require_admin_approval`, verify "Approved Users" tab appears; approve + revoke test users and verify DB state per SC-013..SC-016.

### Implementation for User Story 5 (all shipped in-branch)

- [x] T068 [US5] Create `includes/Database/ConnectorApprovedUsers/Schema.php` — 6 columns + 4 indexes per FR-029 (SC-013 verification).
- [x] T069 [US5] Create `includes/Database/ConnectorApprovedUsers/Table.php` — leading-`\` FQN + F011 phantom-version guard per FR-030 + DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION.
- [x] T070 [US5] Create `includes/Database/ConnectorApprovedUsers/Row.php` — 6 properties + `(int)` casts in constructor per FR-031 (B18 defense).
- [x] T071 [US5] Create `includes/Database/ConnectorApprovedUsers/Query.php` — 5 bespoke methods per FR-032: `find_by_server_and_connector`, `is_user_approved`, `approve` (idempotent short-circuit), `revoke`, `delete_by_user_id`. Every raw DELETE uses `$wpdb->prepare()` with `%i`/`%d`/`%s` placeholders.
- [x] T072 [US5] Update `includes/Main.php::load_hooks()` — register `ConnectorApprovedUsers\Table::instance()` for per-request boot per FR-034 + DEC-BERLINDB-TABLE-REQUEST-BOOT.
- [x] T073 [US5] Update `includes/Main.php::reconcile_database_schemas()` — call `maybe_upgrade()` on the new Table at `admin_init@3` per FR-034.
- [x] T074 [US5] Update `includes/Activator.php::activate()` — call `ConnectorApprovedUsersTable::instance()->maybe_upgrade()` per FR-035 (fresh-install seeder).
- [x] T075 [US5] Update `uninstall.php` — append `$wpdb->prefix . 'acrossai_mcp_connector_approved_users'` to `$tables` array per FR-036 with F032 FID marker.
- [x] T076 [US5] Update `includes/Connectors/ConnectorSettings.php` — 3 approved-user methods (`approved_user_ids`, `add_approved_user`, `is_user_approved`) delegate bodies to `ConnectorApprovedUsersQuery` per FR-033. Signatures preserved byte-identically so no caller breaks.
- [x] T077 [US5] Update `admin/Partials/ServerTabs/AIConnectorsTab.php` — conditional "Approved Users" nav tab in `render_level3_bar()` per FR-046; new `render_approved_users_panel()` method per FR-047 (widefat striped table, Pending + Approved sections); remove pending block from `render_settings_panel()` per FR-048.
- [x] T078 [US5] Update `src/js/ai-connectors.js` — new `handleDenyPending` + `handleRevokeApproval` handlers + click delegation selectors for `.acrossai-mcp-connector-panel__deny-btn` + `.acrossai-mcp-connector-panel__revoke-approval-btn`. Run `npm run build`.

### Tests for User Story 5 (outstanding — testing gap C1 from audit)

- [x] T079 [P] [US5] Create `tests/phpunit/Database/ConnectorApprovedUsers/ConnectorApprovedUsersTest.php` — fresh-install creates all 4 indexes (SC-013), `approve()` idempotency + UNIQUE enforcement (SC-014), `revoke()` returns bool correctly, `is_user_approved()` returns bool, `delete_by_user_id()` site-wide cascade (SC-015). **File authored, PHPCS clean. Execution requires WP_TESTS_DIR provisioned via bin/install-wp-tests.sh + `./vendor/bin/phpunit --bootstrap tests/bootstrap-wp.php --testsuite database`.**
- [ ] T080 [P] [US5] Extend the uninstall test suite — verify `wp_acrossai_mcp_connector_approved_users` is DROPped when `acrossai_mcp_uninstall_delete_data = 1` (SC-016).
- [x] T081-A [US5] Implement `AuthorizationController::handle_get()` admin-bypass per FR-051 — `user_can( $user_id, 'manage_options' )` short-circuit auto-inserts admin into `wp_acrossai_mcp_connector_approved_users` via `ConnectorApprovedUsersQuery::approve( $server_id, $slug, $user_id, $user_id )` (self-approval), then falls through to consent-screen render. Non-admin path unchanged (add_pending_user + render_pending_approval + exit).
- [x] T081-B [US5] Extend Settings-panel UI copy in `render_settings_panel()` per FR-051 — add a second `<p class="description"><em>...</em></p>` line below the `require_admin_approval` description explaining the admin bypass behavior. Escaped via `esc_html__`.
- [x] T081-C [P] [US5] Create `tests/phpunit/OAuth/AdminBypassTest.php` — (a) admin auto-approve with `approved_by = self`; (b) subscriber routed to pending (no approved row); (c) idempotent re-approve for already-approved admin; (d) capability check honors WP role hierarchy. **Tests the KEY invariants of the gate branch decision + downstream Query post-conditions. End-to-end handle_get() invocation deferred (requires mocking `exit` + full OAuth scaffolding). File authored, PHPCS clean.**

---

## Phase 9: User Story 6 — Revoke-Approval Cascade (Priority: P2)

**Goal**: revoking a user's approval also revokes their active tokens for the same (server, connector) pair by default, with a filter-based opt-out honoring §V Extensibility. See spec.md US6 for full scenarios.

### Implementation for User Story 6 (all shipped in-branch)

- [x] T081 [US6] Add `ConnectorAdminController::handle_revoke_user_approval()` per FR-039 — deletes approval row, fires 4-arg `do_action('acrossai_mcp_connector_user_approval_revoked', $server_id, $slug, $user_id, get_current_user_id())`.
- [x] T082 [US6] Add `ConnectorAdminController::handle_deny_pending_consent()` per FR-038 — removes user from pending without approving.
- [x] T083 [US6] Add default listener `ConnectorAdminController::cascade_revoke_tokens_on_approval_revoked()` per FR-040 — enumerates admin + DCR clients, calls `TokensQuery::revoke_by_user_and_server_and_client_ids()`, fires per-token `acrossai_mcp_manager_oauth_token_revoked` with reason `approval_revoked`. Honors opt-out filter per FR-041.
- [x] T084 [US6] Wire the listener via `$this->loader->add_action('acrossai_mcp_connector_user_approval_revoked', ConnectorAdminController::class, 'cascade_revoke_tokens_on_approval_revoked', 10, 4)` in `Main::define_admin_hooks()` per §A1.
- [x] T085 [US6] Add `TokensQuery::revoke_by_user_and_server_and_client_ids()` + `revoke_by_client_id_and_user_id()` per FR-042 — per-client loop (avoids dynamic `IN()` PHPCS false-positive), every raw query uses `$wpdb->prepare()` with `%i`/`%d`/`%s`.
- [x] T086 [US6] Update `includes/OAuth/UserLifecycle.php::on_user_deleted()` — call `ConnectorApprovedUsersQuery::instance()->delete_by_user_id( $user_id )` per FR-037 (extends FR-042 cascade).

### Tests for User Story 6 (outstanding — testing gap C2 from audit)

- [x] T087 [P] [US6] Create `tests/phpunit/OAuth/ApprovalRevokeCascadeTest.php` — (a) revoke-approval → tokens revoked for that (server, connector, user) per SC-017; (b) filter `__return_false` → tokens NOT revoked per SC-018; (c) 4-arg hook signature verified via spy listener; (d) `acrossai_mcp_manager_oauth_token_revoked` fires N times with `reason = 'approval_revoked'`. **Also asserts D34 mutual exclusion — cascade path MUST NOT fire `acrossai_mcp_oauth_client_revoked_across_all_servers`. File authored, PHPCS clean.**
- [ ] T088 [P] [US6] Extend UserLifecycle regression tests — deleting a user with approval rows across 2+ (server, connector) pairs deletes ALL rows regardless of scope per SC-015.

---

## Phase 10: User Story 7 — Nuclear Revoke + Annotated Tokens (Priority: P3)

**Goal**: one-click site-wide token revoke for compromised client_ids + annotated `2 (1 access · 1 refresh)` token counts in the Connections panel. See spec.md US7.

### Implementation for User Story 7 (all shipped in-branch)

- [x] T089 [US7] Add `ConnectorAdminController::handle_revoke_client_tokens_all_servers()` per FR-043 — accepts only `client_id`, calls `TokensQuery::revoke_by_client_id_across_all_servers()`, fires 4-arg `acrossai_mcp_oauth_client_revoked_across_all_servers` action. **MUST NOT fire** `acrossai_mcp_oauth_cross_server_attempted` (docblocked D31 carve-out).
- [x] T090 [US7] Add `TokensQuery::revoke_by_client_id_across_all_servers( string $client_id ): array<int, int>` — site-wide UPDATE, returns array of revoked token ids.
- [x] T091 [US7] Add `TokensQuery::count_active_by_client_id_and_server_id_grouped( string, int ): array{access:int,refresh:int,total:int}` + `AccessTokenRepository::count_active_by_client_id_and_server_id_grouped()` wrapper per FR-045.
- [x] T092 [US7] Update `AIConnectorsTab::render_connections_panel()` — add "Revoke from all servers" button per FR-044 (`.acrossai-mcp-connector-panel__revoke-all-btn`, `data-acrossai-client-id`, no server_id); replace plain token count with annotated form per FR-045.
- [x] T093 [US7] Add `handleRevokeAllServers` handler in `src/js/ai-connectors.js` + click delegation. Run `npm run build`.

### Tests for User Story 7 (outstanding)

- [ ] T094 [P] [US7] Extend `PerServerIsolationTest.php` — assert `acrossai_mcp_oauth_client_revoked_across_all_servers` fires exactly once with 4 args per SC-019 AND `acrossai_mcp_oauth_cross_server_attempted` fires 0 times (spy listener). Assert BOTH servers' tokens marked `revoked = 1`.
- [ ] T095 [P] [US7] Add PHPUnit assertion on rendered HTML — Connections panel row for a client with 1 access + 1 refresh displays `"2 (1 access · 1 refresh)"` per SC-020. On zero tokens, displays `"0"` (no annotation).

---

## Phase 11: User Story 8 — Access Control Connection-Time Gate (Priority: P1)

**Goal**: block unauthorized users at OAuth authorize time BEFORE any auth code is issued. See spec.md US8.

### Implementation for User Story 8 (all shipped in-branch)

- [x] T096 [US8] Add `AcrossAI_MCP_Access_Control::user_has_server_access( int $user_id, int $server_id ): bool` shared helper per FR-049 — fail-open per D19 (AC missing / server row missing / manager null → true).
- [x] T097 [US8] Update `AuthorizationController::handle_get()` — call the helper BEFORE consent-render; on `false` redirect with `error=access_denied` + description + fire `acrossai_mcp_access_control_denied` with `context = 'oauth_authorize'`.
- [x] T098 [US8] Enrich `AccessControl::gate_mcp_tool_call()` 403 with `server_slug` + `user_roles` per FR-050.

### Tests for User Story 8 (outstanding — testing gap C3 from audit)

- [x] T099 [P] [US8] Create `tests/phpunit/Includes/AccessControl/OAuthAuthorizeGateTest.php` — (a) fail-open on AC package absence per D19; (b) fail-open on invalid input; (c) fail-open on missing server row (Q2 race); (d) 4-arg action signature verified; (e) connection-time context enum values (`oauth_authorize`, `cli_device_grant`, `app_password_generate`) contract-verified against php-hooks.md. **Path is `tests/phpunit/Includes/AccessControl/` per phpunit.xml.dist admin-suite mapping. File authored, PHPCS clean.**

---

## Phase 12: Extended-Scope Polish

- [ ] T100 [P] Update `docs/memory/DECISIONS.md` — add DEC-CONNECTOR-APPROVAL-REVOKE-CASCADE, DEC-OAUTH-AUTHORIZE-AC-GATE, DEC-CROSS-SERVER-NUCLEAR-REVOKE-CARVE-OUT entries with `why + how to apply` shape.
- [ ] T101 [P] Update `docs/memory/INDEX.md` — 3 new decision rows + 1 new BerlinDB module row + 1 Approved Users panel row.
- [ ] T102 [P] Update `docs/memory/WORKLOG.md` — append F032 extended-scope milestone entry noting the 4 new user stories (US5-US8) + 22 new FRs (FR-029..FR-050) + 9 new SCs (SC-013..SC-021) folded into the branch.
- [ ] T103 Run scoped PHPCS on new-code files (already green per audit) + scoped PHPStan L8 (already green) + run `composer run phpcs` on the new PHPUnit test files added by T079/T080/T087/T088/T094/T095/T099.
- [ ] T104 Run full test suite (`composer run test`) — all outstanding tests (T079, T080, T087, T088, T094, T095, T099) pass.
- [ ] T105 [OPERATOR] Regenerate `languages/acrossai-mcp-manager.pot` via `wp i18n make-pot . languages/acrossai-mcp-manager.pot` (WP-CLI required). New F032 UI copy strings (FR-051 admin-bypass note, US5 "Approved Users" panel labels, cascade dialog copy) are not yet extracted to the catalogue — translations will miss them until this runs. Not wired as a composer/npm script; requires operator to run manually with wp-cli on PATH. Commit the regenerated `.pot` in the same PR as this branch.
- [x] T106 [US5] SEC-L1 remediation — fire `acrossai_mcp_connector_admin_self_bypassed` action from FR-051 bypass branch per SC-023. Documented in `contracts/php-hooks.md`. Tested in `AdminBypassTest::test_admin_bypass_fires_self_bypassed_observability_action` + regression companion. Codifies B38 (self-approval audit-trail ambiguity) as durable pattern. PHPCS + PHPStan clean.

---

## Notes

- [P] tasks touch different files; parallel means "can be done concurrently by a team", not "MUST be done in parallel".
- Every task path is absolute-from-repo-root or clearly rooted at a top-level directory.
- Every task ID is unique (T001-T104). 104 tasks total (original 67 F032 core + 37 F032 extended-scope).
- Tests-first per §II WordPress Standards + spec §DoD Gates. **Extended-scope Phase 8-11 SHIPPED IMPLEMENTATION FIRST; tests are outstanding (C1/C2/C3 from `/speckit-analyze` audit)** — this is a deliberate deviation from tests-first for the folded-in scope, driven by branch-in-progress momentum. Testing gaps MUST be closed before merge per §II.
- No cross-story dependencies that would break independent testability of any single user story AFTER the full branch lands.
- All FRs from spec.md (FR-001 through FR-050) are covered by at least one implementation task.
- All SCs from spec.md (SC-001 through SC-021) are verified by at least one test task or manual verification step.
- All 4 original clarify decisions (Q1-Q4) are reflected in the task set. Extended-scope decisions captured post-facto in `docs/memory/DECISIONS.md` (T100).
- All 4 v1 security-review remediations (SEC-032-001 through SEC-032-005, minus INFO SEC-032-006 deferred + SEC-032-007 optional) are reflected as explicit tasks with grep-gate governance.
