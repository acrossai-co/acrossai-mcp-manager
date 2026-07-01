# Memory Synthesis

## Current Scope

Phase 8 finalizes the v0.0.4 → WPBoilerplate asset migration. Scope: (1) add missing `css/frontend-oauth` webpack entry + create `src/scss/frontend-oauth.scss` from v0.0.4 source; (2) verify content parity for pre-existing `src/scss/{backend,frontend}.scss` + `src/js/backend.js`; (3) CLOSE the current defect in `public/Main.php` where `enqueue_styles/scripts` runs UNGUARDED on every front-end page (measurable global asset leak); (4) reconcile with Phase 7's `FrontendAuth::enqueue_assets()` so the CLI consent surface is enqueued exactly once; (5) verify `admin/Main.php` already-Phase-8-compliant behavior. No new business logic. No REST routes, no DB, no forms, no transient — no security surface expansion. Two enqueue paths touched (`admin/Main.php` verify only, `public/Main.php` extend).

## Relevant Decisions

- **D5 — PHPCS baseline exceptions** (Active, `docs/memory/DECISIONS.md`). Reason: Phase 8 edits `public/Main.php`; must preserve the existing PHPCS baseline (WP.Files.FileName.NotHyphenatedLowercase exclusion, `$_instance` prefix carve-out, etc.). No new exclusions expected.

## Active Architecture Constraints

- **A1 — All hook registration in Main.php** (`docs/memory/ARCHITECTURE.md`). Reason: `admin_enqueue_scripts` + `wp_enqueue_scripts` actions MUST be wired in `Includes\Main::define_admin_hooks()` and `define_public_hooks()` respectively (already wired for Phase 2 admin + Phase 7 public). Phase 8 changes method BODIES, not hook wiring — no new `add_action` calls anywhere.
- **A9 — Shared constants in `includes/Utilities/`** when read by ≥2 modules. Reason: `AdminPageSlugs::plugin_screen_ids()` is the canonical whitelist source for admin enqueue guard; Phase 8's verification must confirm `admin/Main.php` consumes it (already does). Public-side may need an equivalent predicate (query var for CLI, OAuth-consent predicate for OAuth) — those are per-surface, not shared, so A9 does not force extraction.
- **A6 — `use` imports in `Public\*`** (defends B1). Reason: `public/Main.php` extensions must use proper `use` imports for any cross-namespace refs (e.g. if delegating to Phase 7's FrontendAuth). Currently the file has minimal imports; watch for regression.

## Accepted Deviations

- **DEV3 — Bidirectional Phase 6 ↔ Phase 7 coupling pending T044 A9 promotion** (`docs/memory/INDEX.md`, 2026-06-30). Reason: NOT triggered by Phase 8 — but Phase 8 must AVOID creating a parallel bidirectional Phase 7 ↔ Phase 8 coupling. Two options for FR-020 reconciliation exist; the memory-informed choice is option (b) below.

## Relevant Security Constraints

- **S9 — Consent-surface displayed-state from authoritative store** (`docs/memory/PROJECT_CONTEXT.md`, 2026-06-30). Reason: NOT directly triggered by Phase 8 (no consent-surface rendering). However, Phase 7's consent surface is what Phase 8's `public/Main.php` must reconcile with — the reconciliation must not disturb Phase 7's S9 defenses. Any `public/Main.php` enqueue-path guard MUST use `get_query_var('acrossai_mcp_auth')` semantics (Phase 7's authoritative predicate), not a duplicated URL-inspection.

## Related Historical Lessons

- **B11 — Transient-stored arrays MUST use defensive triple-check on read** (`docs/memory/BUGS.md`). Reason: **Generalizes directly to `.asset.php` manifest reads.** The `require build/*.asset.php` return value should be validated with `is_array + isset('version','dependencies') + is_string(version)` before use — same shape guard, just applied to a `require`-returned array instead of a `get_transient` result. `admin/Main.php::read_asset_manifest()` already applies this pattern (line ~75 in Phase 2 impl); `public/Main.php` MUST adopt the same guard (currently uses bare `include` — a warning-hazard on missing/corrupt manifest).
- **B12 — `wp_enqueue_scripts` does not fire when `template_redirect` exits before `wp_head()`** (`docs/memory/BUGS.md`, 2026-06-30, Feature-007 lesson). Reason: **Directly informs FR-020 reconciliation strategy.** Phase 7's `FrontendAuth::render_page_shell()` calls `$this->enqueue_assets()` explicitly because the `wp_enqueue_scripts` action wired via Loader never fires on its `template_redirect`-exit path. If Phase 8's `public/Main.php::enqueue_styles` (wired to `wp_enqueue_scripts`) tried to handle the CLI surface, it would never fire — the enqueue would be dead code. This is the load-bearing argument for **option (b)** in the conflict below.

## Conflict Warnings

**Soft conflict (SPEC FR-020 vs. memory)**: The spec's FR-020 leaves the reconciliation strategy to `/speckit-plan` — "delegate to Phase 7 for CLI vs. narrow public/Main.php to OAuth-only". Memory context resolves this in favor of **option (b): narrow `public/Main.php` to OAuth-consent scope only**. Reasoning:

1. **B12 makes option (a) mechanically incorrect**: `public/Main.php::enqueue_styles` is wired to `wp_enqueue_scripts`, which never fires on Phase 7's `template_redirect`-exit path. Delegating to Phase 7 from an action that never runs is a no-op.
2. **DEV3 makes option (a) architecturally undesirable**: introducing a `public/Main.php` → `Public\Partials\FrontendAuth` dependency creates a parallel bidirectional coupling risk (FrontendAuth already depends on `Includes\REST\CliController`). A9 promotion (T044) hasn't happened yet; Phase 8 should not compound the coupling problem.
3. **Option (b) has clean boundaries**: `public/Main.php` handles OAuth consent (Phase 5's rendering path fires `wp_head()`, so `wp_enqueue_scripts` DOES run on that surface). Phase 7 keeps sole ownership of CLI-consent enqueue. No cross-module reads.

Plan should adopt option (b). If future analysis surfaces a case where the CLI surface renders through `wp_head()` (e.g. a Site Editor integration), revisit.

**No hard conflicts.** All FRs align with Constitution §I–§VII (Phase 8 introduces no capability check, no forms, no REST — §III surface is null). §III Consent-surface exception is a Phase 7/Phase 5 concern; Phase 8 preserves both.

## Retrieval Notes

- **Read**: `.specify/extensions/memory-md/config.yml` (optimizer.enabled: false → markdown-only), `docs/memory/INDEX.md` (76 lines, entire), spec §Requirements + §Context.
- **Selected**: 1 decision (D5), 3 architecture constraints (A1/A6/A9), 1 accepted deviation (DEV3), 1 security constraint (S9), 2 bug patterns (B11/B12). Total: 8 entries. Under budget (5/5/3/3/3 max = 19).
- **Not read** (below budget): `DECISIONS.md`, `ARCHITECTURE.md`, `BUGS.md`, `PROJECT_CONTEXT.md` source sections — INDEX.md summary lines are sufficient for the 8 selected entries.
- **Full memory read**: NOT performed. Budget respected.
- **Phase-aware**: Specify/Plan mode → prioritized boundary definitions (A1/A6/A9 module ownership) and architectural drift risks (DEV3 avoidance, B12 mechanical constraint).
- **Word count**: ~880 words. Within 900-word budget.
