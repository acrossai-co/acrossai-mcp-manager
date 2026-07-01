---
document_type: security-review
review_type: plan
assessment_date: 2026-07-01
codebase_analyzed: acrossai-mcp-manager (Phase 8 — 008-asset-build-pipeline)
total_files_analyzed: 10
total_findings: 4
overall_risk: INFORMATIONAL
critical_count: 0
high_count: 0
medium_count: 0
low_count: 0
informational_count: 4
owasp_categories: [A05, A08]
cwe_ids: [CWE-829, CWE-1104, CWE-1004]
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

# Security Review — Plan Artifact (Phase 8 / Feature 008-asset-build-pipeline)

## Executive Summary

Phase 8 finalizes the v0.0.4 → WPBoilerplate asset migration. Scope: add the missing `css/frontend-oauth` webpack entry, port `src/scss/frontend-oauth.scss` from v0.0.4, verify content parity for pre-existing `src/scss/{backend,frontend}.scss` + `src/js/backend.js`, and close the `public/Main.php` global asset-leak defect by narrowing enqueue to the OAuth consent surface only. Zero new business logic. Zero new forms, REST routes, DB tables, transients, or user-input handling paths.

**Constitution §III surfaces are structurally null this phase**: nothing to sanitize, escape, nonce, permission-check, or hash. The one §III item that applies — "no hardcoded version strings" — is enforced via spec SC-008 and is fundamentally a hygiene concern, not a vulnerability.

