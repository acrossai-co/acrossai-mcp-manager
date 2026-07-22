# PHP Hooks Contract — Feature 032 (Extended Scope)

Feature 032 introduces **1 filter + 3 new actions + 2 hook-argument extensions to existing actions**. These invocations are preserved through any future refactor per Constitution Principle V — removing them without a major version bump is prohibited.

Cross-reference: F021 introduced the base observability actions (`acrossai_mcp_manager_oauth_token_issued`, `_authorization_denied`, `_token_revoked`, `_cleanup`) and the `acrossai_mcp_manager_connector_profiles` filter — see `specs/021-oauth-2-1-implementation/contracts/php-hooks.md`. F032 adds to that surface without removing anything.

---

## Action — `acrossai_mcp_connector_user_approval_revoked`

**Purpose**: Fired immediately after an admin clicks "Revoke approval" on the Approved Users panel and the DB DELETE completes. Enables both the default token-revoke cascade (registered by `Main::define_admin_hooks()` per §A1) AND arbitrary third-party listeners (audit log, notification email, Slack webhook).

**Fires**:
- Inside `ConnectorAdminController::handle_revoke_user_approval` immediately after `ConnectorApprovedUsersQuery::instance()->revoke( $server_id, $slug, $user_id )` returns, regardless of whether the row existed pre-call (idempotent DELETE semantics — the action fires per admin intent, not per row deletion).

**Signature**:

```php
do_action(
    'acrossai_mcp_connector_user_approval_revoked',
    int    $server_id,       // MCP server row id
    string $connector_slug,  // Connector profile slug (e.g. 'claude')
    int    $user_id,         // WP user id whose approval was revoked
    int    $revoked_by       // Admin's user id (get_current_user_id() at call site)
): void
```

**Contract**:

- Args are cast to `(int)` / `(string)` at the fire site — listeners may trust the types.
- `$revoked_by` is `get_current_user_id()` at the moment the REST endpoint received the request; NEVER 0 in normal admin flows (the endpoint enforces `manage_options`).
- Return value is ignored (fire-and-forget observability signal per D19).
- Default listener `ConnectorAdminController::cascade_revoke_tokens_on_approval_revoked` at priority 10 cascades into a token revoke — see the filter below for opt-out.
- Third-party plugins MAY:
  - `remove_action( 'acrossai_mcp_connector_user_approval_revoked', [ ConnectorAdminController::class, 'cascade_revoke_tokens_on_approval_revoked' ], 10 )` to disable the default cascade entirely.
  - `add_action( ..., 'my_listener', 20, 4 )` to layer additional side effects without disabling the default.

**Example — audit-log listener**:

```php
add_action( 'acrossai_mcp_connector_user_approval_revoked', function ( int $server_id, string $slug, int $user_id, int $revoked_by ) {
    error_log( sprintf(
        '[acrossai-mcp] approval revoked: server=%d connector=%s user=%d by=%d',
        $server_id, $slug, $user_id, $revoked_by
    ) );
}, 20, 4 );
```

---

## Filter — `acrossai_mcp_connector_revoke_tokens_on_approval_revoked`

**Purpose**: Opt-out gate for the default token-revoke cascade. Third-party plugins that want the pre-F032 decoupled behavior (approval = future eligibility, tokens = current session — independent) can return `false` to preserve it without unhooking the default listener entirely.

**Fires**: Inside `ConnectorAdminController::cascade_revoke_tokens_on_approval_revoked` as the FIRST guard — before profile lookup, before client enumeration, before any DB read.

**Signature**:

```php
apply_filters(
    'acrossai_mcp_connector_revoke_tokens_on_approval_revoked',
    bool   $should_revoke,   // Default true
    int    $server_id,
    string $connector_slug,
    int    $user_id,
    int    $revoked_by
): bool
```

**Contract**:

- Default `true` — cascade fires unless a listener explicitly returns `false`.
- On `false`: cascade returns immediately; the approval-revoke DB row is still deleted, the `acrossai_mcp_connector_user_approval_revoked` action still fires (this filter guards the CASCADE, not the ACTION), but no tokens are touched.
- Listeners MUST return a strict bool. Non-bool return values are coerced via `(bool)`.
- Listeners that return `false` MUST document their reason in the callback body — the cascade opt-out has security implications (revoked users remain connected until token expiry).

**Example — preserve pre-F032 decoupled behavior**:

```php
add_filter( 'acrossai_mcp_connector_revoke_tokens_on_approval_revoked', '__return_false' );
```

---

## Action — `acrossai_mcp_connector_admin_self_bypassed`

**Purpose**: Fired once per admin bypass of the `require_admin_approval` pending queue (FR-051). Enables forensic separation of self-service bypass rows from explicit-reviewer approval rows in `wp_acrossai_mcp_connector_approved_users` — without this action, both cases produce indistinguishable `(user_id, approved_by)` tuples when `approved_by === user_id`. Codified per SEC-L1 remediation + B38 durable pattern.

