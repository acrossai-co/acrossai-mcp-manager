---
document_type: security-review
review_type: plan
assessment_date: 2026-07-03
codebase_analyzed: acrossai-mcp-manager (Feature 013 planning artifacts)
total_files_analyzed: 5
total_findings: 8
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 3
informational_count: 5
owasp_categories: [A01, A03, A05, A09]
cwe_ids: [CWE-20, CWE-352, CWE-863, CWE-1104, CWE-778]
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

# Security Review — Feature 013 Plan

## Executive Summary

Feature 013 refactors the per-server-edit page into a per-tab class hierarchy under `admin/Partials/ServerTabs/` and introduces a **new public Renderer layer** under `public/Renderers/` that third-party plugins (BuddyBoss, WooCommerce, other AcrossAI-family plugins) consume via 3 shortcodes + 2 filters + 1 action hook + 1 REST endpoint. The Renderer layer's central security posture is **defense-in-depth against admin-impersonation** in embedded contexts — the WordPress Application Password "Generate" button and its backing REST endpoint (`/wp-json/acrossai-mcp-manager/v1/generate-app-password`) MUST only ever mint credentials for `get_current_user_id()`, never for a `user_id` supplied via context. F013 also introduces **cross-context nonce replay defense**: nonces bind both `$server_id` AND the caller's context slug so a nonce minted for the admin edit page cannot be replayed against a BuddyBoss profile POST.

The plan's security posture is **overall LOW risk**. Zero HIGH/CRITICAL findings. 3 LOW findings and 5 INFO findings are all mitigation-locked at planning time — either mechanically caught by PHPUnit tests planned for TASK-4 or handled by explicit code-review checkpoints. The F013 planning documents (`spec.md`, `plan.md`, `memory-synthesis.md`) demonstrate thorough security awareness through 4 dedicated Functional Requirements (FR-021..024), 8 concrete constraints in the CONSTRAINTS block, and an inline governance run (`specs/013-per-server-tabs-refactor/security-constraints.md`) that this review consolidates and formalizes.

**Recommended disposition**: Proceed to `/speckit-tasks`. The 3 LOW findings fold into TASK-4 code-review DoD; the 5 INFO findings surface for reviewer awareness at TASK-4 + TASK-9.

## Plan Artifacts Reviewed

| Artifact | Path | Purpose in review |
|:---|:---|:---|
| Feature spec | `specs/013-per-server-tabs-refactor/spec.md` (274 lines, 5 Clarifications) | Source of truth for FR-021..024 security requirements + trust-boundary intent |
| Implementation plan | `specs/013-per-server-tabs-refactor/plan.md` (168 lines) | Technical context + Constitution check + 9-task preview + risk register |
| Memory synthesis | `specs/013-per-server-tabs-refactor/memory-synthesis.md` (887 words / 900 budget) | Selected 21 memory entries incl. 3 relevant Security Constraints (S1, S2, S6) + 3 Bug Patterns (B9, B15, B16) + LOAD-BEARING DECs from F011/F012 |
| Inline security constraints | `specs/013-per-server-tabs-refactor/security-constraints.md` (97 lines) | Governance-run trust boundaries + preliminary findings — this formal review consolidates + expands them |
| Planning doc | `docs/planings-tasks/013-per-server-tabs-refactor.md` (826 lines) | Task-level DoDs + grep gates + CONSTRAINTS block |

## Vulnerability Findings

### [LOW] SEC-013-001 — Cross-context nonce replay defense could regress if a future feature relaxes the nonce action binding

