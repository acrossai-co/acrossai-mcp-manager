---
document_type: security-review
review_type: plan
assessment_date: 2026-07-07
codebase_analyzed: acrossai-mcp-manager (Feature 016 plan)
total_files_analyzed: 7
total_findings: 4
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 1
informational_count: 3
owasp_categories: [A02, A09]
cwe_ids: [CWE-212, CWE-778]
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

# Security Review — Feature 016 Plan (Remove Claude Connectors)

## Executive Summary

Feature 016 is a subtractive-edit retirement of the entire Claude Connectors integration and its supporting OAuth infrastructure. **The net effect is an unambiguous reduction of attack surface**: one REST route retired, one `determine_current_user` filter retired, two OAuth `.well-known` discovery endpoints retired, one daily cron retired, one bearer-token acceptance path retired, and ~4,000 lines of security-sensitive code removed from the plugin's hot request path.

**No CRITICAL / HIGH / MEDIUM findings.** The four LOW/INFORMATIONAL findings below are advisory follow-ups — none block implementation. They surface issues that are best solved via release notes, memory-hygiene annotations, or operator-facing documentation rather than plan-level redesign.

The plan explicitly honors the plugin's most important security-relevant durable-memory invariant — `DEC-UNINSTALL-OPT-IN-GATE` — by placing every destructive uninstall statement AFTER the `acrossai_mcp_uninstall_delete_data !== 1` short-circuit (research.md Decision 2, data-model.md "Data flow" diagram). Idempotency is designed into every retirement step (Decisions 1–2, 5–6). No new authentication surface, no new authorization boundary, no new data collection, no new async operation.

## Plan Artifacts Reviewed

1. `specs/016-remove-claude-connectors/plan.md` — implementation plan with Constitution Check.
2. `specs/016-remove-claude-connectors/spec.md` — feature specification (19 FRs, 8 SCs, 7 assumptions).
3. `specs/016-remove-claude-connectors/research.md` — 7 Phase 0 decisions with rationale + alternatives.
4. `specs/016-remove-claude-connectors/data-model.md` — pre/post schema shape + retirement mechanics diagram.
5. `specs/016-remove-claude-connectors/contracts/retired-artifacts.md` — machine-checkable list of retired public APIs.
6. `specs/016-remove-claude-connectors/quickstart.md` — end-to-end verification recipes per user story.
7. `specs/016-remove-claude-connectors/memory-synthesis.md` — durable-memory context including S2, S7, S9.

Cross-referenced against:
- `.specify/memory/constitution.md` — Principle III (Security First) + Consent-surface exception.
- `docs/memory/INDEX.md` — S1..S9 security constraints, B1/B15 bug patterns, DEC-UNINSTALL-OPT-IN-GATE, DEC-BERLINDB-TABLE-REQUEST-BOOT.
- `docs/security-reviews/` — six prior plan reviews (007–013) for baseline norms.

## Vulnerability Findings

### SEC-001 — Retired connector secrets may persist in MySQL storage post-DROP COLUMN (LOW)

- **Location**: `specs/016-remove-claude-connectors/plan.md` §Project Structure (schema edit block) + `research.md` Decision 1.
- **OWASP Category**: A02:2025 — Cryptographic Failures (data-at-rest hygiene).
- **CWE**: CWE-212: Improper Removal of Sensitive Information Before Storage or Transfer.
- **CVSS**: 2.5 (LOW — attacker requires filesystem access to the MySQL data directory; attestation states no live install has real connector secrets).
- **Spec-Kit Task**: TASK-SEC-016-001.

**Finding**: The plan drops columns `claude_connector_client_id`, `claude_connector_client_secret`, and `claude_connector_redirect_uri` via `ALTER TABLE ... DROP COLUMN` (either through BerlinDB's diff engine or the `ConnectorColumnMigration` fallback). Column types are `varchar(255)` and `varchar(500)` with default `''` — these are stored **plaintext** (a hashed secret would be `char(64)` per the plugin's S3 convention for other credential storage; e.g. `access_token_hash` in the retired `wp_acrossai_mcp_oauth_tokens` schema).

