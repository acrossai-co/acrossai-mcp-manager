---
document_type: security-review
review_type: plan
assessment_date: 2026-06-30
codebase_analyzed: acrossai-mcp-manager (Phase 7 — 007-frontend-cli-auth)
total_files_analyzed: 9
total_findings: 7
overall_risk: MODERATE
critical_count: 0
high_count: 0
medium_count: 1
low_count: 3
informational_count: 3
owasp_categories: [A01, A04, A05, A09]
cwe_ids: [CWE-352, CWE-441, CWE-451, CWE-732, CWE-352, CWE-209, CWE-1004]
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

# Security Review — Plan Artifact (Phase 7 / Feature 007-frontend-cli-auth)

## Executive Summary

The plan replaces `public/Partials/FrontendAuth.php` with a re-spec'd singleton that renders the browser-mediated CLI consent surface for the Phase 6 device-code-grant flow. Implementation surface is one PHP file, no new tables / options / REST routes. The plan's security posture is mostly strong: nonce-before-mutation, sanitize-at-boundary / escape-at-output, `nocache_headers()` before any output, kill-switch default OFF, no theme chain (eliminating wp_head injection), no JavaScript (eliminating XSS gadget chains), and an explicit threat-model decision to drop `?action`/`?code`/`?server` from the post-login redirect so as not to widen the `wp_safe_redirect()` attack surface (research §R3).

There is **one MEDIUM finding** that the existing `security-constraints.md` (governed-plan orchestrator output, 2026-06-26) did not surface: the **server slug displayed in the consent UI is not verified against the transient's bound server_id** — it is taken verbatim from `?server=` in the URL. An attacker holding (or substituting) a pending auth code can craft a URL whose displayed server name does not match the server the App Password will actually be bound to (confused-deputy / UI misrepresentation). This is a plan-level gap that should be fixed before implementation.

Three LOW findings document residual risks around the action-only nonce decision (cross-code replay via leaked HTML), the broadened "any logged-in user" authorization (auth code becomes a bearer token for any concurrent logged-in user on the site), and GET as the state-mutation verb. Three INFORMATIONAL findings cover deploy-time hygiene (asset-version fallback, 503 noindex/Retry-After, nonce-vs-code TTL mismatch).

No CRITICAL or HIGH findings. The plan is implementable; SEC-001 should be addressed by adding a `CliController` read helper that returns the transient's stored `server_id` for display.

## Plan Artifacts Reviewed

| Path | Notes |
|---|---|
| `specs/007-frontend-cli-auth/plan.md` | Implementation plan, constitution check, complexity tracking |
| `specs/007-frontend-cli-auth/spec.md` | 6 user stories + 16 FRs + 7 SCs + Security Checklist + Assumptions |
| `specs/007-frontend-cli-auth/research.md` | R1 nonce scope, R2 asset fallback, R3 login redirect URL |
| `specs/007-frontend-cli-auth/data-model.md` | Constants, query var, option, GET params, transient flow diagram |
| `specs/007-frontend-cli-auth/contracts/page-cli-auth.md` | `?action=cli_auth` HTML response contract |
| `specs/007-frontend-cli-auth/contracts/page-cli-auth-approve.md` | `?action=cli_auth_approve` redirect / failure contract |
| `specs/007-frontend-cli-auth/contracts/page-cli-auth-approved.md` | `?action=cli_auth_approved` success contract |
| `specs/007-frontend-cli-auth/contracts/page-disabled-notice.md` | Kill-switch 503 contract |
| `specs/007-frontend-cli-auth/security-constraints.md` | Prior governed-plan security note (2026-06-26) |
| `specs/007-frontend-cli-auth/memory-synthesis.md` | Memory-hub synthesis (S1/S6/S8, A1/A2/A6, B4/B5) |

Cross-referenced: `.specify/memory/constitution.md` §III, `docs/memory/INDEX.md`, `docs/memory/PROJECT_CONTEXT.md` (S6/S7/S8), `docs/memory/BUGS.md` (B11 transient hygiene), `includes/REST/CliController.php` (Phase 6 contract).