**Fires**: Inside `AuthorizationController::handle_get()` immediately after `ConnectorApprovedUsersQuery::approve( $server_id, $slug, $user_id, $user_id )` completes on the admin-bypass branch. Fires ONLY when the user has `manage_options` capability AND `require_admin_approval = true` AND the user was NOT previously in the approved list. Does NOT fire when the same admin re-authorizes (already-approved short-circuit prevents re-entry to the bypass branch).

**Signature**:

```php
do_action(
    'acrossai_mcp_connector_admin_self_bypassed',
    int    $server_id,       // MCP server row id
    string $connector_slug,  // Connector profile slug
    int    $user_id,         // Admin user_id (also = approved_by in the new DB row)
    int    $timestamp        // UNIX timestamp at fire site (time())
): void
```

**Contract**:

- Args are cast to `(int)` / `(string)` at the fire site.
- Fires BEFORE the consent screen renders — listeners may not assume the authorize flow completed.
- Fires ONCE per admin's first-time bypass; subsequent authorize requests from the same admin do NOT re-fire (the `is_user_approved` gate short-circuits before this branch).
- No opt-out filter — this is pure observability, not enforcement.
- Third-party plugins MAY `add_action()` for audit-log side effects. Recommended: audit log entries SHOULD tag rows fired by this action distinctly from `acrossai_mcp_connector_user_approval_revoked` reversals so operators can trace the full lifecycle.

**Distinguishing from explicit approvals**:

The pre-existing `acrossai_mcp_connector_user_approval_approved` action (if a listener exists for the REST `/oauth/approve-pending-consent` handler) fires when Admin Alice approves Admin Bob's pending request. `acrossai_mcp_connector_admin_self_bypassed` fires when Admin Bob self-bypasses on first connection. Both produce a row in `wp_acrossai_mcp_connector_approved_users`, but only the latter fires this action.

**Example — audit-log differentiation**:

```php
add_action( 'acrossai_mcp_connector_admin_self_bypassed', function ( int $server_id, string $slug, int $user_id, int $ts ) {
    error_log( sprintf(
        '[acrossai-mcp AUDIT] admin self-bypass: user=%d server=%d connector=%s (row will show approved_by=%d — SELF)',
        $user_id, $server_id, $slug, $user_id
    ) );
}, 10, 4 );
```

---

## Action — `acrossai_mcp_oauth_client_revoked_across_all_servers`

**Purpose**: Fired once per admin click on the "Revoke from all servers" nuclear button in the Connections panel. Aggregate observability for site-wide operator response to compromised `client_id`s.

**Fires**: Inside `ConnectorAdminController::handle_revoke_client_tokens_all_servers` after `TokensQuery::revoke_by_client_id_across_all_servers()` completes, including when the count is 0 (idempotent no-op still fires).

**Signature**:

```php
do_action(
    'acrossai_mcp_oauth_client_revoked_across_all_servers',
    string $client_id,             // The client_id revoked globally
    int    $revoked_token_count,   // Total tokens revoked across all servers
    int    $user_id,               // Admin performing the action
    int    $timestamp              // UNIX timestamp at fire site (time())
): void
```

**CRITICAL invariant (D34)**: This action is mutually exclusive with `acrossai_mcp_oauth_cross_server_attempted`. The two actions MUST NEVER co-fire for the same admin action.

- `acrossai_mcp_oauth_cross_server_attempted` (FR-023) fires ONLY on bypass ATTEMPTS — a caller submitted a `server_id` that mismatched the client's owning server.
- `acrossai_mcp_oauth_client_revoked_across_all_servers` (FR-043) fires ONLY on legitimate operator-invoked cross-server operations.

Conflating the two would poison forensic streams — false-positive "attempted bypass" records that were actually legitimate operator actions. The docblock on `handle_revoke_client_tokens_all_servers` cites this invariant explicitly.

**Example — Slack webhook for site-wide revokes**:

```php
add_action( 'acrossai_mcp_oauth_client_revoked_across_all_servers', function ( string $client_id, int $count, int $admin_id, int $ts ) {
    if ( $count === 0 ) {
        return; // No-op revoke; skip notification.
    }
    wp_remote_post( SLACK_WEBHOOK_URL, array(
        'body' => wp_json_encode( array(
            'text' => sprintf( 'Site-wide revoke: client_id=%s tokens=%d by user #%d', $client_id, $count, $admin_id ),
        ) ),
    ) );
}, 10, 4 );
```

---

## Hook-argument extension — `acrossai_mcp_access_control_denied`

**Origin**: F015 introduced this action for the `mcp_adapter_pre_tool_call` deny path. F032 EXTENDS the `$context` argument enum with three new connection-time values.

**Existing signature (unchanged)**:

```php
do_action(
    'acrossai_mcp_access_control_denied',
    int         $user_id,
    string      $server_slug,
    string|null $tool_name,   // null when the deny happens BEFORE tool selection
    string      $context      // enum — see extension below
): void
```

