# Feature Specification: Port per-server-edit tabs to a common per-tab class hierarchy + Public Renderer layer

**Feature Branch**: `013-per-server-tabs-refactor`
**Created**: 2026-07-03
**Status**: Draft
**Input**: User description: See `docs/planings-tasks/013-per-server-tabs-refactor.md` for the full detailed input; the summary is: refactor the per-server-edit page (`?page=acrossai_mcp_manager&action=edit`) so tab rendering lives in a per-tab class hierarchy under `admin/Partials/ServerTabs/` (AbstractServerTab base + Registry dispatch + 11 tab subclasses); port 7 missing tabs from the reference plugin (Overview enriched, npm, MCP Clients, WP-CLI, Tools, Abilities, MCP Tracker) plus 2 database-registered-only tabs (Update Server, Danger Zone); port 2 WP_List_Table subclasses (`CliAuthLogListTable`, `ConnectorAuditLogListTable`); AND extract the render bodies of the three client-configuration tabs (npm, MCP Clients, Claude Connector) into a NEW public Renderer layer under `public/Renderers/` so third-party plugins (BuddyBoss, WooCommerce, other AcrossAI plugins) can embed the same UI on their own admin or frontend surfaces with zero code duplication. F012 settings toggles (`acrossai_mcp_npm_login_enabled`, `acrossai_mcp_claude_connectors_enabled`) MUST gate the corresponding blocks in every context (admin AND external).

## Clarifications

### Session 2026-07-03

- Q: When a reference-plugin tab body calls a class field, Row property, or Query helper method that no longer exists in the target's F011-native support layer, how does the porter resolve? → A: **Adapt to F011 native shape.** Reference-fidelity is best-effort — where F011 dropped, renamed, or reshaped a field/method, the port uses the F011-native shape and the visible UI may differ from the reference in that specific detail. No shim adapters, no fail-fast halt. If a visible regression vs. the reference is operator-blocking, it becomes a follow-up feature, not an F013 scope block.
- Q: How does the MCP Clients tab route between the 7-8 per-client sub-panels? → A: **URL query param `?tab=clients&client=<slug>`**. Default to the first registered client (Claude Desktop) when the `client` param is absent or invalid. Full page reload on switch. Works without JS, browser back/forward preserves state, external plugins can deep-link directly to a client. The Renderer's `$context` array gains a `sub_client` key (`sanitize_key`'d from `$_GET['client']` in the admin tab; from the shortcode/hook context elsewhere).
- Q: What stability guarantee does the F013 public Renderer API surface make to third-party integrators (BuddyBoss, WooCommerce, other plugins consuming the shortcodes, action hook, filter, static method)? → A: **Explicitly experimental until 1.0.0.** The plugin ships F013 at version tag `0.0.6`; the public API is documented as `@since 0.0.6` and marked `@experimental May change without notice before 1.0.0` on every public method, hook, filter, and shortcode. DEC-CLIENT-RENDERER-PUBLIC-API + README changelog + inline docblocks all carry this notice. Third-party integrators are informed the API may change during the 0.x line; the plugin retains iteration flexibility. Once a future feature tags 1.0, the API is promoted to semver-stable (breaking changes require major-version bump + deprecation cycle).
- Q: Can third-party plugins register additional MCP client classes into the MCPClientsBlock sub-nav, or is the 7-client list fixed at F004 forever? → A: **Add a filter `acrossai_mcp_client_classes`.** MCPClientsBlock iterates over `apply_filters('acrossai_mcp_client_classes', [ ... 7 F004 class FQNs ... ])`. Third-party plugins hook the filter to append their own `AbstractMCPClient` subclasses. F013 adds ONE filter, no new class, no F004 rework. The filter is part of the experimental public API (per Q3), so its signature can iterate before 1.0.
- Q: What does F013 include as the third-party integrator's onboarding surface (BuddyBoss, WooCommerce, other AcrossAI plugins consuming the Renderer public API)? → A: **API + documentation examples.** Ship `docs/integrations/buddyboss-example.md` + `docs/integrations/woocommerce-example.md` markdown files with concrete usage examples (shortcode syntax + `do_action` snippets + `apply_filters` samples + BuddyBoss profile hook wiring + WooCommerce My Account tab wiring). No working PHP glue code shipped. Third-party developers copy snippets into their own plugins. Working starter plugins deferred to a future feature if demand justifies.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Site admin sees the full 11-tab per-server-edit UI (Priority: P1) 🎯 MVP

A site administrator navigates to `?page=acrossai_mcp_manager&action=edit&server=1&tab=overview`. On the Default MCP Server (plugin-registered, `registered_from='plugin'`), the tab nav shows 9 tabs in this order: Overview, npm, MCP Clients, Claude Connector, WP-CLI, Tools, Abilities, Access Control, MCP Tracker. On a Developer Server (custom, `registered_from='database'`), the same 9 tabs plus Update Server + Danger Zone are visible. Each tab renders its full content matching the reference plugin's UI (screenshots provided).

**Why this priority**: Operator-facing goal. Without the 11 tabs, the plugin is missing 7 of the 11 per-server capabilities that admins need to configure MCP integrations, which is the entire reason the plugin exists.