## Vulnerability Findings

### SEC-001 — Server slug displayed in consent UI is not verified against the transient (Confused-Deputy / UI Misrepresentation)

- **Severity**: MEDIUM
- **CVSS v3.1**: 4.7 (`AV:N/AC:H/PR:L/UI:R/S:U/C:N/I:L/A:N`)
- **OWASP**: A04:2025-Insecure Design
- **CWE**: CWE-451 (User Interface (UI) Misrepresentation of Critical Information), CWE-441 (Unintended Proxy or Intermediary)
- **Location**: `spec.md` FR-008, FR-012; `contracts/page-cli-auth.md:28` ("A CLI tool is requesting access to your MCP server '<escaped-server-slug>'."); `data-model.md` §4 (`server` GET param, "rendered (escaped) in consent UI; downstream validated against transient by CliController")
- **Spec-Kit task**: TASK-SEC-001

**Issue**

The consent form's body text — the one piece of attacker-controlled, user-visible information the security decision is grounded on — is taken verbatim from the `?server=` GET parameter. The transient `acrossai_cli_auth_<code>` already holds the authoritative `server_id` (set at `/auth/start`), but `handle_cli_auth($code, $server)` never reads it. The downstream `CliController::approve_auth_code( $code, $user_id )` signature (verified in `includes/REST/CliController.php:490`) takes ONLY the code and user id — it does NOT re-verify `?server=` against the transient. The bound server_id is read directly from the transient at `CliController.php:517` (`'server_id' => (string) ( $payload['server_id'] ?? '' )`).

**Attack**

1. An attacker initiates `/auth/start` against the legitimate server they own (`server_id=evil-server`), receives a pending `auth_code`.
2. The attacker rewrites the auth_url, replacing `&server=evil-server` with `&server=production-wordpress`.
3. The attacker delivers this URL to a logged-in target via phishing.
4. The target sees: "A CLI tool is requesting access to your MCP server 'production-wordpress'. Click Approve…"
5. The target approves. `CliController::approve_auth_code()` binds the App Password's session token to `evil-server` (the transient's value), NOT `production-wordpress`.
6. The CLI completes `/auth/exchange` and receives a working App Password tied to the target's user identity, with downstream binding to `evil-server`.

The blast radius is mediated by the Phase 6 Q4 server-binding (the session token is `array{user_id, server_id}`, so the App Password only works against `evil-server`), but the user authorized something different than what was displayed. In a multi-tenant MCP-server site, this is a real consent-integrity break.

**Recommendation**

Add a `CliController::peek_pending_server( string $code ): ?string` read-only helper that returns the transient's bound `server_id` for codes whose status is `pending`. In `handle_cli_auth()`:

1. Read the bound server from the transient via the helper.
2. If the helper returns `null` (unknown / expired / non-pending code), render the "Missing Authentication Parameters" path (do NOT trust `?server=` for display).
3. If the URL-supplied `?server=` does not match the transient's value, EITHER render the transient's value (recommended — single source of truth) OR refuse the request with an error page citing slug mismatch.
4. Keep escaping the displayed value via `esc_html()` regardless.

Update FR-012 and the `page-cli-auth.md` contract to reflect that the rendered server slug is sourced from the transient, not from `$_GET`. Update the test matrix to cover the mismatch case.

---

### SEC-002 — Action-only nonce `cli_auth_approve` permits cross-code replay if rendered HTML is exfiltrated (CSRF — residual)

- **Severity**: LOW
- **CVSS v3.1**: 3.1 (`AV:N/AC:H/PR:L/UI:R/S:U/C:N/I:L/A:N`)
- **OWASP**: A01:2025-Broken Access Control
- **CWE**: CWE-352 (Cross-Site Request Forgery)
- **Location**: `spec.md` FR-009; `research.md` §R1; `contracts/page-cli-auth.md:42–47` (`wp_create_nonce( 'cli_auth_approve' )`)
- **Spec-Kit task**: TASK-SEC-002

**Issue**