**F032 `$context` enum extension**:

| Value | Fire site | Introduced |
|-------|-----------|------------|
| `'tool_call'` | `AcrossAI_MCP_Access_Control::gate_mcp_tool_call` (F015 tool-call gate) | F015 |
| `'oauth_authorize'` | `AuthorizationController::handle_get` before consent render | **F032 FR-049** |
| `'cli_device_grant'` | `FrontendAuth::render_consent` before Approve/Deny render | **F032 FR-049** |
| `'app_password_generate'` | `ClientRendererController::handle_generate_app_password` before password issuance | **F032 FR-049** |

**Contract clarifications for the new contexts**:

- On `context = 'oauth_authorize' / 'cli_device_grant' / 'app_password_generate'`, `$tool_name` is always `null` (there is no tool selected at connection-issuance time).
- On `context = 'oauth_authorize'`, the deny is IMMEDIATELY followed by `self::redirect_error( $params['redirect_uri'], 'access_denied', ... )` — listeners MUST NOT expect further processing on the request thread.
- Fail-open guarantee (D19): if `AcrossAI_MCP_Access_Control::user_has_server_access()` returns `true` (missing AC package / manager null / server row missing), this action does NOT fire.

---

## Hook-argument extension — `acrossai_mcp_manager_oauth_token_revoked`

**Origin**: F021 introduced this action for every per-token transition to `revoked = 1`. F032 EXTENDS the `$reason` argument enum with two new stable values.

**Existing signature (unchanged)**:

```php
do_action(
    'acrossai_mcp_manager_oauth_token_revoked',
    int    $token_id,
    string $reason   // enum — see extension below
): void
```

**F032 `$reason` enum extension**:

| Value | Fire site | Introduced |
|-------|-----------|------------|
| `'user_deleted'` | `UserLifecycle::on_user_deleted` (WP user-delete cascade) | F021 |
| `'admin_revoke'` | `ConnectorAdminController::handle_revoke_client_tokens` per-server revoke | F021 |
| `'delete_client'` | `ConnectorAdminController::handle_delete_client` cascade | F021 |
| `'admin_nuclear_revoke'` | `mass_revoke_connector_tokens` (nuclear per-connector) | F021 |
| `'connector_disabled'` | `mass_revoke_connector_tokens` (auto-fire on connector disable) | F024 |
| `'oauth_cleanup'` | Daily cron | F021 |
| `'approval_revoked'` | `cascade_revoke_tokens_on_approval_revoked` (FR-040 default cascade) | **F032** |
| `'admin_revoke_all_servers'` | `handle_revoke_client_tokens_all_servers` (FR-043 nuclear cross-server) | **F032** |

Downstream loggers can differentiate F032 cascade paths from F021/F024 paths via the `$reason` enum — MUST use exact string match, not `strpos` (the enum is stable and case-sensitive).

---

## Non-hook contracts referenced

F032 also introduces two OAuth-error observability actions worth flagging (already covered in `spec.md` §Security Checklist but not repeated here as full contracts — 4-arg signatures, fire-and-forget):

- `acrossai_mcp_oauth_cross_server_attempted` (FR-023) — per-attempt bypass observability. 4-arg: `$client_id, $server_id_requested, $user_id, $timestamp`. Owning server_id INTENTIONALLY OMITTED per SEC-032-001.
- `acrossai_mcp_oauth_legacy_dcr_purged` (FR-024) — aggregate one-time-per-upgrade observability. 3-arg: `$clients_deleted, $tokens_deleted, $auth_codes_deleted`.
- `acrossai_mcp_oauth_dcr_resource_url_origin_mismatch` (FR-027) — DCR origin-verification deny observability. 3-arg: `$resource, $user_id, $timestamp`.

See `spec.md` FR-023 / FR-024 / FR-027 for the full signatures + fire semantics.

---

## Backward-compatibility invariants

- The `acrossai_mcp_access_control_denied` and `acrossai_mcp_manager_oauth_token_revoked` enum extensions are ADDITIVE ONLY. Existing enum values MUST NOT be renamed or removed. Listeners that switch on the enum with a whitelist (safe) work correctly; listeners that assume the enum is exhaustive (unsafe) may miss new values but never crash.
- All F032-new hook names use the `acrossai_mcp_` prefix per plugin convention. Names MUST NOT change without a major version bump.
- The default cascade listener signature is 4-arg. Third-party listeners MAY declare fewer args (PHP will fill unused positions), but the ACTION invocation MUST always pass 4.

---

## Governance

Any PR touching these hooks MUST:

1. Update this contract file if adding a new hook OR extending an existing enum.
2. Add a corresponding entry to `docs/memory/DECISIONS.md` if the hook introduces a new architectural pattern (e.g., D32 for the cascade filter shape, D34 for the mutual-exclusion invariant).
3. Preserve the 4-arg observability signature standard established by D31/D34.
