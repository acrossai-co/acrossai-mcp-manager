# Quickstart — F032 OAuth Per-Server Scoping

For operators: what happens on upgrade + how to verify a successful migration.
For developers: end-to-end verification checklist mirroring `docs/planings-tasks/032-oauth-per-server-scoping.md` Manual Verification Checklist but scoped to the operator/tester perspective.

## Operator Pre-Upgrade Actions

Before deploying F032 to any live install:

1. **Read the release note warning**: F032's Unreleased changelog entry (per FR-025) warns that any pre-F032 DCR-registered OAuth client (Claude.ai / ChatGPT / Cursor / Cline / etc.) currently active on this site will DISCONNECT on next request after upgrade. Users must re-run the OAuth authorize flow to reconnect.

2. **Notify affected users** (optional but recommended): if any of your users have live AI-host connections bound to pre-F032 DCR clients, tell them they will need to re-authorize their AI host once after upgrade. The re-authorize flow is the standard one they used originally; no new UX.

3. **Snapshot the OAuth tables** (recommended for rollback):

   ```bash
   wp db export --tables=wp_acrossai_mcp_oauth_clients,wp_acrossai_mcp_oauth_tokens,wp_acrossai_mcp_oauth_auth_codes oauth-pre-f032.sql
   ```

   Rollback via `composer install <previous-version>` + `wp db import oauth-pre-f032.sql` if needed. F032 ships unconditionally with no feature flag (per Q2), so rollback is package-level only.

## Upgrade Trigger

F032's schema migration fires automatically on the next admin page load after the plugin is upgraded. No operator action required. The three OAuth table upgrade callbacks execute in this order (per `Main::reconcile_database_schemas()` on `admin_init` priority 3):

1. `OAuthTokens\Table::upgrade_to_<v>()` (5 steps: ADD column → ADD KEY → backfill via JOIN → PURGE remaining NULL rows → MODIFY NOT NULL).
2. `OAuthAuthCodes\Table::upgrade_to_<v>()` (same 5-step shape).
3. `OAuthClients\Table::upgrade_to_<v>()` (6 steps: ADD column → backfill from prefix → PURGE remaining NULL rows → swap standalone UNIQUE → MODIFY NOT NULL → fire aggregate `acrossai_mcp_oauth_legacy_dcr_purged` observability action).

Registration order is deliberate — tokens + auth codes MUST run first so their JOIN backfill can still resolve `client_id → server_id` before the OAuthClients callback's PURGE step deletes the source client rows.

## Post-Upgrade Verification (Operator)

Run these WP-CLI commands after loading any wp-admin page post-upgrade. All should return zero rows or the specific expected value.

```bash
# 1. Version stamps advanced
wp option get wpdb_acrossai_mcp_oauth_clients_version
wp option get wpdb_acrossai_mcp_oauth_tokens_version
wp option get wpdb_acrossai_mcp_oauth_auth_codes_version
# Expect: version strings ending in .1 higher than the previous version.

# 2. server_id column exists and is NOT NULL on all three tables
wp db query "SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME IN ('wp_acrossai_mcp_oauth_clients', 'wp_acrossai_mcp_oauth_tokens', 'wp_acrossai_mcp_oauth_auth_codes') AND COLUMN_NAME = 'server_id'"
# Expect: 3 rows, all IS_NULLABLE = 'NO', DATA_TYPE = 'bigint'.

# 3. Composite UNIQUE present, standalone UNIQUE absent on oauth_clients
wp db query "SHOW INDEX FROM wp_acrossai_mcp_oauth_clients WHERE Key_name IN ('client_id', 'client_id_server_id')"
# Expect: Key_name = 'client_id_server_id' with 2 rows (one per column). Zero rows for 'client_id'.

# 4. Post-migration invariant — no NULL server_id anywhere
wp db query "SELECT COUNT(*) AS null_count FROM wp_acrossai_mcp_oauth_clients WHERE server_id IS NULL"
wp db query "SELECT COUNT(*) AS null_count FROM wp_acrossai_mcp_oauth_tokens WHERE server_id IS NULL"
wp db query "SELECT COUNT(*) AS null_count FROM wp_acrossai_mcp_oauth_auth_codes WHERE server_id IS NULL"
# Expect: all three return null_count = 0.

# 4b. Post-migration invariant — no orphan server_ids (per SEC-032-003 remediation)
wp db query "SELECT COUNT(*) AS orphan_count FROM wp_acrossai_mcp_oauth_clients WHERE server_id NOT IN (SELECT id FROM wp_acrossai_mcp_servers)"
# Expect: orphan_count = 0. Non-zero would indicate the backfill's IN-clause guard was missing OR a
# server row was deleted post-migration without cascading to oauth_clients (a separate bug).

# 5. Admin clients backfilled from prefix
wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_clients WHERE client_id LIKE 'server-%'"
# Expect: matches your pre-upgrade admin-client count (rows preserved with server_id backfilled).

# 6. No debug.log warnings during upgrade window
tail -n 100 wp-content/debug.log | grep -i "acrossai_mcp"
# Expect: zero WARNING or ERROR lines related to the migration.
```

## Observability — Attaching a Logger (Optional)

If you want to log cross-server bypass attempts + legacy-purge events, attach listeners in your `wp-config.php` custom plugin or mu-plugin:

```php
// 4-arg signature per SEC-032-001 remediation — the plugin does NOT emit the actual owning server_id.
// If you need it for forensics, query the DB from within your listener (see comment below).
add_action( 'acrossai_mcp_oauth_cross_server_attempted', function( $client_id, $server_id_requested, $user_id, $timestamp ) {
    // Optional: resolve the actual owning server_id from within your OWN listener code.
    // The plugin deliberately does NOT expose this via the action args because any WordPress plugin
    // can hook this action, and leaking cross-server binding info would recreate an oracle.
    // Your listener has full DB access — resolve it yourself if you trust every plugin on this site.
    // global $wpdb;
    // $actual = $wpdb->get_var( $wpdb->prepare( "SELECT server_id FROM {$wpdb->prefix}acrossai_mcp_oauth_clients WHERE client_id = %s", $client_id ) );

    error_log( sprintf(
        '[acrossai-mcp] cross-server bypass attempt: user=%d client=%s requested_server=%d at %d',
        $user_id, $client_id, $server_id_requested, $timestamp
    ) );
}, 10, 4 );

add_action( 'acrossai_mcp_oauth_legacy_dcr_purged', function( $clients_deleted, $tokens_deleted, $auth_codes_deleted ) {
    error_log( sprintf(
        '[acrossai-mcp] F032 legacy DCR purge: %d clients, %d tokens, %d auth codes deleted',
        $clients_deleted, $tokens_deleted, $auth_codes_deleted
    ) );
}, 10, 3 );

// New in SEC-032-002 remediation: DCR endpoint fires this action on origin-mismatch (attacker-controlled URL).
// Useful for detecting probing / SSRF-adjacent attacks.
add_action( 'acrossai_mcp_oauth_dcr_resource_url_origin_mismatch', function( $resource_url, $user_id, $timestamp ) {
    error_log( sprintf(
        '[acrossai-mcp] DCR resource URL origin mismatch: user=%d url=%s at %d',
        $user_id, $resource_url, $timestamp
    ) );
}, 10, 3 );
```

All three actions are fire-and-forget; the plugin does NOT require these listeners. Attach only if you want the forensic detail.

## End-to-End Developer Verification (10 steps)

1. **Fresh install**: fully clean database + activate the plugin. Verify all 3 OAuth tables created with `server_id NOT NULL` from the start; verify no `acrossai_mcp_oauth_legacy_dcr_purged` fires (nothing to purge on fresh install).

2. **Seed 2 servers + 2 admin clients**: create servers with IDs 1 and 2 via the MCP Manager UI. Trigger admin-client generation on each. Verify both `oauth_clients` rows have correct `server_id`.

3. **Test authorize + token exchange (server 1)**: complete OAuth flow against server 1. Verify `oauth_auth_codes.server_id = 1` on the emitted auth code; verify `oauth_tokens.server_id = 1` on the exchanged token.

4. **Test refresh (server 1)**: refresh the token. Verify new token also has `server_id = 1`.

5. **Cross-server 403 test**: from server 1's Connectors tab, submit a `revoke-client-tokens` request with server 1's UI-context but server 2's `client_id`. Expect 403 `acrossai_mcp_oauth_cross_server`. Verify observability listener (if attached) fires with correct args. Verify server 2's tokens untouched in DB.

6. **DCR per-server test**: register the same connector (e.g. "Claude Desktop") twice — once with `resource` pointing at server 1, once at server 2. Verify two distinct `oauth_clients` rows, both `client_name = "Claude Desktop"`, distinct `client_id` + `server_id`.

7. **DCR invalid_target test**: register with malformed `resource` URL. Expect 400 `invalid_target`; verify no client row created.

8. **User deletion regression test**: create user with tokens on both servers. Call `wp user delete <id>`. Verify BOTH servers' tokens for that user have `revoked = 1` (site-wide cascade preserved per FR-042).

9. **Legacy purge simulation**: seed a legacy DCR row directly (`INSERT INTO oauth_clients (client_id, server_id, ...) VALUES ('legacy-claude-abc', NULL, ...)`) + associated tokens. Rewind `db_version_key` and reload admin page. Verify legacy rows deleted + `acrossai_mcp_oauth_legacy_dcr_purged` fires with correct counts.

10. **NOT NULL enforcement**: attempt `INSERT INTO oauth_clients (client_id, ...) VALUES ('test', ...)` WITHOUT `server_id`. Expect MySQL constraint violation (SC-009).

## Rollback

F032 ships unconditionally (no feature flag per Q2). If a live install experiences unexpected impact:

```bash
# 1. Downgrade plugin via composer (or WP admin plugin roll-back)
composer require acrossai-co/acrossai-mcp-manager:<previous-version>

# 2. Restore OAuth table snapshot (if taken pre-upgrade)
wp db import oauth-pre-f032.sql

# 3. Reset db_version stamps to force full re-migration on next upgrade
wp option delete wpdb_acrossai_mcp_oauth_clients_version
wp option delete wpdb_acrossai_mcp_oauth_tokens_version
wp option delete wpdb_acrossai_mcp_oauth_auth_codes_version
```

Note: rollback preserves the `server_id` column (previous versions ignore unknown columns). Only the composite UNIQUE constraint change (F032 replaced standalone `UNIQUE(client_id)` with `UNIQUE(client_id, server_id)`) is a real schema regression on rollback — a manual `ALTER TABLE ... ADD UNIQUE KEY client_id (client_id)` is needed if the pre-F032 plugin re-uses the old constraint name. Reach out on the plugin issue tracker if you hit this.
