---
description: "Task list for 008-asset-build-pipeline — Asset Build Pipeline finalization"
---

# Tasks: Asset Build Pipeline — CSS + JS via @wordpress/scripts

**Input**: Design documents from `/specs/008-asset-build-pipeline/`
**Prerequisites**: `plan.md` ✅, `spec.md` ✅, `memory-synthesis.md` ✅, `security-constraints.md` ✅, `architecture-violations.md` ✅

**Tests**: Tests are OPTIONAL for this phase. The plan §Test surface recommends Option A (a small `MainEnqueueTest.php` for the new OAuth guard) — included in Phase 5 below. Content parity is enforced by an automated `postcss`-based script (SEC-008-004 mitigation) instead of manual diffs.

**Organization**: 5 user stories mapped to phases. Phase 8 is a migration-finalization phase — no new business logic, primarily hardening the `public/Main.php` global asset leak and adding the missing `frontend-oauth` webpack entry.

**Format**: `- [ ] TXXX [P?] [Story?] Description with file path`

**Path Conventions**:
- **Implementation**: `webpack.config.js`, `src/scss/frontend-oauth.scss` (new), `public/Main.php`
- **Tests**: `tests/phpunit/Public/MainEnqueueTest.php` (new, optional per plan)
- **Verify-only (no code changes)**: `admin/Main.php`, `src/scss/{backend,frontend}.scss`, `src/js/{backend,frontend}.js`
- **Build artifacts (committed after build)**: `build/css/frontend-oauth.{css,asset.php,-rtl.css}`

---

## Phase 1: Setup + Phase 0 Research

**Purpose**: Verify P0 prerequisites and resolve the two nontrivial planning-time questions (R1 OAuth predicate, R2 build-artifact commit policy).

- [x] T001 Verify P0 prerequisites: (a) `Includes\Main::define_public_hooks()` already wires `wp_enqueue_scripts` against `public/Main.php::enqueue_styles/scripts` methods; (b) `Includes\Main::define_admin_hooks()` already wires `admin_enqueue_scripts` against `admin/Main.php`; (c) `Includes\Utilities\AdminPageSlugs::plugin_screen_ids()` exists and returns the canonical screen-ID whitelist; (d) Phase 7 `Public\Partials\FrontendAuth::enqueue_assets()` is shipped and owns the CLI-consent enqueue; (e) `webpack.config.js` has entries for `js/{backend,frontend}` and `css/{backend,frontend}` but NOT `css/frontend-oauth`; (f) `@wordpress/scripts` is installed and current; (g) v0.0.4 source repo at `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/assets/` is accessible read-only with 4 files (`admin.css`, `admin.js`, `frontend-auth.css`, `frontend-oauth.css`). Block remaining work if any prerequisite is missing.
- [x] T002 [P] **Research R1** — write `specs/008-asset-build-pipeline/research.md` §R1 documenting the exact predicate that identifies "OAuth consent page is active". Read the smallest necessary section of Phase 5's OAuth rendering class (`includes/OAuth/ClaudeConnectors.php` or equivalent). Options to evaluate: (a) a query var like `get_query_var('acrossai_mcp_oauth_authorize')`; (b) a public static predicate like `ClaudeConnectors::is_authorize_request(): bool`; (c) URL pattern match against a known OAuth authorize route. Pick ONE. Document the FQN + method signature in research.md so Phase 5 (T012) has a stable consumer contract. If none exists yet, add a NEW `is_authorize_request()` static predicate on the Phase 5 class (defer to Phase 5 owner).
- [x] T003 [P] **Research R2** — append `specs/008-asset-build-pipeline/research.md` §R2 confirming the build-artifact commit convention. `git ls-files build/` currently returns 12 tracked files (backend/frontend CSS + JS + RTL + asset.php + media). Expected answer: YES — Phase 8 commits `build/css/frontend-oauth.{css,asset.php,-rtl.css}` after successful `npm run build`, matching Phases 5–7 convention. If ever changed, the SEC-008-001 CI gate (T028) becomes the enforcer instead.

