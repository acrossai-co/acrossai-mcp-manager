# Implementation Plan: Asset Build Pipeline — CSS + JS via @wordpress/scripts

**Branch**: `008-asset-build-pipeline` | **Date**: 2026-07-01 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/008-asset-build-pipeline/spec.md`
**Memory synthesis**: [memory-synthesis.md](./memory-synthesis.md) — 8 durable-memory entries selected (D5, A1, A6, A9, DEV3, S9, B11, B12) within retrieval budget.

---

## Summary

Phase 8 is the migration's final asset-hardening pass. Three concrete work items:

1. **Add the missing `css/frontend-oauth` webpack entry** and create `src/scss/frontend-oauth.scss` by porting content from v0.0.4 `assets/frontend-oauth.css` (SOURCE repo, read-only).
2. **Close the `public/Main.php` global asset leak** — its `enqueue_styles/scripts` methods currently run UNGUARDED on every front-end page load. Add a guard that narrows the enqueue to the OAuth consent surface ONLY (per memory synthesis §Conflict-Warnings option (b), driven by **B12** mechanical constraint + **DEV3** coupling-avoidance).
3. **Verify content parity** for pre-existing `src/scss/{backend,frontend}.scss` + `src/js/backend.js` against v0.0.4 originals.

Zero-touch surfaces (verify only, do not modify):
- `admin/Main.php` — already Phase-8-compliant per its Phase 2 implementation (screen-ID whitelist + `.asset.php` manifest reads + no hardcoded versions).
- `public/Partials/FrontendAuth.php` — Phase 7 shipped the CLI consent surface's own explicit enqueue-in-render-helper pattern; Phase 8 preserves this untouched (B12 rationale). Reconciliation is *avoidance*, not *integration*.

### Plan-time decision: FR-020 reconciliation — option (b)

The spec deferred the FR-020 reconciliation strategy to planning. Memory context resolves it decisively:

- **Adopt option (b): narrow `public/Main.php` to OAuth-consent scope ONLY.** Phase 7 retains sole ownership of CLI-consent enqueue.
- **Load-bearing rationale**:
  1. **B12 (mechanical)**: `wp_enqueue_scripts` never fires when `template_redirect` exits before `wp_head()` — any CLI-surface code in `public/Main.php` would be dead. Phase 7's `render_page_shell()` already calls `$this->enqueue_assets()` explicitly for this exact reason.
  2. **DEV3 (architectural)**: adding a `Public\Main` → `Public\Partials\FrontendAuth` dependency creates a parallel to the existing bidirectional Phase 6 ↔ Phase 7 coupling that T044 is trying to unwind. Phase 8 must not compound the problem.
  3. **Boundary cleanliness**: option (b) gives `public/Main.php` a single narrow responsibility (OAuth consent CSS/JS) and preserves Phase 7's boundary intact.

### Migration-finalization scope

Phase 8 does NOT introduce new business features. Purely infrastructure:
- Add 1 webpack entry
- Add 1 SCSS file (`src/scss/frontend-oauth.scss`)
- Edit 1 PHP file (`public/Main.php` — add guards + OAuth-scope logic + defensive `.asset.php` read)
- Add 3 build artifacts (`build/css/frontend-oauth.{css, asset.php, -rtl.css}`)
- Content-parity verify 4 files (backend.scss, frontend.scss, backend.js, frontend.js)
- Zero new REST routes, zero new options, zero new DB tables, zero new hook wiring.

## Technical Context

| Field | Value |
|---|---|
| Language / version | PHP 8.0+ (constitution target); JS ES2020+ (`@wordpress/scripts` handles target) |
| Primary dependencies | `@wordpress/scripts` (existing), `webpack-remove-empty-scripts` (existing), `copy-webpack-plugin` (existing). No new npm dep. No new Composer dep. |
| Storage | None new. No DB, no options, no transient. |
| Testing | Manual walkthrough (5 admin URLs + 5 frontend URLs + 2 consent surfaces) for guard verification. PHPCS + PHPStan L8 on `public/Main.php`. No new PHPUnit tests strictly required (Phase 2 already covers admin enqueue; Phase 7 already covers CLI consent enqueue; OAuth-surface enqueue MAY warrant one new test — planning decision below). |
| Target platform | WordPress 6.9+; single-site only |
| Project type | WordPress plugin — build pipeline finalization |
| Performance goals | Zero measurable regression in TTFB (guards eliminate wasted enqueue work on non-plugin pages) |
| Constraints | A1 (hooks via Loader — already wired), A6 (`use` imports), A9 (`AdminPageSlugs::plugin_screen_ids()` canonical), B11 (defensive `.asset.php` read), B12 (Phase 7 owns CLI enqueue), no PHPCS baseline additions |
| Scale / scope | 1 webpack.config.js edit + 1 new SCSS file + 1 PHP file edit + 3 build artifacts + content-parity verify. Estimated ~150 LOC total change. |

### Hard prerequisites (P0)

All shipped in prior phases:

1. `Includes\Main::define_public_hooks()` already wires `add_action('wp_enqueue_scripts', $public_main, 'enqueue_styles')` and `enqueue_scripts` ✅
2. `Includes\Main::define_admin_hooks()` already wires the admin equivalents against `admin/Main.php` ✅
3. `Includes\Utilities\AdminPageSlugs::plugin_screen_ids()` returns the canonical whitelist ✅ (Phase 2)
4. `Public\Partials\FrontendAuth::enqueue_assets()` handles CLI consent surface ✅ (Phase 7, PR #9 merged)
5. Phase 5 OAuth consent rendering class exposes a predicate to identify "OAuth consent page is active" — **needs Phase 0 research** to confirm the exact matcher signature (query var name, class method, or route pattern)

### Test surface

The Phase 2 admin enqueue and Phase 7 CLI enqueue are already tested. Phase 8's new code surface is `public/Main.php`'s OAuth guard. Options:

- **Option A**: Add `tests/phpunit/Public/MainEnqueueTest.php` — assert: unguarded page → 0 enqueue; OAuth-consent page → 1 `acrossai-mcp-frontend-oauth` handle; CLI-consent page → 0 enqueue (Phase 7 handles it separately)
- **Option B**: Rely on manual walkthrough per DoD

**Recommendation: Option A** — the guard predicate is subtle enough to warrant a regression net. Estimated ~50 LOC test, one file.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Compliance | Notes |
|---|---|---|
| I. Modular Architecture | ✅ | Single narrow-purpose edit; no new abstractions; preserves Phase 7 boundary. |
| II. WordPress Standards | ✅ | PHPCS WPCS strict + PHPStan L8 mandated in DoD. No deprecated fns. |
| III. Security First | ✅ | No forms, no REST, no DB, no transient — §III's capability/nonce/permission_callback surfaces are null. The one §III item that applies: "no hardcoded version strings" — enforced via SC-008. Consent-surface exception (Feature-007 amendment) untouched. |
| IV. User-Centric Design (DataForm) | ✅ N/A | No admin UI in this phase. |
| V. Extensibility Without Core Modification | ✅ | Hooks via Loader (unchanged); no core mod. |
| VI. Reusability & DRY | ✅ | Consumes existing `AdminPageSlugs` (A9) instead of duplicating whitelist; consumes Phase 7 `FrontendAuth`'s query-var predicate for the CLI-surface guard (via `get_query_var('acrossai_mcp_auth')`), not URL inspection. |
| VII. Definition of Done | ✅ | 10 DoD gates listed in spec §Success Criteria; 4 pre-ship validation scripts (SC-010) are release-gate. |
| **A1** — Hooks via Loader | ✅ | No new `add_action`/`add_filter`. Existing enqueue action wirings unchanged. |
| **A6** — `use` imports in `Public\*` | ✅ | Any cross-namespace refs added to `public/Main.php` use `use` statements. |
| **A9** — Shared constants in `includes/Utilities/` | ✅ | `AdminPageSlugs::plugin_screen_ids()` consumed by admin path (already); OAuth predicate is per-surface (not shared) so no promotion trigger. |
| **B11** — Defensive triple-check on structured reads | ✅ | Applied to `.asset.php` `require` return value in `public/Main.php`. Same shape as `admin/Main.php::read_asset_manifest()`. |
| **B12** — `wp_enqueue_scripts` non-firing on `template_redirect` exit | ✅ | Load-bearing rationale for FR-020 option (b). Phase 8 does NOT try to handle the CLI surface from `public/Main.php`. |
| **DEV3** — Bidirectional Phase 6 ↔ Phase 7 coupling | ✅ | Phase 8 avoids creating a parallel Phase 7 ↔ Phase 8 coupling. `public/Main.php` does NOT import `FrontendAuth`. |

**Result**: All gates pass. Zero documented deviations. No new memory captures warranted — Phase 8 is application of existing patterns.

## Project Structure

### Documentation (this feature)

```text
specs/008-asset-build-pipeline/
├── plan.md                       # THIS FILE
├── spec.md                       # 5 user stories + 23 FRs + 10 SCs (already written)
├── memory-synthesis.md           # 8 durable-memory entries selected
├── research.md                   # Phase 0 — R1 OAuth consent-page predicate; R2 build-artifact commit policy
├── data-model.md                 # Phase 1 — asset entries + handles + manifest shape + guard predicates
├── contracts/                    # Phase 1
│   ├── public-main-enqueue.md    # public/Main.php::enqueue_styles/scripts contract (guard + read + enqueue)
│   └── build-asset-manifest.md   # Shape contract for build/*/*.asset.php files
├── quickstart.md                 # Phase 1 — operator walk: npm install → build → verify admin + frontend guards
├── security-constraints.md       # Phase 8 security review output (governed-plan orchestrator)
├── architecture-violations.md    # Phase 8 architecture review output
└── tasks.md                      # /speckit-tasks output (NOT created here)
```

### Source Code (repository root)

```text
webpack.config.js                 # EXTEND — add 'css/frontend-oauth' entry
src/scss/
├── backend.scss                  # VERIFY content parity vs v0.0.4 assets/admin.css
├── frontend.scss                 # VERIFY content parity vs v0.0.4 assets/frontend-auth.css
└── frontend-oauth.scss           # NEW — port from v0.0.4 assets/frontend-oauth.css
src/js/
├── backend.js                    # VERIFY content parity vs v0.0.4 assets/admin.js
└── frontend.js                   # VERIFY (stub OK)

