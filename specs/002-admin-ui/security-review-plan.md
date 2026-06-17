---
document_type: security-review
review_type: plan
assessment_date: 2026-06-17
codebase_analyzed: acrossai-mcp-manager-new (specs/002-admin-ui plan artifacts)
total_files_analyzed: 12
total_findings: 4
overall_risk: MODERATE
critical_count: 0
high_count: 0
medium_count: 2
low_count: 1
informational_count: 1
owasp_categories: [A02, A03, A04]
cwe_ids: [CWE-20, CWE-79, CWE-116, CWE-312]
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

# Security Review — Plan: Admin UI (specs/002-admin-ui)

**Branch**: `002-admin-ui` | **Assessment Date**: 2026-06-17

---

## Executive Summary

The Phase 2 plan migrates six admin-facing PHP classes from the source
`src/Admin/` directory into the new `admin/Partials/` namespace and rewires
every hook through the Phase 1 Loader. The plan is **a faithful 1:1 port**
(per spec Q1 clarification), so its security posture is inherited from the
source repo plus the four mandated changes (namespace, no constructor hooks,
BerlinDB Query swap, sanitiser audit).

**Overall risk: MODERATE.** Zero Critical / High findings. Two Medium-severity
advisories track behaviour preserved from the source repo (plaintext at-rest
storage of the Claude Connector client secret; the `admin_url()` /
`esc_url()` wrapping requirement that must be enforced by a tasks-phase
verification gate). One Low and one Informational round out the list.

The plan also documents a **P0 cross-phase dependency** (the BerlinDB
`Includes\Database\MCPServer\Query` and `CliAuthLog\Query` classes do not
yet exist) — this is a coordination blocker tracked as task T000 in the
plan, not a security defect, and is excluded from the finding count.

No **Security-Architecture Conflict** detected against the Constitution
or active memory. The plan can be implemented without introducing any
ambiguous security decisions later.

---

## Plan Artifacts Reviewed

| Path | Role |
|---|---|
| `specs/002-admin-ui/spec.md` | Authoritative requirements (FR-001 … FR-024 + FR-007a) |
| `specs/002-admin-ui/plan.md` | Technical context, structure, Constitution Check |
| `specs/002-admin-ui/research.md` | R1 BerlinDB map · R2 singleton retrofit · R3 admin-ajax · R4 access-control resolution · R5 screen whitelist |
| `specs/002-admin-ui/data-model.md` | Four entities (MCP Server row, CLI Auth Log entry, App Password, dismissal flag) |
| `specs/002-admin-ui/contracts/loader-wiring.md` | Authoritative body of `define_admin_hooks()` |
| `specs/002-admin-ui/contracts/notice-dismissal.md` | JS↔PHP admin-ajax contract for the dismissible notice |
| `specs/002-admin-ui/quickstart.md` | 8-step manual verification walk + static checks |
| `specs/002-admin-ui/security-constraints.md` | Trust boundary / data isolation / async-path table (inline review output) |
| `specs/002-admin-ui/memory-synthesis.md` | Memory-driven constraint synthesis (markdown-only fallback) |
| `docs/memory/INDEX.md` | Routing map to durable memory (D1–D8, A1–A8, B1–B6, S1–S6, DEV1–DEV2) |
| `.specify/memory/constitution.md` v1.0.0 | Source-of-truth principles |
| `.github/copilot-instructions.md` | Agent context pointer (now updated to this plan) |

`.specify/memory/security_constitution.md` was not present and was not
required for this review — the security-relevant principles live in
`.specify/memory/constitution.md` §III and are echoed in `docs/memory/`
entries S1–S6.

---

## Findings

### SEC-001 — Claude Connector Client Secret stored plaintext at rest

- **Severity**: MODERATE
- **Location**: `specs/002-admin-ui/spec.md` FR-012; `specs/002-admin-ui/data-model.md` Entity E1 row `claude_oauth_client_secret`
- **OWASP**: A02:2025 — Cryptographic Failures
- **CWE**: CWE-312 — Cleartext Storage of Sensitive Information
- **CVSS v3.1**: 5.3 (AV:N/AC:L/PR:H/UI:N/S:U/C:H/I:N/A:N) — requires
  prior privileged access (DB or admin); exposure of the secret enables
  outbound OAuth impersonation but no immediate site compromise
- **Spec-Kit Task**: TASK-SEC-001

**Description**: The Claude Connector OAuth Client Secret is the outbound
credential the plugin presents to Claude on behalf of the configured
server. The plan persists it as a plain VARCHAR column
(`claude_oauth_client_secret`) per the source repo's behaviour, and
re-renders it as masked dots on the edit form. There is no at-rest
encryption layer.

