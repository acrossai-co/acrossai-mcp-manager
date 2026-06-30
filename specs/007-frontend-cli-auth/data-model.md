# Phase 1 Data Model — Frontend CLI Authentication Page

**Date**: 2026-06-25
**Scope**: documents every value this module reads or writes and the cross-class flow into `CliController`.

This module **owns no database tables, no custom options, no new transients, no new REST routes**. Its data surface is:

1. Two class constants (read-only at class scope).
2. One query var registered with WordPress (`acrossai_mcp_auth`).
3. One `wp_options` row READ (`acrossai_mcp_npm_login_enabled`).
4. GET parameters parsed off the request (`action`, `code`, `server`, `_wpnonce`).
5. One indirect transient write via `CliController::approve_auth_code()`.

---

## 1. Class constants

| Constant | Type | Value | Purpose |
|---|---|---|---|
| `PAGE_SLUG` | `string` | `'acrossai-mcp-manager'` | URL segment for the virtual page; used in rewrite rule + `get_base_url()` |
| `QUERY_VAR` | `string` | `'acrossai_mcp_auth'` | WordPress query var name; flag that `template_redirect` dispatches on |

Both `const` (not `define`-style), declared at class level. No instance properties. (FR-001)

---

## 2. WordPress query var

| Name | Registered by | Read by | Lifetime |
|---|---|---|---|
| `acrossai_mcp_auth` | `FrontendAuth::add_query_var()` filter on `query_vars` | `FrontendAuth::maybe_render_page()` via `get_query_var()` | per-request |

Set by the rewrite rule `^acrossai-mcp-manager/?$ → index.php?acrossai_mcp_auth=1`. Value is always the literal string `'1'` when present, empty/false otherwise. (FR-003, FR-004)

---

## 3. WordPress option (READ)

| Option | Type | Default | Owner | Read by |
|---|---|---|---|---|
| `acrossai_mcp_npm_login_enabled` | `bool` (stored as `'0'`/`'1'`) | `false` | (operator-set, no admin UI in this phase — see below) | `FrontendAuth::maybe_render_page()` |

**Default `false` = kill switch is OFF until operator explicitly enables it.** Read via `get_option( 'acrossai_mcp_npm_login_enabled', false )`. Cast to `(bool)` before comparison.

**No admin UI in this phase.** Operators set the option via WP-CLI:

```sh
wp option update acrossai_mcp_npm_login_enabled 1
```

A future admin-side toggle is out of scope for this phase. (planning-time decision; see plan.md §Complexity Tracking)

---

## 4. GET parameters

All consumed by `maybe_render_page()` and downstream handlers. All MUST pass through `wp_unslash( $_GET[ ... ] ?? '' )` followed by `sanitize_text_field( ... )` in that order. (FR-009, FR-010)

| Param | Type | Source | Used by | Validation |
|---|---|---|---|---|
| `action` | `string` | CLI's `auth_url` or internal redirect | `maybe_render_page()` switch | `sanitize_text_field`; allow-listed to one of `cli_auth`, `cli_auth_approve`, `cli_auth_approved`; unknown values fall through to `cli_auth` default |
| `code` | `string` (32 hex chars) | CLI's `auth_url` | `handle_cli_auth()`, `handle_approve()` | `sanitize_text_field`; empty string triggers "missing parameters" path |
| `server` | `string` (slug) | CLI's `auth_url` | **(2026-06-30 amendment per SEC-001)**: informational only — ignored at dispatch; the displayed value is sourced from the transient via `CliController::peek_pending_server( $code )`. Still sanitized as defense-in-depth, but never rendered or passed downstream. Retained on the URL only for backward compatibility with existing CLI clients that compose `auth_url` with `&server=…`. |
| `_wpnonce` | `string` (10-char hash) | Approve button (minted by `wp_create_nonce`) | `handle_approve()` | `sanitize_text_field`; verified via `wp_verify_nonce( $val, 'cli_auth_approve' )` BEFORE any state mutation (FR-009) |

---

## 5. Indirect transient write (via `CliController::approve_auth_code`)

`FrontendAuth::handle_approve()` calls:

```php
\AcrossAI_MCP_Manager\Includes\REST\CliController::approve_auth_code( $code, get_current_user_id() );
```

That call (per Phase 6 FR-008) reads + mutates two transients owned by `CliController`:

| Transient | Direction | Shape | Owner |
|---|---|---|---|
| `acrossai_cli_auth_<code>` | READ + WRITE (status flip: `pending → approved`) | `array{server_id: string, status: string, user_id: ?int, session_token: ?string, created_at: int}` | `CliController` (this module triggers, does not read) |
| `acrossai_session_<token>` | WRITE (new) | `array{user_id: int, server_id: string}` (per Phase 6 Q4 — bound to consented server_id) | `CliController` (this module triggers, does not read) |

This module **does not directly interact with the transient layer**. The single static call is the entire cross-class data flow.

---

## 6. State transitions in this module

This module has **no internal state machine**. Each request is independent:

```text
Request enters maybe_render_page()
├── query var absent? → return (no output)
├── nocache_headers() emitted
├── not logged in? → wp_redirect( wp_login_url( base_url ) ); exit
├── parse $action, read $enabled
├── $enabled === false? → render_disabled_notice(); exit  (HTTP 503)
└── switch ($action):
    ├── 'cli_auth' / default       → handle_cli_auth( $code, $server )  — renders form, exit
    ├── 'cli_auth_approve'         → verify nonce → handle_approve( $code )
    │                                  ├── nonce bad → wp_die(403)
    │                                  ├── code empty → wp_die(400)
    │                                  ├── approve_auth_code → false → wp_die(400)
    │                                  └── approve_auth_code → true → wp_safe_redirect( ?action=cli_auth_approved ); exit
    └── 'cli_auth_approved'        → handle_approved()  — renders success page, exit
```

(FR-007, FR-008, FR-009, FR-010)

---

## 7. Cross-phase coupling diagram

```text
                 ┌─────────────────────────┐
                 │ CLI client (terminal)   │
                 └────────┬────────────────┘
                          │ HTTP POST /auth/start
                          ▼
       ┌─────────────────────────────────────────┐
       │ Includes\REST\CliController (Phase 6)   │
       │  - auth_start() consumes                │
       │    FrontendAuth::get_base_url()  ◄──┐  │
       │  - approve_auth_code() called by    │  │
       │    FrontendAuth::handle_approve()   │  │
       └────────────┬────────────────────────┼──┘
                    │                        │ static call
                    │ returns auth_url       │
                    ▼                        │
       ┌─────────────────────────────────────┴──┐
       │ Browser (logged-in user)               │
       │  GET /acrossai-mcp-manager/?action=... │
       └────────────┬───────────────────────────┘
                    ▼
       ┌─────────────────────────────────────────┐
       │ Public\Partials\FrontendAuth (PHASE 7)  │
       │  - maybe_render_page() dispatches       │
       │  - handle_approve() calls               │
       │    CliController::approve_auth_code()   │
       └─────────────────────────────────────────┘
```

**Three coupling points** between this module and Phase 6 (*2026-06-30 amendment per SEC-001 — was previously two*):

1. **`FrontendAuth::get_base_url()` consumed by `CliController::auth_start()`** — composes the `auth_url` returned to the CLI in `POST /auth/start`. The contract is one-way: `auth_start()` reads, this module writes.
2. **`CliController::approve_auth_code( $code, $user_id ): bool` consumed by `FrontendAuth::handle_approve()`** — flips the pending auth code to approved. The contract is one-way: `handle_approve()` calls, `approve_auth_code()` returns bool.
3. **`CliController::peek_pending_server( string $code ): ?string` consumed by `FrontendAuth::handle_cli_auth()`** *(NEW 2026-06-30)* — read-only helper that returns the transient's bound `server_id` for pending codes, used to source the displayed slug in the consent UI. Returns `null` for unknown / expired / non-pending codes. The contract is one-way: `handle_cli_auth()` reads, `peek_pending_server()` returns. Pure read — no state mutation, no transient writes, no side effects. See `contracts/cli-controller-peek-pending-server.md`.

No other shared state. No shared services. No bidirectional data flow.
