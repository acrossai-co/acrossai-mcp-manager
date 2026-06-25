# Implementation Plan: OAuth / Claude Connectors Integration

**Branch**: `005-oauth-connectors` | **Date**: 2026-06-18 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/005-oauth-connectors/spec.md`

---

## Summary

Build a fully RFC-conformant OAuth 2.0 Authorization Code flow with PKCE
under `includes/OAuth/` so external OAuth clients (notably Claude
Connectors) can authenticate against the WordPress site and obtain
access tokens to call MCP server endpoints.

The implementation is **greenfield** (not a port — the source repo
contains earlier OAuth scaffolding but the new contract is spec-driven
with explicit Q1/Q2/Q3 clarifications layered on top). It deliberately
**hand-rolls the OAuth state machine** — no external OAuth library —
following the same project pattern that hand-rolled the BerlinDB-style
Query layer (D9). RFC interop is verified by per-RFC-section PHPUnit
tests with golden response fixtures.

**Six OAuth feature classes** in `includes/OAuth/`:
- `ClaudeConnectors` — orchestrator: rewrite registration, discovery
  serving, authorize page rendering, consent form dispatch
- `TokenController` — REST controller for `/wp-json/acrossai-mcp/v1/token`
- `Storage` — persistence facade: auth code issuance/lookup/redeem +
  access token issuance/lookup/revoke + rate-limit counter ops
- `PKCE` — pure utility (A11 candidate): `base64url(sha256(verifier))`
  math, no state, no hooks
- `BearerAuth` — `determine_current_user` filter callback
- `AuditLog` — append-only writer for `acrossai_mcp_oauth_audit` table

**Three new BerlinDB Query layers** in `includes/Database/` (per D9):
- `OAuthToken/{Schema,Table,Row,Query}.php`
- `OAuthAudit/{Schema,Table,Row,Query}.php`
- `CliAuthLog/{Schema,Table}.php` — extended with 4 OAuth columns (per
  Q1: codes live in CliAuthLog; the OAuthAudit table is separate)

**Cron** for cleanup (Q2): one recurring event registered in
`Activator::activate()`, hook handler on `acrossai_mcp_oauth_cleanup`.

**Two memory-flagged soft conflicts** (memory-synthesis §Conflict
Warnings) — both documented in spec, both RFC-mandated, both passed
through to A11-style post-implementation memory captures:
- **A13 candidate**: RFC-prescribed forms exempted from DataForm (A4)
- **S7 candidate**: Token endpoint `__return_true` exempted from S2
  because RFC 6749 §2.3.1 in-body auth IS the authentication

## Technical Context

| Field | Value |
|---|---|
| Language / version | PHP 8.0+ (constitution target); JS only for the small inline consent-form script if any |
| Primary dependencies | `automattic/jetpack-autoloader ^5.0`; **no OAuth library** — hand-rolled per D9 pattern |
| Storage | 3 BerlinDB-Query-fronted tables (1 extended: `acrossai_mcp_cli_auth_logs`; 2 new: `acrossai_mcp_oauth_tokens`, `acrossai_mcp_oauth_audit`); WordPress transients for rate-limit counters (FR-014a) |
| Testing | PHPUnit with **WP-PHPUnit** (the OAuth flow uses `wp_set_current_user`, nonces, `wp_safe_redirect`, sessions — testing requires a WP bootstrap, unlike Phase 4 MCPClients which were pure). RFC-conformance fixtures per RFC section. |
| Target platform | WordPress 6.9+ admin + public; single-site only |
| Project type | WordPress plugin module — `Includes\OAuth\*` |
| Performance goals | Token endpoint responds in ≤200 ms p95; discovery endpoints cacheable (Cache-Control: public, max-age=86400) |
| Constraints | RFC 6749 + RFC 7636 + RFC 8414 + RFC 9728 conformance; FR-008 zero hooks in OAuth class constructors; FR-009 no singleton on pure utility (A11 carve-out applies to PKCE) |
| Scale / scope | 6 feature classes + 12 Database files (3 Query layers × 4 files each) + 4 extension files (Activator, Main, CliAuthLog Schema/Table edits) + tests + golden fixtures |

### Hard prerequisites (P0 dependencies)

Three classes:

1. **Phase 2 BerlinDB Query infrastructure** — `MCPServer\Query` and
   `CliAuthLog\Query` MUST exist. **Status**: merged to `feature/issue-3`
   in PR #5 (`cc536f7`). ✅ Available.

2. **Phase 2 MCPServer `claude_connector_*` columns** — `claude_connector_client_id`,
   `claude_connector_client_secret`, `claude_connector_redirect_uri`.
   **Status**: merged to `feature/issue-3` in PR #5. ✅ Available.

3. **WP-PHPUnit test harness** — different from Phase 4.0's no-WP
   bootstrap. OAuth tests need `wp_set_current_user`, nonces, sessions,
   `wp_safe_redirect`. Phase 4 shipped a **WP-free** bootstrap at
   `tests/bootstrap.php`. **Status**: NOT yet available. **Per D11
   (Phase X.0 absorption pattern)** this phase WILL absorb a Phase 5.0
   setup task to add `tests/bootstrap-wp.php` + a second testsuite in
   `phpunit.xml.dist` named `"oauth"` that uses the WP-PHPUnit bootstrap.
   See `/speckit-tasks` T000-T007 in the future tasks.md.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Compliance | Notes |
|---|---|---|
| I. Modular Architecture | ✅ | 6 OAuth classes + 3 Database modules; sibling-decoupled |
| II. WordPress Standards | ✅ | PHPCS WPCS strict + PHPStan L8 mandated; baseline (D5) preserved |
| III. Security First | ✅ | Hashed storage (FR-020 satisfies §III bullet 7); nonces on consent form (S1); prepared statements via BerlinDB; OAuth tokens NEVER stored plaintext |
| IV. User-Centric Design (DataForm/DataViews) | ⚠ **Documented A4 soft exemption** | Consent page is a plain `<form>` per RFC 6749 §4.1.1. Spec §Admin UI Requirements documents the 3-reason exemption (not a menu page, 2-field form, RFC-prescribed shape). **A13 capture queued** for post-implementation. |
| V. Extensibility Without Core Modification | ✅ | All hooks via Loader; `class_exists()` guards on the MCP adapter `\WP\MCP\Plugin` for the Bearer-resolved endpoints |
| VI. Reusability & DRY | ✅ | Shared `Storage` facade for code+token ops; PKCE math centralized in `Includes\OAuth\PKCE` |
| VII. Definition of Done | ✅ | DoD gates listed in spec + this plan; PHPUnit harness P0 absorbed as Phase 5.0 |
| A1 — Hooks via Loader | ✅ | FR-021 + FR-022 enforced |
| A2 — Singleton pattern | ✅ | All OAuth feature classes singleton; **PKCE exempted per A11** (pure stateless utility) |
| A6 — `use` imports in `Includes\*` | ✅ | Required for OAuth + Database modules (B1 silent-fail risk) |
| **S2** — REST routes have permission_callback; never `__return_true` on mutating routes | ⚠ **Documented exemption** | FR-011 token endpoint uses `__return_true` because RFC 6749 §2.3.1 authenticates via POST body (`client_id`+`client_secret`+`code`). Spec FR-011 documents the rationale verbatim. **S7 capture queued** for post-implementation. |
| **B4** — Unescaped dot in PCRE rewrite rule | ✅ Mitigated | Plan §Phase 0 R1 specifies `'^\.well-known/oauth-authorization-server$'` and the resource variant verbatim |

**Result**: All gates pass with two documented soft exemptions
(A4-consent-page, S2-token-endpoint). Both are RFC-mandated.
Complexity Tracking section captures them.

## Project Structure

### Documentation (this feature)

```text
specs/005-oauth-connectors/
├── plan.md                      # THIS FILE
├── spec.md                      # 24 base FRs + FR-014a/019a/019c clarifications
├── research.md                  # Phase 0 — RFC mapping + B4 escape + entropy + transient keys
├── data-model.md                # Phase 1 — 3 tables + transient rate-limit shape
├── contracts/                   # Phase 1 — JSON envelopes per RFC section
│   ├── discovery-as-metadata.md       # RFC 8414 `/.well-known/oauth-authorization-server`
│   ├── discovery-resource-metadata.md # RFC 9728 `/.well-known/oauth-protected-resource`
│   ├── authorize-page.md              # consent flow (HTML form, not REST)
│   ├── token-endpoint.md              # RFC 6749 §4.1.3 + §5.1 + §5.2 (all error paths)
│   └── bearer-auth-filter.md          # determine_current_user contract
├── quickstart.md                # Phase 1 — full RFC-conformant manual walk
├── memory-synthesis.md          # already produced
├── checklists/requirements.md   # quality checklist
└── tasks.md                     # /speckit-tasks output (not yet created)
```

### Source Code (repository root)

```text
includes/
├── OAuth/                                  # NEW MODULE
│   ├── ClaudeConnectors.php                # NEW — rewrite reg + discovery serve + authorize render + consent dispatch
│   ├── TokenController.php                 # NEW — POST /wp-json/acrossai-mcp/v1/token (FR-011, FR-012, FR-013, FR-014, FR-014a)
│   ├── Storage.php                         # NEW — persistence facade across CliAuthLog + OAuthToken + OAuthAudit + transient rate-limit
│   ├── PKCE.php                            # NEW — pure utility (A11 exemption): base64url(sha256(verifier)) math + S256 validation
│   ├── BearerAuth.php                      # NEW — determine_current_user filter (FR-015)
│   └── AuditLog.php                        # NEW — append-only writer (FR-019a event_type taxonomy)
├── Database/
│   ├── OAuthToken/                         # NEW — BerlinDB Query layer per D9
│   │   ├── Schema.php
│   │   ├── Table.php
│   │   ├── Row.php
│   │   └── Query.php
│   ├── OAuthAudit/                         # NEW — BerlinDB Query layer per D9
│   │   ├── Schema.php
│   │   ├── Table.php
│   │   ├── Row.php
│   │   └── Query.php
│   └── CliAuthLog/                         # EXTEND — add 4 OAuth columns
│       ├── Schema.php                      # EDIT — add redirect_uri, code_challenge, code_challenge_method, scope
│       └── Table.php                       # EDIT — bump DB_VERSION + add ALTER columns to dbDelta SQL
└── Main.php                                # EXTEND — define_admin_hooks + define_public_hooks wire OAuth hooks

