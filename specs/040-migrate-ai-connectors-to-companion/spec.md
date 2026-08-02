# Feature Specification: Migrate AI Connectors + OAuth Stack to Companion Plugin

**Feature Branch**: `040-migrate-ai-connectors-to-companion`
**Created**: 2026-07-31
**Status**: Draft
**Input**: User description: "Remove the AI Connectors + OAuth stack from acrossai-mcp-manager after acrossai-ai-connectors has taken ownership. Delete Connectors framework, OAuth server, BerlinDB modules for four OAuth tables, admin tab + frontend assets + consent template. Modify Activator/Deactivator/Main/admin Main/Registry/uninstall/webpack to unwire OAuth and drop the built-in AI Connectors tab. Add `Requires Plugins: acrossai-ai-connectors` to the plugin header. Add a `class_alias` compat shim so third-party plugins extending `AbstractConnectorProfile` keep working. Preserve all four OAuth tables (`wp_acrossai_mcp_oauth_*`, `wp_acrossai_mcp_connector_approved_users`), all `acrossai_mcp_connector_%` options, the cron hook name `acrossai_mcp_manager_oauth_cleanup`, and the REST namespace `acrossai-mcp-manager/v1` — the companion plugin now owns them with byte-identical declarations."

## Clarifications

### Session 2026-07-31

- Q: What version does the plugin bump to for the release containing the hard `Requires Plugins:` dependency? → A: `0.2.0` (SemVer-minor bump during 0.x to signal a breaking change — installers must have the companion plugin present).
- Q: Should `Deactivator.php` conditionally skip clearing the `acrossai_mcp_manager_oauth_cleanup` cron when the companion is still active? → A: No — always clear (unconditional). Rationale: AI Connectors is an add-on that declares `Requires Plugins: acrossai-mcp-manager`, so WordPress core prevents deactivating mcp-manager while the companion is active. The race where mcp-manager clears a cron the companion still needs cannot occur through the admin UI. WP-CLI bypass is covered by the FR-013 admin notice, and worst-case one missed daily cleanup tick is harmless.
- Q: What should happen to the pre-existing untracked `specs/039-migrate-ai-connectors-to-companion/` directory (a prior drafting attempt that never entered git history)? → A: Delete it (`rm -rf specs/039-migrate-ai-connectors-to-companion/`) as part of this feature so `specs/` contains no duplicate sibling directories for the same migration. Because 039 was never committed, the deletion is a working-tree-only cleanup with zero git-history impact.
- Q: Is a `class_alias` compat shim needed for third-party plugins that might have extended `AbstractConnectorProfile` under the old namespace? → A: No — dropped from scope. Rationale: mcp-manager and its ai-connectors add-on have never been released to third parties, so there are no in-the-wild plugins extending the old `\AcrossAI_MCP_Manager\Includes\Connectors\*` classes. Building a compat layer for a scenario that can't exist is dead code. The former FR-014 (compat shim) and User Story 3 (third-party BC) are removed. If third-party extensions appear later, the shim can be added in a follow-up feature.
- Q: Is `acrossai-ai-connectors` a **hard dependency** of mcp-manager (blocking activation without it) or an **optional premium add-on** for the OAuth click-to-connect path? → A: **Optional premium add-on.** MCP Manager already offers non-OAuth connection paths via the `tab=npm` and `tab=clients` tabs (manual client config for Claude Desktop, Cursor, etc.), and free users depend on those. `Requires Plugins: acrossai-ai-connectors` must NOT be added to mcp-manager's header — that would break every free install. The add-on is required only if the user wants the OAuth-based Connect-with-Claude / ChatGPT / Grok flow. Correspondingly, the admin-notice safety net (FR-013) fires only when prior OAuth data exists (`wp_acrossai_mcp_oauth_tokens` has ≥1 row) AND the companion class is absent; fresh/free users see no notice. Impacts: FR-012 (Requires Plugins line dropped, version bump kept), FR-013 (notice made conditional on prior OAuth use), User Story 2 (reframed), SC-006 (removed), SC-007 (narrowed), new edge case for the free-user standalone path.
- Q: Should the FR-013 conditional admin notice be built at all, given the plugin has no external users yet? → A: **No — dropped from scope.** Rationale: mcp-manager has a single known operator (the plugin author). That operator knows their own site state and does not need an automated warning to remember to install the ai-connectors add-on when updating. The notice was defense-in-depth for a user population that doesn't yet exist. FR-013 is marked as a removed placeholder; the conditional-notice logic, the token-count `absint()` sanitization, and the token-table existence check are all dropped. This feature now adds **zero new code** — it is pure deletions + a header version bump. If external users appear later, the notice can be added in a follow-up feature. Impacts: FR-013 (removed), User Story 2 (further simplified to just document the split, no warning mechanism), SC-007 (removed), security-checklist item about `esc_html__()` (removed), edge cases updated to not reference the notice.

