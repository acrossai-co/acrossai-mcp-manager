# Quickstart — Phase 5: OAuth / Claude Connectors Manual Verification

**Date**: 2026-06-18 | **Branch**: `005-oauth-connectors` | **Time**: ~20 minutes

This is the full RFC-conformant manual walk. Requires `curl`, a test
OAuth client (a small PHP CLI script that mimics Claude's behavior),
and a clean WordPress 6.9 / PHP 8.0 install.

---

## Setup

```bash
# 0.1 — Activate plugin; verify all 3 OAuth tables exist
wp db tables --format=csv | grep -E 'acrossai_mcp_(cli_auth_logs|oauth_tokens|oauth_audit)'
# Expected: 3 lines

# 0.2 — Verify rewrite rules are registered with the dot escaped (R1)
wp option get rewrite_rules --format=json | jq '. | with_entries(select(.key | contains("well-known")))' | grep -F '\\.well-known'
# Expected: at least 2 matches — proves B4 mitigation in place

# 0.3 — Create a Claude Connector entry on an MCP server
wp post meta update ...   # or via admin: Settings → MCP Manager → Edit server → Claude Connector tab
# Set: client_id=test-client-001, client_secret=test-secret-001,
#      redirect_uri=https://oauth-callback.test/callback
```

---

## Walk

### 1. Discovery (US1)

```bash
curl -s https://example.com/.well-known/oauth-authorization-server | jq .
```

✅ Pass = HTTP 200 + JSON with all RFC-8414-mandatory fields populated,
`code_challenge_methods_supported: ["S256"]`, `scopes_supported: ["mcp"]`.

```bash
curl -s https://example.com/.well-known/oauth-protected-resource | jq .
```

✅ Pass = HTTP 200 + JSON with `resource`, `authorization_servers`,
`bearer_methods_supported: ["header"]`.

---

### 2. PKCE generation (test client side)

```php
// In your test OAuth client script:
$verifier  = bin2hex( random_bytes( 32 ) ); // 64 hex chars = within 43-128 range
$challenge = strtr( rtrim( base64_encode( hash( 'sha256', $verifier, true ) ), '=' ), '+/', '-_' );
echo "verifier=$verifier\nchallenge=$challenge\n";
```

Save both — verifier stays on client; challenge sent at authorize.

---

### 3. Authorization request (US2)

```
Browser → https://example.com/acrossai-mcp-oauth/?response_type=code
  &client_id=test-client-001
  &redirect_uri=https://oauth-callback.test/callback
  &scope=mcp
  &state=abc123
  &code_challenge=<challenge>
  &code_challenge_method=S256
```

Log in as admin. Click **Approve**.

✅ Pass = browser redirects to
`https://oauth-callback.test/callback?code=<43char>&state=abc123`. The
code is opaque, 43 chars, base64url.

```bash
# Verify audit row exists
wp db query "SELECT event_type, client_id, user_id FROM wp_acrossai_mcp_oauth_audit WHERE event_type='code_issued' ORDER BY id DESC LIMIT 1;"
# Expected: 1 row
```

---

### 4. Token exchange (US3)

```bash
curl -i -X POST https://example.com/wp-json/acrossai-mcp/v1/token \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d "grant_type=authorization_code" \
  -d "code=<paste-code>" \
  -d "client_id=test-client-001" \
  -d "client_secret=test-secret-001" \
  -d "redirect_uri=https://oauth-callback.test/callback" \
  -d "code_verifier=<paste-verifier>"
```

✅ Pass = HTTP 200 + JSON
`{"access_token":"<43char>","token_type":"Bearer","expires_in":3600,"scope":"mcp"}`.

```bash
# Verify token row + audit row exist
wp db query "SELECT id, expires_at, revoked_at FROM wp_acrossai_mcp_oauth_tokens ORDER BY id DESC LIMIT 1;"
# Expected: 1 row, revoked_at IS NULL, expires_at is ~now+1hour
```

---

### 5. Bearer use against MCP endpoint (US4)

```bash
curl -i -H "Authorization: Bearer <access_token>" \
  https://example.com/wp-json/mcp/wordpress-default-server/whatever
```

✅ Pass = same response the admin would get logged in.

```bash
wp db query "SELECT event_type, token_hash_prefix, endpoint FROM wp_acrossai_mcp_oauth_audit WHERE event_type='bearer_auth_success' ORDER BY id DESC LIMIT 1;"
# Expected: 1 row with token_hash_prefix matching first 8 chars of sha256(access_token)
```

---

### 6. Negative paths (US3 SC-002 → SC-005)

```bash
# Re-redeem the same code → must fail AND revoke the previous token
curl -X POST https://example.com/wp-json/acrossai-mcp/v1/token \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d "grant_type=authorization_code&code=<same-code>&client_id=test-client-001&client_secret=test-secret-001&redirect_uri=https://oauth-callback.test/callback&code_verifier=<same-verifier>"
# Expected: HTTP 400 {"error":"invalid_grant"}

# Previous token from §4 → now revoked
curl -i -H "Authorization: Bearer <access_token>" \
  https://example.com/wp-json/mcp/wordpress-default-server/whatever
# Expected: anonymous response (NOT what an admin would see)
```

✅ Pass = SC-004 verified.

```bash
# Wrong verifier → invalid_grant
curl -X POST ... -d "code_verifier=wrong-verifier-..."
# Expected: HTTP 400 {"error":"invalid_grant","error_description":"PKCE verifier mismatch."}
```

✅ Pass = SC-005 verified.

```bash
# Trailing-slash difference in redirect_uri
# Expected: error page on authorize step (no redirect) — SC-006
```

---

### 7. Rate limiting (FR-014a)

```bash
# Hammer the token endpoint with bad client_secret 6 times in 30 seconds
for i in $(seq 1 6); do
  curl -i -X POST .../v1/token -d "grant_type=authorization_code&code=any&client_id=test-client-001&client_secret=wrong&redirect_uri=..&code_verifier=.."
done
```

✅ Pass = first 4 return 401 invalid_client; the 5th and 6th return
HTTP 429 + `Retry-After: 60` + `{"error":"slow_down"}`. Audit row
`failed_rate_limit` recorded once (not per failed request).

---

### 8. Cleanup cron (FR-019c)

```bash
# Manually trigger cleanup (instead of waiting 24h)
wp eval 'do_action("acrossai_mcp_oauth_cleanup");'

# Verify audit row recording the sweep
wp db query "SELECT event_type, details_json FROM wp_acrossai_mcp_oauth_audit WHERE event_type='cleanup_run' ORDER BY id DESC LIMIT 1;"
# Expected: 1 row with details_json like {"rows_deleted_codes":N,"rows_deleted_tokens":M,"rows_deleted_audit":K}
```

✅ Pass = sweep ran idempotently; if a second `do_action(...)` is called
immediately, it should be a no-op (counts are 0).

---

## Static + automated checks

```bash
# 9.1 — FR-021 grep gate (no constructor hooks)
grep -rnE '^[^*/]*\b(add_action|add_filter)\s*\(' includes/OAuth/
# Expected: empty

# 9.2 — FR-022 grep gate (Main.php wires every OAuth hook)
grep -cE 'loader->add_(action|filter)' includes/Main.php
# Expected: count includes the new OAuth wirings (≥ phase 4 baseline + ~7)

# 9.3 — PHPCS
vendor/bin/phpcs includes/OAuth/ includes/Database/OAuth* tests/phpunit/OAuth/
# Expected: 0 errors, 0 warnings

# 9.4 — PHPStan level 8
vendor/bin/phpstan analyse includes/OAuth/ includes/Database/OAuth* --level=8
# Expected: 0 errors

# 9.5 — PHPUnit OAuth suite (uses WP-PHPUnit bootstrap)
vendor/bin/phpunit --testsuite=oauth
# Expected: all green; per-RFC-section coverage
```

---

## Definition of Done

If §1–§8 of the manual walk pass AND §9 static checks pass, Phase 5
ships. Mark the DoD checkboxes in spec.md complete.
