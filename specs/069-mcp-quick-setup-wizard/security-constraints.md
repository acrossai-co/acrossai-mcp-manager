---
document_type: security-review
review_type: plan
assessment_date: 2026-08-16
codebase_analyzed: acrossai-mcp-manager / feature 069 MCP Quick Setup Wizard
total_files_analyzed: 6
total_findings: 8
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 2
low_count: 3
informational_count: 3
owasp_categories: [A01, A03, A04, A05, A07]
cwe_ids: [CWE-79, CWE-89, CWE-209, CWE-613]
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

# Security Review — Plan Phase: MCP Quick Setup Wizard (Feature 069)

## Executive summary (plain English)

The plan is **secure by design** — every authenticated route sits behind `manage_options`, every mutating route requires an X-WP-Nonce, and all authoritative writes flow through existing plugin APIs that have their own security review history (F015 AC, F017 abilities, F029 REST hardening). The wizard doesn't handle any secrets (App Passwords, OAuth tokens) directly. **Zero Critical or High findings.**

What's worth tightening before implementation lands:
- **Two Medium items** — one about being specific in the plan on which WordPress sanitizer to use for each Step 1 create-form field (the spec/plan mention sanitizers loosely); one about how Step 3's "Enable all abilities" reaches into the F017 abilities controller (proxying through REST when a direct service-class call would be simpler and remove an internal auth loop).
- **Three Low items** — belt-and-suspenders constraints on error-message hygiene, clipboard-copy safety, and REST-nonce refresh behavior over long-lived wizard sessions.
- **Three Informational** — expected transient-in-wp_options behavior, absence of rate limiting (fine for admin-only), and cross-user server catalog sharing (fine because every admin sees the same catalog).

Fold the 5 non-informational findings into `tasks.md` when `/speckit-tasks` runs; that keeps the review actionable without blocking the current phase.

## Plan artifacts reviewed

| File | Reviewed |
|---|---|
| `specs/069-mcp-quick-setup-wizard/plan.md` | ✅ full read |
| `specs/069-mcp-quick-setup-wizard/spec.md` | ✅ full read (with clarifications session) |
| `specs/069-mcp-quick-setup-wizard/research.md` | ✅ full read (7 decisions) |
| `specs/069-mcp-quick-setup-wizard/data-model.md` | ✅ full read (7 entities) |
| `specs/069-mcp-quick-setup-wizard/contracts/quick-setup-rest.md` | ✅ full read (3 REST routes) |
| `specs/069-mcp-quick-setup-wizard/contracts/react-router.md` | ✅ full read (URL + a11y contract) |
| `specs/069-mcp-quick-setup-wizard/contracts/wizard-state.md` | ✅ full read (client store shape) |
| `specs/069-mcp-quick-setup-wizard/quickstart.md` | ✅ full read (dev runbook) |
| `specs/069-mcp-quick-setup-wizard/memory-synthesis.md` | ✅ full read (retrieval-ranked memory) |
| `.specify/memory/constitution.md` | ✅ full read (Principles III/IV/VI applied) |

No `security_constitution.md`, `architecture_constitution.md`, or feature-level `memory.md` present. Historic security posture pulled from memory-synthesis + `docs/memory/INDEX.md` §Security Constraints (S1–S9).

## Vulnerability findings

### SEC-001 — [MEDIUM] Sanitizer-function choices for Step 1 create-form fields under-specified

**Finding_id**: SEC-001
**Location**: `specs/069-mcp-quick-setup-wizard/contracts/quick-setup-rest.md:97` (Step 1b payload)
**OWASP category**: A03:2025-Injection
**CWE**: CWE-89: Improper Neutralization of Special Elements in an SQL Command (SQL Injection) / CWE-20: Improper Input Validation
**CVSS score**: 4.3 (Network / Low complexity / High privilege / User interaction / Unchanged / Low integrity impact)
**Spec_kit_task**: TASK-SEC-001

