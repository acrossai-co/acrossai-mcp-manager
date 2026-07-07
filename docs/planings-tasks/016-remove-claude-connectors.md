# Planning: Remove Claude Connector Feature (Feature 016)

Remove the Claude Connectors integration in its entirety — OAuth 2.1 authorization
server, admin tab, settings toggle, shortcode, per-server audit log, CSS bundle,
the three `claude_connector_*` columns on `wp_acrossai_mcp_servers`, and the two
dedicated OAuth tables `wp_acrossai_mcp_oauth_tokens` +
`wp_acrossai_mcp_oauth_audit`. The prior [[project_connector_oauth_gap]]
investigation established that claude.ai's hosted Connectors UI cannot reach a
local WP install (no OAuth 2.1 dynamic client registration, no CORS, no public
tunnel), and the decision was to stop investing in the feature. Feature 016
retires the ~4,000 lines of code, one daily cron, one rewrite-rule set, one REST
endpoint, and three shortcodes / one action hook that support it.

The scope also includes the **shared OAuth infrastructure** under
`includes/OAuth/` (`Storage`, `TokenController`, `BearerAuth`, `PKCE`,
`AuditLog`, `CliCommand`) and the BerlinDB modules under
`includes/Database/OAuthToken/` + `includes/Database/OAuthAudit/`. A pre-flight
grep confirmed these classes are used ONLY by `ClaudeConnectors` — the CLI auth
flow is a separate stack (`FrontendAuth`, `CliController`, WordPress App
Passwords in `wp_usermeta`, `wp_acrossai_mcp_cli_auth_logs`) and is untouched.

The migration is **NOT backwards-compatible with any prior connector user
data** — codes, tokens, and audit rows are dropped. This is consistent with the
011 Pre-flight Attestation (dev/local only, no production install has real
connector data). The teardown DOES preserve every non-connector row in
`wp_acrossai_mcp_servers`; the column drop only strips the three connector
fields, and the surviving 10 columns keep their exact `CREATE TABLE`
byte-for-byte definition, so BerlinDB's diff engine treats the change as a
column drop only.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "remove-claude-connectors"

