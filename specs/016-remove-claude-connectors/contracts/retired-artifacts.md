# Retired Public API Surface (Feature 016)

This is the machine-checkable list of public artifacts retired by Feature 016. Every entry MUST return zero hits in the FR-015 grep audit after implementation.

## PHP classes (FQN)

- `AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors`
- `AcrossAI_MCP_Manager\Includes\OAuth\Storage`
- `AcrossAI_MCP_Manager\Includes\OAuth\AuditLog`
- `AcrossAI_MCP_Manager\Includes\OAuth\TokenController`
- `AcrossAI_MCP_Manager\Includes\OAuth\BearerAuth`
- `AcrossAI_MCP_Manager\Includes\OAuth\PKCE`
- `AcrossAI_MCP_Manager\Includes\OAuth\CliCommand`
- `AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Table`
- `AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Schema`
- `AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Query`
- `AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Row`
- `AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Table`
- `AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Schema`
- `AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query`
- `AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Row`
- `AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\ClaudeConnectorTab`
- `AcrossAI_MCP_Manager\Admin\Partials\ConnectorAuditLogListTable`
- `AcrossAI_MCP_Manager\Public\Renderers\ClaudeConnectorBlock`

## WordPress hooks (registered by retired classes)

Actions (removed via deletion of their registration blocks in `Main.php::define_public_hooks()`):
- `init` → `ClaudeConnectors::register_rewrite_rules`
- `template_redirect` (priority 9) → `ClaudeConnectors::serve_discovery_or_authorize`
- `acrossai_mcp_oauth_cleanup` → `ClaudeConnectors::handle_cleanup_event` *(the cron event itself is also unscheduled)*
- `rest_api_init` → `TokenController::register_routes`
- `wp_enqueue_scripts` → `Public\Main::enqueue_styles`
- `wp_enqueue_scripts` → `Public\Main::enqueue_scripts`

Filters (removed via deletion of their registration blocks):
- `query_vars` → `ClaudeConnectors::add_query_var`
- `determine_current_user` (priority 20) → `BearerAuth::resolve_bearer_token`

## REST routes

- `POST /wp-json/acrossai-mcp/v1/token` (was `TokenController::register_routes()`)

## Shortcodes

- `[acrossai_mcp_claude_connector_block server=X]`

## Action-hook dispatch entries

- `'claude-connector' => ClaudeConnectorBlock::class` in the map inside `ClientRendererController::dispatch_render_action()`

## URL surfaces (become 404)

- `/.well-known/oauth-authorization-server/mcp/{server}` (any suffix)
- `/.well-known/oauth-protected-resource/mcp/{server}` (any suffix)
- `?acrossai_mcp_oauth=authorize&server={id}` and companion query-var URLs

## WP-CLI commands

- The WP-CLI `cleanup` command registered by `CliCommand` (exact `wp` command name depends on the class's `add_command()` call — verify at implementation time and confirm it no longer resolves).

## Database tables (dropped)

- `{wpdb->prefix}acrossai_mcp_oauth_tokens`
- `{wpdb->prefix}acrossai_mcp_oauth_audit`

## Database columns (dropped from `{wpdb->prefix}acrossai_mcp_servers`)

- `claude_connector_client_id` (`varchar(255)`, default `''`)
- `claude_connector_client_secret` (`varchar(255)`, default `''`)
- `claude_connector_redirect_uri` (`varchar(500)`, default `''`)

## WordPress options (deleted)

- `acrossai_mcp_claude_connectors_enabled`
- `acrossai_mcp_oauth_tokens_db_version`
- `acrossai_mcp_oauth_audit_db_version`

## WordPress cron events (unscheduled)

- `acrossai_mcp_oauth_cleanup`

## Asset handles (never enqueued again)

- `acrossai-mcp-frontend-oauth` (was CSS handle from `Public\Main::OAUTH_STYLE_HANDLE`)

## Files deleted outright

- `includes/OAuth/` (directory + 7 files)
- `includes/Database/OAuthToken/` (directory + 4 files)
- `includes/Database/OAuthAudit/` (directory + 4 files)
- `admin/Partials/ServerTabs/ClaudeConnectorTab.php`
- `admin/Partials/ConnectorAuditLogListTable.php`
- `public/Renderers/ClaudeConnectorBlock.php`
- `src/scss/frontend-oauth.scss`
- `build/css/frontend-oauth.css`, `build/css/frontend-oauth-rtl.css`, `build/css/frontend-oauth.asset.php`
- `tests/phpunit/OAuth/` (directory + 22 files including `fixtures/`)
- `tests/phpunit/Public/MainEnqueueTest.php`

## Retained (verified NOT to be touched — cross-check at implementation time)

- `includes/CLI/` — CLI auth stack (WP-CLI commands unrelated to OAuth)
- `includes/REST/CliController.php` — CLI auth REST controller
- `includes/Database/CliAuthLog/` — separate BerlinDB module
- `wp_acrossai_mcp_cli_auth_logs` table
- `public/Partials/FrontendAuth.php` — CLI auth browser approval page
- `src/scss/frontend.scss`, `build/css/frontend.css` — CLI auth stylesheet
- `public/Renderers/AbstractClientRenderer.php` — base class for `NpmClientBlock` and `MCPClientsBlock`
- `public/Renderers/NpmClientBlock.php`, `MCPClientsBlock.php`
- Shortcodes `[acrossai_mcp_npm_block ...]`, `[acrossai_mcp_clients_block ...]`
- Action-hook dispatch entries `'npm'`, `'clients'` in the surviving 2-entry map
- `wp_acrossai_mcp_cli_auth_log_db_version` option
- WP Application Passwords in `wp_usermeta` (CLI auth uses these; unrelated to OAuth tokens)

## Grep verification (FR-015)

The following regex MUST return zero matches (excluding `docs/`):

```regex
(claude[_-]connector|ClaudeConnector|OAuth\\\\(Storage|AuditLog|TokenController|BearerAuth|PKCE|CliCommand)|OAuthToken|OAuthAudit|acrossai_mcp_claude_connectors_enabled|acrossai_mcp_oauth_cleanup|frontend-oauth)
```

Applied to: `includes/`, `admin/`, `public/`, `src/`, `tests/`, `webpack.config.js`, `uninstall.php`, `acrossai-mcp-manager.php`.

**Bug pattern B15 guard**: Verify the regex matches BOTH bare `use OAuth\Storage;` and leading-`\` `\AcrossAI_MCP_Manager\Includes\OAuth\Storage` forms before treating zero-hits as pass. Seed a fake FQN reference in a scratch file, run the grep, confirm it matches, then remove the fake.