**Why it's not a Critical / High in this plan**:
- The Constitution §III bullet 7 ("OAuth tokens and Application
  Passwords MUST be stored hashed") targets **inbound** credentials
  (tokens we receive); Client Secrets are **outbound** credentials we
  present, so a hash isn't usable (we need to send the cleartext).
- BerlinDB writes use `$wpdb->prepare()` (S4) → no SQL injection vector
- `manage_options` capability is required to view or write the value
  (S1, US3 capability gates)
- Mask-on-render avoids casual exposure via the admin form (FR-012)

**Recommended remediation**:
1. **Phase 2 (this phase)**: Preserve current behaviour per the 1:1 port
   decision. Add a tracking note in `data-model.md` that flags
   `claude_oauth_client_secret` as a candidate for at-rest encryption
   in a follow-up phase.
2. **Phase 6 (Claude Connectors OAuth)** or follow-up: Wrap with
   WordPress-key-derived encryption — e.g. AES-256-GCM keyed off
   `wp_salt('auth')` and a per-row IV — before persistence; decrypt at
   the outbound-OAuth-request boundary only.
3. **Constitution amendment** (recommended): Extend §III bullet 7 to
   cover outbound client secrets explicitly so future reviewers don't
   need to re-derive the distinction.

---

### SEC-002 — Missing explicit `esc_url(admin_url())` enforcement in plan

- **Severity**: MODERATE
- **Location**: `specs/002-admin-ui/spec.md` FR-003 plugin-action-link;
  `specs/002-admin-ui/contracts/loader-wiring.md` (no enforcement clause)
- **OWASP**: A03:2025 — Injection (XSS subtype)
- **CWE**: CWE-79 — Cross-site Scripting; CWE-116 — Improper Encoding/Escaping
- **CVSS v3.1**: 5.4 (AV:N/AC:L/PR:H/UI:R/S:C/C:L/I:L/A:N) — requires
  prior privileged hook abuse; impact reflected XSS in admin context
- **Spec-Kit Task**: TASK-SEC-002

**Description**: Durable memory B6 (`admin_url()` is filterable via the
`admin_url` filter → unescaped output is an XSS vector) and S5
(`admin_url()` MUST be wrapped with `esc_url()` before HTML use) document
a recurring failure pattern. The plan and contracts reference `admin_url()`
multiple times (FR-003 plugin action link; row-action "Edit" / "Toggle
Status" / "Delete" links; the Settings link in plugin-row actions) but do
**not** explicitly mandate the `esc_url()` wrap at each call site.

The 1:1 port from `src/Admin/Settings.php` will carry over whatever
escaping the source has — which in places is `admin_url()` without
`esc_url()` (the source predates the B6 finding).

**Why it's not a Critical / High in this plan**:
- Plan-level finding (not yet code) — no live vulnerability
- The grep verification proposed in `governance-summary.md` Recommended
  Actions #2 catches the issue at merge time
- Exploitation requires another plugin / theme to have already hooked
  `admin_url` filter to inject — high prerequisite

**Recommended remediation**:
1. **Tasks phase MUST add the verification gate** as the first
   security-related task (TASK-SEC-002):
   ```bash
   grep -rn 'admin_url' admin/ | grep -v 'esc_url\|esc_attr'
   # Expected: empty
   ```
   Tag this as a required DoD check.
2. The `contracts/loader-wiring.md` already uses
   `ACROSSAI_MCP_MANAGER_PLUGIN_BASENAME` constant for the plugin action
   link filter name — no risk there. The risk is in the **callback body**
   (`Menu::plugin_action_links()`) where the "Settings" anchor is built.
   That body MUST construct the href as
   `'<a href="' . esc_url( admin_url( 'admin.php?page=acrossai_mcp_manager' ) ) . '">…</a>'`.

---

### SEC-003 — Slug field uses `sanitize_text_field()` instead of stricter `sanitize_key()`

- **Severity**: LOW
- **Location**: `specs/002-admin-ui/spec.md` FR-007a (create handler);
  `specs/002-admin-ui/spec.md` FR-009 (General-tab save sanitisers list)
- **OWASP**: A04:2025 — Insecure Design (mild)
- **CWE**: CWE-20 — Improper Input Validation
- **CVSS v3.1**: 3.1 (AV:N/AC:H/PR:H/UI:N/S:U/C:N/I:L/A:N)
- **Spec-Kit Task**: TASK-SEC-003

**Description**: The MCP server `slug` field is the URL-safe identifier
used to route requests and disambiguate rows. The plan sanitises it with
`sanitize_text_field()`, which permits spaces, mixed case, and most
Unicode — values that other code paths might normalise differently
(e.g. via `sanitize_title()` on a generated URL) leading to silent
collisions or unexpected matching.

**Why it's only Low**: The DB enforces uniqueness on the literal-byte
slug, so two rows with `"TestSlug"` and `"testslug"` coexist without
DB error. Any downstream code that compares slugs case-insensitively
would treat them as the same row → potential confusion but no direct
security impact.

**Recommended remediation**:
1. **Phase 2 (this phase)**: Preserve source behaviour per the 1:1 port
   decision; document the inconsistency risk in `data-model.md`.
2. **Follow-up phase**: Switch to `sanitize_key()` for slug input and
   add an idempotent migration to normalise existing rows.

---

### SEC-INFO-004 — Plan style choice: leading-`\` FQN vs `use` imports

- **Severity**: INFORMATIONAL
- **Location**: `specs/002-admin-ui/contracts/loader-wiring.md` body
- **OWASP**: N/A — style/readability concern
- **CWE**: N/A
- **CVSS v3.1**: 0.0
- **Spec-Kit Task**: N/A

**Description**: Memory entry A6 (and historical bug B1) warn that bare
relative class references inside `AcrossAI_MCP_Manager\Includes\*` files
silently resolve to wrong FQNs (e.g. `Admin\Partials\Settings` →
`Includes\Admin\Partials\Settings`). The plan's `loader-wiring.md`
mitigates this with leading-`\` FQN (`\AcrossAI_MCP_Manager\Admin\…`),
which is safe.

A6 also prefers `use` imports as the readable form. Both styles satisfy
the rule — this is a style heads-up, not a defect. Implementer may
switch to `use` imports at file top.

---

## Confirmed Secure Patterns

The plan **explicitly preserves or strengthens** these patterns:

1. **Nonce enforcement** — Every state-changing handler (toggle, delete,
   bulk action, four-tab save, create form, notice-dismiss admin-ajax)
   verifies a unique nonce action via `check_admin_referer()` or
   `check_ajax_referer()`. (FR-007, FR-007a, FR-013, FR-015, S1)
2. **Capability gate** — `current_user_can('manage_options')` checked at
   every state-changing handler **and** at every render boundary; menu
   items not rendered to non-admins. (US2.5, US3.7, SC-003, US1.5)
3. **Input sanitisation at the boundary** — Per-field most-specific
   sanitiser map (FR-009, FR-012); IDs through `absint()`; URLs through
   `esc_url_raw()`.
4. **Output escaping at point of render** — Notice render uses
   `printf` with `esc_attr()` on the nonce attribute and `esc_html__()`
   for the message body (contracts/notice-dismissal.md).
5. **Prepared statements** — All DB access through BerlinDB Query which
   uses `$wpdb->prepare()` internally; **zero raw `$wpdb->query()`** in
   the planned code. (FR-022, S4)
6. **Hashed Application Password storage** — Tokens tab preserves the
   source `WP_Application_Passwords::create_new_application_password()`
   contract: hash stored, plaintext returned to caller once and never
   persisted. (S3, Constitution §III bullet 7)
7. **Optional-integration class_exists() guards** — `wpb-access-control`
   and `\WP\MCP\Plugin` are guarded at every call site (US1.2/1.3, US3.4/3.5,
   FR-011, FR-015). Plugin degrades gracefully when either is absent.
8. **Per-user notice dismissal** — Q3 clarification 2026-06-17:
   dismissal is per-user via `user_meta`, never per-site or per-role.
   Defense in depth: dismissal endpoint requires nonce **and**
   `manage_options` even though non-admins never see the notice.
9. **Singleton private constructor** — Memory B5 / S6 explicitly
   honoured; plan-time Q1 reconciliation rejected the user's
   constructor-injection sketch and routed every partial through the
   Constitution-mandated `instance()` + private ctor pattern.
10. **Admin-asset enqueue guard** — `get_current_screen()` whitelist
    (R5) means non-plugin admin pages don't load this plugin's JS/CSS,
    reducing the attack surface for any future bundle XSS.

---

## Action Plan & Next Steps

### Mandatory Tasks Phase Additions

| Task ID | Action |
|---|---|
| **T000** | Verify `Includes\Database\MCPServer\Query` and `CliAuthLog\Query` exist (P0 dependency from plan) |
| **TASK-SEC-002** | Add grep gate to DoD: `grep -rn 'admin_url' admin/ \| grep -v 'esc_url\|esc_attr'` → expected empty |
| **TASK-SEC-001** | Add a tracking note in `data-model.md` flagging `claude_oauth_client_secret` for at-rest encryption in a follow-up phase |
| **TASK-SEC-003** | Add a tracking note in `data-model.md` flagging the slug sanitiser scope for a follow-up phase |

### Memory Preservation

**Reviewed for new patterns**: The findings here surface no novel
architectural pattern or decision — they all map cleanly to existing
memory entries (S5/B6, S3 extended, S1, A2). The only candidate worth
considering is a new memory entry recording the **outbound-vs-inbound
credential storage distinction** (motivated by SEC-001). That would be
genuinely durable, project-relevant guidance.

**Decision**: I will propose this as a memory candidate but not
auto-trigger `/speckit-memory-md-capture` yet — the distinction is
worth capturing only after a Phase 6 implementation actually exercises
the at-rest-encryption decision. Today the source repo's plaintext
behaviour is grandfathered in by Q1's 1:1 port — capturing the
"outbound secrets follow rule X" memory before rule X is decided would
be premature.

### Remediation Follow-up

**No `/speckit-security-review-followup` needed**: there are no
Critical / High findings. The two Medium findings are advisory and
captured as tasks above. The Low and Informational findings are
documented in `data-model.md` for future consideration.

---

## Memory Hub INDEX.md Row

Proposed routing row to paste into `docs/memory/INDEX.md` — add under
a new `## Security Reviews` table (or append to an existing one):

```text
| specs/002-admin-ui/security-review-plan.md | plan | 2026-06-17 | MODERATE | C:0 H:0 M:2 L:1 | A02,A03,A04 |
```
