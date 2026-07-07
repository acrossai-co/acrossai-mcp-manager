# Quickstart — Feature 016

End-to-end verification recipe for a maintainer picking up this branch. Each section maps to one User Story from `spec.md` and can be run independently.

## Prerequisites

- Local WordPress site with the plugin's previous version active. The Local by Flywheel install at `/Users/raftaar1191/local-sites/wordpress-7-0/` is the reference environment.
- WP-CLI available in the site's environment (`wp` command).
- A test browser session logged in as the site administrator.

## US1 — Admin UI is leaner

1. Update the plugin to the Feature 016 build (deactivate → replace files → reactivate).
2. Navigate to `wp-admin/admin.php?page=acrossai_mcp_manager` and open any server-edit page.
3. **Expected**: Exactly 10 tabs render (Overview, npm, Clients, WP-CLI, Tools, Abilities, Access Control, MCP Tracker, Update Server, Danger Zone). No "Claude Connector" tab.
4. Navigate to Settings → MCP.
5. **Expected**: No "Claude Connectors" section, no toggle, no description paragraph.
6. Create a test page containing `[acrossai_mcp_claude_connector_block server=1]`. Save and preview.
7. **Expected**: The page renders the literal shortcode text `[acrossai_mcp_claude_connector_block server=1]` (WordPress default for unregistered shortcodes).

## US2 — Operator retires prior schema manually (fresh-install-only stance)

On a copy of the site that had the previous version's data (three connector columns populated, two OAuth tables present with rows):

1. Note baseline:
   ```
   wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_servers"       # note the count as N
   wp db query "DESCRIBE wp_acrossai_mcp_servers"                    # expect 13 rows
   wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_oauth_%'"          # expect 2 rows
   ```
2. **Operator runs the manual retirement SQL** (the plugin does NOT do this for you):
   ```sql
   DROP TABLE IF EXISTS wp_acrossai_mcp_oauth_tokens;
   DROP TABLE IF EXISTS wp_acrossai_mcp_oauth_audit;
   ALTER TABLE wp_acrossai_mcp_servers
       DROP COLUMN claude_connector_client_id,
       DROP COLUMN claude_connector_client_secret,
       DROP COLUMN claude_connector_redirect_uri;
   DELETE FROM wp_options WHERE option_name IN (
       'acrossai_mcp_oauth_tokens_db_version',
       'acrossai_mcp_oauth_audit_db_version',
       'acrossai_mcp_claude_connectors_enabled'
   );
   ```
   And the companion WP-CLI cron cleanup:
   ```
   wp cron event unschedule acrossai_mcp_oauth_cleanup
   ```
3. Deactivate the plugin (via WP admin), update to the Feature 016 build, reactivate.
4. Verify:
   ```
   wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_servers"       # STILL N (data preserved by the ALTER)
   wp db query "DESCRIBE wp_acrossai_mcp_servers"                    # 10 rows, no claude_connector_* columns
   wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_oauth_%'"          # empty result
   wp option get acrossai_mcp_oauth_tokens_db_version                # not set
   wp option get acrossai_mcp_oauth_audit_db_version                 # not set
   wp option get acrossai_mcp_claude_connectors_enabled              # not set
   wp option get acrossai_mcp_manager_db_version                     # STILL 0.0.1 (no bump)
   wp cron event list | grep acrossai_mcp_oauth_cleanup              # no match (Deactivator cleared it)
   wp db query "SHOW WARNINGS"                                       # empty
   tail -50 wp-content/debug.log | grep -i acrossai                  # empty (silent-guard invariant per F011 clarification Q1)
   ```
5. Curl the retired well-known URLs:
   ```
   curl -sI https://LOCAL/.well-known/oauth-authorization-server/mcp/1  # 404
   curl -sI https://LOCAL/.well-known/oauth-protected-resource/mcp/1    # 404
   curl -sI -X POST https://LOCAL/wp-json/acrossai-mcp/v1/token         # 404
   ```

**If the operator forgets the manual SQL**: the plugin still activates cleanly. Residual OAuth tables and connector columns become dead data (no code path reads them post-016). Options remain in `wp_options` unused. This is acceptable degraded state, not a defect.

## US3 — Fresh install ships lean