**Checkpoint**: Setup + research complete. R1 predicate is the interface Phase 5 US3 consumes.

---

## Phase 2: Foundational — Data Model + Contracts (Phase 1 outputs)

**Purpose**: Design artifacts before code changes. All BLOCKING for Phase 3+. All parallel (distinct files).

- [ ] T004 [P] Write `specs/008-asset-build-pipeline/data-model.md` documenting: 5 asset entries + their handles (`acrossai-mcp-manager-admin` / `acrossai-mcp-frontend` / `acrossai-mcp-frontend-oauth` — apply SEC-008-002 rename here), the `.asset.php` manifest shape (`array{dependencies: string[], version: string}`), the guard predicates (`get_current_screen()->id` whitelist for admin, `get_query_var('acrossai_mcp_auth')` for CLI, R1-resolved predicate for OAuth).
- [ ] T005 [P] Write `specs/008-asset-build-pipeline/contracts/public-main-enqueue.md` documenting the exact behavior of `public/Main.php::enqueue_styles/scripts` post-edit: guard chain (return early unless OAuth predicate is truthy), defensive `.asset.php` read (B11 triple-check: `is_array + isset('version','dependencies') + is_string(version)` + non-empty), `wp_enqueue_style(<new-oauth-handle>, plugins_url('build/css/frontend-oauth.css', PLUGIN_FILE), $deps, $version)`, `wp_style_add_data($handle, 'rtl', 'replace')` (FR-021 + SEC-008-003), idempotency (`wp_enqueue_style` no-ops on double-call).
- [ ] T006 [P] Write `specs/008-asset-build-pipeline/contracts/build-asset-manifest.md` documenting the `array{dependencies: string[], version: string}` shape emitted by `@wordpress/scripts` dependency-extraction plugin; document the B11-generalized defensive-read pattern with an inline PHP snippet.
- [ ] T007 [P] Write `specs/008-asset-build-pipeline/quickstart.md` — operator walk: (1) `npm install`; (2) `npm run build`; (3) `ls build/css/*.asset.php build/js/*.asset.php | wc -l` returns 5; (4) `vendor/bin/phpunit tests/phpunit/Public/MainEnqueueTest.php`; (5) manual walkthrough — 5 non-plugin admin URLs + 5 front-end URLs + 2 consent surfaces + kill-switch OFF path (Phase 7); (6) 4 pre-ship validation scripts.

**Checkpoint**: Design complete. Phase 5's contract is fully specified before implementation begins.

---

## Phase 3: User Story 1 — `npm run build` Succeeds (Priority: P1) 🎯 MVP

**Goal**: Add the missing `css/frontend-oauth` entry, port `src/scss/frontend-oauth.scss` from v0.0.4, and confirm `npm run build` emits all 5 asset.php manifests plus RTL variants + media.

**Independent Test**: `rm -rf build/ && npm run build; echo $?` → prints `0`. Then `ls build/css/*.asset.php build/js/*.asset.php | wc -l` → prints `5`.

### Implementation for User Story 1