# 2. Specify
/speckit.specify "Remove the Claude Connectors feature in its entirety from the
acrossai-mcp-manager plugin. Delete the connector-specific PHP classes
(includes/OAuth/ClaudeConnectors.php, admin/Partials/ServerTabs/ClaudeConnectorTab.php,
admin/Partials/ConnectorAuditLogListTable.php, public/Renderers/ClaudeConnectorBlock.php),
the shared OAuth infrastructure that only they use
(includes/OAuth/Storage.php, AuditLog.php, TokenController.php, BearerAuth.php,
PKCE.php, CliCommand.php — the entire includes/OAuth/ directory becomes empty),
and the two BerlinDB modules for the OAuth tables
(includes/Database/OAuthToken/, includes/Database/OAuthAudit/). Drop the two
tables wp_acrossai_mcp_oauth_tokens + wp_acrossai_mcp_oauth_audit in the
Activator upgrade path and in uninstall.php. Drop the three columns
claude_connector_client_id, claude_connector_client_secret,
claude_connector_redirect_uri from the MCPServer Schema, Row, and
DefaultServerSeeder, and bump the MCPServer BerlinDB $version so the column-drop
executes on reactivation. Delete the acrossai_mcp_claude_connectors_enabled
option registration + section + field in admin/Partials/SettingsMenu.php. Delete
the save_claude_connector action branch + handle_save_claude_connector() method
from admin/Partials/Settings.php. Delete the ClaudeConnectorTab entry from
admin/Partials/ServerTabs/Registry.php::all_tabs(). Delete the
acrossai_mcp_claude_connector_block shortcode registration and the
'claude-connector' entry in the dispatch map inside
includes/REST/ClientRendererController.php. Delete the enqueue_styles() +
enqueue_scripts() methods in public/Main.php and unregister the hooks that call
them from includes/Main.php::define_public_hooks(). Delete the ClaudeConnectors,
TokenController, and BearerAuth hook-registration blocks in
includes/Main.php::define_public_hooks(). Delete the register_rewrite_rules() +
flush_rewrite_rules() + wp_schedule_event('acrossai_mcp_oauth_cleanup') blocks
in includes/Activator.php. Delete the wp_clear_scheduled_hook block in
includes/Deactivator.php. Remove the two OAuth table names and the
acrossai_mcp_claude_connectors_enabled option name from uninstall.php. Delete
the src/scss/frontend-oauth.scss source + the 'css/frontend-oauth' webpack entry
in webpack.config.js + the generated build/css/frontend-oauth.{css,-rtl.css,asset.php}
outputs, then run npm run build to produce a clean build/. Delete
tests/phpunit/OAuth/ in its entirety, delete tests/phpunit/Public/MainEnqueueTest.php,
and prune connector assertions from tests/phpunit/Admin/SettingsMenuTest.php,
tests/phpunit/Admin/ServerTabs/RegistryTest.php, and
tests/phpunit/Public/Renderers/PublicApiTest.php. Do NOT touch the CLI auth
stack: FrontendAuth, CliController, wp_acrossai_mcp_cli_auth_logs, or
includes/Database/CliAuthLog/. Do NOT touch AbstractClientRenderer,
NpmClientBlock, or MCPClientsBlock — they are non-connector. Do NOT rename any
surviving table or option key. Memory hygiene per
PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION: mark any DEC-CLAUDE-CONNECTOR-* or
DEC-OAUTH-* decisions as Superseded (Feature 016) with the original body intact;
supersede [[project_connector_oauth_gap]] with a forward-pointer note."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all four of
> these governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, hook registration rules,
>    Before Commit Checklist.
> 2. `docs/planings-tasks/011-berlindb-migration.md` — the sibling column
>    definitions in `MCPServer/Schema.php` were introduced by Feature 011; the
>    `$version` bump + phantom-version guard mechanics for the column drop are
>    defined there.
> 3. `docs/planings-tasks/013-per-server-tabs-refactor.md` — introduced the
>    per-server tab architecture and the `ClaudeConnectorBlock` shared
>    admin/public renderer; the tab / shortcode / action-hook shape being
>    removed here is documented there.
> 4. `docs/planings-tasks/phase-6-oauth.md` — original design of the
>    Claude Connectors OAuth 2.1 flow (discovery, authorize, token, bearer,
>    audit). Feature 016 is the deliberate reversal of this feature.
>
> Every decision — which classes to delete outright vs prune, whether to bump
> BerlinDB's `$version` to trigger the column drop, whether to keep any OAuth
> infrastructure "for future use" — must be justified against the above.
> Default: DELETE. Any surviving reference to Claude Connectors after Feature
> 016 is a defect.
>
> **Public API artifacts being retired (grep-gate before + after — no
> surviving call site permitted):**
>
> - `\AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors`
> - `\AcrossAI_MCP_Manager\Includes\OAuth\Storage`
> - `\AcrossAI_MCP_Manager\Includes\OAuth\AuditLog`
> - `\AcrossAI_MCP_Manager\Includes\OAuth\TokenController`
> - `\AcrossAI_MCP_Manager\Includes\OAuth\BearerAuth`
> - `\AcrossAI_MCP_Manager\Includes\OAuth\PKCE`
> - `\AcrossAI_MCP_Manager\Includes\OAuth\CliCommand`
> - `\AcrossAI_MCP_Manager\Includes\Database\OAuthToken\{Table,Schema,Query,Row}`
> - `\AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\{Table,Schema,Query,Row}`
> - `\AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\ClaudeConnectorTab`
> - `\AcrossAI_MCP_Manager\Admin\Partials\ConnectorAuditLogListTable`
> - `\AcrossAI_MCP_Manager\Public\Renderers\ClaudeConnectorBlock`
>
> Pre-flight grep (records every call site whose removal must be verified):
> ```
> grep -rEn '(claude[_-]connector|ClaudeConnector|OAuth\\\\(Storage|AuditLog|TokenController|BearerAuth|PKCE|CliCommand)|OAuthToken|OAuthAudit|acrossai_mcp_claude_connectors_enabled|acrossai_mcp_oauth_cleanup|frontend-oauth)' \
>     --include='*.php' --include='*.js' --include='*.scss' --include='*.css' --include='*.json' \
>     includes/ admin/ public/ src/ tests/ webpack.config.js uninstall.php acrossai-mcp-manager.php
> ```
> Save this output to `specs/016-remove-claude-connectors/pre-flight-callers.txt`.
> After every TASK, re-run the same grep — hits should shrink monotonically to
> **zero** by end of TASK-10.
>
> **Retired table + option + hook map:**
>
> | Kind | Name | Removal site |
> | --- | --- | --- |
> | Table | `wp_acrossai_mcp_oauth_tokens` | `uninstall.php` DROP + `Activator` upgrade path |
> | Table | `wp_acrossai_mcp_oauth_audit` | same |
> | Column | `claude_connector_client_id` | MCPServer Schema `$version` bump |
> | Column | `claude_connector_client_secret` | same |
> | Column | `claude_connector_redirect_uri` | same |
> | Option | `acrossai_mcp_claude_connectors_enabled` | `SettingsMenu.php` unregistration + `uninstall.php` deletion |
> | Option | `acrossai_mcp_oauth_tokens_db_version` | `uninstall.php` deletion |
> | Option | `acrossai_mcp_oauth_audit_db_version` | same |
> | Cron event | `acrossai_mcp_oauth_cleanup` | `Deactivator::deactivate()` unschedule |
> | Rewrite rules | 3 rules registered by `ClaudeConnectors::register_rewrite_rules()` | dropped by `flush_rewrite_rules()` on activation |
> | Shortcode | `acrossai_mcp_claude_connector_block` | `ClientRendererController::register_shortcodes_and_actions()` |
> | Action hook | dispatch map entry `'claude-connector'` in `acrossai_mcp_render_client_block` | same |
> | REST route | `POST /wp-json/acrossai-mcp/v1/token` | `TokenController::register_routes()` deletion |
> | Filter hook | `determine_current_user` bearer resolver | `BearerAuth` deletion |
>
> ---
>
> **TASK-1 — Delete admin surface: tab, list table, settings action, settings toggle**
>
> Files:
> - DELETE `admin/Partials/ServerTabs/ClaudeConnectorTab.php`
> - DELETE `admin/Partials/ConnectorAuditLogListTable.php`
> - MODIFY `admin/Partials/ServerTabs/Registry.php` — remove the
>   `new ClaudeConnectorTab()` entry from `all_tabs()` and the `use` import.
> - MODIFY `admin/Partials/Settings.php` — remove `'save_claude_connector'`
>   from the allowed-actions list in `handle_actions()`, the
>   `if ( 'save_claude_connector' === $action ) { ... }` branch, and the
>   `handle_save_claude_connector()` private method in its entirety.
> - MODIFY `admin/Partials/SettingsMenu.php` — remove the
>   `register_setting( 'acrossai-settings', 'acrossai_mcp_claude_connectors_enabled', ... )`
>   call, the paired `add_settings_section()` + `add_settings_field()` calls
>   for the "Claude Connectors" section, and the
>   `render_claude_connectors_section_description()` method.
>
> Verify: after this task, the plugin's server-edit page renders 10 tabs
> (was 11), and Settings → MCP renders without a "Claude Connectors" section.
>
> ---
>
> **TASK-2 — Delete public renderer + REST dispatcher entry**
>
> Files:
> - DELETE `public/Renderers/ClaudeConnectorBlock.php`
> - MODIFY `includes/REST/ClientRendererController.php` — remove:
>   - The `add_shortcode( 'acrossai_mcp_claude_connector_block', ... )`
>     registration inside `register_shortcodes_and_actions()`.
>   - The `'claude-connector' => ClaudeConnectorBlock::class` entry in the
>     dispatch map inside `dispatch_render_action()` (or wherever the map is
>     declared).
>   - The `use` statement for `ClaudeConnectorBlock` at the top of the file.
>
> Do NOT delete the file itself — it still registers the App Password REST
> endpoint and the surviving shortcodes for `NpmClientBlock` and
> `MCPClientsBlock`. Preserve those verbatim.
>
> Verify: `[acrossai_mcp_claude_connector_block server=1]` renders as
> literal shortcode text (not registered); the other two client-block
> shortcodes still work.
>
> ---
>
> **TASK-3 — Delete ClaudeConnectors OAuth class + its hook wiring**
>
> Files:
> - DELETE `includes/OAuth/ClaudeConnectors.php`
> - MODIFY `includes/Main.php::define_public_hooks()` — delete the
>   `$claude_connectors = ClaudeConnectors::instance();` block and all four
>   hook registrations (`init` → `register_rewrite_rules`, `query_vars` filter
>   → `add_query_var`, `template_redirect` priority 9 →
>   `serve_discovery_or_authorize`, `acrossai_mcp_oauth_cleanup` →
>   `handle_cleanup_event`). Remove the `use` statement at the top of the
>   file.
> - MODIFY `includes/Activator.php` — delete the
>   `class_exists( ClaudeConnectors::class ) { ClaudeConnectors::instance()->register_rewrite_rules(); }`
>   block, the trailing `flush_rewrite_rules()` call that follows it, and the
>   `if ( ! wp_next_scheduled( 'acrossai_mcp_oauth_cleanup' ) ) { wp_schedule_event(...) }`
>   block. Remove the `use` statement.
> - MODIFY `includes/Deactivator.php` — delete the
>   `wp_clear_scheduled_hook( 'acrossai_mcp_oauth_cleanup' )` line.
>
> Verify: `wp cron event list` shows no `acrossai_mcp_oauth_cleanup` event;
> deactivation does not error; the well-known OAuth discovery URLs
> (`/.well-known/oauth-authorization-server/*`,
> `/.well-known/oauth-protected-resource/*`) return 404.
>
> ---
>
> **TASK-4 — Delete shared OAuth infrastructure**
>
> Files:
> - DELETE `includes/OAuth/Storage.php`
> - DELETE `includes/OAuth/AuditLog.php`
> - DELETE `includes/OAuth/TokenController.php`
> - DELETE `includes/OAuth/BearerAuth.php`
> - DELETE `includes/OAuth/PKCE.php`
> - DELETE `includes/OAuth/CliCommand.php`
> - After the deletions the entire `includes/OAuth/` directory should be
>   empty — remove the directory.
> - MODIFY `includes/Main.php::define_public_hooks()` — delete the
>   `$token_controller = TokenController::instance(); ... add_action('rest_api_init', ...)`
>   block and the
>   `$bearer_auth = BearerAuth::instance(); ... add_filter('determine_current_user', ...)`
>   block, plus their `use` statements.
>
> Verify: pre-flight grep for the seven deleted class names returns zero
> matches; `POST /wp-json/acrossai-mcp/v1/token` returns 404; the
> `determine_current_user` filter no longer resolves bearer tokens (CLI auth
> continues to work — it does not use bearer tokens); `wp cli acrossai-mcp-oauth cleanup`
> exits with "command not registered".
>
> ---
>
> **TASK-5 — Delete OAuthToken + OAuthAudit BerlinDB modules; drop tables in uninstall + Activator**
>
> Files:
> - DELETE `includes/Database/OAuthToken/` (entire directory:
>   `Table.php`, `Schema.php`, `Query.php`, `Row.php`).
> - DELETE `includes/Database/OAuthAudit/` (entire directory).
> - MODIFY `includes/Main.php` — remove any `$table = ...\OAuthToken\Table::instance();`
>   and `$table = ...\OAuthAudit\Table::instance();` calls from the
>   request-time boot method (`bootstrap_database_tables()`) introduced by
>   Feature 011.
> - MODIFY `includes/Activator.php::activate()` — remove the two
>   `Table::instance()->maybe_upgrade()` calls for the OAuth tables and their
>   `use` imports. Add an idempotent `DROP TABLE IF EXISTS` block for both
>   OAuth tables and the two matching `db_version` options
>   (`acrossai_mcp_oauth_tokens_db_version`,
>   `acrossai_mcp_oauth_audit_db_version`) — this ensures existing installs
>   with the tables already present get them cleaned up on reactivation.
> - MODIFY `uninstall.php` — the two OAuth table names in the drop-table
>   list stay (uninstall must still handle installs that skipped the
>   Feature 016 upgrade); the two `db_version` option names also stay in the
>   options-delete list. Add the `acrossai_mcp_claude_connectors_enabled`
>   option to the options-delete list if not already there.
>
> Verify: on an install with pre-Feature-016 data, deactivate + reactivate
> — both OAuth tables and both `db_version` options are gone; no fatal.
> On uninstall of a fresh Feature 016 install: no tables/options remain.
>
> ---
>
> **TASK-6 — Drop the 3 `claude_connector_*` columns from MCPServer**
>
> Files:
> - MODIFY `includes/Database/MCPServer/Schema.php` — delete the three
>   column definitions:
>   - `claude_connector_client_id` (varchar 255 default '')
>   - `claude_connector_client_secret` (varchar 255 default '')
>   - `claude_connector_redirect_uri` (varchar 500 default '')
> - MODIFY `includes/Database/MCPServer/Table.php` — bump `$version` from
>   `'0.0.1'` to `'0.0.2'` so BerlinDB's `maybe_upgrade()` diff engine runs
>   `ALTER TABLE ... DROP COLUMN` on reactivation.
> - MODIFY `includes/Database/MCPServer/Row.php` — remove the three
>   `public $claude_connector_*` property declarations and their three
>   corresponding entries in `to_array()`.
> - MODIFY `includes/Database/MCPServer/DefaultServerSeeder.php` — remove
>   the three `'claude_connector_*' => ''` entries from the insert `$data`
>   array and the three matching `%s` format specifiers.
>
> Verify BEFORE merge: on an install with the columns already present,
> `SHOW COLUMNS FROM wp_acrossai_mcp_servers` after reactivation returns
> 10 rows (was 13); `SHOW WARNINGS` is empty; the surviving 10 columns'
> `SHOW CREATE TABLE` output matches the pre-migration DDL byte-for-byte
> aside from the three deletions.
>
> If BerlinDB's diff engine does NOT execute `DROP COLUMN` on a version
> bump (verify by reading `vendor/berlindb/core/src/Database/Kern/Table.php::maybe_upgrade()`
> BEFORE writing this task's code), fall back to an explicit
> `$wpdb->query( "ALTER TABLE {$prefix}acrossai_mcp_servers DROP COLUMN ..." )`
> triple-statement inside `Activator::activate()` gated on
> `if ( in_array( 'claude_connector_client_id', $wpdb->get_col( 'DESCRIBE ...', 0 ), true ) )`.
> The gate keeps the ALTER idempotent across reactivations.
>
> ---
>
> **TASK-7 — Delete OAuth CSS bundle + public-side enqueue methods**
>
> Files:
> - DELETE `src/scss/frontend-oauth.scss`
> - MODIFY `webpack.config.js` — remove the `'css/frontend-oauth': path.resolve(...)`
>   entry from the `entry` object (currently lines 97–101).
> - DELETE `build/css/frontend-oauth.css`, `build/css/frontend-oauth-rtl.css`,
>   `build/css/frontend-oauth.asset.php` (or run `npm run build` which
>   regenerates `build/` from source and naturally omits the OAuth outputs).
> - MODIFY `public/Main.php` — delete `enqueue_styles()` and
>   `enqueue_scripts()` methods in their entirety (both are guarded by
>   `ClaudeConnectors::is_authorize_page()` and would fail with an undefined
>   class error otherwise). Remove `OAUTH_STYLE_HANDLE` constant + the
>   `use ClaudeConnectors` import.
> - MODIFY `includes/Main.php::define_public_hooks()` — remove the
>   `wp_enqueue_scripts` hook registrations for `Public\Main::enqueue_styles`
>   and `Public\Main::enqueue_scripts`.
>
> Verify: `npm run build` exits 0 and does NOT produce `build/css/frontend-oauth*`;
> loading any frontend page does not enqueue `acrossai-mcp-frontend-oauth`
> (verify via View Source); CLI auth frontend page still enqueues
> `acrossai-mcp-frontend` (the non-connector stylesheet — DO NOT delete).
>
> ---
>
> **TASK-8 — Update / delete tests**
>
> Files:
> - DELETE `tests/phpunit/OAuth/` (entire directory including `fixtures/`) — 22 files.
> - DELETE `tests/phpunit/Public/MainEnqueueTest.php` — targets the deleted
>   `enqueue_styles/scripts` methods.
> - MODIFY `tests/phpunit/Admin/SettingsMenuTest.php` — remove any
>   assertion that `acrossai_mcp_claude_connectors_enabled` is registered
>   or that the "Claude Connectors" section exists.
> - MODIFY `tests/phpunit/Admin/ServerTabs/RegistryTest.php` — remove
>   `'claude-connector'` from the expected-tabs slug list; update the
>   tab-count assertion from 11 to 10.
> - MODIFY `tests/phpunit/Public/Renderers/PublicApiTest.php` — read the
>   file first; if it is exclusively about `ClaudeConnectorBlock`, delete
>   the file. Otherwise, remove only the connector-specific test methods
>   and their fixtures.
>
> Do NOT touch `tests/phpunit/Public/Renderers/AbstractClientRendererTest.php`
> — it tests the base class shared with the surviving renderers.
>
> Verify: `composer test` runs cleanly with the reduced suite; PHPStan L8
> + PHPCS remain green.
>
> ---
>
> **TASK-9 — Callers verification sweep (grep-only, no code change)**
>
> Files: (none — grep-only)
>
> Re-run the pre-flight grep captured in the header of this feature. Compare
> against `specs/016-remove-claude-connectors/pre-flight-callers.txt`. Every
> hit from the pre-flight capture MUST be gone. Any remaining hit is a
> defect — fix the missed deletion in a follow-up task; do NOT close
> Feature 016 until this grep returns **zero** matches.
>
> Additional final-repo audit:
>
> ```
> grep -rEn '(claude[_-]connector|ClaudeConnector|acrossai_mcp_claude_connectors_enabled|acrossai_mcp_oauth_cleanup|frontend-oauth|OAuthToken|OAuthAudit|OAuth\\\\(Storage|AuditLog|TokenController|BearerAuth|PKCE|CliCommand))' \
>     --include='*.php' --include='*.js' --include='*.scss' --include='*.css' --include='*.json' \
>     includes/ admin/ public/ src/ tests/ webpack.config.js uninstall.php acrossai-mcp-manager.php
> ```
>
> Expected: zero matches. Docs under `docs/` are excluded from the audit —
> historical references there are intentionally preserved for archaeology.
>
> ---
>
> **TASK-10 — Release notes, memory hygiene, docs update**
>
> Files: `README.txt`, `docs/memory/DECISIONS.md`, `docs/memory/WORKLOG.md`,
> `docs/memory/INDEX.md`, `docs/planings-tasks/README.md`,
> `docs/memory/ARCHITECTURE.md` (if it describes the connector module as
> current-state).
>
> Read `PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION` (from
> `docs/memory/ARCHITECTURE.md` or sibling plugin) before editing memory.
>
> `README.txt` — add an Unreleased changelog bullet:
> ```
> * Removed the Claude Connectors integration (OAuth 2.1 authorization
>   server, admin tab, settings toggle, per-server audit log, shortcode,
>   and the two dedicated OAuth database tables). The feature never worked
>   with claude.ai's hosted Connectors UI in local installs and has been
>   retired. The three `claude_connector_*` columns on the MCP Servers
>   table are dropped on reactivation via a BerlinDB `$version` bump.
> ```
>
> `docs/memory/DECISIONS.md` — mark the following as
> **Superseded (Feature 016)** if present:
> - Any `DEC-CLAUDE-CONNECTOR-*` entry.
> - Any `DEC-OAUTH-*` entry that describes ClaudeConnectors or the OAuth
>   token/audit tables.
> - Any `DEC-FRONTEND-OAUTH-STYLESHEET-*` entry.
> Do NOT supersede CLI-auth decisions (`DEC-CLI-AUTH-*`, `DEC-FRONTEND-AUTH-*`)
> — those describe the separate stack that stays.
>
> `docs/memory/WORKLOG.md` — add a Feature 016 milestone entry (Why durable
> / Future mistake prevented / Evidence / Where to look). Durable lesson:
> **a spec that never validates the real client's requirements (OAuth 2.1
> dynamic registration + public reachability) can accumulate thousands of
> lines of code before the gap surfaces; validate a minimum-reachable path
> against the real client before scaling the implementation.**
>
> `docs/memory/INDEX.md` — mark superseded rows, append a Feature 016
> WORKLOG row.
>
> `docs/planings-tasks/README.md` — append a row for
> `016-remove-claude-connectors.md`.
>
> `docs/memory/ARCHITECTURE.md` — if it describes `includes/OAuth/` or
> `includes/Database/OAuth{Token,Audit}/` as current architecture, replace
> the sections with a one-line note: "Retired in Feature 016
> ([[project_connector_oauth_gap]] → retired 2026-07-06)."
>
> Auto-memory hygiene (via [[project_connector_oauth_gap]]):
> - Update or supersede `project_connector_oauth_gap.md` with a
>   forward-pointer to Feature 016's retirement.
> - Update or supersede `project_create_server_ui_plan.md` if it references
>   the connector tab.
>
> ---
>
> **CONSTRAINTS**
>
> - **Delete first, keep nothing "just in case".** The user has explicitly
>   opted for full teardown. Any "stub for future OAuth" file left behind is a
>   defect.
> - **Do not touch the CLI auth stack.** `includes/CLI/`, `includes/REST/CliController.php`,
>   `public/Partials/FrontendAuth.php`, `includes/Database/CliAuthLog/`, and
>   `wp_acrossai_mcp_cli_auth_logs` are OUT OF SCOPE. If any file in the
>   deletion list is grepped from these paths, STOP and re-verify — you
>   deleted a shared dependency.
> - **Do not touch AbstractClientRenderer / NpmClientBlock / MCPClientsBlock.**
>   They are non-connector and continue serving their two remaining
>   shortcodes.
> - **Do not delete `includes/REST/ClientRendererController.php`.** Only the
>   connector-specific shortcode + map entry are removed.
> - **BerlinDB `$version` bump on MCPServer MUST be `0.0.1` → `0.0.2`.**
>   Bumping to any other value silently reruns the phantom-version guard on
>   healthy installs.
> - **The MCPServer surviving 10 columns' `CREATE TABLE` DDL must remain
>   byte-for-byte identical** — same types, same lengths, same defaults, same
>   nullability, same index names. BerlinDB `maybe_upgrade()` diffs the DDL;
>   any incidental change fires an unwanted `ALTER TABLE`.
> - **Do NOT add data-migration steps.** No `SELECT ... INSERT` from OAuth
>   tables to any other table. The data is being discarded.
> - **Do NOT skip `flush_rewrite_rules()` on reactivation.** The three
>   OAuth rewrite rules (from `ClaudeConnectors::register_rewrite_rules()`)
>   are stale on existing installs; reactivation-time flush clears them.
>   Add a one-off `flush_rewrite_rules()` call in `Activator::activate()`
>   AFTER the OAuth-column drop so the rewrite table is rebuilt without the
>   deleted rules.
> - **Do not touch any file under `vendor/`.** No composer dependency is
>   removed or bumped by this feature.
> - **Every task must leave PHPStan level 8 + PHPCS individually green
>   before moving to the next.** Constitution §VII per-task gating applies.
> - **The final grep audit MUST return zero matches** for the audit
>   patterns above (excluding `docs/`). This is the merge gate for
>   Feature 016.
> - **Memory hygiene per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION.** Do
>   not delete DECISIONS.md rows — mark them Superseded with the body
>   preserved. Auto-memory rows follow the same rule.

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
npm run build

