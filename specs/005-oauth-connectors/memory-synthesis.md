# Memory Synthesis

## Current Scope

Phase 5 — **OAuth / Claude Connectors integration**. The most security-
dense and structurally-broad feature in the project so far. Spans:
- **Admin context**: consent page render + form handler
- **Public context**: 2 discovery endpoints (`.well-known/*`), authorize
  endpoint (`/acrossai-mcp-oauth/`), token endpoint REST route
- **Data layer**: 3 BerlinDB Query layers — `OAuthToken` (new),
  `OAuthAudit` (new), `CliAuthLog` (extended with 4 OAuth columns)
- **Filter layer**: `determine_current_user` for Bearer auth recognition
- **Cron**: daily cleanup event with WP-CLI fallback

Affected modules: `includes/OAuth/*` (new), `includes/Database/OAuthToken/*`
(new), `includes/Database/OAuthAudit/*` (new), `includes/Database/CliAuthLog/*`
(extension), `includes/Activator.php` (extension), `includes/Main.php`
(extension), `admin/Partials/Settings.php` (already shipped — the
`claude_connector_*` columns this phase consumes).

## Relevant Decisions

- **D5** — PHPCS baseline exceptions preserved. (Reason: every new partial
  hits the `$_instance` prefix + filename casing rules. Source: DECISIONS.md.)
- **D6** — `use` imports for cross-namespace refs in `Includes\*` files.
  (Reason: Activator + Main edits reference 3 new BerlinDB Query classes
  plus the OAuth feature classes. Source: DECISIONS.md.)
- **D9** — BerlinDB-style Query interface hand-rolled. (Reason: this phase
  adds two NEW Query layers — `OAuthToken` and `OAuthAudit` — using the
  same 4-method interface pattern from Phase 2.0. Source: DECISIONS.md.)
- **D11** — Phase X.0 absorption pattern. (Reason: OAuth tests likely need
  a **WP-PHPUnit** harness (sessions, nonces, `wp_set_current_user`) which
  is different from Phase 4.0's no-WP bootstrap. If the WP-PHPUnit harness
  doesn't exist when T004 runs, absorb its setup as Phase 5.0. Source:
  DECISIONS.md.)

## Active Architecture Constraints

- **A1** — Hook registration only in `includes/Main.php`. (Reason: FR-021 +
  FR-022 enforce zero `add_action`/`add_filter` in OAuth classes; every
  OAuth hook wires via Loader. Source: ARCHITECTURE.md.)
- **A2** — Singleton + private ctor for feature classes. (Reason: FR-021
  mandates this exact pattern for OAuth classes — same precedent as Menu,
  Settings, ApplicationPasswords. Source: ARCHITECTURE.md.)
- **A4** — DataForm/DataViews mandated for new admin forms.
  **Soft exemption needed** — the consent page is a standard `<form>` per
  RFC 6749 §4.1.1. Spec §Admin UI Requirements documents the exemption with
  3 rationales (not a menu page, 2-field form, RFC-prescribed shape).
- **A6** — `use` imports / leading-`\` FQN inside `Includes\*` files.
  (Reason: 4+ OAuth classes plus 3 BerlinDB Query classes all live under
  `Includes\OAuth\` and `Includes\Database\OAuth*\`. Bare relative names
  would silently fail per B1. Source: ARCHITECTURE.md.)
- **A11** — Pure service classes exempted from singleton rule.
  (Reason: the planned `Includes\OAuth\PKCE` class (`base64url(sha256(verifier))`
  math, no state, no hooks) qualifies; can use `new PKCE()` per use-site
  instead of the singleton ceremony. Source: ARCHITECTURE.md, 2026-06-18.)

## Relevant Security Constraints

- **S1** — All forms + AJAX endpoints verify a nonce.
  (Reason: the consent page Approve/Deny POST per FR-009 + FR-010 uses
  `wp_nonce_field` + `check_admin_referer`. Source: CONSTITUTION.md §III.)
- **S2** — REST routes have explicit `permission_callback`; `__return_true`
  only on public read routes — **never on mutating routes** (per memory).
  **Tension flagged**: FR-011 sets `permission_callback: __return_true` on
  the token endpoint (mutating — it issues tokens). The justification is
  load-bearing — RFC 6749 §2.3.1 specifies that client_secret in the POST
  body IS the authentication. Plan must call out this exception with
  Phase 2 SEC-002 wisdom (`esc_url(admin_url())`) as precedent for "memory
  rule + documented exception."
- **S3** — OAuth tokens + Application Passwords stored hashed (SHA-256
  minimum), never plaintext. (Reason: FR-020 directly enforces this for
  both auth codes and access tokens. Source: CONSTITUTION.md §III bullet 7.)

## Related Historical Lessons

- **B1** — Namespace silent-fail inside `Includes\*` files. (Reason: every
  new OAuth class extends or references siblings; `use` imports throughout.)
- **B4** — Unescaped dot in `add_rewrite_rule()` PCRE pattern: `'^.well-known/'`
  matches ANY char as the first. (Reason: FR-003 registers exactly this
  family of rewrite rules — `/.well-known/oauth-authorization-server` and
  `/.well-known/oauth-protected-resource`. **The dot MUST be escaped**:
  `'^\.well-known/oauth-authorization-server$'`. Memory entry directly
  load-bearing for this phase. Source: BUGS.md.)
- **B7** — Mass-assignment via forged POST keys to `$wpdb->update/insert`.
  (Reason: the 3 BerlinDB Query layers all need the same column-whitelist
  filter inside `add_item`/`update_item` — pattern already in
  `MCPServer\Query::add_item` from Phase 2.0. Source: BUGS.md.)

## Conflict Warnings

- **Soft conflict — A4 (DataForm/DataViews) vs FR-008 consent page**:
  Constitution mandates DataForm; consent page is a plain `<form>` per
  RFC 6749 §4.1.1. Spec §Admin UI Requirements already documents the
  exemption. **Recommendation**: planning may proceed; if
  `/speckit-architecture-guard-violation-detection` challenges this
  later, the exemption is RFC-mandated, not project preference. Could
  warrant a new **A13 — RFC-prescribed forms are exempted from
  DataForm** memory candidate post-implementation.

- **Soft conflict — S2 (permission_callback never `__return_true` on
  mutating routes) vs FR-011 token endpoint**:
  S2 explicitly forbids `__return_true` on mutating routes. The token
  endpoint mutates (issues tokens) but uses code-in-body authentication
  per RFC 6749 §2.3.1. Spec FR-011 documents the rationale.
  **Recommendation**: planning may proceed with the exemption documented
  in spec. After implementation, propose **S7 — OAuth token endpoint is
  the documented exception to S2** as a durable memory candidate.

No hard conflicts. No constitution MUST is violated.

## Retrieval Notes

- Index entries considered: 18 (D5/D6/D9/D11, A1/A2/A4/A6/A7/A11, B1/B4/B7,
  S1/S2/S3, DEV1 surveyed and rejected as non-applicable).
- Source sections read: INDEX.md only — index entries self-describing
  enough for this phase's planning.
- Budget status: 18/20 entries · 4/5 decisions · 5/5 architecture ·
  0/3 deviations · 3/3 security · 3/3 bugs · 0/2 worklog.
- Synthesis word count: ~720 / 900-word cap.
