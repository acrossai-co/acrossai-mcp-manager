# Research — Phase 5: OAuth / Claude Connectors

**Date**: 2026-06-18 | **Branch**: `005-oauth-connectors`

---

## R1 — Rewrite-rule dot escape (B4 mitigation)

**Decision**: register the two `.well-known` URLs as PCRE patterns with
the leading dot **explicitly escaped** (`\.well-known`). Without
escaping, `.` matches any character — a request to
`/xwell-known/oauth-authorization-server` would resolve to the same
WordPress query var and return the discovery JSON, leaking a probe
vector and violating the canonical URL space.

```php
// Includes\OAuth\ClaudeConnectors::register_rewrite_rules()
add_rewrite_rule(
    '^\.well-known/oauth-authorization-server/?$',
    'index.php?acrossai_mcp_oauth=as_metadata',
    'top'
);
add_rewrite_rule(
    '^\.well-known/oauth-protected-resource/?$',
    'index.php?acrossai_mcp_oauth=rs_metadata',
    'top'
);
add_rewrite_rule(
    '^acrossai-mcp-oauth/?$',
    'index.php?acrossai_mcp_oauth=authorize',
    'top'
);
```

**Verification**: PHPUnit `R1_assertRewriteRuleEscape` calls
`get_option('rewrite_rules')` after activation, asserts each registered
pattern contains the literal substring `\.well-known` (not just
`.well-known`).

**Alternatives rejected**:
- Letting WP normalize the URL: the `add_rewrite_rule` documentation
  does NOT escape; B4 is on the developer.
- Matching `/.well-known/[a-z-]+` and dispatching by parameter: makes
  every unmatched `.well-known` subpath a discovery handler, leaking
  the namespace.

---

## R2 — Cryptographic-random code + token generation

**Decision**: both raw auth codes and raw access tokens are 32 bytes
from `random_bytes(32)` (CSPRNG; PHP 8 throws on entropy failure),
base64url-encoded to 43 chars (32 bytes × 8 / 6 = 42.67 → 43 chars
unpadded).

```php
final class Storage {
    private function generate_raw_code(): string {
        // random_bytes throws \Exception under PHP 8 on entropy failure
        // — caller catches and returns HTTP 503.
        $raw = random_bytes( 32 );
        return strtr( rtrim( base64_encode( $raw ), '=' ), '+/', '-_' );
    }
}
```

**Storage hash**: `hash( 'sha256', $raw, false )` → lowercase 64-char
hex. SQL column `CHAR(64) NOT NULL UNIQUE`.

**Comparison primitive**: `hash_equals()` for every secret check —
never `===`, never `strcmp`, never `==`.

**Why 32 bytes**: 256 bits of entropy ≫ the 128-bit minimum for
unguessable tokens. Same length as a SHA-256 output, which makes the
codes and the PKCE challenge structurally symmetric in size — useful
for human operators eyeballing logs.

---

## R3 — PKCE S256 validation (RFC 7636 §4.6 + §4.2)

**Decision**: implement S256 only; reject `plain` at the authorize
endpoint per spec Assumption (PKCE S256 only).

```php
// Includes\OAuth\PKCE
public function verify( string $code_verifier, string $stored_challenge ): bool {
    $expected = strtr(
        rtrim( base64_encode( hash( 'sha256', $code_verifier, true ) ), '=' ),
        '+/', '-_'
    );
    return hash_equals( $stored_challenge, $expected );
}
```

**RFC test vectors** (RFC 7636 §B):
| verifier | expected challenge |
|---|---|
| `dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk` | `E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM` |

These become the canonical golden-fixture assertions in `PKCETest`.

`code_verifier` MUST be 43-128 chars per RFC 7636 §4.1; PKCE class
validates length at intake and throws `\InvalidArgumentException` on
violation (caller returns HTTP 400 `invalid_grant`).

---

## R4 — Rate-limit transient key derivation (FR-014a)

**Decision**: two transients per `(client_id, IP)` tuple — one for the
minute-window (Threshold A), one for the hour-window (Threshold B).

```php
private function rate_key( string $client_id, string $ip, string $bucket ): string {
    return 'oauth_rate_' . hash(
        'sha256',
        $client_id . '|' . $ip . '|' . $bucket
    );
}

// Threshold A — 5 fails in any 1-minute rolling window
$min_key = $this->rate_key( $cid, $ip, gmdate( 'Y-m-d-H-i' ) );

// Threshold B — 50 fails in any 1-hour rolling window
$hour_key = $this->rate_key( $cid, $ip, gmdate( 'Y-m-d-H' ) );
```

Bucket timestamps in the key give natural rotation; no explicit cleanup
needed (transients expire). On a working object cache (Redis/Memcached)
the read+write is sub-millisecond.

**Request IP**: `$_SERVER['REMOTE_ADDR']` only. `X-Forwarded-For` is
NOT trusted by default because the plugin doesn't know the operator's
reverse-proxy config. Operators behind a proxy MUST configure
WordPress's `$_SERVER['REMOTE_ADDR']` correctly via their proxy
headers (documented in admin notice, per the WP-Cron-disabled pattern
in spec Edge Cases).

**Increment then check** (not check-then-increment) to close a race:
under concurrent failed requests, both clients might read the counter
as 4, both increment to 5, both proceed instead of being rejected.
Atomic increment via `wp_cache_incr` when available, fallback to
`get + set` under lock-free best effort.

---

## R5 — Audit log event-type enum (FR-019a, Q1)

**Decision**: 11 canonical event types (enum-locked for this phase;
new types require a spec amendment + memory capture):

```php
final class AuditLog {
    public const EVENT_CODE_ISSUED              = 'code_issued';
    public const EVENT_CODE_REDEEMED            = 'code_redeemed';
    public const EVENT_CONSENT_DENIED           = 'consent_denied';
    public const EVENT_FAILED_UNKNOWN_CLIENT    = 'failed_unknown_client';
    public const EVENT_FAILED_REDIRECT_MISMATCH = 'failed_redirect_mismatch';
    public const EVENT_FAILED_REPLAY_ATTEMPT    = 'failed_replay_attempt';
    public const EVENT_FAILED_RATE_LIMIT        = 'failed_rate_limit';
    public const EVENT_FAILED_CROSS_SERVER_TOKEN= 'failed_cross_server_token';
    public const EVENT_BEARER_AUTH_SUCCESS      = 'bearer_auth_success';
    public const EVENT_TOKEN_REVOKED            = 'token_revoked';
    public const EVENT_CLEANUP_RUN              = 'cleanup_run';
}
```

Severity table is informational (the column is just `event_type` — no
`severity` column). Admins query by `event_type IN (…critical…)` for
forensics.

**`details_json`** is event-specific structured data. For
`failed_redirect_mismatch`, it records `{expected: <stored>, received:
<from request>}`. For `cleanup_run`, it records `{rows_deleted_codes:
N, rows_deleted_tokens: M, rows_deleted_audit: K}`. **NEVER** records
raw codes, raw tokens, or `client_secret` values.
