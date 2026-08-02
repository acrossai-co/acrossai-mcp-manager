# Implementation Plan: Migrate AI Connectors + OAuth Stack to Companion Plugin

**Branch**: `040-migrate-ai-connectors-to-companion` | **Date**: 2026-07-31 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/040-migrate-ai-connectors-to-companion/spec.md`

## Summary

Pure code-removal migration: delete the OAuth 2.1 authorization server, Connectors framework, 4 BerlinDB OAuth-table modules, AI Connectors admin tab, associated frontend assets + template, and all their PHPUnit tests from `acrossai-mcp-manager` (mcp-manager). Modify the seven wiring files (`Activator.php`, `Deactivator.php`, `Main.php`, `admin/Main.php`, `Registry.php`, `uninstall.php`, `webpack.config.js`) to unwire what was deleted. Bump the plugin `Version:` header from `0.1.9` to `0.2.0`. Also delete the abandoned `specs/039-migrate-ai-connectors-to-companion/` working-tree-only directory (FR-018).

**Zero new files. Zero new hooks. Zero new UI. Zero data migration.** All four OAuth tables, the `acrossai_mcp_connector_%` options, the cron hook name `acrossai_mcp_manager_oauth_cleanup`, and the REST namespace `acrossai-mcp-manager/v1` are preserved verbatim — the companion plugin `acrossai-ai-connectors` v0.5.0 (audited 44/44 checks across two audits) already owns them with byte-identical BerlinDB declarations and matching wiring counterparts.

The migration is atomic: the moment `includes/OAuth/AuthorizationController.php` disappears, the companion's `mcp_manager_still_owns_oauth()` probe (wired into 6 companion bootstrap paths) flips from `true` to `false` and the companion takes over on the next request — no coordination window, no re-authorization, no data movement.

## Technical Context

**Language/Version**: PHP 8.1+ (per plugin header `Requires PHP: 8.1`) and `@wordpress/scripts` toolchain for the webpack build.
**Primary Dependencies**: WordPress 6.9+ (per plugin header `Requires WP: 7.0`, which is above the 6.5 minimum needed for `Requires Plugins:`). Composer autoload PSR-4 under namespace root `AcrossAI_MCP_Manager\`. No dependency changes from this feature.
**Storage**: 4 BerlinDB-managed OAuth tables + `acrossai_mcp_connector_%` options are **preserved** (companion owns them post-migration). The `mcp_servers` table and all other mcp-manager tables/options are **untouched**.
**Testing**: PHPUnit for PHP, Jest for JS. This feature deletes all OAuth/Connectors/AIConnectorsTab-related tests (per FR-015). Remaining suites (`tests/phpunit/Includes/**` minus deleted paths, `tests/phpunit/Admin/**` minus deleted paths, `tests/jest/**`) must continue to pass.
**Target Platform**: WordPress admin on Linux/macOS/Windows web hosts. Single-site only (multisite explicitly out of scope per WordPress Requirements section in spec).
**Project Type**: WordPress plugin (existing codebase — this feature is a subtractive change, not new project scaffolding).
**Performance Goals**: N/A for pure deletion — measured by the SC gates (sub-60s admin friction, zero OAuth request failures for premium users with add-on, zero fatals for free users without add-on).
**Constraints**:

- **MUST NOT drop the 4 preserved tables** at any lifecycle point (activation, deactivation, uninstall) — companion owns them under identical `$name` / `$version` / `$db_version_key`.
- **MUST NOT delete `acrossai_mcp_connector_%` option rows** — the retained `acrossai_mcp_%` LIKE-sweep in `uninstall.php` must not match these keys.
- **MUST NOT rename the cron hook** `acrossai_mcp_manager_oauth_cleanup` — companion re-schedules under this exact name.
- **MUST NOT change the REST namespace** `acrossai-mcp-manager/v1` — in-field DCR clients depend on RFC 8414 discovery URLs under this namespace.
- **MUST NOT modify `AbstractServerTab`, the `acrossai_mcp_manager_server_tabs` filter mechanics in `Registry.php`, or the `mcp_servers` table** (FR-017).
- **MUST NOT add `Requires Plugins: acrossai-ai-connectors` to the mcp-manager header** (per Q5) — mcp-manager stays standalone-activatable for free users on the npm/clients tabs.
- **MUST NOT add any admin-notice callback** (per Q6) — no external users to defend against misconfiguration.
- **MUST NOT introduce a compat shim** at `includes/Compat/ConnectorAliases.php` (per Q4) — no third-party ecosystem to preserve BC for.

**Scale/Scope**: ~35 file deletions, 7 file modifications, 1 header version bump, 1 working-tree directory deletion. Net LOC change is deeply negative (removing ~5000+ lines of PHP + JS + tests). No new code is added.

## Constitution Check

*GATE evaluated 2026-07-31 against constitution v1.1.0.*

| Principle | Applicability | Verdict |
|-----------|---------------|---------|
| **I. Modular Architecture** | The OAuth / Claude Connectors "5th active area" documented in the constitution rationale is the exact thing being removed here (constitution already references its historical status per F016). This feature completes the retirement pattern — no new modules introduced. | ✅ PASS |
| **II. WordPress Standards Compliance** | No new code added, so no new WPCS/PHPStan/ESLint surfaces. Existing deletions cannot introduce standards violations. The pre-flight grep (FR-016) plus DoD PHPStan/PHPCS gates catch any stragglers left in unmodified files. | ✅ PASS |
| **III. Security First (NON-NEGOTIABLE)** | No new input surfaces, no new REST routes, no new forms/AJAX, no new capability boundaries, no new admin notices. OAuth token storage (SHA-256 hashed, `hash_equals` validation) is preserved unchanged under companion ownership. The five conditions of the consent-surface exception continue to hold for the OAuth consent page (now served by the companion). No security regressions possible. | ✅ PASS |
| **IV. User-Centric Design (NON-NEGOTIABLE)** | No new admin UI. The AI Connectors tab is REMOVED from mcp-manager's built-in `Registry::all_tabs()` (10 → 9), but the `acrossai_mcp_manager_server_tabs` filter is preserved so the companion injects its replacement via `add_filter(..., 35, 2)`. Both pre-approved exceptions (parent menu WP_List_Table + Connector picker card layout) survive: parent menu stays in mcp-manager; card layout moves wholesale to the companion. No DataViews/DataForm conversions needed. | ✅ PASS |
| **V. Extensibility Without Core Modification** | The migration IS the extension pattern — a formerly-bundled subsystem becomes an optional add-on that plugs in via the pre-existing filter API. Removing the built-in tab entry from `Registry::all_tabs()` doesn't modify the extension mechanism, only removes a hardcoded consumer of it. Integration remains optional (FR-012 Q5 clarification: add-on is not a hard dependency; free users unaffected). | ✅ PASS |
| **VI. Reusability & DRY** | No new code, so no duplication risk. Deletions cannot violate DRY. `npm run validate-packages` DoD gate covers the webpack change. | ✅ PASS |
| **VII. Definition of Done** | All DoD gates apply as usual — PHPCS, PHPStan L8, ESLint, PHPUnit, `npm run validate-packages`. This spec's FR-016 (pre-flight grep with zero-hit requirement) is an additional gate. See Definition of Done Gates in spec.md for the full list. | ✅ PASS (gates deferred to `/speckit-tasks` + `/speckit-implement`) |

**No violations. No entries in the Complexity Tracking table below. No justifications required.**

## Project Structure

### Documentation (this feature)

```text
specs/040-migrate-ai-connectors-to-companion/
├── plan.md                     # This file (/speckit-plan output)
├── spec.md                     # Feature spec (6-clarification Q&A applied)
├── research.md                 # Phase 0 output — companion-readiness audit synthesis
├── data-model.md               # Phase 1 output — preserved-invariant reference
├── quickstart.md               # Phase 1 output — verification recipes
├── contracts/                  # Phase 1 output — migrated-away REST routes catalogue
│   └── removed-rest-routes.md
├── checklists/
│   └── requirements.md         # Spec-quality checklist (Clarify session summary + audit summary)
└── tasks.md                    # Phase 2 output (NOT created by /speckit-plan; produced by /speckit-tasks)
```

### Source Code (repository root)

This feature makes changes ONLY to the following paths in the mcp-manager repo. Each entry is annotated with its lifecycle:

```text
acrossai-mcp-manager.php                                        # MODIFY — bump Version 0.1.9 → 0.2.0
includes/
├── Activator.php                                               # MODIFY — remove 4 OAuth maybe_upgrade() + cron schedule + rewrite-rules registration
├── Deactivator.php                                             # MODIFY — retain unconditional wp_clear_scheduled_hook (FR-004)
├── Main.php                                                    # MODIFY — remove OAuth REST route registrations, OAuth infra block, 4 OAuth Tables from bootstrap/reconcile
├── OAuth/                                                      # DELETE — entire directory (18 files)
│   ├── AuthorizationController.php
│   ├── TokenController.php
│   ├── ClientRegistrationController.php
│   ├── ConnectorAdminController.php
│   ├── DiscoveryController.php
│   ├── OAuthRouter.php
│   ├── PKCE.php
│   ├── Cleanup.php
│   ├── TokenValidator.php
│   ├── BearerChallengeHeader.php
│   ├── UserLifecycle.php
│   ├── Security/RateLimiter.php
│   ├── Security/SecretsVault.php
│   └── Repositories/
│       ├── AccessTokenRepository.php
│       ├── RefreshTokenRepository.php
│       ├── ClientRepository.php
│       ├── AuthCodeRepository.php
│       └── ScopeRepository.php
├── Connectors/                                                 # DELETE — entire directory (3 files)
│   ├── AbstractConnectorProfile.php
│   ├── ConnectorProfileRegistry.php
│   └── ConnectorSettings.php
└── Database/
    ├── OAuthClients/                                           # DELETE — entire directory (4 files: Schema, Table, Query, Row)
    ├── OAuthTokens/                                            # DELETE — entire directory
    ├── OAuthAuthCodes/                                         # DELETE — entire directory
    └── ConnectorApprovedUsers/                                 # DELETE — entire directory

admin/
├── Main.php                                                    # MODIFY — remove maybe_enqueue_ai_connectors_app() method + call site
└── Partials/ServerTabs/
    ├── AIConnectorsTab.php                                     # DELETE — moved to companion
    └── Registry.php                                            # MODIFY — remove built-in AIConnectorsTab entry from all_tabs() (10 → 9); preserve filter fire

public/
└── Discovery/ConnectionMethodRegistry.php                      # MODIFY — swap ConnectorProfileRegistry FQN from mcp-manager to companion, guard with class_exists() (FR-019, discovered by pre-flight grep)

src/
├── js/ai-connectors.js                                         # DELETE — moved to companion
└── scss/ai-connectors.scss                                     # DELETE — moved to companion

build/
├── js/ai-connectors.{js,asset.php,map}                         # DELETE — build artifact cleanup (regenerated by webpack anyway)
└── css/ai-connectors.{css,asset.php,map}                       # DELETE — build artifact cleanup

templates/
└── oauth/                                                      # DELETE — entire directory (consent.php + directory)
    └── consent.php

tests/phpunit/
├── Includes/
│   ├── OAuth/**                                                # DELETE — all subfolders + files
│   ├── Database/OAuthClients/**                                # DELETE
│   ├── Database/OAuthTokens/**                                 # DELETE
│   ├── Database/OAuthAuthCodes/**                              # DELETE
│   ├── Database/ConnectorApprovedUsers/**                      # DELETE
│   └── Connectors/**                                           # DELETE (if present)
└── Admin/Partials/ServerTabs/
    └── AIConnectorsTabTest.php                                 # DELETE (if present)

uninstall.php                                                   # MODIFY — remove 4 OAuth DROP TABLE lines + OAuth cron-clear line
webpack.config.js                                               # MODIFY — remove 'js/ai-connectors' entry

specs/
└── 039-migrate-ai-connectors-to-companion/                     # DELETE — working-tree-only cleanup (FR-018)
```

**Structure Decision**: This is a subtractive change to an existing plugin (not new scaffolding). The directory layout above IS the plan — every path listed is the entire set of files touched by this feature. Files not listed above MUST NOT be modified. The pre-flight `grep` (FR-016) enforces this at the callers level.

## Phase 0: Outline & Research

`research.md` will consolidate:

1. **Companion audit synthesis** — combines the two Explore audits already run (structural readiness 23/23 PASS + wiring counterparts 21/21 PASS) into a single blocker/no-blocker table used as prerequisite for Phase 2 tasks.
2. **Deletion ordering** — decision: delete tests FIRST (nothing depends on them), then Table/Row PHP classes, then REST controllers, then wiring modifications, then plugin header bump. Rationale: keeps the codebase temporarily-valid at each step so `composer dump-autoload` and PHPStan can be re-run between phases to catch any missed reference.
3. **Pre-flight callers grep result** — the exact command from spec §Input, executed once against `HEAD` before any deletion, output captured to `research.md` so any subsequent `grep` diff after each phase can be reasoned about.
4. **No new decisions to research** — Q1..Q6 clarifications closed every branching decision. Zero `[NEEDS CLARIFICATION]` markers remain in the spec.

## Phase 1: Design & Contracts

Since this feature adds no new APIs, data models, or contracts, the Phase 1 artifacts are **descriptive documentation of what is preserved**, not designs for what is built:

1. **`data-model.md`** — one-page reference documenting the 4 preserved OAuth tables (name, version, `db_version_key`) and the `acrossai_mcp_connector_%` option keys. This gives future maintainers a single-place-to-look for "what did this feature explicitly NOT touch." Confirms the tables' schema fingerprints match the companion PR (audited PASS).
2. **`contracts/removed-rest-routes.md`** — catalogue of the REST routes REMOVED from mcp-manager (companion continues to serve them under the same URLs). Serves as the pre-flight verification checklist ("route X was on mcp-manager before feature 040; after feature 040 it is on the companion; the URL is unchanged").
3. **`quickstart.md`** — three concrete verification recipes: (a) fresh install without add-on (free-user path); (b) upgrade with add-on active (premium-user seamless path); (c) upgrade with add-on missing (premium-degraded path — accepted state per Q6). Each recipe is a numbered WP-CLI + `curl` sequence with expected outputs, used by `/speckit-tasks` as acceptance tests and by post-merge manual verification.
4. **Agent context update** — the `<!-- SPECKIT START -->` / `<!-- SPECKIT END -->` markers in project `CLAUDE.md` will point to this plan file so downstream conversations pick up the correct current plan.

Let's produce those artifacts.

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

No violations. Table intentionally empty.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| _(none)_  | _(n/a)_    | _(n/a)_                             |
