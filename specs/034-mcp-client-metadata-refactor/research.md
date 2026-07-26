# Phase 0 — Research

**Feature**: F034 MCP Client Metadata + Filter-Aware Enumeration Refactor
**Date**: 2026-07-25

The spec + Clarifications section left two low-impact implementation decisions unresolved. Both are test-layer choices with no runtime code impact. Consolidated here for `/speckit-tasks` to reference when generating task IDs.

---

## Decision 1 — Concrete-client metadata test file organization

**Decision**: Single `tests/phpunit/MCPClients/ConcreteClientMetadataTest.php` with `@dataProvider`-parameterized assertions covering all eight built-in clients.

**Rationale**:
- Eight clients × five metadata methods × two assertions (non-empty + specific value) = 80 assertions. Per-client test files would produce 8 near-identical files each with the same shape (10 assertions apiece); rolling the shape into one file with a data provider keeps the maintenance burden linear in the shape, not linear in the client count.
- Follows the same pattern used by `tests/phpunit/OAuth/TokenValidatorTest.php` (data provider covering multiple grant types via one test method) and by `tests/phpunit/Abilities/PermissionOverrideProcessorTest.php` (F033's `test_wrapper_preserves_role_gated_denials` using `@dataProvider provide_wp_roles`).
- Adding a new built-in client in future = one new data-provider row (versus a new whole test file per client). Reduces PR diff churn.
- PHPUnit `@dataProvider` support is verified by existing tests in the `mcpclients` and `abilities` suites.

**Alternatives considered**:
- **Per-client `<Client>Test.php` files** — rejected. Reproduces the same test shape eight times; every future built-in client addition duplicates the boilerplate.
- **Hybrid (per-client for `get_config_snippet` behavioural tests, data-provider for metadata)** — rejected as premature since no existing per-client test files exist today. If future need arises for behavioural per-client tests, they can be added in separate files without disturbing the metadata test.

---

## Decision 2 — Render byte-identity regression test strategy

**Decision**: Hand-authored key-DOM-marker assertions in `tests/phpunit/Public/Renderers/MCPClientsBlockRenderTest.php` covering one representative built-in client (`claude-desktop`).

**Rationale**:
- No snapshot-testing infrastructure exists in the plugin today. Introducing one (spatie/phpunit-snapshot-assertions or similar) is out of scope per FR-017 (no files outside the two touched directories + tests).
- Hand-authored assertions can target the exact DOM markers that FR-016 (byte-identity) is meant to protect: emoji character in the sub-nav, config-file path text, top-level-key label text, first line of instructions. Missing any one of these signals a metadata-migration regression.
- `claude-desktop` is a representative choice: its metadata exercises the full spectrum (emoji `🍰`, macOS-flavored path, `mcpServers` key, multi-step instructions). One client suffices for the DOM-marker approach because the render pipeline is uniform — it iterates all clients through identical template code, so if the pipeline is correct for one client's metadata it is correct for all eight (the metadata migration correctness for the other seven is covered by `ConcreteClientMetadataTest.php` per Decision 1).
- Manual DOM-diff of all eight clients is captured under the spec's "Render-parity check (blocker before merge)" in the Manual Verification Checklist — that catches any anomaly the automated single-client assertion misses.

**Alternatives considered**:
- **Full DOM snapshot for all eight clients** — rejected. Requires new test dependency; produces brittle snapshots that fail on unrelated CSS class reordering.
- **DOM snapshot for one client** — rejected for the same dependency reason; hand-authored markers achieve the same regression protection with zero new dev-dependencies.
- **Skip automated render test, rely on manual DOM-diff only** — rejected. FR-016 is a MUST requirement; a merge-blocking manual check without a CI signal is fragile.

---

## Non-decisions (already resolved elsewhere)

The spec's Clarifications section already resolved the two HIGH/MEDIUM-impact ambiguities:

- **Sub-nav sort order** (Q1) — priority-based sort with built-in overrides `10, 20, ..., 80` preserving byte-identical visual order. See `spec.md` §Clarifications + FR-018.
- **`_doing_it_wrong` version tag** (Q2) — `'0.1.7'` (current release). See `spec.md` §Clarifications + FR-008/FR-009.

No further research needed. Proceed to Phase 1.
