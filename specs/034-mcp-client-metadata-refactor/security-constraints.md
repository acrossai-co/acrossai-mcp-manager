---
document_type: security-review
review_type: plan
assessment_date: 2026-07-25
codebase_analyzed: acrossai-mcp-manager (F034 branch 034-mcp-client-metadata-refactor)
total_files_analyzed: 5
total_findings: 1
overall_risk: INFORMATIONAL
critical_count: 0
high_count: 0
medium_count: 0
low_count: 0
informational_count: 1
owasp_categories: [A03]
cwe_ids: [CWE-79]
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

# Security Review — Plan: F034 MCP Client Metadata + Filter-Aware Enumeration Refactor

## Executive Summary

F034 is a **purely internal architectural refactor** with a **near-zero net change to the plugin's security surface**. The plan preserves every existing security boundary verbatim: no new user input, no new forms, no AJAX, no REST routes, no DB queries, no token/secret handling, no file uploads, no capability gates removed. The `acrossai_mcp_client_classes` filter contract is preserved with the exact SEC-013-008 validation semantic (invalid FQNs silently skipped) that has protected the client-subsystem extension seam since F013.

**One informational finding** (SEC-034-001) is raised — not a vulnerability, but a **change in trust posture** that the plan should call out explicitly so implementers don't inadvertently relax escaping. Pre-refactor, all values consumed by `MCPClientsBlock`'s render helpers came from a plugin-controlled private constant (`CLIENT_META`, plugin-authored, static). Post-refactor, the same values can come from any third-party `AbstractMCPClient` subclass registered via the extension filter. Both regimes require the same `esc_*` escaping at render, but the potential input source is broader post-refactor, so preserving the existing escaping is now **doubly necessary** (was necessary against internal bugs; now necessary against third-party subclass returns).

**Overall risk**: `INFORMATIONAL`. No blocking issues. Proceed to implementation.

## Plan Artifacts Reviewed

- `specs/034-mcp-client-metadata-refactor/spec.md` — 18 FRs, 3 user stories, security checklist marked all-N/A with justifications, Clarifications resolved sort-order + `_doing_it_wrong` version tag
- `specs/034-mcp-client-metadata-refactor/plan.md` — Constitution Check §III explicitly passes with rationale; all 7 principles clean
- `specs/034-mcp-client-metadata-refactor/research.md` — two test-layer decisions (organization + regression strategy)
- `specs/034-mcp-client-metadata-refactor/data-model.md` — post-refactor class-contract model; no persistent state
- `specs/034-mcp-client-metadata-refactor/contracts/AbstractMCPClient.contract.md` — normative contract for third-party subclass authors
- `specs/034-mcp-client-metadata-refactor/quickstart.md` — third-party developer walkthrough
- `specs/034-mcp-client-metadata-refactor/memory-synthesis.md` — SEC-013-008 explicitly preserved; no memory conflicts

**Constitution §III (Security First — NON-NEGOTIABLE)** cross-referenced: all applicable checklist items marked N/A or preserved-verbatim in spec Security Checklist. Consent-surface exception (Feature-007) is not invoked — F034 exposes no consent surface.

## Vulnerability Findings

### SEC-034-001 — Renderer escaping is now doubly-necessary: preserve `esc_*` on all metadata read-back paths

- **Severity**: INFORMATIONAL
- **Location**: `public/Renderers/MCPClientsBlock.php` (render helpers below `render_body()`, called on values sourced from `AbstractMCPClient::get_all_registered_clients()` post-refactor)
- **OWASP**: A03:2025 — Injection
- **CWE**: CWE-79 (Improper Neutralization of Input During Web Page Generation — "Cross-site Scripting")
- **CVSS v3.1**: 0.0 (no exploitable path in the plan as designed; raised as informational to lock in the invariant)
- **Spec-Kit task**: TASK-SEC-034-001 (recommended addition to `tasks.md` as a preservation-invariant check)

**Description**