**Location:** `specs/013-per-server-tabs-refactor/spec.md` FR-022 + FR-023; planned in `public/Renderers/AbstractClientRenderer.php::resolve_context()` + `includes/REST/ClientRendererController.php::permission_callback()`
**OWASP Category:** A03:2025-Injection (cross-context CSRF replay category)
**CWE:** CWE-352: Cross-Site Request Forgery
**CVSS Score:** 3.1 (LOW)
**Description:** F013 introduces a novel security pattern: nonces bind both `$server_id` AND the caller's context slug (`'acrossai_mcp_render_' . $client_slug . '_' . $server_id . '_' . $context_slug`). A nonce minted for `context='admin'` MUST NOT validate against a POST from `context='buddyboss-profile'`. This is defense against an admin who captures a valid nonce from their own admin session and replays it against a BuddyBoss profile POST (potentially bypassing per-context capability restrictions). The pattern works, but is novel to this plugin — a future feature that "simplifies" the nonce action by dropping the context slug binding would silently regress the defense. The plan mitigates this at TASK-4 by requiring a PublicApiTest case asserting 403 on cross-context replay, so any regression is CI-caught.
**Remediation:** No code change required at planning time. The PHPUnit assertion at TASK-4 is the load-bearing defense. Recommend reviewer at TASK-4 explicitly verifies the test exercises BOTH the mint side (context='admin') and the reject side (context='buddyboss-profile') to prove the binding is bidirectional.
**Spec-Kit Task:** TASK-4

### [LOW] SEC-013-002 — Application Password minting could be misconfigured to accept a `user_id` other than the current user

**Location:** `specs/013-per-server-tabs-refactor/spec.md` FR-023 + FR-024; planned in `includes/REST/ClientRendererController.php::permission_callback()`
**OWASP Category:** A01:2025-Broken Access Control
**CWE:** CWE-863: Incorrect Authorization
**CVSS Score:** 3.7 (LOW)
**Description:** The `/generate-app-password` REST endpoint accepts `server_id`, `client_slug`, `context`, and `user_id` in the POST body. FR-023 mandates `permission_callback` returns 403 if `absint($body['user_id']) !== get_current_user_id()`. This is a security invariant — Application Passwords grant persistent WordPress access; allowing an admin to mint one for a different user would be authentication bypass by an insider with `edit_users` capability. The invariant is enforced at TWO layers: the UI Renderer disables the Generate button when `$context['user_id']` differs from `get_current_user_id()` (FR-024, defense-in-depth), and the REST endpoint's `permission_callback` returns 403 on mismatch (FR-023, authoritative check). A regression at either layer alone would still be caught by the other. But a coordinated regression (or a maintenance bug that misuses `wp_get_current_user()` vs `get_current_user_id()` inside `permission_callback`) could open the gap.
**Remediation:** No code change required at planning time. TASK-4 mandates a PublicApiTest case asserting the endpoint returns 403 when `user_id` doesn't match — CI catches the regression. Recommend TASK-4 code review verifies: (a) `get_current_user_id()` is used (not `wp_get_current_user()->ID` which has surprising fallback behavior on `wp_set_current_user(0)`); (b) `absint()` cast is applied before comparison; (c) the assertion is `===`, not `==` (to catch `0 == false` edge cases).
**Spec-Kit Task:** TASK-4

### [LOW] SEC-013-003 — Third-party filter callback returning non-array to `acrossai_mcp_client_block_context` would fatal at `wp_parse_args()`

**Location:** `specs/013-per-server-tabs-refactor/spec.md` FR-016 + Clarifications Q3; planned in `public/Renderers/AbstractClientRenderer.php::resolve_context()`
**OWASP Category:** A05:2025-Security Misconfiguration
**CWE:** CWE-20: Improper Input Validation
**CVSS Score:** 3.7 (LOW)
**Description:** The `acrossai_mcp_client_block_context` filter (per FR-016) is the sanctioned extension point for third-party plugins to customize context defaults. A third-party filter callback that returns a non-array (e.g., `null`, `false`, `'string'`) would cause `wp_parse_args()` inside `resolve_context()` to error. This is not a direct security vulnerability (WordPress core fatal, not privilege escalation), but a broken third-party plugin could DoS the admin edit page or a BuddyBoss profile page for admin users viewing that page. Third-party integrator experience: cryptic PHP fatal instead of a graceful notice.
**Remediation:** In `AbstractClientRenderer::resolve_context()`, cast the filter's return value to `(array)` before `wp_parse_args()`:
```php
$context = (array) apply_filters( 'acrossai_mcp_client_block_context', $context, $this->slug(), $server_id );
$context = wp_parse_args( $context, $defaults );
```
Add a PHPUnit case in `PublicApiTest` that: (a) registers a filter returning `null` / `false` / `'string'`; (b) calls `Renderer::render()`; (c) asserts no fatal + defaults are applied. Fold this into TASK-4 DoD.
**Spec-Kit Task:** TASK-4

