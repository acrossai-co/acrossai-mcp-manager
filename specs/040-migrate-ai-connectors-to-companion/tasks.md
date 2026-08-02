---
description: "Task list for Feature 040 — migrate AI Connectors + OAuth stack to companion plugin"
---

# Tasks: Migrate AI Connectors + OAuth Stack to Companion Plugin

**Input**: Design documents from `/specs/040-migrate-ai-connectors-to-companion/`
**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/removed-rest-routes.md](./contracts/removed-rest-routes.md), [quickstart.md](./quickstart.md)

**Tests**: This feature is a code-removal migration. FR-015 requires DELETING all OAuth/Connectors/AIConnectorsTab test files. No new tests are written. Remaining test suites must continue to pass (Phase 1 baseline + Phase 5 post-migration verification).

**Organization**: Tasks are grouped by user story. Because this feature is pure removal + one modification + a version bump, User Story 1 contains the entire migration payload; User Story 2 is verification-only (proving the migration doesn't disturb free users or fatal on premium-degraded sites).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no cross-task dependencies)
- **[Story]**: Which user story this task belongs to (US1, US2)
- Every implementation task includes the exact file path or command

## Path Conventions

- **Plugin root**: `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager` (referenced as the working directory in every task)
- **Companion root** (read-only reference): `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-ai-connectors`

---

## Phase 1: Setup (Pre-Migration Baseline Capture)

**Purpose**: Snapshot the pre-migration state so post-migration verification (Phase 4) has ground truth to compare against. All these tasks are read-only — no code changes.

- [X] T001 [P] Capture pre-migration OAuth-table row counts and DDL fingerprints. Run:
  ```bash
  wp db query "SELECT 'clients' AS t, COUNT(*) AS c FROM wp_acrossai_mcp_oauth_clients UNION SELECT 'tokens', COUNT(*) FROM wp_acrossai_mcp_oauth_tokens UNION SELECT 'auth_codes', COUNT(*) FROM wp_acrossai_mcp_oauth_auth_codes UNION SELECT 'approved_users', COUNT(*) FROM wp_acrossai_mcp_connector_approved_users" > /tmp/f040-baseline-counts.txt
  for t in oauth_clients oauth_tokens oauth_auth_codes connector_approved_users; do wp db query "SHOW CREATE TABLE wp_acrossai_mcp_$t\G" > /tmp/f040-baseline-ddl-$t.txt; done
  ```
- [X] T002 [P] Capture pre-migration OAuth discovery JSON:
  ```bash
  curl -s "https://wordpress-7-0.local/.well-known/oauth-authorization-server" > /tmp/f040-baseline-discovery.json
  ```
- [X] T003 [P] Capture pre-migration cron event listing:
  ```bash
  wp cron event list --format=json > /tmp/f040-baseline-cron.json
  grep -q 'acrossai_mcp_manager_oauth_cleanup' /tmp/f040-baseline-cron.json && echo "PASS: cron scheduled pre-migration"
  ```
- [X] T004 Run pre-flight callers grep and archive output. Expected: matches inside `includes/OAuth/**`, `includes/Connectors/**`, `includes/Database/OAuth*/**`, `includes/Database/ConnectorApprovedUsers/**`, `admin/Partials/ServerTabs/AIConnectorsTab.php`, `tests/phpunit/OAuth/**`, `tests/phpunit/Database/OAuth*/**`, plus `includes/Main.php:270`, `includes/Activator.php:10-13`, and `public/Discovery/ConnectionMethodRegistry.php:34,215`. See research.md §Pre-Flight Callers Grep for full expected result set:
  ```bash
  cd /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager
  grep -rEn '(new (Authorization|Token|ClientRegistration|ConnectorAdmin|Discovery)Controller|use .*(AuthorizationController|TokenController|ClientRegistrationController|ConnectorAdminController|DiscoveryController|OAuthRouter|PKCE|Cleanup|TokenValidator|BearerChallengeHeader|UserLifecycle|AccessTokenRepository|RefreshTokenRepository|ClientRepository|AuthCodeRepository|ScopeRepository|SecretsVault|RateLimiter|AbstractConnectorProfile|ConnectorProfileRegistry|ConnectorSettings|AIConnectorsTab|OAuthClients|OAuthTokens|OAuthAuthCodes|ConnectorApprovedUsers))' --include='*.php' includes/ admin/ public/ acrossai-mcp-manager.php uninstall.php tests/ > /tmp/f040-preflight-grep.txt
  wc -l /tmp/f040-preflight-grep.txt
  ```
- [X] T005 Confirm companion `acrossai-ai-connectors` is installed at v0.5.0+ and readable:
  ```bash
  wp plugin get acrossai-ai-connectors --field=version
  # Expected: 0.5.0 or higher
  test -f /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-ai-connectors/includes/OAuth/AuthorizationController.php && echo "PASS"
  ```

**Checkpoint**: Baseline captured. If any command fails, resolve BEFORE starting Phase 3 — post-migration verification depends on these snapshots.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: None. This feature adds no new code, requires no new infrastructure, and has no foundational work to complete before User Story 1.

*(Phase intentionally empty — see spec §Assumptions for rationale.)*

---

## Phase 3: User Story 1 — Premium Users Keep Their OAuth Connections (Priority: P1) 🎯 MVP

**Goal**: Delete the OAuth stack + AI Connectors code from mcp-manager. The companion (already audited PASS 44/44 across two audits + 21 differential checks) takes over via its self-disable probe the moment `AuthorizationController.php` is gone. Existing bearer tokens continue to authenticate, discovery URLs continue to resolve, no re-authorization required.

**Independent Test**: Run Recipe B from quickstart.md (premium-user-seamless path). Bearer tokens issued pre-migration MUST return HTTP 200 on MCP requests post-migration. All 4 OAuth tables MUST be preserved with identical row counts and DDL.

### Substream 3A — Test deletions (safest first, no runtime dependencies)

- [X] T006 [P] [US1] Delete OAuth PHPUnit tests: `rm -rf tests/phpunit/OAuth/`
- [X] T007 [P] [US1] Delete OAuthClients PHPUnit tests: `rm -rf tests/phpunit/Database/OAuthClients/`
- [X] T008 [P] [US1] Delete OAuthTokens PHPUnit tests: `rm -rf tests/phpunit/Database/OAuthTokens/`
- [X] T009 [P] [US1] Delete OAuthAuthCodes PHPUnit tests: `rm -rf tests/phpunit/Database/OAuthAuthCodes/`
- [X] T010 [P] [US1] Delete ConnectorApprovedUsers PHPUnit tests: `rm -rf tests/phpunit/Database/ConnectorApprovedUsers/`
- [X] T011 [P] [US1] Delete Connectors PHPUnit tests if present: `rm -rf tests/phpunit/Includes/Connectors/ 2>/dev/null; true`
- [X] T012 [P] [US1] Delete AIConnectorsTab PHPUnit test if present: `rm -f tests/phpunit/Admin/Partials/ServerTabs/AIConnectorsTabTest.php 2>/dev/null; true`
- [X] T013 [US1] Verify test suite still discovers and passes after test-file deletion (before deleting the code under test): `vendor/bin/phpunit --testsuite=default 2>&1 | tail -20` — remaining tests should be green.

### Substream 3B — Source code deletions (companion has probe-guarded equivalents for all of these)

- [X] T014 [P] [US1] Delete OAuth server directory (all 18 files): `rm -rf includes/OAuth/`
- [X] T015 [P] [US1] Delete Connectors framework directory (3 files): `rm -rf includes/Connectors/`
- [X] T016 [P] [US1] Delete OAuthClients BerlinDB module: `rm -rf includes/Database/OAuthClients/`
- [X] T017 [P] [US1] Delete OAuthTokens BerlinDB module: `rm -rf includes/Database/OAuthTokens/`
- [X] T018 [P] [US1] Delete OAuthAuthCodes BerlinDB module: `rm -rf includes/Database/OAuthAuthCodes/`
- [X] T019 [P] [US1] Delete ConnectorApprovedUsers BerlinDB module: `rm -rf includes/Database/ConnectorApprovedUsers/`
- [X] T020 [P] [US1] Delete AI Connectors admin tab: `rm -f admin/Partials/ServerTabs/AIConnectorsTab.php`
- [X] T021 [P] [US1] Delete OAuth consent template + directory: `rm -rf templates/oauth/`
- [X] T022 [P] [US1] Delete AI Connectors JS source: `rm -f src/js/ai-connectors.js`
- [X] T023 [P] [US1] Delete AI Connectors SCSS source: `rm -f src/scss/ai-connectors.scss`
- [X] T024 [P] [US1] Delete AI Connectors build artifacts: `rm -f build/js/ai-connectors.js build/js/ai-connectors.asset.php build/js/ai-connectors.map build/js/ai-connectors.css build/css/ai-connectors.css build/css/ai-connectors.asset.php build/css/ai-connectors.map 2>/dev/null; true`

### Substream 3C — Wiring file modifications (MUST land in the same commit as Substream 3B — otherwise `composer dump-autoload` errors)

- [X] T025 [P] [US1] Modify `includes/Activator.php`:
  1. Remove the 4 `use ...\Database\OAuth{Clients,Tokens,AuthCodes}\Table as ...Table` and `...\ConnectorApprovedUsers\Table` imports at the top (~lines 10-13)
  2. Remove the 4 `<X>Table::instance()->maybe_upgrade()` calls (~lines 62-69)
  3. Remove the `wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'acrossai_mcp_manager_oauth_cleanup' )` block (~lines 71-73)
  4. Remove the `\...\OAuth\OAuthRouter::instance()->register_rewrite_rules()` + `flush_rewrite_rules()` block (~lines 114-118)
- [X] T026 [P] [US1] Modify `includes/Main.php`:
  1. Remove OAuth REST route registrations for ClientRegistrationController + ConnectorAdminController (~lines 624-633; see plan.md §Structure for exact block)
  2. Remove the 5-class OAuth infra wiring block: OAuthRouter, Cleanup, TokenValidator, UserLifecycle, BearerChallengeHeader (~lines 704-747 — every `$this->loader->add_action/add_filter` line referencing these classes)
  3. Remove the 4 OAuth Table instantiations inside `bootstrap_database_tables()` (~lines 217-223)
  4. Remove the 4 OAuth Table `maybe_upgrade()` calls inside `reconcile_database_schemas()` (~lines 278-281)
  5. Remove the `"OAuthClients MUST fire FIRST"` comment at line 270 (or wherever it survives after the block above is gone)
- [X] T027 [P] [US1] Modify `admin/Main.php`:
  1. Remove the entire `maybe_enqueue_ai_connectors_app()` method body (~lines 374-427)
  2. Remove the call site `$this->maybe_enqueue_ai_connectors_app()` (~line 136)
- [X] T028 [P] [US1] Modify `admin/Partials/ServerTabs/Registry.php`:
  1. Remove the `new AIConnectorsTab(),` line inside `all_tabs()` (~line 116)
  2. Remove the `use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\AIConnectorsTab;` at the top (if present)
  3. Update the tab-count comment from "10 built-in" to "9 built-in" (~line 103)
  4. **MUST NOT REMOVE** the `apply_filters( self::FILTER_NAME, $seeded, $server )` call (~line 151) — that's the extension point the companion depends on (FR-017)
- [X] T029 [P] [US1] Modify `uninstall.php`:
  1. Remove the 4 `$wpdb->prefix . 'acrossai_mcp_oauth_*'` + `'acrossai_mcp_connector_approved_users'` entries from the tables array (~lines 57-64)
  2. Remove the `wp_clear_scheduled_hook( 'acrossai_mcp_manager_oauth_cleanup' )` line (~line 74)
  3. **RETAIN** the `acrossai_mcp_%` LIKE-sweep for options — but narrow it so it does NOT match `acrossai_mcp_connector_%` (per FR-003). Two acceptable approaches: (a) explicit `AND option_name NOT LIKE 'acrossai_mcp_connector_%'` clause, or (b) split into `acrossai_mcp_[!c]%` LIKE patterns. Pick (a) for clarity.
- [X] T030 [P] [US1] Modify `webpack.config.js`:
  1. Remove the `'js/ai-connectors': path.resolve( process.cwd(), 'src/js', 'ai-connectors.js' ),` entry from the `entry` object (~lines 98-105)
- [X] T031 [P] [US1] Modify `public/Discovery/ConnectionMethodRegistry.php` per FR-019:
  1. Line ~34: replace `use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry;` with a companion-namespace reference or delete the `use` and use FQN inline in `get_ai_connectors()`
  2. Line ~215: wrap the `ConnectorProfileRegistry::instance()->get_profiles()` call in a `class_exists( '\AcrossAI_AI_Connectors\Includes\Connectors\ConnectorProfileRegistry', false )` guard
  3. When the guard fails (companion not installed), `get_ai_connectors()` MUST return `array()` — the discovery API's `ai_connector` category simply omits entries. No fatal, no warning.

### Substream 3D — Plugin header + working-tree cleanup

- [X] T032 [P] [US1] Modify `acrossai-mcp-manager.php` header: change `Version: 0.1.9` to `Version: 0.2.0` (~line 27). Do NOT add `Requires Plugins:` (per Q5).
- [X] T033 [P] [US1] Delete abandoned spec directory (FR-018): `rm -rf specs/039-migrate-ai-connectors-to-companion/`

### Substream 3E — Post-modification verification (must all PASS to consider US1 complete)

- [X] T034 [US1] Run `composer dump-autoload --optimize` — expected: succeeds with zero warnings, no `Class not found` errors from stale references.
- [X] T035 [US1] Run `vendor/bin/phpstan analyse -c phpstan.neon --level=8 --no-progress` — expected: zero errors (any error indicates a missed caller).
- [X] T036 [US1] Run `vendor/bin/phpcs --standard=WordPress -p .` — expected: zero errors, zero warnings.
- [X] T037 [US1] Run `npm run lint:js` — expected: zero errors on the remaining `src/js/**` after ai-connectors.js removal.
- [X] T038 [US1] Run `npm run build` — expected: succeeds; `build/js/ai-connectors.*` NOT produced; other bundles regenerated normally.
- [X] T039 [US1] Run `npm run validate-packages` — expected: passes.
- [X] T040 [US1] Run `composer test` — expected: remaining PHPUnit suite passes (no OAuth/Connectors tests left; other tests unaffected).
- [X] T041 [US1] Run post-flight callers grep — MUST return zero hits:
  ```bash
  cd /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager
  grep -rEn '(new (Authorization|Token|ClientRegistration|ConnectorAdmin|Discovery)Controller|use .*(AuthorizationController|TokenController|ClientRegistrationController|ConnectorAdminController|DiscoveryController|OAuthRouter|PKCE|Cleanup|TokenValidator|BearerChallengeHeader|UserLifecycle|AccessTokenRepository|RefreshTokenRepository|ClientRepository|AuthCodeRepository|ScopeRepository|SecretsVault|RateLimiter|AbstractConnectorProfile|ConnectorProfileRegistry|ConnectorSettings|AIConnectorsTab|OAuthClients|OAuthTokens|OAuthAuthCodes|ConnectorApprovedUsers))' --include='*.php' includes/ admin/ public/ acrossai-mcp-manager.php uninstall.php tests/ | grep -v 'AcrossAI_AI_Connectors' | wc -l
  # Expected: 0 (the grep -v excludes the FR-019 companion-namespace reference in ConnectionMethodRegistry, which is expected and correct)
  ```
- [X] T042 [US1] Run `git ls-tree HEAD` sanity check on deleted paths — expected: zero output lines:
  ```bash
  git ls-tree HEAD -- includes/OAuth includes/Connectors 'includes/Database/OAuth*' includes/Database/ConnectorApprovedUsers admin/Partials/ServerTabs/AIConnectorsTab.php templates/oauth src/js/ai-connectors.js src/scss/ai-connectors.scss 'build/*/ai-connectors.*' specs/039-migrate-ai-connectors-to-companion 2>&1 | wc -l
  ```

**Checkpoint (US1 complete)**: The migration is code-complete. All deletion + modification tasks pass all DoD gates. Next: verify runtime behavior in Phase 4.

---

## Phase 4: User Story 2 — Free Users Undisturbed; Premium Managed by Operator (Priority: P2)

**Goal**: Verify runtime behavior for both user populations after US1 lands. This phase is **verification only** — no additional code changes. Each task executes one of the three quickstart recipes and captures the pass/fail evidence.

**Independent Test**: All three recipes produce their expected PASS output.

- [X] T043 [P] [US2] Execute Recipe A from `quickstart.md` (free-user path — no add-on, no prior OAuth). Verify 7/7 PASS: mcp-manager active, no PHP fatal, npm tab renders, clients tab renders, AI Connectors tab absent, no admin notice, discovery API `ai_connector` category empty. Archive output to `/tmp/f040-verify-recipe-a.txt`.
- [X] T044 [P] [US2] Execute Recipe B from `quickstart.md` (premium-seamless — add-on active, prior OAuth). Verify 9/9 PASS: both plugins active, `AuthorizationController` GONE from mcp-manager side + present in companion, `TokenValidator` present in companion, discovery URLs preserved, pre-migration bearer token returns HTTP 200 (not 401), cron event still scheduled under preserved name, tables preserved with identical DDL to T001 baseline, AI Connectors tab renders, discovery API `ai_connector` category populated. Archive to `/tmp/f040-verify-recipe-b.txt`.
- [X] T045 [P] [US2] Execute Recipe C from `quickstart.md` (premium-degraded — add-on missing, prior OAuth). Verify 8/8 PASS: mcp-manager still activates cleanly, no PHP fatal, no admin notice (per Q6), OAuth bearer token correctly returns 401, tables preserved, npm/clients tabs still work, AI Connectors tab absent (companion inactive), reactivation restores OAuth without re-authorization. Archive to `/tmp/f040-verify-recipe-c.txt`.
- [X] T046 [US2] Compare Recipe B outputs to Phase 1 baseline snapshots — MUST be equivalent:
  - `diff /tmp/f040-baseline-counts.txt <(wp db query "SELECT ...")` → identical row counts
  - `diff /tmp/f040-baseline-ddl-oauth_tokens.txt <(wp db query "SHOW CREATE TABLE wp_acrossai_mcp_oauth_tokens\G")` → identical DDL
  - `diff /tmp/f040-baseline-discovery.json <(curl -s .../.well-known/oauth-authorization-server)` → semantically equivalent (URLs may differ in ordering; endpoint values identical)
  - `wp cron event list | grep acrossai_mcp_manager_oauth_cleanup` → exactly one entry, daily

**Checkpoint (US2 complete)**: All three user populations validated. SC-001..SC-004 (measurable outcomes) satisfied.

---

## Phase 5: Polish (Documentation, Memory Hygiene, Final DoD)

**Purpose**: Update docs, memory, and changelog to reflect the migration. These tasks don't affect runtime behavior but are required by the Constitution DoD and the durable-lesson pattern from the plugin's memory system.

- [X] T047 [P] Create `docs/planings-tasks/040-migrate-ai-connectors-to-companion.md` per the `feedback_speckit_workflow` convention (planning doc mirroring `011-berlindb-migration.md` shape). Contents: link to spec.md/plan.md/tasks.md; one-paragraph outcome summary; "durable lesson" bullet from research.md Decision 5+6.
- [X] T048 [P] Update `README.txt` — add an Unreleased changelog bullet:
  ```
  * Migrated the AI Connectors + OAuth stack to the companion plugin
    acrossai-ai-connectors (v0.5.0+). Token/client/auth_code storage is
    unchanged (same table names, same schema, no data migration). REST
    namespace kept as acrossai-mcp-manager/v1 for RFC 8414 discovery
    compatibility. Existing Claude/ChatGPT/Grok OAuth connections continue
    transparently when the add-on is installed. Free users on the npm/clients
    tabs are undisturbed; the AI Connectors tab moves out of mcp-manager
    into the paid add-on. Version bumped to 0.2.0 to signal the breaking
    change for premium users.
  ```
  Also update the `Stable tag:` line to `0.2.0` if present.
- [X] T049 [P] Update `docs/memory/DECISIONS.md` — mark every DEC-OAUTH-*, DEC-DCR-*, DEC-BERLINDB-OAUTH-*, DEC-CONNECTOR-PROFILE-* entry as **Superseded (Feature 040)** per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION. Body preserved intact.
- [X] T050 [P] Update `docs/memory/WORKLOG.md` — add a Feature 040 milestone entry. Include: date (2026-07-31 to the merge date), Why durable, Future mistake prevented, Evidence (2 audits + 21 differential checks + 6-clarification Q&A), Where to look (specs/040-.../{spec,plan,research,data-model,contracts,quickstart}.md), and the **durable lesson**:
  > When a subsystem gets its own plugin, prefer code-only migration (identical table names, identical version keys, byte-identical BerlinDB Table subclass declarations) over data-migration. Cross-plugin ownership handoff via `class_exists()` self-disable probe is atomic and requires zero data movement.
- [X] T051 [P] Update `docs/memory/INDEX.md` — update Superseded rows for the retired DEC-OAUTH-* / DEC-DCR-* / DEC-BERLINDB-OAUTH-* / DEC-CONNECTOR-PROFILE-* decisions (mark superseder as Feature 040), and append a new WORKLOG row for Feature 040.
- [X] T052 [P] Update `docs/planings-tasks/README.md` — append a row for `040-migrate-ai-connectors-to-companion.md` (title, one-line summary, status = In Progress → mark Complete on merge).
- [X] T053 Final DoD gate re-run — repeat T034-T041 as a final smoke pass BEFORE opening the PR. Any regression here blocks merge.
- [X] T054 Full-repo audit per `spec.md` §Final full-repo audit — re-execute the callers grep from T041 and confirm zero hits outside `public/Discovery/ConnectionMethodRegistry.php` (which now legitimately references the companion namespace per FR-019).

**Checkpoint (feature complete)**: All Definition of Done gates PASS. Ready for `/speckit-git-commit`, PR creation, code review, and merge.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: Independent — can execute at any time, but MUST complete before Phase 4 (verification needs the baseline snapshots).
- **Phase 2 (Foundational)**: EMPTY — no work.
- **Phase 3 (US1)**: Independent of Phase 1 (except T005 which is a companion-installation sanity check). The 4 substreams inside Phase 3 have a soft ordering: 3A (test deletions) → 3B + 3C + 3D (code changes) → 3E (verification). Substreams 3B, 3C, 3D can proceed in any interleaving because their tasks touch different files. Substream 3E MUST run after all 3B/3C/3D tasks land.
- **Phase 4 (US2)**: Depends on Phase 3E fully green + Phase 1 baseline snapshots on disk.
- **Phase 5 (Polish)**: Depends on Phase 4 fully green.

### Task-Level Dependencies

- T013 (test suite green after test-file deletion) blocks Substream 3B (source code deletions) — because if the test suite is already broken before we touch code, we can't tell if our subsequent changes broke it.
- All 3B tasks (T014-T024) MUST land in the same commit as all 3C tasks (T025-T031). Otherwise Composer autoload fatals — Main.php would reference deleted Table classes for the duration of one commit, which is unacceptable per the research.md deletion-ordering strategy.
- T034 (composer dump-autoload) blocks T035..T041 — the autoload map must be regenerated before PHPStan/PHPCS analyze the tree.
- T041 (post-flight grep) blocks T042 — grep is the semantic gate; git ls-tree is the follow-up.
- T042 (git ls-tree) blocks all Phase 4 tasks — you can't verify runtime behavior on unstaged/uncommitted changes.
- T046 (Recipe B vs baseline diff) blocks Phase 5 — the durable lesson in T050 depends on evidence from these comparisons.

### Parallel Opportunities

- **Phase 1**: T001 + T002 + T003 all parallel (different data sources); T004 + T005 also parallel.
- **Substream 3A**: T006-T012 all parallel (7 different directories/files). T013 is serial (waits for all).
- **Substream 3B**: T014-T024 all parallel (11 different files/directories). No cross-task dependencies.
- **Substream 3C**: T025-T031 all parallel (7 different files, no shared file with 3B).
- **Substream 3D**: T032 + T033 parallel (2 different paths).
- **Substream 3E**: T034 must run first (autoload). T035-T040 can run parallel with each other after T034. T041-T042 serial after.
- **Phase 4**: T043 + T044 + T045 parallel (different scenarios, no state overlap). T046 serial after.
- **Phase 5**: T047-T052 all parallel (6 different files). T053-T054 serial after.

---

## Parallel Example: Substream 3B (Source Code Deletions)

```bash
# Launch all 11 deletion tasks in a single terminal session (or agent invocation):
rm -rf includes/OAuth/ &
rm -rf includes/Connectors/ &
rm -rf includes/Database/OAuthClients/ &
rm -rf includes/Database/OAuthTokens/ &
rm -rf includes/Database/OAuthAuthCodes/ &
rm -rf includes/Database/ConnectorApprovedUsers/ &
rm -f admin/Partials/ServerTabs/AIConnectorsTab.php &
rm -rf templates/oauth/ &
rm -f src/js/ai-connectors.js &
rm -f src/scss/ai-connectors.scss &
rm -f build/js/ai-connectors.js build/js/ai-connectors.asset.php build/js/ai-connectors.map build/js/ai-connectors.css build/css/ai-connectors.css build/css/ai-connectors.asset.php build/css/ai-connectors.map &
wait
git status --short
```

## Parallel Example: Phase 4 Runtime Verification

```bash
# Run all three quickstart recipes in parallel WP-CLI sessions:
(bash recipes/a.sh > /tmp/f040-verify-recipe-a.txt) &
(bash recipes/b.sh > /tmp/f040-verify-recipe-b.txt) &
(bash recipes/c.sh > /tmp/f040-verify-recipe-c.txt) &
wait
grep -c 'FAIL' /tmp/f040-verify-recipe-*.txt
# Expected: 0 in all three files
```

---

## Implementation Strategy

### MVP (User Story 1 only — the migration itself)

1. Complete Phase 1 (baseline capture) — **~10 minutes**
2. Complete Phase 3 (all substreams) — **~1-2 hours** for a careful pass, less if agents run substreams in parallel
3. **STOP** and validate: run T041-T042 to confirm zero surviving callers + zero surviving files
4. If green, US1 is code-complete and the feature can be merged after Phase 4 verification

### Recommended Incremental Delivery

Because Phase 3 is atomic (deletions + modifications must land together), the natural PR shape is:

- **PR #1** (feature branch → main): Phases 1-4. All deletions, modifications, and runtime verification. Reviewers can walk through each substream independently even though it all lands in one PR.
- **PR #2** (optional, separate): Phase 5 doc/memory hygiene. Can also be folded into PR #1 if the review process accepts a slightly bigger diff.

Alternatively for a solo-operator repo (which this is, per Q6 clarification), all 5 phases can land in a single squashed commit tagged `0.2.0`.

### If Working with a Team

Each substream inside Phase 3 could be assigned to a different developer, provided they coordinate on the commit boundary (all Phase 3 work MUST land atomically). Substream 3E (verification) is the natural integrator — one developer runs it after everyone's edits are merged locally.

---

## Notes

- `[P]` tasks = different files, no cross-task dependencies within the same substream.
- `[US1]` and `[US2]` labels map every implementation task to a spec user story. `[Story]` labels are absent from Phase 1 (Setup) and Phase 5 (Polish) — those are cross-story concerns.
- **No new tests are written.** Per FR-015, tests for the deleted code are themselves deleted. Runtime behavior verification is done via the 3 quickstart recipes in Phase 4.
- **Commit strategy**: because deletions + modifications must land atomically to keep the codebase compilable, the natural unit is "one commit per substream cluster" — e.g. one commit for Substream 3A, another for the atomic 3B+3C+3D bundle, another for 3E if it triggers any fix-up edits.
- **Rollback**: file-level only (see `contracts/removed-rest-routes.md` §Rollback Contract). No data migration to undo. Reverting to 0.1.9 restores all deleted files; the companion's self-disable probe re-detects `AuthorizationController` and hands OAuth ownership back automatically.

---

## Task Summary

- **Total tasks**: 54
- **Setup (Phase 1)**: 5 (mostly `[P]`)
- **Foundational (Phase 2)**: 0
- **User Story 1 (Phase 3)**: 37 tasks split across 5 substreams (Test deletions ×8, Source deletions ×11, Wiring modifications ×7, Header + cleanup ×2, Verification ×9)
- **User Story 2 (Phase 4)**: 4 tasks (3 recipe executions + 1 baseline diff)
- **Polish (Phase 5)**: 8 tasks (6 docs/memory updates parallel + 2 final gate runs serial)

**Parallel opportunities**: Substream 3B (11 parallel deletions), Substream 3C (7 parallel modifications), Phase 4 (3 parallel recipe runs), Phase 5 (6 parallel docs updates).

**Independent test criteria**:
- US1: T041 post-flight grep returns 0 hits + T042 git ls-tree returns 0 lines + T035 PHPStan + T036 PHPCS all green.
- US2: T043 + T044 + T045 all show 24/24 PASS across the three recipes.

**Suggested MVP scope**: All of Phase 3 (User Story 1) — the migration itself is the atomic unit that can't be split without breaking the codebase mid-commit.
