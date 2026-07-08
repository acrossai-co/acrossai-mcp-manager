# Phase 1 — Quickstart

End-to-end validation walkthrough for Feature 020. Run this after `/speckit-implement` completes to verify all Definition of Done gates + Success Criteria on a live install.

**Preconditions**:

- Fresh WordPress 6.9+ install, PHP 8.1+, InnoDB utf8mb4.
- Plugin `acrossai-mcp-manager` freshly activated on branch `020-per-server-tool-selection`.
- At least one MCP server registered (the default seeded server counts).
- The WordPress Abilities API is available (`wp_get_abilities()` returns a non-empty array).
- Test user account with `manage_options` capability.

---

## Step 1 — Fresh activation smoke test

1. Deactivate the plugin.
2. Drop the tools table + option (dev-only reset):
   ```sh
   wp db query "DROP TABLE IF EXISTS wp_acrossai_mcp_server_tools;"
   wp option delete acrossai_mcp_server_tools_db_version
   ```
3. Reactivate the plugin.
4. Verify:
   ```sh
   wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_server_tools'"
   # Expected: 1 row.
   wp option get acrossai_mcp_server_tools_db_version
   # Expected: 1.0.0
   wp db query "SHOW CREATE TABLE wp_acrossai_mcp_server_tools\G"
   # Expected: 5 columns (id, server_id, ability_slug, created_at, updated_at),
   #           3 indexes (PRIMARY, UNIQUE server_ability, KEY server_id),
   #           ability_slug varchar(191).
   wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_server_tools"
   # Expected: 0. Table is empty by design (no seeder).
   ```
5. **Tail `wp-content/debug.log`** during reactivation — must be silent (no phantom-guard notices, no BerlinDB errors).

Maps to: **FR-014, FR-019, phantom-version guard, silent lifecycle**.

---

## Step 2 — Tab renders + empty state

1. Log in as a `manage_options` user.
2. Navigate to `?page=acrossai_mcp_manager&action=edit&server_id={id}&tab=tools` for the seeded server.
3. Verify:
   - Page renders without PHP fatal, JS error in browser console, or blank container.
   - Left column heading reads "All abilities" with a count "N available" where N = total registered abilities minus 3 excluded protocol tools.
   - Right column heading reads "Added as tools" with a "3" badge (the three built-ins are always counted).
   - Right column has an "Always available (built-in)" section at the top listing `Discover Abilities`, `Get Ability Info`, `Execute Ability` — each with a 🔒 lock icon and a "Built-in" label (no Remove button).
   - Below the built-ins, a "Curated tools" section header appears; below it, the "No tools added yet" empty state renders.
   - Below the two columns, an **amber warning banner** appears explaining AI clients can still discover the built-ins but cannot execute any WordPress ability until at least one is curated.
   - Below the warning, an **info banner** cross-links to the Abilities tab and names `wordpress/mcp-adapter`.
   - There is **no** Save / Cancel bar at the bottom (each click auto-commits).
   - The "Reset" button in the right-column header is **disabled** (no curated tools to reset).

Maps to: **FR-016, FR-017, FR-025, User Story 1 Acceptance Scenario 4**.

---

## Step 3 — Add three tools (each auto-commits)

1. Type "post" in the left column search box. Verify the pool narrows to matching abilities.
2. Click "+ Add" on three abilities (e.g., `create-post`, `update-post`, `delete-post`). **Verify one at a time**:
   - Immediately after each click: the row moves from the left column to the right column's "Curated tools" section, an inline "Saving…" indicator appears briefly below the picker, then clears when the POST resolves.
   - Add / Remove / Reset buttons are all momentarily disabled while the POST is in-flight.
   - No page reload occurs.
3. After all three Adds resolve, verify:
   - The counter at the top updates: `3 of N abilities added as tools · 3 built-in always available`.
   - The right column shows the three built-ins at the top + three curated rows below (each with a ✓ green checkmark and a "Remove" button).
   - The zero-added amber warning banner is gone.
   - There is **no Save button anywhere** — each click already persisted.
4. Reload the browser tab and re-navigate to the Tools tab. Verify:
   - The three curated abilities are still in the right column (persisted — reload confirms server-side commit).
   - The counter still reads `3 of N …`.

Maps to: **FR-003, FR-007, FR-008, FR-009, FR-010, FR-014, SC-001, SC-003**.

---

## Step 4 — REST round-trip verification

Run against the same server after Step 3:

```sh
wp eval 'echo wp_create_nonce("wp_rest");'
# Copy the nonce.

curl -s -H "X-WP-Nonce: <nonce>" -H "Cookie: <admin session cookie>" \
    "http://<site>/wp-json/acrossai-mcp-manager/v1/servers/1/tools?include_abilities=1" | jq .
# Expected: { "tools": ["acrossai-core-abilities/create-post", ...], "abilities": [{...}, ...] }
# Verify: no mcp-adapter/* slug appears in "abilities".
```

Test POST:

```sh
curl -s -X POST -H "X-WP-Nonce: <nonce>" -H "Cookie: <admin session cookie>" \
    -H "Content-Type: application/json" \
    -d '{"tools":["acrossai-core-abilities/create-post"]}' \
    "http://<site>/wp-json/acrossai-mcp-manager/v1/servers/1/tools" | jq .
# Expected: { "tools": ["acrossai-core-abilities/create-post"] }
# DB truth: only that one slug remains.
```

Test POST with an invalid slug (should reject all):

```sh
curl -s -X POST -H "X-WP-Nonce: <nonce>" -H "Cookie: <admin session cookie>" \
    -H "Content-Type: application/json" \
    -d '{"tools":["acrossai-core-abilities/create-post","not-a-real-slug"]}' \
    "http://<site>/wp-json/acrossai-mcp-manager/v1/servers/1/tools" | jq .
# Expected: HTTP 400
# {
#   "code": "acrossai_mcp_invalid_tool_slug",
#   "data": { "status": 400, "invalid_slugs": ["not-a-real-slug"] }
# }
# DB truth: unchanged from prior POST.
```

Test 403 (unauthenticated):

```sh
curl -s "http://<site>/wp-json/acrossai-mcp-manager/v1/servers/1/tools"
# Expected: 401 or 403 from WP REST middleware. No response body containing tool data.
```

Maps to: **FR-021, FR-022, SC-002**.

---

## Step 5 — Rollback on POST failure

Simulates the optimistic-per-toggle rollback path (FR-009 rollback semantics).

1. Temporarily add a mu-plugin filter that rejects one specific tool save:
   ```php
   // wp-content/mu-plugins/mcp-tools-test-reject.php
   <?php
   add_filter( 'rest_dispatch_request', function ( $result, $request, $route ) {
       if ( '/acrossai-mcp-manager/v1/servers/1/tools' === $route
            && 'POST' === $request->get_method()
            && in_array( 'acrossai-core-abilities/create-post',
                         (array) $request->get_param( 'tools' ), true ) ) {
           return new WP_Error( 'test_reject', 'Injected test failure', array( 'status' => 500 ) );
       }
       return $result;
   }, 10, 3 );
   ```
2. Reload the Tools tab. Click "+ Add" on `create-post`. Verify:
   - The row moves to the right column briefly (optimistic update).
   - After the POST resolves with 500, the row **rolls back** to the left column.
   - An error `<Notice>` surfaces with "Injected test failure" (or a similar 500 message).
   - `wp db query "SELECT * FROM wp_acrossai_mcp_server_tools WHERE ability_slug='acrossai-core-abilities/create-post'"` returns 0 rows.
3. Delete the mu-plugin. Reload. Click "+ Add" on `create-post` again — this time it commits normally.

Maps to: **FR-009 rollback clause, SC-013 observer isolation adjacent**.

---

## Step 6 — Reset + search

1. Click the **Reset** button in the right column header. Verify:
   - The curated section clears immediately (built-ins stay).
   - "Saving…" indicator appears briefly, then clears.
   - The counter reads `0 of N abilities added as tools · 3 built-in always available`.
   - The amber zero-added warning banner reappears.
   - The Reset button is now disabled (nothing to reset).
2. Reload the browser tab. Verify the curated set is still empty server-side (Reset persisted).
3. Type "block" in the search box. Verify the left column narrows to matching abilities.
4. Click "+ Add" on one of the filtered rows. Verify the row moves to the right column and commits.
5. Clear the search. Verify all non-added abilities reappear in the left column.

Maps to: **FR-006 (Reset), FR-007 (search), User Story 2 (bulk reset), User Story 3 (search narrows pool)**.

---

## Step 7 — Server-deletion cleanup (FR-026)

**Precondition**: Register a second MCP server and add 3 tools to it via the tab.

1. Verify rows exist in the DB:
   ```sh
   wp db query "SELECT * FROM wp_acrossai_mcp_server_tools WHERE server_id = <second_id>"
   # Expected: 3 rows.
   ```
2. Delete the second server via the admin UI (WP-List-Table row action "Delete").
3. Verify rows are gone:
   ```sh
   wp db query "SELECT * FROM wp_acrossai_mcp_server_tools WHERE server_id = <second_id>"
   # Expected: 0 rows.
   ```
4. Re-register a server with the SAME numeric ID (if the AI/DB allows). Verify no stale tool selections appear.

Maps to: **FR-026, Edge Case §Parent MCP server deleted**.

---

## Step 8 — Uninstall behavior (FR-028)

1. On the MCP settings tab, ensure "Delete all data on uninstall" is UNCHECKED (default).
2. Uninstall the plugin via Plugins → Delete.
3. Verify preserved:
   ```sh
   wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_server_tools'"
   # Expected: 1 row (table still present).
   wp option get acrossai_mcp_server_tools_db_version
   # Expected: 1.0.0
   ```