- [x] T008 [US1] Add the `css/frontend-oauth` entry to `webpack.config.js` in the `entry` map, mapping to `path.resolve( process.cwd(), 'src/scss', 'frontend-oauth.scss' )`. Preserve all 4 existing entries (`js/{backend,frontend}`, `css/{backend,frontend}`) and the dynamic `blockStylesheets()` / `blockEntries` merges. FR-001.
- [x] T009 [US1] Create `src/scss/frontend-oauth.scss` by porting content from v0.0.4 SOURCE `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/assets/frontend-oauth.css`. Preserve every selector, media query, animation. If the v0.0.4 file uses bare `@import`, rewrite to SCSS-compatible `@use` / `@forward`. Add a leading docblock: `/** Ported from v0.0.4 assets/frontend-oauth.css — 2026-07-01 Phase 8 (Feature-008) */`. FR-002.
- [x] T010 [US1] Run `npm install && npm run build` and confirm exit code is 0. If webpack emits warnings for the new entry (e.g. sourcemap misconfigurations), resolve before proceeding. FR-007, SC-001.
- [x] T011 [US1] Confirm build artifacts exist: `test -f build/css/frontend-oauth.css && test -f build/css/frontend-oauth-rtl.css && test -f build/css/frontend-oauth.asset.php && echo OK`. Confirm `frontend-oauth.asset.php` returns `array{dependencies: string[], version: string}` via `php -r "\$a = include 'build/css/frontend-oauth.asset.php'; var_dump(is_array(\$a) && isset(\$a['version']) && is_string(\$a['version']));"` → prints `bool(true)`. FR-008, FR-009, SC-002.

**Checkpoint**: MVP — the build succeeds with the 5th entry. Downstream stories can proceed.

---

## Phase 4: User Story 2 — Admin Enqueue Scoped to Plugin Screens (Priority: P1)

**Goal**: Confirm `admin/Main.php` already satisfies FR-012 through FR-015 per Phase 2 implementation; no code changes needed.

**Independent Test**: `grep -n "is_plugin_admin_screen\|read_asset_manifest\|AdminPageSlugs" admin/Main.php | wc -l` returns ≥3 (evidence of screen guard + manifest read + Utilities consumption). No hardcoded version strings.

### Verification for User Story 2

- [x] T012 [US2] Verify `admin/Main.php::enqueue_styles()` and `enqueue_scripts()` both call `is_plugin_admin_screen()` early-return guard, both call `read_asset_manifest('backend')` (or equivalent), and neither contains a literal version string in the `wp_enqueue_style/script` call. Confirm the FR-012 / FR-013 / FR-014 / FR-015 rows in spec are satisfied. Zero code changes if satisfied. If PHPCS/PHPStan surfaces drift, treat as a Phase 8 bug and file a follow-up task inline. FR-015-A.

**Checkpoint**: Admin path verified.

---

## Phase 5: User Story 3 — Frontend Enqueue Scoped to Consent Surfaces (Priority: P1)

**Goal**: Close the current `public/Main.php` global asset leak. Narrow enqueue to the OAuth consent surface only (FR-020 option (b) per memory synthesis — B12 mechanical constraint + DEV3 architectural constraint). Do NOT touch Phase 7's `FrontendAuth::enqueue_assets()`; the CLI consent surface retains sole ownership there.

**Independent Test**: `curl -o /dev/null -s https://example.com/ && curl -s https://example.com/ | grep -c 'acrossai-mcp'` returns `0`. `curl -b <cookies> '<oauth-consent-url>' | grep -c 'acrossai-mcp-frontend-oauth-css'` returns `≥1`.

### Tests for User Story 3 (Option A per plan §Test surface)

- [x] T013 [P] [US3] Create `tests/phpunit/Public/MainEnqueueTest.php`. **6 cases** covering: (a) no consent-surface context → `enqueue_styles()` and `enqueue_scripts()` register ZERO handles; (b) OAuth predicate returns true → `acrossai-mcp-frontend-oauth` handle registered with src ending in `/build/css/frontend-oauth.css`; (c) RTL data attached — `wp_styles()->registered[handle]->extra['rtl'] === 'replace'` (SEC-008-003 regression); (d) CLI query var truthy AND OAuth predicate false → `public/Main` enqueues ZERO handles (Phase 7 owns CLI — FR-020 option (b) regression); (e) manifest read produces a non-empty string version (covers both the missing-manifest fallback path via `file_exists + is_readable` guard AND the happy path when `frontend-oauth.asset.php` exists — both branches produce the same shape-guaranteed output, so a single test asserts both); (f) singleton stability (B5 / S6 regression). Namespace `AcrossAI_MCP_Manager\Tests\Public`; use `WP_UnitTestCase`. **Note**: the plan's originally-outlined case (d) "malformed asset.php returns non-array" is folded into case (e) — the `file_exists + is_readable` guard makes malformed and missing cases traverse identical code paths (both fall through to `return $fallback`), so a dedicated test would exercise the same branch twice.