MySQL InnoDB does not securely overwrite data blocks on `DROP COLUMN` — the underlying `.ibd` file may still contain plaintext secret material recoverable via disk forensics until MySQL reclaims and rewrites the page. The plan's retirement path does not include a pre-DROP `UPDATE ... SET column = ''` overwrite step.

**Impact**: For the attested dev-only target sites (Local by Flywheel installs at `~/local-sites/`), risk is negligible — no real secrets exist. For any downstream install that inherits this plugin update AND has populated connector secrets (contrary to attestation), retired plaintext may linger in the InnoDB tablespace until `OPTIMIZE TABLE` or a full backup/restore cycle. Not exploitable without filesystem access.

**Recommendation** (advisory, not blocking):
1. Add a pre-DROP overwrite step to `Activator::activate()`:
   ```php
   $wpdb->query(
     "UPDATE {$wpdb->prefix}acrossai_mcp_servers SET
        claude_connector_client_id = '',
        claude_connector_client_secret = '',
        claude_connector_redirect_uri = ''"
   );
   ```
   Gate it on `column_exists()` to remain idempotent. This overwrite forces the InnoDB page rewrite before the DROP.
2. OR document in README.txt Unreleased that admins with populated connector data should run `OPTIMIZE TABLE wp_acrossai_mcp_servers` after reactivation to reclaim the plaintext bytes.

**Verdict**: The plan's Pre-flight Attestation (2026-07-06) makes this a LOW-severity theoretical concern, not a shipping blocker. Fold the overwrite into TASK-6 as a defense-in-depth step, or accept the risk and cite the attestation. Recommend option 1 (overwrite step) — it's ~5 lines and closes the loop.

---

### SEC-002 — Retirement discards audit trail for any active connector tokens (INFORMATIONAL)

- **Location**: `specs/016-remove-claude-connectors/plan.md` §Project Structure (`includes/Database/OAuthAudit/` [DELETE]) + `data-model.md` OAuth Audit section.
- **OWASP Category**: A09:2025 — Security Logging and Monitoring Failures.
- **CWE**: CWE-778: Insufficient Logging.
- **CVSS**: 0.0 (INFORMATIONAL — no exploitable weakness; loss of forensic trail is retrospective, not prospective).
- **Spec-Kit Task**: TASK-SEC-016-002.

**Finding**: The plan drops the `wp_acrossai_mcp_oauth_audit` table wholesale via `DROP TABLE IF EXISTS` in both `Activator::activate()` and `uninstall.php`. If any active OAuth bearer tokens exist at retirement time (which the attestation says they do not, but which cannot be verified without inspecting each install), any forensic trail of who possessed which token and when it was last used is destroyed simultaneously with the retirement itself.

**Impact**: Zero prospective risk — retired tokens are no longer usable because `BearerAuth::resolve_bearer_token` is also removed. Retrospective risk only: if a security incident predates the retirement AND references a connector-token bearer request, the audit record is gone.

**Recommendation**: Add a bullet to README.txt Unreleased advising admins with active connector tokens to revoke them from the claude.ai Connectors UI BEFORE updating the plugin, and to export any audit rows they need for compliance BEFORE reactivation. Suggested wording:

> If your install has populated claude.ai Connector tokens, revoke them from claude.ai first and export any needed audit records from `wp_acrossai_mcp_oauth_audit` before updating. The retirement drops both tables with no recovery path.

**Verdict**: Documentation follow-up. Not a plan-level defect.

---

### SEC-003 — Silent retirement of `determine_current_user` bearer path may break external consumers (INFORMATIONAL)