admin/Main.php                    # NO CHANGES — Phase 2 impl satisfies FR-012 through FR-015
public/Main.php                   # EXTEND — add OAuth-consent guard, add frontend-oauth handle, defensive .asset.php read (B11), RTL data
includes/Main.php                 # NO CHANGES — enqueue hooks already wired
includes/Utilities/AdminPageSlugs.php  # NO CHANGES — canonical whitelist source (A9)

build/css/                        # NEW artifacts:
├── frontend-oauth.css            #   emitted by webpack
├── frontend-oauth.asset.php      #   emitted by webpack
└── frontend-oauth-rtl.css        #   emitted by webpack (rtlcss)

tests/phpunit/Public/
└── MainEnqueueTest.php           # NEW (recommendation A above): 4–6 cases for OAuth guard + defensive read
```

**Structure Decision**: One-file-edit + one-new-file + one-new-test. Aligned with the Phase 7 pattern: minimal implementation surface, extensive test coverage, memory-captured patterns applied.

## Complexity Tracking

Zero deviations. All FRs align with Constitution + memory-synthesized constraints. The FR-020 reconciliation decision (option b) is documented but is not a "violation" — it's a memory-informed planning choice that _resolves_ potential future complexity.

## Phase 0 Output Plan (Research)

Phase 0 produces `research.md` resolving two design questions:

1. **R1 — OAuth consent-page predicate**: What exact matcher signature (query var, class method, route pattern) identifies "we are rendering the OAuth consent page"? Phase 5's `includes/OAuth/ClaudeConnectors.php` (or equivalent) exposes this. Read the smallest necessary section to identify the predicate. Options to evaluate:
   - `get_query_var('acrossai_mcp_oauth_authorize')` (or similar Phase 5 query var)
   - `ClaudeConnectors::is_authorize_request()` public static predicate
   - URL pattern match against a known OAuth route

2. **R2 — Build-artifact commit policy**: Confirm that `build/css/frontend-oauth.*` files SHOULD be committed to the repo (matches existing convention for Phases 5–7 which committed their build outputs). If yes, the plan's task list includes a `git add build/css/frontend-oauth.*` step; if no, a `.gitignore` update is needed instead. Expected answer: YES, commit — matches prior phases.

No `NEEDS CLARIFICATION` markers remain in the spec.

## Phase 1 Output Plan (Design & Contracts)

Phase 1 produces:

- **`data-model.md`** — the 5 asset entries + their handles + `.asset.php` manifest shape + guard predicates (query var / OAuth predicate).
- **`contracts/public-main-enqueue.md`** — the exact behavior of `public/Main.php::enqueue_styles/scripts` post-edit: guard chain, defensive read, `wp_enqueue_style` call, `wp_style_add_data` for RTL, idempotency.
- **`contracts/build-asset-manifest.md`** — the shape of `build/*/*.asset.php` (`array{dependencies: string[], version: string}`) and the fallback semantics.
- **`quickstart.md`** — operator walk: `npm install → npm run build → verify build/ tree → PHPUnit → manual admin + frontend walkthroughs`.

Agent context (`CLAUDE.md` or copilot-instructions) gets a single-line plan-link update inside the `<!-- SPECKIT START -->...<!-- SPECKIT END -->` markers.

## Phase 1 Re-Check of Constitution Gates

After Phase 1 design, no new violations expected. The three new artifacts (data-model.md, contracts/*, quickstart.md) are documentation only and add no code surface. All memory-informed constraints (A1/A6/A9/B11/B12/DEV3) carry forward unchanged.

**Result**: gates remain green; ready for `/speckit-tasks`.
