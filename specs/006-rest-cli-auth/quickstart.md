# Quickstart — CLI Authentication Flow Walk

**Date**: 2026-06-25 | **Audience**: developers reviewing PR #X; QA running manual SC verification

This document walks the **full end-to-end CLI authentication flow** on a clean WP 6.9 / PHP 8.0 install. It verifies SC-001 through SC-008.

## Prerequisites

1. WordPress 6.9+ installed at `https://example.com` (replace with your local URL).
2. The plugin `acrossai-mcp-manager` is installed and activated.
3. At least ONE enabled MCP server row exists (Phase 2 admin UI: **MCP Manager → Servers**, click **Add Server**, fill in `Server Name`, `Server Slug`, set `Enabled` checked, save).
4. The feature flag `acrossai_mcp_npm_login_enabled` is set to `1`. From WP-CLI:
   ```bash
   wp option update acrossai_mcp_npm_login_enabled 1
   ```
5. An admin user account exists (the one you'll use to approve the CLI request).
6. `WP_DEBUG=true` and `WP_DEBUG_LOG=true` in `wp-config.php` (so any PHP notices are captured to `wp-content/debug.log` during the walk).
7. WP-Apps is enabled (default in WP 6.9). Verify:
   ```bash
   wp eval 'echo (int) wp_is_application_passwords_available();'
   # expected: 1
   ```

## Step 1 — `/health` discovery (SC-007 site_slug)

```bash
curl -s https://example.com/wp-json/acrossai-mcp-manager/v1/health | jq
```

**Expected**:
```json
{
  "plugin_installed": true,
  "plugin_active": true,
  "version": "0.0.1",
  "site_slug": "example-com"
}
```

✅ **SC-007 verified**: `site_slug` is the `sanitize_title()` of the site name.

## Step 2 — `/auth/start` (SC-002 + opaque code generation)

```bash
SERVER_ID="wordpress-default-server"   # adjust to your server slug
RESPONSE=$(curl -s -X POST \
    -H 'Content-Type: application/json' \
    -d "{\"server_id\":\"$SERVER_ID\"}" \
    https://example.com/wp-json/acrossai-mcp-manager/v1/auth/start)
echo "$RESPONSE" | jq

AUTH_CODE=$(echo "$RESPONSE" | jq -r '.auth_code')
AUTH_URL=$(echo "$RESPONSE" | jq -r '.auth_url')
echo "auth_code: $AUTH_CODE"
echo "auth_url:  $AUTH_URL"
```

**Expected**: `auth_code` is exactly 32 hex chars (matches `^[a-f0-9]{32}$`); `auth_url` points at `https://example.com/acrossai-mcp-manager/?action=cli_auth&code=<AUTH_CODE>&server=<SERVER_ID>`; `expires_in: 300`.

✅ **128-bit entropy verified**: `[[ ${#AUTH_CODE} -eq 32 ]]`.

## Step 3 — Browser approval (SC-007 oracle-defense + SC-001 wall-clock)

**Before clicking Approve, verify the oracle-defense first.** Open a SECOND terminal:

```bash
# Poll with the WRONG server slug:
curl -s "https://example.com/wp-json/acrossai-mcp-manager/v1/auth/status?code=$AUTH_CODE&server=wrong-server" | jq
# expected: { "approved": false }    NOT a 404 (oracle defense per FR-003)
```

✅ **SC-007 verified**: wrong-server poll returns `{"approved": false}`, indistinguishable from a real pending state.

Now open `$AUTH_URL` in a browser where you are logged in as an admin. Click **Approve**. You should be redirected to `https://example.com/acrossai-mcp-manager/?action=cli_auth_approved` which displays "CLI Authorization Approved — return to your CLI".

## Step 4 — `/auth/status` (poll until approved)

```bash
while true; do
    STATUS=$(curl -s "https://example.com/wp-json/acrossai-mcp-manager/v1/auth/status?code=$AUTH_CODE&server=$SERVER_ID")
    APPROVED=$(echo "$STATUS" | jq -r '.approved')
    if [[ "$APPROVED" == "true" ]]; then
        SESSION_TOKEN=$(echo "$STATUS" | jq -r '.token')
        echo "Approved! session_token: $SESSION_TOKEN"
        break
    fi
    sleep 1
done
```

**Expected**: Within ~1 second of clicking Approve, the loop exits with a 32-hex-char `session_token`. **SC-003 has a 10-minute TTL** — if you wait > 10 min before polling, the next call returns `{"approved": false}` then 404 once the auth-code transient also expires.

## Step 5 — `/servers` (Bearer-auth + AccessControl filtering)

```bash
curl -s -H "Authorization: Bearer $SESSION_TOKEN" \
    https://example.com/wp-json/acrossai-mcp-manager/v1/servers | jq
```

**Expected**:
```json
{
  "servers": [
    {
      "id": 5,
      "name": "WordPress Default Server",
      "description": "...",
      "enabled": true,
      "version": "v1.0.0",
      "namespace": "mcp",
      "route": "wordpress-default-server",
      "mcp_url": "https://example.com/wp-json/mcp/wordpress-default-server"
    }
  ]
}
```

✅ **SC-006 verified** (manual): if the access-control vendor plugin is installed and the granting user is denied access to server X, X is excluded. If the plugin is absent, ALL enabled servers are returned.

## Step 6 — `/auth/exchange` (issue Application Password — SC-004)

```bash
RESPONSE=$(curl -s -X POST \
    -H 'Content-Type: application/json' \
    -d "{\"code\":\"$AUTH_CODE\",\"server_id\":\"$SERVER_ID\"}" \
    https://example.com/wp-json/acrossai-mcp-manager/v1/auth/exchange)
echo "$RESPONSE" | jq

APP_PASSWORD=$(echo "$RESPONSE" | jq -r '.app_password')
USERNAME=$(echo "$RESPONSE" | jq -r '.username')
USER_ID=$(echo "$RESPONSE" | jq -r '.user_id')
EXPIRES_IN=$(echo "$RESPONSE" | jq -r '.expires_in')
echo "app_password: $APP_PASSWORD"
echo "username:     $USERNAME"
echo "user_id:      $USER_ID"
echo "expires_in:   $EXPIRES_IN"
```

**Expected**:
- `app_password` is a 24-char string with spaces (WP-core format, e.g. `abcd 1234 efgh 5678 ijkl 9012`).
- `username` is your admin login.
- `expires_in` is exactly `2592000` (30 days).
- `server_id` is echoed back.

✅ **SC-004 verified**: `expires_in: 2592000`.

## Step 7 — Single-use enforcement (SC-005)

Try to exchange the SAME code again:

```bash
curl -s -X POST \
    -H 'Content-Type: application/json' \
    -d "{\"code\":\"$AUTH_CODE\",\"server_id\":\"$SERVER_ID\"}" \
    https://example.com/wp-json/acrossai-mcp-manager/v1/auth/exchange | jq
# expected: { "error": "invalid_code" }    HTTP 400
```

✅ **SC-005 verified**: second exchange returns `invalid_code`.

## Step 8 — Use the Application Password against MCP endpoints

```bash
# Substitute the server's actual route + an MCP REST path (e.g. /tools/list)
curl -s \
    -u "$USERNAME:$APP_PASSWORD" \
    "https://example.com/wp-json/mcp/$SERVER_ID/tools/list"
```

**Expected**: a 200 response from the MCP server (proves the Application Password works against `/wp-json/mcp/*` exactly as a real MCP client would).

## SC-008 — `not_supported` failure path (manual)

To verify the `not_supported` 501 response, temporarily disable Application Passwords:

```bash
wp option update wp_application_passwords_filter_global_off 1  # not a real option — illustrative
# OR add to wp-config.php:
#   add_filter( 'wp_is_application_passwords_available', '__return_false' );
```

Then repeat Step 2 + Step 3 + Step 4 to obtain a fresh approved code, and try Step 6 with that code:

```bash
curl -s -X POST \
    -H 'Content-Type: application/json' \
    -d "{\"code\":\"$AUTH_CODE\",\"server_id\":\"$SERVER_ID\"}" \
    https://example.com/wp-json/acrossai-mcp-manager/v1/auth/exchange | jq
# expected: { "error": "not_supported" }    HTTP 501
```

✅ **SC-008 verified**: 501 + no audit row written (check the audit table — no `record_success` entry for this code).

## SC-002 — Auth-code TTL boundary (manual, slow)

To verify the 300-second expiry:

```bash
RESPONSE=$(curl -s -X POST \
    -H 'Content-Type: application/json' \
    -d "{\"server_id\":\"$SERVER_ID\"}" \
    https://example.com/wp-json/acrossai-mcp-manager/v1/auth/start)
EXPIRED_CODE=$(echo "$RESPONSE" | jq -r '.auth_code')
echo "Waiting 305 seconds for transient to expire..."
sleep 305
curl -s -X POST \
    -H 'Content-Type: application/json' \
    -d "{\"code\":\"$EXPIRED_CODE\",\"server_id\":\"$SERVER_ID\"}" \
    https://example.com/wp-json/acrossai-mcp-manager/v1/auth/exchange | jq
# expected: { "error": "invalid_code" }    HTTP 400
```

✅ **SC-002 verified**.

## Cleanup verification

After the full flow:
1. The `acrossai_cli_auth_<code>` transient is GONE (`wp transient get acrossai_cli_auth_$AUTH_CODE` returns empty).
2. The `acrossai_session_<token>` transient is GONE (`wp transient get acrossai_session_$SESSION_TOKEN` returns empty).
3. The Application Password exists at `wp eval 'print_r(WP_Application_Passwords::get_user_application_passwords($USER_ID));'` — look for one named `"AcrossAI MCP Manager CLI - <SERVER_ID>"`.
4. The audit table has TWO rows for this flow:
   ```bash
   wp db query "SELECT id, status, auth_code_hash, user_id, server_id, approved_at, completed_at, app_password_uuid FROM wp_acrossai_mcp_cli_auth_logs WHERE auth_code_hash = SHA2('$AUTH_CODE', 256)"
   # expected: one row with status='approved' (approved_at set, completed_at NULL)
   #           one row with status='success'  (approved_at NULL, completed_at set, app_password_uuid set)
   ```

## DoD checkpoint

After the full walk:
- [ ] All 8 steps succeeded
- [ ] SC-001 through SC-008 verified (manual or automated)
- [ ] Zero PHP notices/warnings in `wp-content/debug.log` for the duration of the walk
- [ ] PHPUnit `cli-rest` testsuite passes: `WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit --testsuite=cli-rest --bootstrap tests/bootstrap-wp.php`
- [ ] PHPCS clean: `vendor/bin/phpcs --standard=phpcs.xml.dist includes/REST/ public/Partials/`
- [ ] PHPStan L8 clean: `vendor/bin/phpstan analyse includes/REST public/Partials --level=8`
- [ ] Loader grep gate: `grep -rnE 'add_action|add_filter' includes/REST/ public/Partials/` returns empty (zero hooks in feature-class constructors)