# 6. Review + memory + commit
/speckit.analyze
/speckit.architecture-guard.architecture-review
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

---

## Manual Verification Checklist

### TASK-1 — Admin surface removal
- [ ] `admin/Partials/ServerTabs/ClaudeConnectorTab.php` and
      `admin/Partials/ConnectorAuditLogListTable.php` are gone.
- [ ] Server-edit page renders exactly 10 tabs (was 11); no
      "Claude Connector" tab.
- [ ] Settings → MCP page has no "Claude Connectors" section / toggle.
- [ ] Attempting `POST admin.php?action=save_claude_connector` on a
      server page does nothing (action not in whitelist).

### TASK-2 — Public renderer + REST dispatch
- [ ] `public/Renderers/ClaudeConnectorBlock.php` is gone.
- [ ] `[acrossai_mcp_claude_connector_block server=1]` on a test page
      renders as literal text (shortcode not registered).
- [ ] `[acrossai_mcp_npm_block server=1]` and
      `[acrossai_mcp_clients_block server=1]` still work.
- [ ] `do_action('acrossai_mcp_render_client_block', 'claude-connector', 1)`
      emits nothing.

### TASK-3 — ClaudeConnectors + hooks
- [ ] `includes/OAuth/ClaudeConnectors.php` is gone.
- [ ] `wp cron event list` shows no `acrossai_mcp_oauth_cleanup`.
- [ ] `curl -sI https://LOCAL/.well-known/oauth-authorization-server/mcp/1`
      returns 404 (was serving discovery JSON).