## User Scenarios & Testing *(mandatory)*

<!--
  This is a migration/removal feature. Priorities reflect risk exposure, not user-visible functionality:
  P1 = zero-disruption to premium users who currently authenticate AI clients via OAuth
       (Claude, ChatGPT, Grok click-to-connect flow), assuming both plugins are updated together
  P2 = free users updating mcp-manager standalone (without the paid AI Connectors add-on)
       continue to use npm/clients tabs unchanged; no automated warning is provided if the
       add-on is missing (see 2026-07-31 Q6 — plugin has no external users yet, operator
       manages their own state)

  Notes:
  - An earlier draft had a P3 story for third-party plugin BC via class_alias. Dropped per
    2026-07-31 Q4 — mcp-manager has no third-party ecosystem yet.
  - An earlier draft assumed acrossai-ai-connectors was a hard dependency of mcp-manager.
    Corrected per 2026-07-31 Q5 — the add-on is required only for the OAuth path; free users
    keep using the mcp-manager npm/clients tabs standalone.
  - An earlier draft added a conditional admin-notice safety net (FR-013) for premium users
    who lost the add-on. Dropped per 2026-07-31 Q6 — plugin has no external users yet, so
    the notice defends against a scenario that doesn't exist. This feature now adds ZERO
    new code (pure deletions + header version bump).
-->

### User Story 1 - Existing AI Client Connections Survive The Update (Priority: P1)

An end-user site has Claude Desktop, ChatGPT, and Grok already connected to the WordPress site via OAuth 2.1 (bearer tokens issued by the current mcp-manager OAuth server). The site administrator updates mcp-manager to the new version (this feature) AND installs/activates the new acrossai-ai-connectors companion plugin. From the AI client's perspective, nothing changes: the same bearer tokens continue to authenticate, the same OAuth discovery endpoints resolve, the same `/wp-json/acrossai-mcp-manager/v1/oauth/*` routes respond identically, and no re-authorization prompt appears.

**Why this priority**: This is the whole point of the migration. If existing connections break, every site with OAuth-connected AI clients has to have every user re-authorize every connection — an unacceptable failure mode that would block the release.

**Independent Test**: On a site with a pre-existing bearer token, `curl -H 'Authorization: Bearer <token>' https://site/wp-json/acrossai-mcp-manager/v1/mcp/<slug>` MUST return 200 (same as before the update). `curl https://site/.well-known/oauth-authorization-server` MUST return the same JSON with `registration_endpoint` pointing at `/wp-json/acrossai-mcp-manager/v1/oauth/register`. `SHOW CREATE TABLE wp_acrossai_mcp_oauth_tokens` MUST return identical DDL to pre-migration.

**Acceptance Scenarios**:

1. **Given** a site with N active bearer tokens in `wp_acrossai_mcp_oauth_tokens` and both plugins installed, **When** the site administrator updates mcp-manager to this version and activates acrossai-ai-connectors, **Then** all N tokens continue to authenticate MCP requests successfully with no re-authorization required.
2. **Given** an AI client that discovered endpoints via `/.well-known/oauth-authorization-server` before the update, **When** the update completes, **Then** the same discovery URL returns the same `authorization_endpoint`, `token_endpoint`, and `registration_endpoint` values (all still under the `acrossai-mcp-manager/v1` REST namespace, now served by the companion plugin).
3. **Given** the `wp_acrossai_mcp_oauth_clients`, `_tokens`, `_auth_codes`, and `wp_acrossai_mcp_connector_approved_users` tables exist with production data, **When** the update runs, **Then** none of the tables are dropped, altered, renamed, or truncated — BerlinDB `db_version_key` values continue at the same version numbers under companion ownership.
4. **Given** the cron event `acrossai_mcp_manager_oauth_cleanup` was scheduled by the pre-update mcp-manager, **When** the update completes and acrossai-ai-connectors activates, **Then** exactly one instance of that hook name remains scheduled (owned by the companion), and expired-token cleanup continues on the same daily cadence.

---

### User Story 2 - Free Users Are Undisturbed; Premium Users Manage Their Own Add-On (Priority: P2)