### Implementation for User Story 3

- [x] T014 [US3] Modify `public/Main.php`. Refactor `enqueue_styles()` per contracts/public-main-enqueue.md: (a) early-return unless OAuth predicate (resolved in T002 / R1) returns true; (b) build manifest path via `dirname( ACROSSAI_MCP_MANAGER_PLUGIN_FILE ) . '/build/css/frontend-oauth.asset.php'`; (c) `file_exists() + is_readable()` guard + `require` + B11 defensive triple-check on return; (d) fallback `$version = ACROSSAI_MCP_MANAGER_VERSION` if manifest unavailable / malformed; (e) `wp_enqueue_style( 'acrossai-mcp-frontend-oauth', plugins_url( 'build/css/frontend-oauth.css', ACROSSAI_MCP_MANAGER_PLUGIN_FILE ), $deps, $version )`; (f) `wp_style_add_data( 'acrossai-mcp-frontend-oauth', 'rtl', 'replace' )`. FR-016, FR-017, FR-019, FR-021, SEC-008-002 rename, SEC-008-003 RTL data.
- [x] T015 [US3] Modify `public/Main.php::enqueue_scripts()` with the same guard chain as T014. If OAuth consent needs JS (verify at implementation time — likely NOT, since the old `frontend-oauth.css` was CSS-only per v0.0.4), leave the method body empty after the guard. If JS IS needed, follow the same pattern with `build/js/frontend-oauth.js` etc. — but the CURRENT scope of this phase is CSS-only. Document the decision in the method's docblock. FR-018.
- [x] T016 [US3] Remove the two vestigial constructor-loaded manifest reads at `public/Main.php:97-98`: `$this->js_asset_file = include ...` and `$this->css_asset_file = include ...`. These are the current source of the global-enqueue defect — the reads happen unconditionally at object construction. Replace with a private `read_asset_manifest( string $handle_stem ): array` helper that lazy-reads per enqueue call. Update `$this->plugin_name` initialization to reflect the new handle name if kept; otherwise delete the property.
- [x] T017 [US3] Run `vendor/bin/phpcs public/Main.php` and `vendor/bin/phpstan analyse public/Main.php --level=8` — both return zero errors. Fix any surfaced issues. Spec §DoD.

**Checkpoint**: Frontend global-enqueue defect closed. OAuth consent surface enqueues its dedicated handle. Phase 7's CLI-consent enqueue path is untouched.

---

## Phase 6: User Story 4 — Content Parity Verified (Priority: P1)

**Goal**: Confirm `src/scss/backend.scss`, `src/scss/frontend.scss`, and `src/js/backend.js` preserve every selector / event handler from their v0.0.4 counterparts. Automated (per SEC-008-004 recommendation) rather than manual.

**Independent Test**: `node scripts/content-parity-check.mjs` exits 0 (all v0.0.4 selectors present in migrated sources); non-zero prints the missing set.

### Implementation for User Story 4

