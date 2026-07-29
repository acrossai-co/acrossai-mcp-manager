---
document_type: security-review
review_type: tasks
assessment_date: 2026-07-29
codebase_analyzed: acrossai-mcp-manager (F038 tasks.md)
total_files_analyzed: 9
total_findings: 3
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 1
informational_count: 2
owasp_categories: [A01, A03]
cwe_ids: [CWE-693, CWE-1104]
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

# Security Review — F038 tasks.md

## Executive Summary

The F038 tasks.md (31 tasks across Setup + US1 + US2 + US3 + Polish) is well-sequenced and preserves the plan-review findings SEC-001..SEC-005 through explicit implementation tasks. Every grep-gate from `spec.md` §Definition of Done is tasked (T023). PHPCS + PHPStan + npm validate-packages run before merge (T024–T026). The manual quickstart walkthrough (T027) covers the fail-open cascade (Test 7), double-render single-`<style>` (Test 8), and third-party extensibility (Test 9).

Three minor gaps surfaced:

1. **SEC-T-001 (LOW)** — SEC-002 filter-boundary trust disclosure and SEC-004 non-goals documentation land in `contracts/` but are NOT explicitly tasked into the class-file docblocks. A companion-plugin author reading only the class docblock (via IDE tooltip) would miss both. Task T007 handles SEC-001 caller-authority; needs a sibling clause for SEC-002 + SEC-004.
2. **SEC-T-002 (INFO)** — `AbstractUserServersRenderer::get_accessible_servers()` is not tasked to defensively coerce a non-array return from the `acrossai_mcp_user_accessible_servers` filter. A buggy filter returning `null` / `false` / string would produce a PHP `TypeError` at the declared `array` return type, fataling the frontend page. This is *arguably* correct fail-loud behavior — but not documented as a deliberate design choice.
3. **SEC-T-003 (INFO)** — Test tasks (T014, T015) are enumerated after production tasks (T005–T013). Not a strict TDD ordering. Because F038's security-critical logic is inside `get_accessible_servers()` (T006, one method), the risk is small — but a red-team could argue an implementer sees the production shape before writing the test and unconsciously mirrors it. Marking T014 with `[P]` and referencing "run parallel to production" would make the intent explicit.

Overall risk: **LOW**. No CRITICAL, HIGH, or MODERATE findings. Every finding is a documentation / sequencing hardening item — no missing security work.

## Tasks Reviewed

| Phase | Tasks | Security-relevant highlights |
|-------|-------|------------------------------|
| Setup | T001–T004 | T001 verifies F015 + F037 contracts present before F038 code begins (upstream security foundation intact). T003–T004 register PHPUnit suite before implementation (test-first infra). |
| Foundational | none | F038 introduces no new schema / REST / admin. Correctly skipped. |
| US1 (P1 MVP) | T005–T016 | **T006 implements the gate cascade** (F015 → F037 → filter). **T007 explicit SEC-001 docblock task**. T010 escape-at-boundary + filter round-trip in `render_shortcode`. T014 covers 11 abuse cases including F015 deny, F015 fail-open, filter round-trip, missing-slug DTO drop. T015 covers XSS-context tests (`test_escape_at_boundary`) + singleton private-ctor reflection assertion. |
| US2 (P1) | T017–T019 | T018 fake fourth-transport smoke test verifies filter-driven composition without F038 changes (SC-005). |
| US3 (P2) | T020–T022 | T020 comment references SEC-004 (filter is mutation seam, not gate-bypass). T021 comment references SEC-002 (filter return un-sanitized). |
| Polish | T023–T031 | T023 all 5 grep-gates verified. T024 PHPCS. T025 PHPStan L8. T027 manual quickstart Tests 1–11 incl. SEC-relevant Tests 7 (fail-open) + 8 (single style). Memory hygiene T029–T031. |

