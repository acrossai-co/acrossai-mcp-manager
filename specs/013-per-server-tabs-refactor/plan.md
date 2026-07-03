# Implementation Plan: Port per-server-edit tabs to a common per-tab class hierarchy + Public Renderer layer (Feature 013)

**Feature Directory**: `specs/013-per-server-tabs-refactor/`
**Feature Branch**: `013-per-server-tabs-refactor`
**Spec**: `spec.md`
**Memory synthesis**: `memory-synthesis.md`
**Created**: 2026-07-03
**Status**: Draft (pre-implementation)

## Summary

F013 replaces the monolithic per-tab render methods on `admin/Partials/Settings.php` with a per-tab class hierarchy under `admin/Partials/ServerTabs/` (AbstractServerTab base + Registry dispatch + 11 tab subclasses). It ports 7 missing tabs from the reference plugin, restores 2 `WP_List_Table` subclasses (`CliAuthLogListTable`, `ConnectorAuditLogListTable`) as per-server-tab inspectors (permitted by DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG), and introduces a **new public Renderer layer** under `public/Renderers/` so third-party plugins (BuddyBoss, WooCommerce) can embed the 3 client-configuration blocks (npm, MCP Clients, Claude Connector) with zero code duplication. The public API surface is documented as `@experimental` until 1.0.0. No new DB, no new required plugins, no new composer/npm deps.

## Technical Context

**PHP Version**: 8.1+ (matches Feature 010 baseline)
**WordPress Version**: 6.9+
**Multisite**: Single-site only
**PHP dependencies**: `berlindb/core: ^3.0` (F010, unchanged); `acrossai-co/main-menu` (D15/DEV4 hard-require, unchanged). **No new dependencies.**
**Reused support layers** (do NOT re-implement — synthesis "REUSE" list):
- `\AcrossAI_MCP_Manager\Includes\Database\{MCPServer,CliAuthLog,OAuthToken,OAuthAudit}\Query` (F011)
- 7 `AbstractMCPClient` subclasses (F004)
- `\AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors` + `Storage` + `BearerAuth` + `PKCE` (F005)
- `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth::get_base_url()` (F007)
- `\AcrossAI_MCP_Manager\Admin\Partials\{SettingsMenu,ApplicationPasswords,Settings::render_edit_page(),SettingsRenderer::render_tab_nav()}` (F012 / earlier)
- `\AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs::PARENT`

**Structure change scope**: NEW `admin/Partials/ServerTabs/` (13 files), NEW `public/Renderers/` (4 files), NEW `includes/REST/ClientRendererController.php`, MODIFY `admin/Partials/Settings.php` + `includes/Main.php`, PORT 2 ListTables into `admin/Partials/`, NEW `docs/integrations/` (2 markdown files), NEW 4 PHPUnit test files.

### Concrete decisions locked at spec time (from Clarifications session)

| # | Decision | Source |
|:---|:---|:---|
| Q1 | Reference-plugin fidelity is best-effort; port adapts to F011-native shape where reference API differs. No shim adapters. | Clarifications 2026-07-03 |
| Q2 | MCP Clients sub-tab routes via `?tab=clients&client=<slug>` URL query param; JS-free; default = first registered client | Clarifications 2026-07-03 |
| Q3 | Public API is `@since 0.0.6 @experimental May change without notice before 1.0.0` on every public method/hook/filter/shortcode | Clarifications 2026-07-03 |
| Q4 | `acrossai_mcp_client_classes` filter registered; invalid FQNs silently skipped | Clarifications 2026-07-03 |
| Q5 | Ship `docs/integrations/{buddyboss,woocommerce}-example.md`; no working PHP glue plugin | Clarifications 2026-07-03 |

## Constitution Check

| Principle | Status | Notes |
|:---|:---|:---|
| **§I Modular Architecture** | ✅ | 11 tab classes + 3 Renderer classes + Registry — each self-contained + independently testable + independently replaceable. Shared helpers on AbstractServerTab / AbstractClientRenderer prevent duplication. |
| **§II WPCS + PHPStan L8** | ✅ (gate) | Whole-plugin PHPStan L8 exit 0 required; PHPCS baseline unchanged on modified files. `#[DataProvider]` per B9. |
| **§III Security** | ✅ | FR-021..024 lock down App Password generation to `get_current_user_id()`; context-bound nonces (server_id + context slug) defeat cross-context replay; every REST route has explicit `permission_callback`. |
| **§IV DataForm mandate** | ⚠ carve-out | Renderer layer displays config JSON + generates App Passwords via WordPress-core `WP_Application_Passwords` — not a data-entry form. Same DEC-VENDOR-SETTINGS-TAB-INTEGRATION precedent covers this. To be reaffirmed in DEC-CLIENT-RENDERER-PUBLIC-API at TASK-9. |
| **§V Extensibility Without Core Modification** | ✅ | `acrossai_mcp_client_classes` filter + `acrossai_mcp_client_block_context` filter + `acrossai_mcp_render_client_block` action + 3 shortcodes IS the extensibility API. Third parties never patch plugin code. |
| **§VI Reusability & DRY** | ✅ (gate) | Grep gates enforce: no `<form method="post"` or `wp_nonce_field(` in tab subclass bodies; no `<pre>` / `<textarea>` / `Configuration JSON` in the 3 client tab classes; cross-context byte-identity PHPUnit test at TASK-8. |
| **§VII Definition of Done** | ✅ (per-task) | Every TASK-N block has explicit DoD gates. Whole-plugin gate at TASK-9. |

