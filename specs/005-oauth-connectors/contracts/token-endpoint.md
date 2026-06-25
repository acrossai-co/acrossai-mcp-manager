# Contract — Token Endpoint

**Date**: 2026-06-18 | **RFC**: 6749 §4.1.3 + §5.1 + §5.2 + §10.5 | **FR**: FR-011 through FR-014a

## Endpoint

| Property | Value |
|---|---|
| URL | `https://{site_url}/wp-json/acrossai-mcp/v1/token` |
| Method | `POST` only (HTTP 405 for everything else) |
| `Content-Type` (request) | `application/x-www-form-urlencoded` (strict — JSON body rejected) |
| `permission_callback` | `__return_true` — **documented S2 exemption**; auth lives in POST body per RFC 6749 §2.3.1 |
| `Content-Type` (response) | `application/json` |
| `Cache-Control` (response) | `no-store` (RFC 6749 §5.1) |
| `Pragma` (response) | `no-cache` |

## Request body — required fields

| Field | Type | Source |
|---|---|---|
| `grant_type` | `string` (MUST equal `'authorization_code'`) | constant from client |
| `code` | `string` (43-char base64url) | from authorize redirect |
| `client_id` | `string` | from server's `claude_connector_client_id` |
| `client_secret` | `string` | from server's `claude_connector_client_secret` |
| `redirect_uri` | `string` (must match authorize-time value) | from client config |
| `code_verifier` | `string` (43-128 chars) | from client's PKCE generation |

## Success response — HTTP 200

```json
{
  "access_token": "<43-char base64url opaque>",
  "token_type": "Bearer",
  "expires_in": 3600,
  "scope": "mcp"
}
```

## Validation chain (FR-012, order is load-bearing)

```
Step 0 — Rate limit check (FR-014a, BEFORE all other validation)
  Threshold A (5 fails / 1 min) OR Threshold B (50 fails / 1 hr)
    → HTTP 429 + `Retry-After` header + `{"error":"slow_down"}`
       (NO audit log on every rejected request — only on threshold-crossing)

Step 1 — Required fields present
  Any missing → HTTP 400 + `{"error":"invalid_request"}`

Step 2 — grant_type === "authorization_code"
  Otherwise → HTTP 400 + `{"error":"unsupported_grant_type"}`

Step 3 — client_id resolves to an MCP server row
  Otherwise → HTTP 401 + `{"error":"invalid_client"}`

Step 4 — client_secret === stored secret (via hash_equals)
  Otherwise → HTTP 401 + `{"error":"invalid_client"}`
  (constant-time comparison; failure increments rate-limit counter)

Step 5 — sha256(code) found in CliAuthLog table for THIS client_id, not redeemed, not expired
  Unknown code → HTTP 400 + `{"error":"invalid_grant"}`
  Already redeemed → HTTP 400 + `{"error":"invalid_grant"}` AND revoke ALL tokens issued from this code (FR-014 anti-replay)
                     + audit log `failed_replay_attempt`
                     + audit log `token_revoked` for each affected token
  Expired (now > created_at + 600s) → HTTP 400 + `{"error":"invalid_grant","error_description":"Authorization code expired."}`

Step 6 — redirect_uri === stored redirect_uri (byte-for-byte)
  Otherwise → HTTP 400 + `{"error":"invalid_grant"}`

Step 7 — base64url(sha256(code_verifier)) === stored code_challenge
  Otherwise → HTTP 400 + `{"error":"invalid_grant","error_description":"PKCE verifier mismatch."}`

Step 8 (success path) — Atomic CAS redeem + issue access token (SEC-001 amendment 2026-06-21)
  • Atomic CAS: UPDATE codes SET completed_at=NOW() WHERE id=:id AND completed_at IS NULL
    → if $wpdb->rows_affected === 1: continue (we won the race)
    → if $wpdb->rows_affected === 0: another concurrent request already redeemed
      this code → jump to Step 8b (REPLAY path)
  • Storage::issue_access_token($server_id, $user_id, $scope)
  • Audit log `code_redeemed`
  • HTTP 200 + success JSON envelope (above)
  • Reset rate-limit counters for this (client_id, IP) tuple

Step 8b — REPLAY path (FR-014, reached from Step 5 OR Step 8 CAS-loss)
  • Storage::revoke_all_tokens_for_code($code_row_id)
    → marks revoked_at=NOW() on every OAuthToken row whose code-of-origin
      is this row (when child token rows track this — see data-model.md E2)
    → for each revoked token, write `token_revoked` audit row
  • Audit log `failed_replay_attempt` with client_id, IP, code_row_id
  • Return HTTP 400 + {"error":"invalid_grant"}
```

**SEC-001 race condition (resolved 2026-06-21)**: The pre-amendment
Step 8 described "mark code as redeemed" as a non-atomic SELECT-then-
UPDATE. Under concurrent requests with the same leaked code + leaked
verifier (PKCE doesn't help if both are leaked together), both requests
would pass Step 5's "not redeemed" check at T0, then both would issue
tokens at T1. Atomic CAS at Step 8 closes the window: only one request
can succeed; the loser is funneled into Step 8b.

PHPUnit test (TokenControllerTest::testConcurrentRedeemRaceIsAntiReplay)
MUST cover this by simulating two concurrent transactions in
REPEATABLE-READ isolation and asserting:
1. Exactly one returns HTTP 200 with an access token
2. Exactly one returns HTTP 400 invalid_grant
3. After both complete, the OAuthToken row from the winner is revoked
   (revoked_at IS NOT NULL) per FR-014

## Error response envelope (RFC 6749 §5.2)

Every error response (HTTP 400 / 401 / 429) has shape:

```json
{
  "error": "<error_code>",
  "error_description": "<optional human-readable description>"
}
```

Error codes used by this endpoint:
- `invalid_request` — missing required field
- `invalid_client` — unknown client_id OR client_secret mismatch
- `invalid_grant` — bad code (unknown / expired / redeemed / PKCE mismatch / redirect mismatch)
- `unsupported_grant_type` — grant_type != `authorization_code`
- `slow_down` — rate-limit threshold crossed

## S2 exemption documentation

`permission_callback: __return_true` violates memory rule S2 literally
("never `__return_true` on mutating routes"). The exemption is
RFC-mandated: RFC 6749 §2.3.1 specifies that authentication for the
token endpoint happens via the `client_id` + `client_secret` POST body
parameters. Routing this authentication through a `permission_callback`
would split validation logic across two callbacks (the
`permission_callback` would need to parse the POST body, then the
`callback` would re-validate the same fields) — actively WORSE for
security and conformance.

**Strong defense**: the rest of S2's intent ("don't make mutating
endpoints accidentally callable") is preserved by the FR-012 chain —
no token is issued without a valid client_id, valid client_secret,
valid code, matching PKCE verifier, and matching redirect_uri.

**S7 capture queued** for post-implementation memory: "OAuth token
endpoint is the documented exception to S2 — auth lives in POST body,
not session/header."