Research §R1 chose `wp_create_nonce('cli_auth_approve')` over `wp_create_nonce('cli_auth_approve_' . $code)` on the grounds that the downstream `pending`-check enforces single-use semantics anyway. That's correct for the standard CSRF threat (an attacker cannot forge `_wpnonce` without the victim's session-bound secret).

However, an action-only nonce minted in any rendered consent page (for any `code`) is a generic "approve anything" token for the current logged-in user for the next 12–24 hours (WordPress nonce default lifetime). The per-code form (`cli_auth_approve_<code>`) would have bound the nonce to that specific code, preventing replay against a different code.

Threat scenario: a target opens the consent page for code A but does not click Approve. The HTML containing the nonce is leaked (browser-cache scrape via local malware, network-level proxy logging on a hostile coffee-shop network, screenshot accidentally shared, etc.). Within the nonce window AND within the auth code's 5-minute TTL of code B (a different pending code), the attacker can replay the same nonce against code B.

Mitigations already in place: 5-minute auth code TTL is much shorter than the 12–24h nonce window, `nocache_headers()` defeats CDN/proxy caching, no JS surface eliminates XSS-based DOM exfiltration. The remaining vector requires a secondary HTML-leak channel that is generally outside the plan's control.

**Recommendation**

Either:

1. **Adopt the per-code action**: change FR-009 to `wp_verify_nonce( $nonce, 'cli_auth_approve_' . $code )`. The code is already part of the verified GET payload — sanitize it BEFORE composing the action string. Audit cost is low (one extra string concatenation).
2. **OR document the residual risk explicitly** in `security-constraints.md` so future reviewers do not have to re-derive the analysis. Note that the 5-minute code TTL is what bounds the replay window; if any future change extends auth code TTL, this finding's severity rises.

Research §R1's stated rationale ("simpler to audit; matches `delete-comment`") is accurate but does not consider the leaked-HTML threat. Recommending option 1 — the per-code action is a one-line change with zero implementation cost and a strictly tighter binding.

---

### SEC-003 — Broadened "any logged-in user" authorization turns auth code into a bearer-for-anyone-logged-in token

- **Severity**: LOW
- **CVSS v3.1**: 3.5 (`AV:N/AC:H/PR:L/UI:R/S:U/C:N/I:L/A:N`)
- **OWASP**: A01:2025-Broken Access Control
- **CWE**: CWE-732 (Incorrect Permission Assignment for Critical Resource), CWE-441 (Unintended Proxy or Intermediary)
- **Location**: `spec.md` FR-007.4, §Assumptions; `plan.md` §Complexity Tracking ("Broadened `manage_options` check to any logged-in user"); `security-constraints.md` "Authorization Assumptions" table
- **Spec-Kit task**: TASK-SEC-003

**Issue**

The plan deliberately drops the `current_user_can('manage_options')` check, allowing ANY logged-in user (including subscribers) to approve a CLI auth code. The justification — "the App Password is scoped to the consenting user's capabilities, so blast radius is bounded" — is correct as a first-order defense.

The residual concern that is NOT addressed in the plan: at `/auth/start` time, the CLI's auth code is NOT bound to a specific WP user. Anyone who acquires the auth code (by any means — screen share, copy/paste, on-screen exposure, terminal logging) and who is concurrently logged in to the same WP site can paste the URL in their own browser and approve it as themselves. The CLI then receives an App Password tied to the WRONG user identity (the attacker), but the CLI does not know this — it just sees a working App Password.

Practical impact:
- The legitimate user expected the CLI to act as themselves; instead it acts as the attacker. Any data the CLI subsequently writes is attributed to the wrong author. Any reads return the wrong user's permitted subset.
- If the legitimate user holds elevated privileges (admin), the attacker (subscriber) "downgrades" the CLI session to the subscriber's caps — generally lower harm.
- If both are non-admin but differ in role (e.g. editor vs author), data integrity is compromised in non-obvious ways.

Plan §Assumptions acknowledges the boundary: "the user clicking Approve is consenting on their own behalf". The threat-model framing is reasonable for a single-developer workstation use case. It does not survive a multi-user-on-shared-device scenario (kiosk, lab machine, shared family device).

