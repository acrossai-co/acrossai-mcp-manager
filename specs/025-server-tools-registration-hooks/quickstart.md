# Quickstart — Feature 025 reviewer walkthrough

Manual walkthrough for a reviewer or QA engineer to verify Feature 025 end-to-end after implementation. Assumes the plugin is installed and active on a LocalWP-style site (baseline: `wordpress-7-0.local`).

## Prerequisites

- WordPress 6.9+ on PHP 8.1+.
- Plugin active. The `wp_acrossai_mcp_servers_db_version` option MUST equal `1.1.0` after activation — verify with `wp option get acrossai_mcp_servers_db_version`.
- At least one enabled MCP server. If none exist, enable the seeded default server via **MCP Manager → Servers → Default MCP Server → Enable**.
- WP-CLI available for the DB and endpoint checks.

## Verify the schema migration

```bash
wp db query "SHOW COLUMNS FROM wp_acrossai_mcp_servers LIKE 'tool_%'"
```

Expected: three rows, `tool_discover_abilities`, `tool_get_ability_info`, `tool_execute_ability`, each `tinyint(1) NOT NULL DEFAULT 1`.

```bash
wp db query "SELECT id, server_slug, tool_discover_abilities, tool_get_ability_info, tool_execute_ability FROM wp_acrossai_mcp_servers"
```

Expected: every row has all three columns equal to `1`.

## User Story 1 — Saved tool selection reaches AI clients

1. Open **MCP Manager → Servers → [any enabled server] → Edit → Tools tab**.
2. Add two curated abilities from the left pane (e.g., `List Plugins`, `Check Updates`).
3. Save.
4. Curl the server's endpoint:

    ```bash
    curl -X POST \
        "https://wordpress-7-0.local/wp-json/mcp/<slug>" \
        -H 'Content-Type: application/json' \
        -u 'admin:app-password-here' \
        -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
    ```

5. Expected: response `result.tools` array contains BOTH curated slugs AND the three protocol slugs.

## User Story 2 — Remove a built-in default

1. Same server, Tools tab.
2. Click **Remove** on **Discover Abilities**.
3. Expected: a `ConfirmDialog` opens with title "Remove built-in tool?" and body starting with "This tool is required by AI clients…". Buttons: "Remove anyway" / "Cancel".
4. Click **Cancel**. Expected: dialog closes; row stays; count text unchanged.
5. Click **Remove** again on **Discover Abilities**, then **Remove anyway**.
6. Expected: row disappears from the right pane, reappears in the left pane's ability pool with the `#fef7e0` background AND a `+ Add` button. Count text decrements by 1.
7. Verify DB:

    ```bash
    wp db query "SELECT tool_discover_abilities FROM wp_acrossai_mcp_servers WHERE id = <server_id>"
    ```

    Expected: `0`.

8. Re-curl `tools/list`. Expected: response omits `mcp-adapter/discover-abilities`.
9. Verify observability: hook `acrossai_mcp_tools_changed` in a mu-plugin with a debug log line; the previous Remove should have logged one entry with `operation='removed'`, `ability_slug='mcp-adapter/discover-abilities'`.

## User Story 3 — Reset restores defaults + clears curated

1. Same server. Confirm state: 1 protocol removed, 2 curated added.
2. Click **Reset** in the "Added as tools" pane header.
3. Expected: `ConfirmDialog` with copy "Reset the tools for this server to only the three built-in defaults?..." and button "Reset to defaults".
4. Click **Reset to defaults**.
5. Expected: right pane refreshes to show exactly the three built-in defaults with `#fef7e0` background. Left pane no longer shows Discover Abilities / Get Ability Info / Execute Ability as `+ Add` candidates. Count text: "3 of N abilities added as tools".
6. Verify DB:

    ```bash
    wp db query "SELECT tool_discover_abilities, tool_get_ability_info, tool_execute_ability FROM wp_acrossai_mcp_servers WHERE id = <server_id>"
    wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_server_tools WHERE server_id = <server_id>"
    ```

    Expected: all three columns `1`; the second query returns `0` (curated rows cleared).