Also loaded: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/AbstractUserServersRenderer.contract.md` (with SEC-001 + SEC-004 subsections), `contracts/UserServersBlock.contract.md` (with SEC-002 + SEC-005 subsections), `memory-synthesis.md`, prior plan review `docs/security-reviews/2026-07-29-037-user-accessible-mcp-servers-shortcode-plan.md`.

## Vulnerability Findings

### SEC-T-001 (LOW) — SEC-002 + SEC-004 disclosures not tasked into class docblocks

- **Finding ID**: SEC-T-001
- **Location**: `specs/037-user-accessible-mcp-servers-shortcode/tasks.md` — T007 (only SEC-001 tasked)
- **OWASP category**: A01:2025 — Broken Access Control (adjacent — trust-boundary documentation gap)
- **CWE**: CWE-1104 — Use of Unmaintained Third Party Components (adjacent — filter-boundary trust documentation)
- **CVSS v3.1 base score**: 2.5 (Low) — AV:N / AC:L / PR:L / UI:R / S:U / C:L / I:N / A:N
- **Spec-Kit task**: TASK-SEC-T-001

The plan review's SEC-002 (filter return un-sanitized) and SEC-004 (filter as mutation-not-gate seam) remediations were applied to the **`contracts/*.contract.md`** files. The tasks.md T007 explicitly tasks copying SEC-001 into the class docblock — but there is NO matching task for SEC-002 or SEC-004.

A companion-plugin author consuming F038 via IDE tooling typically reads the class docblock in their editor (hover / tooltip / Intelephense) but does not open the plugin's `specs/` directory. If SEC-002 + SEC-004 disclosures live only in `contracts/`, they are invisible at consumption time.

**Recommendation**:

Update T007 to broaden its scope, OR add sibling tasks:

```markdown
- [ ] T007 [US1] Include the SEC-001 caller-authority responsibility subsection verbatim
      in the AbstractUserServersRenderer class docblock (top-of-file), AND include the
      SEC-004 non-goals bullet ("filter is mutation seam, not gate-bypass") on the
      apply_filters( 'acrossai_mcp_user_accessible_servers' ) docblock inline (in-source
      documentation of the filter contract).

- [ ] T010a [US1] Include the SEC-002 filter-boundary trust disclosure verbatim in the
      UserServersBlock class docblock (top-of-file), AND include the SEC-005 static-CSS-only
      invariant near the private static $style_emitted flag declaration.
```

**Effort**: ~10 minutes. No production-code impact. Improves consumption-time discoverability of the trust-boundary contract. Recommend applying before implementation begins.

### SEC-T-002 (INFO) — `AbstractUserServersRenderer::get_accessible_servers()` non-array filter-return coercion not explicitly tasked

- **Finding ID**: SEC-T-002
- **Location**: `specs/037-user-accessible-mcp-servers-shortcode/tasks.md` — T006 (algorithm step 6 "Fire filter" doesn't specify coercion)
- **OWASP category**: A03:2025 — Injection (adjacent — reliability, not directly injection)
- **CWE**: CWE-693 — Protection Mechanism Failure (adjacent — availability, not confidentiality)
- **CVSS v3.1 base score**: 0.0 (Informational)
- **Spec-Kit task**: TASK-SEC-T-002

The method signature declares `: array`. If the `acrossai_mcp_user_accessible_servers` filter returns non-array (a buggy plugin returning `null`, `false`, `''`, or a scalar), PHP 8+ throws `TypeError` at the abstract's `return $data;`, killing the frontend page.

This is arguably **correct fail-loud** behavior (silent recovery would mask filter bugs) — but tasks.md does not document this as a deliberate design choice. A future maintainer reviewing the code might "helpfully" add a defensive coercion, breaking the fail-loud contract without realizing it.

**Recommendation**:

Add a one-line comment inside T006's algorithm-step-6 implementation:

```php
$data = apply_filters( 'acrossai_mcp_user_accessible_servers', $data, $user_id );
// Deliberately NOT coercing non-array return to []. A misbehaving filter should surface
// via PHP TypeError at return, not silently drop the entire payload. Caller
// (UserServersBlock::render_shortcode) applies defensive coercion at its HTML boundary,
// but the abstract's contract is "trust the filter output type".
return $data;
```

Also add a matching test to T014: `test_non_array_filter_return_throws_typeerror` documenting the expected fail-loud behavior.

**Effort**: ~5 minutes production code + ~10 minutes test. No breaking change; documents existing behavior. Optional.

### SEC-T-003 (INFO) — Test tasks enumerated after production tasks (not strict TDD)

- **Finding ID**: SEC-T-003
- **Location**: `specs/037-user-accessible-mcp-servers-shortcode/tasks.md` — T005–T013 (production) precede T014–T015 (tests)
- **OWASP category**: N/A (process concern, not a vulnerability)
- **CWE**: N/A
- **CVSS v3.1 base score**: 0.0 (Informational)
- **Spec-Kit task**: TASK-SEC-T-003

Tasks are sequenced production-first (T005–T013), tests-second (T014–T015). Not strict TDD. T014 IS marked `[P]` implying parallel execution is possible, but the task list reads as "implement, then test".

For a feature this small with security-critical logic concentrated in one method (`get_accessible_servers`), the risk is minor. But a strict red-team perspective would argue:

- The implementer sees the production shape before writing tests → unconsciously mirrors it → misses abuse-case coverage.
- SEC-001 caller-authority scenario is only enforced by a docblock (T007), not a runtime test. A companion-plugin author who omits the guard has no CI-detectable failure signal.

**Recommendation** (advisory only — not blocking):

- Reorder Setup phase: after T003 (PHPUnit suite registration), add T003a to write **failing test skeletons** for the 11 abstract + 12 concrete test cases (empty method bodies + `$this->markTestIncomplete()`). This proves the suite runs before any production code exists.
- Then implement T005–T013.
- Then flesh out T014–T015 (removing the `markTestIncomplete` markers).

OR just add explicit language to T014/T015 clarifying they can be developed in parallel with T005–T013 (currently only `[P]` marker communicates this).

**Effort**: ~15 minutes if reordering. Zero if just clarifying language.

---

## Confirmed Secure Patterns

The tasks.md correctly applies these patterns from prior reviews and the plan:

1. **SEC-001 (caller authority) explicitly tasked**: T007 copies the caller-authority subsection verbatim into the class docblock. Directly addresses plan-review LOW finding.
2. **SEC-004 comment in test**: T020 test task explicitly references SEC-004 in its comment (filter is mutation seam, not gate-bypass) — visible to future maintainers grep-searching for SEC-*.
3. **SEC-002 comment in test**: T021 test task explicitly references SEC-002 (filter return un-sanitized) — same visibility benefit.
4. **SEC-005 static-CSS invariant tasked**: T012 requires a static class constant `INLINE_STYLE` — structurally prevents dynamic interpolation into the `<style>` block.
5. **All 5 grep-gates tasked (T023)**: The exact commands from `spec.md` §DoD copied into T023, with expected exit codes. Zero-hit + one-hit conditions specified.
6. **Escape-at-boundary tasked (T010)**: T010 algorithm step 5 references `esc_html` + `esc_attr` + `esc_url` at each render seam.
7. **PHPCS + PHPStan L8 tasked (T024, T025)**: Constitutional DoD gates §VII enforced.
8. **F015 fail-open manually verified (T027, quickstart Test 7)**: The critical "wpb-access-control missing" degradation path has a manual verification task, not just a unit test.
9. **XSS-context assertion tasked (T015 test_escape_at_boundary)**: Explicit test that `<script>` in a server_name renders escaped.
10. **Singleton private-ctor reflection asserted (T015 test_singleton_private_ctor)**: Programmatically verifies S6 constitutional requirement — prevents accidental public-ctor regression from a maintenance PR.
11. **Memory hygiene tasked (T029–T031)**: DECISIONS.md D40 entry, INDEX.md rows for both memory + security-review, WORKLOG.md 2026-07-29 F038 entry. Preserves institutional knowledge per D22.
12. **B9 compliance in T014**: Explicitly requires PHP `#[DataProvider]` attribute (not `@dataProvider` docblock) — avoids the silent-drop bug documented in BUGS.md.

---

## Constitution alignment

| Principle | Task Alignment |
|-----------|----------------|
| §III Security First | ✅ Gate cascade tasked in T006, escape-at-boundary in T010, XSS test in T015, grep-gates in T023 |
| §V Extensibility | ✅ Filter round-trip tested in T014 + T020 + T021; base-class subclass tested in T017 |
| §VI Reusability | ✅ Grep-gates in T023 enforce delegation (no `apply_filters('acrossai_mcp_embed_transports')` inside F038, no direct meta reads) |
| §VII Definition of Done | ✅ Every DoD gate has a matching task (PHPCS T024, PHPStan T025, npm validate-packages T026, grep-gates T023) |

---

## Recommended Action Plan

1. **Apply SEC-T-001** — expand T007's scope to also copy SEC-002 + SEC-004 disclosures into the class docblocks. ~10 min edit to tasks.md. Recommend before implementation begins.
2. **Optional SEC-T-002** — add a one-line comment + one-line test case documenting the fail-loud contract on non-array filter return. Purely defensive documentation.
3. **Optional SEC-T-003** — clarify T014/T015 parallel-with-production intent (or reorder to write empty test skeletons before T005–T013). Advisory only.

None block Phase 3 (`/speckit-implement`). All can fold into implementation edits directly.

## Durable Memory Preservation

No new systemic patterns emerged from this task review. F038 exercises existing patterns already codified (SEC-001 caller-authority, SEC-002 filter-boundary trust, SEC-004 mutation-vs-gate seam, SEC-005 static-CSS invariant). No `/speckit.memory-md.capture` invocation required.

## Next Steps

- Apply SEC-T-001 to tasks.md (task edit — quick).
- Proceed to `/speckit-architecture-guard-governed-implement` or `/speckit-implement`.
- No `/speckit-security-review-followup` needed — no CRITICAL / HIGH findings.

---

## Memory Hub INDEX.md row

```text
| docs/security-reviews/2026-07-29-037-user-accessible-mcp-servers-shortcode-tasks.md | tasks | 2026-07-29 | LOW | C:0 H:0 M:0 L:1 I:2 | A01,A03 |
```
