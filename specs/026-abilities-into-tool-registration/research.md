# Phase 0 — Outline & Research

**Feature**: 026-abilities-into-tool-registration
**Status**: Complete — no `[NEEDS CLARIFICATION]` markers remain in the spec.

The Plan-mode conversation on 2026-07-14 (two rounds of AskUserQuestion after the initial user directive) resolved every design decision before `/speckit-specify` ran. This file records the decision path for each choice plus the alternatives considered.

---

## Decision 1 — Sibling method vs. overload of `compose_for_row()`

**Decision**: Add a new sibling static method `ToolPolicy::compose_effective_tools_for_row( Row ): string[]` alongside the existing `compose_for_row()`. Do NOT modify or overload `compose_for_row()`.

**Rationale**:
- `compose_for_row()` is used by three call sites: `Controller::register_database_servers()`, `Controller::filter_default_server_config()`, and `ToolsController::get_tools()`. The REST GET semantic is "what the operator explicitly picked in the Tools tab" — protocol columns + curated presence rows. Extending it to include F017-effective abilities would change the GET response shape and confuse operators.
- Two call sites (both in `Controller`) need the widened composition; one (`ToolsController::get_tools`) does not. A sibling method lets each caller pick.
- Same-file placement immediately after `compose_for_row()` makes the relationship discoverable — reviewers see the two composers together.

