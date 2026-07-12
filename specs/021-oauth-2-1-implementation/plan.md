# Implementation Plan: OAuth 2.1 + PKCE Authorization Server

**Branch**: `021-oauth-2-1-implementation` | **Date**: 2026-07-10 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification at `specs/021-oauth-2-1-implementation/spec.md`
**Memory Synthesis**: [memory-synthesis.md](memory-synthesis.md) (fresh, prior turn)
**Planning Doc**: [`docs/planings-tasks/021-oauth-2-1-implementation.md`](../../docs/planings-tasks/021-oauth-2-1-implementation.md)

## Summary

Add a provider-agnostic OAuth 2.1 + PKCE authorization server to the plugin. Three new BerlinDB tables (`OAuthClients`, `OAuthTokens`, `OAuthAuthCodes`) store hashed client secrets, opaque access + refresh tokens, and single-use auth codes. Four domain-root endpoints (`/.well-known/oauth-authorization-server`, `/.well-known/oauth-protected-resource`, `/authorize`, `/token`) via `add_rewrite_rule` + `parse_request` (following the Feature-007 `FrontendAuth` pattern), plus a RFC-7591 Dynamic Client Registration endpoint at `/wp-json/acrossai-mcp-manager/v1/oauth/register` and an admin-only credential generator at `/wp-json/acrossai-mcp-manager/v1/oauth/generate-client`. A new `ConnectorProfileRegistry` singleton fires a public filter `acrossai_mcp_manager_connector_profiles` so companion plugins (Claude, ChatGPT, Gemini, Copilot) contribute `AbstractConnectorProfile` subclasses. A new built-in per-server tab `AIConnectorsTab` at priority 35 renders one card per profile with Generate/Regenerate buttons + profile-specific setup instructions — added directly to `Registry::all_tabs()`, NOT via the F019 filter (built-in vs third-party contribution). `TokenValidator` hooks `determine_current_user` at priority 20 to translate bearer tokens into WordPress users, with **RFC 8707 audience-binding enforced at call time** (per §Clarifications Q1) so a token issued for one MCP server rejects when presented against a different server. Cascade cleanup on WP user deletion (`deleted_user` @ 10, per Q4). Consent screen renders every time (per Q3). Admin-generated `client_id` uses structured `server-{id}-{slug}-{rand8}` format (per Q2); DCR uses opaque random. Uninstall respects the F012 opt-in gate. Zero new composer runtime dependencies.

## Technical Context

