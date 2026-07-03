# Planning: Add MCP Settings Tab to Shared AcrossAI Settings Page (Feature 012)

Register an "MCP" tab on the shared `?page=acrossai-settings` admin surface owned
by the `acrossai-co/main-menu` vendor package (installed via Feature 010), and
persist THREE toggles under it via the WordPress Settings API:

- `acrossai_mcp_npm_login_enabled` (boolean) — Front-end CLI login flow (FrontendAuth), default off.
- `acrossai_mcp_claude_connectors_enabled` (boolean) — Direct Claude Connectors mode (ClaudeConnectors), default off.
- `acrossai_mcp_uninstall_delete_data` (int 0/1) — Delete-all-data-on-uninstall opt-in, default 0. When 1, uninstall.php drops all four `wp_acrossai_mcp_*` tables, deletes all `acrossai_mcp_*` options, and clears the `acrossai_mcp_oauth_cleanup` scheduled hook. Matches the sibling `acrossai-abilities-manager` `acrossai_abilities_uninstall_delete_data` opt-in pattern verbatim.

**Behavior change**: the current `uninstall.php` unconditionally drops the two OAuth tables (`oauth_tokens`, `oauth_audit`) and their `db_version` options per Feature 006's "destructive-by-nature" scope. Feature 012 migrates that behavior under the new opt-in flag. The new default is **preserve-everything** on uninstall — matching the sibling plugin's convention and matching WordPress best practices (never delete user data without explicit consent). Sites that expected the OAuth-table wipe get a one-line changelog notice + a clear checkbox to restore the old behavior.

This feature ALSO removes the standalone "CLI Auth Log" admin submenu at `?page=acrossai_mcp_manager_cli_auth_log`. The page was a read-only `WP_List_Table` view over the `wp_acrossai_mcp_cli_auth_logs` table; post-Feature-011 that inspection surface no longer belongs in a top-level admin submenu. The underlying table + Query/Row classes remain — they are still consumed at runtime by the OAuth authentication flow (`includes/OAuth/Storage.php`, `includes/OAuth/BearerAuth.php`, `includes/REST/CliController.php`, `includes/Database/CliAuthLog/Recorder.php`). Only the ADMIN SURFACE is deleted. Auth-log inspection moves to WP-CLI (`wp db query "SELECT ... FROM wp_acrossai_mcp_cli_auth_logs"`) or a future per-server tab.

The sibling plugin `acrossai-abilities-manager` (Feature 038-onward) already
consumes the same vendor tab API via `admin/Partials/SettingsMenu.php` for its
"Abilities" tab. This feature adopts the same pattern verbatim so the two
AcrossAI plugins remain family-consistent and the vendor filter contract
(`acrossai_settings_tabs`) is exercised the same way from both consumers.

The upstream helper this feature consumes — `\AcrossAI_Main_Menu\SettingsPage::tab_page_slug()`
+ the `acrossai_settings_tabs` filter — is documented in
`vendor/acrossai-co/main-menu/README.md` sections 133–207. The vendor's own
`PageRenderer::render()` calls `settings_fields( 'acrossai-settings' )` and
routes Save through `options.php`, so this feature MUST use `'acrossai-settings'`
as the **shared** `register_setting()` option group — NOT the per-tab page slug —
or the shared Save button will not persist this tab's fields.

