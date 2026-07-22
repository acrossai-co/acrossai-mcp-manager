# Implementation Plan: OAuth Per-Server Scoping

**Branch**: `032-oauth-per-server-scoping` | **Date**: 2026-07-21 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/032-oauth-per-server-scoping/spec.md`

## Summary

F032 closes a critical cross-server privilege-escalation gap on the AI Connectors admin surface (F021 + F024) by adding a first-class `server_id BIGINT UNSIGNED NOT NULL` column to the three OAuth entity tables (`wp_acrossai_mcp_oauth_clients`, `wp_acrossai_mcp_oauth_tokens`, `wp_acrossai_mcp_oauth_auth_codes`), replacing the standalone `UNIQUE(client_id)` on the clients table with a composite `UNIQUE(client_id, server_id)`, propagating `server_id` end-to-end through the OAuth authorize → token → refresh flow, and requiring + validating `server_id` on every mutating REST endpoint. Every 403 `acrossai_mcp_oauth_cross_server` response fires `do_action( 'acrossai_mcp_oauth_cross_server_attempted', ... )` (D19 fail-open observability). Legacy pre-F032 DCR client rows (no `server-{id}-` prefix) are auto-purged during the upgrade — the OAuthClients callback fires `do_action( 'acrossai_mcp_oauth_legacy_dcr_purged', $clients_deleted, $tokens_deleted, $auth_codes_deleted )` once per upgrade run.

**F032 extended scope** (added during branch development, folded into one spec per audit surface consolidation): (a) a new BerlinDB module `wp_acrossai_mcp_connector_approved_users` promotes admin-approval state from serialized wp_options to a first-class relational table with `UNIQUE(server_id, connector_slug, user_id)` presence constraints; (b) a new "Approved Users" admin panel appears in the AI Connectors level-3 nav when `require_admin_approval = true`; (c) a new revoke-approval → token-revoke cascade wired via `acrossai_mcp_connector_user_approval_revoked` action + opt-out filter `acrossai_mcp_connector_revoke_tokens_on_approval_revoked`; (d) a "Revoke from all servers" nuclear button provides site-wide operator response for compromised client_ids (deliberate D31 carve-out — must NOT fire the cross-server-attempted action); (e) annotated token counts (`2 (1 access · 1 refresh)`) replace opaque totals in the Connections panel; (f) an OAuth-authorize-time Access Control gate blocks unauthorized users at consent time instead of failing invisibly at every subsequent tool call (F015 amendment fold-in, fail-open per D19); (g) enriched 403 responses at the AC tool-call boundary include `server_slug` + `user_roles` for operator debugging. Full FR list: FR-029..FR-050. Full SC list: SC-013..SC-021.

Technical approach: apply the F029-established D28 3-part contract three times (bumped `Table::$version` + registered `$upgrades` callback + `INFORMATION_SCHEMA.COLUMNS` idempotency check) with a 6-step callback per table: (1) ADD COLUMN as NULL-allowed transiently, (2) backfill (prefix parse for clients, JOIN for tokens/auth codes), (3) PURGE remaining `server_id IS NULL` rows, (4) OAuthClients-only ADD composite UNIQUE → DROP standalone UNIQUE, (5) MODIFY to `NOT NULL`, (6) OAuthClients-only fire aggregate observability action. Registration order in `Main::reconcile_database_schemas()`: OAuthTokens + OAuthAuthCodes MUST run BEFORE OAuthClients so their JOIN backfill can still resolve `client_id → server_id` before the client-side purge deletes the source rows. The new `ConnectorApprovedUsers` table is a v1.0.0 fresh-install-only creation (D21) — no schema drift, no `$upgrades` entry — mirroring the F017 `MCPServerAbility` presence-based composite-key pattern.

## Technical Context

**Language/Version**: PHP 8.1+ (per Constitution §II)
**Primary Dependencies**: WordPress 6.9+, BerlinDB Core 3.0 (via `berlindb/core` composer package — F011 dependency), `@wordpress/*` for admin JS (existing F024 nested-tabs bundle)
**Storage**: MySQL via `$wpdb` — 3 existing BerlinDB tables gain 1 column + 1 or 2 indexes each; no new tables
**Testing**: PHPUnit 13+ (WP-loaded via `bootstrap-wp.php`) — new `oauth` suite tests + upgrade regression tests mirror F029 shapes; JS tests via Jest for the F024 bundle
**Target Platform**: WordPress plugin (single-site per `multisite_support: false` in AGENTS.md)
**Project Type**: WordPress plugin — existing plugin repo, no new project structure
**Performance Goals**: Composite `KEY(server_id, client_id)` on tokens table accelerates the primary lookup `WHERE server_id = ? AND client_id = ?`; upgrade callback runtime bounded by legacy DCR row count (single-digit thousands on any real install)
**Constraints**: (a) table names + `db_version_key` option names preserved byte-for-byte (data-preservation contract); (b) BerlinDB `Schema.php` `$columns` MUST match `upgrade_to_<v>()` DDL final state byte-for-byte or the diff engine fires an ALTER on production installs; (c) auto-purge of legacy DCR rows disconnects live AI-host sessions on next request — README + release-note MUST warn operators (FR-025)
**Scale/Scope**: 3 BerlinDB tables + 5 OAuth controllers + 3 OAuth Repository classes + 1 admin partial + 1 JS bundle + ~10 new PHPUnit tests. Rough diff estimate: ~1500 LOC additions across ~20 files.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Evaluated against `.specify/memory/constitution.md` v1.1.0 (2026-07-12).

| Principle | Verdict | Notes |
|---|---|---|
| I. Modular Architecture | ✅ PASS | F032 stays within `includes/Database/OAuth*` + `includes/OAuth/` + `admin/Partials/ServerTabs/AIConnectorsTab.php` + F024 JS. No shared logic emerges that requires extraction to `includes/Utilities/`. The aggregate observability signal uses cross-table method calls (`OAuthTokens\Table::instance()->get_last_purge_count()` from within `OAuthClients\Table::upgrade_to_<v>()`) but that's canonical BerlinDB per-Table state, not shared utility material. |
| II. WordPress Standards Compliance | ✅ PASS | PHPCS + PHPStan L8 gates already required (per spec §Definition of Done Gates). WordPress 6.9+ / PHP 8.1+ compatible. Multisite: single-site only per plugin-wide `multisite_support: false` — carried over from AGENTS.md, no new multisite considerations. |
| III. Security First (NON-NEGOTIABLE) | ✅ PASS | See §Security Boundary Matrix below. Every mutating endpoint sanitizes `server_id` via `(int)` cast at boundary; every rendered `server_id` in HTML escapes via `esc_attr( (int) $server_id )`; existing `acrossai_mcp_manager_connector` nonce covers F024 JS bodies; every REST route retains `manage_options` cap (DCR route retains S8 body-auth exception, no change); every DB query uses BerlinDB Query or `$wpdb->prepare()` with `INFORMATION_SCHEMA` gates; OAuth tokens remain SHA-256 hashed at rest per F021. **New**: cross-server bypass prevention via composite server_id validation (per FR-016..FR-018) IS the security fix. |
| IV. User-Centric Design (NON-NEGOTIABLE) | ✅ PASS | F032 UI changes are minimal — additive `data-acrossai-server-id` attributes on existing buttons + swap of the authorized-users listing method call. Fits the v1.1.0 "Connector picker card layout" exception (AIConnectorsTab explicitly covered). No new form fields, no new list, no DataForm/DataViews requirement triggered. |
| V. Extensibility Without Core Modification | ✅ PASS | F032 ADDS two `do_action` observability signals (`acrossai_mcp_oauth_cross_server_attempted`, `acrossai_mcp_oauth_legacy_dcr_purged`); no vendor package modifications. Optional integrations unchanged (F015 access control, F017 exposure resolver, F030 permission-override — all untouched). Auto-discovery: N/A. |
| VI. Reusability & DRY | ✅ PASS | New helper `ClientsQuery::find_by_client_id_and_server_id( string, int ): ?Row` follows F017 `ExposureResolver::resolve()` per-request cache shape. Internal-only helper `find_by_client_id_any_server( string ): ?Row` is scoped exclusively to the observability path in ConnectorAdminController (single-caller, docblock-marked); does NOT expose a cross-server accessor. `get_last_purge_count()` on OAuthTokens + OAuthAuthCodes Table subclasses is a 2-caller shared shape — small enough to duplicate justified by BerlinDB's per-Table encapsulation. `npm run validate-packages` requirement unchanged. |
| VII. Definition of Done | ✅ PASS | Spec §Success Criteria enumerates all 10 gates with exception coverage for AIConnectorsTab card layout. |
| **Code Quality & Workflow** | ✅ PASS | New action names + WP_Error error codes all prefixed with `acrossai_mcp_`. Nonces via existing wiring. Capability checks unchanged. Sanitize/escape at boundaries. No deprecated funcs. No new outbound HTTP. |
| **Architecture & UI Standards** | ✅ PASS | Directory layout preserved. Namespace mapping correct (`AcrossAI_MCP_Manager\Includes\Database\OAuth{Clients,Tokens,AuthCodes}`). Admin Partials rule: `AIConnectorsTab` already in `admin/Partials/ServerTabs/`. Boot Flow: F032's `do_action` calls are fire-and-forget (no new `add_action` wiring needed); upgrade callbacks are auto-invoked by existing F029 `Main::reconcile_database_schemas()` at `admin_init@3`. Module Contract: all F032-touched classes are existing singletons with private ctors. Database: direct SQL only via `$wpdb->prepare()` — YES. Custom tables justified (pre-existing OAuth tables). Integration resilience: N/A. |

**Verdict**: All gates pass. **No violations. No Complexity Tracking entries required.**

### Security Boundary Matrix (evidence for §III gate)

**Updated 2026-07-21 per plan-review SEC-032-001/002/003/005 remediations.** All four HIGH/MEDIUM/LOW findings from the plan-phase security review have been remediated inline across spec.md + planning-doc + data-model + contracts.

| Boundary | Where | Sanitization | Escaping | Nonce | Cap Check |
|---|---|---|---|---|---|
| REST body `server_id` | `ConnectorAdminController::handle_revoke_client_tokens/delete_client` | `(int) $request->get_param('server_id')` | N/A (numeric) | Existing `acrossai_mcp_manager_connector` | `manage_options` (unchanged) |
| REST body `client_id` | Same handlers | `self::sanitize_client_id( (string) ... )` (F021 existing) | N/A (structural) | Same nonce | Same cap |
| DCR body `resource` URL — **origin check** (SEC-032-002) | `ClientRegistrationController::resolve_server_id_from_resource_url` Step 1 | `wp_parse_url` + scheme/host/port comparison against `home_url()`; fails-closed to 0 → 400 `invalid_target` | N/A | S8 body-auth exception (unchanged) | `__return_true` (S8) |
| DCR body `resource` URL — **path resolution** | Same helper Step 2 | Route-match via `CurrentServerHolder::extract_server_slug_from_url()` + `MCPServerQuery` lookup | N/A | Same S8 | Same S8 |
| DCR pre-migration race guard (SEC-032-005) | `ClientRegistrationController::handle_register` FR-028 check | `INFORMATION_SCHEMA.COLUMNS` per-request cache; missing column → 503 `service_unavailable` (fail-closed) | N/A | N/A (guard runs pre-auth) | N/A |
| HTML `data-acrossai-server-id` | `AIConnectorsTab::render_connections_panel` | `(int)` cast | `esc_attr( (int) ... )` | N/A (attr) | Handled by page cap |
| Upgrade callback DDL | `OAuthClients/Table::upgrade_to_<v>()` + siblings | Hardcoded schema names | N/A | N/A (admin_init@3) | Admin context (existing F029 gate) |
| Backfill UPDATE — **orphan-server guard** (SEC-032-003) | `OAuthClients\Table::upgrade_to_<v>()` Step 2 | `IN (SELECT id FROM oauth_servers)` clause on parsed server_id; unmatched rows LEFT NULL → PURGED in Step 3 | N/A | N/A | N/A (admin context) |
| Observability action args | `do_action` calls | `(int)` casts on server_id/user_id, `(string)` on client_id; **4-arg signature** on `acrossai_mcp_oauth_cross_server_attempted` (owning server_id NOT included, per SEC-032-001) | N/A (args passed to listeners) | N/A | N/A |
| REST body `user_id` on approve/deny/revoke-approval | `ConnectorAdminController::handle_{approve,deny,revoke}_pending_consent + handle_revoke_user_approval` (FR-038) | `(int) $request->get_param('user_id')` + `>0` gate | N/A (numeric) | `wp_rest` via `admin_permission` | `manage_options` |
| Revoke-approval action args | `do_action( 'acrossai_mcp_connector_user_approval_revoked', ... )` (FR-039) | `(int)` on server_id/user_id/revoked_by; `(string)` sanitized slug from validate_server_and_slug | N/A | N/A | N/A |
| Cascade filter opt-out | `apply_filters( 'acrossai_mcp_connector_revoke_tokens_on_approval_revoked', ... )` (FR-041) | Bool default true; listener can only relax to false (bounded blast radius) | N/A | N/A | N/A |
| Revoke-all-servers endpoint | `handle_revoke_client_tokens_all_servers` (FR-043) | `self::sanitize_client_id()`; NO server_id (server-neutral by design) | N/A | `wp_rest` via `admin_permission` | `manage_options` |
| Revoke-all-servers observability | `do_action( 'acrossai_mcp_oauth_client_revoked_across_all_servers', ... )` (FR-043) | `(int) count()`, `(string) $client_id`, `get_current_user_id()`, `time()` | N/A | N/A | N/A |
| ConnectorApprovedUsers Query | `ConnectorApprovedUsersQuery::{approve,revoke,delete_by_user_id}` (FR-032) | `(int)` casts on all id params + `>0` gates; `(string)` slug + non-empty gate; `$wpdb->prepare()` with `%i`/`%d`/`%s` on raw DELETEs | N/A | N/A (called from REST handler which has cap+nonce) | N/A (called from REST handler) |
| AC connection-time gate | `AuthorizationController::handle_get` FR-049 → `AcrossAI_MCP_Access_Control::user_has_server_access` | `(int)` casts on user_id/server_id; server_slug read from BerlinDB row (already-sanitized DB state) | Denial response uses `esc_url()` on redirect + `wp_safe_redirect` semantics | Existing OAuth flow nonce chain (state param + PKCE) | Fail-open per D19 (returns true if AC missing/manager null/server missing) |
| AC 403 enrichment | `AccessControl::gate_mcp_tool_call` (FR-050) | `wp_get_current_user()->roles` (WP-canonical array), `$server_slug` from BerlinDB row | Consumed by WP_Error, no HTML rendering | N/A (already-authenticated Bearer) | N/A |
| ConnectorApprovedUsers uninstall DROP | `uninstall.php` FR-036 | Hardcoded table stem | `%i` identifier placeholder via `$wpdb->prepare()` | N/A | Existing `WP_UNINSTALL_PLUGIN` + operator opt-in gate |

## Project Structure

### Documentation (this feature)

```text
specs/032-oauth-per-server-scoping/
├── plan.md              # This file (/speckit-plan output)
├── spec.md              # /speckit-specify + /speckit-clarify output
├── memory-synthesis.md  # /speckit-memory-md-plan-with-memory output
├── research.md          # Phase 0 — this command
├── data-model.md        # Phase 1 — this command
├── quickstart.md        # Phase 1 — this command
├── contracts/           # Phase 1 — this command (4 REST route contracts)
├── checklists/
│   └── requirements.md  # /speckit-specify quality checklist (all passing)
└── tasks.md             # Phase 2 (/speckit-tasks — NOT this command)
```

### Source Code (repository root)

```text
includes/
├── Database/
│   ├── OAuthClients/
│   │   ├── Schema.php   # + server_id column (NOT NULL final), + composite UNIQUE(client_id, server_id)
│   │   ├── Table.php    # bump $version, + $upgrades entry, upgrade_to_<v>() 6-step callback
│   │   ├── Row.php      # + public $server_id = 0; + (int) cast, + to_array() entry
│   │   └── Query.php    # + find_by_client_id_and_server_id(), + internal find_by_client_id_any_server()
│   │                    # find_admin_clients_for_server_connector -> server_id column filter (from prefix LIKE)
│   │                    # find_dcr_clients -> gains optional int $server_id filter
│   ├── OAuthTokens/
│   │   ├── Schema.php   # + server_id column (NOT NULL final), + KEY(server_id, client_id)
│   │   ├── Table.php    # bump $version + upgrade_to_<v>() 5-step callback + get_last_purge_count() helper
│   │   ├── Row.php      # + server_id property + cast
│   │   └── Query.php    # revoke_by_client_id gains required int $server_id
│   │                    # get_active_user_ids_by_client_id -> renamed _and_server_id + required int $server_id
│   │                    # revoke_by_user_id STAYS UNCHANGED (site-wide cascade per FR-042)
│   ├── OAuthAuthCodes/
│   │   ├── Schema.php   # + server_id column (NOT NULL final)
│   │   ├── Table.php    # bump $version + upgrade_to_<v>() 5-step callback + get_last_purge_count() helper
│   │   ├── Row.php      # + server_id property + cast
│   │   └── Query.php    # (same shape as tokens; delete_by_user_id STAYS server-neutral)
│   └── ConnectorApprovedUsers/     # NEW module per FR-029..FR-032 (F017 MCPServerAbility shape)
│       ├── Schema.php   # 6 cols (id, server_id, connector_slug, user_id, approved_by, approved_at) + 4 indexes
│       ├── Table.php    # leading-\ FQN (DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION) + F011 phantom guard
│       ├── Row.php      # 6 properties + (int) casts per B18
│       └── Query.php    # 5 bespoke methods: find_by_server_and_connector, is_user_approved, approve, revoke, delete_by_user_id
├── Connectors/
│   └── ConnectorSettings.php   # 3 approved-user methods delegate to ConnectorApprovedUsersQuery (signatures preserved per FR-033)
├── AccessControl/
│   └── AcrossAI_MCP_Access_Control.php  # + user_has_server_access() shared helper (FR-049) + enriched 403 (FR-050)
├── OAuth/
│   ├── AuthorizationController.php     # resolve server_id from RFC 8707 resource, persist on auth_code
│   ├── TokenController.php             # copy server_id from auth_code/prior_token, defense-in-depth compare
│   ├── ClientRegistrationController.php # DCR: resolve server_id from resource + reject invalid_target
│   │                                    # admin_generate: persist server_id from form context
│   ├── ConnectorAdminController.php    # handle_revoke_client_tokens/delete_client require+validate server_id
│   │                                    # + fire acrossai_mcp_oauth_cross_server_attempted before every 403
│   │                                    # handle_revoke_connector_tokens: extend DCR filter by server_id
│   │                                    # NEW: handle_revoke_client_tokens_all_servers (FR-043 nuclear cross-server)
│   │                                    # NEW: handle_deny_pending_consent + handle_revoke_user_approval (FR-038)
│   │                                    # NEW: cascade_revoke_tokens_on_approval_revoked default listener (FR-040)
│   ├── UserLifecycle.php               # + ConnectorApprovedUsersQuery::delete_by_user_id cascade (FR-037)
│   └── Repositories/
│       ├── AuthCodeRepository.php      # create() accepts server_id in $data
│       ├── AccessTokenRepository.php   # same + count_active_by_client_id_and_server_id_grouped wrapper (FR-045)
│       └── RefreshTokenRepository.php  # same
├── Activator.php                       # + ConnectorApprovedUsersTable::instance()->maybe_upgrade (FR-035)
├── Main.php                            # + ConnectorApprovedUsers per-request boot + reconcile (FR-034)
│                                       # + cascade listener wiring per §A1 (FR-040)
admin/
└── Partials/
    └── ServerTabs/
        └── AIConnectorsTab.php         # emit data-acrossai-server-id + swap authorized-users listing call
                                        # + NEW "Revoke from all servers" button (FR-044)
                                        # + NEW annotated token counts "N (A access · R refresh)" (FR-045)
                                        # + NEW "Approved Users" level-3 panel (FR-046..FR-048)
                                        # + REMOVE pending block from render_settings_panel (FR-048)
src/
└── js/
    └── ai-connectors.js                # F024 nested-tabs entry — include server_id in every mutating body
                                        # + surface specific error message on 403 acrossai_mcp_oauth_cross_server
                                        # + NEW handleDenyPending, handleRevokeApproval, handleRevokeAllServers
uninstall.php                           # + acrossai_mcp_connector_approved_users to $tables array (FR-036)
tests/phpunit/
├── OAuth/
│   └── PerServerIsolationTest.php      # NEW — 8 tests (6 isolation + 2 observability)
└── Database/
    ├── OAuthClients/
    │   └── PerServerColumnUpgradeTest.php  # NEW — 5 assertions
    ├── OAuthTokens/
    │   └── PerServerColumnUpgradeTest.php  # NEW — 5 assertions
    └── OAuthAuthCodes/
        └── PerServerColumnUpgradeTest.php  # NEW — 5 assertions
README.txt                              # Unreleased changelog: prominent operator warning per FR-025
docs/memory/
├── DECISIONS.md                        # + DEC-F032-OAUTH-SERVER-ID-FIRST-CLASS (post-implement capture)
├── BUGS.md                             # + B-CROSS-SERVER-BYPASS-VIA-CLIENT-ID-ONLY (post-implement capture)
├── INDEX.md                            # + 3 rows (decision + bug + worklog)
└── WORKLOG.md                          # + F032 milestone entry (post-implement)
```

**Structure Decision**: WordPress plugin single-tree layout (no `src/` / `app/` split). All new production code lives under existing `includes/Database/OAuth*/` + `includes/OAuth/*` + `admin/Partials/ServerTabs/*` + `src/js/*`. All new tests live under existing `tests/phpunit/OAuth/` + `tests/phpunit/Database/OAuth*/` (new subdirectories created only where the module didn't have PHPUnit coverage before F032). No new top-level directories.

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

No violations. Table intentionally empty.
