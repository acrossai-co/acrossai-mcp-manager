---
document_type: security-review
review_type: plan
assessment_date: 2026-07-27
codebase_analyzed: acrossai-mcp-manager (F037 — Per-Server Shortcode + Block Embeds Tab)
total_files_analyzed: 8
total_findings: 6
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 2
informational_count: 4
owasp_categories: [A01, A05, A09]
cwe_ids: [CWE-20, CWE-352, CWE-778, CWE-1188]
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

# SECURITY REVIEW REPORT — PLAN: F037 Per-Server Shortcode + Block Embeds Tab

## Executive Summary

**Verdict**: **Approved for `/speckit-tasks`** with 2 LOW findings to fold into spec/contract before task generation and 4 INFORMATIONAL notes to fold into Phase 6 documentation tasks. No CRITICAL, HIGH, or MODERATE findings.

**Feature shape (why the risk is low)**: F037 introduces 4 trust boundaries (admin form input + filter contribution + F035 DTO delegation + F015 access-control integration) but every boundary sits within established plugin patterns — F013 admin-tab hierarchy, D35 canonical-enumeration validation, D36 `final class` policy, DEC-TOOL-SELECTION-PRESENCE-MODEL storage, D28 schema-drift reconciliation, DEV5 hand-rolled form exception, SEC-035-002 escape-at-render preservation invariant. The 3-gate cascade (master toggle → per-transport toggle → F015 access control) fails silently on any miss, producing zero bytes of output — no information leak to unauthorized visitors. Both new observability actions carry no sensitive data (server_id + transport_key + boolean + user_id — no OAuth tokens, no session data). Constitution §III surface: nonce + capability + escape checklists all verified per FR-018 / FR-019 / SEC-035-002 inheritance.

**Novel security surface vs F035**: F035 was pure programmatic API (0 findings above INFO). F037 adds admin form handling + persistent DB writes + frontend rendering + F015 integration — a much larger threat surface, hence the 2 LOW findings (server-scoped nonce hardening + priority-type coercion defense) that F035 didn't need. Both are defense-in-depth improvements over the baseline plan, not currently-broken paths.

