# Contract: `?action=cli_auth_approve` (state-mutating approval)

**Path**: `/acrossai-mcp-manager/?action=cli_auth_approve&code=<code>&server=<server>&_wpnonce=<nonce>`
**Method**: `GET` (idempotent semantics under WP nonce + downstream pending-check)
**Authentication**: Logged-in user (ANY role); unauthenticated redirected
**Mutating**: YES — flips transient `acrossai_cli_auth_<code>` from `pending → approved`
**Nonce**: REQUIRED — `wp_verify_nonce( $_GET['_wpnonce'], 'cli_auth_approve' )`

## Success response (HTTP 302)

```text
HTTP/1.1 302 Found
Cache-Control: no-cache, must-revalidate, max-age=0
Location: https://example.com/acrossai-mcp-manager/?action=cli_auth_approved
```

The handler MUST `exit` immediately after `wp_safe_redirect()`. No HTML body is emitted on the success branch.

## Side effects on success

1. `CliController::approve_auth_code( $code, get_current_user_id() )` is invoked.
2. Per Phase 6 FR-008, that call flips the transient `acrossai_cli_auth_<code>` status to `approved`, sets `user_id`, generates a fresh `session_token`, and writes a new `acrossai_session_<token>` transient bound to the consented `server_id`.
3. An audit row is written via `CliAuthLog\Recorder::record_approved()` (best-effort).

## Failure: bad/missing nonce (HTTP 403)

```text
HTTP/1.1 403 Forbidden
Cache-Control: no-cache, must-revalidate, max-age=0
Content-Type: text/html; charset=UTF-8

<wp_die output: "Security check failed.">
```

`CliController::approve_auth_code()` is NOT called.

## Failure: missing or empty `code` (HTTP 400)

```text
HTTP/1.1 400 Bad Request
Content-Type: text/html; charset=UTF-8

<wp_die output: "Missing authorization code.">
```

## Failure: `approve_auth_code()` returns `false` (HTTP 400)

Triggered when the code is unknown, expired, or already approved.

```text
HTTP/1.1 400 Bad Request
Content-Type: text/html; charset=UTF-8

<wp_die output: "This authorization code is no longer valid. It may have expired or been used already.">
```

## Test assertions

- A request with a valid nonce + pending code + logged-in user produces HTTP 302 with `Location` containing `action=cli_auth_approved`
- A request with a missing `_wpnonce` produces HTTP 403; `CliController::approve_auth_code()` is verified NOT called (via mock spy)
- A request with a tampered `_wpnonce` produces HTTP 403; same spy check
- A request with `code=''` produces HTTP 400 with the "Missing authorization code" body
- A request where the stub `CliController::approve_auth_code()` returns `false` produces HTTP 400 with the "no longer valid" body
- A request where the stub returns `true` produces HTTP 302; verify `wp_safe_redirect` was called with a URL containing `action=cli_auth_approved`
