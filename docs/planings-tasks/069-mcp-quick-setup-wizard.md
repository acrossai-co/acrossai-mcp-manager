# Planning: MCP Quick Setup Wizard (Feature 069)

Ship a linear 5-step **Quick Setup Wizard** that reduces the current 11-tab
per-server-edit exploration to a single guided flow. New entry points on the
WP admin bar and via a first-run notice on the plugin page route the user to
`/wp-admin/admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1`, where a
new React app takes over the plugin page content area (list table hidden while
the wizard renders). The wizard is **additive** — every existing tab, REST
route, and DB schema stays intact; users who never touch the wizard see the
plugin exactly as it is today.

The design is fully spec'd in `docs/quick-setup-design-brief.md` (the
Claude Design source-of-truth) and the ported mockup at Claude Design project
`135ba973-6a42-48b5-9d50-8f79ddaaaa7f` file `Quick Setup Fullpage.dc.html`.
This planning doc is the implementation contract; the design doc is the visual
contract. Any pixel/copy divergence between the two goes to the design doc's
version first, then reflects here.

The wizard is **URL-driven** — every step is a bookmarkable/shareable URL
(`&step=1..5|done`, plus `&method=connectors|client|npm|wpcli` in Step 5).
Browser Back / Forward walks the flow without full page reloads via
`history.pushState` + a `popstate` listener. Reload mid-wizard restores
answers from server-persisted state (per-user 30-minute transient +
authoritative DB writes on step-advance for the fields that map to existing
tables like MCPServer / wpb-ac).

The wizard is **conditionally branching**:

- **Step 4 auto-skip** — if the selected server is already `is_enabled = 1`,
  the "Enable server" step drops from the flow. Progress bar shows 4 steps
  instead of 5; Back from Step 5 lands on Step 3.
- **Step 3 dual state** — if `acrossai-abilities-manager` is inactive, show a
  "3 abilities available" state with a promo card + WordPress.org install
  CTA. If active, show the real ability count + "Enable all" + "Configure
  one-by-one" (opens the existing Abilities tab in a new browser tab).
- **Step 5 Connectors card tri-state** — driven by `is_plugin_active('acrossai-pro/acrossai-pro.php')`:
  (A) not installed → "Get AcrossAI Pro" pricing link + trial trust line;
  (B) installed inactive → yellow "installed but not active" notice + "Activate" CTA;
  (C) active → 4 provider sub-tabs (ChatGPT / Claude / Gemini / Grok) each
  showing the plugin's canonical MCP URL with copy-to-clipboard.

The wizard's DATA READS come from existing plugin surfaces — **no new queries
are needed for server/abilities/access-control reads**:

- Server list: `MCPServerQuery::instance()->query([...])` (same call the list
  table uses at `admin/Partials/MCPServerListTable.php:82`).
- Abilities count: `count( wp_get_abilities() )` — the WP core hook the
  Abilities tab (`admin/Partials/ServerTabs/AbilitiesTab.php`) already reads.
- Access-control providers/rules: the F015 `AccessControlManager` wrapper at
  `includes/AccessControl/AcrossAI_MCP_Access_Control.php`, and the React
  entry at `src/js/access-control.js`.
- Connection-method DTOs (client list + AI connector panels): F035
  `ConnectionMethodRegistry::instance()->get_clients()` /
  `->get_ai_connectors()` /  `->get_npm_methods()` at
  `public/Discovery/ConnectionMethodRegistry.php`.
- MCP URL: derived from the selected server's `server_route_namespace` +
  `server_route` (existing Row properties) — no new column.
- Plugin activation states: `is_plugin_active()` — same check
  `AbilitiesTab.php` (F023) and `AIConnectorsPromoTab.php` (F040) already use.

The wizard's DATA WRITES all delegate to existing surfaces:

- Server create → new MCP server row via `MCPServerQuery::add_item()`.
- Access-control rule → the wpb-ac `RuleQuery` (same path the F015 Access
  Control tab uses).
- Enable server → `MCPServerQuery::update_item( $id, [ 'is_enabled' => 1 ] )`.
- Per-server ability curation → F017 REST controller
  `/acrossai-mcp-manager/v1/servers/{server_id}/abilities` (POST).

The wizard's **only net-new persistence** is a per-user transient
(`acrossai_mcp_manager_quick_setup_state_{user_id}`, 30-min TTL) that
scratch-pads in-flight step answers so reload doesn't lose position. On
successful completion, transient is cleared. A separate site-level transient
(`acrossai_mcp_manager_quick_setup_prompt`, 24h TTL, set on plugin activation)
gates the first-run banner.

Version bump: `0.2.10` → `0.2.11` (patch — matches the recent release cadence
and matches user instruction). Release PR follows the standard branch → CI →
squash-merge → tag → GitHub Release flow used in the 0.2.7-through-0.2.10
releases.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "mcp-quick-setup-wizard"

