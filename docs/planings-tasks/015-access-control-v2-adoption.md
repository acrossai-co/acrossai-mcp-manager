# Planning: Adopt `wpboilerplate/wpb-access-control` v2 — per-server access rules + MCP-boundary enforcement (Feature 015)

`composer.json:18` requires `wpboilerplate/wpb-access-control: ^2.0.0` (v2), but every consumer in
this plugin was written against v1's `::instance()` singleton API. v2 replaced that with a
public constructor `new AccessControlManager( $providers_filter, $table_slug )` (see
`vendor/wpboilerplate/wpb-access-control/src/AccessControlManager.php:57–76`). **Three call sites
will FATAL as soon as they fire**: `admin/Partials/ServerTabs/AccessControlTab.php:65` (opens on tab
click), `includes/REST/CliController.php:333` (fires on any `/servers` REST hit), and
`includes/Main.php:432` (commented-out dead code referencing a Phase 7 TODO block above line 374).
`includes/Activator.php:37–41` also **fails to create the AC table on activation** — v2 requires the
consumer plugin to call `RuleTable( $slug )->maybe_upgrade()`, which our Activator omits.

The port is **thin because the sibling plugin already solved it**. `acrossai-abilities-manager`
consumes v2 through a proven wrapper pattern (`AcrossAI_Abilities_Access_Control` — 158 LOC) that
this feature copy-adapts verbatim with our namespace + slug: constants `PROVIDERS_FILTER =
'acrossai_mcp_access_control_providers'` + `TABLE_SLUG = 'mcp_manager'`, singleton with
`is_available()` / `boot_manager()` / `get_manager()` / `register_rest_api()` /
`maybe_show_library_notice()`, and the sibling's clean `use WPBoilerplate\AccessControl\...` imports.
The sibling's Activator (`includes/AcrossAI_Activator.php:14+42`) shows the correct `RuleTable`
invocation. The sibling's runtime read pattern
(`AcrossAI_Ability_Override_Processor.php:417–427`) shows the canonical `get_manager()` →
`get_query()->get_rule()` → `user_has_access()` sequence.

The mcp-adapter package exposes a **pre-dispatch filter** exactly for MCP-boundary enforcement:
`apply_filters( 'mcp_adapter_pre_tool_call', $args, $tool_name, $mcp_tool, $server )` at
`vendor/wordpress/mcp-adapter/includes/Handlers/ToolsHandler.php:182`. Returning `WP_Error`
short-circuits execution with a denied MCP response. `$server->get_server_id()` gives us the
target server; `get_current_user_id()` gives us the user (mcp-adapter uses standard WordPress
cookie/OAuth, no custom bearer layer). A single `add_filter()` wired via
`Main::define_public_hooks()` — no vendor fork, no upstream PR, no core modification (§V
compliant).

Feature 012 must not regress: the uninstall opt-in gate
(`acrossai_mcp_uninstall_delete_data === 1`) MUST also purge the new access-control namespace via
`RuleQuery::purge_namespace('acrossai-mcp-manager')` and drop the
`{$wpdb->prefix}mcp_manager_access_control` table when the opt-in fires; preserve-by-default is
the F012 invariant. Feature 013 must not regress: the AccessControlTab shape stays a per-tab class
extending `AbstractServerTab`, but its render body converts to a **thin delegate** to a new
`public/Renderers/AccessControlBlock.php` (matches F013 DEC-CLIENT-RENDERER-PUBLIC-API precedent —
Npm/Clients/ClaudeConnector delegates). The v2 package ships **no admin UI**, so the block builds our
own using the built-in `WpRoleProvider` / `WpUserProvider` / `WpCapabilityProvider` (registered via
the providers filter) as form-field taxonomies. Fail-open when `is_available()` returns false — an
admin notice fires, tool calls pass through — matches the sibling plugin's DEC-PERM-CB pattern.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "access-control-v2-adoption"

# 2. Specify
/speckit.specify "Adopt wpboilerplate/wpb-access-control v2 by fixing 3 v1-API fatal call sites (AccessControlTab.php:65, CliController.php:333, Main.php:432 commented-out block), adding activation-time RuleTable('mcp_manager')->maybe_upgrade() setup in Activator.php, wiring runtime enforcement at both CliController /servers AND the MCP tool boundary via the mcp_adapter_pre_tool_call filter shipped by vendor/wordpress/mcp-adapter/includes/Handlers/ToolsHandler.php:182, and building a per-server AccessControl rule UI (WP role + WP user + WP capability pickers using v2's built-in WpRoleProvider/WpUserProvider/WpCapabilityProvider) that saves via RuleQuery::set_rule() with namespace='acrossai-mcp-manager' + key=\$server_slug.

