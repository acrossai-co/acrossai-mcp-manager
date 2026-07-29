---
document_type: security-review
review_type: staged
assessment_date: 2026-07-29
codebase_analyzed: acrossai-mcp-manager (F038 staged diff — 27 files, +4278/-3 LOC)
total_files_analyzed: 27
total_findings: 1
overall_risk: INFORMATIONAL
critical_count: 0
high_count: 0
medium_count: 0
low_count: 0
informational_count: 1
owasp_categories: []
cwe_ids: [CWE-489]
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

# SECURITY REVIEW REPORT — STAGED CHANGES

## Executive Summary

The F038 staged diff (27 files, +4,278 / -3 LOC) is fully identical in security-relevant surface to the previously-reviewed branch diff (`docs/security-reviews/2026-07-29-037-…-branch.md`). The staged set is the entire feature — no partial staging, no cherry-picked changes. Since staging captured every file at rest without further modification, the branch review's conclusions apply verbatim.

**Overall risk: INFORMATIONAL.** Zero CRITICAL / HIGH / MODERATE / LOW findings. One INFO carry-over from the branch review (SEC-B-001 test-hook naming smell) restated here as SEC-STG-001 for the staged-review index row.

The three post-analyze doc refinements (I1 `tasks.md` T003 footnote, D1 `spec.md` FR-023 sub-clause split, A1 `plan.md` version anchor) all appear in the staged diff. None of them touched code — they are pure specification/documentation refinements — so the security posture is unchanged from the branch review.

## Staged Diff Reviewed

**Total**: 27 files staged, +4,278 insertions, -3 deletions.

| Category | Files | LOC | Security surface |
|----------|-------|-----|------------------|
| Production PHP | `public/Renderers/UserServers/AbstractUserServersRenderer.php` (+244), `public/Renderers/UserServers/UserServersBlock.php` (+365), `includes/Main.php` (+20) | +629 | **Primary** |
| Test PHP | `AbstractUserServersRendererTest.php` (+358), `UserServersBlockTest.php` (+368), `ThirdPartyExtensibilityTest.php` (+235) | +961 | Test-only (not shipped) |
| Config | `phpunit.xml.dist` (+19), `.github/workflows/phpunit.yml` (+4), `.specify/feature.json` (+4/-3) | +27/-3 | No runtime |
| Docs — planning | `docs/planings-tasks/038-…md` (+419), `specs/037-…/{spec,plan,tasks,research,data-model,quickstart,memory-synthesis}.md` (+1,110), `specs/037-…/contracts/*.md` (+445), `specs/037-…/checklists/requirements.md` (+36) | +2,010 | Planning artifacts only |
| Docs — memory | `docs/memory/{DECISIONS,INDEX,WORKLOG}.md` (+75) | +75 | Memory hub updates |
| Docs — security reviews | 3 prior review reports (`plan`, `tasks`, `branch`) (+577) | +577 | Review artifacts |
| Docs — user-facing | `README.txt` (+2) | +2 | Changelog |

## Vulnerability Findings

### SEC-STG-001 (INFORMATIONAL) — Test-hook static method matches B23 naming smell

- **Finding ID**: SEC-STG-001 *(carry-over from branch review SEC-B-001)*
- **Location**: `public/Renderers/UserServers/UserServersBlock.php:129`
- **OWASP category**: N/A (code hygiene)
- **CWE**: CWE-489 — Active Debug Code (adjacent)
- **CVSS v3.1 base score**: 0.0 (Informational)
- **Spec-Kit task**: TASK-SEC-STG-001

`UserServersBlock::reset_style_emitted_for_tests()` matches the B23 naming pattern. Actual risk profile in F038:
- ✅ Called only from tests (3 hits in `UserServersBlockTest.php`, 0 hits in `includes/` or `public/`).
- ✅ Method resets a boolean flag with zero security consequences (worst-case abuse: duplicate `<style>` block emitted).
- ⚠ Method name is a maintenance hazard per B23's smell heuristic.

**Recommendation (optional, post-merge)**: rename to `flush_style_cache()`, update 3 call sites in the test file, keep the docblock's "for test isolation only" phrasing.

**Not blocking merge.** Documented for future refactor tracking.