**Memory hub context loaded**: `specs/036-shortcode-block-embeds/memory-synthesis.md` (this feature's synthesis with 5 decisions + 5 constraints + 1 deviation + 3 security + 3 bug patterns), `specs/036-shortcode-block-embeds/security-constraints.md` (plan-phase inline stub), `docs/memory/INDEX.md` targeted scan for `security-critical` / `csrf` / `nonce` / `admin-form` / `filter-contract` / `f032-cross-server` tags. No unresolved conflicts.

## Plan Artifacts Reviewed

- `specs/036-shortcode-block-embeds/plan.md`
- `specs/036-shortcode-block-embeds/spec.md` (with 3 Clarifications: Q1 singular keys, Q2 persist-silently+GC, Q3 two granular observability actions)
- `specs/036-shortcode-block-embeds/research.md` (R1 `_doing_it_wrong` version `'0.1.10'`, R2 static per-request cache + `flush_cache()`, R3 fire-after-commit + try/catch per listener, R4 uninstall drops table but keeps column)
- `specs/036-shortcode-block-embeds/data-model.md`
- `specs/036-shortcode-block-embeds/contracts/AbstractEmbedTransport.contract.md`
- `specs/036-shortcode-block-embeds/quickstart.md`
- `specs/036-shortcode-block-embeds/security-constraints.md` (plan-phase inline stub)
- `specs/036-shortcode-block-embeds/memory-synthesis.md`

Constitution + memory hub:
- `.specify/memory/constitution.md` (v1.1.0)
- `docs/memory/INDEX.md` (targeted scan)

---

## Vulnerability Findings

### [LOW] SEC-037-001 — Nonce action string not scoped to `$server_id`

**Location**: `specs/036-shortcode-block-embeds/spec.md` FR-018; `specs/036-shortcode-block-embeds/security-constraints.md` §Boundary 1.

**OWASP Category**: A01:2025-Broken Access Control.

**CWE**: CWE-352 (Cross-Site Request Forgery).

**CVSS Score**: 3.5 (Low — AV:N / AC:H / PR:H / UI:R / S:U / C:L / I:L / A:N — requires admin session to be CSRF-hijacked into POSTing to a specific server-edit page).

**Description**: FR-018 mandates nonce verification on the EmbedsTab save handler but does NOT specify the nonce ACTION string shape. If the action is a shared constant (`'acrossai_mcp_embeds_save'`), a valid nonce generated for the server-edit page of Server A could be replayed against the save handler for Server B — the nonce validates but the resulting write lands on the wrong server. Since `manage_options` is site-wide, this is admin-authorized in practice (no privilege escalation), but per B37 F032 "Cross-Server-Bypass-via-Client-ID-Only" pattern + F032's server-scoped nonce precedent, the correct defense is a `$server_id`-scoped nonce action:

```php
// WEAK (current spec allows this shape)
wp_verify_nonce( $_POST['_wpnonce'], 'acrossai_mcp_embeds_save' )

// STRONG (recommended)
wp_verify_nonce( $_POST['_wpnonce'], 'acrossai_mcp_embeds_save_' . $server_id )
```

**Remediation**: Tighten FR-018 to require the nonce action to be `'acrossai_mcp_embeds_save_' . $server_id` (string concatenation with the target server's ID). Update `contracts/AbstractEmbedTransport.contract.md` to document the nonce action shape (even though the nonce lives in the tab class, not the base). Add a test in `EmbedsTabSaveHandlerTest.php` that generates a nonce for server A + submits it to save handler for server B; assert save is rejected.

**Spec-Kit Task**: `TASK-SEC-037-001` — extend FR-018 to require server-scoped nonce action. Add test coverage for cross-server nonce replay.

---

### [LOW] SEC-037-002 — `get_priority()` return-value type coercion missing in comparator

**Location**: `specs/036-shortcode-block-embeds/contracts/AbstractEmbedTransport.contract.md` §Instance Method Contracts → `get_priority()`; `specs/036-shortcode-block-embeds/data-model.md` §3.

**OWASP Category**: A05:2025-Security Misconfiguration.

**CWE**: CWE-20 (Improper Input Validation) + CWE-1188 (Insecure Default Initialization of Resource).

**CVSS Score**: 3.1 (Low — AV:N / AC:H / PR:H / UI:N / S:U / C:N / I:N / A:L — requires admin-installed malicious companion plugin; DOS on the admin EmbedsTab render page).

**Description**: The contract for `get_priority()` states "Value MUST be an integer. No enforcement at collection time — non-int returns produce PHP type errors at `usort` per PHP 8.1+ strict types." This is deliberately loose, but it means a companion plugin that returns `null` / `false` / a string from `get_priority()` will kill the Embeds tab render with a PHP fatal — admin sees a WSOD or a 500 error. Denial-of-service via well-crafted subclass; admin trust anchor holds but the failure mode is unnecessarily brittle.

`AbstractMCPClient::get_all_registered_clients()` (F034 precedent) has the same shape but F034's built-ins ship with the correct type + third-party contributions are less common. F037's tab is admin-visible on every server-edit page — the failure blast radius is larger.

**Remediation**: Update `get_all_registered_transports()` contract to coerce `get_priority()` return via `(int)` cast inside the `usort` comparator:

```php
usort( $instances, static function ( AbstractEmbedTransport $a, AbstractEmbedTransport $b ): int {
    $pa = (int) $a->get_priority();  // coerce, don't fatal
    $pb = (int) $b->get_priority();
    return ( $pa <=> $pb ) ?: strcmp( $a->get_transport_key(), $b->get_transport_key() );
} );
```

Additionally: add optional `_doing_it_wrong` under `WP_DEBUG` when the returned value fails `is_int()`, so companion-plugin authors see the signal during development.

Retrofit to F034's `get_all_registered_clients()` in a follow-up PR — same defensive coercion applies. Not blocking for F037 shipping.

**Spec-Kit Task**: `TASK-SEC-037-002` — tighten `get_all_registered_transports()` comparator with `(int)` coercion + optional `_doing_it_wrong` under `WP_DEBUG`.

---

### [INFORMATIONAL] SEC-037-003 — Missing checkbox parse rule ambiguity

**Location**: `specs/036-shortcode-block-embeds/spec.md` FR-018; `specs/036-shortcode-block-embeds/plan.md` §Technical Context.

**OWASP Category**: A05:2025-Security Misconfiguration.

**CWE**: CWE-20 (Improper Input Validation).

**Description**: HTML forms don't submit unchecked checkboxes — absence of a checkbox field in `$_POST` MUST be interpreted as "unchecked" per standard convention. Plan security-constraints.md notes this but the FR-018 wording doesn't explicitly document what happens if the save handler receives a POST WITHOUT the master field (e.g., a hijacked form submission stripped of the master field). Two behaviors are possible:
1. Interpret "master field absent" as "master = OFF" (destructive — could zero out state).
2. Reject the entire save as malformed and return the form unchanged (safe — but no admin-visible signal).

Neither is a security bug — the nonce prevents adversary submissions — but the parse rule ambiguity could produce different behavior depending on the implementer's interpretation.

**Remediation** (documentation only): Add a `FR-018a` clause to spec.md:
> Save handler MUST treat absent master OR absent transport checkbox fields as EXPLICIT OFF (`checkbox unchecked`) per HTML form convention. Partial submits (missing fields) MUST proceed as OFF for the missing fields, NOT reject the whole save. This matches WP core `checkbox` handling.

Add a test in `EmbedsTabSaveHandlerTest.php` that submits with master absent + transport present → asserts master goes to 0 + transport row updates correctly.

**Spec-Kit Task**: `TASK-SEC-037-003` — add FR-018a to spec.md documenting the missing-checkbox parse rule; add test case.

---

### [INFORMATIONAL] SEC-037-004 — Dedicated `EmbedBlockRenderer` contract file missing

**Location**: `specs/036-shortcode-block-embeds/contracts/` (directory contains only `AbstractEmbedTransport.contract.md`).

**OWASP Category**: A05:2025-Security Misconfiguration.

**CWE**: CWE-79 (Improper Neutralization of Input During Web Page Generation — documentation-scoped).

**Description**: The contracts/ directory documents `AbstractEmbedTransport` (domain layer) but NOT `EmbedBlockRenderer` (frontend renderer). `EmbedBlockRenderer` is where the SEC-035-002 preservation invariant (DTO string fields are NOT pre-escaped by F035; consumers own render-time escaping) manifests as concrete code. Without a contract file, task-generation may under-specify the escape shape — leading to inconsistent escape function choice (`esc_html` vs `wp_kses_post` vs `esc_attr` per context) at implementation time.

**Remediation** (documentation only): Create `specs/036-shortcode-block-embeds/contracts/EmbedBlockRenderer.contract.md` (analogous to F035's `ConnectionMethodRegistry.contract.md`) documenting:
- Shortcode signature + accepted attributes
- 3-gate cascade order (master → per-transport → F015)
- Escape function per DTO field (`name`, `description`, `icon`, `meta.command_template`, `meta.config_file`, `meta.top_level_key`, `meta.icon_url` — each with the correct escape function)
- Filter hook `acrossai_mcp_embed_render_html` firing timing + expected sanitization by consumers
- `<script>` interpolation policy per B36 (`wp_json_encode()` if JS output added)

**Spec-Kit Task**: `TASK-SEC-037-004` — create dedicated `EmbedBlockRenderer.contract.md` during Phase 1 (design) OR Phase 2 (tasks — before Phase 5 US3 implementation).

---

### [INFORMATIONAL] SEC-037-005 — `$user_id = 0` observability semantic unclear

**Location**: `specs/036-shortcode-block-embeds/data-model.md` §4 Observability action payloads; `specs/036-shortcode-block-embeds/contracts/AbstractEmbedTransport.contract.md` §Observability Actions.

**OWASP Category**: A09:2025-Security Logging and Monitoring Failures.

**CWE**: CWE-778 (Insufficient Logging).

**Description**: Both `acrossai_mcp_embed_master_toggled` and `acrossai_mcp_embed_transport_toggled` actions pass `$user_id = get_current_user_id()` — this returns 0 for anonymous users. Since the surrounding save handler requires `manage_options` cap, `$user_id` should always be > 0 in practice. BUT:
- If a companion plugin calls the save handler from a WP-CLI command context OR a cron-fired process, `$user_id` could be 0.
- Audit-log consumers might interpret `$user_id = 0` as "unknown" — creating an audit-trail gap where the toggle transition is recorded but the actor is not.

**Remediation** (documentation only): Add to the contract file:
> `$user_id = 0` indicates a non-user save context (WP-CLI, cron, WP internal). Audit consumers SHOULD treat 0 as "system context" not "unknown user". The save handler enforces `manage_options` for HTTP request context (`$user_id > 0` guaranteed on admin-form-POST); non-user contexts (WP-CLI) MAY invoke the underlying `set_enabled_for_server()` Query method bypassing the cap check + producing `$user_id = 0` — this is by design for automation but audit consumers should be aware.

Consider also documenting: audit-log plugins with per-user attribution requirements SHOULD reject or annotate `$user_id = 0` events separately from `$user_id > 0` events.

**Spec-Kit Task**: `TASK-SEC-037-005` — expand observability action documentation in `contracts/AbstractEmbedTransport.contract.md` + `data-model.md` + `quickstart.md` §3.

---

### [INFORMATIONAL] SEC-037-006 — Block editor preview gate coverage not documented

**Location**: `specs/036-shortcode-block-embeds/spec.md` §Edge Cases; `specs/036-shortcode-block-embeds/plan.md` §Summary (Phase 2 deferral).

**OWASP Category**: A01:2025-Broken Access Control.

**CWE**: CWE-1188 (Insecure Default Initialization of Resource — future-scope).

**Description**: The plan defers the block-editor block to a Phase 2 follow-up. The spec's Edge Cases section mentions "Shortcode inside a block-editor page rendered in the admin preview: same gate cascade applies (admin preview does NOT bypass F015 or F037 checks)." This is CORRECT for the SHORTCODE side — Gutenberg's shortcode block calls the shortcode's PHP renderer at preview time, which enforces the cascade.

But when Phase 2 lands with a proper block-editor BLOCK (React-rendered client-side), the client-side preview would bypass server-side gates entirely — the block's `<ServerSideRender>` component (or equivalent) MUST route through the server for the actual render. If a naive implementation renders client-side directly, the F015 access control + F037 toggles are silently bypassed at edit time.

**Remediation** (documentation only, Phase 2 concern): Add a note to spec.md § Assumptions or a Phase-2-scope-notes section:
> When the block-editor block ships in Phase 2 (deferred F038 or F037 v2), it MUST use `<ServerSideRender>` (or equivalent server-round-tripping mechanism) for its preview render — client-side-only preview WOULD bypass the F015 access control + F037 toggle gates. Document this constraint in the Phase 2 planning brief.

**Spec-Kit Task**: `TASK-SEC-037-006` — add Phase 2 gate-cascade constraint note to spec.md Assumptions section; carry forward to F038/F037-v2 planning brief.

---

## Confirmed Secure Patterns

Every pattern in the plan is verified as correctly applying an existing durable-memory decision or bug prevention:

### 1. 3-gate cascade fails silently (US3 + SC-008) — CORRECT

Plan: shortcode returns empty string on any gate miss (master OFF, per-transport OFF, F015 denies). Zero information leak — attacker can't distinguish "server doesn't exist" from "you don't have access" from "master toggle off". Matches OAuth security convention + F015 D19 fail-open policy inverted for gate-failure output.

### 2. Presence-model storage per DEC-TOOL-SELECTION-PRESENCE-MODEL — CORRECT

Junction table `wp_acrossai_mcp_server_embed_transports` with `UNIQUE(server_id, transport_key)`. Presence + boolean semantic tracked separately (per data-model.md §2) to distinguish "never touched" from "explicitly disabled" for observability action semantics. Third use of DEC-TOOL-SELECTION-PRESENCE-MODEL after F017/F020.

### 3. D28 3-part contract for both schema changes — CORRECT

Column addition on existing table + new junction table both go through D28 (`$version` bump + `$upgrades` callback + `Main::reconcile_database_schemas()` on `admin_init@3`). Prevents B34 silent-write-loss. Sanity SQL documented for post-release verification.

### 4. `final class` on `EmbedBlockRenderer` + all `AbstractEmbedTransport` subclasses per D36 — CORRECT

Extension via filter (not subclass) per D36 policy. Prevents singleton state fragmentation + delegation invariant defeat. Failure mode of dropping `final` later is non-breaking; adding it later is breaking — err on the side of shipping `final`.

### 5. Fail-open on F015 wrapper absent (D19) — CORRECT

Shortcode gate uses `class_exists( \AcrossAI_MCP_Access_Control::class )` guard — if F015 wrapper absent, gate returns true (fail-open per D19). Consistent with F015 F017 F020 F030 F032 precedent for optional integration.

### 6. `try/catch` per observability listener per R3 — CORRECT

Save handler wraps each `do_action` in `try { ... } catch ( \Throwable $e ) { /* log; don't rethrow */ }`. One misbehaving listener MUST NOT break others OR roll back the DB write. Matches F015 D19 fail-forward pattern.

### 7. TINYINT string-return defense per B18 — CORRECT

Both `wp_acrossai_mcp_servers.embeds_enabled` and `wp_acrossai_mcp_server_embed_transports.is_enabled` TINYINT reads MUST cast to `(int)` before strict compare. Documented in security-constraints.md + data-model.md §1 + memory-synthesis.md.

### 8. BerlinDB `modified` flag per B21 — CORRECT

`date_modified` column uses `'flags' => ['modified']` NOT `'date_updated'`. Grep gate documented: `grep -rn "'date_updated'" includes/Database/ServerEmbedTransports/` MUST return zero hits.

### 9. Grep gates spelled out per B26 — CORRECT

SC-005 (delegation) + SC-006 (one-way layering) grep gates use exact directory allow-lists — plan Constraints section spells out exact commands. Prevents B26 "grep gate hard-codes allow-list silently skips new layer" pattern.

### 10. BerlinDB Query methods enforce `$wpdb->prepare` — CORRECT

All DB writes go through BerlinDB Query class methods (`set_enabled_for_server`, `delete_by_server_id`, etc.) — no `$wpdb->update` with `$_POST` array. Prevents mass-assignment attacks (B7).

### 11. Two-check cascade in `is_enabled_for_server()` — CORRECT

Master gate READ first (`wp_acrossai_mcp_servers.embeds_enabled`); short-circuit on 0. Only reads the junction table when master is ON. Prevents unnecessary I/O on the common-case fresh-install (master OFF for every server).

### 12. Nonce + capability enforced on save handler (FR-018) — CORRECT

Standard S1 + S3 compliance. Complements SEC-037-001's LOW recommendation to make the nonce action server-scoped for defense-in-depth.

---

## Action Plan & Next Steps

1. **Fold SEC-037-001 (LOW) into spec + contract**: Tighten FR-018 nonce action shape. Small change — recommended before `/speckit-tasks`.
2. **Fold SEC-037-002 (LOW) into contract**: Add `(int)` coercion to `get_all_registered_transports()` comparator + optional `_doing_it_wrong` under `WP_DEBUG`. Consider follow-up PR retrofitting F034's `get_all_registered_clients()` with the same defense.
3. **Fold SEC-037-003 / 004 / 005 / 006 (INFORMATIONAL) into Phase 6 documentation tasks**: Queue as `TASK-SEC-037-003..006` during `/speckit-tasks`. All are documentation-only edits (spec clause, dedicated contract file, expanded observability docs, Phase-2 scope note).
4. **No `/speckit-security-review-followup` needed**: overall risk LOW, no CRITICAL or HIGH findings. All 6 findings are self-contained + can be addressed in Phase 6 (or Phase 1 for SEC-037-001/002).
5. **Durable Memory Preservation check**: No new systemic vulnerabilities or reusable security patterns identified in this review. SEC-037-001 (server-scoped nonce) applies B37 F032 pattern; SEC-037-002 (comparator type coercion) is a defensive-coding refinement to D35 pattern that could retrofit F034 in a follow-up PR — but not a new pattern per se. Capture pass NOT triggered.
6. **Recommended re-review**: Re-run `/speckit-security-review-staged` after implementation lands to catch any drift between plan and code. F037's threat model expects zero staged-review findings above INFO; a MODERATE or HIGH from staged review would indicate an unplanned surface was added.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-27-036-shortcode-block-embeds-plan.md | plan | 2026-07-27 | LOW | C:0 H:0 M:0 L:2 I:4 | A01,A05,A09 |
```
