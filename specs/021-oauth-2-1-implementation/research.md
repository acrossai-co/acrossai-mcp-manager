# Phase 0 — Research

Every candidate `NEEDS CLARIFICATION` from the Technical Context is resolved below. Decision + Rationale + Alternatives-Considered for each. Zero unknowns remain that block Phase 1 design.

## Decisions (grouped by source)

### From `/speckit-clarify` Session 2026-07-10

- **Decision (Q1)**: One OAuth token = one MCP server. `TokenValidator` enforces RFC 8707 audience-binding at call time: the token's `resource` column MUST match the request's target MCP endpoint URL; cross-server invocation returns 401. (FR-024 refined.)
  **Rationale**: One leaked token unlocking every MCP server on the site is a materially broader compromise than one leaked token unlocking one server. RFC 8707 is the standardized mechanism for this scoping; Anthropic's connector spec assumes it. Pre-empts the F017 storage-vs-enforcement decoupling class of bug (memory-synthesis §Related Historical Lessons).
  **Alternatives considered**: (a) One token unlocks all servers on the site — weaker isolation, no additional convenience for connectors that focus on one server. (b) Per-connector-profile configurable audience — extra API surface, deferred to a future feature.

- **Decision (Q2)**: Admin-generated `client_id` uses the structured format `server-{server_id}-{connector_slug}-{random8}`; DCR uses opaque `bin2hex(random_bytes(16))`. The `server-` prefix is reserved. (FR-023, FR-033, FR-035 refined.)
  **Rationale**: Admin format doubles as an audit tag (immediately identifiable in log lines + `AIConnectorsTab`'s `LIKE 'server-{id}-{slug}-%'` lookup is index-friendly via `KEY(connector_slug)`). DCR clients stay opaque to avoid leaking metadata to third parties. Reserved prefix eliminates collision risk.
  **Alternatives considered**: (a) Both use opaque random — loses audit-readability + tab lookup can't use prefix. (b) Both structured with `dcr-` prefix for DCR — loses opacity for third-party clients.

- **Decision (Q3)**: The consent screen renders on **every** `/authorize` request. No approval memoization, no `approved_at` column, no `OAuthConsents` table. (FR-009 refined.)
  **Rationale**: MCP connectors have persistent access to sensitive tool surfaces (F015 access control + F017 abilities + F020 tools). Explicit operator intent per authorization is a security feature. Re-authorizations only fire on cache clear / reinstall / scope change — friction cost is acceptable.
  **Alternatives considered**: (a) Remember (user, client, resource) approvals for 30 days — convenience win at the cost of explicit operator visibility. (b) Configurable per connector profile — extra API surface. Post-implement `/speckit-memory-md-capture` may formalize "no consent memoization" as `DEC-OAUTH-CONSENT-ALWAYS-SHOW`.

- **Decision (Q4)**: On `deleted_user`, the plugin bulk-revokes tokens + deletes pending auth codes for that user. Fires `token_revoked` per revoked token with reason `'user_deleted'`. (FR-042 added.)
  **Rationale**: Prevents ghost-user authentication (a token binding to a user_id whose account no longer resolves) and maintains audit-trail consistency (revoked with reason, not silently orphaned). The `deleted_user` action is the canonical WordPress hook for this cascade.
  **Alternatives considered**: (a) Leave orphaned; rely on WordPress rejecting the ghost user_id at `wp_set_current_user` — silent-failure semantic; loses audit trail. (b) DELETE rows instead of revoke — cleaner storage but destroys the audit trail.

### From memory synthesis (durable pattern re-use)

- **Decision**: Extend `\BerlinDB\Database\Kern\*` via leading-`\` FQN — no `use BerlinDB\Database\Kern\Table;` etc.
  **Rationale**: Enforced by `DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION` (Active F011). All 12 new class files (3 modules × 4 files) share local names with parents; `use` would fatal.
  **Alternatives considered**: Alias imports — legal but adds import noise; F011/F017/F020 all use leading-`\` FQN.

- **Decision**: Instantiate all three new Table subclasses at BOTH `Activator::activate()` AND `Main::bootstrap_database_tables()`.
  **Rationale**: Enforced by `DEC-BERLINDB-TABLE-REQUEST-BOOT` (Active F011). Activation-only boot leaves BerlinDB's DB interface empty on subsequent requests.
  **Alternatives considered**: None — this is a well-established anti-regression rule.

- **Decision**: Uninstall destructive teardown for the three new tables sits BELOW the `acrossai_mcp_uninstall_delete_data` opt-in gate.
  **Rationale**: Enforced by `DEC-UNINSTALL-OPT-IN-GATE` (Active F012). F021 does NOT add a second gate.
  **Alternatives considered**: Unconditional DROP on uninstall — violates WP.org guideline #5.

- **Decision**: `AuthCodesQuery::consume_atomic( string $code_hash, string $now ): ?Row` mirrors `CliAuthLog\Query::redeem_atomic` exactly. Single UPDATE statement with `used=0 AND expires_at > %s` guard; `1 === (int) $wpdb->rows_affected` semantics.
  **Rationale**: Bug pattern B10 — check-then-act on one-shot credentials must use atomic single-statement CAS to prevent replay under concurrent POSTs.
  **Alternatives considered**: SELECT-then-UPDATE — the exact bug B10 forbids.

- **Decision**: Use `method_exists( $server, 'get_server_id' )` duck-typed feature detection if F021 ever needs to touch mcp-adapter object accessors.
  **Rationale**: Bug pattern B24 (F020). F021 does NOT currently access `mcp-adapter` payloads in this way — the `TokenValidator` operates on the WordPress request layer via `determine_current_user`, well before mcp-adapter is invoked. So B24 doesn't fire on F021's shipped surface. Kept in the research log as a reminder for the RFC 8707 audience-verification implementation: get the current request URL from `$_SERVER` / `home_url()`, not from an mcp-adapter accessor.
  **Alternatives considered**: N/A — B24 is a preventive lesson, not an active call site.

- **Decision**: `AIConnectorsTab` is added directly to `Registry::all_tabs()` at line 108-121, NOT via the F019 `acrossai_mcp_manager_server_tabs` filter.
  **Rationale**: F019's filter surface is for **third-party** contributions. `AIConnectorsTab` is a **built-in** part of F021. Adding a built-in tab via the third-party filter contract muddies the two extensibility layers. Codified in `DEC-OAUTH-BUILTIN-TAB-NOT-FILTER` (post-implement capture per planning doc §TASK-11).
  **Alternatives considered**: Add via the filter — misuses the third-party extensibility API for a first-party feature.

### From spec derivation (baseline architecture)

- **Decision**: Hand-authored OAuth 2.1 against WordPress core + PHP native crypto. NO `league/oauth2-server`, NO JWT library, NO PSR-7 bridge.
  **Rationale**: `league/oauth2-server` v9 requires PHP 8.2+ (plugin floor is 8.1). Adding a JWT library adds signing-key rotation complexity that opaque tokens avoid. `SecretsVault` wraps `random_bytes` + `hash('sha256')` + `hash_equals` — 20 lines of native PHP. This is codified as `DEC-OAUTH-NO-LIBRARY` (post-implement).
  **Alternatives considered**: (a) `league/oauth2-server` — PHP version incompatible. (b) A minimal PSR-7 wrapper around native PHP — adds a dependency for no gain. (c) Custom JWT — requires signing-key rotation infrastructure; opaque tokens are simpler and equally secure with hashed at-rest storage.

- **Decision**: Access tokens = opaque 64-hex-char strings (`bin2hex(random_bytes(32))`). Refresh tokens same shape. Persisted as SHA-256 hex (64 chars). Compared via `hash_equals`.
  **Rationale**: Simpler than JWT. No signing keys. Revocation is trivial (single UPDATE). Storage size 64 bytes.
  **Alternatives considered**: JWT — key rotation + verification-key distribution + payload validation. Overkill for a single-server audience.

- **Decision**: Access token TTL = 3600s. Refresh token TTL = 2592000s (30 days). Auth code TTL = 600s.
  **Rationale**: 1h access token gives connectors a reasonable operating window; 30-day refresh matches Anthropic connector-lifetime expectations; 10min auth code matches F011 CliAuthLog convention + is well within RFC 6749 §4.1.2's recommendation.
  **Alternatives considered**: (a) 24-hour access tokens — extends compromise window. (b) 7-day refresh — connectors would break constantly on WordPress user login rotation.

- **Decision**: Rate limits — DCR at 10/IP/60s, `/authorize` + `/token` at 60/IP/60s. Backing store: WordPress transients.
  **Rationale**: DCR is the highest-abuse-risk endpoint (unauthenticated write). 10/min gives operators a comfortable throttle. `/authorize` + `/token` at 60/min accommodates browsers that retry heavily on network hiccups. Transients backed by object cache when available; per-web-worker inconsistency is acceptable at these thresholds.
  **Alternatives considered**: (a) `wp_cache_*` directly — skips the transient TTL layer; transients are the honest abstraction. (b) Distributed rate limiter (Redis Lua) — new dependency for a threshold that native transients handle.

- **Decision**: Consent template at `templates/oauth/consent.php` is self-contained inline HTML+CSS. No React, no jQuery, no admin-frame chrome.
  **Rationale**: The consent screen is a security-critical surface. Fewer moving parts = fewer attack surfaces. Analogous to WordPress's own `wp-login.php`. Inline CSS is trivial and eliminates asset-load timing / CSP surprises. Codified in Complexity Tracking as `DEC-OAUTH-TEMPLATES-DIRECTORY`.
  **Alternatives considered**: React consent app — reintroduces React runtime + apiFetch + build pipeline. Overkill for a two-button form. Admin-frame render — brings the admin bar + theme header + admin_body_class into a public-facing security surface, all of which are attacker-controllable via filters.

- **Decision**: `ConnectorProfileRegistry::get_profiles()` memoizes filter output per request (fires filter once). Duplicate slugs resolve later-wins with `_doing_it_wrong` under `WP_DEBUG`.
  **Rationale**: Prevents accidental double-firing when multiple callers ask for the profile list in one request. Slug uniqueness is enforced at read time; companion plugins that ship duplicate slugs get a diagnostic in dev without breaking prod.
  **Alternatives considered**: (a) Fire filter per call — wasteful. (b) Reject duplicate slugs entirely — companion plugins that legitimately override each other lose the pattern (later-wins is the WordPress hook idiom).

## Open Questions Resolved

Zero. All decisions above are grounded in either §Clarifications answers, memory-synthesis references, spec-level requirements, or explicit alternatives-considered chains.

## Next Phase

Phase 1 — Design & Contracts: `data-model.md`, `contracts/rest-api.md`, `contracts/php-hooks.md`, `contracts/connector-profile.md`, `quickstart.md`.