**Language/Version**: PHP 8.1+ (constitution target; 8.2 features NOT used). JavaScript minimal — no new React bundle (AIConnectorsTab uses vanilla JS clipboard + a small fetch call for the generate-client endpoint).
**Primary Dependencies**: `berlindb/core: ^3.0.0` (already installed via F010/F011); WordPress core APIs (`wpdb`, transients, cron, `wp_verify_nonce`, `wp_redirect`, `wp_login_url`, `wp_set_current_user`, `deleted_user` action, `determine_current_user` filter, `parse_request` action). Native PHP crypto (`random_bytes`, `hash('sha256')`, `hash_equals`, `base64_encode`). No `league/oauth2-server`, no PSR-7 bridge, no JWT library.
**Storage**: Three new BerlinDB tables: `{wpdb->prefix}acrossai_mcp_oauth_clients` (9 columns), `acrossai_mcp_oauth_tokens` (10 columns), `acrossai_mcp_oauth_auth_codes` (11 columns). Every secret column is `char(64)` (SHA-256 hex) except `code_challenge char(43)` (PKCE S256 invariant, matches F011 `CliAuthLog`). Version options `acrossai_mcp_oauth_<table>_db_version = '1.0.0'`. Per-site (`$global = false`).
**Testing**: PHPUnit (existing suite in `tests/phpunit/`) — new tests under `tests/phpunit/OAuth/` (PKCE verify, TokenValidator recursion + audience, ConnectorRegistry memoization, RateLimiter, AuthCodeConsumeAtomic, DiscoveryMetadata). RFC 7636 Appendix B PKCE vectors as canonical test data.
**Target Platform**: WordPress 6.9+, PHP 8.1+, InnoDB utf8mb4 MySQL 5.6+ or MariaDB equivalent. Multisite supported (per-site tables — `$global = false`).
**Project Type**: WordPress plugin. Uses the existing four-directory layout — `admin/Partials/`, `includes/`, `public/Partials/`, `src/`. Adds a new `templates/` top-level directory (soft conflict noted in memory synthesis).
**Performance Goals**: SC-001 — full connector-provisioning journey in **under 5 minutes**. SC-004 — revoked token fails authentication on the **very next request** (no cache). SC-011 — non-OAuth pages see **zero measurable request-time overhead** (bearer-header check bails fast when no header present).
**Constraints**: Zero new composer runtime dependencies (constitution VI Tier 1/Tier 2 + explicit rejection in Assumptions). Every secret SHA-256 hashed at rest (S3). PKCE S256 mandatory (Anthropic requirement — no `plain` filterable escape hatch). RFC 8707 `resource` param mandatory + enforced at call time (§Clarifications Q1). Rate limits: `/register` at 10/IP/60s, `/authorize` + `/token` at 60/IP/60s. Consent screen renders every time (Q3). Cross-server token = 401 (Q1).
**Scale/Scope**: ~40 new PHP class files (12 BerlinDB module files + 13 OAuth core + 5 repositories + 2 security + 2 connectors + 1 tab + 1 router + 1 cleanup) + 1 HTML template + 1 abstract profile class + ~1,500-2,000 LOC across PHP. Small delta files (Main.php +30 lines, Activator.php +6, Deactivator.php new but tiny, uninstall.php +8, README.txt +5, docs/planings-tasks/README.md +1). Estimated total ~2,500 LOC additive.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Constitution version: **1.0.0** (ratified 2026-05-28, last amended 2026-05-29).

### Principle I — Modular Architecture — ✅ PASS

- Three new BerlinDB modules (`OAuthClients`, `OAuthTokens`, `OAuthAuthCodes`) are self-contained under `includes/Database/`, independently testable. No shared logic duplicated with F011 or F017/F020 modules. No cross-module method calls between OAuth modules and the pre-existing MCPServerAbility / MCPServerTool modules.
- OAuth core sits at `includes/OAuth/` with a clean layering: Controllers (Discovery, Authorization, Token, ClientRegistration, TokenValidator) → Repositories (AccessToken, RefreshToken, AuthCode, Client, Scope) → BerlinDB Queries. Zero direct `$wpdb` access from Controllers.
- `ConnectorProfileRegistry` is a self-contained module under `includes/Connectors/` — no dependency on any OAuth internal beyond the abstract `AbstractConnectorProfile` contract.
- `AIConnectorsTab` extends `AbstractServerTab` (F013 pattern), added to `Registry::all_tabs()` — NOT via the F019 filter (DEC-OAUTH-BUILTIN-TAB-NOT-FILTER planned; see planning doc §TASK-11).

### Principle II — WordPress Standards Compliance — ✅ PASS

- PHPCS + PHPStan level 8 + PHPUnit gates itemized in `spec.md` §Definition of Done Gates. Multisite-compatible via per-site tables (`$global = false`).
- All new PHP text uses text domain `acrossai-mcp-manager`.
- No deprecated WordPress functions.

### Principle III — Security First (NON-NEGOTIABLE) — ✅ PASS (with S7 documented consumer revival)