MCP Manager offers three connection paths for AI clients: (1) `tab=npm` for Claude Desktop / npm-style manual config, (2) `tab=clients` for other MCP-client manual configs, and (3) `tab=ai-connectors` for the OAuth click-to-connect flow (Claude Web, ChatGPT connectors, Grok). Paths (1) and (2) ship with mcp-manager and work standalone. Path (3) is the premium `acrossai-ai-connectors` add-on. After this migration, path (3) moves out of mcp-manager entirely; paths (1) and (2) are unchanged.

The system MUST correctly serve two distinct user populations after the update:

- **Free users** (never used OAuth): update mcp-manager, everything continues to work via npm/clients tabs. The AI Connectors tab simply does not appear in the UI. No admin notice, no configuration change.
- **Premium users** (previously used OAuth, have the add-on installed): update mcp-manager AND ensure the AI Connectors add-on is present + active. If the add-on is missing, OAuth requests will return 401 until it is (re)installed. Because the plugin has no external users yet (see 2026-07-31 Q6), no automated admin-notice safety net is provided — the operator is responsible for their own coordinated update.

**Why this priority**: Free users must not see scary warnings for a feature they never opted into. Premium users are, by construction of the current install base, the plugin author who knows their own state.

**Independent Test**:
- Free-user path: On a site with an empty `wp_acrossai_mcp_oauth_tokens` table (no OAuth usage) and no AI Connectors add-on installed, update mcp-manager. Visit `/wp-admin/`. Assert no admin notice appears, no PHP fatals, and the `tab=npm` / `tab=clients` tabs render normally. Assert the AI Connectors tab does NOT appear in the tab list.
- Premium-user-seamless path: On a site with ≥1 row in `wp_acrossai_mcp_oauth_tokens` and the AI Connectors add-on active, update mcp-manager. Verify existing bearer tokens still authenticate against `/wp-json/acrossai-mcp-manager/v1/mcp/<slug>`.

**Acceptance Scenarios**:

1. **Given** a free-user site with no rows in `wp_acrossai_mcp_oauth_tokens` and no AI Connectors add-on, **When** the site administrator updates mcp-manager, **Then** mcp-manager activates successfully, no admin notice appears, and the npm/clients tabs continue to work.
2. **Given** a premium-user site with prior OAuth activity and the AI Connectors add-on active, **When** the site administrator updates mcp-manager, **Then** no admin notice appears and existing bearer tokens continue to authenticate.
3. **Given** a premium-user site with prior OAuth activity but the add-on inactive, **When** the site administrator visits any wp-admin page, **Then** mcp-manager still activates standalone (no fatal, no admin notice — per Q6, no notice is built), and OAuth REST requests return 401 until the add-on is (re)installed. The operator is responsible for noticing and remediating this.
4. **Given** the AI Connectors add-on is active and declares `Requires Plugins: acrossai-mcp-manager`, **When** the site administrator attempts to deactivate mcp-manager from the plugins UI, **Then** WordPress core prevents the deactivation (add-on requires parent). The mcp-manager plugin itself does NOT declare `Requires Plugins: acrossai-ai-connectors` — mcp-manager remains freely deactivatable/activatable standalone.

---

### Edge Cases