- [ ] `?acrossai_mcp_oauth=authorize&server=1` returns 404.
- [ ] Reactivation runs cleanly; no PHP fatal / debug.log noise.

### TASK-4 — Shared OAuth infra
- [ ] `includes/OAuth/` directory does not exist.
- [ ] `curl -X POST https://LOCAL/wp-json/acrossai-mcp/v1/token` returns
      404 REST error.
- [ ] Sending `Authorization: Bearer XXXXX` on an authenticated REST
      request does NOT elevate to a user (bearer filter deleted).
- [ ] `wp acrossai-mcp-oauth cleanup` prints "not a registered command".
- [ ] CLI auth end-to-end still works (`FrontendAuth` unchanged).

### TASK-5 — OAuth BerlinDB modules + tables
- [ ] `includes/Database/OAuthToken/` and `includes/Database/OAuthAudit/`
      directories do not exist.
- [ ] On an install with pre-Feature-016 data:
  - [ ] `wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_oauth_%'"` returns
        empty after reactivation.
  - [ ] `wp option get acrossai_mcp_oauth_tokens_db_version` returns not-set.
  - [ ] `wp option get acrossai_mcp_oauth_audit_db_version` returns not-set.
- [ ] `uninstall.php` still drops the two tables (safety net for installs
      that skipped Feature 016).