The plan is **memory-informed** to a load-bearing degree: the FR-020 reconciliation decision (narrow `public/Main.php` to OAuth-only rather than delegate to Phase 7) is forced by two independent memory patterns — **B12** (mechanical: `wp_enqueue_scripts` doesn't fire when `template_redirect` exits before `wp_head()`) and **DEV3** (architectural: avoid parallel bidirectional Phase 7 ↔ Phase 8 coupling). Without those captures from prior phases, the plan would likely have shipped dead code.

**Overall risk: INFORMATIONAL.** No CRITICAL/HIGH/MEDIUM/LOW findings. Four INFO-severity findings, all of which are hygiene / operational-defense-in-depth concerns rather than exploitable weaknesses. Phase 8 REDUCES attack surface (closes the global asset leak); it does not expand it.

## Plan Artifacts Reviewed

| Path | Notes |
|---|---|
| `specs/008-asset-build-pipeline/spec.md` | 5 user stories + 23 FRs + 10 SCs + Security Checklist + Assumptions |
| `specs/008-asset-build-pipeline/plan.md` | Implementation plan; FR-020 memory-informed to option (b); zero deviations |
| `specs/008-asset-build-pipeline/memory-synthesis.md` | 8 durable-memory entries (A1/A6/A9/DEV3/S9/B11/B12/D5) within retrieval budget |
| `specs/008-asset-build-pipeline/security-constraints.md` | Governed-plan orchestrator output — this document extends with a deeper standalone pass |
| `specs/008-asset-build-pipeline/architecture-violations.md` | Zero blocking violations |

Cross-referenced: `.specify/memory/constitution.md` §III (Security First) + the 2026-06-30 Consent-surface exception amendment; `docs/memory/PROJECT_CONTEXT.md` (S1–S9); `docs/memory/BUGS.md` §B11 + §B12; `webpack.config.js`; `admin/Main.php` (Phase 2 baseline); `public/Main.php` (current unguarded state); `public/Partials/FrontendAuth.php` (Phase 7 CLI-consent enqueue owner).

## Vulnerability Findings

### SEC-008-001 — Committed build artifacts as an attacker-controlled surface (defense-in-depth informational)

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 2.0 (`AV:L/AC:H/PR:H/UI:N/S:U/C:N/I:L/A:N`)
- **OWASP**: A08:2025-Software and Data Integrity Failures
- **CWE**: CWE-1104 (Use of Unmaintained Third Party Components) — closest match; the underlying issue is trust of committed build outputs
- **Location**: `plan.md` §Project Structure (`build/css/frontend-oauth.*` marked as `NEW artifacts... commit to repo per existing convention`)
- **Spec-Kit task**: TASK-SEC-008-001

**Issue**

The project's convention (verified from Phases 5–7) is to commit `build/*` artifacts alongside their `src/*` sources. This is standard for WP plugins distributed via `wordpress.org` (where deployers do not necessarily run `npm run build`). It creates a small integrity concern: an attacker with commit access could modify `build/css/*.css` and `build/js/*.js` without touching `src/*` — the discrepancy would only be caught by a diff-of-hashes review, not by a source-code review.

Because the current CI/review process trusts commit history (there is no CI job that re-runs `npm run build` and diffs against committed artifacts), a malicious build-artifact commit could theoretically ship past review. The realistic threat requires: (a) attacker commit access, (b) reviewer inattention to `build/*` diffs, (c) no automated re-build-and-diff gate.

**Risk assessment**

Very low practical risk. This is a general concern with committed build artifacts, not Phase-8-specific. Phase 8 inherits the convention rather than establishing it.

**Recommendation**

At release-prep time (before the eventual `feature/issue-3` → `main` merge), add a CI job:

```bash
npm ci
npm run build
git diff --exit-code build/
```

Exit-code non-zero means the committed `build/` tree drifted from what a clean rebuild would emit. This transforms "build/ commits require careful review" into "build/ commits are automatically verified". Non-blocking for Phase 8's own merge — file this as a future release-infrastructure task or fold into the existing `npm run validate-packages` gate.

---

### SEC-008-002 — Handle-name clarity: `acrossai-mcp-manager` used for OAuth-only enqueue post-Phase-8 (naming hygiene)

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 1.0 (`AV:L/AC:H/PR:H/UI:N/S:U/C:N/I:N/A:N`)
- **OWASP**: A05:2025-Security Misconfiguration
- **CWE**: CWE-1004 (Sensitive Cookie Without 'HttpOnly' Flag) — no perfect match; the issue is "misleading name suggesting broader scope than actual"
- **Location**: `plan.md` implicit (preserves `$this->plugin_name` as the handle in `public/Main.php`); confirms `security-constraints.md` Advisory 2
- **Spec-Kit task**: TASK-SEC-008-002

**Issue**

`public/Main.php` currently uses `$this->plugin_name` (`acrossai-mcp-manager`) as the handle for its unguarded frontend enqueue. After Phase 8 narrows this to OAuth-consent-scope only, the handle name will imply "this is the plugin's canonical frontend asset" — but its actual scope will be "OAuth consent surface only".

A future consumer (theme, another plugin, an integration test) might reasonably read `acrossai-mcp-manager` handle presence as an indicator of "the plugin's frontend is loading" — semantically incorrect post-Phase-8.

**Recommendation**

In `contracts/public-main-enqueue.md` (Phase 1 output), rename the handle to `acrossai-mcp-frontend-oauth` to align with Phase 7's `acrossai-mcp-frontend` handle naming. This is a task-level decision at `/speckit-tasks`. The rename is safe (this handle was never publicly documented as an API), zero-cost, and eliminates future confusion.

---

### SEC-008-003 — RTL data attach for the new OAuth handle must be verified at DoD

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 1.2 (`AV:N/AC:H/PR:N/UI:R/S:U/C:N/I:N/A:L`)
- **OWASP**: A05:2025-Security Misconfiguration
- **CWE**: CWE-1004 (closest "missing protective directive" mapping)
- **Location**: `spec.md` FR-021 + `security-constraints.md` Advisory 1
- **Spec-Kit task**: TASK-SEC-008-003

**Issue**

`@wordpress/scripts` emits `build/css/frontend-oauth-rtl.css` automatically via rtlcss, but WordPress will not auto-substitute it unless `wp_style_add_data($handle, 'rtl', 'replace')` is called immediately after `wp_enqueue_style`. If that call is missed on the new OAuth handle, RTL locales (Arabic, Hebrew, Persian, etc.) will render mis-aligned CSS on the OAuth consent surface.

Not strictly a security finding — it's an accessibility / UX regression risk on the OAuth consent flow specifically. Filed as INFO because the OAuth consent surface renders authorization decisions where UI misalignment could plausibly aid a UI redress attack (mislabeled buttons, hidden state). Very speculative; documented for completeness.

**Recommendation**

`/speckit-tasks` output for Phase 8 MUST include a task assertion in the OAuth-guard implementation: after `wp_enqueue_style('<handle>', …)` add `wp_style_add_data('<handle>', 'rtl', 'replace')`. If `MainEnqueueTest.php` is written (plan §Test surface Option A), include an assertion for `wp_styles()->registered['<handle>']->extra['rtl'] === 'replace'` — same pattern as Phase 7's `EnqueueAssetsTest`.

---

### SEC-008-004 — Content-parity verification method should be rigorously specified

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 1.8 (`AV:L/AC:H/PR:H/UI:R/S:U/C:N/I:L/A:N`)
- **OWASP**: A08:2025-Software and Data Integrity Failures
- **CWE**: CWE-829 (Inclusion of Functionality from Untrusted Control Sphere)
- **Location**: `spec.md` §User Story 4 "Content Parity" + FR-003 / FR-004 / FR-005 (uses phrases "manual diff" and "every non-comment selector present in v0.0.4 is also present")
- **Spec-Kit task**: TASK-SEC-008-004

**Issue**

The spec's content-parity user story leans on "manual diff" as the verification method. A naive line-count or word-diff comparison would MISS several classes of drift that matter for security/hygiene:

1. **Selector reordering that changes cascade** — CSS specificity + source order can invert which rule wins. A parity check that only asserts "all selectors present" would miss cascade-inversion regressions.
2. **Media-query nesting changes** — a v0.0.4 rule inside `@media (max-width: …)` moved to top-level scope would still "be present" but its effective breakpoint behavior differs.
3. **JS event-binding order** — `assets/admin.js` might rely on jQuery `on('click', …)` handlers registered in a specific order; a `src/js/backend.js` that has all handlers but in different registration order could produce different event-propagation behavior.

None of these are direct security vulnerabilities, but "we migrated content and thought we preserved everything" false-positive is exactly the failure mode that ships subtle regressions. The migration's zero-regression promise (per project mission statement) is at risk without a rigorous method.

**Recommendation**

Task-level decision at `/speckit-tasks`. Two options:

- **(a) Automated selector-set + property-set diff**: use `postcss` (already a `@wordpress/scripts` transitive dep) to parse both v0.0.4 CSS and the migrated SCSS output, extract `(selector, property, value)` tuples, and diff the sets. Fail if the migrated set is not a superset of the v0.0.4 set. Zero infrastructure cost.
- **(b) Human review with a documented checklist**: reviewer manually walks each v0.0.4 selector, confirms migration counterpart, verifies media-query nesting is preserved. Requires ~15–30 minutes per file for a plugin of this size.

Recommend (a) — it's a one-time script that pays for itself and doesn't rely on reviewer diligence.

## Confirmed Secure Patterns

- ✅ **Guard chain closes the global asset leak** — FR-016 / FR-018 narrow `public/Main.php` to OAuth-consent scope only; measurable frontend surface reduction on every non-plugin page load.
- ✅ **B11 defensive triple-check on `.asset.php` reads** — plan §Constitution Check + §Technical Context both cite this; runtime defense against corrupted / partial-write manifest files.
- ✅ **B12 mechanical constraint applied** — FR-020 option (b) rejects the delegate-to-Phase-7 approach because `wp_enqueue_scripts` would never fire on Phase 7's `template_redirect`-exit path. Without B12 as a captured pattern, this plan would have shipped dead code.
- ✅ **DEV3-adjacent coupling avoidance** — plan explicitly forbids `public/Main.php` importing `FrontendAuth`, preventing a parallel to the Phase 6 ↔ Phase 7 bidirectional coupling T044 is unwinding.
- ✅ **S9-adjacent invariant preserved** — Phase 8 consumes Phase 7's authoritative `get_query_var('acrossai_mcp_auth')` predicate for CLI-surface awareness rather than URL inspection.
- ✅ **Zero new attack surface** — no forms, no REST, no DB, no transient, no user-input handling; §III's normal surfaces are structurally null.
- ✅ **Zero hardcoded version strings** — SC-008 enforces this via CI grep; matches Phase 2's already-satisfied admin baseline.
- ✅ **`file_exists()` guard around `.asset.php` `require`** — FR-014 / FR-019 mandate; prevents PHP warnings on missing manifest.
- ✅ **RTL support baseline** — FR-009 (webpack emits `-rtl.css`) + FR-021 (`wp_style_add_data`) — assuming SEC-008-003 verification holds at DoD.
- ✅ **Phase 7 boundary intact** — plan MUST NOT touch `FrontendAuth::enqueue_assets()`; Phase 7's contract is stable and cited in `data-model.md` as an explicit dependency.
- ✅ **`admin/Main.php` verified-only** — plan §Module Placement explicitly marks admin path as "Verify only"; no accidental regression risk.

## Delta vs. governed-plan `security-constraints.md`

The governed-plan orchestrator output at `specs/008-asset-build-pipeline/security-constraints.md` is accurate and this standalone review confirms its verdict. This document adds:

1. **SEC-008-001** (committed build-artifact integrity) — not surfaced in the orchestrator output because it's a plugin-wide operational concern rather than a Phase-8-specific finding. Included here for completeness.
2. **SEC-008-004** (content-parity method rigor) — orchestrator noted "manual diff" as the spec's stated method but did not flag the failure modes. Included here as an INFO because the migration's zero-regression promise depends on it.
3. **SEC-008-002 and SEC-008-003** — reproduce the orchestrator's Advisory 1 and Advisory 2 in formal finding form with CVSS/CWE mappings for INDEX.md routing.

**No new findings weaken the plan's verdict.** All four are INFO-severity documentation / task-level concerns.

---

## Action Plan & Next Steps

1. **Durable Memory Preservation (Mandatory Check)** — **NO CAPTURES WARRANTED.** Evaluated:
   - **B12** (Phase-7-captured) is applied directly by this plan; reusing an existing pattern is not re-capturing.
   - **B11** (Phase-6-captured) is generalized from transients to `require`-returned arrays; the existing entry already says "structured payload" and the generalization is inference, not new lesson.
   - **DEV3** (this-turn's-captured) is referenced but not modified; the underlying coupling is being unwound by T044.
   - No NEW patterns emerge from Phase 8 — it is a clean application of Phases 5–7 captures.

2. **Remediation Planning** — Not required. Zero CRITICAL / HIGH findings. All four INFO findings are task-level concerns to fold into `/speckit-tasks` output.

3. **Recommended follow-ups (NOT blocking merge)**:
   - **SEC-008-001**: file a release-infrastructure task to add `npm ci && npm run build && git diff --exit-code build/` as a CI gate for the eventual `feature/issue-3` → `main` merge. Track under `.github/workflows/` epic.
   - **SEC-008-004**: include an automated content-parity script in Phase 8's task list (recommend `postcss`-based selector-set diff).

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-01-008-asset-build-pipeline-plan.md | plan | 2026-07-01 | INFORMATIONAL | C:0 H:0 M:0 L:0 | A05,A08 |
```