includes/Activator.php                       # EXTEND — register OAuth rewrite rules, maybe_create_table for 2 new tables, wp_schedule_event for cron
includes/Deactivator.php                     # EXTEND — wp_clear_scheduled_hook for cron event

tests/
├── bootstrap-wp.php                         # NEW (Phase 5.0) — loads wp-phpunit; mirrors WP-PHPUnit conventions
├── phpunit.xml.dist                         # EDIT — add second testsuite "oauth" using bootstrap-wp.php
└── phpunit/
    └── OAuth/                               # NEW
        ├── PKCETest.php                     # WP-free (PKCE is pure)
        ├── ClaudeConnectorsTest.php         # WP — discovery + authorize render
        ├── TokenControllerTest.php          # WP — RFC §4.1.3 + §5.2 per-error-path coverage
        ├── BearerAuthTest.php               # WP — determine_current_user
        ├── StorageTest.php                  # WP — persistence + rate-limit transient
        ├── AuditLogTest.php                 # WP — append-only writer
        └── fixtures/
            ├── discovery-as.json            # RFC 8414 golden response
            ├── discovery-rs.json            # RFC 9728 golden response
            ├── token-success.json           # RFC 6749 §5.1 success envelope
            ├── token-error-invalid_request.json
            ├── token-error-invalid_client.json
            ├── token-error-invalid_grant-expired.json
            ├── token-error-invalid_grant-redeemed.json
            ├── token-error-invalid_grant-pkce.json
            └── token-error-slow_down.json   # FR-014a rate-limit response