Pre-refactor, `MCPClientsBlock::CLIENT_META` is a private const on the Renderer — its values are plugin-authored trusted strings. Post-refactor, the same values (icon, description, config_file, top_level_key, instructions) can be returned by any third-party `AbstractMCPClient` subclass registered via `acrossai_mcp_client_classes`. Third-party subclasses are trusted at the "site admin installed the plugin" level — the same trust level as the base plugin's own classes — but they are still a broader potential input source than a plugin-owned const.

The current `MCPClientsBlock` render helpers already escape metadata values at output per Constitution §III ("All output MUST be escaped at the point of rendering"). PHPCS enforces this via the WPCS `WordPress.Security.EscapeOutput` sniff. The refactor MUST NOT remove or relax any existing `esc_html()` / `esc_attr()` / `esc_url()` call on values now sourced from `$client->get_icon()`, `$client->get_description()`, `$client->get_config_file()`, `$client->get_top_level_key()`, or `$client->get_instructions()`.

**Impact**

If a future refactor within F034's scope (or a downstream refactor consuming F036) removes the escaping around metadata read-back paths, a malicious or buggy third-party subclass returning attacker-controllable strings (e.g. `get_description()` reading from a WP option the plugin's admin form later exposes to less-privileged users) would produce stored XSS on the Clients tab.

Note this is **not exploitable in the plan as designed** — the plan explicitly requires "byte-identical rendered HTML for all eight built-in clients" (FR-016), which is a de-facto guarantee that the escaping calls stay in place, and PHPCS would fail the Definition of Done gate if `esc_*` calls were removed. The finding raises this to informational visibility so it's captured in `tasks.md` as an explicit preservation-invariant task.

**Remediation**

- Add a task in `/speckit-tasks` phase: **TASK-SEC-034-001 — Preservation invariant: no `esc_*` call in `MCPClientsBlock` render helpers may be removed or relaxed by TASK-4** (per DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT-AND-COVERAGE-MATRIX pattern from F025).
- Grep gate (add to Manual Verification Checklist): `git diff main -- public/Renderers/MCPClientsBlock.php | grep -E '^-\s*esc_(html|attr|url|js|textarea|kses)'` MUST return zero lines (no deleted escaping calls).
- Optional hardening: introduce a test in `MCPClientsBlockRenderTest.php` that registers a fake `AbstractMCPClient` subclass returning `'<script>alert(1)</script>'` from `get_description()` and asserts the rendered DOM contains `&lt;script&gt;alert(1)&lt;/script&gt;` (not the raw tag). Not required for the informational rating, but would provide an automated regression signal.

## Confirmed Secure Patterns

The following existing patterns are preserved verbatim by the F034 plan and remain in force:

| Pattern | Source | F034 status |
|---|---|---|
| **SEC-013-008 — Invalid FQNs in `acrossai_mcp_client_classes` filter silently skipped** | Original client-subsystem contract (pre-F013) | **Preserved.** Plan FR-007 explicitly reproduces `is_string` + `class_exists` + `is_subclass_of` validation with silent-skip semantic. Data-model §"Canonical enumeration method" step 2 restates it verbatim. |
| **Constitution §III — `permission_callback` on every REST route** | Constitution v1.1.0 §III | **N/A / preserved.** F034 adds zero REST routes. No routes touched. |
| **Constitution §III — Nonce verification on forms and AJAX handlers** | Constitution v1.1.0 §III | **N/A / preserved.** F034 adds zero forms and zero AJAX handlers. |
| **Constitution §III — Capability check on admin actions** | Constitution v1.1.0 §III | **N/A / preserved.** F034 adds zero admin actions. Existing Clients tab render is gated by `AbstractClientRenderer::render(server_id, context)` which reads `context['cap']` (default `manage_options`) at `public/Renderers/AbstractClientRenderer.php`. |
| **Constitution §III — Output escaping at point of render** | Constitution v1.1.0 §III | **Preserved with elevated diligence.** See SEC-034-001 above — escaping calls in `MCPClientsBlock` render helpers MUST NOT be removed by TASK-4. |
| **Constitution §III — `$wpdb->prepare()` on all queries** | Constitution v1.1.0 §III | **N/A / preserved.** F034 adds zero SQL queries. |
| **Constitution §III — Hashed OAuth tokens (SHA-256 minimum)** | Constitution v1.1.0 §III | **N/A / preserved.** F034 touches no token storage. |
| **Constitution §III — File upload validation** | Constitution v1.1.0 §III | **N/A / preserved.** F034 accepts no file uploads. |
| **B32 — Filter defaults MUST express the plugin's canonical semantic** | `docs/memory/BUGS.md` B32 | **Directly implemented.** F034 IS the application of this pattern to the MCP client subsystem — one canonical enumeration path where three previously disagreed. |
| **B40 — Wrapper closures MUST forward args and preserve `WP_Error`** | `docs/memory/BUGS.md` B40 (F033) | **N/A — no wrapper closures in F034.** No permission_callback wrapping. Not applicable. |
| **A1 — Hook registration lives in `Main.php` only** | `docs/memory/ARCHITECTURE.md` A1 | **Preserved.** F034 fires an existing filter but registers no new hooks. |
| **A11 — Pure service class exemption from A2 singleton rule** | `docs/memory/ARCHITECTURE.md` A11 | **Preserved.** `AbstractMCPClient` remains stateless; all six new methods are pure (no state, no side effects except the `apply_filters` call which is the intended extension seam). |
| **DEC-CLIENT-RENDERER-PUBLIC-API** | `docs/memory/DECISIONS.md` | **Preserved.** `MCPClientsBlock` remains `@experimental`; `instance()` + `slug()` public API unchanged; refactor stays inside the class's contract. |

## Trust Boundaries & Threat Assumptions

**Actors in scope**:

- **Site administrator** (trusted) — installs the base plugin + optional companion plugins. Interacts with the Clients tab via `manage_options` capability. Trusted to only install companion plugins they trust.
- **Companion-plugin developer** (semi-trusted — same trust level as base plugin) — writes an `AbstractMCPClient` subclass. Can return arbitrary strings from getter methods; the site admin's decision to install the plugin is the trust anchor.
- **MCP client applications** (Claude Desktop, Cursor, etc.) — cannot reach the client subsystem admin surface. Consume the config snippets the subsystem generates. Not a threat actor for F034.
- **Anonymous visitors / logged-in non-admins** — cannot reach the Clients tab (capability check enforced at the `AbstractClientRenderer` layer). Not a threat actor.

**Threat assumptions**:

- Companion plugins are trusted at "installed by admin" level — same trust anchor as base plugin code. F034 does NOT assume companion plugins are hostile; it does assume they may have bugs, and the plan's validation semantic (invalid FQNs, bad slugs, duplicate slugs) protects against those bug classes.
- Third-party subclasses execute in the base plugin's PHP context. This is unavoidable in a `class_exists`/`instanceof`/`new` pattern and is inherited from the pre-refactor filter contract. Not a regression.
- WP_DEBUG-gated `_doing_it_wrong` output is developer-facing (not user-facing). Any information disclosure via `_doing_it_wrong` messages is bounded to the developer's own `debug.log`. Following WP core convention.

## Action Plan & Next Steps

1. **Add TASK-SEC-034-001 to `tasks.md`** during `/speckit-tasks` phase — the escaping-preservation-invariant check (see SEC-034-001 §Remediation). Explicitly cite this finding.
2. **No `/speckit-security-review-followup` invocation needed** — no critical or high findings. The single informational finding is a design-time note, not a remediation task.
3. **No `/speckit-memory-md-capture` invocation needed** — no systemic vulnerabilities or reusable security patterns identified. The preservation invariant is a per-feature task-level concern, not a durable pattern (the pattern it references — "output escaping at point of render" — is already codified in Constitution §III).

Proceed to `/speckit-architecture-guard-violation-detection` (Step 5 of the governed-plan flow) with a clean bill of health from Security Review.

---

## Memory Hub INDEX.md Row

```text
| specs/034-mcp-client-metadata-refactor/security-constraints.md | plan | 2026-07-25 | INFORMATIONAL | C:0 H:0 M:0 L:0 | A03 |
```
