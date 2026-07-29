---
document_type: security-review
review_type: plan
assessment_date: 2026-07-29
codebase_analyzed: acrossai-mcp-manager (F038 planning artifacts)
total_files_analyzed: 7
total_findings: 5
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 1
informational_count: 4
owasp_categories: [A01, A03]
cwe_ids: [CWE-284, CWE-79, CWE-863]
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

# Security Review — F038 User-Accessible MCP Servers Shortcode (Plan Phase)

## Executive Summary

Feature 038 introduces a **read-only frontend shortcode** `[acrossai_mcp_servers]` plus a reusable data-only abstract base class `AbstractUserServersRenderer`. The feature is **pure composition** on top of shipped, previously-reviewed subsystems (F011 BerlinDB, F015 Access Control v2, F035 ConnectionMethodRegistry, F037 AbstractEmbedTransport). It introduces **zero new persistent storage, zero REST endpoints, zero admin UI, zero JS, and zero forms**.

The intrinsic attack surface is therefore very small. All authorization decisions delegate to shipped upstream helpers whose security posture was evaluated in prior plan/staged reviews (F015 plan LOW, F017 plan HIGH-remediated, F032 plan HIGH-remediated-v2, F037 plan LOW). The plan enforces this delegation with three grep-gates (FR-023 no re-firing `acrossai_mcp_embed_transports`, FR-024 no direct `_embeds_enabled` / `_embeds_clients` meta reads, FR-025 no `public/` → `includes/` back-imports) plus an implicit fourth (FR-023 also covers `acrossai_mcp_client_classes`).

The findings below are entirely **advisory hardening + contract-doc improvements**. No CRITICAL, HIGH, or MODERATE issues surfaced. Overall risk: **LOW**.

## Plan Artifacts Reviewed

| Artifact | Path |
|----------|------|
| Planning brief | `docs/planings-tasks/038-user-accessible-mcp-servers-shortcode.md` |
| Feature spec | `specs/037-user-accessible-mcp-servers-shortcode/spec.md` |
| Implementation plan | `specs/037-user-accessible-mcp-servers-shortcode/plan.md` |
| Phase 0 research | `specs/037-user-accessible-mcp-servers-shortcode/research.md` |
| Phase 1 data model | `specs/037-user-accessible-mcp-servers-shortcode/data-model.md` |
| Contract — Abstract base | `specs/037-user-accessible-mcp-servers-shortcode/contracts/AbstractUserServersRenderer.contract.md` |
| Contract — Concrete block | `specs/037-user-accessible-mcp-servers-shortcode/contracts/UserServersBlock.contract.md` |
| Quickstart / verification | `specs/037-user-accessible-mcp-servers-shortcode/quickstart.md` |
| Memory synthesis | `specs/037-user-accessible-mcp-servers-shortcode/memory-synthesis.md` |

Context also loaded from `docs/memory/INDEX.md`, `.specify/memory/constitution.md`, prior reviews of F015 / F032 / F035 / F037.

## Vulnerability Findings

### SEC-001 (LOW) — `get_accessible_servers( ?int $user_id )` permits cross-user enumeration by consumers without caller-authority gate

- **Finding ID**: SEC-001
- **Location**: `contracts/AbstractUserServersRenderer.contract.md` §Class shape + §Consumer contract; `spec.md` FR-001 / User Story 2 Acceptance Scenario 3
- **OWASP category**: A01:2025 — Broken Access Control
- **CWE**: CWE-863 — Incorrect Authorization
- **CVSS v3.1 base score**: 3.5 (Low) — AV:N / AC:L / PR:L / UI:N / S:U / C:L / I:N / A:N
- **Spec-Kit task**: TASK-SEC-001

The contract intentionally allows `get_accessible_servers( int $some_other_user_id )` so companion plugins (BuddyBoss profile tab, WPUM member page) can render a **target user's** accessible-server list. F015's `user_has_server_access( $user_id, $server_id )` is evaluated **for the target user**, not the caller.