## Confirmed Secure Patterns

All 18 patterns confirmed in the branch review are preserved verbatim in the staged set. Highlights:

1. **Anonymous short-circuit** at `AbstractUserServersRenderer.php:112`.
2. **F015 gate applied per server row** at `AbstractUserServersRenderer.php:137`.
3. **F037 per-DTO gate at DTO granularity** at `AbstractUserServersRenderer.php:158`.
4. **All 5 delegation grep-gates green** (staged code re-verified: zero hits for `apply_filters.*acrossai_mcp_embed_transports` / `acrossai_mcp_client_classes` inside `public/Renderers/UserServers/`; zero direct `_embeds_*` meta reads; zero back-imports from `includes/`; exactly one `add_shortcode()` call).
5. **Escape-at-boundary** on every user-supplied string in `UserServersBlock.php`. XSS regression test asserts `&lt;script&gt;` output at `UserServersBlockTest.php:242`.
6. **Icon URL whitelist** — only `http://` / `https://` prefixes render as `<img>`; `esc_url` defense-in-depth.
7. **SEC-001 caller-authority disclosure** in `AbstractUserServersRenderer.php` class docblock (staged, lines 30–56).
8. **SEC-002 filter-boundary trust disclosure** in `UserServersBlock.php` class docblock (staged, lines 11–20).
9. **SEC-004 filter mutation-vs-gate disclosure** at the `apply_filters()` call site in `AbstractUserServersRenderer.php` (staged, lines 178–199).
10. **SEC-005 static-CSS invariant** in the `$style_emitted` docblock at `UserServersBlock.php:51-63`; `INLINE_STYLE` is `private const` at line 77.
11. **D36 `final class` + S6 private ctor** verified by reflection assertion in `test_singleton_private_ctor`.
12. **Non-array filter return defensive coercion** at `AbstractUserServersRenderer.php:214`.
13. **One-way `public/` → `includes/` boundary** preserved.
14. **A1 hook-wiring in Main.php only** — F038 wiring block at `Main.php:774–779`.
15. **Constitution §III/§V/§VI/§VII** — all applicable items satisfied.

### Docs-refinement re-verification

The three post-analyze remediations are documentation-only:

- **I1 (tasks.md T003 footnote)** — one-sentence addition explaining the F037-established brief-vs-spec-dir offset. Zero runtime surface. **No impact.**
- **D1 (spec.md FR-023 sub-clause split)** — FR-023 now presented as FR-023a/b/c with owner citations. FR-024..FR-028 renumbering intentionally avoided. Grep-gate implementation in T023 unchanged. **No impact.**
- **A1 (plan.md version anchor)** — `AbstractClientRenderer` precedent annotated as "shipped in 0.1.3 per README.txt, predates D36 which was ratified F035 / 0.1.8". Documentation clarity only. **No impact.**

None of the three changes altered code, contracts, tests, or the security posture.

## Action Plan

1. **SEC-STG-001 (INFO, optional)** — post-merge rename `reset_style_emitted_for_tests()` → `flush_style_cache()`.
2. **Merge-ready.** Zero blocking findings.
3. **Next steps in the governed-implement workflow**:
   - `/speckit-memory-md-capture-from-diff` — capture durable lessons from the staged diff (next skill invocation).
   - `/speckit-git-commit` → PR against `main` → merge → release.

## Durable Memory Preservation

**Not triggered.** No new systemic vulnerabilities or reusable security patterns emerged from the staged review. The primary durable lesson (`D40 / DEC-USER-SCOPED-ENUMERATION-COMPOSES-GATES`) was already captured in `docs/memory/DECISIONS.md` + `INDEX.md` + `WORKLOG.md` during the implementation phase (all three edits are inside the staged diff).

The next-step skill `/speckit-memory-md-capture-from-diff` will do a fuller sweep of the staged diff for any additional lessons — that's its purpose, and may capture patterns this security-focused review doesn't surface.

---

## Memory Hub INDEX.md row

```text
| docs/security-reviews/2026-07-29-037-user-accessible-mcp-servers-shortcode-staged.md | staged | 2026-07-29 | INFORMATIONAL | C:0 H:0 M:0 L:0 I:1 |  |
```