On a fresh WordPress install that has never had the plugin:

1. Activate the plugin via WP admin.
2. Verify:
   ```
   wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_%'"                # 2 rows: servers + cli_auth_logs
   wp db query "DESCRIBE wp_acrossai_mcp_servers"                    # 10 rows, no claude_connector_*
   wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_servers"        # 1 (default seed)
   wp db query "SELECT server_slug FROM wp_acrossai_mcp_servers"     # mcp-adapter-default-server
   wp cron event list | grep acrossai_mcp_oauth_cleanup              # no match
   wp option get acrossai_mcp_manager_db_version                     # 0.0.1 (no bump on fresh install either)
   ```
3. Confirm no `acrossai-mcp-frontend-oauth` stylesheet is enqueued on any page (View Source on any URL).

## US4 — CLI auth flow still works

On any install after applying Feature 016:

1. Initiate the CLI auth handshake (invoke the flow WP-CLI uses to generate an MCP client config). The exact command depends on the client-side tooling — refer to `docs/planings-tasks/phase-cli-auth.md` for the canonical invocation.
2. **Expected**: The browser approval page loads and shows `acrossai-mcp-frontend` stylesheet in the enqueue list (verify via View Source).
3. Approve the request in the browser.
4. Verify:
   ```
   wp db query "SELECT status, server_id, user_id FROM wp_acrossai_mcp_cli_auth_logs ORDER BY id DESC LIMIT 1"
   # Expect: status='approved', server_id=X, user_id=<the admin's user_id>
   wp user meta list <the admin's user_id> | grep _application_passwords
   # Expect: a new entry appended (App Password issued)
   ```
5. Test that bearer tokens are no longer honored:
   ```
   curl -H "Authorization: Bearer 0123456789abcdef" https://LOCAL/wp-json/wp/v2/users/me
   # Expected: 401 rest_not_logged_in (bearer resolver is gone; token doesn't elevate)
   ```

## Full grep audit (FR-015 gate)

From the plugin root:
```
grep -rEn '(claude[_-]connector|ClaudeConnector|acrossai_mcp_claude_connectors_enabled|acrossai_mcp_oauth_cleanup|frontend-oauth|OAuthToken|OAuthAudit|OAuth\\\\(Storage|AuditLog|TokenController|BearerAuth|PKCE|CliCommand))' \
    --include='*.php' --include='*.js' --include='*.scss' --include='*.css' --include='*.json' \
    includes/ admin/ public/ src/ tests/ webpack.config.js uninstall.php acrossai-mcp-manager.php
```
**Expected**: zero matches. Any hit is a defect.

**B15 sanity guard**: BEFORE running the grep, seed a scratch file at `/tmp/scratch-b15.php` containing:
```php
<?php
use AcrossAI_MCP_Manager\Includes\OAuth\Storage;
$x = new \AcrossAI_MCP_Manager\Includes\OAuth\Storage();
```
Copy it into `includes/` temporarily, run the grep, confirm 2 hits, delete the file. Then run the real grep — the 2 hits from the scratch file should not appear.

## Quality gates (DoD)

```
composer run phpcs                            # zero errors
composer run phpstan                          # zero errors at level 8
composer test                                 # PHPUnit remaining tests pass (OAuth/ dir gone)
npm run build                                 # succeeds; no build/css/frontend-oauth* artifacts
npm run validate-packages                     # zero warnings
```

## Uninstall test (safety net)

On an install with pre-Feature-016 data that skipped the F016 activation upgrade (simulate by deleting plugin without reactivating):

1. Manually set the opt-in gate:
   ```
   wp option update acrossai_mcp_uninstall_delete_data 1
   ```
2. Uninstall the plugin via WP admin plugins → Delete.
3. Verify:
   ```
   wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_%'"     # empty
   wp option list --search='acrossai_mcp*'                # empty
   ```

## Rollback (if anything breaks mid-implementation)

- Git: `git checkout main -- .` on the branch (destructive — confirm no uncommitted local work first).
- Database: for connector data specifically, no rollback is possible (data was retired). For non-connector regressions, restore from the pre-implementation DB snapshot taken during US2 step 1.
- The plugin's canonical Local install is disposable; when in doubt, reset the Local site to a snapshot.