Introduce an AcrossAI_MCP_Access_Control wrapper class under includes/AccessControl/ following the sibling acrossai-abilities-manager plugin's proven pattern verbatim (constants PROVIDERS_FILTER='acrossai_mcp_access_control_providers' + TABLE_SLUG='mcp_manager', singleton with is_available()/boot_manager()/get_manager()/register_rest_api()/maybe_show_library_notice() methods, private constructor, protected static \$instance = null; matching F012 SettingsMenu member ordering). The wrapper's boot_manager() lazily instantiates 'new AccessControlManager( self::PROVIDERS_FILTER, self::TABLE_SLUG )' — the correct v2 constructor signature — NEVER v1's ::instance() static.

Preserve F013 AccessControlTab.php shape as a thin delegate — the actual UI lives in a new AccessControlBlock under public/Renderers/ extending AbstractClientRenderer (matches F013 DEC-CLIENT-RENDERER-PUBLIC-API precedent — same shape as NpmClientBlock/MCPClientsBlock/ClaudeConnectorBlock). The block renders 3 provider pickers (WP roles multi-select via get_editable_roles(), WP users picker via wp_dropdown_users(), WP capabilities multi-select limited to a curated safe list — never 'manage_options' or 'edit_users'). Form save handler reads submitted values and calls RuleQuery::set_rule() for each populated provider; empty selections call clear_rule(). Third-party plugins can embed the UI via the same shortcode/action-hook/filter surface F013 established.

Runtime enforcement lives in two places: (1) fix CliController.php:333 to use \\AcrossAI_MCP_Manager\\Includes\\AccessControl\\AcrossAI_MCP_Access_Control::instance()->get_manager()->user_has_access() with fail-open on is_available()=false; (2) Loader-wire an 'mcp_adapter_pre_tool_call' filter callback in Main.php::define_public_hooks() that resolves \$server->get_server_id() to \$server_slug via MCPServerQuery::instance()->get_item(), then calls user_has_access(get_current_user_id(), 'acrossai-mcp-manager', \$server_slug), returning new WP_Error('acrossai_mcp_access_denied', __('...'), array('status'=>403)) on deny. Both sites fail-open when is_available() returns false.

Uninstall opt-in gate MUST purge the new namespace via RuleQuery::purge_namespace('acrossai-mcp-manager') AND drop the table via \$wpdb->query('DROP TABLE IF EXISTS \$prefix.mcp_manager_access_control') AND delete the version option wpb_ac_mcp_manager_db_version — all gated behind F012's existing 'acrossai_mcp_uninstall_delete_data === 1' check. Preserve-by-default is the invariant.

