---
document_type: security-review
review_type: plan
assessment_date: 2026-07-14
codebase_analyzed: acrossai-mcp-manager (Feature 026 planning artifacts)
total_files_analyzed: 8
total_findings: 3
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 0
informational_count: 3
owasp_categories: [A04, A08]
cwe_ids: [CWE-441, CWE-1188]
field_summaries:
  document_type: "Always 'security-review'. Allows indexers to skip non-review documents."
  review_type: "Which command generated this document: audit, branch, staged, plan, tasks, or followup."
  assessment_date: "ISO 8601 date the review was performed (YYYY-MM-DD)."
  overall_risk: "Highest severity tier with active findings (CRITICAL, HIGH, MODERATE, LOW, INFORMATIONAL)."
  critical_count: "Number of Critical findings (CVSS 9.0-10.0)."
  high_count: "Number of High findings (CVSS 7.0-8.9)."
  medium_count: "Number of Medium findings (CVSS 4.0-6.9)."
  low_count: "Number of Low findings (CVSS 0.1-3.9)."
  informational_count: "Number of Informational findings."
  owasp_categories: "OWASP Top 10 2025 categories (A01-A10) that have at least one finding."
  cwe_ids: "CWE identifiers referenced in this document."
  finding_id: "Unique finding identifier (SEC-NNN) for cross-referencing and task linkage."
  location: "File path and line number of the vulnerable code (path/to/file.ext:line)."
  owasp_category: "OWASP Top 10 2025 category for this finding (AXX:2025-Name)."
  cwe: "Common Weakness Enumeration identifier with short name (CWE-NNN: Name)."
  cvss_score: "CVSS v3.1 base score (0.0-10.0). 9.0+=Critical, 7.0-8.9=High, 4.0-6.9=Medium, 0.1-3.9=Low."
  spec_kit_task: "Spec-Kit task ID for backlog tracking and remediation follow-up (TASK-SEC-NNN)."
---

# Security Review — Feature 026 Plan

## Executive Summary

**Feature**: 026-abilities-into-tool-registration
**Branch**: `026-abilities-into-tool-registration`
**Plan artifact under review**: `specs/026-abilities-into-tool-registration/plan.md` (2026-07-14)
**Overall risk**: **LOW** — 0 Critical / 0 High / 0 Moderate / 0 Low / 3 Informational.

F026 adds a new stateless helper `ToolPolicy::compose_effective_tools_for_row()` that widens F025's composed tool list with a third source — every ability where `ExposureResolver::resolve()` returns true. Two F025 call sites in `Controller` swap to the new composer. F017's storage, resolver, and call-time gate are untouched. The F025 filter `acrossai_mcp_manager_server_tools` is reused with a strict-superset pre-filter input. No new REST route, no new user input, no new database writes, no new secrets handling, no new outbound HTTP, no new dependencies, no schema change, no JS, and no vendor code touches.

F026 is fundamentally a read-only extension of existing patterns. All security-relevant behavior carries over from F017 (per-server ability visibility overrides + `AbilityExposureGate` at call-time) and F025 (registration-time composer + `acrossai_mcp_manager_server_tools` filter). The Constitution §III checklist gates pass without exception.

Three INFORMATIONAL findings surface: two are carry-over patterns from F017 / F025 (worth documenting for reviewer awareness), and one is a semantic clarification about F026's implicit `mcp.public = true` opt-in behavior (a design characteristic, not a vulnerability).

## Plan Artifacts Reviewed

- `specs/026-abilities-into-tool-registration/plan.md` (technical context, constitution check, project structure)
- `specs/026-abilities-into-tool-registration/spec.md` (13 FRs, 3 stories, 6 SCs, edge cases, security checklist)
- `specs/026-abilities-into-tool-registration/research.md` (6 decisions, alternatives considered)
- `specs/026-abilities-into-tool-registration/data-model.md` (zero schema delta, one method, contracts with F017/F025)
- `specs/026-abilities-into-tool-registration/contracts/filter-acrossai_mcp_manager_server_tools-widened.md`
- `specs/026-abilities-into-tool-registration/quickstart.md`
- `specs/026-abilities-into-tool-registration/memory-synthesis.md`
- `docs/planings-tasks/026-abilities-into-tool-registration.md` (pre-drafted planning doc)

**Memory hub context**: `.specify/memory/constitution.md` v1.1.0; `docs/memory/INDEX.md` (`DEC-ABILITY-OVERRIDE-RESOLUTION` F017, `DEC-F025-HYBRID-TOOL-STORAGE-PROTOCOL-VS-CURATED` F025, `DEC-F020-TOOL-ENFORCEMENT-PRIORITY` F020, S1–S9, B18/B23/B29); F025 plan reviews (v1 + v2) and tasks-review (2026-07-14) as directly-precedent artifacts.