This feature is **additive**: no existing table, option, class, or hook is renamed
or removed EXCEPT the dead stub `Settings::register_settings()` (empty method
with a TODO comment "US3 T020 ports the full register_setting / add_settings_section
/ field calls") and its Loader wiring line in `Main.php` — that stub IS being
ported (its intent WAS this feature). The option-key names
(`acrossai_mcp_npm_login_enabled`, `acrossai_mcp_claude_connectors_enabled`) are
adopted VERBATIM from a pre-Feature-011 sibling copy of the same plugin at
`/Users/raftaar1191/local-sites/wordpress-ai/app/public/wp-content/plugins/acrossai-mcp-manager/src/Admin/Settings.php:395-445`
so any install that ever used the sibling build inherits its existing options
in-place with no data migration.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "mcp-settings-tab"

# 2. Specify
/speckit.specify "Register a new 'MCP' tab on the shared ?page=acrossai-settings
admin page owned by the acrossai-co/main-menu vendor package. The tab persists
THREE toggles via the WordPress Settings API: acrossai_mcp_npm_login_enabled
(boolean, default false, gates the front-end CLI login flow in FrontendAuth),
acrossai_mcp_claude_connectors_enabled (boolean, default false, gates the
experimental direct Claude Connectors mode in ClaudeConnectors), and
acrossai_mcp_uninstall_delete_data (int 0/1, default 0, opts into destructive
uninstall — when 1, uninstall.php drops all four wp_acrossai_mcp_* tables plus
all acrossai_mcp_* options plus the acrossai_mcp_oauth_cleanup scheduled hook).
Migrate the current uninstall.php's unconditional OAuth-table drops behind this
new opt-in gate so preserve-everything becomes the default on uninstall,
matching the sibling acrossai-abilities-manager pattern verbatim.
Follow the sibling acrossai-abilities-manager Feature-038 pattern verbatim
(admin/Partials/SettingsMenu.php lines 1-221) — same class shape (non-final
class SettingsMenu, singleton with $instance var + instance() method + private
ctor, TAB_SLUG const declared AFTER the singleton scaffolding), same filter
hook (acrossai_settings_tabs), same register_settings() shape (register_setting
against the SHARED option group 'acrossai-settings' with just sanitize_callback +
default — no 'type' key; add_settings_section + add_settings_field against the
per-tab page slug returned by \\AcrossAI_Main_Menu\\SettingsPage::tab_page_slug(
self::TAB_SLUG ) called UNCONDITIONALLY — the sibling does NOT wrap this in
class_exists() because the vendor package is a hard require in composer.json).
Render fields via printf with %s formatters + esc_html__() / checked() /
esc_attr() — matches sibling render_uninstall_field() at lines 212-220. Wire the
class via includes/Main.php Loader per A1 (add_filter on acrossai_settings_tabs +
add_action on admin_init). Delete the existing dead stub
Settings::register_settings() (empty method at admin/Partials/Settings.php lines
~406-413 with the comment 'US3 T020 ports the full register_setting ...') plus
its Loader wiring line in Main.php — that stub IS this feature. Add
AdminPageSlugs::SETTINGS_TAB = 'mcp' constant and extend plugin_screen_ids()
with 'acrossai_page_acrossai-settings' (defensive whitelist for future asset
enqueue on the settings tab per A9). Add a PHPUnit test at
tests/phpunit/Admin/SettingsMenuTest.php locking two invariants: (a) register_tab
appends the expected shape [slug=>mcp, label=>MCP, priority=>20], (b)
register_tab normalizes non-array input. Do not touch vendor. Do not extract new
URL helper methods on ClaudeConnectors — use inline home_url() / rest_url()
fallbacks for the three OAuth URLs displayed in the Claude Connectors section's
warning notice: authorize URL home_url('/acrossai-mcp-connectors/oauth/authorize/'),
token endpoint rest_url('acrossai-mcp-manager/v1/connector/oauth/token'), AS
metadata home_url('/.well-known/oauth-authorization-server'). These describe the
future Connector OAuth surface, NOT the existing CLI OAuth flow at
ClaudeConnectors::serve_as_metadata() lines 132-134 (marked with TODO follow-up
for the future Connector OAuth surface feature). Preserve the two option-key names verbatim from
the sibling wordpress-ai copy at src/Admin/Settings.php:395-445 so any existing
site using that build inherits its options in-place. Do not rename
admin/Partials/Settings.php (the list-page controller) even though the new
SettingsMenu.php sits next to it — coexistence matches the sibling
acrossai-abilities-manager layout. Memory hygiene: capture DEC-VENDOR-SETTINGS-
TAB-INTEGRATION as the durable pattern (acrossai_settings_tabs filter +
SettingsPage::tab_page_slug() helper + shared 'acrossai-settings' option group +
sibling-style class member ordering) tagged 'active' with a note that this
deviates from §IV DataForm mandate because the shared page's own PageRenderer is
a Settings API surface (accepted DEV carve-out matching the vendor package's
contract). Also remove the CLI Auth Log admin submenu at
?page=acrossai_mcp_manager_cli_auth_log: delete admin/Partials/CliAuthLogListTable.php
entirely, delete the add_submenu_page block at admin/Partials/Menu.php:87-96,
delete the AdminPageSlugs::CLI_AUTH_LOG const + its 2 plugin_screen_ids()
entries, and delete Settings::render_cli_auth_log_page() (with its
use ...\\CliAuthLogListTable; import). Preserve every file under
includes/Database/CliAuthLog/** — the OAuth flow still uses the table + Query/Row
classes at runtime (Storage.php, BearerAuth.php, CliController.php, Recorder.php)."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all four of
> these governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, hook registration rules,
>    Before Commit Checklist.
> 2. The sibling plugin's reference for the target pattern:
>    `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-abilities-manager/admin/Partials/SettingsMenu.php`
>    (Feature 038-onward). Lines 68-161 show the canonical `TAB_SLUG` const +
>    `register_tab()` + `register_settings()` + section-description render
>    methods that this feature mirrors.
> 3. Vendor package README at
>    `vendor/acrossai-co/main-menu/README.md` sections 133-207 — documents
>    the `acrossai_settings_tabs` filter shape (`['slug', 'label', 'priority',
>    optional 'capability']`) and the `SettingsPage::tab_page_slug()` helper
>    contract. Also read `vendor/acrossai-co/main-menu/src/SettingsPage.php`
>    (~40 lines) for the exact helper signature + constants.
> 4. Sibling wordpress-ai copy of the target content:
>    `/Users/raftaar1191/local-sites/wordpress-ai/app/public/wp-content/plugins/acrossai-mcp-manager/src/Admin/Settings.php`
>    lines 395-560. This is the pre-Feature-011 flat-namespace copy — do NOT
>    port its class shape (obsolete namespace `ACROSSAI_MCP_MANAGER\Admin`,
>    old submenu-page registration model). Port ONLY the two `register_setting`
>    calls, the two `add_settings_section` calls, the four render-method
>    bodies (`render_npm_section_description`, `render_npm_login_field`,
>    `render_claude_connectors_section_description`,
>    `render_claude_connectors_enabled_field`), and the exact option-key names.
>
> Every decision — class location, method signatures, output-escaping choices,
> URL fallback derivations — must be justified against the above. If a choice
> is not explicitly covered, default to the sibling
> `acrossai-abilities-manager/admin/Partials/SettingsMenu.php` shape. Do not
> write code that would fail any Definition-of-Done gate: PHPStan level 8,
> PHPCS, security review, all `__()` calls using the correct text domain
> `'acrossai-mcp-manager'`.
>
> **Public API artifacts to preserve verbatim (grep-gate before + after):**
>
> - Option key: `acrossai_mcp_npm_login_enabled` (boolean, default false)
> - Option key: `acrossai_mcp_claude_connectors_enabled` (boolean, default false)
> - Option key: `acrossai_mcp_uninstall_delete_data` (int 0/1, default 0)
> - Removed page slug: `acrossai_mcp_manager_cli_auth_log` — this string
>   MUST NOT reappear anywhere in the plugin after TASK-6 lands. Grep-verified
>   zero after every subsequent task.
>
> **Pre-flight grep** (records any existing consumer that reads the three
> option keys — behavior must be unchanged after the migration):
> ```
> grep -rEn "acrossai_mcp_npm_login_enabled|acrossai_mcp_claude_connectors_enabled|acrossai_mcp_uninstall_delete_data" \
>     --include='*.php' \
>     includes/ admin/ public/ acrossai-mcp-manager.php uninstall.php
> ```
> Every hit that surfaces here (e.g., a `get_option( 'acrossai_mcp_npm_login_enabled' )`
> gate somewhere in `FrontendAuth` or `ClaudeConnectors`) MUST still resolve
> to the same value type after every TASK. Any grep result that would break
> (e.g., wrong default, wrong type) requires a caller-side follow-up out of
> Feature 012 scope. `acrossai_mcp_uninstall_delete_data` is expected to
> appear ONLY in `SettingsMenu.php` (writer/renderer) and `uninstall.php`
> (reader/gate) after this feature lands — no other file may consume it.
>
> **Second pre-flight grep** — records every reference to the CLI Auth Log
> admin surface. MUST return one or more hits BEFORE TASK-6 and MUST return
> **zero hits** AFTER TASK-6:
> ```
> grep -rEn "acrossai_mcp_manager_cli_auth_log|CliAuthLogListTable|CLI_AUTH_LOG|render_cli_auth_log_page" \
>     --include='*.php' \
>     includes/ admin/ public/ acrossai-mcp-manager.php
> ```
> Expected pre-flight hits (per Explore inventory, verify before deletion):
> `admin/Partials/CliAuthLogListTable.php` (whole file), `admin/Partials/Menu.php`
> (submenu block), `includes/Utilities/AdminPageSlugs.php` (const + 2
> whitelist entries), `admin/Partials/Settings.php` (use import + render
> method). Zero hits post-TASK-6 is the completion signal.
>
> **Companion grep** — records references to the CliAuthLog DB layer, which
> MUST BE PRESERVED. Hit count MUST match before and after TASK-6:
> ```
> grep -rEn "CliAuthLogQuery|CliAuthLogRow|CliAuthLog\\\\Table|CliAuthLog\\\\Recorder|use .*CliAuthLog\\\\" \
>     --include='*.php' \
>     includes/ admin/ public/ acrossai-mcp-manager.php
> ```
> Expected callers (all preserved): `includes/OAuth/Storage.php`,
> `includes/OAuth/BearerAuth.php`, `includes/REST/CliController.php`,
> `includes/Database/CliAuthLog/Recorder.php`, `includes/Activator.php`,
> `includes/Main.php`. Any DROP in the hit count for this grep indicates
> accidental DB-layer damage and MUST be reverted before merge.
>
> **Preserved contract map:**
>
> | Contract | Value | Source |
> | --- | --- | --- |
> | Tab slug | `mcp` | This feature — new; kept in sync in `AdminPageSlugs::SETTINGS_TAB` const |
> | Tab label | `__( 'MCP', 'acrossai-mcp-manager' )` | This feature — new |
> | Tab priority | `20` | Sorts AFTER sibling `acrossai-abilities-manager` tab (priority 10) |
> | Option group | `'acrossai-settings'` | Vendor package README section 168-180 — MUST be the shared slug |
> | Per-tab page slug | `SettingsPage::tab_page_slug( 'mcp' )` → `'acrossai-settings-mcp'` | Vendor package `SettingsPage.php:28-32` |
> | Sanitize callback (2 boolean toggles) | `'rest_sanitize_boolean'` | Sibling wordpress-ai copy `src/Admin/Settings.php:397, 428` |
> | Sanitize callback (uninstall flag) | `array( $this, 'sanitize_uninstall_flag' )` returning `empty( $value ) ? 0 : 1` | Sibling `acrossai-abilities-manager/admin/Partials/SettingsMenu.php:202-204` |
> | Uninstall option default | int `0` (preserve everything on uninstall) | Sibling `acrossai-abilities-manager/admin/Partials/SettingsMenu.php:116` |
> | Text domain | `'acrossai-mcp-manager'` | Constitution §II + plugin header |
>
> ---
>
> **TASK-1 — Create `SettingsMenu` class**
>
> Files: `admin/Partials/SettingsMenu.php` (NEW)
>
> Read the sibling
> `acrossai-abilities-manager/admin/Partials/SettingsMenu.php`
> (lines 1-221) and the vendor `SettingsPage.php` (all ~40 lines) BEFORE
> editing. **Match the sibling's class structure, member ordering, and
> Settings API call shape verbatim** — deviations from the sibling shape
> require justification against the vendor package's `PageRenderer::render()`
> contract.
>
> Class shell — namespace + defined-guard + sibling-style member ordering
> (`$instance` var → `instance()` method → `__construct()` → `TAB_SLUG`
> const → `register_tab()` → `register_settings()` → render methods; note the
> `TAB_SLUG` const is declared AFTER the singleton scaffolding, matching
> sibling line 68):
>
> ```php
> <?php
> /**
>  * MCP tab on the shared AcrossAI Settings page.
>  *
>  * @package    AcrossAI_MCP_Manager
>  * @subpackage Admin/Partials
>  * @since      0.1.0
>  */
>
> namespace AcrossAI_MCP_Manager\Admin\Partials;
>
> // Exit if accessed directly.
> defined( 'ABSPATH' ) || exit;
>
> class SettingsMenu {
>
>     /**
>      * Singleton instance.
>      *
>      * @var SettingsMenu|null
>      */
>     protected static $instance = null;
>
>     public static function instance(): self {
>         if ( null === self::$instance ) {
>             self::$instance = new self();
>         }
>         return self::$instance;
>     }
>
>     private function __construct() {}
>
>     /**
>      * Tab slug for this plugin's sections on the shared host Settings page.
>      * Lowercase a-z0-9-_ only — sanitize_key() compliant.
>      */
>     public const TAB_SLUG = 'mcp';
>
>     // register_tab / register_settings / render methods below ...
> }
> ```
>
> Do NOT declare the class `final` — sibling uses plain `class SettingsMenu {`.
> Class docblock notes `@since 0.1.0` per WordPress convention.
>
> `register_tab( $tabs ): array` — filter callback. Copy the sibling's
> `is_array( $tabs )` guard verbatim (sibling lines 84-86). Returns:
>
> ```php
> public function register_tab( $tabs ): array {
>     if ( ! is_array( $tabs ) ) {
>         $tabs = array();
>     }
>
>     $tabs[] = array(
>         'slug'     => self::TAB_SLUG,
>         'label'    => __( 'MCP', 'acrossai-mcp-manager' ),
>         'priority' => 20,
>     );
>
>     return $tabs;
> }
> ```
>
> `register_settings(): void` — admin_init callback. **Match sibling
> unconditional call at line 109** — no `class_exists( '\AcrossAI_Main_Menu\SettingsPage' )`
> guard. The `acrossai-co/main-menu` package is a hard require in composer.json
> via Feature 010's FR-030 + the priority-1 pre-activation vendor autoload
> guard, so `\AcrossAI_Main_Menu\SettingsPage` is guaranteed present at
> `admin_init`. A defensive guard would be dead code and would deviate from
> the family pattern established by the sibling.
>
> ```php
> public function register_settings(): void {
>     $page_slug = \AcrossAI_Main_Menu\SettingsPage::tab_page_slug( self::TAB_SLUG );
>
>     register_setting(
>         'acrossai-settings',
>         'acrossai_mcp_npm_login_enabled',
>         array(
>             'sanitize_callback' => 'rest_sanitize_boolean',
>             'default'           => false,
>         )
>     );
>
>     register_setting(
>         'acrossai-settings',
>         'acrossai_mcp_claude_connectors_enabled',
>         array(
>             'sanitize_callback' => 'rest_sanitize_boolean',
>             'default'           => false,
>         )
>     );
>
>     add_settings_section(
>         'acrossai_mcp_npm_section',
>         __( 'npm / CLI Settings', 'acrossai-mcp-manager' ),
>         array( $this, 'render_npm_section_description' ),
>         $page_slug
>     );
>
>     add_settings_field(
>         'acrossai_mcp_npm_login_enabled',
>         __( 'Enable CLI Connections', 'acrossai-mcp-manager' ),
>         array( $this, 'render_npm_login_field' ),
>         $page_slug,
>         'acrossai_mcp_npm_section'
>     );
>
>     add_settings_section(
>         'acrossai_mcp_claude_connectors_section',
>         __( 'Claude Connectors Screen (Experimental)', 'acrossai-mcp-manager' ),
>         array( $this, 'render_claude_connectors_section_description' ),
>         $page_slug
>     );
>
>     add_settings_field(
>         'acrossai_mcp_claude_connectors_enabled',
>         __( 'Enable direct Claude Connectors mode', 'acrossai-mcp-manager' ),
>         array( $this, 'render_claude_connectors_enabled_field' ),
>         $page_slug,
>         'acrossai_mcp_claude_connectors_section'
>     );
>
>     // ── Uninstall section (destructive opt-in — sibling lines 111-159 shape) ─
>     register_setting(
>         'acrossai-settings',
>         'acrossai_mcp_uninstall_delete_data',
>         array(
>             'sanitize_callback' => array( $this, 'sanitize_uninstall_flag' ),
>             'default'           => 0,
>         )
>     );
>
>     add_settings_section(
>         'acrossai_mcp_uninstall_section',
>         __( 'Uninstall Settings', 'acrossai-mcp-manager' ),
>         '__return_false',
>         $page_slug
>     );
>
>     add_settings_field(
>         'acrossai_mcp_uninstall_delete_data',
>         __( 'Delete all data on uninstall', 'acrossai-mcp-manager' ),
>         array( $this, 'render_uninstall_field' ),
>         $page_slug,
>         'acrossai_mcp_uninstall_section'
>     );
> }
> ```
>
> `register_setting()` args for the two boolean toggles match sibling shape at
> lines 111-118 / 121-128: just `sanitize_callback` + `default` — **no explicit
> `'type' => 'boolean'` key**. `rest_sanitize_boolean` handles the boolean
> coercion.
>
> The uninstall `register_setting()` uses the sibling's int-0/1 pattern instead
> of `rest_sanitize_boolean` — matches sibling lines 111-118 exactly (same
> `sanitize_uninstall_flag` callback name, same `default => 0`). Section
> description uses `'__return_false'` (sibling line 150) because the ⚠ warning
> lives on the field itself per sibling `render_uninstall_field()` — same
> layout as the target UI screenshot.
>
> **Divergence from sibling for section descriptions**: the sibling uses
> `'__return_false'` as the section-description callback (lines 134, 150)
> because its Abilities tab has no per-section content. This plugin's
> sections DO have per-section content (warning-notice banners with URLs),
> so bind the section-description arg to real render methods
> (`render_npm_section_description`, `render_claude_connectors_section_description`).
> Justified against the target UI screenshot — the banners are load-bearing
> operator warnings, not decorative.
>
> Four render methods — bodies port the CONTENT from the sibling wordpress-ai
> copy (`src/Admin/Settings.php:454-560`) but the STYLE follows the sibling
> abilities-manager `printf( '<html>%s%s', esc_*( ... ) )` idiom at lines
> 183-190 and 212-220. Field render example:
>
> ```php
> public function render_npm_login_field(): void {
>     $checked = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false );
>     printf(
>         '<label><input type="checkbox" id="acrossai_mcp_npm_login_enabled" name="acrossai_mcp_npm_login_enabled" value="1" %s /> %s</label><p class="description">%s</p>',
>         checked( $checked, true, false ),
>         esc_html__( 'Allow CLI connections via npm / npx', 'acrossai-mcp-manager' ),
>         esc_html__( 'When enabled, the npm tab on each server\'s edit page will display the npx CLI command ...', 'acrossai-mcp-manager' )
>     );
> }
> ```
>
> Section description renderers use `printf( wp_kses_post( __( '<html>%s</code>', ... ) ), esc_html( $val ) )`
> for the warning banners since they contain HTML tags (`<div class="notice">`,
> `<code>`, `<strong>`) that `esc_html__` would escape into text. URL sources:
>
> - `render_npm_section_description()` — uses `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth::get_base_url()`
>   (verified present at `public/Partials/FrontendAuth.php:76`).
> - `render_claude_connectors_section_description()` — the three URL helpers
>   `ClaudeConnectors::get_authorization_server_metadata_url()`,
>   `get_authorize_url()`, `get_token_endpoint_url()` **DO NOT EXIST** in
>   this plugin. Use inline fallbacks for the future Connector OAuth surface:
>   ```php
>   $as_metadata_url = home_url( '/.well-known/oauth-authorization-server' );
>   $authorize_url   = home_url( '/acrossai-mcp-connectors/oauth/authorize/' );
>   $token_url       = rest_url( 'acrossai-mcp-manager/v1/connector/oauth/token' );
>   ```
>   Note: these URLs describe the future Connector OAuth surface — distinct
>   from the existing CLI OAuth flow served at `ClaudeConnectors.php:132-134`
>   (which uses `/acrossai-mcp-oauth/` + `acrossai-mcp/v1/token`). Mark with
>   `// TODO(follow-up): future Connector OAuth surface feature will register
>   these routes and own the URL helpers`.
>
> **Additional sanitize + render methods for the uninstall section** —
> copy verbatim from sibling lines 202-220 (adjusted for MCP option key +
> text domain):
>
> ```php
> public function sanitize_uninstall_flag( $value ): int {
>     return empty( $value ) ? 0 : 1;
> }
>
> public function render_uninstall_field(): void {
>     $checked = (bool) get_option( 'acrossai_mcp_uninstall_delete_data', 0 );
>     printf(
>         '<label><input type="checkbox" id="acrossai_mcp_uninstall_delete_data" name="acrossai_mcp_uninstall_delete_data" value="1" %s /> %s</label><p class="description"><span style="color: #d63638;">%s</span></p>',
>         checked( $checked, true, false ),
>         esc_html__( 'Delete all data on uninstall', 'acrossai-mcp-manager' ),
>         esc_html__( '⚠ Warning: When checked, uninstalling this plugin will permanently delete all custom database tables and plugin options. This cannot be undone.', 'acrossai-mcp-manager' )
>     );
> }
> ```
>
> The `⚠` character is a UTF-8 U+26A0 WARNING SIGN — sibling uses the same
> character verbatim at line 218. File save-encoding MUST be UTF-8 (no BOM)
> or the emoji renders as mojibake.
>
> Do NOT add any hook registration inside the class body (A1 — hooks are
> wired via Main.php Loader per TASK-2). Do NOT extend `WP_List_Table` or any
> other base class — this is a standalone singleton.
>
> ---
>
> **TASK-2 — Wire `SettingsMenu` in `Main.php` + delete `Settings::register_settings()` stub**
>
> Files:
> - `includes/Main.php` (delta: extend docblock at ~lines 310-319, delete line
>   322 stub-wiring, add 3 new lines after the remaining Settings wiring)
> - `admin/Partials/Settings.php` (delta: delete stub method + docblock at
>   ~lines 406-413)
>
> Read `includes/Main.php` lines 300-330 (the Settings block inside
> `define_admin_hooks()`) BEFORE editing.
>
> Main.php edits:
>
> 1. **Extend the docblock** above the Settings wiring to explain the
>    Settings-vs-SettingsMenu split. The `Settings` class continues to own the
>    OWN-page list controller (`?page=acrossai_mcp_manager`); the new
>    `SettingsMenu` class owns the SHARED-page MCP tab
>    (`?page=acrossai-settings&tab=mcp`). Remove the "US3 T020" reference
>    from the existing docblock.
> 2. **Delete** the dead line:
>    ```php
>    $this->loader->add_action( 'admin_init', $settings, 'register_settings' );
>    ```
> 3. **Add** three lines immediately after the remaining
>    `$this->loader->add_action( 'admin_init', $settings, 'handle_actions', 5 );`:
>    ```php
>    $settings_menu = \AcrossAI_MCP_Manager\Admin\Partials\SettingsMenu::instance();
>    $this->loader->add_filter( 'acrossai_settings_tabs', $settings_menu, 'register_tab' );
>    $this->loader->add_action( 'admin_init', $settings_menu, 'register_settings' );
>    ```
>
> Settings.php edits: delete the stub method + its section comment
> (~lines 406-413):
> ```php
> // Settings API registration (no-op stub; populated in US3 T020).
> public function register_settings(): void {
>     // US3 T020 ports the full register_setting / add_settings_section / field calls.
>     // Empty body is safe — register_settings is called on admin_init and may be a no-op.
> }
> ```
> Nothing else in `Settings.php` references this method. The Main.php wiring
> goes away in step 2 of TASK-2. Grep-verify after: `grep -n
> 'register_settings' admin/Partials/Settings.php` returns zero lines.
>
> ---
>
> **TASK-3 — Extend `AdminPageSlugs`**
>
> Files: `includes/Utilities/AdminPageSlugs.php`
>
> Read the current file (~60 lines) BEFORE editing.
>
> 1. **Add** a new class constant after `CLI_AUTH_LOG` (line 31):
>    ```php
>    /** Shared settings page tab slug (`?page=acrossai-settings&tab=<slug>`). */
>    public const SETTINGS_TAB = 'mcp';
>    ```
>    Note that this constant is kept in sync with
>    `SettingsMenu::TAB_SLUG` — both retain the const so each class stays
>    self-contained if a future refactor removes the shared constant.
> 2. **Extend** `plugin_screen_ids()` return array to include the shared
>    settings page's WordPress screen ID:
>    ```php
>    // Shared AcrossAI Settings page (owned by acrossai-co/main-menu).
>    'acrossai_page_acrossai-settings',
>    ```
>    Rationale (per A9 canonical-whitelist preservation): future features may
>    enqueue admin assets on the MCP settings tab, and the enqueue-guard in
>    `admin/Main.php` reads this whitelist. Adding it defensively today means
>    the whitelist is ready when needed. The `acrossai_page_` prefix matches
>    the pattern established in Feature 010 (WordPress derives it from the
>    parent menu title "AcrossAI").
>
> Do NOT remove any existing screen ID from the whitelist. A9
> canonical-whitelist rule: additive-only extensions.
>
> ---
>
> **TASK-4 — PHPUnit test**
>
> Files: `tests/phpunit/Admin/SettingsMenuTest.php` (NEW)
>
> Verify `tests/bootstrap.php` + `tests/bootstrap-wp.php` cover the new
> `tests/phpunit/Admin/` subdir under PSR-4 autoload BEFORE writing the test.
> If not, extend the bootstrap; else the test file lands ready-to-run.
>
> Three test methods (~60 LOC total). Extend `WP_UnitTestCase` (needs
> WordPress test-DB fixtures for the `$wp_registered_settings` global that
> `register_setting()` populates).
>
> 1. `test_register_tab_appends_expected_shape` — asserts:
>    ```php
>    $result = SettingsMenu::instance()->register_tab( array() );
>    $this->assertCount( 1, $result );
>    $this->assertSame( 'mcp', $result[0]['slug'] );
>    $this->assertSame( 'MCP', $result[0]['label'] );
>    $this->assertSame( 20, $result[0]['priority'] );
>    ```
>
> 2. `test_register_tab_normalizes_non_array_input` — passes `null`, `false`,
>    `'string'` in turn. Each MUST return a 1-element array with the expected
>    tab entry (the `is_array( $tabs ) ? $tabs : []` guard).
>
> 3. `test_register_settings_registers_expected_option_keys` — invokes
>    `SettingsMenu::instance()->register_settings()`, then asserts the two
>    option keys are present in the global `$wp_registered_settings` array
>    under the `'acrossai-settings'` option group with the expected
>    `sanitize_callback` and `default` values:
>    ```php
>    global $wp_registered_settings;
>    SettingsMenu::instance()->register_settings();
>    $this->assertArrayHasKey( 'acrossai_mcp_npm_login_enabled', $wp_registered_settings );
>    $this->assertSame( 'rest_sanitize_boolean',
>        $wp_registered_settings['acrossai_mcp_npm_login_enabled']['sanitize_callback'] );
>    $this->assertFalse( $wp_registered_settings['acrossai_mcp_npm_login_enabled']['default'] );
>    // Same for acrossai_mcp_claude_connectors_enabled.
>    ```
>    This locks: (a) both option keys stay in the shared `'acrossai-settings'`
>    option group (any drift here silently breaks Save at the vendor's
>    `options.php` handoff), and (b) the sanitize callback is
>    `rest_sanitize_boolean` (any drift here allows non-boolean values to
>    persist and gates elsewhere may misbehave).
>
> Cite BUGS.md B9 in the test-file docblock: PHPUnit 13+ requires
> `#[DataProvider]` PHP attributes (not `@dataProvider` annotations). This
> test doesn't currently need a data provider, but if any future refactor
> adds one, use the PHP-attribute form.
>
> ---
>
> **TASK-5 — Gate `uninstall.php` on the opt-in flag**
>
> Files: `uninstall.php` (rewrite)
>
> Read the current file (~54 lines) BEFORE editing. Note lines 37-49
> unconditionally drop the two OAuth tables + their `db_version` options +
> the `acrossai_mcp_oauth_cleanup` scheduled hook — a Feature-006 destructive-
> by-nature stopgap. Feature 012 migrates that behavior under the new opt-in
> flag; the new default is **preserve everything** on uninstall (safer +
> matches the sibling pattern + matches WordPress best practices).
>
> New shape:
>
> ```php
> <?php
> /**
>  * Fired when the plugin is uninstalled.
>  *
>  * Reads acrossai_mcp_uninstall_delete_data (set by SettingsMenu):
>  *   0 (default) → PRESERVE all data — no tables dropped, no options deleted.
>  *   1           → DESTRUCTIVE — drop all four wp_acrossai_mcp_* tables,
>  *                 delete all acrossai_mcp_* options, clear all plugin
>  *                 scheduled hooks.
>  *
>  * @package AcrossAI_MCP_Manager
>  * @since   0.0.1
>  */
>
> if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
>     exit;
> }
>
> // Preserve-by-default unless the operator has opted in via the MCP settings tab.
> if ( 1 !== (int) get_option( 'acrossai_mcp_uninstall_delete_data', 0 ) ) {
>     return;
> }
>
> global $wpdb;
>
> // Drop all four Feature-011 BerlinDB tables.
> $tables = array(
>     $wpdb->prefix . 'acrossai_mcp_servers',
>     $wpdb->prefix . 'acrossai_mcp_cli_auth_logs',
>     $wpdb->prefix . 'acrossai_mcp_oauth_tokens',
>     $wpdb->prefix . 'acrossai_mcp_oauth_audit',
> );
> foreach ( $tables as $table ) {
>     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
>     $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
> }
>
> // Delete all acrossai_mcp_* options — sweep-style so future features that
> // add options are covered without touching uninstall.php again.
> $options = $wpdb->get_col(
>     $wpdb->prepare(
>         "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
>         'acrossai_mcp_%'
>     )
> );
> foreach ( $options as $option_name ) {
>     delete_option( $option_name );
> }
>
> // Clear all plugin-owned scheduled hooks. Extend this list if future
> // features add more.
> wp_clear_scheduled_hook( 'acrossai_mcp_oauth_cleanup' );
> ```
>
> **Behavior change vs current uninstall.php**:
> - Old default: destroy OAuth tables + `db_version` options + cron hook
>   unconditionally, preserve everything else.
> - New default: preserve everything (opt-in gate returns early).
> - New opt-in (when the operator checks the box): drop ALL four tables +
>   sweep-delete ALL `acrossai_mcp_*` options + clear the cron hook.
>
> The option-name-LIKE sweep at line ~44 above catches every plugin option
> introduced by Features 001-011 (including the four `db_version` options,
> the two Settings-API booleans, and the uninstall flag itself) without
> maintaining a hand-rolled allow-list. Future features that add new options
> under the `acrossai_mcp_*` prefix are covered for free.
>
> **DoD** for this task:
> - `php -l uninstall.php` returns "No syntax errors detected".
> - PHPStan L8 on `uninstall.php` — 0 errors. Note the file is bootstrap-less
>   (no WordPress test harness), so a `phpstan-ignore-line` on the
>   `WP_UNINSTALL_PLUGIN` guard may be needed for undefined-constant warnings.
> - PHPCS on `uninstall.php` — 0 errors + 0 warnings. The
>   `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` ignore is scoped to
>   the DROP TABLE loop only — `$table` is derived from `$wpdb->prefix` +
>   hardcoded strings, no user input.
> - Manual smoke test: on a local WP install, activate the plugin, tick the
>   uninstall box, save, then uninstall the plugin via the WP admin plugins
>   screen. After uninstall completes, `wp db query "SHOW TABLES LIKE
>   'wp_acrossai_mcp_%'"` returns empty and `wp option list --search='acrossai_mcp_*'`
>   returns empty. Reactivate the plugin from scratch — fresh install path
>   (Feature 011 phantom-version guard) recreates everything cleanly.
> - Reverse smoke test: fresh install, DO NOT tick the uninstall box, then
>   uninstall. All four tables + all options remain intact. Reactivate — no
>   duplicate rows, no phantom-version bumps.
>
> ---
>
> **TASK-6 — Remove the CLI Auth Log admin page**
>
> Files:
> - `admin/Partials/CliAuthLogListTable.php` — **DELETE entire file** (175
>   lines, WP_List_Table subclass; only consumer is the deleted render method
>   below)
> - `admin/Partials/Menu.php` — delete the `add_submenu_page` block for
>   position 3 (~lines 87-96 in the current file); update the docblock at
>   ~lines 59-64 to remove any mention of "Position 3 — CLI Auth Log". The
>   remaining Positions 2 (MCP main) and 4 (Access Control, conditional)
>   stay unchanged
> - `includes/Utilities/AdminPageSlugs.php` — delete `public const CLI_AUTH_LOG`
>   plus its docblock comment (~lines 30-31); delete the two entries from
>   `plugin_screen_ids()` return array that reference it
>   (`'acrossai_page_' . self::CLI_AUTH_LOG,` and
>   `'mcp-manager_page_' . self::CLI_AUTH_LOG,`). Every other constant + every
>   other screen-ID whitelist entry stays (A9 canonical-whitelist rule)
> - `admin/Partials/Settings.php` — delete the `render_cli_auth_log_page()`
>   method + its surrounding docblock (~lines 717-742) AND delete the
>   `use AcrossAI_MCP_Manager\Admin\Partials\CliAuthLogListTable;` import at
>   the top of the file. Every other method + every other import stays
>
> **Preserve verbatim** — every file under `includes/Database/CliAuthLog/**`
> (Table.php, Schema.php, Query.php, Row.php, Recorder.php — 5 files,
> ~587 lines total). These are consumed at runtime by
> `includes/OAuth/Storage.php`, `includes/OAuth/BearerAuth.php`,
> `includes/REST/CliController.php`, and
> `includes/Database/CliAuthLog/Recorder.php` — deletion would break the
> OAuth token exchange (`redeem_atomic` SEC-001 atomic-CAS + auth-log audit
> trail).
>
> **No JS/CSS to delete** — the page had no dedicated asset files. It used
> the generic `backend.js` / `backend.css` bundle via `admin/Main.php`'s
> screen-ID guard, which continues to work correctly after
> `plugin_screen_ids()` sheds the two CLI Auth Log entries.
>
> **No REST route or CLI command to delete** — the page had no REST or CLI
> surface. `includes/REST/CliController.php` and `includes/OAuth/CliCommand.php`
> stay — they use the DB layer, not the admin page.
>
> **No PHPUnit tests to delete** — no test file references
> `CliAuthLogListTable` or the page slug. Tests in `tests/phpunit/Database/`,
> `tests/phpunit/OAuth/`, `tests/phpunit/RestCli/` target the DB layer +
> OAuth flow, both of which stay.
>
> **No uninstall.php change** — the existing note in `uninstall.php` about
> not dropping `acrossai_mcp_cli_auth_logs` remains accurate. TASK-5's new
> opt-in gate still applies uniformly.
>
> **DoD**:
> - `php -l` clean on the 3 modified files (`Menu.php`, `AdminPageSlugs.php`,
>   `Settings.php`).
> - PHPStan L8 + PHPCS zero errors/warnings on the modified files.
> - The removal grep from the pre-flight block above returns **zero hits**.
> - The companion (DB-layer) grep from the pre-flight block above returns
>   the **same non-zero hit count as before** (proves the DB layer is
>   untouched).
> - PHPUnit — every existing test still passes.
>   `tests/phpunit/Database/AtomicCasTest.php` + `PhantomVersionGuardTest.php`
>   + `tests/phpunit/OAuth/*` + `tests/phpunit/RestCli/RecorderTest.php` all
>   green.
> - Manual smoke: activate the plugin →
>   `?page=acrossai_mcp_manager_cli_auth_log` renders the WP "You do not
>   have sufficient permissions" or "Page not found" screen (page slug no
>   longer registered) → `?page=acrossai-settings&tab=mcp` still renders the
>   new MCP Settings tab → OAuth `redeem_atomic` flow still succeeds on a
>   live auth-code redemption round-trip.
>
> ---
>
> **TASK-7 — Memory hygiene + changelog**
>
> Files: `README.txt`, `docs/memory/DECISIONS.md`, `docs/memory/WORKLOG.md`,
> `docs/memory/INDEX.md`, `docs/planings-tasks/README.md`
>
> Read `PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION` (from `docs/memory/ARCHITECTURE.md`
> or sibling plugin) BEFORE editing.
>
> `README.txt` — add two Unreleased changelog bullets:
> ```
> * Added an MCP tab on the shared AcrossAI Settings page
>   (?page=acrossai-settings) with three operator toggles: Enable CLI Connections
>   (`acrossai_mcp_npm_login_enabled`, gates the npm/npx CLI flow), Enable
>   direct Claude Connectors mode
>   (`acrossai_mcp_claude_connectors_enabled`, gates the experimental direct
>   Claude Connectors mode), and Delete all data on uninstall
>   (`acrossai_mcp_uninstall_delete_data`, opts into destructive uninstall).
>   All three toggles default to disabled.
> * BEHAVIOR CHANGE (uninstall): the previous unconditional drop of the two
>   OAuth tables + their `db_version` options + the daily OAuth-cleanup cron
>   is now gated by the new "Delete all data on uninstall" checkbox. Default
>   is now **preserve everything on uninstall** (safer + matches best
>   practices). Sites that expected the OAuth-table wipe must tick the new
>   checkbox before uninstalling to restore the old behavior.
> * Removed the "CLI Auth Log" admin submenu at
>   `?page=acrossai_mcp_manager_cli_auth_log`. The underlying
>   `wp_acrossai_mcp_cli_auth_logs` table + Query/Row classes remain — they
>   continue to power the OAuth authentication flow. Auth-log inspection is
>   now available via WP-CLI (`wp db query "SELECT ... FROM wp_acrossai_mcp_cli_auth_logs"`)
>   or a future per-server tab; the standalone submenu was redundant
>   post-Feature-011.
> ```
>
> `docs/memory/DECISIONS.md` — append a NEW active decision:
> **DEC-VENDOR-SETTINGS-TAB-INTEGRATION (Active — Feature 012)**: When
> consuming the `acrossai-co/main-menu` vendor package's shared settings page,
> plugin tabs MUST (a) hook `acrossai_settings_tabs` filter to register the
> tab entry (`['slug', 'label', 'priority']` shape, with an `is_array($tabs)`
> normalization guard on the filter arg), (b) target
> `\AcrossAI_Main_Menu\SettingsPage::tab_page_slug( <slug> )` as the `$page`
> argument for `add_settings_section` / `add_settings_field`, (c) use the
> shared `'acrossai-settings'` option group for `register_setting` (NOT the
> per-tab page slug — the vendor's own `settings_fields( 'acrossai-settings' )`
> call in `PageRenderer::render()` is what makes the shared Save button route
> through `options.php` for every tab), (d) follow the sibling
> `acrossai-abilities-manager/admin/Partials/SettingsMenu.php` (Feature 038)
> class shape verbatim: non-final `class`, singleton with `$instance` var +
> `instance()` method + private `__construct()`, `TAB_SLUG` const declared
> AFTER the singleton scaffolding, field renderers use `printf( '<html>%s%s',
> esc_*( ... ) )` inline. Reference implementations:
> `acrossai-abilities-manager/admin/Partials/SettingsMenu.php:1-221` (the
> canonical shape) and `admin/Partials/SettingsMenu.php` (this feature; adds
> the section-description render callbacks needed for the two operator warning
> banners — sibling uses `'__return_false'` because its Abilities tab has no
> per-section content).
>
> Note on defense-in-depth: an early `class_exists( '\AcrossAI_Main_Menu\SettingsPage' )`
> guard in `register_settings()` is intentionally OMITTED because the vendor
> package is a hard require in composer.json (Feature 010 FR-030 + the
> priority-1 pre-activation vendor autoload guard). If the package is ever
> demoted from hard-require to optional integration, this decision must be
> revisited and the guard added at that point.
>
> **Companion durable decision — DEC-UNINSTALL-OPT-IN-GATE (Active — Feature 012)**:
> `uninstall.php` MUST preserve all data by default. Destructive teardown
> (dropping tables, deleting `acrossai_mcp_*` options, clearing plugin cron
> hooks) is gated on `get_option( 'acrossai_mcp_uninstall_delete_data', 0 ) === 1`
> — the operator must explicitly opt in via the MCP settings tab. This
> replaces the Feature-006-era "destructive-by-nature for OAuth tables"
> stopgap. Rationale: never delete user data without explicit consent
> (WordPress best practice + WP.org guideline 5); matches the sibling
> `acrossai-abilities-manager` uninstall pattern verbatim. Future features
> that add tables or options MUST NOT bypass this gate — extend the
> `uninstall.php` LIKE-sweep to cover new options or add new DROP TABLE
> entries inside the guarded block, never before it.
>
> **Companion durable decision — DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG (Active — Feature 012)**:
> standalone admin submenus for read-only DB-inspection views SHOULD be
> pruned when they duplicate an already-implemented inspection path
> (WP-CLI, per-server tab, direct DB query). The underlying DB layer stays
> because runtime consumers may still depend on it — the prune targets the
> admin surface only. Rule for future admin surfaces: a submenu justifies
> its existence via an interactive/mutating capability (form, action,
> toggle), not a read-only list. For pure list views, prefer a tab on an
> existing page or a WP-CLI command. Reference application: Feature 012
> TASK-6 removes `render_cli_auth_log_page()` + `CliAuthLogListTable` while
> preserving the full `includes/Database/CliAuthLog/**` runtime layer that
> OAuth token exchange depends on.
>
> Also note: §IV DataForm mandate does NOT apply to tabs on the shared
> settings page — the vendor's `PageRenderer` is a WordPress Settings API
> surface, not a DataForm surface. This is an accepted DEV carve-out matching
> the vendor package's contract. If a future vendor release migrates
> `PageRenderer` to DataForm, all consumer plugins (including this one)
> migrate with it.
>
> `docs/memory/WORKLOG.md` — add a Feature 012 milestone entry (Why durable
> / Future mistake prevented / Evidence / Where to look). Highlight the
> durable lesson: **when consuming a vendor package's shared settings
> surface, the option_group MUST match the vendor's own `settings_fields()`
> call — NOT the per-tab page slug — or Save silently no-ops with no error**.
>
> `docs/memory/INDEX.md` — append a new row for
> `DEC-VENDOR-SETTINGS-TAB-INTEGRATION` under Active Decisions plus a
> WORKLOG row for Feature 012.
>
> `docs/planings-tasks/README.md` — append a row for
> `012-mcp-settings-tab.md` alongside the existing Feature 011 row.
>
> ---
>
> **CONSTRAINTS**
>
> - **Do not touch `vendor/`.** The `acrossai-co/main-menu` package is
>   consumed as-is. The `SettingsPage::tab_page_slug()` helper + the
>   `acrossai_settings_tabs` filter are the ONLY vendor API surfaces this
>   feature consumes.
> - **Do not extract new URL helpers on `ClaudeConnectors` in this PR.** The
>   three Connector OAuth URLs (`get_authorization_server_metadata_url`,
>   `get_authorize_url`, `get_token_endpoint_url`) are inlined as
>   `home_url()` / `rest_url()` fallbacks with a `TODO(follow-up)` comment.
>   Extraction is deferred to a future Connector OAuth surface feature that
>   will register the `/acrossai-mcp-connectors/oauth/authorize/` rewrite +
>   `acrossai-mcp-manager/v1/connector/oauth/token` REST route AND own the
>   matching URL helpers with PHPUnit coverage.
> - **Do not rename `admin/Partials/Settings.php`.** Naming coexistence with
>   the new `SettingsMenu.php` matches the sibling
>   `acrossai-abilities-manager` layout. A rename refactor is a
>   follow-up chore.
> - **Do not add `add_action` / `add_filter` inside `SettingsMenu` class
>   body.** Hook wiring lives in `Main.php` via the Loader per A1.
> - **Do not add a `class_exists( '\AcrossAI_Main_Menu\SettingsPage' )` guard
>   in `register_settings()`.** Match the sibling `acrossai-abilities-manager`
>   line 109 which calls `tab_page_slug()` unconditionally. The vendor package
>   is a hard require in composer.json (Feature 010 FR-030 + priority-1
>   pre-activation vendor autoload guard); an early-return guard would be dead
>   code and would deviate from the family pattern. If the package is ever
>   demoted from hard-require to optional integration, the guard becomes
>   mandatory and DEC-VENDOR-SETTINGS-TAB-INTEGRATION must be re-evaluated.
> - **Do not change the two option-key names for the boolean toggles.** They
>   match the sibling `wordpress-ai` copy verbatim; any existing site
>   inherits its options in-place.
> - **Do not rename `acrossai_mcp_uninstall_delete_data` or change its
>   sanitize contract (int 0/1).** The `uninstall.php` reader is
>   tightly coupled to this exact option name and value shape; a mismatch
>   silently disables the destructive teardown, leaving users with orphaned
>   data on uninstall.
> - **Do not invert the uninstall default from `0` (preserve) to `1`
>   (destroy).** Preserve-by-default is a load-bearing safety invariant.
>   WP.org plugin guideline 5 (never delete user data without consent)
>   applies. Any future change to this default requires an explicit
>   security/UX review + a `constitution.md` amendment.
> - **Do not skip the `if ( 1 !== (int) get_option( ... ) ) { return; }`
>   gate at the top of `uninstall.php`.** Every destructive operation in
>   the file MUST be after this gate — including any future
>   `wp_clear_scheduled_hook()` or `DROP TABLE` additions.
> - **Do not delete any file under `includes/Database/CliAuthLog/**`.** The
>   5 files (Table.php, Schema.php, Query.php, Row.php, Recorder.php) are
>   consumed at runtime by `includes/OAuth/Storage.php`,
>   `includes/OAuth/BearerAuth.php`, `includes/REST/CliController.php`, and
>   `includes/Database/CliAuthLog/Recorder.php`. Deleting them would break
>   OAuth token exchange (`redeem_atomic` SEC-001 atomic-CAS) and the
>   auth-log audit trail. TASK-6 removes the ADMIN SURFACE only. The
>   companion DB-layer grep in the Pre-flight block is the merge-gate for
>   this constraint — hit count MUST match before and after TASK-6.
> - **Do not change the `'acrossai-settings'` option group.** The vendor's
>   `PageRenderer` at line 61 emits `settings_fields( 'acrossai-settings' )`
>   — changing this string in `register_setting` would silently break Save.
> - **Do not remove any existing `AdminPageSlugs::plugin_screen_ids()` entry
>   (A9).** The whitelist is canonical; extension is additive-only.
> - **Every task must leave PHPStan level 8 + PHPCS individually green
>   before moving to the next.** Constitution §VII per-task gating applies.
> - **Every `__()` / `_e()` / `esc_html__()` call MUST use text domain
>   `'acrossai-mcp-manager'`.** Constitution §II.
> - **The two toggles' semantics MUST be preserved by every downstream
>   consumer.** `get_option( 'acrossai_mcp_npm_login_enabled', false )` in
>   `FrontendAuth` (and any other gate) MUST continue to gate the CLI login
>   flow. `get_option( 'acrossai_mcp_claude_connectors_enabled', false )` in
>   `ClaudeConnectors` (and any other gate) MUST continue to gate the direct
>   Claude Connectors mode. If a downstream consumer does not exist yet,
>   note the missing gate as a follow-up task inside `docs/planings-tasks/README.md`.
> - **Grep after every task** for the two option-key names. The Final
>   full-repo audit at the bottom MUST show the option keys present in
>   `SettingsMenu.php` + wherever downstream gates already exist —
>   with no orphans anywhere else in the repo.
