# Contract — Authorization Endpoint (Consent Page)

**Date**: 2026-06-18 | **RFC**: 6749 §4.1.1 + §4.1.2 + §4.1.2.1 | **FR**: FR-004 through FR-010

## Endpoint

| Property | Value |
|---|---|
| URL | `https://{site_url}/acrossai-mcp-oauth/` |
| Method (initial) | `GET` (the consent page render) |
| Method (form submit) | `POST` (Approve / Deny) |
| Auth | WordPress session — user MUST be logged in AND have `manage_options` (per Application Passwords boundary) |

## GET request — params

| Param | Required | Validation |
|---|---|---|
| `response_type` | yes | MUST equal `'code'` (no implicit / hybrid) |
| `client_id` | yes | MUST resolve to an MCP server row's `claude_connector_client_id` |
| `redirect_uri` | yes | MUST exactly equal the resolved server's `claude_connector_redirect_uri` (byte-for-byte) |
| `scope` | yes | MUST equal `'mcp'` |
| `state` | optional | Echoed back unmodified on the redirect |
| `code_challenge` | yes | 43 chars base64url |
| `code_challenge_method` | yes | MUST equal `'S256'` |

### Validation flow (FR-004 → FR-007)

```
1. Param presence + shape check
   ↓ fail → HTTP 400 + error page (NO redirect — RFC 6749 §4.1.2.1)
2. Resolve client_id → server row
   ↓ fail → audit log `failed_unknown_client` + HTTP 400 + error page
3. redirect_uri byte-match check
   ↓ fail → audit log `failed_redirect_mismatch` + HTTP 400 + error page
4. User logged in?
   ↓ no → 302 redirect to wp-login.php?redirect_to=<this URL>
5. User has manage_options?
   ↓ no → HTTP 403 + denial page (NO redirect — admin boundary)
6. Render consent page (FR-008)
```

## Consent page (FR-008)

HTML form rendered via `Includes\OAuth\ClaudeConnectors::render_authorize_page`.

| Field rendered | Source | Escaping |
|---|---|---|
| Server display name | `MCPServer\Query::query(id=...)` → `server_name` | `esc_html()` |
| Requesting client_id | URL param | `esc_html()` |
| Requested scope | URL param (fixed `mcp` for this phase) | `esc_html()` |
| **Approve** button | `<button name="oauth_decision" value="approve">` | static |
| **Deny** button | `<button name="oauth_decision" value="deny">` | static |
| Nonce field | `wp_nonce_field('acrossai_mcp_oauth_consent_<server_id>')` | core-managed |
| Original query params | Hidden inputs `<input type="hidden" name="…">` | `esc_attr()` per value |

## POST request — submission

Browser POSTs `oauth_decision=approve` (or `deny`) + nonce + all
original query params re-submitted as hidden fields.

### Approve path (FR-009)

```
1. check_admin_referer('acrossai_mcp_oauth_consent_<server_id>')  // nonce + manage_options recheck
2. Storage::issue_authorization_code(client_id, server_id, user_id,
                                      redirect_uri, code_challenge,
                                      code_challenge_method, scope)
   → returns raw $code; persists hash + metadata
3. Audit log `code_issued`
4. wp_safe_redirect(esc_url_raw(add_query_arg(['code'=>$raw, 'state'=>$state], $redirect_uri)))
5. exit
```

### Deny path (FR-010)

```
1. check_admin_referer(...)
2. Audit log `consent_denied`
3. wp_safe_redirect(esc_url_raw(add_query_arg(['error'=>'access_denied', 'state'=>$state], $redirect_uri)))
4. exit
```

## Security boundary (S1)

The consent form's `wp_nonce_field` ties the POST to the current user's
session. A CSRF attacker on a different site cannot craft a request
that approves consent without the user-specific nonce — even if the
attacker tricks the user into visiting the URL.

## DataForm exemption (Constitution §IV / A4)

This page is a plain `<form>` (not DataForm) per RFC 6749 §4.1.1
prescribed shape. Three justifications:
1. Not an admin menu page (no DataViews routing applies)
2. Two-button form (DataForm adds ceremony for zero functional gain)
3. RFC-prescribed UX that OAuth users expect (matches every other
   OAuth consent screen)

**A13 capture queued** for post-implementation memory.