- **S1 (nonce)** — Consent form uses `wp_nonce_field( 'acrossai_mcp_manager_oauth_authorize' )` verified on POST. Admin `generate-client` route requires WordPress nonce.
- **S2 (permission_callback explicit)** — Admin `generate-client` route uses `current_user_can( 'manage_options' )`. DCR `/register` route uses rate-limiter permission callback (returns `WP_Error` on lockout, `true` otherwise — the DCR spec explicitly permits unauthenticated registration + rate limiting is the S8 body-authenticated exception). `/token` uses `__return_true` per RFC 6749 §2.3.1 with S7 exception — F021 revives S7's active-consumer status; INDEX row will be updated post-implement.
- **S3 (secrets hashed)** — Every column storing a token, secret, or code is `char(64)` for the SHA-256 hex; comparison via `hash_equals`; no raw values persisted (FR-039, FR-040).
- **S4 (`$wpdb->prepare`)** — All DB writes through BerlinDB's prepared layer + explicit `$wpdb->prepare()` on bespoke `consume_atomic` / `revoke_by_hash` / `delete_expired` / `find_by_fingerprint` / `revoke_by_user_id` methods.
- **S9 (consent surface state)** — `AuthorizationController::handle_post()` re-validates every authorize param on POST from the database (`OAuthClients` row + connector-profile whitelist), NEVER trusts the hidden inputs in the consent form's URL params. Confused-deputy / UI-misrepresentation defense.
- Rate limiting: 429 response with RFC-6749-shaped JSON body + `Retry-After: 60` header (per FR-027, FR-028).
- Redirect URI validation: HTTPS-or-loopback enforced at both DCR registration time AND authorize time.
- PKCE S256 mandatory; `plain` explicitly rejected regardless of client claim.
- RFC 8707 `resource` param mandatory + enforced at call time (§Clarifications Q1) — closes F017's storage-vs-enforcement decoupling class of bug pre-emptively.
- WP user deletion cascade (FR-042, per Q4) — `deleted_user` @ 10 hook auto-revokes tokens + deletes pending codes.

### Principle IV — User-Centric Design (NON-NEGOTIABLE) — ✅ PASS (per constitution v1.1.0 exception)

- **The `AIConnectorsTab` renders per-profile cards with Generate/Regenerate buttons and setup-instructions text, not a `DataForm` or `DataViews` grid.** Constitution v1.1.0 (amended 2026-07-12) adds a "Connector picker card layout" exception to Principle IV that explicitly covers this tab and its Phase 9 shell + Phase 10 nested-tab structure. The exception is scoped narrowly (branded picker UX + small operational lists < 20 rows) so any future filterable/sortable admin UI still owes DataViews/DataForm.
- **Consent screen** at `templates/oauth/consent.php` is a dedicated public-facing HTML template rendered OUTSIDE the WP admin frame (no admin bar, no theme header, no admin_body_class). This is intentionally analogous to WordPress's own `wp-login.php` — a security-critical surface with hand-rolled HTML. Does not fall under Principle IV admin-form mandate.
- **Historical note**: Prior to constitution v1.1.0 this was a logged soft deviation citing F020's shuttle-picker precedent. The v1.1.0 amendment formalizes the pattern; no further post-implement DEC required for the cards-over-DataViews choice.

### Principle V — Extensibility Without Core Modification — ✅ PASS

- One PUBLIC filter (`acrossai_mcp_manager_connector_profiles`) is the ONLY registration path for connector profiles. Base plugin ships ZERO profiles — every AI integration is a companion plugin.
- Four PUBLIC actions (`acrossai_mcp_manager_oauth_token_issued`, `_authorization_denied`, `_token_revoked`, `_cleanup`) for observability. Payload shapes documented in `contracts/php-hooks.md` (Phase 1).
- Companion plugins integrate optionally: absence of any profile → tab shows empty-state; no other feature degrades.
- F019's `acrossai_mcp_manager_server_tabs` filter is UNCHANGED. `AIConnectorsTab` is a built-in (added in `Registry::all_tabs()` at line 108-121), NOT a filter contribution.

### Principle VI — Reusability & DRY — ✅ PASS

- `SecretsVault::random_token()`, `SecretsVault::hash()`, `hash_equals()` are the single generation + hashing + comparison surface — every OAuth secret path goes through them.
- Tier 1 `@wordpress/*` packages (or plain PHP) exclusively — zero external OAuth libraries per FR-DoD gate.
- `AuthCodesQuery::consume_atomic()` follows `CliAuthLog\Query::redeem_atomic()` shape exactly (B10 canonical CAS pattern — see planning doc §TASK-1).

### Principle VII — Definition of Done — ✅ PASS (gates listed)

- All applicable DoD gates itemized in `spec.md` §Definition of Done Gates. `AGENTS.md` standards inherited.