```

**Structure Decision**: Standard WordPress-plugin layout. The OAuth
module is the most modular feature so far — 6 single-responsibility
classes, each with one clear boundary. The DB Query layers follow the
exact 4-file pattern Phase 2.0 established.

## Phase 0 — Outline & Research

Five research outputs in `research.md`:

### R1 — Rewrite rule escape (B4-mitigation, MANDATORY)

The two `.well-known` URLs are registered via `add_rewrite_rule()`. The
PCRE pattern MUST escape the leading dot:

```php
// CORRECT (B4 mitigation):
add_rewrite_rule( '^\.well-known/oauth-authorization-server/?$',
                  'index.php?acrossai_mcp_oauth=as_metadata',
                  'top' );
add_rewrite_rule( '^\.well-known/oauth-protected-resource/?$',
                  'index.php?acrossai_mcp_oauth=rs_metadata',
                  'top' );
```

Without the backslash, `.` matches ANY character — `/xwell-known/...`
would route to the discovery handler, breaking the URL space and
giving attackers a probe vector. The unit test `R1_assertRewriteRuleEscape`
asserts the registered regex string contains `\.well-known` verbatim.

### R2 — Cryptographic-random code + token generation

Both raw auth codes and raw access tokens are 32 bytes from
`random_bytes(32)` (CSPRNG; throws on failure under PHP 8 — caller MUST
catch and return HTTP 503), base64url-encoded. The 43-char base64url
output (32 bytes = 43 chars unpadded) is the same length as a PKCE
`code_challenge`, which is a useful structural symmetry for human
operators reading logs.

**Hash storage**: `hash('sha256', $raw, false)` → lowercase 64-char hex.
SQL column is `CHAR(64)` with a UNIQUE index. `hash_equals()` is the
constant-time comparison primitive everywhere a secret is compared.

### R3 — PKCE S256 validation (RFC 7636 §4.6 + §4.2)

```php
$expected_challenge = strtr(
    rtrim( base64_encode( hash( 'sha256', $code_verifier, true ) ), '=' ),
    '+/', '-_'
);
if ( ! hash_equals( $stored_code_challenge, $expected_challenge ) ) {
    // FR-012 step 7 → HTTP 400 invalid_grant
}
```

`hash_equals` not `===` (timing-channel defense). `code_verifier` MUST
be 43-128 chars per RFC 7636 §4.1; validate at intake. `code_challenge_method`
MUST be exactly `S256` — `plain` rejected at authorize-time per spec
Assumption (PKCE S256 only).

### R4 — Rate-limit transient key derivation (FR-014a)

Transient key:

```php
$key = 'oauth_rate_' . hash(
    'sha256',
    $client_id . '|' . $request_ip . '|' . gmdate( 'Y-m-d-H' ) // hour bucket
);
```

The hour-bucket prefix gives natural 1-hour rotation without explicit
transient cleanup. The 1-minute threshold uses a separate transient
with a 60-second TTL keyed on minute-bucket. Both transients are
write-then-read with `wp_cache_*` short-circuit so a working
persistent-object-cache (Redis/Memcached) handles spikes without
hitting the options table.

Request IP determination MUST honor the standard WordPress trust
order: `REMOTE_ADDR` only — `X-Forwarded-For` is NOT trusted by default
because the plugin doesn't know the operator's reverse-proxy
configuration. Operators behind a proxy MUST configure WordPress's
`$_SERVER['REMOTE_ADDR']` correctly via their proxy headers
(documented in admin notice).

### R5 — Audit log event-type enum (FR-019a, Q1)

Canonical list (frozen for this phase; new event types require a spec
amendment):

| event_type | Trigger | Severity |
|---|---|---|
| `code_issued` | FR-009 successful consent + code generation | info |
| `code_redeemed` | FR-013 successful token exchange | info |
| `consent_denied` | FR-010 Deny button | info |
| `failed_unknown_client` | FR-005 unknown client_id at authorize | warn |
| `failed_redirect_mismatch` | FR-005 redirect_uri mismatch at authorize | warn |
| `failed_replay_attempt` | FR-014 second-redemption of an already-used code | **critical** (revokes all tokens) |
| `failed_rate_limit` | FR-014a threshold A or B crossed | warn |
| `failed_cross_server_token` | FR-015 Bearer token for wrong server | warn |
| `bearer_auth_success` | FR-016 every successful Bearer-auth MCP call | info |
| `token_revoked` | FR-014 anti-replay revocation | info |
| `cleanup_run` | FR-019c daily sweep (records rows deleted per class) | info |

## Phase 1 — Design & Contracts

### data-model.md

Three entity tables + one in-memory transient shape:

1. **Authorization Code** — extends `acrossai_mcp_cli_auth_logs` with 4
   new columns (redirect_uri, code_challenge, code_challenge_method,
   scope). State machine: `issued → redeemed | expired | replayed`.
2. **Access Token** — new table; state machine: `issued → expired |
   revoked`.
3. **Audit Event** — new table; immutable once written.
4. **Rate-limit Counter (transient)** — `oauth_rate_<sha256>` keyed
   per (client_id, IP, hour-bucket); TTL 1h.

State-transition diagram included in data-model.md for each entity.

### contracts/

Five contract documents (one per network boundary):

- `discovery-as-metadata.md` — RFC 8414 §3 JSON keys with golden
  fixture pinning the exact shape
- `discovery-resource-metadata.md` — RFC 9728 JSON keys
- `authorize-page.md` — HTTP request → consent HTML → POST → 302 (the
  one contract that crosses HTML rather than JSON)
- `token-endpoint.md` — RFC 6749 §4.1.3 + §5.1 success envelope +
  §5.2 error envelope; one row per error path documenting which FR-012
  validation step it covers
- `bearer-auth-filter.md` — the `determine_current_user` contract:
  inputs (request URL + Authorization header) → outputs (`user_id` or
  unchanged default); never throws; never short-circuits other auth
  methods

### quickstart.md

A full RFC-conformant manual flow walk using `curl` and a one-off
test OAuth client (PHP CLI script that mimics Claude's expected
behavior). Confirms SC-001 through SC-007 in order.

### Agent context update

Update `.github/copilot-instructions.md` between the `<!-- SPECKIT
START -->` markers to point at `specs/005-oauth-connectors/plan.md`.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| **A4 DataForm exemption** for consent page | RFC 6749 §4.1.1 prescribes a specific consent-page shape; DataForm doesn't model it cleanly | DataForm wrap rejected — would not match RFC-prescribed UX users + OAuth implementations expect |
| **S2 `__return_true` exemption** for token endpoint | RFC 6749 §2.3.1 specifies that client_id + client_secret in POST body IS the authentication; session/header auth would VIOLATE the RFC | Custom permission_callback rejected — would force routing the body parse into a permission_callback context, splitting validation logic across two callbacks |

Both exemptions are explicit in the Constitution Check above and in
the spec's §Admin UI Requirements / FR-011. Neither violates a
constitutional MUST — A4 has the documented carve-out pattern (A10,
A11), and S2's "never `__return_true` on mutating routes" rule has
RFC-mandated exceptions per OAuth's auth-via-body design.
