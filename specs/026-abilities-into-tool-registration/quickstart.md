# Quickstart — Feature 026 reviewer walkthrough

> ⚠️ **2026-07-15 revert — the walkthrough below is largely superseded.**
>
> F026 v1's tools-widening was reverted by commit `0e122e2`. Steps `US1 §Acceptance 1–4` (which assert `mcp.public = true` abilities appear in `tools/list`) and `US2 §Acceptance 1–3` (which exercise the widened `acrossai_mcp_manager_server_tools` filter) NO LONGER MATCH shipped behavior. The equivalent modern verification path is:
>
> 1. **Scratch ability visible only via meta tools** — register a public ability, call `tools/list` on the server: expect ONLY 3 built-ins + F020 curated (NOT the scratch ability). Then call `tools/call` on `mcp-adapter-discover-abilities` (note: SANITIZED name with hyphens, not `mcp-adapter/discover-abilities`): expect the scratch ability in the response's `abilities` array.
> 2. **Abilities-tab toggle authoritative** — toggle the ability OFF on `?page=acrossai_mcp_manager&action=edit&server=N&tab=abilities`. Re-call `discover-abilities`: the ability should be absent. Toggle back ON, re-call: present. Same expectation for `execute-ability` (403 `acrossai_mcp_ability_not_exposed_for_server` when hidden).
> 3. **`acrossai_mcp_is_ability_exposed` filter** — hook the new filter and return `false` for a specific slug on a specific server_id: verify it disappears from `discover-abilities` on that server only.
>
> Steps for US3 (REST GET, Tools-tab UX, fail-open, F017 call-time gate) are still valid as written.
>
> Resources / prompts widening (US4–US5 in `spec.md`) is UNCHANGED; those checks still apply.
>
> Original walkthrough retained below for historical reference and for anyone re-hydrating F026 v1's behavior on a branch.

---

Manual walkthrough for a reviewer or QA engineer to verify Feature 026 end-to-end after implementation. Assumes the plugin is installed and active on a LocalWP-style site (baseline: `wordpress-7-0.local`).

## Prerequisites