**Constitution Check Result**: All 7 principles pass under constitution v1.1.0. The former Principle IV soft deviation is now covered by the v1.1.0 "Connector picker card layout" exception. No hard violations.

## Project Structure

### Documentation (this feature)

```text
specs/021-oauth-2-1-implementation/
├── plan.md                    # This file — /speckit-plan output
├── spec.md                    # Feature spec (written 2026-07-10)
├── memory-synthesis.md        # Memory context (written 2026-07-10)
├── research.md                # Phase 0 output (this run)
├── data-model.md              # Phase 1 output (this run)
├── quickstart.md              # Phase 1 output (this run)
├── contracts/
│   ├── rest-api.md            # Phase 1 — REST + rewrite-rule endpoint contracts
│   ├── php-hooks.md           # Phase 1 — filter + action contracts
│   └── connector-profile.md   # Phase 1 — AbstractConnectorProfile contract
├── checklists/
│   └── requirements.md        # Written during /speckit-specify
└── tasks.md                   # Phase 2 output (/speckit-tasks — NOT written by this command)
```

### Source Code (repository root)

WordPress plugin — single deliverable using the existing four-directory layout + one new top-level `templates/` directory. F021 files marked with `**`:

```text
admin/
└── Partials/
    └── ServerTabs/
        ├── AIConnectorsTab.php # ** NEW — priority-35 built-in tab
        └── Registry.php        # ** DELTA — insert new tab in all_tabs() at line 108-121

includes/
├── Main.php                    # ** DELTA — request-time boot × 3, REST wire × 2, consent + token + rewrite + cron + deleted_user hooks
├── Activator.php               # ** DELTA — 3 × Table::instance()->maybe_upgrade() + wp_schedule_event
├── Deactivator.php             # ** NEW/DELTA — wp_clear_scheduled_hook
├── Database/
│   ├── OAuthClients/           # ** NEW MODULE — 4 files (Schema, Row, Table, Query)
│   ├── OAuthTokens/            # ** NEW MODULE — 4 files
│   └── OAuthAuthCodes/         # ** NEW MODULE — 4 files
├── OAuth/                      # ** NEW MODULE
│   ├── DiscoveryController.php
│   ├── AuthorizationController.php
│   ├── TokenController.php
│   ├── ClientRegistrationController.php
│   ├── TokenValidator.php
│   ├── PKCE.php
│   ├── OAuthRouter.php
│   ├── Cleanup.php
│   ├── Repositories/
│   │   ├── AccessTokenRepository.php
│   │   ├── RefreshTokenRepository.php
│   │   ├── AuthCodeRepository.php
│   │   ├── ClientRepository.php
│   │   └── ScopeRepository.php
│   └── Security/
│       ├── RateLimiter.php
│       └── SecretsVault.php
└── Connectors/                 # ** NEW MODULE
    ├── AbstractConnectorProfile.php
    └── ConnectorProfileRegistry.php

templates/                      # ** NEW top-level directory
└── oauth/
    └── consent.php             # ** NEW self-contained HTML

tests/
└── phpunit/
    └── OAuth/                  # ** NEW
        ├── PKCEVerifyTest.php
        ├── TokenValidatorTest.php  # includes audience-binding + recursion guard
        ├── ConnectorProfileRegistryTest.php
        ├── RateLimiterTest.php
        ├── AuthCodeConsumeAtomicTest.php
        ├── DiscoveryMetadataTest.php
        └── UserDeletedCascadeTest.php    # FR-042 (Q4) verification

# Delta-only files:
acrossai-mcp-manager.php        # ** DELTA — Version 0.0.9 → 0.1.0
README.txt                      # ** DELTA — Unreleased changelog bullet + version bump
uninstall.php                   # ** DELTA — 3 more DROP TABLE + 3 more delete_option + wp_clear_scheduled_hook, all BELOW the F012 opt-in gate
docs/extending-connector-profiles.md   # ** NEW author guide
docs/planings-tasks/README.md   # ** DELTA — append row 021
docs/memory/{DECISIONS,WORKLOG,INDEX,ARCHITECTURE}.md # ** DELTA — 5-7 new DEC-* + 1 WORKLOG row (captured post-implement)
```