Every printf/sprintf uses ONE placeholder style per B16. Every translated string uses text domain 'acrossai-mcp-manager'. Every hook goes through Main::define_public_hooks() per A1 — no add_action/add_filter inside class bodies. No new npm deps, no vendor forks. Memory hygiene: capture DEC-ACCESS-CONTROL-V2-ADOPTION as the canonical v2 consumption pattern for the AcrossAI plugin family (wrapper class + PROVIDERS_FILTER/TABLE_SLUG constants + fail-open + admin notice) AND a shorter D-lesson codifying the mcp_adapter_pre_tool_call filter as the canonical MCP-boundary enforcement hook."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all four of
> these governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, hook registration rules
>    (§I A1), Before Commit Checklist.
> 2. The sibling plugin's canonical wrapper — read all three files
>    end-to-end:
>    - `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-abilities-manager/includes/Modules/Abilities/AcrossAI_Abilities_Access_Control.php`
>      (158 LOC — the wrapper class we copy-adapt)
>    - `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-abilities-manager/includes/AcrossAI_Activator.php:14+42`
>      (the `use RuleTable` import + `->maybe_upgrade()` call)
>    - `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-abilities-manager/includes/Modules/Abilities/AcrossAI_Ability_Override_Processor.php:366–380`
>      (canonical `set_rule()` write) + `:417–427` (canonical
>      `get_manager()->get_query()->get_rule()` + `user_has_access()` read)
> 3. The v2 package internals — read the public API surface end-to-end:
>    - `vendor/wpboilerplate/wpb-access-control/src/AccessControlManager.php`
>      — constructor signature `new AccessControlManager( $providers_filter,
>      $table_slug )` + access-hierarchy comment block (lines 30–36 —
>      admin always allowed, unauth denied, no-provider denied, provider
>      delegated)
>    - `vendor/wpboilerplate/wpb-access-control/src/Database/Rule/RuleQuery.php`
>      — `set_rule()` / `get_rule()` / `clear_rule()` / `purge_namespace()`
>    - `vendor/wpboilerplate/wpb-access-control/src/Database/Rule/RuleTable.php`
>      — BerlinDB Table subclass + `maybe_upgrade()`; table name pattern
>      `{$prefix}{$slug}_access_control`; version option
>      `wpb_ac_{$slug}_db_version`
>    - `vendor/wpboilerplate/wpb-access-control/src/AbstractProvider.php` +
>      `src/WpRoleProvider.php` + `src/WpUserProvider.php` +
>      `src/WpCapabilityProvider.php` — built-in providers we register via
>      the providers filter
> 4. The mcp-adapter filter site:
>    `vendor/wordpress/mcp-adapter/includes/Handlers/ToolsHandler.php:182`
>    — the exact 4-arg `apply_filters( 'mcp_adapter_pre_tool_call', $args,
>    $tool_name, $mcp_tool, $server )` signature we hook against + line 184
>    where a `WP_Error` return short-circuits execution.
>
> Every design decision — wrapper member ordering, escape-idiom choice,
> where to Loader-wire hooks, block-vs-tab responsibility — must be
> justified against the above. If a choice is not explicitly covered,
> default to the sibling plugin's `AcrossAI_Abilities_Access_Control`
> shape. Do not write code that would fail any DoD gate: PHPStan level 8,
> PHPCS, security review, all `__()` calls using text domain
> `'acrossai-mcp-manager'`.
>
> **Public API artifacts + regression grep-gates (before + after every TASK):**
>
> Zero-hit gate — v1 API remnants MUST NOT appear anywhere after TASK-3:
> ```
> grep -rn 'AccessControlManager::instance' --include='*.php' admin/ includes/ public/
> ```
> Expected: 0 hits (proves the v1→v2 migration is complete).
>
> Correct v2 constructor pattern — expect at least one hit in the new
> wrapper file only:
> ```
> grep -rn 'new AccessControlManager' --include='*.php' includes/
> ```
> Expected: exactly 1 hit at `includes/AccessControl/AcrossAI_MCP_Access_Control.php`.
>
> Activation-time table setup:
> ```
> grep -rn 'RuleTable.*maybe_upgrade' includes/Activator.php
> ```
> Expected: exactly 1 hit.
>
> MCP-boundary filter wired in Main.php:
> ```
> grep -rn "mcp_adapter_pre_tool_call" includes/Main.php
> ```
> Expected: exactly 1 hit (the Loader-wired `add_filter` call).
>
> Uninstall opt-in gate purges AC namespace:
> ```
> grep -rn 'purge_namespace' uninstall.php
> ```
> Expected: at least 1 hit.
>
> Legacy uppercase namespace regression check (inherited from F013):
> ```
> grep -rn 'ACROSSAI_MCP_MANAGER\\\\' --include='*.php' admin/ includes/ public/ acrossai-mcp-manager.php
> ```
> Expected: 0 hits both BEFORE and AFTER (regression prevention).
>
> **Preserved contract map:**
>
> | Contract | Value | Source |
> | --- | --- | --- |
> | Providers filter tag | `'acrossai_mcp_access_control_providers'` | new F015 constant `AcrossAI_MCP_Access_Control::PROVIDERS_FILTER` — mirrors sibling |
> | Table slug (drives table name, cache group, REST route prefix) | `'mcp_manager'` → `{$wpdb->prefix}mcp_manager_access_control` | new F015 constant `AcrossAI_MCP_Access_Control::TABLE_SLUG` |
> | Rule namespace | `'acrossai-mcp-manager'` | consistent across write (`set_rule`) + read (`user_has_access`) + purge |
> | Rule key | `$server_slug` (from `MCPServerQuery::instance()->get_item((int) $server_id)->server_slug`) | one row per server per provider option |
> | Fail-open on package absent | `is_available()` returns false → admin notice fires + tool calls pass through | matches sibling DEC-PERM-CB |
> | Nonce action (admin form) | `'acrossai_mcp_manager_server_' . (int) $server['id']` | F013 `AbstractServerTab::nonce_field()` — unchanged |
> | REST/MCP user identity | `get_current_user_id()` — standard WP cookie/OAuth | mcp-adapter uses no custom bearer layer |
> | Text domain | `'acrossai-mcp-manager'` | Constitution §II + plugin header |
> | PascalCase namespace root | `AcrossAI_MCP_Manager\Includes\AccessControl\` | F002 module structure |
> | Existing helpers to reuse (never re-implement) | `MCPServerQuery::instance()->get_item()` (F011); F013 `AbstractServerTab` + `AbstractClientRenderer` shared helpers; F012 uninstall opt-in gate check; sibling wrapper class (copy-adapt) | Prior features |
>
> ---
>
> **TASK-1 — Scaffold `includes/AccessControl/AcrossAI_MCP_Access_Control.php`**
>
> Files:
> - `includes/AccessControl/AcrossAI_MCP_Access_Control.php` (NEW)
>
> Namespace `AcrossAI_MCP_Manager\Includes\AccessControl`. Copy-adapt the
> sibling plugin's `AcrossAI_Abilities_Access_Control` class verbatim,
> substituting our providers filter tag + table slug + admin notice text.
> Singleton scaffolding matches F012's `SettingsMenu` (protected static
> `$instance = null;` → `public static function instance(): self` → `private
> function __construct() {}` per A2 + S6).
>
> Wrapper class shape:
> ```php
> namespace AcrossAI_MCP_Manager\Includes\AccessControl;
>
> use WPBoilerplate\AccessControl\AccessControlManager;
>
> defined( 'ABSPATH' ) || exit;
>
> final class AcrossAI_MCP_Access_Control {
>     protected static $instance = null;
>
>     public const PROVIDERS_FILTER = 'acrossai_mcp_access_control_providers';
>     public const TABLE_SLUG       = 'mcp_manager';
>
>     private $manager = null;
>
>     public static function instance(): self { ... }
>     private function __construct() {}
>
>     public function is_available(): bool {
>         return class_exists( AccessControlManager::class );
>     }
>
>     public function boot_manager(): void {
>         if ( ! $this->is_available() || $this->manager instanceof AccessControlManager ) {
>             return;
>         }
>         $this->manager = new AccessControlManager( self::PROVIDERS_FILTER, self::TABLE_SLUG );
>     }
>
>     public function get_manager(): ?AccessControlManager {
>         if ( ! $this->manager instanceof AccessControlManager ) {
>             $this->boot_manager();
>         }
>         return $this->manager;
>     }
>
>     public function register_rest_api(): void { ... }
>     public function maybe_show_library_notice(): void { ... }
>     public function gate_mcp_tool_call( array $args, string $tool_name, $mcp_tool, $server ) { ... }
> }
> ```
>
> The `gate_mcp_tool_call` method is the `mcp_adapter_pre_tool_call` filter
> callback (Loader-wired in TASK-6). It:
> 1. Returns `$args` unchanged when `is_available()` returns false (fail-open).
> 2. Resolves `$server_slug` from `$server->get_server_id()` via
>    `MCPServerQuery::instance()->get_item()` — returns `$args` unchanged if
>    the server row is not found (defensive fail-open).
> 3. Calls `$this->get_manager()->user_has_access( get_current_user_id(),
>    'acrossai-mcp-manager', $server_slug )`.
> 4. Returns `new WP_Error( 'acrossai_mcp_access_denied', __(
>    'You do not have permission to invoke tools on this MCP server.',
>    'acrossai-mcp-manager' ), array( 'status' => 403 ) )` on deny.
> 5. Returns `$args` unchanged on allow.
>
> **DoD**: `php -l` clean; PHPStan L8 zero errors; PHPCS zero errors, zero
> warnings; sibling-plugin file diff shows only namespace/constant/text
> changes (proves faithful copy-adapt, no accidental design drift).
>
> ---
>
> **TASK-2 — Activation-time table setup in `includes/Activator.php`**
>
> Files:
> - `includes/Activator.php` (MODIFY)
>
> Add `use WPBoilerplate\AccessControl\Database\Rule\RuleTable;` +
> `use AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control;`
> at the top. Inside `Activator::activate()` — after the 4 existing F011
> table calls (`MCPServerTable::instance()->maybe_upgrade()`, etc.) — add:
> ```php
> ( new RuleTable( AcrossAI_MCP_Access_Control::TABLE_SLUG ) )->maybe_upgrade();
> ```
> `RuleTable::maybe_upgrade()` is idempotent — safe on both fresh install +
> existing install upgrade paths (BerlinDB handles the version check).
>
> **DoD**: `wp plugin activate` on a fresh install creates the
> `{$wpdb->prefix}mcp_manager_access_control` table; option
> `wpb_ac_mcp_manager_db_version` set; PHPStan L8 + PHPCS green;
> `grep -rn 'RuleTable.*maybe_upgrade' includes/Activator.php` returns
> exactly 1 hit.
>
> ---
>
> **TASK-3 — Fix 3 v1-API fatal call sites**
>
> Files:
> - `includes/REST/CliController.php` (MODIFY line 333)
> - `admin/Partials/ServerTabs/AccessControlTab.php` (MODIFY)
> - `includes/Main.php` (MODIFY line 432 — delete dead comment)
>
> **CliController.php:333** — replace:
> ```php
> if ( class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' ) ) {
>     $acm     = \WPBoilerplate\AccessControl\AccessControlManager::instance();  // v1 — fatals
>     $allowed = $acm->user_has_access( $user_id, $ns, $route );
>     ...
> }
> ```
> with:
> ```php
> $ac      = \AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::instance();
> if ( $ac->is_available() ) {
>     $manager = $ac->get_manager();
>     $allowed = $manager->user_has_access( $user_id, 'acrossai-mcp-manager', $ns . '/' . $route );
>     if ( ! $allowed ) {
>         return new WP_REST_Response( array( 'servers' => array() ), 200 );
>     }
> }
> ```
> Rule key becomes `$ns . '/' . $route` to match the enforcement site's
> operator-visible target (matches per-server-slug convention: caller passes
> `$server_slug` there). Silent empty-array-on-deny stays — matches today's
> enumeration-defense semantics.
>
> **AccessControlTab.php** — refactor to a THIN DELEGATE to
> `public/Renderers/AccessControlBlock.php` (see TASK-4). Match the F013
> `NpmTab` / `ClientsTab` / `ClaudeConnectorTab` delegate shape:
> ```php
> protected function render_body( array $server ): void {
>     \AcrossAI_MCP_Manager\Public\Renderers\AccessControlBlock::instance()->render(
>         (int) $server['id'],
>         array(
>             'context'           => 'admin',
>             'cap'               => 'manage_options',
>             'submit_target_url' => $this->server_edit_url( $server, 'access-control' ),
>             'nonce_action'      => 'acrossai_mcp_manager_server_' . (int) $server['id'],
>         )
>     );
> }
> ```
> All `render_admin_page()` / `method_exists()` speculation gone. The v1-API
> `AccessControlManager::instance(...)` call at line 65 is deleted.
>
> **Main.php:432** — delete the commented-out `//
> $access_control = \WPBoilerplate\AccessControl\AccessControlManager::instance(
> 'acrossai_mcp_access_control_providers' );` line and the empty
> `if ( class_exists( ... ) ) { /* TODO Phase 7 */ }` block above line 374.
> Both are replaced by the real Loader wiring added in TASK-6.
>
> **DoD**: `grep -rn 'AccessControlManager::instance' --include='*.php'
> admin/ includes/ public/` returns **zero hits**; PHPStan L8 + PHPCS green;
> `AccessControlTab.php` content grep for `<form method="post"` +
> `wp_nonce_field(` + raw HTML remains 0 (proves thin-delegate shape per
> F013 FR-026).
>
> ---
>
> **TASK-4 — Ship `public/Renderers/AccessControlBlock.php` (per-server rule UI)**
>
> Files:
> - `public/Renderers/AccessControlBlock.php` (NEW)
>
> Extends `AbstractClientRenderer` per F013 DEC-CLIENT-RENDERER-PUBLIC-API.
> Singleton (protected static `$instance = null;` → `public static function
> instance(): self` → `private function __construct() {}`). Docblock cites
> `@since 0.0.7 @experimental May change without notice before 1.0.0`.
>
> `render_body( array $server, array $context ): void` shape:
> 1. Fail-open check — if `AcrossAI_MCP_Access_Control::instance()->is_available()`
>    is false, render an info notice ("Access Control is inactive because the
>    wpb-access-control library is not loaded. Tool calls pass through
>    unrestricted.") and return.
> 2. Load current rule for this server via
>    `$manager->get_query()->get_rule( 'acrossai-mcp-manager', (string) $server['server_slug'] )`.
> 3. Emit `<h2>` heading and description text ("Restrict which WordPress
>    users can invoke MCP tools on this server. Rules are enforced at both
>    the /servers listing endpoint and every MCP tool call.").
> 4. Emit the form via `AbstractServerTab::open_form()` — but this Block is
>    not a tab, so instead emit a `<form method="post" action="...">` directly
>    with `wp_nonce_field( $context['nonce_action'] )` and hidden inputs
>    `page` + `action=save_access_control` + `server=$server_id`.
> 5. Render `<table class="form-table" role="presentation">` with 3 rows:
>    - **Allowed WP Roles** — multi-checkbox list from `get_editable_roles()`.
>      Pre-check any roles already saved. Name attribute
>      `acrossai_mcp_ac_wp_role[]`.
>    - **Allowed WP Users** — pre-populated user IDs displayed as `<code>`
>      tags with remove buttons + an autocomplete field (uses core WP
>      `wp-user-search` REST endpoint). Name attribute
>      `acrossai_mcp_ac_wp_user[]`.
>    - **Allowed WP Capabilities** — multi-checkbox list from a curated
>      allow-list constant `AcrossAI_MCP_Access_Control::SAFE_CAPABILITIES`
>      (e.g., `edit_posts`, `publish_posts`, `read`, `moderate_comments`,
>      `manage_categories`, `upload_files`). NEVER `manage_options` or
>      `edit_users`. Name attribute `acrossai_mcp_ac_wp_capability[]`.
> 6. `submit_button( __( 'Save Access Rules', 'acrossai-mcp-manager' ) )` +
>    `<button type="submit" name="acrossai_mcp_ac_clear" value="1">Clear
>    Rules</button>`.
> 7. Close the form.
>
> Save handler lives in `includes/Main.php`'s existing admin_init handler
> (or a small new one Loader-wired in TASK-6) — reads submitted values,
> verifies nonce + cap, then calls `$manager->get_query()->set_rule()` for
> each populated provider or `->clear_rule()` on the Clear button.
>
> **DoD**: Form saves round-trip; rules appear in the
> `{$wpdb->prefix}mcp_manager_access_control` table; PHPStan L8 + PHPCS
> green. Manual smoke: save a rule with `wp_role=editor`, verify a POST
> against `/servers` from a subscriber returns empty `servers` array;
> upgrade subscriber to editor, verify same POST returns the server row.
>
> ---
>
> **TASK-5 — Register the 3 built-in providers via the providers filter**
>
> Files:
> - `includes/AccessControl/AcrossAI_MCP_Access_Control.php` (MODIFY —
>   TASK-1 stub)
>
> Extend TASK-1's wrapper with a static
> `register_default_providers( array $providers ): array` method:
> ```php
> public static function register_default_providers( array $providers ): array {
>     $providers[] = new \WPBoilerplate\AccessControl\WpRoleProvider();
>     $providers[] = new \WPBoilerplate\AccessControl\WpUserProvider();
>     $providers[] = new \WPBoilerplate\AccessControl\WpCapabilityProvider();
>     return $providers;
> }
> ```
> Loader-wire in TASK-6 as `add_filter( self::PROVIDERS_FILTER, ...,
> 'register_default_providers' )`. Third-party plugins can `add_filter` on
> the same tag to append their own providers (e.g., BuddyBoss profile-type
> provider, MemberPress membership provider — both already shipped by v2
> as optional classes).
>
> **DoD**: `$manager->user_has_access( $user_id, 'acrossai-mcp-manager',
> $slug )` resolves correctly against a saved `wp_role` / `wp_user` /
> `wp_capability` rule; PHPStan L8 + PHPCS green.
>
> ---
>
> **TASK-6 — Wire everything in `Main.php::define_public_hooks()`**
>
> Files:
> - `includes/Main.php` (MODIFY)
>
> Add the following Loader wiring inside `define_public_hooks()` per A1
> (all hooks live here, not in class bodies):
> ```php
> // Feature 015 — Access Control v2 adoption
> $access_control = \AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::instance();
> $this->loader->add_action( 'init',              $access_control, 'boot_manager', 5 );
> $this->loader->add_action( 'rest_api_init',     $access_control, 'register_rest_api' );
> $this->loader->add_action( 'admin_notices',     $access_control, 'maybe_show_library_notice' );
> $this->loader->add_filter( 'mcp_adapter_pre_tool_call', $access_control, 'gate_mcp_tool_call', 10, 4 );
> $this->loader->add_filter(
>     \AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::PROVIDERS_FILTER,
>     '\\AcrossAI_MCP_Manager\\Includes\\AccessControl\\AcrossAI_MCP_Access_Control',
>     'register_default_providers'
> );
> // AccessControlBlock save handler (fires on POST via admin_init like save_claude_connector)
> $this->loader->add_action( 'admin_init', $settings, 'handle_actions', 5 );  // already present — extend to handle save_access_control
> ```
> The save handler for `save_access_control` action is added to the
> existing `Settings::handle_actions()` method (matches F013 pattern for
> `save_claude_connector`). It reads submitted values, verifies nonce +
> cap, then calls
> `$access_control->get_manager()->get_query()->set_rule()` per provider or
> `->clear_rule()` on the Clear button.
>
> **DoD**: `grep -rn 'mcp_adapter_pre_tool_call' includes/Main.php`
> returns exactly 1 hit; all 4 hook registrations wired via the Loader
> (no `add_action`/`add_filter` in class bodies per A1); PHPStan L8 +
> PHPCS green. Manual smoke: save a `wp_role=editor` rule for server X,
> then POST an MCP tool call as a subscriber — the response is an MCP
> protocol error with `access_denied`. Same POST as an editor succeeds.
>
> ---
>
> **TASK-7 — Uninstall opt-in gate purges AC namespace + drops table**
>
> Files:
> - `uninstall.php` (MODIFY)
>
> After F012's opt-in gate confirms `acrossai_mcp_uninstall_delete_data ===
> 1`, add the following block BEFORE the existing F011 table drops:
> ```php
> // Feature 015 — Access Control cleanup (opt-in only).
> if ( class_exists( '\WPBoilerplate\AccessControl\Database\Rule\RuleQuery' ) ) {
>     $rule_query = new \WPBoilerplate\AccessControl\Database\Rule\RuleQuery( 'mcp_manager' );
>     $rule_query->purge_namespace( 'acrossai-mcp-manager' );
> }
> $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}mcp_manager_access_control`" );
> delete_option( 'wpb_ac_mcp_manager_db_version' );
> ```
> `purge_namespace()` is defensive — even if the table is about to be
> dropped, running the row-by-row delete first purges any BerlinDB cache
> for the namespace so no stale entries linger post-DROP. The `class_exists`
> guard handles the "package uninstalled before this plugin" edge case.
>
> **DoD**: Uninstall with opt-in (`$data_delete_opt_in === 1`) → the AC
> table is dropped + version option deleted + purge_namespace fires;
> uninstall without opt-in → table preserved, version option preserved;
> `grep -rn 'purge_namespace' uninstall.php` returns at least 1 hit.
>
> ---
>
> **TASK-8 — PHPUnit coverage**
>
> Files:
> - `tests/phpunit/Includes/AccessControl/AcrossAI_MCP_Access_Control_Test.php`
>   (NEW)
>
> Docblock cites `BUGS.md B9` (use `#[DataProvider]` attribute per PHPUnit
> 13+). Test methods:
> 1. `test_is_available_true_when_package_present` — asserts
>    `class_exists( AccessControlManager::class )` path.
> 2. `test_is_available_false_when_package_absent` — mocks
>    `class_exists()` via runkit or symbol table override; asserts
>    `is_available()` returns false + `get_manager()` returns null.
> 3. `test_boot_manager_creates_v2_instance_with_correct_slug_and_filter`
>    — asserts the internal `$manager` property is an
>    `AccessControlManager` instance constructed with
>    `PROVIDERS_FILTER` + `TABLE_SLUG` values (verified via reflection).
> 4. `test_gate_mcp_tool_call_returns_args_unchanged_when_no_rule` —
>    mocks `MCPServerQuery::instance()->get_item()` to return a server
>    row; asserts filter returns `$args` when no rule is configured
>    (fail-open on no-rule).
> 5. `test_gate_mcp_tool_call_returns_wp_error_when_denied` — sets up a
>    rule that denies the current user; asserts filter returns
>    `WP_Error` with `'acrossai_mcp_access_denied'` code +
>    `array('status'=>403)` data.
> 6. `test_gate_mcp_tool_call_returns_args_when_package_missing` — with
>    `is_available()` false, asserts filter returns `$args` (fail-open).
> 7. `test_register_default_providers_returns_3_providers` — asserts the
>    static returns an array with 3 provider instances of the correct
>    concrete classes.
>
> **DoD**: All 7 tests green under `vendor/bin/phpunit --testsuite admin
> --bootstrap tests/bootstrap-wp.php --filter
> AcrossAI_MCP_Access_Control_Test`; PHPStan L8 + PHPCS green on the test
> file.
>
> ---
>
> **TASK-9 — Memory hygiene + changelog + docs**
>
> Files:
> - `docs/memory/DECISIONS.md` (APPEND)
> - `docs/memory/INDEX.md` (APPEND rows)
> - `docs/planings-tasks/README.md` (APPEND F015 row)
> - `README.txt` Unreleased section (APPEND bullet)
>
> **`docs/memory/DECISIONS.md`** — append 2 entries:
>
> `### YYYY-MM-DD — DEC-ACCESS-CONTROL-V2-ADOPTION`
>
> Rule: any AcrossAI-family plugin consuming
> `wpboilerplate/wpb-access-control ^2.0.0` MUST wrap the v2
> `AccessControlManager` in a plugin-scoped singleton wrapper class with:
> - `PROVIDERS_FILTER` class constant (plugin-specific hook tag)
> - `TABLE_SLUG` class constant (drives DB table name, cache group, REST
>   route prefix — MUST match `^[a-z0-9_]{1,32}$` per v2 Slug::PATTERN)
> - `is_available()` guard (fail-open when package class absent — matches
>   sibling plugin's DEC-PERM-CB pattern)
> - `boot_manager()` lazy-init with `new AccessControlManager(
>   PROVIDERS_FILTER, TABLE_SLUG )`
> - `get_manager(): ?AccessControlManager` accessor
> - `register_rest_api()` REST route registration delegate
> - `maybe_show_library_notice()` admin notice on package absence
> - `Activator` MUST call `(new RuleTable(TABLE_SLUG))->maybe_upgrade()`
>   at plugin activation
> - `uninstall.php` MUST purge the namespace + drop the table + delete the
>   version option — but ONLY when the plugin-specific opt-in gate fires
>   (preserve-by-default is the F012 pattern)
> - The 3 built-in providers (`WpRoleProvider`, `WpUserProvider`,
>   `WpCapabilityProvider`) MUST be registered via
>   `add_filter( PROVIDERS_FILTER, ..., 'register_default_providers' )`
>
> Canonical for F015 + any future v2 adoption. Sibling plugin
> `acrossai-abilities-manager`'s `AcrossAI_Abilities_Access_Control` is
> the same-shape reference. Fail-open is intentional — package absence
> is treated as "no rules configured" (matches WP core's graceful
> degradation ethos). Never fail-closed silently.
>
> `### YYYY-MM-DD — D18 — `mcp_adapter_pre_tool_call` is the canonical
> MCP-boundary enforcement hook`
>
> Rule: any AcrossAI-family plugin wanting to gate MCP tool invocations
> based on `(user_id, server_id)` MUST hook the `mcp_adapter_pre_tool_call`
> filter fired by `vendor/wordpress/mcp-adapter/includes/Handlers/ToolsHandler.php:182`.
> Signature: `apply_filters( 'mcp_adapter_pre_tool_call', $args, $tool_name,
> $mcp_tool, $server )`. Return `WP_Error` with `array('status'=>403)` to
> short-circuit execution with a denied MCP response. Do NOT fork
> mcp-adapter to add a new hook — this one exists specifically for this
> use case. Do NOT try to hook the ability's `permission_callback` — those
> are ability-scoped and don't compose cleanly for cross-cutting
> per-server enforcement. Loader-wire via `Main::define_public_hooks()`
> per A1.
>
> **`docs/memory/INDEX.md`** — append 1 DEC row (`DEC-ACCESS-CONTROL-V2-ADOPTION`,
> tags `access-control, v2-adoption, wrapper, table-slug, fail-open,
> mcp-boundary`, Active F015) + 1 D-row (`D18 — mcp_adapter_pre_tool_call
> filter`, tags `mcp-adapter, enforcement-hook, filter, cross-cutting`,
> Active F015).
>
> **`docs/planings-tasks/README.md`** — append F015 row alongside F013.
>
> **`README.txt`** — Unreleased changelog bullet:
> > `* Adopted wpboilerplate/wpb-access-control v2 with per-server access
> > rules, MCP-boundary enforcement via the mcp_adapter_pre_tool_call
> > filter, and a shared Renderer block (AccessControlBlock) that
> > third-party plugins can embed on their own admin surfaces. Fixes 3
> > fatal v1-API call sites (AccessControlTab, CliController /servers,
> > Main.php TODO block). Activator now creates the
> > {prefix}mcp_manager_access_control table; uninstall opt-in gate
> > purges the namespace + drops the table. Wrapper class matches the
> > sibling acrossai-abilities-manager plugin's proven pattern.`
>
> **DoD**: `grep -c 'DEC-ACCESS-CONTROL-V2-ADOPTION' docs/memory/INDEX.md`
> returns 1; `grep -c '015-access-control-v2-adoption'
> docs/planings-tasks/README.md` returns at least 1; markdown files still
> parse cleanly (no broken tables).

---

**CONSTRAINTS**

- Do not modify vendor code inside
  `vendor/wpboilerplate/wpb-access-control/` or `vendor/wordpress/mcp-adapter/`.
- Do not use v1's `::instance()` API anywhere. Only `new AccessControlManager(
  $providers_filter, $table_slug )`. Grep-gate enforces this after TASK-3.
- Do not add capability providers that grant access to `manage_options` or
  `edit_users` capabilities. The WP Capability picker MUST be limited to a
  curated safe list (`AcrossAI_MCP_Access_Control::SAFE_CAPABILITIES`
  constant enumerated in TASK-4).
- Do not couple the wrapper class to `MCPServerQuery` inside `boot_manager()`
  — resolve `$server_slug` inside `gate_mcp_tool_call()` only (defer the DB
  call until the filter fires per DEC-BERLINDB-TABLE-REQUEST-BOOT F011).
- Do not add `add_action` / `add_filter` calls inside the wrapper's class
  body — every hook goes through `Main::define_public_hooks()` per A1. The
  `mcp_adapter_pre_tool_call` filter, the providers filter, the REST
  registration, and the admin notice are ALL Loader-wired.
- Do not hardcode `manage_options` inside `AccessControlBlock::render_body()`
  — use `$context['cap']` per F013 SEC-013-005 pattern (BuddyBoss embed may
  legitimately need `cap='read'`).
- Do not touch the F013 `AccessControlTab.php` beyond converting it to a
  thin delegate to `AccessControlBlock` — its shape (extends
  `AbstractServerTab`) is preserved per D8 + DEC-CLIENT-RENDERER-PUBLIC-API.
- Do not use `printf` with mixed positional (`%s`) + numbered (`%1$s`)
  placeholders in one call per B16.
- Do not port the sibling plugin's REST controller wiring verbatim without
  reading the sibling's `Main.php::define_admin_hooks()` — its REST route
  registration timing (`rest_api_init` priority 10) matters for v2's
  session cache group initialization.
- Do not weaken the F012 uninstall opt-in gate: TASK-7's `purge_namespace()`
  + `DROP TABLE` MUST be gated behind the existing
  `acrossai_mcp_uninstall_delete_data === 1` check. Preserve-by-default is
  the invariant.
- Every printf/sprintf uses ONE placeholder style.
- Text domain `'acrossai-mcp-manager'` on every translated string.
- PascalCase namespace `AcrossAI_MCP_Manager\Includes\AccessControl\`
  (matches F002 module structure).