7. Re-curl `tools/list`. Expected: response contains exactly the three protocol slugs.

## User Story 4 — Companion-plugin filter (database-server path)

1. Drop a scratch mu-plugin at `wp-content/mu-plugins/f025-scratch.php`:

    ```php
    <?php
    add_filter( 'acrossai_mcp_manager_server_tools', static function ( $tools, $server ) {
        // Remove execute-ability from every DB-registered server.
        return array_values( array_diff( (array) $tools, [ 'mcp-adapter/execute-ability' ] ) );
    }, 10, 2 );
    ```

2. Re-curl `tools/list` on any DATABASE server (`registered_from='database'`).
3. Expected: response omits `mcp-adapter/execute-ability` — even though the column is `1`.
4. Curl the DEFAULT server's `tools/list` endpoint.
5. Expected: `execute-ability` is STILL present — the filter does NOT fire for the default server.
6. Delete the mu-plugin.

## User Story 4b — Companion-plugin filter (default-server path via vendor filter)

1. Update the scratch mu-plugin:

    ```php
    <?php
    add_filter( 'mcp_adapter_default_server_config', static function ( $config ) {
        if ( is_array( $config['tools'] ?? null ) ) {
            $config['tools'] = array_values( array_diff( $config['tools'], [ 'mcp-adapter/execute-ability' ] ) );
        }
        return $config;
    }, 20 ); // priority 20 to run AFTER Controller::filter_default_server_config()'s default 10.
    ```

2. Curl the default server's `tools/list`.
3. Expected: response omits `mcp-adapter/execute-ability`.
4. Delete the mu-plugin.

## Empty-tool-list warning banner (FR-017)

1. On any server, remove all three built-in defaults (three times through the confirmation dialog) and delete every curated pick.
2. Expected: the right pane renders the warning banner "This server has no tools. AI clients can't discover or execute abilities. Click Reset to restore defaults." with an inline Reset CTA.
3. Click the inline Reset CTA. Expected: the same Reset `ConfirmDialog` opens.
4. Confirm. Expected: back to the three protocol defaults.

## Regression: call-time gate (F020 unchanged)

1. Remove one curated ability from the Tools tab.
2. Attempt to invoke that ability via `tools/call` JSON-RPC.
3. Expected: `403 acrossai_mcp_tool_not_added` error — F020's `ToolExposureGate` at `mcp_adapter_pre_tool_call` priority 30 still enforces.

## Backwards-compat sanity

1. Verify existing REST clients: send a `POST /tools` with a payload containing ONLY curated slugs (no protocol slugs — the pre-F025 shape).
2. Expected: `200`, all three columns flipped to `0` (they were not in the payload), curated rows saved.
3. Verify: this is the DOCUMENTED backwards-compat semantic of the unified payload — the F020 REST-client contract "the payload is the complete tool set" is preserved. Existing REST clients that never sent protocol slugs will now UN-set them on save. If a REST client wants to preserve protocol defaults, it MUST include them in the payload.
4. Recovery for any operator affected: click Reset in the UI.

## Automated coverage

The full manual walkthrough is codified in PHPUnit:

```bash
vendor/bin/phpunit tests/phpunit/Database/MCPServer/ToolPolicyTest.php
vendor/bin/phpunit tests/phpunit/MCP/ControllerToolsInjectionTest.php
vendor/bin/phpunit tests/phpunit/REST/ToolsControllerTest.php
```

All three files MUST pass on the merge-ready branch.

## Sign-off

The reviewer signs off when:

- All eight User Story checks pass.
- Both automated test files pass.
- `wp option get acrossai_mcp_servers_db_version` returns `1.1.0`.
- `grep -rn "mcp-adapter/discover-abilities" includes/` returns exactly one match, inside `ToolPolicy::PROTOCOL_TOOLS`.
- `grep -rn "EXCLUDED_SLUGS" includes/ src/js/` returns zero matches.
