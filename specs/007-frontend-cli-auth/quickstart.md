# Quickstart — Frontend CLI Authentication Page

**Audience**: site administrator (operator), CLI tool developer, plugin reviewer
**Goal**: complete the full CLI consent flow end-to-end on a fresh WordPress install and verify the user receives a working Application Password.

This walkthrough takes ~5 minutes on a clean WP 6.9 / PHP 8.0 install.

---

## Prerequisites

- WordPress 6.9+ with the plugin newly activated (this phase's PR merged into `feature/issue-3`)
- An admin user (or any role) able to log in to `wp-admin`
- `curl` available in a terminal
- `wp-cli` (`wp`) available in the same terminal
- The plugin's REST endpoint base: `https://example.com/wp-json/acrossai-mcp-manager/v1`

---

## 1. Enable the kill switch (operator)

The page is **disabled by default**. Enable it explicitly:

```sh
wp option update acrossai_mcp_npm_login_enabled 1
```

Verify:

```sh
wp option get acrossai_mcp_npm_login_enabled
# 1
```

---

## 2. Verify the pretty URL resolves (operator)

```sh
curl -I https://example.com/acrossai-mcp-manager/
```

**Expected**:
- HTTP 302
- `Location` header pointing at `wp-login.php?redirect_to=https%3A%2F%2Fexample.com%2Facrossai-mcp-manager%2F`
- NOT HTTP 404 (would mean the rewrite rule was not flushed — re-run `wp rewrite flush` if so)

---

## 3. CLI initiates the flow (developer)

```sh
curl -X POST \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data 'server_id=wordpress-default-server' \
  https://example.com/wp-json/acrossai-mcp-manager/v1/auth/start
```

**Expected response**:

```json
{
  "auth_code": "<32 hex chars>",
  "auth_url": "https://example.com/acrossai-mcp-manager/?action=cli_auth&code=<32 hex>&server=wordpress-default-server",
  "expires_in": 300
}
```

Copy the `auth_url`. Note the `auth_code` for verification later.

---

## 4. CLI starts polling (developer)

In a second terminal, start polling every second:

```sh
while true; do
  curl -s "https://example.com/wp-json/acrossai-mcp-manager/v1/auth/status?code=<auth_code>&server=wordpress-default-server"
  echo
  sleep 1
done
```

You should see `{"approved":false}` repeating.

---

## 5. User opens the auth URL in a browser (user)

Paste the `auth_url` from step 3 into a browser. You will see one of two things:

### 5a. Not logged in → login redirect

The browser lands on `wp-login.php?redirect_to=https%3A%2F%2Fexample.com%2Facrossai-mcp-manager%2F`. Log in.

After login, the browser lands on `/acrossai-mcp-manager/` (base URL, no `?action=`). You will see:

> **Missing Authentication Parameters**
> This page must be opened via a link from your CLI tool.

This is expected per research.md §R3 (redirect preservation trade-off). Re-paste the `auth_url` from step 3 to get back to the consent form.

### 5b. Logged in → consent form

The browser shows:

> **Authorize CLI Access**
> A CLI tool is requesting access to your MCP server "wordpress-default-server".
> Click Approve to grant the tool access. The session is single-use.
>
> **[Approve]**

Verify the page has no theme chrome (no admin bar, no theme header/footer). Verify the `<link rel="stylesheet" id="acrossai-mcp-frontend-css">` tag is present (DevTools → Network → CSS).

---

## 6. User clicks Approve (user)

Click **Approve**. The browser:

1. Posts to `?action=cli_auth_approve&code=<code>&server=<server>&_wpnonce=<nonce>`.
2. WordPress nonce-checks the request.
3. `CliController::approve_auth_code()` is called.
4. The browser is redirected to `?action=cli_auth_approved`.
5. You see:

> **CLI Authorization Approved**
> You can now return to your CLI tool — it will detect the approval shortly.
> This page can be closed.

---

## 7. CLI detects approval (developer)

The polling loop from step 4 now prints:

```json
{"approved":true,"token":"<32 hex chars>"}
```

Stop the loop. Copy the `token` value.

---

## 8. CLI exchanges for App Password (developer)

```sh
curl -X POST \
  -H 'Content-Type: application/json' \
  -d '{"code":"<auth_code>","server_id":"wordpress-default-server"}' \
  https://example.com/wp-json/acrossai-mcp-manager/v1/auth/exchange
```

**Expected response**:

```json
{
  "app_password": "abcd efgh ijkl mnop qrst uvwx",
  "username": "your-wp-username",
  "user_id": 1,
  "expires_in": 2592000,
  "server_id": "wordpress-default-server"
}
```

---

## 9. CLI uses App Password (developer)

```sh
curl -u "your-wp-username:abcd efgh ijkl mnop qrst uvwx" \
  https://example.com/wp-json/wp/v2/users/me
```

**Expected**: HTTP 200 with your WordPress user JSON. The Application Password is the credential the CLI uses for all future requests against the MCP servers it was authorized for.

---

## 10. Verify the consent surface is hardened (reviewer)

```sh
# Asset enqueue scoping — admin should NOT include the handle
curl -s -c /tmp/cookies https://example.com/wp-admin/ | grep -c 'acrossai-mcp-frontend-css'
# 0

# Asset enqueue scoping — home should NOT include the handle
curl -s https://example.com/ | grep -c 'acrossai-mcp-frontend-css'
# 0

# Asset enqueue scoping — consent page DOES include the handle (after login)
curl -s -b /tmp/cookies https://example.com/acrossai-mcp-manager/?action=cli_auth\&code=test\&server=test | grep -c 'acrossai-mcp-frontend-css'
# 1

# Theme markup absent — no wp_head() output
curl -s -b /tmp/cookies https://example.com/acrossai-mcp-manager/?action=cli_auth\&code=test\&server=test | grep -c 'wp-emoji-release.min.js'
# 0

# Cache-Control header is no-cache
curl -s -b /tmp/cookies -I https://example.com/acrossai-mcp-manager/?action=cli_auth\&code=test\&server=test | grep -i 'cache-control'
# Cache-Control: no-cache, must-revalidate, max-age=0

# Bad nonce → 403
curl -s -b /tmp/cookies -o /dev/null -w "%{http_code}\n" "https://example.com/acrossai-mcp-manager/?action=cli_auth_approve&code=test&_wpnonce=invalid"
# 403

# Kill switch off → 503
wp option update acrossai_mcp_npm_login_enabled 0
curl -s -b /tmp/cookies -o /dev/null -w "%{http_code}\n" https://example.com/acrossai-mcp-manager/?action=cli_auth\&code=test\&server=test
# 503
wp option update acrossai_mcp_npm_login_enabled 1
```

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `/acrossai-mcp-manager/` returns 404 | Rewrite rule not flushed | `wp rewrite flush` |
| Consent page shows theme header/footer | `wp_head()` accidentally re-introduced | Re-verify FR-011 implementation |
| Approve click → "no longer valid" error | Auth code expired (5-minute TTL) | Re-run step 3 |
| Approve click → 403 nonce error | Browser session expired (12–24h nonce window) | Re-paste auth URL to get a fresh nonce |
| CSS missing on consent page | `build/css/frontend.asset.php` not deployed | `npm run build` before packaging release |
| `wp option update` shows no change | Option was already set | `wp option get acrossai_mcp_npm_login_enabled` to verify |