This is by-design for the motivating consumer, but the current contract text ("the gate evaluates for the target user, not the calling user") is a one-liner buried in User Story 2 scenario 3. A companion plugin author who reads only the class contract might legitimately build a widget that lets any logged-in user (including subscribers) view any other user's accessible-server list by manipulating the target user_id — enabling **cross-user enumeration** of which servers each user can reach.

The information disclosed is not per-se sensitive (server slugs are already discoverable via public REST routes), but the **allowed set per user** IS meta-information about access-control rules, and leaking it lets an attacker map out access-control policy without needing admin rights.

**Recommendation** (pre-implementation, not blocking):

Amend the `AbstractUserServersRenderer.contract.md` §Consumer contract with an explicit **"Caller-authority responsibility"** subsection stating:

> When a consumer calls `get_accessible_servers( $target_user_id )` where `$target_user_id !== get_current_user_id()`, the consumer MUST independently verify that the current viewer is authorized to see the target user's information. F038 does NOT gate the caller — it evaluates access control FOR the target user. Typical guards: `current_user_can( 'edit_user', $target_user_id )` for admin views, or a `bp_is_my_profile()` check for BuddyPress profile tabs.

Also add a matching PHPUnit test that asserts the contract text exists in the class docblock (regex on file contents) so refactors cannot silently drop the warning.

**No code change required** — this is a documentation hardening item. F038's intrinsic behavior is correct.

### SEC-002 (INFO) — `acrossai_mcp_servers_shortcode_html` filter output not re-sanitized

- **Finding ID**: SEC-002
- **Location**: `contracts/UserServersBlock.contract.md` §Filter contract; `plan.md` §Summary
- **OWASP category**: A03:2025 — Injection (informational — standard WP filter behavior)
- **CWE**: CWE-79 — Improper Neutralization of Input During Web Page Generation (informational — filter listener injection surface, not F038 injection)
- **CVSS v3.1 base score**: 0.0 (Informational)
- **Spec-Kit task**: TASK-SEC-002