**Recommendation**

This is largely a **Phase 6** scope concern (auth code binding), not Phase 7. Three options at increasing cost:

1. **Document the threat model explicitly in spec §Assumptions** — explicitly note "auth code is a bearer token visible to anyone logged in concurrently; do not initiate the CLI flow on shared devices". Quickstart already documents the operator-side enable step; add a parallel note for end users.
2. **Bind the auth code to the initiating WP user at `/auth/start`** — requires authenticated `/auth/start` (currently unauthenticated by Phase 6 design). Would change the CLI UX (CLI must first prompt for credentials), which is a fundamental flow change.
3. **Add a one-time-use binding cookie** — at `/auth/start`, set a short-TTL HTTP-only cookie with a server-side-stored secret; verify in `handle_approve()`. Cookie reaches the browser only when the CLI deep-links into the WP-origin browser context. This is a non-trivial protocol addition.

For Phase 7 alone, recommend option 1 (documentation). Track options 2 and 3 as a follow-up scoped to a hardening epic.

---

### SEC-004 — State-mutating action via GET enables prefetch / linktape side effects (CSRF — defense in depth)

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 2.6 (`AV:N/AC:H/PR:L/UI:R/S:U/C:N/I:L/A:N`)
- **OWASP**: A01:2025-Broken Access Control
- **CWE**: CWE-352 (Cross-Site Request Forgery)
- **Location**: `spec.md` FR-008 dispatch table (`cli_auth_approve` row marked GET, Mutating=YES, Nonce required=YES); `research.md` §R1 ("Switching to a POST form for one button is YAGNI")
- **Spec-Kit task**: TASK-SEC-004

**Issue**

The plan uses GET as the verb for the `cli_auth_approve` state mutation. WP nonce verification defeats the standard CSRF threat. However, GET state-mutations are sometimes triggered by browser/extension behaviors that POST is not: link prefetch (Chrome `<link rel="prerender">`, Safari preconnect on hover), some accessibility-tool "click-everything" sweeps, antivirus URL scanners.

For all of these to actually approve a code, they would need the valid `_wpnonce` value AND cookies AND a pending code in the transient. Prefetchers normally do not include cookies on cross-origin GET; antivirus scanners do not run inside the logged-in session. So the residual exposure is small and bounded by FR-007.2's `nocache_headers()` (the consent page is not cached).

**Recommendation**

Acceptable as-is for Phase 7 with explicit risk acknowledgement. If the consent surface ever grows beyond one button (e.g. adds "Approve as", a scope selector, a server picker), revisit the POST decision. Note in `security-constraints.md` that the GET dispatch is conditional on (a) `nocache_headers()` being emitted on every render, (b) no `Link: <...>; rel=preload/prerender` headers ever being added to the consent page response.

---

### SEC-005 — Kill-switch 503 response lacks `Retry-After` and `noindex` directives

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 1.6 (`AV:N/AC:H/PR:L/UI:N/S:U/C:N/I:N/A:L`)
- **OWASP**: A05:2025-Security Misconfiguration
- **CWE**: CWE-1004 (Sensitive Cookie Without 'HttpOnly' Flag) — *closest match for "missing protective HTTP directive"; no perfect CWE*
- **Location**: `contracts/page-disabled-notice.md:11–29`
- **Spec-Kit task**: TASK-SEC-005

**Issue**

The disabled-notice 503 response includes `nocache_headers()` but no `Retry-After` (RFC 7231) and no `<meta name="robots" content="noindex,nofollow">`. A 503 page that ends up in a search index because a crawler hit it while the kill switch was disabled by accident would leak the URL of the consent surface to opportunistic scanners.

The reality: the rewrite rule is only registered when the plugin is active; the URL is plugin-specific and easily discoverable from any disclosed install. The disclosure is small.

**Recommendation**

In `render_disabled_notice()`, add `header('Retry-After: 3600');` (operator-tunable) and `<meta name="robots" content="noindex,nofollow">` inside `<head>`. Both are one-line additions in the rendering helper.

