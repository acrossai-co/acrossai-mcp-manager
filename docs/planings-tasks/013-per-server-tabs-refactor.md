# Planning: Port per-server-edit tabs to a common per-tab class hierarchy (Feature 013)

Refactor the per-server-edit page (`?page=acrossai_mcp_manager&action=edit&server={id}&tab={slug}`) so
tab rendering lives in a per-tab class hierarchy under `admin/Partials/ServerTabs/` instead of
the current monolithic `render_*_tab` methods on `admin/Partials/Settings.php`. Introduce
`AbstractServerTab` (template method + shared render helpers) and `Registry` (singleton dispatch +
ordered tab list + `visible_for` filtering). Refactor the existing 4 tabs (General, Tokens, Access
Control, Claude Connector — ~106 LOC total) into the new shape as a zero-UI-change shape validation
pass, then port the missing **7 tabs** from the reference plugin (Overview replacing General, npm, MCP
Clients, WP-CLI, Tools, Abilities, MCP Tracker) + **2 database-registered-only tabs** (Update Server,
Danger Zone). The target UI matches the screenshots the operator provided.

The port is **almost pure render-layer work**: every support layer already exists in this plugin
post-Feature-011/012 — MCP Server / OAuth Tokens / OAuth Audit / CliAuthLog DB (F011); 8 MCP Client
classes (F004); MCP Controller (F009); REST CLI (F006); OAuth flow + ClaudeConnectors + FrontendAuth +
ApplicationPasswords + SettingsMenu (F005–F007, F012). No new DB, no new REST route, no new WP-CLI
command. Only **2 `WP_List_Table` subclasses** must be ported from the reference plugin's
`src/Admin/` directory (which uses the legacy `ACROSSAI_MCP_MANAGER\` uppercase namespace): the port
adopts the PascalCase namespace and consumes the target's existing DB Query classes.

Feature 012 must not regress: MCP settings tab at `?page=acrossai-settings&tab=mcp` still renders and
saves; the uninstall opt-in gate still holds; no legacy `CLI_AUTH_LOG` constant or
`acrossai_mcp_manager_cli_auth_log` slug reappears anywhere. Bringing back `CliAuthLogListTable`
under a per-server npm tab is explicitly permitted by **DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG** —
that decision pruned the standalone admin submenu while blessing per-server-tab inspection as the
canonical replacement path. The AbstractServerTab base class shape echoes F012's
DEC-VENDOR-SETTINGS-TAB-INTEGRATION singleton/template-method pattern.

**Cross-context reuse (new for F013).** The three client-configuration tabs — **npm**, **MCP Clients**,
and **Claude Connector** — MUST be renderable from arbitrary third-party contexts (BuddyBoss member
profile, WooCommerce My Account page, another AcrossAI-family plugin's admin surface, or an operator's
custom shortcode) with **zero code duplication** vs. the admin per-server-edit rendering. To make that
possible, F013 extracts the render bodies of those three tabs into a new public **Renderer layer**
under `public/Renderers/` (`AbstractClientRenderer` + `NpmClientBlock` + `MCPClientsBlock` +
`ClaudeConnectorBlock`) with a public API surface (static `render()` method + `do_action()` hook +
context-filter). The admin tab classes (`NpmTab`, `ClientsTab`, `ClaudeConnectorTab`) become **thin
delegates** — their `render_body()` calls into the corresponding Renderer with an `[ 'context' =>
'admin' ]` array. External plugins call the same Renderer with their own context slug
(`'buddyboss-profile'`, `'woocommerce-my-account'`, etc.) and get identical output routed to their
own submit target + capability check + nonce. The 8 non-client tabs (Overview, WP-CLI, Tools,
Abilities, Access Control, MCP Tracker, Update Server, Danger Zone) stay admin-only — no Renderer
extraction needed.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "per-server-tabs-refactor"

# 2. Specify
/speckit.specify "Refactor the per-server-edit page (?page=acrossai_mcp_manager&action=edit) so tab renders live in a per-tab class hierarchy under admin/Partials/ServerTabs/ instead of monolithic render_*_tab methods on admin/Partials/Settings.php. Introduce AbstractServerTab (template method: slug/label/visible_for/render_body + shared helpers open_form/close_form/nonce_field/json_config_block/passwords_notice/server_edit_url/client_label_pair) and Registry (singleton dispatch + ordered tab list + visible_for filtering). Refactor the existing 4 tabs (General→OverviewTab, TokensTab delegating to ApplicationPasswords::render_for_server, AccessControlTab preserving the class_exists vendor guard per D8, ClaudeConnectorTab) into the same shape and delete the 4 old render_*_tab methods from Settings.php. Port 7 new tabs from the reference plugin (Overview enriched, NpmTab, ClientsTab, WpCliTab, ToolsTab, AbilitiesTab, McpTrackerTab) plus 2 database-registered-only tabs (UpdateServerTab, DangerZoneTab) via visible_for() returning ('database' === registered_from). Port 2 WP_List_Table classes from the reference plugin adopting the PascalCase namespace: admin/Partials/CliAuthLogListTable.php (consumed by NpmTab — reintroduction as per-server-tab inspector is permitted by DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG) and admin/Partials/ConnectorAuditLogListTable.php (consumed by ClaudeConnectorTab). Reuse existing support layers verbatim (do not re-implement): MCPServer/OAuthToken/OAuthAudit/CliAuthLog DB Query classes, 8 MCPClients concrete classes, MCP Controller, OAuth flow, FrontendAuth, ApplicationPasswords, ClaudeConnectors. Every form-open MUST route through AbstractServerTab::open_form() and every nonce MUST route through AbstractServerTab::nonce_field( array $server ) with action 'acrossai_mcp_manager_server_' . (int) $server['id']. Every render body MUST use the most-specific WordPress escape function at rendering point (esc_url/esc_html/esc_attr/wp_kses_post). Every translated string MUST use text domain 'acrossai-mcp-manager'. No printf may mix positional %s + numbered %1$s placeholders in one call per BUGS.md B16. Do not port the reference plugin's inline JS into tab bodies — if a tab needs JS, extend src/js/backend.js and enqueue via admin/Main.php screen-ID guard. Do not touch SettingsRenderer::render_tab_nav or ApplicationPasswords::render_for_server. Do not widen the Update-Server + Danger-Zone visibility gate. Do not introduce any file that uses the legacy ACROSSAI_MCP_MANAGER\\ uppercase namespace.

CROSS-CONTEXT REUSE: Extract the render bodies of the three client-configuration tabs (npm, MCP Clients, Claude Connector) into a new public Renderer layer under public/Renderers/ so third-party plugins (BuddyBoss, WooCommerce, other AcrossAI plugins) can embed the same UI on their own admin or frontend surfaces with zero code duplication. Create AbstractClientRenderer (abstract client_key/render_body_for_context + shared helpers passwords_generate_button/config_json_pre_block/copy_config_button) and three subclasses: NpmClientBlock (encapsulates NpmTab body), MCPClientsBlock (encapsulates ClientsTab body — dispatches per-client sub-renders across the 8 MCPClients), ClaudeConnectorBlock (encapsulates ClaudeConnectorTab body). Public API surface: (a) static render(int server_id, array context = []) method with context keys server_id/context/nonce_action/submit_target_url/cap/user_id/copy_button_enabled; (b) action hook 'acrossai_mcp_render_client_block' with args (renderer_slug, server_id, context); (c) context filter 'acrossai_mcp_client_block_context' with args (context_array, renderer_slug, server_id); (d) optional shortcodes [acrossai_mcp_npm_block server=X], [acrossai_mcp_clients_block server=X], [acrossai_mcp_claude_connector_block server=X] each with a manage_options-default capability filter. The three admin tab classes (NpmTab, ClientsTab, ClaudeConnectorTab) become THIN DELEGATES — their render_body() calls Renderer::render($server['id'], ['context' => 'admin', 'cap' => 'manage_options', 'submit_target_url' => admin_url('admin.php?...'), 'nonce_action' => 'acrossai_mcp_manager_server_' . $server['id']]). The 8 non-client tabs stay admin-only — no Renderer extraction. Security: every Renderer::render() call re-verifies the capability from the context (default manage_options; overridable to any cap the embedder requires); the WordPress Application Password 'Generate' button must ONLY generate for wp_get_current_user() — never for a target user in a BuddyBoss-profile-view context (defense against admin-impersonation). REST endpoint for password generation enforces this via permission_callback that rejects any request whose target user_id differs from get_current_user_id(). Nonces bind BOTH server_id AND context slug ('acrossai_mcp_render_' . client_key . '_' . server_id . '_' . context_slug) so a nonce minted in one context cannot be replayed in another.

Memory hygiene: capture DEC-SERVER-TAB-CLASS-HIERARCHY as the canonical pattern for future per-server-edit admin sections AND DEC-CLIENT-RENDERER-PUBLIC-API codifying the public Renderer layer as the reusable-render pattern for future MCP-adjacent third-party integrations."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all four of
> these governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, hook registration rules
>    (§I A1), Before Commit Checklist.
> 2. The reference plugin's monolithic tab file:
>    `/Users/raftaar1191/local-sites/wordpress-ai/app/public/wp-content/plugins/acrossai-mcp-manager/src/Admin/Settings.php`
>    (2,615 lines). Read the 11 tab render methods at these ranges — each
>    port must preserve the operator-visible behavior verbatim:
>
>    | Tab | Reference render method | Line range | LOC |
>    | --- | --- | --- | --- |
>    | Overview | `render_overview_tab` | 1101–1247 | 147 |
>    | npm | `render_npm_tab` | 1258–1351 | 94 |
>    | MCP Clients (parent) | `render_clients_tab` | 1366–1396 | 30 |
>    | MCP Clients (per-client sub) | `render_client_tab` | 1409–1489 | 81 |
>    | Claude Connector | `render_claude_connector_tab` | 1498–1698 | 201 |
>    | WP-CLI | `render_wpcli_tab` | 1762–1880 | 119 |
>    | Tools | `render_tools_tab` | 1893–1963 | 71 |
>    | Abilities | `render_abilities_tab` | 1981–2134 | 154 |
>    | Access Control | `render_access_control_tab` | 2157–2168 | 12 |
>    | MCP Tracker | `render_mcp_tracker_tab` | 2293–2379 | 87 |
>    | Update Server | `render_update_server_tab` | 2390–2510 | 121 |
>    | Danger Zone | `render_danger_zone_tab` | 2524–2592 | 69 |
>
> 3. The in-repo canonical for singleton + template-method + shared-helper
>    class shape: `admin/Partials/SettingsMenu.php` (Feature 012). Its
>    singleton scaffolding, member ordering, and the way it centralizes
>    render helpers is the reference for `AbstractServerTab`.
> 4. The sibling plugin's SettingsMenu:
>    `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-abilities-manager/admin/Partials/SettingsMenu.php`
>    (Feature 038-onward) — canonical singleton + hook-owned-by-Main.php
>    shape for the AcrossAI plugin family, already blessed in
>    DEC-VENDOR-SETTINGS-TAB-INTEGRATION.
>
> Every design decision — class-member ordering, escape-idiom choice,
> form-open helper vs inline HTML, visibility-gate placement — must be
> justified against the above. If a choice is not explicitly covered,
> default to the F012 SettingsMenu.php shape. Do not write code that would
> fail any DoD gate: PHPStan level 8, PHPCS, security review, all `__()`
> calls using text domain `'acrossai-mcp-manager'`.
>
> **Public API artifacts + regression grep-gates (before + after every TASK):**
>
> Zero-hit gate — the 4 old render method names MUST NOT appear anywhere in
> `admin/` after TASK-2:
> ```
> grep -rEn "render_general_tab|render_access_control_tab|render_claude_connector_tab|render_tokens_tab" \
>     --include='*.php' admin/
> ```
> Zero-hit gate — the legacy uppercase namespace MUST NEVER appear in the
> plugin. Runs both BEFORE (baseline confirmation) and AFTER every TASK
> (regression prevention during the reference-plugin port):
> ```
> grep -rn 'ACROSSAI_MCP_MANAGER\\\\' --include='*.php' admin/ includes/ public/ acrossai-mcp-manager.php
> ```
> Exact-count gate — after TASK-6 (all 11 tabs ported), the ServerTabs
> directory MUST contain exactly 11 tab subclasses:
> ```
> grep -rEn "class .*Tab extends AbstractServerTab" admin/Partials/ServerTabs/
> ```
> Expected: 11 hits.
>
> Duplication gates — after TASK-6, no raw `<form method="post">` and no
> raw `wp_nonce_field(` may appear in any tab subclass body (all routed
> through AbstractServerTab helpers):
> ```
> grep -cE '<form method="post"' admin/Partials/ServerTabs/*.php
> grep -rn 'wp_nonce_field(' admin/Partials/ServerTabs/*.php
> ```
> Both expected: zero.
>
> **Preserved contract map:**
>
> | Contract | Value | Source |
> | --- | --- | --- |
> | Tab dispatch slug list (order matters — matches the nav bar left-to-right) | `overview`, `npm`, `clients`, `claude-connector`, `wp-cli`, `tools`, `abilities`, `access-control`, `mcp-tracker`, `update-server`, `danger-zone` | This feature — 11 tabs, ordered as reference plugin's nav |
> | Nonce action name | `'acrossai_mcp_manager_server_' . (int) $server['id']` | Reference plugin pattern; centralized in `AbstractServerTab::nonce_field()` |
> | DB-only visibility gate | `'database' === ( $server['registered_from'] ?? '' )` | Reference `src/Admin/Settings.php:1042` — verbatim |
> | Text domain | `'acrossai-mcp-manager'` | Constitution §II + plugin header |
> | PascalCase namespace root | `AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\` | Module structure F002 |
> | Existing helpers to reuse (never re-implement) | `SettingsRenderer::render_tab_nav()`; `ApplicationPasswords::render_for_server()`; `MCPServerQuery::instance()->get_item()`; `\AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors`; `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth::get_base_url()`; the 8 MCPClients concrete classes | Prior features |
>
> ---
>
> **TASK-1 — Scaffold `AbstractServerTab` base + `Registry` dispatch + PHPUnit harness**
>
> Files:
> - `admin/Partials/ServerTabs/AbstractServerTab.php` (NEW)
> - `admin/Partials/ServerTabs/Registry.php` (NEW)
> - `tests/phpunit/Admin/ServerTabs/RegistryTest.php` (NEW)
> - `tests/phpunit/Admin/ServerTabs/AbstractServerTabTest.php` (NEW)
>
> Read `admin/Partials/SettingsMenu.php` (F012) BEFORE editing. Match its
> singleton member ordering verbatim on `Registry` (protected static
> `$instance` → `public static function instance(): self` → `private
> __construct()`). AbstractServerTab is NOT a singleton — one instance per
> tab, held by the Registry.
>
> AbstractServerTab shape:
> ```php
> namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;
>
> defined( 'ABSPATH' ) || exit;
>
> abstract class AbstractServerTab {
>     abstract public function slug(): string;
>     abstract public function label(): string;
>     public function visible_for( array $server ): bool { return true; }
>     abstract protected function render_body( array $server ): void;
>
>     final public function render( array $server ): void {
>         $this->render_body( $server );
>     }
>
>     protected function open_form( array $server, string $action ): void { ... }
>     protected function close_form( string $submit_label ): void { ... }
>     protected function nonce_field( array $server ): void { ... }
>     protected function json_config_block( array $server, string $client_slug, array $config ): void { ... }
>     protected function passwords_notice(): void { ... }
>     protected function server_edit_url( array $server, string $tab ): string { ... }
>     protected function client_label_pair( string $client_name, string $vendor ): void { ... }
> }
> ```
>
> Registry shape (singleton, ordered list, filter):
> ```php
> final class Registry {
>     protected static $instance = null;
>     public static function instance(): self { ... }
>     private function __construct() {}
>
>     /** @return AbstractServerTab[] Ordered instantiation of all tabs. */
>     public function all_tabs(): array { ... }
>
>     /** Filters all_tabs() by ->visible_for( $server ). */
>     public function visible_tabs( array $server ): array {
>         return array_values( array_filter(
>             $this->all_tabs(),
>             static fn ( AbstractServerTab $t ) => $t->visible_for( $server )
>         ) );
>     }
>
>     /** Dispatches on tab slug; falls back to OverviewTab if slug unknown. */
>     public function render( string $tab_slug, array $server ): void {
>         foreach ( $this->all_tabs() as $tab ) {
>             if ( $tab->slug() === $tab_slug ) { $tab->render( $server ); return; }
>         }
>         // Fallback — render Overview.
>         ( new OverviewTab() )->render( $server );
>     }
> }
> ```
>
> RegistryTest MUST cover:
> - Slug uniqueness across `all_tabs()` (assertCount === count of distinct slugs).
> - Ordered iteration (assert `overview` at index 0, `danger-zone` at last index).
> - `visible_tabs()` with `$server['registered_from'] === 'plugin'` returns 9
>   tabs (no Update Server, no Danger Zone).
> - `visible_tabs()` with `$server['registered_from'] === 'database'`
>   returns 11 tabs.
>
> AbstractServerTabTest MUST cover `open_form()` + `nonce_field()` markup
> emission via output buffer + `assertStringContainsString`. Use
> `#[DataProvider]` attribute per BUGS.md B9. Cite B9 in the test-file
> docblock.
>
> **DoD**: `php -l` clean on 4 new files; PHPStan L8 zero errors; PHPCS
> zero errors, zero warnings; `vendor/bin/phpunit --testsuite admin
> --bootstrap tests/bootstrap-wp.php` returns zero failures (Registry
> returns empty array while no concrete tabs registered — this is a valid
> tested state until TASK-2).
>
> ---
>
> **TASK-2 — Refactor existing 4 tabs into per-tab classes (zero UI change)**
>
> Files:
> - `admin/Partials/ServerTabs/OverviewTab.php` (NEW — minimal shell, will
>   be enriched in TASK-4)
> - `admin/Partials/ServerTabs/TokensTab.php` (NEW)
> - `admin/Partials/ServerTabs/AccessControlTab.php` (NEW)
> - `admin/Partials/ServerTabs/ClaudeConnectorTab.php` (NEW — minimal port
>   of current Settings.php:664–706 body; TASK-3 adds `ConnectorAuditLogListTable`)
> - `admin/Partials/ServerTabs/Registry.php` (delta: register the 4 tabs
>   into `all_tabs()` in order)
> - `admin/Partials/Settings.php` (delta: delete `render_general_tab` /
>   `render_tokens_tab` / `render_access_control_tab` /
>   `render_claude_connector_tab`; rewrite the switch dispatch in
>   `render_edit_page()` to a single `Registry::instance()->render( $tab_slug,
>   $server )` call after the wrap + breadcrumb)
>
> OverviewTab.php — for TASK-2, keep it a minimal shell that just echoes
> the current `render_general_tab` body verbatim (via `open_form()` +
> ported fields + `close_form()`). TASK-4 enriches it to match the
> reference plugin's full 147-LOC Overview.
>
> TokensTab.php — delegate entirely to
> `\AcrossAI_MCP_Manager\Admin\Partials\ApplicationPasswords::render_for_server(
> $server['id'] )`. No new rendering. `visible_for()` returns
> `parent::visible_for( $server )` (always true).
>
> AccessControlTab.php — port `render_access_control_tab` (Settings.php
> line 637) VERBATIM. Preserve the `class_exists(
> '\WPBoilerplate\AccessControl\AccessControlManager' )` guard per D8. Do
> NOT extend or "improve" the guard — this is a shape-only refactor pass.
>
> ClaudeConnectorTab.php — port `render_claude_connector_tab`
> (Settings.php line 664) into the new shape. Use `open_form()` +
> `nonce_field()` instead of raw `<form>` + `wp_nonce_field()`. Use
> `close_form()` for the submit button. Do NOT add
> `ConnectorAuditLogListTable` yet — that lands in TASK-3.
>
> **DoD**:
> - `grep -rEn "render_general_tab|render_access_control_tab|render_claude_connector_tab|render_tokens_tab" --include='*.php' admin/`
>   returns **zero hits**.
> - Manual smoke: navigate the 4 existing tabs on a live WP install +
>   verify they render identically to pre-refactor (screenshots optional
>   but recommended for the reviewer).
> - PHPStan L8 + PHPCS green on the 4 new files + modified `Settings.php`
>   + `Registry.php`.
> - `RegistryTest::test_slug_ordering()` now passes with the 4 slugs
>   `[overview, tokens, access-control, claude-connector]` (7 more tabs
>   added in TASK-4/5/6).
>
> ---
>
> **TASK-3 — Port 2 `WP_List_Table` subclasses from reference plugin**
>
> Files:
> - `admin/Partials/CliAuthLogListTable.php` (NEW — ported from reference)
> - `admin/Partials/ConnectorAuditLogListTable.php` (NEW — ported from reference)
>
> Source paths in reference plugin (read BEFORE editing):
> - `/Users/raftaar1191/local-sites/wordpress-ai/app/public/wp-content/plugins/acrossai-mcp-manager/src/Admin/CliAuthLogListTable.php`
> - `/Users/raftaar1191/local-sites/wordpress-ai/app/public/wp-content/plugins/acrossai-mcp-manager/src/Admin/ConnectorAuditLogListTable.php`
>
> Adaptation checklist for BOTH files:
> 1. Change namespace from `ACROSSAI_MCP_MANAGER\Admin` (uppercase) to
>    `AcrossAI_MCP_Manager\Admin\Partials` (PascalCase).
> 2. Replace `use ACROSSAI_MCP_MANAGER\Database\...` with target's DB
>    Query classes:
>    - CliAuthLogListTable → `use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query;`
>    - ConnectorAuditLogListTable → `use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query;`
> 3. Replace class-body DB calls: `Query::instance()->query( [...] )`
>    matches the target's BerlinDB-backed API (F011).
> 4. Add `defined( 'ABSPATH' ) || exit;` per plugin convention.
> 5. Cite `DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG` in the
>    `CliAuthLogListTable.php` file docblock — the reintroduction is
>    on-pattern (per-server-tab inspection is the blessed alternative to
>    the deleted standalone admin submenu).
> 6. Match target's PHPCS style — leading `\` on FQN in `extends
>    \WP_List_Table` per B15 pattern.
>
> **DoD**:
> - Legacy-namespace grep `grep -rn 'ACROSSAI_MCP_MANAGER\\\\'
>   --include='*.php' admin/` returns **zero hits**.
> - `php -l` clean; PHPStan L8 zero errors; PHPCS zero errors, zero
>   warnings.
> - Optional smoke: instantiate each ListTable in a test scenario — no
>   fatal on `->prepare_items()`.
>
> ---
>
> **TASK-4 — Create public Renderer layer for cross-context reuse**
>
> Files:
> - `public/Renderers/AbstractClientRenderer.php` (NEW — base class + shared helpers + public API surface)
> - `public/Renderers/NpmClientBlock.php` (NEW — extends AbstractClientRenderer)
> - `public/Renderers/MCPClientsBlock.php` (NEW — extends AbstractClientRenderer, dispatches per-client sub-render)
> - `public/Renderers/ClaudeConnectorBlock.php` (NEW — extends AbstractClientRenderer)
> - `includes/REST/ClientRendererController.php` (NEW — permission_callback locks Application Password generation to `get_current_user_id()`)
> - `includes/Main.php` (delta: wire the shortcode + REST route)
> - `tests/phpunit/Public/Renderers/{AbstractClientRendererTest,PublicApiTest}.php` (NEW)
>
> This task lands BEFORE TASK-5 (the admin tab ports for the 3 client tabs)
> because those tabs delegate to these Renderer classes. WpCliTab / ToolsTab /
> McpTrackerTab (also in TASK-5) stay admin-only and do NOT touch this layer.
>
> **`AbstractClientRenderer.php` shape**:
> ```php
> namespace AcrossAI_MCP_Manager\Public\Renderers;
>
> defined( 'ABSPATH' ) || exit;
>
> abstract class AbstractClientRenderer {
>     /** Unique slug for the block ('npm', 'clients', 'claude-connector'). */
>     abstract public function slug(): string;
>
>     /** Renders the block body given a fully-resolved context. */
>     abstract protected function render_body( array $server, array $context ): void;
>
>     /**
>      * Public entry point. Called by admin tab classes AND by external plugins.
>      *
>      * @param int   $server_id MCP server ID.
>      * @param array $context   {
>      *     Optional. Context array.
>      *     @type string $context           Context slug — 'admin', 'buddyboss-profile',
>      *                                     'woocommerce-my-account', or any custom string.
>      *                                     Default 'admin'.
>      *     @type string $cap               Required capability. Default 'manage_options'.
>      *     @type string $submit_target_url URL forms POST to. Default admin_url() edit page.
>      *     @type string $nonce_action      Nonce action name. Auto-derived if omitted.
>      *     @type int    $user_id           Target user for Application Password. Default get_current_user_id().
>      *                                     If not equal to get_current_user_id(), password generation
>      *                                     is disabled (defense-in-depth against admin-impersonation).
>      *     @type bool   $copy_button       Whether to render the "Copy Configuration" JS button.
>      *                                     Default true.
>      * }
>      */
>     final public function render( int $server_id, array $context = array() ): void {
>         $context = $this->resolve_context( $server_id, $context );
>         if ( ! current_user_can( $context['cap'] ) ) { return; }
>         $server = \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()->get_item( $server_id );
>         if ( null === $server ) { $this->render_missing_server_notice(); return; }
>         $this->render_body( $server->to_array(), $context );
>     }
>
>     /** Applies defaults + the 'acrossai_mcp_client_block_context' filter. */
>     protected function resolve_context( int $server_id, array $context ): array {
>         $defaults = array(
>             'context'           => 'admin',
>             'cap'               => 'manage_options',
>             'submit_target_url' => admin_url( 'admin.php?page=' . \AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs::PARENT ),
>             'nonce_action'      => 'acrossai_mcp_render_' . $this->slug() . '_' . $server_id . '_' . ( $context['context'] ?? 'admin' ),
>             'user_id'           => get_current_user_id(),
>             'copy_button'       => true,
>         );
>         $context = wp_parse_args( $context, $defaults );
>         return (array) apply_filters( 'acrossai_mcp_client_block_context', $context, $this->slug(), $server_id );
>     }
>
>     // Shared helpers used by 2+ block subclasses
>     protected function passwords_generate_button( array $server, array $context ): void { /* renders the button only if user_id === get_current_user_id() */ }
>     protected function config_json_pre_block( array $server, array $context, array $config ): void { /* renders the <pre> block */ }
>     protected function copy_config_button( string $target_selector ): void { /* renders the JS button */ }
>     protected function render_missing_server_notice(): void { /* renders a "server not found" notice */ }
>
>     /**
>      * Renders the "feature currently disabled" notice when a global F012
>      * toggle at ?page=acrossai-settings&tab=mcp gates this block off.
>      *
>      * Used by NpmClientBlock (option `acrossai_mcp_npm_login_enabled`) and
>      * ClaudeConnectorBlock (option `acrossai_mcp_claude_connectors_enabled`).
>      * Renders header + "currently disabled" callout + link to settings +
>      * explanatory paragraph. Returns nothing (echoes markup).
>      */
>     protected function render_feature_disabled_notice( string $feature_label, string $enable_link_text, string $explanation ): void {
>         $settings_url = admin_url( 'admin.php?page=acrossai-settings&tab=mcp' );
>         printf(
>             '<div class="notice notice-warning inline"><p><strong>%1$s</strong></p>' .
>             /* translators: 1: feature label, 2: settings page link */
>             wp_kses_post( __( '<p>To use the %1$s feature, please %2$s first.</p>', 'acrossai-mcp-manager' ) ) .
>             '<p>%3$s</p></div>',
>             esc_html( $feature_label ),
>             sprintf(
>                 '<a href="%s">%s</a>',
>                 esc_url( $settings_url ),
>                 esc_html( $enable_link_text )
>             ),
>             esc_html( $explanation )
>         );
>     }
> }
> ```
>
> **F012 settings-toggle gating (load-bearing)**:
>
> Two of the three blocks are gated by F012's Settings API toggles at
> `?page=acrossai-settings&tab=mcp`. The gate MUST be enforced INSIDE the
> Renderer's `render_body()` so that admin, BuddyBoss, WooCommerce, and
> shortcode consumers all get the same behavior — an operator who
> disables npm in Settings expects it disabled EVERYWHERE, not just in
> the admin edit page.
>
> **NpmClientBlock**:
> ```php
> protected function render_body( array $server, array $context ): void {
>     $this->render_section_heading( __( 'npm / npx CLI', 'acrossai-mcp-manager' ) );
>
>     $enabled = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false );
>     if ( ! $enabled ) {
>         $this->render_feature_disabled_notice(
>             __( 'npm / npx CLI', 'acrossai-mcp-manager' ),
>             __( 'enable CLI Connections in Settings', 'acrossai-mcp-manager' ),
>             __( 'Enabling this feature allows terminal users to connect the AcrossAI MCP Manager CLI tool to this WordPress site using the npx command. Users sign in through WordPress and approve access in the browser, then the CLI receives an Application Password automatically so no JSON files need to be configured by hand. Only enable this if you intend to use the CLI for local development or trusted environments.', 'acrossai-mcp-manager' )
>         );
>     } else {
>         // Full config UI — generate button + config file path + JSON block + copy button.
>         $this->passwords_generate_button( $server, $context );
>         $this->render_config_file_row( ... );
>         $this->config_json_pre_block( $server, $context, $config );
>         $this->copy_config_button( '#acrossai-mcp-npm-config' );
>     }
>
>     // CLI Connection Log ListTable ALWAYS renders — past auth attempts are
>     // still worth showing regardless of the current toggle state.
>     $this->render_cli_connection_log( $server );
> }
> ```
>
> **ClaudeConnectorBlock**:
> ```php
> protected function render_body( array $server, array $context ): void {
>     $this->render_section_heading( __( 'Claude Connector', 'acrossai-mcp-manager' ) );
>
>     $enabled = (bool) get_option( 'acrossai_mcp_claude_connectors_enabled', false );
>     if ( ! $enabled ) {
>         $this->render_feature_disabled_notice(
>             __( 'Claude Connector', 'acrossai-mcp-manager' ),
>             __( 'enable direct Claude Connectors mode in Settings', 'acrossai-mcp-manager' ),
>             __( 'Enabling this feature allows this MCP server to be added directly to Claude Desktop or Claude Code as a native connector. The plugin serves the OAuth authorization-server metadata, authorize URL, and token endpoint required by Claude. Only enable this if you intend to use this server as a Claude connector target.', 'acrossai-mcp-manager' )
>         );
>     } else {
>         // Full config UI — per-server client_id / client_secret / redirect_uri form.
>         $this->open_render_form( $server, $context, 'save_claude_connector' );
>         $this->render_claude_connector_fields( $server );
>         $this->close_render_form( __( 'Save Claude Connector', 'acrossai-mcp-manager' ) );
>     }
>
>     // Connector audit log ALWAYS renders — past OAuth events are useful
>     // even when the toggle is currently off.
>     $this->render_connector_audit_log( $server );
> }
> ```
>
> **MCPClientsBlock is NOT gated** by any F012 toggle. It aggregates the
> per-client config (Claude Desktop, Claude Code, VS Code, GitHub Copilot,
> Codex, Cursor, Custom Client) which are all manual-config-file paths —
> they don't route through the CLI login flow or the Claude Connector
> OAuth surface, so neither gate applies.
>
> When embedded outside the admin (e.g., BuddyBoss context, `cap` = `'read'`),
> the "enable ... in Settings" link in the disabled-notice STILL points at
> `?page=acrossai-settings&tab=mcp`. Non-admin viewers see the link but
> get the "insufficient permissions" screen if they click it — the
> settings page is `manage_options` upstream (vendor SettingsPage). This
> is intentional: the block honestly communicates the reason it's
> disabled + points at the authoritative place to fix it, even if the
> current viewer can't perform the fix themselves.
>
> **Registration** — inside `includes/Main.php::define_public_hooks()`:
> ```php
> add_action( 'init', function () {
>     add_shortcode( 'acrossai_mcp_npm_block', array( \AcrossAI_MCP_Manager\Public\Renderers\NpmClientBlock::class, 'shortcode' ) );
>     add_shortcode( 'acrossai_mcp_clients_block', array( \AcrossAI_MCP_Manager\Public\Renderers\MCPClientsBlock::class, 'shortcode' ) );
>     add_shortcode( 'acrossai_mcp_claude_connector_block', array( \AcrossAI_MCP_Manager\Public\Renderers\ClaudeConnectorBlock::class, 'shortcode' ) );
> } );
> $this->loader->add_action( 'acrossai_mcp_render_client_block', /* dispatcher */ );
> ```
>
> The action hook lets a third-party plugin render a block without knowing
> the Renderer class name:
> ```php
> // BuddyBoss integration example (in a third-party plugin):
> do_action( 'acrossai_mcp_render_client_block', 'npm', $server_id, array(
>     'context'           => 'buddyboss-profile',
>     'cap'               => 'read',  // BuddyBoss members can view their own MCP config
>     'submit_target_url' => bp_get_profile_url(),
>     'user_id'           => bp_displayed_user_id(),
> ) );
> ```
>
> **`ClientRendererController.php` (REST)** — POST
> `/wp-json/acrossai-mcp-manager/v1/generate-app-password` — accepts
> `server_id` + `client_slug` + `context`. `permission_callback` verifies:
> 1. `is_user_logged_in()`,
> 2. Requested `user_id` (if provided) equals `get_current_user_id()`,
> 3. Nonce (X-WP-Nonce header) matches
>    `'acrossai_mcp_render_' . $client_slug . '_' . $server_id . '_' . $context`.
>
> Returns the newly-generated Application Password via
> `WP_Application_Passwords::create_new_application_password()`. Never
> generates for a user other than the current one — even if an admin
> passes a different `user_id`.
>
> **`PublicApiTest.php` MUST cover**:
> - `AbstractClientRenderer::render()` fails silently (no output) when
>   the current user lacks the context `cap`.
> - `resolve_context()` merges defaults correctly + applies the filter.
> - The context filter can override every default.
> - Missing `$server` (invalid ID) triggers the missing-server notice, not
>   a fatal.
> - `passwords_generate_button()` renders a disabled button when
>   `$context['user_id']` differs from `get_current_user_id()`.
> - REST `permission_callback` rejects a request whose `user_id` differs
>   from `get_current_user_id()` (403).
> - REST `permission_callback` rejects a request with a nonce minted for
>   a DIFFERENT context slug (403 — defense against cross-context nonce
>   replay).
> - **F012 gate — npm disabled**: `NpmClientBlock::render()` with
>   `get_option('acrossai_mcp_npm_login_enabled') === false` produces
>   output containing "currently disabled" + a link to
>   `?page=acrossai-settings&tab=mcp`, and does NOT produce any
>   `Configuration JSON` / `Generate New Application Password` markup.
>   The CLI Connection Log ListTable output IS still present.
> - **F012 gate — npm enabled**: same call with the option = true
>   produces the full config UI (Generate button + config file row +
>   JSON block) AND the ListTable — no "currently disabled" text.
> - **F012 gate — Claude Connector disabled**:
>   `ClaudeConnectorBlock::render()` with
>   `get_option('acrossai_mcp_claude_connectors_enabled') === false`
>   produces "Claude Connector is currently disabled" notice + settings
>   link, and does NOT emit a `save_claude_connector` form. The audit
>   log ListTable output IS still present.
> - **F012 gate — Claude Connector enabled**: same call with option =
>   true produces the full per-server client_id/client_secret/redirect_uri
>   form + audit log — no "currently disabled" text.
> - **MCPClientsBlock NOT gated**: verify
>   `MCPClientsBlock::render()` produces the full 8-client dispatch
>   regardless of the values of the two F012 options (proves MCPClients
>   tab is NOT accidentally coupled to the toggles).
>
> **DoD**:
> - 5 new PHP files under `public/Renderers/` + 1 REST controller + 2
>   PHPUnit files.
> - `php -l` clean; PHPStan L8 zero errors; PHPCS zero errors, zero warnings.
> - `PublicApiTest` covers all 7 cases above with `#[DataProvider]` where
>   applicable (per B9).
> - Shortcodes registered on `init` and return non-empty output when a
>   valid `server=` attr is provided by a `manage_options`-capable user.
>
> ---
>
> **TASK-5 — Port 5 new tabs (NpmTab, ClientsTab, WpCliTab, ToolsTab, McpTrackerTab)**
>
> Files:
> - `admin/Partials/ServerTabs/NpmTab.php` (NEW — THIN DELEGATE to `NpmClientBlock`)
> - `admin/Partials/ServerTabs/ClientsTab.php` (NEW — THIN DELEGATE to `MCPClientsBlock`)
> - `admin/Partials/ServerTabs/WpCliTab.php` (NEW — from reference lines 1762–1880, admin-only render)
> - `admin/Partials/ServerTabs/ToolsTab.php` (NEW — from reference lines 1893–1963, admin-only render)
> - `admin/Partials/ServerTabs/McpTrackerTab.php` (NEW — from reference lines 2293–2379, admin-only detection render)
> - `admin/Partials/ServerTabs/Registry.php` (delta: register 5 more tabs)
> - `admin/Partials/ServerTabs/OverviewTab.php` (delta: enrich to full 147-LOC content from reference lines 1101–1247)
> - `admin/Partials/ServerTabs/ClaudeConnectorTab.php` (delta: convert from TASK-2 minimal port to THIN DELEGATE to `ClaudeConnectorBlock`, + wire the newly-ported `ConnectorAuditLogListTable` INTO the Block, not the Tab)
>
> The three CLIENT tab classes (NpmTab, ClientsTab, ClaudeConnectorTab)
> become ~15-line delegates:
> ```php
> final class NpmTab extends AbstractServerTab {
>     public function slug(): string { return 'npm'; }
>     public function label(): string { return __( 'npm', 'acrossai-mcp-manager' ); }
>
>     protected function render_body( array $server ): void {
>         \AcrossAI_MCP_Manager\Public\Renderers\NpmClientBlock::instance()->render(
>             (int) $server['id'],
>             array(
>                 'context'           => 'admin',
>                 'cap'               => 'manage_options',
>                 'submit_target_url' => $this->server_edit_url( $server, 'npm' ),
>                 'nonce_action'      => 'acrossai_mcp_manager_server_' . (int) $server['id'],
>             )
>         );
>     }
> }
> ```
> The full render body (94 LOC for NpmTab per reference) lives EXCLUSIVELY
> in `NpmClientBlock::render_body()`. Grep for `<pre>` or `<textarea>` or
> `Configuration JSON` in `admin/Partials/ServerTabs/NpmTab.php` → **0
> hits**. The Tab is a thin wrapper only.
>
> Same pattern for ClientsTab → `MCPClientsBlock::instance()->render(...)`
> and ClaudeConnectorTab → `ClaudeConnectorBlock::instance()->render(...)`.
>
> The two ADMIN-ONLY new tabs (WpCliTab, ToolsTab, McpTrackerTab) render
> directly in their `render_body()` — no Renderer extraction (they are not
> reused by external contexts).
>
> ClientsTab.php delegates its per-client sub-rendering to
> `MCPClientsBlock` — which internally iterates over the 8 MCPClients
> classes from F004 and calls a shared helper on `AbstractClientRenderer`
> for the per-client body. Do NOT re-implement any MCPClient class.
>
> **DoD**:
> - `grep -cE '<form method="post"' admin/Partials/ServerTabs/*.php`
>   returns **0**.
> - `grep -rn 'wp_nonce_field(' admin/Partials/ServerTabs/*.php` returns
>   **0**.
> - `grep -cE '<pre>|<textarea>|Configuration JSON' admin/Partials/ServerTabs/{NpmTab,ClientsTab,ClaudeConnectorTab}.php`
>   returns **0** (the 3 client tabs are thin delegates — all rich HTML
>   lives in the Renderer layer).
> - PHPStan L8 + PHPCS zero errors, zero warnings on the 5 new files + the
>   3 modified files.
> - `RegistryTest::test_all_tabs_returns_9_when_plugin_source()` passes
>   with the 9 slugs (10th and 11th arrive in TASK-7).
>
> ---
>
> **TASK-6 — Port AbilitiesTab with external-API absence guard**
>
> Files:
> - `admin/Partials/ServerTabs/AbilitiesTab.php` (NEW — from reference lines 1981–2134)
> - `admin/Partials/ServerTabs/Registry.php` (delta: register 1 more tab)
>
> Port the reference plugin's `render_abilities_tab()` (line 1981) body.
> The reference plugin uses `wp_get_abilities()` — an external API
> provided by the WP Abilities Manager sibling plugin. That plugin is NOT
> a hard dependency of `acrossai-mcp-manager`, so:
>
> 1. Guard the entire render with `function_exists( 'wp_get_abilities'
>    )`. If absent, render a soft notice explaining that the
>    `acrossai-abilities-manager` plugin (or another Abilities provider)
>    must be active to use this tab, and stop.
> 2. Preserve the `class_exists(
>    '\AcrossAI_Abilities_Manager\Includes\Runtime' )` guard from the
>    reference plugin per D8.
> 3. Match target's escape idioms — no reference-plugin `esc_html()` on
>    URL substitutions; use `esc_url()` per SEC-012-008.
>
> **DoD**:
> - The `function_exists('wp_get_abilities')` gate is present at the top
>   of `render_body()`.
> - Rendering with the guard tripped (function absent) does NOT fatal.
> - PHPStan L8 + PHPCS green.
>
> ---
>
> **TASK-7 — Port DB-only tabs (UpdateServerTab, DangerZoneTab) + Registry visibility rule**
>
> Files:
> - `admin/Partials/ServerTabs/UpdateServerTab.php` (NEW — from reference lines 2390–2510)
> - `admin/Partials/ServerTabs/DangerZoneTab.php` (NEW — from reference lines 2524–2592)
> - `admin/Partials/ServerTabs/Registry.php` (delta: register the last 2 tabs; total is now 11)
>
> Both classes override `visible_for()`:
> ```php
> public function visible_for( array $server ): bool {
>     return 'database' === ( $server['registered_from'] ?? '' );
> }
> ```
>
> UpdateServerTab renders an edit form for server metadata. DangerZoneTab
> renders a delete-with-confirmation form. Both consume
> `MCPServerQuery::instance()->update_item( $id, ... )` and
> `->delete_item( $id )` — reuse F011's Query API verbatim; do NOT
> re-implement.
>
> **DoD**:
> - Exact-count gate: `grep -rEn "class .*Tab extends AbstractServerTab"
>   admin/Partials/ServerTabs/` returns **exactly 11 hits**.
> - `RegistryTest::test_visible_tabs_gates_db_only()` covers both
>   scenarios (`registered_from` = plugin → 9 tabs; = database → 11 tabs).
> - PHPStan L8 + PHPCS green.
>
> ---
>
> **TASK-8 — DRY sweep + expanded PHPUnit coverage + cross-context integration test**
>
> Files:
> - `admin/Partials/ServerTabs/AbstractServerTab.php` (potential delta —
>   promote any duplication surviving the 11-tab port to protected methods)
> - `tests/phpunit/Admin/ServerTabs/RegistryTest.php` (expand)
> - `tests/phpunit/Admin/ServerTabs/AbstractServerTabTest.php` (expand)
>
> Sweep `admin/Partials/ServerTabs/*.php` for any repeated HTML shell (>3
> lines identical across 2+ tabs) or repeated `esc_*` idiom clusters.
> Promote to `AbstractServerTab` protected methods. Common candidates:
> - JSON-config code block header ("Copy this into your ... config file")
> - Empty-state notice when no server data present
> - Client-label pair rendering (used across NpmTab, ClientsTab,
>   ClaudeConnectorTab)
>
> Expand tests:
> - `RegistryTest::test_all_tabs_slugs_match_expected_order()` — asserts
>   the full 11-slug list is in the correct order.
> - `AbstractServerTabTest::test_json_config_block_emits_expected_markup()`
>   — verify the JSON config block helper.
> - `PublicApiTest::test_admin_and_external_context_produce_identical_body_markup()`
>   — call `NpmClientBlock::instance()->render()` twice: once with
>   `['context' => 'admin']` and once with `['context' => 'buddyboss-profile', 'cap' => 'read', 'submit_target_url' => 'http://example.com/members/me/']`.
>   Assert the CORE markup (config JSON, client label, notice text) is
>   BYTE-IDENTICAL between the two contexts modulo the form action URL and
>   the nonce field. This is the zero-duplication invariant made
>   mechanically verifiable — if two callers get different bodies from the
>   same Renderer, someone has duplicated logic outside the Renderer.
>
> **DoD**:
> - All 5 grep gates from the spec's "regression grep-gates" block still
>   green.
> - Additional grep gate:
>   `grep -cE '<pre>|<textarea>|Configuration JSON' admin/Partials/ServerTabs/{NpmTab,ClientsTab,ClaudeConnectorTab}.php`
>   returns **0** (zero-duplication invariant — the 3 client tab classes
>   are pure delegates).
> - PHPUnit green (including the cross-context byte-identity test).
> - PHPStan L8 + PHPCS green plugin-wide.
>
> ---
>
> **TASK-9 — Memory hygiene + changelog + planings-tasks + security review**
>
> Files:
> - `README.txt` (delta: add Unreleased changelog bullet)
> - `docs/memory/DECISIONS.md` (append DEC-SERVER-TAB-CLASS-HIERARCHY Active — F013 AND DEC-CLIENT-RENDERER-PUBLIC-API Active — F013)
> - `docs/memory/INDEX.md` (append 2 DEC rows + security review row)
> - `docs/memory/WORKLOG.md` (append a milestone IF a durable non-obvious
>   lesson surfaced — do NOT force one; a strong candidate is the
>   "zero-duplication cross-context byte-identity test" pattern from TASK-8)
> - `docs/planings-tasks/README.md` (append F013 row)
> - `docs/security-reviews/2026-07-04-013-per-server-tabs-refactor-plan.md`
>   (via `/speckit-security-review-plan` skill during the governed-plan
>   step, not this doc)
>
> README.txt Unreleased bullet:
> > `* Ported 7 additional per-server-edit tabs (Overview enriched, npm, MCP Clients, WP-CLI, Tools, Abilities, MCP Tracker) plus 2 database-registered-only tabs (Update Server, Danger Zone) from the reference plugin into a new per-tab class hierarchy under admin/Partials/ServerTabs/. Refactored the existing 4 tabs (General→Overview, Tokens, Access Control, Claude Connector) into the same shape. Extracted the three client-configuration blocks (npm, MCP Clients, Claude Connector) into a new public Renderer layer under public/Renderers/ with a public API surface (static render() method + acrossai_mcp_render_client_block action hook + acrossai_mcp_client_block_context filter + optional shortcodes) so third-party plugins (BuddyBoss, WooCommerce, other AcrossAI-family plugins) can embed the same UI on their own admin or frontend surfaces with zero code duplication. Restored CliAuthLogListTable + added ConnectorAuditLogListTable as per-server tab inspectors under DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG's blessed reintroduction path. No standalone admin submenu returns; the standalone CLI Auth Log admin surface removed in Feature 012 stays removed.`
>
> DEC-SERVER-TAB-CLASS-HIERARCHY body — Rule: any multi-tab admin surface
> in this plugin uses the AbstractServerTab template-method pattern
> (abstract slug/label/visible_for/render_body + shared open_form /
> close_form / nonce_field / json_config_block helpers) with a Registry
> singleton for dispatch. This is the canonical shape for future admin
> sections that grow beyond 3 tabs. It codifies DRY across tab renderers
> and matches F012 SettingsMenu's singleton pattern.
>
> DEC-CLIENT-RENDERER-PUBLIC-API body — Rule: any admin surface that
> shows MCP client configuration content (JSON config, "Generate
> Application Password" button, config-file path, top-level key) MUST
> render that content via the `public/Renderers/` layer using the
> `Renderer::render( $server_id, $context )` public API — NEVER by
> duplicating the render inside a private admin method. The public API
> supports (a) static method call, (b) `do_action(
> 'acrossai_mcp_render_client_block' )` for name-agnostic dispatch, (c)
> `acrossai_mcp_client_block_context` filter for defaults override, and
> (d) shortcodes for user-facing embed. Security: every Renderer
> capability-checks the resolved context `cap`; every nonce binds BOTH
> `$server_id` AND context slug so cross-context nonce replay is
> blocked; the Application Password "Generate" button only ever
> generates for `get_current_user_id()` — never for a target user
> passed via context (defense against admin-impersonation when embedded
> in another user's BuddyBoss profile view). This is the canonical
> pattern for future MCP-adjacent third-party integrations —
> BuddyBoss/WooCommerce/other AcrossAI-family plugins call the
> Renderers directly, they do NOT reimplement config rendering.
>
> **DoD**:
> - `grep -c 'DEC-SERVER-TAB-CLASS-HIERARCHY\|DEC-CLIENT-RENDERER-PUBLIC-API' docs/memory/INDEX.md`
>   returns 2.
> - `grep -c '013-per-server-tabs-refactor' docs/planings-tasks/README.md`
>   returns at least 1.
> - Whole-plugin gate: PHPStan L8 exit 0; PHPCS baseline unchanged;
>   `find includes admin public *.php -name '*.php' | xargs php -l`
>   zero syntax errors; `vendor/bin/phpunit --testsuite admin
>   --bootstrap tests/bootstrap-wp.php` green.

---

**CONSTRAINTS**

- **Do not touch `SettingsRenderer::render_tab_nav()`.** Registry supplies the tab list; the nav HTML stays as-is.
- **Do not touch `ApplicationPasswords::render_for_server()`.** TokensTab is a thin delegate.
- **Do not re-implement any support layer.** Every DB call reuses `\AcrossAI_MCP_Manager\Includes\Database\{MCPServer,OAuthToken,OAuthAudit,CliAuthLog}\Query::instance()`. Every MCPClient call reuses `\AcrossAI_MCP_Manager\Includes\MCPClients\<Client>::instance()`. Every OAuth call reuses `\AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors`. Every FrontendAuth call reuses `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth::get_base_url()`.
- **Do not widen the Update-Server + Danger-Zone visibility gate.** Only `'database' === ( $server['registered_from'] ?? '' )` — never any other `registered_from` value.
- **Do not port the reference plugin's inline JavaScript into tab bodies.** If a tab needs JS, extend `src/js/backend.js` and enqueue via `admin/Main.php`'s existing screen-ID guard (F008 pipeline).
- **Do not introduce any file that uses `ACROSSAI_MCP_MANAGER\` uppercase namespace.** The grep gate must return zero both before and after every TASK.
- **Do not use `printf` with mixed positional (`%s`) + numbered (`%1$s`) placeholders in one call.** BUGS.md B16 — either split into two `printf`/`sprintf` calls or use one placeholder style throughout the format string.
- **Every tab class MUST extend `AbstractServerTab`.** No direct `render_*_tab` methods on `Settings.php` after TASK-2; no bare private tab-render methods elsewhere.
- **Every form's nonce MUST route through `AbstractServerTab::nonce_field()`.** No raw `wp_nonce_field()` calls in tab bodies. Action name is `'acrossai_mcp_manager_server_' . (int) $server['id']` (single source of truth).
- **Text domain `'acrossai-mcp-manager'` on every `__()` / `_e()` / `esc_html__()` / `esc_html_e()`.** No shorthand, no reused text domain from the reference plugin.
- **Every URL substitution uses `esc_url()`, not `esc_html()`.** SEC-012-008 from F012 — regressing this on any of the 11 tabs is a review-blocking bug.
- **Client-config render logic lives ONLY in `public/Renderers/`.** `admin/Partials/ServerTabs/NpmTab.php`, `ClientsTab.php`, `ClaudeConnectorTab.php` are pure delegates — no `<pre>`, no `<textarea>`, no "Configuration JSON" heading, no "Copy Configuration" button, no `Generate New Application Password` UI, no `~/.vscode/mcp.json` string. All of that lives in `NpmClientBlock` / `MCPClientsBlock` / `ClaudeConnectorBlock`. Grep gate in TASK-8 enforces this mechanically.
- **Application Password generation MUST target `get_current_user_id()` only.** Even when a `user_id` is supplied via context (e.g., BuddyBoss admin viewing another member's profile), the "Generate" button in the block MUST render as disabled and the REST endpoint MUST return 403 when `$_POST['user_id'] !== get_current_user_id()`. Documented in DEC-CLIENT-RENDERER-PUBLIC-API.
- **Nonces bind BOTH `server_id` AND `context` slug.** Format: `'acrossai_mcp_render_' . $client_slug . '_' . $server_id . '_' . $context_slug`. A nonce minted for the admin edit page (`context='admin'`) MUST NOT validate against a BuddyBoss profile POST (`context='buddyboss-profile'`) — defense against cross-context nonce replay.
- **External-context capability checks are NEVER hardcoded in the Renderer.** The Renderer takes `$context['cap']` as an input and calls `current_user_can( $context['cap'] )`. Admin tab classes pass `'manage_options'`; a BuddyBoss integration might pass `'read'` (self-view) or a custom cap. The Renderer NEVER assumes `manage_options` — that would break external reuse.
- **The `acrossai_mcp_client_block_context` filter is the ONLY sanctioned extension point for context defaults.** Third-party plugins customize context via that filter — they never patch the Renderer classes directly. Documented in DEC-CLIENT-RENDERER-PUBLIC-API.
- **F012 settings toggles gate NpmClientBlock + ClaudeConnectorBlock render output.** When `acrossai_mcp_npm_login_enabled === false`, NpmClientBlock MUST render the "CLI connections are currently disabled" notice + a link to `?page=acrossai-settings&tab=mcp` INSTEAD of the config form. When `acrossai_mcp_claude_connectors_enabled === false`, ClaudeConnectorBlock MUST render the equivalent Claude-Connector disabled notice + link INSTEAD of the config form. Both blocks MUST still render their log ListTables (`CliAuthLogListTable` for npm, `ConnectorAuditLogListTable` for Claude Connector) below the gate — past events remain visible regardless of the current toggle state. The gate check lives INSIDE the Renderer (not the admin tab wrapper) so that BuddyBoss / WooCommerce / shortcode embeds honor the same admin-configured feature switch. `MCPClientsBlock` is NOT gated by either toggle — it always renders the full 8-client dispatch. Grep gate at TASK-8: `grep -rn 'acrossai_mcp_npm_login_enabled\|acrossai_mcp_claude_connectors_enabled' public/Renderers/` returns hits ONLY in `NpmClientBlock.php` and `ClaudeConnectorBlock.php` — not in `MCPClientsBlock.php` or `AbstractClientRenderer.php` bodies.