### [INFO] SEC-013-004 — Public API is `@experimental` until 1.0.0 tag

**Location:** `specs/013-per-server-tabs-refactor/spec.md` FR-016a + Clarifications Q3; documented in `DEC-CLIENT-RENDERER-PUBLIC-API` (to be captured at TASK-9)
**OWASP Category:** A05:2025-Security Misconfiguration (advisory)
**CWE:** CWE-1104: Use of Unmaintained Third Party Components (adjacent — the "unmaintained" here is the pre-1.0 stability commitment)
**CVSS Score:** 0.0 (informational)
**Description:** F013 documents its public Renderer API (2 filters, 1 action hook, 3 shortcodes, 1 REST route, static `render()` methods) as `@since 0.0.6 @experimental May change without notice before 1.0.0`. Third-party integrators are informed the API may change during the 0.x line. This is intentional — the plugin is at `Stable tag: 0.0.1` and needs iteration room before committing to semver deprecation cycles. This is not a vulnerability; it's an honest stability disclosure. Third parties who ignore the notice and depend on unversioned behavior may hit breakage on plugin upgrade.
**Remediation:** No code change. Confirm at TASK-9 that DEC-CLIENT-RENDERER-PUBLIC-API + README changelog + inline docblocks all carry the experimental notice. Reviewer to spot-check 3 random shortcode/hook/filter docblocks post-TASK-4.
**Spec-Kit Task:** TASK-9

### [INFO] SEC-013-005 — F012 settings-toggle bypass via shortcode is defended by placing the gate inside the Renderer

**Location:** `specs/013-per-server-tabs-refactor/spec.md` FR-017 + FR-018 + FR-020; planned in `NpmClientBlock::render_body()` + `ClaudeConnectorBlock::render_body()`
**OWASP Category:** A05:2025-Security Misconfiguration (defense-in-depth advisory)
**CWE:** CWE-778: Insufficient Logging (adjacent — the gate is the "log" that the operator disabled the feature)
**CVSS Score:** 0.0 (informational)
**Description:** F012 introduced 2 admin-configurable toggles at `?page=acrossai-settings&tab=mcp` — `acrossai_mcp_npm_login_enabled` and `acrossai_mcp_claude_connectors_enabled`. F013 makes these toggles enforceable across ALL rendering contexts (admin tab, shortcode, `do_action` hook, direct static call) by placing the gate check INSIDE the Renderer's `render_body()`, not the admin tab wrapper. A third-party plugin embedding `[acrossai_mcp_npm_block]` on a public page cannot bypass the admin's decision to disable npm. This is defense-in-depth against admin-configured feature switches being circumvented by embed contexts. FR-020 mandates this via grep gate at TASK-8.
**Remediation:** No code change. Confirm at TASK-8 that the grep gate returns hits ONLY in `NpmClientBlock.php` and `ClaudeConnectorBlock.php` — not in `MCPClientsBlock.php`, `AbstractClientRenderer.php`, or any admin tab class body.
**Spec-Kit Task:** TASK-8

### [INFO] SEC-013-006 — Shortcode-rendered blocks on cached third-party pages could freeze the F012 toggle state

**Location:** `specs/013-per-server-tabs-refactor/spec.md` FR-014 (shortcode registration); planned effect at `AbstractClientRenderer::render_feature_disabled_notice()` output
**OWASP Category:** A05:2025-Security Misconfiguration (operator-visible drift advisory)
**CWE:** CWE-778: Insufficient Logging (adjacent — the cached page shows stale enabled state)
**CVSS Score:** 0.0 (informational)
**Description:** F013 registers 3 shortcodes on `init` that BuddyBoss/WooCommerce members might use in pages/widgets. Third-party page-cache plugins (WP Rocket, W3 Total Cache) that cache the rendered page could freeze the F012 toggle state — an admin who toggles npm off in Settings would see cached "enabled" state on the frontend until the cache clears. This is not a security vulnerability (Application Password minting is still REST-endpoint-guarded), but a possible operator confusion: "why is npm still showing enabled after I turned it off?"
**Remediation:** No code change required in F013. Optional TASK-4 code-review note: add a hint in the disabled-notice HTML (e.g., an HTML comment `<!-- rendered at [timestamp] with toggle state [enabled/disabled] -->`) so operators debugging can spot cached responses. Deferred as an optional polish for a future feature.
**Spec-Kit Task:** TASK-4 (advisory)