**Structure Decision**: Single WordPress-plugin repo. Two brand-new namespaces (`\AcrossAI_MCP_Manager\Includes\OAuth\*` and `\AcrossAI_MCP_Manager\Includes\Connectors\*`) mirror the existing `\AcrossAI_MCP_Manager\Includes\Database\*` layout. One new top-level `templates/` directory for the consent HTML — no prior feature uses it; documented in Complexity Tracking as a new convention introduced by F021. Three new BerlinDB modules mirror F011's `MCPServer/CliAuthLog/OAuthToken/OAuthAudit/MCPServerAbility/MCPServerTool` shape one-for-one.

## Complexity Tracking

Two deviations from Constitution IV / A4 are justified below. No hard violations.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| `AIConnectorsTab` renders per-profile cards + Generate/Regenerate buttons, not `DataForm` or `DataViews` | The tab body is a **display of static configuration** (one card per registered connector profile with its brand/icon, its setup instructions, and a Generate button). It is NOT a data-entry form and NOT a listing/table with searchable/sortable rows. F020's shuttle picker set the precedent for hand-rolled admin UI when neither DataForm nor DataViews expresses the intended UX; mirrors that alternative-considered pattern. | (a) `DataViews` grid with connector-slug as row + "Generate credentials" as a row action — loses the profile-branded card layout that operators need to visually distinguish Claude from ChatGPT from Gemini; the DataViews' fixed column layout materially degrades the "at-a-glance connector picker" UX. (b) `DataForm` — inapplicable; there is no user input beyond a button click. Both alternatives materially degrade the UX. Post-implement: `DEC-OAUTH-AICONNECTORSTAB-CARDS-OVER-DATAVIEWS` codifies the deviation, following F020's `DEC-TOOL-SHUTTLE-PICKER-OVER-DATAVIEWS` precedent. |
| New top-level `templates/` directory for the consent HTML | The consent screen is a public-facing HTML page rendered OUTSIDE the WP admin frame — no admin bar, no theme header, no `admin_body_class`. It cannot live under `admin/Partials/` (that's the admin-only namespace per constitution's Architecture Standards) and doesn't fit `public/Partials/` (that's for frontend-facing PHP classes, not HTML templates). A new `templates/` directory is the honest layout. | (a) Put the template under `public/Partials/OAuthConsent.php` as a class that echoes HTML — adds a class shim over what is fundamentally a template file, muddles `Public/Partials`' purpose. (b) Inline the HTML in `AuthorizationController::render_consent()` — makes the 100+ line HTML unmaintainable inside a PHP class. (c) Use a `.html.php` extension inside `includes/OAuth/` — mixes concerns (templates in the domain layer). A dedicated `templates/` root, following widely-established WordPress plugin patterns, is the cleanest option. Post-implement: `DEC-OAUTH-TEMPLATES-DIRECTORY` codifies the new convention. |

## Phase 0 → Phase 1 Artifacts

- **Phase 0 — Research** (`research.md`): all Technical Context choices reviewed for open questions; every candidate `NEEDS CLARIFICATION` was resolved either during `/speckit-clarify` (4 clarifications) or during the memory-synthesis step. Zero unknowns remain that block Phase 1 design. See `research.md` for the decision log.
- **Phase 1 — Design** (`data-model.md`, `contracts/`, `quickstart.md`): produced in this same command run — see the sibling files under `specs/021-oauth-2-1-implementation/`.
- **Agent context update**: no `CLAUDE.md` exists at the plugin root; nothing to update between `<!-- SPECKIT START -->` and `<!-- SPECKIT END -->` markers. The plan is discoverable via `specs/021-oauth-2-1-implementation/plan.md`. Recorded in the governance summary.

## Phase 9 Addendum — Connector Card Shell (2026-07-11)

Scope addition after initial F021 ship. Multiple AI connectors are planned (Claude → ChatGPT → Gemini → Copilot), and Approach B (each companion plugin owns its full card rendering) forces every new companion to duplicate ~500 LOC of near-identical CSS + JS + event handlers. Phase 9 promotes the shared shell into the base plugin.

**New / delta files** (all inside `acrossai-mcp-manager`):

