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

---

## F026 v3 decisions (2026-07-15)

Post-shipping design decisions from the refactor + revert arc. Each links to the commit that carried it out.

### Decision 7 — Callback swap via `wp_register_ability_args` over unregister+re-register (commit `070ffe2`)

**Chosen**: hook WP core's `wp_register_ability_args` filter and swap `execute_callback` + `permission_callback` on the three vendor slugs at registration time. Vendor's schema/label/description/category/annotations are preserved.

**Rationale**:
- No priority race, no `_doing_it_wrong` noise.
- Zero vendor callback duplication → no schema-drift risk if the vendor updates its abilities.
- Third-party plugins that hooked the vendor's original ability instances continue to work unchanged.
- `~120 LOC` of new module code vs. `~600–800 LOC` for unregister + full class copies.

**Alternatives considered**:
1. **Unregister + re-register with plugin-owned copies** (user's original request) — rejected after Plan-agent analysis. Detailed trade-offs in `docs/planings-tasks/026-abilities-into-tool-registration.md` and this feature's git history around 2026-07-14→15.
2. **Post-hoc intercept via `mcp_adapter_pre_tool_call` + `mcp_adapter_tool_call_result`** (commit `4ca9db4`, superseded) — shipped as an interim step but had metadata-enumeration hazard because the ability still executed fully before being post-filtered. Callback-swap enforces BEFORE execution.

**Source of decision**: user AskUserQuestion answer during plan mode on 2026-07-15, "Yes — replace with unregister + re-register" was overridden by intermediate plan-agent pressure-test that surfaced the callback-swap approach as strictly superior on the trade-offs matrix.

### Decision 8 — Revert F026 v1 tools-widening (commit `0e122e2`)

**Chosen**: `tools/list` returns only the F025 protocol columns + F020 curated slugs. Abilities are NOT advertised as top-level tools. AI clients reach them through the three built-in meta tools (whose callbacks respect Abilities-tab visibility via Decision 7).

**Rationale**:
- Fixes the Tools-tab UI vs `tools/list` mismatch (operator saw 3 tools, AI saw 3 + N).
- Cleaner mental model: tools = operator's Tools-tab picks; abilities = introspectable via meta tools.
- Symmetric with F017's own design intent — F017 was per-server visibility, not per-server tool advertising.
- Preserves F026 v2's resources/prompts widening (no equivalent meta tools exist for those types).

**Alternatives considered**:
1. **Keep F026 v1 widening** — rejected on operator feedback.
2. **Drop F020 curated tools too** (only 3 built-ins in `tools/list`) — rejected via AskUserQuestion; F020 is operator-authored intent and should be preserved.

**Source of decision**: user AskUserQuestion answer on 2026-07-15, "Yes — revert F026 tools-widening (Recommended)" + "Leave as-is (Recommended)" for resources/prompts.

### Decision 9 — `AbilityHelpers::apply_exposure_filter` default = `ExposureResolver::resolve()` (commit `e0189b0`)

**Chosen**: the `acrossai_mcp_is_ability_exposed` filter's default value comes from `ExposureResolver::resolve( $server_id, $slug, $meta )` when an MCP request context is available (via `CurrentServerHolder`), fallback to `meta.mcp.public` when not (CLI, cron, direct `wp_get_ability()->execute()`).

**Rationale**:
- The Abilities tab is the operator's per-server override surface — the plugin MUST honor it by default. Requiring companion code to hook the filter would leak F017's semantics into every downstream consumer.
- `DEC-ABILITY-OVERRIDE-RESOLUTION` mandates a single canonical resolver — re-deriving exposure inline was a violation.
- Fixes the "1 of 6 abilities returned" bug the operator hit on 2026-07-15.

**Alternatives considered**:
1. **Keep `meta.mcp.public` as the default, wire an internal filter callback to add F017** — rejected. Adds indirection without benefit; the filter shape stays the same either way.
2. **Bake F017 into `Discover::execute()` directly, skip the filter for the default** — rejected. The filter is the extension seam for companion code; the default value is the right layer to encode the plugin's per-server semantic.

**Source of decision**: operator bug report + code inspection on 2026-07-15.

### Decision 10 — F020 `EXCLUDED_SLUGS` accepts vendor-sanitized names (commit `69e689c`)

**Chosen**: `ToolExposureGate::EXCLUDED_SLUGS` lists both the raw ability form (`mcp-adapter/discover-abilities`) and the vendor-sanitized form (`mcp-adapter-discover-abilities`).

**Rationale**:
- Vendor's `McpNameSanitizer::sanitize_name` swaps `/` → `-` when registering an ability as an MCP tool. The client-facing tool name (what arrives at `mcp_adapter_pre_tool_call` as `$tool_name`) is the hyphen form.
- The pre-existing raw-form-only list never matched — F020 rejected all three built-in meta tools with `acrossai_mcp_tool_not_added`.
- The gap survived undetected because pre-`070ffe2` nobody actually invoked the meta tools via `tools/call` — they were vendor plumbing.

**Alternatives considered**:
1. **Sanitize at compare time** (call `McpNameSanitizer::sanitize_name` on both sides in the gate) — rejected. Adds vendor coupling at gate time; constant is simpler.
2. **Use `wp_get_ability()` to reverse-resolve the sanitized name → raw slug** — rejected. Sanitizer isn't reversible (`/` → `-` is destructive).

**Source of decision**: operator bug report on 2026-07-15 ("This tool is not enabled on this MCP server") + code trace.