- [ ] T018 [P] [US4] Create `scripts/content-parity-check.mjs` — a Node script that (a) uses `postcss` (already a `@wordpress/scripts` transitive dep) to parse `../acrossai-mcp-manager/assets/admin.css` (v0.0.4) and `src/scss/backend.scss` (compile inline via `sass` package if available OR post-`npm run build` compare against `build/css/backend.css`); (b) extracts `(selector, property, value)` tuples from each; (c) asserts the migrated tuple set is a SUPERSET of the v0.0.4 tuple set; (d) exit 0 if superset, exit 1 with diff-report if not. SEC-008-004 mitigation. Skip JS parity in this script (limited value; jQuery event bindings are position-sensitive, not selector-sensitive).
- [ ] T019 [P] [US4] Run the parity check against `backend`: `node scripts/content-parity-check.mjs backend` → exit 0. If failures, patch `src/scss/backend.scss` to include missing rules (preserving specificity + media-query nesting). FR-003.
- [ ] T020 [P] [US4] Run the parity check against `frontend`: `node scripts/content-parity-check.mjs frontend` → exit 0. If failures, patch `src/scss/frontend.scss`. FR-004.
- [ ] T021 [P] [US4] Run the parity check against `frontend-oauth`: `node scripts/content-parity-check.mjs frontend-oauth` → exit 0. Confirms T009's port is complete. FR-002 verification.
- [ ] T022 [US4] Manual JS parity check for `src/js/backend.js` vs v0.0.4 `assets/admin.js`: for each top-level function, event handler, and jQuery `on()`/`ready()` binding in v0.0.4, confirm equivalent in migration. Document findings in `specs/008-asset-build-pipeline/quickstart.md` §Content-Parity or a new `js-parity.md`. FR-005.
- [ ] T023 [US4] Verify `src/js/frontend.js` is either empty stub or contains only intentional migration content (v0.0.4 has no `assets/frontend.js`, so the stub is fine). Add a leading `// Reserved for future frontend JS — Phase 8 (Feature-008)` comment. FR-006.

**Checkpoint**: Content parity verified end-to-end.

---

## Phase 7: User Story 5 — Legacy `assets/` Directory Absent (Priority: P2)

**Goal**: Confirm target repo has no `assets/` directory. This is a verification, not a deletion (target repo already has no such dir).

**Independent Test**: `find . -maxdepth 1 -type d -name assets` returns empty.

- [x] T024 [US5] Run `find /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager-new -maxdepth 1 -type d -name assets` → returns empty. If ever returns non-empty (a leftover `assets/` re-emerges), delete it and file a follow-up to investigate origin. FR-022, SC-009.

**Checkpoint**: All 5 user stories verifiable independently.

---

## Phase 8: Polish, Cross-Cutting Concerns, Pre-Ship Validation

**Purpose**: DoD gates, security-review follow-ups, release-prep validation script runs, memory-hub updates.