---

### SEC-006 — Asset-version fallback `'0.0.0'` silently masks build-pipeline misconfiguration

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 1.3 (`AV:L/AC:H/PR:H/UI:N/S:U/C:N/I:N/A:L`)
- **OWASP**: A05:2025-Security Misconfiguration
- **CWE**: CWE-1004 (closest "missing operational signal" mapping)
- **Location**: `research.md` §R2; `spec.md` FR-013 step 3
- **Spec-Kit task**: TASK-SEC-006

**Issue**

Research §R2 explicitly rejects `error_log()` on the missing-asset path on the grounds that "log noise on every page load is worse than silent degradation". That's defensible for runtime hygiene but creates a deploy-time blindspot: a misconfigured release where `build/css/frontend.asset.php` was not generated will serve the consent page with `?ver=0.0.0` indefinitely, with no operational signal.

The downstream consequence is browser-cache staleness, not a security break. Including this finding only because it interacts with SEC-005 (operator-runbook gap) — both stem from a "fail silent, fix-by-CI-gate" posture.

**Recommendation**

Add a CI gate (in `package.json` scripts or `.github/workflows/`) that fails the release pipeline if `build/css/frontend.css` or `build/css/frontend.asset.php` is missing OR if `frontend.asset.php` does not match the expected shape `['dependencies' => array, 'version' => string]`. The runtime behavior can stay silent.

---

### SEC-007 — Nonce 12–24h window much wider than 5-minute auth-code TTL (operational invariant — informational)

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 1.0 (`AV:N/AC:H/PR:L/UI:R/S:U/C:N/I:N/A:N`)
- **OWASP**: A09:2025-Security Logging and Monitoring Failures (operational invariant tracking)
- **CWE**: CWE-209 (Generation of Error Message Containing Sensitive Information) — closest fit for "operational state not observable"
- **Location**: `spec.md` §Edge Cases ("Nonce reuse across approval clicks"); `research.md` §R1
- **Spec-Kit task**: TASK-SEC-007

**Issue**

The plan acknowledges that WP nonces have a 12–24h lifetime and that the single-use guarantee on approval comes from `CliController::approve_auth_code()` returning `false` for non-`pending` codes (per Phase 6 FR-008.1). This is correct but is an **operational invariant** that is not asserted anywhere in the plan's test matrix. If Phase 6 ever loosens the `pending`-check (e.g. allows `approved` retries), the action-only nonce immediately becomes a 24h replay window with no compensating control.

**Recommendation**

Add a regression test in `tests/phpunit/CliRest/FrontendAuth/HandleApproveTest.php` that asserts: when `CliController::approve_auth_code()` is stubbed to return `false` (simulating non-`pending` status), the HTTP response is 400, no redirect is issued, and `wp_safe_redirect()` is verified NOT called. This locks in the assumption so future Phase 6 changes break the test if they widen the invariant.

## Confirmed Secure Patterns

