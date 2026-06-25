# Contract — Bearer Authentication Filter

**Date**: 2026-06-18 | **RFC**: 6750 | **FR**: FR-015 + FR-016

## Filter

| Property | Value |
|---|---|
| Hook | `determine_current_user` |
| Priority | `20` (after WordPress's default auth methods at priority 10) |
| Callback | `Includes\OAuth\BearerAuth::resolve_bearer_token` |
| Wired in | `Includes\Main::define_public_hooks()` via Loader |

## Inputs

```php
public function resolve_bearer_token( $user_id ): int
```

Plus implicit inputs:
- `$_SERVER['HTTP_AUTHORIZATION']` (or `$_SERVER['REDIRECT_HTTP_AUTHORIZATION']`
  for Apache+CGI cases — checked in order)
- The request URL — must be on the MCP REST namespace
  (`/wp-json/mcp/*` or `/wp-json/acrossai-mcp/*`) for the filter to act

## Algorithm

```
1. If $user_id is already truthy (some earlier auth method succeeded)
     → return $user_id unchanged
       (NEVER override an existing auth result)

2. Parse Authorization header
     → if missing or not "Bearer <token>" pattern → return $user_id unchanged

3. Determine target server_id from request URL
     → if URL is /wp-json/mcp/<route> → look up server by route in MCPServer\Query
     → if URL is /wp-json/acrossai-mcp/v1/<…> → return $user_id unchanged (not an MCP server endpoint)

4. Compute sha256(token) → look up in OAuthToken\Query
     → query MUST be: hash matches AND server_id matches AND
       revoked_at IS NULL AND expires_at > NOW()

5. Found valid token →
     • Audit log `bearer_auth_success` (with token_hash_prefix, server_id, user_id, endpoint)
     • return $token->user_id

6. Found token but server_id mismatch (cross-server) →
     • Audit log `failed_cross_server_token`
     • return $user_id unchanged (anonymous)

7. Found token but expired or revoked →
     • NO audit log (high-volume — would spam audit table)
     • return $user_id unchanged

8. No matching token →
     • NO audit log (would create discovery oracle)
     • return $user_id unchanged
```

## Outputs

- **Found valid token**: returns `(int) $token->user_id` — WordPress
  then sets the current user for the request lifetime
- **Anything else**: returns the input `$user_id` unchanged — other
  auth methods (cookie session, Application Password, etc.) still get
  to run; this filter NEVER short-circuits

## Security invariants

1. **Constant-time hash comparison** — `hash_equals()` not `===`
   (timing-attack defense; though the DB index lookup is the primary
   filter, the comparison after fetch still uses `hash_equals`)
2. **Cross-server defense** — a token issued for server A cannot
   authenticate against server B (FR-015 step 6). The defense is the
   `server_id` predicate in the DB query, NOT a post-fetch check
   (faster + atomic).
3. **No oracle** — invalid tokens produce no audit row AND no error
   distinguishable from "no token at all". Attackers cannot probe
   whether a guess was a valid-but-expired token vs. unknown token.
4. **Never elevates** — if `$user_id` is already set, the filter never
   overrides. WordPress's default cookie auth ALWAYS wins if it
   matches.

## Bearer header parsing

```php
private function get_bearer_token_from_request(): ?string {
    $header = '';
    if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
        $header = (string) $_SERVER['HTTP_AUTHORIZATION'];
    } elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
        // Apache + CGI strips Authorization header by default; this is
        // the fallback for hosts that route via getallheaders polyfill.
        $header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } else {
        return null;
    }

    if ( 0 !== stripos( $header, 'Bearer ' ) ) {
        return null;
    }

    $token = trim( substr( $header, 7 ) );
    if ( '' === $token || strlen( $token ) > 256 ) {
        return null; // Length guard against pathological inputs.
    }

    return $token;
}
```

The 256-char length guard is paranoid — a real token is 43 chars
base64url. Larger inputs are either accidental garbage or attack
probes; cheap to reject.

## Audit log entry shape (FR-016)

```json
{
  "event_type": "bearer_auth_success",
  "server_id": 5,
  "user_id": 42,
  "client_id": null,
  "token_hash_prefix": "a1b2c3d4",
  "endpoint": "/wp-json/mcp/wordpress-default-server/tools/list",
  "details_json": null
}
```

Raw token is NEVER written; only the 8-hex-char prefix of its SHA-256
hash for log correlation against the tokens table.