- `includes/Connectors/AbstractConnectorProfile.php` — extended with concrete render helpers: `render_default_card`, `render_card_header`, `render_card_body`, `render_url_row`, `render_credentials_area`, `render_regenerate_area`, `render_result_target`, `find_existing_client_id`. `render_tab_section` is no longer abstract — default calls `render_default_card`.
- `src/scss/ai-connectors.scss` — shared card CSS (~245 lines).
- `src/js/ai-connectors.js` — shared delegated JS handler (~250 lines). Imports the SCSS.
- `build/js/ai-connectors.{js,css,asset.php}` — webpack-produced bundle (via `npm run build`).
- `webpack.config.js` — new `js/ai-connectors` entry.
- `admin/Main.php` — new `maybe_enqueue_ai_connectors_app()` method, mirrors `maybe_enqueue_tools_app` / `maybe_enqueue_abilities_app` shape. Gated on `?tab=ai-connectors`. Localizes `acrossaiMcpConnectors` with REST endpoint + 11 i18n strings.
- `docs/extending-connector-profiles.md` — new §Card Shell section documenting the shell HTML, JS event contract, and override granularities.
- `specs/021-oauth-2-1-implementation/spec.md` — added FR-046..FR-052.
- `specs/021-oauth-2-1-implementation/tasks.md` — added §Phase 9 tasks T130..T139 (all marked complete).

**Companion plugin delta** (`acrossai-claude-connectors`):

- `includes/ClaudeConnectorProfile.php` — deleted `render_tab_section` override (~130 lines removed). Class is now pure metadata.
- `includes/Main.php` — deleted `enqueue_admin_assets` method.
- `assets/js/claude-connector.js` — DELETED (was ~250 lines).
- `assets/css/claude-connector.css` — DELETED (was ~200 lines).
- `assets/claude-icon.svg` — retained (only remaining asset).

**Public API contract additions**: FR-051 locks these class + attribute names as `@experimental May change without notice before 1.0.0`:
- `.acrossai-mcp-ai-connectors` (wrapper) + `data-server-id` + `data-wp-rest-nonce`
- `.acrossai-mcp-connector` (section) + `data-acrossai-connector-slug`
- `.acrossai-mcp-connector__generate-btn`, `.acrossai-mcp-connector__regenerate-btn` + `data-acrossai-confirm`
- `[data-acrossai-copy]`, `[data-acrossai-reveal]`
- `[data-acrossai-result]`

**Quality gates**: PHPCS clean on both plugins; PHPStan level 8 exit 0 on the base plugin's Connectors namespace; F021 governance gates (A1, layering, column widths, raw-secret) still PASS.

## Phase 10 Addendum — Nested Tabs (F024 folded — 2026-07-11)

Scope addition after Phase 9. Consolidates what was originally tracked as separate feature F024 (spec + plan + tasks preserved under `specs/024-connectors-nested-tabs/` for historical traceability). The single flat AI Connectors tab is replaced with a nested-tab admin UI: Level 1 (server tabs, existing) → Level 2 (connector profile sub-tabs, new) → Level 3 (Generate | Connections | Settings panels, new). Backwards-compatible: any companion plugin's `render_tab_section` override is invoked inside the Generate panel's "Advanced: pre-generate credentials manually" collapsible.

### Constitution Check (Phase 10)

Six principles pass — same envelope as Phase 9. One deviation carries forward from Phase 9 (Principle IV cards over DataViews); a second concern surfaced during architecture review:

- **Principle I — Modular Architecture**: `ConnectorSettings`, `ConnectorAdminController`, and the new `AbstractConnectorProfile` protected methods are self-contained.
- **Principle II — WordPress Standards**: PHPCS + PHPStan level 8 clean. Text domain `acrossai-mcp-manager` on every `__()`. Uses standard `.nav-tab-wrapper` markup for Level 2 + Level 3 tab bars.
- **Principle III — Security First**: All 5 new REST endpoints gate on `manage_options` + `X-WP-Nonce`. `AuthorizationController::handle_get` gains connector-disabled + admin-approval gates BEFORE `render_consent`. Pending-approval page uses `nocache_headers()`. Every dynamic value escaped at output.
- **Principle IV — User-Centric Design**: The nested tab structure uses `.nav-tab-wrapper` + hand-rolled panels + a `widefat striped` connections table, not DataViews. Covered by the constitution v1.1.0 "Connector picker card layout" exception (amended 2026-07-12) which explicitly names the AI Connectors tab's Level 2 + Level 3 panels including the `widefat striped` Connections table.
- **Principle V — Extensibility**: 2 new `@experimental` methods on `AbstractConnectorProfile` (`matches_dcr_client`, `get_mcp_url_setup_html`). CSS class names + `data-*` attributes documented as public API (see below).
- **Principle VI — Reusability**: `mcp_url_for_server()` protected helper deduplicates the URL construction across Generate panel + shared shell.
- **Principle VII — Definition of Done**: PHPCS + PHPStan + F021 governance gates all pass. Manual verification checklist below.

**Layering concern surfaced + resolved by architecture review (2026-07-12)**: The Connections-panel renderer inside `AIConnectorsTab.php` originally accessed `$wpdb` and `OAuthClients\Query::instance()` directly (view layer → DB), bypassing the Repository layer. Fixed same day: `AccessTokenRepository::count_active_by_client_id` + `get_active_user_ids_by_client_id` and `ClientRepository::find_admin_for_server_connector` + `find_dcr_all` now front all four call sites. New gate `T118d Partial/Repository/$wpdb layering` added to `bin/verify-f021-gates.sh` so any regression is caught immediately.

### Complexity Tracking (Phase 10)

Two deviations from the "cards" pattern of Phase 9, both justified:

| Deviation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Nested `<nav class="nav-tab-wrapper">` for Level 2 + Level 3 | Reuse WP core's `.nav-tab-wrapper` styling and semantics — familiar admin idiom. | JS-only tab switching would require in-page state management and break bookmarkability. Full page reloads on switch match F013/F019's Level 1 pattern for consistency. |
| Options-backed settings (`wp_options` rows) instead of BerlinDB table | Settings are per-`(server, connector)` and small (< 5 rows per pair). Adding a BerlinDB table for a few boolean flags is overkill. | A new BerlinDB module would add ~4 files + schema migration risk for no additional query power. Options are fine at this scale. |

### Project Structure (Phase 10)

**New files** (base plugin):

```
includes/Connectors/ConnectorSettings.php       ← settings CRUD + approved/pending lists
includes/OAuth/ConnectorAdminController.php     ← 5 admin REST endpoints
```

**Modified files** (base plugin):

```
includes/Connectors/AbstractConnectorProfile.php  ← +2 concrete methods + mcp_url_for_server helper
includes/Database/OAuthClients/Query.php          ← +find_admin_clients_for_server_connector, find_dcr_clients, delete_by_id
includes/OAuth/AuthorizationController.php        ← +connector-disabled gate + admin-approval gate
includes/Main.php                                 ← wire ConnectorAdminController on rest_api_init
admin/Partials/ServerTabs/AIConnectorsTab.php     ← full rewrite of render_body → nested router
src/scss/ai-connectors.scss                       ← +130 lines for panels + tables + forms
src/js/ai-connectors.js                           ← +150 lines for admin action handlers
build/js/ai-connectors.{js,css,asset.php}         ← rebuilt via npm run build
```

**Modified files** (companion plugin `acrossai-claude-connectors`):

```
includes/ClaudeConnectorProfile.php               ← +matches_dcr_client + get_mcp_url_setup_html
```

### Data Model (Phase 10)

**No new tables.** Everything via `wp_options` (all `autoload=false`):

| Option key pattern | Value shape | Purpose |
|---|---|---|
| `acrossai_mcp_connector_settings_{server_id}_{slug}` | `array{enabled: bool, require_admin_approval: bool}` | The two toggles from the Settings panel. Defaults on cache miss. |
| `acrossai_mcp_connector_approved_users_{server_id}_{slug}` | `array<int, int>` (list of user_ids) | Users pre-approved to complete OAuth for this connector. |
| `acrossai_mcp_connector_pending_approvals_{server_id}_{slug}` | `array<int, int>` (list of user_ids) | Users waiting for an admin's approve click. |

