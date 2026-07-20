# Feature Specification: Per-Server Ability Permission-Callback Override

**Feature Branch**: `030-per-server-permission-override`
**Created**: 2026-07-20
**Status**: Draft
**Input**: User description: on the server-edit "Access Control" tab, **add** a new per-server boolean toggle (alongside the existing vendor `wpb-access-control` React panel — do NOT replace it) that, when enabled, causes every ability exposed to that MCP server to bypass its `permission_callback` — but only for in-flight MCP requests routed to that specific server. See `docs/planings-tasks/030-per-server-permission-override.md` for the full engineering brief.

## Clarifications

### Session 2026-07-20

- Q: Should the "Access Control" tab label change after the content update? → A: No content swap — the existing wpb-ac React panel stays; the permission override is an **additional** section on the same tab. Label remains `"Access Control"`.
- Q: Position of the new form section relative to the vendor wpb-ac panel? → A: Below the wpb-ac panel, separated by an `<hr>`. Standard access-control rules first; the escape-hatch override second.
- Q: Confirmation UX before saving the toggle? → A: Both **C** (warning banner rendered above the checkbox whenever the flag is currently ON — passive persistent reminder) and **B** (native browser `confirm()` prompt fires when the form is submitted with the checkbox checked). Belt-and-suspenders posture — informed by the security weight of an unconditional-allow override.
- Q: How should we help operators who don't want to hand-edit ability `permission_callback`s in code? → A: Add a promotional card to the Access Control tab that (1) offers one-click Install & Activate of the sibling plugin `acrossai-abilities-manager` (https://github.com/acrossai-co/acrossai-abilities-manager/) when it is missing/inactive, (2) shows an "Edit Abilities" button linking to `admin.php?page=acrossai-abilities-manager` when active, AND (3) documents the WordPress core filter this feature uses (`wp_register_ability_args`) so developers who prefer code can hook the same filter at priority > 999999 without installing the sibling plugin. The install/activate flow MUST reuse the existing `acrossai_addons` filter + `AddonsAjaxHandlers` (install/activate/deactivate) pattern from the `main-menu` composer package — do NOT reimplement.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Operator enables permission override for a specific MCP server (Priority: P1)

An MCP-server operator needs to grant an authenticated MCP client (e.g. a Claude Desktop connector or a scripted agent) access to every ability that has been exposed to a particular MCP server — regardless of what any ability's own `permission_callback` would normally decide. The operator opens the server-edit page for that server, switches to the Access Control tab, ticks a single checkbox labelled "Override abilities permission_callback for this MCP server," and saves. From that point on, MCP requests that route to that server invoke exposed abilities without the ability's own permission gate running; MCP requests to other servers and non-MCP consumers of the same abilities are unaffected.

**Why this priority**: This is the entire feature. Without this journey the checkbox does nothing.

**Independent Test**: Load `admin.php?page=acrossai_mcp_manager&action=edit&server={id}&tab=access-control`; tick the checkbox; save; open a second browser as a low-privilege user; hit the server's MCP tool-call endpoint for any exposed ability; observe success. Untick and repeat — expect the ability's original permission verdict to prevail.

**Acceptance Scenarios**:

1. **Given** server X has `override_abilities_permission = 0` and ability `foo/bar` is exposed to server X, **When** the operator ticks the checkbox on server X's Access Control tab and clicks Save, **Then** the checkbox stays ticked on reload AND the DB row's `override_abilities_permission` equals `1`.
2. **Given** server X has `override_abilities_permission = 1` and abilities `foo/bar` + `baz/qux` are both exposed to X, **When** an authenticated MCP client without normal capability calls either ability via server X's route, **Then** the call succeeds and the tool result is returned.
3. **Given** server X has `override_abilities_permission = 1` but ability `not/exposed` is registered site-wide and NOT in `wp_acrossai_mcp_server_abilities` for X, **When** any consumer invokes `not/exposed`, **Then** the ability's original `permission_callback` decides (override does not apply).

---

### User Story 2 - Override does not leak to other servers or to site-wide callers (Priority: P1)

The operator has two MCP servers on the same site. Server A has the override switch ON; server B has it OFF. A low-privilege authenticated user must be able to invoke abilities via server A but must remain blocked when calling the same abilities via server B — or via non-MCP paths such as the WordPress Abilities API REST route, WP-CLI, or wp-admin screens that consult the same ability.