**Not consulted (not applicable)**: OAuth flow docs (F016 retired); Freemius integration docs (F022, orthogonal).

## Vulnerability Findings

### SEC-026-INFO-1 — F017 confused-deputy surface carries into F026's widened filter input

| Field | Value |
|---|---|
| **Finding ID** | SEC-026-INFO-1 |
| **Location** | `specs/026-abilities-into-tool-registration/contracts/filter-acrossai_mcp_manager_server_tools-widened.md` §"Backwards compatibility guarantees" + §"Interaction with the Abilities tab" |
| **Severity** | INFORMATIONAL |
| **CVSS v3.1** | 0.0 (documented design, not a runtime vulnerability) |
| **OWASP Top 10 2025** | A04:2025 — Insecure Design (documentation gap only) |
| **CWE** | CWE-441 — Unintended Proxy or Intermediary ('Confused Deputy') |
| **Spec-Kit task** | TASK-SEC-026-1 |

**Observation**: F025 shipped with SEC-025-INFO-1 — the `acrossai_mcp_manager_server_tools` filter allows companion plugins to add OR remove any slug in the composed tool list, including protocol slugs the operator has explicitly enabled. Under F026, the same surface widens: a companion filter callback can also strip F017-effective abilities that the operator explicitly enabled via the Abilities tab. Symmetrically, a companion callback can add back an ability the operator hid via the Abilities tab, silently overriding the operator's UI-facing decision.

Concretely: an operator toggles `my-plugin/sensitive-action` OFF for server X on the Abilities tab (writes `is_exposed = 0` row). A companion plugin's callback returns `array_merge( $tools, [ 'my-plugin/sensitive-action' ] )`. The composed set post-filter includes the slug; `tools/list` advertises it; but F017's call-time gate (priority 20 on `mcp_adapter_pre_tool_call`) still fires 403 `acrossai_mcp_ability_not_exposed` on any `tools/call` attempt. Net effect: the ability is advertised but not callable — misleading, not exploitable.

**Why this is not a new vulnerability**:
- Companion plugins are privileged code by the WordPress trust model (they run `include`-level PHP).
- F017's call-time gate remains authoritative — a companion filter cannot bypass it.
- F026 makes the confused-deputy surface no wider than F025's original: it just extends the pre-filter set from `(protocol + curated)` to `(protocol + curated + F017-effective)`.

**Recommendation** (non-blocking):
- Ensure the new §"Interaction with the Abilities tab" section in `docs/extending-server-tools.md` explicitly cross-references SEC-025-INFO-1 and notes that F017 overrides can be similarly circumvented by companion plugins at the advertising layer (but NOT at the call layer).
- No code change to plan.

**Blocking?** No.

---

### SEC-026-INFO-2 — B23 test-suffix method usage (`ExposureResolver::_reset_cache_for_tests()`)

| Field | Value |
|---|---|
| **Finding ID** | SEC-026-INFO-2 |
| **Location** | `specs/026-abilities-into-tool-registration/data-model.md` §"Test data model (PHPUnit fixtures)"; `includes/Database/MCPServerAbility/ExposureResolver.php:84` (F017 shipping code that F026 tests will invoke) |
| **Severity** | INFORMATIONAL |
| **CVSS v3.1** | 0.0 (test-code dependency; not a runtime concern) |
| **OWASP Top 10 2025** | A08:2025 — Software and Data Integrity Failures |
| **CWE** | CWE-1188 — Initialization of a Resource with an Insecure Default (test-hook exposure lens) |
| **Spec-Kit task** | TASK-SEC-026-2 |

**Observation**: F017 shipped `ExposureResolver::_reset_cache_for_tests()` as a `public static` method with a leading-underscore name intended to convey "internal / test-only". Per memory hub `B23`, test-suffix method names called from production code paths (or by production-adjacent code) are a silent-regression smell — a maintainer removing the method during cleanup silently breaks an invariant that the call site relied on.

F026 introduces one new dependency on this method: `tests/phpunit/Database/MCPServer/ToolPolicyTest.php::setUp()` will call it to clear F017's per-request static cache between tests. This is a legitimate test-only dependency, not a production one.

