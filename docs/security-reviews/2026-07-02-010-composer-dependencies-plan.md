---
document_type: security-review
review_type: plan
assessment_date: 2026-07-02
codebase_analyzed: acrossai-mcp-manager (Feature 010 — composer dependencies + PHP 8.1 + main-menu migration)
total_files_analyzed: 12
total_findings: 5
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 1
informational_count: 4
owasp_categories: [A06, A08]
cwe_ids: [CWE-1104, CWE-829, CWE-1035]
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

# Security Review — Plan Artifact (Feature 010 / composer-dependencies)

## Executive Summary

Feature 010 is a dependency-management + admin-menu-migration feature. It has zero code paths that consume user input, no REST routes, no DB queries, no transient reads, no forms, no consent surface changes. Constitution §III's normal attack surfaces (sanitize/escape/nonce/capability/hashed-creds/permission_callback) are structurally absent this feature.

The security-relevant work is **entirely supply-chain**: adding 3 new production dependencies (`wpboilerplate/wpb-access-control ^2.0.0`, `berlindb/core ^3.0.0`, `acrossai-co/main-menu ^0.0.8`) and bumping `automattic/jetpack-autoloader` two major versions (^3.0 → ^5.0). PHP baseline moves 7.4/8.0 → 8.1, which REDUCES attack surface (older runtime deprecations closed).

