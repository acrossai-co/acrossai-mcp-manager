# Contract: `CliController::peek_pending_server( string $auth_code ): ?string`

**Module**: `Includes\REST\CliController` (Phase 6 owner)
**Consumer**: `Public\Partials\FrontendAuth::handle_cli_auth()` (Phase 7)
**Added**: 2026-06-30 — fixes SEC-001 / S9 (consent-surface displayed-state from authoritative store, not URL)
**Type**: pure static read helper — no state mutation, no transient writes, no logging at INFO level, idempotent, side-effect-free

## Signature

```php
namespace AcrossAI_MCP_Manager\Includes\REST;

final class CliController {
    /**
     * Read-only peek at the pending auth-code transient. Returns the bound
     * server_id ONLY when the transient exists, is well-formed, and has
     * status === 'pending'. Returns null in every other case.
     *
     * Pure read — no transient writes, no state mutation, no audit-log writes.
     * Idempotent: calling N times returns identical values and changes no state.
     *
     * @param string $auth_code Raw authorization code from the CLI's URL.
     * @return string|null      The transient's bound `server_id`, or null if
     *                          the code is unknown, expired, malformed, or
     *                          not in 'pending' state.
     */
    public static function peek_pending_server( string $auth_code ): ?string;
}
```

## Behavior

| Input | Transient state | Return value | Notes |
|---|---|---|---|
| `$auth_code === ''` | (not consulted) | `null` | Early reject without a transient read |
| Unknown / never-existed code | `false` (no transient) | `null` | Standard `get_transient()` semantics |
| Expired code | `false` (TTL passed) | `null` | Same as unknown — `get_transient()` returns false |
| Malformed payload (not an array) | non-array | `null` | B11 defensive check |
| Missing `status` or `server_id` keys | array missing keys | `null` | B11 defensive triple-check |
| Wrong type for `server_id` (not string) | non-string `server_id` | `null` | B11 defensive triple-check |
| Empty string `server_id` | `''` | `null` | Empty slug is invalid for consent display |
| `status === 'approved'` (already-used code) | approved | `null` | Only `pending` codes are exposed |
| `status === 'pending'` + valid `server_id` | `'wordpress-default-server'` | `'wordpress-default-server'` | Happy path — returns the slug verbatim |

## Defensive read (B11 — generalized)

The implementation MUST apply the B11 transient-defensive triple-check (see `docs/memory/BUGS.md` §B11):

```php
public static function peek_pending_server( string $auth_code ): ?string {
    if ( '' === $auth_code ) {
        return null;
    }
    $payload = get_transient( self::AUTH_TRANSIENT_PREFIX . $auth_code );
    if ( ! is_array( $payload ) ) {
        return null;
    }
    if ( ! isset( $payload['status'], $payload['server_id'] ) ) {
        return null;
    }
    if ( 'pending' !== $payload['status'] ) {
        return null;
    }
    if ( ! is_string( $payload['server_id'] ) || '' === $payload['server_id'] ) {
        return null;
    }
    return $payload['server_id'];
}
```

## What this method MUST NOT do

- MUST NOT call `set_transient()`, `delete_transient()`, or any other transient-write function.
- MUST NOT call `Recorder::*` or any audit-log write.
- MUST NOT call `error_log()` on the not-pending or malformed paths (silent fallback — the absence of a return is the operational signal).
- MUST NOT throw exceptions — return `null` on every degenerate input.
- MUST NOT mutate `$payload` or any other state.

## What the consumer (FrontendAuth) does with the result

```php
// In FrontendAuth::handle_cli_auth( string $code ):
$bound_server = CliController::peek_pending_server( $code );
if ( '' === $code || null === $bound_server ) {
    // Render "Missing Authentication Parameters" path.
    return;
}
// Render consent body with $bound_server (escaped via esc_html()).
```

## Test surface

Required PHPUnit coverage in `tests/phpunit/RestCli/PeekPendingServerTest.php`:

- Unknown code → `null`
- Empty `$auth_code` → `null`
- Malformed transient (non-array) → `null`
- Missing keys (`status` or `server_id`) → `null`
- `status !== 'pending'` (approved, expired-status) → `null`
- Non-string `server_id` (int, bool, array) → `null`
- Empty string `server_id` → `null`
- Valid pending transient → returns `server_id` string verbatim
- Idempotency: two consecutive calls return identical values, no transient writes verified via a `wp_transient_*` action spy
- No state mutation: spy on `set_transient` / `delete_transient` / `Recorder::record_*` and assert zero invocations

## Cross-references

- **SEC-001** (CWE-451 / CWE-441) — the security finding this helper resolves. See `docs/security-reviews/2026-06-30-007-frontend-cli-auth-plan.md` §SEC-001.
- **S9** — generalized pattern captured to `docs/memory/PROJECT_CONTEXT.md`. Applies to OAuth consent (Phase 5) and any future device-grant surface.
- **B11** — transient-defensive triple-check pattern this helper applies.
- **A11** — pure stateless static method exemption (no instance state, no hook registration). T043 architecture-guard verify confirms alignment.
- **Phase 6 FR-008** — the `approve_auth_code()` cousin that writes the transient this helper reads.
- **Phase 7 FR-008 + FR-012** (2026-06-30 amendments) — the consumer-side contract.
