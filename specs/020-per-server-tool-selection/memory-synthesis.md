# Memory Synthesis

## Current Scope

Feature 020 adds a per-server Tools tab that lets site administrators curate which registered WordPress abilities each MCP server exposes as callable MCP tools. Affected modules: new BerlinDB module `MCPServerTool` (Table / Schema / Query / Row); new `ToolsController` REST endpoint; new React bundle `src/js/tools.js` implementing a two-column shuttle picker; `ToolsTab::render_body()` rewrite to mount the React app; hook wiring in `Main.php` (rest_api_init, admin_enqueue_scripts, server-deletion cleanup); `Activator::activate()` + `uninstall.php` deltas. Feature 017 (Abilities tab), Feature 019 (tab filter Registry), and the four existing BerlinDB modules are explicitly out of scope.

## Relevant Decisions

- **DEC-BERLINDB-TABLE-REQUEST-BOOT** (Reason Included: F020 adds a fifth BerlinDB Table subclass; missing the request-time boot in `Main::bootstrap_database_tables()` produces the exact "Table doesn't exist" fallback bug F011 fixed. Status: Active F011. Source: DECISIONS.md:494)
- **DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION** (Reason Included: all four new class files have the same local name as their `\BerlinDB\Database\Kern\*` parent — a `use` statement will fatal. Status: Active F011. Source: DECISIONS.md:531)
- **DEC-WP-DATAVIEWS-OVER-REACT** (Reason Included: the spec's shuttle-picker UX is a deliberate divergence from F017's DataViews pattern; DEC's reconsider clause requires a new DEC-* entry when DataViews cannot express the UX. Status: Active F017. Source: DECISIONS.md:1167. **See Conflict Warnings.**)
- **DEC-UNINSTALL-OPT-IN-GATE** (Reason Included: Q3 clarification said "drop on uninstall matching the four existing tables"; that convention is opt-in-gated, not unconditional. FR-028 must specify DROP happens AFTER the `acrossai_mcp_uninstall_delete_data` gate. Status: Active F012. Source: DECISIONS.md:604. **See Conflict Warnings.**)
- **DEC-SERVER-TAB-CLASS-HIERARCHY** (Reason Included: `ToolsTab` extends `AbstractServerTab` and is dispatched by the F019 Registry; TASK-4's render_body swap must preserve the base-class contract and priority slot. Status: Active F013. Source: INDEX row.)

## Active Architecture Constraints

- **A1 — All hook registration in `Main.php`** (Reason Included: F020 wires REST route on `rest_api_init`, script enqueue on `admin_enqueue_scripts`, server-deletion cleanup on the server-delete action, and table boot in `bootstrap_database_tables()` — all four MUST land in `Main.php` via the Loader, none in class constructors. Source: ARCHITECTURE.md via INDEX.)
- **A2 — Singleton `instance()` pattern** (Reason Included: `Query`, `ToolsController` — both must be singletons with private constructors. Source: ARCHITECTURE.md.)
- **A4 — DataForm/DataViews for new admin surfaces** (Reason Included: same soft conflict as DEC-WP-DATAVIEWS-OVER-REACT — shuttle picker doesn't map to DataViews. Source: ARCHITECTURE.md. **See Conflict Warnings.**)
- **A6 — `use` imports or leading-`\` FQN inside `Includes\` namespace** (Reason Included: `ToolsController` and `Main.php` reference sub-namespaces and MUST use `use` or leading-`\` — bare relative paths (B1) silently fail. Source: ARCHITECTURE.md.)
- **A9 — Shared constants in `includes/Utilities/`** (Reason Included: if `EXCLUDED_SLUGS` is shared between `src/js/tools.js` and `ToolsController.php`, it belongs as a shared source, not duplicated. F017 duplicated it — F020 can mirror to stay symmetric, or extract now.)

## Accepted Deviations

- **DEV1 — WP_List_Table exception** (Reason Included: only referenced to confirm F020 does NOT qualify — the Tools tab is per-server admin JS, not the shared MCP Manager parent menu. Status: Accepted-Deviation. Source: CONSTITUTION.md §IV.)

## Relevant Security Constraints

- **S2 — REST permission_callback explicit, no `__return_true` on mutating routes** (Reason Included: both GET and POST on `/servers/{id}/tools` MUST check `manage_options` — FR-021. Source: CONSTITUTION.md §III.)
- **S4 — `$wpdb->prepare()` on all queries** (Reason Included: BerlinDB uses prepare internally; F020's Query::replace_set MUST route through `add_item` / `delete_item` — no raw `$wpdb->query()` allowed. Source: CONSTITUTION.md §III.)
- **S1 — Nonce verification on forms/AJAX** (Reason Included: `@wordpress/api-fetch` nonce middleware seeded with `wp_create_nonce('wp_rest')` in the localize payload. Source: CONSTITUTION.md §III.)

## Related Historical Lessons

- **B7 — Mass-assignment via forged POST payload** (Reason Included: `ToolsController::post_tools()` MUST filter the submitted `tools` array against `wp_get_abilities()` catalog and reject unknown slugs — FR-022. Do NOT pass unfiltered slugs to `Query::replace_set`.)
- **B21 — BerlinDB v3 rejects `date_updated`; use `modified`** (Reason Included: F020 `Schema::$columns` has an `updated_at` column intended to auto-stamp on write. The recognized flag is `'modified' => true`, NOT `'date_updated'`. Grep gate mandatory before merge.)
- **B22 — @wordpress/* v0.x packages need runtime store lookup** (Reason Included: `src/js/tools.js` will reference the `core/abilities` store (F017 pattern) — must use string-key lookup + REST fallback, not build-time import.)
- **B17 — `rest_url()` trailing slash** (Reason Included: localize payload MUST wrap with `untrailingslashit()` — F017 already handles this; F020 mirrors.)
- **F017 Worklog** (2026-07-07, Reason Included: direct architectural sibling; note that F017 established the resolver pattern which F020 deliberately does NOT follow — presence-based storage is a distinct problem shape and the divergence is captured in the planning doc's DEC-TOOL-SELECTION-PRESENCE-MODEL.)

## Conflict Warnings

- **HARD (RESOLVED 2026-07-09)** — FR-028 originally said the table "MUST be dropped" on uninstall unconditionally, violating **DEC-UNINSTALL-OPT-IN-GATE** (Active F012). **Resolution applied**: FR-028 + the Q3 clarification bullet + the Assumptions section now specify DROP + option-delete happen only when `acrossai_mcp_uninstall_delete_data === 1`, live BELOW the existing opt-in short-circuit in `uninstall.php`, and add NO second gate. Spec re-checks Clean. No longer blocks planning.
- **HARD (RESOLVED post-security-review 2026-07-09)** — Runtime enforcement path was unspecified (SEC-020-001, security review HIGH). Plan artifacts described storage + UI + REST + observability but no consumer of the tools table at the MCP tool-call boundary. **Resolution applied**: FR-029 added (spec.md) naming `mcp_adapter_pre_tool_call` at priority 30, deny-precedence, fail-open, protocol-tool bypass; `contracts/enforcement.md` created (7-scenario callback contract); `data-model.md §Runtime Enforcement Consumer` documents the `ToolExposureGate` class + priority-slot table; `plan.md §Constitution Check §Principle III` reflects the closure. Reuses F017's D18 (`mcp_adapter_pre_tool_call` = canonical enforcement hook) — no new decision to capture beyond F020's priority slot. F020's priority-30 slot updates the D18 slot map (10 = F015, 20 = F017, 30 = F020). No hard conflicts remaining.
- **MEDIUM (RESOLVED post-security-review 2026-07-09)** — Concurrent `replace_set()` race (SEC-020-002). **Resolution applied**: FR-030 added specifying explicit `START TRANSACTION` / `COMMIT` / `ROLLBACK` wrap; `data-model.md §Query::replace_set()` documents the 3-step try/catch shape; SC-011 added.
- **MEDIUM (RESOLVED post-security-review 2026-07-09)** — Contract inconsistency on server-deletion cascade hook (SEC-020-003 / architecture Medium). **Resolution applied**: FR-026 rewritten to name the concrete BerlinDB-native `mcp_server_deleted` action; `data-model.md §Deletion hook` documents the vendor fire site + the two admin caller code paths.
- **LOW (RESOLVED post-security-review 2026-07-09)** — Observer 500-risk on POST (SEC-020-004). **Resolution applied**: FR-031 added specifying controller-side try/catch around each `do_action` fire; `contracts/rest-api.md §Side effects on 200` shows the exact loop; `contracts/js-hooks.md §PHP Action` guarantees isolation.
- **INFO (RESOLVED post-security-review 2026-07-09)** — REST args schema for POST body (SEC-020-005). **Resolution applied**: `contracts/rest-api.md §Route 2` now includes explicit `args` block with `type=array`, `items.type=string`, `sanitize_callback`, `validate_callback`.
- **HIGH (RESOLVED post-security-review-v2 2026-07-09)** — SEC-020-007 closure regression: `contracts/enforcement.md` originally used `$server instanceof \WP\MCP\Server` and `$server->get_id()` — wrong class name (`\WP\MCP\Core\McpServer`) and wrong accessor (`get_server_id(): string`). As written, callback would fail-open every real request → enforcement no-op → same effective outcome as pre-remediation SEC-020-001. **Resolution applied**: `contracts/enforcement.md §2` rewritten to use duck-typed `method_exists( $server, 'get_server_id' )` feature detection + slug-to-integer resolution via `MCPServerQuery::instance()->query()` — mirrors F017 `AbilityExposureGate::gate_tool_call_by_exposure()` at `includes/MCP/AbilityExposureGate.php:98-119` line-for-line. `data-model.md §Callback semantics §2` updated in lockstep. Added Test 10 to `contracts/enforcement.md §Test Coverage` as SEC-020-007 anti-regression guard.
- **LOW (RESOLVED post-security-review-v2 2026-07-09)** — SEC-020-008 TX isolation level unspecified. **Resolution applied**: FR-030 rewritten to mandate `SELECT ... FOR UPDATE` row-range lock at start of transaction. Data-model.md §replace_set shows the exact prepared-statement lock acquisition. Overlapping saves on the same server now serialize cleanly (no deadlocks under this shape); saves on different servers still proceed independently.
- **LOW (RESOLVED post-security-review-v2 2026-07-09)** — SEC-020-009 server_id path param args schema. **Resolution applied**: `contracts/rest-api.md §Route 2 §args` now includes an explicit `server_id` entry alongside the `tools` entry — `type=integer`, `sanitize_callback=absint`, `validate_callback` rejecting non-positive values with `rest_invalid_id`.
- **INFO (RESOLVED post-security-review-v2 2026-07-09)** — SEC-020-010 500 response body composition. **Resolution applied**: `contracts/rest-api.md §Route 2 §Response — 500` documents the exact JSON shape (`code=acrossai_mcp_tools_save_failed`, generic message, no exception detail leak). Server-side `error_log` format documented.
- **INFO (RESOLVED post-security-review-v2 2026-07-09)** — SEC-020-011 cascade per-row loop. **Resolution applied**: `data-model.md §delete_items_for_server` rewritten to use a single `$wpdb->delete()` statement with `WHERE server_id = %d` + `wp_cache_flush_group()` — one SQL round-trip per server delete instead of N.
- **SOFT (proceed with new DEC capture)** — the shuttle-picker UX violates **DEC-WP-DATAVIEWS-OVER-REACT** + **A4** in letter (DataViews mandate) but not in spirit (only `@wordpress/*` packages are used; no Tier-2 React libs are added). The DEC's reconsider clause requires: (a) explicit justification that DataViews can't express the UX, (b) a new DEC-* entry. **Resolution**: proceed with the divergence; capture `DEC-TOOL-SHUTTLE-PICKER-OVER-DATAVIEWS` via `/speckit-memory-md-capture` post-implement.
- **SOFT (informational)** — presence-based storage diverges from **DEC-ABILITY-OVERRIDE-RESOLUTION**'s single-resolver pattern. Different problem shape (no fallback layer needed). Captured as new decision `DEC-TOOL-SELECTION-PRESENCE-MODEL` in the planning doc.

## Retrieval Notes

- Index entries considered: 20 (all Active Decisions + top 4 Bug Patterns + 3 Security Constraints).
- Source sections read: DECISIONS.md lines 494–535 (F011 BerlinDB pair), 604–639 (F012 uninstall gate), 1167–1195 (F017 DataViews mandate) — targeted seeks, not full-file reads.
- Full memory read: **not** performed (config allows: false).
- Budget status: 5 decisions / 5 constraints / 1 deviation / 3 security / 5 lessons / 1 worklog / ~830 words — within all limits.
- Optimizer: disabled (config `optimizer.enabled: false`); markdown-only retrieval used.
