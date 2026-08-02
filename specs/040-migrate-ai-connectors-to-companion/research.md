# Research: Migrate AI Connectors + OAuth Stack to Companion Plugin

**Feature**: 040-migrate-ai-connectors-to-companion
**Phase**: 0 (Outline & Research)
**Date**: 2026-07-31
**Status**: Complete — all `[NEEDS CLARIFICATION]` markers resolved via the 6-question Clarify session and 2 companion audits

## Summary

This is a subtractive migration with **zero unresolved unknowns** at planning time. All branching decisions were closed during the Clarify session (Q1..Q6). The companion plugin was audited twice (structural readiness + wiring counterparts) with a combined 44/44 PASS. One caller outside the deletion/modification scope was surfaced by the pre-flight grep and captured as FR-019.

---

## Decision 1 — Version bump target

- **Decision**: Bump `Version:` from `0.1.9` → `0.2.0`.
- **Rationale**: SemVer during 0.x — any breaking change bumps the minor. Removing the built-in OAuth stack is a breaking change for premium users who relied on it. `0.1.10` would misleadingly signal a patch bump; `1.0.0` would prematurely commit to API stability.
- **Alternatives considered**: `0.1.10` (patch — rejected, misrepresents scope); `1.0.0` (major stability commitment — premature).
- **Source**: Clarify Q1 (2026-07-31).

## Decision 2 — Deactivator cron-clear behavior

- **Decision**: `wp_clear_scheduled_hook( 'acrossai_mcp_manager_oauth_cleanup' )` in `Deactivator.php` runs **unconditionally**. No `class_exists()` guard.
- **Rationale**: The race scenario (mcp-manager clears a cron the companion still needs) cannot occur under normal admin flow because AI Connectors declares `Requires Plugins: acrossai-mcp-manager` — WordPress core refuses to deactivate mcp-manager while the companion is active. WP-CLI can bypass the gate; the resulting harmless one-tick gap is accepted (per Q6, no admin-notice safety net is built).
- **Alternatives considered**: `class_exists()`-guarded clear (extra code protecting against an impossible scenario); remove the clear entirely (orphaned cron risk if companion is uninstalled first).
- **Source**: Clarify Q2 (2026-07-31).

## Decision 3 — Fate of untracked `specs/039-migrate-ai-connectors-to-companion/` directory

- **Decision**: Delete via `rm -rf` as part of this feature (FR-018).
- **Rationale**: Was never committed to git (working-tree-only). Keeping it invites reviewer confusion about which spec directory is authoritative for the migration. Deletion is a zero-history-impact cleanup.
- **Alternatives considered**: Leave untouched (messy); rename to `-abandoned/` suffix (unusual convention).
- **Source**: Clarify Q3 (2026-07-31).

## Decision 4 — `class_alias` compat shim for third-party plugins

- **Decision**: NOT built. FR-014 is a removed-placeholder. `includes/Compat/` directory MUST NOT exist after this feature.
- **Rationale**: mcp-manager has no third-party plugin ecosystem yet — nobody is extending `AbstractConnectorProfile` under the old namespace. Building a compat layer for a non-existent user population is dead code. Can be added in a follow-up feature if third parties appear.
- **Alternatives considered**: Build the shim as originally specified in the Input (rejected as YAGNI); alias only 1-2 of the 3 classes (rejected — no principled subset).
- **Source**: Clarify Q4 (2026-07-31).

## Decision 5 — `Requires Plugins:` header direction