### Public API additions (Phase 10)

Marked `@experimental May change without notice before 1.0.0` per the Phase 9 convention:

- `AbstractConnectorProfile::matches_dcr_client( string $client_name, array $redirect_uris ): bool`
- `AbstractConnectorProfile::get_mcp_url_setup_html( string $mcp_url ): string`
- `AbstractConnectorProfile::mcp_url_for_server( array $server ): string` (protected)
- CSS classes: `.acrossai-mcp-ai-connectors__level2`, `__level3`, `.acrossai-mcp-connector-panel__revoke-btn`, `__delete-btn`, `__nuclear-btn`, `__approve-btn`, `__settings-form`
- Data attributes: `data-acrossai-client-id`, `data-acrossai-user-id`, `data-acrossai-connector-slug`, `data-acrossai-confirm`
- 5 new REST endpoints (see spec.md FR-024-020..023)

### Runtime verification (Phase 10, post-implement)

Verified against live install on 2026-07-11:

- ✅ REST discovery lists all 5 new routes at `/wp-json/acrossai-mcp-manager/v1`
- ✅ Unauthenticated POST to `/oauth/connector-settings` returns 403 with custom error code (permission callback runs correctly)
- ✅ MCP URL construction now uses `server_route_namespace + server_route` (matching F011 CliController pattern) — verified against actual default server row (`mcp` + `mcp-adapter-default-server`)
- ✅ `server_id_from_client_and_resource` handles both admin-generated clients (parse `server-{id}` prefix) AND DCR-registered clients (walk MCPServer rows matching resource URL)

### Two runtime bugs found + fixed during Phase 10 verification

1. **`$server['mcp_url']` used a non-existent column.** Fixed by inlining the F011 CliController pattern (`rest_url( trailingslashit( $namespace ) . $route )`) and abstracting into `mcp_url_for_server` helper.
2. **`server_id_from_resource` regex `/server-(\d+)/` never matched real URLs.** Fixed by replacing with `server_id_from_client_and_resource(ClientRow, string)` that parses from client_id for admin clients + walks MCPServer rows for DCR clients.

Both discovered by consulting the live DB after linting passed, before shipping.

### Manual verification checklist (Phase 10)

1. **Empty registry state**: Deactivate all connector companion plugins. Load AI Connectors tab. See F021 Phase 8 empty state, no sub-tab bar.
2. **Single-profile state**: Reactivate `acrossai-claude-connectors`. Load AI Connectors tab. See Level 2 bar with only `[Claude]`, Level 3 bar with `[Generate] [Connections] [Settings]`.
3. **URL routing**: Click Connections → URL changes to `?...&connector=claude&panel=connections`; Connections panel renders.
4. **Generate panel**: MCP URL shown correctly as `https://<site>/wp-json/mcp/mcp-adapter-default-server`. Copy button flashes "Copied!". Claude's branded 4-step ordered list appears in the accent callout. "Advanced: pre-generate credentials manually" collapsible opens and reveals the F021 Phase 9 admin-generate flow.
5. **Connections panel**: Empty state visible: "No AI clients have connected via Claude yet."
6. **Settings panel**: Both checkboxes render checked/unchecked from defaults (`enabled=true`, `require_admin_approval=false`). Save Settings persists to `wp_options` (check DB); reload shows new state.
7. **Mass-revoke on disable**: Seed one admin-generated client + one active token. Uncheck "Enable this connector on this server" → Save. Reload Connections panel → the token is now revoked (0 active). Fire history includes `token_revoked` with reason `'connector_disabled'`.
8. **Admin approval gate**: Toggle `require_admin_approval` ON. As a non-approved user, hit `/authorize?client_id=...`. See "Waiting for admin approval" page. Log in as admin → Settings panel shows pending user in the list → click Approve. Retry `/authorize` as the previously-pending user → consent screen now renders normally.

## Next Command

`/speckit-tasks` (produces `tasks.md` from this plan). Governed variant used by the orchestrating command: `/speckit-architecture-guard-governed-tasks`.