- **What happens when a free user (no OAuth usage) updates mcp-manager without the AI Connectors add-on installed?** Nothing unusual: mcp-manager activates standalone (it does NOT declare `Requires Plugins: acrossai-ai-connectors`), the npm/clients tabs continue to work, no admin notice fires (per Q6, no notice is built). The AI Connectors tab simply does not appear in the tab list.
- **What happens when a premium user with prior OAuth data updates mcp-manager but AI Connectors is missing?** mcp-manager still activates standalone (no hard dependency, no fatal). Existing bearer tokens will 401 until the add-on is (re)installed. No automated warning is provided — the operator is responsible for noticing this (per Q6, given the plugin has no external users).
- **What happens when a premium user with the AI Connectors add-on active updates mcp-manager?** Seamless — the companion's self-disable probe flips as mcp-manager's `AuthorizationController` disappears, the companion takes over, and the same `.well-known/oauth-authorization-server` endpoints continue to respond. No re-authorization.
- **What happens if an operator uninstalls (not just deactivates) mcp-manager?** `uninstall.php` MUST NOT drop the four OAuth tables (companion owns them) and MUST NOT clear the `acrossai_mcp_manager_oauth_cleanup` cron hook (companion owns it). The `acrossai_mcp_%` options LIKE-sweep is retained for other mcp-manager options but MUST NOT touch `acrossai_mcp_connector_%` keys — the companion writes and reads those.
- **What happens if the AI Connectors add-on is uninstalled while mcp-manager remains active?** The add-on declares `Requires Plugins: acrossai-mcp-manager`, so uninstall works cleanly. The tables and cron event remain (owned by no code path until the add-on is reinstalled). Bearer tokens 401 until reinstall. No admin notice fires (per Q6).
- **What happens if some other plugin `use`s a deleted OAuth or Connectors class?** That plugin fatals on autoload. Since mcp-manager has no third-party ecosystem yet, the only callers to worry about are inside this repo itself — the pre-flight `grep` step (FR-016) catalogues them all before deletion. Every hit is either a stale caller (delete) or part of the tab framework we're preserving (leave). No compat shim is provided (see 2026-07-31 clarification); if a third-party appears in the future, a follow-up feature can add `class_alias` targets.
- **What happens when `.well-known/oauth-authorization-server` is fetched by a cached AI-client discovery?** Same URL, same JSON, same routes — served by the companion plugin under the same REST namespace `acrossai-mcp-manager/v1`. No cache invalidation required on the AI-client side.
- **What happens if BerlinDB's `db_version_key` was left at a different version by the pre-update mcp-manager than the companion declares?** Companion's `Table` subclasses declare identical `$version` and `$db_version_key` values (a hard prerequisite validated in the companion PR); BerlinDB `maybe_upgrade()` becomes a no-op. If the versions ever drift, BerlinDB attempts an ALTER — this is the failure mode the companion PR must prevent.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The plugin MUST remove all PHP source files that implement the OAuth 2.1 authorization server (`includes/OAuth/**`), the Connectors framework (`includes/Connectors/AbstractConnectorProfile.php`, `ConnectorProfileRegistry.php`, `ConnectorSettings.php`), the four OAuth-related BerlinDB modules (`includes/Database/OAuthClients/`, `OAuthTokens/`, `OAuthAuthCodes/`, `ConnectorApprovedUsers/`), the AI Connectors admin tab (`admin/Partials/ServerTabs/AIConnectorsTab.php`), the OAuth consent template (`templates/oauth/consent.php` and enclosing directory if empty), and the ai-connectors frontend build inputs and outputs (`src/js/ai-connectors.js`, `src/scss/ai-connectors.scss`, all `build/{js,css}/ai-connectors.*` artifacts).
- **FR-002**: The plugin MUST NOT drop, alter, rename, or truncate the four underlying database tables (`{prefix}acrossai_mcp_oauth_clients`, `{prefix}acrossai_mcp_oauth_tokens`, `{prefix}acrossai_mcp_oauth_auth_codes`, `{prefix}acrossai_mcp_connector_approved_users`) at any lifecycle point (activation, deactivation, uninstall, upgrade). The companion plugin `acrossai-ai-connectors` now owns these tables with byte-identical Table subclass declarations.
- **FR-003**: The plugin MUST NOT delete option rows matching `acrossai_mcp_connector_%` during uninstall or deactivation. The `acrossai_mcp_%` LIKE-sweep in `uninstall.php` MUST be preserved for non-connector options (settings, feature flags, etc.) — but the retained sweep MUST NOT match `acrossai_mcp_connector_%` (either by narrower LIKE pattern or by explicit exclusion).
- **FR-004**: The plugin MUST NOT rename the cron hook `acrossai_mcp_manager_oauth_cleanup`. `includes/Deactivator.php` MUST retain an unconditional `wp_clear_scheduled_hook( 'acrossai_mcp_manager_oauth_cleanup' )` call — no `class_exists()` guard, no conditional skip when the companion is active. The race scenario (mcp-manager clears a cron the companion still needs) is prevented by WordPress core's `Requires Plugins:` gate: because AI Connectors declares mcp-manager as a required plugin, WordPress refuses to deactivate mcp-manager while the companion is active — so by the time this line runs, the companion has already been deactivated. WP-CLI can bypass the gate; that path is accepted as an operator-managed edge case (no admin-notice safety net is built, per Q6).
- **FR-005**: The plugin MUST NOT change the REST API namespace `acrossai-mcp-manager/v1`. In-field DCR clients depend on `.well-known/oauth-authorization-server` returning URLs under this namespace for RFC 8414 compatibility.
- **FR-006**: `includes/Activator.php` MUST remove the four `<X>Table::instance()->maybe_upgrade()` calls for the OAuth tables, the `wp_schedule_event( ... 'acrossai_mcp_manager_oauth_cleanup' )` block, the `OAuthRouter::register_rewrite_rules()` call, and any now-unused `use` imports.
- **FR-007**: `includes/Main.php` MUST remove all OAuth REST route registrations, the OAuthRouter + Cleanup + TokenValidator + UserLifecycle + BearerChallengeHeader wiring block, the four OAuth Table subclasses from `bootstrap_database_tables()`, and the four OAuth table entries from `reconcile_database_schemas()`. No new `require_once` is added by this feature (the earlier-drafted compat-shim requirement was dropped per the 2026-07-31 clarification).
- **FR-008**: `admin/Main.php` MUST remove the `maybe_enqueue_ai_connectors_app()` method and its call site.
- **FR-009**: `admin/Partials/ServerTabs/Registry.php` MUST remove the built-in `AIConnectorsTab` entry from `all_tabs()` and update the built-in tab count in its accompanying comment (10 → 9). The `acrossai_mcp_manager_server_tabs` filter MUST continue to fire so the companion plugin can register the tab via the existing filter API; the tab framework contract (`AbstractServerTab`, `Registry::all_tabs()` filter behavior) is otherwise untouched.
- **FR-010**: `uninstall.php` MUST remove the four `DROP TABLE` statements targeting OAuth tables and the `wp_clear_scheduled_hook( 'acrossai_mcp_manager_oauth_cleanup' )` line. The `acrossai_mcp_%` options sweep MUST remain (per FR-003 caveat).
- **FR-011**: `webpack.config.js` MUST remove the `'js/ai-connectors'` entry so the ai-connectors bundle is no longer produced by `npm run build`.
- **FR-012**: The plugin bootstrap file `acrossai-mcp-manager.php` MUST bump the plugin `Version:` from `0.1.9` to **`0.2.0`** (SemVer-minor bump during 0.x, signalling a breaking change for premium users who relied on the built-in OAuth path). The plugin MUST NOT add `Requires Plugins: acrossai-ai-connectors` to the header — per the 2026-07-31 Q5 clarification, mcp-manager remains standalone-activatable so free users using only `tab=npm` / `tab=clients` are undisturbed. Dependency direction is one-way: the ai-connectors add-on declares `Requires Plugins: acrossai-mcp-manager` in its own header (already in place per companion audit). `Requires PHP:` and `Requires WP:` header values are unchanged (`8.1` and `7.0`).
- **FR-013**: *(Removed per 2026-07-31 Q6 clarification.)* An earlier draft required a conditional admin-notice callback in `Main.php::load_hooks()` that would fire when prior OAuth data existed AND the ai-connectors add-on was missing. Because the plugin has no external users yet — the sole operator is the plugin author, who manages their own coordinated updates — the notice would defend against a scenario that doesn't exist. FR is intentionally left as a placeholder to preserve numbering. **This feature adds no admin-notice callback and no new hook in `Main.php`.** If external users appear later, the notice can be added in a follow-up feature (the exact logic is captured in Q6 for future reference).
- **FR-014**: *(Removed per 2026-07-31 clarification.)* An earlier draft required a `class_alias` compat shim at `includes/Compat/ConnectorAliases.php` to preserve third-party plugins extending the old `AbstractConnectorProfile` FQN. Because mcp-manager has no third-party ecosystem yet, that shim would defend against a scenario that cannot occur. This FR is intentionally left as a placeholder to preserve numbering; the file MUST NOT be created and the `includes/Compat/` directory MUST NOT exist after this feature.
- **FR-015**: All PHPUnit tests under `tests/phpunit/Includes/OAuth/**`, `tests/phpunit/Includes/Database/OAuthClients/**`, `OAuthTokens/**`, `OAuthAuthCodes/**`, `ConnectorApprovedUsers/**`, `tests/phpunit/Includes/Connectors/**`, and `tests/phpunit/Admin/Partials/ServerTabs/AIConnectorsTabTest.php` MUST be deleted. Their equivalents move to the companion plugin's test suite in the companion PR (out of scope for this feature).
- **FR-016**: A pre-flight `grep` audit (per the callers pattern in the Input) MUST show zero surviving references to any deleted OAuth or Connectors symbol anywhere in `includes/`, `admin/`, `public/`, `acrossai-mcp-manager.php`, `uninstall.php`, or `tests/`. Because the compat shim is removed (former FR-014), there is no exempt file — the grep must return truly zero matches. This is a merge blocker.
- **FR-017**: This feature MUST NOT modify `AbstractServerTab`, the `acrossai_mcp_manager_server_tabs` filter mechanics in `Registry.php`, or the `mcp_servers` table (schema, DDL, or row layout). The tab framework and per-server-tab extension point remain owned by mcp-manager.
- **FR-018**: The pre-existing untracked directory `specs/039-migrate-ai-connectors-to-companion/` (an abandoned prior drafting attempt of the same migration) MUST be deleted as part of this feature. Because 039 was never committed, this is a working-tree-only cleanup with zero git-history impact — no `git rm` required, only `rm -rf`. This prevents `specs/` from containing two sibling directories that appear to describe the same migration.
- **FR-019**: `public/Discovery/ConnectionMethodRegistry.php` MUST be modified to reference the companion plugin's `ConnectorProfileRegistry` class instead of mcp-manager's (which is being deleted). Specifically: the `use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry;` statement (~line 34) and the call site `ConnectorProfileRegistry::instance()->get_profiles()` in `get_ai_connectors()` (~line 215) MUST be updated so that:
  1. If the companion class `\AcrossAI_AI_Connectors\Includes\Connectors\ConnectorProfileRegistry` exists (add-on active), `get_ai_connectors()` returns the same DTO shape it does today, sourced from the companion's registry.
  2. If the companion class does NOT exist (free user, no add-on), `get_ai_connectors()` MUST return an empty array — `[]` — with no fatal error and no `class_exists()` warning. The discovery API's `ai_connector` category simply omits entries. Rationale: this preserves the free-user standalone use case (Q5) without leaking a hard dependency into mcp-manager. Discovered by the pre-flight callers grep (FR-016) during planning; this is the ONE caller outside the OAuth/Connectors/AIConnectorsTab deletion scope that needs to follow.