**Why this is not a real vulnerability**:
- The method is called only from test setUp/tearDown — no production code path.
- If F017 ever removes the method, F026's tests will fail loudly (fatal `Undefined method`), surfacing the regression immediately rather than silently.
- The method's behavior is a cache reset — mutating it is not an exploitation vector.

**Why it deserves a mention**:
- B23 identifies this class of method-name convention as fragile. F026 doubling down on it is worth explicit acknowledgment.

**Recommendation** (non-blocking; future refactor eligible):
- Consider whether to rename `_reset_cache_for_tests()` to `clear_request_cache()` (production-shape name) in a future F017 maintenance PR. Would require coordinated updates across F017's own tests + F026's new tests. Out of scope for F026.
- No code change to F026 plan. B23 lesson is durable; noted for future maintainers via this review.

**Blocking?** No.

---

### SEC-026-INFO-3 — `mcp.public = true` is implicit opt-in for every ability without operator override

| Field | Value |
|---|---|
| **Finding ID** | SEC-026-INFO-3 |
| **Location** | `specs/026-abilities-into-tool-registration/spec.md` §Edge Cases §"What if a curated ability has `mcp.public = false` AND no per-server override?"; `specs/026-abilities-into-tool-registration/research.md` §Decision 4 |
| **Severity** | INFORMATIONAL |
| **CVSS v3.1** | 0.0 (design characteristic, not a vulnerability) |
| **OWASP Top 10 2025** | A04:2025 — Insecure Design (documentation clarity) |
| **CWE** | (no direct CWE; design-boundary documentation note) |
| **Spec-Kit task** | TASK-SEC-026-3 |

**Observation**: F026 makes `mcp.public = true` a **live opt-in signal for every enabled MCP server** at advertisement time, not just at call time. Previously (F017 only), an ability with `mcp.public = true` was ONLY callable — never advertised in `tools/list`. Post-F026, the same ability appears in `tools/list` on every enabled server by default. Adding a third-party plugin that registers an ability with `meta.mcp.public = true` immediately exposes that ability's presence to every AI client connecting to any server on the site — no per-server operator opt-in required.

Concretely: operator installs a third-party plugin that registers `evil-plugin/leak-config` with `mcp.public = true`. Every MCP server's `tools/list` now advertises this tool. Any AI client can see the tool exists and read its description (which may leak configuration details) even without ever calling it. Operators who want opt-in-only behavior must write `is_exposed = 0` rows per server per ability.

**Why this is not a real vulnerability**:
- `mcp.public = true` is the ability author's declaration that the ability is safe for public MCP exposure. It's the same signal F017's call-time gate has always used.
- F026 makes the signal MORE consistent (advertised == callable), not less secure.
- Third-party plugins that would register abilities with `mcp.public = true` inappropriately are the same threat model that existed pre-F026 — they could already leak the ability at call time.
- F017's Abilities tab is the operator's remediation surface: toggle OFF per server to write `is_exposed = 0`.

**Why it deserves a mention**:
- Operators upgrading to F026 may see abilities appear in `tools/list` that they didn't consciously opt in to. Documentation should make this explicit so operators know where to look (Abilities tab) to override.
- No new attack vector, but a UX-boundary shift worth highlighting.

**Recommendation** (non-blocking):
- Ensure `docs/extending-server-tools.md` §"Interaction with the Abilities tab" (added in F026 TASK-4) explicitly notes: **"Adding a third-party plugin that registers abilities with `meta.mcp.public = true` will immediately expose those abilities in `tools/list` on every enabled MCP server. To opt out per server, use the Abilities tab to toggle each ability OFF."**
- Consider a future feature (out of scope for F026): a plugin-wide setting `acrossai_mcp_manager_public_abilities_default_hidden` that flips the ExposureResolver fallback to `false` when no override row exists. This would make F026's behavior opt-in per site rather than opt-in per ability.
- No code change to F026 plan.

**Blocking?** No.

## Confirmed Secure Patterns

The following aspects of the plan explicitly satisfy Constitution §III and the plugin's durable security constraints (S1–S9 in memory hub):