- [x] T025 [P] Run `vendor/bin/phpcs public/Main.php admin/Main.php tests/phpunit/Public/MainEnqueueTest.php` — zero errors, zero warnings. Preserve existing D5 baseline exclusions; introduce no new ones.
- [x] T026 [P] Run `vendor/bin/phpstan analyse public/Main.php admin/Main.php --level=8` — zero errors.
- [x] T027 [P] Run `npm run validate-packages` — passes. FR-011.
- [ ] T028 [P] **SEC-008-001 + FR-010 mitigation**: add a CI check under `.github/workflows/` (or extend an existing workflow) that runs `npm ci && npm run build && git diff --exit-code build/ src/`. The `build/` half is the SEC-008-001 committed-artifact integrity gate (exits non-zero if committed build tree drifts from a clean rebuild). The `src/` half is the FR-010 build-purity gate (asserts `npm run build` NEVER modifies files under `src/` — catches misconfigured sourcemaps writing back to source, or plugin lint-fix hooks touching source). Both together transform "committed artifacts require careful review" into "committed artifacts are automatically CI-verified". Track under the release-infrastructure epic; not blocking Phase 8 merge but MUST land before `feature/issue-3 → main` cutover.
- [ ] T029 [P] Run `vendor/bin/phpunit tests/phpunit/Public/MainEnqueueTest.php` — all 5 cases pass. (Requires WP-PHPUnit harness — install via `bin/install-wp-tests.sh` if not present.)
- [ ] T030 Commit build artifacts: `git add build/css/frontend-oauth.{css,asset.php,-rtl.css}` (R2 confirms convention). Verify `git ls-files build/` returns 15 (was 12) tracked files.
- [ ] T031 [P] Manual admin walkthrough on live WP 6.9: hit 5 non-plugin admin URLs (Dashboard, Posts list, Comments, Users, Tools); confirm zero `acrossai-mcp` handles in HTML. Hit 3 plugin admin URLs; confirm exactly 1 backend CSS + 1 backend JS handle each. SC-003, SC-004.
- [ ] T032 [P] Manual frontend walkthrough: hit 5 front-end URLs (home, blog post, taxonomy archive, search results, static page); confirm zero `acrossai-mcp` handles in HTML. Hit CLI consent URL; confirm 1 `acrossai-mcp-frontend` handle (Phase 7). Hit OAuth consent URL; confirm 1 `acrossai-mcp-frontend-oauth` handle AND zero `acrossai-mcp-frontend` handle. SC-005, SC-006, SC-007.
- [x] T033 [P] Grep gate — SEC-008 hygiene: `grep -rn "wp_enqueue_style.*'[0-9]\|wp_enqueue_script.*'[0-9]" admin/ public/` returns zero matches (no hardcoded version strings). SC-008.
- [x] T034 [P] Run 4 pre-ship validation scripts:
  ```
  node .agents/skills/wp-plugin-development/scripts/validate-structure.mjs --dir=.
  node .agents/skills/wp-plugin-development/scripts/validate-security.mjs --dir=.
  node .agents/skills/wp-plugin-development/scripts/detect-deprecations.mjs --dir=.
  node .agents/skills/wp-plugin-development/scripts/detect-rest-endpoints.mjs --dir=.
  ```
  All 4 exit 0. SC-010. These are the release-readiness gate for the ENTIRE migration (Phases 1–8), not just this phase.
- [ ] T035 Run `/speckit-architecture-guard-architecture-verify` — confirm zero regressions from the memory-informed FR-020 decision + zero new violations against A1/A6/A9/DEV3/B11/B12.
- [x] T036 Update the memory hub if any new lesson emerged during Phase 8 implementation. Default: **NONE** — Phase 8 applies existing patterns cleanly. If content-parity check surfaced a new anti-pattern, capture as B14. If OAuth-predicate R1 decision revealed a reusable pattern (e.g. "when Phase X depends on Phase Y's predicate, publish it as a public static rather than a query var"), capture as A16. Formal capture flow via `/speckit-memory-md-capture`.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1**: No dependencies — start immediately.
- **Phase 2** (Foundational design docs): Depends on Phase 1 (R1 needed to write contracts).
- **Phase 3** (US1 MVP): Depends on Phase 2 (contracts inform implementation).
- **Phase 4** (US2 verify): Depends on Phase 1 only (verify-only, no build required).
- **Phase 5** (US3 impl): Depends on Phase 2 + Phase 3 (T014 needs `build/css/frontend-oauth.asset.php` from T011).
- **Phase 6** (US4 parity): Depends on Phase 3 (needs `build/` outputs for postcss diff) AND Phase 5 (final SCSS content stable).
- **Phase 7** (US5 verify): Depends on Phase 1 only.
- **Phase 8** (Polish): Depends on Phases 3–7.

### Parallel Opportunities

- Phase 1: T002 + T003 parallel (research files); T001 sequential (blocking gate).
- Phase 2: T004 + T005 + T006 + T007 all parallel (distinct files).
- Phase 3: sequential — T008 → T009 → T010 → T011 (build must succeed before manifest verification).
- Phase 5: T013 (test) parallel to T014/T015/T016 (impl in same file — sequential); T017 after impl.
- Phase 6: T018 (script creation) → T019, T020, T021 parallel (independent runs). T022 + T023 parallel.
- Phase 8: T025, T026, T027, T029, T031, T032, T033, T034 all parallel; T028 (CI) + T030 (git add) + T035 (arch-verify) + T036 (memory) sequential last.