### WordPress Requirements

**PHP Version**: PHP 8.0+ (constitution target; plugin still declares 8.1 minimum in its header).
**WordPress Version**: 6.9+ (plugin's declared minimum). No `Requires Plugins:` header is added by this feature — the ai-connectors add-on is optional (see Optional Integrations below).
**Multisite**: Single-site only (matches current plugin configuration; multisite migration is out of scope).
**Required Plugins / Packages**: **None.** mcp-manager remains standalone-activatable after this migration.
**Optional Integrations**: `acrossai-ai-connectors` — a premium add-on that provides the OAuth click-to-connect flow (Claude Web, ChatGPT, Grok connectors). When present, the add-on injects an "AI Connectors" tab via the `acrossai_mcp_manager_server_tabs` filter and registers OAuth routes under mcp-manager's REST namespace. When absent, mcp-manager continues to work standalone via the `tab=npm` and `tab=clients` connection tabs — free users see no change and no admin notice.

### Module Placement

**PHP Class(es)**: This feature adds NO new PHP class files. It is pure deletions + modifications to existing files. (Earlier drafts added a compat-shim file at `includes/Compat/ConnectorAliases.php`; that requirement was removed per 2026-07-31 Q4 — see former FR-014.)

**Hook Registration**: This feature adds NO new hooks. (An earlier draft wired a conditional admin-notice callback inside `Main.php::load_hooks()`; that requirement was removed per 2026-07-31 Q6 — see former FR-013.) The constitution rule "only `Main.php` calls `add_action`/`add_filter`" therefore has no new work to do for this feature — existing hook removals inside `Main.php` still count as modifications, not additions.

### Admin UI Requirements

<!-- No new admin UI is added. The AI Connectors tab is removed from mcp-manager's built-in Registry (companion registers its replacement via the existing filter). No DataForm/DataViews change, no admin notice. -->

No new admin UI, no admin notices, no DataForm/DataViews work. (An earlier draft added a conditional admin notice via FR-013; that was removed per 2026-07-31 Q6.)

### REST API Contract

<!-- This feature REMOVES REST routes. It does not add any. -->

Routes REMOVED from mcp-manager (all continue to be served under the same namespace by the companion plugin):

| Method | Route (removed from mcp-manager, served by companion) | Auth |
|--------|-------------------------------------------------------|------|
| `GET`  | `/wp-json/acrossai-mcp-manager/v1/oauth/authorize`   | `manage_options` (browser session) |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/oauth/token`       | `__return_true` (validates PKCE + client_secret in body) |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/oauth/register`    | `__return_true` (public DCR endpoint) |
| `GET`  | `/wp-json/acrossai-mcp-manager/v1/oauth/discovery`   | `__return_true` (public RFC 8414) |
| `GET`  | `/wp-json/acrossai-mcp-manager/v1/oauth/connector-admin/*` | `manage_options` |

**`permission_callback` rule**: Not applicable here — this feature only DELETES route registrations. Every deletion is a net-neutral for mcp-manager (the routes now live in the companion under the same URLs and same permission checks).

### Database / Storage

**No schema changes.** This feature explicitly does NOT create, alter, drop, rename, or truncate any table. The four OAuth-related tables are preserved verbatim under companion ownership:

- `{wpdb->prefix}acrossai_mcp_oauth_clients`
- `{wpdb->prefix}acrossai_mcp_oauth_tokens`
- `{wpdb->prefix}acrossai_mcp_oauth_auth_codes`
- `{wpdb->prefix}acrossai_mcp_connector_approved_users`

BerlinDB `db_version_key` values are preserved. The companion plugin's `Table` subclasses declare identical `$name`, `$version`, and `$db_version_key`, which makes `maybe_upgrade()` a no-op under companion ownership.

Options preserved verbatim under companion ownership: `acrossai_mcp_connector_%`.

### Security Checklist

*(This feature is a pure code-removal migration and adds ZERO new code — no admin notice, no compat shim. Almost every item is N/A.)*

- [ ] No new form/AJAX handlers introduced — nonces N/A for this feature.
- [ ] No new capability checks needed — no new admin pages, no new REST routes, no new mutations, no new admin notices.
- [ ] No user input sanitization changes — no new input surfaces.
- [ ] No new DB queries — no `wpdb->prepare` concerns.
- [ ] No new output rendering — no `esc_html*` concerns.
- [ ] OAuth token storage unchanged — hashed storage (SHA-256) remains in place, now owned by the companion.
- [ ] Pre-flight callers grep (FR-016) completed with truly zero surviving references — no exempt file — verified before merge.

### Key Entities *(preserved as-is; documented for cross-plugin invariant clarity)*

- **OAuth Client**: `{prefix}acrossai_mcp_oauth_clients` — client_id, client_secret_hash, redirect_uris, connector_slug, etc. Written by companion, read by companion. **Not touched by this feature.**
- **OAuth Access Token / Refresh Token**: `{prefix}acrossai_mcp_oauth_tokens` — token_hash (SHA-256), user_id, client_id, scope, expires_at. Written by companion, validated by companion via TokenValidator. **Not touched by this feature.**
- **OAuth Authorization Code**: `{prefix}acrossai_mcp_oauth_auth_codes` — code_hash, client_id, user_id, code_challenge, code_challenge_method, expires_at (short-lived, ~10min). Written by companion. **Not touched by this feature.**
- **Connector-Approved User**: `{prefix}acrossai_mcp_connector_approved_users` — per-connector explicit user allowlist for the "explicit allowlist" access-control mode. Read by companion at auth-code exchange. **Not touched by this feature.**

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`)
- [ ] PHPStan level 8: zero errors (`vendor/bin/phpstan`)
- [ ] ESLint: zero errors on the remaining src/js after `src/js/ai-connectors.js` removal (`npm run lint:js`)
- [ ] `composer test` — remaining PHPUnit suite passes (all OAuth/Connectors tests deleted per FR-015)
- [ ] `composer dump-autoload` — succeeds with zero warnings after class deletions
- [ ] `npm run build` — succeeds without producing `build/*/ai-connectors.*` (webpack entry removed per FR-011)
- [ ] `npm run validate-packages` passes
- [ ] Pre-flight grep (FR-016) returns truly zero matches — no exempt file
- [ ] Security checklist above: all applicable items verified
- [ ] `git ls-tree HEAD` on each deleted path returns nothing (verifies deletion is committed, not just staged)
- [ ] Companion plugin `acrossai-ai-connectors` at v0.5.0+ is deployable to production BEFORE this PR merges (coordination invariant — releasing mcp-manager without a deployable companion opens a request window during which OAuth is owned by nobody)
- [ ] No hooks wired outside `Main.php` (constitution rule — vacuously satisfied: this feature adds no new hooks anywhere, per Q6 removing the former admin-notice hook)

### Measurable Outcomes

- **SC-001**: **Zero OAuth-authenticated MCP requests fail** on a production site after the update, given both plugins active. Measured by: same pre-migration bearer token → HTTP 200 on `/wp-json/acrossai-mcp-manager/v1/mcp/<slug>` after migration.
- **SC-002**: **Zero AI clients require re-authorization.** Measured by: no client_id from `wp_acrossai_mcp_oauth_clients` disappears, no bearer token in `wp_acrossai_mcp_oauth_tokens` is invalidated, discovery JSON at `/.well-known/oauth-authorization-server` is byte-identical (or semantically equivalent per RFC 8414) to pre-migration.
- **SC-003**: **Zero database rows lost.** Measured by: `SELECT COUNT(*)` on each of the four preserved tables returns the same value pre- and post-migration, and `SHOW CREATE TABLE` returns identical DDL.
- **SC-004**: **Fewer than 60 seconds of admin friction.** A site administrator with both plugins already installed completes the update (WP → Plugins → Update) in under one minute with no additional configuration screens or migration wizards.
- **SC-005**: *(Removed per 2026-07-31 clarification.)* The former measurable outcome for third-party BC via `class_alias` is no longer applicable — mcp-manager has no third-party plugin ecosystem that could extend `AbstractConnectorProfile`.
- **SC-006**: *(Removed per 2026-07-31 Q5 clarification.)* The former "mcp-manager cannot be activated without the companion" criterion is invalid — mcp-manager remains standalone-activatable so free users can keep using the npm/clients tabs without the paid add-on.
- **SC-007**: *(Removed per 2026-07-31 Q6 clarification.)* The former "FR-013 admin notice fires only for premium users who have lost their OAuth stack" criterion is moot because FR-013 was itself removed — the plugin has no external users yet, so the notice defends against a scenario that doesn't exist.

---

## Assumptions

- **MCP Manager has three parallel connection paths**, only one of which is affected by this feature:
  1. `?page=acrossai_mcp_manager&...&tab=npm` — manual JSON config for Claude Desktop / npm-style clients (**free**, untouched).
  2. `?page=acrossai_mcp_manager&...&tab=clients` — manual config for other MCP clients (**free**, untouched).
  3. `?page=acrossai_mcp_manager&...&tab=ai-connectors` — OAuth click-to-connect (Claude Web, ChatGPT, Grok) (**paid add-on**, moves out of mcp-manager to `acrossai-ai-connectors`).

  Free users depend on paths (1) and (2). Only path (3) requires the add-on.

- **Dependency direction is one-way.** ai-connectors declares `Requires Plugins: acrossai-mcp-manager` (add-on needs parent's tab framework and REST plumbing). mcp-manager does NOT declare `Requires Plugins: acrossai-ai-connectors` (per Q5 clarification) — the add-on is a premium upsell, not a hard dependency. Consequence: no circular-dependency situation to worry about.

- The companion plugin `acrossai-ai-connectors` at v0.5.0 has been **audited** (see `specs/040-.../checklists/requirements.md` Companion Plugin Audit section) and satisfies all 23 coordination invariants: byte-identical BerlinDB Table subclasses, same REST namespace `acrossai-mcp-manager/v1`, same cron name `acrossai_mcp_manager_oauth_cleanup`, injects AI Connectors tab via `acrossai_mcp_manager_server_tabs` filter, and has the self-disable probe (`if ( class_exists( '\AcrossAI_MCP_Manager\Includes\OAuth\AuthorizationController' ) ) { return; }`) wired into 6 bootstrap paths. **This feature deleting `AuthorizationController.php` is the atomic event that flips OAuth ownership from mcp-manager to companion.**

- The AI Connectors tab URL `?page=acrossai_mcp_manager&action=edit&server=<id>&tab=ai-connectors` resolves as follows after the migration: if the add-on is active, `Registry.php` fires `apply_filters( 'acrossai_mcp_manager_server_tabs', $tabs )`, the companion's filter callback registers its `AIConnectorsTab`, and the tab renders. If the add-on is not active, the URL 404s within the tab framework (no such tab in the registry) — but the URL is only ever advertised by the add-on's UI, so free users never see or hit it.

- Bearer tokens are stored as SHA-256 hashes with the plaintext token never persisted. The token validator uses `hash_equals` for constant-time comparison. Both properties are preserved unchanged under companion ownership.

- No data migration script is required or wanted. Cross-plugin table ownership handoff via `class_exists()` probe is atomic and requires zero row movement. This is the durable lesson that goes to WORKLOG on merge.

- The existing `specs/039-migrate-ai-connectors-to-companion/` directory (untracked on `main`) is a previous drafting attempt that was abandoned before commit. This feature at spec number 040 supersedes it; per the 2026-07-31 clarification, deleting `specs/039-...` is now IN SCOPE for this feature — see FR-018.