**Independent Test**: Activate `acrossai-mcp-manager`; navigate to `/wp-admin/admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=overview`. Assert the tab nav lists exactly 9 tabs in the expected order. Click each tab; assert the tab body renders operator-facing content matching the reference plugin at `wordpress-ai/.../src/Admin/Settings.php` lines 1101–1247, 1258–1351, 1366–1489, 1498–1698, 1762–1880, 1893–1963, 1981–2134, 2157–2168, 2293–2379. Repeat on a database-registered server (server_id=2, if seeded); assert 11 tabs including Update Server + Danger Zone.

**Acceptance Scenarios**:

1. **Given** the plugin is active with a plugin-registered server (id=1, registered_from='plugin'), **When** the admin navigates to `?page=acrossai_mcp_manager&action=edit&server=1`, **Then** the tab nav renders exactly 9 tabs (Overview, npm, MCP Clients, Claude Connector, WP-CLI, Tools, Abilities, Access Control, MCP Tracker) in that order, with Overview selected by default.
2. **Given** the same server, **When** the admin clicks the "MCP Clients" tab, **Then** the tab body renders the 7-client dispatcher (Claude Desktop, Claude Code, VS Code, GitHub Copilot, Codex, Cursor, Custom Client) matching the reference plugin's per-client-config UI.
3. **Given** a database-registered server (id=2, registered_from='database'), **When** the admin navigates to the edit page, **Then** the tab nav renders 11 tabs (the 9 above plus Update Server and Danger Zone in that order).
4. **Given** the plugin-registered server (id=1), **When** the admin tries `?tab=update-server`, **Then** the Registry falls back to OverviewTab (Update Server is not visible for plugin-registered servers).

---

### User Story 2 — Third-party plugin embeds a client block with zero duplication (Priority: P1)

A BuddyBoss or WooCommerce plugin developer wants to show the "npm CLI config" block inside a BuddyBoss member profile tab (`bp_get_profile_url()`) so members can copy their own MCP config JSON. They add ONE `do_action( 'acrossai_mcp_render_client_block', 'npm', $server_id, [ 'context' => 'buddyboss-profile', 'cap' => 'read', 'user_id' => bp_displayed_user_id() ] )` call inside their profile tab template. The block renders identically to the admin per-server-edit page (same "Generate Application Password" button, same Config File path, same Configuration JSON `<pre>` block, same "Copy Configuration" button) with the form action + nonce automatically re-targeted to the BuddyBoss profile URL.

**Why this priority**: This is the reason this feature exists as F013 and not as "just port the tabs into Settings.php". Without cross-context reusability, adding MCP config to BuddyBoss or WooCommerce would mean duplicating ~500 LOC of tab body into every third-party integration — every future integration risks drift, security regressions, and UI inconsistency.

**Independent Test**: Write a minimal WP_UnitTestCase that calls `NpmClientBlock::instance()->render( 1, ['context' => 'admin'] )` and captures the output; then calls it again with `['context' => 'buddyboss-profile', 'cap' => 'read', 'submit_target_url' => 'http://example.com/members/me/']` and captures the second output; assert the two outputs are BYTE-IDENTICAL modulo the form action URL, the nonce field value, and the referring context slug in the nonce action name. Any other diff means duplication has crept in.

**Acceptance Scenarios**:

1. **Given** the plugin is active + a valid server_id=1, **When** any WP-authenticated user (regardless of role) has `read` capability, `do_action( 'acrossai_mcp_render_client_block', 'npm', 1, [ 'context' => 'external', 'cap' => 'read' ] )` is called, **Then** the block renders the full config UI (assuming npm toggle is enabled — see US4).
2. **Given** the same call with `cap` set to `manage_options` (default) and the current user LACKS that cap, **When** the action fires, **Then** the block renders no output (silent no-op — no error, no partial UI).
3. **Given** the shortcode `[acrossai_mcp_npm_block server="1"]` is used in a page or widget by an admin, **When** the page renders, **Then** the block renders inside the page content just as it would in the admin edit tab.

---

### User Story 3 — F012 settings toggles gate the client blocks in every context (Priority: P1)

A site admin visits `?page=acrossai-settings&tab=mcp` and unchecks "Enable CLI Connections". They then navigate to the per-server npm tab. The tab shows a "CLI connections are currently disabled" notice + a link back to Settings, INSTEAD of the config UI. A BuddyBoss member viewing the same npm block on their profile ALSO sees the disabled notice (not the config UI), because the gate is enforced inside the Renderer layer, not the admin tab wrapper. The CLI Connection Log ListTable still renders below the gate (past auth attempts remain visible). Same behavior for the Claude Connector tab when `acrossai_mcp_claude_connectors_enabled` is off.

**Why this priority**: F012 introduced these toggles as first-class safety switches. If F013 lets the client blocks render config UI regardless of the toggle state, it silently overrides F012's operator intent — and worse, does so inconsistently between admin and external contexts. Consistent gating is a security posture requirement, not a polish item.

