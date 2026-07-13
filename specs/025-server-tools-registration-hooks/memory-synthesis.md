# Memory Synthesis

## Current Scope

Feature 025 (branch `025-server-tools-registration-hooks`) wires the per-server tool selection saved by Feature 020 into the MCP adapter's `create_server()` call for both server-registration paths, adds three `tinyint(1) NOT NULL DEFAULT 1` columns on `wp_acrossai_mcp_servers` for built-in-default enablement, makes those defaults UI-removable via a `ConfirmDialog`, and exposes `acrossai_mcp_manager_server_tools` for companion plugins on the database-server path (using the vendor's `mcp_adapter_default_server_config` filter for the default-server path). Affected modules: `includes/Database/MCPServer/{Schema,Table,Row,ToolPolicy}.php`, `includes/MCP/Controller.php`, `includes/REST/ToolsController.php`, `includes/Main.php`, `src/js/tools.js`.

## Relevant Decisions

- **DEC-BERLINDB-TABLE-REQUEST-BOOT** (Reason Included: F025 bumps `MCPServer\Table::$version` `1.0.0` → `1.1.0`; the request-time boot pattern from F011 already instantiates the Table subclass via `Main::bootstrap_database_tables()`, so the ALTER-on-first-request path is preserved without new wiring; Status: Active F011; Source: DECISIONS.md).
- **DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION** (Reason Included: F025 touches `MCPServer\Row` and `MCPServer\Schema` — reviewers must confirm no new `use BerlinDB\Database\Kern\{Row,Schema}` imports are added; Status: Active F011; Source: DECISIONS.md).
- **DEC-TOOL-SELECTION-PRESENCE-MODEL** (Reason Included: F020's presence-based storage is the direct precedent for the curated-tools layer, which F025 keeps unchanged; the protocol-columns half of F025's hybrid model is a soft-conflict deviation — see Conflict Warnings below; Status: Active F020 [supplemented F025]; Source: DECISIONS.md).
- **DEC-F020-TOOL-ENFORCEMENT-PRIORITY** (Reason Included: F025 does NOT add a new gate on `mcp_adapter_pre_tool_call`; the priority slot map (10/20/30) stays intact and `ToolExposureGate::EXCLUDED_SLUGS` remains the safety net for cached clients; Status: Active F020; Source: DECISIONS.md).
- **DEC-WP-DATAVIEWS-OVER-REACT** (Reason Included: F025's UI work in `src/js/tools.js` uses `@wordpress/components` `ConfirmDialog` for both the remove-protocol-tool and Reset dialogs — no generic React libs; existing bundle uses `@wordpress/element` + `@wordpress/api-fetch` + `@wordpress/i18n`; Status: Active F017; Source: DECISIONS.md).

## Active Architecture Constraints

- **A1** — All hook registration in `Main.php` via `define_admin_hooks()`/`define_public_hooks()` (Reason: F025 adds exactly one `$this->loader->add_filter( 'mcp_adapter_default_server_config', $mcp_controller, 'filter_default_server_config' )` — MUST land in `define_admin_hooks()` next to the existing F009 wiring at line 513; Source: ARCHITECTURE.md).
- **A2** — Singleton `instance()` pattern (Reason: `Controller` stays singleton; new `filter_default_server_config()` method preserves the shape; Source: ARCHITECTURE.md).
- **A6** — Cross-namespace refs use `use` imports or leading-`\` FQN (Reason: New `ToolPolicy` file adds `use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as MCPServerToolQuery;`; `Controller` gains `use ... MCPServer\ToolPolicy`; watch for bare relative-name silent failures per B1; Source: ARCHITECTURE.md).
- **A11** — Pure service class exemption (Reason: `ToolPolicy` is stateless with `public static compose_for_row()` + `public static split_payload()` — mirrors `DefaultServerSeeder`'s shape; NOT a singleton, no ctor; Source: ARCHITECTURE.md).
- **A4** — New admin UI uses DataForm/DataViews (Reason: F025 modifies an EXISTING JS module — no new admin surface; DataForm/DataViews mandate does not extend to editing `src/js/tools.js`. The `ConfirmDialog` addition is a `@wordpress/components` primitive, aligned with A4's spirit; Source: ARCHITECTURE.md).

## Accepted Deviations

- None directly relevant to F025. `DEV1` (MCP Manager parent menu `WP_List_Table`) does not extend to the Tools tab (React). No new deviation needed.

## Relevant Security Constraints

- **S2** — All REST routes MUST have explicit `permission_callback` (Reason: `ToolsController` GET/POST already check `manage_options` per F020 baseline; F025 preserves both; the `EXCLUDED_SLUGS` guard being deleted is validation, not authorization — no S2 impact; Source: CONSTITUTION.md §III).
- **S4** — All DB queries MUST use `$wpdb->prepare()` (Reason: F025's new writes go through `MCPServerQuery::update_item()` and `MCPServerToolQuery::replace_set()` — both BerlinDB paths that prepare; no raw SQL added; Source: CONSTITUTION.md §III).
- **S6** — Singleton `__construct()` MUST be private (Reason: `ToolPolicy` is exempt via A11 — no ctor at all; existing singletons touched by F025 (Controller, MCPServerQuery, MCPServerToolQuery) already conform; Source: PROJECT_CONTEXT.md).

## Related Historical Lessons

- **B18** — `$wpdb` returns TINYINT columns as strings; strict-compare (`1 === $row->col`) is always false. `Row::__construct()` MUST cast `tool_discover_abilities`, `tool_get_ability_info`, `tool_execute_ability` to `(int)` (spec calls this out already). `ToolPolicy::compose_for_row()` MUST use `! empty()` or `(int)`-cast checks — never `=== 1`. Reason: Directly loadbearing for F025's new columns.
- **B21** — BerlinDB v3 Column flags — `date_updated` is unrecognized; datetime auto-update uses `modified`. Reason: F025's new columns are `tinyint`, not datetime, so `date_updated` doesn't apply — but reviewers of new Schema entries should be reminded of B21's grep gate posture.
- **B24** — Vendor accessor via `method_exists`, not `instanceof` (F020 SEC-020-007). Reason: F025's `filter_default_server_config()` receives an array `$config` (not a vendor object) so B24 does not directly apply. `Controller::register_database_servers()` receives `\WP\MCP\Core\McpAdapter $adapter` — F025 only calls `$adapter->create_server(...)` which was the vendor contract at F009; no new vendor-accessor surface introduced.
- **DEC-ABILITY-OVERRIDE-RESOLUTION** (F017) — Every consumer of "effective ability exposure" routes through `ExposureResolver::resolve()`. F025's `ToolPolicy::compose_for_row()` is the direct analog for tools — the single canonical composer that both server-registration paths and the REST GET handler must use. No consumer may re-derive the union inline.

## Conflict Warnings

- **Soft conflict — `DEC-TOOL-SELECTION-PRESENCE-MODEL` (F020) vs. F025's protocol-columns storage.** DEC prefers presence-based rows when UX models two-state set-membership with no third state. F025 uses `tinyint(1) DEFAULT 1` columns for the three protocol slugs. **Justification for deviation (proposed):** (1) The DEC explicitly excepts F017's "boolean-with-resolver pattern when a fallback layer exists" — F025's `DEFAULT 1` IS the fallback layer (fresh + upgraded rows both land in the enabled state without explicit action). (2) Protocol tools are a fixed cardinality-3 known set; presence-based storage would require reserving three specific slugs in the open-ended `wp_acrossai_mcp_server_tools` table, diluting its semantic. (3) Reset flips all three atomically via one column UPDATE — presence storage would need three DELETE+INSERT round trips. (4) The curated half of F025's model stays presence-based (unchanged from F020) — the deviation is scoped to the fixed protocol set. **Action for planning:** capture as a new decision `DEC-F025-HYBRID-TOOL-STORAGE-PROTOCOL-VS-CURATED` supplementing (not superseding) DEC-TOOL-SELECTION-PRESENCE-MODEL, per D22's fold-in convention.

## Retrieval Notes

- Config: `.specify/extensions/memory-md/config.yml` present; `optimizer.enabled: false` — proceeded with markdown-only, index-first retrieval.
- Read: `docs/memory/INDEX.md` (full — 142 lines, single index sweep).
- Selected: 5 decisions, 5 architecture constraints, 0 deviations, 3 security constraints, 3 bug patterns, 1 additional lesson (DEC-ABILITY-OVERRIDE-RESOLUTION as historical lesson). Under budget on every axis.
- Deferred / not loaded: full `DECISIONS.md`, full `ARCHITECTURE.md`, `BUGS.md`, `WORKLOG.md` — index summaries sufficient for planning. If planning uncovers a new conflict, load the specific source section.
- No feature `memory.md` present at `specs/025-server-tools-registration-hooks/memory.md`.
- Word count: ~870 (under 900-word budget).
- No hard conflicts detected. One soft conflict (see Conflict Warnings) — proceed to `/speckit-plan` with the proposed deviation justification; capture the new decision via `/speckit-memory-md-capture` after plan approval.