- [ ] `Main::bootstrap_database_tables()` no longer references the two
      deleted modules.

### TASK-6 — MCPServer column drop
- [ ] `wp db query "DESCRIBE wp_acrossai_mcp_servers"` returns 10 rows
      (was 13); the three `claude_connector_*` columns are absent.
- [ ] `SHOW CREATE TABLE wp_acrossai_mcp_servers` output matches the
      pre-migration DDL byte-for-byte except for the three deleted columns.
- [ ] `wp option get acrossai_mcp_manager_db_version` returns `0.0.2`.
- [ ] `SELECT COUNT(*) FROM wp_acrossai_mcp_servers` returns the same
      count as before the reactivation (no data lost).
- [ ] `SHOW WARNINGS` after reactivation is empty.

### TASK-7 — Frontend OAuth CSS + enqueue
- [ ] `src/scss/frontend-oauth.scss` is gone.
- [ ] `webpack.config.js` has no `css/frontend-oauth` entry.
- [ ] `npm run build` exits 0 and does NOT produce
      `build/css/frontend-oauth*`.
- [ ] `build/css/frontend-oauth*` files are absent.
- [ ] `public/Main.php` has no `enqueue_styles()` / `enqueue_scripts()`
      methods.
- [ ] `includes/Main.php::define_public_hooks()` has no
      `wp_enqueue_scripts` hook targeting `Public\Main`.