**Independent Test**: `update_option( 'acrossai_mcp_npm_login_enabled', false )`; call `NpmClientBlock::instance()->render( 1, ['context' => 'admin'] )`; assert output contains "currently disabled" + a link to `?page=acrossai-settings&tab=mcp` and does NOT contain `Configuration JSON` or `Generate New Application Password`. Repeat with `context => 'buddyboss-profile'` and assert identical disabled behavior. Assert the `CliAuthLogListTable` output IS still present in both outputs. Repeat all of the above for `acrossai_mcp_claude_connectors_enabled` + `ClaudeConnectorBlock`. Also assert that `MCPClientsBlock` is NOT affected by either toggle (always renders the 7-client dispatch).

**Acceptance Scenarios**:

1. **Given** `acrossai_mcp_npm_login_enabled` is `false` (default), **When** the admin visits the npm tab on any server, **Then** the tab body shows the "currently disabled" notice + Settings link, does NOT show the "Generate Application Password" button, and DOES show the CLI Connection Log ListTable (with its empty-state or existing rows).
2. **Given** the same option is `true`, **When** the admin visits the npm tab, **Then** the tab body shows the full config UI (Generate button, config file path, JSON block, copy button) AND the ListTable below — no "currently disabled" text.
3. **Given** `acrossai_mcp_claude_connectors_enabled` is `false`, **When** the admin visits the Claude Connector tab, **Then** the tab body shows the "Claude Connector is currently disabled" notice + link, does NOT show the client_id/client_secret/redirect_uri form, and DOES show the ConnectorAuditLogListTable.
4. **Given** either toggle is off, **When** a shortcode `[acrossai_mcp_npm_block server="1"]` renders on a public page, **Then** the disabled notice appears there too (block honors the admin-configured gate uniformly across contexts).

---

### User Story 4 — Application Password generation is locked to the current user (Priority: P1)

A malicious site editor (someone with `edit_users` but not `manage_options`) opens the "MCP Clients" tab in their BuddyBoss profile view of ANOTHER user's page. The "Generate New Application Password" button on the Renderer renders with the `disabled` + `aria-disabled="true"` attributes and a description explaining that Application Passwords can only be generated for the current user. If the editor manually crafts a REST request to `/wp-json/acrossai-mcp-manager/v1/generate-app-password` with a `user_id` other than their own, the endpoint returns HTTP 403.

**Why this priority**: Application Passwords grant persistent WordPress access. Allowing an editor to mint a password for a different user is a direct authentication bypass. The Renderer must NEVER let a `$context['user_id']` differing from `get_current_user_id()` result in a working Generate button or a successful REST call. This is defense-in-depth against admin-impersonation via the BuddyBoss/WooCommerce embed surface.

**Independent Test**: PHPUnit — instantiate `NpmClientBlock::instance()->render( 1, [ 'context' => 'buddyboss-profile', 'user_id' => 999 ] )` while logged in as user_id=42; capture the output; assert the Generate button HTML contains both `disabled` and `aria-disabled="true"` attributes. Then simulate a REST POST to the endpoint with body `{ server_id: 1, client_slug: 'npm', context: 'buddyboss-profile', user_id: 999 }` while logged in as user_id=42; assert the response is HTTP 403.

**Acceptance Scenarios**:

1. **Given** an admin is logged in and calls `NpmClientBlock::render( $sid, [ 'user_id' => get_current_user_id() ] )`, **When** the block renders, **Then** the Generate button is active.
2. **Given** an admin is logged in and calls `NpmClientBlock::render( $sid, [ 'user_id' => (get_current_user_id() + 1) ] )`, **When** the block renders, **Then** the Generate button is rendered with the `disabled` + `aria-disabled="true"` attributes and a `<p class="description">` explaining the constraint.
3. **Given** any user, **When** they POST to `/wp-json/acrossai-mcp-manager/v1/generate-app-password` with a `user_id` field that does not equal `get_current_user_id()`, **Then** the endpoint returns HTTP 403 with no password generated.
4. **Given** any user, **When** they POST with a valid `user_id` = `get_current_user_id()` but a nonce minted for a DIFFERENT context slug, **Then** the endpoint returns HTTP 403 (cross-context nonce replay defense).

---

### User Story 5 — Existing 4-tab UI regresses to zero UI change during the refactor (Priority: P2)

