# Quickstart — Feature 017

**Audience**: engineer implementing / reviewing / testing the feature. Not a companion-plugin author guide (that's `docs/extending-abilities-tab.md`, written in TASK-9).

## Prerequisites

- Local WordPress dev site with the `acrossai-mcp-manager` plugin at branch `017-per-server-ability-selection`.
- `berlindb/core: ^3.0.0` installed via Composer (already present per F010).
- The WordPress Abilities API available (either via the `wp-abilities-api` package or the `acrossai-abilities-manager` sibling plugin) — optional; F017 degrades gracefully without it.
- Node ≥ 18, npm dependencies installed.

## Build

```bash
cd /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager
composer install
npm ci
npm run build
```

Expected new build artifacts:
- `build/js/abilities.js`
- `build/js/abilities.asset.php`
- `build/js/abilities.css` (only if `src/scss/abilities.scss` is imported)

## Activate + install the new table

```bash
wp plugin deactivate acrossai-mcp-manager
wp plugin activate acrossai-mcp-manager
wp db query "SHOW CREATE TABLE wp_acrossai_mcp_server_abilities \G"
```

Expected: table exists with `PRIMARY KEY (id)`, `UNIQUE KEY server_ability (server_id, ability_slug)`, `KEY server_id (server_id)`.

```bash
wp option get acrossai_mcp_server_abilities_db_version
```

Expected: `1.0.0`.

## Phantom-version guard smoke test

```bash
wp db query "DROP TABLE wp_acrossai_mcp_server_abilities"
wp option get acrossai_mcp_server_abilities_db_version   # still 1.0.0 — option outlives table
wp plugin deactivate acrossai-mcp-manager
wp plugin activate acrossai-mcp-manager
wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_server_abilities'"   # 1 row again
tail -50 wp-content/debug.log | grep -i acrossai   # should be EMPTY (silent guard)
```

## Manual UI walkthrough (User Story 1)

1. Visit `http://wordpress-7-0.local/wp-admin/admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=abilities`.
2. Expect the mockup 1A UI: search box, category + type filters, sortable columns, per-row toggle, live "N of M exposed" counter, and Expose/Hide bulk actions.
3. Toggle one row; reload the page. Expected: state persists.
4. Select 3 rows, use "Hide selected". Expected: single POST request; refreshed list shown; counter drops by 3.
5. Type into search — rows filter live.
6. Click each sortable header — asc/desc toggles.

## Manual UI walkthrough (User Story 5 — enqueue scope)

Visit the **Overview** and **Access Control** tabs. Check DevTools → Network:
- ✅ `build/js/abilities.js` request appears on the **Abilities** tab only.
- ✅ `window.acrossaiMcpAbilities` is `undefined` on other tabs.

## REST API smoke test (User Story 3)

```bash
# Fetch a nonce (admin cookie in the request):
NONCE=$(wp eval 'echo wp_create_nonce("wp_rest");')

# GET
curl -H "X-WP-Nonce: $NONCE" \
     --cookie-jar /tmp/wp.cookies --cookie /tmp/wp.cookies \
     http://wordpress-7-0.local/wp-json/acrossai-mcp-manager/v1/servers/1/abilities

# Expected: { has_abilities_api: true, abilities: [ ... ] }

# POST — flip two rows
curl -X POST -H "X-WP-Nonce: $NONCE" -H "Content-Type: application/json" \
     --cookie-jar /tmp/wp.cookies --cookie /tmp/wp.cookies \
     -d '{"abilities":[{"slug":"core/get-user-info","is_exposed":true},{"slug":"ai/get-post-details","is_exposed":false}]}' \
     http://wordpress-7-0.local/wp-json/acrossai-mcp-manager/v1/servers/1/abilities

# Expected: refreshed merged list.

# 400 — unknown slug
curl -X POST -H "X-WP-Nonce: $NONCE" -H "Content-Type: application/json" \
     --cookie-jar /tmp/wp.cookies --cookie /tmp/wp.cookies \
     -d '{"abilities":[{"slug":"does/not-exist","is_exposed":true}]}' \
     http://wordpress-7-0.local/wp-json/acrossai-mcp-manager/v1/servers/1/abilities

# Expected: 400 with acrossai_mcp_invalid_payload; wp db query "SELECT COUNT(*) ..." unchanged.

# 404 — non-existent server
curl -H "X-WP-Nonce: $NONCE" \
     --cookie-jar /tmp/wp.cookies --cookie /tmp/wp.cookies \
     http://wordpress-7-0.local/wp-json/acrossai-mcp-manager/v1/servers/9999/abilities

# Expected: 404 with acrossai_mcp_server_not_found.

# 403 — unauthenticated
curl http://wordpress-7-0.local/wp-json/acrossai-mcp-manager/v1/servers/1/abilities

# Expected: 403 rest_forbidden.
```

## Extensibility smoke test (User Story 6)

Write a tiny helper plugin (`/wp-content/plugins/hello-abilities-ext/hello-abilities-ext.php`):

```php
<?php
/** Plugin Name: Hello Abilities Extension */

add_action( 'admin_enqueue_scripts', function () {
    if ( ! ( isset( $_GET['tab'] ) && 'abilities' === $_GET['tab'] ) ) {
        return;
    }
    wp_enqueue_script(
        'hello-abilities-ext',
        plugins_url( 'ext.js', __FILE__ ),
        array( 'acrossai-mcp-manager-abilities' ),
        '0.1.0',
        true
    );
} );

add_filter( 'acrossai_mcp_ability_row', function ( $row, $server_id, $ability ) {
    $row['hello_extra'] = 'from-php';
    return $row;
}, 10, 3 );
```

`ext.js`:

```js
wp.hooks.addFilter(
    'acrossaiMcpManager.abilities.fields',
    'hello/extra-column',
    ( fields ) => [
        ...fields,
        {
            id: 'hello_action',
            label: 'Action',
            enableSorting: false,
            render: ( { item } ) => wp.element.createElement(
                'button',
                { onClick: () => alert( 'Edit ' + item.slug + ' — ' + item.hello_extra ) },
                'Edit'
            ),
        },
    ]
);
```

Activate the helper plugin. Expected:
- ✅ The Abilities tab now shows an extra "Action" column with an "Edit" button per row.
- ✅ Clicking Edit shows an alert including `from-php` — confirms the PHP row filter added data that the JS filter's `render` can read.
- ✅ Removing the helper plugin restores the original column set.

Now test failure smoke (User Story 6 §Acceptance Scenario 5):

Replace `ext.js` with:

```js
wp.hooks.addFilter(
    'acrossaiMcpManager.abilities.fields',
    'hello/broken',
    () => { throw new Error( 'boom' ); }
);
```

Expected:
- ✅ Abilities tab still renders the built-in columns.
- ✅ Browser console shows exactly one `[acrossai-mcp-manager] filter "acrossaiMcpManager.abilities.fields" threw:` line.
- ✅ No white-screen; no red banner; no debug.log entries in `wp-content/debug.log`.

## Test suites

```bash
# PHP
composer run phpcs
composer run phpstan
vendor/bin/phpunit tests/phpunit/Database/MCPServerAbility/
vendor/bin/phpunit tests/phpunit/REST/AbilitiesControllerTest.php

# JS
npm run lint:js
npm test -- --testPathPattern abilities
npm run validate-packages
```

Expected: zero errors on all commands.

## Final audit greps (SC-008 + TASK-9 gates)

```bash
grep -rEn 'react-query|@tanstack|redux|mobx|react-table|@mui/|styled-components' src/js/
# Expected: zero matches (WP-packages-only invariant).

grep -rEn 'acrossaiMcpManager\.abilities\.(fields|actions|row)' src/js/
# Expected: three matches — one per filter name.

grep -rEn 'acrossai_mcp_ability_row' includes/REST/
# Expected: one apply_filters() call.

grep -rEn 'partition_abilities|render_public_table|render_private_table' \
     admin/Partials/ServerTabs/AbilitiesTab.php
# Expected: zero matches — legacy PHP partition helpers fully retired.
```

## Rollback

- If the tab breaks: `wp plugin deactivate acrossai-mcp-manager` restores the previous behavior; no data is lost.
- To reset all per-server overrides: `wp db query "TRUNCATE TABLE wp_acrossai_mcp_server_abilities"` — every server falls back to inheriting from `meta[mcp][public]`.
- To fully uninstall the table (only after `acrossai_mcp_uninstall_delete_data = 1`): `wp plugin uninstall acrossai-mcp-manager` — the F012 opt-in gate protects data by default.