# 2. Specify
/speckit.specify "Add a linear 5-step admin wizard that condenses the
existing 11-tab per-server-edit page into a single guided flow. Entry
points: (a) new admin-bar node 'MCP Quick Setup' with dashicon wrench
gated on manage_options and rendered via admin_bar_menu at priority 100;
(b) dismissible notice on the plugin page while a first-run transient
(acrossai_mcp_manager_quick_setup_prompt, 24h TTL, set on plugin activation)
is live. Both entry points target
admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1. The wizard hijacks
the plugin page render when quick-setup=1 is present in $_GET so the
existing MCPServerListTable does not render; a new
admin/Partials/QuickSetup/QuickSetupPage singleton renders the React mount
point <div id='acrossai-mcp-quick-setup-root'></div>. Step index is a URL
query param (?step=1|2|3|4|5|done) so browser Back / Forward walks steps
via history.pushState + popstate listener; deep links (e.g.
&step=5&method=client) work. New REST namespace
/acrossai-mcp-manager/v1/quick-setup/* with three routes: GET /state
(returns server list, per-server settings, abilities count,
is_plugin_active for acrossai-pro + acrossai-abilities-manager),
POST /step (persists per-step transient scratch-pad + delegates
authoritative writes to existing surfaces: MCPServerQuery, wpb-ac RuleQuery,
F017 abilities controller), POST /complete (clears the first-run transient
+ marks the per-user state transient completed). Every REST route is gated
on manage_options and requires a valid X-WP-Nonce. Data source reuse: the
server list comes from MCPServerQuery::instance()->query — same call the
list table already uses; abilities count comes from wp_get_abilities();
Connection-method DTOs (Step 5 client picker + AI connector panels + npm
command + WP-CLI command) come from ConnectionMethodRegistry (F035); AC
rule reads/writes flow through the F015 AccessControlManager wrapper.
Conditional branching: Step 4 (Enable server) is auto-skipped when the
selected server is already enabled (progress bar shows 4 steps); Step 3
(Abilities) dual-state on is_plugin_active('acrossai-abilities-manager/...');
Step 5 Connectors card tri-state on is_plugin_active('acrossai-pro/...').
New React entry src/js/quick-setup.js bundled via webpack.config.js
alongside js/embeds, js/abilities, js/tools; enqueue in Admin\\Main
gated on ?page=acrossai_mcp_manager&quick-setup=1 so the ~200KB bundle
never loads on the list-table view. Component tree per
docs/quick-setup-design-brief.md: App, StepLayout, Step1_ServerPick,
Step1_ServerCreate, Step2_AccessControl, Step3_Abilities,
Step4_EnableServer, Step5_MethodGrid + one panel per method
(Connectors/Client/Npm/WpCli), Completion. Custom hooks
useWizardRouter (URL step/method state) and useWizardState (REST +
@wordpress/data store). New SCSS module src/scss/quick-setup.scss ports
the design's inline styles to proper CSS classes with SCSS variables
matching the design tokens (#3858e9 primary blue, #4f46e5 brand purple,
#4ab866 success, #f0b849 warning, #1e1e1e text, #757575 muted, #ddd border,
2px border-radius, 8px spacing unit, JetBrains Mono for code, system font
stack elsewhere). Copy assets/quick-setup/acrossai-logo.svg from the
design project so the wizard header logo has a source file. Do not modify
any existing per-server-edit tab, REST route, or DB schema — the wizard
is strictly additive. Do not seed any DB rows on plugin activation
beyond the existing default server row and the two new transients. Bump
plugin version 0.2.10 -> 0.2.11 in acrossai-mcp-manager.php,
includes/Main.php, README.txt. Add a = 0.2.11 = changelog section
describing the new wizard, entry points, conditional branching, and the
'quick-setup=1' URL. Memory hygiene per
PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION: capture
DEC-WIZARD-URL-DRIVEN-STEP-STATE and DEC-WIZARD-ADDITIVE-NO-EXISTING-CHANGES
as Active decisions."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all of these
> governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, hook registration rules,
>    Before Commit Checklist. Every new PHP class in this feature MUST
>    implement the `instance()` / `private __construct()` pattern; every
>    `add_action` / `add_filter` call MUST live in `includes/Main.php` (not
>    inside the class body).
> 2. `.specify/memory/CONSTITUTION.md` — canonical directory layout, the
>    Admin/Partials rule for classes that render HTML, the UI-contract
>    section that says `@wordpress/dataviews` for lists + `@wordpress/dataforms`
>    for forms (the wizard's server-picker and access-control step MUST use
>    those two packages; hand-rolling equivalents is forbidden).
> 3. `docs/quick-setup-design-brief.md` — the visual + copy contract. Every
>    screen, every conditional state (Step 3 A/B, Step 4 A/B, Step 5 A/B/C),
>    every hardcoded string in the mockup is spec'd here. Any implementation
>    choice not covered by this planning doc defaults to whatever the design
>    brief says. If the two disagree, the design brief wins on visuals + copy;
>    this doc wins on PHP contract + data-source wiring.
> 4. Existing patterns to mirror, not reinvent:
>    - `admin/Partials/ServerTabs/EmbedsTab.php` + `AbstractReactMountServerTab.php`
>      — the React-mount pattern (asset manifest, enqueue gate, permission gate,
>      bootstrap payload). Retired-but-retained after 0.2.10 hid the tab; the
>      class is still the canonical shape for how a React app boots inside the
>      WP admin.
>    - `admin/Partials/ServerTabs/AbilitiesTab.php` (F017) — the React-entry
>      shape and the "sibling plugin detection + promo nudge" pattern used
>      when `acrossai-abilities-manager` is missing.
>    - `admin/Partials/ServerTabs/AIConnectorsPromoTab.php` (F040) — the
>      three-state (`missing` / `inactive` / `active`) detection pattern that
>      Step 5's Connectors card mirrors for `acrossai-pro`.
>    - `admin/Partials/ServerTabs/AccessControlTab.php` (F015 + F042) — the
>      access-control React component whose form the wizard's Step 2 reuses.
>      Do NOT hand-roll a second AC form; mount the same React component.
>    - `public/Discovery/ConnectionMethodRegistry.php` (F035) — the DTO
>      source-of-truth for Step 5's client picker, npm command, AI connector
>      panels.
>    - `webpack.config.js` — copy the `js/embeds` entry shape verbatim for
>      the new `js/quick-setup` entry (mini-css-extract, asset manifest,
>      output filename convention).
>
> Every decision — React component tree, REST route shapes, transient TTLs,
> capability gates — must be justified against the above. If a choice is not
> explicitly covered, default to the closest sibling pattern. Do not write
> code that would fail any Definition-of-Done gate: PHPStan level 8, PHPCS
> WPCS-strict, security review, all `__()` calls using the correct text
> domain `'acrossai-mcp-manager'`, all forms carrying wp_nonce_field +
> capability check.
>
> **Public API artifacts to preserve verbatim** (grep-gate before + after —
> the wizard is strictly additive):
>
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query` (read + write)
> - `\AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control`
> - `\AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry`
> - Every ServerTab class under `admin/Partials/ServerTabs/` — the wizard
>   MUST NOT touch any of them, only READ their data sources.
> - Every REST controller under `includes/REST/` — the wizard's own controller
>   is net-new; the existing controllers stay unchanged.
> - All existing wp_options and DB tables — no schema migration in this feature.
>
> Pre-flight grep (should return the same result set post-implementation):
>
> ```bash
> grep -rEn "class .* extends AbstractServerTab" admin/Partials/ServerTabs/
> ```
>
> Post-implementation, this grep MUST show the same 11 built-in tabs (Overview,
> Npm, Clients, AIConnectorsPromo, WpCli, Tools, Abilities, AccessControl,
> UpdateServer, DangerZone, McpTracker) — no additions, no removals. The
> wizard is a new page render, not a new server tab.
>
> ---
>
> **TASK-1 — Plugin activation transient + admin bar entry + first-run banner**
>
> Files:
>
> - `acrossai-mcp-manager.php` (delta — extend `acrossai_mcp_manager_activate()`)
> - `admin/Partials/QuickSetup/AdminBarEntry.php` (NEW)
> - `admin/Partials/QuickSetup/FirstRunBanner.php` (NEW)
> - `includes/Main.php` (delta — wire the two new admin surfaces)
>
> Activation delta — inside `acrossai_mcp_manager_activate()` (currently in
> `acrossai-mcp-manager.php`), after the Activator call, add:
>
> ```php
> set_transient(
>     'acrossai_mcp_manager_quick_setup_prompt',
>     '1',
>     DAY_IN_SECONDS
> );
> ```
>
> This is the only new persistence added at activation time. Existing
> activation behavior (DB seeding, rewrite-rule flush, cron scheduling)
> is unchanged.
>
> `AdminBarEntry` singleton — implements the plugin's canonical singleton
> pattern (protected `$_instance`, public `instance()`, private
> `__construct()`). Exposes ONE public method:
>
> ```php
> public function register_node( \WP_Admin_Bar $wp_admin_bar ): void {
>     if ( ! current_user_can( 'manage_options' ) ) {
>         return;
>     }
>     $wp_admin_bar->add_node( array(
>         'id'    => 'acrossai-mcp-quick-setup',
>         'title' => '<span class="ab-icon dashicons dashicons-admin-tools" style="top:3px;"></span>'
>                  . esc_html__( 'MCP Quick Setup', 'acrossai-mcp-manager' ),
>         'href'  => admin_url( 'admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1' ),
>         'meta'  => array( 'title' => __( 'Guided 5-step MCP configuration', 'acrossai-mcp-manager' ) ),
>     ) );
> }
> ```
>
> Wired in `Main::define_admin_hooks()`:
>
> ```php
> $this->loader->add_action(
>     'admin_bar_menu',
>     \AcrossAI_MCP_Manager\Admin\Partials\QuickSetup\AdminBarEntry::instance(),
>     'register_node',
>     100
> );
> ```
>
> `FirstRunBanner` singleton — renders a dismissible `notice notice-info`
> ONLY on the plugin's own admin page (`current_screen()` check for the
> plugin's page slug) AND ONLY while the `acrossai_mcp_manager_quick_setup_prompt`
> transient exists AND ONLY when `?quick-setup=1` is NOT already present
> in the URL. Copy per design brief: headline "Get started in under a
> minute", body "Try the Quick Setup wizard for a guided 5-step MCP
> configuration.", primary CTA "Start setup" → the wizard URL, secondary
> "Dismiss" link that deletes the transient + reloads.
>
> Wire in `Main::define_admin_hooks()`:
>
> ```php
> $this->loader->add_action(
>     'admin_notices',
>     \AcrossAI_MCP_Manager\Admin\Partials\QuickSetup\FirstRunBanner::instance(),
>     'maybe_render'
> );
> $this->loader->add_action(
>     'admin_post_acrossai_mcp_quick_setup_dismiss',
>     \AcrossAI_MCP_Manager\Admin\Partials\QuickSetup\FirstRunBanner::instance(),
>     'handle_dismiss'
> );
> ```
>
> The dismiss handler MUST verify a fresh `wp_verify_nonce` (action
> `acrossai_mcp_quick_setup_dismiss`) + capability check + issue
> `wp_safe_redirect` back to the plugin page + `exit`.
>
> ---
>
> **TASK-2 — Route hijack + React mount page**
>
> Files:
>
> - `admin/Partials/QuickSetup/QuickSetupPage.php` (NEW)
> - `admin/Partials/Settings.php` OR `admin/Partials/Menu.php` (delta —
>   whichever file currently handles the `?page=acrossai_mcp_manager` render
>   dispatch; read the file first to find the existing entry point)
> - `admin/Main.php` (delta — enqueue quick-setup asset bundle gated on the
>   wizard URL)
>
> Read the existing `?page=acrossai_mcp_manager` render dispatcher BEFORE
> editing. The wizard hijack lands EARLY in that dispatcher — before any
> list-table or edit-page render logic runs:
>
> ```php
> if ( ! empty( $_GET['quick-setup'] ) && '1' === (string) $_GET['quick-setup'] ) {
>     if ( ! current_user_can( 'manage_options' ) ) {
>         wp_die( esc_html__( 'You do not have permission to run the Quick Setup wizard.', 'acrossai-mcp-manager' ) );
>     }
>     \AcrossAI_MCP_Manager\Admin\Partials\QuickSetup\QuickSetupPage::instance()->render();
>     return;
> }
> ```
>
> `QuickSetupPage` singleton — one public method `render()` that outputs the
> React mount div plus a `<noscript>` fallback:
>
> ```php
> public function render(): void {
>     echo '<div class="wrap acrossai-mcp-quick-setup-wrap">';
>     echo '<div id="acrossai-mcp-quick-setup-root"></div>';
>     echo '<noscript><p>' . esc_html__( 'The Quick Setup wizard requires JavaScript. Enable JavaScript in your browser to use it.', 'acrossai-mcp-manager' ) . '</p></noscript>';
>     echo '</div>';
> }
> ```
>
> Asset enqueue lives in `admin/Main::enqueue_scripts()` / `enqueue_styles()`
> per the plugin convention. Gate on the wizard URL:
>
> ```php
> if ( ! empty( $_GET['quick-setup'] ) && '1' === (string) $_GET['quick-setup'] ) {
>     $asset = require ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/js/quick-setup.asset.php';
>     wp_enqueue_script(
>         'acrossai-mcp-manager-quick-setup',
>         ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/quick-setup.js',
>         $asset['dependencies'],
>         $asset['version'],
>         array( 'in_footer' => true )
>     );
>     wp_set_script_translations( 'acrossai-mcp-manager-quick-setup', 'acrossai-mcp-manager' );
>     $css_path = ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/js/quick-setup.css';
>     if ( file_exists( $css_path ) ) {
>         wp_enqueue_style(
>             'acrossai-mcp-manager-quick-setup',
>             ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/quick-setup.css',
>             array(),
>             $asset['version']
>         );
>     }
>     wp_localize_script(
>         'acrossai-mcp-manager-quick-setup',
>         'acrossaiMcpQuickSetup',
>         array(
>             'restUrl'   => rest_url( 'acrossai-mcp-manager/v1/quick-setup' ),
>             'restNonce' => wp_create_nonce( 'wp_rest' ),
>             'adminUrl'  => admin_url( 'admin.php?page=acrossai_mcp_manager' ),
>             'logoUrl'   => ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'assets/quick-setup/acrossai-logo.svg',
>         )
>     );
> }
> ```
>
> Do NOT enqueue on any other admin page. The bundle is expected to run
> ~200KB (React + wp-components + wp-dataviews); it MUST NOT load on the
> list-table view.
>
> ---
>
> **TASK-3 — REST controller: /quick-setup/state, /quick-setup/step, /quick-setup/complete**
>
> Files:
>
> - `includes/REST/QuickSetupController.php` (NEW)
> - `includes/Main.php` (delta — register the controller)
>
> Singleton pattern per convention. `register_routes()` hooked on
> `rest_api_init`. Namespace `acrossai-mcp-manager/v1`, three routes:
>
> **GET `/quick-setup/state`** — returns the full snapshot the React app
> needs to render every step. Permission callback: `current_user_can( 'manage_options' )`.
> Response shape:
>
> ```json
> {
>   "servers": [ { "id": 1, "name": "Default MCP Server", "slug": "...", "route_full": "mcp/mcp-adapter-default-server", "enabled": true }, ... ],
>   "abilities": { "total": 3, "hasManagerPlugin": false },
>   "plugins": { "acrossaiPro": "missing", "abilitiesManager": "missing" },
>   "wizardState": { "server_id": null, "current_step": 1, "method": null }
> }
> ```
>
> Server list source: `MCPServerQuery::instance()->query( [ 'orderby' => 'id', 'order' => 'ASC' ] )`.
> Abilities count source: `count( wp_get_abilities() )`.
> Plugin state source: `is_plugin_active()` — MUST include `require_once ABSPATH . 'wp-admin/includes/plugin.php'` if the helper isn't loaded (see `AIConnectorsPromoTab::ensure_plugin_helpers_loaded()` for the canonical guard).
> Wizard state source: `get_transient( 'acrossai_mcp_manager_quick_setup_state_' . get_current_user_id() )` (default to `[ 'current_step' => 1 ]` if absent).
>
> **POST `/quick-setup/step`** — persists per-step scratch-pad state. Request
> body:
>
> ```json
> {
>   "step": 1|2|3|4|5,
>   "data": { ...step-specific... }
> }
> ```
>
> Behavior per step:
>
> - Step 1: `data.server_id` (int) OR `data.new_server` (create payload). Create
>   flow → `MCPServerQuery::add_item()`, return the new `server_id`. Store
>   `server_id` in the per-user transient.
> - Step 2: `data.access_rule` (wpb-ac shape). Delegate to the F015
>   AccessControlManager's public setter for the current server. Do NOT
>   reimplement the rule schema — the AC React component already emits the
>   correct shape.
> - Step 3: `data.enable_all_abilities` (bool) OR no-op. If true and Abilities
>   Manager is active, POST to the F017 controller's abilities-update route
>   with the full ability set for the current server.
> - Step 4: `data.enabled` (bool). `MCPServerQuery::update_item( $server_id, [ 'is_enabled' => (int) $data['enabled'] ] )`.
> - Step 5: `data.method` (string). Persist to the per-user transient so
>   the completion screen can display it — no other side effect (the actual
>   connection setup happens client-side via copy-to-clipboard).
>
> Every step response returns the updated wizard state and MAY return updated
> lookup data (e.g., Step 1 create response includes the refreshed server list).
>
> **POST `/quick-setup/complete`** — deletes the first-run banner transient +
> deletes the per-user state transient + returns 204. React app redirects to
> the server-edit page on success.
>
> All three routes MUST use `permission_callback` returning boolean (not
> capability strings — the WP REST layer only accepts closures/callbacks here
> per SEC review of prior features).
>
> Wire in `Main::define_public_hooks()`:
>
> ```php
> $quick_setup_rest = \AcrossAI_MCP_Manager\Includes\REST\QuickSetupController::instance();
> $this->loader->add_action( 'rest_api_init', $quick_setup_rest, 'register_routes' );
> ```
>
> ---
>
> **TASK-4 — React entry + router + state store**
>
> Files:
>
> - `src/js/quick-setup.js` (NEW — entry point)
> - `src/js/quick-setup/App.jsx` (NEW)
> - `src/js/quick-setup/StepLayout.jsx` (NEW)
> - `src/js/quick-setup/hooks/useWizardRouter.js` (NEW)
> - `src/js/quick-setup/hooks/useWizardState.js` (NEW)
> - `src/js/quick-setup/components/CodeBlock.jsx` (NEW)
> - `src/js/quick-setup/components/Notice.jsx` (NEW)
> - `src/js/quick-setup/components/RadioCard.jsx` (NEW)
> - `src/js/quick-setup/components/icons.jsx` (NEW)
> - `webpack.config.js` (delta — add `js/quick-setup` entry)
> - `package.json` (delta — verify all needed `@wordpress/*` packages are
>   already dependencies; add any missing ones)
>
> Read `src/js/embeds.js` first for the entry-file shape. New entry mounts
> `<App />` at `#acrossai-mcp-quick-setup-root` using `@wordpress/element`
> `createRoot`. Bootstrap payload is read from `window.acrossaiMcpQuickSetup`
> (localized by TASK-2).
>
> `App.jsx` — top-level component. Uses `useWizardRouter` to derive `step`
> and `method` from URL; uses `useWizardState` to fetch + persist state.
> Renders `<StepLayout>` wrapping the current step's component. On mount,
> issues GET `/quick-setup/state` to hydrate the store.
>
> `StepLayout.jsx` — chrome shared by every step: header (logo + "Quick Setup"
> title + "Exit setup" link on the right), 4px progress bar (fill % = step /
> total_steps × 100), content pane with a max-width container per the design,
> footer with Back + Continue buttons (Back disabled on step 1; Continue
> label changes to "Finish" on step 5 and to "Restart" on the completion
> screen).
>
> `useWizardRouter` — custom hook returning
> `{ step, method, goTo(step, method), advance(), back(), exit() }`. Reads
> from URL via `@wordpress/url` `getQueryArg`, writes via `history.pushState`
> + `addQueryArgs`. Registers a `popstate` listener that syncs state on
> browser Back/Forward. Emits step guards (advance is blocked when the
> current step's `canAdvance()` returns false — each step passes its own
> guard via context or prop).
>
> `useWizardState` — custom hook wrapping the REST calls. Uses
> `@wordpress/data` for shared state or (simpler) `useReducer` with a context
> provider. Exposes `{ state, isLoading, error, refetch, saveStep(step, data) }`.
> `saveStep` PATCHes to `/quick-setup/step` and updates local state
> optimistically; failure rolls back + surfaces an error notice.
>
> `CodeBlock.jsx` — reusable code display with a "Copy" button in the
> top-right. Uses `@wordpress/compose` `useCopyToClipboard`. Supports two
> variants: `variant="inline"` (single-line, monospace, gray background)
> and `variant="pane"` (multi-line, dark background with a title strip).
>
> `Notice.jsx` — reusable inline notice with left color bar. Props:
> `{ status: 'info'|'warning'|'success'|'error', children }`. Matches the
> design's left-bar-color pattern (#3858e9 info, #f0b849 warning, #4ab866
> success, #cc1818 error).
>
> `RadioCard.jsx` — reusable server row / method card with a custom radio
> circle on the left. Props: `{ selected, onSelect, title, subtitle, badge?, children? }`.
>
> `icons.jsx` — inline SVG icons extracted from the design (link/chain,
> puzzle piece, terminal, checkmark, chevron-down, chevron-right,
> external-link-arrow). No external icon library.
>
> Webpack entry (add to `webpack.config.js` alongside `js/embeds`):
>
> ```js
> 'js/quick-setup': path.resolve( process.cwd(), 'src/js', 'quick-setup.js' ),
> ```
>
> ---
>
> **TASK-5 — Step 1 (server picker + inline create form)**
>
> Files:
>
> - `src/js/quick-setup/steps/Step1_ServerPick.jsx` (NEW)
> - `src/js/quick-setup/steps/Step1_ServerCreate.jsx` (NEW)
>
> `Step1_ServerPick` — renders the section header ("Choose a server"), a
> `@wordpress/dataviews` list OR a hand-rolled `RadioCard` list of the
> `state.servers` array. Each row shows Name + `<code>` route + Inactive
> badge if `!enabled`. Below the list, a `+ Create a new server` text
> button that flips local component state to render `<Step1_ServerCreate />`
> inline (not a modal, not a new URL — a compact slide-over inside the
> content pane per the design brief).
>
> `Step1_ServerCreate` — inline form with fields: Name (required), Slug
> (auto-derived from Name via `sanitize_title` client-side helper, but
> editable), Description (textarea), Route Namespace (default `mcp`), Route
> (auto-derived from Slug, editable), Version (default `v1.0.0`). Two
> buttons: "Create Server" (primary — POSTs to `/quick-setup/step` with
> `data.new_server`), "Cancel" (returns to picker).
>
> Advance guard: `canAdvance = !!wizardState.server_id`. Picker: selecting a
> row sets `wizardState.server_id`. Create: on success, `server_id` is
> populated and the picker re-renders with the new row selected.
>
> ---
>
> **TASK-6 — Step 2 (access control)**
>
> Files:
>
> - `src/js/quick-setup/steps/Step2_AccessControl.jsx` (NEW)
>
> Read `src/js/access-control.js` first. If the AC React component is
> exported / re-importable, mount it inside Step 2 with the wizard's
> `server_id` scoped in. If not exported (currently mounts to its own
> tab's DOM), extract a `<AccessControlEditor server_id={id} onSave={fn} />`
> shape into a shared module and import from BOTH the tab and the wizard —
> DO NOT copy-paste the form.
>
> Above the AC editor, render a `<Notice status="info">` with the exact
> F042 admin-only banner text (copy-paste from `AccessControlTab.php` — do
> not paraphrase — the copy is authoritative in that file).
>
> Below the editor, footnote: "You can change this anytime under the
> server's Access Control tab."
>
> Advance guard: none (AC is optional per F042 — leaving it as "no rule"
> keeps the server admin-only, which is a valid final state).
>
> ---
>
> **TASK-7 — Step 3 (abilities)**
>
> Files:
>
> - `src/js/quick-setup/steps/Step3_Abilities.jsx` (NEW)
>
> Reads `state.abilities.total` and `state.plugins.abilitiesManager`. Renders
> ONE of two variants:
>
> - Variant A (`abilitiesManager !== 'active'`): giant "3" number, subhead
>   "WordPress ships with only 3 by default.", list of the three core
>   abilities, then a purple-bordered promo card matching the design brief
>   (headline "Unlock 300+ abilities with AcrossAI Abilities Manager",
>   body copy per the design brief, primary CTA "Install from WordPress.org"
>   linking to `https://wordpress.org/plugins/acrossai-abilities-manager/`,
>   secondary "View case studies →" linking to `https://acrossai.co/use-cases/`),
>   admin-only info footnote.
> - Variant B (`abilitiesManager === 'active'`): giant `state.abilities.total`
>   number, subhead "From AcrossAI Abilities Manager.", two side-by-side
>   buttons — "Enable all abilities for this server" (primary, POSTs to
>   the F017 abilities controller) + "Configure abilities one-by-one" (opens
>   `?tab=abilities&server=<id>` in a NEW browser tab, `target="_blank"`
>   with `rel="noopener"`).
>
> Advance guard: none (enabling abilities is optional).
>
> ---
>
> **TASK-8 — Step 4 (enable server + auto-skip)**
>
> Files:
>
> - `src/js/quick-setup/steps/Step4_EnableServer.jsx` (NEW)
> - `src/js/quick-setup/App.jsx` (delta — auto-skip logic in the router)
>
> If the selected server has `enabled === true`, `App.jsx` MUST auto-advance
> past step 4 on entry (both when landing via URL and when advancing from
> step 3). The stepper progress bar recomputes total steps to 4 instead of 5,
> so the bar reaches 100% on step 5 instead of step 4.
>
> If `enabled === false`, `Step4_EnableServer` renders the design's yellow
> notice + toggle switch. Toggle-on POSTs to `/quick-setup/step` with
> `data.enabled = true` and updates local server list state. Advance guard:
> `canAdvance = wizardState.enabled === true` (Continue disabled until the
> toggle flips on).
>
> ---
>
> **TASK-9 — Step 5 (method grid + 4 expanded panels)**
>
> Files:
>
> - `src/js/quick-setup/steps/Step5_MethodGrid.jsx` (NEW)
> - `src/js/quick-setup/steps/Step5_ConnectorsPanel.jsx` (NEW)
> - `src/js/quick-setup/steps/Step5_ClientPanel.jsx` (NEW)
> - `src/js/quick-setup/steps/Step5_NpmPanel.jsx` (NEW)
> - `src/js/quick-setup/steps/Step5_WpCliPanel.jsx` (NEW)
>
> `Step5_MethodGrid` — 2×2 grid of `RadioCard`s. Data source: static list of
> four method definitions (title, description, icon, badge, sub-state
> resolver). The Connectors card's sub-state derived from
> `state.plugins.acrossaiPro`:
>
> - `missing` → "Get AcrossAI Pro →" link to `https://acrossai.co/pricing/#pricing`.
>   Below the grid, render a purple-bordered trial trust line with exact copy:
>   "Start on Personal with a 30-day free trial on 1 site. No card charged
>   today, cancel any time before it ends. Try it risk-free for 14 days."
> - `inactive` → yellow inline notice "AcrossAI Pro is installed but not
>   active." + "Activate AcrossAI Pro" primary button (POSTs to
>   `plugins.php` activate URL via `wp_nonce_url` — server-side, use
>   `wp_safe_redirect` for the callback).
> - `active` → cards are radio-selectable; picking Connectors expands into
>   `Step5_ConnectorsPanel`.
>
> `Step5_ConnectorsPanel` — 4 provider tabs (ChatGPT / Claude / Gemini / Grok).
> Each tab shows the plugin's canonical MCP URL for the selected server
> (`site_url() . '/wp-json/' . $server->server_route_namespace . '/' . $server->server_route`)
> with a `<CodeBlock variant="inline">` + Copy button and the copy: "This
> connector supports Dynamic Client Registration only — paste the MCP URL
> above into your AI client and it will register itself. No manual
> credentials to generate."
>
> `Step5_ClientPanel` — pill-row of the F035 `get_clients()` DTOs (Claude
> Desktop, Claude Code, VS Code, GitHub Copilot, Cursor, Codex, Gemini CLI,
> Custom Client — 8 items, matching the design brief). Each pill has an
> emoji icon prefix (from the DTO's `icon` field) and a name. Selecting a
> pill reveals a `<CodeBlock variant="pane">` with the JSON config for that
> client. Above the code block, a `<Notice status="info">` with the
> Application Password copy from the design brief.
>
> `Step5_NpmPanel` — one `<CodeBlock variant="inline">` with the shape
> `npx -y @acrossai/mcp-manager --siteurl=<siteurl> --server=<slug>`, then
> two metadata rows (Site URL, Server) with `<code>` chips, then the muted
> helper text about OS keychain storage.
>
> `Step5_WpCliPanel` — three `<CodeBlock variant="inline">` blocks:
> `wp mcp-adapter list`, `wp mcp-adapter serve --server=<slug> --user=admin`,
> and (per the design) a comparison note about STDIO vs HTTP transport.
>
> Advance guard: `canAdvance = !!wizardState.method` (must pick a card).
> Continue on Step 5 fires POST `/quick-setup/complete` and navigates to
> `?step=done`.
>
> ---
>
> **TASK-10 — Completion screen**
>
> Files:
>
> - `src/js/quick-setup/steps/Completion.jsx` (NEW)
>
> Green checkmark circle + summary table (Server, Access, Abilities count,
> Connected via). Three CTAs: "Go to server dashboard" (primary → server-edit
> page for the wizard's `server_id`), "Set up another server" (resets
> transient + navigates to `?step=1`), "Dismiss" (text link → server list).
> Tiny footer: "You can re-run this wizard anytime from the top admin bar."
>
> No advance guard; Continue button is hidden on this screen.
>
> ---
>
> **TASK-11 — SCSS + design tokens + asset copy**
>
> Files:
>
> - `src/scss/quick-setup.scss` (NEW — imported from `src/js/quick-setup.js`
>   so mini-css-extract emits `build/js/quick-setup.css`)
> - `assets/quick-setup/acrossai-logo.svg` (NEW — copy from Claude Design
>   project 135ba973-6a42-48b5-9d50-8f79ddaaaa7f)
>
> Design tokens as SCSS variables at the top of `quick-setup.scss`:
>
> ```scss
> $qs-primary:       #3858e9;
> $qs-brand-purple:  #4f46e5;
> $qs-success:       #4ab866;
> $qs-warning:       #f0b849;
> $qs-danger:        #cc1818;
> $qs-text:          #1e1e1e;
> $qs-text-muted:    #757575;
> $qs-border:        #ddd;
> $qs-border-strong: #949494;
> $qs-bg-blue-tint:  #f0f4ff;
> $qs-bg-purple-tint:#f5f3ff;
> $qs-bg-warning:    #fcf9e8;
> $qs-bg-success:    #edfaef;
> $qs-bg-code:       #f6f7f7;
> $qs-bg-code-dark:  #23282d;
> $qs-radius:        2px;
> $qs-space-unit:    8px;
> $qs-font-mono:     'JetBrains Mono', monospace;
> $qs-font-body:     -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto,
>                    'Helvetica Neue', sans-serif;
> ```
>
> All wizard styles scoped under `.acrossai-mcp-quick-setup-wrap` so they
> can never leak into the list-table or per-server-edit pages. Class names
> follow BEM-lite: `.qs__header`, `.qs__progress`, `.qs__content`,
> `.qs__footer`, `.qs-card`, `.qs-card--radio`, `.qs-code`, `.qs-code--pane`,
> etc.
>
> Do NOT `@import` the wizard styles into `src/scss/backend.scss` — the
> wizard bundle owns its own CSS via mini-css-extract, and gating the
> enqueue on `?quick-setup=1` keeps the wizard's ~30KB of CSS off every
> other admin page.
>
> Asset copy: download the logo SVG from the Claude Design project (path
> `acrossai-logo.svg`) into `assets/quick-setup/acrossai-logo.svg`. Verify
> the SVG's `viewBox` is `0 0 100 100` (per the design source) so the
> React `<img>` sizing works without additional CSS scaling.
>
> ---
>
> **TASK-12 — Version bump + changelog + memory hygiene**
>
> Files:
>
> - `acrossai-mcp-manager.php` (delta — Version header)
> - `includes/Main.php` (delta — `ACROSSAI_MCP_MANAGER_VERSION` constant)
> - `README.txt` (delta — Stable tag + new `= 0.2.11 =` changelog section)
> - `docs/memory/DECISIONS.md` (delta — new DEC entries)
> - `docs/memory/WORKLOG.md` (delta — Feature 069 milestone)
> - `docs/memory/INDEX.md` (delta — Active decision rows + WORKLOG row)
> - `docs/planings-tasks/README.md` (delta — index row for `069-mcp-quick-setup-wizard.md`)
>
> Version bump 0.2.10 → 0.2.11 in all three files (plugin header, constant,
> Stable tag). Changelog entry outline:
>
> ```
> = 0.2.11 =
> * **Admin — New MCP Quick Setup wizard (Feature 069).** A guided 5-step
>   flow that condenses the per-server-edit page's 11 tabs into one linear
>   configuration path. Reached from the new top admin bar chip
>   ('MCP Quick Setup', wrench dashicon, `manage_options`-gated) or from
>   the dismissible first-run banner on the plugin page. URL:
>   `/wp-admin/admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1`.
>   Every step's answer persists to a per-user 30-minute transient so
>   reload restores position; authoritative writes flow through existing
>   surfaces (MCPServerQuery, wpb-access-control, F017 abilities
>   controller) — no new DB tables. Conditional branching per install
>   state: Step 4 auto-skips when the server is already enabled; Step 3
>   dual-state on Abilities Manager plugin activation; Step 5 Connectors
>   card tri-state on AcrossAI Pro plugin activation. Bundle
>   `build/js/quick-setup.{js,css}` (~200KB JS + ~30KB CSS) enqueues
>   ONLY when `?quick-setup=1` is present, so the list-table view is
>   unaffected. Fully additive — no existing tab, REST route, or DB
>   schema changed.
> * **Internal: `ACROSSAI_MCP_MANAGER_VERSION` constant + `Stable tag`
>   bumped to `0.2.11` matching the plugin header.**
> ```
>
> DECISIONS.md — capture as Active:
>
> - `DEC-WIZARD-URL-DRIVEN-STEP-STATE (Active — Feature 069)`: Step index
>   lives in the URL as `?step=N`, not in internal component state, so
>   browser Back/Forward works, deep links work, and reload restores
>   position without server round-trips for navigation. Advance via
>   `history.pushState`; sync via `popstate` listener.
> - `DEC-WIZARD-ADDITIVE-NO-EXISTING-CHANGES (Active — Feature 069)`:
>   The wizard is strictly additive. It reads from existing sources
>   (`MCPServerQuery`, `wp_get_abilities()`, `AccessControlManager`,
>   `ConnectionMethodRegistry`) and writes through existing sources; it
>   does not modify any existing tab, REST route, DB schema, or wp_option.
>   Grep-gate: post-implementation, `git diff` shows zero non-additive
>   deletions in any file under `admin/Partials/ServerTabs/`,
>   `includes/REST/` (except the new `QuickSetupController.php`), or
>   `includes/Database/`.
>
> WORKLOG.md — Feature 069 milestone entry (Why durable / Future mistake
> prevented / Evidence / Where to look). Durable lesson: **when adding
> a new admin experience that overlaps existing surfaces, the strictly-
> additive approach (reads/writes through existing APIs) is preferable
> to fork-and-modify; the wizard is a proof-of-pattern for future
> multi-tab-consolidation features**.
>
> INDEX.md — append Active-Decisions rows for the two new DEC entries;
> append a WORKLOG row for Feature 069.
>
> `docs/planings-tasks/README.md` — append index row for
> `069-mcp-quick-setup-wizard.md`.
>
> ---
>
> **CONSTRAINTS**
>
> - **Do not touch any existing per-server-edit tab.** All 11 tabs under
>   `admin/Partials/ServerTabs/` stay byte-identical post-feature.
> - **Do not touch any existing REST controller.** All controllers under
>   `includes/REST/` (except the net-new `QuickSetupController.php`) stay
>   byte-identical.
> - **Do not touch any existing DB schema, wp_option, or seeded row.**
>   The only new persistence is two transients + implicit new rows in
>   existing tables (MCP servers, wpb-ac rules) via existing APIs.
> - **Do not reimplement forms** that already have React components
>   (Access Control React app, Abilities picker). Import + reuse.
> - **Do not hand-roll dataviews/dataforms** for the server picker or the
>   access-control form. Constitution §UI-Contract mandates
>   `@wordpress/dataviews` for lists + `@wordpress/dataforms` for forms.
> - **Do not enqueue the wizard bundle outside `?quick-setup=1`.** The
>   ~200KB bundle MUST NOT hit the list-table view.
> - **Do not embed the WordPress logo, Claude logo, ChatGPT logo, Gemini
>   logo, or Grok logo** as PNG/SVG assets in this plugin — reference-by-
>   name only. The only asset shipped is the AcrossAI logo SVG.
> - **Do not add a new capability.** All access gates use `manage_options`,
>   matching every existing admin surface in this plugin.
> - **Do not seed any DB rows on activation** beyond the existing default
>   server row. The first-run transient is the only new activation-time
>   persistence.
> - **Every task must leave PHPStan level 8 + PHPCS individually green
>   before moving to the next.** Constitution §VII per-task gating applies.
> - **Every REST route MUST have a permission callback that returns bool.**
>   Every state-mutating route MUST verify a fresh nonce.
> - **Every `__()` / `_e()` / `esc_html__()` call MUST use the text domain
>   `'acrossai-mcp-manager'`.** No fallback to `'default'`.
> - **Every user-provided string in server-create MUST be sanitized before
>   `MCPServerQuery::add_item()` runs.** Reuse the plugin's existing
>   sanitizers (see the plugin's existing server-create form for the
>   canonical shape).

```markdown
# 3. Plan + guard + security
/speckit.memory-md.plan-with-memory
/speckit.architecture-guard.governed-plan
/speckit.security-review.plan

# 4. Tasks + guard
/speckit.tasks
/speckit.architecture-guard.governed-tasks

# 5. Implement + quality checks
/speckit.architecture-guard.governed-implement
composer dump-autoload
composer run phpcs
composer run phpstan

# 6. Review + memory + commit
/speckit.analyze
/speckit.architecture-guard.architecture-review
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

---

## Manual Verification Checklist

### TASK-1 — Activation transient + admin bar entry + first-run banner
- [ ] Fresh activation sets the `acrossai_mcp_manager_quick_setup_prompt`
      transient with a 24h TTL (`wp option get _transient_timeout_acrossai_mcp_manager_quick_setup_prompt`).
- [ ] Admin bar shows the "MCP Quick Setup" chip for a `manage_options` user;
      absent for an Editor user.
- [ ] First-run banner appears on the plugin page while the transient is
      live; absent on other admin pages; absent on the plugin page when
      `?quick-setup=1` is already in the URL.
- [ ] Dismiss link deletes the transient + redirects back to the plugin
      page (no more banner after reload).
- [ ] Nonce failure on the dismiss endpoint returns `wp_die`.

### TASK-2 — Route hijack + React mount
- [ ] Navigating to `?page=acrossai_mcp_manager&quick-setup=1&step=1`
      renders the React mount div; list table is NOT rendered.
- [ ] The wizard bundle enqueues on that URL and NOT on
      `?page=acrossai_mcp_manager` alone.
- [ ] View-source shows the `<div id="acrossai-mcp-quick-setup-root">`
      + the `<noscript>` fallback.
- [ ] `window.acrossaiMcpQuickSetup` in the browser console shows the
      four expected keys (`restUrl`, `restNonce`, `adminUrl`, `logoUrl`).
- [ ] A logged-out user hitting the URL is redirected to wp-login.
- [ ] An Editor user hitting the URL sees the "You do not have permission"
      wp_die screen.

### TASK-3 — REST controller
- [ ] `GET /wp-json/acrossai-mcp-manager/v1/quick-setup/state` as admin
      returns the four expected top-level keys (`servers`, `abilities`,
      `plugins`, `wizardState`).
- [ ] Same GET as a Subscriber returns 401.
- [ ] POST `/quick-setup/step` with `{ step: 1, data: { server_id: 1 } }`
      persists to the per-user transient; a re-fetch of `/state` shows
      the updated `wizardState.server_id`.
- [ ] POST `/quick-setup/step` with `{ step: 4, data: { enabled: true } }`
      flips the server's `is_enabled` in the DB (verify with
      `MCPServerQuery::instance()->query( [ 'id' => X ] )`).
- [ ] POST `/quick-setup/complete` deletes both transients.

### TASK-4 — React entry + shell
- [ ] Wizard renders the header + progress bar + footer without console
      errors on first load.
- [ ] Back button on step 1 is disabled; Continue advances URL to
      `?step=2` via `pushState` (no full page reload).
- [ ] Browser Back button returns to step 1.
- [ ] Reload on step 3 renders step 3 (URL is source of truth).

### TASK-5 — Step 1
- [ ] Server list renders all rows from `state.servers`.
- [ ] Selecting a row enables Continue.
- [ ] "+ Create a new server" reveals the inline form; Cancel returns
      to the picker.
- [ ] Submitting the create form POSTs successfully; new row appears in
      the picker; the new server is auto-selected.

### TASK-6 — Step 2
- [ ] Info banner text matches `AccessControlTab.php`'s exact copy
      (no paraphrase).
- [ ] AC React component renders the current rule for `state.wizardState.server_id`.
- [ ] Saving a rule (Editor role) via the AC editor persists to the
      wpb-ac table (verify via `RuleQuery::get_rule`).

### TASK-7 — Step 3
- [ ] Variant A (Abilities Manager inactive) shows "3 abilities available"
      + the three core-ability list + the promo card.
- [ ] Variant B (Abilities Manager active) shows the real total count +
      the two action buttons.
- [ ] "Configure abilities one-by-one" opens `?tab=abilities&server=<id>`
      in a new browser tab.
- [ ] "Enable all abilities" POSTs to the F017 abilities controller.

### TASK-8 — Step 4
- [ ] Server that's already enabled: Step 4 is auto-skipped; progress bar
      shows 4 total steps; Back from Step 5 lands on Step 3.
- [ ] Server that's disabled: Step 4 renders with Continue disabled;
      flipping the toggle enables Continue + persists `is_enabled = 1`.

### TASK-9 — Step 5
- [ ] Grid renders four cards.
- [ ] AcrossAI Pro missing: Connectors card shows "Get AcrossAI Pro" link;
      trial trust line renders below the grid.
- [ ] AcrossAI Pro inactive: yellow notice on the Connectors card +
      "Activate AcrossAI Pro" button.
- [ ] AcrossAI Pro active: cards become radio-selectable; picking
      Connectors expands into the 4-provider panel.
- [ ] Connectors panel MCP URL is `site_url() + /wp-json/ + namespace + / + route`
      for the current server.
- [ ] Client panel shows exactly 8 client pills; picking one renders
      the corresponding JSON config.
- [ ] npm panel shows the correct `npx -y @acrossai/mcp-manager` command
      with the current server's slug + site URL.
- [ ] WP-CLI panel shows three commands with the current server's slug.
- [ ] Every Copy button copies to clipboard.

### TASK-10 — Completion
- [ ] Completion screen shows the four summary rows (Server, Access,
      Abilities, Connected via) with the current wizard's values.
- [ ] "Go to server dashboard" navigates to the server-edit page for
      the wizard's `server_id`.
- [ ] "Set up another server" clears state + returns to Step 1.

### TASK-11 — SCSS + assets
- [ ] `build/js/quick-setup.css` exists after `npm run build`.
- [ ] `assets/quick-setup/acrossai-logo.svg` exists and renders in the
      wizard header.
- [ ] No wizard styles leak into the list-table view (open the plugin
      page WITHOUT `?quick-setup=1`, DevTools: no `.qs-*` classes
      applied to any element).

### TASK-12 — Version bump + memory hygiene
- [ ] `grep '0.2.11' acrossai-mcp-manager.php includes/Main.php README.txt`
      returns exactly three matches (Version header, constant, Stable tag).
- [ ] README changelog contains the `= 0.2.11 =` section per the outline
      above.
- [ ] `docs/memory/DECISIONS.md` contains DEC-WIZARD-URL-DRIVEN-STEP-STATE
      and DEC-WIZARD-ADDITIVE-NO-EXISTING-CHANGES as Active.
- [ ] `docs/memory/WORKLOG.md` contains a Feature 069 milestone entry.
- [ ] `docs/memory/INDEX.md` lists both new DECISIONs + the WORKLOG row.
- [ ] `docs/planings-tasks/README.md` lists `069-mcp-quick-setup-wizard.md`.

### Final full-repo audit (blocker before merge)

```bash
# Additive-only invariant: no non-additive deletions in existing tabs / REST / DB
git diff main..HEAD --stat -- \
  admin/Partials/ServerTabs/ \
  includes/REST/ \
  includes/Database/ \
| grep -Ev '^[[:space:]]+create mode' \
| grep -Ev '^[[:space:]]+admin/Partials/ServerTabs/EmbedsTab.php' \
| grep -E '^\s*[^|]+\|\s+[0-9]+\s+[+-]+' \
| grep -Ev '\+\+\+\s+$'
```
- [ ] Grep returns **zero non-additive edits** under those three paths
      (except intentional docblock touch-ups explicitly called out in
      TASK descriptions above, if any).

```bash
# Bundle-gating invariant: quick-setup.js MUST NOT enqueue outside ?quick-setup=1
grep -rEn 'acrossai-mcp-manager-quick-setup' admin/ includes/
```
- [ ] Every hit sits inside a `! empty( $_GET['quick-setup'] )` conditional.

### Quality gates (all must be green before commit)
- [ ] PHPStan level 8 — zero errors.
- [ ] PHPCS — zero errors on all new files.
- [ ] `composer test` — PHPUnit all remaining tests pass; new tests for
      QuickSetupController pass.
- [ ] `npm run build` — succeeds; `build/js/quick-setup.{js,css,asset.php}`
      all present.
- [ ] Manual smoke: complete the wizard end-to-end on a fresh install
      (no servers, Abilities Manager missing, AcrossAI Pro missing) —
      reach the completion screen with no console errors.
- [ ] Manual smoke: complete the wizard on an install with 1 already-
      enabled server + Abilities Manager active + AcrossAI Pro active —
      confirm Step 4 auto-skips and Step 5 Connectors expansion works.
- [ ] Manual smoke: reload the browser mid-wizard on step 3 — position
      restores from URL.
- [ ] Manual smoke: use browser Back / Forward across all 5 steps — URL
      + rendered step stay in sync via `popstate` listener.
