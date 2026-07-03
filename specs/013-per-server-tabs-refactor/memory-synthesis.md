# Memory Synthesis

## Current Scope

F013 refactors the per-server-edit page into a per-tab class hierarchy under `admin/Partials/ServerTabs/` (AbstractServerTab + Registry + 11 tab subclasses); ports 7 missing tabs + 2 WP_List_Table classes; introduces a **public Renderer layer** under `public/Renderers/` (3 Block classes) that third-party plugins consume via 3 shortcodes + 2 filters + 1 action hook + 1 REST endpoint. Consumes F011 BerlinDB Query, F004 MCPClients, F012 Settings toggles, F007 FrontendAuth — no new DB, no new required plugins. API is `@experimental` until 1.0.0. Modules: `admin/Partials/`, `public/Renderers/` (new), `includes/REST/`, `includes/Main.php`, `docs/integrations/` (new).

## Relevant Decisions

- **D8** — Access Control tab MUST use `class_exists('\WPBoilerplate\AccessControl\AccessControlManager')` guard (Reason: AccessControlTab port preserves the D8 pattern verbatim; also applies to McpTrackerTab (`\WPVMCPT\Plugin`) + AbilitiesTab (`\AcrossAI_Abilities_Manager\Includes\Runtime`). Status: Active. Source: DECISIONS.md).
- **D14** — Cross-phase state observation via public-static predicate on the owning module (Reason: F013 introduces `acrossai_mcp_client_classes` filter — same family; consumers hook the filter without duplicating client-registry magic strings. Status: Active. Source: DECISIONS.md).
- **DEC-VENDOR-SETTINGS-TAB-INTEGRATION** — Singleton + template method + shared helper pattern; accepted §IV DataForm carve-out for Settings API surfaces (Reason: `AbstractServerTab` + `Registry` echo this pattern verbatim; F013 Renderer layer inherits the same carve-out precedent — App Password generation is a WordPress-core Application Password creation flow, not a data-entry form. Status: Active F012. Source: DECISIONS.md).
- **DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG** — Standalone admin submenus for DB-inspection are pruned; per-server-tab inspection is EXPLICITLY blessed (Reason: **LOAD-BEARING** for F013. TASK-3 restores `CliAuthLogListTable` as a per-server npm-tab inspector. This DEC explicitly permits the reintroduction. Cite in `CliAuthLogListTable.php` docblock. Status: Active F012. Source: DECISIONS.md).
- **DEC-BERLINDB-TABLE-REQUEST-BOOT** — BerlinDB Table subclasses MUST be instantiated at request time via `Main::load_hooks()` (Reason: F013 tab bodies call `MCPServerQuery`, `CliAuthLogQuery`, `OAuthAuditQuery` — all rely on F011's request-time boot. If tab code runs before boot, Query fails silently. Status: Active F011. Source: DECISIONS.md).

## Active Architecture Constraints

- **A1** — All hook registration lives exclusively in `includes/Main.php::define_admin_hooks()` / `define_public_hooks()` (Reason: F013 wires 4+ new hooks — `acrossai_mcp_client_classes` filter, `acrossai_mcp_render_client_block` action, 3 shortcodes, REST route. All MUST be Loader-wired, not in constructors. Source: ARCHITECTURE.md).
- **A2** — Every feature class uses the singleton `instance()` pattern (Reason: `Registry` + 3 `*ClientBlock` Renderer classes must be singletons; A11 exception applies to `AbstractServerTab` subclasses since they're stateless render helpers. Source: ARCHITECTURE.md).
- **A6** — Any class in `Includes` MUST use `use` imports or FQN with leading `\` when referencing sub-namespace classes (Reason: F013's tab classes reference `MCPServerQuery`, `AbstractMCPClient`, `AdminPageSlugs`, `FrontendAuth`, etc. — all cross-namespace; bare relative names silently fail. Source: ARCHITECTURE.md).
- **A9** — Shared admin constants live in `includes/Utilities/` (Reason: F013 tab classes consume `AdminPageSlugs::PARENT` for URL building; ban duplication of `'acrossai_mcp_manager'` literal in tab bodies. Source: ARCHITECTURE.md).
- **A10** — WP_List_Table subclasses exempted from singleton rule; public ctor required (Reason: F013's ported `CliAuthLogListTable` + `ConnectorAuditLogListTable` use A10 exemption; never Loader-wired; instantiated per-render inside the Renderer body. Source: ARCHITECTURE.md).

## Accepted Deviations

- **DEV1** (Accepted-Deviation) — WP_List_Table on `?page=acrossai_mcp_manager` (Reason: DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG *narrows* DEV1 to shared pages + per-server-tab inspectors, NOT standalone submenus. F013's 2 ported ListTables are per-server-tab inspectors → on-pattern. Status: Accepted-Deviation, narrowed F012).
- **DEV4** (Accepted-Deviation) — Shared parent-menu bootstrap in plugin entry file (Reason: F013's shortcode registration on `init` inside `define_public_hooks()` runs AFTER vendor package is bootstrapped per DEV4; no interaction. Status: Accepted-Deviation).
- **§IV DataForm carve-out** — Vendor Settings API surface exempted (Reason: F013 Renderer layer displays config JSON + generates App Passwords; not a data-entry form. Per DEC-VENDOR-SETTINGS-TAB-INTEGRATION precedent, accepted carve-out. Status: Accepted-Deviation, precedent from F012).

## Relevant Security Constraints

- **S1** — All forms + AJAX endpoints MUST verify nonce (Reason: F013 `AbstractServerTab::nonce_field()` + Renderer's context-bound nonces + `/generate-app-password` REST route all bind nonce to server_id AND context slug for cross-context replay defense per FR-022. Source: CONSTITUTION.md §III).
- **S2** — All REST routes MUST have explicit `permission_callback` (Reason: `/generate-app-password` MUST reject requests where `$body['user_id'] !== get_current_user_id()` — no `__return_true` fallback per FR-023/024. Source: CONSTITUTION.md §III).
- **S6** — Singleton `__construct()` MUST be private (Reason: `Registry` + 3 Renderer classes are singletons; private ctor + `instance()` static method per A2 + F012 SettingsMenu pattern. Source: PROJECT_CONTEXT.md).

## Related Historical Lessons

- **B9** — PHPUnit 13+ needs `#[DataProvider]` attribute (Reason: F013 tests explicitly cited to use `#[DataProvider]` — RegistryTest, AbstractServerTabTest, PublicApiTest all use this per B9. Source: BUGS.md).
- **B15** — Grep gates must handle leading-`\` FQN + short-name aliased forms (Reason: F013 defines 5 grep gates — removal grep, legacy-namespace grep, exact-count `class .*Tab extends`, no `<form method="post"`, no `wp_nonce_field(`. Must use ERE with `\\?` or two passes. Source: BUGS.md).
- **B16** — Mixed positional (`%s`) + numbered (`%1$s`) printf placeholders silently mislabel output (Reason: F013 Renderer helpers `render_feature_disabled_notice()` + `json_config_block()` do printf with translated HTML — MUST use ONE placeholder style consistently. Cite F012 spec's discovery incident. Source: BUGS.md).

## Conflict Warnings

- **SOFT — §IV DataForm vs. Renderer layer**: Renderer displays config JSON + generates App Passwords, not a data-entry form. DEC-VENDOR-SETTINGS-TAB-INTEGRATION precedent applies. Reaffirm carve-out in DEC-CLIENT-RENDERER-PUBLIC-API at TASK-9.
- **SOFT — CliAuthLogListTable reintroduction**: F012 deleted the standalone admin surface; F013 restores as per-server-tab inspector. DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG explicitly permits. Cite in file docblock.
- **No HARD conflicts** identified.

## Retrieval Notes

Index entries considered: 40+. Selected: 21 entries within budget (5+5+3+3+3+2). Constitution not opened — DECs cite principles by reference. Source sections read: F011 DEC-BERLINDB-TABLE-REQUEST-BOOT, F012 DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG + DEC-VENDOR-SETTINGS-TAB-INTEGRATION, B16. Optimizer: disabled; markdown-only index-first retrieval.
