---
document_type: security-review
review_type: plan
assessment_date: 2026-06-17
codebase_analyzed: acrossai-mcp-manager-new (specs/004-mcp-clients plan artifacts)
total_files_analyzed: 8
total_findings: 4
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 0
informational_count: 4
owasp_categories: [A04]
cwe_ids: [CWE-79, CWE-116, CWE-200, CWE-754]
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

# Security Review — Plan: MCP Client Classes (specs/004-mcp-clients)

**Branch**: `004-mcp-clients` | **Assessment Date**: 2026-06-17

---

## Executive Summary

Phase 4 ships a **pure service layer** with deliberately small attack
surface: 8 PHP files under `includes/MCPClients/` that take a server URL
and an auth token and return a copy-paste configuration snippet. No
WordPress hooks, no database, no HTTP, no rendering, no session/cookie
writes, no deserialization, no privilege boundary of its own.

**Overall risk: LOW.** Zero Critical / High / Medium findings.
Four Informational findings track design choices the implementation
should preserve and contracts the downstream consumer (Phase 2's
`ApplicationPasswords::render_for_server` amendment) must honor.

The plan honors the resolved governance choices from this turn's
`/speckit-architecture-guard-governed-plan`:

- **V1=singleton** — no constructor injection on `Settings`; consumer
  resolves MCPClients via `AbstractMCPClient::get_all_clients()` at
  use-site (parallel to Phase 2 vendor-singleton pattern).
- **V2=redesign** — explicit interface-redesign port; source repo
  consulted for AI-tool envelope shapes only.
- **V3=both** — `get_all_clients()` public static API + file-scan
  internal mechanism. Spec FR-010 amended.

This module **does not carry any active threat from prior phases**.
Phase 2's SEC-001 (Claude Connector Secret plaintext at rest), SEC-002
(`admin_url()` esc_url), and SEC-003 (slug sanitiser) are all unrelated
to this module's surface.

---

## Plan Artifacts Reviewed

| Path | Role |
|---|---|
| `specs/004-mcp-clients/spec.md` | Authoritative requirements (FR-001 … FR-012); 3 clarifications recorded (Q1 server-key derivation, Q2 empty-token placeholder, Q3 V3=both factory mechanism) |
| `specs/004-mcp-clients/plan.md` | Technical context, Constitution Check, project structure, 4 research outputs |
| `specs/004-mcp-clients/research.md` | R1 per-client envelopes · R2 `derive_server_key` matrix · R3 `safe_token` centralization · R4 `get_all_clients` glob implementation |
| `specs/004-mcp-clients/quickstart.md` | 7-step verification walk (file existence → grep gates → PHPUnit → smoke → static analysis) |
| `specs/004-mcp-clients/memory-synthesis.md` | Memory-driven constraint synthesis with A2-vs-FR-009 soft conflict surfaced |
| `specs/004-mcp-clients/security-constraints.md` | Trust boundary table + the same 4 findings restated here in plan-review format |
| `docs/memory/INDEX.md` | Routing map: A1–A10, B1–B8, D1–D10, S1–S6 |
| `.specify/memory/constitution.md` v1.0.0 | Source-of-truth principles |

`.specify/memory/security_constitution.md` does not exist in this
project; security-relevant principles live in `.specify/memory/constitution.md`
§III + the project memory hub.

---

## Findings

### SEC-INFO-001 — Auth token embedded plaintext in returned snippet

- **Severity**: INFORMATIONAL (by-design behavior)
- **Location**: `specs/004-mcp-clients/spec.md` FR-006; `specs/004-mcp-clients/research.md` R1 envelope template (`'WP_API_PASSWORD' => $auth_token`)
- **OWASP Category**: A04:2025 — Insecure Design (informational — design is correct for this auth flow)
- **CWE**: CWE-200 — Exposure of Sensitive Information (informational; not a defect)
- **CVSS v3.1**: 0.0 (informational — intended behavior)
- **Spec-Kit Task**: N/A

**Description**: The returned snippet contains the WordPress Application
Password as plaintext (e.g. `'WP_API_PASSWORD' => 'XXXX YYYY ZZZZ ...'`).
The user copies this string and pastes it into their AI tool's config
file. This is **the intended Application Password user flow**: WP core
generates a fresh password, hashes the database row, returns the
plaintext to the originating user exactly once. The plugin's snippet
builder operates on that one-shot transit string.

**Why this is not a defect**:
1. Constitution §III bullet 7 (OAuth tokens / Application Passwords
   stored hashed) applies to **server-side storage**, which is hashed
   by WP core in `wp_users` meta — out of this module's scope.
2. The token is the **credential the user grants to their AI tool** —
   the user controls both endpoints.
3. The plaintext exists for one trip (generate → paste) by design.

**Module-side hardening already in plan**:
- `redact_token()` helper for log-safe representation; the plan
  explicitly notes log statements MUST use `redact_token($auth_token)`,
  never `$auth_token` directly.
