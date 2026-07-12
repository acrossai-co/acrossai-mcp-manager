# PHP Hooks Contract — Feature 021

Feature 021 exposes **1 filter + 4 actions** as its permanent public API. These invocations are preserved through any future refactor per Constitution Principle V — removing them without a major version bump is prohibited.

---

## Filter — `acrossai_mcp_manager_connector_profiles`

**Purpose**: The ONLY registration path for `AbstractConnectorProfile` subclasses contributed by companion plugins.

**Fires**: Once per request, memoized in `ConnectorProfileRegistry::get_profiles()`. The filter's return value is deduplicated by slug (later-wins with `_doing_it_wrong` under `WP_DEBUG`) and sorted alphabetically by slug before being cached.

**Signature**:

```php
apply_filters(
    'acrossai_mcp_manager_connector_profiles',
    array $profiles   // array<AbstractConnectorProfile>
): array<AbstractConnectorProfile>
```

**Contract**:

- Input `$profiles` is empty on first fire. Companion plugins append their own instances.
- Output MUST be an array of `AbstractConnectorProfile` instances. Non-conforming entries are silently discarded with a `_doing_it_wrong` notice under `WP_DEBUG`.
- Duplicate slugs (`$profile->get_slug()`) → later-wins with `_doing_it_wrong` under `WP_DEBUG`. Prod stays silent.
- Callbacks MUST NOT fire during callback (recursion protection via memoization — the registry short-circuits on the second entry).

**Example**:

```php
add_filter( 'acrossai_mcp_manager_connector_profiles', function ( array $profiles ) {
    $profiles[] = new \MyCompanion\ClaudeDesktopProfile();
    return $profiles;
} );
```

---

## Action — `acrossai_mcp_manager_oauth_token_issued`

**Purpose**: Observability hook fired after every successful access-token issuance (initial or refresh).

**Fires**:
- Inside `TokenController::handle_authorization_code` after a successful auth-code → token exchange.
- Inside `TokenController::handle_refresh_token` after a successful refresh → new pair.

**Signature**:

```php
do_action(
    'acrossai_mcp_manager_oauth_token_issued',
    int    $token_id,        // OAuthTokens.id (access token's row id)
    string $client_id,       // OAuthClients.client_id
    int    $user_id,         // WP user id
    string $connector_slug   // OAuthClients.connector_slug ('' for DCR clients)
): void
```

**Contract**:

- Payload contains NO raw tokens, secrets, PII, or IP addresses.
- Fires ONCE per issuance — NOT per refresh-rotation pair (rotation fires `token_revoked` for the old + `token_issued` for the new = one call each).
- Fired AFTER the DB commit; consumers can assume the state is durable.
- Consumer exceptions bubble up (F020 pattern of controller-side try/catch is NOT applied here — the caller is `/token` which the client trusts to be atomic; a buggy observer causing a 500 is preferable to silently misreporting the wire transaction).

**Example consumer** (audit log):

```php
add_action( 'acrossai_mcp_manager_oauth_token_issued', function ( $token_id, $client_id, $user_id, $connector_slug ) {
    error_log( sprintf(
        '[oauth] token_issued token=%d client=%s user=%d connector=%s',
        $token_id, $client_id, $user_id, $connector_slug ?: '<dcr>'
    ) );
}, 10, 4 );
```

---

## Action — `acrossai_mcp_manager_oauth_authorization_denied`

**Purpose**: Fires when a user clicks Deny on the consent screen.

**Fires**: Inside `AuthorizationController::handle_post` on the Deny branch, BEFORE the redirect fires (so consumers can log before the browser leaves).

**Signature**:

```php
do_action(
    'acrossai_mcp_manager_oauth_authorization_denied',
    string $client_id,
    string $redirect_uri,
    string $reason           // Currently only 'user_denied'; reserved for future codes
): void
```

**Contract**: No PII beyond the client_id + redirect_uri. `reason` is a stable string enumeration.

---

## Action — `acrossai_mcp_manager_oauth_token_revoked`