### [INFO] SEC-013-007 — `docs/integrations/*-example.md` MUST use context-appropriate capability values

**Location:** `specs/013-per-server-tabs-refactor/spec.md` FR-016c; planned files `docs/integrations/buddyboss-example.md` + `docs/integrations/woocommerce-example.md`
**OWASP Category:** A09:2025-Security Logging and Monitoring Failures (documentation misconfiguration advisory)
**CWE:** CWE-778: Insufficient Logging (adjacent — docs are the "log" of intended usage)
**CVSS Score:** 0.0 (informational)
**Description:** F013 ships 2 markdown integration examples (per FR-016c + Clarifications Q5). These examples serve as canonical templates that third-party integrators will copy-paste into their own plugins. If an example steers integrators toward permission-permissive misconfigurations (e.g., `cap='read'` on a mutating action; hardcoded `user_id`; missing nonce action), real-world third-party integrations would inherit the hole. Docs-as-code security matters here.
**Remediation:** No code change. Confirm at TASK-9 that the two integration docs: (a) use context-appropriate capability values (`cap='read'` for viewing own config; NEVER for mutating operations); (b) demonstrate the correct nonce action derivation; (c) demonstrate `$context['user_id']` set to `bp_displayed_user_id()` with a note that the Generate button will be disabled unless it equals `get_current_user_id()`; (d) show a `add_filter('acrossai_mcp_client_classes', ...)` example that validates the appended FQN via `class_exists() + is_subclass_of()`.
**Spec-Kit Task:** TASK-9

### [INFO] SEC-013-008 — Invalid FQNs passed to `acrossai_mcp_client_classes` filter must silently skip, not fatal

**Location:** `specs/013-per-server-tabs-refactor/spec.md` FR-016b; planned in `MCPClientsBlock::render_body()`
**OWASP Category:** A05:2025-Security Misconfiguration (robustness advisory)
**CWE:** CWE-20: Improper Input Validation
**CVSS Score:** 0.0 (informational)
**Description:** Per FR-016b, the `acrossai_mcp_client_classes` filter accepts an array of FQN strings. Third-party plugins may append FQNs of classes that don't exist (typo, wrong namespace, class removed in a later version). MCPClientsBlock MUST silently skip invalid FQNs (no fatal, no admin notice) to preserve robustness. The plan mandates this behavior explicitly. This is not a vulnerability — it's a robustness requirement that prevents third-party misuse from breaking the admin page.
**Remediation:** No code change. Confirm at TASK-4 code review that the iteration validates with BOTH `class_exists()` AND `is_subclass_of( $fqn, AbstractMCPClient::class )` before dispatching to `Client::instance()`. PublicApiTest should include a case appending an invalid FQN and asserting no fatal + valid clients still render.
**Spec-Kit Task:** TASK-4

## Confirmed Secure Patterns

The plan demonstrates these secure-by-design patterns:

| Pattern | Evidence in plan |
|:---|:---|
| **Defense-in-depth on Application Password minting** | FR-024 (UI disables button) + FR-023 (REST returns 403) — same invariant enforced at 2 independent layers |
| **Cross-context nonce replay defense** | FR-022 (nonce action binds server_id + context slug) — a novel-to-this-plugin pattern; CI-tested at TASK-4 |
| **Least-privilege capability check** | FR-021 (Renderer uses `$context['cap']` — never hardcoded `manage_options`) — enables BuddyBoss to embed with `cap='read'` while preserving admin cap on admin tabs |
| **F012 toggle enforcement inside Renderer** | FR-017 + FR-018 + FR-020 — gate lives in the layer that renders, not the layer that dispatches, so shortcode/embed contexts inherit the same enforcement |
| **REST endpoint explicit `permission_callback`** | FR-023 — no `__return_true`; explicit rejection on user_id + nonce mismatch. Complies with S2. |
| **Nonce action bound to per-server ID** | FR-022 + preserved from F013 `AbstractServerTab::nonce_field()` — per-server isolation (nonce for server 1 cannot mutate server 2) |
| **REST input sanitization at boundary** | Plan Trust Boundaries table mandates `absint($body['user_id'])` + `absint($body['server_id'])` + `sanitize_key($body['client_slug'])` + `sanitize_key($body['context'])` |
| **Silent-skip robustness for third-party misuse** | FR-016b — invalid FQNs in `acrossai_mcp_client_classes` filter silently skipped; no fatal from bad third-party input |
| **Preserved F011 SEC-001 atomic-CAS** | Ported CliAuthLogListTable is READ-ONLY; no write path introduced that could break the atomic redemption invariant |
| **Preserved F012 uninstall + settings tab invariants** | F013 does NOT touch `uninstall.php`, MCP settings tab, or the standalone CLI Auth Log admin surface removal |
| **`use` imports / leading-`\` FQN throughout** | Per A6 + B15 lesson — plan explicitly cites both patterns |
| **PHPUnit `#[DataProvider]` attribute** | Per B9 — plan tests explicitly use PHP attribute, not `@dataProvider` annotation |
| **`printf` uses one placeholder style per call** | Per B16 — plan CONSTRAINTS forbids mixed positional + numbered placeholders; explicit call-out in AbstractClientRenderer's `render_feature_disabled_notice()` code sample |

## Action Plan & Next Steps

### Findings summary
- 8 findings total: **0 CRITICAL / 0 HIGH / 0 MODERATE / 3 LOW / 5 INFO**
- Overall risk: **LOW**
- No blocking issues for `/speckit-tasks` phase

### Immediate actions (planning phase)
1. **Fold the 3 LOW findings** into TASK-4 DoD:
   - SEC-013-001: PublicApiTest case asserting cross-context nonce replay returns 403
   - SEC-013-002: TASK-4 code-review checklist — verify `===` (not `==`), `absint()` before compare, `get_current_user_id()` (not `wp_get_current_user()->ID`)
   - SEC-013-003: `(array)` cast at `resolve_context()` filter boundary + PublicApiTest case for non-array filter return
2. **Fold the 5 INFO findings** as advisory checkpoints:
   - SEC-013-004: TASK-9 docblock spot-check for `@experimental` notice
   - SEC-013-005: TASK-8 grep gate verifies F012 gate lives only in gated Blocks
   - SEC-013-006: TASK-4 optional polish note re: cache-buster hint
   - SEC-013-007: TASK-9 doc reviewer checklist for `docs/integrations/*` example correctness
   - SEC-013-008: TASK-4 code review — `class_exists() + is_subclass_of()` gating in MCPClientsBlock

### Durable Memory Preservation
No new durable security pattern surfaces from this review that isn't already staged for capture at TASK-9:
- **DEC-CLIENT-RENDERER-PUBLIC-API** will codify: cross-context nonce action pattern (SEC-013-001), user_id-lockdown pattern (SEC-013-002), context.cap parametrization (SEC-013-005 secure design), F012-gate-in-Renderer pattern (SEC-013-005), experimental-until-1.0 stability disclosure (SEC-013-004), silent-skip-third-party-misuse pattern (SEC-013-008).

No `/speckit.memory-md.capture` needed inline — all patterns already staged for TASK-9.

### Remediation planning
Not required. Zero HIGH/CRITICAL findings means no `/speckit.security-review.followup` remediation task chain is needed. All LOW findings have inline mitigations foldable into TASK-4/8/9 DoD.

### Recommended next step
Proceed to `/speckit-tasks` (or the governed variant `/speckit-architecture-guard-governed-tasks`, matching how F011/F012 were governed). The plan is security-clean.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-03-013-per-server-tabs-refactor-plan.md | plan | 2026-07-03 | LOW | C:0 H:0 M:0 L:3 | A01,A03,A05,A09 |
```