**Description**: The contract file lists sanitizers per field ("`server_slug` → `sanitize_title`; `server_route_namespace` → `sanitize_key`; `server_version` → `sanitize_text_field`"), but does NOT require the wizard's REST handler to match the sanitizer choices used by the plugin's **existing** MCPServer write path (`admin/Partials/Settings.php` new-server form handler). A drift risk exists: the existing form may apply stricter validation (e.g., regex `^[a-z0-9\-_]+$` on `server_slug`) that the wizard's REST handler doesn't replicate, allowing values through the wizard that the DB write later trips over — or worse, values that satisfy `sanitize_title` but violate an admin-side invariant.

**Recommendation**: Before implementation, TASK-SEC-001 MUST:
1. Grep the existing new-server write path (`admin/Partials/Settings.php` or wherever the current create-new-server form handler lives) for its exact sanitizer sequence per field.
2. Extract the sanitizer sequence into a shared helper (`AcrossAI_MCP_Manager\Includes\Utilities\MCPServerFieldSanitizer` or similar) called from BOTH the existing admin form and the new `QuickSetupController::handle_step_1_create()`.
3. Add a PHPUnit test asserting identical sanitized output for a shared fixture set (10+ edge-case inputs including Unicode, HTML tags, SQL injection strings).

**Rationale**: Constitution §VI DRY + §III "sanitize at boundary with the most specific function" — a shared sanitizer is the only way to guarantee both surfaces converge.

---

### SEC-002 — [MEDIUM] Step 3 "Enable all abilities" internal REST-to-REST call pattern

**Finding_id**: SEC-002
**Location**: `specs/069-mcp-quick-setup-wizard/contracts/quick-setup-rest.md:126-131` (Step 3 payload behavior)
**OWASP category**: A04:2025-Insecure Design
**CWE**: CWE-670: Always-Incorrect Control Flow Implementation
**CVSS score**: 4.7 (Network / High complexity / High privilege / User interaction / Unchanged / Low integrity impact)
**Spec_kit_task**: TASK-SEC-002

**Description**: The plan specifies that Step 3 "Enable all" fires a request to `POST /quick-setup/step` with `{step:3, data:{enable_all_abilities:true}}`, and the controller then "POSTs to F017's abilities-update route with the full ability set." This is an internal REST-to-REST call inside the same PHP request context. Two concrete concerns:

1. **Auth double-check anti-pattern**: The wizard's REST controller has already verified `manage_options` + nonce. Round-tripping through the F017 REST route re-runs the same checks — wasted CPU and, more importantly, opens a "same PHP process, different nonce lifecycle" foot-gun (the internal call needs a nonce that isn't the same as the wizard's). The plan doesn't say whether the internal call reuses the wizard's nonce, creates a fresh one, or bypasses nonce verification — every option has hazards.
2. **Error-path complexity**: If the F017 internal call fails, the wizard's outer response must translate F017's error shape into the wizard's error shape. The plan is silent on this mapping.