1. **S2 — REST `permission_callback` preserved.** F026 modifies ZERO REST routes. `ToolsController` GET/POST retain `manage_options` (F025 baseline); `AbilitiesController` GET/POST retain `manage_options` (F017 baseline); no new endpoints. `plan.md` §Constitution Check confirms.
2. **S4 — Prepared statements.** F026 READS via `MCPServerAbility\Query::instance()->query()` inside `ExposureResolver::resolve()` — BerlinDB Kern prepared path. Zero raw `$wpdb->query()` calls added. `data-model.md` §"Storage layers" confirms.
3. **S1 — Nonces inherited from F017/F025.** No new POST endpoints; nonce middleware flow unchanged.
4. **No new user input.** F026 introduces no REST POST accept, no admin form handler, no `$_GET`/`$_POST` reading. All new code paths are internal composition + resolver lookup.
5. **No new output escaping surface.** F026 makes no HTML output changes. The composed tool list is a JSON payload in MCP JSON-RPC responses (vendor-controlled); each slug is `strval`'d before being passed to `create_server()`.
6. **`ExposureResolver` is A11 pure-service (F017 baseline).** Stateless; per-request static cache; no persistence; no side effects. F026 consumes it read-only.
7. **B18 already handled at the F017 layer.** `wp_acrossai_mcp_server_abilities.is_exposed` is `tinyint(1)`; F017's `ExposureResolver::resolve()` casts to `bool` at its own line 69. F026 receives a correctly-typed `bool` return — no new B18 defense needed at the F026 layer.
8. **B24 — no new vendor-accessor via `instanceof`.** F026's Controller call-site swaps preserve the F025-established `create_server( ..., $tools, ... )` invocation shape. No new duck-typed feature detection.
9. **B29 — vendor abilities-registration timing NOT applicable.** F026 iterates `wp_get_abilities()` at `mcp_adapter_init` firing time — that action runs AFTER `wp_abilities_api_init` on which third-party abilities register. So third-party abilities ARE present. The three vendor MCP protocol slugs (which B29 covered as bootstrap-timing-blind) are NOT relevant to F026 since they come from `ToolPolicy::PROTOCOL_TOOLS` columns, not `wp_get_abilities()`. Fail-open branch (FR-003) provides belt-and-braces safety for edge cases.
10. **Fail-open pattern matches F017/F025 posture.** When `wp_get_abilities()` unavailable, F026 skips the F017 pass and returns F025 output. No fatal, no log, no admin notice. Matches D19 fail-open observability contract.
11. **F017 storage layer is READ-only from F026.** No INSERT/UPDATE/DELETE to `wp_acrossai_mcp_server_abilities`. Data integrity is F017's contract; F026 does not touch it.
12. **`AbilityExposureGate` at priority 20 is untouched.** F026 does not modify F020's priority slot map (10/20/30). Deny precedence honored: a companion filter that adds an F017-hidden slug at advertising time cannot bypass F017's call-time gate. `mcp_adapter_pre_tool_call` chain intact.
13. **Zero new dependencies.** No `composer.json`, `package.json`, `composer.lock`, `package-lock.json` changes.
14. **Zero vendor edits.** `vendor/wordpress/mcp-adapter/` untouched. Verified by planned grep audits.
15. **Zero new outbound HTTP.** F026 introduces no `wp_remote_get()` / `wp_remote_post()` / `curl` / `fetch()`. Same inbound-only REST surface as F025.

## Action Plan & Next Steps

### 1. Recommended non-blocking edits (before merge, not before implementation)

- **SEC-026-INFO-1** — cross-reference SEC-025-INFO-1 in `docs/extending-server-tools.md` §"Interaction with the Abilities tab" (planning-doc TASK-4). One sentence.
- **SEC-026-INFO-3** — add explicit "`mcp.public = true` is site-wide opt-in for every ability by default; opt out per server via the Abilities tab" note in the same doc section. One paragraph.
- **SEC-026-INFO-2** — no F026 edit needed. Noted for future F017 maintenance PR (consider renaming `_reset_cache_for_tests()` → `clear_request_cache()`).

### 2. Durable Memory Preservation

The three findings are per-feature clarity observations, not new architectural patterns or repeatable lessons. No `/speckit-memory-md-capture` invocation warranted from this security review alone.

**Deferred (post-implementation)**: consider whether F026's clean re-use pattern warrants updating F025's `DEC-F025-HYBRID-TOOL-STORAGE-PROTOCOL-VS-CURATED` decision with an "F026 is the second use of this pattern" annotation. That's a maintenance edit best done after F026 lands, not blocking this review.

### 3. Remediation Planning

No CRITICAL or HIGH findings surfaced. `/speckit-security-review-followup` NOT required. Address the three INFO findings as inline TODOs during implementation Task 4 (docs update).

### 4. Proceed to Architecture Guard

The plan is safe to hand off to `/speckit-architecture-guard-violation-detection` (Step 5 of the parent `governed-plan` workflow).

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-14-026-abilities-into-tool-registration-plan.md | plan | 2026-07-14 | LOW | C:0 H:0 M:0 L:0 I:3 | A04,A08 |
```