- [ ] Any frontend page loads with no `acrossai-mcp-frontend-oauth`
      stylesheet reference in the HTML source.
- [ ] CLI auth frontend still enqueues `acrossai-mcp-frontend`.

### TASK-8 — Tests
- [ ] `tests/phpunit/OAuth/` does not exist.
- [ ] `tests/phpunit/Public/MainEnqueueTest.php` does not exist.
- [ ] `composer test` runs cleanly with the reduced suite; PHPStan L8 +
      PHPCS remain green on all remaining files.
- [ ] `tests/phpunit/Admin/ServerTabs/RegistryTest.php` asserts the
      updated 10-tab set (no `claude-connector` slug).
- [ ] `tests/phpunit/Admin/SettingsMenuTest.php` has no assertion for
      `acrossai_mcp_claude_connectors_enabled`.

### TASK-9 — Grep audit
- [ ] Pre-flight grep at `specs/016-remove-claude-connectors/pre-flight-callers.txt`
      exists.
- [ ] Post-implementation re-run of the same grep returns ZERO matches
      (excluding `docs/`).
- [ ] Additional final-repo audit grep (from TASK-9's spec above) returns
      ZERO matches.

### TASK-10 — Release notes + memory hygiene
- [ ] `README.txt` Unreleased changelog contains the Feature 016 bullet.
- [ ] `docs/memory/DECISIONS.md`: any `DEC-CLAUDE-CONNECTOR-*` or
      `DEC-OAUTH-*` (connector-flavored) or
      `DEC-FRONTEND-OAUTH-STYLESHEET-*` entries are marked
      Superseded (Feature 016) with the original body intact.
- [ ] `docs/memory/WORKLOG.md`: Feature 016 milestone entry added.
- [ ] `docs/memory/INDEX.md`: Superseded rows updated + WORKLOG row
      appended.
- [ ] `docs/planings-tasks/README.md` lists
      `016-remove-claude-connectors.md`.
- [ ] `docs/memory/ARCHITECTURE.md` no longer describes the OAuth /
      Claude Connectors module as current architecture.
- [ ] Auto-memory `project_connector_oauth_gap.md` has a forward-pointer
      to Feature 016 (or is superseded).

### Final full-repo audit (blocker before merge)

```
grep -rEn '(claude[_-]connector|ClaudeConnector|acrossai_mcp_claude_connectors_enabled|acrossai_mcp_oauth_cleanup|frontend-oauth|OAuthToken|OAuthAudit|OAuth\\\\(Storage|AuditLog|TokenController|BearerAuth|PKCE|CliCommand))' \
    --include='*.php' --include='*.js' --include='*.scss' --include='*.css' --include='*.json' \
    includes/ admin/ public/ src/ tests/ webpack.config.js uninstall.php acrossai-mcp-manager.php
```

- [ ] Grep returns **zero matches**. Any hit is a defect.

### Quality gates (all must be green before commit)
- [ ] PHPStan level 8 — zero errors.
- [ ] PHPCS — zero errors.
- [ ] `composer test` — PHPUnit remaining tests all pass.
- [ ] `composer dump-autoload` — succeeds with zero warnings.
- [ ] `npm run build` — succeeds with zero warnings.
- [ ] `SHOW TABLES LIKE 'wp_acrossai_mcp_%'` on a fresh install returns
      exactly two rows (`wp_acrossai_mcp_servers`,
      `wp_acrossai_mcp_cli_auth_logs`) — was four.

---

## Pre-flight Attestation (SEC-016-001 / T001)

**Captured**: 2026-07-06 via the plan mode `AskUserQuestion` scoping step
that confirmed full teardown.

**Attestation**: No site outside `~/local-sites/` runs this plugin with
issued Claude Connector OAuth tokens, audit rows, or client
credentials populated on the three `claude_connector_*` MCPServer columns.
The plugin is dev/local only; no live install has real connector data that
Feature 016 could orphan.

**Basis for**: FR-teardown authorization (table drops, column drops,
option deletes, cron unschedule, rewrite-rule flush).

**Attesting user**: raftaar1191@gmail.com

**Validity window**: 2026-07-06 → Feature 016 merge. Any new install
between attestation and merge that populates connector data invalidates
the teardown premise and requires re-scoping.

---

## Emergent Fixes (post-workflow — fill in after implementation)

_This section is a placeholder. As Feature 016 is implemented, any
surprise findings — e.g., a missed connector-referencing file surfaced
by the TASK-9 grep, a BerlinDB diff-engine behavior different from what
this doc assumes for the column drop, a test-suite regression from
deleting `MainEnqueueTest.php` — get logged here with symptom + root
cause + fix._

---

## Evidence Collation Template (fill in after TASK-9 + TASK-10 + PHPUnit)

_Merge-gate evidence pack. Follow the shape of Feature 011's evidence
section: reactivation smoke on a pre-Feature-016 install (columns +
tables actually drop), post-uninstall smoke (all traces gone), final
grep audit output, PHPUnit output, PHPStan + PHPCS output._
