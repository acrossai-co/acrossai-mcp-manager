# Implementation Plan: Remove Claude Connectors

**Branch**: `016-remove-claude-connectors` | **Date**: 2026-07-07 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/016-remove-claude-connectors/spec.md`

## Summary

Retire the Claude Connectors feature (OAuth 2.1 authorization server + admin tab + per-server audit log + settings toggle + client-block shortcode + CSS bundle) and its shared OAuth infrastructure (`Storage`, `TokenController`, `BearerAuth`, `PKCE`, `AuditLog`, `CliCommand`). Also retire the two dedicated OAuth BerlinDB modules (`includes/Database/OAuthToken/`, `includes/Database/OAuthAudit/`) and the three `claude_connector_*` column definitions on `wp_acrossai_mcp_servers`. Preserve the CLI auth stack in full (`FrontendAuth`, `CliController`, `wp_acrossai_mcp_cli_auth_logs`, `includes/Database/CliAuthLog/`) and the two remaining client-block shortcodes (npm, clients).

**Scope reduction (user directive 2026-07-07)**: The plugin ships fresh-install-only. **No in-plugin migration code is added.** The operator retires any pre-Feature-016 physical data manually — `DROP TABLE wp_acrossai_mcp_oauth_tokens; DROP TABLE wp_acrossai_mcp_oauth_audit;` followed by `ALTER TABLE wp_acrossai_mcp_servers DROP COLUMN claude_connector_client_id, DROP COLUMN claude_connector_client_secret, DROP COLUMN claude_connector_redirect_uri;` — then deactivate + reactivate the plugin. This removes the entire self-heal path: no `ConnectorColumnMigration` fallback helper, no idempotent `DROP TABLE IF EXISTS` in `Activator::activate()`, no `delete_option` cleanup calls, no `$version` bump on `MCPServer/Table.php`. Retirement in code = pure deletion.

The technical approach is entirely subtractive edits governed by two durable-memory gates: (a) `DEC-BERLINDB-TABLE-REQUEST-BOOT` — both deleted BerlinDB modules must be un-registered from `Main::bootstrap_database_tables()`; (b) `D6/A6/B1` — every stale `use` import must be purged to prevent silent-fail on bare relative names. `DEC-UNINSTALL-OPT-IN-GATE` remains relevant for `uninstall.php` hygiene but does not require new destructive statements; existing OAuth table entries in the drop-list stay as an idempotent safety net (`DROP TABLE IF EXISTS` is a no-op when the operator has already dropped them manually).

## Technical Context

**Language/Version**: PHP 8.0+ (WP 6.9+ target); no JS changes; SCSS deletion only.
**Primary Dependencies**: `berlindb/core: ^3.0.0` (retained), `wpboilerplate/wpb-mcp-servers-list` (untouched), `wpboilerplate/wpb-access-control ^2.0.0` (untouched), `wordpress/mcp-adapter` (untouched). No new dependencies added; no dependencies removed from `composer.json` (the deleted OAuth code was internal, not vendored).
**Storage**: WordPress custom tables (BerlinDB-managed). Post-016 fresh-install state: 2 tables (`wp_acrossai_mcp_servers` @ 10 cols, `wp_acrossai_mcp_cli_auth_logs` unchanged). Retired physically: `wp_acrossai_mcp_oauth_tokens`, `wp_acrossai_mcp_oauth_audit` — operator drops manually. Retired in code: BerlinDB modules under `includes/Database/OAuthToken/` and `includes/Database/OAuthAudit/`.
**Testing**: PHPUnit for PHP unit + integration; `composer test` command; `tests/phpunit/OAuth/` directory retired entirely (22 files); `tests/phpunit/Public/MainEnqueueTest.php` retired; 3 test files pruned (`SettingsMenuTest`, `RegistryTest`, `PublicApiTest`).
**Target Platform**: WordPress 6.9+ single-site primary; multisite behavior unchanged by this feature.
**Project Type**: WordPress plugin (PHP + SCSS build via `@wordpress/scripts` webpack).
**Performance Goals**: Retirement reduces work — one fewer daily cron, one fewer REST route, one fewer `determine_current_user` filter, ~4,000 fewer lines of code loaded on every request. No performance regressions expected.
**Constraints**:
- No BerlinDB `$version` bump. Schema-defined column set on fresh install matches the 10-column reality; there is no runtime diff to reconcile because operator has already dropped the columns manually.
- Surviving 10 columns' `CREATE TABLE` DDL MUST remain identical to the pre-Feature-016 definition of those columns (no incidental drift — BerlinDB's diff engine WILL fire an `ALTER TABLE` if any type/length/default changes).
- Uninstall path continues to include the OAuth tables in `DROP TABLE IF EXISTS` list as an idempotent safety net; entries are harmless when the operator has already dropped the tables.
- Grep audit (FR-015) MUST return zero matches for the retirement-symbol regex under `includes/`, `admin/`, `public/`, `src/`, `tests/`, `webpack.config.js`, `uninstall.php`, `acrossai-mcp-manager.php`.
**Scale/Scope**: 12 files deleted outright (7 PHP + 1 SCSS + 22 test files after directory removal), 15 files modified surgically. ~4,000 lines of code retired.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Feature 016 is a subtractive-edit feature. Every constitution principle applies but yields a "reduces scope, not violates" verdict:

| Principle | Verdict | Notes |
|---|---|---|
| **I. Modular Architecture** | PASS | Retires one of the 5 active feature areas listed in Principle I's Rationale ("OAuth / Claude Connectors"). The constitution's Rationale must be annotated post-implementation to reflect 4 active areas + 1 retired (memory-hygiene, not a gate failure). No new module coupling introduced. |
| **II. WordPress Standards** | PASS | PHPCS + PHPStan L8 gates apply per DoD; no new PHP added (the optional `ConnectorColumnMigration` helper uses `$wpdb->prepare()` if implemented). |
| **III. Security First** | PASS | Feature REDUCES attack surface: one fewer REST route, one fewer bearer-token filter, no more OAuth discovery endpoints. The **Consent-surface exception** (added 2026-06-30 for Feature-007) was drafted broadly enough to cover FrontendAuth AND the retired OAuth consent form. Post-016 the sole exception consumer is `public/Partials/FrontendAuth.php` — the exception remains valid and DOES NOT need supersession. The S7 exception ("OAuth token endpoint `__return_true`, exactly one match in `includes/OAuth/`") is orphaned by this feature; PROJECT_CONTEXT.md needs annotation (memory-hygiene follow-up, not a gate failure). |
| **IV. User-Centric Design** | PASS | No new admin UI added. `WP_List_Table` exception for MCP Manager parent menu preserved. `DEC-CLIENT-RENDERER-PUBLIC-API`'s 3-shortcode surface shrinks to 2 — the DataForm carve-out for the Renderer layer remains intact for the surviving 2 shortcodes. |
| **V. Extensibility** | PASS | Removal is via the same hook/module patterns used to add features (subtractive edits to `Main.php::define_public_hooks()`, `Registry::all_tabs()`, `ClientRendererController::register_shortcodes_and_actions()`). No core-modification. |
| **VI. Reusability & DRY** | PASS | No duplication introduced. Optional `ConnectorColumnMigration` helper (only if BerlinDB fallback needed) is a single-purpose class in the same `includes/Database/MCPServer/` module as the schema it migrates. |
| **VII. Definition of Done** | PASS | All 10 DoD gates apply; spec's Success Criteria already map to them. |

**Directory Layout impact** (constitution §Architecture): the layout lists `includes/OAuth/` as "Claude Connectors OAuth 2.0 flow". After Feature 016 the directory is empty and removed. The constitution's Directory Layout diagram needs annotation post-implementation to remove the `includes/OAuth/` line — this is a memory-hygiene follow-up (proposed via `/speckit-memory-md-capture` post-implementation), NOT a gate failure. The layout is descriptive, not prescriptive.

**Attention required (soft, memory-hygiene follow-ups)**:
- `PROJECT_CONTEXT.md::S7` — annotate that no consumers remain post-016.
- `DECISIONS.md::DEC-CLIENT-RENDERER-PUBLIC-API` — annotate that surface shrinks to 2 shortcodes / 2 map entries.
- `ARCHITECTURE.md::A13` — annotate that no active consumers remain (constraint still valid for future RFC forms).
- Constitution Principle I Rationale + Architecture Directory Layout — remove `OAuth / Claude Connectors` from active-area list and remove `includes/OAuth/` from directory diagram.

**GATE VERDICT: PASS.** No hard violations. All 4 memory-hygiene items are handled post-implementation via `/speckit-memory-md-capture-from-diff`, not by Feature 016 itself.

## Project Structure

### Documentation (this feature)

```text
specs/016-remove-claude-connectors/
├── plan.md                    # This file (/speckit-plan output)
├── spec.md                    # Feature specification
├── memory-synthesis.md        # Memory context (from /speckit-memory-md-plan-with-memory)
├── research.md                # Phase 0 output (this command)
├── data-model.md              # Phase 1 output (this command)
├── quickstart.md              # Phase 1 output (this command)
├── contracts/                 # Phase 1 output (this command)
│   └── retired-artifacts.md   # Machine-readable list of retired public API surfaces
├── checklists/
│   └── requirements.md        # Spec-quality checklist (from /speckit-specify)
└── tasks.md                   # Phase 2 output (/speckit-tasks — NOT created here)
```

### Source Code (repository root)

Subtractive-edit feature. Below is the effective post-Feature-016 layout, with `[DELETE]`, `[EDIT]`, and `[UNCHANGED]` annotations:

```text
acrossai-mcp-manager/
├── acrossai-mcp-manager.php              # [UNCHANGED] plugin entry
├── uninstall.php                         # [UNCHANGED — already lists OAuth tables in DROP list AFTER opt-in gate; safety net stays intact]
├── webpack.config.js                     # [EDIT] remove 'css/frontend-oauth' entry
│
├── admin/Partials/
│   ├── Settings.php                      # [EDIT] delete save_claude_connector branch + handle_save_claude_connector()
│   ├── SettingsMenu.php                  # [EDIT] delete Claude Connectors section/toggle/description
│   ├── ConnectorAuditLogListTable.php    # [DELETE]
│   └── ServerTabs/
│       ├── Registry.php                  # [EDIT] remove ClaudeConnectorTab from all_tabs()
│       ├── ClaudeConnectorTab.php        # [DELETE]
│       └── [10 other tab classes]        # [UNCHANGED]
│
├── includes/
│   ├── Main.php                          # [EDIT] delete 5 hook registrations (ClaudeConnectors bundle, TokenController, BearerAuth, Public\Main::enqueue_styles, Public\Main::enqueue_scripts) + 2 Table::instance() boot calls (OAuthToken + OAuthAudit)
│   ├── Activator.php                     # [EDIT] delete ClaudeConnectors register + OAuth cron schedule + 2 Table maybe_upgrade calls + stale use imports. NO new cleanup code (fresh-install-only).
│   ├── Deactivator.php                   # [EDIT] delete wp_clear_scheduled_hook('acrossai_mcp_oauth_cleanup')
│   ├── OAuth/                            # [DELETE] entire directory (7 files + directory)
│   ├── Database/
│   │   ├── OAuthToken/                   # [DELETE] entire directory (4 files + directory)
│   │   ├── OAuthAudit/                   # [DELETE] entire directory (4 files + directory)
│   │   ├── MCPServer/
│   │   │   ├── Schema.php                # [EDIT] delete 3 claude_connector_* column definitions
│   │   │   ├── Table.php                 # [UNCHANGED] no $version bump; preserve phantom-version guard override verbatim
│   │   │   ├── Row.php                   # [EDIT] delete 3 public properties + 3 to_array() entries
│   │   │   └── DefaultServerSeeder.php   # [EDIT] delete 3 seed columns + matching %s specifiers
│   │   └── CliAuthLog/                   # [UNCHANGED]
│   ├── REST/
│   │   ├── ClientRendererController.php  # [EDIT] delete claude-connector shortcode + dispatch map entry + use import
│   │   └── CliController.php             # [UNCHANGED]
│   └── [other includes/]                 # [UNCHANGED]
│
├── public/
│   ├── Main.php                          # [EDIT] delete enqueue_styles + enqueue_scripts methods + ClaudeConnectors use + OAUTH_STYLE_HANDLE
│   ├── Renderers/
│   │   ├── ClaudeConnectorBlock.php      # [DELETE]
│   │   ├── AbstractClientRenderer.php    # [UNCHANGED]
│   │   ├── NpmClientBlock.php            # [UNCHANGED]
│   │   └── MCPClientsBlock.php           # [UNCHANGED]
│   └── Partials/
│       └── FrontendAuth.php              # [UNCHANGED] CLI auth stack, out of scope
│
├── src/
│   └── scss/
│       ├── frontend-oauth.scss           # [DELETE]
│       ├── frontend.scss                 # [UNCHANGED] CLI auth stylesheet
│       └── backend.scss                  # [UNCHANGED]
│
├── build/                                # [REBUILD] `npm run build` regenerates without frontend-oauth outputs
│
└── tests/phpunit/
    ├── OAuth/                            # [DELETE] entire directory (22 files including fixtures/)
    ├── Public/MainEnqueueTest.php        # [DELETE]
    ├── Admin/
    │   ├── SettingsMenuTest.php          # [EDIT] remove connector assertions
    │   └── ServerTabs/RegistryTest.php   # [EDIT] remove 'claude-connector' slug + update tab count
    └── Public/Renderers/
        └── PublicApiTest.php             # [EDIT or DELETE] read first; delete if connector-only
```

**Structure Decision**: Preserve the current plugin directory layout; the only structural change is removing the empty `includes/OAuth/`, `includes/Database/OAuthToken/`, and `includes/Database/OAuthAudit/` directories after their contents are deleted. No new directories introduced.

## Complexity Tracking

Constitution Check has zero hard violations. The only "complexity" is the conditional `ConnectorColumnMigration` fallback helper, and it is not a violation of any principle — it is a single-purpose class in the same module as the schema it migrates, gated behind an existence check to remain idempotent. No table entries required.