- `safe_token()` is the only path that emits plaintext; renaming would
  make a future audit easier (`safe_token` is the snippet-output side;
  `redact_token` is the logging side).

**Remediation**: None required. Code review during implementation
should reject any new log statement that interpolates `$auth_token`
without `redact_token()` filtering.

---

### SEC-INFO-002 — Snippet output relies on consumer for XSS escaping

- **Severity**: INFORMATIONAL (boundary handoff, not a defect in *this* plan)
- **Location**: `specs/004-mcp-clients/spec.md` §Security Checklist (output escaping is consumer's responsibility); downstream consumer: Phase 2's `admin/Partials/ApplicationPasswords.php::render_for_server` (separate RT-3 amendment, NOT this phase)
- **OWASP Category**: A04:2025 — Insecure Design (boundary contract)
- **CWE**: CWE-79 — Improper Neutralization of Input During Web Page Generation; CWE-116 — Improper Encoding or Escaping of Output
- **CVSS v3.1**: 0.0 (this plan — no XSS surface here; future consumer task)
- **Spec-Kit Task**: TASK-SEC-INFO-002 (to be added to the Phase 2 RT-3 amendment task list, not this phase's tasks.md)

**Description**: This module returns raw `string` or `array` data — no
HTML, no escaping at the module boundary. The consumer (Phase 2's
amended Tokens-tab renderer) must:
- For `array` snippets: serialize via `wp_json_encode()` AND wrap the
  result in HTML-safe markup (`<pre><code>` with `esc_html()` for the
  pretty-printed JSON).
- For `string` snippets (Claude Code CLI install command): wrap with
  `esc_html()` before placing in `<pre>`.

If the consumer skips escaping and places a raw snippet inside HTML,
any user-controlled substring in the server URL (which is admin-only,
but still possible to inject `</script><svg onload=`-style payloads
through `update_server` saves) becomes a stored XSS vector.

**Why this is not THIS plan's defect**:
- This plan's module produces data, not HTML. Output escaping at the
  point of render is a universal WordPress rule (Constitution §III
  bullet 2) — not something this module can or should enforce on
  callers.
- The spec.md Security Checklist explicitly documents the boundary
  contract.

**Mandatory carry-over to Phase 2 RT-3 amendment task list**:
- Add task: `Wrap snippet output with esc_html() + wp_json_encode()
  before HTML emission`
- Add task: `Add grep gate to RT-3 PR — grep -rE 'echo \\$snippet'
  admin/ must return empty`
- Pairs with memory entry **B8** (`// esc_url'd above` pattern
  fragility, 2026-06-17) — same defense-in-depth rule.

---

### SEC-INFO-003 — Placeholder string `(paste generated password here)` is not internationalized

- **Severity**: INFORMATIONAL
- **Location**: `specs/004-mcp-clients/research.md` R3 `safe_token()` returns a literal English string
- **OWASP Category**: N/A (not a security concern)
- **CWE**: N/A
- **CVSS v3.1**: 0.0
- **Spec-Kit Task**: N/A

**Description**: The empty-token placeholder is a literal English
string. Per SC-003, this module's tests run **without bootstrapping
WordPress** to prove FR-008 architectural purity. WP i18n functions
(`__()`, `_e()`) are not available in that test environment, so
internationalizing the placeholder would force a WP bootstrap into the
test harness — defeating the purity test that's a core architectural
guarantee of this module.

**Why this isn't actually a security finding**:
- The placeholder is a developer hint inside a config snippet — never
  rendered as UI text.
- Non-English admins copy the snippet, see the placeholder, replace
  it. The English text doesn't impede the workflow.

**Recommendation**: If i18n becomes a requirement, perform the
substitution at the **consumer boundary** in Phase 2 RT-3 amendment
via `str_replace('(paste generated password here)', __('...', '...'),
$snippet)`. This preserves the module's WP-free test contract while
making the rendered output localized.

---

### SEC-INFO-004 — `get_all_clients()` does not catch developer errors in malformed client files

- **Severity**: INFORMATIONAL (accepted per spec Edge Cases)
- **Location**: `specs/004-mcp-clients/research.md` R4 `get_all_clients()` implementation; `specs/004-mcp-clients/spec.md` Edge Cases
- **OWASP Category**: N/A (not a security concern)
- **CWE**: CWE-754 — Improper Check for Unusual or Exceptional Conditions (informational; intentional)
- **CVSS v3.1**: 0.0
- **Spec-Kit Task**: N/A

**Description**: The `get_all_clients()` glob + `class_exists()` +
`is_subclass_of()` flow does not wrap class loading in `try/catch`. If
a developer adds a class file under `includes/MCPClients/` that
extends `AbstractMCPClient` but doesn't implement an abstract method,
PHP raises a fatal at autoload time when `class_exists()` is evaluated.
That fatal crashes the admin page that triggered the consumer call.

**Why this is intentional**:
- Spec.md Edge Cases explicitly accepts this: "PHP raises a fatal at
  autoload time. This is acceptable — the developer sees the error
  immediately; the production site never serves it because the
  autoloader fails fast."
- Catching the fatal would mask developer errors that should fail
  loud. Silent-pass-through on a broken file produces "where did my
  client go?" debugging hell.

**Threat model**: Self-inflicted only. Cannot be exploited by an
external actor — adding a class file under `includes/MCPClients/`
requires filesystem write access to the plugin directory, which means
the attacker already has code-execution rights and this is the least
of your problems.

**Recommendation**: None. Accept the trade-off.

---

## Confirmed Secure Patterns

This plan **demonstrates** these patterns by design:

1. **No SQL surface** — no `$wpdb`, no `wpdb->prepare()`, no DB writes.
   Module has zero SQL injection risk by construction.
2. **No HTTP surface** — no `wp_remote_get`, no `wp_remote_post`,
   no `curl`, no socket APIs. Module has zero SSRF risk.
3. **No filesystem write** — module only reads (`glob()`,
   `class_exists()` triggers autoload reads). Zero path-traversal-via-
   writes risk.
4. **No deserialization** — module returns native PHP arrays/strings;
   no `unserialize()`, no `maybe_unserialize()`. Zero
   deserialization-RCE risk.
5. **No hooks** — module registers zero `add_action`/`add_filter`.
   FR-008 enforces this via grep gate (`grep -rnE 'add_action|add_filter'
   includes/MCPClients/` must return empty).
6. **No global state** — module classes hold no instance state; every
   method input arrives via parameter, every output returns by value.
   No race conditions, no leaked state between concurrent admin users.
7. **No session/cookie writes** — module has no
   `setcookie`/`header()`/`session_*` calls. Zero session-fixation or
   cookie-injection risk.
8. **No privilege boundary** — module has no admin endpoints, no REST
   routes, no AJAX handlers. The caller's privilege model (Phase 2's
   `manage_options` gate on the Tokens tab) is fully sufficient.
9. **Token redaction helper** — `redact_token()` lives in
   `AbstractMCPClient` so every concrete client has access to it; any
   log statement MUST route through it to prevent token leakage.
10. **Test purity guarantee** — SC-003 mandates tests run without WP
    bootstrap. This is itself a security-relevant invariant: it proves
    the module's purity claim is not a documentation assertion but a
    testable contract.

---

## Action Plan & Next Steps

### Items for this phase's tasks.md (when `/speckit-tasks` runs)

- Add the spec security checklist (8 items) as part of the DoD gate
  task — verify each at implementation time
- Include a code-review reminder task: any new log/debug statement
  involving `$auth_token` MUST use `$this->redact_token($auth_token)`

### Items for the Phase 2 RT-3 amendment task list (NOT this phase)

- **TASK-SEC-INFO-002**: Wrap snippet output with `esc_html()` +
  `wp_json_encode()` before HTML emission in
  `ApplicationPasswords::render_for_server()`
- Grep gate: `grep -rE 'echo \\$snippet' admin/` must return empty
- Optional: i18n substitution of the empty-token placeholder at the
  consumer boundary (SEC-INFO-003)

### Items NOT to do

- **Do not** wrap `get_all_clients()` in `try/catch` — explicitly
  rejected per SEC-INFO-004
- **Do not** internationalize the placeholder inside the module —
  explicitly rejected per SEC-INFO-003
- **Do not** introduce a Registry class — V3=both already gives the
  consumer a clean public API via `AbstractMCPClient::get_all_clients()`

### Durable Memory Preservation

Reviewed for new security patterns or recurring vulnerabilities:

- **Token-redaction-helper pattern** (`redact_token()` for logs +
  `safe_token()` for snippet output) is a project-specific defense
  but Phase 2 already establishes the broader "tokens never logged"
  norm via Application Passwords hashing. The two-helper split is a
  small implementation detail; not durable enough.
- **The boundary-handoff pattern** (this module produces data;
  consumer escapes at render) is well-covered by existing memory B8
  + Constitution §III bullet 2. Capturing it again would duplicate.
- **A11 candidate** (pure service classes exempted from singleton
  rule) is **architectural, not security** — queued for post-
  implementation capture per governance-summary.md recommendation.

**Decision**: I will **not** auto-trigger `/speckit-memory-md-capture`
from this review. The 4 findings here are all by-design or accepted
trade-offs; none introduce a new durable lesson worth elevating from
this report into the memory hub.

### Remediation Planning

**No `/speckit-security-review-followup` needed** — there are no
Critical / High findings. The 4 Informational findings are all
documented in spec.md or accepted as trade-offs.

---

## Memory Hub INDEX.md Row

Proposed routing row to paste into `docs/memory/INDEX.md` under a
`## Security Reviews` section (or append to the existing one started
in Phase 2):

```text
| specs/004-mcp-clients/security-review-plan.md | plan | 2026-06-17 | LOW | C:0 H:0 M:0 L:0 | A04 |
```