**Purpose**: Fires when a token transitions from `revoked=0` to `revoked=1`.

**Fires**:
- Inside `TokenController::handle_refresh_token` for the old refresh (reason `'refresh_rotation'`).
- Inside admin `generate-client` bulk revocation for every revoked token from the prior client (reason `'client_regenerated'`).
- Inside the `deleted_user` cascade for every revoked token (reason `'user_deleted'`, per Q4 / FR-042).
- Anywhere `TokensQuery::revoke_by_hash` or `revoke_by_user_id` or `revoke_by_client_id` returns non-zero.

**Signature**:

```php
do_action(
    'acrossai_mcp_manager_oauth_token_revoked',
    int    $token_id,       // OAuthTokens.id
    string $reason          // 'refresh_rotation' | 'client_regenerated' | 'user_deleted' | 'admin_action'
): void
```

**Contract**:

- Fires once per row transitioned. If `revoke_by_user_id` revokes 47 tokens, the action fires 47 times.
- `$reason` is a stable enumeration — new values added only via a documented DEC + memory capture.
- Payload MUST NOT include raw tokens.

---

## Action — `acrossai_mcp_manager_oauth_cleanup`

**Purpose**: Daily cron hook that also serves as a public extension point for integrators wanting to piggy-back on the daily schedule.

**Fires**: Once at the start of `Cleanup::run()`, BEFORE the actual delete queries.

**Signature**:

```php
do_action( 'acrossai_mcp_manager_oauth_cleanup' ): void
```

**Contract**:

- No arguments.
- The `wp_schedule_event` handler wires `Cleanup::run` at priority 10 on this action; consumers wanting to run before/after the plugin's own cleanup use different priorities.

---

## Design Notes

### SEC-021-008 — No `authorization_approved` action in v1

**Decision** (accepted trade-off): v1 fires `acrossai_mcp_manager_oauth_authorization_denied` on Deny but does **not** fire a symmetric `acrossai_mcp_manager_oauth_authorization_approved` on Approve.

**Rationale**: Approvals are inferrable from the subsequent `token_issued` fire — every successful Approve → `/token` exchange produces exactly one `token_issued` event with the same `client_id`. Audit consumers wanting a "consent granted" record cross-reference these two events. Adding an approve-side action is possible but adds a new stable event surface for negligible marginal signal.

**Follow-up path**: If an audit consumer emerges that requires observing approvals in isolation (e.g., an operator abandoning the flow after Approve but before `/token`), a future release may add `acrossai_mcp_manager_oauth_authorization_approved` fired in `AuthorizationController::handle_post` Approve branch just before the redirect. Adding an action is minor-version compatible; removing one is not.

Filed against: [Plan-phase security review §SEC-021-008](../../../docs/security-reviews/2026-07-10-021-oauth-2-1-implementation-plan.md).

### No filters on error responses

Error redirects and error JSON bodies do NOT fire filters — the exact wire format is a public contract with the spec (RFC 6749 §5.2 shape). Consumers wanting to observe errors should use logs at the request layer, not filter the plugin's response.

### No filters on token generation

`SecretsVault::random_token()` output is NOT filterable. Deterministic token generation would defeat the security model.

### No filters on hash algorithm

The hash algorithm is hardcoded to SHA-256 per S3. Making it filterable would let a companion plugin downgrade to MD5.

### Recursion / re-entrancy

`ConnectorProfileRegistry::get_profiles()` memoizes to prevent re-firing the filter inside a callback. `TokenValidator::authenticate` has its own static recursion guard (FR-025) so a downstream `current_user_can` call inside the token lookup path can't re-trigger the filter.

### Testing surface

- `ConnectorProfileRegistryTest` (planned Phase 2 task) verifies: filter fires ONCE per request; duplicate slugs → later-wins; empty filter → empty array.
- The four actions are verified via `TokenValidatorTest` (integration verifies `token_issued` fires per test scenario) and `UserDeletedCascadeTest` (verifies `token_revoked` fires per revoked row).