Before the refactor, the admin sees 4 tabs on the per-server-edit page: General, Tokens, Access Control, Claude Connector. After TASK-2 (refactor existing 4 tabs into per-tab classes, before US1's new-tab port), the admin STILL sees the same 4 tabs with pixel-identical output. This validates the AbstractServerTab shape before US1's port introduces new content.

**Why this priority**: Enforces the "shape validation" checkpoint. If the refactor pass silently breaks any of the 4 existing tabs, the port pass (US1) inherits the breakage. Catching it at TASK-2 is far cheaper than at TASK-9.

**Independent Test**: Screenshot each of the 4 tabs before + after TASK-2 → visually diff. Also automated: PHPUnit renders each tab's output via output buffer, captures a hash of the sanitized HTML, and compares against a golden hash. Any diff other than whitespace requires review before proceeding.

**Acceptance Scenarios**:

1. **Given** the plugin is at HEAD-before-TASK-2, **When** an admin views each of the 4 tabs, **Then** they see the current pre-refactor output.
2. **Given** the plugin is at HEAD-after-TASK-2, **When** an admin views each of the 4 tabs, **Then** the visible content is unchanged (modulo cosmetic whitespace).
3. **Given** both HEAD states, **When** an admin saves each tab's form, **Then** the save round-trip succeeds identically (same nonce action name, same option keys, same redirect target).

---

### Edge Cases

- What if the `acrossai-co/main-menu` vendor package is not present (per D15/DEV4 it is a hard-require, but the shortcode + external-embed path may hit sites where the settings page's URL from the disabled-notice link is unreachable)? The link renders as-is; clicking gives a 404 or "insufficient permissions" — the block's rendering does not fatal.
- What if the site admin has BOTH `acrossai_mcp_npm_login_enabled` = false AND a BuddyBoss member is currently viewing their profile? The BuddyBoss profile page shows the disabled notice with the link. Non-admin clicking the link lands on the settings page and gets the "insufficient permissions" WP notice — the block is honest about the reason it's disabled + points at the authoritative place to fix it, even if the current viewer can't.
- What if `MCPServerQuery::instance()->get_item( $server_id )` returns null (deleted server)? The Renderer emits a "server not found" notice and stops. No fatal, no HTML injection via a bogus `$server_id`.
- What if the reference plugin's inline JavaScript is critical for a tab's operator experience (e.g., the "Copy Configuration" button)? The JS is extracted to `src/js/backend.js` and enqueued via `admin/Main.php`'s existing screen-ID guard (F008 pipeline) — the tab body embeds no `<script>` tags.
- What if a third-party plugin passes an unknown `client_slug` to the `do_action( 'acrossai_mcp_render_client_block', ... )` hook? The Registry catches unknown slugs and emits no output (silent no-op).
- What if two different WP sites host the same plugin AND a shared REST endpoint like Application Password generation gets replayed across sites via a shared cookie? The nonce binds site-specific `$server_id` (which is unique per site) + context, so cross-site replay is not possible.

---

## Requirements *(mandatory)*

### Functional Requirements

**Per-tab class hierarchy scaffolding:**

- **FR-001**: The plugin MUST introduce `admin/Partials/ServerTabs/AbstractServerTab.php` — an abstract base class with abstract methods `slug(): string`, `label(): string`, `render_body( array $server ): void`, plus a default-`true`-returning `visible_for( array $server ): bool`, a `final` `render( array $server ): void` (template method), and protected shared helpers `open_form()`, `close_form()`, `nonce_field()`, `json_config_block()`, `passwords_notice()`, `server_edit_url()`, `client_label_pair()`.
- **FR-002**: The plugin MUST introduce `admin/Partials/ServerTabs/Registry.php` — a singleton (`protected static $instance` → `public static function instance(): self` → `private __construct()` matching F012 SettingsMenu member ordering) that provides `all_tabs(): array`, `visible_tabs( array $server ): array`, and `render( string $tab_slug, array $server ): void`.
- **FR-003**: `admin/Partials/Settings.php::render_edit_page()` MUST delegate to `Registry::instance()->render( $tab_slug, $server )` after the wrap + breadcrumb + tab-nav. The 4 existing `render_general_tab`, `render_tokens_tab`, `render_access_control_tab`, `render_claude_connector_tab` methods MUST be deleted from `Settings.php`.

**Per-tab classes (11 total):**

- **FR-004**: The plugin MUST provide 11 concrete tab subclasses under `admin/Partials/ServerTabs/` — `OverviewTab`, `NpmTab`, `ClientsTab`, `ClaudeConnectorTab`, `WpCliTab`, `ToolsTab`, `AbilitiesTab`, `AccessControlTab`, `McpTrackerTab`, `UpdateServerTab`, `DangerZoneTab`. Each MUST extend `AbstractServerTab`.
- **FR-005**: The tab order returned by `Registry::all_tabs()` MUST match the reference plugin's nav order left-to-right: `overview`, `npm`, `clients`, `claude-connector`, `wp-cli`, `tools`, `abilities`, `access-control`, `mcp-tracker`, `update-server`, `danger-zone`.
- **FR-006**: `UpdateServerTab` and `DangerZoneTab` MUST override `visible_for()` to return `'database' === ( $server['registered_from'] ?? '' )`. All other tabs return the default `true`.
- **FR-007**: `NpmTab`, `ClientsTab`, and `ClaudeConnectorTab` MUST be thin delegates — their `render_body()` implementations MUST NOT emit any `<form>`, `<pre>`, `<textarea>`, `wp_nonce_field()`, "Configuration JSON" heading, "Copy Configuration" button, "Generate New Application Password" UI, or config file path string. All such rich HTML lives exclusively in the Public Renderer layer (FR-011..016).

**Ported WP_List_Table classes:**

- **FR-008**: The plugin MUST port `admin/Partials/CliAuthLogListTable.php` from the reference plugin's `src/Admin/CliAuthLogListTable.php`, adopting the PascalCase namespace `AcrossAI_MCP_Manager\Admin\Partials`, replacing legacy DB calls with `\AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query::instance()`. The file docblock MUST cite `DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG` explaining the reintroduction is on-pattern.
- **FR-009**: The plugin MUST port `admin/Partials/ConnectorAuditLogListTable.php` from the reference plugin, adopting the PascalCase namespace and consuming `\AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query::instance()`.
- **FR-010**: Neither ported ListTable class MUST register a standalone admin submenu — both are consumed only from within the per-server tab context via the Renderer layer.

**Public Renderer layer:**

- **FR-011**: The plugin MUST introduce `public/Renderers/AbstractClientRenderer.php` — an abstract base class exposing a `final public function render( int $server_id, array $context = [] ): void` entry point that resolves context defaults, applies the `acrossai_mcp_client_block_context` filter, checks `current_user_can( $context['cap'] )`, loads the server row, and delegates to an abstract `render_body( array $server, array $context ): void`.
- **FR-012**: The plugin MUST introduce 3 concrete Renderer subclasses: `public/Renderers/NpmClientBlock.php`, `MCPClientsBlock.php`, `ClaudeConnectorBlock.php`. Each MUST extend `AbstractClientRenderer` and provide the full rich UI that was previously inside the corresponding admin tab body.
- **FR-013**: The `Renderer::render( int $server_id, array $context = [] )` public API MUST accept these `$context` keys: `context` (slug identifying the caller, default `'admin'`), `cap` (required capability, default `'manage_options'`), `submit_target_url` (form action URL, default `admin_url('admin.php?page=' . AdminPageSlugs::PARENT)`), `nonce_action` (auto-derived if omitted), `user_id` (target user for Application Password, default `get_current_user_id()`), `copy_button` (boolean, default true), `sub_client` (per Clarifications Q2, the slug of the MCP Clients sub-client to render — consumed only by `MCPClientsBlock`; default is the first registered client's slug when absent or invalid).
- **FR-013a**: `ClientsTab::render_body()` MUST read `$_GET['client']` via `sanitize_key()` and pass it as `$context['sub_client']` when delegating to `MCPClientsBlock::render()`. `MCPClientsBlock::render_body()` MUST render the sub-nav (list of sub-client buttons/links pointing at `?tab=clients&client=<slug>` for the admin context, or at `add_query_arg('client', $slug, $context['submit_target_url'])` for external contexts) AND the selected sub-client's body. When `$context['sub_client']` is invalid or absent, MCPClientsBlock falls back to the first registered client.
- **FR-014**: The plugin MUST register 3 shortcodes on the `init` action: `[acrossai_mcp_npm_block server="X"]`, `[acrossai_mcp_clients_block server="X"]`, `[acrossai_mcp_claude_connector_block server="X"]`. Each shortcode's default capability MUST be `manage_options` (filterable via the `acrossai_mcp_client_block_context` filter).
- **FR-015**: The plugin MUST register the `acrossai_mcp_render_client_block` action hook accepting args `( string $renderer_slug, int $server_id, array $context )`. A third-party plugin calling this hook with an unknown `$renderer_slug` MUST result in no output (silent no-op).
- **FR-016**: The plugin MUST register the `acrossai_mcp_client_block_context` filter accepting args `( array $context, string $renderer_slug, int $server_id )`. This filter is the ONLY sanctioned extension point for context defaults; third-party plugins customize context via this filter, not by patching Renderer classes.
- **FR-016a** (per Clarifications Q3): The public API surface (static `Renderer::render()` methods on the 3 Block subclasses, the `acrossai_mcp_render_client_block` action hook, the `acrossai_mcp_client_block_context` filter, the `acrossai_mcp_client_classes` filter, the 3 shortcodes, the REST route `/generate-app-password`) MUST be documented as `@since 0.0.6 @experimental May change without notice before 1.0.0` on every public method + hook + filter + shortcode docblock. `README.txt` Unreleased changelog MUST include the same experimental notice. `DEC-CLIENT-RENDERER-PUBLIC-API` MUST include the "experimental until 1.0" clause. Third-party integrators are informed the API may change during the 0.x line; promotion to semver-stable is deferred to a future 1.0 tag.
- **FR-016b** (per Clarifications Q4): The plugin MUST register a filter `acrossai_mcp_client_classes` accepting args `( array $client_class_fqns )` where the default value is the ordered array of the 7 F004 concrete `AbstractMCPClient` subclass FQNs (`ClaudeDesktopClient`, `ClaudeCodeClient`, `VSCodeClient`, `GitHubCopilotClient`, `CodexClient`, `CursorClient`, `CustomClient`). `MCPClientsBlock::render_body()` MUST iterate over `apply_filters('acrossai_mcp_client_classes', [ ... default 7 ... ])` and dispatch to each client's per-client render helper. Third-party plugins wanting to add a custom MCP client hook this filter to append their own `AbstractMCPClient` subclass FQN. Invalid FQNs (class does not exist or does not extend `AbstractMCPClient`) MUST be silently skipped — no fatal, no admin notice — to preserve robustness under third-party misuse.
- **FR-016c** (per Clarifications Q5): The plugin MUST ship two documentation files: `docs/integrations/buddyboss-example.md` and `docs/integrations/woocommerce-example.md`. Each file MUST contain: (a) a minimal working code snippet that renders the three client-configuration blocks (npm, MCP Clients, Claude Connector) on the target platform's canonical user-facing surface (BuddyBoss member profile tab, WooCommerce My Account custom tab), (b) an example of using `apply_filters('acrossai_mcp_client_block_context', ...)` to customize `cap`, `user_id`, and `submit_target_url` for that platform, (c) a security note that the "Generate Application Password" button will remain disabled unless `$context['user_id'] === get_current_user_id()`, (d) an example of extending the MCP Clients sub-nav with a platform-specific client via `add_filter('acrossai_mcp_client_classes', ...)`. No working PHP glue code is shipped as a plugin — the docs are copy-paste starter material.

**F012 settings-toggle gating:**

- **FR-017**: `NpmClientBlock::render_body()` MUST check `get_option( 'acrossai_mcp_npm_login_enabled', false )` first. When false, it MUST render the "CLI connections are currently disabled" notice + link to `?page=acrossai-settings&tab=mcp` INSTEAD of the config UI. When true, it renders the full config UI (Generate button, config file row, JSON block, copy button). In BOTH cases, the `CliAuthLogListTable` MUST render below.
- **FR-018**: `ClaudeConnectorBlock::render_body()` MUST check `get_option( 'acrossai_mcp_claude_connectors_enabled', false )` first. When false, it MUST render the "Claude Connector is currently disabled" notice + link INSTEAD of the client_id/client_secret/redirect_uri form. When true, it renders the full form. In BOTH cases, the `ConnectorAuditLogListTable` MUST render below.
- **FR-019**: `MCPClientsBlock::render_body()` MUST NOT be gated by any F012 toggle. It always renders the 7-client dispatch.
- **FR-020**: The gate checks in FR-017 and FR-018 MUST live inside the Renderer's `render_body()`, NOT inside the admin tab wrapper — so that shortcodes, action-hook dispatches, and third-party embeds all honor the admin-configured gate uniformly.

**Security:**

- **FR-021**: The Renderer MUST NEVER hardcode `'manage_options'` in `render()`. The capability check MUST use the resolved `$context['cap']` value (defaulting to `'manage_options'` but overridable via context).
- **FR-022**: The Renderer MUST derive its default nonce action as `'acrossai_mcp_render_' . $this->slug() . '_' . $server_id . '_' . $context['context']` — binding both `$server_id` AND the context slug. A nonce minted for `context='admin'` MUST NOT validate against a POST with `context='buddyboss-profile'`.
- **FR-023**: The plugin MUST introduce `includes/REST/ClientRendererController.php` with a POST route `/wp-json/acrossai-mcp-manager/v1/generate-app-password` whose `permission_callback` returns HTTP 403 if ANY of: (a) the user is not logged in, (b) the requested `user_id` differs from `get_current_user_id()`, (c) the nonce provided via X-WP-Nonce does not match the exact context-bound action name from FR-022.
- **FR-024**: When rendered in a context where `$context['user_id']` differs from `get_current_user_id()`, the Renderer's "Generate New Application Password" button MUST render with both the HTML `disabled` and `aria-disabled="true"` attributes, immediately followed by a `<p class="description">` accessible explanation. It MUST NEVER render as an enabled button that would allow generating a password for a different user. The "absent" alternative previously permitted by this FR is disallowed — the button MUST render disabled so cross-context byte-identity assertions (SC-002) remain deterministic modulo a single boolean attribute delta.

**Regression + hygiene:**

- **FR-025**: The plugin MUST NOT introduce any file that uses the legacy uppercase namespace `ACROSSAI_MCP_MANAGER\`. Grep gate: `grep -rn 'ACROSSAI_MCP_MANAGER\\\\' --include='*.php' admin/ includes/ public/ acrossai-mcp-manager.php` MUST return zero hits both before and after every task.
- **FR-026**: No admin tab subclass under `admin/Partials/ServerTabs/*.php` may contain a raw `<form method="post">` string or a raw `wp_nonce_field(` call. All form-open and nonce operations route through `AbstractServerTab::open_form()` and `AbstractServerTab::nonce_field()`. Grep gates enforce this after TASK-8.
- **FR-027**: The reference plugin's inline JavaScript MUST NOT be ported into tab bodies. Any tab needing JS extends `src/js/backend.js` and is enqueued via `admin/Main.php`'s existing screen-ID guard.
- **FR-028**: `SettingsRenderer::render_tab_nav()` and `ApplicationPasswords::render_for_server()` MUST NOT be touched. The Registry supplies the tab list to the nav helper. (Note: F013's initial US5 refactor created a `TokensTab` thin delegate to the password renderer, but the reference plugin's 11-tab UI has no distinct Tokens tab — App Passwords render inside Overview via `render_passwords_notice()`. The orphan file was removed per architecture-review R2.)

### WordPress Requirements

**PHP Version**: PHP 8.1+ (matches plugin's Feature 010 baseline).
**WordPress Version**: 6.9+.
**Multisite**: Single-site only (matches prior features).
**Required Plugins / Packages**: `acrossai-co/main-menu` (D15/DEV4 hard-require, already in place). No new required plugins.
**Optional Integrations**: `wpb-access-control` (existing D8 pattern preserved by AccessControlTab); `acrossai-abilities-manager` (existing pattern preserved by AbilitiesTab); external plugins consuming the Renderer layer (BuddyBoss, WooCommerce) are third-party — the plugin degrades gracefully if these are absent.

### Module Placement

**PHP Classes**:
- `admin/Partials/ServerTabs/{AbstractServerTab,Registry}.php` → namespace `AcrossAI_MCP_Manager\Admin\Partials\ServerTabs` — admin-side dispatch + base class.
- `admin/Partials/ServerTabs/{OverviewTab,...,DangerZoneTab}.php` → same namespace — 11 concrete tab subclasses.
- `admin/Partials/{CliAuthLogListTable,ConnectorAuditLogListTable}.php` → namespace `AcrossAI_MCP_Manager\Admin\Partials` — ported WP_List_Table classes.
- `public/Renderers/{AbstractClientRenderer,NpmClientBlock,MCPClientsBlock,ClaudeConnectorBlock}.php` → namespace `AcrossAI_MCP_Manager\Public\Renderers` — the public Renderer layer (external plugins consume via this namespace).
- `includes/REST/ClientRendererController.php` → namespace `AcrossAI_MCP_Manager\Includes\REST` — REST endpoint for Application Password generation.

**Hook Registration**: `add_action`/`add_filter` calls MUST live in `includes/Main.php::define_public_hooks()` — shortcode registration, the `acrossai_mcp_render_client_block` action wiring, and the REST route registration all wired via the Loader per A1.

### Admin UI Requirements

**Pre-approved WP_List_Table exception** (this feature's scope):
- `admin/Partials/CliAuthLogListTable.php` and `admin/Partials/ConnectorAuditLogListTable.php` extend `\WP_List_Table` — this is a per-server-tab-inspection use of the pre-existing DEV1 carve-out, further narrowed by `DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG` to per-server tab inspectors only (no standalone admin submenus).
- No new DataForm/DataViews screens are introduced — the per-server-edit UI is a pre-existing surface not eligible for DataForm (the vendor shared Settings page is the DEV carve-out for Settings API surfaces per DEC-VENDOR-SETTINGS-TAB-INTEGRATION).

### REST API Contract

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `POST` | `/wp-json/acrossai-mcp-manager/v1/generate-app-password` | Custom `permission_callback` — logged-in + `user_id === get_current_user_id()` + context-bound nonce match | Generates a WordPress Application Password for the CURRENT user only. Never generates for a different `user_id` even if requested. Returns HTTP 403 on any mismatch. |

**`permission_callback` rule**: This route is a mutating write (creates an Application Password). The callback MUST explicitly verify `is_user_logged_in()` AND `wp_verify_nonce()` with the context-bound action name AND `absint( $body['user_id'] ) === get_current_user_id()`.

### Database / Storage

**Existing WordPress options consumed** (F012 toggles — read-only in F013):
- `acrossai_mcp_npm_login_enabled` (bool, default false) — consumed by `NpmClientBlock::render_body()` gate.
- `acrossai_mcp_claude_connectors_enabled` (bool, default false) — consumed by `ClaudeConnectorBlock::render_body()` gate.

**Existing custom DB tables consumed** (read-only in F013):
- `wp_acrossai_mcp_servers` via `MCPServerQuery::instance()->get_item()`.
- `wp_acrossai_mcp_cli_auth_logs` via `CliAuthLogQuery::instance()->query()` from within `CliAuthLogListTable`.
- `wp_acrossai_mcp_oauth_audit` via `OAuthAuditQuery::instance()->query()` from within `ConnectorAuditLogListTable`.

**No new persistent storage.** F013 introduces no options, no meta, no tables, no transients.

### Security Checklist

- [ ] All form/AJAX handlers verify nonce via `wp_verify_nonce()` (routed through `AbstractServerTab::nonce_field()` and `AbstractClientRenderer` context-bound nonces).
- [ ] All admin page renders check capability via `AbstractClientRenderer::render()` calling `current_user_can( $context['cap'] )`. Admin tabs pass `'manage_options'`.
- [ ] REST route `/generate-app-password` has explicit `permission_callback` — not `__return_true`.
- [ ] All user input (server_id, client_slug, context, user_id) sanitized at REST boundary with `absint()` and `sanitize_key()`.
- [ ] All output escaped at point of rendering — `esc_url()` for URLs (SEC-012-008), `esc_html()` for text, `esc_attr()` for attributes, `wp_kses_post()` for translated HTML.
- [ ] No new DB queries introduced by F013 — the ported ListTables reuse F011's BerlinDB-backed Query classes via prepared statements.
- [ ] Application Passwords are stored by WordPress core hashed — F013 does not touch storage; it only invokes `WP_Application_Passwords::create_new_application_password()`.
- [ ] No file uploads.

### Key Entities

- **Tab**: A single per-server-edit surface identified by its `slug` (e.g., `'npm'`, `'clients'`). Owns a label, a visibility rule, and a render body. There are 11 tabs total.
- **Server**: An MCP server row from `wp_acrossai_mcp_servers`, identified by `id`. Has `registered_from` = `'plugin'` (built-in) or `'database'` (custom). The Update Server + Danger Zone tabs are visible only for `'database'` servers.
- **Renderer**: A public/Renderers/ class that owns the full HTML for a client-configuration block (npm, MCP Clients, Claude Connector). Called by both the admin tab AND third-party plugins via a shared public API. The zero-duplication invariant means the Renderer is the SOLE source of the block's HTML.
- **Context**: An array passed to the Renderer's `render()` method describing WHO is embedding the block, WHERE the form should POST to, WHICH capability gates access, and WHICH user is the target for Application Password generation.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings on all F013-touched surfaces (`vendor/bin/phpcs admin/Partials/ServerTabs/ public/Renderers/ includes/REST/ClientRendererController.php`)
- [ ] PHPStan level 8: zero errors plugin-wide (`vendor/bin/phpstan analyse --level=8 --no-progress`)
- [ ] PHPUnit tests written and passing — RegistryTest (slug uniqueness + ordered iteration + visible_for filter), AbstractServerTabTest (open_form + nonce_field markup), PublicApiTest (12 cases covering context resolution + cap check + F012 gate ×5 + user_id lockdown + cross-context nonce replay)
- [ ] Security checklist above: all applicable items verified
- [ ] All hooks wired in `Main.php` — no `add_action`/`add_filter` in class constructors or class bodies
- [ ] No code duplication — the 3 client tab subclasses (NpmTab, ClientsTab, ClaudeConnectorTab) are thin delegates verified by grep gate: `grep -cE '<pre>|<textarea>|Configuration JSON' admin/Partials/ServerTabs/{NpmTab,ClientsTab,ClaudeConnectorTab}.php` returns 0
- [ ] All F013 files use PascalCase namespace `AcrossAI_MCP_Manager\`; legacy uppercase namespace grep returns 0
- [ ] `npm run validate-packages` passes (no new npm dependencies added)

### Measurable Outcomes

- **SC-001**: A site admin can navigate through 11 tabs on a database-registered server and 9 tabs on a plugin-registered server in under 30 seconds, with every tab rendering visually-matching content vs. the reference plugin's screenshots.
- **SC-002**: The 3 client-configuration blocks (NpmClientBlock, MCPClientsBlock, ClaudeConnectorBlock) each render byte-identical CORE markup when invoked from the admin context vs. from an external `buddyboss-profile` context, modulo the form action URL and nonce field. Verified by an automated PHPUnit test that diff-compares the two outputs.
- **SC-003**: When `acrossai_mcp_npm_login_enabled` is false, the NpmClientBlock renders the disabled notice in EVERY context (admin tab, shortcode, `do_action` hook, direct static call), and NEVER renders the "Generate Application Password" button. Same invariant holds for the Claude Connector toggle.
- **SC-004**: A third-party plugin developer can embed a client-configuration block by adding exactly ONE line — either `[acrossai_mcp_npm_block server="X"]` in a page/widget, or `do_action( 'acrossai_mcp_render_client_block', 'npm', $server_id, [ ... ] )` in a template — WITHOUT copying, modifying, or forking any HTML from the plugin.
- **SC-005**: Attempting to generate an Application Password for a `user_id` different from `get_current_user_id()` via the REST endpoint returns HTTP 403 with an empty response body. Nonce replayed across contexts returns HTTP 403.
- **SC-006**: The regression grep-gate `grep -rn 'ACROSSAI_MCP_MANAGER\\\\' --include='*.php' admin/ includes/ public/ acrossai-mcp-manager.php` returns 0 hits both before AND after every task in F013 (proves the legacy namespace never leaks in during the reference-plugin port).
- **SC-007**: The 11 required grep gates (regression + duplication + F012 gate + exact-count) from the planning doc's `Public API artifacts + regression grep-gates` block all return the expected values after TASK-9, verified in the post-merge-verification.txt file.

---

## Assumptions

- The reference plugin at `/Users/raftaar1191/local-sites/wordpress-ai/app/public/wp-content/plugins/acrossai-mcp-manager/src/Admin/Settings.php` is authoritative for the operator-facing UI of the 11 tabs — as a best-effort target. Per Clarifications Q1 (2026-07-03), where the reference plugin's tab body calls a class field, Row property, or Query helper method that F011 dropped/renamed/reshaped, the port adapts to F011 native shape. The port MAY diverge from the reference UI in those specific details; the port MUST NOT introduce shim adapters to preserve reference-perfect UI. Any operator-visible regression vs. the reference from this rule is a follow-up scope item, not an F013 blocker.
- All support layers (DB Query classes, MCPClients, MCP Controller, OAuth flow, FrontendAuth, ApplicationPasswords, ClaudeConnectors, SettingsMenu) are already in place and correctly implemented — F013 only consumes them, never re-implements.
- The `acrossai-co/main-menu` vendor package is present (D15/DEV4 hard-require).
- The AbstractServerTab template-method pattern is the correct level of abstraction — no additional traits or intermediate abstract classes are needed for the 11 tabs.
- The Renderer layer's `$context` array is sufficient as the extension point for third-party plugins — no additional class hierarchy or plugin-API surface is needed.
- The F012 settings toggles are the ONLY global gates that affect client-block rendering. No additional toggles are anticipated.
- Multisite support is out of scope. Single-site only.
- The reference plugin's inline JavaScript for the "Copy Configuration" button and any live-config-update flows can be re-expressed inside `src/js/backend.js` without behavior loss.
- Manual smoke tests on live WP will be performed by the human reviewer before merge; automated PHPUnit + PHPStan + PHPCS gates run in CI.