- WordPress 6.9+ on PHP 8.1+.
- Plugin active with F025 already shipped (F026 extends F025's composer — verify F025 is in place first).
- At least one enabled MCP server. If none exist, enable the seeded default server via **MCP Manager → Servers → Default MCP Server → Enable**.
- WP-CLI available for CLI checks.
- A scratch mu-plugin capability (drop a file at `wp-content/mu-plugins/f026-scratch.php`).

## Register a scratch public ability

Drop this file at `wp-content/mu-plugins/f026-scratch.php` to register two abilities: one public, one non-public.

```php
<?php
add_action( 'wp_abilities_api_init', static function () {
    wp_register_ability( 'my-plugin/public-echo', [
        'label'       => 'Public Echo',
        'description' => 'A publicly-exposed test ability for F026 walkthrough.',
        'category'    => 'test',
        'meta'        => [
            'mcp' => [ 'public' => true ],
        ],
        'input_schema' => [ 'type' => 'object', 'properties' => new stdClass() ],
        'output_schema' => [ 'type' => 'object', 'properties' => new stdClass() ],
        'execute_callback' => static fn () => [ 'echo' => 'hello F026' ],
    ] );

    wp_register_ability( 'my-plugin/non-public-echo', [
        'label'       => 'Non-Public Echo',
        'description' => 'A privately-scoped test ability for F026 walkthrough.',
        'category'    => 'test',
        'meta'        => [
            'mcp' => [ 'public' => false ],
        ],
        'input_schema' => [ 'type' => 'object', 'properties' => new stdClass() ],
        'output_schema' => [ 'type' => 'object', 'properties' => new stdClass() ],
        'execute_callback' => static fn () => [ 'echo' => 'private F026' ],
    ] );
} );
```

## US1 §Acceptance 1 — Public ability appears in `tools/list`

1. Open **MCP Manager → Servers → [any enabled server] → Edit → Abilities tab**.
2. Verify `my-plugin/public-echo` appears in the list with default visibility ON (or no explicit override).
3. `curl` the server's endpoint:

    ```bash
    curl -X POST \
        "https://wordpress-7-0.local/wp-json/mcp/<slug>" \
        -H 'Content-Type: application/json' \
        -u 'admin:app-password-here' \
        -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
    ```

4. Expected: response `result.tools` includes `my-plugin/public-echo` alongside the three protocol slugs.
5. Expected: response `result.tools` does NOT include `my-plugin/non-public-echo` (its `mcp.public = false` default keeps it hidden).

## US1 §Acceptance 2 — Toggling OFF removes the ability

1. On the Abilities tab, toggle `my-plugin/public-echo` **OFF** for the server. This writes `is_exposed = 0` to `wp_acrossai_mcp_server_abilities`.
2. Re-issue the `tools/list` curl.
3. Expected: `my-plugin/public-echo` is gone from the response.
4. Verify DB:

    ```bash
    wp db query "SELECT ability_slug, is_exposed FROM wp_acrossai_mcp_server_abilities WHERE server_id = <server_id>"
    ```

    Expected: at least one row with `ability_slug = my-plugin/public-echo` and `is_exposed = 0`.

## US1 §Acceptance 3 — Non-public ability stays hidden by default

1. Confirm `my-plugin/non-public-echo` has no row in `wp_acrossai_mcp_server_abilities` (default state).
2. `tools/list` from step US1 §Acceptance 1 already confirmed it's absent. Re-verify.

## US1 §Acceptance 4 — Toggling ON exposes the non-public ability

1. On the Abilities tab, toggle `my-plugin/non-public-echo` **ON** for the server. Writes `is_exposed = 1`.
2. Re-issue `tools/list`.
3. Expected: `my-plugin/non-public-echo` appears in the response.

## US2 §Acceptance 1 — F025 filter receives widened composed set

1. Amend the scratch mu-plugin to add:

    ```php
    add_filter( 'acrossai_mcp_manager_server_tools', static function ( array $tools, $server ) {
        error_log( '[F026 scratch] server_slug=' . $server->server_slug . ' tools=' . implode( ',', $tools ) );
        return $tools;
    }, 10, 2 );
    ```

2. Re-issue `tools/list` on any DB server.
3. Tail `wp-content/debug.log` (assuming `WP_DEBUG_LOG` on).
4. Expected: log line includes protocol slugs + curated slugs (if any) + `my-plugin/public-echo` (if visible per Abilities tab state).

## US2 §Acceptance 2 — Callback strips a slug

1. Replace the mu-plugin filter body with:

    ```php
    return array_values( array_diff( $tools, [ 'my-plugin/public-echo' ] ) );
    ```

2. Re-issue `tools/list`.
3. Expected: `my-plugin/public-echo` is gone from the response (proof the filter still strips slugs correctly on the widened input).

## US2 §Acceptance 3 — Vendor filter used for default server

1. Amend the mu-plugin to also hook `mcp_adapter_default_server_config` at priority 20:

    ```php
    add_filter( 'mcp_adapter_default_server_config', static function ( array $config ) {
        if ( is_array( $config['tools'] ?? null ) ) {
            $config['tools'] = array_values( array_diff( $config['tools'], [ 'my-plugin/public-echo' ] ) );
        }
        return $config;
    }, 20 );
    ```

2. `curl` the default server's `tools/list` endpoint.
3. Expected: `my-plugin/public-echo` is absent from the default server's response (proof the vendor filter runs AFTER `Controller::filter_default_server_config()` and can strip further).
4. Delete the mu-plugin filter after verification.

## US3 §Acceptance 1 — Tools tab UI unchanged

1. Open the Tools tab for any server (`?tab=tools`).
2. Verify:
   - `my-plugin/public-echo` is NOT shown in the "Added as tools" pane (unless it's a curated pick, which it isn't here).
   - The count text reads `%1$d of %2$d abilities added as tools` — unchanged from F025.
   - No new UI elements appeared.

## US3 §Acceptance 2 — REST GET unchanged

1. `curl` the Tools tab GET endpoint:

    ```bash
    curl -X GET \
        "https://wordpress-7-0.local/wp-json/acrossai-mcp-manager/v1/servers/<id>/tools" \
        -u 'admin:app-password-here'
    ```

2. Expected: response `tools` array contains ONLY protocol slugs + curated picks — NOT `my-plugin/public-echo` (even though it's in `tools/list` on the MCP endpoint).

## US3 §Acceptance 3 — Saving Tools tab doesn't bleed into F017

1. On the Tools tab, add a curated ability (any ability). Save.
2. Query `wp_acrossai_mcp_server_abilities` for the server.
3. Expected: no new rows in F017's table. F026 does NOT write to F017 storage; F020 POST only writes to `wp_acrossai_mcp_server_tools` + `wp_acrossai_mcp_servers` columns.

## Fail-open (SC-006) — Abilities API absent

Simulated (best-effort — F026 fail-open is defensive coverage for an edge case that's rare on modern WP):

1. Temporarily disable the mu-plugin from step "Register a scratch public ability".
2. If your WP install has `WP_DEBUG_LOG = true`, check `wp-content/debug.log` before and after issuing a `tools/list` request.
3. Expected: no PHP fatal, no `error_log` line about `wp_get_abilities` being missing. Server still advertises protocol + curated (no F017 pass, because there are no abilities to iterate).

## F017 call-time gate regression

1. Re-enable the mu-plugin. Toggle `my-plugin/public-echo` OFF via the Abilities tab.
2. Try to invoke it via `tools/call`:

    ```bash
    curl -X POST \
        "https://wordpress-7-0.local/wp-json/mcp/<slug>" \
        -H 'Content-Type: application/json' \
        -u 'admin:app-password-here' \
        -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"my-plugin/public-echo","arguments":{}}}'
    ```

3. Expected: `403 acrossai_mcp_ability_not_exposed` — F017's `AbilityExposureGate` at priority 20 blocks the call, unchanged from F017 baseline.

## Automated coverage

The full manual walkthrough is codified in PHPUnit:

```bash
vendor/bin/phpunit tests/phpunit/Database/MCPServer/ToolPolicyTest.php
vendor/bin/phpunit tests/phpunit/MCP/ControllerToolsInjectionTest.php
```

Both files MUST pass on the merge-ready branch (the 4 new `test_compose_effective_*` cases and the 1 new `test_register_database_servers_produces_f017_widened_*` case).

## Grep audits

Run all four to confirm the F026 code paths landed correctly and the F025 non-changes were preserved:

```bash
grep -rn "compose_effective_tools_for_row" includes/
# Expected: 3 matches — 1 definition in ToolPolicy.php, 2 call sites in Controller.php

grep -rn "compose_for_row" includes/
# Expected: 3 matches — 1 definition, 1 REST GET in ToolsController.php, 1 internal seed call in ToolPolicy.php

grep -n "apply_filters( 'acrossai_mcp_manager_server_tools'" includes/
# Expected: 1 match — inside register_database_servers() in Controller.php

grep -n "acrossai_mcp_ability_exposure_changed" includes/
# Expected: 1+ matches — inside AbilitiesController.php (F017, unchanged) — proves F017 storage layer is untouched
```

## Sign-off

The reviewer signs off when:

- All 12 checks pass.
- PHPUnit files pass.
- Grep audits return expected counts.
- No PHP errors in `wp-content/debug.log` during the walkthrough.
- `wp option get acrossai_mcp_servers_db_version` still returns `1.1.0` (F025 schema unchanged — F026 does not migrate).
- Companion mu-plugin removed after verification.