Two clarifications from the 2026-07-01 session define the regression net for this feature: **Q1 — no new PHPUnit files added** (SC-005 curl smoke + Phase 8's existing enqueue-guard tests carry the regression load), **Q2 — Feature 011 (BerlinDB refactor) is non-blocking** for `feature/issue-3 → main` cutover.

**Overall risk: LOW** — one LOW finding (transitive-dep audit for the 3 new packages, all from trusted authors but unverified transitively) and four INFO findings (CI infra, guard removal timeline, autoloader shape drift, composer.lock integrity). No CRITICAL, HIGH, or MEDIUM findings.

The plan is implementable as-written. All findings are release-prep or future-feature concerns; none block Feature 010's merge to `feature/issue-3`.

## Plan Artifacts Reviewed

| Path | Notes |
|---|---|
| `specs/010-composer-dependencies/spec.md` | 5 US + 28 FRs + 11 SCs + 5 CONSTRAINTS + §Clarifications (2 Q/A from 2026-07-01) |
| `specs/010-composer-dependencies/plan.md` | Implementation plan; TASK-1 research gates FR-018–FR-021; atomic PHP bump per CONSTRAINT 4 |
| `specs/010-composer-dependencies/memory-synthesis.md` | 11 durable-memory entries (A1/A6/A9/D5/D13/D14/DEV3/S9/§III guard/B11/B12) within retrieval budget |
| `specs/010-composer-dependencies/security-constraints.md` | Governed-plan orchestrator output (2026-07-01) — this document extends |
| `specs/010-composer-dependencies/architecture-violations.md` | Zero blocking arch violations; 3 P3 informational drifts |
| `docs/planings-tasks/010-composer-dependencies.md` | Source planning doc — 11 TASKs, 5 CONSTRAINTS |

Cross-referenced: `.specify/memory/constitution.md` §III + 2026-06-30 Consent-surface exception amendment; `docs/memory/INDEX.md` (77 lines, D14 added Feature 008); `docs/memory/PROJECT_CONTEXT.md` (S1–S9); current `composer.json` + `composer.lock`; `docs/security-reviews/2026-07-01-008-asset-build-pipeline-plan.md` (SEC-008-001 vendor/-diff CI gate as precedent).

## Vulnerability Findings

### SEC-010-001 — CI `composer install && git diff --exit-code vendor/` gate is deferred to release-prep infrastructure

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 2.0 (`AV:L/AC:H/PR:H/UI:N/S:U/C:N/I:L/A:N`)
- **OWASP**: A08:2025-Software and Data Integrity Failures
- **CWE**: CWE-1104 (Use of Unmaintained Third Party Components) — closest fit for "no automated integrity gate on committed dependency artifacts"
- **Location**: `specs/010-composer-dependencies/plan.md` §Recommended Actions; `security-constraints.md` Advisory 1
- **Spec-Kit task**: TASK-SEC-010-001

**Issue**

Feature 010 introduces a 2-major-version bump of `jetpack-autoloader` (the plugin's bootstrap-critical autoloader) and 3 new production packages. Without a CI job that runs `composer install && git diff --exit-code vendor/` on every PR, committed `vendor/` state could drift from what a clean install would emit. This is the same concern Phase 8 flagged for `build/` artifacts (SEC-008-001).

The realistic threat requires (a) attacker commit access, (b) reviewer inattention to `vendor/` diffs, (c) no automated re-install-and-diff gate. Low practical risk. Also, this is a general concern with committed `vendor/` — not Feature 010-specific — but Feature 010 makes it more consequential because the autoloader is now 2 major versions newer than the last shipping baseline.

**Recommendation**

Extend the release-infrastructure epic (which already owns Phase 8's SEC-008-001 `build/` gate) to add a composer-vendor equivalent:

```yaml
- run: composer install --no-dev --no-scripts --no-interaction
- run: git diff --exit-code vendor/
```

Non-blocking for Feature 010's merge — file as a release-prep task alongside SEC-008-001.

---

### SEC-010-002 — `class_exists()` guard removal timeline for `AccessControlManager` is not scheduled

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 1.0 (`AV:L/AC:H/PR:H/UI:N/S:U/C:N/I:N/A:N`)
- **OWASP**: A08:2025-Software and Data Integrity Failures
- **CWE**: CWE-1035 (OWASP Top Ten 2017 Category A9)
- **Location**: `spec.md` FR-025 + CONSTRAINT 1; `security-constraints.md` Advisory 2
- **Spec-Kit task**: TASK-SEC-010-002

**Issue**

CONSTRAINT 1 preserves the 4 existing `class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' )` guards "for at least 3 months soak time" after the package becomes a hard require. No specific removal target is scheduled. This creates two concerns:

1. **Guards may become permanent by default** — with no reminder mechanism, "3 months" becomes "indefinite". The guards add code noise; keeping them forever hurts readability. But removing prematurely reintroduces a fatal-error surface if `vendor/` fails to autoload.
2. **Guard removal is not part of any planned feature** — no `specs/012-...` slot reserved, no memory entry with a review-by date, no calendar reminder.

Non-blocking for Feature 010. Documentation-level hygiene concern.

**Recommendation**

Add a review-by date to CONSTRAINT 1 in the spec, e.g. "review guard removal on 2026-10-01 (3 months post-merge)". OR create a memory entry with a scheduled reassessment date so future audits find it. OR add a follow-up task to `docs/planings-tasks/` scheduled for Q4 2026.

Not required for Feature 010's merge.

---

### SEC-010-003 — Transitive dependency audit for the 3 new packages is deferred to implementation

- **Severity**: LOW
- **CVSS v3.1**: 3.1 (`AV:N/AC:H/PR:H/UI:N/S:U/C:N/I:L/A:L`)
- **OWASP**: A06:2025-Vulnerable and Outdated Components
- **CWE**: CWE-1104 (Use of Unmaintained Third Party Components)
- **Location**: `spec.md` FR-002–FR-005; `plan.md` §Phase 0 Output Plan R2; `security-constraints.md` §Supply-Chain Concerns
- **Spec-Kit task**: TASK-SEC-010-003

**Issue**

The plan adds 3 new production dependencies + bumps 1 (jetpack-autoloader) two major versions. The security review at plan-time verifies TOP-LEVEL trust (all three package authors — WPBoilerplate, BerlinDB / Sandhills Development, AcrossAI — have known reputations). But it does NOT audit TRANSITIVE dependencies:

- `wpboilerplate/wpb-access-control ^2.0.0` — does it pull in unfamiliar transitive deps?
- `berlindb/core ^3.0.0` — same question
- `acrossai-co/main-menu ^0.0.8` — TASK-1 already investigates the package API, but that's public-surface only; not transitive deps

A compromised transitive dep is a real supply-chain vector. The 2021 `ua-parser-js` attack, the 2022 `event-stream` incident, and countless smaller supply-chain compromises show that top-level trust is not sufficient.

**Impact**

- If any transitive dep is unmaintained, has known CVEs, or is authored by an untrusted entity, the runtime attack surface expands beyond what the plan review can assess.
- `class_exists()` guards on `AccessControlManager` don't help against a compromised transitive dep of `main-menu` or `berlindb/core` — those load automatically via jetpack-autoloader.

**Recommendation**

At TASK-2 execution (immediately after `composer update`), run:

```bash
composer show --tree
# For each new package, verify:
# - Direct requires (should be minimal, ideally none beyond well-known WP-ecosystem packages)
# - No unfamiliar author namespaces
# - Composer advisory: composer audit
```

If `composer audit` surfaces CVEs OR any transitive dep is from an unfamiliar author, escalate before proceeding. Feature 010 spec §Assumptions already implicitly covers this ("package APIs stable, no upstream breaking changes") but the transitive-audit step should be an explicit TASK-2 verification gate, not an assumption.

**Non-blocking for merge** as long as `composer audit` runs during implementation. If any CVE surfaces, remediate before opening PR.

---

### SEC-010-004 — `composer.lock` integrity is not verified by CI (parity with SEC-010-001)

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 1.4 (`AV:L/AC:H/PR:H/UI:N/S:U/C:N/I:L/A:N`)
- **OWASP**: A08:2025-Software and Data Integrity Failures
- **CWE**: CWE-1104
- **Location**: `spec.md` FR-009 (composer.lock regenerated via `composer update`)
- **Spec-Kit task**: TASK-SEC-010-004

**Issue**

The plan commits `composer.lock` alongside `composer.json` (matches Phases 5–8 convention). But without a CI job that verifies `composer.lock` matches the current state of `composer.json` (`composer install --no-scripts && composer validate --strict`), an attacker could commit a `composer.lock` that pins a compromised package version distinct from what `composer.json` would resolve fresh.

Realistic threat requires attacker commit access + reviewer inattention + no automated lock-vs-manifest check. Low practical risk. But mitigating cost is trivial (add `composer validate --strict` to CI).

**Recommendation**

Combined with SEC-010-001, add to the release-prep CI infrastructure:

```yaml
- run: composer validate --strict
- run: composer install --no-dev --no-scripts --no-interaction  
- run: git diff --exit-code composer.lock vendor/
```

Same follow-up epic as SEC-010-001. Non-blocking for Feature 010.

---

### SEC-010-005 — Jetpack autoloader `^3.0 → ^5.0` shape drift is verified only by activation smoke test

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 2.8 (`AV:L/AC:H/PR:N/UI:R/S:U/C:N/I:L/A:L`)
- **OWASP**: A08:2025-Software and Data Integrity Failures
- **CWE**: CWE-829 (Inclusion of Functionality from Untrusted Control Sphere)
- **Location**: `spec.md` §Assumptions "jetpack-autoloader 3.x → 5.x is safe for the plugin's bootstrap pattern"; `plan.md` §Technical Context Testing row
- **Spec-Kit task**: TASK-SEC-010-005

**Issue**

The plan bumps `automattic/jetpack-autoloader` from `^3.0` (v3.1.3 installed) to `^5.0`. This is a 2-major-version jump for a package that plays the bootstrap role — the plugin's `acrossai-mcp-manager.php` `require`s `vendor/autoload_packages.php`, which is emitted by this package.

Verification of the shape stability is a **single manual activation smoke test on WP 6.9 / PHP 8.1** (TASK-11 / SC-004). If the smoke test misses a subtle namespace-resolution regression (e.g. a class that autoloads slightly differently), a downstream feature could fail at runtime in a way that testing didn't catch.

**Impact bounds**:
- Only in-plugin classes affected (jetpack-autoloader scopes to its manifest — cross-plugin conflicts are outside its scope)
- Detected quickly on any live install (fatal error at bootstrap)
- Reversible (revert to `^3.0`)

**Recommendation**

TASK-11 smoke test should include a MORE THOROUGH bootstrap verification:

1. Fresh `composer install --no-dev` on a clean checkout
2. Activate on WP 6.9 / PHP 8.1
3. Hit 5 different code paths that exercise different namespaces:
   - Admin menu (Menu.php) — verify autoload of `\AcrossAI_Co\MainMenu\...`
   - MCP Controller boot (`\AcrossAI_MCP_Manager\Includes\MCP\Controller`)
   - CLI Auth (`\AcrossAI_MCP_Manager\Includes\REST\CliController`)
   - OAuth (`\AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors`)
   - FrontendAuth (`\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth`)
4. Confirm each executes without `Class not found` errors

If jetpack-autoloader ^5.0 has drifted, at least one of the 5 paths will fail — narrower detection surface than a single smoke test.

**Non-blocking for merge** — TASK-11 quickstart walkthrough can be extended with these 5 checks.

## Confirmed Secure Patterns

- ✅ **§III surfaces structurally absent** — Feature 010 introduces no forms, REST routes, DB queries, transient reads, consent surfaces, or user-input paths. Constitution §III's normal defenses (sanitize/escape/nonce/capability/hashed-creds/permission_callback) don't apply because there's nothing to defend.
- ✅ **`class_exists()` guards preserved** — 4 guards for `\WPBoilerplate\AccessControl\AccessControlManager` remain in place (FR-025 / CONSTRAINT 1) as defense-in-depth against `vendor/` autoload failures.
- ✅ **Admin URL slug preservation** — CONSTRAINT 5 + FR-019 mandate `?page=acrossai_mcp_manager` unchanged. External Application Password callbacks + integration tests don't break.
- ✅ **Atomic PHP version bump** — CONSTRAINT 4 + FR-014 mandate composer.json + plugin header + README.txt + constitution + copilot-instructions all move in one commit. Eliminates the mismatch window where composer permits an install the plugin header rejects.
- ✅ **`AdminPageSlugs::plugin_screen_ids()` additive updates only** — FR-022 mandates whitelist extensions only, never removals. Phase 8's admin asset enqueue guard invariant (`admin/Main.php`) preserved.
- ✅ **Feature-007 §III Consent-surface exception untouched** — Feature 010 does not touch consent flows.
- ✅ **Feature-009 `\WP\MCP\Plugin` guard pattern preserved** — Feature 010 does not touch `MCP/Controller.php`.
- ✅ **Custom Query classes untouched** — FR-026 / CONSTRAINT 2 defer the real-BerlinDB refactor to Feature 011. No semantic change to DB access paths this feature.
- ✅ **No PHP 8.1 language features introduced** — CONSTRAINT 3 forbids `readonly` / `enum` / `never` / first-class callable syntax. Language-level modernization is deferred; runtime baseline moves without code-level dependencies.
- ✅ **Test regression net documented** — Q1 clarification confirms Phase 8's admin asset enqueue tests + SC-005 curl smoke are the regression net for Menu.php migration. No test-coverage gap.
- ✅ **Feature 011 non-blocking** — Q2 clarification confirms `feature/issue-3 → main` cutover can proceed with custom Query classes in place. No governance dependency created.

## Delta vs. Governed-Plan `security-constraints.md`

The governed-plan orchestrator output at `specs/010-composer-dependencies/security-constraints.md` is accurate; this standalone review confirms its verdict. This document adds:

1. **SEC-010-003** (LOW — transitive dependency audit) — not surfaced in the orchestrator output because the orchestrator focused on top-level trust boundaries. Included here because the 3 new packages introduce unknown transitive surface.
2. **SEC-010-004** (INFO — composer.lock integrity) — parallel to SEC-010-001 but distinct; the orchestrator conflated them.
3. **SEC-010-005** (INFO — Jetpack autoloader shape drift verification depth) — orchestrator noted the risk in §Assumptions but did not prescribe a mitigating test procedure.

The two orchestrator advisories (SEC-010-001 CI vendor/-diff gate + SEC-010-002 guard removal timeline) are reproduced here in formal finding form with CVSS/CWE mappings for INDEX.md routing.

**No new finding weakens the plan's verdict.** All five are INFORMATIONAL or LOW-severity concerns; none block Feature 010's merge to `feature/issue-3`.

---

## Action Plan & Next Steps

1. **Durable Memory Preservation (Mandatory Check)** — **No captures warranted at plan-time.** Evaluated:
   - **Supply-chain audit pattern** (SEC-010-003) — is `composer audit` + `composer show --tree` for new packages a durable lesson? It's WP-ecosystem hygiene, generalizable to any future feature adding composer deps. But only ~1–2 features per year add new production packages; the frequency doesn't justify a capture. Reassess if patterns emerge.
   - **Atomic multi-file version bump pattern** (CONSTRAINT 4) — could crystallize into a durable rule for future PHP version bumps. Currently one instance (Feature 010); need ≥2 before capture. Reassess post-implementation.
   - The other findings (SEC-010-001/-002/-004/-005) are release-prep or future-feature concerns, not memory patterns.
   
   Formal `/speckit-memory-md-capture` invocation deferred until post-implementation. Default at plan-time: NONE.

2. **Remediation Planning** — no CRITICAL or HIGH findings. All 5 findings are release-prep or task-level concerns to fold into `/speckit-tasks` output:
   - SEC-010-001 + SEC-010-004 → same release-prep infrastructure epic that owns Phase 8's SEC-008-001 `build/` gate. Not a Feature 010 task.
   - SEC-010-002 → add a review-by date to spec CONSTRAINT 1 (one-line edit). Optional; not blocking.
   - SEC-010-003 → add to TASK-2 execution the verification steps (`composer audit`, `composer show --tree` for new packages). Fold into tasks.md.
   - SEC-010-005 → extend TASK-11 quickstart walkthrough to hit 5 code paths verifying autoloader shape stability. Fold into tasks.md.

3. **Optional follow-up**: Run `/speckit-security-review-followup` to convert SEC-010-003/-005 into Spec-Kit remediation tasks. Not required — these can be folded directly into `/speckit-tasks` output when generating tasks.md.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-02-010-composer-dependencies-plan.md | plan | 2026-07-02 | LOW | C:0 H:0 M:0 L:1 | A06,A08 |
```
