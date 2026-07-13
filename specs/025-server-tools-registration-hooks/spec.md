# Feature Specification: Wire the per-server tool selection into MCP registration, and let operators remove built-in defaults

**Feature Branch**: `025-server-tools-registration-hooks`
**Created**: 2026-07-13
**Status**: Draft
**Input**: User description: "Give each MCP server a hybrid tool storage: three tinyint(1) columns on wp_acrossai_mcp_servers for the protocol tools (each DEFAULT 1) plus the existing wp_acrossai_mcp_server_tools rows for curated abilities. Wire the composed set into `\WP\MCP\Core\McpAdapter::create_server()`'s 10th argument for both server-registration paths; let operators remove/restore the three built-in defaults from the Tools tab with a warning confirmation and a Reset button; expose a companion-plugin filter for the database-server path and hook the vendor filter for the default-server path."

## Clarifications

### Session 2026-07-13

- Q: Should protocol-tool enablement changes emit an event, and if so, which one? → A: Reuse the existing `acrossai_mcp_tools_changed` action — fire one bullet per column flipped (`added` / `removed` op, slug taken from the built-in `COLUMN_MAP`). Single unified event stream; existing F020 subscribers pick up protocol changes automatically.
- Q: When the "Added as tools" pane becomes empty (operator removed everything, no curated picks), what should the UI render? → A: Warning banner inside the empty pane with copy "This server has no tools. AI clients can't discover or execute abilities. Click Reset to restore defaults." plus an inline Reset CTA. Trusts the operator (removal is allowed) but surfaces the risk and keeps recovery one click away.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Operator's saved tool selection becomes what AI clients actually see (Priority: P1)

Today the Tools tab (Feature 020) lets an operator pick which WordPress abilities are exposed as "tools" for a given MCP server, but the picks are only enforced at call-time by the ability gate — they never make it into the tool list advertised by the server. AI clients calling `tools/list` see only the three built-in MCP protocol tools, never the operator's curated abilities. This story closes that loop: the operator's saved selection IS the tool list the server advertises.

**Why this priority**: Without this, the Tools tab is cosmetic. Operators believe they've configured a server but their picks are invisible to any AI client. This is the whole reason the tab exists — everything else in this feature builds on it.

**Independent Test**: Enable an MCP server, open its Tools tab, pick two abilities, Save. Call `tools/list` on the server's endpoint. The response includes the two picked abilities alongside the built-in defaults.

**Acceptance Scenarios**:

1. **Given** an enabled database MCP server with two curated abilities saved, **When** an AI client sends a `tools/list` JSON-RPC request to the server's endpoint, **Then** the response's `tools` array contains the two curated ability names plus the three built-in default tools.
2. **Given** an enabled default MCP server with three curated abilities saved, **When** an AI client sends a `tools/list` request, **Then** the response contains the three curated abilities plus the three built-in defaults.
3. **Given** a database server with no curated picks yet, **When** an AI client sends `tools/list`, **Then** the response contains only the three built-in defaults (no regression from today's behavior).

---

### User Story 2 - Operator can remove built-in default tools with an explicit warning (Priority: P2)

Today the three built-in default tools appear in the Tools tab as read-only "always available" ornaments with a lock icon — operators cannot remove them even for legitimate reasons (e.g., a security-hardened server that only executes a fixed ability set and doesn't need discovery). This story makes those three tools removable, but only after a confirmation dialog surfaces the risk that AI clients depend on them for discovery and execution.

**Why this priority**: This is the visible "new capability" of the feature. It requires User Story 1's infrastructure to actually take effect (otherwise the UI would let operators toggle a bit that the backend ignores).

**Independent Test**: Open the Tools tab for any server. Click Remove on "Discover Abilities". A confirmation dialog appears with a warning. Click "Remove anyway". The row disappears from the "Added as tools" pane and reappears in the left "All abilities" list. On the next `tools/list` call, `mcp-adapter/discover-abilities` is absent.

**Acceptance Scenarios**:

1. **Given** the Tools tab is open, **When** the operator clicks Remove on any of the three built-in default tools, **Then** a confirmation dialog appears with the message "This tool is required by AI clients to discover and execute WordPress abilities on this server. Removing it may prevent connected AI clients from working correctly."
2. **Given** the confirmation dialog is open, **When** the operator clicks Cancel, **Then** the dialog closes and the tool remains in the "Added as tools" pane.
3. **Given** the confirmation dialog is open, **When** the operator clicks "Remove anyway", **Then** the tool disappears from the "Added as tools" pane, reappears in the left "All abilities" list (sourced from the GET endpoint's `abilities` catalog, which always includes the three protocol slugs via `ToolPolicy::PROTOCOL_TOOL_METADATA` fallback — see FR-018) with the recommended-defaults visual treatment, and the next `tools/list` request no longer advertises it.
4. **Given** a non-built-in ability is currently added as a tool, **When** the operator clicks Remove, **Then** the tool is removed immediately without a confirmation dialog (only built-in defaults trigger the warning).

---

### User Story 3 - Reset button restores the recommended defaults (Priority: P2)

An operator who has removed one or more built-in defaults or accumulated many curated picks needs a one-click path back to the "recommended factory defaults" configuration. This story defines the Reset button's semantics: restore all three built-in defaults AND clear all curated picks, with a confirmation dialog before the destructive action.

**Why this priority**: Ties with P2. Together with User Story 2, it defines the full Remove/Restore lifecycle for built-in defaults.

**Independent Test**: Open the Tools tab. Remove one built-in default and add three curated abilities. Click Reset. Confirm the dialog. Verify that all three built-in defaults are back and the three curated abilities are gone.

**Acceptance Scenarios**:

1. **Given** an operator has removed at least one built-in default OR added at least one curated ability, **When** the operator clicks Reset, **Then** a confirmation dialog appears with the message "Reset the tools for this server to only the three built-in defaults? All curated picks will be removed."
2. **Given** the reset confirmation dialog is open, **When** the operator clicks Cancel, **Then** no state changes.
3. **Given** the reset confirmation dialog is open, **When** the operator clicks "Reset to defaults", **Then** all three built-in defaults are added, all curated picks are removed, and the "Added as tools" pane shows exactly three entries.

---

### User Story 4 - Companion plugins can add or remove tools via a documented filter (Priority: P3)

Third-party companion plugins need a programmatic seam to modify each server's tool list — for example, to auto-add a "Notes" ability to every server whose name matches a pattern, or to strip execution capabilities from an audit-only server. This story defines that seam.

**Why this priority**: Extension surface. Not required for the operator UX in stories 1–3 to work, but essential for third-party integrations to compose cleanly with this plugin.

**Independent Test**: In a scratch companion plugin, register a filter callback that appends one slug to the tools array for one specific server. Reload the target server's `tools/list` endpoint; the added slug is present. Register a second callback that removes the "execute" built-in default. Reload; the execute default is gone.

**Acceptance Scenarios**:

1. **Given** a companion plugin registers a callback on the new database-server filter that adds one slug, **When** an AI client requests `tools/list` on any database server, **Then** the added slug is present in the response.
2. **Given** a companion plugin registers a callback that removes one built-in default slug, **When** an AI client requests `tools/list`, **Then** the removed slug is absent even though the operator has not touched the Tools tab.
3. **Given** a companion plugin targets the default server, **When** it hooks the existing MCP-adapter filter used by the default server, **Then** its modifications appear in the default server's `tools/list`.
4. **Given** a companion callback returns a non-array value or a null, **When** the server registration runs, **Then** the system defensively normalizes the return without a fatal error, and the server registers with an empty or coerced tool list.

---

### Edge Cases

- **What happens on the first request after upgrade for a server that existed before this feature?** The three built-in defaults MUST remain enabled (backwards-compatible). The operator hasn't opened the Tools tab, so nothing new is stored; the schema change alone must preserve the pre-feature behavior.
- **What if the operator removes all three built-in defaults AND has no curated picks?** *(behavior differs by server source — SEC-025-v2-1 clarification 2026-07-14)*:
  - **For DATABASE servers** (`registered_from = 'database'`): the server registers with an empty tool list, and `tools/list` returns an empty array. This is a legal (if unusual) configuration.
  - **For the DEFAULT server** (`registered_from = 'plugin'`, slug `mcp-adapter-default-server`): the vendor's default tools (the three protocol slugs) win as a safer fallback — `Controller::filter_default_server_config()` short-circuits when the composed set is empty, and `\WP\MCP\Servers\DefaultServerFactory::create()` then merges the vendor's default `tools` back in via `wp_parse_args()`. Operators requiring a truly empty tool list on the default server MUST hook `mcp_adapter_default_server_config` at priority `>10` and explicitly set `$config['tools'] = []`.
  - In BOTH cases, the Tools tab renders a warning banner inside the empty "Added as tools" pane ("This server has no tools. AI clients can't discover or execute abilities. Click Reset to restore defaults.") with an inline Reset CTA — see FR-017.
- **What if the vendor's default-server row cannot be located when the vendor filter fires?** The plugin's filter callback returns the vendor's config untouched — vendor defaults win, no error surfaces.
- **What if a companion filter throws a `\Throwable`?** The exception propagates (standard WordPress filter behavior). Companion authors are responsible for their own throw safety; documentation calls this out.
- **What if two operators save the Tools tab simultaneously for the same server?** The two POST-side writes — the server-row column `UPDATE` and the curated `MCPServerToolQuery::replace_set()` — are **not** wrapped in a single outer transaction. Two concurrent saves may leave columns from writer A and curated rows from writer B for a milliseconds-wide window. This is a **documented accepted race** (SEC-025-INFO-2 / `data-model.md` §"Two-write POST path — accepted race") — the Tools tab is single-operator in practice; the operator sees any divergence on the next page load and can Reset to a known-good state. Not remediated in F025; future features may wrap both writes in an explicit `START TRANSACTION` at the controller layer.
- **What if the MCP adapter package (`\WP\MCP\Plugin`) is absent?** No change from today's behavior — the Controller reports `not-found` and no server is registered.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST include the operator's saved per-server tool selection (both built-in defaults and curated abilities) in the tool list each MCP server advertises via `tools/list`.
- **FR-002**: The Tools tab MUST let the operator remove any of the three built-in default tools (Discover Abilities, Get Ability Info, Execute Ability).
- **FR-003**: A confirmation dialog MUST appear before a built-in default tool is removed, warning that "This tool is required by AI clients to discover and execute WordPress abilities on this server. Removing it may prevent connected AI clients from working correctly."
- **FR-004**: The system MUST persist per-server built-in default enablement across requests, per MCP server row.
- **FR-005**: The system MUST render built-in default tools with a distinct visual treatment (recommended-defaults color) in both the "Added as tools" pane and the "All abilities" pool.
- **FR-006**: Removing a non-built-in tool MUST NOT show a confirmation dialog; only built-in defaults trigger the warning.
- **FR-007**: The Reset button MUST restore all three built-in defaults AND clear every curated pick for the current server, gated by its own confirmation dialog.
- **FR-008**: The system MUST expose a plugin-owned filter that fires once per database-server registration, receiving the composed tool list and the server's identifying data as arguments. Callbacks MUST be able to add or remove any slug freely.
- **FR-009**: The system MUST hook the existing MCP-adapter vendor filter for the default server. The plugin MUST NOT emit its own filter for the default-server path (single extension seam per path).
- **FR-010**: Any third-party filter return that is not an array of strings MUST be defensively normalized — non-array wrapped, non-string cast, duplicates collapsed — before being passed to the adapter.
- **FR-011**: Upgrading an existing install MUST preserve every existing server's pre-upgrade tool advertisement (all three built-in defaults remain enabled by default for every existing server row).
- **FR-012**: The Tools tab REST endpoint (POST) MUST accept a unified tool array containing any mix of built-in default slugs and curated ability slugs.
- **FR-013**: The Tools tab REST endpoint (GET) MUST return a unified tool array composed of both the built-in default state and the curated picks.
- **FR-014**: The system MUST document the new companion-plugin filter (contract, signature, when it fires) in the plugin's extension-author documentation.
- **FR-015**: The three built-in default tool slugs MUST live in a single canonical source location on the PHP side (no inline duplicate literals across the codebase).
- **FR-016**: The system MUST fire the existing `acrossai_mcp_tools_changed` action once per built-in default column that flipped state on a POST save — payload `{ server_id, ability_slug, operation }` with `operation` `'added'` (column flipped `0` → `1`) or `'removed'` (column flipped `1` → `0`) and `ability_slug` taken from the built-in `COLUMN_MAP`. Existing F020 subscribers require no code change to observe protocol-tool changes.
- **FR-017**: When the "Added as tools" pane holds zero entries for a server (all built-in defaults removed AND no curated picks), the Tools tab MUST render a warning banner inside that pane reading "This server has no tools. AI clients can't discover or execute abilities. Click Reset to restore defaults." with an inline Reset CTA that opens the same confirmation dialog as the pane's Reset button. Server registration is NOT blocked by this state — the banner is informational, not preventive.
- **FR-018**: Protocol slugs (`ToolPolicy::PROTOCOL_TOOLS`) are canonical plugin constants whose validity is guaranteed by the plugin's own source, not by the runtime abilities catalog. The system MUST:
  - **On POST** — bypass `wp_get_abilities()` catalog validation for any submitted slug that appears in `ToolPolicy::PROTOCOL_TOOLS`. Non-protocol slugs continue to be catalog-validated as before.
  - **On GET (`include_abilities=1`)** — always include the three protocol slugs in the `abilities` response array, using `ToolPolicy::PROTOCOL_TOOL_METADATA` as a runtime-timing-safe fallback merged with any entries `wp_get_abilities()` returns (dedup: `wp_get_abilities()` wins when both sources hold the same slug).
  - Rationale: the vendored MCP-adapter registers the three protocol abilities via `wp_register_ability` on the `wp_abilities_api_init` action, but its listener attaches inside `Controller::initialize_adapter()` on `rest_api_init` — which fires AFTER `wp_abilities_api_init` on REST requests whose Abilities-API bootstrap already ran on `init`. Runtime evidence 2026-07-14 confirmed `wp_get_abilities()` is blind to the three protocol slugs at REST-handler time, so the plugin's own canonical source (`ToolPolicy`) is authoritative for both validation and catalog visibility.

### WordPress Requirements

**PHP Version**: PHP 8.0+ (plugin minimum PHP 7.4; feature does not rely on 8.1+ syntax).
**WordPress Version**: 6.9+
**Multisite**: Single-site only (matches plugin baseline; not extended by this feature).
**Required Plugins / Packages**: `wordpress/mcp-adapter` (already vendored).
**Optional Integrations**: `wpboilerplate/wpb-access-control` (unchanged from Feature 015 baseline — orthogonal to this feature).

### Module Placement

**PHP Class(es)**:
- `includes/Database/MCPServer/Schema.php`, `Table.php`, `Row.php` — extend the existing MCPServer schema/row with the three enablement flags for built-in defaults.
- `includes/Database/MCPServer/ToolPolicy.php` (new) — stateless helper that composes the effective tool list for a server row (union of enabled built-in defaults + curated picks) and splits a REST payload into the two storage layers.
- `includes/MCP/Controller.php` — reads the composed tool list per server and fires the plugin filter for database-server registrations; adds a public method that hooks the vendor filter for the default-server path.
- `includes/REST/ToolsController.php` — GET composes the response from both storage layers; POST splits the payload into built-in-flag updates and curated `replace_set` calls.

**JavaScript Module(s)**:
- `src/js/tools.js` — Tools tab UI; adds the remove-with-warning dialog for built-in defaults, adds the Reset confirmation dialog, and stops filtering built-in default slugs out of the left-pane ability pool.

**Hook Registration**: The one new filter subscription for `mcp_adapter_default_server_config` MUST be wired in `includes/Main.php::define_admin_hooks()` via the `Loader` — no `add_filter` calls in class constructors.

### Admin UI Requirements

**Existing screen (Tools tab under Edit MCP Server):**

- The tab already exists (Feature 020) and its layout is unchanged. Feature 025 modifies rendering rules only:
  - Every entry in the "Added as tools" pane renders with a `Remove` button, including the three built-in defaults (removes the lock-icon read-only branch from F020).
  - Built-in default tools retain their recommended-defaults color treatment (currently `#fef7e0` background / `#8a6d00` foreground).
  - The three built-in default slugs are no longer filtered out of the "All abilities" left-pane pool; if any are removed, they appear there with the recommended-defaults color and a `+ Add` button.
  - Confirmation dialogs are implemented with `@wordpress/components` `ConfirmDialog` (not native `confirm()`), matching the plugin's WPDS convention and i18n contract.

### REST API Contract

Route shapes are preserved. Only semantic changes:

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `GET` | `/wp-json/acrossai-mcp-manager/v1/servers/{id}/tools` | `manage_options` | Returns a `tools` array composed of the server row's enabled built-in defaults plus its curated picks. When `include_abilities=1`, the ability catalog now includes the three built-in default slugs as regular entries (previously silently filtered). |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/servers/{id}/tools` | `manage_options` | Accepts a unified `tools` array. The controller routes built-in default slugs to the server-row enablement flags and hands remaining slugs to the existing curated `replace_set` path. |

**`permission_callback` rule**: Both endpoints already check `manage_options`. No change.

### Database / Storage

**Custom DB table** (extending an existing table):

- Table: `{wpdb->prefix}acrossai_mcp_servers` (existing since Feature 011).
- Change: three new columns, each `tinyint(1) NOT NULL DEFAULT 1`:
  - `tool_discover_abilities`
  - `tool_get_ability_info`
  - `tool_execute_ability`
- Justification: the three built-in default slugs are a fixed, known set with a structural difference from the open-ended curated set (`wp_acrossai_mcp_server_tools` presence rows). Storing them as columns preserves the "always enabled by default" semantic via `DEFAULT 1`.
- Migration mechanism: the `ALTER TABLE ... ADD COLUMN ... DEFAULT 1` executed by BerlinDB's `maybe_upgrade()` on the next request after the schema version bumps IS the migration. MySQL populates every existing row with `1` during the ALTER. No separate activation-time backfill step is written or required.
- Curated storage (`{wpdb->prefix}acrossai_mcp_server_tools`, from Feature 020): unchanged. Its schema, its `MCPServerToolQuery` API, and the `ToolExposureGate` call-time enforcement all remain as-is.

### Security Checklist

- [ ] All form/AJAX handlers verify nonce via `wp_verify_nonce()` or `check_ajax_referer()` — the Tools tab uses REST + nonces; no change from F020.
- [ ] All admin page renders check `current_user_can('manage_options')` — unchanged.
- [ ] All REST routes have explicit `permission_callback` — unchanged.
- [ ] All user input sanitized at system boundary with most-specific function — new POST split uses `absint()` for the three int flags and existing `sanitize_text_field()` for slugs.
- [ ] All output escaped at point of rendering with most-specific function — dialog copy uses `__()` + `esc_html` at render time.
- [ ] All DB queries use `$wpdb->prepare()` — the new column update goes through BerlinDB's `update_item()` which prepares under the hood.
- [ ] OAuth tokens / Application Passwords stored hashed — orthogonal; unchanged.
- [ ] File uploads — N/A.

### Key Entities

- **MCP Server**: a registered endpoint that exposes zero or more tools to AI clients. Each server row now carries three flags for built-in default tool enablement in addition to the existing name/slug/route/enabled fields.
- **Tool**: an ability advertised by an MCP server in its `tools/list` response. A tool is either a **built-in default tool** (fixed set of three: Discover Abilities, Get Ability Info, Execute Ability) or a **curated ability** (any WordPress ability the operator picked).
- **Built-in default tool state**: per-server, per-slug boolean stored as a column on the server row. Default is enabled; the operator can toggle it via the Tools tab.
- **Curated ability picks**: per-server presence rows in the curated-tools table (unchanged from Feature 020).
- **Composed tool list**: the union of the server row's enabled built-in defaults and its curated picks. This is the list passed to the MCP adapter at server-registration time and the list returned by the GET endpoint.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

- [ ] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`).
- [ ] PHPStan level 8: zero errors (`vendor/bin/phpstan`).
- [ ] ESLint: zero errors (`npm run lint:js`).
- [ ] PHPUnit tests written and passing for the compose helper, both filter paths, the REST split-on-POST / compose-on-GET, and the schema migration.
- [ ] Security checklist above: all applicable items verified.
- [ ] All hooks wired in `Main.php` — the new `mcp_adapter_default_server_config` subscription lives in `define_admin_hooks()`, not in a class constructor.
- [ ] Existing curated storage layer (`wp_acrossai_mcp_server_tools`, `MCPServerToolQuery`, `ToolExposureGate`) is untouched.
- [ ] No code duplication of the three built-in default slugs on the PHP side; a single canonical location holds them.
- [ ] All new functions, hooks, and classes prefixed with `acrossai_mcp_` (or `AcrossAI_MCP_Manager\` namespace).
- [ ] `npm run validate-packages` passes.

### Measurable Outcomes

- **SC-001**: On any enabled MCP server that has curated picks, `tools/list` returns those picks alongside the enabled built-in defaults within one request-cycle after Save (no cache invalidation delay).
- **SC-002**: The operator can remove a built-in default tool in ≤ 2 clicks (Remove button + confirm), and can restore all defaults + clear curated picks in ≤ 2 clicks (Reset button + confirm).
- **SC-003**: 100% of existing MCP server rows on any install upgrading to this feature retain their pre-upgrade advertised tool set (all three built-in defaults remain enabled by default; no server suddenly loses tools).
- **SC-004**: A companion plugin can add OR remove any tool slug for any database server via a single documented filter, without touching this plugin's code or DB directly.
- **SC-005**: A callback returning `null` / `false` / a non-array value from the companion filter does not produce a PHP fatal or warning; the server registers with a defensively-normalized tool list.
- **SC-006**: An install that had zero servers before the upgrade shows the same behavior as a fresh install (default server, when enabled, exposes exactly the three built-in defaults).

---

## Assumptions

- The MCP adapter package (`\WP\MCP\Plugin`) is present in the vendored tree (F009 baseline).
- The plugin's ability-exposure layer (F017) and call-time tool-curation gate (F020 `ToolExposureGate`) remain authoritative at call-time and are orthogonal to this feature's registration-time changes.
- The Tools tab is edited by a single operator at a time; the small race window between the two writes (server-row column update and curated `replace_set`) is acceptable and does not require an outer transaction in this increment.
- Operators who use the Reset button understand the destructive intent (removes all curated picks) — this is surfaced explicitly in the dialog copy.
- Multisite support is out of scope for this increment (plugin baseline).