- **Location**: `specs/016-remove-claude-connectors/contracts/retired-artifacts.md` §"WordPress hooks" filter row `determine_current_user`.
- **OWASP Category**: A09:2025 — Security Logging and Monitoring Failures (missed operator-visible signal).
- **CWE**: CWE-778: Insufficient Logging.
- **CVSS**: 0.0 (INFORMATIONAL).
- **Spec-Kit Task**: TASK-SEC-016-003.

**Finding**: The plan removes `BearerAuth::resolve_bearer_token` from the `determine_current_user` filter (priority 20). Any external code (mu-plugin, sibling plugin, custom theme functions.php) that relies on the plugin accepting `Authorization: Bearer <token>` headers to elevate the current user will silently start returning anonymous. There is no operator-visible signal — no admin notice, no debug.log entry, no filter that consumers can subscribe to to detect the removal.

**Impact**: If the attestation holds (dev-only, no live consumers), zero impact. If any downstream site has stood up a custom integration that leans on the bearer path, that integration silently breaks at update time.

**Recommendation**: In the release notes (README.txt Unreleased), state explicitly:

> The plugin no longer accepts `Authorization: Bearer <token>` headers for user resolution. If your integration relies on the `determine_current_user` filter registered by `AcrossAI_MCP_Manager\Includes\OAuth\BearerAuth`, migrate to WordPress Application Passwords (Basic auth) via the CLI auth flow (`public/Partials/FrontendAuth`) — the CLI auth stack is untouched by this update.

**Verdict**: Documentation follow-up. Not a plan-level defect.

---

### SEC-004 — Orphaned security constraint invariants require memory-hygiene follow-up (INFORMATIONAL)

- **Location**: `specs/016-remove-claude-connectors/memory-synthesis.md` §Conflict Warnings + §Relevant Security Constraints S7.
- **OWASP Category**: A09:2025 — Security Logging and Monitoring Failures (documentation drift).
- **CWE**: N/A (memory-hygiene, not a runtime weakness).
- **CVSS**: 0.0 (INFORMATIONAL).
- **Spec-Kit Task**: TASK-SEC-016-004.

**Finding**: The plan retires the sole consumer of the S7 security constraint ("OAuth token endpoint `__return_true` — exactly one match permitted across `includes/OAuth/`"). Post-implementation, `PROJECT_CONTEXT.md::S7` will describe an exception that has zero consumers. A future code reviewer reading S7 could reasonably conclude that a new `__return_true` REST route is permitted under the S7 umbrella — the exception has become a footgun without an annotation.

Same class of concern applies to `A13` (RFC-prescribed forms exempted from A4 DataForm) and the Consent-surface exception in Constitution §III (added 2026-06-30 for Feature-007): the retired OAuth consent form was ONE of the two consumers; only `public/Partials/FrontendAuth` remains as an active consumer.

**Impact**: Memory-hygiene drift. Not a runtime issue. Risk accrues over months as the plugin evolves and new features could accidentally cite an orphaned exception as precedent.

**Recommendation**: Post-implementation, execute `/speckit-memory-md-capture-from-diff`. The plan already queues four specific annotations (plan.md Constitution Check §"Attention required"):
1. `PROJECT_CONTEXT.md::S7` — annotate "no consumers post-F016; token endpoint retired".
2. `DECISIONS.md::DEC-CLIENT-RENDERER-PUBLIC-API` — annotate "post-F016: 2 shortcodes + 2 dispatch map entries".
3. `ARCHITECTURE.md::A13` — annotate "no active consumers post-F016; still valid for future RFC-prescribed forms".
4. Constitution Principle I Rationale + Architecture Directory Layout — remove `includes/OAuth/` line + strike `OAuth / Claude Connectors` from active-area list.

**Verdict**: Already queued in the plan. Track as TASK-SEC-016-004 to ensure the follow-up actually runs.

## Confirmed Secure Patterns

The plan explicitly honors these security-relevant durable-memory invariants:

- ✅ **`DEC-UNINSTALL-OPT-IN-GATE`** — every destructive `DROP TABLE` / `delete_option` statement in `uninstall.php` lives AFTER the `acrossai_mcp_uninstall_delete_data !== 1` short-circuit (research.md Decision 2, data-model.md flow diagram).
- ✅ **Idempotent Activator operations** — `DROP TABLE IF EXISTS`, `delete_option()` (no error on missing option), `column_exists`-gated fallback ALTER (research.md Decision 5). Safe to reactivate any number of times.
- ✅ **BerlinDB phantom-version guard preserved** — the `Table::maybe_upgrade()` override from Feature 011 stays intact during the `$version` bump; version-only bumps don't reopen the F011 activation bug (plan.md schema edit block, data-model.md BerlinDB metadata row).
- ✅ **CLI auth stack out of scope** — `FrontendAuth`, `CliController`, `wp_acrossai_mcp_cli_auth_logs`, `includes/Database/CliAuthLog/`, `acrossai-mcp-frontend` stylesheet all explicitly excluded from removal (spec FR-013, contracts §"Retained").
- ✅ **Application Passwords untouched** — CLI auth's `wp_usermeta`-stored App Passwords survive intact; the retirement only removes bearer-token acceptance, not the App Password credential storage (contracts §"Retained", quickstart.md US4).
- ✅ **Grep audit with B15 sanity guard** — FR-015 uses ERE `\\?` alternation to match both bare-`use` and leading-`\` FQN forms; quickstart.md's B15 seed-then-remove ritual verifies the audit isn't a false-pass (quickstart.md §"Full grep audit").
- ✅ **Attack surface reduction, not shift** — no security-sensitive path replaces the retired paths; there is no compensating new surface introduced.

## Constitution Alignment (Principle III — Security First)

| Constitution rule | Feature 016 impact | Verdict |
|---|---|---|
| Input sanitized at boundary | No new inputs added | PASS |
| Output escaped at rendering | No new outputs added | PASS |
| Nonce on forms/AJAX | `save_claude_connector` handler (which verified nonce) is removed; no new form added | PASS |
| Capability check on admin actions | Retires an admin surface; retention has no new admin action | PASS |
| `$wpdb->prepare()` on DB queries | Retirement path uses prepared statements (research.md Decision 5); fallback helper uses `$wpdb->prepare()` per plan.md §Security Checklist | PASS (implementation-time verify) |
| Explicit `permission_callback` on REST | Retires the S7-exception route; no new route added | PASS |
| OAuth tokens / App Passwords hashed | Retires the (already-hashed) `access_token_hash` column with the OAuth Token table; App Passwords untouched. The plaintext `claude_connector_client_secret` column is dropped (see SEC-001) | PASS with SEC-001 caveat |
| File upload validated | N/A — no uploads | N/A |
| Consent-surface exception (2026-06-30) | Retired consent form was ONE of two exception consumers; `FrontendAuth` remains as sole consumer; exception stays valid | PASS |

## Action Plan & Next Steps

1. **Durable Memory Preservation**: The plan already identified four memory-hygiene follow-ups (see Constitution Check §"Attention required" in plan.md). SEC-004 restates them. Handle post-implementation via `/speckit-memory-md-capture-from-diff` — NOT part of Feature 016's PR. **No new durable-memory entries are required from the SECURITY REVIEW itself** because Feature 016 does not introduce a new architectural pattern; the patterns being annotated are already-captured entries that need their status updated.
2. **Remediation planning**: No CRITICAL/HIGH/MEDIUM findings. `/speckit-security-review-followup` is not required, but recommend folding SEC-001 (pre-DROP overwrite) into TASK-6 during `/speckit-tasks` decomposition. SEC-002 + SEC-003 recommendations fold into TASK-10 (README.txt Unreleased changelog).
3. **Handoff to Architecture Guard**: This review's output is `security-constraints.md` (short summary) in the feature directory. Architecture Guard Step 5 consumes it in the parent governed-plan workflow.