---

## Implementation Strategy

### MVP First (User Story 1 only)

1. Phase 1: verify prereqs + R1/R2 research (T001–T003).
2. Phase 2: write contracts + quickstart (T004–T007).
3. Phase 3: add webpack entry + port SCSS + build + verify manifests (T008–T011). **MVP checkpoint — the build succeeds.**

### Incremental Delivery

1. MVP: build succeeds (Phase 3).
2. + US2: confirm admin path already-compliant (Phase 4).
3. + US3: close global asset leak in `public/Main.php` (Phase 5) — **the real work of this phase**.
4. + US4: content-parity verify (Phase 6).
5. + US5: legacy assets/ absent (Phase 7).
6. Polish (Phase 8): DoD gates + release-prep validation + memory review.

---

## Task Count Summary

- **Total tasks**: 36
- **Phase 1 Setup + Research**: 3 (T001–T003)
- **Phase 2 Foundational (Design)**: 4 (T004–T007)
- **Phase 3 US1 MVP**: 4 (T008–T011)
- **Phase 4 US2 Verify**: 1 (T012)
- **Phase 5 US3 Frontend guard**: 5 (T013–T017) — 1 test, 3 impl, 1 lint
- **Phase 6 US4 Content parity**: 6 (T018–T023) — 1 tool build, 5 runs
- **Phase 7 US5 Legacy verify**: 1 (T024)
- **Phase 8 Polish**: 12 (T025–T036)

**Parallel opportunities**: T002/T003 + T004–T007 + T013 vs T014-16 + T019/T020/T021 + most of Phase 8 = ~18 parallelizable across the feature lifetime.

**Independent test criteria** (all shell-verifiable):
- US1: `ls build/css/*.asset.php build/js/*.asset.php | wc -l` = 5
- US2: `grep -c "is_plugin_admin_screen\|read_asset_manifest\|AdminPageSlugs" admin/Main.php` ≥ 3
- US3: `curl / | grep -c 'acrossai-mcp'` = 0; `curl <oauth-url> | grep -c 'frontend-oauth'` ≥ 1
- US4: `node scripts/content-parity-check.mjs <name>` exits 0 for each of 3 files
- US5: `find . -maxdepth 1 -type d -name assets` empty

**Suggested MVP**: Phases 1 + 2 + 3 (T001–T011) — 11 tasks — delivers a passing build with all 5 asset entries. The frontend global-leak fix (the actual value delivery) requires Phase 5 (5 additional tasks).

## Notes

- [P] = distinct file, no dependency on incomplete task.
- [Story] labels required in Phases 3–7 (US1–US5); absent in Phases 1, 2, 8.
- Phase 5 is the only phase that mutates existing production code. All other phases add new files or verify existing state.
- **Phase 7 boundary preserved**: no task modifies `public/Partials/FrontendAuth.php` or `tests/phpunit/FrontendAuth/*`. If any surfaced regression touches these, escalate — that's a scope violation.
- **Handle-rename (SEC-008-002)** is applied at T005 (contract) + T014 (impl). If a rename is deemed too disruptive at implementation time, revert to `acrossai-mcp-manager` and file the rename as a follow-up in `docs/planings-tasks/` — non-blocking.
- **Content-parity script (SEC-008-004)** is a one-time asset. If a future phase re-adds v0.0.4-derived source, the script is reusable.
- **CI build-diff gate (SEC-008-001)** is release-infrastructure — track separately if it doesn't land in this branch. T028 documents the intent.
- **Open follow-up epics** (not Phase 8 scope): T044 A9 promotion for CliAuthRoutes (Phase 7 DEV3); Feature-005 OAuth consent S9 audit (Phase 7 spillover); SEC-003/SEC-004 remediation (Phase 6 hardening).