## Project Structure

### Documentation (this feature)

```
specs/013-per-server-tabs-refactor/
├── spec.md                         (274 lines, includes 5 Clarifications)
├── plan.md                         (this file)
├── memory-synthesis.md             (887 words)
├── security-constraints.md         (to be created by /speckit-security-review-plan)
├── architecture-violations.md      (to be created by /speckit-architecture-guard-violation-detection)
├── tasks.md                        (to be created by /speckit-tasks)
├── checklists/requirements.md      (created by /speckit-specify)
└── pre-flight-reference-plugin.txt (created by TASK-1)
```

### Source Code (repository root)

```
admin/Partials/ServerTabs/           NEW — 13 files
  AbstractServerTab.php              base + shared helpers
  Registry.php                       singleton dispatch + ordered tab list + visible_for filter
  OverviewTab.php
  NpmTab.php                         thin delegate → NpmClientBlock
  ClientsTab.php                     thin delegate → MCPClientsBlock (reads ?client= for sub-nav)
  ClaudeConnectorTab.php             thin delegate → ClaudeConnectorBlock
  WpCliTab.php                       admin-only render (no Renderer)
  ToolsTab.php                       admin-only render
  AbilitiesTab.php                   function_exists('wp_get_abilities') guarded
  AccessControlTab.php               class_exists('\WPBoilerplate\AccessControl\...') D8 guarded
  McpTrackerTab.php                  class_exists('\WPVMCPT\Plugin') guarded
  UpdateServerTab.php                visible_for = 'database' === registered_from
  DangerZoneTab.php                  visible_for = 'database' === registered_from

admin/Partials/                      PORTED from reference (2 files) + MODIFY (1 file)
  CliAuthLogListTable.php            NEW; cite DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG in docblock
  ConnectorAuditLogListTable.php     NEW
  Settings.php                       MODIFY — delete 4 render_*_tab methods, replace dispatch

public/Renderers/                    NEW — 4 files (public API layer)
  AbstractClientRenderer.php         base + resolve_context + F012-gate helper + shared UI helpers
  NpmClientBlock.php                 F012 gate: acrossai_mcp_npm_login_enabled
  MCPClientsBlock.php                iterates over apply_filters('acrossai_mcp_client_classes', ...)
  ClaudeConnectorBlock.php           F012 gate: acrossai_mcp_claude_connectors_enabled

includes/
  Main.php                           MODIFY — Loader wires 4 new hooks (filter, action, shortcodes, REST)
  REST/ClientRendererController.php  NEW — POST /generate-app-password with locked user_id
                                            + context-bound nonce

tests/phpunit/
  Admin/ServerTabs/RegistryTest.php  NEW — slug uniqueness, ordered iteration, visible_for filter
  Admin/ServerTabs/AbstractServerTabTest.php  NEW — open_form + nonce_field markup
  Public/Renderers/AbstractClientRendererTest.php  NEW — resolve_context + cap check + missing server
  Public/Renderers/PublicApiTest.php  NEW — 12 cases: T024 base 4 (byte-identity + shortcode + action-hook dispatch + client_classes filter silent-skip) + T031 F012 gate ×5 (npm off/on, claude off/on, MCPClients not-gated) + T032 lockdown ×3 (button disabled on user_id mismatch, REST 403 on user_id mismatch, REST 403 on cross-context nonce replay)

docs/
  integrations/buddyboss-example.md   NEW (per Q5)
  integrations/woocommerce-example.md NEW (per Q5)
  planings-tasks/README.md            MODIFY — append F013 row
  memory/DECISIONS.md                 MODIFY — DEC-SERVER-TAB-CLASS-HIERARCHY + DEC-CLIENT-RENDERER-PUBLIC-API
  memory/INDEX.md                     MODIFY — 2 DEC rows + security-review row
  memory/WORKLOG.md                   MODIFY — optional milestone at TASK-9
  planings-tasks/013-per-server-tabs-refactor.md  ALREADY PRESENT (source of truth for /speckit.specify)

README.txt                            MODIFY — Unreleased changelog bullet (per FR-016a)
```

## Task Groups (Phase 2 preview)

Mirrors the 9-task breakdown in `docs/planings-tasks/013-per-server-tabs-refactor.md` verbatim:

| Task | Scope | New files | Gates |
|:---|:---|:---|:---|
| T1 | Scaffold AbstractServerTab + Registry + PHPUnit harness | 4 | php-l + PHPStan L8 + PHPCS + PHPUnit (empty state) |
| T2 | Refactor existing 4 tabs (zero UI change) | 4 | grep: 4 old method names → 0 hits; manual smoke; PHPUnit for 4 slugs |
| T3 | Port `CliAuthLogListTable` + `ConnectorAuditLogListTable` from reference | 2 | Legacy-namespace grep → 0; PHPStan L8; PHPCS |
| T4 | Public Renderer layer + REST endpoint + PHPUnit | 5 + 1 REST + 2 test files | 12 PublicApiTest cases (4 base + 5 F012 gate + 3 lockdown); PHPStan L8; PHPCS |
| T5 | Port 5 new tabs (3 client tabs are thin delegates; 3 admin-only) | 5 + 2 modify | grep: `<pre>` / `<textarea>` / `Configuration JSON` in 3 client tabs → 0 |
| T6 | Port AbilitiesTab with `function_exists('wp_get_abilities')` guard | 1 | Gate present; no fatal when guard trips |
| T7 | Port DB-only UpdateServerTab + DangerZoneTab + visibility rule | 2 | exact-count grep: 11 tab subclasses; RegistryTest visible_for scenarios |
| T8 | DRY sweep + expanded PHPUnit + cross-context byte-identity test | 0 new files (edits) | All 5 grep gates green; byte-identity test passes |
| T9 | Memory hygiene + changelog + docs/integrations/ + security review | 2 integrations docs + memory + README + `docs/security-reviews/2026-07-04-013-*-plan.md` | INDEX contains both DECs; whole-plugin gate |

**Total task count**: 9 (vs F012's 8). **Estimated file impact**: 15 new PHP + 4 new PHPUnit + 2 new markdown + 4 modified PHP + 4 modified markdown + `spec.md`/`plan.md`/`tasks.md`/etc.

## Constitution Re-check (post-Phase-1 design)

Re-evaluated after drafting the class shapes above:

- **§I Modular**: still ✅. Each per-tab class is self-contained; Registry is the only dispatch point; Renderer layer is boundary-controlled by explicit `$context` array.
- **§II WPCS + PHPStan L8**: still ✅ (gate). No changes to baseline configuration; no new legacy patterns introduced.
- **§III Security**: still ✅. Added observation: FR-023's REST `permission_callback` MUST verify BOTH `$body['user_id'] === get_current_user_id()` AND `wp_verify_nonce()` against context-bound action name in the SAME callback — reviewer to verify at TASK-4 code review.
- **§IV DataForm carve-out**: still applies. Reaffirmation at TASK-9 in DEC-CLIENT-RENDERER-PUBLIC-API.
- **§V Extensibility**: still ✅. Two filters + action + shortcodes = full public extensibility surface.
- **§VI DRY**: still ✅ (gate). Cross-context byte-identity PHPUnit at TASK-8 is the mechanically-verified DRY guarantee.
- **§VII DoD**: still ✅. Every TASK has explicit DoD.

**Zero HARD violations detected.** Two SOFT items require capture at TASK-9:
1. §IV DataForm carve-out reaffirmation in DEC-CLIENT-RENDERER-PUBLIC-API.
2. DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG-narrowing citation in `CliAuthLogListTable.php` docblock.

## Complexity Tracking

**Net-new architectural surface** (vs. F012's polish-and-port profile):
- 3 new public API extension points (2 filters + 1 action + 3 shortcodes + 1 REST route).
- Public Renderer layer establishes a NEW plugin subsystem (`public/Renderers/`) that has no prior analog in this plugin.
- Cross-context nonce replay defense (FR-022 + FR-023) is a novel security pattern; no prior feature required this.

**Justification**: F013 is the largest architectural change since Feature 011 (BerlinDB migration). The Renderer layer + REST endpoint + shortcodes together enable the "third-party plugin can embed our config UI" story that unlocks BuddyBoss/WooCommerce integrations. Without this, every future integration would duplicate ~500 LOC of tab body — a permanent DRY tax. The added surface is bounded by 3 concrete Block classes; the extensibility API is `@experimental` until 1.0.0 so signature iteration is unblocked.

**Task count justification**: 9 tasks vs F012's 8. The extra task is TASK-4 (Public Renderer layer + REST + PHPUnit) which is genuinely new architectural work. Splitting T5 into "port 3 client tabs as thin delegates" + "port 3 admin-only tabs" was considered but rejected — both sub-tasks share the same file set and same review context.

**Risk register**:
- **R1** (MEDIUM): Reference-plugin API drift discovered mid-port. **Mitigation**: Q1 clarification pre-authorizes F011-native adaptation; no port halts.
- **R2** (LOW): Third-party plugins register invalid client class FQNs via `acrossai_mcp_client_classes`. **Mitigation**: FR-016b requires silent-skip for invalid FQNs; no fatal.
- **R3** (LOW): Application Password `permission_callback` regression — future feature could relax the `user_id` check. **Mitigation**: PublicApiTest at TASK-4 includes an explicit 403-on-mismatch assertion; regression is CI-caught.