4. Reinstall + activate. Verify prior data reappears.
5. Repeat but with "Delete all data on uninstall" CHECKED.
6. Uninstall. Verify dropped:
   ```sh
   wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_server_tools'"
   # Expected: 0 rows.
   wp option get acrossai_mcp_server_tools_db_version
   # Expected: option does not exist.
   ```

Maps to: **FR-028, DEC-UNINSTALL-OPT-IN-GATE**.

---

## Step 9 — Grace degradation

**Test A — server disabled**:

1. Disable the seeded server on the Overview tab.
2. Navigate to Tools tab. Verify the disabled-server warning notice appears; picker is hidden. No React mount.

**Test B — Abilities API absent**:

1. Programmatically remove `wp_get_abilities()` (e.g., deactivate the plugin providing it in a test environment).
2. Navigate to Tools tab. Verify an error notice explains the missing dependency. No JS mount attempt in the browser console.

**Test C — asset manifest missing**:

1. Rename `build/js/tools.asset.php` to `.asset.php.bak`.
2. Navigate to Tools tab. Verify the tab renders **without** the React app (silent bail) — no PHP notice, no JS error. The `#acrossai-mcp-tools-root` div contains only the "Loading tools…" placeholder.
3. Restore the manifest.

Maps to: **FR-018, FR-019, SC-007, silent-bail pattern**.

---

## Step 10 — Extensibility smoke test

Drop a small "companion" mu-plugin file at `wp-content/mu-plugins/mcp-tools-companion.php`:

```php
<?php
add_action( 'acrossai_mcp_tools_changed', function ( array $payload ) {
    error_log( sprintf(
        '[mcp-tools-companion] %s: %d/%s',
        $payload['operation'],
        $payload['server_id'],
        $payload['ability_slug']
    ) );
} );
```

Add a tool via the picker + Save. Verify `wp-content/debug.log` contains one `added` line for the new slug. Remove the same tool + Save. Verify one `removed` line.

Register a broken JS filter:

```javascript
wp.hooks.addFilter( 'acrossaiMcpManager.tools.fields', 'broken/throws', () => {
    throw new Error( 'intentional' );
} );
```

Reload the Tools tab. Verify:

- The mount still renders (safeApplyFilters caught the throw).
- Browser console contains a `console.error` with the thrown error.
- Fields display normally (fallback = original unmodified array).

Maps to: **Principle V, FR-023, safeApplyFilters boundary**.

---

## Quality Gates

Run in the plugin root:

```sh
composer run phpcs           # Expected: zero errors.
composer run phpstan         # Expected: zero errors at level 8.
vendor/bin/phpunit tests/phpunit/Database/MCPServerTool/ tests/phpunit/REST/ToolsControllerTest.php
                             # Expected: all pass.
npm run lint:js              # Expected: zero errors.
npm run lint:css             # Expected: zero errors.
npm test tests/jest/tools/   # Expected: all pass.
npm run build                # Expected: build/js/tools.js + tools.asset.php produced.
npm run validate-packages    # Expected: no violations.
```

Additional grep gates:

```sh
grep -rEn '\b(get_core_tools|render_tools_table)\b' includes/ admin/ public/
# Expected: 0 matches. (Retired helpers.)

grep -rEn "'date_updated'" includes/Database/MCPServerTool/
# Expected: 0 matches. (B21 — must use 'modified'.)

grep -rEn 'react-query|@tanstack|redux|mobx|react-table|@mui/|styled-components' src/js/tools.js
# Expected: 0 matches. (DEC-WP-DATAVIEWS-OVER-REACT forbidden libs.)

grep -rEn 'use BerlinDB\\Database\\Kern\\(Table|Schema|Query|Row)' includes/Database/MCPServerTool/
# Expected: 0 matches. (DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION — extend via leading-\ FQN.)

grep -rEn 'MCPServerAbility|AbilitiesController' includes/Database/MCPServerTool/ includes/REST/ToolsController.php admin/Partials/ServerTabs/ToolsTab.php src/js/tools.js
# Expected: 0 matches. (F017 architectural independence.)
```

Fail on any non-zero grep result. Fail on any failed gate.

---

## Success Criteria — final tally

Once all 10 quickstart steps pass and all quality gates return zero errors, the feature satisfies:

- **SC-001**: 3 tools added + saved in under 30 s (Step 3).
- **SC-002**: 100% of unauth REST rejected (Step 4).
- **SC-003**: Persistence across reload (Step 3).
- **SC-004**: Zero page reloads to see UI changes (Steps 3, 5, 6).
- **SC-005**: Empty state + warning banner both visible (Step 2).
- **SC-006**: Server A ≠ Server B (Step 7 by construction — two independent servers).
- **SC-007**: Graceful error notice, not fatal (Step 9 A/B).
- **SC-008**: Stale rows visible + operator-removable (Step 7 verifies cleanup, complementary to stale-row visibility test).
- **SC-009**: 20-slug save < 1 s (Step 3 or explicit benchmark).
- **SC-010**: Excluded slugs never in pool (Step 4 REST check).

Feature is complete when all 10 SCs verify + all quality gates green.