**Alternatives considered**:
1. **Overload `compose_for_row()` in-place** — rejected. Would change the REST GET response, breaking the F025 contract that the Tools tab UI reflects only the operator's explicit picks.
2. **Add a boolean flag** (e.g., `compose_for_row( Row $row, bool $include_effective_abilities = false )`) — rejected. Boolean flag parameters are a well-documented anti-pattern (they force every caller to think about a state they don't care about, and the default becomes semantically loaded).
3. **Compose inline in `Controller`** — rejected. Would violate DRY (`ExposureResolver::resolve()` iteration would live in two Controller methods) and put domain logic in the Entry/App layer instead of the Domain layer (`ToolPolicy`).

**Source of decision**: user answer to Plan-mode AskUserQuestion Q1 on 2026-07-14.

**Reference**: spec.md §Assumptions notes that this was a Plan-mode clarification.

---

## Decision 2 — Reuse F025's `acrossai_mcp_manager_server_tools` filter vs. add a new filter

**Decision**: Reuse the F025 filter `acrossai_mcp_manager_server_tools` verbatim. Same signature `(string[] $tools, MCPServer\Row $server)`. Same call site (`Controller::register_database_servers` at line ~162). Wider pre-filter composed set.

**Rationale**:
- Companion plugins hooking F025's filter see a strict superset of what they saw pre-F026. No callback needs to change.
- Adding a second filter (e.g., `acrossai_mcp_manager_server_abilities_as_tools`) would double the extension surface for a marginal separation-of-concerns benefit. Companion plugins already need to understand the composed set holistically.
- The filter's docblock is updated to declare the widened input; `docs/extending-server-tools.md` gets a corresponding update.

**Alternatives considered**:
1. **Add a new filter alongside** (e.g., `acrossai_mcp_manager_server_abilities` fires first, then `acrossai_mcp_manager_server_tools` on the merged result) — rejected. Doubles extension surface; forces companion plugins to hook two filters if they want to touch anything; asymmetry with the default-server path (which uses only the vendor `mcp_adapter_default_server_config`).
2. **Replace the F025 filter with a new one** — rejected. Breaking change for any companion plugin already hooking the F025 filter (even though F025 shipped only recently, breaking established contracts is a bad precedent).

**Source of decision**: user answer to Plan-mode AskUserQuestion Q2 on 2026-07-14.

**Reference**: `docs/extending-server-tools.md` receives Q2's amendment in this feature (task-4 in `docs/planings-tasks/026-abilities-into-tool-registration.md`).

---

## Decision 3 — REST GET behavior

**Decision**: `ToolsController::get_tools()` continues to call `compose_for_row()` (protocol + curated only). The F017-effective abilities are NOT surfaced in the Tools tab GET response.

**Rationale**:
- The Tools tab UI already shows a count and a list of "Added as tools" — extending that to include F017-effective abilities would either (a) inflate the count without a corresponding UI to add/remove them, or (b) require Tools tab UI changes that F026 explicitly avoids.
- The Abilities tab is the surface for per-server visibility overrides. Conflating the two tabs' semantics violates the operator's mental model.
- Preserves the F025 REST GET contract byte-for-byte — no third-party API consumer needs to update.

**Alternatives considered**:
1. **Include F017-effective abilities in the GET response** — rejected. See Rationale above.
2. **Add a query param** (e.g., `?include_effective=1`) to widen the GET conditionally — rejected. YAGNI. No known consumer.

**Source of decision**: implied by user's plan-mode answer that "GET should stay as-is" (recommended option) during the two-question round.

**Reference**: spec §FR-007, spec §User Story 3 (Tools tab UI is unchanged), and `docs/planings-tasks/026-abilities-into-tool-registration.md` TASK-3 (grep-only verification).

---

## Decision 4 — Fail-open when Abilities API absent

**Decision**: If `function_exists( 'wp_get_abilities' )` returns false, `compose_effective_tools_for_row()` skips the F017 pass and returns the F025 `compose_for_row()` output (protocol + curated). Silent — no `WP_Error`, no `error_log`, no admin notice.

**Rationale**:
- Matches F017's `AbilityExposureGate::gate_tool_call_by_exposure()` fail-open pattern (line 123–124 — returns `$args` unchanged when `wp_get_ability` is missing).
- Matches F025's `Controller::filter_default_server_config()` defensive short-circuits (return `$config` untouched when default row missing or composed set empty).
- Matches D19 (Fail-open observability pattern) — F017 already fires `acrossai_mcp_access_control_missing_server` on similar edge cases. F026 does not need to fire a new event because ability-registration state changes are already observable via `acrossai_mcp_ability_exposure_changed` (F017).

**Alternatives considered**:
1. **Fail-closed (WP_Error, halt registration)** — rejected. Breaks server registration on installs where the Abilities API is late-loaded or absent (edge cases: prerelease WP builds, aggressive plugin unload plugins).
2. **Log a WARNING via `error_log()`** — rejected. Log noise on every REST request during the edge case. Not actionable to operators.

**Source of decision**: consistent with existing F017 + F025 defensive patterns; codified as FR-003 in the spec.

**Reference**: spec §FR-003, spec §Edge Case §1, spec §SC-006.

---

## Decision 5 — Composition order

**Decision**: Composition order is protocol slugs first (`ToolPolicy::COLUMN_MAP` iteration order) → curated slugs next (insertion order from `MCPServerToolQuery::get_added_slugs`) → F017-effective abilities last (`wp_get_abilities()` iteration order). Dedup drops any repeats. Order is stable within a single call but NOT part of the public contract.

**Rationale**:
- Stable ordering aids reviewer traceability — reading `tools/list` output in production, the first N slugs are always protocol tools, the next M are always the operator's Tools tab picks, and the remainder is the F017 effective set.
- Non-contractual because `tools/list` MCP semantics do not depend on ordering — clients treat the array as an unordered set.
- Downstream deduplication (via `array_values( array_unique( array_map( 'strval', $tools ) ) )`) handles duplicates cleanly — a slug that appears as both a curated pick and an F017-effective ability collapses to one entry (order preserved by `array_unique`: first occurrence wins).

**Alternatives considered**:
1. **Sort alphabetically** — rejected. Loses the semantic grouping that helps reviewer scan.
2. **Match F017 Abilities tab's display order** — rejected. Abilities tab order is React-side sort; matching it would introduce an implicit contract between the UI and the composer.
3. **Randomize** — rejected. Non-deterministic order is a nightmare for reviewers.

**Source of decision**: implied by F025's existing `compose_for_row()` order (protocol → curated) plus the natural extension of "then F017-effective" for the new source.

**Reference**: spec §FR-011, `data-model.md`.

---

## Decision 6 — No new filter for the F017 pass

**Decision**: F026 does NOT emit a new filter after the F017 pass but before the F025 `acrossai_mcp_manager_server_tools` filter fires. The existing single filter receives the fully-composed set.

**Rationale**:
- One filter, one call site. Companion plugins get a unified view of the tool list. If they want to modify just the F017-effective abilities, they can filter on `array_diff( $tools, ToolPolicy::PROTOCOL_TOOLS )` inside their callback.
- Adding a second filter would break the "single extension seam" principle from F025.

**Alternatives considered**:
1. **New filter `acrossai_mcp_manager_effective_abilities` before merge** — rejected. Doubles extension surface.
2. **Fire the F025 filter twice** (once with just F025 tools, once with F026-widened tools) — rejected. Idempotency headaches; callbacks would fire on both passes and have to detect which one they're in.

**Source of decision**: implied by Decision 2 (reuse F025 filter).

---

## Retrieval Notes

- Sources consulted: `spec.md` (13 FRs, 3 stories, 6 SCs), `memory-synthesis.md`, `.specify/memory/constitution.md`, `docs/planings-tasks/026-abilities-into-tool-registration.md` (pre-drafted planning doc), `docs/memory/INDEX.md`.
- F025's `research.md` (`specs/025-server-tools-registration-hooks/research.md`) consulted as a parallel-shape reference — same numbered-decision structure applies.
- Zero web research required — every decision was locally sourced from user directives + prior features' patterns.
- No `[NEEDS CLARIFICATION]` markers in the spec at any point.