- ✅ **Nonce verified BEFORE state mutation** (FR-009). `wp_verify_nonce` is constant-time; failure → `wp_die(403)`.
- ✅ **Sanitize-at-boundary, escape-at-output** (FR-009, FR-010, FR-012). All `$_GET` reads pass through `wp_unslash() + sanitize_text_field()` in correct order; all rendered values use the most-specific escape function.
- ✅ **`nocache_headers()` emitted BEFORE any output** (FR-007.2). Prevents intermediary caches from serving stale pages with embedded nonces.
- ✅ **Standalone HTML shell — no theme, no `wp_head()`, no JS** (FR-011). Eliminates third-party theme/plugin code injection into the consent surface and eliminates an entire XSS gadget class.
- ✅ **Asset enqueue scoped to consent page only** via `get_query_var('acrossai_mcp_auth')` guard (FR-013). Prevents global CSS leak.
- ✅ **Unauthenticated visitors → `wp_login_url()`** (FR-007.3). No custom auth surface, no public 403.
- ✅ **Login redirect uses BASE URL only**, not full request URI (research §R3). Sidesteps URL-encoding round-trip + future `wp_safe_redirect()` injection via attacker-influenced `?code=`/`?server=` values containing `//`.
- ✅ **Kill switch default OFF** (FR-007.6, data-model §3). Operators must explicitly opt in via `wp option update acrossai_mcp_npm_login_enabled 1`.
- ✅ **Singleton + private constructor** (FR-002, A2, S6, B5). Prevents double hook registration.
- ✅ **All hooks via Loader; zero `add_action`/`add_filter` in class** (FR-014, A1). Locked by CI grep gate (SC-006).
- ✅ **`home_url()`, not `admin_url()`** for `get_base_url()` (FR-006). Page resolves on the front-end where login cookie applies.
- ✅ **Downstream Phase 6 binding** preserves consented server scope (S8, `array{user_id, server_id}` payload). App Password is bound to the server in the transient.
- ✅ **i18n with text domain** (FR-016). All user-facing strings translatable; PHPCS WPCS strict reports zero `WordPress.WP.I18n` violations.
- ✅ **RTL CSS variant registered** via `wp_style_add_data` (FR-013 step 5; clarification 2026-06-25).
- ✅ **`use` imports for cross-namespace classes** (A6). Prevents B1 double-namespace silent failure.
- ✅ **No new REST routes, no new DB tables, no new options** (data-model §1). Attack surface restricted to one URL, one query var.
- ✅ **Generic error messages on `approve_auth_code()` failure** (contracts/page-cli-auth-approve.md). No transient internals, no stack traces.

## Plan-Level Gaps NOT Surfaced By Prior `security-constraints.md` (2026-06-26)

| Gap | Reason missed |
|---|---|
| SEC-001 (server slug spoofing) | Prior reviewer treated `?server=` as "non-secret, attacker-controlled, escaped" — true for XSS but missed the consent-integrity / UI-misrepresentation angle |
| SEC-002 (cross-code nonce replay via leaked HTML) | Prior reviewer accepted research §R1 verbatim without exploring the leaked-HTML side channel |
| SEC-005, SEC-006 (deploy-time hygiene) | Out of "Trust Boundaries / Authorization / Data Isolation" table scope; not run as a checklist |
| SEC-007 (operational invariant assertion) | Test-matrix coverage was not part of the prior review's mandate |

---

## Action Plan & Next Steps

### 1. Durable Memory Preservation (Mandatory Check)

The SEC-001 finding (display-time consent-text MUST match the authoritative transient value, not the URL parameter) is a **reusable security pattern** that applies to any future consent surface in this codebase (OAuth consent already has a similar concern). I recommend executing `/speckit.memory-md.capture` to propose a new Security Constraint entry:

> **S9 — Consent-surface displayed-state MUST be sourced from the server-side authoritative store, not from URL parameters** [Feature-007, 2026-06-30]. URL-supplied consent context (server slug, scope name, requested capability) is attacker-controllable in any deep-link flow. Render the value from the persisted state (transient, option, DB row) keyed by the unforgeable code, not from `$_GET`. Applies to: CLI consent (Phase 7), OAuth consent (Phase 5), any future device-grant surface.

The other findings (SEC-002, SEC-003) are feature-local trade-offs already documented in plan §Complexity Tracking and research.md — no memory capture needed.

### 2. Remediation Planning

No CRITICAL or HIGH findings; remediation is not blocking. SEC-001 (MEDIUM) should be addressed before implementation begins. Recommended next step:

```
/speckit-security-review-followup
```

…to convert SEC-001, SEC-002, SEC-005, SEC-007 into Spec-Kit tasks scoped to this feature. SEC-003 should be referenced but tracked under a separate Phase-6-hardening epic (it cannot be fixed in Phase 7 alone). SEC-004 and SEC-006 can be deferred to security-constraints.md amendments.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-06-30-007-frontend-cli-auth-plan.md | plan | 2026-06-30 | MODERATE | C:0 H:0 M:1 L:3 | A01,A04,A05,A09 |
```