- **Decision**: mcp-manager does **NOT** declare `Requires Plugins: acrossai-ai-connectors`. The companion declares `Requires Plugins: acrossai-mcp-manager` in its own header (already in place per audit). Direction is one-way (add-on → parent only).
- **Rationale**: MCP Manager offers three connection paths: `tab=npm`, `tab=clients` (both free, standalone), and `tab=ai-connectors` (paid add-on). If mcp-manager declared the add-on as required, every free user's install would break. The `ai-connectors` add-on is a premium upsell for the OAuth click-to-connect flow (Claude Web, ChatGPT connectors, Grok), NOT a hard dependency.
- **Alternatives considered**: Mutual `Requires Plugins:` (rejected — breaks free tier); no `Requires Plugins:` on either side (rejected — add-on genuinely needs the parent's tab framework).
- **Source**: Clarify Q5 (2026-07-31) — this was a scope-corrective clarification after the user revealed the free-vs-premium tab architecture.

## Decision 6 — Admin-notice safety net for premium users who lose the add-on

- **Decision**: NOT built. FR-013 is a removed-placeholder. This feature adds **zero new code** — pure deletions + a header version bump.
- **Rationale**: mcp-manager has a single known operator (the plugin author) who manages their own coordinated updates. An automated warning is defense-in-depth against a scenario that doesn't exist. If external users appear later, the notice logic (both-conditions: prior OAuth data exists AND companion class absent) can be added in a follow-up feature — the design is captured in Q6 for reference.
- **Alternatives considered**: Build the conditional notice as originally spec'd (rejected as YAGNI); unconditional notice fires whenever the add-on is missing (rejected — would scare free users).
- **Source**: Clarify Q6 (2026-07-31).

## Decision 7 — Deletion ordering strategy

- **Decision**: Execute deletions and modifications in this order across task-level phases:
  1. **Pre-flight** — run the FR-016 callers grep, capture baseline. (Done in Phase 0; see finding at end of this doc.)
  2. **Test deletions first** — nothing depends on `tests/phpunit/OAuth/**` etc., so they go first with zero risk.
  3. **BerlinDB module deletions** (`includes/Database/OAuth*/**` and `.../ConnectorApprovedUsers/**`) — these have no callers outside the OAuth stack itself once that stack is gone. So they must go AFTER OAuth PHP class deletions OR SIMULTANEOUSLY with them.
  4. **OAuth PHP class deletions** (`includes/OAuth/**`) — deletes the entire directory. After this, `bootstrap_database_tables()` in `Main.php` (still holding references) will fatal until the corresponding MODIFY completes.
  5. **Connectors PHP class deletions** (`includes/Connectors/**`).
  6. **Admin tab deletion** (`admin/Partials/ServerTabs/AIConnectorsTab.php`).
  7. **Frontend asset deletions** (`src/js/ai-connectors.js`, `src/scss/ai-connectors.scss`, `build/*/ai-connectors.*`).
  8. **Template + directory deletions** (`templates/oauth/`).
  9. **Wiring file modifications** (`Activator.php`, `Deactivator.php`, `Main.php`, `admin/Main.php`, `Registry.php`, `uninstall.php`, `webpack.config.js`, `public/Discovery/ConnectionMethodRegistry.php`) — these must land in the same commit or PR as the deletions they mirror; otherwise the plugin is broken between commits.
  10. **Header version bump** (`acrossai-mcp-manager.php`).
  11. **Spec directory cleanup** (`rm -rf specs/039-...`).
  12. **Post-flight** — re-run the FR-016 callers grep. Expected: zero hits anywhere in `includes/`, `admin/`, `public/`, `tests/`, `acrossai-mcp-manager.php`, `uninstall.php`. Merge blocker.

- **Rationale**: Keeps the codebase in a compilable/testable state at each commit boundary. Because deletions and modifications MUST land atomically (Main.php cannot reference deleted classes even for one commit), the practical unit of work in `/speckit-implement` will be "one commit per functional group" — e.g. one commit for the entire OAuth extraction, not 20 tiny per-file commits.
- **Alternatives considered**: File-by-file commits (rejected — leaves the plugin unbuildable between commits); one giant commit (rejected — harder to review and revert).

## Companion Readiness — Cross-Audit Synthesis

Two audits of `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-ai-connectors` at commit-time state:

### Audit 1: Structural readiness (23 invariants, all PASS)

Plugin header (v0.5.0, `Requires Plugins: acrossai-mcp-manager`), namespace autoload, self-disable probe (`mcp_manager_still_owns_oauth()` wired to 6 bootstrap paths), byte-identical BerlinDB Table declarations (name/version/db_version_key), REST namespace preservation, cron hook name preservation, tab filter registration at priority 35, class-alias targets exist (for the shim we decided NOT to build), all 17 OAuth classes present, AIConnectorsTab present, frontend assets migrated, template migrated, no illicit MCP Manager OAuth namespace references.

### Audit 2: Wiring counterparts (21 checks, all PASS)

`Activator.php` — cron scheduling, 4-table `maybe_upgrade()`, probe guard, rewrite rules. `Deactivator.php` — cron clear, probe guard, no table drops. `Main.php` — 5 REST controllers, OAuth infrastructure block, 4-table bootstrap, cron callback. `admin/Main.php` — `maybe_enqueue_ai_connectors_app` method, probe guard, tab-specific enqueue. `Registry.php` (via filter) — tab registration confirmed. `uninstall.php` — dual-gated table drops, cron clear, options sweep. `webpack.config.js` — `js/ai-connectors` entry + built artifacts on disk.

### Conclusion

**Zero blockers.** The companion is production-deployable. The coordination invariant in the spec's Assumptions section ("MUST NOT merge Feature 040 until the companion PR is deployable") is satisfied at the time of this plan.

One non-blocking note from Audit 2: `Main::reconcile_database_schemas()` is referenced in ClientRegistrationController comments but doesn't exist as a standalone method. Race protection is provided by `ClientsQuery::server_id_column_exists()` (direct column introspection). Stale doc comment on the companion side, not a functional issue.

## Pre-Flight Callers Grep — Findings

The FR-016 grep was executed against `HEAD` on branch `040-migrate-ai-connectors-to-companion`:

```bash
grep -rEn '(new (Authorization|Token|ClientRegistration|ConnectorAdmin|Discovery)Controller|\
  use .*(AuthorizationController|TokenController|ClientRegistrationController|ConnectorAdminController|\
  DiscoveryController|OAuthRouter|PKCE|Cleanup|TokenValidator|BearerChallengeHeader|UserLifecycle|\
  AccessTokenRepository|RefreshTokenRepository|ClientRepository|AuthCodeRepository|ScopeRepository|\
  SecretsVault|RateLimiter|AbstractConnectorProfile|ConnectorProfileRegistry|ConnectorSettings|\
  AIConnectorsTab|OAuthClients|OAuthTokens|OAuthAuthCodes|ConnectorApprovedUsers))' \
  --include='*.php' \
  includes/ admin/ public/ acrossai-mcp-manager.php uninstall.php tests/
```

### Categorized results

**Category A — Inside deletion-scope files** (expected, will disappear with the file):

- `includes/OAuth/**` — every internal `use` between OAuth classes. Vanishes when the directory is deleted.
- `includes/Connectors/**` — every `use` between the 3 Connector classes. Vanishes.
- `includes/Database/OAuthClients/**`, `OAuthTokens/**`, `OAuthAuthCodes/**`, `ConnectorApprovedUsers/**` — internal `use`s inside Row/Query/Schema/Table siblings. Vanishes.
- `admin/Partials/ServerTabs/AIConnectorsTab.php` — 3 `use` statements. Vanishes.
- `tests/phpunit/OAuth/**` + `tests/phpunit/Database/OAuth*/**` + `.../ConnectorApprovedUsers/**` — dozens of `use` statements. All test files are deleted per FR-015.

**Category B — Inside modification-scope files** (expected, will be edited to remove):

- `includes/Main.php:270` — comment `"OAuthClients MUST fire FIRST because OAuthTokens + OAuthAuthCodes JOIN backfill"`. Goes away when the surrounding `bootstrap_database_tables()` OAuth-table entries are removed.
- `includes/Activator.php:10-13` — 4 `use` statements for OAuth-table subclasses. Removed per plan.

**Category C — Callers OUTSIDE deletion/modification scope** (SURFACE FINDING — added to plan):

- `public/Discovery/ConnectionMethodRegistry.php:34` — `use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry;`
- `public/Discovery/ConnectionMethodRegistry.php:215` — `$profiles = ConnectorProfileRegistry::instance()->get_profiles();`

**This file was NOT originally listed in the Input's TASK list or my initial MODIFY set.** It's part of Feature 035 (public connection-method discovery API) and uses `ConnectorProfileRegistry` to enumerate AI connectors for the `/wp-json/acrossai-mcp-manager/v1/discovery/methods` endpoint.

- **Impact if untreated**: PHP fatal on any call to `ConnectionMethodRegistry::get_ai_connectors()` after mcp-manager's `Connectors/` directory is deleted.
- **Resolution**: Added as FR-019 to spec. `public/Discovery/ConnectionMethodRegistry.php` added to plan's MODIFY list. Fix approach: swap the `use` FQN from `AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry` to `AcrossAI_AI_Connectors\Includes\Connectors\ConnectorProfileRegistry`, wrap the `get_profiles()` call in `class_exists( ..., false )` guard, return empty array when companion is absent (preserves Q5 free-user standalone contract).

No other Category C hits exist. The pre-flight grep is now fully mapped.

## Post-Flight Verification

After all deletions + modifications are applied, re-running the same grep MUST return zero matches. This is a DoD gate (per FR-016). Any surviving hit is a merge blocker — either a stale caller inside a should-have-been-modified file, or a caller in a file not yet in the plan.