**Why this priority**: A per-server toggle that silently affects other servers or site-wide consumers is a security regression. This is the non-negotiable invariant that makes the feature safe to ship.

**Independent Test**: With server A override ON and server B override OFF, invoke ability `foo/bar` (exposed to both) via A's route → succeeds; via B's route → denied per original callback; via a non-MCP REST endpoint that consults `foo/bar` → denied per original callback.

**Acceptance Scenarios**:

1. **Given** server A override ON, server B override OFF, ability `foo/bar` exposed to both, **When** a low-privilege user calls `foo/bar` via server B, **Then** the request is denied per `foo/bar`'s original `permission_callback`.
2. **Given** server A override ON, ability `foo/bar` exposed to A, **When** the same user calls the WordPress Abilities REST endpoint for `foo/bar` (not routed through any MCP server), **Then** the request is denied per `foo/bar`'s original `permission_callback`.
3. **Given** server A override ON and the sibling `acrossai-abilities-manager` plugin is active with an access-control rule that denies `foo/bar` for the current user, **When** the user calls `foo/bar` via server A, **Then** the request succeeds (the per-server override wins over the sibling plugin's per-slug injector).

---

### User Story 3 - Override state persists safely and upgrades cleanly (Priority: P2)

The operator upgrades the plugin from a version that predates this feature. Every existing MCP server on the site must default to override-OFF (byte-for-byte identical pre-feature behaviour). The new column must be added to the servers table without requiring a manual DB migration, without emitting warnings, and without re-running its own ALTER on subsequent admin loads.

**Why this priority**: A silent, idempotent schema upgrade is what makes the feature safe to release into an existing install. Without it operators would face fatals or unexpected behaviour changes on upgrade.

**Independent Test**: Take a production-shaped copy where `wp_acrossai_mcp_servers` has table version `1.1.1`; deploy this feature; load any wp-admin page; verify the column is added, the option stamp advances to `1.1.2`, no errors appear in `debug.log`, and every existing row has `override_abilities_permission = 0`.

**Acceptance Scenarios**:

1. **Given** an existing install at MCPServer table `$version = 1.1.1`, **When** the plugin update deploys and the operator loads any admin page, **Then** the `override_abilities_permission` column exists on `wp_acrossai_mcp_servers`, the `db_version` option reads `1.1.2`, and `debug.log` is silent.
2. **Given** the upgrade already ran, **When** the operator reloads any admin page, **Then** no ALTER statement is re-issued (verified by empty `SHOW WARNINGS` and silent `debug.log`).
3. **Given** any existing server row prior to upgrade, **When** the upgrade runs, **Then** that row's `override_abilities_permission` equals `0` (feature-off by default; no behaviour change without operator opt-in).

---

### User Story 4 - Operator discovers the ability-editing plugin from within the Access Control tab (Priority: P2)

An operator who wants fine-grained control (e.g. per-role or per-capability rules, or editing an ability's `permission_callback` in a UI) opens the Access Control tab. They see the permission-override section and, below it, a promo card recommending the `acrossai-abilities-manager` plugin. From that card they can either install & activate the plugin in place (no page navigation), or — if it is already active — jump straight to its Abilities admin screen. A developer who prefers not to install another plugin can expand a "Prefer to use code?" section right below the card and see the exact WP filter (`wp_register_ability_args`) + priority they can hook themselves.

**Why this priority**: This is the promotion/onboarding lane. The core functionality works without it, but this card is what turns Feature 030 from a raw toggle into a discoverable pathway toward the recommended fine-grained tooling.

**Independent Test**: On a fresh site where `acrossai-abilities-manager` is not installed, open the Access Control tab and click "Install & Activate" — verify the plugin is installed and active. Reload the tab — verify the button is now "Edit Abilities" linking to `admin.php?page=acrossai-abilities-manager`. Expand the "Prefer to use code?" section — verify the documented filter name + priority match this feature's actual registration.

**Acceptance Scenarios**:

1. **Given** `acrossai-abilities-manager` is not installed on the site, **When** an operator with `install_plugins` + `activate_plugins` capabilities clicks "Install & Activate" on the promo card, **Then** the plugin is downloaded from the documented source, activated, and the card re-renders showing the "Edit Abilities" button — all without a full page reload.
2. **Given** the sibling plugin is already installed and active, **When** the operator opens the Access Control tab, **Then** the promo card shows only the "Edit Abilities" button (no install action available) linking to the abilities-manager admin screen.
3. **Given** any state of the sibling plugin, **When** the operator expands the "Prefer to use code?" section, **Then** the documented filter name is `wp_register_ability_args` and the documented priority is `999999` — matching exactly what this feature registers.

---

### Edge Cases

- **The wpb-access-control React panel and the new permission-override form coexist on the same tab.** The two sections are independent: saving one does not affect the state of the other. Each has its own nonce (existing vendor nonce for the React panel's REST calls; new `acrossai_mcp_manager_permission_override_{server_id}` nonce for the form POST).
- **Operator lacks `install_plugins` / `activate_plugins` capability.** The promo card's Install & Activate button MUST be hidden or disabled for such operators (main-menu package's AJAX handlers already refuse the action with a 403; the card SHOULD not render the button at all to avoid dead UX).
- **`acrossai-abilities-manager` is installed but at a version incompatible with the promo card's assumptions** (e.g., the abilities admin page moved). The "Edit Abilities" button MUST still link to `admin.php?page=acrossai-abilities-manager` — if that slug is not registered, WP will show a permission error page. This is acceptable: the promo card is not responsible for cross-version compatibility guarantees of the sibling plugin.
- **The `acrossai_addons` filter is already registered elsewhere** and has already listed `acrossai-abilities-manager` (e.g., by main-menu's default seed list). FR-019's "do not double-register" clause covers this: register only if not already present in the filtered array.
- **User visits `?tab=access-control` for a server ID that doesn't exist.** Existing 404/redirect behaviour on the server-edit page is preserved — this feature does not add new failure paths.
- **`AccessControlBlock` renderer is still called from `AccessControlTab::render_body()`.** The wpb-ac React panel continues to render exactly as before this feature. Third-party plugins that mount the vendor panel via `AccessControlBlock::instance()->render(...)` also continue to work.
- **`CurrentServerHolder` is not yet booted (very early request lifecycle).** The closure sees `null === get_server_id()` and falls through to the ability's original `permission_callback` — fail-safe, not fail-open.
- **Ability has no `permission_callback` defined at registration.** The closure's fallback treats missing callbacks as deny (returns `false`) when override is OFF, matching WP Abilities API semantics. When override is ON and the ability is exposed to the current server, the closure returns `true` regardless.
- **Operator flips the toggle mid-request.** DB read happens per-request inside the closure; the next request after Save picks up the new value.
- **Sibling `acrossai-abilities-manager` plugin is absent or inactive.** No impact — the override closure does not depend on it; the P999999 registration wins by ordering even when the sibling plugin's P100000 filter is not registered at all.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The `wp_acrossai_mcp_servers` table MUST gain a `override_abilities_permission` column (`tinyint(1)`, `NOT NULL`, `DEFAULT 0`) via BerlinDB's schema-drift-reconciliation contract (bump `$version` `1.1.1 → 1.1.2`; idempotent `upgrade_to_1_1_2()` guarded by `INFORMATION_SCHEMA.COLUMNS` existence check).
- **FR-002**: The MCPServer Row MUST expose the new column as a public property and cast it to `int` in the row constructor, matching the existing `tool_*` tinyint handling.
- **FR-003**: The server-edit Access Control tab (`?tab=access-control`) MUST render — **in addition to** the existing vendor `wpb-access-control` React panel — a new form section containing a single labelled checkbox bound to `override_abilities_permission`, a save button, and a descriptive paragraph explaining the override's scope + precedence. The vendor React mount div (`<div id="acrossai-mcp-ac-root">`) MUST continue to be emitted first; the new form section MUST be rendered BELOW it, visually separated by an `<hr>` element.
- **FR-004**: The Access Control tab MUST retain its slug (`'access-control'`) and its registration in the built-in server-tabs list — only the tab body is rewritten. Existing bookmarks to `?tab=access-control` MUST continue to land on this same tab.
- **FR-005**: The form save path MUST use a per-server nonce (`acrossai_mcp_manager_permission_override_{server_id}`) and MUST reject requests that fail either `manage_options` capability or nonce verification. Save handler MUST persist via `MCPServerQuery::instance()->update_item()`, then redirect back to the same tab with a success indicator.
- **FR-006**: The save path MUST be a dedicated handler distinct from `handle_update_server()` — the Update Server tab's persistence MUST NOT be touched. Each tab owns its own POST route.
- **FR-007**: A new PHP class MUST hook `wp_register_ability_args` at priority **999999** and wrap every ability's `permission_callback` in a closure that captures the original callback.
- **FR-008**: At call time the closure MUST resolve the current MCP server via `CurrentServerHolder::instance()->get_server_id()`. When the server context is `null` the closure MUST invoke and return the original callback's result (no override).
- **FR-009**: When a server context is present, the closure MUST read the corresponding server row and, if `override_abilities_permission = 0` or the row is missing, MUST invoke and return the original callback's result (no override).
- **FR-010**: When override is ON, the closure MUST additionally verify that the ability slug is actually exposed to this server via `ExposureResolver::resolve()` against `wp_acrossai_mcp_server_abilities`. Abilities not exposed to the current server MUST fall through to their original callback.
- **FR-011**: Only when server context is present, override is ON, and the slug is exposed, the closure MUST return `true`.
- **FR-012**: The filter priority (999999) MUST be strictly higher than the sibling `acrossai-abilities-manager` plugin's `wp_register_ability_args` injector at P100000, and strictly higher than this plugin's own `CallbackReplacer` at P10. This ordering is a load-bearing invariant of the feature.
- **FR-013**: `public/Renderers/AccessControlBlock.php` MUST remain untouched — it is a documented public API for third-party plugins AND is the renderer that mounts the wpb-ac panel this tab still displays. `AccessControlTab::render_body()` MUST continue to invoke `AccessControlBlock::instance()->render(...)` for the panel; the new form section is added alongside it, not in place of it.
- **FR-014**: `admin/Main.php::maybe_enqueue_access_control_app()` MUST remain in place. It is NOT orphaned by this feature — the enqueue is still required to hydrate the preserved wpb-ac React panel.
- **FR-015**: On upgrade from any prior version, every existing server row MUST have `override_abilities_permission = 0`. No data migration MUST be required — the DEFAULT value delivers this automatically.
- **FR-016**: Whenever the override is currently `1` for the server being viewed, the tab MUST render a persistent warning banner (`<div class="notice notice-warning inline">…</div>`) above the checkbox, explaining that every exposed ability's `permission_callback` is being bypassed for MCP requests to this server.
- **FR-017**: The form MUST fire a native browser `confirm()` prompt (inline `<script>`, no build step) when submitted with the override checkbox checked. If the operator dismisses the prompt, the form submission MUST be cancelled and no state change occurs. The confirm message MUST name the specific server (server name from the row) and briefly restate the override's scope.
- **FR-018**: The Access Control tab MUST render a promotional card for the `acrossai-abilities-manager` sibling plugin. The card:
  - When the sibling plugin is **not installed**: MUST render an "Install & Activate on Add-ons page →" button that links (with `target="_blank"`) to `admin.php?page=acrossai-addons`, where the operator completes install/activate via the existing `main-menu` package UI (`AddonsAjaxHandlers` handles the AJAX flow there — F030 does NOT reimplement it inline).
  - When the sibling plugin is **installed but not active**: MUST render an "Activate on Add-ons page →" button linking (with `target="_blank"`) to the same Add-ons page.
  - When the sibling plugin is **installed and active**: MUST render an "Edit Abilities →" button linking (with `target="_blank"`) to `admin.php?page=acrossai-abilities-manager`.
  - MUST reuse `\AcrossAI_Main_Menu\AddonsInstaller::find_plugin_file()` for state detection (with a manual `get_plugins()` fallback for graceful degradation when the vendor class is absent).
  - Rationale for link-in-new-tab instead of inline AJAX button: keeps the promo card scope small (no duplicate JS/config/nonce emission), preserves the operator's tab-scoped work-in-progress on the Access Control page, and delegates the actual install/activate UX to the canonical `main-menu` Add-ons page which is the single source of truth for addon flows across the AcrossAI plugin family.
- **FR-019**: The `acrossai-abilities-manager` slug MUST be reachable through `apply_filters('acrossai_addons', [])`. If the entry is already present in `\AcrossAI_Main_Menu\AddonsPageRenderer::ADDONS`'s baseline constant (F030 verifies this is the case as of `acrossai-co/main-menu` `0.0.22+`), no additional registration is required. If a future `main-menu` version drops the baseline entry, F030 MUST register the addon via `add_filter('acrossai_addons', …)` at that point.
- **FR-020**: Below the promo card, the tab MUST render a "Prefer to use code?" collapsible section (`<details>` element) documenting the WordPress core filter this feature registers on — `wp_register_ability_args` at priority `999999` — with a short code snippet showing how a developer could hook the same filter at priority `> 999999` to fine-tune or override the override behaviour for a specific slug / server / user without installing the sibling plugin.

### WordPress Requirements

**PHP Version**: PHP 8.0+ (plugin supports 7.4 minimum; this feature has no new PHP-version-gated syntax).
**WordPress Version**: 6.9+
**Multisite**: Single-site only — matches the plugin-wide `multisite_support: false` policy in `AGENTS.md`.
**Required Plugins / Packages**: None new. Feature depends on the plugin's existing internal infra: BerlinDB Core 3.0 (already vendored), WP Abilities API (already required), MCP Adapter (already required).
**Required Composer packages** (already installed): `main-menu` — its `acrossai_addons` filter + `AddonsAjaxHandlers` (`install` / `activate` / `deactivate`) + `AddonsPageRenderer::button_state_for()` are load-bearing dependencies for the promo card. This feature MUST NOT reimplement the install/activate flow.

**Optional Integrations**:
- `acrossai-abilities-manager` (sibling plugin) — MUST degrade gracefully whether active or not. Runtime override closure does not import from it; the P999999 filter ordering wins by number regardless of the sibling's presence. The Access Control tab's promo card actively surfaces this plugin as the recommended fine-grained tooling (see FR-018).
- `wpb-access-control` vendor React app — CONTINUES to be mounted by this plugin's admin on the Access Control tab, unchanged from prior behaviour. Feature 030 adds a second form section + promo card BELOW it.

### Module Placement

**PHP Class(es)**:
- `includes/Database/MCPServer/Schema.php` → namespace `AcrossAI_MCP_Manager\Includes\Database\MCPServer` — column added to `$columns` array.
- `includes/Database/MCPServer/Table.php` → namespace `AcrossAI_MCP_Manager\Includes\Database\MCPServer` — `$version` bump, `$upgrades` entry, new `upgrade_to_1_1_2()` method.
- `includes/Database/MCPServer/Row.php` → namespace `AcrossAI_MCP_Manager\Includes\Database\MCPServer` — public property + int cast.
- `admin/Partials/ServerTabs/AccessControlTab.php` → namespace `AcrossAI_MCP_Manager\Admin\Partials\ServerTabs` — `render_body()` extended: continues to call `AccessControlBlock::instance()->render(...)` for the wpb-ac panel, then `<hr>`, then renders the new permission-override form (with warning banner + confirm() script when applicable), then renders the promo card + "Prefer to use code?" `<details>` section.
- `admin/Partials/Settings.php` → namespace `AcrossAI_MCP_Manager\Admin\Partials` — new `handle_save_permission_override()` method + wire into the existing `admin_init` server-edit POST router.
- `includes/Abilities/PermissionOverrideProcessor.php` (NEW) → namespace `AcrossAI_MCP_Manager\Includes\Abilities` — plugin singleton implementing `boot()` + `inject_override( array $args, string $slug ): array`.
- `admin/Partials/AddonsFilter.php` (existing) → extend or add a sibling class that registers `acrossai-abilities-manager` in the `acrossai_addons` filter (idempotent — do not double-register if main-menu already seeds it).
- `admin/Partials/ServerTabs/Partials/AbilitiesManagerPromoCard.php` (NEW, or inline helper inside `AccessControlTab`) → namespace `AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Partials` — renders the promo card by reusing `AddonsPageRenderer::button_state_for()` (or an equivalent public helper) for state detection + the same JS/AJAX endpoints for install/activate.

**Hook Registration**: `PermissionOverrideProcessor::boot()` is called from `Main::load_hooks()`, matching the sibling plugin's `AcrossAI_Ability_Override_Processor::boot()` shape (inline `add_filter` inside a static boot method). This is an acknowledged deviation from the strict "only `Main.php` uses `$this->loader`" rule and is justified by the mirror-the-sibling-plugin decision recorded in the planning doc. The save handler in `Settings.php` is registered through the existing `admin_init` router — no new Loader wiring for the form path.

### Admin UI Requirements

**Existing screen (Access Control tab body rewrite)**:

This feature does NOT create a new admin screen. It replaces the body of a pre-existing per-server tab. The plugin's server-edit tabs already use hand-rolled admin forms (see the Update Server tab, Danger Zone tab, etc.); Feature 030 follows the same precedent. The constitution's "New screen ⇒ must use DataForm/DataViews" rule does not apply to tab-body rewrites of existing screens.

The form uses standard WP admin markup: `wp_nonce_field()`, `<input type="checkbox">`, `submit_button()`, `checked()` for the checkbox state. Success/error feedback uses `admin_notices` in the standard `<div class="notice notice-success"><p>…</p></div>` shape.

### REST API Contract

No new REST routes. The feature uses:
- An existing wp-admin form POST (routed through `admin_init` and the plugin's per-tab save-handler dispatcher).
- The existing WP Abilities API and MCP Adapter surfaces at ability-call time. Feature 030 does not add or modify any REST route; it only injects a closure into the Abilities API's `wp_register_ability_args` filter.

### Database / Storage

**Custom DB table** (existing table gains one column):
- Table: `{$wpdb->prefix}acrossai_mcp_servers`
- New column: `override_abilities_permission tinyint(1) NOT NULL DEFAULT 0`
- Justification: The toggle is per-server state, sits alongside the existing `tool_*` per-server flags on the same table, and is read at request time inside a hot ability-call closure. Storing it on the server row keeps the read path to a single indexed `SELECT … WHERE id = X` query the plugin already runs.
- Upgrade mechanism: BerlinDB `$version` `1.1.1 → 1.1.2` + `upgrade_to_1_1_2()` idempotent ALTER, fired by the existing `Main::reconcile_database_schemas()` at `admin_init` P3.

### Security Checklist

- [x] All form/AJAX handlers verify nonce via `wp_verify_nonce()` or `check_admin_referer()` — new save handler uses `check_admin_referer( 'acrossai_mcp_manager_permission_override_' . $server_id, … )`.
- [x] All admin page renders check `current_user_can('manage_options')` — server-edit page already enforces; save handler re-verifies.
- [x] All REST routes have explicit `permission_callback` — N/A, no new REST routes.
- [x] All user input sanitized at system boundary with most-specific function — `absint()` for `server`; `! empty()` for the checkbox tinyint coercion.
- [x] All output escaped at point of rendering with most-specific function — `esc_html__()` for labels, `checked()` for the checkbox state, `esc_url()` for the form action.
- [x] All DB queries use `$wpdb->prepare()` — column read via BerlinDB Query (parameterised); upgrader ALTER uses backtick-quoted `{$table}` interpolation for identifier only (identifier interpolation is standard for BerlinDB upgrade callbacks).
- [x] OAuth tokens / Application Passwords stored hashed — N/A, feature does not touch tokens.
- [x] File uploads validated — N/A, no file uploads.

**Trust boundary** (SEC-030-004 remediation): the runtime override closure trusts `CurrentServerHolder::instance()->get_server_id()` as the sole authority for "which server is being served right now". That holder is populated only inside `rest_pre_dispatch` P5 by `capture_from_request()`, which matches the incoming REST route against `McpAdapter::instance()->get_servers()` — server-side authoritative. It is NEVER populated from URL parameters, POST body, headers, or client-supplied identifiers. Any bug in `CurrentServerHolder::capture_from_request()` that let a client control which server_id is returned would defeat F030's per-server scoping; A17 wiring (including the `shutdown` P999 safety-net that clears the holder even when a fatal error kills the request before `rest_post_dispatch` fires) MUST NOT regress. The same trust boundary applies to `PermissionOverrideProcessor::$server_row_cache` — cleared symmetric with `CurrentServerHolder::clear()` on both `rest_post_dispatch` and `shutdown` to prevent long-lived-PHP-process (Roadrunner, FrankenPHP) cross-request leaks.

### Key Entities

- **MCP Server** (existing entity, `wp_acrossai_mcp_servers`): gains one attribute — `override_abilities_permission`, a per-server boolean flag that governs runtime permission-callback override for abilities exposed to this server.
- **MCP Server ↔ Ability exposure** (existing junction entity, `wp_acrossai_mcp_server_abilities`): unchanged. Read at request time by the override closure to scope the override to exposed abilities only.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

- [ ] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`)
- [ ] PHPStan level 8: zero errors (`vendor/bin/phpstan`)
- [ ] ESLint: zero errors (`npm run lint:js` if any JS added — this feature adds none)
- [ ] PHPUnit tests written and passing for `PermissionOverrideProcessor` covering all four fall-through paths (null server context; override off; not exposed; override on + exposed) plus the save handler's nonce + capability rejection paths
- [ ] Security checklist above: all applicable items verified
- [ ] All hooks wired in `Main.php` — save handler routes through the existing `admin_init` server-edit dispatcher; `PermissionOverrideProcessor::boot()` is invoked from `Main::load_hooks()` (documented precedent from sibling plugin)
- [ ] All new admin UI uses DataForm/DataViews (unless a pre-approved exception applies) — this tab is a pre-existing per-server tab; hand-rolled admin form is precedented across Update Server / Danger Zone tabs
- [ ] No code duplication — closure factory + `call_original()` helper are the only shared logic and live in the new class
- [ ] All functions, hooks, and classes prefixed with `acrossai_mcp_` — nonce, form field name, redirect flag all match
- [ ] `npm run validate-packages` passes

### Measurable Outcomes

- **SC-001**: An operator can toggle the override for a server in ≤ 3 interactions: page load, checkbox click, save button click.
- **SC-002**: With override ON on server X and any low-privilege authenticated user, 100% of abilities exposed to X via `wp_acrossai_mcp_server_abilities` return non-denied when invoked through X's MCP route. Verified via an integration test that lists exposed abilities and calls each one as a subscriber-role user.
- **SC-003**: With override OFF on server X, MCP-request behaviour against server X is byte-for-byte identical to pre-feature behaviour. Verified by a snapshot test comparing response bodies + status codes for a known ability call before and after Feature 030 deploy on a synthetic fixture site.
- **SC-004**: For any ability `foo/bar` exposed to server X where override is ON, non-MCP consumers of `foo/bar` (WordPress Abilities REST route, WP-CLI, wp-admin screens) see the ability's original `permission_callback` verdict unchanged. Verified by direct REST call to the Abilities endpoint for the same slug and same user — expected: original callback's verdict (no override).
- **SC-005**: On upgrade from a `wpdb_acrossai_mcp_servers_version = 1.1.1` install, the `override_abilities_permission` column is added exactly once, the version stamp advances to `1.1.2`, and `debug.log` shows zero AcrossAI-related lines during the upgrade window. Verified by manual replay of the D28 phantom-version-guard test procedure documented in Feature 011.
- **SC-006**: On a fresh site where `acrossai-abilities-manager` is missing, an operator with `install_plugins` + `activate_plugins` capabilities can reach the sibling plugin's install/activate UX from the Access Control tab in **one click** — the promo card's "Install & Activate on Add-ons page →" button opens the shared main-menu Add-ons page (`admin.php?page=acrossai-addons`) in a new browser tab, where the operator completes install/activate via the existing addon card there. When the operator returns to (or reloads) the Access Control tab afterwards, the promo card MUST re-render with the "Edit Abilities →" button linking to `admin.php?page=acrossai-abilities-manager`.
- **SC-007**: With the override flag currently ON for the server being viewed, the Access Control tab MUST render a persistent warning banner ABOVE the checkbox stating the bypass behaviour and naming the target server. Submitting the form with the checkbox still checked MUST trigger a native browser `confirm()` prompt that names the same server; dismissing the prompt MUST cancel the submission (no DB write, no redirect).


---

## Assumptions

- The plugin's server-edit tabs precedent (Update Server, Danger Zone, etc.) allows hand-rolled admin forms outside DataForm/DataViews; the constitution's DataForm rule is scoped to "new screens" and does not apply to this feature's tab-body rewrite.
- `CurrentServerHolder` is present and populated by `rest_pre_dispatch` P5 whenever an MCP REST request is in flight, and cleared by `rest_post_dispatch`/`shutdown` P999. Feature 030's closure treats "no server context" as the safe fall-through — this depends on the holder's contract being correct.
- Sibling `acrossai-abilities-manager` plugin's `AcrossAI_Ability_Override_Processor` registers at P100000 (verified: line 164 of that plugin's `AcrossAI_Ability_Override_Processor.php`). If a future version of the sibling raises its priority above 999999, Feature 030's ordering guarantee breaks — mitigation: bump this feature's priority to `PHP_INT_MAX` in a follow-up feature.
- BerlinDB's `Table::maybe_upgrade()` runs idempotently under `Main::reconcile_database_schemas()` on `admin_init` P3, matching D28. This feature does not modify the reconciliation harness.
- WP Abilities API contract: a `permission_callback` returning `true` grants access; returning falsy denies; missing callback is treated as deny. The override closure's `false` fallback for missing-original-callback matches this contract.
- WordPress.org plugin-directory assets in `.wordpress-org/` (2 banners, 1 icon.svg, 11 screenshots) will be committed in the same PR as this feature. Out of scope for the spec but in scope for the PR.
- Multisite support is out of scope for this increment (`multisite_support: false` per AGENTS.md).
- The `main-menu` composer package's public API (`acrossai_addons` filter, `AddonsAjaxHandlers`, `AddonsPageRenderer::button_state_for()`) is stable and available. If any of these move or rename, this feature must be updated to match. Documented dependency.
- The `acrossai-abilities-manager` plugin is distributed via GitHub releases (per `https://github.com/acrossai-co/acrossai-abilities-manager/`). The add-on entry MUST use `source: 'github'` and MUST point `download_url` at a stable release ZIP. Plan phase will pin the release URL; if the plugin later publishes to WordPress.org, `source` switches to `'wordpress.org'` and `download_url` is dropped.
- Inline `<script>` for the `confirm()` prompt is acceptable admin-only markup (no build step, no @wordpress/scripts entry). Modern browsers all support `confirm()`; screen-reader compatibility is a WordPress admin baseline expectation and is not a differentiator for this feature.
- **DEC-F030-EXPLICIT-EXPOSURE-ONLY** (proposed durable memory entry — to be formalized via `/speckit-memory-md-capture`): F030's runtime override closure calls `ExposureResolver::resolve( $server_id, $slug, array() )` with intentionally empty `$meta`. This is a scoped, documented deviation from DEC-ABILITY-OVERRIDE-RESOLUTION's canonical "row exists → row wins; no row → `meta.mcp.public` fallback" contract. By passing empty meta, F030 collapses the fallback to `false` — the override applies ONLY to abilities the operator has EXPLICITLY toggled ON in the Abilities tab (junction row exists), NOT to abilities that are globally-public via `meta.mcp.public = true` without a per-server junction row. The narrower scope keeps the six-layer defensive gating meaningful for a security-critical bypass — an unconditional `permission_callback → true` should require explicit operator opt-in per-ability, not inherit implicit visibility from a third-party plugin author's meta declaration.
- **DEV5 candidate** (proposed accepted deviation — to be added to `docs/memory/INDEX.md` §Accepted Deviations via `/speckit-memory-md-capture`): Per-server-edit tab sub-forms (`admin/Partials/ServerTabs/*::render_body()`) MAY use hand-rolled admin form HTML instead of `DataForm` from `@wordpress/dataviews` when the form has ≤ 3 configurable fields with no filter/sort/pagination requirement. Precedents: Update Server tab (F013), Danger Zone tab (F013), Access Control tab per-server override toggle (F030). Rationale: DataForm's toolchain overhead (JS build entry + REST wiring) is disproportionate for a single-field or small-field form; the shared WP admin form idioms (`wp_nonce_field`, `submit_button`, `checked()`, `esc_*`) already provide the security posture the constitution §III mandates.