The filter `acrossai_mcp_servers_shortcode_html` receives the rendered HTML and returns whatever a listener plugin wants — F038 does not re-sanitize the returned string. Any registered listener can inject arbitrary HTML, `<script>`, or attribute breakouts. This is **standard WordPress filter behavior** (F037's `acrossai_mcp_embed_render_html` follows the same shape) and cannot be defended against without breaking the extensibility contract.

**Recommendation**: add a one-line disclosure in the filter's contract docblock:

> Note: F038 returns the filter result verbatim without re-escaping. Listener plugins are trusted at the filter boundary — a malicious or buggy listener can introduce XSS. This matches WP's standard filter idiom.

Also add a PHPUnit test asserting a plaintext filter mutation appears unchanged in the output (already planned per contract §Test contract `test_filter_round_trip_html`).

### SEC-003 (INFO) — Icon-URL detection idiom pre-dates PHP 8

- **Finding ID**: SEC-003
- **Location**: `contracts/UserServersBlock.contract.md` §Icon URL detection
- **OWASP category**: N/A (code quality — not a security finding, listed for defense-in-depth review)
- **CWE**: N/A
- **CVSS v3.1 base score**: 0.0 (Informational)
- **Spec-Kit task**: TASK-SEC-003

The contract uses `strpos( $icon, 'http://' ) === 0` for the URL prefix check. Constitution §II mandates PHP 8.1+; the idiomatic modern form is `str_starts_with( $icon, 'http://' )`. Behavior is identical; readability improves. F038's plugin-wide PHP min is 7.4 (per AGENTS.md), which lacks `str_starts_with` — so `strpos` is defensively correct for backward compatibility.

`esc_url()` on the src attribute already strips dangerous schemes (`javascript:`, `data:`) — the prefix check is defense-in-depth. No change required. Flagged only so the implementer is aware of the tradeoff.

### SEC-004 (INFO) — `acrossai_mcp_user_accessible_servers` filter can append gate-bypassing entries

- **Finding ID**: SEC-004
- **Location**: `contracts/AbstractUserServersRenderer.contract.md` §Filter contract
- **OWASP category**: A01:2025 — Broken Access Control (informational — filter power by design)
- **CWE**: CWE-284 — Improper Access Control (informational)
- **CVSS v3.1 base score**: 0.0 (Informational)
- **Spec-Kit task**: TASK-SEC-004

The filter runs **after** the F015 + F037 gate cascade. A buggy or malicious listener could append server entries that the cascade would have denied — bypassing access control for that render. This is by-design (the filter is a mutation seam for consumers who need to reshape the payload for their own context), but the contract should be explicit that:

- The filter is intended for **removing / reshaping** entries, not **appending** entries.
- Consumers who add entries MUST replay the gate cascade for the added entries themselves.

**Recommendation**: update the contract's §Filter contract §Non-goals to add:

> Not a place to bypass the gate cascade by appending entries. F038 does NOT re-verify appended entries against F015 or F037 — a listener that appends servers effectively grants unmediated access. Consumers appending entries MUST call `AcrossAI_MCP_Access_Control::user_has_server_access` + `AbstractEmbedTransport::is_enabled_for_server` themselves for each appended entry.

Also add a PHPUnit test that documents (not enforces) the append-bypass scenario — one test showing a listener appending an unauthorized server and the resulting payload including it. This makes the behavior visible to future maintainers.

### SEC-005 (INFO) — Inline `<style>` block content is static — future dynamic interpolation would require CSS-context escaping

- **Finding ID**: SEC-005
- **Location**: `contracts/UserServersBlock.contract.md` §CSS scope
- **OWASP category**: A03:2025 — Injection (informational — pre-emptive guardrail)
- **CWE**: CWE-79 (informational)
- **CVSS v3.1 base score**: 0.0 (Informational)
- **Spec-Kit task**: TASK-SEC-005

F038's inline `<style>` block content is static — no user-controlled or DTO-sourced value is interpolated into CSS. This is correct. However, the contract doesn't explicitly forbid future dynamic interpolation, and a future maintainer adding e.g. a `<style>.acrossai-mcp-servers[data-theme="{$user_theme}"] { … }</style>` value would introduce a CSS-context injection vector. `esc_html` / `esc_attr` are HTML-context escapers and do NOT neutralize CSS injection.

**Recommendation**: add a one-line invariant to the contract's §CSS scope subsection:

> Invariant: the emitted `<style>` block content is a static CSS literal. No user-supplied, DTO-supplied, or filter-supplied value may be interpolated into it. Future dynamic values (per-shortcode-instance theming, custom brand colors, etc.) MUST be applied via `data-*` attributes on the wrapper `<div>` and CSS attribute selectors — never via string concatenation into the `<style>` block. `esc_html` / `esc_attr` DO NOT escape CSS context (B36-analog pattern for CSS instead of JS).

Aligns with B36 (`wp_json_encode` for JS-context inline scripts) — same principle, different sink.

---

## Confirmed Secure Patterns

The plan already applies the following patterns correctly:

1. **Delegation-not-reimplementation** enforced by FR-023 + FR-024 + FR-025 grep-gates. Prevents drift from B32 (canonical resolver in filter defaults) and inherits F015 / F037's fail-open + memoization semantics.
2. **Escape-at-boundary** discipline mandated at every render seam (FR-014, FR-015) — matches F037's `EmbedBlockRenderer` shape, and F035's DTO-consumer preservation invariant (SEC-035-002).
3. **Anonymous silent no-render** (FR-010) — no information leak about the existence of gated content to logged-out visitors.
4. **Empty-state wrapper distinct from anonymous** — logged-in-with-zero-servers is a real user-facing state; logged-out is a no-user state. Correctly separated (spec.md §Session Q4 + Q5).
5. **Fail-open inheritance from F015** — documented in Edge Case 1 + Quickstart Test 7 (grants operator visibility if the vendor package goes missing). F015 wrapper's admin_notice on `is_available() === false` already surfaces this operationally.
6. **`final class` + private singleton constructor** on `UserServersBlock` (D36 + A2 + S6) — prevents duplicate instantiation + `$style_emitted` fragmentation that would break FR-016.
7. **One-way `public/` → `includes/` dependency** grep-gated by FR-025 — prevents accidental circular imports and preserves the two-layer boundary.
8. **Icon URL scheme allow-list** — only `http://` and `https://` prefixes render as `<img>`, everything else renders as `esc_html` text. `esc_url` provides defense-in-depth against scheme injection.
9. **Filter output type-coercion** — non-array from `acrossai_mcp_user_accessible_servers` coerced to `[]`; non-string from `acrossai_mcp_servers_shortcode_html` coerced via `(string)`. Prevents downstream fatal errors from misbehaving listeners.
10. **Zero direct `$wpdb` calls** — every DB touchpoint routes through a shipped BerlinDB Query / F037 meta reader / F015 vendor manager. Inherits their `$wpdb->prepare()` discipline transitively.

---

## Constitution alignment

| Principle | Plan status |
|-----------|-------------|
| §III Security First | ✅ No forms → S1 N/A. No REST → S2 N/A. No secrets → S3 N/A. No SQL → S4 satisfied via delegation. |
| §V Extensibility Without Core Modification | ✅ Two new filters + subclass extension surface for base. Fail-open cascade preserved. |
| §VI Reusability & DRY | ✅ Zero re-implementation of F015 / F037 helpers — enforced by grep-gates. |
| §VII Definition of Done | ✅ All DoD items present in spec.md and quickstart.md verification. |
| DEC-CLIENT-RENDERER-PUBLIC-API | ✅ Both classes marked `@experimental May change without notice before 1.0.0`. |
| D36 (`final class` for public `@experimental`) | ⚠ Precedent-based deviation on `AbstractUserServersRenderer` (documented in plan.md §Complexity Tracking, matches shipped `AbstractClientRenderer` shape). Not a security finding. |

---

## Recommended Action Plan

1. **Apply SEC-001 documentation** — amend `contracts/AbstractUserServersRenderer.contract.md` with the caller-authority responsibility subsection. Add a PHPUnit assertion that the docblock warning text is present.
2. **Apply SEC-002 documentation** — one-line disclosure in the HTML filter's contract docblock about the un-sanitized return.
3. **Apply SEC-004 documentation** — non-goals paragraph clarifying the filter is not a gate-bypass surface.
4. **Apply SEC-005 documentation** — CSS-static-content invariant in the CSS scope subsection.
5. **Optional SEC-003** — implementer's discretion whether to use `strpos` (7.4-compatible) or `str_starts_with` (8.0+). `strpos` is defensively correct for the AGENTS.md-declared PHP min; keep it.

None of the above block Phase 2 (`/speckit.tasks`). All can be folded into the implementation directly.

## Durable Memory Preservation

No new systemic patterns emerged. F038 exercises existing patterns (D35 delegation, DEC-CLIENT-RENDERER-PUBLIC-API `@experimental`, D36 with documented precedent deviation, S6 singleton, D19 fail-open observability-adjacent). No `/speckit.memory-md.capture` invocation required.

## Next Steps

- Fold SEC-001, SEC-002, SEC-004, SEC-005 documentation updates into the `contracts/` files (5-line-each edits).
- Proceed to `/speckit-architecture-guard-violation-detection` (mandatory next step in governed-plan orchestration).
- No `/speckit-security-review-followup` needed — no CRITICAL or HIGH findings.

---

## Memory Hub INDEX.md row

```text
| docs/security-reviews/2026-07-29-037-user-accessible-mcp-servers-shortcode-plan.md | plan | 2026-07-29 | LOW | C:0 H:0 M:0 L:1 I:4 | A01,A03 |
```