**Recommendation**: TASK-SEC-002 MUST refactor the plan/design to call **F017's underlying service class method directly** from `QuickSetupController::handle_step_3()`, not via REST. Concretely:
1. Identify the F017 service class (likely `Includes\Abilities\ExposureResolver` or similar) that implements the "enable a set of abilities for a server" operation.
2. If no such service method exists, extract one from F017's REST controller into a public method that the wizard's controller can call directly.
3. Wizard's controller invokes the service method with `server_id` + full ability set; wraps any exception into the wizard's own `WP_Error` shape.
4. Zero REST-to-REST hops; single auth check (the outer wizard route's).

**Rationale**: Matches Constitution §V (extensibility via reused APIs, not artificial REST plumbing) + eliminates the sub-request nonce confusion. This is also cleaner per DEC-ABILITY-OVERRIDE-RESOLUTION's "route through `ExposureResolver::resolve()`" mandate — direct service call, not REST round-trip.

---

### SEC-003 — [LOW] REST error responses risk leaking internals

**Finding_id**: SEC-003
**Location**: `specs/069-mcp-quick-setup-wizard/contracts/quick-setup-rest.md:154-163` (error response table)
**OWASP category**: A05:2025-Security Misconfiguration
**CWE**: CWE-209: Generation of Error Message Containing Sensitive Information
**CVSS score**: 3.1 (Network / Low complexity / High privilege / User interaction / Unchanged / Low confidentiality impact)
**Spec_kit_task**: TASK-SEC-003

**Description**: The REST contract lists error codes (`acrossai_mcp_quick_setup_persist_failed`, `acrossai_mcp_quick_setup_server_gone`) but does NOT constrain error message contents. A naive implementation might pass raw `$wpdb->last_error`, PHP exception messages, or transient key strings into `WP_Error` messages — surfacing internal state to any admin user who could inspect the JSON response (or to any error-tracking service that captures them).

**Recommendation**: TASK-SEC-003 MUST add this constraint to the plan (and enforce in `QuickSetupController` implementation):
> Every `WP_Error` returned by the wizard REST layer MUST use a hand-authored, user-facing message. Raw exception text, `$wpdb->last_error`, transient key strings, or file paths MUST NEVER appear in the response message. Internal diagnostics MAY be logged via `error_log` (never `_doing_it_wrong`, which surfaces to admin UI on `WP_DEBUG`).

Concrete PHPCS/grep gate:

```bash
grep -rEn "'message'\s*=>\s*\\\$e->getMessage\(\)|'message'\s*=>\s*\\\$wpdb->" includes/REST/QuickSetupController.php
# Expected result: zero matches
```

**Rationale**: Constitution §III + S2 imply this but don't explicitly forbid leaking internals. Explicit is safer than implicit.

---

### SEC-004 — [LOW] Copy-to-clipboard MUST use text-node rendering, not innerHTML

**Finding_id**: SEC-004
**Location**: `specs/069-mcp-quick-setup-wizard/contracts/wizard-state.md` (CodeBlock component) + `contracts/react-router.md` Step 5 method panels
**OWASP category**: A03:2025-Injection
**CWE**: CWE-79: Improper Neutralization of Input During Web Page Generation (XSS)
**CVSS score**: 3.5 (Local / Low complexity / High privilege / User interaction / Unchanged / Low integrity impact)
**Spec_kit_task**: TASK-SEC-004

**Description**: Step 5's method panels render command strings (`npx …`, `wp mcp-adapter …`) and JSON configs that include `server_slug`, `site_url()`, and other server-derived values. These values are ultimately admin-controlled (Step 1 create-form input) but pass through user-attacker-shaped paths (URL-encoded, base64-decoded, template-interpolated) before reaching the React CodeBlock. If any implementation uses `dangerouslySetInnerHTML` (or an equivalent unsafe render path) to display a code block, an admin who creates a server with a specially-crafted `server_name` could inject markup into another admin's wizard view.

**Recommendation**: TASK-SEC-004 MUST add this constraint to the plan:
> The `CodeBlock` component MUST render its contents as a plain text child of a `<pre>` or `<code>` element. `dangerouslySetInnerHTML` is FORBIDDEN inside the wizard React tree. Any per-server template interpolation (npm command, MCP URL, JSON config) MUST perform interpolation as JavaScript string concatenation, NEVER as `innerHTML` assignment or `document.write`.

Enforcement grep-gate for `/speckit-security-review-staged`:

```bash
grep -rn "dangerouslySetInnerHTML" src/js/quick-setup/
# Expected result: zero matches
```

**Rationale**: React auto-escapes text nodes but not `dangerouslySetInnerHTML`. Because Step 1's create form doesn't currently apply any HTML-tag stripping to `server_name` (per DR: `sanitize_text_field` strips tags but not HTML entities), a `server_name` = `&lt;script&gt;alert(1)&lt;/script&gt;` could round-trip to the wizard summary + method panels; safe under text-node render, dangerous under `innerHTML`.

---

### SEC-005 — [LOW] 12-hour REST nonce lifetime + long-lived wizard sessions

**Finding_id**: SEC-005
**Location**: `specs/069-mcp-quick-setup-wizard/plan.md` (bootstrap payload localization) + `contracts/wizard-state.md` (REST interaction)
**OWASP category**: A07:2025-Identification and Authentication Failures
**CWE**: CWE-613: Insufficient Session Expiration
**CVSS score**: 2.8 (Network / High complexity / High privilege / User interaction / Unchanged / Low availability impact)
**Spec_kit_task**: TASK-SEC-005

**Description**: The plan localizes `restNonce = wp_create_nonce('wp_rest')` inside the bootstrap payload — a standard pattern. WordPress REST nonces have a ~12-hour lifetime by default. If an admin opens the wizard, walks away for 12+ hours, and returns to click Continue, the POST fails with 403 and the wizard shows an opaque error. Related bug pattern B25 (from memory): `apiFetch` middleware wiring is easy to get wrong — the WP-canonical shape is `createNonceMiddleware(nonce)` alone; ADDING `createRootURLMiddleware` (redundant in admin) risks double-slash 404s.

**Recommendation**: TASK-SEC-005 MUST:
1. Explicitly wire `apiFetch.use( apiFetch.createNonceMiddleware(bootstrap.restNonce) )` in `src/js/quick-setup.js`. Do NOT wire `createRootURLMiddleware` (matches F017 abilities.js:95 per B25).
2. When a 403 error is caught in `useWizardState`'s `saveStep`, surface a user-friendly error: *"Your session has expired. Please reload the page to continue."* (rather than a generic "network error"). This matches the WordPress convention for expired-nonce failures.
3. Optionally (future enhancement, not required now): register a `heartbeat` listener that refreshes the localized nonce hourly for open-tab-longevity. Deferred to a follow-up feature since the wizard session is expected to be short (SC-001 = <3 min task time).

**Rationale**: Item 1 is B25 mitigation (avoid the middleware anti-pattern). Item 2 improves the UX of the expired-nonce case without changing security posture.

---

### SEC-006 — [INFORMATIONAL] Transient keys stored in `wp_options`

**Finding_id**: SEC-006
**Location**: `specs/069-mcp-quick-setup-wizard/data-model.md` (E1 + E2)
**OWASP category**: (none)
**CWE**: (none — expected behavior)
**CVSS score**: 0.0
**Spec_kit_task**: (no follow-up)

**Description**: When object cache is absent (default WordPress install), transients land in `wp_options` under `_transient_{key}` and `_transient_timeout_{key}`. Any admin with `manage_options` can `SELECT * FROM wp_options WHERE option_name LIKE '_transient_acrossai_mcp_manager_quick_setup_%'`. This exposes: (a) which admins have in-flight wizard sessions (E1's per-user key includes user_id), and (b) their partial answers.

**Assessment**: Not a vulnerability — every admin visible in this query already has `manage_options` and could read the underlying data (server list, AC rules, ability selections) directly. The exposure is the CONTENT of an admin's in-flight setup choices, which is not sensitive relative to their existing capabilities.

**No remediation required.** Documented for future reference in case the wizard's scope ever expands to include less-privileged users (which would require reconsidering the storage strategy).

---

### SEC-007 — [INFORMATIONAL] No rate limiting on wizard REST routes

**Finding_id**: SEC-007
**Location**: `specs/069-mcp-quick-setup-wizard/contracts/quick-setup-rest.md`
**OWASP category**: (none — accepted risk for admin-only surface)
**CWE**: (none)
**CVSS score**: 0.0
**Spec_kit_task**: (no follow-up)

**Description**: The three REST routes have no rate-limit protection. A malicious or buggy admin client could spam `POST /step` and thrash the transient table.

**Assessment**: Accepted risk — every route is `manage_options`-only. An attacker with `manage_options` has direct DB access via WP-CLI or phpMyAdmin; rate-limiting the wizard endpoint would not meaningfully raise the bar. Standard WordPress admin surfaces (post autosave, media upload) similarly go unlimited.

**No remediation required.**

---

### SEC-008 — [INFORMATIONAL] Cross-admin server catalog sharing

**Finding_id**: SEC-008
**Location**: `specs/069-mcp-quick-setup-wizard/data-model.md` E1 (Wizard Scratchpad — per-user) vs E3 (MCP Server — site-level)
**OWASP category**: (none)
**CWE**: (none)
**CVSS score**: 0.0
**Spec_kit_task**: (no follow-up)

**Description**: The wizard scratchpad is per-user but the MCP server catalog is site-level. If Admin A creates server X, then Admin B logs in, Admin B's wizard sees server X in the Step 1 picker (via `GET /state`). This is expected — all `manage_options` admins share the same server catalog everywhere in the plugin, not just in the wizard.

**Assessment**: Not a vulnerability — matches the plugin's existing model. Documented for future reference in case a per-user server scoping model is ever introduced.

**No remediation required.**

## Confirmed secure patterns

- **A01 Broken Access Control**: Every REST route explicitly declares `permission_callback` returning `current_user_can('manage_options')` boolean (S2 compliant). The wizard render hijack in `Settings.php` performs the same check before rendering. Non-admins reaching any wizard URL get `wp_die` (page render) or 401 (REST).
- **CSRF/Nonce**: Every mutating REST route requires `X-WP-Nonce` for action `wp_rest` (S1 compliant). No `admin-post.php` handlers (Q2 pivot removed the Dismiss handler that would have needed a separate nonce).
- **Open Redirect**: `wp_safe_redirect` used for activation redirect (redirects only to same-host URLs). Target URL is hardcoded in `ActivationRedirect::maybe_redirect()`, not derived from any user-controllable input.
- **Multi-tenant guards**: FR-004 explicitly suppresses activation redirect on bulk-activation + network-activation contexts — no confused-deputy risk.
- **No credential handling**: Wizard handles no OAuth tokens, App Passwords, or secrets directly (S3 not applicable). Step 5's method panels display config templates but never generate credentials.
- **Extensibility contract**: Additive-only (FR-029/030) enforced via post-implementation grep gate. Zero risk of accidentally weakening existing security surfaces (F015 AC, F017 abilities, F029 OAuth) via wizard edits.

## Action Plan & Next Steps

1. **Durable Memory Preservation check** — **No new architectural patterns or reusable security decisions identified this turn.** Findings SEC-001 through SEC-005 are all specific to this feature's implementation shape; SEC-006 through SEC-008 are expected behaviors. No `/speckit.memory-md.capture` needed.

2. **Remediation planning** — No Critical or High findings; `/speckit.security-review.followup` is not required. Fold SEC-001 through SEC-005 into `tasks.md` when `/speckit-tasks` runs. Suggested task IDs: TASK-SEC-001 through TASK-SEC-005 (aligned with finding IDs).

3. **Recommended next command** — `/speckit-architecture-guard-violation-detection` (Step 5 of the governed-plan orchestrator) to validate the plan against the architecture constitution + memory constraints.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-08-16-069-mcp-quick-setup-wizard-plan.md | plan | 2026-08-16 | LOW | C:0 H:0 M:2 L:3 I:3 | A01,A03,A04,A05,A07 |
```

Save location: this review is authoritative at `specs/069-mcp-quick-setup-wizard/security-constraints.md` (per `/speckit-architecture-guard-governed-plan` Step 4). If you also want the audit trail under `docs/security-reviews/`, copy the file there and use the row above.
